<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TVenue_model {

    function __construct() {
        // Call the Model constructor
        parent::__construct();
        $this->load->model('V1/museum_model');
        $this->load->model('V1/hotel_model');
        $this->load->model('V1/firebase_model');
        $this->api_channel_types = array(4, 6, 7, 8, 9, 13, 5); // API Chanell types
    }

    /**
     * @Name     : confirm_postpaid_tickets().
     * @Purpose  : confirm postpaid tickets on selecting 1 ticket from app in postpaid process
     * @parameters :$request_para array.
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 25 Feb 2019
     */
    function confirm_postpaid_tickets($request_para) {
        global $internal_logs;
        global $MPOS_LOGS;
        try {
            $museum_data = $request_para['user_detail'];
            $pass_no = $request_para['pass_no'];
            $visitor_group_no = $request_para['visitor_group_no'];
            $ticket_id = $request_para['ticket_id'];
            $selected_date = $request_para['selected_date'];
            $from_time = $request_para['from_time'];
            $to_time = $request_para['to_time'];
            $ticket_title = $request_para['ticket_title'];
            $shared_capacity_id = $request_para['shared_capacity_id'];
            $is_reservation = $request_para['is_reservation'];
            $own_capacity_id = $request_para['own_capacity_id'];
            $museum_id = $request_para['museum_id'];
            $channel_type = $request_para['channel_type'];
            $is_discount_in_percent = $request_para['discount_type'];
            $tax_id = $request_para['ticket_tax_id'];
            $timezone = ltrim($request_para['timezone'], 'GMT');
            $shift_id = $request_para['shift_id'];
            $payment_method = $request_para['payment_method'];
            $action = $request_para['action'];
            $used = $request_para['used'];
            $types = $request_para['ticket_types'];
            $hotel_id = $request_para['hotel_id'];
            $timeslot = $request_para['timeslot'];
            $prepaid_ticket_ids = $request_para['prepaid_ticket_ids'];
            
            $statusConfirm = true;
            /* count PT records for redumption count in batch_rule table */
            if(($used == 0 && $action == 0) || ($used == 1 && $action == 1)) { 
                $countPtRecords = 1;
            } else if ($used == 1 && $action == 2) {
                
                foreach ($types as $type) {
                    $types_data[$type['tps_id']] = array(
                        'tps_id' => $type['tps_id'],
                        'extra_options' => $type['extra_options'],
                        'pax' => $type['pax'],
                        'capacity' => $type['capacity'],
                        'pricetext' => $type['price'],
                        'ticket_amount' => $type['price_after_discount'],
                        'save_amount' => $type['save_amount'],
                        'age_group' => $type['age_group'],
                        'ticket_type' => $type['ticket_type'],
                        'count' => $type['count'],
                    );

                    $ticket_types[$type['tps_id']] = array(
                        'tps_id' => (int) $type['tps_id'],
                        'ticket_type' => (int) $type['ticket_type'],
                        'age_group' => (string) $type['age_group'],
                        'price' => (float) $type['price'],
                        'count' => (int) $type['count']
                    );
                }

                $existing_enteries = $this->find('prepaid_tickets', array('select' => 'prepaid_ticket_id, tps_id', 'where' => 'visitor_group_no = "'.$visitor_group_no.'" and museum_id = "'.$museum_id.'" and ticket_id = "' . $ticket_id . '" and used = "0" and activated = "1"'), 'list');
                $logs['fetch_existing_enteries'] = $this->primarydb->db->last_query();
                $logs['existing_enteries'] = $existing_enteries;
                foreach($existing_enteries as $pt_id => $tps_id){
                                    $type_based_pt_ids[$tps_id][] = $pt_id;
                }
                
                $insQty = 0;
                $logs['type_based_pt_ids'] = $type_based_pt_ids;
                $logs['types_data'] = $types_data;
                foreach($types_data as $tps_id => $type) {
                    $i = 0;
                    do {
                        if (isset($type_based_pt_ids[$tps_id][$i])) {
                            $pt_ids[] = $type_based_pt_ids[$tps_id][$i];
                        } else {
                            $insert_tps_count[$tps_id]++; 
                            $insQty++;
                        }
                        $i++;
                    } while ($i < $type['count']);
                }
                /* Total count of insertion and updation for cashier quantity check */
                $countPtRecords = (count($pt_ids)+$insQty);
            }
            
            /* get existing ticket_booking_id's for the VGN */
            $ticketBookingCheck = $this->get_existing_ticket_booking_ids($visitor_group_no);
            $logs['ticketBookingCheck_query'] = $this->primarydb->db->last_query();
            $logs['ticketBookingCheck'] = $ticketBookingCheck;
            
            /* CHECK CASHIER REDEEM LIMIT FOR THE TIMESLOT */
            $capacityType = $this->find('modeventcontent', array("select" => 'capacity_type', "where" => "mec_id = '{$ticket_id}'"), 'row_array');
            
            $batch_rule_id = 0;
            $batch_not_found = false;
            $limitArray = array("passNo" => $pass_no, "shared_capacity_id" => $shared_capacity_id, 
                                "cashier_id" => $museum_data->uid, 
                                "cod_id" => $museum_id, 
                                "capacity_type" => $capacityType['capacity_type'], 
                                "selected_date" => $selected_date,
                                "from_time" => $from_time, 
                                "to_time" => $to_time, 
                                "ticket_id" => $ticket_id);
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
                    $maximum_quantity  = $validate['maximum_quantity'];
                    $quantity_redeemed  = $validate['quantity_redeemed'];
                    $last_scans = $validate['last_scan'];
                    $rules_data = $validate['rules_data'];
                    $left_qunatity = ($maximum_quantity-$quantity_redeemed);
                    if($left_qunatity == 0 || $left_qunatity < $countPtRecords) {
                        $statusConfirm = false;
                    }
                    $notifyQuantity = ($countPtRecords+$quantity_redeemed);
                    $this->emailNotification($rules_data, $notifyQuantity);
                }
            }
            
            if($statusConfirm == true) {
            
                $batch_id = isset($validate['batch_id']) ? $validate['batch_id'] : 0;
                if(($used == 0 && $action == 0) || ($used == 1 && $action == 1)) { //for one quantity
                    $tps_id = $types[0]['tps_id'];
                    $extra_options = $types[0]['extra_options'];
                    $pax = $types[0]['pax'];
                    $capacity = $types[0]['capacity'];
                    $pricetext = $types[0]['price'];
                    $ticket_amount = $types[0]['price_after_discount'];
                    $save_amount = $types[0]['save_amount'];
                    $age_group = $types[0]['age_group'];
                    $ticket_type = $types[0]['ticket_type'];

                    if ($used == 0 && $action == 0) { //confirm one new entry (only one type in req)
                        
                        /* check if ticket_booking_id exists in array else generate a new one */
                        $checkKey = implode("_", array($visitor_group_no, $pass_no, $ticket_id, (($from_time != '') ? date('Y-m-d') : ''), $from_time, $to_time));
                        if(!empty($ticketBookingCheck) && isset($ticketBookingCheck[$checkKey])) {
                            $ticket_booking_id = $ticketBookingCheck[$checkKey];
                        }
                        else {
                            $ticket_booking_id = $this->get_ticket_booking_id($visitor_group_no);
                            $ticketBookingCheck[$checkKey] = $ticket_booking_id;
                        }
                        $logs['ticketBookingCheck_key'] = $checkKey;
                        
                        $insert_data['visitor_group_no'] = $visitor_group_no;
                        $insert_data['ticket_booking_id'] = $ticket_booking_id;
						$insert_data['pass_no'] = $pass_no;
						$insert_data['ticket_id'] = $ticket_id;
						$insert_data['ticket_title'] = $ticket_title;
						$insert_data['timezone'] = $timezone;
						$insert_data['tps_id'] = $tps_id;
						$insert_data['from_time'] = $from_time;
						$insert_data['to_time'] = $to_time;
						$insert_data['timeslot'] = $timeslot;
						$insert_data['channel_type'] = $channel_type;
						$insert_data['payment_method'] = $payment_method;
						$insert_data['museum_id'] = $museum_id;
						$insert_data['own_capacity_id'] = $own_capacity_id;
						$insert_data['shared_capacity_id'] = $shared_capacity_id;
						$insert_data['pricetext'] = $pricetext;
						$insert_data['ticket_type'] = $ticket_type;
						$insert_data['age_group'] = $age_group;
						$insert_data['pax'] = $pax;
						$insert_data['capacity'] = $capacity;
						$insert_data['shift_id'] = $shift_id;
						$insert_data['is_discount_in_percent'] = $is_discount_in_percent;
						$insert_data['save_amount'] = $save_amount;
						$insert_data['ticket_amount'] = $ticket_amount;
						$insert_data['tax_id'] = $tax_id;
						$insert_data['is_reservation'] = $is_reservation;
						$insert_data['hotel_id'] = $hotel_id;
						$insert_data['extra_options'] = $extra_options;
                                                if ($batch_id != 0) {
                                                    $insert_data['batch_id'] = $batch_id;
                                                }
						$this->insert_new_postpaid_enteries ($insert_data, $museum_data, 0, $ticketBookingCheck);
					} else if ($used == 1 && $action == 1) { //redeem one pre_booked quantity    

						$prepaid_ticket_id = explode(',', $prepaid_ticket_ids);
						$update_data['visitor_group_no'] = $visitor_group_no;
						$update_data['pass_no'] = $pass_no;
						$update_data['ticket_id'] = $ticket_id;
						$update_data['shift_id'] = $shift_id;
						$update_data['from_time'] = $from_time;
						$update_data['to_time'] = $to_time;
						$update_data['prepaid_ticket_ids'] = array($prepaid_ticket_id[0]);
                                                if ($batch_id != 0) {
                                                    $update_data['batch_id'] = $batch_id;
                                                }
                                                $update_data['hotel_id'] = $hotel_id;
						$logs['update_data'] = $update_data;
						$this->update_enteries_for_postpaid($update_data, $museum_data);
					}
					$ticket_types[$tps_id] = array(
						'tps_id' => (int) $tps_id,
						'ticket_type' => (int) $ticket_type,
						'age_group' => (string) $age_group,
						'price' => (float) $pricetext,
						'count' => 1
					);
				} else if ($used == 1 && $action == 2) { //confirm prepaid tickets with alteration in quantity or types
					
					if(!empty($pt_ids)) {
						$update_data['visitor_group_no'] = $visitor_group_no;
						$update_data['pass_no'] = $pass_no;
						$update_data['ticket_id'] = $ticket_id;
						$update_data['shift_id'] = $shift_id;
						$update_data['from_time'] = $from_time;
						$update_data['to_time'] = $to_time;
						$update_data['prepaid_ticket_ids'] = isset($pt_ids) ? $pt_ids : array();
                                                if ($batch_id != 0) {
                                                    $update_data['batch_id'] = $batch_id;
                                                }
						$update_data['hotel_id'] = $hotel_id;
						$logs['update_data'] = $update_data;
						$this->update_enteries_for_postpaid($update_data, $museum_data);
					}
					if(isset($insert_tps_count) && !empty($insert_tps_count)) { //To insert added quantity enteries in db
						$logs['insert_tps_count'] = $insert_tps_count;
						foreach ($insert_tps_count as $tps_id => $count) {
                            
                            /* check if ticket_booking_id exists in array else generate a new one */
                            $checkKey = implode("_", array($visitor_group_no, $pass_no, $ticket_id, (($from_time != '') ? date('Y-m-d') : ''), $from_time, $to_time));
                            if(!empty($ticketBookingCheck) && isset($ticketBookingCheck[$checkKey])) {
                                $ticket_booking_id = $ticketBookingCheck[$checkKey];
                            }
                            else {
                                $ticket_booking_id = $this->get_ticket_booking_id($visitor_group_no);
                                $ticketBookingCheck[$checkKey] = $ticket_booking_id;
                            }
                            $logs['ticketBookingCheck_key'] = $checkKey;
                            
                            for ($i = 0; $i < $count; $i++) {
                                $insert_data['visitor_group_no'] = $visitor_group_no;
                                $insert_data['ticket_booking_id'] = $ticket_booking_id;
								$insert_data['pass_no'] = $pass_no;
								$insert_data['ticket_id'] = $ticket_id;
								$insert_data['ticket_title'] = $ticket_title;
								$insert_data['timezone'] = $timezone;
								$insert_data['tps_id'] = $tps_id;
								$insert_data['from_time'] = $from_time;
								$insert_data['to_time'] = $to_time;
								$insert_data['timeslot'] = $timeslot;
								$insert_data['channel_type'] = $channel_type;
								$insert_data['payment_method'] = $payment_method;
								$insert_data['museum_id'] = $museum_id;
								$insert_data['own_capacity_id'] = $own_capacity_id;
								$insert_data['shared_capacity_id'] = $shared_capacity_id;
								$insert_data['shift_id'] = $shift_id;
								$insert_data['is_discount_in_percent'] = $is_discount_in_percent;
								$insert_data['save_amount'] = $types_data[$tps_id]['save_amount'];
								$insert_data['ticket_amount'] = $types_data[$tps_id]['ticket_amount'];
								$insert_data['pricetext'] = $types_data[$tps_id]['pricetext'];
								$insert_data['ticket_type'] = $types_data[$tps_id]['ticket_type'];
								$insert_data['age_group'] = $types_data[$tps_id]['age_group'];
								$insert_data['pax'] = $types_data[$tps_id]['pax'];
								$insert_data['capacity'] = $types_data[$tps_id]['capacity'];
								$insert_data['tax_id'] = $tax_id;
								$insert_data['is_reservation'] = $is_reservation;
								$insert_data['hotel_id'] = $hotel_id;
								$insert_data['extra_options'] = $extra_options;
                                                                if ($batch_id != 0) {
                                                                    $insert_data['batch_id'] = $batch_id;
                                                                }
								$this->insert_new_postpaid_enteries($insert_data, $museum_data, $i, $ticketBookingCheck);
							}
						}
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
                    $logs['update_batch_rules_'.date('H:i:s')] = $this->db->last_query();
                }
                
                $response['status'] = 1;
                $response['message'] = 'Success';            
                $response['data'] = array(
                    'visitor_group_no' => (string) $visitor_group_no,
                    'ticket_id' => (int) $ticket_id,
                    'ticket_title' => (string) $ticket_title,
                    'ticket_types' => array_values($ticket_types)
                );
            } else {
				
				$response['status'] = 0;
				$response['message'] = ($batch_not_found==true? 'Batch not found.': 'Batch capacity is full');
				$response['data'] = array(
					'visitor_group_no' => (string) $visitor_group_no,
					'ticket_id' => (int) $ticket_id,
					'ticket_title' => (string) $ticket_title,
					'ticket_types' => array_values($ticket_types)
				);
			}
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['confirm_postpaid_tickets'] = $logs;
        return $response;
    }

    /**
     * @Name     : deactivate_visitor_pass().
     * @Purpose  : To deactivate pass in HTO
     * @parameters :$request_para array.
     * @Created  : Komal garg <komalgarg.intersoft@gmail.com> on date 6 March 2019 
     */
    function deactivate_visitor_pass($request_para) {
        global $internal_logs;
        global $MPOS_LOGS;
        try {
            $logs['request_' . date('H:i:s')] = $request_para;
            $museum_id = $request_para['museum_id'];
            $pass_no = $request_para['pass_no'];
            $hto_query = 'update hotel_ticket_overview set isPassActive = "0", deactivatedBy=' . $museum_id . ', updatedOn="' . strtotime(gmdate('m/d/Y H:i:s')) . '" where passNo = "' . $pass_no . '" and hotel_checkout_status="0"';
            $this->query($hto_query);
            $logs['update_hto'] = $this->db->last_query();
            $db2_query[] = $hto_query;
            //Queue to update DB1 and DB2 and redeem tickets table
            if (!empty($db2_query)) {
                $request_array = array();
                if (!empty($db2_query)) {
                    $request_array['db2'] = $db2_query;
                }
                $request_array['write_in_mpos_logs'] = 1;
                $request_array['action'] = "deactivate_visitor_pass";
                $request_array['pass_no'] = $pass_no;
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                if (LOCAL_ENVIRONMENT == 'Local') {
                    local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                } else {
                    $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                }
            }
            $response['status'] = 1;
            $response['message'] = 'Success';
            $MPOS_LOGS['deactivate_visitor_pass_' . date('H:i:s')] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['deactivate_visitor_pass'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name     : get_postpaid_tickets_listing()
     * @Purpose  : to return listing of tickets linked with museum for postpaid process
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 6 March 2019 
     */
    function get_postpaid_tickets_listing($data = array()) {
        global $MPOS_LOGS;
        try {
            if (!empty($data)) {
                $logs['req_data_' . date('H:i:s')] = $data;
                $user_id = $data['user_id'];
                $pass_no = $data['pass_no'];
                $museum_id = $data['museum_id'];
                $device_time = $data['device_time'] / 1000;

                $postpaid_conditions = 'passNo = "' . $pass_no . '" and is_prioticket = "1" and hotel_checkout_status = "0"';
                $postpaid_tickets = $this->find('hotel_ticket_overview', array('select' => '*', 'where' => $postpaid_conditions));
                $logs['hto_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                if (!empty($postpaid_tickets) && $postpaid_tickets[0]['isPassActive'] == "1") {
                    $is_checked_out = 0;
                    $timezone_in_hto = $postpaid_tickets[0]['timezone'];
                    $hto_timezone = $this->get_timezone_value($timezone_in_hto);
                    $logs['hto_timezone'] = $hto_timezone;
                    $payment_method = $postpaid_tickets[0]['activation_method'];
                    $age = $postpaid_tickets[0]['user_age'];
                    $visitor_group_no = $postpaid_tickets[0]['visitor_group_no'];
                    $hto_id = $postpaid_tickets[0]['id'];
                    $hotel_name = $postpaid_tickets[0]['hotel_name'];

                    $pt_checks = 'visitor_group_no = "' . $visitor_group_no . '" and museum_id = ' . $museum_id . ' and used = "0" and is_prioticket = "1" and is_addon_ticket = "0" and activated = "1" and is_refunded = "0"';
                    $this->primarydb->db->protect_identifiers = FALSE;
                    $prepaid_data = $this->find("prepaid_tickets", array("select" => "prepaid_ticket_id, price, ticket_type, age_group, tps_id, ticket_id, selected_date, from_time, to_time", "where" => $pt_checks, "order_by" => "FIELD (ticket_type, 'Infant', 'Child', 'Youth', 'Adult', 'Senior') DESC"));
                    $this->primarydb->db->protect_identifiers = TRUE;
                    $logs['pt_queryy_' . date('H:i:s')] = $this->primarydb->db->last_query();
                    if (!empty($prepaid_data) && sizeof($prepaid_data) > 0) {
                        $pt_data = array();
                        $total_pre_booked_count = 0;
                        foreach ($prepaid_data as $prepaid) {
                            $total_pre_booked_count++;
                            $pt_data[$prepaid['ticket_id']]['count'] ++;
                            $pt_data[$prepaid['ticket_id']]['prepaid_ticket_ids'][] = $prepaid['prepaid_ticket_id'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['tps_id'] = $prepaid['tps_id'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['ticket_id'] = $prepaid['ticket_id'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['selected_date'] = $prepaid['selected_date'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['from_time'] = $prepaid['from_time'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['to_time'] = $prepaid['to_time'];
                            $pt_data[$prepaid['ticket_id']]['tps_data'][$prepaid['tps_id']]['price'] = $prepaid['price'];
                            $pt_tps_prices[$prepaid['ticket_id']][$prepaid['ticket_type'] . '_' . $prepaid['age_group']] = $prepaid['price']; //to handle seasonal prices
                        }
                    }
                    $hidden_tickets = $this->common_model->getSingleFieldValueFromTable('hide_tickets', 'users', array('id' => $user_id));
                    if ($hidden_tickets == '') {
                        $hidden_tickets = '0';
                    }
                    //fetch data of all tickets of respective museum valid for the day
                    $select_mec = 'mec_id, shared_capacity_id, own_capacity_id, postingEventTitle as ticket_title, is_reservation, is_extra_options, limited_availPcs, endDate, timezone';
                    $where_mec = 'cod_id = "' . $museum_id . '" and mec_id NOT IN (' . $hidden_tickets . ') and (third_party_id = "0" or third_party_id is NULL) and deleted = "0" and (startDate +(timezone * 3600)) <= "' . $device_time . '"';
                    $mec_data = $this->find('modeventcontent', array('select' => $select_mec, 'where' => $where_mec));
                    $logs['mec_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                    if (!empty($mec_data)) {
                        foreach ($mec_data as $tickets) {
                            $ticket_timezone = $this->get_timezone_value($tickets['timezone']);
                            if (in_array($tickets['mec_id'], array_keys($pt_data)) || (!in_array($tickets['mec_id'], array_keys($pt_data)) && ($tickets['endDate'] + $ticket_timezone) >= $device_time)) {
                                $tickets_data[$tickets['mec_id']] = $tickets;
                                if ($tickets['shared_capacity_id'] != 0) {
                                    $shared_capacity_ids[$tickets['mec_id']] = $tickets['shared_capacity_id'];
                                }
                                if ($tickets['own_capacity_id'] != 0) {
                                    $shared_capacity_ids[$tickets['mec_id']] = $tickets['own_capacity_id'];
                                    $own_ids[$tickets['mec_id']] = $tickets['shared_capacity_id'];
                                }
                            }
                        }
                        //filter tickets data according to types (tps)
                        $select_tps = 'id, timezone, ticket_id, ticketType, ticket_type_label, end_date, agefrom, ageto, pax, adjust_capacity, pricetext, ticket_tax_id, discountType, discount, saveamount, newPrice, group_type_ticket, group_linked_with, min_qty';
                        $where_tps = 'ticket_id IN (' . implode(',', array_keys($tickets_data)) . ') and deleted = 0 and agefrom <= "' . $age . '" and ageto >= "' . $age . '" and is_commission_assigned = "1" and start_date <= "' . $device_time . '" and ticketType in (1,3,8,9,13)';
                        $tps_data = $this->find('ticketpriceschedule', array('select' => $select_tps, 'where' => $where_tps));
                        $logs['tps_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                        $tps_ids = '0';
                        $reservation_tickets = '';
                        if (!empty($tps_data)) {
                            foreach ($tps_data as $types) {
                                if ((in_array($types['ticket_id'], array_keys($pt_data))) || (!in_array($types['ticket_id'], array_keys($pt_data)) && ($types['end_date'] >= $device_time))) {
                                    $tps_ids .= ',' . $types['id'];
                                    if ($tickets_data[$types['ticket_id']]['is_reservation'] == '1') {
                                        $reservation_tickets .= $types['ticket_id'] . ',';
                                        $all_res_tickets[$shared_capacity_ids[$types['ticket_id']]][] = $types;
                                    } else {
                                        $types_data[$types['ticket_id']][] = $types; //all valid booking tickets
                                    }
                                }
                            }
                            //fetch left capacity for reservation tickets
                            $capacity = array();
                            if ($reservation_tickets != '') {
                                $select_capacity = 'ticket_id, date, from_time, to_time, actual_capacity, sold, shared_capacity_id';
                                $where_capacity = 'ticket_id IN (' . trim($reservation_tickets, ",") . ') and date = "' . gmdate('Y-m-d') . '"';
                                $capacity_data = $this->find('ticket_capacity_v1', array('select' => $select_capacity, 'where' => $where_capacity));
                                $logs['capacity_data_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                                foreach ($capacity_data as $ticket) {
                                    $sold_tickets[$ticket['shared_capacity_id'] . '_' . $ticket['from_time'] . '_' . $ticket['to_time']] = $ticket['ticket_id'];
                                    $capacity[$ticket['shared_capacity_id'] . '_' . $ticket['date'] . '_' . $ticket['from_time'] . '_' . $ticket['to_time']]['total_quantity'] = $ticket['actual_capacity'];
                                    $capacity[$ticket['shared_capacity_id'] . '_' . $ticket['date'] . '_' . $ticket['from_time'] . '_' . $ticket['to_time']]['sold'] = $ticket['sold'];
                                }
                                //filter tickets data according to day and timeslots
                                $select_stoh = 'start_from, end_to, timeslot, ticket_id, capacity, shared_capacity_id, timezone';
                                $where_stoh = 'days = "' . gmdate('l') . '" and shared_capacity_id IN (' . implode(',', array_unique($shared_capacity_ids)) . ')';
                                $stoh_data = $this->find('standardticketopeninghours', array('select' => $select_stoh, 'where' => $where_stoh));
                                $logs['stoh_query_' . date('H:i:s')] = $this->primarydb->db->last_query();

                                foreach ($stoh_data as $ticket_detail) {
                                    $stoh_timezone = $this->get_timezone_value($ticket_detail['timezone']);
                                    if (strpos($ticket_detail['start_from'], '-') !== false) {
                                        $start_from = substr($ticket_detail['start_from'], 1);
                                        $start_time = strtotime(date('Y-m-d', strtotime('-1 day')) . $start_from) + $stoh_timezone;
                                    } else {
                                        $start_time = strtotime($ticket_detail['start_from']) + $stoh_timezone;
                                    }
                                    $end_time = strtotime($ticket_detail['end_to']) + $stoh_timezone;
                                    if ((in_array($ticket_detail['ticket_id'], array_keys($pt_data))) || ($start_time <= $device_time && $device_time <= $end_time && in_array($ticket_detail['shared_capacity_id'], array_keys($all_res_tickets)))) {
                                        foreach ($all_res_tickets[$ticket_detail['shared_capacity_id']] as $ticket) {
                                            $types_data[$ticket['ticket_id']][] = $ticket;
                                            $final_data[$ticket['ticket_id']] = $ticket_detail;
                                        }
                                        $capacity_per_ticket[$ticket_detail['shared_capacity_id']] = $ticket_detail['capacity'];
                                    }
                                }
                            }
                            //fetch extra options for all filtered tickets
                            $select_eo = 'ticket_id, schedule_id, ticket_extra_option_id, per_ticket_vs_per_age_group, optional_vs_mandatory, single_vs_multiple, main_description, description, amount, net_amount, tax';
                            $where_eo = 'ticket_id IN (' . implode(',', array_keys($types_data)) . ') and schedule_id IN (' . $tps_ids . ')';
                            $extra_options_details = $this->find('ticket_extra_options', array('select' => $select_eo, 'where' => $where_eo));
                            $logs['extra_options_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                            $option_details = array();
                            foreach ($extra_options_details as $options) {
                                $extra_options_data = array();
                                $options['description'] = unserialize($options['description']);
                                $options['amount'] = unserialize($options['amount']);
                                $options['net_amount'] = unserialize($options['net_amount']);
                                $options['tax'] = unserialize($options['tax']);
                                $extra_options_data['ticketpriceschedule_id'] = (int) $options['schedule_id'];
                                $extra_options_data['ticket_extra_option_id'] = (int) $options['ticket_extra_option_id'];
                                $extra_options_data['optional_vs_mandatory'] = (int) $options['optional_vs_mandatory'];
                                $extra_options_data['single_vs_multiple'] = (int) $options['single_vs_multiple'];
                                $extra_options_data['main_description'] = $options['main_description'];
                                foreach ($options['description'] as $key => $val) {
                                    $tax = explode('_', $options['tax'][$key]);
                                    $extra_options_data['options'][] = array(
                                        'description' => $val,
                                        'price' => (float) $options['amount'][$key],
                                        'net_price' => (float) round($options['net_amount'][$key], 2),
                                        'tax' => (int) $tax[1],
                                    );
                                }
                                $key = ($options['per_ticket_vs_per_age_group'] == 2) ? 'per_age_extra_options' : 'per_ticket_extra_options';
                                $option_details[$options['ticket_id']][$key][] = isset($extra_options_data) ? $extra_options_data : array();
                            }
                            $tickets_details = array();
                            $logs['types_data'] = $types_data;
                            foreach ($types_data as $ticket_id => $tickets) {
                                if ($tickets[0]['group_type_ticket'] == '1') { //flex ticket
                                    $min_qty = $tickets[0]['min_qty'];
                                    foreach ($tickets as $ticket) {
                                        if ($min_qty >= $ticket['min_qty']) {
                                            $types_data[$ticket_id] = array();
                                            $types_data[$ticket_id][] = $ticket;
                                            $min_qty = $ticket['min_qty'];
                                        }
                                    }
                                }
                            }
                            $show_pre_booked = 0;
                            foreach ($types_data as $ticket_id => $tickets) {
                                foreach ($tickets as $ticket) {
                                    $early_late_msg = '';
                                    $from_time = '';
                                    $to_time = '';
                                    $own_left_capacity = 0;
                                    if ($tickets_data[$ticket_id]['is_reservation'] == '1') {
                                        //To check available timeslots
                                        $timeslot = $final_data[$ticket_id]['timeslot'];
                                        $tps_timezone = $this->get_timezone_value($ticket['timezone']);
                                        if (strpos($final_data[$ticket_id]['start_from'], '-') !== false) {
                                            $start_from = substr($final_data[$ticket_id]['start_from'], 1);
                                            $ticket_from_time = strtotime(date("H:i", strtotime(date('Y-m-d', strtotime('-1 day')) . $start_from) + $tps_timezone));
                                        } else {
                                            $ticket_from_time = strtotime(date("Y-m-d H:i", strtotime($final_data[$ticket_id]['start_from']) + $tps_timezone));
                                        }
                                        $ticket_to_time = strtotime(date("Y-m-d H:i", (strtotime($final_data[$ticket_id]['end_to']) + $tps_timezone)));

                                        if ($timeslot == 'hour') {
                                            for ($i = $ticket_from_time; $i < $ticket_to_time; $i += 3600) {
                                                $from = strtotime(date('Y-m-d H:i:s', $i));
                                                if ($from < $device_time) {
                                                    $timeslot_starts_at = date('H:i', $i);
                                                    $timeslot_ends_at = date('H:i', $i + 3600);
                                                }
                                            }
                                        } else if ($timeslot == '30min') {
                                            for ($i = $ticket_from_time; $i < $ticket_to_time; $i += 1800) {
                                                $from = strtotime(date('Y-m-d H:i:s', $i));
                                                if ($from < $device_time) {
                                                    $timeslot_starts_at = date('H:i', $i);
                                                    $timeslot_ends_at = date('H:i', $i + 1800);
                                                }
                                            }
                                        } else if ($timeslot == '15min') {
                                            for ($i = $ticket_from_time; $i < $ticket_to_time; $i += 900) {
                                                $from = strtotime(date('Y-m-d H:i:s', $i));
                                                if ($from < $device_time) {
                                                    $timeslot_starts_at = date('H:i', $i);
                                                    $timeslot_ends_at = date('H:i', $i + 900);
                                                }
                                            }
                                        } else if ($timeslot == '20min') {
                                            for ($i = $ticket_from_time; $i < $ticket_to_time; $i += 1200) {
                                                $from = strtotime(date('Y-m-d H:i:s', $i));
                                                if ($from < $device_time) {
                                                    $timeslot_starts_at = date('H:i', $i);
                                                    $timeslot_ends_at = date('H:i', $i + 1200);
                                                }
                                            }
                                        } else {
                                            $timeslot_starts_at = date("H:i", $ticket_from_time);
                                            $timeslot_ends_at = date("H:i", $ticket_to_time);
                                        }
                                        $from_time = $timeslot_starts_at;
                                        $to_time = $timeslot_ends_at;
                                        $capacity_key = $shared_capacity_ids[$ticket_id] . '_' . gmdate('Y-m-d') . '_' . $from_time . '_' . $to_time;
                                        $left_capacity = (in_array($shared_capacity_ids[$ticket_id] . '_' . $from_time . '_' . $to_time, array_keys($sold_tickets))) ? $capacity[$capacity_key]['total_quantity'] - $capacity[$capacity_key]['sold'] : $final_data[$ticket_id]['capacity'];
                                        if (isset($own_ids[$ticket_id]) && $own_ids[$ticket_id] > 0) {
                                            $capacity_key = $own_ids[$ticket_id] . '_' . gmdate('Y-m-d') . '_' . $from_time . '_' . $to_time;
                                            if (in_array($own_ids[$ticket_id] . '_' . $from_time . '_' . $to_time, array_keys($sold_tickets))) {
                                                $own_left_capacity = $capacity[$capacity_key]['total_quantity'] - $capacity[$capacity_key]['sold'];
                                            } else {
                                                $own_left_capacity = $capacity_per_ticket[$own_ids[$ticket_id]];
                                            }
                                        }

                                        //for already purchased tickets
                                        $arrival_status = 0;
                                        if (in_array($ticket_id, array_keys($pt_data)) && in_array($ticket['id'], array_keys($pt_data[$ticket_id]['tps_data']))) {
                                            $capacity_key = $shared_capacity_ids[$ticket_id] . '_' . gmdate('Y-m-d') . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time'] . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'];
                                            $left_capacity = (in_array($shared_capacity_ids[$ticket_id] . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time'] . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'], array_keys($sold_tickets))) ? $capacity[$capacity_key]['total_quantity'] - $capacity[$capacity_key]['sold'] : $final_data[$ticket_id]['capacity'];
                                            if (isset($own_ids[$ticket_id])) {
                                                $capacity_key = $own_ids[$ticket_id] . '_' . gmdate('Y-m-d') . '_' . $from_time . '_' . $to_time;
                                                if (in_array($own_ids[$ticket_id] . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time'] . '_' . $pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'], array_keys($sold_tickets))) {
                                                    $own_left_capacity = $capacity[$capacity_key]['total_quantity'] - $capacity[$capacity_key]['sold'];
                                                } else {
                                                    $own_left_capacity = $capacity_per_ticket[$own_ids[$ticket_id]];
                                                }
                                            }
                                            //To check late or early cases if ticket is prepaid.
                                            $booked_date = date("d M", strtotime($pt_data[$ticket_id]['tps_data'][$ticket['id']]['selected_date']));
                                            $current_day = strtotime(gmdate("d M"));
                                            if ($current_day > strtotime($booked_date)) {
                                                //late case
                                                $arrival_status = 2;
                                                $datediff = strtotime(date("Y-m-d")) - strtotime($booked_date);
                                                $notification_value = floor($datediff / (60 * 60 * 24));
                                                $notification_text = 'days late';
                                            } else if ($current_day < strtotime($booked_date)) {
                                                //early case
                                                $arrival_status = 1;
                                                $datediff = strtotime($booked_date) - strtotime(date("Y-m-d"));
                                                $notification_value = floor($datediff / (60 * 60 * 24));
                                                $notification_text = 'days early';
                                            } else {
                                                //on same day
                                                $from_time_to_check = $pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time'];
                                                if ($timeslot == "specific") {
                                                    $from_time_to_check = $pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'];
                                                }
                                                if ($device_time < strtotime($from_time_to_check)) {
                                                    //early than the booked time
                                                    $arrival_status = 1;
                                                    $datediff = strtotime($from_time_to_check) - $device_time;
                                                    $all = round(($datediff) / 60);
                                                    $d = floor($all / 1440);
                                                    $h = floor(($all - $d * 1440) / 60);
                                                    $m = $all - ($d * 1440) - ($h * 60);
                                                    if ($h == 1 && $m == 0) {
                                                        $notification_value = '1';
                                                        $notification_text = 'hour early';
                                                    } else if ($h > 0) {
                                                        if (strlen($m) == 1) {
                                                            $m = '0' . $m;
                                                        }
                                                        $notification_value = $h . ':' . $m;
                                                        $notification_text = 'hours early';
                                                    } else {
                                                        if ($m == 1) {
                                                            $notification_value = "1";
                                                            $notification_text = 'min early';
                                                        } else {
                                                            if (strlen($m) == 1) {
                                                                $m = '0' . $m;
                                                            }
                                                            $notification_value = $m;
                                                            $notification_text = 'mins early';
                                                        }
                                                    }
                                                } else if ($device_time > strtotime($pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'])) {
                                                    //late than the booked time
                                                    $arrival_status = 2;
                                                    $datediff = $device_time - strtotime($pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time']);
                                                    $all = round(($datediff) / 60);
                                                    $d = floor($all / 1440);
                                                    $h = floor(($all - $d * 1440) / 60);
                                                    $m = $all - ($d * 1440) - ($h * 60);
                                                    if ($h == 1 && $m == 0) {
                                                        $notification_value = '1';
                                                        $notification_text = 'hour late';
                                                    } else if ($h > 0) {
                                                        if (strlen($m) == 1) {
                                                            $m = '0' . $m;
                                                        }
                                                        $notification_value = $h . ':' . $m;
                                                        $notification_text = 'hours late';
                                                    } else {
                                                        if ($m == 1) {
                                                            $notification_value = "1";
                                                            $notification_text = 'min late';
                                                        } else {
                                                            if (strlen($m) == 1) {
                                                                $m = '0' . $m;
                                                            }
                                                            $notification_value = $m;
                                                            $notification_text = 'mins late';
                                                        }
                                                    }
                                                } else {
                                                    //exact on time
                                                    $arrival_status = 0;
                                                    $notification_value = '';
                                                    $notification_text = '';
                                                }
                                            }
                                            $early_late_msg = $notification_value . ' ' . $notification_text;
                                        }
                                    }

                                    if ($own_left_capacity > 0) {
                                        if ($own_left_capacity < $left_capacity) {
                                            $actual_left_capacity = $own_left_capacity;
                                        } else {
                                            $actual_left_capacity = $left_capacity;
                                        }
                                    } else {
                                        $actual_left_capacity = $left_capacity;
                                    }

                                    $price_after_discount = $existing_price = '';
                                    if (in_array($ticket_id, array_keys($pt_data)) && in_array($ticket['ticket_type_label'] . '_' . $ticket['agefrom'] . '-' . $ticket['ageto'], array_keys($pt_tps_prices[$ticket_id]))) {
                                        $existing_price = $pt_tps_prices[$ticket_id][$ticket['ticket_type_label'] . '_' . $ticket['agefrom'] . '-' . $ticket['ageto']];
                                    }
                                    if ($existing_price == '' && $ticket['newPrice'] != 0) {
                                        $price_after_discount = (float) $ticket['newPrice'];
                                    } else {
                                        $price_after_discount = (float) $ticket['pricetext'];
                                    }
                                    $show_pre_booked =  ($show_pre_booked == 1 || isset($pt_data[$ticket_id]['count'])) ? 1 : 0;
                                    $tickets_details[$ticket_id]['booked_count'] = isset($pt_data[$ticket_id]['count']) ? (int) $pt_data[$ticket_id]['count'] : 0;
                                    $tickets_details[$ticket_id]['age_group'] = (string) $ticket['agefrom'] . '-' . $ticket['ageto'];
                                    $tickets_details[$ticket_id]['ticket_type'] = (int) $ticket['ticketType'];
                                    $tickets_details[$ticket_id]['ticket_type_label'] = (string) $ticket['ticket_type_label'];
                                    $tickets_details[$ticket_id]['extra_options_exists'] = (int) $tickets_data[$ticket_id]['is_extra_options'];
                                    $tickets_details[$ticket_id]['extra_options'] = !empty($option_details[$ticket_id]) ? $option_details[$ticket_id] : (object) array();
                                    $tickets_details[$ticket_id]['booked_date'] = isset($booked_date) ? $booked_date : '';
                                    $tickets_details[$ticket_id]['from_time'] = isset($pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time']) ? (string) $pt_data[$ticket_id]['tps_data'][$ticket['id']]['from_time'] : (string) $from_time;
                                    $tickets_details[$ticket_id]['to_time'] = isset($pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time']) ? (string) $pt_data[$ticket_id]['tps_data'][$ticket['id']]['to_time'] : (string) $to_time;
                                    $tickets_details[$ticket_id]['timeslot'] = isset($timeslot) ? (string) $timeslot : '';
                                    $tickets_details[$ticket_id]['capacity_left'] = ($tickets_data[$ticket_id]['is_reservation'] == '0') ? 0 : (int) $actual_left_capacity;
                                    $tickets_details[$ticket_id]['ticket_id'] = (int) $ticket_id;
                                    $tickets_details[$ticket_id]['reservation_status'] = isset($early_late_msg) ? (string) $early_late_msg : '';
                                    $tickets_details[$ticket_id]['arrival_status'] = (int) $arrival_status;
                                    $tickets_details[$ticket_id]['prepaid_ticket_ids'] = !empty($pt_data[$ticket_id]['prepaid_ticket_ids']) ? implode($pt_data[$ticket_id]['prepaid_ticket_ids'], ',') : '';
                                    $tickets_details[$ticket_id]['is_reservation'] = (int) $tickets_data[$ticket_id]['is_reservation'];
                                    $tickets_details[$ticket_id]['ticket_title'] = (string) $tickets_data[$ticket_id]['ticket_title'];
                                    $tickets_details[$ticket_id]['tps_id'] = (int) $ticket['id'];
                                    $tickets_details[$ticket_id]['pax'] = (int) $ticket['pax'];
                                    $tickets_details[$ticket_id]['capacity'] = (int) $ticket['adjust_capacity'];
                                    $tickets_details[$ticket_id]['shared_capacity_id'] = (int) $tickets_data[$ticket_id]['shared_capacity_id'];
                                    $tickets_details[$ticket_id]['own_capacity_id'] = (int) $tickets_data[$ticket_id]['own_capacity_id'];
                                    $tickets_details[$ticket_id]['ticket_tax_id'] = (int) $ticket['ticket_tax_id'];
                                    $tickets_details[$ticket_id]['price'] = ($existing_price != '') ? (float) $existing_price : (float) $ticket['pricetext'];
                                    $tickets_details[$ticket_id]['price_after_discount'] = ($existing_price != '') ? (float) $existing_price : (float) $price_after_discount;
                                    $tickets_details[$ticket_id]['discount_type'] = (int) $ticket['discountType'];
                                    $tickets_details[$ticket_id]['save_amount'] = ($ticket['discountType'] == "2") ? (float) $ticket['discount'] : (float) $ticket['saveamount'];
                                }
                            }
                            rsort($tickets_details);
                            $status = 1;
                            $message = 'success';
                        } else {
                            $status = 12;
                            $message = 'No tickets are available';
                        }
                    } else {
                        $status = 12;
                        $message = 'No tickets are available';
                    }
                } else if (!empty($postpaid_tickets) && $postpaid_tickets[0]['isPassActive'] == "0") {
                    $status = 12;
                    $message = 'Pass Deactivated';
                } else {
                    $status = 0;
                    $message = 'Pass not valid';
                }
            }
            $response = array();
            if ($status == 0) {
                $response['errorMessage'] = $message;
            }
            $response['status'] = (int) $status;
            $response['message'] = $message;
            $response['is_prioticket'] = (int) 1;
            $response['is_ticket_listing'] = isset($tickets_details) ? (int) 1 : 0;
            $response['pre_booked_count'] = ($show_pre_booked == 1) ? (int) $total_pre_booked_count : 0;
            $response['total_tickets'] = (int) sizeof($types_data);
            $response['is_checked_out'] = (int) $is_checked_out;
            $response['visitor_group_no'] = (string) $visitor_group_no;
            $response['hto_id'] = (string) $hto_id;
            $response['hotel_name'] = (string) $hotel_name;
            $response['age'] = isset($age) ? $age : 0;
            $response['timezone'] = (string) $hto_timezone;
            $response['pass_no'] = (string) $pass_no;
            $response['payment_method'] = (int) $payment_method;
            $response['data'] = isset($tickets_details) ? array_values($tickets_details) : array();
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['get_postpaid_tickets_listing_' . date('H:i:s')] = $logs;
        return $response;
    }

    /**
     * @name   : get_guest_details()
     * @purpose: To get details of guest
     * @where  : Call from api controller
     * @return : guest and visitor details
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 07 march 2019 
     */
    function get_guest_detail($visitor_group_no, $hotel_id) {
        global $MPOS_LOGS;
        try {
            $logs['request_' . date('H:i:s')] = array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id);
            // get visitor check out status  
            $this->primarydb->db->protect_identifiers = FALSE;
            $where = 'visitor_group_no = "' . $visitor_group_no . '" and hotel_id = "' . $hotel_id . '"  ';
            $select = 'roomNo, receiptEmail, createdOn, expectedCheckoutTime, pspReference,is_block, card_number, card_name, hotel_checkout_status, visitor_group_no, passNo, id, user_age, gender, user_image, isPassActive';
            $hto_reponse = $this->find('hotel_ticket_overview', array('select' => $select, 'where' => $where));
            $this->primarydb->db->protect_identifiers = TRUE;
            // check hotel status
            if ($hto_reponse[0]['hotel_checkout_status'] == '1') {
                $arrReturn['status'] = 0;
                $arrReturn['message'] = 'This user has been already checked out';
                $arrReturn['visitorGroupNo'] = $visitor_group_no;
                $logs['response'] = $arrReturn;
                $MPOS_LOGS['get_guest_detail_' . date('H:i:s')] = $logs;
                $params[] = $arrReturn;
                return $params;
            } else {
                if (!empty($hto_reponse)) {
                    $servicedata = $this->find('services', array('select' => 'timeZone', 'where' => 'id = "' . SERVICE_NAME . '"'));
                    $timeZone = $servicedata[0]['timeZone'];
                    $creditcards = array();
                    $param['roomNo'] = !empty($hto_reponse[0]['roomNo']) ? $hto_reponse[0]['roomNo'] : '';
                    $param['receiptEmail'] = !empty($hto_reponse[0]['receiptEmail']) ? $hto_reponse[0]['receiptEmail'] : '';
                    $checkinDate = $hto_reponse[0]['createdOn'];
                    $param['checkinDate'] = date('d M Y H:i', ($checkinDate + ($timeZone * 3600)));
                    if ($hto_reponse[0]['expectedCheckoutTime'] != '' && $hto_reponse[0]['expectedCheckoutTime']) {
                        $param['checkoutDate'] = date('d M Y', ($hto_reponse[0]['expectedCheckoutTime'] + ($timeZone * 3600)));
                        $param['checkoutTime'] = date('H:i', ($hto_reponse[0]['expectedCheckoutTime'] + ($timeZone * 3600)));
                    } else {
                        $param['checkoutDate'] = '';
                        $param['checkoutTime'] = '';
                    }
                    // credit card payment Info
                    if ($hto_reponse[0]['pspReference'] != '') {
                        $creditcards[] = array('card_holder_name' => $hto_reponse[0]['card_name'], 'cardNumber' => $hto_reponse[0]['card_number'], 'visitorGroupNo' => $hto_reponse[0]['visitor_group_no'], 'passNo' => $hto_reponse[0]['passNo'], 'visitorId' => $hto_reponse[0]['id']);
                    }
                    // visitors details
                    $visitors = array();
                    foreach ($hto_reponse as $visitorInfo) {
                        $isPassActive = $visitorInfo['isPassActive'] == 0 ? 1 : 0; // if pass is deactivated
                        $visitors[] = array(
                            'visitorId' => $visitorInfo['id'],
                            'age' => $visitorInfo['user_age'],
                            'gender' => $visitorInfo['gender'],
                            'user_image' => $visitorInfo['user_image'],
                            'passNo' => $visitorInfo['passNo'],
                            'isPassDeactivated' => $isPassActive);
                    }
                } else {
                    $arrReturn['status'] = 0;
                    $arrReturn['message'] = 'No Detail Found';
                    $MPOS_LOGS['get_guest_detail'] = $logs;
                    return $arrReturn;
                }
            }
            $param['creditcards'] = $creditcards;
            $param['visitors'] = $visitors;
            $arrReturn[] = $param;
            $MPOS_LOGS['get_guest_detail'] = $logs;
            return $arrReturn;
        } catch (\Exception $e) {
            $MPOS_LOGS['get_guest_detail'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @name   : edit_guest_details()
     * @purpose: To edit details of guest
     * @where  : Call from api controller
     * @return : 
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 07 march 2019 
     */
    function edit_guest_details($data) {
        $visitor_group_no = $data['visitor_group_no'];
        $hotel_id = $data['hotel_id'];
        $visitorId = $data['visitorId'];
        $userImage = $data['user_image'];
        global $MPOS_LOGS;
        try {
            $logs['request_' . date('H:i:s')] = array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id);
            $where = 'visitor_group_no ="' . $visitor_group_no . '" && hotel_id ="' . $hotel_id . '"  ';
            if (isset($visitorId) && !empty($visitorId)) {  // for hto id exist in request
                $where .= ' && id =' . $visitorId;
            }
            $select = 'roomNo, receiptEmail, createdOn, expectedCheckoutTime, pspReference,is_block, card_number, card_name, hotel_checkout_status, visitor_group_no, passNo, id, user_age, gender, user_image, isPassActive';
            $hto_reponse = $this->find('hotel_ticket_overview', array('select' => $select, 'where' => $where), 'array');
            // check hotel status
            if ($hto_reponse[0]['hotel_checkout_status'] == '1') {
                $arrReturn['status'] = 0;
                $arrReturn['message'] = 'This user has been already checked out';
                $arrReturn['visitorGroupNo'] = $visitor_group_no;
                $logs['response'] = $arrReturn;
                $MPOS_LOGS['edit_guest_details_' . date('H:i:s')] = $logs;
                $params[] = $arrReturn;
                return $params;
            } else {
                // edit room info
                if (!isset($visitorId)) {
                    if (!empty($data['room_no'])) {
                        $update_data['roomNo'] = $data['room_no'];
                        // delete previous room if we edit room
                        $headers = $this->all_headers(array(
                            'action' => 'update_guest_details_from_MPOS',
                            'hotel_id' => $hotel_id
                        ));
                        $MPOS_LOGS['DB'][] = 'FIREBASE';
                        $this->curl->requestASYNC('FIREBASE', '/delete_firestore', array(
                            'type' => 'POST',
                            'additional_headers' => $headers,
                            'body' => array("path" => 'Pass_activation_details/' . $hto_reponse[0]['roomNo'])
                        ));
                        $ticket_tax = 0;
                        $CheckinDate = $hto_reponse[0]['createdOn'];
                        // fetch prepaid query if exist data
                        $prepaid_tickets_details = $this->common_model->find('prepaid_tickets', array('select' => 'museum_name, title , ticket_type, sum(price) as price, count(prepaid_ticket_id) as ticket_count ', 'where' => 'passNo = "' . $hto_reponse[0]['passNo'] . '"'));
                        $checkinStr = 'Check-in: ' . date('d M H:i', ($CheckinDate + ($hto_reponse[0]['timezone'] * 60 * 60)));
                        $checkoutStr = 'Check-out: ' . date('d M H:i', ($hto_reponse[0]['expectedCheckoutTime'] + ($hto_reponse[0]['timezone'] * 60 * 60)));
                        // insert new room details                    
                        $firestore_data_save[$data['room_no']] = array(
                            'room_no' => $data['room_no'],
                            'payment_status' => $hto_reponse[0]['paymentStatus'],
                            'hotel_id' => $hotel_id,
                            'persons_count' => count($hto_reponse),
                            'visitor_group_no' => $visitor_group_no,
                            'parent_pass_no' => $hto_reponse[0]['parentPassNo'],
                            'total_tickets' => (int) !empty($prepaid_tickets_details[0]['ticket_count']) ? $prepaid_tickets_details[0]['ticket_count'] : (int) 0,
                            'total_tickets_amount' => !empty($prepaid_tickets_details[0]['price']) ? $prepaid_tickets_details[0]['price'] : '',
                            'total_tickets_net_amount' => !empty($prepaid_tickets_details[0]['price']) ? $prepaid_tickets_details[0]['price'] - round(($prepaid_tickets_details[0]['price'] * $ticket_tax) / (100 + $ticket_tax), 2) : '',
                            'is_pass_deactivated' => $hto_reponse[0]['isPassActive'],
                            'museum_name' => '',
                            'ticket_type' => '',
                            'is_credit_card_failed' => 0,
                            'check_in_date' => $checkinStr,
                            'check_out_date' => $checkoutStr,
                        );

                        $MPOS_LOGS['DB'][] = 'FIREBASE';
                        $this->curl->requestASYNC('FIREBASE', '/sync_firestore', array(
                            'type' => 'POST',
                            'additional_headers' => $headers,
                            'body' => array("Pass_activation_details" => $firestore_data_save)
                        ));
                        
                        //save room no in hotel_rooms table if not exist
                        $this->hotel_model->checkAndSaveRoom($data['room_no'], $hotel_id);
                    }
                    if (!empty($data['receiptEmail'])) {
                        $update_data['receiptEmail'] = $data['receiptEmail'];
                    }
                    if ($data['check_out_date'] != '') {
                        $expectedCheckoutTime = strtotime($data['check_out_date'] . ' ' . $data['check_out_date']) - ($hto_reponse[0]['timezone'] * 3600); // get timestamp and change into GMT 0
                        // calculate nights
                        $checkinDate = $hto_reponse[0]['createdOn'];
                        $checkinDate = date('d M Y', ($checkinDate + ($hto_reponse[0]['timezone'] * 3600)));
                        $nights = $this->dateDiff($checkoutDate, $checkinDate);
                        if ($expectedCheckoutTime > 0) {
                            $update_data['expectedCheckoutTime'] = $expectedCheckoutTime;
                            $update_data['nights'] = $nights;
                        }
                    }
                    $this->update('hotel_ticket_overview', $update_data, $where);
                }

                // update visitor details
                if (isset($visitorId) && !empty($visitorId)) {
                    if (!empty($data['age'])) {
                        $update_data['user_age'] = $data['age'];
                    }
                    if (!empty($data['gender'])) {
                        $update_data['gender'] = $data['gender'];
                    }
                    if (!empty($data['passNo'])) {
                        $update_data['passNo'] = $data['passNo'];
                        $update_data['parentPassNo'] = $data['passNo'];
                    }
                    if (!empty($data['reactivate_pass']) && $data['reactivate_pass'] == 1) {  // reactive pass condition
                        $update_data['isPassActive'] = 1;

                        // process sync firebase for reactive pass
                        $firestore_data_save = array(
                            'is_pass_deactivate' => 1,
                        );

                        try {
                            $MPOS_LOGS['DB'][] = 'FIREBASE';
                            $this->curl->requestASYNC('FIREBASE', '/updateField', array(
                                'type' => 'POST',
                                'additional_headers' => $this->all_headers(array(
                                        'action' => 'update_guest_details_from_MPOS',
                                        'hotel_id' => $hotel_id
                                    )),
                                'body' => array("path" => 'Pass_activation_details/' . $hto_reponse[0]['roomNo'], 'update' => $firestore_data_save)
                            ));
                        } catch (\Exception $e) {
                            $logs['exception'] = $e->getMessage();
                        }
                    }

                    $update_data['updatedOn'] = strtotime(gmdate('m/d/Y H:i:s'));
                    // upload image
                    if ($userImage != '' && file_exists('uploadImage/upload/' . $userImage)) {
                        if (!is_dir(COMPANY_IMAGE_URL . $hotel_id)) {
                            mkdir(COMPANY_IMAGE_URL . $hotel_id);
                            chmod(COMPANY_IMAGE_URL . $hotel_id, 0777);
                        }
                        if (!is_dir(COMPANY_IMAGE_URL . $hotel_id . '/visitors')) {
                            mkdir(COMPANY_IMAGE_URL . $hotel_id . '/visitors');
                            chmod(COMPANY_IMAGE_URL . $hotel_id . '/visitors', 0777);
                        }
                        if (!is_dir(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y'))) {
                            mkdir(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y'));
                            chmod(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y'), 0777);
                        }
                        if (!is_dir(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m'))) {
                            mkdir(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m'));
                            chmod(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m'), 0777);
                        }
                        $newname = $userImage;
                        rename('uploadImage/upload/' . $userImage . '', COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m') . '/' . $newname . '');
                        $thumb = new \CI_Thumbnail();
                        $thumb->smart_resize_image(COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m') . '/' . $newname, 140, 160, true, COMPANY_IMAGE_URL . $hotel_id . '/visitors/' . date('Y') . '/' . date('m') . '/' . $newname, false);
                        $update_data['user_image'] = $hotel_id . '/visitors/' . date('Y') . '/' . date('m') . '/' . $newname;

                        // delete the previous image
                        $oldimage = $this->find('hotel_ticket_overview', array('select' => 'user_image', 'where' => 'id = "' . $visitorId . '"'));
                        if ($oldimage[0]['user_image'] != '') {
                            unlink(COMPANY_IMAGE_URL . $oldimage[0]['user_image']);
                        }
                    }
                    $this->update('hotel_ticket_overview', $update_data, $where);

                }

                // update secondary table
                if (UPDATE_SECONDARY_DB) {
                    $sns_messages = array();
                    $sns_messages[] = $this->db->last_query();
                    if (!empty($sns_messages)) {
                        $request_array['db2'] = $sns_messages;
                        $request_array['visitor_group_no'] = $visitor_group_no;
                        $request_array['write_in_mpos_logs'] = 1;
                        $request_array['action'] = "edit_guest_details";
                        $request_string = json_encode($request_array);
                        $logs['db2_process_' . date('H:i:s')] = $request_string;
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        if (LOCAL_ENVIRONMENT == 'Local') {
                            local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                        } else {
                            $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                        }
                    }
                }

                // update in visitor_tickets table
                if (isset($visitorId) && !empty($visitorId)) {
                    $visitor_where .= 'hto_id =' . $visitorId;
                    $update_visitor['passNo'] = $data['passNo'];
                    $this->update('visitor_tickets', $update_visitor, $visitor_where);
                }

                if (UPDATE_SECONDARY_DB) {
                    $sns_messages = array();
                    $sns_messages[] = $this->db->last_query();
                    if (!empty($sns_messages)) {
                        $request_array['db2'] = $sns_messages;
                        $request_array['visitor_group_no'] = $visitor_group_no;
                        $request_array['action'] = "edit_guest_details";
                        $request_array['write_in_mpos_logs'] = 1;
                        $request_string = json_encode($request_array);
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        if (LOCAL_ENVIRONMENT == 'Local') {
                            local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                        } else {
                            $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                        }
                    }
                }
            }
            $arrReturn['message'] = 'success';
            $MPOS_LOGS['edit_guest_details'] = $logs;
            return $arrReturn;
        } catch (\Exception $e) {
            $MPOS_LOGS['edit_guest_details'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }
    
    /**
     * @Name     : update_enteries_for_postpaid().
     * @Purpose  : redeem pre_booked postpaid enteries in DB
     * @called from : confirm_postpaid_tickets()
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 8 july 2019
     */
    function update_enteries_for_postpaid($update_data, $museum_data) { //redeem pre_booked quantities
        global $MPOS_LOGS;
        extract($update_data);
        $action_performed = 'MPOS_PST_SCAN';
        if (isset($batch_id) && $batch_id != 0) {
            $insert_pt_db2['batch_id'] = $batch_id;
            $batch_id_db1 = ", batch_id = ".$batch_id;
        } else {
            $batch_id_db1 = "";
        }
        $db1_query[] = 'update prepaid_tickets set used = "1", from_time = "'.$from_time.'", to_time = "'.$to_time.'", scanned_at = "' . strtotime(gmdate('Y-m-d H:i:s')) . '", passNo = "' . $pass_no . '", museum_cashier_id = "' . $museum_data->uid . '", shift_id = "' . $shift_id . '", museum_cashier_name = "' . $museum_data->fname . ' ' . $museum_data->lname . '", redeem_date_time = "' . gmdate('Y-m-d H:i:s') . '", action_performed = CONCAT(action_performed, ", ' . $action_performed . '")'.$batch_id_db1.' where visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and used = "0" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')';
        $insert_vt['used'] = $insert_pt_db2['used'] = '1';
        $insert_vt['from_time'] = $insert_pt_db2['from_time'] = $from_time;
        $insert_vt['to_time'] = $insert_pt_db2['to_time'] = $to_time;
        $insert_pt_db2['scanned_at'] = strtotime(gmdate('Y-m-d H:i:s'));
        $insert_vt['updated_at'] = $insert_pt_db2['updated_at'] = $insert_pt_db2['redeem_date_time'] = gmdate('Y-m-d H:i:s');
        $insert_vt['passNo'] = $insert_pt_db2['passNo'] = $pass_no;
        $insert_vt['shift_id'] = $insert_pt_db2['shift_id'] = $shift_id;
        $insert_vt['redeem_method'] = $insert_pt_db2['redeem_method'] = "Voucher";
        $insert_vt['updated_by_id'] = $insert_vt['voucher_updated_by'] = $insert_pt_db2['voucher_updated_by'] = $insert_pt_db2['museum_cashier_id'] = $museum_data->uid;
        $insert_vt['updated_by_username'] = $insert_vt['voucher_updated_by_name'] = $insert_pt_db2['voucher_updated_by_name'] = $insert_pt_db2['museum_cashier_name'] = $museum_data->fname . ' ' . $museum_data->lname;
        $insert_vt['CONCAT_VALUE']['action_performed'] = $insert_pt_db2['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
        $where_pt_db2 = ' visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and used = "0" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')';
        $where_vt = ' vt_group_no = "' . $visitor_group_no . '" and ticketId = "' . $ticket_id . '" and used = "0" and transaction_id IN (' . implode(',', $prepaid_ticket_ids) . ')';
        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $insert_pt_db2, "where" => $where_pt_db2);
        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $insert_vt, "where" => $where_vt);

        //update redeem tickets table
        $update_redeem_table['visitor_group_no'] = $visitor_group_no;
        $update_redeem_table['prepaid_ticket_ids'] = $prepaid_ticket_ids;
        $update_redeem_table['museum_cashier_id'] = $museum_data->uid;
        $update_redeem_table['shift_id'] = $shift_id;
        $update_redeem_table['redeem_date_time'] = gmdate("Y-m-d H:i:s");
        $update_redeem_table['hotel_id'] = $hotel_id;
        $update_redeem_table['museum_cashier_name'] = $museum_data->fname . ' ' . $museum_data->lname;

        //Queue to update DB1 and DB2 and redeem tickets table
        if (!empty($db1_query) || !empty($update_redeem_table)) {
            $request_array = array();
            if (!empty($db1_query)) {
                $request_array['db1'] = $db1_query;
            }
            $request_array['db2_insertion'] = $insertion_db2;
            $request_array['update_redeem_table'] = $update_redeem_table;
            $request_array['write_in_mpos_logs'] = 1;
            $request_array['visitor_group_no'] = $visitor_group_no;
            $request_array['action'] = "update_enteries_for_postpaid";
            $request_string = json_encode($request_array);
            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
            $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
            } else {
                $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);               
            }
            $MPOS_LOGS['confirm_postpaid_tickets']['update_enteries'] = $logs;
        }
    }

    /**
     * @Name     : insert_new_postpaid_enteries().
     * @Purpose  : insert one new entry corresponding to postpaid entry insertion
     * @called from : confirm_postpaid_tickets()
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 8 july 2019
     */
    function insert_new_postpaid_enteries($insert_data, $museum_data, $i = 0) { //insert one new entry (only one type in req)
        global $MPOS_LOGS;
        extract($insert_data);
        // Fetch tax details
        $tax_details = reset($this->find('store_taxes', array('select' => '*', 'where' => 'tax_type = "0" and id = "' . $tax_id . '"')));
        //get total ticket details in HTO
        $hto_data = reset($this->find('hotel_ticket_overview', array('select' => 'visitor_group_no, id, hotel_id, hotel_name, uid, host_name, ticket_ids, total_price, total_net_price, quantity, paymentStatus, pspReference, authorisedAmtRequestCnt', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and passNo = "' . $pass_no . '"', 'limit' => 1)));
        //Fetch data from qr-codes table
        $hotel_id = ($hotel_id > 0) ? $hotel_id : $hto_data['hotel_id'];
        $qr_codes_data = reset($this->find('qr_codes', array('select' => 'reseller_id, reseller_name, channel_id, channel_name, saledesk_id, saledesk_name, instant_ticket_charge, paymentMethodType', 'where' => 'cod_id = "' . $hotel_id . '"')));
        $logs['qr_codes_data_'.$i] = $qr_codes_data;
        //Prepare PT array
        if (($qr_codes_data['paymentMethodType'] == "1" || $qr_codes_data['paymentMethodType'] == "3") && $payment_method == 1) {// payment type is credit card
            $currencyCode = $this->common_model->getISO3CurrencySymbolFromCodId($hto_data['hotel_id']);
            if ($hto_data['paymentStatus'] == "1" && $hto_data['pspReference'] != '' && $hto_data['authorisedAmtRequestCnt'] == "0") { // card is authorised
                if ($qr_codes_data['instant_ticket_charge'] == "1") {
                    $logs['make_payment_'.$i] = 'makeInstantTicketPaymentv1 called';
                    $response = $this->museum_model->makeInstantTicketPaymentv1($pass_no, $ticket_amount, $currencyCode, $hto_data['hotel_id'], $ticket_id, $payment_method);
                } else {
                    $logs['make_payment_'.$i] = 'makeTicketPaymentv1 called';
                    $response = $this->museum_model->makeTicketPaymentv1($pass_no, $ticket_amount, $currencyCode, $hto_data['hotel_id'], $payment_method);
                }
                $responseMessage = $response['message'];
                $responsePspReference = $response['responsePspReference'];
            }
        } else {
            $responseMessage = true;
        }
        if ((($responseMessage) && ($responseMessage == 'success' || $responseMessage == 'success_with_cc_fail')) || $payment_method == "3") { // ticket payment is success or ticket is given even CC payment fails
        
            $prepaid_tickets_data = array();
            
            $prepaid_ticket_data['prepaid_ticket_id'] = (int) (microtime(true) * 1000000000);
            $prepaid_ticket_data['visitor_tickets_id'] = $prepaid_ticket_data['prepaid_ticket_id'] . '01';
            $prepaid_ticket_data['created_date_time'] = gmdate('Y-m-d H:i:s');
            $prepaid_ticket_data['visitor_group_no'] = $visitor_group_no;
            $prepaid_ticket_data['ticket_booking_id'] = $ticket_booking_id;
            $prepaid_ticket_data['hotel_id'] = $hto_data['hotel_id'];
            $prepaid_ticket_data['hotel_name'] = $hto_data['hotel_name'];
            $prepaid_ticket_data['hotel_ticket_overview_id'] = $hto_data['id'];
            $prepaid_ticket_data['title'] = $ticket_title;
            $prepaid_ticket_data['museum_id'] = $museum_id;
            $prepaid_ticket_data['museum_name'] = $museum_data->company;
            $prepaid_ticket_data['shared_capacity_id'] = ($own_capacity_id > 0) ? $shared_capacity_id . ',' . $own_capacity_id : $shared_capacity_id;
            $prepaid_ticket_data['additional_information'] = '';
            $prepaid_ticket_data['price'] = $ticket_amount;
            $prepaid_ticket_data['discount'] = $save_amount;
            $prepaid_ticket_data['reseller_id'] = $qr_codes_data['reseller_id'];
            $prepaid_ticket_data['reseller_name'] = $qr_codes_data['reseller_name'];
            $prepaid_ticket_data['tax'] = $tax_details['tax_value'];
            $prepaid_ticket_data['tax_name'] = $tax_details['tax_name'];
            $prepaid_ticket_data['net_price'] = $ticket_amount - round(($ticket_amount * $tax_details['tax_value']) / (100 + $tax_details['tax_value']), 2);
            $prepaid_ticket_data['ticket_id'] = $ticket_id;
            $prepaid_ticket_data['tps_id'] = $tps_id;
            $prepaid_ticket_data['age_group'] = $age_group;
            $prepaid_ticket_data['passNo'] = $pass_no;
            $prepaid_ticket_data['ticket_type'] = ucfirst(array_search($ticket_type, $this->types));
            $prepaid_ticket_data['quantity'] = 1;
            $prepaid_ticket_data['selected_date'] = ($from_time != '') ? date('Y-m-d') : '';
            $prepaid_ticket_data['from_time'] = $from_time;
            $prepaid_ticket_data['to_time'] = $to_time;
            $prepaid_ticket_data['timeslot'] = $timeslot;
            $prepaid_ticket_data['pax'] = $pax;
            $prepaid_ticket_data['capacity'] = $capacity;
            $prepaid_ticket_data['scanned_at'] = strtotime(gmdate('Y-m-d H:i:s'));
            $prepaid_ticket_data['redeem_date_time'] = gmdate('Y-m-d H:i:s');
            $prepaid_ticket_data['action_performed'] = '0, MPOS_PST_INSRT';
            $prepaid_ticket_data['timezone'] = $timezone;
            $prepaid_ticket_data['is_combi_ticket'] = 0;
            $prepaid_ticket_data['is_combi_discount'] = '0';
            $prepaid_ticket_data['combi_discount_gross_amount'] = '0';
            $prepaid_ticket_data['pass_type'] = '0';
            $prepaid_ticket_data['is_prepaid'] = '0';
            $prepaid_ticket_data['used'] = '1';
            $prepaid_ticket_data['is_prioticket'] = '1';
            $prepaid_ticket_data['museum_cashier_id'] = $museum_data->uid;
            $prepaid_ticket_data['museum_cashier_name'] = $museum_data->fname . ' ' . $museum_data->lname;
            $prepaid_ticket_data['activation_method'] = $payment_method;
            $prepaid_ticket_data['channel_type'] = $channel_type;
            $prepaid_ticket_data['cashier_id'] = $hto_data['uid'];
            $prepaid_ticket_data['cashier_name'] = $hto_data['host_name'];
            $prepaid_ticket_data['shift_id'] = $shift_id;
            $prepaid_ticket_data['pos_point_id'] = 0;
            $prepaid_ticket_data['pos_point_name'] = '';
            $prepaid_ticket_data['supplier_price'] = $ticket_amount;
            $prepaid_ticket_data['booking_status'] = '1';
            $prepaid_ticket_data['tp_payment_method'] = '1';
            $prepaid_ticket_data['merchantReference'] = ($payment_method == 1) ? 'MPOS-' . $visitor_group_no . '-' . $pass_no : "";
            $prepaid_ticket_data['pspReference'] = isset($responsePspReference) ? $responsePspReference : '';
            $this->db->insert('prepaid_tickets', $prepaid_ticket_data);
            $logs['pt_query_' . date('H:i:s').'_'.$i] = $this->db->last_query();
            $prepaid_ticket_data['channel_id'] = $qr_codes_data['channel_id'];
            $prepaid_ticket_data['channel_name'] = $qr_codes_data['channel_name'];
            $prepaid_ticket_data['saledesk_id'] = $qr_codes_data['saledesk_id'];
            $prepaid_ticket_data['saledesk_name'] = $qr_codes_data['saledesk_name'];
            $prepaid_ticket_data['oroginal_price'] = $pricetext;
            $prepaid_ticket_data['is_discount_in_percent'] = $is_discount_in_percent;
            $prepaid_ticket_data['created_at'] = strtotime(gmdate('m/d/Y H:i:s'));
            $prepaid_ticket_data['is_pre_selected_ticket'] = 0;
            $prepaid_ticket_data['supplier_original_price'] = $pricetext;
            $prepaid_ticket_data['supplier_discount'] = $save_amount;
            $prepaid_ticket_data['supplier_tax'] = $tax_details['tax_value'];
            $prepaid_ticket_data['supplier_net_price'] = $prepaid_ticket_data['net_price'];
            if (isset($batch_id) && $batch_id != 0) {
                $prepaid_ticket_data['batch_id'] = $batch_id;
            }
            $prepaid_ticket_data['location_code'] = $prepaid_ticket_data['cashier_code'] = 'Venue';
            $prepaid_ticket_data['voucher_updated_by'] = $museum_data->uid;
            $prepaid_ticket_data['voucher_updated_by_name'] = $museum_data->fname . ' ' . $museum_data->lname;
            $prepaid_ticket_data['redeem_method'] = 'Voucher';
            $prepaid_ticket_data['payment_date'] = $prepaid_ticket_data['order_confirm_date'] = gmdate('Y-m-d H:i:s');
            $prepaid_ticket_data['updated_at'] = gmdate("Y-m-d H:i:s");
            $prepaid_tickets_data[] = $prepaid_ticket_data;
            //Update total amounts and quantity in HTO
            $ticket_detail_updations = array();
            $sns_messages = array();
            $ticket_detail_updations['ticket_ids'] = ($hto_data['ticket_ids'] == '') ? $ticket_id : $hto_data['ticket_ids'] . ',' . $ticket_id;
            $ticket_detail_updations['quantity'] = $hto_data['quantity'] + 1;
            $ticket_detail_updations['total_price'] = $hto_data['total_price'] + $prepaid_ticket_data['price'];
            $ticket_detail_updations['total_net_price'] = $hto_data['total_net_price'] + $prepaid_ticket_data['net_price'];
            $this->update("hotel_ticket_overview", $ticket_detail_updations, array("visitor_group_no" => $visitor_group_no));
            $sns_messages[] = $this->db->last_query();
            $last_inserted_array = $this->find('prepaid_extra_options', array('select' => 'count(*) as count', 'where' => 'visitor_group_no = "' . $visitor_group_no . '"'));
            if (empty($last_inserted_array)) {
                $insertion_count = 800;
            } else {
                $insertion_count = 800 + $last_inserted_array[0]['count'];
            }
            // Insert entry into prepaid_extra_options table
            $prepaid_extra_options_data = array();
            foreach ($extra_options as $extra_option) {
                foreach ($extra_option as $option) {
                    foreach ($option['options'] as $vals) {
                        $extra_options_data = array();
                        $extra_options_data['prepaid_extra_options_id'] = $visitor_group_no . str_pad(++$insertion_count, 3, "0", STR_PAD_LEFT);
                        $extra_options_data['created'] = gmdate('Y-m-d H:i:s');
                        $extra_options_data['scanned_at'] = strtotime(gmdate('Y-m-d H:i:s'));
                        $extra_options_data['visitor_group_no'] = $visitor_group_no;
                        $extra_options_data['ticket_booking_id'] = $ticket_booking_id;
                        $extra_options_data['ticket_id'] = $ticket_id;
                        $extra_options_data['ticket_price_schedule_id'] = $option['ticketpriceschedule_id'];
                        $extra_options_data['extra_option_id'] = $option['ticket_extra_option_id'];
                        $extra_options_data['main_description'] = $option['main_description'];
                        $extra_options_data['selected_date'] = ($from_time != '') ? date('Y-m-d') : '';
                        $extra_options_data['from_time'] = $from_time;
                        $extra_options_data['to_time'] = $to_time;
                        $extra_options_data['timeslot'] = $timeslot;
                        $extra_options_data['used'] = 1;
                        $extra_options_data['is_prepaid'] = 0;
                        $extra_options_data['description'] = $vals['description'];
                        $extra_options_data['price'] = $vals['price'];
                        $extra_options_data['net_price'] = $vals['net_price'];
                        $extra_options_data['tax'] = $vals['tax'];
                        $extra_options_data['quantity'] = ($vals['quantity']) ? $vals['quantity'] : 1;
                        $this->db->insert("prepaid_extra_options", $extra_options_data);
                        $prepaid_extra_options_data[] = $extra_options_data;
                    }
                }
            }
            if (isset($shared_capacity_id) && ($is_reservation == 1)) {
                $logs['update_capacity_'.$i] = array($ticket_id, date('Y-m-d'), $from_time, $to_time, $capacity, $timeslot);
                $this->update_capacity($ticket_id, date('Y-m-d'), $from_time, $to_time, $capacity, $timeslot);
            }
            if (UPDATE_SECONDARY_DB) {
                require_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new \Sqs();
                $this->load->library('Sns');
                $sns_object = new \Sns();

                if (!empty($sns_messages)) {
                    $request_array = array();
                    $request_array['db2'] = $sns_messages;
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_string = json_encode($request_array);
                    $logs['data_to_queue_UPDATE_DB_ARN_' . date('H:i:s').'_'.$i] = $request_string;
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                    }
                }
                $aws_data['add_to_prioticket'] = '1';
                $aws_data['hotel_ticket_overview_data'] = array();
                $aws_data['shop_products_data'] = array();
                $aws_data['prepaid_extra_options_data'] = $prepaid_extra_options_data;
                $aws_data['prepaid_tickets_data'] = $prepaid_tickets_data;
                $aws_data['cc_row_data'] = isset($response['cc_rows']) ? unserialize($response['cc_rows']) : array();
                $aws_data['write_in_mpos_logs'] = 1;
                $aws_data['visitor_group_no'] = $visitor_group_no;
                $aws_data['action'] = "insert_new_postpaid_enteries";
                $aws_message = base64_encode(gzcompress(json_encode($aws_data)));
                $logs['data_to_queue_VENUE_TOPIC_ARN_' . date('H:i:s').'_'.$i] = json_encode($aws_data);
                $queueUrl = QUEUE_URL_VENUE;
                if (LOCAL_ENVIRONMENT == 'Local') {
                    local_queue_helper::local_queue($aws_message, 'VENUE_TOPIC_ARN');
                } else {
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);

                    if ($MessageId) {
                        $sns_object->publish($MessageId . '#~#' . $queueUrl, VENUE_TOPIC_ARN);
                    }
                }

                //update redeem_cashiers_details table
                $update_redeem_table['visitor_group_no'] = $visitor_group_no;
                $update_redeem_table['prepaid_ticket_ids'] = array($prepaid_ticket_data['prepaid_ticket_id']);
                $update_redeem_table['museum_cashier_id'] = $prepaid_ticket_data['museum_cashier_id'];
                $update_redeem_table['shift_id'] = $shift_id;
                $update_redeem_table['redeem_date_time'] = gmdate("Y-m-d H:i:s");
                $update_redeem_table['hotel_id'] = $hotel_id;
                $update_redeem_table['museum_cashier_name'] = $prepaid_ticket_data['museum_cashier_name'];

                //Queue to update DB1 and DB2 and redeem tickets table
                if (!empty($update_redeem_table)) {
                    $request_array = array();
                    $request_array['update_redeem_table'] = $update_redeem_table;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s').'_'.$i] = $request_array;
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                    } else {
                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                    }
                }
            }
            $response['status'] = 1;
            $response['message'] = 'Success';
        } else {
            $response['status'] = ($responseMessage != '') ? $responseMessage : '';
            $response['message'] = 'Success';
        }
        $MPOS_LOGS['confirm_postpaid_tickets']['insert_new_postpaid_enteries'] = $logs;
    }

    /**
     * @Name     : get_timezone_value().
     * @Purpose  : convert passed timezone value into seconds
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 8 july 2019
     */
    function get_timezone_value($timezone){
        $hrs_mints = explode(':', $timezone);
        $hr = $hrs_mints[0];
        $mints = ($hrs_mints[1] > 0) ? $hrs_mints[1] : 0;
        $sign = substr($hr, 0, 1);
        if($sign == '+' || $sign == '-'){
        $hours = substr($hr, 1, 2);
        } else {
            $hours = $sign;
        }
        $seconds = ($hours * 3600) + ($mints * 60);
        if($sign == '+' || $sign == '-'){
            return $sign . '' . $seconds;
        } else {
            return $seconds;
        }
    }
    
    /*
     * used for hotel guests listing in active and completed 
     * $filter = 1: for active rooms (user has not checked out), 2: user has checked out 
     * $searchText = When seraching will be made based on room no
     * $serachPass = When searching will be made based on pass no
     *  @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 07 march 2019 
     */
    
    function getHotelBillList($data) {
        global $MPOS_LOGS;
        try {
            $logs['Request_' . date('H:i:s')] = array('Request' => $data);
            $limit = 20;
            $query = 'SELECT hto.id, hto.timezone, count(distinct hto.id) as personsingroup,hto.hotel_id, hto.timezone, hto.activation_method, hto.visitor_group_no, hto.parentPassNo, hto.roomNo, hto.user_image, hto.createdOn, hto.updatedOn, hto.online_type, count(prepaid_ticket_id) as totalTickets, sum(pt.price) as amt, sum(pt.net_price) as net_amt, hto.card_number, hto.hotel_checkout_status as checkoutStatus, hto.paymentStatus, hto.card_name, hto.receiptEmail, hto.comment, hto.expectedCheckoutTime, hto.is_block, hto.issuer_country_code FROM hotel_ticket_overview hto left join prepaid_tickets pt on hto.id = pt.hotel_ticket_overview_id and pt.deleted = "0" and pt.museum_name != "Test museum" WHERE hto.hotel_id =' . $data['hotel_id'] . ' and hto.paymentStatus =' . $data['filter'] . '  ';
            $querySearch = '';
            if ($data['searchText'] != '' && $data['searchText'] != 'Search') { // If searching is made based on room no.
                $visitor_group_nos = 0;
                if ($data['searchPass'] != '') { // If searching is made based on pass no
                    $visitor_group_nos = $this->find('hotel_ticket_overview', array('select' => ' visitor_group_no', 'where' => 'passNo like "%' . $data['searchPass'] . '%" '));
                    foreach ($visitor_group_nos as $visitors_nos) {
                        $allids[] = $visitors_nos['visitor_group_no'];
                    }
                    $allid = implode(',', $allids);
                }
                if ($allid != 0) {
                    $query .= ' and (hto.visitor_group_no in (' . $allid . '))';
                } else {
                    $querySearch = ' and (hto.passNo like "%' . $data['searchText'] . '%" or hto.roomNo = "' . $data['searchText'] . '")';
                }
            }
            $query .= ' and (hto.activation_method = "0" || hto.activation_method = "1" || hto.activation_method = "3") and hto.is_prioticket = "1"';
            if ($data['searchText'] != '') {
                $querySearch = ' and (hto.roomNo like "%' . $data['searchText'] . '%")';
            }
            if ($data['searchPass'] != '' && $allid != '') {
                $query .= ' and (hto.visitor_group_no in (' . $allid . '))';
            }
            $query .= $querySearch;

            if ($data['filter'] == 1) { // for active rooms (user has not checked out)
                $query .= ' and hotel_checkout_status = "0"';
            } else {
                $query .= ' and hotel_checkout_status = "1"';
            }
            $query .= 'and hto.is_order_from_mobile_app = 0 group by hto.visitor_group_no';
            if ($limit != '') {
                $query .= ' limit ' . $data['offset'] . ', ' . $limit;
            }
            $result = $this->primarydb->db->query($query)->result();
            $logs['hto_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            if ($result && count($result) > 0) {
                $tempArray = array();
                $flag = true;
                foreach ($result as $bill) {
                    $roomNo = $bill->roomNo;
                    $visitor_group_no = $bill->visitor_group_no;
                    $param['visitorGroupNo'] = $visitor_group_no;
                    $param['personsCount'] = $bill->personsingroup;
                    $param['parentPassNo'] = $bill->parentPassNo;
                    $param['paymentStatus'] = $bill->paymentStatus;
                    $param['roomNo'] = $roomNo;
                    $param['totalRecords'] = count($result);
                    $param['totalTickets'] = $bill->totalTickets;
                    $param['totalTicketsAmount'] = isset($bill->amt) ? $bill->amt : 0;
                    $param['totalTicketsNetAmount'] = isset($bill->net_amt) ? $bill->net_amt : 0;
                    if ($bill->paymentStatus != 2) {
                        if (in_array($roomNo, $tempArray)) { // check if room no already exist in array
                            if ($flag) {
                                $key = array_search($roomNo, $tempArray); // get index of element
                                $CheckinDate = $this->find('hotel_ticket_overview', array('select' => 'createdOn, timezone', 'where' => 'visitor_group_no = "' . $result[$key]->visitor_group_no . '"'));
                                $CheckinDate = $CheckinDate[0]['createdOn'];
                                $timeZone    = $CheckinDate[0]['timezone'];
                                $params[$key]['checkinDate'] = 'Check-in: ' . date('d M H:i', ($CheckinDate + ($timeZone * 60 * 60)));
                            }
                            $CheckinDate = $this->find('hotel_ticket_overview', array('select' => 'createdOn, timezone', 'where' => 'visitor_group_no = "' . $visitor_group_no . '"'));
                            $CheckinDate = $CheckinDate[0]['createdOn'];
                            $timeZone    = $CheckinDate[0]['timezone'];
                            $checkinStr = 'Check-in: ' . date('d M H:i', ($CheckinDate + ($timeZone * 60 * 60)));
                        } else {
                            $checkinStr = '';
                        }
                    }
                    $tempArray[] = $roomNo;
                    // checked out
                    $visitorData = $this->find('hotel_ticket_overview', array('select' => '*', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" && passNo = parentPassNo'));
                    if ($visitorData[0]['paymentStatus'] == 2) { 
                        $checkinStr = 'Check-out: ' . date('d M H:i', ($visitorData[0]['updatedOn'] + ($visitorData[0]['timezone'] * 60 * 60)));
                    }
                    $isPassDeactivated = $this->find('hotel_ticket_overview', array('select' => 'id', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" && isPassActive = "0"'));
                    $param['isPassDeactivated'] = !empty($isPassDeactivated) ? 1 : 0;
                    if ($visitorData[0]['ticketPaymentFailCount'] > 0 && $visitorData[0]['is_block'] != 2) {
                        $isCreditCardFailed = 1;
                    } else {
                        $isCreditCardFailed = 0;
                    }
                    $param['isCreditCardFailed'] = $isCreditCardFailed;
                    $param['checkinDate'] = $checkinStr;
                    $params[] = $param;
                }
            } else {
                $arrReturn['status'] = 0;
                $arrReturn['message'] = 'No Detail Found';
                $MPOS_LOGS['getHotelBillList'] = $logs;
                return $arrReturn;
            }
            $hotelbilldata['billdata'] = $params;
            $arrReturn[] = $hotelbilldata;
            $MPOS_LOGS['getHotelBillList'] = $logs;
            return $arrReturn;
        } catch (\Exception $e) {
            $MPOS_LOGS['getHotelBillList'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @name   : checkoutHotel()
     * @purpose: checkout room
     * @where  : Call from api controller
     * @return : checkout status with invoice id
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 12 march 2019 
     */
    function checkoutHotel($visitor_group_no, $email, $comment) {
        global $MPOS_LOGS;
        try {
            $card_type = 'cash';
            $logs['request_' . date('H:i:s')] = array('visitor_group_no' => $visitor_group_no ,'email' => $email ,'comment' => $comment);
            // get visitor check out status  
            $where = 'visitor_group_no ="' . $visitor_group_no . '" ';
            $select = 'roomNo, timezone, all_ticket_ids, hotel_id, parentPassNo, quantity, total_price, isBillToHotel,total_net_price, receiptEmail, createdOn, expectedCheckoutTime, pspReference,is_block, card_number, card_name, hotel_checkout_status, visitor_group_no, passNo, id, user_age, gender, user_image, isPassActive, paymentStatus';
            $hto_reponse = $this->find('hotel_ticket_overview', array('select' => $select, 'where' => $where));
            $logs['hto_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            
            // check hotel status
            if ($hto_reponse[0]['hotel_checkout_status'] == '1') {
                $arrReturn['message'] = 'This user has been already checked out';
                $arrReturn['invoice_id'] = '';
                $logs['response'] = $arrReturn;
                $MPOS_LOGS['get_guest_detail_' . date('H:i:s')] = $logs;
                $params[] = $arrReturn;
                return $params;
            } else {
                if (!empty($hto_reponse)) {
                    $isBillToHotel = $hto_reponse[0]['isBillToHotel'];
                    if ($isBillToHotel == 0) { // if credit card hotel
                        // 'checkout the the hotel
                        $response = $this->hotel_model->checkoutHotel($visitor_group_no, '', $card_type);
                        if ($response != 'error') {
                            $arrReturn['message'] = 'Success';
                            $arrReturn['invoice_id'] = $response;
                            $MPOS_LOGS['checkoutHotel'] = $logs;
                            // firestore sync parameters
                            $updatedOn = strtotime(gmdate('m/d/Y H:i:s'));
                            $checkinStr = 'Check-in: ' . date('d M H:i', ($hto_reponse[0]['createdOn'] + ($hto_reponse[0]['timezone'] * 60 * 60)));
                            $checkoutStr = 'Check-out: ' . date('d M H:i', ($updatedOn + ($hto_reponse[0]['timezone'] * 60 * 60)));
                            $ticket_array = explode(",", $hto_reponse[0]['all_ticket_ids']);
                            $prepaid_ticket_id = count($ticket_array);
                            // data sync to firestore
                            $firestore_data_save[$hto_reponse[0]['roomNo']] = array(
                                'room_no' => $hto_reponse[0]['roomNo'],
                                'payment_status' => 2,
                                'hotel_id' => $hto_reponse[0]['hotel_id'],
                                'persons_count' => $hto_reponse[0]['quantity'],
                                'visitor_group_no' => $visitor_group_no,
                                'parent_pass_no' => $hto_reponse[0]['parentPassNo'],
                                'total_tickets' => $prepaid_ticket_id,
                                'total_tickets_amount' => $hto_reponse[0]['total_price'],
                                'total_tickets_net_amount' => $hto_reponse[0]['total_net_price'],
                                'is_pass_deactivated' => $hto_reponse[0]['isPassActive'],
                                'is_credit_card_failed' => 0,
                                'check_in_date' => $checkinStr,
                                'check_out_date' => $checkoutStr,
                            );
                        } else {
                            $arrReturn['status'] = 0;
                            $arrReturn['message'] = 'Fail';
                            $arrReturn['invoice_id'] = '';
                            $MPOS_LOGS['checkoutHotel'] = $logs;
                        }
                    } else { // if bill to hotel
                        $response = $this->hotel_model->checkoutHotel($visitor_group_no, $comment, $card_type);
                        if ($response != 'error') {
                            
                            // firestore sync parameters
                            $updatedOn = strtotime(gmdate('m/d/Y H:i:s'));
                            $checkinStr = 'Check-in: ' . date('d M H:i', ($hto_reponse[0]['createdOn'] + ($hto_reponse[0]['timezone'] * 60 * 60)));
                            $checkoutStr = 'Check-out: ' . date('d M H:i', ($updatedOn + ($hto_reponse[0]['timezone'] * 60 * 60)));
                            $ticket_array = explode(",", $hto_reponse[0]['all_ticket_ids']);
                            $prepaid_ticket_id = count($ticket_array);
                            // data sync to firestore
                            // process sync firebase
                            $firestore_data_save = array(
                                'payment_status' => 2,
                                'check_in_date' => $checkinStr,
                                'check_out_date' => $checkoutStr,
                            );

                            try {
                                $MPOS_LOGS['DB'][] = 'FIREBASE';
                                $this->curl->requestASYNC('FIREBASE', '/updateField', array(
                                    'type' => 'POST',
                                    'additional_headers' => $this->all_headers(array(
                                        'action' => 'checkout_hotel_from_MPOS',
                                        'hotel_id' => $hto_reponse[0]['hotel_id'],
                                        'ticket_id' => $hto_reponse[0]['all_ticket_ids']
                                    )),
                                    'body' => array("path" => 'Pass_activation_details/' . $hto_reponse[0]['roomNo'], 'update' => $firestore_data_save)
                                )); //->async
                            } catch (\Exception $e) {
                                $logs['exception'] = $e->getMessage();
                            }
                            $arrReturn['message'] = 'Success';
                            $arrReturn['invoice_id'] = $response;
                        } else {
                            $arrReturn['message'] = 'Fail';
                            $arrReturn['invoice_id'] = '';
                        }
                    }
                } else {
                    $arrReturn['message'] = 'No Detail Found';
                    $MPOS_LOGS['checkoutHotel'] = $logs;
                    return $arrReturn;
                }
            }
            $MPOS_LOGS['checkoutHotel'] = $logs;
            return $arrReturn;
        } catch (\Exception $e) {
            $MPOS_LOGS['checkoutHotel'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }
}
?>