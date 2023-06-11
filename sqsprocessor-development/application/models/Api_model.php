<?php

/**
 * @description     : This file contains API Server related code.
 * @author          : Haripriya <haripriya.intersoft@gmail.com>
 * @date            : 17 Sept 2019
 */
class Api_model extends MY_Model
{

    function __construct()
    {
        /* Call the Model constructor */
        parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_vt_model');
        /* distributor and token for getting ARENA constant value from API end */
        $this->api_url = $this->config->config['api_url'];
        /* constant details for authorization at API end */
        $this->get_api_const = json_decode(TEST_API_DISTRIBUTOR, TRUE);
        $microtime = microtime();
        $search = array('0.', ' ');
        $replace = array('', '');
        $error_reference_no = str_replace($search, $replace, $microtime);
        define('ERROR_REFERENCE_NUMBER', $error_reference_no);
        /* check the statement has been generated or not for the resellers through bigquery statement table */
        define('EVAN_EVANS_DISTRIBUTOR_ID', json_encode(array(5960)));
        if (SERVER_ENVIRONMENT == 'LIVE') {
            define('GOOGLE_API_VERIFY_PASSWORD', 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword');
            define('GOOGLE_API_REFRESH_TOKEN', 'https://securetoken.googleapis.com/v1/token');
            define('FIREBASE_PROJECT_KEY', 'AIzaSyBfA3_C8xMgrUAUxYwM-_K1yqFtX79CxKE');
            define("BIGQUERY_ORDER_STATUS", "https://bqapi.prioticket.com/bigquery/getStatmentOrder/");
            define("BIGQUERY_UPDATE_ORDER", "https://bqapi.prioticket.com/bigquery/updateStatmentOrders/");
            define('CASHIER_ID', 22935);
            define('CASHIER_EMAIL', 'testteam@example.com');
            define('CASHIER_PWD', 'T#est@123');
        } else {
            define('GOOGLE_API_VERIFY_PASSWORD', 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword');
            define('FIREBASE_PROJECT_KEY', 'AIzaSyARvVKAAEx-t6NmEXuxMUm8065hOC7-nYM');
            define("BIGQUERY_ORDER_STATUS", "https://testbqapi.prioticket.com/bigquery/getStatmentOrder/");
            define("BIGQUERY_UPDATE_ORDER", "https://testbqapi.prioticket.com/bigquery/updateStatmentOrders/");
            define('CASHIER_ID', 22935);
            define('CASHIER_EMAIL', 'rokin69@priopass.com');
            define('CASHIER_PWD', 'Admin@123');
        }
    }

    /**
     * @name: update_pt_vt_for_refunded_orders
     * @purpose: To double the entry in pt, vt for refunded orders
     * @where: It is called from pos.php
     * @How it works:
     *      it will get the visitor group no, prepaid_ticket_id and ticket_booking_is, add the refunded entries for that visitor_group_no on the basis of these id's
     *      in pt , vt table
     *      Here at the time of update it willinsert new version enteries in the table
     * @params: array containing key value pair having keys as
     *      visitor_group_no , prepaid_ticket_id, ticket_booking_id , pt, vt, last_db1_prepaid_id, action_from, OTA_action
     * @returns: No parameter is returned
     * @createdby :- haripriya <haripriya.intersoft@gmail.com> Jan 4th, 2018
     * @modifiedby :- haripriya <haripriya.intersoft@gmail.com> Aug 06th, 2019
     */

    function insert_refunded_orders(array $receive_data)
    {
        global $gray_logging;
        $this->ADAM_TOWER_SUPPLIER_ID = json_decode(ADAM_TOWER_SUPPLIER_ID, true);
        $psp_references = $transaction_ids = $arena_prepaid_rows = $prepaid_ticket_ids = $bg_notify = $adam_tower_prepaid_rows = $upsell_pt_data = $upsell_ticket_booking_id = $check_action_performed = $upsell_update_ticket_booking_id = $firebase_notify_data = $notify_api_array = $prepaid_extra_options = $rel_target_ids = array();
        $prepaid_last_id = $extra_pt_id = $flag = $row_type = $last_db1_prepaid_id = $pt = $vt = $notify_to_arena = $transaction_id_flag = $statement = $hotel_id = $notify_api_flag = $is_activated_update = $is_full_cancelled = $per_ticket_booking_cancel = $ticket_update = $psp_reference = $order_refund_via_lambda_queue = $cancel_payment_update_pt = 0;
        $is_amend_order_call = $is_cancel_payment_only = $api_version_booking_email = 0;
        $current_date_time = gmdate('Y-m-d H:i:s');
        $visitor_group_no = $prepaid_ticket_id = $ticket_booking_id = $action_from = $OTA_action = $old_order = $action = $partial_cancel_request = $order_updated_cashier_name = $order_updated_cashier_id = '';
        $cashier_type = 1;
        $order_status_not_insert_lambda = [];
        foreach ($receive_data as $key => $value) {
            ${$key} = $value;
        }
        if (empty($old_order)) {
            $old_order = 'yes';
        }
        if (empty($OTA_action)) {
            if (!empty($action)) {
                $OTA_action = $action;
            } else {
                $OTA_action = 'OAPI22_RFN';
            }
        }
        $prepaid_table = 'prepaid_tickets';
        $vt_table = 'visitor_tickets';
        $channel_type = $is_invoice = $is_bundle_order = 0;
        $tps_count = [];
        $action_performed_array = array('4' => 'GYG_RFN', '6' => $OTA_action, '7' => 'VIATOR_RFN', '8' => 'EXPEDIA_RFN', '9' => 'CSS_RFN', '12' => 'CTRIP_RFN', '15' => 'TourCMS_OTA_Refund');
        if (!empty($visitor_group_no) && $visitor_group_no != '') {
            /* INCASE OF CSS TICKETS ONLY, REDEEMED PASSES CAN BE CANCELLED */
            $prepaid_tickets_data = $this->secondarydb->rodb->select('*')->from($prepaid_table)->where('visitor_group_no = ' . $visitor_group_no)->order_by("prepaid_ticket_id", "ASC")->get()->result_array();
            $db_last_query = $this->secondarydb->rodb->last_query();
            $this->CreateLog('api_refunded_records.php', "PT entries: " . $visitor_group_no, array('query' => $db_last_query, 'pt_ids' => $prepaid_ticket_id, 'data' => json_encode($prepaid_tickets_data)));
            $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_PT_existing_data_from_DB', 'data' => json_encode(array('query' => $db_last_query, 'pt_ids' => $prepaid_ticket_id)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
            if (!empty($prepaid_tickets_data)) {
                /* get fields from primary db, compare db2, db1 fields, get extra fields which are in db2 */
                $primary_prepaid_columns_info = $this->primarydb->db->query("SHOW COLUMNS FROM prepaid_tickets")->result_array();
                foreach ($primary_prepaid_columns_info as $prepaid_column_info) {
                    $primary_prepaid_fields[$prepaid_column_info['Field']] = $prepaid_column_info['Field'];
                }
                $secondary_prepaid_fields = array_keys($prepaid_tickets_data[0]);
                $db2_extra_fieds = array_diff($secondary_prepaid_fields, $primary_prepaid_fields);
                if (isset($ticket_booking_id) && $ticket_booking_id != '') {
                    $ticketbooking_id = str_replace('"', '', $ticket_booking_id);
                    $ticket_booking_ids = array_map("trim", array_unique(explode(',', $ticketbooking_id)));
                }
                if (isset($prepaid_ticket_id) && $prepaid_ticket_id != '') {
                    $prepaidticket_id = str_replace('"', '', $prepaid_ticket_id);
                    $prepaid_ticket_ids = array_map("trim", array_unique(explode(',', $prepaidticket_id)));
                }

                $promocounter = 1;
                $refunded_booking = $not_refunded_booking = [];
                foreach ($prepaid_tickets_data as $key => $data) {
                    if ($data['is_refunded'] == '0' && !in_array($data['ticket_booking_id'], $not_refunded_booking)) {
                        $not_refunded_booking[] = $data['ticket_booking_id'];
                    }
                    if ($data['is_refunded'] == '1' && !in_array($data['ticket_booking_id'], $refunded_booking)) {
                        $refunded_booking[] = $data['ticket_booking_id'];
                    }
                    /** #Comment : Need to Handle Bundle Discount Cancellation Entries in VT table */
                    if (!empty($data['product_type']) && $data['product_type'] == 8) {
                        $tps_count[$data['tps_id']] = 1;
                        $is_bundle_order = 1;
                    }
                    $main_pt_id = $prepaid_last_id = $data['prepaid_ticket_id'];
                    $order_update_cashier_id = $data['cashier_id'];
                    $order_update_cashier_name = $data['cashier_name'];
                    if ($data['is_addon_ticket'] == '0') {
                        $reseller_id = $data['reseller_id'];
                        $merchant_admin_id = $data['merchant_admin_id'];
                        $channel_type = $data['channel_type'] ?? 0;
                        $is_invoice  = $data['is_invoice'] ?? 0;
                    }
                    $hotel_id = $data['hotel_id'];

                    if (isset($ticket_booking_ids) && !empty($ticket_booking_ids) && $data['ticket_booking_id'] == $ticket_booking_ids[0] && in_array($data['is_addon_ticket'], ["0", "1"])) {
                        $main_ticket_id[$data['ticket_id']] = $data['ticket_id'];
                    }
                    $already_action_performed = $data['action_performed'];
                    /* UPSELL INSERT CASE HANDLING IN V3.3 */
                    $check_action_performed = explode(',', $data['action_performed']);
                    if (in_array('UPSELL_INSERT', $check_action_performed)) {
                        $upsell_ticket_booking_id[] = $data['ticket_booking_id'];
                    }
                }
                /* UPSELL INSERT CASE HANDLING IN V3.3 */
                if (!empty($upsell_ticket_booking_id)) {
                    foreach ($prepaid_tickets_data as $pt_key => $pt_data) {
                        if (!in_array($pt_data['ticket_booking_id'], $upsell_ticket_booking_id) && empty($pt_data['activated'])) {
                            $upsell_pt_data[] = $pt_data;
                        }
                    }
                    if (!empty($upsell_pt_data) && !empty($partial_cancel_request)) {
                        $upsell_pt_data_max_version = $this->api_get_max_version_data($upsell_pt_data, 'prepaid_ticket_id');
                        $upsell_version = (max(array_unique(array_column($prepaid_tickets_data, 'version'))) + 1);
                        foreach ($upsell_pt_data_max_version as $upsell_key => $upsell_value) {
                            unset($upsell_value['last_modified_at']);
                            $upsell_update_ticket_booking_id[] = $upsell_value['ticket_booking_id'];
                            $insert_upsell_prepaid_row = $upsell_value;
                            $insert_upsell_prepaid_row['version'] = (int)$upsell_version;
                            $insert_upsell_prepaid_row['activated'] = '1';
                            $insert_upsell_prepaid_row['updated_at'] = $current_date_time;
                            $insert_upsell_prepaid_row['deleted'] = '0';
                            $upsell_action_performed = $insert_upsell_prepaid_row['action_performed'] = $upsell_value['action_performed'] . ', OAPI33_UPSELL';
                            $insert_upsell_pt_rows[] = $insert_upsell_prepaid_row;
                        }
                        $this->insert_batch($prepaid_table, $insert_upsell_pt_rows, "1");
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->insert_batch($prepaid_table, $insert_upsell_pt_rows, "4");
                        }
                        /* $this->db->where('is_refunded not in ("1","2")');
                        $this->db->where('activated = "0" and deleted = "0" and visitor_group_no = ' . $visitor_group_no);
                        $this->db->where_in('ticket_booking_id', $upsell_update_ticket_booking_id);
                        $this->db->update('prepaid_tickets', array('action_performed' => $upsell_action_performed, 'activated' => '1'), array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id)); */
                    }
                }
                /* UPSELL INSERT CASE HANDLING IN V3.3 END*/
                /*NEED TO UPDATE EXPEDIA_PT TABLE IF ORDER IS CANCEL FROM V3.2*/
                if (($OTA_action == 'OAPI32_RFN' || strpos($OTA_action, 'OAPI3') !== false) && in_array($hotel_id, [846, 2423, 1011])) {
                    $this->db->where('is_refunded not in ("1","2")');
                    $this->db->where('order_status = "0" and deleted = "0" and is_cancelled = "0"');
                    $this->db->update('expedia_prepaid_tickets', array('action_performed' => $already_action_performed . ', ' . $OTA_action, 'is_refunded' => '2'), array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id));
                }
                /* statement checking */
                if ($reseller_id == STATEMENT_RESELLER_ID || $merchant_admin_id == STATEMENT_RESELLER_ID) {
                    $other_data['channel_type'] = $channel_type;
                    $other_data['is_invoice'] = $is_invoice;
                    $bg_notify = $this->statement_checking(array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id, 'other_data' => $other_data));
                    $statement = 1;
                }
                if (isset($action_from) && $action_from != '' && $last_db1_prepaid_id != 0) {
                    $main_pt_id = $prepaid_last_id = $last_db1_prepaid_id;
                }
                $this->CreateLog('api_refunded_records.php', "PT entries: " . $visitor_group_no, array('pt_ids' => $main_pt_id));
                $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_pt_ids', 'data' => json_encode(array('pt_ids' => $main_pt_id, 'big_query_data' => $bg_notify)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                $flag = 0;
                $pt_row_ids = array();
                if (isset($ticket_booking_ids) && !empty($ticket_booking_ids)) {
                    foreach ($prepaid_tickets_data as $key => $data) {
                        if ((in_array($OTA_action, ['OAPI32_RFN', 'OAPI33_RFN', 'OAPI33_INACTIVE']) || strpos($OTA_action, 'OAPI3') !== false)  && isset($prepaid_ticket_ids) && !empty($prepaid_ticket_ids)) {
                            if (!in_array($data['prepaid_ticket_id'], $prepaid_ticket_ids)) {
                                $flag++;
                                unset($prepaid_tickets_data[$key]);
                            }
                        } else {
                            if (!in_array($data['ticket_booking_id'], $ticket_booking_ids)) {
                                $flag++;
                                unset($prepaid_tickets_data[$key]);
                            }
                        }
                        $pt_row_ids[] = $data['prepaid_ticket_id'];
                    }
                }
                if ($flag == 0) {
                    foreach ($prepaid_tickets_data as $values) {
                        $pt_row_ids[] = $values['prepaid_ticket_id'];
                    }
                }
                $this->CreateLog('api_refunded_records.php', "PT dataa: " . $visitor_group_no, array('$ticketbooking_id' => $ticketbooking_id, '$ticket_booking_ids' => json_encode($ticket_booking_ids), 'data' => json_encode($prepaid_tickets_data)));
                $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_PT_data', 'data' => json_encode(array('$ticketbooking_id' => $ticketbooking_id, '$ticket_booking_ids' => json_encode($ticket_booking_ids), 'PT_row_ids' => $pt_row_ids)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                $insert_prepaid_rows = array();
                $vt_last_query = '';
                /* Insert same rows instead of updating in financial tables*/
                $prepaid_tickets_data = $this->api_get_max_version_data($prepaid_tickets_data, 'prepaid_ticket_id');
                $ticket_pt_versions = array();
                foreach ($prepaid_tickets_data as $prepaid_tickets_detail) {
                    $ticket_pt_versions[$prepaid_tickets_detail['ticket_booking_id']][] = $prepaid_tickets_detail['version'];
                }
                $version = (max(array_unique(array_column($prepaid_tickets_data, 'version'))) + 1);
                $pt_max_version = (max(array_unique(array_column($prepaid_tickets_data, 'version'))));
                $exist_ticket_booking_ids = array();
                foreach ($prepaid_tickets_data as $data) {
                    unset($data['last_modified_at']);
                    $insert_version_prepaid_row = $data;
                    $insert_version_prepaid_row['is_refunded'] = '2';
                    $insert_version_prepaid_row['deleted'] = '0';
                    $insert_version_prepaid_row['shift_id'] = '0';
                    /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                    if (!empty($shift_id)) {
                        $insert_version_prepaid_row['shift_id'] = $shift_id;
                    }
                    if (!empty($pos_point_id)) {
                        $insert_version_prepaid_row['pos_point_id'] = $pos_point_id;
                    }
                    if (!empty($pos_point_name)) {
                        $insert_version_prepaid_row['pos_point_name'] = $pos_point_name;
                    }
                    if (!empty($cashier_register_id)) {
                        $insert_version_prepaid_row['cashier_register_id'] = $cashier_register_id;
                    }
                    /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                    $insert_version_prepaid_row['order_updated_cashier_id']  = !empty($order_updated_cashier_id) ? $order_updated_cashier_id : $data['cashier_id'];
                    $insert_version_prepaid_row['order_updated_cashier_name']  = !empty($order_updated_cashier_name) ? $order_updated_cashier_name : $data['cashier_name'];
                    $insert_version_prepaid_row['order_status'] = $data['order_status'];
                    if (!empty($is_static_cancel_call) && (in_array($data['prepaid_ticket_id'], $lambda_prepaid_ids)) && empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                        continue;
                    }
                    if (!empty($data['order_status']) && (in_array($data['order_status'], [13]))) {
                        $order_status_not_insert_lambda[$data['ticket_booking_id']] = $data['ticket_booking_id'];
                        continue;
                    }
                    /** #Comment : Save Canellation Reason in PT DB2 in refund_note Column */
                    if (!empty($cancellation_reason)) {
                        $insert_version_prepaid_row['refund_note'] = $cancellation_reason;
                    }
                    $insert_version_prepaid_row['order_status'] = '0';
                    if (!empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                        $insert_version_prepaid_row['order_status'] = '0';
                    }
                    if (!in_array($data['ticket_booking_id'], $exist_ticket_booking_ids) && !empty($OTA_action) && isset($ticket_pt_versions[$data['ticket_booking_id']]) &&  strpos($OTA_action, 'OAPI3') !== false) {
                        $version = max($ticket_pt_versions[$data['ticket_booking_id']]) + 1;
                    }
                    $exist_ticket_booking_ids[] = $data['ticket_booking_id'];
                    $insert_version_prepaid_row['version'] = (int)$version;
                    if (isset($action_from) && $action_from != '') {
                        $insert_version_prepaid_row['activated'] = '0';
                        if ($ticket_update == 0) {
                            $is_amend_order_call = 1;
                            $insert_version_prepaid_row['is_iticket_product'] = '1';
                        }
                    } else if ($partial_cancel_request != '' && $is_cancel_payment_only == 0) {
                        $insert_version_prepaid_row['activated'] = '0';
                    }
                    if (isset($is_activated_update) && $is_activated_update == 1) {
                        $insert_version_prepaid_row['activated'] = '0';
                    }
                    if (isset($order_refund_via_lambda_queue) && $order_refund_via_lambda_queue == 1 && empty($lambda_refund_cancel_call)) {
                        $insert_version_prepaid_row['order_status'] = '13';
                    } else if (!empty($lambda_refund_cancel_call)) {
                        $insert_version_prepaid_row['order_status'] = '0';
                    }

                    if (!empty($OTA_action) && in_array($OTA_action, json_decode(OTA_NAME_ACTIVATED))) {
                        $insert_version_prepaid_row['activated'] = '0';
                    }
                    $insert_version_prepaid_row['action_performed'] = $data['action_performed'] . ', ' . ((($OTA_action != 'OAPI32_RFN' || strpos($OTA_action, 'OAPI3') === false) && !empty($action_performed_array[$data['channel_type']])) ? $action_performed_array[$data['channel_type']] : $OTA_action);
                    $insert_version_prepaid_row['order_cancellation_date'] = $insert_version_prepaid_row['updated_at'] = $current_date_time;
                    $insert_version_pt_rows[] = $insert_version_prepaid_row;
                    if ($data['is_addon_ticket'] == '0') {
                        $firebase_notify_data[] = $data;
                    }
                }
                if (!empty($insert_version_pt_rows)) {
                    $this->insert_batch($prepaid_table, $insert_version_pt_rows, "1");
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                        $this->insert_batch($prepaid_table, $insert_version_pt_rows, "4");
                    }
                    unset($insert_version_pt_rows, $insert_version_prepaid_row);
                }
                /* from here it will again select data from table in order to get highest version data that should be inserted in refunded rows */
                //$verion_prepaid_tickets_data = $this->secondarydb->rodb->select('*')->from($prepaid_table)->where('visitor_group_no = ' . $visitor_group_no)->order_by("prepaid_ticket_id", "ASC")->get()->result_array();
                //$prepaid_tickets_data = $this->api_get_max_version_data($verion_prepaid_tickets_data, 'prepaid_ticket_id');

                /* foreach ($prepaid_tickets_data as $key => $data) {
                    $main_pt_id = $prepaid_last_id = $data['prepaid_ticket_id'];
                    $order_update_cashier_id = $data['cashier_id'];
                    $order_update_cashier_name = $data['cashier_name'];
                    $reseller_id = $data['reseller_id'];
                    $hotel_id = $data['hotel_id'];
                    if (isset($ticket_booking_ids) && !empty($ticket_booking_ids) && $data['ticket_booking_id'] == $ticket_booking_ids[0] && in_array($data['is_addon_ticket'], ["0","1"])) {
                        $main_ticket_id[$data['ticket_id']] = $data['ticket_id'];
                    }
                }
                if (isset($action_from) && $action_from != '' && $last_db1_prepaid_id != 0) {
                    $main_pt_id = $prepaid_last_id = $last_db1_prepaid_id;
                }
                $flag = 0;
                $pt_row_ids = array();
                if (isset($ticket_booking_ids) && !empty($ticket_booking_ids)) {
                    foreach ($prepaid_tickets_data as $key => $data) {                    
                        if ((in_array($OTA_action, ['OAPI32_RFN', 'OAPI33_RFN', 'OAPI33_INACTIVE'])  || strpos($OTA_action, 'OAPI3') !== false)  && isset($prepaid_ticket_ids) && !empty($prepaid_ticket_ids)) {
                            if (!in_array($data['prepaid_ticket_id'], $prepaid_ticket_ids)) {
                                $flag++;
                                unset($prepaid_tickets_data[$key]);
                            }
                        } else {
                            if (!in_array($data['ticket_booking_id'], $ticket_booking_ids)) {
                                $flag++;
                                unset($prepaid_tickets_data[$key]);
                            }
                        }
                        $pt_row_ids[] = $data['prepaid_ticket_id'];
                    }
                } */
                /* Insert same rows instead of updating in financial tables*/
                $pt_insertion_key = 0;
                $is_refunded_prepaid_tickets_id = [];
                $this->CreateLog('api_refunded_records.php', "PT entries: " . $visitor_group_no, array(json_encode($pt_row_ids)));
                $exist_ticket_booking_ids = $prepaid_extra_booking_information = array();
                foreach ($prepaid_tickets_data as $data) {
                    $prepaid_extra_booking_information = isset($data['extra_booking_information']) ? json_decode(stripslashes(stripslashes($data['extra_booking_information'])), true) : [];
                    if (!isset($main_ticket_id[$data['ticket_id']]) && in_array($data['is_addon_ticket'], ["0", "1"])) {
                        $main_ticket_id[$data['ticket_id']] = $data['ticket_id'];
                    }
                    $refunded_prepaid_ids[] = $data['prepaid_ticket_id'];;
                    $prepaid_ticket_id_for_refund = $data['prepaid_ticket_id'];
                    $order_update_cashier_id = (isset($order_updated_cashier_id) && $order_updated_cashier_id) ? $order_updated_cashier_id : $data['cashier_id'];
                    $order_update_cashier_name = (isset($order_updated_cashier_name) && $order_updated_cashier_name) ? $order_updated_cashier_name : $data['cashier_name'];
                    $created_date_time = $data['created_date_time'];
                    unset($data['last_modified_at']);
                    unset($data['prepaid_ticket_id']);
                    $is_greater_july = 0;
                    /* common data out from these if loops */
                    $insert_prepaid_row = $data;
                    if (!empty($data['financial_id'])) {
                        $rel_target_ids[] = $data['financial_id'];
                    }
                    if (isset($action_from) && $action_from != '') {
                        $insert_prepaid_row['activated'] = '0';
                        if ($ticket_update == 0) {
                            $is_amend_order_call = 1;
                            $insert_prepaid_row['is_iticket_product'] = '1';
                        }
                    } else if ($partial_cancel_request != '' && $is_cancel_payment_only == 0) {
                        $insert_prepaid_row['activated'] = '0';
                    }
                    if (isset($is_activated_update) && $is_activated_update == 1) {
                        $insert_prepaid_row['activated'] = '0';
                    }
                    if (!empty($OTA_action) && in_array($OTA_action, json_decode(OTA_NAME_ACTIVATED))) {
                        $insert_prepaid_row['activated'] = '0';
                    }
                    $insert_prepaid_row['action_performed'] = $data['action_performed'] . ', ' . ((($OTA_action != 'OAPI32_RFN' || strpos($OTA_action, 'OAPI3') === false) && !empty($action_performed_array[$data['channel_type']])) ? $action_performed_array[$data['channel_type']] : $OTA_action);
                    $insert_prepaid_row['order_cancellation_date'] = $insert_prepaid_row['updated_at'] = $current_date_time;
                    if (!in_array($data['ticket_booking_id'], $exist_ticket_booking_ids) && !empty($OTA_action) && isset($ticket_pt_versions[$data['ticket_booking_id']]) &&  strpos($OTA_action, 'OAPI3') !== false) {
                        $version = max($ticket_pt_versions[$data['ticket_booking_id']]) + 1;
                    }
                    $exist_ticket_booking_ids[] = $data['ticket_booking_id'];
                    $insert_prepaid_row['version'] = (int)$version;
                    $insert_prepaid_row['is_refunded'] = '1';
                    $insert_prepaid_row['deleted'] = '0';
                    $insert_prepaid_row['shift_id'] = '0';
                    /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                    $insert_prepaid_row['shift_id'] = '0';
                    if (!empty($shift_id)) {
                        $insert_prepaid_row['shift_id'] = $shift_id;
                    }
                    if (!empty($pos_point_id)) {
                        $insert_prepaid_row['pos_point_id'] = $pos_point_id;
                    }
                    if (!empty($pos_point_name)) {
                        $insert_prepaid_row['pos_point_name'] = $pos_point_name;
                    }
                    if (!empty($cashier_register_id)) {
                        $insert_prepaid_row['cashier_register_id'] = $cashier_register_id;
                    }
                    /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                    $insert_prepaid_row['order_status'] = $data['order_status'];
                    if (!empty($is_static_cancel_call) && (in_array($prepaid_ticket_id_for_refund, $lambda_prepaid_ids)) && empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                        continue;
                    }
                    if (!empty($data['order_status']) && (in_array($data['order_status'], [13]))) {
                        continue;
                    }
                    $insert_prepaid_row['order_status'] = '0';
                    if (!empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                        $insert_prepaid_row['order_status'] = '0';
                    }
                    /** #Comment : Save Canellation Reason in PT DB2 in refund_note Column */
                    if (!empty($cancellation_reason)) {
                        $insert_prepaid_row['refund_note'] = $cancellation_reason;
                    }
                    if (isset($order_refund_via_lambda_queue) && $order_refund_via_lambda_queue == 1 && empty($lambda_refund_cancel_call)) {
                        $insert_prepaid_row['order_status'] = '13';
                    } else if (!empty($lambda_refund_cancel_call)) {
                        $insert_prepaid_row['order_status'] = '0';
                    }
                    $insert_prepaid_row['is_cancelled'] = '1';
                    if (!empty($psp_references[$data['ticket_booking_id']])) {
                        $insert_prepaid_row['pspReference'] = $psp_references[$data['ticket_booking_id']];
                    } else if (!empty($psp_reference)) {
                        $insert_prepaid_row['pspReference'] = $psp_reference;
                    }
                    $insert_prepaid_row['order_updated_cashier_id'] = $order_update_cashier_id;
                    $insert_prepaid_row['order_updated_cashier_name'] = $order_update_cashier_name;
                    if ($old_order == 'yes') {
                        $insert_prepaid_row['order_cancellation_date'] = $insert_prepaid_row['order_confirm_date'] = $insert_prepaid_row['updated_at'] = $current_date_time;
                    }
                    /* If the order is done before primary key i.e. id changes */
                    if (strtotime(gmdate("Y-m-d", strtotime($data['created_date_time']))) > strtotime("2018-07-12 10:26:00")) {
                        $is_greater_july = 1;
                        if (strpos($data['action_performed'], '_PMTRFN') !== false && $data['is_refunded'] == 2) {
                            $is_refunded_prepaid_tickets_id[$prepaid_ticket_id_for_refund] = 1;
                        }
                        //$pt_max_id_last_three_digit = substr($prepaid_last_id, -3);
                        $pt_max_id_last_three_digit = substr($prepaid_last_id, strlen($visitor_group_no));
                        $last_three_count = $pt_max_id_last_three_digit + 1;
                        $last_three_new_count = str_pad($last_three_count, 3, "0", STR_PAD_LEFT);
                        $insert_prepaid_row['prepaid_ticket_id'] = $visitor_group_no . $last_three_new_count;
                        $insert_prepaid_row['visitor_tickets_id'] = $insert_prepaid_row['prepaid_ticket_id'] . ($data['is_addon_ticket'] == '0' ? '01' : '02');
                        $prepaid_last_id = $insert_prepaid_row['prepaid_ticket_id'];
                        $insert_prepaid_rows[] = $insert_prepaid_row;
                        if (empty($is_refunded_prepaid_tickets_id) || empty($is_refunded_prepaid_tickets_id[$prepaid_ticket_id_for_refund])) {
                            $insert_prepaid_row['prepaid_ticket_id'] = $insert_prepaid_row['prepaid_ticket_id'] - $pt_insertion_key;
                            $data_to_inserted_in_pt1[] = $insert_prepaid_row;
                        } else {
                            $pt_insertion_key++;
                        }
                        if ($data['museum_id'] == ARENA_SUPPLIER_ID) {
                            $arena_prepaid_rows[] = $insert_prepaid_row;
                        }
                        /* Handle case to notify not arena supplier */
                        if (!empty(NOTIFY_SUPPLIER_NOT_ARENA)) {
                            $notify_api_flag =  1;
                            $notify_api_array[] = $insert_prepaid_row;
                        }
                        if (in_array($data['museum_id'], $this->ADAM_TOWER_SUPPLIER_ID)) {
                            $adam_tower_prepaid_rows[] = $insert_prepaid_row;
                        }
                    } else {
                        $secondarty_db_data = $insert_prepaid_row;
                        /* unset fields from array which are not in db1 but having in db2 so that get no error in db1 while insertion  */
                        if (!empty($db2_extra_fieds)) {
                            foreach ($db2_extra_fieds as $extra_field) {
                                unset($insert_prepaid_row[$extra_field]);
                            }
                        }
                        if ($pt == '1') {
                            $this->db->insert('prepaid_tickets', $insert_prepaid_row);
                        }
                        $secondarty_db_data['prepaid_ticket_id'] = $this->db->insert_id();
                        $insert_prepaid_rows[] = $secondarty_db_data;
                        if ($data['museum_id'] == ARENA_SUPPLIER_ID) {
                            $arena_prepaid_rows[] = $insert_prepaid_row;
                        }
                        /* Handle case to notify not arena supplier */
                        if (!empty(NOTIFY_SUPPLIER_NOT_ARENA)) {
                            $notify_api_flag =  1;
                            $notify_api_array[] = $insert_prepaid_row;
                        }
                        if (in_array($data['museum_id'], $this->ADAM_TOWER_SUPPLIER_ID)) {
                            $adam_tower_prepaid_rows[] = $insert_prepaid_row;
                        }
                    }
                }
                $this->CreateLog('api_refunded_records.php', "PT refunded entries: " . $visitor_group_no, array(json_encode($insert_prepaid_rows)));
                $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_PT_refund_entries', 'data' => json_encode($insert_prepaid_rows), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                if (!empty($insert_prepaid_rows) && $pt == '1') {
                    /* insert in primary using batch if created_date_time greater than 12 july */
                    if ($is_greater_july == 1) {
                        $this->insert_batch('prepaid_tickets', $data_to_inserted_in_pt1);
                    }
                    $this->insert_batch($prepaid_table, $insert_prepaid_rows, "1");
                    /* To update the data in RDS realtime */
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                        $this->insert_batch($prepaid_table, $insert_prepaid_rows, "4");
                    }
                }
                /*Extra options refund entry handling*/
                $prepaid_extra_options = $this->find('prepaid_extra_options', array('select' => 'prepaid_extra_options_id', 'where' => 'visitor_group_no = ' . $visitor_group_no . ' and ticket_id in (' . implode(',', $main_ticket_id) . ')'));
                if (!empty($prepaid_extra_options[0]['prepaid_extra_options_id'])) {
                    if (!empty($prepaid_ticket_id)) {
                        $prepaid_ticket_id = implode(',', array_merge(explode(',', $prepaid_ticket_id), array_column($prepaid_extra_options, 'prepaid_extra_options_id')));
                    }
                    if ($refunded_prepaid_ids) {
                        $refunded_prepaid_ids = array_merge($refunded_prepaid_ids, array_column($prepaid_extra_options, 'prepaid_extra_options_id'));
                    }
                }
                $vt_where = "is_refunded = 0 and vt_group_no = " . $visitor_group_no;
                /* Handle code for cancel promocode refunded entry.*/
                $this->CreateLog('discount_refunded_data.php', "prepaid_tickets_data[0] entries: " . $visitor_group_no, array(json_encode($prepaid_extra_booking_information)));
                if (
                    ($is_full_cancelled == 0 && in_array($OTA_action, ['OAPI35_RFN', 'OAPI34_RFN', 'OAPI33_RFN'])) || 
                    ($is_cancel_payment_only == 1 && $cancel_payment_update_pt == 1 && (in_array($OTA_action, ['OAPI35_PMTRFN', 'OAPI34_PMTRFN', 'OAPI33_PMTRFN']) || (!empty($is_enable_cancellation_refund_amendment) && in_array($OTA_action, ['OAPI35_INACTIVE', 'OAPI34_INACTIVE', 'OAPI33_INACTIVE']))) ) 
                    ) {
                    $vt_where_new = "is_refunded IN('2', '0') and vt_group_no = " . $visitor_group_no;
                    $vt_table_transaction_data = $this->secondarydb->rodb->select('id,transaction_id, row_type, is_refunded, version, ticket_booking_id')->from($vt_table)->where($vt_where_new)->get()->result_array();
                    if (!empty($vt_table_transaction_data)) {
                        foreach ($vt_table_transaction_data as $vt_table_transaction_data_one) {
                            if ($vt_table_transaction_data_one['is_refunded'] == '0' && !in_array($vt_table_transaction_data_one['transaction_id'], $vt_table_transaction_ids)) {
                                $vt_table_transaction_ids[] = $vt_table_transaction_data_one['transaction_id'];
                            }
                        }
                    }
                    $row_types = array_unique(array_column($vt_table_transaction_data, 'row_type'));
                    if (!empty($row_types)) {
                        $this->CreateLog('row_type_data.php', "row_types entries: " . $visitor_group_no, array(json_encode($row_types)));
                        $exploded_prepaid_ticket_ids = explode(',', $prepaid_ticket_id);
                        foreach ($vt_table_transaction_data as $vt_table_transaction_data_single) {
                            if (in_array($vt_table_transaction_data_single['transaction_id'], $exploded_prepaid_ticket_ids) && !in_array($vt_table_transaction_data_single['version'], $version_arr)) {
                                $version_arr[] = $vt_table_transaction_data_single['version'];
                            }
                        }
                        /* add row_type 12 refunded entries. */
                        if (in_array('12', $row_types)) {
                            $not_refunded_service_cost = array_diff($not_refunded_booking, $refunded_booking);

                            if (count(array_unique($not_refunded_service_cost)) == count(array_unique($ticket_booking_ids))) {
                                foreach ($vt_table_transaction_data as $vt_table_transaction_data_single) {
                                    if (in_array($vt_table_transaction_data_single['version'], $version_arr) && $vt_table_transaction_data_single['row_type'] == '1') {
                                        $exploded_prepaid_ticket_ids[] = $vt_table_transaction_data_single['id'];
                                    }
                                }
                            }
                        }
                        /* add row_type 18 and 19 refunded entries. */
                        if (in_array('18', $row_types) || in_array('19', $row_types)) {

                            $is_refunded_zero = $is_refunded_one = $is_refunded_other_version = [];
                            foreach ($vt_table_transaction_data as $vt_table_transaction_data_single) {
                                if ($vt_table_transaction_data_single['is_refunded'] == '0' && in_array($vt_table_transaction_data_single['row_type'], ['18', '19']) && !in_array($vt_table_transaction_data_single['transaction_id'], $is_refunded_zero) && in_array($vt_table_transaction_data_single['version'], $version_arr) && in_array($vt_table_transaction_data_single['ticket_booking_id'], $ticket_booking_ids)) {
                                    $is_refunded_zero[] = $vt_table_transaction_data_single['transaction_id'];
                                }
                                if ($vt_table_transaction_data_single['is_refunded'] == '0' && in_array($vt_table_transaction_data_single['row_type'], ['18', '19']) && !in_array($vt_table_transaction_data_single['transaction_id'], $is_refunded_other_version) && !in_array($vt_table_transaction_data_single['version'], $version_arr) && in_array($vt_table_transaction_data_single['ticket_booking_id'], $ticket_booking_ids)) {
                                    $is_refunded_other_version[] = $vt_table_transaction_data_single['transaction_id'];
                                }
                                if ($vt_table_transaction_data_single['is_refunded'] == '2' && in_array($vt_table_transaction_data_single['row_type'], ['18', '19']) && !in_array($vt_table_transaction_data_single['transaction_id'], $is_refunded_one)) {
                                    $is_refunded_one[] = $vt_table_transaction_data_single['transaction_id'];
                                }
                            }
                            $not_refunded_transaction_service_fees = array_values(array_diff($is_refunded_zero, $is_refunded_one));
                            $not_refunded_transaction_service_fees = array_values(array_diff($not_refunded_transaction_service_fees, $is_refunded_other_version));
                            for ($i = 0; $i < count($ticket_booking_ids); $i++) {
                                if (isset($not_refunded_transaction_service_fees[$i])) {
                                    $exploded_prepaid_ticket_ids[] = $not_refunded_transaction_service_fees[$i];
                                }
                            }
                        }
                        $prepaid_ticket_id = implode(',', $exploded_prepaid_ticket_ids);
                        $this->CreateLog('row_type_data.php', "prepaid_ticket_id entries: " . $visitor_group_no, array(json_encode($prepaid_ticket_id)));
                    }
                    if (!empty($prepaid_extra_booking_information)) {
                        $discount_codes_details = $prepaid_extra_booking_information["discount_codes_details"] ? $prepaid_extra_booking_information["discount_codes_details"] : array();
                        $this->CreateLog('discount_refunded_data.php', "prepaid_extra_booking_information entries: " . $visitor_group_no, array(json_encode($prepaid_extra_booking_information)));
                        $this->CreateLog('discount_refunded_data.php', "discount_codes_details entries: " . $visitor_group_no, array(json_encode($discount_codes_details)));
                        if (!empty($discount_codes_details)) {
                            $promocode_count = count($discount_codes_details);
                            $last_promo_entry_no_exist = ($promocode_count * $pt_max_version);
                            $last_promo_entry_no = ($promocode_count * $pt_max_version) - $promocode_count;
                            $transction_check_start = 900 + $last_promo_entry_no;
                            $transaction_to_check = $transction_check_start;
                            for ($j = $last_promo_entry_no_exist; $j > 0; $j--) {
                                $old_promo_id = 900 + $j;
                                $transaction_to_check = $visitor_group_no . $old_promo_id;
                                if (in_array($transaction_to_check, $vt_table_transaction_ids)) {
                                    $transction_check_start = $old_promo_id - $promocode_count;
                                    break;
                                }
                            }
                            $this->CreateLog('discount_refunded_data.php', "transction_check_start entries: " . $visitor_group_no, array(json_encode($transction_check_start)));
                            $exploded_prepaid_ticket_ids = explode(',', $prepaid_ticket_id);
                            for ($i = 1; $i <= $promocode_count; $i++) {
                                $new_promo_id = $transction_check_start + $i;
                                $exploded_prepaid_ticket_ids[] = $visitor_group_no . $new_promo_id;
                                $refunded_prepaid_ids[] = $visitor_group_no . $new_promo_id;
                            }
                            $prepaid_ticket_id = implode(',', $exploded_prepaid_ticket_ids);
                        }
                    }
                }
                $this->CreateLog('discount_refunded_data.php', "prepaid_ticket_id entries: " . $visitor_group_no, array(json_encode($prepaid_ticket_id)));
                $this->CreateLog('discount_refunded_data.php', "refunded_prepaid_ids entries: " . $visitor_group_no, array(json_encode($refunded_prepaid_ids)));

                /* Refund enteries in VT table related to Promocode END */

                /** #Comment : Need to Add Bundle Discount Transaction ID in PT ids */
                if (!empty($is_bundle_order)) {
                    $this->secondarydb->db->select('transaction_id, ticket_booking_id, version, is_refunded');
                    $this->secondarydb->db->from('visitor_tickets');
                    $this->secondarydb->db->where("vt_group_no", $visitor_group_no);
                    $this->secondarydb->db->where("row_type", '1');
                    $this->secondarydb->db->where("deleted", '0');
                    $this->secondarydb->db->where("transaction_type_name", 'Bundle Discount');
                    $result = $this->secondarydb->db->group_by("transaction_id")->get();
                    if ($result->num_rows() > 0) {
                        $result = $result->result();
                    }
                    $booking_max_version = $bundle_data = [];
                    foreach ($result as $res) {
                        if (empty($booking_max_version[$res->ticket_booking_id])) {
                            $booking_max_version[$res->ticket_booking_id] = $res->version ?? '';
                        } else if ($res->version > $booking_max_version[$res->ticket_booking_id]) {
                            $booking_max_version[$res->ticket_booking_id] = $res->version ?? '';
                        }
                    }
                    foreach ($result as $res) {
                        if ($res->version == $booking_max_version[$res->ticket_booking_id] && $res->is_refunded == '0') {
                            $bundle_data[] = $res->transaction_id;
                        }
                    }
                    foreach ($bundle_data as $transaction_id){
                        $prepaid_ticket_id = $prepaid_ticket_id.','.$transaction_id;
                    } 
                }
                /** #Comment : END :: Need to Add Bundle Discount Transaction ID in PT ids */

                /* BOC to get the data from Vt in case of cancellation */

                if (isset($prepaid_ticket_id) && $prepaid_ticket_id != '' && strtotime(gmdate("Y-m-d", strtotime($created_date_time))) > strtotime("2018-07-12 10:26:00") && $is_full_cancelled == 0) {
                    $vt_where .= ' AND transaction_id in (' . $prepaid_ticket_id . ')';
                } else if ($is_full_cancelled == 0) {
                    $refund_transaction_id = implode(',', $refunded_prepaid_ids);
                    $vt_where .= ' AND transaction_id in (' . $refund_transaction_id . ')';
                } else if (!empty($is_full_cancelled) && !empty($prepaid_ticket_id)) {
                    $vt_where .= ' AND transaction_id in (' . $prepaid_ticket_id . ')';
                }
                $this->CreateLog('discount_refunded_data.php', "vt_where entries: " . $visitor_group_no, array(json_encode($vt_where)));
                $visitor_tickets_data = $this->secondarydb->rodb->select('*')->from($vt_table)->where($vt_where)->order_by("id", "ASC")->get()->result_array();
                $vt_last_query = $this->secondarydb->rodb->last_query();
                $this->CreateLog('api_refunded_records.php', "VT entries: " . $visitor_group_no, array('query' => $vt_last_query, 'data' => json_encode($visitor_tickets_data)));
                $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_VT_entries', 'data' => json_encode(array('query' => $vt_last_query)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                $bundle_last_id = 0;
                if (!empty($visitor_tickets_data)) {
                    /* Insert same rows instead of updating in financial tables*/
                    $visitor_tickets_data = $this->api_get_max_version_data($visitor_tickets_data, 'id');
                    $ticket_vt_versions = array();
                    foreach ($visitor_tickets_data as $visitor_tickets_detail) {
                        $ticket_vt_versions[$visitor_tickets_detail['ticket_booking_id']][] = $visitor_tickets_detail['version'];
                    }
                    $vt_version = (max(array_unique(array_column($visitor_tickets_data, 'version'))) + 1);
                    $exist_ticket_booking_ids = array();
                    foreach ($visitor_tickets_data as $data) {
                        unset($data['last_modified_at']);
                        $insert_version_visitor_row = $data;
                        $insert_version_visitor_row['is_refunded'] = '2';
                        $insert_version_visitor_row['deleted'] = '0';
                        $insert_version_visitor_row['shift_id'] = '0';
                        /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                        if (!empty($shift_id)) {
                            $insert_version_visitor_row['shift_id'] = $shift_id;
                        }
                        if (!empty($pos_point_id)) {
                            $insert_version_visitor_row['pos_point_id'] = $pos_point_id;
                        }
                        if (!empty($pos_point_name)) {
                            $insert_version_visitor_row['pos_point_name'] = $pos_point_name;
                        }
                        if (!empty($cashier_register_id)) {
                            $insert_version_visitor_row['cashier_register_id'] = $cashier_register_id;
                        }
                        if ($data['transaction_type_name'] == 'Bundle Discount') {
                            $bundle_last_id = $data['transaction_id'];
                        }
                        /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                        $insert_version_visitor_row['order_updated_cashier_id'] = $order_update_cashier_id ?? '';
                        $insert_version_visitor_row['order_updated_cashier_name'] = $order_update_cashier_name ?? '';
                        $insert_version_visitor_row['order_status'] = $data['order_status'];
                        if (!empty($is_static_cancel_call) && (in_array($data['transaction_id'], $lambda_prepaid_ids)) && empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                            /** #Comment: - No need to Insert Lambda Order Refunnd Entries */
                            continue;
                        }
                        /** #Comment : Skip Entries For Lambda Booking which is Not Refunded*/
                        if (!empty($is_bundle_order) && !in_array($data['ticket_booking_id'], $ticket_booking_ids)) {
                          continue;
                        }
                        /** Not insert order status 13 Entries in DB */
                        if (!empty($order_status_not_insert_lambda[$data['ticket_booking_id']])) {
                            continue;
                        }
                        if (!empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                            $insert_version_visitor_row['order_status'] = '0';
                        }
                        if (!in_array($data['ticket_booking_id'], $exist_ticket_booking_ids) && !empty($OTA_action) && isset($ticket_vt_versions[$data['ticket_booking_id']]) &&  strpos($OTA_action, 'OAPI3') !== false) {
                            $vt_version = max($ticket_vt_versions[$data['ticket_booking_id']]) + 1;
                        }
                        $exist_ticket_booking_ids[] = $data['ticket_booking_id'];
                        $insert_version_visitor_row['order_status'] = !empty($is_static_cancel_call) ? '14' : $data['order_status'];
                        $insert_version_visitor_row['version'] = (int)$vt_version;
                        if ($ticket_update == 0 && isset($action_from) && $action_from != '') {
                            $insert_version_visitor_row['nights'] = '1';
                        }
                        if ($data['row_type'] == '1') {
                            $insert_version_visitor_row['invoice_status'] = '0';
                        } else if (in_array($data['row_type'], ['2', '3', '4', '15', '17']) && (isset($bg_notify['bg_out_put']) && $bg_notify['bg_out_put'] == 2) || ($statement == 0)) {
                            $insert_version_visitor_row['invoice_status'] = '0';
                        } else if (in_array($data['row_type'], ['2', '3', '4', '15', '17'])) {
                            $insert_version_visitor_row['invoice_status'] = '10';
                        } else {
                            $insert_version_visitor_row['invoice_status'] = '0';
                        }
                        if (in_array($channel_type, ['6', '7']) || $is_invoice == '6') {
                            $insert_version_visitor_row['invoice_status'] = ($statement == 1) ? $bg_notify[$insert_version_visitor_row['is_refunded']]['invoice_status']['row_type'][$insert_version_visitor_row['row_type']] : $insert_version_visitor_row['invoice_status'];
                        }

                        $insert_version_visitor_row['action_performed'] = $action_performed = $data['action_performed'] . ', ' . (($OTA_action != 'OAPI32_RFN' && !empty($action_performed_array[$data['channel_type']])) ? $action_performed_array[$data['channel_type']] : $OTA_action);
                        $insert_version_visitor_row['order_cancellation_date'] = $insert_version_visitor_row['updated_at'] = $current_date_time;

                        $insert_version_vt_rows[] = $insert_version_visitor_row;
                    }
                    $this->insert_batch($vt_table, $insert_version_vt_rows, "1");
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                        $this->insert_batch($vt_table, $insert_version_vt_rows, "4");
                    }
                    /* from here it will again select data from table in order to get highest version data that should be inserted in refunded rows */
                    $vt_where2 = "is_refunded = '2' and vt_group_no = " . $visitor_group_no;
                    if (isset($prepaid_ticket_id) && $prepaid_ticket_id != '' && strtotime(gmdate("Y-m-d", strtotime($created_date_time))) > strtotime("2018-07-12 10:26:00") && $is_full_cancelled == 0) {
                        $vt_where2 .= ' AND transaction_id in (' . $prepaid_ticket_id . ')';
                    } else if ($is_full_cancelled == 0) {
                        $refund_transaction_id = implode(',', $refunded_prepaid_ids);
                        $vt_where2 .= ' AND transaction_id in (' . $refund_transaction_id . ')';
                    } else if (!empty($is_full_cancelled) && !empty($prepaid_ticket_id)) {
                        $vt_where2 .= ' AND transaction_id in (' . $prepaid_ticket_id . ')';
                    }
                    $verion_visitor_tickets_data = $this->secondarydb->rodb->select('*')->from($vt_table)->where($vt_where2)->order_by("id", "ASC")->get()->result_array();
                    $visitor_tickets_data2 = $this->api_get_max_version_data($verion_visitor_tickets_data, 'id');
                    $vt_last_query2 = $this->secondarydb->db->last_query();
                    
                    /* Insert same rows instead of updating in financial tables*/
                    $this->CreateLog('api_refunded_records.php', "VT entries2: " . $visitor_group_no, array('query' => $vt_last_query2, 'data' => json_encode($visitor_tickets_data2)));
                    $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_VT_entries-2', 'data' => json_encode(array('query' => $vt_last_query)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                    foreach ($visitor_tickets_data2 as $data) {
                        $extra_pt_id = $data['transaction_id'];
                        if ($transaction_id_flag == 0 && $data['row_type'] == '17') {
                            $transaction_id_flag = 1;
                        }
                    }
                    $insert_visitor_rows = $vt_rows_ids = $vt_rows_main_ids = $vt_rows_type_main_ids = $tps_bundle = array();
                    $k = $j = 1;
                    foreach ($visitor_tickets_data2 as $data) {
                        unset($data['last_modified_at']);
                        $transaction_ids[] = $data['transaction_id'];
                        if(!empty($transaction_id_mapping_array) && !isset($transaction_id_mapping_array[$data['transaction_id']])){
                            $main_pt_id = $main_pt_id + $k;
                        }
                        if (!isset($transaction_id_mapping_array[$data['transaction_id']])) {
                            $transaction_id_mapping_array[$data['transaction_id']] = ($main_pt_id + $k);
                        }
                        $is_extra_option_row = (strpos($data['transaction_type_name'], "Extra") === 0) ? 1 : 0;
                        if (in_array($data['booking_status'], ['1', '2'])) {
                            /* common data from id loops */
                            $insert_visitor_row = $data;
                            $insert_visitor_row['invoice_id'] = '';
                            $insert_visitor_row['is_refunded'] = '1';
                            $insert_visitor_row['deleted'] = '0';
                            $insert_visitor_row['order_status'] = $data['order_status'];
                            if (!empty($is_static_cancel_call) && (in_array($data['transaction_id'], $lambda_prepaid_ids)) && empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                                /** #Comment: - No need to Insert Lambda Order Refunnd Entries */
                                continue;
                            }
                             /** Not insert order status 13 Entries in DB */
                            if (!empty($order_status_not_insert_lambda[$data['ticket_booking_id']])) {
                                continue;
                            }
 
                            if (!empty($lambda_skip_tp_call[$data['ticket_booking_id']])) {
                                $insert_visitor_row['order_status'] = '0';
                            }
                            if ($ticket_update == 0 && isset($action_from) && $action_from != '') {
                                $insert_visitor_row['nights'] = '1';
                            }
                            /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                            $insert_visitor_row['shift_id'] = '0';
                            if (!empty($shift_id)) {
                                $insert_visitor_row['shift_id'] = $shift_id;
                            }
                            if (!empty($pos_point_id)) {
                                $insert_visitor_row['pos_point_id'] = $pos_point_id;
                            }
                            if (!empty($pos_point_name)) {
                                $insert_visitor_row['pos_point_name'] = $pos_point_name;
                            }
                            if (!empty($cashier_register_id)) {
                                $insert_visitor_row['cashier_register_id'] = $cashier_register_id;
                            }
                            /* #Comment: Set cashier details from cashier_register table on the basis of requested cashier */
                            if (!empty($psp_references[$data['ticket_booking_id']])  && $data['row_type'] == 1) {
                                $insert_visitor_row['pspReference'] = $psp_references[$data['ticket_booking_id']];
                            } else if (!empty($psp_reference) && $data['row_type'] == 1) {
                                $insert_visitor_row['pspReference'] = $psp_reference;
                            }
                            if (!empty($verion_prepaid_tickets_data) && isset($verion_prepaid_tickets_data['0']['timezone'])) {
                                $timezone = $verion_prepaid_tickets_data['0']['timezone'];
                                $insert_visitor_row['col8'] = gmdate('Y-m-d', strtotime(gmdate('Y-m-d H:i:s')) + ($timeZone * 3600));
                            } else {
                                $timezone = $data['timezone'];
                                $insert_visitor_row['col8'] = gmdate('Y-m-d', strtotime(gmdate('Y-m-d H:i:s')) + ($timeZone * 3600));
                            }
                            $insert_visitor_row['invoice_status'] = ($statement == 1) ? $bg_notify[$insert_visitor_row['is_refunded']]['invoice_status']['row_type'][$data['row_type']] : '11';
                            $insert_visitor_row['order_updated_cashier_id'] = $order_update_cashier_id;
                            $insert_visitor_row['order_updated_cashier_name'] = $order_update_cashier_name;
                            if ($old_order == 'yes') {
                                $insert_visitor_row['order_cancellation_date'] = $insert_visitor_row['order_confirm_date'] = $insert_visitor_row['updated_at'] = $current_date_time;
                            }
                            /* special conditions */
                            if (in_array($data['booking_status'], ['1', '2']) && ($data['tickettype_name'] != '') && $is_extra_option_row == 0) {
                                if ((!isset($main_ticket_id[$data['ticketId']]) && $data['row_type'] == '2')) {
                                    $main_pt_id = $main_pt_id + $k;
                                }
                                /**#Comment : Handle multiple affiliate row vt id process for is_refunded = 1 row. */
                                if ($data['row_type'] == '11') {
                                    $count_row_affiliate = 26;
                                    $insert_visitor_row['id'] = ($main_pt_id + $k) . str_pad(($count_row_affiliate), 2, "0", STR_PAD_LEFT);
                                } else {
                                    $insert_visitor_row['id'] = ($main_pt_id + $k) . str_pad($data['row_type'], 2, "0", STR_PAD_LEFT);
                                }
                                $insert_visitor_row['transaction_id'] = isset($transaction_id_mapping_array[$data['transaction_id']]) ? $transaction_id_mapping_array[$data['transaction_id']] : ($main_pt_id + $k);
                                if (isset($vt_rows_type_main_ids[$data['transaction_id']][$data['row_type']]) && $data['row_type'] == '11') {
                                    $count_val = count($vt_rows_type_main_ids[$data['transaction_id']][$data['row_type']]);
                                    $count_row_affiliate = 26;
                                    $insert_visitor_row['id'] = ($main_pt_id + $k) . str_pad(($count_row_affiliate + $count_val), 2, "0", STR_PAD_LEFT);
                                }
                                $insert_visitor_rows[] = $insert_visitor_row;
                                $row_type = $transaction_id_flag == 1 ? '17' : '4';
                               
                            } else if ($is_extra_option_row == 1) {
                                $insert_visitor_row['id'] = ($extra_pt_id + $j) . str_pad($data['row_type'], 2, "0", STR_PAD_LEFT);
                                $insert_visitor_row['transaction_id'] = ($extra_pt_id + $j);
                                $insert_visitor_rows[] = $insert_visitor_row;
                                if ($data['row_type'] == '2') {
                                    $extra_pt_id = $extra_pt_id + $j;
                                }
                            } else if (!empty($is_bundle_order)) {
                                if (empty($tps_bundle[$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['ticket_booking_id']])) {
                                    $tps_bundle[$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['ticket_booking_id']] = 1;
                                    $bundle_last_id++;
                                }
                                /** #Comment : Skip Entries For Lambda Booking which is Not Refunded*/
                                if (!in_array($data['ticket_booking_id'], $ticket_booking_ids)) {
                                    continue;
                                }
                                $insert_visitor_row['id'] = $bundle_last_id . str_pad($data['row_type'], 2, "0", STR_PAD_LEFT);
                                $insert_visitor_row['transaction_id'] = $bundle_last_id;
                                $insert_visitor_rows[] = $insert_visitor_row;
                                if ($data['row_type'] == '2') {
                                    $extra_pt_id = $extra_pt_id + $j;
                                }
                            }
                            $vt_rows_ids[] = $insert_visitor_row['transaction_id'];
                            $vt_rows_type_main_ids[$data['transaction_id']][$data['row_type']][] = $data['row_type'];
                            $vt_rows_main_ids[$data['transaction_id']][] = $insert_visitor_row['id'];
                        }
                    }
                    $this->CreateLog('api_refunded_records.php', "VT refunded entries: " . $visitor_group_no, array(json_encode($insert_visitor_rows)));
                    $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'API_order_refund_VT_final_entries_from_obtained_data', 'data' => json_encode(array('vt_rows_ids' => $vt_rows_ids)), 'api_name' => $receive_data['action_from'] . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                    if (!empty($insert_visitor_rows) && $vt == '1') {
                        $this->insert_batch($vt_table, $insert_visitor_rows, "1");
                        /* To update the data in RDS realtime*/
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->insert_batch($vt_table, $insert_visitor_rows, "4");
                        }
                    }
                    if (($pt == '0' || $vt == '0') && $action_from == 'manual') {
                        echo ($pt == '0' ? 'PT data has not been inserted' : ($vt == '0' ? 'VT data has not been updated' : 'Please Check Data'));
                    }
                } else if ($OTA_action != 'IMP_REFUND_V2.2') {
                    $this->highalert_email($visitor_group_no, "VT " . $action_from);
                }
                /* Notify to firebase from common function */
                if (!empty($firebase_notify_data)) {
                    $firebase_notify_data = $this->api_get_max_version_data($firebase_notify_data, 'ticket_id');
                    $this->notify_firebase($firebase_notify_data, $OTA_action);
                }
            } else {
                $this->highalert_email($visitor_group_no, "PT " . $action_from);
            }
            if (!empty($gray_logging)) {
                $gray_logging['queue'] = 'API_refund_orders';
                $this->log_library->write_log($gray_logging, 'api_refund', $visitor_group_no);
            }
        } else {
            $this->highalert_email($visitor_group_no, "no Visitor_group_no");
        }
        /* if partial cancel then send booking_references */
        if ($is_full_cancelled == 0) {
            if (isset($ticket_booking_ids) && !empty($ticket_booking_ids)) {
                $event_data['booking_references'] = $ticket_booking_ids;
            }
        }
        /* call order cancel event webhook for email */
        if (!empty($prepaid_tickets_data) && $api_version_booking_email == 1 && $is_cancel_payment_only == 0 && $is_amend_order_call == 0) {
            $prepaid_data = reset($prepaid_tickets_data);
            if (isset($prepaid_data['reseller_id']) && $prepaid_data['reseller_id'] != 541) {
                $event_data['visitor_group_no'] = $visitor_group_no;
                $event_data['hotel_id'] = !empty($prepaid_data['hotel_id']) ? $prepaid_data['hotel_id'] : '';
                $event_data['event_name'] = 'ORDER_CANCEL';
                if (isset($order_refund_via_lambda_queue) && $order_refund_via_lambda_queue == 1 && empty($lambda_refund_cancel_call)) {
                    $event_data['event_name'] = 'VOUCHER_RELEASE_FAILED';
                }
                $this->call_webhook_email_notification($event_data);
            }
        }
        if ($notify_to_arena && !empty($arena_prepaid_rows)) {
            $this->arena_instant_booking_notification($arena_prepaid_rows);
        }
        if ($notify_api_flag && !empty($notify_api_array) && ($is_cancel_payment_only == 0) && empty($is_amend_order_call)) {
            $partial_cancel_request = $partial_cancel_request ?? '';
            $this->sendOrderProcessNotification($notify_api_array, 0, $order_refund_via_lambda_queue, $partial_cancel_request, $cashier_type);
        }

        if ($notify_api_flag && !empty($notify_api_array) && $is_amend_order_call == 0) {
            $this->CreateLog('supplier_notification_api.php', 'step-1', array('visitr_group_np' => $visitor_group_no));
            $this->sendSupplierNotificationEmail($notify_api_array, $rel_target_ids);
        }

        if (!empty($adam_tower_prepaid_rows)) {
            $this->adam_tower_instant_booking_notification($adam_tower_prepaid_rows);
        }
        /* EOC to get the data from vt in case of cancellation */
    }

    /**
     * @Name        : highalert_email()
     * @Purpose     : To send email if the no entry exists in pt or vt table of secondary db.
     * @Params      : $visitor_group_no         - Order id to refund the order.
     *              : $tbl                      - table in which entry doesnot exists and data related to arena email.
     * @CreatedBy   : Hardeep Kaur <hardeep.intersoft@gmail.com> on 20 August 2017
     */
    function highalert_email($visitor_group_no = '', $tbl = '')
    {
        $log_file = 'high_alert_email.php';
        $mail_data = $tbl;
        $event_details = array();
        /* manage data related to arena proxy, If data belong to arena proxy listener */
        if (is_array($mail_data)) {
            $dist_id = isset($mail_data['other_data']['distributor_id']) && !empty($mail_data['other_data']['distributor_id']) ? $mail_data['other_data']['distributor_id'] : array_keys($this->get_api_const)[0];
            /* preparing mail body */
            if (!empty($mail_data['arena_response'])) {
                $msg = "Error Reference = " . ERROR_REFERENCE_NUMBER . "<br><br> Request time = " . gmdate("Y M d H:i:s") . "<br><br> API = " . $mail_data['other_data']['api_name'] . '<br><br> Distributor id = ' . $dist_id . '<br><br> ' . (!empty($mail_data['other_data']['request']) ? ' Request = ' . json_encode($mail_data['other_data']['request'], JSON_PRETTY_PRINT) : '') . (!empty($mail_data['arena_response']) ? ('<br><br> Response = ' . json_encode($mail_data['arena_response'], JSON_PRETTY_PRINT) . (isset($mail_data['arena_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['arena_response']['processing_time'] . ' seconds.' : '')) : (!empty($mail_data['third_party_constant_response']) ? ('<br><br> Response = ' . json_encode($mail_data['third_party_constant_response'], JSON_PRETTY_PRINT) . (isset($mail_data['third_party_constant_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['third_party_constant_response']['processing_time'] . ' seconds.' : '')) : ''));
            } else if (!empty($mail_data['adam_response'])) {
                $msg = "Error Reference = " . ERROR_REFERENCE_NUMBER . "<br><br> Request time = " . gmdate("Y M d H:i:s") . "<br><br> API = " . $mail_data['other_data']['api_name'] . '<br><br> Distributor id = ' . $dist_id . '<br><br> ' . (!empty($mail_data['other_data']['request']) ? ' Request = ' . json_encode($mail_data['other_data']['request'], JSON_PRETTY_PRINT) : '') . (!empty($mail_data['adam_response']) ? ('<br><br> Response = ' . json_encode($mail_data['adam_response']['adam_tower_response']['request'], JSON_PRETTY_PRINT) . (isset($mail_data['adam_response']['adam_tower_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['adam_response']['adam_tower_response']['processing_time'] . ' seconds.' : '')) : (!empty($mail_data['third_party_constant_response']) ? ('<br><br> Response = ' . json_encode($mail_data['third_party_constant_response'], JSON_PRETTY_PRINT) . (isset($mail_data['third_party_constant_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['third_party_constant_response']['processing_time'] . ' seconds.' : '')) : ''));
            } else {
                $msg = "Error Reference = " . ERROR_REFERENCE_NUMBER . "<br><br> Request time = " . gmdate("Y M d H:i:s") . "<br><br> API = " . $mail_data['other_data']['api_name'] . '<br><br> Distributor id = ' . $dist_id . '<br><br> ' . (!empty($mail_data['other_data']['request']) ? ' Request = ' . json_encode($mail_data['other_data']['request'], JSON_PRETTY_PRINT) : '') . (!empty($mail_data['arena_response']) ? ('<br><br> Response = ' . json_encode($mail_data['arena_response'], JSON_PRETTY_PRINT) . (isset($mail_data['arena_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['arena_response']['processing_time'] . ' seconds.' : '')) : (!empty($mail_data['third_party_constant_response']) ? ('<br><br> Response = ' . json_encode($mail_data['third_party_constant_response'], JSON_PRETTY_PRINT) . (isset($mail_data['third_party_constant_response']['processing_time']) ? '<br><br>Processing Time = ' . $mail_data['third_party_constant_response']['processing_time'] . ' seconds.' : '')) : ''));
            }
            $request_log = array('data' => 'Email_request', 'visitor_group_no' => $visitor_group_no, 'Error Reference' => ERROR_REFERENCE_NUMBER, 'post_data' => json_encode($mail_data));
            $this->CreateLog($log_file, $mail_data['other_data']['api_name'], $request_log);
            $arraylist['emailc'] = $mail_data['other_data']['emailc'];
            $arraylist['subject'] = $mail_data['other_data']['subject'];
        } else {
            /* case other than arena proxy listener */
            $msg = "Visitor_group_no: " . $visitor_group_no . "<br><br>Request time = " . gmdate("Y M d H:i:s") . "<br><br> Order not refunded in DB. Consider it in On priority from table" . $tbl;
            $arraylist['emailc'] = (SERVER_ENVIRONMENT == 'LIVE') ? 'gagandeepgoyal@intersoftprofessional.com' : 'notification.intersoft@gmail.com';
            $arraylist['subject'] = 'SQS Processor HIGH ALERT EMAIL for Refunded orders';
        }
        $arraylist['html'] = $msg;
        $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
        $arraylist['fromname'] = MESSAGE_SERVICE_NAME;
        $arraylist['attachments'] = array();
        $arraylist['BCC'] = array('h.soni@prioticket.com');
        $event_details['send_email_details'][] = (!empty($arraylist)) ? $arraylist : array();
        if (!empty($event_details)) {
            /* Send request to send email */
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sns');
            $aws_message = json_encode($event_details);
            $aws_message = base64_encode(gzcompress($aws_message));
            $queueUrl = QUEUE_URL_EVENT;
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            if (SERVER_ENVIRONMENT == 'Local') {
                local_queue($aws_message, 'EVENT_TOPIC_ARN');
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId !== false) {
                    $sns_object = new Sns();
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, EVENT_TOPIC_ARN);
                }
            }
        }
    }

    /**
     * @Name        : arena_instant_booking_notification
     * @Purpose     : common function for all OTA/OWN_API for hitting queue for notifying arena proxy listener in case of cancel_booking
     *              : function will just notify to arena listener and mainly used to prepare request_body for instant_booking.
     * @param type $pre_selected_ticket_data => array contain all data of prepaid rows of particular order.
     * @return array
     * @CreatedBy   : Aftab Raza <aftab.aipl@gmail.com> on 24 June, 2019
     */
    function arena_instant_booking_notification($pre_selected_ticket_data = array())
    {
        /* initializing params for preparing request and notifying to ARENA_PRXY_LISTENER */
        $arena_booking_date = $arena_booking_from_time = $arena_match_key = $arena_db_booking_date = '';
        $arena_booking_req = $arena_booking_info = array();

        /* preparing request for getting constants from API end corresponding to Arena_proxy_listener */
        $request_data = [
            'vgn'        => $pre_selected_ticket_data[0]['visitor_group_no'],
            'third_party' => "arena_proxy",
            'const_name'  => ['TP_NATIVE_INFO']
        ];
        $arena_constants = $this->get_constant_from_api($request_data);

        /* if we get proper constants in response from APi end */
        if (!empty($arena_constants)) {

            /* extracting constants from cURL response from API server end */
            $arena_const_response = $arena_constants[$request_data['const_name'][0]]['arena_proxy_listener'];

            foreach ($pre_selected_ticket_data as $pre_selected_ticket) {

                /* preparing required stuff for request */
                $arena_db_booking_date = $pre_selected_ticket['booking_selected_date'] != '0' && !empty($pre_selected_ticket['booking_selected_date']) ? $pre_selected_ticket['booking_selected_date'] : $pre_selected_ticket['selected_date'];
                $arena_booking_date = $arena_db_booking_date == '0000-00-00' ? date('Y-m-d') : $arena_db_booking_date;
                $arena_booking_from_time = !empty($pre_selected_ticket['from_time']) ? date('H:i', strtotime($pre_selected_ticket['from_time'])) . ':00' : '00:00:00';

                /* this key mainly work for multi-ticket order case */
                $arena_match_key = $pre_selected_ticket['ticket_id'] . str_replace('-', '', $arena_booking_date) . str_replace(':', '', $arena_booking_from_time);

                if ($pre_selected_ticket['is_refunded'] == '1') {
                    $pre_selected_ticket['quantity'] = (string)'-' . $pre_selected_ticket['quantity'];
                    $request_type = 'cancel_booking';
                }
                /* special case if we are managing array for a single ticket */
                if (!isset($arena_booking_req[$arena_match_key])) {

                    /* managing request body for ARENA_PROXY_LISTENER  if get a fresh(not prepared for arena in earlier loop */
                    $arena_booking_from_time = !empty($pre_selected_ticket['from_time']) ? date('H:i', strtotime($pre_selected_ticket['from_time'])) . ':00' : '00:00:00';
                    $arena_booking_to_time = !empty($pre_selected_ticket['to_time']) ? date('H:i', strtotime($pre_selected_ticket['to_time'])) . ':00' : '23:59:59';
                    $timezone_value = $this->common_model->formatted_timezone($pre_selected_ticket['timezone']);

                    /* preparing actual instant_booking request include VGN number which further operate in arena cURL function */
                    $arena_booking_req[$arena_match_key] = array(
                        'channel_type' => $pre_selected_ticket['channel_type'],
                        'visitor_group_no' => $pre_selected_ticket['visitor_group_no'],
                        'request_type' => !empty($request_type) ? $request_type : 'booking',
                        'data' => array(
                            'booking_type' => array(
                                'ticket_id' => $pre_selected_ticket['ticket_id'],
                                'from_date_time' => $arena_booking_date . 'T' . $arena_booking_from_time . $timezone_value,
                                'to_date_time' => $arena_booking_date . 'T' . $arena_booking_to_time . $timezone_value,
                                'booking_details' => array(array(strtoupper($pre_selected_ticket['ticket_type']) => array(
                                    'ticket_type' => strtoupper($pre_selected_ticket['ticket_type']),
                                    'count' => $pre_selected_ticket['quantity'],
                                )))
                            ),
                            'booking_name' => $pre_selected_ticket['guest_names'],
                            'booking_email' => $pre_selected_ticket['guest_emails'],
                            'distributor_reference' => isset($pre_selected_ticket['without_elo_reference_no']) && !empty($pre_selected_ticket['without_elo_reference_no']) ? $pre_selected_ticket['without_elo_reference_no'] : 'PRIO' . time(),
                        )
                    );
                } else {

                    /* updation in request_body when quantity for ticket_type of a ticket (exist in arena_request) obtained OR new ticket_type found for that ticket */
                    if (isset($arena_booking_req[$arena_match_key]['data']['booking_type']['booking_details'][0][strtoupper($pre_selected_ticket['ticket_type'])])) {
                        $arena_booking_req[$arena_match_key]['data']['booking_type']['booking_details'][0][strtoupper($pre_selected_ticket['ticket_type'])]['count'] += $pre_selected_ticket['quantity'];
                    } else {
                        $arena_booking_req[$arena_match_key]['data']['booking_type']['booking_details'][0][strtoupper($pre_selected_ticket['ticket_type'])] = array(
                            'ticket_type' => strtoupper($pre_selected_ticket['ticket_type']),
                            'count' => $pre_selected_ticket['quantity'],
                        );
                    }
                }
            }

            /* sending request array to arena function contain cURL opearation */
            if (!empty($arena_booking_req)) {

                $arena_booking_req = array_values($arena_booking_req);

                /* Get Arena constants API server end */
                foreach ($arena_booking_req as $arena_value) {

                    $arena_response = $arena_other_data = array();

                    $arena_booking_info = array_values($arena_value['data']['booking_type']['booking_details'][0]);
                    $arena_value['data']['booking_type']['booking_details'] = $arena_booking_info;

                    /* hitting common_function for notifying ARENA_PROXY_LISTENER */
                    $arena_response = $this->notifying_arena_proxy(array('constants' => $arena_const_response, 'request' => $arena_value));

                    /* 202 error from Arena end */
                    if ($arena_response['curl_info']['http_code'] != '202') {
                        /* email notification in case of reuqest is not accepted */
                        $arena_other_data = array('distributor_id' => $arena_const_response['distributor_id'], 'emailc' => NOTIFICATION_EMAILS, 'subject' => strtoupper(SERVER_ENVIRONMENT) . ' server ARENA PROXY LISTENER  for VGN ' . $arena_value['visitor_group_no'] . ' ' . $arena_value['request_type'] . ' API', 'api_name' => $arena_value['request_type']);
                        $mail_data[] = array(
                            'other_data' => $arena_other_data,
                            'arena_response' => $arena_response,
                            'mail_regarding' => 'arena_proxy_listener'
                        );
                    }
                }
            }
        }
        /* If there is any data prepare regarding mail */
        if (isset($mail_data) && !empty($mail_data)) {
            /* sending mail for error either in getting constants from API end OR from Arena end */
            foreach ($mail_data as $mail_info) {
                $this->highalert_email($pre_selected_ticket_data[0]['visitor_group_no'], $mail_info);
            }
        }
    }

    /**
     * @Name        : notifying_arena_proxy
     * @Purpose     : to notify Arena Listner Proxy (Third_party) on cancellation and booking of specific
     *                supplier's tickets (supplier id(s) mentioned in Constant)
     *              : this function will execute when ticket will processed from API end.
     *              : function will just notify to arena listener.
     * @param type $thirdparty_body_data
     * @return array
     * @CreatedBy   : Aftab Raza <aftab.aipl@gmail.com> on 24 June, 2019
     */
    function notifying_arena_proxy($request_params = array())
    {

        /* initializing variables */
        global $arena_logs;
        $end_date_array = $header_data = array();
        $end_date = $api_name = $start_time = $end_time = $processing_time = $processing_time = $request_log = $visitor_group_no = '';

        $api_name = $request_params['request']['request_type'];
        $request_params['request']['data']['distributor_id'] = $request_params['constants']['distributor_id'];
        $log_file = 'arena_listener_proxy.php';

        /* extracting VGN number from request. This case exist if in future handle cancel_booking or other API request for arena */
        if (isset($request_params['request']['visitor_group_no'])) {
            $visitor_group_no = $request_params['request']['data']['booking_reference'] = $request_params['request']['visitor_group_no'];
        } else {
            $visitor_group_no = $request_params['request']['data']['booking_reference'];
        }
        $channel_type = $request_params['request']['channel_type'];
        unset($request_params['request']['visitor_group_no'], $request_params['request']['channel_type']);
        /* creating header values for request */
        $identifier = time();
        $requestAuthentication = base64_encode(hash('sha256', utf8_encode($identifier . ':' . $request_params['constants']['token']), TRUE));
        $header_data = array('Content-Type: application/json', 'x-request-identifier' => $identifier, 'x-request-authentication' => $requestAuthentication);

        /* selection of TEST/LIVE endpoint of arena listner */
        $url = $request_params['constants']['end_point'];

        $start_time = microtime();

        $microtime = explode(' ', $start_time);
        $request_log = array('data' => 'Arena_Request', 'end_point' => $url, 'request_start_time' => date("Y-m-d H:i:s", time()), 'visitor_group_no' => $visitor_group_no, 'headers' => json_encode($header_data), 'post_data' => json_encode($request_params['request']));

        $arena_logs['Arena_request'] = array('log_dir' => 'thirdparty_lib/Arena_proxy_listener', 'log_filename' => $log_file, 'title' => 'Arena Request', 'data' => json_encode($request_log), 'api_name' => 'Arena ' . $api_name, 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'arenaSQS.prioticket.com', 'source_name' => 'Arena');
        $this->CreateLog($log_file, $api_name, $request_log);

        if ($url != '') {

            /* preparing request for getting constants from API end corresponding to Arena_proxy_listener */
            $curl_request_params = array(
                'headers' => array(
                    'Content-Type: application/json',
                    'x-request-identifier:' . $identifier,
                    'x-request-authentication:' . $requestAuthentication
                ),
                'end_point' => $request_params['constants']['end_point'],
                'method' => 'POST',
                'timeout' => $request_params['constants']['timeout_value'],
                'request' => $request_params['request']
            );

            /* hitting common function for cURL request */
            $arena_response = $this->common_model->curl_request_for_arena($curl_request_params);

            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((float) $processing_time) / 1000;

            $end_date_array = explode(" ", $start_time);
            $end_date = date("Y-m-d H:i:s", $end_date_array[1]);

            /* inserting data in notify_to_third_parties */
            $this->db->insert('notify_to_third_parties', array('created_date_time' => $end_date, 'third_party_id' => 24, 'visitor_group_no' => $visitor_group_no, 'status_code' => $arena_response['curl_info']['http_code'], 'channel_type' => $channel_type, 'response_status' => $arena_response['curl_error'], 'notified_at' => date('Y-m-d H:i:s', $microtime[1]), 'action_performed' => $api_name, 'request_data' => json_encode($request_params['request'])));
            $this->load->library('log_library');
            $this->log_library->write_log($arena_logs, 'arena', ERROR_REFERENCE_NUMBER);

            return array('arena_response' => array('processing_time' => $processing_times, 'response' => $arena_response['response'], 'request' => $request_params['request']), 'curl_info' => $arena_response['curl_info']);
        }
    }

    /**
     * @Name        : statement_checking
     * @Purpose     : check the statement invoiced or not
     * @param type array
     * @return array
     * @CreatedBy   : Haripriya <h.soni@prioticket.com> on 26 feb 2020
     */
    public function statement_checking($received_data = [])
    {
        global $gray_logging;
        $is_refunded = [];
        if (!empty($received_data['visitor_group_no'])) {
            $visitor_group_no = $received_data['visitor_group_no'];
            $hotel_id = $received_data['hotel_id'];
            if (!empty(CASHIER_EMAIL) && !empty(CASHIER_PWD) && !empty(GOOGLE_API_VERIFY_PASSWORD) && !empty(FIREBASE_PROJECT_KEY)) {
                $this->load->library('api/enum/InvoceStatus_Enum', 'invoicestatus_enum');
                $invoiceStatus = $this->invoicestatus_enum;//=> Direct variable call will not work
                $google_data = json_encode(array("email" => CASHIER_EMAIL, "password" => CASHIER_PWD, "returnSecureToken" => 'true'));
                $google_headers = array(
                    'Content-Type:application/json'
                );
                $google_url = GOOGLE_API_VERIFY_PASSWORD . '?key=' . FIREBASE_PROJECT_KEY;
                try {
                    $handle = curl_init();
                    curl_setopt($handle, CURLOPT_URL, $google_url);
                    curl_setopt($handle, CURLOPT_HTTPHEADER, $google_headers);
                    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($handle, CURLOPT_POSTFIELDS, $google_data);
                    $getdata = curl_exec($handle);
                    curl_close($handle);
                    /* get token required for bigquery authentication */
                    $id_token = json_decode($getdata, true)['idToken'];
                    $bg_headers = array(
                        'Content-Type:application/json',
                        'Authorization:' . $id_token
                    );
                    $bg_url = BIGQUERY_ORDER_STATUS . $visitor_group_no;
                    $bg_data = array(
                        'headers' => json_encode($bg_headers),
                        'url' => $bg_url
                    );
                    $output = $this->curl_hitting($bg_data);
                    if (!empty($output) && strpos($output, "\ufeff") !== false) {
                        $output = str_replace('\ufeff', '', $output);
                    }
                   $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'Big query queue data', 'data' => json_encode(array('response' => $output, 'data' => $bg_data, 'google_header' => $google_headers, 'google_url' => $google_url)), 'api_name' => 'google api' . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                  if (in_array($output, [0, 1])) {
                        if (!empty($received_data['is_amend_order_call'])) {
                            $is_refunded['0']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['0']['invoice_status']['row_type']['2'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['0']['invoice_status']['row_type']['3'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['0']['invoice_status']['row_type']['4'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['0']['invoice_status']['row_type']['15'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['0']['invoice_status']['row_type']['17'] = $invoiceStatus->InvoiceStatusZero;
                        } else {
                            $is_refunded['2']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['1']['invoice_status']['row_type']['2'] = $is_refunded['2']['invoice_status']['row_type']['2'] = $invoiceStatus->InvoiceStatusTen;
                            $is_refunded['1']['invoice_status']['row_type']['3'] = $is_refunded['2']['invoice_status']['row_type']['3'] = $invoiceStatus->InvoiceStatusTen;
                            $is_refunded['1']['invoice_status']['row_type']['4'] = $is_refunded['2']['invoice_status']['row_type']['4'] = $invoiceStatus->InvoiceStatusTen;
                            $is_refunded['1']['invoice_status']['row_type']['15'] = $is_refunded['2']['invoice_status']['row_type']['15'] = $invoiceStatus->InvoiceStatusTen;
                            $is_refunded['1']['invoice_status']['row_type']['17'] = $is_refunded['2']['invoice_status']['row_type']['17'] = $invoiceStatus->InvoiceStatusTen;
                            $is_refunded['1']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusEleven;
                        }
                        if (in_array($output, [1]) && !empty($hotel_id)) {
                            $bg_url = BIGQUERY_UPDATE_ORDER . $hotel_id . '/' . $visitor_group_no;
                            $bg_data = array(
                                'headers' => json_encode($bg_headers),
                                'url' => $bg_url
                            );
                            $update_data = $this->curl_hitting($bg_data);
                        }
                    } else if (in_array($output, [2])) {
                        if (!empty($received_data['is_amend_order_call'])) {
                            $is_refunded['0']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusThree;
                            $is_refunded['0']['invoice_status']['row_type']['2'] =  $invoiceStatus->InvoiceStatusThree;
                            $is_refunded['0']['invoice_status']['row_type']['3'] =  $invoiceStatus->InvoiceStatusThree;
                            $is_refunded['0']['invoice_status']['row_type']['4'] =  $invoiceStatus->InvoiceStatusThree;
                            $is_refunded['0']['invoice_status']['row_type']['15'] =  $invoiceStatus->InvoiceStatusThree;
                            $is_refunded['0']['invoice_status']['row_type']['17'] =  $invoiceStatus->InvoiceStatusThree;
                        } else {
                            $is_refunded['2']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusZero;
                            $is_refunded['1']['invoice_status']['row_type']['1'] = $invoiceStatus->InvoiceStatusEleven;
                            if(!empty($received_data['other_data']) && ((!empty($received_data['other_data']['channel_type']) && in_array($received_data['other_data']['channel_type'], ['6','7'])) || (!empty($received_data['other_data']['is_invoice']) && $received_data['other_data']['is_invoice'] == '6'))){
                                $is_refunded['2']['invoice_status']['row_type']['2'] = $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['2']['invoice_status']['row_type']['3'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['2']['invoice_status']['row_type']['4'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['2']['invoice_status']['row_type']['15'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['2']['invoice_status']['row_type']['17'] =  $invoiceStatus->InvoiceStatusTwelve;
                               
                                $is_refunded['1']['invoice_status']['row_type']['2'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['1']['invoice_status']['row_type']['3'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['1']['invoice_status']['row_type']['4'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['1']['invoice_status']['row_type']['15'] =  $invoiceStatus->InvoiceStatusTwelve;
                                $is_refunded['1']['invoice_status']['row_type']['17'] =  $invoiceStatus->InvoiceStatusTwelve;
                                
                            }else{
                                $is_refunded['2']['invoice_status']['row_type']['2'] = $invoiceStatus->InvoiceStatusZero;
                                $is_refunded['2']['invoice_status']['row_type']['3'] = $invoiceStatus->InvoiceStatusZero;
                                $is_refunded['2']['invoice_status']['row_type']['4'] = $invoiceStatus->InvoiceStatusZero;
                                $is_refunded['2']['invoice_status']['row_type']['15'] = $invoiceStatus->InvoiceStatusZero;
                                $is_refunded['2']['invoice_status']['row_type']['17'] = $invoiceStatus->InvoiceStatusZero;
                               
                                $is_refunded['1']['invoice_status']['row_type']['2'] = $invoiceStatus->InvoiceStatusTen;
                                $is_refunded['1']['invoice_status']['row_type']['3'] = $invoiceStatus->InvoiceStatusTen;
                                $is_refunded['1']['invoice_status']['row_type']['4'] = $invoiceStatus->InvoiceStatusTen;
                                $is_refunded['1']['invoice_status']['row_type']['15'] = $invoiceStatus->InvoiceStatusTen;
                                $is_refunded['1']['invoice_status']['row_type']['17'] = $invoiceStatus->InvoiceStatusTen;
                            }
                            $is_refunded['bg_out_put'] = '2';
                        }
                    } else { /* send email if receive data in response other than 0,1,2 */
                        $mail_other_data = array('distributor_id' => $hotel_id, 'emailc' => NOTIFICATION_EMAILS, 'subject' => strtoupper(SERVER_ENVIRONMENT) . ' bigquery unexpected response ' . $hotel_id . ' refund API', 'api_name' => 'sqs refund');
                        $mail_data = array(
                            'other_data' => $mail_other_data,
                            'get_statement' => $output,
                            'update_statement' => !empty($update_data) ? $update_data : ''
                        );
                        $this->highalert_email($visitor_group_no, $mail_data);
                    }
                } catch (Exception $e) {
                    $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'Big query queue', 'data' => json_encode(array('exception' => $e, 'google_data' => $google_data, 'google_header' => $google_headers, 'google_url' => $google_url)), 'api_name' => 'google api' . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
                }
            }
        }
        return $is_refunded;
    }

    /**
     * @Name        : curl_hitting
     * @Purpose     : hit the curl for big query api's
     * @param type array
     * @return array
     * @CreatedBy   : Haripriya <h.soni@prioticket.com> on 26 feb 2020
     */
    private function curl_hitting($curl_data = [])
    {
        $output = [];
        global $gray_logging;
        if (!empty($curl_data)) {
            try {
                if (!empty($curl_data['url']) && !empty($curl_data['headers'])) {
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, $curl_data['url']);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, json_decode($curl_data['headers'], true));
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
                    $output = curl_exec($curl);
                    curl_close($curl);
                }
            } catch (Exception $e) {
                $gray_logging[] = array('log_filename' => 'api_refunded_records.php', 'title' => 'Big query queue', 'data' => json_encode(array('exception' => $e, 'curl_data' => $curl_data)), 'api_name' => 'BQ api' . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
            }
        }
        return $output;
    }

    /**
     * @Name        : get_constant_from_api
     * @Purpose     : function for getting the constant value from the api end.
     * @param type $data => array contain vgn,third_party_name,and list of constants. 
     * @return array
     * @CreatedBy   : Rashmi <rashmi.aipl01@gmail.com> on 2 April, 2020
     */
    function get_constant_from_api($data = [])
    {
        $get_const = !empty($data['const_name']) ? $data['const_name'] : ['TP_NATIVE_INFO'];
        /* preparing request for getting constants from API end */
        $api_name = "get_constant";
        $curl_request_params = [
            'headers' => [
                'Content-Type: application/json',
                'token:' . array_values($this->get_api_const)[0]
            ],
            'end_point' => $this->api_url . '/v2.2/booking_service',
            'method' => 'POST',
            'request' => [
                'request_type' => 'get_constants',
                'data' => [
                    'distributor_id' => array_keys($this->get_api_const)[0],
                    'constant_name' => $get_const
                ]
            ]
        ];
        $get_constant = $this->common_model->curl_request_for_arena($curl_request_params); /* Use commmon function of common_model for getting the response */
        if (isset($get_constant['curl_info']['http_code']) && $get_constant['curl_info']['http_code'] == '200') {
            $third_party_const_response = $get_constant['response']['data']['constants'];
            $cont_response = [];
            foreach ($third_party_const_response as $get_constant_val) {
                if (in_array($get_constant_val['name'], $get_const)) {
                    $cont_response[$get_constant_val['name']] = $get_constant_val['value'];
                }
            }
        } else {
            /* send mail if not getting proper constants details from API end */
            /* email notification in case of reuqest is not accepted */
            $other_data = ['emailc' => NOTIFICATION_EMAILS, 'subject' => strtoupper(SERVER_ENVIRONMENT) . ' server error in getting CONSTANTS from API server related to Any Third Party  for VGN ' . $data['vgn'] . ' booking ' . ' API', 'api_name' => 'booking'];
            $mail_data[0] = [
                'other_data' => $other_data,
                'mail_regarding' => $data['third_party'],
                'third_party_constant_response' => $get_constant
            ];
            $log_file = 'Constant_error.php';
            $request_log = ['data' => 'constant_Request', 'request_start_time' => date("Y-m-d H:i:s", time()), 'constant_value' => json_encode($get_constant)];
            $this->CreateLog($log_file, $api_name, $request_log);
        }
        /* If there is any data prepare regarding mail */
        if (isset($mail_data) && !empty($mail_data)) {
            /* sending mail for error either in getting constants from API end */
            foreach ($mail_data as $mail_info) {
                $this->highalert_email($data['vgn'], $mail_info);
            }
        }
        return $cont_response;
    }

    /**
     * @Name        : get_mec_data
     * @Purpose     : to get the mec data
     * @param type  : int
     * @return      : array
     * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
     */
    function get_mec_data($product_id = 0)
    {
        $response = [];
        if (!empty($product_id)) {
            $this->primarydb->db->select('mec_id, startDate, endDate, is_cut_off_time, cut_off_time, local_timezone_name, is_reservation, shared_capacity_id, third_party_id, second_party_id');
            $this->primarydb->db->from('modeventcontent');
            $this->primarydb->db->where(array('mec_id' => $product_id, 'deleted' => '0'));
            $response = $this->primarydb->db->get()->result_array()[0];
        }
        return $response;
    }

    /**
     * @Name        : adam_tower_instant_booking_notification
     * @Purpose     : common function for all OTA/OWN_API for hitting queue for notifying adam tower in case of cancel_booking
     *              : function will just notify to adam tower and mainly used to prepare request_body for instant_booking. 
     * @param type $pre_selected_ticket_data => array contain all data of prepaid rows of particular order.            
     * @return array
     * @CreatedBy   : Rashmi <rashmi.aipl01@gmail.com> on 26 March, 2020
     */
    function adam_tower_instant_booking_notification($pre_selected_ticket = [])
    {
        /* call a function for getting the constant from api end */
        $request_data = [
            'vgn' => $pre_selected_ticket[0]['visitor_group_no'],
            'third_party' => "adam_tower",
            'const_name' => ['TP_NATIVE_INFO']
        ];
        //$adam_tower_response = $this->get_constant_from_api($request_data);
        $adam_tower_response = [];
        $adam_match_key = "";
        $adam_tower_booking_req = $adam_booking_info = [];
        if (!empty($adam_tower_response)) {
            /* if we get proper constants in response from API end */
            $i = 0;

            foreach ($pre_selected_ticket as $pre_selected_ticket_data) {
                $adam_tower_const_response = $adam_tower_response[$request_data['const_name'][0]]['adam_tower'][$pre_selected_ticket_data['museum_id']];
                if ($pre_selected_ticket_data['is_refunded'] == '1') {
                    $request_type = 'cancel_booking';
                }
                $adam_tower_booking_date = $pre_selected_ticket_data['selected_date'] == '0000-00-00' ? date('Y-m-d') : $pre_selected_ticket_data['selected_date'];
                $adam_tower_booking_from_time = !empty($pre_selected_ticket_data['from_time']) ? date('H:i', strtotime($pre_selected_ticket_data['from_time'])) . ':00' : '00:00:00';
                $adam_tower_booking_to_time = !empty($pre_selected_ticket_data['to_time']) ? date('H:i', strtotime($pre_selected_ticket_data['to_time'])) . ':00' : '23:59:59';
                /* this key mainly work for multi-ticket order case */
                $adam_match_key = $pre_selected_ticket_data['ticket_id'] . str_replace('-', '', $adam_tower_booking_date) . str_replace(':', '', $adam_tower_booking_from_time);
                if (!isset($adam_tower_booking_req[$adam_match_key])) {
                    $timezone_value = $this->common_model->formatted_timezone($pre_selected_ticket_data['timezone']);
                    $order_custom_field = !empty($pre_selected_ticket_data['extra_booking_information']) ? json_decode($pre_selected_ticket_data['extra_booking_information'], true) : "";
                    $adam_tower_booking_req[$adam_match_key] = array(
                        'request_type' => !empty($request_type) ? $request_type : 'booking',
                        'adam_tower_response' => $adam_tower_const_response,
                        'data' => [
                            'distributor_id' => $pre_selected_ticket_data['hotel_id'],
                            'distributor_reference' => !empty($pre_selected_ticket_data['without_elo_reference_no']) ? $pre_selected_ticket_data['without_elo_reference_no'] : 'PRIO' . time(),
                            'distributor_name' => $pre_selected_ticket_data['hotel_name'],
                            'booking_reference' => $pre_selected_ticket_data['visitor_group_no'],
                            'booking_type' => [
                                'ticket_id' => $pre_selected_ticket_data['ticket_id'],
                                'ticket_title' => $pre_selected_ticket_data['title'],
                                'supplier_name' => $pre_selected_ticket_data['museum_name'],
                                'from_date_time' => $adam_tower_booking_date . 'T' . $adam_tower_booking_from_time . $timezone_value,
                                'to_date_time' => $adam_tower_booking_date . 'T' . $adam_tower_booking_to_time . $timezone_value,
                                'booking_details' => [$i => [
                                    'ticket_type' => strtoupper($pre_selected_ticket_data['ticket_type']),
                                    'count' => ($pre_selected_ticket_data['is_refunded'] == "1") ? (string) '-' . $pre_selected_ticket_data['quantity'] : $pre_selected_ticket_data['quantity'],
                                    'ticket_code' => $pre_selected_ticket_data['passNo']
                                ]]
                            ],
                            'booking_name' => $pre_selected_ticket_data['guest_names'],
                            'booking_email' => $pre_selected_ticket_data['guest_emails'],
                            'booking_phone' => $pre_selected_ticket_data['phone_number'],
                            'order_custom_fields' => !empty($order_custom_field['order_custom_fields']) ? $order_custom_field['order_custom_fields'] : "",
                        ]
                    );
                } else {
                    $adam_tower_booking_req[$adam_match_key]['data']['booking_type']['booking_details'][$i] = array(
                        'ticket_type' => strtoupper($pre_selected_ticket_data['ticket_type']),
                        'count' => ($pre_selected_ticket_data['is_refunded'] == "1") ? (string) '-' . $pre_selected_ticket_data['quantity'] : $pre_selected_ticket_data['quantity'],
                        'ticket_code' => $pre_selected_ticket_data['passNo']
                    );
                }
                $i++;
            }
            if (!empty($adam_tower_booking_req)) {
                $adam_tower_booking_req_data = array_values($adam_tower_booking_req);
                foreach ($adam_tower_booking_req_data as $adam_value) {
                    $adam_tower_response = $adam_other_data = $adam_tower_const = [];
                    $adam_booking_info = array_values($adam_value['data']['booking_type']['booking_details']);
                    $adam_value['data']['booking_type']['booking_details'] = $adam_booking_info;
                    /* hitting common_function for notifying to third party */
                    $adam_tower_const = $adam_value['adam_tower_response'];
                    unset($adam_value['adam_tower_response']);
                    $adam_tower_response = $this->notify_to_tp(['constants' => $adam_tower_const, 'request' => $adam_value, 'api_name' => "adam_tower", 'channel_type' => $pre_selected_ticket[0]['channel_type'], 'supplier_id' => $adam_tower_const['enabled_museums']]);
                    /* 200 error from Adam Tower end */
                    if ($adam_tower_response['curl_info']['http_code'] != '200') {
                        /* email notification in case of reuqest is not accepted */
                        $adam_other_data = array('distributor_id' => $pre_selected_ticket_data[0]['hotel_id'], 'emailc' => NOTIFICATION_EMAILS, 'subject' => strtoupper(SERVER_ENVIRONMENT) . ' server Adam Tower for VGN ' . $pre_selected_ticket_data[0]['visitor_group_no'] . ' ' . $adam_tower_booking_req['request_type'] . ' API', 'api_name' => $adam_tower_booking_req['request_type']);
                        $mail_data[] = array(
                            'other_data' => $adam_other_data,
                            'adam_response' => $adam_tower_response,
                            'mail_regarding' => 'adam_tower'
                        );
                    }
                }
            }

            /* If there is any data prepare regarding mail */
            if (isset($mail_data) && !empty($mail_data)) {
                /* sending mail for error either in getting constants from API end OR from Arena end */
                foreach ($mail_data as $mail_info) {
                    $this->highalert_email($pre_selected_ticket_data[0]['visitor_group_no'], $mail_info);
                }
            }
        }
    }

    /**
     * @Name        : notify_to_tp
     * @Purpose     : to notify to any Third_party on cancellation and booking of specific
     *                supplier's tickets (supplier id(s) mentioned in Constant)
     *              : this function will execute when ticket will processed from API end.
     *              : function will just notify to third_party. 
     * @param type $request_params
     * @return array
     * @CreatedBy   : Rashmi<rashmi.aipl01@gmail.com> on 27 March, 2020
     */
    function notify_to_tp($request_params = [])
    {
        /* initializing variables */
        global $adam_tower_logs;
        $end_date_array = [];
        $end_date = $api_name = $start_time = $end_time = $processing_time = $request_log = $visitor_group_no = '';
        $api_name = $request_params['request']['request_type'];
        $visitor_group_no = $request_params['request']['data']['booking_reference'];

        $log_file = 'adam_tower.php';

        $channel_type = $request_params['channel_type'];
        /* selection of TEST/LIVE endpoint of adam tower */
        $url = $request_params['constants']['end_point'];

        $start_time = microtime();

        $microtime = explode(' ', $start_time);

        $request_log = ['data' => 'Adam_tower_Request', 'end_point' => $url, 'request_start_time' => date("Y-m-d H:i:s", time()), 'visitor_group_no' => $visitor_group_no, 'post_data' => json_encode($request_params['request'])];

        $adam_tower_logs['Adam_tower_request'] = array('log_dir' => 'thirdparty_lib/Adam_tower', 'log_filename' => $log_file, 'title' => 'Adam_tower_Request', 'data' => json_encode($request_log), 'api_name' => 'Adam Tower ' . $api_name, 'request_time' => date("Y-m-d H:i:s", time()), 'source_name' => 'Adam Tower');
        $this->CreateLog($log_file, $api_name, $request_log);

        if (!empty($url && $request_params['constants']['username'] && $request_params['constants']['password'])) {
            if (empty($request_params['request']['data']['order_custom_fields'])) {
                unset($request_params['request']['data']['order_custom_fields']);
            }
            /* preparing request for getting constants from API end corresponding to Adam tower */
            $curl_request_params = array(
                'username' => $request_params['constants']['username'],
                'password' => $request_params['constants']['password'],
                'end_point' => $url,
                'method' => 'POST',
                'timeout' => $request_params['constants']['timeout_value'],
                'request' => $request_params['request'],
            );
            /* hitting common function for cURL request */
            $adam_tower_response = $this->common_model->curl_request_for_arena($curl_request_params);
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((float) $processing_time) / 1000;

            $end_date_array = explode(" ", $start_time);
            $end_date = date("Y-m-d H:i:s", $end_date_array[1]);

            /* inserting data in notify_to_third_parties */
            $this->db->insert('notify_to_third_parties', array('created_date_time' => $end_date, 'third_party_id' => 55, 'visitor_group_no' => $visitor_group_no, 'status_code' => $adam_tower_response['curl_info']['http_code'], 'channel_type' => $channel_type, 'response_status' => $adam_tower_response['curl_error'], 'notified_at' => date('Y-m-d H:i:s', $microtime[1]), 'action_performed' => $api_name, 'request_data' => json_encode($request_params['request'])));
            $this->load->library('log_library');
            $this->log_library->write_log($adam_tower_logs, 'adam_tower', ERROR_REFERENCE_NUMBER);
            return array('adam_tower_response' => array('processing_time' => $processing_times, 'response' => $adam_tower_response, 'request' => $request_params['request']), 'curl_info' => $adam_tower_response['curl_info']);
        }
    }

    /**
     * @name    : api_get_max_version_data()     
     * @Purpose : To get max version rows from data
     * @where   : FROM insert_refunded_orders()
     * @return  : $result : having correct value after comapring all versions
     */
    public function api_get_max_version_data($db_data = array(), $db_key = '')
    {
        $versions = array();
        foreach ($db_data as $db_row) {
            if (!isset($versions[$db_row[$db_key]])) {
                $versions[$db_row[$db_key]] = $db_row['version'];
                $result[$db_row[$db_key]] = $db_row;
            } else if (isset($versions[$db_row[$db_key]]) && $db_row['version'] > $versions[$db_row[$db_key]]) {
                $versions[$db_row[$db_key]] = $db_row['version'];
                $result[$db_row[$db_key]] = $db_row;
            }
        }
        return $result;
    }

    /**
     * @name    : insert_payment_refund_detail()     
     * @Purpose : To insert refunded entries in order_payment_details table
     */
    public function insert_payment_refund_detail($order_payment_details = array())
    {
        if (!empty($order_payment_details)) {
            $this->secondarydb->db->insert('order_payment_details', $order_payment_details);
        }
    }

    /**
     * @Name : notify_firebase()
     * @Purpose : To notify firebase at the time of confirmation cancellation
     * @Call from : insert_refunded_orders
     * @Receiver params : $cancelled_tickets_data
     * @Return params : no data
     * @Created : Harirpiya <haripriya.intersoft@gmail.com> on Feb 2019
     */
    function notify_firebase($firebase_notify_data = array(), $OTA_action = '')
    {
        $shared_capacity_id = 0;
        foreach ($firebase_notify_data as $fkey => $cancelled_tickets_data) {
            $shared_capacity_ids = explode(',', $cancelled_tickets_data['shared_capacity_id']);
            foreach ($shared_capacity_ids as $shared_capacity_id) {
                $shared_capacity_id = trim($shared_capacity_id);
                if (!empty($shared_capacity_id)) {
                    $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, sold, blocked, actual_capacity, adjustment, adjustment_type, timeslot, is_active', 'where' => "shared_capacity_id = '" . $shared_capacity_id . "' and date = '" . date('Y-m-d', strtotime($cancelled_tickets_data['selected_date'])) . "' and from_time = '" . $cancelled_tickets_data['from_time'] . "' and to_time = '" . $cancelled_tickets_data['to_time'] . "'"));
                    $timeslot_type = isset($cancelled_tickets_data['timeslot']) ? $cancelled_tickets_data['timeslot'] : (isset($cancelled_tickets_data['timeslote_type']) ? $cancelled_tickets_data['timeslote_type'] : $sold[0]['timeslot']);
                    $total_capacity  = (int)$sold[0]['actual_capacity'];

                    if ($sold[0]['adjustment_type'] == '1') {
                        $total_capacity = (int) ($total_capacity + $sold[0]['adjustment']);
                    } else {
                        $total_capacity = (int) ($total_capacity - $sold[0]['adjustment']);
                    }
                    if (empty($sold[0]['sold']) || $sold[0]['sold'] == NULL) {
                        $sold[0]['sold'] = 0;
                    }
                    if (empty($sold[0]['blocked']) || $sold[0]['blocked'] == NULL) {
                        $sold[0]['blocked'] = 0;
                    }
                    $selected_date = date('Y-m-d', strtotime($cancelled_tickets_data['selected_date']));
                    $update_values = array(
                        'blocked'   => max((int)$sold[0]['blocked'], 0),
                        'bookings'  => max((int)($sold[0]['sold']), 0),
                        'from_time' => $cancelled_tickets_data['from_time'],
                        'is_active' => ($sold[0]['is_active'] == '1') ? true : false,
                        'to_time'   => $cancelled_tickets_data['to_time'],
                        'total_capacity' => ($total_capacity != '') ? (int) $total_capacity : (int) 100,
                        'type' => $timeslot_type,
                        'slot_id' => (string)str_replace("-", "", $selected_date) . str_replace(":", "", $cancelled_tickets_data['from_time']) . str_replace(":", "", $cancelled_tickets_data['to_time']) . $shared_capacity_id
                    );
                    $notify_shared_capacity_id = $shared_capacity_id;
                    $notify_to_time = $cancelled_tickets_data['to_time'];
                    /* prepare header request to gave in graylog to track data */
                    $headers = $this->set_headers($cancelled_tickets_data, $OTA_action, $cancelled_tickets_data['visitor_group_no']);
                    $this->notify_firebase_curl_function($selected_date, $update_values, $notify_shared_capacity_id, $notify_to_time, $headers, $cancelled_tickets_data['visitor_group_no']);
                }
            }
        }
    }

    /**
     * @Name : notify_firebase_curl_function()
     * @Purpose : To notify firebase curl function common
     * @Call from : notify_firebase() , notify_firebase_at_reserve_confirm()
     * @Receiver params : $selected_date, $update_values, $notify_shared_capacity_id, $notify_to_time
     * @Return params : response from firebase
     * @Created : Harirpiya <haripriya.intersoft@gmail.com> on MArch 2019
     */
    function notify_firebase_curl_function($selected_date = '', $update_values = array(), $notify_shared_capacity_id = 0, $notify_to_time = '', $header_data = array(), $vgn = 0)
    {
        global $gray_logging;
        /* mearge and create header */

        $headers2 = $this->all_headers_new('notify_firebase_curl_function', '', '', $vgn);

        $headers = !empty($header_data) ? $header_data : $headers2;
        $params = json_encode(array("node" => 'ticket/availabilities/' . $notify_shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $notify_to_time, 'details' => $update_values));
        if (SYNC_WITH_FIREBASE == 1) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details_in_array");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
                $getdata = curl_exec($ch);
                $response = json_decode($getdata, true);

                $curl_errno = curl_errno($ch);
                $curl_error = curl_error($ch);
                if ($curl_errno > 0 && $curl_errno == 28) {
                    $response['error'] = $curl_error;
                }
                curl_close($ch);
                $this->CreateLog('firebase_user_type', __CLASS__ . '-' . __FUNCTION__, array('user_type' => $this->session->userdata['user_type'], 'cashier_email' => $this->session->userdata['uname'], 'cashier_type' => $this->get_cashier_type_from_tokenId()));
            } catch (Exception $ex) {
                $this->CreateLog('api_notify_firebase.php', "notify_firebase: " . $vgn, array(json_encode(array('Request' => json_decode($params), 'headers' => $headers, 'Response' => $ex, "url" => FIREBASE_SERVER . "/update_details_in_array"))));
                $gray_logging[] = array('log_filename' => 'api_notify_firebase.php', 'title' => 'notify_firebase', 'data' => json_encode(array('Request' => json_decode($params), 'Response' => $ex, 'headers' => $headers, "url" => FIREBASE_SERVER . "/update_details_in_array")), 'api_name' => 'notify firebase' . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
            }
        }
        $this->CreateLog('api_notify_firebase.php', "notify_firebase: " . $vgn, array(json_encode(array('Request' => json_decode($params), 'headers' => $headers, 'Response' => $response, "url" => FIREBASE_SERVER . "/update_details_in_array"))));
        $gray_logging[] = array('log_filename' => 'api_notify_firebase.php', 'title' => 'notify_firebase', 'data' => json_encode(array('Request' => json_decode($params), 'Response' => $response, 'headers' => $headers, "url" => FIREBASE_SERVER . "/update_details_in_array")), 'api_name' => 'notify firebase' . ' refund', 'request_time' => date("Y-m-d H:i:s", time()), 'host_name' => 'SQS.prioticket.com', 'source_name' => 'API refund');
        return $getdata;
    }

    function set_headers($header_request = array(), $OTA_action = '', $vgn = 0)
    {
        $headers = array();
        if (!empty($header_request)) {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . REDIS_AUTH_KEY,
                'museum_id : ' . (!empty($header_request['museum_id']) ? $header_request['museum_id'] : 0),
                'pos_type : ' . (!empty($header_request['pos_type']) ? $header_request['pos_type'] : 6),
                'channel_type : ' . (!empty($header_request['channel_type']) ? $header_request['channel_type'] : 0),
                'hotel_id : ' . (!empty($header_request['hotel_id']) ? $header_request['hotel_id'] : 0),
                'ticket_id : ' . (!empty($header_request['ticket_id']) ? $header_request['ticket_id'] : 0),
                'action : ' . (!empty($OTA_action) ? $OTA_action : ""),
                'vgn : ' . (!empty($vgn) ? $vgn : 0)
            );
        } else {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . REDIS_AUTH_KEY
            );
        }
        return $headers;
    }

    /**
     * @Name        : sendOrderProcessNotification
     * @Purpose     : send order process notification
     * @param type $pre_selected_ticket_data => array contain all data of prepaid rows of particular order.
     * @return array
     */
    function sendOrderProcessNotification($pre_selected_ticket_data = array(), $is_amend_order = 0, $order_refund_via_lambda_queue = 0, $partial_cancel_request = '', $cashier_type = 1)
    {
        foreach ($pre_selected_ticket_data as $pre_selected_ticket) {
            if ($pre_selected_ticket['is_refunded'] == '1') {
                $request_type = 'cancel_booking';
            }
        }
        /* Send notification to arena users */
        if (!empty($pre_selected_ticket_data[0]['visitor_group_no']) && SERVER_ENVIRONMENT != 'Local') {
            $this->load->model('notification_model');
            $order_event = (!empty($request_type) && $request_type == 'cancel_booking') ? 'ORDER_CANCEL' : 'ORDER_CREATE';
            if ($is_amend_order == 1) {
                $order_event = 'ORDER_UPDATE';
            }
            if (!empty($partial_cancel_request) && $partial_cancel_request == 'yes' && empty($is_amend_order)) {
                $order_event = 'ORDER_CREATE';
                $notify_request['eventPartial'] = 'ORDER_PARTIAL_CANCEL';
            }
            $notify_request['reference_id'] = $pre_selected_ticket_data[0]['visitor_group_no'];
            $notify_request['event'] = [$order_event];
            $notify_request['cashier_type'] = $cashier_type;
            $this->notification_model->checkNotificationEventExist(array($notify_request));
        }
    }

    /**
     * api_booking_email_queue
     *
     * @param  mixed $booking_data
     * @param  mixed $event_data
     * @return void
     */
    public function api_booking_email_queue($booking_data, $event_data)
    {
        $prepaid_data  = $event_data['prepaid_tickets_data'][0];
        if (!empty($booking_data) && !empty($prepaid_data)) {
            if (!empty($event_data['amended_booking_references'])) {
                $event_data['booking_references'] = $event_data['amended_booking_references'];
            }
            $event_data['visitor_group_no'] = !empty($prepaid_data['visitor_group_no']) ? $prepaid_data['visitor_group_no'] : '';
            if (!empty($booking_data['order_options']['email_options'])) {
                $emailOptions = $booking_data['order_options']['email_options'];
                //$send_receipt = (!empty($emailOptions['email_types']['send_receipt']) && $emailOptions['email_types']['send_receipt'] == true) ? true : false;
                $send_tickets = (!empty($emailOptions['email_types']['send_tickets']) && $emailOptions['email_types']['send_tickets'] == true) ? true : false;
                //$pos_type_detail = $this->get_pos_type($prepaid_data['hotel_id']);
                $call_event = 1;
                /*if (!empty($pos_type_detail) && $pos_type_detail->pos_type == 6) {
                    $call_event = 0;
                }*/
                if ($send_tickets == true && $call_event == 1) {
                    if (1==0 && isset($prepaid_data['reseller_id']) && isset($prepaid_data['hotel_id']) && $prepaid_data['reseller_id'] == "541" && in_array($prepaid_data['hotel_id'], json_decode(EVAN_EVANS_DISTRIBUTOR_ID, true))) {
                        if (!empty($emailOptions['email_recipients']) || !empty($event_data['email_recipients_address'])) {
                            $recipientsVal = [];
                            if (!empty($emailOptions['email_recipients'])) {
                                foreach ($emailOptions['email_recipients'] as $recipients) {
                                    if (!empty($recipients['recipients_address'])) {
                                        $recipientsVal[] = $recipients['recipients_address'];
                                    }
                                }
                            }
                            if (empty($recipientsVal) && !empty($event_data['email_recipients_address'])) {
                                $recipientsVal[] = $event_data['email_recipients_address'];
                            }
                            if (!empty($recipientsVal)) {
                                $queueData['visitor_group_no'] = $prepaid_data['visitor_group_no'];
                                $queueData['hotel_id'] = $prepaid_data['hotel_id'];
                                $queueData['cc_copy'] = STATIC_BOOKING_EMAIL_VAL;
                                $queueData['subject'] = "Order Placed Successfully";
                                $queueData['email'] = (IS_STATIC_BOOKING_EMAIL == 1) ? STATIC_BOOKING_EMAIL_VAL : $recipientsVal;
                                $queueData['request_from_name'] = API_REQUEST_FROM_NAME;
                                $queueData['request_from_email'] = API_REQUEST_FROM_EMAIL;
                                //$queueData['send_receipt'] = $send_receipt;
                                //$queueData['send_tickets'] = $send_tickets;
                                $this->CreateLog('api_send_booking_email.php', 'Success data', array("params" => json_encode($queueData)));
                                $api_report_queue = API_REPORT_QUEUE;
                                $aws_message = base64_encode(gzcompress(json_encode($queueData)));
                                $this->load->library('Sqs');
                                $sqs_object = new Sqs();
                                $MessageId = $sqs_object->sendMessage($api_report_queue, $aws_message);
                                if ($MessageId != false) {
                                    $this->load->library('Sns');
                                    $sns_object = new Sns();
                                    $sns_object->publish($MessageId . '#~#' . $api_report_queue, API_REPORT_ARN);
                                }
                            }
                        }
                    } else {
                        /** #Comment : Need to send Booking Failed Email in Case of NI Payment */
                        if (!empty($event_data['email_booking_ids'])) {
                            foreach ($event_data['email_booking_ids'] as $key => $value) {
                                if (!empty($value)) {
                                    $event_data['email_key'] = $key;
                                    $event_data['booking_references'] = array_values(array_unique($value));
                                    $this->call_webhook_email_notification($event_data);
                                }
                            }
                        } else {
                            $this->call_webhook_email_notification($event_data);
                        }
                    }
                }
            }
        }
    }

    /**
     * get_pos_type
     * @Purpose     : to get pos type of distributor.
     * @param  string $distributor_id
     */
    public function get_pos_type($distributor_id)
    {
        /* #region  EOC to get pos type */
        return $this->primarydb->db->select('pos_type')
            ->from('qr_codes')
            ->where('cod_id', $distributor_id)
            ->get()->row();
        /* #endregion EOC to get pos type */
    }

    /**
     * call_webhook_email_notification
     * @Purpose     : call webhook email notification for orders apis.
     * @param  array $event_data
     * @return void
     */
    public function call_webhook_email_notification($event_data)
    {
        $call_event = 1;
        /*if (!empty($event_data['hotel_id'])) {
            $pos_type_detail = $this->get_pos_type($event_data['hotel_id']);
            if (!empty($pos_type_detail) && $pos_type_detail->pos_type == 6) {
                $call_event = 0;
            }
        }*/
        if ($call_event == 1) {
            /* preparing request for send webhook call. */
            $event_name = "ORDER_CREATE";
            $supplier_event_name = "";
            /** #Comment : Set Eveny key Order Create or BOOKING_FAILED we send From Distributor API */
            if (!empty($event_data['email_key'])) {
                $event_name = $event_data['email_key'];
            }
            if (!empty($event_data['event_name'])) {
                $event_name = $event_data['event_name'];
            }
            if (isset($event_data["is_cancel_order_only"]) && $event_data["is_cancel_order_only"] == 1) {
                $supplier_event_name = "ORDER_CANCEL_SUPPLIER";
            }
            /*  if amend booking called. */
            if (isset($event_data['uncancel_order']) && $event_data['uncancel_order'] == 1) {
                $event_name = "ORDER_UPDATE";
            }
            if ($event_name == "ORDER_UPDATE") {
                $supplier_event_name = "ORDER_UPDATE_SUPPLIER";
            }
            $request_data["data"]["notification"]["notification_event"] = $event_name;
            $request_data["data"]["notification"]["notification_item_id"]["order_reference"] = !empty($event_data['visitor_group_no']) ? $event_data['visitor_group_no'] : '';
            if (!empty($event_data['booking_references'])) {
                $request_data["data"]["notification"]["notification_item_id"]["booking_reference"] = $event_data['booking_references'];
            }
            if (!empty($event_data['other_data']['status'])) {
                $request_data["data"]["notification"]["notification_item_id"]["status"] = strtoupper($event_data['other_data']['status']);
            }
            if (!empty($event_data['other_data']['status'])) {
                $request_data["data"]["notification"]["notification_item_id"]["cashier_details"] = $event_data['other_data']['cashier_details'];
            }
            $this->CreateLog('webhook_email_notification.php', "VGN: " . $request_data["data"]["notification"]["notification_item_id"]["order_reference"], array('Request' => json_encode($request_data)));
        }
        if ($call_event == 1 && !empty(COMMON_EMAIL_QUEUE_URL) && !empty(COMMON_EMAIL_TOPIC_ARN) && SERVER_ENVIRONMENT != 'Local') {
            /* preparing request for send webhook call. */
            $common_email_request_message = base64_encode(gzcompress(json_encode($request_data)));
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_email_request_message);
            if ($MessageId) {
                $this->load->library('Sns');
                $sns_object = new Sns();
                $sns_object->publish($MessageId . '#~#' . COMMON_EMAIL_QUEUE_URL, COMMON_EMAIL_TOPIC_ARN);
            }
            // send supplier email
            if (!empty($supplier_event_name) && !empty($event_data)) {
                $common_supplier_email_request_data = [
                    "data" => [
                        "notification" => [
                            "notification_event" => $supplier_event_name,
                            "notification_item_id" => [
                                "order_reference" =>  !empty($event_data['visitor_group_no']) ? $event_data['visitor_group_no'] : '',
                                "booking_reference" => $event_data['booking_references'] ? $event_data['booking_references'] : '',
                            ]
                        ]
                    ]
                ];
                $common_supplier_email_request_message = base64_encode(gzcompress(json_encode($common_supplier_email_request_data)));
                $message_id = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_supplier_email_request_message);
                if ($message_id) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish(COMMON_EMAIL_QUEUE_URL, COMMON_EMAIL_TOPIC_ARN);
                }
            }
            $grayLogData = array(
                'log_name'           => 'EmailWebhookNotification',
                'log_type'           => 'Notification',
                'api_name'           =>  'SQSNotification',
                'request_time'       => date("Y-m-d H:i:s"),
                'reference'          => $request_data["data"]["notification"]["notification_item_id"]["order_reference"]
            );
            $grayLogData['data'] = json_encode($request_data);
            $this->load->library('log_library');
            $graylogs[] = $grayLogData;
            $this->log_library->CreateGrayLog($graylogs);
        }
    }

    /* #endregion to Send Success order Booking Email */
    /* #region to send supplier notification email after amendment */
    /**
     * send_supplier_notification
     * @param  mixed $pt_data
     * @param  mixed $rel_target_ids
     * @return void
     */
    function sendSupplierNotificationEmail($pt_data = array(), $rel_target_ids = array())
    {
        $this->CreateLog('supplier_notification_api.php', 'step-2', array('php_array' => json_encode($pt_data)));
        if (!empty($rel_target_ids)) {
            $pickup_locations_data = $this->common_model->get_pickups_data($rel_target_ids);
        }
        $mail_key = 0;
        $api_channel_type = array('4', '6', '7', '8', '9', '12', '15');
        $extra_booking_information_for_api = '';
        $final_array_of_api = array();
        $api_data = 0;
        if (isset($pt_data[0]['visitor_group_no'])) {
            $hotel_data = $this->secondarydb->rodb->get_where("hotel_ticket_overview", array("visitor_group_no" => $pt_data[0]['visitor_group_no']))->row_array();
            $details['hotel_ticket_overview'] = (object) $hotel_data;
            $visitor_group_no = $hotel_data['visitor_group_no'];
            $hotel_id = $hotel_data['hotel_id'];
            $hotel_name = $hotel_data['hotel_name'];
            $channel_type = isset($hotel_data['channel_type']) ? $hotel_data['channel_type'] : 0;
            $is_prioticket = $hotel_data['is_prioticket'];
            $isBillToHotel = $hotel_data['isBillToHotel'];
            $timezone = $hotel_data['timezone'];
            $createdOn = $hotel_data['createdOn'];
            $parentPassNo = $hotel_data['parentPassNo'];
            $passNo = $hotel_data['passNo'];
            $order_total_price = $hotel_data['total_price'];
            $order_total_tickets = $hotel_data['quantity'];
            $order_status = isset($hotel_data['order_status']) ? $hotel_data['order_status'] : 0;
        }

        $extra_options_per_ticket = array();
        $prepaid_extra_options_data = $this->common_model->getExtraOptions($visitor_group_no);
        if (!empty($prepaid_extra_options_data)) {
            foreach ($prepaid_extra_options_data as $extra_option) {
                $extra_options_per_ticket[$extra_option['ticket_id']][$extra_option['description']][] = $extra_option['quantity'];
            }
        }
        foreach ($pt_data as $prepaid_tickets_data) {

            $ticket_details[$prepaid_tickets_data['ticket_id']] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $prepaid_tickets_data['ticket_id']));
            $prepaid_tickets_data_array = $prepaid_tickets_data;
            $channel_type               = $prepaid_tickets_data['channel_type'];
            $room_no                    = $prepaid_tickets_data['without_elo_reference_no'] != 'Prio' ? $prepaid_tickets_data['without_elo_reference_no'] : '';
            $guest_names                = $prepaid_tickets_data['guest_names'] != '' ? $prepaid_tickets_data['guest_names'] : '';
            $guest_email                = $prepaid_tickets_data['guest_emails'] != '' ? $prepaid_tickets_data['guest_emails'] : '';
            $phone_number               = isset($prepaid_tickets_data['phone_number']) ? $prepaid_tickets_data['phone_number'] : '';
            $reseller_id               = isset($prepaid_tickets_data['reseller_id']) ? $prepaid_tickets_data['reseller_id'] : '';
            $activation_method          = $prepaid_tickets_data['activation_method'];
            $mail_content[$mail_key]    = $prepaid_tickets_data;
            $ticket_age_groups          = $this->order_process_vt_model->getAgeGroupAndDiscount($prepaid_tickets_data['tps_id']);
            $ticket_type                = $prepaid_tickets_data['ticket_type'];
            $age_groups                 = $ticket_age_groups->agefrom . '-' . $ticket_age_groups->ageto;
            $mail_content[$mail_key]['extra_text_field']            = $ticket_details[$prepaid_tickets_data['ticket_id']]->extra_text_field;
            $mail_content[$mail_key]['tax_id']                      = $ticket_age_groups->ticket_tax_id;
            $mail_content[$mail_key]['museum_email']                = $ticket_details[$prepaid_tickets_data['ticket_id']]->msgClaim;
            $mail_content[$mail_key]['booking_email_text']          = $ticket_details[$prepaid_tickets_data['ticket_id']]->booking_email_text;
            $mail_content[$mail_key]['is_reservation']              = $ticket_details[$prepaid_tickets_data['ticket_id']]->is_reservation;
            $mail_content[$mail_key]['museum_additional_email']     = $ticket_details[$prepaid_tickets_data['ticket_id']]->additional_notification_emails;
            $mail_content[$mail_key]['ticket_id']                   = $ticket_id;
            $mail_content[$mail_key]['ticket_title']                = $ticket_details[$prepaid_tickets_data['ticket_id']]->postingEventTitle;
            $mail_content[$mail_key]['museum_id']                   = $ticket_details[$prepaid_tickets_data['ticket_id']]->cod_id;
            $mail_content[$mail_key]['museum_name']                 = $ticket_details[$prepaid_tickets_data['ticket_id']]->museum_name;
            $mail_content[$mail_key]['ticket_tax_id']               = $ticket_details[$prepaid_tickets_data['ticket_id']]->ticket_tax_id;
            $mail_content[$mail_key]['ticket_tax_value']            = $ticket_details[$prepaid_tickets_data['ticket_id']]->ticket_tax_value;
            $mail_content[$mail_key]['ticketpriceschedule_id']      = $prepaid_tickets_data['tps_id'];
            $mail_content[$mail_key]['age_groups']                  = array('0' => array('ticket_type' => $ticket_type, 'count' => '1', 'age_group' => $age_groups));
            $mail_content[$mail_key]['extra_booking_information']   = $prepaid_tickets_data['extra_booking_information'];
            $mail_content[$mail_key]['visitor_group_no']            = $prepaid_tickets_data['visitor_group_no'];
            $mail_content[$mail_key]['slot_type']                   = $prepaid_tickets_data['timeslot'];
            if (!empty($pickup_locations_data) && !empty($mail_content)) {
                $mail_content[$mail_key]['pickups_data']            = $pickup_locations_data[$prepaid_tickets_data['financial_id']];
                $mail_content[$mail_key]['pickups_data']['time']    = $prepaid_tickets_data['financial_name'];
            }
            if (!empty($prepaid_tickets_data['extra_booking_information'])) {
                $extra_booking_information_for_api = $prepaid_tickets_data['extra_booking_information'];
            }
            $mail_key++;
        }
        if (!empty($extra_booking_information_for_api)) {
            $extra_booking_information_for_api = json_decode($extra_booking_information_for_api, true);
            if (!empty($extra_booking_information_for_api['order_custom_fields'])) {
                foreach ($extra_booking_information_for_api['order_custom_fields'] as  $result) {
                    if ($result['custom_field_name'] == 'per_participants') {
                        foreach ($result['custom_field_value'] as $key => $result) {
                            $final_array_of_api[] = $result;
                        }
                    }
                }
            }
        }
        $prepaid_tickets_data     = $prepaid_tickets_data_array;
        if ($mail_content) {
            $client_reference     = $prepaid_tickets_data['without_elo_reference_no'];
            $booking_details     = unserialize($prepaid_tickets_data['booking_details']);
            $note = '';
            if (isset($booking_details['travelerHotel']) && !empty($booking_details['travelerHotel'])) {
                $traveler_hotel = $booking_details['travelerHotel'];
            }
            if (isset($booking_details['primary_host_notes']['note_administration']) && !empty($booking_details['primary_host_notes']['note_administration'])) {
                $note = $booking_details['primary_host_notes']['note_administration'];
            }
            if (isset($booking_details['client_reference']) && !empty($booking_details['client_reference'])) {
                $room_no = $booking_details['client_reference'];
            }
            $museums = array();
            $age_groups_array = array();
            $ticket_count = array();
            foreach ($mail_content as $content) {
                $museum_id = $content['museum_id'];
                $ticket_id = $content['ticket_id'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['extra_booking_information']   = $content['extra_booking_information'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['museum_id']                   = $museum_id;
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['museum_name']                 = $content['museum_name'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['museum_email']                = $content['museum_email'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['booking_email_text']          = $content['booking_email_text'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['museum_additional_email']     = $content['museum_additional_email'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['ticket_title']                = $content['ticket_title'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['hotel_id']                    = $content['hotel_id'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['extra_text_field']            = isset($content['extra_text_field']) ? $content['extra_text_field'] : '';
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['extra_text_field_answer']     = isset($content['extra_text_field_answer']) ? $content['extra_text_field_answer'] : '';
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['selected_date']               = $content['selected_date'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['from_time']                   = $content['from_time'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['to_time']                     = $content['to_time'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['is_reservation']              = !empty($content['is_reservation']) ? 1 : 0;
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['ticketpriceschedule_id']      = $content['ticketpriceschedule_id'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['pickups_data']                = $content['pickups_data'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['visitor_group_no']            = $content['visitor_group_no'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['ticket_booking_id']            = $content['ticket_booking_id'];
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['slot_type']                   = $content['slot_type'];
                $market_merchant_id = $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                if ($room_no != '' && !in_array($channel_type, $api_channel_type)) {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['room_no'] = $room_no;
                }
                if (isset($traveler_hotel) && $traveler_hotel != '') {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['travelerHotel'] = $traveler_hotel;
                }
                if (isset($note) && $note != '') {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['note'] = $note;
                }
                if ($guest_names != '') {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['guest_names'] = $guest_names;
                }

                if ($guest_email != '') {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['guest_email'] = $guest_email;
                }
                if ($phone_number != '') {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['phone_number'] = $phone_number;
                }
                if (in_array($channel_type, $api_channel_type) && isset($prepaid_tickets_data['without_elo_reference_no']) && !empty($prepaid_tickets_data['without_elo_reference_no'])) {
                    $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['client_reference'] = $client_reference;
                }
                if (!empty($content['age_groups'])) {
                    foreach ($content['age_groups'] as $group) {
                        if (in_array($group['age_group'], $age_groups_array[$museum_id][$prepaid_tickets_data['ticket_id']])) {
                            $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['count'] = $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['count'] + 1;
                        } else {
                            $age_groups_array[$museum_id][$prepaid_tickets_data['ticket_id']][] = $group['age_group'];
                            $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['count'] = 1;
                        }
                        $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['type'] = $group['ticket_type'];
                        $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['passNO'][] = $content['passNo'];

                        $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['ticketpriceschedule_id'] = $content['ticketpriceschedule_id'];
                        if (!empty($final_array_of_api)) {
                            foreach ($final_array_of_api as $row) {
                                if (!empty($row[$content['ticketpriceschedule_id']])) {
                                    $ticket_count[$museum_id][$prepaid_tickets_data['ticket_id']][$group['age_group']]['extra_booking_information_api'] = $row[$content['ticketpriceschedule_id']];
                                    $api_data = 1;
                                }
                            }
                        }
                    }
                }
                $museums[$museum_id][$prepaid_tickets_data['ticket_id']]['age_group'] = $ticket_count;
            }
        }
        $this->CreateLog('supplier_notification_api.php', 'step-3', array('php_array' => json_encode($museums)));
        foreach ($museums as $key => $value1) {
            $notification_mail_contents = array();
            $ticket_booking_id = "";
            foreach ($value1 as $key2 => $value) {
                $notification_emails    = array();
                $ticket_detail_for_supplier_notification = array();
                $subject  = 'Booking Cancelled - Order reference No. ' . $visitor_group_no;
                $notification_emails[]  = $value['museum_email'];
                $additional_email       = array();
                $additional_email       = explode("::", $value['museum_additional_email']);
                $notification_emails    = array_unique(array_merge($notification_emails, $additional_email));
                $attachments            = array();
                $ticket_detail_for_supplier_notification['ticket_id']                   = $key2;
                $ticket_detail_for_supplier_notification['museum_id']                   = $value['museum_id'];
                $ticket_detail_for_supplier_notification['museum_name']                 = $value['museum_name'];
                $ticket_detail_for_supplier_notification['hotel_id']                    = $value['hotel_id'];
                $ticket_detail_for_supplier_notification['visitor_group_no']            = $visitor_group_no;
                $ticket_detail_for_supplier_notification['ticket_title']                = $value['ticket_title'];
                $ticket_detail_for_supplier_notification['extra_text_field']            = $value['extra_text_field'];
                $ticket_detail_for_supplier_notification['extra_text_field_answer']     = $value['extra_text_field_answer'];
                $ticket_detail_for_supplier_notification['extra_booking_information']   = $value['extra_booking_information'];
                $ticket_detail_for_supplier_notification['selected_date']               = $value['selected_date'];
                $ticket_detail_for_supplier_notification['from_time']                   = $value['from_time'];
                $ticket_detail_for_supplier_notification['to_time']                     = $value['to_time'];
                $ticket_detail_for_supplier_notification['is_reservation']              = $value['is_reservation'];
                $ticket_detail_for_supplier_notification['pickups_data']                = $value['pickups_data'];
                $ticket_detail_for_supplier_notification['market_merchant_id']          = $value['market_merchant_id'];
                $ticket_detail_for_supplier_notification['booking_email_text']          = $value['booking_email_text'];
                $ticket_booking_id = $value['ticket_booking_id'];
                if ($value['room_no'] != '') {
                    $ticket_detail_for_supplier_notification['room_no']                 = $value['room_no'];
                }
                if ($value['guest_email'] != '') {
                    $ticket_detail_for_supplier_notification['guest_email'] = $value['guest_email'];
                }
                if ($value['guest_names'] != '') {
                    $ticket_detail_for_supplier_notification['guest_names'] = $value['guest_names'];
                }
                if ($value['phone_number'] != '') {
                    $ticket_detail_for_supplier_notification['phone_number'] = $value['phone_number'];
                }
                if ($value['client_reference'] != '') {
                    $ticket_detail_for_supplier_notification['client_reference'] = $value['client_reference'];
                }
                if (isset($value['travelerHotel']) && $value['travelerHotel'] != '') {
                    $ticket_detail_for_supplier_notification['travelerHotel'] = $value['travelerHotel'];
                }
                if (isset($value['note']) && $value['note'] != '') {
                    $ticket_detail_for_supplier_notification['note'] = $value['note'];
                }
                $age_groups = array();
                if (!empty($value['age_group'])) {
                    foreach ($value['age_group'][$key][$key2] as $key1 => $data1) {
                        $age_group['age_group']     = $key1;
                        $age_group['ticket_id']     = $key2 . '_' . $data1['ticketpriceschedule_id'];
                        $age_group['count']         = $data1['count'];
                        $age_group['ticket_type']   = $data1['type'];
                        $age_group['passNO']        = implode(',', array_unique($data1['passNO']));
                        if (!empty($data1['extra_booking_information_api'])) {
                            $age_group['extraBookIngInformationApi'] = $data1['extra_booking_information_api'];
                        }
                        $age_groups[$key2][]        = $age_group;
                    }
                }
                $ticket_detail_for_supplier_notification['age_groups'] = $age_groups;
                $ticket_detail_for_supplier_notification['hotel_name'] = $hotel_name;
                $ticket_detail_for_supplier_notification['slot_type'] = $value['slot_type'];
                $notification_emails = array_filter($notification_emails);
                if (!empty($notification_emails)) {
                    foreach ($notification_emails as $notification_email) {
                        $notification_mail_contents[$notification_email][] = $ticket_detail_for_supplier_notification;
                    }
                }
            }

            if (!empty($notification_mail_contents)) {
                $this->CreateLog('supplier_notification_api.php', 'step-3.1', array('visitr_group_np' => $visitor_group_no));

                $arraylist = array(
                    "notification_event" => "ORDER_CANCEL_SUPPLIER",
                    "visitor_group_no" => $visitor_group_no,
                    "booking_reference" => $ticket_booking_id
                );

                $return = $this->sendemail_model->sendSupplierNotification($arraylist);
                if ($return) {
                    $this->CreateLog('supplier_notification_api.php', 'step-3.2', array('visitr_group_np' => $visitor_group_no));
                }
            }

            foreach ($notification_mail_contents as $email => $mail_content) {

                if (empty($mail_content) || empty($email)) {
                    continue;
                }
                $content_data['mail_content'] = $mail_content;
                $content_data['extra_services'] = $extra_service_options_for_email;
                $content_data['cancel_ticket'] = 0;
                $content_data['extra_options_per_ticket'] = $extra_options_per_ticket;
                if (!empty($api_data)) {
                    $content_data['api_data'] = $api_data;
                }
                $content_data['distributor_name'] = $hotel_name;
                $content_data['market_merchant_id'] = $market_merchant_id;
                $arraylist['emailc'] = $email;
                $arraylist['subject'] = $subject;
                $arraylist['attachments'] = array();
                $arraylist['BCC'] = array();
                $arraylist['market_merchant_id'] = !empty($market_merchant_id) ? $market_merchant_id : 0;
                $arraylist['museum_id'] = $key;
                $arraylist['reseller_id'] = !empty($reseller_id) ? $reseller_id : 0;
                $arraylist['visitor_group_no'] = $visitor_group_no;
                $arraylist['api_email'] = 1;
                $arraylist['cancel_email'] = 1;
                if (!empty($email) && false) {
                    $this->CreateLog('supplier_notification_api.php', 'step-4', array('visitr_group_np' => $visitor_group_no, 'array_lsit' => json_encode($arraylist)));
                    $return = $this->sendemail_model->sendSupplierNotification($arraylist);
                }
            }
        }
    }
    /* #endregion */
}
