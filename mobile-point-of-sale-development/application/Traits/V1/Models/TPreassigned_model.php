<?php  

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TPreassigned_model {

    function __construct() {
        // Call the Model constructor
        parent::__construct();
        $this->load->model('V1/museum_model');
        $this->load->model('V1/venue_model');
        $this->load->model('V1/firebase_model');
        $this->load->model('V1/intersolver_model');
        $this->api_channel_types = array(4, 6, 7, 8, 9, 13, 5); // API Chanell types

    }
    
    /**
     * @Name     : get_pass_info_from_preassigned().
     * @Purpose  : Used to scan countdown tickets and simple ticket for prssepaid tickets
     * @Called   : called from merchantlogin method.
     * @parameters : $email_id => users unique email_id
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 23 Nov 2017
     */
    function get_pass_info_from_preassigned($museum_info = array(), $data = array()) { 
        global $MPOS_LOGS;
        try{
            $museum_id = $data['museum_id'];
            $pass_no = isset($data['pass_no']) ? addslashes(trim($data['pass_no'])) : '';
            $add_to_pass = isset($data['add_to_pass']) ? $data['add_to_pass'] : 2; //2 -> add_to_pass has to be fetched on ticket level
            $pos_point_id = isset($data['pos_point_id']) ? $data['pos_point_id'] : 0;
            $shift_id = isset($data['shift_id']) ? $data['shift_id'] : 0;
            $pos_point_name = isset($data['pos_point_name']) ? $data['pos_point_name'] : '';
            $ticket_id = $data['ticket_id'];
            $is_edited = $data['is_edited'];
            $selected_date = isset($data['selected_date']) ? $data['selected_date'] : '';
            $from_time = isset($data['from_time']) ? $data['from_time'] : '';
            $to_time = isset($data['to_time']) ? $data['to_time'] : '';
            $slot_type = isset($data['slot_type']) ? $data['slot_type'] : '';
            $shared_capacity_id = isset($data['shared_capacity_id']) ? $data['shared_capacity_id'] : '';
            $own_capacity_id = isset($data['own_capacity_id']) ? $data['own_capacity_id'] : '';
            $device_time = $data['device_time'];
            $tps_id = $data['tps_id'];
            $preassigned_voucher_group = isset($data['preassigned_voucher_group']) ? $data['preassigned_voucher_group'] : 0;
            if(strlen($pass_no) == PASSLENGTH) {
                $pass_no = 'http://qu.mu/'.$pass_no;
            }
            $arrReturn = array();
            $dist_id = $museum_info->dist_id;
            $dist_cashier_id = $museum_info->dist_cashier_id;
            if($museum_info->cashier_type == '3' && $museum_info->reseller_id != '' ) {
                $user_id = $museum_info->reseller_cashier_id;
                $user_name = $museum_info->reseller_cashier_name;
            } else {
                $user_id = $museum_info->uid;
                $user_name = $museum_info->fname . ' ' . $museum_info->lname;
            }
 
            $current_date_time = gmdate("Y-m-d H:i:s");
            if (!empty($pass_no)) {
                $where = '';
                if ($ticket_id != 0 && $preassigned_voucher_group == 0) {
                    $where .= 'ticket_id = '. $ticket_id.' and ';
                }
                if ($tps_id != 0) {
                    $where .= 'tps_id = '. $tps_id.' and ';
                }
                //Check pass in pre_assigned_codes table
                $where .= 'child_pass_no="' . $pass_no . '" and activated = "1" and active = "0"';
                $pre_assigned_detail = $this->find('pre_assigned_codes', array('select' => '*', 'where' => $where), 'object');
                $logs['preassigned query_'.date('H:i:s')] = $this->primarydb->db->last_query();

                if (!empty($pre_assigned_detail)) {
                    if($add_to_pass == 2){
                        $mec_data = $this->find('modeventcontent', array('select' => 'allow_city_card, is_scan_countdown, is_reservation, upsell_ticket_ids, is_combi', 'where' => 'mec_id = "'.$pre_assigned_detail[0]->ticket_id.'"'), 'row_object');
                        $add_to_pass = $mec_data->allow_city_card;
                        $is_scan_countdown = $mec_data->is_scan_countdown;
                        $is_reservation = $mec_data->is_reservation;
                        $upsell_ticket_ids = $mec_data->upsell_ticket_ids;
                        $logs['mec_query_'.date('H:i:s')] = $add_to_pass.'_'.$this->primarydb->db->last_query();
                    }
                    $arrReturn['status'] = 0;
                    $arrReturn['is_ticket_listing'] = 0;
                    $arrReturn['add_to_pass'] = $add_to_pass;
                    $arrReturn['errorMessage'] = 'Pass not valid';
                    $arrReturn['data'] = array();
                    // get cluster ticket supliers
                    $hotel_id_check_cond = 'hotel_id = "' . $pre_assigned_detail[0]->hotel_id . '" and '; 
                    if($mec_data->is_combi == "2") {
                        $hotel_id_check_cond  = '';
                    }
                    $cluster_tickets_details = $this->find('cluster_tickets_detail', array('select' => 'museum_id', 'where' => $hotel_id_check_cond . ' main_ticket_id="' . $pre_assigned_detail[0]->ticket_id . '" and main_ticket_price_schedule_id ="' . $pre_assigned_detail[0]->tps_id . '" and is_deleted = "0"'));
                    $logs['cluster_ticket_query_'.date('H:i:s')] = $add_to_pass.'_'.$this->primarydb->db->last_query();
                    $sub_tickets_supplier = array_unique(array_column($cluster_tickets_details, 'museum_id'));
                    $sub_tickets_supplier[] = $pre_assigned_detail[0]->museum_id;
                    $pre_assigned_data = array();
                    foreach ($pre_assigned_detail as $pre_assigned) {
                        if ($pre_assigned->bleep_pass_no == $pass_no) {
                            $pre_assigned_data[] = $pre_assigned;
                        }
                        $ticket_ids[$pre_assigned->ticket_id] = $pre_assigned->ticket_id;
                    }
                    $valid_till_date_time = $pre_assigned_detail[0]->valid_till_date_time;
                    //check if pass is expired
                    if($valid_till_date_time != '' && $valid_till_date_time != '0000-00-00 00:00:00' && strtotime($valid_till_date_time) < strtotime($current_date_time)) {
                        $arrReturn['status'] = 0;
                        $arrReturn['is_ticket_listing'] = 0;
                        $arrReturn['add_to_pass'] = $add_to_pass;
                        $arrReturn['message'] = 'Pass has been expird on '.date("d M, Y H:i", strtotime($valid_till_date_time) );
                        $arrReturn['data'] = array();
                        $MPOS_LOGS['get_pass_info_from_preassigned'] = $logs;
                        return $arrReturn;
                    }

                    //If pass of different ticket is scanned in case of voucher group confirmed
                    if($pre_assigned_detail[0]->ticket_id != $ticket_id && ($pre_assigned_detail[0]->combi_pass  == 0 || ($pre_assigned_detail[0]->combi_pass  == 1 && count(array_keys($ticket_ids)) == 1)) && $preassigned_voucher_group == 1 && $ticket_id > 0) {
						
                        $arrReturn['status'] = 12;
                        $arrReturn['is_ticket_listing'] = 0;
                        $arrReturn['add_to_pass'] = $add_to_pass;
                        $arrReturn['message'] = 'Different ticket is not allowed in active voucher group. Please try with same voucher ticket.';
                        $arrReturn['data'] = array();
                        $MPOS_LOGS['get_pass_info_from_preassigned'] = $logs;
                        return $arrReturn;
                    }

                    if (empty($pre_assigned_data)) {
                        $pre_assigned_data = $pre_assigned_detail;
                    }
                    $pre_assigned_data[0]->user_id = $user_id;
                    $pre_assigned_data[0]->user_name = $user_name;
                    $pre_assigned_data[0]->shift_id = $shift_id;


                    $citysightseen_card = 0;
                    if ($add_to_pass == '1' && empty($pre_assigned_data[0]->bleep_pass_no)) {
                        $citysightseen_card = 1;
                    }
                    if ((!empty($pre_assigned_data[0]->bleep_pass_no) && ($pre_assigned_data[0]->bleep_pass_no != $pass_no ) ) || ($add_to_pass == '1' && $citysightseen_card == 0 && (!in_array($museum_id, $sub_tickets_supplier) && $museum_info->cashier_type != '3')) || ($add_to_pass == '0' && (!in_array($museum_id, $sub_tickets_supplier)) && $museum_info->cashier_type != '3')) {
                        //prepaid_tickets response
                        $arrReturn['status'] = 0;
                        $arrReturn['is_ticket_listing'] = 0;
                        $arrReturn['add_to_pass'] = $add_to_pass;
                        $arrReturn['errorMessage'] = 'Pass not valid';
                        if(!in_array($museum_id, $sub_tickets_supplier)) {
                            $arrReturn['errorMessage'] = 'Pass belongs to different supplier';
                        }
                        $arrReturn['data'] = array();
                        $logs['error'] = $arrReturn;
                        $MPOS_LOGS['get_pass_info_from_preassigned'] = $logs;
                        return $arrReturn;
                    }
                    //Update capacity in DB, redis and firebase in case of reservation tickets
                    if ($selected_date != '' && $from_time != '') {
                        $logs['capacity'] = array($pre_assigned_data[0]->ticket_id, $selected_date, $from_time, $to_time, 1, $slot_type);
                        $this->update_capacity($pre_assigned_data[0]->ticket_id, $selected_date, $from_time, $to_time, 1, $slot_type);
                    }
                    $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                    $logs = array();

                    /* IF Voucher Passes */
                    if ($add_to_pass == 0 && count($pre_assigned_data) == 1 && $pre_assigned_data[0]->is_voucher == '1' && ($is_scan_countdown == 0  || ($is_scan_countdown == 1 && $is_reservation == 0))) {
                        if ($pre_assigned_data[0]->is_countdown == '0') {
                            /* When simple ticket for without voucher passes */
                            $arrReturn = $this->scan_simple_tickets($dist_id, $dist_cashier_id, $pre_assigned_data[0], $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $own_capacity_id, $device_time, $time_zone, $is_edited, $shift_id, $pos_point_id, $pos_point_name, $add_to_pass);
                        } else {
                            /* When count down ticket for without voucher passes */
                            $arrReturn = $this->scan_voucher_count_down_tickets($dist_id, $dist_cashier_id, $pos_point_id, $pos_point_name, $pre_assigned_data[0], $add_to_pass, $upsell_ticket_ids);
                        }
                    } else {
                        /* Ticket Listing API for Voucher passes */
                        if ($pre_assigned_data[0]->used == '0') {
                            $arrReturn = $this->listing_of_linked_tickets($pre_assigned_data, $add_to_pass, $pass_no, $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $own_capacity_id, $device_time, $time_zone, $shift_id);
                        }
                    }
                    if (!empty($arrReturn)) {
                        $arrReturn['show_group_checkin_button'] = 0;
                    }
                } else { 
                    $arrReturn['status'] = 0;
                    $arrReturn['is_ticket_listing'] = 0;
                    $arrReturn['add_to_pass'] = (int) ($add_to_pass == 2) ? 0 : $add_to_pass;
                    $arrReturn['message'] = 'Pass not valid';
                    $arrReturn['data'] = array();
                }
            } else {
                $arrReturn['status'] = 0;
                $arrReturn['is_ticket_listing'] = 0;
                $arrReturn['add_to_pass'] = 0;
                $arrReturn['errorMessage'] = 'Pass not valid';
                $arrReturn['data'] = array();
            }
            $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
            return $arrReturn;
        } catch (\Exception $e) {
            $MPOS_LOGS['get_pass_info_from_preassigned'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : scan_simple_tickets().
     * @Purpose  : Used to scan Simple tickets from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 28 Nov 2017
     */
    function scan_simple_tickets($dist_id = 0, $dist_cashier_id = 0, $voucher_simple_passes = array(), $selected_date = '', $from_time = '', $to_time = '', $slot_type = '', $shared_capacity_id = '', $own_capacity_id = '', $device_time = '', $time_zone = '', $is_edited = 0 , $shift_id = 0, $pos_point_id = 0, $pos_point_name = '', $add_to_pass = 0) {
        global $MPOS_LOGS;
        try{
            $logs['request_'.date('H:i:s')] = array('voucher passes data' => $voucher_simple_passes, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'slot_type' => $slot_type, 'shared_capacity_id' => $shared_capacity_id, 'own_capacity_id' => $own_capacity_id, 'device_time' => $device_time, 'time_zone' => $time_zone, 'is_edited' => $is_edited, 'add_to_pass' => $add_to_pass);
            $arrReturn = array();
            $is_redeem = 0;
            $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
            //get ticket details from modeventcontent table
            $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate, own_capacity_id, shared_capacity_id, is_reservation', 'where' => 'mec_id = ' . $voucher_simple_passes->ticket_id), 'array');

            //Return error if pass is already scanned
            if ($voucher_simple_passes->used == '1') {
                $status = 0;
                $message = 'Pass not valid';
            } else {
                //Update preassigned table in case we add entry in PT
                if (($capacity[0]['is_reservation'] != 1) || ($capacity[0]['is_reservation'] == 1 && (isset($is_edited) && $is_edited == 1 ))) {
                    if ($voucher_simple_passes->combi_pass == 1) {
                        $this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $voucher_simple_passes->user_id, "museum_cashier_name" => $voucher_simple_passes->user_name, "scanned_at" => $scanned_at), array("id" => $voucher_simple_passes->id));
                    } else {
                        $this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $voucher_simple_passes->user_id, "museum_cashier_name" => $voucher_simple_passes->user_name, "scanned_at" => $scanned_at), array("url" => $voucher_simple_passes->url));
                    }
                }
                
                $logs['preassigned_query_'.date('H:i:s')] = $this->db->last_query();
                $cluster_id_check = $this->find('pre_assigned_codes', array('select' => 'clustering_id, ticket_id, hotel_id', 'where' => 'child_pass_no = "' . $voucher_simple_passes->child_pass_no.'"'), 'row_array');
                if (empty($cluster_id_check['clustering_id'])) {
                    $is_cluster = 0;
                } else {
                    $is_cluster = 1;
                }

                if (strstr($voucher_simple_passes->child_pass_no, 'http://')) {
                    $voucher_simple_passes->child_pass_no = str_replace('http://qu.mu/', '', $voucher_simple_passes->child_pass_no);
                }
                // check if Prepaid data exist for that pass       
                $select = 'prepaid_ticket_id,used, additional_information, hotel_ticket_overview_id, extra_discount, channel_type, third_party_type, second_party_type, without_elo_reference_no, combi_discount_gross_amount, redeem_users, is_addon_ticket, clustering_id, is_combi_ticket, distributor_partner_id, distributor_partner_name, visitor_tickets_id, museum_id, museum_name, ticket_id, tps_id, title, price, ticket_type, age_group, hotel_id, hotel_name, cashier_name, pos_point_name, tax, visitor_group_no, passNo, bleep_pass_no, second_party_passNo, second_party_type, third_party_type, group_price, activation_method, booking_status, used, channel_type, action_performed, scanned_at, is_prepaid, additional_information, visitor_group_no, selected_date, from_time, to_time, timeslot, timezone';
                $prepaid_data = $this->find('prepaid_tickets', array('select' => $select, 'where' => 'hotel_id = '. $voucher_simple_passes->hotel_id .' and passNo = "' . $voucher_simple_passes->child_pass_no.'"'), 'array');
                $logs['pt_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                if (empty($prepaid_data)) {
                    $is_redeem = 0;
                } else {
                    $is_redeem = 1;
                }
                $status = 1;
                $message = 'Valid';

                /* HIT SNS to insert record in HTO and PT */
                $is_redeem = 0;
                if ($capacity[0]['is_reservation'] == 1) {
                    $device_time_check = $device_time / 1000;
                    if (isset($selected_date) && $selected_date != '') {
                        $from_time_check = strtotime($from_time);
                        $to_time_check = strtotime($to_time);
                    } else {
                        $from_time_check = strtotime($prepaid_data[0]['from_time']);
                        $to_time_check = strtotime($prepaid_data[0]['to_time']);
                    }

                    // When Selcted Date  is Matching With Current Date
                    if ($selected_date == date("Y-m-d") && ($from_time_check <= $device_time_check && $to_time_check >= $device_time_check)) {
                            $is_redeem = 1;
                    }                   
                }
				
                $merchant_admin_id = 0;
                $merchant_admin_name = '';
                $preData = $this->find('pre_assigned_codes', array('select' => 'ticket_id, hotel_id, tps_id', 'where' => 'id = "'.$voucher_simple_passes->id.'"'), 'row_array');
                if(!empty($preData)) {

                    $hotel_id = $preData['hotel_id'];
                    $merchant = $this->find('modeventcontent', array('select' => 'merchant_admin_id, merchant_admin_name', 'where' => 'mec_id = '.$preData['ticket_id']), 'row_array');
                    if(!empty($merchant)) {
                        $merchant_admin_id = $merchant['merchant_admin_id'];
                        $merchant_admin_name = $merchant['merchant_admin_name'];
                    }

                    /* Check TLC/CLC level if prices are modified and add value to PT */
                    $merchant_data = $this->get_price_level_merchant($preData['ticket_id'], $hotel_id, $preData['tps_id']);
                    if(!empty($merchant_data)) {
                        $merchant_admin_id = $merchant_data->merchant_admin_id;
                        $merchant_admin_name = $merchant_data->merchant_admin_name;
                    }
                    /* Check TLC/CLC level if prices are modified and add  value to PT */
                }
                
                $request_data = array();
                $request_data['dist_id'] = (isset($dist_id) && $dist_id > 0) ? $dist_id : 0 ;
                $request_data['dist_cashier_id'] = (isset($dist_cashier_id) && $dist_cashier_id > 0) ? $dist_cashier_id : 0;
                $request_data['pos_point_id_on_redeem'] = (isset($pos_point_id) && $pos_point_id > 0) ? $pos_point_id : 0;
                $request_data['pos_point_name_on_redeem'] = isset($pos_point_name) ? $pos_point_name : 0;
                $request_data['museum_id'] = $voucher_simple_passes->museum_id;
                $request_data['ticket_id'] = $voucher_simple_passes->ticket_id;
                $request_data['tps_id'] = $voucher_simple_passes->tps_id;
                $request_data['pos_point_id'] = $pos_point_id;
                $request_data['pos_point_name'] = $pos_point_name;
                $request_data['shift_id'] = $shift_id;
                $request_data['child_pass_no'] = $voucher_simple_passes->child_pass_no;
                $request_data['parent_pass_no'] = $voucher_simple_passes->parent_pass_no;
                $request_data['extra_discount'] = $voucher_simple_passes->extra_discount;
                $request_data['order_currency_extra_discount'] = $voucher_simple_passes->order_currency_extra_discount;
                $request_data['discount'] = $voucher_simple_passes->discount;
                $request_data['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0;
                $request_data['from_time'] = !empty($prepaid_data[0]['from_time']) ? $prepaid_data[0]['from_time'] : $from_time;
                $request_data['to_time'] = !empty($prepaid_data[0]['to_time']) ? $prepaid_data[0]['to_time'] : $to_time;
                $request_data['slot_type'] = !empty($prepaid_data[0]['timeslot']) ? $prepaid_data[0]['timeslot'] : $slot_type;
                $request_data['selected_date'] = !empty($prepaid_data[0]['selected_date']) ? $prepaid_data[0]['selected_date'] : $selected_date;
                $request_data['used'] = ($capacity[0]['is_reservation'] != 1 || $is_redeem == 1) ? 1 : 0;
                $request_data['merchant_admin_id'] = $merchant_admin_id;
                $request_data['merchant_admin_name'] = $merchant_admin_name;

                if (UPDATE_SECONDARY_DB && !empty($request_data) && ($capacity[0]['is_reservation'] != 1 || ($capacity[0]['is_reservation'] == 1 && $selected_date != ''))) {
                    $request_array['update_pre_assigned_records'] = $request_data;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "scan_simple_tickets";
                    $request_array['visitor_group_no'] = $voucher_simple_passes->visitor_group_no;
                    $request_array['pass_no'] = $voucher_simple_passes->child_pass_no;
                    $request_array['channel_type'] = '5';
                    $request_data['pass_no'] = $voucher_simple_passes->child_pass_no;
                    $logs['data_to_queue_UPDATE_DB_QUEUE_URL_'.date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                    }
                }
            }

            if ($status) {
                //Prepare types count array
                $types_count = array();
                $types_count[$voucher_simple_passes->ticket_type]['clustering_id'] = !empty($voucher_simple_passes->clustering_id) ? $voucher_simple_passes->clustering_id : 0;
                $types_count[$voucher_simple_passes->ticket_type]['tps_id'] = $voucher_simple_passes->tps_id;
                $types_count[$voucher_simple_passes->ticket_type]['ticket_type'] = (!empty($this->types[strtolower($voucher_simple_passes->ticket_type)]) && ($this->types[strtolower($voucher_simple_passes->ticket_type)] > 0)) ? $this->types[strtolower($voucher_simple_passes->ticket_type)] : 10;
                $types_count[$voucher_simple_passes->ticket_type]['ticket_type_label'] = $voucher_simple_passes->ticket_type;
                $types_count[$voucher_simple_passes->ticket_type]['title'] = $voucher_simple_passes->ticket_title;
                $types_count[$voucher_simple_passes->ticket_type]['price'] = $voucher_simple_passes->price;
                $types_count[$voucher_simple_passes->ticket_type]['tax'] = $voucher_simple_passes->tax;
                $types_count[$voucher_simple_passes->ticket_type]['pax'] = (int) isset($voucher_simple_passes->pax) ? $voucher_simple_passes->pax : 0;
                $types_count[$voucher_simple_passes->ticket_type]['capacity'] = (int) isset($voucher_simple_passes->capacity) ? $voucher_simple_passes->capacity : 1;
                $types_count[$voucher_simple_passes->ticket_type]['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : '';
                $types_count[$voucher_simple_passes->ticket_type]['end_date'] = isset($capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : '';
                $types_count[$voucher_simple_passes->ticket_type]['count'] += 1;

                $arrReturn['title'] = $voucher_simple_passes->ticket_title;
                $arrReturn['age_group'] = $voucher_simple_passes->age_group;
                $arrReturn['price'] = $voucher_simple_passes->price;
                $arrReturn['pass_no'] = $voucher_simple_passes->child_pass_no;
                $arrReturn['group_price'] = 0;
                $arrReturn['visitor_group_no'] = (string) $voucher_simple_passes->visitor_group_no;
                $arrReturn['ticket_id'] = $voucher_simple_passes->ticket_id;
                $arrReturn['tps_id'] = $voucher_simple_passes->tps_id;
                $arrReturn['ticket_type'] = (!empty($this->types[strtolower($voucher_simple_passes->ticket_type)]) && ($this->types[strtolower($voucher_simple_passes->ticket_type)] > 0)) ? $this->types[strtolower($voucher_simple_passes->ticket_type)] : 10;
                $arrReturn['ticket_type_label'] = $voucher_simple_passes->ticket_type;
                $arrReturn['own_capacity_id'] = !empty($capacity[0]['own_capacity_id']) ? (int) $capacity[0]['own_capacity_id'] : (int) 0;
                $arrReturn['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0;
                $arrReturn['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
                $arrReturn['from_time'] = !empty($prepaid_data[0]['from_time']) ? $prepaid_data[0]['from_time'] : '';
                $arrReturn['to_time'] = !empty($prepaid_data[0]['to_time']) ? $prepaid_data[0]['to_time'] : '';
                $arrReturn['selected_date'] = !empty($prepaid_data[0]['selected_date']) ? $prepaid_data[0]['selected_date'] : '';
                $arrReturn['slot_type'] = !empty($prepaid_data[0]['timeslot']) ? $prepaid_data[0]['timeslot'] : '';
                $arrReturn['count'] = 1;
                $arrReturn['types_count'] = array_values($types_count);
                $arrReturn['is_scan_countdown'] = 0;
                $arrReturn['is_late'] = 0;
                $arrReturn['is_voucher'] = 1;
                $arrReturn['channel_type'] = !empty($prepaid_data[0]['channel_type']) ? (int) $prepaid_data[0]['channel_type'] : (int) 5;
                $arrReturn['is_redeem'] = ($is_redeem != null) ? $is_redeem : 0;
                $arrReturn['countdown_interval'] = '';
                $arrReturn['hotel_name'] = !empty($voucher_simple_passes->hotel_name) ? $voucher_simple_passes->hotel_name : '';
                $arrReturn['scanned_at'] = date('d/m/Y H:i:s', $scanned_at);
            }

            //FINAL pre_assigned response
            $response = array();
            $response['data'] = $arrReturn;
            $response['is_ticket_listing'] = 0;
            $response['add_to_pass'] = (int) $add_to_pass;
            $response['is_prepaid'] = 0;
            $response['is_cluster'] = $is_cluster;
            $response['status'] = $status;
            $response['message'] = $message;
            $MPOS_LOGS['scan_simple_tickets_'.date('H:i:s')] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['scan_simple_tickets'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : scan_voucher_count_down_tickets().
     * @Purpose  : Used to scan countdown tickets from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 28 Nov 2017
     */
    function scan_voucher_count_down_tickets($dist_id = 0, $dist_cashier_id = 0, $pos_point_id = 0, $pos_point_name = '', $voucher_countdown_passes = array(), $add_to_pass = 0, $upsell_ticket_ids = '') {
        global $MPOS_LOGS;
        try{
            $logs['data_'.date('H:i:s')] = $voucher_countdown_passes;
            if ($voucher_countdown_passes->scanned_at == '' || $voucher_countdown_passes->scanned_at == '0' || $voucher_countdown_passes->scanned_at == NULL) {
                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
               	$this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $voucher_countdown_passes->user_id, "museum_cashier_name" => $voucher_countdown_passes->user_name, "scanned_at" => $scanned_at), array("id" => $voucher_countdown_passes->id));
                /* HIT SNS to insert record in HTO and PT */
				
                $merchant_admin_id = 0;
                $merchant_admin_name = '';
                $preData = $this->find('pre_assigned_codes', array('select' => 'ticket_id, hotel_id, tps_id', 'where' => 'id = "'.$voucher_countdown_passes->id.'"'), 'row_array');
                if(!empty($preData)) {

                    $hotel_id = $preData['hotel_id'];
                    $merchant = $this->find('modeventcontent', array('select' => 'merchant_admin_id, merchant_admin_name', 'where' => 'mec_id = '.$preData['ticket_id']), 'row_array');
                    if(!empty($merchant)) {
                        $merchant_admin_id = $merchant['merchant_admin_id'];
                        $merchant_admin_name = $merchant['merchant_admin_name'];
                    }

                    /* Check TLC/CLC level if prices are modified and add value to PT */
                    $merchant_data = $this->get_price_level_merchant($preData['ticket_id'], $hotel_id, $preData['tps_id']);
                    if(!empty($merchant_data)) {
                        $merchant_admin_id = $merchant_data->merchant_admin_id;
                        $merchant_admin_name = $merchant_data->merchant_admin_name;
                    }
                    /* Check TLC/CLC level if prices are modified and add  value to PT */
                }
				
                $request_data = array();
                $request_data['dist_id'] = (isset($dist_id) && $dist_id > 0) ? $dist_id : 0 ;
                $request_data['dist_cashier_id'] = (isset($dist_cashier_id) && $dist_cashier_id > 0) ? $dist_cashier_id : 0;
                $request_data['pos_point_id'] = (isset($pos_point_id) && $pos_point_id > 0) ? $pos_point_id : 0;
                $request_data['pos_point_name'] = isset($pos_point_name) ? $pos_point_name : '';
                $request_data['pos_point_id_on_redeem'] = (isset($pos_point_id) && $pos_point_id > 0) ? $pos_point_id : 0;
                $request_data['pos_point_name_on_redeem'] = isset($pos_point_name) ? $pos_point_name : '';
                $request_data['museum_id'] = $voucher_countdown_passes->museum_id;
                $request_data['ticket_id'] = $voucher_countdown_passes->ticket_id;
                $request_data['tps_id'] = $voucher_countdown_passes->tps_id;
                $request_data['shift_id'] = $voucher_countdown_passes->shift_id;
                $request_data['pass_no'] = $voucher_countdown_passes->url;
                $request_data['child_pass_no'] = $voucher_countdown_passes->child_pass_no;
                $request_data['parent_pass_no'] = $voucher_countdown_passes->parent_pass_no;
                $request_data['extra_discount'] = $voucher_countdown_passes->extra_discount;
                $request_data['order_currency_extra_discount'] = $voucher_countdown_passes->order_currency_extra_discount;
                $request_data['discount'] = $voucher_countdown_passes->discount;
                $request_data['used'] = '1';
                $request_data['merchant_admin_id'] = $merchant_admin_id;
                $request_data['merchant_admin_name'] = $merchant_admin_name;
                if (UPDATE_SECONDARY_DB && !empty($request_data)) {
                    $request_array['update_pre_assigned_records'] = $request_data;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "scan_voucher_count_down_tickets";
                    $request_array['visitor_group_no'] = $voucher_countdown_passes->visitor_group_no;
                    $request_array['pass_no'] = $voucher_countdown_passes->child_pass_no;
                    $request_array['channel_type'] = '5';
                    $request_data['pass_no'] = $voucher_countdown_passes->child_pass_no;
                    $logs['data_to_queue_UPDATE_DB_QUEUE_URL_'.date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);                           
                    }
                }
            } else {
                $scanned_at = $voucher_countdown_passes->scanned_at;
                $this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $voucher_countdown_passes->user_id, "museum_cashier_name" => $voucher_countdown_passes->user_name), array("id" => $voucher_countdown_passes->id));
            }

            if(isset($request_data['ticket_id']) && $request_data['ticket_id'] > 0){                
                $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate', 'where' => 'mec_id = ' . $request_data['ticket_id']), 'array');
            }

            $status = 1;
            $message = 'Valid';
            $arrReturn = array();
            //Prepare reponse
            $types_count[$voucher_countdown_passes->ticket_type]['clustering_id'] = !empty($voucher_countdown_passes->clustering_id) ? $voucher_countdown_passes->clustering_id : 0;
            $types_count[$voucher_countdown_passes->ticket_type]['tps_id'] = $voucher_countdown_passes->tps_id;
            $types_count[$voucher_countdown_passes->ticket_type]['ticket_type'] = (!empty($this->types[strtolower($voucher_countdown_passes->ticket_type)]) && ($this->types[strtolower($voucher_countdown_passes->ticket_type)] > 0)) ? $this->types[strtolower($voucher_countdown_passes->ticket_type)] : 10;
            $types_count[$voucher_countdown_passes->ticket_type]['ticket_type_label'] = $voucher_countdown_passes->ticket_type;
            $types_count[$voucher_countdown_passes->ticket_type]['title'] = $voucher_countdown_passes->ticket_title;
            $types_count[$voucher_countdown_passes->ticket_type]['price'] = $voucher_countdown_passes->price;
            $types_count[$voucher_countdown_passes->ticket_type]['tax'] = $voucher_countdown_passes->tax;
            $types_count[$voucher_countdown_passes->ticket_type]['pax'] = (int) isset($voucher_countdown_passes->pax) ? $voucher_countdown_passes->pax : 0;
            $types_count[$voucher_countdown_passes->ticket_type]['capacity'] = (int) isset($voucher_countdown_passes->capacity) ? $voucher_countdown_passes->capacity : 1;
            $types_count[$voucher_countdown_passes->ticket_type]['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : '';
            $types_count[$voucher_countdown_passes->ticket_type]['end_date'] = isset( $capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : '';
            $types_count[$voucher_countdown_passes->ticket_type]['count'] += 1;

            $arrReturn['title'] = $voucher_countdown_passes->ticket_title;
            $arrReturn['ticket_type'] = (!empty($this->types[strtolower($voucher_countdown_passes->ticket_type)]) && ($this->types[strtolower($voucher_countdown_passes->ticket_type)] > 0)) ? $this->types[strtolower($voucher_countdown_passes->ticket_type)] : 10;
            $arrReturn['ticket_type_label'] = $voucher_countdown_passes->ticket_type;
            $arrReturn['age_group'] = $voucher_countdown_passes->age_group;
            $arrReturn['price'] = $voucher_countdown_passes->price;
            $arrReturn['group_price'] = 0;
            $arrReturn['visitor_group_no'] = (string) $voucher_countdown_passes->visitor_group_no;
            $arrReturn['ticket_id'] = $voucher_countdown_passes->ticket_id;
            $arrReturn['tps_id'] = $voucher_countdown_passes->tps_id;
            $arrReturn['count'] = 1;
            $arrReturn['types_count'] = array_values($types_count);
            $arrReturn['show_extend_button'] = !empty($upsell_ticket_ids) ? 1 : 0;
            $arrReturn['upsell_ticket_ids'] = $upsell_ticket_ids;
            $arrReturn['pass_no'] = $voucher_countdown_passes->child_pass_no;
            $arrReturn['address'] = '';
            $arrReturn['cashier_name'] = $voucher_countdown_passes->cashier_name;
            $arrReturn['pos_point_name'] = $voucher_countdown_passes->pos_point_name;
            $arrReturn['tax'] = $voucher_countdown_passes->tax;
            $arrReturn['is_scan_countdown'] = 1;
            $arrReturn['is_late'] = 0;
            $arrReturn['channel_type'] = 5;
            //calculations to fetch left time of the pass
            $countdown_time = explode('-', $voucher_countdown_passes->countdown_interval);
            $countdown_text = $countdown_time[1];
            $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_time[0]);
            if ($voucher_countdown_passes->scanned_at == '' || $voucher_countdown_passes->scanned_at == '0' || $voucher_countdown_passes->scanned_at == NULL) {
                $arrReturn['upsell_left_time'] = $current_count_down_time . '_' . $countdown_text . '_' . $voucher_countdown_passes->scanned_at;
            } else {
                $arrReturn['upsell_left_time'] = ($current_count_down_time + $voucher_countdown_passes->scanned_at) . '_' . $countdown_text . '_' . $voucher_countdown_passes->scanned_at;
            }
            $current_time = strtotime(gmdate('m/d/Y H:i:s'));
            if (!empty($voucher_countdown_passes->scanned_at)) {
                $difference = $current_time - $voucher_countdown_passes->scanned_at;
            } else {
                $difference = 0;
            }
            $left_time_user_format = $this->get_arrival_status_from_time($current_time, $voucher_countdown_passes->scanned_at, $current_count_down_time);
            if($add_to_pass == 0 && $voucher_countdown_passes->is_countdown == '1' && $arrReturn['show_extend_button'] == '1'){
                $arrReturn['countdown_interval'] = $left_time_user_format;
                $arrReturn['is_late'] = 0;
                $arrReturn['show_extend_button'] = 1;
            } else if (empty($voucher_countdown_passes->scanned_at) || $current_count_down_time > $difference || $current_count_down_time == $difference) {
                $arrReturn['countdown_interval'] = $left_time_user_format;
                $arrReturn['is_late'] = 0;
                $arrReturn['show_extend_button'] = 0;
            } else {
                $arrReturn['is_late'] = 1;
                $arrReturn['countdown_interval'] = $left_time_user_format . ' late';
                $arrReturn['show_extend_button'] = 0;
            }

            $arrReturn['hotel_name'] = !empty($voucher_countdown_passes->hotel_name) ? $voucher_countdown_passes->hotel_name : '';
            $arrReturn['scanned_at'] = !empty($voucher_countdown_passes->scanned_at) ? date('d/m/Y H:i:s', $voucher_countdown_passes->scanned_at) : gmdate('d/m/Y H:i:s');

            if (($scanned_at - strtotime(gmdate('Y-m-d h:i:s'))) < -(24 * 3600)) {
                $status = 0;
                $message = 'Pass not valid';
                $arrReturn = array();
            }
            if (!empty($arrReturn)) {
                //replace null with blank
                array_walk_recursive($arrReturn, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'visitor_group_no') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }
                    $item = null === $item ? '' : $item;
                });
            }
            $logs['return_array'] = $arrReturn;
            //FINAL pre_assigned response
            $response = array();
            $response['data'] = $arrReturn;
            $response['is_ticket_listing'] = 0;
            $response['add_to_pass'] = (int) $add_to_pass;
            $response['is_prepaid'] = 0;
            $response['status'] = $status;
            $response['message'] = $message;
            $MPOS_LOGS['scan_voucher_count_down_tickets_'.date('H:i:s')] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['scan_voucher_count_down_tickets'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : listing_of_linked_tickets().
     * @Purpose  : Used to List tickets from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned passes data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 28 Nov 2017
     */
    function listing_of_linked_tickets($voucher_passes = array(), $add_to_pass = 0, $pass_no = '', $selected_date = '', $from_time = '', $to_time = '', $slot_type = '', $shared_capacity_id = '', $own_capacity_id = '', $device_time = '', $time_zone = '') {
        global $MPOS_LOGS;
        try {
            $logs['passes_details_' . date('H:i:s')] = $voucher_passes;
            $ticket_listing = $total_count = array();
            $status = 1;
            $message = 'Valid';
            
            if ($voucher_passes[0]->combi_pass == '1') { // when combi on in case of voucher
                $tps_detail = array();
                foreach ($voucher_passes as $pass) {
                    $ticket_id = $pass->ticket_id;
                    $total_count[$pass->ticket_id] += 1;
                    if (empty($tps_detail[$pass->ticket_id][$pass->tps_id]['count'])) {
                        $count = 1;
                    } else {
                        $count = $tps_detail[$pass->ticket_id][$pass->tps_id]['count'] + 1;
                    }
                    // new changes to add start date and end date to type count array                    
                    
                    $tps_detail[$pass->ticket_id][$pass->tps_id] = array(
		    'tps_id' => $pass->tps_id, 
		    'pax' => (int) isset($pass->pax) ? $pass->pax : 0, 
		    'capacity' => (int) isset($pass->capacity) ? $pass->capacity : 1,
		    'clustering_id' => 0, 
		    'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10, 
                     'ticket_type_label' => $pass->ticket_type, 
		    'price' => $pass->price, 
		    'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99', 
		    'count' => $count, 
		    'child_pass_no' => $pass->child_pass_no
		    );
                }
                $logs['tps_details'] = $tps_detail;
                foreach ($voucher_passes as $passes) {
                    $ticket_id = $passes->ticket_id;

                    // condition to get capacity according to ticket Id
                    $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate, own_capacity_id, shared_capacity_id, is_reservation', 'where' => 'mec_id = ' . $ticket_id), 'array');
                    $cluster_check = $this->find('pre_assigned_codes', array('select' => 'clustering_id', 'where' => "(child_pass_no = '" . $pass_no . "' || bleep_pass_no = '" . $pass_no . "')"), 'row_array');
                    $is_cluster = 0;
                    if (!empty($cluster_check['clustering_id'])) {
                        $is_cluster = 1;
                    }

                    $tps_detail[$passes->ticket_id][$passes->tps_id]['start_date'] = isset($capacity[0]['startDate']) ? date('Y-m-d', $capacity[0]['startDate']) : "";
                    $tps_detail[$passes->ticket_id][$passes->tps_id]['end_date'] = isset($capacity[0]['endDate']) ? date('Y-m-d', $capacity[0]['endDate']) : "";

                    $ticket_detail = array();
                    $ticket_detail['hotel_name'] = !empty($passes->hotel_name) ? $passes->hotel_name : '';
                    $ticket_detail['own_capacity_id'] = !empty($capacity[0]['own_capacity_id']) ? (int) $capacity[0]['own_capacity_id'] : (int) 0;
                    $ticket_detail['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0;
                    $ticket_detail['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
                    $ticket_detail['title'] = $passes->ticket_title;
                    if (isset($from_time) && isset($to_time) && isset($selected_date) && isset($slot_type)) {
                        $ticket_detail['from_time'] = $from_time;
                        $ticket_detail['to_time'] = $to_time;
                        $ticket_detail['selected_date'] = $selected_date;
                        $ticket_detail['slot_type'] = $slot_type;
                    } else {
                        $ticket_detail['from_time'] = '';
                        $ticket_detail['to_time'] = '';
                        $ticket_detail['selected_date'] = '';
                        $ticket_detail['slot_type'] = '';
                    }

                    $ticket_detail['visitor_group_no'] = (string) $passes->visitor_group_no;
                    $ticket_detail['ticket_id'] = $passes->ticket_id;
                    $ticket_detail['add_to_pass'] = (int) $add_to_pass;
                    $ticket_detail['is_voucher'] = 1;
                    $ticket_detail['is_prepaid'] = 0;
                    $ticket_detail['is_redeem'] = 0;
                    $ticket_detail['is_cluster'] = $is_cluster;
                    $ticket_detail['total_count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['citysightseen_card'] = ($add_to_pass == '1' && empty($passes->bleep_pass_no)) ? 1 : 0;
                    $ticket_detail['show_extend_button'] = ($passes->is_countdown == '1' && !empty($passes->upsell_ticket_ids)) ? 1 : 0;
                    $ticket_detail['upsell_ticket_ids'] = $passes->upsell_ticket_ids;
                    $ticket_detail['pass_no'] = $pass_no;
                    $ticket_detail['address'] = '';
                    $ticket_detail['cashier_name'] = $passes->cashier_name;
                    $ticket_detail['pos_point_name'] = $passes->pos_point_name;
                    $ticket_detail['tax'] = $passes->tax;
                    if ($passes->is_countdown == '1') {
                        $countdown_time = explode('-', $passes->countdown_interval);
                        $countdown_text = $countdown_time[1];
                        $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_time[0]);
                        if (empty($passes->scanned_at)) {
                            $passes->scanned_at = 0;
                        }
                        $ticket_detail['upsell_left_time'] = ($current_count_down_time + $passes->scanned_at) . '_' . $countdown_text . '_' . $passes->scanned_at;
                    } else {
                        $ticket_detail['upsell_left_time'] = '';
                    }
                    $ticket_detail['is_scan_countdown'] = $passes->is_countdown;
                    $ticket_detail['countdown_interval'] = $passes->countdown_interval;
                    $ticket_detail['types'] = array_values($tps_detail[$passes->ticket_id]);
                    $ticket_detail['unused_vouchers'] = array();
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
                $response['status'] = $status;
                $response['data'] = $ticket_listing;
                $response['is_ticket_listing'] = 1;
                $response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
                $response['is_prepaid'] = 0;
                $response['message'] = $message;
                $MPOS_LOGS['listing_of_linked_tickets_' . date('H:i:s')] = $logs;
                return $response;
            } else { // when combi off in case of voucher
                $tps_detail = array();
                foreach ($voucher_passes as $pass) {
                    $ticket_id = $pass->ticket_id;

                    $total_count[$pass->ticket_id] += 1;
                    if (empty($tps_detail[$pass->ticket_id][$pass->tps_id]['count'])) {
                        $count = 1;
                    } else {
                        $count = $tps_detail[$pass->ticket_id][$pass->tps_id]['count'] + 1;
                    }

                    // new changes to add start date and end date to type count array
                    $tps_detail[$pass->ticket_id][$pass->tps_id] = array('tps_id' => $pass->tps_id, 'pax' => (int) isset($pass->pax) ? $pass->pax : 0, 'capacity' => (int) isset($pass->capacity) ? $pass->capacity : 1, 'clustering_id' => 0, 'ticket_type_label' => $pass->ticket_type, 'ticket_type' => (!empty($this->types[strtolower($pass->ticket_type)]) && ($this->types[strtolower($pass->ticket_type)] > 0)) ? $this->types[strtolower($pass->ticket_type)] : 10, 'title' => $pass->ticket_title, 'ticket_id' => $pass->ticket_id, 'price' => $pass->price, 'age_group' => !empty($pass->age_group) ? $pass->age_group : '1-99', 'count' => $count, 'child_pass_no' => $pass->child_pass_no);
                }
                $logs['tps_details'] = $tps_detail;

                foreach ($voucher_passes as $passes) {
                    $child_pass_unused = $passes->child_pass_no;
                    $url_unused = $passes->url;
                    $ticket_id = $passes->ticket_id;
                    
                    $unused_passes = $this->find('pre_assigned_codes', array('select' => 'child_pass_no', 'where' => '(child_pass_no != "' . $child_pass_unused . '" && url = "' . $url_unused . '")'), 'object');
                    if (!empty($unused_passes)) {
                        foreach ($unused_passes as $unused) {
                            $get_unused[] = (string) $unused->child_pass_no;
                        }
                    } else {
                        $get_unused = array();
                    }

                    // condition to get capacity according to ticket Id
                    $capacity = $this->find('modeventcontent', array('select' => 'startDate, endDate, own_capacity_id, shared_capacity_id, is_reservation', 'where' => 'mec_id = ' . $ticket_id), 'array');
                    $tps_detail[$passes->ticket_id][$passes->tps_id]['start_date'] = isset($capacity[0]['startDate']) ? (string) date('Y-m-d', $capacity[0]['startDate']) : "";
                    $tps_detail[$passes->ticket_id][$passes->tps_id]['end_date'] = isset($capacity[0]['endDate']) ? (string) date('Y-m-d', $capacity[0]['endDate']) : "";
                    $ticket_detail = array();
                    $ticket_detail['is_combi_ticket'] = $passes->is_combi_ticket;
                    $ticket_detail['ticket_type'] = (!empty($this->types[strtolower($passes->ticket_type)]) && ($this->types[strtolower($passes->ticket_type)] > 0)) ? $this->types[strtolower($passes->ticket_type)] : 10;
                    $ticket_detail['ticket_type_label'] = $passes->ticket_type;
                    $ticket_detail['combi_pass'] = $passes->combi_pass;
                    $ticket_detail['age_group'] = $passes->age_group;
                    $ticket_detail['own_capacity_id'] = !empty($capacity[0]['own_capacity_id']) ? (int) $capacity[0]['own_capacity_id'] : (int) 0;
                    $ticket_detail['shared_capacity_id'] = !empty($capacity[0]['shared_capacity_id']) ? (int) $capacity[0]['shared_capacity_id'] : (int) 0;
                    $ticket_detail['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
                    $ticket_detail['price'] = $passes->price;
                    $ticket_detail['visitor_group_no'] = $passes->visitor_group_no;
                    $ticket_detail['hotel_name'] = !empty($passes->hotel_name) ? $passes->hotel_name : '';
                    $ticket_detail['title'] = $passes->ticket_title;
                    $ticket_detail['combi_pass'] = $passes->combi_pass;
                    $ticket_detail['visitor_group_no'] = (string) $passes->visitor_group_no;
                    if (isset($from_time) && isset($to_time) && isset($selected_date) && isset($slot_type)) {
                        $ticket_detail['from_time'] = $from_time;
                        $ticket_detail['to_time'] = $to_time;
                        $ticket_detail['selected_date'] = $selected_date;
                        $ticket_detail['slot_type'] = $slot_type;
                    } else {
                        $ticket_detail['from_time'] = '';
                        $ticket_detail['to_time'] = '';
                        $ticket_detail['selected_date'] = '';
                        $ticket_detail['slot_type'] = '';
                    }
                    $ticket_detail['early_checkin_time'] = "";
                    $ticket_detail['ticket_id'] = $passes->ticket_id;
                    $ticket_detail['tps_id'] = $passes->tps_id;
                    $ticket_detail['ticket_type'] = (!empty($this->types[strtolower($passes->ticket_type)]) && ($this->types[strtolower($passes->ticket_type)] > 0)) ? $this->types[strtolower($passes->ticket_type)] : 10;
                    $ticket_detail['price'] = $passes->price;
                    $ticket_detail['total_count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['count'] = $total_count[$passes->ticket_id];
                    $ticket_detail['is_late'] = 0;
                    $ticket_detail['group_price'] = 0;
                    $ticket_detail['total_amount'] = 0;
                    $ticket_detail['is_prepaid'] = 0;
                    $ticket_detail['is_voucher'] = 1;
                    $ticket_detail['is_redeem'] = 0;
                    $ticket_detail['action'] = 1;
                    $ticket_detail['is_cash_pass'] = 1;
                    $ticket_detail['is_pre_booked_ticket'] = 0;
                    $ticket_detail['extra_options_exists'] = 0;
                    $ticket_detail['show_group_checkin_button'] = 1;
                    $ticket_detail['scanned_at'] = $passes->scanned_at;
                    $ticket_detail['citysightseen_card'] = ($add_to_pass == '1' && empty($passes->bleep_pass_no)) ? 1 : 0;
                    $ticket_detail['show_extend_button'] = ($passes->is_countdown == '1' && !empty($passes->upsell_ticket_ids)) ? 1 : 0;
                    $ticket_detail['upsell_ticket_ids'] = $passes->upsell_ticket_ids;
                    $ticket_detail['already_used'] = $passes->used;
                    $ticket_detail['pass_no'] = $pass_no;
                    $ticket_detail['address'] = "";
                    $ticket_detail['cashier_name'] = "";
                    $ticket_detail['pos_point_name'] = "";
                    $ticket_detail['tax'] = $passes->tax;
                    if ($passes->is_countdown == '1') {
                        $countdown_time = explode('-', $passes->countdown_interval);
                        $countdown_text = $countdown_time[1];
                        $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_time[0]);
                        if (empty($passes->scanned_at)) {
                            $passes->scanned_at = 0;
                        }
                        $ticket_detail['upsell_left_time'] = ($current_count_down_time + $passes->scanned_at) . '_' . $countdown_text . '_' . $passes->scanned_at;
                    } else {
                        $ticket_detail['upsell_left_time'] = '';
                    }
                    $ticket_detail['is_scan_countdown'] = $passes->is_countdown;
                    $ticket_detail['countdown_interval'] = $passes->countdown_interval;
                    $ticket_detail['types_count'] = array_values($tps_detail[$passes->ticket_id]);
                    $ticket_detail['unused_vouchers'] = $get_unused;
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
                $MPOS_LOGS['listing_of_linked_tickets']['ticket_listing'] = $ticket_listing;
                //FINAL Ticket Listing response
                $response = array();
                $response['status'] = $status;
                $response['data'] = $ticket_listing[0];
                $response['is_ticket_listing'] = 0;
                $response['add_to_pass'] = (int) $add_to_pass;
                $response['is_prepaid'] = 0;
                $response['message'] = $message;
                $MPOS_LOGS['listing_of_linked_tickets_' . date('H:i:s')] = $logs;
                return $response;
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['listing_of_linked_tickets'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : confirm_listed_voucher_tickets().
     * @Purpose  : Used to confirm listed vochers from pre_assigned table
     * @Called   : called from confirm_pass API.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 29 Nov 2017
     */
    function confirm_listed_voucher_tickets($request_para) {
        global $MPOS_LOGS;
        try {
            $logs_data = $request_para;
            unset($logs_data['user_detail']);
            $user_detail = $request_para['user_detail'];
            $museum_id = $request_para['museum_id'];
            $ticket_id = $request_para['ticket_id'];
            $tps_id = $request_para['tps_id'];
            $pass_no = $request_para['pass_no'];
            $add_to_pass = $request_para['add_to_pass'];
            $bleep_pass_no = $request_para['bleep_pass_no'];
            $child_pass_no = $request_para['child_pass_no'];
            $selected_date = $request_para['selected_date'];
            $from_time = $request_para['from_time'];
            $to_time = $request_para['to_time'];
            $slot_type = $request_para['slot_type'];
            $shared_capacity_id = $request_para['shared_capacity_id'];
            $device_time = $request_para['device_time'];
            $pos_point_id = $request_para['pos_point_id'];
            $pos_point_name = $request_para['pos_point_name'];
            $shift_id = $request_para['shift_id'];
            $cashier_type = $user_detail->cashier_type;
            if (empty($child_pass_no)) {
                $child_pass_no = $pass_no;
            }
            $types_bleep_passes = array();
            // Check if bleep passes array is not empty then assign it in PT and update its status in bleep_pass_nos table
            if (!empty($bleep_pass_no)) {
                foreach ($bleep_pass_no as $bleep_pass_nos) {
                    $types_bleep_passes[$bleep_pass_nos['ticket_type']][] = $bleep_pass_nos['pass_no'];
                    $bleep_pass_no = $bleep_pass_nos['pass_no'];
                    $this->query('update bleep_pass_nos set status="1" where pass_no in("' . $bleep_pass_no . '")');
                }
            }
            //Update capacity in case of reservation ticket
            if ($selected_date != '' && $from_time != '') {
                $this->update_capacity($ticket_id, $selected_date, $from_time, $to_time, 1, $slot_type);
            }
            $pre_assigned_data = $data = array();
            $res = $user_detail;
            $dist_id = $user_detail->dist_id;
            $dist_cashier_id = $user_detail->dist_cashier_id;
            if($cashier_type  == '3' && $user_detail->reseller_id != '') {
                $res->user_id = $res->reseller_cashier_id;
                $name = $res->reseller_cashier_name;
            } else {
                $res->user_id = $res->uid;
                $name = $res->fname . ' ' . $res->lname;
            }
           
            $is_prepaid = $is_ticket_listing = 0;

            // Check pass details in preassigned table
            $where = "(child_pass_no='" . $child_pass_no . "' or url='" . $child_pass_no . "') and activated = '1' and ticket_id = ".$ticket_id." and tps_id = ".$tps_id;
            if ($museum_id != '' && $cashier_type != '3') {
                $where .= " and museum_id = ". $museum_id;
            }
            $pre_assigned_data = $this->find('pre_assigned_codes', array('select' => '*', 'where' => $where), 'row_object');

            $logs['preassigned_db_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            if (!empty($pre_assigned_data)) {
                $pre_assigned_data->bleep_pass_no = $bleep_pass_no;
                //get data from pre_assigned_codes table
                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
                $where = 'id="' . $pre_assigned_data->id . '"';
                // Update pass status in pre assigned table
                if ($add_to_pass == '1' && !empty($bleep_pass_no)) {
                      $this->update("pre_assigned_codes", array("bleep_pass_no" => $bleep_pass_no, 'active' => 1), array("id" => $pre_assigned_data->id));
                }
                if (empty($bleep_pass_no)) {
                    $this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $res->user_id, "museum_cashier_name" => $name, "scanned_at" => $scanned_at), array("url" => $pre_assigned_data->url));
                } else {
                    $this->update("pre_assigned_codes", array("active" => "1", "museum_cashier_id" => $res->user_id, "museum_cashier_name" => $name, "scanned_at" => $scanned_at), array("url" => $pre_assigned_data->url));
                }
                $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                $logs = array();
                if ($pre_assigned_data->is_countdown == '0') {
                    $preassigned_data = $this->response_for_simple_listed_ticket($dist_id, $dist_cashier_id, $cashier_type, $pre_assigned_data, $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $device_time, $add_to_pass, $pos_point_id, $pos_point_name, $shift_id, $res);
                } else {
                    if($add_to_pass == '0'){
                        $pre_assigned_data->scanned_at = $scanned_at;
                        $pre_assigned_data->used = '1';
                    }
                    $preassigned_data = $this->response_for_listed_count_down($dist_id, $dist_cashier_id, $cashier_type, $pre_assigned_data, $child_pass_no, $res);
                }
                if(!empty($preassigned_data['error_no']) && $preassigned_data['error_no'] > 0){ //some error in function
                     return $preassigned_data;
                }

                $data = $preassigned_data['data'];
                $status = $preassigned_data['status'];
                $is_prepaid = $preassigned_data['is_prepaid'];
                $is_ticket_listing = $preassigned_data['is_ticket_listing'];
                $message = $preassigned_data['message'];
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

                    if ($key == 'pass_no') {
                        $item = (string) $item;
                    }
                    $item = null === $item ? '' : $item;
                });
            }

            $response = array('status' => $status, 'message' => $message, 'is_prepaid' => $is_prepaid, 'is_ticket_listing' => $is_ticket_listing, 'add_to_pass' => $add_to_pass, 'data' => $data);
            $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['confirm_listed_voucher_tickets'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : confirm_pass_preassigned().
     * @Purpose  : Used to confirm listed vochers from pre_assigned table
     * @Called   : called from confirm_pass_preassigned API
     * @parameters :preassigned pass data.
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 21 Sep 2018
     */
    function confirm_listed_preassigned_voucher($request_para) {
        global $MPOS_LOGS;
        try {
            $logs_data = $request_para;
            unset($logs_data['user_detail']);
            $user_detail = $request_para['user_detail'];
            $museum_id = $request_para['museum_id'];
            $ticket_id = $request_para['ticket_id'];
            $tps_id = $request_para['tps_id'];
            $pass_no = $request_para['pass_no'];
            $add_to_pass = $request_para['add_to_pass'];
            $bleep_pass_no = $request_para['bleep_pass_no'];
            $child_pass_no = $request_para['child_pass_no'];
            $selected_date = $request_para['selected_date'];
            $from_time = $request_para['from_time'];
            $to_time = $request_para['to_time'];
            $slot_type = $request_para['slot_type'];
            $shared_capacity_id = $request_para['shared_capacity_id'];
            $shift_id = $request_para['shift_id'];
            $pos_point_id = $request_para['pos_point_id'];
            $pos_point_name = $request_para['pos_point_name'];
            
            if (empty($child_pass_no)) {
                $child_pass_no = $pass_no;
            }
            $types_bleep_passes = array();
			
            // If bleep passes are not empty in request, update its status in bleep_pass_nos table
            if (!empty($bleep_pass_no)) {
                foreach ($bleep_pass_no as $bleep_pass_nos) {
                    $types_bleep_passes[$bleep_pass_nos['ticket_type']][] = $bleep_pass_nos['pass_no'];
                    $bleep_pass_no = $bleep_pass_nos['pass_no'];
                    $this->query('update bleep_pass_nos set status="1",last_modified_at = "' . gmdate("Y-m-d H:i:s") . '" where pass_no in("' . $bleep_pass_no . '")');
                }
            }
            $pre_assigned_data = array();
            $res = $user_detail;
            $dist_id = $user_detail->dist_id;
            $dist_cashier_id = $user_detail->dist_cashier_id;
            $cashier_type = $user_detail->cashier_type;
            if($cashier_type  == '3' && $user_detail->reseller_id != '') {
                $res->user_id = $res->reseller_cashier_id;
                $name = $res->reseller_cashier_name;
            } else {
                $res->user_id = $res->uid;
                $name = $res->fname . ' ' . $res->lname;
            }
            
            $data = array();
            $is_prepaid = 0;
            $is_ticket_listing = 0;
            if(strlen($child_pass_no) == PASSLENGTH) {
                $child_pass_no = 'http://qu.mu/'.$pass_no;
            }
            
            //Fetch pass details from preassigned table and update its status
            $where = '(child_pass_no = "' . $child_pass_no . '" or url = "' . $child_pass_no . '") and activated = "1" and active = "0" and ticket_id = '.$ticket_id.' and tps_id = '.$tps_id;
            if ($museum_id != '' && $user_detail->cashier_type == '3') {
                $where .= ' and museum_id = '. $museum_id;
            }
            $pre_assigned_data = $this->find('pre_assigned_codes', array('select' => '*', 'where' => $where), 'row_object');

            $logs['preassigned_db_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
            if (!empty($pre_assigned_data)) {
                $pre_assigned_data->bleep_pass_no = $bleep_pass_no;
                $capacity_update = $pre_assigned_data->capacity;
                //get data from pre_assigned_codes table
                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
                $where = 'id="' . $pre_assigned_data->id . '"';
                if ($add_to_pass == '1' && !empty($bleep_pass_no)) {
                    $this->update("pre_assigned_codes", array("bleep_pass_no" => $bleep_pass_no, 'active' => 1), array("id" => $pre_assigned_data->id));
                }

                if (empty($bleep_pass_no)) {
                    $this->update("pre_assigned_codes", array("active" => "1", "used" => "1", "museum_cashier_id" => $res->user_id, "museum_cashier_name" => $name, "scanned_at" => $scanned_at), array("url" => $pre_assigned_data->url));
                } else {
                    $this->update("pre_assigned_codes", array("active" => "1", "museum_cashier_id" => $res->user_id, "museum_cashier_name" => $name, "scanned_at" => $scanned_at), array("url" => $pre_assigned_data->url));
                }
                $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                $logs = array();
                //Call functions separately for each timebased tickets and non-timebased tickets
                if ($pre_assigned_data->is_countdown == '0') {
                    $preassigned_data = $this->response_for_simple_listed_ticket($dist_id, $dist_cashier_id, $cashier_type, $pre_assigned_data, $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $device_time, $add_to_pass, $pos_point_id, $pos_point_name, $shift_id, $res);
                } else {
                    if($add_to_pass == '0'){
                        $pre_assigned_data->scanned_at = $scanned_at;
                    }
                    $preassigned_data = $this->response_for_listed_count_down($dist_id, $dist_cashier_id, $cashier_type, $pre_assigned_data, $child_pass_no, $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $pos_point_id, $pos_point_name, $shift_id, $res);
                }
                if(!empty($preassigned_data['error_no']) && $preassigned_data['error_no'] > 0){ //some error in function
                    return $preassigned_data;
                }

                $data = $preassigned_data['data'];
                $status = $preassigned_data['status'];
                $is_prepaid = $preassigned_data['is_prepaid'];
                $is_ticket_listing = $preassigned_data['is_ticket_listing'];
                $message = $preassigned_data['message'];
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

            $response = array('status' => $status, 'message' => $message, 'is_prepaid' => $is_prepaid, 'is_ticket_listing' => $is_ticket_listing, 'add_to_pass' => $add_to_pass, 'data' => $data, 'capacity_update' => $capacity_update);
            $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['confirm_listed_preassigned_voucher'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : response_for_simple_listed_ticket().
     * @Purpose  : Used to confirm listed vochers from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 29 Nov 2017
     */
    function response_for_simple_listed_ticket($dist_id = 0, $dist_cashier_id = 0, $cashier_type = 0, $pre_assigned_data = array(), $selected_date = '', $from_time = '', $to_time = '', $slot_type = '', $shared_capacity_id = 0, $device_time = '',$add_to_pass = 0, $pos_point_id = 0,$pos_point_name = '', $shift_id = 0, $res = '') {
        global $MPOS_LOGS;
        try {
            $logs['pre_assigned_data_' . date('H:i:s')] = $pre_assigned_data;
            $logs['selected_date, from_time, to_time, slot_type, shared_capacity_id, device_time,pos_point_id,pos_point_name'] = array($selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $device_time, $pos_point_id, $pos_point_name);
            $request_data = array();
            $is_redeem = 0;
            $used = '0';
            $current_day = strtotime(date("Y-m-d"));
            $device = $device_time / 1000;
            $device_time_check = $device;
            $from_time_check = strtotime($from_time);
            $to_time_check = strtotime($to_time);
            $selected_date_check = strtotime($selected_date);

            // On sechdule cases
            if ($selected_date_check == $current_day) {
                if ($from_time_check <= $device_time_check && $to_time_check >= $device_time_check) {
                    $is_redeem = 1;
                    $used = '1';
                } else {
                    $is_redeem = 0;
                    $used = '0';
                }
            } else {
                $is_redeem = 0;
                $used = '0';
            }

            $capacity = $this->find('modeventcontent', array('select' => 'is_reservation, capacity_type, guest_notification', 'where' => 'mec_id = '.$pre_assigned_data->ticket_id), 'array');

            //for booking type tickets -> direct redeem
            if ($capacity[0]['is_reservation'] != 1) {
                $used = '1';
                $is_redeem = 1;
            }

            $statusConfirm = true;
            if($is_redeem == 1 && $used == '1') {

                /* count PT records for redumption count in batch_rule table */
                $countPtRecords = 1;

                /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
                $batch_rule_id = 0;
                $batch_not_found = false;
                $limitArray = array(
                                    "shared_capacity_id" => $shared_capacity_id, 
                                    "cashier_id" => $res->user_id, 
                                    "cod_id" => $pre_assigned_data->museum_id, 
                                    "capacity_type" => $capacity[0]['capacity_type'], 
                                    "selected_date" => $selected_date, 
                                    "from_time" => $from_time, 
                                    "to_time" => $to_time, 
                                    "ticket_id" => $pre_assigned_data->ticket_id);
                $validate = $this->validate_cashier_limit($limitArray);
                if(!empty($validate) && is_array($validate)) {

                    if($validate['status']=='batch_not_found') {
                        $statusConfirm = false;
                        $batch_not_found = true;
                    }
                    if($validate['status']=='qty_expired') {
                        $statusConfirm = false;
                    }
                    if($validate['status']=='redeem') {
                        $batch_rule_id  = $validate['batch_rule_id'];
                        $last_scans = $validate['last_scan'];
                        $maximum_quantity  = $validate['maximum_quantity'];
                        $quantity_redeemed  = $validate['quantity_redeemed'];
						$rules_data  = $validate['rules_data'];
                        $left_qunatity = ($maximum_quantity-$quantity_redeemed);
                        if($left_qunatity == 0 || $left_qunatity < $countPtRecords) {
                            $statusConfirm = false;
                        }
						$notifyQuantity = ($countPtRecords+$quantity_redeemed);
						$this->emailNotification($rules_data, $notifyQuantity);
                    }
                }
            }

            if($statusConfirm == true) {

                $merchant_admin_id = 0;
                $merchant_admin_name = '';
                $preData = $this->find('pre_assigned_codes', array('select' => 'ticket_id, hotel_id, tps_id', 'where' => 'id = "'.$pre_assigned_data->id.'"'), 'row_array');
                if(!empty($preData)) {

                    $hotel_id = $preData['hotel_id'];
                    $merchant = $this->find('modeventcontent', array('select' => 'merchant_admin_id, merchant_admin_name', 'where' => 'mec_id = '.$preData['ticket_id']), 'row_array');
                    if(!empty($merchant)) {
                        $merchant_admin_id = $merchant['merchant_admin_id'];
                        $merchant_admin_name = $merchant['merchant_admin_name'];
                    }

                    /* Check TLC/CLC level if prices are modified and add value to PT */
                    $merchant_data = $this->get_price_level_merchant($preData['ticket_id'], $hotel_id,  $preData['tps_id']);
                    if(!empty($merchant_data)) {
                        $merchant_admin_id = $merchant_data->merchant_admin_id;
                        $merchant_admin_name = $merchant_data->merchant_admin_name;
                    }
                    /* Check TLC/CLC level if prices are modified and add  value to PT */
                }

                $request_data['dist_id'] = (isset($dist_id) && $dist_id > 0) ? $dist_id : 0;
                $request_data['dist_cashier_id'] = (isset($dist_cashier_id) && $dist_cashier_id > 0) ? $dist_cashier_id : 0;
                $request_data['pos_point_id_on_redeem'] = (isset($pos_point_id) && $pos_point_id > 0) ? $pos_point_id : 0;
                $request_data['pos_point_name_on_redeem'] = (isset($pos_point_name) && $pos_point_name != '') ? $pos_point_name : '';
                $request_data['museum_id'] = $pre_assigned_data->museum_id;
                $request_data['ticket_id'] = $pre_assigned_data->ticket_id;
                $request_data['tps_id'] = $pre_assigned_data->tps_id;
                $request_data['pass_no'] = $pre_assigned_data->url;
                $request_data['child_pass_no'] = $pre_assigned_data->child_pass_no;
                $request_data['bleep_pass_no'] = $pre_assigned_data->bleep_pass_no;
                $request_data['extra_discount'] = $pre_assigned_data->extra_discount;
                $request_data['order_currency_extra_discount'] = $pre_assigned_data->order_currency_extra_discount;
                $request_data['discount'] = $pre_assigned_data->discount;
                $request_data['selected_date'] = $selected_date;
                $request_data['from_time'] = $from_time;
                $request_data['to_time'] = $to_time;
                $request_data['slot_type'] = $slot_type;
                $request_data['shared_capacity_id'] = $shared_capacity_id;
                $request_data['shift_id'] = $shift_id;
                $request_data['pos_point_id'] = $pos_point_id;
                $request_data['pos_point_name'] = $pos_point_name;
                $request_data['used'] = $used;
                $request_data['merchant_admin_id'] = $merchant_admin_id;
                $request_data['merchant_admin_name'] = $merchant_admin_name;
                if (isset($validate['batch_id']) && $validate['batch_id'] != 0) {
                    $request_data['batch_id'] =  $validate['batch_id'];
                }

                $show_scanner_receipt = 0;
                if (!empty($pre_assigned_data->bleep_pass_no)) {
                    $show_scanner_receipt = 1;
                }
                if($cashier_type == '2'){
                    $show_scanner_receipt = 0;
                }
                
                if (!empty($request_data)) {
                    $request_array['update_pre_assigned_records'] = $request_data;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "response_for_simple_listed_ticket";
                    $request_array['pass_no'] = $pre_assigned_data->child_pass_no;
                    $request_array['channel_type'] = '5';
                    $request_array['visitor_group_no'] = $pre_assigned_data->visitor_group_no;
                    $logs['data_to_queue_UPDATE_DB_QUEUE_URL_'.date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);  
                    }
                }

                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
                $status = 1;
                $message = 'Valid';

                /* UPDATE redumption of cashier in batch_rule table */
                if(!empty($batch_rule_id) && $countPtRecords>0) {
                    $strUpd = '';
                    if(strtotime($last_scans) < strtotime(date('Y-m-d'))) {
                        $strUpd.= "quantity_redeemed = 0, ";
                    }
                    $strUpd.= "quantity_redeemed = (quantity_redeemed+{$countPtRecords}), last_scan = '".date('Y-m-d')."'";

                    $this->query("UPDATE batch_rules SET {$strUpd} WHERE batch_rule_id = {$batch_rule_id}");
                    $MPOS_LOGS['update_batch_rules_'.date('H:i:s')] = $this->db->last_query();
                }
            } 
            else {

                $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
                $status = 12;
                $message = ($batch_not_found==true? 'Batch not found.': 'Batch capacity is full');
            }

            $arrReturn = array();

            $ticket_type = (!empty($this->types[trim(strtolower($pre_assigned_data->ticket_type))]) && ($this->types[trim(strtolower($pre_assigned_data->ticket_type))] > 0)) ? $this->types[trim(strtolower($pre_assigned_data->ticket_type))] : 10;
            $arrReturn['title'] = $pre_assigned_data->ticket_title;
            $arrReturn['guest_notification'] = isset($capacity[0]['guest_notification']) ? $capacity[0]['guest_notification'] : '';
            $arrReturn['sold_by'] = $pre_assigned_data->hotel_name;
            $arrReturn['ticket_type'] = !empty($ticket_type) ? $ticket_type : 10;
            $arrReturn['ticket_type_label'] = $pre_assigned_data->ticket_type;
            $arrReturn['age_group'] = $pre_assigned_data->age_group;
            $arrReturn['price'] = $pre_assigned_data->price;
            $arrReturn['group_price'] = 0;
            $arrReturn['visitor_group_no'] = (string) $pre_assigned_data->visitor_group_no;
            $arrReturn['ticket_id'] = $pre_assigned_data->ticket_id;
            $arrReturn['tps_id'] = $pre_assigned_data->tps_id;
            $arrReturn['pass_no'] = !empty($pre_assigned_data->child_pass_no) ? (string) $pre_assigned_data->child_pass_no : '';
            $arrReturn['count'] = 1;
            $arrReturn['is_voucher'] = 1;
            $arrReturn['is_reservation'] = !empty($capacity[0]['is_reservation']) ? (int) $capacity[0]['is_reservation'] : (int) 0;
            $arrReturn['from_time'] = !empty($from_time) ? (string) $from_time : '';
            $arrReturn['to_time'] = !empty($to_time) ? (string) $to_time : '';
            $arrReturn['selected_date'] = !empty($selected_date) ? (string) $selected_date : '';
            $arrReturn['slot_type'] = !empty($slot_type) ? (string) $slot_type : '';
            $arrReturn['is_redeem'] = ($is_redeem != null) ? $is_redeem : 0;
            $arrReturn['is_scan_countdown'] = 0;
            $arrReturn['show_scanner_receipt'] = $show_scanner_receipt;
            $arrReturn['is_late'] = 0;
            $arrReturn['countdown_interval'] = '';
            $arrReturn['hotel_name'] = !empty($pre_assigned_data->hotel_name) ? $pre_assigned_data->hotel_name : '';
            $arrReturn['scanned_at'] = date('d/m/Y H:i:s', $scanned_at);

            //FINAL pre_assigned response
            if (!empty($arrReturn)) {
                //replace null with blank
                array_walk_recursive($data, function (&$item, $key) {
                    $item = (empty($item) && is_array($item)) ? array() : $item;
                    if ($key != 'pass_no') {
                        $item = is_numeric($item) ? (int) $item : $item;
                    }
                    if ($key == 'pass_no') {
                        $item = (string) $item;
                    }

                    $item = null === $item ? '' : $item;
                });
            }
            $response = array();
            $response['data'] = $arrReturn;
            $response['is_ticket_listing'] = 0;
            $response['is_prepaid'] = 0;
            $response['status'] = $status;
            $response['message'] = $message;
            $MPOS_LOGS['response_for_simple_listed_ticket'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['response_for_simple_listed_ticket'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : response_for_listed_count_down().
     * @Purpose  : Used to confirm listed vochers from pre_assigned table
     * @Called   : calledwhen scab qrcode from venue app.
     * @parameters :preassigned pass data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 29 Nov 2017
     */
    function response_for_listed_count_down($dist_id = 0, $dist_cashier_id = 0, $cashier_type = 0, $pre_assigned_data = array(), $pass_no = '', $selected_date = '', $from_time = '', $to_time = '', $slot_type = '', $shared_capacity_id = 0,$pos_point_id = 0,$pos_point_name = '', $shift_id = 0, $res = array()) {
        global $MPOS_LOGS;
        try {

            $logs['pre_assigned_data_' . date('H:i:s')] = $pre_assigned_data;
            $logs['pass_no, selected_date, from_time, to_time, slot_type, shared_capacity_id,pos_point_id,pos_point_name'] = array($pass_no, $selected_date, $from_time, $to_time, $slot_type, $shared_capacity_id, $pos_point_id, $pos_point_name);

            $scanned_at = strtotime(gmdate('m/d/Y H:i:s'));
            $arrReturn = array();
            $status = 1;
            $message = 'Valid';
            /* HIT SNS to insert record in HTO and PT */
            if ($status) {
				
                $statusConfirm = true;
                if($pre_assigned_data->used=='1') {

                    /* count PT records for redumption count in batch_rule table */
                    $countPtRecords = 1;

                    /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
                    $capacityType = $this->find('modeventcontent', array("select" => 'capacity_type', "where" => "mec_id = '{$pre_assigned_data->ticket_id}'"), 'row_array');

                    $batch_rule_id = 0;
                    $batch_not_found = false;
                    $limitArray = array("passNo" => $pass_no, 
                                        "shared_capacity_id" => $shared_capacity_id, 
                                        "cod_id" => $pre_assigned_data->museum_id, 
                                        "capacity_type" => $capacityType['capacity_type'], 
                                        "selected_date" => $selected_date, 
                                        "from_time" => $from_time, 
                                        "to_time" => $to_time, 
                                        "ticket_id" => $pre_assigned_data->ticket_id);
                    $validate = $this->validate_cashier_limit($limitArray);
                    if(!empty($validate) && is_array($validate)) {

                        if($validate['status']=='batch_not_found') {
                            $statusConfirm = false;
                            $batch_not_found = true;
                        }
                        if($validate['status']=='qty_expired') {
                            $statusConfirm = false;
                        }
                        if($validate['status']=='redeem') {
                            $batch_rule_id  = $validate['batch_rule_id'];
                            $last_scans = $validate['last_scan'];
                            $maximum_quantity  = $validate['maximum_quantity'];
                            $quantity_redeemed  = $validate['quantity_redeemed'];
							$rules_data  = $validate['rules_data'];
                            $left_qunatity = ($maximum_quantity-$quantity_redeemed);
                            if($left_qunatity == 0 || $left_qunatity < $countPtRecords) {
                                $statusConfirm = false;
                            }
							$notifyQuantity = ($countPtRecords+$quantity_redeemed);
							$this->emailNotification($rules_data, $notifyQuantity);
                        }
                    }
                }
                
                if($statusConfirm == true) {
                    $merchant_admin_id = 0;
                    $merchant_admin_name = '';
                    $preData = $this->find('pre_assigned_codes', array('select' => 'ticket_id, hotel_id, tps_id', 'where' => 'id = "'.$pre_assigned_data->id.'"'), 'row_array');
                    if(!empty($preData)) {
    
                        $hotel_id = $preData['hotel_id'];
                        $merchant = $this->find('modeventcontent', array('select' => 'merchant_admin_id, merchant_admin_name', 'where' => 'mec_id = '.$preData['ticket_id']), 'row_array');
                        if(!empty($merchant)) {
                            $merchant_admin_id = $merchant['merchant_admin_id'];
                            $merchant_admin_name = $merchant['merchant_admin_name'];
                        }
    
                        /* Check TLC/CLC level if prices are modified and add value to PT */
                        $merchant_data = $this->get_price_level_merchant($preData['ticket_id'], $hotel_id, $preData['tps_id']);
                        if(!empty($merchant_data)) {
                            $merchant_admin_id = $merchant_data->merchant_admin_id;
                            $merchant_admin_name = $merchant_data->merchant_admin_name;
                        }
                        /* Check TLC/CLC level if prices are modified and add  value to PT */
                    }

                    $request_data = array();
                    $request_data['dist_id'] = (isset($dist_id) && $dist_id > 0) ? $dist_id : 0;
                    $request_data['dist_cashier_id'] = (isset($dist_cashier_id) && $dist_cashier_id > 0) ? $dist_cashier_id : 0;
		            $request_data['pos_point_id_on_redeem'] = (isset($pos_point_id) && $pos_point_id > 0) ? $pos_point_id : 0;
		            $request_data['pos_point_name_on_redeem'] = (isset($pos_point_name) && $pos_point_name != '') ? $pos_point_name : '';
                    $request_data['museum_id'] = $pre_assigned_data->museum_id;
                    $request_data['ticket_id'] = $pre_assigned_data->ticket_id;
                    $request_data['tps_id'] = $pre_assigned_data->tps_id;
                    $request_data['pass_no'] = $pre_assigned_data->url;
                    $request_data['child_pass_no'] = $pass_no;
                    $request_data['bleep_pass_no'] = $pre_assigned_data->bleep_pass_no;
                    $request_data['extra_discount'] = $pre_assigned_data->extra_discount;
                    $request_data['order_currency_extra_discount'] = $pre_assigned_data->order_currency_extra_discount;
                    $request_data['discount'] = $pre_assigned_data->discount;
                    $request_data['selected_date'] = $selected_date;
                    $request_data['from_time'] = $from_time;
                    $request_data['to_time'] = $to_time;
                    $request_data['slot_type'] = $slot_type;
                    $request_data['shared_capacity_id'] = $shared_capacity_id;
                    $request_data['shift_id'] = $shift_id;
                    $request_data['pos_point_id'] = $pos_point_id;
                    $request_data['pos_point_name'] = $pos_point_name;
                    $request_data['used'] = $pre_assigned_data->used;
                    $request_data['merchant_admin_id'] = $merchant_admin_id;
                    $request_data['merchant_admin_name'] = $merchant_admin_name;
                    if (isset($validate['batch_id']) && $validate['batch_id'] != 0) {
                        $request_data['batch_id'] = $validate['batch_id'];
                    }
                    $request_data['cashier_type'] = $cashier_type;
                    $request_data['reseller_id'] =  $res->reseller_id != '' ? $res->reseller_id : '' ;
                    $request_data['reseller_cashier_id'] =  $res->reseller_cashier_id != '' ? $res->reseller_cashier_id : '' ;
                    $show_scanner_receipt = 0;
                    if (!empty($pre_assigned_data->bleep_pass_no)) {
                        $show_scanner_receipt = 1;
                    }
                    if($cashier_type == '2') {
                        $show_scanner_receipt = 0;
                    }
			
                    if (!empty($request_data)) {
                        $request_array['update_pre_assigned_records'] = $request_data;
                        $request_array['write_in_mpos_logs'] = 1;
                        $request_array['action'] = "response_for_listed_count_down";
                        $request_array['visitor_group_no'] = $pre_assigned_data->visitor_group_no;
                        $request_array['pass_no'] = $pre_assigned_data->child_pass_no;
                        $request_array['channel_type'] = '5';
                        $request_string = json_encode($request_array);
                         $logs['data_to_queue_UPDATE_DB_QUEUE_URL_'.date('H:i:s')] = $request_array;
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        if (LOCAL_ENVIRONMENT == 'Local') {
                            local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                        } else {
                            $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);  
                        }
                    }
					
                    /* UPDATE redumption of cashier in batch_rule table */
                    if(!empty($batch_rule_id) && $countPtRecords>0) {
                        $strUpd = '';
                        if(strtotime($last_scans) < strtotime(date('Y-m-d'))) {
                            $strUpd.= "quantity_redeemed = 0, ";
                        }
                        $strUpd.= "quantity_redeemed = (quantity_redeemed+{$countPtRecords}), last_scan = '".date('Y-m-d')."'";

                        $this->query("UPDATE batch_rules SET {$strUpd} WHERE batch_rule_id = {$batch_rule_id}");
                        $MPOS_LOGS['update_batch_rules_'.date('H:i:s')] = $this->db->last_query();
                    }
                }
                else {

                    $status = 12;
                    $message = ($batch_not_found==true? 'Batch not found.': 'Batch capacity is full');
                }
					
                $countdown_time = explode('-', $pre_assigned_data->countdown_interval);
                $countdown_text = $countdown_time[1];
                $current_count_down_time = $this->get_count_down_time($countdown_text, $countdown_time[0]);
                if (empty($pre_assigned_data->scanned_at)) {
                    $pre_assigned_data->scanned_at = 0;
                }
                // Make array to return in response
                $mec_data = $this->find('modeventcontent', array('select' => 'upsell_ticket_ids, guest_notification', 'where' => 'mec_id = "'.$request_data['ticket_id'].'"'), 'row_object');
                $ticket_type = (!empty($this->types[trim(strtolower($pre_assigned_data->ticket_type))]) && ($this->types[trim(strtolower($pre_assigned_data->ticket_type))] > 0)) ? $this->types[trim(strtolower($pre_assigned_data->ticket_type))] : 10;
                $arrReturn['title'] = $pre_assigned_data->ticket_title;
                $arrReturn['guest_notification'] = isset($mec_data->guest_notification) ? $mec_data->guest_notification : '';
                $arrReturn['sold_by'] = $pre_assigned_data->hotel_name;
                $arrReturn['ticket_type'] = !empty($ticket_type) ? $ticket_type : 10;
                $arrReturn['ticket_type_label'] = $pre_assigned_data->ticket_type;
                $arrReturn['age_group'] = $pre_assigned_data->age_group;
                $arrReturn['price'] = $pre_assigned_data->price;
                $arrReturn['group_price'] = 0;
                $arrReturn['visitor_group_no'] = (string) $pre_assigned_data->visitor_group_no;
                $arrReturn['ticket_id'] = $pre_assigned_data->ticket_id;
                $arrReturn['tps_id'] = $pre_assigned_data->tps_id;
                $arrReturn['count'] = 1;
                $arrReturn['show_extend_button'] = ($mec_data->upsell_ticket_ids != '') ? 1 : 0;
                $arrReturn['upsell_ticket_ids'] = $mec_data->upsell_ticket_ids;
                $arrReturn['pass_no'] = $pass_no;
                $arrReturn['show_scanner_receipt'] = $show_scanner_receipt;
                $arrReturn['upsell_left_time'] = ($current_count_down_time + $pre_assigned_data->scanned_at) . '_' . $countdown_text . '_' . $pre_assigned_data->scanned_at;
                $arrReturn['is_scan_countdown'] = 1;
                $arrReturn['is_late'] = 0;
                $arrReturn['channel_type'] = 5;
                $arrReturn['countdown_interval'] = '';
                $current_time = strtotime(gmdate('m/d/Y H:i:s'));
                $difference = $current_time - $pre_assigned_data->scanned_at;
                $left_time_user_format = $this->get_arrival_status_from_time($current_time, $pre_assigned_data->scanned_at, $current_count_down_time);

                if ($current_count_down_time > $difference || $current_count_down_time == $difference) {
                    $arrReturn['countdown_interval'] = $left_time_user_format;
                } else {
                    $arrReturn['is_late'] = 1;
                    $arrReturn['countdown_interval'] = $left_time_user_format . ' late';
                }

                $arrReturn['hotel_name'] = !empty($pre_assigned_data->hotel_name) ? $pre_assigned_data->hotel_name : '';
                $arrReturn['scanned_at'] = date('d/m/Y H:i:s', $scanned_at);
            }

            //FINAL pre_assigned response
            $response = array();
            $response['data'] = $arrReturn;
            $response['is_ticket_listing'] = 0;
            $response['is_prepaid'] = 0;
            $response['status'] = $status;
            $response['message'] = $message;
            $MPOS_LOGS['response_for_listed_count_down'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['response_for_listed_count_down'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /* #endregion Scan Process Module : Cover all the functions used in scanning process of mpos */
}


?>