<?php

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TApi_model {

    function __construct() {
        // Call the Model constructor
        parent::__construct();
        $this->load->model('V1/venue_model');
        $this->load->model('V1/intersolver_model');
        $this->load->model('V1/preassigned_model');
        $this->api_channel_types = array(4, 6, 7, 8, 9, 13, 5, 0); // API Chanell types
        $this->is_invoice = array(10,11);// For MPOS Process
        $this->css_resellers_array = json_decode(CSS_RESELLERS, true);
        $this->liverpool_resellers_array = json_decode(LIVERPOOL_RESELLERS, true);
        $this->redeem_all_passes_on_one_scan_suppliers = json_decode(REDEEM_ALL_PASSES_ON_ONE_SCAN_SUPPLIERS, true);

    }

    function CSSNotifyLog($apiname, $paramsArray, $req_status = 1) {
        if (ENABLE_LOGS) {
            $log = 'CSS JSON API=>  Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $param) {
                    if ($i == 0) {
                        $log .= $param;
                    } else {
                        $log .= ', ' . $param;
                    }
                    $i++;
                }
                if ($req_status) {
                    $log .= '<br>, HEADERS: ' . json_encode(getallheaders());
                }
            }

            if (is_file('application/storage/logs/mpos_logs/CSSNotifyLog.php') && filesize("application/storage/logs/mpos_logs/CSSNotifyLog.php") > 1048576) {
                 rename("application/storage/logs/mpos_logs/CSSNotifyLog.php", "application/storage/logs/mpos_logs/CSSNotifyLog_" . date("m-d-Y_H-i-s") . ".php");
            }
            $fp = fopen('application/storage/logs/mpos_logs/CSSNotifyLog.php', 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }
    
    /* #region Scan Process Module  : Cover all the functions used in scanning process of mpos */

    /**
     * @Name     : scan_pass().
     * @Purpose  : Used to scan countdown tickets and simple ticket for prepaid tickets
     * @Called   : called from merchantlogin method.
     * @parameters : $email_id => users unique email_id
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 23 Nov 2017
     */
    function scan_pass($user_detail = array(), $scan_data = array()) {
        global $MPOS_LOGS;
        try{
            $preassigned_voucher_group = isset($scan_data['preassigned_voucher_group']) ? $scan_data['preassigned_voucher_group'] : 0;
            //define empty element must to avoid object/array issue in android end        
            $data = array();
            $is_ticket_listing = $is_prepaid = $is_early_reservation_entry = $show_cluster_extend_option = 0;
            $show_group_checkin_listing = $redeem_cluster_tickets = $allow_adjustment = 0;
            $cluster_main_ticket_details = (object) array();
            // check if request is not for preassigned pass
            
            if(0 && isset($scan_data['intersolver']) && $scan_data['intersolver'] == 1) {
                $pass_data = $this->intersolver_model->scan($scan_data, $user_detail);
                $data = (!empty($pass_data['data'])) ? $pass_data['data'] : (object)array();
                $is_ticket_listing = (isset($pass_data['is_ticket_listing'])) ? $pass_data['is_ticket_listing'] : 0;
                $is_prepaid = (isset($pass_data['is_prepaid'])) ? $pass_data['is_prepaid'] : 0;
                $status = (isset($pass_data['status'])) ? $pass_data['status'] : 0;
                if (isset($pass_data['error_message'])) {
                    $message = $pass_data['error_message'];
                } else if (isset($pass_data['message'])) {
                    $message = $pass_data['message'];
                } else {
                    $message = 'Pass not valid';
                }
            } else {
                if ($preassigned_voucher_group != 1) {
                    //get data from prepaid_tickets
                    $prepaid_data = $this->get_pass_info_from_prepaid_tickets($user_detail, $scan_data);
                    if(!empty($prepaid_data['error_no']) && $prepaid_data['error_no'] > 0){ //some error in function
                        return $prepaid_data;
                    }
                    $is_prepaid = 1;
                    $is_ticket_listing = $prepaid_data['is_ticket_listing'];
                    $add_to_pass = $prepaid_data['add_to_pass'];
                    $is_cluster = $prepaid_data['is_cluster'];
                    $is_editable = $prepaid_data['is_editable'];
                    $show_group_checkin_listing = $prepaid_data['show_group_checkin_listing'];
                    $checkin_action = isset($prepaid_data['checkin_action']) ? $prepaid_data['checkin_action'] : 0;
                    $is_one_by_one_entry_allowed = (isset($prepaid_data['is_one_by_one_entry_allowed']) && $prepaid_data['is_one_by_one_entry_allowed']  == 1) ? true : false;
                    $show_cluster_extend_option = !empty($prepaid_data['show_cluster_extend_option']) ? $prepaid_data['show_cluster_extend_option'] : 0;
                    $cluster_main_ticket_details = !empty($prepaid_data['cluster_main_ticket_details']) ? $prepaid_data['cluster_main_ticket_details'] : (object) array();
                    $booking_note = (isset($prepaid_data['booking_note']) && $prepaid_data['booking_note'] != '') ? $prepaid_data['booking_note'] : "";
                    $data = $prepaid_data['data'];
                    $redeem_cluster_tickets = $prepaid_data['redeem_cluster_tickets'];
                    $status = $prepaid_data['status'];
                    $is_early_reservation_entry = $prepaid_data['is_early_reservation_entry'];
                    $allow_adjustment = $prepaid_data['allow_adjustment'];
                    $message = $prepaid_data['message'];
                    if ($status == 0 && $message == 'Pass not valid') {
                        //get data from pre_assigned_codes table
                        $preassigned_data = $this->preassigned_model->get_pass_info_from_preassigned($user_detail, $scan_data);
                        if(!empty($preassigned_data['error_no']) && $preassigned_data['error_no'] > 0) { //some error in function
                            return $preassigned_data;
                        }
                        $data = $preassigned_data['data'];
                        $is_prepaid = 0;
                        $add_to_pass = $preassigned_data['add_to_pass'];
                        $is_ticket_listing = $preassigned_data['is_ticket_listing'];
                        $is_cluster = $preassigned_data['is_cluster'];
                        $is_early_reservation_entry = 0;
                        $allow_adjustment = 0;
                        $redeem_cluster_tickets = 0;
                        $status = $preassigned_data['status'];
                        $message = ($preassigned_data['errorMessage']) ? $preassigned_data['errorMessage'] :  $preassigned_data['message'];
                        if ($status == 0 && $message == 'Pass not valid') {
                            //get data from HTO table for postpaid orders
                            $req_data['user_id'] = $user_detail->uid;
                            $req_data['pass_no'] = $scan_data['pass_no'];
                            $req_data['museum_id'] = $scan_data['museum_id'];
                            $req_data['device_time'] = $scan_data['device_time'];
                            $is_prepaid = 1; 
                            //for postpaid orders
                            $postpaid_tickets = $this->venue_model->get_postpaid_tickets_listing($req_data);                   
                            if(!empty($postpaid_tickets['error_no']) && $postpaid_tickets['error_no'] > 0){ //some error in function
                                return $postpaid_tickets;
                            }
                            $data = $postpaid_tickets['data'];
                            $status = $postpaid_tickets['status'];
                            $message = $postpaid_tickets['message'];
                            if($status == 0 || $status == 12){
                                $is_ticket_listing = 0;
                                $add_to_pass = 0;
                                $show_cluster_extend_option = 0;
                                $is_early_reservation_entry = 0;
                            } else {
                                return $postpaid_tickets;
                            }
                        }
                    }
                } else {
                    //get data from pre_assigned_codes table
                    $preassigned_data = $this->preassigned_model->get_pass_info_from_preassigned($user_detail, $scan_data);
                    if(!empty($preassigned_data['error_no']) && $preassigned_data['error_no'] > 0){ //some error in function
                        return $preassigned_data;
                    }
                    $data = $preassigned_data['data'];
                    $add_to_pass = $preassigned_data['add_to_pass'];
                    $is_ticket_listing = $preassigned_data['is_ticket_listing'];
                    $status = $preassigned_data['status'];
                    $message = $preassigned_data['message'];
                }
            }
            if (!empty($data)) {
                //replace null with blank
                array_walk_recursive($data, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no' && $key != 'upsell_ticket_ids' && $key != 'prepaid_ticket_id' && $key != 'reservation_date' && $key != 'child_pass_no' && $key != 'from_time' && $key != 'to_time' && $key != 'selected_date' && $key != 'timeslot' && !(is_numeric($key))) {
                        $item = is_numeric($item) ? (int) $item : $item;
                    } else if ($key === 'price') {
                        $item = $item ? (float) $item : $item;
                    } else if ($key == 'upsell_ticket_ids' || $key == 'reservation_date' || $key == 'from_time' || $key == 'to_time' || $key == 'selected_date' || $key == 'timeslot' || $key == 'child_pass_no' || $key == 'prepaid_ticket_id' ) {
                        $item = (string) $item;
                    }
                    
                    $item = null === $item ? '' : $item;
                });
            }
            
            $show_group_checkin_listing = !empty($show_group_checkin_listing) ? $show_group_checkin_listing : 0;
            $checkin_action = !empty($checkin_action) ? $checkin_action : 0;
            $redeem_cluster_tickets = !empty($redeem_cluster_tickets) ? $redeem_cluster_tickets : 0;
            $allow_adjustment = !empty($allow_adjustment) ? $allow_adjustment : 0;
            $is_cluster = !empty($is_cluster) ? $is_cluster : 0;
            $is_editable = !empty($is_editable) ? $is_editable : 0;
            //in is_ticket_listing = 1 cases, add_to_pass value is used from data array.
            $response = array('status' => $status, 'is_early_reservation_entry' => $is_early_reservation_entry, 'show_one_by_one_entry_allowed' => $is_one_by_one_entry_allowed, 'allow_re_entry' => 0, 'is_editable' => $is_editable, 'show_group_checkin_listing' => $show_group_checkin_listing, 'checkin_action' => $checkin_action, 'add_to_pass' => (int) $add_to_pass, 'is_ticket_listing' => ($is_ticket_listing != null) ? $is_ticket_listing : 0, 'is_cluster' => $is_cluster, 'redeem_cluster_tickets' => $redeem_cluster_tickets, 'allow_adjustment' => $allow_adjustment, 'is_prepaid' => $is_prepaid, 'message' => $message, 'show_cluster_extend_option' => $show_cluster_extend_option, 'cluster_main_ticket_details' => $cluster_main_ticket_details, 'booking_note' => $booking_note, 'data' => $data);
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        return $response;
    }

    /**
     * @Name     : get_pass_info_from_prepaid_tickets()
     * @Purpose  : to return the pass information from prepaid_tickets
     * @Called   : called from scan_pass method.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 20 Nov 2017
     */
    function get_pass_info_from_prepaid_tickets($museum_info = array(), $data = array()) {
        global $MPOS_LOGS;
        global $spcl_ref;
        global $internal_logs;
        // try{
            $museum_id = $data['museum_id'];
            $reseller_id = $museum_info->reseller_id; //login with reseller user
            $reseller_cashier_id = $museum_info->reseller_cashier_id;
            $reseller_cashier_name = $museum_info->reseller_cashier_name;
            $cashier_type = $museum_info->cashier_type;
            $pass_no = isset($data['pass_no']) ? addslashes(trim($data['pass_no'])) : '';
            $add_to_pass = isset($data['add_to_pass']) ? $data['add_to_pass'] : 2; //2 -> add_to_pass has to be fetched on ticket level
            $pos_point_id = isset($data['pos_point_id']) ? $data['pos_point_id'] : 0;
            $pos_point_name = isset($data['pos_point_name']) ? $data['pos_point_name'] : '';
            $redeem_cluster_tickets = $data['redeem_cluster_tickets'];
            $ticket_id = $ticket_id_in_req = (isset($data['ticket_id']) && $data['ticket_id'] > 0) ? $data['ticket_id'] : 0;
            $is_edited = $data['is_edited'];
            $selected_date = isset($data['selected_date']) ? $data['selected_date'] : '';
            $from_time = isset($data['from_time']) ? $data['from_time'] : '';
            $to_time = isset($data['to_time']) ? $data['to_time'] : '';
            $slot_type = isset($data['slot_type']) ? $data['slot_type'] : '';
            $shared_capacity_id = isset($data['shared_capacity_id']) ? $data['shared_capacity_id'] : '';
            $own_capacity_id = isset($data['own_capacity_id']) ? $data['own_capacity_id'] : '';
            $device_time = $data['device_time']; //from formatted_device_time of req
            $formatted_device_time = $data['formatted_device_time'];
            $one_by_one_checkin = (isset($data['one_by_one_checkin']) && $data['one_by_one_checkin'] == true) ? 1 : 0;
            $gmt_device_time = $data['gmt_device_time'];
            $is_request_redeem = isset($data['is_redeem']) && $data['is_redeem'] == 1 ? 1 : 0 ;
            $allow_entry = $data['allow_entry'] ;
            $shift_id = ($data['shift_id'] > 0) ? $data['shift_id'] : 0;
            $device_time_check = $device_time / 1000;
            $timezone = $data['time_zone'];
            $is_error = $is_ticket_listing = $show_cluster_extend_option = $expired = $is_redeem = $is_editable = $update_on_pass_no = $reservation_ticket = $valid_till_time = $unpaid_order = $total_price_update = $already_cancelled = $redeem_all_passes_on_one_scan = 0;
            $pt_response = $cluster_main_ticket_details = $results = array();
            //get the exact pass number

            if (strstr($pass_no, 'http')) {
                $pass_no = substr($pass_no, -PASSLENGTH);
            }
            $user_id = $museum_info->uid;
            $user_name = $museum_info->fname . ' ' . $museum_info->lname;
            // condition on reference no in case of api passes (given by Gagan sir) in search query from PT
            if (substr($pass_no, 0, 3) == "CXS" || substr($pass_no, 0, 3) == "CSS") {
                $third_party = 1;
                $pass_condition = ' or without_elo_reference_no = "' . $pass_no . '"';
            } else {
                $third_party = 0;
                $pass_condition = '';
            }
            
            $alpha_numeric_pass = is_numeric($pass_no) ? 'visitor_group_no="' . $pass_no . '" or ' : '';
            $where = 'visitor_group_no !="0" and (passNo="' . $pass_no . '" or '.$alpha_numeric_pass.'bleep_pass_no="' . $pass_no . '" ' . $pass_condition . ') and is_prioticket != "1" and is_refunded != "1" and deleted = "0" and (order_status = "0" or order_status = "2" or order_status = "3")';
            if ($ticket_id > 0) {
                $where .= ' and ticket_id ='. $ticket_id;
            }

            $select = 'activated, reseller_id, pax, capacity, is_prioticket, extra_booking_information, prepaid_ticket_id,used, is_refunded, tax_name, hotel_ticket_overview_id,additional_information, action_performed, payment_conditions, extra_discount, channel_type, third_party_type, second_party_type, without_elo_reference_no, combi_discount_gross_amount, redeem_users, is_addon_ticket, clustering_id, is_combi_ticket, distributor_partner_id, distributor_partner_name, visitor_tickets_id, museum_id, museum_name, ticket_id, tps_id, title, price, ticket_type, age_group, hotel_id, hotel_name, cashier_name, pos_point_name, tax, visitor_group_no, passNo, pass_type, bleep_pass_no, second_party_passNo, second_party_type, third_party_type, group_price, activation_method, booking_status, used, channel_type, scanned_at, is_prepaid, additional_information, selected_date, from_time, to_time, timeslot, timezone,created_date_time,cashier_id, museum_cashier_id, museum_cashier_name,shift_id, related_product_id, is_invoice';
            $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where, 'order_by' => 'created_date_time DESC'), 'object');
            $logs['prepaid_query_'.date('H:i:s')] = array($this->primarydb->db->last_query());
            $is_scan_countdown = 1;

            if(isset($reseller_id) && $reseller_id != '' && $cashier_type == "3"){
                $pass_supplier_ids = array_column($prepaid_data, 'museum_id');
                if(!empty($pass_supplier_ids)) {
                    $fetch_suppliers_reseller = $this->find('qr_codes', array('select' => 'cod_id, reseller_id', 'where' => ' cod_id IN( "'.implode('","', $pass_supplier_ids).'") '), 'list');
                    $fetch_all_suppliers = $this->find('qr_codes', array('select' => 'reseller_id, cod_id', 'where' => ' reseller_id IN( "'.implode('","',$fetch_suppliers_reseller).'") and cashier_type = "2"'), 'array');               
                    foreach($fetch_all_suppliers as $supplier_details) {
                        $reseller_suppliers[$supplier_details['reseller_id']][] = $supplier_details['cod_id'];
                    }
                }
                $logs['resellers-'.date('H:i:s')] = $reseller_suppliers;
                $logs['qr_codes_query_'.date('H:i:s')] = array($this->primarydb->db->last_query());
            }
            if (!empty($prepaid_data)) {
                $internal_log['prepaid_query_data'] = $prepaid_data;
                $pt_data = $timezone = $already_cancelled = $citysightseen_card = 0;
                $already_redeemed = $check_activated = 1;
                $already_redeemed_time = $clustring_ids = $passed_vgn = $passed_passNo = '';

                foreach ($prepaid_data as $pt_result) {
                    $valid_till_time = ($pt_result->payment_conditions != Null && $pt_result->payment_conditions != '') ? $pt_result->payment_conditions : 0;
                    if($pt_result->used == "1" && (($pt_result->museum_id == $museum_id) || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_result->museum_id]])))) {
                        $already_redeemed_time = $pt_result->scanned_at;
                    }
                    if($pt_result->clustering_id != "0" && $pt_result->clustering_id != "") {
                        //get all clustering ids to handle cluster case of DPOS
                        $clustring_ids_array[] = $pt_result->clustering_id;
                    }
                    $timezone = $pt_result->timezone;
                    $current_time = strtotime(gmdate('m/d/Y H:i:s'));
                    //check if pass is already cancelled, redeemed or expired
                    if($pt_result->activated == "0" && strpos($pt_result->action_performed, 'UPSELL') === false) {
                        $already_cancelled = 1;
                    } else if(strpos($pt_result->action_performed, 'UPSELL') !== false) { 
                        if(strpos($pt_result->action_performed, 'UPSELL_INSERT') !== false && $pt_result->activated == "0") { //48
                            $already_cancelled = 1;
                        } else if($pt_result->activated == "1"){
                            $already_cancelled = 0;
                            $results[] = $pt_result;
                        }
                    } else if((($pt_result->museum_id == $museum_id) || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_result->museum_id]]))) && ($passed_vgn == '' || $passed_vgn == $pt_result->visitor_group_no) && ($passed_passNo == '' || $passed_passNo == $pt_result->passNo)) { //for city cards assigned to multiple orders , passNo for preassigned
                        if ($pt_result->used == 0 && (($pt_result->museum_id == $museum_id) || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_result->museum_id]])))) {
                            $already_redeemed = 0;
                        }
                        if($pt_result->selected_date != '' && $pt_result->selected_date !== '0' && $pt_result->from_time != '' && $pt_result->from_time !== '0' && $pt_result->to_time != '' && $pt_result->from_time !== '0') {
                            //reservation ticket
                            $must_be_created_after = gmdate('Y-m-d', strtotime('-3 months'));
                            $reservation_ticket = 1;
                        } else {
                            //booking ticket
                            $must_be_created_after = gmdate('Y-m-d', strtotime('-1 years'));
                        }
                        
                        $cs_expiry_time = gmdate('Y-m-d', strtotime($pt_result->selected_date.' +12 months'));
                        $css_campaign_data = json_decode(CSS_CAMPAIGN_DATA, true);
                        $css_distributors = array_keys($css_campaign_data);
                        $css_suppliers = isset($css_campaign_data[$pt_result->hotel_id]) ? $css_campaign_data[$pt_result->hotel_id]['suppliers'] : array();
                        $cs_third_party_ticket = 0;
                        if (in_array($pt_result->hotel_id, $css_distributors) && 
                                in_array($pt_result->museum_id, $css_suppliers) &&
                                strtotime($pt_result->created_date_time) >= $css_campaign_data[$pt_result->hotel_id]['campaing_start_date'] && 
                                strtotime($pt_result->created_date_time) < $css_campaign_data[$pt_result->hotel_id]['campaing_end_date'] &&
                                strtotime($pt_result->selected_date.' +12 months') < $css_campaign_data[$pt_result->hotel_id]['expiry_time'] && 
                                $device_time_check < $css_campaign_data[$pt_result->hotel_id]['expiry_time']) {
                            //purchased in between of capaign and now scanning before expiring time
                            $results[] = $pt_result;
                            $passed_vgn = $pt_result->visitor_group_no;
                            if ($pt_result->channel_type == 5) {
                                $passed_passNo = $pt_result->passNo;
                            }
                        } else if(($pt_result->second_party_type == 5 || $pt_result->third_party_type == 5)  && 
                                (date("Y-m-d", $device_time_check) <= $cs_expiry_time )) {
                                    $cs_third_party_ticket = 1;
                                    $results[] = $pt_result;
                                    $passed_vgn = $pt_result->visitor_group_no;
                                    if ($pt_result->channel_type == 5) {
                                        $passed_passNo = $pt_result->passNo;
                                    }
                        } 
                    #region start - To check If Scanned pass is expired   
                        if($reservation_ticket == 1 && date("Y-m-d", strtotime($pt_result->selected_date)) <= $must_be_created_after && $cs_third_party_ticket == 0) {
                            $expired = 1; //reservation passes within 3 months are scannable from mpos
                            $logs['reservation ticket  expired'] = $expired;
                            $expired_on = date('d M, Y', strtotime($pt_result->selected_date." +3 months -1 days"));
                        } else if($reservation_ticket == 0 && date("Y-m-d", strtotime($pt_result->created_date_time)) <= $must_be_created_after) {
                            $expired = 1; //booking tickets within one year are scannable from mpos
                            $expired_on = date('d M, Y', strtotime($pt_result->created_date_time." +1 years -1 days"));
                            $logs['booking ticket  expired'] = $expired;
                        } else if ($valid_till_time > 0 && $valid_till_time < $current_time) {
                            $expired = 1;//activated pass has been expired (payment_conditions)
                            $expired_on = date('d M, Y H:i', $valid_till_time + ($timezone * 3600));
                            $logs['paymnet conditions expired'] = $expired;
                        } else if (($valid_till_time == 0 || ($valid_till_time > 0 && $valid_till_time > $current_time)) && $cs_third_party_ticket == 0) {
                            $logs['conditions_passed_3'] = array('valid till'=> $valid_till_time, 'current' => $current_time);
                            $results[] = $pt_result; //pass is activated but still scannable as per timer case
                            $passed_vgn = $pt_result->visitor_group_no;
                            if($valid_till_time > 0 && $valid_till_time > $current_time){
                                $already_redeemed = 0;
                            }
                            if ($pt_result->channel_type == 5) {
                                $passed_passNo = $pt_result->passNo;
                            }
                        }
                        if (($pt_result->second_party_type == 5 || $pt_result->third_party_type == 5)  && 
                                (date("Y-m-d", $device_time_check) > $cs_expiry_time )) {
                            $expired = 1;
                            $expired_on =  date('d M, Y', strtotime($cs_expiry_time));
                            $logs['CS ticket  expired'] = $expired;
                        }
                        if (in_array($pt_result->hotel_id, $css_distributors) && 
                                in_array($pt_result->museum_id, $css_suppliers) &&
                                strtotime($pt_result->created_date_time) >= $css_campaign_data[$pt_result->hotel_id]['campaing_start_date'] && 
                                strtotime($pt_result->created_date_time) < $css_campaign_data[$pt_result->hotel_id]['campaing_end_date'] &&
                                strtotime($pt_result->selected_date.' +12 months') < $css_campaign_data[$pt_result->hotel_id]['expiry_time'] && 
                                $device_time_check >= $css_campaign_data[$pt_result->hotel_id]['expiry_time']) {
                            //purchased in between of capaign and now scanning before expiring time
                            $expired = 1;
                            $expired_on =  date('d M, Y', strtotime(date('Y-m-d', $css_campaign_data[$pt_result->hotel_id]['expiry_time'])." -1 days"));
                            $logs['CSS ticket expired'] = $expired;
                        }
                    #region end - To check If Scanned pass is expired
                    }
                    if($pt_result->booking_status == "0" && $pt_result->channel_type != "2") { // unpaid orders other than group booking
                        $unpaid_order = 1;
                    }
                    if(strpos($pt_result->action_performed, 'UPSELL_INSERT') !== false && $pt_result->activated == '1') {
                        $check_activated = 0; //don't check activated for pt n ticket_status for VT to be 1, 
                        /*reason : we allow scan for refunded enteries as well (on basis of activated 1),
                         but upsell - 24hr has activated 0, but it is also redemmed with upsell - 48 redemption, 
                         so for upsell tickets, activated check is ignored
                         */
                    }
                }
                if(!empty(array_unique($clustring_ids_array))) {
                    $clustring_ids = implode("','", array_unique($clustring_ids_array));
                    $clustring_ids = "'".$clustring_ids."'";
                }
                //return error in case of deactivated Pass
                $logs['results size after all checks'] = count($results);
                if ($already_cancelled == 1 && empty($results)) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Order is already deactivated');
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                //return error in case of pass expired
                if ($unpaid_order == 1) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Order is not confirmed or in pending state');
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                //return error in case of pass expired
                if ($expired == 1) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, '', $expired, $expired_on);
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                $internal_log['pt_data_after_expired_checks'] = count($results);
                $all_used = 1;
                $first_time = 1;
                /* To get logged in suppler record which are not used */
                foreach ($results as $pt_result) {
                    if($pt_result->clustering_id != '' && $pt_result->is_addon_ticket == "2") {
                        $ticketArr[$pt_result->ticket_id] = $pt_result->related_product_id;
                    }
                    if ((empty($pt_result->bleep_pass_no) || $pt_result->bleep_pass_no == '') && $third_party == 1) {
                        $pass_no = $pt_result->passNo;
                    }
                    if (($pt_result->museum_id == $museum_id) || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_result->museum_id]])) ) {
                        $ticket_id = $pt_result->ticket_id;
                        if ($first_time == "1") {
                            $result = $pt_result;
                        }
                        $first_time ++;
                        if ($pt_result->used == '0' || $pt_result->used == '') {
                            $all_used = 0;
                            break;
                        }
                    }
                }
                $main_ticket_id  = '';
                // check if ticket is main ticket or sub ticket
                if($result->is_addon_ticket == "2") {
                    $main_ticket_id = $ticketArr[$result->ticket_id];
                }
                //check if pass is scanned by same supplier user
                if (empty($result)) {
                    $is_error = 1;
                    $logs['pass_not_valid'] = $msg = 'This Pass belongs to a different supplier';
                }
                $types_count = array();
                $is_one_by_one_entry_allowed = 0;
                $supplier_hotel_id = 0;
                $redeem_same_supplier_cluster_tickets = 0;
                $redeemed_clustering_ids = '';
                $museum_details = $this->find('qr_codes', array('select' => 'cod_id, own_supplier_id, is_group_check_in_allowed, is_group_entry_allowed, address, mpos_settings', 'where' => 'cod_id = "' . $museum_id . '" or own_supplier_id = "'. $museum_id .'"'));
                $logs['museum_details_query'] = $this->primarydb->db->last_query();
                foreach($museum_details as $details) {
                    if($details['cod_id'] == $museum_id) {
                        $details['mpos_settings'] = stripslashes($details['mpos_settings']);
                        $mpos_settings = json_decode($details['mpos_settings'],TRUE);
                        if($mpos_settings['is_one_by_one_entry_allowed'] == 1){
                            $is_one_by_one_entry_allowed = 1;
                        }
                        if($mpos_settings['redeem_cluster_tickets_for_supplier'] == 1){
                            $redeem_same_supplier_cluster_tickets = 1;
                        } 
                        $is_group_check_in_allowed = $details['is_group_check_in_allowed'];
                        $is_group_entry_allowed = $details['is_group_entry_allowed'];
                    } else if($details['own_supplier_id'] == $museum_id){
                        $supplier_hotel_id = $details['cod_id']; 
                    }
                }
                $ticketCheck[] =  $result->ticket_id;

				if($main_ticket_id != '') {
                   array_push($ticketCheck, $main_ticket_id );
                }
                $logs['redeem_same_supplier_cluster_tickets'] = $redeem_same_supplier_cluster_tickets;
                // $ticket_status = $this->find('modeventcontent', array('select' => 'allow_city_card, guest_notification, second_party_id, startDate, endDate, is_reservation,own_capacity_id,shared_capacity_id, second_party_parameters, third_party_parameters, is_scan_countdown, countdown_interval, upsell_ticket_ids, merchant_admin_id, merchant_admin_name, cod_id, capacity_type, grace_time_enable, grace_time_before, grace_time_before_type,grace_time_after, grace_time_after_type', 'where' => 'mec_id = "' . $result->ticket_id . '"'), 'row_object');
                $ticketDetails = $this->find('modeventcontent', array('select' => 'mec_id, allow_city_card, guest_notification, second_party_id, startDate, endDate, is_reservation,own_capacity_id,shared_capacity_id, second_party_parameters, third_party_parameters, is_scan_countdown, countdown_interval, upsell_ticket_ids, merchant_admin_id, merchant_admin_name, cod_id, capacity_type, grace_time_enable, grace_time_before, grace_time_before_type,grace_time_after, grace_time_after_type, is_combi', 'where' => 'mec_id In( "' . implode('","', $ticketCheck) . '") '), 'object');
                
                foreach($ticketDetails as $details) {
                    $newTicketDetail[$details->mec_id] = $details;
                }
                $ticket_status = $newTicketDetail[$result->ticket_id];
                
                if($ticket_status->grace_time_enable) { //this ticket is allowed for grace period
                    
                }
                if($add_to_pass == 2){ //city card on/off is based on tickets instead of users
                    $add_to_pass = $ticket_status->allow_city_card;
                }
                if($main_ticket_id != '' && $result->is_addon_ticket == '2') {// fetch main ticket details
                    $mainTicketDetails = $newTicketDetail[$result->related_product_id];
                    $logs['mainticketDetails_'.date('H:i:s')] = $mainTicketDetails;
                }

                $is_scan_countdown = $ticket_status->is_scan_countdown;
                $logs['mec_query_data_'.date('H:i:s')] = array($this->primarydb->db->last_query(), 'is_scan_countdown' => $is_scan_countdown, 'all_used' => $all_used);
                $logs['mec_query_details'] = array($ticket_status);
                /* In case of "add to pass = 1" then first time scan any pass then send response  with "$citysightseen_card=1" */
                $citysightseen_card = 0;
                if ($add_to_pass == '1' && empty($result->bleep_pass_no)) {
                    $citysightseen_card = 1;
                }

                #region start - To Validate Scanned pass
                
                /* If any pass scan for reddem purpose then museum_id matched with loged in museum_id and also check if bleeppass not empty then scanned pass no shold be equal to bleep pass no otherwise send error message */
                if($add_to_pass == '0' && $pass_no == $result->visitor_group_no) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Please, scan the pass number ');
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                //return error if pass is scanned but citycards are activated
                if(!empty($result->bleep_pass_no) && $result->bleep_pass_no != $pass_no) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Please scan the citycard.');
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                //Return error if scanned pass belongs to other supplier
                if (($result->museum_id != $museum_id && $cashier_type !=  "3") || ($cashier_type ==  "3" && !empty($reseller_suppliers) && !in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$result->museum_id]]))  ) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'This Pass belongs to a different supplier');
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                $early_redeem = 0;
                $timezone_count = 3600 * $result->timezone;
                //check case of early redeem
                if ($citysightseen_card == 0 && !empty($result->selected_date) && (strtotime($result->selected_date) > strtotime(gmdate('Y-m-d'))) && $ticket_status->is_reservation == '1') {
                    $early_redeem = 1;
                    $logs['early_redeem'] = 'reservation ticket date is not today';
                }         
                // check for late entry
                $is_early_reservation_entry = 0; 
                $late_redeem = 0;
                $late_checkin_time = '';
                if($is_request_redeem == 0 && (isset($allow_entry) && !empty($allow_entry))  && $result->used == 0 && !empty($result->selected_date) && ((strtotime($result->selected_date)) < strtotime(gmdate('Y-m-d'))) && $result->channel_type != 5 && $ticket_status->is_reservation == '1') {
                    $late_redeem = 1;
                    $selected_time = date('Y-m-d H:i', strtotime($result->selected_date.''.$result->from_time));
                    $late_checkin_time = $this->get_difference_bw_time($selected_time) . ' late';
                }
                $city_card_off_late_redeem = ($late_redeem == 1 && $citysightseen_card == 0) ? 1 : 0 ;
                $logs['late_redeem'] = $late_redeem;
                $logs['city_card_off_late_redeem'] = $city_card_off_late_redeem;
                if ($is_error) {
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (0, $is_ticket_listing, $add_to_pass, $msg);
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets'] = $logs;                
                    return $pt_response;
                }
                
                $upsell_ticket_ids = '';
                if (!empty($ticket_status)) {
                    $is_scan_countdown = $ticket_status->is_scan_countdown;
                    $countdown_interval = $ticket_status->countdown_interval;
                    $is_reservation = $ticket_status->is_reservation;
                    $own_capacity_id = $ticket_status->own_capacity_id;
                    $shared_capacity_id = $ticket_status->shared_capacity_id;
                    $upsell_ticket_ids = $ticket_status->upsell_ticket_ids;
                    $current_time = strtotime(gmdate("Y-m-d H:i", time() + ($ticket_status->timezone * 3600)));
                    $todayDateTime = date('Y-m-d H:i',$current_time);
                    $upsell_ticket_ids = $this->checkUpsellTicketIdsIfNotExpired($upsell_ticket_ids, $todayDateTime);
                }
                //Return error if pass is already redeemed
                if ((($is_scan_countdown == 0 && $already_redeemed == 1) || ($is_scan_countdown == 1 && empty($result->bleep_pass_no) && $result->used == "1")) && $add_to_pass == '1' ) {
                    $message = 'Pass already redeemed on ' . date('d M, Y H:i', $already_redeemed_time + ($timezone * 3600));
                    //prepaid_tickets response
                    $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, $message);
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_'.date('H:i:s')] = $logs;
                    return $pt_response;
                }
                //Return error if all of the tickets are used
                if ($is_scan_countdown == '0' && $all_used) {
                    //prepaid_tickets response
                    $message = 'Already Redeemed at ' . date('d M, Y H:i', $already_redeemed_time + ($timezone * 3600));
                    $pt_response = $this->common_model->return_pass_not_valid (0, $is_ticket_listing, $add_to_pass, $message);
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    $MPOS_LOGS['get_pass_info_from_prepaid_tickets_' . date('H:i:s')] = $logs;
                    return $pt_response;
                }
            
            #region end - To Validate Scanned pass
                
            #region start - To Display Tickets Listing on scan
                $ticket_listing = $prepaid_listing = $group_prepaid_listing = array();
                $allow_adjustment = 0;
                
                //check if it is cluster ticket to show on app
                foreach ($results as $pt_data) {
                    $logs['screen_params_check'] = array('is_group_check_in_allowed' => $is_group_check_in_allowed, 'is_group_entry_allowed' => $is_group_entry_allowed, 'citysightseen_card' => $citysightseen_card, 'is_one_by_one_entry_allowed' =>$is_one_by_one_entry_allowed, 'is_combi_ticket' => $pt_data->is_combi_ticket, 'museum_id_in_req' => $museum_id, 'museum_id' => $pt_data->museum_id, 'redeem_cluster_tickets' => $redeem_cluster_tickets, 'add_to_pass' => $add_to_pass, 'bleep_pass' => $results[0]->bleep_pass_no, 'one_by_one_checkin_in_req' => $one_by_one_checkin);
                    if (
                        ($museum_id == $pt_data->museum_id || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_data->museum_id]]))) && 
                        (!empty($pt_data->clustering_id) ) && $citysightseen_card == 0 && $redeem_cluster_tickets == 0 && $one_by_one_checkin == 0 && $pt_data->activated == '1'
                    ) {
                        $ticket_listing[$pt_data->ticket_id][] = $pt_data;
                        $prepaid_listing[] = $pt_data;
                    }
                    if ($add_to_pass == "0" && empty($results[0]->bleep_pass_no) && $one_by_one_checkin == "0" && $is_scan_countdown == 0 && (($museum_id == $pt_data->museum_id) || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_data->museum_id]]))) && (($is_group_check_in_allowed == "1" && $is_group_entry_allowed == "0" && $pt_data->is_combi_ticket == "1") || ($is_group_check_in_allowed == "0" && $is_group_entry_allowed == "1" && $pt_data->is_combi_ticket == "1") || ($is_group_check_in_allowed == "0" && $is_group_entry_allowed == "0" && $pt_data->is_combi_ticket == "1"))) {
                        $group_prepaid_listing[] = $pt_data;
                    }
                }
                if($add_to_pass == "0" && $is_scan_countdown && in_array($museum_id, $this->redeem_all_passes_on_one_scan_suppliers)) { //redeem_all_passes_on_one_scan case
                    $redeem_all_passes_on_one_scan = 1;
                }
                $logs['redeem_all_passes_on_one_scan'] = $redeem_all_passes_on_one_scan;
                //check if group checkin and one by one checkin exist in add to pass = 0
                if (($results[0]->is_combi_ticket == "0" || $one_by_one_checkin == "1") && $add_to_pass == "0" && empty($results[0]->bleep_pass_no) && $is_scan_countdown == 0 && $results[0]->channel_type != 5) {
                    $additional_information = unserialize($result->additional_information);
                    if ($results[0]->channel_type == 5 || (isset($additional_information['extended_preassigned']) && $additional_information['extended_preassigned'] == "1")) {
                        $where = '(passNo = "' . $pass_no . '" or bleep_pass_no = "' . $pass_no . '") and (order_status = "0" or order_status = "2" or order_status = "3") and is_refunded != "1" and deleted = "0" and activated= "1" ';
                    } else {
                        $where = 'visitor_group_no = "' . $results[0]->visitor_group_no . '" and (order_status = "0" or order_status = "2" or order_status = "3") and is_refunded != "1" and deleted = "0" and activated= "1" ';
                    }
                    if ($ticket_id > 0) {
                        $where .= ' and ticket_id = '. $ticket_id;
                    }
                    $select = "prepaid_ticket_id, pax, capacity, additional_information, extra_booking_information, used, hotel_ticket_overview_id, extra_discount, combi_discount_gross_amount, channel_type,  redeem_users, is_addon_ticket, clustering_id, is_combi_ticket, distributor_partner_id, distributor_partner_name, visitor_tickets_id, museum_id, museum_name, ticket_id, tps_id, title, price, ticket_type, age_group, hotel_id, hotel_name, cashier_name, pos_point_name, tax, visitor_group_no, passNo, bleep_pass_no, second_party_passNo, second_party_type, third_party_type, group_price, activation_method, booking_status, used, channel_type, action_performed, scanned_at, is_prepaid, additional_information, visitor_group_no, selected_date, from_time, to_time, timeslot, timezone,created_date_time,cashier_id, is_invoice";
                    $this->primarydb->db->protect_identifiers = FALSE;
                    $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');
                    $this->primarydb->db->protect_identifiers = TRUE;
                    $logs['select_pt'] = $this->primarydb->db->last_query();
                    $count_prepaid = 0;
                    foreach ($prepaid_data as $pt_data) {
                        $count_prepaid++;
                        if (($redeem_all_passes_on_one_scan == 0 ) && (($museum_id == $pt_data->museum_id || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$pt_data->museum_id]]))) && ($pt_data->is_combi_ticket == "0" && ($is_group_check_in_allowed == "0" || ($is_group_check_in_allowed == "1" && $is_group_entry_allowed == "0" ))) || $one_by_one_checkin == "1")) {
                            $single_prepaid_listing[] = $pt_data;
                        }
                        if (($is_group_check_in_allowed == "1" && $is_group_entry_allowed == "0" && $pt_data->is_combi_ticket == "0") || $one_by_one_checkin == "1") {
                            $allow_adjustment = 1;
                        }
                    }
                }
                $logs['ticket_listing'] = count($ticket_listing);
                $logs['group_listing_data'] = count($group_prepaid_listing);
                $logs['one_by_one_listing_data'] = count($single_prepaid_listing);
                $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                $logs = array();
                //If there are more than 1 ticket in case of cluster ticket. prepare array for that
				
                if (count($ticket_listing) > 1) {
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    //listing for cluster ticket -> in both add to pass on and off
                    return $this->get_prepaid_linked_ticket_listing($prepaid_listing, $add_to_pass, $pass_no, $selected_date, $from_time, $to_time, $slot_type, $supplier_hotel_id, $redeem_same_supplier_cluster_tickets);
                }
                //prepare array for group checkin
                if (!empty($group_prepaid_listing)) {
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    //group checkin -> add to pass off
                    return $this->get_prepaid_group_ticket_details(0, $group_prepaid_listing, $formatted_device_time, $add_to_pass, $pass_no, $allow_adjustment, $is_one_by_one_entry_allowed);
                }
                //prepare array for one_by_one_checkin
                if (!empty($single_prepaid_listing)) {
                    if (!empty($internal_log)) {
                        $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                    }
                    //one by one checkin -> add to pass off
                    return $this->get_prepaid_single_ticket_details(0, $single_prepaid_listing, $device_time_check, $add_to_pass, $pass_no, $allow_adjustment, $selected_date, $from_time, $to_time, $slot_type);
                }
            #region end - To Display Tickets Listing on scan

                $early_checkin_time = '';
                if ($early_redeem && empty($result->scanned_at) && $is_scan_countdown == '1' && $result->channel_type != 5) {
                    $is_early_reservation_entry = 1;
                    $timezone_count = 3600 * $result->timezone;
                    $selected_time = date('Y-m-d H:i', strtotime($result->selected_date) - $timezone_count);
                    $early_checkin_time = $this->get_difference_bw_time($selected_time) . ' early';
                }
                //get prepaid details to redeem
                if($redeem_all_passes_on_one_scan) {
                    $used_check = '';
                } else {
                    $used_check = ' and (used = "0" or used = "")';
                }
                $select = 'pax, capacity, additional_information, extra_booking_information, title, tax, tps_id ,ticket_id , clustering_id, group_price, group_quantity, quantity, ticket_type, extra_discount, combi_discount_gross_amount, channel_type, price, is_invoice';
                $where = 'visitor_group_no ='. $result->visitor_group_no. $used_check .' and (order_status = "0" or order_status = "2" or order_status = "3") and activated = "1" and deleted = "0" and is_refunded != "1"';
                if ($museum_id != '' && $add_to_pass == '0' && $cashier_type  != '3' ) {
                    $where .= ' and museum_id = '. $museum_id;
                }
                if ($pass_no != $result->visitor_group_no) {
                    if ($add_to_pass == '1' && $result->is_combi_ticket == '1') {
                        $where .= ' and (passNo="' . $pass_no . '" or bleep_pass_no="' . $pass_no . '") && ticket_id="' . $result->ticket_id . '"';
                    } else {
                        if ($result->channel_type == 5) {
                            $where .= ' and passNo = "'. $result->passNo.'" and ticket_id ='. $result->ticket_id;
                        } else {
                            $where .= ' and ticket_id ='. $result->ticket_id;
                        }
                    }
                }
                if($redeem_same_supplier_cluster_tickets == 1 && $result->clustering_id != '' && $result->clustering_id != NULL && $result->clustering_id != "0") {
                    $where .= ' and clustering_id IN ('.$clustring_ids.')';
                }
                if ($add_to_pass != "0") {
                    $where .= ' and is_addon_ticket = "0"';
                }
                
                $get_count = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');                
                $logs['types_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                $quantity = 0;
                $total_amount = 0;
                //Prepare types count array
                if (!empty($get_count)) {
                    $quantity = count($get_count);
                    $internal_log['data_for_types_array'] = $get_count;
                    foreach ($get_count as $prepaid_count) {
                        $additional_information = unserialize($prepaid_count->additional_information);
                        $discount = 0;
                        $extra_discount = unserialize($prepaid_count->extra_discount);
                        $discount = $extra_discount['gross_discount_amount'];
                        if (($prepaid_count->channel_type == 10 || $prepaid_count->channel_type == 11 || ($prepaid_count->channel_type == 6 && in_array($prepaid_count->is_invoice, $this->is_invoice ))) && $prepaid_count->combi_discount_gross_amount > 0) {
                            $discount = $discount + $prepaid_count->combi_discount_gross_amount;
                        }
                        if ($is_early_reservation_entry == 1 || $city_card_off_late_redeem == 1) {
                            if ($result->tps_id === $prepaid_count->tps_id && $redeem_all_passes_on_one_scan == 0 ) {
                                $types_count[$result->tps_id]['tps_id'] = $prepaid_count->tps_id;
                                $types_count[$result->tps_id]['ticket_type'] = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                                $types_count[$result->tps_id]['ticket_type_label'] = $prepaid_count->ticket_type;
                                $types_count[$result->tps_id]['title'] = $prepaid_count->title;
                                $types_count[$result->tps_id]['price'] = $prepaid_count->price + $discount;
                                $types_count[$result->tps_id]['pax'] = isset($prepaid_count->pax) ? (int) $prepaid_count->pax : (int) 0;
                                $types_count[$result->tps_id]['capacity'] = isset($prepaid_count->capacity) ? (int) $prepaid_count->capacity : (int) 1;
                                $types_count[$result->tps_id]['start_date'] = isset($ticket_status->startDate) ? (string) date('Y-m-d', $ticket_status->startDate) : '';
                                $types_count[$result->tps_id]['end_date'] = isset($ticket_status->endDate) ? (string) date('Y-m-d', $ticket_status->endDate) : '';
                                $types_count[$result->tps_id]['count'] = 1;
                                $total_amount = $prepaid_count->price;
                            }
                            if($redeem_all_passes_on_one_scan) {
                                $types_count[$prepaid_count->tps_id]['clustering_id'] = !empty($prepaid_count->clustering_id) ? (int) $prepaid_count->clustering_id : (int) 0;
                                $types_count[$prepaid_count->tps_id]['tps_id'] = $prepaid_count->tps_id;
                                $types_count[$prepaid_count->tps_id]['ticket_type'] = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                                $types_count[$prepaid_count->tps_id]['ticket_type_label'] = $prepaid_count->ticket_type;
                                $types_count[$prepaid_count->tps_id]['title'] = $prepaid_count->title;
                                $types_count[$prepaid_count->tps_id]['price'] = $prepaid_count->price + $discount;
                                $types_count[$prepaid_count->tps_id]['tax'] = $prepaid_count->tax;
                                $types_count[$prepaid_count->tps_id]['pax'] = isset($prepaid_count->pax) ? (int) $prepaid_count->pax : (int) 0;
                                $types_count[$prepaid_count->tps_id]['capacity'] = isset($prepaid_count->capacity) ? (int) $prepaid_count->capacity : (int) 1;
                                $types_count[$prepaid_count->tps_id]['start_date'] = isset($ticket_status->startDate) ? (string) date('Y-m-d', $ticket_status->startDate) : '';
                                $types_count[$prepaid_count->tps_id]['end_date'] = isset($ticket_status->endDate) ? (string) date('Y-m-d', $ticket_status->endDate) : '';
                                $types_count[$prepaid_count->tps_id]['count'] += 1;
                                $total_amount += $prepaid_count->price + $discount;
                            }
                        } else {
                            if (!empty($prepaid_count->clustering_id)) {
                                $types_count[$prepaid_count->clustering_id]['clustering_id'] = !empty($prepaid_count->clustering_id) ? (int) $prepaid_count->clustering_id : (int) 0;
                                $types_count[$prepaid_count->clustering_id]['tps_id'] = $prepaid_count->tps_id;
                                $types_count[$prepaid_count->clustering_id]['ticket_type'] = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                                $types_count[$prepaid_count->clustering_id]['ticket_type_label'] = $prepaid_count->ticket_type;
                                $types_count[$prepaid_count->clustering_id]['title'] = $prepaid_count->title;
                                $types_count[$prepaid_count->clustering_id]['price'] = $prepaid_count->price + $discount;
                                $types_count[$prepaid_count->clustering_id]['pax'] = isset($prepaid_count->pax) ? (int) $prepaid_count->pax : (int) 0;
                                $types_count[$prepaid_count->clustering_id]['capacity'] = isset($prepaid_count->capacity) ? (int) $prepaid_count->capacity : (int) 1;
                                $types_count[$prepaid_count->clustering_id]['start_date'] = isset($ticket_status->startDate) ? (string) date('Y-m-d', $ticket_status->startDate) : '';
                                $types_count[$prepaid_count->clustering_id]['end_date'] = isset($ticket_status->endDate) ? (string) date('Y-m-d', $ticket_status->endDate) : '';
                                $types_count[$prepaid_count->clustering_id]['tax'] = $prepaid_count->tax;
                                $types_count[$prepaid_count->clustering_id]['count'] += 1;
                                $total_amount += $prepaid_count->price + $discount;
                            } else {
                                $types_count[$prepaid_count->tps_id]['clustering_id'] = !empty($prepaid_count->clustering_id) ? (int) $prepaid_count->clustering_id : (int) 0;
                                $types_count[$prepaid_count->tps_id]['tps_id'] = $prepaid_count->tps_id;
                                $types_count[$prepaid_count->tps_id]['ticket_type'] = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                                $types_count[$prepaid_count->tps_id]['ticket_type_label'] = $prepaid_count->ticket_type;
                                $types_count[$prepaid_count->tps_id]['title'] = $prepaid_count->title;
                                $types_count[$prepaid_count->tps_id]['price'] = $prepaid_count->price + $discount;
                                $types_count[$prepaid_count->tps_id]['tax'] = $prepaid_count->tax;
                                $types_count[$prepaid_count->tps_id]['pax'] = isset($prepaid_count->pax) ? (int) $prepaid_count->pax : (int) 0;
                                $types_count[$prepaid_count->tps_id]['capacity'] = isset($prepaid_count->capacity) ? (int) $prepaid_count->capacity : (int) 1;
                                $types_count[$prepaid_count->tps_id]['start_date'] = isset($ticket_status->startDate) ? (string) date('Y-m-d', $ticket_status->startDate) : '';
                                $types_count[$prepaid_count->tps_id]['end_date'] = isset($ticket_status->endDate) ? (string) date('Y-m-d', $ticket_status->endDate) : '';
                                $types_count[$prepaid_count->tps_id]['count'] += 1;
                                $total_amount += $prepaid_count->price + $discount;
                            }
                        }
                    }
                }

                /* count PT records for redumption count in batch_rule table */
                $countPtRecords = $quantity;
                /* count PT records for redumption count in batch_rule table */
				
                /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
                $checkCashierLimit = true;
				
                /* don't execute cashier check limit if its one_by_one_checkin */
                if($one_by_one_checkin==1) {
                    $checkCashierLimit = false;
                }
				
                $valgroupcheckin = 1;
                /* don't execute cashier check limit if its group_check_in */
                if (($countPtRecords) < 2) {
                    $valgroupcheckin = 0;
                }
                if (($result->is_combi_ticket == '1' && $result->passNo == $pass_no) && $add_to_pass == '0') {
                    $valgroupcheckin = 0;
                }
                if (($is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1') && $add_to_pass == '0') {
                    $valgroupcheckin = 0;
                }
                if ($add_to_pass == "0" && ($museum_id != $result->museum_id || ($cashier_type ==  "3" && !empty($reseller_suppliers) && in_array($museum_id,$reseller_suppliers[$fetch_suppliers_reseller[$result->museum_id]]))) && $result->clustering_id > 0 && $result->is_addon_ticket != "0") {
                    $valgroupcheckin = 0;
                }
                if ($result->channel_type == 5) {
                    $valgroupcheckin = 0;
                }
                if($valgroupcheckin==1) {
                    $checkCashierLimit = false;
                }
				
                $is_check = 0;
                /* Checks to verify if its a redeem request */
                if ($result->channel_type == 5 && $is_reservation == 1) {
                    $current_day_v = strtotime(date("Y-m-d"));
                    if (!empty($from_time) && !empty($to_time) && !empty($selected_date)) {
                        $from_time_check_v 		= strtotime($from_time);
                        $to_time_check_v 		= strtotime($to_time);
                        $selected_date_check_v 	= strtotime($selected_date);
                    } else {
                        $from_time_check_v 		= strtotime($result->from_time);
                        $to_time_check_v 		= strtotime($result->to_time);
                        $selected_date_check_v 	= strtotime($result->selected_date);
                    }
                    if ($selected_date_check_v == $current_day_v) {
                        if (($from_time_check_v <= $device_time_check && $to_time_check_v >= $device_time_check) || ($is_scan_countdown == 1)) {
                            $is_check = 1;
                        } 
                    } else if($selected_date_check_v < $current_day_v) {
                        $is_check = ($is_edited == 0) ? 1 : 0;
                    } else  if($selected_date_check_v > $current_day_v) {
                        $is_check = ($is_edited == 0 || ($is_scan_countdown == 1 && $result->used==1 )) ? 1 :0;
                    }
                    if ($is_edited == 0) {
                        $is_check = 1;
                    }
                } 
                else if ($is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1' && $add_to_pass == '0' && $is_scan_countdown == 0 && $result->channel_type != 5) {
                    $is_check = 1;
                }
                else if ($is_early_reservation_entry == 0) {
                    $is_check = 1;
                }
				
            #region start - To validate batch
                if($checkCashierLimit && $is_check==1) {
					
                    $batch_rule_id = 0;
                    $limitArray = array("passNo" => $data['pass_no'], "shared_capacity_id" => $ticket_status->shared_capacity_id, 
                                        "cashier_id" => $museum_info->uid, 
                                        "cod_id" => $museum_id, "capacity_type" => $ticket_status->capacity_type, 
                                        "selected_date" => $selected_date, 
                                        "from_time" => (!empty($from_time)? $from_time: $result->from_time), 
                                        "to_time" => (!empty($to_time)? $to_time: $result->to_time), 
                                        "ticket_id" => $ticket_id);
                    $validate = $this->validate_cashier_limit($limitArray);
                    if(!empty($validate) && is_array($validate)) {
						
                        if($validate['status']=='batch_not_found') {
                            //prepaid_tickets response
                            $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Batch not found');
                            if (!empty($internal_log)) {
                                $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                            }
                            $MPOS_LOGS['get_pass_info_from_prepaid_tickets_' . date('H:i:s')] = $logs;
                            return $pt_response;
                        }
                        if($validate['status']=='qty_expired') {
                            //prepaid_tickets response
                            $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Batch capacity is full');
                            if (!empty($internal_log)) {
                                $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                            }
                            $MPOS_LOGS['get_pass_info_from_prepaid_tickets_' . date('H:i:s')] = $logs;
                            return $pt_response;
                        }
                        if($validate['status']=='redeem') {
                            $batch_rule_id  = $validate['batch_rule_id'];
							$last_scans = $validate['last_scan'];
                            $maximum_quantity  = $validate['maximum_quantity'];
                            $quantity_redeemed  = $validate['quantity_redeemed'];
							$rules_data = $validate['rules_data'];
                            $left_qunatity = ($maximum_quantity-$quantity_redeemed);
                            if($left_qunatity < $countPtRecords) {
                                //prepaid_tickets response
                                $pt_response = $this->common_model->return_pass_not_valid (12, $is_ticket_listing, $add_to_pass, 'Batch capacity is full');
                                if (!empty($internal_log)) {
                                    $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
                                }
                                $MPOS_LOGS['get_pass_info_from_prepaid_tickets_' . date('H:i:s')] = $logs;
                                return $pt_response;
                            }
							$notifyQuantity = ($countPtRecords+$quantity_redeemed);
							$this->emailNotification($rules_data, $notifyQuantity);
                        }
                    }
                }
            #region end - To validate batch
                if(isset($validate['batch_id']) && $validate['batch_id'] != 0 && $validate['batch_id'] != NULL) {
                    $batch_id = $validate['batch_id'];
                    $batch_id_db1 = ", batch_id = ".$validate['batch_id'];
                } else {
                    $batch_id = 0;
                    $batch_id_db1 = "";
                }
                /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
				$logs['types_count_array'.date('H:i:s')] = $types_count;
                if ($citysightseen_card == 0 && ($is_early_reservation_entry == 0 && $late_redeem == 0) && $result->channel_type != 5 && !in_array($museum_id, $this->redeem_all_passes_on_one_scan_suppliers)) {
                    $types_count = array();
                }
                //set action performed to update
                $types_count = $this->common_model->sort_ticket_types($types_count);
                $logs['types'] = $types_count;
                $action_performed = '';
                if($redeem_all_passes_on_one_scan) {
                    $action_performed = 'RDM_ALL';
                    $update_db1_set = "action_performed = CONCAT(action_performed, ', RDM_ALL')";
                    $insert_pt_data['CONCAT_VALUE']['action_performed'] = ', RDM_ALL';
                } else if (($result->passNo == $pass_no && $is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1') && $add_to_pass == '0' && $result->channel_type != 5 && $is_scan_countdown != '1') {
                    $action_performed = 'CSS_GCKN';
                    $update_db1_set = "action_performed = CONCAT(action_performed, ', CSS_GCKN')";
                    $insert_pt_data['CONCAT_VALUE']['action_performed'] = ', CSS_GCKN';
                } else {
                    if ($is_scan_countdown == '1') {
                        $action_performed = 'SCAN_TB';
                        $update_db1_set = "action_performed = CONCAT(action_performed, ', SCAN_TB')";
                        $insert_pt_data['CONCAT_VALUE']['action_performed'] = ', SCAN_TB';
                    } else {
                        $action_performed = 'SCAN_CSS';
                        $update_db1_set = "action_performed = CONCAT(action_performed, ', SCAN_CSS')";
                        $insert_pt_data['CONCAT_VALUE']['action_performed'] = ', SCAN_CSS';
                    }
                }
                
                //print receipt for third party city card off
                $updPass = false;
                $show_scanner_receipt = 0;
                $receipt_data = array();
                $sub_ticket_supplier_scan = 0;
                if($cashier_type == '3'  && $reseller_id != '') {
                    $museum_cashier_id = ( !empty( $result->museum_cashier_id )? $result->museum_cashier_id: $reseller_cashier_id );
                    $museum_cashier_name = ( !empty( $result->museum_cashier_id )? $result->museum_cashier_name: $reseller_cashier_name );
                } else {
                    $museum_cashier_id = ( !empty( $result->museum_cashier_id )? $result->museum_cashier_id: $user_id );
                    $museum_cashier_name = ( !empty( $result->museum_cashier_id )? $result->museum_cashier_name: $user_name );
                }

                if(in_array($result->channel_type, $this->api_channel_types)  && $result->channel_type != "5" && $add_to_pass == 0 && 
                ($is_scan_countdown == 1 || $result->is_addon_ticket == "0"  && $is_scan_countdown == 0 && in_array($result->reseller_id, $this->css_resellers_array)  || $result->is_addon_ticket == "2" && !empty($mainTicketDetails) && $mainTicketDetails->is_scan_countdown == "1" && $mainTicketDetails->allow_city_card == "0" && in_array($result->reseller_id, $this->liverpool_resellers_array)) && (!in_array($result->is_invoice, $this->is_invoice))) {
                    $cond = ' and is_addon_ticket = "0"';
                    if($result->is_addon_ticket == "2") {
                        $cond =' and ticket_id = "'.$result->ticket_id.'"';
                        $sub_ticket_supplier_scan = 1;
                    }
                    $logs['sub_ticket_supplier_scan_'.date('H:i:s')] = $sub_ticket_supplier_scan;
                    $pt_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'visitor_group_no = "'.$result->visitor_group_no.'" and activated = "1" and is_refunded != "1" and deleted = "0" and channel_type = "'.$result->channel_type.'" and (order_status = "0" or order_status = "2" or order_status = "3") '.$cond.' '), 'array');
                    $extra_options_data = $this->find('prepaid_extra_options', array('select' => '*', 'where' => 'visitor_group_no = "'.$result->visitor_group_no.'" and is_cancelled = "0"'), 'array');
                    /* City Card off updation of passNo for prohibt duplicate user entry */
                    $checkUsed = array_sum(array_map('intval', array_column($pt_data, 'used')));
                    $logs['check_used_orders_'.date('H:i:s')] = $checkUsed;
                    if($checkUsed == 0 &&  $pt_data[0]['bleep_pass_no'] == '') {
                        $show_scanner_receipt = 1;
                        $getPtData = $this->getUpdatedPasses($result->visitor_group_no, $result->channel_type);
                        if(!empty($getPtData)) {
                            $updPass = true;
                        }
                    }
                    /* City Card off updation of passNo for prohibt duplicate user entry */
                    
                    foreach($extra_options_data as $extra_options) {
                        if($extra_options['variation_type'] == 0){
                            if($extra_options['ticket_price_schedule_id'] > 0) {
                                $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            } else {
                                $per_ticket_extra_options[$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int)$per_ticket_extra_options[$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int)$per_ticket_extra_options[$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float)$per_ticket_extra_options[$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            }
                        }
                    }
                    $total_price = 0;
                    foreach($pt_data as $data) {
                        if($data['used'] == 1) {
                            $show_scanner_receipt = 0;
                        }
                        sort($per_age_group_extra_options[$data['tps_id']]);
                        $total_price += $data['price'];
                        
                        if($updPass && isset($getPtData[$data['prepaid_ticket_id']]) && !empty($getPtData[$data['prepaid_ticket_id']])) {
                            $passes[$data['tps_id']][] = $getPtData[$data['prepaid_ticket_id']];
                            $data['bleep_pass_no'] = $getPtData[$data['prepaid_ticket_id']];
                        }
                        else {
                            $passes[$data['tps_id']][] = $data['passNo'];
                        }
                        
                        $types_array[$data['tps_id']] = array(
                            'tps_id' => (int)$data['tps_id'],
                            'tax' => (float)$data['tax'],
                            'age_group' => $data['age_group'],
                            'type' => (!empty($this->types[strtolower($data['ticket_type'])]) && ($this->types[strtolower($data['ticket_type'])] > 0)) ? $this->types[strtolower($data['ticket_type'])] : 10,
                            'ticket_type_label' => $data['ticket_type'],
                            'quantity' => (int)$types_array[$data['tps_id']]['quantity'] + 1,
                            'price' => (float)$types_array[$data['tps_id']]['price'] + $data['price'],
                            'net_price' => (float)$types_array[$data['tps_id']]['net_price'] + $data['net_price'],
                            'passes' => array_unique($passes[$data['tps_id']]),
                            'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data['tps_id']])) ? $per_age_group_extra_options[$data['tps_id']] : array()
                        );
                        $pt_sync_daata[] = $data;
                    }
                    sort($types_array);
                    sort($per_ticket_extra_options);
                    $tickets_array = array(
                        'ticket_id' => (int)$result->ticket_id,
                        'title' => $result->title,
                        'guest_notification' => '',
                        'is_reservation' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0' && $result->from_time != '' && $result->from_time != NULL && $result->from_time != '0' && $result->to_time != '' && $result->to_time != NULL && $result->to_time != '0') ? 1 : 0,
                        'selected_date' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->selected_date : '',
                        'from_time' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->from_time : '',
                        'to_time' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->to_time : '',
                        'slot_type' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->timeslot : '',
                        'types' => $types_array,
                        'per_ticket_extra_options' => (!empty($per_ticket_extra_options)) ? $per_ticket_extra_options : array()
                    );
                    $receipt_data = array(
                        'supplier_name' => $result->museum_name,
                        'tax_name' => $result->tax_name,
                        'payment_method' => (int)$result->activation_method,
                        'pass_type' => (int)$result->pass_type,
                        //'is_combi_ticket' => (int)$result->is_combi_ticket,
                        'is_combi_ticket' => 0,
                        'distributor_name' => $result->hotel_name,
                        //'distributor_address' => $dist_address,
                        'booking_date_time' => $result->created_date_time,
                        'total_combi_discount' => 0,
                        'total_service_cost' => 0,
                        'total_amount' => (float)$total_price,
                        'sold_by' => $result->cashier_name, 
                        'tickets' => $tickets_array
                    );
                }
                $order_confirm_date = gmdate('Y-m-d H:i:s');
                //update order confirm date in case of group booking
                if ($result->booking_status == 0 && $result->channel_type == 2) {
                    $insert_pt_data['order_confirm_date'] = $order_confirm_date;
                }
                //update scanned_user and pos_point_details and other required params 
                if ($result->activation_method == 1) {
                    $activation_method = 'credit card';
                }
                if ($result->activation_method == 2) {
                    $activation_method = 'cash';
                }
                if ($result->activation_method == 3) {
                    $activation_method = 'voucher';
                }
                $redeem_date_time = gmdate('Y-m-d H:i:s');
                $scanned_at = strtotime($redeem_date_time);
                if($cashier_type == '3' && $reseller_id != '') {
                    $redeem_users = $reseller_cashier_id . '_' . gmdate('Y-m-d');
                } else {
                    $redeem_users = $user_id . '_' . gmdate('Y-m-d');
                }
                
                if ($is_scan_countdown == 1) {
                    if (empty($result->redeem_users)) {
                        $update_voucher = ", redeem_users = '" . $redeem_users . "'";
                        $update_db1_set .= ", redeem_users = '" . $redeem_users . "'";
                        $update_voucher_cols['redeem_users'] = $insert_pt_data['redeem_users'] = $redeem_users;
                    } else {
                        $update_voucher .= ", redeem_users = CONCAT(redeem_users, ', " . $redeem_users . "')";
                        $update_db1_set .= ", redeem_users = CONCAT(redeem_users, ', " . $redeem_users . "')";
                        $insert_pt_data['CONCAT_VALUE']['redeem_users'] =  ', '.$redeem_users;
                        $update_voucher_cols['CONCAT_VALUE']['redeem_users'] = ', '.$redeem_users;
                    }
                } else {
                    $update_voucher .= ", museum_cashier_id = '" . $museum_cashier_id . "', museum_cashier_name = '" . $museum_cashier_name . "'";
                    $update_db1_set .= ", museum_cashier_id = '" . $museum_cashier_id . "', museum_cashier_name = '" . $museum_cashier_name . "'";
                    $update_voucher_cols['museum_cashier_id'] = $insert_pt_data['museum_cashier_id'] = $museum_cashier_id;
                    $update_voucher_cols['museum_cashier_name'] = $insert_pt_data['museum_cashier_name'] = $museum_cashier_name;
                }
                if ((in_array($result->channel_type, $this->api_channel_types)) && !(in_array($result->is_invoice, $this->is_invoice)) && $pos_point_id > 0) {
                    $update_db1_set .= ", pos_point_id = '" . $pos_point_id . "', pos_point_name = '" . $pos_point_name . "'";
                    $insert_pt_data['pos_point_id'] = $pos_point_id;
                    $insert_pt_data['pos_point_name'] = $pos_point_name;
                }
                if (($result->second_party_type == "5" || $result->third_party_type == "5") && $add_to_pass == '0') {
                    $insert_pt_data['redemption_notified_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'redemption_notified_at','value' => ''), array( 'key' => 'redemption_notified_at','value' => 'NULL')
                    ) ,'update' => gmdate("Y-m-d H:i:s")));
                    $insert_pt_data['authcode'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'authcode','value' => ''), array( 'key' => 'authcode','value' => 'NULL')
                    ) ,'update' => gmdate("Y-m-d H:i:s")));
                }
                if($add_to_pass == 0) {
                    $insert_pt_data['voucher_updated_by'] = $museum_cashier_id;
                    $insert_pt_data['voucher_updated_by_name'] = $museum_cashier_name;
                    $insert_pt_data['redeem_method'] = 'Voucher';
                }
        		$insert_pt_data['pos_point_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'pos_point_id_on_redeem','value' => ''), array( 'key' => 'pos_point_id_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_id_on_redeem','value' => '0')
                ) ,'update' => $pos_point_id));
                $insert_pt_data['pos_point_name_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'pos_point_name_on_redeem','value' => ''), array( 'key' => 'pos_point_name_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_name_on_redeem','value' => '0')
                ) ,'update' => $pos_point_name));
                $insert_pt_data['distributor_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'distributor_id_on_redeem','value' => ''), array( 'key' => 'distributor_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_id_on_redeem','value' => '0')
                ) ,'update' => $museum_info->dist_id));
                $insert_pt_data['distributor_cashier_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'distributor_cashier_id_on_redeem','value' => ''), array( 'key' => 'distributor_cashier_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_cashier_id_on_redeem','value' => '0')
                ) ,'update' => $museum_info->dist_cashier_id));
                
                $redeem_status = $early_checkin = 0;
                $add_ticket_check = $add_ticket_check_vt = '';

                /* handle group checkin case in insert update */
                $insert_update_group_checking = 0;
                $group_checking_pt_ids = '';
                
                /* SUGGESTED By RATTAN SIR: voucher_updated_by="'.$user_id.'", redeem_method="Voucher", */

                // now update these field when activate CT CARD."museum_cashier_id" => $user_id, "museum_cashier_name" => $user_name,
                // chec condition according to Time slot Change on 29 oct
                if ($result->channel_type == 5 && $is_reservation == 1) { 
                    $current_time = strtotime(gmdate("H:i", time() + ($result->timezone * 3600)));
                    $current_day = strtotime(date("Y-m-d"));
                    if (isset($from_time) && $from_time != '' && isset($to_time) && $to_time != '' && isset($selected_date) && $selected_date != '') {
                        $from_time_check = strtotime($from_time);
                        $to_time_check = strtotime($to_time);
                        $selected_date_check = strtotime($selected_date);
                        $update_from_time = $from_time;
                        $update_to_time = $to_time;
                        $update_selected_date = $selected_date;
                    } else {
                        $from_time_check = strtotime($result->from_time);
                        $to_time_check = strtotime($result->to_time);
                        $selected_date_check = strtotime($result->selected_date);
                        $update_from_time = $result->from_time;
                        $update_to_time = $result->to_time;
                        $update_selected_date = $result->selected_date;
                    }
                    if ($is_edited == 1 && SYNC_WITH_FIREBASE == 1 && $add_to_pass == 1) {
                        try {
                            $headers = $this->all_headers(array(
                                'hotel_id' => $result->hotel_id,
                                'museum_id' => $result->museum_id,
                                'ticket_id' => $result->ticket_id,
                                'channel_type' => $result->channel_type,
                                'action' => 'sync_voucher_scans_on_scan_pass',
                                'user_id' => $cashier_type == '3'   && $reseller_id != ''? $reseller_cashier_id : $user_id,
                                'reseller_id' => $cashier_type == '3'  && $reseller_id != '' ? $reseller_id : ''));

                            /* fetch details of order of previous date */
                            $previous_key = base64_encode($result->visitor_group_no . '_' . $result->ticket_id . '_' . $result->selected_date . '_' . $result->from_time . "_" . $result->to_time . "_" . $result->created_date_time . '_' . $result->passNo);
                            $new_key = base64_encode($result->visitor_group_no . '_' . $result->ticket_id . '_' . $update_selected_date . '_' . $update_from_time . "_" . $update_to_time . "_" . $result->created_date_time . '_' . $result->passNo);
                            if($cashier_type == '3'   && $reseller_id != '') { 
                                $node = 'resellers/' . $reseller_id . '/' . 'voucher_scans' . '/' . $reseller_cashier_id . '/' . date("Y-m-d", strtotime($result->created_date_time)) . '/' . $previous_key;
                            } else {
                                $node = 'suppliers/' . $result->museum_id . '/' . 'voucher_scans' . '/' . $user_id . '/' . date("Y-m-d", strtotime($result->created_date_time)) . '/' . $previous_key;
                            }
                            $MPOS_LOGS['DB'][] = 'FIREBASE';
                            $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $node)
                            ));
                            $previous = json_decode($getdata);
                            $previous_data = $previous->data;
                            if (!empty($previous_data)) {
                                $previous_data->from_time = $update_from_time;
                                $previous_data->reservation_date = $update_selected_date;
                                $previous_data->to_time = $update_to_time;
                            }
                            /* delete previous node */
                            $this->curl->requestASYNC('FIREBASE', '/delete_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $node)
                            ));

                            /* create new node */
                            if($cashier_type == '3'   && $reseller_id != '') { 
                                $new_node = 'resellers/' . $reseller_id . '/' . 'voucher_scans' . '/' . $reseller_cashier_id . '/' . gmdate("Y-m-d") . '/' . $new_key;
                            } else {
                                $new_node = 'suppliers/' . $result->museum_id . '/voucher_scans/' . $user_id . '/' . gmdate("Y-m-d") . '/' . $new_key;
                            }
                            $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $new_node, 'details' => $previous_data)
                            ));
                        } catch (\Exception $e) {
                            $logs['exception'] = $e->getMessage();
                        }
                    }
                    if ($is_edited == 1) {
                        $capacity = isset($result->capacity) ? $result->capacity : 1;
                        $logs['capacity'] = array($result->ticket_id, $result->selected_date, $result->from_time, $result->to_time, $capacity, $result->timeslot);
                        //update capacity of prev timeslot
                        $this->update_prev_capacity($result->ticket_id, $result->selected_date, $result->from_time, $result->to_time, $capacity, $result->timeslot);
                        //update capacity of new timeslot
                        $this->update_capacity($result->ticket_id, $update_selected_date, $update_from_time, $update_to_time, $capacity, $slot_type);
                    }
                    if ($selected_date_check == $current_day) {
                        if ($from_time_check <= $device_time_check && $to_time_check >= $device_time_check) {
                            $is_redeem = 1;
                        } else {
                            if($is_scan_countdown == 1) {
                                $is_redeem = 1;
                            } else {
                                $early_checkin = 1;
                            }
                        }
                    } else if($selected_date_check < $current_day) {
                        $is_redeem = ($is_edited == 0) ? 1 : 0;
                    }else  if($selected_date_check > $current_day){
                        $is_redeem = ($is_edited == 0 || ($is_scan_countdown == 1 && $result->used==1 )) ? 1 :0;
                        $early_checkin = ($is_edited == 0 && $is_scan_countdown == 1 && $is_redeem == 1) ? 0 : 1;
                    }
                    if ($is_edited == 0) {
                        $is_redeem = 1;
                    }
                    if ($is_edited == 1 && $is_redeem == 1) {
                        $action_performed = $action_performed . ', UPDATE_RSV_ON_SCAN';
                    }

                    // db1 and db2 queries
                    if ($is_redeem == 1) {
                        $update_pt = ',museum_cashier_id = "'.$museum_cashier_id.'", museum_cashier_name = "'.$museum_cashier_name.'", action_performed=concat(action_performed, ", ' . $action_performed . '"),scanned_at=(case when (scanned_at = "" || scanned_at is NULL) then "'.$scanned_at.'" else scanned_at end ), redeem_date_time = (case when redeem_date_time = "1970-01-01 00:00:01" then "'.$redeem_date_time.'" else redeem_date_time end ),booking_status="1", shift_id = "'. $shift_id.'"';
                        $insert_pt_db['booking_status'] = $insert_vt_query['booking_status'] = "1";
                        $insert_pt_db['CONCAT_VALUE']['action_performed'] = $insert_vt_query['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                        $insert_pt_db['scanned_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'scanned_at','value' => ''), array( 'key' => 'scanned_at','value' => 'NULL')
                        ) ,'update' => $scanned_at));
                        $insert_pt_db['redeem_date_time'] = array('case' => array('key' => 'redeem_date_time','value' => "1970-01-01 00:00:01",'update' => $redeem_date_time));
                        $insert_pt_db['updated_at'] = $insert_vt_query['updated_at'] = gmdate("Y-m-d H:i:s");
                        $insert_pt_db['used'] = $insert_vt_query['used'] = $is_redeem;
                        $insert_pt_db['museum_cashier_id'] = $insert_pt_db['voucher_updated_by'] = $insert_vt_query['updated_by_id'] = $insert_vt_query['voucher_updated_by'] = $museum_cashier_id;
                        $insert_pt_db['museum_cashier_name'] = $insert_pt_db['voucher_updated_by_name'] = $insert_vt_query['updated_by_username'] = $insert_vt_query['voucher_updated_by_name'] = $museum_cashier_name;
                        $insert_pt_db['redeem_method'] = $insert_vt_query['redeem_method'] = "Voucher";
                        $insert_pt_db['pos_point_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'pos_point_id_on_redeem','value' => ''), array( 'key' => 'pos_point_id_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_id_on_redeem','value' => '0')
                        ) ,'update' => $pos_point_id));
                        $insert_pt_db['pos_point_name_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'pos_point_name_on_redeem','value' => ''), array( 'key' => 'pos_point_name_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_name_on_redeem','value' => '0')
                        ) ,'update' => $pos_point_name));
                        $insert_pt_db['distributor_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'distributor_id_on_redeem','value' => ''), array( 'key' => 'distributor_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_id_on_redeem','value' => '0')
                        ) ,'update' => $museum_info->dist_id ));
                        $insert_pt_db['distributor_cashier_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'distributor_cashier_id_on_redeem','value' => ''), array( 'key' => 'distributor_cashier_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_cashier_id_on_redeem','value' => '0')
                        ) ,'update' => $museum_info->dist_cashier_id));
                        if ($result->scanned_at == '' || $result->scanned_at == null) {
                            $insert_vt_query['visit_date'] = $scanned_at;
                        } 
                        $insert_pt_db['shift_id'] = $shift_id;  
                        $insert_vt_query['shift_id'] = $shift_id;
                    }
                    //if reservation date is updated for preassigned pass

                    if ($is_edited == 1) {
                        $insert_pt_db['from_time'] = $insert_vt_query['from_time'] = $update_from_time;
                        $insert_pt_db['to_time'] = $insert_vt_query['to_time'] = $update_to_time;
                        $insert_pt_db['selected_date'] = $insert_vt_query['selected_date'] = $update_selected_date;
                    }
                    if ($batch_id != 0) {
                        $insert_pt_db['batch_id'] = $batch_id;
                    }
                    if ($is_edited == 1 && $is_redeem == 1) {
                        $insert_pt_db = array_merge($insert_pt_db, $update_voucher_cols);
                        $insert_pt_db['CONCAT_VALUE']['action_performed'] =  ', '.$action_performed;
                        $update_pt_db1 = ' from_time= "' . $update_from_time . '",to_time="' . $update_to_time . '",selected_date="' . $update_selected_date . '",' . ' used="' . $is_redeem . '"' . $update_voucher . $update_pt;
                        $where_pt = ' visitor_group_no = "' . $result->visitor_group_no . '" and prepaid_ticket_id = "' . $result->prepaid_ticket_id . '" and is_refunded != "1" and deleted = "0"';
                        $redeem_status = 1;
                        $where_vt_query = ' vt_group_no = "' . $result->visitor_group_no . '" and transaction_id = ' . $result->prepaid_ticket_id . ' and ticketId = "' . $result->ticket_id . '" and is_refunded != "1"' ;
                    } else if ($is_edited == 1 && $is_redeem == 0) {
                        $update_pt_db1 = ' action_performed=concat(action_performed, ", UPDATE_RSV_ON_SCAN"),from_time= "' . $update_from_time . '",to_time="' . $update_to_time . '",selected_date="' . $update_selected_date . '" ';
                        $where_pt = ' visitor_group_no = "' . $result->visitor_group_no . '" and prepaid_ticket_id = "' . $result->prepaid_ticket_id . '" and used = "0" and is_refunded != "1" and deleted = "0"';
                        $insert_pt_db['CONCAT_VALUE'] = array("action_performed" => ', UPDATE_RSV_ON_SCAN');
                        $insert_vt_query['CONCAT_VALUE'] = array("action_performed" => ', UPDATE_RSV_ON_SCAN');
                        $where_vt_query = ' vt_group_no = "' . $result->visitor_group_no . '" and transaction_id = ' . $result->prepaid_ticket_id . ' and ticketId = "' . $result->ticket_id . '" and used = "0" and is_refunded != "1"';
                    } else if ($is_redeem == 1) { 
                        //id preassigned pass is redeemed
                        $insert_pt_db = array_merge($insert_pt_db, $update_voucher_cols);
                        $insert_pt_db['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                        $update_pt_db1 = ' used="' . $is_redeem . '"' . $update_voucher . $update_pt;
                        $where_pt = ' visitor_group_no = "' . $result->visitor_group_no . '" and prepaid_ticket_id = "' . $result->prepaid_ticket_id . '" and is_refunded != "1" and deleted = "0"';
                        $redeem_status = 1;
                        $where_vt_query = ' vt_group_no = "' . $result->visitor_group_no . '" and transaction_id = ' . $result->prepaid_ticket_id . ' and ticketId = "' . $result->ticket_id . '" and is_refunded != "1"';
                    }
                    if(!empty($insert_vt_query) && !empty($where_vt_query)) {
                        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt_query, "where" => $where_vt_query, "redeem" => ($is_redeem=='1'? 1: 0), 'activated' => $check_activated);
                    } 
                    if($update_pt_db1 != '' && $where_pt != '') {
                        $this->query('update prepaid_tickets set ' . $update_pt_db1 .$batch_id_db1. ' where '. $where_pt . (($check_activated) ? ' and activated = "1" ' : ''));
                        $logs['pt_query_'.date('H:i:s')] = $this->db->last_query();
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db, "where" => $where_pt, "redeem" => ($is_redeem=='1'? 1: 0), 'activated' => $check_activated);                    
                    }
                    $logs['voucher_case_details'] = array('add_to_pass' => $add_to_pass, 'is_redeem' => $is_redeem, 'is_edited' => $is_edited, 'update_from_time' => $update_from_time, 'update_to_time' => $update_to_time, 'update_selected_date' => $update_selected_date, 'device_time_check' => device_time_check);
                } else if ($is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1' && $add_to_pass == '0' && $is_scan_countdown == 0 && $result->channel_type != 5) {
                    //Update query, in case of add to pass off and prepaid orders
                    if ($batch_id != 0) {
                        $insert_pt_case['batch_id'] = $batch_id;
                    }
                    $insert_pt_case['booking_status'] = $insert_pt_case['used'] = "1";
                    $insert_pt_case['updated_at'] = gmdate("Y-m-d H:i:s");
                    $insert_pt_case['redeem_date_time'] = $redeem_date_time;
                    $insert_pt_case['scanned_at'] = $scanned_at;
                    $insert_pt_case = array_merge($insert_pt_case, $insert_pt_data);
                    
                    if($redeem_same_supplier_cluster_tickets == 1 && $result->clustering_id != '' && $result->clustering_id != NULL && $result->clustering_id != "0") {
                        $db1_query = 'update prepaid_tickets set ' . $update_db1_set. $batch_id_db1 . ', used = "1", redeem_date_time = "'.$redeem_date_time.'", booking_status = "1", scanned_at = "' . $scanned_at . '" where visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "' . $result->museum_id . '" and used = "0" and is_refunded != "1" and deleted = "0" and clustering_id IN ('.$clustring_ids.')' . (($check_activated) ? ' and activated = "1" ' : '');
                        $where_pt_case = 'visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "' . $result->museum_id . '" and used = "0" and is_refunded != "1" and deleted = "0" and clustering_id IN ('.$clustring_ids.')'; 
                        $where_vt_query = '  vt_group_no="' . $result->visitor_group_no . '" and museum_id="' . $result->museum_id . '" and is_refunded != "1" and targetlocation IN ('.$clustring_ids.')';
                        $send_pt_ids[] = $result->prepaid_ticket_id;
                        $total_price_update += $result->price;
                        $redeemed_clustering_ids = $clustring_ids;
                        $logs['prepaid_query1_'.date('H:i:s')] = $db1_query;
                    } else {
                        if(in_array($result->channel_type, $this->api_channel_types) && !in_array($result->is_invoice, $this->is_invoice)) {
                            $update_db1_set .= ", shift_id = '" . $shift_id . "'";
                            $insert_pt_case['shift_id'] = $shift_id;
                        }                        
                        $db1_query = 'update prepaid_tickets set ' . $update_db1_set . ', used = "1", redeem_date_time = "'.$redeem_date_time.'", booking_status = "1", scanned_at = "' . $scanned_at . '" where visitor_group_no = "' . $result->visitor_group_no . '" and ticket_id = "' . $result->ticket_id . '" and used = "0" and is_refunded != "1" and deleted = "0"' . (($check_activated) ? ' and activated = "1" ' : '');
                        $pt_ids = $this->find('prepaid_tickets', array('select' => 'prepaid_ticket_id, price', 'where' => 'visitor_group_no = "' . $result->visitor_group_no . '" and ticket_id = "' . $result->ticket_id . '" and used = "0" and is_refunded != "1" and deleted = "0"' . (($check_activated) ? ' and activated = "1" ' : '')), 'array');
                        $send_pt_ids = array_unique(array_column($pt_ids, 'prepaid_ticket_id'));
                        $total_price_update = array_sum(array_column($pt_ids, 'price'));
                        $where_pt_case = 'visitor_group_no = "' . $result->visitor_group_no . '" and ticket_id = "' . $result->ticket_id . '"  and is_refunded != "1" and deleted = "0"';
                        $where_vt_query = ' vt_group_no="' . $result->visitor_group_no . '" and ticketId="' . $result->ticket_id . '" and is_refunded != "1"';
                        $logs['prepaid_query2_'.date('H:i:s')] = $db1_query;
                    }
                    $insert_vt_query['order_confirm_date']['case'] = array('separator' => '&&', 'conditions' => array(
                        array( 'key' => 'booking_status','value' => '0'), array( 'key' => 'channel_type','value' => "2" )),
                        'update' => $order_confirm_date, 'default_col' => 'visit_date_time');
                    $insert_vt_query['CONCAT_VALUE']['action_performed'] = ', CSS_GCKN';
                    $insert_vt_query['updated_at'] = gmdate("Y-m-d H:i:s");
                    $insert_vt_query['updated_by_id'] = $insert_vt_query['voucher_updated_by'] = $museum_cashier_id;
                    $insert_vt_query['updated_by_username'] = $insert_vt_query['voucher_updated_by_name'] = $museum_cashier_name;
                    $insert_vt_query['redeem_method'] = "Voucher";
                    $insert_vt_query['booking_status'] = $insert_vt_query['used'] = "1";
                    $insert_vt_query['visit_date'] = $scanned_at;
                    $insert_vt_query['shift_id'] = $shift_id;
                    if(!empty($insert_vt_query) && !empty($where_vt_query)) {
                        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt_query, "where" => $where_vt_query, "redeem" => 1, "group_checkin" => 1, 'activated' => $check_activated);
                    }
                    $this->query($db1_query);
                    $expedia_query = 'update expedia_prepaid_tickets set used="1", scanned_at="' . $scanned_at . '", action_performed=concat(action_performed, ", CSS_GCKN") where visitor_group_no="' . $result->visitor_group_no . '" and ticket_id="' . $result->ticket_id . '" and used ="0" and is_refunded = "0"';
                    $update_redeem_table = array();
                    $update_redeem_table['visitor_group_no'] = $result->visitor_group_no;
                    $update_redeem_table['ticket_id'] = $result->ticket_id;
                    $update_redeem_table['museum_id'] = $museum_id;
                    $update_redeem_table['museum_cashier_id'] = $museum_cashier_id;
                    $update_redeem_table['shift_id'] = $shift_id;
                    $update_redeem_table['redeem_date_time'] = $redeem_date_time;
                    $update_redeem_table['museum_cashier_name'] = $museum_cashier_name;
                    $update_redeem_table['hotel_id'] = $result->hotel_id;
                    if($redeemed_clustering_ids != '') {
                        $update_redeem_table['clustering_id_in'] = $redeemed_clustering_ids;
                    } else {
                        $update_redeem_table['prepaid_ticket_ids'] = $send_pt_ids;
                    }
                    $update_redeem_table['cashier_type'] = $cashier_type ;
                    $is_redeem = 1;
                    $insert_update_group_checking = 1;
                    $group_checking_pt_ids = (!empty($send_pt_ids)? '"'.implode('","', $send_pt_ids ).'"': '');
                } else {
                    $insert_vt_where = '';
                    //city card on - all cases OR Timebased - all cases OR channel 5 - booking  ticket OR any group
                    if ($is_early_reservation_entry == 0 && $late_redeem == 0) {
                        $is_redeem = 1;
                        if($result->is_addon_ticket == '2' || ($redeem_all_passes_on_one_scan == 1 && strpos($pt_result->action_performed, 'UPSELL') === false ) || ($ticket_id_in_req != '' && $redeem_same_supplier_cluster_tickets == 0)) {
                            $add_ticket_check = 'and ticket_id = "'.$ticket_id.'"';
                            $add_ticket_check_vt = 'and ticketId = "'.$ticket_id.'"';
                        }
                        
                        if ($add_to_pass == '1') {
                            //Update queries in case of add_to_pass ON
                            if ($batch_id != 0) {
                                $insert_pt_pass_on_case['batch_id'] = $batch_id;
                            }
                            $insert_pt_pass_on_case['booking_status'] = $insert_pt_pass_on_case['used'] = "1";
                            $insert_pt_pass_on_case['updated_at'] = gmdate("Y-m-d H:i:s");
                            if (!empty($result->bleep_pass_no)) {
                                if ($result->scanned_at == '' || $result->scanned_at == '0' || $result->scanned_at == NULL) {
                                    $insert_pt_pass_on_case['redeem_date_time'] = $redeem_date_time;
                                    $insert_pt_pass_on_case['scanned_at'] = $scanned_at;
                                    $update_pt_db1 =  $update_db1_set . ', used = "1", redeem_date_time = "'.$redeem_date_time.'", booking_status = "1",scanned_at = "' . $scanned_at . '" ';
                                    $where_pt = ' visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'.$result->museum_id.'" and bleep_pass_no = "'.$pass_no.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                    $this->query('update prepaid_tickets set '. $update_pt_db1 .$batch_id_db1. ' where '.$where_pt . (($check_activated) ? ' and activated = "1" ' : ''));
                                    $logs['prepaid_query3_'.date('H:i:s')] = $this->db->last_query();
                                    $expedia_query = 'update expedia_prepaid_tickets set used="1", scanned_at="' . $scanned_at . '", action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'.$result->museum_id.'" and bleep_pass_no = "'.$pass_no.'" '.$add_ticket_check.' and is_refunded = "0"';
                                } else {
                                    $where_pt = ' visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'.$result->museum_id.'" and bleep_pass_no = "'.$pass_no.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                    $scanned_at = $result->scanned_at;
                                    $expedia_query = 'update expedia_prepaid_tickets set used="1", action_performed=concat(action_performed, ", ' . $action_performed . '") where  visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'.$result->museum_id.'" and bleep_pass_no = "'.$pass_no.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                    $update_pt_db1 = $update_db1_set . ', used = "1", booking_status = "1"';
                                    $this->query('update prepaid_tickets set '. $update_pt_db1. $batch_id_db1. ' where '.$where_pt . (($check_activated) ? ' and activated = "1" ' : ''));
                                    $logs['prepaid_query4_'.date('H:i:s')] = $this->db->last_query();
                                }
                                $insert_vt_where = ' vt_group_no = "' . $result->visitor_group_no . '" and museum_id = "'.$result->museum_id.'" and scanned_pass = "'.$pass_no.'" '.$add_ticket_check_vt.' and is_refunded != "1" and deleted = "0"';
                                $insert_pt_pass_on_case = array_merge($insert_pt_pass_on_case, $insert_pt_data);
                                if (!empty($insert_pt_pass_on_case) && !empty($where_pt)) {
                                    $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_pass_on_case, "where" => $where_pt, "redeem" => ($is_redeem=='1'? 1: 0), 'activated' => $check_activated);
                                }
                                $redeem_status = 1;
                            }
                        } else {
                            //Update queries in case of add_to_pass OFF
                            if ($batch_id != 0) {
                                $insert_pt_case['batch_id'] = $batch_id;
                            }
                            $insert_pt_case['booking_status'] = $insert_pt_case['used'] = "1";
                            $insert_pt_case['updated_at'] = gmdate("Y-m-d H:i:s");
                            $insert_pt_case['museum_cashier_id'] = $museum_cashier_id;
                            $insert_pt_case['museum_cashier_name'] = $museum_cashier_name;
                            $insert_pt_case = array_merge($insert_pt_case, $insert_pt_data);
                            if ($result->scanned_at == '' || $result->scanned_at == '0' || $result->scanned_at == NULL) {
                                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
                                $insert_pt_case['redeem_date_time'] = $redeem_date_time;
                                $insert_pt_case['scanned_at'] = $scanned_at;
                                if(in_array($result->channel_type, $this->api_channel_types) && !in_array($result->is_invoice, $this->is_invoice) && $add_to_pass == 0 && $result->channel_type != "5" && $is_scan_countdown == "1" && ($result->shift_id == "" || $result->shift_id == NULL || $result->shift_id == "0")) {
                                    $insert_pt_case['shift_id'] = $shift_id;
                                    $update_db1_set .= ', shift_id = "'.$shift_id.'" ';
                                    
                                    $update_shift_id = 1;
                                }
                                if($redeem_same_supplier_cluster_tickets == "1" && $result->clustering_id != '' && $result->clustering_id != NULL && $result->clustering_id != "0") {
                                    
                                    $db1_query = 'update prepaid_tickets set ' . $update_db1_set. $batch_id_db1 . ', museum_cashier_id = "'. $museum_cashier_id .'", museum_cashier_name = "'. $museum_cashier_name .'", used = "1", redeem_date_time = "'.$redeem_date_time.'", booking_status = "1",scanned_at = "' . $scanned_at . '", redeem_date_time = "'.$redeem_date_time.'" where visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'. $result->museum_id .'" and clustering_id IN ('.$clustring_ids.') and deleted = "0"';
                                    $where_pt_case = 'visitor_group_no = "' . $result->visitor_group_no . '" and museum_id = "'. $result->museum_id .'" and clustering_id IN ('.$clustring_ids.') and deleted = "0"';
                                    $logs['prepaid_query5_'.date('H:i:s')] = $db1_query;
                                    $insert_vt_where = '  vt_group_no="' . $result->visitor_group_no . '" and museum_id="' . $result->museum_id . '" and targetlocation IN ('.$clustring_ids.') and deleted = "0"';
                                    $redeemed_clustering_ids = $clustring_ids;
                                } else {
                                    if($redeem_all_passes_on_one_scan) {
                                        $bleep_pass_or_pass = $bleep_pass_or_pass_vt = '';
                                    } else if($pass_no == $result->bleep_pass_no) { //to handle city card off sub tickets of city card on main tickets
                                        $bleep_pass_or_pass = 'bleep_pass_no = "' . $pass_no . '" and ';
                                        $bleep_pass_or_pass_vt = 'scanned_pass = "' . $pass_no . '" and ';
                                    } else {
                                        $update_on_pass_no = 1;
                                        $bleep_pass_or_pass_vt = $bleep_pass_or_pass = ' passNo = "' . $pass_no . '" and ';
                                    }
                                    $db1_query = 'update prepaid_tickets set ' . $update_db1_set . $batch_id_db1 . ', museum_cashier_id = "'. $museum_cashier_id .'", museum_cashier_name = "'. $museum_cashier_name .'", used = "1", booking_status = "1",scanned_at = "' . $scanned_at . '", redeem_date_time = "'.$redeem_date_time.'" where visitor_group_no = "' . $result->visitor_group_no . '" and '. $bleep_pass_or_pass. ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                    $where_pt_case = ' visitor_group_no = "' . $result->visitor_group_no . '" and '. $bleep_pass_or_pass. ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                    $insert_vt_where = '  vt_group_no = "' . $result->visitor_group_no . '" and '. $bleep_pass_or_pass_vt. ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check_vt.' and is_refunded != "1" and deleted = "0"';
                                    $logs['prepaid_query6_'.date('H:i:s')] = $db1_query;
                                }
                                $expedia_query = 'update expedia_prepaid_tickets set used="1", scanned_at="' . $scanned_at . '", action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $result->visitor_group_no . '" and '. $bleep_pass_or_pass. ' ticket_id = "'.$ticket_id.'" and is_refunded = "0"';
                                $this->query($db1_query. (($check_activated) ? ' and activated = "1" ' : ''));
                            } else {
                                if($redeem_all_passes_on_one_scan) {
                                    $bleep_pass_or_pass = $bleep_pass_or_pass_vt = '';
                                } else if(in_array($result->channel_type, $this->api_channel_types) && (!in_array($result->is_invoice, $this->is_invoice)) && 
                                   $result->channel_type != "5" && 
                                   $add_to_pass == 0 && 
                                   $is_scan_countdown == "1" && 
                                   $pass_no == $result->bleep_pass_no) {
                                    
                                    $bleep_pass_or_pass = 'bleep_pass_no = "' . $pass_no . '" and ';
                                    $bleep_pass_or_pass_vt = ' scanned_pass = "' . $pass_no . '" and ';
                                }
                                else {
                                    $bleep_pass_or_pass_vt = $bleep_pass_or_pass = 'passNo = "' . $pass_no . '" and ';
                                    $update_on_pass_no = 1;
                                }
                                
                                $scanned_at = $result->scanned_at;
                                $expedia_query = 'update expedia_prepaid_tickets set used="1", action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $result->visitor_group_no . '" and passNo = "' . $pass_no . '" '.$add_ticket_check.' and is_refunded = "0"';
                                // Code to update action performed in main ticket
                                // echo "<pre>"; echo(strpos($result->action_performed, 'UPSELL_INSERT')); exit;
                                if(($ticket_status->is_combi == "2") && ($result->is_addon_ticket == "0") && (strpos($result->action_performed, 'UPSELL_INSERT') == false)){
                                    // Update action performed in the previous main ticket too check 
                                    $add_ticket_check = 'and is_addon_ticket = "'.$result->is_addon_ticket.'"';
                                    $add_ticket_check_vt = 'and col2 = "'.$result->is_addon_ticket.'"';
                                    $check_activated = 0;
                                }
                                $db1_query = 'update prepaid_tickets set ' . $update_db1_set . $batch_id_db1 . ', used = "1", booking_status = "1" where visitor_group_no = "' . $result->visitor_group_no . '" and ' . $bleep_pass_or_pass . ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"' . (($check_activated) ? ' and activated = "1" ' : '');
                                $this->query($db1_query);
                                $logs['prepaid_query7_'.date('H:i:s')] = $db1_query;
                                $where_pt_case = '  visitor_group_no = "' . $result->visitor_group_no . '" and ' . $bleep_pass_or_pass . ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check.' and is_refunded != "1" and deleted = "0"';
                                $insert_vt_where = '  vt_group_no = "' . $result->visitor_group_no . '" and '. $bleep_pass_or_pass_vt. ' museum_id = "'.$result->museum_id.'" '.$add_ticket_check_vt.' and is_refunded != "1" and deleted = "0"';
                                $check_ticket_to_redeem = '0';
                            }
                            $redeemed_pt_ids = array();
                            if($redeem_all_passes_on_one_scan && $where_pt_case != '') {
                                $updated_data = $this->find('prepaid_tickets', array('select' => 'prepaid_ticket_id, price', 'where' => $where_pt_case. (($check_activated) ? ' and activated = "1" ' : '')), 'array');
                                $redeemed_pt_ids = array_unique(array_column($updated_data, 'prepaid_ticket_id'));                                
                            }
                            $redeem_status = 1;
                        }
                    }
                }
                $logs['where_pt_case__redeemed_pt_ids'.date('H:i:s')] = array('where_pt_case' => $where_pt_case. (($check_activated) ? ' and activated = "1" ' : ''), 'redeemed_pt_ids' => $redeemed_pt_ids);
                if (!empty($insert_pt_case) && !empty($where_pt_case)) {
                    $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_case, "where" => $where_pt_case, "redeem" => ($is_redeem=='1'? 1: 0), "group_checkin" => $insert_update_group_checking, 'activated' => $check_activated);
                } 
                if ($insert_vt_where != '') {
                    $updated_data = array();
                    $updated_data['updated_by_username'] = $museum_cashier_name;
                    $updated_data['updated_by_id'] = $museum_cashier_id;
                    $updated_data['used'] = '1';
                    $updated_data['visit_date'] = $scanned_at;
                    if($add_to_pass == 0) {
                        $updated_data['redeem_method'] = 'Voucher';
                        $updated_data['voucher_updated_by'] = $museum_cashier_id;
                        $updated_data['voucher_updated_by_name'] = $museum_cashier_name;
                    }
                    if (in_array($result->channel_type, $this->api_channel_types) && (!in_array($result->is_invoice, $this->is_invoice))) {
                        $updated_data['pos_point_id'] = $pos_point_id;
                        $updated_data['pos_point_name'] = $pos_point_name;
                        if ($update_shift_id == 1) {
                            $updated_data['shift_id'] = $shift_id;                        
                        }
                    }
                    if ($result->booking_status == 0 && $result->channel_type == 2) {
                        $updated_data['order_confirm_date'] = $order_confirm_date;
                    }

                    $updated_data['booking_status'] = 1;
                    if ($is_scan_countdown == '1') {
                        $updated_data['CONCAT_VALUE']['action_performed'] = ($redeem_all_passes_on_one_scan) ? ', RDM_ALL' : ', SCAN_TB';
                    } else {
                        $updated_data['CONCAT_VALUE']['action_performed'] = ', SCAN_CSS';
                    }
                    $updated_data['updated_at'] = gmdate("Y-m-d H:i:s");
                    $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $updated_data, "where" => $insert_vt_where, "redeem" => ($is_redeem=='1'? 1: 0), 'activated' => $check_activated);
                }
                /* UPDATE redumption of cashier in batch_rule table */
                if(!empty($batch_rule_id) && $countPtRecords>0) {
                    $strUpd = '';
                    if(strtotime($last_scans) < strtotime(date('Y-m-d'))) {
                        $strUpd.= "quantity_redeemed = 0, ";
                    }
                    $strUpd.= "quantity_redeemed = (quantity_redeemed+{$countPtRecords}), last_scan = '".date('Y-m-d')."'";

                    $this->query("UPDATE batch_rules SET {$strUpd} WHERE batch_rule_id = {$batch_rule_id}");
                    $logs['update_batch_rules_'.date('H:i:s')] = $this->db->last_query();
                }
                /* UPDATE redumption of cashier in batch_rule table */
				
                //Update payment_conditions in PT
                if (
                    (
                    ((strpos($result->action_performed, 'SCAN_TB') === false || strpos($result->action_performed, 'RDM_ALL') === false) && (strpos($result->action_performed, 'UPSELL') === false) && $is_scan_countdown == 1 && $result->scanned_at == '' && $early_checkin == 0 && ($action_performed == 'SCAN_TB' || $action_performed == 'RDM_ALL') && $citysightseen_card == 0) || 
                    ($result->is_addon_ticket == "2" &&  !empty($mainTicketDetails) && $mainTicketDetails->is_scan_countdown == "1" && $mainTicketDetails->allow_city_card == "0"  && in_array($result->reseller_id, $this->liverpool_resellers_array))
                    )
                    && $result->payment_conditions == '' && $is_redeem == 1) {
                    $countdown_values = explode('-', $countdown_interval);
                    if($result->is_addon_ticket == "2" && !empty($mainTicketDetails) && $mainTicketDetails->is_scan_countdown == "1" && $mainTicketDetails->allow_city_card == "0"   && in_array($result->reseller_id, $this->liverpool_resellers_array)) {
                        $countdown_values = explode('-', $mainTicketDetails->countdown_interval);
                    }                    
                    $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                    $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                    if($redeem_all_passes_on_one_scan) {
                        $pass_no_check = $add_ticket_check;
                    } else {
                        $pass_no_check = ' and (passNo = "' . $pass_no . '"  or bleep_pass_no = "' . $pass_no . '")';
                    }
                    $main_ticket_details =  $this->find('prepaid_tickets', array('select' => 'reseller_id, supplier_price, capacity, prepaid_ticket_id,cashier_id, pass_type, cashier_name, tax_name, museum_id,museum_name,activation_method,hotel_id,pos_point_id,pos_point_name,created_date_time,visitor_group_no,museum_cashier_id,visitor_tickets_id, clustering_id,is_addon_ticket,hotel_ticket_overview_id,hotel_name, extra_discount, combi_discount_gross_amount, channel_type, is_combi_ticket,ticket_id, tps_id, title, ticket_type, age_group, price, hotel_name, pos_point_name, tax,selected_date, from_time, to_time, timeslot, passNo,second_party_passNo,second_party_type,third_party_type, channel_type, booking_status, shift_id, related_product_id', 'where' => 'visitor_group_no = "' . $result->visitor_group_no . '" and ticket_id = "' . $result->related_product_id . '" and used = "1" and is_refunded != "1" and activated = "0" '));
                    if((count($main_ticket_details)) == 0){
                        $update_prepaid_query = 'update prepaid_tickets set payment_conditions = (case when (payment_conditions = "" || payment_conditions is NULL) then "' . $valid_till . '" else payment_conditions end ) where visitor_group_no = "' . $result->visitor_group_no . '" ' . $pass_no_check . ' and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : '');
                        $this->query($update_prepaid_query);
                        $logs['payment_conditions_query_'.date('H:i:s')] = $update_prepaid_query;
                        $insert_pt_payment['payment_conditions'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'payment_conditions','value' => ''), array( 'key' => 'payment_conditions','value' => 'NULL')
                        ) ,'update' => $valid_till));
                        
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_payment, "where" => ' visitor_group_no = "' . $result->visitor_group_no . '" ' . $pass_no_check . ' and is_refunded != "1"', "redeem" => ($is_redeem=='1'? 1: 0), 'activated' => $check_activated);
                        $sub_transactions = 1;
                    }
                }

                //Prepare array to update redeem_cashiers_details array
                if ($redeem_status) {
                    $update_redeem_table = array();
                    $update_redeem_table['visitor_group_no'] = $result->visitor_group_no;
                    $update_redeem_table['museum_cashier_id'] = $museum_cashier_id;
                    if(in_array($result->channel_type, $this->api_channel_types) &&(!in_array($result->is_invoice, $this->is_invoice))) {
                        $update_redeem_table['shift_id'] = $shift_id > 0 ? $shift_id : $result->shift_id;
                    }
                    $update_redeem_table['museum_id'] = $museum_id;
                    $update_redeem_table['redeem_date_time'] = $redeem_date_time;
                    $update_redeem_table['museum_cashier_name'] = $museum_cashier_name;
                    $update_redeem_table['hotel_id'] = $result->hotel_id;
                    if($redeemed_clustering_ids != '') {
                        $update_redeem_table['clustering_id_in'] = $redeemed_clustering_ids;
                    } else {
                        $update_redeem_table['prepaid_ticket_ids'] = !empty($redeemed_pt_ids) ? $redeemed_pt_ids : array($result->prepaid_ticket_id);
                    }
                    if($update_on_pass_no == 1) {
                        $update_redeem_table['update_on_pass_no'] = $update_on_pass_no;
                        $update_redeem_table['prepaid_ticket_ids'] = array();
                        $update_redeem_table['pass_no'] = $pass_no;
                        if($add_ticket_check != '') { //for upsell insert case, ticket id should not be checked
                            $update_redeem_table['ticket_id'] = $ticket_id;
                        }
                    }
                    $update_redeem_table['cashier_type'] = $cashier_type ;
                }

                //Add all queries in queue to update
                if (!empty($insertion_db2)) {
                    /*             * **** Notify Third party system ***** */
                    if ($citysightseen_card == 0 && $result->used == '0' && $add_to_pass == '0' && ($result->second_party_type == 5 || $result->third_party_type == 5)) {
                        $request_array = array();
                        $request_array['passNo'] = $result->passNo;
                        $request_array['ticket_id'] = $result->ticket_id;
                        $request_array['scan_api'] = 'direct redeem city card off - mpos app';
                        $request_array['date_time'] = gmdate("Y-m-d H:i:s");
                        $request_string = json_encode($request_array);
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        // This Fn used to send notification with data on AWS panel. 
                        if (LOCAL_ENVIRONMENT != 'Local') {
                            $spcl_ref = 'notify_third_party';
                            $MPOS_LOGS['external_reference'] = 'notify_third_party';
                            $logs['data_to_queue_THIRD_PARTY_REDUMPTION_ARN_' . date('H:i:s')] = $request_array;
                            $this->queue($aws_message, THIRD_PARTY_REDUMPTION, THIRD_PARTY_REDUMPTION_ARN);
                        }
                    }
                    /*             * **** Update Third party system ***** */
                    if (!empty($expedia_query)) {
                        $this->query($expedia_query);
                        $logs['expedia_query_'.date('H:i:s')] = $expedia_query;
                    }
                    $request_array['db2_insertion'] = $insertion_db2;
                    $request_array['sub_transactions'] = isset($sub_transactions) ? $sub_transactions : 0;
                    if ($result->booking_status == '0' && $result->channel_type == '2' && $citysightseen_card == 0) {
                        if (($is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1') && $add_to_pass == '0') {
                            if($result->is_combi_ticket == 0) {
                                $request_array['confirm_order'] = array('reseller_id' => $result->reseller_id, 'dist_id' => $result->hotel_id, 'voucher_updated_by' => $museum_cashier_id , 'voucher_updated_by_name' => $museum_cashier_name , 'visitor_group_no' => $result->visitor_group_no, 'action_performed' => $action_performed);
                            } else {
                                $request_array['confirm_order'] = array('reseller_id' => $result->reseller_id, 'dist_id' => $result->hotel_id, 'voucher_updated_by' => $museum_cashier_id , 'voucher_updated_by_name' => $museum_cashier_name , 'pass_no' => $result->passNo, 'hto_id' => $result->hotel_ticket_overview_id, 'action_performed' => $action_performed);
                            }
                            
                            $request_array["confirm_order"]["all_prepaid_ticket_ids"] = $group_checking_pt_ids;
                            $request_array["confirm_order"]["price"] = ($total_price_update > 0) ? $total_price_update : $result->price;
                        } else {
                            if (!empty($result->prepaid_ticket_id)) {
                                $request_array['confirm_order'] = array('reseller_id' => $result->reseller_id, 'price' => $result->price, 'dist_id' => $result->hotel_id, 'voucher_updated_by' => $museum_cashier_id , 'voucher_updated_by_name' => $museum_cashier_name , 'prepaid_ticket_id' => $result->prepaid_ticket_id, 'hto_id' => $result->hotel_ticket_overview_id, 'action_performed' => $action_performed);
                            }
                        }
                    }
                    $request_array['update_redeem_table'] = $update_redeem_table;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['visitor_group_no'] = $result->visitor_group_no;
                    $request_array['action'] = "get_pass_info_from_prepaid_tickets";
                    $request_array['pass_no'] = $result->passNo;
                    $request_array['channel_type'] = $result->channel_type;
                    $MPOS_LOGS['external_reference'] = 'redeem';
                    $logs['data_to_queue_SCANING_ACTION_ARN_'.date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                    } else {
                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                    }
                }

                if($updPass && !empty($getPtData) && ( $museum_info->cashier_type!='2' || $museum_info->cashier_type =='2' &&  $sub_ticket_supplier_scan == '1') && $show_scanner_receipt=='1' && $is_redeem==1) {
                    $this->executeUpdPassesQueries($getPtData);
                    $basic_data = array(
                        'pos_point_id' => $pos_point_id, 
                        'pos_point_name' => $pos_point_name,
                        'user_id' => $cashier_type == "3" && $reseller_id != '' ? $reseller_cashier_id : $museum_info->uid,
                        'shift_id' => $shift_id,
                        'museum_id' => $museum_id,
                        'cashier_type' => $museum_info->cashier_type,
                        'reseller_id' => isset($museum_info->reseller_id) && $museum_info->reseller_id != '' ?  $museum_info->reseller_id : '',
                        'reseller_cashier_id' =>  isset($museum_info->reseller_cashier_id) && $museum_info->reseller_cashier_id != '' ?  $museum_info->reseller_cashier_id : ''
                    );
                    if(!empty($pt_sync_daata)) {
                        $this->sync_voucher_scans($pt_sync_daata, $basic_data);
                    } else {
                        $this->sync_voucher_scans($pt_data, $basic_data);
                    }
                }
                // check is Voucher
                if ($result->channel_type == 5 && ($add_to_pass == 0 || ($add_to_pass ==1 && !empty($result->bleep_pass_no)))) {
                    $is_voucher = 1;
                } else {
                    $is_voucher = 0;
                }

                $cashier_type = $museum_info->cashier_type;
                if($cashier_type == '2' || !empty($this->liverpool_resellers_array) && in_array($result->reseller_id, $this->liverpool_resellers_array)){
                    $show_scanner_receipt = 0;
                }
                //Prepare response array
                $status = 1;
                $message = 'Valid';
                $extra_booking_info = json_decode(stripslashes($result->extra_booking_information), true);
                $booking_note = $extra_booking_info['extra_note'];
                $arrReturn['show_scanner_receipt'] = ($is_redeem == 1) ? $show_scanner_receipt : 0;
                $arrReturn['receipt_details'] = ($show_scanner_receipt == 1 && $is_redeem == 1) ? $receipt_data : (object)array();
                $arrReturn['is_combi_ticket'] = $result->is_combi_ticket;
                $arrReturn['title'] = $result->title;
                $arrReturn['ticket_type'] = (!empty($this->types[strtolower($result->ticket_type)]) && ($this->types[strtolower($result->ticket_type)] > 0)) ? $this->types[strtolower($result->ticket_type)] : 10;
                $arrReturn['ticket_type_label'] = $result->ticket_type;
                $arrReturn['own_capacity_id'] = !empty($own_capacity_id) ? (int) $own_capacity_id : (int) 0;
                $arrReturn['shared_capacity_id'] = !empty($shared_capacity_id) ? (int) $shared_capacity_id : (int) 0;
                $arrReturn['is_reservation'] = !empty($is_reservation) ? (int) $is_reservation : (int) 0;
                $arrReturn['channel_type'] = !empty($result->channel_type) ? (int) $result->channel_type : (int) 0;
                $arrReturn['age_group'] = $result->age_group;
                $arrReturn['price'] = $result->price;
                $arrReturn['group_price'] = $result->group_price;
                $arrReturn['visitor_group_no'] = (string) $result->visitor_group_no;
                $arrReturn['pass_no'] = (string) $pass_no;
                $arrReturn['ticket_id'] = $result->ticket_id;
                $arrReturn['tps_id'] = $result->tps_id;
                $arrReturn['price'] = $result->price;
                $arrReturn['count'] = (isset($count_prepaid) && $count_prepaid > 0 && $add_to_pass == "0") ? $count_prepaid : 1;
                $arrReturn['total_amount'] = $total_amount;
                $arrReturn['from_time'] = $result->from_time;
                $arrReturn['to_time'] = $result->to_time;
                $arrReturn['selected_date'] = (string)$result->selected_date;
                $arrReturn['slot_type'] = !empty($result->timeslot) ? $result->timeslot : '';
                $arrReturn['early_checkin_time'] = $early_checkin_time;
                $arrReturn['hotel_name'] = !empty($result->hotel_name) ? $result->hotel_name : '';
                $arrReturn['address'] = '';
                $arrReturn['cashier_name'] = $result->cashier_name;
                $arrReturn['pos_point_name'] = $result->pos_point_name;
                $arrReturn['tax'] = $result->tax;
                $arrReturn['is_prepaid'] = $result->is_prepaid;
                $arrReturn['action'] = '1';
                $arrReturn['is_voucher'] = $is_voucher;
                $arrReturn['is_cash_pass'] = '0';
                $arrReturn['is_pre_booked_ticket'] = '0';
                $arrReturn['extra_options_exists'] = '0';
                if ($result->activation_method == '2') {
                    $arrReturn['is_cash_pass'] = '1';
                }
                $arrReturn['types_count'] = array_values($types_count);
                $arrReturn['show_extend_button'] = ($is_scan_countdown == '1' && !empty($upsell_ticket_ids)) ? 1 : 0;
                $arrReturn['upsell_ticket_ids'] = $upsell_ticket_ids;
                $arrReturn['pass_no'] = $pass_no;
                $arrReturn['upsell_left_time'] = '';
                $arrReturn['is_scan_countdown'] = $is_scan_countdown;
                $arrReturn['is_late'] = 0;
                $arrReturn['multi_redeem'] = $redeem_all_passes_on_one_scan;
                if ($is_reservation == 1 && $result->channel_type == 5 && $is_voucher == 1 && $result->used == '1' && $is_scan_countdown == 1) {
                    $arrReturn['is_redeem'] = 1;
                } else {
                    $arrReturn['is_redeem'] = ($is_redeem != null) ? $is_redeem : 0;
                }
                $left_time = '0';
                //Check pass left time 
                if(!empty($countdown_interval) && ($arrReturn['is_redeem'] == 1)) {
                    $countdown_time = explode('-', $countdown_interval);
                    $current_count_down_time = $this->get_count_down_time($countdown_time[1], $countdown_time[0]);
                    if($countdown_time[1] != '') {
                        $arrReturn['upsell_left_time'] = ($current_count_down_time + $result->scanned_at) . '_' . $countdown_time[1] . '_' . $result->scanned_at;
                        if ($result->scanned_at == '' || $result->scanned_at == '0' || $result->scanned_at == NULL) {
                            $arrReturn['upsell_left_time'] = $current_count_down_time . '_' . $countdown_time[1] . '_' . $result->scanned_at;
                        }
                    }
                    $difference = $gmt_device_time - $result->scanned_at;
                    $left_time_user_format = $this->get_arrival_status_from_time($gmt_device_time, $result->scanned_at, $current_count_down_time);
                    $arrReturn['countdown_interval'] = $left_time_user_format; 
                } else if ($result->channel_type == 5) {
                    // if($is_reservation == 1) {
                        $current_day = strtotime(date("Y-m-d"));
                        $from_date_time = ($result->from_time == '0') ? $result->selected_date . " 00:00" : $result->selected_date . " " . $result->from_time;
                        $to_date_time = ($result->to_time == "0") ? $result->selected_date . " 00:00" : $result->selected_date . " " . $result->to_time;
                        $arrival_time = $this->get_arrival_time_from_slot('', $from_date_time, $to_date_time, $result->timeslot, $device_time_check);
                        $arrReturn['is_late'] = ($arrival_time['arrival_status'] == 1) ? 0 : 1;  //arrival_status == 1 is for early
                        $arrReturn['countdown_interval'] = $arrival_time['arrival_msg'];
                    // }
                } else {
                    $arrReturn['is_late'] = 0;
                    $arrReturn['countdown_interval'] = '';
                }

                // Time Calculation according to selected date and from Time and To Time 
                $logs['late_checks'] = array('left_time' => $left_time, 'left_time_user_format' => $left_time_user_format, "current_count_down_time" => $current_count_down_time, "difference" => $difference, "result->scanned_at" => $result->scanned_at);
                if (($current_count_down_time > $difference || ($current_count_down_time > 0 && $current_count_down_time == $difference)) || 
                    ($result->channel_type!=5 && empty($result->scanned_at) ) || 
                    ($result->channel_type==5 && $is_scan_countdown==1 &&  ($is_edited!=2))) {
                    if($is_edited==1 && ($selected_date_check > $current_day || $selected_date_check < $current_day) ){
                        $arrReturn['countdown_interval'] = ''; 
                    }else{
                       $arrReturn['countdown_interval'] = $left_time_user_format; 
                    } 
                }
                
                $arrReturn['show_group_checkin_button'] = 1;
                $arrReturn['already_used'] = $all_used;
                $arrReturn['citysightseen_card'] = $citysightseen_card;
                $order_quantity = (int) $quantity;
                if (($order_quantity) < 2) {
                    $arrReturn['show_group_checkin_button'] = 0;
                }
                if (($result->is_combi_ticket == '1' && $result->passNo == $pass_no) && $add_to_pass == '0') {
                    $arrReturn['show_group_checkin_button'] = 0;
                }
                if (($is_group_check_in_allowed == '1' && $is_group_entry_allowed == '1') && $add_to_pass == '0') {
                    $arrReturn['show_group_checkin_button'] = 0;
                }
                if ($add_to_pass == "0" && $museum_id != $result->museum_id && $result->clustering_id > 0 && $result->is_addon_ticket != "0") {
                    $arrReturn['show_group_checkin_button'] = 0;
                }
                if ($result->channel_type == 5) {
                    $arrReturn['show_group_checkin_button'] = 0;
                }
                $is_editable = ($add_to_pass == "0") ? 1: 0;
                $arrReturn['scanned_at'] = !empty($result->scanned_at) ? date('d/m/Y H:i:s', $result->scanned_at) : gmdate('d/m/Y H:i:s');
                $arrReturn['late_checkin_time'] = $late_checkin_time;
                $additional_information = unserialize($result->additional_information);
                if ($result->channel_type != 5 && $is_scan_countdown == 0) {
                    $arrReturn['countdown_interval'] = '';
                }
            } else {
                $status = 0;
                $message = 'Pass not valid';
                $booking_note = "";
            }

            //prepaid_tickets response
            $pt_response = array();
            $pt_response['status'] = $status;
            $pt_response['data'] = $arrReturn;
            $pt_response['message'] = $message;
            $pt_response['is_ticket_listing'] = $is_ticket_listing;
            $pt_response['add_to_pass'] = (int) ($add_to_pass == 2) ? 0 : $add_to_pass;
            $pt_response['is_early_reservation_entry'] = ($late_redeem == 1) ? 2 : $is_early_reservation_entry;
            $pt_response['is_editable'] = ($late_redeem == 1) ? 0  : $is_editable;
            $pt_response['show_cluster_extend_option'] = $show_cluster_extend_option;
            $pt_response['cluster_main_ticket_details'] = $cluster_main_ticket_details;
            $pt_response['booking_note'] = $booking_note;
            $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
            if (!empty($internal_log)) {
                $internal_logs['get_pass_info_from_prepaid_tickets'] = $internal_log;
            }
            return $pt_response;
        // } catch (\Exception $e) {
        //     $MPOS_LOGS['scan_pass'] = $logs;
        //     if(json_decode($e->getMessage(), true) == NULL){
        //         $MPOS_LOGS['exception'] = $e->getMessage();
        //     } else {
        //         $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        //     }
        //     return $MPOS_LOGS['exception'];
        // }
    }

    /**
     * @Name     : check_bleep_pass_valid()
     * @Purpose  : to check if scanned bleep pass is valid or not
     * @Called   : called from bleep pass scanner
     * @Created  : Taranjeet Singh<taran.intersoft@gmail.com> on date 17 March 2018
     */
    function check_bleep_pass_valid($data) {
        global $MPOS_LOGS;
        try{
            $pass_no = $data['pass_no'];
            $guest_details = isset($data['guest_details']) ? $data['guest_details'] : 0;
            $logs['pass_no_'.date('H:i:s')] = array($pass_no);
            //check pass in bleep_passes table
            $result = $this->find('bleep_pass_nos', array('select' => '*', 'where' => 'pass_no = "'.$pass_no.'"'), 'row_object');
            $logs['check_pass_query'] = $this->primarydb->db->last_query();
            if (!empty($result)) {
                if ($result->status == '1') {
                    //check if scanned pass already exist in PT
                    $pt_result = $this->find('prepaid_tickets', array('select' => 'prepaid_ticket_id, ticket_id, used, scanned_at, payment_conditions, extra_booking_information, guest_names, guest_emails ', 'where' => 'bleep_pass_no = "' . $pass_no.'" and is_refunded != "1" and activated = "1"'), 'object');
                    $logs['prepaid_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                    $logs['prepaid_query__result_'.date('H:i:s')] = count($pt_result);
                    if (!empty($pt_result)) {
                        $status = 0;
                        $message = 'Pass not valid';
                        $ticket_ids = $all_prepaid_data = $used_tickets = $pt_data = array();
                        $total_count = 0;
                        
                        //prepare guest details for WAT order
                        $extra_booking_info = json_decode(stripslashes($pt_result[0]->extra_booking_information), true);
                        $user_info = $extra_booking_info['per_participant_info'];
                        $guest_names = ($pt_result[0]->guest_names != '' && $pt_result[0]->guest_names != null) ? $pt_result[0]->guest_names : '';
                        $guest_emails = ($pt_result[0]->guest_emails != '' && $pt_result[0]->guest_emails != null) ? $pt_result[0]->guest_emails : '';
                        if(!empty($user_info)) {
                            $user_info['name'] = (($user_info['name'] == null) || $user_info['name'] == '') ? $guest_names : $user_info['name'];
                            $user_info['email'] = (($user_info['email'] == null) || $user_info['email'] == '') ? $guest_emails : $user_info['email'];
                            $guest_detail['name'] = isset($user_info['name']) ? ucfirst(trim($user_info['name'])) : '';
                            $guest_detail['email_id'] = isset($user_info['email']) ? $user_info['email'] : '';
                            $guest_detail['dob'] = ($user_info['date_of_birth'] != '' && $user_info['date_of_birth'] != NULL) ? $user_info['date_of_birth'] : '';
                            $guest_detail['departure'] = isset($user_info['departure']) ? $user_info['departure'] : '';
                            $guest_detail['arrival'] = isset($user_info['arrival']) ? $user_info['arrival'] : '';
                            $guest_detail['mode_of_transport'] = isset($user_info['mode_of_transport']) ? $user_info['mode_of_transport'] : '';
                            $guest_detail['passport_no'] = isset($user_info['id']) ? $user_info['id'] : '';
                            $guest_detail['dietary_information'] = '';
                            $guest_detail['additional_information'] = '';
                            $guest_detail['gender'] = isset($user_info['gender']) ? $user_info['gender'] : '';
                            $guest_detail['nationality'] = isset($user_info['nationality']) ? $user_info['nationality'] : '';
                            $guest_detail['country_of_residence'] = isset($user_info['country_of_residence']) ? $user_info['country_of_residence'] : '';
                            $guest_detail['phone_number'] = isset($user_info['phone_no']) ? $user_info['phone_no'] : '';
                            $guest_detail['aiuia_resident'] = isset($user_info['is_aiuia_resident']) ? $user_info['is_aiuia_resident'] : '';
                            $guest_detail['attended_WaT_season'] = isset($user_info['is_attended_WaT_season']) ? $user_info['is_attended_WaT_season'] : '';
                            $guest_detail['survey_info'] = isset($user_info['survey_info']) ? $user_info['survey_info'] : '';
                        }
                        
                        //check if pass is expired fot countdown tickets in PT or not
                        foreach ($pt_result as $data) {
                            $valid_till_time = $data->payment_conditions;
                            $current_time = strtotime(gmdate('m/d/Y H:i:s'));
                            if (($valid_till_time > 0 && $valid_till_time > $current_time) || ($valid_till_time == NULL || $valid_till_time == '') ) {
                                $pt_data[] = $data;
                            }
                        }
                        //check if pass is already used for non-countdown tixkets in PTand get ticket ids
                        if (!empty($pt_data) && $guest_details == 0) {
                            foreach ($pt_data as $prepaid_detail) {
                                $total_count ++;
                                if (($prepaid_detail->used == '1' || $prepaid_detail->payment_conditions > strtotime(gmdate('m/d/Y H:i:s'))) && $prepaid_detail->scanned_at > 0) {
                                    $used_tickets[] = $prepaid_detail;
                                    $ticket_ids[] = $prepaid_detail->ticket_id;
                                    $all_prepaid_data[$prepaid_detail->ticket_id][] = $prepaid_detail;
                                }
                            }
                            //Get ticket details from modeventcontent table
                            if (!empty($ticket_ids)) {
                                $ticket_status = $this->find('modeventcontent', array('select' => 'mec_id, is_scan_countdown, countdown_interval', 'where' => 'mec_id in (' . implode(',', $ticket_ids).')'), 'object');
                                $scan_countdown_tickets = array();
                                if (!empty($ticket_status)) {
                                    foreach ($ticket_status as $tickets) {
                                        $scan_countdown_tickets[$tickets->mec_id]['is_scan_countdown'] = $tickets->is_scan_countdown;
                                        $scan_countdown_tickets[$tickets->mec_id]['countdown_interval'] = $tickets->countdown_interval;
                                    }
                                }
                            }
                            //check if countdown passes are still valid
                            $no_scan_count_down = $expired_count = $is_count_down = 0;
                            $logs['pt_data_'.date('H:i:s')] = count($all_prepaid_data);
                            foreach ($all_prepaid_data as $ticket_id => $prepaid_detail) {
                                foreach ($prepaid_detail as $prepaid_tickets) {
                                    $is_scan_countdown = $scan_countdown_tickets[$ticket_id]['is_scan_countdown'];
                                    $countdown_interval = $scan_countdown_tickets[$ticket_id]['countdown_interval'];
                                    $countdown_time = explode('-', $countdown_interval);
                                    if (empty($prepaid_tickets->scanned_at)) {
                                        $prepaid_tickets->scanned_at = 0;
                                    }
                                    $difference = strtotime(gmdate('m/d/Y H:i:s')) - $prepaid_tickets->scanned_at;
                                    $logs['difference'] = $difference;
                                    $current_count_down_time = $this->get_count_down_time($countdown_time[1], $countdown_time[0]);
                                    if ($is_scan_countdown == '1') {
                                        ++$is_count_down;
                                        if ($current_count_down_time < $difference) {
                                            ++$expired_count;
                                        }
                                    } else {
                                        ++$no_scan_count_down;
                                    }
                                }
                            }
                            //check if pass is valid or not
                            $logs['valid_count'] = array('total_count' => $total_count, 'without_countdown_tickets' => $no_scan_count_down, 'countdown_tickets' => $is_count_down, 'expired count' => $expired_count, 'used_count' => count($used_tickets));
                            if ((!empty($all_prepaid_data) && $no_scan_count_down == count($used_tickets) && $total_count == count($used_tickets) && $is_count_down == 0) || (!empty($all_prepaid_data) && ($is_count_down == $expired_count) && $total_count == count($used_tickets))) {
                                $status = 1;
                                $message = 'Valid';
                            }
                        } else {
                            $status = 1;
                            $message = 'Valid';
                        }
                    } else {
                        $status = 1;
                        $message = 'Valid';
                    }
                    if (!empty($guest_detail)) {
                        $response = array('status' => $status, 'message' => $message, 'guest_details' => $guest_detail);
                    } else {
                        $response = array('status' => $status, 'message' => $message);
                    }
                } else {
                    $response = array('status' => 1, 'message' => 'Valid');
                }
            } else {
                $response = array('status' => 0, 'message' => 'Pass not valid');
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['check_bleep_pass_valid'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['check_bleep_pass_valid'] = $logs;
        return $response;
    }

    /**
     * @Name     : activate_prepaid_bleep_pass()
     * @Purpose  : to activate bleep pass in prepaid_tickets
     * @Called   : called from bllep scanner api method.
     * @Created  : Taranjeet Singh<taran.intersoft@gmail.com> on date 12 Jan 2018
     */
    function activate_prepaid_bleep_pass($museum_info = array(), $museum_id = 0, $pass_no = '', $bleep_pass_no = '', $pos_point_id = 0, $pos_point_name = '', $shift_id = 0) {
        global $MPOS_LOGS;
        global $spcl_ref;
        try {
            //define empty element must to avoid object/array issue in android end        
            $arrReturn = $types_bleep_passes = array();
            $is_ticket_listing = 0;
            $is_prepaid = 1;
            foreach ($bleep_pass_no as $bleep_pass_nos) {
                if (!empty($bleep_pass_nos['clustering_id'])) {
                    $types_bleep_passes[$bleep_pass_nos['clustering_id']][] = $bleep_pass_nos['pass_no'];
                    $types_bleep_passes['0'.$bleep_pass_nos['clustering_id']][] = $bleep_pass_nos['pass_no'];
                } else {
                    $types_bleep_passes[$bleep_pass_nos['tps_id']][] = $bleep_pass_nos['pass_no'];
                }
            }
            //condition on the basis of reference number for api orders
            if (substr($pass_no, 0, 3) == "CXS" || substr($pass_no, 0, 3) == "CSS") {
                $third_party = 1;
                $pass_condition = ' or without_elo_reference_no = "' . $pass_no . '"';
            } else {
                $third_party = 0;
                $pass_condition = '';
            }
            if (1) {
                //get the exact pass number
                if (strstr($pass_no, 'http')) {
                    $pass_no = substr($pass_no, -PASSLENGTH);
                }

                //get data from prepaid_tickets
                $select = 'prepaid_ticket_id, clustering_id,action_performed, is_combi_ticket,bleep_pass_no, second_party_type, third_party_type, without_elo_reference_no, visitor_tickets_id, ticket_id, tps_id, title, price, ticket_type, age_group, hotel_name, cashier_name, pos_point_name, tax, visitor_group_no, passNo, group_price, activation_method, used, action_performed, scanned_at, is_prepaid, booking_status, channel_type, selected_date, from_time, to_time, timeslot, second_party_passNo, second_party_type, third_party_type, created_date_time, is_invoice';
                $where = "visitor_group_no !='0' and (passNo='" . $pass_no . "' or visitor_group_no ='" . $pass_no . "' " . $pass_condition . ") and activated = '1' and (order_status = '0' or order_status = '2' or order_status = '3') and is_refunded != '1'";
                $results = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');

                $prepaid_ticket_ids = array();
                $is_scan_countdown = 1;
                $countdown_interval = '';
                $pass_no_scanned = 0;
                $logs['pt_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                if (!empty($results)) {
                    $all_used = 1;
                    $ticket_ids = array();
                    foreach ($results as $pt_result) {
                        if($pt_result->passNo == $pass_no && $pass_no_scanned == 0) {
                            $pass_no_scanned = 1;
                        }
                        if ($third_party == 1) {
                            $pass_no = $pt_result->passNo;
                        }
                        $ticket_ids[$pt_result->ticket_id] = $pt_result->ticket_id;
                    }
                    foreach ($results as $pt_result) {
                        $result = $pt_result;
                        if ($pt_result->used == '0') {
                            $all_used = 0;
                            break;
                        }
                    }
                    //fetch ticket details from modeventcontent table
                    $types_count = array();
                    $ticket_status = $this->find('modeventcontent', array('select' => 'mec_id, guest_notification, cod_id, is_scan_countdown, countdown_interval, upsell_ticket_ids, second_party_id, third_party_id, second_party_parameters, third_party_parameters', 'where' => 'mec_id in (' . implode(',', $ticket_ids) . ')'), 'object');
                    $tickets_data = array();
                    $show_extend_botton = 0;
                    if (!empty($ticket_status)) {
                        foreach ($ticket_status as $ticket) {
                            $tickets_data[$ticket->mec_id] = $ticket;
                            if ($ticket->is_scan_countdown == '1' && !empty($ticket->upsell_ticket_ids) && $museum_id == $ticket->cod_id) {
                                $show_extend_botton = 1;
                            }
                        }
                    }
                    if (!empty($tickets_data[$result->ticket_id])) {
                        $is_scan_countdown = $tickets_data[$result->ticket_id]->is_scan_countdown;
                        $countdown_interval = $tickets_data[$result->ticket_id]->countdown_interval;
                        $upsell_ticket_ids = $tickets_data[$result->ticket_id]->upsell_ticket_ids;
                    }

                    if (($is_scan_countdown == '0' && $all_used) && ($result->scanned_at - strtotime(gmdate('Y-m-d h:i:s'))) < -(24 * 3600)) {
                        $status = 0;
                        $message = 'Pass not valid';
                        //prepaid_tickets response
                        $pt_response = array();
                        $pt_response['status'] = $status;
                        $pt_response['data'] = array();
                        $pt_response['message'] = $message;
                        return $pt_response;
                    }
                    //check if case of early redeem
                    $early_redeem = 0;
                    $timezone_count = 3600 * $result->timezone;
                    if ($is_scan_countdown == '1' && !empty($result->selected_date) && ( (strtotime($result->selected_date) - $timezone_count) > strtotime(gmdate('Y-m-d h:i')) )) {
                        $early_redeem = 1;
                    }
                    //calculate valid time for pass in case of countdown tickets
                    $logs['valid_till_conditions__' . date('H:i:s')] = array($is_scan_countdown, $result->selected_date, (strtotime($result->selected_date) - $timezone_count), strtotime(gmdate('Y-m-d h:i')), $early_redeem);
                    if ($is_scan_countdown == 1 && $early_redeem == 0) {
                        $countdown_values = explode('-', $countdown_interval);
                        $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                        $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                        $insert_pt_db2['payment_conditions'] = $valid_till;
                        $valid_time = ', payment_conditions = "' . $valid_till . '"';
                    } else {
                       $valid_time = $insert_pt_db2['payment_conditions'] = '';
                    }
                    $logs['early_redeem__countdown_interval'] = array('early_redeem' => $early_redeem, 'countdown_interval' => $countdown_interval, 'types_from_mec_' . date('H:i:s') => $this->primarydb->db->last_query());
                    //Fetch complete order details to activate citycards
                    $pt_check = ' and is_addon_ticket = "0" and activated = "1" and used = "0" and (order_status = "0" or order_status = "2" or order_status = "3") and is_refunded != "1"';
                    if ($result->channel_type == 5) {
                        $get_count = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'visitor_group_no = ' . $result->visitor_group_no . ' and passNo = "' . $result->passNo . '"'.$pt_check), 'object');
                    } else {
                        $get_count = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'visitor_group_no = ' . $result->visitor_group_no . $pt_check), 'object');
                    }
                    $logs['get_pt_unused_count_query'] = $this->primarydb->db->last_query();
                    $quantity = 0;
                    //Prepare types count array
                    if (!empty($get_count)) {
                        $quantity = count($get_count);
                        $logs['quantity in PT'] = $quantity;
                        foreach ($get_count as $prepaid_count) {
                            $ticket_type_id = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                            $types_count[$prepaid_count->tps_id]['ticket_type'] = $ticket_type_id;
                            $types_count[$prepaid_count->tps_id]['ticket_type_label'] = ucfirst(strtolower($prepaid_count->ticket_type));
                            $types_count[$prepaid_count->tps_id]['title'] = $prepaid_count->title;
                            $types_count[$prepaid_count->tps_id]['price'] = $prepaid_count->price;
                            $types_count[$prepaid_count->tps_id]['tax'] = $prepaid_count->tax;
                            $types_count[$prepaid_count->tps_id]['count'] += 1;
                        }

                        foreach ($get_count as $pt_result) {
                            if ($pt_result->used == '0') {
                                if (!empty($pt_result->clustering_id)) {
                                    $prepaid_ticket_ids[$pt_result->clustering_id]['prepaid_ticket_id'] = $pt_result->prepaid_ticket_id;
                                    $prepaid_ticket_ids[$pt_result->clustering_id]['ticket_type'] = $pt_result->ticket_type;
                                    $prepaid_ticket_ids[$pt_result->clustering_id]['clustering_id'] = $pt_result->clustering_id;
                                    $prepaid_ticket_ids[$pt_result->clustering_id]['tps_id'] = $pt_result->tps_id;
                                } else {
                                    $prepaid_ticket_ids[$pt_result->prepaid_ticket_id]['prepaid_ticket_id'] = $pt_result->prepaid_ticket_id;
                                    $prepaid_ticket_ids[$pt_result->prepaid_ticket_id]['ticket_type'] = $pt_result->ticket_type;
                                    $prepaid_ticket_ids[$pt_result->prepaid_ticket_id]['tps_id'] = $pt_result->tps_id;
                                    $prepaid_ticket_ids[$pt_result->prepaid_ticket_id]['clustering_id'] = $pt_result->clustering_id;
                                }
                            }
                        }
                        $redeem_date_time = gmdate("Y-m-d H:i:s");
                        $scanned_at = strtotime($redeem_date_time);
                        if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') {
                            $museum_cashier_id = $museum_info->reseller_cashier_id;
                            $museum_cashier_name = $museum_info->reseller_cashier_name;
                        } else {
                            $museum_cashier_id = $museum_info->uid;
                            $museum_cashier_name = $museum_info->fname . ' ' . $museum_info->lname;
                        }
                        //Make update citycards queries
                        if (strpos($result->action_performed, 'CSS_ACT') === false || (strpos($result->action_performed, 'MPOS_ACT_CNCL') !== false && ($result->bleep_pass_no == '' || $result->bleep_pass_no == NULL))) {
                            $update_bleep_query = ' update prepaid_tickets set museum_cashier_id= (case when (is_addon_ticket = "0") then "' . $museum_cashier_id . '" else 0 end ), museum_cashier_name= (case when (is_addon_ticket = "0") then "' . $museum_cashier_name . '" else "" end ) ' . $valid_time . ', ';
                            $expedia_query = ' update expedia_prepaid_tickets set  ';
                            $insert_pt_db2['redeem_method'] = "Voucher";
                            $insert_pt_db2['updated_at'] = gmdate("Y-m-d H:i:s");
                            $insert_pt_db2['pos_point_id_on_redeem'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $pos_point_id), "default" => "0");
                            $insert_pt_db2['pos_point_name_on_redeem'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $pos_point_name), "default" => "");
                            $insert_pt_db2['distributor_id_on_redeem'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $museum_info->dist_id), "default" => "0");
                            $insert_pt_db2['distributor_cashier_id_on_redeem'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $museum_info->dist_cashier_id), "default" => "0");
                            $insert_pt_db2['voucher_updated_by'] = $insert_pt_db2['museum_cashier_id'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $museum_cashier_id), "default" => "0");
                            $insert_pt_db2['voucher_updated_by_name'] = $insert_pt_db2['museum_cashier_name'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $museum_cashier_name), "default" => "");
                            $insert_vt['CONCAT_VALUE']['action_performed'] = ', CSS_ACT';
                            $insert_vt['updated_at'] = gmdate("Y-m-d H:i:s");
                            $insert_vt['updated_by_id'] = $museum_cashier_id;
                            $insert_vt['updated_by_username'] = $museum_cashier_name;
                            $insert_vt['voucher_updated_by'] = array('case' => array('key' => 'col2','value' => '0','update' => $museum_cashier_id), "default" => "");
                            $insert_vt['voucher_updated_by_name'] = array('case' => array('key' => 'col2','value' => '0','update' => $museum_cashier_name), "default" => "");
                            $insert_vt['redeem_method'] = "Voucher";
                            if ($early_redeem == 0) {
                                //update used with scanned_at
                                $update_bleep_query .= ' scanned_at= (case when (is_addon_ticket = "0") then "' . $scanned_at . '" else "" end ), used= (case when (is_addon_ticket = "0") then "1" else "0" end ),  redeem_date_time= (case when (is_addon_ticket = "0") then "' . $redeem_date_time . '" else "1970-01-01 00:00:01" end ), ';
                                $expedia_query .= ' scanned_at= (case when (is_addon_ticket = "0") then "' . $scanned_at . '" else "" end ), ';
                                $insert_pt_db2['scanned_at'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $scanned_at), "default" => "");
                                $insert_pt_db2['used'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => '1'), "default" => "0");
                                $insert_pt_db2['redeem_date_time'] = array('case' => array('key' => 'is_addon_ticket','value' => '0','update' => $redeem_date_time), "default" => "1970-01-01 00:00:01");
                                $insert_vt['visit_date'] = array('case' => array('key' => 'col2','value' => '0','update' => $scanned_at));
                                $insert_vt['used'] = array('case' => array('key' => 'col2','value' => '0','update' => '1'), "default" => "0");
                            }
                            $update_bleep_query .= ' action_performed=CONCAT(action_performed, ", CSS_ACT"), ';
                            $expedia_query .= ' action_performed=CONCAT(action_performed, ", CSS_ACT"), ';
                            $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', CSS_ACT';
                            if ($result->second_party_type == 5 || $result->third_party_type == 5) {
                                $insert_pt_db2['authcode'] = array('case' => array('separator' => '||', 'conditions' => array(
                                    array( 'key' => 'authcode','value' => ''), array( 'key' => 'authcode','value' => 'NULL')
                                ) ,'update' => gmdate("Y-m-d H:i:s")));
                                $insert_pt_db2['redemption_notified_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                                    array( 'key' => 'redemption_notified_at','value' => ''), array( 'key' => 'redemption_notified_at','value' => 'NULL')
                                ) ,'update' => gmdate("Y-m-d H:i:s")));
                            }
                            if ($result->booking_status == 0 && $result->channel_type == 2) {
                                $insert_pt_db2['order_confirm_date'] = gmdate('Y-m-d H:i:s');
                            }
                            if ((in_array($result->channel_type, $this->api_channel_types)) && (!in_array($result->is_invoice, $this->is_invoice)) && $shift_id > 0) {
                                $update_bleep_query .= ' shift_id="' . $shift_id . '", ';
                                $insert_vt['shift_id'] = $insert_pt_db2['shift_id'] = $shift_id;
                            }
                            if ($result->channel_type == 5 && $early_redeem == 0) {
                                $update_bleep_query .= ' used="1", ';
                                $insert_pt_db2['used'] = $insert_vt['used'] = '1';
                            }
                            $update_bleep_query .= ' bleep_pass_no=(case ';
                            $expedia_query .= ' bleep_pass_no=(case ';
                            $all_bleep_pass_nos = array();
                            if (!empty($prepaid_ticket_ids)) {
                                foreach ($prepaid_ticket_ids as $prepaid_ticket) {
                                    $prepaid_ticket_id = $prepaid_ticket['prepaid_ticket_id'];
                                    $clustering_id = $prepaid_ticket['clustering_id'];
                                    $tps_id = $prepaid_ticket['tps_id'];
                                    if (!empty($clustering_id)) {
                                        if ($types_bleep_passes[$clustering_id][0] != '' || $types_bleep_passes[$clustering_id][0] != NULL) {
                                            $clustering_ids_for_updation[] = $clustering_id;
                                            if (date("Y-m-d", strtotime($result->created_date_time)) < '2019-08-01') {
                                                $prepaid_ticket_ids_array[] = $prepaid_ticket_id;
                                            }
                                            $insert_pt_db2['bleep_pass_no']['case'][] = array('separator' => '&&', 'conditions' => array(
                                                    array( 'key' => 'clustering_id','value' => $clustering_id), array( 'key' => 'visitor_group_no','value' => $result->visitor_group_no)
                                                ) ,'update' => $types_bleep_passes[$clustering_id][0]);
                                            $insert_vt['scanned_pass']['case'][] = array('separator' => '&&', 'conditions' => array(
                                                    array( 'key' => 'targetlocation','value' => $clustering_id), array( 'key' => 'vt_group_no','value' => $result->visitor_group_no)
                                                ) ,'update' => $types_bleep_passes[$clustering_id][0]);
	                                    $update_bleep_query .= ' when (clustering_id="' . $clustering_id . '" and visitor_group_no="' . $result->visitor_group_no . '") then  "' . $types_bleep_passes[$clustering_id][0] . '" ';
	                                    $expedia_query .= ' when (clustering_id="' . $clustering_id . '" and visitor_group_no="' . $result->visitor_group_no . '") then  "' . $types_bleep_passes[$clustering_id][0] . '" ';
	                                    $all_bleep_pass_nos[] = $types_bleep_passes[$clustering_id][0];
	                                    unset($types_bleep_passes[$clustering_id][0]);
	                                    sort($types_bleep_passes[$clustering_id]);
                                        }
                                    } else {
                                        if ($types_bleep_passes[$tps_id][0] != '' || $types_bleep_passes[$tps_id][0] != NULL) {
                                            $prepaid_ticket_ids_for_updation[] = $prepaid_ticket_id;
                                            $logs['prepaid_ticket_ids_for_updation'] = $prepaid_ticket_ids_for_updation;
                                            $bleep_pass_no_insert['case'][] = array('separator' => '||', 'conditions' => array(
                                                    array( 'key' => 'prepaid_ticket_id','value' =>  $prepaid_ticket_id)
                                                ) ,'update' => $types_bleep_passes[$tps_id][0]);
                                            $scanned_pass_insert['case'][] = array('separator' => '||', 'conditions' => array(
                                                    array( 'key' => 'transaction_id','value' =>  $prepaid_ticket_id)
                                                ) ,'update' => $types_bleep_passes[$tps_id][0]);
                                            $update_bleep_query .= ' when prepaid_ticket_id="' . $prepaid_ticket_id . '" then  "' . $types_bleep_passes[$tps_id][0] . '" ';
                                            $expedia_query .= ' when prepaid_ticket_id="' . $prepaid_ticket_id . '" then  "' . $types_bleep_passes[$tps_id][0] . '" ';
                                            $all_bleep_pass_nos[] = $types_bleep_passes[$tps_id][0];
                                            unset($types_bleep_passes[$tps_id][0]);
                                            sort($types_bleep_passes[$tps_id]);
                                    	}
                                    }	
                                }
                                if (!empty($bleep_pass_no_insert)) {
                                    $insert_pt_db2['bleep_pass_no'] = $bleep_pass_no_insert;
                                    $insert_vt['scanned_pass'] = $scanned_pass_insert;
                                }
                                $logs['prepaid_ticket_ids_for_updation'] = $prepaid_ticket_ids_for_updation;
                                $logs['clustering_ids_for_updation'] = $clustering_ids_for_updation;
                                if (!empty($all_bleep_pass_nos)) {
                                    $bleep_passes = implode(', ', $all_bleep_pass_nos);
                                    $all_bleep_pass_nos_string = implode('","', $all_bleep_pass_nos);
                                    $this->query('update bleep_pass_nos set status="1", last_modified_at = "' . gmdate("Y-m-d H:i:s") . '" where pass_no in("' . $all_bleep_pass_nos_string . '")');
                                }

                                $update_bleep_query .= ' else bleep_pass_no end) where visitor_group_no="' . $result->visitor_group_no . '" and is_refunded != "1" and activated = "1" and used = "0" ';
                                $expedia_query .= ' else bleep_pass_no end) where visitor_group_no="' . $result->visitor_group_no . '" and is_refunded != "1" and used = "0" ';
                                $where_db2_insert = ' visitor_group_no="' . $result->visitor_group_no . '" and is_refunded != "1" and activated = "1" and used = "0" ';
                                $where_vt_insert = ' vt_group_no="' . $result->visitor_group_no . '" and is_refunded != "1" and used = "0"';
                                if ($result->channel_type == 5) {
                                    $update_bleep_query .= ' and passNo = "' . $result->passNo . '"';
                                    $where_db2_insert .= ' and passNo = "' . $result->passNo . '"';
                                    $where_vt_insert .= ' and passNo = "' . $result->passNo . '"';
                                }
                                if($pass_no_scanned == 1) {
                                    if (!empty($prepaid_ticket_ids_for_updation)) {
                                        $update_bleep_query .= ' and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids_for_updation) . ')';
                                        $where_db2_insert .= ' and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids_for_updation) . ')';
                                        $where_vt_insert .= ' and transaction_id IN (' . implode(',', $prepaid_ticket_ids_for_updation) . ')';
                                    } else if (!empty($clustering_ids_for_updation)) {
                                        $update_bleep_query .= ' and clustering_id IN (' . implode(',', $clustering_ids_for_updation) . ')';
                                        $where_db2_insert .= ' and clustering_id IN (' . implode(',', $clustering_ids_for_updation) . ')';
                                        if(!empty($prepaid_ticket_ids_array)) {
                                            $where_vt_insert .= ' and transaction_id IN (' . implode(',', $prepaid_ticket_ids_array) . ')';
                                        } else {
                                            $where_vt_insert .= ' and targetlocation IN (' . implode(',', $clustering_ids_for_updation) . ')';
                                        }
                                       
                                    }
                                }
                                $this->query($update_bleep_query);
                            }
                            //SYNC firebase in case of API orders
                            $logs['update_queries_' . date('H:i:s')] = array('query_db1=>' => $update_bleep_query);
                            if (in_array($result->channel_type, $this->api_channel_types) && (!in_array($result->is_invoice, $this->is_invoice))) {
                                /* Set values to sync bookings on firebase. */
                                foreach ($get_count as $prepaid_tickets_data) {
                                    $prepaid_ticket = (array) $prepaid_tickets_data;
                                    $third_party_response_data = json_decode($prepaid_ticket['third_party_response_data'], true);
                                    $visitor_group_no = $prepaid_ticket['visitor_group_no'];
                                    $selected_date = ($prepaid_ticket['selected_date'] != 0 && $prepaid_ticket['selected_date'] != '') ? $prepaid_ticket['selected_date'] : '';
                                    $from_time = ($prepaid_ticket['from_time'] != '' && $prepaid_ticket['from_time'] != '0') ? $prepaid_ticket['from_time'] : "";
                                    $to_time = ($prepaid_ticket['to_time'] != 0 && $prepaid_ticket['to_time'] != '') ? $prepaid_ticket['to_time'] : '';
                                    if ($prepaid_ticket['channel_type'] == 5) {
                                        $sync_key = base64_encode($prepaid_ticket['visitor_group_no'] . '_' . $prepaid_ticket['ticket_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $prepaid_ticket['created_date_time'] . "_" . $prepaid_ticket['passNo']);
                                    } else {
                                        $sync_key = base64_encode($prepaid_ticket['visitor_group_no'] . '_' . $prepaid_ticket['ticket_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $prepaid_ticket['created_date_time']);
                                    }
                                   
                                    $ticket_types[$sync_key][$prepaid_ticket['tps_id']] = array(
                                        'tps_id' => (int) $prepaid_ticket['tps_id'],
                                        'age_group' => $prepaid_ticket['age_group'],
                                        'price' => (float) $prepaid_ticket['price'],
                                        'net_price' => (float) $prepaid_ticket['net_price'],
                                        'type' => ucfirst(strtolower($prepaid_ticket['ticket_type'])),
                                        'quantity' => (int) $ticket_types[$sync_key][$prepaid_ticket['tps_id']]['quantity'] + 1,
                                        'combi_discount_gross_amount' => (float) $prepaid_ticket['combi_discount_gross_amount'],
                                        'refund_quantity' => (int) 0,
                                        'refunded_by' => array(),
                                        'per_age_group_extra_options' => array(),
                                    );
                                    $bookings[$sync_key]['amount'] = (float) ($bookings[$sync_key]['amount'] + ((float) $prepaid_ticket['price']) + (float) $prepaid_ticket['combi_discount_gross_amount']);
                                    $bookings[$sync_key]['quantity'] = (int) ($bookings[$sync_key]['quantity'] + 1);
                                    $bookings[$sync_key]['passes'] = ($bookings[$sync_key]['passes'] != '' && $prepaid_ticket['is_combi_ticket'] == 0 ) ? $bookings[$sync_key]['passes'] . ', ' . $prepaid_ticket['passNo'] : $prepaid_ticket['passNo'];
                                    $bookings[$sync_key]['bleep_passes'] = ($bleep_passes != '') ? $bleep_passes : '';
                                    $bookings[$sync_key]['booking_date_time'] = $prepaid_ticket['created_date_time'];
                                    $bookings[$sync_key]['booking_name'] = '';
                                    $bookings[$sync_key]['cashier_name'] = $prepaid_ticket['cashier_name'];
                                    $bookings[$sync_key]['pos_point_id'] = (int) $pos_point_id;
                                    $bookings[$sync_key]['pos_point_name'] = $pos_point_name;
                                    if ($prepaid_ticket['hotel_id'] == CITY_EXPERT) { // 2667
                                        $bookings[$sync_key]['group_id'] = (int) 1;
                                        $bookings[$sync_key]['group_name'] = 'City Expert';
                                    } else if($prepaid_ticket['hotel_id'] == CITY_SIGHT_SEEING) { //1790
                                        if(strtolower($third_party_response_data['third_party_params']['agent']) == "city-sightseeing-spain.com") {
                                            $bookings[$sync_key]['group_id'] = (int) 2;
                                        } else if(strtolower($third_party_response_data['third_party_params']['agent']) == "city-sightseeing.com") {
                                            $bookings[$sync_key]['group_id'] = (int) 3;
                                        } else {
                                            $bookings[$sync_key]['group_id'] = (int) 4;
                                        }
                                        $bookings[$sync_key]['group_name'] = $third_party_response_data['third_party_params']['agent'] != '' ? $third_party_response_data['third_party_params']['agent'] : 'City Sightseeing Website';
                                    } else {
                                        $bookings[$sync_key]['group_id'] = (int) 5;
                                        $bookings[$sync_key]['group_name'] = 'OTA';
                                    }
                                    $bookings[$sync_key]['channel_type'] = (int) $prepaid_ticket['channel_type'];
                                    $bookings[$sync_key]['discount_code_amount'] = (float) 0;
                                    $bookings[$sync_key]['service_cost_type'] = (int) 0;
                                    $bookings[$sync_key]['service_cost_amount'] = (float) $prepaid_ticket['service_cost_amount'];
                                    $bookings[$sync_key]['activated_by'] = (int) $museum_cashier_id;
                                    $bookings[$sync_key]['activated_at'] = gmdate('Y-m-d h:i:s');
                                    $bookings[$sync_key]['cancelled_tickets'] = (int) 0;
                                    $bookings[$sync_key]['is_voucher'] = (int) 1;
                                    $bookings[$sync_key]['shift_id'] = (int) $shift_id;
                                    $bookings[$sync_key]['ticket_types'] = (!empty($ticket_types[$sync_key])) ? $ticket_types[$sync_key] : array();
                                    $bookings[$sync_key]['per_ticket_extra_options'] = array();
                                    $bookings[$sync_key]['from_time'] = ($prepaid_ticket['from_time'] != '' && $prepaid_ticket['from_time'] != '0') ? $prepaid_ticket['from_time'] : "";
                                    $bookings[$sync_key]['is_reservation'] = (int) !empty($prepaid_ticket['selected_date']) && !empty($prepaid_ticket['from_time']) && !empty($prepaid_ticket['to_time']) ? 1 : 0;
                                    $bookings[$sync_key]['merchant_reference'] = '';
                                    $bookings[$sync_key]['museum'] = $prepaid_ticket['museum_name'];
                                    $bookings[$sync_key]['order_id'] = (isset($visitor_group_no) && $visitor_group_no != '') ? $visitor_group_no : $_REQUEST['visitor_group_no'];
                                    $bookings[$sync_key]['payment_method'] = (int) $prepaid_ticket['activation_method'];
                                    $bookings[$sync_key]['reservation_date'] = ($prepaid_ticket['selected_date'] != '' && $prepaid_ticket['selected_date'] != '0') ? $prepaid_ticket['selected_date'] : "";
                                    $bookings[$sync_key]['ticket_id'] = (int) $prepaid_ticket['ticket_id'];
                                    $bookings[$sync_key]['ticket_title'] = $prepaid_ticket['title'];
                                    $bookings[$sync_key]['timezone'] = (int) $prepaid_ticket['timezone'];
                                    $bookings[$sync_key]['to_time'] = ($prepaid_ticket['to_time'] != '' && $prepaid_ticket['to_time'] != '0') ? $prepaid_ticket['to_time'] : "";
                                    $bookings[$sync_key]['status'] = (int) 2;
                                    $bookings[$sync_key]['is_extended_ticket'] = (int) 0;
                                }
                                /* SYNC bookings */
                                try {
                                    $headers = $this->all_headers(array(
                                        'hotel_id' => $prepaid_ticket['hotel_id'],
                                        'museum_id' => $museum_id,
                                        'ticket_id' => $prepaid_ticket['ticket_id'],
                                        'channel_type' => $prepaid_ticket['channel_type'],
                                        'action' => 'city_card_activation_from_MPOS',
                                        'user_id' => $museum_info->cashier_type == '3' && $museum_info->reseller_id != '' ? $museum_info->reseller_cashier_id : $museum_cashier_id,
                                        'reseller_id' => $museum_info->cashier_type == '3' && $museum_info->reseller_id != '' ? $museum_info->reseller_id : ''
                                    ));
                                    if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') { 
                                        $node = 'resellers/' . $museum_info->reseller_id . '/' . 'voucher_scans' . '/' . $museum_info->reseller_cashier_id . '/' . date("Y-m-d");
                                    } else {
                                        $node = 'suppliers/' . $museum_id . '/voucher_scans/' . $museum_info->uid . '/' . date("Y-m-d");
                                    }
                                    $MPOS_LOGS['DB'][] = 'FIREBASE';
                                    $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                        'type' => 'POST',
                                        'additional_headers' => $headers,
                                        'body' => array("node" => $node, 'details' => $bookings)
                                    ));
                                } catch (\Exception $e) {
                                    $logs['exception'] = $e->getMessage();
                                }

                                /*                                 * ***** Notify Third party system ***** */

                                $request_array = array();
                                $request_array['passNo'] = $result->passNo;
                                $request_array['ticket_id'] = $result->ticket_id;
                                $request_array['scan_api'] = 'scan on activate city card';
                                $request_array['date_time'] = gmdate("Y-m-d H:i:s");
                                $request_string = json_encode($request_array);
                                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                // This Fn used to send notification with data on AWS panel. 
                                if (LOCAL_ENVIRONMENT != 'Local') {
                                    $spcl_ref = 'notify_third_party';
                                    $MPOS_LOGS['external_reference'] = 'notify_third_party';
                                    $logs['data_to_queue_THIRD_PARTY_REDUMPTION_ARN_' . date('H:i:s')] = $request_array;
                                    $this->queue($aws_message, THIRD_PARTY_REDUMPTION, THIRD_PARTY_REDUMPTION_ARN);
                                }

                                if (!empty($expedia_query)) {
                                    $this->query($expedia_query);
                                    $logs['expedia_query_' . date('H:i:s')] = $expedia_query;
                                }
                                
                            }
                            if (UPDATE_SECONDARY_DB) {                                
                                /* if order is redeemed then pass redeem as 1 */
                                $redeemChk = ((isset($insert_pt_db2['used']) && $insert_pt_db2['used']=='1')? 1: 0);
                                $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db2, "where" => $where_db2_insert, "redeem" => $redeemChk);
                                $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt, "where" => $where_vt_insert, "redeem" => $redeemChk);
                                if (!empty($insertion_db2)) {
                                    $request_array['db2_insertion'] = $insertion_db2;                                    
                                    $request_array['action'] = "activate_prepaid_bleep_pass";
                                    $request_array['visitor_group_no'] = $result->visitor_group_no;
                                    $request_array['write_in_mpos_logs'] = 1;
                                    $request_array['pass_no'] = $result->passNo;
                                    $request_array['channel_type'] = $result->channel_type;
                                    $request_string = json_encode($request_array);
                                    $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                                    $MPOS_LOGS['external_reference'] = 'notify_third_party, city_card';
                                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                    if (LOCAL_ENVIRONMENT == 'Local') {
                                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                                    } else {
                                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                                    }
                                }
                            }
                        }
                        //Prepare response array 
                        $countdown_time = explode('-', $countdown_interval);
                        $current_count_down_time = $this->get_count_down_time($countdown_time[1], $countdown_time[0]);
                        $status = 1;
                        $message = 'Valid';
                        $arrReturn['is_combi_ticket'] = $result->is_combi_ticket;
                        $arrReturn['title'] = $result->title;
                        $arrReturn['guest_notification'] = '';
                        $arrReturn['ticket_type'] = (!empty($this->types[strtolower($result->ticket_type)]) && ($this->types[strtolower($result->ticket_type)] > 0)) ? $this->types[strtolower($result->ticket_type)] : 10;
                        $arrReturn['age_group'] = $result->age_group;
                        $arrReturn['price'] = $result->price;
                        $arrReturn['group_price'] = $result->group_price;
                        $arrReturn['visitor_group_no'] = (string) $result->visitor_group_no;
                        $arrReturn['pass_no'] = $pass_no;
                        $arrReturn['ticket_id'] = $result->ticket_id;
                        $arrReturn['tps_id'] = $result->tps_id;
                        $arrReturn['selected_date'] = ($result->selected_date != 0) ? $result->selected_date : '';
                        $arrReturn['slot_type'] = !empty($result->timeslot) ? $result->timeslot : '';
                        $arrReturn['channel_type'] = !empty($result->channel_type) ? $result->channel_type : 0;
                        $arrReturn['from_time'] = ($result->from_time != 0) ? $result->from_time : '';
                        $arrReturn['to_time'] = ($result->to_time != 0) ? $result->to_time : '';
                        $arrReturn['hotel_name'] = !empty($result->hotel_name) ? $result->hotel_name : '';
                        $arrReturn['address'] = '';
                        $arrReturn['cashier_name'] = $result->cashier_name;
                        $arrReturn['pos_point_name'] = $result->pos_point_name;
                        $arrReturn['tax'] = $result->tax;
                        $arrReturn['is_prepaid'] = $result->is_prepaid;
                        $arrReturn['action'] = '1';
                        $arrReturn['is_cash_pass'] = '0';
                        $arrReturn['is_pre_booked_ticket'] = '0';
                        $arrReturn['extra_options_exists'] = '0';
                        if ($result->activation_method == '2') {
                            $arrReturn['is_cash_pass'] = '1';
                        }
                        $arrReturn['count'] = $quantity;
                        $cashier_type = $museum_info->cashier_type;
                        if ($cashier_type == '2') {
                            $arrReturn['show_scanner_receipt'] = 0;
                        } else {
                            $arrReturn['show_scanner_receipt'] = ((in_array($result->channel_type, $this->api_channel_types)) && (!in_array($result->is_invoice, $this->is_invoice))) ? 1 : 0;
                        }
                        $arrReturn['types_count'] = array_values($types_count);
                        $arrReturn['show_extend_button'] = $show_extend_botton;
                        $arrReturn['upsell_ticket_ids'] = $upsell_ticket_ids;
                        $arrReturn['pass_no'] = (string) $pass_no;
                        $arrReturn['is_scan_countdown'] = $is_scan_countdown;
                        $arrReturn['is_late'] = 0;
                        $arrReturn['countdown_interval'] = '';
                        if($countdown_time[1] != '') {
                            if (empty($result->scanned_at)) {
                                $arrReturn['upsell_left_time'] = $current_count_down_time . '_' . $countdown_time[1] . '_' . $result->scanned_at;
                            } else {
                                $arrReturn['upsell_left_time'] = ($current_count_down_time + $result->scanned_at) . '_' . $countdown_time[1] . '_' . $result->scanned_at;
                            }
                        } else {
                            $arrReturn['upsell_left_time'] = '';
                        }

                        $arrReturn['show_group_checkin_button'] = 0;
                        $arrReturn['already_used'] = $all_used;
                        $order_quantity = (int) $quantity;
                        if (($order_quantity) < 2) {
                            $arrReturn['show_group_checkin_button'] = 0;
                        }
                        $arrReturn['scanned_at'] = !empty($result->scanned_at) ? date('d/m/Y H:i:s', $result->scanned_at) : gmdate('d/m/Y H:i:s');
                        //check if this user has activated shift
                        $get_user_details = $this->find(
                            'users', array(
                                'select' => 'company, fname, lname ', 
                                'where' => 'id = "' . $museum_info->dist_cashier_id . '"'
                            ), 'row_object'); 
                        $whereCashierRegister = 'pos_point_id = "' . $pos_point_id . '" and cashier_id= "' . $museum_info->dist_cashier_id . '" and shift_id= "' . $shift_id . '" and hotel_id= "' . $museum_info->dist_id . '" and status = "1" ';
                        $check_cashier_register = $this->find(
                            'cashier_register', array(
                                'select' => '*', 
                                'where' => $whereCashierRegister . ' and date(created_at) = "' . gmdate('Y-m-d') . '"'
                            ), 'row_object');
                        $logs['cashier_register_' . date('H:i:s')] = $this->primarydb->db->last_query();
                        if(empty($check_cashier_register)) { // current day shift is not activated
                            $update_cashier_register_query = 'update cashier_register set status="2"  where ' . $whereCashierRegister . ' and date(created_at) = "' . gmdate('Y-m-d',strtotime("-1 days")) . '"';
                            $logs['update_cashier_register_query' . date('H:i:s')] = $update_cashier_register_query;
                            $this->query($update_cashier_register_query);
                            $logs['update_cashier_register_' . date('H:i:s')] = $this->db->last_query();
                            $pos_point_details = $this->find('pos_points_setting', array('select' => 'location_code, financier_email_address', 'where' => 'pos_point_id = "' . $pos_point_id . '" and hotel_id = "' . $museum_info->dist_id . '"'));
                            $logs['pos_point_details_' . date('H:i:s')] = $this->primarydb->db->last_query();
                            $insertData = array(
                                "created_at" => gmdate('Y-m-d H:i:s'),
                                "modified_at" => gmdate('Y-m-d H:i:s'),
                                "timezone" => $result->timeZone,
                                "hotel_id" => $museum_info->dist_id,
                                "shift_id" => $shift_id,
                                "cashier_id" => $museum_info->dist_cashier_id,
                                "status" => '1',
                                "pos_point_id" => $pos_point_id,
                                "pos_point_name" => $result->pos_point_name,
                                "location_code" => $pos_point_details[0]['location_code'],
                                "pos_point_admin_email" => $pos_point_details[0]['financier_email_address'],
                                "hotel_name" => $get_user_details->company != '' ? $get_user_details->company : '',
                                "cashier_name" => ($get_user_details->fname != '') ?  $get_user_details->fname .' '. $get_user_details->lname : '',
                                "reference_id" => time(),
                                "opening_time" => date("H:i:s", strtotime(gmdate('Y-m-d H:i:s'))),
                                "opening_cash_balance" => 0,
                                "sale_by_cash" => 0,
                                "sale_by_voucher" => 0,
                                "sale_by_card" => 0,
                                "closing_cash_balance" => 0,
                                "closing_card_balance" => 0,
                                "total_sale" => 0,
                                "total_closing_balance" => 0
                            );
                            $this->db->insert('cashier_register', $insertData);
                            $logs['cashier_register_insert' . date('H:i:s')] = $this->db->last_query();
                        }
                    } else {
                        $status = 0;
                        $message = 'pass_not_valid';
                    }
                } else {
                    $status = 0;
                    $message = 'Pass not valid';
                }
            }
            if (!empty($arrReturn)) {
                //replace null with blank
                array_walk_recursive($arrReturn, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }
                    if ($key == 'price') {
                        $item = $item ? (float) $item : $item;
                    }
                    if ($key == 'upsell_ticket_ids' && $key == 'pass_no') {
                        $item = $item ? (string) $item : $item;
                    }
                    $item = null === $item ? '' : $item;
                });
            }
            $response = array('status' => $status, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, 'message' => $message, 'data' => $arrReturn);
        } catch (\Exception $e) {
            $MPOS_LOGS['activate_prepaid_bleep_pass'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['activate_prepaid_bleep_pass'] = $logs;
        return $response;
    }

    /**
     * @Name     : get_prepaid_single_ticket_details().
     * @Purpose  : Used to List tickets from prepaod_tickets table for one by one checkin and combi on case
     * @Called   : called when scan prepaid qrcode from CSS venue app.
     * @parameters :Prepaid same passes data passes data.
     * @Created  : Komal  <komalgarg.intersoft@gmail.com> on date 14 AUG 2018
     */
    function get_prepaid_single_ticket_details($postpaid_scan = 0, $prepaid_records = array(), $device_time_check = 0, $add_to_pass = 0, $pass_no = '', $allow_adjustment = 0, $selected_date_new = '', $from_time_new = '', $to_time_new = '', $slot_type = '') {
        global $MPOS_LOGS;
        try {
            $logs['request_' . date('H:i:s')] = array('add_to_pass' => $add_to_pass, 'pass_no' => $pass_no, 'allow_adjustment' => $allow_adjustment);
            // check pass scan from voucher 
            $is_voucher = ($prepaid_records[0]->channel_type == 5) ? 1 : 0;
            $is_ticket_listing = ($prepaid_records[0]->channel_type == 5) ? 0 : 1;

            // condition to get capacity according to ticket Id            
            $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate, own_capacity_id,shared_capacity_id,is_reservation', 'where' => 'mec_id = '.$prepaid_records[0]->ticket_id), 'row_array');
            
            if (!empty($prepaid_records)) {
                $ticket_listing = $tps_detail = array();
                $status = 1;
                $message = 'Valid';

                //To sort prepaid_records according to types
                $types_array = array();
                foreach ($prepaid_records as $record) {
                    $record->ticket_type_label = $record->ticket_type;
                    $types_array[] = (array) $record;
                }
                $prepaid_records = $this->common_model->sort_ticket_types($types_array);
                $prepaid_records = array_values($prepaid_records);
                // start reservation 
                if ($capacity['is_reservation'] == 1) {
                    // Start Condition to confirm OR Redeem pass According to Date and Time Condition
                    if (isset($selected_date_new) && $selected_date_new != '') {
                        $update_from_time = $from_time_new;
                        $update_to_time = $to_time_new;
                        $update_selected_date = $selected_date_new;
                    } else {
                        $update_from_time = $prepaid_records[0]['from_time'];
                        $update_to_time = $prepaid_records[0]['to_time'];
                        $update_selected_date = $prepaid_records[0]['selected_date'];
                    }
                    $from_date_time = strtotime($update_selected_date) . " " . $update_from_time;
                    $to_date_time = strtotime($update_selected_date) . " " . $update_to_time;
                    $arrival_time = $this->get_arrival_time_from_slot('', $from_date_time, $to_date_time, $slot_type, $device_time_check);
                }
                // end Reservation
                //Make tps detail array
                $total_count = array();
                foreach ($prepaid_records as $pass) {
                    $pass = (object) $pass;
                    $prepaid_pertype_records[$pass->tps_id] = $pass;
                    $total_count[$pass->ticket_id] += 1;
                    $total_used[$pass->ticket_id] = ($pass->used == "1") ? ($total_used[$pass->ticket_id] + 1) : $total_used[$pass->ticket_id];
                    $discount = 0;
                    $extra_discount = unserialize($pass->extra_discount);
                    $discount = $extra_discount['gross_discount_amount'];
                    if (($pass->channel_type == 10 || $pass->channel_type == 11 || (in_array($pass->is_invoice, $this->is_invoice))) && $pass->combi_discount_gross_amount > 0) {
                        $discount = $discount + $pass->combi_discount_gross_amount;
                    }
                    if ($pass->used != 1) {
                        $key = 'unused';
                    } else {
                        $key = 'used';
                    }
                    if ($postpaid_scan == 1) {
                        $price = (float) $pass->supplier_price;
                    } else {
                        $price = (float) $pass->price + $discount;
                    }
                    
                    $tps_detail[$pass->ticket_id][$key][] = array(
                        'prepaid_ticket_id' => array($pass->prepaid_ticket_id),
                        'child_pass_no' => isset($pass->passNo) ? $pass->passNo : "",
                        'tps_id' => $pass->tps_id,
                        'clustering_id' => (int) 0,
                        'count' => 1,
                        'pax' => isset($pass->pax) ? (int) $pass->pax : (int) 0,
                        'capacity' => isset($pass->capacity) ? (int) $pass->capacity : (int) 1,
                        'start_date' => isset($capacity['startDate']) ? (string) date('Y-m-d', $capacity['startDate']) : '',
                        'end_date' => isset($capacity['endDate']) ? (string) date('Y-m-d', $capacity['endDate']) : '',
                        'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10,
                        'ticket_type_label' => $pass->ticket_type,
                        'price' => $price,
                        'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99',
                        'per_age_group_extra_options' => array());
                }

                //make final array for ticket details

                foreach ($prepaid_pertype_records as $passes) {
                    
                    if(empty($slot_type) && isset($passes->timeslot)) {
                        $slot_type = $passes->timeslot;
                    }
                    else if(empty($slot_type) && empty($passes->timeslot)) {
                        $slot_type = '';
                    }
                    
                    $ticket_detail = array();
                    // Code begins to get the reservation time notification
                    $ticket_detail['hotel_name'] = !empty($passes->hotel_name) ? $passes->hotel_name : '';
                    $ticket_detail['title'] = $passes->title;
                    $ticket_detail['is_reservation'] = !empty($capacity['is_reservation']) ? (int) $capacity['is_reservation'] : (int) 0;
                    $ticket_detail['reservation_date'] = isset($update_selected_date) ? $update_selected_date : gmdate('Y-m-d');
                    $ticket_detail['arrival_status'] = isset($arrival_time['arrival_status']) ? $arrival_time['arrival_status'] : 0;
                    $ticket_detail['arrival_time'] = isset($arrival_time['arrival_time']) ? $arrival_time['arrival_time'] : "";
                    $ticket_detail['from_time'] = isset($update_from_time) ? $update_from_time : "";
                    $ticket_detail['to_time'] = isset($update_to_time) ? $update_to_time : "";
                    $ticket_detail['own_capacity_id'] = !empty($capacity['own_capacity_id']) ? (int) $capacity['own_capacity_id'] : (int) 0; // new paramter add 17 nov
                    $ticket_detail['shared_capacity_id'] = !empty($capacity['shared_capacity_id']) ? (int) $capacity['shared_capacity_id'] : (int) 0; // new paramter add 17 nov
                    $ticket_detail['timeslot'] = $slot_type;
                    $ticket_detail['visitor_group_no'] = (string) $passes->visitor_group_no;
                    $ticket_detail['ticket_id'] = (int) $passes->ticket_id;
                    $ticket_detail['total_count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['total_used'] = (!empty($total_used[$passes->ticket_id])) ? $total_used[$passes->ticket_id] : 0;
                    $ticket_detail['citysightseen_card'] = 0;
                    $ticket_detail['show_extend_button'] = 0;
                    $ticket_detail['upsell_ticket_ids'] = '';
                    $ticket_detail['per_ticket_extra_options'] = array();
                    $ticket_detail['pass_no'] = isset($pass_no) ? $pass_no : '';
                    $ticket_detail['address'] = '';
                    $ticket_detail['cashier_name'] = $passes->cashier_name;
                    $ticket_detail['pos_point_name'] = $passes->pos_point_name;
                    $ticket_detail['tax'] = (int) $passes->tax;
                    $ticket_detail['is_voucher'] = $is_voucher;
                    $ticket_detail['is_scan_countdown'] = 0;
                    $ticket_detail['countdown_interval'] = '';

                    if ($prepaid_records[0]->channel_type != 5) {
                        $ticket_detail['types'] = array();
                        $ticket_detail['used_details'] = (!empty($tps_detail[$passes->ticket_id]['used'])) ? $tps_detail[$passes->ticket_id]['used'] : array();
                        $ticket_detail['unused_details'] = (!empty($tps_detail[$passes->ticket_id]['unused'])) ? $tps_detail[$passes->ticket_id]['unused'] : array();
                    } else {
                        $ticket_detail['types_count'] = $tps_detail[$passes->ticket_id];
                    }
                    if ($ticket_detail['total_used'] == $ticket_detail['total_count']) {
                        $status = 0;
                        $message = 'Pass not valid';
                        //prepaid_tickets response
                        $pt_response = array();
                        $pt_response['status'] = $status;
                        $pt_response['data'] = array();
                        $pt_response['message'] = $message;
                        $pt_response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
                        $pt_response['is_ticket_listing'] = 1;
                        $pt_response['show_group_checkin_listing'] = 2;
                        $pt_response['checkin_action'] = 2;
                        return $pt_response;
                    }

                    if (!empty($ticket_detail)) {
                        //replace null with blank
                        array_walk_recursive($arrReturn, function (&$item, $key) {
                            $item = (empty($item) && is_array($item)) ? array() : $item;
                            if ($key == 'price') {
                                $item = $item ? (float) $item : $item;
                            }
                            if ($key != 'visitor_group_no' && $key != 'price' && $key != 'prepaid_ticket_id' && $key != 'reservation_date') {
                                $item = is_numeric($item) ? (int) $item : $item;
                            }
                            if ($key == 'upsell_ticket_ids') {
                                $item = $item ? (string) $item : $item;
                            }
                            if ($key == 'prepaid_ticket_id') {
                                $item = ($item) ? (string) $item : $item;
                            }
                            $item = null === $item ? '' : $item;
                        });
                    }
                    $ticket_listing[$passes->ticket_id] = $ticket_detail;
                }
                sort($ticket_listing);
                //FINAL Ticket Listing response
                $extra_booking_info = json_decode(stripslashes($prepaid_records[0]['extra_booking_information']), true);
                $response = array();
                $response['status'] = !empty($ticket_listing) ? $status : 0;
                $response['data'] = $ticket_listing;
                $response['is_ticket_listing'] = $is_ticket_listing;
                $response['add_to_pass'] = (int) $add_to_pass;
                $response['is_editable'] = ($add_to_pass == "0") ? 1 : 0;
                $response['booking_note'] = $extra_booking_info['extra_note'];
                if ($prepaid_records[0]->channel_type != 5) {
                    $response['is_early_reservation_entry'] = 0;
                    $response['allow_adjustment'] = $allow_adjustment;
                    $response['show_group_checkin_listing'] = 2;
                    $response['checkin_action'] = 2;
                    $response['show_cluster_extend_option'] = 0;
                    $response['cluster_main_ticket_details'] = (object) array();
                }
                $response['is_prepaid'] = 1;
                $response['message'] = $response['status'] == 0 ? 'Pass not valid' : $message;
                $MPOS_LOGS['get_prepaid_single_ticket_details'] = $logs;
                return $response;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['get_prepaid_single_ticket_details'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : get_prepaid_group_ticket_details().
     * @Purpose  : Used to List tickets from prepaod_tickets table for group checkin and combi on case
     * @Called   : called when scan prepaid qrcode from CSS venue app.
     * @parameters :Prepaid same passes data passes data.
     * @Created  : Komal  <komalgarg.intersoft@gmail.com> on date 09 AUG 2018
     */
    function get_prepaid_group_ticket_details($postpaid_scan = 0, $prepaid_records = array(), $device_time = 0, $add_to_pass = 0, $pass_no = '', $allow_adjustment = 0, $is_one_by_one_entry_allowed = 0) {
        global $MPOS_LOGS;
        try{
            $logs['data_' . date('H:i:s')] = array('add_to_pass' => $add_to_pass, 'pass_no' => $pass_no, 'allow_adjustment' => $allow_adjustment);
            if (!empty($prepaid_records)) {
                $ticket_listing = array();
                $status = 1;
                $message = 'Valid';

                $tps_detail = array();
                $total_count = array();
                //To sort prepaid_records according to types
                $types_array = array();
                foreach ($prepaid_records as $record) {
                    $record->ticket_type_label = $record->ticket_type;
                    $types_array[] = (array) $record;
                }
                $prepaid_records = $this->common_model->sort_ticket_types($types_array);
                $prepaid_records = array_values($prepaid_records);
                //Make tps array
                foreach ($prepaid_records as $pass) {
                    $ticket_id = $pass['ticket_id'];
                    $pass = (object) $pass;
                    $prepaid_pertype_records[$pass->tps_id] = $pass;
                    $total_count[$pass->ticket_id] += 1;
                    $total_used[$pass->ticket_id] = ($pass->used == "1") ? ($total_used[$pass->ticket_id] + 1) : $total_used[$pass->ticket_id];
                    if (empty($tps_detail[$pass->ticket_id][$pass->tps_id]['count'])) {
                        $tps_detail[$pass->ticket_id][$pass->tps_id]['count'] = 1;
                        if ($pass->used == "1") {
                            $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] = 1;
                        }
                    } else {
                        $tps_detail[$pass->ticket_id][$pass->tps_id]['count'] += 1;
                        if ($pass->used == "1") {
                            $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] += 1;
                        }
                    }
                    if ($pass->used != 1) {
                        $prepaid_ticket_ids[$pass->ticket_id][$pass->tps_id][] = (string) $pass->prepaid_ticket_id;
                    }
                    $discount = 0;
                    $extra_discount = unserialize($pass->extra_discount);
                    $discount = $extra_discount['gross_discount_amount'];

                    $ticket_dates = $this->find('modeventcontent', array('select' => 'startDate, endDate', 'where' => 'mec_id = ' . $ticket_id ), 'array');
                    $logs['mec_qyery_res_'] = $ticket_dates;
                    if (($pass->channel_type == 10 || $pass->channel_type == 11 || in_array($pass->is_invoice, $this->is_invoice)) && $pass->combi_discount_gross_amount > 0) {
                        $discount = $discount + $pass->combi_discount_gross_amount;
                    }
                    
                    if($postpaid_scan == 1){
                        $tps_detail[$pass->ticket_id][$pass->tps_id] = array(
                            'prepaid_ticket_id' => (!empty($prepaid_ticket_ids[$pass->ticket_id][$pass->tps_id])) ? $prepaid_ticket_ids[$pass->ticket_id][$pass->tps_id] : array(), 
                            'tps_id' => $pass->tps_id,
                            'pax' => isset($pass->pax) ? (int) $pass->pax : (int) 0,
                            'capacity' => isset($pass->capacity) ? (int) $pass->capacity : (int) 1,
                            'clustering_id' => !empty($pass->clustering_id) ? (int) $pass->clustering_id : (int) 0,
                            'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10,
                            'ticket_type_label' => $pass->ticket_type,
                            'price' => (float) $pass->supplier_price,
                            'start_date' => isset($ticket_dates[0]['startDate']) ? date('Y-m-d', $ticket_dates[0]['startDate']) : '',
                            'end_date' => isset($ticket_dates[0]['endDate']) ? date('Y-m-d', $ticket_dates[0]['endDate']) : '',
                            'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99',
                            'count' => $tps_detail[$pass->ticket_id][$pass->tps_id]['count'],
                            'used_count' => (!empty($tps_detail[$pass->ticket_id][$pass->tps_id]['used_count']) && $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] > 0) ? $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] : 0,
                            'per_age_group_extra_options' => array());
                    } else {
                        $tps_detail[$pass->ticket_id][$pass->tps_id] = array(
                            'prepaid_ticket_id' => (!empty($prepaid_ticket_ids[$pass->ticket_id][$pass->tps_id])) ? $prepaid_ticket_ids[$pass->ticket_id][$pass->tps_id] : array(),
                            'tps_id' => $pass->tps_id,
                            'pax' => isset($pass->pax) ? (int) $pass->pax : (int) 0, 
                            'capacity' => isset($pass->capacity) ? (int) $pass->capacity : (int) 1, 
                            'clustering_id' => !empty($pass->clustering_id) ? (int) $pass->clustering_id : (int) 0, 
                            'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10,
                            'ticket_type_label' => $pass->ticket_type,
                            'price' => (float) $pass->price + $discount,
                            'start_date' => isset($ticket_dates[0]['startDate']) ? date('Y-m-d', $ticket_dates[0]['startDate']) : '', 
                            'end_date' => isset($ticket_dates[0]['endDate']) ? date('Y-m-d', $ticket_dates[0]['endDate']) : '', 
                            'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99', 
                            'count' => $tps_detail[$pass->ticket_id][$pass->tps_id]['count'], 
                            'used_count' => (!empty($tps_detail[$pass->ticket_id][$pass->tps_id]['used_count']) && $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] > 0) ? $tps_detail[$pass->ticket_id][$pass->tps_id]['used_count'] : 0, 
                            'per_age_group_extra_options' => array()
                            );
                    }
                }
                $types = array();
                foreach ($tps_detail[$pass->ticket_id] as $data) {
                    $types[] = $data;
                }

                foreach ($prepaid_pertype_records as $passes) {
                    if($device_time != '' && $device_time != 0) {
                        $current_time = strtotime(date("H:i", strtotime($device_time)));
                        $current_day = strtotime(date("Y-m-d", strtotime($device_time)));
                    } else {
                        $current_time = strtotime(gmdate("H:i", time() + ($passes->timezone * 3600)));
                        $current_day = strtotime(date("Y-m-d"));
                    }
                    //Code begins to get the reservation time notification
                    $from_date_time = $passes->selected_date . " " . $passes->from_time;
                    $to_date_time = $passes->selected_date . " " . $passes->to_time;
                    $arrival_time = $this->get_arrival_time_from_slot('', $from_date_time, $to_date_time, $passes->timeslot, $current_time);
                    $logs['current_time__current_day__from_date_time__to_date_time__passes->timeslot__arrival_time'] = array($current_time, $current_day, $from_date_time, $to_date_time, $passes->timeslot, $arrival_time);
                    // new condition 17 nov 
                    // condition to get capacity according to ticket Id
                    $capacity = $this->find('modeventcontent', array('select' => 'own_capacity_id,shared_capacity_id,is_reservation', 'where' => 'mec_id = ' . $passes->ticket_id ), 'array');
                    $ticket_detail = array();
                    $ticket_detail['hotel_name'] = !empty($passes->hotel_name) ? $passes->hotel_name : '';
                    $ticket_detail['title'] = $passes->title;
                    $ticket_detail['arrival_status'] = ($passes->selected_date != '' && $passes->selected_date != 0  && isset($arrival_time['arrival_status'])) ? $arrival_time['arrival_status'] : 0;
                    $ticket_detail['arrival_time'] = ($passes->selected_date != '' && $passes->selected_date != 0 && isset($arrival_time['arrival_msg'])) ? $arrival_time['arrival_msg'] : '';
                    $ticket_detail['from_time'] = isset($passes->from_time) ? $passes->from_time : '';
                    $ticket_detail['to_time'] = isset($passes->to_time) ? $passes->to_time : "";
                    $ticket_detail['reservation_date'] = isset($passes->selected_date) ? $passes->selected_date : "";
                    $ticket_detail['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
                    $ticket_detail['own_capacity_id'] = !empty($capacity[0]['own_capacity_id']) ? (int) $capacity[0]['own_capacity_id'] : (int) 0; // new condition 17 nov 
                    $ticket_detail['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0; // new condition 17 nov 
                    $ticket_detail['timeslot'] = isset($passes->timeslot) ? (string) $passes->timeslot : "";
                    $ticket_detail['slot_type'] = isset($passes->timeslot) ? (string) $passes->timeslot : ''; // new condition 17 nov  
                    $ticket_detail['visitor_group_no'] = (string) $passes->visitor_group_no;
                    $ticket_detail['ticket_id'] = (int) $passes->ticket_id;
                    $ticket_detail['add_to_pass'] = (int) $add_to_pass;
                    $ticket_detail['total_count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['total_used'] = (!empty($total_used[$passes->ticket_id])) ? $total_used[$passes->ticket_id] : 0;
                    $ticket_detail['citysightseen_card'] = 0;
                    $ticket_detail['show_extend_button'] = 0;
                    $ticket_detail['upsell_ticket_ids'] = '';
                    $ticket_detail['per_ticket_extra_options'] = array();
                    $ticket_detail['pass_no'] = isset($pass_no) ? $pass_no : "";
                    $ticket_detail['address'] = '';
                    $ticket_detail['cashier_name'] = $passes->cashier_name;
                    $ticket_detail['pos_point_name'] = $passes->pos_point_name;
                    $ticket_detail['tax'] = (int) $passes->tax;
                    $ticket_detail['countdown_interval'] = '';
                    $ticket_detail['is_redeem'] = 0;
                    $ticket_detail['is_scan_countdown'] = 0;

                    $ticket_detail['types'] = $types;
                    if ($ticket_detail['total_used'] == $ticket_detail['total_count']) {
                        $status = 0;
                        $message = 'Pass not valid';
                        //prepaid_tickets response
                        $pt_response = array();
                        $pt_response['status'] = $status;
                        $pt_response['data'] = array();
                        $pt_response['message'] = $message;
                        $pt_response['is_ticket_listing'] = 1;
                        $pt_response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
                        $pt_response['show_group_checkin_listing'] = 1;
                        return $pt_response;
                    }
                    if (!empty($ticket_detail)) {
                        //replace null with blank
                        array_walk_recursive($arrReturn, function (&$item, $key) {
                            $item = (empty($item) && is_array($item)) ? array() : $item;
                            if ($key == 'price') {
                                $item = $item ? (float) $item : $item;
                            }
                            if ($key != 'visitor_group_no' && $key != 'price' && $key != 'prepaid_ticket_id' && $key != 'reservation_date') {
                                $item = is_numeric($item) ? (int) $item : $item;
                            }
                            if ($key == 'upsell_ticket_ids') {
                                $item = $item ? (string) $item : $item;
                            }
                            if ($key == 'prepaid_ticket_id') {
                                $item = ($item) ? (string) $item : $item;
                            }
                            $item = null === $item ? '' : $item;
                        });
                    }
                    $ticket_listing[$passes->ticket_id] = $ticket_detail;
                }
                sort($ticket_listing);
                //FINAL Ticket Listing response
                $extra_booking_info = json_decode(stripslashes($prepaid_records[0]['extra_booking_information']), true);
                $response = array();
                $response['status'] = !empty($ticket_listing) ? $status : 0;
                $response['data'] = $ticket_listing;
                $response['is_ticket_listing'] = 1;
                $response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
                $response['is_editable'] = ($add_to_pass == "0") ? 1 : 0;
                $response['allow_adjustment'] = $allow_adjustment;
                $response['is_early_reservation_entry'] = 0;
                $response['is_one_by_one_entry_allowed'] = $is_one_by_one_entry_allowed;
                $response['show_group_checkin_listing'] = 1;
                $response['checkin_action'] = 1;
                $response['show_cluster_extend_option'] = 0;
                $response['cluster_main_ticket_details'] = (object) array();
                $response['booking_note'] = $extra_booking_info['extra_note'];
                $response['is_prepaid'] = 1;
                $response['message'] = $response['status'] == 0 ? 'Pass not valid' : $message;
                $MPOS_LOGS['get_prepaid_group_ticket_details'] = $logs;
                return $response;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['get_prepaid_group_ticket_details'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : redeem_group_tickets().
     * @Purpose  : redeem quantity selected by user for group checkin and one by one checking when add to pass is off
     * @Called   : called when click on confirm button from group checkin and one by one checkin screen
     * @Created  : Komal  <komalgarg.intersoft@gmail.com> on date 11 AUG 2018
     */
    function redeem_group_tickets($data) {
        global $spcl_ref;
        global $MPOS_LOGS;
        try {
            $logs_data = $data;
            unset($logs_data['user_detail']);
            $museum_id = $data['museum_id'];
            $museum_info = $data['user_detail'];
            $dist_id = $data['dist_id'];
            $dist_cashier_id = $data['dist_cashier_id'];
            $cashier_type = $data['cashier_type'];
            $pos_point_name = $data['pos_point_name'];
            $pos_point_id = $data['pos_point_id'];
            $cashier_id = $data['cashier_id'];
            $cashier_name = $data['cashier_name'];
            $prepaid_ticket_ids = $data['prepaid_ticket_ids'];
            $visitor_group_no = $data['visitor_group_no'];
            $is_group_check_in = $data['is_group_check_in'];
            $pre_pass_no = isset($data['pass_no']) ? $data['pass_no'] : "";
            $is_edited = $data['is_edited'];
            $from_time_new = $data['from_time'];
            $shift_id = ($data['shift_id'] > 0) ? $data['shift_id'] : 0;
            $to_time_new = $data['to_time'];
            $timeslot_new = $data['slot_type'];
            $selected_date_new = $data['selected_date'];
            $device_time = $data['device_time'];
            $ticket_id = $data['ticket_id'];
            $is_reservation = $data['is_reservation'];
            $update_tickets = $visitor_ticket_ids = $insertion_db2 = array();
            if ($is_group_check_in == 1) {
                $action_performed = 'CSS_GCKN';
            } else if ($is_group_check_in == 2) {
                $action_performed = 'SCAN_CSS';
            } else if ($is_group_check_in == 3) {
                $action_performed = 'MPOS_PST_OCKN';
            } else if ($is_group_check_in == 4) {
                $action_performed = 'MPOS_PST_GCKN';
            }
            $museum_info = $data['user_detail'];
            if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') {
                $cashier_id = $museum_info->reseller_cashier_id;
                $cashier_name = $museum_info->reseller_cashier_name;
            }
            $update_redeem_table = $update_capacity = array();
            $total_price_update = $update_from_time = $update_to_time = $update_selected_date = $count = 0;
            $check_activated = $is_redeem = 1;
            $clustring_ids = '';
            //Get pass details from PT
            $all_prepaid_data = $this->find('prepaid_tickets', array('select' => 'reseller_id, supplier_price, capacity, prepaid_ticket_id,cashier_id, pass_type, cashier_name, tax_name, museum_id,museum_name,activation_method,hotel_id,pos_point_id,pos_point_name,created_date_time,visitor_group_no,museum_cashier_id,visitor_tickets_id, clustering_id,is_addon_ticket,hotel_ticket_overview_id,hotel_name, extra_discount, combi_discount_gross_amount, channel_type, is_combi_ticket,ticket_id, tps_id, title, ticket_type, age_group, price, hotel_name, pos_point_name, tax,selected_date, from_time, to_time, timeslot, passNo,second_party_passNo,second_party_type,third_party_type, channel_type, booking_status, shift_id, related_product_id, is_invoice', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and used = "0" and is_refunded != "1" and activated = "1" '));
            $logs['find_pt_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $first_time_redeem = 1;
            $add_ticket_check = '';
            foreach ($all_prepaid_data as $row) {
                if($row['used'] == "1") {
                    $first_time_redeem = 0;
                }
                $update_capacity[$row['tps_id']]['count'] = isset($update_capacity[$row['tps_id']]['count']) ? $update_capacity[$row['tps_id']]['count'] + 1 : 1;
                $update_capacity[$row['tps_id']]['capacity'] = $row['capacity'];
                $count++;
                if (in_array($row['prepaid_ticket_id'], $prepaid_ticket_ids)) {
                    $get_prepaid_data[] = $row;
                    $vt_ticket_ids[] = $row['visitor_tickets_id'];
                    $total_price_update += $row['price'];
                    if($row['clustering_id'] != "0" && $row['clustering_id'] != "") {
                        $clustring_ids_array[] = $row['clustering_id'];
                    }
                }
                if(strpos($row['action_performed'], 'UPSELL_INSERT') !== false) {
                    $check_activated = 0; //don't check activated for pt n ticket_status for VT to be 1, 
                    /*reason : we allow scan for refunded enteries as well (on basis of activated 1),
                     but upsell - 24hr has activated 0, but it is also redemmed with upsell - 48 redemption, 
                     so for upsell tickets, activated check is ignored
                     */
                }
            }
            if(!empty(array_unique($clustring_ids_array))) {
                $clustring_ids = implode("','", array_unique($clustring_ids_array));
                $clustring_ids = "'".$clustring_ids."'";   
            }
            $redeem_same_supplier_cluster_tickets = 0;
            //check cluster settings (for DPOS orders) on redeem
            $company_settings = $this->find('qr_codes', array('select' => 'mpos_settings', 'where' => 'cod_id  = "' . $get_prepaid_data[0]['museum_id'] . '"'));
            $mpos_settings = json_decode(stripslashes($company_settings[0]['mpos_settings']), TRUE);
            if ($mpos_settings['redeem_cluster_tickets_for_supplier'] == 1) {
                $redeem_same_supplier_cluster_tickets = 1;
            }
            $types_array = array();
            if (!empty($get_prepaid_data)) {
                foreach ($get_prepaid_data as $record) {
                    $record['ticket_type_label'] = $record['ticket_type'];
                    $types_array[] = $record;
                    $channel_type = $record['channel_type'];
                }
                $get_prepaid_data = $this->common_model->sort_ticket_types($types_array);
                $get_prepaid_data = array_values($get_prepaid_data);
                //Prepare types count array
                foreach ($get_prepaid_data as $pre_data) {
                    $result = (object) $pre_data;
                    $discount = 0;
                    $extra_discount = unserialize($pre_data['extra_discount']);
                    $discount = $extra_discount['gross_discount_amount'];
                    if (($pre_data['channel_type'] == 10 || $pre_data['channel_type'] == 11 || in_array($pre_data['is_invoice'], $this->is_invoice)) && $pre_data['combi_discount_gross_amount'] > 0) {
                        $discount = $discount + $pre_data['combi_discount_gross_amount'];
                    }
                    $is_editable = 1;
                    $update_tickets[$pre_data['ticket_id']] = $pre_data['ticket_id'];
                    if($is_group_check_in == 3 || $is_group_check_in == 4){
                    $types_count[$pre_data['tps_id']] = array(
                        'clustering_id' => 0,
                        'tps_id' => (int) $pre_data['tps_id'],
                        'ticket_type' => (!empty($this->types[strtolower($pre_data['ticket_type'])]) && ($this->types[strtolower($pre_data['ticket_type'])] > 0)) ? $this->types[strtolower($pre_data['ticket_type'])] : 10,
                            'ticket_type_label' => $pre_data['ticket_type'],
                            'title' => $pre_data['title'],
                            'ticket_id' => (int) $pre_data['ticket_id'],
                            'price' => $pre_data['supplier_price'],
                            'tax' => (float) $pre_data['tax'],
                            'count' => $types_count[$pre_data['tps_id']]['count'] + 1,
                        );
                    } else {
                        $types_count[$pre_data['tps_id']] = array(
                            'clustering_id' => 0,
                            'tps_id' => (int) $pre_data['tps_id'],
                            'ticket_type' => (!empty($this->types[strtolower($pre_data['ticket_type'])]) && ($this->types[strtolower($pre_data['ticket_type'])] > 0)) ? $this->types[strtolower($pre_data['ticket_type'])] : 10,
                            'ticket_type_label' => $pre_data['ticket_type'],
                            'title' => $pre_data['title'],
                            'ticket_id' => (int) $pre_data['ticket_id'],
                            'price' => $pre_data['price'] + $discount,
                            'tax' => (float) $pre_data['tax'],
                            'count' => $types_count[$pre_data['tps_id']]['count'] + 1,
                        );
                    }
                    if ($pre_data['is_addon_ticket'] == "2") {
                        $visitor_ticket_ids[$pre_data['visitor_tickets_id']] = $pre_data['visitor_tickets_id'];
                        $add_ticket_check = 'and ticket_id = "'.$ticket_id.'"';
                    }
                }

                $types_count = array_values($types_count);
                $show_scanner_receipt = 0;
                $receipt_data = array();
                $updPass = false;
                $sub_ticket_supplier_scan = 0;
                if($result->is_addon_ticket == "2") {
                    $mainTicketDetails = $this->find('modeventcontent', array('select' => 'mec_id, allow_city_card, is_scan_countdown, countdown_interval, cod_id', 'where' => 'mec_id = "' . $result->related_product_id . '" '), 'row_object');
                }

                //print receipt in case of api orders and order is scanned for first time
                if (in_array($result->channel_type, $this->api_channel_types) && (!in_array($result->is_invoice, $this->is_invoice)) && $result->channel_type != "5" && 
                (($result->is_addon_ticket == "0" && in_array($result->reseller_id,  $this->css_resellers_array)) || 
                (!empty($mainTicketDetails) && $mainTicketDetails->is_scan_countdown == "1" && $mainTicketDetails->allow_city_card == "0" && in_array($result->reseller_id,  $this->liverpool_resellers_array)))) {
            
                    $cond = ' and is_addon_ticket = "0"';
                    if($result->is_addon_ticket == "2") {
                        $cond =' and ticket_id = "'.$result->ticket_id.'"';
                        $sub_ticket_supplier_scan = 1;
                    }
                    $pt_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'visitor_group_no = "' . $result->visitor_group_no . '" and activated = "1" and is_refunded != "1" and channel_type = "' . $result->channel_type . '" '.$cond.' '), 'array');
                    $extra_options_data = $this->find('prepaid_extra_options', array('select' => '*', 'where' => 'visitor_group_no = "' . $result->visitor_group_no . '" and is_cancelled = "0"'), 'array');
                     /* City Card off updation of passNo for prohibt duplicate user entry */
                    $checkUsed = array_sum(array_map('intval', array_column($pt_data, 'used')));
                    $logs['check_used_orders_'.date('H:i:s')] = $checkUsed;
                    if($checkUsed == 0 && $pt_data[0]['bleep_pass_no'] == "" ) {
                        $show_scanner_receipt = 1;
                        $getPtData = $this->getUpdatedPasses($result->visitor_group_no, $result->channel_type);
                        if(!empty($getPtData)) {
                            $updPass = true;
                        }
                    }
                     /* City Card off updation of passNo for prohibt duplicate user entry */
                     
                    foreach ($extra_options_data as $extra_options) {
                        if($extra_options['variation_type'] == 0){
                            if ($extra_options['ticket_price_schedule_id'] > 0) {
                                $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int) $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int) $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float) $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            } else {
                                $per_ticket_extra_options[$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int) $per_ticket_extra_options[$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int) $per_ticket_extra_options[$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float) $per_ticket_extra_options[$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            }
                        }
                    }
                    $total_price = $used = 0;
                    $first_time_redeem = 1;
                    foreach ($pt_data as $data) {
                        if ($data['used'] == 1) {
                            $show_scanner_receipt = $first_time_redeem = 0;
                        }
                        $total_price += $data['price'];
                        if($updPass && isset($getPtData[$data['prepaid_ticket_id']]) && !empty($getPtData[$data['prepaid_ticket_id']])) {
                            $passes[$data['tps_id']][] = $getPtData[$data['prepaid_ticket_id']];
                            $data['bleep_pass_no'] = $getPtData[$data['prepaid_ticket_id']];
                        }
                        else {
                            $passes[$data['tps_id']][] = $data['passNo'];
                        }
                        sort($per_age_group_extra_options[$data['tps_id']]);
                        if($is_group_check_in == 3 || $is_group_check_in == 4) {
                        $ticket_types_array[$data['tps_id']] = array(
                            'tps_id' => (int) $data['tps_id'],
                            'tax' => (float) $data['tax'],
                            'age_group' => $data['age_group'],
                            'type' => (!empty($this->types[strtolower($data['ticket_type'])]) && ($this->types[strtolower($data['ticket_type'])] > 0)) ? $this->types[strtolower($data['ticket_type'])] : 10,
                            'ticket_type_label' => $data['ticket_type'],
                            'quantity' => (int) $ticket_types_array[$data['tps_id']]['quantity'] + 1,
                            'price' => (float) $ticket_types_array[$data['tps_id']]['supplier_price'],
                            'net_price' => (float) $ticket_types_array[$data['tps_id']]['net_price'] + $data['net_price'],
                            'passes' => array_unique($passes[$data['tps_id']]),
                            'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data['tps_id']])) ? $per_age_group_extra_options[$data['tps_id']] : array()
                            );
                        } else {
                            $ticket_types_array[$data['tps_id']] = array(
                                'tps_id' => (int) $data['tps_id'],
                                'tax' => (float) $data['tax'],
                                'age_group' => $data['age_group'],
                                'type' => (!empty($this->types[strtolower($data['ticket_type'])]) && ($this->types[strtolower($data['ticket_type'])] > 0)) ? $this->types[strtolower($data['ticket_type'])] : 10,
                                'ticket_type_label' => $data['ticket_type'],
                                'quantity' => (int) $ticket_types_array[$data['tps_id']]['quantity'] + 1,
                            	'price' => (float) $ticket_types_array[$data['tps_id']]['price'] + $data['price'],
                            	'net_price' => (float) $ticket_types_array[$data['tps_id']]['net_price'] + $data['net_price'],
                            	'passes' => array_unique($passes[$data['tps_id']]),
                            	'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data['tps_id']])) ? $per_age_group_extra_options[$data['tps_id']] : array()
                            );
                        }
                        $pt_sync_daata[] = $data;
                    }
                    sort($ticket_types_array);
                    sort($per_ticket_extra_options);
                    $tickets_array = array(
                        'ticket_id' => (int) $result->ticket_id,
                        'title' => $result->title,
                        'is_reservation' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0' && $result->from_time != '' && $result->from_time != NULL && $result->from_time != '0' && $result->to_time != '' && $result->to_time != NULL && $result->to_time != '0') ? 1 : 0,
                        'selected_date' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->selected_date : '',
                        'from_time' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->from_time : '',
                        'to_time' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->to_time : '',
                        'slot_type' => ($result->selected_date != '' && $result->selected_date != NULL && $result->selected_date != '0') ? $result->timeslot : '',
                        'types' => $ticket_types_array,
                        'per_ticket_extra_options' => (!empty($per_ticket_extra_options)) ? $per_ticket_extra_options : array()
                    );
                    $receipt_data = array(
                        'supplier_name' => $result->museum_name,
                        'distributor_name' => $result->hotel_name,
                        'tax_name' => $result->tax_name,
                        //'distributor_address' => $dist_address,
                        'booking_date_time' => $result->created_date_time,
                        'payment_method' => (int) $result->activation_method,
                        'pass_type' => (int) $result->pass_type,
                        'is_combi_ticket' => (int) ($updPass)  ?  0 :   $result->is_combi_ticket,
                        'total_combi_discount' => 0,
                        'total_service_cost' => 0,
                        'total_amount' => (float) $total_price,
                        'sold_by' => $result->cashier_name,
                        'tickets' => $tickets_array
                    );
                }

                foreach ($get_prepaid_data as $pre_data) {
                    $channel_type = $pre_data['channel_type'];
                    $booking_status = $pre_data['booking_status'];
                    $prepaid_ticket_id = $pre_data['prepaid_ticket_id'];
                    $current_time = strtotime(gmdate("H:i", time() + ($passes->timezone * 3600)));
                    $current_day = strtotime(date("Y-m-d"));
                    $selected_date = $pre_data['selected_date'];
                    $from_time = $pre_data['from_time'];
                    $to_time = $pre_data['to_time'];
                    $slot_type = $pre_data['timeslot'];
                    // Code begins to get the reservation time notification
                    $from_date_time = $selected_date . " " . $from_time;
                    $to_date_time = $selected_date . " " . $to_time;
                    $arrival_time = $this->get_arrival_time_from_slot('', $from_date_time, $to_date_time, $slot_type, $current_time);
                    if ($is_reservation == 1) {
                        /*  Update reservation details in Firebase if venue_app active for the user */

                        if ($is_edited == 1 && SYNC_WITH_FIREBASE == 1) {
                            try {
                                $headers = $this->all_headers(array(
                                    'hotel_id' => $pre_data['hotel_id'],
                                    'museum_id' => $get_prepaid_data[0]['museum_id'],
                                    'ticket_id' => $ticket_id,
                                    'channel_type' => $channel_type,
                                    'is_invoice' => $pre_data['is_invoice'],
                                    'action' => 'redeem_group_tickets_from_MPOS',
                                    'user_id' => $pre_data['cashier_id']
                                ));

                                $selected_date = date('Y-m-d', strtotime($selected_date));
                                /* fetch details of order of previous date */
                                $previous_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $pre_data['created_date_time']);
                                $new_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $selected_date_new . '_' . $from_time_new . '_' . $to_time_new . '_' . $pre_data['created_date_time']);
                                $MPOS_LOGS['DB'][] = 'FIREBASE';
                                $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                                    'type' => 'POST',
                                    'additional_headers' => $headers,
                                    'body' => array("node" => 'distributor/bookings_list/' . $pre_data['hotel_id'] . '/' . $pre_data['cashier_id'] . '/' . date("Y-m-d", strtotime($pre_data['created_date_time'])) . '/' . $previous_key)
                                ));
                                $previous = json_decode($getdata);
                                $previous_data = $previous->data;
                                if (!empty($previous_data)) {
                                    $previous_data->from_time = $from_time_new;
                                    $previous_data->reservation_date = $selected_date_new;
                                    $previous_data->to_time = $to_time_new;
                                }
                                /* delete previous node */
                                $this->curl->requestASYNC('FIREBASE', '/delete_details', array(
                                    'type' => 'POST',
                                    'additional_headers' => $headers,
                                    'body' => array("node" => 'distributor/bookings_list/' . $pre_data['hotel_id'] . '/' . $pre_data['cashier_id'] . '/' . date("Y-m-d", strtotime($pre_data['created_date_time'])) . '/' . $previous_key)
                                ));
                                //update new node
                                $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                    'type' => 'POST',
                                    'additional_headers' => $headers,
                                    'body' => array("node" => 'distributor/bookings_list/' . $pre_data['hotel_id'] . '/' . $pre_data['cashier_id'] . '/' . gmdate("Y-m-d") . '/' . $new_key, 'details' => $previous_data)
                                ));
                            } catch (\Exception $e) {
                                $logs['exception'] = $e->getMessage();
                            }
                        }


                        $current_day = strtotime(date("Y-m-d"));
                        if (isset($from_time_new) && $from_time_new != '' && isset($to_time_new) && $to_time_new != '' && isset($selected_date_new) && $selected_date_new != '') {
                            $from_time_stamp = strtotime($from_time_new);
                            $to_time_stamp = strtotime($to_time_new);
                            $selected_date_time_stamp = strtotime($selected_date_new);
                            $update_from_time = $from_time_new;
                            $update_to_time = $to_time_new;
                            $update_selected_date = $selected_date_new;
                            $device_time_check = $device_time / 1000;
                        } else {
                            $from_time_stamp = strtotime($from_time);
                            $to_time_stamp = strtotime($to_time);
                            $selected_date_time_stamp = strtotime($selected_date);
                            $update_from_time = $from_time;
                            $update_to_time = $to_time;
                            $update_selected_date = $selected_date;
                            $device_time_check = $device_time / 1000;
                        }
                        if ($selected_date_time_stamp == $current_day) {
                            if ($from_time_stamp <= $device_time_check && $to_time_stamp >= $device_time_check) {
                                $is_redeem = 1;
                            } else if ($from_time_stamp >= $device_time_check) {
                                if (isset($is_edited) && $is_edited == 1) {
                                    $is_redeem = 0;
                                } else {
                                    $is_redeem = 1;
                                }
                            }
                        } else if ($selected_date_time_stamp > $current_day) {
                            if (isset($is_edited) && $is_edited == 1) {
                                $is_redeem = 0;
                            } else {
                                $is_redeem = 1;
                            }
                        }
                    }
                    if ($cashier_type == '2' ||  !empty($this->liverpool_resellers_array) && in_array($pre_data['reseller_id'], $this->liverpool_resellers_array)) {
                        $show_scanner_receipt = 0;
                    }
                    //Prepare final response
                    $final_data = array(
                        'is_combi_ticket' => (int) $pre_data['is_combi_ticket'],
                        'show_scanner_receipt' => ($is_redeem == 1) ? $show_scanner_receipt : 0,
                        'receipt_details' => ($show_scanner_receipt == 1 && $is_redeem == 1) ? $receipt_data : (object) array(),
                        'title' => $pre_data['title'],
                        'ticket_type' => (!empty($this->types[strtolower($pre_data['ticket_type'])]) && ($this->types[strtolower($pre_data['ticket_type'])] > 0)) ? $this->types[strtolower($pre_data['ticket_type'])] : 10,
                        'ticket_type_label' => $pre_data['ticket_type'],
                        'age_group' => $pre_data['age_group'],
                        'price' => (float) $pre_data['price'],
                        'visitor_group_no' => $visitor_group_no,
                        'pass_no' => $pre_data['passNo'],
                        'ticket_id' => (int) $pre_data['ticket_id'],
                        'tps_id' => (int) $pre_data['tps_id'],
                        'count' => $final_data['count'] + 1,
                        'total_amount' => ($final_data['count'] * $pre_data['price']),
                        'from_time' => $pre_data['from_time'],
                        'to_time' => $pre_data['to_time'],
                        'slot_type' => $pre_data['timeslot'],
                        'early_checkin_time' => ($pre_data['from_time'] != '' && $pre_data['from_time'] != 0 && isset($arrival_time) && $arrival_time['arrival_status'] == 1) ? $arrival_time['arrival_msg'] : '',
                        'hotel_name' => $pre_data['hotel_name'],
                        'address' => '',
                        'cashier_name' => $cashier_name,
                        'pos_point_name' => $pre_data['pos_point_name'],
                        'tax' => (float) $pre_data['tax'],
                        'is_prepaid' => 1,
                        'action' => 1,
                        'is_cash_pass' => ($pre_data['activation_method'] == "2") ? 1 : 0,
                        'is_pre_booked_ticket' => 0,
                        'extra_options_exists' => 0,
                        'types_count' => $types_count,
                        'show_extend_button' => 0,
                        'upsell_ticket_ids' => '',
                        'upsell_left_time' => '',
                        'is_scan_countdown' => 0,
                        'is_late' => ($arrival_time['arrival_status'] == 2) ? 1 : 0,
                        'countdown_interval' => '',
                        'show_group_checkin_button' => 0,
                        'already_used' => 0,
                        'is_redeem' => ($is_redeem != null) ? $is_redeem : 0,
                        'citysightseen_card' => 0,
                        'scanned_at' => gmdate('m/d/Y H:i:s'),
                    );
                }
                //If timeslot is updated that update it in DB, Redis and firebase
                if ($is_edited == 1) {
                    $logs['update_capacity_' . date('H:i:s')] = $update_capacity;
                    $capacity_update = 0;
                    foreach ($update_capacity as $tps_data) {
                        $capacity_update += $tps_data['count'] * $tps_data['capacity'];
                    }
                    //update capacity of prev timeslot
                    $this->update_prev_capacity($ticket_id, $selected_date, $from_time, $to_time, $capacity_update, $slot_type);
                    //update capacity of new timeslot
                    $this->update_capacity($ticket_id, $selected_date_new, $from_time_new, $to_time_new, $capacity_update, $timeslot_new);
                }

                $used = '1';
                $redeem_date_time = gmdate("Y-m-d H:i:s");
                $scanned_at = strtotime($redeem_date_time);
                if (isset($is_redeem)) {
                    $used = $is_redeem;
                    if ($is_redeem == 0) {
                        $scanned_at = '';
                    }
                }
                $update_db1_set = '';
                if (isset($pre_pass_no) && $pre_pass_no != ""){
                    $update_db1_set .= "passNo = '" . $pre_pass_no . "', ";
                    $update_db2['passNo'] = $pre_pass_no;
                }
                if(in_array($result->channel_type, $this->api_channel_types) && (!in_array($result->is_invoice, $this->is_invoice))) {
                    $update_db1_set .= "shift_id = '".$shift_id."',";
                    $update_db2['shift_id'] = $shift_id;
                }
               
                //Update query in case if timeslot is updated
                if ($is_edited == 1) {
                    $update_pt_reservation_query = 'update prepaid_tickets set from_time="' . $update_from_time . '",to_time="' . $update_to_time . '",selected_date="' . $update_selected_date . '", action_performed=CONCAT(action_performed, ", UPDATE_RSV_ON_SCAN") where visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and used = "0" and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : '');
                    $this->query($update_pt_reservation_query);
                    $logs['pt_reservation_update_query_' . date('H:i:s')] = $update_pt_reservation_query;
                    $insert_pt_db2_date_change['from_time'] = $update_from_time;
                    $insert_pt_db2_date_change['to_time'] = $update_to_time;
                    $insert_pt_db2_date_change['selected_date'] = $update_selected_date;
                    $insert_pt_db2_date_change['CONCAT_VALUE']['action_performed'] = ', UPDATE_RSV_ON_SCAN';
                    $where_db2_insert_date_change = '  visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : '');
                    $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db2_date_change, "where" => $where_db2_insert_date_change, "group_checkin" => 1);
                    if ($channel_type != 2 && $booking_status != 0) {
                        $insert_vt_date_change['from_time'] = $update_from_time;
                        $insert_vt_date_change['to_time'] = $update_to_time;
                        $insert_vt_date_change['selected_date'] = $update_selected_date;
                        $insert_vt_date_change['CONCAT_VALUE']['action_performed'] = ', UPDATE_RSV_ON_SCAN';
                        $where_vt_insert_date_change = '  vt_group_no = "' . $visitor_group_no . '" and ticketId = "' . $ticket_id . '" and used = "0" and is_refunded != "1"' . (($check_activated) ? ' and ticket_status = "1" ' : '');
                        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt_date_change, "where" => $where_vt_insert_date_change, "group_checkin" => 1);
                    }
                    $sub_transactions = 1;
                }
                //Update query in case if pass is redeemed
                if ($is_redeem != 0) {
                    $redeemed_clustering_ids = '';
					
                    $capacityType = $this->find('modeventcontent', array("select" => 'capacity_type', "where" => "mec_id = '{$ticket_id}'"), 'row_array');
                    $MPOS_LOGS['get_capacity_type_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                    $MPOS_LOGS['get_capacity_type_query_data_' . date('H:i:s')] = json_encode($capacityType);

                    /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
                    $batch_rule_id = 0;
                    $limitArray = array("passNo" => $pre_pass_no, "shared_capacity_id" => $data['shared_capacity_id'], 
                                        "cashier_id" => $cashier_id, 
                                        "cod_id" => 0, 
                                        "capacity_type" => $capacityType['capacity_type'], 
                                        "selected_date" => $selected_date_new, 
                                        "from_time" => $from_time_new, 
                                        "to_time" => $to_time_new, 
                                        "ticket_id" => $ticket_id);
                    $validate = $this->validate_cashier_limit($limitArray);
                    if(!empty($validate) && is_array($validate)) {

                        /* count PT records for redumption count in batch_rule table */
                        $redeemCount = $this->find('prepaid_tickets', array("select" => 'count(prepaid_ticket_id) AS passCount', "where" => 'visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ') '), 'row_array');
                        $MPOS_LOGS['get_count_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                        $countPtRecords = $redeemCount['passCount'];    

                        if($validate['status']=='batch_not_found') {
                            $response = array('status' => (int) 12, 'message' => 'Batch not found', 'is_prepaid' => 1, 'is_editable' => $is_editable, 'data' => $final_data, 'is_early_reservation_entry' => 0);
                            $MPOS_LOGS['redeem_group_tickets_' . date('H:i:s')] = $logs;
                            return $response;
                        }
                        if($validate['status']=='qty_expired') {
                            $response = array('status' => (int) 12, 'message' => 'Batch capacity is full', 'is_prepaid' => 1, 'is_editable' => $is_editable, 'data' => $final_data, 'is_early_reservation_entry' => 0);
                            $MPOS_LOGS['redeem_group_tickets_' . date('H:i:s')] = $logs;
                            return $response;
                        }
                        if($validate['status']=='redeem') {
                            $batch_rule_id  = $validate['batch_rule_id'];
                            $last_scan = $validate['last_scan'];
                            $maximum_quantity  = $validate['maximum_quantity'];
                            $quantity_redeemed  = $validate['quantity_redeemed'];
							$rules_data = $validate['rules_data'];
                            $left_qunatity = ($maximum_quantity-$quantity_redeemed);
                            if($left_qunatity < $countPtRecords) {
                                $response = array('status' => (int) 12, 'message' => 'Batch capacity is full', 'is_prepaid' => 1, 'is_editable' => $is_editable, 'data' => $final_data, 'is_early_reservation_entry' => 0);
                                $MPOS_LOGS['redeem_group_tickets_' . date('H:i:s')] = $logs;
                                return $response;
                            }
							
							$notifyQuantity = ($countPtRecords+$quantity_redeemed);
							$this->emailNotification($rules_data, $notifyQuantity);
                        }
                         /* UPDATE redumption of cashier in batch_rule table */
                        if(!empty($batch_rule_id) && $countPtRecords>0) {
                            $strUpd = '';
                            if(strtotime($last_scan) < strtotime(date('Y-m-d'))) {
                                $strUpd.= "quantity_redeemed = 0, ";
                            }
                            $strUpd.= "quantity_redeemed = (quantity_redeemed+{$countPtRecords}), last_scan = '".date('Y-m-d')."'";

                            $this->query("UPDATE batch_rules SET {$strUpd} WHERE batch_rule_id = {$batch_rule_id}");
                            $logs['update_batch_rules_'.date('H:i:s')] = $this->db->last_query();
                        }
                    }
                    if(isset($validate['batch_id']) && $validate['batch_id'] != 0 && $validate['batch_id'] != NULL) {
                        $batch_id_db1 = ", batch_id = ".$validate['batch_id'];
                        $update_db2['batch_id'] = $validate['batch_id'];
                    } else {
                        $batch_id_db1 = "";
                    }
                    if ($updPass  && ($get_prepaid_data[0]['is_addon_ticket'] == "2" &&  !empty($mainTicketDetails) && $mainTicketDetails->is_scan_countdown == "1" && $mainTicketDetails->allow_city_card == "0")  && $result->payment_conditions == '' && $is_redeem == 1) {
                        $countdown_values = explode('-', $mainTicketDetails->countdown_interval);                   
                        $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                        $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                        $update_prepaid_query = 'update prepaid_tickets set payment_conditions = (case when (payment_conditions = "" || payment_conditions is NULL) then "' . $valid_till . '" else payment_conditions end ) where visitor_group_no = "' . $visitor_group_no . '" and (passNo = "' . $get_prepaid_data[0]['pass_no'] . '"  or bleep_pass_no = "' . $get_prepaid_data[0]['pass_no'] . '") and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : '');
                        $this->query($update_prepaid_query);
                        $logs['payment_conditions_query_'.date('H:i:s')] = $update_prepaid_query;
                        $insert_pt_payment['payment_conditions'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'payment_conditions','value' => ''), array( 'key' => 'payment_conditions','value' => 'NULL')
                        ) ,'update' => $valid_till));
                        
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_payment, "where" => ' visitor_group_no = "' . $visitor_group_no . '" and (passNo = "' . $get_prepaid_data[0]['pass_no'] . '"  or bleep_pass_no = "' . $get_prepaid_data[0]['pass_no'] . '") and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : ''), "redeem" => ($is_redeem=='1'? 1: 0));
                        $sub_transactions = 1;
                    }
                    $update_db2['used'] = isset($used) && $used == '1' ? "1" : "0";
                    $update_db2['voucher_updated_by'] = $update_db2['museum_cashier_id'] = $cashier_id;
                    $update_db2['booking_status'] = '1';
                    $update_db2['voucher_updated_by_name'] = $update_db2['museum_cashier_name'] = $cashier_name;
                    $update_db2['scanned_at'] = $scanned_at;
                    $update_db2['redeem_date_time'] = $redeem_date_time;
                    $update_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                    $update_db2['redeem_method'] = 'Voucher';
                    $update_db2['updated_at'] = gmdate("Y-m-d H:i:s");
                    $update_db2['pos_point_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'pos_point_id_on_redeem','value' => ''), array( 'key' => 'pos_point_id_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_id_on_redeem','value' => '0')
                    ) ,'update' => $pos_point_id));
                    $update_db2['pos_point_name_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'pos_point_name_on_redeem','value' => ''), array( 'key' => 'pos_point_name_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_name_on_redeem','value' => '0')
                    ) ,'update' => $pos_point_name));
                    $update_db2['distributor_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'distributor_id_on_redeem','value' => ''), array( 'key' => 'distributor_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_id_on_redeem','value' => '0')
                    ) ,'update' => $dist_id));
                    $update_db2['distributor_cashier_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                        array( 'key' => 'distributor_cashier_id_on_redeem','value' => ''), array( 'key' => 'distributor_cashier_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_cashier_id_on_redeem','value' => '0')
                    ) ,'update' => $dist_cashier_id));
                    $update_pt_db1 = $update_db1_set . ' used = "' . $used . '", museum_cashier_id="' . $cashier_id . '", booking_status = "1", museum_cashier_name="' . $cashier_name . '", scanned_at="' . $scanned_at . '", redeem_date_time = "' . $redeem_date_time . '", action_performed=CONCAT(action_performed, ", ' . $action_performed . '") ';
                    
                    if ($redeem_same_supplier_cluster_tickets == 1 && $get_prepaid_data[0]['clustering_id'] != '' && $get_prepaid_data[0]['clustering_id'] != NULL && $get_prepaid_data[0]['clustering_id'] != "0") {
                        $where = ' visitor_group_no = "' . $visitor_group_no . '" and museum_id = "' . $get_prepaid_data[0]['museum_id'] . '" and used = "0" and clustering_id IN (' . $clustring_ids . ') and is_refunded != "1"';
                        $redeemed_clustering_ids = $clustring_ids;
                    } else {
                        $where = ' visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')  and is_refunded != "1" '.$add_ticket_check;
                    }
                    if($check_activated) {
                        $where .= '  and activated= "1" ';
                    }
                    if ($get_prepaid_data[0]['booking_status'] == 0 && $get_prepaid_data[0]['channel_type'] == 2) {
                        $order_confirm_date = gmdate('Y-m-d H:i:s');
                        $update_db2['order_confirm_date'] = gmdate('Y-m-d H:i:s');
                    }
                    $this->query('update prepaid_tickets set ' .$update_pt_db1 .$batch_id_db1. ' where ' .$where);
                    $logs['update_pt_query_'.date('H:i:s')] =  $this->db->last_query();
                    
                    $redeemChk = ((isset($update_db2['used']) && $update_db2['used']=='1')? 1: 0);
                    $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $update_db2, "where" => $where, "redeem" => $redeemChk);
                    if (!($channel_type == 2 && $booking_status == 0) && ($get_prepaid_data[0]['second_party_type'] == 5 || $get_prepaid_data[0]['third_party_type'] == 5)) {
                            $insert_pt_db2_for_tp['redemption_notified_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                                array( 'key' => 'redemption_notified_at','value' => ''), array( 'key' => 'redemption_notified_at','value' => 'NULL')
                            ) ,'update' => gmdate("Y-m-d H:i:s")));
                            $insert_pt_db2_for_tp['authcode'] = array('case' => array('separator' => '||', 'conditions' => array(
                                array( 'key' => 'authcode','value' => ''), array( 'key' => 'authcode','value' => 'NULL')
                            ) ,'update' => gmdate("Y-m-d H:i:s")));
                            $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db2_for_tp, "where" => ' visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ') ');
                            $sub_transactions = 1;
                    }
                    $update_expedia_query = 'update expedia_prepaid_tickets set used = "' . $used . '", scanned_at="' . $scanned_at . '", action_performed=CONCAT(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ') ';
                    if (!empty($update_expedia_query)) {
                        $this->query($update_expedia_query);
                        $logs['expedia_query_' . date('H:i:s')] = $update_expedia_query;
                    }
                    //update redeem tickets table
                    $update_redeem_table['visitor_group_no'] = $visitor_group_no;
                    if($redeemed_clustering_ids != '') {
                        $update_redeem_table['clustering_id_in'] = $redeemed_clustering_ids;
                        $update_redeem_table['museum_id'] = $get_prepaid_data[0]['museum_id'];
                    } else {
                        $update_redeem_table['prepaid_ticket_ids'] = !empty($prepaid_ticket_ids) ? $prepaid_ticket_ids : array();
                    }
                    $update_redeem_table['museum_cashier_id'] = $cashier_id;
                    $update_redeem_table['shift_id'] = $shift_id;
                    $update_redeem_table['redeem_date_time'] = $redeem_date_time;
                    $update_redeem_table['museum_cashier_name'] = $cashier_name;
                    $update_redeem_table['hotel_id'] = $pre_data['hotel_id'];					                 
                }
                
                /*                 * ***** Notify Third party system ***** */
                if ($used == 1 && $first_time_redeem == "1" && ($get_prepaid_data[0]['second_party_type'] == 5 || $get_prepaid_data[0]['third_party_type'] == 5)) {
                    $request_array = array();
                    $request_array['passNo'] = $get_prepaid_data[0]['passNo'];
                    $request_array['ticket_id'] = $get_prepaid_data[0]['ticket_id'];
                    $request_array['scan_api'] = 'scan from redeem group tickets fxn  - mpos app';
                    $request_array['date_time'] = gmdate("Y-m-d H:i:s");
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    $MPOS_LOGS['external_reference'] = 'redeem';
                    $logs['data_to_queue_THIRD_PARTY_REDUMPTION_ARN_' . date('H:i:s')] = $request_array;
                    // This Fn used to send notification with data on AWS panel. 
                    if (LOCAL_ENVIRONMENT != 'Local') {
                        $spcl_ref = 'notify_third_party';
                        $this->queue($aws_message, THIRD_PARTY_REDUMPTION, THIRD_PARTY_REDUMPTION_ARN);
                    }
                }
                /*                 * ***** Update Third party system ***** */

                //Queue to update DB1 and DB2 and redeem tickets table
                if (!empty($update_redeem_table) ||!empty($insertion_db2)) {
                    $request_array = array();
                    $request_array['db2_insertion'] = $insertion_db2;
                    $request_array['sub_transactions'] =isset($sub_transactions) ? $sub_transactions : 0;
                    if ($booking_status == '0' && $channel_type == '2' && $is_redeem != 0 && !empty($prepaid_ticket_id)) {
                        $request_array['confirm_order'] = array('reseller_id' => $result->reseller_id, 'price' => $total_price_update, 'dist_id' => $result->hotel_id, 'voucher_updated_by' => $cashier_id , 'voucher_updated_by_name' => $cashier_name , 'visitor_group_no' => $visitor_group_no, 'order_confirm_date' => $order_confirm_date, 'all_prepaid_ticket_ids' => implode(',', $prepaid_ticket_ids), 'action_performed' => $action_performed, 'scanned_at' => $scanned_at);
                        $request_array['action'] = "redeem_group_tickets";
                        $request_array['visitor_group_no'] = $visitor_group_no;
                    }
                    // db 2 vt request
                    if ($is_redeem != 0 && empty($request_array['confirm_order'])) {
                        if(date("Y-m-d", strtotime($get_prepaid_data[0]['created_date_time'])) < '2019-08-01') {
                            $clustring_ids = '';
                        }
                        $request_array['vt_db2'] = array(
                            'redeem_group_tickets' => 1, 
                            'visitor_group_no' => $visitor_group_no, 
                            'visitor_tickets_id' => $vt_ticket_ids, 
                            'updated_by_username' => $cashier_name, 
                            'updated_by_id' => $cashier_id, 
                            'museum_id' => $get_prepaid_data[0]['museum_id'],
                            'clustering_id' => $clustring_ids, 'used' => '1', 
                            'visit_date' => $scanned_at, 
                            'redeem_method' => 'Voucher', 
                            'voucher_updated_by' => $cashier_id,
                            'voucher_updated_by_name' => $cashier_name, 
                            'check_ticket_id' => ($redeem_same_supplier_cluster_tickets == 1 && $get_prepaid_data[0]['clustering_id'] != '' && $get_prepaid_data[0]['clustering_id'] != NULL && $get_prepaid_data[0]['clustering_id'] != "0") ? '0' : '1', 
                            'booking_status' => '1',
                            'ticket_id' => $ticket_id,
                            'action_performed' => $action_performed,
                            'shift_id' => (in_array($get_prepaid_data[0]['channel_type'], $this->api_channel_types) && !in_array($get_prepaid_data[0]['is_invoice'], $this->is_invoice)) ? $shift_id :  $get_prepaid_data[0]['shift_id'],
                            'updated_at' => gmdate("Y-m-d H:i:s")
                        );
                    }
                    $request_array['update_redeem_table'] = $update_redeem_table;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "redeem_group_tickets";
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $request_array['pass_no'] = $get_prepaid_data[0]['passNo'];
                    $request_array['channel_type'] = $channel_type;
                    $request_string = json_encode($request_array);
                    $logs['data_to_queue_SCANING_ACTION_URL_' . date('H:i:s')] = $request_array;
                    $MPOS_LOGS['external_reference'] = 'redeem';
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                    } else {
                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                    }
                }
                
                if($updPass && !empty($getPtData) && ($museum_info->cashier_type !='2' || ($museum_info->cashier_type =='2' && $sub_ticket_supplier_scan == 1)) && ($show_scanner_receipt=='1' || !empty($this->liverpool_resellers_array) && in_array($get_prepaid_data[0]['reseller_id'], $this->liverpool_resellers_array)) && $is_redeem==1) {
                    $this->executeUpdPassesQueries($getPtData);
                    $basic_data = array(
                        'pos_point_id' => $pos_point_id, 
                        'pos_point_name' => $pos_point_name,
                        'user_id' => $museum_info->uid,
                        'shift_id' => $shift_id,
                        'museum_id' => $museum_id,
                        'cashier_type' => $museum_info->cashier_type,
                        'reseller_id' => isset($museum_info->reseller_id) && $museum_info->reseller_id != '' ?  $museum_info->reseller_id : '',
                        'reseller_cashier_id' =>  isset($museum_info->reseller_cashier_id) && $museum_info->reseller_cashier_id != '' ?  $museum_info->reseller_cashier_id : ''
                    );
                    if(!empty($pt_sync_daata)) {
                        $this->sync_voucher_scans($pt_sync_daata, $basic_data);
                    } else {
                        $this->sync_voucher_scans($pt_data, $basic_data);
                    }
                }
                $response = array('status' => (int) 1, 'message' => 'Valid', 'is_prepaid' => 1, 'is_editable' => $is_editable, 'data' => $final_data, 'is_early_reservation_entry' => 0);
            } else {
                $response = array('status' => (int) 0, 'errorMessage' => 'Already redeemed', 'is_prepaid' => 1, 'is_editable' => $is_editable, 'data' => $final_data, 'is_early_reservation_entry' => 0);
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['redeem_group_tickets_' . date('H:i:s')] = $logs;
        return $response;
    }

    /**
     * @Name     : get_prepaid_linked_ticket_listing().
     * @Purpose  : Used to List tickets from prepaod_tickets table
     * @Called   : called when scan prepaid qrcode from CSS venue app.
     * @parameters :Prepaid same passes data passes data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 09 MARCH 2018
     */
    function get_prepaid_linked_ticket_listing($prepaid_records = array(), $add_to_pass = 0, $pass_no = '', $selected_date_new = '', $from_time_new = '', $to_time_new = '', $slot_type = '', $supplier_hotel_id = 0, $redeem_same_supplier_cluster_tickets = 0) {
        global $MPOS_LOGS;
        try {
            if (!empty($prepaid_records)) {
                $ticket_listing = array();
                $status = 1;
                $message = 'Valid';
                $show_cluster_extend_option = 0;
                $cluster_main_ticket_details = (object) array();

                $tps_detail = array();
                $ticket_ids = array();
                // Unset records in case of DPOS cluster orders acc. to condition that if order is scanned by supplier which is own supplier of that dist.
                // then show main ticket else show sub ticket
                if($redeem_same_supplier_cluster_tickets == "1") {
                    foreach ($prepaid_records as $key => $pt_tickets) {
                        if(($supplier_hotel_id > 0 && $supplier_hotel_id == $pt_tickets->hotel_id && $pt_tickets->is_addon_ticket == 2) || ($supplier_hotel_id != $pt_tickets->hotel_id && $pt_tickets->is_addon_ticket == 0)) {
                            unset($prepaid_records[$key]);
                        } 
                    }
                }
                foreach ($prepaid_records as $key => $pt_tickets) {
                    $ticket_ids[$pt_tickets->ticket_id] = $pt_tickets->ticket_id;
                    $is_voucher = ($pt_tickets->channel_type == 5) ? 1 : 0;
                }
                
                //Fetch details from modeventcontent table
                $ticket_status = $this->find('modeventcontent', array('select' => 'allow_city_card, startDate, endDate, mec_id, is_scan_countdown, countdown_interval, upsell_ticket_ids,own_capacity_id,shared_capacity_id,is_reservation', 'where' => 'mec_id in (' . implode(',', $ticket_ids) . ')'), 'object');
                $scan_countdown_tickets = array();
                if (!empty($ticket_status)) {
                    foreach ($ticket_status as $tickets) {
                        $scan_countdown_tickets[$tickets->mec_id]['is_scan_countdown'] = $tickets->is_scan_countdown;
                        $scan_countdown_tickets[$tickets->mec_id]['countdown_interval'] = $tickets->countdown_interval;
                        $scan_countdown_tickets[$tickets->mec_id]['own_capacity_id'] = $tickets->own_capacity_id;
                        $scan_countdown_tickets[$tickets->mec_id]['shared_capacity_id'] = $tickets->shared_capacity_id;
                        $scan_countdown_tickets[$tickets->mec_id]['is_reservation'] = $tickets->is_reservation;
                        $scan_countdown_tickets[$tickets->mec_id]['allow_city_card'] = $tickets->allow_city_card;
                        $scan_countdown_tickets[$tickets->mec_id]['start_date'] = isset($ticket_status->startDate) ? date('Y-m-d', $ticket_status->startDate) : "";
                        $scan_countdown_tickets[$tickets->mec_id]['end_date'] = isset($ticket_status->endDate) ? date('Y-m-d', $ticket_status->endDate) : "";
                        $upsell_ticket_ids[$tickets->mec_id] = $tickets->upsell_ticket_ids;
                    }
                }
                $logs['modeventcontent query'] = $this->primarydb->db->last_query();
                $logs['modeventcontent_ticket_details'] = $scan_countdown_tickets;

                foreach ($prepaid_records as $key => $pt_tickets) {
                    $prepaid_records[$key]->is_scan_countdown = $scan_countdown_tickets[$pt_tickets->ticket_id]['is_scan_countdown'];
                    $prepaid_records[$key]->countdown_interval = $scan_countdown_tickets[$pt_tickets->ticket_id]['countdown_interval'];
                    if ($pt_tickets->used == '1' && $scan_countdown_tickets[$pt_tickets->ticket_id]['is_scan_countdown'] == '0') {
                        unset($prepaid_records[$key]);
                    }
                    if ($add_to_pass == '1' && $pt_tickets->used == '1' && !empty($pt_tickets->bleep_pass_no) && $pt_tickets->passNo == $pass_no && $scan_countdown_tickets[$pt_tickets->ticket_id]['is_scan_countdown'] == '0') {
                        unset($prepaid_records[$key]);
                    }
                }
                $total_count = array();
                foreach ($prepaid_records as $pass) {
                    $prepaid_pertype_records[$pass->tps_id] = $pass;
                    $total_count[$pass->ticket_id] += 1;
                    if (!empty($pass->clustering_id) && $pass->is_combi_ticket == '0') {
                        $count = 1;
                    } else {
                        if (empty($tps_detail[$pass->ticket_id][$pass->tps_id]['count'])) {
                            $count = 1;
                        } else {
                            $count = $tps_detail[$pass->ticket_id][$pass->tps_id]['count'] + 1;
                        }
                    }
                    
                    $tps_detail[$pass->ticket_id][$pass->tps_id] = array(
                        'tps_id' => $pass->tps_id, 
                        'clustering_id' => !empty($pass->clustering_id) ? (int) $pass->clustering_id : (int) 0, 
                        'pax' => isset($pass->pax) ? (int) $pass->pax : (int) 0,
                        'capacity' => isset($pass->capacity) ? (int) $pass->capacity : (int) 1, 
                        'start_date' => isset($scan_countdown_tickets[$pass->ticket_id]['start_date']) ? (string) $scan_countdown_tickets[$pass->ticket_id]['start_date'] : '', 
                        'end_date' => isset($scan_countdown_tickets[$pass->ticket_id]['end_date']) ? (string) $scan_countdown_tickets[$pass->ticket_id]['end_date'] : '', 
                        'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10, 
                        'ticket_type_label' => $pass->ticket_type,
                        'price' => $pass->price,
                        'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99', 
                        'count' => $count);
                }
                $extra_booking_info = json_decode(stripslashes($prepaid_records[0]->extra_booking_information), true);
                // Prepare final response array to show listing on app
                foreach ($prepaid_pertype_records as $passes) {
                    $ticket_detail = array();
                    if ($scan_countdown_tickets[$passes->ticket_id]['is_reservation'] == 1) {
                        if (isset($selected_date_new) && $selected_date_new != '' && $ticket_detail['selected_date'] != '') {
                            $ticket_detail['from_time'] = $from_time_new;
                            $ticket_detail['to_time'] = $to_time_new;
                            $ticket_detail['selected_date'] = (string)$selected_date_new;
                            $ticket_detail['slot_type'] = $slot_type;
                        } else {
                            $ticket_detail['from_time'] = $passes->from_time;
                            $ticket_detail['to_time'] = $passes->to_time;
                            $ticket_detail['selected_date'] = $passes->selected_date;
                            $ticket_detail['slot_type'] = $passes->timeslot;
                        }
                        $ticket_detail['own_capacity_id'] = ($scan_countdown_tickets[$passes->ticket_id]['own_capacity_id'] > 0) ? (int) $scan_countdown_tickets[$passes->ticket_id]['own_capacity_id'] : (int) 0;
                        $ticket_detail['shared_capacity_id'] = ($scan_countdown_tickets[$passes->ticket_id]['shared_capacity_id'] > 0) ? (int) $scan_countdown_tickets[$passes->ticket_id]['shared_capacity_id'] : (int) 0;
                        $ticket_detail['is_reservation'] = ($scan_countdown_tickets[$passes->ticket_id]['is_reservation'] > 0) ? (int) $scan_countdown_tickets[$passes->ticket_id]['is_reservation'] : (int) 0;
                    }
                    $ticket_detail['is_voucher'] = $is_voucher;
                    $ticket_detail['is_cluster'] = 1;
                    $ticket_detail['hotel_name'] = !empty($passes->hotel_name) ? $passes->hotel_name : '';
                    $ticket_detail['title'] = $passes->title;
                    $ticket_detail['visitor_group_no'] = (string) $passes->visitor_group_no;
                    $ticket_detail['ticket_id'] = $passes->ticket_id;
                    $ticket_detail['add_to_pass'] = (int) $scan_countdown_tickets[$passes->ticket_id]['allow_city_card'];
                    $ticket_detail['total_count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['citysightseen_card'] = ($add_to_pass == '1' && empty($passes->bleep_pass_no)) ? 1 : 0;
                    $ticket_detail['show_extend_button'] = ($scan_countdown_tickets[$passes->ticket_id]['is_scan_countdown'] == '1' && !empty($upsell_ticket_ids[$passes->ticket_id])) ? 1 : 0;
                    $ticket_detail['upsell_ticket_ids'] = $upsell_ticket_ids[$passes->ticket_id];
                    $ticket_detail['pass_no'] = $pass_no;
                    $ticket_detail['address'] = '';
                    $ticket_detail['cashier_name'] = $passes->cashier_name;
                    $ticket_detail['pos_point_name'] = $passes->pos_point_name;
                    $ticket_detail['tax'] = $passes->tax;

                    if ($scan_countdown_tickets[$passes->ticket_id]['is_scan_countdown'] == '1') {
                        $countdown_time = explode('-', $scan_countdown_tickets[$passes->ticket_id]['countdown_interval']);
                        $countdown_period = $countdown_time[0];
                        $countdown_text = $countdown_time[1];
                        $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_period);
                        if (empty($passes->scanned_at)) {
                            $passes->scanned_at = 0;
                        }
                        $ticket_detail['upsell_left_time'] = ($current_count_down_time + $passes->scanned_at) . '_' . $countdown_text . '_' . $passes->scanned_at;
                    } else {
                        $ticket_detail['upsell_left_time'] = '';
                    }
                    $ticket_detail['is_scan_countdown'] = !empty($scan_countdown_tickets[$passes->ticket_id]['is_scan_countdown']) ? $scan_countdown_tickets[$passes->ticket_id]['is_scan_countdown'] : 0;
                    $ticket_detail['countdown_interval'] = !empty($scan_countdown_tickets[$passes->ticket_id]['countdown_interval']) ? $scan_countdown_tickets[$passes->ticket_id]['countdown_interval'] : '';

                    if (!empty($passes->clustering_id) && 0) {
                        sort($tps_detail[$passes->clustering_id]);
                        $ticket_detail['types'] = $tps_detail[$passes->clustering_id];
                    } else {
                        sort($tps_detail[$passes->ticket_id]);
                        $ticket_detail['types'] = $tps_detail[$passes->ticket_id];
                    }
                    if ($add_to_pass == 0) {
                        $ticket_detail['types'] = array();
                    }
                    if (!empty($ticket_detail)) {
                        //replace null with blank
                        array_walk_recursive($arrReturn, function (&$item, $key) {
                            $item = (empty($item) && is_array($item)) ? array() : $item;
                            if ($key == 'price') {
                                $item = $item ? (float) $item : $item;
                            }
                            if ($key != 'visitor_group_no' && $key != 'price') {
                                $item = is_numeric($item) ? (int) $item : $item;
                            }
                            if ($key == 'upsell_ticket_ids') {
                                $item = $item ? (string) $item : $item;
                            }
                            $item = null === $item ? '' : $item;
                        });
                    }
                    $ticket_listing[$passes->ticket_id] = $ticket_detail;
                }
                sort($ticket_listing);
                //FINAL Ticket Listing response
                $response = array();
                $response['status'] = !empty($ticket_listing) ? $status : 0;
                $response['data'] = $ticket_listing;
                $response['is_ticket_listing'] = 1;
                $response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
                $response['redeem_cluster_tickets'] = 1;
                $response['is_early_reservation_entry'] = 0;
                $response['show_cluster_extend_option'] = $show_cluster_extend_option;
                $response['cluster_main_ticket_details'] = $cluster_main_ticket_details;
                $response['booking_note'] = $extra_booking_info['extra_note'];
                $response['is_prepaid'] = 1;
                $response['message'] = $response['status'] == 0 ? 'Pass not valid' : $message;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['get_prepaid_linked_ticket_listing'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['get_prepaid_linked_ticket_listing_' . date('H:i:s')] = $logs;
        return $response;
    }
    
    /**
     * @Name     : confirm_listed_prepaid_tickets().
     * @Purpose  : Used to confirm listed Prepaid tickets
     * @Called   : called when tap to ticket listing any type, called from confirm_pass API.
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 12 March 2018
     */
    function confirm_listed_prepaid_tickets($request_para) {
        global $MPOS_LOGS;
        global $spcl_ref;
        try {
            $logs_data = $request_para;
            unset($logs_data['user_detail']);
            $museum_info = $request_para['user_detail'];
            $museum_id = $request_para['museum_id'];
            $ticket_id = $request_para['ticket_id'];
            $tps_id = $request_para['tps_id'];
            $pass_no = $request_para['pass_no'];
            $add_to_pass = $request_para['add_to_pass'];
            $bleep_pass_no = $request_para['bleep_pass_no'];
            $is_scan_countdown = $request_para['is_scan_countdown'];
            $countdown_interval = $request_para['countdown_interval'];
            $upsell_ticket_ids = $request_para['upsell_ticket_ids'];
            $pos_point_id = $request_para['pos_point_id'];
            $pos_point_name = $request_para['pos_point_name'];
            $allow_reservation_entry = $request_para['allow_reservation_entry'];
            $is_reservation = $request_para['is_reservation'];
            $from_time = $request_para['from_time'];
            $to_time = $request_para['to_time'];
            $selected_date = $request_para['selected_date'];
            $slot_type = $request_para['slot_type'];
            $is_edited = $request_para['is_edited'];
            $shift_id = $request_para['shift_id'];
            $gmt_device_time = $request_para['gmt_device_time'];
            $device_time = $request_para['device_time'] / 1000;
            $types_bleep_passes = array();
            $cluster_bleep_pass = '';
            $is_redeem = 0;
            $future_reservation = 0;
            $update_on_pass_no = 0;
            $update_on_bleep_pass_no = $upsell_performed = $redeem_main_ticket_only = $redeem_all_passes_on_one_scan = 0;
            $check_activated = 1;
            if (!empty($bleep_pass_no)) {
                foreach ($bleep_pass_no as $bleep_pass_nos) {
                    $types_bleep_passes[$bleep_pass_nos['ticket_type']][] = $cluster_bleep_pass = $bleep_pass_nos['pass_no'];
                }
            }
            $requested_pt_data = $prepaid_data = $all_prepaid_data = $requested_pt_data = $upsell_pt_data = array();
            $data = array();
            $museum_details = $this->find('qr_codes', array('select' => 'cod_id, is_group_check_in_allowed, is_group_entry_allowed, mpos_settings', 'where' => 'cod_id = "' . $museum_id . '"'));
            $logs['museum_details_query'] = $this->primarydb->db->last_query();
            $logs['museum_details'] = $museum_details;
            foreach($museum_details as $details) {
                if($details['cod_id'] == $museum_id) {
                    $details['mpos_settings'] = stripslashes($details['mpos_settings']);
                    $mpos_settings = json_decode($details['mpos_settings'],TRUE);
                    $is_group_check_in_allowed = $details['is_group_check_in_allowed'];
                    $is_group_entry_allowed = $details['is_group_entry_allowed'];
                }
            }
            $ticket_data = $this->find('modeventcontent', array('select' => 'is_scan_countdown, guest_notification, countdown_interval, allow_city_card, merchant_admin_id, merchant_admin_name', 'where' => 'mec_id = "' . $ticket_id . '"'));
            $logs['mec_data query'] = $this->primarydb->db->last_query();
            $logs['mec_data_'] = $ticket_data;
            $allow_city_card = $ticket_data[0]['allow_city_card'];
            if(in_array($museum_id, $this->redeem_all_passes_on_one_scan_suppliers) && $is_scan_countdown && $allow_city_card == '0') { //redeem_all_passes_on_one_scan case
                $redeem_all_passes_on_one_scan = 1;
            }
            $logs['redeem_all_passes_on_one_scan'] = $redeem_all_passes_on_one_scan;
            //Get pass details from PT
            $select = "reseller_id, pax, capacity, additional_information, prepaid_ticket_id,is_addon_ticket, hotel_ticket_overview_id, second_party_type, third_party_type, payment_conditions, redeem_users, clustering_id, cashier_name,pos_point_id, pos_point_name, tax, hotel_id, hotel_name, ticket_id, tps_id, ticket_type, passNo, bleep_pass_no, title, price, age_group, used, visitor_group_no, scanned_at, visitor_tickets_id, pass_type, activation_method, tax_name, museum_id,museum_cashier_id,created_date_time, museum_name, distributor_partner_id, distributor_partner_name, is_combi_ticket,channel_type, action_performed, selected_date, from_time, to_time,timeslot, timeslot, timezone, booking_status, is_invoice";
            $where = 'visitor_group_no !="0" and (visitor_group_no="' . $pass_no . '" or passNo="' . $pass_no . '" or bleep_pass_no="' . $pass_no . '") and is_refunded != "1"';
            if ($museum_id != ''  && $museum_info->cashier_type != '3') {
                $where .= ' and museum_id = '. $museum_id;
            }
            $all_prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where,  'order_by' => 'created_date_time DESC' ), 'object');
            $logs['prepaid Query'] = $this->primarydb->db->last_query();
            $logs['prepaid_data'] = count($all_prepaid_data);
            if (!empty($all_prepaid_data)) {
                foreach ($all_prepaid_data as $key => $pt_tickets) {
                    if($pt_tickets->ticket_id == $ticket_id && $pt_tickets->tps_id == $tps_id) { //for upsell both tickets are required to be redeemed
                        $requested_pt_data[] = $pt_tickets;
                        if($pt_tickets->is_addon_ticket == '0' && strpos($pt_tickets->action_performed, 'UPSELL') !== false) {
                            $upsell_performed = 1;
                        }
                    }
                }                
                $logs['upsell_performed'] = $upsell_performed;
                if($upsell_performed == 1) {
                    foreach ($all_prepaid_data as $key => $pt_tickets) {
                        if($pt_tickets->is_addon_ticket == '0' && (strpos($pt_tickets->action_performed, 'UPSELL') !== false)) {
                            $upsell_pt_data[] = $pt_tickets;
                            $redeem_main_ticket_only = 1;
                        }
                    }
                    $prepaid_data = $upsell_pt_data;
                } else {
                   $prepaid_data = $requested_pt_data;
                }
                if($redeem_all_passes_on_one_scan) {
                    $select = "reseller_id, pax, capacity, additional_information, prepaid_ticket_id,is_addon_ticket, hotel_ticket_overview_id, second_party_type, third_party_type, payment_conditions, redeem_users, clustering_id, cashier_name,pos_point_id, pos_point_name, tax, hotel_id, hotel_name, ticket_id, tps_id, ticket_type, passNo, bleep_pass_no, title, price, age_group, used, visitor_group_no, scanned_at, visitor_tickets_id, pass_type, activation_method, tax_name, museum_id,museum_cashier_id,created_date_time, museum_name, distributor_partner_id, distributor_partner_name, is_combi_ticket,channel_type, action_performed, selected_date, from_time, to_time,timeslot, timeslot, timezone, booking_status, is_invoice";
                    $where = 'visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'" and ticket_id = "'.$ticket_id.'" and activated = "1" and is_refunded != "1" and is_addon_ticket = "0"';
                    $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where,  'order_by' => 'created_date_time DESC' ), 'object');
                    $logs['prepaid Query_in_redeem_all_passes_on_one_scan'] = $this->primarydb->db->last_query();
                    $logs['prepaid_data_for_redeem_all_passes_on_one_scan'] = count($prepaid_data);
                }
                $flag = 1;
                //Check prepaid entries to redeem which are not redeemed and if redeemed they are still valid in case of countdown tickets
                foreach ($prepaid_data as $key => $pt_tickets) {
                    if ($flag == 1) {
                        $prepaid_row = $pt_tickets;
                        $flag++;
                    }
                    if ($pt_tickets->used == '1' && !(strstr($pt_tickets->action_performed, 'SCAN_TB') || strstr($pt_tickets->action_performed, 'UPSELL_INSERT') || strstr($pt_tickets->action_performed, 'UPSELL') || ($pt_tickets->passNo == $pt_tickets->bleep_pass_no))) {
                        unset($prepaid_data[$key]);
                    }
                    $valid_till_time = $pt_tickets->payment_conditions;
                    $current_time = strtotime(gmdate('m/d/Y H:i:s'));
                    if ($valid_till_time > 0 && $valid_till_time < $current_time) {
                        unset($prepaid_data[$key]);
                    }
                }
                $prepaid_data = array_values($prepaid_data);
                $logs['prepaid_data_count'] = count($prepaid_data);
                //print receipt for third party city card off
                $show_scanner_receipt = 0;
                $receipt_data = array();
                if(in_array($prepaid_data[0]->channel_type, $this->api_channel_types) && (!in_array($prepaid_data[0]->is_invoice, $this->is_invoice)) &&  $prepaid_data[0]->channel_type != "5" && $add_to_pass == 0 && ($is_scan_countdown == 1 || $prepaid_data[0]->is_addon_ticket == "0" && $is_scan_countdown == 0 && in_array($prepaid_data[0]->reseller_id, $this->css_resellers_array))) {
                    $pt_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'" and activated = "1" and is_refunded != "1" and is_addon_ticket = "0" and channel_type = "'.$prepaid_data[0]->channel_type.'"'), 'array');
                    $extra_options_data = $this->find('prepaid_extra_options', array('select' => '*', 'where' => 'visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'" and is_cancelled = "0"'), 'array');
                    
                    /* City Card off updation of passNo for prohibt duplicate user entry */
                    $checkUsed = array_sum(array_map('intval', array_column($pt_data, 'used')));
                    $logs['check_used_orders_'.date('H:i:s')] = $checkUsed;
                    if($checkUsed == 0 && $pt_data[0]['bleep_pass_no'] == '') {
                        $show_scanner_receipt = 1;
                        $getPtData = $this->getUpdatedPasses($prepaid_data[0]->visitor_group_no, $prepaid_data[0]->channel_type);
                        if(!empty($getPtData)) {
                            $updPass = true;
                        }
                    }
                    /* City Card off updation of passNo for prohibt duplicate user entry */
                    
                    foreach($extra_options_data as $extra_options) {
                        if($extra_options['variation_type'] == 0){
                            if($extra_options['ticket_price_schedule_id'] > 0) {
                                $per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float)$per_age_group_extra_options[$extra_options['ticket_price_schedule_id']][$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            } else {
                                $per_ticket_extra_options[$extra_options['description']] = array(
                                    'main_description' => $extra_options['main_description'],
                                    'description' => $extra_options['description'],
                                    'quantity' => (int)$per_ticket_extra_options[$extra_options['description']]['quantity'] + $extra_options['quantity'],
                                    'refund_quantity' => (int)$per_ticket_extra_options[$extra_options['description']]['refund_quantity'] + $extra_options['refund_quantity'],
                                    'price' => (float)$per_ticket_extra_options[$extra_options['description']]['price'] + $extra_options['price'],
                                );
                            }
                        }
                    }
                    $total_price = 0;
                    $used = 0;
                    foreach($pt_data as $data) {
                        if($data['used'] == 1) {
                            $show_scanner_receipt = 0;
                        }
                        sort($per_age_group_extra_options[$data['tps_id']]);
                        $total_price += $data['price'];
                        
                        if($updPass && isset($getPtData[$data['prepaid_ticket_id']]) && !empty($getPtData[$data['prepaid_ticket_id']])) {
                            $passes[$data['tps_id']][] = $getPtData[$data['prepaid_ticket_id']];
                            $data['bleep_pass_no'] = $getPtData[$data['prepaid_ticket_id']];
                        }
                        else {
                            $passes[$data['tps_id']][] = $data['passNo'];
                        }
                        
                        $types_array[$data['tps_id']] = array(
                            'tps_id' => (int)$data['tps_id'],
                            'tax' => (float)$data['tax'],
                            'age_group' => $data['age_group'],
                            'type' => (!empty($this->types[strtolower($data['ticket_type'])]) && ($this->types[strtolower($data['ticket_type'])] > 0)) ? $this->types[strtolower($data['ticket_type'])] : 10,
                            'ticket_type_label' => $data['ticket_type'],
                            'quantity' => (int)$types_array[$data['tps_id']]['quantity'] + 1,
                            'price' => (float)$types_array[$data['tps_id']]['price'] + $data['price'],
                            'net_price' => (float)$types_array[$data['tps_id']]['net_price'] + $data['net_price'],
                            'passes' => array_unique($passes[$data['tps_id']]),
                            'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data['tps_id']])) ? $per_age_group_extra_options[$data['tps_id']] : array()
                        );
                        $pt_sync_daata[] = $data;
                    }
                    sort($types_array);
                    sort($per_ticket_extra_options);
                    $tickets_array = array(
                        'ticket_id' => (int)$prepaid_data[0]->ticket_id,
                        'title' => $prepaid_data[0]->title,
                        'guest_notification' =>  '',
                        'is_reservation' => ($prepaid_data[0]->selected_date != '' && $prepaid_data[0]->selected_date != NULL && $prepaid_data[0]->selected_date != '0' && $prepaid_data[0]->from_time != '' && $prepaid_data[0]->from_time != NULL && $prepaid_data[0]->from_time != '0' && $prepaid_data[0]->to_time != '' && $prepaid_data[0]->to_time != NULL && $prepaid_data[0]->to_time != '0') ? 1 : 0,
                        'selected_date' => ($prepaid_data[0]->selected_date != '' && $prepaid_data[0]->selected_date != NULL && $prepaid_data[0]->selected_date != '0') ? $prepaid_data[0]->selected_date : '',
                        'from_time' => ($prepaid_data[0]->selected_date != '' && $prepaid_data[0]->selected_date != NULL && $prepaid_data[0]->selected_date != '0') ? $prepaid_data[0]->from_time : '',
                        'to_time' => ($prepaid_data[0]->selected_date != '' && $prepaid_data[0]->selected_date != NULL && $prepaid_data[0]->selected_date != '0') ? $prepaid_data[0]->to_time : '',
                        'slot_type' => ($prepaid_data[0]->selected_date != '' && $prepaid_data[0]->selected_date != NULL && $prepaid_data[0]->selected_date != '0') ? $prepaid_data[0]->timeslot : '',
                        'types' => $types_array,
                        'per_ticket_extra_options' => (!empty($per_ticket_extra_options)) ? $per_ticket_extra_options : array()
                    );
                    $receipt_data = array(
                        'supplier_name' => $prepaid_data[0]->museum_name,
                        'tax_name' => $prepaid_data[0]->tax_name,
                        'payment_method' => (int)$prepaid_data[0]->activation_method,
                        'pass_type' => (int)$prepaid_data[0]->pass_type,
                        //'is_combi_ticket' => (int)$prepaid_data[0]->is_combi_ticket,
                        'is_combi_ticket' => 0,
                        'distributor_name' => $prepaid_data[0]->hotel_name,
                        'booking_date_time' => $prepaid_data[0]->created_date_time,
                        'total_combi_discount' => 0,
                        'total_service_cost' => 0,
                        'total_amount' => (float)$total_price,
                        'sold_by' => $prepaid_data[0]->cashier_name, 
                        'tickets' => $tickets_array
                    );
                }
                //Update Node in case of Is_edited is Set
                if ($is_edited == 1) {
                    if (SYNC_WITH_FIREBASE == 1) {
                        try {
                            $MPOS_LOGS['DB'][] = 'FIREBASE';
                            $headers = $this->all_headers(array(
                                'hotel_id' => $prepaid_data[0]->hotel_id,
                                'museum_id' => $prepaid_data[0]->museum_id,
                                'ticket_id' => $prepaid_data[0]->ticket_id,
                                'channel_type' => $prepaid_data[0]->channel_type,
                                'is_invoice' => $prepaid_data[0]->is_invoice,
                                'action' => 'confirm_listed_prepaid_tickets_from_MPOS',
                                'user_id' => $museum_info->cashier_type == '3' && $museum_info->reseller_id != ''? $museum_info->reseller_cashier_id : $prepaid_data[0]->museum_cashier_id,
                                'reseller_id' => $museum_info->cashier_type == '3' && $museum_info->reseller_id != '' ? $museum_info->reseller_id : ''
                            ));

                            /* fetch details of order of previous date */
                            $previous_key = base64_encode($prepaid_row->visitor_group_no . '_' . $prepaid_row->ticket_id . '_' . $prepaid_row->selected_date . '_' . $prepaid_row->from_time . "_" . $prepaid_row->to_time . "_" . $prepaid_row->created_date_time . '_' . $prepaid_row->passNo);
                            $new_key = base64_encode($prepaid_data[0]->visitor_group_no . '_' . $prepaid_data[0]->ticket_id . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $prepaid_data[0]->created_date_time . '_' . $prepaid_data[0]->passNo);
                            
                            if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') { 
                                $node = 'resellers/' . $museum_info->reseller_id . '/' . 'voucher_scans' . '/' . $museum_info->reseller_cashier_id . '/' . date("Y-m-d", strtotime($prepaid_data[0]->created_date_time)) . '/' . $previous_key;
                            } else {
                                $node = 'suppliers/' . $prepaid_data[0]->museum_id . '/' . 'voucher_scans' . '/' . $prepaid_data[0]->museum_cashier_id . '/' . date("Y-m-d", strtotime($prepaid_data[0]->created_date_time)) . '/' . $previous_key;
                            }

                            $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $node)
                            ));
                            $previous = json_decode($getdata);
                            $previous_data = $previous->data;
                            if (!empty($previous_data)) {
                                $previous_data->from_time = $from_time;
                                $previous_data->reservation_date = $selected_date;
                                $previous_data->to_time = $to_time;
                            }
                            /* delete previous node */
                            $this->curl->requestASYNC('FIREBASE', '/delete_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $node)
                            ));
                            /* create new node */
                            if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') { 
                                $new_node =  'resellers/' . $museum_info->reseller_id . '/voucher_scans/' . $museum_info->reseller_cashier_id . '/' . gmdate("Y-m-d") . '/' . $new_key;
                            } else {
                                $new_node = 'suppliers/' . $prepaid_data[0]->museum_id . '/voucher_scans/' . $prepaid_data[0]->museum_cashier_id . '/' . gmdate("Y-m-d") . '/' . $new_key;
                            }
                            $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array("node" => $new_node, 'details' => $previous_data)
                            ));
                        } catch (\Exception $e) {
                           $logs['exception'] = $e->getMessage(); 
                        }
                    }
                    //decrease capacity of prev timeslot
                    $capacity = isset($prepaid_row->capacity) ? $prepaid_row->capacity : 1;
                    $this->update_prev_capacity($prepaid_row->ticket_id, $prepaid_row->selected_date, $prepaid_row->from_time, $prepaid_row->to_time, $capacity, $prepaid_row->timeslot);
                    //update capacity of new timeslot
                    $this->update_capacity($prepaid_row->ticket_id, $selected_date, $from_time, $to_time, $capacity, $slot_type);
                }

                if (!empty($prepaid_data)) {
                    $cluster_ticket_ids = array();
                    foreach ($prepaid_data as $clusters) {
                        if (!empty($clusters->clustering_id)) {
                            $cluster_ticket_ids[$clusters->clustering_id] = $clusters;
                        }
                        if(strpos($clusters->action_performed, 'UPSELL') !== false) {
                            $check_activated = 0; //don't check activated for pt n ticket_status for VT to be 1, 
                            /*reason : we allow scan for refunded enteries as well (on basis of activated 1),
                             but upsell - 24hr has activated 0, but it is also redemmed with upsell - 48 redemption, 
                             so for upsell tickets, activated check is ignored
                             */
                        }
                    }
                    //Get ticket details from modeventcontent table
                    $is_scan_countdown = $ticket_data[0]['is_scan_countdown'];
                    $countdown_interval = $ticket_data[0]['countdown_interval'];
                    $merchant_admin_id = $ticket_data[0]['merchant_admin_id'];

                    $early_redeem = 0;
                    $timezone_count = 3600 * $prepaid_data[0]->timezone;
                    if (!empty($prepaid_data[0]->selected_date) && ( (strtotime($prepaid_data[0]->selected_date) - $timezone_count) > strtotime(gmdate('Y-m-d h:i')) )) {
                        $early_redeem = 1;
                        $logs['early redeem case'] = 'reservation ticket date is not today';
                    }
                    // For Reservation and Voucher Case
                    if ($is_reservation == 1) {
                        $current_day = strtotime(date("Y-m-d"));

                        if (isset($from_time) && $from_time != '') {
                            $from_time_check = strtotime($from_time);
                            $to_time_check = strtotime($to_time);
                            $selected_date_check = strtotime($selected_date);
                            $device_time_check = $device_time;
                        } else {
                            $from_time_check = strtotime($prepaid_data[0]->from_time);
                            $to_time_check = strtotime($prepaid_data[0]->to_time);
                            $selected_date_check = strtotime($prepaid_data[0]->selected_date);
                            $device_time_check = $device_time;
                        }

                        if ($selected_date_check == $current_day) {
                            if ($from_time_check <= $device_time_check && $to_time_check >= $device_time_check) {
                                $is_redeem = 1;
                            } else if ($from_time_check >= $device_time_check) {
                                $future_reservation = ($is_scan_countdown == 1) ? 0 : 1;
                                if (isset($is_edited)) {
                                    if ($is_edited == 1) {
                                        $is_redeem = ($is_scan_countdown == 1) ? 1 : 0;
                                    } else {
                                        $is_redeem = 1;
                                    }
                                }
                            }
                        } else if ($selected_date_check > $current_day) {
                            $future_reservation = 1;
                            if (isset($is_edited) && $is_edited == 1) {
                                $is_redeem = 0;
                            } else {
                                $is_redeem = 1;
                            }
                        } else if ($selected_date_check < $current_day) {
                            if (isset($is_edited) && $is_edited == 1) {
                                $is_redeem = 0;
                            } else {
                                $is_redeem = 1;
                            }
                        }
                        if ($prepaid_data[0]->used == 1 && strstr($prepaid_data[0]->action_performed, 'SCAN_TB')) {
                            $is_redeem = 1;
                        }
                    }
                    $logs['future_reservation'] = $future_reservation;
                    $is_early_reservation_entry = 0;
                    $early_checkin_time = '';
                    if ($early_redeem && empty($prepaid_data[0]->scanned_at) && (int) $allow_reservation_entry == 0 && $is_scan_countdown == '1') {
                        $is_early_reservation_entry = 1;
                        $is_redeem = 0; // if redeem in early stage is not from req don't redeem (PMS 11.422.0b : bug 4)
                        $timezone_count = 3600 * $prepaid_data[0]->timezone;
                        if ($prepaid_data[0]->timeslot == 'specific' || $prepaid_data[0]->timeslot != 'specific' ) {
                            $selected_time = date('Y-m-d H:i', strtotime($prepaid_data[0]->selected_date) - $timezone_count);
                        }
                        $early_checkin_time = $this->get_difference_bw_time($selected_time) . ' early';
                    }
                    $logs['is_early_reservation_entry'] = $is_early_reservation_entry;
                    $redeem_date_time = gmdate("Y-m-d H:i:s");
                    $scanned_at = strtotime($redeem_date_time);
                    if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '') {
                        $redeem_users = $museum_info->reseller_cashier_id . '_' . gmdate('Y-m-d');
                        $museum_cashier_id = $museum_info->reseller_cashier_id;
                        $museum_cashier_name = $museum_info->reseller_cashier_name;
                    } else {
                        $redeem_users = $museum_info->uid . '_' . gmdate('Y-m-d');
                        $museum_cashier_id = $museum_info->uid;
                        $museum_cashier_name = $museum_info->fname . ' ' . $museum_info->lname;
                    }
                    $logs['update_bleep_query'] = 1;
                    $update_bleep_query = 'update prepaid_tickets set ';
                    // Make update queries to update timeslot and redeem
                    $insert_pt_db2['updated_at'] = gmdate("Y-m-d H:i:s");
                    if (!empty($cluster_ticket_ids) && !empty($bleep_pass_no)) {
                        $update_bleep_query .= '  museum_cashier_id="' . $museum_cashier_id . '", museum_cashier_name="' . $museum_cashier_name . '", booking_status="1", ';
                        $insert_pt_db2['museum_cashier_id'] = $museum_cashier_id;
                        $insert_pt_db2['museum_cashier_name'] = $museum_cashier_name;
                        $insert_pt_db2['booking_status'] = "1";
                    } else if ($future_reservation == 0 || $is_redeem == 1) {
                        $insert_pt_db2['booking_status'] = "1";
                        if ($is_scan_countdown == 1) {
                            if (empty($prepaid_data[0]->redeem_users)) {
                                $update_bleep_query .= ' redeem_users="' . $redeem_users . '", booking_status="1", ';
                                $insert_pt_db2['redeem_users'] = $redeem_users;
                            } else {
                                $update_bleep_query .= ' redeem_users=CONCAT(redeem_users, ",' . $redeem_users . '"),  booking_status="1", ';
                                $insert_pt_db2['CONCAT_VALUE']['redeem_users'] =  ', '.$redeem_users;
                            }
                        } else {
                            $update_bleep_query .= ' museum_cashier_id="' . $museum_cashier_id . '", museum_cashier_name="' . $museum_cashier_name . '", booking_status="1", ';
                            $insert_pt_db2['museum_cashier_id'] = $museum_cashier_id;
                            $insert_pt_db2['museum_cashier_name'] = $museum_cashier_name;
                        }
                    }
                    if (strpos($prepaid_data[0]->action_performed, 'SCAN_TB') === false && $is_scan_countdown == 1 && $prepaid_data[0]->scanned_at == '' && $prepaid_data[0]->payment_conditions == '' && ($is_early_reservation_entry == 0 || $is_redeem == 1)) {
                        $countdown_values = explode('-', $countdown_interval);
                        $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                        $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                        if($redeem_all_passes_on_one_scan) {
                            $check_for_payment_cond = 'and ticket_id = "' . $ticket_id . '" '; //ticket should be checked for redeem_all_passes_on_one_scan
                        } else {
                            $check_for_payment_cond = 'and ( passNo = "' . $pass_no . '" or bleep_pass_no = "' . $pass_no . '" ) ';
                        }
                        $update_prepaid_query = 'update prepaid_tickets set payment_conditions = "' . $valid_till . '" where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" '. $check_for_payment_cond . ' and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : '');
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => array('payment_conditions' => $valid_till), "where" => ' visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" '. $check_for_payment_cond . ' and is_refunded != "1"' . (($check_activated) ? ' and activated = "1" ' : ''), "redeem" => (($is_redeem == 1)? 1: 0));
                        $sub_transactions = 1;
                    }
                    if (in_array($prepaid_data[0]->channel_type, $this->api_channel_types) && (!in_array($prepaid_data[0]->is_invoice, $this->is_invoice))) {
                        $update_bleep_query .= ' pos_point_id="' . $pos_point_id . '", pos_point_name="' . $pos_point_name . '", ';
                        $insert_pt_db2['pos_point_id'] = $pos_point_id;
                        $insert_pt_db2['pos_point_name'] = $pos_point_name;
                    }

                    if (empty($prepaid_data[0]->scanned_at) && ($is_early_reservation_entry == 0 && $future_reservation == 0) || $is_redeem == 1) {
                    	$update_bleep_query .= ' scanned_at=(case when (scanned_at = "" || scanned_at is NULL) then "'.$scanned_at.'" else scanned_at end ), redeem_date_time=(case when redeem_date_time = "1970-01-01 00:00:01" then "'.$redeem_date_time.'" else redeem_date_time end ), ';
                        $insert_pt_db2['scanned_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'scanned_at','value' => ''), array( 'key' => 'scanned_at','value' => 'NULL')
                        ) ,'update' => $scanned_at));
                        $insert_pt_db2['redeem_date_time'] = array('case' => array('key' => 'redeem_date_time','value' => "1970-01-01 00:00:01",'update' => $redeem_date_time));
                        //update used with scanned_at
                        $update_bleep_query .= ' used=(case when (scanned_at = "" || scanned_at is NULL) then "1" else used end ),';
                        $insert_pt_db2['used'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'scanned_at','value' => ''), array( 'key' => 'scanned_at','value' => 'NULL')
                        ) ,'update' => "1"));
                    }
                    if (empty($bleep_pass_no) && ($is_early_reservation_entry == 0 && $future_reservation == 0) || $is_redeem == 1) {
                        $used = 1;
                        $update_bleep_query .= ' used="1", ';
                        $insert_pt_db2['used'] = "1";
                    }
                    if($add_to_pass == 0) {
                        $insert_pt_db2['voucher_updated_by'] = $museum_cashier_id;
                        $insert_pt_db2['voucher_updated_by_name'] = $museum_cashier_name;
                        $insert_pt_db2['redeem_method'] = "Voucher";
                        $insert_pt_db2['pos_point_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'pos_point_id_on_redeem','value' => ''), array( 'key' => 'pos_point_id_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_id_on_redeem','value' => '0')
                        ) ,'update' => $pos_point_id));
                        $insert_pt_db2['pos_point_name_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'pos_point_name_on_redeem','value' => ''), array( 'key' => 'pos_point_name_on_redeem','value' => 'NULL'), array( 'key' => 'pos_point_name_on_redeem','value' => '0')
                        ) ,'update' => $pos_point_name));
                        $insert_pt_db2['distributor_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'distributor_id_on_redeem','value' => ''), array( 'key' => 'distributor_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_id_on_redeem','value' => '0')
                        ) ,'update' => $museum_info->dist_id));
                        $insert_pt_db2['distributor_cashier_id_on_redeem'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'distributor_cashier_id_on_redeem','value' => ''), array( 'key' => 'distributor_cashier_id_on_redeem','value' => 'NULL'), array( 'key' => 'distributor_cashier_id_on_redeem','value' => '0')
                        ) ,'update' => $museum_info->dist_cashier_id));
                    }

                    if ($is_edited == 1) {
                        $action_performed = 'UPDATE_RSV_ON_SCAN';
                        $update_bleep_query .= ' selected_date = "' . $selected_date . '", from_time = "' . $from_time . '", to_time = "' . $to_time . '" ,action_performed=CONCAT(action_performed, ", ' . $action_performed . '"), ';
                        $insert_pt_db2['selected_date'] = $selected_date;
                        $insert_pt_db2['from_time'] = $from_time;
                        $insert_pt_db2['to_time'] = $to_time;
                        $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                    } else {
                        $action_performed = '';
                    }
                    if (!empty($bleep_pass_no)) {
                        $update_bleep_query .= ' action_performed=CONCAT(action_performed, ", CSS_ACT"), ';
                        $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', CSS_ACT';
                    } else if ($used == 1) {
                        if ($is_scan_countdown == '1') {
                            if($redeem_all_passes_on_one_scan) {
                                $action_performed = $action_performed . 'RDM_ALL';
                                $update_bleep_query .= ' action_performed=CONCAT(action_performed, ", ' . $action_performed . '"), '; // update action in prepaid_tickets from
                                $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                            } else {
                                $action_performed = $action_performed . 'SCAN_TB';
                                $update_bleep_query .= ' action_performed=CONCAT(action_performed, ", ' . $action_performed . '"), '; // update action in prepaid_tickets from
                                $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                            }
                        } else {
                            $action_performed = $action_performed . 'SCAN_CSS';
                            $update_bleep_query .= ' action_performed=CONCAT(action_performed, ", ' . $action_performed . '"), '; // update action in prepaid_tickets from
                            $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                        }
                    }
                    if (($prepaid_data[0]->second_party_type == "5" || $prepaid_data[0]->third_party_type == "5") && $used == 1) {
                        $insert_pt_db2['redemption_notified_at'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'redemption_notified_at','value' => ''), array( 'key' => 'redemption_notified_at','value' => 'NULL')
                        ) ,'update' => gmdate("Y-m-d H:i:s")));
                        $insert_pt_db2['authcode'] = array('case' => array('separator' => '||', 'conditions' => array(
                            array( 'key' => 'authcode','value' => ''), array( 'key' => 'authcode','value' => 'NULL')
                        ) ,'update' => gmdate("Y-m-d H:i:s")));
                    }
					
                    /* Check TLC/CLC level if prices are modified and add value to PT */
                    $merchant_data = $this->get_price_level_merchant($ticket_id, $prepaid_data[0]->hotel_id, $prepaid_data[0]->tps_id);
                    if(!empty($merchant_data)) {
                        $merchant_admin_id = $merchant_data->merchant_admin_id;
                    }
                    if(isset($merchant_admin_id)) {
                        $insert_pt_db2['merchant_admin_id'] = $merchant_admin_id;
                    }
                    /* Check TLC/CLC level if prices are modified and add  value to PT */
                    
                    $prepaid_ticket_ids_array = $clustering_ids_array = $all_bleep_pass_nos = $visitor_tickets_ids_array = array();
                    $update_bleep_condition = '';
                    $prepaid_row = $prepaid_data[0];
                    $update_bleep_query =  rtrim($update_bleep_query,", ");
                    if (!empty($cluster_ticket_ids) && !empty($bleep_pass_no)) {
                        foreach ($cluster_ticket_ids as $prepaid_detail) {
                            $clustering_ids_array[] = $prepaid_detail->clustering_id;
                            $ticket_type = (!empty($this->types[strtolower($prepaid_detail->ticket_type)]) && ($this->types[strtolower($prepaid_detail->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_detail->ticket_type)] : 10;
                            if ($prepaid_detail->is_combi_ticket == '1') {
                                $update_bleep_condition .= ' when (clustering_id="' . $prepaid_detail->clustering_id . '" and (passNo="' . $pass_no . '" or visitor_group_no= "' . $pass_no . '")) then  "' . $types_bleep_passes[$ticket_type][0] . '" ';
                                $all_bleep_pass_nos[] = $types_bleep_passes[$ticket_type][0];
                                $insert_pt_db2['bleep_pass_no']['case'][] = array('separator' => '&&', 'conditions' => array(
                                    array( 'key' => 'clustering_id','value' => $prepaid_detail->clustering_id), array( 'key' => 'passNo','value' => $pass_no)
                                ) ,'update' => $types_bleep_passes[$ticket_type][0]);
                                $insert_pt_db2['bleep_pass_no']['case'][] = array('separator' => '&&', 'conditions' => array(
                                    array( 'key' => 'clustering_id','value' => $prepaid_detail->clustering_id), array( 'key' => 'visitor_group_no','value' => $pass_no)
                                ) ,'update' => $types_bleep_passes[$ticket_type][0]);
                            } else {
                                $update_bleep_condition .= ' when (clustering_id="' . $prepaid_detail->clustering_id . '" and (passNo="' . $pass_no . '" or visitor_group_no= "' . $pass_no . '") ) then  "' . $cluster_bleep_pass . '" ';
                                $all_bleep_pass_nos[] = $cluster_bleep_pass;
                                $insert_pt_db2['bleep_pass_no']['case'][] = array('separator' => '&&', 'conditions' => array(
                                    array( 'key' => 'clustering_id','value' => $prepaid_detail->clustering_id), array( 'key' => 'passNo','value' => $pass_no)
                                ) ,'update' => $cluster_bleep_pass);
                                $insert_pt_db2['bleep_pass_no']['case'][] = array('separator' => '&&', 'conditions' => array(
                                    array( 'key' => 'clustering_id','value' => $prepaid_detail->clustering_id), array( 'key' => 'visitor_group_no','value' => $pass_no)
                                ) ,'update' => $cluster_bleep_pass);
                            }
                            unset($types_bleep_passes[$ticket_type][0]);
                            sort($types_bleep_passes[$ticket_type]);
                            $prepaid_row = $prepaid_detail;
                        }
                        $clustering_ids_array_string = implode('","', $clustering_ids_array);
                        $update_bleep_pass_column = ', bleep_pass_no=(case ' . $update_bleep_condition . ' else bleep_pass_no end) ';
                        $update_bleep_query .= ' ' . $update_bleep_pass_column . '  where clustering_id in ("' . $clustering_ids_array_string . '") and (passNo="' . $pass_no . '" or visitor_group_no= "' . $pass_no . '") ';
                        $where_pt_db2 = ' clustering_id in ("' . $clustering_ids_array_string . '") and (passNo="' . $pass_no . '" or visitor_group_no= "' . $pass_no . '") ';
                    } else if ($future_reservation == 0 || $is_redeem == 1 || $is_edited == 1) {
                        foreach ($prepaid_data as $prepaid_detail) {
                            $prepaid_row = $prepaid_detail;
                            $prepaid_ticket_ids_array[] = $prepaid_ticket_id = $prepaid_detail->prepaid_ticket_id;
                            $visitor_tickets_ids_array[] = $prepaid_detail->visitor_tickets_id;
                            if ($prepaid_detail->used == '0' && ($future_reservation == 0 || $is_redeem == 1)) {
                                $ticket_type = (!empty($this->types[strtolower($prepaid_detail->ticket_type)]) && ($this->types[strtolower($prepaid_detail->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_detail->ticket_type)] : 10;
                                if (!empty($bleep_pass_no)) {
                                    $update_bleep_condition .= ' when prepaid_ticket_id="' . $prepaid_ticket_id . '" then  "' . $types_bleep_passes[$ticket_type][0] . '" ';
                                    $all_bleep_pass_nos[] = $types_bleep_passes[$ticket_type][0];
                                    $bleep_pass_no_insert['case'][] = array( 'key' => 'prepaid_ticket_id','value' => $prepaid_ticket_id, 'update' => $types_bleep_passes[$ticket_type][0]);
                                    unset($types_bleep_passes[$ticket_type][0]);
                                    sort($types_bleep_passes[$ticket_type]);
                                    break;
                                }
                                $order_confirm_date_insert['case'][] = array('separator' => '&&', 'conditions' => array(
                                    array( 'key' => 'prepaid_ticket_id','value' => $prepaid_ticket_id), array( 'key' => 'booking_status','value' => "0" ), array( 'key' => 'channel_type','value' => "2" )
                                ) ,'update' => gmdate('Y-m-d H:i:s'));
                            }
                        }
                        $prepaid_ticket_ids_string = implode(',', $prepaid_ticket_ids_array);
                        $where_cond  = '';
                        if($museum_info->cashier_type != '3') {
                            $where_cond = 'and museum_id="' . $museum_id.'"'; 
                        }
                        if ($is_redeem == 1 || $future_reservation == 0) {
                            $logs['case : '] = 'redeem or not future reservation';
                            $update_bleep_pass_column = '';
                            if (!empty($bleep_pass_no)) {
                                $update_bleep_pass_column = ', bleep_pass_no=(case ' . $update_bleep_condition . ' else bleep_pass_no end) ';
                            }
                            if (isset($bleep_pass_no_insert)) {
                                $insert_pt_db2['bleep_pass_no'] = $bleep_pass_no_insert;
                            }
                            if (isset($order_confirm_date_insert)) {
                                $insert_pt_db2['order_confirm_date'] = $order_confirm_date_insert;
                            }
                            if (!empty($bleep_pass_no)) {
                                $update_bleep_query .= ' ' . $update_bleep_pass_column . ' where visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  ';
                                $where_pt_db2 = ' visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ') ';
                            } else {
                                $expedia_query = 'update expedia_prepaid_tickets set ';
                                if (empty($prepaid_data[0]->scanned_at) && $is_early_reservation_entry == 0) {
                                    $expedia_query .= ' scanned_at="' . $scanned_at . '", used="1",  ';
                                }
                                $logs['city_card_combi_timebase'] = $allow_city_card ."== '0' &&". $prepaid_data[0]->is_combi_ticket ."== '1' &&". $is_scan_countdown ." == '1'";
                                if(in_array($prepaid_data[0]->channel_type, $this->api_channel_types) &&(!in_array($prepaid_data[0]->is_invoice, $this->is_invoice)) && $add_to_pass == 0 && $prepaid_data[0]->channel_type != "5" && $is_scan_countdown == "1") {
                                    $update_bleep_pass_column .= ', shift_id = "'.$shift_id.'"';
                                    $insert_pt_db2['shift_id'] = $shift_id;
                                    
                                    $update_shift_id = 1;
                                }
                                if($allow_city_card == '0' && $is_scan_countdown == '1') {
                                    if($redeem_all_passes_on_one_scan == '0') {
                                        $pass_NO_check = ' passNo = "' . $pass_no . '" and';
                                    }
                                    if($prepaid_data[0]->is_combi_ticket == '1') {
                                        $update_on_pass_no = 1;                                                                  
                                        $expedia_query .= ' action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and passNo = "' . $pass_no . '" and ticket_id = "' . $ticket_id . '"';
                                        $update_bleep_query .= ' ' . $update_bleep_pass_column . ' , museum_cashier_id="' . $museum_cashier_id . '", museum_cashier_name="' . $museum_cashier_name . '" where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and'.$pass_NO_check.' ticket_id = "' . $ticket_id . '" '.$where_cond.' ';
                                        $where_pt_db2 = ' visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '"  and'.$pass_NO_check.' ticket_id = "' . $ticket_id . '" '.$where_cond.' ';
                                    } else {
                                        $expedia_query .= ' action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ') ';
                                        $update_bleep_query .= ' ' . $update_bleep_pass_column . ' , museum_cashier_id="' . $museum_cashier_id . '", museum_cashier_name="' . $museum_cashier_name . '"  where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  '.$where_cond.' ';
                                        $where_pt_db2 = ' visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  '.$where_cond.' ';
                                    }
                                } else if(!($allow_city_card == '0' && $is_scan_countdown == '1' && $prepaid_data[0]->is_combi_ticket == '1')){
                                    $expedia_query .= ' action_performed=concat(action_performed, ", ' . $action_performed . '") where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ') ';
                                    $update_bleep_query .= ' ' . $update_bleep_pass_column . ' , museum_cashier_id="' . $museum_cashier_id . '", museum_cashier_name="' . $museum_cashier_name . '"  where visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  '.$where_cond.' ';
                                    $where_pt_db2 = ' visitor_group_no = "' . $prepaid_data[0]->visitor_group_no . '" and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  '.$where_cond.' ';
                                }
                                $insert_pt_db2['museum_cashier_id'] = $museum_cashier_id;
                                $insert_pt_db2['museum_cashier_name'] = $museum_cashier_name;
                            }
                        } else if ($is_edited == 1) {
                            $logs['case : '] = 'edit';
                            $update_bleep_query .= ' where visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'"  and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ') '.$where_cond.' ';
                            $where_pt_db2 = '  visitor_group_no = "'.$prepaid_data[0]->visitor_group_no.'"  and prepaid_ticket_id in (' . $prepaid_ticket_ids_string . ')  '.$where_cond.' ';
                        }
                    }
                    if (empty($prepaid_ticket_ids_array) && empty($clustering_ids_array)) {
                        $update_bleep_query = $expedia_query = $where_pt_db2 = '';
                        $insert_pt_db2 = array();
                    }
                    if (!empty($all_bleep_pass_nos)) {
                        $all_bleep_pass_nos_string = implode('","', $all_bleep_pass_nos);
                        $this->query('update bleep_pass_nos set status="1" where pass_no in("' . $all_bleep_pass_nos_string . '")');
                    }

                    if (!empty($update_bleep_query)) {
                        $this->query($update_bleep_query);
                        $logs['update_query_'.date('H:i:s')] = $update_bleep_query;
                    }
                    if (!empty($update_prepaid_query)) {
                        $this->query($update_prepaid_query);
                        $logs['pt_query_'.date('H:i:s')] = $update_prepaid_query;
                    }
                    if (!empty($expedia_query)) {
                        $this->query($expedia_query);
                        $logs['expedia_query_'.date('H:i:s')] = $expedia_query;
                    }
                    //Prepare array to make entry in redeem_cashiers_details table
                    $update_redeem_table = array();
                    if ($used == 1) {
                        $update_redeem_table['visitor_group_no'] = $prepaid_row->visitor_group_no;
                        $update_redeem_table['prepaid_ticket_ids'] = !empty($prepaid_ticket_ids_array) ? $prepaid_ticket_ids_array : array($prepaid_row->prepaid_ticket_id);
                        $update_redeem_table['museum_cashier_id'] = $museum_cashier_id;
                        $update_redeem_table['shift_id'] = $shift_id;
                        $update_redeem_table['redeem_date_time'] = $redeem_date_time;
                        $update_redeem_table['hotel_id'] = $prepaid_data[0]->hotel_id;
                        $update_redeem_table['museum_cashier_name'] = $museum_cashier_name;
                        if($update_on_pass_no == 1) {
                            $update_redeem_table['update_on_pass_no'] = $update_on_pass_no;
                            $update_redeem_table['prepaid_ticket_ids'] = array();
                            $update_redeem_table['pass_no'] = $pass_no;
                            $update_redeem_table['ticket_id'] = $ticket_id;
                        }
                    }

                    //Notify third party tickets
                    if ($prepaid_row->used == '0' && $used == 1 && ($prepaid_row->second_party_type == "5" || $prepaid_row->third_party_type == "5")) {
                        $request_array = array();
                        $request_array['passNo'] = $pass_no;
                        $request_array['ticket_id'] = $ticket_id;
                        $request_array['scan_api'] = 'scan from confirm pass - mpos app';
                        $request_array['date_time'] = gmdate("Y-m-d H:i:s");
                        $request_string = json_encode($request_array);
                        $logs['data_to_queue_THIRD_PARTY_REDUMPTION_'.date('H:i:s')] = $request_array;
                        $MPOS_LOGS['external_reference'] = 'redeem';
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        // This Fn used to send notification with data on AWS panel. 
                        if (LOCAL_ENVIRONMENT != 'Local') {
                            $spcl_ref = 'notify_third_party';
                            $this->queue($aws_message, THIRD_PARTY_REDUMPTION, THIRD_PARTY_REDUMPTION_ARN);
                        }
                    }
                    //Add update queries in queue
                    if(!empty($insert_pt_db2) && !empty($where_pt_db2)) {
                        $redeemChk = ((isset($insert_pt_db2['used']) && $insert_pt_db2['used']=='1')? 1: 0);
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db2, "where" => $where_pt_db2, "redeem" => $redeemChk);
                    }
                    if (!empty($update_redeem_table) || !empty($insertion_db2)) {
                        $request_array = array();
                        if ($prepaid_row->booking_status == '0' && $prepaid_row->channel_type == '2' && !empty($prepaid_row->prepaid_ticket_id)) {
                            $request_array['confirm_order'] = array('reseller_id' => $prepaid_row->reseller_id, 'price' => $prepaid_row->price, 'dist_id' => $prepaid_row->hotel_id, 'voucher_updated_by' => $museum_cashier_id , 'voucher_updated_by_name' => $museum_cashier_name, 'prepaid_ticket_id' => $prepaid_row->prepaid_ticket_id, 'hto_id' => $prepaid_row->hotel_ticket_overview_id, 'action_performed' => $action_performed);
                        }
                        $request_array['db2_insertion'] = $insertion_db2;
                        $request_array['sub_transactions'] = isset($sub_transactions) ? $sub_transactions : 0;
                        $request_array['update_redeem_table'] = $update_redeem_table;
                        $request_array['write_in_mpos_logs'] = 1;
                        if ($add_to_pass == '1' && empty($prepaid_row->bleep_pass_no)) {
                            $request_array['action'] = "confirm_listed_prepaid_tickets";
                            $request_array['pass_no'] = $pass_no;
                            $request_array['channel_type'] = $prepaid_row->channel_type;
                        }
                        $request_array['visitor_group_no'] = $prepaid_row->visitor_group_no;
                        
                        $updated_data = array();
                        if($update_on_pass_no == 1) {
                            $updated_data['passNo'] = $pass_no;
                            $updated_data['update_on_pass_no'] = 1;
                        } else if($update_on_bleep_pass_no == 1) {
                            $updated_data['bleep_pass_no'] = $prepaid_row->bleep_pass_no;
                            $updated_data['update_on_bleep_pass_no'] = 1;
                        }
                        $updated_data['visitor_group_no'] = $prepaid_row->visitor_group_no;
                        $updated_data['visitor_tickets_id'] = isset($visitor_tickets_ids_array) ? $visitor_tickets_ids_array : array($prepaid_row->visitor_tickets_id);
                        $updated_data['redeem_group_tickets'] = isset($visitor_tickets_ids_array) ? "1" : "0";
                        $updated_data['ticket_id'] = $prepaid_row->ticket_id;
                        $updated_data['check_ticket_id'] = ($prepaid_row->ticket_id == $ticket_id) ? "1" : "0";
                        $updated_data['check_activated'] = $check_activated;
                        if($add_to_pass == 0) {
                            $updated_data['voucher_updated_by'] = $museum_cashier_id;
                            $updated_data['voucher_updated_by_name'] = $museum_cashier_name;
                            $updated_data['redeem_method'] = 'Voucher';
                        }
                        if ($is_edited == 1) {
                            $updated_data['selected_date'] = $selected_date;
                            $updated_data['from_time'] = $from_time;
                            $updated_data['to_time'] = $to_time;
                            $update_vt_action = 'UPDATE_RSV_ON_SCAN,';
                            $updated_data['action_performed'] = 'UPDATE_RSV_ON_SCAN,';
                            $updated_data['updated_at'] = gmdate("Y-m-d H:i:s");
                        }
                        if ($used == 1) {
                            $updated_data['updated_by_username'] = $museum_cashier_name;
                            $updated_data['updated_by_id'] = $museum_cashier_id;
                            $updated_data['used'] = '1';
                            if($prepaid_data[0]->channel_type != 5 || ($prepaid_data[0]->channel_type == 5 && $prepaid_data[0]->is_addon_ticket == "2")) {
                                $updated_data['visit_date'] = $scanned_at;
                            }
                            if (in_array($prepaid_row->channel_type, $this->api_channel_types) && (!in_array($prepaid_row->is_invoice, $this->is_invoice))) {
                                $updated_data['pos_point_id'] = $pos_point_id;
                                $updated_data['pos_point_name'] = $pos_point_name;
                                if ($update_shift_id == 1) {
                                    $updated_data['shift_id'] = $shift_id;
                                }
                            }
                            if ($prepaid_row->booking_status == 0 && $prepaid_row->channel_type == 2) {
                                $updated_data['order_confirm_date'] = gmdate('Y-m-d H:i:s');
                            }
                            $updated_data['booking_status'] = 1;
                            if ($is_scan_countdown == '1') {
                                $updated_data['action_performed'] = ($redeem_all_passes_on_one_scan) ?  $update_vt_action . ' RDM_ALL' : $update_vt_action . ' SCAN_TB';
                            } else {
                                $updated_data['action_performed'] = $update_vt_action . ' SCAN_CSS';
                            }
                            $updated_data['updated_at'] = gmdate("Y-m-d H:i:s");
                            $updated_data['redeem_main_ticket_only'] = $redeem_main_ticket_only;
                        }

                        if (($add_to_pass == '0' || ($add_to_pass == '1' && !empty($prepaid_row->bleep_pass_no))) && !empty($updated_data)) {
                            $request_array['vt_db2'] = $updated_data;
                        }
                        $request_string = json_encode($request_array);
                        $logs['data_to_queue_SCANING_ACTION_ARN_'.date('H:i:s')] = $request_array;
                        $MPOS_LOGS['external_reference'] = 'redeem';
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        if (LOCAL_ENVIRONMENT == 'Local') {
                            local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                        } else {
                            $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                        }
                    }
                    
                    if($updPass && !empty($getPtData) && $museum_info->cashier_type!='2' && $show_scanner_receipt=='1' && $used==1) {
                        $this->executeUpdPassesQueries($getPtData);
                    }
                    
                    if(in_array($prepaid_data[0]->channel_type, $this->api_channel_types) && (!in_array($prepaid_row->is_invoice, $this->is_invoice)) && 
                       $add_to_pass == '0' && 
                       $prepaid_row->channel_type != "5" && 
                       $is_scan_countdown == "1" &&
                       $pass_no == $prepaid_row->bleep_pass_no
                    ) {
                        $update_on_bleep_pass_no = 1;
                    }
                    
                    $prepaid_row->is_scan_countdown = $is_scan_countdown;
                    $prepaid_row->countdown_interval = $countdown_interval;
                    $prepaid_row->upsell_ticket_ids = $upsell_ticket_ids;
                    $prepaid_row->add_to_pass = 0;
                    if (!empty($bleep_pass_no)) {
                        $prepaid_row->add_to_pass = 1;
                    }
                    $prepaid_row->early_checkin_time = $early_checkin_time;
                    $prepaid_row->allow_reservation_entry = $allow_reservation_entry;
                    if ($prepaid_row->channel_type == 5) {
                        $is_early_reservation_entry = 0;
                    }
                    if ($used == 1) {
                        $is_redeem = 1;
                    }
                    $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                    $logs = array();
                    $cashier_type = $museum_info->cashier_type;
                    if($show_scanner_receipt == 1 && $is_redeem == 1) { //first time redeem of third party timebased ticket
                        $basic_data = array(
                            'pos_point_id' => $pos_point_id, 
                            'pos_point_name' => $pos_point_name,
                            'user_id' => $museum_cashier_id,
                            'shift_id' => $shift_id,
                            'museum_id' => $museum_id,
                            'cashier_type' => $museum_info->cashier_type,
                            'reseller_id' => isset($museum_info->reseller_id) && $museum_info->reseller_id != '' ?  $museum_info->reseller_id : '',
                            'reseller_cashier_id' =>  isset($museum_info->reseller_cashier_id) && $museum_info->reseller_cashier_id != '' ?  $museum_info->reseller_cashier_id : ''
                        );
                        if(!empty($pt_sync_daata)) {
                            $this->sync_voucher_scans($pt_sync_daata, $basic_data);
                        } else {
                            $this->sync_voucher_scans($pt_data, $basic_data);
                        }
                    }
                    if($cashier_type == '2' || !empty($this->liverpool_resellers_array) && in_array($prepaid_data[0]->reseller_id, $this->liverpool_resellers_array)){
                        $show_scanner_receipt = 0;
                    }
                    //Prepare final response
                    $prepaid_response = $this->response_after_confirm_prepaid_listed_ticket($prepaid_row, $pass_no, $is_early_reservation_entry, $is_reservation, $device_time, $from_time, $to_time, $selected_date, $slot_type, $is_edited, $is_redeem, $gmt_device_time, $add_to_pass, $show_scanner_receipt, $receipt_data, $redeem_all_passes_on_one_scan);

                    $data = $prepaid_response['data'];
                    $is_cluster = $prepaid_response['is_cluster'];
                    $status = $prepaid_response['status'];
                    $message = $prepaid_response['message'];
                } else {
                    $status = 0;
                    $message = 'Pass not valid';
                }
            } else {
                $status = 0;
                $message = 'Pass not valid';
            }

            if (!empty($data)) {
                //replace null with blank
                array_walk_recursive($data, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no' && $key != 'pass_no') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }

                    $item = null === $item ? '' : $item;
                });
            }

            $response = array('status' => $status, 'message' => $message, 'is_prepaid' => 1, 'data' => $data, 'is_early_reservation_entry' => $is_early_reservation_entry, 'is_cluster' => $is_cluster);
            $logs['response' . date('H:i:s')] = $response;
            $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['confirm_listed_prepaid_tickets'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : response_after_confirm_prepaid_listed_ticket().
     * @Purpose  : Used to confirm listed vochers from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 29 Nov 2017
     */
    function response_after_confirm_prepaid_listed_ticket($prepaid_data = array(), $pass_no = '', $is_early_reservation_entry = '', $is_reservation_check = '', $device_time = '', $from_time_new = '', $to_time_new = '', $selected_date_new = '', $slot_type = '', $is_edited = 0, $is_redeem = 0, $gmt_device_time = '', $add_to_pass = 0, $show_scanner_receipt = '', $receipt_data = array(), $redeem_all_passes_on_one_scan = 0) {
        global $MPOS_LOGS;
        try {
            $logs['pass_no__early_res__is_edited__is_redeem'] = array($pass_no, $is_early_reservation_entry, $is_edited, $is_redeem);
            $scanned_at = !empty($prepaid_data->scanned_at) ? $prepaid_data->scanned_at : strtotime(gmdate('m/d/Y H:i:s'));

            if (isset($from_time_new) && $from_time_new != '') {
                $from_time_check = $from_time_new;
                $to_time_check = $to_time_new;
                $selected_date_check = $selected_date_new;
                $timeslot = $slot_type;
            } else {
                $from_time_check = $prepaid_data->from_time;
                $to_time_check = $prepaid_data->to_time;
                $selected_date_check = $prepaid_data->selected_date;
                $timeslot = $prepaid_data->timeslot;
            }

            $current_day = strtotime(date("Y-m-d", $device_time));
            $status = 1;
            $message = 'Valid';
            $types_count = array();
            $total_amount = 0;
            // check is Voucher
            $is_voucher = ($prepaid_data->channel_type == 5) ? 1 : 0;
            $is_cluster = ($prepaid_data->clustering_id > 0) ? 1 : 0;
            //Get details from mec table
            $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate, own_capacity_id, shared_capacity_id, is_reservation, countdown_interval', 'where' => 'mec_id = ' . $prepaid_data->ticket_id), 'array');
            if (($prepaid_data->add_to_pass == 1 && $is_early_reservation_entry) || $redeem_all_passes_on_one_scan) {
                if($redeem_all_passes_on_one_scan) {
                    $used_check = '';
                } else {
                    $used_check = ' and passNo = "' . $pass_no . '" and used = "0" ';
                }
                $select = 'pax, capacity, additional_information, tps_id, title, clustering_id, group_price, group_quantity, quantity, ticket_type, price, tax';
                $where = 'visitor_group_no = "'.$prepaid_data->visitor_group_no.'" '.$used_check.' and is_addon_ticket = "0" and (order_status = "0" or order_status = "2" or order_status = "3") and activated = "1" and is_refunded != "1"';
                $get_count = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');                
                $logs['search_pt_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                if (!empty($get_count)) {
                    foreach ($get_count as $prepaid_count) {
                        $ticket_type_id = (!empty($this->types[strtolower($prepaid_count->ticket_type)]) && ($this->types[strtolower($prepaid_count->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_count->ticket_type)] : 10;
                        $types_count[$prepaid_count->tps_id]['ticket_type'] = $ticket_type_id;
                        $types_count[$prepaid_count->tps_id]['ticket_type_label'] = $prepaid_count->ticket_type;
                        $types_count[$prepaid_count->tps_id]['title'] = $prepaid_count->title;
                        $types_count[$prepaid_count->tps_id]['price'] = $prepaid_count->price;
                        $types_count[$prepaid_count->tps_id]['tax'] = $prepaid_count->tax;
                        $types_count[$prepaid_count->tps_id]['pax'] = isset($prepaid_count->pax) ? (int) $prepaid_count->pax : (int) 0;
                        $types_count[$prepaid_count->tps_id]['capacity'] = isset($prepaid_count->capacity) ? (int) $prepaid_count->capacity : (int) 1;
                        $types_count[$prepaid_count->tps_id]['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : '';
                        $types_count[$prepaid_count->tps_id]['end_date'] = isset($capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : '';
                        $types_count[$prepaid_count->tps_id]['count'] += 1;
                    }
                }
            }
            // Make types count array in case of early reservation
            if ($is_early_reservation_entry) {
                $types_count = array();
                $types_count[$prepaid_data->tps_id]['tps_id'] = $prepaid_data->tps_id;
                $types_count[$prepaid_data->tps_id]['ticket_type'] = (!empty($this->types[strtolower($prepaid_data->ticket_type)]) && ($this->types[strtolower($prepaid_data->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_data->ticket_type)] : 10;
                $types_count[$prepaid_data->tps_id]['ticket_type_label'] = (string) $prepaid_data->ticket_type;
                $types_count[$prepaid_data->tps_id]['title'] = $prepaid_data->title;
                $types_count[$prepaid_data->tps_id]['price'] = $prepaid_data->price;
                $types_count[$prepaid_data->tps_id]['pax'] = isset($prepaid_data->pax) ? (int) $prepaid_data->pax : (int) 0;
                $types_count[$prepaid_data->tps_id]['capacity'] = isset($prepaid_data->capacity) ? (int) $prepaid_data->capacity : (int) 1;
                $types_count[$prepaid_data->tps_id]['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : '';
                $types_count[$prepaid_data->tps_id]['end_date'] = isset($capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : '';
                $types_count[$prepaid_data->tps_id]['count'] = 1;
                $total_amount = $prepaid_data->price;
            }
            //prepare final array for response
            $arrReturn = array();
            $arrReturn['show_scanner_receipt'] = ($is_redeem == 1) ? $show_scanner_receipt : 0;
            $arrReturn['receipt_details'] = ($show_scanner_receipt == 1 && $is_redeem == 1) ? $receipt_data : (object)array();
            if ($prepaid_data->channel_type == 5) {
                $arrReturn['pax'] = isset($prepaid_data->pax) ? (int) $prepaid_data->pax : (int) 0;
                $arrReturn['capacity'] = isset($prepaid_data->capacity) ? (int) $prepaid_data->capacity : (int) 1;
                $arrReturn['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : '';
                $arrReturn['end_date'] = isset($capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : '';
            }
            $arrReturn['is_prepaid'] = 1;
            $arrReturn['title'] = $prepaid_data->title;
            $arrReturn['ticket_type'] = (!empty($this->types[strtolower($prepaid_data->ticket_type)]) && ($this->types[strtolower($prepaid_data->ticket_type)] > 0)) ? $this->types[strtolower($prepaid_data->ticket_type)] : 10;
            $arrReturn['ticket_type_label'] = $prepaid_data->ticket_type;
            $arrReturn['age_group'] = $prepaid_data->age_group;
            if ($capacity[0]['is_reservation'] == 1) {
                $arrReturn['is_voucher'] = $is_voucher;
                $arrReturn['own_capacity_id'] = !empty($capacity[0]['own_capacity_id']) ? (int) $capacity[0]['own_capacity_id'] : (int) 0;
                $arrReturn['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0;
                $arrReturn['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
                $arrReturn['from_time'] = $prepaid_data->from_time;
                $arrReturn['to_time'] = $prepaid_data->to_time;
                $arrReturn['selected_date'] = $prepaid_data->selected_date;
                $arrReturn['slot_type'] = !empty($prepaid_data->timeslot) ? $prepaid_data->timeslot : '';
                $arrReturn['is_redeem'] = ($is_redeem != null) ? $is_redeem : 0;
            }
            $arrReturn['channel_type'] = !empty($prepaid_data->channel_type) ? (int) $prepaid_data->channel_type : (int) 0;
            $arrReturn['price'] = $prepaid_data->price;
            $arrReturn['group_price'] = 0;
            $arrReturn['visitor_group_no'] = (string) $prepaid_data->visitor_group_no;
            $arrReturn['ticket_id'] = $prepaid_data->ticket_id;
            $arrReturn['tps_id'] = $prepaid_data->tps_id;
            $arrReturn['hotel_name'] = !empty($prepaid_data->hotel_name) ? $prepaid_data->hotel_name : '';
            $arrReturn['address'] = '';
            $arrReturn['cashier_name'] = $prepaid_data->cashier_name;
            $arrReturn['pos_point_name'] = $prepaid_data->pos_point_name;
            $arrReturn['tax'] = $prepaid_data->tax;
            $arrReturn['count'] = 1;
            $arrReturn['from_time'] = $prepaid_data->from_time;
            $arrReturn['to_time'] = $prepaid_data->to_time;
            $arrReturn['slot_type'] = !empty($prepaid_data->timeslot) ? $prepaid_data->timeslot : '';
            $arrReturn['total_amount'] = $total_amount;
            $arrReturn['early_checkin_time'] = $prepaid_data->early_checkin_time;
            $arrReturn['types_count'] = !empty($types_count) ? array_values($types_count) : array();
            $arrReturn['show_extend_button'] = ($prepaid_data->is_scan_countdown == '1' && !empty($prepaid_data->upsell_ticket_ids)) ? 1 : 0;
            $arrReturn['upsell_ticket_ids'] = $prepaid_data->upsell_ticket_ids;
            $arrReturn['pass_no'] = $pass_no;
            $arrReturn['is_scan_countdown'] = $prepaid_data->is_scan_countdown;
            $additional_information = unserialize($prepaid_data->additional_information);
            if ($additional_information['add_to_pass'] != 1 && empty($prepaid_data->scanned_at)) {
                $arrReturn['countdown_interval'] = '';
            } else {
                $arrReturn['countdown_interval'] = !empty($prepaid_data->countdown_interval) ? $prepaid_data->countdown_interval : '';
                if (empty($prepaid_data->scanned_at)) {
                    $arrReturn['countdown_interval'] = '';
                }
            }
            if (!empty($prepaid_data->countdown_interval) && ($is_redeem == 1 || $is_edited == 0)) {
                $countdown_time = explode('-', $prepaid_data->countdown_interval);
                $countdown_period = $countdown_time[0];
                $countdown_text = $countdown_time[1];
                $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_period);
                $left_time_user_format = $this->get_arrival_status_from_time($gmt_device_time, $prepaid_data->scanned_at, $current_count_down_time);
                $difference = $gmt_device_time - $prepaid_data->scanned_at;
                if ($current_count_down_time > $difference || $current_count_down_time == $difference || empty($prepaid_data->scanned_at)) {
                    $arrReturn['countdown_interval'] = !empty($left_time_user_format) ? $left_time_user_format : '';
                } else {
                    $arrReturn['is_late'] = 1;
                    $arrReturn['countdown_interval'] = $left_time_user_format . ' late';
                    $arrReturn['show_extend_button'] = 0;
                }
            } else if ($prepaid_data->channel_type == 5 && $is_edited == 2) {
                $logs['current_day__$selected_date_check__fromtime__totime__device_time'] = array($current_day, $selected_date_check, $from_time_check, $to_time_check, $device_time);
                // Code begins to get the reservation time notification

                $from_date_time = $selected_date_check . " " . $from_time_check;
                $to_date_time = $selected_date_check . " " . $to_time_check;
                $arrival_time = $this->get_arrival_time_from_slot('', $from_date_time, $to_date_time, $timeslot, $device_time);
                $arrReturn['is_late'] = ($arrival_time['arrival_status'] == 1) ? 0 : 1;  //arrival_status == 1 is for early
                $arrReturn['countdown_interval'] = ($arrival_time['arrival_msg']) ? $arrival_time['arrival_msg'] : '';
            } else {
                $arrReturn['is_late'] = 0;
                $arrReturn['countdown_interval'] = '';
            }
            if ($prepaid_data->allow_reservation_entry == '1') {
                $arrReturn['is_late'] = 0;
                $arrReturn['countdown_interval'] = isset($arrReturn['countdown_interval']) ? $arrReturn['countdown_interval'] : $prepaid_data->countdown_interval;
            }
            $arrReturn['hotel_name'] = !empty($prepaid_data->hotel_name) ? $prepaid_data->hotel_name : '';
            $arrReturn['scanned_at'] = date('d/m/Y H:i:s', $scanned_at);
            $arrReturn['upsell_left_time'] = ($current_count_down_time + $prepaid_data->scanned_at) . '_' . $countdown_text . '_' . $prepaid_data->scanned_at;
            $arrReturn['multi_redeem'] = $redeem_all_passes_on_one_scan;
            //FINAL prepaid response
            $response = array();
            $response['data'] = $arrReturn;
            $response['is_ticket_listing'] = 0;
            $response['add_to_pass'] = (int) $add_to_pass;
            $response['is_early_reservation_entry'] = $is_early_reservation_entry;
            if ($is_reservation_check == 1) {
                $response['is_cluster'] = $is_cluster;
            }
            $response['is_prepaid'] = 0;
            $response['status'] = $status;
            $response['message'] = $message;
            $MPOS_LOGS['response_after_confirm_prepaid_listed_ticket_'.date('H:i:s')] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['response_after_confirm_prepaid_listed_ticket'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : get_extended_tickets_listing().
     * @Purpose  : show ticket listing for upsell tickets
     * @Called   : called when tap to extend button on CSS APP.
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 16 March 2018
     */
    function get_extended_tickets_listing($request_data) {
        global $MPOS_LOGS;
        try {
            $logs_data = $request_data;
            unset($logs_data['user_detail']);
            //define empty element must to avoid object/array issue in android end        
            $data = array();
            $is_ticket_listing = 1;
            $is_prepaid = 1;
            $is_extended = 1;
            if (1) {
                $upsell_ticket_ids = explode(',', $request_data['upsell_ticket_ids']);
                // Get ticket details of tickets which can be extended
                $tickets_detail = $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle as title, countdown_interval', 'where' => 'mec_id in ('. implode(',', $this->primarydb->db->escape($upsell_ticket_ids)).') and active = "1" and deleted = "0"'), 'object');
                $logs['query from modeventcontent_' . date('H:i:s')] = $this->primarydb->db->last_query();
                if (!empty($tickets_detail)) {
                    $all_tickets = array();
                    if (empty($request_data['visitor_group_no'])) {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => 'visitor_group_no', 'where' => 'passNo = "'.$this->primarydb->db->escape($request_data['pass_no']).'" and activated = 1', 'limit' => 1), 'row_object');
                    }

                    foreach ($tickets_detail as $ticket_fields) {
                        $all_tickets[$ticket_fields->mec_id] = $ticket_fields;
                    }
                    //make details array
                    foreach ($all_tickets as $ticket_data) {
                        $ticket_detail = array();
                        $ticket_detail['title'] = $ticket_data->title;
                        $ticket_detail['ticket_id'] = $ticket_data->mec_id;
                        $ticket_detail['main_ticket_id'] = $request_data['main_ticket_id'];
                        $ticket_detail['visitor_group_no'] = empty($request_data['visitor_group_no']) ? $prepaid_data->visitor_group_no : $request_data['visitor_group_no'];
                        $ticket_detail['pass_no'] = (string) $request_data['pass_no'];
                        $ticket_detail['countdown_interval'] = $ticket_data->countdown_interval;
                        $ticket_detail['upsell_left_time'] = $request_data['upsell_left_time'];
                        $ticket_detail['upsell_ticket_ids'] = $request_data['upsell_ticket_ids'];
                        $ticket_detail['types'] = array();
                        if (!empty($ticket_detail)) {
                            //replace null with blank
                            array_walk_recursive($ticket_detail, function (&$item, $key) {
                                $item = (empty($item) && is_array($item)) ? array() : $item;
                                if ($key == 'price') {
                                    $item = $item ? (float) $item : $item;
                                }
                                if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no' && $key != 'upsell_ticket_ids') {
                                    $item = is_numeric($item) ? (int) $item : $item;
                                }
                                $item = null === $item ? '' : $item;
                            });
                        }
                        $ticket_listing[$ticket_data->mec_id] = $ticket_detail;
                    }
                    sort($ticket_listing);
                    $data = !empty($ticket_listing) ? $ticket_listing : array();
                    $status = !empty($ticket_listing) ? 1 : 0;
                    $message = !empty($ticket_listing) ? 'Success' : 'This ticket have not upsell option.';
                } else {
                    $status = 0;
                    $message = array_search(strtolower($request_data['ticket_type']), $this->types) . ' type not exist';
                }
            }
            $logs['ticket_listing'] = $ticket_listing;
            $response = array('status' => $status, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, 'is_extended' => $is_extended, "show_cluster_extend_option" => 0, "cluster_main_ticket_details" => (object) array(), 'message' => $message, 'data' => $data);
        } catch (\Exception $e) {
            $MPOS_LOGS['get_extended_tickets_listing'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['get_extended_tickets_listing'] = $logs;
        return $response;
    }

    /**
     * @Name     : choose_upsell_types().
     * @Purpose  : to extende linked ticket insert new entry in main table
     * @Called   : called when tap to extend button on CSS APP.
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018
     */
    function choose_upsell_types($visitor_group_no = '', $main_ticket_id = 0, $ticket_id = 0, $upsell_ticket_ids = '', $pass_no = '') {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            //get data from prepaid_tickets
            if (!empty($pass_no)) {
		$where = 'visitor_group_no = "'.$visitor_group_no.'" and ((channel_type != "5") or (channel_type = "5" and (passNo = "' . $pass_no . '" or bleep_pass_no = "' . $pass_no . '"))) and ';
	    } else {
		$where = 'visitor_group_no = "'.$visitor_group_no.'" and ';
	    }
            
            $select = 'ticket_id, tps_id, ticket_type, age_group, visitor_group_no, passNo, bleep_pass_no, scanned_at, additional_information, timezone';
            $where .= 'ticket_id = '.$main_ticket_id.' and activated = "1" and (order_status = "0" or order_status = "2" or order_status = "3") and is_refunded != "1"';
            $pt_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');
            $logs['pt_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $status = 0;
            $is_ticket_listing = 1;
            $is_prepaid = 1;
            $message = 'Data Not Found';
            $data = array();
            $cluster_main_ticket_details = (object) array();
            if (!empty($pt_data)) {
                $status = 0;
                $message = 'Upsell tickets not linked.';
                $ticket_lisitng = array();
                $total_count = array();
                $ticket_ids = array();

                foreach ($pt_data as $pass) {
                    $ticket_ids[$pass->ticket_id] = $pass->ticket_id;
                    $total_count[$pass->ticket_id] += 1;
                    $additional_information = unserialize($pass->additional_information);
                    $timezone = $pass->timezone;
                }
                $start_date = (date("Y-m-d", strtotime($additional_information['start_date'])));
                $end_date = (date("Y-m-d", strtotime($additional_information['end_date'])));

                $upsell_ticket_ids_array = array();
                $upsell_ticket_ids_array[] = $ticket_id;
                $upsell_ticket_ids_array[] = $main_ticket_id;
                $logs['start_date, end_date and upsell ticket ids'] = array($start_date, $end_date, $upsell_ticket_ids_array);
                $this->primarydb->db->select('mec.mec_id, mec.postingEventTitle as title, mec.is_scan_countdown , mec.allow_city_card, mec.countdown_interval, tps.id as tps_id, tps.ticketType, tps.agefrom, tps.ageto, tps.pricetext, tps.ticket_tax_value', false);
                $this->primarydb->db->from("modeventcontent mec");
                $this->primarydb->db->join("ticketpriceschedule tps", 'mec.mec_id=tps.ticket_id', 'right');
                $this->primarydb->db->where("tps.deleted", '0');
                $this->primarydb->db->where_in("mec_id", $upsell_ticket_ids_array);
                if (isset($additional_information['start_date']) && isset($additional_information['end_date']) && date("Y", strtotime($additional_information['end_date'])) != 2286) {
                    $this->primarydb->db->where("DATE_FORMAT(FROM_UNIXTIME(tps.start_date + " . ($timezone * 3600) . "), '%Y-%m-%d') = '" . $start_date . "'");
                    $this->primarydb->db->where("(DATE_FORMAT(FROM_UNIXTIME(tps.end_date + " . ($timezone * 3600) . "), '%Y-%m-%d') = '" . $end_date . "') or tps.end_date = '9999999999'");
                }

                $mod_query = $this->primarydb->db->get();
                $tickets_data = array();
                $basic_tickets = array();
                if ($mod_query->num_rows() > 0) {
                    $ticket_status = $mod_query->result();
                    foreach ($ticket_status as $ticket) {
                        if ($ticket->mec_id == $ticket_id) {
                            $tickets_data[$ticket->ticketType . '_' . $ticket->agefrom . '-' . $ticket->ageto] = $ticket;
                        } else {
                            $add_to_pass = $ticket->allow_city_card;
                        }
                        $basic_tickets[$ticket->mec_id] = $ticket;
                    }
                }
                $logs['modeventcontent query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                $internal_log['tickets data'] = $tickets_data;
                $type_details = array();
                foreach ($pt_data as $prepaid) {
                    $tps_type = (!empty($this->types[strtolower($prepaid->ticket_type)]) && ($this->types[strtolower($prepaid->ticket_type)] > 0)) ? $this->types[strtolower($prepaid->ticket_type)] : 10;
                    $ticket_type_key = $tps_type . '_' . $prepaid->age_group;
                    $price = $tickets_data[$ticket_type_key]->pricetext;
                    $prepaid->bleep_pass_no = !empty($prepaid->bleep_pass_no) ? $prepaid->bleep_pass_no : $prepaid->passNo;
                    $type_details[] = array(
		    'main_tps_id' => $prepaid->tps_id, 
		    'tps_id' => $tickets_data[$ticket_type_key]->tps_id, 
		    'ticket_type' => (!empty($this->types[strtolower($prepaid->ticket_type)]) && ($this->types[strtolower($prepaid->ticket_type)] > 0)) ? $this->types[strtolower($prepaid->ticket_type)] : 10, 
                     'ticket_type_label' => $prepaid->ticket_type,
		    'age_group' => $prepaid->age_group, 
		    'price' => $price, 
		    'bleep_pass_no' => (string) $prepaid->bleep_pass_no, 
		    'tax' => $tickets_data[$ticket_type_key]->ticket_tax_value);
                }
                $type_details = $this->common_model->sort_ticket_types($type_details);
                $type_details = array_values($type_details);
                foreach ($pt_data as $prepaid) {
                    $status = 1;
                    $message = 'Success';
                    $ticket_detail = array();
                    $types_label = (!empty($this->types[strtolower($prepaid->ticket_type)]) && ($this->types[strtolower($prepaid->ticket_type)] > 0)) ? $this->types[strtolower($prepaid->ticket_type)] : 10;
                    $ticket_type_key = $types_label . '_' . $prepaid->age_group;
                    $ticket_detail['title'] = $tickets_data[$ticket_type_key]->title;
                    $ticket_detail['visitor_group_no'] = $prepaid->visitor_group_no;
                    $ticket_detail['main_ticket_id'] = $main_ticket_id;
                    $ticket_detail['ticket_id'] = $ticket_id;
                    $ticket_detail['is_scan_countdown'] = $tickets_data[$ticket_type_key]->is_scan_countdown;
                    $ticket_detail['countdown_interval'] = $tickets_data[$ticket_type_key]->countdown_interval;
                    $ticket_detail['upsell_ticket_ids'] = $upsell_ticket_ids;
                    $ticket_detail['pass_no'] = $prepaid->passNo;
                    $ticket_detail['types'] = $type_details;
                    $ticket_detail['add_to_pass'] = $add_to_pass;
                    $countdown_time = explode('-', $basic_tickets[$prepaid->ticket_id]->countdown_interval);
                    $countdown_period = $countdown_time[0];
                    $countdown_text = $countdown_time[1];
                    $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_period);
                    if (empty($prepaid->scanned_at)) {
                        $ticket_detail['upsell_left_time'] = $current_count_down_time . '_' . $countdown_text . '_' . $prepaid->scanned_at;
                    } else {
                        $ticket_detail['upsell_left_time'] = ($current_count_down_time + $prepaid->scanned_at) . '_' . $countdown_text . '_' . $prepaid->scanned_at;
                    }
                    $ticket_lisitng[0] = $ticket_detail;
                }
                sort($ticket_lisitng);
                if (!empty($tickets_data[$ticket_type_key]->title)) {
                    //replace null with blank
                    array_walk_recursive($ticket_lisitng, function (&$item, $key) {
                        $item = (empty($item) && is_array($item)) ? array() : $item;
                        if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no' && $key != 'upsell_ticket_ids' && $key != 'bleep_pass_no') {
                            $item = is_numeric($item) ? (int) $item : $item;
                        }
                        if ($key == 'price') {
                            $item = $item ? (float) $item : $item;
                        }
                        $item = null === $item ? '' : $item;
                    });
                    $data = !empty($ticket_lisitng) ? $ticket_lisitng : array();
                } else {
                    $status = 0;
                    $is_ticket_listing = 1;
                    $is_prepaid = 1;
                    $message = 'Upsell Linked ticket is not valid.';
                    $data = array();
                }
            }
            $response = array('status' => $status, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, "is_order_listing" => 1, 'message' => $message, "show_cluster_extend_option" => 0, "cluster_main_ticket_details" => $cluster_main_ticket_details, 'data' => $data);
        } catch (\Exception $e) {
            $MPOS_LOGS['choose_upsell_types'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
            $MPOS_LOGS['choose_upsell_types'] = $logs;
            if (!empty($internal_log)) {
                $internal_logs['choose_upsell_types'] = $internal_log;
            }
            return $response;
    }

    /**
     * @Name     : extended_linked_ticket().
     * @Purpose  : to extende linked ticket insert new entry in main table
     * @Called   : called when tap to extend button on CSS APP.
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018
     */
    function extended_linked_ticket($request_data) {
        global $MPOS_LOGS;
        try {
            $logs_data = $request_data;
            $current_time = strtotime(gmdate('m/d/Y H:i:s'));
            unset($logs_data['user_detail']);
            $res = $request_data['user_detail'];
            //define empty element must to avoid object/array issue in android end        
            $data = array();
            $is_ticket_listing = 0;
            $is_prepaid = $request_data['is_prepaid'];

            if($res->cashier_type == '3') {
                $request_data['cashier_id'] = $res->reseller_cashier_id;
                $request_data['cashier_name'] = $res->reseller_cashier_name;
            } else {
                $request_data['cashier_id'] = $res->uid;
                $request_data['cashier_name'] = $res->fname . ' ' . $res->lname;
            }
           
            if (1) {
                $status = 1;
                $message = 'Valid';

                $countdown_time = explode('-', $request_data['countdown_interval']);
                $countdown_period = $countdown_time[0];
                $countdown_text = $countdown_time[1];

                $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_period);
                if (empty($current_count_down_time)) {
                    $current_count_down_time = 0;
                }

                $upsell_left_time = explode('_', $request_data['upsell_left_time']);
                $actual_scanned_time = $upsell_left_time[2];

                $difference = $current_time - $actual_scanned_time;
                $left_time_user_format = $this->get_arrival_status_from_time($current_time, $actual_scanned_time, $current_count_down_time);
                $data['countdown_interval'] = $left_time_user_format;
                if (empty($actual_scanned_time) || ( ((int) $actual_scanned_time + (int) $current_count_down_time) >= $difference )) {
                    $data['is_late'] = 0;
                } else {
                    $data['is_late'] = 1;
                }

                $types_count = array();
                $total_amount = 0;
                $where_string = '';
                if (!empty($request_data['ticket_types'])) {
                    $loop = 0;
                    foreach ($request_data['ticket_types'] as $extended_ticket_types) {
                        ++$loop;
                        $types_count[$extended_ticket_types['tps_id']]['ticket_type'] = $extended_ticket_types['ticket_type'];
                        $types_count[$extended_ticket_types['tps_id']]['ticket_type_label'] = isset($extended_ticket_types['ticket_type_label']) ? $extended_ticket_types['ticket_type_label'] : '';
                        $types_count[$extended_ticket_types['tps_id']]['title'] = $request_data['title'];
                        $types_count[$extended_ticket_types['tps_id']]['price'] = $extended_ticket_types['price'];
                        $types_count[$extended_ticket_types['tps_id']]['tax'] = $extended_ticket_types['tax'];
                        $types_count[$extended_ticket_types['tps_id']]['count'] += 1;
                        $total_amount += $types_count[$extended_ticket_types['tps_id']]['price'];
                        $main_tps_id = $extended_ticket_types['main_tps_id'];
                        $bleep_pass_no = $extended_ticket_types['bleep_pass_no'];
                        if ($loop == 1) {
                            $where_string .= '( (tps_id ="' . $main_tps_id . '" and ( passNo="' . $bleep_pass_no . '" or bleep_pass_no="' . $bleep_pass_no . '" ) ) ';
                        } else if (count($request_data['ticket_types']) == $loop) {
                            $where_string .= ' or (tps_id ="' . $main_tps_id . '" and ( passNo="' . $bleep_pass_no . '" or bleep_pass_no="' . $bleep_pass_no . '" )) )';
                        } else {
                            $where_string .= ' or (tps_id ="' . $main_tps_id . '" and ( passNo="' . $bleep_pass_no . '" or bleep_pass_no="' . $bleep_pass_no . '" ) ) ';
                        }
                        if ($loop == 1 && count($request_data['ticket_types']) == $loop) {
                            $where_string = '( (tps_id ="' . $main_tps_id . '" and ( passNo="' . $bleep_pass_no . '" or bleep_pass_no="' . $bleep_pass_no . '" ) ) ) ';
                        }
                    }
                }

                $select = 'prepaid_ticket_id, ticket_id, action_performed, is_combi_ticket, is_addon_ticket, tps_id, ticket_type, age_group, clustering_id, title, price, hotel_id, ticket_type, activation_method, shift_id, pos_point_id,shift_id, visitor_group_no, created_date_time, hotel_name, tax, cashier_id, cashier_name, pos_point_name, selected_date, from_time, to_time, timeslot';
                $where = 'visitor_group_no = '.$request_data['visitor_group_no'].' and is_addon_ticket = "0" and activated = 1 and ticket_id = '.$request_data['main_ticket_id'];
                if (!empty($where_string)) {
                    $where .= ' and '.$where_string;
                }
                
                $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where), 'object');
                
                $logs['prepaid query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                $count = 0;
                $is_whole_order_upsell = 0;
                $types_not_matched = 0;

                if (!empty($prepaid_data)) {
                    $pt_data = array();
                    foreach ($prepaid_data as $prepaid) {
                        ++$count;
                        $pt_data = $prepaid;
                    }

                    /* If extended ticket types not matched with scaneed ticket. */
                    if ($types_not_matched) {
                        $response = array('status' => 0, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, 'message' => 'Extended Linked ticket types not matched.', 'data' => array());
                        $logs['response'] = $response;
                        $MPOS_LOGS['extended_linked_ticket_' . date('H:i:s')] = $logs;
                        return $response;
                    }
                    $user_details = reset($this->common_model->find('users', array('select' => 'fcm_tokens, firebase_user_id', 'where' => 'id = "' . $request_data['disributor_cashier_id'] . '" and deleted = "0"')));
                    $data['title'] = $pt_data->title;
                    $data['guest_notification'] = '';
                    $data['price'] = $pt_data->price;
                    $data['total_amount'] = (float) $total_amount;
                    $data['count'] = $count;
                    $data['ticket_type'] = (!empty($this->types[trim(strtolower($pt_data->ticket_type))]) && ($this->types[trim(strtolower($pt_data->ticket_type))] > 0)) ? $this->types[trim(strtolower($pt_data->ticket_type))] : 10;
                    $data['ticket_type_label'] = $pt_data->ticket_type;
                    $data['payment_type'] = $request_data['payment_type'];
                    $data['pos_point_id'] = !empty($request_data['pos_point_id']) ? $request_data['pos_point_id'] : $pt_data->pos_point_id;
                    $data['shift_id'] = !empty($request_data['shift_id']) ? $request_data['shift_id'] : $pt_data->shift_id;
                    $data['visitor_group_no'] = $pt_data->visitor_group_no;
                    $data['address'] = '';
                    $data['email'] = '';
                    $data['tax'] = $pt_data->tax;
                    $data['hotel_name'] = $pt_data->hotel_name;
                    $data['pos_point_name'] = !empty($request_data['pos_point_name']) ? $request_data['pos_point_name'] : $pt_data->pos_point_name;
                    $data['cashier_id'] = (int) $request_data['disributor_cashier_id'];
                    $data['cashier_name'] = $request_data['disributor_cashier_name'];
                    $data['firebase_uid'] = (string) $user_details['firebase_user_id'];
                    $data['tax'] = $pt_data->tax;
                    $data['selected_date'] = !empty($pt_data->selected_date) ? $pt_data->selected_date : '';
                    $data['slot_type'] = !empty($pt_data->timeslot) ? $pt_data->timeslot : '';
                    $data['from_time'] = !empty($pt_data->from_time) ? $pt_data->from_time : '';
                    $data['to_time'] = !empty($pt_data->to_time) ? $pt_data->to_time : '';
                    $data['order_date'] = date('Y-m-d h:i:s A', strtotime($pt_data->created_date_time));
                }
                $data['types_count'] = !empty($types_count) ? array_values($types_count) : array();

                if (!empty($request_data)) {
                    $request_data['where_string'] = $where_string;
                    $request_array['extended_linked_ticket_from_css_app']['detail'] = $request_data;
                    $request_array['extended_linked_ticket_from_css_app']['is_whole_order'] = $is_whole_order_upsell;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "extended_linked_ticket";
                    $request_array['visitor_group_no'] = $pt_data->visitor_group_no;
                    $logs['data_to_queue_UPDATE_DB_ARN_'.date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                    }
                }
            }
            if (!empty($data)) {
                //replace null with blank
                array_walk_recursive($data, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no' && $key != 'upsell_ticket_ids') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }
                    if ($key == 'price') {
                        $item = $item ? (float) $item : $item;
                    }
                    $item = null === $item ? '' : $item;
                });
            }
            $response = array('status' => $status, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, 'message' => $message, 'data' => $data);
        } catch (\Exception $e) {
            $MPOS_LOGS['extended_linked_ticket'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['extended_linked_ticket'] = $logs;
        return $response;
    }

    /*
     * @Name     : extended_linked_ticket().
     * @Purpose  : to extende linked ticket insert new entry in main table
     * @Called   : called when tap to extend button on CSS APP.
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018
     */
    function order_details($visitor_group_no = '', $museum_id = 0, $user_detail = array(),$channel_type = '',$pass_no = '') {
        global $MPOS_LOGS;
        try {
            //get data from prepaid_tickets            
            $where = 'visitor_group_no = '.$visitor_group_no.' and activated = "1" and is_addon_ticket = "0" and (order_status = "0" or order_status = "2" or order_status = "3") and is_refunded != "1"';
            if ($user_detail->add_city_card == 0) {
                $where .= ' and museum_id ='.$museum_id;
            }
            if ($channel_type == 5) {
                $where .= ' and (passNo="' . $pass_no . '" or bleep_pass_no="' . $pass_no . '")';
            }
            $pt_data = $this->find('prepaid_tickets', array('select' => 'ticket_id, tps_id, title, price, ticket_type, age_group, hotel_name, tax, visitor_group_no, passNo, scanned_at, timezone', 'where' => $where), 'object');
            
            $logs['pt_qyery'] = $this->primarydb->db->last_query();
            $status = 0;
            $is_ticket_listing = 1;
            $is_prepaid = 1;
            $message = 'Data Not Found';
            $data = array();
            $cluster_main_ticket_details = (object) array();
            if (!empty($pt_data)) {
                $status = 0;
                $message = 'Upsell tickets not linked.';
                $ticket_lisitng = array();
                $total_count = array();
                $ticket_ids = array();

                foreach ($pt_data as $pass) {
                    $ticket_ids[$pass->ticket_id] = $pass->ticket_id;
                    $total_count[$pass->ticket_id] += 1;
                }
                
                $ticket_status = $this->find('modeventcontent', array('select' => 'mec_id, allow_city_card, is_scan_countdown, countdown_interval, upsell_ticket_ids, timezone', 'where' => 'mec_id in ('. implode(',', $ticket_ids).')'), 'object');
                $tickets_data = array();
                if (!empty($ticket_status)) {
                    foreach ($ticket_status as $ticket) {
                        $tickets_data[$ticket->mec_id] = $ticket;
                    }
                }
                $logs['mec_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                $logs['mec_data'] = $tickets_data;

                foreach ($pt_data as $prepaid) {
                    if (empty($tickets_data[$prepaid->ticket_id]->upsell_ticket_ids)) {
                        continue;
                    }
                    $status = 1;
                    $message = 'Success';
                    $ticket_detail = array();
                    $ticket_detail['hotel_name'] = $prepaid->hotel_name;
                    $ticket_detail['title'] = $prepaid->title;
                    $ticket_detail['visitor_group_no'] = $prepaid->visitor_group_no;
                    $ticket_detail['ticket_id'] = $prepaid->ticket_id;
                    $ticket_detail['add_to_pass'] = (int)$ticket_status[0]->allow_city_card;
                    $ticket_detail['is_scan_countdown'] = $tickets_data[$prepaid->ticket_id]->is_scan_countdown;
                    $ticket_detail['countdown_interval'] = $tickets_data[$prepaid->ticket_id]->countdown_interval;
                    $ticket_detail['total_count'] = $total_count[$prepaid->ticket_id];
                    $ticket_detail['citysightseen_card'] = 0;
                    $ticket_detail['show_extend_button'] = 1;
                    $current_time = strtotime(gmdate("Y-m-d H:i", time() + ($tickets_data[$prepaid->ticket_id]->timezone * 3600)));
                    $todayDateTime = date('Y-m-d H:i',$current_time);
                    $ticket_detail['upsell_ticket_ids'] = $tickets_data[$prepaid->ticket_id]->upsell_ticket_ids;
                    $ticket_detail['upsell_ticket_ids'] = $this->checkUpsellTicketIdsIfNotExpired($tickets_data[$prepaid->ticket_id]->upsell_ticket_ids, $todayDateTime);
                    $ticket_detail['pass_no'] = $prepaid->passNo;
                    $ticket_detail['types'] = array();
                    $countdown_time = explode('-', $tickets_data[$prepaid->ticket_id]->countdown_interval);
                    $countdown_period = $countdown_time[0];
                    $countdown_text = $countdown_time[1];
                    $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_period);
                    if (empty($prepaid->scanned_at)) {
                        $ticket_detail['upsell_left_time'] = $current_count_down_time . '_' . $countdown_text . '_' . $prepaid->scanned_at;
                    } else {
                        $ticket_detail['upsell_left_time'] = ($current_count_down_time + $prepaid->scanned_at) . '_' . $countdown_text . '_' . $prepaid->scanned_at;
                    }
                    $ticket_lisitng[$prepaid->ticket_id] = $ticket_detail;
                }
                sort($ticket_lisitng);
                //replace null with blank
                array_walk_recursive($ticket_lisitng, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no' && $key != 'price' && $key != 'pass_no' && $key != 'upsell_ticket_ids') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }
                    if ($key == 'price') {
                        $item = $item ? (float) $item : $item;
                    }
                    $item = null === $item ? '' : $item;
                });
                $data = !empty($ticket_lisitng) ? $ticket_lisitng : array();
            }
            $response = array('status' => $status, 'is_ticket_listing' => $is_ticket_listing, 'is_prepaid' => $is_prepaid, "is_order_listing" => 1, 'message' => $message, "show_cluster_extend_option" => 0, "cluster_main_ticket_details" => $cluster_main_ticket_details, 'data' => $data);
        } catch (\Exception $e) {
            $MPOS_LOGS['order_details'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['order_details'] = $logs;
        return $response;
    }

    /*
     * @name   : get_postpaid_listing()
     * @Purpose  : to get types listing of prebooked tickets in case of postpaid pass scan
     * @Called   : called when user clicks on group checkin or one by one checkin button after pre booked ticket details
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on date 20 june 2019
     */
    function get_postpaid_listing($data = array()) {
        try {
            global $MPOS_LOGS;
  
            $action = $data['action']; //one by one checkin (1) or group checkin (2)
            $vgn = $data['visitor_group_no'];
            $ticket_id = $data['ticket_id']; //pre-booked ticket id
            $museum_id = $data['museum_id'];
            $formatted_device_time = $data['formatted_device_time'];
            $device_time = $data['device_time'];
            $device_time_check = $device_time / 1000;
            $select = 'supplier_price, ticket_type, tps_id, ticket_id, used, prepaid_ticket_id, timeslot, extra_discount, channel_type, combi_discount_gross_amount, pax, capacity, clustering_id, price, age_group, timezone, selected_date, from_time, to_time, hotel_name, title, selected_date, from_time, to_time, visitor_group_no, cashier_name, pos_point_name, tax, is_invoice';
            $where = 'visitor_group_no = "' . $vgn . '" and ticket_id = "' . $ticket_id . '" and is_refunded != "1" and activated = "1" and (order_status = "0" or order_status = "2" or order_status = "3") ';
            if ($museum_id > 0) {
                $where .= 'and museum_id = "' . $museum_id . '"';
            }
            $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => $where, 'order_by' => 'created_date_time DESC'), 'object');
            $logs['prepaid_query_' . date('H:i:s')] = array($this->primarydb->db->last_query());
            $postpaid_scan = 1;
            if (!empty($prepaid_data)) { //data of pre booked ticket
                if ($action == 1) { //one by one checkin listing
                    $res_data = $this->api_model->get_prepaid_single_ticket_details($postpaid_scan, $prepaid_data, $device_time_check);
                    $res_data['show_one_by_one_entry_allowed'] = false;
                    $res_data['checkin_action'] = 3;
                    $res_data['allow_adjustment'] = 1;
                } else if ($action == 2) { //group checkin listing
                    $res_data = $this->api_model->get_prepaid_group_ticket_details($postpaid_scan, $prepaid_data, $formatted_device_time);
                    $res_data['show_one_by_one_entry_allowed'] = true;
                    $res_data['checkin_action'] = 4;
                    $res_data['allow_adjustment'] = 0;
                }
                $res_data['is_editable'] = 0;
                $log = array_merge($logs, $MPOS_LOGS);
                unset($log['API'], $log['headers'], $log['request'], $MPOS_LOGS['get_prepaid_single_ticket_details'], $MPOS_LOGS['get_prepaid_group_ticket_details']);
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['get_postpaid_listing'] = $log;
        return $res_data;
    }
   
    /*
     * sync_voucher_scans : called to update voucher_scans node on firebase 
     * at present we are using it in scan_pass and confirm_pass only
     * created by : Vaishali Raheja <vaishali.intersoft@gmail.com> on 9 Sep 2019
     */
    public function sync_voucher_scans($pt_data = array(), $basic_data = array()) {
        global $MPOS_LOGS;
        extract($basic_data);
        if (!empty($pt_data)) {
            /* Set values to sync bookings on firebase. */
            foreach ($pt_data as $prepaid_ticket) {
                $third_party_response_data = json_decode($prepaid_ticket['third_party_response_data'], true);
                $visitor_group_no = $prepaid_ticket['visitor_group_no'];
                $selected_date = ($prepaid_ticket['selected_date'] != 0 && $prepaid_ticket['selected_date'] != '') ? $prepaid_ticket['selected_date'] : '';
                $from_time = ($prepaid_ticket['from_time'] != '' && $prepaid_ticket['from_time'] != '0') ? $prepaid_ticket['from_time'] : "";
                $to_time = ($prepaid_ticket['to_time'] != 0 && $prepaid_ticket['to_time'] != '') ? $prepaid_ticket['to_time'] : '';
                $sync_key = base64_encode($prepaid_ticket['visitor_group_no'] . '_' . $prepaid_ticket['ticket_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $prepaid_ticket['created_date_time']);

                $ticket_types[$sync_key][$prepaid_ticket['tps_id']] = array(
                    'tps_id' => (int) $prepaid_ticket['tps_id'],
                    'age_group' => $prepaid_ticket['age_group'],
                    'price' => (float) $prepaid_ticket['price'],
                    'net_price' => (float) $prepaid_ticket['net_price'],
                    'type' => ucfirst(strtolower($prepaid_ticket['ticket_type'])),
                    'quantity' => (int) $ticket_types[$sync_key][$prepaid_ticket['tps_id']]['quantity'] + 1,
                    'combi_discount_gross_amount' => (float) $prepaid_ticket['combi_discount_gross_amount'],
                    'refund_quantity' => (int) 0,
                    'refunded_by' => array(),
                    'per_age_group_extra_options' => array(),
                );
                $bookings[$sync_key]['amount'] = (float) ($bookings[$sync_key]['amount'] + ((float) $prepaid_ticket['price']) + (float) $prepaid_ticket['combi_discount_gross_amount']);
                $bookings[$sync_key]['quantity'] = (int) ($bookings[$sync_key]['quantity'] + 1);
                $bookings[$sync_key]['passes'] = ($bookings[$sync_key]['passes'] != '' && $prepaid_ticket['is_combi_ticket'] == 0 ) ? $bookings[$sync_key]['passes'] . ', ' . $prepaid_ticket['passNo'] : $prepaid_ticket['passNo'];
                $bookings[$sync_key]['bleep_passes'] = ($bookings[$sync_key]['bleep_passes'] != '') ? $bookings[$sync_key]['bleep_passes'] . ', ' . $prepaid_ticket['bleep_pass_no'] : $prepaid_ticket['bleep_pass_no'];
                $bookings[$sync_key]['booking_date_time'] = $prepaid_ticket['created_date_time'];
                $bookings[$sync_key]['booking_name'] = '';
                $bookings[$sync_key]['cashier_name'] = $prepaid_ticket['cashier_name'];
                $bookings[$sync_key]['pos_point_id'] = (int) $pos_point_id;
                $bookings[$sync_key]['pos_point_name'] = $pos_point_name;
                if ($prepaid_ticket['hotel_id'] == CITY_EXPERT) { // 2667
                    $bookings[$sync_key]['group_id'] = (int) 1;
                    $bookings[$sync_key]['group_name'] = 'City Expert';
                } else if($prepaid_ticket['hotel_id'] == CITY_SIGHT_SEEING) { //1790
                    if(strtolower($third_party_response_data['third_party_params']['agent']) == "city-sightseeing-spain.com") {
                        $bookings[$sync_key]['group_id'] = (int) 2;
                    } else if(strtolower($third_party_response_data['third_party_params']['agent']) == "city-sightseeing.com") {
                        $bookings[$sync_key]['group_id'] = (int) 3;
                    } else {
                        $bookings[$sync_key]['group_id'] = (int) 4;
                    }
                    $bookings[$sync_key]['group_name'] = $third_party_response_data['third_party_params']['agent'] != '' ? $third_party_response_data['third_party_params']['agent'] : 'City Sightseeing Website';
                } else {
                    $bookings[$sync_key]['group_id'] = (int) 5;
                    $bookings[$sync_key]['group_name'] = 'OTA';
                }
                $bookings[$sync_key]['channel_type'] = (int) $prepaid_ticket['channel_type'];
                $bookings[$sync_key]['discount_code_amount'] = (float) 0;
                $bookings[$sync_key]['service_cost_type'] = (int) 0;
                $bookings[$sync_key]['service_cost_amount'] = (float) 0;
                $bookings[$sync_key]['activated_by'] = (int) ($cashier_type == '3' && $reseller_id != '' ) ? $reseller_cashier_id : $user_id;
                $bookings[$sync_key]['activated_at'] = gmdate('Y-m-d h:i:s');
                $bookings[$sync_key]['cancelled_tickets'] = (int) 0;
                $bookings[$sync_key]['is_voucher'] = (int) 1;
                $bookings[$sync_key]['shift_id'] = (int) $shift_id;
                $bookings[$sync_key]['ticket_types'] = (!empty($ticket_types[$sync_key])) ? $ticket_types[$sync_key] : array();
                $bookings[$sync_key]['per_ticket_extra_options'] = array();
                $bookings[$sync_key]['from_time'] = ($prepaid_ticket['from_time'] != '' && $prepaid_ticket['from_time'] != '0') ? $prepaid_ticket['from_time'] : "";
                $bookings[$sync_key]['is_reservation'] = (int) !empty($prepaid_ticket['selected_date']) && !empty($prepaid_ticket['from_time']) && !empty( $prepaid_ticket['to_time']) ? 1 : 0;
                $bookings[$sync_key]['merchant_reference'] = '';
                $bookings[$sync_key]['museum'] = $prepaid_ticket['museum_name'];
                $bookings[$sync_key]['order_id'] = (isset($visitor_group_no) && $visitor_group_no != '') ? $visitor_group_no : $_REQUEST['visitor_group_no'];
                $bookings[$sync_key]['payment_method'] = (int) $prepaid_ticket['activation_method'];
                $bookings[$sync_key]['reservation_date'] = ($prepaid_ticket['selected_date'] != '' && $prepaid_ticket['selected_date'] != '0') ? $prepaid_ticket['selected_date'] : "";
                $bookings[$sync_key]['ticket_id'] = (int) $prepaid_ticket['ticket_id'];
                $bookings[$sync_key]['ticket_title'] = $prepaid_ticket['title'];
                $bookings[$sync_key]['timezone'] = (int) $prepaid_ticket['timezone'];
                $bookings[$sync_key]['to_time'] = ($prepaid_ticket['to_time'] != '' && $prepaid_ticket['to_time'] != '0') ? $prepaid_ticket['to_time'] : "";
                $bookings[$sync_key]['status'] = (int) 2;
                $bookings[$sync_key]['is_extended_ticket'] = (int) 0;
            }
            /* SYNC bookings */
            try {
                if($cashier_type == '3' && $reseller_id != '') { 
                    $new_node =  'resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . date("Y-m-d");
                } else {
                    $new_node = 'suppliers/' . $museum_id . '/voucher_scans/' . $user_id . '/' . date("Y-m-d");
                }
                $params = array(
                    'type' => 'POST',
                    'additional_headers' => $this->all_headers(array(
                        'hotel_id' => $prepaid_ticket['hotel_id'],
                        'museum_id' => $museum_id,
                        'ticket_id' => $prepaid_ticket['ticket_id'],
                        'channel_type' => $prepaid_ticket['channel_type'],
                        'action' => 'sync_voucher_scans_from_MPOS',
                        'user_id' => ($cashier_type == '3' && $reseller_id != '' ) ? $reseller_cashier_id : $user_id,
                        'reseller_cashier_id' => ($cashier_type == '3' && $reseller_id != '') ? $reseller_id : '' 
                    )),
                    'body' => array("node" => $new_node, 'details' => $bookings)
                );
                $MPOS_LOGS['sync_voucher_scans_params'] = $params['body'];
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }
        }
    }

    /**
     * @name: getUpdatedPasses()
     * @purpose: Get updated passes info in case of channel_type (4,6,7,8,9,13), (city card off + timebase), (Combi Off/Combi On)
     * @params: $visitor_group_no, $channel_type
     * @where: scan pass
     * @return: jsonarray()
     * @created by: Jatinder Kumar<jatinder.aipl@gmail.com> on date 21 Feb 2020 
    */
    private function getUpdatedPasses($vgn='', $channel_type='') {
        
        global $MPOS_LOGS;
        $data = $logs = array();
        if(!empty($vgn) && $channel_type != '') {
            
            $pt_data = $this->find('prepaid_tickets', array('select' => 'prepaid_ticket_id, passNo, is_combi_ticket, clustering_id, is_addon_ticket, ticket_id, ticket_type, reseller_id', 'where' => 'visitor_group_no = "'.$vgn.'" AND activated = "1" AND is_refunded != "1" AND channel_type = "'.$channel_type.'"'), 'array');
            $logs['pt_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if(!empty($pt_data)) {
                
                $combi = $cluster = $combiCluster = $updClsuter = array();
                foreach($pt_data as $val) {
                    
                    if($val['is_combi_ticket'] == '0' && empty($val['clustering_id'])) {
                        $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].'1';
                    }
                    else if($val['is_combi_ticket'] == '1' && empty($val['clustering_id'])) {
                        
                        if(!isset($combi[$val['passNo']])) {
                            $combi[$val['passNo']] = 1;
                            $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] :  $val['passNo'].$combi[$val['passNo']];
                        }
                        else {
                            $combi[$val['passNo']] = $combi[$val['passNo']]+1;
                            $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].$combi[$val['passNo']];
                        }
                    }
                    else if($val['is_combi_ticket'] == '0' && !empty($val['clustering_id'])) {
                        
                        if(!isset($cluster[$val['clustering_id']])) {
                            
                            $cluster[$val['clustering_id']] = 1;
                            $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].$cluster[$val['clustering_id']];
                        }
                        else {
                            $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].$cluster[$val['clustering_id']];
                        }
                    }
                    else if($val['is_combi_ticket'] == '1' && !empty($val['clustering_id'])) {
                        
                        if($val['is_addon_ticket'] == '0') {
                            
                            $uKey = $val['passNo'];
                            if(!isset($combiCluster[$uKey])) {
                                
                                $combiCluster[$uKey] = 1;
                                $updClsuter[$val['clustering_id']] = $combiCluster[$uKey];
                                $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].$combiCluster[$uKey];
                            }
                            else {
                                
                                $combiCluster[$uKey] = $combiCluster[$uKey]+1;
                                $updClsuter[$val['clustering_id']] = $combiCluster[$uKey];
                                $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].$combiCluster[$uKey];
                            }
                        }
                        else if($val['is_addon_ticket'] == '2') {
                            
                            $data[$val['prepaid_ticket_id']] = !empty($this->liverpool_resellers_array) && in_array($val['reseller_id'], $this->liverpool_resellers_array) ? $val['passNo'] : $val['passNo'].(isset($updClsuter[$val['clustering_id']])? $updClsuter[$val['clustering_id']]: '1');
                        }
                    }
                }
            }
        }
        
        $logs['returned_data_'.date('H:i:s')] = $data;
        $MPOS_LOGS['getUpdatedPasses_' . date('Y-m-d H:i:s')] = $logs;
        return $data;
    }
    
    /**
     * @name: executeUpdPassesQueries()
     * @purpose: Execute updated passes queries in DB2 using SQS
     * @params: $data (array)
     * @where: scan pass
     * @return: json
     * @created by: Jatinder Kumar<jatinder.aipl@gmail.com> on date 21 Feb 2020 
    */
    function executeUpdPassesQueries($data=array()) {

        global $MPOS_LOGS;
        $logs = array();
        
        if(!empty($data)) {
            
            $ptIds = array_keys($data);
            
            $pt_query = 'UPDATE prepaid_tickets SET bleep_pass_no = (CASE ';
            foreach($data as $key => $val) {
                $pt_query.= ' WHEN prepaid_ticket_id="'.$key.'" THEN "'.$val.'" ';
                $bleep_pass_no_insert['case'][] = array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'prepaid_ticket_id','value' =>  (string) $key)
                ) ,'update' => (string) $val);
                $scanned_pass_insert['case'][] = array('separator' => '||', 'conditions' => array(
                    array( 'key' => 'transaction_id','value' =>  (string) $key)
                ) ,'update' => (string) $val);
            }
            $pt_query.= ' ELSE bleep_pass_no END) WHERE prepaid_ticket_id IN ('.implode(",", $ptIds).')';
            $insert_pt['bleep_pass_no'] = $bleep_pass_no_insert;
            $insert_vt['scanned_pass'] = $scanned_pass_insert;
            $logs['pt_updation_query_' . date('Y-m-d H:i:s')] = $pt_query;
            
            $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt, "where" => ' prepaid_ticket_id IN ('.implode(",", $ptIds).') and activated = "1" and is_refunded != "1"');
            $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt, "where" => ' transaction_id IN ('.implode(",", $ptIds).') and is_refunded != "1"');
        }
        if(!empty($pt_query)) {
            
            $this->query($pt_query);
            
            $request_array = array();
            $request_array['db2_insertion'] = $insertion_db2;            
            $request_array['sub_transactions'] = 1;
            $logs['data_to_queue_SCANING_ACTION_ARN_'.date('H:i:s')] = $request_array;
            $request_string = json_encode($request_array);
            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
            } else {
                $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
            }
        }
        
        $MPOS_LOGS['executeUpdPassesQueries_' . date('Y-m-d H:i:s')] = $logs;
    }

    public function checkUpsellTicketIdsIfNotExpired($upsellTicketIds, $userDateTime){
        $upsellTicketIds = explode(",",$upsellTicketIds);
        $upsellTickets = $this->find('modeventcontent', array('select' => 'mec_id, startDate, endDate, startTime, endTime', 'where' => 'mec_id IN ( "'.implode('","', $upsellTicketIds).'") '), 'array');
        $ticketIdstoSend = [];
        foreach($upsellTickets as $upsellTicket) {
            if($upsellTicket['endDate'] > strtotime($userDateTime)){
                array_push($ticketIdstoSend, $upsellTicket['mec_id']);
           }
        }
        if(!empty($ticketIdstoSend)){
            return implode(",",$ticketIdstoSend);
        }
        return '';
    }

    /* #endregion Scan Process Module : Cover all the functions used in scanning process of mpos */
}
?>
