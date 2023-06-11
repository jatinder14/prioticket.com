<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

class Order_process extends MY_Controller {
    /* #region  BOC of class Order_process */
    var $base_url;    
    
    /* #region  main function to load controller Order Process */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('order_process_model');
        $this->load->library('log_library');
        $this->load->library('purgefastly');
        $this->load->library('elastic_search');
        $this->base_url = $this->config->config['base_url'];
        $this->load->model('notification_model');
    }
    /* #endregion main function to load controller pos*/
    
    /* region To start rabbitmq exchange for admin server */    
    /**
     * run_exchange_sqs
     *
     * @param  mixed $error_reporting
     * @return void
     * @author Jatinder Kumar
     */
    function run_exchange_sqs( $error_reporting=0 ) {
    
        if( $error_reporting == '1' ) {
            
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        if( Rabbitqueue::ENABLE === 1 ) {

            $this->load->library( 'rabbitmq' );
            $this->rabbitmq->get_from_exchange( 'sqs_processes', 'direct' );
        }
        die( json_encode( array( "success" => 'OK' ) ) );
    }
    /* endregion To start exchange for admin server */
    
    /* #region  To move the data in main tables of seconday DB from queue */
    /**
     * insert_in_db
     *
     * @return void
     * @author Davinder singh <davinder.intersoft@gmail.com> on November, 2016
     */
    function insert_in_db()
    {
        // Queue process check
        if (STOP_QUEUE) {
            exit();
        }
        
        // Fetch the raw POST body containing the message
        $postBody = file_get_contents('php://input');
        if (SERVER_ENVIRONMENT == 'Local') {
            $message_id = 1;
            $queueUrl = QUEUE_URL;
        } else {
            $message = json_decode($postBody, true);
            $message_id = $message['Message'];
            
            if ($message_id != '') {
                $message_id_data = explode('#~#', $message_id);
                $message_id = $message_id_data[0];
                $queueUrl = $message_id_data[1] ? $message_id_data[1] : QUEUE_URL;
            }
        }
        $this->CreateLog('check_server.php','insert_in_db',array('message_id' => $message_id, 'data' =>$postBody));
        if ($message_id != '') {

            // Load SQS library.
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            
            if (SERVER_ENVIRONMENT == 'Local') {
                $this->CreateLog('check_server.php','insert_in_db',array('Exception' => 'sadsa'));
                $postBody = file_get_contents('php://input');
                $messages = array(
                    'Body' => $postBody
                );
            } else {
                $this->CreateLog('check_server.php','insert_in_db',array('Exception' => 'sasadsadsadsa'));
                $messages = $sqs_object->receiveMessage($queueUrl);
                $messages = $messages->getPath('Messages');
            }
            
            // It return Data from message.
            if (!empty($messages)) {
                try {                
                    $this->queue_alerts($messages,$queueUrl,2);
                } catch (Exception $e) {
                    $this->CreateLog('queue_alerts_issue.php','insert_in_db',array('Exception' => $e->getMessage()));
                }
                foreach ($messages as $message) {
                    
                    $this->processQueueMessage( $message, false, $queueUrl, $main_queueUrl );
                }
            }
        }
    }
    /* #endregion To move the data in main tables of seconday DB. */

    /* #region  To process the data for insertion in secondary DB from queue */
    /**
     * processQueueMessage
     *
     * @param $message (string) (queue message)
     * @param $rabbitMQ (boolean)
     * @return void
     * @author Jatinder Kumar <jatinder.aipl@gmail.com> on 9 March, 2021
    */
    function processQueueMessage( $message, $rabbitMQ=false, $queueUrl='', $main_queueUrl='' ) {
        
        // Load SQS library.
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        
        if (SERVER_ENVIRONMENT != 'Local') {
            
            if(SEND_IN_NEW_QUEUE == '1') {

                $new_queue_url = NEW_QUEUE_URL;
                $send_message = $message['Body'];
                try {
                    $sms_id = $sqs_object->sendMessage($new_queue_url, $send_message);
                    $this->CreateLog('New_queue_send_data.php','Data',array('Message_body' => $send_message,'Id' => $sms_id));
                } catch (Exception $e) {
                    $this->CreateLog('New_queue_error.php','Error_message',array('Exception' => $e->getMessage()));
                }
            }
            
            /* If endpointgets hit from AWS then delete the message from queue after reading the message */
            if( $rabbitMQ === false && !empty( $queueUrl ) ) {
                $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                /* EOC It remove message from SQS queue for next entry. */
            }
            /* BOC extract and convert data in array from queue */
            $string = $message['Body'];
        } 
        else {
            $string = $message;
        }
        $string = gzuncompress(base64_decode($string));
        $this->CreateLog('queue_log.php', $string, array());
        $distributor_ids = $supplier_ids = '';
        $string = utf8_decode($string);
        $data = json_decode($string, true);


        $vgn = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
        if (!$vgn || $vgn == "") {
            $vgn = $data['prepaid_tickets_data'][0]['visitor_group_no'];
        }
        $this->CreateLog('a_website.php', 'web2', array('vgn : ' => $vgn));
        // To check record exist or not in Secondary DB.
        if (isset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'])) {
            if ($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'] == 1) {
                $hto_id = '';
            }
            unset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup']);
        } 
        else {
            $hto_id = $this->secondarydb->db->get_where("prepaid_tickets", array("visitor_group_no" => $vgn))->row()->prepaid_ticket_id;
        }                    
        $this->CreateLog('a_website.php', 'web3', array('data : ' => json_encode($data)));
        if ((isset($data['uncancel_order']) && ($data['uncancel_order'] == 1)) || (!$hto_id || $hto_id == '' || isset($data['add_to_prioticket'])) || (isset($data['script_order']) && $data['script_order'] == 1)) {
            // Main Method of Model to insert data in Secondaty DB [hotel_ticket_overview, prepaid_ticket, visitor_ticket, prepaid_extra_options Tables].
            $this->CreateLog('a_website.php', 'web4', array('testing : ' => 'ENTRY'));
            $messages = $this->order_process_model->insertdata($data, "0", "1");

            if (isset($data['order_contacts']) && !empty($data['order_contacts'])) {
                $order_contacts = $data['order_contacts'];
                $this->order_process_model->contact_add($order_contacts, $data['hotel_ticket_overview_data'][0]['visitor_group_no']);
            }
            if (!empty($data['insert_paid_payment_detail'])) {
                $data_new = $data;
                if(isset($data_new['booking_level_order_payment_details'])){
                    unset($data_new['booking_level_order_payment_details']);
                }
                $this->order_process_model->add_payments($data['insert_paid_payment_detail'], 1, $data_new);
            }
            if (!empty($data['order_payment_details'])) {
                $this->order_process_model->add_payments($data['order_payment_details'], 1, $data);
            }
            if (isset($data['action_from']) && $data['action_from'] == 'V3.2'
                    && !empty($data['prepaid_tickets_data'][0]['hotel_id']) && !empty($data['prepaid_tickets_data'][0]['visitor_group_no'])
            ) {
                $delete_where_bod = array(
                    'visitor_group_no' => $data['prepaid_tickets_data'][0]['visitor_group_no'],
                    'distributor_id' => $data['prepaid_tickets_data'][0]['hotel_id']
                );
                $this->order_process_model->deleteBlockedRecord($delete_where_bod);
                $delete_where_bop = array(
                    'visitor_group_no' => $data['prepaid_tickets_data'][0]['visitor_group_no'],
                    'payment_category' => '3'
                );
                $this->order_process_model->deleteBlockedPaymentRecord($delete_where_bop);

            }
            $inserted_flag = $messages['flag'];
            $sns_messages = $messages['sns_message'];
            $final_visitor_data_to_insert_big_query_transaction_specific = $messages['final_visitor_data_to_insert_big_query_transaction_specific'];
            $final_prepaid_data_to_insert_big_query = $messages['final_prepaid_data_to_insert_big_query'];



            //Pt to overview rporting table sync in bigquery
            $results = $this->secondarydb->db->query('select * from prepaid_tickets pt1 where visitor_group_no ="' . $vgn . '" and pt1.version = (select max(version) from prepaid_tickets pt2 where pt1.prepaid_ticket_id = pt2.prepaid_ticket_id and  pt1.visitor_group_no ="' . $vgn . '")')->result();
            $this->CreateLog('big_query_1.php', 'pt_db2_data', array(
                        "sqs_received_messages" => json_encode($results)
                    ));
            $aws_message2 = json_encode($results);
            $aws_message2 = base64_encode(gzcompress($aws_message2));

            if (SERVER_ENVIRONMENT != 'Local') {
            
                $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                if ($MessageIdss) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                }
            }

            /* Select data and sync on BQ */
            $this->order_process_model->getVtDataToSyncOnBQ($vgn);

            /**code for bigquery aggvt day table */
            $final_visitor_data_to_insert_big_query_transaction_specific = $this->secondarydb->db->query('select activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id from visitor_tickets where vt_group_no ="' . $vgn . '"  ')->result_array();
            $logs['vt_data-'.$queue_data['action']."_".$queue_data['visitor_group_no']] = $final_visitor_data_to_insert_big_query_transaction_specific;
            $final_visitor_data_to_insert_big_query = array();
            $i = 0;
            foreach ($final_visitor_data_to_insert_big_query_transaction_specific as $final_visitor_data_transaction_rows_to_insert_big_query) {
                $final_visitor_data_to_insert_big_query = array_merge($final_visitor_data_to_insert_big_query, $final_visitor_data_transaction_rows_to_insert_big_query);
                $i++;
            }

            $final_visitor_data_to_insert_big_query_json = json_encode($final_visitor_data_to_insert_big_query_transaction_specific);
            $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress($final_visitor_data_to_insert_big_query_json));
            if (SERVER_ENVIRONMENT == 'Local') {
                local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
            } else {
                $agg_vt_insert_queueUrl = AGG_VT_INSERT_QUEUEURL;
                // This Fn used to send notification with data on AWS panel. 
                $MessageId = $sqs_object->sendMessage($agg_vt_insert_queueUrl, $final_visitor_data_to_insert_big_query_compressed);
                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                $err = '';
                if ($MessageId) {
                    $err = $sns_object->publish($agg_vt_insert_queueUrl, AGG_VT_INSERT_ARN);
                }
            }
            /**code for aggvt day table end here */


            //BOC to send email for api orders for dinner train supplier tickets
            $channel_type_array = array(4, 6, 7, 8);
            $send_email_for_api_orders = 0;
            if (isset($data['prepaid_tickets_data'][0]['channel_type']) && in_array($data['prepaid_tickets_data'][0]['channel_type'], $channel_type_array) && isset($data['prepaid_tickets_data'][0]['museum_id']) && $data['prepaid_tickets_data'][0]['museum_id'] == DINNER_TRAIN_MUSEUM) {
                $send_email_for_api_orders = 1;
            }

            $is_web_widget_dynamic_order = 0;
            $is_city_sight_website_order = 0;

            $city_sight_website_distributor_ids = json_decode(CITY_SIGHT_WEBSITE_DISTRIBUTOR_IDS, TRUE);
            if (isset($data['prepaid_tickets_data'][0]['hotel_id']) && in_array($data['prepaid_tickets_data'][0]['hotel_id'], $city_sight_website_distributor_ids)) {
                $is_city_sight_website_order = 1;
            }

            $extra_booking_information = !empty($data['prepaid_tickets_data'][0]["extra_booking_information"]) ? json_decode($data['prepaid_tickets_data'][0]["extra_booking_information"], true) : [];
            $order_custom_fields = !empty($extra_booking_information["order_custom_fields"]) ? $extra_booking_information["order_custom_fields"] : array(); 

            $spos_pay_by_link = false;
            $spos_send_pdf_by_mail = false;
            if (!empty($order_custom_fields)) {
                foreach($order_custom_fields as $order_custom_field) {
                    if (isset($order_custom_field["custom_field_name"])
                        && isset($order_custom_field["custom_field_value"])
                        && $order_custom_field["custom_field_name"] == "spos_pay_by_link"
                        && $order_custom_field["custom_field_value"] == "1"                
                    ) {
                        $spos_pay_by_link = true;
                    }
                    if (isset($order_custom_field["custom_field_name"])
                        && isset($order_custom_field["custom_field_value"])
                        && $order_custom_field["custom_field_name"] == "send_pdf_by_mail"
                        && $order_custom_field["custom_field_value"] == "1"                
                    ) {
                        $spos_send_pdf_by_mail = false;
                    }
                }
            }

            if (!empty($data['prepaid_tickets_data'][0]["extra_booking_information"]) && isset($data['prepaid_tickets_data'][0]['hotel_id']) && in_array($data['prepaid_tickets_data'][0]['hotel_id'], json_decode(NO_COMMON_EMAIL_DISTRIBUTORS, TRUE))) {
                $extra_booking_information = json_decode($data['prepaid_tickets_data'][0]["extra_booking_information"], true);
                $order_custom_fields = !empty($extra_booking_information["order_custom_fields"]) ? $extra_booking_information["order_custom_fields"] : array();

                if (!empty($order_custom_fields)) {
                    foreach($order_custom_fields as $order_custom_field) {
                        if (isset($order_custom_field["custom_field_name"])
                            && isset($order_custom_field["custom_field_value"])
                            && $order_custom_field["custom_field_name"] == "is_widget_order"
                            && $order_custom_field["custom_field_value"] == "1"                
                        ) {
                            $is_web_widget_dynamic_order = 1;
                        }

                    }
                }
            }

            $this->CreateLog('issue_log.php', $vgn, array('queueUrl : ' => $queueUrl, 'Handler : ' => $message['ReceiptHandle']));

            //send an email to guest in case of website order
            if ((isset($data['website_order_data']) && !empty($data['website_order_data'])) || ($send_email_for_api_orders == 1 || $is_city_sight_website_order == 1)) {
                $this->CreateLog('a_website.php', $vgn, array('step 1 : ' => 'entry', 'website_order : ' => $web_site_order_data['is_website_order']));
                $web_site_order_data = $data['website_order_data'];
                if ((isset($web_site_order_data['is_website_order']) && $web_site_order_data['is_website_order'] == "1") || ($send_email_for_api_orders == 1 || $is_city_sight_website_order == 1)) {

                    $visitor_group_no = $vgn;

                    $aws_data = array();
                    $aws_data['visitor_group_number'] = !empty($visitor_group_no) ? $visitor_group_no : 0;
                    $aws_data['is_city_sight_website_order'] = $is_city_sight_website_order;
                    if ($send_email_for_api_orders == 1) {
                        $aws_data['send_email_for_api_orders'] = $send_email_for_api_orders;
                    }
                    $aws_message = base64_encode(gzcompress(json_encode($aws_data)));

                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    $MessageId = $sqs_object->sendMessage(WEBSITE_EMAIL_QUEUE_URL, $aws_message);
                    if ($MessageId) {
                        $sns_object->publish('hello', WEBSITE_EMAIL_TOPIC_ARN);
                    }
                    $this->CreateLog('a_website.php', $vgn, array('step 2 : ' => 'entry', 'MessageId : ' => $MessageId));
                    // Create ticket on fresh desk for azimuth event 
                    if (SERVER_ENVIRONMENT == 'LIVE' && $data['prepaid_tickets_data'][0]['hotel_id'] == UAT_WINTER_AT_TANTORA){
                        $this->CreateLog('a_web.php', $vgn, array('step0 : ' => 'entry' ) );
                        $this->load->library('watfreshdesk');
                        $this->CreateLog('a_web.php', $vgn, array('step0a : ' => 'entry' ) );
                        $result = $this->watfreshdesk->create_ticket_on_fresh_desk_for_azimuth_event($data['prepaid_tickets_data'][0]['visitor_group_no'], $data['prepaid_tickets_data']);
                        $this->CreateLog('a_web.php', $vgn, array('res : ' => json_encode($result) ) );
                    }

                }
            } else if ($is_web_widget_dynamic_order == 1) {
                $this->CreateLog('a_website.php', $vgn, array('step 1 : ' => 'is_web_widget_dynamic_order', 'is_web_widget_dynamic_order : ' => $is_web_widget_dynamic_order));
                $visitor_group_no = $vgn;

                $aws_data = array();
                $aws_data['visitor_group_number'] = !empty($visitor_group_no) ? $visitor_group_no : 0;
                $aws_message = base64_encode(gzcompress(json_encode($aws_data)));

                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $MessageId = $sqs_object->sendMessage(WEB_WIDGET_DYNAMIC_EMAIL_QUEUE_URL, $aws_message);
                if ($MessageId) {
                    $sns_object->publish('hello', WEB_WIDGET_DYNAMIC_EMAIL_TOPIC_ARN);
                }
            } else if (!empty($data['prepaid_tickets_data'][0]['hotel_id'])
                && !in_array($data['prepaid_tickets_data'][0]['hotel_id'], json_decode(NO_COMMON_EMAIL_DISTRIBUTORS, TRUE))) {
                $sopos_common_email = false;
                $this->CreateLog('common_email.php', $vgn, array('step 1 : ' => 'prepaid data', 'prepaid_data : ' => json_encode($data['prepaid_tickets_data'][0]))); 
                if ((isset($data['prepaid_tickets_data'][0]['channel_type']) && in_array((string) $data['prepaid_tickets_data'][0]['channel_type'], ["0","2","20"], true))
                    && (empty(NO_COMMON_EMAIL_SPOS_RESELLERS) || !in_array($data['prepaid_tickets_data'][0]['reseller_id'], NO_COMMON_EMAIL_SPOS_RESELLERS))
                    && $spos_send_pdf_by_mail
                ) {
                    $this->CreateLog('common_email.php', $vgn, array('step 1 : ' => 'prepaid data', 'prepaid_data : ' => "yes")); 
                    $sopos_common_email = true;                   
                }              
                
                if (SERVER_ENVIRONMENT != 'Local'   
                    && ((isset($data['prepaid_tickets_data'][0]['channel_type']) && in_array($data['prepaid_tickets_data'][0]['channel_type'], COMMON_EMAIL_CHANNELS))
                        || $sopos_common_email)
                ) {
                        $common_email_request_data = [
                            "data" => [
                                "notification" => [
                                    "notification_event" => "ORDER_CREATE",
                                    "notification_item_id" => [
                                        "order_reference" =>  $vgn
                                    ]
                                ]
                            ]
                        ];
                        $this->CreateLog('common_email.php', $vgn, array('step 1 : ' => 'entry', 'common_email_request_data : ' => json_encode($common_email_request_data))); 
                        $common_email_request_message = base64_encode(gzcompress(json_encode($common_email_request_data)));                                
                        $this->load->library('Sqs');
                        $sqs_object = new Sqs();
                        $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_email_request_message);
                        if ($MessageId) {
                            $sns_object->publish('hello', COMMON_EMAIL_TOPIC_ARN);
                        }
                }

            }

            

            /* Start function Send Booking Confirmation Email in queue from Prioticket Distributor API 3.2 */
            if (SERVER_ENVIRONMENT != 'Local' && !empty($data['api_version_booking_email']) && $data['api_version_booking_email'] == "yes" && !empty($data['prepaid_tickets_data'][0]['extra_booking_information'])) {
                $extra_booking_information_data = json_decode($data['prepaid_tickets_data'][0]['extra_booking_information'], true);
                if (!empty($extra_booking_information_data) && (isset($data['is_full_paymet_done']) || !empty($data['is_full_paymet_done']))) {
                    $this->load->model('api_model');
                    $this->api_model->api_booking_email_queue($extra_booking_information_data, $data);
                }
            }
            /* End function Send Booking Confirmation Email in queue from Prioticket Distributor API 3.2 */

            /* Start function Send Booking Confirmation Email in queue for pay by link orders */               
            if ((isset($extra_booking_information['paid_by_link']) && $extra_booking_information['paid_by_link'] == "TRUE") || $spos_pay_by_link) {
                $this->send_pay_by_link_email($extra_booking_information, $data['prepaid_tickets_data'][0]);
            }

            /* End function Send Booking Confirmation Email in queue for pay by link orders */

            if ($inserted_flag) {
                // It remove message from SQS queue for next entry.
                if (SERVER_ENVIRONMENT != 'Local') {
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                }

                // Send data in RDS queue realtime
                if (SYNCH_WITH_RDS_REALTIME) {
                    $sqs_object->sendMessage(RDS_QUEUE_URL_REALTIME, $message['Body']);
                    $sns_object->publish('RDS Realtime Insert', RDS_TOPIC_ARN_REALTIME);
                }
            }
            if (!empty($sns_messages)) {
                $request_array['db1'] = $sns_messages;
                $request_array['visitor_group_no'] = $vgn;
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $update_queueUrl = UPDATE_DB_QUEUE_URL;
                $MessageId = $sqs_object->sendMessage($update_queueUrl, $aws_message);
                $err = '';
                if ($MessageId) {
                    $err = $sns_object->publish($update_queueUrl, UPDATE_DB_ARN);
                }
            }
            if (!empty($data['prepaid_tickets_data'])) {
                $distributor_ids = array_column($data['prepaid_tickets_data'], 'hotel_id');
                $supplier_ids = array_column($data['prepaid_tickets_data'], 'museum_id');
                $supplier_ids = implode(",",$supplier_ids);
                $distributor_ids = implode(",",$distributor_ids);
            }
            /* BOC sync data on elastic cache */
            if( !empty( $vgn ) && ENABLE_ELASTIC_SEARCH_SYNC == 1 ) {
                $other_data = [];
                $other_data['request_reference'] = $vgn;
                $other_data['hotel_id'] = !empty($data['prepaid_tickets_data'][0]['hotel_id']) ? $data['prepaid_tickets_data'][0]['hotel_id'] : '';
                $other_data['fastly_purge_action'] = 'ORDER_CREATE';
                $this->elastic_search->sync_data($vgn, $other_data);
            } elseif(!empty($vgn) && !empty($data['prepaid_tickets_data'][0]['hotel_id'])) {
                /* Start code to purge keys as per the distributer on fastly */
                $fastlykey = 'order/account/' . $data['prepaid_tickets_data'][0]['hotel_id'];
                $request_reference = $vgn;
                $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, 'ORDER_CREATE');
                /* end code to purge keys as per the distributer on fastly */
            }
            /* EOC sync data on elastic cache */
            
            /* BOC Notifying through api3.3 */
            if (!empty($messages['notify_api_array']) && $messages['notify_api_flag'] > 0) {
                $is_amend_order = 0;
                if (isset($data['uncancel_order']) && $data['uncancel_order'] == 1) {
                    $is_amend_order = 1;
                }
                $this->load->model('api_model');
                if ((empty($data['action'])) || (!empty($data['action']) && $data['action'] != 'send_email_during_correction')) {
                    $this->api_model->sendOrderProcessNotification($messages['notify_api_array'], $is_amend_order, 0, '', $data['cashier_type'] ?? 1);
                }
            }
            /* EOC Notifying through api3.3 */


            /** code start to publish visitor data to insert in aggregate bigquery table */
            if (!empty($final_visitor_data_to_insert_big_query_transaction_specific)) {
                $final_visitor_data_to_insert_big_query = array();
                foreach ($final_visitor_data_to_insert_big_query_transaction_specific as $final_visitor_data_transaction_rows_to_insert_big_query) {
                    $final_visitor_data_to_insert_big_query = array_merge($final_visitor_data_to_insert_big_query, $final_visitor_data_transaction_rows_to_insert_big_query);
                }
                $final_visitor_data_to_insert_big_query_json = json_encode($final_visitor_data_to_insert_big_query);

                $this->CreateLog('big_query_agg_vt_insertion.php', 'big_query_agg_vt_insertion', array("big_query_agg_vt_insertion" =>  json_encode($final_visitor_data_to_insert_big_query_json)));
                $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress($final_visitor_data_to_insert_big_query_json));
                if (SERVER_ENVIRONMENT == 'Local') {
                    local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
                }
            }
            /** code end to publish visitor data to insert in aggregate bigquery table */
            /* BOC Insertion in bigquery prepaid ticket */
            if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == strstr($_SERVER['HTTP_HOST'], '10.10.10.')) {
                /*** Commented for local server 
                local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIG_QUERY_PT_INSERT_TOPIC');
                ***/
            } else {
                if (!empty($final_prepaid_data_to_insert_big_query)) {
                    $this->CreateLog('big_query_pt_insertion.php', 'big_query_pt_insertion', array("big_query_pt_insertion" =>  json_encode($final_prepaid_data_to_insert_big_query)));
                    $final_prepaid_data_to_insert_big_query_json = json_encode($final_prepaid_data_to_insert_big_query);
                    $final_prepaid_data_to_insert_big_query_compressed = base64_encode(gzcompress($final_prepaid_data_to_insert_big_query_json));

                    $pt_insert_queueUrl = PT_INSERT_QUEUEURL;
                    $MessageId = $sqs_object->sendMessage($pt_insert_queueUrl, $final_prepaid_data_to_insert_big_query_compressed);
                    if ($MessageId) {
                        $sns_object->publish($pt_insert_queueUrl, PT_INSERT_ARN);
                    }
                }
            }
            /* EOC Insertion in big query prepaid ticket */
        } 
        else {
            $cnt = $this->secondarydb->rodb->query('select count(id) as cnt from visitor_tickets where row_type = "1" and visitor_group_no = "' . $vgn . '"')->row()->cnt;
            if ($cnt == (count($data['prepaid_tickets_data']) + count($data['shop_products_data']))) {
                $sqs_object->deleteMessage($main_queueUrl, $message['ReceiptHandle']);
            } else {
                $sqs_object->sendMessage(INSERT_IN_DB_ERROR_QUEUE_URL, $message['Body']);
                $sqs_object->deleteMessage($main_queueUrl, $message['ReceiptHandle']);
            }
        }

         /* Here we are using facebook conversion api */
         if (in_array($data['prepaid_tickets_data'][0]['hotel_id'], ARENA_HOTEL_ID)) {
            $facebook_conversion_api = [
                "data" => [
                    "notification" => [
                        "notification_event" => "FACEBOOK_CONVERSION",
                        "notification_item_id" => [
                            "order_reference" => $vgn
                        ]
                    ]
                ]
            ];
            $this->CreateLog('facebook_conversion_api.php', $visitor_group_no, array('step 1 : ' => 'entry', 'facebook_conversion_data : ' => json_encode($facebook_conversion_api))); 
            $facebook_conversion_request_message = base64_encode(gzcompress(json_encode($facebook_conversion_api)));                                
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            $MessageId = $sqs_object->sendMessage(FACEBOOK_CONVERSION_QUEUE_URL, $facebook_conversion_request_message);
            if ($MessageId) {
                $sns_object->publish('hello', FACEBOOK_CONVERSION_TOPIC_ARN);
            }
        }
        
        /** Send Data to notifiaction Queue TO Purge Data on Fastly */
        if (!empty($vgn)) {
            $this->notification_model->purgeFastlyNotifiaction($vgn, 'ORDER_CREATE', $distributor_ids, $supplier_ids);
        }
    }
    /* #endregion  To process the data for insertion in secondary DB */

    /* #region insert_in_db_direct on local. Same as insert_in_db for queues */
    /**
     * insert_in_db_direct
     *
     * @return void
     */
    function insert_in_db_direct() {
        // Fetch the raw POST body containing the message
        $postBody = file_get_contents('php://input');

        $string = $postBody;
        $this->CreateLog('queue_log_json_data.php', $string, array());
        $string = gzuncompress(base64_decode($string));
        $this->CreateLog('queue_log.php', $string, array());
        $string = utf8_decode($string);
        $data = json_decode($string, true);
        $distributor_ids = $supplier_ids = '';
        // To check record exist or not in Secondary DB.
        if (isset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'])) {
            if ($data['hotel_ticket_overview_data'][0]['existingvisitorgroup'] == 1) {
                $hto_id = '';
            }
            unset($data['hotel_ticket_overview_data'][0]['existingvisitorgroup']);
        } else {
            $hto_id = $this->secondarydb->db->get_where("hotel_ticket_overview", array("visitor_group_no" => $data['hotel_ticket_overview_data'][0]['visitor_group_no']))->row()->id;
        }
        if ((isset($data['uncancel_order']) && ($data['uncancel_order'] == 1)) || (!$hto_id || $hto_id == '' || isset($data['add_to_prioticket']) || isset($data['is_manual_payment']) && $data['is_manual_payment'] == '1') || 1) {
            // Main Method of Model to insert data in Secondaty DB [hotel_ticket_overview, prepaid_ticket, visitor_ticket, prepaid_extra_options Tables].
            $sns_messages = $this->order_process_model->insertdata($data);

            /* BOC sync data on elastic cache */
            if (!empty($data['prepaid_tickets_data'])) {
                $distributor_ids = array_column($data['prepaid_tickets_data'], 'hotel_id');
                $supplier_ids = array_column($data['prepaid_tickets_data'], 'museum_id');
                $supplier_ids = implode(",",$supplier_ids);
                $distributor_ids = implode(",",$distributor_ids);
            }
            $vgn = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];

            /* Select data and sync on BQ */
            $this->order_process_model->getVtDataToSyncOnBQ($vgn, "order_process/insert_in_db_direct");
            
            /* BOC sync data on elastic cache */
            if (!empty($vgn) && ENABLE_ELASTIC_SEARCH_SYNC == 1) {
                $other_data = [];
                $other_data['request_reference'] = $vgn;
                $other_data['hotel_id'] = !empty($data['prepaid_tickets_data'][0]['hotel_id']) ? $data['prepaid_tickets_data'][0]['hotel_id'] : '';
                $other_data['fastly_purge_action'] = 'ORDER_CREATE';
                $this->elastic_search->sync_data($vgn, $other_data);
            } elseif(!empty($vgn) && !empty($data['prepaid_tickets_data'][0]['hotel_id'])) {
                /* Start code to purge keys as per the distributer on fastly */
                $fastlykey = 'order/account/' . $data['prepaid_tickets_data'][0]['hotel_id'];
                $request_reference = $vgn;
                $this->purgefastly->purge_fastly_cache($fastlykey, $request_reference, 'ORDER_CREATE');
                /* end code to purge keys as per the distributer on fastly */
            }
            /* EOC sync data on elastic cache */
            
            if (isset($data['order_contacts'])) {
                $order_contacts = $data['order_contacts'];
                $this->order_process_model->contact_add($order_contacts, $data['hotel_ticket_overview_data'][0]['visitor_group_no']);
            }
            if (!empty($data['insert_paid_payment_detail'])) {
                $data_new = $data;
                if(isset($data_new['booking_level_order_payment_details'])){
                    unset($data_new['booking_level_order_payment_details']);
                }
                $this->order_process_model->add_payments($data['insert_paid_payment_detail'], 1, $data_new);
            }
            if (!empty($data['order_payment_details'])) {
                $this->order_process_model->add_payments($data['order_payment_details'], 1, $data);
            }
            if (!empty($data['prepaid_tickets_data'][0]['hotel_id']) && !empty($data['prepaid_tickets_data'][0]['visitor_group_no'])) {
                $delete_where_bod = array(
                    'visitor_group_no' => $data['prepaid_tickets_data'][0]['visitor_group_no'],
                    'distributor_id' => $data['prepaid_tickets_data'][0]['hotel_id']
                );
                $this->order_process_model->deleteBlockedRecord($delete_where_bod);
                $delete_where_bop = array(
                    'visitor_group_no' => $data['prepaid_tickets_data'][0]['visitor_group_no'],
                    'payment_category' => '3'
                );
                $this->order_process_model->deleteBlockedPaymentRecord($delete_where_bop);
            }
            /* Start function Send Booking Confirmation Email in queue from Prioticket Distributor API 3.2 */
            if (!empty($data['api_version_booking_email']) && $data['api_version_booking_email'] == "yes" && !empty($data['prepaid_tickets_data'][0]['extra_booking_information'])) {
                $extra_booking_information_data = json_decode($data['prepaid_tickets_data'][0]['extra_booking_information'], true);
                if (!empty($extra_booking_information_data) && (isset($data['is_full_paymet_done']) && !empty($data['is_full_paymet_done']))) {
                    $this->load->model('api_model');
                    $this->api_model->api_booking_email_queue($extra_booking_information_data, $data);
                }
            }
            /* End function Send Booking Confirmation Email in queue from Prioticket Distributor API 3.2 */
             /* Notifying through api3.3 */
            if (!empty($sns_messages['notify_api_array']) && $sns_messages['notify_api_flag'] > 0) {
                $is_amend_order = 0;
                if (isset($data['uncancel_order']) && $data['uncancel_order'] == 1) {
                    $is_amend_order = 1;
                }
                $this->load->model('api_model');
                $this->api_model->sendOrderProcessNotification($sns_messages['notify_api_array'], $is_amend_order, 0, '', $data['cashier_type'] ?? 1);
            }
            /** Send Data to notifiaction Queue TO Purge Data on Fastly */
            if (!empty($vgn)) {
                $this->notification_model->purgeFastlyNotifiaction($vgn, 'ORDER_CREATE', $distributor_ids, $supplier_ids);
            }
        }
    }
    /* #endregion insert_in_db_direct*/
    
    /* #region to send alert in email if data struck in queue from long time  */
    /**
     * queue_alerts
     *
     * @param  mixed $messages
     * @param  mixed $queue_name
     * @param  mixed $db
     * @param  mixed $update_query
     * @return void
     * @author Pardeep Kumar
     */
    function queue_alerts($messages = array(),$queue_name = '',$db = 1, $update_query = 0) {
        if($update_query == 0) {
            $html = '';
            $msg = 0;
            $send_mail = 0;
            $queue_alert_time = QUEUE_ALERT_TIME;
            $html .= '<table>';
            $this->CreateLog('queue_alerts_messages_from_sqs_processor.php','Messages_data',array('Messages' => json_encode($messages)));
            foreach($messages as $message) {
                $attributes = $message['Attributes'];
                $sent_time = floor($attributes['SentTimestamp']/1000);
                $interval = strtotime($queue_alert_time);
                if(0 && $sent_time < $interval) {
                    $msg_body = $message['Body'];
                    $string = gzuncompress(base64_decode($msg_body));
                    $string = utf8_decode($string);
                    $string = str_replace("?", "", $string);
                    $main_request = json_decode($string, true);
                    $this->CreateLog('queue_alerts_messages_from_sqs_processor.php','Main_request',array('Request' => json_encode($main_request)));
                    $booking_id = $distributor_id = $distributor_name = $product_id = $product_title = $admin_id = $admin_name = '';
                    $headings = ['Booking Id','Distributor Id','Distributor Name','Product Id','Product Title','Admin Id','Admin Name'];
                    if(isset($main_request['prepaid_tickets_data'])) {
                        $booking_id = $main_request['prepaid_tickets_data'][0]['visitor_group_no'];
                        $distributor_id = $main_request['prepaid_tickets_data'][0]['hotel_id'];
                        $distributor_name = $main_request['prepaid_tickets_data'][0]['hotel_name'];
                        $product_id = $main_request['prepaid_tickets_data'][0]['ticket_id'];
                        $product_title = $main_request['prepaid_tickets_data'][0]['title'];
                        $museum_id = $main_request['prepaid_tickets_data'][0]['museum_id'];
                    }
                    if(isset($museum_id) && $museum_id != '') {
                        $admin_data = $this->common_model->getmultipleFieldValueFromTable('reseller_id,reseller_name', 'qr_codes', array('cod_id' => $museum_id),'0', 'row_array');
                        $admin_id = $admin_data['reseller_id'];
                        $admin_name = $admin_data['reseller_name'];
                    }
                    $values_array = [$booking_id,$distributor_id,$distributor_name,$product_id,$product_title,$admin_id,$admin_name];
                    if($msg == 0) {
                        $html .= '<tr>';
                        foreach($headings as $val) {
                            $html .= '<th>'.$val.'</th>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '<tr>';
                    foreach($values_array as $value) {
                        $html .= '<td>'.$value.'</td>';
                    }
                    $html .= '</tr>';
                    $msg++;
                    $send_mail = 1;
                }
            }
            $html .= '</table>';
            if($send_mail == 1) {
                $this->CreateLog('queue_alerts_messages_from_sqs_processor.php','Email_data',array('Html_data' => $html));
                $que_name = substr($queue_name, strrpos($queue_name, '/') + 1);
                if($db == 1) {
                    $db_is = ' Primary_db_queue';
                } else {
                    $db_is = ' Secondary_db_queue';
                }
                $subject = $msg.' Messages are stuck in '.$que_name.' queue'.$db_is;
                $sendmsg = 'The following bookings are in queue<br>';
                $sendmsg .= $html;
                $attachments = array();
                //$arraylist['emailc']        = 'pardeepku.aipl@gmail.com'
                $arraylist['emailc']        = 'rattan.intersoft@gmail.com';
                $arraylist['BCC']           = array('pardeepku.aipl@gmail.com','sarita.intersoft@gmail.com');
                $arraylist['html'] = $sendmsg;
                $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
                $arraylist['fromname'] = 'Queue Alert';
                $arraylist['subject']  = $subject;
                $arraylist['attachments'] = $attachments;
                $this->load->model('Sendemail_model');   
                $this->CreateLog('queue_alerts_messages_error.php','Step',array('Working' => '1'));   
                $this->Sendemail_model->sendemailtousers($arraylist);
            }
        } else {
            $this->CreateLog('queue_alert_update_queue_data.php','Query_Data',array('Data' => json_encode($messages)));
            $html = '';
            $msg = 0;
            $send_mail = 0;
            $queue_alert_time = QUEUE_ALERT_TIME;
            $html .= '<table>';
            foreach($messages as $message) {
                $attributes = $message['Attributes'];
                $sent_time = floor($attributes['SentTimestamp']/1000);
                $interval = strtotime($queue_alert_time);
                if(0 && $sent_time < $interval) {
                    $msg_body = $message['Body'];
                    $string = gzuncompress(base64_decode($msg_body));
                    $string = utf8_decode($string);
                    $string = str_replace("?", "", $string);
                    $main_request = json_decode($string, true);
                    if(isset($main_request['db2'])) {
                        $database = 'db2';
                    } else if(isset($main_request['DB2'])) {
                        $database = 'DB2';
                    } else if(isset($main_request['db1'])) {
                        $database = 'db1';
                    } else {
                        $database = 'DB1';
                    }
                    $this->CreateLog('queue_alerts_messages_from_sqs_processor.php','Main_request',array('Request' => json_encode($main_request)));
                    $headings_array = array('visitor_group_no' => 'Booking Id','hotel_id' => 'Distributor Id','hotel_name' => 'Distributor Name','ticket_id' => 'Product Id','title' => 'Product Title','admin_id' => 'Admin Id','admin_name' => 'Admin Name','database' => 'Database');
                    if(isset($main_request[$database])) {
                        foreach($main_request[$database] as $query) {
                            $value['hotel_id'] = $value['visitor_group_no'] = $value['hotel_name'] = $value['ticket_id'] = $value['title'] = $value['admin_id'] = $value['admin_name'] = $value['database'] = '';
                            if(strpos($query, 'prepaid_tickets')) {
                                $send_mail = 1;
                                $where  = explode('WHERE',$query);
                                $and = explode('AND',$where[1]);
                                foreach($and as $fields) {
                                    if(strpos($fields,'hotel_id')) {
                                        $data = explode('=',$fields);
                                        $value['hotel_id'] = str_replace("'","",$data[1]);
                                    }
                                    if(strpos($fields,'visitor_group_no')) {
                                        $data = explode('=',$fields);
                                        $value['visitor_group_no'] = str_replace("'","",$data[1]);
                                    }
                                    if(strpos($fields,'hotel_name')) {
                                        $data = explode('=',$fields);
                                        $value['hotel_name'] = str_replace("'","",$data[1]);
                                    }
                                    if(strpos($fields,'ticket_id')) {
                                        $data = explode('=',$fields);
                                        $value['ticket_id'] = str_replace("'","",$data[1]);
                                    }
                                    if(strpos($fields,'title')) {
                                        $data = explode('=',$fields);
                                        $value['title'] = str_replace("'","",$data[1]);
                                    }
                                }
                                $value['database'] = $database;
                                $html_feilds[] = $value;
                            }
                        }
                    }
                }
                if($send_mail == 1 && $msg == 0) {
                    $html .= '<tr>';
                        foreach($headings_array as $heading) {
                            $html .= '<th>'.$heading.'</th>';
                        }
                    $html .= '</tr>';
                    /***
                     * Loop on uninitialized value 
                        foreach($html_fields as $field) {
                            $html .= '<tr>';
                            foreach($headings_array as $key => $val) {
                                $html .= '<td>'.$field[$key].'</td>';
                            }
                            $html .= '</tr>';
                        }
                    ***/
                }
                $msg++;
            } //messages loop
            $html .= '</table>';
            if($send_mail == 1) {
                $this->CreateLog('queue_alerts_update_messages_from_sqs_processor.php','Email_data',array('Html_data' => $html));
                $que_name = substr($queue_name, strrpos($queue_name, '/') + 1);
                $subject = $msg.' Messages are stuck in '.$que_name.' Update Queue';
                $sendmsg = 'The following bookings are in queue<br>';
                $sendmsg .= $html;
                $attachments = array();
                //$arraylist['emailc']        = 'pardeepku.aipl@gmail.com'
                $arraylist['emailc']        = 'rattan.intersoft@gmail.com';
                $arraylist['BCC']           = array('pardeepku.aipl@gmail.com','sarita.intersoft@gmail.com');
                $arraylist['html'] = $sendmsg;
                $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
                $arraylist['fromname'] = 'Queue Alert';
                $arraylist['subject']  = $subject;
                $arraylist['attachments'] = $attachments;
                $this->load->model('Sendemail_model');   
                $this->CreateLog('queue_alerts_update_messages_error.php','Step',array('Working' => '1'));   
                $this->Sendemail_model->sendemailtousers($arraylist);
            }
        }
    }
    /* #endregion */

    /* #region to send passes and order confirm email for the orders made through pay by link  */
    /**
     * To send passes and order confirm email.
     *
     * @param  mixed $booking_data
     * @param  mixed $prepaid_data
     * @return void
     */
    function send_pay_by_link_email($booking_data, $prepaid_data) {
        $email_data['visitor_group_no'] = $prepaid_data['visitor_group_no'];
        $email_data['activation_pass_no'] = '';
        $email_data['hotel_id'] = $prepaid_data['hotel_id'];
        $email_data['museum_id'] = '';

        // to send pdf in email through sns.
        if ($_SERVER['HTTP_HOST'] != 'localhost' && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.8.0.1') && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.10.10.') && !empty($email_data['email'])) {
            $aws_message = base64_encode(gzcompress(json_encode($email_data)));
            $queueUrl = QUEUE_URL_SEND_DPOS_EMAIL;
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
            if ($MessageId) {
                $sns_object = new Sns();
                $sns_object->publish($MessageId . '#~#' . $queueUrl, SNS_SEND_DPOS_EMAIL);
            }
        }
    }
    /* #endregion */
}
