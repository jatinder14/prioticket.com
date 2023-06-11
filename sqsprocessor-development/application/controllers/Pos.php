<?php 
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Pos extends MY_Controller
{
    /* #region  BOC of class Pos */
    var $base_url;

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct()
    {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('pos_model');
        $this->load->model('importbooking_model');
        $this->load->model('order_process_model');
        $this->load->model('order_process_vt_model');
        $this->load->library('log_library');
        $this->base_url = $this->config->config['base_url'];
        $this->LANGUAGE_CODES['en'] = 'english';
        $this->LANGUAGE_CODES['es'] = 'spanish';
        $this->LANGUAGE_CODES['nl'] = 'dutch';
        $this->LANGUAGE_CODES['de'] = 'german';
        $this->LANGUAGE_CODES['it'] = 'italian';
        $this->LANGUAGE_CODES['fr'] = 'french';
        define('EVAN_EVANS_RESELLER_ID', 541);
        $this->load->model('sendemail_model');
    }
    /* #endregion main function to load controller pos*/

    /* #region getActionWiseOrder  */
    /**
     * getActionWiseOrder
     *
     * @return void
     */
    function getActionWiseOrder()
    {
        // Load SQS library.
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();

        $queueUrl = 'https://sqs.eu-west-1.amazonaws.com/783885816093/sqs2big';
        $messages = $sqs_object->receiveMessage($queueUrl);
        $messages = $messages->getPath('Messages');
        $this->CreateLog('getActionWiseOrder.log', 'message1', array(
            "sqs_received_messages" => json_encode($messages)
        ));

        if (!empty($messages)) {
            foreach ($messages as $message) {
                $string = $message['Body'];
                $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                $string = gzuncompress(base64_decode($string));

                $string = utf8_decode($string);
                $messagedata = json_decode($string, true);
                $this->CreateLog('getActionWiseOrder.log', 'message2', array(
                    "sqs_received_messages" => json_encode($messagedata)
                ));

                if (!empty($messagedata)) {
                    sleep(3);
                }

                $results = $this->secondarydb->rodb->query('select * from prepaid_tickets where visitor_group_no ="' . $messagedata . '"  ')->result();
                $this->CreateLog('getActionWiseOrder.log', 'message3', array(
                    "sqs_received_messages" => json_encode($results)
                ));

                $aws_message2 = json_encode($results);
                $aws_message2 = base64_encode(gzcompress($aws_message2));
                $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                $this->CreateLog('pt2scan.php', $messagedata, array("data : " => $MessageIdss));
                if ($MessageIdss) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                }
                $this->CreateLog('getActionWiseOrder.log', 'message4', array(
                    "sqs_received_messages" => json_encode($results)
                ));

                /* Select data and sync on BQ */
                $this->order_process_model->getVtDataToSyncOnBQ($messagedata);
            }
        }
    }
    /* #endregion getActionWiseOrder*/

    /* #region test2  */
    /**
     * test2
     *
     * @return void
     */
    function test2()
    {
        include_once 'aws-php-sdk/aws-autoloader.php';

        include('sqss3/sqs.php');
        $sqs = new SqsS3();
        $url = 'https://sqs.eu-west-1.amazonaws.com/783885816093/pt2scan_group_booking_test';
        $messageToSend = 'this is a demo message';
        $sqs->sendMessage($url, $messageToSend, 'ALWAYS');
    }
    /* #endregion test2*/

    /* #endregion To move the data in main tables of seconday DB. */

    /* #region function To insert into RDS tables  */

    /**
     * insert_in_rds_db_realtime
     *
     * @return void
     * @author Pankaj Kumar <pankajk.dev@outlook.com> on 14 Feb, 2018
     */
    function insert_in_rds_db_realtime()
    {
        // Queue process check
        if (STOP_QUEUE) {
            exit();
        }
        // Queue process check
        if (STOP_RDS_INSERT_QUEUE) {
            exit();
        }
        $queueUrl = RDS_QUEUE_URL_REALTIME;

        // Load SQS library.
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();

        // It return Data from message.
        $messages = $sqs_object->receiveMessage($queueUrl);
        if ($messages->getPath('Messages')) {
            foreach ($messages->getPath('Messages') as $message) {
                $string = $message['Body'];
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $data = json_decode($string, true);
                // Note: delte code should be after the insertdata function, temporary moved to up because of hto empty data condition issue (all_ticket_ids case) for old data
                // It remove message from SQS queue for next entry.
                $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                $this->CreateLog('check_data_in_rds.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'_check_data_in_rds_'.date("H:i:s.u"), array("data" => json_encode($data)), $data['hotel_ticket_overview_data'][0]['channel_type']);                              
                // Main Method of Model to insert data in Secondaty DB [hotel_ticket_overview, prepaid_ticket, visitor_ticket, prepaid_extra_options Tables].
                if (isset($data['visitor_ticket_data'])) {
                    $counter = 0;
                    foreach ($data['hotel_ticket_overview_data'] as $hto_insert_data) {
                        $counter++;
                        $final_hto_data[] = $hto_insert_data;
                        if ($counter % 200 == 0) {
                            $this->pos_model->insert_batch('hotel_ticket_overview', $final_hto_data, '4');
                            $final_hto_data = array();
                        }
                    }

                    if (!empty($final_hto_data)) {
                        $this->pos_model->insert_batch('hotel_ticket_overview', $final_hto_data, '4');
                    }

                    $counter = 0;
                    foreach ($data['prepaid_ticket_data'] as $pt_insert_data) {
                        $counter++;
                        $final_pt_data[] = $pt_insert_data;
                        if ($counter % 200 == 0) {
                            $this->pos_model->insert_batch('prepaid_tickets', $final_pt_data, '4');
                            $final_pt_data = array();
                        }
                    }

                    if (!empty($final_pt_data)) {
                        $this->pos_model->insert_batch('prepaid_tickets', $final_pt_data, '4');
                    }

                    $counter = 0;
                    foreach ($data['visitor_ticket_data'] as $vt_insert_data) {
                        $counter++;
                        $final_vt_data[] = $vt_insert_data;
                        if ($counter % 200 == 0) {
                            $this->pos_model->insert_batch('visitor_tickets', $final_vt_data, '4');
                            $final_vt_data = array();
                        }
                    }

                    if (!empty($final_vt_data)) {
                        $this->pos_model->insert_batch('visitor_tickets', $final_vt_data, '4');
                    }
                } else {
                    $this->order_process_model->insertdata($data, "0", "4");
                }
            }
        }
    }

    /* #endregion function To insert into RDS tables */

    /* #region insert_in_table */

    /**
     * insert_in_table
     *
     * @param  mixed $queue_url
     * @return void
     */
    function insert_in_table($queue_url)
    {
        $queueUrl = 'https://sqs.eu-west-1.amazonaws.com/783885816093/' . $queue_url;
        echo '<br>' . $queueUrl . '<br>';
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        echo date("i:s") . '---';
        $messages = $sqs_object->receiveMessage($queueUrl);
        echo date("i:s") . '---';
        if ($messages->getPath('Messages')) {
            echo "<pre>";
            print_r($messages->getPath('Messages'));
            echo "</pre>";
            foreach ($messages->getPath('Messages') as $message) {
                $string_to_insert = $message['Body'];

                $string = gzuncompress(base64_decode($string_to_insert));
                $string = utf8_decode($string);
                $data = json_decode($string, true);
                $visitor_group_no = (isset($data['prepaid_tickets_data'][0]['visitor_group_no'])) ? $data['prepaid_tickets_data'][0]['visitor_group_no'] : '';
                $this->db->insert("aws_queue_data", array("data" => $string_to_insert, 'visitor_group_no' => $visitor_group_no));
                echo "<pre>";
                print_r($data);
                echo "</pre>";
                $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
            }
        }
    }

    /* #endregion insert_in_table */

    /* #region process_table  */

    /**
     * process_table
     *
     * @param  mixed $limit
     * @return void
     */
    function process_table($limit)
    {
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $results = $this->primarydb->db->query('select * from aws_queue_data where status = "0" limit ' . $limit)->result();
        foreach ($results as $res) {
            $this->db->update("aws_queue_data", array("status" => "1"), array("id" => $res->id));
            $string = $res->data;
            $string = gzuncompress(base64_decode($string));
            $string = utf8_decode($string);
            $data = json_decode($string, true);
            $vgn = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
            if (!$vgn || $vgn == "") {
                $vgn = $data['prepaid_tickets_data'][0]['visitor_group_no'];
            }
            echo "<pre>";
            print_r($data);
            echo "</pre>";
            $hto_all_data = $this->secondarydb->rodb->get_where("hotel_ticket_overview", array("visitor_group_no" => $vgn))->row();
            $hto_id = $hto_all_data->id;
            if (!$hto_id || $hto_id == "" || isset($data['add_to_prioticket'])) {
                $this->CreateLog('tracking_deleted.php', 're-inserted', array('data' => json_encode($data)));
                $messages = $this->order_process_model->insertdata($data);
                $inserted_flag = $messages['flag'];
                $sns_messages = $messages['sns_message'];
                if ($inserted_flag) {
                    $this->db->delete("aws_queue_data", array("id" => $res->id));
                    if (!empty($sns_messages)) {
                        $request_array['db1'] = $sns_messages;
                        $request_array['visitor_group_no'] = $vgn;
                        $request_string = json_encode($request_array);
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        $queueUrl = UPDATE_DB_QUEUE_URL;
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId) {
                            $this->load->library('Sns');
                            $sns_object = new Sns();
                            $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                        }
                    }
                }
                echo "deleted - <br>";
            } else {
                $cnt = $this->secondarydb->rodb->query('select count(id) as cnt from visitor_tickets where row_type = "1" and visitor_group_no = "' . $vgn . '"')->row()->cnt;
                if ($cnt == (count($data['prepaid_tickets_data']) + count($data['shop_products_data']))) {
                    $this->CreateLog('tracking_deleted.php', 'already in DB - directly deleted', array('data' => json_encode($data)));
                    $this->db->delete("aws_queue_data", array("id" => $res->id));
                    echo "deleted - <br>";
                } else {
                    if ($vgn) {
                        if ($hto_all_data->channel_type == 5) {
                            exit;
                        }
                        $this->CreateLog('tracking_deleted.php', 'half inserted, deleting and re-inserting', array('data' => json_encode($data)));
                        $this->secondarydb->db->query('delete from hotel_ticket_overview where visitor_group_no = "' . $vgn . '" and deactivatedBy = "0" and is_order_cancelled = 0');
                        $this->secondarydb->db->query('delete from prepaid_tickets where visitor_group_no = "' . $vgn . '" and activated = "1" and is_refunded = "0"');
                        $this->secondarydb->db->query('delete from prepaid_extra_options where visitor_group_no = "' . $vgn . '"');
                        $this->secondarydb->db->query('delete from visitor_tickets where vt_group_no = "' . $vgn . '" and deleted = "0"');
                    }
                    $this->CreateLog('process_table.php', 'Order reinserted', array("data" => $vgn));
                    $this->secondarydb->db->query('update temp_analytic_records set status = "0", operation= "Refunded" where visitor_group_no = "' . $vgn . '"');
                    // So while calling insertdata send another parameter 1
                    $messages = $this->order_process_model->insertdata($data, 1);
                    $inserted_flag = $messages['flag'];
                    $sns_messages = $messages['sns_message'];
                    if ($inserted_flag) {
                        $this->db->delete("aws_queue_data", array("id" => $res->id));
                        if (!empty($sns_messages)) {
                            $request_array['db1'] = $sns_messages;
                            $request_array['visitor_group_no'] = $vgn;
                            $request_string = json_encode($request_array);
                            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $queueUrl = UPDATE_DB_QUEUE_URL;
                            // This Fn used to send notification with data on AWS panel. 
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                            if ($MessageId) {
                                $this->load->library('Sns');
                                $sns_object = new Sns();
                                $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }
                        }
                    }
                    echo "deleted - <br>";
                }
            }
        }
    }

    /* #endregion process_table */


    /* #region update_api_records_manual  */

    /**
     * update_api_records_manual
     *
     * @return void
     */
    function update_api_records_manual()
    {
        $request = $_REQUEST;
        include_once 'aws-php-sdk/aws-autoloader.php';
        if (!empty($request) && isset($request['visitor_group_no']) && $request['visitor_group_no'] != '') {
            $request_pt_data = $request_vt_data = $request_id_data = $arena_notifying_arr = $old_order = [];
            $main_request_data = array(
                'visitor_group_no' => $request['visitor_group_no'],
                'action_from' => 'manual',
                'action' => !empty($request['action']) ? $request['action'] : "",
                'hotel_id' => $request['hotel_id'],
                'ticket_update' => $request['ticket_update'],
            );
            if (isset($request['pt']) && $request['pt'] != '' && $request['pt'] != 0) {
                $request_pt_data = array(
                    'pt' => $request['pt']
                );
            }
            if (isset($request['vt']) && $request['vt'] != '' && $request['vt'] != 0) {
                $request_vt_data = array(
                    'vt' => $request['vt']
                );
            }
            if (isset($request['prepaid_ticket_ids']) && $request['prepaid_ticket_ids'] != '' && isset($request['ticket_booking_id']) && $request['ticket_booking_id'] != '') {
                $request_id_data = array(
                    'prepaid_ticket_id' => $request['prepaid_ticket_ids'],
                    'ticket_booking_id' => $request['ticket_booking_id'],
                    'last_db1_prepaid_id' => $request['last_db1_prepaid_id'] != '' ? $request['last_db1_prepaid_id'] : 0
                );
            }
            /* check for arena notification */
            if (!empty($request['notify_to_arena']) && $request['notify_to_arena'] == '1') {
                $arena_notifying_arr['notify_to_arena'] = '1';
            }
            /*  */
            if (!empty($request['old_order']) && $request['old_order'] == '1') {
                $old_order['old_order'] = 'no';
            }
            $request_data = array_merge($main_request_data, $request_pt_data, $request_vt_data, $request_id_data, $arena_notifying_arr, $old_order);
            $this->load->model('api_model');
            $response = $this->api_model->insert_refunded_orders($request_data);
            echo "<pre>";
            print_r($response);
            echo "</pre>";
            exit;
        } else {
            echo 'not valid VGN';
            exit;
        }
    }
    /* #endregion update_api_records_manual*/

    /* #region SYNC_firebase  */
    /**
     * SYNC_firebase
     *
     * @param  mixed $hotel_id
     * @param  mixed $ticket_id
     * @param  mixed $exit
     * @param  mixed $q
     * @return void
     */
    function SYNC_firebase($hotel_id = '', $ticket_id = '', $exit = 0, $q = '')
    {
        if ($ticket_id == 0) {
            $ticket_id = '';
        }
        $this->pos_model->SYNC_firebase($hotel_id, $ticket_id, $exit, $q);
    }
    /* #endregion SYNC_firebase*/

    /* #region function To insert the data in VT table  */

    /**
     * correct_visitor_tickets_record
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $pass_no
     * @param  mixed $prepaid_ticket_id
     * @param  mixed $hto_id
     * @param  mixed $action_performed
     * @return void
     * @author Pankaj Kumar<pankajk.dev@outlook.com>
     */
    function correct_visitor_tickets_record($visitor_group_no = '', $pass_no = '', $prepaid_ticket_id = '', $hto_id = '', $action_performed = '')
    {
        if ($visitor_group_no != '' || $pass_no != '') {
            // need to delete order bases on VGN except channel type 5, for channel type 5 pass number need to be send in params
            if ($pass_no == '') {
                // Delete enteries from DB2/DB4
                $this->secondarydb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                $this->fourthdb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                echo 'Deleted from VT of DB2 and DB4 <br/>';
            }
            $this->pos_model->correct_visitor_tickets_record_model($visitor_group_no, $pass_no, $prepaid_ticket_id, $hto_id, $action_performed);
            echo 'Success';
        } else {
            echo "VGN or PassNo Not Found";
        }
    }

    /* #endregion function To insert the data in VT table */

    /* #region update_vt_directly  */

    /**
     * update_vt_directly
     *
     * @param  mixed $limit
     * @return void
     * @author Pankaj Kumar <pankajk.dev@outlook.com> on 3 April, 2018
     */
    function update_vt_directly($limit = '10')
    {
        // get data from bulk_updated_orders of DB4
        $query = 'select DISTINCT visitor_group_no from bulk_updated_orders where is_data_moved = 0 order by id ASC limit ' . $limit;
        $result = $this->primarydb->db->query($query);
        $data = $result->result_array();

        foreach ($data as $vt_num) {
            $visitor_group_no = $vt_num['visitor_group_no'];

            if (!empty($visitor_group_no)) {
                // Delete enteries from DB2/DB4
                $this->secondarydb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                $this->fourthdb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                echo 'Deleted from VT of DB2 and DB4 <br/>';

                $this->pos_model->correct_visitor_tickets_record_model($visitor_group_no);
                echo 'Done';
                echo '<br/>';
            }

            $this->db->query('update bulk_updated_orders set is_data_moved = 1 where visitor_group_no =' . $visitor_group_no);
        }
    }

    /* #endregion update_vt_directly */

    /* #region to insert data in all order table imported from import booking */

    /**
     * insert_batch_in_orders_tables
     *
     * @return void
     */
    public function insert_batch_in_orders_tables() {
        $postBody = file_get_contents('php://input');
        $this->CreateLog('subscription.php','data',array('data' => $postBody));
        global $insert_batch_in_orders_tables;
        $logs = array();
        $pt_table = (!empty(PT_TABLE) ? PT_TABLE : 'prepaid_tickets');
        $vt_table = 'visitor_tickets';

        if (ENABLE_PUBSUB) {
            $this->load->library('Gpubsub');
            $google_pub = new Gpubsub();

            $subscription = $google_pub->pull_messages(PUB_SUB_INSERT_ORDER_DATA_IMPORT_BOOKING_PULL_SUBSCRIPTION);
            foreach ($subscription->pull() as $message) {
                $messages[] = $message->data();
                // Acknowledge the Pub/Sub message has been received, so it will not be pulled multiple times.
                $subscription->acknowledge($message);
            }
        } else if (ENABLE_QUEUE) {
            $postBody = file_get_contents('php://input');
            $queueUrl = IMPORTBOOKING_ORDERS_QUEUE;
            if (SERVER_ENVIRONMENT != 'Local') {
                /* Load SQS library. */
                include_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $this->load->library('Sns');
                $sns_object = new Sns();
                // It return Data from message.
                $messagess = $sqs_object->receiveMessage($queueUrl);
                if ($messagess->getPath('Messages')) {
                    foreach ($messagess->getPath('Messages') as $message) {
                        $string = $message['Body'];
                        $string = gzuncompress(base64_decode($string));
                        $string = utf8_decode($string);
                        $received_messages[] = str_replace("?", "", $string);
                        $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    }
                    foreach ($received_messages as $data_arr) {
                        $messages[] = $data_arr;
                    }                      
                }
            } else {
                $string = gzuncompress(base64_decode($postBody));
                $string = utf8_decode($string);
                $messages[] = str_replace("?", "", $string);
            }
        } else {
            $postBody = file_get_contents('php://input');
            $messages[] = $postBody;
        }
        $logs['request_messages_' . date("Y-m-d H:i:s")] = $messages;
        $hotel_ticket_overview_data = array();
        $prepaid_tickets_data = array();

        $hotel_ticket_overview_data_chunks = array();
        $prepaid_tickets_data_chunks = array();

        $visitor_group_nos = array();
        $import_booking_orders_to_insert_in_vt_data = array();

        if (empty($messages)) {
            echo "no";
            exit;
        }

        foreach ($messages as $message) {
            $data = json_decode($message, true);
            if (empty($data)) {
                continue;
            }

            if (!empty($data["hotel_ticket_overview_data"])) {
                $hotel_ticket_overview_data = array_merge($hotel_ticket_overview_data, $data["hotel_ticket_overview_data"]);
            }

            if (!empty($data["prepaid_tickets_data"])) {
                $prepaid_tickets_data = array_merge($prepaid_tickets_data, $data["prepaid_tickets_data"]);
                $visitor_group_nos = array_merge($visitor_group_nos, array_column($data["prepaid_tickets_data"], "visitor_group_no"));
            }
        }

        $hotel_id = 0;

        $booking_information = '';
        $contact_information = '';
        $booking_details = '';
        $phone_number = '';
        $contact_details = '';


        if (!empty($hotel_ticket_overview_data)) {
            $hotel_ticket_overview_data_chunks = array_chunk($hotel_ticket_overview_data, 200);
            foreach ($hotel_ticket_overview_data_chunks as $hotel_ticket_overview_to_insert) {
                if (empty($hotel_ticket_overview_to_insert)) {
                    continue;
                }

                foreach ($hotel_ticket_overview_to_insert as $hotel_data) {

                    if (isset($hotel_data['existingvisitorgroup'])) {
                        unset($hotel_data['existingvisitorgroup']);
                    }
                    if ($hotel_data['roomNo'] == '') {
                        $hotel_data['roomNo'] = substr($hotel_data['visitor_group_no'], 9, 12);
                    }

                    if ($hotel_data['gender'] == '') {
                        $hotel_data['gender'] = 'Male';
                    }
                    $mainharray = array();
                    foreach ($hotel_data as $hkey => $hdata) {
                        if (isset($hdata['existingvisitorgroup'])) {
                            unset($hdata['existingvisitorgroup']);
                            continue;
                        }
                        if ($hdata !== '') {
                            $mainharray[$hkey] = $hdata;
                        }
                    }
                    if (isset($mainharray['booking_information']) && $mainharray['booking_information'] != '') {
                        $booking_information = $mainharray['booking_information'];
                    }
                    if (isset($mainharray['contact_information']) && $mainharray['contact_information'] != '') {
                        $contact_information = $mainharray['contact_information'];
                    }
                    if (isset($mainharray['booking_details']) && $mainharray['booking_details'] != '') {
                        $booking_details = $mainharray['booking_details'];
                    }
                    if (isset($mainharray['phone_number']) && $mainharray['phone_number'] != '') {
                        $phone_number = $mainharray['phone_number'];
                    }
                    if (isset($mainharray['contact_details']) && $mainharray['contact_details'] != '') {
                        $contact_details = $mainharray['contact_details'];
                    }

                    if (isset($mainharray['last_modified_at'])) {
                        unset($mainharray['last_modified_at']);
                    }

                    $final_hto_data[] = $mainharray;
                    $insertedId = $hotel_data['id'];
                    $hto_ids[$hotel_data['passNo']] = $insertedId;
                    $arrpass[] = $hotel_data['passNo'];

                    if ($hotel_id == 0) {
                        $details['hotel_ticket_overview'] = (object) $hotel_data;
                        $visitor_group_no = $hotel_data['visitor_group_no'];
                        $hotel_id = $hotel_data['hotel_id'];
                        $phone_number = isset($hotel_data['phone_number']) ? $hotel_data['phone_number'] : '';
                        $activation_method = $hotel_data['activation_method'];
                        $is_prioticket = $hotel_data['is_prioticket'];
                    }
                }

                $this->pos_model->insert_batch('hotel_ticket_overview', $hotel_ticket_overview_to_insert);
                $logs['hto_last_query_' . date("Y-m-d H:i:s")] = $this->primarydb->db->last_query();
                $this->pos_model->insert_batch('hotel_ticket_overview', $hotel_ticket_overview_to_insert, 1);
                // Send data in RDS queue realtime
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                    $this->pos_model->insert_batch('hotel_ticket_overview', $hotel_ticket_overview_to_insert, 4);
                }
            }
        }

        if (!empty($prepaid_tickets_data)) {
            $prepaid_tickets_data_chunks = array_chunk($prepaid_tickets_data, 200);
            foreach ($prepaid_tickets_data_chunks as $prepaid_tickets_data_to_insert) {
                if (empty($prepaid_tickets_data_to_insert)) {
                    continue;
                }

                if (!empty($details['prepaid_tickets'])) {
                    $temp_prepaid_data = array_merge($details['prepaid_tickets'], $prepaid_tickets_data_to_insert);
                } else {
                    $temp_prepaid_data = $prepaid_tickets_data_to_insert;
                }
                $details['prepaid_tickets'] = (object) $temp_prepaid_data;
                $cluster_tps_ids = array();
                $cluster_tickets_data = array();
                $rel_target_ids = array();
                $cluster_net_price = array();

                foreach ($prepaid_tickets_data_to_insert as $value) {

                    if ($value['is_addon_ticket'] == '0') {
                        $cluster_tps_ids[] = $value['tps_id'];
                        $main_ticket_ids[$value['ticket_id']] = $value['ticket_id'];
                        $cluster_net_price[$value['clustering_id']] += 0;
                    } else if ($value['is_addon_ticket'] == '2' && $value['clustering_id'] != '' && $value['clustering_id'] != '0') {
                        $cluster_net_price[$value['clustering_id']] += $value['net_price'];
                    }
                    if ($value['financial_id'] > 0) {
                        $rel_target_ids[] = $value['financial_id'];
                    }

                    $all_tickets[] = $value['ticket_id'];
                    $all_museum_id[] = $value['museum_id'];
                }

                $this->load->model('pos_model');
                if (!empty($main_ticket_ids)) {
                    $main_ticket_combi_data = $this->order_process_model->main_ticket_combi_data($main_ticket_ids);
                }
                if (!empty($cluster_tps_ids)) {
                    $cluster_tickets_data = $this->order_process_model->cluster_tickets_detail_data($cluster_tps_ids);
                }

                /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
                $uncancel_order = 1;
                if ($uncancel_order == 1) {
                    $vt_version_data = $this->secondarydb->rodb->select('version')->from($vt_table)->where('visitor_group_no', $visitor_group_no)->get()->result_array();
                    $vt_version = max($vt_version_data);
                }
                //BOC to insert orders details  in order_flags table
                if (!empty($prepaid_tickets_data_to_insert)) {
                    foreach ($prepaid_tickets_data_to_insert as $pt_details_arr) { //prepare array w.r.t vgn
                        $pt_details_for_flag[$pt_details_arr['visitor_group_no']]['prepaid_tickets_data'][] = $pt_details_arr;
                    }
                    $new_flag_details_arr = array();
                    foreach ($pt_details_for_flag as $vgn_no => $vgn_pt_details) {
                        $vgns_arr[] = $vgn_no;
                        $flag_entities_vgn[$vgn_no] = $this->order_process_model->set_entity($vgn_pt_details); //prepare array for all entitities
                        // merging all entities corresponding to each vgn in one array
                        foreach ($flag_entities_vgn as $flag_entities) {
                            foreach ($flag_entities as $entity_name => $value) {
                                if (!empty($value)) {
                                    foreach ($value as $val) {
                                        $new_entity_arr[$entity_name][] = $val; //merged array of all vgns w.r.t all entities
                                    }
                                }
                            }
                        }
                    }
                    //prepare unique array w.t.t each entity
                    foreach ($new_entity_arr as $entity_name => $details) {
                        $final_flag_entities[$entity_name] = array_unique($details);
                    }
                    $flag_details = $this->importbooking_model->get_flags_details($final_flag_entities); // fetching flag entities details
                    if (!empty($flag_details)) {
                        foreach ($flag_entities_vgn as $vgn => $entity_detail) {
                            foreach ($flag_details as $key => $value) {
                                if (in_array($value->entity_id, $entity_detail[$value->entity_name])) { //check entity exist in vgn array
                                    $new_flag_details_arr[$vgn][] =  $value;
                                }
                            }
                        }
                        if (!empty($new_flag_details_arr)) { // set flags for each vgn
                            $this->importbooking_model->set_order_flags($new_flag_details_arr);
                        }
                    }
                }
                //EOC to insert orders details  in order_flags table
                foreach ($prepaid_tickets_data_to_insert as $key => $prepaid_tickets_data) {

                    if (!empty($prepaid_tickets_data['extra_booking_information'])) {
                        $prepaid_extra_booking_information = json_decode(stripslashes(stripslashes($prepaid_tickets_data['extra_booking_information'])), true);
                    } else {
                        $prepaid_extra_booking_information = array();
                    }
                    if (!empty($booking_information)) {
                        $prepaid_tickets_data['booking_information'] = $booking_information;
                    }
                    if (!empty($contact_information)) {
                        $prepaid_tickets_data['contact_information'] = $contact_information;
                    }
                    if (!empty($booking_details)) {
                        $prepaid_tickets_data['booking_details'] = $booking_details;
                    }
                    if (!empty($phone_number)) {
                        $prepaid_tickets_data['phone_number'] = $phone_number;
                    }
                    if (!empty($contact_details)) {
                        $prepaid_tickets_data['contact_details'] = $contact_details;
                    }

                    if (isset($prepaid_tickets_data['activation_method']) && $prepaid_tickets_data['activation_method'] != '') {
                        $activation_method = $prepaid_tickets_data['activation_method'];
                    }
                    if (strstr($prepaid_tickets_data['action_performed'], '0, PST_INSRT') || strstr($prepaid_tickets_data['action_performed'], '0, API_SYX_PST')) {
                        $redeem_prepaid_ticket_ids[] = $prepaid_tickets_data['prepaid_ticket_id'];
                    }

                    if (strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                        $upsell_order = 1;
                        unset($prepaid_tickets_data['main_ticket_id']);
                    }
                    unset($prepaid_tickets_data['order_type']);

                    $pos_point_id   = $prepaid_tickets_data['pos_point_id'];
                    $shift_id   = $prepaid_tickets_data['shift_id'];
                    $pos_point_name = $prepaid_tickets_data['pos_point_name'];
                    $channel_id = $prepaid_tickets_data['channel_id'];
                    $channel_name = $prepaid_tickets_data['channel_name'];

                    $prepaid_tickets_data['payment_date'] = !empty($prepaid_tickets_data['payment_date']) ? $prepaid_tickets_data['payment_date'] : '0000-00-00 00:00';
                    if (empty($prepaid_tickets_data['group_type_ticket'])) {
                        $group_type_ticket = '0';
                    } else {
                        $group_type_ticket = $prepaid_tickets_data['group_type_ticket'];
                    }
                    if (empty($prepaid_tickets_data['group_price'])) {
                        $group_price = '0';
                    } else {
                        $group_price = $prepaid_tickets_data['group_price'];
                    }
                    if (empty($prepaid_tickets_data['group_quantity'])) {
                        $group_quantity = '0';
                    } else {
                        $group_quantity = $prepaid_tickets_data['group_quantity'];
                    }

                    if (empty($prepaid_tickets_data['group_linked_with'])) {
                        $group_linked_with = '0';
                    } else {
                        $group_linked_with = $prepaid_tickets_data['group_linked_with'];
                    }

                    if (empty($pos_point_id)) {
                        $pos_point_id = 0;
                    }
                    if (empty($shift_id)) {
                        $shift_id = 0;
                    }
                    if (empty($pos_point_name)) {
                        $pos_point_name = '';
                    }
                    if (isset($prepaid_tickets_data['net_price\t']) && $prepaid_tickets_data['net_price\t'] != '') {
                        $prepaid_tickets_data['net_price'] = $prepaid_tickets_data['net_price\t'];
                    }
                    if (isset($prepaid_tickets_data['is_combi_ticket']) && $prepaid_tickets_data['is_combi_ticket'] == "1") {
                        $prepaid_tickets_data['is_combi_ticket'] = "1";
                    } else {
                        $prepaid_tickets_data['is_combi_ticket'] = "0";
                    }
                    if ($prepaid_tickets_data['passNo'] != '') {
                        $check_pass = $prepaid_tickets_data['passNo'];
                        if (((strlen($prepaid_tickets_data['passNo']) == 6 && $prepaid_tickets_data['third_party_type'] < '1') || $prepaid_tickets_data['ticket_id'] == RIPLEY_TICKET_ID || (isset($prepaid_tickets_data['is_iticket_product']) && $prepaid_tickets_data['is_iticket_product'] == '1')) && !strstr($prepaid_tickets_data['passNo'], 'http') && $prepaid_tickets_data['passNo'] != '') {
                            if ($prepaid_tickets_data['ticket_id'] == RIPLEY_TICKET_ID) {
                                if (!strstr($check_pass, 'qb.vg')) {
                                    $check_pass = 'qb.vg/' . $check_pass;
                                }
                            } else {
                                if (!strstr($check_pass, '-')) {
                                    if (!strstr($check_pass, 'qu.mu')) {
                                        $check_pass = 'qu.mu/' . $check_pass;
                                    }
                                } else {
                                    if (!strstr($check_pass, 'qb.vg')) {
                                        $check_pass = 'qb.vg/' . $check_pass;
                                    }
                                }
                            }
                            $check_pass = "http://" . $check_pass;
                        }
                        if (!(isset($prepaid_tickets_data['hotel_ticket_overview_id']) && $prepaid_tickets_data['hotel_ticket_overview_id'] > 0)) {
                            $prepaid_tickets_data['hotel_ticket_overview_id'] = $hto_ids[$check_pass];
                        }
                    } else {
                        if (!(isset($prepaid_tickets_data['hotel_ticket_overview_id']) && $prepaid_tickets_data['hotel_ticket_overview_id'] > 0) && $prepaid_tickets_data['is_prioticket'] == "0") {
                            $prepaid_tickets_data['hotel_ticket_overview_id'] = $hto_ids[$arrpass[0]];
                        }
                    }

                    $sync_all_tickets['ticket_id'][] = $prepaid_tickets_data['ticket_id'];
                    if (isset($prepaid_tickets_data['last_modified_at'])) {
                        unset($prepaid_tickets_data['last_modified_at']);
                    }

                    $prepaid_tickets_data['secondary_guest_name'] = !empty($prepaid_tickets_data['guest_names']) ? $prepaid_tickets_data['guest_names'] : '';
                    $prepaid_tickets_data['secondary_guest_email'] = !empty($prepaid_tickets_data['guest_emails']) ? $prepaid_tickets_data['guest_emails'] : '';
                    $prepaid_tickets_data['phone_number'] = !empty($prepaid_tickets_data['phone_number']) ? $prepaid_tickets_data['phone_number'] : '';
                    $prepaid_tickets_data['passport_number'] = '';

                    $insertedId = $prepaid_tickets_data['prepaid_ticket_id'];
                    $ticket_id = $prepaid_tickets_data['ticket_id'];

                    if ($prepaid_tickets_data['is_discount_code']  == '1') {
                        $discount_codes_details = $prepaid_extra_booking_information["discount_codes_details"] ? $prepaid_extra_booking_information["discount_codes_details"] : array();
                        if (empty($discount_codes_details)) {
                            $discount_codes_details[$prepaid_tickets_data['discount_code_value']] = array(
                                'tax_id' => 0,
                                'tax_value' => 0.00,
                                'promocode' => $prepaid_tickets_data['discount_code_value'],
                                'discount_amount' => $prepaid_tickets_data['discount_code_amount'],
                            );
                        }
                    }

                    if (!array_key_exists($ticket_id, $museum_details)) {
                        $details['modeventcontent'][$ticket_id] = $ticket_details[$ticket_id] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $ticket_id));
                        $museum_details[$ticket_id] = $ticket_details[$ticket_id]->museum_name;
                    }

                    if (empty($prepaid_tickets_data['selected_date'])) {
                        // for open tickets
                        $prepaid_tickets_data['selected_date'] = date('Y-m-d');
                    }

                    $confirm_data = array();
                    $confirm_data['pertransaction'] = "0";
                    $confirm_data['scanned_pass'] = isset($prepaid_tickets_data['scanned_pass']) ? $prepaid_tickets_data['scanned_pass'] : '';
                    $discount_data = unserialize($prepaid_tickets_data['extra_discount']);
                    $confirm_data['creation_date'] = $prepaid_tickets_data['created_at'];
                    $confirm_data['visit_date'] = (isset($prepaid_tickets_data['scanned_at']) && $prepaid_tickets_data['scanned_at'] != '') ? $prepaid_tickets_data['scanned_at'] : strtotime(gmdate('Y-m-d H:i:s'));
                    $confirm_data['distributor_partner_id'] = !empty($prepaid_tickets_data['distributor_partner_id']) ? $prepaid_tickets_data['distributor_partner_id'] : 0;
                    $confirm_data['distributor_partner_name'] = $prepaid_tickets_data['distributor_partner_name'];
                    $confirm_data['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                    $confirm_data['hotel_id'] = $prepaid_tickets_data['hotel_id'];
                    $confirm_data['channel_type'] = $prepaid_tickets_data['channel_type'];
                    $confirm_data['partner_category_id'] = !empty($prepaid_tickets_data['partner_category_id']) ? $prepaid_tickets_data['partner_category_id'] : 0;
                    $confirm_data['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                    $confirm_data['hotel_name'] = $prepaid_tickets_data['hotel_name'];
                    $confirm_data['resuid'] = $prepaid_tickets_data['cashier_id'];
                    $confirm_data['resfname'] = $prepaid_tickets_data['cashier_name'];
                    $confirm_data['channel_id'] = $channel_id;
                    $confirm_data['channel_name;'] = $channel_name;
                    $confirm_data['shift_id'] = $shift_id;
                    $confirm_data['pos_point_id'] = $pos_point_id;
                    $confirm_data['pos_point_name'] = $pos_point_name;
                    $confirm_data['museum_name'] = !empty($prepaid_tickets_data['museum_name']) ? $prepaid_tickets_data['museum_name'] : $museum_details[$ticket_id];
                    if ($is_prioticket == "0") {
                        $confirm_data['passNo'] = $prepaid_tickets_data['passNo'];
                    } else if ($is_prioticket == "1") {
                        $confirm_data['passNo'] = strlen($prepaid_tickets_data['passNo']) > 6 && !strstr($prepaid_tickets_data['passNo'], 'http') ? $prepaid_tickets_data['passNo'] : '';
                    } else {
                        $confirm_data['passNo'] = '';
                    }
                    $confirm_data['pass_type'] = $prepaid_tickets_data['pass_type'];
                    $confirm_data['prepaid_ticket_id'] = $insertedId;
                    $confirm_data['is_ripley_pass'] = ($ticket_details[$ticket_id]->cod_id == RIPLEY_MUSEUM_ID && $ticket_id == RIPLEY_TICKET_ID) ? 1 : 0;
                    $confirm_data['visitor_group_no'] = $visitor_group_no;
                    $confirm_data['ticket_booking_id'] = $prepaid_tickets_data['ticket_booking_id'];
                    $confirm_data['ticketId'] = $ticket_id;
                    $confirm_data['is_combi_discount'] = $prepaid_tickets_data['is_combi_discount'];
                    $confirm_data['combi_discount_gross_amount'] = $prepaid_tickets_data['combi_discount_gross_amount'];
                    $confirm_data['order_currency_combi_discount_gross_amount'] = $prepaid_tickets_data['order_currency_combi_discount_gross_amount'];
                    $confirm_data['price'] = $prepaid_tickets_data['price'];
                    $confirm_data['order_currency_price'] = $prepaid_tickets_data['order_currency_price'];
                    $confirm_data['supplier_currency_code'] = $prepaid_tickets_data['supplier_currency_code'];
                    $confirm_data['supplier_currency_symbol'] = $prepaid_tickets_data['supplier_currency_symbol'];
                    $confirm_data['order_currency_code'] = $prepaid_tickets_data['order_currency_code'];
                    $confirm_data['order_currency_symbol'] = $prepaid_tickets_data['order_currency_symbol'];
                    $confirm_data['currency_rate'] = $prepaid_tickets_data['currency_rate'];
                    $confirm_data['discount_type'] = $discount_data['discount_type'];
                    $confirm_data['new_discount'] = $discount_data['new_discount'];
                    $confirm_data['gross_discount_amount'] = $discount_data['gross_discount_amount'];
                    $confirm_data['net_discount_amount'] = $discount_data['net_discount_amount'];
                    if (isset($discount_data['discount_label']) && $discount_data['discount_label'] != '') {
                        $confirm_data['discount_label'] = $discount_data['discount_label'];
                    } else {
                        $confirm_data['discount_label'] = '';
                    }

                    $confirm_data['group_type_ticket'] = $group_type_ticket;
                    $confirm_data['group_price'] = $group_price;
                    $confirm_data['group_quantity'] = $group_quantity;
                    $confirm_data['group_linked_with'] = $group_linked_with;
                    $confirm_data['pax'] = $prepaid_tickets_data['pax'];
                    $confirm_data['capacity'] = $prepaid_tickets_data['capacity'];
                    $confirm_data['clustering_id'] = $prepaid_tickets_data['clustering_id'];
                    $confirm_data['version'] = $prepaid_tickets_data['version'];

                    if ($prepaid_tickets_data['service_cost'] > 0) {
                        if ($prepaid_tickets_data['service_cost_type'] == "1") {
                            $confirm_data['service_gross'] = $prepaid_tickets_data['service_cost'];
                            $confirm_data['service_cost_type'] = $prepaid_tickets_data['service_cost_type'];
                            $confirm_data['pertransaction'] = "0";
                            $confirm_data['price'] = $prepaid_tickets_data['price'] - $prepaid_tickets_data['service_cost'] + $prepaid_tickets_data['combi_discount_gross_amount'];
                        } else if ($prepaid_tickets_data['service_cost_type'] == "0") {
                            $confirm_data['service_gross'] = $prepaid_tickets_data['service_cost'];
                            $confirm_data['service_cost_type'] = $prepaid_tickets_data['service_cost_type'];
                        }
                    } else {
                        $confirm_data['service_gross'] = 0;
                        $confirm_data['service_cost_type'] = 0;
                        $confirm_data['pertransaction'] = "0";
                    }

                    /*
                     * Make sure, the passNo is passed with full URL
                     * Ripley ticket condition is added becuase it contains http in its url when saved in hotel_ticket_overview and pass
                     * is scanned through venue app
                     */
                    if ((strlen($prepaid_tickets_data['passNo']) == 6 || $ticket_id == RIPLEY_TICKET_ID || (isset($prepaid_tickets_data['is_iticket_product']) && $prepaid_tickets_data['is_iticket_product'] == '1')) && !strstr($prepaid_tickets_data['passNo'], 'http') && $prepaid_tickets_data['passNo'] != '') {
                        if ($ticket_id == RIPLEY_TICKET_ID) {
                            if (!strstr($prepaid_tickets_data['passNo'], 'qb.vg')) {
                                $prepaid_tickets_data['passNo'] = 'qb.vg/' . $prepaid_tickets_data['passNo'];
                            }
                        } else {
                            if (!strstr($prepaid_tickets_data['passNo'], '-')) {
                                if (!strstr($prepaid_tickets_data['passNo'], 'qu.mu')) {
                                    $prepaid_tickets_data['passNo'] = 'qu.mu/' . $prepaid_tickets_data['passNo'];
                                }
                            } else {
                                if (!strstr($prepaid_tickets_data['passNo'], 'qb.vg')) {
                                    $prepaid_tickets_data['passNo'] = 'qb.vg/' . $prepaid_tickets_data['passNo'];
                                }
                            }
                        }
                        $prepaid_tickets_data['passNo'] = "http://" . $prepaid_tickets_data['passNo'];
                    }
                    
                    $confirm_data['initialPayment'] = $this->order_process_vt_model->getInitialPaymentDetail($prepaid_tickets_data, $final_hto_data[0]);
                    
                    if(!$confirm_data['initialPayment']) {
                        return false;
                    }

                    if (strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                        $confirm_data['selected_date'] = $prepaid_tickets_data['selected_date'] != '' ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                        $confirm_data['from_time'] = $prepaid_tickets_data['from_time'] != '' ? $prepaid_tickets_data['from_time'] : '0';
                        $confirm_data['to_time'] = $prepaid_tickets_data['to_time'] != '' ? $prepaid_tickets_data['to_time'] : '0';
                        $confirm_data['slot_type'] = $prepaid_tickets_data['timeslot'] != '' ? $prepaid_tickets_data['timeslot'] : '0';
                    } else {
                        $confirm_data['selected_date'] = $prepaid_tickets_data['selected_date'] != '' ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                        $confirm_data['from_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['from_time'] : '0';
                        $confirm_data['to_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['to_time'] : '0';
                        $confirm_data['slot_type'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['timeslot'] : '0';
                    }

                    $confirm_data['ticketpriceschedule_id'] = $prepaid_tickets_data['tps_id'];
                    $confirm_data['ticketwithdifferentpricing'] = $ticket_details[$ticket_id]->ticketwithdifferentpricing;
                    $confirm_data['booking_selected_date']      = $ticket_details[$ticket_id]->is_reservation == '0' ? isset($prepaid_tickets_data['booking_selected_date']) ? $prepaid_tickets_data['booking_selected_date'] : '' : '';
                    $confirm_data['prepaid_type'] = $activation_method;
                    $confirm_data['cashier_id'] = $prepaid_tickets_data['cashier_id'];
                    $confirm_data['cashier_name'] = $prepaid_tickets_data['cashier_name'];
                    $confirm_data['action_performed'] = isset($prepaid_tickets_data['action_performed']) ? $prepaid_tickets_data['action_performed'] : '';
                    $mpos_postpaid_order = 0;
                    if (strpos($prepaid_tickets_data['action_performed'], 'MPOS_PST_INSRT') !== false) {
                        $confirm_data['action_performed'] =  "MPOS_PST_INSRT";
                        $mpos_postpaid_order = 1;
                    }

                    if ($upsell_order || ($prepaid_tickets_data['activation_method'] == "19" && $prepaid_tickets_data['is_addon_ticket'] == "0") || $mpos_postpaid_order == 1) {
                        $confirm_data['userid'] = $prepaid_tickets_data['museum_cashier_id'];
                        $user_name = explode(' ', $prepaid_tickets_data['museum_cashier_name']);
                        $confirm_data['fname'] = $user_name[0];
                        array_shift($user_name);
                        $confirm_data['lname'] = implode(" ", $user_name);
                    } else if (isset($prepaid_tickets_data['bleep_pass_no']) && !empty($prepaid_tickets_data['bleep_pass_no'])) {
                        $confirm_data['userid'] = $prepaid_tickets_data['voucher_updated_by'];
                        $voucher_updated_by_name = explode(' ', $prepaid_tickets_data['voucher_updated_by_name']);
                        $confirm_data['fname'] = $voucher_updated_by_name[0];
                        array_shift($voucher_updated_by_name);
                        $confirm_data['lname'] =  implode(" ", $voucher_updated_by_name);
                        $confirm_data['scanned_pass'] = ($prepaid_tickets_data['channel_type'] == 10 || $prepaid_tickets_data['channel_type'] == 11) ? $prepaid_tickets_data['bleep_pass_no'] : '';
                    } else {
                        $confirm_data['userid'] = '0';
                        $confirm_data['fname'] = 'Prepaid';
                        $confirm_data['lname'] = 'ticket';
                    }

                    $confirm_data['financial_id'] = $prepaid_tickets_data['financial_id'];
                    $confirm_data['financial_name'] = $prepaid_tickets_data['financial_name'];
                    $confirm_data['is_prioticket'] = $is_prioticket;
                    $confirm_data['check_age'] = 0;
                    $hotel_info = $this->common_model->companyName($prepaid_tickets_data['hotel_id']); // Hotel Information
                    $confirm_data['cmpny'] = $hotel_info;
                    $confirm_data['timeZone'] = $prepaid_tickets_data['timezone'];
                    $confirm_data['used'] = $prepaid_tickets_data['used'];
                    if (!isset($prepaid_tickets_data['is_prepaid'])) {
                        $prepaid_tickets_data['is_prepaid'] = "1";
                    }

                    $confirm_data['is_prepaid'] = (isset($prepaid_tickets_data['channel_type']) && $prepaid_tickets_data['channel_type'] == 2) ? 1 : $prepaid_tickets_data['is_prepaid'];
                    $confirm_data['is_voucher'] = $prepaid_tickets_data['is_voucher'];
                    $confirm_data['is_shop_product'] = $prepaid_tickets_data['product_type'];
                    $confirm_data['is_pre_ordered'] = $prepaid_tickets_data['used'] == '1' ? 1 : 0;
                    if ($prepaid_tickets_data['booking_status'] == '1') {
                        $confirm_data['is_pre_ordered'] = 1;
                    }
                    $confirm_data['order_status'] = $prepaid_tickets_data['order_status'];
                    $confirm_data['extra_text_field'] = $ticket_details[$ticket_id]->extra_text_field;
                    $confirm_data['extra_text_field_answer'] = $prepaid_tickets_data['extra_text_field_answer'];
                    $confirm_data['is_iticket_product'] = $prepaid_tickets_data['is_iticket_product'];
                    $confirm_data['without_elo_reference_no'] = $prepaid_tickets_data['without_elo_reference_no'];
                    if (isset($prepaid_tickets_data['is_addon_ticket']) && $prepaid_tickets_data['is_addon_ticket'] != '') {
                        $confirm_data['is_addon_ticket'] = $prepaid_tickets_data['is_addon_ticket'];
                    } else {
                        $confirm_data['is_addon_ticket'] = '';
                    }

                    $transaction_id = $this->importbooking_model->get_auto_generated_id_dpos($confirm_data['visitor_group_no'], $confirm_data['prepaid_ticket_id']);
                    if ($confirm_data['is_addon_ticket'] != "2") {
                        $transaction_id_array[$prepaid_tickets_data['cluster_group_id']][$prepaid_tickets_data['clustering_id']][] = $transaction_id;
                        $this->CreateLog('checktransaction.php', json_encode($transaction_id_array), array());
                        $confirm_data['merchant_admin_id'] = $ticket_details[$ticket_id]->merchant_admin_id;
                        $confirm_data['merchant_admin_name'] = $ticket_details[$ticket_id]->merchant_admin_name;
                    } else {
                        $cluster_tickets_transaction_id_array[$prepaid_tickets_data['ticket_id'] . '::' . $prepaid_tickets_data['cluster_group_id'] . '::' . $prepaid_tickets_data['clustering_id']][] = $transaction_id;
                        $this->CreateLog('checktransaction.php', json_encode($cluster_tickets_transaction_id_array), array());
                        $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_name'];
                        $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_id'];
                    }
                    if (!empty($main_ticket_combi_data) && isset($main_ticket_combi_data[$prepaid_tickets_data['ticket_id']])) {
                        $confirm_data['is_combi'] = $main_ticket_combi_data[$prepaid_tickets_data['ticket_id']];
                    } else {
                        $confirm_data['is_combi'] = 0;
                    }

                    $confirm_data['ticketDetail'] = $ticket_details[$ticket_id];
                    $confirm_data['cluster_ticket_net_price'] = $prepaid_tickets_data['net_price'];
                    $confirm_data['discount'] = $prepaid_tickets_data['discount'];
                    $confirm_data['is_discount_in_percent'] = $prepaid_tickets_data['is_discount_in_percent'];
                    $confirm_data['split_payment'] = $data['split_payment'];
                    $details['visitor_tickets'][] = $confirm_data;
                    $confirm_data['extra_discount'] = $prepaid_tickets_data['extra_discount'];
                    $confirm_data['order_currency_extra_discount'] = $prepaid_tickets_data['order_currency_extra_discount'];
                    $confirm_data['commission_type'] = isset($prepaid_tickets_data['commission_type']) ? $prepaid_tickets_data['commission_type'] : 0;
                    $confirm_data['booking_information'] = isset($mainharray['booking_information']) ? $mainharray['booking_information'] : '';
                    $confirm_data['updated_at'] = $prepaid_tickets_data['created_at'];
                    $confirm_data['supplier_gross_price'] = $prepaid_tickets_data['supplier_price'];
                    $confirm_data['supplier_discount'] = $prepaid_tickets_data['supplier_discount'];
                    $confirm_data['supplier_ticket_amt'] = $prepaid_tickets_data['supplier_original_price'];
                    $confirm_data['supplier_tax_value'] = $prepaid_tickets_data['supplier_tax'];
                    $confirm_data['supplier_net_price'] = $prepaid_tickets_data['supplier_net_price'];
                    $confirm_data['tp_payment_method']  = $prepaid_tickets_data['tp_payment_method'];
                    $confirm_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                    $confirm_data['payment_date'] = !empty($prepaid_tickets_data['payment_date']) ? $prepaid_tickets_data['payment_date'] : '0000-00-00 00:00';
                    $confirm_data['chart_number'] = $prepaid_tickets_data['chart_number'];
                    $confirm_data['account_number'] = $prepaid_tickets_data['account_number'];
                    $confirm_data['uncancel_order'] = $uncancel_order;
                    $confirm_data['primary_host_name'] = $prepaid_tickets_data['primary_host_name'];
                    $confirm_data['ticketsales'] = $prepaid_tickets_data['is_prepaid'];
                    $confirm_data['distributor_reseller_id'] = $hotel_info->reseller_id;
                    $confirm_data['distributor_reseller_name'] = $hotel_info->reseller_name;
                    $confirm_data['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                    $confirm_data['tickettype_name'] = $prepaid_tickets_data['ticket_type'];
                    $confirm_data['prepaid_reseller_id'] = $prepaid_tickets_data['reseller_id'];
                    $confirm_data['prepaid_reseller_name'] = $prepaid_tickets_data['reseller_name'];

                    if (in_array($prepaid_tickets_data['channel_type'], array('5', '10', '11'))) {
                        $confirm_data['voucher_updated_by'] = $prepaid_tickets_data['voucher_updated_by'];
                        $confirm_data['voucher_updated_by_name'] = $prepaid_tickets_data['voucher_updated_by_name'];
                    }

                    $extra_option_merchant_data[$ticket_id] = 0;

                    //insert in vt when booking_status is 1 only.
                    /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
                    if ($uncancel_order == 1 && !empty($vt_version)) {
                        $confirm_data['version'] = (int)$vt_version['version'];
                    }
                    /* case handle for v3.2 API.*/
                    if (!empty($data['action_from'])) {
                        $confirm_data['action_from'] = $data['action_from'];
                    }
                    if ($confirm_data['is_addon_ticket'] == 0) {
                        $confirm_data['cluster_net_price'] = $cluster_net_price[$confirm_data['clustering_id']];
                    } else {
                        $confirm_data['cluster_net_price'] = 0;
                    }
                    $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, $is_secondary_db);

                    $museum_net_fee = $museum_gross_fee = $hgs_net_fee = $hgs_gross_fee = $distributor_net_fee = $distributor_gross_fee = 0.00;

                    if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {
                        $relevant = array();
                        foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $visitor_row_batch) {
                            if ($visitor_row_batch['row_type'] == '2') {
                                $museum_net_fee = $visitor_row_batch['partner_net_price'];
                                $museum_gross_fee = $visitor_row_batch['partner_gross_price'];
                            } else if ($visitor_row_batch['row_type'] == '3') {
                                $distributor_net_fee = $visitor_row_batch['partner_net_price'];
                                $distributor_gross_fee = $visitor_row_batch['partner_gross_price'];
                            } else if ($visitor_row_batch['row_type'] == '4') {
                                $hgs_net_fee = $visitor_row_batch['partner_net_price'];
                                $hgs_gross_fee = $visitor_row_batch['partner_gross_price'];
                            }

                            $relevant[] = array("row_type" => $visitor_row_batch['row_type'], "partner_net_price" => $visitor_row_batch['partner_net_price'], "partner_gross_price" => $visitor_row_batch['partner_gross_price'], "ticket_type" => $visitor_row_batch['tickettype_name'], "transaction_type_name" => $visitor_row_batch['transaction_type_name']);
                        }
                    }
                    $prepaid_tickets_data_to_insert[$key]['museum_net_fee'] = $museum_net_fee;
                    $prepaid_tickets_data_to_insert[$key]['distributor_net_fee'] = $distributor_net_fee;
                    $prepaid_tickets_data_to_insert[$key]['hgs_net_fee'] = $hgs_net_fee;
                    $prepaid_tickets_data_to_insert[$key]['museum_gross_fee'] = $museum_gross_fee;
                    $prepaid_tickets_data_to_insert[$key]['distributor_gross_fee'] = $distributor_gross_fee;
                    $prepaid_tickets_data_to_insert[$key]['hgs_gross_fee'] = $hgs_gross_fee;
                }
                $vgns = array_unique(array_column($prepaid_tickets_data_to_insert, 'visitor_group_no'));
                $this->pos_model->insert_batch('prepaid_tickets', $prepaid_tickets_data_to_insert);
                $this->pos_model->insert_batch($pt_table, $prepaid_tickets_data_to_insert, 1);
                 $logs['pt_db2_query_'.date("Y-m-d H:is")] = $this->secondarydb->db->last_query();
                $local_machines = array('10.10.10.23', '10.10.10.24', '10.10.10.25','10.10.10.120', '10.10.10.26', '10.10.10.27', '10.10.10.29', '10.10.10.68', '10.10.10.69', '10.10.10.93', '10.10.10.46', '10.10.10.63','10.10.10.108','10.10.10.63');  
                if ($_SERVER['HTTP_HOST'] != 'localhost' && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.10.10.')) {
                    
                    $pt_data_bq_sync = $this->secondarydb->db->query('select * from prepaid_tickets pt1 where visitor_group_no in (' . implode(',', $vgns) . ') and version = (select max(version) from prepaid_tickets pt2 where pt2.prepaid_ticket_id = pt1.prepaid_ticket_id and pt1.visitor_group_no in (' . implode(',', $vgns) . '))')->result();
                    
                    $this->CreateLog('pt_bq_insertion_import_booking.php', 'ENVIORNMENT ==>>', array("SERVER_ENVIRONMENT===>>>" => "NOT LOCAL"));
                    $this->CreateLog('pt_bq_insertion_import_booking.php', 'DATA ARRAY ==>>', array("DATA===>>>" => json_encode($pt_data_bq_sync)));
                    
                    $aws_message2 = '';
                    $aws_message2 = json_encode($pt_data_bq_sync);
                    $aws_message2 = base64_encode(gzcompress($aws_message2));
                    $logs['data_to_queue_LIVE_SCAN_REPORT_QUEUE_URL_' . date("H:i:s")] = $aws_message2;
                    $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                    if ($MessageIdss != false) {
                        $err = $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                    }
                    /* code for inserting in bigquery from mysql secondary pt tbl ends here*/

                    /* Select data and sync on BQ */
                    $this->order_process_model->getVtDataToSyncOnBQ($vgns);
                }
                else {
                    
                    $this->CreateLog('pt_bq_insertion_import_booking.php', 'ENVIORNMENT ==>>', array("SERVER_ENVIRONMENT===>>>" => "LOCAL"));
                    $this->CreateLog('pt_bq_insertion_import_booking.php', 'DATA ARRAY ==>>', array("DATA===>>>" => json_encode($prepaid_tickets_data_to_insert)));
                }

                // Send data in RDS queue realtime
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                    $this->pos_model->insert_batch($pt_table, $prepaid_tickets_data_to_insert, 4);
                }
            }
        }


        if (!empty($visitor_group_nos)) {
            $visitor_group_nos = array_unique($visitor_group_nos);
            foreach ($visitor_group_nos as $visitor_group_no) {
                $import_booking_orders_to_insert_in_vt_data[] = array(
                    "visitor_group_no" => $visitor_group_no,
                    "processed_status" => "0",
                    "created_at" => gmdate("Y-m-d H:i:s"),
                    "last_modified_at" => gmdate("Y-m-d H:i:s")
                );
            }
            $import_booking_orders_to_insert_in_vt_data_chunks = array_chunk($import_booking_orders_to_insert_in_vt_data, 200);
            foreach ($import_booking_orders_to_insert_in_vt_data_chunks as $import_booking_orders_to_insert_in_vt_data_to_insert) {
                if (empty($import_booking_orders_to_insert_in_vt_data_to_insert)) {
                    continue;
                }
                $this->pos_model->insert_batch('import_booking_orders_to_insert_in_vt', $import_booking_orders_to_insert_in_vt_data_to_insert, 1);
                $logs['import_booking_orders_to_insert_in_vt_query_' . date("Y-m-d H:is")] = $this->secondarydb->db->last_query();
            }
        }
        $insert_batch_in_orders_tables['insert batch order table logs_' . date("Y-m-d H:i:s")] = $logs;
        $this->CreateLog('import_booking_process.php', 'insert batch order table logs ', array(json_encode($insert_batch_in_orders_tables)));
        echo "yes";
        exit;
    }

    /* #endregion to insert data in all order table imported from import booking */

    /* #region to insert data in visitor tickets by fetching from import booking orders */

    /**
     * insert_data_visitor_table_from_import_booking_orders
     *
     * @return void
     */
    public function insert_data_visitor_table_from_import_booking_orders()
    {
        $import_booking_orders_to_insert_in_vt_data = $this->pos_model->find('import_booking_orders_to_insert_in_vt', array('select' => 'visitor_group_no', 'where' => 'processed_status = "0" limit 10'), 'array', "1");
        echo "<pre>==import_booking_orders_to_insert_in_vt_data<br>";
        print_r($import_booking_orders_to_insert_in_vt_data);

        if (empty($import_booking_orders_to_insert_in_vt_data)) {
            exit;
        }

        $visitor_group_nos_to_insert = array_column($import_booking_orders_to_insert_in_vt_data, "visitor_group_no");

        echo "==visitor_group_nos_to_insert<br>";
        print_r($visitor_group_nos_to_insert);

        $inserted_visitor_group_nos = array();

        foreach ($visitor_group_nos_to_insert as $visitor_group_no_to_insert) {
            $this->order_process_update_model->update_visitor_tickets_direct($visitor_group_no_to_insert);
            $inserted_visitor_group_nos[] = $visitor_group_no_to_insert;
        }
        if (!empty($inserted_visitor_group_nos)) {
            $this->importbooking_model->update_processed_status_in_import_booking_orders_to_insert_in_vt($inserted_visitor_group_nos, "1");
        }
        echo "done";
        exit;
    }

    /* #endregion to insert data in visitor tickets by fetching from import booking orders */

    /* #region to insert data in visitor tickets by fetching from import booking orders */

    /**
     * prepare_vt_insertion
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $db_type
     * @return void
     */
    public function prepare_vt_insertion($visitor_group_no = '', $db_type = '1')
    {
        global $logsData;
        echo "<pre>";
        print_r("1 = " . date('Y-m-d H:i:s'));
        $pendingOrders = $this->importbooking_model->find('import_booking_orders_to_insert_in_vt', array('select' => 'visitor_group_no', 'where' => "processed_status = '0' LIMIT 15"), 'array', '1');
        $logs['orders_to_be_processed_query_' . date("Y-m-d H;i:s0")] = $this->secondarydb->rodb->last_query();
        echo "\r\n" . $this->secondarydb->rodb->last_query();
        if (empty($pendingOrders) && empty($visitor_group_no)) {
            return false;
        }
        $logs['orders_to_be_processed_details_' . date("Y-m-d H;i:s0")] = $pendingOrders;

        $pt_table = (!empty(PT_TABLE) ? PT_TABLE : 'prepaid_tickets');

        $visitorGroupNo = array_column($pendingOrders, "visitor_group_no");
        if (!empty($visitor_group_no)) {
            $visitorGroupNo = array($visitor_group_no);
        }

        $this->secondarydb->rodb->select("*");
        //$this->secondarydb->rodb->select("visitor_group_no, timezone, isBillToHotel, channel_type, activation_method, paymentMethod, pspReference");
        $this->secondarydb->rodb->from("hotel_ticket_overview");
        $this->secondarydb->rodb->where_in("visitor_group_no", $visitorGroupNo);
        $overview_result = $this->secondarydb->rodb->get();
        $logs['db2_HTO_query_' . date('H:i:s')] = $this->secondarydb->rodb->last_query();
        echo "<pre>";
        print_r($this->secondarydb->rodb->last_query());
        if ($overview_result->num_rows() > 0) {

            $overview_data = $overview_result->result_array();
            $batchVts = array_column($overview_data, 'visitor_group_no');
            $htoVTdata = array_combine($batchVts, $overview_data);
            $hotel_data = $htoVTdata;
        }

        $this->secondarydb->rodb->select("prepaid_ticket_id, channel_id, reseller_id, reseller_name, timezone, 
                        saledesk_id, saledesk_name, channel_name, financial_id, financial_name, 
                        distributor_type, used, ticket_booking_id, selected_date, from_time, to_time, timeslot, 
                        pos_point_id, pos_point_name, cashier_id, cashier_name, scanned_at, extra_discount, 
                        voucher_updated_by, voucher_updated_by_name, museum_id, museum_name, distributor_partner_id, 
                        distributor_partner_name, passNo, pax, capacity, pass_type, visitor_group_no, 
                        order_currency_extra_discount, ticket_id, is_combi_discount, discount, service_cost, 
                        service_cost_type, combi_discount_gross_amount, price, created_at, tps_id, activation_method, 
                        is_prioticket, booking_status, is_prepaid, is_voucher, product_type, order_status, 
                        without_elo_reference_no, prepaid_ticket_id, is_addon_ticket, action_performed, updated_at, 
                        supplier_price, supplier_discount, supplier_original_price, supplier_tax, supplier_net_price, 
                        commission_json, tp_payment_method, order_confirm_date, payment_date, shared_capacity_id, 
                        channel_type, market_merchant_id, booking_information, hotel_id, hotel_name, merchant_admin_id, 
                        ticket_type, is_refunded, is_cancelled, clustering_id, related_product_id, created_date_time, version, net_price, is_discount_in_percent, currency_rate");
        $this->secondarydb->rodb->from($pt_table);
        $this->secondarydb->rodb->where_in("visitor_group_no", $visitorGroupNo);
        //$this->secondarydb->rodb->where('deleted', '0');
        $prepaid_result = $this->secondarydb->rodb->get();
        $logs['db2_query_PT_' . date('H:i:s')] = $this->secondarydb->rodb->last_query();
        echo "<pre>";
        print_r(date('Y-m-d H:i:s') . " - " . $this->secondarydb->rodb->last_query());
        if ($prepaid_result->num_rows() > 0) {

            $prepaid_data = $prepaid_result->result_array();
            $ptVgns = array_column($prepaid_data, 'visitor_group_no');
            $foundVgns = array_unique($ptVgns);
            $ptTicketIds = array_unique(array_column($prepaid_data, 'ticket_id'));
            $ptHotelIds = array_unique(array_column($prepaid_data, 'hotel_id'));
            $tpsIds = array_filter(array_unique(array_column($prepaid_data, 'tps_id')));
            $allTicketIds = array_column($prepaid_data, 'ticket_id');
            $allTicketTypes = array_column($prepaid_data, 'ticket_type');
            $alltypeIds = array_unique(array_map(function ($key, $val) {
                return implode("_", array($key, $val));
            }, $allTicketIds, $allTicketTypes));
            $channelTypeChk = array_unique(array_column($prepaid_data, 'channel_type'));

            $ticket_details = $this->importbooking_model->ticketIdsIn($ptTicketIds);
            $hotel_info = $this->importbooking_model->companyNameIn($ptHotelIds);
            $tpsDetails = $this->importbooking_model->tpsIdsIn($tpsIds);
            $tpsPartnerFin = $this->importbooking_model->tpsPartnerFinancialIn($tpsIds);

            $serviceCostTaxIds = array_column($hotel_info, 'service_cost_tax');
            $tax = $this->importbooking_model->hotelTaxIn($serviceCostTaxIds);

            $allCurrentTpsIds = array();
            $allCurrentSeasonTpsId = $this->importbooking_model->getCurrentSeasonTpsId($alltypeIds);
            if (!empty($allCurrentSeasonTpsId) && (in_array('2', $channelTypeChk) || in_array('5', $channelTypeChk))) {
                $allCurrentTpsIds = $this->importbooking_model->getCurrentSeasonTpsIdDetails($allCurrentSeasonTpsId);
            }

            foreach ($ptVgns as $key1 => $vtData) {
                $vgnQuantity[$vtData][] = $prepaid_data[$key1];
            }
        } else {
            $this->importbooking_model->update_processed_status_in_import_booking_orders_to_insert_in_vt($visitorGroupNo, "4");
        }

        $VTchunks = array_chunk($vgnQuantity, 20, true);
        foreach ($VTchunks as $vgns) {

            $VTdata = $this->importbooking_model->vtDataPrepare($vgns, $overview_data, $hotel_data, $ticket_details, $hotel_info, $tpsDetails, $tax, $tpsPartnerFin, $allCurrentSeasonTpsId, $allCurrentTpsIds);
            if (!empty($VTdata)) {
                $this->importbooking_model->update_processed_status_in_import_booking_orders_to_insert_in_vt($VTdata, "1");
                $logs['update_processed_status_query_' . date("Y-m-d H:i:s")] = $this->secondarydb->db->last_query();
            }
        }
        //update mpos exception order
        $exception_order_ids = array();
        $vgn_array = array();
        $this->secondarydb->rodb->select("*");
        $this->secondarydb->rodb->from("import_booking");
        $this->secondarydb->rodb->where_in("visitor_group_no", $visitorGroupNo);
        $query = $this->secondarydb->rodb->get();
        $exception_results = $query->result_array();
        echo "\r\n" . $this->secondarydb->rodb->last_query();
        foreach ($exception_results as $exception_result) {
            if ($exception_result['exception_order_id'] != '') {
                array_push($exception_order_ids, $exception_result['exception_order_id']);
                array_push($vgn_array, $exception_result['visitor_group_no']);
            }
        }

        if (!empty($exception_order_ids)) {
            $update_query = "UPDATE prepaid_tickets SET used = '1', action_performed = CONCAT(action_performed, ', IMP_EXCEP_SCAN'), scanned_at = '" . strtotime(date("Y-m-d")) . "' , redeem_date_time = '" . gmdate("Y-m-d H:i:s") . "' WHERE visitor_group_no IN("  . implode(',', $vgn_array) . ") ";
            $this->db->query($update_query);
            $logs['exception_order_db1_query_' . date("Y-m-d H:i:s")] = $this->db->last_query();
            echo "\r\n" . $this->db->last_query();
            $timestamp = strtotime(date("Y-m-d"));
            /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
            $insertion_db2[] = array(
                "table" => 'prepaid_tickets',
                "columns" => array(
                    "used" => '1',
                    "CONCAT_VALUE" => array("action_performed" => ', IMP_EXCEP_SCAN'),
                    "scanned_at" => $timestamp,
                    "redeem_date_time" => gmdate("Y-m-d H:i:s"),
                    "updated_at" => gmdate('Y-m-d H:i:s')
                ),
                "where" => "visitor_group_no IN(" . implode(',', $vgn_array) . ")",
                "redeem" => 1
            );

            $insertion_db2[] = array(
                "table" => 'visitor_tickets',
                "columns" => array(
                    "used" => '1',
                    "CONCAT_VALUE" => array("action_performed" => ', IMP_EXCEP_SCAN'),
                    "visit_date" => $timestamp,
                    "updated_at" => gmdate('Y-m-d H:i:s')
                ),
                "where" => " vt_group_no IN(" . implode(',', $vgn_array) . ")  ",
                "redeem" => 1
            );

            if ($_SERVER['HTTP_HOST'] != 'localhost' && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.10.10.') && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.8.0.')) {
                include_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library(array('Sns', 'Sqs'));
                $sns_object = new Sns();
                $sqs_object = new Sqs();
            }
            //Send DB queries in queue
            if (!empty($insertion_db2)) {
                $request_array['db2_insertion'] = $insertion_db2;
                $request_array['write_in_mpos_logs'] = 1;
                $request_array['action'] = 'mpos_exception_updation';
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;
                print_r($request_array);
                $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date("Y-m-d H:i:s")] = $request_array;
                // This Fn used to send notification with data on AWS panel.
                if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == strstr($_SERVER['HTTP_HOST'], '10.10.10.') || $_SERVER['HTTP_HOST'] == strstr($_SERVER['HTTP_HOST'], '10.8.0.')) {
                    local_queue($aws_message, 'UPDATE_DB2');
                } else {
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                    // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                    if ($MessageId) {
                        $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }
                }
            }
        }

        if (!empty($visitorGroupNo) && !empty($foundVgns)) {

            $diff = array_diff($visitorGroupNo, $foundVgns);
            if (!empty($diff)) {
                echo "<pre>";
                print_r("VGNS not found in PT = " . implode(",", $diff));
                $this->importbooking_model->update_processed_status_in_import_booking_orders_to_insert_in_vt($diff, "4");
            }
        }
        $logsData['prepare_vt_insertion_' . date("Y-m-d H:i:s")] = $logs;
        $this->log_library->write_log($logsData, 'import_booking', 'prepare_vt_insertion');
        echo "<pre>";
        print_r("END = " . date('Y-m-d H:i:s'));
    }

    /* #endregion to insert data in visitor tickets by fetching from import booking orders */

    /* #region to Send Success order Booking Email   */

    /* #region tourcms_update_in_db_manual_hit  */

    /**
     * tourcms_update_in_db_manual_hit
     *
     * @param  mixed $offset
     * @param  mixed $length
     * @return void
     */
    function tourcms_update_in_db_manual_hit($offset, $length)
    {
        $all_data = array();
        $data = array();
        /* READ DATA FROM FILE */
        $dir = 'qrcodes/images/';
        if (is_dir($dir)) {
            $files = scandir($dir);
            if ($files) {
                foreach ($files as $file) {
                    if (!is_dir($file)) {
                        preg_match_all("'tourcms_webhook_db2_queries(.*?)php'si", $file, $match);
                        if (!empty($match[0])) {
                            $file_content = file_get_contents($dir . $file);
                            preg_match_all("'Request_identifier -(.*?)End'si", $file_content, $match2);
                            if (!empty($match2[1])) {
                                foreach ($match2[1] as $row) {
                                    $all_data[] = json_decode($row, TRUE);
                                }
                            }
                        }
                    }
                }
            }
        }
        $offset = !empty($offset) ? $offset : 0;
        $length = !empty($length) ? $length : count($all_data);
        $data = array_splice($all_data, $offset, $length);
        echo "<pre>";
        print_r("offset: " . $offset);
        echo "</pre>";
        echo "<pre>";
        print_r("Length: " . $length);
        echo "</pre>";
        echo "<pre>";
        print_r($data);
        echo "</pre>";
        if (!empty($data)) {
            foreach ($data as $queue_data) {
                /* BOC If DB2 queries exist in array then execute in Secondary DB */
                if (!empty($queue_data['db2'])) {
                    foreach ($queue_data['db2'] as $query) {
                        if (trim($query) == '') {
                            continue;
                        }
                        $query = trim($query);
                        $this->secondarydb->db->query($query);
                        if ($this->secondarydb->db->affected_rows() == 0) {
                            $query_error_flag = 1;
                        }
                        // To update the data in RDS realtime
                        if ((strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview')) && SYNCH_WITH_RDS_REALTIME) {
                            $this->fourthdb->db->query($query);
                        }
                    }
                    if ($query_error_flag && 0) {
                        $this->highalert_email($queue_data['api_visitor_group_no'], "Prepaid_tickets", $queue_data);
                    }
                }
                /* EOC If DB2 queries exist in array then execute in Secondary DB */

                /* BOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
                if (!empty($queue_data['update_redeem_table'])) {
                    $this->load->model('venue_model');
                    $this->venue_model->update_redeem_table($queue_data['update_redeem_table']);
                }
                /* EOC When UPDATE IN DB QUEUE SNS HIT FROM VENUE app confirm API */
            }
        }
    }

    /* #endregion tourcms_update_in_db_manual_hit */

    /* #region highalert_email  */

    /**
     * highalert_email
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $tbl
     * @param  mixed $other_data
     * @return void
     */
    function highalert_email($visitor_group_no = '', $tbl = '', $other_data = array())
    {
        $msg = "Visitor_group_no: " . $visitor_group_no . "<br><br>Request time = " . gmdate("Y M d H:i:s") . "<br><br> Order not redeemed in DB2. Consider it in On priority from table" . $tbl;
        if (!empty($other_data)) {
            $msg .= "<br/><br/><b>DATA</b>" . json_encode($other_data);
        }
        $arraylist['emailc'] = 'prionotification@gmail.com';
        $arraylist['html'] = $msg;
        $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
        $arraylist['fromname'] = MESSAGE_SERVICE_NAME;
        $arraylist['subject'] = 'SQS Processor HIGH ALERT EMAIL for TOURCMS REDEEMED orders';
        $arraylist['attachments'] = array();
        $arraylist['BCC'] = array();

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
                /*** Commented for local server local_queue($aws_message, 'EVENT_TOPIC_ARN'); ***/
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId) {
                    $sns_object = new Sns();
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, EVENT_TOPIC_ARN);
                }
            }
        }
    }

    /* #endregion highalert_email */

    /* #region function To insert record on tem_analytic records table when confirm any ticket. */

    /**
     * update_temp_analytic_records_from_venue_app
     *
     * @return void
     * @author Taran singh <taran.intersoft@gmail.com> on 31 Aug, 2017
     */
    function update_temp_analytic_records_from_venue_app()
    {
        $update_visitor_tickts = 0;
        $_REQUEST = file_get_contents('php://input');
        $string = json_decode($_REQUEST, true);
        $main_string = trim($string['Message']);
        $this->CreateLog('main_temp_analytic_SNS.php', 'request', array('Temp update SNS' => gmdate('Y-m-d H:i:s'), 'request=> ' => $main_string));

        /* In Case of REQUEST FROM "MANIFEST" OR REQUEST FROM "OVERVIEW" OR FROM "CANCEL_OVERVIEW" */
        if (strstr($main_string, 'MANIFEST=>') || strstr($main_string, 'OVERVIEW=>') || strstr($main_string, 'OVERVIEW_CANCEL=>')) {
            $request_data = json_decode($main_string, true);
        } else {/* In Case of VENUE APP OR API */
            if (strstr($main_string, '{')) { /* In Case of REQUEST FROM  VENUE APP */
                $request_data = json_decode($main_string, true);
                $update_visitor_tickts = 1;
            } else {/* In Case of REQUEST FROM  API */
                $request_data = trim($string['Message']);
            }
        }

        if (!empty($request_data)) {
            $visitor_tickets_id = '';
            /* In Case of REQUEST FROM "MANIFEST" OR REQUEST FROM "OVERVIEW" OR FROM "CANCEL_OVERVIEW" */
            if (strstr($main_string, 'MANIFEST=>')) {
                $visitor_group_no = $request_data['visitor_group_no'];
                $ticket_id = $request_data['ticket_id'];
                $selected_date = $request_data['selected_date'];
                $from_time = $request_data['from_time'];
                $to_time = $request_data['to_time'];
            } else if (strstr($main_string, 'OVERVIEW=>')) {
                $visitor_group_no = $request_data['visitor_group_no'];
                $ticket_id = $request_data['ticket_id'];
                if ($request_data['is_reservation'] == '1') {
                    $selected_date = $request_data['selected_date'];
                    $from_time = $request_data['from_time'];
                }
            } else if (strstr($main_string, 'OVERVIEW_CANCEL=>')) {
                $transaction_id = $request_data['transaction_id'];
                $from_overview_cancel = 1;
            } else {/* In Case of VENUE APP OR API */
                if (strstr($main_string, '{')) {/* In Case of VENUE APP */
                    $where = $request_data['where'];
                    if (strstr($where, '_')) {
                        $request = explode('_', $where);
                        $visitor_group_no = $request[0];
                        $visitor_tickets_id = $request[1];
                    } else {
                        $visitor_group_no = $where;
                    }
                    if ($visitor_tickets_id == '0') {
                        $visitor_tickets_id = '';
                    }
                    $from_venue_app = 1;
                } else { /* In Case of API */
                    if (strstr($request_data, '_')) {
                        $request = explode('_', $request_data);
                        $visitor_group_no = $request[0];
                        $visitor_tickets_id = $request[1];
                    } else {
                        $visitor_group_no = $request_data;
                    }
                }
            }

            /* BOC to update booking status and required fields in visitor ticket IN CASE OF VENUE APP */
            if ($update_visitor_tickts) {
                $action_performed = $request_data['action_performed'];
                unset($request_data['action_performed']);
                unset($request_data['where']);
                $this->secondarydb->db->set('action_performed', "CONCAT(action_performed, '" . $action_performed . "')", FALSE);
                if ($visitor_tickets_id != '') {
                    $this->secondarydb->db->update("visitor_tickets", $request_data, array("transaction_id" => $transaction_id));
                } else {
                    $this->secondarydb->db->update("visitor_tickets", $request_data, array("vt_group_no" => $visitor_group_no));
                }

                // To update the data in RDS realtime
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                    $this->fourthdb->db->set('action_performed', "CONCAT(action_performed, '" . $action_performed . "')", FALSE);
                    if ($visitor_tickets_id != '') {
                        $this->fourthdb->db->update("visitor_tickets", $request_data, array("transaction_id" => $transaction_id));
                    } else {
                        $this->fourthdb->db->update("visitor_tickets", $request_data, array("vt_group_no" => $visitor_group_no));
                    }
                }
            }
            /* EOC to update booking status and required fields in visitor ticket */
        }
    }

    /* #endregion function To insert record on tem_analytic records table when confirm any ticket. */

    /* #region pos_update_redeem_tickets  */

    /**
     * pos_update_redeem_tickets
     *
     * @return void
     */
    function pos_update_redeem_tickets()
    {
        // Queue process check
        if (STOP_QUEUE) {
            exit();
        }

        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_object = new Sns();
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        // Fetch the raw POST body containing the message    
        $queueUrl = UPDATE_POS_REDEEM_URL;
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

        if (!empty($messages)) {
            foreach ($messages as $message) {
                /* BOC It remove message from SQS queue for next entry. */
                if (SERVER_ENVIRONMENT != 'Local') {
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    /* EOC It remove message from SQS queue for next entry. */
                    /* BOC extract and convert data in array from queue */
                    $string = $message['Body'];
                } else {
                    $string = $message;
                }
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $queue_data = json_decode($string, true);

                $expedia_hotel_list = explode(',', Expedia_Hotel_List);

                $hto_ids = $queue_data['hto_ids'];
                $payment_method = $queue_data['payment_method'];
                $channel_type = $queue_data['channel_type'];
                $from_time = $queue_data['redeem_by_from_time'];
                $to_time = $queue_data['redeem_by_to_time'];

                $current_day = date('Y-m-d h:i:s');
                $sns_messages = array();
                $sns_message_pt = array();
                $sns_message_vt = array();
                if ($to_time == "") {
                    $to_time = $from_time;
                    $time = strtotime($from_time) - (15 * 60);
                    $from_time = date("H:i", $time);
                } else {
                    if (strlen($to_time) > 5) {
                        $to_time = substr($to_time, 0, 5);
                    }
                }
                $timeslot = isset($queue_data['redeem_timeslot']) ? $queue_data['redeem_timeslot'] : '';        
                $redeem_ticket_id = $queue_data['redeem_ticket_id'];
                $request['visitor_group_no'] = $queue_data['visitor_group_no'];                
                $prepaid_table ='prepaid_tickets';
                $vt_table ='visitor_tickets';
                /*payment data updation */
                
                $order_details = $this->pos_model->find('hotel_ticket_overview', array('select' => 'activation_method,hotel_id', 'where' => 'visitor_group_no  = ' . $queue_data['visitor_group_no'] . ''));

                // temp condition
                $pt_data = $this->pos_model->find('prepaid_tickets', array('select' => 'activation_method', 'where' => 'visitor_group_no  = ' . $queue_data['visitor_group_no'] . ''));
                $this->CreateLog('update_redeem_tickets.php', 'step-0', array("hto==> : " => $order_details[0]['activation_method'], "pt : " => $pt_data[0]['activation_method']));
                $order_details[0]['activation_method'] = $pt_data[0]['activation_method'];


                if ($order_details[0]['activation_method'] == 16) {
                    $prepaid_data['tp_payment_method'] = 1;
                    $prepaid_data['order_confirm_date'] = $current_day;
                    $prepaid_data['payment_date'] = $current_day;
                    $visitor_data['tp_payment_method'] = 1;
                    $visitor_data['order_confirm_date'] = $current_day;
                    $visitor_data['payment_date'] = $current_day;
                    $hto_data['tp_payment_method'] = 1;
                } else if ($order_details[0]['activation_method'] == 14) {
                    $prepaid_data['tp_payment_method'] = 0;
                    $visitor_data['tp_payment_method'] = 0;
                    $hto_data['tp_payment_method'] = 0;
                } else if ($order_details[0]['activation_method'] == 15) {
                    $prepaid_data['tp_payment_method'] = 0;
                    $prepaid_data['order_confirm_date'] = $current_day;
                    $visitor_data['tp_payment_method'] = 0;
                    $visitor_data['order_confirm_date'] = $current_day;
                    $hto_data['tp_payment_method'] = 0;
                }

                if(isset($queue_data['redeem_by_ticket_id']) && !empty($queue_data['redeem_by_ticket_id'])) {
                    $redeemed_by_ticket_id_detail = $this->pos_model->find('modeventcontent', array('select' => 'is_own_capacity,shared_capacity_id,own_capacity_id', 'where' => 'mec_id  = ' . $queue_data['redeem_by_ticket_id'] . ''));
                } else {
                    $redeemed_by_ticket_id_detail = array();
                }

                $prepaid_data['updated_at'] = $current_day;
                $prepaid_data['voucher_updated_by'] = $queue_data['redeem_by_cashier_id'];
                $prepaid_data['voucher_updated_by_name'] = $queue_data['redeem_by_cashier_name'];
                $prepaid_data['used'] = '1';
                $prepaid_data['booking_status'] = '1';
                $prepaid_data['scanned_at'] = strtotime($current_day);
                if ($payment_method == "1") {
                    $prepaid_data['redeem_method'] = "Voucher";
                } else if ($payment_method == "2") {
                    $prepaid_data['redeem_method'] = "Cash";
                } else if ($payment_method == "3") {
                    $prepaid_data['redeem_method'] = "Card";
                }

                if ($queue_data['is_reserved'] == '0') {
                    $prepaid_data['redeem_by_ticket_id'] = $queue_data['redeem_by_ticket_id'];                    
                    if(!empty($redeemed_by_ticket_id_detail)) {
                        if($redeemed_by_ticket_id_detail[0]['is_own_capacity'] == '3') {
                            $prepaid_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'].','.$redeemed_by_ticket_id_detail[0]['own_capacity_id'];
                        } else {
                            $prepaid_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'];
                        }
                    }
                    $prepaid_data['redeem_by_ticket_title'] = $queue_data['redeem_by_ticket_title'];
                    $prepaid_data['selected_date'] = $queue_data['redeem_by_selected_date'];
                    $prepaid_data['from_time'] = $from_time;
                    $prepaid_data['to_time'] = $to_time;
                    $where['visitor_group_no'] = $queue_data['visitor_group_no'];
                    $where['ticket_id'] = $queue_data['redeem_ticket_id'];
                } else {
                    $where['visitor_group_no'] = $queue_data['visitor_group_no'];
                    $where['ticket_id'] = $queue_data['redeem_ticket_id'];
                    $where['selected_date'] = $queue_data['redeem_by_selected_date'];
                    $where['from_time'] = $from_time;
                    $where['to_time'] = $to_time;
                }
                $where['booking_status'] = "0";
                $where['order_status'] = "0";
                $this->db->set('action_performed', "CONCAT(action_performed, ', CPOS_SC')", FALSE);
                unset($prepaid_data['order_confirm_date']);
                unset($prepaid_data['payment_date']);
                unset($prepaid_data['updated_at']);
                unset($prepaid_data['voucher_updated_by']);
                unset($prepaid_data['voucher_updated_by_name']);
                unset($prepaid_data['redeem_method']);
                unset($prepaid_data['redeem_by_ticket_id']);
                unset($prepaid_data['redeem_by_ticket_title']);
                $this->db->update('prepaid_tickets', $prepaid_data, $where);
                $last_affected_rows = $this->db->affected_rows();

                $this->CreateLog('update_expedia_table.php', $order_details[0]['hotel_id'], array("hotel_id" => $order_details[0]['hotel_id']));
                unset($where['booking_status']);
                if (in_array($order_details[0]['hotel_id'], $expedia_hotel_list)) {
                    $expedia_prepaid_data['scanned_at'] = $prepaid_data['scanned_at'];
                    $expedia_prepaid_data['used'] = $prepaid_data['used'];
                    $expedia_prepaid_data['booking_status'] = $prepaid_data['booking_status'];
                    $this->db->set('action_performed', "CONCAT(action_performed, ', CPOS_SC')", FALSE);
                    $this->db->update('expedia_prepaid_tickets', $expedia_prepaid_data, $where);
                    $this->CreateLog('update_expedia_table.php', $this->db->last_query(), array("affected_rows" => $this->db->affected_rows()));
                }

                if ($last_affected_rows == '0') {
                    $this->db->set('action_performed', "CONCAT(action_performed, ', CPOS_SC')", FALSE);
                }
                $date_scanned = $current_day;
                $new_prepaid_data = array();
                if ($queue_data['is_reserved'] == '0') {
                    $new_prepaid_data['redeem_by_ticket_id'] = $queue_data['redeem_by_ticket_id'];
                    if(!empty($redeemed_by_ticket_id_detail)) {
                        if($redeemed_by_ticket_id_detail[0]['is_own_capacity'] == '3') {
                            $new_prepaid_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'].','.$redeemed_by_ticket_id_detail[0]['own_capacity_id'];
                        } else {
                            $new_prepaid_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'];
                        }
                    }
                    $new_prepaid_data['redeem_by_ticket_title'] = $queue_data['redeem_by_ticket_title'];
                    $new_prepaid_data['selected_date'] = $queue_data['redeem_by_selected_date'];
                    $new_prepaid_data['from_time'] = $from_time;
                    $new_prepaid_data['to_time'] = $to_time;
                    $new_prepaid_data['used'] = "1";
                    $new_prepaid_data['updated_at'] = $date_scanned;
                    $new_prepaid_data['redeem_date_time'] = $date_scanned;

                    $new_prepaid_data['scanned_at'] = strtotime($date_scanned);
                } else {
                    $new_prepaid_data['used'] = "1";
                    $new_prepaid_data['updated_at'] = $date_scanned;
                    $new_prepaid_data['redeem_date_time'] = $date_scanned;
                    $new_prepaid_data['scanned_at'] = strtotime($date_scanned);
                }
                unset($new_prepaid_data['redeem_by_ticket_id']);
                unset($new_prepaid_data['redeem_by_ticket_title']);
                unset($new_prepaid_data['updated_at']);
                $this->db->update('prepaid_tickets', $new_prepaid_data, $where);

                $update_redeem_table = array();
                $update_redeem_table = $where;
                $update_redeem_table['voucher_updated_by'] = $queue_data['redeem_by_cashier_id'];
                $update_redeem_table['voucher_updated_by_name'] = $queue_data['redeem_by_cashier_name'];

                if (in_array($order_details[0]['hotel_id'], $expedia_hotel_list)) {

                    unset($where['booking_status']);
                    $this->db->update('expedia_prepaid_tickets', array('used' => "1", 'scanned_at' => strtotime($date_scanned)), $where);
                }

                $this->CreateLog('cpos_sc.php', $this->db->last_query(), array("affected_rows" => $this->db->affected_rows()));

                $where['pos_point_id'] = "0";

                $pos_point_name = $this->pos_model->find('pos_points_setting', array('select' => 'pos_point_name', 'where' => 'pos_point_id = ' . $queue_data['pos_point_id']));
                $queue_data['pos_point_name'] = $pos_point_name[0]['pos_point_name'];

                $this->db->update('prepaid_tickets', array('pos_point_id' => $queue_data['pos_point_id'], 'pos_point_name' => $queue_data['pos_point_name']), $where);

                unset($where['pos_point_id']);
                if ($payment_method == "1") {
                    $hto_data['redeem_method'] = "Voucher";
                } else if ($payment_method == "2") {
                    $hto_data['redeem_method'] = "Cash";
                } else if ($payment_method == "3") {
                    $hto_data['redeem_method'] = "Card";
                    if(!empty($queue_data['adyen_response_details'])) {
                        $adyen_response_details = json_decode($queue_data['adyen_response_details'], true);
                        $hto_data['pspReference'] = $adyen_response_details['pspReference'];
                        $hto_data['merchantReference'] = $adyen_response_details['merchantReference'];
                        $hto_data['paymentMethod'] = $adyen_response_details['cardType'];
                        $hto_data['merchantAccountCode'] = $adyen_response_details['merchantAccount'];
                    }
                }

                if(in_array($order_details[0]['hotel_id'], $expedia_hotel_list)){
                $this->db->update('expedia_prepaid_tickets', array('pos_point_id' => $queue_data['pos_point_id'], 'pos_point_name' => $queue_data['pos_point_name']), $where);
                }
                
                $query_where = "pt1.visitor_group_no = '" . $queue_data['visitor_group_no'] . "'  and pt1.order_status = '0' AND pt1.ticket_id = '" . $queue_data['redeem_ticket_id'] . "'";
                $select_query = "SELECT * from " . $prepaid_table . " pt1 where " . $query_where . " and pt1.version =(select max(version) from " . $prepaid_table . " pt2 WHERE pt1.prepaid_ticket_id= pt2.prepaid_ticket_id AND " . $query_where . ")";
                $pt_result = $this->secondarydb->rodb->query($select_query);
                $prepaid_ticket_records = $pt_result->result_array();
                foreach ($prepaid_ticket_records as $prepaid_ticket_record) {
                    $insert_prepaid_ticket = array();
                    $insert_prepaid_ticket = $prepaid_ticket_record;
                    $insert_prepaid_ticket['action_performed'] .= ', CPOS_SC';
                    if ($insert_prepaid_ticket['booking_status'] == '0') {
                        if ($insert_prepaid_ticket['activation_method'] == 16) {
                            $insert_prepaid_ticket['tp_payment_method'] = 1;
                            $insert_prepaid_ticket['order_confirm_date'] = $current_day;
                            $insert_prepaid_ticket['payment_date'] = $current_day;
                        } else if ($insert_prepaid_ticket['activation_method'] == 14) {
                            $insert_prepaid_ticket['tp_payment_method'] = 0;
                        } else if ($insert_prepaid_ticket['activation_method'] == 15) {
                            $insert_prepaid_ticket['tp_payment_method'] = 0;
                            $insert_prepaid_ticket['order_confirm_date'] = $current_day;
                        }

                        $insert_prepaid_ticket['updated_at'] = $current_day;
                        $insert_prepaid_ticket['voucher_updated_by'] = $queue_data['redeem_by_cashier_id'];
                        $insert_prepaid_ticket['voucher_updated_by_name'] = $queue_data['redeem_by_cashier_name'];
                        $insert_prepaid_ticket['used'] = '1';
                        $insert_prepaid_ticket['booking_status'] = '1';
                        $insert_prepaid_ticket['scanned_at'] = strtotime($current_day);
                        if ($payment_method == "1") {
                            $prepaid_data['redeem_method'] = "Voucher";
                        } else if ($payment_method == "2") {
                            $prepaid_data['redeem_method'] = "Cash";
                        } else if ($payment_method == "3") {
                            $prepaid_data['redeem_method'] = "Card";
                            if(!empty($queue_data['adyen_response_details'])) {
                                $adyen_response_details = json_decode($queue_data['adyen_response_details'], true);
                                $insert_prepaid_ticket['pspReference'] = $adyen_response_details['pspReference'];
                                $insert_prepaid_ticket['merchantReference'] = $adyen_response_details['merchantReference'];
                            }
                        }

                        if ($queue_data['is_reserved'] == '0') {
                            $insert_prepaid_ticket['redeem_by_ticket_id'] = $queue_data['redeem_by_ticket_id'];
                            if(!empty($redeemed_by_ticket_id_detail)) {
                                if($redeemed_by_ticket_id_detail[0]['is_own_capacity'] == '3') {
                                    $insert_prepaid_ticket['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'].','.$redeemed_by_ticket_id_detail[0]['own_capacity_id'];
                                } else {
                                    $insert_prepaid_ticket['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'];
                                }
                            }
                            $insert_prepaid_ticket['redeem_by_ticket_title'] = $queue_data['redeem_by_ticket_title'];
                            $insert_prepaid_ticket['selected_date'] = $queue_data['redeem_by_selected_date'];
                            $insert_prepaid_ticket['from_time'] = $from_time;
                            $insert_prepaid_ticket['to_time'] = $to_time;
                        }
                    } else {
                        if ($queue_data['is_reserved'] == '0') {
                            $insert_prepaid_ticket['redeem_by_ticket_id'] = $queue_data['redeem_by_ticket_id'];
                            if(!empty($redeemed_by_ticket_id_detail)) {
                                if($redeemed_by_ticket_id_detail[0]['is_own_capacity'] == '3') {
                                    $insert_prepaid_ticket['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'].','.$redeemed_by_ticket_id_detail[0]['own_capacity_id'];
                                } else {
                                    $insert_prepaid_ticket['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'];
                                }
                            }
                            $insert_prepaid_ticket['redeem_by_ticket_title'] = $queue_data['redeem_by_ticket_title'];
                            $insert_prepaid_ticket['selected_date'] = $queue_data['redeem_by_selected_date'];
                            $insert_prepaid_ticket['from_time'] = $from_time;
                            $insert_prepaid_ticket['to_time'] = $to_time;
                        }
                        $insert_prepaid_ticket['used'] = "1";
                        $insert_prepaid_ticket['updated_at'] = $date_scanned;
                        $insert_prepaid_ticket['redeem_date_time'] = $date_scanned;
                        $insert_prepaid_ticket['scanned_at'] = strtotime($date_scanned);
                    }
                    $insert_prepaid_ticket['version'] = $insert_prepaid_ticket['version'] + 1;
                    if($insert_prepaid_ticket['pos_point_id'] == "0") {
                        $insert_prepaid_ticket['pos_point_id'] = $queue_data['pos_point_id'];
                        $insert_prepaid_ticket['pos_point_name'] = $queue_data['pos_point_name'];
                    }
                    unset($insert_prepaid_ticket['last_modified_at']);   
                    $pt_query = $this->secondarydb->db->insert_string($prepaid_table, $insert_prepaid_ticket);
                    if ($insert_prepaid_ticket['activation_method'] == 14 || $channel_type != 2) {
                        $sns_messages[] = $pt_query;
                    } else {
                        $sns_message_pt[] = $pt_query;
                    }
                }

                $this->pos_model->update_cashier_register($queue_data['total_amount'], strtolower($hto_data['redeem_method']) . "_redeem", $queue_data['redeem_by_cashier_id'], $queue_data['pos_point_id'], '', '', $queue_data['visitor_group_no'], $order_details[0]['hotel_id']);
                $hto_data['voucher_updated_by'] = $queue_data['redeem_by_cashier_id'];
                $hto_ids = explode(",", $hto_ids);
                $this->db->where_in('id', $hto_ids);
                $this->db->update('hotel_ticket_overview', $hto_data);
                $sns_messages[] = $this->db->last_query();
                //Update Visitor tickets table                
                $visitor_data['voucher_updated_by'] = $queue_data['redeem_by_cashier_id'];
                $visitor_data['voucher_updated_by_name'] = $queue_data['redeem_by_cashier_name'];
                $visitor_data['used'] = '1';
                $visitor_data['booking_status'] = '1';
                $visitor_data['visit_date'] = strtotime($current_day);
                if ($payment_method == "1") {
                    $visitor_data['redeem_method'] = "Voucher";
                } else if ($payment_method == "2") {
                    $visitor_data['redeem_method'] = "Cash";
                } else if ($payment_method == "3") {
                    $visitor_data['redeem_method'] = "Card";
                }
                if ($queue_data['is_reserved'] == '0') {
                    if(!empty($redeemed_by_ticket_id_detail)) {
                        if($redeemed_by_ticket_id_detail[0]['is_own_capacity'] == '3') {
                            $visitor_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'].','.$redeemed_by_ticket_id_detail[0]['own_capacity_id'];
                        } else {
                            $visitor_data['shared_capacity_id'] = $redeemed_by_ticket_id_detail[0]['shared_capacity_id'];
                        }
                    }
                    $visitor_data['redeem_by_ticket_id'] = $queue_data['redeem_by_ticket_id'];
                    $visitor_data['redeem_by_ticket_title'] = $queue_data['redeem_by_ticket_title'];
                    $visitor_data['selected_date'] = $queue_data['redeem_by_selected_date'];
                    $visitor_data['from_time'] = $from_time;
                    $visitor_data['to_time'] = $to_time;
                    $where_visit['vt_group_no'] = $queue_data['visitor_group_no'];
                    $where_visit['ticketId'] = $queue_data['redeem_ticket_id'];
                } else {
                    $where_visit['vt_group_no'] = $queue_data['visitor_group_no'];
                    $where_visit['ticketId'] = $queue_data['redeem_ticket_id'];
                    $where_visit['selected_date'] = $queue_data['redeem_by_selected_date'];
                    $where_visit['from_time'] = $from_time;
                    $where_visit['to_time'] = $to_time;
                }
                $where_visit['deleted'] = '0';
                $where_visit['booking_status'] = "0";    
                            
                $visitor_query = 'update visitor_tickets set action_performed = CONCAT(action_performed, ", CPOS_SC"), updated_at = "'.gmdate('Y-m-d H:i;s').'", voucher_updated_by = "' . $queue_data['redeem_by_cashier_id'] . '"';
                foreach ($visitor_data as $vkey => $visitor_cols) {
                    if ($vkey != 'voucher_updated_by') {
                        $visitor_query .= ', ' . $vkey . ' = "' . $visitor_cols . '"';
                    }
                }
                $visitor_query .= ' where vt_group_no = "' . $queue_data['visitor_group_no'] . '" and invoice_status not in ("10","11")';
                foreach ($where_visit as $wkey => $visitor_vals) {
                    if ($wkey != 'vt_group_no') {
                        $visitor_query .= ' and ' . $wkey . '= "' . $visitor_vals . '"';
                    }
                }
                unset($where_visit['booking_status']);        
                //unset($visitor_data['visit_date']);        

                $visitor_query = 'update visitor_tickets set action_performed = CONCAT(action_performed, ", CPOS_SC"), updated_at = "'.gmdate('Y-m-d H:i;s').'", voucher_updated_by = "' . $queue_data['redeem_by_cashier_id'] . '"';
                foreach ($visitor_data as $vkey => $visitor_cols) {
                    if ($vkey != 'voucher_updated_by') {
                        $visitor_query .= ', ' . $vkey . ' = "' . $visitor_cols . '"';
                    }
                }
                $visitor_query .= ' where vt_group_no = "' . $queue_data['visitor_group_no'] . '" and invoice_status not in ("10","11")';
                foreach ($where_visit as $wkey => $visitor_vals) {
                    if ($wkey != 'vt_group_no') {
                        $visitor_query .= ' and ' . $wkey . '= "' . $visitor_vals . '"';
                    }
                }

                $visitor_query = 'update visitor_tickets set pos_point_id = "'.$queue_data['pos_point_id'].'", pos_point_name = "'.$queue_data['pos_point_name'].'"';        
                $visitor_query .= ' where pos_point_id = "0" and vt_group_no = "' . $queue_data['visitor_group_no'] . '" and invoice_status not in ("10","11")';
                foreach ($where_visit as $wkey => $visitor_vals) {
                    if ($wkey != 'vt_group_no') {
                        $visitor_query .= ' and ' . $wkey . '= "' . $visitor_vals . '"';
                    }
                }

                $query_where = "vt1.vt_group_no = '" . $queue_data['visitor_group_no'] . "' AND vt1.invoice_status not in ('10','11') AND vt1.ticketId = '" . $queue_data['redeem_ticket_id'] . "' AND vt1.deleted = '0'";
                $select_query = "SELECT * from " . $vt_table . " vt1 where " . $query_where . " and vt1.version =(select max(version) from " . $vt_table . " vt2 WHERE vt1.id= vt2.id AND " . $query_where . ")";
                $pt_result = $this->secondarydb->rodb->query($select_query);
                $visitor_ticket_records = $pt_result->result_array();
                foreach ($visitor_ticket_records as $visitor_ticket_record) {
                    $insert_visitor_tickets = array();
                    $insert_visitor_tickets = $visitor_ticket_record;
                    $insert_visitor_tickets['action_performed'] .= ', CPOS_SC';
                    foreach ($visitor_data as $vkey => $visitor_cols) {
                        if ($vkey != 'voucher_updated_by') {
                            $insert_visitor_tickets[$vkey] = $visitor_cols;
                        }
                    }
                    $insert_visitor_tickets['version'] = $insert_visitor_tickets['version'] + 1;
                    if($insert_visitor_tickets['pos_point_id'] == "0") {
                        $insert_visitor_tickets['pos_point_id'] = $queue_data['pos_point_id'];
                        $insert_visitor_tickets['pos_point_name'] = $queue_data['pos_point_name'];
                    }
                    $insert_visitor_tickets['updated_at'] = $date_scanned;
                    if ($insert_visitor_tickets['booking_status'] == '0') {
                        $insert_visitor_tickets['order_confirm_date'] = $date_scanned;
                    }
                    $insert_visitor_tickets['visit_date_time'] = $date_scanned;
                    unset($insert_visitor_tickets['last_modified_at']);
                    $vt_query = $this->secondarydb->db->insert_string($vt_table, $insert_visitor_tickets);
                    if ($insert_visitor_tickets['activation_method'] == 14 || $channel_type != 2) {
                        $sns_messages[] = $vt_query;
                    } else {
                        $sns_message_vt[] = $vt_query;
                    }
                }


                if (!empty($sns_messages)) {
                    $request_array['db2'] = $sns_messages;
                    $request_array['visitor_group_no'] = $queue_data['visitor_group_no'];

                    // to insert data in VT table only for group booking orders ( not for on invoice )
                    if (in_array($order_details[0]['activation_method'], array('3', '13', '15', '16')) && $channel_type == 2) {
                        $request_array['confirm_order'] = array('visitor_group_no' => $queue_data['visitor_group_no'], 'action_performed' => 'CPOS_SC', 'sns_message_pt' => $sns_message_pt, 'sns_message_vt' => $sns_message_vt);
                    }

                    $request_string = json_encode($request_array);
                    $this->CreateLog('update_redeem_tickets.php', 'step-2', array("data : " => $request_string));
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    if ($_SERVER['HTTP_HOST'] != 'localhost' && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.10.10.') && $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.8.0.110')) {
                        $queueUrl = UPDATE_DB_QUEUE_URL;
                        // This Fn used to send notification with data on AWS panel. 
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                        if ($MessageId) {
                            $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                        }
                    } else {
                        local_queue($aws_message, 'UPDATE_DB_ARN');
                    }
                }

                if (SYNCH_WITH_RDS) {
                    $this->CreateLog('redeem_in_rds.php', 'step-1', array("data : " => $queue_data['visitor_group_no']));
                    $aws_data = array();
                    $aws_data['rds']['prepaid_tickets'] = 1;
                    $aws_data['rds']['visitor_group_number'] = !empty($queue_data['visitor_group_no']) ? $queue_data['visitor_group_no'] : 0;
                    $aws_data['rds']['visitor_tickets'] = 1;

                    $aws_message = base64_encode(gzcompress(json_encode($aws_data)));

                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    $MessageId = $sqs_object->sendMessage(RDS_UPDATE_QUEUE_URL, $aws_message);

                    if ($MessageId) {
                        $sns_object->publish('hello', RDS_UPDATE_TOPIC_ARN);
                    }
                }
            }
        }
    }

    /* #endregion pos_update_redeem_tickets */

    /* #region update_contact_direct  */

    /**
     * update_contact_direct
     *
     * @return void
     */
    function update_contact()
    {
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $queueUrl = UPDATE_CONTACT_QUEUE_URL;
        $messages = array();
        $distributor_id = '';
        $notify_event = 'CONTACT_UPDATE';
        if (SERVER_ENVIRONMENT == 'Local') {
            $postBody = file_get_contents('php://input');
            $messages = array(
                'Body' => $postBody
            );
        } else {
            if (STOP_QUEUE) {
                exit();
            }
            $request_headers = getallheaders();
            if (SERVER_ENVIRONMENT != 'Local' && (!isset($request_headers['X-Amz-Sns-Topic-Arn']) || $request_headers['X-Amz-Sns-Topic-Arn'] != UPDATE_CONTACT_ARN)) {
                $this->output->set_header('HTTP/1.1 401 Unauthorized');
                $this->output->set_status_header(401, "Unauthorized");
                exit;
            }

            $messages = $sqs_object->receiveMessage($queueUrl);
            $messages = $messages->getPath('Messages');
        }
        /* It receive message from given queue. */
        if (!empty($messages)) {
            foreach ($messages as $message) {
                if (SERVER_ENVIRONMENT != 'Local') {
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    /* EOC It remove message from SQS queue for next entry. */
                    /* BOC extract and convert data in array from queue */
                    $string = $message['Body'];
                } else {
                    $string = $postBody;
                }
                $string = gzuncompress(base64_decode($string));
                $this->CreateLog('queue_log.php', $string, array());
                $string = utf8_decode($string);
                $data = json_decode($string, true);
                $this->pos_model->contact_update($data['guest_contact'], $data['order_contacts']);
                if (!empty($data['guest_contact'])) {
                    $distributor_id =  $data['guest_contact']['distributor_id'] ?? '';
                }
                if (ENABLE_ELASTIC_SEARCH_SYNC == 1 && !empty($data['order_references'])) {
                    $this->load->library('elastic_search');
                    $other_data = [];
                    $other_data['request_reference'] = $data['order_references'];
                    $other_data['hotel_id'] = !empty($distributor_id) ? $distributor_id : '';
                    $other_data['fastly_purge_action'] = $notify_event;
                    $this->elastic_search->sync_data($data['order_references'], $other_data);
                }
              
                if (!empty($data['db1'])) {
                    $i = 0;
                    foreach ($data['db1'] as $query) {
                        if (trim($query) != '') {
                            $query = trim($query);
                            $this->db->query($query);
                            $logs['db1_update_' . $i] = $this->db->last_query();
                        }
                        $i++;
                    }
                }
                $action_performed = 'OAPI32_CU';
                if (!empty($data['action_performed'])) {
                    $action_performed = $data['action_performed'];
                }
                /* BOC If DB2 queries exist in array then execute in Secondary DB */
                if (!empty($data['db2'])) {
                    $i = 0;
                    foreach ($data['db2'] as $query) {
                        if (trim($query) == '') {
                            continue;
                        }
                        $query = trim($query);
                        $this->secondarydb->db->query($query);
                        $logs['db2_update_' . $i] = $this->secondarydb->db->last_query();

                        // To update the data in RDS realtime
                        if ((strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview')) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                            $this->fourthdb->db->query($query);
                            $logs['db4_update_' . $i] = $this->fourthdb->db->last_query();
                        }
                        $i++;
                    }
                }
                $vt_data = $pt_data = array();
                /*  insertion in db2 for PT and VT with new version */
                if (!empty($data['db2_insertion'])) {
                    $order_references = array_filter(explode(',', $data['order_references']));
                    if (!empty($order_references)) {
                        $select_query = "SELECT * from prepaid_tickets pt1 where visitor_group_no in ('" . implode("','", $order_references) . "') and deleted = '0' and pt1.version =(select max(version) from prepaid_tickets pt2 WHERE pt1.prepaid_ticket_id= pt2.prepaid_ticket_id AND visitor_group_no in ('" . implode("','", $order_references) . "') AND pt2.deleted = '0')";
                        $pt_result = $this->secondarydb->rodb->query($select_query);
                        $prepaid_ticket_records = $pt_result->result_array();
                        if (!empty($prepaid_ticket_records)) {
                            foreach ($prepaid_ticket_records as $pt_records) {
                                $pt_data[$pt_records['prepaid_ticket_id']] = $pt_records;
                            }
                        }
                        $select_query = "SELECT * from visitor_tickets vt1 where vt_group_no in ('" . implode("','", $order_references) . "') and deleted = '0' and vt1.version =(select max(version) from visitor_tickets vt2 WHERE vt1.transaction_id= vt2.transaction_id AND vt_group_no in ('" . implode("','", $order_references) . "') AND vt2.deleted = '0')";
                        $vt_result = $this->secondarydb->rodb->query($select_query);
                        $visitor_ticket_records = $vt_result->result_array();
                        $visitor_tickets_insertion = $where_arr = [];
                        foreach ($data['db2_insertion'] as $db2_insertion) {
                            if ($db2_insertion['table'] == 'visitor_tickets') {
                                $visitor_tickets_insertion = $db2_insertion;
                            }
                        }
                        if (!empty($visitor_ticket_records)) {
                            foreach ($visitor_ticket_records as $vt_records) {
                                $vt_data[$vt_records['transaction_id']][] = $vt_records;
                                if(!empty($visitor_tickets_insertion) && in_array($vt_records['row_type'], ["12","18","19"])){
                                    $visitor_tickets_insertion['where'] = $vt_records['transaction_id'];
                                    $data['db2_insertion'][]  = $visitor_tickets_insertion;
                                }
                            }
                        }
                        $pt_batch = $vt_batch = array();
                        $updated_at = gmdate("Y-m-d H:i:s");
                        foreach ($data['db2_insertion'] as $db2_insertion_array) {
                            if ($db2_insertion_array['table'] == 'prepaid_tickets') {
                                $pt_array = $pt_data[$db2_insertion_array['where']];
                                foreach ($db2_insertion_array['columns'] as $pt_key => $pt_value) {
                                    $pt_array[$pt_key] = $pt_value;
                                }
                                $pt_array['version'] = (int) $pt_array['version'] + 1;
                                $pt_array['updated_at'] = $updated_at;
                                $pt_array['action_performed'] = $pt_array['action_performed'] . ', ' . $action_performed;
                                $pt_batch[] = $pt_array;
                            } else {
                                if(!in_array($db2_insertion_array['where'], $where_arr)){
                                    foreach ($vt_data[$db2_insertion_array['where']] as $vt_transactions) {
                                    foreach ($db2_insertion_array['columns'] as $vt_key => $vt_value) {
                                        $vt_transactions[$vt_key] = $vt_value;
                                    }
                                    $vt_transactions['version'] = (int) $vt_transactions['version'] + 1;
                                    $vt_transactions['updated_at'] = $updated_at;
                                    $vt_transactions['action_performed'] = $vt_transactions['action_performed'] . ', ' . $action_performed;
                                    $where_arr[] = $db2_insertion_array['where'];
                                    $vt_batch[] = $vt_transactions;
                                }
                                }
                                
                            }
                        }
                    }
                    if (!empty($pt_batch)) {
                        $this->pos_model->insert_batch('prepaid_tickets', $pt_batch, '1');
                        // To update the data in RDS realtime
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->pos_model->insert_batch('prepaid_tickets', $pt_batch, '4');
                        }
                    }
                    if (!empty($vt_batch)) {
                        $this->pos_model->insert_batch('visitor_tickets', $vt_batch, '1');
                        // To update the data in RDS realtime
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->pos_model->insert_batch('visitor_tickets', $vt_batch, '4');
                        }
                    }
                }
                /** Send Supplier Email in Case of Update Contact API. */
                if (!empty($data['order_references']) && SERVER_ENVIRONMENT != 'Local') { 
                    $array_keys['where'] = "visitor_group_no = '".$data['order_references']."'";
                    $db_data = $this->common_model->find('prepaid_tickets', array('select' => '*', 'where' => $array_keys['where']), "array", "2");
                    $ticket_booking_id_array = array_values(array_unique(array_column($db_data, 'ticket_booking_id')));
                    if (!empty($ticket_booking_id_array)) {
                        $arraylist = array(
                            "notification_event" => "ORDER_UPDATE_SUPPLIER",
                            "visitor_group_no" => $data['order_references'] ?? '',
                            "booking_reference" => $ticket_booking_id_array ?? ''
                        );      
                        $this->sendemail_model->sendSupplierNotification($arraylist);  
                    }
                }                    
                /** Send Data to notifiaction Queue TO Purge Data on Fastly */
                if (!empty($data['order_references'])) {
                    $this->load->model('notification_model');
                    $this->notification_model->purgeFastlyNotifiaction($data['order_references'], ($notify_event ?? ''));
                    $event_version_data['reference_id'] = $data['order_references'];
                    $event_version_data['event'] = [$notify_event];
                    $event_version_data['cashier_type'] = $data['cashier_type'] ?? 1;
                    $this->notification_model->insertMaxOrderVersionEvents($event_version_data);
                }
                /* BOC If DB1 query exist in array then execute in Primary DB */
            }
        }
    }

    /* #endregion update_contact_direct */

    function insert_extra_options_data($vgn = '')
    {
        $final_visitor_data_to_insert_big_query_transaction_specific = array();
        $vt_table = 'visitor_tickets';
        $primary_db = $this->primarydb->db;
        $secondary_db = $this->secondarydb->db;
        $current_date = date('Y-m-d');
        $primary_db->select('count(*) as quantity, pt.selected_date, pt.from_time, pt.to_time, pt.timeslot, pt.created_date_time as created, pt.scanned_at, pt.museum_id, pt.visitor_group_no, pt.refunded_by, pt.used, pt.is_prepaid, pt.is_cancelled, pt.hotel_id as distributor_id, pt.ticket_booking_id, pt.distributor_type, pt.batch_id, teo.ticket_id, teo.main_description as description, teo.amount, teo.net_amount, teo.tax, teo.ticket_extra_option_id as extra_option_id, teo.schedule_id as ticket_price_schedule_id, teo.main_description')
            ->from('prepaid_tickets pt')
            ->join('ticket_extra_options teo', "pt.ticket_id=teo.ticket_id AND ((teo.per_ticket_vs_per_age_group = '2' AND teo.schedule_id=pt.tps_id) OR teo.per_ticket_vs_per_age_group = '1')", 'left')
            ->join('prepaid_extra_options peo', "pt.visitor_group_no=peo.visitor_group_no", 'left')
            ->where('teo.ticket_id !=', NULL)
            ->where('peo.visitor_group_no', NULL)
            ->where('teo.option_type', 1)
            ->where('teo.deleted', 0)
            ->where('pt.deleted', '0')
            ->where('pt.is_refunded', '0')
            ->group_by('pt.visitor_group_no')
            ->group_by('pt.ticket_id')
            ->group_by('pt.is_refunded')
            ->group_by('teo.ticket_extra_option_id');
        if ($vgn != '') {
            $primary_db->where('pt.visitor_group_no', $vgn);
        } else {
            $primary_db->where('pt.created_date_time like "' . $current_date . '%"');
        }
        $result = $primary_db->get();
        if ($result->num_rows() > 0) {
            $results = $result->result_array();
            $visitor_group_nos = array_unique(array_column($results, 'visitor_group_no'));
            $ticket_ids = array_unique(array_column($results, 'ticket_id'));
            $taxes = array_unique(array_column($results, 'tax'));
            foreach ($taxes as $tax_values) {
                $where_store_taxes[] = explode('_', unserialize($tax_values)[0])[0];
            }
            $taxes_data = $primary_db->select('tax_type_name, is_exception, id')->from('store_taxes')->where_in('id', array_unique($where_store_taxes))->get()->result_array();
            foreach ($taxes_data as $tax_data) {
                $store_taxes[$tax_data['id']] = $tax_data;
            }
            $max_peo_ids = $secondary_db->select('max(prepaid_extra_options_id) as prepaid_extra_options_id, visitor_group_no')->from('prepaid_extra_options')->where_in('visitor_group_no', $visitor_group_nos)->group_by('visitor_group_no')->get()->result_array();
            foreach ($max_peo_ids as $max_peo_id) {
                $peo_ids[$max_peo_id['visitor_group_no']] = $max_peo_id['prepaid_extra_options_id'];
            }
            $vt_query = 'select * from visitor_tickets vt where vt.is_refunded = "0" AND vt.deleted = "0" AND vt.ticketId in (' . implode(',', $ticket_ids) . ') AND vt.vt_group_no IN (' . implode(',', $visitor_group_nos) . ') AND version = (select max(vt1.version) from visitor_tickets vt1 where vt1.transaction_id=vt.transaction_id AND vt_group_no IN (' . implode(',', $visitor_group_nos) . ') AND vt1.ticketId in (' . implode(',', $ticket_ids) . ')) group by vt_group_no,ticketId';
            $vt_result = $secondary_db->query($vt_query)->result_array();
            foreach ($results as $value) {
                if (!array_key_exists($value['visitor_group_no'], $peo_ids) && !array_key_exists($value['visitor_group_no'], $pteo_ids)) {
                    $pteo_ids[$value['visitor_group_no']] = '800';
                } elseif (!array_key_exists($value['visitor_group_no'], $pteo_ids)) {
                    $pteo_ids[$value['visitor_group_no']] = explode($value['visitor_group_no'], $peo_ids[$value['visitor_group_no']])[1];
                }
                $data[$value['visitor_group_no']] = $value;
                $value['price'] = unserialize($value['amount'])[0];
                $value['net_price'] = unserialize($value['net_amount'])[0];
                unset($value['amount']);
                unset($value['net_amount']);
                $tax = unserialize($value['tax'])[0];
                $value['tax_id'] = explode('_', $tax)[0];
                $value['tax'] = explode('_', $tax)[1];
                $value['order_currency_price'] = $value['price'];
                $value['order_currency_net_price'] = $value['net_price'];
                $data = $value;
                $data['is_purchased_with_postpaid'] = 3;
                $data['prepaid_extra_options_id'] = $value['visitor_group_no'] . ++$pteo_ids[$value['visitor_group_no']];
                $data['tax_name'] = $store_taxes[$value['tax_id']]['tax_type_name'];
                $data['tax_exception_applied'] = $store_taxes[$value['tax_id']]['is_exception'];
                $data_for_vt_loop[$value['visitor_group_no'] . '_' . $value['ticket_id']][] = $data;
                $insert_data[]   = $data;
                if (count($insert_data) % 200 == 0) {
                    $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '0');
                    $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '1');
                    $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '4');
                }
            }
            if (!empty($insert_data)) {
                $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '0');
                $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '1');
                $this->pos_model->insert_batch('prepaid_extra_options', $insert_data, '4');
            }
            if (!empty($vt_result)) {
                $transaction_id = array();
                foreach ($vt_result as $vt_value) {
                    $transaction_id_prefix = 800;
                    $hotel_data = $this->secondarydb->rodb->get_where("hotel_ticket_overview", array("visitor_group_no" => $vt_value['visitor_group_no']))->row_array();
                    $hotel_info = $this->common_model->companyName($vt_value['hotel_id']);
                    $max_transaction_id_query = 'SELECT max(transaction_id) as transaction_id from visitor_tickets vt WHERE vt.vt_group_no = ' . $vt_value['visitor_group_no'] . ' AND vt.transaction_type_name LIKE "Extra %"';
                    $transaction_id_array = $secondary_db->query($max_transaction_id_query)->row_array();
                    if (!empty($transaction_id_array) && !array_key_exists($vt_value['vt_group_no'], $transaction_id)) {
                        $transaction_id[$vt_value['vt_group_no']] = $transaction_id_array['transaction_id'];
                    }
                    if (array_key_exists($vt_value['vt_group_no'] . '_' . $vt_value['ticketId'], $data_for_vt_loop)) {
                        foreach ($data_for_vt_loop[$vt_value['vt_group_no'] . '_' . $vt_value['ticketId']] as $insert_values) {
                            for ($quantity_loop = 0; $quantity_loop < $insert_values['quantity']; $quantity_loop++) {
                                if ($transaction_id[$vt_value['vt_group_no']] > 0 && $transaction_id_prefix == 800) {
                                    $transaction_id_prefix = explode($insert_values['visitor_group_no'], $transaction_id[$vt_value['vt_group_no']])[1];
                                    $transaction_id[$vt_value['vt_group_no']] = $insert_values['visitor_group_no'] . ++$transaction_id_prefix;
                                } elseif ($transaction_id_prefix == 800) {
                                    $transaction_id[$vt_value['vt_group_no']] = $insert_values['prepaid_extra_options_id'];
                                } else {
                                    $transaction_id[$vt_value['vt_group_no']] = $insert_values['visitor_group_no'] . ++$transaction_id_prefix;
                                }
                                for ($i = 1; $i < 3; $i++) {
                                    $insert_vt_data = $vt_value;
                                    $insert_vt_data['row_type'] = $i;
                                    $paymentMethod = isset($hotel_data['paymentMethod']) ? $hotel_data['paymentMethod'] : '';
                                    $pspReference = isset($hotel_data['pspReference']) ? $hotel_data['pspReference'] : '';

                                    if ($paymentMethod == '' && $pspReference == '') {
                                        $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                                        $invoice_status = '6';
                                    } else {
                                        $payment_method = 'Others'; //   others
                                        $invoice_status = '0';
                                    }
                                    if ($i == 1) {
                                        $insert_vt_data['transaction_type_name'] = 'Extra option sales';
                                        $insert_vt_data['ticket_title'] = $vt_value['museum_name'] . '~_~' . $insert_values['description'];
                                        $insert_vt_data['partner_id'] = $vt_value['hotel_id'];
                                        $insert_vt_data['debitor'] = "Guest";
                                        $insert_vt_data['creditor'] = "Debit";
                                        $insert_vt_data['ticket_extra_option_id'] = $insert_values['extra_option_id'];
                                        $insert_vt_data['ticketPrice']   = '0';
                                        $insert_vt_data['paid']   = '1';
                                        $insert_vt_data['payment_method']   = $payment_method;
                                        $insert_vt_data['captured'] = "1";
                                        $insert_vt_data['invoice_status'] = $invoice_status;
                                    } else {
                                        $insert_vt_data['transaction_type_name'] = 'Extra service cost';
                                        $insert_vt_data['ticket_title'] = '(Extra option)';
                                        $insert_vt_data['visitor_group_no'] = '0';
                                        $insert_vt_data['partner_id'] = $vt_value['museum_id'];
                                        $insert_vt_data['partner_name'] = $vt_value['museum_name'];
                                        $insert_vt_data['debitor'] = $vt_value['museum_name'];
                                        $insert_vt_data['creditor'] = "Credit";
                                        $insert_vt_data['ticket_extra_option_id'] = 0;
                                        $insert_vt_data['ticketPrice']   = $insert_values['price'];
                                        $insert_vt_data['paid']   = '0';
                                        $insert_vt_data['invoice_status'] = "0";
                                        unset($insert_vt_data['payment_method']);
                                        unset($insert_vt_data['captured']);
                                    }
                                    $insert_vt_data['ticketwithdifferentpricing'] = '0';
                                    $insert_vt_data['ticketpriceschedule_id'] = $insert_values['ticket_price_schedule_id'];
                                    $insert_vt_data['hto_id']           = '0';
                                    $insert_vt_data['group_quantity']   = '0';
                                    $insert_vt_data['ticketAmt']   = $insert_values['price'];
                                    $insert_vt_data['partner_gross_price']   = $insert_values['price'];
                                    $insert_vt_data['order_currency_partner_gross_price'] = $insert_values['price'];
                                    $insert_vt_data['partner_net_price']   = $insert_values['net_price'];
                                    $insert_vt_data['order_currency_partner_net_price'] = $insert_values['net_price'];
                                    $insert_vt_data['ticketType']   = '1';
                                    $insert_vt_data['transaction_id']   = $transaction_id[$vt_value['vt_group_no']];
                                    $insert_vt_data['id']   = $insert_vt_data['transaction_id'] . '0' . $i;
                                    $insert_vt_data['is_prioticket'] = 0;
                                    $insert_vt_data['user_image'] = 3;
                                    $insert_vt_data['created_date'] = $insert_values['created'];
                                    $insert_vt_data['slot_type'] = $insert_values['timeslot'];
                                    $insert_vt_data['invoice_id'] = '';
                                    $ticketTypeDetail = $this->order_process_vt_model->getTicketTypeFromTicketpriceschedule_id($insert_vt_data['ticketpriceschedule_id']);
                                    if (!empty($ticketTypeDetail)) {
                                        if ($ticketTypeDetail->parent_ticket_type == 'Group') {
                                            $tickettype_name = $ticketTypeDetail->ticket_type_label;
                                        } else {
                                            $tickettype_name = $ticketTypeDetail->tickettype_name;
                                        }
                                    }
                                    $insert_vt_data['tickettype_name'] = $tickettype_name;
                                    $insert_vt_data['visit_date'] = strtotime($insert_values['created']);
                                    $insert_vt_data['visit_date_time'] = $insert_values['created'];
                                    $insert_vt_data['captured'] = "1";
                                    $insert_vt_data['tax_id'] = $insert_values['tax_id'];
                                    $insert_vt_data['tax_value'] = $insert_values['tax'];
                                    $insert_vt_data['isBillToHotel'] = $hotel_data['isBillToHotel'];
                                    $timezone = $hotel_data['timezone'];
                                    $insert_vt_data['timezone'] = $timezone;
                                    $insert_vt_data['is_prepaid'] = "1";
                                    $insert_vt_data['used'] = "0";
                                    $insert_vt_data['paymentMethodType'] = $hotel_info->paymentMethodType;
                                    $today_date =  strtotime($insert_vt_data['order_confirm_date']);
                                    $insert_vt_data['col7'] = gmdate('Y-m', $today_date);
                                    $insert_vt_data['col8'] = gmdate('Y-m-d', $today_date);
                                    if ($insert_vt_data['activation_method'] == '10') {
                                        $insert_vt_data['is_voucher'] = '1';
                                    }
                                    $insert_vt_data['updated_at'] = $insert_values['created'];
                                    $insert_vt_data['supplier_gross_price'] = $insert_vt_data['ticketAmt'];
                                    $insert_vt_data['supplier_ticket_amt'] = $insert_vt_data['ticketAmt'];
                                    $insert_vt_data['supplier_tax_value'] = $insert_vt_data['tax_value'];
                                    $insert_vt_data['supplier_net_price'] = $insert_vt_data['partner_net_price'];
                                    $insert_vt_data['merchant_admin_id'] = $insert_vt_data['market_merchant_id'];
                                    $insert_vt_data['action_performed'] .= ', ADMND_WRKR';
                                    $insert_vt_data['is_custom_setting'] = '0';
                                    unset($insert_vt_data['roomNo']);
                                    unset($insert_vt_data['nights']);
                                    unset($insert_vt_data['user_age']);
                                    unset($insert_vt_data['gender']);
                                    unset($insert_vt_data['visitor_country']);
                                    unset($insert_vt_data['passNo']);
                                    unset($insert_vt_data['pass_type']);
                                    unset($insert_vt_data['total_gross_commission']);
                                    unset($insert_vt_data['total_net_commission']);
                                    unset($insert_vt_data['partner_gross_price_without_combi_discount']);
                                    unset($insert_vt_data['partner_net_price_without_combi_discount']);
                                    unset($insert_vt_data['adyen_status']);
                                    unset($insert_vt_data['adjustment_method']);
                                    unset($insert_vt_data['visitor_invoice_id']);
                                    unset($insert_vt_data['ticket_status']);
                                    unset($insert_vt_data['distributor_type']);
                                    unset($insert_vt_data['extra_discount']);
                                    unset($insert_vt_data['merchant_admin_name']);
                                    unset($insert_vt_data['financial_id']);
                                    unset($insert_vt_data['age_group']);
                                    unset($insert_vt_data['without_elo_reference_no']);
                                    unset($insert_vt_data['tax_name']);
                                    unset($insert_vt_data['updated_by_username']);
                                    unset($insert_vt_data['voucher_updated_by_name']);
                                    unset($insert_vt_data['redeem_method']);
                                    unset($insert_vt_data['distributor_status']);
                                    unset($insert_vt_data['invoice_type']);
                                    unset($insert_vt_data['group_type_ticket']);
                                    unset($insert_vt_data['group_linked_with']);
                                    $insert_vt_batch[] = $insert_vt_data;
                                    $final_visitor_data_to_insert_big_query_transaction_specific[$insert_vt_data["transaction_id"]][] = $insert_vt_data;
                                    if (count($insert_vt_batch) % 200 == 0) {
                                        if (INSERT_BATCH_ON) {
                                            if (!empty($insert_vt_batch)) {
                                                $this->insert_batch($vt_table, $insert_vt_batch, 1);
                                            }
                                        } else {
                                            if (!empty($insert_vt_batch)) {
                                                $this->insert_without_batch($vt_table, $insert_vt_batch, 1);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if (!empty($insert_vt_batch)) {
                if (INSERT_BATCH_ON) {
                    if (!empty($insert_vt_batch)) {
                        $this->pos_model->insert_batch($vt_table, $insert_vt_batch, 1);
                        $this->pos_model->insert_batch('visitor_tickets', $insert_vt_batch, '4');
                    }
                } else {
                    if (!empty($insert_vt_batch)) {
                        $this->pos_model->insert_without_batch($vt_table, $insert_vt_batch, 1);
                        $this->pos_model->insert_without_batch('visitor_tickets', $insert_vt_batch, '4');
                    }
                }
            }            
        }
        return $final_visitor_data_to_insert_big_query_transaction_specific;
    }

    public function barcodeImage()
    {
        ini_set('display_errors', 1);
        $ticket_code = 'eJxNUU1LBDEM\/SuyA54UkvlqCoLIiAi74EHFw1ZwptMeRfYDVjz4152+VLpzeK9NmpeXzKp6fSEWay0tX0fX7rhwnZA7nCnhZBKOiLDZObfBnX4fEnuf0DYJpX5HwZRwDgh1CFH3jUx\/1qJ1n7sbvJzdARFIjaibcPYj4qiy8vYEjpsZVQx9AYaf4vcfiak\/oQJaBFeC2mi+jgemdvFVbE06bavYFilBJ8bZmxL38QOJxish0wyZ1\/t1VeVj5kzDkMOg\/hbVUdekO8N86BGl9NY33jxiKLqEnDQXiYLRd5XmcAklkdckRTLKSYe+UxfI+yt0qJ+hojaA1t4XT15\/S95zdh62Ojer3HaPJrBAZ7scg+7Mojmv\/gDfR7bE';
        $barcodeOpts = [
            "bcid" => "azteccode",
            "text" => json_decode(gzuncompress(base64_decode($ticket_code)), true),
            "includetext" => false,
            "layers" => 17,
            "scale_x" => 3,
            "parse" => true
        ];
        $remoteImage  = 'http://99.81.101.255:8083/aztec?' . http_build_query($barcodeOpts);
        $imginfo = getimagesize($remoteImage);
        //echo '<pre>';print_r($imginfo);exit;
        header("Content-Description: File Transfer");
        header("Content-Type: {$imginfo['mime']}; charset=UTF-8");
        header("Content-Disposition: inline; filename='test.pdf'");
        header("Content-Transfer-Encoding: binary");
//        header("Content-Length: " . filesize($remoteImage));
        readfile($remoteImage);
        exit(0);
    }
    /* #endregion EOC of class Pos */

    /*
     * Function to insert previous order  in order flag 
     * created by : supriya<supriya10.aipl@gmail.com>
     */

    function insert_in_order_flags($reseller_id, $start_date, $end_date)
    {
        $startdate = date("Y-m-d H:i:s", strtotime($start_date));
        $enddate = date("Y-m-d H:i:s", strtotime($end_date));
        $query = 'Select * from prepaid_tickets where reseller_id= "' . $reseller_id . '" and created_date_time BETWEEN "' . $startdate . '" and "' . $enddate . '" order by created_date_time ASC';
        $query_result = $this->primarydb->db->query($query)->result_array();
        $this->CreateLog('order_flag_script.php', 'step 1', array('query' => $this->primarydb->db->last_query()));
        if (!empty($query_result)) {
            $vgns_arr = array();
            foreach ($query_result as $order) {
                $order_array[$order['visitor_group_no']]['prepaid_tickets_data'][] = $order;
            }
            $vgns = array_unique(array_column($query_result, 'visitor_group_no'));

            echo "VGN to be updated " . implode(", ", $vgns) . " and total count  is " . count($vgns);
            $this->CreateLog('order_flag_script.php', 'step 2', array('VGNS to Update' => json_encode($vgns), "count" => count($vgns)));

            $order_query = "Select id, order_id, flag_id, flag_uid, flag_type, flag_entity_type,flag_entity_id, flag_name, flag_value, created_at, updated_at from order_flags where order_id In (" . implode(",", $vgns) . ") ";
            $order_query_result = $this->secondarydb->db->query($order_query)->result_array();
            foreach ($order_query_result as $order_flags_set) {
                $new_order_flag_arr[$order_flags_set['order_id']][$order_flags_set['flag_entity_type'] . '_' . $order_flags_set['flag_entity_id']] = $order_flags_set;
            }
            $this->CreateLog('order_flag_script.php', 'step 3', array('Order Flag Array' => json_encode($new_order_flag_arr)));

            foreach ($order_array as $vgn => $order_val) {
                $flag_entities_vgn[$vgn] = $this->order_process_model->set_entity($order_val); //prepare array for all entitities
                // merging all entities corresponding to each vgn in one array
                foreach ($flag_entities_vgn as $flag_entities) {
                    foreach ($flag_entities as $entity_name => $value) {
                        if (!empty($value)) {
                            foreach ($value as $val) {
                                $new_entity_arr[$entity_name][] = $val; //merged array of all vgns w.r.t all entities
                            }
                        }
                    }
                }
            }
            //prepare unique array w.t.t each entity
            foreach ($new_entity_arr as $entity_name => $details) {
                $final_flag_entities[$entity_name] = array_unique($details);
            }
            $this->load->model('importbooking_model');
            if (!empty($final_flag_entities)) {
                $flag_details = $this->importbooking_model->get_flags_details($final_flag_entities); // fetching flag entities details
            }
            if (!empty($flag_details)) {
                foreach ($flag_entities_vgn as $vgn => $entity_detail) {
                    foreach ($flag_details as $value) {
                        if (in_array($value->entity_id, $entity_detail[$value->entity_name])) { //check entity exist in vgn array
                            $new_flag_details_arr[$vgn][$value->entity_type . '_' . $value->entity_id] = $value;
                        }
                    }
                }
                foreach ($vgns as $vg_id) {
                    if (!empty($new_order_flag_arr[$vg_id])) {
                        $final_array = array_diff_key($new_flag_details_arr[$vg_id], $new_order_flag_arr[$vg_id]);
                    } else {
                        $final_array = $new_flag_details_arr[$vg_id];
                    }

                    if (!empty($final_array)) {
                        array_push($vgns_arr, $vg_id);
                        $new_final_array[$vg_id] = array_values($final_array);
                    }
                }
                $this->CreateLog('order_flag_script.php', 'step 4', array('data' => json_encode($new_final_array)));
                if (!empty($new_final_array)) { // set flags for each vgn
                    $this->importbooking_model->set_order_flags($new_final_array);
                }
            }

            if (!empty($vgns_arr)) {
                $this->CreateLog('order_flag_script.php', 'step 5', array('VGNS Updated' => json_encode($vgns_arr), "count" => count($vgns_arr)));
                echo " Order flag updated for  VGN " . implode(", ", $vgns_arr) . "and total count  is " . count($vgns_arr);
            } else {
                echo  " No records to insert..!!!";
            }
        } else {
            echo "No records found..!!!!";
        }
    }
    /* #endregion */
    function get_orderdata_from_queue() {
        $postBody = file_get_contents('php://input');
        $this->CreateLog('get_orderdata_from_queue.php', 'step 1', array("params" => $postBody));
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $queue_url = NEW_QUEUE_URL;
        $messages = $sqs_object->receiveMessage($queue_url);
        $messages = $messages->getPath('Messages');
        foreach ($messages as $message) {
            $string = $message['Body'];
            $string = gzuncompress(base64_decode($string));
            $this->CreateLog('get_orderdata_from_queue.php', $string, array());
            $string = utf8_decode($string);
            $data = json_decode($string, true);

            $vgn = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
            if (!$vgn || $vgn == "") {
                $vgn = $data['prepaid_tickets_data'][0]['visitor_group_no'];
            }
            if ($vgn != '' && $vgn != '0') {
                $db = $this->secondarydb->db;
                $tables_array = ['hotel_ticket_overview', 'prepaid_tickets', 'visitor_tickets'];
                foreach ($tables_array as $table) {
                    $db->where('visitor_group_no', $vgn);
                    $db->delete($table);
                }
            }
            $this->order_process_model->insertdata($data, "0", "1");
        }
    }
    
    
        /**
     * insert_in_rds_db_realtime_script
     *
     * @return void
     * @author Vishal katna <vishal24.aipl@gmail.com> on 14 sept, 2022
     */
    function insert_in_rds_db_realtime_script()
    {
        $orders = ["eJzt3d1y28YVB/D7PAWHt7Vl7OKDgDqdjuzaaTvjxBMr7UwjDwYEIQkNCCAAKEXx5KbP06fqk/QA2AUXIEjKNkTS5H9ij6XF4muxu2d/PAr08ZvRaHybFEHkFqH/c1C4yV2Q3YXBvTvzCm98/hNV+FhWCmfjc2ZZNrMmjmM5hs25prFn5SY/C7wimH0fqzWqLYt0trLFrrbchXlYJJl7kyWL1I0TN4noBOPOGcbPltdXXsDY0HRWF/q3XhzLYtOqC28KNwtyugGvCJO42qYph4i9eUC1X4XFw+j1r2mQFaO3XuTdePXenl+EYs/iIS1ravWGMH8ZRtFl8tfyKGqxe5cs/NsgW5alXlbEQSZPJa41made/NAuVM42D4rbpLp93hwliIt3Xp5/l5S3Z9oO02zd0bnJdHMiK23cnCXJvNr86tVz7hjj9qN6+fDtIsiLlSd2E8Sz6n6oYQLRqGXF6trz5uLrsmDuhdGyMImjMA5k24nndB/SvS2K8mKvs2TuzeZhvNy6oIflhnPvptzjtijS/PzqxdWLqtZZmoVJ3SvPqAWvXvyS+cksyK9eVDvQv9RtFmmUeDN2lsY3f6ae+yduntmifefJIqYbHOv8zHHqsji8uS1y2SlE73z5UF4Pmzii8RdVl1IKbhNx/1T8Ogpib/SvekNAfcinI7y6Dfyf6SYvw7JO2aJcm5iOUz+HwA/CtHhdtlXTVOltQi0VL+bTsrHjRRQtmyOmXqaUUS+Lg/s8CoqCNuaLae5n4TRounZ3JK0bRfToZ2Hhe9lMqVofoqDr/o0uiHb9A1PHnC9uzM0Lr1jkajd/mFMPfS+LO4NSdgH1KVfPWJO3VPYH95oum57sNBSTj7I9yagbumWPcefJNIwC10tTdf+mb7TGY72bn8TXYTYv26ieoaZJ8nMY37hhTKecV4OOdvPOtfOPv8shGhc0Ijs1xq29Z0Eh+ru6Z5inWZB6dacRXW9GLU1H8KKVMxrnH/Nzpp1fjeeef1sOl3B2Nf5jfq5zKjOsC+cv2gUz31iWYZqWbRtvjDdvbOuCa680g1U1q72/f+9Sd8/psFWZQUXMOLPqCoy+mwV3oR9U3bYqtKgsfFd2u2Uduk63SKpnUReWR6GvxJ3NwrygrrYoe5d4ou8XaRqFQTbKaXrI62qRR22TB1E1FKhOEN9EYX5bb/xl4cUFzbfjcy66WhVlwlnZjIal26LJiqSg1qKn6tNpLPNMhIm6OKY9xCbToU1M+c+gar8/2xilOKLUYUUpx3A4Q5RClEKUQpQ6nShFfz/UD2OeJlnhiieWu16WeQ+1tT4oY56ixCwI5m7hTSN1rOW3SUpnSmYLv8gVp9X7iufsBr8WmecmaflQ19eq7zTvak92Jtlg5fZlUGjv7G6g4frOLicEeQFhT2Clo2isFRKq63CLagYbc43z54zRnxF1FNM5lwH2sZMNPfKomi7znKaJ0IvGon3D6/quxtyyxDHThIZzEsZyg2Y53Q1i8r0MKTp5FLOzMPbkFFw+i/7x/fFKxi6aKq5Dvyq/GlMnvxo/uxK7lrPtsozWBG4ZwkM/pIhdTwe09acPtE3EelfMfVflbLQszYLrgIK2Lw/2+9apwPoapgJxDpr/aViVXWRZVnUSTX/OeVVmUxE9nm4lbltlT+Lacl6RayR6IMFNkj0o67SVTa0lU35L66IZbUw9n+aUur/oBuOm7HW0RomSMiTMaNivTGLy6Mvz9W1tnZKGGTVNuSLsBBs/WuRlQK5HQnlESzMm3BG38asSFMXlyvEp9qz6K+3GLd3ULLFUiKqhKGYgseRVJsn6sNqZpo3bs2mnXipGPdcNzvTmTvqC5pbPOmivdoSW+9HEGaR1GI/X7z6n0L+Y0xOjkS8avlxZGf2b22ssbz7NPDnOc79cWcxcb3XJSsdQYq+Y2R81m4mRXE6h9SjNxerNNHQRgJr7p57iiwWl7AGt8rIjUFs0q07ZvHXYqtag9RJ0Gcs4xTJHU/5rhUgRcS9mi6joxtK6I1WrI3GPGjtvOkXS3LhOdyvarxNulm3ySbF+7Uqx+xnnpg81O51BLrP7e0GrozfzfE9I06f6fS77AN1uJE9h2KxTrDrwfUWCIKDDtjA497LyjPMgKxe1Yozp2+mWi1aTD1nlBy2B6/mirK8/l8ViFHSinyh9zLXSvCDrvbz8Z/NAm/7azBd1yFM2bQD2o8j8aBuukUE1KXVXz37VsnSuMl4uoVNffEH/uNdhENFcEOf3LT41ayvBEiZmQxmym6fF1Kmi1ImC2HpRtcjKYP7gluIsO+OPP7SnjBsaRvfl0lJqflHcirrqccTt0gItmHp5UC/ZZG9sT/Zrlkzqhwxau4clWXgTxstls9rVmkq9/aDZuhJRmi3KPDWuJqpW89RrztXJpDPBXtPlxX7oic7FuqXt6E7TDPH+57qu0ykUVd9WGBzR0lA0j/jsIJzfuMwyJo7FNMeUXxoad0mZNOiLpPqMQHRtGkeR+ChAnDxK/Db6kixZ27j0TJppP6x6qh/EjV47PWglAnS2Kw0tNV7NdWXE27rzmgEt1/Q90bJ5wGu7+GqN/GE+TcoPL/73n//2jpKmwlXRVBGfrlTXsL6HlPGVlkMUyPKgKGjwKsO5vf53aU6oR2y/BFRGtOoup1Q1yHUqLec9tdIyVLUqK0FLLv/l3U4f2iuc1e2bVjliRmqCyj/EyG9Noa1PPGXhY8KE7OPyifc0ogx3F37VoV6JGe0b9ZPgLxcsH0SwHIKFYCHY/QvW0TV2YIJV8iCfI1hldwgWgl1JeEKwECwEC8FCsBAsBHs0gu0uKR5jWX0Qy+oab/f7J7Hs27+9fXsBx644dnJyjq1PnVbRvTkLfyrgNtZrcKs9EW3lKNpKW5mKkrBtqPs0yVnbsJ2ObKknWeoactep2R5mMmeiPdcY/Rlp2nn5R06EO2Om1iVma0XxiaLsalKuEA9BkieZC10OjkdAUjccvQ+Sb+m7ZKTEMuBxODz2fNzVa4KvSpU9OBwClcvNB21K02TMZppmUefJC/Etc8yzf6dfosnl3X8OJnu3PQaSvTseNSKPUU3GIGoyoCaoCWo6ODUNlRAcTE3DpQOhJuTfoCaoCWqCmqAmqGmnajIHUZO5GzVV8eaVR9cXjy5vH8p5CIgCooConaee5PK4hahlEgmppxNDFFJPWxFlmhsQ1RfUoCloCprarCmD/iqaMvSJAU1BU/vSlDWIpixoCpqCpg5dU8OlpAbSFFJSR6IpDk1BU9AUNAVNQVOnq6nJIJqa7FBTNCN6o9grvGj0jiST5wlEBVFBVE+Vn+Jsnajky08UUU32+dZKiAr5qQMXVef9Mx1RUWD7bjWwQVVQ1VOoSpnYv35VWYyrqproOlQFVe1LVc0C4otUZUNVUBVU9TWo6pPzVE+tKuSpjkRVHKqCqqAqqAqqgqpOW1XOIKpydqgqEXFaDQtRQVQQ1S7zVHaPqJYv1EWe6sREhTzV1nfB21rvT/69WVDo8emEo/XhDa6Cq+Cqza4yda66isIRg6vgqj25inV+e/vnuYppcBVcBVcduqsGzFQN4ypkqo7EVRyugqvgKrgKroKr4CqNsUFcxXboqotommReHIJUIBVItfNUlSl1opLKNj+ZVEhVHQmpkKp6xA//sT5S9cYzGAqGgqG2GMrWTcVQFmc2DAVD7ctQfBBDcRgKhoKhDthQg6WlhjIU0lJHYigOQ8FQMBQMBUPBUCdpKH0QQ+k7NBRNZMk8uQujq4WmBbOcriwZPYxmwSjyRvNkBlvBVrDVHvJTxqqtHHV5hfzUSdkK+anttrLsDbaSca4d4d5ShCu/xM//wV1w1+PdZZlcfbc6LQkduAvu2pe7jEHcZcBdcBfc9RW6a8Cc1jDuQk7rSNzF4S64C+6Cu+AuuAvu6ojJHMRd5g7d9UNwRwE1KWPPJY0FKAvKgrJ2nt1isnSprIn8H/aR3To9ZSG7tV1ZvF3aUlZvVIOn4Cl4arOnJrplKJ6aWHjvOjy1P09Zg3jKgqfgKXjq4D01XNZqIE8ha3UknuLwFDwFT8FT8BQ8dcKemgziqclOPPV3GmzJ6P1tcj96E1Fzxz40BU1BU3vITuk9msKvsTpZTSE79QhN9b5ufW1Mg6VgKVhqs6Vs5liKpWzdgKVgqb1Zqlk7fJGlbFgKloKlDtxSA2amhrEUMlNHYikOS8FSsBQsBUvBUidrKWcQSzk7sdQr6sxZMnqXzNNwlizgKDgKjtp9TspadRTD76s6WUchJ7XdUWzS56jeeAZDwVAw1EZD0WrTVH62z9F0YwJDwVB7MhTXhjBU+YkkDAVDwVAHa6gBc1HDGAq5qCMxFIehYCgYCoaCoWCokzQUG8RQbJfvm1jk+FE+8Al82kMKylnlE1dXUEhBnRSfkIJ6BJ9YH5+6oQxygpwgpy1yMpmmyskyrLM0hpwgp73IiQ8iJw45QU6Q02HKacDE0zByQuLpSOTEISfICXKCnCAnyOnU5KQPIid9Nz+3twjuvHw0C6LRZZDj/eYAFAD1hKknpvUDympKG0BZlr4MQUg9nRigkHraCiin//3mayIaHAVHPYWjWM+Wr9NRts2Zs/zdu/St5eBn9+CovTnKGMRRBhwFR8FRB+2oT05EPbWjkIg6EkdxOAqOgqPgKDgKjjpRR5mDOMrciaPeeeWPPwTxaOpleKU5EAVE7T4Z5egrr5GwNTlQkYw6PUQhGbUNUYZmWH2I+vHy9ejlMpIBT8AT8LQBT7o2cWzblHjSNVvTNAt4Ap72hSdrEDxZwBPwBDwdLp4Gy0ANhSdkoI4ETxx4Ap6AJ+AJeAKeTgxPk0HwNNkJni4i3/vNm3qtNoWeoCfoaWepJ6pnmR0+cZ1Wa/g9UCfrJySftvqJMbv3R/hkSIOf4Cf4aYufLGPiGJZlOvLLiWa7Lu3ue9mNRzcJR32mo64WXPN8UOqLKNUsHr6IUjYoBUqBUgdMqaESUQNSCqmoI6EUB6VAKVAKlAKlQKnTpZQzCKWc3bxXwsuLMIqS0bfhNPOiaw9vlgCnwKm9ZKYmfZxyPplTyEwdCaeQmXoEp5zet5yvDWuQFWQFWW2Rla3rzZe2psiKQVaQ1f5kpWtDyErXICvICrI6cFkNmagaSFZIVB2JrDhkBVlBVpAVZAVZQVZkIjaIrNgOX0PhjdIwgKggKohq57mqycrv4+U6EUddTyFXdVKiQq5qu6h0rV9U5bV+uwhnASAFSAFSWyFlmwbjhi2/tDXmulWVHIgCovaIKD4IojgQBUQBUQeKqOHSUoMhCmmpI0EUB6KAKCAKiAKigKivAFH094Nys3K8z4Kinug+9sPkp7K4i5POseRSwDLPxGJCTBJJQaNdKV6tuFIlzVMxuTCzKWhMsJzmZChb3VTOWG5rymrPp3ISrQ8vziXWFQVNVVWz1MWGUjxzq4G0+ujtdsT/lJVAL+PU1Usnpq1ZC2zpm/bqQNpQa91QajZk9ezYyHyc0xArZIeqe0zdO3zfzZL7cjr11OL7LKQlK00y8xKmUXLTPJoSTyIIyklCdfWmkUdzd0GR111UTVNd3P8BGSa0WQ=="];
        foreach($orders as $string){
            $string = gzuncompress(base64_decode($string));
            $string = utf8_decode($string);
            $data = json_decode($string, true);
            // Note: delte code should be after the insertdata function, temporary moved to up because of hto empty data condition issue (all_ticket_ids case) for old data
            // It remove message from SQS queue for next entry.
            $this->CreateLog('check_data_in_rds_manual_script.php', $data['hotel_ticket_overview_data'][0]['visitor_group_no'].'check_data_in_rds_manual_script_'.date("H:i:s.u"), array("data" => json_encode($data)), $data['hotel_ticket_overview_data'][0]['channel_type']);                              
            // Main Method of Model to insert data in Secondaty DB [hotel_ticket_overview, prepaid_ticket, visitor_ticket, prepaid_extra_options Tables].
            if (isset($data['visitor_ticket_data'])) {
                $counter = 0;
                foreach ($data['hotel_ticket_overview_data'] as $hto_insert_data) {
                    $counter++;
                    $final_hto_data[] = $hto_insert_data;
                    if ($counter % 200 == 0) {
                        $this->pos_model->insert_batch('hotel_ticket_overview', $final_hto_data, '4');
                        $final_hto_data = array();
                    }
                }

                if (!empty($final_hto_data)) {
                    $this->pos_model->insert_batch('hotel_ticket_overview', $final_hto_data, '4');
                }

                $counter = 0;
                foreach ($data['prepaid_ticket_data'] as $pt_insert_data) {
                    $counter++;
                    $final_pt_data[] = $pt_insert_data;
                    if ($counter % 200 == 0) {
                        $this->pos_model->insert_batch('prepaid_tickets', $final_pt_data, '4');
                        $final_pt_data = array();
                    }
                }

                if (!empty($final_pt_data)) {
                    $this->pos_model->insert_batch('prepaid_tickets', $final_pt_data, '4');
                }

                $counter = 0;
                foreach ($data['visitor_ticket_data'] as $vt_insert_data) {
                    $counter++;
                    $final_vt_data[] = $vt_insert_data;
                    if ($counter % 200 == 0) {
                        $this->pos_model->insert_batch('visitor_tickets', $final_vt_data, '4');
                        $final_vt_data = array();
                    }
                }

                if (!empty($final_vt_data)) {
                    $this->pos_model->insert_batch('visitor_tickets', $final_vt_data, '4');
                }
            } else {
                $this->order_process_model->insertdata($data, "0", "4");
                echo $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
                echo "Order Processed";
            }
        }
    }
}
