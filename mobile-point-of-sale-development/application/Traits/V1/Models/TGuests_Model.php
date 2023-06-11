<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TGuests_Model {
    /*
     * To load commonaly used files in all functions
     */

    function __construct() {
        parent::__construct();
        $this->load->Model('V1/common_model');
    }

    /* #region : confirm the guests/

      /**
     * @Name confirm
     * @Purpose : to confirm the payments wrt each guest 
     * @return status and data 
     *      status 1 or 0
     *      data having guest details or guest details with order details of a user
     * @param 
     *      $req - array() - API request (same as from APP) + user_details + hidden_tickets (of sup user)
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 16 june 2020
     */
    function confirm($req = array()) {
        global $MPOS_LOGS;
        $proceed = "1";
        $pt_data = $this->find('prepaid_tickets', array('select' => 'booking_status', 'where' => "visitor_group_no in (" . implode(",", $this->primarydb->db->escape($req['order_references'])) . ")"), 'array');
        $logs['pt_check for bs 0'] = $this->primarydb->db->last_query();
        foreach ($pt_data as $pt_records) {
            if ($pt_records['booking_status'] == "1") {
                $proceed = "0";
            }
        }
        if ($proceed == "1") {
            $hto_card_update = $hto_update = $pt_card_update = "";
            $action_performed = "CONTIKI_PAID";
            if (isset($req['payment_method']) && $req['payment_method'] != '' && $req['payment_method'] != 'undefined') {
                $payment_method = $req['payment_method'];
            } else {
                $payment_method = '2';
            }
            $hto_update_for_card = $partner_id = $partner_category_id = 0;
            $card_values = array();
            $partner_name = $partner_category_name = "";
            if ($payment_method == 1) {
                $redeem_method = 'card';
                $method = 5;
                $card_values = $this->check_card_values($req);
            } else if ($payment_method == 2) {
                $redeem_method = 'cash';
                $method = 2;
            } else if ($payment_method == 10 && isset($req['partner'])) {
                $redeem_method = 'voucher';
                $method = 11;
                $partner_id = $req['partner']['partner_id'];
                $partner_name = $req['partner']['partner_name'];
                $partner_category_id = $req['partner']['group_id'];
                $partner_category_name = $req['partner']['group_name'];
            } else if (isset($req['split_payment']) && !empty($req['split_payment'])) {
                $payment_method = '9';
                $method = 8;
                $insert_pt_db2['split_card_amount'] = $split_detail['card'] = ($req['split_payment']['card_amount'] > 0) ? $req['split_payment']['card_amount'] : 0;
                if ($req['split_payment']['card_amount'] > 0) {
                    $card_values = $this->check_card_values($req);
                }
                $insert_pt_db2['split_cash_amount'] = $split_detail['cash'] = $req['split_payment']['cash_amount'];
                $insert_pt_db2['split_direct_payment_amount'] = $split_detail['direct'] = $req['split_payment']['direct_amount'];
                $insert_pt_db2['split_voucher_amount'] = $split_detail['voucher'] = $req['split_payment']['coupon_amount'];
                $hto_update = ", split_payment_detail = '" . serialize($split_detail) . "' ";
            }

            if (!empty($card_values)) {
                $hto_card_update = ", paymentStatus = '1', pspReference = '" . $card_values['psp_reference'] . "', merchantReference = '" . $card_values['merchant_reference'] . "', paymentMethod = '" . $card_values['paymentMethod'] . "', merchantAccountCode = '" . $card_values['merchant_account'] . "', shopperReference = '" . $card_values['shopperReference'] . "', eventCode = '" . $card_values['eventCode'] . "'";
                $pt_card_update = ", pspReference = '" . $card_values['psp_reference'] . "', merchantReference = '" . $card_values['merchant_reference'] . "', authcode = '" . $card_values['auth_code'] . "' ";
                $insert_pt_db2['pspReference'] = $card_values['psp_reference'];
                $insert_pt_db2['merchantReference'] = $card_values['merchant_reference'];
                $insert_pt_db2['authcode'] = $card_values['auth_code'];
                $insert_pt_db2['payment_gateway'] = $card_values['payment_gateway'];
                $hto_update_for_card = 1;
            }
            $logs['hto_update_query_' . date('H:i:s')] = $this->db->last_query();
            $this->query("UPDATE prepaid_tickets SET booking_status = '1', is_order_confirmed = '1', action_performed = concat(action_performed, ',$action_performed'), activation_method = '" . $payment_method . "', partner_category_id = '" . $partner_category_id . "', partner_category_name = '" . $partner_category_name . "', distributor_partner_id = '" . $partner_id . "', distributor_partner_name = '" . $partner_name . "'" . $pt_card_update . " where visitor_group_no in ('" . implode("','", $req['order_references']) . "') and booking_status = '0' ");
            $logs['pt_ update_query_' . date('H:i:s')] = $this->db->last_query();
            $insert_pt_db2['booking_status'] = 1;
            $insert_pt_db2['is_order_confirmed'] = 1;
            $insert_pt_db2['order_confirm_date'] = gmdate("Y-m-d H:i:s");
            $insert_pt_db2['voucher_updated_by'] = $req['users_details']['dist_cashier_id'];
            $insert_pt_db2['voucher_updated_by_name'] = $req['users_details']['cashier_name'];
            $insert_pt_db2['activation_method'] = $payment_method;
            $insert_pt_db2['partner_category_id'] = $partner_category_id;
            $insert_pt_db2['partner_category_name'] = $partner_category_name;
            $insert_pt_db2['distributor_partner_id'] = $partner_id;
            $insert_pt_db2['distributor_partner_name'] = $partner_name;
            $insert_pt_db2['CONCAT_VALUE'] = array("action_performed" => ', ' . $action_performed);

            $insertion_db2[] = array(
                "table" => 'prepaid_tickets',
                "columns" => $insert_pt_db2,
                "where" => " visitor_group_no in ('" . implode("','", $req['order_references']) . "') and booking_status = '0' ");
            $req_array['db2_insertion'] = $insertion_db2;
            $req_array['confirm_order'] = array(
                'voucher_updated_by' => $req['users_details']['dist_cashier_id'],
                'voucher_updated_by_name' => $req['users_details']['cashier_name'],
                'multiple_visitor_group_no' => $req['order_references'],
                'action_performed' => $action_performed,
                'hto_update_for_card' => ($hto_update_for_card == 1) ? $card_values: array()
            );

            $req_array['order_payment_details'] = array(
                'visitor_group_no' => $req['order_references'],
                "amount" => isset($req['amount']) ? $req['amount'] : 0,
                "total" => isset($req['amount']) ? $req['amount'] : 0,
                "psp_type" => isset($card_values['psp_type']) ? $card_values['psp_type'] : 15,
                "psp_reference" => isset($card_values['psp_reference']) ? $card_values['psp_reference'] : "",
                "merchant_reference" => isset($card_values['merchant_reference']) ? $card_values['merchant_reference'] : "",
                "auth_code" => isset($card_values['auth_code']) ? $card_values['auth_code'] : "",
                "status" => 1, //paid
                "method" => $method,
                "type" => 1, //captured payment
                "settlement_type" => 4, //external paymnt
                "settled_on" => date("Y-m-d H:i:s"),
                "cashier_id" => $req['users_details']['dist_cashier_id'],
                "cashier_name" => $req['users_details']['cashier_name'],
                "shift_id" => $req['shift_id'],
                "updated_at" => date("Y-m-d H:i:s"),
            );
            if (isset($req['distributor_id'])) {
                $req_array['order_payment_details']['distributor_id'] = $req['distributor_id'];
            }
            if (isset($req['reseller_id'])) {
                $req_array['order_payment_details']['reseller_id'] = $req['reseller_id'];
            }

            $req_array['write_in_mpos_logs'] = 1;
            $req_array['action'] = "confirm_payment_from_guests";
            $req_array['multiple_visitor_group_no'] = $req['order_references'];
            $logs['data_to_queue_SCANING_ACTION_ARN_' . date('H:i:s')] = $req_array;
            $req_string = json_encode($req_array);
            $aws_message = base64_encode(gzcompress($req_string)); // To compress heavy data data to pass inSQS  message.
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'SCANING_ACTION_ARN');
            } else {
                $this->queue($aws_message, SCANING_ACTION_URL, SCANING_ACTION_ARN);
            }
            $MPOS_LOGS['confirm'] = $logs;
            $response['status'] = 1;
            $response['message'] = "success";
        } else {
            $MPOS_LOGS['confirm'] = $logs;
            $response['status'] = 0;
            $response['message'] = "Already Paid";
        }
        return $response;
    }

    /**
     * @Name check_card_values
     * @Purpose : to prepare details of card array for order payments details table
     * @return array()
     * @param 
     *      $req - array() - card details from req
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 18 sep 2020
     */
    function check_card_values($req = array()) {
        if (isset($req['adyen_details']) && !empty($req['adyen_details'])) {
            $psp_ref = $req['adyen_details']['pspReference'];
            $merchent_ref = $req['adyen_details']['merchantReference'];
            $paymentMethod = $req['adyen_details']['cardType'];
            $merchant_account = $req['adyen_details']['merchantAccount'];
            $shopperReference = $req['adyen_details']['shopperReference'];
            $eventCode = 'Success';
            $payment_gateway = 'ADYEN';
            $psp_type = 1;
        } else if (isset($req['izettle_details']) && !empty($req['izettle_details'])) {
            $psp_ref = $req['izettle_details']['referenceNumber'];
            $paymentMethod = strtolower($req['izettle_details']['cardBrand']);
            $eventCode = 'Success';
            $payment_gateway = 'IZETTLE';
            $authcode = $req['izettle_details']['authorizationCode'];
            $psp_type = 12;
        } else if (isset($req['ni_payment_details']) && !empty($req['ni_payment_details'])) {
            $psp_ref = isset($req['ni_payment_details']['psp_reference']) ? $req['ni_payment_details']['psp_reference'] : "";
            $merchent_ref = isset($req['ni_payment_details']['merchant_reference']) ? $req['ni_payment_details']['merchant_reference'] : "";
            $merchant_account = isset($req['ni_payment_details']['merchant_account']) ? $req['ni_payment_details']['merchant_account'] : "";
            $shopperReference = isset($req['ni_payment_details']['shopper_reference']) ? $req['ni_payment_details']['shopper_reference'] : "";
            $eventCode = 'Success';
            $payment_gateway = 'NetworkInternational';
            $authcode = isset($req['ni_payment_details']['auth_code']) ? $req['ni_payment_details']['auth_code'] : "";
            $psp_type = 14;
        }
        return array(
            "psp_type" => isset($psp_type) ? $psp_type : 15,
            "payment_gateway" => $payment_gateway,
            "eventCode" => $eventCode,
            "psp_reference" => isset($psp_ref) ? $psp_ref : "",
            "merchant_reference" => isset($merchent_ref) ? $merchent_ref : "",
            "paymentMethod" => isset($paymentMethod) ? $paymentMethod : "",
            "auth_code" => isset($authcode) ? $authcode : "",
            "merchant_account" => isset($merchant_account) ? $merchant_account : "",
            "shopperReference" => isset($shopperReference) ? $shopperReference : ""
        );
    }
}

?>