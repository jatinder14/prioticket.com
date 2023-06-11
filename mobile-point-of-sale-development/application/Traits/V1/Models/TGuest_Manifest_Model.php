<?php  

namespace Prio\Traits\V1\Models;

trait TGuest_Manifest_Model {
     /**
    *To load commonaly used files in all functions
    */
    function __construct() {
        parent::__construct();
        $this->load->model('V1/common_model');
    }
    
    /* #region Guest Manifest Module : Cover all api's used in guest manifest module */

    /**
     * @Name get_timeslots
     * @Purpose used to get timeslots of respective dates
     * @return mixed
     * $data - API request (same as from APP)
     */
    function get_timeslots($data) 
    {
        try {
            global $MPOS_LOGS;
            $from_date = $data['from_date'];
            $to_date = $data['to_date'];
            $shared_capacity_id = $data['shared_capacity_id'];
            $fromDate = strtotime($from_date);
            $toDate = strtotime($to_date);
            $req_start_time = !empty($data['start_time']) ? $data['start_time'] : '' ;
            $req_end_time = !empty($data['end_time']) ?  $data['end_time'] : ''  ;
            $req_ticket_id = $data['ticket_id'];
            $main_tickets = isset($data['main_tickets']) ? $data['main_tickets'] : array();
            $supplier_id = ($data['supplier_id'] != '') ? $data['supplier_id'] : '';
            for ($dates = $fromDate; $dates <= $toDate; $dates += (86400)) {
                $date = date('Y-m-d', $dates);
                $day = date('l', strtotime($date));
                $total_days[] = $day;
                $date_arr[$day][] =   $date;     
            }
            $standard_hours_query = 'select  days as day, start_from as start_time, end_to as end_time, cod_id, timeslot as timeslot_type, timeslot, capacity, timezone, selected_date, is_extra_timeslot, end_date, is_active, season_start_date, season_end_date from standardticketopeninghours where shared_capacity_id =' . $this->primarydb->db->escape($shared_capacity_id) . ' and days in  ("' . implode('","', $total_days) . '") and is_extra_timeslot = "1" order by start_from';
            $standard_hours_query = $this->primarydb->db->query($standard_hours_query);
            $logs['standard_hours_query'.date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
            $modeventcontent_query = 'select cod_id  from modeventcontent where shared_capacity_id ="' . $shared_capacity_id . '"';
            $modeventcontent_details = $this->primarydb->db->query($modeventcontent_query)->result_array();
            if(empty($modeventcontent_details)){
                $modeventcontent_query = 'select cod_id  from modeventcontent where is_own_capacity = 3 and own_capacity_id ="' . $shared_capacity_id . '"';
            }
            $modeventcontent_details = $this->primarydb->db->query($modeventcontent_query)->result_array();
            $logs['modeventcontent_query'.date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
            /*---- start ----
              purpose to fetch redeem count , note count , notes w.r.t time range if time range exist in request*/
            if(!empty($req_start_time) && !empty($req_end_time)) {
                $where = ' museum_id = "'.$modeventcontent_details[0]['cod_id'].'" and shared_capacity_id LIKE "%'.$shared_capacity_id.'%" and selected_date BETWEEN "'.$from_date.'" AND  "'.$to_date.'" and is_refunded ="0" and deleted = "0" and (order_status = "0" or order_status = "3") ' ;
                if($req_ticket_id > 0) {
                   $where .= ' and ticket_id = "'.$req_ticket_id.'"'; 
                }
                $query = 'Select  quantity, selected_date, shared_capacity_id, from_time, to_time,extra_text_field_answer, created_date_time, used from prepaid_tickets where '.$where.' order by visitor_group_no ASC';
                $redeem_details = $this->primarydb->db->query($query)->result_array();
                $logs['prepaid_tickets_query'.date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                if(!empty($redeem_details)) {
                    $date_slots = array();
                    foreach($redeem_details as $redeem_detail) {
                        if(!empty($redeem_detail['extra_text_field_answer'])) {                            
                            $note_detail_array[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['note'] = $redeem_detail['extra_text_field_answer'];
                            $note_detail_array[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['date_time'] = date("Y-m-d H:i", strtotime($redeem_detail['created_date_time']));
                            $note_detail_array[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['name'] = '';
                            $node_details[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']][] = $note_detail_array[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']];                         
                        }
                        $date_slots[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['bookings'] += $redeem_detail['quantity'];
                        if($redeem_detail['used']  == "1") {
                          $date_slots[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['redeem_count'] += $redeem_detail['quantity'];  
                        } 
                        $date_slots[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['notes_list'] = !empty($node_details[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]) ? $node_details[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']] : array();
                        $date_slots[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]['total_notes'] = count($node_details[$redeem_detail['selected_date']][$redeem_detail['from_time'].'_'.$redeem_detail['to_time']]);
                    }
                }
            }
             /*---- end ----*/
        
           
            $standard_hours_result = $standard_hours_query->result_array();
            $extra_timeslot = array();
            foreach ($standard_hours_result as $key => $standard_hour) {
                $standard_hours[$standard_hour['day']][$key] = $standard_hour;
            }
            if (!empty($standard_hours)) {
                foreach ($standard_hours as $day => $standard_hour) {
                    foreach ($standard_hour as $hour) {
                        $all_dates = array();
                        $from_time = strtotime($this->common_model->convert_time_into_user_timezone($hour['start_time'], $hour['timezone'], '0'));
                        $end_time = strtotime($this->common_model->convert_time_into_user_timezone($hour['end_time'], $hour['timezone'], '0'));
                        $from_Time = date('H:i', $from_time);
                        $to_Time = date('H:i', $end_time);
                        $selected_date = strtotime($hour['selected_date']);
                        $end_date = strtotime($hour['end_date']);
                        for ($timeslot_dates = $selected_date; $timeslot_dates <= $end_date; $timeslot_dates += (86400)) {
                            $timeslot_date = date('Y-m-d', $timeslot_dates);
                            array_push($all_dates, $timeslot_date);
                        }
                        $slot_dates = $date_arr[$day];
                        foreach ($slot_dates as $s_date) {
                            if (in_array($s_date, $all_dates)) {
                                $extra_slot[$s_date][$from_Time . '_' . $to_Time] = $hour['is_extra_timeslot'];
                            }
                            $extra_timeslot = $extra_slot;
                        }
                    }
                }
            }
            $params = array(
                'type' => 'POST',
                'additional_headers' => $this->all_headers(array(
                        'action' => 'get_timeslots_from_MPOS_guest_manifest',
                        'museum_id' => $standard_hours_result[0]['cod_id'],
                        'ticket_id' => $standard_hours_result[0]['ticket_id']
                    )),
                'body' => array("ticket_id" => 0, "ticket_class" => '2', "from_date" => $from_date, "to_date" => $to_date, 'shared_capacity_id' => $shared_capacity_id)
            );
            $logs['listcapacity_request_'.date("Y-m-d H:i:s")] = $params['body'];
            $getdata = $this->curl->request('CACHE', '/listcapacity', $params);
            $capacity_from_redis = json_decode($getdata, true);
            $actual_data = array();
            $logs['listcapacity_response_'.date("Y-m-d H:i:s")] = $capacity_from_redis;
            if (!empty($capacity_from_redis['data'])) {
                foreach ($capacity_from_redis['data'] as $day_data) {
                    $timeslots = array();
                    foreach ($day_data['timeslots'] as $timeslot) {
                        if ($timeslot['from_time'] !== '0' && $timeslot['total_capacity'] > 0) {
                            $availability_id = str_replace("-", "", $day_data['date']) . str_replace(":", "", $timeslot['from_time']) . str_replace(":", "", $timeslot['to_time']) . $shared_capacity_id;
                            $timeslots[$timeslot['from_time'] . '_' . $timeslot['to_time']] = array(
                                'slot_id' => (string) $availability_id,
                                'from_time' => $timeslot['from_time'],
                                'to_time' => $timeslot['to_time'],
                                'type' => isset($timeslot['timeslot_type']) ? $timeslot['timeslot_type'] : 'day',
                                'is_active' => ($timeslot['is_active'] == 1 && $timeslot['is_active_slot'] == 1) ? true : false,
                                'bookings' => isset($timeslot['blocked']) ? (int) ($timeslot['bookings'] - $timeslot['blocked']) : (int) $timeslot['bookings'],
                                'total_capacity' => (int) $timeslot['total_capacity'],
                                'blocked' => isset($timeslot['blocked']) ? (int) $timeslot['blocked'] : (int)  0,
                                'adjustment' =>  isset($timeslot['adjustment']) ? (int) $timeslot['adjustment'] : (int) 0,
                                'adjustment_type' => isset($timeslot['adjustment_type']) ? (int) $timeslot['adjustment_type'] : (int) 0,
                                'capacity_type' => isset($timeslot['capacity_type']) ? (int) $timeslot['capacity_type'] : (int) 0,
                                'extra_timeslot' => !empty($extra_timeslot[$day_data['date']][$timeslot['from_time'] . '_' . $timeslot['to_time']]) ?(int) $extra_timeslot[$day_data['date']][$timeslot['from_time'] . '_' . $timeslot['to_time']] : (int) 0
                            );
                        }
                    }
                    
                    if(!empty($timeslots)) {
                         /*---- start ----
                           purpose to send redeem count , note count , notes list w.r.t time range in response if time range exist in request */
                        if((isset($req_start_time) && $req_start_time != '' ) &&  (isset($req_end_time) && $req_end_time != '')) {
                            $new_timeslots = array();
                            foreach ($timeslots as $key => $timeslot) {
                                if(
                                    ($req_start_time == $req_end_time && $req_start_time == $timeslot['from_time']) || 
                                    ($req_end_time == '00:00' && $req_start_time <= $timeslot['from_time']) || 
                                    ($req_start_time <= $timeslot['from_time'] && $timeslot['to_time'] <= $req_end_time && $timeslot['to_time'] != '00:00')                                   
                                ) {
                                    $timeslot['redeem_count'] = !empty($date_slots[$day_data['date']][$key]['redeem_count']) ? (int) $date_slots[$day_data['date']][$key]['redeem_count']: (int) 0;
                                    $timeslot['total_notes'] = !empty($date_slots[$day_data['date']][$key]['total_notes']) ? (int) $date_slots[$day_data['date']][$key]['total_notes'] : (int) 0;
                                    $timeslot['notes_list'] = !empty($date_slots[$day_data['date']][$key]['notes_list']) ? $date_slots[$day_data['date']][$key]['notes_list']: array();
                                    $timeslot['bookings'] = !empty($date_slots[$day_data['date']][$key]['bookings']) ? (int) $date_slots[$day_data['date']][$key]['bookings']: (int) 0;
                                    $new_timeslots[$key] = $timeslot;
                                }
                            }
                            $timeslots = $new_timeslots;
                        }  
                         /*---- end ----*/
                        ksort($timeslots);
                        $timeslots = array_values($timeslots);
                        if(!empty($timeslots)) {
                            $actual_data[$day_data['date']] = array(
                                'date' => $day_data['date'],
                                'timeslots' => $timeslots
                            );
                        }
                        
                    }
                }
                
                $total_checkedin_guests = $total_guests = 0;
                if (!empty($main_tickets) && $supplier_id != '') {
                    $secondarydb = $this->load->database('secondary', true);
                    $query = 'select version, prepaid_ticket_id, ticket_id, timeslot, selected_date, from_time, to_time, title, booking_status, visitor_group_no, reserved_1, is_cancelled, deleted, is_addon_ticket, is_refunded , extra_booking_information from prepaid_tickets '
                        . ' where museum_id = ' . $this->primarydb->db->escape($supplier_id) . ' and ticket_id in (' . implode(',', $this->primarydb->db->escape($main_tickets)) . ') and is_addon_ticket = "0" and is_prepaid = "1" order by visitor_group_no asc ';

                    $tickets_order_in_DB = $secondarydb->query($query)->result_array();
                    $logs['pt_db2_query_'.date("H:i:s")] = $query;

                    $tickets_order_in_DB = $this->get_max_version_data($tickets_order_in_DB, 'prepaid_ticket_id');
                    foreach($tickets_order_in_DB as $orders) {
                        if($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0") {
                            $tickets_orders_in_DB[] = $orders;
                        }
                    }
                    $contact_uids = array_values(array_unique(array_column($tickets_orders_in_DB, 'reserved_1')));
                    $contacts = $this->get_contacts($contact_uids);
                    $logs['filtered contacts'] = $contacts;
                    $tickets_data = $guests_count = array();
                    foreach ($tickets_orders_in_DB as $db_orders) {
                        if ($db_orders['reserved_1'] != "" && $db_orders['reserved_1'] != null && $db_orders['reserved_1'] != '0') {
                            $info = $this->get_contact_info($db_orders, $contacts, 0);
                            $ticket_key = $db_orders['selected_date'] . "_" . $db_orders['from_time'] . "_" . $db_orders['to_time'] . "_" . $db_orders['ticket_id'];
                            if (!isset($guests_count[$ticket_key][$info['contact_uid']]) || $guests_count[$ticket_key][$info['contact_uid']] == null) {
                                $tickets_data[$ticket_key]['total_guests'] = ($tickets_data[$ticket_key]['total_guests'] > 0) ? $tickets_data[$ticket_key]['total_guests'] + 1 : 1;
                                $guests_count[$ticket_key][$info['contact_uid']] = 1;
                            }
                        }
                    }
                    if($from_date == $to_date && $req_start_time != '' && $req_end_time != '' && $main_tickets[0] != 0) {
                        $main_ticket_key = $from_date . "_" . $req_start_time . "_" . $req_end_time . "_" . $main_tickets[0];
                    }
                    $logs['tickets_data_tickets_data'] = $tickets_data;
                    foreach($tickets_data as $key => $data) { //consider guests of main ticket and selected timeslot only.
                        if($main_ticket_key != '') {
                            if($key == $main_ticket_key) {
                                $total_guests += $data['total_guests'];
                            }
                        } else {
                            $total_guests += $data['total_guests'];
                        }
                    }
                    $total_checkedin_guests = (int)$total_guests;
                }
                
                if(!empty($actual_data)) {
                    $response['data'] = array_values($actual_data);
                    $response['total_checkedin_guests'] = ($total_checkedin_guests > 0) ? $total_checkedin_guests : 0;
                    $response['status'] = 1;
                    $response['message'] = "Slots fetched";
                }
                else {
                    $response['data'] = array();
                    $response['total_checkedin_guests'] = 0;
                    $response['status'] = 1;
                    $response['message'] = "No Timeslots Found!";
                }
            } else {
                $response = $this->exception_handler->error_500();
            }
            $MPOS_LOGS['get_timeslots'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['get_timeslots'] = $logs;
            return $logs['exception'];
        }
        
    }
    
    /**
     * @Name update_timeslots
     * @Purpose used to update a particular timeslot (add or reduce availability count, deactive or active slot) 
     * @return mixed
     * $data - API request (same as from APP)
     */
    function update_timeslots($data)
    {
        global $MPOS_LOGS;
        try {
            $timeslot_satus = $data['active']; //1 - active, 0 - deactive
            $timeslot_type = $data['type'];
            $date = $data['date'];
            $from_time = $data['from_time'];
            $to_time = $data['to_time'];
            $shared_capacity_id = $data['shared_capacity_id'];
            $actual_capacity = $data['actual_capacity'];
            $adjustment = ($data['adjustment'] > 0) ? $data['adjustment'] : 0;
            $adjustment_type = $data['adjustment_type']; //1 - Increased, 2 - Decreased
            $update_data = array();
            $capacity_details = $this->find(
                    'ticket_capacity_v1', array('select' => '*',
                'where' => 'shared_capacity_id = ' . $this->primarydb->db->escape($shared_capacity_id) . ' and date = ' . $this->primarydb->db->escape($date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ''), "array"
            );
            $logs['fetch from ticket_capacity_v1_' . date('H:i:s')] = $this->primarydb->db->last_query();
            if (!empty($capacity_details)) { // update existing entry
                $logs['existing in ticket_capacity_v1_' . date('H:i:s')] = $capacity_details;
                if($adjustment_type == "2" && ($capacity_details[0]['actual_capacity'] - $capacity_details[0]['sold'] < $adjustment)){
                    $remaningCount =($capacity_details[0]['actual_capacity']) - ($capacity_details[0]['sold']);
                    $logs['update ticket_capacity_v1_' . date('H:i:s')] = 'nothing to update because Adjustment cannot be allowed.';
                    $response['status'] = (int) 0;
                    $response['message'] = "Adjustment cannot be allowed more than $remaningCount.";
                    $MPOS_LOGS['update_timeslots'] = $logs;
                    return $response;
                }
                $update_data['adjustment'] = $adjustment;
                $update_data['adjustment_type'] = $adjustment_type;
                if ($timeslot_satus != $capacity_details[0]['is_active']) {
                    $update_data['is_active'] = $timeslot_satus;
                    if($timeslot_satus) {
                        $update_data['action_performed'] = (isset($capacity_details[0]['action_performed']) && !empty($capacity_details[0]['action_performed'])) ? $capacity_details[0]['action_performed']. ", MPOS_GM_CAP_ON_ADJ" : "MPOS_GM_CAP_ON_ADJ";
                    } else {
                        $update_data['action_performed'] = (isset($capacity_details[0]['action_performed']) && !empty($capacity_details[0]['action_performed'])) ? $capacity_details[0]['action_performed']. ", MPOS_GM_CAP_OFF_ADJ" : "MPOS_GM_CAP_OFF_ADJ";
                    }
                } else {
                    $update_data['action_performed'] = (isset($capacity_details[0]['action_performed']) && !empty($capacity_details[0]['action_performed'])) ? $capacity_details[0]['action_performed']. ", MPOS_GM_CAP_ADJ" : "MPOS_GM_CAP_ADJ";
                }

                if (!empty($update_data)) {
                    $this->update('ticket_capacity_v1', $update_data, array('shared_capacity_id' => $shared_capacity_id, 'date' => $date, 'from_time' => $from_time, 'to_time' => $to_time));
                    $logs['update ticket_capacity_v1_' . date('H:i:s')] = $this->db->last_query();
                } else { //nothing to update
                    $logs['update ticket_capacity_v1_' . date('H:i:s')] = 'nothing to update';
                }
                $headers = $this->all_headers(array(
                    'action' => 'update_timeslots_from_MPOS_guest_manifest',
                    'museum_id' => $capacity_details[0]['museum_id']
                ));
            } else { //add new entry
                $logs['in ticket_capacity_v1_' . date('H:i:s')] = 'New Entry';
                $mec_data = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, mec_id, cod_id', 'where' => 'shared_capacity_id = '.$this->primarydb->db->escape($shared_capacity_id).' or own_capacity_id = '.$this->primarydb->db->escape($shared_capacity_id).''));
                $logs['fetch_mec_' . date('H:i:s')] = $this->db->last_query();
                $logs['mec_data_' . date('H:i:s')] = $mec_data;
                $update_data = array();
                $update_data['created'] = gmdate('Y-m-d H:i:s');
                $update_data['modified'] = gmdate('Y-m-d H:i:s');
                $update_data['ticket_id'] = isset($mec_data[0]['mec_id']) ? $mec_data[0]['mec_id'] : 0;
                $update_data['museum_id'] = $mec_data[0]['cod_id'];
                $update_data['shared_capacity_id'] = $shared_capacity_id;
                $update_data['date'] = $date;
                $update_data['timeslot'] = $timeslot_type;
                $update_data['from_time'] = $from_time;
                $update_data['to_time'] = $to_time;
                $update_data['actual_capacity'] = $actual_capacity;
                $update_data['adjustment_type'] = $adjustment_type;
                $update_data['adjustment'] = $adjustment;
                $update_data['is_active'] = $timeslot_satus;
                if($timeslot_satus) {
                    $update_data['action_performed'] = 'MPOS_GM_CAP_ON_ADJ';
                } else {
                    $update_data['action_performed'] = 'MPOS_GM_CAP_OFF_ADJ';
                }
                $update_data['blocked'] = 0;
                $update_data['sold'] = 0;
                $update_data['google_event_id'] = '';
                $this->save('ticket_capacity_v1', $update_data);
                $logs['insert in ticket_capacity_v1_' . date('H:i:s')] = $this->db->last_query();
                $headers = $this->all_headers(array(
                    'ticket_id' => $mec_data[0]['mec_id'],
                    'action' => 'update_timeslots_from_MPOS_guest_manifest',
                    'museum_id' => $mec_data[0]['cod_id']
                ));
            }

            if (!empty($update_data) && SYNC_WITH_FIREBASE == 1) {
               //to update data on redis               
                $params = array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array (
                        "ticket_id" => $shared_capacity_id,
                        "date" => $date,
                        "from_time" => $from_time,
                        "to_time" => $to_time,
                        "adjustment_type" => $adjustment_type,
                        'adjustment' => $adjustment,
                        'is_active' => $timeslot_satus,
                        "created_at" => gmdate('Y-m-d H:i:s'),
                        "modified_at" => gmdate('Y-m-d H:i:s')
                    )
                );
                $logs['update_redis_' . date('H:i:s')] = $params['body'];
                $this->curl->requestASYNC('CACHE', '/update_timeslot_settings', $params);
                $getdata = $this->curl->request('CACHE', '/listcapacity', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("ticket_id" => 0, "ticket_class" => '2', "from_date" => $date, "to_date" => $date, 'shared_capacity_id' => $shared_capacity_id)
                ));
                $capacity_from_redis = json_decode($getdata, true);
                foreach ($capacity_from_redis['data'] as $day_data) {
                    foreach ($day_data['timeslots'] as $timeslot) {
                        if ($timeslot['from_time'] !== '0' && $timeslot['total_capacity'] > 0) {
                            if ($timeslot['from_time'] == $from_time && $timeslot['to_time'] == $to_time) {
                                $availability_id = str_replace("-", "", $date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
                                $update_firebase = array(
                                    'slot_id' => (string) $availability_id,
                                    'from_time' => $from_time,
                                    'to_time' => $to_time,
                                    'type' => ($timeslot_type != '') ? $timeslot_type : 'day',
                                    'is_active' => ($timeslot_satus == 1) ? true : false,
                                    'bookings' => (int) ($timeslot['bookings'] - $timeslot['blocked']),
                                    'total_capacity' => ($adjustment_type == 1) ? (int) $actual_capacity + $adjustment : (int) $actual_capacity - $adjustment,
                                    'blocked' => (int) isset($timeslot['blocked']) ? $timeslot['blocked'] : 0,
                                );
                            }
                        }
                    }
                }                
                //to update data on firebase
                
                $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array(
                        "node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $date . '/timeslots',
                        'search_key' => 'to_time',
                        'search_value' => $to_time,
                        'details' => $update_firebase
                    )
                ));
            }
            $response['status'] = (int) 1;
            $response['message'] = 'Timeslot Updated';
            $MPOS_LOGS['update_timeslots'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['update_timeslots'] = $logs;
            return $logs['exception'];
        }
    }

    /**
     * @Name get_timeslot_bookings
     * @Purpose To return set bookings for a specific shared_capacity_id, selected_date and selected timeslot.
     * @CreatedBy Jatinder <jatinder.aipl@gmail.com> on 23 Oct 2019
    */
    function get_timeslot_bookings($data = array()) {
        
        global $MPOS_LOGS;
	    global $internal_logs;
        
        try {
            
            $logs = array();
            array_map('trim', $data);
            if(!isset($data['supplier_id']) || empty($data['supplier_id'])) {
                throw new \Exception("Supplier id cannot be blank.");
            }
            if(!isset($data['shared_capacity_id']) || empty($data['shared_capacity_id'])) {
                throw new \Exception("Shared capacity id cannot be blank.");
            }
            $selected_date = date('Y-m-d');
            if(isset($data['selected_date']) && !empty($data['selected_date'])) {
                $selected_date = $data['selected_date'];
            }
            if(!isset($data['from_time']) && empty($data['from_time'])) {
                throw new \Exception("From time cannot be blank.");
            }
            if(!isset($data['to_time']) && empty($data['to_time'])) {
                throw new \Exception("To time cannot be blank.");
            }
            
            $db                     = $this->primarydb->db;
            $shared_capacity_id     = $data['shared_capacity_id'];
            $from_time              = $data['from_time'];
            $to_time                = $data['to_time'];
            $cashier_id             = $data['cashier_id'];
            $supplier_id            = $data['supplier_id'];
            $show_guest_wise        = $data['show_guest_wise'];
            
            if($show_guest_wise == '1') {
                
                $resp                   = $this->listGuestWiseData($data);
                if(empty($resp['data'])) {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
                }
                else {
                    $allBookingsCount       = $resp['total_count'];
                    $notesCount             = ((!empty($resp['note_count']))? array($resp['note_count']): 0);
                    $responseData           = (!empty($resp['data'])? array_values($resp['data']): array());
                }
            }
            else {
                
                $db->select("hide_tickets");
                $db->from("users");
                $db->where(array("id" => $cashier_id));
                $query_users = $db->get();
                $logs['users_query_' . date('Y-m-d H:i:s')] = $db->last_query();
                $result_users = $query_users->row_array();
                $hidden_tickets = (!empty($result_users['hide_tickets'])? array_map('trim', explode(",", $result_users['hide_tickets'])): array());

                $db->select("pt.quantity, pt.prepaid_ticket_id, pt.ticket_type, pt.tps_id, pt.passNo AS ticket_code, pt.bleep_pass_no, 
                            pt.ticket_id, pt.title, pt.shared_capacity_id, pt.museum_id, pt.visitor_group_no, pt.hotel_name, pt.channel_type, pt.used, 
                            pt.is_order_confirmed, pt.booking_status, pt.order_status, pt.order_status_hto, pt.order_status AS pt_order_status, pt.is_refunded,
                            pt.activation_method, pt.guest_names, pt.guest_emails, pt.extra_text_field_answer, pt.bleep_pass_no, pt.passNo, pt.without_elo_reference_no as reference, 
                            pt.pos_point_name AS location, pt.batch_id, pt.museum_cashier_name as guide_name, pt.selected_date, pt.from_time, pt.to_time, pt.extra_booking_information, 
                            pt.product_type, pt.selected_date, pt.from_time, pt.to_time, pt.created_date_time, pt.hotel_id, pt.tp_payment_method, 
                            pt.third_party_type, pt.third_party_response_data, pt.extra_booking_information, pt.deleted");
                $db->from("prepaid_tickets pt");
                $db->where(array("pt.museum_id" => $data['supplier_id'], "pt.selected_date" => $selected_date));
                $db->where("pt.shared_capacity_id LIKE '%".$shared_capacity_id."%'");
                $db->where("pt.from_time = '{$from_time}' AND pt.to_time = '{$to_time}'");
                $db->where(array("pt.order_status != " => '1', "pt.is_refunded != " => '1', "pt.deleted !=" => '1'));
                $query = $db->get();
                $logs['pt_query_' . date('Y-m-d H:i:s')] = $db->last_query();
                if($query->num_rows() == 0) {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
                }
                else {
                    $queryData                  = $query->result_array();
                    
                    
                    $result = $groupArray = $count = $prepaid_ticket_ids = $ticket_type = $quantity = $tps_ids = $ticket_code = $bleep_pass_nos = array();
                    foreach($queryData as $v) {
                        
                        $keyGroup = implode("_", array($v['visitor_group_no'], $v['selected_date'], $v['from_time'], $v['to_time'], $v['ticket_id'], $v['is_refunded']));
                        if(!isset($groupArray[$keyGroup])) {
                            $groupArray[$keyGroup]              = $v;
                        }
                        
                        $count[$keyGroup][]                   = $v['quantity'];
                        $prepaid_ticket_ids[$keyGroup][]      = $v['prepaid_ticket_id'];
                        $ticket_type[$keyGroup][]             = $v['ticket_type'];
                        $quantity[$keyGroup][]                = $v['quantity'];
                        $tps_ids[$keyGroup][]                 = $v['tps_id'];
                        $ticket_code[$keyGroup][]             = $v['ticket_code'];
                        $bleep_pass_nos[$keyGroup][]          = $v['bleep_pass_no'];
                    }
                    
                    if(!empty($groupArray)) {
                        
                        $c = 0;
                        foreach($groupArray as $k1 => $v1) {
                            
                            $result[$c]                             = $v1;
                            $result[$c]['count']                    = array_sum($count[$k1]);
                            $result[$c]['prepaid_ticket_ids']       = implode(",", array_filter($prepaid_ticket_ids[$k1]));
                            $result[$c]['ticket_type']              = implode(",", array_filter($ticket_type[$k1]));
                            $result[$c]['quantity']                 = implode(",", array_filter($quantity[$k1]));
                            $result[$c]['tps_ids']                  = implode(",", array_filter($tps_ids[$k1]));
                            $result[$c]['ticket_code']              = implode(",", array_filter($ticket_code[$k1]));
                            $result[$c]['bleep_pass_nos']           = implode(",", array_filter($bleep_pass_nos[$k1]));
                            $c++;
                        }
                    }
                    /* GROUPING THE DATA */
                    
                    $all_visitor_group_nos      = array_unique(array_column($result, 'visitor_group_no'));
                    $batch_ids                  = array_unique(array_filter(array_column($result, 'batch_id')));
                    $cancelled_ticket           = $used_ticket = array();
                    if (!empty($all_visitor_group_nos)) {

                       $data = $db->select("visitor_group_no, is_refunded, order_status, used, ticket_id, selected_date, from_time, to_time, deleted")
                                    ->from("prepaid_tickets")
                                    ->where(array("museum_id" => $supplier_id))
                                    ->where_in("visitor_group_no", $all_visitor_group_nos)
                                    ->where(array("selected_date" => $selected_date))
                                    ->like("shared_capacity_id", $shared_capacity_id)
                                    ->where("( from_time = '{$from_time}' AND to_time = '{$to_time}' )")
                                    ->where(array("order_status != " => '1', "is_refunded != " => '1' , "deleted !=" => '1'))
                                    ->get();
                        if ($data->num_rows() > 0) {

                            $order_details = $data->result_array();
                            foreach ($order_details as $oVal) {

                                if ($oVal['is_refunded'] == 2) {
                                    if (isset($cancelled_ticket[$oVal['visitor_group_no']]) && $cancelled_ticket[$oVal['visitor_group_no']] > 0) {
                                        $cancelled_ticket[$oVal['visitor_group_no']] = $cancelled_ticket[$oVal['visitor_group_no']] + 1;
                                    }
                                    else {
                                        $cancelled_ticket[$oVal['visitor_group_no']] = 1;
                                    }
                                }
                                if ($oVal['used'] == 1) {

                                    $key = implode("_", array($oVal['visitor_group_no'], $oVal['ticket_id'], $oVal['selected_date'], $oVal['from_time'], $oVal['to_time']));
                                    if (isset($used_ticket[$key]) && $used_ticket[$key] > 0) {
                                        $used_ticket[$key] = $used_ticket[$key] + 1;
                                    }
                                    else {
                                        $used_ticket[$key] = 1;
                                    }
                                }
                            }
                        }
                    }

                    $db->select("pt_extra.prepaid_ticket_id, pt_extra.ticket_id, pt_extra.ticket_type, pt_extra.extra_booking_information, pt_extra.visitor_group_no, pt_extra.is_refunded");
                    $db->from("prepaid_tickets pt_extra");
                    $db->where(array("museum_id" => $supplier_id));
                    $db->where_in("pt_extra.visitor_group_no", $all_visitor_group_nos);
                    $db->where(array("selected_date" => $selected_date));
                    $db->where("shared_capacity_id LIKE '%".$shared_capacity_id."%'");
                    $db->where("( from_time = '{$from_time}' AND to_time = '{$to_time}' )");
                    $db->where(array("order_status != " => '1', "is_refunded != " => '1'));
                    $query_extra = $db->get();
                    $logs['pt_query_extra_' . date('Y-m-d H:i:s')] = $db->last_query();
                    $result_extra = $query_extra->result_array();
                    $typeSpots = $count = $seatDetails = $guestName = $participantInfo = array();
                    foreach($result_extra as $valExtra) {

                        $typeId = (isset($this->types[strtolower($valExtra['ticket_type'])])? $this->types[strtolower($valExtra['ticket_type'])]: 10);

                        $count[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded'] ."_". $typeId] = (isset($count[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded'] ."_".$typeId])? $count[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded'] ."_". $typeId]+1: 0);

                        $bookingInfoData    = (!empty($valExtra['extra_booking_information'])? json_decode($valExtra['extra_booking_information'], true): array());
                        $section            = (!empty($bookingInfoData['product_type_spots'][0]['spot_section'])? $bookingInfoData['product_type_spots'][0]['spot_section']: '');
                        $row                = (!empty($bookingInfoData['product_type_spots'][0]['spot_row'])? $bookingInfoData['product_type_spots'][0]['spot_row']: '');
                        $number             = (!empty($bookingInfoData['product_type_spots'][0]['spot_number'])? $bookingInfoData['product_type_spots'][0]['spot_number']: '');
                        $spot_details       = ["section" => $section, "row" => $row, "number" => $number];

                        $typeSpots[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded']][$typeId][$count[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded'] ."_".$typeId]] = $spot_details;
                        $seatDetails[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded']][$typeId][$count[$valExtra['visitor_group_no'] . "_" . $valExtra['is_refunded'] ."_".$typeId]] = (array) $spot_details;
                        
                        $participantInfo = (isset($bookingInfoData['per_participant_info'])? $bookingInfoData['per_participant_info']: array());
                        $guestName[$valExtra['ticket_id'] . "_" . $valExtra['prepaid_ticket_id']] = (!empty(trim($participantInfo['name']))? $participantInfo['name']: '');
                    }

                    $batchesArray = array();
                    if(!empty($batch_ids) && is_array($batch_ids)) {

                        $dataBatches = $db->select("batch_id, batch_name")
                                          ->from("batches")
                                          ->where_in("batch_id", $batch_ids)
                                          ->get();
                        if ($data->num_rows() > 0) {

                            $batchesDetails     = $dataBatches->result_array();
                            $batchesArray       = array_combine(array_column($batchesDetails, 'batch_id'), $batchesDetails);
                        }
                    }

                    $responseData = array();
                    if(!empty($result)) {

                        $allBookingsCount   = array_sum(array_column($result, 'count'));
                        $total_count        = $types = $qty = $bookingList = $notesCount = array();
                        foreach($result as $key => $val) {

                            $mergeGuest = array();
                            
                            if ($val['tp_payment_method'] == 6) { //combi
                                $product_type = 2;
                            } else if ($val['tp_payment_method'] == 7) { //cluster
                                $product_type = 3;
                            } else {
                                $product_type = 0; //normal
                            }

                            //check if row is refunded
                            $refundedRow = false;
                            if($val['is_refunded'] == "2" || $val['pt_order_status'] == "2") {
                                $refundedRow = true;
                            }

                            //Preparing total count for each ticket
                            if($refundedRow == false) {
                                $total_count[$val['ticket_id']]     = ((isset($total_count[$val['ticket_id']]))? $total_count[$val['ticket_id']] + $val['count']: $val['count']);
                            }

                            //Ticket types array for each ticket
                            $expTps         = explode(",", $val['tps_ids']);
                            $expTypes       = explode(",", $val['ticket_type']);
                            $expQty         = explode(",", $val['quantity']);
                            foreach($expTps as $tKey => $tVal) {

                                $qty[$val['ticket_id']][$tVal]      = ((isset($qty[$val['ticket_id']][$tVal]))? (($refundedRow==true)? $qty[$val['ticket_id']][$tVal]: ($qty[$val['ticket_id']][$tVal] + $expQty[$tKey])): (($refundedRow==true)? 0: $expQty[$tKey]));
                                $types[$val['ticket_id']][$tVal]    = array("tps_id"              => (int) $tVal, 
                                                                            "ticket_type"         => (array_key_exists(strtolower($expTypes[$tKey]), $this->types)? $this->types[strtolower($expTypes[$tKey])]: 10), 
                                                                            "ticket_type_label"   => $expTypes[$tKey], 
                                                                            "count"               => (int) $qty[$val['ticket_id']][$tVal]);
                            }
                            
                            //Status Name
                            $booking_status = $this->getStatusName($val, $used_ticket, $cancelled_ticket);
                            
                            //Booking_list array on the basis of VGN
                            if(!empty(trim($val['extra_text_field_answer'])) && !isset($notesCount[$val['visitor_group_no']])) { 
                                $notesCount[$val['visitor_group_no']]++; 
                            }

                            $bookingInfo = (!empty($val['extra_booking_information'])? json_decode(stripslashes($val['extra_booking_information']), true): array());
                            $phoneNum = (isset($bookingInfo['per_participant_info']) && !empty($bookingInfo['per_participant_info']['phone_no'])? $bookingInfo['per_participant_info']['phone_no']: '');
                            $section = $row = $number = $secret_key = $spot_details = $event_key = '';
                            $thirdPartyResponseData = $bookingInfoData = array();
                            if($val['third_party_type'] == "36") {

                                $thirdPartyResponseData = (!empty($val['third_party_response_data'])? json_decode($val['third_party_response_data'], true): array());
                                if(
                                    isset($thirdPartyResponseData['third_party_reservation_detail']['supplier']) && 
                                    !empty($thirdPartyResponseData['third_party_reservation_detail']['supplier']['secretKey'])
                                ) {
                                    $secret_key = $thirdPartyResponseData['third_party_reservation_detail']['supplier']['secretKey'];
                                }

                                $event_key = (isset($thirdPartyResponseData['third_party_reservation_detail']['product_availability_id']) && !empty($thirdPartyResponseData['third_party_reservation_detail']['product_availability_id'])? $thirdPartyResponseData['third_party_reservation_detail']['product_availability_id']: '');
                            }
                            else {
                                $typeSpots[$val['visitor_group_no'] . "_" . $val['is_refunded']] = (object) array();
                                $seatDetails[$val['visitor_group_no'] . "_" . $val['is_refunded']] = array();
                            }

                            // get guest names
                            if(!empty($guestName) && !empty($val['prepaid_ticket_ids'])) {
                                
                                $expPtid = explode(",", $val['prepaid_ticket_ids']);
                                if(!empty($expPtid)) {
                                    
                                    foreach($expPtid as $pid) {
                                        
                                        $ky = implode("_", array($val['ticket_id'], $pid));
                                        if(isset($guestName[$ky]) && !empty($guestName[$ky])) {
                                            $mergeGuest[] = $guestName[$ky];
                                        }
                                    }
                                }
                            }
                            
                            $bookingList[$val['ticket_id']][] = array("guest"                 => (int) $val['count'], 
                                                                      "prepaid_ticket_id"     => (int) $val['prepaid_ticket_id'], 
                                                                      "booking_id"            => substr($val['visitor_group_no'], -6), 
                                                                      "visitor_group_no"      => $val['visitor_group_no'], 
                                                                      "booking_count"         => (int) $val['count'], 
                                                                      "channel"               => $val['hotel_name'], 
                                                                      "channel_type"          => (int) $val['channel_type'], 
                                                                      "note"                  => (!empty($val['extra_text_field_answer'])? $val['extra_text_field_answer']: ''), 
                                                                      "booking_name"          => (!empty($val['guest_names'])? $val['guest_names']: ''), 
                                                                      "email"                 => (!empty($val['guest_emails'])? $val['guest_emails']: ''), 
                                                                      "reference"             => (string) $val['reference'], 
                                                                      "pass_no"               => (!empty($val['bleep_pass_nos'])? implode(",", array_filter(explode(",", $val['bleep_pass_nos']))): ''), 
                                                                      "ticket_code"           => (!empty($val['ticket_code'])? implode(",", array_filter(explode(",", $val['ticket_code']))): ''), 
                                                                      "distributor_name"      => $val['hotel_name'], 
                                                                      "location"              => $val['location'], 
                                                                      "batch_name"            => (!empty($batchesArray[$val['batch_id']])? trim($batchesArray[$val['batch_id']]['batch_name']): ''), 
                                                                      "guide_name"            => (!empty($val['guide_name'])? $val['guide_name']: ''), 
                                                                      "status"                => $booking_status, 
                                                                      "phone_number"          => $phoneNum, 
                                                                      "product_type"          => $product_type, 
                                                                      "selected_date"         => $val['selected_date'], 
                                                                      "from_time"             => $val['from_time'], 
                                                                      "to_time"               => $val['to_time'], 
                                                                      "booking_date_time"     => $val['created_date_time'], 
                                                                      "language_code"         => "en", 
                                                                      "can_redeem"            => (in_array($val['title'], $hidden_tickets)? "0": "1"), 
                                                                      "hotel_id"              => $val['hotel_id'], 
                                                                      "spot_details"          => $typeSpots[$val['visitor_group_no'] . "_" . $val['is_refunded']], 
                                                                      "seat_details"          => $seatDetails[$val['visitor_group_no'] . "_" . $val['is_refunded']], 
                                                                      "secret_key"            => $secret_key, 
                                                                      "event_key"             => $event_key, 
                                                                      "guest_name"            => (!empty($mergeGuest)? implode(", ", array_filter($mergeGuest)): ''));

                            $responseData[$val['ticket_id']]['ticket_id']                = (int) $val['ticket_id'];
                            $responseData[$val['ticket_id']]['ticket_title']             = $val['title'];
                            $responseData[$val['ticket_id']]['shared_capacity_id']       = (int) $val['shared_capacity_id'];
                            $responseData[$val['ticket_id']]['museum_id']                = (int) $val['museum_id'];
                            $responseData[$val['ticket_id']]['total_count']              = (int) $total_count[$val['ticket_id']];
                            $responseData[$val['ticket_id']]['ticket_types']             = (!empty($types[$val['ticket_id']])? array_values($types[$val['ticket_id']]): array());
                            $responseData[$val['ticket_id']]['booking_list']             = (!empty($bookingList[$val['ticket_id']])? array_values($bookingList[$val['ticket_id']]): array());
                        }
                    }
                    
                    $allBookingsCount = array_sum(array_values($total_count));
                }
            }
            
            $response['status']         = (int) 1;
            $response['total_count']    = $allBookingsCount;
            $response['note_count']     = ((!empty($notesCount))? (int) array_sum(array_values($notesCount)): 0);
            $response['data']           = (!empty($responseData)? array_values($responseData): array());
            $MPOS_LOGS['get_timeslot_bookings_' . date('Y-m-d H:i:s')] = $logs;
            return $response;
        }
        catch(\Exception $e) {
            
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['get_timeslot_bookings_' . date('Y-m-d H:i:s')] = $logs;
            return $MPOS_LOGS['exception'];
        }
    }
    
    /**
     * @Name listGuestWiseData
     * @Purpose To return set bookings for a specific shared_capacity_id, selected_date and selected timeslot grouped by prepaid_ticket_id.
     * @CreatedBy Jatinder <jatinder.aipl@gmail.com> on 15 Jan 2020
    */
    private function listGuestWiseData($data=array()) {
        
        global $MPOS_LOGS;
	    global $internal_logs;
        
        try { 
            
            $logs                   = array();
            $db                     = $this->primarydb->db;
            $shared_capacity_id     = $data['shared_capacity_id'];
            $selected_date          = $data['selected_date'];
            $from_time              = $data['from_time'];
            $to_time                = $data['to_time'];
            $cashier_id             = $data['cashier_id'];
            $supplier_id            = $data['supplier_id'];
            
            $db->select("hide_tickets");
            $db->from("users");
            $db->where(array("id" => $cashier_id));
            $query_users = $db->get();
            $logs['users_query_' . date('Y-m-d H:i:s')] = $db->last_query();
            $result_users = $query_users->row_array();
            $hidden_tickets = (!empty($result_users['hide_tickets'])? array_map('trim', explode(",", $result_users['hide_tickets'])): array());
            
            $db->select("pt.prepaid_ticket_id, pt.ticket_type, pt.quantity, pt.tps_id, 
                        pt.ticket_id, pt.title, pt.shared_capacity_id, pt.museum_id, pt.visitor_group_no, pt.hotel_name, pt.channel_type, pt.used, 
                        pt.is_order_confirmed, pt.booking_status, pt.order_status, pt.order_status_hto, pt.order_status AS pt_order_status, pt.is_refunded,
                        pt.activation_method, pt.guest_names, pt.guest_emails, pt.extra_text_field_answer, pt.bleep_pass_no, pt.passNo, pt.without_elo_reference_no as reference, 
                        pt.pos_point_name AS location, pt.batch_id, pt.museum_cashier_name as guide_name, pt.selected_date, pt.from_time, pt.to_time, pt.extra_booking_information, 
                        pt.product_type, pt.selected_date, pt.from_time, pt.to_time, pt.created_date_time, pt.hotel_id, pt.tp_payment_method, 
                        pt.third_party_type, pt.third_party_response_data, pt.extra_booking_information, pt.is_cancelled, pt.deleted");
            $db->from("prepaid_tickets pt");
            $db->where(array("pt.museum_id" => $supplier_id, "pt.selected_date" => $selected_date));
            $db->where("pt.shared_capacity_id LIKE '%".$shared_capacity_id."%'");
            $db->where("pt.from_time = '{$from_time}' AND pt.to_time = '{$to_time}'");
            $db->where(array("pt.order_status != " => '1', "pt.is_refunded != " => '1', "pt.deleted !=" => '1'));
            $query = $db->get();
            $logs['pt_query_' . date('Y-m-d H:i:s')] = $db->last_query();
            if($query->num_rows() == 0) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
            }
            else {
                
                $result                     = $query->result_array();
                $batch_ids                  = array_unique(array_filter(array_column($result, 'batch_id')));
                
                $bookingList = $batchesArray = $totalCount = $notesCount = array();
                if(!empty($batch_ids) && is_array($batch_ids)) {
                    
                    $dataBatches = $db->select("batch_id, batch_name")
                                      ->from("batches")
                                      ->where_in("batch_id", $batch_ids)
                                      ->get();
                    if ($data->num_rows() > 0) {
                        
                        $batchesDetails     = $dataBatches->result_array();
                        $batchesArray       = array_combine(array_column($batchesDetails, 'batch_id'), $batchesDetails);
                    }
                }
                $allBookingsCount = $total_count = 0;
                foreach($result as $val) {
                    
                    $ptid       = $val['prepaid_ticket_id'];
                    $ticketId   = $val['ticket_id'];
                    $type       = $val['ticket_type'];
                    
                    if ($val['tp_payment_method'] == 6) { //combi
                        $product_type = 2;
                    } else if ($val['tp_payment_method'] == 7) { //cluster
                        $product_type = 3;
                    } else {
                        $product_type = 0; //normal
                    }
                    
                    $refundedRow = false;
                    if($val['is_refunded'] == "2" || $val['pt_order_status'] == "2") {
                        $refundedRow = true;
                    }
                    
                    //Preparing total count for each ticket
                    if($refundedRow == false) {
                        $total_count++;
                        $totalCount[$ticketId]++;
                        
                        $types[$ticketId][] = array("tps_id"            => (int) $val['tps_id'], 
                                                "ticket_type"           => (array_key_exists(strtolower($type), $this->types)? $this->types[strtolower($type)]: 10), 
                                                "ticket_type_label"     => $type, 
                                                "count"                 => 1);
                    }
                    
                    //Status Name
                    $booking_status = '';
                    if (in_array($val['channel_type'], array(0,1,3,5,10,11))) {
                        $booking_status = 'Confirmed';
                    }
                    else {
                        
                        if($val['used'] == "1") {
                            $booking_status = 'Redeemed';
                        }
                        elseif(in_array($val['order_status_hto'], array(1,2,3))) {
                            
                            $statuses = array(1 => 'Block', 2 => 'Quote', 3 => 'Option');
                            $booking_status = $statuses[$val['order_status_hto']];
                            if ($val['is_order_confirmed'] == 1) {
                                $booking_status = 'Confirmed';
                            }
                        }
                        else if ($val['order_status_hto'] == 4) {
                            
                            // allowed activation method for the status
                            $booking_status = 'Pending';
                            if (in_array($val['activation_method'], array('14','15','16'))) {
                                $booking_status = 'Booked';
                            }
                            else if ($val['is_order_confirmed'] == 1) {
                                $booking_status = 'Confirmed';
                            }
                        }
                    }
                    
                    $redeem_count   = 0;
                    if ($val['used'] > 0) {
                        $redeem_count = 1;
                    }
                    
                    if (( $booking_status == 'Confirmed' ) && ( $redeem_count > 0 )) {
                        
                        $booking_status = 'Redeemed';
                        if($redeem_count < $val['count']) {
                            $booking_status = $redeem_count . ' Redeemed';
                        }
                    }
                    if($val['used'] == "1") {
                        
                        $booking_status = 'Redeemed';
                        if($redeem_count > 0 && $redeem_count < $val['count']) {
                            $booking_status = $redeem_count . ' Redeemed';
                        }
                    }
                    
                    if ($val['is_cancelled'] == '1') {
                        $booking_status = "Refunded";
                    }
                    if($val['is_refunded'] == "2" || $val['pt_order_status'] == "2") {
                        $booking_status = 'Refunded';
                    }
                    if($val['pt_order_status'] == "3") {
                        $booking_status = 'Correction';
                    }
                    
                    //Booking_list array on the basis of VGN
                    if(!empty(trim($val['extra_text_field_answer'])) && !isset($notesCount[$val['visitor_group_no']])) { 
                        $notesCount[$val['visitor_group_no']]++; 
                    }
                    
                    $bookingInfo = (!empty($val['extra_booking_information'])? json_decode(stripslashes($val['extra_booking_information']), true): array());
                    $phoneNum = (isset($bookingInfo['per_participant_info']) && !empty($bookingInfo['per_participant_info']['phone_no'])? $bookingInfo['per_participant_info']['phone_no']: '');
                    $guestName = (isset($bookingInfo['per_participant_info']) && !empty($bookingInfo['per_participant_info']['name'])? $bookingInfo['per_participant_info']['name']: '');
                    
                    $section = $row = $number = $secret_key = $spot_details = $event_key = '';
                    $thirdPartyResponseData = $bookingInfoData = array();
                    if($val['third_party_type'] == "36") {

                        $thirdPartyResponseData = (!empty($val['third_party_response_data'])? json_decode($val['third_party_response_data'], true): array());
                        if(
                            isset($thirdPartyResponseData['third_party_reservation_detail']['supplier']) && 
                            !empty($thirdPartyResponseData['third_party_reservation_detail']['supplier']['secretKey'])
                        ) {
                            $secret_key = $thirdPartyResponseData['third_party_reservation_detail']['supplier']['secretKey'];
                        }

                        $event_key = (isset($thirdPartyResponseData['third_party_reservation_detail']['product_availability_id']) && !empty($thirdPartyResponseData['third_party_reservation_detail']['product_availability_id'])? $thirdPartyResponseData['third_party_reservation_detail']['product_availability_id']: '');
                        
                        $bookingInfoData    = (!empty($val['extra_booking_information'])? json_decode($val['extra_booking_information'], true): array());
                        $section            = (!empty($bookingInfoData['product_type_spots'][0]['spot_section'])? $bookingInfoData['product_type_spots'][0]['spot_section']: '');
                        $row                = (!empty($bookingInfoData['product_type_spots'][0]['spot_row'])? $bookingInfoData['product_type_spots'][0]['spot_row']: '');
                        $number             = (!empty($bookingInfoData['product_type_spots'][0]['spot_number'])? $bookingInfoData['product_type_spots'][0]['spot_number']: '');
                        $spot_details       = ["section" => $section, "row" => $row, "number" => $number];

                        $typeId = (isset($this->types[strtolower($type)])? $this->types[strtolower($type)]: 10);
                        
                        $typeSpots[$ptid][$typeId] = $spot_details;
                        $seatDetails[$ptid][$typeId] = array($spot_details);
                    }
                    else {
                        $typeSpots[$ptid] = (object) array();
                        $seatDetails[$ptid] = array();
                    }
                    
                    $bookingList[$ticketId][] = array("guest"                 => 1, 
                                                      "prepaid_ticket_id"     => (int) $ptid, 
                                                      "booking_id"            => substr($val['visitor_group_no'], -6), 
                                                      "visitor_group_no"      => $val['visitor_group_no'], 
                                                      "booking_count"         => 1, 
                                                      "channel"               => $val['hotel_name'], 
                                                      "channel_type"          => (int) $val['channel_type'], 
                                                      "note"                  => (!empty($val['extra_text_field_answer'])? $val['extra_text_field_answer']: ''), 
                                                      "booking_name"          => (!empty($val['guest_names'])? $val['guest_names']: ''), 
                                                      "email"                 => (!empty($val['guest_emails'])? $val['guest_emails']: ''), 
                                                      "reference"             => (string) $val['reference'], 
                                                      "pass_no"               => (!empty($val['bleep_pass_no'])? $val['bleep_pass_no']: ''), 
                                                      "ticket_code"           => $val['passNo'], 
                                                      "distributor_name"      => $val['hotel_name'], 
                                                      "location"              => $val['location'], 
                                                      "batch_name"            => (!empty($batchesArray[$val['batch_id']])? trim($batchesArray[$val['batch_id']]['batch_name']): ''), 
                                                      "guide_name"            => (!empty($val['guide_name'])? $val['guide_name']: ''), 
                                                      "status"                => $booking_status, 
                                                      "phone_number"          => $phoneNum, 
                                                      "product_type"          => $product_type, 
                                                      "selected_date"         => $val['selected_date'], 
                                                      "from_time"             => $val['from_time'], 
                                                      "to_time"               => $val['to_time'], 
                                                      "booking_date_time"     => $val['created_date_time'], 
                                                      "language_code"         => "en", 
                                                      "can_redeem"            => (in_array($val['title'], $hidden_tickets)? "0": "1"), 
                                                      "hotel_id"              => $val['hotel_id'], 
                                                      "spot_details"          => $typeSpots[$ptid], 
                                                      "seat_details"          => $seatDetails[$ptid], 
                                                      "secret_key"            => $secret_key, 
                                                      "event_key"             => $event_key, 
                                                      "guest_name"            => $guestName);
                    
                    $responseData[$ticketId]['ticket_id']                     = (int) $ticketId;
                    $responseData[$ticketId]['ticket_title']                  = $val['title'];
                    $responseData[$ticketId]['shared_capacity_id']            = (int) $val['shared_capacity_id'];
                    $responseData[$ticketId]['museum_id']                     = (int) $val['museum_id'];
                    $responseData[$ticketId]['total_count']                   = (int) $totalCount[$ticketId];
                    $responseData[$ticketId]['ticket_types']                  = (!empty($types)? $types[$ticketId]: array());
                    $responseData[$ticketId]['booking_list']                  = (!empty($bookingList)? $bookingList[$ticketId]: array());
                    
                    $allBookingsCount++;
                }
                
                $allBookingsCount = array_sum(array_values($totalCount));
            }
            
            $response['status']         = (int) 1;
            $response['total_count']    = $allBookingsCount;
            $response['note_count']     = ((!empty($notesCount))? array_sum(array_values($notesCount)): 0);
            $response['data']           = (!empty($responseData)? array_values($responseData): array());
            $MPOS_LOGS['get_timeslot_bookings__' . date('Y-m-d H:i:s')] = $logs;
            return $response;
        }
        catch(\Exception $e) {
            
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['get_timeslot_bookings__' . date('Y-m-d H:i:s')] = $logs;
            return $MPOS_LOGS['exception'];
        }
    }
    
    /**
     * @Name getStatusName
     * @Purpose To return booking status.
     * @CreatedBy Jatinder <jatinder.aipl@gmail.com> on 17 Jan 2020
    */
    private function getStatusName($val, $used_ticket, $cancelled_ticket) {
        
        $booking_status = '';
        if (in_array($val['channel_type'], array(0,1,3,5,10,11))) {
            $booking_status = 'Confirmed';
        }
        else {

            if($val['used'] == "1") {
                $booking_status = 'Redeemed';
            }
            elseif(in_array($val['order_status_hto'], array(1,2,3))) {

                $statuses = array(1 => 'Block', 2 => 'Quote', 3 => 'Option');
                $booking_status = $statuses[$val['order_status_hto']];
                if ($val['is_order_confirmed'] == 1) {
                    $booking_status = 'Confirmed';
                }
            }
            else if ($val['order_status_hto'] == 4) {

                // allowed activation method for the status
                $booking_status = 'Pending';
                if (in_array($val['activation_method'], array('14','15','16'))) {
                    $booking_status = 'Booked';
                }
                else if ($val['is_order_confirmed'] == 1) {
                    $booking_status = 'Confirmed';
                }
            }
        }

        $redeem_key     = implode("_", array($val['visitor_group_no'], $val['ticket_id'], $val['selected_date'], $val['from_time'], $val['to_time']));
        $redeem_count   = 0;

        if (isset($used_ticket[$redeem_key]) && $used_ticket[$redeem_key] > 0) {
            $redeem_count = $used_ticket[$redeem_key];
        }

        if (( $booking_status == 'Confirmed' ) && ( $redeem_count > 0 )) {

            $booking_status = 'Redeemed';
            if($redeem_count < $val['count']) {
                $booking_status = $redeem_count . ' Redeemed';
            }
        }
        if($val['used'] == "1") { 

            $booking_status = 'Redeemed';
            if($redeem_count > 0 && $redeem_count < $val['count']) {
                $booking_status = $redeem_count . ' Redeemed';
            }
        }
        if (isset($cancelled_ticket[$val['visitor_group_no']]) && !empty(trim($cancelled_ticket[$val['visitor_group_no']]))) {
            $booking_status .= " & " . $cancelled_ticket[$val['visitor_group_no']] . " Quantity Refunded";
        }
        if($val['is_refunded'] == "2" || $val['pt_order_status'] == "2") {
            $booking_status = 'Refunded';
        }
        if($val['pt_order_status'] == "3") {
            $booking_status = 'Correction';
        }
        
        return $booking_status;
    }
    
    /**
     * update_multiple_timeslots
     * @purpose To update details of multiple timeslots 
     * @param  mixed $data
     * @return void
     * @created by supriya saxena<supriya10.aipl@gmail.com>
     */
    function update_multiple_timeslots($data) {
        global $MPOS_LOGS;
        try {
            $slots = $data['slots'];
            $timeslot_status = $data['action'];
            foreach ($slots as $slot) {

                $shared_capacity_id = $slot['shared_capacity_id'];

                $date = $slot['date'];
                $from_time = $slot['from_time'];
                $to_time = $slot['to_time'];
                $where[] = '(shared_capacity_id = ' . $this->primarydb->db->escape($shared_capacity_id) . ' and date = ' . $this->primarydb->db->escape($date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ')';
            }
            // fetching data w.r.t shared_capacity_id , date, from_time, to_time
            $capacity_details = $this->find(
                    'ticket_capacity_v1', array('select' => '*', 'where' => implode(" OR ", $where)), "array"
            );
            $logs['fetch from ticket_capacity_v1_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $logs['existing in ticket_capacity_v1_' . date('H:i:s')] = $capacity_details;
            foreach ($capacity_details as $capacity_detail) {
                $capacity_detail_array[$capacity_detail['date']][$capacity_detail['from_time'] . '_' . $capacity_detail['to_time']] = $capacity_detail;
            }
           // preparing array for updations and insertion of new records
            foreach ($slots as $slot) {
                if (!empty($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']])) { // if slot details exist in db
                    $update_data =
                     array('shared_capacity_id' => $slot['shared_capacity_id'], 
                     'date' => $slot['date'] , 
                     'from_time'=> $slot['from_time'], 
                     'to_time' => $slot['to_time'], 
                     'is_active'=> $timeslot_status,
                     'adjustment' => $slot['adjustment'],
                     'adjustment_type' => $slot['adjustment_type']);
                     if ($timeslot_status != $capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['is_active']) {
                        if($timeslot_status) {
                            $update_data['action_performed'] = (isset($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]) && !empty($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed'])) ? $capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed']. ", MPOS_GM_CAP_ON_ADJ" : "MPOS_GM_CAP_ON_ADJ";
                        } else {
                            $update_data['action_performed'] = (isset($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed']) && !empty($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed'])) ? $capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed']. ", MPOS_GM_CAP_OFF_ADJ" : "MPOS_GM_CAP_OFF_ADJ";
                        }
                    } else {
                        $update_data['action_performed'] = (isset($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed']) && !empty($capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed'])) ? $capacity_detail_array[$slot['date']][$slot['from_time'] . '_' . $slot['to_time']]['action_performed']. ", MPOS_GM_CAP_ADJ" : "MPOS_GM_CAP_ADJ";
                    }
                     $update_data_array[] = $update_data;
                    $logs['update detail req ticket_capacity_v1_' . date('H:i:s')] = $update_data_array;
                } else {
                    $logs['in ticket_capacity_v1_' . date('H:i:s')] = 'New Entry';
                    $mec_data = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, mec_id, cod_id', 'where' => 'shared_capacity_id = ' . $this->primarydb->db->escape($slot['shared_capacity_id']) . ' or own_capacity_id = ' . $this->primarydb->db->escape($slot['shared_capacity_id']) . ''));
                    $logs['fetch_mec_' . date('H:i:s')] =  $this->primarydb->db->last_query();
                    $logs['mec_data_' . date('H:i:s')] = $mec_data;
                    $update_data = array();
                    $update_data['created'] = gmdate('Y-m-d H:i:s');
                    $update_data['modified'] = gmdate('Y-m-d H:i:s');
                    $update_data['ticket_id'] = isset($mec_data[0]['mec_id']) ? $mec_data[0]['mec_id'] : 0;
                    $update_data['museum_id'] = $mec_data[0]['cod_id'];
                    $update_data['shared_capacity_id'] = $slot['shared_capacity_id'];
                    $update_data['date'] = $slot['date'];
                    $update_data['timeslot'] = $slot['type'];
                    $update_data['from_time'] = $slot['from_time'];
                    $update_data['to_time'] = $slot['to_time'];
                    $update_data['actual_capacity'] = $slot['actual_capacity'];
                    $update_data['adjustment_type'] = $slot['adjustment_type'];
                    $update_data['adjustment'] = $slot['adjustment'];
                    $update_data['is_active'] = $timeslot_status;
                    $update_data['blocked'] = 0;
                    $update_data['sold'] = 0;
                    $update_data['google_event_id'] = '';
                    if($timeslot_status) {
                        $update_data['action_performed'] = 'MPOS_GM_CAP_ON_ADJ';
                    } else {
                        $update_data['action_performed'] = 'MPOS_GM_CAP_OFF_ADJ';
                    }

                    $insert_details[] = $update_data;
                }
            }
            // executing queries
            if (!empty($update_data_array)) {
                $where_array = array('shared_capacity_id', 'date', 'from_time', 'to_time');
                $this->update_batch('ticket_capacity_v1', $update_data_array, $where_array);
                $logs['update query  ticket_capacity_v1_' . date('H:i:s')] = $this->db->last_query();
            }
            if ($insert_details) {
                $this->insert_batch('ticket_capacity_v1', $insert_details);
                $logs['insert query ticket_capacity_v1_' . date('H:i:s')] = $this->db->last_query();
            }
            // updating details on redis and syncing details on firebase
            if (SYNC_WITH_FIREBASE == 1) {
                $headers = $this->all_headers(array(
                    'action' => 'update_multiple_timeslots_from_MPOS_guest_manifest',
                    'museum_id' => isset($capacity_details[0]['museum_id']) ? $capacity_details[0]['museum_id'] : $mec_data[0]['cod_id'],
                ));
                foreach ($slots as $slot) {
                    //to update data on redis               
                    $params = array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => array(
                            "ticket_id" => $slot['shared_capacity_id'],
                            "date" => $slot['date'],
                            "from_time" => $slot['from_time'],
                            "to_time" => $slot['to_time'],
                            "adjustment_type" => $slot['adjustment_type'],
                            'adjustment' => $slot['adjustment'],
                            'is_active' => $timeslot_status,
                            "created_at" => gmdate('Y-m-d H:i:s'),
                            "modified_at" => gmdate('Y-m-d H:i:s')
                        )
                    );
                    $logs['update_redis_'.$slot['shared_capacity_id'] .'_'.$slot['to_time'].'_'.date('H:i:s')] = $params['body'];
                    $this->curl->requestASYNC('CACHE', '/update_timeslot_settings', $params);
                    $getdata = $this->curl->request('CACHE', '/listcapacity', array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => array("ticket_id" => 0, "ticket_class" => '2', "from_date" => $slot['date'], "to_date" => $slot['date'], 'shared_capacity_id' => $slot['shared_capacity_id'])
                    ));
                    $capacity_from_redis = json_decode($getdata, true);
                    foreach ($capacity_from_redis['data'] as $day_data) {
                        foreach ($day_data['timeslots'] as $timeslot) {
                            if ($timeslot['from_time'] !== '0' && $timeslot['total_capacity'] > 0 && $timeslot['from_time'] == $slot['from_time'] && $timeslot['to_time'] == $slot['to_time']) {
                                $availability_id = str_replace("-", "", $slot['date']) . str_replace(":", "", $slot['from_time']) . str_replace(":", "", $slot['to_time']) . $slot['shared_capacity_id'];
                                $update_firebase = array(
                                    'slot_id' => (string) $availability_id,
                                    'from_time' => $slot['from_time'],
                                    'to_time' => $slot['to_time'],
                                    'type' => ($slot['type'] != '') ? $slot['type'] : 'day',
                                    'is_active' => ($timeslot_status == 1) ? true : false,
                                    'bookings' => (int) ($timeslot['bookings'] - $timeslot['blocked']),
                                    'total_capacity' => ($slot['adjustment_type'] == 1) ? (int) $slot['actual_capacity'] + $slot['adjustment'] : (int) $slot['actual_capacity'] - $slot['adjustment'],
                                    'blocked' => (int) isset($timeslot['blocked']) ? $timeslot['blocked'] : 0,
                                );
                                $sync_data[$availability_id]['api'] = 'update_details_in_array';
                                $sync_data[$availability_id]['node'] = 'ticket/availabilities/' . $slot['shared_capacity_id'] . '/' . $slot['date'] . '/timeslots';
                                $sync_data[$availability_id]['details'] = $update_firebase;
                                $sync_data[$availability_id]['search_key'] = 'to_time';
                                $sync_data[$availability_id]['search_value'] = $slot['to_time'];
                            }
                        }
                    }

                }
                //to update data on firebase
                $sync_details['sync_data'] = $sync_data;
                $logs['update_firebase_' . date('H:i:s')] = $sync_details;
                $this->curl->requestASYNC('FIREBASE', '/sync_details', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => $sync_details
                ));
            }
            $response['status'] = (int) 1;
            $response['message'] = 'Timeslot Updated';
            $MPOS_LOGS['update_multiple_timeslots'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['update_multiple_timeslots'] = $logs;
            return $logs['exception'];
        }
    }

    /**
     * @Name tickets_listing
     * @Purpose : to return tickets listing of distributors or corresponding to reseller or for a supplier 
     * @return status and data 
     *      status 1 or 0
     *      open_tickets having all booking type tickets
     *      capacity_details having all reservation tickets
     * @param 
     *      $req - $data() -decoded from JWT Token
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 Aug 2020
     */ 
    function tickets_listing ($data = array()) {
        global $MPOS_LOGS;       
        try {
            $logs['cashier_type'] = $data['cashier_type'];
            $cod_id_query = '';
            $hidden_tickets = $hidden_capacities = $suppliers = array();
            if ($data['cashier_type'] == '3' && $data['reseller_id'] != '') { //reseller user
                $suppliers = $this->common_model->get_supplier_of_reseller($data['reseller_id']);
                $cod_id_query = ' in (' . implode(",", $suppliers) .')';
            } elseif ($data['cashier_type'] == '1' && $data['distributor_id'] != '') { //distributor user logged in
                $suppliers = $this->common_model->get_own_supplier_of_dist($data['distributor_id']);
                $cod_id_query = ' in (' . implode(",", $suppliers) .')';
            } else if ($data['supplier_id'] != '') { //supplier user
                $cod_id_query = ' = "'.$data['supplier_id'].'"';
            }

            if ($data['supplier_cashier'] != '') {
                $user_data =  $this->find('users', array('select' => 'hide_tickets, hide_capacities', 'where' => 'id = "'.$data['supplier_cashier'].'" and deleted = "0"'), 'array');
                $logs['users_query'] = $this->primarydb->db->last_query();
                $logs['user_data'] = $user_data;
                $hidden_tickets = ($user_data[0]['hide_tickets'] != '' && $user_data[0]['hide_tickets'] != NULL) ? explode(",", $user_data[0]['hide_tickets']) : array();
                $hidden_capacities = ($user_data[0]['hide_capacities'] != '' && $user_data[0]['hide_capacities'] != NULL) ? explode(",", $user_data[0]['hide_capacities']) : array();
            }
            $hidden_tickets = array_map('trim', $hidden_tickets);
            $hidden_capacities = array_map('trim', $hidden_capacities);
            $logs['hidden_tickets and hidden_capacities'] = array('hidden_tickets' => $hidden_tickets, 'hidden_capacities' => $hidden_capacities);
            if(!empty($data['flag_ids'])) { //filter on flag basis
                $ticket_flags = $timeslot_flags = $timeslots = array();
                $timeslots_query = '';
                $entities = $this->find(
                    'flag_entities',
                        array(
                            'select' => '*',
                            'where' => 'item_id in (' . implode(",", $data['flag_ids']) .') and entity_type in ("5", "8") and deleted = "0"'),
                        'array'
                    );
                foreach($entities as $entity) {
                    if($entity['entity_type'] == "5" && !in_array($entity['entity_id'], $ticket_flags)) {
                        $ticket_flags[] = $main_ticket_id = $entity['entity_id'];
                    } else if($entity['entity_type'] == "8" && !in_array($entity['entity_id'], $timeslot_flags)) {
                        $timeslot_flags[] = $entity['entity_id'];
                        $timeslot['date'] = substr($entity['entity_id'], 0, 4) . "-" . substr($entity['entity_id'], 4, 2) . "-" . substr($entity['entity_id'], 6, 2);
                        $timeslot['from_time'] = substr($entity['entity_id'], 8, 2) . ":" . substr($entity['entity_id'], 10, 2);
                        $timeslot['to_time'] = substr($entity['entity_id'], 12, 2) . ":" . substr($entity['entity_id'], 14, 2);
                        $timeslot['shared_capacity_id'] = substr($entity['entity_id'], 16);
                        $timeslots[$timeslot['shared_capacity_id']] = $timeslot;
                    }
                }
                $logs['ticket_flags and timeslots_flags'] = array('ticket_flags' => $ticket_flags, 'timeslots_flags' => $timeslots);
                $subtickets_n_addons = array();
                if(!empty($ticket_flags)) {
                    $sub_tickets = $this->find(
                        'cluster_tickets_detail',
                            array(
                                'select' => 'cluster_ticket_id, main_ticket_id',
                                'where' => 'main_ticket_id in (' . implode(",", $ticket_flags) .') and is_deleted = "0"'),
                            'list'
                        );
                    if(!empty($sub_tickets)) {
                        $logs['sub_tickets from clusters'] = $sub_tickets;
                        $addons = $this->find(
                            'addon_tickets',
                                array(
                                    'select' => 'addon_mec_id, mec_id',
                                    'where' => 'mec_id in (' . implode(",", array_keys($sub_tickets)) .') and is_deleted = "0"'),
                                'list'
                            );
                        if(!empty($addons)) {
                            $logs['addon_tickets'] = $addons;
                            $subtickets_n_addons = array_merge(array_keys($addons), array_keys($sub_tickets));
                            foreach($addons as $addon => $addonParent) {
                                $sub_tickets[$addon] = $sub_tickets[$addonParent];
                            }
                        } else {
                            $subtickets_n_addons = array_keys($sub_tickets);
                        }
                    }
                }
                $logs['subtickets_n_addons and merged_sub_tickets'] = array('subtickets_n_addons' => $subtickets_n_addons, 'sub_tickets' => $sub_tickets);
                if(!empty($timeslots)) {
                    $timeslots_query = ' or shared_capacity_id in (' . implode(",", array_keys($timeslots)) .')';
                }
                if($main_ticket_id != "") {
                    $headers = $this->all_headers(array(
                        'action' => 'update_order_from_MPOS'
                    ));
                    $firebaseData = $this->curl->request('FIREBASE', '/get_details', array(
                        'type' => 'POST',
                        'additional_headers' => $headers,           
                        'body' => array("node" => 'ticket/details/'.$main_ticket_id.'/dependant_tickets')
                    ));
                    $dependent_ticket = json_decode($firebaseData, true);
                    $dependency[$main_ticket_id] = $dependent_ticket['data'];
                }
            }
            if ($cod_id_query != '') {
                if(!empty($subtickets_n_addons)) {
                    $cap_ids = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id', 'where' => 'mec_id in (' . implode(",", $subtickets_n_addons) .') and active = "1" and deleted = "0" and endDate >= "'.strtotime(date("Y-m-d")).'" and is_reservation = "1"'));
                    $opn_tickets =  $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, cod_id', 'where' => '(cod_id '.$cod_id_query.' or mec_id in (' . implode(",", $subtickets_n_addons) .')) and active = "1" and deleted = "0" and endDate >= "'.strtotime(date("Y-m-d")).'" and is_reservation = "0"'));
                    $ids = array();
                    foreach($cap_ids as $id) {
                        if($id['shared_capacity_id'] != "0" && $id['shared_capacity_id'] != Null) {
                            $ids[] = $id['shared_capacity_id'];
                        } 
                        if($id['own_capacity_id'] != "0" && $id['own_capacity_id'] != Null) {
                            $ids[] = $id['own_capacity_id'];
                        }
                    }
                    $addons_cap_query = '';
                    if(!empty($ids)) {
                        $addons_cap_query = ' or shared_capacity_id in (' . implode(",", $ids) .')';
                    }
                } else {
                    $opn_tickets =  $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, cod_id', 'where' => 'cod_id '.$cod_id_query.' and active = "1" and deleted = "0" and endDate >= "'.strtotime(date("Y-m-d")).'" and is_reservation = "0"'));
                }
                $logs['open_tickets_query'] = $this->primarydb->db->last_query();
                if (!empty($opn_tickets)) {
                    foreach ($opn_tickets as $opn_ticket) {
                        if (
                                (
                                (empty($ticket_flags) || (!empty($ticket_flags) && in_array($opn_ticket['mec_id'], $ticket_flags))) 
                                 && (empty($hidden_tickets) || (!empty($hidden_tickets) && !in_array($opn_ticket['mec_id'], $hidden_tickets)))
                                )
                                    ||
                                ((empty($subtickets_n_addons) && !empty($ticket_flags) ) || 
                                (
                                    !empty($subtickets_n_addons) && in_array($opn_ticket['mec_id'], $subtickets_n_addons)
                                     && 
                                        (in_array($opn_ticket['mec_id'], array_keys($sub_tickets)) && 
                                            (
                                                (!in_array($opn_ticket['mec_id'], array_keys($addons)) && (!in_array($sub_tickets[$opn_ticket['mec_id']], $hidden_tickets) )) ||
                                                (in_array($opn_ticket['mec_id'], array_keys($addons)) &&  (!in_array($sub_tickets[$addons[$opn_ticket['mec_id']]], $hidden_tickets)) )                                                 
                                            )                                        
                                        )
                                        
                                )
                                )
                            )
                            {
                            $main_ticket = (in_array($opn_ticket['mec_id'], $ticket_flags)) ?  $opn_ticket['mec_id'] : "";
                            $open_tickets[$opn_ticket['mec_id']] = array(
                                'ticket_id' => (int) $opn_ticket['mec_id'],
                                'ticket_title' => $opn_ticket['postingEventTitle'],
                                'supplier_id' => (int) $opn_ticket['cod_id'],
                                'main_ticket' => isset($sub_tickets[$opn_ticket['mec_id']]) ? $sub_tickets[$opn_ticket['mec_id']] : $main_ticket,
                                'dependant_tickets' => isset($dependency[$opn_ticket['mec_id']]) ? $dependency[$opn_ticket['mec_id']] : (object) array()
                            );
                            if(isset($addons[$opn_ticket['mec_id']]) && $addons[$opn_ticket['mec_id']] != null) {
                                $open_tickets[$opn_ticket['mec_id']]['addon_parent'] = $addons[$opn_ticket['mec_id']];
                            }
                        }
                    }
                }

                $shared_capacities = $this->find('shared_capacity', array('select' => 'shared_capacity_id, capacity_title', 'where' => 'supplier_id '.$cod_id_query.$timeslots_query.$addons_cap_query), "list");
                $logs['shared_capacities_query'] = $this->primarydb->db->last_query();
                if (!empty($shared_capacities)) {
                    if(!empty($subtickets_n_addons)) {
                        $res_tickets = $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, shared_capacity_id, own_capacity_id, cod_id', 'where' => '((cod_id '.$cod_id_query.' and (shared_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ') or own_capacity_id in (' . implode(",", array_keys($shared_capacities)) . '))) or mec_id in (' . implode(",", $subtickets_n_addons) .')) and active = "1" and deleted = "0" and endDate >= "'.strtotime(date("Y-m-d")).'" and is_reservation = "1"'));
                    } else {
                        $res_tickets = $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, shared_capacity_id, own_capacity_id, cod_id', 'where' => 'cod_id '.$cod_id_query.' and (shared_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ') or own_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ')) and active = "1" and deleted = "0" and endDate >= "'.strtotime(date("Y-m-d")).'" and is_reservation = "1"'));
                    }
                    foreach($res_tickets as $res_ticket_details) {
                        $ticket_capacity_details[$res_ticket_details['mec_id']] = $res_ticket_details;
                    }
                    $logs['reserve_tickets_query'] = $this->primarydb->db->last_query();
                    foreach ($res_tickets as $res_ticket) {
                        if (
                                (
                                (empty($ticket_flags) || (!empty($ticket_flags) && in_array($res_ticket['mec_id'], $ticket_flags))) 
                                 && (empty($hidden_tickets) || (!empty($hidden_tickets) && !in_array($res_ticket['mec_id'], $hidden_tickets)))
                                )
                                    ||
                                ((empty($subtickets_n_addons) && !empty($ticket_flags))  || 
                                
                                (
                                    !empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                     && 
                                        (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) && 
                                            (
                                                (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($sub_tickets[$res_ticket['mec_id']], $hidden_tickets) ) ||
                                                (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($sub_tickets[$addons[$res_ticket['mec_id']]], $hidden_tickets) )                                                 
                                            )                                        
                                        )
                                        
                                )
                                )
                            )
                        { //remove hidden tickets
                            if ($res_ticket['shared_capacity_id'] > 0 && 
                                (empty($hidden_capacities) || 
                                    (
                                        !empty($hidden_capacities) && !in_array($res_ticket['shared_capacity_id'], $hidden_capacities) && !in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                           ||
                                        ( (empty($subtickets_n_addons)  && !empty($ticket_flags)) || 
                                            (
                                                !empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                                && 
                                                    (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) && 
                                                        (
                                                            (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($ticket_capacity_details[$sub_tickets[$res_ticket['mec_id']]]['shared_capacity_id'], $hidden_capacities) ) ||
                                                            (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($ticket_capacity_details[$sub_tickets[$addons[$res_ticket['mec_id']]]]['shared_capacity_id'], $hidden_capacities) )                                                 
                                                        )                                        
                                                    )
                                                    
                                            )
                                        )
                                    )                           
                            
                                )  
                            ) { //remove hidden capacities
                                $main_ticket = (in_array($res_ticket['mec_id'], $ticket_flags)) ?  $res_ticket['mec_id'] : "";
                                $reservation_tickets[$res_ticket['shared_capacity_id']]['tickets'][$res_ticket['mec_id']] = array(
                                    'ticket_title' => $res_ticket['postingEventTitle'],
                                    'ticket_id' => (int) $res_ticket['mec_id'],
                                    'supplier_id' => (int) $res_ticket['cod_id'],
                                    'main_ticket' => isset($sub_tickets[$res_ticket['mec_id']]) ? $sub_tickets[$res_ticket['mec_id']] : $main_ticket,
                                    'dependant_tickets' => isset($dependency[$res_ticket['mec_id']]) ? $dependency[$res_ticket['mec_id']] : (object) array()
                                );
                                if(isset($addons[$res_ticket['mec_id']]) && $addons[$res_ticket['mec_id']] != null) {
                                    $reservation_tickets[$res_ticket['shared_capacity_id']]['tickets'][$res_ticket['mec_id']]['addon_parent'] = $addons[$res_ticket['mec_id']];
                                }
                                $reservation_tickets[$res_ticket['shared_capacity_id']]['capacity_title'] = $shared_capacities[$res_ticket['shared_capacity_id']];
                                $reservation_tickets[$res_ticket['shared_capacity_id']]['capacity_id'] = (int) $res_ticket['shared_capacity_id'];
                                $reservation_tickets[$res_ticket['shared_capacity_id']]['supplier_id'] = (int) $res_ticket['cod_id'];
                                $reservation_tickets[$res_ticket['shared_capacity_id']]['slots'] = isset($timeslots[$res_ticket['shared_capacity_id']]) ? $timeslots[$res_ticket['shared_capacity_id']] : (object) array();
                            }
                            if ($res_ticket['own_capacity_id'] > 0 &&
                                (empty($hidden_capacities) ||
                                    (
                                    !empty($hidden_capacities) && !in_array($res_ticket['own_capacity_id'], $hidden_capacities) && !in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                    ) ||
                                    ((empty($subtickets_n_addons)  && !empty($ticket_flags)) || 
                                        (
                                            !empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                            && 
                                                (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) && 
                                                    (
                                                        (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($ticket_capacity_details[$sub_tickets[$res_ticket['mec_id']]]['own_capacity_id'], $hidden_capacities) ) ||
                                                        (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($ticket_capacity_details[$sub_tickets[$addons[$res_ticket['mec_id']]]]['own_capacity_id'], $hidden_capacities) )                                                 
                                                    )                                        
                                                )
                                                
                                        )
                                    )
                                )
                            )  {//remove hidden capacities
                                $main_ticket = (in_array($res_ticket['mec_id'], $ticket_flags)) ?  $res_ticket['mec_id'] : "";
                                $reservation_tickets[$res_ticket['own_capacity_id']]['tickets'][$res_ticket['mec_id']] = array(
                                    'ticket_title' => $res_ticket['postingEventTitle'],
                                    'ticket_id' => (int) $res_ticket['mec_id'],
                                    'supplier_id' => (int) $res_ticket['cod_id'],
                                    'main_ticket' => isset($sub_tickets[$res_ticket['mec_id']]) ? $sub_tickets[$res_ticket['mec_id']] : $main_ticket,
                                    'dependant_tickets' => isset($dependency[$res_ticket['mec_id']]) ? $dependency[$res_ticket['mec_id']] : (object) array()
                                );
                                if(isset($addons[$res_ticket['mec_id']]) && $addons[$res_ticket['mec_id']] != null) {
                                    $reservation_tickets[$res_ticket['own_capacity_id']]['tickets'][$res_ticket['mec_id']]['addon_parent'] = $addons[$res_ticket['mec_id']];
                                }
                                $reservation_tickets[$res_ticket['own_capacity_id']]['capacity_title'] = $shared_capacities[$res_ticket['own_capacity_id']];
                                $reservation_tickets[$res_ticket['own_capacity_id']]['capacity_id'] = (int) $res_ticket['own_capacity_id'];
                                $reservation_tickets[$res_ticket['own_capacity_id']]['supplier_id'] = (int) $res_ticket['cod_id'];
                                $reservation_tickets[$res_ticket['own_capacity_id']]['slots'] = isset($timeslots[$res_ticket['own_capacity_id']]) ? $timeslots[$res_ticket['own_capacity_id']] : (object) array();
                            }
                        }
                    }
                }
                if(!empty($open_tickets)) {
                    foreach($open_tickets as $ticket_id =>  $open_ticket_arr) {
                        if(in_array($ticket_id, $subtickets_n_addons)) {
                            if(array_key_exists($open_ticket_arr['main_ticket'], $ticket_capacity_details)) {
                                if(in_array($ticket_capacity_details[$open_ticket_arr['main_ticket']]['shared_capacity_id'], $hidden_capacities) || in_array($ticket_capacity_details[$open_ticket_arr['main_ticket']]['own_capacity_id'], $hidden_capacities) ) {
                                    unset($open_tickets[$ticket_id ]) ;
                                }
                            }
                        } 
                    }
                }
                $MPOS_LOGS['tickets_listing'] = $logs;
                return array(
                    'status' => 1,
                    'message' => 'tickets_listing',
                    'capacity_details' => !empty($reservation_tickets) ? $reservation_tickets : (object) array(),
                    'open_tickets' => !empty($open_tickets) ? $open_tickets : (object) array()
                );
            } else {
                $logs['data_from_req'] = $data;
                $MPOS_LOGS['tickets_listing'] = $logs;
                return array(
                    'status' => 0,
                    'message' => 'Incorrect Token'
                );
            }
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['tickets_listing'] = $logs;
            return $logs['exception'];
        }
    }

    /* #endregion Guest Manifest Module : Cover all api's used in guest manifest module */
}

?>
