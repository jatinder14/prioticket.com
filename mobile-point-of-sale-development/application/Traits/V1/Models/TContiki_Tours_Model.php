<?php

namespace Prio\Traits\V1\Models;

trait TContiki_Tours_Model  {
    /*
     * To load commonaly used files in all functions
     */

    function __construct() {
        parent::__construct();
        $this->max_allowed_trips = 15;
        $this->product_type = array(
            'ticket' => 5,
            'timeslot' => 8
        );
        $this->load->model('V1/common_model');
    }

    /* #region Contiki tours Module : Cover all the functions used in contiki tours */

    /**
     * @Name get_tour_details
     * @Purpose 
     * @return status and data 
     *      status 1 or 0
     *      data having guest details or guest details with order details of a user
     * @param 
     *      $req - array() - API request (same as from APP) + user_details + hidden_tickets (of sup user)
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    function get_tour_details($req = array())
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $cancelled_statuses = array(3, 4, 6, 7, 10);
        $ticket_orders_in_DB = $total_orders_in_DB = $total_not_checked_in_guests = $flags = $flagsData = array();
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        $supplier_id = $req['supplier_id'];
        $ticket_id = $req['ticket_id'];
        $start_time = $req['start_time'];
        $entity_ids = isset($req['entity_ids']) ? $req['entity_ids'] : array();
        $reseller_id = isset($req['reseller_id']) ? $req['reseller_id'] : 0;
        $date_time = explode(" ", $start_time);
        $logs_from_get_tour_details['req_' .$reseller_id . '_'.date("H:i:s")] = $req;
        //BOC - subtickets of main ticket -> to show further slides on APP
        $cluster_tickets_query = 'select cluster_ticket_id, cluster_ticket_title, ticket_museum_id, age_from, age_to from cluster_tickets_detail where main_ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and is_deleted = "0"';
        $cluster_tickets = $this->primarydb->db->query($cluster_tickets_query)->result_array();
        $logs_from_get_tour_details['cluster_tickets_query'] = $cluster_tickets_query;
        $internallogs['cluster_tickets_data'] = $cluster_tickets;

        foreach($entity_ids as $entity_id) {
            $product_type = ($entity_id === $ticket_id) ? $this->product_type['ticket'] : $this->product_type['timeslot'];
            $flags[$entity_id] = $this->flags_of_a_entity($entity_id, $product_type, $req);
        }

        if (!empty($cluster_tickets)) {
            $sub_ticket_ids = array_unique(array_column($cluster_tickets, 'cluster_ticket_id'));
            $locations = $this->get_locations_of_tickets($sub_ticket_ids);
            $tickets_detail = $this->find(
                'modeventcontent',
                array(
                'select' => 'mec_id, startDate, startTime, endDate, endTime, postingEventTitle',
                'where' => 'mec_id in (' . implode(',', $sub_ticket_ids) . ','.$this->primarydb->db->escape($ticket_id).')'),
                'array'
            );
            $logs_from_get_tour_details['tickets_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
            $internallogs['ticket_data'] = $tickets_detail;
            foreach ($tickets_detail as $ticket_detail) {
                $timings[$ticket_detail['mec_id']]['start_time'] = date("Y-m-d", $ticket_detail['startDate']) . " " . $ticket_detail['startTime'];
                $timings[$ticket_detail['mec_id']]['end_time'] = date("Y-m-d", $ticket_detail['endDate']) . " " . $ticket_detail['endTime'];
                $ticket_titles[$ticket_detail['mec_id']] = $ticket_detail['postingEventTitle'];
            }

            foreach ($cluster_tickets as $no => $sub_ticket) {
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['ticket_id'] = $sub_ticket['cluster_ticket_id'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['title'] = $sub_ticket['cluster_ticket_title'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['sort_order'] = (int) $no + 1;
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['museum_id'] = $sub_ticket['ticket_museum_id'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['from_age'] = $sub_ticket['age_from'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['to_age'] = $sub_ticket['age_to'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['departure'] = (string) $locations[$sub_ticket['cluster_ticket_id']]['start_location'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['destination'] = (string) $locations[$sub_ticket['cluster_ticket_id']]['end_location'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['start_time'] = (string) $timings[$sub_ticket['cluster_ticket_id']]['start_time'];
                $sub_tickets_data[$sub_ticket['cluster_ticket_id']]['end_time'] = (string) $timings[$sub_ticket['cluster_ticket_id']]['end_time'];
            }
        } else {
            $ticket_titles = $this->find(
                'modeventcontent',
                array(
                'select' => 'mec_id, postingEventTitle',
                'where' => 'mec_id = '.$this->primarydb->db->escape($ticket_id).''),
                'list'
            );
            $logs_from_get_tour_details['tickets_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
        }

        //BOC - Guests_listing corresponding to each ticket -> to show in listing each screen on APP
        $total_amount = $total_owing_amount = $total_paid_amount = 0;  
        $order_currency = '';   
        $logs_from_get_tour_details['pt_query_starts_at'] = date("H:i:s");
        $internallogs['modeventcontent_data'] = $ticket_titles;
        $reseller_check = ($reseller_id > 0) ? 'reseller_id = "'. $reseller_id.'" and ' : '';
        $query = 'select visitor_group_no, version, ticket_id, ticket_booking_id, activated , prepaid_ticket_id, is_addon_ticket, title, reserved_1, secondary_guest_email, secondary_guest_name, price, passport_number, extra_booking_information, guest_names, guest_emails, tp_payment_method, is_refunded, is_cancelled, deleted, order_currency_code from prepaid_tickets '
                . ' where ' . $reseller_check . ' ((museum_id = ' . $secondarydb->escape($supplier_id) . ' and ticket_id = ' . $secondarydb->escape($ticket_id) . ' and selected_date = ' . $secondarydb->escape($date_time[0]) . ' and from_time = ' . $secondarydb->escape($date_time[1]) . ') ' //for main ticket
                . ' or '
                . ' (related_product_id = ' . $secondarydb->escape($ticket_id) . '  and is_addon_ticket = "1")) and ' //for addon tickets
                . ' is_prepaid = "1"';

        $total_orders_in_DB = $secondarydb->query($query)->result_array();
        $logs_from_get_tour_details['pt_query_db2'.date("H:i:s")] = $secondarydb->last_query();
        $internallogs['pt_query_db2_data'] = $total_orders_in_DB;
        $total_orders_in_DB = $this->get_max_version_data($total_orders_in_DB, 'prepaid_ticket_id');
        $logs_from_get_tour_details['max_data_from_pt_'.date("H:i:s")] = count($total_orders_in_DB);
        foreach($total_orders_in_DB as $orders) {
            if($orders['is_refunded'] == "1" && $orders['is_addon_ticket'] == "1" && $orders['activated'] == "1" && $orders['deleted'] == "0"){
                array_push($ticket_orders_in_DB,$orders);
            }
            if($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0" && $orders['activated'] == "1") {
                $ticket_orders_in_DB[] = $orders;
            }
        }
        $logs_from_get_tour_details['pt__db2__query_'.date("H:i:s")] = $query;
        $contact_uids = array_values(array_unique(array_column($ticket_orders_in_DB, 'reserved_1')));
        $contacts = $this->get_contacts($contact_uids);
        $vgn_based_amounts = array();
        $ticket_booking_ids = array_values(array_unique(array_column($ticket_orders_in_DB, 'ticket_booking_id')));
        $opd_query_result = $secondarydb->query('Select is_active, refund_type , status, amount, total, id, version, payment_category ,visitor_group_no, ticket_booking_id, deleted from order_payment_details where ticket_booking_id in (' . implode(',', $this->primarydb->db->escape($ticket_booking_ids)) . ') and is_active = "1"')->result_array();
        $logs_from_get_tour_details['opd_query_db2'.date("H:i:s")] = $secondarydb->last_query();
        $internallogs['opd_query_db2_data'] = $opd_query_result;
        foreach($opd_query_result as $opd_row) {
            $vgn_based_amounts[$opd_row['visitor_group_no']]['paid_amount'] += ($opd_row['status'] == 1) ? $opd_row['amount'] : 0;
            $vgn_based_amounts[$opd_row['visitor_group_no']]['total_amount'] += ($opd_row['status'] == 2) ? $opd_row['amount'] : 0;
            if(in_array($opd_row['status'], $cancelled_statuses)) {
                $vgn_based_amounts[$opd_row['visitor_group_no']]['total_amount'] -= $opd_row['amount'];
                if($opd_row['status'] == 3 || $opd_row['status'] == 4) {
                    $vgn_based_amounts[$opd_row['visitor_group_no']]['paid_amount'] -= $opd_row['amount'];
                }
            }
        }
        foreach($vgn_based_amounts as $vgn => $amounts) {
            $vgn_based_amounts[$vgn]['owing_amount'] = $amounts['total_amount'] - $amounts['paid_amount'];
        }
        foreach ($ticket_orders_in_DB as $pt_order) {
            if ($pt_order['is_addon_ticket'] == '0' && isset($contacts[$pt_order['reserved_1']])) {
                $info = $this->get_contact_info($pt_order, $contacts, 0);
                $main_guests[] = $info['contact_uid'];
            }
        }
        $main_guests = array_unique($main_guests);
        $logs_from_get_tour_details['main_guests_'.date("H:i:s")] = $main_guests;
        $passed_vgns = array();
        foreach ($ticket_orders_in_DB as $order) {
            $info = $this->get_contact_info($order, $contacts, 0);
            if (in_array($info['contact_uid'], $main_guests) ) {
                $guests[$info['contact_uid']]['guest_name'] = $info['name'];
                $guests[$info['contact_uid']]['guest_email'] = $info['email'];
                $guests[$info['contact_uid']]['contact_uid'] = ($order['reserved_1'] != '' && $order['reserved_1'] != null && $order['reserved_1'] != '0') ? $order['reserved_1'] : "";
                $guests[$info['contact_uid']]['passport_no'] = $info['passport'];
                $guests[$info['contact_uid']]['paid'] = ($order['is_addon_ticket'] == '0' && $order['tp_payment_method'] != '0') ?  '1' : $guests[$info['contact_uid']]['paid'];
                $guests[$info['contact_uid']]['total_notes'] = sizeof($this->notes($order, $contacts));
                $guests[$info['contact_uid']]['notes'] = $this->notes($order, $contacts);
                $addons = ($guests[$info['contact_uid']]['total_addons']) ? $guests[$info['contact_uid']]['total_addons'] : 0;
                $new_addons = $this->total_addons($order);
                $guests[$info['contact_uid']]['total_addons'] = ($order['is_addon_ticket'] == '0' && $new_addons >  0) ? (int) $new_addons : (int) $addons;
                $purchased_addons = isset($guests[$info['contact_uid']]['purchased_addons']) ? $guests[$info['contact_uid']]['purchased_addons'] : 0;
                $guests[$info['contact_uid']]['purchased_addons'] = ($order['is_addon_ticket'] == '1') ? $purchased_addons + 1 : $purchased_addons;
                // $guests[$info['contact_uid']]['total_amount'] += (float) $order['price'];
                $total_amount += isset($vgn_based_amounts[$order['visitor_group_no']]['total_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['total_amount'] : 0;
                if ($order['tp_payment_method'] == 0) {
                    if(!in_array($info['contact_uid'], $total_not_checked_in_guests)) {
                        $total_not_checked_in_guests[] = $info['contact_uid'];
                    }
                }
                if ((empty($passed_vgns) || (!empty($passed_vgns) && !in_array($order['visitor_group_no'] , $passed_vgns))) ) {
                    $passed_vgns[] = $order['visitor_group_no'];
                    $guests[$info['contact_uid']]['total_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['total_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['total_amount'] : 0;
                    $guests[$info['contact_uid']]['paid_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['paid_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['paid_amount'] : 0;
                    $guests[$info['contact_uid']]['owing_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['owing_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['owing_amount'] : 0;
                    $total_paid_amount += isset($vgn_based_amounts[$order['visitor_group_no']]['paid_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['paid_amount'] : 0;
                    $total_owing_amount += isset($vgn_based_amounts[$order['visitor_group_no']]['owing_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['owing_amount'] : 0;
                }
            }
            $order_currency = $order['order_currency_code'];
        }
        $logs_from_get_tour_details['entity_ids_'.date("H:i:s")] = count($entity_ids);
        if(!empty($flags)) {
            foreach($entity_ids as $entity_id) {
                foreach($flags[$entity_id] as $val) {
                    $flagsData[] = $val;
                }
            }
        }
        $response['status'] = 1;
        $response['ticket_id'] = $ticket_id;
        $response['flags'] = !empty($flagsData) ? array_values($flagsData) : array();
        $response['ticket_title'] = isset($ticket_titles[$ticket_id]) ? $ticket_titles[$ticket_id] : '';
        $response['total_guests'] = isset($guests) ? sizeof($guests) : 0;
        $response['start_time'] = isset($start_time) ? $start_time : "";
        $response['end_time'] = isset($req['end_time']) ? $req['end_time'] : "";
        $response['not_checked_in_guests'] = !empty($total_not_checked_in_guests) ? sizeof($total_not_checked_in_guests) : 0;
        $response['guests'] = isset($guests) ? array_values($guests) : array();
        $response['total_amount'] = isset($total_amount) ? (float) $total_amount : (float) 0;
        $response['paid_amount'] = isset($total_paid_amount) ? (float) $total_paid_amount : (float) 0;
        $response['owing_amount'] = isset($total_owing_amount) ? (float) $total_owing_amount : (float) 0;
        $response['sub_tickets'] = array();
        $response['order_currency'] = $order_currency;

        // $MPOS_LOGS['get_tour_details'] = $logs_from_get_tour_details;
        // $internal_logs['get_tour_details'] = $internallogs;
        /* set logs empty due to 500 error */
        $MPOS_LOGS['get_tour_details'] = '';
        $internal_logs['get_tour_details'] = '';
        return $response;
    }

    /**
     * @Name get_trip_overview
     * @Purpose 
     * @return status and data 
     *      status 1 or 0
     *      data having trip details
     * @param 
     *      $req - array() - API request (same as from APP)
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 01 April 2020
     */ 
    function get_trip_overview($req = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        $tickets_order_in_DB = $tickets_orders_in_DB = $capacities = $slot_flags = $timeslot_flags = $flag_entities = array();
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        $supplier_id = $req['supplier_id'];
        $shift_id = $req['shift_id'];
        $req['current_time'] = $this->get_time_wrt_timezone($req['timezone'], $req['current_time']/1000);

        $flag_entities = $this->get_flag_entities($shift_id, "9");
        $logs_from_get_trip_overview['shift_flag_queries'.date("H:i:s")] = $this->primarydb->db->last_query();
        if (!empty($flag_entities)) {
            $flag_ids = array_keys($flag_entities);
            $order_flags_query = 'select order_id, flag_id from order_flags'
            . ' where flag_id in (' . implode(',', $this->primarydb->db->escape($flag_ids)) . ') and flag_type = "2"';
        
            $order_flags = $secondarydb->query($order_flags_query)->result_array();
            $logs_from_get_trip_overview['order_flags_query_'.date("H:i:s")] = $order_flags_query;
            $logs_from_get_trip_overview['order_flags_data'.date("H:i:s")] = $order_flags;
            foreach($order_flags as $flag_entry) {
                $vgn_based_flags[$flag_entry['order_id']][] = $flag_entry['flag_id'];
            }
            foreach($vgn_based_flags as $order_id => $flags) {
                $array_difference = array_diff($flag_ids, $flags);
                if(empty($array_difference)) {
                    $vgns[] = $order_id;
                }
            }
            $vgns = array_unique($vgns);
            if (!empty($vgns)) {
                $locations = $this->get_locations_of_tickets($req['ticket_ids']);
                $query = 'select version, prepaid_ticket_id, ticket_id, timeslot, selected_date, shared_capacity_id, from_time, to_time, title, tp_payment_method, visitor_group_no, reserved_1, is_cancelled, deleted, is_addon_ticket, is_refunded , extra_booking_information, order_currency_code from prepaid_tickets '
                . ' where reseller_id = ' . $req['reseller_id'] . ' and visitor_group_no in (' . implode(',', $this->primarydb->db->escape($vgns)) . ') and is_addon_ticket = "0" and '
                . ' is_prepaid = "1" order by visitor_group_no asc ';

                $tickets_order_in_DB = $secondarydb->query($query)->result_array();
                $logs_from_get_trip_overview['pt_db2_query_'.date("H:i:s")] = $query;
                $tickets_order_in_DB = $this->get_max_version_data($tickets_order_in_DB, 'prepaid_ticket_id');
                $logs_from_get_trip_overview['max_data_from_ptt'] = count($tickets_order_in_DB);
                foreach($tickets_order_in_DB as $orders) {
                    if($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0") {
                        $tickets_orders_in_DB[] = $orders;
                    }
                }
                if(!empty($tickets_orders_in_DB)) {      
                    $trips = $upcoming_trip_keys = $past_trip_keys = $current_trip_keys = $new_trips = array();
                    $tickets_mec_data = $this->find(
                                'modeventcontent',
                                array(
                                'select' => 'mec_id, shared_capacity_id, own_capacity_id, duration',
                                'where' => 'mec_id in (' . implode(',', $req['ticket_ids']) . ')'),
                            'array'
                    );
                    foreach ($tickets_mec_data as $ticket) {
                        $ticket_durations[$ticket['mec_id']] = $ticket['duration'];
                        $capacities[$ticket['mec_id']][] = $ticket['shared_capacity_id'];
                                if ($ticket['own_capacity_id'] !== '' && $ticket['own_capacity_id'] !== '0') {
                                    $capacities[$ticket['mec_id']][] = $ticket['own_capacity_id'];
                        }
                    }
                    foreach ($req['ticket_ids'] as $ticket_id) {
                        $flags[$ticket_id] = $this->flags_of_a_entity($ticket_id, $this->product_type['ticket'], $req);
                    } 
                    foreach ($capacities as $capId) {
                        foreach ($capId as $capacityId) {
                            $slot_flags = $this->flags_of_a_entity($capacityId, $this->product_type['timeslot'], $req);
                            foreach ($slot_flags as $flgData) {
                                $timeslot_flags[$flgData['entity_id']][] = $flgData;
                            }
                        }
                    }
                    $logs_from_get_trip_overview['tickets_query_'.date("H:i:s")] = $this->primarydb->db->last_query();

                    //BOC to send  sub locations in response
                    foreach($tickets_orders_in_DB as $ticket_order) { //get latest vgn wrt selected date, from time , to time and ticket id
                        $ticket_order_arr[$ticket_order['selected_date'].'_'.$ticket_order['from_time'].'_'.$ticket_order['to_time'].'_'.$ticket_order['ticket_id']] = $ticket_order;
                    }

                    foreach($ticket_order_arr as $sub_arr) { // preparing array to fetch subticket of latest vgn wrt selected date, from time , to time and ticket id
                        $vgn_arr[] = $sub_arr['visitor_group_no'] ;
                        $date_vgn[$sub_arr['visitor_group_no']] = $sub_arr['selected_date'].'_'.$sub_arr['from_time'].'_'.$sub_arr['to_time'].'_'.$sub_arr['ticket_id'];
                    }

                    $sub_vgn_query = 'select ticket_id, selected_date, from_time, to_time, title, museum_id, visitor_group_no from prepaid_tickets '
                            . ' where visitor_group_no in (' . implode(',', $vgn_arr) . ') and is_addon_ticket = "2" and '
                            . ' is_prepaid = "1" and deleted = "0" and is_refunded = "0" ';
                    $sub_tickets_orders_in_DB = $this->primarydb->db->query($sub_vgn_query)->result_array();

                    $logs_from_get_trip_overview['sub_ticket_vgn_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
                    $sub_tickets_ids  = array_unique(array_column($sub_tickets_orders_in_DB, 'ticket_id'));// fetching all sub ticket ids
                    if(!empty($sub_tickets_ids)) {
                        $sub_ticket_durations = $this->find(
                            'modeventcontent',
                            array(
                                'select' => 'mec_id, duration, cod_id',
                                'where' => 'mec_id in (' . implode(',', $sub_tickets_ids) . ')'),
                                'list'
                        );
                    }           
       
                    $logs_from_get_trip_overview['sub_tickets_query_'.date("H:i:s")] = $this->primarydb->db->last_query();

                    foreach($sub_tickets_orders_in_DB as $key => $sub_vgn_query_arr) { // prepareing sublocation array
                        $sub_from_time = $sub_vgn_query_arr['from_time'] == "0" ? "00:00" : $sub_vgn_query_arr['from_time'];
                        $sub_to_time = $sub_vgn_query_arr['to_time'] == "0" ? "00:00" : $sub_vgn_query_arr['to_time'];
                        $new_sub_tickets_ids[$key]['ticket_id']         = (int) $sub_vgn_query_arr['ticket_id'];
                        $new_sub_tickets_ids[$key]['ticket_title']      = $sub_vgn_query_arr['title'];
                        $new_sub_tickets_ids[$key]['museum_id']         = $sub_vgn_query_arr['museum_id'];
                        $new_sub_tickets_ids[$key]['start_time']        = $sub_vgn_query_arr['selected_date'] . " " . $sub_from_time;
                        $new_sub_tickets_ids[$key]['end_time']          = ($sub_ticket_durations[$sub_vgn_query_arr['ticket_id']] != "") ? $this->get_duration_from_start_time($new_sub_tickets_ids[$key]['start_time'], $sub_ticket_durations[$sub_vgn_query_arr['ticket_id']]) : $sub_vgn_query_arr['selected_date'] . " " . $sub_to_time;
                        $new_sub_tickets_ids[$key]['visitor_group_no']  = $sub_vgn_query_arr['visitor_group_no'];
                    } 
                    foreach($new_sub_tickets_ids as $sub_vgn_query_array) { //preparing subarray wrt vgn
                        $visitor_group_no = $sub_vgn_query_array['visitor_group_no'];
                        unset($sub_vgn_query_array['visitor_group_no']);
                        $final_sub_vgn_query_arr[$visitor_group_no ][$sub_vgn_query_array['ticket_id']] = $sub_vgn_query_array;
                    }  
                    foreach($final_sub_vgn_query_arr as $vgn => $sub_locations) {//prepareing array wrt to main ticket selected date, from time, to time, ticket id
                        $final_new_sub_location[$date_vgn[$vgn]] = $sub_locations;
                    }            

                    //EOC to send  sub locations in response
                    $contact_uids = array_values(array_unique(array_column($tickets_orders_in_DB, 'reserved_1')));
                    $contacts = $this->get_contacts($contact_uids);
                    $guests_count = $addon_orders_in_DB = array();

                    if($req['reseller_id'] != "") {
                        $addon_orders_in_DB = $this->get_addons_of_tickets_from_reseller($req['ticket_ids'], $req['reseller_id']);
                    }
                    foreach ($tickets_orders_in_DB as $db_orders) {
                        if ($db_orders['reserved_1'] != "" && $db_orders['reserved_1'] != null && $db_orders['reserved_1'] != '0') {
                            $info = $this->get_contact_info($db_orders, $contacts, 0);
                            $ticket_key = $db_orders['selected_date'] . "_" . $db_orders['from_time'] . "_" . $db_orders['to_time'] . "_" . $db_orders['ticket_id'];
                            $slott_id = str_replace("-", "", $db_orders['selected_date']) . str_replace(":", "", $db_orders['from_time']) . str_replace(":", "", $db_orders['to_time']) . $db_orders['shared_capacity_id'];
                            $tickets_data[$ticket_key]['ticket_id'] = (int) $db_orders['ticket_id'];
                            $tickets_data[$ticket_key]['ticket_title'] = $db_orders['title'];
                            $tickets_data[$ticket_key]['departure'] = (string) $locations[$db_orders['ticket_id']]['start_location'];
                            $tickets_data[$ticket_key]['destination'] = (string) $locations[$db_orders['ticket_id']]['end_location'];
                            $tickets_data[$ticket_key]['museum_id'] = $supplier_id;
                            $tickets_data[$ticket_key]['slot_type'] = $db_orders['timeslot'];
                            $tickets_data[$ticket_key]['start_time'] = $db_orders['selected_date'] . " " . $db_orders['from_time'];
                            $tickets_data[$ticket_key]['end_time'] = ($ticket_durations[$db_orders['ticket_id']] != "") ? $this->get_duration_from_start_time($tickets_data[$ticket_key]['start_time'], $ticket_durations[$db_orders['ticket_id']]) : $db_orders['selected_date'] . " " . $db_orders['to_time'];
                            $tickets_data[$ticket_key]['note'] = "Read everything about your trip";
                            $tickets_data[$ticket_key]['total_notes'] = isset($tickets_data[$ticket_key]['total_notes']) ? $tickets_data[$ticket_key]['total_notes'] + sizeof($this->notes($db_orders, $contacts)) : sizeof($this->notes($db_orders, $contacts));
                            $tickets_data[$ticket_key]['total_addons'] = isset($tickets_data[$ticket_key]['total_addons']) ? (int) ($tickets_data[$ticket_key]['total_addons'] + $this->total_addons($db_orders, $contacts)) : (int) $this->total_addons($db_orders, $contacts);
                            $purchased_addons = isset($tickets_data[$ticket_key]['total_purchased_addons']) ? $tickets_data[$ticket_key]['total_purchased_addons'] : 0;
                            $tickets_data[$ticket_key]['total_purchased_addons'] = isset($addon_orders_in_DB[$db_orders['visitor_group_no']]) ? $purchased_addons + $addon_orders_in_DB[$db_orders['visitor_group_no']]['count'] : $purchased_addons;
                            if (isset($flags[$db_orders['ticket_id']]) && isset($timeslot_flags[$slott_id])) {
                                $tickets_data[$ticket_key]['flags'] = array_merge($timeslot_flags[$slott_id] , $flags[$db_orders['ticket_id']]);
                            } else if (isset($flags[$db_orders['ticket_id']])) {
                                $tickets_data[$ticket_key]['flags'] = $flags[$db_orders['ticket_id']];
                            } else if (isset($timeslot_flags[$slott_id])) {
                                $tickets_data[$ticket_key]['flags'] = $timeslot_flags[$slott_id];
                            }
                            if (!isset($guests_count[$ticket_key][$info['contact_uid']]) || $guests_count[$ticket_key][$info['contact_uid']] == null) {
                                $tickets_data[$ticket_key]['total_guests'] = ($tickets_data[$ticket_key]['total_guests'] > 0) ? $tickets_data[$ticket_key]['total_guests'] + 1 : 1;
                                $guests_count[$ticket_key][$info['contact_uid']] = 1;
                                if ($db_orders['tp_payment_method'] == 0) {
                                    $tickets_data[$ticket_key]['total_pending_guests'] = ($tickets_data[$ticket_key]['total_pending_guests'] > 0) ? $tickets_data[$ticket_key]['total_pending_guests'] + 1 : 1;
                                } else if(isset($addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest']) && $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'] > 0){
                                    $tickets_data[$ticket_key]['total_pending_guests'] = ($tickets_data[$ticket_key]['total_pending_guests'] > 0) ? $tickets_data[$ticket_key]['total_pending_guests'] + $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'] : $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'];
                                } else {
                                    $tickets_data[$ticket_key]['total_pending_guests'] = ($tickets_data[$ticket_key]['total_pending_guests'] > 0) ? $tickets_data[$ticket_key]['total_pending_guests'] : 0;
                                }
                            }

                            if (!empty($final_new_sub_location[$ticket_key])) {
                                $tickets_data[$ticket_key]['sub_locations'] = array_values($final_new_sub_location[$ticket_key]);
                            }
                            $tickets_data[$ticket_key]['order_currency'] = $db_orders['order_currency_code'];
                            if (strtotime($tickets_data[$ticket_key]['start_time']) > $req['current_time']) {
                                $upcoming_trip_keys[] = $ticket_key;
                            } elseif (strtotime($tickets_data[$ticket_key]['end_time']) < $req['current_time']) {
                                $past_trip_keys[] = $ticket_key;
                            } elseif (strtotime($tickets_data[$ticket_key]['end_time']) > $req['current_time'] && strtotime($tickets_data[$ticket_key]['start_time']) < $req['current_time']) {
                                $current_trip_keys[] = $ticket_key;
                            }
                        }
                    }
                    $asc_tickets_data = $desc_tickets_data = $tickets_data;
                    krsort($desc_tickets_data);
                    ksort($asc_tickets_data);
                    $logs_from_get_trip_overview['upcoming'] = $upcoming_trip_keys;
                    $logs_from_get_trip_overview['past'] = $past_trip_keys;
                    $logs_from_get_trip_overview['current'] = $current_trip_keys;
                    foreach ($asc_tickets_data as $key => $tickets_details) {
                        if (in_array($key, $upcoming_trip_keys) && sizeof($trips['upcoming_trips']) < $this->max_allowed_trips) {
                            $trips['upcoming_trips'][$key] = $tickets_details;
                        }
                        if (in_array($key, $current_trip_keys)) {
                            $trips['current_trips'][$key] = $tickets_details;
                        }
                    }
                    foreach ($desc_tickets_data as $key => $tickets_details) {
                        if (in_array($key, $past_trip_keys) && sizeof($trips['past_trips']) < $this->max_allowed_trips) {
                            $trips['past_trips'][$key] = $tickets_details;
                        }
                    }
                    if(!empty($trips['past_trips'])) {
                        ksort($trips['past_trips']); 
                        $new_trips['past_trips'] = array_values($trips['past_trips']);
                    }
                    if(!empty($trips['upcoming_trips'])) {
                        ksort($trips['upcoming_trips']); 
                        $new_trips['upcoming_trips'] = array_values($trips['upcoming_trips']);
                    }       
                    if(!empty($trips['current_trips'])) {
                        ksort($trips['current_trips']); 
                        $new_trips['current_trips'] = array_values($trips['current_trips']);
                    }
                    if (!empty($new_trips)) {
                        $res['status'] = 1;
                        $res['trips'] = $new_trips;
                    } else {
                        $res['status'] = 1;
                        $res['message'] = "No Trips Found";
                    }
                    $MPOS_LOGS['get_trip_overview'] = $logs_from_get_trip_overview;
                } else {
                    $res['status'] = 1;
                    $res['message'] = "No Trips Found";
                    $MPOS_LOGS['get_trip_overview'] = $logs_from_get_trip_overview;
                }
            } else {
                $res['status'] = 1;
                $res['message'] = "No Trips Found";
                $MPOS_LOGS['get_trip_overview'] = $logs_from_get_trip_overview;
            }
        } else {
            $res['status'] = 1;
            $res['message'] = "No Trips Found";
            $MPOS_LOGS['get_trip_overview'] = $logs_from_get_trip_overview;
        }
        return $res;
    }
    
    /**
     * @Name get_partners_listing
     * @Purpose : to return Partners listing of distributors or corresponding to reseller 
     * @return status and data 
     *      status 1 or 0
     *      data having partners list
     * @param 
     *      $req - $data() -decoded from JWT Token
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 Aug 2020
     */ 
    function get_partners_listing ($data = array()) {
        global $MPOS_LOGS;
        $this->load->model('V1/common_model');
        try {
            $logs['cashier_type'] = $data['cashier_type'];
            $distributors = array();
            $proceed = 0;
            if ($data['cashier_type'] == '3' && $data['reseller_id'] != '') { //reseller user
                $distributors = $this->common_model->get_distributors_of_reseller($data['reseller_id']);
                if (!empty($distributors)) {
                    $proceed = 1;
                    $dist_in_query = ' in (' . implode(",", $distributors) .')';
                }
            } elseif ($data['cashier_type'] == '1' && $data['distributor_id'] != '') { //distributor user logged in
                $dist_in_query = ' = '.$data['distributor_id'];
                $proceed = 1;
            }
            $logs['mid_dist_query'] = $dist_in_query;
            if($proceed == 1) {
                $cod_names = $this->find('qr_codes', array(
                    'select' => 'cod_id, company',
                    'where' => 'cod_id '. $dist_in_query . ' and isActive = "1"'), 
                        'list'
                );
                $logs['cod_ids data'] = $cod_names;
                $partners = $this->find('nav_customers', array(
                    'select' => 'id,distributer_id,name,email,payment_terms_code,country, group_id, group_name',
                    'where' => 'distributer_id '. $dist_in_query. ' and is_deleted = "0"'), 
                        'array'
                );
                $logs['partners query'] = $this->primarydb->db->last_query();
                $logs['partners data'] = $partners;
                if(!empty($cod_names)) {
                    $list = array();
                    foreach($cod_names as $cod_id => $name) {
                        $partner_listing = array();
                        foreach ($partners as $partner) {
                            if($partner['distributer_id'] == $cod_id) {
                                $partner_listing[] =  array(
                                    'id' => (int) $partner['id'],
                                    'name' => $partner['name'],
                                    'email' => $partner['email'],
                                    'group_id' => (int) $partner['group_id'],
                                    'group_name' => $partner['group_name'],
                                    'payment_terms_code' => $partner['payment_terms_code'],
                                    'country' => $partner['country']
                                );
                            }
                        }
                        $list[$cod_id]['distributor_id'] = $cod_id;
                        $list[$cod_id]['distributor_name'] = $name;
                        $list[$cod_id]['partners'] = $partner_listing;
                    }
                    $MPOS_LOGS['partners_listing'] = $logs;
                    return array(
                        'status' => 1,
                        'message' => 'Partners_listing',
                        'data' => !empty($list) ? array_values($list) : array()
                    );
                } else {
                    $MPOS_LOGS['partners_listing'] = $logs;
                    return array(
                        'status' => 0,
                        'message' => 'No distributor Found'
                    );
                }
            } else {
                $MPOS_LOGS['partners_listing'] = $logs;
                return array(
                    'status' => 0,
                    'message' => 'No distributor or reseller Found'
                );
            }
            
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['get_timeslots'] = $logs;
            return $logs['exception'];
        }
    }

    /**
     * @Name get_affiliates_listing
     * @Purpose : to return affiliates list  corresponding to reseller 
     * @return status and data 
     *      status 1 or 0
     *      data having affiliates list
     * @param 
     *      $req - $req() 
     * @created_by Supriya saxena<supriya10.aipl@gmail.com
     */ 
    function get_affiliates_listing($req = array()){
        global $MPOS_LOGS;
        try {
            $reseller_id  = $req['reseller_id'];
            $page = $req['page'];
            $item_per_page = $req['item_per_page'];
            $search_param = $req['search'];
            $offset = ($page-1) * $item_per_page;
            if($reseller_id != '') {
                $where =  'reseller_id  = '.$this->primarydb->db->escape($reseller_id).' AND cashier_type = "5" AND IsActive ="1" ';
                if($search_param != '') {
                    $where .= ' AND (cod_id LIKE "%'.$search_param.'%" OR company LIKE "%'.$search_param.'%" )';
                }

                $where .= ' LIMIT  '.$offset .', '.$item_per_page.'';
         
                $affiliates_list = $this->find( //fetch affiliates of reseller
                    'qr_codes',
                    array(
                    'select' => 'cod_id as id, company as name',
                    'where' => $where),
                    'array'
                );
                $logs['affiliates_query_'.date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                $logs['affiliates_list_'.date("Y-m-d H:i:s")] = $affiliates_list;
                if(!empty($affiliates_list)){
                    $MPOS_LOGS['affiliate_listing'] = $logs;
                    return array(
                        "status" => 1,
                        "message" => "Listing fetched successfully",
                        "data" =>  $affiliates_list
                    );

                } else {
                    $MPOS_LOGS['affiliate_listing'] = $logs;
                    return array(
                        "status" => 1,
                        "message" => "No affiliate found"
                    );
                }
            } else {
                $MPOS_LOGS['affiliate_listing'] = $logs;
                return array(
                    "status" => 0,
                    "message" => " Invalid Request"
                );
            }

        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['affiliate_listing'] = $logs;
            return $logs['exception'];

        }
    }
 
     /**
     * @Name get_affiliates_ticket_listing
     * @Purpose : to return affiliates ticket list  corresponding to affiliate 
     * @return status and data 
     *      status 1 or 0
     *      data having affiliates list
     * @param 
     *      $req - $req() 
     * @created_by Supriya saxena<supriya10.aipl@gmail.com
     */ 
    function get_affiliate_tickets_listing($req = array())
    {
        global $MPOS_LOGS;
        try {
            $flag_data = $req['flags'];
            $reseller_id = $req['reseller_id'];
            $affiliate_id  = $req['affiliate_id'];
            $page = $req['page'];
            $item_per_page = $req['item_per_page'];
            $search_param = $req['search'];
            $offset = ($page - 1) * $item_per_page;
            if ($affiliate_id != '') {
                $affiliate_tickets_list = $this->find(
                    'channel_level_affiliates',
                    array(
                        'select' => 'ticket_id',
                        'where' => 'cod_id  = ' . $this->primarydb->db->escape($affiliate_id) . ' AND deleted = "0"'
                    ),
                    'array'
                );
                $logs['affiliates_tickets_query_' . date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                $logs['affiliate_tickets_list_' . date("Y-m-d H:i:s")] = $affiliate_tickets_list;
                if (!empty($affiliate_tickets_list)) {
                    $affiliate_ticket_ids_array = array_column($affiliate_tickets_list, 'ticket_id');
                    // if flags are not coming in request
                    if (empty($flag_data)) {
                        $where =  'mec_id IN (' . implode(',', array_unique($affiliate_ticket_ids_array)) . ')';
                        if ($search_param != '') {
                            $where .= ' AND (mec_id LIKE "%' . $search_param . '%" OR postingEventTitle LIKE "%' . $search_param . '%" )';
                        }

                        $where .= ' LIMIT  ' . $offset . ', ' . $item_per_page . '';
                        $mode_query = " Select mec_id as id , postingEventTitle as title ,is_reservation from modeventcontent where " . $where;
                        $mode_event_content_details = $this->primarydb->db->query($mode_query)->result_array();

                        $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                        return array(
                            "status" => 1,
                            "message" => "Listing fetched successfully",
                            "data" =>  $mode_event_content_details
                        );
                    } else {
                        $valid_flags = $this->find(
                            'flag_entities',
                            array(
                                'select' => 'item_id',
                                'where' => 'item_id In (' . implode(',', array_unique($flag_data)) .  ')'
                            )
                        );
                        if (!empty($valid_flags)) {
                            $mapping = array();
                            $sub_ticket_arr = array();
                            $start_date_arr = $end_date_arr = array();
                            $main_ticket = "Select entity_type, entity_id from flag_entities where entity_type In (5,8)" . ' AND item_id IN (' . implode(',', array_unique($flag_data)) . ')';
                            $main_ticket_query = $this->primarydb->db->query($main_ticket)->result_array();
                            $logs['main_ticket_query_' . date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                            $logs['main_ticket_' . date("Y-m-d H:i:s")] =  $main_ticket_query;
                            foreach($main_ticket_query as $main_ticket){
                                if($main_ticket['entity_type'] == 5){
                                    $entities_ids_array[] = $main_ticket['entity_id'];        
                                }elseif($main_ticket['entity_type'] == 8){
                                    $timeslot['date'] = substr($main_ticket['entity_id'], 0, 4) . "-" . substr($main_ticket['entity_id'], 4, 2) . "-" . substr($main_ticket['entity_id'], 6, 2);
                                    $timeslot['from_time'] = substr($main_ticket['entity_id'], 8, 2) . ":" . substr($main_ticket['entity_id'], 10, 2);
                                    $timeslot['to_time'] = substr($main_ticket['entity_id'], 12, 2) . ":" . substr($main_ticket['entity_id'], 14, 2);
                                    $timeslot['shared_capacity_id'] = substr($main_ticket['entity_id'], 16);       
                                }
                            }
                            $tour_date = '';
                            if(isset($timeslot)){
                                $tour_date = $timeslot['date'];
                            }
                            $get_dependencies_query = "Select booking_start_time , booking_end_time from dependencies where main_ticket_id" . ' IN (' . implode(',', $entities_ids_array) . ') ';
                            $get_dependencies = $this->primarydb->db->query($get_dependencies_query)->result_array();
                            if (!empty($get_dependencies)) {
                                $start_time = $get_dependencies[0]['booking_start_time'];
                                $start_time = json_decode($start_time);
                                foreach ($start_time as $values) {
                                    foreach ($values as $key => $val) {
                                        $sub_tkt_arr[] = $key;
                                        $timetoadd = '';
                                        if($val->hours > 0){
                                            $timetoadd .= '+'. $val->hour . ' hour ';
                                        }
                                        if($val->mins > 0){
                                            $timetoadd .= '+'. $val->mins .' minutes ';
                                        }
                                        if($val->days > 0){
                                            $timetoadd .= '+'. $val->days .' day ';
                                        }
                                        $start_date[$key] = date('Y-m-d',strtotime($timetoadd,strtotime($tour_date)));
                                        
                                    }
                                }
                                $end_time = $get_dependencies[0]['booking_end_time'];
                                $end_time = json_decode($end_time);
                                foreach ($end_time as $values) {
                                    foreach ($values as $key => $val) {
                                        $timetoadd = '';
                                        if($val->hours > 0){
                                            $timetoadd .= '+'. $val->hour . ' hour ';
                                        }
                                        if($val->mins > 0){
                                            $timetoadd .= '+'. $val->mins .' minutes ';
                                        }
                                        if($val->days > 0){
                                            $timetoadd .= '+'. $val->days .' day ';
                                        }
                                        $end_date[$key] = date('Y-m-d',strtotime($timetoadd,strtotime($tour_date)));
                                    }
                                }
                                $get_addons = $this->find(
                                    'addon_tickets',
                                    array(
                                        'select' => 'mec_id, addon_mec_id',
                                        'where' => 'mec_id in (' . implode(',', $sub_tkt_arr) . ')'
                                    ),
                                    'array'
                                );
                            }
                            $where = '';
                            if ($search_param != '') {
                                $where .= '(ticket_id LIKE "%' . $search_param . '%" OR title LIKE "%' . $search_param . '%" ) AND ';
                            }
                            $where .=  'reseller_id = ' . $reseller_id . ' AND ticket_id IN (' . implode(',', array_unique($affiliate_ticket_ids_array)) . ')' . 'AND related_product_id IN(' . implode(',', array_unique($entities_ids_array)) . ') GROUP BY ticket_id, selected_date ';
                            // $where .= ' LIMIT  ' . $offset . ', ' . $item_per_page . '';
                            $pre_query = " Select ticket_id as id , visitor_group_no , title , selected_date ,from_time , to_time from  prepaid_tickets where " . $where;
                            $logs['prepaid_query_' . date("Y-m-d H:i:s")] =  $pre_query;
                            $prepaid_ticket_details = $this->primarydb->db->query($pre_query)->result_array();
                            $logs['addons_tickets_' . date("Y-m-d H:i:s")] = $prepaid_ticket_details;
                            $is_reservation = "0";
                            foreach($get_addons as $addonKey => $addonticket){
                                foreach ($prepaid_ticket_details as $addons) {
                                    if(($addons['id'] == $addonticket['addon_mec_id']) && (strtotime($addons['selected_date']) >= strtotime($start_date[$addonticket['mec_id']]) ) &&(strtotime($addons['selected_date']) <= strtotime($end_date[$addonticket['mec_id']])) ){
                                        if ($addons['from_time'] == '0' && $addons['to_time'] == '0') {
                                            $is_reservation = "0";
                                        } else {
                                            $is_reservation = "1";
                                        }
                                        $addons_listing[$addons['id'].'_'.$addons['selected_date'].'_'.$addons['from_time'].'_'.$addons['to_time']] =  array(
                                            'id' => $addons['id'],
                                            'title' => $addons['title'],
                                            'selected_date' => $addons['selected_date'],
                                            'from_time' => $addons['from_time'],
                                            'to_time' => $addons['to_time'],
                                            'is_reservation' => $is_reservation
                                        );
                                        
                                    }
                                }
                            }
                            foreach($addons_listing as $addons){
                                $addons_array[] = $addons;
                            }

                            // The page to display (Usually is received in a url parameter)
                            $page = intval($req['page']) ? intval($req['page']) : 1 ;

                            // The number of records to display per page
                            $page_size = $req['item_per_page'] ? $req['item_per_page'] : 10;
                            // Calculate the position of the first record of the page to display
                            $offset = ($page - 1) * $page_size;

                            // Get the subset of records to be displayed from the array
                            $addons_array = array_slice($addons_array, $offset, $page_size);
                            $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                            return array(
                                "status" => 1,
                                "message" => "Listing fetched successfully",
                                "data" =>  !empty($addons_array) ? $addons_array : array()
                            );
                        } else {
                            return $this->exception_handler->error_400(0, 'INVALID_FLAGS', 'The Requested Flags does not exist.');
                        }
                    }
                } else {
                    $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                    return array(
                        "status" => 0,
                        "message" => "No ticket found"
                    );
                }
            } else {
                $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                return array(
                    "status" => 0,
                    "message" => " Invalid Request"
                );
            }
        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
            return $logs['exception'];
        }
    }
    
    
     /**
     * @Name get_affiliates_ticket_listing
     * @Purpose : to return affiliates ticket list  corresponding to affiliate 
     * @return status and data 
     *      status 1 or 0
     *      data having affiliates list
     * @param 
     *      $req - $req() 
     * @created_by Supriya saxena<supriya10.aipl@gmail.com
     */ 
    function get_affiliate_tickets_amount($req = array()){
        global $MPOS_LOGS;
        try {
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['affiliate_id'];
            $ticket_id = $req['ticket_id'];
            $start_date = $req['start_date'];
            $end_date = $req['end_date'];
            $start_time = $req['start_time'];
            $end_time = $req['end_time'];
            $is_reservation = $req['is_reservation'];
            $refunded_ids = array();
            if($affiliate_id != '' && $start_date != '' && $end_date != '' && $start_time != '' && $end_time != '' && $ticket_id !=  '' && $is_reservation != '') {
                // fetch total amount
                $startdatetime =  $start_date.' '.$start_time;
                $enddatetime  =  $end_date.' '.$end_time;
                if($is_reservation == 1) {
                    $date_check = ' CAST(CONCAT(selected_date, " ", from_time) as DATETIME) >= "'.$startdatetime.'" AND CAST(CONCAT(selected_date, " ", to_time)  as DATETIME) <= "'.$enddatetime.'"  ';
                } else if($is_reservation == 0) {
                    $date_check = ' CAST(concat(DATE(booking_selected_date), " ", from_time) as DATETIME) >= "'.$startdatetime.'" AND CAST(concat(DATE(booking_selected_date), " ", to_time) as DATETIME) <= "'.$enddatetime.'" ';
                }
                $where = ' partner_id = '.$affiliate_id. ' AND ticketId = "'.$ticket_id.'" AND '. $date_check  .' AND (row_type = "11" or row_type = "19" ) and (is_refunded = "0" or is_refunded= "2")';
                $vt_query_result = $secondarydb->query('Select partner_gross_price, ticketId, ticket_title, transaction_id, is_refunded, vt_group_no, selected_date, version from visitor_tickets where '. $where )->result_array();                       
                $logs['vt_query_'.date("Y-m-d H:i:s")] = $secondarydb->last_query();
                foreach($vt_query_result as $val) {
                    $vgn_array[] = $val['vt_group_no'];
                    if($val['is_refunded'] == '2'){
                        array_push($refunded_ids, $val['transaction_id']);
                    }
                }
                $affiliate_details = $this->find(  //fetch currency of affiliate
                    'qr_codes',
                    array(
                    'select' => 'currency_code',
                    'where' => 'cod_id  = '.$this->primarydb->db->escape($affiliate_id).''),
                    'array'
                );
                if(!empty($vgn_array)) {
                    $vt_data = $secondarydb->query(" Select  partner_gross_price, ticketId, ticket_title, transaction_id, is_refunded, vt_group_no, selected_date, version, from_time, to_time, booking_selected_date, row_type from visitor_tickets where vt_group_no IN (".implode(",",$vgn_array ).") AND (row_type = '11' or row_type = '19') and partner_id = '".$affiliate_id. "' AND ticketId = '".$ticket_id."' AND (is_refunded= '0' Or is_refunded = '2')")->result_array();
                    if(!empty($vt_data)) {
                        foreach($vt_data as $v_data) {
                            $vgn_details[$v_data['vt_group_no'].'_'.$v_data['transaction_id']][] = $v_data;
                        }
                        $versions= array();
                        foreach ($vgn_details as $db_key => $db_rows) {
                            foreach($db_rows as $db_row) {
                                if(!isset($versions[$db_key]) || (isset($versions[$db_key]) && $db_row['version'] > $versions[$db_key]['version'])){
                                    $versions[$db_key] = $db_row;
                                }
                            }
                            
                        }
                    }
                }
                if(!empty($versions)){
                    //get row type wise  price
                    foreach($versions as $data) {
                        $row_wise_data[$data['ticketId']][$data['row_type']] = $data;
                    }
                    // fetch paid_amount
                    $paid_where = ' partner_id = '.$affiliate_id. ' AND ticket_id = "'.$ticket_id.'" AND CAST(CONCAT(start_date, " ", from_time) as DATETIME) >= "'.$startdatetime.'" AND  CAST(CONCAT(end_date, " ", to_time)  as DATETIME) <= "'.$enddatetime.'" AND partner_type = "2"';
                    $order_payment_query_result = $secondarydb->query('Select order_amount, ticket_id, id, version, deleted from order_payment_details where '. $paid_where)->result_array();                       
                    $order_payment_result = $this->get_max_version_data($order_payment_query_result, 'id');
                    if(!empty($order_payment_result)) {
                        foreach($order_payment_result  as $details) {
                            if ($details['deleted'] == "0") {
                                $order_payment_arr[$details['ticket_id']]['paid_amount'] = $order_payment_arr[$details['ticket_id']]['paid_amount'] + $details['order_amount'];
                            }
                        }
                    }

                    $logs['order_payment_'.date("Y-m-d H:i:s")] = $secondarydb->last_query();
                    $total_affiliate_fee = 0;
                    $total_transaction_fee = 0;
                    $quantity = 0;
                    foreach($versions as $data) {
                        if(
                            ($is_reservation == 1 && strtotime($data['selected_date']." ".$data['from_time']) >=  strtotime($startdatetime)  &&  strtotime($data['selected_date']." ".$data['to_time']) <=  strtotime($enddatetime) ) 
                        || ($is_reservation == 0  && strtotime(date("Y-m-d", strtotime($data['booking_selected_date']))) >=  strtotime($start_date)  &&  strtotime(date("Y-m-d", strtotime($data['booking_selected_date']))) <=  strtotime($end_date) )
                        ) {
                            $unit_price = $row_wise_data[$data['ticketId']]['11']['partner_gross_price'];
                            if(!in_array($data['transaction_id'], $refunded_ids)) {
                                if($data['row_type'] == '11') {
                                    $total_affiliate_fee = $total_affiliate_fee +  $data['partner_gross_price'];
                                    $quantity = $quantity + 1;
                                }
                                if($data['row_type'] == '19') {
                                    $total_transaction_fee = $total_transaction_fee +  $data['partner_gross_price'];
                                }
                                $ticket_wise_amount[$data['ticketId']]['id'] =  $data['ticketId'];
                                $ticket_wise_amount[$data['ticketId']]['total_affiliate_fee'] = (float) $total_affiliate_fee;
                                $ticket_wise_amount[$data['ticketId']]['total_transaction_fee'] = (float) $total_transaction_fee;
                                $ticket_wise_amount[$data['ticketId']]['quantity'] =  (int)  $quantity;
                                $ticket_wise_amount[$data['ticketId']]['unit_price'] =  (float) $unit_price;
                                $ticket_wise_amount[$data['ticketId']]['title'] =  $data['ticket_title'];
                                $ticket_wise_amount[$data['ticketId']]['paid_amount'] = (float) isset($order_payment_arr[$data['ticketId']]['paid_amount']) ? $order_payment_arr[$data['ticketId']]['paid_amount'] : 0 ;
                                $ticket_wise_amount[$data['ticketId']]['currency'] = isset($affiliate_details[0]['currency_code']) ? $affiliate_details[0]['currency_code'] : '' ;   
                            } else {
                                $ticket_wise_amount[$data['ticketId']]['id'] =  $data['ticketId'];
                                $ticket_wise_amount[$data['ticketId']]['total_affiliate_fee'] = (float) isset($total_affiliate_fee) ? $total_affiliate_fee  : 0;
                                $ticket_wise_amount[$data['ticketId']]['total_transaction_fee'] = (float) isset($total_transaction_fee) ? $total_transaction_fee  : 0;
                                $ticket_wise_amount[$data['ticketId']]['quantity'] =  (int) isset($ticket_wise_amount[$data['ticketId']]['quantity']) ? $ticket_wise_amount[$data['ticketId']]['quantity'] : 0;
                                $ticket_wise_amount[$data['ticketId']]['title'] =  $data['ticket_title'];
                                $ticket_wise_amount[$data['ticketId']]['paid_amount'] = (float) isset($order_payment_arr[$data['ticketId']]['paid_amount']) ? $order_payment_arr[$data['ticketId']]['paid_amount'] : 0 ;
                                $ticket_wise_amount[$data['ticketId']]['currency'] = isset($affiliate_details[0]['currency_code']) ? $affiliate_details[0]['currency_code'] : '' ;   
                            }
                        }

                    }
                    $new_ticket_wise_amount = isset($ticket_wise_amount) && !empty($ticket_wise_amount) ? array_values($ticket_wise_amount) : array();
                    $logs['new_ticket_wise_amount_'.date("Y-m-d H:i:s")] = $new_ticket_wise_amount;
                    $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                    if(!empty($new_ticket_wise_amount)) {
                        return array(
                            "status" => 1,
                            "message" => "Total amount to pay",
                            "data" =>  $new_ticket_wise_amount[0] 
                        );
                    } else {
                        return array(
                            "status" => 0,
                            "message" => "No amount to pay",
                            "data" =>  (object) array("id" => 0, "total_amount" => (float) 0, "quantity" => (int) 0 , "title" => "", "paid_amount" => (float) 0, "currency" => "")
                        );
                    }

                } else {
                    $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                    return array(
                        "status" => 0,
                        "message" => "No amount to pay",
                        "data" =>  (object) array("id" => 0, "total_amount" => (float) 0, "quantity" => (int) 0 , "title" => "", "paid_amount" => (float) 0, "currency" => "")
                    );
                }
            } else {
                $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                return array(
                    "status" => 0,
                    "message" => " Invalid Request"
                );
            }

        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
            return $logs['exception'];

        }
    }  
        /**
     * @Name get_affiliates_ticket_listing
     * @Purpose : to return affiliates ticket list  corresponding to affiliate 
     * @return status and data 
     *      status 1 or 0
     *      data having affiliates list
     * @param 
     *      $req - $req() 
     * @created_by Supriya saxena<supriya10.aipl@gmail.com
     */ 
    function get_affiliate_pay_amount($req = array()){
        global $MPOS_LOGS;
        try {
            $logs['request_'.date("Y-m-d H:i:s")] =  $req;
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['affiliate_id'];
            $ticket_id = $req['ticket_id'];
            $start_date = $req['start_date'];
            $end_date = $req['end_date'];
            $start_time = $req['start_time'];
            $end_time = $req['end_time'];
            $ticket_amount = $req['ticket_amount'];
            $reason = $req['notes'];
            $payment_type = $req['payment_type'];
            
            if($affiliate_id != '' && $ticket_id != '' && $start_date != '' && $end_date != '' && $start_time != '' && $end_time != '') {
                //create  array for order payment details
                $order_payment_detail_array = array();
                $order_payment_detail_array['id'] = $this->generate_uuid() ;
                $order_payment_detail_array['order_amount'] = $ticket_amount > 0 ? $ticket_amount  : 0 ;
                $order_payment_detail_array['order_total'] = $ticket_amount > 0 ? $ticket_amount  : 0 ;
                $order_payment_detail_array['partner_id'] = $affiliate_id;
                $order_payment_detail_array['partner_type'] = "2";
                $order_payment_detail_array['ticket_id'] = $ticket_id;
                $order_payment_detail_array['start_date'] = $start_date;
                $order_payment_detail_array['end_date'] = $end_date;
                $order_payment_detail_array['from_time'] = $start_time;
                $order_payment_detail_array['to_time'] = $end_time;
                $order_payment_detail_array['notes'] = $reason;
                $order_payment_detail_array['method'] =  $payment_type;
                $order_payment_detail_array['psp_type'] = "15";
                $order_payment_detail_array['status'] = "1";
                $order_payment_detail_array['is_active'] = "1";
                $order_payment_detail_array['shift_id'] = $req['shift_id'];
                $order_payment_detail_array['reseller_id'] = $req['reseller_id'];
                $order_payment_detail_array['distributor_id'] = $req['distributor_id'];
                $order_payment_detail_array['cashier_id'] = $req['users_details']['dist_cashier_id'];
                $order_payment_detail_array['cashier_name'] = $req['users_details']['cashier_name'];
                $order_payment_detail_array['cashier_email'] = $req['cashier_email'];
                $order_payment_detail_array['updated_at'] =  date("Y-m-d H:i:s");
                $order_payment_detail_array['created_at'] =  date("Y-m-d H:i:s");

                $last_inserted_id = $secondarydb->insert('order_payment_details', $order_payment_detail_array);
                $logs['order_payment_query_'.date("Y-m-d H:i:s")] = $secondarydb->last_query();
                if(!empty($last_inserted_id)) {
                    $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
                    return array(
                        "status" => 1,
                        "message" => "Payment done successfully"
                    );
                } else {
                    $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
                    return array(
                        "status" => 0,
                        "message" => "Something went wrong..!"
                    );
                }
                

            } else {
                $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
                return array(
                    "status" => 0,
                    "message" => " Invalid Request"
                );
            }

        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
            return $logs['exception'];

        }
    }  

    function get_affiliate_payment_history($req = array()) {
        global $MPOS_LOGS;
        try {
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['affiliate_id'];
            $ticket_id = $req['ticket_id'];
            $start_date = $req['start_date'];
            $end_date = $req['end_date'];
            $startdate = strtotime($start_date);
            for($i =  $startdate ;$i <=  strtotime($end_date) ; $i = $i + 86400) {
                $all_dates[] = date("Y-m-d", $i);
            }
           
            if($affiliate_id != '' && $ticket_id != '' && $start_date != '' && $end_date != '') {
                //create  array for order payment details
                $paid_where = ' partner_id = '.$affiliate_id. ' AND ticket_id = "'.$ticket_id.'" AND date(start_date) In("'.implode('","',$all_dates ).'")  AND  date(end_date) In("'.implode('","',$all_dates ).'")  AND partner_type = "2"';
                $order_payment_query_result = $secondarydb->query('Select order_amount as amount_settled, ticket_id, start_date, end_date, created_at, id, deleted, version from order_payment_details where '. $paid_where. ' order by created_at ASC')->result_array();  

                $logs['order_payment_query_'.date("Y-m-d H:i:s")] = $secondarydb->last_query();
                $order_payment_result = $this->get_max_version_data($order_payment_query_result, 'id');
                foreach($order_payment_result as $data) {
                    if ($data['deleted'] == "0") {
                        $key = $data['start_date'].'_'.$data['end_date'];
                        $new_payment_histor_array[$key]['amount_settled'] = (float) $new_payment_histor_array[$key]['amount_settled'] + $data['amount_settled'];
                        $new_payment_histor_array[$key]['ticket_id']      =  $data['ticket_id'];
                        $new_payment_histor_array[$key]['start_date']     =  $data['start_date'];
                        $new_payment_histor_array[$key]['end_date']       =  $data['end_date'];
                        $new_payment_histor_array[$key]['created_at']     =  $data['created_at'];
                    }
                }
                if(!empty($new_payment_histor_array)) {
                    $MPOS_LOGS['get_affiliate_payment_history'] = $logs;
                    return array(
                        "status" => 1,                                           
                        "message" => "Payment fetched successfully", 
                        "data" => array_values($new_payment_histor_array)
                    );
                } else {
                    $MPOS_LOGS['get_affiliate_payment_history'] = $logs;
                    return array(
                        "status" => 0,
                        "message" => "No payment done..!"
                    );
                }                

            } else {
                $MPOS_LOGS['get_affiliate_payment_history'] = $logs;
                return array(
                    "status" => 0,
                    "message" => " Invalid Request"
                );
            }

        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
            return $logs['exception'];

        }

    }
     /* #region Contiki tours Module : Cover all the functions used in contiki tours */

}
  
?>