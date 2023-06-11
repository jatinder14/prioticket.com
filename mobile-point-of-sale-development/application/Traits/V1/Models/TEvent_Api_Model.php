<?php 
namespace Prio\Traits\V1\Models;

use function GuzzleHttp\json_encode;

use \Prio\helpers\V1\local_queue_helper;

trait TEvent_Api_Model {
    function __construct() {
        /* Call the Model constructor */
        parent::__construct();
        $this->api_url = $this->config->config['api_url'];
        $this->get_api_const = json_decode(TEST_API_DISTRIBUTOR, TRUE);
        $microtime = microtime();
        $search = array('0.', ' ');
        $replace = array('', '');
        $error_reference_no = str_replace($search, $replace, $microtime);
        define('ERROR_REFERENCE_NUMBER', $error_reference_no);
    }

    /* #region get mec data. */

    /**
     * @Name        : get_mec_data
     * @Purpose     : to get the mec data
     * @param type  : int
     * @return      : array
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function get_mec_data($product_id = 0) {
        $response = [];
        if (!empty($product_id)) {
            $this->primarydb->db->select('mec_id, startDate, endDate, is_cut_off_time, cut_off_time, local_timezone_name, is_reservation, shared_capacity_id, third_party_id, second_party_id');
            $this->primarydb->db->from('modeventcontent');
            $this->primarydb->db->where(array('mec_id' => $product_id, 'deleted' => '0'));
            $response = $this->primarydb->db->get()->result_array()[0];
        }
        return $response;
    }

    /* #endregion get mec data. */
    /* #region send cancelbooking email to third party. */

    /**
     * @Name        : send_cancel_booking_email_TP()
     * @Purpose     : To send_cancel_booking_email_TP
     * @Params      : $email_orders - start time e.g. 02:30
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    public function send_cancel_booking_email_TP($email_orders = []) {
        /* #region  send_cancel_booking_email_TP */
        global $graylogs;
        global $api_global_constant_values;
        global $filelogs;

        if (!empty($api_global_constant_values['PRIOHUB_SERVICE_NAME']['value']) && !empty($api_global_constant_values['PRIOHUB_NO_REPLY_EMAIL']['value']) && !empty($email_orders) && !empty($api_global_constant_values['QUEUE_URL_EVENT']['value']) && !empty($api_global_constant_values['EVENT_TOPIC_ARN']['value'])) {

            if (isset($email_orders['travel_cancel_timeout']) && $email_orders['travel_cancel_timeout'] == 1) {
                /*  Call from Travelbox Libary for cancel order email which order has been inititated at travelbox end and there was curl timeout at Prioend.send email from run time. Notify the Travelbox about the cancel order Via email. [Travelbox] Need to handle OTAX_BookConfirmRQ  timeout in both v2.6 & another OTA (GYG & v2.4) */
                $query = $this->primarydb->db->select('reference_id, visitor_group_no as externalbookingid, ticket_booking_id, passNo, group_concat(ticket_type) as count_of_passengers, selected_date, third_party_response_data, booking_selected_date')->from('prepaid_tickets')
                        ->where_in('visitor_group_no', $email_orders['visitor_group_no']);
                $pt_results = $query->get();
                if ($pt_results->num_rows() > 0) {
                    $email_orders = $pt_results->result_array();
                    $email_orders[0]['itineraryname'] = json_decode($email_orders[0]['third_party_response_data'], true)['third_party_params']['third_party_ticket_id'];
                }
            }
            $msg = '';
            $msg .= 'Hello TravelBox Team,<br><br>';
            $msg .= 'Order cancelled in priohub needs to be cancelled in TravelBox system.<br>';
            $msg .= 'Please find  cancellation detail below:<br>';
            $msg .= '<table style="width:100%" border="1">';
            $msg .= '<tr>
                             <th>TravelBox ItineraryName</th>
                             <th>ExternalBookingID</th>
                             <th>Travel Selected Date</th>
                             <th>Count of Passengers</th>
                     </tr>';
            foreach ($email_orders as $data) {
                $passengers = '';
                $ticket_types = array_count_values(explode(',', $data['count_of_passengers']));
                foreach ($ticket_types as $ticket_type => $count) {
                    $passengers .= '    ' . strtoupper($ticket_type) . ' : ' . $count . '<br>';
                }
                $msg .= '<tr>';
                $msg .= "<td style='text-align:center'>" . $data['itineraryname'] . "</td>";
                $msg .= "<td style='text-align:center'>" . $data['externalbookingid'] . "</td>";
                $msg .= "<td style='text-align:center'>" . $data['selected_date'] . "</td>";
                $msg .= "<td style='text-align:center'>" . $passengers . "</td>";
                $msg .= "</tr>";
            }
            $msg .= '</table>';
            $msg .= "<br>Thanks and Regards<br>";
            $msg .= "<b>priohub Support<b><br>";
            $subject = "Cancelled Order backfilling required on TravelBox";
            $arraylist = $event_details = [];
            $arraylist['emailc'] = LOCAL_ENVIRONMENT != "Live" ? NOTIFICATION_EMAILS : "aa-opsresvn@emirates.com";
            $arraylist['html'] = $msg;
            $arraylist['from'] = $api_global_constant_values['PRIOHUB_NO_REPLY_EMAIL']['value'];
            $arraylist['fromname'] = $api_global_constant_values['PRIOHUB_SERVICE_NAME']['value'];
            $arraylist['subject'] = LOCAL_ENVIRONMENT == "Live" ? $subject : LOCAL_ENVIRONMENT . ' ' . $subject;
            $arraylist['attachments'] = [];
            $arraylist['from_priohub'] = "1";
            $arraylist['BCC'] = LOCAL_ENVIRONMENT != "Live" ? [] : [NOTIFICATION_EMAILS, 'sergio.ornia@dnata.com'];
            $event_details['send_email_details'][] = (!empty($arraylist)) ? $arraylist : [];
            if (LOCAL_ENVIRONMENT != "Live") {
                print_r($arraylist);
            }
            if (!empty($event_details)) {
                try {
                    $this->common_sqs_server_call($api_global_constant_values['QUEUE_URL_EVENT']['value'], 'EVENT_TOPIC_ARN', $api_global_constant_values['EVENT_TOPIC_ARN']['value'], $event_details);
                } catch (Exception $e) {
                    $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => "notify_offline_third_parties_for_refund.php", 'title' => "Offline refund mail content", 'data' => json_encode(['message' => $msg, 'exception' => $e->getMessage()]), 'api_name' => "third_party_refund", 'request_time' => $this->get_current_datetime()];
                    //$this->CreateLog('notify_offline_third_parties_for_refund.php', 'Offline refund mail content', ['message' => $msg, 'exception' => $e->getMessage()]);
                }
            }
        }
    }

    /* #endregion send cancelbooking email to third party. */

    /* #region common sqs server call. */

    /**
     * @Name        : common_sqs_server_call
     * @Purpose     : create a common function for sending data to SQS
     * @params
     *          $queue_data -> data need to send in SQS queue 
     *          $queue_url  -> URL of queue (obtained from constants)
     *          $message_ref-> reference used for operating queue operation on LOCAL server 
     *          $publish_ref-> reference used when publishing data on SNS
     * @return 
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    public function common_sqs_server_call($queue_url = '', $message_ref = '', $publish_ref = '', $queue_data = []) {

        /* executing further process when having process end_poitn and sending details */
        if (!empty($queue_data) && !empty($queue_url)) {
            try {
                $aws_message = json_encode($queue_data);
                $aws_message = base64_encode(gzcompress($aws_message));
                if (LOCAL_ENVIRONMENT == 'Local') {
                    /* If don't have an empty value in reference */
                    if (!empty($message_ref)) {
                        local_queue_helper::local_queue_handler($aws_message, $message_ref);
                    }
                } else {
                    require_once 'aws-php-sdk/aws-autoloader.php';
                    $this->load->library('Sqs');
                    $sqs_object = new \Sqs();
                    $MessageId = $sqs_object->sendMessage($queue_url, $aws_message);
                    if (!empty($MessageId)) {
                        $this->load->library('Sns');
                        $sns_object = new \Sns();
                        $sns_object->publish($MessageId . '#~#' . $queue_url, $publish_ref);
                    }
                }
            } catch (Exception $e) {
                return $e->getMessage();
            }
        }
    }

    /* #endregion common sqs server call. */

    /* #region get constant from api server. */

    /**
     * @Name        : get_constant_from_api
     * @Purpose     : function for getting the constant value from the api end.
     * @param type $data => array contain vgn,third_party_name,and list of constants. 
     * @return array
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function get_constant_from_api($data = []) {
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
        $get_constant = SendRequest::send_request($curl_request_params);
        $cont_response = [];
        if (isset($get_constant['curl_info']['http_code']) && $get_constant['curl_info']['http_code'] == '200') {
            $third_party_const_response = $get_constant['response']['data']['constants'];
            foreach ($third_party_const_response as $get_constant_val) {
                if (in_array($get_constant_val['name'], $get_const)) {
                    $cont_response[$get_constant_val['name']] = $get_constant_val['value'];
                }
            }
        } else {
            /* send mail if not getting proper constants details from API end */
            /* email notification in case of reuqest is not accepted */
            $other_data = ['emailc' => NOTIFICATION_EMAILS, 'subject' => strtoupper(LOCAL_ENVIRONMENT) . ' server error in getting CONSTANTS from API server related to Any Third Party  for VGN ' . $data['vgn'] . ' booking ' . ' API', 'api_name' => 'booking'];
            $mail_data[0] = [
                'other_data' => $other_data,
                'mail_regarding' => $data['third_party'],
                'third_party_constant_response' => $get_constant
            ];
            $log_file = 'Constant_error.php';
            $request_log = ['data' => 'constant_Request', 'request_start_time' => date("Y-m-d H:i:s", time()), 'constant_value' => json_encode($get_constant)];
            //$this->CreateLog($log_file, $api_name, $request_log);
        }
        /* If there is any data prepare regarding mail */
        if (1==0 && isset($mail_data) && !empty($mail_data)) {
            /* sending mail for error either in getting constants from API end */
            foreach ($mail_data as $mail_info) {
                $this->highalert_email($data['vgn'], $mail_info);
            }
        }
        return $cont_response;
    }

    /* #endregion get constant from api server. */

    /* #region send high alert email. */

    /**
     * @Name        : highalert_email()
     * @Purpose     : To send email if the no entry exists in pt or vt table of secondary db.
     * @Params      : $visitor_group_no         - Order id to refund the order.
     *              : $tbl                      - table in which entry doesnot exists and data related to arena email.
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function highalert_email($visitor_group_no = '', $tbl = '') {
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
            //$this->CreateLog($log_file, $mail_data['other_data']['api_name'], $request_log);
            $arraylist['emailc'] = $mail_data['other_data']['emailc'];
            $arraylist['subject'] = $mail_data['other_data']['subject'];
        } else {
            /* case other than arena proxy listener */
            $msg = "Visitor_group_no: " . $visitor_group_no . "<br><br>Request time = " . gmdate("Y M d H:i:s") . "<br><br> Order not refunded in DB. Consider it in On priority from table" . $tbl;
            $arraylist['emailc'] = (LOCAL_ENVIRONMENT == 'LIVE') ? 'gagandeepgoyal@intersoftprofessional.com' : 'notification.intersoft@gmail.com';
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
            require_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sns');
            $aws_message = json_encode($event_details);
            $aws_message = base64_encode(gzcompress($aws_message));
            $queueUrl = QUEUE_URL_EVENT;
            $this->load->library('Sqs');
            $sqs_object = new \Sqs();
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'EVENT_TOPIC_ARN');
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId) {
                    $sns_object = new \Sns();
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, EVENT_TOPIC_ARN);
                }
            }
        }
    }

    /* #endregion send high alert email. */

    /* #region get constant. */

    /**
     * @Name        : get_constant()
     * @Purpose     : To get the constant values from API'S
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function get_constant() {
        /* #region get_constant  */
        global $api_global_constant_values;
        $constants[] = "GYG_TICKETS_LIST";
        $constants[] = "CRON_REDIS_SERVER";
        $constants[] = "BATCH_REDIS_SERVER";
        $constants[] = "API_REDIS_SERVER";
        $constants[] = "REDIS_AUTH_KEY";
        $constants[] = "DEFAULT_CAPACITY";
        $constants[] = "CAPACITY_FROM_REDIS";
        $constants[] = "EXPEDIA_NON_NUMERIC_SUPPLIER";
        $constants[] = "QUEUE_URL_EVENT";
        $constants[] = "EVENT_TOPIC_ARN";
        $constants[] = "NOTIFICATION_EMAILS";
        $constants[] = "PRIOPASS_NO_REPLY_EMAIL";
        $constants[] = "MESSAGE_SERVICE_NAME";
        $constants[] = "ENVIRONMENT";
        $constants[] = "EXPEDIA_TICKET_INFO";
        $constants[] = "GYG_SERVER";
        $constants[] = "GYG_AUTH_CSS";
        $constants[] = "GYG_AUTH";
        $constants[] = "CURLAUTH_BASIC";
        $constants[] = "PRIOHUB_NO_REPLY_EMAIL";
        $constants[] = "DUMMY_CUSTOMER_VALUE";
        $constants[] = "UPDATE_SECONDARY_DB";
        $constants[] = "PRIOHUB_SERVICE_NAME";
        $constants[] = "UPDATE_SECONDARY_DB";
        $constants[] = "TP_PICKUP_LINKED_WITH_REL_TARGET";
        $constants[] = "FAREHABOR_CRON_TICKET";
        $constant['const_name'] = $constants;
        $this->get_constant_from_api($constant);
        /* #endregion */
    }

    /* #endregion get constant. */
    /* #region create log. */

    /**
     * @Name : CreateLog()
     * @Purpose : To Create logs  
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021  
     */
    function CreateLog($filename, $apiname, $paramsArray) {
        if (ENABLE_LOGS) {
            $log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $key => $param) {
                    if ($i == 0) {
                        $log .= $key . '=>' . $param;
                    } else {
                        $log .= ', ' . $key . '=>' . $param;
                    }
                    $i++;
                }
            }
            if (is_file('application/storage/logs/' . $filename) && filesize('application/storage/logs/' . $filename) > 1048576) {
                rename("application/storage/logs/$filename", "application/storage/logs/" . $filename . "_" . date("m-d-Y-H-i-s") . ".php");
            }
            $fp = fopen('application/storage/logs/' . $filename, 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }

    /* #endregion create log. */
}

?>