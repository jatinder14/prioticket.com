<?php

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

use function GuzzleHttp\json_encode;

trait TFirebase_model {

    var $TICKET_TYPES = array();
    var $REFUND_ACTIONS = array();

    function __construct() {

        /* Call the Model constructor */
        parent::__construct();
        $this->load->model('V1/hotel_model');
        $this->load->model('V1/common_model');
        $this->load->model('V1/third_party_api_model');
        $this->TICKET_TYPES = array(
            'Adult' => '1',
            'Baby' => '2',
            'Child' => '3',
            'Elderly' => '4',
            'Handicapt' => '5',
            'Student' => '6',
            'Military' => '7',
            'Youth' => '8',
            'Senior' => '9',
            'Custom' => '10',
            'Family' => '11',
            'Resident' => '12',
            'Infant' => '13',
        );
        $this->REFUND_ACTIONS = array(
            '1' => 'MPOS_BO_RFN',
            '2' => 'MPOS_BO_CC_RFN',
            '3' => 'MPOS_BO_PC_RFN',
            '4' => 'MPOS_CC_RFN',
            '5' => 'MPOS_PC_RFN',
            '6' => 'MPOS_CS_RFN',
            '7' => 'MPOS_ACT_CNCL'
        );
        $this->api_channel_types = array(4, 5, 6, 7, 8, 9, 13); // API Chanell types
        $this->current_date = "2019-10-11";
        $this->mpos_channels = array('10', '11');
        $this->vt_max_rows = 30;
        $this->notes_levels = array('order', 'booking');
    }

    /* #region Order Process  Module : This covers cancel proces api's */
    
    /**
     * @Name ticket_listing
     * @Purpose function is used to get booking orders for firebase
     * @returns Return list of bookings for that cashier.
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 1 Nov 2017
     */
    function ticket_listing($from_date = '', $to_date = '', $cashier_id = '', $page = '', $limit = '', $search = '', $user_role = '', $hotel_id = '') {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $all_tickets = array();
            $response = array();
            /* Get details from prepaid tickets */
            $offset = ($page - 1) * $limit;
            $search = trim($search);
            $query = 'select tp_payment_method, is_refunded, created_date_time, bleep_pass_no, guest_names, cashier_name, merchantReference, timezone, passNo, GROUP_CONCAT(passNo SEPARATOR ", "), ticket_id, museum_name, is_combi_ticket, cashier_id, visitor_group_no, count(prepaid_ticket_id) as total_tickets, sum(if(is_refunded = "2", 1, 0)) as cancel_tickets, selected_date, from_time, to_time, museum_name, title as ticket_title, sum(price) as order_amount, order_status as invoice_status, ticket_booking_id, without_elo_reference_no, channel_type, booking_status, timezone, hotel_id, activation_method, extra_discount from prepaid_tickets where hotel_id = "' . $hotel_id . '" and ';

            if (strpos($search, 'https://qu.mu') !== false) { //search from host app
                $search = str_replace("https://qu.mu/", "", $search);
                $query .= 'bleep_pass_no = "' . $search . '" and ';
            } else { //search from MPOS app
                if ($user_role == 2) { //only city card on cases are visible to supervisor for preassigned and third party 
                    $search_query = ' or (museum_cashier_name like "%' . $search . '%" and (channel_type in (10,11) or (channel_type not in (10,11) and bleep_pass_no != "" )))';
                } else {
                    $search_query = ' and channel_type IN (10,11)';
                }
                
                if (strlen($search) == 15) { //vgn
                    $query .= 'visitor_group_no = "' . $search . '" and ';
                } else if(strlen($search) == 16) { //passNo
                        $query .= '(passNo = "' . $search . '" or bleep_pass_no = "' . $search . '") and ';
                } else {
                    if ($search != '') {
                        $query .= '(visitor_group_no = "' . $search . '" or passNo = "' . $search . '" or bleep_pass_no = "' . $search . '" or merchantReference like "%' . $search . '%" or cashier_name like "%' . $search . '%" '.$search_query.'  or guest_names like "%' . $search . '%") and ';
                    }
                }
                
                /* cashier_id is not required for admin and supervisor because supervisor and admin can search about all other users */
                if ($user_role != '2' && $user_role != '3') {
                    $query .= 'cashier_id = "' . $cashier_id . '" and ';
                }
            }

            $query .= 'is_prepaid = "1" and deleted = "0" and is_addon_ticket !="2" and (is_refunded = "0" or is_refunded = "2") ';

            if ($from_date != '') {
                $query .= ' and DATE(created_date_time) >= "' . $from_date . '"';
            }
            if ($to_date != '') {
                $query .= ' and DATE(created_date_time) <= "' . $to_date . '"';
            }

            $query .= ' group by visitor_group_no, ticket_booking_id, ticket_id, selected_date, from_time, to_time';
            $query .= ' order by created_date_time desc';
            $query .= ' limit ' . $offset . ', ' . $limit;
            $all_tickets = $this->query($query, 1);
            $logs['pt_query_' . date('H:i:s')] = $query;
            $internal_log['pt_query_response_' . date('H:i:s')] = $all_tickets;
            $pass_check = $all_tickets[0]['passNo'];
            $bleep_pass_check = $all_tickets[0]['bleep_pass_no'];
            $visitor_group_no = $all_tickets[0]['visitor_group_no'];
            $search_pass = 1;
            foreach ($all_tickets as $ticket) {
                if ($ticket['channel_type'] == 5) {
                    $search_pass = 0;
                }
            }

            /* To get complete order details related to pass no searched */
            if ($search_pass == '1' && ($pass_check == $search || $bleep_pass_check == $search)) {

                /* on basis of visitor_group_no, fetched from search details */
                $condition = '';
                if ($user_role != '2' && $user_role != '3') {
                    $condition = ' and cashier_id = "' . $cashier_id . '"';
                }
                if ($user_role == 2) {
                    $srch_query = '  and (channel_type in (10,11) or (channel_type not in (10,11) and bleep_pass_no != "" ))';
                } else {
                    $srch_query = ' and channel_type IN (10,11)';
                }

                $query = 'select tp_payment_method, is_refunded, created_date_time, timezone, passNo, GROUP_CONCAT(passNo SEPARATOR ", "), ticket_id, museum_name, is_combi_ticket, cashier_id, visitor_group_no, count(prepaid_ticket_id) as total_tickets, sum(if(is_refunded = "2", 1, 0)) as cancel_tickets, selected_date, from_time, to_time, pt.museum_name, pt.title as ticket_title, sum(pt.price) as order_amount, pt.order_status as invoice_status, pt.ticket_booking_id, pt.without_elo_reference_no, pt.channel_type, pt.booking_status, pt.timezone, pt.hotel_id, pt.activation_method, pt.extra_discount from prepaid_tickets pt where pt.visitor_group_no = ' . $visitor_group_no . ' and pt.is_prepaid = "1" and pt.deleted = "0" and pt.is_addon_ticket !="2" and is_refunded = "0" ' . $srch_query . $condition . ' group by ticket_id';
                /* overwrite previous data by complete order data */
                $all_tickets = $this->query($query, 1);
            }

            if (!empty($all_tickets)) {
                foreach ($all_tickets as $ticket) {
                    $all_orders[$ticket['visitor_group_no']] = $ticket['visitor_group_no'];
                }

                /* Get extra option prices */
                $query = 'select visitor_group_no, ticket_id, ticket_price_schedule_id, selected_date, from_time, to_time, timeslot, price, net_price, quantity, refund_quantity, variation_type';
                $query .= ' from prepaid_extra_options where visitor_group_no IN (' . implode(',', array_keys($all_orders)) . ')';
                $all_extra_options = $this->query($query, 1);
                $logs['extra_options_' . date('H:i:s')] = $query;
                $internal_log['extra_options_result_' . date('H:i:s')] = $all_extra_options;
                foreach ($all_extra_options as $option) {
                    if($option['variation_type'] == 0){
                        $quantity = $option['quantity'];
                        $extra_option_prices[$option['visitor_group_no'] . '_' . $option['ticket_id']] = array(
                            "price" => $extra_option_prices[$option['visitor_group_no'] . '_' . $option['ticket_id']]['price'] + $option['price'] * $quantity
                        );
                    }
                }

                /* Make array to return data on firebase */
                foreach ($all_tickets as $details) {
                    if ($details['selected_date'] != '' && $details['selected_date'] != '0' && $details['from_time'] != '' && $details['from_time'] !== '0' && $details['to_time'] != '' && $details['to_time'] != '0') {
                        $reservation_date = date('Y-m-d', strtotime($details['selected_date']));
                        $from_time = $details['from_time'];
                        $to_time = $details['to_time'];
                    } else {
                        $reservation_date = '';
                        $from_time = '';
                        $to_time = '';
                    }
                    if ($details['cancel_tickets'] > 0) {
                        $status = 3;
                    } else {
                        $status = 2;
                    }
                    /* Prepare booking listing array to send response to app. */
                    if ($details['tp_payment_method'] == 6) { //combi
                        $product_type = 2;
                    } else if ($details['tp_payment_method'] == 7) { //cluster
                        $product_type = 3;
                    } else {
                        $product_type = 0; //normal
                    }
                    $response['bookings_list'][] = array(
                        'booking_date_time' => $details['created_date_time'],
                        'reservation_date' => $reservation_date,
                        'from_time' => $from_time,
                        'to_time' => $to_time,
                        'order_id' => $details['visitor_group_no'],
                        'channel_type' => $details['channel_type'],
                        'is_voucher' => ($details['channel_type'] != 5 && $details['hotel_id'] == $hotel_id) ? (int) 0 : (int) 1,
                        'reference' => ($details['without_elo_reference_no'] != '') ? $details['without_elo_reference_no'] : '',
                        'museum' => $details['museum_name'],
                        'timezone' => (int) $details['timezone'],
                        'product_type' => (int) $product_type,
                        'ticket_id' => (int) $details['ticket_id'],
                        'ticket_title' => $details['ticket_title'],
                        'is_reservation' => ($reservation_date != 0 && $reservation_date != '' && $from_time != '' && $from_time != 0) ? (int) 1 : (int) 0,
                        'booking_name' => ($details['guest_names'] != '') ? $details['guest_names'] : '',
                        "merchant_reference" => '',
                        'payment_method' => (int) $details['activation_method'],
                        'quantity' => (int) $details['total_tickets'],
                        'amount' => (float) ($details['order_amount'] + ((isset($extra_option_prices[$details['visitor_group_no'] . '_' . $details['ticket_id']]) && !empty($extra_option_prices[$details['visitor_group_no'] . '_' . $details['ticket_id']])) ? $extra_option_prices[$details['visitor_group_no'] . '_' . $details['ticket_id']]['price'] : 0)),
                        'cancelled_tickets' => (int) ($details['cancel_tickets']),
                        'passes' => ($details['is_combi_ticket'] == "1") ? $details['passNo'] : $details['GROUP_CONCAT(pt.passNo SEPARATOR ", ")'],
                        'status' => (int) $status
                    );
                }
                if (!empty($response)) {
                    $response['status'] = (int) 1;
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
                }
            } else {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
            }
            $MPOS_LOGS['ticket_listing'] = $logs;
            $internal_logs['ticket_listing'] = $internal_log;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['ticket_listing'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name order_details
     * @Purpose This function is used to send all the details for requested booking
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function order_details($hotel_id = '', $data = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $logs['hotel_id'] = $hotel_id;
            $response = array();
            $created_date_time = $data['booking_date_time'];
            if ($data['channel_type'] == 5) {
                $pass_condition = ' and passNo = "' . $data['pass_no'] . '"';
            } else {
                $pass_condition = '';
            }
            //Fetch complete order details from PT and then filter it for particular ticket to show details on app
            $is_voucher = (isset($data['is_voucher'])) ? $data['is_voucher'] : 0;
            $query = 'select pax, capacity, redeem_users, extra_booking_information, third_party_type, third_party_response_data, museum_id, museum_name, visitor_group_no, activated, action_performed, scanned_at, museum_cashier_id,museum_cashier_name,hotel_id, hotel_name, order_status,is_addon_ticket,clustering_id,pos_point_name, cluster_group_id,shared_capacity_id,cashier_id,cashier_name,distributor_partner_name,bleep_pass_no,additional_information, used, is_refunded, timezone, is_prioticket, extra_text_field_answer, is_prioticket, passNo, activation_method, channel_type, without_elo_reference_no, extra_discount, pass_type, discount, created_date_time, selected_date, from_time, to_time, timeslot, ticket_id, title, tps_id, ticket_type, age_group, price, net_price, quantity, passNo, is_combi_ticket, is_combi_discount, combi_discount_gross_amount';
            $query .= ' from prepaid_tickets where visitor_group_no = "' . $data['order_id'] . '"' . $pass_condition . ' and (is_refunded = "0" or is_refunded = "2") and created_date_time = "' . $created_date_time . '" and deleted = "0" ';
            $order_all_details = $this->query($query, 1);
            $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['pt_response_' . date('H:i:s')] = $order_all_details;
            $is_combi_discount = 0;
            $voucher_order = 0;
            $exception_order = 0;
            $cluster_tickets_array = array();
            $canceled_cluster_tickets_array = array();
            $total_tickets_array = array();
            $is_upsell = 0;
            $is_upsell_main = 0;
            $citycard_cancel = 1;
            $active_ticket = 1;
            foreach ($order_all_details as $detail) {
                //Check if combi discount, is on order, we can't cancel it from booking overview
                if ($detail['combi_discount_gross_amount'] > 0) {
                    $is_combi_discount = 1;
                }
                if ($detail['activation_method'] == "10") {
                    $voucher_order = 1;
                }
                if ($detail['activation_method'] == "19") {
                    $exception_order = 1;
                }
                if ($detail['is_addon_ticket'] == "0") {
                    $total_tickets_array[$detail['ticket_id'] . '_' . $detail['selected_date'] . '_' . $detail['from_time'] . '_' . $detail['to_time']] = $detail['ticket_id'];
                }
                /* Get details of requested tickets. */
                if ($data['ticket_id'] == $detail['ticket_id']) { //get cluster data for matching ticket
                    $cluster_group_id = $detail['cluster_group_id'];
                }
                if ($data['selected_date'] != '' && $data['selected_date'] != 0 && $data['from_time'] != '' && $data['from_time'] !== '0') {
                    //for same ticket with multiple time slots in same order
                    if ($data['ticket_id'] == $detail['ticket_id'] && $data['selected_date'] == $detail['selected_date'] && $data['from_time'] == $detail['from_time'] && $data['to_time'] == $detail['to_time']) {
                        if (strpos($detail['action_performed'], 'UPSELL') !== false && ($data['cashier_type'] == "2" || $data['cashier_type'] == "3")) {
                            $is_upsell_main = 1;
                            if ($detail['activated'] != 1) {
                                $active_ticket = 0;
                            }
                        } else if (strpos($detail['action_performed'], 'UPSELL') !== false && ($data['cashier_type'] != "2" || $data['cashier_type'] != "3") && $detail['activated'] == "0" && $detail['is_addon_ticket'] == "0") {
                            $is_upsell = 1;
                            if ($detail['activated'] != 1) {
                                $active_ticket = 0;
                            }
                        } else if (strpos($detail['action_performed'], 'UPSELL_INSERT') !== false && ($data['cashier_type'] != "2" && $data['cashier_type'] != "3" && $data['cashier_type'] != "5")) {
                            $is_upsell = 1;
                        }
                        $booking_details[] = $detail;
                    } else if ($data['product_type'] == '2' && $detail['is_addon_ticket'] == '2' && $cluster_group_id == $detail['cluster_group_id']) {
                        $sub_tickets[] = $detail;
                    }
                } else {
                    if ($data['ticket_id'] == $detail['ticket_id']) {
                        if (strpos($detail['action_performed'], 'UPSELL_INSERT') !== false && ($data['cashier_type'] != "2" && $data['cashier_type'] != "3" && $data['cashier_type'] != "5")) {
                            $is_upsell = 1;
                        } else if (strpos($detail['action_performed'], 'UPSELL') !== false && strpos($detail['action_performed'], 'UPSELL_INSERT') === false && ($data['cashier_type'] == "2" || $data['cashier_type'] == "3")) {
                            $is_upsell_main = 1;
                            if ($detail['activated'] != 1) {
                                $active_ticket = 0;
                            }
                        } else if (strpos($detail['action_performed'], 'UPSELL') !== false && ($data['cashier_type'] != "2" || $data['cashier_type'] != "3") && $detail['activated'] == "0" && $detail['is_addon_ticket'] == "0") {
                            $is_upsell = 1;
                            if ($detail['activated'] != 1) {
                                $active_ticket = 0;
                            }
                        }
                        $booking_details[] = $detail;
                    } else if ($data['product_type'] == '2' && $detail['is_addon_ticket'] == '2' && $cluster_group_id == $detail['cluster_group_id']) {
                        $sub_tickets[] = $detail;
                    }
                }
                // $citycard_cancel = 0 -> order is extended, we can't cancel it
                // $citycard_cancel = 1 -> it will cancel the citycards
                // $citycard_cancel = 2 -> citycards are already cancelled
                // $citycard_cancel = 4 -> citycards can be cancelled
                if (strpos($detail['action_performed'], 'UPSELL') !== false && $detail['activated'] == "0" && $detail['ticket_id'] == $data['ticket_id']) {
                    $citycard_cancel = 0;
                } else if ($detail['ticket_id'] == $data['ticket_id']) {
                    $citycard_cancel = (empty($detail['bleep_pass_no']) ? 0 : 1);
                }
                if (strpos($detail['action_performed'], 'MPOS_ACT_CNCL') !== false && $detail['bleep_pass_no'] == '') {
                    $citycard_cancel = 2;
                }
                if (strpos($detail['action_performed'], 'MPOS_ACT_CNCL') !== false && $detail['bleep_pass_no'] != '') {
                    $citycard_cancel = 3;
                }
                //Prepare cluster tickets used and refunded array
                if ($detail['cluster_group_id'] != "" && $detail['cluster_group_id'] != NULL && $detail['cluster_group_id'] != 0) {
                    //check used cluster tickets
                    if ((isset($cluster_tickets_array[$detail['clustering_id']]) && $cluster_tickets_array[$detail['clustering_id']] == 0) || !isset($cluster_tickets_array[$detail['clustering_id']])) {
                        $cluster_tickets_array[$detail['clustering_id']] = $detail['used'];
                    }
                    //check cancelled cluster tickets
                    if ((isset($canceled_cluster_tickets_array[$detail['clustering_id']]) && $canceled_cluster_tickets_array[$detail['clustering_id']] == 0) || !isset($canceled_cluster_tickets_array[$detail['clustering_id']])) {
                        $canceled_cluster_tickets_array[$detail['clustering_id']] = $detail['is_refunded'];
                    }
                }
            }
            sort($total_tickets_array);
            $total_number_of_tickets = count($total_tickets_array);
            $internal_log['total_number_of_tickets'] = $total_number_of_tickets;
            if (!empty($booking_details)) {
                // reprint setting for distributor
                $qr_codes = $this->find('qr_codes', array('select' => 'mpos_settings', 'where' => 'cod_id = "' . $hotel_id . '" '));
                $mpos_settings = json_decode($qr_codes[0]['mpos_settings'], TRUE);
                /* Get third party details from modeventcontent table. */
                $query = 'select third_party_id, mec_id, allow_city_card, is_scan_countdown, barcode_type';
                $query .= ' from modeventcontent where mec_id in (' . implode(',', $total_tickets_array) . ')';
                $ticket_details_array = $this->query($query);
                $logs['modeventcontent_' . date('H:i:s')] = $this->db->last_query();
                $internal_log['modeventcontent_response_' . date('H:i:s')] = $ticket_details_array;
                $third_party_details = array();
                $third_party_exist = 0;
                $barcode_type_exist = 0;
                foreach($ticket_details_array as $ticket_details) {
                    $countdown_tickets[$ticket_details['mec_id']] = $ticket_details['is_scan_countdown'];
                    if ($data['ticket_id'] == $ticket_details['mec_id']) {
                        $is_barcode_ticket = $ticket_details['barcode_type'];
                    }
                    if ($ticket_details['third_party_id'] != '' && $ticket_details['third_party_id'] > 0) {
                        $third_party = 1;
                        if ($data['ticket_id'] == $ticket_details['mec_id']) {
                            $third_party_details = array(
                                'third_party' => (int) $ticket_details['third_party_id']
                            );
                        }
                    } else {
                        $third_party = 0;
                    }
                    // check if requested ticket is third party ticket or other ticket in order is third party
                    $third_party_type = ($third_party != 0) ? 1 : 0;
                    if ($third_party_type == 1) {
                        if (!empty($third_party_details)) {
                            $third_party_exist = 1;
                        } else {
                            $third_party_exist = 2;
                        }
                    }
                    // check if requested ticket is barcode type ticket or other ticket in order is third party
                    if ($ticket_details['barcode_type'] != 0) {
                        if ($data['ticket_id'] == $ticket_details['mec_id']) {
                            $barcode_type_exist = 1;
                        } else {
                            $barcode_type_exist = 2;
                        }
                    }
                    $allow_city_card = $ticket_details['allow_city_card'];
                }

                $passes = array();
                $used = 1;
                $unused = 1;
                $refunded = 1;
                $extra_discount = array();
                $redeem_users = '';
                foreach ($booking_details as $row) {
                    //make array of all clustering ids which are not used
                    if (!empty($row['clustering_id'])) {
                        $cluster_details_array[$row['ticket_id'] . '_' . $row['tps_id']][$row['clustering_id']] = $row['used'];
                    }
                    $device_details = unserialize($row['additional_information']);
                    //set used = 1 if bleep passs no is added in add to pass case
                    if ($row['bleep_pass_no'] != '' && !isset($device_details['add_to_pass'])) {
                        $row['used'] = 1;
                    }
                    if ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '') {
                        $row['used'] = 0;
                    }
                    $redeem_users = ($redeem_users == '' && $row['redeem_users'] != '') ? $row['redeem_users'] : $redeem_users;
                    $bleep_pass_no = ($bleep_pass_no == '' && $row['bleep_pass_no'] != '') ? $row['bleep_pass_no'] : $bleep_pass_no;
                    if ($row['bleep_pass_no'] != '' && $row['bleep_pass_no'] != NULL) {
                        $bleep_passes[$row['tps_id']][] = $row['bleep_pass_no'];
                        $all_passes[$row['bleep_pass_no'] . '_' . $row['ticket_id'] . '_' . $row['tps_id']] = $row['passNo'] . '_' . $row['ticket_id'] . '_' . $row['tps_id'];
                        $all_bleep_passes[$row['bleep_pass_no'] . '_' . $row['ticket_id'] . '_' . $row['tps_id']] = $row['passNo'];
                    }
                    /* Make array for used and unused passes. */
                    if ($mpos_settings['order_overview_type'] == '2' && $row['is_refunded'] == '2' && $row['passNo'] != '') {
                        if ($row['is_combi_ticket'] == 1) {
                            if ($refunded == 1 || empty($passes[$row['tps_id']])) {
                                $passes[$row['tps_id']]['refunded_passes'][] = $row['passNo'];
                            }
                            $refunded++;
                        } else {
                            $passes[$row['tps_id']]['refunded_passes'][] = $row['passNo'];
                        }
                    } else if ($row['used'] == 1 && $row['passNo'] != '') {
                        if ($row['is_combi_ticket'] == 1) {
                            if ($used == 1 || empty($passes[$row['tps_id']])) {
                                $passes[$row['tps_id']]['used_passes'][] = $row['passNo'];
                            }
                            $used++;
                        } else {
                            $passes[$row['tps_id']]['used_passes'][] = $row['passNo'];
                        }
                    } else if ($row['used'] == 0 && $row['passNo'] != '') {
                        if ($row['is_combi_ticket'] == 1) {
                            if ($unused == 1 || empty($passes[$row['tps_id']])) {
                                $passes[$row['tps_id']]['unused_passes'][] = $row['passNo'];
                            }
                            $unused++;
                        } else {
                            $passes[$row['tps_id']]['unused_passes'][] = $row['passNo'];
                        }
                    }
                    $ticket_type = ucfirst(strtolower($row['ticket_type']));
                    $key = $row['visitor_group_no'] . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time'] . '_' . $row['tps_id'];
                    $used_cluster_ticket = 0;
                    $canceled_cluster_ticket = 0;
                    if ($row['cluster_group_id'] != "" && $row['cluster_group_id'] != NULL && $row['cluster_group_id'] != 0) {
                        if (isset($cluster_tickets_array[$row['clustering_id']]) && $cluster_tickets_array[$row['clustering_id']] == "1") {
                            $used_cluster_ticket = 1;
                        }
                        if (isset($canceled_cluster_tickets_array[$row['clustering_id']]) && $canceled_cluster_tickets_array[$row['clustering_id']] != "0") {
                            $canceled_cluster_ticket = 1;
                        }
                    }
                    $extra_discount = unserialize($row['extra_discount']);
                    $capacity_ids = explode(',', $row['shared_capacity_id']);
                    $extra_info = json_decode($row['extra_booking_information'], true);
                    $third_party_response_data = json_decode(stripslashes($row['third_party_response_data']), true);
                    if (!empty($extra_info['product_type_spots']) && $row['third_party_type'] == '36') {
                        $spot_detail[$key][] = array(
                            "section" => $extra_info['product_type_spots'][0]['spot_section'],
                            "row" => $extra_info['product_type_spots'][0]['spot_row'],
                            "number" => $extra_info['product_type_spots'][0]['spot_number'],
                            "secret_key" => ($third_party_response_data['third_party_reservation_detail']['supplier']['secretKey'] != '') ? $third_party_response_data['third_party_reservation_detail']['supplier']['secretKey'] : "",
                            "event_key" => ($third_party_response_data['third_party_reservation_detail']['product_availability_id'] != '') ? $third_party_response_data['third_party_reservation_detail']['product_availability_id'] : ""
                        );
                    }
                    $order_details[$key] = array(
                        'visitor_group_no' => $row['visitor_group_no'],
                        'email' => ($extra_info['per_participant_info']['email'] != '' && $extra_info['per_participant_info']['email'] != NULL) ? $extra_info['per_participant_info']['email'] : '',
                        'name' => ($extra_info['per_participant_info']['name'] != '' && $extra_info['per_participant_info']['name'] != NULL) ? $extra_info['per_participant_info']['name'] : '',
                        'museum_id' => $row['museum_id'],
                        'museum_name' => $row['museum_name'],
                        'cashier_id' => $row['cashier_id'],
                        'cashier_name' => $row['cashier_name'],
                        'hotel_id' => $row['hotel_id'],
                        'hotel_name' => $row['hotel_name'],
                        'activated_by_id' => $row['museum_cashier_id'],
                        'channel_type' => $row['channel_type'],
                        'activated_by_name' => $row['museum_cashier_name'],
                        'group_name' => $device_details['group_name'],
                        'activated_at' => date("Y-m-d", $row['scanned_at']),
                        'is_upsell' => (strpos($detail['action_performed'], 'UPSELL_INSERT') !== false) ? (int) 1 : (int) 0,
                        'voucher_partner' => $row['distributor_partner_name'],
                        'voucher_category' => (isset($device_details['group_name']) && $device_details['group_name'] != '') ? $device_details['group_name'] : '',
                        'start_date' => (isset($device_details['start_date']) && $device_details['start_date'] != '') ? $device_details['start_date'] : '',
                        'end_date' => (isset($device_details['end_date']) && $device_details['end_date'] != '') ? $device_details['end_date'] : '',
                        'shared_capacity_id' => (!empty($capacity_ids[0])) ? $capacity_ids[0] : 0,
                        'own_capacity_id' => (!empty($capacity_ids[1])) ? $capacity_ids[1] : 0,
                        'cluster_ids' => (!empty($row['clustering_id'])) ? array_keys($cluster_details_array[$row['ticket_id'] . '_' . $row['tps_id']]) : array(),
                        'order_status' => $row['order_status'],
                        'used' => $row['used'],
                        'channel_name' => $row['distributor_type'],
                        'passNo' => $passes,
                        'bleep_passes' => $bleep_passes[$row['tps_id']],
                        'activation_method' => $row['activation_method'],
                        'without_elo_reference_no' => $row['without_elo_reference_no'],
                        'pass_type' => $is_barcode_ticket,
                        'discount' => $row['discount'],
                        'created_date_time' => $row['created_date_time'],
                        'pos_point_name' => $row['pos_point_name'],
                        'timezone' => $row['timezone'],
                        'created_at' => strtotime($row['created_date_time']),
                        'selected_date' => $row['selected_date'],
                        'from_time' => $row['from_time'],
                        'note' => $row['extra_text_field_answer'],
                        'to_time' => $row['to_time'],
                        'timeslot' => $row['timeslot'],
                        'ticket_id' => $row['ticket_id'],
                        'title' => $row['title'],
                        'guest_notification' => isset($extra_info['guest_notification']) ? $extra_info['guest_notification'] : '',
                        'tps_id' => $row['tps_id'],
                        'ticket_type' => (int) (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? $this->TICKET_TYPES[$ticket_type] : 10,
                        'ticket_type_label' => (string) $ticket_type,
                        /* cashier discount values according to each type */
                        'is_discount_code' => (!empty($extra_discount) || $order_details[$key]['is_discount_code'] == 1) ? 1 : 0,
                        'discount_code_amount' => (!empty($extra_discount) || $order_details[$key]['discount_code_amount'] > 0) ? $order_details[$key]['discount_code_amount'] + $extra_discount['gross_discount_amount'] : $extra_discount['gross_discount_amount'],
                        'discount_code_value' => (!empty($order_details[$key]['discount_code_value'])) ? $order_details[$key]['discount_code_value'] : $extra_discount['discount_label'],
                        'age_group' => $row['age_group'],
                        'price' => $row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount'],
                        'net_price' => $row['net_price'],
                        'quantity' => $row['quantity'],
                        'pax' => isset($row['pax']) ? (int) $row['pax'] : (int) 0,
                        'capacity' => isset($row['capacity']) ? (int) $row['capacity'] : (int) 1,
                        'oroginal_price' => $row['price'],
                        'is_combi_ticket' => $row['is_combi_ticket'],
                        'is_combi_discount' => $row['is_combi_discount'],
                        'combi_discount_gross_amount' => $order_details[$key]['combi_discount_gross_amount'] + $row['combi_discount_gross_amount'],
                        'total_combi_discount' => $order_details[$key]['total_combi_discount'] + $row['combi_discount_gross_amount'],
                        'total_tickets' => $order_details[$key]['total_tickets'] + $row['quantity'],
                        'cancel_tickets' => ($row['is_refunded'] == 2 || $canceled_cluster_ticket == "1") ? ($order_details[$key]['cancel_tickets'] + 1) : $order_details[$key]['cancel_tickets'],
                        'used_tickets' => ($row['used'] == 1 || $used_cluster_ticket == "1") ? $order_details[$key]['used_tickets'] + 1 : $order_details[$key]['used_tickets'],
                        'is_reservation' => ($row['selected_date'] != 0 && $row['selected_date'] != '' && $row['from_time'] != '' && $row['from_time'] !== '0') ? (int) 1 : (int) 0
                    );
                }
                if ($booking_details[0]['clustering_id'] > 0) {
                    $query = 'select ticket_id, tps_id, passNo, bleep_pass_no from prepaid_tickets where visitor_group_no = "' . $data['order_id'] . '" and is_addon_ticket = "2"';
                    $sub_ticket_data = $this->query($query, 1);
                    foreach ($sub_ticket_data as $row) {
                        $all_passes[$row['bleep_pass_no'] . '_' . $row['ticket_id'] . '_' . $row['tps_id']] = $row['passNo'] . '_' . $row['ticket_id'] . '_' . $row['tps_id'];
                        $all_bleep_passes[$row['bleep_pass_no'] . '_' . $row['ticket_id'] . '_' . $row['tps_id']] = $row['passNo'];
                    }
                }
               
                $order_details = $this->common_model->sort_ticket_types($order_details);
                $order_details = array_values($order_details);
                /* Get location of ticket */
                $ticket_location_details = $this->find('rel_targetvalidcities', array('select' => 'targetlocation, targetcity, targetcountry', 'where' => 'module_item_id = "' . $data['ticket_id'] . '"'));
                /* Get extra options purchased with that ticket */
                if ($data['selected_date'] != '' && $data['selected_date'] != 0 && $data['from_time'] != '' && $data['from_time'] !== 0) {
                    $where = 'visitor_group_no = "' . $data['order_id'] . '" and ticket_id = "' . $data['ticket_id'] . '" and selected_date = "' . $data['selected_date'] . '" and from_time = "' . $data['from_time'] . '" and to_time = "' . $data['to_time'] . '"';
                } else {
                    $where = 'visitor_group_no = "' . $data['order_id'] . '" and ticket_id = "' . $data['ticket_id'] . '"';
                }
                //Fetch and prepare extra options array
                $extra_options = $this->find('prepaid_extra_options', array('select' => 'description, price, quantity, tax,refund_quantity, net_price, ticket_price_schedule_id, visitor_group_no, selected_date, from_time, to_time, variation_type', 'where' => $where));
                $per_ticket_extra_options = array();
                $per_age_group_options = array();
                foreach ($extra_options as $option_details) {
                    if($option_details['variation_type'] == 0){
                        if ($option_details['ticket_price_schedule_id'] == 0 || $option_details['ticket_price_schedule_id'] == '') {
                            $per_ticket_extra_options[] = array(
                                'name' => $option_details['description'],
                                'quantity' => (int) $option_details['quantity'],
                                'refund_quantity' => (int) $option_details['refund_quantity'],
                                'price' => (float) $option_details['price'],
                                'net_price' => (float) $option_details['net_price'],
                                'tax' => (float) $option_details['tax']
                            );
                        } else {
                            $per_age_group_options[$option_details['ticket_price_schedule_id']][] = array(
                                'name' => $option_details['description'],
                                'quantity' => (int) $option_details['quantity'],
                                'refund_quantity' => (int) $option_details['refund_quantity'],
                                'price' => (float) $option_details['price'],
                                'net_price' => (float) $option_details['net_price'],
                                'tax' => (float) $option_details['tax']
                            );
                        }
                    }
                }
                /* Get details from hotel_ticket_overview */
                $query1 = 'select isBillToHotel, roomNo, guest_names, is_prioticket, passNo, merchantReference, user_age, distributor_type, user_email, show_ticket_price, is_voucher, client_reference, merchantReference, visitor_group_no from hotel_ticket_overview where visitor_group_no = "' . $data['order_id'] . '"';
                $hotel_overview_data = $this->query($query1, 1);
                $logs['hotel_ticket_overview_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['hotel_overview_response_' . date('H:i:s')] = $hotel_overview_data;
                $activated_passes = array();
                if ($hotel_overview_data[0]['is_prioticket'] == 1) {
                    foreach ($hotel_overview_data as $overview_row) {
                        $activated_passes[] = array(
                            'age' => $overview_row['user_age'],
                            'passNo' => $overview_row['passNo'],
                        );
                    }
                }
                $total_cancelled = 0;
                $used_tickets = 0;
                $total_tickets = 0;
                $total_combi_discount = 0;
                $total_discount_code_amount = 0; /* for total discount */
                $remaining_tickets = 1;
                $is_citycard = 0;
                $bleep_pass_no = array();

                if (!empty($sub_tickets)) {
                    foreach ($sub_tickets as $sub_ticket) {
                        $sub_ticket_capacity_ids = explode(',', $sub_ticket['shared_capacity_id']);
                        $sub_tickets_details[$sub_ticket['ticket_id']]['ticket_id'] = (int) $sub_ticket['ticket_id'];
                        $sub_tickets_details[$sub_ticket['ticket_id']]['ticket_title'] = isset($sub_ticket['title']) ? $sub_ticket['title'] : '';
                        $sub_tickets_details[$sub_ticket['ticket_id']]['is_reservation'] = ($sub_ticket['selected_date'] != '' && $sub_ticket['selected_date'] != '0' && $sub_ticket['from_time'] != '' && $sub_ticket['from_time'] !== '0' && $sub_ticket['to_time'] != '' && $sub_ticket['to_time'] != '0' ) ? 1 : 0;
                        $sub_tickets_details[$sub_ticket['ticket_id']]['shared_capacity_id'] = (!empty($sub_ticket_capacity_ids[0])) ? (int) $sub_ticket_capacity_ids[0] : 0;
                        $sub_tickets_details[$sub_ticket['ticket_id']]['own_capacity_id'] = (!empty($sub_ticket_capacity_ids[1])) ? (int) $sub_ticket_capacity_ids[1] : 0;
                        $sub_tickets_details[$sub_ticket['ticket_id']]['timeslot_type'] = ($sub_ticket['timeslot'] != '' && $sub_ticket['timeslot'] != null && $sub_ticket['timeslot'] != 0) ? $sub_ticket['timeslot'] : '';
                        $sub_tickets_details[$sub_ticket['ticket_id']]['selected_date'] = ($sub_ticket['selected_date'] == '' || $sub_ticket['selected_date'] == '0') ? '' : $sub_ticket['selected_date'];
                        $sub_tickets_details[$sub_ticket['ticket_id']]['from_time'] = ($sub_ticket['from_time'] == '' || $sub_ticket['from_time'] === '0') ? '' : $sub_ticket['from_time'];
                        $sub_tickets_details[$sub_ticket['ticket_id']]['to_time'] = ($sub_ticket['to_time'] == '' || $sub_ticket['to_time'] == '0') ? '' : $sub_ticket['to_time'];
                        $sub_tickets_details[$sub_ticket['ticket_id']]['supplier_name'] = isset($sub_ticket['museum_name']) ? $sub_ticket['museum_name'] : '';
                        $sub_tickets_details[$sub_ticket['ticket_id']]['supplier_id'] = isset($sub_ticket['museum_id']) ? (int) $sub_ticket['museum_id'] : '';
                        if ($sub_ticket['is_refunded'] == '2') {
                            if (!in_array($sub_ticket['passNo'], $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'])) {
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'][] = $sub_ticket['passNo'];
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'] : array();
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'] : array();
                            }
                        } else if ($sub_ticket['used'] == '1') {
                            if (!in_array($sub_ticket['passNo'], $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'])) {
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'][] = $sub_ticket['passNo'];
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'] : array();
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'] : array();
                            }
                        } else {
                            if (!in_array($sub_ticket['passNo'], $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'])) {
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['unused_passes'][] = $sub_ticket['passNo'];
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['used_passes'] : array();
                                $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'] = !empty($sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes']) ? $sub_tickets_details[$sub_ticket['ticket_id']]['passNo']['refunded_passes'] : array();
                            }
                        }
                    }
                }
                foreach ($order_details as $details) {
                    $index = $details['visitor_group_no'] . '_' . $details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['tps_id'];
                    $total_discount_code_amount += $details['discount_code_amount']; /* sum total of cashier discount on all types */
                    $total_combi_discount += $details['combi_discount_gross_amount'];
                    $total_cancelled += $details['cancel_tickets'];
                    $total_tickets += $details['total_tickets'];
                    $used_tickets += $details['used_tickets'];
                    //make array on the basis of tps_id
                    $booked_tickets[] = array(
                        "tps_id" => (int) $details['tps_id'],
                        "spot_details" => !empty($spot_detail[$index]) ? $spot_detail[$index] : array(),
                        "start_date" => $details['start_date'],
                        "end_date" => $details['end_date'],
                        "clustering_ids" => $details['cluster_ids'],
                        "ticket_type" => (int) $details['ticket_type'],
                        "ticket_type_label" => (string) $details['ticket_type_label'],
                        "age_group" => $details['age_group'],
                        "unit_price" => (float) $details['price'],
                        "quantity" => (int) $details['total_tickets'],
                        "pax" => isset($details['pax']) ? (int) $details['pax'] : (int) 0,
                        "capacity" => isset($details['capacity']) ? (int) $details['capacity'] : (int) 1,
                        "cancelled_tickets" => (int) $details['cancel_tickets'],
                        "price" => (float) ($details['price']) * $details['total_tickets'],
                        "net_price" => (float) $details['net_price'],
                        "original_price" => (float) $details['price'],
                        "combi_discount_gross_amount" => (float) $details['combi_discount_gross_amount'],
                        "passNo" => array(
                            'used_passes' => !empty($passes[$details['tps_id']]['used_passes']) ? $passes[$details['tps_id']]['used_passes'] : array(),
                            'unused_passes' => !empty($passes[$details['tps_id']]['unused_passes']) ? $passes[$details['tps_id']]['unused_passes'] : array(),
                            'refunded_passes' => !empty($passes[$details['tps_id']]['refunded_passes']) ? $passes[$details['tps_id']]['refunded_passes'] : array()
                        ),
                        "used_count" => (int) $details['used_tickets'],
                        "extra_options" => (isset($per_age_group_options[$details['tps_id']]) && !empty($per_age_group_options[$details['tps_id']])) ? $per_age_group_options[$details['tps_id']] : array()
                    );
                    $remaining_tickets = $total_tickets - ($used_tickets + $total_cancelled);
                    if (!empty($details['bleep_passes'])) {
                        $is_citycard = 0;
                        $bleep_pass_no = array_merge($bleep_pass_no, $details['bleep_passes']);
                    } else if ($countdown_tickets[$details['ticket_id']] == "1" && empty($details['bleep_passes'])) {
                        $is_citycard = 1;
                        $passes[$details['tps_id']]['used_passes'] = (empty($passes[$details['tps_id']]['used_passes'])) ? array() : $passes[$details['tps_id']]['used_passes'];
                        $bleep_pass_no = array_merge($bleep_pass_no, $passes[$details['tps_id']]['used_passes']);
                    }
                }
                //To show city card redemption details (list of city cards with sub tickets and redeption time)
                if (!empty(array_values($all_bleep_passes))) {
                    $all_pass_no = array_merge($bleep_pass_no, array_values($all_bleep_passes));
                } else {
                    $all_pass_no = $bleep_pass_no;
                }
                $all_pass_no = array_unique($all_pass_no);
                $bleep_passes = implode('","', $all_pass_no);
                $redeem_details = array();
                if ($bleep_passes != '') {
                    foreach ($bleep_pass_no as $pass) {
                        $redeem_details[$pass] = array(
                            "city_card_no" => (string) $pass,
                            "details" => array()
                        );
                    }
                    $redeem_details_data = $this->find('redeem_cashiers_details', array('select' => 'ticket_id, ticket_title, is_addon_ticket, pass_no, visitor_group_no, redeem_time', 'where' => 'visitor_group_no = "' . $data['order_id'] . '" and pass_no in ("' . $bleep_passes . '") and (ticket_id in (' . implode(',', array_keys($countdown_tickets)) . ') or is_addon_ticket = "2")', 'order_by' => 'redeem_time ASC'));
                    $logs['redeem_cashiers_details_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                    $internal_log['redeem_cashiers_details_response_' . date('H:i:s')] = $redeem_details_data;
                    if (sizeof($redeem_details_data) > 0) {
                        foreach ($redeem_details_data as $redeems) {
                            $card_details[$redeems['pass_no']][$redeems['ticket_id'] . '_' . $redeems['tps_id']][] = array(
                                'title' => ($redeems['is_addon_ticket'] == '0' && $countdown_tickets[$redeems['ticket_id']] == "1") ? 'Bus Redemption' : $redeems['ticket_title'],
                                'redeem_time' => $redeems['redeem_time'],
                            );
                        }
                        //Make array for citycards detaila that will show on app detail screen popup
                        foreach ($card_details as $pass => $details) {
                            foreach ($details as $ticket => $values) {
                                $ticket_details = explode('_', $ticket);
                                $redeemed_ticket_id = $ticket_details[0];
                                $redeemed_tps_id = $ticket_details[1];
                                $pass_no = $pass;
                                if (!in_array($pass, $bleep_pass_no)) {
                                    $pass_no = array_search($pass . '_' . $ticket, $all_passes);
                                    $pas_details = explode('_', $pass_no);

                                    if ($pas_details[1] == $redeemed_ticket_id && $pas_details[2] == $redeemed_tps_id) {
                                        $pass_no = $pas_details[0];
                                    } else {
                                        $pass_no = '';
                                    }
                                }

                                if ($pass_no != '') {
                                    if (empty($redeem_details[$pass_no]['details'])) {
                                        $redeem_details[$pass_no]['details'] = array();
                                    }
                                    $redeem_details[$pass_no] = array(
                                        "city_card_no" => (string) $pass_no,
                                        "details" => array_merge($redeem_details[$pass_no]['details'], $values)
                                    );
                                }
                            }
                        }
                    }
                    $redeem_details = array_values($redeem_details);
                }

                if ($order_details[0]['activation_method'] == '0') {
                    if ($hotel_overview_data[0]['isBillToHotel'] == 0) {
                        $order_details[0]['activation_method'] = '1';
                    } else {
                        $order_details[0]['activation_method'] = '3';
                    }
                }
                if ($order_details[0]['without_elo_reference_no'] != '' && $order_details[0]['without_elo_reference_no'] != NULL) {
                    $order_reference = $order_details[0]['without_elo_reference_no'];
                } else if ($hotel_overview_data[0]['roomNo'] != '') {
                    $order_reference = $hotel_overview_data[0]['roomNo'];
                }
                $is_cancelled = 0;
                $order_status = 'Confirmed';
                //check order status on the basis of cancelled tickets
                if ($total_cancelled > 0) {
                    if ($total_cancelled == $total_tickets) {
                        $is_cancelled = 1;
                        $order_status = 'Refunded';
                    } else {
                        $order_status = $total_cancelled . ' Refunded';
                    }
                }
                if ($order_details[0]['selected_date'] != '' && $order_details[0]['selected_date'] != '0') {
                    $reservation_date = $order_details[0]['selected_date'];
                    $from_time = $order_details[0]['from_time'];
                    $to_time = $order_details[0]['to_time'];
                } else {
                    $reservation_date = '';
                    $from_time = '';
                    $to_time = '';
                }
                // check if requested booking can be cancelled or not
                $can_cancelled = 1;
                $cancel_reason = '';
                if ($third_party_exist == 1) {
                    $can_cancelled = 0;
                    $cancel_reason = 'third_party_tickets';
                }
                if ($third_party_exist == 1 && $third_party_details['third_party'] == 17) {
                    $can_cancelled = 1;
                    $cancel_reason = 'Enviso third_party_tickets';
                }
                if ($is_upsell == "1") {
                    $can_cancelled = 0;
                    $cancel_reason = 'upsell'; //24 hr ticket
                }
                if ($is_upsell_main == "1") { //extended (48 hr ticket) can be cancelled only by supervisor and admin
                    $can_cancelled = 3;
                    $cancel_reason = 'upsell showing to supervisor or admin';
                }
                if ($order_status == 'Refunded') {
                    $can_cancelled = 0;
                    $cancel_reason = 'refunded_tickets';
                }
                if ($barcode_type_exist == 1) {
                    $can_cancelled = 0;
                    $cancel_reason = 'barcode_type_ticket';
                }
                if (($data['cashier_type'] == "6" && $bleep_pass_no != '' && $redeem_users != '') || ($remaining_tickets == 0 && $data['cashier_type'] == "1")) { //streetseller or cashier can't cancel their user tickets.
                    $can_cancelled = 0;
                    $cancel_reason = 'all tickets are either used or refunded';
                }
                if ($is_combi_discount == 1) {
                    if (($can_cancelled == 1 || $can_cancelled == 2) && ($third_party_exist != 2 || $barcode_type_exist != 2)) {
                        $can_cancelled = 2;
                        $cancel_reason = 'combi_discount';
                    } else {
                        $can_cancelled = 0;
                        $cancel_reason = 'combi_discount_' . $cancel_reason;
                    }
                }
                if ($voucher_order == 1) {
                    $can_cancelled = 0;
                    $cancel_reason = 'voucher orders can not be refunded';
                }
                if ($exception_order == 1) {
                    $can_cancelled = 0;
                    $cancel_reason = 'exception orders can not be refunded';
                }
                if ($is_voucher == 1 && $citycard_cancel == 1) {
                    $can_cancelled = 1;
                    $cancel_reason = '';
                } else if ($is_voucher == 1 && $citycard_cancel == 0) {
                    $can_cancelled = 0;
                    $cancel_reason = "we can't cancel citycard if the order is extended";
                }
                if ($citycard_cancel == 2) {
                    $can_cancelled = 0;
                    $cancel_reason = "Already cancelled";
                    $order_status = 'Cancelled';
                }
                if ($citycard_cancel == 3) {
                    $can_cancelled = 1;
                    $cancel_reason = "";
                    $order_status = 'Confirmed';
                }
                // send details of requested bookings.
                if ($mpos_settings['allow_reprint'] == '1' && $total_tickets > $total_cancelled && ($order_details[0]['channel_type'] == '10' || $order_details[0]['channel_type'] == '11') && ($active_ticket === '' || $active_ticket != 0)) {
                    $allow_reprint = 1;
                } else {
                    $allow_reprint = 0;
                }
                $order = array(
                    'order_overview_type' => isset($mpos_settings['order_overview_type']) ? (int) $mpos_settings['order_overview_type'] : 1,
                    "supplier_id" => ($order_details[0]['museum_id'] != '' && $order_details[0]['museum_id'] != null) ? (int) $order_details[0]['museum_id'] : '',
                    "can_cancelled" => isset($can_cancelled) ? (int) $can_cancelled : (int) 1,
                    'cancel_reason' => $cancel_reason,
                    "order_id" => $order_details[0]['visitor_group_no'],
                    "is_service_cost" => (int) 0,
                    "service_cost" => (float) 0,
                    "order_status" => $order_status,
                    "is_upsell" => $order_details[0]['is_upsell'],
                    "channel_name" => ($hotel_overview_data[0]['distributor_type'] != '') ? $hotel_overview_data[0]['distributor_type'] : '',
                    "client_reference" => ($order_reference != '' && $order_reference != null) ? $order_reference : '',
                    "payment_method" => (int) $order_details[0]['activation_method'],
                    "booking_date_time" => $order_details[0]['created_date_time'],
                    "pos_point_name" => ($order_details[0]['pos_point_name'] != '') ? $order_details[0]['pos_point_name'] : '',
                    "selected_date" => $reservation_date,
                    "from_time" => $from_time,
                    "is_third_party" => (!empty($third_party_details)) ? (int) 1 : (int) 0,
                    'third_party_details' => (!empty($third_party_details)) ? $third_party_details : (object) array(),
                    "is_reservation" => $order_details[0]['is_reservation'],
                    "to_time" => $to_time,
                    "is_cancelled" => (int) $is_cancelled,
                    'activated_passes' => (!empty($activated_passes)) ? $activated_passes : array(),
                    'bleep_passes' => (!empty($bleep_pass_no) && $is_citycard == 0) ? $bleep_pass_no : array(),
                    'is_citycard' => $is_citycard,
                    'city_cards' => (!empty($redeem_details)) ? $redeem_details : array(),
                    "timeslot_type" => ($order_details[0]['timeslot'] != '' && $order_details[0]['timeslot'] != null) ? $order_details[0]['timeslot'] : '',
                    "ticket_id" => (int) $order_details[0]['ticket_id'],
                    "product_type" => isset($data['product_type']) ? (int) $data['product_type'] : 0,
                    "ticket_title" => $order_details[0]['title'],
                    "guest_notification" => $details['guest_notification'],
                    "is_discount_code" => (isset($order_details[0]['is_discount_code'])) ? (int) $order_details[0]['is_discount_code'] : (int) 0,
                    "discount_code_value" => (isset($order_details[0]['discount_code_value'])) ? $order_details[0]['discount_code_value'] : '',
                    "discount_code_amount" => ($total_discount_code_amount > 0) ? (float) $total_discount_code_amount : (int) 0,
                    "ticket_location" => ($ticket_location_details[0]['targetlocation'] != '') ? $ticket_location_details[0]['targetlocation'] . ', ' . $ticket_location_details[0]['targetcity'] . ', ' . $ticket_location_details[0]['targetcountry'] : '',
                    "list_price_on_ticket" => (int) $hotel_overview_data[0]['show_ticket_price'],
                    "booking_name" => ($order_details[0]['name'] != '') ? $order_details[0]['name'] : '',
                    "booking_email" => ($order_details[0]['email'] != '') ? $order_details[0]['email'] : '',
                    "pass_type" => (int) $order_details[0]['pass_type'] + 1,
                    "booked_tickets" => (!empty($booked_tickets)) ? $booked_tickets : array(),
                    "sub_tickets" => (!empty($sub_tickets_details)) ? array_values($sub_tickets_details) : array(),
                    "per_ticket_extra_option" => (!empty($per_ticket_extra_options)) ? $per_ticket_extra_options : array(),
                    "is_combi_ticket" => (int) $order_details[0]['is_combi_ticket'],
                    "combi_discount_amount" => (float) $order_details[0]['combi_discount_gross_amount'],
                    "Combi_discount_amount_per_ticket" => (float) $order_details[0]['combi_discount_gross_amount'],
                    "total_combi_discount" => (float) $total_combi_discount,
                    "gross_discount_amount" => (float) $order_details[0]['discount'],
                    "timezone" => (int) $order_details[0]['timezone'],
                    "shared_capacity_id" => (int) $order_details[0]['shared_capacity_id'],
                    "own_capacity_id" => (int) $order_details[0]['own_capacity_id'],
                    "note" => ($order_details[0]['note'] != '' && $order_details[0]['note'] != null) ? $order_details[0]['note'] : '',
                    "merchant_reference" => ($hotel_overview_data[0]['merchantReference'] != '' && $hotel_overview_data[0]['merchantReference'] != null) ? $hotel_overview_data[0]['merchantReference'] : ''
                );
                $response = array(
                    'is_voucher' => (int) $is_voucher,
                    "supplier_name" => ($order_details[0]['museum_name'] != '' && $order_details[0]['museum_name'] != null) ? $order_details[0]['museum_name'] : '',
                    "sold_by" => ($order_details[0]['cashier_name'] != '' && $order_details[0]['cashier_name'] != null) ? $order_details[0]['cashier_name'] : '',
                    "attended_by" => ($order_details[0]['cashier_name'] != '' && $order_details[0]['cashier_name'] != null) ? $order_details[0]['cashier_name'] : '',
                    "add_to_pass" => isset($allow_city_card) ? (int) $allow_city_card : 0,
                    "allow_reprint" => isset($allow_reprint) ? (int) $allow_reprint : 1,
                    "cashier_id" => ($order_details[0]['cashier_id'] != '' && $order_details[0]['cashier_id'] != null) ? (int) $order_details[0]['cashier_id'] : (int) 0,
                    "cashier_name" => ($order_details[0]['cashier_name'] != '' && $order_details[0]['cashier_name'] != null) ? $order_details[0]['cashier_name'] : '',
                    "hotel_name" => ($order_details[0]['hotel_name'] != '' && $order_details[0]['hotel_name'] != null) ? $order_details[0]['hotel_name'] : '',
                    "channel_type" => ($order_details[0]['channel_type'] != '' && $order_details[0]['channel_type'] != null) ? (int) $order_details[0]['channel_type'] : (int) 0,
                    "hotel_id" => ($order_details[0]['hotel_id'] != '' && $order_details[0]['hotel_id'] != null) ? (int) $order_details[0]['hotel_id'] : (int) 0,
                    "activated_at" => ($order_details[0]['activated_at'] != '' && $order_details[0]['activated_at'] != null) ? $order_details[0]['activated_at'] : '',
                    "activated_by_id" => ($order_details[0]['activated_by_id'] != '' && $order_details[0]['activated_by_id'] != null) ? (int) $order_details[0]['activated_by_id'] : 0,
                    "activated_by_name" => ($order_details[0]['activated_by_name'] != '' && $order_details[0]['activated_by_name'] != null) ? $order_details[0]['activated_by_name'] : '',
                    "group_name" => ($order_details[0]['group_name'] != '' && $order_details[0]['group_name'] != null) ? $order_details[0]['group_name'] : '',
                    "voucher_partner" => ($order_details[0]['voucher_partner'] != '' && $order_details[0]['voucher_partner'] != null) ? $order_details[0]['voucher_partner'] : '',
                    "voucher_category" => ($order_details[0]['voucher_category'] != '' && $order_details[0]['voucher_category'] != null) ? $order_details[0]['voucher_category'] : '',
                    "order_details" => (!empty($order)) ? $order : array()
                );
            }
            if (!empty($response)) {
                $response['status'] = (int) 1;
            } else {
                $response = $this->exception_handler->show_error();
            }
            $MPOS_LOGS['order_details'] = $logs;
            $internal_logs['order_details'] = $internal_log;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['order_details'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name order_details_on_cancel
     * @Purpose This function is used to send all the details for any order .
     * It is called at the time of cancel complete or partial order
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 22 Dec 2017
     */
    function order_details_on_cancel($hotel_id = '', $data = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $logs['hotel_id'] = $hotel_id;
            $response = array();
            $pass_no = $data['pass_no'];
            if ($data['cancel_time_limit'] != '') {
                $cancel_time_limit = $data['cancel_time_limit'];
                $cancelled_time = gmdate("Y-m-d H:i:s", strtotime('-' . $cancel_time_limit));
            }
            $cancel_from_overview = (isset($data['cancel_from_overview']) && $data['cancel_from_overview'] == "1") ? "1" : "0";
            $cond = "";
            if(is_numeric($pass_no)) {
                $cond = 'visitor_group_no = "' . $pass_no . '" or ';    
            }
            /* Get visitor_group_no from pass_no */
            $order = $this->find('prepaid_tickets', array('select' => 'visitor_group_no, created_date_time, action_performed, is_refunded', 'where' => 'hotel_id = "' . $hotel_id . '" and ( '. $cond . ' passNo = "' . $pass_no . '" or bleep_pass_no = "' . $pass_no . '") and channel_type != "5"'));
            $logs['all_orders'] = $order;
            $vgn = '';
            $logs['pt_query1'] = $this->primarydb->db->last_query();
            if (isset($order[0]['visitor_group_no']) && $order[0]['visitor_group_no'] != '') {
		foreach($order as $id) {
                    if($id['is_refunded'] == '0' && $vgn == '') {
                        $vgn = $id['visitor_group_no'];
                    }
                }
                $logs['vgn'] = $vgn;
                if ($vgn != '') {
                    $query = 'select pax, capacity, extra_booking_information, museum_name,bleep_pass_no,redeem_users,activated,additional_information,action_performed, is_addon_ticket, cluster_group_id ,clustering_id,visitor_group_no, order_status, used, is_refunded, timezone,cashier_id, is_prioticket, extra_text_field_answer, is_prioticket, activation_method, channel_type, without_elo_reference_no, extra_discount, pass_type, discount, created_date_time, timezone, selected_date, from_time, to_time, timeslot, ticket_id, title, tps_id, ticket_type, age_group, price, net_price, quantity, passNo, is_combi_ticket, is_combi_discount, combi_discount_gross_amount, third_party_type';
                    $query .= ' from prepaid_tickets where hotel_id = "' . $hotel_id . '" and visitor_group_no = "' . $vgn . '" and is_refunded = "0"';
                    $booking_details = $this->query($query, 1);
                    $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                    $internal_log['pt_response_' . date('H:i:s')] = $booking_details;
                    if (!empty($booking_details)) {
                        if (isset($data['cashier_id']) || $data['cashier_type'] == "2" || $data['cashier_type'] == "3") {
                            /* Get details from prepaid tickets */
                            if (($data['cancel_time_limit'] != '' && $booking_details[0]['created_date_time'] > $cancelled_time) || $cancel_from_overview == "1" || $data['cashier_type'] == "2" || $data['cashier_type'] == "3") {
                                $passes = array();
                                $used = 1;
                                $unused = 1;
                                $is_combi_discount = 0;
                                $third_party_ticket = 0;
                                $barcode_tickets = 0;
                                $voucher_order = 0;
                                $exception_order = 0;
                                $discount_code = '0';
                                $cluster_tickets_array = array();
                                $discount_code_amount = 0;
                                $is_upsell = 0;
                                $cashier_error = 0;
                                $is_upsell_main = 0;
                                $canceled_cluster_tickets_array = array();
                                foreach ($booking_details as $row) {
                                    //Admin and supervisor can scan any order but other user_types can scan only their own orders
                                    if ($data['cashier_type'] != "2" && $data['cashier_type'] != "3" && $row['cashier_id'] == $data['cashier_id']) {
                                        $prepaid_booking_details[] = $row;
                                    } else if ($data['cashier_type'] == "2" || $data['cashier_type'] == "3") {
                                        $prepaid_booking_details[] = $row;
                                    } else {
                                        $cashier_error = 1;
                                    }
                                    $extra_discount = unserialize($row['extra_discount']);
                                    if ($row['is_addon_ticket'] == "0" && !empty($extra_discount)) {
                                        $discount_code_amount += $extra_discount['gross_discount_amount'];
                                        $discount_code_value = $extra_discount['discount_label'];
                                    }
                                    if ($row['cluster_group_id'] != "" && $row['cluster_group_id'] != NULL && $row['cluster_group_id'] != 0) {
                                        if ((isset($cluster_tickets_array[$row['clustering_id']]) && $cluster_tickets_array[$row['clustering_id']] == 0) || !isset($cluster_tickets_array[$row['clustering_id']])) {
                                            $cluster_tickets_array[$row['clustering_id']] = $row['used'];
                                        }
                                        if ((isset($canceled_cluster_tickets_array[$row['clustering_id']]) && $canceled_cluster_tickets_array[$row['clustering_id']] != 0) || !isset($canceled_cluster_tickets_array[$row['clustering_id']])) {
                                            $canceled_cluster_tickets_array[$row['clustering_id']] = $row['is_refunded'];
                                        }
                                    }
                                }
                                foreach ($prepaid_booking_details as $row) {
                                    if ($row['cluster_group_id'] != "" && $row['cluster_group_id'] != NULL && $row['cluster_group_id'] != 0 && $row['is_addon_ticket'] == "0" && $canceled_cluster_tickets_array[$row['clustering_id']] == "0" && (($cluster_tickets_array[$row['clustering_id']] != "1") || $data['cashier_type'] == "2" || $data['cashier_type'] == "3" || $data['cashier_type'] == "5" || ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == ''))) {
                                        $cluster_details_array[$row['ticket_id'] . '_' . $row['tps_id']][$row['clustering_id']] = $row['used'];
                                    }
                                    if ($row['is_addon_ticket'] != "2") {
                                        $visitor_group_no = $row['visitor_group_no'];
                                        /* Check if any ticket in order has combi discount */
                                        if ($row['combi_discount_gross_amount'] > 0) {
                                            $is_combi_discount = 1;
                                        }
                                        if ($row['activation_method'] == "10") {
                                            $voucher_order = 1;
                                        }
                                        if ($row['activation_method'] == "19") {
                                            $exception_order = 1;
                                        }
                                        /* Get third party details */
                                        $ticket_details = $this->find('modeventcontent', array('select' => 'third_party_id, barcode_type', 'where' => 'mec_id = "' . $row['ticket_id'] . '"'));
                                        $third_party_details = array();
                                        if ($ticket_details[0]['third_party_id'] != '' && $ticket_details[0]['third_party_id'] > 0) {
                                            $third_party = 1;
                                            $third_party_details = array(
                                                'third_party' => (int) $ticket_details[0]['third_party_id']
                                            );
                                        } else {
                                            $third_party = 0;
                                        }
                                        if ($row['third_party_type'] == 5) {
                                            $third_party = 5;
                                            $third_party_details = array(
                                                'third_party' => (int) 5
                                            );
                                        }
                                        $third_party_type = ($third_party != 0) ? 1 : 0;
                                        /* Check if any third party ticket exist in order */
                                        if ($third_party_type == 1 && $third_party != 5) {
                                            $third_party_ticket = 1;
                                        }
                                        /* Check if any barcode type ticket exist in order */
                                        if ($ticket_details[0]['barcode_type'] != 0) {
                                            $barcode_tickets = 1;
                                        }
                                        $device_details = unserialize($row['additional_information']);
                                        if ($data['cashier_type'] == "2" || $data['cashier_type'] == "3" || $data['cashier_type'] == "5" || ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '')) {
                                            $row['used'] = "0";
                                        }
                                        /* Get all passes */
                                        if ($row['used'] == 1 && $row['passNo'] != '' && $row['is_refunded'] == '0') {
                                            if ($row['is_combi_ticket'] == 1) {
                                                if ($used == 1) {
                                                    $passes[$row['ticket_id']][$row['tps_id']]['used_passes'][] = $row['passNo'];
                                                }
                                            } else {
                                                $passes[$row['ticket_id']][$row['tps_id']]['used_passes'][] = $row['passNo'];
                                            }
                                        } else if ($row['used'] == 0 && $row['passNo'] != '' && $row['is_refunded'] == '0') {
                                            if ($row['is_combi_ticket'] == 1) {
                                                if ($unused == 1) {
                                                    $passes[$row['ticket_id']][$row['tps_id']]['unused_passes'][] = $row['passNo'];
                                                }
                                            } else {
                                                $passes[$row['ticket_id']][$row['tps_id']]['unused_passes'][] = $row['passNo'];
                                            }
                                        }
                                        $used_passes = array_unique($passes[$row['ticket_id']][$row['tps_id']]['used_passes']);
                                        $unused_passes = array_unique($passes[$row['ticket_id']][$row['tps_id']]['unused_passes']);
                                        sort($used_passes);
                                        sort($unused_passes);
                                        $extra_discount = unserialize($row['extra_discount']);
                                        $passes[$row['ticket_id']][$row['tps_id']]['used_passes'] = $used_passes;
                                        $passes[$row['ticket_id']][$row['tps_id']]['unused_passes'] = $unused_passes;
                                        /* Get location of ticket */
                                        $ticket_location_details = $this->find('rel_targetvalidcities', array('select' => 'targetlocation, targetcity, targetcountry', 'where' => 'module_item_id = "' . $row['ticket_id'] . '"'));
                                        $cancelled_tickets[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']] = array(
                                            'total_tickets' => $cancelled_tickets[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['total_tickets'] + $row['quantity'],
                                            'cancelled_tickets' => ($row['is_refunded'] == 2) ? ($cancelled_tickets[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['cancelled_tickets'] + 1) : $cancelled_tickets[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['cancelled_tickets'],
                                        );
                                        $ticket_type = ucfirst(strtolower($row['ticket_type']));
                                        $key = $row['visitor_group_no'] . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time'] . '_' . $row['tps_id'] . '_' . $row['created_date_time'];
                                        $used_cluster_ticket = 0;
                                        $canceled_cluster_ticket = 0;
                                        if ($row['cluster_group_id'] != "" && $row['cluster_group_id'] != NULL && $row['cluster_group_id'] != 0) {
                                            if (isset($cluster_tickets_array[$row['clustering_id']]) && $cluster_tickets_array[$row['clustering_id']] == "1") {
                                                $used_cluster_ticket = 1;
                                                if ($data['cashier_type'] == "2" || $data['cashier_type'] == "3" || $data['cashier_type'] == "5" || ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '')) {
                                                    $used_cluster_ticket = 0;
                                                }
                                            }
                                            if (isset($canceled_cluster_tickets_array[$row['clustering_id']]) && $canceled_cluster_tickets_array[$row['clustering_id']] != "0") {
                                                $canceled_cluster_ticket = 1;
                                            }
                                        }
                                        if ($discount_code_amount > 0 && $discount_code == "0") {
                                            $discount_code = '1';
                                        }
                                        if (strpos($row['action_performed'], 'UPSELL') !== false && $row['activated'] == '0' && $row['is_addon_ticket'] != '2') {
                                            $is_upsell_main = 1;
                                        } else {
                                            $is_upsell_main = 0;
                                        }
                                        if (strpos($row['action_performed'], 'UPSELL') !== false) {
                                            $is_upsell = 1;
                                            $action = 1;
                                        }
                                        if (strpos($row['action_performed'], 'UPSELL_INSERT') !== false) {
                                            $action = 2;
                                        }
                                        if ($row['activated'] == "0") {
                                            $activated[$row['ticket_id']] = "0";
                                        }
                                        $extra_info = json_decode($row['extra_booking_information'], true);
                                        $order_details[$key] = array(
                                            'action' => isset($action) ? (int) $action : 0,
                                            'visitor_group_no' => $row['visitor_group_no'],
                                            'is_cluster' => ($row['cluster_group_id'] != '') ? (int) 1 : (int) 0,
                                            'cluster_ids' => ($row['cluster_group_id'] != '') ? array_keys($cluster_details_array[$row['ticket_id'] . '_' . $row['tps_id']]) : array(),
                                            'museum_name' => $row['museum_name'],
                                            'cashier_id' => $row['cashier_id'],
                                            'order_status' => $row['order_status'],
                                            'activated' => $activated[$row['ticket_id']],
                                            'used' => $row['used'],
                                            'is_upsell_main' => $is_upsell_main,
                                            'channel_name' => $row['distributor_type'],
                                            'passNo' => $passes,
                                            "is_third_party" => (int) $third_party_type,
                                            'third_party_details' => (!empty($third_party_details)) ? $third_party_details : (object) array(),
                                            'activation_method' => $row['activation_method'],
                                            'without_elo_reference_no' => $row['without_elo_reference_no'],
                                            'pass_type' => $ticket_details[0]['barcode_type'],
                                            'discount' => $row['discount'],
                                            'created_date_time' => $row['created_date_time'],
                                            'timezone' => $row['timezone'],
                                            'created_at' => strtotime($row['created_date_time']),
                                            'selected_date' => $row['selected_date'],
                                            'from_time' => $row['from_time'],
                                            'note' => $row['extra_text_field_answer'],
                                            'to_time' => $row['to_time'],
                                            'timeslot' => $row['timeslot'],
                                            "ticket_location" => ($ticket_location_details[0]['targetlocation'] != '') ? $ticket_location_details[0]['targetlocation'] . ', ' . $ticket_location_details[0]['targetcity'] . ', ' . $ticket_location_details[0]['targetcountry'] : '',
                                            'ticket_id' => $row['ticket_id'],
                                            'title' => $row['title'],
                                            'guest_notification' => isset($extra_info['guest_notification']) ? $extra_info['guest_notification'] : '',
                                            'tps_id' => $row['tps_id'],
                                            'pax' => isset($row['pax']) ? (int) $row['pax'] : (int) 0,
                                            'capacity' => isset($row['capacity']) ? (int) $row['capacity'] : (int) 1,
                                            'start_date' => (isset($device_details['start_date']) && $device_details['start_date'] != '') ? $device_details['start_date'] : '',
                                            'end_date' => (isset($device_details['end_date']) && $device_details['end_date'] != '') ? $device_details['end_date'] : '',
                                            'ticket_type' => (int) (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? $this->TICKET_TYPES[$ticket_type] : 10,
                                            'ticket_type_label' => (string) $ticket_type,
                                            'is_discount_code' => ($discount_code_amount > 0) ? 1 : 0,
                                            'discount_code_amount' => (isset($discount_code_amount) && $discount_code_amount > 0) ? $discount_code_amount : 0,
                                            'discount_code_value' => ($discount_code_amount > 0) ? $discount_code_value : "",
                                            'age_group' => $row['age_group'],
                                            'price' => $row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount'],
                                            'net_price' => $row['net_price'],
                                            'quantity' => $row['quantity'],
                                            'oroginal_price' => $row['price'],
                                            'is_combi_ticket' => $row['is_combi_ticket'],
                                            'is_combi_discount' => $row['is_combi_discount'],
                                            'timezone' => $row['timezone'],
                                            'combi_discount_gross_amount' => $order_details[$key]['combi_discount_gross_amount'] + $row['combi_discount_gross_amount'],
                                            'total_combi_discount' => $order_details[$key]['total_combi_discount'] + $row['combi_discount_gross_amount'],
                                            'total_tickets' => (isset($order_details[$key]['total_tickets'])) ? ($order_details[$key]['total_tickets'] + $row['quantity']) : $row['quantity'],
                                            'cancel_tickets' => ($row['is_refunded'] == "2" || $canceled_cluster_ticket == "1") ? ($order_details[$key]['cancel_tickets'] + 1) : $order_details[$key]['cancel_tickets'],
                                            'used_tickets' => ($row['used'] == "1" || $used_cluster_ticket == "1") ? $order_details[$key]['used_tickets'] + 1 : $order_details[$key]['used_tickets'],
                                            'is_reservation' => ($row['selected_date'] != 0 && $row['selected_date'] != '' && $row['from_time'] != '' && $row['from_time'] !== '0') ? (int) 1 : (int) 0
                                        );
                                    }
                                }
                                $order_details = $this->common_model->sort_ticket_types($order_details);
                                $order_details = array_values($order_details);
                                /* Get details from hotel_ticket_overview */
                                $query1 = 'select isBillToHotel, roomNo, guest_names, is_prioticket, passNo, merchantReference, user_age, distributor_type, user_email, show_ticket_price, is_voucher, client_reference, merchantReference, visitor_group_no from hotel_ticket_overview where hotel_id = "' . $hotel_id . '" and visitor_group_no = "' . $visitor_group_no . '"';
                                $hotel_overview_data = $this->query($query1, 1);
                                $logs['hto_query_' . date('H:i:s')] = $query1;
                                $internal_log['hto_response_' . date('H:i:s')] = $hotel_overview_data;
                                $activated_passes = array();
                                if ($hotel_overview_data[0]['is_prioticket'] == 1) {
                                    foreach ($hotel_overview_data as $overview_row) {
                                        $activated_passes[] = array(
                                            'age' => $overview_row['user_age'],
                                            'passNo' => $overview_row['passNo'],
                                        );
                                    }
                                }
                                $total_cancelled = 0;
                                $total_tickets = 0;
                                $total_used_tickets = 0;
                                $total_combi_discount_on_order = 0;
                                $cancel_allowed = 0;
                                foreach ($order_details as $key => $details) {
                                    /* Get extra options purchased with that ticket */
                                    if ($details['selected_date'] != '' && $details['selected_date'] != 0 && $details['from_time'] != '' && $details['from_time'] !== '0') {
                                        $where = 'visitor_group_no = "' . $details['visitor_group_no'] . '" and ticket_id = "' . $details['ticket_id'] . '" and selected_date = "' . $details['selected_date'] . '" and from_time = "' . $details['from_time'] . '" and to_time = "' . $details['to_time'] . '" and is_cancelled = "0"';
                                    } else {
                                        $where = 'visitor_group_no = "' . $details['visitor_group_no'] . '" and ticket_id = "' . $details['ticket_id'] . '" and is_cancelled = "0"';
                                    }
                                    $extra_options = $this->find('prepaid_extra_options', array('select' => 'extra_option_id, ticket_id, description, price, quantity, refund_quantity, is_cancelled, tax, net_price, ticket_price_schedule_id, visitor_group_no, selected_date, from_time, to_time, variation_type', 'where' => $where));
                                    $per_ticket_extra_options = array();
                                    $per_age_group_options = array();
                                    $options = array();
                                    foreach ($extra_options as $option_details) {
                                        if($option_details['variation_type'] == 0){
                                            if ($option_details['is_cancelled'] != "1") {
                                                $extra_option_details = $this->find('ticket_extra_options', array('select' => 'optional_vs_mandatory, single_vs_multiple, main_description', 'where' => 'ticket_extra_option_id = "' . $option_details['extra_option_id'] . '"'));
                                                $options[$option_details['extra_option_id']][] = array(
                                                    'description' => $option_details['description'],
                                                    'quantity' => (int) ($option_details['quantity'] - $option_details['refund_quantity']),
                                                    'price' => (float) $option_details['price'],
                                                    'net_price' => (float) $option_details['net_price'],
                                                    'tax' => (float) $option_details['tax']
                                                );
                                                if ($option_details['ticket_price_schedule_id'] == 0 || $option_details['ticket_price_schedule_id'] == '') {
                                                    $per_ticket_extra_options[$option_details['ticket_id']][$option_details['extra_option_id']] = array(
                                                        'id' => (int) $option_details['extra_option_id'],
                                                        'main_description' => $extra_option_details[0]['main_description'],
                                                        'optional' => (int) $extra_option_details[0]['optional_vs_mandatory'],
                                                        'single' => (int) $extra_option_details[0]['single_vs_multiple'],
                                                        'options' => $options[$option_details['extra_option_id']]
                                                    );
                                                } else {
                                                    $per_age_group_options[$option_details['ticket_id']][$option_details['ticket_price_schedule_id']][$option_details['extra_option_id']] = array(
                                                        'id' => (int) $option_details['extra_option_id'],
                                                        'main_description' => $extra_option_details[0]['main_description'],
                                                        'optional' => (int) $extra_option_details[0]['optional_vs_mandatory'],
                                                        'single' => (int) $extra_option_details[0]['single_vs_multiple'],
                                                        'options' => $options[$option_details['extra_option_id']]
                                                    );
                                                }
                                            }
                                        }
                                    }
                                    if (isset($per_ticket_extra_options[$details['ticket_id']])) {
                                        sort($per_ticket_extra_options[$details['ticket_id']]);
                                    }
                                    if (isset($per_age_group_options[$details['ticket_id']][$details['tps_id']])) {
                                        sort($per_age_group_options[$details['ticket_id']][$details['tps_id']]);
                                    }
                                    $total_cancelled += $details['cancel_tickets'];
                                    $total_tickets += $details['total_tickets'];
                                    $total_used_tickets += $details['used_tickets'];
                                    /* Make array for ticket type details. */
                                    if ($details['cancel_tickets'] < $details['total_tickets'] && $details['total_tickets'] > $details['used_tickets']) {
                                        $ticket_type_details[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']][$details['tps_id']] = array(
                                            "tps_id" => (int) $details['tps_id'],
                                            'clustering_ids' => $details['cluster_ids'],
                                            "ticket_type" => (int) $details['ticket_type'],
                                            "ticket_type_label" => (string) $details['ticket_type_label'],
                                            "age_group" => $details['age_group'],
                                            'gross_discount_amount' => (float) $details['discount'],
                                            "unit_price" => (float) $details['price'],
                                            "quantity" => (int) ($details['total_tickets'] - $details['cancel_tickets'] - $details['used_tickets']),
                                            "pax" => (int) isset($details['pax']) ? $details['pax'] : 0,
                                            "capacity" => (int) isset($details['capacity']) ? $details['capacity'] : 1,
                                            "start_date" => (string) $details['start_date'],
                                            "end_date" => (string) $details['end_date'],
                                            "price" => (float) $details['price'] * ($details['total_tickets'] - $details['cancel_tickets']),
                                            "net_price" => (float) $details['net_price'],
                                            "original_price" => (float) $details['price'],
                                            "combi_discount_gross_amount" => (float) $details['combi_discount_gross_amount'],
                                            "passNo" => array(
                                                'used_passes' => !empty($passes[$details['ticket_id']][$details['tps_id']]['used_passes']) ? $passes[$details['ticket_id']][$details['tps_id']]['used_passes'] : array(),
                                                'unused_passes' => !empty($passes[$details['ticket_id']][$details['tps_id']]['unused_passes']) ? $passes[$details['ticket_id']][$details['tps_id']]['unused_passes'] : array()
                                            ),
                                            "used" => (int) $details['used_tickets'],
                                            "extra_options" => (isset($per_age_group_options[$option_details['ticket_id']][$details['tps_id']]) && !empty($per_age_group_options[$option_details['ticket_id']][$details['tps_id']])) ? $per_age_group_options[$option_details['ticket_id']][$details['tps_id']] : array()
                                        );
                                    }
                                    /*
                                      Check if any ticket is partially cancelled or not
                                      can_cancelled = 0 -> this ticket can't be cancelled => msg on app will be "partially cancellation not allowed" on tickets
                                      can_cancelled = 1 -> this ticket can be cancelled => partial cancel is also possible after tapping on ticket
                                      can_cancelled = 3 -> this ticket can be cancelled completely only (partial cancel after tapping on ticket is not possible)
                                     */
                                    $can_cancelled = 1;
                                    $cancel_reason = 'confirmed';
                                    if ($details['is_third_party'] == 1) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'third_party_tickets';
                                    }
                                    if ($is_upsell == "1" && $details['action'] == 2) {
                                        $can_cancelled = 3;
                                        $cancel_reason = 'upsell';
                                    }
                                    if (($details['is_upsell_main'] == '1' && !isset($main_cluster[$details['ticket_id']])) || $details['activated'] == "0") {
                                        $main_cluster[$details['ticket_id']] = '0';
                                        $can_cancelled = 0;
                                        $cancel_reason = 'upsell main ticket';
                                    } else if (isset($main_cluster[$details['ticket_id']])) {
                                        $can_cancelled = $main_cluster[$details['ticket_id']];
                                    }
                                    if ($discount_code == "1") {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'option_discount';
                                    }
                                    if ($total_cancelled >= $total_tickets) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'already_cancelled';
                                    }
                                    if ($is_combi_discount == 1) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'combi_discount';
                                    }
                                    if ($details['pass_type'] != 0) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'barcoide_type_ticket';
                                    }
                                    if ($total_tickets - ($total_cancelled + $total_used_tickets) < 1) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'all_tickets_is_either_redeemed_or_cancelled';
                                    }
                                    if ($voucher_order == 1) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'voucher_order_can_not_cancelled';
                                    }
                                    if ($details['activation_method'] == 19) {
                                        $can_cancelled = 0;
                                        $cancel_reason = 'exception_order_can_not_cancelled';
                                    }
                                    if ($can_cancelled == 1) {
                                        $cancel_allowed = 1;
                                    }
                                    $order_status = 'Confirmed';
                                    if ($cancelled_tickets[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']]['cancelled_tickets'] > 0) {
                                        if ($cancelled_tickets[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']]['cancelled_tickets'] == $cancelled_tickets[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time']]['total_tickets']) {
                                            $order_status = 'Refunded';
                                        } else {
                                            $order_status = $cancelled_tickets[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']]['cancelled_tickets'] . ' Refunded';
                                        }
                                    }
                                    $total_combi_discount_on_order += $details['total_combi_discount'];
                                    if (!empty($ticket_type_details[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']])) {
                                        sort($ticket_type_details[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']]);
                                        /* Make array for all tickets which are neither redeemed nor cancelled. */
                                        $all_tickets[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']] = array(
                                            "upsell_performed" => (int) $details['action'],
                                            "can_cancelled" => (int) $can_cancelled, //to show cancel button on each ticket (showing cancel ticket)
                                            "cancel_reason" => $cancel_reason,
                                            "is_upsell" => ($can_cancelled == '3') ? (int) 1 : (int) 0,
                                            "order_status" => $order_status,
                                            "booking_date_time" => $details['created_date_time'],
                                            "supplier_name" => $details['museum_name'],
                                            'pass_type' => (int) $details['pass_type'] + 1,
                                            'selected_date' => ($details['selected_date'] == "0") ? '' : $details['selected_date'],
                                            'from_time' => ($details['from_time'] == "0") ? '' : $details['from_time'],
                                            'to_time' => ($details['to_time'] == "0") ? '' : $details['to_time'],
                                            'timeslot_type' => ($details['timeslot'] != '' && $details['timeslot'] != "0") ? $details['timeslot'] : '',
                                            "is_third_party" => (int) $details['is_third_party'],
                                            'third_party_details' => $details['third_party_details'],
                                            "ticket_location" => $details['ticket_location'],
                                            'ticket_id' => (int) $details['ticket_id'],
                                            'title' => $details['title'],
                                            'guest_notification' => $details['guest_notification'],
                                            "is_combi_ticket" => (int) $details['is_combi_ticket'],
                                            'timezone' => $details['timezone'],
                                            'total_combi_discount_on_ticket' => $all_tickets[$details['ticket_id']]['total_combi_discount'] + $details['total_combi_discount'],
                                            'ticket_type_details' => (!empty($ticket_type_details[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']])) ? $ticket_type_details[$details['ticket_id'] . '_' . $details['selected_date'] . '_' . $details['from_time'] . '_' . $details['to_time'] . '_' . $details['created_date_time']] : array(),
                                            'is_reservation' => ($details['selected_date'] != '' && $details['selected_date'] != '0' && $details['from_time'] != "" && $details['from_time'] !== "0" && $details['to_time'] != "" && $details['to_time'] != "0") ? (int) 1 : (int) 0,
                                            "per_ticket_extra_option" => (!empty($per_ticket_extra_options[$option_details['ticket_id']])) ? $per_ticket_extra_options[$option_details['ticket_id']] : array()
                                        );
                                    }
                                }
                                sort($all_tickets);
                                $logs['tickets_detail_' . date('H:i:s')] = $all_tickets;

                                if ($order_details[0]['activation_method'] == '0') {
                                    if ($hotel_overview_data[0]['isBillToHotel'] == 0) {
                                        $order_details[0]['activation_method'] = '1';
                                    } else {
                                        $order_details[0]['activation_method'] = '3';
                                    }
                                }
                                if ($order_details[0]['without_elo_reference_no'] != '' && $order_details[0]['without_elo_reference_no'] != NULL) {
                                    $order_reference = $order_details[0]['without_elo_reference_no'];
                                } else if ($hotel_overview_data[0]['roomNo'] != '') {
                                    $order_reference = $hotel_overview_data[0]['roomNo'];
                                }
                                $order_status = 'Confirmed';
                                if ($total_cancelled > 0) {
                                    if ($total_cancelled == $total_tickets) {
                                        $order_status = 'Refunded';
                                    } else {
                                        $order_status = $total_cancelled . ' Refunded';
                                    }
                                }
                                //Check if the complete order can be cancelled or not
                                //$can_cancel_order = 0 -> this order can't be cancelled
                                //$can_cancel_order = 1 -> this order can be cancelled
                                $can_cancel_order = 1;
                                $cancel_reason = 'confirmed';
                                if ($third_party_ticket == 1) {
                                    $can_cancel_order = 0;
                                    $cancel_reason = 'third_party_ticket';
                                }

                                if ($barcode_tickets == 1) {
                                    $can_cancel_order = 0;
                                    $cancel_reason = 'barcode_type_ticket';
                                }
                                if ($total_tickets - ($total_cancelled + $total_used_tickets) < 1) {
                                    $can_cancel_order = 0;
                                    $cancel_reason = 'all_tickets_is_either_redeemed_or_cancelled';
                                }
                                if ($voucher_order == 1) {
                                    $can_cancel_order = 0;
                                    $cancel_reason = 'voucher_order_can_not_cancelled';
                                }
                                if ($exception_order == 1) {
                                    $can_cancel_order = 0;
                                    $cancel_reason = 'exception_order_can_not_cancelled';
                                }
                                if ($data['cashier_type'] != '2' && $data['cashier_type'] != '3' && $is_upsell == "1" && $action == 2) { //upsell insert enteries exists (action = 2) so full order cant be cancelled
                                    $can_cancel_order = 0;
                                    $cancel_reason = "normal user can't cancel complete order";
                                }
                                if ($can_cancel_order == 1) {
                                    $cancel_allowed = 1;
                                }
                                $order = array(
                                    "order_id" => $order_details[0]['visitor_group_no'],
                                    "order_status" => $order_status,
                                    'total_tickets' => $total_tickets,
                                    "channel_name" => ($hotel_overview_data[0]['distributor_type'] != '') ? $hotel_overview_data[0]['distributor_type'] : '',
                                    "client_reference" => ($order_reference != '' && $order_reference != null) ? $order_reference : '',
                                    "payment_method" => (int) $order_details[0]['activation_method'],
                                    "booking_date_time" => $order_details[0]['created_date_time'],
                                    'activated_passes' => (!empty($activated_passes)) ? $activated_passes : array(),
                                    "is_discount_code" => (int) $order_details[0]['is_discount_code'],
                                    "discount_code_value" => $order_details[0]['discount_code_value'],
                                    "discount_code_amount" => isset($order_details[0]['discount_code_amount']) ? (float) $order_details[0]['discount_code_amount'] : 0,
                                    "list_price_on_ticket" => (int) $hotel_overview_data[0]['show_ticket_price'],
                                    'total_combi_discount_on_order' => $total_combi_discount_on_order,
                                    "booking_name" => ($hotel_overview_data[0]['guest_names'] != '') ? $hotel_overview_data[0]['guest_names'] : '',
                                    "booking_email" => ($hotel_overview_data[0]['user_email'] != '') ? $hotel_overview_data[0]['user_email'] : '',
                                    "timezone" => (int) $order_details[0]['timezone'],
                                    "note" => ($order_details[0]['note'] != '' && $order_details[0]['note'] != null) ? $order_details[0]['note'] : '',
                                    "merchant_reference" => ($hotel_overview_data[0]['merchantReference'] != '' && $hotel_overview_data[0]['merchantReference'] != null) ? $hotel_overview_data[0]['merchantReference'] : '',
                                    'tickets' => (!empty($all_tickets)) ? $all_tickets : array()
                                );
                                $response = array(
                                    "supplier_name" => ($order_details[0]['museum_name'] != '' && $order_details[0]['museum_name'] != null) ? $order_details[0]['museum_name'] : '',
                                    "cashier_id" => ($order_details[0]['cashier_id'] != '' && $order_details[0]['cashier_id'] != null) ? (int) $order_details[0]['cashier_id'] : 0,
                                    "can_cancel_order" => isset($can_cancel_order) ? (int) $can_cancel_order : (int) 1, //to show bottom button (showing cancel order)
                                    'cancel_reason' => $cancel_reason,
                                    "order_details" => (!empty($order)) ? $order : array()
                                );
                                if ($cancel_allowed != 1 && empty($response['order_details']['tickets'])) {
                                    $response = array();
                                    $response = $this->exception_handler->show_error(11, 'VALIDATION_FAILURE', "You can't cancel this order");
                                }
                                if (empty($order['tickets'])) {
                                    $response = array();
                                    if ($cashier_error == "1") {
                                        $response = array();
                                        $response = $this->exception_handler->show_error(8, 'VALIDATION_FAILURE', "Can not be canceled by this user.");
                                    } else if ($order_details[0]['total_tickets'] == $order_details[0]['used_tickets']) {
                                        $response = $this->exception_handler->show_error(7, 'VALIDATION_FAILURE', "This order is already redeemed.");
                                    } else {
                                        $response = $this->exception_handler->show_error(4, 'VALIDATION_FAILURE', "Invalid Pass");
                                    }
                                }
                            } else {
                                $response = $this->exception_handler->show_error(3, 'VALIDATION_FAILURE', "Time to cancel this order has been expired.");
                            }
                        } else {
                            $response = array();
                            $response = $this->exception_handler->show_error(8, 'VALIDATION_FAILURE', "Can not be canceled by this user.");
                        }
                        if (!empty($response)) {
                            if (!empty($response['order_details']['tickets'])) {
                                $response['status'] = (int) 1;
                            }
                        } else {
                            $response = $this->exception_handler->show_error();
                        }
                    } else {
                        $response = $this->exception_handler->show_error(2, 'VALIDATION_FAILURE', "Order does not exists or already refunded.");
                    }
                } else {
                    $response = $this->exception_handler->show_error(2, 'VALIDATION_FAILURE', "Order does not exists or already refunded.");
                }
            } else {
                $response = $this->exception_handler->show_error(4, 'VALIDATION_FAILURE', "Invalid Pass");
            }
            $MPOS_LOGS['order_details_on_cancel'] = $logs;
            $internal_logs['order_details_on_cancel'] = $internal_log;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['order_details_on_cancel'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name update_order
     * @Purpose This function is used to update date and time for reservation tickets.
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function update_order($hotel_id = '', $data = array(), $timezone = '') {
        try {
            global $MPOS_LOGS;
            global $internal_logs;
            $logs['hotel_id'] = $hotel_id;
            $sns_messages = $insertion_db2 = $visitor_data = $prepaid_data = array();

            $visitor_group_no = $data['order_id'];
            $booking_id = isset($data['booking_id']) ? $data['booking_id'] : '';
            $ticket_status = isset($data['ticket_status']) ? $data['ticket_status'] : 0;
            $ticket_id = $data['ticket_id'];
            $data['selected_date'] = date("Y-m-d", strtotime($data['selected_date']));
            $visitor_data['selected_date'] = $prepaid_data['selected_date'] = $data['selected_date'];
            $visitor_data['from_time'] = $prepaid_data['from_time'] = $start_time = $data['from_time'];
            $visitor_data['to_time'] = $prepaid_data['to_time'] = $end_time = $data['to_time'];
            $visitor_data['slot_type'] = $prepaid_data['timeslot'] = $slot_type = $data['timeslot'];
            $created_date_time = $data['booking_date_time'];
            $main_ticket_check = '';

            if(!empty($data['main_ticket']) && $data['main_ticket'] != $ticket_id) {
                $main_ticket_check = ' or main_ticket_id = "' . $data['main_ticket'] . '" ';
            }
            $dependent_tickets = $this->find('dependencies', array('select' => '*', 'where' => 'main_ticket_id = "' . $ticket_id . '"'. $main_ticket_check), 'array');
            $logs['dependencies_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $allow_reservation_update = 1;
            if (!empty($dependent_tickets)) {
                foreach ($dependent_tickets as $dependent_ticket) {                    
                    if (strpos($dependent_ticket['booking_start_time'], $ticket_id)) {
                        $allow_reservation_update = 0;
                    }
                }
            }

            $logs['allow_reservation_update' . date('H:i:s')] = $allow_reservation_update; 
            if ($allow_reservation_update == 1) {
                /*  Fetch prepaid ticket details */
                if (isset($booking_id) && is_array($booking_id)) { 
                    $where_check = ' prepaid_ticket_id in (' . implode(',', $this->primarydb->db->escape($booking_id)) . ') ';
                } else if ($booking_id != '') { //updations from WAT
                    $where_check = ' visitor_group_no =' . $this->primarydb->db->escape($visitor_group_no) . ' and prepaid_ticket_id = ' . $this->primarydb->db->escape($booking_id) . ' ';
                } else {
                    $where_check = ' hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no =' . $this->primarydb->db->escape($visitor_group_no) . '';
                }
                $where = $where_check . ' and ticket_id =' . $ticket_id . ' and is_refunded = 0 and is_refunded = 0';
                $result = $this->find('prepaid_tickets', array('select' => 'payment_conditions, capacity, used, museum_id, channel_type, selected_date, from_time, to_time, group_price, timezone, timeslot, hotel_id, cashier_id, created_date_time, sum(group_quantity) as group_count, count(*) as count1, GROUP_CONCAT(prepaid_ticket_id) as pt_ids, GROUP_CONCAT(visitor_tickets_id) as vt_ids ', 'where' => $where, 'group_by' => 'selected_date, from_time, to_time, tps_id'), 'object');
                $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['pt_response_' . date('H:i:s')] = $result;
                $total_count = 0;
                if (!empty($result)) {
                    $types_data = $result;
                    $vt_ids_group = array();
                    foreach ($types_data as $types) {
                        $total_count = $total_count + ($types->count1 * $types->capacity);
                        $group_count = $group_count + $types->group_count;
                        $vt_ids_array = explode(',', $types->vt_ids);
                        foreach($vt_ids_array as $id) {
                            $vt_ids_group[] = $id;
                            for ($i = 1; $i <= $this->vt_max_rows ; $i++) {
                                $actual_vt_id = substr($id, 0, -2);
                                if($i < 10) {
                                    $vt_ids_group[] = $actual_vt_id.'0'.$i;
                                } else {
                                    $vt_ids_group[] = $actual_vt_id.$i;
                                }
                            }
                        }
                    }
                    $ticket_data = $types_data[0];

                    //Update capacity in DB, Redis and Firebase
                    if ($ticket_data->used == "0") {
                        $selected_date = $ticket_data->selected_date;
                        $from_time = $ticket_data->from_time;
                        $to_time = $ticket_data->to_time;
                        if ($ticket_data->group_price == '1') {
                            $total_count = $group_count;
                        }
                        $timeslot_type = $ticket_data->timeslot;
                        /* Reduce previous reservation capacity from ticket_capacity_v1 and from REDIS server */
                        if (!empty($ticket_data)) {
                            $logs['update_prev_capacity_data_' . date('H:i:s')] = array('ticket_id' => $ticket_id, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $total_count, 'timeslot_type' => $timeslot_type);
                            $this->update_prev_capacity($ticket_id, $selected_date, $from_time, $to_time, $total_count, $timeslot_type);
                        }
                        $this->update_capacity($ticket_id, $data['selected_date'], $start_time, $end_time, $total_count, $slot_type);
                        if ($ticket_status == 3) { //timebased redeemed ticket -> msg according to activation time
                            $arrival_status = 3;
                            $pymnt_cndtns = new \DateTime(date("Y-m-d H:i:s", $ticket_data->payment_conditions));
                            $logs['Current__paymnet_conditions_' . $i] = array(strtotime(gmdate("Y-m-d H:i:s")), $ticket_data->payment_conditions);
                            $time_left = $pymnt_cndtns->diff(new \DateTime(date("Y-m-d H:i:s")));
                            $logs['time_left_' . $i] = $time_left;
                            $hours = ($time_left->d > 0) ? $time_left->d * 24 : 0;
                            $hrs = ($time_left->h > 0) ? $hours + $time_left->h : $hours;
                            $mint = $time_left->i;
                            $sec = $time_left->s;
                            if ($hrs > 0) {
                                $arrival_msg = $hrs . " hrs " . $mint . " mins left";
                            } else {
                                $arrival_msg = $mint . " mins " . $sec . " secs left";
                            }
                        } else {
                            $from_date_time = $data['selected_date'] . " " . $start_time;
                            $to_date_time = $data['selected_date'] . " " . $end_time;
                            $arrival_time = $this->get_arrival_time_from_slot($timezone, $from_date_time, $to_date_time, $slot_type);
                            $arrival_msg = $arrival_time['arrival_msg'];
                            $arrival_status = $arrival_time['arrival_status'];
                        }
                        $new_reservation_details = array(
                            "reservation_date" => $data['selected_date'],
                            "from_time" => $start_time,
                            "to_time" => $end_time,
                            "timeslot" => $slot_type,
                            "arrival_status" => $arrival_status,
                            "arrival_message" => $arrival_msg
                        );
                        if (isset($booking_id) && is_array($booking_id)) { 
                            $action_performed = ', WAT_RSV'; 
                            $where_check = ' vt_group_no = "' . $visitor_group_no . '" and id in (' . implode(',', $vt_ids_group) . ') and ';
                        } else if ($booking_id != '') { //updations from WAT
                            $action_performed = ', WAT_RSV'; 
                            $where_check = 'vt_group_no = "' . $visitor_group_no . '" and transaction_id = "' . $booking_id . '" and ';
                        } else {  
                            $action_performed = ', MPOS_RSV';                                                                  
                            $where_check = 'vt_group_no = "' . $visitor_group_no . '" and ';
                        }
                        $where_vt = $where_check . ' ticketId = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '" and created_date = "' . $created_date_time . '" and is_refunded = "0"';

                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("selected_date"             => $visitor_data['selected_date'], 
                                            "from_time"                 => $visitor_data["from_time"], 
                                            "to_time"                   => $visitor_data["to_time"], 
                                            "slot_type"                 => $visitor_data["slot_type"], 
                                            "CONCAT_VALUE"              => array("action_performed" => $action_performed), 
                                            "updated_at"                => gmdate("Y-m-d H:i:s"));
                        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => $where_vt);                    

                        /*  Update reservation details in Firebase if venue_app active for the user */
                        if (SYNC_WITH_FIREBASE == 1 && in_array($ticket_data->channel_type, $this->mpos_channels)) {
                            $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $ticket_data->hotel_id . '"'));
                            if ($hotel_data[0]['is_venue_app_active'] == 1) {
                                try {
                                    $MPOS_LOGS['DB'][] = 'FIREBASE';
                                    $headers = $this->all_headers(array(
                                        'hotel_id' => $ticket_data->hotel_id,
                                        'museum_id' => $ticket_data->museum_id,
                                        'ticket_id' => $ticket_id,
                                        'channel_type' => $ticket_data->channel_type,
                                        'action' => 'update_order_from_MPOS',
                                        'user_id' => $ticket_data->cashier_id
                                    ));


                                    $selected_date = date('Y-m-d', strtotime($selected_date));
                                    /* fetch details of order of previous date */
                                    $previous_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time);
                                    $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                                        'type' => 'POST',
                                        'additional_headers' => $headers,
                                        'body' => array("node" => 'distributor/bookings_list/' . $ticket_data->hotel_id . '/' . $ticket_data->cashier_id . '/' . date("Y-m-d", strtotime($ticket_data->created_date_time)) . '/' . $previous_key)
                                    ));
                                    $previous = json_decode($getdata, true);
                                    $previous_data = $previous['data'];
                                    $logs['previous_order_data_from_firebase'] = $previous;
                                    if (!empty($previous_data)) {
                                        $previous_data['from_time'] = $start_time;
                                        $previous_data['reservation_date'] = $data['selected_date'];
                                        $previous_data['to_time'] = $end_time;
                                    }

                                    /* delete previous node */
                                    $this->curl->requestASYNC('FIREBASE', '/delete_details', array(
                                        'type' => 'POST',
                                        'additional_headers' => $headers,
                                        'body' => array("node" => 'distributor/bookings_list/' . $ticket_data->hotel_id . '/' . $ticket_data->cashier_id . '/' . date("Y-m-d", strtotime($ticket_data->created_date_time)) . '/' . $previous_key)
                                    ));

                                    /* create new node */
                                    $update_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $data['selected_date'] . '_' . $start_time . '_' . $end_time . '_' . $created_date_time);
                                    $params = array(
                                        'type' => 'POST',
                                        'additional_headers' => $headers,
                                        'body' => array("node" => 'distributor/bookings_list/' . $ticket_data->hotel_id . '/' . $ticket_data->cashier_id . '/' . date("Y-m-d", strtotime($ticket_data->created_date_time)) . '/' . $update_key, 'details' => $previous_data)
                                    );
                                    $logs['firebase_update_req_' . date('H:i:s')] = $params['body'];
                                    $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
                                } catch (\Exception $e) {
                                    $logs['exception'] = $e->getMessage();
                                }
                            }
                        }
                        /*  Update prepaid_tickets table */
                        if (isset($booking_id) && is_array($booking_id)) { 
                            $action_performed = ', WAT_RSV'; 
                            $where = 'visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id in ("' . implode('","', $booking_id) . '") and ';
                        } else if ($booking_id != '') { //updations from WAT
                            $action_performed = ', WAT_RSV'; 
                            $where = 'visitor_group_no = "' . $visitor_group_no . '" and prepaid_ticket_id = "' . $booking_id . '" and ';
                        } else {
                            $action_performed = ', MPOS_RSV';                  
                            $where = 'visitor_group_no = "' . $visitor_group_no . '" and ';
                        }
                        $update_pt_db1 = 'selected_date = "' . $prepaid_data['selected_date'] . '", from_time = "' . $prepaid_data['from_time'] . '", to_time = "' . $prepaid_data['to_time'] . '", timeslot = "' . $visitor_data['slot_type'] . '", action_performed = concat(action_performed, "'.$action_performed . '")';
                        $where .= ' ticket_id = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time
                        //  . '" and created_date_time = "' . $created_date_time 
                        . '" and is_refunded = "0"';
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("selected_date"             => $prepaid_data['selected_date'], 
                                            "from_time"                 => $prepaid_data["from_time"], 
                                            "to_time"                   => $prepaid_data["to_time"], 
                                            "timeslot"                  => $visitor_data["slot_type"], 
                                            "CONCAT_VALUE"              => array("action_performed" => $action_performed));

                        $updateCols = array_merge($updateCols, array("updated_at" => gmdate("Y-m-d H:i:s")));
                        $this->query('update prepaid_tickets set '.$update_pt_db1.' where ' . $where, 0);
                        $logs['pt_query_' . date('H:i:s')] = $this->db->last_query();                    
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where);

                        /*  Update prepaid_extra_options table */
                        $this->update('prepaid_extra_options', $prepaid_data, array('visitor_group_no' => $visitor_group_no, 'ticket_id' => $ticket_id, 'selected_date' => $ticket_data->selected_date, 'from_time' => $ticket_data->from_time, 'to_time' => $ticket_data->to_time), 0);
                        $sns_messages[] = $this->db->last_query();
                        $logs['prepaid_extra_options_query_' . date('H:i:s')] = $this->db->last_query();


                        try {
                            if (!empty($sns_messages) || !empty($insertion_db2)) {
                                $request_array['db2_insertion'] = $insertion_db2;
                                $request_array['db2'] = $sns_messages;
                                $request_array['api_visitor_group_no'] = $visitor_group_no;
                                $request_array['write_in_mpos_logs'] = 1;
                                $request_array['action'] = "update_order";
                                $request_array['visitor_group_no'] = $visitor_group_no;
                                $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                                $request_string = json_encode($request_array);
                                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                if (LOCAL_ENVIRONMENT == 'Local') {
                                    local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                                } else {
                                    $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                                }
                            }
                        } catch (\Exception $e) {
                            $logs['exception'] = $e->getMessage();
                        }
                        $response['status'] = (int) 1;
                        $response['message'] = 'Booking updated successfully';
                        if ($booking_id != '') { //from WAT app
                            $response['ticket_details'] = $new_reservation_details;
                        }
                        $internal_logs['update_order'] = $internal_log;
                    } else {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'Booking already redeemed';
                    }
                }
            } else {
                $response['status'] = (int) 0;
                $response['errorMessage'] = 'Reservation Cannot be edit for the dependant ticket';
            }
            $MPOS_LOGS['update_order'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['update_order'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name update_note
     * @Purpose This function is used to update the note from firebase app
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function update_note($hotel_id = '', $data = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $logs['hotel_id'] = $hotel_id;
            $insertion_db2 = array();
            $visitor_group_no = $data['order_id'];
            $selected_date = $data['selected_date'];
            $created_date_time = $data['booking_date_time'];
            $booking_id_check = ($data['booking_id'] !== '' && $data['booking_id'] != NULL) ? ' and ticket_booking_id = "' . $data['booking_id'] . '" ' : '';
            $prepaid_data = $this->find('prepaid_tickets', array('select' => 'channel_type, ticket_booking_id, extra_booking_information, ticket_id, prepaid_ticket_id', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" ' . $booking_id_check . ' and is_refunded = "0"  and is_addon_ticket != "2"'));
            $logs['pt_query_' . date('H:i:s')] = str_replace('\n', ' ', $this->primarydb->db->last_query());
            if (in_array($prepaid_data[0]['channel_type'], $this->mpos_channels)) {
                $note_to_be_updated = isset($data['updated_note']) ?  $data['updated_note'] : $data["user_note"];
                $update_pt_db1 = 'extra_text_field_answer = "' . $note_to_be_updated . '", guest_names = "' . $data['booking_name'] . '", guest_emails = "' . $data['booking_email'] . '", action_performed = concat(action_performed, ", MPOS_NOTE")';
                $where = 'visitor_group_no = "' . $visitor_group_no . '"';
                $this->query('update prepaid_tickets set ' . $update_pt_db1 . ' where ' .$where, 0);
                $logs['pt_update_query_' . date('H:i:s')] = str_replace('\n', ' ', $this->db->last_query());
                /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                $updateCols = array("extra_text_field_answer"   => $note_to_be_updated,
                                        "guest_names"               => $data["booking_name"],
                                        "guest_emails"              => $data["booking_email"],
                                        "CONCAT_VALUE"              => array("action_performed" => ', MPOS_NOTE'),
                                        "updated_at"                => gmdate("Y-m-d H:i:s"),
                                        "secondary_guest_name"      => $data['booking_name'],
                                        "secondary_guest_email"     => $data['booking_email']);
                $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where);
                    
                /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                $updateColsVT = array("CONCAT_VALUE" => array("action_performed" => ', MPOS_NOTE'), "updated_at" => gmdate("Y-m-d H:i:s"));                
                /* ARRAY TO INSERT INSTEAD OF UPDATION */
                $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $updateColsVT, "where" => 'vt_group_no = "' . $visitor_group_no . '"');
                $ticket_prepaid_details = $this->find('prepaid_tickets', array('select' => 'cashier_id, created_date_time, ticket_id, selected_date, from_time, to_time, created_date_time', 'where' =>
                    'visitor_group_no = "' . $visitor_group_no . '" and is_refunded = "0" and is_addon_ticket != "2" 
                    group by visitor_group_no, ticket_booking_id, ticket_id, selected_date, from_time, to_time'));
                $logs['fetch_pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['fetch_pt_response_' . date('H:i:s')] = $ticket_prepaid_details;
                /*  Update order details in Firebase if venue_app active for the user */
                if (SYNC_WITH_FIREBASE == 1) {
                    $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $hotel_id . '"'));
                    if ($hotel_data[0]['is_venue_app_active'] == 1) {
                        try {
                            $headers = $this->all_headers(array(
                                    'hotel_id' => $hotel_id,
                                    'ticket_id' => $prepaid_data[0]['ticket_id'],
                                    'channel_type' => $prepaid_data[0]['channel_type'],
                                    'action' => 'update_note_from_MPOS',
                                    'user_id' => $ticket_prepaid_details[0]['cashier_id']
                                ));
                            $update_values = array(
                                    'booking_name' => $data['booking_name'],
                                    'reference' => $data['client_reference']
                                );
                                $MPOS_LOGS['DB'][] = 'FIREBASE';
                            foreach ($ticket_prepaid_details as $ticket_prepaid_detail) {
                                if ($ticket_prepaid_detail['from_time'] == '' || $ticket_prepaid_detail['from_time'] == '0') {
                                    $selected_date = $ticket_prepaid_detail['selected_date'];
                                    $from_time = '0';
                                    $to_time = '0';
                                } else {
                                    $selected_date = $ticket_prepaid_detail['selected_date'];
                                    $from_time = $ticket_prepaid_detail['from_time'];
                                    $to_time = $ticket_prepaid_detail['to_time'];
                                }
                                $created_date_time = $ticket_prepaid_detail['created_date_time'];
                                $update_key = base64_encode($visitor_group_no . '_' . $ticket_prepaid_detail['ticket_id'] . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time);
                                $params = array(
                                        'type' => 'POST',
                                        'additional_headers' => $headers,
                                        'body' => array("node" => 'distributor/bookings_list/' .$hotel_id . '/' . $ticket_prepaid_detail['cashier_id'] . '/' . date("Y-m-d", strtotime($ticket_prepaid_detail['created_date_time'])) . '/' . $update_key, 'details' => $update_values)
                                    );
                                $logs['Firebase_req_' . date('H:i:s')] = $params['body'];
                                $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
                                // sync details in order list
                                $order_date = date("Y-m-d", strtotime($created_date_time));
                                $current_date = $this->current_date;
                                if (strtotime($order_date) >= strtotime($current_date)) {
                                    $update_details = array(
                                            'booking_name' => $data['booking_name'],
                                            'booking_email' => $data['booking_email'],
                                            'note' => $data['user_note'],
                                        );
                                    $params = array(
                                            'type' => 'POST',
                                            'additional_headers' => $headers,
                                            'body' => array("node" => 'distributor/orders_list/' . $hotel_id . '/' . $ticket_prepaid_detail['cashier_id'] . '/' . date("Y-m-d", strtotime($ticket_prepaid_detail['created_date_time'])) . '/' . $visitor_group_no, 'details' => $update_details)
                                        );
                                    $logs['firebase-orderslist-req-booking_details_' . date('H:i:s')] = $params['body'];
                                    $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
                                }
                            }
                        } catch (\Exception $e) {
                            $logs['exception'] = $e->getMessage();
                        }
                    }
                }
            } else { //for channels other than mpos
                if(in_array($data['note_level'], $this->notes_levels)) {
                    $updated_notes = array();
                    $internal_log['fetch_pt_response_' . date('H:i:s')] = $prepaid_data;
                    $booking_info = json_decode($prepaid_data[0]['extra_booking_information'], true);
                    $existing_notes = $booking_info[$data['note_level']."_notes"];
                    $note_found = 0;
                    foreach($existing_notes as $note) {
                        if(trim(strtolower($note['note_value'])) == trim(strtolower($data['existing_note']))) {
                            $note['note_value'] = ucfirst($data['updated_note']);
                            $note_found = 1;
                        }
                        if($data['updated_note'] != '') {
                            $updated_notes[] = $note;
                        }
                    }
                    $booking_info[$data['note_level']."_notes"] = $updated_notes;
                    if ($note_found == 1) {
                        $this->query("update prepaid_tickets set extra_booking_information = '" . json_encode($booking_info, JSON_HEX_APOS) . "' , action_performed = concat(action_performed, ', MPOS_NOTE') where  visitor_group_no = '" . $visitor_group_no . "' ". $booking_id_check . " and is_refunded = '0'  and is_addon_ticket != '2'", 0);
                        $logs['pt_update_query_' . date('H:i:s')] = str_replace('\n', ' ', $this->db->last_query());
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array(
                            "extra_booking_information"   => json_encode($booking_info, JSON_HEX_APOS),
                            "CONCAT_VALUE"              => array("action_performed" => ', MPOS_NOTE'),
                            "updated_at"                => gmdate("Y-m-d H:i:s"));
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => ' visitor_group_no = "' . $visitor_group_no . '" ' . $booking_id_check . ' and is_refunded = "0"  and is_addon_ticket != "2" ');
                        $updateColsVT = array("CONCAT_VALUE" => array("action_performed" => ', MPOS_NOTE'), "updated_at" => gmdate("Y-m-d H:i:s"));
                        /* ARRAY TO INSERT INSTEAD OF UPDATION */
                        $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $updateColsVT, "where" => 'vt_group_no = "' . $visitor_group_no . '" ' . $booking_id_check . ' and is_refunded = "0"  and col2 != "2" ');
                    } else {
                        $response['status'] = (int) 0;
                        $response['message'] = 'Existing Notes mismatch on '.$data['note_level']. ' level';
                        $MPOS_LOGS['update_note'] = $logs;
                        return $response;
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['message'] = 'Unspecified Notes Level';
                    $MPOS_LOGS['update_note'] = $logs;
                    return $response;
                }
            }
            /* Send request to SNS to update DB2 */
            try {
                if (!empty($insertion_db2)) {
                    $request_array['db2_insertion'] = $insertion_db2;
                    $request_array['api_visitor_group_no'] = $visitor_group_no;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "update_note";
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.

                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $this->queue($aws_message, UPDATE_DB_QUEUE_URL, UPDATE_DB_ARN);
                    }
                }
                $internal_logs['update_note'] = $internal_log;
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }
            $response['status'] = (int) 1;
            $response['message'] = 'Updated successfully';
            $MPOS_LOGS['update_note'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['update_note'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name cancel_citycard
     * @Purpose This function is used to cancel the city cards activated for third party orders for supervisor
     * It will remove the bleep_passes from PT 
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 28 Sep 2018
     */
    function cancel_citycard($data = array()) {
        global $MPOS_LOGS;
        try {
            $order_id = $data['order_id'];
            $ticket_id = $data['ticket_id'];
            $selected_date = $data['selected_date'];
            $from_time = ($data['from_time'] != '' && $data['from_time'] != '0') ? $data['from_time'] : "";
            $to_time = ($data['to_time'] != '' && $data['to_time'] != '0') ? $data['to_time'] : "";
            $pass_no = $data['pass_no'];
            $channel_type = $data['channel_type'];
            $own_supplier_id = $data['own_supplier_id'];
            $supplier_cashier_id = $data['supplier_cashier_id'];
            $cancelled_by = $data['refunded_by'];
            $created_date_time = $data['booking_date_time'];
            $action_performed = (isset($data['action_performed'])) ? $this->REFUND_ACTIONS[$data['action_performed']] : 'MPOS_ACT_CNCL';
            $activated_at = $data['activated_at'];
            $bleep_passes = $data['bleep_pass_nos'];

            if ($channel_type == 5) {
                $pass_condition = ' and passNo= "' . $pass_no . '"';
            } else {
                $pass_condition = '';
            }
            $db1_sns_messages = $insertion_db2 = array();
            $all_bleep_pases = implode('","', $bleep_passes);
            $all_bleep_passes_to_sync = implode(',', $bleep_passes);
            $from_bleep_passes = 0;
            $bleep_passes_query = 'select * from bleep_pass_nos where pass_no IN ("' . $all_bleep_pases . '")';
            $bleep_pass_activated = $this->query($bleep_passes_query, 1);
            if(!empty($bleep_pass_activated)) {
                $activated_at = $bleep_pass_activated[0]['last_modified_at'];
                $from_bleep_passes = 1;
            }
            
            //undo scanned_at and used values as it was before city card activation
            $pt_db1_cols = ' used = "0" , scanned_at  = "", payment_conditions = "", redeem_users = "", redeem_date_time = "1970-01-01 00:00:01", action_performed = concat(action_performed, ", ' . $action_performed . '"), pos_point_id = 0, pos_point_name = "", museum_cashier_id = 0, museum_cashier_name = "", bleep_pass_no = ""';
            $where = 'where visitor_group_no = "' . $order_id . '"' . $pass_condition . ' and created_date_time = "' . $created_date_time . '"';
            
            $pt_db1_query = 'update prepaid_tickets set '.$pt_db1_cols.' '.$where;
            $db1_sns_messages[] = $pt_db1_query;
            
            /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
            $updateCols = array("used"                      => '0', 
                                "scanned_at"                => '', 
                                "redeem_date_time"          => '1970-01-01 00:00:01',
                                "CONCAT_VALUE"              => array("action_performed" => ', '.$action_performed), 
                                "pos_point_id"              => 0, 
                                "pos_point_name"            => '', 
                                "museum_cashier_id"         => '0', 
                                "museum_cashier_name"       => '', 
                                "bleep_pass_no"             => '', 
                                "payment_conditions"        => '', 
                                "redeem_users"              => '', 
                                "updated_at"                => gmdate("Y-m-d H:i:s"),
                                'pos_point_id_on_redeem'    => '0',
                                'pos_point_name_on_redeem'  => '',
                                'distributor_id_on_redeem'  => '0',
                                'distributor_cashier_id_on_redeem'  => '',
                                'voucher_updated_by'        => '0',
                                'voucher_updated_by_name'   => ''
                            );
            $whereCon = 'visitor_group_no = "' . $order_id . '"' . $pass_condition . ' and created_date_time = "' . $created_date_time . '"';
            $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $whereCon);
            
            $expedia_query = 'update expedia_prepaid_tickets set used = "0", scanned_at = "", action_performed = concat(action_performed, ", ' . $action_performed . '"),  bleep_pass_no = "" where visitor_group_no = "' . $order_id . '" ';
            $db1_sns_messages[] = $expedia_query;
            
            /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
            $updateCols = array("used"                      => '0', 
                                "CONCAT_VALUE"              => array("action_performed" => ', '.$action_performed), 
                                "pos_point_id"              => 0, 
                                "pos_point_name"            => '', 
                                "updated_by_id"             => 0, 
                                "updated_by_username"       => '', 
                                "voucher_updated_by"        => 0, 
                                "voucher_updated_by_name"   => '', 
                                "redeem_method"             => '',
                                "scanned_pass"              => '', 
                                "updated_at"                => gmdate("Y-m-d H:i:s"));
            $whereCon = 'vt_group_no = "' . $order_id . '"' . $pass_condition . ' and created_date = "' . $created_date_time . '"';
            $insertion_db2[] = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => $whereCon);
            
            if($from_bleep_passes == 1) {
                $bleep_pass_query = 'update bleep_pass_nos set status = "0", last_modified_at = "' . gmdate("Y-m-d H:i:s") . '" where pass_no IN ("' . $all_bleep_pases . '")';
                $db1_sns_messages[] = $bleep_pass_query;
            }
            $redeem_cashier_detail_query = 'delete from redeem_cashiers_details where pass_no IN ("' . $all_bleep_pases . '")';
            $db1_sns_messages[] = $redeem_cashier_detail_query;
            //Remmove the city cards from Firebase in voucher scans node under suppliers
            if (SYNC_WITH_FIREBASE == 1) {
                try {
                    $MPOS_LOGS['DB'][] = 'FIREBASE';
                    $headers = $this->all_headers(array(
                        'museum_id' => $own_supplier_id,
                        'ticket_id' => $ticket_id,
                        'channel_type' => $channel_type,
                        'action' => 'sync_voucher_scans_on_cancel_citycard',
                        'user_id' => $supplier_cashier_id
                    ));
                    $update_values = array(
                        'status' => (int) 4,
                        'cancelled_by' => (int) $cancelled_by,
                        'cancelled_bleep_passes' => $all_bleep_passes_to_sync,
                        'bleep_passes' => ''
                    );
                    if ($channel_type == 5) {
                        $update_key = base64_encode($order_id . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time . '_' . $pass_no);
                    } else {
                        $update_key = base64_encode($order_id . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time);
                    }
                    
                    $params = array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => array("node" => 'suppliers/' . $own_supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . date("Y-m-d", strtotime($activated_at)) . '/' . $update_key, 'details' => $update_values)
                    );
                    $logs['firebase-req-cancel_' . date('H:i:s')] = $params['body']; 
                    $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
                } catch (\Exception $e) {
                    $logs['exception'] = $e->getMessage();
                }
            }
            try {
                if (!empty($db1_sns_messages) || !empty($insertion_db2)) {
                    $request_array['db1'] = $db1_sns_messages;
                    $request_array['db2_insertion'] = $insertion_db2;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = "cancel_citycard";
                    $request_array['visitor_group_no'] = $order_id;
                    $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                    } else {
                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                    }
                }
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }
            $response['status'] = (int) 1;
            $response['message'] = 'Cancelled successfully';
            $MPOS_LOGS['cancel_citycard_api'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['update_note'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name cancel
     * @Purpose This function is used to cancel the booking and if booking is confirmed, then refund the order and update firebase
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 3 Nov 2017
     */
    function cancel($hotel_id = '', $data = array()) {
        try {
            global $MPOS_LOGS;
            global $internal_logs;
            $logs['hotel_id'] = $hotel_id;
            $sns_messages = array();
            $insertion_db2 = array();
            $refunded_by_user = '';
            $order_cancellation_date = date('Y-m-d H:i:s');
            if ($data['selected_date'] == '' || $data['selected_date'] == '0') {
                $data['selected_date'] = '0';
                $data['from_time'] = '0';
                $data['to_time'] = '0';
            }
            $refunded_by = (isset($data['refunded_by'])) ? $data['refunded_by'] : 0;
            $visitor_group_no = $data['order_id'];
            $ticket_id = $data['ticket_id'];
            $selected_date = $data['selected_date'];
            $from_time = $data['from_time'];
            $to_time = $data['to_time'];
            $cashier_id = $data['cashier_id'];
            $created_date_time = $data['booking_date_time'];
            $upsell_order = $data['upsell_order'];
            $cashier_type = $data['cashier_type'];
            $cluster_ids = (isset($data['cluster_ids'])) ? $data['cluster_ids'] : array();
            $tps_ids = (isset($data['tps_ids'])) ? $data['tps_ids'] : array();
            $action_performed = (isset($data['action_performed'])) ? $this->REFUND_ACTIONS[$data['action_performed']] : 'MPOS_BO_RFN';
            $start_amount = (isset($data['start_amount'])) ? $data['start_amount'] : 0;
            $order_confirm_date = gmdate("Y-m-d H:i:s");

            
            //Fetch values from users table to sync in shift report
            $user_details = $this->find('users', array('select' => 'id, firebase_user_id, user_role, fname, lname', 'where' => 'id = ' . $this->primarydb->db->escape($cashier_id) . ' or id = ' . $this->primarydb->db->escape($refunded_by) . ''));
            $logs['users_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['user_details_' . date('H:i:s')] = $user_details;
            foreach ($user_details as $user) {
                $user_firebase_ids[$user['id']]['firebase_user_id'] = $user['firebase_user_id'];
                $user_firebase_ids[$user['id']]['user_role'] = $user['user_role'];
                if ($user['id'] == $refunded_by) {
                    $refunded_by_user = $user['fname'] . ' ' . $user['lname'];
                }
            }
            /* Fetch passes and other required info from prepaid tickets */
            if ($upsell_order == "1") {
                //In case of upsell, data is fetched for extended entry
                //As we can't cancel the main tickets untill ectended ticket is not cancelled
                if (!empty($cluster_ids)) {
                    $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and action_performed like "%UPSELL_INSERT%" and ( is_addon_ticket = "0" or is_addon_ticket = "1" or clustering_id IN (' . implode(',', $cluster_ids) . '))'));
                } else {
                    if (!($from_time != '' && $from_time != 0 && $to_time != '' && $to_time != 0)) { //booking ticket
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and action_performed like "%UPSELL_INSERT%" and ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' '));
                    } else {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and action_performed like "%UPSELL_INSERT%" and ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and selected_date = ' . $this->primarydb->db->escape($selected_date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ' and (is_refunded = "0" or is_refunded = "2")'));
                    }
                }
            } else {
                if (!empty($cluster_ids)) {
                    $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and (is_refunded = "0" or is_refunded = "2") and ( is_addon_ticket = "0" or is_addon_ticket = "1" or clustering_id IN (' . implode(',', $cluster_ids) . '))'));
                } else {
                    if (!($from_time != '' && $from_time != 0 && $to_time != '' && $to_time != 0)) { //booking ticket
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and (is_refunded = "0" or is_refunded = "2") and ticket_id = "' . $ticket_id . '" '));
                    } else {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and selected_date = ' . $this->primarydb->db->escape($selected_date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ' and (is_refunded = "0" or is_refunded = "2")'));
                    }
                }
            }
            $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['pt_response'] = $prepaid_data;
            if (!empty($prepaid_data)) {
                if ($prepaid_data[0]['third_party_type'] == 17 && isset($prepaid_data[0]['reference_id'])) {
                    $third_party_data = $this->find('modeventcontent', array('select' => 'third_party_parameters', 'where' => 'mec_id = "' . $ticket_id . '"'));
                    $logs['mec_data'] = array('query' => $this->primarydb->db->last_query(), 'data' => $third_party_data);
                    $third_party_details = json_decode($third_party_data[0]['third_party_parameters'], true);
                    $cancel_third_party_order['booking_id'] = $prepaid_data[0]['reference_id'];
                    $cancel_third_party_order['third_party_details'] = $third_party_details;
                    $logs['cancel_third_party_order_req'] = $cancel_third_party_order;
                    $response_cancel = $this->third_party_api_model->cancel_enviso_order($cancel_third_party_order);
                    $logs['cancel_third_party_order_res'] = $response_cancel;
                    if ($response_cancel['status'] == 0) {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = $response_cancel['message'];
                        return $response;
                    }
                }
                $cancelled_tickets = 0;
                $used_tickets = 0;
                $discount_code_amount = 0;
                $cancelled_ticket_array = array();
                $update_prepaid_data = array();
                $prev_cancelled_tickets = 0;
                $activation_method = $prepaid_data[0]['activation_method'];
                $redeemed_tickets = 0;
                $prepaid_ticket_ids = $ticket_booking_ids = $return_cancelled_ticket_details = $ticket_types_firebase_array = array();
                $cant_cancel = 0;
                foreach ($prepaid_data as $row) {
                    //prepare data for tickets other than cluster tickets and cluster tickets which are to be cancelled
                    if ((empty($cluster_ids)) || ((!empty($cluster_ids)) && in_array($row['clustering_id'], $cluster_ids))) {
                        $order_details = unserialize($row['additional_information']);
                        //MPM can cancel its own used tickets
                        if ($cashier_type == "2" || $cashier_type == "3" || $cashier_type == "5" || ($cashier_type == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '')) {
                            if ($row['used'] == 1) {
                                $row['prev_used'] = 1;
                            }
                            $row['used'] = 0;
                            $row['prev_bleep_pass_no'] = $row['bleep_pass_no'];
                            $row['bleep_pass_no'] = '';
                        }
                        //Count used tickets
                        if ($row['used'] == "1") {
                            $used_tickets++;
                        }
                        if ($row['bleep_pass_no'] != '' && !isset($order_details['add_to_pass'])) {
                            $redeemed_tickets++;
                        }
                        //CCount prev cancelled tickets to sync on firebase
                        if ($row['is_refunded'] == "2" && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1")) {
                            $prev_cancelled_tickets++;
                            $refund_array[$row['tps_id']][$row['refunded_by']] = array(
                                'refunded_by' => (int) $row['refunded_by'],
                                'count' => (int) $refund_array[$row['tps_id']][$row['refunded_by']]['count'] + 1
                            );
                        }
                        //prepare array to return and sync
                        $extra_discount = unserialize($row['extra_discount']);
                        if ($row['used'] == "0" && $row['is_refunded'] == "0" && ($row['bleep_pass_no'] == "" || ($row['bleep_pass_no'] != '' && $order_details['add_to_pass'] == "1"))) {
                            if (!empty($cluster_ids) || ($row['created_date_time'] == $created_date_time)) {
                                $update_prepaid_data[] = $row;
                            }
                            $prepaid_ticket_ids[] = $row['prepaid_ticket_id'];
                            $ticket_booking_ids[] = $row['ticket_booking_id'];
                            $visitor_ticket_ids[] = $row['visitor_tickets_id'];
                            if ((empty($tps_ids) || (!empty($tps_ids) && in_array($row['tps_id'], $tps_ids))) && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1")) {
                                if ($row['from_time'] != "" && $row['from_time'] !== "0" && $row['to_time'] != "" && $row['to_time'] !== "0") {
                                    $cancelled_ticket_array[$row['tps_id']] = array(
                                        'capacity_count' => $cancelled_ticket_array[$row['tps_id']]['capacity_count'] + 1,
                                        'group_count' => $cancelled_ticket_array[$row['tps_id']]['group_count'] + $row['group_quantity'],
                                        'group_price' => $row['group_price'],
                                        'selected_date' => $row['selected_date'],
                                        'from_time' => $row['from_time'],
                                        'to_time' => $row['to_time'],
                                        'ticket_id' => $row['ticket_id'],
                                        'capacity' => $row['capacity']
                                    );
                                }
                                $cancelled_tickets++;
                                $ticket_type = ucfirst(strtolower($row['ticket_type']));
                                $cancelled_pass[$row['tps_id']][$row['passNo']] = $row['passNo'];
                                $discount = (!empty($extra_discount)) ? $extra_discount['gross_discount_amount'] : 0;
                                $return_cancelled_ticket_details[$row['tps_id']] = array(
                                    'tps_id' => (int) $row['tps_id'],
                                    'quantity' => (int) ($return_cancelled_ticket_details[$row['tps_id']]['quantity'] + 1),
                                    'ticket_type' => (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? (int) $this->TICKET_TYPES[$ticket_type] : (int) 10,
                                    'ticket_type_label' => (string) $ticket_type,
                                    'pass_no' => array_values($cancelled_pass[$row['tps_id']]),
                                    'price' => (float) $row['price'],
                                    'cashier_discount' => (float) ($return_cancelled_ticket_details[$row['tps_id']]['cashier_discount'] + $discount),
                                );
                                $return_cancelled_ticket_details = $this->common_model->sort_ticket_types($return_cancelled_ticket_details);
                                $refund_array[$row['tps_id']][$refunded_by] = array(
                                    'refunded_by' => (int) $refunded_by,
                                    'count' => (int) $refund_array[$row['tps_id']][$refunded_by]['count'] + 1
                                );
                            }
                            if ($refunded_by != $row['cashier_id'] &&( $row['is_addon_ticket'] == "0" ||  $row['is_addon_ticket'] == "1")) {
                                $other_cancellation = array(
                                    'amount' => (!empty($extra_discount)) ? (float) $other_cancellation['amount'] + ($row['price'] - $extra_discount['gross_discount_amount']) : (float) ($other_cancellation['amount'] + $row['price']),
                                    'cashier_id' => (int) $row['cashier_id']
                                );
                            }
                        }
                        if (($row['is_refunded'] == "0" || $row['is_refunded'] == "2") && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1" )&& (empty($tps_ids) || (!empty($tps_ids) && in_array($row['tps_id'], $tps_ids)))) {
                            $ticket_type = ucfirst(strtolower($row['ticket_type']));
                            $cashier_id = $row['cashier_id'];
                            // combi on and off cases
                            if (($row['used'] == '0' && in_array($row['tps_id'], array_keys($refund_array))) || $row['is_refunded'] == '2') {
                                $refunded_pass[$row['tps_id']][] = $row['passNo'];
                            }
                            $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']] = array(
                                'tps_id' => (int) $row['tps_id'],
                                'quantity' => (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['quantity'] + 1),
                                'refund_quantity' => ($row['used'] != "1") ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'],
                                'type' => $ticket_type,
                                'tax' => (float) $row['tax'],
                                'cashier_discount' => (float) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                                'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                                'age_group' => $row['age_group'],
                                'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                                'net_price' => (float) ($row['net_price'] + $extra_discount['net_discount_amount']),
                                'per_age_group_extra_options' => array(),
                                'refunded_by' => array(),
                                'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                                'refunded_passes' => array_values($refunded_pass[$row['tps_id']]),
                            );
                        }
                        $passes['"' . $row['passNo'] . '"'] = $row['passNo'];
                    } else if ((!empty($cluster_ids)) && !in_array($row['clustering_id'], $cluster_ids) && ($row['is_addon_ticket'] == "0" ||  $row['is_addon_ticket'] == "1" )&& (empty($tps_ids) || (!empty($tps_ids) && in_array($row['tps_id'], $tps_ids)))) {
                        //prepare data for cluster tickets which are already cancelled or which are not to be cancelled
                        if ($row['is_refunded'] == "2") {
                            $refund_array[$row['tps_id']][$row['refunded_by']] = array(
                                'refunded_by' => (int) $row['refunded_by'],
                                'count' => (int) $refund_array[$row['tps_id']][$row['refunded_by']]['count'] + 1
                            );
                        }
                        // combi on and off cases
                        if (in_array($row['tps_id'], array_keys($refund_array))) {
                            $refunded_pass[$row['tps_id']][] = $row['passNo'];
                        }
                        $extra_discount = unserialize($row['extra_discount']);
                        $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']] = array(
                            'tps_id' => (int) $row['tps_id'],
                            'tax' => (float) $row['tax'],
                            'quantity' => (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['quantity'] + 1),
                            'refund_quantity' => ($row['is_refunded'] == "2") ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'],
                            'type' => $row['ticket_type'],
                            'cashier_discount' => (float) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                            'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                            'age_group' => $row['age_group'],
                            'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                            'net_price' => (float) ($row['net_price'] + $extra_discount['net_discount_amount']),
                            'per_age_group_extra_options' => array(),
                            'refunded_by' => array(),
                            'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                            'refunded_passes' => array_values($refunded_pass[$row['tps_id']]),
                        );
                    }
                    //to update third party for cancel
                    if (($row['channel_type'] == '10' || $row['channel_type'] == '11') && $row['third_party_type'] == 5) {
                        $order_reference = '';
                        if ($row['without_elo_reference_no'] != '') {
                            $order_reference = $row['without_elo_reference_no'];
                        }
                        $booking_date = $row['selected_date'];
                        $booking_time = $row['from_time'];
                        $notify_key = $prepaid_data['ticket_id'] . '_' . $booking_date . '_' . $booking_time;
                        $notify_data[$notify_key]['request_type'] = 'cancel';
                        $notify_data[$notify_key]['booking_data']['distributor_id'] = $row['hotel_id'];
                        $notify_data[$notify_key]['booking_data']['channel_type'] = $row['channel_type'];
                        $notify_data[$notify_key]['booking_data']['ticket_id'] = $row['ticket_id'];
                        $notify_data[$notify_key]['booking_data']['booking_date'] = $booking_date;
                        $notify_data[$notify_key]['booking_data']['booking_time'] = $booking_time;
                        $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['tps_id'] = $row['tps_id'];
                        $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['ticket_type'] = $row['ticket_type'];
                        $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['count'] += 1;
                        $notify_data[$notify_key]['booking_data']['booking_reference'] = isset($order_reference) ? $order_reference : '';
                        $notify_data[$notify_key]['booking_data']['barcode'] = $row['passNo'];
                        $notify_data[$notify_key]['booking_data']['integration_booking_code'] = $row['visitor_group_no'];
                        $notify_data[$notify_key]['booking_data']['customer']['name'] = '';
                        $notify_data[$notify_key]['third_party_data']['agent'] = 'CEXcursiones';
                    }

                    if ($row['activation_method'] != '10' && $row['activation_method'] != '19' && $row['is_refunded'] == '0') {
                        $update_credit_limit = array();
                        $update_credit_limit['museum_id'] = $row['museum_id'];
                        $update_credit_limit['reseller_id'] = $row['reseller_id'];
                        $update_credit_limit['hotel_id'] = $row['hotel_id'];
                        $update_credit_limit['partner_id'] = $row['distributor_partner_id'];
                        $update_credit_limit['cashier_name'] = $row['distributor_partner_name'];
                        $update_credit_limit['hotel_name'] = $row['hotel_name'];
                        $update_credit_limit['visitor_group_no'] = $row['visitor_group_no'];
                        $update_credit_limit['merchant_admin_id'] = $row['merchant_admin_id'];
                        $update_credit_limit['channel_type'] = $row['channel_type'];
                        if (array_key_exists($row['museum_id'], $update_credit_limit_data)) {
                            $update_credit_limit_data[$row['museum_id']]['used_limit'] += $row['price'];
                        } else {
                            $update_credit_limit['used_limit'] = $row['price'];
                            $update_credit_limit_data[$row['museum_id']] = $update_credit_limit;
                        }
                    }
                    if ($cant_cancel == 0 && $row['scanned_at'] != '' && $row['scanned_at'] != NULL && $row['scanned_at'] >= strtotime("-2 minutes")) {
                        $cant_cancel = 1;
                    }
                }

                if ($cant_cancel == 0) {
                    $logs['return_cancelled_ticket_details'] = $return_cancelled_ticket_details;
                    if ($upsell_order == "1") {
                        $this->query('update prepaid_tickets set activated = "1", updated_at = "' . gmdate("Y-m-d H:i:s") . '" where visitor_group_no = "' . $visitor_group_no . '" and activated = "0" and passNo in ("' . implode('","', array_keys($passes)) . '")', 0);
                        $logs['pt_passno_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                        
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("activated" => '1', "updated_at" => gmdate("Y-m-d H:i:s"));
                        $where = 'visitor_group_no = "'.$visitor_group_no.'" and activated = "0" and passNo in ("'.implode('","', array_keys($passes)).'")';
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where, "unset_updated_at" => 0);
                        
                        
                        $logs['upsell_case_pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                    }
                    if ($cancelled_tickets > 0) {
                        if ($prepaid_data[0]['booking_status'] == '1') {
                            $order_status = '2';
                        } else {
                            $order_status = '1';
                        }
                        $activation_method = $prepaid_data[0]['activation_method'];
                        $is_combi_pass = $prepaid_data[0]['is_combi_ticket'];
                        $passNo = $prepaid_data[0]['passNo'];
                        /* In case of payment by card, need payment references from HTO */
                        $user_data = $this->find('hotel_ticket_overview', array('select' => 'channel_type, merchantAccountCode, pspReference, merchantReference, hotel_name, activation_method, paymentMethod', 'where' => 'hotel_id = "' . $hotel_id . '" and visitor_group_no = "' . $visitor_group_no . '"', 'limit' => 1));
                        $merchant_account = $user_data[0]['merchantAccountCode'];
                        $psp_reference = $user_data[0]['pspReference'];
                        if ($visitor_group_no != '' && $ticket_id != '') {
                            //Prepare array to send in queue for cancel tickets inj visitor_tickets table
                            if (!empty($cluster_ids)) {
                                $cancel_tickets_from_firebase = array(
                                    'vt_group_no' => $visitor_group_no,
                                    'used' => '0',
                                    'is_refunded' => "0",
                                    'created_date' => $created_date_time,
                                    'visitor_tickets_id' => $visitor_ticket_ids,
                                    'action_performed' => $action_performed,
                                    'order_confirm_date' => $order_confirm_date,
                                    'refunded_by' => $refunded_by,
                                    'refunded_by_user' => $refunded_by_user,
                                    'order_cancellation_date' => $order_cancellation_date
                                );
                            } else {
                                if ($selected_date != '' && $selected_date != '0' && $from_time != '' && $from_time != 0 && $to_time != '' && $to_time != '0') {
                                    $cancel_tickets_from_firebase = array(
                                        'vt_group_no' => $visitor_group_no,
                                        'ticketId' => $ticket_id,
                                        'used' => '0',
                                        'selected_date' => $selected_date,
                                        'from_time' => $from_time,
                                        'to_time' => $to_time,
                                        'is_refunded' => "0",
                                        'created_date' => $created_date_time,
                                        'visitor_tickets_id' => $visitor_ticket_ids,
                                        'action_performed' => $action_performed,
                                        'order_confirm_date' => $order_confirm_date,
                                        'refunded_by' => $refunded_by,
                                        'refunded_by_user' => $refunded_by_user,
                                        'order_cancellation_date' => $order_cancellation_date
                                    );
                                } else {
                                    $cancel_tickets_from_firebase = array(
                                        'vt_group_no' => $visitor_group_no,
                                        'ticketId' => $ticket_id,
                                        'used' => '0',
                                        'is_refunded' => "0",
                                        'created_date' => $created_date_time,
                                        'visitor_tickets_id' => $visitor_ticket_ids,
                                        'action_performed' => $action_performed,
                                        'order_confirm_date' => $order_confirm_date,
                                        'refunded_by' => $refunded_by,
                                        'refunded_by_user' => $refunded_by_user,
                                        'order_cancellation_date' => $order_cancellation_date
                                    );
                                }
                            }
                            if ($cashier_type == "2" || $cashier_type == "3" || $cashier_type == "5") {
                                unset($cancel_tickets_from_firebase['used']);
                            }
                            $cancelled_tickets_data = array();
                            $logs['hto_queries_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                            /*  Fetch unused and non-cancelled tickets count and update sold count in ticket_capacity_v1 */
                            if (!empty($cancelled_ticket_array)) {
                                $capacity = 0;
                                foreach ($cancelled_ticket_array as $cancelled_tickets_data) {
                                    $capacity += $cancelled_tickets_data['capacity_count'] * $cancelled_tickets_data['capacity'];
                                    if ($cancelled_tickets_data['group_price'] == '1') {
                                        $capacity += $cancelled_tickets_data['group_count'];
                                    }
                                    $change_ticket_id = $cancelled_tickets_data['ticket_id'];
                                    $change_date = $cancelled_tickets_data['selected_date'];
                                    $change_from_time = $cancelled_tickets_data['from_time'];
                                    $change_to_time = $cancelled_tickets_data['to_time'];
                                }
                            }
                            if ($capacity > 0 && $change_date != '' && $change_date != '0') {
                                $this->update_capacity_on_cancel($capacity, $change_ticket_id, $change_date, $change_from_time, $change_to_time);
                                $logs['update_capacity_' . date('H:i:s')] = array($capacity, $change_ticket_id, $change_date, $change_from_time, $change_to_time);
                            }
                            /*  update is_refunded field of prepaid tickets, is_cancelled of prepaid_extra_options where used is 0 */
                            $upsell_condition = '';
                            if ($upsell_order == "1") {
                                $upsell_condition = ' and action_performed like "%UPSELL_INSERT%" ';
                            }
                            $pt_db1_cols ='is_refunded = "2", activated = "0", action_performed = concat(action_performed, ", ' . $action_performed . '"), refunded_by = "' . $refunded_by . '"';
                            
                            /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                            $updateCols = array("is_refunded"               => '2', 
                                                "activated"                 => '0',
                                                "CONCAT_VALUE"              => array("action_performed" => ', ' . $action_performed . ''), 
                                                "refunded_by"               => $refunded_by,           
                                                "order_cancellation_date"   => $order_cancellation_date, 
                                                "order_updated_cashier_id"  => $refunded_by,
                                                "order_updated_cashier_name"=> $refunded_by_user,
                                                "updated_at"                => gmdate("Y-m-d H:i:s"));
                            
                            if (!empty($cluster_ids)) {
                                
                                $this->query('update prepaid_tickets set '.$pt_db1_cols.' where visitor_group_no = "' . $visitor_group_no . '" and (is_refunded = "0" or is_refunded = "2") and clustering_id IN (' . implode(',', $cluster_ids) . ')' . $upsell_condition, 0);
                                
                                /* WHERE CONDITION FOR INSERTION PROCESS */
                                $where = 'visitor_group_no = "' . $visitor_group_no . '" and is_cancelled = "0" and clustering_id IN (' . implode(',', $cluster_ids) . ')' . $upsell_condition;
                                
                            } else {
                                if (!($from_time != '' && $from_time != 0 && $to_time != '' && $to_time != 0)) { //booking ticket
                                    $this->query('update prepaid_tickets set '.$pt_db1_cols.' where visitor_group_no = "' . $visitor_group_no . '" and is_refunded ="0" and ticket_id = "' . $ticket_id . '" and created_date_time = "' . $created_date_time . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')' . $upsell_condition, 0);
                                    
                                    /* WHERE CONDITION FOR INSERTION PROCESS */
                                    $where = 'visitor_group_no = "' . $visitor_group_no . '" and  is_cancelled = "0" and is_refunded = "0" and ticket_id = "' . $ticket_id . '" and created_date_time = "' . $created_date_time . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')' . $upsell_condition;
                                    
                                } else {
                                    $this->query('update prepaid_tickets set '.$pt_db1_cols.' where visitor_group_no = "' . $visitor_group_no . '" and is_refunded = "0" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '" and created_date_time = "' . $created_date_time . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')' . $upsell_condition, 0);
                                    
                                    /* WHERE CONDITION FOR INSERTION PROCESS */
                                    $where = 'visitor_group_no = "' . $visitor_group_no . '" and is_cancelled = "0" and is_refunded = "0" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '" and created_date_time = "' . $created_date_time . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')' . $upsell_condition;
                                }
                            }
                            
                            /* CREATING REQUEST TO PROCES INSERTION IN DB2 */
                            $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where, "unset_updated_at" => 0);
                            
                            
                            $logs['update_pt_db1_queries_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                            /* Add new row in PT for isrefunded 1 and with order status */
                            $insert_prepaid_ticket_data = array();
                            $total_amount = 0;
                            foreach ($update_prepaid_data as $prepaid_ticket_record) {
                                $insert_prepaid_ticket = array();
                                $insert_prepaid_ticket = $prepaid_ticket_record;

                                if ($prepaid_ticket_record['is_addon_ticket'] == "0") {
                                    $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))] = array(
                                        'shift_id' => (int) $insert_prepaid_ticket['shift_id'],
                                        'cashier_id' => (int) $insert_prepaid_ticket['cashier_id'],
                                        'firebase_user_id' => $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['firebase_user_id'],
                                        'user_role' => (int) $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['user_role'],
                                        'pos_point_id' => (int) $insert_prepaid_ticket['pos_point_id'],
                                        'booking_date' => date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time'])),
                                        'amount' => $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))]['amount'] + $insert_prepaid_ticket['price'] - $option_discount
                                    );
                                    $total_amount += $insert_prepaid_ticket['price'];
                                }
                                $insert_prepaid_ticket['is_refunded'] = '1';
                                $insert_prepaid_ticket['activated'] = "0";                                
                                $insert_prepaid_ticket['order_cancellation_date'] = $order_cancellation_date;
                                $insert_prepaid_ticket['order_updated_cashier_id'] = $refunded_by;
                                $insert_prepaid_ticket['order_updated_cashier_name'] = $refunded_by_user;
                                $insert_prepaid_ticket['order_status'] = $order_status;
                                $insert_prepaid_ticket['refunded_by'] = $refunded_by;
                                $insert_prepaid_ticket['is_cancelled'] = '1';
                                $insert_prepaid_ticket['action_performed'] = '0, ' . $action_performed;
                                $insert_prepaid_ticket['bleep_pass_no'] = isset($insert_prepaid_ticket['prev_bleep_pass_no']) ? (string) $insert_prepaid_ticket['prev_bleep_pass_no'] : $insert_prepaid_ticket['bleep_pass_no'];
                                $insert_prepaid_ticket['used'] = ($insert_prepaid_ticket['prev_used'] == 1) ? (string) $insert_prepaid_ticket['prev_used'] : (string) $insert_prepaid_ticket['used'];
                                unset($insert_prepaid_ticket['prev_bleep_pass_no']);
                                unset($insert_prepaid_ticket['prev_used']);
                                unset($insert_prepaid_ticket['last_modified_at']);
                                $insert_prepaid_ticket['updated_at'] = gmdate("Y-m-d H:i:s");
                                $insert_prepaid_ticket['order_confirm_date'] = $order_confirm_date;
                                $insert_prepaid_ticket['created_at'] = strtotime(date('Y-m-d H:i:s'));
                                $insert_prepaid_ticket['start_amount'] = $start_amount;
                                $pt_id = $insert_prepaid_ticket['prepaid_ticket_id'];
                                unset($insert_prepaid_ticket['prepaid_ticket_id']);
                                $insert_prepaid_ticket_data[$pt_id] = $insert_prepaid_ticket;
                            }
                            /* cashier discount subtracted from refunded amount */
                            if ($discount_code_amount > 0) {
                                $total_amount = $total_amount - $discount_code_amount;
                            }
                            $service_cost = 0;
                            $track_time['step-8'] = gmdate("Y-m-d H:i:s");
                            /* update prepaid_extra_options */
                            if (!($from_time != '' && $from_time != 0 && $to_time != '' && $to_time != 0)) { //booking ticket
                                $ex_selected_date = '';
                                $ex_from_time = '';
                                $ex_to_time = '';
                            }
                            $query = 'select refunded_by, refund_quantity, quantity, ticket_id, ticket_price_schedule_id, extra_option_id, description, main_description, price, created, prepaid_extra_options_id, variation_type from prepaid_extra_options where visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $ex_selected_date . '" and from_time = "' . $ex_from_time . '" and to_time = "' . $ex_to_time . '"';
                            $extra_options = $this->query($query, 1);
                            $logs['prepaid_extra_options_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                            $internal_log['prepaid_extra_options_result_' . date('H:i:s')] = $extra_options;
                            if (!empty($extra_options)) {
                                $refunded_extra_options = array();
                                foreach ($extra_options as $extra_options_data) {
                                    if($extra_options_data['variation_type'] == 0){
                                        if ($extra_options_data['refunded_by'] != NULL && $extra_options_data['refunded_by'] != '') {
                                            $refunded_by_details = json_decode($extra_options_data['refunded_by']);
                                            foreach ($refunded_by_details as $array) {
                                                $refunded_extra_options[$array->refunded_by] = array(
                                                    'refunded_by' => (int) $array->refunded_by,
                                                    'count' => (int) $array->count
                                                );
                                            }
                                        }
                                        if ($extra_options_data['refund_quantity'] == NULL || $extra_options_data['refund_quantity'] == '') {
                                            $extra_options_data['refund_quantity'] = '0';
                                        }
                                        $refunded_extra_options[$refunded_by] = array(
                                            'refunded_by' => (int) $refunded_by,
                                            'count' => (int) ($refunded_extra_options[$refunded_by]['count'] + ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']))
                                        );
                                        sort($refunded_extra_options);
                                        if ($extra_options_data['ticket_price_schedule_id'] == "0") {
                                            $per_ticket_extra_options[$extra_options_data['extra_option_id'] . '_' . $extra_options_data['description']] = array(
                                                'main_description' => $extra_options_data['main_description'],
                                                'description' => $extra_options_data['description'],
                                                'quantity' => (int) $extra_options_data['quantity'],
                                                'refund_quantity' => (int) $extra_options_data['quantity'],
                                                'refunded_by' => $refunded_extra_options,
                                                'price' => (float) $extra_options_data['price'],
                                            );
                                        } else {
                                            $per_age_group_extra_options[$extra_options_data['ticket_price_schedule_id']][$extra_options_data['extra_option_id'] . '_' . $extra_options_data['description']] = array(
                                                'main_description' => $extra_options_data['main_description'],
                                                'description' => $extra_options_data['description'],
                                                'quantity' => (int) $extra_options_data['quantity'],
                                                'refund_quantity' => (int) $extra_options_data['quantity'],
                                                'refunded_by' => $refunded_extra_options,
                                                'price' => (float) $extra_options_data['price'],
                                            );
                                        }
                                        if (!empty($other_cancellation)) {
                                            $other_cancellation['amount'] = (float) ($other_cancellation['amount'] + ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity'])));
                                        }
                                        $extra_options_amount[date("Y-m-d", strtotime($extra_options_data['created']))]['amount'] = $extra_options_amount[date("Y-m-d", strtotime($extra_options_data['created']))]['amount'] + ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']));
                                        $total_amount += ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']));
                                        $query = "Update prepaid_extra_options set is_cancelled = '1', refund_quantity = '" . $extra_options_data['quantity'] . "', refunded_by = '" . json_encode($refunded_extra_options) . "' where prepaid_extra_options_id = '" . $extra_options_data['prepaid_extra_options_id'] . "'";
                                        $this->query($query);
                                        $sns_messages[] = $this->db->last_query();
                                        $refunded_extra_options = array();
                                    }
                                }
                            }

                            sort($shift_report_array);
                            // prepare array to refund amount in shift report and return in response
                            foreach ($shift_report_array[0] as $key => $report) {
                                $payments[] = array(
                                    "payment_type" => (int) 2,
                                    "amount" => (float) $report['amount'] + $extra_options_amount[$key]['amount'] - $discount_code_amount + $service_cost
                                );
                                if ($report['cashier_id'] == $refunded_by) {                                  
                                    $final_array[] = array(
                                        'shift_id' => (int) $report['shift_id'],
                                        'pos_point_id' => (int) $report['pos_point_id'],
                                        'cashier_id' => (int) $report['cashier_id'],
                                        'firebase_user_id' => $report['firebase_user_id'],
                                        'user_role' => (int) $report['user_role'],
                                        'booking_date' => $report['booking_date'],
                                        'amount' => (float) $report['amount'] + $extra_options_amount[$key]['amount'] - $discount_code_amount + $service_cost,
                                        'payments' => $activation_method == "9" ? $payments : array()
                                    );     
                                }
                                if ($report['cashier_id'] != $refunded_by) {
                                    $final_array[] = array(
                                        'shift_id' => (int) $report['shift_id'],
                                        'pos_point_id' => (int) $report['pos_point_id'],
                                        'cashier_id' => (int) $refunded_by,
                                        'firebase_user_id' => $user_firebase_ids[$refunded_by]['firebase_user_id'],
                                        'user_role' => (int) $user_firebase_ids[$refunded_by]['user_role'],
                                        'booking_date' => $report['booking_date'],
                                        'amount' => (float) $report['amount'] + $extra_options_amount[$key]['amount'] - $discount_code_amount + $service_cost,
                                        'payments' => $activation_method == "9" ? $payments : array()
                                    );
                                }
                            }

                            /*  Update cancel details at distributor level in Firebase if venue_app active for the user */
                            if (SYNC_WITH_FIREBASE == 1) {
                                $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $prepaid_data[0]['hotel_id'] . '"'));
                                if ($hotel_data[0]['is_venue_app_active'] == 1) {
                                    try {
                                        $MPOS_LOGS['DB'][] = 'FIREBASE';
                                        $headers = $this->all_headers(array(
                                            'hotel_id' => $prepaid_data[0]['hotel_id'],
                                            'ticket_id' => $ticket_id,
                                            'action' => 'sync_bookings_list_on_cancel_from_MPOS',
                                            'user_id' => $cashier_id
                                        ));
                                        foreach ($ticket_types_firebase_array as $tic_id => $array) {
                                            foreach ($array as $tps_id => $type_array) {
                                                sort($per_age_group_extra_options[$tps_id]);
                                                sort($refund_array[$tps_id]);
                                                $ticket_types_firebase_array[$tic_id][$tps_id]['refunded_by'] = $refund_array[$tps_id];
                                                $ticket_types_firebase_array[$tic_id][$tps_id]['per_age_group_extra_options'] = (!empty($per_age_group_extra_options[$tps_id])) ? $per_age_group_extra_options[$tps_id] : array();
                                            }
                                        }
                                        sort($per_ticket_extra_options);
                                        $update_values = array(
                                            'status' => (int) 3,
                                            'ticket_types' => $ticket_types_firebase_array[$ticket_id],
                                            'per_ticket_extra_options' => (!empty($per_ticket_extra_options)) ? $per_ticket_extra_options : array()
                                        );
                                        $update_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time);
                                        $this->curl->requestASYNC('FIREBASE', '/update_values_in_array', array(
                                            'type' => 'POST',
                                            'additional_headers' => $headers,
                                            'body' => array(
                                                "node" => 'distributor/bookings_list/' . $prepaid_data[0]['hotel_id'] . '/' . $cashier_id . '/' . date("Y-m-d", strtotime($prepaid_data[0]['created_date_time'])) . '/' . $update_key, 
                                                'details' => $update_values, 
                                                'update_key' => 'cancelled_tickets', 
                                                'update_value' => $cancelled_tickets
                                            )
                                        ));

                                        // sync status in order list 
                                        $order_date = date("Y-m-d", strtotime($prepaid_data[0]['created_date_time']));
                                        $current_date = $this->current_date;
                                        if (strtotime($order_date) >= strtotime($current_date)) {
                                            $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                                'type' => 'POST',
                                                'additional_headers' => $headers,
                                                'body' => array(
                                                    "node" => 'distributor/orders_list/' . $prepaid_data[0]['hotel_id'] . '/' . $cashier_id . '/' . date("Y-m-d", strtotime($prepaid_data[0]['created_date_time'])) . '/' . $visitor_group_no, 
                                                    'details' => array(
                                                        'status' => (int) 3
                                                    )
                                                )
                                            ));
                                        }

                                        if (!empty($other_cancellation)) {
                                            $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                                                'type' => 'POST',
                                                'additional_headers' => $headers,
                                                'body' => array(
                                                    "node" => 'distributor/others_cancellation/' . $prepaid_data[0]['hotel_id'] . '/' . $refunded_by . '/' . gmdate("Y-m-d"), 
                                                    'details' => $other_cancellation)
                                                ));
                                        }
                                    } catch (\Exception $e) {
                                        $logs['exception'] = $e->getMessage();
                                    }
                                }
                            }
                            //Refund adyen amount in case of card payment
                            if ($activation_method == "1" && $total_amount > 0) {
                                $this->refund($merchant_account, ($total_amount * 100), $psp_reference);
                            }
                            try {
                                require_once 'aws-php-sdk/aws-autoloader.php';
                                $this->load->library('Sns');
                                $this->load->library('Sqs');
                                if (!empty($sns_messages) || !empty($insertion_db2)) {
                                    $request_array['db2_insertion'] = $insertion_db2;
                                    $request_array['hotel_id'] = (!empty($insertion_db2)? $hotel_id: 0);
                                    $request_array['api_visitor_group_no'] = $visitor_group_no;
                                    $request_array['refund_prepaid_orders'] = $insert_prepaid_ticket_data;
                                    $request_array['update_visitor_tickets_for_refund_order'] = $cancel_tickets_from_firebase;
                                    $request_array['update_credit_limit_data'] = $update_credit_limit_data;
                                    $request_array['write_in_mpos_logs'] = 1;
                                    $request_array['order_payment_details'] = array(
                                        "visitor_group_no" => $visitor_group_no,
                                        "amount" => ($total_amount > 0) ? $total_amount : 0, 
                                        "total" => ($total_amount > 0) ? $total_amount : 0,
                                        "status" => 3, //partial refund
                                        "type" => 2, //refund
                                        "refund_type" => 1,
                                        "settled_on" => date("Y-m-d H:i:s"),
                                        "cashier_id" => $refunded_by,
                                        "cashier_name" => $refunded_by_user,
                                        "updated_at"=> date("Y-m-d H:i:s"),
                                        "refunded_entry" => 1
                                    );
                                    if(!empty($ticket_booking_ids)) {
                                        $request_array['order_payment_details']['ticket_booking_id'] = implode(",", array_unique($ticket_booking_ids));
                                    }
                                    $request_array['action'] = "cancel";
                                    $request_array['visitor_group_no'] = $visitor_group_no;
                                    $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                                    $request_string = json_encode($request_array);
                                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                    if (LOCAL_ENVIRONMENT == 'Local') {
                                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                                    } else {
                                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                                    }
                                }
                                if (!empty($notify_data)) {
                                    foreach ($notify_data as $key => $data) {
                                        sort($data['booking_data']['ticket_type']);
                                        $notify_data[$key] = $data;
                                    }
                                    sort($notify_data);
                                    $logs['data_to_queue_THIRD_PARTY_NOTIFY_QUEUE_' . date('H:i:s')] = $notify_data;
                                    $request_string = json_encode($notify_data);
                                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                    $queueUrl = THIRD_PARTY_NOTIFY_QUEUE;
                                    if (LOCAL_ENVIRONMENT == 'Local') {
                                        local_queue_helper::local_queue($aws_message, 'THIRD_PARTY_NOTIFY_ARN');
                                    } else {
                                        $sqs_object = new \Sqs();
                                        // This Fn used to send notification with data on AWS panel. 

                                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                        // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                        if ($MessageId) {
                                            $sns_object = new \Sns();
                                            $sns_object->publish($MessageId . '#~#' . $queueUrl, THIRD_PARTY_NOTIFY_ARN); //SNS link
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $logs['exception'] = $e->getMessage();
                            }

                            $response['status'] = (int) 1;
                            $response['shift_id'] = ($prepaid_data[0]['shift_id'] != '' && $prepaid_data[0]['shift_id'] != NULL) ? (int) $prepaid_data[0]['shift_id'] : (int) 0;
                            $response['pos_point_id'] = ($prepaid_data[0]['pos_point_id'] != '' && $prepaid_data[0]['pos_point_id'] != NULL) ? (int) $prepaid_data[0]['pos_point_id'] : (int) 0;
                            $response['payment_type'] = ($activation_method == "3") ? (int) 2 : (int) $activation_method;
                            $response['total_amount'] = (float) $total_amount;
                            $response['shift_report_array'] = !empty($final_array) ? $final_array : array();
                            $response['cancelled_tickets'] = (!empty($return_cancelled_ticket_details)) ? array_values($return_cancelled_ticket_details) : array();
                            $response['message'] = 'Booking cancelled successfully';
                        }
                        $internal_logs['cancel'] = $internal_log;
                    } else if ($used_tickets > 0 || $redeemed_tickets > 0) {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'This booking is already redeemed.';
                    } else {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'This booking is already cancelled.';
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['errorMessage'] = 'Previous request is running. Please try after some time.';
                }
            } else {
                $response['status'] = (int) 0;
                $response['errorMessage'] = 'This booking is not valid.';
            }
            $MPOS_LOGS['cancel'] = $logs;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['cancel'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name cancel
     * @Purpose This function is used to cancel the order and if order is confirmed, then refund the order and update firebase
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 3 Nov 2017
     */
    function cancel_order($hotel_id = '', $data = array()) {

        try {
            global $MPOS_LOGS;
            global $internal_logs;
            $logs['hotel_id'] = $hotel_id;
            $sns_messages = array();
            $insertion_db2 = array();
            $visitor_group_no = $data['order_id'];
            $cluster_ids = (isset($data['cluster_ids'])) ? $data['cluster_ids'] : array();
            $cashier_id = $data['cashier_id'];
            $refunded_by = (isset($data['refunded_by'])) ? $data['refunded_by'] : 0;
            $start_amount = (isset($data['start_amount'])) ? $data['start_amount'] : 0;
            if ($refunded_by == "0") {
                $refunded_by = $cashier_id;
            }
            
            $action_performed = (isset($data['action_performed'])) ? $this->REFUND_ACTIONS[$data['action_performed']] : 'MPOS_CC_RFN';
            if ($data['cancel_time_limit'] != '') {
                $cancel_time_limit = $data['cancel_time_limit'];
                $cancelled_time = gmdate("Y-m-d H:i:s", strtotime('-' . $cancel_time_limit));
            }
            $order_confirm_date = gmdate("Y-m-d H:i:s");
            /* Where conditions to update prepaid tickets */
            $mpos_request = $this->query('select * from mpos_requests where status = "1" and visitor_group_no = ' . $this->db->escape($visitor_group_no) . '', 1);
            $logs['mpos_requests_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
            $internal_log['mpos_requests_response_' . date('H:i:s')] = $mpos_request;
            /* Fetch passes and other required info from prepaid tickets */
            if (!empty($cluster_ids)) {
                $query = 'select * from prepaid_tickets where hotel_id = ' . $this->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->db->escape($visitor_group_no) . ' and (is_addon_ticket = "0" or is_addon_ticket = "1" or clustering_id IN (' . implode(',', $this->db->escape($cluster_ids)) . '))';
                $prepaid_data = $this->query($query, 1);
            } else {
                $query = 'select * from prepaid_tickets where hotel_id = ' . $this->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->db->escape($visitor_group_no) . '';
                $prepaid_data = $this->query($query, 1);
            }
            $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
            $internal_log['pt_response_' . date('H:i:s')] = $prepaid_data;
            if (!empty($prepaid_data) && (!empty($mpos_request)))  {
                if (($data['cancel_time_limit'] != '' && $prepaid_data[0]['created_date_time'] > $cancelled_time) || $data['cancel_time_limit'] == '' || $data['cashier_type'] == "2" || $data['cashier_type'] == "3") {
                    //Check if cancel time limit is set from pos_point_settings 
                    $activation_method = $prepaid_data[0]['activation_method'];
                    $cancelled_tickets = 0;
                    $used_tickets = 0;
                    $cancelled_ticket_array = $prev_cancelled_tickets = $prepaid_ticket_ids = $ticket_booking_ids = $visitor_ticket_ids = $return_cancelled_ticket_details = array();
                    $activation_method = $prepaid_data[0]['activation_method'];
                    $redeemed_tickets = 0;
                    $cant_cancel = 0;
                    foreach ($prepaid_data as $row) {
                        /* non cluster check, cluster id check, non cluster ticket in cluster + non cluster case */
                        if ((empty($cluster_ids)) || ((!empty($cluster_ids)) && in_array($row['clustering_id'], $cluster_ids)) || ((!empty($cluster_ids)) && !in_array($row['clustering_id'], $cluster_ids) && $row['is_addon_ticket'] == "0" && $row['is_refunded'] == "0")) {
                            $order_details = unserialize($row['additional_information']);
                            $ticket_type = ucfirst(strtolower($row['ticket_type']));
                            //Supervisor, admin and MPM can cancel redeemed orders
                            if ($data['cashier_type'] == "2" || $data['cashier_type'] == "3" || $data['cashier_type'] == "5" || ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '')) {
                                if ($row['used'] == 1) {
                                    $row['prev_used'] = 1;
                                }
                                $row['used'] = 0;
                                $row['prev_bleep_pass_no'] = $row['bleep_pass_no'];
                                $row['bleep_pass_no'] = '';
                            }
                            // prepare array for cancelled tickets
                            $extra_discount = unserialize($row['extra_discount']);
                            if ($row['is_refunded'] == "0" && $row['used'] == 0 && ($row['bleep_pass_no'] == "" || ($row['bleep_pass_no'] != '' && $order_details['add_to_pass'] == "1"))) {
                                $prepaid_data_update[] = $row;
                                if ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1") {
                                    $cancelled_tickets_array[$visitor_group_no . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']] = array(
                                        'ticket_id' => $row['ticket_id'],
                                        'selected_date' => $row['selected_date'],
                                        'from_time' => $row['from_time'],
                                        'cashier_id' => $row['cashier_id'],
                                        'to_time' => $row['to_time'],
                                        'booking_date_time' => $row['created_date_time'],
                                        'created_date_time' => date("Y-m-d", strtotime($row['created_date_time'])),
                                        'quantity' => $cancelled_tickets_array[$visitor_group_no . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['quantity'] + 1,
                                    );
                                    $cancelled_pass[$row['tps_id']][$row['passNo']] = $row['passNo'];
                                    $discount = (!empty($extra_discount)) ? $extra_discount['gross_discount_amount'] : 0;
                                    /* prepare array for device */
                                    $return_cancelled_ticket_details[$row['tps_id']] = array(
                                        'tps_id' => (int) $row['tps_id'],
                                        'quantity' => (int) ($return_cancelled_ticket_details[$row['tps_id']]['quantity'] + 1),
                                        'ticket_type' => (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? (int) $this->TICKET_TYPES[$ticket_type] : (int) 10,
                                        'ticket_type_label' => (string) $ticket_type,
                                        'pass_no' => array_values($cancelled_pass[$row['tps_id']]),
                                        'price' => (float) $row['price'],
                                        'cashier_discount' => (float) ($return_cancelled_ticket_details[$row['tps_id']]['cashier_discount'] + $discount),
                                    );
                                    $return_cancelled_ticket_details = $this->common_model->sort_ticket_types($return_cancelled_ticket_details);
                                    $cancelled_tickets++;
                                }
                                if ($refunded_by != $row['cashier_id'] && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1")) {
                                    $other_cancellation = array(
                                        'amount' => (float) $other_cancellation['amount'] + $row['price'],
                                        'cashier_id' => (int) $row['cashier_id']
                                    );
                                }
                                $ticket_booking_ids[] = $row['ticket_booking_id'];
                                $prepaid_ticket_ids[] = $row['prepaid_ticket_id'];
                                $visitor_ticket_ids[] = $row['visitor_tickets_id'];
                            }
                            if ($row['is_refunded'] == "2") {
                                $refund_array[$row['ticket_id']][$row['tps_id']][$row['refunded_by']] = array(
                                    'refunded_by' => (int) $row['refunded_by'],
                                    'count' => (int) $refund_array[$row['ticket_id']][$row['tps_id']][$row['refunded_by']]['count'] + 1
                                );
                                $prev_cancelled_tickets[$visitor_group_no . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']] = array(
                                    'quantity' => $prev_cancelled_tickets[$visitor_group_no . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['quantity'] + 1,
                                );
                            }
                            if ($row['used'] == "1") {
                                $used_tickets++;
                            }
                            $track_time['step-3'] = gmdate("Y-m-d H:i:s");
                            if ($row['bleep_pass_no'] != '' && !isset($order_details['add_to_pass'])) {
                                $redeemed_tickets++;
                            }
                            if ($row['from_time'] != "" && $row['from_time'] !== "0" && $row['to_time'] != "" && $row['to_time'] !== "0" &&
                                    $row['used'] == "0" && $row['is_refunded'] == "0" &&
                                    ($row['bleep_pass_no'] == "" || ($row['bleep_pass_no'] != '' && $order_details['add_to_pass'] == "1"))) {
                                $cancelled_ticket_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']][$row['tps_id']] = array(
                                    'capacity_count' => $cancelled_ticket_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']][$row['tps_id']]['capacity_count'] + 1,
                                    'group_count' => $cancelled_ticket_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']][$row['tps_id']]['group_count'] + $row['group_quantity'],
                                    'group_price' => $row['group_price'],
                                    'selected_date' => $row['selected_date'],
                                    'from_time' => $row['from_time'],
                                    'to_time' => $row['to_time'],
                                    'ticket_id' => $row['ticket_id'],
                                    'capacity' => $row['capacity']
                                );
                            }

                            if (($row['is_refunded'] == "0" || $row['is_refunded'] == "2") && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1")) {
                                if ($row['used'] == "0" && $row['is_refunded'] == "0") {
                                    $refund_array[$row['ticket_id']][$row['tps_id']][$refunded_by] = array(
                                        'refunded_by' => (int) $refunded_by,
                                        'count' => (int) $refund_array[$row['ticket_id']][$row['tps_id']][$refunded_by]['count'] + 1
                                    );
                                }
                                if (($row['used'] == "0" && $row['is_refunded'] == "0") || $row['is_refunded'] == "2") {
                                    $refunded_pass[$row['tps_id']][] = $row['passNo'];
                                }
                                $cashier_ids[$row['cashier_id']] = $row['cashier_id'];
                                $cashier_ids[$refunded_by] = $refunded_by;
                                $extra_discount = unserialize($row['extra_discount']);
                                $ticket_type = ucfirst(strtolower($row['ticket_type']));
                                /* data for firebase */
                                $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']] = array(
                                    'tps_id' => (int) $row['tps_id'],
                                    'quantity' => (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['quantity'] + 1),
                                    'refund_quantity' => ($row['used'] != "1") ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'],
                                    'type' => $ticket_type,
                                    'tax' => (float) $row['tax'],
                                    'cashier_discount' => (float) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                                    'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                                    'age_group' => $row['age_group'],
                                    'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                                    'net_price' => (float) ( $row['net_price'] + $extra_discount['net_discount_amount']),
                                    'per_age_group_extra_options' => array(),
                                    'refunded_by' => array(),
                                    'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                                    'refunded_passes' => array_values($refunded_pass[$row['tps_id']]),
                                );
                            }
                        } else if (((!empty($cluster_ids)) && !in_array($row['clustering_id'], $cluster_ids) && $row['is_addon_ticket'] == "0") && ($row['is_refunded'] == "2" && $row['is_addon_ticket'] == "0")) {
                            /* data of previously cancelled tickets for Firebase syncing */
                            $refund_array[$row['ticket_id']][$row['tps_id']][$row['refunded_by']] = array(
                                'refunded_by' => (int) $row['refunded_by'],
                                'count' => (int) $refund_array[$row['ticket_id']][$row['tps_id']][$row['refunded_by']]['count'] + 1
                            );
                            $refunded_pass[$row['tps_id']][] = $row['passNo'];
                            $extra_discount = unserialize($row['extra_discount']);
                            $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']] = array(
                                'tps_id' => (int) $row['tps_id'],
                                'quantity' => (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['quantity'] + 1),
                                'refund_quantity' => ($row['is_refunded'] == "2") ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['refund_quantity'],
                                'type' => $row['ticket_type'],
                                'tax' => (float) $row['tax'],
                                'cashier_discount' => (float) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                                'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                                'age_group' => $row['age_group'],
                                'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                                'net_price' => (float) ( $row['net_price'] + $extra_discount['net_discount_amount']),
                                'per_age_group_extra_options' => array(),
                                'refunded_by' => array(),
                                'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                                'refunded_passes' => array_values($refunded_pass[$row['tps_id']]),
                            );
                        }
                        //to update third party for cancel
                        if (($row['channel_type'] == '10' || $row['channel_type'] == '11') && $row['third_party_type'] == 5) {
                            $order_reference = '';
                            if ($row['without_elo_reference_no'] != '') {
                                $order_reference = $row['without_elo_reference_no'];
                            }
                            $booking_date = $row['selected_date'];
                            $booking_time = $row['from_time'];
                            $notify_key = $prepaid_data['ticket_id'] . '_' . $booking_date . '_' . $booking_time;
                            $notify_data[$notify_key]['request_type'] = 'cancel';
                            $notify_data[$notify_key]['booking_data']['distributor_id'] = $row['hotel_id'];
                            $notify_data[$notify_key]['booking_data']['channel_type'] = $row['channel_type'];
                            $notify_data[$notify_key]['booking_data']['ticket_id'] = $row['ticket_id'];
                            $notify_data[$notify_key]['booking_data']['booking_date'] = $booking_date;
                            $notify_data[$notify_key]['booking_data']['booking_time'] = $booking_time;
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['tps_id'] = (int) $row['tps_id'];
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['ticket_type'] = $row['ticket_type'];
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$row['tps_id']]['count'] += 1;
                            $notify_data[$notify_key]['booking_data']['booking_reference'] = isset($order_reference) ? $order_reference : '';
                            $notify_data[$notify_key]['booking_data']['barcode'] = $row['passNo'];
                            $notify_data[$notify_key]['booking_data']['integration_booking_code'] = $row['visitor_group_no'];
                            $notify_data[$notify_key]['booking_data']['customer']['name'] = '';
                            $notify_data[$notify_key]['third_party_data']['agent'] = 'CEXcursiones';
                        }

                        if ($row['activation_method'] != '10' && $row['activation_method'] != '19' && $row['is_refunded'] == '0') {
                            $update_credit_limit = array();
                            $update_credit_limit['museum_id'] = $row['museum_id'];
                            $update_credit_limit['reseller_id'] = $row['reseller_id'];
                            $update_credit_limit['hotel_id'] = $row['hotel_id'];
                            $update_credit_limit['partner_id'] = $row['distributor_partner_id'];
                            $update_credit_limit['cashier_name'] = $row['distributor_partner_name'];
                            $update_credit_limit['hotel_name'] = $row['hotel_name'];
                            $update_credit_limit['visitor_group_no'] = $row['visitor_group_no'];
                            $update_credit_limit['merchant_admin_id'] = $row['merchant_admin_id'];
                            $update_credit_limit['channel_type'] = $row['channel_type'];
                            if (array_key_exists($row['museum_id'], $update_credit_limit_data)) {
                                $update_credit_limit_data[$row['museum_id']]['used_limit'] += $row['price'];
                            } else {
                                $update_credit_limit['used_limit'] = $row['price'];
                                $update_credit_limit_data[$row['museum_id']] = $update_credit_limit;
                            }
                        }

                        if ($cant_cancel == 0 && $data['action_performed'] != '6' && $row['scanned_at'] != '' && $row['scanned_at'] != NULL && $row['scanned_at'] >= strtotime("-2 minutes")) {
                            $cant_cancel = 1;
                        }
                    }
                    if ($cant_cancel == 0) {
                        $refunded_by_user = '';
                        $order_cancellation_date = date('Y-m-d H:i:s');
                        $user_details = $this->find('users', array('select' => 'id, firebase_user_id, user_role, fname, lname', 'where' => 'id IN (' . implode(',', $cashier_ids) . ') '));
                        foreach ($user_details as $user) {
                            $user_firebase_ids[$user['id']]['firebase_user_id'] = $user['firebase_user_id'];
                            $user_firebase_ids[$user['id']]['user_role'] = $user['user_role'];
                            if ($user['id'] == $refunded_by) {
                                $refunded_by_user = $user['fname'] . ' ' . $user['lname'];
                            }
                        }
                        if ($cancelled_tickets > 0) {
                            if ($prepaid_data_update[0]['booking_status'] == '1') {
                                $order_status = '2';
                            } else {
                                $order_status = '1';
                            }
                            /* In case of payment by card, need payment references from HTO */
                            $user_data = $this->find('hotel_ticket_overview', array('select' => 'channel_type, merchantAccountCode, pspReference, merchantReference, hotel_name, activation_method, paymentMethod', 'where' => 'hotel_id = "' . $hotel_id . '" and visitor_group_no = "' . $visitor_group_no . '"', 'limit' => 1));
                            $merchant_account = $user_data[0]['merchantAccountCode'];
                            $psp_reference = $user_data[0]['pspReference'];
                            if ($visitor_group_no != '') {
                                $cancel_tickets_from_firebase = array(
                                    'vt_group_no' => $visitor_group_no,
                                    'is_refunded' => "0",
                                    'visitor_tickets_id' => $visitor_ticket_ids,
                                    'action_performed' => $action_performed,
                                    'order_confirm_date' => $order_confirm_date,
                                    'refunded_by' => $refunded_by,
                                    'refunded_by_user' => $refunded_by_user,
                                    'order_cancellation_date' => $order_cancellation_date
                                );

                                /*  Fetch unused and non-cancelled tickets count and update sold count in ticket_capacity_v1 */
                                if (!empty($cancelled_ticket_array)) {
                                    foreach ($cancelled_ticket_array as $cancelled_tickets) {
                                        $capacity = 0;
                                        foreach ($cancelled_tickets as $cancelled_tickets_data) {
                                            $capacity += $cancelled_tickets_data['capacity_count'] * $cancelled_tickets_data['capacity'];
                                            if ($cancelled_tickets_data['group_price'] == '1') {
                                                $capacity += $cancelled_tickets_data['group_count'];
                                            }
                                            $change_ticket_id = $cancelled_tickets_data['ticket_id'];
                                            $change_date = $cancelled_tickets_data['selected_date'];
                                            $change_from_time = $cancelled_tickets_data['from_time'];
                                            $change_to_time = $cancelled_tickets_data['to_time'];
                                        }
                                        if ($capacity > 0 && $change_date != '' && $change_date != '0') {
                                            $this->update_capacity_on_cancel($capacity, $change_ticket_id, $change_date, $change_from_time, $change_to_time);
                                        }
                                    }
                                }
                                /* update prepaid_extra_options */
                                $query = 'select refunded_by, refund_quantity, quantity, ticket_id, ticket_price_schedule_id, extra_option_id, description, main_description, price, created, prepaid_extra_options_id, variation_type from prepaid_extra_options where visitor_group_no = "' . $visitor_group_no . '"';
                                $extra_options = $this->query($query, 1);
                                $total_amount = 0;
                                if (!empty($extra_options)) {
                                    $refunded_extra_options = array();
                                    foreach ($extra_options as $extra_options_data) {
                                        if($extra_options_data['variation_type'] == 0){
                                            if ($extra_options_data['refunded_by'] != NULL && $extra_options_data['refunded_by'] != '') {
                                                $refunded_by_details = json_decode($extra_options_data['refunded_by']);
                                                foreach ($refunded_by_details as $array) {
                                                    $refunded_extra_options[$array->refunded_by] = array(
                                                        'refunded_by' => (int) $array->refunded_by,
                                                        'count' => (int) $array->count
                                                    );
                                                }
                                            }
                                            if ($extra_options_data['refund_quantity'] == NULL || $extra_options_data['refund_quantity'] == '') {
                                                $extra_options_data['refund_quantity'] = '0';
                                            }
                                            $refunded_extra_options[$refunded_by] = array(
                                                'refunded_by' => (int) $refunded_by,
                                                'count' => (int) ($refunded_extra_options[$refunded_by]['count'] + ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']))
                                            );
                                            sort($refunded_extra_options);
                                            if ($extra_options_data['ticket_price_schedule_id'] == "0") {
                                                $per_ticket_extra_options[$extra_options_data['ticket_id']][$extra_options_data['extra_option_id'] . '_' . $extra_options_data['description']] = array(
                                                    'main_description' => $extra_options_data['main_description'],
                                                    'description' => $extra_options_data['description'],
                                                    'quantity' => (int) $extra_options_data['quantity'],
                                                    'refund_quantity' => (int) $extra_options_data['quantity'],
                                                    'refunded_by' => $refunded_extra_options,
                                                    'price' => (float) $extra_options_data['price'],
                                                );
                                            } else {
                                                $per_age_group_extra_options[$extra_options_data['ticket_id']][$extra_options_data['ticket_price_schedule_id']][$extra_options_data['extra_option_id'] . '_' . $extra_options_data['description']] = array(
                                                    'main_description' => $extra_options_data['main_description'],
                                                    'description' => $extra_options_data['description'],
                                                    'quantity' => (int) $extra_options_data['quantity'],
                                                    'refund_quantity' => (int) $extra_options_data['quantity'],
                                                    'refunded_by' => $refunded_extra_options,
                                                    'price' => (float) $extra_options_data['price'],
                                                );
                                            }
                                            if (!empty($other_cancellation)) {
                                                $other_cancellation['amount'] = (float) ($other_cancellation['amount'] + ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity'])));
                                            }
                                            $extra_options_amount[date("Y-m-d", strtotime($extra_options_data['created']))]['amount'] = $extra_options_amount[date("Y-m-d", strtotime($extra_options_data['created']))]['amount'] + ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']));
                                            $total_amount += ($extra_options_data['price'] * ($extra_options_data['quantity'] - $extra_options_data['refund_quantity']));
                                            $query = "Update prepaid_extra_options set is_cancelled = '1', refund_quantity = '" . $extra_options_data['quantity'] . "', refunded_by = '" . json_encode($refunded_extra_options) . "' where prepaid_extra_options_id = '" . $extra_options_data['prepaid_extra_options_id'] . "'";
                                            $this->query($query);
                                            $sns_messages[] = $this->db->last_query();
                                            $refunded_extra_options = array();
                                        }
                                    }
                                }
                                $pt_db1_cols = 'is_refunded = "2", activated = "0", action_performed = concat(action_performed, ", ' . $action_performed . '"), refunded_by = "' . $refunded_by . '"';
                                $where_cond = 'where visitor_group_no = "' . $visitor_group_no . '" and is_refunded = "0" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')';
                                $where = 'visitor_group_no = "' . $visitor_group_no . '" and is_refunded = "0" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ')';
                                /*  update is_refunded field of prepaid tickets, is_cancelled of prepaid_extra_options where used is 0 */
                                $this->query('update prepaid_tickets set '.$pt_db1_cols.' '.$where_cond, 0);
                                
                                $logs['refund_pt_db1_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                                                                
                                /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                                $updateCols = array("is_refunded"                   => '2', 
                                                    "activated"                     => '0',
                                                    "CONCAT_VALUE"                  => array("action_performed" => ', '.$action_performed), 
                                                    "refunded_by"                   => $refunded_by,  
                                                    "order_cancellation_date"       => $order_cancellation_date, 
                                                    "order_updated_cashier_id"      => $refunded_by, 
                                                    "order_updated_cashier_name"    => $refunded_by_user, 
                                                    "updated_at"                    => gmdate("Y-m-d H:i:s"));
                                $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where, "unset_updated_at" => 0);
                                
                                
                                /* Add new row in PT for isrefunded 1 and with order status */
                                $insert_prepaid_ticket_data = array();
                                foreach ($prepaid_data_update as $prepaid_ticket_record) {
                                    $insert_prepaid_ticket = array();
                                    $insert_prepaid_ticket = $prepaid_ticket_record;
                                    $cashier_discount = $extra_option_discount['gross_discount_amount'];
                                    if ($insert_prepaid_ticket['is_addon_ticket'] == 0 || $insert_prepaid_ticket['is_addon_ticket'] == 1) {
                                        $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id'] . '_' . $insert_prepaid_ticket['cashier_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))] = array(
                                            'shift_id' => (int) $insert_prepaid_ticket['shift_id'],
                                            'cashier_id' => (int) $insert_prepaid_ticket['cashier_id'],
                                            'firebase_user_id' => $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['firebase_user_id'],
                                            'user_role' => (int) $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['user_role'],
                                            'pos_point_id' => (int) $insert_prepaid_ticket['pos_point_id'],
                                            'booking_date' => date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time'])),
                                            'amount' => $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id'] . '_' . $insert_prepaid_ticket['cashier_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))]['amount'] + $insert_prepaid_ticket['price'] - $cashier_discount
                                        );
                                        $total_amount += $insert_prepaid_ticket['price'];
                                    }
                                    $insert_prepaid_ticket['is_refunded'] = '1';
                                    $insert_prepaid_ticket['activated'] = '0';
                                    $insert_prepaid_ticket['order_cancellation_date'] = $order_cancellation_date;
                                    $insert_prepaid_ticket['order_updated_cashier_id'] = $refunded_by;
                                    $insert_prepaid_ticket['order_updated_cashier_name'] = $refunded_by_user;
                                    $insert_prepaid_ticket['order_status'] = $order_status;
                                    $insert_prepaid_ticket['refunded_by'] = $refunded_by;
                                    $insert_prepaid_ticket['action_performed'] = '0, ' . $action_performed;
                                    $insert_prepaid_ticket['updated_at'] = gmdate("Y-m-d H:i:s");                                    
                                    $insert_prepaid_ticket['order_confirm_date'] = $order_confirm_date;
                                    $insert_prepaid_ticket['is_cancelled'] = '1';
                                    $insert_prepaid_ticket['created_at'] = strtotime(date('Y-m-d H:i:s'));

                                    $insert_prepaid_ticket['bleep_pass_no'] = isset($insert_prepaid_ticket['prev_bleep_pass_no']) ? (string) $insert_prepaid_ticket['prev_bleep_pass_no'] : $insert_prepaid_ticket['bleep_pass_no'];
                                    $insert_prepaid_ticket['used'] = ($insert_prepaid_ticket['prev_used'] == 1) ? (string) $insert_prepaid_ticket['prev_used'] : (string) $insert_prepaid_ticket['used'];
                                    unset($insert_prepaid_ticket['prev_used']);
                                    unset($insert_prepaid_ticket['prev_bleep_pass_no']);
                                    unset($insert_prepaid_ticket['last_modified_at']);
                                    $insert_prepaid_ticket['start_amount'] = $start_amount;
                                    $pt_id = $insert_prepaid_ticket['prepaid_ticket_id'];
                                    unset($insert_prepaid_ticket['prepaid_ticket_id']);
                                    unset($insert_prepaid_ticket['authcode']);
                                    unset($insert_prepaid_ticket['redemption_notified_at']);
                                    $insert_prepaid_ticket_data[$pt_id] = $insert_prepaid_ticket;
                                }
                                if ($discount_code_amount > 0) {
                                    $total_amount = $total_amount - $discount_code_amount;
                                }
                                $service_cost = 0;
                                $total_amount = $total_amount + $service_cost;
                                if (!empty($other_cancellation)) {
                                    $other_cancellation['amount'] = (float) ($other_cancellation['amount'] + $service_cost);
                                }

                                sort($shift_report_array);
                                // prepare array to refund amount in shift report and return in response
                                foreach ($shift_report_array as $array) {
                                    foreach ($array as $key => $report) {
                                        $payments[] = array(
                                            "payment_type" => (int) 2,
                                            "amount" => (float) $total_amount
                                        );
                                        if($report['cashier_id'] == $refunded_by ) {
                                            $final_array[$report['cashier_id']] = array(
                                                'shift_id' => (int) $report['shift_id'],
                                                'pos_point_id' => (int) $report['pos_point_id'],
                                                'cashier_id' => (int) $report['cashier_id'],
                                                'firebase_user_id' => $report['firebase_user_id'],
                                                'user_role' => (int) $report['user_role'],
                                                'booking_date' => $report['booking_date'],
                                                'amount' => (float) $report['amount'] + $extra_options_amount[$key]['amount'] + $service_cost,
                                                'payments' => $activation_method == "9" ? $payments : array()
                                            );
                                        }
                                        
                                        if ($report['cashier_id'] != $refunded_by && $refunded_by != "0") {
                                            $payments_array[] = array(
                                                "payment_type" => (int) 2,
                                                "amount" => (float) ( $final_array[$refunded_by]['amount'] + $report['amount'] + $extra_options_amount[$key]['amount'] + $service_cost)
                                            );
                                            $final_array[$refunded_by] = array(
                                                'shift_id' => (int) $report['shift_id'],
                                                'pos_point_id' => (int) $report['pos_point_id'],
                                                'cashier_id' => (int) $refunded_by,
                                                'firebase_user_id' => $user_firebase_ids[$refunded_by]['firebase_user_id'],
                                                'user_role' => (int) $user_firebase_ids[$refunded_by]['user_role'],
                                                'booking_date' => $report['booking_date'],
                                                'amount' => (float) ( $final_array[$refunded_by]['amount'] + $report['amount'] + $extra_options_amount[$key]['amount'] + $service_cost),
                                                'payments' => $activation_method == "9" ? $payments_array : array()
                                            );
                                        }
                                    }
                                }
                                sort($final_array);
                                /*  Update cancel details at distributor level in Firebase if venue_app active for the user */
                                if (SYNC_WITH_FIREBASE == 1) {
                                    $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $prepaid_data[0]['hotel_id'] . '"'));
                                    if ($hotel_data[0]['is_venue_app_active'] == 1) {
                                        try {
                                            $logs['req-cancel_' . date('H:i:s')] = $cancelled_tickets_array;
                                            foreach ($ticket_types_firebase_array as $ticket_id => $tps_array) {
                                                foreach ($tps_array as $tps_id => $array) {
                                                    sort($per_age_group_extra_options[$ticket_id][$tps_id]);
                                                    sort($refund_array[$ticket_id][$tps_id]);
                                                    $ticket_types_firebase_array[$ticket_id][$tps_id]['refunded_by'] = $refund_array[$ticket_id][$tps_id];
                                                    $ticket_types_firebase_array[$ticket_id][$tps_id]['per_age_group_extra_options'] = $per_age_group_extra_options[$ticket_id][$tps_id];
                                                }
                                            }
                                            if (!empty($cancelled_tickets_array)) {
                                                $MPOS_LOGS['DB'][] = 'FIREBASE';
                                                foreach ($cancelled_tickets_array as $key => $array) {
                                                    $headers = $this->all_headers(array(
                                                        'hotel_id' => $prepaid_data[0]['hotel_id'],
                                                        'museum_id' => $prepaid_data[0]['museum_id'],
                                                        'channel_type' => $prepaid_data[0]['channel_type'],
                                                        'ticket_id' => $array['ticket_id'],
                                                        'action' => 'sync_bookings_list_on_cancel_order',
                                                        'user_id' => $array['cashier_id']
                                                    ));

                                                    sort($per_ticket_extra_options[$array['ticket_id']]);
                                                    $update_values = array(
                                                        'status' => (int) 3,
                                                        'ticket_types' => $ticket_types_firebase_array[$array['ticket_id']],
                                                        'per_ticket_extra_options' => $per_ticket_extra_options[$array['ticket_id']]
                                                    );
                                                    $update_key = base64_encode($visitor_group_no . '_' . $array['ticket_id'] . '_' . $array['selected_date'] . '_' . $array['from_time'] . '_' . $array['to_time'] . '_' . $array['booking_date_time']);
                                                    $this->curl->requestASYNC('FIREBASE', '/update_values_in_array', array(
                                                        'type' => 'POST',
                                                        'additional_headers' => $headers,
                                                        'body' => array(
                                                            "node" => 'distributor/bookings_list/' . $prepaid_data[0]['hotel_id'] . '/' . $array['cashier_id'] . '/' . $array['created_date_time'] . '/' . $update_key, 
                                                            'details' => $update_values, 
                                                            'update_key' => 'cancelled_tickets', 
                                                            'update_value' => $array['quantity']
                                                        )
                                                    ));

                                                    // sync status in order list                                                 
                                                    $order_date = date("Y-m-d", strtotime($prepaid_data[0]['created_date_time']));
                                                    $current_date = $this->current_date;
                                                    if (strtotime($order_date) >= strtotime($current_date)) {
                                                        $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                                            'type' => 'POST',
                                                            'additional_headers' => $headers,
                                                            'body' => array(
                                                                "node" => 'distributor/orders_list/' . $prepaid_data[0]['hotel_id'] . '/' . $array['cashier_id'] . '/' . $array['created_date_time'] . '/' . $visitor_group_no, 
                                                                'details' => array(
                                                                    'status' => (int) 3
                                                                )
                                                            )
                                                        ));
                                                    }
                                                }
                                            }
                                            if (!empty($other_cancellation)) {
                                                $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                                                    'type' => 'POST',
                                                    'additional_headers' => $headers,
                                                    'body' => array("node" => 'distributor/others_cancellation/' . $prepaid_data[0]['hotel_id'] . '/' . $refunded_by . '/' . gmdate("Y-m-d"), 'details' => $other_cancellation)
                                                ));
                                            }
                                        } catch (\Exception $e) {
                                            $logs['exception'] = $e->getMessage();
                                        }
                                    }
                                }
                                //Refund adyen amount in case of card payment
                                if ($activation_method == "1" && $total_amount > 0) {
                                    $this->refund($merchant_account, ($total_amount * 100), $psp_reference);
                                }
                                try {
                                    require_once 'aws-php-sdk/aws-autoloader.php';
                                    $this->load->library('Sns');
                                    $this->load->library('Sqs');
                                    if (!empty($sns_messages) || !empty($insertion_db2)) {
                                        $request_array['db2_insertion'] = $insertion_db2;
                                        $request_array['hotel_id'] = (!empty($insertion_db2)? $hotel_id: 0);
                                        $request_array['api_visitor_group_no'] = $visitor_group_no;
                                        $request_array['refund_prepaid_orders'] = $insert_prepaid_ticket_data;
                                        $request_array['update_visitor_tickets_for_refund_order'] = $cancel_tickets_from_firebase;
                                        $request_array['update_credit_limit_data'] = $update_credit_limit_data;
                                        $request_array['write_in_mpos_logs'] = 1;
                                        $request_array['action'] = "cancel_order";
                                        $request_array['visitor_group_no'] = $visitor_group_no;
                                        $request_array['order_payment_details'] = array(
                                            "visitor_group_no" => $visitor_group_no,
                                            "amount" => ($total_amount > 0) ? $total_amount : 0, 
                                            "total" => ($total_amount > 0) ? $total_amount : 0,
                                            "status" => 3, //partial refund
                                            "type" => 2, //refund
                                            "refund_type" => 1,
                                            "settled_on" => date("Y-m-d H:i:s"),
                                            "cashier_id" => $refunded_by,
                                            "cashier_name" => $refunded_by_user,
                                            "updated_at"=> date("Y-m-d H:i:s"),
                                            "refunded_entry" => 1
                                        );
                                        if(!empty($ticket_booking_ids)) {
                                            $request_array['order_payment_details']['ticket_booking_id'] = implode(",", array_unique($ticket_booking_ids));
                                        }
                                        $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                                        $request_string = json_encode($request_array);
                                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                        if (LOCAL_ENVIRONMENT == 'Local') {
                                            local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                                        } else {
                                            $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                                        }
                                    }
                                    if (!empty($notify_data)) {
                                        foreach ($notify_data as $key => $data) {
                                            sort($data['booking_data']['ticket_type']);
                                            $notify_data[$key] = $data;
                                        }
                                        sort($notify_data);
                                        $logs['data_to_queue_THIRD_PARTY_NOTIFY_QUEUE_' . date('H:i:s')] = $notify_data;
                                        $request_string = json_encode($notify_data);
                                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                        $queueUrl = THIRD_PARTY_NOTIFY_QUEUE;
                                        if (LOCAL_ENVIRONMENT == 'Local') {
                                            local_queue_helper::local_queue($aws_message, 'THIRD_PARTY_NOTIFY_ARN');
                                        } else {
                                            $sqs_object = new \Sqs();
                                            // This Fn used to send notification with data on AWS panel. 
                                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                            if ($MessageId) {
                                                $sns_object = new \Sns();
                                                $sns_object->publish($MessageId . '#~#' . $queueUrl, THIRD_PARTY_NOTIFY_ARN); //SNS link
                                            }
                                        }
                                    }
                                } catch (\Exception $e) {
                                    $logs['exception'] = $e->getMessage();
                                }

                                $response['status'] = (int) 1;
                                $response['shift_id'] = ($prepaid_data[0]['shift_id'] != '' && $prepaid_data[0]['shift_id'] != NULL) ? (int) $prepaid_data[0]['shift_id'] : (int) 0;
                                $response['pos_point_id'] = ($prepaid_data[0]['pos_point_id'] != '' && $prepaid_data[0]['pos_point_id'] != NULL) ? (int) $prepaid_data[0]['pos_point_id'] : (int) 0;
                                $response['payment_type'] = ($activation_method == "3") ? (int) 2 : (int) $activation_method;
                                $response['total_amount'] = (float) $total_amount;
                                $response['shift_report_array'] = !empty($final_array) ? $final_array : array();
                                $response['cancelled_tickets'] = (!empty($return_cancelled_ticket_details)) ? array_values($return_cancelled_ticket_details) : array();
                                $response['message'] = 'Order cancelled successfully';
                            }
                        } else if ($cancelled_tickets == 0 && $used_tickets == 0) {
                            $response['status'] = (int) 0;
                            $response['errorMessage'] = 'Order already cancelled.';
                        } else if ($cancelled_tickets == 0 && ($used_tickets > 0 || $redeemed_tickets > 0)) {
                            $response['status'] = (int) 0;
                            $response['errorMessage'] = "This order is already redeemed. We can't cancel this order.";
                        }
                    } else {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'Previous request is running. Please try after some time.';
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['errorMessage'] = 'Time to cancel this booking has expired.';
                }
            } else {
                $this->common_model->save('firebase_pending_request', array('visitor_group_no' => $visitor_group_no, 'request_type' => 'cancel-order', 'request' => json_encode($data), 'added_at' => gmdate("Y-m-d H:i:s")));
                $response['status'] = (int) 1;
                $logs['firebase_pending_request_query' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                $response['message'] = 'Request added in pending request successfully.';
            }
            $MPOS_LOGS['cancel_order'] = $logs;
            $internal_logs['cancel_order'] = $internal_log;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['cancel_order'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name partial_cancel_order
     * @Purpose This function is used to partially cancel the order and if order is confirmed, then refund the order and update firebase
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 27 Dec 2017
     */
    function partial_cancel_order($hotel_id = '', $data = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $mpos_request = $this->query('select * from mpos_requests where visitor_group_no = ' . $this->db->escape($data['order_id']) . '', 1);
            $logs['mpos_requests_table_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
            if($mpos_request[0]['status'] != 1){
                $response['status'] = (int) 0;
                $response['errorMessage'] = 'This booking is in progress. Please try after sometime !';
                $logs['exception'] = 'This booking is in progress. Please try after sometime !';
                $MPOS_LOGS['partially_cancel_order'] = $logs;
                return $response;
            }

            $logs['hotel_id'] = $hotel_id;
            $sns_messages = array();
            if (!($data['from_time'] != '' && $data['from_time'] !== '0' && $data['to_time'] != '' && $data['to_time'] !== '0')) { //booking ticket
                $data['from_time'] = '0';
                $data['to_time'] = '0';
            }
            $visitor_group_no = $data['order_id'];
            $ticket_id = $data['ticket_id'];
            $selected_date = $data['selected_date'];
            $from_time = $data['from_time'];
            $to_time = $data['to_time'];
            $cashier_id = $data['cashier_id'];
            $refunded_by = (isset($data['refunded_by'])) ? $data['refunded_by'] : 0;
            $upsell_order = $data['upsell_order'];
            $start_amount = (isset($data['start_amount'])) ? $data['start_amount'] : 0;
            $action_performed = (isset($data['action_performed'])) ? $this->REFUND_ACTIONS[$data['action_performed']] : 'MPOS_PC_RFN';
            $created_date_time = $data['booking_date_time'];
            $canceled_array = $data['cancel_tickets'];
            $upsell_performed = $data['upsell_performed'];
            
            if ($data['cancel_time_limit'] != '') {
                $cancel_time_limit = $data['cancel_time_limit'];
                $cancelled_time = gmdate("Y-m-d H:i:s", strtotime('-' . $cancel_time_limit));
            }
            $order_confirm_date = gmdate("Y-m-d H:i:s");
            $cluster_ids = array();
            $canceled_array = $data['cancel_tickets'];
            foreach ($canceled_array as $array) {
                $cancel_tickets_array[$array['tps_id']] = $array;
                foreach ($array['cluster_ids'] as $id) {
                    $cluster_ids[$id] = $id;
                }
            }


            if ($upsell_performed == 1) {
                if (!empty($cluster_ids)) {
                    $upsell_condition = ' and (action_performed like "%UPSELL%" or clustering_id IN (' . implode(',', $cluster_ids) . '))';
                } else {
                    $upsell_condition = ' and action_performed like "%UPSELL%"';
                }
            } else if ($upsell_performed == 2) {
                $upsell_condition = ' and action_performed like "%UPSELL_INSERT%" ';
            }

            foreach ($data['extra_options'] as $row) {
                $cancel_extra_options_array[$row['extra_option_id'] . '_' . $row['description']] = $row['quantity'];
            }

            if (($data['cancel_time_limit'] != '' && $created_date_time > $cancelled_time) || $data['cancel_time_limit'] == "" || $data['cashier_type'] == "2" || $data['cashier_type'] == "3") {

                /* Fetch passes and other required info from prepaid tickets */
                if ($upsell_order == "1") {
                    if (!empty($cluster_ids)) {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . '  and ( is_addon_ticket = "0" or  is_addon_ticket = "1" or clustering_id IN (' . implode(',', $this->primarydb->db->escape($cluster_ids)) . '))' . $upsell_condition));
                    } else {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and selected_date = ' . $this->primarydb->db->escape($selected_date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ' and created_date_time = ' . $this->primarydb->db->escape($created_date_time) . ' and is_refunded = "0"' . $upsell_condition));
                    }
                } else {
                    if (!empty($cluster_ids)) {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . '  and ( is_addon_ticket = "0" or is_addon_ticket = "1" or clustering_id IN (' . implode(',', $this->primarydb->db->escape($cluster_ids)) . '))'));
                    } else {
                        $prepaid_data = $this->find('prepaid_tickets', array('select' => '*', 'where' => 'hotel_id = ' . $this->primarydb->db->escape($hotel_id) . ' and visitor_group_no = ' . $this->primarydb->db->escape($visitor_group_no) . ' and ticket_id = ' . $this->primarydb->db->escape($ticket_id) . ' and selected_date = ' . $this->primarydb->db->escape($selected_date) . ' and from_time = ' . $this->primarydb->db->escape($from_time) . ' and to_time = ' . $this->primarydb->db->escape($to_time) . ' and created_date_time = ' . $this->primarydb->db->escape($created_date_time) . ' and ( is_refunded = "0" or is_refunded = "2" )'));
                    }
                }
                $logs['pt_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['pt_response_' . date('H:i:s')] = $prepaid_data;
                $total_tickets_to_cancel = $used_tickets = $prev_cancelled_tickets = $redeemed_tickets = $cant_cancel = $cancel_tickets = 0;
                $cancelled_tickets_array = $prepaid_ticket_ids = $visitor_ticket_ids = $return_cancelled_ticket_details = $un_refunded_passes = $cancel_capacity = $ticket_booking_ids = array();
                $activation_method = $prepaid_data[0]['activation_method'];
                $logs['cancel_tickets_array'] = $cancel_tickets_array;
                foreach ($prepaid_data as $i => $row) {
                    if ((empty($cluster_ids)) || ((!empty($cluster_ids)) && in_array($row['clustering_id'], $cluster_ids))) {
                        //Prepare data for tickets other than cluster tickets and cluster tickets athat are selected for cancel
                        $order_details = unserialize($row['additional_information']);
                        //Supervisor, admin and MPM can cancel redeemed orders
                        if ($data['cashier_type'] == "2" || $data['cashier_type'] == "3" || $data['cashier_type'] == "5" || ($data['cashier_type'] == "6" && $row['bleep_pass_no'] != '' && $row['redeem_users'] == '')) {
                            if ($row['used'] == 1) {
                                $row['prev_used'] = 1;
                            }
                            $row['used'] = 0;
                            $row['prev_bleep_pass_no'] = $row['bleep_pass_no'];
                            $row['bleep_pass_no'] = '';
                        }
                        if ($row['is_refunded'] == "0" && ($row['bleep_pass_no'] == "" || ($row['bleep_pass_no'] != '' && $order_details['add_to_pass'] == "1"))) {
                            if ($row['used'] == 0) {
                                $key = $row['visitor_group_no'] . '_' . $row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time'] . '_' . $row['tps_id'] . '_' . $row['used'] . '_' . $row['is_refunded'];
                                $update_prepaid_data[$key][] = $row;
                                $total_tickets_to_cancel++;
                            }
                            $db_tickets[$row['tps_id']] = array(
                                'total_quantity' => $db_tickets[$row['tps_id']]['total_quantity'] + 1
                            );
                        }
                        if (($row['is_addon_ticket'] == '0' || $row['is_addon_ticket'] == '1') && $row['is_refunded'] == "2") {
                            $refund_array[$row['tps_id']][$row['refunded_by']] = array(
                                'refunded_by' => (int) $row['refunded_by'],
                                'count' => (int) $refund_array[$row['tps_id']][$row['refunded_by']]['count'] + 1
                            );
                            $prev_cancelled_tickets++;
                        }
                        if ($row['used'] == "1") {
                            $used_tickets++;
                        }
                        if ($row['bleep_pass_no'] != '' && !isset($order_details['add_to_pass'])) {
                            $redeemed_tickets++;
                        }
                        if ($row['is_refunded'] == '0') {
                            $un_refunded_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                        }
                        if ($row['is_combi_ticket'] == '1') {
                            $is_combi = 1;
                            $combi_total_passes[$row['tps_id']][] = $row['passNo'];
                        }
                        $total_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                        if ($row['used'] == "0" && $row['is_refunded'] == "0" && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1") && ($row['bleep_pass_no'] == "" || ($row['bleep_pass_no'] != '' && $order_details['add_to_pass'] == "1"))) {
                            if ($row['from_time'] != "" && $row['from_time'] !== "0" && $row['to_time'] != "" && $row['to_time'] !== "0") {
                                $cancelled_tickets_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']] = array(
                                    'capacity_count' => $cancelled_tickets_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['capacity_count'] + 1,
                                    'group_count' => $cancelled_tickets_array[$row['ticket_id'] . '_' . $row['selected_date'] . '_' . $row['from_time'] . '_' . $row['to_time']]['group_count'] + $row['group_quantity'],
                                    'group_price' => $row['group_price'],
                                    'selected_date' => $row['selected_date'],
                                    'from_time' => $row['from_time'],
                                    'to_time' => $row['to_time'],
                                    'ticket_id' => $row['ticket_id']
                                );
                            }
                            $ticket_booking_ids[] = $row['ticket_booking_id'];
                            $prepaid_ticket_ids[] = $row['prepaid_ticket_id'];
                        }
                        $extra_discount = unserialize($row['extra_discount']);
                        if ($row['is_refunded'] == "2") {
                            $refund_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                        }
                        if (($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1") && $row['ticket_id'] == $ticket_id) {
                            $cashier_id = $row['cashier_id'];
                            $ticket_types_firebase_array[$row['tps_id']] = array(
                                'tps_id' => (int) $row['tps_id'],
                                'quantity' => (int) ($ticket_types_firebase_array[$row['tps_id']]['quantity'] + 1),
                                'refund_quantity' => ($row['is_refunded'] == "2") ? (int) ($ticket_types_firebase_array[$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['tps_id']]['refund_quantity'],
                                'type' => $row['ticket_type'],
                                'passes' => array_values($un_refunded_passes[$row['tps_id']]),
                                'tax' => (float) $row['tax'],
                                'cashier_discount' => (float) ($ticket_types_firebase_array[$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                                'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                                'age_group' => $row['age_group'],
                                'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                                'net_price' => (float) ($row['net_price'] + $extra_discount['net_discount_amount']),
                                'per_age_group_extra_options' => array(),
                                'refunded_passes' => array_values($refund_passes[$row['tps_id']]),
                                'refunded_by' => array(),
                                'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                            );
                            $passes['"' . $row['passNo'] . '"'] = $row['passNo'];
                        }
                        $cashier_ids[$row['cashier_id']] = $row['cashier_id'];
                        $cashier_ids[$refunded_by] = $refunded_by;
                    } else if ((!empty($cluster_ids)) && !in_array($row['clustering_id'], $cluster_ids) && ($row['is_addon_ticket'] == "0" || $row['is_addon_ticket'] == "1")) {
                        //Prepare data for cluster tickets which are already cancelled or not to be cancelled
                        if ($row['is_combi_ticket'] == 1) {
                            $combi_total_passes[$row['tps_id']][] = $row['passNo'];
                        }
                        $total_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                        if ($row['is_refunded'] != "1") {
                            if ($row['is_refunded'] == "2") {
                                $refund_array[$row['tps_id']][$row['refunded_by']] = array(
                                    'refunded_by' => (int) $row['refunded_by'],
                                    'count' => (int) $refund_array[$row['tps_id']][$row['refunded_by']]['count'] + 1
                                );
                            }
                            if ($row['is_refunded'] == "2") {
                                $refund_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                            }
                            if ($row['is_refunded'] == '0') {
                                $un_refunded_passes[$row['tps_id']][$row['passNo']] = $row['passNo'];
                            }
                            $extra_discount = unserialize($row['extra_discount']);
                            if ($row['ticket_id'] == $ticket_id) {
                                $ticket_types_firebase_array[$row['tps_id']] = array(
                                    'tps_id' => (int) $row['tps_id'],
                                    'quantity' => (int) ($ticket_types_firebase_array[$row['tps_id']]['quantity'] + 1),
                                    'refund_quantity' => ($row['is_refunded'] == "2") ? (int) ($ticket_types_firebase_array[$row['tps_id']]['refund_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['tps_id']]['refund_quantity'],
                                    'type' => $row['ticket_type'],
                                    'passes' => array_values($un_refunded_passes[$row['tps_id']]),
                                    'tax' => (float) $row['tax'],
                                    'cashier_discount' => (float) ($ticket_types_firebase_array[$row['tps_id']]['cashier_discount'] + $extra_discount['gross_discount_amount']),
                                    'cashier_discount_quantity' => ($extra_discount['gross_discount_amount'] > 0) ? (int) ($ticket_types_firebase_array[$row['tps_id']]['cashier_discount_quantity'] + 1) : (int) $ticket_types_firebase_array[$row['ticket_id']][$row['tps_id']]['cashier_discount_quantity'],
                                    'age_group' => $row['age_group'],
                                    'price' => (float) ($row['price'] + $row['combi_discount_gross_amount'] + $extra_discount['gross_discount_amount']),
                                    'net_price' => (float) ($row['net_price'] + $extra_discount['net_discount_amount']),
                                    'per_age_group_extra_options' => array(),
                                    'refunded_passes' => array_values($refund_passes[$row['tps_id']]),
                                    'refunded_by' => array(),
                                    'combi_discount_gross_amount' => (float) $row['combi_discount_gross_amount'],
                                );
                            }
                        }
                    }

                    if ($row['activation_method'] != '10' && $row['activation_method'] != '19' && $row['is_refunded'] == '0' && in_array($row['tps_id'], array_keys($cancel_tickets_array)) && $refund_type[$row['tps_id']]['cancel_quantity'] < $cancel_tickets_array[$row['tps_id']]['cancel_quantity']) {
                        $update_credit_limit = array();
                        $update_credit_limit['museum_id'] = $row['museum_id'];
                        $update_credit_limit['reseller_id'] = $row['reseller_id'];
                        $update_credit_limit['hotel_id'] = $row['hotel_id'];
                        $update_credit_limit['partner_id'] = $row['distributor_partner_id'];
                        $update_credit_limit['cashier_name'] = $row['distributor_partner_name'];
                        $update_credit_limit['hotel_name'] = $row['hotel_name'];
                        $update_credit_limit['visitor_group_no'] = $row['visitor_group_no'];
                        $update_credit_limit['merchant_admin_id'] = $row['merchant_admin_id'];
                        $update_credit_limit['channel_type'] = $row['channel_type'];
                        if (array_key_exists($row['museum_id'], $update_credit_limit_data)) {
                            $update_credit_limit_data[$row['museum_id']]['used_limit'] += $row['price'];
                        } else {
                            $update_credit_limit['used_limit'] = $row['price'];
                            $update_credit_limit_data[$row['museum_id']] = $update_credit_limit;
                        }
                        $refund_type[$row['tps_id']]['cancel_quantity'] = isset($refund_type[$row['tps_id']]['cancel_quantity']) ? $refund_type[$row['tps_id']]['cancel_quantity'] + 1 : 1;
                        $logs['$refund_type_' . $i] = $refund_type;
                    }

                    if ($cant_cancel == 0 && $row['scanned_at'] != '' && $row['scanned_at'] != NULL && $row['scanned_at'] >= strtotime("-2 minutes")) {
                        $cant_cancel = 1;
                    }
                }
                if ($cant_cancel == 0) {
                    $internal_log['ticket_types_firebase_array_' . date('H:i:s')] = $ticket_types_firebase_array;
                    $internal_log['cancelled_tickets_array_' . date('H:i:s')] = $cancelled_tickets_array;
                    //get firebase_user_ids to send in response to update cancelled amount in shift report array on firebase
                    $user_details = $this->find('users', array('select' => 'id, firebase_user_id, user_role, fname, lname', 'where' => 'id IN (' . implode(',', $cashier_ids) . ') '));
                    $refunded_by_user = '';
                    $order_cancellation_date = date('Y-m-d H:i:s');
                    foreach ($user_details as $user) {
                        $user_firebase_ids[$user['id']]['firebase_user_id'] = $user['firebase_user_id'];
                        $user_firebase_ids[$user['id']]['user_role'] = $user['user_role'];
                        if ($user['id'] == $refunded_by) {
                            $refunded_by_user = $user['fname'] . ' ' . $user['lname'];
                        }
                    }
                    $visitor_id = array();
                    //Activate main ticket if extended tickis cancelled
                    if ($upsell_order == "1") {
                        $visitor_ids = $this->find('prepaid_tickets', array('select' => 'visitor_tickets_id', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and activated = "0" and passNo in (' . implode(',', array_keys($passes)) . ')'));
                        $logs['pt_passno_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
                        $this->query('update prepaid_tickets set activated = "1" where visitor_group_no = "' . $visitor_group_no . '" and activated = "0" and passNo in (' . implode(',', array_keys($passes)) . ')', 0);
                        
                        
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("activated" => '1', "updated_at" => gmdate("Y-m-d H:i:s"));
                        $where = 'visitor_group_no = "'.$visitor_group_no.'" and activated = "0" and passNo in ('.implode(',', array_keys($passes)).')';
                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where);
                    

                        foreach ($visitor_ids as $vt_id) {
                            $visitor_id[] = $vt_id['visitor_tickets_id'];
                        }
                    }
                    foreach ($cancel_tickets_array as $array) {
                        $refund_array[$array['tps_id']][$refunded_by] = array(
                            'refunded_by' => (int) $refunded_by,
                            'count' => (int) $refund_array[$array['tps_id']][$refunded_by]['count'] + $array['cancel_quantity']
                        );
                        $ticket_types_firebase_array[$array['tps_id']]['refund_quantity'] = $ticket_types_firebase_array[$array['tps_id']]['refund_quantity'] + $array['cancel_quantity'];
                        $cancel_tickets += $array['cancel_quantity'];
                        $cancel_capacity[$array['tps_id']]['count'] += $array['cancel_quantity'];
                        $cancel_capacity[$array['tps_id']]['capacity'] += $array['capacity'];
                        $cancelled_array[$array['tps_id']] = array(
                            'cancel_quantity' => $array['cancel_quantity'],
                            'total_quantity' => $db_tickets[$array['tps_id']]['total_quantity']
                        );
                    }

                    if ($total_tickets_to_cancel > 0) {
                        if ($prepaid_data[0]['booking_status'] == '1') {
                            $order_status = '2';
                        } else {
                            $order_status = '1';
                        }
                        $activation_method = $prepaid_data[0]['activation_method'];
                        $is_combi_pass = $prepaid_data[0]['is_combi_ticket'];
                        $passNo = $prepaid_data[0]['passNo'];
                        /* In case of payment by card, need payment references from HTO */
                        $user_data = $this->find('hotel_ticket_overview', array('select' => 'channel_type, merchantAccountCode, pspReference, merchantReference, hotel_name, activation_method, paymentMethod', 'where' => 'hotel_id = "' . $hotel_id . '" and visitor_group_no = "' . $visitor_group_no . '"', 'limit' => 1));
                        $merchant_account = $user_data[0]['merchantAccountCode'];
                        $psp_reference = $user_data[0]['pspReference'];
                        if ($visitor_group_no != '' && $ticket_id != '') {
                            $logs['cancelled_tickets_array'] = $cancelled_tickets_array;
                            /*  Fetch unused and non-cancelled tickets count and update sold count in ticket_capacity_v1 */
                            if (!empty($cancelled_tickets_array)) {
                                $capacity_cancel = 0;
                                foreach ($cancel_capacity as $tps_data) {
                                    $capacity_cancel += $tps_data['count'] * $tps_data['capacity'];
                                }
                                foreach ($cancelled_tickets_array as $cancelled_tickets_data) {
                                    $change_ticket_id = $cancelled_tickets_data['ticket_id'];
                                    $change_date = $cancelled_tickets_data['selected_date'];
                                    $change_from_time = $cancelled_tickets_data['from_time'];
                                    $change_to_time = $cancelled_tickets_data['to_time'];
                                }
                                $logs['cancel__checkss'] = array($capacity_cancel, $change_ticket_id, $change_date, $change_from_time, $change_to_time);
                                if ($capacity_cancel > 0 && $change_date != '' && $change_date != '0') {
                                    $this->update_capacity_on_cancel($capacity_cancel, $change_ticket_id, $change_date, $change_from_time, $change_to_time);
                                }
                            }
                            /* update is_refunded field of prepaid tickets, and prepare array to insert new row in PT */
                            $insert_prepaid_ticket_data = array();
                            $total_amount = 0;
                            $passes = array();
                            $upsell_condition_query = '';
                            if ($upsell_performed == 1) {
                                $upsell_condition_query = ' and action_performed like "%UPSELL%" ';
                            } else if ($upsell_performed == 2) {
                                $upsell_condition_query = ' and action_performed like "%UPSELL_INSERT%" ';
                            }
                            $logs['clusterrr'] = $cluster_ids;
                            if (!empty($cluster_ids)) {
                                
                                $pt_db1_cols = 'is_refunded = "2", activated = "0", action_performed = concat(action_performed, ", ' . $action_performed . '"), refunded_by = "' . $refunded_by . '", refunded_by = "' . $refunded_by . '"';
                                
                                $query = 'Update prepaid_tickets set '.$pt_db1_cols.' where visitor_group_no = "' . $visitor_group_no . '" and (is_refunded = "2" || is_refunded = "0") and clustering_id IN (' . implode(',', array_keys($cluster_ids)) . ')' . $upsell_condition_query;
                                
                                $this->query($query);
                                $logs['prepaid_db1_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                                
                                /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                                $updateCols = array("is_refunded"                   => '2', 
                                                    "activated"                     => '0',
                                                    "CONCAT_VALUE"                  => array("action_performed" => ', '.$action_performed), 
                                                    "refunded_by"                   => $refunded_by,             
                                                    "order_cancellation_date"       => $order_cancellation_date, 
                                                    "order_updated_cashier_id"      => $refunded_by, 
                                                    "order_updated_cashier_name"    => $refunded_by_user, 
                                                    "updated_at"                    => gmdate("Y-m-d H:i:s"));
                                $where = 'visitor_group_no = "' . $visitor_group_no . '" and is_cancelled = "0" and clustering_id IN (' . implode(',', array_keys($cluster_ids)) . ')' . $upsell_condition_query;
                                $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where, "unset_updated_at" => 0);
                                
                                
                                $i = 1;
                                foreach ($update_prepaid_data as $row) {
                                    foreach ($row as $insert_prepaid_ticket) {
                                        $tps_id = $insert_prepaid_ticket['tps_id'];
                                        $passes[] = '"' . $insert_prepaid_ticket['passNo'] . '"';
                                        $refund_passes[$tps_id][$insert_prepaid_ticket['passNo']] = $insert_prepaid_ticket['passNo'];
                                        $ticket_type = ucfirst(strtolower($insert_prepaid_ticket['ticket_type']));
                                        $cancel_passes[$tps_id][$insert_prepaid_ticket['passNo']] = $insert_prepaid_ticket['passNo'];
                                        if ($insert_prepaid_ticket['is_addon_ticket'] != "2") {
                                            $total_amount += $insert_prepaid_ticket['price'];
                                            //return array for cancelled tickets data
                                            $return_cancelled_ticket_details[$tps_id] = array(
                                                'tps_id' => (int) $tps_id,
                                                'quantity' => (int) $cancelled_array[$tps_id]['cancel_quantity'],
                                                'ticket_type' => (int) (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? $this->TICKET_TYPES[$ticket_type] : 10,
                                                'ticket_type_label' => (string) $ticket_type,
                                                'pass_no' => array_values($cancel_passes[$tps_id]),
                                                'price' => (float) $insert_prepaid_ticket['price'],
                                            );
                                            $return_cancelled_ticket_details = $this->common_model->sort_ticket_types($return_cancelled_ticket_details);
                                            //make array to update shift report
                                            $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))] = array(
                                                'shift_id' => (int) $insert_prepaid_ticket['shift_id'],
                                                'cashier_id' => (int) $insert_prepaid_ticket['cashier_id'],
                                                'firebase_user_id' => $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['firebase_user_id'],
                                                'user_role' => (int) $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['user_role'],
                                                'pos_point_id' => (int) $insert_prepaid_ticket['pos_point_id'],
                                                'booking_date' => date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time'])),
                                                'amount' => $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))]['amount'] + $insert_prepaid_ticket['price'] - $extra_discount['gross_discount_amount']
                                            );
                                            if ($refunded_by != $insert_prepaid_ticket['cashier_id']) {
                                                $other_cancellation = array(
                                                    'amount' => (!empty($extra_discount)) ? (float) $other_cancellation['amount'] + ($insert_prepaid_ticket['price'] - $extra_discount['gross_discount_amount']) : (float) $other_cancellation['amount'] + $insert_prepaid_ticket['price'],
                                                    'cashier_id' => (int) $insert_prepaid_ticket['cashier_id']
                                                );
                                            }
                                        }
                                        $visitor_ticket_ids[] = $insert_prepaid_ticket['visitor_tickets_id'];
                                        $insert_prepaid_ticket['is_refunded'] = '1';
                                        $insert_prepaid_ticket['activated'] = '0';
                                        $insert_prepaid_ticket['order_cancellation_date'] = $order_cancellation_date;
                                        $insert_prepaid_ticket['order_updated_cashier_id'] = $refunded_by;
                                        $insert_prepaid_ticket['order_updated_cashier_name'] = $refunded_by_user;
                                        $insert_prepaid_ticket['order_status'] = $order_status;
                                        $insert_prepaid_ticket['refunded_by'] = $refunded_by;
                                        $insert_prepaid_ticket['action_performed'] = '0, ' . $action_performed;
                                        $insert_prepaid_ticket['bleep_pass_no'] = isset($insert_prepaid_ticket['prev_bleep_pass_no']) ? (string) $insert_prepaid_ticket['prev_bleep_pass_no'] : $insert_prepaid_ticket['bleep_pass_no'];
                                        $insert_prepaid_ticket['used'] = ($insert_prepaid_ticket['prev_used'] == 1) ? (string) $insert_prepaid_ticket['prev_used'] : (string) $insert_prepaid_ticket['used'];
                                        unset($insert_prepaid_ticket['prev_bleep_pass_no']);
                                        unset($insert_prepaid_ticket['prev_used']);
                                        unset($insert_prepaid_ticket['last_modified_at']);
                                        $insert_prepaid_ticket['updated_at'] = gmdate("Y-m-d H:i:s");                               
                                        $insert_prepaid_ticket['order_confirm_date'] = $order_confirm_date;
                                        $insert_prepaid_ticket['is_cancelled'] = '1';
                                        $insert_prepaid_ticket['created_at'] = strtotime(date('Y-m-d H:i:s'));
                                        $pt_id = $insert_prepaid_ticket['prepaid_ticket_id'];
                                        unset($insert_prepaid_ticket['prepaid_ticket_id']);
                                        $insert_prepaid_ticket_data[$pt_id] = $insert_prepaid_ticket;
                                    }
                                }
                                $cancel_tickets_from_firebase = array(
                                    'vt_group_no' => $visitor_group_no,
                                    'is_refunded' => "0",
                                    'cancel_tickets' => $cancel_tickets_array,
                                    'visitor_ids' => $visitor_id,
                                    'visitor_tickets_id' => $visitor_ticket_ids,
                                    'action_performed' => $action_performed,
                                    'order_confirm_date' => $order_confirm_date,
                                    'refunded_by' => $refunded_by,
                                    'refunded_by_user' => $refunded_by_user,
                                    'order_cancellation_date' => $order_cancellation_date, 
                                    'hotel_id' => $hotel_id
                                );
                            } else {
                                //for tickets other than cluster tickets
                                foreach ($cancelled_array as $key => $cancelled) {
                                    $pt_db1_cols = 'is_refunded = "2", activated = "0", action_performed = concat(action_performed, ", ' . $action_performed . '"), refunded_by = "' . $refunded_by . '"';
                                    $where_cond = 'where visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '" and  is_refunded = "0" and created_date_time = "' . $created_date_time . '" and tps_id = "' . $key . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ') ' . $upsell_condition . '';
                                    $query = 'Update prepaid_tickets set '.$pt_db1_cols.' '.$where_cond.' limit ' . $cancelled['cancel_quantity'];
                                    $this->query($query, 0);
                                    $logs['pt_db1_refunded_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                                    
                                    
                                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                                        $updateCols = array("is_refunded"                   => '2', 
                                                            "activated"                     => '0',
                                                            "CONCAT_VALUE"                  => array("action_performed" => ', '.$action_performed), 
                                                            "refunded_by"                   => $refunded_by,                                                            
                                                            "order_cancellation_date"       => $order_cancellation_date, 
                                                            "order_updated_cashier_id"      => $refunded_by, 
                                                            "order_updated_cashier_name"    => $refunded_by_user, 
                                                            "updated_at"                    => gmdate("Y-m-d H:i:s"));
                                        $where = 'visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '" and  is_refunded = "0" and created_date_time = "' . $created_date_time . '" and tps_id = "' . $key . '" and prepaid_ticket_id IN (' . implode(',', $prepaid_ticket_ids) . ') ' . $upsell_condition . ' and is_cancelled = "0"';
                                        $insertion_db2[] = array("table" => 'prepaid_tickets', "columns" => $updateCols, "where" => $where, "limit" => $cancelled['cancel_quantity'], "unset_updated_at" => 0);
                                    
                                    
                                    $logs['pt_db1_refunded_query_' . date('H:i:s')] = $query;

                                    /* Add new row in PT for isrefunded 1 and with order status */
                                    for ($i = 1; $i <= $cancelled['cancel_quantity']; $i++) {
                                        $insert_prepaid_ticket = array();
                                        $insert_prepaid_ticket = $update_prepaid_data[$visitor_group_no . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $key . '_0_0'][$i - 1];
                                        $passes[] = '"' . $insert_prepaid_ticket['passNo'] . '"';
                                        $refund_passes[$key][$insert_prepaid_ticket['passNo']] = $insert_prepaid_ticket['passNo'];
                                        $total_amount += $insert_prepaid_ticket['price'];
                                        $ticket_type = ucfirst(strtolower($insert_prepaid_ticket['ticket_type']));
                                        $cancel_passes[$key][$insert_prepaid_ticket['passNo']] = $insert_prepaid_ticket['passNo'];
                                        $return_cancelled_ticket_details[$key] = array(
                                            'tps_id' => (int) $key,
                                            'quantity' => (int) $cancelled['cancel_quantity'],
                                            'ticket_type' => (!empty($this->TICKET_TYPES[$ticket_type]) && ($this->TICKET_TYPES[$ticket_type] > 0)) ? (int) $this->TICKET_TYPES[$ticket_type] : (int) 10,
                                            'ticket_type_label' => (string) $ticket_type,
                                            'pass_no' => array_values($cancel_passes[$key]),
                                            'price' => (float) $insert_prepaid_ticket['price'],
                                        );
                                        $return_cancelled_ticket_details = $this->common_model->sort_ticket_types($return_cancelled_ticket_details);
                                        $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))] = array(
                                            'shift_id' => (int) $insert_prepaid_ticket['shift_id'],
                                            'cashier_id' => (int) $insert_prepaid_ticket['cashier_id'],
                                            'firebase_user_id' => $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['firebase_user_id'],
                                            'user_role' => (int) $user_firebase_ids[$insert_prepaid_ticket['cashier_id']]['user_role'],
                                            'pos_point_id' => (int) $insert_prepaid_ticket['pos_point_id'],
                                            'booking_date' => date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time'])),
                                            'amount' => $shift_report_array[$insert_prepaid_ticket['shift_id'] . '_' . $insert_prepaid_ticket['pos_point_id']][date("Y-m-d", strtotime($insert_prepaid_ticket['created_date_time']))]['amount'] + $insert_prepaid_ticket['price'] - $extra_discount['gross_discount_amount']
                                        );
                                        if ($refunded_by != $insert_prepaid_ticket['cashier_id']) {
                                            $other_cancellation = array(
                                                'amount' => (!empty($extra_discount)) ? (float) $other_cancellation['amount'] + ($insert_prepaid_ticket['price'] - $extra_discount['gross_discount_amount']) : (float) $other_cancellation['amount'] + $insert_prepaid_ticket['price'],
                                                'cashier_id' => (int) $insert_prepaid_ticket['cashier_id']
                                            );
                                        }
                                        $visitor_ticket_ids[] = $insert_prepaid_ticket['visitor_tickets_id'];
                                        $insert_prepaid_ticket['is_refunded'] = '1';
                                        $insert_prepaid_ticket['activated'] = '0';
                                        $insert_prepaid_ticket['order_cancellation_date'] = $order_cancellation_date;
                                        $insert_prepaid_ticket['order_updated_cashier_id'] = $refunded_by;
                                        $insert_prepaid_ticket['order_updated_cashier_name'] = $refunded_by_user;
                                        $insert_prepaid_ticket['order_status'] = $order_status;
                                        $insert_prepaid_ticket['refunded_by'] = $refunded_by;
                                        $insert_prepaid_ticket['action_performed'] = '0, ' . $action_performed;
                                        $insert_prepaid_ticket['updated_at'] = gmdate("Y-m-d H:i:s");                                       
                                        $insert_prepaid_ticket['order_confirm_date'] = $order_confirm_date;
                                        $insert_prepaid_ticket['is_cancelled'] = '1';
                                        $insert_prepaid_ticket['bleep_pass_no'] = isset($insert_prepaid_ticket['prev_bleep_pass_no']) ? (string) $insert_prepaid_ticket['prev_bleep_pass_no'] : $insert_prepaid_ticket['bleep_pass_no'];
                                        $insert_prepaid_ticket['used'] = ($insert_prepaid_ticket['prev_used'] == 1) ? (string) $insert_prepaid_ticket['prev_used'] : (string) $insert_prepaid_ticket['used'];
                                        unset($insert_prepaid_ticket['prev_used']);
                                        unset($insert_prepaid_ticket['prev_bleep_pass_no']);
                                        unset($insert_prepaid_ticket['last_modified_at']);
                                        $insert_prepaid_ticket['created_at'] = strtotime(date('Y-m-d H:i:s'));
                                        $insert_prepaid_ticket['start_amount'] = $start_amount;
                                        $pt_id = $insert_prepaid_ticket['prepaid_ticket_id'];
                                        unset($insert_prepaid_ticket['prepaid_ticket_id']);
                                        $insert_prepaid_ticket_data[$pt_id] = $insert_prepaid_ticket;
                                    }
                                }
                                //array  to cancel tickets in VT and send in queue
                                if ($data['from_time'] != '' && $data['from_time'] !== '0' && $data['to_time'] != '' && $data['to_time'] != 0) { //reservtn ticket
                                    $cancel_tickets_from_firebase = array(
                                        'vt_group_no' => $visitor_group_no,
                                        'ticketId' => $ticket_id,
                                        'selected_date' => $selected_date,
                                        'from_time' => $from_time,
                                        'to_time' => $to_time,
                                        'is_refunded' => "0",
                                        'created_date' => $created_date_time,
                                        'cancel_tickets' => $cancel_tickets_array,
                                        'visitor_ids' => $visitor_id,
                                        'visitor_tickets_id' => $visitor_ticket_ids,
                                        'action_performed' => $action_performed,
                                        'order_confirm_date' => $order_confirm_date,
                                        'refunded_by' => $refunded_by,
                                        'refunded_by_user' => $refunded_by_user,
                                        'order_cancellation_date' => $order_cancellation_date, 
                                        'hotel_id' => $hotel_id
                                    );
                                } else {
                                    $cancel_tickets_from_firebase = array(
                                        'vt_group_no' => $visitor_group_no,
                                        'ticketId' => $ticket_id,
                                        'is_refunded' => "0",
                                        'created_date' => $created_date_time,
                                        'cancel_tickets' => $cancel_tickets_array,
                                        'visitor_ids' => $visitor_id,
                                        'visitor_tickets_id' => $visitor_ticket_ids,
                                        'action_performed' => $action_performed,
                                        'order_confirm_date' => $order_confirm_date,
                                        'refunded_by' => $refunded_by,
                                        'refunded_by_user' => $refunded_by_user,
                                        'order_cancellation_date' => $order_cancellation_date, 
                                        'hotel_id' => $hotel_id
                                    );
                                }
                            }
                            /* update prepaid_extra_options */
                            if (!empty($cancel_extra_options_array) || 1) {
                                if (!($data['from_time'] != '' && $data['from_time'] !== '0' && $data['to_time'] != '' && $data['to_time'] != 0)) { //booking ticket
                                    $ex_selected_date = '';
                                    $ex_from_time = '';
                                    $ex_to_time = '';
                                }
                                $query = 'select refunded_by, refund_quantity, quantity, ticket_id, ticket_price_schedule_id, extra_option_id, description, main_description, price, created, prepaid_extra_options_id, variation_type from prepaid_extra_options where visitor_group_no = "' . $visitor_group_no . '" and ticket_id = "' . $ticket_id . '" and selected_date = "' . $ex_selected_date . '" and from_time = "' . $ex_from_time . '" and to_time = "' . $ex_to_time . '"';
                                $extra_options_data = $this->query($query, 1);
                                $logs['prepaid_extra_options_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                                $refunded_extra_options = array();
                                foreach ($extra_options_data as $data) {
                                    if($data['variation_type'] == 0) {
                                        if ($data['refunded_by'] != NULL && $data['refunded_by'] != '') {
                                            $refunded_by_details = json_decode($data['refunded_by']);
                                            $logs['all_extra_options_ref_details'] = $refunded_by_details;
                                            foreach ($refunded_by_details as $array) {
                                                $refunded_extra_options[$array->refunded_by] = array(
                                                    'refunded_by' => (int) $array->refunded_by,
                                                    'count' => (int) $array->count
                                                );
                                            }
                                        }
                                        if ($data['refund_quantity'] == NULL || $data['refund_quantity'] == '') {
                                            $data['refund_quantity'] = '0';
                                        }
                                        if (!empty($cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']])) {
                                            $refunded_extra_options[$refunded_by] = array(
                                                'refunded_by' => (int) $refunded_by,
                                                'count' => (int) ($refunded_extra_options[$refunded_by]['count'] + $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']])
                                            );
                                        } else {
                                            $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']] = 0;
                                        }
                                        sort($refunded_extra_options);
                                        if ($data['ticket_price_schedule_id'] == "0") {
                                            $per_ticket_extra_options[$data['extra_option_id'] . '_' . $data['description']] = array(
                                                'main_description' => $data['main_description'],
                                                'description' => $data['description'],
                                                'quantity' => (int) $data['quantity'],
                                                'refunded_by' => $refunded_extra_options,
                                                'refund_quantity' => (int) ($data['refund_quantity'] + $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']]),
                                                'price' => (float) $data['price'],
                                            );
                                        } else {
                                            $per_age_group_extra_options[$data['ticket_price_schedule_id']][$data['extra_option_id'] . '_' . $data['description']] = array(
                                                'main_description' => $data['main_description'],
                                                'description' => $data['description'],
                                                'quantity' => (int) $data['quantity'],
                                                'refunded_by' => $refunded_extra_options,
                                                'refund_quantity' => (int) ($data['refund_quantity'] + $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']]),
                                                'price' => (float) $data['price'],
                                            );
                                        }
                                        if (!empty($other_cancellation)) {
                                            $other_cancellation['amount'] = (float) ($other_cancellation['amount'] + ($data['price'] * ($cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']])));
                                        }
                                        if ($cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']] > 0) {
                                            $extra_options_amount[date("Y-m-d", strtotime($data['created']))]['amount'] = $extra_options_amount[date("Y-m-d", strtotime($data['created']))]['amount'] + ($data['price'] * $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']]);
                                            $total_amount += ($cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']] * $data['price']);
                                            if ($cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']] == $data['quantity']) {
                                                $update = "is_cancelled = '1', refund_quantity = '" . $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']] . "', refunded_by = '" . json_encode($refunded_extra_options) . "'";
                                            } else {
                                                $update = "refunded_by = '" . json_encode($refunded_extra_options) . "', refund_quantity = refund_quantity + " . $cancel_extra_options_array[$data['extra_option_id'] . '_' . $data['description']];
                                            }
                                            $query = "Update prepaid_extra_options set " . $update . " where prepaid_extra_options_id = '" . $data['prepaid_extra_options_id'] . "'";
                                            $this->query($query, 0);
                                            $sns_messages[] = $this->db->last_query();
                                        }
                                        $refunded_extra_options = array();
                                    }
                                }
                            }
                            $internal_log['refunded_extra_options'] = $refunded_extra_options;
                            //Prepare final shift report array
                            sort($shift_report_array);
                            foreach ($shift_report_array[0] as $key => $report) {
                                $payments[] = array(
                                    "payment_type" => (int) 2,
                                    "amount" => (float) $report['amount'] + $extra_options_amount[$key]['amount']
                                );
                                if($report['cashier_id'] == $refunded_by) {
                                    $final_array[] = array(
                                        'shift_id' => (int) $report['shift_id'],
                                        'pos_point_id' => (int) $report['pos_point_id'],
                                        'cashier_id' => (int) $report['cashier_id'],
                                        'firebase_user_id' => $report['firebase_user_id'],
                                        'user_role' => (int) $report['user_role'],
                                        'booking_date' => $report['booking_date'],
                                        'amount' => (float) $report['amount'] + $extra_options_amount[$key]['amount'],
                                        'payments' => $activation_method == "9" ? $payments : array()
                                    );
                                }                                
                                if ($report['cashier_id'] != $refunded_by) {
                                    $final_array[] = array(
                                        'shift_id' => (int) $report['shift_id'],
                                        'pos_point_id' => (int) $report['pos_point_id'],
                                        'cashier_id' => (int) $refunded_by,
                                        'firebase_user_id' => $user_firebase_ids[$refunded_by]['firebase_user_id'],
                                        'user_role' => (int) $user_firebase_ids[$refunded_by]['user_role'],
                                        'booking_date' => $report['booking_date'],
                                        'amount' => (float) $report['amount'] + $extra_options_amount[$key]['amount'],
                                        'payments' => $activation_method == "9" ? $payments : array()
                                    );
                                }
                            }

                            /*  Update cancel details at distributor level in Firebase if venue_app active for the user */
                            if (SYNC_WITH_FIREBASE == 1) {
                                $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $prepaid_data[0]['hotel_id'] . '"'));
                                if ($hotel_data[0]['is_venue_app_active'] == 1) {
                                    try {
                                        $headers = $this->all_headers(array(
                                            'hotel_id' => $prepaid_data[0]['hotel_id'],
                                            'ticket_id' => $ticket_id,
                                            'museum_id' => $prepaid_data[0]['museum_id'],
                                            'channel_type' => $prepaid_data[0]['channel_type'],
                                            'action' => 'sync_bookings_list_on_partial_cancel_order',
                                            'user_id' => $prepaid_data[0]['cashier_id']
                                        ));
                                        foreach ($ticket_types_firebase_array as $tps_id => $array) {
                                            if ($is_combi == 1) {
                                                $quantity = $array['quantity'];
                                                $refund_quantity = $array['refund_quantity'];
                                                if ($quantity == $refund_quantity) {
                                                    $ticket_types_firebase_array[$tps_id]['passes'] = array();
                                                    $ticket_types_firebase_array[$tps_id]['refunded_passes'] = $combi_total_passes[$tps_id];
                                                } else {
                                                    $left = $quantity - $refund_quantity;
                                                    $total_combi_passes = array_chunk($combi_total_passes[$tps_id], $left);
                                                    $ticket_types_firebase_array[$tps_id]['passes'] = $total_combi_passes[0];
                                                    $total_refunded_passes = array_chunk($combi_total_passes[$tps_id], $refund_quantity);
                                                    $ticket_types_firebase_array[$tps_id]['refunded_passes'] = $total_refunded_passes[0];
                                                }
                                            } else {
                                                if (isset($refund_array[$tps_id]) && !empty($refund_array[$tps_id])) {
                                                    $ticket_types_firebase_array[$tps_id]['refunded_passes'] = array_values($refund_passes[$tps_id]);
                                                    $ticket_types_firebase_array[$tps_id]['passes'] = array_values(array_diff($total_passes[$tps_id], $refund_passes[$tps_id]));
                                                }
                                            }
                                            sort($refund_array[$tps_id]);
                                            sort($per_age_group_extra_options[$tps_id]);
                                            $ticket_types_firebase_array[$tps_id]['refunded_by'] = $refund_array[$tps_id];
                                            $ticket_types_firebase_array[$tps_id]['per_age_group_extra_options'] = $per_age_group_extra_options[$tps_id];
                                        }
                                        sort($per_ticket_extra_options);
                                        $update_values = array(
                                            'status' => (int) 3,
                                            'ticket_types' => $ticket_types_firebase_array,
                                            'per_ticket_extra_options' => $per_ticket_extra_options
                                        );
                                        $MPOS_LOGS['DB'][] = 'FIREBASE';
                                        $update_key = base64_encode($visitor_group_no . '_' . $ticket_id . '_' . $selected_date . '_' . $from_time . '_' . $to_time . '_' . $created_date_time);
                                        $this->curl->requestASYNC('FIREBASE', '/update_values_in_array', array(
                                            'type' => 'POST',
                                            'additional_headers' => $headers,
                                            'body' => array("node" => 'distributor/bookings_list/' . $prepaid_data[0]['hotel_id'] . '/' . $prepaid_data[0]['cashier_id'] . '/' . date("Y-m-d", strtotime($prepaid_data[0]['created_date_time'])) . '/' . $update_key, 'details' => $update_values, 'update_key' => 'cancelled_tickets', 'update_value' => $cancel_tickets)
                                        ));
                                        
                                        // sync status in order list                                                 
                                        $order_date = date("Y-m-d", strtotime($prepaid_data[0]['created_date_time']));
                                        $current_date = $this->current_date;
                                        if (strtotime($order_date) >= strtotime($current_date)) {
                                            $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                                                'type' => 'POST',
                                                'additional_headers' => $headers,
                                                'body' => array(
                                                    "node" => 'distributor/orders_list/' . $prepaid_data[0]['hotel_id'] . '/' . $prepaid_data[0]['cashier_id'] . '/' . date("Y-m-d", strtotime($prepaid_data[0]['created_date_time'])) . '/' . $visitor_group_no, 
                                                    'details' => array(
                                                        'status' => (int) 3
                                                    )
                                                )
                                            ));
                                        }
                                        if (!empty($other_cancellation)) {
                                            $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                                                'type' => 'POST',
                                                'additional_headers' => $headers,
                                                'body' => array("node" => 'distributor/others_cancellation/' . $prepaid_data[0]['hotel_id'] . '/' . $refunded_by . '/' . gmdate("Y-m-d"), 'details' => $other_cancellation)
                                            ));
                                        }
                                    } catch (\Exception $e) {
                                        $logs['exception'] = $e->getMessage();
                                    }
                                }
                            }
                            $logs['track_time'] = date('H:i:s');
                            //Refund adyen amount in case of card payment
                            if ($activation_method == "1" && $total_amount > 0) {
                                $this->refund($merchant_account, ($total_amount * 100), $psp_reference);
                            }
                            try {
                                if (!empty($sns_messages) || !empty($insertion_db2)) {
                                    $request_array['db2_insertion'] = $insertion_db2;
                                    $request_array['hotel_id'] = $hotel_id;
                                    $request_array['api_visitor_group_no'] = $visitor_group_no;
                                    $request_array['refund_prepaid_orders'] = $insert_prepaid_ticket_data;
                                    $request_array['update_visitor_tickets_for_partial_refund_order'] = $cancel_tickets_from_firebase;
                                    $request_array['update_credit_limit_data'] = $update_credit_limit_data;
                                    $request_array['write_in_mpos_logs'] = 1;
                                    $request_array['action'] = "partial_cancel_order";
                                    $request_array['visitor_group_no'] = $visitor_group_no;
                                    $request_array['order_payment_details'] = array(
                                        "visitor_group_no" => $visitor_group_no,
                                        "amount" => ($total_amount > 0) ? $total_amount : 0, 
                                        "total" => ($total_amount > 0) ? $total_amount : 0,
                                        "status" => 4, //partial refund
                                        "type" => 2, //refund
                                        "refund_type" => 1, 
                                        "settled_on" => date("Y-m-d H:i:s"),
                                        "cashier_id" => $refunded_by,
                                        "cashier_name" => $refunded_by_user,
                                        "updated_at"=> date("Y-m-d H:i:s"),
                                        "refunded_entry" => 1
                                    );
                                    if(!empty($ticket_booking_ids)) {
                                        $request_array['order_payment_details']['ticket_booking_id'] = implode(",", array_unique($ticket_booking_ids));
                                    }
                                    $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $request_array;
                                    $request_string = json_encode($request_array);
                                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                    if (LOCAL_ENVIRONMENT == 'Local') {
                                        local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
                                    } else {
                                        $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
                                    }
                                }
                            } catch (\Exception $e) {
                                $logs['exception'] = $e->getMessage();
                            }

                            $response['status'] = (int) 1;
                            $response['shift_id'] = ($prepaid_data[0]['shift_id'] != '' && $prepaid_data[0]['shift_id'] != NULL) ? (int) $prepaid_data[0]['shift_id'] : (int) 0;
                            $response['pos_point_id'] = ($prepaid_data[0]['pos_point_id'] != '' && $prepaid_data[0]['pos_point_id'] != NULL) ? (int) $prepaid_data[0]['pos_point_id'] : (int) 0;
                            $response['payment_type'] = ($activation_method == "3") ? (int) 2 : (int) $activation_method;
                            $response['total_amount'] = (float) $total_amount;
                            $response['cancelled_tickets'] = (!empty($return_cancelled_ticket_details)) ? array_values($return_cancelled_ticket_details) : array();
                            $response['shift_report_array'] = !empty($final_array) ? $final_array : array();
                            $response['message'] = 'Booking cancelled successfully';
                        }
                        $internal_logs['partially_cancel_order'] = $internal_log;
                    } else if ($used_tickets > 0 || $redeemed_tickets > 0) {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'This booking is already redeemed.';
                    } else {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = 'This booking is already cancelled.';
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['errorMessage'] = 'Previous request is running. Please try after some time.';
                }
            } else {
                $response['status'] = (int) 0;
                $response['errorMessage'] = 'Time to cancel this booking has expired.';
            }
            $MPOS_LOGS['partially_cancel_order'] = $logs;
            return $response;
        } catch (\Exception $e) {
            echo "<pre>"; print_r($e->getMessage());
            $MPOS_LOGS['partially_cancel'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return($MPOS_LOGS['exception']);
        }
    }

    function sales_report_v4($data = array()) {
        global $MPOS_LOGS;
        try {
            $hotel_id = $data['hotel_id'];
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $supplier_id = $data['own_supplier_id'];
            $supplier_cashier_id = $data['supplier_cashier_id'];
            $date = $data['date'];
            $cashier_type = $data['user_details']['cashierType'];
            $reseller_id = $data['user_details']['resellerId'];
            $reseller_cashier_id = $data['user_details']['resellerCashierId'];
            try {
                $url = FIREBASE_URL . '/distributor/bookings_list/' . $hotel_id . '/' . $cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                /* Fetch all bookings from firebase */
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                $getdata = curl_exec($ch);
                curl_close($ch);
                $firebase_orders = json_decode($getdata);
                $prepaid_data = $firebase_orders;


                // check other users orders cancelled
                $url = FIREBASE_URL . '/distributor/others_cancellation/' . $hotel_id . '/' . $cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                $getdata = curl_exec($ch);
                curl_close($ch);
                $firebase_cancels = json_decode($getdata);
                $others_cancellations = $firebase_cancels;


                if (!empty($others_cancellations)) {
                    foreach ($others_cancellations as $data) {
                        if (!empty($data->amount)) {
                            $refunded_by_cashiers[$cashier_id] = $refunded_by_cashiers[$cashier_id] + $data->amount;
                        }
                    }
                }
                /* Fetch activated city cards from firebase */
                if ($supplier_id > 0 && $supplier_cashier_id > 0 || ($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0)) {
                    if($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0) {
                        $url1 = FIREBASE_URL . '/resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                        $data = json_encode(array("node" => 'resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . $date));   
                    } else if ($supplier_id > 0 && $supplier_cashier_id > 0) {
                        $url1 = FIREBASE_URL . '/suppliers/' . $supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                        $data = json_encode(array("node" => 'suppliers/' . $supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . $date)); 
                    }
                  
                    $data = json_encode(array("node" => 'suppliers/' . $supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . $date));
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    $getdata = curl_exec($ch);
                    curl_close($ch);
                    $firebase_activated_orders = json_decode($getdata);
                    $activated_data = $firebase_activated_orders;
                }
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }

            if (!empty($activated_data)) {
                foreach ($activated_data as $data) {
                    if ($data->status == 2 && $data->shift_id == $shift_id) {
                        if ($data->group_id > 0) {
                            $voucher_category = $data->group_id . '_' . strtolower($data->group_name);
                        } else {
                            $voucher_category = '';
                        }
                        $pos_points_array[$data->pos_point_id] = $data->pos_point_name;
                        //Arrange ticket types for activated cards
                        foreach ($data->ticket_types as $tps_id => $type_details) {
                            $activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => ($type_details->age_group != '') ? $type_details->age_group : '',
                                'unit_price' => (float) round(($type_details->price), 2),
                                'net_price' => (float) round($type_details->net_price, 2),
                                'total_price' => round(($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['total_price'] + $type_details->price + $type_details->combi_discount_gross_amount), 2),
                                'total_quantity' => (int) ($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[ucfirst(strtolower($type_details->type))]) && ($this->TICKET_TYPES[ucfirst(strtolower($type_details->type))] > 0)) ? (int) $this->TICKET_TYPES[ucfirst(strtolower($type_details->type))] : (int) 10,
                                'ticket_type_label' => (string) ucfirst(strtolower($type_details->type)),
                                'per_age_group_extra_options' => array()
                            );
                            $activated_ticket_type_details = $this->common_model->sort_ticket_types($activated_ticket_type_details);
                        }
                        //Arrange activated city cards tickets
                        if (!empty($activated_ticket_type_details[$voucher_category][$data->ticket_id]) && !empty($data->ticket_id)) {
                            $activated_tickets_array[$voucher_category][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'total_amount' => round($activated_tickets_array[$voucher_category][$data->ticket_id]['total_amount'] + $data->amount, 2),
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'title' => ($data->ticket_title != '') ? $data->ticket_title : '',
                                'ticket_type_details' => (array) (!empty($activated_ticket_type_details[$voucher_category][$data->ticket_id])) ? $activated_ticket_type_details[$voucher_category][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => array()
                            );
                        }
                    }
                }

                foreach ($activated_tickets_array as $key => $array) {
                    $shift_key = explode('_', $key);
                    foreach ($array as $row) {
                        sort($row['ticket_type_details']);
                        $ticket_scanned_details[$shift_key[0]][] = $row;
                    }

                    $scanned_vouchers[$shift_key[0]] = array(
                        'category_id' => (int) $shift_key[0],
                        'category_name' => $shift_key[1],
                        'total_amount' => (int) 0,
                        'ticket_details' => (!empty($ticket_scanned_details[$shift_key[0]])) ? $ticket_scanned_details[$shift_key[0]] : array()
                    );
                }

                sort($scanned_vouchers);
            }
            //scanned tickets
            $scan_report_data = array();
            if ($supplier_cashier_id > 0 && $supplier_id > 0) {
                $req = array(
                    'cashier_id' => $supplier_cashier_id,
                    'shift_id' => $shift_id,
                    'supplier_id' => $supplier_id,
                    'date' => $date,
                    'scan_report' => "1",
                    'cashier_type' => $cashier_type,
                    'reseller_id' => $reseller_id,
                    'reseller_cashier_id' => $reseller_cashier_id
                );
                $logs['scan_report_req_' . date('H:i:s')] = $req;
                $MPOS_LOGS['sales_report_v4'] = $logs;
                $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                $logs = array();
                $scan_report_data = $this->shift_report_for_redeem_v2($req);
                if (!empty($scan_report_data['error_no']) && $scan_report_data['error_no'] > 0) { //some error in scan report process
                    return $scan_report_data;
                }
            }
            if (!empty($prepaid_data)) {
                foreach ($prepaid_data as $data) {
                    if ($data->shift_id == $shift_id) {
                        $flag = "0";
                        $data->payment_method = $data->payment_method == '12' ? '1' : $data->payment_method;
                        if ($data->group_id > 0) {
                            $voucher_category = '_' . $data->group_id . '_' . strtolower($data->group_name);
                        } else {
                            $voucher_category = '';
                        }
                        if (!empty($data->pos_point_id)) {
                            $pos_points_array[$data->pos_point_id] = $data->pos_point_name;
                        }

                        foreach ($data->ticket_types as $tps_id => $type_details) {
                            $age_group = explode('-', $type_details->age_group);
                            if ($type_details->refund_quantity > 0 && $data->payment_method != 10 && $data->payment_method != 19) {
                                foreach ($type_details->refunded_by as $refund) {
                                    if (!empty($refund->refunded_by)) {
                                        $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $type_details->price);
                                        if ($flag == "0") {
                                            $refunded_by_cashiers[$refund->refunded_by] = ($data->quantity == $data->cancelled_tickets) ? ($refunded_by_cashiers[$refund->refunded_by] - $data->discount_code_amount) : $refunded_by_cashiers[$refund->refunded_by];
                                            $refunded_by_cashiers[$refund->refunded_by] = $refunded_by_cashiers[$refund->refunded_by] - $data->total_combi_discount;
                                        }
                                    }
                                    $flag = "1";
                                }
                            }
                            $cashier_discount = ($type_details->quantity - $type_details->refund_quantity) ? $type_details->cashier_discount : 0;
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                    //all tickets Per age group extra options 
                                    $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description] = array(
                                        'main_description' => $extra_options->main_description,
                                        'description' => $extra_options->description,
                                        'unit_price' => (float) round($extra_options->price, 2),
                                        'quantity' => (int) $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                        'total_price' => (float) round(($per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2),
                                        'refund_quantity' => (int) $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                    );
                                    //total refunded amount
                                    $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                    //total amount and refunded amount for any ticket
                                    $total_payments[$data->ticket_id]['total_amount'] = round($total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity)), 2);
                                    $total_payments[$data->ticket_id]['refunded_amount'] = $total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                }

                                //Arrange  all tickets
                                $ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id] = array(
                                    'tps_id' => (int) $tps_id,
                                    'age_group' => $type_details->age_group,
                                    'unit_price' => (float) round($type_details->price, 2),
                                    'combi_discount' => round(($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)), 2),
                                    'net_price' => (float) round($type_details->net_price, 2),
                                    'total_price' => round(($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + $type_details->price * ($type_details->quantity - $type_details->refund_quantity) - $cashier_discount), 2),
                                    'total_quantity' => (int) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                    'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                    'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                    'ticket_type_label' => (string) $type_details->type,
                                    'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data->ticket_id][$tps_id])) ? $per_age_group_extra_options[$data->ticket_id][$tps_id] : array(),
                                );
                                $ticket_type_details = $this->common_model->sort_ticket_types($ticket_type_details);
                                //total refunded amount
                                $total_payments['refunded_amount'] = ($type_details->refund_quantity > "0") ? round(($total_payments['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount), 2) : round($total_payments['refunded_amount'], 2);
                                //total amount and refunded amount for any ticket
                                $total_payments[$data->ticket_id]['total_amount'] = round(($total_payments[$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount), 2);
                                $total_payments[$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_payments[$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_payments[$data->ticket_id]['refunded_amount'];
                            }
                            foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                if ($extra_options->refund_quantity > 0) {
                                    foreach ($extra_options->refunded_by as $refund) {
                                        if (!empty($refund->refunded_by)) {
                                            $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $extra_options->price);
                                        }
                                    }
                                }
                                //Per age group extra options at pos level
                                $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => (float) round($extra_options->price, 2),
                                    'quantity' => (int) $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2) : (float) round($pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['total_price'], 2),
                                    'refund_quantity' => (int) $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //per age group extra options as per payment method
                                $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round($extra_options->price, 2) : (float) 0,
                                    'quantity' => (int) $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2) : (float) 0,
                                    'refund_quantity' => (int) $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //total refunded amount for diff payment method
                                $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? round(($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)), 2) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                                //total amount and refunded amount for any ticket for diff payment method
                                $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                //total refunded amount at pos level
                                if ($data->payment_method != '10' && $data->payment_method != '19') {
                                    $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : round($total_pos_payments[$data->pos_point_id]['refunded_amount'], 2);
                                }
                                //total amount and refunded amount for any ticket at pos level
                                $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) , 2) : round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'], 2);
                                $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity)), 2) : round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'], 2);
                            }
                            //Arrange ticket types at pos level
                            $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => $type_details->age_group,
                                'unit_price' => (float) round(($type_details->price), 2),
                                'combi_discount' => (float) round(($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)), 2),
                                'net_price' => (float) round(($type_details->net_price), 2),
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount),2) : (float) round($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_price'],2),
                                'total_quantity' => (int) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                'ticket_type_label' => (string) $type_details->type,
                                'per_age_group_extra_options' => (!empty($pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id])) ? $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id] : array()
                            );
                            $pos_ticket_type_details = $this->common_model->sort_ticket_types($pos_ticket_type_details);
                            //Arrange ticket types payment wise
                            $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => $type_details->age_group,
                                'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($type_details->price), 2) : (float) 0,
                                'combi_discount' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)), 2) : (float) 0,
                                'net_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($type_details->net_price), 2) : (float) 0,
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount),2) : (float) 0,
                                'total_quantity' => (int) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                'ticket_type_label' => (string) $type_details->type,
                                'per_age_group_extra_options' => (!empty($payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id])) ? $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id] : array()
                            );
                            $payment_ticket_type_details = $this->common_model->sort_ticket_types($payment_ticket_type_details);
                            //total refunded amount for diff payment method
                            $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                            //total amount and refunded amount for any ticket for diff payment method
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount), 2);
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount), 2) : round($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'], 2);
                            //total refunded amount at pos level
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($type_details->refund_quantity > "0") ? round(($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount), 2) : round($total_pos_payments[$data->pos_point_id]['refunded_amount'], 2);
                            }
                            //total amount and refunded amount for any ticket at pos level
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount ), 2) : (float) round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'], 2);
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0 && $data->payment_method != 10 && $data->payment_method != 19) ? round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount), 2) : round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'], 2);
                        }
                        if ($data->payment_method != '10' && $data->payment_method != '19') {
                            foreach ($data->per_ticket_extra_options as $extra_options) {
                                //all per ticket extra options
                                $per_ticket_extra_options[$data->ticket_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => (float) round(($extra_options->price), 2),
                                    'quantity' => (int) $per_ticket_extra_options[$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => (float) round(($per_ticket_extra_options[$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2),
                                    'refund_quantity' => (int) $per_ticket_extra_options[$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //total refunded amount
                                $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                //total amount and refunded amount for any ticket
                                $total_payments[$data->ticket_id]['total_amount'] = round(($total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2);
                                $total_payments[$data->ticket_id]['refunded_amount'] = round(($total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity)), 2);
                            }
                            //Arrange all tickets and its types 
                            if (!empty($data->ticket_id)) {
                                $tickets_array[$data->ticket_id] = array(
                                    'ticket_id' => (int) $data->ticket_id,
                                    'upsell_ticket' => (int) $data->is_extended_ticket,
                                    'total_amount' => (float) round($total_payments[$data->ticket_id]['total_amount'], 2),
                                    'refunded_amount' => (float) round($total_payments[$data->ticket_id]['refunded_amount'], 2),
                                    'title' => $data->ticket_title,
                                    'ticket_type_details' => $ticket_type_details[$data->ticket_id],
                                    'per_ticket_extra_options' => (!empty($per_ticket_extra_options[$data->ticket_id])) ? $per_ticket_extra_options[$data->ticket_id] : array()
                                );
                            }
                            // total amount and discount and service cost
                            $total_payments['total'] = round(($total_payments['total'] + $data->amount - $data->discount_code_amount),2);
                            /* combined option discount for each ticket in a order. */
                            $total_payments['option_discount'] = round(($total_payments['option_discount'] + $data->discount_code_amount), 2);
                            $total_payments['option_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['option_discount_cancelled'] + $data->discount_code_amount) : $total_payments['option_discount_cancelled'];
                            if (empty($total_payments['service_cost'][$data->order_id])) {
                                $total_payments['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                            }
                            if (empty($total_payments['service_cost_cancelled'][$data->order_id])) {
                                $total_payments['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                            }
                            $total_payments['combi_discount'] = round(($total_payments['combi_discount'] + $data->total_combi_discount), 2);
                            $total_payments['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['combi_discount_cancelled'] + $data->total_combi_discount) : $total_payments['combi_discount_cancelled'];
                        }
                        foreach ($data->per_ticket_extra_options as $extra_options) {
                            if ($extra_options->refund_quantity > 0) {
                                foreach ($extra_options->refunded_by as $refund) {
                                    if (!empty($refund->refunded_by)) {
                                        $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $extra_options->price);
                                    }
                                }
                            }
                            //per ticket extra options at pos level
                            $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description] = array(
                                'main_description' => $extra_options->main_description,
                                'description' => $extra_options->description,
                                'unit_price' => (float) round($extra_options->price,2),
                                'total_price' => ($data->payment_method != '10' && $data->payment_method != '19') ? (float) round(($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))),2) : (float) round($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['total_price'],2),
                                'quantity' => (int) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                'refund_quantity' => (int) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                            );
                            //per ticket extra options for diff payment methods
                            $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description] = array(
                                'main_description' => $extra_options->main_description,
                                'description' => $extra_options->description,
                                'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round($extra_options->price, 2): (float) 0,
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))),2) : (float) 0,
                                'quantity' => (int) $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                'refund_quantity' => (int) $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                            );
                            //total amounts and refunded amount for diff activation method
                            $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? round(($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)), 2) : round($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'], 2);
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))),2);
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity) - $type_details->cashier_discount), 2);
                            //total refunded amount at pos level
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? round(($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)), 2) : round($total_pos_payments[$data->pos_point_id]['refunded_amount'], 2);
                            }
                            //total amount and refunded amount for any ticket at pos level
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))), 2) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'];
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? round(($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity)), 2) : round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'], 2);
                        }
                        //Arrange tickets and its types at pos level
                        if (!empty($data->ticket_id)) {
                            $pos_tickets_array[$data->pos_point_id][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'total_amount' => (float) round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'], 2),
                                'refunded_amount' => (float) round($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'],2),
                                'title' => $data->ticket_title,
                                'ticket_type_details' => (!empty($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id])) ? $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => (!empty($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id])) ? $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id] : array()
                            );
                        }
                        //Arrange tickets and its types payment wise
                        if (!empty($data->ticket_id)) {
                            $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'total_amount' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float)  round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount']),2) : (float) 0,
                                'refunded_amount' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount']),2) : (float) 0,
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'title' => $data->ticket_title,
                                'ticket_type_details' => (!empty($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id])) ? $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => (!empty($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id])) ? $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id] : array()
                            );
                        }
                        if ($data->payment_method != '10' && $data->payment_method != '19') {
                            // total amount and discount and service cost at pos level
                            $total_pos_payments[$data->pos_point_id]['amount'] = round(($total_pos_payments[$data->pos_point_id]['amount'] + $data->amount - $data->discount_code_amount), 2);
                            $total_pos_payments[$data->pos_point_id]['option_discount'][$data->order_id] += $data->discount_code_amount;
                            $total_pos_payments[$data->pos_point_id]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['combi_discount'] = round(($total_pos_payments[$data->pos_point_id]['combi_discount'] + $data->total_combi_discount),2);
                            $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] + $data->total_combi_discount) : $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'];
                        }
                        // total amount and discount and service cost for diff payment methods
                        $total_activation_payment[$data->payment_method . $voucher_category]['amount'] = round(($total_activation_payment[$data->payment_method . $voucher_category]['amount'] + $data->amount - $data->discount_code_amount),2);
                        $total_activation_payment[$data->payment_method . $voucher_category]['option_discount'][$data->order_id] = round($data->discount_code_amount,2);
                        $total_activation_payment[$data->payment_method . $voucher_category]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] = round(($total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] + $data->total_combi_discount),2);
                        $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? round(($total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] + $data->total_combi_discount),2) : round($total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'],2);
                    }
                }

                //calculate user role of cancelled tickets by cashiers
                //total option discount
                foreach ($total_payments['option_discount'] as $discount) {
                    $total_payments['total_option_discount'] += $discount;
                }
                //total option discount for cancelled orders
                foreach ($total_payments['option_discount_cancelled'] as $discount) {
                    $total_payments['total_option_discount_cancelled'] += $discount;
                }
                //total service cost
                foreach ($total_payments['service_cost'] as $discount) {
                    $total_payments['total_service_cost'] += $discount;
                }
                foreach ($total_payments['service_cost_cancelled'] as $discount) {
                    $total_payments['total_service_cost_cancelled'] += $discount;
                }
                //option discount and sercice cost At pos level
                foreach ($total_pos_payments as $pos_point_id => $pos_discount) {
                    foreach ($pos_discount['option_discount'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_option_discount'] += $discount;
                    }
                    foreach ($pos_discount['option_discount_cancelled'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_option_discount_cancelled'] += $discount;
                    }
                    foreach ($pos_discount['service_cost'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_service_cost'] += $discount;
                    }
                    foreach ($pos_discount['service_cost_cancelled'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_service_cost_cancelled'] += $discount;
                    }
                }
                //option discount and sercice cost For payment methods
                foreach ($total_activation_payment as $payment_method => $payment_discount) {
                    foreach ($payment_discount['option_discount'] as $discount) {
                        $total_activation_payment[$payment_method]['total_option_discount'] += $discount;
                    }
                    foreach ($payment_discount['option_discount_cancelled'] as $discount) {
                        $total_activation_payment[$payment_method]['total_option_discount_cancelled'] += $discount;
                    }
                    foreach ($payment_discount['service_cost'] as $discount) {
                        $total_activation_payment[$payment_method]['total_service_cost'] += $discount;
                    }
                    foreach ($payment_discount['service_cost_cancelled'] as $discount) {
                        $total_activation_payment[$payment_method]['total_service_cost_cancelled'] += $discount;
                    }
                }

                //find commission and its range at user level and location level from user_targets table.
                if (!empty($tickets_array)) {
                    if (!empty($pos_points_array)) {
                        $user_targets = $this->find('user_targets', array('select' => '*', 'where' => 'is_active = "1" and (user_id = "' . $cashier_id . '" or location_id IN (' . implode(',', array_keys($pos_points_array)) . ')) and start_date <= "' . gmdate("Y-m-d") . '" and end_date >= "' . gmdate("Y-m-d") . '" and day = "' . gmdate("l") . '"'));
                    } else {
                        $user_targets = $this->find('user_targets', array('select' => '*', 'where' => 'is_active = "1" and user_id = "' . $cashier_id . '" and start_date <= "' . gmdate("Y-m-d") . '" and end_date >= "' . gmdate("Y-m-d") . '" and day = "' . gmdate("l") . '"'));
                    }
                    foreach ($user_targets as $target) {
                        if ($target['user_id'] != 0 && $target['location_id'] != 0) {
                            $user_location_level_approach = (!isset($user_location_level_approach)) ? $target['pricing_name'] : $user_location_level_approach;
                            $user_location_level_target[$target['user_id'] . '_' . $target['location_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        } else if ($target['user_id'] != 0) {
                            $user_level_approach = (!isset($user_level_approach)) ? $target['pricing_name'] : $user_level_approach;
                            $user_level_target[$target['user_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        } else if ($target['location_id'] != 0) {
                            $location_level_approach = (!isset($location_level_approach)) ? $target['pricing_name'] : $location_level_approach;
                            $location_level_target[$target['location_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        }
                    }
                    $fees = array();
                    //Calculate commission at each pos point
                    foreach ($total_pos_payments as $pos_point_id => $amount) {
                        if (!empty($user_location_level_target[$cashier_id . '_' . $pos_point_id])) {
                            //if commission exist at user level and location level
                            $commission_approach = $user_location_level_approach;
                            $commissions = $user_location_level_target[$cashier_id . '_' . $pos_point_id];
                        } else if (!empty($user_level_target[$cashier_id])) {
                            //if commission exist at user level
                            $commission_approach = $user_level_approach;
                            $commissions = $user_level_target[$cashier_id];
                        } else if (!empty($location_level_target[$pos_point_id])) {
                            //if commission  not exist at user level then check at location level
                            $commission_approach = $location_level_approach;
                            $commissions = $location_level_target[$pos_point_id];
                        } else {
                            $commissions = array();
                        }
                        $total_sale = $amount['amount'] - $amount['refunded_amount'] - ($amount['combi_discount'] - $amount['combi_discount_cancelled']) - ($amount['total_option_discount'] - $amount['total_option_discount_cancelled']) - ($amount['total_service_cost'] - $amount['total_service_cost_cancelled']);
                        $count = count($commissions); // count total slots of a day
                        $i = 0;
                        $tier = 0; // Slot Allocations
                        $flag = 0; // exit the loop if flag => 1
                        $last_max = 0; // last maximum slot value
                        $pending_amount = 0; // diffrence between 2 max slots
                        $total_fee = 0; // total commission of cashier
                        $final_array = array(); // Final array to put in csv
                        if (!empty($commissions)) {
                            foreach ($commissions as $row) {
                                if ($flag == 1) {
                                    break;
                                }
                                $tier = $i + 1;
                                $final_array['Tier'] = 'Tier ' . $tier;
                                $final_array['Minimum Amount'] = $row['min_amount'];
                                $final_array['Maximum Amount'] = $row['max_amount'];
                                if ($total_sale > 0) {
                                    /* if total sale didn't exist in any slot */
                                    if ($i == 0 && $total_sale < $row['min_amount']) {
                                        $commission = 0;
                                        $final_array['commission'] = $commission;
                                        $final_array['sale_amount'] = $total_sale;
                                        $final_array['fees'] = 0;
                                        $flag = 1;
                                    } else {
                                        if ($commission_approach == 1) {//Tier pricing approach
                                            $pending_amount = $row['max_amount'] - $last_max; // get diffrence between 2 max slots
                                            /*  allocate whole pending amount to max slot i.e last slot */
                                            if ($i == $count - 1 || $total_sale <= $pending_amount) {
                                                $flag = 1;
                                            } else {
                                                /*  total amount greater than slot maximum amount */
                                                $total_sale -= $pending_amount;
                                                $final_array['sale_amount'] = $pending_amount;
                                            }
                                            $commission = $row['commission'];
                                            $final_array['commission'] = $commission;
                                            $final_array['fees'] = ($final_array['sale_amount'] * $commission ) / 100;
                                            $final_array['sale_amount'] = $total_sale;
                                        } else {//Linear approach
                                            if ($total_sale <= $row['max_amount'] || ($i == $count - 1)) {
                                                $commission = $row['commission'];
                                                $final_array['commission'] = $commission;
                                                $final_array['sale_amount'] = $total_sale;
                                                $final_array['fees'] = ($final_array['sale_amount'] * $commission ) / 100;
                                                $flag = 1;
                                            } else {
                                                $final_array['commission'] = '-';
                                                $final_array['sale_amount'] = '-';
                                                $final_array['fees'] = 0;
                                            }
                                        }
                                    }
                                }
                                $last_max = $row['max_amount'];
                                $i++;
                                $total_fee += $final_array['fees'];
                                $fees[$pos_point_id] = $total_fee;
                            }
                        }
                    }
                }



                //prepare final array for pos level tickets
                if (!empty($pos_tickets_array)) {
                    foreach ($pos_tickets_array as $pos_key => $array) {
                        foreach ($array as $ticket) {
                            krsort($ticket['ticket_type_details']);
                            $tps_age_sorted_array = array();
                            foreach ($ticket['ticket_type_details'] as $tps_array) {
                                foreach ($tps_array as $type_details) {
                                    sort($type_details['per_age_group_extra_options']);
                                    $tps_age_sorted_array[] = $type_details;
                                }
                            }
                            sort($ticket['per_ticket_extra_options']);
                            $ticket['ticket_type_details'] = $tps_age_sorted_array;
                            $pos_tickets[$pos_key][] = $ticket;
                        }
                        $pos_shift_details[$pos_key] = array(
                            'pos_point_id' => (int) $pos_key,
                            'pos_point_name' => $pos_points_array[$pos_key],
                            'total_amount' => (float) ($total_pos_payments[$pos_key]['amount'] - $total_pos_payments[$pos_key]['refunded_amount']),
                            'commission' => (!empty($fees[$pos_key])) ? (float) round($fees[$pos_key], 2) : (float) 0,
                            'combi_discount' => (float) ($total_pos_payments[$pos_key]['combi_discount'] - $total_pos_payments[$pos_key]['combi_discount_cancelled']),
                            'option_discount' => ($total_pos_payments[$pos_key]['total_option_discount'] > 0) ? (float) ($total_pos_payments[$pos_key]['total_option_discount'] - $total_pos_payments[$pos_key]['total_option_discount_cancelled']) : (float) 0,
                            'service_cost' => ($total_pos_payments[$pos_key]['total_service_cost'] > 0) ? (float) ($total_pos_payments[$pos_key]['total_service_cost'] - $total_pos_payments[$pos_key]['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_pos_payments[$pos_key]['refunded_amount'] > 0) ? (float) ($total_pos_payments[$pos_key]['refunded_amount']) : (float) 0,
                            'tickets' => $pos_tickets[$pos_key]
                        );
                    }
                }
                sort($pos_shift_details);
                //final array for diff paymebnt methods
                if (!empty($payment_tickets_array)) {
                    foreach ($payment_tickets_array as $payment_method => $array) {
                        $payment_values = explode('_', $payment_method);
                        foreach ($array as $ticket) {
                            krsort($ticket['ticket_type_details']);
                            $tps_age_sorted_array = array();
                            foreach ($ticket['ticket_type_details'] as $tps_array) {
                                foreach ($tps_array as $type_details) {
                                    sort($type_details['per_age_group_extra_options']);
                                    $tps_age_sorted_array[] = $type_details;
                                }
                            }
                            sort($ticket['per_ticket_extra_options']);
                            $ticket['ticket_type_details'] = $tps_age_sorted_array;
                            $payment_tickets[$payment_method][] = $ticket;
                        }
                        $categories[$payment_values[0]][$payment_values[1]] = array(
                            'category_id' => (isset($payment_values[1])) ? (int) $payment_values[1] : (int) 0,
                            'category_name' => (isset($payment_values[2])) ? $payment_values[2] : '',
                            'tickets' => $payment_tickets[$payment_method]
                        );
                        // payment_values 10 is for vouchers and 19 is for scan exception case 
                        $payment_shift_details[$payment_values[0]] = array(
                            'payment_method' => (int) $payment_values[0],
                            'total_amount' => ($payment_values[0] != 10 && $payment_values[0] != 19) ? (float)  round(($total_activation_payment[$payment_method]['amount'] - $total_activation_payment[$payment_method]['refunded_amount']),2) : (float) 0,
                            'combi_discount' => ($payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['combi_discount'] - $total_activation_payment[$payment_method]['combi_discount_cancelled']) : (float) 0,
                            'option_discount' => ($total_activation_payment[$payment_method]['total_option_discount'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['total_option_discount'] - $total_activation_payment[$payment_method]['total_option_discount_cancelled']) : (float) 0,
                            'service_cost' => ($total_activation_payment[$payment_method]['total_service_cost'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['total_service_cost'] - $total_activation_payment[$payment_method]['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_activation_payment[$payment_method]['refunded_amount'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['refunded_amount']) : (float) 0,
                            'categories' => $categories[$payment_values[0]]
                        );
                    }
                }
                if (!empty($payment_shift_details)) {
                    if (!empty($payment_shift_details["2"])) {
                        sort($payment_shift_details["2"]['categories']);
                        $payment_shift_details["2"]['categories'] = (array) $payment_shift_details["2"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["2"];
                    }
                    if (!empty($payment_shift_details["1"])) {
                        sort($payment_shift_details["1"]['categories']);
                        $payment_shift_details["1"]['categories'] = (array) $payment_shift_details["1"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["1"];
                    }
                    if (!empty($payment_shift_details["3"])) {
                        sort($payment_shift_details["3"]['categories']);
                        $payment_shift_details["3"]['categories'] = (array) $payment_shift_details["3"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["3"];
                    }
                    if (!empty($payment_shift_details["10"])) {
                        sort($payment_shift_details["10"]['categories']);
                        $payment_shift_details["10"]['categories'] = (array) $payment_shift_details["10"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["10"];
                    }
                    if (!empty($payment_shift_details["19"])) {
                        sort($payment_shift_details["19"]['categories']);
                        $payment_shift_details["19"]['categories'] = (array) $payment_shift_details["19"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["19"];
                    }
                }
                // all tickets
                if (!empty($tickets_array)) {
                    foreach ($tickets_array as $array) {
                        krsort($array['ticket_type_details']);
                        $tps_age_sorted_array = array();
                        foreach ($array['ticket_type_details'] as $tps_array) {
                            foreach ($tps_array as $type_details) {
                                sort($type_details['per_age_group_extra_options']);
                                $tps_age_sorted_array[] = $type_details;
                            }
                        }
                        sort($array['per_ticket_extra_options']);
                        $array['ticket_type_details'] = $tps_age_sorted_array;
                        $all_tickets_array[] = $array;
                        $all_shift_details = array(
                            'total_amount' => (float) round($total_payments['total'] - $total_payments['refunded_amount'], 2),
                            'combi_discount' => (float) ($total_payments['combi_discount'] - $total_payments['combi_discount_cancelled']),
                            'total_option_discount' => ($total_payments['total_option_discount'] > 0) ? (float) ($total_payments['total_option_discount'] - $total_payments['total_option_discount_cancelled']) : (float) 0,
                            'total_service_cost' => ($total_payments['total_service_cost'] > 0) ? (float) ($total_payments['total_service_cost'] - $total_payments['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_payments['refunded_amount'] > 0) ? (float) ($total_payments['refunded_amount']) : (float) 0,
                            'tickets' => $all_tickets_array
                        );
                    }
                }
            }
            //calculate user role of cancelled tickets by cashiers
            if (!empty($refunded_by_cashiers)) {
                $user_types = $this->find('users', array('select' => 'id, user_role', 'where' => 'id IN (' . implode(',', array_keys($refunded_by_cashiers)) . ')'));
                foreach ($user_types as $user) {
                    $user_role[$user['id']] = $user['user_role'];
                }
                foreach ($refunded_by_cashiers as $cashier_id => $amount) {
                    if ($cashier_id == "1") {
                        $user_role[$cashier_id] = "0";
                    }
                    $refunded_amounts_array[] = array(
                        'cashier_id' => (int) $cashier_id,
                        'user_role' => (int) $user_role[$cashier_id],
                        'cancelled_amount' => (float) $amount
                    );
                }
            }
            if ((!empty($payment_ticket_arrays)) || (!empty($pos_shift_details)) || (!empty($scanned_vouchers)) || (!empty($scan_report_data)) || !empty($refunded_amounts_array)) {
                if (empty($all_shift_details)) {
                    $all_shift_details = array(
                        'total_amount' => (float) 0,
                        'combi_discount' => (float) 0,
                        'total_option_discount' => (float) 0,
                        'total_service_cost' => (float) 0,
                        'refunded_amount' => (float) 0,
                        'tickets' => array()
                    );
                }
                /* send response to app. */
                $response = array(
                    'status' => (int) 1,
                    'total_amount' => ($total_payments['total'] > 0) ? (float)  round($total_payments['total'] + $total_payments['option_discount'], 2) : (float) 0,
                    'total_refunded_amount' => ($total_payments['refunded_amount'] > 0) ? (float) ($total_payments['refunded_amount'] - $total_payments['combi_discount_cancelled'] + $total_payments['total_service_cost_cancelled']) : (float) 0,
                    'total_combi_discount' => ($total_payments['combi_discount'] > 0) ? (float) ($total_payments['combi_discount']) : (float) 0,
                    'total_option_discount' => ($total_payments['option_discount'] > 0) ? (float) ($total_payments['option_discount']) : (float) 0,
                    'service_cost' => ($total_payments['total_service_cost'] > 0) ? (float) ($total_payments['total_service_cost']) : (float) 0,
                    'total_combi_discount_cancelled' => ($total_payments['combi_discount_cancelled'] > 0) ? (float) ($total_payments['combi_discount_cancelled']) : (float) 0,
                    'total_option_discount_cancelled' => ($total_payments['option_discount_cancelled'] > 0) ? (float) ($total_payments['option_discount_cancelled']) : (float) 0,
                    'service_cost_cancelled' => ($total_payments['total_service_cost_cancelled'] > 0) ? (float) ($total_payments['total_service_cost_cancelled']) : (float) 0,
                    'shift_details' => (!empty($all_shift_details)) ? $all_shift_details : (object) array(),
                    'payment_level_shift_details' => (!empty($payment_ticket_arrays)) ? (array) $payment_ticket_arrays : array(),
                    'pos_level_shift_details' => (!empty($pos_shift_details)) ? $pos_shift_details : array(),
                    'scanned_citycards_details' => (!empty($scanned_vouchers)) ? $scanned_vouchers : array(),
                    'refunded_amounts_array' => (!empty($refunded_amounts_array)) ? $refunded_amounts_array : array(),
                    'scan_report' => (!empty($scan_report_data)) ? $scan_report_data : array()
                );
            } else {
                $response['status'] = (int) 2;
                $response['errorMessage'] = 'No bookings';
            }

            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['sales_report_v4'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }
    
    function sales_report_v4_db($data = array()) {
        global $MPOS_LOGS;
        try {
            $hotel_id = $data['hotel_id'];
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $supplier_id = $data['own_supplier_id'];
            $supplier_cashier_id = $data['supplier_cashier_id'];
            $date = $data['date'];
            $cashier_type = $data['user_details']['cashierType'];
            $reseller_id = $data['user_details']['resellerId'];
            $reseller_cashier_id = $data['user_details']['resellerCashierId'];
            try {                             
                // Fetch all bookings from  database
                $query = "Select * from prepaid_tickets where hotel_id ='".$hotel_id."' and cashier_id = '".$cashier_id."' and DATE(created_date_time) ='".$date."' and (is_refunded = '0' || is_refunded= '2') and is_addon_ticket = '0'  ";
                $logs['booking_query_'.date("Y-m-d H:i:s")] = $query;
                $result = $this->primarydb->db->query($query)->result_array();
                $logs['booking_query_result_count_'.date("Y-m-d H:i:s")] = count($result);
                if (!empty($result)) {
                    foreach ($result as  $pt_data) {
                        $vgn_data[$pt_data['visitor_group_no'].'_'.$pt_data['ticket_id']][] = $pt_data ;
                    }
                    $all_vgn = array_unique(array_column($result, 'visitor_group_no'));
                    $sql = "Select * from prepaid_extra_options where visitor_group_no In(".implode(',', $all_vgn).")";
                    $extra_option_result = $this->primarydb->db->query($sql)->result_array();
                    foreach ($extra_option_result as  $pt_eo_data) {
                        if($pt_eo_data['variation_type'] == 0) {
                            $eo_vgn_data[$pt_eo_data['visitor_group_no'].'_'.$pt_eo_data['ticket_id']]['eo_data'][$pt_eo_data['ticket_price_schedule_id']][] = (object) array("description" => $pt_eo_data['description'], "main_description" => $pt_eo_data['main_description'], "price" => $pt_eo_data['price'], "quantity" => $pt_eo_data['quantity'], "refund_quantity" => $pt_eo_data['refund_quantity'] ) ;
                            $eo_vgn_data[$pt_eo_data['visitor_group_no'].'_'.$pt_eo_data['ticket_id']]['eo_total_price'] = $eo_vgn_data[$pt_eo_data['visitor_group_no'].'_'.$pt_eo_data['ticket_id']]['eo_total_price'] + ($pt_eo_data['price'] * ($pt_eo_data['quantity'] - $pt_eo_data['refund_quantity']));
                        }
                    }  
                    foreach ($vgn_data as $vgn_ticket => $data) {
                        $key = $vgn_ticket;
                        $extra_option_amount  = !empty($eo_vgn_data[$key]['eo_total_price']) ?  $eo_vgn_data[$key]['eo_total_price'] : 0;
                        foreach ($data as $ticket_data) {         
                            $discount_array = !empty($ticket_data['extra_discount']) ? unserialize($ticket_data['extra_discount']) : array();
                            $discount_gross_amount = !empty($discount_array['gross_discount_amount']) ? $discount_array['gross_discount_amount'] : 0;                            
                            if ($ticket_data['is_refunded'] == "2" || $ticket_data['is_refunded'] == "0") {
                                $vgn_arr[$key]['activated_at']                    = (int) 0;
                                $vgn_arr[$key]['activated_by']                    = (int) 0;
                                $vgn_arr[$key]['amount']                          = (float) ($vgn_arr[$key]['amount'] + (($ticket_data['price'] + $discount_gross_amount) * $ticket_data['quantity']));
                                $vgn_arr[$key]['booking_date_time']               = $ticket_data['created_date_time'];
                                $vgn_arr[$key]['booking_name']                    = $ticket_data['guest_names'];
                                $vgn_arr[$key]['cancelled_tickets']               = (int) $ticket_data['is_refunded'] == "2" ? $vgn_arr[$key]['cancelled_tickets'] + $ticket_data['quantity']: 0;
                                $vgn_arr[$key]['cashier_name']                    = $ticket_data['cashier_name'];
                                $vgn_arr[$key]['channel_type']                    = $ticket_data['channel_type'];
                                $vgn_arr[$key]['discount_code_amount']            = $discount_gross_amount;
                                $vgn_arr[$key]['discount_code_type']              = !empty($discount_array['discount_type']) ? $discount_array['discount_type'] : 0;
                                $vgn_arr[$key]['from_time']                       = $ticket_data['from_time'];
                                $vgn_arr[$key]['group_id']                        = $ticket_data['activation_method'] == "10" || $ticket_data['activation_method']== "19" ? (int) $ticket_data['partner_category_id'] : (int) 0;
                                $vgn_arr[$key]['group_name']                      = $ticket_data['activation_method'] == "10" || $ticket_data['activation_method'] == "19" ? $ticket_data['partner_category_name'] : '';
                                $vgn_arr[$key]['is_combi_ticket']                 = $ticket_data['is_combi_ticket'];
                                $vgn_arr[$key]['total_combi_discount']            = (float) $ticket_data['combi_discount_gross_amount'];
                                $vgn_arr[$key]['is_extended_ticket']              = (int) 0;
                                $vgn_arr[$key]['museum']                          = $ticket_data['museum_name'];
                                $vgn_arr[$key]['order_id']                        = $ticket_data['visitor_group_no'];
                                $vgn_arr[$key]['payment_method']                  = $ticket_data['activation_method'];
                                $vgn_arr[$key]['pos_point_id']                    = $ticket_data['pos_point_id'];
                                $vgn_arr[$key]['pos_point_name']                  = $ticket_data['pos_point_name'];
                                $vgn_arr[$key]['quantity']                        = $vgn_arr[$key]['quantity'] + $ticket_data['quantity'];
                                $vgn_arr[$key]['shift_id']                        = $ticket_data['shift_id'];
                                $vgn_arr[$key]['slot_type']                       = $ticket_data['slot_type'];
                                $vgn_arr[$key]['ticket_id']                       = $ticket_data['ticket_id'];
                                $vgn_arr[$key]['ticket_title']                    = $ticket_data['title'];
                                if (!empty($eo_vgn_data[$key]['eo_data']['0'])) {
                                    $vgn_arr[$key]['per_ticket_extra_options']        = !empty($eo_vgn_data[$key]['eo_data']['0']) ? $eo_vgn_data[$key]['eo_data']['0'] : array();
                                }

                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['age_group']         = $ticket_data['age_group'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['net_price']         = (float) $ticket_data['net_price'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['price']             = (float) $ticket_data['price'] + $discount_gross_amount;
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['cashier_discount']  = (float) ($vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['cashier_discount'] + ($ticket_data['quantity'] * $discount_gross_amount));
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['quantity']          = (int) $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['quantity'] + $ticket_data['quantity'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity']   = (int) $ticket_data['is_refunded'] ==  "2" ? $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity'] + $ticket_data['quantity'] : 0;
                                if (!empty($eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']])) {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['per_age_group_extra_options']        = !empty($eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']]) ? $eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']] : array();
                                }
                              
                                if ($ticket_data['is_refunded'] ==  "2") {
                                    $count = (int) $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity'];
                                    $refunded_by =  (int) $ticket_data['is_refunded'] ==  "2" ? $ticket_data['refunded_by'] : '';
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refunded_by'][] = (object) array("count" => $count, "refunded_by"=> $refunded_by);
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refunded_passes'][]   =  $ticket_data['passNo'];
                                }
                                if ($ticket_data['is_refunded'] ==  "") {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['passes'][]   =  $ticket_data['passNo'];
                                }
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['tax']        = (float) $ticket_data['tax'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['tps_id']     = (int) $ticket_data['tps_id'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['type']       = $ticket_data['ticket_type'];
                                $vgn_arr[$key]['timezone']                                      = (int) $ticket_data['timezone'];
                                $vgn_arr[$key]['to_time']                                       =  $ticket_data['to_time'];
                            }
                        }
                        $vgn_arr[$key]['amount']                                               = (float) ($vgn_arr[$key]['amount'] + $extra_option_amount);
                    }
                    foreach ($vgn_arr as $key => $value) {
                        foreach ($value['ticket_types'] as $tps_id =>  $tps_data) {
                            $value['ticket_types'][$tps_id] = (object) $tps_data;
                        }
                        $value['ticket_types'] = (object) $value['ticket_types'];
                        $prepaid_data[$key] = (object) $value;
                    }
                }
                $sql = "Select * from prepaid_tickets where hotel_id ='".$hotel_id."' and cashier_id != '".$cashier_id."' and refunded_by = '".$cashier_id."' and DATE(created_date_time) ='".$date."' and is_refunded = '2' and is_addon_ticket = '0'";
                $logs['refund_query_'.date("Y-m-d H:i:s")] = $sql;
                $result_array = $this->primarydb->db->query($sql)->result_array();
                $logs['refund_query_result_count_'.date("Y-m-d H:i:s")] = count($result_array);
                if(!empty($result_array)) {
                    foreach($result_array as  $new_pt_data) {            
                        $new_vgn_data[$new_pt_data['visitor_group_no'].'_'.$new_pt_data['ticket_id']][] = $new_pt_data ;
                    }
                    foreach($new_vgn_data as $refund_vgn_ticket => $refund_data) {  
                        foreach($refund_data as $refund_ticket_data)  {
                            if($refund_ticket_data['is_refunded'] == "2"){                                                         
                                $refund_array[$refund_vgn_ticket]['amount']        =   $refund_array[$refund_vgn_ticket][$refund_ticket_data['ticket_id']]['amount'] + $refund_ticket_data['price'];
                                $refund_array[$refund_vgn_ticket]['cashier_id']    =   $refund_ticket_data['cashier_id'];                             
                            }
                        }    
                    }
                    foreach($refund_array as $val) {
                        $others_cancellations[] = (object) $val;
                    }
    
                    if (!empty($others_cancellations)) {
                        foreach ($others_cancellations as $data) {
                            if (!empty($data->amount)) {
                                $refunded_by_cashiers[$cashier_id] = $refunded_by_cashiers[$cashier_id] + $data->amount;
                            }
                        }
                    }
                }
                                
                /* Fetch activated city cards from firebase */
                if(($supplier_id > 0 && $supplier_cashier_id > 0) || ($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0)) {
                    if($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0) {
                        $url1 = FIREBASE_URL . '/resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                        $data = json_encode(array("node" => 'resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . $date));   
                    } else if ($supplier_id > 0 && $supplier_cashier_id > 0) {
                        $url1 = FIREBASE_URL . '/suppliers/' . $supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . $date . '.json?auth=' . SECRET_MANAGER['FIREBASE_AUTH_KEY'] ?? FIREBASE_AUTH_KEY;
                        $data = json_encode(array("node" => 'suppliers/' . $supplier_id . '/voucher_scans/' . $supplier_cashier_id . '/' . $date)); 
                    }
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    $getdata = curl_exec($ch);
                    curl_close($ch);
                    $firebase_activated_orders = json_decode($getdata);
                    $activated_data = $firebase_activated_orders;
                }
               
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }

            if (!empty($activated_data)) {
                foreach ($activated_data as $data) {
                    if ($data->status == 2 && $data->shift_id == $shift_id) {
                        if ($data->group_id > 0) {
                            $voucher_category = $data->group_id . '_' . strtolower($data->group_name);
                        } else {
                            $voucher_category = '';
                        }
                        $pos_points_array[$data->pos_point_id] = $data->pos_point_name;
                        //Arrange ticket types for activated cards
                        foreach ($data->ticket_types as $tps_id => $type_details) {
                            $activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => ($type_details->age_group != '') ? $type_details->age_group : '',
                                'unit_price' => (float) ($type_details->price),
                                'net_price' => (float) $type_details->net_price,
                                'total_price' => (float) ($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['total_price'] + $type_details->price + $type_details->combi_discount_gross_amount),
                                'total_quantity' => (int) ($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $activated_ticket_type_details[$voucher_category][$data->ticket_id][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[ucfirst(strtolower($type_details->type))]) && ($this->TICKET_TYPES[ucfirst(strtolower($type_details->type))] > 0)) ? (int) $this->TICKET_TYPES[ucfirst(strtolower($type_details->type))] : (int) 10,
                                'ticket_type_label' => (string) ucfirst(strtolower($type_details->type)),
                                'per_age_group_extra_options' => array()
                            );
                            $activated_ticket_type_details = $this->common_model->sort_ticket_types($activated_ticket_type_details);
                        }
                        //Arrange activated city cards tickets
                        if (!empty($activated_ticket_type_details[$voucher_category][$data->ticket_id]) && !empty($data->ticket_id)) {
                            $activated_tickets_array[$voucher_category][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'total_amount' => (float) ($activated_tickets_array[$voucher_category][$data->ticket_id]['total_amount'] + $data->amount),
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'title' => ($data->ticket_title != '') ? $data->ticket_title : '',
                                'ticket_type_details' => (array) (!empty($activated_ticket_type_details[$voucher_category][$data->ticket_id])) ? $activated_ticket_type_details[$voucher_category][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => array()
                            );
                        }
                    }
                }

                foreach ($activated_tickets_array as $key => $array) {
                    $shift_key = explode('_', $key);
                    foreach ($array as $row) {
                        sort($row['ticket_type_details']);
                        $ticket_scanned_details[$shift_key[0]][] = $row;
                    }

                    $scanned_vouchers[$shift_key[0]] = array(
                        'category_id' => (int) $shift_key[0],
                        'category_name' => $shift_key[1],
                        'total_amount' => (int) 0,
                        'ticket_details' => (!empty($ticket_scanned_details[$shift_key[0]])) ? $ticket_scanned_details[$shift_key[0]] : array()
                    );
                }

                sort($scanned_vouchers);
            }
            //scanned tickets
            $scan_report_data = array();
            if ($supplier_cashier_id > 0 && $supplier_id > 0 ||  ($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0)) {
                $req = array(
                    'cashier_id' => $supplier_cashier_id,
                    'shift_id' => $shift_id,
                    'supplier_id' => $supplier_id,
                    'date' => $date,
                    'scan_report' => "1",
                    'cashier_type' => $cashier_type,
                    'reseller_id' => $reseller_id,
                    'reseller_cashier_id' => $reseller_cashier_id
                );
                $logs['scan_report_req_' . date('H:i:s')] = $req;
                $MPOS_LOGS['sales_report_v4'] = $logs;
                $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                $logs = array();
                $scan_report_data = $this->shift_report_for_redeem_v2($req);
                if (!empty($scan_report_data['error_no']) && $scan_report_data['error_no'] > 0) { //some error in scan report process
                    return $scan_report_data;
                }
            }
            if (!empty($prepaid_data)) {
                foreach ($prepaid_data as $data) {
                    if ($data->shift_id == $shift_id) {
                        $flag = "0";
                        $data->payment_method = $data->payment_method == '12' ? '1' : $data->payment_method;
                        if ($data->group_id > 0) {
                            $voucher_category = '_' . $data->group_id . '_' . strtolower($data->group_name);
                        } else {
                            $voucher_category = '';
                        }
                        if (!empty($data->pos_point_id)) {
                            $pos_points_array[$data->pos_point_id] = $data->pos_point_name;
                        }
                        foreach ($data->ticket_types as $tps_id => $type_details) {
                            $age_group = explode('-', $type_details->age_group);
                            if ($type_details->refund_quantity > 0 && $data->payment_method != 10 && $data->payment_method != 19) {
                                foreach ($type_details->refunded_by as $refund) {
                                    if (!empty($refund->refunded_by)) {
                                        $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $type_details->price);
                                        if ($flag == "0") {
                                            $refunded_by_cashiers[$refund->refunded_by] = ($data->quantity == $data->cancelled_tickets) ? ($refunded_by_cashiers[$refund->refunded_by] - $data->discount_code_amount) : $refunded_by_cashiers[$refund->refunded_by];
                                            $refunded_by_cashiers[$refund->refunded_by] = $refunded_by_cashiers[$refund->refunded_by] - $data->total_combi_discount;
                                        }
                                    }
                                    $flag = "1";
                                }
                            }
                            $cashier_discount = ($type_details->quantity - $type_details->refund_quantity) ? $type_details->cashier_discount : 0;
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                    //all tickets Per age group extra options 
                                    $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description] = array(
                                        'main_description' => $extra_options->main_description,
                                        'description' => $extra_options->description,
                                        'unit_price' => (float) $extra_options->price,
                                        'quantity' => (int) $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                        'total_price' => (float) ($per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))),
                                        'refund_quantity' => (int) $per_age_group_extra_options[$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                    );
                                    //total refunded amount
                                    $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                    //total amount and refunded amount for any ticket
                                    $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                    $total_payments[$data->ticket_id]['refunded_amount'] = $total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                }

                                //Arrange  all tickets
                                $ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id] = array(
                                    'tps_id' => (int) $tps_id,
                                    'age_group' => $type_details->age_group,
                                    'unit_price' => (float) ($type_details->price),
                                    'combi_discount' => (float) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)),
                                    'net_price' => (float) $type_details->net_price,
                                    'total_price' => (float) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + $type_details->price * ($type_details->quantity - $type_details->refund_quantity) - $cashier_discount),
                                    'total_quantity' => (int) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                    'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $ticket_type_details[$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                    'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                    'ticket_type_label' => (string) $type_details->type,
                                    'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$data->ticket_id][$tps_id])) ? $per_age_group_extra_options[$data->ticket_id][$tps_id] : array(),
                                );
                                $ticket_type_details = $this->common_model->sort_ticket_types($ticket_type_details);
                                //total refunded amount
                                $total_payments['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_payments['refunded_amount'];
                                //total amount and refunded amount for any ticket
                                $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount;
                                $total_payments[$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_payments[$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_payments[$data->ticket_id]['refunded_amount'];
                            }
                            foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                if ($extra_options->refund_quantity > 0) {
                                    foreach ($extra_options->refunded_by as $refund) {
                                        if (!empty($refund->refunded_by)) {
                                            $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $extra_options->price);
                                        }
                                    }
                                }
                                //Per age group extra options at pos level
                                $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => (float) $extra_options->price,
                                    'quantity' => (int) $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) : (float) $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['total_price'],
                                    'refund_quantity' => (int) $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //per age group extra options as per payment method
                                $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) $extra_options->price : (float) 0,
                                    'quantity' => (int) $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) : (float) 0,
                                    'refund_quantity' => (int) $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //total refunded amount for diff payment method
                                $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                                //total amount and refunded amount for any ticket for diff payment method
                                $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                //total refunded amount at pos level
                                if ($data->payment_method != '10' && $data->payment_method != '19') {
                                    $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                                }
                                //total amount and refunded amount for any ticket at pos level
                                $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity)) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'];
                                $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'];
                            }
                            //Arrange ticket types at pos level
                            $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => $type_details->age_group,
                                'unit_price' => (float) ($type_details->price),
                                'combi_discount' => (float) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)),
                                'net_price' => (float) $type_details->net_price,
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount) : (float) $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_price'],
                                'total_quantity' => (int) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                'ticket_type_label' => (string) $type_details->type,
                                'per_age_group_extra_options' => (!empty($pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id])) ? $pos_level_per_age_group_extra_options[$data->pos_point_id][$data->ticket_id][$tps_id] : array()
                            );
                            $pos_ticket_type_details = $this->common_model->sort_ticket_types($pos_ticket_type_details);
                            //Arrange ticket types payment wise
                            $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id] = array(
                                'tps_id' => (int) $tps_id,
                                'age_group' => $type_details->age_group,
                                'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($type_details->price) : (float) 0,
                                'combi_discount' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['combi_discount'] + ($type_details->combi_discount_gross_amount)) : (float) 0,
                                'net_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) $type_details->net_price : (float) 0,
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['total_price'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount) : (float) 0,
                                'total_quantity' => (int) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['total_quantity'] + $type_details->quantity),
                                'refund_quantity' => ($type_details->refund_quantity > "0") ? (int) ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'] + $type_details->refund_quantity) : (int) $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$age_group[1]][$tps_id]['refund_quantity'],
                                'ticket_type' => (!empty($this->TICKET_TYPES[$type_details->type]) && ($this->TICKET_TYPES[$type_details->type] > 0)) ? (int) $this->TICKET_TYPES[$type_details->type] : (int) 10,
                                'ticket_type_label' => (string) $type_details->type,
                                'per_age_group_extra_options' => (!empty($payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id])) ? $payment_per_age_group_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id] : array()
                            );
                            $payment_ticket_type_details = $this->common_model->sort_ticket_types($payment_ticket_type_details);
                            //total refunded amount for diff payment method
                            $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                            //total amount and refunded amount for any ticket for diff payment method
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount;
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'];
                            //total refunded amount at pos level
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                            }
                            //total amount and refunded amount for any ticket at pos level
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount ) : (float) $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'];
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0 && $data->payment_method != 10 && $data->payment_method != 19) ? ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'];
                        }
                        if ($data->payment_method != '10' && $data->payment_method != '19') {
                            foreach ($data->per_ticket_extra_options as $extra_options) {
                                //all per ticket extra options
                                $per_ticket_extra_options[$data->ticket_id][$extra_options->description] = array(
                                    'main_description' => $extra_options->main_description,
                                    'description' => $extra_options->description,
                                    'unit_price' => (float) $extra_options->price,
                                    'quantity' => (int) $per_ticket_extra_options[$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                    'total_price' => (float) ($per_ticket_extra_options[$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))),
                                    'refund_quantity' => (int) $per_ticket_extra_options[$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                                );
                                //total refunded amount
                                $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                //total amount and refunded amount for any ticket
                                $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                $total_payments[$data->ticket_id]['refunded_amount'] = $total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                            }
                            //Arrange all tickets and its types 
                            if (!empty($data->ticket_id)) {
                                $tickets_array[$data->ticket_id] = array(
                                    'ticket_id' => (int) $data->ticket_id,
                                    'upsell_ticket' => (int) $data->is_extended_ticket,
                                    'total_amount' => (float) ($total_payments[$data->ticket_id]['total_amount']),
                                    'refunded_amount' => (float) ($total_payments[$data->ticket_id]['refunded_amount']),
                                    'title' => $data->ticket_title,
                                    'ticket_type_details' => $ticket_type_details[$data->ticket_id],
                                    'per_ticket_extra_options' => (!empty($per_ticket_extra_options[$data->ticket_id])) ? $per_ticket_extra_options[$data->ticket_id] : array()
                                );
                            }
                            // total amount and discount and service cost
                            $total_payments['total'] = ($total_payments['total'] + $data->amount - $data->discount_code_amount);
                            /* combined option discount for each ticket in a order. */
                            $total_payments['option_discount'] = ($total_payments['option_discount'] + $data->discount_code_amount);
                            $total_payments['option_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['option_discount_cancelled'] + $data->discount_code_amount) : $total_payments['option_discount_cancelled'];
                            if (empty($total_payments['service_cost'][$data->order_id])) {
                                $total_payments['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                            }
                            if (empty($total_payments['service_cost_cancelled'][$data->order_id])) {
                                $total_payments['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                            }
                            $total_payments['combi_discount'] = $total_payments['combi_discount'] + $data->total_combi_discount;
                            $total_payments['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['combi_discount_cancelled'] + $data->total_combi_discount) : $total_payments['combi_discount_cancelled'];
                        }
                        foreach ($data->per_ticket_extra_options as $extra_options) {
                            if ($extra_options->refund_quantity > 0) {
                                foreach ($extra_options->refunded_by as $refund) {
                                    if (!empty($refund->refunded_by)) {
                                        $refunded_by_cashiers[$refund->refunded_by] += ($refund->count * $extra_options->price);
                                    }
                                }
                            }
                            //per ticket extra options at pos level
                            $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description] = array(
                                'main_description' => $extra_options->main_description,
                                'description' => $extra_options->description,
                                'unit_price' => (float) $extra_options->price,
                                'total_price' => ($data->payment_method != '10' && $data->payment_method != '19') ? (float) ($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) : (float) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['total_price'],
                                'quantity' => (int) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                'refund_quantity' => (int) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                            );
                            //per ticket extra options for diff payment methods
                            $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description] = array(
                                'main_description' => $extra_options->main_description,
                                'description' => $extra_options->description,
                                'unit_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) $extra_options->price : (float) 0,
                                'total_price' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['total_price'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) : (float) 0,
                                'quantity' => (int) $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['quantity'] + $extra_options->quantity,
                                'refund_quantity' => (int) $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['refund_quantity'] + $extra_options->refund_quantity,
                            );
                            //total amounts and refunded amount for diff activation method
                            $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                            $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity) - $type_details->cashier_discount;
                            //total refunded amount at pos level
                            if ($data->payment_method != '10' && $data->payment_method != '19') {
                                $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                            }
                            //total amount and refunded amount for any ticket at pos level
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity))) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'];
                            $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($data->payment_method != 10 && $data->payment_method != 19) ? ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity)) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'];
                        }
                        //Arrange tickets and its types at pos level
                        if (!empty($data->ticket_id)) {
                            $pos_tickets_array[$data->pos_point_id][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'total_amount' => (float) ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount']),
                                'refunded_amount' => (float) ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount']),
                                'title' => $data->ticket_title,
                                'ticket_type_details' => (!empty($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id])) ? $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => (!empty($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id])) ? $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id] : array()
                            );
                        }
                        //Arrange tickets and its types payment wise
                        if (!empty($data->ticket_id)) {
                            $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id] = array(
                                'ticket_id' => (int) $data->ticket_id,
                                'total_amount' => ($data->payment_method != 10 && $data->payment_method != 19) ? round(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount']), 2) : (float) 0,
                                'refunded_amount' => ($data->payment_method != 10 && $data->payment_method != 19) ? (float) ($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount']) : (float) 0,
                                'upsell_ticket' => (int) $data->is_extended_ticket,
                                'title' => $data->ticket_title,
                                'ticket_type_details' => (!empty($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id])) ? $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id] : array(),
                                'per_ticket_extra_options' => (!empty($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id])) ? $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id] : array()
                            );
                        }
                        if ($data->payment_method != '10' && $data->payment_method != '19') {
                            // total amount and discount and service cost at pos level
                            $total_pos_payments[$data->pos_point_id]['amount'] = ($total_pos_payments[$data->pos_point_id]['amount'] + $data->amount - $data->discount_code_amount);
                            $total_pos_payments[$data->pos_point_id]['option_discount'][$data->order_id] += $data->discount_code_amount;
                            $total_pos_payments[$data->pos_point_id]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                            $total_pos_payments[$data->pos_point_id]['combi_discount'] = $total_pos_payments[$data->pos_point_id]['combi_discount'] + $data->total_combi_discount;
                            $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] + $data->total_combi_discount) : $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'];
                        }
                        // total amount and discount and service cost for diff payment methods
                        $total_activation_payment[$data->payment_method . $voucher_category]['amount'] = $total_activation_payment[$data->payment_method . $voucher_category]['amount'] + $data->amount - $data->discount_code_amount;
                        $total_activation_payment[$data->payment_method . $voucher_category]['option_discount'][$data->order_id] = $data->discount_code_amount;
                        $total_activation_payment[$data->payment_method . $voucher_category]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                        $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] = $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] + $data->total_combi_discount;
                        $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] + $data->total_combi_discount) : $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'];
                    }
                }

                //calculate user role of cancelled tickets by cashiers
                //total option discount
                foreach ($total_payments['option_discount'] as $discount) {
                    $total_payments['total_option_discount'] += $discount;
                }
                //total option discount for cancelled orders
                foreach ($total_payments['option_discount_cancelled'] as $discount) {
                    $total_payments['total_option_discount_cancelled'] += $discount;
                }
                //total service cost
                foreach ($total_payments['service_cost'] as $discount) {
                    $total_payments['total_service_cost'] += $discount;
                }
                foreach ($total_payments['service_cost_cancelled'] as $discount) {
                    $total_payments['total_service_cost_cancelled'] += $discount;
                }
                //option discount and sercice cost At pos level
                foreach ($total_pos_payments as $pos_point_id => $pos_discount) {
                    foreach ($pos_discount['option_discount'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_option_discount'] += $discount;
                    }
                    foreach ($pos_discount['option_discount_cancelled'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_option_discount_cancelled'] += $discount;
                    }
                    foreach ($pos_discount['service_cost'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_service_cost'] += $discount;
                    }
                    foreach ($pos_discount['service_cost_cancelled'] as $discount) {
                        $total_pos_payments[$pos_point_id]['total_service_cost_cancelled'] += $discount;
                    }
                }
                //option discount and sercice cost For payment methods
                foreach ($total_activation_payment as $payment_method => $payment_discount) {
                    foreach ($payment_discount['option_discount'] as $discount) {
                        $total_activation_payment[$payment_method]['total_option_discount'] += $discount;
                    }
                    foreach ($payment_discount['option_discount_cancelled'] as $discount) {
                        $total_activation_payment[$payment_method]['total_option_discount_cancelled'] += $discount;
                    }
                    foreach ($payment_discount['service_cost'] as $discount) {
                        $total_activation_payment[$payment_method]['total_service_cost'] += $discount;
                    }
                    foreach ($payment_discount['service_cost_cancelled'] as $discount) {
                        $total_activation_payment[$payment_method]['total_service_cost_cancelled'] += $discount;
                    }
                }

                //find commission and its range at user level and location level from user_targets table.
                if (!empty($tickets_array)) {
                    if (!empty($pos_points_array)) {
                        $user_targets = $this->find('user_targets', array('select' => '*', 'where' => 'is_active = "1" and (user_id = "' . $cashier_id . '" or location_id IN (' . implode(',', array_keys($pos_points_array)) . ')) and start_date <= "' . gmdate("Y-m-d") . '" and end_date >= "' . gmdate("Y-m-d") . '" and day = "' . gmdate("l") . '"'));
                    } else {
                        $user_targets = $this->find('user_targets', array('select' => '*', 'where' => 'is_active = "1" and user_id = "' . $cashier_id . '" and start_date <= "' . gmdate("Y-m-d") . '" and end_date >= "' . gmdate("Y-m-d") . '" and day = "' . gmdate("l") . '"'));
                    }
                    foreach ($user_targets as $target) {
                        if ($target['user_id'] != 0 && $target['location_id'] != 0) {
                            $user_location_level_approach = (!isset($user_location_level_approach)) ? $target['pricing_name'] : $user_location_level_approach;
                            $user_location_level_target[$target['user_id'] . '_' . $target['location_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        } else if ($target['user_id'] != 0) {
                            $user_level_approach = (!isset($user_level_approach)) ? $target['pricing_name'] : $user_level_approach;
                            $user_level_target[$target['user_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        } else if ($target['location_id'] != 0) {
                            $location_level_approach = (!isset($location_level_approach)) ? $target['pricing_name'] : $location_level_approach;
                            $location_level_target[$target['location_id']][] = array(
                                'min_amount' => $target['min_amount'],
                                'max_amount' => $target['max_amount'],
                                'commission' => $target['commission'],
                            );
                        }
                    }
                    $fees = array();
                    //Calculate commission at each pos point
                    foreach ($total_pos_payments as $pos_point_id => $amount) {
                        if (!empty($user_location_level_target[$cashier_id . '_' . $pos_point_id])) {
                            //if commission exist at user level and location level
                            $commission_approach = $user_location_level_approach;
                            $commissions = $user_location_level_target[$cashier_id . '_' . $pos_point_id];
                        } else if (!empty($user_level_target[$cashier_id])) {
                            //if commission exist at user level
                            $commission_approach = $user_level_approach;
                            $commissions = $user_level_target[$cashier_id];
                        } else if (!empty($location_level_target[$pos_point_id])) {
                            //if commission  not exist at user level then check at location level
                            $commission_approach = $location_level_approach;
                            $commissions = $location_level_target[$pos_point_id];
                        } else {
                            $commissions = array();
                        }
                        $total_sale = $amount['amount'] - $amount['refunded_amount'] - ($amount['combi_discount'] - $amount['combi_discount_cancelled']) - ($amount['total_option_discount'] - $amount['total_option_discount_cancelled']) - ($amount['total_service_cost'] - $amount['total_service_cost_cancelled']);
                        $count = count($commissions); // count total slots of a day
                        $i = 0;
                        $tier = 0; // Slot Allocations
                        $flag = 0; // exit the loop if flag => 1
                        $last_max = 0; // last maximum slot value
                        $pending_amount = 0; // diffrence between 2 max slots
                        $total_fee = 0; // total commission of cashier
                        $final_array = array(); // Final array to put in csv
                        if (!empty($commissions)) {
                            foreach ($commissions as $row) {
                                if ($flag == 1) {
                                    break;
                                }
                                $tier = $i + 1;
                                $final_array['Tier'] = 'Tier ' . $tier;
                                $final_array['Minimum Amount'] = $row['min_amount'];
                                $final_array['Maximum Amount'] = $row['max_amount'];
                                if ($total_sale > 0) {
                                    /* if total sale didn't exist in any slot */
                                    if ($i == 0 && $total_sale < $row['min_amount']) {
                                        $commission = 0;
                                        $final_array['commission'] = $commission;
                                        $final_array['sale_amount'] = $total_sale;
                                        $final_array['fees'] = 0;
                                        $flag = 1;
                                    } else {
                                        if ($commission_approach == 1) {//Tier pricing approach
                                            $pending_amount = $row['max_amount'] - $last_max; // get diffrence between 2 max slots
                                            /*  allocate whole pending amount to max slot i.e last slot */
                                            if ($i == $count - 1 || $total_sale <= $pending_amount) {
                                                $flag = 1;
                                            } else {
                                                /*  total amount greater than slot maximum amount */
                                                $total_sale -= $pending_amount;
                                                $final_array['sale_amount'] = $pending_amount;
                                            }
                                            $commission = $row['commission'];
                                            $final_array['commission'] = $commission;
                                            $final_array['fees'] = ($final_array['sale_amount'] * $commission ) / 100;
                                            $final_array['sale_amount'] = $total_sale;
                                        } else {//Linear approach
                                            if ($total_sale <= $row['max_amount'] || ($i == $count - 1)) {
                                                $commission = $row['commission'];
                                                $final_array['commission'] = $commission;
                                                $final_array['sale_amount'] = $total_sale;
                                                $final_array['fees'] = ($final_array['sale_amount'] * $commission ) / 100;
                                                $flag = 1;
                                            } else {
                                                $final_array['commission'] = '-';
                                                $final_array['sale_amount'] = '-';
                                                $final_array['fees'] = 0;
                                            }
                                        }
                                    }
                                }
                                $last_max = $row['max_amount'];
                                $i++;
                                $total_fee += $final_array['fees'];
                                $fees[$pos_point_id] = $total_fee;
                            }
                        }
                    }
                }



                //prepare final array for pos level tickets
                if (!empty($pos_tickets_array)) {
                    foreach ($pos_tickets_array as $pos_key => $array) {
                        foreach ($array as $ticket) {
                            krsort($ticket['ticket_type_details']);
                            $tps_age_sorted_array = array();
                            foreach ($ticket['ticket_type_details'] as $tps_array) {
                                foreach ($tps_array as $type_details) {
                                    sort($type_details['per_age_group_extra_options']);
                                    $tps_age_sorted_array[] = $type_details;
                                }
                            }
                            sort($ticket['per_ticket_extra_options']);
                            $ticket['ticket_type_details'] = $tps_age_sorted_array;
                            $pos_tickets[$pos_key][] = $ticket;
                        }
                        $pos_shift_details[$pos_key] = array(
                            'pos_point_id' => (int) $pos_key,
                            'pos_point_name' => $pos_points_array[$pos_key],
                            'total_amount' => (float) ($total_pos_payments[$pos_key]['amount'] - $total_pos_payments[$pos_key]['refunded_amount']),
                            'commission' => (!empty($fees[$pos_key])) ? (float) round($fees[$pos_key], 2) : (float) 0,
                            'combi_discount' => (float) ($total_pos_payments[$pos_key]['combi_discount'] - $total_pos_payments[$pos_key]['combi_discount_cancelled']),
                            'option_discount' => ($total_pos_payments[$pos_key]['total_option_discount'] > 0) ? (float) ($total_pos_payments[$pos_key]['total_option_discount'] - $total_pos_payments[$pos_key]['total_option_discount_cancelled']) : (float) 0,
                            'service_cost' => ($total_pos_payments[$pos_key]['total_service_cost'] > 0) ? (float) ($total_pos_payments[$pos_key]['total_service_cost'] - $total_pos_payments[$pos_key]['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_pos_payments[$pos_key]['refunded_amount'] > 0) ? (float) ($total_pos_payments[$pos_key]['refunded_amount']) : (float) 0,
                            'tickets' => $pos_tickets[$pos_key]
                        );
                    }
                }
                sort($pos_shift_details);
                //final array for diff paymebnt methods
                if (!empty($payment_tickets_array)) {
                    foreach ($payment_tickets_array as $payment_method => $array) {
                        $payment_values = explode('_', $payment_method);
                        foreach ($array as $ticket) {
                            krsort($ticket['ticket_type_details']);
                            $tps_age_sorted_array = array();
                            foreach ($ticket['ticket_type_details'] as $tps_array) {
                                foreach ($tps_array as $type_details) {
                                    sort($type_details['per_age_group_extra_options']);
                                    $tps_age_sorted_array[] = $type_details;
                                }
                            }
                            sort($ticket['per_ticket_extra_options']);
                            $ticket['ticket_type_details'] = $tps_age_sorted_array;
                            $payment_tickets[$payment_method][] = $ticket;
                        }
                        $categories[$payment_values[0]][$payment_values[1]] = array(
                            'category_id' => (isset($payment_values[1])) ? (int) $payment_values[1] : (int) 0,
                            'category_name' => (isset($payment_values[2])) ? $payment_values[2] : '',
                            'tickets' => $payment_tickets[$payment_method]
                        );
                        // payment_values 10 is for vouchers and 19 is for scan exception case 
                        $payment_shift_details[$payment_values[0]] = array(
                            'payment_method' => (int) $payment_values[0],
                            'total_amount' => ($payment_values[0] != 10 && $payment_values[0] != 19) ? round(($total_activation_payment[$payment_method]['amount'] - $total_activation_payment[$payment_method]['refunded_amount']), 2) : (float) 0,
                            'combi_discount' => ($payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['combi_discount'] - $total_activation_payment[$payment_method]['combi_discount_cancelled']) : (float) 0,
                            'option_discount' => ($total_activation_payment[$payment_method]['total_option_discount'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['total_option_discount'] - $total_activation_payment[$payment_method]['total_option_discount_cancelled']) : (float) 0,
                            'service_cost' => ($total_activation_payment[$payment_method]['total_service_cost'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['total_service_cost'] - $total_activation_payment[$payment_method]['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_activation_payment[$payment_method]['refunded_amount'] > 0 && $payment_values[0] != 10 && $payment_values[0] != 19) ? (float) ($total_activation_payment[$payment_method]['refunded_amount']) : (float) 0,
                            'categories' => $categories[$payment_values[0]]
                        );
                    }
                }
                if (!empty($payment_shift_details)) {
                    if (!empty($payment_shift_details["2"])) {
                        sort($payment_shift_details["2"]['categories']);
                        $payment_shift_details["2"]['categories'] = (array) $payment_shift_details["2"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["2"];
                    }
                    if (!empty($payment_shift_details["1"])) {
                        sort($payment_shift_details["1"]['categories']);
                        $payment_shift_details["1"]['categories'] = (array) $payment_shift_details["1"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["1"];
                    }
                    if (!empty($payment_shift_details["3"])) {
                        sort($payment_shift_details["3"]['categories']);
                        $payment_shift_details["3"]['categories'] = (array) $payment_shift_details["3"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["3"];
                    }
                    if (!empty($payment_shift_details["10"])) {
                        sort($payment_shift_details["10"]['categories']);
                        $payment_shift_details["10"]['categories'] = (array) $payment_shift_details["10"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["10"];
                    }
                    if (!empty($payment_shift_details["19"])) {
                        sort($payment_shift_details["19"]['categories']);
                        $payment_shift_details["19"]['categories'] = (array) $payment_shift_details["19"]['categories'];
                        $payment_ticket_arrays[] = $payment_shift_details["19"];
                    }
                }
                // all tickets
                if (!empty($tickets_array)) {
                    foreach ($tickets_array as $array) {
                        krsort($array['ticket_type_details']);
                        $tps_age_sorted_array = array();
                        foreach ($array['ticket_type_details'] as $tps_array) {
                            foreach ($tps_array as $type_details) {
                                sort($type_details['per_age_group_extra_options']);
                                $tps_age_sorted_array[] = $type_details;
                            }
                        }
                        sort($array['per_ticket_extra_options']);
                        $array['ticket_type_details'] = $tps_age_sorted_array;
                        $all_tickets_array[] = $array;
                        $all_shift_details = array(
                            'total_amount' => (float) ($total_payments['total'] - $total_payments['refunded_amount']),
                            'combi_discount' => (float) ($total_payments['combi_discount'] - $total_payments['combi_discount_cancelled']),
                            'total_option_discount' => ($total_payments['total_option_discount'] > 0) ? (float) ($total_payments['total_option_discount'] - $total_payments['total_option_discount_cancelled']) : (float) 0,
                            'total_service_cost' => ($total_payments['total_service_cost'] > 0) ? (float) ($total_payments['total_service_cost'] - $total_payments['total_service_cost_cancelled']) : (float) 0,
                            'refunded_amount' => ($total_payments['refunded_amount'] > 0) ? (float) ($total_payments['refunded_amount']) : (float) 0,
                            'tickets' => $all_tickets_array
                        );
                    }
                }
            }
            //calculate user role of cancelled tickets by cashiers
            if (!empty($refunded_by_cashiers)) {
                $user_types = $this->find('users', array('select' => 'id, user_role', 'where' => 'id IN (' . implode(',', array_keys($refunded_by_cashiers)) . ')'));
                foreach ($user_types as $user) {
                    $user_role[$user['id']] = $user['user_role'];
                }
                foreach ($refunded_by_cashiers as $cashier_id => $amount) {
                    if ($cashier_id == "1") {
                        $user_role[$cashier_id] = "0";
                    }
                    $refunded_amounts_array[] = array(
                        'cashier_id' => (int) $cashier_id,
                        'user_role' => (int) $user_role[$cashier_id],
                        'cancelled_amount' => (float) $amount
                    );
                }
            }
            if ((!empty($payment_ticket_arrays)) || (!empty($pos_shift_details)) || (!empty($scanned_vouchers)) || (!empty($scan_report_data)) || !empty($refunded_amounts_array)) {
                if (empty($all_shift_details)) {
                    $all_shift_details = array(
                        'total_amount' => (float) 0,
                        'combi_discount' => (float) 0,
                        'total_option_discount' => (float) 0,
                        'total_service_cost' => (float) 0,
                        'refunded_amount' => (float) 0,
                        'tickets' => array()
                    );
                }
                /* send response to app. */
                $response = array(
                    'status' => (int) 1,
                    'total_amount' => ($total_payments['total'] > 0) ? (float) ($total_payments['total'] + $total_payments['option_discount']) : (float) 0,
                    'total_refunded_amount' => ($total_payments['refunded_amount'] > 0) ? (float) ($total_payments['refunded_amount'] - $total_payments['combi_discount_cancelled'] + $total_payments['total_service_cost_cancelled']) : (float) 0,
                    'total_combi_discount' => ($total_payments['combi_discount'] > 0) ? (float) ($total_payments['combi_discount']) : (float) 0,
                    'total_option_discount' => ($total_payments['option_discount'] > 0) ? (float) ($total_payments['option_discount']) : (float) 0,
                    'service_cost' => ($total_payments['total_service_cost'] > 0) ? (float) ($total_payments['total_service_cost']) : (float) 0,
                    'total_combi_discount_cancelled' => ($total_payments['combi_discount_cancelled'] > 0) ? (float) ($total_payments['combi_discount_cancelled']) : (float) 0,
                    'total_option_discount_cancelled' => ($total_payments['option_discount_cancelled'] > 0) ? (float) ($total_payments['option_discount_cancelled']) : (float) 0,
                    'service_cost_cancelled' => ($total_payments['total_service_cost_cancelled'] > 0) ? (float) ($total_payments['total_service_cost_cancelled']) : (float) 0,
                    'shift_details' => (!empty($all_shift_details)) ? $all_shift_details : (object) array(),
                    'payment_level_shift_details' => (!empty($payment_ticket_arrays)) ? (array) $payment_ticket_arrays : array(),
                    'pos_level_shift_details' => (!empty($pos_shift_details)) ? $pos_shift_details : array(),
                    'scanned_citycards_details' => (!empty($scanned_vouchers)) ? $scanned_vouchers : array(),
                    'refunded_amounts_array' => (!empty($refunded_amounts_array)) ? $refunded_amounts_array : array(),
                    'scan_report' => (!empty($scan_report_data)) ? $scan_report_data : array()
                );
            } else {
                $response['status'] = (int) 2;
                $response['errorMessage'] = 'No bookings';
            }
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['sales_report_v4'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /**
     * @Name shift_report_for_redeem
     * @Purpose api for channel wise
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 12 Feb 2018
     */
    function shift_report_for_redeem_v2($data = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $date = $data['date'];
            $start_date = $date . ' 00:00:00';
            $end_date = $date . ' 23:59:59';
            $scan_report_settings = $this->find('qr_codes', array('select' => 'scan_report_settings', 'where' => 'cod_id = "' . $data['supplier_id'] . '"'));
            $scan_settings = unserialize($scan_report_settings[0]['scan_report_settings']);
            //Make array for own sales account
            if ($scan_settings['own_sales_account'] == 'on') {
                foreach ($scan_settings['own_sales_accounts'] as $account) {
                    $own_sales_accounts[$account] = $account;
                }
            } else {
                foreach ($scan_settings['own_sales_accounts'] as $account) {
                    $partner_accounts[$account] = $account;
                }
            }

            if (sizeof($scan_settings['separate_list']) > 0) {
                foreach ($scan_settings['separate_list'] as $key => $account) {
                    if ($key === 'own_sales_accounts') {
                        foreach ($account as $own_acc) {
                            $own_separate_listing[$own_acc] = $own_acc;
                        }
                    } else if ($key === 'resellers') {
                        foreach ($account as $resellers_acc) {
                            $resellers_separate_listing[$resellers_acc] = $resellers_acc;
                        }
                    }
                }
                $resellers_separate = implode(',', array_keys($resellers_separate_listing));
                $own_separate = implode(',', array_keys($own_separate_listing));
                $logs['own_separate_listing'] = array('own_separate' => $own_separate, 'separate' => $resellers_separate);
                if ($resellers_separate != '' && $own_separate != '') {
                    $separate = $resellers_separate . ',' . $own_separate;
                } else if ($own_separate == '' && $resellers_separate != '') {
                    $separate = $resellers_separate;
                } else if ($resellers_separate == '' && $own_separate != '') {
                    $separate = $own_separate;
                }
                if ($separate != '') {
                    $companies = $this->find('qr_codes', array('select' => 'cod_id, company', 'where' => 'cod_id in (' . $separate . ')'));
                    foreach ($companies as $values) {
                        $separate_list[$values['cod_id']] = $values['company'];
                    }
                }
            }
            //Get all resellers
            foreach ($scan_settings['reseller'] as $key => $value) {
                $all_resellers[$key] = $key;
            }
            //Fetch reseller name from resellers table
            if (!empty($all_resellers)) {
                $resellers_list = $this->find('resellers', array('select' => 'reseller_id, reseller_name', 'where' => 'reseller_id IN (' . implode(',', array_keys($all_resellers)) . ')'));
                foreach ($resellers_list as $array) {
                    if ($scan_settings['reseller'][$array['reseller_id']] == 'on') {
                        $resellers[$array['reseller_id']] = array(
                            'reseller_name' => $array['reseller_name']
                        );
                    }
                }
            }

            foreach ($scan_settings['resellers'] as $id => $res) {
                if ($scan_settings['reseller'][$id] == 'on') {
                    foreach ($res as $key => $val) {
                        $resellers[$id]['hotel'][$val] = $val;
                    }
                }
            }
            //fetch data from redeem_cashiers_details table on the basis of shift_id
            if($data['cashier_type'] == '3' && $data['reseller_id'] != '') {
                $where_cond = 'reseller_id = "' . $data['reseller_id'] . '" and cashier_id = "' . $data['reseller_cashier_id'] . '" ';
            } else {
                $where_cond = 'supplier_id = "' . $data['supplier_id'] . '" and cashier_id = "' . $cashier_id . '" ';
            }
            
            $redeem_cashiers_details = $this->find('redeem_cashiers_details', array('select' => 'pass_no,GROUP_CONCAT(prepaid_ticket_id), distributor_id,ticket_id, ticket_title,ticket_type, prepaid_ticket_id,age_group,tps_id,price,distributor_type, distributor_partner_id, distributor_partner_name, channel_type, partner_category_id, partner_category_name', 'where' => $where_cond .'  and shift_id = "' . $shift_id . '" and redeem_time >= "' . $start_date . '" and redeem_time <= "' . $end_date . '" and is_addon_ticket = "0"', 'group_by' => 'ticket_id, distributor_id, tps_id,distributor_partner_id, partner_category_id'));
            $logs['redeem_cashier_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['redeem_cashiers_details_' . date('H:i:s')] = $redeem_cashiers_details;
            if (!empty($redeem_cashiers_details)) {

                if (!empty($redeem_cashiers_details)) {

                    $partners_listing = array('City Expert' => '', 'Agencia Local' => '', 'OTA' => '');
                    foreach ($redeem_cashiers_details as $prepaid_data) {
                        $prepaid_ids = explode(',', $prepaid_data['GROUP_CONCAT(prepaid_ticket_id)']);
                        $prepaid_ids = array_unique($prepaid_ids);
                        $ticket_type = ucfirst(strtolower($prepaid_data['ticket_type']));
                        $ticket_type_data[$prepaid_data['distributor_id'] . '_' . $prepaid_data['distributor_partner_id'] . '_' . $prepaid_data['distributor_partner_name'] . '_' . $prepaid_data['partner_category_id'] . '_' . $prepaid_data['partner_category_name']][$prepaid_data['ticket_id']][$prepaid_data['tps_id']] = array(
                            'tps_id' => $prepaid_data['tps_id'],
                            'age_group' => $prepaid_data['age_group'],
                            'quantity' => count($prepaid_ids),
                            'ticket_type' => (int) (!empty($this->TICKET_TYPES[ucfirst(strtolower($ticket_type))]) && ($this->TICKET_TYPES[ucfirst(strtolower($ticket_type))] > 0)) ? $this->TICKET_TYPES[ucfirst(strtolower($ticket_type))] : 10,
                            'ticket_type_label' => (string) ucfirst(strtolower($ticket_type)),
                            'price' => $ticket_type_data[$prepaid_data['distributor_id'] . '_' . $prepaid_data['distributor_partner_id'] . '_' . $prepaid_data['distributor_partner_name'] . '_' . $prepaid_data['partner_category_id'] . '_' . $prepaid_data['partner_category_name']][$prepaid_data['ticket_id']][$prepaid_data['tps_id']]['price'] + $prepaid_data['price']
                        );
                        $ticket_type_data = $this->common_model->sort_ticket_types($ticket_type_data);
                        $tickets_data[$prepaid_data['distributor_id'] . '_' . $prepaid_data['distributor_partner_id'] . '_' . $prepaid_data['distributor_partner_name'] . '_' . $prepaid_data['partner_category_id'] . '_' . $prepaid_data['partner_category_name']][$prepaid_data['ticket_id']] = array(
                            'ticket_id' => $prepaid_data['ticket_id'],
                            'title' => $prepaid_data['ticket_title'],
                            'ticket_type_details' => (array) $ticket_type_data[$prepaid_data['distributor_id'] . '_' . $prepaid_data['distributor_partner_id'] . '_' . $prepaid_data['distributor_partner_name'] . '_' . $prepaid_data['partner_category_id'] . '_' . $prepaid_data['partner_category_name']][$prepaid_data['ticket_id']]
                        );
                    }
                    foreach ($tickets_data as $key => $row) {
                        $key_vals = explode('_', $key);
                        $hotel_id = $key_vals[0];
                        $partner_id = $key_vals[1];
                        $partner_name = $key_vals[2];
                        $partner_group_id = $key_vals[3];
                        $partner_group_name = $key_vals[4];


                        foreach ($row as $ticket_id => $details) {
                            if (in_array($hotel_id, $own_sales_accounts)) { // own sales account redeems
                                $redeem_prepaid_data['Admin'][$ticket_id]['ticket_id'] = $ticket_id;
                                $redeem_prepaid_data['Admin'][$ticket_id]['title'] = $details['title'];
                                foreach ($details['ticket_type_details'] as $tps_id => $type) {
                                    if (isset($redeem_prepaid_data['Admin'][$ticket_id]['ticket_type_details'][$tps_id])) {
                                        $redeem_prepaid_data['Admin'][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] = $redeem_prepaid_data['Admin'][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] + $type['quantity'];
                                    } else {
                                        $redeem_prepaid_data['Admin'][$ticket_id]['ticket_type_details'][$tps_id] = $type;
                                    }
                                }
                            }
                        }
                    }

                    /* resellers section */
                    foreach ($tickets_data as $key => $row) {
                        $key_vals = explode('_', $key);
                        $hotel_id = $key_vals[0];
                        $partner_id = $key_vals[1];
                        $partner_name = $key_vals[2];
                        $partner_group_id = $key_vals[3];
                        $partner_group_name = $key_vals[4];

                        foreach ($row as $ticket_id => $details) {
                            foreach ($resellers as $array) {
                                if (in_array($hotel_id, array_keys($array['hotel']))) {
                                    $redeem_prepaid_data[$array['reseller_name']][$ticket_id]['ticket_id'] = $ticket_id;
                                    $redeem_prepaid_data[$array['reseller_name']][$ticket_id]['title'] = $details['title'];
                                    foreach ($details['ticket_type_details'] as $tps_id => $type) {
                                        if (isset($redeem_prepaid_data[$array['reseller_name']][$ticket_id]['ticket_type_details'][$tps_id])) {
                                            $redeem_prepaid_data[$array['reseller_name']][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] = $redeem_prepaid_data[$array['reseller_name']][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] + $type['quantity'];
                                        } else {
                                            $redeem_prepaid_data[$array['reseller_name']][$ticket_id]['ticket_type_details'][$tps_id] = $type;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    /* separate list redeems */
                    foreach ($tickets_data as $key => $row) {
                        $key_vals = explode('_', $key);
                        $hotel_id = $key_vals[0];
                        $partner_id = $key_vals[1];
                        $partner_name = $key_vals[2];
                        $partner_group_id = $key_vals[3];
                        $partner_group_name = $key_vals[4];
                        foreach ($row as $ticket_id => $details) {
                            if (in_array($hotel_id, array_keys($separate_list))) {
                                $distributor_name = $separate_list[$hotel_id];
                                $redeem_prepaid_data[$distributor_name][$ticket_id]['ticket_id'] = $ticket_id;
                                $redeem_prepaid_data[$distributor_name][$ticket_id]['title'] = $details['title'];
                                foreach ($details['ticket_type_details'] as $tps_id => $type) {
                                    if (isset($redeem_prepaid_data[$distributor_name][$ticket_id]['ticket_type_details'][$tps_id])) {
                                        $redeem_prepaid_data[$distributor_name][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] = $redeem_prepaid_data[$distributor_name][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] + $type['quantity'];
                                    } else {
                                        $redeem_prepaid_data[$distributor_name][$ticket_id]['ticket_type_details'][$tps_id] = $type;
                                    }
                                }
                            }
                        }
                    }
                    /* partners redeems */
                    $partners = array();
                    foreach ($tickets_data as $key => $row) {
                        $key_vals = explode('_', $key);
                        $hotel_id = $key_vals[0];
                        if (in_array($hotel_id, $own_sales_accounts) || in_array($hotel_id, $own_separate_listing)) {
                            $partner_id = $key_vals[1];
                            $partner_name = $key_vals[2];
                            $partner_group_id = $key_vals[3];
                            $partner_group_name = $key_vals[4];
                            foreach ($row as $ticket_id => $details) {
                                if ($scan_settings['partners'] == 'on' && (in_array($partner_group_id, array_keys($scan_settings['partner']['group'])) || in_array($partner_id, array_keys($scan_settings['partner']['partner_id'])))) { // partner redeems
                                    foreach ($details['ticket_type_details'] as $tps_id => $type) {
                                        if ($partner_group_name == '') {
                                            $name = $partner_name;
                                        } else {
                                            $name = $partner_group_name;
                                        }
                                        $partners[$name][$ticket_id]['ticket_id'] = $ticket_id;
                                        $partners[$name][$ticket_id]['title'] = $details['title'];
                                        if (isset($partners[$name][$ticket_id]['ticket_type_details'][$tps_id])) {
                                            $partners[$name][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] = $partners[$name][$ticket_id]['ticket_type_details'][$tps_id]['quantity'] + $type['quantity'];
                                        } else {
                                            $partners[$name][$ticket_id]['ticket_type_details'][$tps_id] = $type;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    $partners = array_merge($partners_listing, $partners);
                    $redeem_prepaid_data = array_merge($redeem_prepaid_data, $partners);
                    foreach ($redeem_prepaid_data as $key => $data) {
                        foreach ($data as $type => $row) {
                            sort($row['ticket_type_details']);
                            $redeem_ticket_details[$key][] = $row;
                        }
                    }
                    foreach ($redeem_ticket_details as $type => $data) {
                        $redeem_data[] = array(
                            'channel_type' => $type,
                            'ticket_details' => $data
                        );
                    }
                    if (!empty($redeem_data)) {
                        $response = $redeem_data;
                    } else {
                        $response = array();
                    }
                } else {
                    $response = array();
                }
            } else {
                $response = array();
            }
            $logs['shift_report_response'] = $response;
            $MPOS_LOGS['shift_report_for_redeem_v2'] = $logs;
            $internal_logs['shift_report_for_redeem_v2'] = $internal_log;
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['scan_report_data'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
            $logs['scan_report'] = $response;
            return $response;
        }
    }

    /**
     * @Name refund
     * @Purpose refund payment from card in case of cancel tickets
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function refund($merchantAccount = '', $modificationAmount = '', $pspReference = '') {
        $oREC = new \CI_AdyenRecurringPayment(false);
        $oREC->startSOAP();
        return $oREC->refund($merchantAccount, $modificationAmount, $pspReference);
    }

    /**
     * @name : block_ticket_capacity()
     * @purpose : to block capacity
     * @created by : supriya saxena <supriya10.aipl@gmail.com> on 6 Feb, 2020
     */
    function block_ticket_capacity($block_request, $channel_type,$hotel_id) {
        global $MPOS_LOGS;
        $arr_return = array();
        $response = array();
        $MPOS_LOGS['block_ticket_capacity_' . date("H:i:s").'_'.$block_request['booking_id']] = array('block_array' => $block_request, 'channel_type' => $channel_type);
        $booking_id = $block_request['booking_id'];
        $ticket_id = $block_request['ticket_id'];
        $selected_date = $block_request['selected_date'];
        $from_time = $block_request['from_time'];
        $to_time = $block_request['to_time'];
        $quantity = $block_request['quantity'];
        $slot_type = $block_request['slot_type'];
        $shared_capacity_id = $block_request['shared_capacity_id'];
        $own_capacity_id = $block_request['own_capacity_id'];
        $total_capacity = $block_request['total_capacity'];
        $museum_id = $block_request['museum_id'];
        if (!empty($own_capacity_id) && $own_capacity_id > 0) {
            $this->block_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $own_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
            $response = $this->block_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $shared_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
        } else {
            $response = $this->block_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $shared_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
        }
        $arr_return['booking_id'] = $booking_id;
        if ($response['status'] == 1) {
            $arr_return['status'] = $response['status'];
            $arr_return['message'] = $response['message'];
        } else if ($response['status'] == 0) {
            $arr_return['status'] = $response['status'];
            $arr_return['message'] = $response['message'];
            $arr_return['exception'] = $response['exception'];
        }
        return $arr_return;
    }

    /**
     * @Name : blocked_capacity()
     * @Purpose : To update the blocked field with total no. of tickets in ticket_capacity_v1.
     * @Call from : Called from reserve from mpos.php
     * @Receiver params : $ticket_id,$selected_date,$from_time,$to_time,$quantity
     * @Created : komal <komalgarg.intersoft@gmail.com> on 19 May 2017
     * @Modified : supriya saxena <supriya10.aipl@gmail.com> on 6 Feb, 2020
     */
    function block_capacity($booking_id = 0, $ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $shared_capacity_id = 0, $actual_capacity = 0, $slot_type = '', $channel_type = 0, $museum_id = 0, $hotel_id = 0) {
        global $MPOS_LOGS;
        $response = array();
        try {
            $logs['block_capacity_request_'.date("H:i:s")] = array(
                'ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $quantity, 'timeslot_type' => $slot_type, 'shared_capacity_id' => $shared_capacity_id, 'actual_capacity' => $actual_capacity
            );
            $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, sold, blocked, actual_capacity, adjustment_type, adjustment, timeslot, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and date = '$selected_date' and from_time = '$from_time' and to_time = '$to_time'"));
            $logs['ticket_capacity_v1_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
            $logs['sold_data_'.date("H:i:s")] = array('ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'sold' => $sold[0]['sold'], 'blocked' => $sold[0]['blocked'], 'is_active' => $sold[0]['is_active']);
            $total_capacity = (int) $sold[0]['actual_capacity'];
            if ($sold[0]['total'] == '0') {
                $data = array();
                $data['created'] = gmdate('Y-m-d H:i:s');
                $data['ticket_id'] = $ticket_id;
                $data['date'] = $selected_date;
                $data['from_time'] = $from_time;
                $data['to_time'] = $to_time;
                $data['blocked'] = $quantity;
                $data['shared_capacity_id'] = $shared_capacity_id;
                $data['museum_id'] = $museum_id;
                $data['timeslot'] = $slot_type;
                $total_capacity =  $data['actual_capacity'] = $actual_capacity;
                $this->save('ticket_capacity_v1', $data);
                $logs['block ticket_capacity_v1_for_' . $shared_capacity_id.'_'.$booking_id.'_'.date("H:i:s")] = $this->db->last_query();
            } else {
                if ($sold[0]['actual_capacity'] === NULL || $sold[0]['actual_capacity'] == '' || $sold[0]['actual_capacity'] == 0) {
                    $total_capacity = (int) $actual_capacity;
                    $sql = "UPDATE `ticket_capacity_v1` SET `blocked` = blocked + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "', actual_capacity = '" . $actual_capacity . "' WHERE `shared_capacity_id` = '" . $shared_capacity_id . "' and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                } else {
                    $sql = "UPDATE `ticket_capacity_v1` SET `blocked` = blocked + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` = '" . $shared_capacity_id . "' and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                }
                $logs['block ticket_capacity_v1_for_' . $shared_capacity_id.'_'.date("H:i:s")] = $sql;
            }

            $block_capacity_details_array = $this->find('block_capacity_details', array('select' => ' * ', 'where' => 'ticket_reservation_id = "' . $booking_id . '" and shared_capacity_id = "' . $shared_capacity_id . '"'));
            $logs['block_capacity_details_array_'.date("H:i:s")] = $block_capacity_details_array;
            $block_capacity_details = array();
            $current_date = date("Y-m-d H:i:s");
            $block_capacity_details['visitor_group_no'] = '0';
            $block_capacity_details['ticket_reservation_id'] = $booking_id;
            $block_capacity_details['created_at'] = gmdate("Y-m-d H:i:s");
            $block_capacity_details['modified_at'] = gmdate("Y-m-d H:i:s");
            $block_capacity_details['channel_type'] = $channel_type;
            $block_capacity_details['distributor_id'] = $hotel_id;
            $block_capacity_details['museum_id'] = $museum_id;
            $block_capacity_details['ticket_id'] = $ticket_id;
            $block_capacity_details['shared_capacity_id'] = $shared_capacity_id;
            $block_capacity_details['selected_date'] = $selected_date;
            $block_capacity_details['from_time'] = $from_time;
            $block_capacity_details['to_time'] = $to_time;
            $block_capacity_details['timeslote_type'] = $slot_type;
            $block_capacity_details['total_persons'] = $quantity;
            $block_capacity_details['status'] = "1";
            $block_capacity_details['valid_upto'] = date("Y-m-d H:i:s", strtotime($current_date . " +1 hour"));
            if (empty($block_capacity_details_array)) {
                $this->save('block_capacity_details', $block_capacity_details);
                $logs['update block_capacity_details_for_' . $shared_capacity_id.'_'.$booking_id.'_'.date("H:i:s")] = $this->db->last_query();
            } else {
                $query = "UPDATE  block_capacity_details set total_persons = total_persons + " . $quantity . ", selected_date = '" . $selected_date . "', from_time = '" . $from_time . "', to_time = '" . $to_time . "', timeslote_type = '" . $slot_type . "', status = '1', valid_upto = '" . date("Y-m-d H:i:s", strtotime($current_date . " +1 hour")) . "' where ticket_reservation_id = '" . $booking_id . "' and shared_capacity_id = '" . $shared_capacity_id . "' ";
                $this->db->query($query);
                $logs['update block_capacity_details_for_' . $shared_capacity_id.'_'.$booking_id.'_'.date("H:i:s")] = $query;
            }
            
            /* Get availability for particular date range REDIS SERVER */
            $headers = $this->all_headers(array(
                'ticket_id' => $ticket_id,
                'action' => 'block_capacity_from_MPOS',
                'museum_id' => $museum_id,
                'hotel_id' => $hotel_id,
                'channel_type' => $channel_type
            ));
            $MPOS_LOGS['DB'][] = 'CACHE';
            /* Update Block capacity for a particular date time of a ticket */
            $this->curl->requestASYNC('CACHE', '/blockcapacity', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => array("shared_capacity_id" => $shared_capacity_id, "date" => $selected_date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => -$quantity)
            ));

            /* Firebase Updations */
            /* SYNC firebase if target point reached */
            if ($sold[0]['adjustment_type'] == '1') {
                $total_capacity = $total_capacity + $sold[0]['adjustment'];
            } else {
                $total_capacity = $total_capacity - $sold[0]['adjustment'];
            }
            $is_active = isset($sold[0]['is_active']) &&  $sold[0]['is_active'] != NULL ? $sold[0]['is_active'] : true;
            $selected_date = date('Y-m-d', strtotime($selected_date));
            $id = str_replace("-", "", $selected_date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
            $update_values = array(
                'slot_id' => (string) $id,
                'from_time' => $from_time,
                'to_time' => $to_time,
                'type' => $slot_type,
                'is_active' => ($is_active == 1) ? true : false,
                'bookings' => (int) ($sold[0]['sold']),
                'total_capacity' => (int) $total_capacity,
                'blocked' => (int) ($sold[0]['blocked'] + $quantity),
            );

            $params = array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => array("node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $to_time, 'details' => $update_values)
            );
            $logs['params_on_firebase_for_block_'.date("H:i:s")] = $params['body'];
            $MPOS_LOGS['DB'][] = 'FIREBASE';
            $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', $params);
            $MPOS_LOGS['block_capacity']['update_block_capacity_for_' . $shared_capacity_id.'_'.$booking_id] = $logs;            
            $response['status'] = (int) 1; 
            $response['message'] = "success";
            return $response;
        } catch (\Exception $e) {
            $logs['exception_'.date("H:i:s")] = $e->getMessage();
            $MPOS_LOGS['update_capacity']['update_block_capacity_for_' . $shared_capacity_id.'_'.$booking_id] = $logs;
            $response['exception'] = $logs['exception'];
            $response['message'] = "error";
            $response['status'] = (int) 0;
            return $response;
        }
    }

    /**
     * @name : release_ticket_capacity()
     * @purpose : to block capacity
     * @created by : supriya saxena <supriya10.aipl@gmail.com> on 6 Feb, 2020
     */
    function release_ticket_capacity($release_request, $channel_type, $hotel_id) {
        global $MPOS_LOGS;
        $arr_return = array();
        $response = array();
        $MPOS_LOGS['release_ticket_capacity' . date("H:i:s").'_'.$release_request['booking_id']] = array('release_array' => $release_request, 'channel_type' => $channel_type);
        $booking_id = $release_request['booking_id'];
        $ticket_id = $release_request['ticket_id'];
        $selected_date = $release_request['selected_date'];
        $from_time = $release_request['from_time'];
        $to_time = $release_request['to_time'];
        $quantity = $release_request['quantity'];
        $slot_type = $release_request['slot_type'];
        $shared_capacity_id = $release_request['shared_capacity_id'];
        $own_capacity_id = $release_request['own_capacity_id'];
        $total_capacity = $release_request['total_capacity'];
        $museum_id = $release_request['museum_id'];
        if (!empty($own_capacity_id) &&  $own_capacity_id > 0) {
           $this->release_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $own_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
            $response = $this->release_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $shared_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
        } else {
            $response = $this->release_capacity($booking_id, $ticket_id, $selected_date, $from_time, $to_time, $quantity, $shared_capacity_id, $total_capacity, $slot_type, $channel_type, $museum_id, $hotel_id);
        }
        $arr_return['booking_id'] = $booking_id;
        if ($response['status'] == 1) {
            $arr_return['status'] = $response['status'];
            $arr_return['message'] = $response['message'];
        } else if ($response['status'] == 0) {
            $arr_return['status'] = $response['status'];
            $arr_return['message'] = $response['message'];
            $arr_return['exception'] = $response['exception'];
        }
        return $arr_return;
    }

    /**
     * @Name : release_capacity()
     * @Purpose : To release capacity on delete
     * @Call from : Called from cancel_v1 from getyourguide_model.php
     * @Receiver params : $ticket_id,$selected_date,$from_time,$to_time,$quantity
     * @Created : komal <komalgarg.intersoft@gmail.com> on 24 May 2017
     * @Modified : supriya saxena <supriya10.aipl@gmail.com> on 6 Feb, 2020
     */
    function release_capacity($booking_id = 0, $ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $shared_capacity_id = 0, $actual_capacity = 0, $slot_type = '', $channel_type = 0, $museum_id = 0, $hotel_id = 0) {
        global $MPOS_LOGS;
        $response = array();
        $current_date = date("Y-m-d H:i:s");
        try {
            $logs['release_capacity_data_'.date("H:i:s")] = array(
                'ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $quantity, 'timeslot_type' => $slot_type, 'shared_capacity_id' => $shared_capacity_id, 'actual_capacity' => $actual_capacity
            );
            $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, sold, blocked, actual_capacity, is_active, adjustment, adjustment_type', 'where' => "shared_capacity_id = '$shared_capacity_id' and date = '$selected_date' and from_time = '$from_time' and to_time = '$to_time'"));
            $logs['ticket_capacity_v1_query'] = $this->primarydb->db->last_query();
            $logs['sold_data_'.date("H:i:s")] = array('ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'sold' => $sold[0]['sold'], 'blocked' => $sold[0]['blocked']);
            $total_capacity = (int) $sold[0]['actual_capacity'];
            $block_capacity_details_array = $this->find('block_capacity_details', array('select' => ' * ', 'where' => "ticket_reservation_id = '" . $booking_id . "' and shared_capacity_id = '" . $shared_capacity_id . "'"));
            $logs['block_capacity_details_array_'.$booking_id] = $block_capacity_details_array;
            if(!empty($block_capacity_details_array)) {
                if ($sold[0]['actual_capacity'] === NULL || $sold[0]['actual_capacity'] == '' || $sold[0]['actual_capacity'] == 0) {
                    $total_capacity = (int) $actual_capacity;
                    $sql = "UPDATE `ticket_capacity_v1` SET `blocked` = blocked - " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "', actual_capacity = " . $actual_capacity . " WHERE `shared_capacity_id` = '" . $shared_capacity_id . "' and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                } else {
                    $sql = "UPDATE `ticket_capacity_v1` SET `blocked` = blocked - " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` = '" . $shared_capacity_id . "' and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                }
                $logs['update ticket_capacity_v1_for_' . $shared_capacity_id . '_' . date("H:i:s")] = $sql;
                $blocked_quantity = $block_capacity_details_array[0]['total_persons'] - $quantity;
                if ($blocked_quantity > 0) {
                    $query = "UPDATE  block_capacity_details set total_persons = " . $blocked_quantity . ", status = '1', valid_upto = '" . date("Y-m-d H:i:s", strtotime($current_date . " +1 hour")) . "' where ticket_reservation_id = '" . $booking_id . "' and shared_capacity_id = '" . $shared_capacity_id . "'";
                    $this->db->query($query);
                } else {
                    $query = "UPDATE  block_capacity_details set  total_persons = " . $blocked_quantity . ", status = '2'  where ticket_reservation_id = '" . $booking_id . "' and shared_capacity_id = '" . $shared_capacity_id . "'";
                    $this->db->query($query);
                }

                $logs['update_block_capacity_details_for_' . $shared_capacity_id . '_' . $booking_id . '_' . date("H:i:s")] = $query;
                /* Get availability for particular date range REDIS SERVER */
                $headers = $this->all_headers(array(
                    'ticket_id' => $ticket_id,
                    'action' => 'release_capacity_from_MPOS',
                    'museum_id' => $museum_id,
                    'hotel_id' => $hotel_id,
                    'channel_type' => $channel_type
                ));
                $MPOS_LOGS['DB'][] = 'CACHE';
                $this->curl->requestASYNC('CACHE', '/blockcapacity', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("shared_capacity_id" => $shared_capacity_id, "date" => $selected_date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => +$quantity)
                ));
                /* Firebase Updations */
                /* SYNC firebase if target point reached */
                if ($sold[0]['adjustment_type'] == '1') {
                    $total_capacity = $total_capacity + $sold[0]['adjustment'];
                } else {
                    $total_capacity = $total_capacity - $sold[0]['adjustment'];
                }
                $is_active = isset($sold[0]['is_active']) && $sold[0]['is_active'] != NULL ? $sold[0]['is_active'] : true;
                $selected_date = date('Y-m-d', strtotime($selected_date));
                $id = str_replace("-", "", $selected_date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
                $update_values = array(
                    'slot_id' => (string) $id,
                    'from_time' => $from_time,
                    'to_time' => $to_time,
                    'type' => $slot_type,
                    'is_active' => ($is_active == 1) ? true : false,
                    'bookings' => (int) ($sold[0]['sold']),
                    'total_capacity' => (int) $total_capacity,
                    'blocked' => (int) ($sold[0]['blocked'] - $quantity),
                );
                $params = array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $to_time, 'details' => $update_values)
                );
                $logs['params_on_firebase_for_release_' . date("H:i:s")] = $params['body'];
                
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', $params);
                $MPOS_LOGS['release_capacity']['update_release_capacity_for_' . $shared_capacity_id . '_' . $booking_id] = $logs;
                $response['status'] = (int) 1;
                $response['message'] = "success";
                return $response;
            } else {
                $response['status'] = (int) 0;
                $response['message'] = "No block request found";                
                $response['exception'] = "INVALID REQUEST";
                return $response;
            }
            
        } catch (\Exception $e) {
            $logs['exception_'.date("H:i:s")] = $e->getMessage();
            $MPOS_LOGS['update_capacity']['update_release_capacity_for_' . $shared_capacity_id.'_'.$booking_id] = $logs;
            $response['exception'] = $logs['exception'];
            $response['status'] = (int) 0;
            return $response;
        }
        return true;
    }

    /**
     * @Name : release_blocked_capacity()
     * @Purpose : To release blocked capacity.
     * @Call from : Cron
     * @Created : Jatinder Kumar <jatinder.aipl@gmail.com> on 25 Feb 2020
    */
    /*function release_blocked_capacity() {    
        global $MPOS_LOGS;
        $MPOS_LOGS['API'] = "release_blocked_capacity";
        $dateTime = date('Y-m-d H:i:s', time() - 3600);
        $get_blocked_capacity = $this->query('select * from block_capacity_details where status="1" and modified_at <= "'.$dateTime.'"', 1);
        $MPOS_LOGS['blocked_capacity_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
        $MPOS_LOGS['blocked_capacity_data_' . date('H:i:s')] = $get_blocked_capacity;
        if(!empty($get_blocked_capacity)) {
            
            $blockedIds = array_column($get_blocked_capacity, 'block_capacity_details_id');
            
            foreach($get_blocked_capacity as $val) {
                
                $array = array("booking_id" => $val['ticket_reservation_id'], 
                              "ticket_id" => $val['ticket_id'], 
                              "selected_date" => $val['selected_date'], 
                              "from_time" => $val['from_time'], 
                              "to_time" => $val['to_time'], 
                              "quantity" => $val['total_persons'], 
                              "slot_type" => $val['timeslote_type'], 
                              "shared_capacity_id" => $val['shared_capacity_id'], 
                              "own_capacity_id" => '0', 
                              "total_capacity" => 0, 
                              "museum_id" => $val['museum_id']);
                $MPOS_LOGS['blocked_capacity_request_data_' . date('H:i:s')] = $array;
                
                $this->release_ticket_capacity($array, $val['channel_type'], $val['distributor_id']);
            }
        }
        
        if(!empty($blockedIds)) {
            
            $db = $this->primarydb->db;
            $sql = 'UPDATE block_capacity_details SET status="2" WHERE block_capacity_details_id IN ("'. implode('","', $blockedIds) .'")';
            $db->query($sql);
            $MPOS_LOGS['blocked_capacity_update_query_' . date('H:i:s')] = $db->last_query();
        }
        
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
    }*/
    
    function sync_third_party_order() {
        $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
        $_REQUEST = json_decode($jsonStr, TRUE);
        $ticket_types = [
            'Adult'         => '1',
            'Baby'          =>  '2',
            'Child'         =>  '3',
            'Elderly'       =>  '4',
            'Handicapt'     =>  '5',
            'Student'       =>  '6',
            'Military'      =>  '7',
            'Youth'         =>  '8',
            'Senior'        =>  '9',
            'Group'         =>  '10',
            'Family'        =>  '11',
            'Resident'      =>  '12',
            'Infant'        =>'13'
        ];
        $vgn = $_REQUEST['order_reference'];
        $vgn_details = $this->common_model->find('prepaid_tickets', array('select' => 'ticket_id, shared_capacity_id, from_time, to_time, timeslot, selected_date, activation_method, visitor_group_no, tps_id, price, net_price, timezone, museum_id, museum_name, shift_id, pos_point_id, pos_point_name, extra_booking_information ', 'where' => 'visitor_group_no = "'.$vgn.'"'), 'array');                            
        if(!empty($vgn_details)) {
            foreach($vgn_details as $vgn_detail) {
                $new_details[$vgn_detail['ticket_id']] = $vgn_detail;
                $ticket_ids[] = $vgn_detail['ticket_id'];
                $vgn_array[$vgn_detail['visitor_group_no']] = $vgn_detail;
                $product_type_details[$vgn_detail['ticket_id'].'_'.$vgn_detail['tps_id']] = $vgn_detail;
            }
        }
        if(!empty($ticket_ids)) {
            $tickets = $this->common_model->find('modeventcontent', array('select' => 'mec_id, is_sell_via_ota, code_vs_voucher, voucher_type, capacity_type, product_type', 'where' => 'mec_id IN("'.implode('","', $ticket_ids).'") and deleted = "0"'), 'array');                            
            if(!empty($tickets)) {
                foreach($tickets as $ticket) {
                    $new_tickets[$ticket['mec_id']] =  $ticket;
                }
            }
        }

        foreach($_REQUEST['order_bookings'] as $booking_and_product_details) {
            $from_time = $this->get_time_in_H_i($booking_and_product_details['product_availability_from_date_time']);
            $to_time = $this->get_time_in_H_i($booking_and_product_details['product_availability_to_date_time']);
            $selected_date = $this->get_date($booking_and_product_details['booking_travel_date']);
            $booking_date = gmdate("Y-m-d H:i:s", strtotime($_REQUEST['order_created']));
               #region start - prepare combi details : subtickets_details array
            $subtickets = array();
            if(isset($booking_and_product_details['product_combi_details']) && sizeof($booking_and_product_details['product_combi_details']) > 0) {
                foreach($booking_and_product_details['product_combi_details'] as $combi_subticket) {
                    if($booking_and_product_details['product_id'] == $combi_subticket['product_parent_id']) {
                        $subtickets[$combi_subticket['product_id']]['capacity_type'] = $new_tickets[$combi_subticket['product_id']]['capacity_type']; //==
                        $subtickets[$combi_subticket['product_id']]['from_time'] = $new_details[$combi_subticket['product_id']]['from_time'] == "0" ? "" : $new_details[$combi_subticket['product_id']]['from_time'];
                        $subtickets[$combi_subticket['product_id']]['guest_notification'] = '';
                        $subtickets[$combi_subticket['product_id']]['pass_type'] =  (strpos($combi_subticket['product_code_settings']['product_code_format'], 'BAR_CODE') !== false) ? (string) 2 :  (string) 1; // 2 for barcode, res al will be considered as QR_CODES
                        $subtickets[$combi_subticket['product_id']]['selected_date'] = $this->get_date($combi_subticket['booking_travel_date']);;
                        $subtickets[$combi_subticket['product_id']]['slot_type'] =  $new_details[$combi_subticket['product_id']]['timeslot'];
                        $subtickets[$combi_subticket['product_id']]['supplier_name'] = $combi_subticket['product_supplier_name'];
                        $subtickets[$combi_subticket['product_id']]['ticket_id'] = $combi_subticket['product_id'];
                        $subtickets[$combi_subticket['product_id']]['ticket_title'] = $combi_subticket['product_title'];
                        $subtickets[$combi_subticket['product_id']]['to_time'] =  $new_details[$combi_subticket['product_id']]['to_time'] == "0" ? "" : $new_details[$combi_subticket['product_id']]['to_time'];
                        foreach ($combi_subticket['product_type_details'] as $type_details) {
                            $subtickets[$combi_subticket['product_id']]['type_details'][$type_details['product_type_id']]['age_range'] = $type_details['product_type_age_from'].'-'.$type_details['product_type_age_to'];
                            $subtickets[$combi_subticket['product_id']]['type_details'][$type_details['product_type_id']]['passes'][] = $type_details['product_type_code'];
                            $subtickets[$combi_subticket['product_id']]['type_details'][$type_details['product_type_id']]['ticket_type'] = $ticket_types[$type_details['product_type_label']];
                            $subtickets[$combi_subticket['product_id']]['type_details'][$type_details['product_type_id']]['ticket_type_label'] = $type_details['product_type_label'];
                            $subtickets[$combi_subticket['product_id']]['type_details'][$type_details['product_type_id']]['tps_id'] = $type_details['product_type_id'];
                        }
                    }
                }
            }
            #region end - prepare combi details : subtickets_details array

            #region start - check pass_allocation_type

                if(!isset($new_tickets[$booking_and_product_details['product_id']])) {
                    $pass_allocation_type = "0";
                } else {
                    if ($new_tickets[$booking_and_product_details['product_id']]['is_sell_via_ota'] == '2') {
                        if ($new_tickets[$booking_and_product_details['product_id']]['code_vs_voucher'] == '1') {
                            $pass_allocation_type = "1";
                        } else if ($new_tickets[$booking_and_product_details['product_id']]['code_vs_voucher'] == '2') {
                            $pass_allocation_type = "2";
                        }
                    } else if ($new_tickets[$booking_and_product_details['product_id']]['is_sell_via_ota'] == '1') {
                        if ($new_tickets[$booking_and_product_details['product_id']]['voucher_type'] == '2') {
                            $pass_allocation_type = "3";
                        } else if ($new_tickets[$booking_and_product_details['product_id']]['voucher_type'] == '3') {
                            $pass_allocation_type = "4";
                        } else if ($new_tickets[$booking_and_product_details['product_id']]['voucher_type'] == '1') {
                            $pass_allocation_type = "5";
                        } else if ($new_tickets[$booking_and_product_details['product_id']]['voucher_type'] == '4') {
                            $pass_allocation_type = "6";
                        } else if ($new_tickets[$booking_and_product_details['product_id']]['voucher_type'] == '5') {
                            $pass_allocation_type = "7";
                        } 
                    } else if ($new_tickets[$booking_and_product_details['product_id']]['is_sell_via_ota'] == '0') {
                        $pass_allocation_type = "0";
                    }
                }
            #region end - check pass_allocation_type
            $sync_key = base64_encode($_REQUEST['order_reference'] . '_' . $booking_and_product_details['product_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $booking_date);
            #region start - get types details
                foreach ($booking_and_product_details['product_type_details'] as $type) {
                    $taxes = $this->common_model->find('store_taxes', array('select' => 'tax_value', 'where' => 'id = "' . $type['product_type_pricing']['price_taxes'][0]['tax_id'] . '"'), 'row_array');
                    $ticket_types[$sync_key][$type['product_type_id']] = array(
                        'tps_id' => (int) $type['product_type_id'],
                        'age_group' => $type['product_type_age_from'].'-'.$type['product_type_age_to'],
                        'price' => (float) $type['product_type_pricing']['price_total'],
                        'net_price' => (float) $product_type_details[$booking_and_product_details['product_id'].'_'. $type['product_type_id']]['net_price'],
                        'tax' => (float) $taxes['tax_value'],
                        'type' => $type['product_type_label'],
                        'cashier_discount' => 0,
                        'cashier_discount_quantity' => 0,
                        'quantity' => isset($ticket_types[$sync_key][$type['product_type_id']]['quantity']) ? (int)$ticket_types[$sync_key][$type['product_type_id']]['quantity'] + 1 : 1,
                        'combi_discount_gross_amount' => 0,
                        'refund_quantity' => (int) 0,
                        'refunded_by' => array(),
                        'per_age_group_extra_options' => array(),
                        'passes' => array($type['product_type_code']),
                    );
                    $passes[] =  $type['product_type_code'];

                    //prepare array to sync on order list node
                    $order_details['booking_date_time'] = (string) $booking_date;
                    $order_details['booking_email'] = (string)  $_REQUEST['order_contacts'][0]['contact_email'];
                    $order_details['booking_name'] = (string)  $_REQUEST['order_contacts'][0]['contact_name_first']." " . $_REQUEST['order_contacts'][0]['contact_name_last'];
                    $order_details['note'] = '';
                    $order_details['payment_method'] = (int) $vgn_array[$_REQUEST['order_reference']]['activation_method'];
                    $order_details['status'] = (int) 2;
                    $order_details['tickets'][$booking_and_product_details['product_id']]['museum_id'] = (int) $vgn_array[$_REQUEST['order_reference']]['museum_id'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['museum_name'] = (string) $vgn_array[$_REQUEST['order_reference']]['museum_name'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['title'] = (string)  $booking_and_product_details['product_title'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['ticket_id'] = (int) $booking_and_product_details['product_id'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['types'][$type['product_type_id']]['tps_id'] = (int) $type['product_type_id'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['types'][$type['product_type_id']]['age_group'] = (string)  $type['product_type_age_from'].'-'.$type['product_type_age_to'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['types'][$type['product_type_id']]['quantity'] += (int)  $type['product_type_count'];
                    $order_details['tickets'][$booking_and_product_details['product_id']]['types'][$type['product_type_id']]['type'] = (string) $type['product_type_label'];
                }
            #region end - get types details

            #region start - prepare array to sync on firebase
                $pos_point_id = 0;
                $pos_point_name = '';
                $extra_booking_information =  json_decode($vgn_array[$_REQUEST['order_reference']]['extra_booking_information'], true);
                if(isset($extra_booking_information['order_custom_fields']) && !empty($extra_booking_information['order_custom_fields'])) {
                    foreach($extra_booking_information['order_custom_fields'] as $order_custom_fields) {
                      
                        if($order_custom_fields['custom_field_name'] == 'pos_point_name') {
                            $pos_point_name = $order_custom_fields['custom_field_value'];
                        }
                        if($order_custom_fields['custom_field_name'] == 'pos_point_id') {
                            $pos_point_id = $order_custom_fields['custom_field_value'];
                        }
                    }
                }
                $bookings[$sync_key]['activated_by'] = 0;
                $bookings[$sync_key]['activated_at'] = 0;
                $bookings[$sync_key]['amount'] = (float) $_REQUEST['order_pricing']['price_total'];
                $bookings[$sync_key]['booking_date_time'] = $booking_date;
                $bookings[$sync_key]['booking_name'] = $_REQUEST['order_contacts'][0]['contact_name_first']." " . $_REQUEST['order_contacts'][0]['contact_name_last'];
                $bookings[$sync_key]['cancelled_tickets'] = 0;
                $bookings[$sync_key]['cashier_name'] = $_REQUEST['order_created_name'];
                $bookings[$sync_key]['channel_type'] = (int) $_REQUEST['channel_type'];
                $bookings[$sync_key]['change'] = (int) 0;
                $bookings[$sync_key]['from_time'] = ($booking_and_product_details['product_availability_from_date_time'] != '') ? $from_time : "0";
                $bookings[$sync_key]['group_id'] = 0;
                $bookings[$sync_key]['group_name'] = '';
                $bookings[$sync_key]['guest_notification'] = "";
                $bookings[$sync_key]['is_combi_ticket'] = ($booking_and_product_details['product_code_settings']['product_voucher_settings'] == "SINGLE") ? 0 : 1;
                $bookings[$sync_key]['is_extended_ticket'] = (int) 0;
                $bookings[$sync_key]['is_reservation'] = ($booking_and_product_details['product_availability_id'] != '') ? 1 : 0;
                $bookings[$sync_key]['merchant_reference'] = isset($_REQUEST['adyen_details']['merchantReference']) ? $_REQUEST['adyen_details']['merchantReference'] : ""; //==
                $bookings[$sync_key]['museum'] = $booking_and_product_details['product_supplier_name'];
                $bookings[$sync_key]['order_id'] = $_REQUEST['order_reference'];
                $bookings[$sync_key]['pass_allocation_type'] = (isset($pass_allocation_type) && isset($booking_and_product_details['product_combi_details']) && sizeof($booking_and_product_details['product_combi_details']) > 0) ? (int) $pass_allocation_type : (int) 0;
                $bookings[$sync_key]['pass_type'] = (strpos($booking_and_product_details['product_code_settings']['product_code_format'], 'BAR_CODE') !== false) ? 2 : 1; // 2 for barcode, res al will be considered as QR_CODES
                $bookings[$sync_key]['passes'] = implode(",", array_unique($passes));
                $bookings[$sync_key]['payment_method'] = (int) $vgn_array[$_REQUEST['order_reference']]['activation_method'];
                $bookings[$sync_key]['pos_point_id'] = $pos_point_id; 
                $bookings[$sync_key]['pos_point_name'] = $pos_point_name;
                $bookings[$sync_key]['product_type'] = (int) $new_tickets[$booking_and_product_details['product_id']]['product_type'];
                $bookings[$sync_key]['quantity'] = sizeof($booking_and_product_details['product_type_details']);
                $bookings[$sync_key]['reservation_date'] = ($booking_and_product_details['booking_travel_date'] != '') ? $selected_date : "";
                $bookings[$sync_key]['service_cost_amount'] = (float) 0;
                $bookings[$sync_key]['service_cost_type'] = 0;
                $bookings[$sync_key]['shift_id'] = isset($vgn_array[$_REQUEST['order_reference']]['shift_id']) ? $vgn_array[$_REQUEST['order_reference']]['shift_id'] : 0;
                $bookings[$sync_key]['slot_type'] = $new_details[$booking_and_product_details['product_id']]['timeslot']; //==
                $bookings[$sync_key]['status'] = 2;
                $bookings[$sync_key]['ticket_id'] = (int) $booking_and_product_details['product_id'];
                $bookings[$sync_key]['ticket_title'] = $booking_and_product_details['product_title'];
                $bookings[$sync_key]['timezone'] = (int) $new_details[$booking_and_product_details['product_id']]['timezone'];
                $bookings[$sync_key]['to_time'] = ($booking_and_product_details['product_availability_to_date_time'] != '') ? $to_time : "0";
                $bookings[$sync_key]['voucher_exception_code'] = '';      
                $bookings[$sync_key]['is_discount_code'] = 0;
                $bookings[$sync_key]['discount_code_type'] = 0;
                $bookings[$sync_key]['total_combi_discount'] = 0;
                $bookings[$sync_key]['discount_code_amount'] = (float) 0;           
                $bookings[$sync_key]['ticket_types'] = (!empty($ticket_types[$sync_key])) ? $ticket_types[$sync_key] : array();
                $bookings[$sync_key]['subticket_details'] = !empty($subtickets) ? $subtickets : array();
            #region end - prepare array to sync on firebase
        }
        #region start - sync on firebase
            $body= array(
                    "node" => 'distributor/bookings_list/' . $_REQUEST['order_distributor_id'] . '/' . $_REQUEST['user_id'] . '/' . date("Y-m-d", strtotime($booking_date)),
                    'details' => $bookings
                );

            $headers = array('Content-Type: application/json', 'Authorization: '. (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $get_data = curl_exec($ch);
            curl_close($ch);
             
             //sync orders in orders list
            
            $order_list_details = array(
                "node" => 'distributor/orders_list/' . $_REQUEST['order_distributor_id'] . '/' . $_REQUEST['user_id'] . '/' . date("Y-m-d", strtotime($booking_date)) .'/'. $_REQUEST['order_reference'],
                'details' => $order_details
            );

            $headers = array('Content-Type: application/json', 'Authorization: '. (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_list_details));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
            curl_close($ch);
            return json_decode($get_data, true);

            // echo json_encode($body);
        #region start - sync on firebase
    }


    /* #endregion Order Process  Module : This covers cancel proces api's */
}

 ?>