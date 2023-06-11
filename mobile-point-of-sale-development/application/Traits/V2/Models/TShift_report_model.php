<?php

use phpDocumentor\Reflection\Types\Object_;

/**
 * @Name     : Shift report model
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 8th May 2023
 */
namespace Prio\Traits\V2\Models;
use \Prio\helpers\V1\common_error_specification_helper;
Trait TShift_report_model 
{
    #region Function to set variable under constuction 
    function __construct()
    {
        parent::__construct();
        $this->load->model('V1/common_model');
    }

    function get_payment_type($case)
    {
        switch ($case) {
            case '2':
                $response = "CASH";
                break;
            case '10':
                $response = "VOUCHER";
                break;
            case '12':
                $response = "EXTERNAL";
                break;
            case '9':
                $response = "SPLIT";
                break;
            case '3':
                $response = "GUEST_BILL";
                break;
            default:
                $response = "CARD";
                break;
        }
        return $response ? $response : "";
    }

    function shift_report_for_redeem($data = array())
    {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $date = $data['date'];
            $start_date = $date . ' 00:00:00';
            $end_date = $date . ' 23:59:59';
            if ($data['cashier_type'] == '3' && $data['reseller_id'] != '') {
                $where_cond = 'reseller_id = "' . $data['reseller_id'] . '" and cashier_id = "' . $data['reseller_cashier_id'] . '" ';
            } else {
                $where_cond = 'supplier_id = "' . $data['supplier_id'] . '" and cashier_id = "' . $cashier_id . '" ';
            }
            $redeem_cashiers_details = $this->find('redeem_cashiers_details', array('select' => 'pass_no, visitor_group_no,prepaid_ticket_id, distributor_id,ticket_id, ticket_title,ticket_type, prepaid_ticket_id,age_group,tps_id,price,distributor_type, distributor_partner_id, distributor_partner_name, channel_type, partner_category_id, partner_category_name', 'where' => $where_cond . '  and shift_id = "' . $shift_id . '" and redeem_time >= "' . $start_date . '" and redeem_time <= "' . $end_date . '" and is_addon_ticket = "0"'));
            $pt_data = $this->find('prepaid_tickets', array('select' => 'ticket_id , tps_id, price, is_refunded, quantity', 'where' => 'hotel_id = "' . $data['hotel_id'] . '" and reseller_id = "' . $data['reseller_id'] . '" and shift_id = "' . $shift_id . '" and date(created_date_time) = "' . $date . '" AND is_refunded = "2" AND used = "1"'));
            $refunded_data = array_column($pt_data, 'price');
            $logs['redeem_cashier_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['redeem_cashiers_details_' . date('H:i:s')] = $redeem_cashiers_details;
            if (!empty($redeem_cashiers_details)) {
                $all_vgn = array_unique(array_column($redeem_cashiers_details, 'visitor_group_no'));
                foreach ($redeem_cashiers_details as $prepaid_data) {
                    $ticket_type = strtoupper($prepaid_data['ticket_type']);
                    $total_redeem_price[] = $ticket_redeem_price[$prepaid_data['ticket_id']][] =  $prepaid_data['price'];
                    $ticket_type_data[$prepaid_data['ticket_id']][$prepaid_data['tps_id']] = array(
                        'product_type' => $ticket_type,
                        'product_type_id' => $prepaid_data['tps_id'],
                        'product_type_count' => $ticket_type_data[$prepaid_data['ticket_id']][$prepaid_data['tps_id']]['product_type_count'] + 1,
                        'product_type_pricing' => [
                            "price_total" => (string) number_format($ticket_type_data[$prepaid_data['ticket_id']][$prepaid_data['tps_id']]['product_type_pricing']['price_total'] + $prepaid_data['price'], 2)
                        ]
                    );
                    $ticket_type_data = $this->common_model->sort_ticket_types($ticket_type_data);
                    $tickets_data[$prepaid_data['ticket_id']] = array(
                        'product_id' => (string) $prepaid_data['ticket_id'],
                        'product_title' => $prepaid_data['ticket_title'],
                        'product_price_total' => (string) number_format(array_sum($ticket_redeem_price[$prepaid_data['ticket_id']]), 2),
                        'product_type_details' => (array) array_values($ticket_type_data[$prepaid_data['ticket_id']]),
                    );
                }
                $sql = "Select * from prepaid_extra_options where visitor_group_no In(" . implode(',', $all_vgn) . ")";
                $extra_option_result = $this->primarydb->db->query($sql)->result_array();
                foreach ($extra_option_result as  $pt_eo_data) {
                    $per_ticket_extra_options[$pt_eo_data['ticket_id']][] = array(
                        "option_id" => $pt_eo_data['prepaid_extra_options_id'],
                        "option_name" => $pt_eo_data['main_description'],
                        "option_values" => array(
                            "value_id" => $pt_eo_data['prepaid_extra_options_id'],
                            "value_name" => $pt_eo_data['description'],
                            "value_price" => $pt_eo_data["price"],
                            "value_count" => (int) $pt_eo_data['quantity'],
                            "value_product_type_id" => $pt_eo_data['ticket_price_schedule_id'],
                        ),
                    );
                }
                // ALL redeems tickets
                foreach ($tickets_data as $key => $row) {
                    $redeem_prepaid_data[$key]['product_id'] = (string)$key;
                    $redeem_prepaid_data[$key]['product_title'] = $row['product_title'];
                    $redeem_prepaid_data[$key]['product_price_total'] = (string) $row['product_price_total'];
                    foreach ($row['product_type_details'] as $tps_id => $type) {
                        if (isset($redeem_prepaid_data[$key]['product_type_details'][$tps_id])) {
                            $redeem_prepaid_data[$key]['product_type_details'][$tps_id]['product_type_count'] = $redeem_prepaid_data[$key]['product_type_details'][$tps_id]['product_type_count'] + $type['product_type_count'];
                        } else {
                            $redeem_prepaid_data[$key]['product_type_details'][$tps_id] = $type;
                        }    
                    }
                    if (!empty($per_ticket_extra_options[$key])) {
                        $redeem_prepaid_data[$key]['product_options'] = array_values($per_ticket_extra_options[$key]);
                    }
                }
                $total_redeem_price =  !empty($refunded_data) ? (array_sum($total_redeem_price) - array_sum($refunded_data)) : array_sum($total_redeem_price);
                $redeem_data = array();
                foreach ($redeem_prepaid_data as $type => $data) {
                    $redeem_data["redeem_price_total"] = (string) number_format($total_redeem_price, 2);
                    $redeem_data['redeem_products'][] = (array) $data;
                }
                if (!empty($redeem_data)) {
                    $response = $redeem_data;
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

    function sales_report($data = array())
    {
        global $MPOS_LOGS;
        try {
            $supplier_cashier_id = $data['supplier_cashier_id'];
            $supplier_id = $data['cashier_supplier_id'];
            $date =  $data['cashier_shift_date'];
            $cashier_type = $data['cashierType'];
            $reseller_id = $data['reseller_id'];
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['cashier_shift_id'];
            $reseller_id = $data['user_details']['resellerId'];
            $reseller_cashier_id = $data['user_details']['resellerCashierId'];
            $secondarydb = $this->load->database('secondary', true);
            try {
                //Fetch distributor's data from qr_codes 
                if ($data['resellerId']) {
                    $qr_query = $this->primarydb->db->query("Select reseller_id, reseller_name, company, cod_id from qr_codes where reseller_id = '" . $data['resellerId'] . "'")->result_array();
                } else if ($data['distributor_id']) {
                    $qr_query = $this->primarydb->db->query("Select reseller_id, reseller_name, company, cod_id from qr_codes where cod_id = '" . $data['distributor_id'] . "'")->result_array();
                }
                // Fetch all bookings from  database
                $query = "Select * from prepaid_tickets where reseller_id = '" . $qr_query[0]['reseller_id'] . "' AND hotel_id = '" . $qr_query[0]['cod_id'] . "' AND cashier_id = '" . $cashier_id . "' AND shift_id = '" . $data['cashier_shift_id'] . "' and DATE(created_date_time) ='" . $date . "'";
                $logs['Prepaid_query_' . date("Y-m-d H:i:s")] = $query;
                $res = $secondarydb->query($query)->result_array();
                $result = $this->get_max_version_data($res, 'prepaid_ticket_id');
                $logs['Prepaid_query_result_count_' . date("Y-m-d H:i:s")] = count($result);
                if (!empty($result)) {
                    $shift_data = $this->find('cashier_register', array('select' => 'hotel_name, closing_time, opening_time, sale_by_cash, sale_by_card, sale_by_voucher, opening_cash_balance, status, unbalance_status, closing_cash_balance, note, cashier_register_id', 'where' => 'cashier_id = "' . $cashier_id . '" AND shift_id = "' . $shift_id . '" AND DATE(created_at) = "' . $date . '"'), 'array');
                    foreach ($result as  $pt_data) {
                        $vgn_data[$pt_data['visitor_group_no'] . '_' . $pt_data['ticket_id']][] = $pt_data;
                    }
                    $all_vgn = array_unique(array_column($result, 'visitor_group_no'));
                    $sql = "Select * from prepaid_extra_options where visitor_group_no In(" . implode(',', $all_vgn) . ")";
                    $logs['Prepaid_extra_options_query_' . date("Y-m-d H:i:s")] = $sql;
                    $extra_option_result = $this->primarydb->db->query($sql)->result_array();
                    foreach ($extra_option_result as  $pt_eo_data) {
                        if ($pt_eo_data['variation_type'] == 0) {
                            $eo_vgn_data[$pt_eo_data['visitor_group_no'] . '_' . $pt_eo_data['ticket_id']]['eo_data'][$pt_eo_data['ticket_price_schedule_id']][] = (object) array("description" => $pt_eo_data['description'], "main_description" => $pt_eo_data['main_description'], "price" => $pt_eo_data['price'], "quantity" => $pt_eo_data['quantity'], "refund_quantity" => $pt_eo_data['refund_quantity'], "extra_option_id" => $pt_eo_data['extra_option_id'], "prepaid_extra_options_id" => $pt_eo_data['prepaid_extra_options_id'], "ticket_price_schedule_id" => $pt_eo_data['ticket_price_schedule_id']);
                            $eo_vgn_data[$pt_eo_data['visitor_group_no'] . '_' . $pt_eo_data['ticket_id']]['eo_total_price'] = $eo_vgn_data[$pt_eo_data['visitor_group_no'] . '_' . $pt_eo_data['ticket_id']]['eo_total_price'] + ($pt_eo_data['price'] * ($pt_eo_data['quantity']));
                        }
                        $product_options[$pt_eo_data['visitor_group_no']][] = array(
                            "option_id" => $pt_eo_data['extra_option_id'],
                            "option_name" => $pt_eo_data['main_description'],
                            "option_values" => array(
                                "value_id" => $pt_eo_data['prepaid_extra_options_id'],
                                "value_name" => $pt_eo_data['description'],
                                "value_price" => $pt_eo_data["price"],
                                "value_count" => (int) $product_options[$pt_eo_data['visitor_group_no']]['option_values']['value_count'] + $pt_eo_data['quantity'],
                                "value_product_type_id" => $pt_eo_data['ticket_price_schedule_id'],
                            ),
                        );
                    }
                    $i = 0;
                    foreach ($vgn_data as $vgn_ticket => $data) {
                        $key = $vgn_ticket;
                        $extra_option_amount  = !empty($eo_vgn_data[$key]['eo_total_price']) ?  $eo_vgn_data[$key]['eo_total_price'] : 0;

                        foreach ($data as $ticket_data) {
                            $i++;
                            $discount_array = !empty($ticket_data['extra_discount']) ? unserialize($ticket_data['extra_discount']) : array();
                            $discount_gross_amount = !empty($discount_array['new_discount']) ? $discount_array['new_discount'] : 0;
                            $discount_variation_type = !empty($discount_array['discount_variation_type']) ? $discount_array['discount_variation_type'] : "";
                            if ($ticket_data['is_refunded'] == "0" || $ticket_data['is_refunded'] == "2") {

                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['extra_booking_information'] =  $ticket_data['extra_booking_information'];
                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['extra_discount']          =  $ticket_data['extra_discount'] ? unserialize($ticket_data['extra_discount']) : "";
                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['cashier_discount']       = $ticket_data['quantity'] * $discount_gross_amount;
                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['tps_id']        = $ticket_data['tps_id'];
                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['activation_method']       = $ticket_data['activation_method'];

                                /*#COMMENT : Start Block to handle the activation method wise with key of activation method , ticket id , tps id */
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['extra_booking_information'] =  $ticket_data['extra_booking_information'];
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['extra_discount']          =  $ticket_data['extra_discount'] ? unserialize($ticket_data['extra_discount']) : "";
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['cashier_discount']       = $ticket_data['quantity'] * $discount_gross_amount;
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['tps_id']        = $ticket_data['tps_id'];
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['activation_method']       = $ticket_data['activation_method'];

                                $vgn_arr[$key]['extra_booking_information']       = $ticket_data['extra_booking_information'];
                                $vgn_arr[$key]['prepaid_ticket_id']               = $ticket_data['prepaid_ticket_id'];
                                $vgn_arr[$key]['activated_at']                    = (int) 0;
                                $vgn_arr[$key]['extra_discount']                  = $ticket_data['extra_discount'] ? unserialize($ticket_data['extra_discount']) : "";
                                $vgn_arr[$key]['activated_by']                    = (int) 0;
                                $vgn_arr[$key]['amount']                          = (float) ($vgn_arr[$key]['amount'] + (($ticket_data['price'] + $discount_gross_amount) * $ticket_data['quantity']));
                                $vgn_arr[$key]['booking_date_time']               = $ticket_data['created_date_time'];
                                $vgn_arr[$key]['booking_name']                    = $ticket_data['guest_names'];
                                $vgn_arr[$key]['cancelled_tickets']               = (int) $ticket_data['is_refunded'] == "2" ? $vgn_arr[$key]['cancelled_tickets'] + $ticket_data['quantity'] : 0;
                                $vgn_arr[$key]['cashier_name']                    = $ticket_data['cashier_name'];
                                $vgn_arr[$key]['is_refunded']                     = $ticket_data['is_refunded'];
                                $vgn_arr[$key]['channel_type']                    = $ticket_data['channel_type'];
                                $vgn_arr[$key]['discount_code_type']              = !empty($discount_array['discount_type']) ? $discount_array['discount_type'] : 0;
                                $vgn_arr[$key]['discount_code_amount']            = $discount_gross_amount;
                                $vgn_arr[$key]['discount_variation_type']         = $discount_variation_type;
                                $vgn_arr[$key]['from_time']                       = $ticket_data['from_time'];
                                $vgn_arr[$key]['is_combi_ticket']                 = $ticket_data['is_combi_ticket'];
                                $vgn_arr[$key]['total_combi_discount']            = (float) $ticket_data['combi_discount_gross_amount'];
                                $vgn_arr[$key]['is_extended_ticket']              = (int) 0;
                                $vgn_arr[$key]['used']                            = $ticket_data['used'];
                                $vgn_arr[$key]['order_currency_price']            = $ticket_data['order_currency_price'];
                                $vgn_arr[$key]['museum']                          = $ticket_data['museum_name'];
                                $vgn_arr[$key]['ticket_type']                     = $ticket_data['ticket_type'];
                                $vgn_arr[$key]['distributor_id']                  = $ticket_data['hotel_id'];
                                $vgn_arr[$key]['distributor_name']                = $ticket_data['hotel_name'];
                                $vgn_arr[$key]['reseller_id']                     = $ticket_data['reseller_id'];
                                $vgn_arr[$key]['reseller_name']                   = $ticket_data['reseller_name'];
                                $vgn_arr[$key]['order_id']                        = $ticket_data['visitor_group_no'];
                                $vgn_arr[$key]['payment_method']                  = $ticket_data['activation_method'];
                                $vgn_arr[$key]['pos_point_id']                    = $ticket_data['pos_point_id'];
                                $vgn_arr[$key]['pos_point_name']                  = $ticket_data['pos_point_name'];
                                $vgn_arr[$key]['quantity']                        = $vgn_arr[$key]['quantity'] + $ticket_data['quantity'];
                                $vgn_arr[$key]['shift_id']                        = $ticket_data['shift_id'];
                                $vgn_arr[$key]['slot_type']                       = $ticket_data['slot_type'];
                                $vgn_arr[$key]['ticket_id']                       = $ticket_data['ticket_id'];
                                $vgn_arr[$key]['ticket_title']                    = $ticket_data['title'];
                                $vgn_arr[$key]['price']                           = $ticket_data['price'];
                                $vgn_arr[$key]['sale_by_cash']                    = $ticket_data['sale_by_cash'];
                                $vgn_arr[$key]['sale_by_voucher']                 = $ticket_data['sale_by_voucher'];
                                $vgn_arr[$key]['sale_by_card']                    = $ticket_data['sale_by_card'];
                                if (!empty($eo_vgn_data[$key]['eo_data']['0'])) {
                                    $vgn_arr[$key]['per_ticket_extra_options']        = !empty($eo_vgn_data[$key]['eo_data']['0']) ? $eo_vgn_data[$key]['eo_data']['0'] : array();
                                }

                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['age_group']         = $ticket_data['age_group'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['extra_booking_information']   = $ticket_data['extra_booking_information'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['extra_discount']    = $ticket_data['extra_discount'] ? unserialize($ticket_data['extra_discount']) : "";
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['net_price']         = (string) $ticket_data['net_price'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['price']             = (string) $ticket_data['oroginal_price'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['cashier_discount']  = (string) ($vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['cashier_discount'] + ($ticket_data['quantity'] * $discount_gross_amount));
                                $data_all[$ticket_data['ticket_id'] . '_' . $ticket_data['tps_id']][$i]['variation_count'] += 1;
                                $paymnet_data_activation_type[$ticket_data['activation_method'] . "_" . $ticket_data['ticket_id']  . '_' . $ticket_data['tps_id']][$i]['variation_count'] += 1;
                                if ($discount_gross_amount) {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['variation_count']  = ($vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['variation_count'] + 1);
                                }
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['variation_discount']  = (string) ($discount_gross_amount);
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['variation_type']  = !empty($discount_variation_type) ? $discount_variation_type : $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['variation_type'];
                                if (!empty($eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']])) {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['per_age_group_extra_options']        = !empty($eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']]) ? $eo_vgn_data[$key]['eo_data'][$ticket_data['tps_id']] : array();
                                }
                                if ($ticket_data['is_refunded'] ==  "2") {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity']   = (string) $ticket_data['is_refunded'] ==  "2" ? $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity'] + $ticket_data['quantity'] : 0;
                                    $count = (string) $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refund_quantity'];
                                    $refunded_by =  (string) $ticket_data['is_refunded'] ==  "2" ? $ticket_data['refunded_by'] : '';
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refunded_by'][] = (object) array("count" => $count, "refunded_by" => $refunded_by);
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['refunded_passes'][]   =  $ticket_data['passNo'];
                                }
                                if ($ticket_data['is_refunded'] ==  "") {
                                    $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['passes'][]   =  $ticket_data['passNo'];
                                }
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['tax']        = (string) $ticket_data['tax'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['tps_id']     = (string) $ticket_data['tps_id'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['ticket_id']     = (string) $ticket_data['ticket_id'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['type']       = $ticket_data['ticket_type'];
                                $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['activation_method']       = $ticket_data['activation_method'];
                                $vgn_arr[$key]['timezone']                                      = (string) $ticket_data['timezone'];
                                $vgn_arr[$key]['to_time']                                       =  $ticket_data['to_time'];
                            }
                            $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['quantity']          = (string) $vgn_arr[$key]['ticket_types'][$ticket_data['tps_id']]['quantity'] + $ticket_data['quantity'];
                        }
                        $vgn_arr[$key]['amount']                                               = (string) ($vgn_arr[$key]['amount'] + $extra_option_amount);
                    }
                    foreach ($vgn_arr as $key => $value) {
                        foreach ($value['ticket_types'] as $tps_id =>  $tps_data) {
                            $value['ticket_types'][$tps_id] = (object) $tps_data;
                        }
                        $value['ticket_types'] = (object) $value['ticket_types'];
                        $prepaid_data[$key] = (object) $value;
                    }
                    if (!empty($prepaid_data)) {
                        $shift_discount_total = $total_shift_discount = $pos_discount_total = $shift_sale_discount_total = $pos_discount = $sale_discount = $shiftdiscount_total = [];
                        foreach ($prepaid_data as $data) {
                            if ($data->shift_id == $shift_id) {
                                $data->payment_method = $data->payment_method == '12' ? '1' : $data->payment_method;
                                $voucher_category = '';
                                if (!empty($data->pos_point_id)) {
                                    $pos_points_array[$data->pos_point_id] = $data->pos_point_name;
                                }
                                $payment_type_surcharge_amount = $total_surcharge_amount = $total_amount =  $payment_type_total_amount = $dynamic_variation = 0;
                                foreach ($data->ticket_types as $tps_id => $type_details) {
                                    $get_data = $payment_data_activation_type = $total_amount = [];
                                    $cashier_discount = ($type_details->quantity - $type_details->refund_quantity) ? $type_details->cashier_discount : 0;
                                    foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                        //total refunded amount
                                        $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                        //total amount and refunded amount for any ticket
                                        $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                        $shift_total[$data->shift_id]['total_amount'] = $shift_total[$data->shift_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                        $total_payments[$data->ticket_id]['refunded_amount'] = $total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                    }
                                    //total refunded amount
                                    $total_payments['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_payments['refunded_amount'];
                                    //total amount and refunded amount for any ticket
                                    $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity)) - $cashier_discount;
                                    $total_payments[$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_payments[$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_payments[$data->ticket_id]['refunded_amount'];

                                    foreach ($type_details->per_age_group_extra_options as $extra_options) {
                                        $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                                        //total amount and refunded amount for any ticket for diff payment method
                                        $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity));
                                        $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                        //total refunded amount at pos level
                                        $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                                        //total amount and refunded amount for any ticket at pos level
                                        $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity));
                                        $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                    }
                                    // $extra_booking_information = !empty($data->extra_booking_information) ? json_decode($data->extra_booking_information, true) : [];
                                    /* Promocodes not handled yet */
                                    // $res = $this->setOrderPricingPromocodes($extra_booking_information);
                                    // $promocode_data[$data->ticket_id][$tps_id][] = array(
                                    //     "promo_code" => !empty($res['promo_code']) ? $res['promo_code'] : "",
                                    //     "promo_amount" => !empty($res['promo_amount']) ? $res['promo_amount'] : "",
                                    // );
                                    $product_type_data_All = ($data_all[$type_details->ticket_id . "_" . $type_details->tps_id]);
                                    $product_type_data_activation_wise = $paymnet_data_activation_type[$type_details->activation_method . "_" . $type_details->ticket_id . "_" . $type_details->tps_id];
                                    foreach($product_type_data_All as $product_type_data) {
                                        list($get_data[] , $total_surcharge_amount) = $this->SetOrderProductTypePricing((object) $product_type_data, $type_details->extra_discount ? $type_details->quantity : 0, $total_surcharge_amount);
                                    }
                                    foreach($product_type_data_activation_wise as $product_type_data) {
                                        list($payment_data_activation_type[], $payment_type_surcharge_amount) = $this->SetOrderProductTypePricing((object) $product_type_data, $type_details->extra_discount ? $type_details->quantity : 0, $payment_type_surcharge_amount);
                                    }
                                    list($all_payment_data ,$total_amount)  =  $this->getTotalVariation($get_data);
                                    list($payment_type_wise_data ,$payment_type_total_amount, $dynamic_variation)  =  $this->getTotalVariation($payment_data_activation_type);
                                    // Calculated all discount and variations
                                    $pos_discount_total[$data->pos_point_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount + $dynamic_variation : $type_details->cashier_discount;
                                    $total_shift_discount[$data->shift_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount: $type_details->cashier_discount;
                                    $shiftdiscount_total[$data->payment_method][$data->ticket_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount +$dynamic_variation : $type_details->cashier_discount;
                                    $shift_discount_total[$data->payment_method][$data->shift_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount +$dynamic_variation : $type_details->cashier_discount;
                                    $pos_discount[$data->pos_point_id][$data->ticket_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount +$dynamic_variation : $type_details->cashier_discount;
                                    $sale_discount[$data->shift_id][$data->ticket_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount +$dynamic_variation : $type_details->cashier_discount;
                                    $shift_sale_discount_total[$data->shift_id] += !empty($dynamic_variation) ?  $type_details->cashier_discount +$dynamic_variation : $type_details->cashier_discount;
                                    //Arrange tickets types w.r.t pos point 
                                    $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type"] = strtoupper($type_details->type);
                                    $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]['product_type_id'] = (string) $tps_id;
                                    $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_count"] =  $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]['product_type_count'] + $type_details->quantity - $type_details->refund_quantity;
                                    $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_pricing"]["price_subtotal"] = number_format($type_details->price * ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_count"])+ $total_surcharge_amount, 2);
                                    if (!empty($all_payment_data)) {
                                        $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_pricing"]["price_variations"] = $all_payment_data;
                                    }
                                    $pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_pricing"]['price_total'] = (string) number_format($type_details->price * ($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id][$tps_id]["product_type_count"] - $type_details->refund_quantity) + $total_surcharge_amount - ($total_amount), 2);
                                    $pos_ticket_type_details = $this->common_model->sort_ticket_types($pos_ticket_type_details);
                                    //Arrange ticket types payment wise
                                    $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type"] = strtoupper($type_details->type);
                                    $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]['product_type_id'] = (string) $tps_id;
                                    $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_count"] = $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]['product_type_count'] + $type_details->quantity - $type_details->refund_quantity;
                                    $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_pricing"]["price_subtotal"] = number_format($type_details->price * ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_count"]) + $payment_type_surcharge_amount, 2);
                                    if (!empty($payment_type_wise_data)) {
                                        $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_pricing"]["price_variations"] = $payment_type_wise_data;
                                    }
                                    $payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_pricing"]['price_total'] = (string) number_format($type_details->price * ($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id][$tps_id]["product_type_count"] - $type_details->refund_quantity) - $payment_type_total_amount + $payment_type_surcharge_amount, 2);
                                    //Arrange ticket types corresponding to thier shift 
                                    $shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type"] = strtoupper($type_details->type);
                                    $shift_ticket_type_details[$data->ticket_id][$tps_id]['product_type_id'] = (string) $tps_id;
                                    $shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_count"] = $shift_ticket_type_details[$data->ticket_id][$tps_id]['product_type_count'] + $type_details->quantity - $type_details->refund_quantity;
                                    $shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_pricing"]["price_subtotal"] = number_format($type_details->price *($shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_count"]) + $total_surcharge_amount, 2);
                                    if (!empty($all_payment_data)) {
                                        $shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_pricing"]["price_variations"] = $all_payment_data;
                                    }
                                    $shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_pricing"]['price_total'] = (string) number_format($type_details->price * ($shift_ticket_type_details[$data->ticket_id][$tps_id]["product_type_count"] - $type_details->refund_quantity) - $total_amount + $total_surcharge_amount, 2);
                                    $payment_ticket_type_details = $this->common_model->sort_ticket_types($payment_ticket_type_details);
                                    //total refunded amount for diff payment method
                                    $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                                    //total amount and refunded amount for any ticket for diff payment method
                                    $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity));
                                    $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'];
                                    //total refunded amount at pos level
                                    $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($type_details->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($type_details->refund_quantity * $type_details->price) - $type_details->cashier_discount) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                                    //total amount and refunded amount for any ticket at pos level
                                    $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] = $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity));
                                    $total_shift_sale_payments[$data->shift_id][$data->ticket_id]['total_amount'] = $total_shift_sale_payments[$data->shift_id][$data->ticket_id]['total_amount'] + ($type_details->price * ($type_details->quantity - $type_details->refund_quantity));
                                    $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] = ($type_details->refund_quantity > 0) ? ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($type_details->price * $type_details->refund_quantity) - $type_details->cashier_discount) : $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'];
                                }
                                foreach ($data->per_ticket_extra_options as $extra_options) {
                                    $per_ticket_extra_options[$data->ticket_id][$extra_options->description] = array(
                                        "option_id" => $extra_options->extra_option_id,
                                        "option_name" => $extra_options->main_description,
                                        "option_values" => array(
                                            "value_id" => $extra_options->prepaid_extra_options_id,
                                            "value_name" => $extra_options->description,
                                            "value_price" => $extra_options->price,
                                            "value_count" => (int) $per_ticket_extra_options[$data->ticket_id][$extra_options->description]['value_count'] + $extra_options->quantity,
                                            "value_product_type_id" => $extra_options->ticket_price_schedule_id,
                                        ),
                                    );
                                    //total amount and refunded amount for any ticket
                                    $total_payments[$data->ticket_id]['total_amount'] = $total_payments[$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity - $extra_options->refund_quantity));
                                    $total_payments[$data->ticket_id]['refunded_amount'] = $total_payments[$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity);
                                }
                                // total amount and discount and service cost
                                $total_payments['total'] = ($total_payments['total'] + $data->amount);
                                /* combined option discount for each ticket in a order. */
                                $total_payments['option_discount'] = ($total_payments['option_discount'] + $type_details->cashier_discount);
                                $total_payments['option_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['option_discount_cancelled'] + $data->discount_code_amount) : $total_payments['option_discount_cancelled'];
                                if (empty($total_payments['service_cost'][$data->order_id])) {
                                    $total_payments['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                                }
                                if (empty($total_payments['service_cost_cancelled'][$data->order_id])) {
                                    $total_payments['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                                }
                                $total_payments['combi_discount'] = $total_payments['combi_discount'] + $data->total_combi_discount;
                                $total_payments['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_payments['combi_discount_cancelled'] + $data->total_combi_discount) : $total_payments['combi_discount_cancelled'];

                                foreach ($data->per_ticket_extra_options as $extra_options) {
                                    // Ticket extra options as per pos level
                                    $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description] = array(
                                        "option_id" => $extra_options->extra_option_id,
                                        "option_name" => $extra_options->main_description,
                                        "option_values" => array(
                                            "value_id" => $extra_options->prepaid_extra_options_id,
                                            "value_name" => $extra_options->description,
                                            "value_price" => $extra_options->price,
                                            "value_count" => (int) $pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id][$extra_options->description]['option_values']['value_count'] + $extra_options->quantity,
                                            "value_product_type_id" => $extra_options->ticket_price_schedule_id,
                                        ),
                                    );
                                    // Ticket extra options w.r.t payment methods
                                    $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description] = array(
                                        "option_id" => $extra_options->extra_option_id,
                                        "option_name" => $extra_options->main_description,
                                        "option_values" => array(
                                            "value_id" => $extra_options->prepaid_extra_options_id,
                                            "value_name" => $extra_options->description,
                                            "value_price" => $extra_options->price,
                                            "value_count" => (int) $payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id][$extra_options->description]['option_values']['value_count'] + $extra_options->quantity,
                                            "value_product_type_id" => $extra_options->ticket_price_schedule_id,
                                        ),
                                    );
                                    $total_payments['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_payments['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_payments['refunded_amount'];
                                    //total amounts and refunded amount for diff activation method
                                    $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_activation_payment[$data->payment_method . $voucher_category]['refunded_amount'];
                                    $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity));
                                    $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] = $total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity) - $type_details->cashier_discount;
                                    //total refunded amount at pos level
                                    $total_pos_payments[$data->pos_point_id]['refunded_amount'] = ($extra_options->refund_quantity > "0") ? ($total_pos_payments[$data->pos_point_id]['refunded_amount'] + ($extra_options->refund_quantity * $extra_options->price)) : $total_pos_payments[$data->pos_point_id]['refunded_amount'];
                                    //total amount and refunded amount for any ticket at pos level
                                    $total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] =  ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] + ($extra_options->price * ($extra_options->quantity)));
                                    $total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] =  ($total_pos_payments[$data->pos_point_id][$data->ticket_id]['refunded_amount'] + ($extra_options->price * $extra_options->refund_quantity));
                                }
                                // total amount and discount and service cost at pos level
                                $total_pos_payments[$data->pos_point_id]['amount'] = ($total_pos_payments[$data->pos_point_id]['amount'] + $data->amount);
                                $total_pos_payments[$data->pos_point_id]['option_discount'][$data->order_id] += $data->discount_code_amount;
                                $total_pos_payments[$data->pos_point_id]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                                $total_pos_payments[$data->pos_point_id]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                                $total_pos_payments[$data->pos_point_id]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                                $total_pos_payments[$data->pos_point_id]['combi_discount'] = $total_pos_payments[$data->pos_point_id]['combi_discount'] + $data->total_combi_discount;
                                $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'] + $data->total_combi_discount) : $total_pos_payments[$data->pos_point_id]['combi_discount_cancelled'];
                                $total_sale_payments[$data->shift_id]['total_amount'] = ($total_sale_payments[$data->shift_id]['total_amount'] + $data->amount);
                                $total_shift_payments[$data->payment_method][$data->shift_id]['total_amount'] = ($total_shift_payments[$data->payment_method][$data->shift_id]['total_amount'] + $data->amount);
                                //Arrange tickets and its types at pos level
                                $total_discount[] = $total_amount;
                                if (!empty($data->ticket_id)) {
                                    $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_id'] = (string) $data->ticket_id;
                                    $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_title'] =  $data->ticket_title;
                                    $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_price_total'] = (string) number_format($total_pos_payments[$data->pos_point_id][$data->ticket_id]['total_amount'] - $pos_discount[$data->pos_point_id][$data->ticket_id] + $total_surcharge_amount, 2);
                                    $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_price_discount'] = (string) number_format($pos_discount[$data->pos_point_id][$data->ticket_id], 2);
                                    $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_type_details'] = (!empty($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id])) ? array_values($pos_ticket_type_details[$data->pos_point_id][$data->ticket_id]) : array();
                                    if($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id]){
                                        $pos_tickets_array[$data->pos_point_id][$data->ticket_id]['product_options'] = array_values($pos_level_per_ticket_extra_options[$data->pos_point_id][$data->ticket_id]);
                                    }
                                }
                                //Arrange tickets and its types payment wise
                                if (!empty($data->ticket_id)) {
                                    $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_id'] = (string) $data->ticket_id;
                                    $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_title'] = $data->ticket_title;
                                    $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_price_total'] = (string) number_format(($total_activation_payment[$data->payment_method . $voucher_category][$data->ticket_id]['total_amount'] - $shiftdiscount_total[$data->payment_method][$data->ticket_id] + $payment_type_surcharge_amount), 2);
                                    $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_price_discount'] = (string) number_format($shiftdiscount_total[$data->payment_method][$data->ticket_id], 2);
                                    $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_type_details'] = (!empty($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id])) ? array_values($payment_ticket_type_details[$data->payment_method . $voucher_category][$data->ticket_id]) : array();
                                    if(!empty($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id])){
                                        $payment_tickets_array[$data->payment_method . $voucher_category][$data->ticket_id]['product_options'] = array_values($payment_per_ticket_extra_options[$data->payment_method . $voucher_category][$data->ticket_id]);
                                    }
                                }
                                /* Arrange all sold tickets in shift*/
                                if (!empty($data->ticket_id)) {
                                    if ($data->is_refunded == 2) {
                                        $refunded_price[$data->ticket_id][] = $data->price;
                                    }
                                    $shift_sale_details[$data->shift_id][$data->ticket_id]['product_id'] = (string) $data->ticket_id;
                                    $shift_sale_details[$data->shift_id][$data->ticket_id]['product_title'] = $data->ticket_title;
                                    $shift_sale_details[$data->shift_id][$data->ticket_id]['product_price_total'] = (string) number_format(($total_shift_sale_payments[$data->shift_id][$data->ticket_id]['total_amount'] - $sale_discount[$data->shift_id][$data->ticket_id] + $total_surcharge_amount), 2);
                                    $shift_sale_details[$data->shift_id][$data->ticket_id]['product_price_discount'] = (string) number_format($sale_discount[$data->shift_id][$data->ticket_id], 2);
                                    $shift_sale_details[$data->shift_id][$data->ticket_id]['product_type_details'] = (!empty($shift_ticket_type_details[$data->ticket_id])) ? array_values($shift_ticket_type_details[$data->ticket_id]) : array();
                                    if (!empty($per_ticket_extra_options[$data->ticket_id])) {
                                        $shift_sale_details[$data->shift_id][$data->ticket_id]['product_options'] = array_values($per_ticket_extra_options[$data->ticket_id]);
                                    }
                                }
                                // total amount and discount and service cost for diff payment methods
                                $total_activation_payment[$data->payment_method . $voucher_category]['amount'] = $total_activation_payment[$data->payment_method . $voucher_category]['amount'] + $data->amount - $data->discount_code_amount;
                                $total_activation_payment[$data->payment_method . $voucher_category]['option_discount'] += $type_details->cashier_discount;
                                $total_activation_payment[$data->payment_method . $voucher_category]['option_discount_cancelled'][$data->order_id] = ($data->cancelled_tickets == $data->quantity) ? $data->discount_code_amount : 0;
                                $total_activation_payment[$data->payment_method . $voucher_category]['service_cost'][$data->order_id] = ($data->service_cost_type == "2") ? $data->service_cost_amount : 0;
                                $total_activation_payment[$data->payment_method . $voucher_category]['service_cost_cancelled'][$data->order_id] = ($data->service_cost_type == "2" && $data->cancelled_tickets == $data->quantity) ? $data->service_cost_amount : 0;
                                $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] = $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount'] + $data->total_combi_discount;
                                $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] = ($data->cancelled_tickets == $data->quantity) ? ($total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'] + $data->total_combi_discount) : $total_activation_payment[$data->payment_method . $voucher_category]['combi_discount_cancelled'];
                            }
                        }
                        //prepare final array for pos level tickets
                        if (!empty($pos_tickets_array)) {
                            foreach ($pos_tickets_array as $pos_key => $array) {
                                foreach ($array as $ticket) {
                                    $pos_tickets[$pos_key][] = $ticket;
                                }
                                $pos_shift_details[$pos_key] = array(
                                    'pos_point_id' => (string) $pos_key,
                                    'pos_point_name' => $pos_points_array[$pos_key],
                                    'pos_point_price_total' => (string) number_format($total_pos_payments[$pos_key]['amount'] - $total_pos_payments[$pos_key]['refunded_amount'] - $pos_discount_total[$pos_key], 2),
                                    'pos_point_price_discount' => (string) number_format($pos_discount_total[$pos_key], 2),
                                    'pos_point_price_refunded' => ($total_pos_payments[$pos_key]['refunded_amount'] > 0) ? (string) number_format($total_pos_payments[$pos_key]['refunded_amount'], 2) : "0.00",
                                    'pos_point_products' => $pos_tickets[$pos_key]
                                );
                            }
                        }
                        sort($pos_shift_details);
                        //final array for diff paymebnt methods
                        if (!empty($payment_tickets_array)) {
                            foreach ($payment_tickets_array as $payment_method => $array) {
                                $payment_values = explode('_', $payment_method);
                                foreach ($array as $ticket) {
                                    $payment_tickets[$payment_method][] = $ticket;
                                }
                                $payment_shift_details[$payment_values[0]] = array(
                                    'shift_payment_type' => (string) $this->get_payment_type($payment_values[0]),
                                    'shift_price_total' => (string) number_format(($total_shift_payments[$payment_method][$data->shift_id]['total_amount'] - $total_activation_payment[$payment_method]['refunded_amount'] - $shift_discount_total[$payment_method][$data->shift_id] + $dynamic_variation), 2),
                                    'shift_price_refunded' => ($total_activation_payment[$payment_method]['refunded_amount'] > 0) ? (string) number_format($total_activation_payment[$payment_method]['refunded_amount'], 2) : "0.00",
                                    'shift_price_discount' => (string) number_format($shift_discount_total[$payment_method][$data->shift_id], 2),
                                    'shift_products' => $payment_tickets[$payment_method]
                                );
                            }
                        }
                        if (!empty($payment_shift_details)) {
                            if (!empty($payment_shift_details["2"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["2"];
                            }
                            if (!empty($payment_shift_details["4"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["4"];
                            }
                            if (!empty($payment_shift_details["9"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["9"];
                            }
                            if (!empty($payment_shift_details["16"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["16"];
                            }
                            if (!empty($payment_shift_details["1"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["1"];
                            }
                            if (!empty($payment_shift_details["3"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["3"];
                            }
                            if (!empty($payment_shift_details["10"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["10"];
                            }
                            if (!empty($payment_shift_details["19"])) {
                                $payment_ticket_arrays[] = $payment_shift_details["19"];
                            }
                        }
                    }
                    foreach ($shift_data as $value) {
                        if ($value['status'] == "1") {
                            $shift_type = "OPEN";
                        } elseif ($value['status'] == "2") {
                            $shift_type = "CLOSE";
                        }
                        $shift_pt_data = array(
                            'shift_start_time' => $value['opening_time'] ? $date . "T" . $value['opening_time'] . "+00:00" : "",
                            'shift_end_time' => $value['closing_time'] ? $date . "T" . $value['closing_time'] . "+00:00" : "",
                            'shift_start_amount' => $value['opening_cash_balance'] ? (string) number_format($value['opening_cash_balance'], 2) : "",
                            'shift_closing_amount' => (string) number_format($value['opening_cash_balance'] + ($total_payments['total'] - $total_payments['refunded_amount'] - $total_shift_discount[$data->shift_id]), 2),
                            'shift_price_total' => (string) number_format($total_payments['total'] - $total_payments['refunded_amount'] - $total_shift_discount[$data->shift_id] + $dynamic_variation, 2),
                            'shift_price_refunded' => (string) $total_payments['refunded_amount'] > 0 ? (string) number_format($total_payments['refunded_amount'], 2) : "0.00",
                            'shift_price_discount' => (string) number_format($total_shift_discount[$data->shift_id] + $dynamic_variation, 2),
                            'shift_notes' => $value['note'] ? $value['note'] : ""
                        );
                    }
                    if (!empty($shift_sale_details)) {
                        foreach ($shift_sale_details as $key => $array) {
                            foreach ($array as $ticket) {
                                $sale_tickets[$key][] = $ticket;
                            }
                            $shift_details[$key]['sale_price_total'] = (string) number_format($total_sale_payments[$data->shift_id]['total_amount'] - $total_payments['refunded_amount'] - $shift_sale_discount_total[$data->shift_id], 2);
                            $shift_details[$key]['sale_price_discount'] = (string) number_format($shift_sale_discount_total[$data->shift_id], 2);
                            $shift_details[$key]['sale_price_refunded'] = $total_payments['refunded_amount'] > 0 ? (string) number_format($total_payments['refunded_amount'], 2) : "0.00";
                            $shift_details[$key]['sale_products'] = $sale_tickets[$key];
                        }
                    }

                    //redeemed tickets
                    $scan_report_data = array();
                    if ($supplier_cashier_id > 0 && $supplier_id > 0 ||  ($cashier_type == '3' && $reseller_id != '' && $reseller_id > 0 && $reseller_cashier_id > 0)) {
                        $req = array(
                            'cashier_id' => $supplier_cashier_id,
                            'shift_id' => $shift_id,
                            'supplier_id' => $supplier_id,
                            'date' => $date,
                            'scan_report' => "1",
                            'cashier_type' => $cashier_type,
                            'hotel_id' => $qr_query[0]['cod_id'],
                            'reseller_id' => $qr_query[0]['reseller_id'],
                            'reseller_cashier_id' => $reseller_cashier_id
                        );
                        $logs['redeem_report_req_' . date('H:i:s')] = $req;
                        $MPOS_LOGS['shift_report'] = $logs;
                        $MPOS_LOGS = array_merge($MPOS_LOGS, $logs);
                        $logs = array();
                        $scan_report_data = $this->shift_report_for_redeem($req);
                        if (!empty($scan_report_data['error_no']) && $scan_report_data['error_no'] > 0) { //some error in scan report process
                            return $scan_report_data;
                        }
                    }
                    if ((!empty($payment_ticket_arrays)) || (!empty($pos_shift_details)) || (!empty($scan_report_data))) {
                        /* send response to app. */
                        $response = array(
                            'distributor_id' => $qr_query[0]['cod_id'],
                            'distributor_name' => $qr_query[0]['company'],
                            'reseller_id' => $qr_query[0]['reseller_id'],
                            'reseller_name' => $qr_query[0]['reseller_name'],
                            'shift_id' => $shift_id,
                            'shift_status' => isset($shift_type) ? $shift_type : "",
                            'shift_details' => (!empty($shift_pt_data)) ? $shift_pt_data : (object) array(),
                            'shift_payment_details' => (!empty($payment_ticket_arrays)) ? (array) $payment_ticket_arrays : array(),
                            'shift_pos_point_details' => (!empty($pos_shift_details)) ? $pos_shift_details : array(),
                            'shift_sale_details' => !empty($shift_details) ? $shift_details[$shift_id] : (object)[],
                            'shift_redeem_details' => (!empty($scan_report_data)) ? $scan_report_data : (object) array()
                        );
                    }
                } else {
                    $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'shift_report');
                }
            } catch (\Exception $e) {
                $logs['exception'] = $e->getMessage();
            }
            return $response;
        } catch (\Exception $e) {
            $MPOS_LOGS['sales_report_v4'] = $logs;
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            return $MPOS_LOGS['exception'];
        }
    }

    /* Promocodes Not handled yet */
    // public function setOrderPricingPromocodes($extra_booking_info)
    // {
    //     if (is_array($extra_booking_info) && !empty($extra_booking_info['discount_codes_details'])) {
    //         foreach ($extra_booking_info['discount_codes_details'] as $key => $promocodes) {
    //             $promocode['promo_code'] = $key;
    //             $promocode['promo_amount'] = number_format($promocodes['discount_amount'], 2);
    //         }
    //     }
    //     return $promocode;
    // }

    public function SetOrderProductTypePricing($order_detail, $other_data, $surcharge_key)
    {
        $extra_booking_information = !empty($order_detail->extra_booking_information) ? json_decode($order_detail->extra_booking_information) : [];
        $is_partner_discount = $daily_variation = $quantity_variations = $is_quantity_variations = 0;
        $price_variations = !empty($extra_booking_information->product_type_pricing->price_variations) ?
            $extra_booking_information->product_type_pricing->price_variations : (!empty($extra_booking_information->ticket_type_data->{$order_detail->tps_id}->prices->product_type_pricing->price_variations) ? $extra_booking_information->ticket_type_data->{$order_detail->tps_id}->prices->product_type_pricing->price_variations : []);
        /** #Comment: - Unserialize Extra Discount Column From PT */
        // $extra_discount = !empty($order_detail->extra_discount) ? @unserialize($order_detail->extra_discount) : [];
        /** #Comment: - PRODUCT_DAILY and PRODUCT_QUANTITY we picked From Extra booking Information*/
        list($daily_variation, $quantity_variations, $is_quantity_variations) = $this->getDiscountFromExtraBookingInformation($price_variations);
        /** #Comment:-  Function To calculate PRODUCT_DYNAMIC */
        $dynamic_pricing = 0;
        if (!empty($extra_booking_information->sale_variation_amount)) {
            $dynamic_pricing = $extra_booking_information->sale_variation_amount ?? '0';
        }        
        list($ProductDynamicVariation , $surcharge) = $this->setProductDynamicVariation(($dynamic_pricing ?? 0) , $order_detail);
        $surcharge += $surcharge_key;
        if (!empty($ProductDynamicVariation)) {
            $pricing = $ProductDynamicVariation;
        }
        /** #Comment: -  Function To calculate PRODUCT_DAILY */
        if (!empty($other_data['product_type_count']) && isset($daily_variation)) {
            $daily_variation = $daily_variation * $other_data['product_type_count'];
        }
        /** #Comment: -  Function To calculate PRODUCT_QUANTITY */
        if (!empty($is_quantity_variations)) {
            $ProductQuantityVariation = $this->setProductQuantityVariation($quantity_variations ?? 0);
            if (!empty($ProductQuantityVariation)) {
                $pricing = $ProductQuantityVariation;
            }
        }
        /** #Comment: -  Function To calculate product cart variation */
        $ProductCartVariation = $this->setProductCartVariation(($order_detail->extra_discount ?? []), $order_detail);
        if (!empty($ProductCartVariation)) {
            $pricing = $ProductCartVariation;
        }
        return [$pricing , $surcharge];
    }
    public function getDiscountFromExtraBookingInformation($price_variations)
    {
        $daily_variation = $quantity_variations = $is_quantity_variations = 0;
        if (!empty($price_variations)) {
            foreach ($price_variations as $variation) {
                if (!empty($variation->variation_type)) {
                    switch ($variation->variation_type) {
                        case 'PRODUCT_DAILY':
                            $daily_variation = $variation->variation_amount ?? 0;
                            break;
                        case 'PRODUCT_QUANTITY':
                            $is_quantity_variations = 1;
                            $quantity_variations = $variation->variation_amount ?? 0;
                            break;
                    }
                }
            }
        }
        return [$daily_variation, $quantity_variations, $is_quantity_variations];
    }
    public function setProductDynamicVariation($sale_variation , $other_data)
    {
        if (!empty($sale_variation)) {
            if($sale_variation < 0){
                $pricing['variation_amount'] = str_replace("-", "" ,number_format($sale_variation, 2));
                $pricing['variation_type'] =  'PRODUCT_DYNAMIC';
                $pricing['variation_count']  = $other_data->variation_count;
            }else{
                $surcharge_key = $sale_variation;
            }
        }
        return [$pricing, $surcharge_key];
    }

    public function setProductQuantityVariation($daily_variations)
    {
        if (!empty($daily_variations) && $daily_variations > 0) {
            $pricing['variation_amount'] = number_format($daily_variations, 2);
            $pricing['variation_type'] =  'PRODUCT_QUANTITY';
            $pricing['variation_count']  =  count($daily_variations);
        }
        return $pricing;
    }

    public function setProductCartVariation($cart_discounts, $other_data)
    {
        if (!empty($cart_discounts['gross_discount_amount'])) {
            $pricing = array(
                'variation_amount' => number_format($other_data->cashier_discount, 2),
                'variation_type' =>  $cart_discounts['discount_variation_type'] ?? 'CART_DISCOUNT_CUSTOM',
                'variation_count'  => $other_data->variation_count,
            );
        }
        return $pricing;
    }
    public function searchparentkey($value, $arr)
    {
        if (!empty($arr) && !empty($value)) {
            foreach ($arr as $key => $val) {
                $val = (array) ($val);
                $value = (string) ($value);
                if (!empty($val) && !empty($value) && in_array($value, $val)) {
                    return $key;
                }
            }
        }
    }

    public function getTotalVariation($get_data)
    {
        $newarr = [];$discount = $dynamic_variation = 0;
        foreach ($get_data as $val) {
            if (!empty($val)) {
                $key = $this->searchparentkey($val['variation_type'], $newarr);
                if (isset($newarr[$key])) {
                    $newarr[$key]['variation_type'] = $val['variation_type'];
                    $newarr[$key]['variation_amount'] += $val['variation_amount'];
                    $newarr[$key]['variation_count'] += $val['variation_count'];
                    $newarr[$key]['variation_amount'] = number_format($newarr[$key]['variation_amount'], 2);
                } else {
                    array_push($newarr, $val);
                }
                if ($val['variation_amount'] > 0) {
                    $discount  += $val['variation_amount'] ?? 0;
                }
                if($val['variation_type'] == "PRODUCT_DYNAMIC" && $val['variation_amount'] > 0){
                    $dynamic_variation += $val['variation_amount'];
                }
            }
        }
        return [$newarr , $discount, $dynamic_variation];
    }
}
