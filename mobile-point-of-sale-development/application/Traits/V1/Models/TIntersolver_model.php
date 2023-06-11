<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TIntersolver_model  {
    function __construct() {
        // Call the Model constructor
        parent::__construct();
        $this->load->library('ThirdParty');

        $this->types = array(
            'adult' => 1,
            'baby' => 2,
            'infant' => 13,
            'child' => 3,
            'elderly' => 4,
            'handicapt' => 5,
            'student' => 6,
            'military' => 7,
            'youth' => 8,
            'senior' => 9,
            'custom' => 10,
            'family' => 11,
            'resident' => 12
        );
    }

    /* #region Scan Process Module : Covers all the api's used in intersolver scan process */

    /* @Method     : scan
     * @Purpose    : scan gift card and purchase a ticket of related museum
     */

    function scan($scan_data, $user_detail) {
        global $MPOS_LOGS;
        try {
            $current_time = date('Y-m-d H:i:s');
            $current_timezone = new \DateTimeZone($scan_data['time_zone']);
            $timezone_in_seconds = $current_timezone->getOffset(new \DateTime($current_time));
            $logs['intersolver_req_' . date('H:i:s')] = json_encode($scan_data);
            $logs['user_details_' . date('H:i:s')] = json_encode($user_detail);
            $tp_native_info = json_decode(TP_NATIVE_INFO, true);
            $intersolver_values = $tp_native_info['intersolver'];
            foreach ($intersolver_values as $service => $service_data) {
                /* check for the service of intersolver, pass belongs to */
                if (strpos($scan_data['pass_no'], $service_data['card_prefix']) !== false) {
                    $scan_data['service'] = $service;
                    $service_native_data = $service_data;
                    break;
                }
            }
            if (!empty($service_native_data)) {
                /* data from constants */
                if (in_array($user_detail->supplier_id, array_keys($service_native_data['supplier_detail']))) {
                    /* supplier belongs to intersolver */
                    $data['timezone'] = '+'.gmdate("H:i", $timezone_in_seconds);
                    $data['timezone_in_seconds'] = $timezone_in_seconds;
                    $curent_gm_time = gmdate('H:i:s');
                    $current_time = strtotime($curent_gm_time) + $timezone_in_seconds;
                    $supplier_id = $scan_data['supplier_id'] = $user_detail->supplier_id;
                    $museum_cashier_id = $user_detail->uid;
                    $museum_cashier_name = $user_detail->fname . ' ' . $user_detail->lname;
                    $current_date = gmdate('Y-m-d');
                    
                    /* for giftcard ticketcode start */
                    if ($service_native_data['third_party_code'] == 'giftcard') {
                        /* check in PT if pass is new or already scanned */
                        $data['action_performed'] = '0, MPOS_GIFTCARD';
                        $where = '(passNo = "' . $scan_data['pass_no'] . '" or without_elo_reference_no = "' . $scan_data['pass_no'] . '")';
                        $this->primarydb->db->select('prepaid_ticket_id, visitor_group_no, passNo, ticket_type, is_combi_ticket, title, quantity, group_price, group_quantity, used, museum_cashier_id, is_cancelled, action_performed, scanned_at, third_party_type, second_party_type, ticket_id, channel_type, valid_till', false);
                        $this->primarydb->db->from('prepaid_tickets');
                        $this->primarydb->db->where('museum_id', $supplier_id);
                        $this->primarydb->db->where($where);
                        $this->primarydb->db->order_by('prepaid_ticket_id', 'DESC');
                        $ticket_details = $this->primarydb->db->get();
                        $sale_detail = $ticket_details->result_array();
                        $logs['pt_query_' . date('H:i:s')] = $this->primarydb->db->last_query();

                        /* gift card is scanned atleast once */
                        if (!empty($sale_detail) && $sale_detail[0]['scanned_at'] !== '' && $sale_detail[0]['scanned_at'] !== NULL) {
                            $scanned_at = $sale_detail[0]['scanned_at'];
                            $scanned_date = date('Y-m-d', $sale_detail[0]['scanned_at']);
                            $scanned_time = $scanned_at + $timezone_in_seconds;
                        }

                        $logs['last_scanned_at'] = $scanned_date;
                        if (isset($scanned_date) && $scanned_date == $current_date) {
                            //gift card is scanned on same day
                            $response['status'] = 12;
                            $response['message'] = "Pass already redeemed on " . date('d M, Y H:i', $scanned_time);
                        } else {
                            $distributor_id = $where_pos['hotel_id'] = $service_native_data['distributor_id'];
                            $where_pos['museum_id'] = $supplier_id;
                            $where_pos['is_pos_list'] = 1;
                            $pos_ticket_detail = $this->primarydb->db->select("mec_id, museum_id")->from('pos_tickets')->where($where_pos)->get()->row_array();
                            $logs['query_from_pos_tickets_' . date('H:i:s')] = $this->primarydb->db->last_query();
                            if (empty($pos_ticket_detail) || $pos_ticket_detail['mec_id'] == '') {
                                $response['status'] = 12;
                                $response['message'] = 'No Tickets found';
                                $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
                                return $response;
                            }
                            $data['ticket_id'] = $ticket_id = $pos_ticket_detail['mec_id'];
                            $tickets = $this->find('modeventcontent', array(
                                'select' => 'mec_id, postingEventTitle as shortDesc, shortDesc as description, cod_id as museum_id, museum_name, is_own_capacity, own_capacity_id, shared_capacity_id, eventImage as image, highlights, barcode_type, is_reservation as ticket_class, timezone, ',
                                'where' => 'mec_id = ' . $ticket_id
                                    ), 'row_array'
                            );
                            $logs['query_from_modeventcontent_' . date('H:i:s')] = $this->primarydb->db->last_query();
                            if (!empty($tickets)) {
                                $data['tickets'] = $tickets;
                                $museum_id = $tickets['museum_id'];
                                $active_timeslot = array();
                                if($tickets['ticket_class'] == '1') {
                                    $headers = $this->all_headers(array(
                                        'hotel_id' => $where_pos['hotel_id'],
                                        'museum_id' => $museum_id,
                                        'ticket_id' => $ticket_id,
                                        'channel_type' => $sale_detail[0]['channel_type'],
                                        'user_id' => $museum_cashier_id,
                                        'action' => 'scan_from_intersolver'
                                    ));
                                    if ($tickets['shared_capacity_id'] > 0) {
                                        $getdata = $this->curl->request('CACHE', '/listcapacity', array(
                                            'type' => 'POST',
                                            'additional_headers' => $headers,
                                            'body' => array(
                                                    "ticket_id" => $ticket_id,
                                                    "from_date" => $current_date,
                                                    "to_date" => $current_date,
                                                    "shared_capacity_id" => $tickets['shared_capacity_id']
                                                )
                                        ));
                                        $available_capacity = json_decode($getdata, true);
                                        $current_time_HI = date('H:i', $current_time);
                                        foreach ($available_capacity['data'] as $hours) {
                                            foreach ($hours['timeslots'] as $timeslot) {
                                                $active_timeslot_count = $timeslot['total_capacity'] - $timeslot['bookings'];
                                                if ($active_timeslot_count > 0 && (($timeslot['from_time'] < $current_time_HI && $timeslot['to_time'] > $current_time_HI) || ($timeslot['from_time'] > $current_time_HI))) {
                                                   $active_timeslot = $timeslot;
                                                   break;
                                                }
                                            }
                                        }
                                    }
                                    if ($tickets['own_capacity_id'] > 0) {
                                        $getdata = $this->curl->request('CACHE', '/listcapacity', array(
                                            'type' => 'POST',
                                            'additional_headers' => $headers,
                                            'body' => array(
                                                "ticket_id" => $ticket_id, 
                                                "from_date" => $current_date, 
                                                "to_date" => $current_date, 
                                                "shared_capacity_id" => $tickets['own_capacity_id']
                                            )
                                        ));
                                        $available_capacity = json_decode($getdata, true);
                                        foreach ($available_capacity['data'] as $hours) {
                                            foreach ($hours['timeslots'] as $timeslot) {
                                                $own_timeslot_count = $timeslot['total_capacity'] - $timeslot['bookings'];
                                                if ($own_timeslot_count > 0 && (($timeslot['from_time'] < $current_time_HI && $timeslot['to_time'] > $current_time_HI) || ($timeslot['from_time'] > $current_time_HI))) {
                                                   $active_timeslot = $timeslot;
                                                   break;
                                                }
                                            }
                                        }
                                    }
                                    if((isset($own_timeslot_count) && $own_timeslot_count < 0) || (isset($active_timeslot_count) && $active_timeslot_count < 0)) {
                                        $active = 0;
                                    } else if(isset($own_timeslot_count) && $own_timeslot_count > 0) {
                                        $active = 1; 
                                    } else {
                                        $active = ($active_timeslot_count > 0) ? 1 : 0; 
                                    }
                                    $logs['active_timeslot and active and own_timeslot_count and active_timeslot_count'] = array(
                                        'active_timeslot' => $active_timeslot, 
                                        'active' => $active,
                                        'own_timeslot_count' => $own_timeslot_count,
                                        'active_timeslot_count' => $active_timeslot_count);
                                    if(!empty($active_timeslot) && $active == 1) {
                                        $data['from_time'] = $active_timeslot['from_time'];
                                        $data['to_time'] = $active_timeslot['to_time'];
                                        $data['timeslot'] = $active_timeslot['timeslot_type'];
                                        $data['selected_date'] = gmdate('Y-m-d');
                                        $data['booking_selected_date'] = '';
                                    } else {
                                        $response['status'] = 12;
                                        $response['message'] = 'No Available Timeslot found';
                                        $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
                                        return $response;
                                    }
                                } else {
                                    $data['selected_date'] = '';
                                    $data['from_time'] = '';
                                    $data['to_time'] = '';
                                    $data['timeslot'] = '';
                                    $data['booking_selected_date'] = gmdate('Y-m-d');
                                }
                            } else {
                                $response['status'] = 12;
                                $response['message'] = 'No Tickets found';
                                $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
                                return $response;
                            }
                            $company_detail = $this->primarydb->db->select("cashier_type, company, distributor_type, channel_id, channel_name, saledesk_id, saledesk_name, reseller_id, reseller_name, partner_name, currency_code, hex_code")->from('qr_codes')->where("cod_id", $distributor_id)->or_where("cod_id", $museum_id)->get()->result_array();
                            $logs['query_from_qr_codes_' . date('H:i:s')] = $this->primarydb->db->last_query();
                            foreach ($company_detail as $row) {
                                if ($row['cashier_type'] == "1") {
                                    $data['distributor_detail'] = $row;
                                } else if ($row['cashier_type'] == "2") {
                                    $data['supplier_detail'] = $row;
                                }
                            }
                            if (!$data['distributor_detail']) {
                                $response['status'] = 12;
                                $response['message'] = 'No Distributor Found';
                                $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
                                return $response;
                            }

                            $thirdpartyObj = new \ThirdParty("intersolver");
                            $data_to_tp = array(
                                'scanned_code' => $scan_data['pass_no'],
                                'service' => $scan_data['service'],
                                'museum_id' => $supplier_id,
                            );
                            $logs['data_to_validate_card_' . date('H:i:s')] = $data_to_tp;
                            /* call thirdparty(giftcard_v1) validate_card function to validate ticket code at LIAB */
                            $thirdparty_response = $thirdpartyObj->call('validate_card', $data_to_tp);
                            $logs['third_party_response_' . date('H:i:s')] = $thirdparty_response;
                            /* if get success response at LIAB then insert entries in financial tables else return error message. */
                            if (!empty($thirdparty_response['data']) && $thirdparty_response['data']['status'] == 'Success') {
                                $data['guest_name'] = isset($thirdparty_response['data']['get_card_response']['Card']['BrandName']) ? $thirdparty_response['data']['get_card_response']['Card']['BrandName'] : '';
                                /* get third party price based on card balance of getCard operation. */
                                $card_balance = isset($thirdparty_response['data']['get_card_response']['Card']['Balance']) ? $thirdparty_response['data']['get_card_response']['Card']['Balance'] : 0;
                                $balance_factor = isset($thirdparty_response['data']['get_card_response']['Card']['BalanceFactor']) ? $thirdparty_response['data']['get_card_response']['Card']['BalanceFactor'] : 100;
                                if (!$balance_factor) {
                                    $balance_factor = 100;
                                }
                                $data['third_party_ticket_price'] = $card_balance / $balance_factor;
                                unset($thirdparty_response['get_card_response']);
                                $hto_data = $this->primarydb->db->select("visitor_group_no, quantity, total_price, total_net_price")->from('hotel_ticket_overview')->where("passNo", $scan_data['pass_no'])->get()->row_array();
                                $logs['hto_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                                $visitor_group_no = '';
                                $hto_record = array();
                                if ($hto_data['visitor_group_no'] != '' && $hto_data['visitor_group_no'] > 0) {
                                    $visitor_group_no = $hto_data['visitor_group_no'];
                                    $hto_record = $hto_data;
                                }
                                /* set ticket_type based on card balance. */
                                if ($data['third_party_ticket_price'] >= '64.90') {
                                    $data['ticket_type'] = $ticket_type = 'ADULT';
                                    $second_type = 'CHILD';
                                } else {
                                    $data['ticket_type'] = $ticket_type = 'CHILD';
                                    $second_type = 'ADULT';
                                }
                                $ticket_type_price_detail = $this->find('ticketpriceschedule', array(
                                    'select' => 'id as ticketpriceschedule_id, adjust_capacity, pax, ticketType, agefrom, ageto, pricetext, group_type_ticket, group_price, group_linked_with, min_qty, max_qty, original_price, newPrice, discountType, discount, saveamount, ticket_tax_value, ticket_net_price, third_party_ticket_type_id',
                                    'where' => 'ticket_id = ' . $ticket_id . ' and deleted = 0 ORDER BY FIELD (ticket_type_label, "' . $second_type . '", "' . $ticket_type . '") DESC limit 1',
                                        ), 'row_array'
                                );
                                $supplier_data = array();
                                $logs['query_from_tps_' . date('H:i:s')] = $this->primarydb->db->last_query();
                                $data['ticket_type_price_detail'] = $ticket_type_price_detail;
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
                                }
                                $price = $data['ticket_gross_price_amount'] = ($ticket_type_price_detail['newPrice'] > 0) ? $ticket_type_price_detail['newPrice'] : $ticket_type_price_detail['pricetext'];
                                $ticket_tax = $ticket_type_price_detail['ticket_tax_value'];
                                $data['supplier_data'] = $supplier_data;
                                $data['total_net_price_hto'] = ($price - ($price * $ticket_tax) / ($ticket_tax + 100));
                                $data['current_date_time'] = gmdate('Y-m-d') . ' ' . $curent_gm_time;
                                $data['device_time'] = $scan_data['device_time'];
                                $data['museum_cashier_name'] = $museum_cashier_name;
                                $data['ticket_code'] = $scan_data['pass_no'];
                                $data['pos_point_id'] = (isset($scan_data['pos_point_id']) && $scan_data['pos_point_id'] > 0) ? $scan_data['pos_point_id'] : 0;
                                $data['pos_point_name'] = (isset($scan_data['pos_point_name']) && $scan_data['pos_point_name'] != '') ? $scan_data['pos_point_name'] : '';
                                $data['distributor_id'] = $service_native_data['distributor_id'];
                                $data['dist_cashier_id'] = $user_detail->dist_cashier_id;
                                $data['dist_cashier_name'] = $user_detail->dist_cashier_name;
                                $data['visitor_group_no'] = $visitor_group_no;
                                $data['hto_record'] = $hto_record;
                                $data['supplier_id'] = $supplier_id;
                                $data['museum_cashier_id'] = $museum_cashier_id;
                                $data['museum_cashier_name'] = $museum_cashier_name;
                                $data['thirdparty_response'] = json_encode($thirdparty_response);
                                $MPOS_LOGS['scan_' . date('H:i:s')] = $logs;
                                return $response = $this->insert_financial_entries($data);
                            } else {
                                unset($thirdparty_response['purchase_failed']);
                                $response = $thirdparty_response;
                            }
                        }
                    } else {
                        $response['message'] = 'Not a Gift Card';
                    }
                } else {
                    $response['message'] = 'Not an Intersolver Museum';
                }
            } else {
                $response['message'] = 'Not an Intersolver Service';
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
     * @Purpose        : to insert entries in financial tables for intersolver.
     * Prepare data for HTO, PT, VT and send to queue
     */
    private function insert_financial_entries($data = array()) {
        global $MPOS_LOGS;
        try {
            $logs['data_to_update_' . date('H:i:s')] = $data;

            $data['hotel_ticket_overview_data'] = array();
            if ($data['visitor_group_no'] == '' && $data['visitor_group_no'] == 0) {
                $data['visitor_group_no'] = round(microtime(true) * 1000) . '' . rand(0, 9) . '' . rand(0, 9);
                $data['financial_details'] = $this->primarydb->db->select("financial_id, financial_name")->from("channels")->where("channel_id", $data['distributor_detail']->channel_id)->get()->row();
                $data['hotel_ticket_overview_data'] = 1;
            }
            $queue_data = array(
                'intersolver_ticket' => 1,
                'data' => $data,
                'supplier_data' => $data['supplier_data'],
                'action' => 'insert_financial_entries',
                'visitor_group_no' => $data['visitor_group_no'],
                'write_in_mpos_logs' => 1
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
            $queueUrl = UPDATE_DB_QUEUE_URL;
            // This Fn used to send notification with data on AWS panel. 
            $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $queue_data;
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                if ($MessageId) {
                    $sns_object->publish($queueUrl, UPDATE_DB_ARN); 
                }
            }
            $response = $this->set_response($data);
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
     * @Params         : $data: array which is needed to set response.
     */
    private function set_response($data = array()) {
        $types_count[] = array(
            "clustering_id" => 0,
            "tps_id" => (int) $data['ticket_type_price_detail']['ticketpriceschedule_id'],
            "ticket_type" => (int) $data['ticket_type_price_detail']['ticketType'],
            "price" => (float) $data['ticket_type_price_detail']['pricetext'],
            "tax" => (float) $data['ticket_type_price_detail']['ticket_tax_value'],
            "count" => 1
        );
        $scan_data = array(
            'ticket_id' => (isset($data['ticket_id'])) ? (int) $data['ticket_id'] : 0,
            'title' => (isset($data['tickets']['shortDesc'])) ? ($data['tickets']['shortDesc']) : '',
            'hotel_name' => $data['distributor_detail']['company'],
            'ticket_type' => (int) $data['ticket_type_price_detail']['ticketType'],
            'price' => (float) $data['ticket_type_price_detail']['pricetext'],
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

    /* #endregion Scan Process Module : Covers all the api's used in intersolver scan process */
}
?>