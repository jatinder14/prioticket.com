<?php

class Order_process_update extends MY_Controller {
    
    /* #region for construct  */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('order_process_update_model');
        $this->load->model('order_process_model');
        $this->load->library('log_library');
        $this->load->library('purgefastly');
        $this->load->library('elastic_search');
        $this->load->model('sendemail_model');
        $this->base_url = $this->config->config['base_url'];
        define('EVAN_EVANS_RESELLER_ID', 541);
    }
    /* #endregion */

   /* #region function To update the DB1 and DB2 from queues */ 
    /**
     * update_in_db
     *
     * @return void
     *  @author Taranjeet singh <taran.intersoft@gmail.com> on Sep, 2017
     */
    function update_in_db() {
        global $MPOS_LOGS;
        $webhook_email_notification_data = [];
        $order_event_data = [];
        $is_paymnet_full_amount_paid = 0;
        $fastly_purge_key = 'ORDER_CANCEL';
        // Queue process check
        if (STOP_UPDATE_QUEUE) {
            exit();
        }
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        $queueUrl = UPDATE_DB_QUEUE_URL;
        $messages = array();
        if (SERVER_ENVIRONMENT == 'Local') {
            $postBody = file_get_contents('php://input');
            $messages = array(
                'Body' => $postBody
            );
        } else {
            $messages = $sqs_object->receiveMessage($queueUrl);
            $messages = $messages->getPath('Messages');
        }
        /* It receive message from given queue. */
        if (!empty($messages)) {
            $this->load->model('realtime_sync_model');
            /* Logs for duplicate entry issue 17/03/2023 */
            $duplicateEntryCheck = 0;
            foreach ($messages as $message) {
                /* BOC It remove message from SQS queue for next entry. */
                if (SERVER_ENVIRONMENT != 'Local') {
                    /* EOC It remove message from SQS queue for next entry. */
                    /* BOC extract and convert data in array from queue */
                    $string = $message['Body'];
                } else {
                    $string = $message;
                }
                $lambdaProcessMessage = $string;
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $queue_data = json_decode($string, true);
                $notify_vgn = '';
                /* EOC Get extract and convert data in array from queue */

                $this->CreateLog('confirm_order_db.php', "queue_data", array(json_encode($queue_data)));
                if (SERVER_ENVIRONMENT != 'Local') {
                    /* Logs for duplicate entry issue 17/03/2023 */
                    if ($duplicateEntryCheck == $queue_data['prepaid_ticket_ids']) {
                        $this->CreateLog('debug_duplicate_entries.php', "debugging", array(json_encode($queue_data)));
                    }
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    if (!empty($queue_data['action']) && in_array($queue_data['action'],['third_party_lambda_order_sync','TIQETS_WEBHOOK']) && !empty($queue_data['visitor_group_no'])) {
                        $lambda_vgn = $queue_data['visitor_group_no'];
                        $prepaid_ticket_ids = $duplicateEntryCheck = $queue_data['prepaid_ticket_ids'] ?? '';
                        $array_keys['where'] = "visitor_group_no = $lambda_vgn";
                        if (!empty($prepaid_ticket_ids)) {
                            $array_keys['where'] .= " and prepaid_ticket_id in ($prepaid_ticket_ids)";
                        }
                        $db_data = $this->common_model->find('prepaid_tickets', array('select' => '*', 'where' => $array_keys['where']), "array", "2");
                        if (empty($db_data)) {
                            $MessageId = $sqs_object->sendMessage($queueUrl, $lambdaProcessMessage);
                            if ($MessageId) {
                                $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }
                            return true;
                        }
                    }
                }
                if (isset($queue_data['write_in_mpos_logs']) && $queue_data['write_in_mpos_logs'] == 1) {
                    $MPOS_LOGS['write_in_mpos_logs'] = 1;
                }
                /* BOC If DB! query exist in array then execute in Primary DB */
                if (!empty($queue_data['db1'])) {
                    $i = 0;
                    foreach ($queue_data['db1'] as $query) {
                        if (trim($query) != '') {
                            $query = trim($query);
                            $this->db->query($query);
                            $this->CreateLog('confirm_order_db.php', 'db1 : ', array("rows" => $this->db->affected_rows(), "query"=>$query, "executed_query"=>$this->db->last_query()));
                            $logs['db1_update_' . $i] = $this->db->last_query();
                        }
                        $i++;
                    }
                }
                if (!empty($queue_data['DB1'])) {
                    $i = 0;
                    foreach ($queue_data['DB1'] as $query) {
                        if (trim($query) != '') {
                            $query = trim($query);
                            $this->db->query($query);
                            $logs['DB1_update_' . $i] = $this->db->last_query();
                        }
                        $i++;
                    }
                }
                /* EOC If DB1 queries exist in array then execute in Primary DB */

                /* BOC If DB2 queries exist in array then execute in Secondary DB */
                $query_error_flag = 0;
                if (!empty($queue_data['db2'])) {
                    $i = 0;
                    foreach ($queue_data['db2'] as $query) {
                        if (trim($query) == '') {
                            continue;
                        }
                        $query = trim($query);
                        $this->secondarydb->db->query($query);
                        $logs['db2_update_' . $i] = $this->secondarydb->db->last_query();
                        if ($this->secondarydb->db->affected_rows() == 0) {
                            $query_error_flag = 1;
                        }
                        // To update the data in RDS realtime
                        if ((strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview') || strstr($query, 'prepaid_extra_options')) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                            $this->fourthdb->db->query($query);
                            $logs['db4_update_' . $i] = $this->fourthdb->db->last_query();
                        }
                        $i++;
                    }
                    if ($query_error_flag && 0) {
                        $error_message = array();
                        $error_message = json_encode($queue_data['db2']);
                        $error_message = base64_encode(gzcompress($error_message));
                        $sqs_object->sendMessage(UPDATE_DB_ERROR_QUEUE_URL, $error_message);
                    }
                }
                if (isset($queue_data['update_booking']) && $queue_data['update_booking'] == 'yes' && isset($queue_data['order_contacts'])) {
                    $order_contacts = $queue_data['order_contacts'];
                    $this->order_process_model->contact_add($order_contacts, $queue_data['api_visitor_group_no']);
                    foreach ($order_contacts as $contact) {
                        $distributor_id = isset($contact['distributor_id']) ? $contact['distributor_id'] : '' ;
                        if (!empty($distributor_id)) {
                            $fastly_purge_key = 'CONTACT_UPDATE';
                            break;
                        }
                    }
                    $notify_vgn = $queue_data['api_visitor_group_no'];
                } else if (isset($queue_data['update_booking']) && $queue_data['update_booking'] == 'yes' && !empty($queue_data['IsUpdateBookingNotes'])) {
                    $notify_vgn = $queue_data['api_visitor_group_no'];
                    $arraylist = array(
                        "notification_event" => "ORDER_UPDATE_SUPPLIER",
                        "visitor_group_no" => $notify_vgn ?? '',
                        "booking_reference" => [$queue_data['ticket_booking_id']] ?? ''
                    );
                    $return = $this->sendemail_model->sendSupplierNotification($arraylist);
                    if ($return) {
                        $this->CreateLog('supplier_notification_api.php', 'step-1.1', array('visitor_group_no' => $visitor_group_no));
                    }
                }
                if (!empty($queue_data['DB2'])) {
                    $i = 0;
                    foreach ($queue_data['DB2'] as $query) {
                        if (trim($query) == '') {
                            continue;
                        }
                        $query = trim($query);
                        $this->secondarydb->db->query($query);
                        $logs['DB2_update_' . $i] = $this->secondarydb->db->last_query();
                        // To update the data in RDS realtime
                        if ((strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview') || strstr($query, 'prepaid_extra_options')) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                            $this->fourthdb->db->query($query);
                            $logs['DB4_update_' . $i] = $this->fourthdb->db->last_query();
                        }
                        $i++;
                    }
                }                  
                /* Here start is a code to sending email to the order modification  **/            
                if((!empty($queue_data['action']) && $queue_data['action'] == 'update_reservation_details' && !empty($queue_data['ticket_booking_id_data_array'])) || (!empty($queue_data['action']) && $queue_data['action'] == 'send_email_during_correction' && !empty($queue_data['ticket_booking_id_data_array']))) {
                    $common_email_request_data = [
                        "data" => [
                            "notification" => [
                                "notification_event" => "ORDER_UPDATE",
                                "notification_item_id" => [
                                    "order_reference" =>  $queue_data['visitor_group_no'], 
                                    "booking_reference" => array_unique($queue_data['ticket_booking_id_data_array']),                                       
                                ]
                            ]
                        ]
                    ];    
                    $common_email_request_message = base64_encode(gzcompress(json_encode($common_email_request_data)));
                    $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_email_request_message);
                    if ($MessageId) {
                        $sns_object->publish('cancel', COMMON_EMAIL_TOPIC_ARN);
                    }
                }
                /** Prepare Bundle Entries In Payment Version Updagrade case */
                $bundle_data = [];
                if (!empty($queue_data['db2_insertion']) && !empty($queue_data['is_bundle_product'])) {
                    $vt_version_data = $this->secondarydb->rodb->select('version')->from('visitor_tickets')->where('visitor_group_no', $queue_data['visitor_group_no'])->get()->result_array();
                    $vt_version = max($vt_version_data);
                    $this->secondarydb->db->select('transaction_id');
                    $this->secondarydb->db->from('visitor_tickets');
                    $this->secondarydb->db->where("vt_group_no", $queue_data['visitor_group_no']);
                    $this->secondarydb->db->where("row_type", '1');
                    $this->secondarydb->db->where("deleted", '0');
                    $this->secondarydb->db->where("version", $vt_version['version']);
                    $this->secondarydb->db->where("transaction_type_name", 'Bundle Discount');
                    $result = $this->secondarydb->db->group_by("transaction_id")->get();
                    if ($result->num_rows() > 0) {
                        $result = $result->result();
                        $bundle_data = array_column($result, 'transaction_id');
                        $vt_data = [];
                        foreach ($queue_data['db2_insertion'] as $array_keys) {
                            if ($array_keys['table'] == 'visitor_tickets') {
                                $vt_data = $array_keys;
                                break;
                            }
                        }
                        foreach ($bundle_data as $trans_id) {
                            $vt_data['where'] = 'transaction_id = "' . $trans_id . '" and vt_group_no = "' . $queue_data['visitor_group_no'] . '" and deleted = "0"';
                            $queue_data['db2_insertion'][] = $vt_data;
                        }
                    }
                }

                /* Here end data **/
                if(!empty($queue_data['db2_insertion'])) {
                    if(ENABLE_ELASTIC_SEARCH_SYNC == 1) {
                        $this->load->library('elastic_search');
                    }
                     /* Handle case for make payment venue orders v3.x.*/
                    $db2_insertion_data = $this->handle_promocode_vt_insetion($queue_data);
                    if (!empty($db2_insertion_data)) {
                        $queue_data['db2_insertion'] = array_merge($queue_data['db2_insertion'], $db2_insertion_data);
                    }
                    $i = 0;
                    foreach ($queue_data['db2_insertion'] as $array_keys) {

                        $array_keys['update_booking_value'] = $queue_data['update_booking_value'] ?? 0;
                        $array_keys['update_booking_version'] = $queue_data['update_booking_version'] ?? 0;

                        if (!empty($array_keys['table']) && !empty($array_keys['columns']) && !empty($array_keys['where'])) {

                            $this->load->model('venue_model');
                            $this->venue_model->set_insert_queries($array_keys);
                            $logs['set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                            unset($MPOS_LOGS['get_insert_queries']);
                        }
                        if(isset($queue_data['channel_type']) && $queue_data['channel_type'] == "13" && ENABLE_ELASTIC_SEARCH_SYNC == 1 && !empty($array_keys['visitor_group_no']) ) {
                            $this->elastic_search->sync_data($array_keys['visitor_group_no']);
                        }
                        $i++;
                    }
                }

                if (!empty($logs)) {
                    $MPOS_LOGS['db_updates'] = $logs;
                    $logs = array();
                }
                /* EOC If DB2 queries exist in array then execute in Secondary DB */
                /* BOC If db4 queries exist in array then execute in RDS DB */
                if (!empty($queue_data['db4'])) {
                    $i = 0;
                    foreach ($queue_data['db4'] as $query) {
                        if (trim($query) == '') {
                            continue;
                        }
                        $query = trim($query);
                        // To update the data in RDS realtime
                        if ((strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview') || strstr($query, 'prepaid_extra_options')) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                            $this->fourthdb->db->query($query);
                            $logs['DB4_update_' . $i] = $this->fourthdb->db->last_query();
                        }
                        $i++;
                    }
                }
                /* EOC If db4 queries exist in array then execute in RDS DB */
                $update_ids_in_vt = array();
                /* BOC to insert refund entries in prepaid ticket table. */
                $this->CreateLog('refund_prepaid_orders.php', $queue_data['visitor_group_no'], array('prepaid_data : ' => json_encode($queue_data['refund_prepaid_orders']))); 
                if (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders'])) {
                    $this->load->model('firebase_model');
                    // secound param is to check whether order is refunded from app or wedend
                    $this->firebase_model->refund_prepaid_orders($queue_data['refund_prepaid_orders'],  0 , $queue_data['update_status']);
                    $this->CreateLog('refund_prepaid_orders.php', "step2", array('prepaid_data : ' => json_encode($queue_data['refund_prepaid_orders']))); 
                    /* HERE WE ARE USING CANCEL ORDER GUEST NOTIFICATION EMAIL TASK  **/  
                    if (!empty($queue_data['common_cancel_email'])) {                        
                        $this->CreateLog('common_cancel_email.php', $queue_data['visitor_group_no'], array('prepaid_data : ' => "yes")); 
                        $ticket_booking_id_array = array();
                        foreach ($queue_data['refund_prepaid_orders'] as $result_order_data) {
                            $ticket_booking_id_array[] = $result_order_data['ticket_booking_id'];
                        }
                        $common_email_request_data = [
                            "data" => [
                                "notification" => [
                                    "notification_event" => "ORDER_CANCEL",
                                    "notification_item_id" => [
                                        "order_reference" =>  $queue_data['visitor_group_no'], 
                                        "booking_reference" => array_unique($ticket_booking_id_array),                                       
                                    ]
                                ]
                            ]
                        ];                                                                           
                        $common_email_request_message = base64_encode(gzcompress(json_encode($common_email_request_data)));
                        $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_email_request_message);
                        if ($MessageId) {
                            $sns_object->publish('cancel', COMMON_EMAIL_TOPIC_ARN);
                        }
                    }                    
                }
                /* EOC to insert refund entries in prepaid ticket table. */

                /* EOC order cancel notification when call cancel order only from 3.3  */
                if (!empty($queue_data['order_payment_details'])) {
                    if(!empty($queue_data['insert_paid_payment_detail_case'])){
                        $this->order_process_model->add_payments($queue_data['order_payment_details'], 0 , $queue_data);
                    }else{
                        $this->order_process_model->add_payments($queue_data['order_payment_details']);
                    }
                    /* send webhook email notification when  refund payment only. */
                    if (isset($queue_data['is_cancel_payment_only']) && !empty($queue_data['api_version_booking_email'])) {
                        if (isset($queue_data['order_payment_details'][0])) {
                            $event_data['visitor_group_no'] = $queue_data['order_payment_details'][0]['visitor_group_no'];
                            $event_data['reseller_id'] = $queue_data['order_payment_details'][0]['reseller_id'];
                        } else {
                            $event_data['visitor_group_no'] = $queue_data['order_payment_details']['visitor_group_no'];
                            $event_data['reseller_id'] = $queue_data['order_payment_details']['reseller_id'];
                        }
                        if ($event_data['reseller_id'] != EVAN_EVANS_RESELLER_ID) {
                            $event_data['event_name'] = 'PAYMENT_REFUND';
                            $event_data['hotel_id'] = !empty($queue_data['hotel_id']) ? $queue_data['hotel_id'] : '';
                            $webhook_email_notification_data = $event_data;
                        }
                    }
                }
                if (!empty($queue_data['update_order_payment_details'])) {
                    if (!empty($queue_data['update_booking_order_payment_details'])) {
                        $this->order_process_model->update_payments($queue_data['update_booking_order_payment_details']);
                    } else {
                        $this->order_process_model->update_payments($queue_data['update_order_payment_details']);
                    }
                    if ((!empty($queue_data['update_order_payment_details']['visitor_group_no']) || !empty($queue_data['update_order_payment_details'][0]['visitor_group_no'])) && isset($queue_data['is_send_webhook_notification']) && $queue_data['is_send_webhook_notification'] == 1) {
                        $order_event_data['visitor_group_no'] = $notify_vgn = $queue_data['update_order_payment_details']['visitor_group_no'] ?? ($queue_data['update_order_payment_details'][0]['visitor_group_no'] ?? 0);
                        if (!empty($notify_vgn)) {
                            $notify_event = 'PAYMENT_CREATE';
                        }
                        $order_event_data['event_name'] = 'ORDER_CREATE';
                        $is_paymnet_full_amount_paid = $queue_data['is_paymnet_full_amount_paid'] ?? 0;
                    }
                    /* BOC payment create notification when payment created from 3.3  */
                    if (
                        isset($queue_data['api_version_booking_email']) && $queue_data['api_version_booking_email'] == 1
                        && $queue_data['update_order_payment_details']['reseller_id'] != EVAN_EVANS_RESELLER_ID
                    ) {
                        $order_event_data['visitor_group_no'] = $event_data['visitor_group_no'] = $queue_data['update_order_payment_details']['visitor_group_no'] ?? ($queue_data['update_order_payment_details'][0]['visitor_group_no'] ?? 0);
                        $event_data['event_name'] = 'PAYMENT_CREATE';
                        if (!empty($queue_data['update_order_payment_details']['ticket_booking_id'])) {
                            $event_data['booking_references'] = array_map("trim", array_unique(explode(',', $queue_data['update_order_payment_details']['ticket_booking_id'])));
                        }
                        $event_data['hotel_id'] = !empty($queue_data['update_order_payment_details']['distributor_id']) ? $queue_data['update_order_payment_details']['distributor_id'] : '';
                        $webhook_email_notification_data = $event_data;
                        $order_event_data['event_name'] = 'ORDER_CREATE';
                        $is_paymnet_full_amount_paid = $queue_data['is_paymnet_full_amount_paid'] ?? 0;
                    }
                    /* EOC payment create notification when payment created from 3.3  */
                }

                /* #COMMNET : Need to send the booking email for the payment paid orders */
                if (!empty($order_event_data) && !empty($is_paymnet_full_amount_paid)) {
                    $this->load->model('api_model');
                    $this->api_model->call_webhook_email_notification($order_event_data);
                }
                /* #COMMNET : Need to send the booking email for the payment paid orders */
                /* BOC If frp, SYX api */
                if (!empty($queue_data['visitor_tickets_id'])) {
                    $this->secondarydb->rodb->select('transaction_id');
                    $this->secondarydb->rodb->from('visitor_tickets');
                    $this->secondarydb->rodb->where("id", $queue_data['visitor_tickets_id']);
                    $result = $this->secondarydb->rodb->get();
                    if ($result->num_rows() > 0) {
                        $data = $result->row();
                        $this->secondarydb->db->set('action_performed', "CONCAT(action_performed, ', API_SYX')", FALSE);
                        $this->secondarydb->db->where('transaction_id', $data->transaction_id);
                        $this->secondarydb->db->update('visitor_tickets', array("used" => "1", 'updated_at' => $queue_data['visitor_tickets_updated_at']));

                        // To update the data in RDS realtime
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                            $this->fourthdb->db->set('action_performed', "CONCAT(action_performed, ', API_SYX')", FALSE);
                            $this->fourthdb->db->where('transaction_id', $data->transaction_id);
                            $this->fourthdb->db->update('visitor_tickets', array("used" => "1", 'updated_at' => $queue_data['visitor_tickets_updated_at']));
                        }
                    }
                }
                /* EOC If frp, SYX api */
                $is_amend_order_call = 0;
                /* BOC to get the data from pt,vt in case of cancellation from API's */
                if (!empty($queue_data['api_visitor_group_no']) && $queue_data['channel_name'] == 'API') {
                    $request_id_data = $requested_data = $request_main_data = $arena_notifying_arr = [];
                    $request_main_data = array(
                        'visitor_group_no' => $queue_data['api_visitor_group_no'],
                        'pt' => '1',
                        'vt' => '1',
                        'last_db1_prepaid_id' => isset($queue_data['last_db1_prepaid_id']) ? $queue_data['last_db1_prepaid_id'] : 0,
                        'OTA_action' => !empty($queue_data['OTA_action']) ? $queue_data['OTA_action'] : '',
                        'action' => !empty($queue_data['action']) ? $queue_data['action'] : '',
                        'order_updated_cashier_id' => isset($queue_data['order_updated_cashier_id']) ? $queue_data['order_updated_cashier_id'] : '',
                        'order_updated_cashier_name' => isset($queue_data['order_updated_cashier_name']) ? $queue_data['order_updated_cashier_name'] : '',
                        'activation_method' => $queue_data['activation_method'],
                        'hotel_id' => $queue_data['hotel_id'],
                        'psp_reference' => isset($queue_data['psp_reference']) ? $queue_data['psp_reference'] : '',
                        'psp_references' => isset($queue_data['psp_references']) ? $queue_data['psp_references'] : '',
                        'is_activated_update' => isset($queue_data['is_activated_update']) ? $queue_data['is_activated_update'] : '',
                        'order_refund_via_lambda_queue' => (isset($queue_data['order_refund_via_lambda_queue']) && ($queue_data['order_refund_via_lambda_queue'] == 1)) ? $queue_data['order_refund_via_lambda_queue'] : '',
                        'is_full_cancelled' => isset($queue_data['is_full_cancelled']) ? $queue_data['is_full_cancelled'] : '',
                        'is_cancel_payment_only' => isset($queue_data['is_cancel_payment_only']) ? $queue_data['is_cancel_payment_only'] : '0',
                        'cancel_payment_update_pt' => isset($queue_data['cancel_payment_update_pt']) ? $queue_data['cancel_payment_update_pt'] : '0',
                        'api_version_booking_email' => isset($queue_data['api_version_booking_email']) ? $queue_data['api_version_booking_email'] : 0,
                        'per_ticket_booking_cancel' => isset($queue_data['per_ticket_booking_cancel']) ? $queue_data['per_ticket_booking_cancel'] : '',
                        'partial_cancel_request' => isset($queue_data['partial_cancel_request']) ? $queue_data['partial_cancel_request'] : '',
                        'ticket_update' => isset($queue_data['ticket_update']) ? $queue_data['ticket_update'] : 0,
                        'is_static_cancel_call' => isset($queue_data['is_static_cancel_call']) ? $queue_data['is_static_cancel_call'] : 0,
                        'shift_id' => isset($queue_data['shift_id']) ? $queue_data['shift_id'] : '',
                        'pos_point_id' => isset($queue_data['pos_point_id']) ? $queue_data['pos_point_id'] : '',
                        'pos_point_name' => isset($queue_data['pos_point_name']) ? $queue_data['pos_point_name'] : '',
                        'cashier_register_id' => isset($queue_data['cashier_register_id']) ? $queue_data['cashier_register_id'] : '',
                        'lambda_prepaid_ids' => isset($queue_data['lambda_prepaid_ids']) ? $queue_data['lambda_prepaid_ids'] : [],
                        'lambda_skip_tp_call' => isset($queue_data['lambda_skip_tp_call']) ? $queue_data['lambda_skip_tp_call'] : [],
                        'lambda_refund_cancel_call' => isset($queue_data['lambda_refund_cancel_call']) ? $queue_data['lambda_refund_cancel_call'] : [],
                        'cancellation_reason' => isset($queue_data['cancellation_reason']) ? $queue_data['cancellation_reason'] : ''
                    );
                    if ((isset($queue_data['prepaid_ticket_ids']) && $queue_data['prepaid_ticket_ids'] != '') || (isset($queue_data['ticket_booking_id']) && $queue_data['ticket_booking_id'] != '')) {
                        $request_id_data = array(
                            'prepaid_ticket_id' => !empty($queue_data['prepaid_ticket_ids']) ? $queue_data['prepaid_ticket_ids'] : '',
                            'ticket_booking_id' => !empty($queue_data['ticket_booking_id']) ? $queue_data['ticket_booking_id'] : '',
                            'action_from' => ($queue_data['action_from'] != '') ? $queue_data['action_from'] : ''
                        );
                    }
                    if (empty($distributor_id)) {
                        $distributor_id = !empty($queue_data['hotel_id']) ? $queue_data['hotel_id'] : "";
                    }

                    /* check for arena notification */
                    if (!empty($queue_data['notify_to_arena']) && $queue_data['notify_to_arena'] == '1') {
                        $arena_notifying_arr['notify_to_arena'] = '1';
                    }
                    $requested_data = array_merge($request_main_data, $request_id_data, $arena_notifying_arr);
                    $this->load->model('api_model');
                    $is_update_pt = 0;
                    if (isset($queue_data['action_from']) && $queue_data['action_from'] != '' && isset($queue_data['ticket_update']) && $queue_data['ticket_update'] == 0) {
                        $is_amend_order_call = 1;
                    }
                   // if ($queue_data['activation_method'] != 16) {
                        $is_update_pt = 1;
                   // }
                    /* conditions for payment refund and cancel order from 3.2 */
                    if ((isset($queue_data['is_cancel_payment_only']) && !isset($queue_data['cancel_payment_update_pt']))
                            || (isset($queue_data['is_cancel_order_only']) && $queue_data['is_cancel_order_only'] == 1)) {
                        $is_update_pt = 0;
                    } else if (isset($queue_data['is_cancel_payment_only']) && isset($queue_data['cancel_payment_update_pt'])) {
                        $is_update_pt = 1;
                    } else if (!empty($queue_data['is_payment_cancel_already']) && $queue_data['is_payment_cancel_already'] == 1) {
                        $is_update_pt = 0;
                    }
                    $requested_data['cashier_type'] = $queue_data['cashier_type'] ?? 1;
                    if ($is_update_pt == 1 || (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders']))) {
                        $this->api_model->insert_refunded_orders($requested_data);
                    }
                    if(isset($queue_data['VENUE_TOPIC_ARN']))  {
                        include_once 'aws-php-sdk/aws-autoloader.php';
                        $this->load->library('Sns');
                        $request_string = json_encode($queue_data['VENUE_TOPIC_ARN']['data']);
                        $aws_message    = base64_encode(gzcompress($request_string));
                        //$aws_message = $queue_data['VENUE_TOPIC_ARN']['data'];
                        $queueUrl = QUEUE_URL_API;
                        $this->load->library('Sqs');
                        $sqs_object = new Sqs();
                        if (SERVER_ENVIRONMENT == 'Local') {
                            local_queue($aws_message, 'VENUE_TOPIC_ARN');
                        } else {
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                            if ($MessageId !== false) {
                                $sns_object = new Sns();
                                $sns_object->publish($MessageId . '#~#' . $queueUrl, TOPIC_ARN);
                            }
                        }
                    } 
                    /*To handle notification for cancel order only */
                    if ($is_amend_order_call == 0 && $is_update_pt == 0 && !empty($queue_data['is_full_cancelled'])) {
                        $notify_vgn = $queue_data['api_visitor_group_no'];
                        $notify_event = 'ORDER_CANCEL';
                    }
                    $this->CreateLog('queue_data.php', 'vt_data', array('query' => json_encode($queue_data['api_visitor_group_no'])));
                }
                /** #Comment:- Handle Refund Case for Admin Refund */
                if (isset($queue_data['action']) && in_array($queue_data['action'], ['cancel_tickets','CRON_RFN'])) {
                    $notify_vgn = $queue_data['visitor_group_no'] ?? '';
                    $notify_event = 'ORDER_CANCEL';
                }
                /**#Comment:- Handle Refund Case for Partial Cancel */
                if ((isset($queue_data['action']) && in_array($queue_data['action'], ['partial_refund_order']))) {
                    $notify_event = 'ORDER_CREATE';
                    $notify_event_version_case = "ORDER_PARTIAL_CANCEL";
                    $notify_vgn = $queue_data['visitor_group_no'] ?? '';
                }
                /**#Comment:- Handle Refund Case for Partial Cancel */
                if ((isset($queue_data['action']) && in_array($queue_data['action'], ['third_party_lambda_order_sync','tp_voucher_release']))) {
                    $notify_event = $queue_data['lambda_event'] ?? '';
                    $notify_vgn = $queue_data['visitor_group_no'] ?? '';
                }
                if (!empty($queue_data['api_visitor_group_no'])) {
                    $queue_data['visitor_group_no'] = $queue_data['api_visitor_group_no'];
                }
                
                /* FOR TRAVELER VOUCHER NOTIFICATION UPDATE TO SPOS START */
                if (isset($queue_data['webhook_notification_data']) && !empty($queue_data['webhook_notification_data']) && !empty($queue_data['webhook_notification_data'])) {
                    $this->load->model('api_model');
                    foreach ($queue_data['webhook_notification_data'] as $event_data) {
                        $this->api_model->call_webhook_email_notification($event_data);
                        /*#COMMENT : Start Block for sync the  multiple orders coming in queue for voucher release  */
                        if (!empty($queue_data['action']) && !empty($event_data['visitor_group_no']) && in_array($queue_data['action'], ['tp_voucher_release'])) {
                            $other_data = [];
                            $other_data['request_reference'] = $event_data['visitor_group_no'] ?? '';
                            $other_data['hotel_id'] = !empty($distributor_id) ? $distributor_id : (!empty($event_data['hotel_id']) ? $event_data['hotel_id'] : '');
                            $other_data['action'] = $queue_data['action'] ?? '';
                            $this->elastic_search->sync_data($event_data['visitor_group_no'], $other_data);
                        }
                        /*#COMMENT : End Block for sync the  multiple orders coming in queue for voucher release  */
                    }
                }
                /* FOR TRAVELER VOUCHER NOTIFICATION UPDATE TO SPOS END */
               /* BOC order cancel notification when call cancel order only from 3.3  */
                if (
                    (
                        (isset($queue_data['is_cancel_order_only']) && $queue_data['is_cancel_order_only'] == 1) || 
                        (isset($queue_data['is_payment_cancel_already']) && $queue_data['is_payment_cancel_already'] == 1)
                    ) || 
                    ($is_amend_order_call == 0 && $queue_data['activation_method'] == 16) && 
                    ( 
                        !empty($queue_data['api_version_booking_email']) && 
                        isset($queue_data['reseller_id']) && 
                        $queue_data['reseller_id'] != EVAN_EVANS_RESELLER_ID
                    )
                ) { 
                    $event_data['visitor_group_no'] = $queue_data['visitor_group_no'];
                    $event_data['event_name'] = 'ORDER_CANCEL';
                    $event_data['is_cancel_order_only'] = $queue_data['is_cancel_order_only'];
                    if ((isset($queue_data['order_refund_via_lambda_queue']) && ($queue_data['order_refund_via_lambda_queue'] == 1)) && empty($queue_data['lambda_refund_cancel_call'])) {
                        $event_data['event_name'] = "VOUCHER_RELEASE_FAILED";
                    }
                    if (!empty($queue_data['ticket_booking_id']) && isset($queue_data['is_full_cancelled']) &&  $queue_data['is_full_cancelled'] == 0) {
                        $ticketbooking_id = str_replace('"', '', $queue_data['ticket_booking_id']);
                        $event_data['booking_references'] = array_map("trim", array_unique(explode(',', $ticketbooking_id)));
                    }
                    $event_data['hotel_id'] = !empty($queue_data['hotel_id']) ? $queue_data['hotel_id'] : '';
                    $this->load->model('api_model');
                    $this->api_model->call_webhook_email_notification($event_data);
                }
                /* EOC order cancel notification when call cancel order only from 3.3  */
                if (!empty($webhook_email_notification_data)) {
                    $this->load->model('api_model');
                    $this->api_model->call_webhook_email_notification($event_data);
                }
                /* EOC to get the data from pt,vt in case of cancellation from API's */

                /* BOC If visitor_tickets queries to cancel order exist in array then execute in Secondary DB */
                if (!empty($queue_data['update'])) {
                    foreach ($queue_data['update'] as $where) {
                        if (isset($where['updated_rows'])) {
                            $updated_rows = $where['updated_rows'];
                            unset($where['updated_rows']);
                        } else {
                            $updated_rows = 0;
                        }
                        $this->secondarydb->db->select('distinct(transaction_id)');
                        $this->secondarydb->db->from('visitor_tickets');
                        $this->secondarydb->db->where($where);
                        $this->secondarydb->db->where('deleted', '0');
                        $this->secondarydb->db->where("transaction_type_name NOT LIKE 'Extra%'");
                        if ($updated_rows > 0) {
                            $this->secondarydb->db->limit($updated_rows);
                        }
                        $result = $this->secondarydb->db->get();
                        if ($result->num_rows() > 0) {
                            $data = $result->result();
                            $transaction_ids = array();
                            foreach ($data as $value) {
                                $transaction_ids[] = $value->transaction_id;
                            }
                            $this->secondarydb->db->where_in('transaction_id', $transaction_ids);
                            if ($queue_data['pos_type'] == 'cpos') {
                                $this->secondarydb->db->set('action_performed', "CONCAT(action_performed, ', CPOS_EOL')", FALSE);
                            }
                            $this->secondarydb->db->update('visitor_tickets', array('deleted' => '2', 'invoice_status' => '10', 'updated_at' => gmdate("Y-m-d H:i:s")));

                            // To update the data in RDS realtime
                            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                                $this->fourthdb->db->where_in('transaction_id', $transaction_ids);
                                if ($queue_data['pos_type'] == 'cpos') {
                                    $this->fourthdb->db->set('action_performed', "CONCAT(action_performed, ', CPOS_EOL')", FALSE);
                                }
                                $this->fourthdb->db->update('visitor_tickets', array('deleted' => '2', 'invoice_status' => '10', 'updated_at' => gmdate("Y-m-d H:i:s")));
                            }
                        }
                    }
                }
                /* EOC If visitor_tickets queries to cancel order exist in array then execute in Secondary DB */
                /* BOC When any data Insert with db type based in any table  */
                if (!empty($queue_data['insert'])) {
                    foreach ($queue_data['insert']['db_type'] as $db_type) {
                        $table_name = $queue_data['insert']['table_name'];
                        $insert_batch = $queue_data['insert']['insert_batch'];
                        $data = $queue_data['insert']['data'];
                        if (!empty($data)) {
                            if ($db_type == 'DB1') {
                                if ($insert_batch == '1') {
                                    $this->order_process_update_model->insert_batch($table_name, $data);
                                } else {
                                    $this->db->insert($table_name, $data);
                                }
                            } else if ($db_type == 'DB2') {
                                if ($insert_batch == '1') {
                                    $this->order_process_update_model->insert_batch($table_name, $data, '1');
                                    // To update the data in RDS realtime
                                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                                        $this->order_process_update_model->insert_batch($table_name, $data, '4');
                                    }
                                } else {
                                    $this->secondarydb->db->insert($table_name, $data);
                                    // To update the data in RDS realtime
                                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                                        $this->fourthdb->db->insert($table_name, $data);
                                    }
                                }
                            }
                        }
                    }
                }
                /* EOC When  any data Insert with db type bases in any table  */

                /* BOC When UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */
                if (!empty($queue_data['update_visitor_tickets_for_refund_order'])) {
                    $this->load->model('firebase_model');
                    $hotel_id = (isset($queue_data['hotel_id']) ? $queue_data['hotel_id'] : 0);
                    $this->firebase_model->update_visitor_for_cancel_orders($queue_data['update_visitor_tickets_for_refund_order'], $update_ids_in_vt, $hotel_id);
                    $ref = 'update_in_db->firebase_model/update_visitor_for_cancel_orders';
                }
                /* EOC UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */

                /* BOC When UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */
                if (!empty($queue_data['update_visitor_tickets_for_partial_refund_order'])) {
                    $this->load->model('firebase_model');
                    $this->firebase_model->update_visitor_for_partial_cancel_orders($queue_data['update_visitor_tickets_for_partial_refund_order'], $update_ids_in_vt);
                    $ref = 'update_in_db->firebase_model/update_visitor_for_partial_cancel_orders';
                }
                /* EOC UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */
                if (!empty($queue_data['update_pre_assigned_records'])) {
                    $this->load->model('venue_model');
                    $this->venue_model->update_pre_assigned_records($queue_data['update_pre_assigned_records']);
                    $ref = 'update_in_db->venue_model/update_pre_assigned_records';
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */
                if (!empty($queue_data['extended_linked_ticket_from_css_app']['detail'])) {
                    $this->load->model('venue_model');
                    $is_whole_order = $queue_data['extended_linked_ticket_from_css_app']['is_whole_order'];
                    $is_whole_order = !empty($is_whole_order) ? $is_whole_order : 0;
                    $this->venue_model->extended_linked_ticket_from_css_app($queue_data['extended_linked_ticket_from_css_app']['detail'], $is_whole_order);
                    $ref = 'update_in_db->venue_model/extended_linked_ticket_from_css_app';
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM OLD VENUE app to insert in secondry db */
                if (!empty($queue_data['update_preassigned_codes'])) {
                    $hto_id = $queue_data['update_preassigned_codes'];
                    $this->order_process_update_model->update_order_table($hto_id, 0, '1', $queue_data['extra_params']);
                    // To update the data in RDS realtime
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                        // third parameter for db type
                        $this->order_process_update_model->update_order_table($hto_id, 0, '4', $queue_data['extra_params']);
                    }
                    $ref = 'update_in_db->pos_model/update_order_table';
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM OLD VENUE app confirm API */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
                if (!empty($queue_data['update_venue_confirmapi_status'])) {
                    $request_data = $queue_data['update_venue_confirmapi_status'];
                    $user_id = $request_data['user_id'];
                    $user_name = $request_data['user_name'];
                    $museum_id = $request_data['museum_id'];
                    $ticket_id = $request_data['ticket_id'];
                    $scanned_at = $request_data['scanned_at'];
                    $notify_vgn = $visitor_group_no = $request_data['visitor_group_no'];
                    $order_confirm_date = gmdate('Y-m-d H:i:s');
                    $redeem_users = $user_id . '_' . gmdate('Y-m-d');
                    $prepaid_query = 'update prepaid_tickets set used="1", booking_status="1", order_confirm_date=(CASE when (booking_status="0" and channel_type="2") then "' . $order_confirm_date . '" else created_date_time end), action_performed=CONCAT(action_performed, ", CSS_GCKN"), updated_at="' . gmdate("Y-m-d H:i:s") . '", redeem_users=CONCAT(redeem_users, ",' . $redeem_users . '"), voucher_updated_by="' . $user_id . '", redeem_method="Voucher", museum_cashier_id="' . $user_id . '", museum_cashier_name="' . $user_name . '", scanned_at="' . $scanned_at . '" where visitor_group_no="' . $visitor_group_no . '" and ticket_id="' . $ticket_id . '" and museum_id="' . $museum_id . '" and used="0" ';
                    $visitor_query = 'update visitor_tickets set used="1", booking_status="1", order_confirm_date=(CASE when (booking_status="0" and channel_type="2") then "' . $order_confirm_date . '" else visit_date_time end), action_performed=CONCAT(action_performed, ", CSS_GCKN"), updated_at="' . gmdate("Y-m-d H:i:s") . '", voucher_updated_by="' . $user_id . '", redeem_method="Voucher", updated_by_id="' . $user_id . '", updated_by_username="' . $user_name . '", visit_date="' . $scanned_at . '" where vt_group_no="' . $visitor_group_no . '" and ticketId="' . $ticket_id . '" and used="0" ';
                    $this->db->query($prepaid_query);
                    $this->secondarydb->db->query($prepaid_query);
                    $this->secondarydb->db->query($visitor_query);
                    $request = array();
                    $request['museum_cashier_id'] = $user_id;
                    $request['museum_cashier_name'] = $user_name;
                    $request['visitor_group_no'] = $visitor_group_no;
                    $request['ticket_id'] = $ticket_id;
                    $request['action'] = ', CSS_GCKN';
                    $this->load->model('venue_model');
                    $this->venue_model->update_redeem_table($request);
                    // To update the data in RDS realtime
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                        $this->fourthdb->db->query($prepaid_query);
                        $this->fourthdb->db->query($visitor_query);
                    }
                    $ref = 'update_in_db->venue_model/update_redeem_table';
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
                if (!empty($queue_data['update_redeem_table'])) {
                    $notify_event = 'REDEMPTION';
                    $this->load->model('venue_model');
                    $this->venue_model->update_redeem_table($queue_data['update_redeem_table']);
                    $ref = 'update_in_db/update_redeem_table';
                    if (!empty($queue_data['update_redeem_table']['visitor_group_no'])) {
                        $notify_vgn = $queue_data['update_redeem_table']['visitor_group_no'];
                    }
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */


                /* EOC to insert confirmed enteries in VT table. */
                /* BOC to insert enteries in HTO PT VT tables for intersolver . */
                if (isset($queue_data['intersolver_ticket']) && $queue_data['intersolver_ticket'] == "1") {
                    $this->load->model('firebase_model');
                    if ($queue_data['data']['hotel_ticket_overview_data'] == "1") {
                        $queue_data['data']['hotel_ticket_overview_data'] = $this->firebase_model->insert_in_hto($queue_data['data']);
                    }
                    $this->firebase_model->insert_in_pt_vt($queue_data['data'], $queue_data['supplier_data']);
                }
                /* EOC to insert enteries in HTO PT VT tables for intersolver . */

                /* BOC to insert new enteries in VT table for MPOS . */
                if (isset($queue_data['insert_in_vt_by_mpos']) && $queue_data['insert_in_vt_by_mpos']['prepaid_ticket_ids'] != "") {
                    $pt_db2_data['prepaid_tickets_data'] = $this->order_process_update_model->find(
                            'prepaid_tickets', array(
                        'select' => '*',
                        'where' => 'prepaid_ticket_id IN (' . $queue_data['insert_in_vt_by_mpos']['prepaid_ticket_ids'] . ')'), 'array', '1');
                    $logs['data_for_VT_insertion'] = $pt_db2_data;
                    $this->order_process_model->insertdata($pt_db2_data, 0, 1, 1);
                    $MPOS_LOGS['insert_in_vt_by_mpos'] = $logs;
                }
                /* EOC to insert new enteries in VT table for MPOS . */

                /* BOC to insert confirmed enteries in VT table. */
                if (isset($queue_data['confirm_order']) && !empty($queue_data['confirm_order'])) {
                    $ticket_id = $selected_date = $from_time = $to_time = '';
                    $vt_group_no = $queue_data['confirm_order']['visitor_group_no'];
                    $pass_no = $queue_data['confirm_order']['pass_no'];
                    $prepaid_ticket_id = $queue_data['confirm_order']['prepaid_ticket_id'];
                    $hto_id = $queue_data['confirm_order']['hto_id'];
                    $action_performed = $queue_data['confirm_order']['action_performed'];
                    $sns_message_pt = $queue_data['confirm_order']['sns_message_pt'];
                    $sns_message_vt = $queue_data['confirm_order']['sns_message_vt'];
                    $voucher_updated_by = isset($queue_data['confirm_order']['voucher_updated_by']) ? $queue_data['confirm_order']['voucher_updated_by'] : 0;
                    $voucher_updated_by_name = isset($queue_data['confirm_order']['voucher_updated_by_name']) ? $queue_data['confirm_order']['voucher_updated_by_name'] : '';
                    $all_prepaid_ticket_ids = isset($queue_data['confirm_order']['prepaid_ticket_ids']) ? $queue_data['confirm_order']['prepaid_ticket_ids'] : '';
                    $multiple_hto_ids = isset($queue_data['confirm_order']['multiple_hto_ids']) ? $queue_data['confirm_order']['multiple_hto_ids'] : '';
                    if (!empty($queue_data['confirm_order']['ticket_id'])) {
                        $ticket_id = $queue_data['confirm_order']['ticket_id'];
                    }
                    if (!empty($queue_data['confirm_order']['selected_date'])) {
                        $selected_date = $queue_data['confirm_order']['selected_date'];
                    }
                    if (!empty($queue_data['confirm_order']['from_time'])) {
                        $from_time = $queue_data['confirm_order']['from_time'];
                    }
                    if (!empty($queue_data['confirm_order']['to_time'])) {
                        $to_time = $queue_data['confirm_order']['to_time'];
                    }

                    $this->order_process_update_model->update_visitor_tickets_direct($vt_group_no, $pass_no, $prepaid_ticket_id, $hto_id, $action_performed, $sns_message_pt, $sns_message_vt, $ticket_id, $db_type = '1', $order_confirm_date = '', $all_prepaid_ticket_ids, $voucher_updated_by, $voucher_updated_by_name, $scanned_at = '', $multiple_hto_ids, $selected_date, $from_time, $to_time);
                    $ref = 'update_in_db->pos_model/update_visitor_tickets_direct';


                    /* code for inserting in bigquery from mysql secondary pt tbl */
                    if (!empty($queue_data['action'])) {
                        $this->realtime_sync_model->sync_data_to_bigquery($queue_data);
                        /*                         * code for aggvt day table end here */
                    }
                } else {
                    /* code for inserting in bigquery from mysql secondary pt tbl*/
                    if (!empty($queue_data['action']) && (empty($queue_data['channel_type']) || $queue_data['channel_type'] != '5')) {
                        $logs['action_for_bigquery-'.$queue_data['action']."_".$queue_data['visitor_group_no']] = $queue_data['action']."_".$queue_data['visitor_group_no'];
                        $this->realtime_sync_model->sync_data_to_bigquery($queue_data);
                    }
                    $MPOS_LOGS['action_for_BG-' . $queue_data['action'] . "_" . $queue_data['visitor_group_no']] = $logs;
                    $logs = array();
                    /* code for inserting in bigquery from mysql secondary pt tbl ends here */
                }
                if (!empty($notify_vgn)) {
                    if (!empty($queue_data['IsUpdateBooking']) && $queue_data['IsUpdateBooking'] == '1') {
                        $notify_event = 'ORDER_UPDATE';
                    } else if (!empty($queue_data['IsOrderRedeemed']) && $queue_data['IsOrderRedeemed'] == '1') {
                        $notify_event = 'REDEMPTION';
                    }
                }
                /*Handle payment cancel api for notification */
                if (!empty($queue_data['order_payment_details']) && isset($queue_data['is_cancel_payment_only'])) {
                    $notify_event = 'PAYMENT_REFUND';
                    $notify_vgn = $queue_data['order_payment_details'][0]['visitor_group_no'];
                }
                /* BOC Insertion of data in elastic search */
                if (!empty($queue_data['visitor_group_no']) && ENABLE_ELASTIC_SEARCH_SYNC == 1) {
                    $other_data = [];
                    $other_data['request_reference'] = $queue_data['visitor_group_no'];
                    $other_data['hotel_id'] = !empty($distributor_id) ? $distributor_id : (!empty($event_data['hotel_id']) ? $event_data['hotel_id'] : '');
                    $other_data['fastly_purge_action'] = !empty($notify_event) ? $notify_event : (!empty($queue_data['update_order_payment_details'] && !empty($payment_event)) ? $payment_event : $fastly_purge_key);
                    $this->elastic_search->sync_data($queue_data['visitor_group_no'], $other_data);
                } elseif (!empty($queue_data['visitor_group_no']) && (!empty($distributor_id) || !empty($event_data['hotel_id']))) {/* Start code to purge keys as per the distributer on fastly */
                    $distributor_id = !empty($distributor_id) ? $distributor_id : (!empty($event_data['hotel_id']) ? $event_data['hotel_id'] : "");
                    $fastlykey = 'order/account/' . $distributor_id;
                    $request_reference = $queue_data['visitor_group_no'];
                    $fastly_purge_key = !empty($notify_event) ? $notify_event : (!empty($queue_data['update_order_payment_details'] && !empty($payment_event)) ? $payment_event : $fastly_purge_key);
                    $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, $fastly_purge_key);
                }
                /* EOC Insertion of data in elastic search */

                /* BOC to update credit limit */
                if (!empty($queue_data['update_credit_limit_data'])) {
                    $this->order_process_model->update_credit_limit($queue_data['update_credit_limit_data'], '1');
                    $ref = 'update_in_db->pos_model/update_credit_limit';
                }
                /* Boc soft delete whole order in mpos exception (import booking) */
                if(isset($queue_data['exception_order_for_deletion'])) {                    
                    $this->importbooking_model->update_order_in_db($queue_data['exception_order_for_deletion']);
                }
                if(isset($queue_data['mpos_exception_order_to_delete']) ) {                    
                    $this->importbooking_model->update_mposexception_in_db($queue_data['mpos_exception_order_to_delete']);
                }
                /** #Comment:- Need to check If admin Update order call */
                if (isset($queue_data['action']) && (($queue_data['action'] == 'update_user_information') || ($queue_data['action'] == 'update_reservation_details'))) {
                    $notify_vgn = $queue_data['visitor_group_no'] ?? '';
                    $notify_event = 'ORDER_UPDATE';
                }
                /* Eoc soft delete whole order in mpos exception (import booking) */
                /* EOC to update credit limit */
                $MPOS_LOGS['queue'] = 'UPDATE_DB_QUEUE_URL';
                /* Send notification for update and redemption */
                if (!empty($notify_vgn)) {
                    if (!empty($notify_event)) {
                        $this->load->model('notification_model');
                        $notify_request['reference_id'] = $notify_vgn;
                        $notify_request['event'] = [$notify_event];
                        $notify_request['cashier_type'] = $queue_data['cashier_type'] ?? 1;
                        if(!empty($notify_event_version_case)){
                            $notify_request['eventPartial'] = $notify_event_version_case; 
                        }
                        $this->notification_model->checkNotificationEventExist(array($notify_request));
                    }
                }
                /** Send Data to notifiaction Queue TO Purge Data on Fastly */
                if (!empty($queue_data['visitor_group_no'])) {
                    $this->load->model('notification_model');
                    $this->notification_model->purgeFastlyNotifiaction($queue_data['visitor_group_no'], ($notify_event ?? ''));
                }
            }
        }
    }

    /* #endregion function To update the DB1 and DB2 from crons */

    /* #region  to update_in_db_direct on local. Its same functionality as update_in_db for queues */
    /**
     * update_in_db_direct
     *
     * @return void
     * @author Taranjeet singh <taran.intersoft@gmail.com> on Sep, 2017
     */
    function update_in_db_direct() {
        $webhook_email_notification_data = [];
        // Fetch the raw POST body containing the message
        $postBody = file_get_contents('php://input');
        $string = $postBody;
        $order_update = 'update_order';
        /* BOC extract and convert data in array from queue */
        $string = gzuncompress(base64_decode($string));
        $string = utf8_decode($string);
        $this->CreateLog('update_in_db_queries.php', 'response', array('queries ' => $string));
        $queue_data = json_decode($string, true);
        $this->CreateLog('cancel_queue_data.php', $queue_data, array('queries ' => json_encode($queue_data)));

        /* EOC Get extract and convert data in array from queue */
        /* BOC If DB! query exist in array then execute in Primary DB */
        if (!empty($queue_data['db1'])) {
            foreach ($queue_data['db1'] as $query) {
                $query = trim($query);
                $this->db->query($query);
                $this->CreateLog('update_in_db_queries.php', 'db1query=>', array('queries ' => $query));
            }
        }
        if (!empty($queue_data['DB1'])) {
            foreach ($queue_data['DB1'] as $query) {
                $query = trim($query);
                $this->db->query($query);
                $this->CreateLog('update_in_db_queries.php', 'DB1=>', array('queries ' => $query));
            }
        }
        /* EOC If DB1 queries exist in array then execute in Primary DB */
        /* BOC If DB2 queries exist in array then execute in Secondary DB */
        if (!empty($queue_data['db2'])) {
            foreach ($queue_data['db2'] as $query) {
                $query = trim($query);
                $this->secondarydb->db->query($query);
                $this->CreateLog('update_in_db_queries.php', 'db2query=>', array('queries ' => $query));
            }
            if (isset($queue_data['ticket_booking_id'])) {
                $prepaid_query = 'update prepaid_tickets set updated_at="' . gmdate("Y-m-d H:i:s") . '" where visitor_group_no="' . $queue_data['api_visitor_group_no'] . '" AND ticket_booking_id in ("' . $queue_data['ticket_booking_id'] . '")';
            } else {
                $prepaid_query = 'update prepaid_tickets set updated_at="' . gmdate("Y-m-d H:i:s") . '" where visitor_group_no="' . $queue_data['api_visitor_group_no'] . '"';
            }
            $this->secondarydb->db->query($prepaid_query);
        }
        if (isset($queue_data['update_booking']) && $queue_data['update_booking'] == 'yes' && isset($queue_data['order_contacts'])) {
            $order_contacts = $queue_data['order_contacts'];
            foreach ($order_contacts as $contact) {
                $distributor_id = isset($contact['distributor_id']) ? $contact['distributor_id'] : '' ;
                if (!empty($distributor_id)) {
                    $order_update='update_contact';
                    break;
                }
            }
            $this->order_process_model->contact_add($order_contacts, $queue_data['api_visitor_group_no']);
        }

        if (!empty($queue_data['DB2'])) {
            foreach ($queue_data['DB2'] as $query) {
                $query = trim($query);
                $this->secondarydb->db->query($query);
                $this->CreateLog('update_in_db_queries.php', 'DB2=>', array('queries ' => $query));
            }
        }
        /** Prepare Bundle Entries In Payment Version Updagrade case */
        $bundle_data = [];
        if (!empty($queue_data['is_bundle_product'])) {
            $vt_version_data = $this->secondarydb->rodb->select('version')->from('visitor_tickets')->where('visitor_group_no', $queue_data['visitor_group_no'])->get()->result_array();
            $vt_version = max($vt_version_data);
            $this->secondarydb->db->select('transaction_id');
            $this->secondarydb->db->from('visitor_tickets');
            $this->secondarydb->db->where("vt_group_no", $queue_data['visitor_group_no']);
            $this->secondarydb->db->where("row_type", '1');
            $this->secondarydb->db->where("deleted", '0');
            if (empty($queue_data['is_cancel_order'])) {
                $this->secondarydb->db->where("is_refunded", '0');
            }
            $this->secondarydb->db->where("version", $vt_version['version']);
            $this->secondarydb->db->where("transaction_type_name", 'Bundle Discount');
            $result = $this->secondarydb->db->group_by("transaction_id")->get();
            if ($result->num_rows() > 0) {
                $result = $result->result();
                $bundle_data = array_column($result, 'transaction_id');
                $vt_data = [];
                foreach ($queue_data['db2_insertion'] as $array_keys) {
                    if ($array_keys['table'] == 'visitor_tickets') {
                        $vt_data = $array_keys;
                        break;
                    }
                }
                foreach ($bundle_data as $trans_id) {
                    $vt_data['where'] = 'transaction_id = "'.$trans_id.'" and vt_group_no = "'.$queue_data['visitor_group_no'].'" and deleted = "0"';
                    $queue_data['db2_insertion'][]=$vt_data;
                }
            }
        }
        if (!empty($queue_data['db2_insertion'])) {
            /* Handle case for make payment venue orders v3.x.*/
            $db2_insertion_data = $this->handle_promocode_vt_insetion($queue_data);
            if (!empty($db2_insertion_data)) {
                $queue_data['db2_insertion'] = array_merge($queue_data['db2_insertion'], $db2_insertion_data);
            }
            $i = 0;
            foreach ($queue_data['db2_insertion'] as $array_keys) {
                $array_keys['update_booking_value'] = $queue_data['update_booking_value'] ?? 0;
                $array_keys['update_booking_version'] = $queue_data['update_booking_version'] ?? 0;
                if (!empty($array_keys['table']) && !empty($array_keys['columns']) && !empty($array_keys['where'])) {
                    $this->load->model('venue_model');
                    $this->venue_model->set_insert_queries($array_keys);
                }
                $i++;
            }
        }

        /* EOC If DB2 queries exist in array then execute in Secondary DB */
        /* BOC to insert refund entries in prepaid ticket table. */
        if (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders'])) {

            $prepaid_table = 'prepaid_tickets';
            if (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders'])) {
                foreach ($queue_data['refund_prepaid_orders'] as $key => $prepaid_order) {
                    $channel_type = $prepaid_order['channel_type'];
                    $visitor_group_no = $prepaid_order['visitor_group_no'];
                    $pt_ids[$key] = $key;
                }
                if ($channel_type == '10' || $channel_type == '11') {
                    $this->CreateLog('refund.php', 'ids', array(json_encode($pt_ids)));
                    $pt_db2_data = $this->order_process_update_model->find($prepaid_table, array('select' => 'prepaid_ticket_id, museum_net_fee, hgs_net_fee, distributor_net_fee, museum_gross_fee, hgs_gross_fee, distributor_gross_fee, merchant_admin_id', 'where' => 'prepaid_ticket_id IN (' . implode(',', array_keys($pt_ids)) . ')'), 'array', '1');
                    $this->CreateLog('refund.php', 'query ' . $this->secondarydb->rodb->last_query(), array());
                    foreach ($pt_db2_data as $data) {
                        $pt_fields_update[$data['prepaid_ticket_id']] = array(
                            'museum_net_fee' => $data['museum_net_fee'],
                            'hgs_net_fee' => $data['hgs_net_fee'],
                            'distributor_net_fee' => $data['distributor_net_fee'],
                            'museum_gross_fee' => $data['museum_gross_fee'],
                            'hgs_gross_fee' => $data['hgs_gross_fee'],
                            'distributor_gross_fee' => $data['distributor_gross_fee'],
                            'distributor_gross_fee' => $data['distributor_gross_fee'],
                            'merchant_admin_id' => $data['merchant_admin_id'],
                        );
                    }
                    $this->CreateLog('refund.php', 'data', array(json_encode($pt_fields_update)));
                    $max_prepaid_id = '';
                    $prepaid_data = reset($this->order_process_update_model->find('prepaid_tickets', array('select' => 'created_date_time, max(prepaid_ticket_id) as max_prepaid_id', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" ')));
                    if (date('Y-m-d', strtotime($prepaid_data['created_date_time'])) >= '2018-07-12') {
                        $max_prepaid_id = $prepaid_data['max_prepaid_id'];
                    }
                }
                $i = 0;
                $total_price = 0;
                $prepaid_table = 'prepaid_tickets';
                foreach ($queue_data['refund_prepaid_orders'] as $pid => $prepaid_order) {
                    if (($channel_type == '10' || $channel_type == '11') && $max_prepaid_id != '') {
                        $prepaid_order['prepaid_ticket_id'] = ++$max_prepaid_id;
                        if ($prepaid_order['is_addon_ticket'] == '2') {
                            $prepaid_order['visitor_tickets_id'] = $prepaid_order['prepaid_ticket_id'] . '02';
                        } else {
                            $transaction_id[$prepaid_order['clustering_id']] = $prepaid_order['prepaid_ticket_id'];
                            $prepaid_order['visitor_tickets_id'] = $prepaid_order['prepaid_ticket_id'] . '01';
                        }
                        $update_ids_in_vt[$prepaid_order['visitor_group_no'] . '_' . $prepaid_order['ticket_id'] . '_' . $prepaid_order['tps_id'] . '_' . $prepaid_order['passNo']][] = array('prepaid_ticket_id' => $prepaid_order['prepaid_ticket_id'], 'visitor_tickets_id' => $prepaid_order['visitor_tickets_id'], 'transaction_id' => $transaction_id[$prepaid_order['clustering_id']], 'is_addon_ticket' => $prepaid_order['is_addon_ticket']);
                    }
                    if ($channel_type == '10' || $channel_type == '11' && !empty($pt_fields_update)) {
                        $museum_net_fee = $pt_fields_update[$pid]['museum_net_fee'];
                        $hgs_net_fee = $pt_fields_update[$pid]['hgs_net_fee'];
                        $distributor_net_fee = $pt_fields_update[$pid]['distributor_net_fee'];
                        $museum_gross_fee = $pt_fields_update[$pid]['museum_gross_fee'];
                        $hgs_gross_fee = $pt_fields_update[$pid]['hgs_gross_fee'];
                        $distributor_gross_fee = $pt_fields_update[$pid]['distributor_gross_fee'];
                        $refund_merchant_admin_id = $pt_fields_update[$pid]['merchant_admin_id'];
                    }
                    $prepaid_order_db1 = $this->order_process_update_model->set_unset_array_values($prepaid_order);
                    $this->db->insert('prepaid_tickets', $prepaid_order_db1);
                    $this->set_unset_expedia_prepaid_columns($prepaid_order_db1);
                    unset($prepaid_order['market_merchant_id']);
                    $update_cashier_register_data['activation_method'] = $prepaid_order['activation_method'];
                    if ($prepaid_order['is_addon_ticket'] == '0') {
                        $total_price += $prepaid_order['price'];
                    }
                    $update_cashier_register_data['hotel_id'] = $prepaid_order['hotel_id'];
                    $distributor_id = empty($distributor_id) ? $prepaid_order['hotel_id'] : $distributor_id;
                    $update_cashier_register_data['cashier_id'] = $prepaid_order['cashier_id'];
                    $update_cashier_register_data['shift_id'] = $prepaid_order['shift_id'];
                    $update_cashier_register_data['pos_point_id'] = $prepaid_order['pos_point_id'];
                    $update_cashier_register_data['pos_point_name'] = $prepaid_order['pos_point_name'];
                    $update_cashier_register_data['timezone'] = $prepaid_order['timezone'];
                    $update_cashier_register_data['hotel_name'] = $prepaid_order['hotel_name'];
                    $update_cashier_register_data['cashier_name'] = $prepaid_order['cashier_name'];
                    if ($channel_type == '10' || $channel_type == '11') {
                        $prepaid_order['museum_net_fee'] = $museum_net_fee;
                        $prepaid_order['hgs_net_fee'] = $hgs_net_fee;
                        $prepaid_order['distributor_net_fee'] = $distributor_net_fee;
                        $prepaid_order['museum_gross_fee'] = $museum_gross_fee;
                        $prepaid_order['hgs_gross_fee'] = $hgs_gross_fee;
                        $prepaid_order['distributor_gross_fee'] = $distributor_gross_fee;
                        $prepaid_order['merchant_admin_id'] = $refund_merchant_admin_id;
                    }
                    $secondary_prepaid_data = $prepaid_order;  // prepare array for insert in secondary DB.     
                    $secondary_prepaid_data['prepaid_ticket_id'] = $this->db->insert_id();
                    $this->secondarydb->db->insert($prepaid_table, $secondary_prepaid_data);
                    $logs['update_pt_db2_' . $i . '_' . date('H:i:s')] = $this->secondarydb->db->last_query();
                    // To update the data in RDS realtime
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                        $this->fourthdb->db->insert('prepaid_tickets', $secondary_prepaid_data);
                    }
                    $i++;
                }
                $update_cashier_register_data['price'] = $total_price;
                /*** Uninitialize values assigned
                $update_cashier_register_data['start_amount'] = $start_amount; ***/
                $update_cashier_register_data['start_amount'] = '0';
                $this->load->model('venue_model');
                /* DO NOT REMOVE THE CODE */
                // if ($channel_type == '10' || $channel_type == '11') {
                //     $this->venue_model->update_cashier_register_data_on_cancel($update_cashier_register_data);
                //     $logs['update_cashier_register_data_query_' . date('H:i:s')] = $this->db->last_query();
                // }
                
                $MPOS_LOGS['refund_prepaid_orders'] = $logs;
                $logs = array();
            }
        }

        /* EOC to insert refund entries in prepaid ticket table. */

        if (!empty($queue_data['order_payment_details'])) {
            if(!empty($queue_data['insert_paid_payment_detail_case'])){
                $this->order_process_model->add_payments($queue_data['order_payment_details'], 0 , $queue_data);
            }else{
                $this->order_process_model->add_payments($queue_data['order_payment_details']);
            }
            
            /* send webhook email notification when  refund payment only. */
            if (isset($queue_data['is_cancel_payment_only']) && !empty($queue_data['api_version_booking_email'])) {
               if (isset($queue_data['order_payment_details'][0])) {
                    $event_data['visitor_group_no'] = $queue_data['order_payment_details'][0]['visitor_group_no'];
                    $event_data['reseller_id'] = $queue_data['order_payment_details'][0]['reseller_id'];
                } else {
                    $event_data['visitor_group_no'] = $queue_data['order_payment_details']['visitor_group_no'];
                    $event_data['reseller_id'] = $queue_data['order_payment_details']['reseller_id'];
                }
                if ($event_data['reseller_id'] != EVAN_EVANS_RESELLER_ID) {
                    $event_data['event_name'] = 'PAYMENT_REFUND';
                    $event_data['hotel_id'] = !empty($queue_data['hotel_id']) ? $queue_data['hotel_id'] : '';
                    $webhook_email_notification_data = $event_data;
                }
            }
        }
        if (!empty($queue_data['update_order_payment_details'])) {
            if (!empty($queue_data['update_booking_order_payment_details'])) {
                $this->order_process_model->update_payments($queue_data['update_booking_order_payment_details']);
            } else {
                $this->order_process_model->update_payments($queue_data['update_order_payment_details']);
            }
            /* BOC payment create notification when payment created from 3.3  */
            if (isset($queue_data['api_version_booking_email']) && $queue_data['api_version_booking_email'] == 1
                    && $queue_data['update_order_payment_details']['reseller_id'] != EVAN_EVANS_RESELLER_ID) {
                $order_event_data['visitor_group_no'] = $event_data['visitor_group_no'] = $queue_data['update_order_payment_details']['visitor_group_no'];
                $event_data['event_name'] = 'PAYMENT_CREATE';
                if (!empty($queue_data['update_order_payment_details']['ticket_booking_id'])) {
                    $event_data['booking_references'] = array_map("trim", array_unique(explode(',', $queue_data['update_order_payment_details']['ticket_booking_id'])));
                }
                $event_data['hotel_id'] = !empty($queue_data['update_order_payment_details']['distributor_id']) ? $queue_data['update_order_payment_details']['distributor_id'] : '';
                $webhook_email_notification_data = $event_data;
                $order_event_data['event_name'] = 'ORDER_CREATE';
                /* EOC order cancel notification when call cancel order only from 3.3  */
                if (!empty($order_event_data)) {
                    $this->load->model('api_model');
                    $this->api_model->call_webhook_email_notification($order_event_data);
                }
                 /* EOC to get the data from pt,vt in case of cancellation from API's */
            }
            /* EOC payment create notification when payment created from 3.3  */
        }
        /* BOC If frp, SYX api */
        if (!empty($queue_data['visitor_tickets_id'])) {
            $this->secondarydb->db->select('transaction_id');
            $this->secondarydb->db->from('visitor_tickets');
            $this->secondarydb->db->where("id", $queue_data['visitor_tickets_id']);
            $result = $this->secondarydb->db->get();
            if ($result->num_rows() > 0) {
                $data = $result->row();
                $this->secondarydb->db->set('action_performed', "CONCAT(action_performed, ', API_SYX')", FALSE);
                $this->secondarydb->db->where('transaction_id', $data->transaction_id);
                $this->secondarydb->db->update('visitor_tickets', array("used" => "1", 'updated_at' => $queue_data['visitor_tickets_updated_at']));
            }
        }
        /* EOC If frp, SYX api */
        /* BOC to get the data from pt,vt and temp_analytics in case of cancellation from API's */
        if (!empty($queue_data['api_visitor_group_no']) && $queue_data['channel_name'] == 'API') {
            $request_id_data = $requested_data = $request_main_data = $arena_notifying_arr = [];
            $request_main_data = array(
                'visitor_group_no' => $queue_data['api_visitor_group_no'],
                'pt' => '1',
                'vt' => '1',
                'last_db1_prepaid_id' => isset($queue_data['last_db1_prepaid_id']) ? $queue_data['last_db1_prepaid_id'] : 0,
                'OTA_action' => !empty($queue_data['OTA_action']) ? $queue_data['OTA_action'] : '',
                'action' => !empty($queue_data['action']) ? $queue_data['action'] : '',
                'order_updated_cashier_id' => isset($queue_data['order_updated_cashier_id']) ? $queue_data['order_updated_cashier_id'] : '',
                'order_updated_cashier_name' => isset($queue_data['order_updated_cashier_name']) ? $queue_data['order_updated_cashier_name'] : '',
                'activation_method' => $queue_data['activation_method'],
                'hotel_id' => $queue_data['hotel_id'],
                'psp_reference' => isset($queue_data['psp_reference']) ? $queue_data['psp_reference'] : '',
                'psp_references' => isset($queue_data['psp_references']) ? $queue_data['psp_references'] : '',
                'partial_cancel_request' => isset($queue_data['partial_cancel_request']) ? $queue_data['partial_cancel_request'] : '',
                'is_activated_update' => isset($queue_data['is_activated_update']) ? $queue_data['is_activated_update'] : '',
                'order_refund_via_lambda_queue' => (isset($queue_data['order_refund_via_lambda_queue']) && ($queue_data['order_refund_via_lambda_queue'] == 1)) ? $queue_data['order_refund_via_lambda_queue'] : '',
                'is_full_cancelled' => isset($queue_data['is_full_cancelled']) ? $queue_data['is_full_cancelled'] : '',
                'is_cancel_payment_only' => isset($queue_data['is_cancel_payment_only']) ? $queue_data['is_cancel_payment_only'] : '0',
                'cancel_payment_update_pt' => isset($queue_data['cancel_payment_update_pt']) ? $queue_data['cancel_payment_update_pt'] : '0',
                'api_version_booking_email' => isset($queue_data['api_version_booking_email']) ? $queue_data['api_version_booking_email'] : 0,
                'per_ticket_booking_cancel' => isset($queue_data['per_ticket_booking_cancel']) ? $queue_data['per_ticket_booking_cancel'] : '',
                'partial_cancel_request' => isset($queue_data['partial_cancel_request']) ? $queue_data['partial_cancel_request'] : '',
                'ticket_update' => isset($queue_data['ticket_update']) ? $queue_data['ticket_update'] : 0,
                'is_static_cancel_call' => isset($queue_data['is_static_cancel_call']) ? $queue_data['is_static_cancel_call'] : 0,
                'shift_id' => isset($queue_data['shift_id']) ? $queue_data['shift_id'] : '',
                'pos_point_id' => isset($queue_data['pos_point_id']) ? $queue_data['pos_point_id'] : '',
                'pos_point_name' => isset($queue_data['pos_point_name']) ? $queue_data['pos_point_name'] : '',
                'cashier_register_id' => isset($queue_data['cashier_register_id']) ? $queue_data['cashier_register_id'] : '',
                'lambda_prepaid_ids' => isset($queue_data['lambda_prepaid_ids']) ? $queue_data['lambda_prepaid_ids'] : [],
                'lambda_skip_tp_call' => isset($queue_data['lambda_skip_tp_call']) ? $queue_data['lambda_skip_tp_call'] : [],
                'lambda_refund_cancel_call' => isset($queue_data['lambda_refund_cancel_call']) ? $queue_data['lambda_refund_cancel_call'] : [],
                'cancellation_reason' => isset($queue_data['cancellation_reason']) ? $queue_data['cancellation_reason'] : ''
            );
            if ((isset($queue_data['prepaid_ticket_ids']) && $queue_data['prepaid_ticket_ids'] != '') || (isset($queue_data['ticket_booking_id']) && $queue_data['ticket_booking_id'] != '')) {
                $request_id_data = array(
                    'prepaid_ticket_id' => !empty($queue_data['prepaid_ticket_ids']) ? $queue_data['prepaid_ticket_ids'] : '',
                    'ticket_booking_id' => !empty($queue_data['ticket_booking_id']) ? $queue_data['ticket_booking_id'] : '',
                    'action_from' => ($queue_data['action_from'] != '') ? $queue_data['action_from'] : ''
                );
            }

            /* check for arena notification */
            if (!empty($queue_data['notify_to_arena']) && $queue_data['notify_to_arena'] == '1') {
                $arena_notifying_arr['notify_to_arena'] = '1';
            }

            $requested_data = array_merge($request_main_data, $request_id_data, $arena_notifying_arr);
            //#76.2 : Implement Pay At Cashier
            $this->load->model('api_model');
            $is_update_pt = 0;
            $is_amend_order_call = 0;
            if (isset($queue_data['action_from']) && $queue_data['action_from'] != '' && isset($queue_data['ticket_update']) && $queue_data['ticket_update'] == 0) {
                $is_amend_order_call = 1;
            }
           // if ($queue_data['activation_method'] != 16) {
                $is_update_pt = 1;
            //}
            /* conditions for payment refund and cancel order from 3.2 */
            if ((isset($queue_data['is_cancel_payment_only']) && !isset($queue_data['cancel_payment_update_pt']))
                    || (isset($queue_data['is_cancel_order_only']) && $queue_data['is_cancel_order_only'] == 1)) {
                $is_update_pt = 0;
            } else if (isset($queue_data['is_cancel_payment_only']) && isset($queue_data['cancel_payment_update_pt'])) {
                $is_update_pt = 1;
            } else if (!empty($queue_data['is_payment_cancel_already']) && $queue_data['is_payment_cancel_already'] == 1) {
                $is_update_pt = 0;
            }
            $requested_data['cashier_type'] = $queue_data['cashier_type'] ?? 1;
            if ($is_update_pt == 1 || (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders']))) {
                $this->api_model->insert_refunded_orders($requested_data);
                 if(isset($queue_data['VENUE_TOPIC_ARN']))  {
                    include_once 'aws-php-sdk/aws-autoloader.php';
                    $this->load->library('Sns');
                    $request_string = json_encode($queue_data['VENUE_TOPIC_ARN']['data']);
                    $aws_message    = base64_encode(gzcompress($request_string));
                    //$aws_message = $queue_data['VENUE_TOPIC_ARN']['data'];
                    $queueUrl = QUEUE_URL;
                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    if (SERVER_ENVIRONMENT == 'Local') {
                        local_queue($aws_message, 'VENUE_TOPIC_ARN');
                    } else {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId !== false) {
                            $sns_object = new Sns();
                            $sns_object->publish($MessageId . '#~#' . $queueUrl, VENUE_TOPIC_ARN);
                        }
                    }
                }      

            }
            /*To handle notification for cancel order */
            if ($is_amend_order_call == 0 && $is_update_pt == 0 && isset($queue_data['is_full_cancelled'])) {
                $notify_VGN = $queue_data['api_visitor_group_no'];
                $notify_Event = 'ORDER_CANCEL';
            }
            $this->CreateLog('queue_data.php', 'vt_data', array('query' => json_encode($queue_data['api_visitor_group_no'])));
        }
        /** #Comment:- Handle Refund Case for Admin Refund */
        if (isset($queue_data['action']) && in_array($queue_data['action'], ['cancel_tickets','CRON_RFN'])) {
            $notify_VGN = $queue_data['visitor_group_no'] ?? '';
            $notify_Event = 'ORDER_CANCEL';
        }
        /* Handle case for offline bookings*/ 
        if ((isset($queue_data['action']) && in_array($queue_data['action'], ['third_party_lambda_order_sync','tp_voucher_release']))) {
            $notify_Event = $queue_data['lambda_event'] ?? '';
            $notify_VGN = $queue_data['visitor_group_no'] ?? '';
        }
        /* FOR TRAVELER VOUCHER NOTIFICATION UPDATE TO SPOS START */
        if (isset($queue_data['webhook_notification_data']) && !empty($queue_data['webhook_notification_data']) && !empty($queue_data['webhook_notification_data'])) {
            $this->load->model('api_model');
            foreach ($queue_data['webhook_notification_data'] as $event_data) {
                $this->api_model->call_webhook_email_notification($event_data);
                /*#COMMENT : Start Block for sync the  multiple orders coming in queue for voucher release  */
                if (!empty($queue_data['action']) && !empty($event_data['visitor_group_no']) && in_array($queue_data['action'], ['tp_voucher_release'])) {
                    $other_data = [];
                    $other_data['request_reference'] = $event_data['visitor_group_no'] ?? '';
                    $other_data['hotel_id'] = !empty($distributor_id) ? $distributor_id : (!empty($event_data['hotel_id']) ? $event_data['hotel_id'] : '');
                    $other_data['action'] = $queue_data['action'] ?? '';
                    $this->elastic_search->sync_data($event_data['visitor_group_no'], $other_data);
                }
                /*#COMMENT : End Block for sync the  multiple orders coming in queue for voucher release  */
            }
        }
        /* FOR TRAVELER VOUCHER NOTIFICATION UPDATE TO SPOS END */
        if (
            (
                (isset($queue_data['is_cancel_order_only']) && $queue_data['is_cancel_order_only'] == 1) || 
                ($queue_data['activation_method'] == 16 && $is_amend_order_call == 0) || 
                (isset($queue_data['is_payment_cancel_already']) && $queue_data['is_payment_cancel_already'] == 1)
            ) && 
            !empty($queue_data['api_version_booking_email']) && 
            isset($queue_data['reseller_id']) && 
            $queue_data['reseller_id'] != EVAN_EVANS_RESELLER_ID
        ) {
            
            $event_data['visitor_group_no'] = $queue_data['visitor_group_no'];
            $event_data['event_name'] = 'ORDER_CANCEL';
            if ((isset($queue_data['order_refund_via_lambda_queue']) && ($queue_data['order_refund_via_lambda_queue'] == 1))) {
                $event_data['event_name'] = "VOUCHER_RELEASE_FAILED";
            }
            if (!empty($queue_data['ticket_booking_id']) && isset($queue_data['is_full_cancelled']) &&  $queue_data['is_full_cancelled'] == 0) {
                $ticketbooking_id = str_replace('"', '', $queue_data['ticket_booking_id']);
                $event_data['booking_references'] = array_map("trim", array_unique(explode(',', $ticketbooking_id)));
            }
            $event_data['hotel_id'] = !empty($queue_data['hotel_id']) ? $queue_data['hotel_id'] : '';
            $this->load->model('api_model');
            $this->api_model->call_webhook_email_notification($event_data);
        }
        if(!empty($webhook_email_notification_data)){
            $this->load->model('api_model');
            $this->api_model->call_webhook_email_notification($event_data);
        }

        if(empty($distributor_id)) {  
            $distributor_id =!empty($event_data['hotel_id']) ? $event_data['hotel_id'] : '';
        }

        /* EOC to get the data from pt,vt and temp_analytics in case of cancellation from API's */

        /* BOC If visitor_tickets queries to cancel order exist in array then execute in Secondary DB */
        if (!empty($queue_data['update'])) {
            foreach ($queue_data['update'] as $where) {
                if (isset($where['updated_rows'])) {
                    $updated_rows = $where['updated_rows'];
                    unset($where['updated_rows']);
                } else {
                    $updated_rows = 0;
                }
                $this->secondarydb->db->select('distinct(transaction_id)');
                $this->secondarydb->db->from('visitor_tickets');
                $this->secondarydb->db->where($where);
                $this->secondarydb->db->where('deleted', '0');
                $this->secondarydb->db->where("transaction_type_name NOT LIKE 'Extra%'");
                if ($updated_rows > 0) {
                    $this->secondarydb->db->limit($updated_rows);
                }
                $result = $this->secondarydb->db->get();
                if ($result->num_rows() > 0) {
                    $data = $result->result();
                    $transaction_ids = array();
                    foreach ($data as $value) {
                        $transaction_ids[] = $value->transaction_id;
                    }
                    $this->secondarydb->db->where_in('transaction_id', $transaction_ids);
                    if ($queue_data['pos_type'] == 'cpos') {
                        $this->secondarydb->db->set('action_performed', "CONCAT(action_performed, ', CPOS_EOL')", FALSE);
                    }
                    $this->secondarydb->db->update('visitor_tickets', array('deleted' => '2', 'invoice_status' => '10', 'updated_at' => gmdate("Y-m-d H:i:s")));
                }
            }
        }
        /* EOC If visitor_tickets queries to cancel order exist in array then execute in Secondary DB */

        /* BOC If temp_analytic_records_where exist in array from update reservation then execute in Secondary DB */
        if (isset($queue_data['temp_analytic_records_where']) && !empty($queue_data['temp_analytic_records_where'])) {
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from('temp_analytic_records');
            $where = $queue_data['temp_analytic_records_where'];
            $this->secondarydb->db->where($where);
            $result = $this->secondarydb->db->get();
            if ($result->num_rows() > 0) {
                $ticket_data = $result->result_array();
                foreach ($ticket_data as $temp_data) {
                    if ($temp_data['status'] != 0) {
                        $this->secondarydb->db->update('temp_analytic_records', array('status' => 0, 'operation' => 'Refunded'), array('sale_id' => $temp_data['sale_id']));
                        $insert_array = $temp_data;
                        $insert_array['reservation_date'] = $data['selected_date'];
                        $insert_array['operation'] = 'Sale';
                        $insert_array['status'] = 0;
                        unset($insert_array['sale_id']);
                        $this->CreateLog('temp_analytic_records.php', 'master_overview/update_reservation_details', array("data" => $insert_array));
                        $this->secondarydb->db->insert('temp_analytic_records', $insert_array);
                    } else {
                        $this->secondarydb->db->update('temp_analytic_records', array('reservation_date' => $data['selected_date']), array('sale_id' => $temp_data['sale_id']));
                    }
                }
            }
        }
        /* EOC If visitor_tickets queries to cancel order exist in array then execute in Secondary DB */

        /* BOC When any data Insert with db type based in any table  */
        if (!empty($queue_data['insert'])) {
            foreach ($queue_data['insert']['db_type'] as $db_type) {
                $table_name = $queue_data['insert']['table_name'];
                $insert_batch = $queue_data['insert']['insert_batch'];
                $data = $queue_data['insert']['data'];
                if (!empty($data)) {
                    if ($db_type == 'DB1') {
                        if ($insert_batch == '1') {
                            $this->order_process_update_model->insert_batch($table_name, $data);
                        } else {
                            $this->db->insert($table_name, $data);
                        }
                    } else if ($db_type == 'DB2') {
                        if ($insert_batch == '1') {
                            $this->order_process_update_model->insert_batch($table_name, $data, '1');
                        } else {
                            $this->secondarydb->db->insert($table_name, $data);
                        }
                    }
                }
            }
        }
        /* EOC When  any data Insert with db type bases in any table  */

        /* BOC When UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */
        if (!empty($queue_data['update_visitor_tickets_for_refund_order'])) {
            $this->load->model('firebase_model');
            $this->firebase_model->update_visitor_for_cancel_orders($queue_data['update_visitor_tickets_for_refund_order']);
        }
        /* EOC UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */

        /* BOC When UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */
        if (!empty($queue_data['update_visitor_tickets_for_partial_refund_order'])) {
            $this->load->model('firebase_model');
            $this->firebase_model->update_visitor_for_partial_cancel_orders($queue_data['update_visitor_tickets_for_partial_refund_order']);
        }
        /* EOC UPDATE IN DB FOR CANCEL ORDER FROM FIREBASE .SNS HIT FROM FIREBASE CANCEL API */

        /* BOC to update temp_analytic_records for MPOS. */
        if (isset($queue_data['update_temp_analytic_records_for_mpos']) && !empty($queue_data['update_temp_analytic_records_for_mpos'])) {
            $this->load->model('firebase_model');
            $this->firebase_model->update_temp_table_for_mpos_orders($queue_data['update_temp_analytic_records_for_mpos']);
        }
        /* EOC to update temp_analytic_records for MPOS. */

        /* BOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */
        if (!empty($queue_data['update_pre_assigned_records'])) {
            $this->load->model('venue_model');
            $this->venue_model->update_pre_assigned_records($queue_data['update_pre_assigned_records']);
        }
        /* EOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */

        /* BOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */
        if (!empty($queue_data['extended_linked_ticket_from_css_app'])) {
            $this->load->model('venue_model');
            $this->venue_model->extended_linked_ticket_from_css_app($queue_data['extended_linked_ticket_from_css_app']);
        }
        /* EOC When UPDATE IN DB QUEUE SNS HIT FROM CSS VENUE app confirm API */

        /* BOC When UPDATE IN DB QUEUE SNS HIT FROM OLD VENUE app to insert in secondry db */
        if (!empty($queue_data['update_preassigned_codes'])) {
            $hto_id = $queue_data['update_preassigned_codes'];
            $this->order_process_update_model->update_order_table($hto_id, 0, '1');
        }
        /* EOC When UPDATE IN DB QUEUE SNS HIT FROM OLD VENUE app confirm API */

        /* BOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
        if (!empty($queue_data['update_venue_confirmapi_status'])) {
            $request_data = $queue_data['update_venue_confirmapi_status'];
            $user_id = $request_data['user_id'];
            $user_name = $request_data['user_name'];
            $museum_id = $request_data['museum_id'];
            $ticket_id = $request_data['ticket_id'];
            $scanned_at = $request_data['scanned_at'];
            $visitor_group_no = $request_data['visitor_group_no'];
            $order_confirm_date = gmdate('Y-m-d H:i:s');
            $prepaid_query = 'update prepaid_tickets set used="1", booking_status="1", order_confirm_date=(CASE when (booking_status="0" and channel_type="2") then "' . $order_confirm_date . '" else created_date_time end), action_performed=CONCAT(action_performed, ", SCAN_PRE_CSS"), updated_at="' . gmdate("Y-m-d H:i:s") . '", museum_cashier_id="' . $user_id . '", museum_cashier_name="' . $user_name . '", scanned_at="' . $scanned_at . '" where visitor_group_no="' . $visitor_group_no . '" and ticket_id="' . $ticket_id . '" and museum_id="' . $museum_id . '" ';
            $visitor_query = 'update visitor_tickets set used="1", booking_status="1", order_confirm_date=(CASE when (booking_status="0" and channel_type="2") then "' . $order_confirm_date . '" else visit_date_time end), action_performed=CONCAT(action_performed, ", SCAN_PRE_CSS"), updated_at="' . gmdate("Y-m-d H:i:s") . '", updated_by_id="' . $user_id . '", updated_by_username="' . $user_name . '", visit_date="' . $scanned_at . '" where vt_group_no="' . $visitor_group_no . '" and ticketId="' . $ticket_id . '" ';
            $this->CreateLog('update_in_db_queries.php', 'response->groupchekin=>', array('queries ' => $prepaid_query . ' ~~~~~ ' . $visitor_query));
            $this->db->query($prepaid_query);
            $this->secondarydb->db->query($prepaid_query);
            $this->secondarydb->db->query($visitor_query);
        }
        /* EOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */

        /* BOC to insert confirmed enteries in VT table. */
        if (isset($queue_data['confirm_order']) && !empty($queue_data['confirm_order'])) {
            $hto_id = $pass_no = $prepaid_ticket_id = $ticket_id = $selected_date = $from_time = $to_time = '';
            $sns_message_pt = $queue_data['confirm_order']['sns_message_pt'];
            $sns_message_vt = $queue_data['confirm_order']['sns_message_vt'];
            if (isset($queue_data['confirm_order']['action_performed']) && !empty($queue_data['confirm_order']['action_performed'])) {
                $vt_group_no = $queue_data['confirm_order']['visitor_group_no'];
                if (!empty($queue_data['confirm_order']['pass_no'])) {
                    $pass_no = $queue_data['confirm_order']['pass_no'];
                }
                if (!empty($queue_data['confirm_order']['prepaid_ticket_id'])) {
                    $prepaid_ticket_id = $queue_data['confirm_order']['prepaid_ticket_id'];
                }
                $all_prepaid_ticket_ids = isset($queue_data['confirm_order']['prepaid_ticket_ids']) ? $queue_data['confirm_order']['prepaid_ticket_ids'] : '';
                if (!empty($queue_data['confirm_order']['hto_id'])) {
                    $hto_id = $queue_data['confirm_order']['hto_id'];
                }
                $action_performed = $queue_data['confirm_order']['action_performed'];
                if (!empty($queue_data['confirm_order']['ticket_id'])) {
                    $ticket_id = $queue_data['confirm_order']['ticket_id'];
                }
                if (!empty($queue_data['confirm_order']['selected_date'])) {
                    $selected_date = $queue_data['confirm_order']['selected_date'];
                }
                if (!empty($queue_data['confirm_order']['from_time'])) {
                    $from_time = $queue_data['confirm_order']['from_time'];
                }
                if (!empty($queue_data['confirm_order']['to_time'])) {
                    $to_time = $queue_data['confirm_order']['to_time'];
                }
            } else {
                $vt_group_no = $queue_data['confirm_order'];
            }

            $this->order_process_update_model->update_visitor_tickets_direct($vt_group_no, $pass_no, $prepaid_ticket_id, $hto_id, $action_performed, $sns_message_pt, $sns_message_vt, $ticket_id, '1', '', $all_prepaid_ticket_ids, 0, '', '', '', $selected_date, $from_time, $to_time);
        }
        /* EOC to insert confirmed enteries in VT table. */
        /*Handle payment api for notification */
        if ((!empty($queue_data['update_order_payment_details']['visitor_group_no']) || !empty($queue_data['update_order_payment_details'][0]['visitor_group_no']))  && isset($queue_data['is_send_webhook_notification']) && $queue_data['is_send_webhook_notification'] == 1 ) {
                $notify_Event = 'PAYMENT_CREATE';
                $notify_VGN = $queue_data['update_order_payment_details']['visitor_group_no'];
        }   
        if (!empty($queue_data['order_payment_details']) && isset($queue_data['is_cancel_payment_only'])) {
            $notify_Event = 'PAYMENT_REFUND';
            $notify_VGN = $queue_data['order_payment_details'][0]['visitor_group_no'];
        } 
        /* Handle in case of update booking */
        if (!empty($queue_data['IsUpdateBooking']) && $queue_data['IsUpdateBooking'] == '1' && !empty($queue_data['api_visitor_group_no'])) {
            $notify_Event = 'ORDER_UPDATE';
            $notify_VGN = $queue_data['api_visitor_group_no'];
        }
        /*Send notification Function */
        if (!empty($notify_VGN) && !empty($notify_Event)) {
            $this->load->model('notification_model');
            $notify_request['reference_id'] = $notify_VGN;
            $notify_request['event'] = [$notify_Event];
            $notify_request['cashier_type'] = $queue_data['cashier_type'] ?? 1;
            $this->notification_model->checkNotificationEventExist(array($notify_request));
        }

    }

    /* #endregion to update_in_db_direct on local.*/

    /* #region to insert data in VT  */
    /**
     * insert_in_vt_script
     * to insert the data in vt when required through script
     * @author Sudeepta Mukkherjee <sudeepta.intersoft@gmail.com>
     */
    function insert_in_vt_script($vgn = '' ,$db_type = '1') {
        $this->order_process_update_model->update_visitor_tickets_direct($vgn);
    }
    /* #endregion to insert data in VT */
    
    
    /* #region to prepare Promocode details for vt insertion  */

    /**
     * handle_promocode_vt_insetion
     * prepare data to insert in vt promocode details.
     * @author Neha <nehadev.aipl@gmail.com>
     */
    function handle_promocode_vt_insetion($queue_data) {
        $db2_insertion = [];
        $this->CreateLog('db2_insertion_data.php', 'handle_promocode_vt_insetion 1 =>', array('queue_data ' => json_encode($queue_data)));
        /* Handle case for make payment venue orders. */
        if (((!empty($queue_data['payment_with_venue_promocode']) && !empty($queue_data['discount_codes_details'])) || (!empty($queue_data['action_from']) && $queue_data['action_from'] == 'OAPI35_OP') || (!empty($queue_data['is_cancel_order_only']) && $queue_data['is_cancel_order_only'] == '1') || (!empty($queue_data['IsUpdateBooking']) && $queue_data['IsUpdateBooking'] == '1' && !empty($queue_data['update_booking_value']) && $queue_data['update_booking_value'] == 'OAPI32_UPD') || (!empty($queue_data['update_booking_value']) && $queue_data['update_booking_value'] == 'CAPI32_UPD')) && !empty($queue_data['visitor_group_no'])) {
            $vt_detail_for_promoce = [];
            foreach ($queue_data['db2_insertion'] as $array_keys) {
                if ($array_keys['table'] == 'visitor_tickets') {
                    $vt_detail_for_promoce = $array_keys;
                    break;
                }
            }
            $vt_max_version_data = $version_arr = $not_refunded_booking = $refunded_booking = $not_refunded_booking_other_version = $transaction_ids = [];
            $visitor_group_no = $queue_data['visitor_group_no'];
            if (!empty($vt_detail_for_promoce)) {
                if (!empty($queue_data['prepaid_ticket_ids']) && !empty($queue_data['ticket_booking_id'])) {
                    $vt_max_version_data = $this->secondarydb->db->select('transaction_id, row_type, is_refunded, version, id, ticket_booking_id')->from('visitor_tickets')->where('vt_group_no', $visitor_group_no)->get()->result_array();
                    $this->CreateLog('db2_insertion_data.php', 'handle_promocode_vt_insetion  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($vt_max_version_data)));
                    $row_types = array_unique(array_column($vt_max_version_data, 'row_type'));
                    $ticket_booking_ids = explode(',', $queue_data['ticket_booking_id']);
                    if (!empty($row_types)) {
                        $this->CreateLog('row_type_data.php', "row_types entries: " . $visitor_group_no, array(json_encode($row_types)));
                        $exploded_prepaid_ticket_ids = explode(',', $queue_data['prepaid_ticket_ids']);
                        foreach ($vt_max_version_data as $vt_table_transaction_data_single) {
                            if (in_array($vt_table_transaction_data_single['transaction_id'], $exploded_prepaid_ticket_ids) && !in_array($vt_table_transaction_data_single['version'], $version_arr)) {
                                $version_arr[] = $vt_table_transaction_data_single['version'];
                            }
                        }
                        foreach ($vt_max_version_data as $vt_table_transaction_data_single) {
                            if ($vt_table_transaction_data_single['is_refunded'] == '0' && in_array($vt_table_transaction_data_single['version'],$version_arr)) {
                                $not_refunded_booking[] = $vt_table_transaction_data_single['ticket_booking_id'];
                            }
                            if ($vt_table_transaction_data_single['is_refunded'] == '2') {
                                $refunded_booking[] = $vt_table_transaction_data_single['ticket_booking_id'];
                            }
                             if ($vt_table_transaction_data_single['is_refunded'] == '0' && !in_array($vt_table_transaction_data_single['version'],$version_arr)) {
                                $not_refunded_booking_other_version[] = $vt_table_transaction_data_single['ticket_booking_id'];
                            }
                        }
                        $this->CreateLog('db2_insertion_data.php', 'version_arr  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($version_arr)));
                        /* add row_type 12 refunded entries. */
                        if (in_array('12', $row_types)) {
                            $this->CreateLog('db2_insertion_data.php', 'not_refunded_booking  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($not_refunded_booking)));
                            $this->CreateLog('db2_insertion_data.php', 'refunded_booking  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($refunded_booking)));
                            $not_refunded_service_cost = array_values(array_diff(array_unique($not_refunded_booking), array_unique($refunded_booking)));
                            $not_refunded_service_cost = array_values(array_diff(array_unique($not_refunded_service_cost), array_unique($not_refunded_booking_other_version)));
                            $this->CreateLog('db2_insertion_data.php', 'not_refunded_service_cost  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($not_refunded_service_cost)));
                            $this->CreateLog('db2_insertion_data.php', 'ticket_booking_ids  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($ticket_booking_ids)));
                            if (count(array_unique($not_refunded_service_cost)) == count(array_unique($ticket_booking_ids))) {
                                foreach ($vt_max_version_data as $vt_table_transaction_data_single) {
                                    if (in_array($vt_table_transaction_data_single['version'], $version_arr) && $vt_table_transaction_data_single['row_type'] == '1') {
                                        if (!empty($queue_data['is_cancel_order_only'])) {
                                            $transaction_ids[] = $vt_table_transaction_data_single['id'];
                                        } else {
                                            $vt_detail_for_promoce['where'] = 'transaction_id = "' . $vt_table_transaction_data_single['id'] . '" and vt_group_no = "' . $visitor_group_no . '"';
                                            $db2_insertion[] = $vt_detail_for_promoce;
                                        }

                                        $this->CreateLog('db2_insertion_data.php', 'vt_detail_for_promoce  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($vt_detail_for_promoce)));
                                    }
                                }
                            }
                        }
                        /* add row_type 18 and 19 refunded entries. */
                        if (in_array('18', $row_types) || in_array('19', $row_types)) {
                            $is_refunded_zero = $is_refunded_one = $is_refunded_other_version = [];
                            foreach ($vt_max_version_data as $vt_table_transaction_data_single) {
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
                            $this->CreateLog('db2_insertion_data.php', 'is_refunded_zero  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($is_refunded_zero)));
                            $this->CreateLog('db2_insertion_data.php', 'is_refunded_one  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($is_refunded_one)));
                            $not_refunded_transaction_service_fees = array_values(array_diff($is_refunded_zero, $is_refunded_one));
                            $not_refunded_transaction_service_fees = array_values(array_diff($not_refunded_transaction_service_fees, $is_refunded_other_version));

                            $this->CreateLog('db2_insertion_data.php', 'not_refunded_transaction_service_fees  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($not_refunded_transaction_service_fees)));
                            for ($i = 0; $i < count($ticket_booking_ids); $i++) {
                                if (isset($not_refunded_transaction_service_fees[$i])) {
                                    if (!empty($queue_data['is_cancel_order_only'])) {
                                        $transaction_ids[] = $not_refunded_transaction_service_fees[$i];
                                    } else {
                                        $vt_detail_for_promoce['where'] = 'transaction_id = "' . $not_refunded_transaction_service_fees[$i] . '" and vt_group_no = "' . $visitor_group_no . '"';
                                        $db2_insertion[] = $vt_detail_for_promoce;
                                    }

                                    $this->CreateLog('db2_insertion_data.php', 'vt_detail_for_promoce  vt_max_version_data 1 =>', array('vt_max_version_data ' => json_encode($vt_detail_for_promoce)));
                                }
                            }
                        }
                        if(!empty($transaction_ids) && !empty($queue_data['without_transaction_where_vt_query'])){
                             $vt_detail_for_promoce['where'] = $queue_data['without_transaction_where_vt_query'].' and  transaction_id in ("' . implode('", "', $transaction_ids) . '")';
                             $db2_insertion[] = $vt_detail_for_promoce;
                        }
                    }
                }
                if (!empty($queue_data['payment_with_venue_promocode']) && !empty($queue_data['discount_codes_details'])) {

                    $promocode_count = count($queue_data['discount_codes_details']);
                    if (empty($vt_max_version_data)) {
                        $vt_max_version_data = $this->secondarydb->db->select('max(version) as version, transaction_id')->from('visitor_tickets')->where('vt_group_no', $visitor_group_no)->group_by("transaction_id")->get()->result_array();
                    }
                    $vt_max_version = max(array_column($vt_max_version_data, 'version'));
                    $vt_table_transaction_ids = array_unique(array_column($vt_table_transaction_data, 'transaction_id'));
                    $last_promo_entry_no_exist = ($promocode_count * $vt_max_version);
                    $last_promo_entry_no = ($promocode_count * $vt_max_version) - $promocode_count;
                    $transction_check_start = 900 + $last_promo_entry_no;
                    for ($j = $last_promo_entry_no_exist; $j > 0; $j--) {
                        $old_promo_id = 900 + $j;
                        $transaction_to_check = $visitor_group_no . $old_promo_id;
                        if (in_array($transaction_to_check, $vt_table_transaction_ids)) {
                            $transction_check_start = $old_promo_id - $promocode_count;
                            break;
                        }
                    }
                    for ($i = 1; $i <= $promocode_count; $i++) {
                        $new_promo_id = $transction_check_start + $i;
                        $promo_transaction_id = $visitor_group_no . $new_promo_id;
                        $vt_detail_for_promoce['where'] = 'transaction_id = "' . $promo_transaction_id . '" and vt_group_no = "' . $visitor_group_no . '"';
                        $db2_insertion[] = $vt_detail_for_promoce;
                    }
                }
            }
            $this->CreateLog('discount_refunded_data.php', "queue_data['db2_insertion'] entries: " . $visitor_group_no, array(json_encode($queue_data['db2_insertion'])));
        }
        return $db2_insertion;
    }
    /* #endregion to prepare Promocode details for vt insertion  */
}

?>
