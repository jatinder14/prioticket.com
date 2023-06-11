<?php

/**
 * @Name     : Affiliate model
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 27th Jan 2023
 */
namespace Prio\Traits\V2\Models;
use \Prio\helpers\V1\common_error_specification_helper;
Trait TAffiliate_model
{
    #region Function to set variable under constuction 
    function __construct()
    {
        parent::__construct();
        $this->load->model('V1/common_model');
    }
    #endregion Function to set variable under constuction

    /*
     * @Name get_affiliates_listing
     * @Purpose : to return affiliates list  corresponding to reseller 
    */

    function affiliates($request)
    {
        global $MPOS_LOGS;
        try {
            $reseller_id  = $request['reseller_id'];
            $page = !empty($request['page']) ? $request['page'] : 1;
            $item_per_page = !empty($request['items_per_page']) ? $request['items_per_page'] : 10;
            $search_param = $request['affiliate_search_query'];
            $offset = ($page - 1) * $item_per_page;
            $where =  'reseller_id  = ' . $this->primarydb->db->escape($reseller_id) . ' AND cashier_type = "5" AND IsActive ="1" ';
            if ($search_param != '') {
                $where .= ' AND (cod_id LIKE "%' . $search_param . '%" OR company LIKE "%' . $search_param . '%" )';
            }
            $affiliates_list = $this->find( //fetch affiliates of reseller
                'qr_codes',
                array(
                    'select' => 'cod_id as affiliate_id, company as affiliate_name',
                    'where' => $where
                ),
                'array'
            );
            $logs['affiliates_query_' . date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
            $logs['affiliates_list_' . date("Y-m-d H:i:s")] = $affiliates_list;
            if(!empty($affiliates_list)){
                $final_arr = array_slice($affiliates_list, $offset, $item_per_page);
            }
            $MPOS_LOGS['affiliate_listing'] = $logs;
            return array(
                "kind" => "affiliate",
                "current_item_count" => ($item_per_page < count($final_arr) ? $item_per_page : count($final_arr)),
                "items_per_page" => (int) $item_per_page,
                "start_index" => $page * $item_per_page - ($item_per_page - 1),
                "total_items" => count($affiliates_list),
                "page_index" => !empty($page) ? (int) $page : 1,
                "total_pages" => ceil(count($affiliates_list) / $item_per_page),
                "items" =>  !empty($final_arr) ? $final_arr : array()
            );
        } catch (\Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['affiliate_listing'] = $logs;
            return $logs['exception'];
        }
    }

    /**
     * @Name affiliate_tickets_listing
     * @Purpose : to return affiliates ticket list  corresponding to affiliate 
     */
    function affiliate_tickets_listing($request)
    {
        global $MPOS_LOGS, $logs;
        try {
            $flag_data = $request['flags'];
            $reseller_id = $request['reseller_id'];
            $affiliate_id  = $request['affiliate_id'];
            $page = !empty($request['page']) ? $request['page'] : 1;
            $item_per_page = !empty($request['items_per_page']) ? $request['items_per_page'] : 10;
            $search_param = $request['affiliate_search_query'];
            $offset = ($page - 1) * $item_per_page;
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
            if (empty($flag_data)) {
                $where =  'mec_id IN (' . implode(',', array_unique($affiliate_ticket_ids_array)) . ')';
                if ($search_param != '') {
                    $where .= ' AND (mec_id LIKE "%' . $search_param . '%" OR postingEventTitle LIKE "%' . $search_param . '%" )';
                }

                $where .= ' LIMIT  ' . $offset . ', ' . $item_per_page . '';
                $mode_query = " Select mec_id as id , postingEventTitle as title ,is_reservation, startTime, endTime, startDate, endDate from modeventcontent where " . $where;
                $mode_event_content_details = $this->primarydb->db->query($mode_query)->result_array();
                if(!empty($mode_event_content_details)){
                    foreach ($mode_event_content_details as $records) {
                        $items[] = array(
                            'product_id' => $records['id'],
                            'product_title' => $records['title'],
                            'product_availability' => $records['is_reservation'] == "1" ? true : false
                        );
                    }
                }
                $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                return array(
                    "kind" => "product",
                    "current_item_count" => ($item_per_page < count($mode_event_content_details) ? $item_per_page : count($mode_event_content_details)),
                    "items_per_page" => (int) $item_per_page,
                    "start_index" => $page * $item_per_page - ($item_per_page - 1),
                    "total_items" => count($mode_event_content_details),
                    "page_index" => !empty($page) ? (int) $page : 1,
                    "total_pages" => ceil(count($mode_event_content_details) / $item_per_page),
                    "items" => !empty($items) ? $items : array()
                );
            } else {
                $flag_data = explode(',', $flag_data);
                $main_ticket = "Select entity_type, entity_id from flag_entities where entity_type In (5,8)" . ' AND item_id IN (' . implode(',', array_unique($flag_data)) . ')';
                $main_ticket_query = $this->primarydb->db->query($main_ticket)->result_array();
                $logs['main_ticket_query_' . date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                $logs['main_ticket_' . date("Y-m-d H:i:s")] =  $main_ticket_query;
                if(!empty($main_ticket_query)){
                    foreach ($main_ticket_query as $main_ticket) {
                        if ($main_ticket['entity_type'] == 5) {
                            $entities_ids_array[] = $main_ticket['entity_id'];
                        } elseif ($main_ticket['entity_type'] == 8) {
                            $timeslot['date'] = substr($main_ticket['entity_id'], 0, 4) . "-" . substr($main_ticket['entity_id'], 4, 2) . "-" . substr($main_ticket['entity_id'], 6, 2);
                            $timeslot['from_time'] = substr($main_ticket['entity_id'], 8, 2) . ":" . substr($main_ticket['entity_id'], 10, 2);
                            $timeslot['to_time'] = substr($main_ticket['entity_id'], 12, 2) . ":" . substr($main_ticket['entity_id'], 14, 2);
                            $timeslot['shared_capacity_id'] = substr($main_ticket['entity_id'], 16);
                        }
                    }
                }
                $tour_date = '';
                if (isset($timeslot)) {
                    $tour_date = $timeslot['date'];
                }
                $get_dependencies_query = "Select booking_start_time , booking_end_time from dependencies where main_ticket_id" . ' IN (' . implode(',', $entities_ids_array) . ') ';
                $get_dependencies = $this->primarydb->db->query($get_dependencies_query)->result_array();
                if (!empty($get_dependencies)) {
                    $start_time = $get_dependencies[0]['booking_start_time'] ?? "00:00";
                    $start_time = json_decode($start_time);
                    foreach ($start_time as $values) {
                        foreach ($values as $key => $val) {
                            $sub_tkt_arr[] = $key;
                            $timetoadd = '';
                            if ($val->hours > 0) {
                                $timetoadd .= '+' . $val->hour . ' hour ';
                            }
                            if ($val->mins > 0) {
                                $timetoadd .= '+' . $val->mins . ' minutes ';
                            }
                            if ($val->days > 0) {
                                $timetoadd .= '+' . $val->days . ' day ';
                            }
                            $start_date[$key] = date('Y-m-d', strtotime($timetoadd, strtotime($tour_date)));
                        }
                    }
                    $end_time = $get_dependencies[0]['booking_end_time'] ?? "00:00";
                    $end_time = json_decode($end_time);
                    foreach ($end_time as $values) {
                        foreach ($values as $key => $val) {
                            $timetoadd = '';
                            if ($val->hours > 0) {
                                $timetoadd .= '+' . $val->hour . ' hour ';
                            }
                            if ($val->mins > 0) {
                                $timetoadd .= '+' . $val->mins . ' minutes ';
                            }
                            if ($val->days > 0) {
                                $timetoadd .= '+' . $val->days . ' day ';
                            }
                            $end_date[$key] = date('Y-m-d', strtotime($timetoadd, strtotime($tour_date)));
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
                $pre_query = " Select ticket_id as id , visitor_group_no , title , selected_date ,from_time , to_time from  prepaid_tickets where " . $where;
                $logs['prepaid_query_' . date("Y-m-d H:i:s")] =  $pre_query;
                $prepaid_ticket_details = $this->primarydb->db->query($pre_query)->result_array();
                $logs['addons_tickets_' . date("Y-m-d H:i:s")] = $prepaid_ticket_details;
                $is_reservation = "0";
                if(!empty($get_addons)){
                    foreach ($get_addons as $addonticket) {
                        if(!empty($prepaid_ticket_details)){
                            foreach ($prepaid_ticket_details as $addons) {
                                if ($addons['from_time'] == '0') {
                                    $addons['from_time'] = "00:00";
                                }
                                if ($addons['to_time'] == '0') {
                                    $addons['to_time'] = "00:00";
                                }
                                $timezone = $this->fetch_timezone($addons['id']);
                                $start_datetime = $addons['selected_date'] . ' ' . $addons['from_time'];
                                $end_datetime = $addons['selected_date'] . ' ' . $addons['to_time'];
                                foreach($timezone as $val){
                                    $start_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $start_datetime);
                                    $end_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $end_datetime);
                                }            
                                if (($addons['id'] == $addonticket['addon_mec_id']) && (strtotime($addons['selected_date']) >= strtotime($start_date[$addonticket['mec_id']])) && (strtotime($addons['selected_date']) <= strtotime($end_date[$addonticket['mec_id']]))) {
                                    if ($addons['from_time'] == '0' && $addons['to_time'] == '0') {
                                        $is_reservation = "0";
                                    } else {
                                        $is_reservation = "1";
                                    }
                                    $addons_listing[$addons['id'] . '_' . $addons['selected_date'] . '_' . $addons['from_time'] . '_' . $addons['to_time']] =  array(
                                        'product_id' => $addons['id'],
                                        'product_title' => $addons['title'],
                                        'product_availability' => $is_reservation == "1" ? true : false,
                                        'product_availability_from_date_time' => $start_datetime,
                                        'product_availability_to_date_time' => $end_datetime
                                    );
                                }
                            }
                        }
                    }
                    foreach ($addons_listing as $addons) {
                        $addons_array[] = $addons;
                    }
                    // Get the subset of records to be displayed from the array
                    $final_arr = array_slice($addons_array, $offset, $item_per_page);
                    $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
                }
            }
        }
        $response = array(
            "kind" => "product",
            "current_item_count" => ($item_per_page < count($final_arr) ? $item_per_page : count($final_arr)),
            "items_per_page" => (int) $item_per_page,
            "start_index" => $page * $item_per_page - ($item_per_page - 1),
            "total_items" => count($addons_array),
            "page_index" => !empty($page) ? (int) $page : 1,
            "total_pages" => ceil(count($addons_array) / $item_per_page),
            "items" => !empty($final_arr) ? $final_arr : array()
        );
        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['affiliate_tickets_listing'] = $logs;
            return $logs['exception'];
        }
        return $response;
    }

    /**
     * @Name affiliate_ticket_amount
     * @Purpose : to return affiliates amount  corresponding to affiliate tickets 
     */
    function affiliate_ticket_amount($req, $ticket_id)
    {
        global $MPOS_LOGS, $logs;
        try {
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['affiliate_id'];
            $start_date = $req['from_date'];
            $end_date = $req['to_date'];
            $is_reservation = $req['is_reservation'];
            $refunded_ids = $order_payment_arr = array();
            if ($affiliate_id != '' && $start_date != '' && $end_date != '' && $ticket_id !=  '' && $is_reservation != '') {
                // fetch total amount
                $timezone = $this->fetch_timezone($ticket_id);
                foreach($timezone as $val){
                    $start_date_time_zone = $this->get_timezone_from_text($val['local_timezone_name'], $start_date);
                    $end_date_time_zone = $this->get_timezone_from_text($val['local_timezone_name'], $end_date);
                }
                list($start_hours, $start_minutes) = explode(':', $start_date_time_zone);
                list($end_hours, $end_minutes) = explode(':', $end_date_time_zone);
                $start_seconds = $start_hours * 60 * 60 + $start_minutes * 60;
                $end_seconds = $end_hours * 60 * 60 + $end_minutes * 60;
                $start_timestamp = strtotime($start_date) - $start_seconds;
                $end_timestamp = strtotime($end_date) - $end_seconds;
                $start_date = date('Y-m-d', $start_timestamp) . ' ' . date('H:i:s', $start_timestamp);
                $end_date = date('Y-m-d', $end_timestamp) . ' ' . date('H:i:s', $end_timestamp);
                $startdatetime =  $start_date;
                $enddatetime  =  $end_date;
                if ($is_reservation == 1) {
                    $date_check = ' CAST(CONCAT(selected_date, " ", from_time) as DATETIME) >= "' . $startdatetime . '" AND CAST(CONCAT(selected_date, " ", to_time)  as DATETIME) <= "' . $enddatetime . '"  ';
                } else if ($is_reservation == 0) {
                    $date_check = ' CAST(concat(DATE(booking_selected_date), " ", from_time) as DATETIME) >= "' . $startdatetime . '" AND CAST(concat(DATE(booking_selected_date), " ", to_time) as DATETIME) <= "' . $enddatetime . '" ';
                }
                $where = ' partner_id = "' . $affiliate_id . '" AND ticketId = "' . $ticket_id . '" AND ' . $date_check  . ' AND (row_type = "11" or row_type = "19" ) and (is_refunded = "0" or is_refunded= "2")';
                $vt_query_result = $secondarydb->query('Select partner_gross_price, ticketId, ticket_title, transaction_id, is_refunded, vt_group_no, selected_date, version from visitor_tickets where ' . $where)->result_array();
                $logs['vt_query_' . date("Y-m-d H:i:s")] = $secondarydb->last_query();
                if(!empty($vt_query_result)){
                    foreach ($vt_query_result as $val) {
                        $vgn_array[] = $val['vt_group_no'];
                        if ($val['is_refunded'] == '2') {
                            array_push($refunded_ids, $val['transaction_id']);
                        }
                    }
                }
                $affiliate_details = $this->find(  //fetch currency of affiliate
                    'qr_codes',
                    array(
                        'select' => 'currency_code',
                        'where' => 'cod_id  = ' . $this->primarydb->db->escape($affiliate_id) . ''
                    ),
                    'array'
                );
                if (!empty($vgn_array)) {
                    $vt_data = $secondarydb->query("Select  partner_gross_price, ticketId, ticket_title, transaction_id, is_refunded, vt_group_no, selected_date, version, from_time, to_time, booking_selected_date, row_type from visitor_tickets where vt_group_no IN (" . implode(",", $vgn_array) . ") AND (row_type = '11' or row_type = '19') and partner_id = '" . $affiliate_id . "' AND ticketId = '" . $ticket_id . "' AND (is_refunded= '0' Or is_refunded = '2')")->result_array();
                    if (!empty($vt_data)) {
                        foreach ($vt_data as $v_data) {
                            $vgn_details[$v_data['vt_group_no'] . '_' . $v_data['transaction_id']][] = $v_data;
                        }
                        $versions = array();
                        foreach ($vgn_details as $db_key => $db_rows) {
                            foreach ($db_rows as $db_row) {
                                if (!isset($versions[$db_key]) || (isset($versions[$db_key]) && $db_row['version'] > $versions[$db_key]['version'])) {
                                    $versions[$db_key] = $db_row;
                                }
                            }
                        }
                    }
                }
                if (!empty($versions)) {
                    //get row type wise  price
                    foreach ($versions as $data) {
                        $row_wise_data[$data['ticketId']][$data['row_type']] = $data;
                    }
                    // fetch paid_amount
                    $paid_where = ' partner_id = ' . $affiliate_id . ' AND ticket_id = "' . $ticket_id . '" AND CAST(CONCAT(start_date, " ", from_time) as DATETIME) >= "' . $startdatetime . '" AND  CAST(CONCAT(end_date, " ", to_time)  as DATETIME) <= "' . $enddatetime . '" AND partner_type = "2"';
                    $order_payment_query_result = $secondarydb->query('Select order_amount, ticket_id, id, version, deleted from order_payment_details where ' . $paid_where)->result_array();
                    if(!empty($order_payment_query_result)){
                        $order_payment_result = $this->get_max_version_data($order_payment_query_result, 'id');
                        if (!empty($order_payment_result)) {
                            foreach ($order_payment_result  as $details) {
                                if ($details['deleted'] == "0") {
                                    $order_payment_arr[$details['ticket_id']]['paid_amount'] = $order_payment_arr[$details['ticket_id']]['paid_amount'] + $details['order_amount'];
                                }
                            }
                        }
                    }

                    $logs['order_payment_' . date("Y-m-d H:i:s")] = $secondarydb->last_query();
                    $total_affiliate_fee = 0;
                    $total_transaction_fee = 0;
                    $quantity = 0;
                    foreach ($versions as $data) {
                        if (
                            ($is_reservation == 1 && strtotime($data['selected_date'] . " " . $data['from_time']) >=  strtotime($startdatetime)  &&  strtotime($data['selected_date'] . " " . $data['to_time']) <=  strtotime($enddatetime))
                            || ($is_reservation == 0  && strtotime(date("Y-m-d", strtotime($data['booking_selected_date']))) >=  strtotime($start_date)  &&  strtotime(date("Y-m-d", strtotime($data['booking_selected_date']))) <=  strtotime($end_date))
                        ) {
                            $unit_price = $row_wise_data[$data['ticketId']]['11']['partner_gross_price'] ?? 0;
                            if (!in_array($data['transaction_id'], $refunded_ids)) {
                                if ($data['row_type'] == '11') {
                                    $total_affiliate_fee = $total_affiliate_fee +  $data['partner_gross_price'];
                                    $quantity = $quantity + 1;
                                }
                                if ($data['row_type'] == '19') {
                                    $total_transaction_fee = $total_transaction_fee +  $data['partner_gross_price'];
                                }
                                $ticket_wise_amount[$data['ticketId']]['product_id'] =  $data['ticketId'];
                                $ticket_wise_amount[$data['ticketId']]['product_title'] =  $data['ticket_title'];
                                $ticket_wise_amount[$data['ticketId']]['product_currency'] = isset($affiliate_details[0]['currency_code']) ? $affiliate_details[0]['currency_code'] : '';
                                $ticket_wise_amount[$data['ticketId']]['total_affiliate_fee'] = (string) $total_affiliate_fee;
                                $ticket_wise_amount[$data['ticketId']]['total_transaction_fee'] = (string) $total_transaction_fee;
                                $ticket_wise_amount[$data['ticketId']]['product_quantity'] =  (string)  $quantity;
                                $ticket_wise_amount[$data['ticketId']]['unit_price'] =  (string) $unit_price;
                                $ticket_wise_amount[$data['ticketId']]['total_paid_amount'] = isset($order_payment_arr[$data['ticketId']]['paid_amount']) ? (string) $order_payment_arr[$data['ticketId']]['paid_amount'] : "0";
                            } else {
                                $ticket_wise_amount[$data['ticketId']]['product_id'] =  $data['ticketId'];
                                $ticket_wise_amount[$data['ticketId']]['product_title'] =  $data['ticket_title'];
                                $ticket_wise_amount[$data['ticketId']]['product_currency'] = isset($affiliate_details[0]['currency_code']) ? $affiliate_details[0]['currency_code'] : '';
                                $ticket_wise_amount[$data['ticketId']]['product_quantity'] =  isset($ticket_wise_amount[$data['ticketId']]['product_quantity']) ? (string) $ticket_wise_amount[$data['ticketId']]['product_quantity'] : "0";
                                $ticket_wise_amount[$data['ticketId']]['total_affiliate_fee'] = isset($total_affiliate_fee) ? (string) $total_affiliate_fee  : "0";
                                $ticket_wise_amount[$data['ticketId']]['total_transaction_fee'] = isset($total_transaction_fee) ? (string)$total_transaction_fee  : "0";
                                $ticket_wise_amount[$data['ticketId']]['total_paid_amount'] = isset($order_payment_arr[$data['ticketId']]['paid_amount']) ? (string) $order_payment_arr[$data['ticketId']]['paid_amount'] : "0";
                            }
                        }
                    }
                    $new_ticket_wise_amount = isset($ticket_wise_amount) && !empty($ticket_wise_amount) ? array_values($ticket_wise_amount) : array();
                    $logs['new_ticket_wise_amount_' . date("Y-m-d H:i:s")] = $new_ticket_wise_amount;
                    $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                    $response = array(
                        "kind" => "affiliate_amount",
                        "items" =>  !empty($new_ticket_wise_amount) ? $new_ticket_wise_amount[0] : (object) array()
                    );
                } else {
                    $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                    $response = Common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                    $response['errors'] = array('No data found.');
                }
            } else {
                $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
                $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                $response['errors'] = array('Invalid Request.');
            }
            return $response;
        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_tickets_amount'] = $logs;
            return $logs['exception'];
        }
    }

    /**
     * @Name get_affiliates_ticket_listing
     * @Purpose : to return pay amount corresponding to affiliate 
     */
    function get_affiliate_pay_amount($req)
    {
        global $MPOS_LOGS;
        try {
            $logs['request_' . date("Y-m-d H:i:s")] =  $req;
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['request_array']['data']['payment']['affiliate_id'];
            $shift_id  = $req['request_array']['data']['payment']['shift_id'];
            $ticket_id = $req['request_array']['data']['payment']['product_id'];
            $payment_from_date_time = $req['request_array']['data']['payment']['payment_from_date_time'];
            $payment_to_date_time = $req['request_array']['data']['payment']['payment_to_date_time'];
            $timezone = $this->fetch_timezone($ticket_id);
            foreach($timezone as $val){
                $created_at = date("Y-m-d") . ' ' . date("H:i:s");
                $created_at = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $created_at);
                $start_date_time_zone = $this->get_timezone_from_text($val['local_timezone_name'], $payment_from_date_time);
                $end_date_time_zone = $this->get_timezone_from_text($val['local_timezone_name'], $payment_to_date_time);
            }
            list($start_hours, $start_minutes) = explode(':', $start_date_time_zone);
            list($end_hours, $end_minutes) = explode(':', $end_date_time_zone);
            $start_seconds = $start_hours * 60 * 60 + $start_minutes * 60;
            $end_seconds = $end_hours * 60 * 60 + $end_minutes * 60;
            $start_timestamp = strtotime($payment_from_date_time) - $start_seconds;
            $end_timestamp = strtotime($payment_to_date_time) - $end_seconds;
            $from_date = date('Y-m-d', $start_timestamp);
            $from_time = date('H:i:s', $start_timestamp);
            $to_date = date('Y-m-d', $end_timestamp) ;
            $to_time =  date('H:i:s', $end_timestamp);
            $payment_type = $req['request_array']['data']['payment']['payment_type'];
            $payment_amount = $req['request_array']['data']['payment']['payment_amount'];
            $payment_reason = $req['request_array']['data']['payment']['payment_reason'];
            if ($affiliate_id != '' && $ticket_id != '' && $from_date != '' && $to_date != '' && $from_time != '' && $to_time != '' && $shift_id != '') {
                //create  array for order payment details
                $order_payment_detail_array = array();
                $order_payment_detail_array['id'] = $this->generate_uid();
                $order_payment_detail_array['order_amount'] = $payment_amount > 0 ? $payment_amount  : 0;
                $order_payment_detail_array['order_total'] = $payment_amount > 0 ? $payment_amount  : 0;
                $order_payment_detail_array['partner_id'] = $affiliate_id;
                $order_payment_detail_array['partner_type'] = "2";
                $order_payment_detail_array['ticket_id'] = $ticket_id;
                $order_payment_detail_array['start_date'] = $from_date;
                $order_payment_detail_array['end_date'] = $to_date;
                $order_payment_detail_array['from_time'] = $from_time;
                $order_payment_detail_array['to_time'] = $to_time;
                $order_payment_detail_array['notes'] = $payment_reason;
                $order_payment_detail_array['method'] =  $payment_type;
                $order_payment_detail_array['psp_type'] = "15";
                $order_payment_detail_array['status'] = "1";
                $order_payment_detail_array['is_active'] = "1";
                $order_payment_detail_array['shift_id'] = $shift_id;
                $order_payment_detail_array['reseller_id'] = $req['reseller_id'];
                $order_payment_detail_array['distributor_id'] = $req['distributor_id'];
                $order_payment_detail_array['cashier_id'] = $req['users_details']['dist_cashier_id'];
                $order_payment_detail_array['cashier_name'] = $req['users_details']['cashier_name'];
                $order_payment_detail_array['cashier_email'] = $req['cashier_email'];
                $order_payment_detail_array['updated_at'] =  date("Y-m-d H:i:s");
                $order_payment_detail_array['created_at'] =  date("Y-m-d H:i:s");
                $last_inserted_id = $secondarydb->insert('order_payment_details', $order_payment_detail_array);
                $logs['order_payment_query_' . date("Y-m-d H:i:s")] = $secondarydb->last_query();
                if (!empty($last_inserted_id)) {
                    $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
                    $from_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $payment_from_date_time);
                    $to_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $payment_to_date_time);
                    $response = array(
                        "kind" => "payment",
                        "payment" => array(
                            "product_id" => (string) $ticket_id,
                            "payment_from_date_time" => $from_datetime,
                            "payment_to_date_time" => $to_datetime,
                            "payment_amount" => (string) $payment_amount,
                            "payment_type" => $payment_type,
                            "created_at" => $created_at
                        )
                    );
                } else {
                    $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
                    $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                    $response['errors'] = array('Invalid Request.');
                }
            } else {
                $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                $response['errors'] = array('Invalid Request.');
            }
            return $response;
        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
            return $logs['exception'];
        }
    }

    function get_affiliate_payment_history($req = array())
    {
        global $MPOS_LOGS, $logs;
        try {
            $secondarydb = $this->load->database('secondary', true);
            $MPOS_LOGS['DB'][] = 'DB2';
            $affiliate_id  = $req['affiliate_id'];
            $ticket_id = $req['product_id'];
            $start_date = $req['from_date'];
            $end_date = $req['to_date'];
            $page = !empty($req['page']) ? $req['page'] : 1;
            $item_per_page = !empty($req['items_per_page']) ? $req['items_per_page'] : 10;
            $search_param = $req['affiliate_search_query'];
            $offset = ($page - 1) * $item_per_page;

            $startdate = strtotime($start_date);
            for ($i =  $startdate; $i <=  strtotime($end_date); $i = $i + 86400) {
                $all_dates[] = date("Y-m-d", $i);
            }
            if ($affiliate_id != '' && $ticket_id != '' && $start_date != '' && $end_date != '') {
                //create  array for order payment details
                $paid_where = ' partner_id = ' . $affiliate_id . ' AND ticket_id = "' . $ticket_id . '" AND date(start_date) In("' . implode('","', $all_dates) . '")  AND date(end_date) In("' . implode('","', $all_dates) . '")  AND partner_type = "2"';
                if ($search_param != '') {
                    $paid_where .= ' AND (ticket_id LIKE "%' . $search_param . '%")';
                }
                $order_payment_query_result = $secondarydb->query('Select order_amount as amount_settled, ticket_id, start_date, end_date, created_at, id, deleted, version, from_time, to_time from order_payment_details where ' . $paid_where . ' order by created_at ASC')->result_array();
                $logs['order_payment_query_' . date("Y-m-d H:i:s")] = $secondarydb->last_query();
                if(!empty($order_payment_query_result)){
                    $order_payment_result = $this->get_max_version_data($order_payment_query_result, 'id');
                }
                $timezone = $this->fetch_timezone($ticket_id);
                if(!empty($order_payment_result)){
                    foreach ($order_payment_result as $data) {
                        foreach ($timezone  as $val) {
                            if ($data['deleted'] == "0") {
                                $key = $data['start_date'] . '_' . $data['end_date'];
                                $amount[] = $data['amount_settled'];
                                $new_payment_histor_array[$key]['product_id']      =  $data['ticket_id'];
                                $new_payment_histor_array[$key]['amount_settled'] = (string) array_sum($amount);
                                $from_datetime = $data['start_date'] . ' ' . $data['from_time'];
                                $from_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $from_datetime);
                                $new_payment_histor_array[$key]['amount_settled_from_date_time']     =  $from_datetime;
                                $to_datetime = $data['end_date'] . ' ' . $data['to_time'];
                                $to_datetime = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $to_datetime);
                                $new_payment_histor_array[$key]['amount_settled_to_date_time']       =  $to_datetime;
                                $created_at = $this->getDateTimeWithTimeZone($val['local_timezone_name'], $data['created_at']);
                                $new_payment_histor_array[$key]['created_at']           =  $created_at;
                            }
                        }
                    }                    
                }
                $final_arr = array_slice($new_payment_histor_array, $offset, $item_per_page);
                $MPOS_LOGS['get_affiliate_payment_history'] = $logs;
                $response = array(
                    "kind" => 'payment',
                    "current_item_count" => ($item_per_page < count($final_arr) ? $item_per_page : count($final_arr)),
                    "items_per_page" => (int) $item_per_page,
                    "start_index" => $page * $item_per_page - ($item_per_page - 1),
                    "total_items" => count($new_payment_histor_array),
                    "page_index" => !empty($page) ? (int) $page : 1,
                    "total_pages" => ceil(count($new_payment_histor_array) / $item_per_page),
                    "items" => !empty($new_payment_histor_array) ? array_values($new_payment_histor_array) : array()
                );
            } else {
                $MPOS_LOGS['get_affiliate_payment_history'] = $logs;
                $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                $response['errors'] = array('Invalid Request.');
            }
            return $response;
        } catch (\Exception $ex) {
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_affiliate_pay_amount'] = $logs;
            return $logs['exception'];
        }
    }

    function generate_uid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    function fetch_timezone($ticket_id){
        $mec_query = 'select local_timezone_name ,timezone from modeventcontent where mec_id = "'. $ticket_id .'"';
        $res_arr = $this->primarydb->db->query($mec_query)->result_array();
        return $res_arr;
    }

    function getDateTimeWithTimeZone($supplier_timezone_name = '', $datetime = '', $no_t_formate = 0) { //$supplier_timezone_name = Canada/Newfoundland,    $datetime = 2018-07-26 11:30:00
        if($supplier_timezone_name){
            $time_zone = $this->get_timezone_from_text($supplier_timezone_name, $datetime);
        } else {
            $time_zone = $this->get_timezone_of_date($datetime);
        }
        list($hours, $minutes) = explode(':', $time_zone);
        $seconds = $hours * 60 * 60 + $minutes * 60;
        $timestamp = strtotime( $datetime ) + $seconds;
        if($no_t_formate == 1){
            $date_time = date('Y-m-d H:i:s', $timestamp);
        } else {
            $date_time = date('Y-m-d', $timestamp) . 'T' . date('H:i:s', $timestamp).$time_zone;
        }
        return $date_time;
    }
}
