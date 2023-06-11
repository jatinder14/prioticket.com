<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Notification extends MY_Controller {
    /* #region  BOC of class Notification */

    var $base_url;

    /* #region main function to load controller notification */

    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('sendemail_model');
        $this->load->model('notification_model');
        $this->load->library('log_library');
        $this->load->library('Sns');
        $this->load->library('Sqs');
        $this->base_url = $this->config->config['base_url'];
    }

    /* #endregion main function to load controller notification */

    /* #region function used to send notification to users  */

    /**
     * send_notification
     *
     * @return void
     */
    function send_notification() {
        $postBodylog = file_get_contents('php://input');
        $this->CreateLog('notification_request_log.log', strtotime(gmdate("Y-m-d H:i:s")).'_request_message', array('message' => json_encode($postBodylog)));
        $grayLogData = array(
            'log_name'           => 'WebhookNotification', 
            'log_type'           => 'Notification', 
            'api_name'           =>  'SQS Notification', 
            'request_time'       => date("Y-m-d H:i:s"),
            'reference'          => strtotime(gmdate("Y-m-d H:i:s"))
        );
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $sqs_object = new Sqs();
        $queueUrl = SEND_NOTIFICATION_QUEUE_URL;
        $messages = array();
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
            if (SERVER_ENVIRONMENT != 'Local' && (!isset($request_headers['X-Amz-Sns-Topic-Arn']) || $request_headers['X-Amz-Sns-Topic-Arn'] != SEND_NOTIFICATION_ARN)) {
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
                    /* BOC extract and convert data in array from queue */
                    $string = $message['Body'];
                } else {
                    $string = $postBody;
                }
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $data = json_decode($string, true);
                $api_data = json_decode($data['data'],true);
                $reference = strtotime(gmdate("Y-m-d H:i:s"));
                $numeric_api_version = isset($api_data['api_version']) ? $api_data['api_version'] : '3.3' ;
                $adam_tower_suppliers = !empty(ADAM_TOWER_SUPPLIER_ID) ? json_decode(ADAM_TOWER_SUPPLIER_ID, true) : array();
                foreach ($data['user_type'] as $user_type => $user_data) {
                    foreach ($user_data as $user_id => $webhook_data) {
                        if ($webhook_data['is_via_url'] == '1' && !empty($webhook_data['url'])) {
                            /* Send data on link */
                            /* Handle case for dummy endpoint */
                            if (strpos($webhook_data['url'], $_SERVER['HTTP_HOST'])) {
                                $response = $this->call();
                            } else {
                                $webhook_data['headers']['Content-Type'] = "application/json";
                                // if (in_array($user_id, $adam_tower_suppliers)) {
                                //     $webhook_data['headers']['Content-Type'] = "multipart/form-data; boundary=*";
                                // }
                                if (strpos($webhook_data['url'], "vidi.back4app.io") || (!empty($numeric_api_version) && $numeric_api_version >= 3.4)) {
                                    $webhook_data['headers'] = $this->notification_model->setHeaderValues($webhook_data['headers']);
                                    $requestData = $data['data'];
                                } else {
                                    $requestData = array('data' => $data['data']);
                                }
                                $response = $this->sendCurlNotification($webhook_data['method'], $webhook_data['url'], $requestData, $webhook_data['headers']);
                            }
                        } else {
                            /* Send data on email */
                            $response['success'] = 'email sent';
                        }
                        $webhook_data['user_type'] = strtoupper($user_type);
                        $webhook_data['user_id'] = $user_id;
                        $grayLogData['data'] = json_encode(array(
                                                'request' => array(
                                                    'webhook_request' => $webhook_data,
                                                    'webhook_data' => $api_data,
                                                ),
                                                'response' => isset($response['success']) ? $response['success'] : (isset($response['error']) ? $response['error'] : "")
                                            ));
                        $grayLogData['http_status'] = isset($response['http_status']) ? $response['http_status'] : '504';
                        $grayLogData['processing_time'] = isset($response['processing_time']) ? $response['processing_time'] : 0;
                        $graylogs[] = $grayLogData;
                    }
                }
                /* EOC It remove message from SQS queue for next entry. */
                if (SERVER_ENVIRONMENT != 'Local') {
                    if(!empty($graylogs)) {
                        $this->log_library->CreateGrayLog($graylogs);
                    }
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                } else {
                    $this->CreateLog('notification_queue_log.php', 'notification', array('data' => json_encode($graylogs)));
                }
            }
        }
    }
    /* #endregion function used to send notification to users */
    
    /* #region function used to send notification through curl */
    /**
     * sendCurlNotification
     *
     * @param string $url
     * @param mixed $data
     * @return response
     */
    function sendCurlNotification($method, $url, $data, $headers = array())
    {
        $result = array();
        // Generate curl request
        $curl_session = curl_init($url);
        // Tell curl to use HTTP POST
        curl_setopt($curl_session, CURLOPT_CUSTOMREQUEST, $method);
        // Tell curl that this is the body of the POST
        curl_setopt($curl_session, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl_session, CURLOPT_HTTPHEADER, $headers);
        // Tell PHP not to use SSLv3 (instead opting for TLS)
        curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_session, CURLOPT_TIMEOUT_MS, NOTIFICATION_CURL_TIMEOUT);
        // obtain response
        $response = curl_exec($curl_session);
        $curl_info = curl_getinfo($curl_session);
        $result['http_status'] = isset($curl_info['http_code']) ? $curl_info['http_code'] : '';
        $result['processing_time'] = isset($curl_info['total_time']) ? ($curl_info['total_time'] * 1000) : 0;
        if (curl_errno($curl_session)) {
            $result['error'] = curl_error($curl_session);
        }
        curl_close($curl_session);

        if (!isset($result['error'])) {
            $result['success'] = json_decode($response,true);
        }
        
        return $result;
    }
    /* #endregion function used to send notification through curl */
    
    /* #region Dummy function used to get response for notification sending through curl */
    /**
     * call
     *
     * @param string $string
     * @return array
     */
    function call($string = '') {
        return array('success' => array('status' => 200, 'response' => 'Success'));
    }
    /* #endregion Dummy function used to get response for notification sending through curl */
    
    /* #region function used to call notification api manually */
    /**
     * checkNotification
     *
     * @param string $reference_id
     * @param string $event_name
     * @return void
     */
    function checkNotification($reference_id = '', $event_name = '', $distributor_id = '')
    {
        $getHeaders = getallheaders();
        if (!empty($getHeaders['Authorization']) && $getHeaders['Authorization'] == "NotificationCheck#123") {
            if (!empty($reference_id) && !empty($event_name)) {
                $notify_request['reference_id'] = $reference_id;
                $notify_request['event'] = [$event_name];
                if (!empty($distributor_id)) {
                    $notify_request['distributor_id'] = $distributor_id;
                }
                $notification_response = $this->notification_model->checkNotificationEventExist(array($notify_request));
            }
        }
    }
    /**
     * @name product_notifications
     * @purpose : For sending notifications while product create and update
     * @created by : shiwani<shivani.aipl@gamil.com>
     * @created at: 11 Dec 2020
     */
    function product_notifications($notify_request = array()) {
        try {
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $postBody = file_get_contents('php://input');

            if (strtoupper(SERVER_ENVIRONMENT) == 'LOCAL') {
                $queue_messages[] = $postBody;
            } else {
                $sqs_object = new Sqs();
                $queueUrl = TEST_POST_COMMON_NOTIFICATION_PROCESS;
                /* It return Data from message */
                $messages = $sqs_object->receiveMessage($queueUrl);
                $queue_messages = $messages->getPath('Messages');
            }
            if (!empty($queue_messages)) {
                foreach ($queue_messages as $message) {
                    if (strtoupper(SERVER_ENVIRONMENT) == 'LOCAL') {
                        $data_string = gzuncompress(base64_decode($message));
                    } else {
                        $data_string = gzuncompress(base64_decode($message['Body']));
                    }
                    $data_string = utf8_decode($data_string);
                    $data = json_decode($data_string, TRUE);
                    if (!empty($data)) {
                        $product_notification_response = $this->notification_model->checkNotificationEventExist($data);
                    }
                    if (strtoupper(SERVER_ENVIRONMENT) != 'LOCAL') {
                        $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    }
                }
            }
        } catch (Exception $e){
            $this->CreateLog('product_notifications.php', 'Exception 1: ', array('EventStart' => gmdate('Y-m-d H:i:s'), 'exception' => json_encode($e->getMessage())));
        }
    }
    /* #endregion function used to call notification api manually */
    /* #endregion EOC of class Notification */
}
