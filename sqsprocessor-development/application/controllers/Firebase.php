<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');


class Firebase extends MY_Controller
{

    var $base_url;

    function __construct()
    {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('firebase_model');
        $this->load->model('pos_model');
        $this->load->model('order_process_model');
        $this->load->model('venue_model');
        $this->load->library('log_library');
        $this->load->library('purgefastly');
        $this->load->library('elastic_search');
        $this->base_url = $this->config->config['base_url'];
        $this->LANGUAGE_CODES['en'] = 'english';
        $this->LANGUAGE_CODES['es'] = 'spanish';
        $this->LANGUAGE_CODES['nl'] = 'dutch';
        $this->LANGUAGE_CODES['de'] = 'german';
        $this->LANGUAGE_CODES['it'] = 'italian';
        $this->LANGUAGE_CODES['fr'] = 'french';
    }
    

    function insert_in_db($queue_url = '')
    {  
        global $MPOS_LOGS;
        try {
        // Fetch the raw POST body containing the message
        $postBody = file_get_contents('php://input');
        $queueUrl = MPOS_ORDERS_QUEUE;      
        if (SERVER_ENVIRONMENT != 'Local') {
            $request_headers = getallheaders();
            if(!isset($request_headers['X-Amz-Sns-Topic-Arn']) || $request_headers['X-Amz-Sns-Topic-Arn'] != MPOS_ORDERS_ARN) {
                $this->output->set_header('HTTP/1.1 401 Unauthorized');
                $this->output->set_status_header(401, "Unauthorized");
                exit;
            }
            // Load SQS library.
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqs_object = new Sqs();

            $this->load->library('Sns');
            $sns_object = new Sns();
            // It return Data from message.
            $messages = $sqs_object->receiveMessage($queueUrl);
            if ($messages->getPath('Messages')) {
                if ($queue_url != '') {
                    echo "<pre>";
                    print_r($messages->getPath('Messages'));
                    echo "</pre>";
                }
                $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step0_'.date("H:i:s.u"), array("messages" => json_encode($messages)));   
                foreach ($messages->getPath('Messages') as $message) {
                    $string = $message['Body'];
                    $string = gzuncompress(base64_decode($string));
                    $string = utf8_decode($string);
                    $data = json_decode($string, true);
                    $logs['queue_data'] = $data;
                    if ($queue_url != '') {
                        echo "<pre>";
                        print_r($data);
                        echo "</pre>";
                    }
                    if (isset($data['write_in_mpos_logs']) && $data['write_in_mpos_logs'] == 1) {
                        $MPOS_LOGS['write_in_mpos_logs'] = 1;
                    }
                    if(!empty($data['import_bookings_array'])) {
                       $this->common_model->insert_batch('import_booking', $data['import_bookings_array'], 1);
                    }
                    // To check record exist or not in Secondary DB.
                    if (isset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'])) {
                        if ($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'] == 1) {
                            $hto_id = '';
                        }
                        unset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup']);
                    } else {
                        $hto_id = $this->secondarydb->db->get_where("prepaid_tickets", array("visitor_group_no" => $data['hotel_ticket_overview_data'][0]['visitor_group_no']))->row()->id;
                    }

                    $logs['hto_id'] = $hto_id;
                    $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step1_'.date("H:i:s.u"), array("hto_id" => $hto_id, 'data' => json_encode($data)), $data['hotel_ticket_overview_data'][0]['channel_type']);   
                    if ($hto_id == false || $hto_id == '' || isset($data['add_to_prioticket'])) {

                        // Main Method of Model to insert data in Secondaty DB [hotel_ticket_overview, prepaid_ticket, visitor_ticket, prepaid_extra_options Tables].
                        $logs['calling_order_process_model->insertdata_on'] = date("H:i:s.u");
                        $sns_messages = $this->order_process_model->insertdata($data);
                        if (!empty($data['order_payment_details'])) {
                            $this->order_process_model->add_payments($data['order_payment_details']);
                        }
                        if ($data['contact_uid'] != "") {
                            $this->firebase_model->insert_order_contact($data['contact_uid'], $data['hotel_ticket_overview_data'][0]['visitor_group_no'], $data['created_date']);
                        }
                        $sns_messages = $sns_messages['sns_message'];                                         

                        $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step2_before_rds_sync_'.date("H:i:s.u"), array("step" => "_data_step2_before_rds_sync_"), $data['hotel_ticket_overview_data'][0]['channel_type']);   
                        // Send data in RDS queue realtime
                        if (SYNCH_WITH_RDS_REALTIME) {
                            $sqs_object->sendMessage(RDS_QUEUE_URL_REALTIME, $message['Body']);
                            $sns_object->publish('RDS Realtime Insert', RDS_TOPIC_ARN_REALTIME);
                        }
                        $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step_after_rds_sync_'.date("H:i:s.u"), array("step" => "update_redeem_table"), $data['hotel_ticket_overview_data'][0]['channel_type']);   
                        if (!empty($data['update_redeem_table']) && $data['update_redeem_table'] != null) {
                            $this->venue_model->update_redeem_table($data['update_redeem_table']);
                        }    
                        $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step_after_redeem_table_updations_'.date("H:i:s.u"), array("step" => "data_step_after_redeem_table_updations_"), $data['hotel_ticket_overview_data'][0]['channel_type']);                              
                        
                        if (!empty($sns_messages)) {
                            $request_array['db1'] = $sns_messages;
                            $request_array['visitor_group_no'] = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
                            $request_string = json_encode($request_array);
                            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $update_queueUrl = UPDATE_DB_QUEUE_URL;
                            // This Fn used to send notification with data on AWS panel. 
                            $MessageId = $sqs_object->sendMessage($update_queueUrl, $aws_message);
                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                            $err = '';
                            if ($MessageId != false) {
                                $this->load->library('Sns');
                                $sns_object = new Sns();
                                $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }
                        }

                        $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step_delete_from_queue_'.date("H:i:s.u"), array("msg->handle" => json_encode($message['ReceiptHandle'])), $data['hotel_ticket_overview_data'][0]['channel_type']);   
                        // It remove message from SQS queue for next entry.
                        if($message['ReceiptHandle'] != '') {

                            $delete_return = $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                            $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_data_step_delete_from_queue_'.date("H:i:s.u"), array("delete_return" => json_encode($delete_return)), $data['hotel_ticket_overview_data'][0]['channel_type']);   
                        }
                         /* BOC sync data on elastic cache */
                        if( !empty( $data['hotel_ticket_overview_data'][0]['visitor_group_no'] ) && ENABLE_ELASTIC_SEARCH_SYNC == 1 ) {
                            $other_data = [];
                            $other_data['request_reference'] = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
                            $other_data['hotel_id'] = !empty($data['hotel_ticket_overview_data'][0]['hotel_id']) ? $data['hotel_ticket_overview_data'][0]['hotel_id'] : '';
                            $other_data['fastly_purge_action'] = 'ORDER_CREATE';
                            $this->elastic_search->sync_data($data['hotel_ticket_overview_data'][0]['visitor_group_no'], $other_data);
                        }elseif(!empty($data['hotel_ticket_overview_data'][0]['visitor_group_no']) && !empty($data['hotel_ticket_overview_data'][0]['hotel_id'])){
                            /* Start code to purge keys as per the distributer on fastly */
                            $fastlykey = 'order/account/' . $data['hotel_ticket_overview_data'][0]['hotel_id'];
                            $request_reference = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
                            $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, 'ORDER_CREATE');
                            /* end code to purge keys as per the distributer on fastly */
                        }
                        //Pt to overview rporting table sync in bigquery
                        $results = $this->secondarydb->db->query('select * from prepaid_tickets pt1 where visitor_group_no ="' . $data['hotel_ticket_overview_data'][0]['visitor_group_no'] . '" and version = (select max(version) from prepaid_tickets pt2 where pt2.prepaid_ticket_id = pt1.prepaid_ticket_id and pt1.visitor_group_no ="' . $data['hotel_ticket_overview_data'][0]['visitor_group_no'] . '") ')->result();
                        $this->CreateLog('big_query_1.php', 'pt_db2_data', array(
                                    "sqs_received_messages" => json_encode($results)
                                ));
                        $aws_message2 = json_encode($results);
                        $aws_message2 = base64_encode(gzcompress($aws_message2));

                        $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                        if ($MessageIdss != false) {
                            $this->load->library('Sns');
                            $sns_object = new Sns();
                            $err = $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                        }
                        //Pt to overview rporting table sync in bigquery end here

                        /* Select data and sync on BQ */
                        $this->order_process_model->getVtDataToSyncOnBQ($data['hotel_ticket_overview_data'][0]['visitor_group_no']);

                        /** Send Data to notifiaction Queue TO Purge Data on Fastly */
                        if (!empty($queue_data['visitor_group_no'])) {
                            $this->load->model('notification_model');
                            $this->notification_model->purgeFastlyNotifiaction($queue_data['visitor_group_no'], 'ORDER_CREATE');
                        }
                        /* EOC sync data on elastic cache */ 
                        
                        /**code for bigquery aggvt day table */
                        $vt_data_for_BG = $this->secondarydb->db->query('select activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id from visitor_tickets vt1 where vt_group_no ="' . $data['hotel_ticket_overview_data'][0]['visitor_group_no'] . '" and version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.vt_group_no ="' . $data['hotel_ticket_overview_data'][0]['visitor_group_no'] . '") ')->result_array();
                                       
                        $bg_vt_data = base64_encode(gzcompress(json_encode($vt_data_for_BG)));
                        // This Fn used to send notification with data on AWS panel. 
                        $MessageId = $sqs_object->sendMessage(AGG_VT_INSERT_QUEUEURL, $bg_vt_data);
                        // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                        $err = '';
                        if ($MessageId != false) {
                            $err = $sns_object->publish(AGG_VT_INSERT_QUEUEURL, AGG_VT_INSERT_ARN);
                        }
                        /**code for aggvt day table end here */   
                         /* BOC sync data on elastic cache */
                         if( !empty( $data['hotel_ticket_overview_data'][0]['visitor_group_no'] )) {
                            $this->elastic_search->sync_data($data['hotel_ticket_overview_data'][0]['visitor_group_no']);
                        }
                        /* EOC sync data on elastic cache */
                    } else {
                        if($message['ReceiptHandle'] != '') {
                            $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                        }
                    }
                }
            }
        } else {
            $string = $postBody;
            $logs['request_' . date('H:i:s')] = json_decode($string, true);
            $data = json_decode($string, true);
            if(!empty($data['import_bookings_array'])) {
                $this->common_model->insert_batch('import_booking', $data['import_bookings_array'], 1);
            }
             $this->order_process_model->insertdata($data);
            if (!empty($data['update_redeem_table'])) {
                $this->venue_model->update_redeem_table($data['update_redeem_table']);
            }
            if (!empty($data['order_payment_details'])) {
                $this->order_process_model->add_payments($data['order_payment_details']);
            }
            if ($data['contact_uid'] != "") {
                $this->firebase_model->insert_order_contact($data['contact_uid'], $data['hotel_ticket_overview_data'][0]['visitor_group_no'], $data['created_date']);
            }
             /* BOC sync data on elastic cache */
             if( !empty( $data['hotel_ticket_overview_data'][0]['visitor_group_no'] ) && ENABLE_ELASTIC_SEARCH_SYNC == 1 ) {
                $other_data = [];
                $other_data['request_reference'] = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
                $other_data['hotel_id'] = !empty($data['hotel_ticket_overview_data'][0]['hotel_id']) ? $data['hotel_ticket_overview_data'][0]['hotel_id'] : '';
                $other_data['fastly_purge_action'] = 'ORDER_CREATE';
                $this->elastic_search->sync_data($data['hotel_ticket_overview_data'][0]['visitor_group_no'], $other_data);
            }
            /* EOC sync data on elastic cache */
        }
        
        } catch (Exception $e) {
            $this->CreateLog('sync_rds_from_mpos.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_exception_in_order_process_'.date("H:i:s.u"), array(json_encode($e->getMessage())), "10");   
            if (json_decode($e->getMessage(), true) == NULL) {
                $MPOS_LOGS['exception_in_order_process_'.date("H:i:s")] = $e->getMessage();
            } else {
                $MPOS_LOGS['exception_in_order_process_'.date("H:i:s")] = json_decode($e->getMessage(), true);
            }
        }
        $MPOS_LOGS['insert_in_db'] = $logs;
        $MPOS_LOGS['queue'] = 'MPOS_ORDERS_QUEUE';
        $this->log_library->write_log($MPOS_LOGS, 'mainLog', 'insert_in_db');
    }

}
