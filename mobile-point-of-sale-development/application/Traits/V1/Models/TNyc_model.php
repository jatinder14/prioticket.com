<?php 

namespace Prio\Traits\V1\Models;

trait TNyc_model {
    /*
     * To load commonaly used files in all functions
     */

    public $TP_NATIVE_INFO = array();

    function __construct() {
        parent::__construct();
        $this->TP_NATIVE_INFO = json_decode(TP_NATIVE_INFO, true);
    }

    /* #region NYc Process  Module  : This module covers NYC api's  */

    /**
     * @Name nyc_validate
     * @Purpose 
     * @return status and data 
     *      status 1 or 0
     *      data 
     * @param 
     *      $req - array() - API request (same as from APP) + user_details + hidden_tickets (of sup user)
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 20 Feb 2020
     */
    function nyc_validate($users_data, $request) {
        global $MPOS_LOGS;
        $query_string = '?validationId=' . $request['pass_no'] . '&vendorId=' . $request['vendor_id'];
        if (isset($request['ticket']) && !empty($request['ticket'])) {
            $query_string .= "&productTag=" . $request['ticket']['product_tag'];
        }

        $logs['query_string'] = $query_string;
        $url = $this->TP_NATIVE_INFO['nyc']['tp_end_point'] . '/v2/voucher/validate' . $query_string;
        $headers = array(
            'Content-Type:application/json',
            'X-Api-Key:' . NYC_API_KEY
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $tp_response = curl_exec($ch);
        $tp_response = json_decode($tp_response, true);
        $logs['tp_response'] = $tp_response;
        if ($tp_response['status'] == 'ERROR') {
            $response['status'] = 0;
            $response['errorMessage'] = $tp_response['message'];
        } else if ($tp_response['status'] == 'FAILURE') {
            $response['status'] = 0;
            $response['errorMessage'] = $tp_response['errorCode'];
        } else if ($tp_response['status'] == 'SUCCESS' && !empty($tp_response['validation'])) {
            $response['status'] = 1;
            $response['is_redeemed'] = 1;
        } else if ($tp_response['status'] == 'CLARIFY' && !empty($tp_response['suggestedProducts'])) { //having different products in same order
            $response['status'] = 1;
            $response['is_redeemed'] = 0;
            $show_tickets = 1;
        } else {
            $logs['tp_response'] = $tp_response;
            $response['status'] = 0;
            $response['errorMessage'] = "Not Valid";
        }
        $logs['show_tickets'] = $show_tickets;
        $logs['response_in_between'] = $response;
        $MPOS_LOGS['nyc_validate'] = $logs;
        if ($response['status'] == 0) {
            $logs['response_in_between'] = $response;
            return $response;
        } else {
            $response['supplier_id'] = (int) $request['supplier_id'];
            $response['vendor_id'] = (int) $request['vendor_id'];
            $response['pass_no'] = (string) $request['pass_no'];
            $response['third_party_type'] = 19;
            if ($show_tickets == 1) {
                $tickets_listing = $this->get_tickets_listing($tp_response, $request['supplier_id'], 0);
                $response['tickets'] = $tickets_listing;
            }
        }

        if ($response['is_redeemed'] == 1) { //updations in DB are required
            $redeemed_tickets = $this->get_redeemed_data($tp_response, $request['supplier_id'], $request['ticket']);
            $this->add_enteries($request, $users_data, $redeemed_tickets, $tp_response);
            $response['tickets'][] = $redeemed_tickets;
        }
        $MPOS_LOGS['nyc_validate'] = $logs;
        return $response;
    }

    function get_tickets_listing($tp_data = array(), $supplier_id = '0', $from_redeem = 0) { //returns listing of different tags 
        global $MPOS_LOGS;
        $logs_of_get_tickets_listing['from_redeem'] = $from_redeem;
        if ($from_redeem == 0) {
            $tp_ticket_ids = array_unique(array_column($tp_data['suggestedProducts'], 'productTag'));
            foreach ($tp_data['suggestedProducts'] as $products) {
                $tags[$products['productTag']]['name'] = $products['name'];
                $tags[$products['productTag']]['tag'] = $products['productTag'];
            }
        } else {
            $tp_ticket_ids = array_unique(array_column($tp_data['validation'], 'productTag'));
            foreach ($tp_data['validation'] as $products) {
                $tags[$products['productTag']]['name'] = $products['productName'];
                $tags[$products['productTag']]['tag'] = $products['productTag'];
            }
        }
        $logs_of_get_tickets_listing['tags'] = $tags;
        if ($supplier_id != '0' && !empty($tp_ticket_ids)) {
            $tickets_data = $this->find('modeventcontent', array(
                'select' => 'third_party_ticket_id, mec_id, postingEventTitle',
                'where' => 'cod_id = "' . $supplier_id . '" and third_party_id = "19" and third_party_ticket_id in ("' . implode('","', $tp_ticket_ids) . '")'), 'array');
            $logs_of_get_tickets_listing['mec_query'] = $this->primarydb->db->last_query();
            $logs_of_get_tickets_listing['tickets'] = $tickets_data;
            foreach ($tickets_data as $ticket_data) {
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['third_party_product_id'] = $ticket_data['third_party_ticket_id'];
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['product_id'] = (int) $ticket_data['mec_id'];
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['title'] = $ticket_data['postingEventTitle'];
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['third_party_product_title'] = $tags[$ticket_data['third_party_ticket_id']]['name'];
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['product_tag'] = $tags[$ticket_data['third_party_ticket_id']]['tag'];
                $tickets[$ticket_data['mec_id'] . "_" . $ticket_data['third_party_ticket_id']]['types'] = array();
            }
            $MPOS_LOGS['get_tickets_listing'] = $logs_of_get_tickets_listing;
            return array_values($tickets);
        }
    }

    function get_redeemed_data($tp_data = array(), $supplier_id = '0', $ticket_data = array()) {
        global $MPOS_LOGS;
        $validated_data = array();
        $log_of_get_redeemed_data['ticket_data'] = $ticket_data;
        if (empty($ticket_data)) {
            $ticket_data = $this->get_tickets_listing($tp_data, $supplier_id, 1);
            $ticket_data = $ticket_data[0];
        }
        $tp_type_ids = array_unique(array_column($tp_data['validation'], 'productSku'));
        $types_data = $this->find('ticketpriceschedule', array(
            'select' => 'third_party_ticket_type_id, ticket_id, id, ticket_type_label, ticketType, pricetext',
            'where' => 'ticket_id = "' . $ticket_data['product_id'] . '" and third_party_ticket_type_id in ("' . implode('","', $tp_type_ids) . '")'), 'array');

        $log_of_get_redeemed_data['tps_query'] = $this->primarydb->db->last_query();
        $log_of_get_redeemed_data['types_data'] = $types_data;
        foreach ($tp_data['validation'] as $validations) {
            $validated_data[$validations['productSku']]['quantity'] += $validations['quantity'];
        }
        foreach ($types_data as $type_data) {
            $types[$type_data['id'] . "_" . $type_data['third_party_ticket_type_id']]['tps_id'] = $type_data['id'];
            $types[$type_data['id'] . "_" . $type_data['third_party_ticket_type_id']]['quantity'] = $validated_data[$type_data['third_party_ticket_type_id']]['quantity'];
            $types[$type_data['id'] . "_" . $type_data['third_party_ticket_type_id']]['ticket_type'] = (int) $type_data['ticketType'];
            $types[$type_data['id'] . "_" . $type_data['third_party_ticket_type_id']]['ticket_type_label'] = ucfirst(trim(strtolower($type_data['ticket_type_label'])));
            $types[$type_data['id'] . "_" . $type_data['third_party_ticket_type_id']]['price'] = (float) $type_data['pricetext'];
        }
        $ticket_data['types'] = array_values($types);
        $log_of_get_redeemed_data['types'] = $ticket_data;
        $MPOS_LOGS['get_redeemed_data'] = $log_of_get_redeemed_data;
        return $ticket_data;
    }

    function add_enteries($scan_data = array(), $user_detail = array(), $redeemed_tickets = array(), $tp_response = array()) {
        global $MPOS_LOGS;
        $current_time = date('Y-m-d H:i:s');
        $current_timezone = new \DateTimeZone($scan_data['time_zone']);
        $timezone_in_seconds = $current_timezone->getOffset(new \DateTime($current_time));
        $data['timezone'] = '+' . gmdate("H:i", $timezone_in_seconds);
        $data['timezone_in_seconds'] = $timezone_in_seconds;
        $curent_gm_time = gmdate('H:i:s');
        $current_time = strtotime($curent_gm_time) + $timezone_in_seconds;
        $supplier_id = $scan_data['supplier_id'];
        $dist_id = isset($user_detail->dist_id) ? $user_detail->dist_id : 0;
        $dist_cashier_id = isset($user_detail->dist_cashier_id) ? $user_detail->dist_cashier_id : 0;
        $museum_cashier_id = $user_detail->uid;
        $museum_cashier_name = $user_detail->fname . ' ' . $user_detail->lname;
        $current_date = gmdate('Y-m-d');

        $data['action_performed'] = '0, MPOS_NYC_RDM';
        $this->primarydb->db->select('prepaid_ticket_id, visitor_group_no, passNo, ticket_type, is_combi_ticket, title, quantity, group_quantity, used, museum_cashier_id, is_cancelled, action_performed, scanned_at, third_party_type, second_party_type, ticket_id, channel_type, valid_till', false);
        $this->primarydb->db->from('prepaid_tickets');
        $this->primarydb->db->where('museum_id', $supplier_id);
        $this->primarydb->db->where('passNo', $scan_data['pass_no']);
        $ticket_details = $this->primarydb->db->get();
        $sale_detail = $ticket_details->result_array();
        $logs_for_add_enteries['pt_query_' . date('H:i:s')] = $this->primarydb->db->last_query();

        $data['ticket_id'] = $ticket_id = $redeemed_tickets['product_id'];

        $mec_data = $this->find('modeventcontent', array(
            'select' => 'mec_id, postingEventTitle, shortDesc as description, cod_id as museum_id, museum_name, is_own_capacity, own_capacity_id, shared_capacity_id, eventImage as image, highlights, barcode_type, is_reservation as ticket_class, timezone, ',
            'where' => 'cod_id = "' . $supplier_id . '" and mec_id = "' . $ticket_id . '" and third_party_id = "19"'
                ), 'row_array'
        );
        $logs_for_add_enteries['query_from_modeventcontent_' . date('H:i:s')] = $this->primarydb->db->last_query();
        // Taking 11No from , to time and dates for reservation ticket  -> need to update this after confrmation
        $mec_data['selected_date'] = $current_date;
        $mec_data['from_time'] = '';
        $mec_data['to_time'] = '';
        $mec_data['timeslot'] = '';
        $mec_data['booking_selected_date'] = $current_date;
            
        $data['ticket_data'] = $mec_data;
        $company_detail = $this->primarydb->db->select("cashier_type, company, distributor_type, channel_id, channel_name, saledesk_id, saledesk_name, reseller_id, reseller_name, partner_name, currency_code, hex_code")->from('qr_codes')->where("cod_id", $dist_id)->or_where("cod_id", $supplier_id)->get()->result_array();
        $logs_for_add_enteries['query_from_qr_codes_' . date('H:i:s')] = $this->primarydb->db->last_query();
        foreach ($company_detail as $row) {
            $data['distributor_detail'] = ($row['cashier_type'] == "1") ? $row : array();
            $data['supplier_detail'] = ($row['cashier_type'] == "2") ? $row : array();
        }

        //If existing pass is scanned
        $hto_data = $this->primarydb->db->select("visitor_group_no, quantity, total_price, total_net_price")->from('hotel_ticket_overview')->where("passNo", $scan_data['pass_no'])->get()->row_array();
        $logs_for_add_enteries['hto_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
        $visitor_group_no = '';
        $hto_record = array();
        if ($hto_data['visitor_group_no'] != '' && $hto_data['visitor_group_no'] > 0) {
            $visitor_group_no = $hto_data['visitor_group_no'];
            $hto_record = $hto_data;
        }
        $tps_ids = array_unique(array_column($redeemed_tickets['types'], 'tps_id'));
        foreach($redeemed_tickets['types'] as $type) {
            $quantity_per_type[$type['tps_id']] = $type['quantity'];
            if ($type['quantity'] > 1) {
                $combi_product = 1;
            }
        }
        $data['is_combi_ticket'] = (sizeof($tps_ids) > 1 || $combi_product == 1) ? 1 : 0;
        $ticket_type_price_details = $this->find('ticketpriceschedule', array(
            'select' => 'id as ticketpriceschedule_id, adjust_capacity, pax, ticketType, ticket_type_label, agefrom, ageto, pricetext, original_price, newPrice, discount, saveamount, ticket_tax_value, ticket_net_price, third_party_ticket_type_id',
            'where' => 'ticket_id = ' . $ticket_id . ' and id in (' . implode(',', $tps_ids) . ') and deleted = 0',
                ), 'array'
        );
        $logs_for_add_enteries['query_from_tps_' . date('H:i:s')] = $this->primarydb->db->last_query();
        foreach ($ticket_type_price_details as $ticket_type_price_detail) {
            $price = ($ticket_type_price_detail['newPrice'] > 0) ? $ticket_type_price_detail['newPrice'] : $ticket_type_price_detail['pricetext'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['ticket_tax_value'] = $ticket_tax = $ticket_type_price_detail['ticket_tax_value'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['total_net_price_hto'] = ($price - ($price * $ticket_tax) / ($ticket_tax + 100));
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_price'] = $price;
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_original_price'] = $ticket_type_price_detail['pricetext'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_discount'] = isset($ticket_type_price_detail['saveamount']) ? $ticket_type_price_detail['saveamount'] : 0;
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_tax'] = $ticket_type_price_detail['ticket_tax_value'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_net_price'] = $ticket_type_price_detail['ticket_net_price'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['oroginal_price'] = $ticket_type_price_detail['original_price'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['pricetext'] = $ticket_type_price_detail['pricetext'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['saveamount'] = $ticket_type_price_detail['saveamount'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['ticket_gross_price_amount'] = $price;
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['ticketpriceschedule_id'] = $ticket_type_price_detail['ticketpriceschedule_id'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['ticket_type'] = $ticket_type_price_detail['ticket_type_label'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['adjust_capacity'] = $ticket_type_price_detail['adjust_capacity'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['pax'] = $ticket_type_price_detail['pax'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['agefrom'] = $ticket_type_price_detail['agefrom'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['ageto'] = $ticket_type_price_detail['ageto'];
            $types_data[$ticket_type_price_detail['ticketpriceschedule_id']]['quantity'] = $quantity_per_type[$ticket_type_price_detail['ticketpriceschedule_id']];
        }
        $data['types_data'] = array_values($types_data);
        $data['current_date_time'] = gmdate('Y-m-d') . ' ' . $curent_gm_time;
        $data['device_time'] = $scan_data['device_time'];
        $data['museum_cashier_name'] = $museum_cashier_name;
        $data['ticket_code'] = $scan_data['pass_no'];
        $data['pos_point_id'] = (isset($scan_data['pos_point_id']) && $scan_data['pos_point_id'] > 0) ? $scan_data['pos_point_id'] : 0;
        $data['pos_point_name'] = (isset($scan_data['pos_point_name']) && $scan_data['pos_point_name'] != '') ? $scan_data['pos_point_name'] : '';
        $data['distributor_id'] = $dist_id;
        $data['dist_cashier_id'] = $dist_cashier_id;
        $data['dist_cashier_name'] = isset($user_detail->dist_cashier_name) ? $user_detail->dist_cashier_name : '';
        $data['visitor_group_no'] = $visitor_group_no;
        $data['hto_record'] = $hto_record;
        $data['supplier_id'] = $supplier_id;
        $data['museum_cashier_id'] = $museum_cashier_id;
        $data['channel_type'] = $scan_data['channel_type'];
        $data['thirdparty_response'] = json_encode($tp_response);
        $logs_for_add_enteries['data_passed_to_insert_financial_entries'] = $data;
        
        $MPOS_LOGS['add_enteries_' . date('H:i:s')] = $logs_for_add_enteries;
        $this->load->model('V1/intersolver_model');
        return $response = $this->intersolver_model->insert_financial_entries($data);
    }

    /* #endregion NYc Process  Module  : This module covers NYC api's  */
}

?>