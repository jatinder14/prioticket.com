<?php

/**
 * @Name     :  *  -- -- Contiki Tour and trips Controller -- -- 
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 5 April 2023
 */
namespace Prio\Traits\V2\Models;
use \Prio\helpers\V1\common_error_specification_helper;
Trait TTour_model
{
    #region Function to set variable under constuction 
    function __construct()
    {
        parent::__construct();
        $this->max_allowed_trips = 15;
        $this->product_type = array(
            'ticket' => 5,
            'timeslot' => 8
        );
        $this->load->model('V1/common_model');
    }
    #endregion Function to set variable under constuction

    // Function to get overview of trip
    function trip_overview($request)
    {
        global $MPOS_LOGS;
        $tickets_order_in_DB = $tickets_orders_in_DB = $capacities = $slot_flags = $timeslot_flags = $flag_entities = array();
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        $supplier_id = $request['product_supplier_id'];
        $shift_id = $request['shift_id'];
        $request['current_time'] = $this->get_time_wrt_timezone($request['timezone'], $request['current_time'] / 1000);
        $flag_entities = $this->get_flag_entities($shift_id, "9");
        $logs_from_get_trip_overview['shift_flag_queries' . date("H:i:s")] = $this->primarydb->db->last_query();
        if (!empty($flag_entities)) {
            $flag_ids = array_keys($flag_entities);
            $order_flags_query = 'select order_id, flag_id from order_flags'
                . ' where flag_id in ("' . implode('","', $this->primarydb->db->escape($flag_ids)) . '") and flag_type = "2"';
            $order_flags = $secondarydb->query($order_flags_query)->result_array();
            $logs_from_get_trip_overview['order_flags_query_' . date("H:i:s")] = $order_flags_query;
            $logs_from_get_trip_overview['order_flags_data' . date("H:i:s")] = $order_flags;
            foreach ($order_flags as $flag_entry) {
                $vgn_based_flags[$flag_entry['order_id']][] = $flag_entry['flag_id'];
            }
            foreach ($vgn_based_flags as $order_id => $flags) {
                $array_difference = array_diff($flag_ids, $flags);
                if (empty($array_difference)) {
                    $vgns[] = $order_id;
                }
            }
            $vgns = array_unique($vgns);
            if (!empty($vgns)) {
                $request['product_id'] = explode(',', $request['product_id']);
                $locations = $this->get_locations_of_tickets($request['product_id']);
                $query = 'select version, prepaid_ticket_id, ticket_id, is_prepaid, timeslot, selected_date, shared_capacity_id, from_time, to_time, title, tp_payment_method, visitor_group_no, reserved_1, is_cancelled, deleted, is_addon_ticket, is_refunded , extra_booking_information, order_currency_code from prepaid_tickets '
                    . ' where reseller_id = "' . $request['reseller_id'] . '" and visitor_group_no in ("' . implode('","', $this->primarydb->db->escape($vgns)) . '")'
                    .' order by visitor_group_no asc ';
                $tickets_order_in_DB = $secondarydb->query($query)->result_array();
                $logs_from_get_trip_overview['pt_db2_query_' . date("H:i:s")] = $query;
                $tickets_order_in_DB = $this->get_max_version_data($tickets_order_in_DB, 'prepaid_ticket_id');
                $logs_from_get_trip_overview['max_data_from_ptt'] = count($tickets_order_in_DB);
                foreach ($tickets_order_in_DB as $orders) {
                    if ($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0" && $orders['is_addon_ticket'] == "0" && $orders['is_prepaid'] == "1") {
                        $tickets_orders_in_DB[] = $orders;
                    }
                }
                if (!empty($tickets_orders_in_DB)) {
                    $trips = $upcoming_trip_keys = $past_trip_keys = $current_trip_keys = $new_trips = array();
                    $tickets_mec_data = $this->find(
                        'modeventcontent',
                        array(
                            'select' => 'mec_id, shared_capacity_id, own_capacity_id, duration',
                            'where' => 'mec_id in ("' . implode('","', $request['product_id']) . '")'
                        ),
                        'array'
                    );
                    foreach ($tickets_mec_data as $ticket) {
                        $ticket_durations[$ticket['mec_id']] = $ticket['duration'];
                        $capacities[$ticket['mec_id']][] = $ticket['shared_capacity_id'];
                        if ($ticket['own_capacity_id'] !== '' && $ticket['own_capacity_id'] !== '0') {
                            $capacities[$ticket['mec_id']][] = $ticket['own_capacity_id'];
                        }
                    }
                    foreach ($request['product_id'] as $ticket_id) {
                        $flags[$ticket_id] = $this->flags_of_a_entity($ticket_id, $this->product_type['ticket'], $request);
                    }
                    foreach ($capacities as $capId) {
                        foreach ($capId as $capacityId) {
                            $slot_flags = $this->flags_of_a_entity($capacityId, $this->product_type['timeslot'], $request);
                            foreach ($slot_flags as $flgData) {
                                $timeslot_flags[$flgData['entity_id']][] = $flgData;
                            }
                        }
                    }
                    $logs_from_get_trip_overview['tickets_query_' . date("H:i:s")] = $this->primarydb->db->last_query();

                    //BOC to send  sub locations in response
                    foreach ($tickets_orders_in_DB as $ticket_order) { //get latest vgn wrt selected date, from time , to time and ticket id
                        $ticket_order_arr[$ticket_order['selected_date'] . '_' . $ticket_order['from_time'] . '_' . $ticket_order['to_time'] . '_' . $ticket_order['ticket_id']] = $ticket_order;
                    }

                    foreach ($ticket_order_arr as $sub_arr) { // preparing array to fetch subticket of latest vgn wrt selected date, from time , to time and ticket id
                        $vgn_arr[] = $sub_arr['visitor_group_no'];
                        $date_vgn[$sub_arr['visitor_group_no']] = $sub_arr['selected_date'] . '_' . $sub_arr['from_time'] . '_' . $sub_arr['to_time'] . '_' . $sub_arr['ticket_id'];
                    }
                    $sub_vgn_query = 'select ticket_id, selected_date, is_addon_ticket, is_prepaid, from_time, to_time, title, museum_id, museum_name, timeslot, visitor_group_no from prepaid_tickets '
                        . ' where visitor_group_no in ("' . implode('","', $vgn_arr) . '")'
                        . ' and deleted = "0" and is_refunded = "0" ';

                    $sub_tickets_orders_in_DB = $this->primarydb->db->query($sub_vgn_query)->result_array();
                    foreach($sub_tickets_orders_in_DB as $sub_tkt_order){
                        if($sub_tkt_order['is_addon_ticket'] == "2" && $sub_tkt_order['is_prepaid'] == "1"){
                            $new_sub_ticket_arr[] = $sub_tkt_order;
                        }
                    }
                    $logs_from_get_trip_overview['sub_ticket_vgn_query_' . date("H:i:s")] = $this->primarydb->db->last_query();
                    $sub_tickets_ids  = array_unique(array_column($new_sub_ticket_arr, 'ticket_id')); // fetching all sub ticket ids
                    if (!empty($sub_tickets_ids)) {
                        $sub_ticket_durations = $this->find(
                            'modeventcontent',
                            array(
                                'select' => 'mec_id, duration, cod_id',
                                'where' => 'mec_id in ("' . implode('","', $sub_tickets_ids) . '")'
                            ),
                            'list'
                        );
                    }
                    $logs_from_get_trip_overview['sub_tickets_query_' . date("H:i:s")] = $this->primarydb->db->last_query();
                    foreach ($tickets_orders_in_DB as $db_orders) {
                        foreach ($new_sub_ticket_arr as $key => $sub_vgn_query_arr) { // prepareing sublocation array
                            $sub_from_time = $sub_vgn_query_arr['from_time'] == "0" ? "00:00" : $sub_vgn_query_arr['from_time'];
                            $sub_to_time = $sub_vgn_query_arr['to_time'] == "0" ? "00:00" : $sub_vgn_query_arr['to_time'];
                            $new_sub_tickets_ids[$key]['product_parent_id'] = (string) $db_orders['ticket_id'];
                            $new_sub_tickets_ids[$key]['product_id']         = (string) $sub_vgn_query_arr['ticket_id'];
                            $new_sub_tickets_ids[$key]['product_title']      = $sub_vgn_query_arr['title'];
                            $new_sub_tickets_ids[$key]['product_supplier_id']         =  $sub_vgn_query_arr['museum_id'];
                            $new_sub_tickets_ids[$key]['product_supplier_name']         = $sub_vgn_query_arr['museum_name'];
                            $new_sub_tickets_ids[$key]['product_admission_type']         = $this->get_admission_type($sub_vgn_query_arr['timeslot']);
                            $new_sub_tickets_ids[$key]['product_availability_from_date_time']        = $this->create_date_time($sub_vgn_query_arr['selected_date'], $sub_from_time);
                            $new_sub_tickets_ids[$key]['product_availability_to_date_time']          = ($sub_ticket_durations[$sub_vgn_query_arr['ticket_id']] != "") ? $this->get_duration_from_start_time($new_sub_tickets_ids[$key]['product_availability_from_date_time'], $sub_ticket_durations[$sub_vgn_query_arr['ticket_id']]) : $this->create_date_time($sub_vgn_query_arr['selected_date'], $sub_to_time);
                            $new_sub_tickets_ids[$key]['visitor_group_no']  = $sub_vgn_query_arr['visitor_group_no'];
                        }
                    }
                    foreach ($new_sub_tickets_ids as $sub_vgn_query_array) { //preparing subarray wrt vgn
                        $visitor_group_no = $sub_vgn_query_array['visitor_group_no'];
                        unset($sub_vgn_query_array['visitor_group_no']);
                        $final_sub_vgn_query_arr[$visitor_group_no][$sub_vgn_query_array['product_id']] = $sub_vgn_query_array;
                    }
                    foreach ($final_sub_vgn_query_arr as $vgn => $sub_locations) { //prepareing array wrt to main ticket selected date, from time, to time, ticket id
                        $final_new_sub_location[$date_vgn[$vgn]] = $sub_locations;
                    }

                    //EOC to send  sub locations in response
                    $contact_uids = array_values(array_unique(array_column($tickets_orders_in_DB, 'reserved_1')));
                    $contacts = $this->get_contacts($contact_uids);
                    $guests_count = $addon_orders_in_DB = array();
                    if ($request['reseller_id'] != "") {
                        $addon_orders_in_DB = $this->get_addons_of_tickets_from_reseller($request['product_id'], $request['reseller_id']);
                    }
                    foreach ($tickets_orders_in_DB as $db_orders) {
                        if ($db_orders['reserved_1'] != "" && $db_orders['reserved_1'] != null && $db_orders['reserved_1'] != '0') {
                            $info = $this->get_contact_info($db_orders, $contacts, 0);
                            $ticket_key = $db_orders['selected_date'] . "_" . $db_orders['from_time'] . "_" . $db_orders['to_time'] . "_" . $db_orders['ticket_id'];
                            $slott_id = str_replace("-", "", $db_orders['selected_date']) . str_replace(":", "", $db_orders['from_time']) . str_replace(":", "", $db_orders['to_time']) . $db_orders['shared_capacity_id'];
                            $tickets_data[$ticket_key]['product_id'] = (string) $db_orders['ticket_id'];
                            $tickets_data[$ticket_key]['product_title'] = $db_orders['title'];
                            $tickets_data[$ticket_key]['product_supplier_id'] = (string) $supplier_id;
                            $tickets_data[$ticket_key]['product_admission_type'] = $this->get_admission_type($db_orders['timeslot']);
                            $tickets_data[$ticket_key]['departure'] = (string) $locations[$db_orders['ticket_id']]['start_location'];
                            $tickets_data[$ticket_key]['destination'] = (string) $locations[$db_orders['ticket_id']]['end_location'];
                            $tickets_data[$ticket_key]['order_currency_code'] = $db_orders['order_currency_code'];
                            $tickets_data[$ticket_key]['product_availability_from_date_time'] = $this->create_date_time($db_orders['selected_date'], $db_orders['from_time']);
                            $tickets_data[$ticket_key]['product_availability_to_date_time'] = ($ticket_durations[$db_orders['ticket_id']] != "") ? $this->get_duration_from_start_time($tickets_data[$ticket_key]['product_availability_from_date_time'], $ticket_durations[$db_orders['ticket_id']]) : $this->create_date_time($db_orders['selected_date'], $db_orders['to_time']);
                            $tickets_data[$ticket_key]['notes']['note_value'] = "Read everything about your trip";
                            $tickets_data[$ticket_key]['notes']['total_notes'] = isset($tickets_data[$ticket_key]['notes']['total_notes']) ? $tickets_data[$ticket_key]['notes']['total_notes'] + sizeof($this->notes($db_orders, $contacts)) : sizeof($this->notes($db_orders, $contacts));
                            $tickets_data[$ticket_key]['product_addons']['total_addons'] = isset($tickets_data[$ticket_key]['product_addons']['total_addons']) ? ($tickets_data[$ticket_key]['product_addons']['total_addons'] + $this->total_addons($db_orders, $contacts)) : $this->total_addons($db_orders, $contacts);
                            $purchased_addons = isset($tickets_data[$ticket_key]['product_addons']['purchased_addons']) ? $tickets_data[$ticket_key]['product_addons']['purchased_addons'] : 0;
                            $tickets_data[$ticket_key]['product_addons']['purchased_addons'] = isset($addon_orders_in_DB[$db_orders['visitor_group_no']]) ?  $purchased_addons + $addon_orders_in_DB[$db_orders['visitor_group_no']]['count'] : $purchased_addons;
                            if (!isset($guests_count[$ticket_key][$info['contact_uid']]) || $guests_count[$ticket_key][$info['contact_uid']] == null) {
                                $tickets_data[$ticket_key]['guest_details']['total_guests'] = ($tickets_data[$ticket_key]['guest_details']['total_guests'] > 0) ? $tickets_data[$ticket_key]['guest_details']['total_guests'] + 1 : 1;
                                $guests_count[$ticket_key][$info['contact_uid']] = 1;
                                if ($db_orders['tp_payment_method'] == 0) {
                                    $tickets_data[$ticket_key]['guest_details']['pending_guests'] = ($tickets_data[$ticket_key]['guest_details']['pending_guests'] > 0) ? $tickets_data[$ticket_key]['guest_details']['pending_guests'] + 1 : 1;
                                } else if (isset($addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest']) && $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'] > 0) {
                                    $tickets_data[$ticket_key]['guest_details']['pending_guests'] = ($tickets_data[$ticket_key]['guest_details']['pending_guests'] > 0) ? $tickets_data[$ticket_key]['guest_details']['pending_guests'] + $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'] : $addon_orders_in_DB[$db_orders['visitor_group_no']]['pending_guest'];
                                } else {
                                    $tickets_data[$ticket_key]['guest_details']['pending_guests'] = ($tickets_data[$ticket_key]['guest_details']['pending_guests'] > 0) ? $tickets_data[$ticket_key]['guest_details']['pending_guests'] : 0;
                                }
                            }
                            if (isset($flags[$db_orders['ticket_id']]) && isset($timeslot_flags[$slott_id])) {
                                $tickets_data[$ticket_key]['flags'] = array_merge($timeslot_flags[$slott_id], $flags[$db_orders['ticket_id']]);
                            } else if (isset($flags[$db_orders['ticket_id']])) {
                                $tickets_data[$ticket_key]['flags'] = $flags[$db_orders['ticket_id']];
                            } else if (isset($timeslot_flags[$slott_id])) {
                                $tickets_data[$ticket_key]['flags'] = $timeslot_flags[$slott_id];
                            }
                            if (!empty($final_new_sub_location[$ticket_key])) {
                                $tickets_data[$ticket_key]['product_combi_details'] = array_values($final_new_sub_location[$ticket_key]);
                            }
                            if (strtotime($tickets_data[$ticket_key]['product_availability_from_date_time']) > $request['current_time']) {
                                $upcoming_trip_keys[] = $ticket_key;
                            } elseif (strtotime($tickets_data[$ticket_key]['product_availability_to_date_time']) < $request['current_time']) {
                                $past_trip_keys[] = $ticket_key;
                            } elseif (strtotime($tickets_data[$ticket_key]['product_availability_to_date_time']) > $request['current_time'] && strtotime($tickets_data[$ticket_key]['product_availability_from_date_time']) < $request['current_time']) {
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
                    if (!empty($trips['past_trips'])) {
                        ksort($trips['past_trips']);
                        $new_trips['past_trips'] = array_values($trips['past_trips']);
                    }
                    if (!empty($trips['upcoming_trips'])) {
                        ksort($trips['upcoming_trips']);
                        $new_trips['upcoming_trips'] = array_values($trips['upcoming_trips']);
                    }
                    if (!empty($trips['current_trips'])) {
                        ksort($trips['current_trips']);
                        $new_trips['current_trips'] = array_values($trips['current_trips']);
                    }
                }
            }
        }
        $res['kind'] = "trips";
        $res['trips'] = !empty($new_trips) ? $new_trips : (object) array();
        $MPOS_LOGS['get_trip_overview'] = $logs_from_get_trip_overview;
        return $res;
    }

    /* Function to get details of the tour */
    function tour_details($req)
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $cancelled_statuses = array(3, 4, 6, 7, 10);
        $ticket_orders_in_DB = $total_orders_in_DB = $total_not_checked_in_guests = $flags = $flagsData = array();
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        $supplier_id = $req['product_supplier_id'];
        $ticket_id = $req['product_id'];
        $start_time = $req['availability_from_date_time'];
        $to_datetime = $req['availability_to_date_time'];
        $req['entity_ids'] = explode(",", $req['entity_ids']);
        $entity_ids = isset($req['entity_ids']) ? $req['entity_ids'] : array();
        $reseller_id = isset($req['reseller_id']) ? $req['reseller_id'] : 0;
        $date_time = explode("T", $start_time);
        $to_date_time = explode("T", $to_datetime);
        $time = explode(" ", $date_time[1]);
        $to_time = explode(" ", $to_date_time[1]);
        $logs_from_get_tour_details['req_' . $reseller_id . '_' . date("H:i:s")] = $req;
        //BOC - subtickets of main ticket -> to show further slides on APP
        $cluster_tickets_query = 'select cluster_ticket_id, cluster_ticket_title, ticket_museum_id, age_from, age_to from cluster_tickets_detail where main_ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and is_deleted = "0"';
        $cluster_tickets = $this->primarydb->db->query($cluster_tickets_query)->result_array();
        $logs_from_get_tour_details['cluster_tickets_query'] = $cluster_tickets_query;
        $internallogs['cluster_tickets_data'] = $cluster_tickets;

        foreach ($entity_ids as $entity_id) {
            $product_type = ($entity_id === $ticket_id) ? $this->product_type['ticket'] : $this->product_type['timeslot'];
            $flags[$entity_id] = $this->flags_of_a_entity($entity_id, $product_type, $req);
        }

        if (!empty($cluster_tickets)) {
            $sub_ticket_ids = array_unique(array_column($cluster_tickets, 'cluster_ticket_id'));
            $locations = $this->get_locations_of_tickets($ticket_id);
            $tickets_detail = $this->find(
                'modeventcontent',
                array(
                    'select' => 'mec_id, startDate, startTime, endDate, endTime, postingEventTitle',
                    'where' => 'mec_id in (' . implode(',', $sub_ticket_ids) . ',' . $this->primarydb->db->escape($ticket_id) . ')'
                ),
                'array'
            );
            $logs_from_get_tour_details['tickets_query_' . date("H:i:s")] = $this->primarydb->db->last_query();
            $internallogs['ticket_data'] = $tickets_detail;
            foreach ($tickets_detail as $ticket_detail) {
                $timings[$ticket_detail['mec_id']]['start_time'] = date("Y-m-d", $ticket_detail['startDate']) . " " . $ticket_detail['startTime'];
                $timings[$ticket_detail['mec_id']]['end_time'] = date("Y-m-d", $ticket_detail['endDate']) . " " . $ticket_detail['endTime'];
                $ticket_titles[$ticket_detail['mec_id']] = $ticket_detail['postingEventTitle'];
            }
        } else {
            $ticket_titles = $this->find(
                'modeventcontent',
                array(
                    'select' => 'mec_id, postingEventTitle',
                    'where' => 'mec_id = ' . $this->primarydb->db->escape($ticket_id) . ''
                ),
                'list'
            );
            $logs_from_get_tour_details['tickets_query_' . date("H:i:s")] = $this->primarydb->db->last_query();
        }

        //BOC - Guests_listing corresponding to each ticket -> to show in listing each screen on APP
        $total_amount = $total_owing_amount = $total_paid_amount = array();
        $order_currency = '';
        $logs_from_get_tour_details['pt_query_starts_at'] = date("H:i:s");
        $internallogs['modeventcontent_data'] = $ticket_titles;
        $reseller_check = ($reseller_id > 0) ? 'reseller_id = "' . $reseller_id . '" and ' : '';
        $query = 'select visitor_group_no, version, ticket_id, ticket_booking_id, activated , activation_method, currency_rate, order_currency_price, prepaid_ticket_id, is_addon_ticket, title, reserved_1, secondary_guest_email, secondary_guest_name, price, passport_number, extra_booking_information, guest_names, guest_emails, tp_payment_method, is_refunded, is_cancelled, deleted, order_currency_code from prepaid_tickets '
            . ' where ' . $reseller_check . ' ((museum_id = ' . $secondarydb->escape($supplier_id) . ' and ticket_id = ' . $secondarydb->escape($ticket_id) . ' and selected_date = ' . $secondarydb->escape($date_time[0]) . ' and from_time = ' . $secondarydb->escape($time[1]) . ') ' //for main ticket
            . ' or '
            . ' (related_product_id = ' . $secondarydb->escape($ticket_id) . '  and is_addon_ticket = "1")) and ' //for addon tickets
            . ' is_prepaid = "1"';
        $total_orders_in_DB = $secondarydb->query($query)->result_array();
        $logs_from_get_tour_details['pt_query_db2' . date("H:i:s")] = $secondarydb->last_query();
        $internallogs['pt_query_db2_data'] = $total_orders_in_DB;
        $total_orders_in_DB = $this->get_max_version_data($total_orders_in_DB, 'prepaid_ticket_id');
        $logs_from_get_tour_details['max_data_from_pt_' . date("H:i:s")] = count($total_orders_in_DB);
        foreach ($total_orders_in_DB as $orders) {
            if ($orders['is_refunded'] == "1" && $orders['is_addon_ticket'] == "1" && $orders['activated'] == "1" && $orders['deleted'] == "0") {
                array_push($ticket_orders_in_DB, $orders);
            }
            if ($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0" && $orders['activated'] == "1") {
                $ticket_orders_in_DB[] = $orders;
            }
        }
        $logs_from_get_tour_details['pt__db2__query_' . date("H:i:s")] = $query;
        $contact_uids = array_values(array_unique(array_column($ticket_orders_in_DB, 'reserved_1')));
        $contacts = $this->get_contacts($contact_uids);
        $vgn_based_amounts = array();
        $ticket_booking_ids = array_values(array_unique(array_column($ticket_orders_in_DB, 'ticket_booking_id')));
        $opd_query_result = $secondarydb->query('Select is_active, refund_type , status, amount, total, id, version, payment_category ,visitor_group_no, ticket_booking_id, deleted from order_payment_details where ticket_booking_id in (' . implode(',', $this->primarydb->db->escape($ticket_booking_ids)) . ') and is_active = "1"')->result_array();
        $logs_from_get_tour_details['opd_query_db2' . date("H:i:s")] = $secondarydb->last_query();
        $internallogs['opd_query_db2_data'] = $opd_query_result;
        foreach ($opd_query_result as $opd_row) {
            $vgn_based_amounts[$opd_row['visitor_group_no']]['paid_amount'] += ($opd_row['status'] == 1) ? $opd_row['amount'] : 0;
            $vgn_based_amounts[$opd_row['visitor_group_no']]['total_amount'] += ($opd_row['status'] == 2) ? $opd_row['amount'] : 0;
            if (in_array($opd_row['status'], $cancelled_statuses)) {
                $vgn_based_amounts[$opd_row['visitor_group_no']]['total_amount'] -= $opd_row['amount'];
                if ($opd_row['status'] == 3 || $opd_row['status'] == 4) {
                    $vgn_based_amounts[$opd_row['visitor_group_no']]['paid_amount'] -= $opd_row['amount'];
                }
            }
        }
        foreach ($vgn_based_amounts as $vgn => $amounts) {
            $vgn_based_amounts[$vgn]['owing_amount'] = $amounts['total_amount'] - $amounts['paid_amount'];
        }
        foreach ($ticket_orders_in_DB as $pt_order) {
            if ($pt_order['is_addon_ticket'] == '0' && isset($contacts[$pt_order['reserved_1']])) {
                $info = $this->get_contact_info($pt_order, $contacts, 0);
                $main_guests[] = $info['contact_uid'];
            }
        }
        $main_guests = array_unique($main_guests);
        $logs_from_get_tour_details['main_guests_' . date("H:i:s")] = $main_guests;
        $passed_vgns = array();
        foreach ($ticket_orders_in_DB as $order) {
            $info = $this->get_contact_info($order, $contacts, 0);
            if (in_array($info['contact_uid'], $main_guests)) {
                $guests[$info['contact_uid']]['contact_name_first'] = $info['first_name'];
                $guests[$info['contact_uid']]['contact_name_last'] = $info['last_name'];
                $guests[$info['contact_uid']]['contact_email'] = $info['email'];
                $guests[$info['contact_uid']]['contact_uid'] = ($order['reserved_1'] != '' && $order['reserved_1'] != null && $order['reserved_1'] != '0') ? $order['reserved_1'] : "";
                $guests[$info['contact_uid']]['passport_no'] = $info['passport'];
                $guests[$info['contact_uid']]['paid'] = ($order['is_addon_ticket'] == '0' && $order['tp_payment_method'] != '0') ?  true : false;
                $guests[$info['contact_uid']]['notes']['total_notes'] = sizeof($this->notes($order, $contacts));
                $guests[$info['contact_uid']]['notes']['note_value'] = $this->notes($order, $contacts);
                $addons = ($guests[$info['contact_uid']]['product_addons']['total_addons']) ? $guests[$info['contact_uid']]['product_addons']['total_addons'] : 0;
                $new_addons = $this->total_addons($order);
                $guests[$info['contact_uid']]['product_addons']['total_addons'] = ($order['is_addon_ticket'] == '0' && $new_addons >  0) ? $new_addons : $addons;
                $purchased_addons = isset($guests[$info['contact_uid']]['product_addons']['purchased_addons']) ? $guests[$info['contact_uid']]['product_addons']['purchased_addons'] : 0;
                $guests[$info['contact_uid']]['product_addons']['purchased_addons'] = ($order['is_addon_ticket'] == '1') ? $purchased_addons + 1 : $purchased_addons;
                // $guests[$info['contact_uid']]['total_amount'] += (float) $order['price'];
                if ($order['tp_payment_method'] == 0) {
                    if (!in_array($info['contact_uid'], $total_not_checked_in_guests)) {
                        $total_not_checked_in_guests[] = $info['contact_uid'];
                    }
                }
                if ((empty($passed_vgns) || (!empty($passed_vgns) && !in_array($order['visitor_group_no'], $passed_vgns)))) {
                    $passed_vgns[] = $order['visitor_group_no'];
                    // $guests[$info['contact_uid']]['order_payments']['payment_method'] = $order['activation_method'];
                    $guests[$info['contact_uid']]['order_payments']['payment_currency_rate'] = $order['currency_rate'];
                    // $guests[$info['contact_uid']]['order_payments']['payment_currency_amount'] = (int) $order['order_currency_price'];
                    $guests[$info['contact_uid']]['order_payments']['total_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['total_amount']) ? $vgn_based_amounts[$order['visitor_group_no']]['total_amount'] :  0;
                    $guests[$info['contact_uid']]['order_payments']['paid_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['paid_amount']) ?  $vgn_based_amounts[$order['visitor_group_no']]['paid_amount'] :  0;
                    $guests[$info['contact_uid']]['order_payments']['owing_amount'] += isset($vgn_based_amounts[$order['visitor_group_no']]['owing_amount']) ?  $vgn_based_amounts[$order['visitor_group_no']]['owing_amount'] :  0;
                    $total_paid_amount[] = $vgn_based_amounts[$order['visitor_group_no']]['paid_amount'];
                    $total_owing_amount[] = $vgn_based_amounts[$order['visitor_group_no']]['owing_amount'];
                    $total_amount[] = $vgn_based_amounts[$order['visitor_group_no']]['total_amount'];
                }
            }
            $order_currency = $order['order_currency_code'];
        }
        $logs_from_get_tour_details['entity_ids_' . date("H:i:s")] = count($entity_ids);
        if (!empty($flags)) {
            foreach ($entity_ids as $entity_id) {
                foreach ($flags[$entity_id] as $val) {
                    $flagsData[] = $val;
                }
            }
        }
        $sub_tickets_data['departure'] = !empty($locations[$ticket_id]['start_location']) ? (string) $locations[$ticket_id]['start_location'] : "";
        $sub_tickets_data['destination'] = !empty($locations[$ticket_id]['end_location']) ? (string) $locations[$ticket_id]['end_location'] : "";
        $response['kind'] = "tour";
        $response['tour'] = array(
            'product_id' => $ticket_id,
            'product_title' => isset($ticket_titles[$ticket_id]) ? $ticket_titles[$ticket_id] : '',
            'departure' => $sub_tickets_data['departure'],
            'destination' =>  $sub_tickets_data['destination'],
            'product_availability_from_date_time' => $date_time[0] . 'T' . $time[0] . '+00:00',
            'product_availability_to_date_time' => $to_date_time[0] . 'T' . $to_time[0] . '+00:00',
            'order_currency_code' => $order_currency,
            'guest_details' => array(
                'total_guests' => isset($guests) ? sizeof($guests) : 0,
                'pending_guests' => !empty($total_not_checked_in_guests) ? sizeof($total_not_checked_in_guests) : 0,
            ),
            'flags' => !empty($flagsData) ? array_values($flagsData) : array(),
            'order_contacts' => isset($guests) ? array_values($guests) : array(),
            'paid_amount' => isset($total_paid_amount) ?  array_sum($total_paid_amount) :  0,
            'owing_amount' => isset($total_owing_amount) ? array_sum($total_owing_amount) :  0,
            'total_amount' => isset($total_amount) ?  array_sum($total_amount) :  0,
        );
        // $MPOS_LOGS['get_tour_details'] = $logs_from_get_tour_details;
        // $internal_logs['get_tour_details'] = $internallogs;
        /* set logs empty due to 500 error */
        $MPOS_LOGS['get_tour_details'] = '';
        $internal_logs['get_tour_details'] = '';
        return $response;
    }
    /* End Function to get details of the tour */

    // Function to get listing of tickets
    function tickets_listing($data = array())
    {
        global $MPOS_LOGS, $logs;
        try {
            $logs['cashier_type'] = $data['cashier_type'];
            $cod_id_query = '';
            $search = $data['product_admission_type'];
            $item_per_page = !empty($data['items_per_page']) ? $data['items_per_page'] : 10;
            $page = !empty($data['page']) ? $data['page'] : 1;
            $offset = ($page - 1) * $item_per_page;
            $search_param = $data['ticket_search_query'] ? $data['ticket_search_query'] : "";
            $hidden_tickets = $hidden_capacities = $suppliers = array();
            if ($data['cashier_type'] == '3' && $data['reseller_id'] != '') { //reseller user
                $suppliers = $this->common_model->get_supplier_of_reseller($data['reseller_id']);
                $cod_id_query = ' in (' . implode(",", $suppliers) . ')';
            } elseif ($data['cashier_type'] == '1' && $data['distributor_id'] != '') { //distributor user logged in
                $suppliers = $this->common_model->get_own_supplier_of_dist($data['distributor_id']);
                $cod_id_query = ' in (' . implode(",", $suppliers) . ')';
            } else if ($data['supplier_id'] != '') { //supplier user
                $cod_id_query = ' = "' . $data['supplier_id'] . '"';
            }

            if ($data['supplier_cashier'] != '') {
                $user_data =  $this->find('users', array('select' => 'hide_tickets, hide_capacities', 'where' => 'id = "' . $data['supplier_cashier'] . '" and deleted = "0"'), 'array');
                $logs['users_query'] = $this->primarydb->db->last_query();
                $logs['user_data'] = $user_data;
                $hidden_tickets = ($user_data[0]['hide_tickets'] != '' && $user_data[0]['hide_tickets'] != NULL) ? explode(",", $user_data[0]['hide_tickets']) : array();
                $hidden_capacities = ($user_data[0]['hide_capacities'] != '' && $user_data[0]['hide_capacities'] != NULL) ? explode(",", $user_data[0]['hide_capacities']) : array();
            }
            $hidden_tickets = array_map('trim', $hidden_tickets);
            $hidden_capacities = array_map('trim', $hidden_capacities);
            $logs['hidden_tickets and hidden_capacities'] = array('hidden_tickets' => $hidden_tickets, 'hidden_capacities' => $hidden_capacities);
            if (!empty($data['flag_ids'])) { //filter on flag basis
                $data['flag_ids'] = explode(",", $data['flag_ids']);
                $ticket_flags = $timeslot_flags = $timeslots = array();
                $timeslots_query = '';
                $entities = $this->find(
                    'flag_entities',
                    array(
                        'select' => '*',
                        'where' => 'item_id in (' . implode(",", $data['flag_ids']) . ') and entity_type in ("5", "8") and deleted = "0"'
                    ),
                    'array'
                );
                foreach ($entities as $entity) {
                    if ($entity['entity_type'] == "5" && !in_array($entity['entity_id'], $ticket_flags)) {
                        $ticket_flags[] = $main_ticket_id = $entity['entity_id'];
                    } else if ($entity['entity_type'] == "8" && !in_array($entity['entity_id'], $timeslot_flags)) {
                        $timeslot_flags[] = $entity['entity_id'];
                        // $timeslot['date'] = substr($entity['entity_id'], 0, 4) . "-" . substr($entity['entity_id'], 4, 2) . "-" . substr($entity['entity_id'], 6, 2);
                        $timeslot_data['product_availability_from_date_time'] = substr($entity['entity_id'], 0, 4) . "-" . substr($entity['entity_id'], 4, 2) . "-" . substr($entity['entity_id'], 6, 2) . "T" . substr($entity['entity_id'], 8, 2) . ":" . substr($entity['entity_id'], 10, 2) . "+00:00";
                        $timeslot_data['product_availability_to_date_time'] = substr($entity['entity_id'], 0, 4) . "-" . substr($entity['entity_id'], 4, 2) . "-" . substr($entity['entity_id'], 6, 2) . "T" . substr($entity['entity_id'], 12, 2) . ":" . substr($entity['entity_id'], 14, 2) . "+00:00";
                        $timeslot['shared_capacity_id'] = substr($entity['entity_id'], 16);
                        $timeslots[$timeslot['shared_capacity_id']] =  $timeslot_data;
                    }
                }
                $logs['ticket_flags and timeslots_flags'] = array('ticket_flags' => $ticket_flags, 'timeslots_flags' => $timeslots);
                $subtickets_n_addons = array();
                if (!empty($ticket_flags)) {
                    $sub_tickets = $this->find(
                        'cluster_tickets_detail',
                        array(
                            'select' => 'cluster_ticket_id, main_ticket_id',
                            'where' => 'main_ticket_id in (' . implode(",", $ticket_flags) . ') and is_deleted = "0"'
                        ),
                        'list'
                    );
                    if (!empty($sub_tickets)) {
                        $logs['sub_tickets from clusters'] = $sub_tickets;
                        $addons = $this->find(
                            'addon_tickets',
                            array(
                                'select' => 'addon_mec_id, mec_id',
                                'where' => 'mec_id in (' . implode(",", array_keys($sub_tickets)) . ') and is_deleted = "0"'
                            ),
                            'list'
                        );
                        if (!empty($addons)) {
                            $logs['addon_tickets'] = $addons;
                            $subtickets_n_addons = array_merge(array_keys($addons), array_keys($sub_tickets));
                            foreach ($addons as $addon => $addonParent) {
                                $sub_tickets[$addon] = $sub_tickets[$addonParent];
                            }
                        } else {
                            $subtickets_n_addons = array_keys($sub_tickets);
                        }
                    }
                }
                $logs['subtickets_n_addons and merged_sub_tickets'] = array('subtickets_n_addons' => $subtickets_n_addons, 'sub_tickets' => $sub_tickets);
                if (!empty($timeslots)) {
                    $timeslots_query = ' or shared_capacity_id in (' . implode(",", array_keys($timeslots)) . ')';
                }
                if ($main_ticket_id != "") {
                    $get_dependencies_query = "Select booking_start_time , booking_end_time from dependencies where main_ticket_id" . ' = ' . $main_ticket_id . ' ';
                    $get_dependencies = $this->primarydb->db->query($get_dependencies_query)->result_array();
                    if (!empty($get_dependencies)) {
                        $start_time = $get_dependencies[0]['booking_start_time'] ?? "00:00";
                        $start_time = json_decode($start_time);
                        foreach ($start_time as $values) {
                            foreach ($values as $key => $val) {
                                $sub_tkt_arr[] = $key;
                                $hours = $val->days > 0 ? $val->days * 24 : '00';
                                $hours += $val->hours > 0 ? $val->hours : '00';
                                if ($val->mins > 0) {
                                    if ($val->mins > 60) {
                                        $hours  += $val->mins / 60;
                                    } else {
                                        $minutes = $val->mins;
                                    }
                                } else {
                                    $minutes = '00';
                                }
                                $start_date[$key] = $hours . ':' . $minutes;
                            }
                        }
                        $end_time = $get_dependencies[0]['booking_end_time'] ?? "00:00";
                        $end_time = json_decode($end_time);
                        foreach ($end_time as $values) {
                            foreach ($values as $key => $val) {
                                $hours = $val->days > 0 ? $val->days * 24 : '00';
                                $hours += $val->hours > 0 ? $val->hours : '00';
                                if ($val->mins > 0) {
                                    if ($val->mins > 60) {
                                        $hours  += $val->mins / 60;
                                    } else {
                                        $minutes = $val->mins;
                                    }
                                } else {
                                    $minutes = '00';
                                }
                                $end_date[$key] = $hours . ':' . $minutes;
                            }
                        }
                    }
                    foreach ($start_time as $prod_key => $val) {
                        foreach ($val as $subkey => $value) {
                            $dependency[$main_ticket_id][] = array(
                                'product_id' => (string) $subkey,
                                'product_parent_id' => $main_ticket_id,
                                'product_dependent_id' => $prod_key,
                                'product_booking_window_start_time' => $start_date[$subkey],
                                'product_booking_window_end_time' => $end_date[$subkey],
                            );
                        }
                    }
                }
            }
            if ($cod_id_query != '') {
                if (!empty($subtickets_n_addons)) {
                    $cap_ids = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id', 'where' => 'mec_id in (' . implode(",", $subtickets_n_addons) . ') and active = "1" and deleted = "0" and endDate >= "' . strtotime(date("Y-m-d")) . '" and is_reservation = "1"'));
                    $where = '(cod_id ' . $cod_id_query . ' or mec_id in (' . implode(",", $subtickets_n_addons) . ')) and active = "1" and deleted = "0" and endDate >= "' . strtotime(date("Y-m-d")) . '" and is_reservation = "0"';
                    $opn_tickets =  $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle,shared_capacity_id, cod_id', 'where' => $where));
                    $ids = array();
                    foreach ($cap_ids as $id) {
                        if ($id['shared_capacity_id'] != "0" && $id['shared_capacity_id'] != Null) {
                            $ids[] = $id['shared_capacity_id'];
                        }
                        if ($id['own_capacity_id'] != "0" && $id['own_capacity_id'] != Null) {
                            $ids[] = $id['own_capacity_id'];
                        }
                    }
                    $addons_cap_query = '';
                    if (!empty($ids)) {
                        $addons_cap_query = ' or shared_capacity_id in (' . implode(",", $ids) . ')';
                    }
                } else {
                    $where = 'cod_id ' . $cod_id_query . ' and active = "1" and deleted = "0" and endDate >= "' . strtotime(date("Y-m-d")) . '" and is_reservation = "0"';
                    $opn_tickets =  $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, shared_capacity_id, cod_id', 'where' => $where));
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
                            ((empty($subtickets_n_addons) && !empty($ticket_flags)) ||
                                (!empty($subtickets_n_addons) && in_array($opn_ticket['mec_id'], $subtickets_n_addons)
                                    &&
                                    (in_array($opn_ticket['mec_id'], array_keys($sub_tickets)) &&
                                        (
                                            (!in_array($opn_ticket['mec_id'], array_keys($addons)) && (!in_array($sub_tickets[$opn_ticket['mec_id']], $hidden_tickets))) ||
                                            (in_array($opn_ticket['mec_id'], array_keys($addons)) &&  (!in_array($sub_tickets[$addons[$opn_ticket['mec_id']]], $hidden_tickets)))
                                        )
                                    )

                                )
                            )
                        ) {
                            $main_ticket = (in_array($opn_ticket['mec_id'], $ticket_flags)) ?  $opn_ticket['mec_id'] : "";
                            $open_tickets[$opn_ticket['mec_id']] = array(
                                'product_id' => $opn_ticket['mec_id'],
                                'product_title' => $opn_ticket['postingEventTitle'],
                                'product_supplier_id' => $opn_ticket['cod_id'],
                                'main_product_id' => isset($sub_tickets[$opn_ticket['mec_id']]) ? $sub_tickets[$opn_ticket['mec_id']] : $main_ticket,
                                'product_admission_type' => 'TIME_OPEN'
                            );
                            if (isset($addons[$opn_ticket['mec_id']]) && $addons[$opn_ticket['mec_id']] != null) {
                                $open_tickets[$opn_ticket['mec_id']]['addon_parent'] = (string) $addons[$opn_ticket['mec_id']];
                            }
                            $open_tickets[$opn_ticket['mec_id']]['product_dependency_details'] = isset($dependency[$opn_ticket['mec_id']]) ? $dependency[$opn_ticket['mec_id']] : array();
                        }
                    }
                }

                $shared_capacities = $this->find('shared_capacity', array('select' => 'shared_capacity_id, capacity_title', 'where' => 'supplier_id ' . $cod_id_query . $timeslots_query . $addons_cap_query), "list");
                $logs['shared_capacities_query'] = $this->primarydb->db->last_query();
                if (!empty($shared_capacities)) {
                    if (!empty($subtickets_n_addons)) {
                        $where = '((cod_id ' . $cod_id_query . ' and (shared_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ') or own_capacity_id in (' . implode(",", array_keys($shared_capacities)) . '))) or mec_id in (' . implode(",", $subtickets_n_addons) . ')) and active = "1" and deleted = "0" and endDate >= "' . strtotime(date("Y-m-d")) . '" and is_reservation = "1"';
                        $res_tickets = $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, shared_capacity_id, own_capacity_id, cod_id', 'where' => $where));
                    } else {
                        $where = 'cod_id ' . $cod_id_query . ' and (shared_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ') or own_capacity_id in (' . implode(",", array_keys($shared_capacities)) . ')) and active = "1" and deleted = "0" and endDate >= "' . strtotime(date("Y-m-d")) . '" and is_reservation = "1"';
                        $res_tickets = $this->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, shared_capacity_id, own_capacity_id, cod_id', 'where' => $where));
                    }
                    foreach ($res_tickets as $res_ticket_details) {
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

                                (!empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                    &&
                                    (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) &&
                                        (
                                            (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($sub_tickets[$res_ticket['mec_id']], $hidden_tickets)) ||
                                            (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($sub_tickets[$addons[$res_ticket['mec_id']]], $hidden_tickets))
                                        )
                                    )

                                )
                            )
                        ) { //remove hidden tickets
                            if (
                                $res_ticket['shared_capacity_id'] > 0 &&
                                (empty($hidden_capacities) ||
                                    (!empty($hidden_capacities) && !in_array($res_ticket['shared_capacity_id'], $hidden_capacities) && !in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                        ||
                                        ((empty($subtickets_n_addons)  && !empty($ticket_flags)) ||
                                            (!empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                                &&
                                                (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) &&
                                                    (
                                                        (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($ticket_capacity_details[$sub_tickets[$res_ticket['mec_id']]]['shared_capacity_id'], $hidden_capacities)) ||
                                                        (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($ticket_capacity_details[$sub_tickets[$addons[$res_ticket['mec_id']]]]['shared_capacity_id'], $hidden_capacities))
                                                    )
                                                )

                                            )
                                        )
                                    )

                                )
                            ) {
                                // Get timesolt from standard ticket opening hours
                                $query = 'select timeslot from standardticketopeninghours where ticket_id = ' . $res_ticket['mec_id'];
                                $result_arr = $this->primarydb->db->query($query)->result_array();
                                //remove hidden capacities
                                $main_ticket = (in_array($res_ticket['mec_id'], $ticket_flags)) ?  $res_ticket['mec_id'] : "";
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']] = array(
                                    'product_id' => $res_ticket['mec_id'],
                                    'product_title' => $res_ticket['postingEventTitle'],
                                    'product_supplier_id' => $res_ticket['cod_id'],
                                    'main_product_id' => isset($sub_tickets[$res_ticket['mec_id']]) ? $sub_tickets[$res_ticket['mec_id']] : $main_ticket,
                                    'product_admission_type' => $this->get_admission_type($result_arr[0]['timeslot']),
                                );
                                if (isset($addons[$res_ticket['mec_id']]) && $addons[$res_ticket['mec_id']] != null) {
                                    $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['addon_parent'] = $addons[$res_ticket['mec_id']];
                                }
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['product_dependency_details'] = isset($dependency[$res_ticket['mec_id']]) ? $dependency[$res_ticket['mec_id']] : array();
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['capacity_title'] = $shared_capacities[$res_ticket['shared_capacity_id']];
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['capacity_id'] = (string) $res_ticket['shared_capacity_id'];
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['supplier_id'] = (string) $res_ticket['cod_id'];
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['product_assigned_slots'] = isset($timeslots[$res_ticket['shared_capacity_id']]) ? $timeslots[$res_ticket['shared_capacity_id']] : (object) array();
                            }
                            if (
                                $res_ticket['own_capacity_id'] > 0 &&
                                (empty($hidden_capacities) ||
                                    (!empty($hidden_capacities) && !in_array($res_ticket['own_capacity_id'], $hidden_capacities) && !in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                    ) ||
                                    ((empty($subtickets_n_addons)  && !empty($ticket_flags)) ||
                                        (!empty($subtickets_n_addons) && in_array($res_ticket['mec_id'], $subtickets_n_addons)
                                            &&
                                            (in_array($res_ticket['mec_id'], array_keys($sub_tickets)) &&
                                                (
                                                    (!in_array($res_ticket['mec_id'], array_keys($addons)) && !in_array($ticket_capacity_details[$sub_tickets[$res_ticket['mec_id']]]['own_capacity_id'], $hidden_capacities)) ||
                                                    (in_array($res_ticket['mec_id'], array_keys($addons)) &&  !in_array($ticket_capacity_details[$sub_tickets[$addons[$res_ticket['mec_id']]]]['own_capacity_id'], $hidden_capacities))
                                                )
                                            )

                                        )
                                    )
                                )
                            ) { //remove hidden capacities
                                $main_ticket = (in_array($res_ticket['mec_id'], $ticket_flags)) ?  $res_ticket['mec_id'] : "";
                                $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']] = array(
                                    'product_title' => $res_ticket['postingEventTitle'],
                                    'product_id' => $res_ticket['mec_id'],
                                    'product_supplier_id' => $res_ticket['cod_id'],
                                    'main_product_id' => isset($sub_tickets[$res_ticket['mec_id']]) ? $sub_tickets[$res_ticket['mec_id']] : $main_ticket,
                                    'product_admission_type' => 'RESERVATION_TICKET',
                                );
                                if (isset($addons[$res_ticket['mec_id']]) && $addons[$res_ticket['mec_id']] != null) {
                                    $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']]['addon_parent'] = $addons[$res_ticket['mec_id']];
                                }
                                $reservation_tickets[$res_ticket['shared_capacity_id'] . '_' . $res_ticket['mec_id']]['product_dependency_details'] = isset($dependency[$res_ticket['mec_id']]) ? $dependency[$res_ticket['mec_id']] : array();
                                $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']]['capacity_title'] = $shared_capacities[$res_ticket['own_capacity_id']];
                                $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']]['capacity_id'] = $res_ticket['own_capacity_id'];
                                $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']]['supplier_id'] = $res_ticket['cod_id'];
                                $reservation_tickets[$res_ticket['own_capacity_id'] . '_' . $res_ticket['mec_id']]['product_assigned_slots'] = isset($timeslots[$res_ticket['own_capacity_id']]) ? $timeslots[$res_ticket['own_capacity_id']] : (object) array();
                            }
                        }
                    }
                }
                if (!empty($open_tickets)) {
                    foreach ($open_tickets as $ticket_id =>  $open_ticket_arr) {
                        if (in_array($ticket_id, $subtickets_n_addons)) {
                            if (array_key_exists($open_ticket_arr['main_ticket'], $ticket_capacity_details)) {
                                if (in_array($ticket_capacity_details[$open_ticket_arr['main_ticket']]['shared_capacity_id'], $hidden_capacities) || in_array($ticket_capacity_details[$open_ticket_arr['main_ticket']]['own_capacity_id'], $hidden_capacities)) {
                                    unset($open_tickets[$ticket_id]);
                                }
                            }
                        }
                    }
                }
            }
            $final = array_merge(array_values($reservation_tickets), array_values($open_tickets));
            $final_arr = array_slice($final, $offset, $item_per_page);
            if (!empty($search_param)) {
                $final_arr = $this->search_filterdata($search_param, $final_arr);
            }
            if ($search) {
                $final_arr = $this->searchForaadmission($search, $final_arr);
            }
            // $open_tickets = array_slice($open_tickets, $offset, $item_per_page);
            $MPOS_LOGS['tickets_listing'] = $logs;
            $response =  array(
                "kind" => "product",
                "current_item_count" => ($item_per_page < count($final_arr) ? $item_per_page : count($final_arr)),
                "items_per_page" => (int) $item_per_page,
                "start_index" => $page * $item_per_page - ($item_per_page - 1),
                "total_items" => count($final),
                "page_index" => !empty($page) ? (int) $page : 1,
                "total_pages" => ceil(count($final) / $item_per_page),
                'items' => !empty($final_arr) ? $final_arr : array()
            );
            return $response;
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['tickets_listing'] = $logs;
            return $logs['exception'];
        }
    }
    // End of Function to get listing of tickets

    //Function to filter data as per product_admission_type
    function searchForaadmission($status, $array)
    {
        $status = explode(",", $status);
        foreach ($array as $val) {
            if (in_array($val['product_admission_type'], $status)) {
                $vals[] = $val;
            }
        }
        return $vals;
    }

    // function to get ENUMS as per timeslots
    function get_admission_type($timeslot = '')
    {
        switch ($timeslot) {
            case 'open':
                $product_admission_type = "TIME_OPEN";
                break;
            case 'day':
                $product_admission_type = "TIME_DATE";
                break;
            case 'time_interval':
                $product_admission_type = "TIME_PERIOD";
                break;
            case 'specific':
                $product_admission_type = "TIME_POINT";
                break;
            default:
                $product_admission_type = "TIME_SLOT";
                break;
        }
        return $product_admission_type;
    }

    // Function to convert datatime format
    function create_date_time($date_format, $time)
    {
        return $date_format . 'T' . $time . ':00+00:00';
    }

    // Function to filter data as per ticket id or ticket_title
    function search_filterdata($search, $final_arr)
    {
        foreach ($final_arr as $val) {
            if ($val['product_id'] == $search || $val['product_title'] == $search) {
                $vals[] = $val;
            }
        }
        return $vals;
    }
}
