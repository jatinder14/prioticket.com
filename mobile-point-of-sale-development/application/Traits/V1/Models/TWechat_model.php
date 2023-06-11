<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TWechat_model {

    function __construct() {
        // Call the Model constructor
        parent::__construct();
        
        $this->types = array(
            'adult'     => 1,
            'baby'      => 2,
            'infant'    => 13,
            'child'     => 3,
            'elderly'   => 4,
            'handicapt' => 5,
            'student'   => 6,
            'military'  => 7,
            'youth'     => 8,
            'senior'    => 9,
            'custom'     => 10,
            'family'    => 11,
            'resident'  => 12
        );
    }
    
    /* #region Wechat Module  : This module covers wechat api's  */

    /* @Method     : scan
     * @Purpose    : scan wechat gift card
     * @Parameters : pass_no
     * @Return     : 
     * @Created By : komal <komalgarg.intersoft@gmail.com> ON May 31, 2019
     */
    function scan ($scan_data, $user_detail) {
        global $MPOS_LOGS;
        try {
            $logs['giftcard_req_'.date('H:i:s')] = json_encode($scan_data);
            $logs['user_details_'.date('H:i:s')] = json_encode($user_detail);
            $tp_native_info = json_decode(TP_NATIVE_INFO, true);
            $card_prefix = $tp_native_info['wechat']['card_prefix'];
            $data['timezone'] = $timezone = (int) $this->get_timezone_of_date();        
            $curent_gm_time = gmdate('H:i:s');
            $current_time = strtotime($curent_gm_time) + $timezone * 60 * 60;
            $amsterdam_datetime = date('Y-m-d\TH:i:s', $current_time) . '+0' . $timezone . ':00';
            $supplier_id = $scan_data['supplier_id'] = $user_detail->supplier_id;
            $museum_cashier_id = $user_detail->uid;
            $museum_cashier_name = $user_detail->fname . ' ' . $user_detail->lname;

            $where = '(passNo = "'.$scan_data['pass_no'].'" or without_elo_reference_no = "'.$scan_data['pass_no'].'")';
            $this->primarydb->db->select('prepaid_ticket_id, CASE when selected_date="" or selected_date="0" then booking_selected_date else selected_date END, visitor_group_no, passNo, ticket_type, is_combi_ticket, title, quantity, group_price, group_quantity, used, museum_cashier_id, is_cancelled, action_performed, scanned_at, third_party_type, second_party_type, ticket_id, channel_type, valid_till', false);
            $this->primarydb->db->from('prepaid_tickets');
            $this->primarydb->db->where('museum_id', $supplier_id);
            $this->primarydb->db->where($where);
            $this->primarydb->db->order_by('prepaid_ticket_id', 'DESC');        
            $ticket_details = $this->primarydb->db->get();        
            $sale_detail = $ticket_details->result_array();
            $logs['pt_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if(!empty($sale_detail)){
                $scanned_at = $sale_detail[0]['scanned_at'];
                if($scanned_at){
                    $scanned_date = date('Y-m-d', $sale_detail[0]['scanned_at']);
                    $scanned_time = $scanned_at + $timezone * 60 * 60;
                    $current_date = gmdate('Y-m-d');
                }
            }
            /* checks for giftcard ticketcode start */
            $is_gift_card = 0;
            if (isset($card_prefix)) {
                foreach ($card_prefix as $crd_prefix) {
                    if (strpos($scan_data['pass_no'], $crd_prefix) !== false) {
                        $is_gift_card = 1;
                    }
                }
                /* if card_prefix matches with giftcard.*/
                if($is_gift_card == 1){
                    if(isset($scanned_date) && $scanned_date == $current_date){
                        $response['status'] = 12;
                        $response['message'] = "Pass already redeemed on ".date('d M, Y H:i', $scanned_time);
                    } else {
                        $this->load->library('ThirdParty');
                        $thirdpartyObj = new \ThirdParty("wechat");
                        /* call thirdparty(giftcard_v1) validate_card function to validate ticket code at LIAB */
                        $thirdparty_response = $thirdpartyObj->call('validate_card', $scan_data);
                        $logs['third_party_response_'.date('H:i:s')] = json_encode($thirdparty_response);
                        /* if get success response at LIAB then insert entries in financial tables else return error message. */
                        if (1 || $thirdparty_response['data']['status'] == 'Success') {                   
                            $data['guest_name'] = isset($thirdparty_response['data']['get_card_response']['Card']['BrandName']) ? $thirdparty_response['data']['get_card_response']['Card']['BrandName'] : '';
                            /* get third party price based on card balance of getCard operation. */
                            $card_balance = isset($thirdparty_response['data']['get_card_response']['Card']['Balance']) ?  $thirdparty_response['data']['get_card_response']['Card']['Balance']  : 0;
                            $balance_factor = isset($thirdparty_response['data']['get_card_response']['Card']['BalanceFactor']) ? $thirdparty_response['data']['get_card_response']['Card']['BalanceFactor'] : 100;
                            if(!$balance_factor){
                                $balance_factor = 100;
                            }
                            $data['third_party_ticket_price'] = $card_balance/$balance_factor;
                            unset($thirdparty_response['get_card_response']);                       
                            $hto_data = $this->primarydb->db->select("visitor_group_no, quantity, total_price, total_net_price")->from('hotel_ticket_overview')->where("passNo", $scan_data['pass_no'])->get()->row_array();
                            $logs['hto_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                            $visitor_group_no = '';
                            $hto_record = array();
                            if ($hto_data['visitor_group_no']) {
                                $visitor_group_no = $hto_data['visitor_group_no'];
                                $hto_record = $hto_data;
                            }
                            $data['current_date_time'] = gmdate('Y-m-d').' '.$curent_gm_time;
                            $data['museum_cashier_name'] = $museum_cashier_name;
                            $data['ticket_code'] = $scan_data['pass_no'];
                            $data['pos_point_id'] = (isset($scan_data['pos_point_id']) && $scan_data['pos_point_id'] > 0) ? $scan_data['pos_point_id'] : 0;
                            $data['pos_point_name'] = (isset($scan_data['pos_point_name']) && $scan_data['pos_point_name'] != '') ? $scan_data['pos_point_name'] : '';
                            $data['distributor_id'] = $tp_native_info['wechat']['distributor_id'];
                            $data['dist_cashier_id'] = $user_detail->dist_cashier_id;
                            $data['dist_cashier_name'] = $user_detail->dist_cashier_name;
                            $data['visitor_group_no'] = $visitor_group_no;
                            $data['hto_record'] = $hto_record;
                            $data['supplier_id'] = $supplier_id;
                            $data['museum_cashier_id'] = $museum_cashier_id;
                            $data['museum_cashier_name'] = $museum_cashier_name;
                            $data['amsterdam_datetime'] = $amsterdam_datetime; 
                            $data['thirdparty_response'] = $thirdparty_response;
                            return $response = $this->insert_financial_entries($data);
                        } else {
                            $response['errorNo'] = $thirdparty_response['data']['error']['error_details'];
                            $response['error_code'] = $thirdparty_response['data']['error']['error_code'];
                            $response['error_message'] = 'Pass is not valid';
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
        return $response;
    }
    
        /**
     * @Name           : insert_financial_entries
     * @Purpose        : to insert entries in financial tables for giftcard.
     * @Params         : $data: array which is needed to insert records.
     * @Created By     : komal <komalgarg.intersoft@gmail.com> ON May 31, 2019
     */
    private function insert_financial_entries($data = array()) {
        global $MPOS_LOGS;
        try {
            $logs['insert_financial_entries_'.date('H:i:s')] = $data;
            $date_time = $data['current_date_time'];
            $distributor_id = $where_pos['hotel_id'] = $data['distributor_id'];
            $where_pos['museum_id'] = $data['supplier_id'];
            $where_pos['is_pos_list'] = 1;
            $data['pos_ticket_detail'] = $this->primarydb->db->select("*")->from('pos_tickets')->where($where_pos)->get()->row_array();
            $logs['query_from_pos_tickets_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if (!$data['pos_ticket_detail'] || $data['pos_ticket_detail']['mec_id'] == '') {
                $response['status'] = 12;
                $response['message'] = 'No entry found in pos_tickets';
                return $response;
            }

            $ticket_id = $data['pos_ticket_detail']['mec_id'];
            $tickets = $this->find('modeventcontent', array(
                'select' => 'timezone, sub_cat_id, cod_id as museum_id, museum_name, is_own_capacity, shared_capacity_id, own_capacity_id, extra_text_field, duration, mec_id, eventImage as image, highlights, postingEventTitle as shortDesc, barcode_type, shortDesc as description, ticketwithdifferentpricing, newPrice, newPrice as pricetext, discount, ticketPrice, ticket_tax_value, is_reservation as ticket_class, timezone, msgClaim, location, city, notification_emails, third_party_id, third_party_parameters, second_party_id, cluster_ticket_details,second_party_parameters, capacity_type, third_party_ticket_id',
                'where' => 'mec_id = ' . $ticket_id . ' and (startDate <= ' . strtotime($date_time) . ' and endDate >= ' . strtotime($date_time) . ')'
                    )
            );
            $logs['query_from_modeventcontent_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if (!empty($tickets)) {
                $data['tickets'] = $tickets[0];
                $museum_id = $tickets[0]['museum_id'];
            } else {
                $response['status'] = 12;
                $response['message'] = 'No entry found in mec';
            }
            $company_detail = $this->primarydb->db->select("cashier_type, company, distributor_type, channel_id, channel_name, saledesk_id, saledesk_name, reseller_id, reseller_name, partner_name, currency_code, hex_code")->from('qr_codes')->where("cod_id", $distributor_id)->or_where("cod_id", $museum_id)->get()->result_array();
            $logs['query_from_qr_codes_'.date('H:i:s')] = $this->primarydb->db->last_query();
            foreach($company_detail as $row) {
                if($row['cashier_type'] == "1") {
                    $data['distributor_detail'] = $row;
                } else if($row['cashier_type'] == "2") {
                    $data['supplier_detail'] = $row;
                }
            }
            if (!$data['distributor_detail']) {
                $response['status'] = 12;
                $response['message'] = 'No entry found in qr_code for distributor';
            }
            /* set ticket_type based on card balance. */
            if($data['third_party_ticket_price'] >= '32.45' && $data['third_party_ticket_price']<= '64.90'){
                 $data['ticket_type'] = $ticket_type = 'CHILD';
            }else if($data['third_party_ticket_price'] >= '64.90' || 1){
                $data['ticket_type'] = $ticket_type = 'ADULT';
            }else{
                $response['status'] = 12;
                $response['message'] = 'Third party price not matched according to ticket types';
            }        
            $different_price_tickets = $this->find('ticketpriceschedule', array(
                'select' => 'id as ticketpriceschedule_id, adjust_capacity, pax, ticketType, agefrom, ageto, pricetext, group_type_ticket, group_price, group_linked_with, min_qty, max_qty, original_price, newPrice, discountType, discount, saveamount, ticket_tax_value, ticket_net_price, third_party_ticket_type_id',
                'where' => 'ticket_id = ' . $ticket_id . ' and deleted = 0 and start_date <= "' . strtotime($date_time) . '" and end_date >= ' . strtotime($date_time),
                'order_by' => 'ageto asc'
                    )
            );    
            $logs['query_from_ticketpriceschedule_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if (!empty($different_price_tickets)) {
                $ticket_details = $this->prepare_ordered_ticket_types($different_price_tickets, $ticket_details);
            } else {
                $response['status'] = 12;
                $response['message'] = 'No entry found in tps.';
            }    
            $seasonal_tps_ids = isset($ticket_details['seasonal_tps_ids']) ? $ticket_details['seasonal_tps_ids'] : array();
            $supplier_data = array();
            $data['ticket_prices'] = $ticket_prices = $this->get_ticket_price($data['distributor_id'], $ticket_id, $seasonal_tps_ids, $data['distributor_detail']->channel_id);
            $data['ticket_type_price_detail'] = $ticket_type_price_detail = $ticket_details['ticket_details']['different_price_tickets'][$ticket_type]['groups'][0];
            if (isset($ticket_type_price_detail['ticketpriceschedule_id']) && $ticket_type_price_detail['ticketpriceschedule_id'] > 0) {
                $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_original_price'] = $ticket_type_price_detail['pricetext'];
                $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_discount'] = isset($ticket_type_price_detail['saveamount']) ? $ticket_type_price_detail['saveamount'] : 0;
                if ($ticket_type_price_detail['newPrice'] > 0) {
                    $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_price'] = $ticket_type_price_detail['newPrice'];
                } else {
                    $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_price'] = $ticket_type_price_detail['pricetext'];
                }
                $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_tax'] = $ticket_type_price_detail['ticket_tax_value'];
                $supplier_data[$ticket_type_price_detail['ticketpriceschedule_id']]['supplier_net_price'] = $ticket_type_price_detail['ticket_net_price'];
            }else{ 
                $response['status'] = 12;
                $response['message'] = 'No entry found in tps table for this price ticket type';
            }
            $price = 0;
            $tps_id = $ticket_type_price_detail['ticketpriceschedule_id'];     
            /* If price is not in TLC or CLC then assign prices from ticketpriceschedule. */
            if (!empty($ticket_prices[$tps_id])) {
                $price = $data['ticket_gross_price_amount'] = $ticket_prices[$tps_id]['ticket_gross_price'];           
            } else {
                $price = $data['ticket_gross_price_amount'] = ($ticket_type_price_detail['newPrice'] > 0) ? $ticket_type_price_detail['newPrice'] : $ticket_type_price_detail['pricetext'];
             }
            $ticket_tax = $ticket_type_price_detail['ticket_tax_value'];
            $data['total_net_price_hto'] = ($price - ($price * $ticket_tax) / ($ticket_tax + 100));
            $data['hotel_ticket_overview_data'] = array();
            if (!$data['visitor_group_no']) {
                $data['visitor_group_no'] = $this->hotel_model->getLastVisitorGroupNo();
                $data['financial_details'] = $this->primarydb->db->select("financial_id, financial_name")->from("channels")->where("channel_id", $data['distributor_detail']->channel_id)->get()->row();
                $data['hotel_ticket_overview_data'] = 1;
            }
            $queue_data = array(
                'third_party_giftcards' => 1,
                'data' => $data,
                'supplier_data' => $supplier_data
            );
            
            // Load AWS library.
            require_once 'aws-php-sdk/aws-autoloader.php';
            // Load SNS library.
            $this->load->library('Sns');
            $sns_object = new \Sns();
            // Load SQS library.
            $this->load->library('Sqs');
            $sqs_object = new \Sqs();

            $request_string = json_encode($queue_data);
            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
            $queueUrl = SCANING_ACTION_URL;
            // This Fn used to send notification with data on AWS panel. 
            $logs['data_to_queue_SCANING_ACTION_ARN_'.date('H:i:s')] = $queue_data;
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                if ($MessageId) {
                    $sns_object->publish($queueUrl, SCANING_ACTION_ARN); // api.prioticket.com/api/update_third_party_redumption
                }
            }
            $response = $this->set_response($data);
            $logs['response'.date('H:i:s')] = $queue_data;
        } catch (\Exception $e) {
            $MPOS_LOGS['insert_financial_entries_' . date('H:i:s')] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['insert_financial_entries_' . date('H:i:s')] = $logs;
        return $response;
    }
    
    /**
     * @Name           : set_response
     * @Purpose        : to set response for giftcard.
     * @Params         : $data: array which is needed to set response.
     * @CreatedBy       : komal <komalgarg.intersoft@gmail.com> on date(31 May 2019)
     */
    private function set_response($data = array()) {
        $types_count[] = array(
            "clustering_id"=> 0,
            "tps_id"=> (int)$data['ticket_type_price_detail']['ticketpriceschedule_id'],
            "ticket_type"=> (int)$data['ticket_type_price_detail']['ticketType'],
            "price"=> (float)$data['ticket_type_price_detail']['pricetext'],
            "tax"=> (float)$data['ticket_type_price_detail']['ticket_tax_value'],
            "count"=> 1
        );
        $scan_data = array(
            'ticket_id' => (isset($data['pos_ticket_detail']['mec_id'])) ? (int)$data['pos_ticket_detail']['mec_id'] : 0,
            'title' => (isset($data['tickets']['shortDesc'])) ? ($data['tickets']['shortDesc']): '',
            'hotel_name' => $data['distributor_detail']['company'],
            'ticket_type' => (int)$data['ticket_type_price_detail']['ticketType'],
            'price' => (float)$data['ticket_type_price_detail']['pricetext'],
            'count' => 1,
            'types_count' => $types_count
        );
        $response['status'] = 1;
        $response['is_ticket_listing'] = 0;
        $response['is_prepaid'] = 1;
        $response['message'] = 'Scan successful';
        $response['data'] = $scan_data;
        return $response;
    }
    
    /**
     * @Name           : cancel
     * @Purpose        : to calcel order on third party.
     * @Params         : $data: array which is needed to cancel order.
     * @CreatedBy       : komal <komalgarg.intersoft@gmail.com> on date(31 May 2019)
     */
    public function cancel($data) {
        $this->load->library('ThirdParty');
        $thirdpartyObj = new \ThirdParty("wechat");
        /* call thirdparty(giftcard_v1) validate_card function to validate ticket code at LIAB */
        $thirdpartyObj->call('cancel', $data);
    }

    /* #endregion Wechat Module  : This module covers wechat api's  */
}

?>