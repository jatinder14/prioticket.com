<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait THost_App_Model {
     /*
    *To load commonaly used files in all functions
    */
    function __construct() 
    {
        parent::__construct();
    }

    /* #region Host App Module : Covers all the api's used in host app */

    /**
     * @Name search
     * @Purpose used to search all records from PT DB2 corresponding to search value 
     * in first case (show_orders => 0), we will return only guests details related to searched value
     * in another case (show_orders => 1), we will return particular (tapped) guest details with all orders of this particular user
     * @return status and data 
     *      status 1 or 0
     *      data having guest details or guest details with order details of a user
     * @param 
     *      $req - array() - API request (same as from APP) + user_details + hidden_tickets (of sup user)
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    
    function search($req = array()) 
    {
        ini_set('memory_limit', '-1');
        $secondarydb = $this->load->database('secondary', true); //db2 connection only for this API
        global $MPOS_LOGS;
        global $internal_logs;
        $app_flavour = $req['app_flavour'];
        $search_key = $req['search_key'];
        $search_value = $req['search_value'];
        $museum_id = $req['supplier_id'];
        $email_id = strtolower(trim($req['email_id']));
        $name = strtolower(trim($req['name']));
        $passport_no = isset($req['passport_no']) ? $req['passport_no'] : '';
        $show_orders = $req['show_orders'];
        $device_current_time = (int) ($req['current_time'] / 1000);
        $timezone = $req['time_zone'];
        $allow_activation = $req['allow_activation']; // 1 for activation user, 0 if scan user
        $logs['hidden_tickets'] = $req['hidden_tickets'];
        $pt_checks = '';
        //search from PT
        switch ($search_key) {
            CASE 'pass_no':
                if (strpos($search_value, 'https://qu.mu') !== false) { //city card scanned
                    $pt_checks = 'bleep_pass_no = "' . str_replace("https://qu.mu/", "", $search_value) . '" and ';
                } else {
                    $pt_checks = 'passNo = "' . $search_value . '" and ';
                }
                break;
            CASE 'visitor_group_no':
                $pt_checks = 'visitor_group_no = "' . $search_value . '" and ';
                break;
            CASE 'reference':
                $pt_checks = 'without_elo_reference_no = "' . $search_value . '" and ';
                break;
            CASE 'passport_number':
                $pt_checks = 'passport_number = "' . $search_value . '" and ';
                break;
            CASE 'contact_number':
                $pt_checks = 'phone_number = "' . $search_value . '" and ';
                break;
            CASE 'email_id':
                $pt_checks = 'secondary_guest_email = "' . strtolower(trim($search_value)) . '" and ';
                break;
            CASE 'name':
                $pt_checks = 'secondary_guest_name like "' . trim($search_value) . '%" and ';
                break;
            DEFAULT :
                break;
        }
        
        if ($pt_checks != '') {
            $query = 'select reserved_1, ticket_type, created_date_time, timezone, passNo, museum_name, is_combi_ticket, visitor_group_no, is_refunded, shared_capacity_id, valid_till, timeslot, '
                    . ' ticket_id, tps_id, selected_date, from_time, to_time, timeslot, title, without_elo_reference_no, activation_method, prepaid_ticket_id, used, payment_conditions, channel_type, '
                    . ' bleep_pass_no, museum_cashier_id, museum_cashier_name, scanned_at, redeem_date_time, distributor_partner_name, distributor_partner_id, cashier_name, cashier_id, capacity, '
                    . ' price, secondary_guest_email, secondary_guest_name, phone_number, passport_number, extra_booking_information, guest_names, guest_emails, tps_id, tp_payment_method, '
                    . ' third_party_type, third_party_response_data, museum_name, museum_id, booking_status, is_addon_ticket, related_product_id from prepaid_tickets '
                    . ' where ';
            if ($app_flavour != "CNTK") {
                $query .= ' museum_id = "' . $museum_id . '" and ';
            }
            $query .= $pt_checks . ' is_prepaid = "1" and deleted = "0" and is_refunded != "1"  ORDER BY is_addon_ticket DESC';


            $pt_actual_data = $secondarydb->query($query)->result_array();
            $pt_actual_data = $this->get_max_version_data($pt_actual_data, 'prepaid_ticket_id');
        }
        $logs['query_fom_pt_db2'] = $query;
        $update_form_extra_booking_info = 0;
        foreach($pt_actual_data as $pt_values) {
            if($pt_values['reserved_1'] == '' || $pt_values['reserved_1'] == NULL || $pt_values['reserved_1'] == 0) {
                $update_form_extra_booking_info = 1;
            }
        }
        $logs['update_form_extra_booking_info'] = $update_form_extra_booking_info;
        $contact_uids = array_unique(array_column($pt_actual_data, 'reserved_1'));
        if ($update_form_extra_booking_info != 1) {
            $contacts_query = 'select * from guest_contacts '
            . ' where contact_uid in ("' . implode('","', $contact_uids) . '") and is_deleted = "0"';
            $contacts_in_DB = $secondarydb->query($contacts_query)->result_array();
            $contacts_in_DB = $this->get_max_version_data($contacts_in_DB, 'contact_uid');
            foreach ($contacts_in_DB as $contact) {
                if ($contact['contact_uid'] != '') {
                    $contacts[$contact['contact_uid']] = $contact;
                }
            }
            $logs['contacts_query_fom_db2'] = $contacts_query;
        }
        if (!empty($pt_actual_data)) {
            $internal_log['data_fom_pt'] = $pt_actual_data;
            $tps_ids = array_unique(array_column($pt_actual_data, 'tps_id'));
            $ticket_ids = array_unique(array_column($pt_actual_data, 'ticket_id'));
            $all_vgns = array_unique(array_column($pt_actual_data, 'visitor_group_no'));
            $extra_options = $this->find(
                'prepaid_extra_options', array(
                    'select' => 'description, price, quantity, tax,refund_quantity, net_price, ticket_price_schedule_id, visitor_group_no, ticket_id, selected_date, from_time, to_time',
                    'where' => 'visitor_group_no in (' . implode(',', $all_vgns) . ')'),
                'array'
            );
            $logs['extra_options_query'] = $this->primarydb->db->last_query();

            $per_ticket_extra_options = array();
            $per_age_group_options = array();
            foreach ($extra_options as $option_details) {
                $from_time = ($option_details['from_time'] != '' && $option_details['from_time'] != '0') ? $option_details['from_time'] : '';
                $to_time = ($option_details['to_time'] != '' && $option_details['to_time'] != '0') ? $option_details['to_time'] : '';
                $reservation_date = ($option_details['selected_date'] != '' && $option_details['selected_date'] != 0 && $from_time != '' && $to_time != '') ? date('Y-m-d', strtotime($option_details['selected_date'])) : ''; // from_time, to_time not equal to blank to check booking type ticket
                if ($option_details['ticket_price_schedule_id'] == 0 || $option_details['ticket_price_schedule_id'] == '') {
                    $per_ticket_extra_options[$option_details['visitor_group_no'] . '_' . $option_details['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time][] = array(
                        'name' => $option_details['description'],
                        'quantity' => (int) $option_details['quantity'],
                        'refund_quantity' => (int) $option_details['refund_quantity'],
                        'price' => (float) $option_details['price'],
                        'net_price' => (float) $option_details['net_price'],
                        'tax' => (float) $option_details['tax']
                    );
                } else {
                    $per_age_group_options[$option_details['visitor_group_no'] . '_' . $option_details['ticket_id'] . '_' . $option_details['ticket_price_schedule_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time][] = array(
                        'name' => $option_details['description'],
                        'quantity' => (int) $option_details['quantity'],
                        'refund_quantity' => (int) $option_details['refund_quantity'],
                        'price' => (float) $option_details['price'],
                        'net_price' => (float) $option_details['net_price'],
                        'tax' => (float) $option_details['tax']
                    );
                }
            }
            $tickets = $this->find('modeventcontent', array('select' => 'mec_id, cod_id, is_scan_countdown, countdown_interval, is_reservation', 'where' => 'mec_id in (' . implode(',', $ticket_ids) . ')'), 'array');
            $logs['mec_data_query'] = $this->primarydb->db->last_query();
            foreach ($tickets as $ticket) {
                $tickets_data[$ticket['mec_id']] = $ticket;
            }
            $tps_data = $this->find('ticketpriceschedule', array('select' => 'start_date, end_date, id, days', 'where' => 'id in (' . implode(',', $tps_ids) . ') and deleted = "0"'), 'array');
            $logs['tps_data_query'] = $this->primarydb->db->last_query();
            $days = array('all' => '0', 'sun' => '1', 'mon' => '2', 'tue' => '3', 'wed' => '4', 'thu' => '5', 'fri' => '6', 'sat' => '7');
            foreach ($tps_data as $type) {
                $tps_details[$type['id']]['start_date'] = date("Y-m-d", $type['start_date']);
                $tps_details[$type['id']]['end_date'] = date("Y-m-d", $type['end_date']);
                $tps_details[$type['id']]['day'] = $days[strtolower($type['days'])];
            }
            foreach ($pt_actual_data as $q => $pt_data) {
                $info = $this->get_contact_info($pt_data, $contacts, $update_form_extra_booking_info);
                $contact_name = $info['name'];
                $contact_email = $info['email'];
                $contact_passport = $info['passport'];
                $logs['contact_detailsss__'.$q] = $contact_name;
                if ($show_orders == 1 && $contact_email == $email_id && $contact_name == $name &&
                        $contact_passport == $passport_no &&
                        $pt_data['valid_till'] != '' && $pt_data['valid_till'] != null && $pt_data['museum_cashier_id'] > 0) {
                    $valid_till_values[$pt_data['valid_till']] = array(
                        "museum_cashier_id" => $pt_data['museum_cashier_id'],
                        "museum_cashier_name" => trim($pt_data['museum_cashier_name']),
                    );
                }
            }
            $min_valid_till = min(array_filter(array_unique(array_keys($valid_till_values))));
            $min_valid_till_values = $valid_till_values[$min_valid_till];

            foreach ($pt_actual_data as $data) {
                $info = $this->get_contact_info($data, $contacts, $update_form_extra_booking_info);
                $contact_name = $info['name'];
                $contact_email = $info['email'];
                $contact_passport = $info['passport'];
                
                if ($show_orders == 0 || (
                    $contact_email == $email_id && $contact_name == $name &&
                        (!isset($contact_passport) || $contact_passport == $passport_no)
                )) {
                    $pp_no = isset($contact_passport) ? $contact_passport : "";
                    $extra_booking_info = json_decode(stripslashes($data['extra_booking_information']), true);
                    $third_party_response_data = json_decode(stripslashes($data['third_party_response_data']), true);
                    $key = $contact_email . '_' . $pp_no . '_' . $contact_name;
                    $get_guests_details_res = array_values($this->get_guests_details($contact_name, $contact_email, $pp_no, $data['extra_booking_information'], $data['reserved_1']));
                    $guest_data[$key] = $get_guests_details_res[0];
                    $activated_pass = isset($data['bleep_pass_no']) ? $data['bleep_pass_no'] : '';
                    $guest_data[$key]['activated_pass_no'] = ($guest_data[$key]['activated_pass_no'] != '' && $guest_data[$key]['activated_pass_no'] != null) ? $guest_data[$key]['activated_pass_no'] : $activated_pass;
                    if ($show_orders == 1) {
                        $guest_data[$key]['activated_by_cashier_id'] = ($min_valid_till_values['museum_cashier_id'] > 0) ? (int) $min_valid_till_values['museum_cashier_id'] : 0;
                        $guest_data[$key]['activated_by_cashier_name'] = isset($min_valid_till_values['museum_cashier_name']) ? trim($min_valid_till_values['museum_cashier_name']) : '';
                        $guest_data[$key]['activation_time'] = ($min_valid_till != '') ? date('Y-m-d H:i:s', $min_valid_till) : '';
                        $from_time = ($data['from_time'] != '' && $data['from_time'] != '0') ? $data['from_time'] : '';
                        $to_time = ($data['from_time'] != '' && $data['from_time'] != '0') ? $data['to_time'] : '';
                        $reservation_date = ($data['selected_date'] != '' && $data['selected_date'] != 0 && $from_time != '' && $to_time != '') ? date('Y-m-d', strtotime($data['selected_date'])) : '';
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['tps_id'] = (int) $data['tps_id'];
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['ticket_type'] = $this->types[strtolower($data['ticket_type'])];
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['ticket_type_label'] = $data['ticket_type'];
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['quantity'] ++;
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['unit_price'] = (float) $data['price'];
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['capacity'] = isset($data['capacity']) ? (int) $data['capacity'] : 1;
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['start_date'] = isset($tps_details[$data['tps_id']]) ? $tps_details[$data['tps_id']]['start_date'] : '';
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['end_date'] = isset($tps_details[$data['tps_id']]) ? $tps_details[$data['tps_id']]['end_date'] : '';
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['day'] = isset($tps_details[$data['tps_id']]) ? (int) $tps_details[$data['tps_id']]['day'] : 0;
                        $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['extra_options'] = (!empty($per_age_group_options[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $data['tps_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time])) ? $per_age_group_options[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $data['tps_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time] : array();
                        if ($data['third_party_type'] == '36') {
                            $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['spot_details']['section'] = ($extra_booking_info['product_type_spots'][0]['spot_section'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_section'] : '';
                            $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['spot_details']['row'] = ($extra_booking_info['product_type_spots'][0]['spot_row'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_row'] : '';
                            $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['spot_details']['number'] = ($extra_booking_info['product_type_spots'][0]['spot_number'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_number'] : '';
                            $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['spot_details']['secret_key'] = ($third_party_response_data['third_party_reservation_detail']['supplier']['secretKey'] != '') ? $third_party_response_data['third_party_reservation_detail']['supplier']['secretKey'] : "";
                            $types[$data['visitor_group_no'] . '_' . $data['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$data['tps_id']]['spot_details']['event_key'] = ($third_party_response_data['third_party_reservation_detail']['product_availability_id'] != '') ? $third_party_response_data['third_party_reservation_detail']['product_availability_id'] : "";
                        }
                    }
                }
            }
            $internal_log['types'] = $types;
            $activated_tickets = $reservation_tickets = $open_tickets = $other_booking_tickets = $other_res_tickets = array();
            if ($show_orders == 1) {
                $current_date_time_str = strtotime(gmdate("Y-m-d H:i:s"));
                foreach ($pt_actual_data as $i => $details) {
                    $booking_details = array();
                    $status = $arrival_status = 0;
                    $arrival_msg = '';
                    $info = $this->get_contact_info($details, $contacts, $update_form_extra_booking_info);
                    $contact_name = $info['name'];
                    $contact_email = $info['email'];
                    $contact_passport = $info['passport'];
                    $pasport = ($details['passport_number'] != '' && $details['passport_number'] != null) ? $details['passport_number'] : '';
                    $details['passport_number'] = isset($contact_passport) ? $contact_passport : $pasport;
                    if ($contact_email == $email_id && $contact_name == $name && $details['passport_number'] == $passport_no) {
                        $from_time = ($details['from_time'] != '' && $details['from_time'] != '0') ? $details['from_time'] : '';
                        $to_time = ($details['to_time'] != '' && $details['to_time'] != '0') ? $details['to_time'] : '';
                        $reservation_date = ($details['selected_date'] != '' && $details['selected_date'] != 0 && $from_time != '' && $to_time != '') ? date('Y-m-d', strtotime($details['selected_date'])) : '';
                        if ($details['is_refunded'] == '2') {
                            $status = 2;
                        } else if ($details['used'] == 1) {
                            $status = 1;
                        } else {
                            $status = 0;
                        }
                        if ($status == 1 && $details['payment_conditions'] != '') { //activating user will always get timebased ticket as normal ticket
                            $status = 0;
                        }
                        if ($details['payment_conditions'] != '' && $details['bleep_pass_no'] != '') { //timebased ticket with activated card
                            $logs[$details['ticket_id'] . '_status__payment_conditions__current_' . $i] = array($status, $details['payment_conditions'], $device_current_time);
                            if ($status != 2 && $details['payment_conditions'] > 0 && $details['payment_conditions'] < $device_current_time) {
                                $status = 4; //expired card
                            } else if ($status != 2 && $details['used'] == '1') {
                                $status = 3; //Timebased (can be redeemed again)
                            }
                        }
                        $pt_ids[] = $details['prepaid_ticket_id'];

                        $vgn_ticket = $details['visitor_group_no'] . '_' . $details['ticket_id'];
                        if (date("Y-m-d", strtotime($details['redeem_date_time'])) != "1970-01-01") { // redeemed ticket
                            $date_key = date("Y-m-d", strtotime($details['redeem_date_time']));
                        } else if ($details['selected_date'] != '' && $details['selected_date'] != '0' && $details['from_time'] != '' && $details['from_time'] != '0' && $details['to_time'] != '' && $details['to_time'] != '0') { //reservation ticket
                            $date_key = $details['selected_date'];
                        } else {
                            $date_key = date("Y-m-d");
                        }
                        if ($status == 3) { //timebased redeemable ticket should be listed in current date
                            $date_key = date("Y-m-d");
                        }

                        if ($details['selected_date'] != '' && $details['selected_date'] != '0' && $details['from_time'] != '' && $details['from_time'] != '0' && $details['to_time'] != '' && $details['to_time'] != '0') { //reservation ticket
                            $reservation = 1;
                            if (strpos($details['shared_capacity_id'], ',') !== false) {
                                $ids = explode(",", $details['shared_capacity_id']);
                                $shared_capacity = $ids[0];
                                $own_capacity = $ids[1];
                            } else {
                                $shared_capacity = $details['shared_capacity_id'];
                                $own_capacity = 0;
                            }
                        } else {  //open ticket
                            $reservation = 0;
                            $shared_capacity = 0;
                            $own_capacity = 0;
                        }

                        if ($status == 3 || ($tickets_data[$details['ticket_id']]['is_reservation'] == 1 && $status != 2 && $status != 4)) { // timebased or reservation tickets have msg only
                            if ($status != 3) { //reservation tickets -> time msg according to slot
                                $from_date_time = $date_key . " " . $details['from_time'];
                                $to_date_time = $date_key . " " . $details['to_time'];
                                $arrival_time = $this->get_arrival_time_from_slot($timezone, $from_date_time, $to_date_time, $details['timeslot']);
                                $arrival_msg = $arrival_time['arrival_msg'];
                                $arrival_status = $arrival_time['arrival_status'];
                            } else { //timebased redeemed ticket -> msg according to activation time
                                $arrival_status = 3;
                                $pymnt_cndtns = new \DateTime(date("Y-m-d H:i:s", $details['payment_conditions']));
                                $logs['Current__paymnet_conditions_' . $i] = array($current_date_time_str, $details['payment_conditions']);
                                $time_left = $pymnt_cndtns->diff(new DateTime(date("Y-m-d H:i:s", $current_date_time_str)));
                                $logs['time_left_' . $i] = $time_left;
                                $hours = ($time_left->d > 0) ? $time_left->d * 24 : 0;
                                $hrs = ($time_left->h > 0) ? $hours + $time_left->h : $hours;
                                $mint = $time_left->i;
                                $sec = $time_left->s;
                                if ($hrs > 0) {
                                    $arrival_msg .= $hrs . " hrs ";
                                }
                                if ($mint > 0) {
                                    $arrival_msg .= $mint . " min ";
                                }
                                if ($sec > 0 && $hrs == 0) {
                                    $arrival_msg .= $sec . " secs ";
                                }
                                if ($arrival_msg != '') {
                                    $arrival_msg .= "left ";
                                }
                            }
                        } else { //no msg to display
                            $arrival_msg = '';
                        }
                        if ($details['tp_payment_method'] == 6) { //combi
                            $product_type = 2;
                        } else if ($details['tp_payment_method'] == 7) { //cluster
                            $product_type = 3;
                        } else {
                            $product_type = 0; //normal
                        }
                        
                        $booking_details['key'] = ($to_time < $from_time || ($from_time == '00:00' && $to_time == '23:59')) ? $from_time : $to_time;
                        $booking_details['ticket_id'] = (int) $details['ticket_id'];
                        $booking_details['ticket_title'] = $details['title'];
                        $booking_details['bleep_pass_no'] = $details['bleep_pass_no'];
                        $booking_details['activated'] = ($app_flavour == "CNTK" || $details['bleep_pass_no'] != '') ? 1 : 0;
                        $booking_details['payment_status'] = (int) $details['booking_status'];
                        $booking_details['booked'] = 1;
                        $booking_details['museum_name'] = $details['museum_name'];
                        $booking_details['museum_id'] = $details['museum_id'];
                        $booking_details['ticket_status'] = $status;
                        $booking_details['first_used'] = ($details['used'] == '1' && date("Y-m-d", strtotime($details['redeem_date_time'])) != '1970-01-01') ? $details['redeem_date_time'] : '';
                        $booking_details['last_used'] = ($details['used'] == '1' && date("Y-m-d", strtotime($details['redeem_date_time'])) != '1970-01-01' && $details['payment_conditions'] != '' && $details['payment_conditions'] != NULL) ? $details['redeem_date_time'] : '';
                        $booking_details['hidden_ticket'] = ($allow_activation == 1) ? 1 : 0;
                        $booking_details['reservation'] = $reservation;
                        $booking_details['shared_capacity_id'] = (int) $shared_capacity;
                        $booking_details['own_capacity_id'] = (int) $own_capacity;
                        $booking_details['time_based'] = (int) $tickets_data[$details['ticket_id']]['is_scan_countdown'];
                        $booking_details['arrival_status'] = (int) $arrival_status; //1 or 2 or 3 -> early or late or timebased redeem
                        $booking_details['arrival_message'] = $arrival_msg; //hours early or late
                        $booking_details['activation_time'] = ($details['valid_till'] != '' && $details['valid_till'] != NULL) ? date('Y-m-d H:i:s', $details['valid_till']) : '';
                        $booking_details['product_type'] = (int) $product_type;
                        $booking_details['voucher'] = (int) 0;
                        $booking_details['channel_type'] = $details['channel_type'];
                        $booking_details['passes'] = $details['passNo'];
                        $booking_details['price'] = (float) $details['price'];
                        $booking_details['per_ticket_extra_option'] = (!empty($per_ticket_extra_options[$vgn_ticket . '_' . $reservation_date . '_' . $from_time . '_' . $to_time])) ? $per_ticket_extra_options[$vgn_ticket . '_' . $reservation_date . '_' . $from_time . '_' . $to_time] : array();
                        $booking_details['booking_details']['visitor_group_no'] = $details['visitor_group_no'];
                        $booking_details['booking_details']['prepaid_ticket_id'] = $details['prepaid_ticket_id'];
                        $booking_details['booking_details']['visitor_group_no'] = $details['visitor_group_no'];
                        $booking_details['booking_details']['prepaid_ticket_id'] = $details['prepaid_ticket_id'];
                        $booking_details['booking_details']['reservation_date'] = $reservation_date;
                        $booking_details['booking_details']['from_time'] = $from_time;
                        $booking_details['booking_details']['to_time'] = $to_time;
                        $booking_details['booking_details']['slot_type'] = $details['timeslot'];
                        $booking_details['booking_details']['booking_date_time'] = $details['created_date_time'];
                        $booking_details['booking_details']['partner_name'] = isset($details['distributor_partner_name']) ? $details['distributor_partner_name'] : '';
                        $booking_details['booking_details']['partner_id'] = isset($details['distributor_partner_id']) ? (int) $details['distributor_partner_id'] : 0;
                        $booking_details['booking_details']['sales_by_cashier_id'] = isset($details['cashier_id']) ? (int) $details['cashier_id'] : 0;
                        $booking_details['booking_details']['sales_by_cashier_name'] = isset($details['cashier_name']) ? $details['cashier_name'] : '';
                        $booking_details['booking_details']['primary_name'] = isset($details['guest_names']) ? $details['guest_names'] : '';
                        $booking_details['booking_details']['primary_email'] = isset($details['guest_emails']) ? $details['guest_emails'] : '';
                        $booking_details['booking_details']['ticket_types'] = isset($types[$vgn_ticket . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types'][$details['tps_id']]) ? array_values($types[$details['visitor_group_no'] . '_' . $details['ticket_id'] . '_' . $reservation_date . '_' . $from_time . '_' . $to_time]['types']) : array();
                        if ($details['is_addon_ticket'] == '2') { //sub ticket
                            $main_to_sub_data[$details['related_product_id']][] = $booking_details;
                        }
                        $booking_details['sub_tickets'] = (in_array($booking_details['ticket_id'], array_keys($main_to_sub_data))) ? array_values($main_to_sub_data[$booking_details['ticket_id']]) : array(); //data for main ticket
                        
                        if($details['is_addon_ticket'] != '2')  {
                            if ($status == 3) {
                                $activated_tickets[$date_key][$from_time . '_' . $to_time . '_' . $vgn_ticket] = $booking_details;
                            } else if ($reservation == 0 && $status == 0) { //booking + redeemable tickets
                                $open_tickets[$date_key][$from_time . '_' . $to_time . '_' . $vgn_ticket] = $booking_details;
                            } else if ($reservation == 1 && $status == 0) {
                                $reservation_tickets[$date_key][$from_time . '_' . $to_time . '_' . $vgn_ticket] = $booking_details;
                                sort($reservation_tickets[$date_key]);
                            } else { //status 1, 2, 4 (used, refunded, expired)
                                if ($reservation == 0) {
                                    $other_booking_tickets[$date_key][$from_time . '_' . $to_time . '_' . $vgn_ticket] = $booking_details;
                                } else {
                                    $other_res_tickets[$date_key][$from_time . '_' . $to_time . '_' . $vgn_ticket] = $booking_details;
                                    sort($other_res_tickets[$date_key]);
                                }
                            }
                        }
                    }
                }
                $reservation_details = array_merge_recursive($activated_tickets, $open_tickets, $reservation_tickets, $other_booking_tickets, $other_res_tickets);
                if (!empty($reservation_details)) {
                    foreach ($reservation_details as $date => $reserve_data) {
                        $reservation_detail[$date] = array_values($reserve_data);
                    }
                }
                $response['data'] = array(
                    'guest_itineraries' => !empty($reservation_detail) ? $reservation_detail : array(),
                    'related_booking_ids' => !empty($pt_ids) ? $pt_ids : array(),
                    'guest_details' => $guest_data[$email_id . '_' . $passport_no . '_' . $name],
                );
            } else {
                $response['data'] = array_values($guest_data);
                $response['guest_listing'] = 1;
            }

            $response['status'] = 1;
        } else {
            $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "No record found");
        }
        $MPOS_LOGS['search'] = $logs;
        $internal_logs['search'] = $internal_log;
        return $response;
    }
    
    /**
     * @Name activate
     * @Purpose used to activate selected tickets corresponding to a user
     * in first case (check_pass => 0), we will use already activated pass for this particular user
     * in another case (check_pass => 1), we will add a new pass regarding this user
     * @return status and data 
     *      status 1 or 0
     *      guest_details (the main user) and related_guests(guests who belong to this main user in any of his order)
     * @param 
     *      $req - array() - API request (same as from APP) + user_details
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    function activate($req = array()) 
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $current_date_time = gmdate("Y-m-d H:i:s");
        $current_date_time_str = strtotime($current_date_time);
        $app_flavour = $req['app_flavour'];
        $museum_id = $req['supplier_id'];
        $related_orders = $req['related_orders'];
        $guest_details = $req['guest_details'];
        $pos_point_id = ($req['pos_point_id'] != '' || $req['pos_point_id'] != null) ? $req['pos_point_id'] : 0;
        $pos_point_name = ($req['pos_point_name'] != '' || $req['pos_point_name'] != null) ? $req['pos_point_name'] : "";
        $payment_method = isset($req['payment_method']) ? $req['payment_method'] : 2; // 1 for activation user, 0 if scan user
        $allow_activation = isset($req['allow_activation']) ? $req['allow_activation'] : 1; // 1 for activation user, 0 if scan user
        $passport_no = isset($guest_details['passport_no']) ? $guest_details['passport_no'] : '';
        $guest_detail = strtolower(trim($guest_details['email_id'])) . '_' . $passport_no . '_' . strtolower(trim($guest_details['name']));
        $users_details = $req['users_details'];
        $msg = '';
        $prepaid_ticket_ids = array();
        if ($req['check_pass'] == 1 && strpos($req['bleep_pass_no'], 'https://qu.mu') !== false) {
            $bleep_pass_no = str_replace("https://qu.mu/", "", $req['bleep_pass_no']);
            $result = $this->find('bleep_pass_nos', array('select' => '*', 'where' => 'pass_no = "' . $bleep_pass_no . '" and status = "0"'), 'row_object');
        } else {
            $bleep_pass_no = $req['bleep_pass_no'];
        }
        if (($allow_activation == 0) || ($allow_activation == 3) || ($allow_activation == 1 && ($req['check_pass'] == 0 || ($req['check_pass'] == 1 && !empty($result))))) { // redeem or valid pass
            $ticket_ids = array_column($req['add_to_pass'], 'ticket_id');
            $prepaid_ticket_ids = array_column($req['add_to_pass'], 'prepaid_ticket_id');
            $tickets = $this->find('modeventcontent', array('select' => 'mec_id, guest_notification, cod_id, is_scan_countdown, countdown_interval', 'where' => 'mec_id in (' . implode(',', $ticket_ids) . ')'), 'array');
            $payment_conditions = '(CASE ';
            foreach ($tickets as $ticket) {
                
                if ($ticket['is_scan_countdown'] == 1) {
                    $countdown_values = explode('-', $ticket['countdown_interval']);
                    $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                    $logs['countdown_values__countdown_time'] = array($countdown_values, $countdown_time);
                    $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                    $payment_conditions .= ' WHEN ticket_id = "' . $ticket['mec_id'] . '" AND (payment_conditions = 0 OR payment_conditions is NULL) THEN "' . $valid_till . '"';
                    $redeem_users = $users_details['sup_cashier_id'] . '_' . gmdate("Y-m-d") . ',';
                    $fetch_pt_data[$ticket['mec_id']] = 1;
                } else {
                    $payment_conditions .= ' WHEN ticket_id = "' . $ticket['mec_id'] . '" THEN ""';
                    $redeem_users = '';
                }
            }
            $payment_conditions .= ' ELSE payment_conditions END)';
            if ($allow_activation == 1) { //activate bleep pass
                $action_performed = $app_flavour . '_ACT';
                $update_pt_db1 = 'bleep_pass_no = "' . $bleep_pass_no . '", action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), '
                        . ' museum_cashier_id = "' . $users_details['sup_cashier_id'] . '", museum_cashier_name = "' . $users_details['sup_cashier_name'] . '", '
                        . ' valid_till = "' . $current_date_time_str . '" ';
                $update_pt_db2 = ', voucher_updated_by = "' . $users_details['sup_cashier_id'] . '", voucher_updated_by_name = "' . $users_details['sup_cashier_name'] . '", redeem_method = "voucher", updated_at = "' . $current_date_time . '" ';
                $where = ' museum_id = "' . $museum_id . '" and  prepaid_ticket_id in (' . implode(',', $prepaid_ticket_ids) . ')';
                $this->query('UPDATE prepaid_tickets SET '. $update_pt_db1 . ' where '. $where);
                $logs['pt_update_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
                $db2_queries[] = 'UPDATE prepaid_tickets SET '. $update_pt_db1 . $update_pt_db2 . ' where '. $where;
                
                $this->query('update bleep_pass_nos set status="1", last_modified_at = "' . gmdate("Y-m-d H:i:s") . '" where pass_no = "' . $bleep_pass_no . '"');
                $update_vt_query = 'UPDATE visitor_tickets SET scanned_pass = "' . $bleep_pass_no . '", action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), '
                        . ' redeem_method = "voucher", voucher_updated_by = "' . $users_details['sup_cashier_id'] . '", voucher_updated_by_name = "' . $users_details['sup_cashier_name'] . '" '
                        . ' where museum_id = "' . $museum_id . '" and  transaction_id in (' . implode(',', $prepaid_ticket_ids) . ')';
                $db2_queries[] = $update_vt_query;
                $redemption_status = 1;
            } else if ($allow_activation == 3) { //pay at cashier for contiki app
                $action_performed = $app_flavour . '_PAID';
                //here add_to_pass has main tickets then each main ticket object has its respective subtickets
                $pt_ids = "";
                foreach ($req['add_to_pass'] as $pass_request) {
                    $pt_ids = implode(',', array_column($pass_request['sub_tickets'], 'prepaid_ticket_id'));
                }
                if ($pt_ids != "") { //pt_ids of subtickets
                    $pt_ids = "," . $pt_ids;
                }
                $update_pt_query = 'UPDATE prepaid_tickets SET booking_status = "1", is_order_confirmed = "1", action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), activation_method = "' . $payment_method . '" '
                        . ' where ';
                if ($app_flavour != "CNTK") {
                    $update_pt_query .= ' museum_id = "' . $museum_id . '" and ';
                }
                $update_pt_query .= ' prepaid_ticket_id in (' . implode(',', $prepaid_ticket_ids) . $pt_ids . ')';
                $this->query($update_pt_query);
                $insert_in_vt = array(
                    'prepaid_ticket_ids' => implode(',', $prepaid_ticket_ids) . $pt_ids,
                    'action_performed' => $action_performed,
                );
                $vgns = array_column($req['add_to_pass'], 'visitor_group_no');
                $pt_ids_for_VT_BG = $pt_ids_for_BG = explode(",", implode(',', $prepaid_ticket_ids) . $pt_ids);
                $db2_queries[] = $update_pt_query;
                $logs['pt_update_query'] = $update_pt_query;
                $redemption_status = 1;
            } else if ($allow_activation == 0) { //redeem a ticket 
                //here prepaid_ticket_ids refer to the pt_ids of tickets selected to redeem, 
                // in case of subtickets redeem, these are sutickets pt_ids, and main_ticket object has main ticket ids of this sub product. 
                $action_performed = $app_flavour . "_SCAN";
                $scaned_time = '';
                $update_pt_db1 = 'used = "1", redeem_users = CONCAT(redeem_users, "' . $redeem_users . '"), action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), '
                        . ' scanned_at = (case when scanned_at = "" or scanned_at is null then "' . $current_date_time_str . '" else scanned_at end), payment_conditions = ' . $payment_conditions . ',  '
                        . ' redeem_date_time = (case when redeem_date_time = "1970-01-01 00:00:01" or redeem_date_time is null then "' . $current_date_time . '" else redeem_date_time end) ';
                $update_pt_db2 = ', pos_point_id_on_redeem = (case when pos_point_id_on_redeem = "0" or pos_point_id_on_redeem is null then "' . $pos_point_id . '" else pos_point_id_on_redeem end), '
                        . ' pos_point_name_on_redeem = (case when pos_point_name_on_redeem = "" or pos_point_name_on_redeem is null then "' . $pos_point_name . '" else pos_point_name_on_redeem end) ';
                if ($app_flavour != "CNTK") {
                    $where = ' museum_id = "' . $museum_id . '" and ';
                }
                $where .= '  prepaid_ticket_id in (' . implode(',', $prepaid_ticket_ids) . ')';
                $this->query('UPDATE prepaid_tickets SET ' . $update_pt_db1 . ' where '. $where);
                $logs['pt_update_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
                $db2_queries[] = 'UPDATE prepaid_tickets SET ' . $update_pt_db1 . $update_pt_db2 . ' where '. $where;
                //for vt -> in case of subticket redeemption, we need to redeem transaction id of main and ticket id of sub ticket.
                if (isset($req['main_ticket']) && !empty($req['main_ticket'])) {
                    $main_transaction_id = $req['main_ticket']['prepaid_ticket_id'];
                    $update_vt_query = 'UPDATE visitor_tickets SET visit_date = (case when used != "1" then "' . $current_date_time_str . '" else visit_date end), '
                            . ' action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), used = "1" where ';
                    if ($app_flavour != "CNTK") {
                        $update_vt_query .= ' museum_id = "' . $museum_id . '" and ';
                    }
                    $update_vt_query .= ' transaction_id = "' . $main_transaction_id . '" and ticketId = "' . $req['add_to_pass'][0]['ticket_id'] . '"';
                    $pt_ids_with_ticket_id_for_VT_BG = array($main_transaction_id => $req['add_to_pass'][0]['ticket_id']);
                } else {
                    $update_vt_query = 'UPDATE visitor_tickets SET visit_date = (case when used != "1" then "' . $current_date_time_str . '" else visit_date end), '
                            . ' action_performed = CONCAT(action_performed, ", ' . $action_performed . '"), used = "1" where ';
                    if ($app_flavour != "CNTK") {
                        $update_vt_query .= ' museum_id = "' . $museum_id . '" and ';
                    }
                    $update_vt_query .= ' transaction_id in (' . implode(',', $prepaid_ticket_ids) . ')';
                }
                $db2_queries[] = $update_vt_query;
                $update_redeem_table['prepaid_ticket_ids'] = $prepaid_ticket_ids;
                $update_redeem_table['museum_cashier_id'] = $users_details['sup_cashier_id'];
                $update_redeem_table['redeem_date_time'] = $current_date_time;
                $update_redeem_table['museum_cashier_name'] = $users_details['sup_cashier_name'];
                $redemption_status = 2;
                if (!empty($prepaid_ticket_ids) && $fetch_pt_data[$req['add_to_pass'][0]['ticket_id']] == 1) {
                    $time_based_data = $this->find('prepaid_tickets', array('select' => 'ticket_id, payment_conditions', 'where' => 'prepaid_ticket_id in (' . implode(',', $prepaid_ticket_ids) . ')'), 'list');
                    $logs['time_based_data_query'] = array($this->primarydb->db->last_query(), $time_based_data);
                    if (isset($time_based_data[$req['add_to_pass'][0]['ticket_id']]) && $time_based_data[$req['add_to_pass'][0]['ticket_id']] != '' && $time_based_data[$req['add_to_pass'][0]['ticket_id']] != null) {
                        $scaned_time = $time_based_data[$req['add_to_pass'][0]['ticket_id']];
                        $pymnt_cndtns = new \DateTime(date("Y-m-d H:i:s", $scaned_time));
                        $logs['diff_in_time_left____Current__scanned'] = array(date("Y-m-d H:i:s", $current_date_time_str), date("Y-m-d H:i:s", $scaned_time));
                        $time_left = $pymnt_cndtns->diff(new \DateTime(date("Y-m-d H:i:s", $current_date_time_str)));
                        $logs['time_left'] = $time_left;
                        $hours = ($time_left->d > 0) ? $time_left->d * 24 : 0;
                        $hrs = ($time_left->h > 0) ? $hours + $time_left->h : $hours;
                        $mint = $time_left->i;
                        $sec = $time_left->s;
                        if ($hrs > 0) {
                            $msg .= $hrs . " hrs ";
                        }
                        if ($mint > 0) {
                            $msg .= $mint . " min ";
                        }
                        if ($sec > 0 && $hrs == 0) {
                            $msg .= $sec . " secs ";
                        }
                        if ($msg != '') {
                            $msg .= "left ";
                        }
                        $redemption_status = 4;
                    }
                }
            }

            //fetch for related guests
            $query = 'select prepaid_ticket_id, bleep_pass_no, museum_cashier_id, museum_cashier_name, scanned_at, extra_booking_information, third_party_type, visitor_group_no, guest_names, guest_emails from prepaid_tickets where ';
            if ($app_flavour != "CNTK") {
                $query .= ' museum_id = "' . $museum_id . '" and ';
            }
            $query .= ' visitor_group_no in (' . implode(',', $related_orders) . ') '
                    . ' and is_prepaid = "1" and deleted = "0" and is_addon_ticket !="2" and is_refunded != "1" ';

            $order_info = $this->query($query);
            $logs['fetch_guests_query'] = $query;
            if (!empty($order_info)) {
                foreach ($order_info as $info) {
                    $extra_booking_info = json_decode(stripslashes($info['extra_booking_information']), true);
                    $person_detail = $extra_booking_info['per_participant_info'];
                    $passport = ($person_detail['id'] != '' && $person_detail['id'] != null) ? $person_detail['id'] : '';
                    $name = $this->get_guest_name($info['guest_names'], $info['guest_names'], $info['extra_booking_information']);
                    $email = $this->get_guest_email($info['guest_emails'], $info['guest_emails'], $info['extra_booking_information']);
                    $key = strtolower(trim($email)) . '_' . $passport . '_' . strtolower(trim($name));
                    $get_guests_details_res = array_values($this->get_guests_details($name, $email, $passport, $info['extra_booking_information']));
                    $guests_info[$key] = $get_guests_details_res[0];
                    if (!empty($extra_booking_info['per_participant_info']) && empty($spot_details) && $allow_activation == 0 && $key == $guest_detail && $info['prepaid_ticket_id'] == $prepaid_ticket_ids[0]) { //seat details of the actual guest only for redeem case
                        $spot_details = array(
                            'section' => ($info['third_party_type'] == '36' && $extra_booking_info['product_type_spots'][0]['spot_section'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_section'] : '',
                            'row' => ($info['third_party_type'] == '36' && $extra_booking_info['product_type_spots'][0]['spot_row'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_row'] : '',
                            'number' => ($info['third_party_type'] == '36' && $extra_booking_info['product_type_spots'][0]['spot_number'] != '') ? (string) $extra_booking_info['product_type_spots'][0]['spot_number'] : ''
                        );
                    }
                }
                
                $logs['guests_info'] = $guests_info;
                $logs['spot_details'] = $spot_details;
                unset($guests_info[$guest_detail]);
                $guest_details['spot_details'] = isset($spot_details) ? $spot_details : (object) array();
            }
            //queue to update DB2
            if (UPDATE_SECONDARY_DB && !empty($db2_queries)) {
                // Load AWS library.
                require_once 'aws-php-sdk/aws-autoloader.php';
                // Load SNS library.
                $this->load->library('Sns');
                $sns_object = new \Sns();
                // Load SQS library.
                $this->load->library('Sqs');
                $sqs_object = new \Sqs();

                $request_array['db2'] = !empty($db2_queries) ? $db2_queries : array();
                $request_array['update_redeem_table'] = $update_redeem_table;
                $request_array['insert_in_vt_by_mpos'] = !empty($insert_in_vt) ? $insert_in_vt : array();
                $request_array['action'] = "activate";
                $request_array['pt_ids_for_BG'] =  !empty($pt_ids_for_BG) ? $pt_ids_for_BG : $prepaid_ticket_ids;
                $request_array['pt_ids_for_VT_BG'] = !empty($pt_ids_for_VT_BG) ? $pt_ids_for_VT_BG : array();
                $request_array['pt_ids_with_ticket_for_VT_BG'] = !empty($pt_ids_with_ticket_id_for_VT_BG) ? $pt_ids_with_ticket_id_for_VT_BG : array();
                $request_array['write_in_mpos_logs'] = 1;
                $request_string = json_encode($request_array);
                $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;
                if (LOCAL_ENVIRONMENT == 'Local') {
                    local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                } else {
                    // This Fn used to send notification with data on AWS panel.
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                    // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                    if ($MessageId) {
                        $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }
                }
            }
            $response['status'] = 1;
            $response['redemption_status'] = $redemption_status;
            $response['message'] = ($msg != '') ? $msg : "success";
            $response['related_guests'] = !empty($guests_info) ? array_values($guests_info) : array();
            $response['guest_details'] = $guest_details;
        } else {
            $logs['check_pass_query'] = $this->primarydb->db->last_query();
            $response['status'] = 0;
            $response['message'] = "Pass Not Valid";
        }
        $MPOS_LOGS['activate'] = $logs;
        return $response;
    }
    
    /**
     * @Name guest_details
     * @Purpose used to fetch details of guests belonging to a particular user
     * @return status and data 
     *      guest_details (the main user) and related_guests(guests who belong to this main user in any of his order) and related_orders (all orders of this user)
     * @param 
     *      $req - array() - API request (same as from APP) + user_details
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    function guest_details($req = array()) 
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $app_flavour = $req['app_flavour'];
        $museum_id = $req['supplier_id'];
        $guest_details = $req['guest_details'];
        $pass_no = isset($guest_details['activated_pass_no']) ? $guest_details['activated_pass_no'] : '';
        $passport = isset($guest_details['passport_no']) ? $guest_details['passport_no'] : '';
        $real_guest_key = strtolower(trim($guest_details['email_id'])) . '_' . $passport . '_' . strtolower(trim($guest_details['name']));
        $related_orders = implode(',', $req['related_orders']);
        if (!empty($related_orders)) {
            $query = 'select passNo, used, bleep_pass_no, prepaid_ticket_id, visitor_group_no, extra_booking_information, guest_names, guest_emails from prepaid_tickets where';
            if ($app_flavour != "CNTK") {
                $query .= ' museum_id = "' . $museum_id . '" and ';
            }
            $query .= '  visitor_group_no in (' . implode(',', $req['related_orders']) . ') and is_prepaid = "1" and deleted = "0" and is_refunded != "1"';
            $logs['fetch_pt_query'] = $query;
            $pt_data = $this->primarydb->db->query($query)->result_array();
            $key = '';
            foreach ($pt_data as $data) {
                $extra_booking_info = json_decode(stripslashes($data['extra_booking_information']), true);
                $user_info = $extra_booking_info['per_participant_info'];
                $pp_no = (($user_info['id'] == null) || $user_info['id'] == '') ? '' : $user_info['id'];
                $user_info['name'] = $this->get_guest_name($data['secondary_guest_name'], $data['guest_names'], $data['extra_booking_information']);
                $user_info['email'] = $this->get_guest_email($data['secondary_guest_email'], $data['guest_emails'], $data['extra_booking_information']);
                $key = strtolower(trim($user_info['email'])) . '_' . $pp_no . '_' . strtolower(trim($user_info['name']));
                $get_guests_details_res = array_values($this->get_guests_details($user_info['name'], $user_info['email'], $pp_no, $data['extra_booking_information']));
                $related_guests_data[$key] = $get_guests_details_res[0];
                $related_guests_data[$key]['related_booking_ids'][] = $data['prepaid_ticket_id'];
                $related_guests_data[$key]['dietary_information'] = '';
                $related_guests_data[$key]['additional_information'] = '';
                $related_guests_data[$key]['gender'] = isset($user_info['gender']) ? $user_info['gender'] : '';
                $booking_ids[$key] = ($booking_ids[$key] == '') ? $data['prepaid_ticket_id'] : $booking_ids[$key].','.$data['prepaid_ticket_id'];
            }
            $logs['booking_ids'] = $booking_ids;
            foreach ($related_guests_data as $guest_key => $guest) {
                $related_guest_data[$guest_key] = $guest;
                $related_guest_data[$guest_key]['related_booking_ids'] = explode(",", $booking_ids[$guest_key]);
            }
            if ($pass_no != '') {
                $redeem_details = $this->find('redeem_cashiers_details', array('select' => 'pass_no, visitor_group_no, redeem_time', 'where' => 'supplier_id = "' . $museum_id . '" and pass_no = "' . $pass_no . '"', 'order_by' => 'redeem_time ASC'));
                $logs['fetch_redeem_cashiers_details_query'] = $this->primarydb->db->last_query();
                $total_redeems = sizeof($redeem_details);
                $related_guest_data[$real_guest_key]['activated_pass_no'] = $pass_no;
                $related_guest_data[$real_guest_key]['activation_time'] = $guest_details['activation_time'];
                $related_guest_data[$real_guest_key]['first_used'] = isset($redeem_details[0]['redeem_time']) ? $redeem_details[0]['redeem_time'] : '';
                $related_guest_data[$real_guest_key]['last_used'] = isset($redeem_details[$total_redeems - 1]['redeem_time']) ? $redeem_details[$total_redeems - 1]['redeem_time'] : '';
            }
            $response['guest_details'] = !empty($related_guest_data[$real_guest_key]) ? $related_guest_data[$real_guest_key] : (object) array();
            unset($related_guest_data[$real_guest_key]);
            $response['status'] = 1;
            $response['related_guests'] = !empty($related_guest_data) ? array_values($related_guest_data) : array();
            $response['related_orders'] = $req['related_orders'];
        }
        $MPOS_LOGS['guest_details'] = $logs;
        return $response;
    }
        
    /**
     * @Name update_guest_details
     * @Purpose used to update details of a particular guest
     * @return status and message 
     * @param 
     *      $req - array() - API request (same as from APP) + user_details
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    function update_guest_details($req = array()) 
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $museum_id = $req['supplier_id'];
        $app_flavour = $req['app_flavour'];
        $guest_details = $req['guest_details'];
        $updated_guest_details = $req['updated_guest_details'];
        $related_booking_ids = !empty($guest_details['related_booking_ids']) ? $guest_details['related_booking_ids'] : array();
        $query = 'select extra_booking_information, guest_names, guest_emails, prepaid_ticket_id from prepaid_tickets where ';
        if ($app_flavour != "CNTK") {
            $query .= ' museum_id = "' . $museum_id . '" and ';
        }
        $query .= ' prepaid_ticket_id in (' . implode(',', $related_booking_ids) . ')';
        $logs['fetch_pt_query'] = $query;
        $pt_data = $this->primarydb->db->query($query)->result_array();
        if (!empty($pt_data)) {
            $prepaid_ticket_ids = array_unique(array_column($pt_data, 'prepaid_ticket_id'));
            $action_performed = $app_flavour . '_GUPDATE';
            foreach ($pt_data as $i => $pr_entry) {
                $updated_user_info = $user_info = $updated_user_details = array();
                $extra_booking_info = json_decode(stripslashes($pr_entry['extra_booking_information']), true);
                if (isset($extra_booking_info['per_participant_info']) && !empty($extra_booking_info['per_participant_info'])) {
                    $updated_user_info = $user_info = $extra_booking_info['per_participant_info'];
                    $user_info['name'] = $this->get_guest_name($pr_entry['secondary_guest_name'], $pr_entry['guest_names'], $pr_entry['extra_booking_information']);
                    $user_info['email'] = $this->get_guest_email($pr_entry['secondary_guest_email'], $pr_entry['guest_emails'], $pr_entry['extra_booking_information']);
                    $updated_user_details["name"] = $updated_user_info["name"] = isset($updated_guest_details['name']) ? ucfirst(trim($updated_guest_details['name'])) : ucfirst(trim($user_info['name']));
                    $updated_user_info["gender"] = isset($updated_guest_details['gender']) ? $updated_guest_details['gender'] : $user_info['gender'];
                    $updated_user_details["id"] = $updated_user_info["id"] = isset($updated_guest_details['passport_no']) ? $updated_guest_details['passport_no'] : $user_info['id'];
                    $updated_user_info["nationality"] = isset($updated_guest_details['nationality']) ? $updated_guest_details['nationality'] : $user_info['nationality'];
                    $updated_user_info["country_of_residence"] = isset($updated_guest_details['country_of_residence']) ? $updated_guest_details['country_of_residence'] : $user_info['country_of_residence'];
                    $updated_user_info["phone_no"] = isset($updated_guest_details['phone_number']) ? $updated_guest_details['phone_number'] : $user_info['phone_no'];
                    $updated_user_details["email"] = $updated_user_info["email"] = isset($updated_guest_details['email_id']) ? $updated_guest_details['email_id'] : $user_info['email'];
                    $updated_user_info["date_of_birth"] = ($updated_guest_details['dob'] != '' && $updated_guest_details['dob'] != NULL) ? date("j-n-Y", strtotime($updated_guest_details['dob'])) : $user_info['date_of_birth'];
                    $updated_user_info["departure"] = isset($updated_guest_details['departure']) ? $updated_guest_details['departure'] : $user_info['departure'];
                    $updated_user_info["arrival"] = isset($updated_guest_details['arrival']) ? $updated_guest_details['arrival'] : $user_info['arrival'];
                    $extra_booking_info['per_participant_info'] = $updated_user_info;
                } else if (isset($extra_booking_info['order_contact']) && !empty($extra_booking_info['order_contact'])) {
                    $updated_user_info = $user_info = $extra_booking_info['order_contact'];
                    $user_info['name'] = $this->get_guest_name($pr_entry['secondary_guest_name'], $pr_entry['guest_names'], $pr_entry['extra_booking_information']);
                    $user_info['email'] = $this->get_guest_email($pr_entry['secondary_guest_email'], $pr_entry['guest_emails'], $pr_entry['extra_booking_information']);
                    $name = isset($updated_guest_details['name']) ? explode(" ", $updated_guest_details['name']) : array();
                    $updated_user_info["contact_name_first"] = isset($updated_guest_details['name']) ? ucfirst(trim($name[0])) : ucfirst(trim($user_info['name']));
                    unset($name[0]);
                    $updated_user_info["contact_name_last"] = isset($updated_guest_details['name']) ? ucfirst(trim(implode(" ", $name))) : ucfirst(trim($user_info['name']));
                    $updated_user_details["name"] = $updated_user_info["contact_name_first"] ." ". $updated_user_info["contact_name_last"];
                    $updated_user_info["contact_nationality"] = isset($updated_guest_details['nationality']) ? $updated_guest_details['nationality'] : $user_info['nationality'];
                    $updated_user_info["contact_phone"] = isset($updated_guest_details['phone_number']) ? $updated_guest_details['phone_number'] : $user_info['phone_no'];
                    $updated_user_info['contact_address']['country'] = isset($updated_guest_details['country_of_residence']) ? $updated_guest_details['country_of_residence'] : $updated_user_info['contact_address']['country'];
                    $updated_user_details["email"] = $updated_user_info["contact_email"] = isset($updated_guest_details['email_id']) ? $updated_guest_details['email_id'] : $user_info['email'];
                    $extra_booking_info['order_contact'] = $updated_user_info;
                    $updated_user_details["id"] = $user_info['id'];
                }
                $new_ph_no = isset($updated_guest_details['phone_number']) ? $updated_guest_details['phone_number'] : $user_info['phone_no'];
                $logs['new_info_'.$i] = $extra_booking_info;
                $logs['coded_new_info_'.$i] = addslashes(json_encode($extra_booking_info));
                $update_query = "update prepaid_tickets set extra_booking_information = '" . addslashes(json_encode($extra_booking_info)) . "', action_performed = CONCAT(action_performed, ', " . $action_performed . "'), "
                        . " guest_emails = '" . $updated_user_details["email"] . "', guest_names = '" . $updated_user_details["name"] . "'"
                        . "  where prepaid_ticket_id = '" . $pr_entry['prepaid_ticket_id'] . "'";
                $this->query($update_query);
                $logs['pt_update_query_'.$i] = $update_query;
                $update_db2_query = "update prepaid_tickets set extra_booking_information = '" . addslashes(json_encode($extra_booking_info)) . "', phone_number = '" . $new_ph_no . "', action_performed = CONCAT(action_performed, ', " . $action_performed . "'), "
                        . " passport_number = '" . $updated_user_details["id"] . "', secondary_guest_email = '" . $updated_user_details["email"] . "', secondary_guest_name  = '" . $updated_user_details["name"] . "', "
                        . " guest_emails = '" . $updated_user_details["email"] . "', guest_names = '" . $updated_user_details["name"] . "' "
                        . " where prepaid_ticket_id = '" . $pr_entry['prepaid_ticket_id'] . "'";
                $db2_queries[] = $update_db2_query;
            }
            //queue to update DB2
            if (UPDATE_SECONDARY_DB && !empty($db2_queries)) {
                // Load AWS library.
                require_once 'aws-php-sdk/aws-autoloader.php';
                // Load SNS library.
                $this->load->library('Sns');
                $sns_object = new \Sns();
                // Load SQS library.
                $this->load->library('Sqs');
                $sqs_object = new \Sqs();
                $request_array['db2'] = $db2_queries;
                $request_array['action'] = "update_guest_details";
                $request_array['pt_ids_for_BG'] = !empty($prepaid_ticket_ids) ? $prepaid_ticket_ids : array();
                $request_array['write_in_mpos_logs'] = 1;
                $request_string = json_encode($request_array);
                $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;
                if (LOCAL_ENVIRONMENT == 'Local') {
                    local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                } else {
                    // This Fn used to send notification with data on AWS panel.
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                    // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                    if ($MessageId) {
                        $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }
                }
            }
        }
        $response['status'] = 1;
        $response['message'] = "success";
        $MPOS_LOGS['update_guest_details'] = $logs;
        return $response;
    }    
        
    /**
     * @Name remove_pass
     * @Purpose used to remove a pass from a user
     * in first case (deactivate_pass => 0), we will remove this pass from particular tickets having ids specified in related IDs
     * in another case (deactivate_pass => 1), we will remove pass from all the ids (specified in related IDs), It will release pass from all orders.
     * @return status and data 
     *      status 1 or 0
     *      message
     * @param 
     *      $req - array() - API request (same as from APP) + user_details
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 19 nov 2019
     */
    function remove_pass($req = array()) 
    {
        global $MPOS_LOGS;
        global $internal_logs;
        $current_date_time = gmdate("Y-m-d H:i:s");
        $app_flavour = $req['app_flavour'];
        $bleep_pass_no = $req['bleep_pass_no'];
        $related_ids = $req['booking_ids'];
        $deactivate_pass = $req['deactivate_pass'];
        $action_performed = $app_flavour . '_DEACT';
        if (!empty($related_ids)) {
            $update_pt_db1 = 'bleep_pass_no = "", museum_cashier_id = "", '
                    . ' museum_cashier_name = "", scanned_at = "", redeem_date_time = "1970-01-01 00:00:01", '
                    . ' action_performed = CONCAT(action_performed, ", ' . $action_performed . '") ';
            $update_pt_db2 = ', pos_point_id_on_redeem = 0 , pos_point_name_on_redeem = "", voucher_updated_by = "", voucher_updated_by_name = "", redeem_method = "" ';
            $where = ' bleep_pass_no = "' . $bleep_pass_no . '" and prepaid_ticket_id in (' . implode(',', $related_ids) . ') ';
            $this->query('UPDATE prepaid_tickets SET ' . $update_pt_db1 .' where '. $where);
            $logs['pt_update_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
            if ($deactivate_pass == 1) {
                $this->query('update bleep_pass_nos set status="0", last_modified_at = "' . $current_date_time . '" where pass_no = "' . $bleep_pass_no . '"');
            }
            $db2_queries[] = 'UPDATE prepaid_tickets SET ' . $update_pt_db1 .$update_pt_db2 .' where '. $where;
            $update_vt_query = 'UPDATE visitor_tickets SET scanned_pass = "", redeem_method = "", visit_date = UNIX_TIMESTAMP(visit_date_time), '
                    . ' voucher_updated_by = "", voucher_updated_by_name = "", action_performed = CONCAT(action_performed, ", ' . $action_performed . '") where transaction_id in (' . implode(',', $related_ids) . ') and scanned_pass = "' . $bleep_pass_no . '"';
            $db2_queries[] = $update_vt_query;
            //queue to update DB2
            if (UPDATE_SECONDARY_DB && !empty($db2_queries)) {
                // Load AWS library.
                require_once 'aws-php-sdk/aws-autoloader.php';
                // Load SNS library.
                $this->load->library('Sns');
                $sns_object = new \Sns();
                // Load SQS library.
                $this->load->library('Sqs');
                $sqs_object = new \Sqs();
                $request_array['db2'] = $db2_queries;
                $request_array['write_in_mpos_logs'] = 1;
                $request_array['action'] = "remove_pass";
                $request_array['pt_ids_for_VT_BG'] = $request_array['pt_ids_for_BG'] = !empty($related_ids) ? $related_ids : array();
                $request_string = json_encode($request_array);
                $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;
                if (LOCAL_ENVIRONMENT == 'Local') {
                    local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                } else {
                    // This Fn used to send notification with data on AWS panel.
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                    // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                    if ($MessageId) {
                        $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }
                }
            }
            $response['status'] = 1;
            $response['message'] = "success";
        } else {
            $response['status'] = 0;
            $response['message'] = "invalid request";
        }
        $MPOS_LOGS['remove_pass'] = $logs;
        return $response;
    }
     
    /**
     * @Name get_guests_details
     * @Purpose used to get guest details from all data
     * @return 
     * @param 
     *      $guest_names -> name of guest,
     *      $guest_emails -> email of guest,
     *      $extra_booking_information -> from DB (json format), 
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 27 march 2020
     */
    private function get_guests_details($guest_names = '', $guest_emails = '', $passport = '', $extra_booking_information = '', $contact_uid = '') {
        $extra_booking_info = json_decode(stripslashes($extra_booking_information), true);
        if (isset($extra_booking_info['per_participant_info']) && !empty($extra_booking_info['per_participant_info'])) {
            $person_detail = $extra_booking_info['per_participant_info'];
            if ($passport == '') {
                $passport = ($person_detail['id'] != '' && $person_detail['id'] != null) ? $person_detail['id'] : '';
            }
            $person_detail['email'] = ($person_detail['email'] != '' && $person_detail['email'] != null) ? $person_detail['email'] : '';
            $person_detail['name'] = ($person_detail['name'] != null && $person_detail['name'] != '') ? $person_detail['name'] : '';
            $name = ($contact_uid != '') ? ucfirst(trim($guest_names))  : $person_detail['name'];
            $email = ($contact_uid != '') ? $guest_emails  : $person_detail['email'];
            $key = strtolower(trim($email)) . '_' . $passport . '_' . strtolower(trim($name));
            $guests_info[$key] = array(
                'name' => $name,
                'email_id' => $email,
                'contact_uid' => ($contact_uid != '' && $contact_uid != null) ? $contact_uid : "",
                'dob' => ($person_detail['date_of_birth'] != '' && $person_detail['date_of_birth'] != NULL) ? date("Y-m-d", strtotime($person_detail['date_of_birth'])) : '',
                'mode_of_transport' => isset($person_detail['mode_of_transport']) ? $person_detail['mode_of_transport'] : '',
                'passport_no' => $passport,
                'phone_number' => isset($person_detail['phone_no']) ? $person_detail['phone_no'] : '',
                'departure' => isset($person_detail['departure']) ? $person_detail['departure'] : '',
                'arrival' => isset($person_detail['arrival']) ? $person_detail['arrival'] : '',
            );
        } else if (isset($extra_booking_info['order_contact']) && !empty($extra_booking_info['order_contact'])) {
            $order_contact_details = $extra_booking_info['order_contact'];
            $order_contactname = $order_contact_details['contact_name_first'] . " " . $order_contact_details['contact_name_last'];
            $pt_name = ($order_contactname != ' ' && $order_contactname != '' && $order_contactname != NULL) ? $order_contactname : $guest_names;
            $name = ($contact_uid != '') ? ucfirst(trim($guest_names)) : $pt_name;
            $pt_email = ($order_contact_details['contact_email'] != '' && $order_contact_details['contact_email'] != NULL) ? $order_contact_details['contact_email'] : $guest_emails;
            $email = ($contact_uid != '') ? ucfirst(trim($guest_emails)) : $pt_email;
            $key = strtolower(trim($email)) . '_' . $passport . '_' . strtolower(trim($name));
            $guests_info[$key] = array(
                'name' => ($name != null) ? ucfirst(trim($name)) : '',
                'email_id' => ($email != null) ? $email : '',
                'contact_uid' => ($contact_uid != '' && $contact_uid != null) ? $contact_uid : "",
                'passport_no' => $passport,
                'phone_number' => ($order_contact_details['contact_phone'] != '' && $order_contact_details['contact_phone'] != NULL) ? $order_contact_details['contact_phone'] : '',
                'nationality' => ($order_contact_details['contact_nationality'] != '' && $order_contact_details['contact_nationality'] != NULL) ? $order_contact_details['contact_nationality'] : '',
                'country_of_residence' => ($order_contact_details['contact_address']['country'] != '' && $order_contact_details['contact_address']['country'] != NULL) ? $order_contact_details['contact_address']['country'] : '',
            );
        }
        return $guests_info;
    }

    /* #endregion Host App Module : Covers all the api's used in host app */
}

?>