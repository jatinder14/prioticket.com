<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Scanning_action extends MY_Controller
{

    var $base_url;

    function __construct()
    {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('pos_model');
        $this->load->model('firebase_model');
        $this->load->model('order_process_update_model');
        $this->load->model('venue_model');
        $this->load->library('log_library');
        $this->load->library('elastic_search');
        $this->load->library('purgefastly');
        // $this->load->library('trans');
    }

    /**
     * @name    : update_actions()     
     * @Purpose : To update prepaid and visitor updation from time of reddem action
     * @params  : No parameter required
     * @return  : nothing
     * @created by: Taranjeet singh <taran.intersoft@gmail.com> on July, 2018
     */
    function db_update_actions($fifo = 1)
    {
        global $MPOS_LOGS;
        $deleted_data_from_queue = $OTHERS_LOGS = array();
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        
        try {
            $spcl_ref = '';
            // Queue process check
            if (STOP_QUEUE) {
                exit();
            }
            
            $postBody = file_get_contents('php://input');
            $message = json_decode($postBody, true);
            $message_id = $message['Message'];
            $queue_url = '';
            if ($message_id != '') {
                $message_id_data = explode('#~#', $message_id);
                $queue_url = $message_id_data[1];
            }
            if (!empty($queue_url)) {
                $queueUrl = $queue_url;
            } else if ($fifo == 1) { //to hit fifo queue
                $queueUrl = SCANING_ACTION_URL;
                $MPOS_LOGS['queue'] = "SCANING_ACTION_URL";
                // $this->trans->begin();
            } else { //to hit standard queue => this will be used to process stucked records in standard queue
                $queueUrl = SCANING_ACTION_URL_STANDARD;
                $MPOS_LOGS['queue'] = "SCANING_ACTION_URL_STANDARD";
            }
            if (SERVER_ENVIRONMENT == 'Local') {
                $postBody = file_get_contents('php://input');
                $messages = array(
                    'Body' => $postBody
                );
            } else {
                $request_headers = getallheaders();
                if(SERVER_ENVIRONMENT != 'Local' && !isset($request_headers['X-Amz-Sns-Topic-Arn']) ||  !in_array($request_headers['X-Amz-Sns-Topic-Arn'], [SCANING_ACTION_ARN, API_SUPPLIER_REDEMPTION_ARN])) {
                    $this->output->set_header('HTTP/1.1 401 Unauthorized');
                    $this->output->set_status_header(401, "Unauthorized");
                    exit;
                }
                /* It receive message from given queue. */
                $messages = $sqs_object->receiveMessage($queueUrl);
                $messages = $messages->getPath('Messages');
            }
            //To update pending scan records 
            $this->primarydb->db->select("*");
            $this->primarydb->db->from('firebase_pending_request');
            $this->primarydb->db->where("request_type =", "scaning_actions");
            $this->primarydb->db->where("added_at <=", date('Y-m-d H:i:s', strtotime('-1 hour')));
            $firebase_pending_request = $this->primarydb->db->get()->result_array();
            $logs['firebase_pending_orders_query_' .  date("H:i:s")] = $this->primarydb->db->last_query();
            if (!empty($firebase_pending_request)) {
                $logs['firebase_pending_orders'] = $firebase_pending_request;
                foreach ($firebase_pending_request as $key => $pending_request) {
                    $request = json_decode($pending_request['request'], true);
                    if (!empty($request['update_db2_query'])) {
                        foreach ($request['update_db2_query'] as $query) {
                            $query = trim($query);
                            $this->secondarydb->db->query($query);
                        }
                    }
                    if (!empty($request['update_DB2_query'])) {
                        foreach ($request['update_DB2_query'] as $query) {
                            $query = trim($query);
                            $this->secondarydb->db->query($query);
                        }
                    }
                    if (!empty($request['update_db4_query'])) {
                        foreach ($request['update_db4_query'] as $query) {
                            $query = trim($query);
                            $this->fourthdb->db->query($query);
                        }
                    }
                    if (!empty($request['update_DB4_query'])) {
                        foreach ($request['update_DB4_query'] as $query) {
                            $query = trim($query);
                            $this->fourthdb->db->query($query);
                        }
                    }
                    if (!empty($request['insert_db2_db4'])) {
                        foreach ($request['insert_db2_db4'] as $array_keys) {
                            if(!empty($array_keys['table']) && !empty($array_keys['columns']) && !empty($array_keys['where'])) {
                                $this->venue_model->set_insert_queries($array_keys);
                                $logs[$key . '_insert_db2_db4->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                                unset($MPOS_LOGS['get_insert_queries']);
                                $logs[$key . '_insert_db2_db4->db2_vt_update_' . date('H:i:s')] = $this->secondarydb->db->last_query();
                                $logs[$key . '_insert_db2_db4->db4_vt_update_' . date('H:i:s')] = $this->fourthdb->db->last_query();
                            }
                        }
                    }
                    
                    if (!empty($request['vt_db2'])) {
                        $result = $this->secondarydb->db->query($request['vt_db2']['fetch_vt_data'])->result_array();
                        $result = $this->venue_model->get_max_version_data($result, 'id');
                        $transaction_ids = array();
                        foreach ($result as $visitors_data) {
                            $transaction_ids[] = (int) $visitors_data['transaction_id'];
                        }
                        if (!empty($transaction_ids)) {
                            $request['vt_db2']['update_query']['where'] = str_replace("transaction_id IN () ", "transaction_id IN (" . implode(',', $transaction_ids) . ") ", $request['vt_db2']['update_query']['where']);
                            $this->venue_model->set_insert_queries($request['vt_db2']['update_query']);
                            $logs[$key . '_vt_db2->set_insert_queries'] = $MPOS_LOGS['get_insert_queries'];
                            unset($MPOS_LOGS['get_insert_queries']);
                            $logs[$key . '_vt_db2->db2_vt_update_' . date('H:i:s')] = $last_query = $this->secondarydb->db->last_query();
                            $logs[$key . '_vt_db2->db4_vt_update_' . date('H:i:s')] = $last_query = $this->fourthdb->db->last_query();
                        }
                    }
                    $delete_where = array(
                        'id' => $pending_request['id'],
                        'request_type' => 'scaning_actions'
                    );
                    $this->db->delete('firebase_pending_request', $delete_where);
                    $logs[$key . '_firebase_pending_orders_query_' . $key] = $this->db->last_query();
                }
            }
            if (!empty($logs)) {
                $MPOS_LOGS['firebase_pending_orders'] = $logs;
            }
            if (!empty($messages)) {
                foreach ($messages as $i => $message) {
                    $MPOS_LOGS_DATA = $add_to_pending_req = $logs = array();
                    if (SERVER_ENVIRONMENT != 'Local') {
                        /* BOC It remove message from SQS queue for next entry. */
                        $logs['delete_msg_init_for_'.$i.'_' .  strtotime(date('Y-m-d H:i:s'))] = date('Y-m-d H:i:s');
                        $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                        $logs['delete_msg_done_for_'.$i.'_' .  strtotime(date('Y-m-d H:i:s'))] = date('Y-m-d H:i:s');
                        /* EOC It remove message from SQS queue for next entry. */
                        $string = $message['Body'];
                    } else {
                        $string = $message;
                    }
                    
                    $action_already_updated_in_pt_BG = 0;
                    $deleted_data_from_queue[] = $message;
                    /* BOC extract and convert data in array from queue */
                    $string = gzuncompress(base64_decode($string));
                    $string = utf8_decode($string);
                    $queue_data = json_decode($string, true);
                    $queue_channel_type = $queue_data['channel_type'];
                    $queue_vgn = $queue_data['visitor_group_no'];
                    $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_data_inside_funcction_Call_queue_data'.date("H:i:s.u"), array('queue_data' => json_encode($queue_data)), $queue_channel_type);  
                    /* BOC If DB! query exist in array then execute in Primary DB */
                    if (!empty($queue_data['db1'])) {
                        $i = 0;
                        foreach ($queue_data['db1'] as $query) {
                            $query = trim($query);
                            $this->db->query($query);
                            $logs['db1_update_' . $i] = $this->db->last_query();
                            $i++;
                        }
                    }
                    if (!empty($queue_data['DB1'])) {
                        $i = 0;
                        foreach ($queue_data['DB1'] as $query) {
                            $query = trim($query);
                            $this->db->query($query);
                            $logs['DB1_update_' . $i] = $this->db->last_query();
                            $i++;
                        }
                    }
                    /* EOC If DB1 queries exist in array then execute in Primary DB */

                    /* BOC If DB2 queries exist in array then execute in Secondary DB */
                    if(!empty($queue_data['db2_insertion'])) {
                        $i = 0;
                        foreach ($queue_data['db2_insertion'] as $array_keys) {
                            if(!empty($array_keys['table']) && !empty($array_keys['columns']) && !empty($array_keys['where'])) {
                                $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_for_each_array_keys'.date("H:i:s.u"), array('array_keys' => json_encode($array_keys)), $queue_channel_type);  
                                $data_updated = $this->venue_model->set_insert_queries($array_keys);
                                $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_for_each_data_updated'.date("H:i:s.u"), array('data_updated' => json_encode($data_updated)), $queue_channel_type); 
                                if(!($data_updated)) { //no data found for this transaction IN DB so adding these to pending req
                                    $add_to_pending_req['insert_db2_db4'][] = $array_keys;
                                }
                            }
                            $logs['set_insert_queries_'.$i] = $MPOS_LOGS['get_insert_queries'];
                            unset($MPOS_LOGS['get_insert_queries']);
                            $i++;
                            
                            if($queue_data['sub_transactions'] == 1 && $fifo == 1) {
                                // $this->trans->close();
                                // $this->trans->begin();
                            }
                        }
                    }
                    if (!empty($queue_data['db2'])) {
                        $i = 0;
                        foreach ($queue_data['db2'] as $query) {
                            $query = trim($query);
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_queue_data_db2'.date("H:i:s.u"), array('query' => json_encode($query)), $queue_channel_type); 
                            $this->secondarydb->db->query($query);
                            if ($this->secondarydb->db->affected_rows() <= 0) {
                                $add_to_pending_req['update_db2_query'][] = $query;
                            }
                            $logs['db2_update_' . $i] = $this->secondarydb->db->last_query();
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'db2_update_'.date("H:i:s.u"), array('query' => json_encode($query)), $queue_channel_type); 
                            // To update the data in RDS realtime
                            if (($queue_data['update_action'] == 'mark_guest_as_entered' && (strstr($query, 'prepaid_tickets') || strstr($query, 'visitor_tickets')) || strstr($query, 'hotel_ticket_overview'))
                             && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                                $this->fourthdb->db->query($query);
                                if ($this->fourthdb->db->affected_rows() <= 0) {
                                    $add_to_pending_req['update_db4_query'][] = $query;
                                }
                                $logs['db4_update_' . $i] = $this->fourthdb->db->last_query();
                            }
                            $i++;
                        }
                    }
                    
                    if (!empty($queue_data['DB2'])) {
                        $i = 0;
                        foreach ($queue_data['DB2'] as $query) {
                            $query = trim($query);
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_queue_data_DB2_lower'.date("H:i:s.u"), array('query' => json_encode($query)), $queue_channel_type); 
                            $this->secondarydb->db->query($query);
                            if ($this->secondarydb->db->affected_rows() <= 0) {
                                $add_to_pending_req['update_DB2_query'] = $query;
                            }
                            $logs['DB2_update_' . $i] = $this->secondarydb->db->last_query();
                            // To update the data in RDS realtime
                            if (($queue_data['update_action'] == 'mark_guest_as_entered' && (strstr($query, 'prepaid_tickets') || strstr($query, 'visitor_tickets')) || strstr($query, 'hotel_ticket_overview'))
                             && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                                $this->fourthdb->db->query($query);
                                if ($this->fourthdb->db->affected_rows() <= 0) {
                                    $add_to_pending_req['update_DB4_query'][] = $query;
                                }
                                $logs['DB4_update_' . $i] = $this->fourthdb->db->last_query();
                            }
                            $i++;
                        }
                    }

                    if (!empty($logs)) {
                        $MPOS_LOGS_DATA['db_updates'] = $logs;
                        $logs = array();
                    }
                    /* BOC to insert confirmed enteries in VT table. */
                    if (isset($queue_data['confirm_order']) && !empty($queue_data['confirm_order'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_confirm_order'.date("H:i:s.u"), array('confirm_order' => json_encode($queue_data['confirm_order'])), $queue_channel_type); 
                        $vt_group_no = isset($queue_data['confirm_order']['visitor_group_no']) ? $queue_data['confirm_order']['visitor_group_no'] : $queue_data['visitor_group_no'];
                        $multiple_visitor_group_no = $queue_data['confirm_order']['multiple_visitor_group_no'];
                        $pass_no = $queue_data['confirm_order']['pass_no'];
                        $prepaid_ticket_id = $queue_data['confirm_order']['prepaid_ticket_id'];
                        $order_confirm_date = $queue_data['confirm_order']['order_confirm_date'];
                        $ticket_id = $queue_data['confirm_order']['ticket_id'];
                        $hto_id = $queue_data['confirm_order']['hto_id'];
                        $action_performed = $queue_data['confirm_order']['action_performed'];
                        $scanned_at = $queue_data['confirm_order']['scanned_at'];
                        $all_prepaid_ticket_ids = $queue_data['confirm_order']['all_prepaid_ticket_ids'];
                        $sns_message_pt = $queue_data['confirm_order']['sns_message_pt'];
                        $sns_message_vt = $queue_data['confirm_order']['sns_message_vt'];
                        $voucher_updated_by = isset($queue_data['confirm_order']['voucher_updated_by']) ? $queue_data['confirm_order']['voucher_updated_by'] : 0;
                        $voucher_updated_by_name = isset($queue_data['confirm_order']['voucher_updated_by_name']) ? $queue_data['confirm_order']['voucher_updated_by_name'] : '';
                        $hto_update_for_card = !empty($queue_data['confirm_order']['hto_update_for_card']) ? $queue_data['confirm_order']['hto_update_for_card'] : array();
                        $this->log_library->write_log($queue_data['confirm_order'], 'mainLog', 'scanning_action_arn');
                        $this->order_process_update_model->update_visitor_tickets_direct($vt_group_no, $pass_no, $prepaid_ticket_id, $hto_id, $action_performed, $sns_message_pt, $sns_message_vt, $ticket_id, 1, $order_confirm_date, $all_prepaid_ticket_ids, $voucher_updated_by, $voucher_updated_by_name, $scanned_at, '', '', '', '', $multiple_visitor_group_no, $hto_update_for_card);

                        if (SERVER_ENVIRONMENT != 'Local' && (BigqueryConstants::SYNC_BQ == 1) && !empty($queue_data['action']) && isset($queue_data['visitor_group_no'])) {
                            $action_already_updated_in_pt_BG = 1;
                            /**code for bigquery aggvt day table */
			                $logs['action_in_confirm_order_'.$queue_data['action'].'_'.$queue_data['visitor_group_no']] = $queue_data['action'].'_'.$queue_data['visitor_group_no'];
                            $final_visitor_data_to_insert_big_query_transaction_specific = $this->secondarydb->db->query('select activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id from visitor_tickets vt1 where vt_group_no ="' . $queue_data['visitor_group_no'] . '" and version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.vt_group_no ="' . $queue_data['visitor_group_no'] . '") ')->result_array();
                            $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress(json_encode($final_visitor_data_to_insert_big_query_transaction_specific)));
                            $logs['data_to_queue_AGG_VT_INSERT_QUEUEURL_' . date("H:i:s")] = $final_visitor_data_to_insert_big_query_compressed;
                            
                            $results = $this->secondarydb->db->query('select * from prepaid_tickets pt1 where visitor_group_no ="' . $queue_data['visitor_group_no'] . '" and version = (select max(version) from prepaid_tickets pt2 where pt2.prepaid_ticket_id = pt1.prepaid_ticket_id and pt1.visitor_group_no ="' . $queue_data['visitor_group_no'] . '") ')->result();
                            $aws_message2 = base64_encode(gzcompress(json_encode($results)));
                            $logs['data_to_queue_LIVE_SCAN_REPORT_QUEUE_URL_' . date("H:i:s")] = $aws_message2;
                            
                            if (SERVER_ENVIRONMENT == 'Local') {
                                local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
                            } else {
                                $agg_vt_insert_queueUrl = AGG_VT_INSERT_QUEUEURL;
                                // This Fn used to send notification with data on AWS panel. 
                                $MessageId = $sqs_object->sendMessage($agg_vt_insert_queueUrl, $final_visitor_data_to_insert_big_query_compressed);
                                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                $err = '';
                                if ($MessageId != false) {
                                    $err = $sns_object->publish($agg_vt_insert_queueUrl, AGG_VT_INSERT_ARN);
                                }
                                
                                $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                                if ($MessageIdss != false) {
                                    $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                                }

                                /* Select data and sync on BQ */
                                $this->order_process_model->getVtDataToSyncOnBQ($queue_data['visitor_group_no']);
                            }
                            /**code for aggvt day table end here */
                        }
                        if((!isset($queue_data['order_payment_details']) || empty($queue_data['order_payment_details'])) && ($vt_group_no != null || $vt_group_no != "")) {
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_order_payment_details'.date("H:i:s.u"), array('order_payment_details' => json_encode($queue_data['order_payment_details'])), $queue_channel_type); 
                            $payment_details_for_bs0 =  array(
                                'visitor_group_no' => $vt_group_no,
                                "psp_type" => 15, // none
                                "status" => 1, //paid
                                "method" => 2, //BS 0 confirms with cash only
                                "type" => 1, //captured payment
                                "settlement_type"=> 2, // venue
                                "settled_on" => date("Y-m-d H:i:s"),
                                "cashier_id" => isset($queue_data['confirm_order']['voucher_updated_by']) ? $queue_data['confirm_order']['voucher_updated_by'] : 0,
                                "cashier_name" => isset($queue_data['confirm_order']['voucher_updated_by_name']) ? $queue_data['confirm_order']['voucher_updated_by_name'] : '',
                                "updated_at"=> date("Y-m-d H:i:s"),
                                "created_at"=> date("Y-m-d H:i:s")
                            );
                            if(isset($queue_data['confirm_order']['reseller_id']) && $queue_data['confirm_order']['reseller_id'] != "" && $queue_data['confirm_order']['reseller_id'] != NULL) {
                                $payment_details_for_bs0['reseller_id'] = $queue_data['confirm_order']['reseller_id'];
                            }
                            if(isset($queue_data['confirm_order']['price']) && $queue_data['confirm_order']['price'] != "" && $queue_data['confirm_order']['price'] != NULL) {
                                $payment_details_for_bs0['total'] = $queue_data['confirm_order']['price'];
                                $payment_details_for_bs0['amount'] = $queue_data['confirm_order']['price'];
                            }
                            if(isset($queue_data['confirm_order']['dist_id']) && $queue_data['confirm_order']['dist_id'] != "" && $queue_data['confirm_order']['dist_id'] != NULL) {
                                $payment_details_for_bs0['distributor_id'] = $queue_data['confirm_order']['dist_id'];
                            }
                            $logs['data_for_order_paymnet_details'] = $payment_details_for_bs0;
                            $this->pos_model->update_payment_detaills($payment_details_for_bs0);
                        } else {
                            $logs['data_for_order_paymnet_details'] = "No updations from BS 0 end";
                        }
                        if (!empty($logs)) {
                            $MPOS_LOGS_DATA['confirm_order'] = $logs;
                            $logs = array();
                        }
                    }
                    /* EOC to insert confirmed enteries in VT table. */

                    /* BOC If frp, SYX api */
                    if (!empty($queue_data['visitor_tickets_id'])) {
                        $this->secondarydb->rodb->select('transaction_id');
                        $this->secondarydb->rodb->from('visitor_tickets');
                        $this->secondarydb->rodb->where("id", $queue_data['visitor_tickets_id']);
                        $result = $this->secondarydb->rodb->get();
                        $logs['get_from_vt'] = $this->secondarydb->rodb->last_query();
                        if ($result->num_rows() > 0) {
                            $data = $result->row();
                            /* check if distributor is added in constant for version table insertion */
                            $updateCols = array("used" => '1', 
                                                "visit_date" => $queue_data['visit_date'], 
                                                "updated_at" => $queue_data['visitor_tickets_updated_at'], 
                                                "CONCAT_VALUE" => array("action_performed" => ', API_SYX'));
                            $this->venue_model->set_insert_queries(array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => ' transaction_id = "'.$data->transaction_id.'"', "redeem" => 1));
                            $logs['visitor_tickets_id->set_insert_queries'] = $MPOS_LOGS['get_insert_queries'];
                            unset($MPOS_LOGS['get_insert_queries']);
                            $logs['update_vt_db2_'] = $this->secondarydb->db->last_query();
                            $logs['update_vt_db4_'] = $this->fourthdb->db->last_query();
                            $MPOS_LOGS_DATA['visitor_tickets_id_action'] = $logs;
                            $logs = array();
                        }
                    }
                    /* EOC If frp, SYX api */

                    /* BOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
                    if (!empty($queue_data['update_redeem_table'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_update_redeem_table'.date("H:i:s.u"), array('update_redeem_table' => json_encode($queue_data['update_redeem_table'])), $queue_channel_type); 
                        $this->venue_model->update_redeem_table($queue_data['update_redeem_table']);
                        if (isset($MPOS_LOGS['update_redeem_table'])) {
                            $MPOS_LOGS_DATA['update_redeem_table'] = $MPOS_LOGS['update_redeem_table'];
                            $logs = array();
                            unset($MPOS_LOGS['update_redeem_table']);
                        }
                    }

                    /* BOC If vt_db2 exist in array then execute in Secondary AND FOURTH DB IN VT TABLE */
                    if (isset($queue_data['vt_db2']) && !empty($queue_data['vt_db2'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_vt_db2'.date("H:i:s.u"), array('vt_db2' => json_encode($queue_data['vt_db2'])), $queue_channel_type); 
                        $vt_table_name = 'visitor_tickets';
                        $logs = array();
                        $ticketid = '';
                        $visitor_tickets_id = (isset($queue_data['vt_db2']['visitor_tickets_id']) && !empty($queue_data['vt_db2']['visitor_tickets_id'])) ? $queue_data['vt_db2']['visitor_tickets_id'] : array();
                        $visitor_group_no = $queue_data['vt_db2']['visitor_group_no'];
                        $museum_id = (isset($queue_data['vt_db2']['museum_id']) && $queue_data['vt_db2']['museum_id'] > 0) ? $queue_data['vt_db2']['museum_id'] : 0;
                        $clustering_id = (isset($queue_data['vt_db2']['clustering_id']) && $queue_data['vt_db2']['clustering_id'] != '' && $queue_data['vt_db2']['clustering_id'] != '0') ? $queue_data['vt_db2']['clustering_id'] : '';
                        $action_performed = $queue_data['vt_db2']['action_performed'];
                        $pass_no = $queue_data['vt_db2']['passNo'];
                        $bleep_pass_no = $queue_data['vt_db2']['bleep_pass_no'];
                        $update_on_pass_no = $queue_data['vt_db2']['update_on_pass_no'];
                        $update_on_bleep_pass_no = $queue_data['vt_db2']['update_on_bleep_pass_no'];
                        $from_redeem_group_tickets = (isset($queue_data['vt_db2']['redeem_group_tickets']) && $queue_data['vt_db2']['redeem_group_tickets'] == "1") ? "1" : "0";
                        if (isset($queue_data['vt_db2']['redeem_group_tickets']) && $queue_data['vt_db2']['redeem_group_tickets'] == "1" && !empty($visitor_tickets_id)) {
                            $query = 'select * from visitor_tickets where vt_group_no = "' . $visitor_group_no . '" and id IN (' . implode(',', $visitor_tickets_id) . ') and  (row_type = "1" or row_type = "2") and invoice_status not in("10") and is_refunded !="1" and ticket_status = "1" and deleted="0" and (transaction_type_name = "Ticket cost" or transaction_type_name = "General sales" )';
                        } else {
                            $query = 'select * from visitor_tickets where vt_group_no = "' . $visitor_group_no . '" and (row_type = "1" or row_type = "2") and invoice_status not in("10") and is_refunded !="1" and ticket_status = "1" and deleted="0" and (transaction_type_name = "Ticket cost" or transaction_type_name = "General sales" )';
                        }
                        $result = $this->secondarydb->db->query($query)->result_array();
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_vt_db2_result'.date("H:i:s.u"), array('vt_db2' => json_encode($result)), $queue_channel_type); 
                        $logs['fetch_vt_data'] = $fetch_vt_data = $this->secondarydb->db->last_query();

                        $transaction_ids = array();
                        $result = $this->venue_model->get_max_version_data($result, 'id');
                        foreach ($result as $visitors_data) {
                            if($from_redeem_group_tickets) {
                                $transaction_ids[] = (int) $visitors_data['transaction_id'];
                            } else if (!empty($visitor_tickets_id)){
                                if (in_array($visitors_data['id'], $visitor_tickets_id)) {
                                    $transaction_ids[] = $visitors_data['transaction_id'];
                                }
                            } else {
                                if (strstr($visitors_data['passNo'], $pass_no)) {
                                    $transaction_ids[] = $visitors_data['transaction_id'];
                                }
                            }
                        }
                        if ($queue_data['vt_db2']['check_ticket_id'] == '1') {
                            $ticketid = isset($queue_data['vt_db2']['ticket_id']) ? $queue_data['vt_db2']['ticket_id'] : '';
                        }
                        $check_activated = (($queue_data['vt_db2']['check_activated']) ? ' and ticket_status = "1" ' : '');
                        $redeem_main_ticket_only = ($queue_data['vt_db2']['redeem_main_ticket_only']) ? 1 : 0;
                        
                        unset($queue_data['vt_db2']['passNo']);
                        unset($queue_data['vt_db2']['bleep_pass_no']);
                        unset($queue_data['vt_db2']['update_on_pass_no']);
                        unset($queue_data['vt_db2']['update_on_bleep_pass_no']);
                        unset($queue_data['vt_db2']['action_performed']);
                        unset($queue_data['vt_db2']['redeem_group_tickets']);
                        unset($queue_data['vt_db2']['ticket_id']);
                        unset($queue_data['vt_db2']['clustering_id']);
                        unset($queue_data['vt_db2']['museum_id']);
                        unset($queue_data['vt_db2']['check_ticket_id']);
                        unset($queue_data['vt_db2']['where']);
                        unset($queue_data['vt_db2']['visitor_group_no']);
                        unset($queue_data['vt_db2']['visitor_tickets_id']);
                        unset($queue_data['vt_db2']['revision_distributor']);
                        unset($queue_data['vt_db2']['check_activated']);
                        unset($queue_data['vt_db2']['redeem_main_ticket_only']);
                        
                        if($update_on_pass_no == "1" && $pass_no != '') {
                            $where = ' vt_group_no = "'.$visitor_group_no.'" and passNo = "'. $pass_no .'" and is_refunded !="1" ' . $check_activated;
                        } else if($update_on_bleep_pass_no == "1" && $bleep_pass_no != '') {
                            $where = ' vt_group_no = "'.$visitor_group_no.'" and scanned_pass = "'. $bleep_pass_no .'" and is_refunded !="1" ' . $check_activated;
                        } else {
                            $where = ' vt_group_no = "' . $visitor_group_no . '" and transaction_id IN (' . implode(',', $transaction_ids) . ') and is_refunded !="1" ' . $check_activated;
                        }
                        if (isset($ticketid) && $ticketid != '') {
                            $where .= ' and ticketId= "' . $ticketid . '"';
                        }
                        if ($museum_id > 0) {
                            $where .= ' and museum_Id= "' . $museum_id . '"';
                        }
                        if ($clustering_id != '') {
                            $where .= ' and used= "0" and targetLocation IN (' . $clustering_id . ')';
                        }
                        if ($redeem_main_ticket_only) {
                            $where .= ' and col2 = "0"';
                        }
                        $queue_data['vt_db2']['CONCAT_VALUE']['action_performed'] = ', '.$action_performed;
                        $redeemChk = ((isset($queue_data['vt_db2']['used']) && $queue_data['vt_db2']['used']=='1')? 1: 0);
                        $insert_in_vt = array("table" => 'visitor_tickets', "columns" => $queue_data['vt_db2'], "where" => $where, "redeem" => $redeemChk);
                        if (!empty($transaction_ids)) {
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_transaction_ids'.date("H:i:s.u"), array('transaction_ids' => json_encode($transaction_ids), 'insert_in_vt' => json_encode($insert_in_vt)), $queue_channel_type); 
                                $this->venue_model->set_insert_queries($insert_in_vt);
                                $logs['vt_db2->set_insert_queries'] = $MPOS_LOGS['get_insert_queries'];
                                unset($MPOS_LOGS['get_insert_queries']);
                                $logs['db2_vt_update_' . date('H:i:s')] = $last_query = $this->secondarydb->db->last_query();
                                $logs['db4_vt_update_' . date('H:i:s')] = $last_query = $this->fourthdb->db->last_query();
                                $MPOS_LOGS_DATA['vt_db2_updations'] = $logs;
                        } else {
                            $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_inside_transaction_ids_else'.date("H:i:s.u"), array('insert_in_vt' => json_encode($insert_in_vt)), $queue_channel_type); 
                            $logs['missing_update_vt_DB2_and_db4'] = $insert_in_vt;
                            $MPOS_LOGS_DATA['vt_db2_updations'] = $logs;
                            $add_to_pending_req['vt_db2'] = array(
                                'fetch_vt_data' => $fetch_vt_data,
                                'update_query' => $insert_in_vt
                            );
                        }
                        $logs = array();
                    }
                    /* EOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */ 
     
                    /* BOC to insert refund entries from MPOS in prepaid ticket table. */
                    if (isset($queue_data['refund_prepaid_orders']) && !empty($queue_data['refund_prepaid_orders'])) {
                        // secound param is to check whether order is refunded from app or wedend
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_refund_prepaid_orders'.date("H:i:s.u"), array('refund_prepaid_orders' => json_encode($queue_data['refund_prepaid_orders'])), $queue_channel_type); 

                        $pt_details = $this->firebase_model->refund_prepaid_orders($queue_data['refund_prepaid_orders'], 1);
                        if (isset($MPOS_LOGS['refund_prepaid_orders'])) {
                            $MPOS_LOGS_DATA['refund_prepaid_orders'] = $MPOS_LOGS['refund_prepaid_orders'];
                            unset($MPOS_LOGS['refund_prepaid_orders']);
                        }
                    }
                    
                    if (!empty($queue_data['update_visitor_tickets_for_refund_order'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_update_visitor_tickets_for_refund_order'.date("H:i:s.u"), array('update_visitor_tickets_for_refund_order' => json_encode($queue_data['update_visitor_tickets_for_refund_order'])), $queue_channel_type); 
                        $this->firebase_model->update_visitor_for_cancel_orders($queue_data['update_visitor_tickets_for_refund_order']);
                        if (isset($MPOS_LOGS['update_visitor_for_cancel_orders'])) {
                            $MPOS_LOGS_DATA['update_visitor_tickets_for_refund_order'] = $MPOS_LOGS['update_visitor_for_cancel_orders'];
                            unset($MPOS_LOGS['update_visitor_for_cancel_orders']);
                        }
                    }
                    
                    if (!empty($queue_data['update_visitor_tickets_for_partial_refund_order'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'update_visitor_tickets_for_partial_refund_order'.date("H:i:s.u"), array('update_visitor_tickets_for_partial_refund_order' => json_encode($queue_data['update_visitor_tickets_for_partial_refund_order'])), $queue_channel_type); 
                        $this->firebase_model->update_visitor_for_partial_cancel_orders($queue_data['update_visitor_tickets_for_partial_refund_order']);
                        if (isset($MPOS_LOGS['update_visitor_for_partial_cancel_orders'])) {
                            $MPOS_LOGS_DATA['update_visitor_tickets_for_partial_refund_order'] = $MPOS_LOGS['update_visitor_for_partial_cancel_orders'];
                            unset($MPOS_LOGS['update_visitor_for_partial_cancel_orders']);
                        }
                    }

                    /* EOC to insert refund entries from MPOS in prepaid ticket table. */
                    
                    /* BOC to update credit limit */
                    if (!empty($queue_data['update_credit_limit_data'])) {
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'update_credit_limit_data'.date("H:i:s.u"), array('update_credit_limit_data' => json_encode($queue_data['update_credit_limit_data'])), $queue_channel_type); 
                        $this->order_process_model->update_credit_limit($queue_data['update_credit_limit_data'], '1');
                    }
                    /* EOC to update credit limit */
                    if(isset($queue_data['order_payment_details']) && !empty($queue_data['order_payment_details'])) {
                        $this->CreateLog('paymnts.php', $queue_data['visitor_group_no'].'_order_paymnet_'.date("H:i:s.u"), array('data' => json_encode($queue_data['order_payment_details'])), "10");   
                        if($queue_data['order_payment_details']['refunded_entry'] == "1") {
                            $refunded_entry = 1;
                            unset($queue_data['order_payment_details']['refunded_entry']);
                        } else {
                            $refunded_entry = 0;
                        }
                        $this->pos_model->update_payment_detaills($queue_data['order_payment_details'], $refunded_entry);
                    }

                    if (SERVER_ENVIRONMENT != 'Local' && (BigqueryConstants::SYNC_BQ == 1) && !empty($queue_data['action']) && (!isset($queue_data['confirm_order']) || (isset($queue_data['confirm_order']) && empty($queue_data['confirm_order'])))) { //confirm_order = array() can exists in queue
                        $logs['action_out_of_'.$queue_data['action'].'_'.$queue_data['visitor_group_no']] = $queue_data['action'].'_'.$queue_data['visitor_group_no'];
                        /* code for inserting in bigquery from mysql secondary pt tbl*/
                        if($action_already_updated_in_pt_BG == 0 && (empty($queue_data['channel_type']) || $queue_data['channel_type']!='5')) { //PT needs to be updated
                            
                            $results = $this->secondarydb->db->query('select * from prepaid_tickets pt1 where visitor_group_no ="' . $queue_data['visitor_group_no'] . '" and version = (select max(version) from prepaid_tickets pt2 where pt2.prepaid_ticket_id = pt1.prepaid_ticket_id and pt1.visitor_group_no ="' . $queue_data['visitor_group_no'] . '") ')->result();
                            $aws_message2 = base64_encode(gzcompress(json_encode($results)));
                            $logs['data_to_queue_LIVE_SCAN_REPORT_QUEUE_URL_' . date("H:i:s")] = $aws_message2;
                            $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                            if ($MessageIdss != false) {
                                $err = $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                            }

                            /* Select data and sync on BQ */
                            $this->order_process_model->getVtDataToSyncOnBQ($queue_data['visitor_group_no']);
                        }
                        
                        if(isset($queue_data['pass_no']) && !empty($queue_data['pass_no']) && !empty($queue_data['channel_type']) && $queue_data['channel_type']=='5') {
                            
                            /* Get data on the basis of passNo if channel_type='5' */
                            $final_visitor_data_to_insert_big_query_transaction_specific = $this->secondarydb->db->query('SELECT activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id FROM visitor_tickets vt1 WHERE passNo = "' . $queue_data['pass_no'] . '" and version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.passNo = "' . $queue_data['pass_no'] . '") ')->result_array();
                            $logs['final_visitor_data_vt_query_for_channel_type_5_'.date('Y-m-d H:i:s')] = $this->secondarydb->db->last_query();
                            $results = $this->secondarydb->db->query('SELECT * FROM prepaid_tickets pt1 WHERE passNo="'.$queue_data['pass_no'].'" and version = (select max(version) from prepaid_tickets pt2 where pt2.prepaid_ticket_id = pt1.prepaid_ticket_id and pt1.passNo="'.$queue_data['pass_no'].'")')->result();
                            
                            $logs['final_visitor_data_pt_query_for_channel_type_5_'.date('Y-m-d H:i:s')] = $this->secondarydb->db->last_query();
                            $logs['final_prepaid_data_to_insert_big_query_compressed_'.date('Y-m-d H:i:s')] = json_encode($results);
                        } else {
                            //for vt
                            $final_visitor_data_to_insert_big_query_transaction_specific = $this->secondarydb->db->query('select activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id from visitor_tickets vt1 where vt_group_no ="' . $queue_data['visitor_group_no'] . '" and version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.vt_group_no ="' . $queue_data['visitor_group_no'] . '") ')->result_array();
                        }
                        
                        $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress(json_encode($final_visitor_data_to_insert_big_query_transaction_specific)));
                        $logs['data_to_queue_AGG_VT_INSERT_QUEUEURL_' . date("H:i:s")] = $final_visitor_data_to_insert_big_query_compressed;
                        if (SERVER_ENVIRONMENT == 'Local') {
                            local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
                        } else {
                            
                            $this->load->library('Sns');
                            $sns_object = new Sns();
                            
                            $agg_vt_insert_queueUrl = AGG_VT_INSERT_QUEUEURL;
                            // This Fn used to send notification with data on AWS panel. 
                            $MessageId = $sqs_object->sendMessage($agg_vt_insert_queueUrl, $final_visitor_data_to_insert_big_query_compressed);
                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                            $err = '';
                            if ($MessageId != false) {
                                $err = $sns_object->publish($agg_vt_insert_queueUrl, AGG_VT_INSERT_ARN);
                            }
                            $aws_message2 = '';
                            $aws_message2 = json_encode($results);
                            $aws_message2 = base64_encode(gzcompress($aws_message2));
                            $logs['data_to_queue_LIVE_SCAN_REPORT_QUEUE_URL_' . date("H:i:s")] = $aws_message2;
                            $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                            if ($MessageIdss != false) {
                                $err = $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                            }

                            /* Select data and sync on BQ */
                            $this->order_process_model->getVtDataToSyncOnBQ($queue_data['visitor_group_no']);
                        }
                        /* code for inserting in bigquery from mysql secondary pt tbl ends here*/
                        if (!empty($logs)) {
                            $MPOS_LOGS_DATA['action_for_'.$queue_data['action'].'_'.$queue_data['visitor_group_no']] = $logs;
                            $logs = array();
                        }
                    }
                    
                    /* BOC Insertion of data in elastic search */
                    if (SERVER_ENVIRONMENT != 'Local' && ENABLE_ELASTIC_SEARCH_SYNC) {
                        $elastic_logs['elastic_search_starts'] = date('Y-m-d H:i:s');
                        if (isset($queue_data['multiple_visitor_group_no']) && !empty($queue_data['multiple_visitor_group_no'])) {
                            $elastic_logs['multiple_visitor_group_no'] = json_encode($queue_data['multiple_visitor_group_no']);
                            $other_data = [];
                            $other_data['hotel_id'] = !empty($queue_data['update_redeem_table']['hotel_id']) ? $queue_data['update_redeem_table']['hotel_id'] : (!empty($queue_data['hotel_id']) ? $event_data['hotel_id'] : '');
                            $other_data['fastly_purge_action'] = !empty($queue_data['action']) ? $queue_data['action'] : 'REDEMPTION_SCANING_ACTION';
                            foreach ($queue_data['multiple_visitor_group_no'] as $vgn) {
                                $other_data['request_reference'] = $vgn;
                                $this->elastic_search->sync_data($vgn, $other_data);
                            }
                        } else if ($queue_data['visitor_group_no'] != '') {
                            $elastic_logs['visitor_group_no'] = json_encode($queue_data['visitor_group_no']);
                            $other_data = [];
                            $other_data['request_reference'] = $queue_data['visitor_group_no'];
                            $other_data['hotel_id'] = !empty($queue_data['update_redeem_table']['hotel_id']) ? $queue_data['update_redeem_table']['hotel_id'] : (!empty($queue_data['hotel_id']) ? $event_data['hotel_id'] : '');
                            $other_data['fastly_purge_action'] = !empty($queue_data['action']) ? $queue_data['action'] : 'REDEMPTION_SCANING_ACTION';
                            $this->elastic_search->sync_data($queue_data['visitor_group_no'], $other_data);
                        }
                        $MPOS_LOGS_DATA['elastic_search _' .  date("H:i:s")] = $elastic_logs;
                    } else {
                        if (isset($queue_data['multiple_visitor_group_no']) && !empty($queue_data['multiple_visitor_group_no'])) {
                            $request_reference = '';
                            foreach ($queue_data['multiple_visitor_group_no'] as $vgn) {
                                $request_reference = $vgn;
                                break;
                            }
                            $distributor_id = !empty($queue_data['update_redeem_table']['hotel_id']) ? $queue_data['update_redeem_table']['hotel_id'] : (!empty($queue_data['hotel_id']) ? $event_data['hotel_id'] : '');
                            if (!empty($distributor_id)) {
                                $fastlykey = 'order/account/' . $distributor_id;
                                $fastly_purge_key = !empty($queue_data['action']) ? $queue_data['action'] : 'REDEMPTION_SCANING_ACTION';
                                $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, $fastly_purge_key);
                            }
                        } else if ($queue_data['visitor_group_no'] != '') {
                            $distributor_id = !empty($queue_data['update_redeem_table']['hotel_id']) ? $queue_data['update_redeem_table']['hotel_id'] : (!empty($queue_data['hotel_id']) ? $event_data['hotel_id'] : '');
                            if(!empty($distributor_id)){
                                $fastlykey = 'order/account/' . $distributor_id;
                                $request_reference = $queue_data['visitor_group_no'];
                                $fastly_purge_key = !empty($queue_data['action']) ? $queue_data['action'] : 'REDEMPTION_SCANING_ACTION';
                                $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, $fastly_purge_key);
                            }
                        }
                        /** Send Data to notifiaction Queue TO Purge Data on Fastly */
                        if (!empty($queue_data['visitor_group_no'])) {
                            $this->load->model('notification_model');
                            $this->notification_model->purgeFastlyNotifiaction($queue_data['visitor_group_no'], ($queue_data['action'] ?? 'REDEMPTION_SCANING_ACTION'));
                        }
                    }
                    /* EOC Insertion of data in elastic search */

                    if(isset($queue_data['write_in_mpos_logs']) && $queue_data['write_in_mpos_logs'] == 1) { //from mpos devices
                        // $transaction_failures = $this->trans->check_trans_status();
                        if(!empty($add_to_pending_req) && empty($transaction_failures)) {
                            $MPOS_LOGS_DATA['add_to_pending_req_'.date("H:i:s")] = $add_to_pending_req;
                            $update_pending_req_table['request_type'] = 'scaning_actions';
                	    $update_pending_req_table['visitor_group_no'] = isset($visitor_group_no) ? $visitor_group_no : '';
                            $update_pending_req_table['request'] = json_encode($add_to_pending_req);
                            $update_pending_req_table['added_at'] = gmdate("Y-m-d H:i:s");
                	    $this->pos_model->save('firebase_pending_request', $update_pending_req_table);
                        }
                        
                        $MPOS_LOGS['write_in_mpos_logs'] = 1;
                        $MPOS_LOGS[] = $MPOS_LOGS_DATA;
                        $this->CreateLog('scanning_action_logs.php', $queue_vgn.'_mpos_logs_data'.date("H:i:s.u"), array('_mpos_logs_data' => json_encode($MPOS_LOGS)), $queue_channel_type); 
        
                    } else {
                        $OTHERS_LOGS[] = $MPOS_LOGS_DATA;
                    }
                }
            }
            
            if ($fifo == 1) {
                // $this->trans->close();
            }
        } catch (Exception $e) {
            $spcl_ref = 'scanning_action_error';
            if (json_decode($e->getMessage(), true) == NULL) {
                $MPOS_LOGS['exception'] = $e->getMessage();
            } else {
                $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            }
            $this->CreateLog('scanning_action_exception.php', 'exception_date_time'.date("H:i:s.u"), array('exception' => json_encode($e->getMessage())), 11);  
            //for any exception in fifo queue processing => we will pass all the processed (deleted) records to standard queue => then that queue will be processed manually with fifo = 0
            if (SERVER_ENVIRONMENT != 'Local' && $fifo == 1) {
                $MPOS_LOGS['Data_to_SCANING_ACTION_URL_STANDARD_queue'] = $deleted_data_from_queue;
                $this->CreateLog('scanning_action_exception.php', 'exception_date_time'.date("H:i:s.u"), array('exception_data' => json_encode($deleted_data_from_queue)), 11); 
                $queueUrl = SCANING_ACTION_URL_STANDARD;
                $deleted_msgs = array_chunk($deleted_data_from_queue, 10);
                foreach($deleted_msgs as $deleted_msg) {
                    $batch = array();
                    foreach ($deleted_msg as $queue_msgs) {
                        $messageData = ['Id' => (string) $queue_msgs['MessageId'], 'MessageBody' => $queue_msgs['Body']];
                        $batch[] = $messageData;
                    }
                    // This Fn used to send notification with data on AWS panel.
                    $sqs_object->sendMessageBatch($queueUrl, $batch);
                }
            }
        }
        $this->log_library->write_log($MPOS_LOGS, 'mainLog', 'scanning_action_arn', $spcl_ref);
        if (!empty($OTHERS_LOGS)) {
            $this->log_library->write_log($OTHERS_LOGS, 'mainLog', 'scanning_action_arn');
        }
    }
}
