<?php 

namespace Prio\Traits\V1\Controllers;
use \Prio\helpers\V1\mpos_auth_helper;
trait TApi {

    public function __construct() {
        parent::__construct();
        $this->load->model('V1/api_model');
        $this->load->model('V1/firebase_model');
        $this->log_dir = "tp_redumption/";
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['API'] = $this->router->method;
        $internal_logs['API'] = $this->router->method;
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if($this->uri->segment(2) != 'update_third_party_redumption' && !isset($headers['jwt_token']) ) {
              $this->validate_request_token($headers);
        }

        /* Firebase SCAN APIS Declariotion */
        $this->apis = array(
            'scan_pass',
            'activate_prepaid_bleep_pass',
            'check_bleep_pass_valid',
            'confirm_pass',
            'confirm_pass_preassigned',
            'get_ticket_details_with_extra_options',
            'confirm_group_chekin',
            'get_extended_tickets_listing',
            'extended_linked_ticket',
            'order_details',
            'choose_upsell_types',
            'redeem_group_tickets',
            'confirm_postpaid_tickets',
            'deactivate_visitor_pass',
            'get_postpaid_listing',
            'get_guest_details',
            'edit_guest_details',
            'getHotelBillList',
            'checkoutHotel'
        );

        $headers = array_change_key_case($headers, CASE_LOWER);
        unset ($headers['content-type']);
        unset ($headers['user-agent']);
        unset ($headers['accept']);
        unset ($headers['cache-control']);
        unset ($headers['postman-token']);
        unset ($headers['host']);
        unset ($headers['accept-encoding']);
        unset ($headers['content-length']);
        unset ($headers['connection']);
        $MPOS_LOGS['headers'] = $headers;
    }

    function update_third_party_redumption() {
        global $graylogs;
        if (ENVIRONMENT == 'Local') {
            $postBody = file_get_contents('php://input');
            $messages[] = $postBody;
        } else {
            require_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqs_object = new \Sqs();
            $queueUrl = THIRD_PARTY_REDUMPTION;
            try {
                /* It receive message from given queue. */
                $messages = $sqs_object->receiveMessage($queueUrl);
                $messages = $messages->getPath('Messages');
            } catch (\Exception $e) {
                $this->apilog->setlog($this->log_dir, 'exception.php', "Exception queue", json_encode(array($e->getMessage(), $e->getCode())));
                return;
            }
        }
        
        if ($messages) {
            foreach ($messages as $message) {
                if (ENVIRONMENT == 'Local') {
                    $string = $message;
                } else {
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    $string = $message['Body'];
                }
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $queue_data = json_decode($string, true);
                /* GET THE SCAN API : WHETHER THE PASS IS SCAN FROM VENUE APP OR OWN SUPPLIER API
                 * Incase of own supplier api : $queue_data['scan_api'] value is prio_supplier_api            
                 */
                $scan_api = !empty($queue_data['scan_api']) ? $queue_data['scan_api'] : "";
                $date_time = !empty($queue_data['date_time']) ? $queue_data['date_time'] : gmdate("Y-m-d H:i:s");
                $other_data['error_reference_no'] = $error_reference_number = !empty($queue_data['error_reference_no']) ? $queue_data['error_reference_no'] : ERROR_REFERENCE_NUMBER;
                $graylogs[] = array(
                    'log_dir' => $this->log_dir,
                    'log_filename' => 'tp_redemption_call.php',
                    'title' => 'AC queue Request',
                    'data' => json_encode(array('queue_redemption_data' => $queue_data)),
                    'api_name' => 'CSS_Redemption',
                    'error_reference_no' => $error_reference_number
                );
                $this->apilog->setlog($this->log_dir, 'tp_redemption_call.php', "AC queue Request", json_encode(array('queue_redemption_data' => $queue_data)), 'CSS redemption', $error_reference_number);
                if (!isset($queue_data['date_time']) || empty($queue_data['date_time'])) {
                    $this->apilog->setlog($this->log_dir, 'highalert_blank_datetime_sent.php', "CSS redemption empty datetime sent", json_encode(array('queue_data' => $queue_data)), 'CSS redemption', $error_reference_number);
                }
                $this->common_model->update_third_party_redumption($queue_data['passNo'], $queue_data['ticket_id'], $scan_api, $date_time, $other_data);
            }
            $this->apilog->setlog_v1($graylogs);
        }
    }
    
    /* #region Scan Process Module : Cover all the functions used in scanning process of mpos */

    /**
     * @name    : check_api_request()
     * @purpose : To check API request headers and post body parameters validation and send required response to user.
     * @where   : When call any APi of CSS scan APP
     * @return  : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 09 May 2018.
     */
    function check_api_request($api_name = '') {
        global $MPOS_LOGS;
        header('Content-Type: application/json');
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        $invalid_header = 0;
        $invalid_auth_header = 0;
        $invalid_request = 0;
        $MPOS_LOGS['headers'] = $headers;
        if( isset($headers['jwt_token']) && $headers['jwt_token'] != '' && isset($headers['cashier_id']) && $headers['cashier_id'] > 0) {
            if($headers['jwt_token'] == 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjNhYTE0OGNkMDcyOGUzMDNkMzI2ZGU1NjBhMzVmYjFiYTMyYTUxNDkiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiZGV2IHVzZXIiLCJjYXNoaWVyVHlwZSI6MSwiY2FzaGllcklkIjoiMTQyODkzMSIsImRpc3RyaWJ1dG9ySWQiOiIzODMxMyIsIm93blN1cHBsaWVySWQiOiIzODMxMSIsInBvc1R5cGUiOiIyIiwicmVnaW9uYWxfc2V0dGluZ3MiOiJleUprWVhSbFgyWnZjbTFoZENJNklsbGNMMjFjTDJRaUxDSjBhVzFsWDJadmNtMWhkQ0k2SWtnNmFTSXNJbU4xY25KbGJtTjVYM0J2YzJsMGFXOXVJam9pY21sbmFIUmZkMmwwYUc5MWRGOXpjR0ZqWlNJc0luUm9iM1Z6WVc1a1gzTmxjR0Z5WVhSdmNpSTZJaTRpTENKa1pXTnBiV0ZzWDNObGNHRnlZWFJ2Y2lJNklpNGlMQ0p1YjE5dlpsOWtaV05wYldGc2N5STZJakVpTENKc1lXNW5kV0ZuWlNJNkltNXNMVTVNSWl3aWRHbHRaWHB2Ym1VaU9pSkJjMmxoWEM5VWFHbHRjR2gxSW4wPSIsImFkbWluSWQiOjE4OTksImFkbWluTmFtZSI6Ik1wb3MgRGV2IiwiZGlzdHJpYnV0b3JOYW1lIjoiTXBvcyBEZXYiLCJwbGF0Zm9ybUlkIjoxLCJwbGF0Zm9ybU5hbWUiOiJQcmlvdGlja2V0IiwiaXNzIjoiaHR0cHM6Ly9zZWN1cmV0b2tlbi5nb29nbGUuY29tL3Rlc3QtcHJpb3RpY2tldCIsImF1ZCI6InRlc3QtcHJpb3RpY2tldCIsImF1dGhfdGltZSI6MTY0MzQ0MDQ2NywidXNlcl9pZCI6Ik16Z3pNVE5mWTJGemFHbGxja0J0Y0c5ekxtUmxkZz09Iiwic3ViIjoiTXpnek1UTmZZMkZ6YUdsbGNrQnRjRzl6TG1SbGRnPT0iLCJpYXQiOjE2NDM0NDA0NjcsImV4cCI6MTY0MzQ0NDA2NywiZW1haWwiOiJjYXNoaWVyQG1wb3MuZGV2IiwiZW1haWxfdmVyaWZpZWQiOmZhbHNlLCJmaXJlYmFzZSI6eyJpZGVudGl0aWVzIjp7ImVtYWlsIjpbImNhc2hpZXJAbXBvcy5kZXYiXX0sInNpZ25faW5fcHJvdmlkZXIiOiJwYXNzd29yZCJ9fQ.i5BtzKYzaTDNXNn7FLXQClaWj0_0ISLUXYSNtQffs9VzHxgivuByLiAUbYyL6zYpvd5vd0wCG507vgINgILroe1QXPwqSQyfIt1Abwe9fW_c5QMgzaE42uddGs_1DJol9CWig6U20A29iodBg12QNYpk0YkYpB70Emr0eaEue-iej_Rq5UwsAICAClPeDeXAj8-rg9nGwfxJKdc-fsafXQV6r7191Z_ua0oWkQL3cu2aLbtrJzpmp1cPnTYYasS_cVQIxB5O--uHLZBHWq7fxo5sXf1l99tcsxlgpwnsd5uQx9nmI_SsRQecxZeZPYBR0mN9bmHNq_RXfU1HGCsFJw') { //static token for dev server
                $validate_token = json_decode('{"status":1,"user_details":{"name":"dev user","cashierType":1,"cashierId":"1428931","distributorId":"38313","ownSupplierId":"38311","posType":"2","regional_settings":"eyJkYXRlX2Zvcm1hdCI6IllcL21cL2QiLCJ0aW1lX2Zvcm1hdCI6Ikg6aSIsImN1cnJlbmN5X3Bvc2l0aW9uIjoicmlnaHRfd2l0aG91dF9zcGFjZSIsInRob3VzYW5kX3NlcGFyYXRvciI6Ii4iLCJkZWNpbWFsX3NlcGFyYXRvciI6Ii4iLCJub19vZl9kZWNpbWFscyI6IjEiLCJsYW5ndWFnZSI6Im5sLU5MIiwidGltZXpvbmUiOiJBc2lhXC9UaGltcGh1In0=","adminId":1899,"adminName":"Mpos Dev","distributorName":"Mpos Dev","platformId":1,"platformName":"Prioticket","iss":"https:\/\/securetoken.google.com\/test-prioticket","aud":"test-prioticket","auth_time":1643440467,"user_id":"MzgzMTNfY2FzaGllckBtcG9zLmRldg==","sub":"MzgzMTNfY2FzaGllckBtcG9zLmRldg==","iat":1643440467,"exp":1643444067,"email":"cashier@mpos.dev","email_verified":false,"firebase":{"identities":{"email":["cashier@mpos.dev"]},"sign_in_provider":"password"}}}', true);
            } else {
                $validate_token = mpos_auth_helper::verify_token($headers['jwt_token'], $headers['cashier_id']);
            }
            $MPOS_LOGS['validated_token'] = $validate_token;
            if ($validate_token['status'] == 1 && !empty($validate_token['user_details'])) {
                $user_detail = $this->api_model->get_user_details($headers['supplier_cashier_id']);
                if(!empty($user_detail) || $api_name == 'check_bleep_pass_valid') {
                    $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                    if (!empty($jsonStr)) {
                        $_REQUEST = json_decode($jsonStr, TRUE);
                        $MPOS_LOGS['_REQUEST'] = $_REQUEST;
                        if (
                                ($api_name == 'scan_pass' && ( empty($_REQUEST['pass_no']) || (isset($_REQUEST['add_to_pass']) && $_REQUEST['add_to_pass'] === '') )) || 
                                ($api_name == 'activate_prepaid_bleep_pass' && ( empty($_REQUEST['pass_no']) || empty($_REQUEST['bleep_pass_no']))) || 
                                ($api_name == 'check_bleep_pass_valid' && empty($_REQUEST['pass_no']) ) || 
                                ($api_name == 'confirm_pass' && (!isset($_REQUEST['is_prepaid']) || $_REQUEST['is_prepaid'] === '' || empty($_REQUEST['pass_no']) || empty($_REQUEST['ticket_id']) || empty($_REQUEST['tps_id']) || !isset($_REQUEST['is_scan_countdown']) || $_REQUEST['is_scan_countdown'] === '')) || 
                                ($api_name == 'confirm_pass_preassigned' && (!isset($_REQUEST['pre_assigned_passes_detail']))) || 
                                ($api_name == 'get_ticket_details_with_extra_options' && ( empty($_REQUEST['visitor_group_no']) || !isset($_REQUEST['is_prepaid']) || $_REQUEST['is_prepaid'] === '' || empty($_REQUEST['pass_no']) || empty($_REQUEST['ticket_id']) || empty($_REQUEST['tps_id']) || !isset($_REQUEST['is_cash_pass']) || $_REQUEST['is_cash_pass'] === '')) || 
                                ($api_name == 'confirm_group_chekin' && ( empty($_REQUEST['visitor_group_no']) || empty($_REQUEST['ticket_id']) )) || 
                                ($api_name == 'get_extended_tickets_listing' && ( empty($_REQUEST['pass_no']) || empty($_REQUEST['upsell_left_time']) || empty($_REQUEST['upsell_ticket_ids']) )) || 
                                ($api_name == 'extended_linked_ticket' && (!isset($_REQUEST['is_prepaid']) || $_REQUEST['is_prepaid'] === '' || empty($_REQUEST['ticket_id']) || empty($_REQUEST['main_ticket_id']) || empty($_REQUEST['ticket_types']) || empty($_REQUEST['countdown_interval']) || empty($_REQUEST['upsell_left_time']) || empty($_REQUEST['visitor_group_no']) || empty($_REQUEST['title']) || empty($_REQUEST['payment_type']) )) || 
                                ($api_name == 'order_details' && ( empty($_REQUEST['visitor_group_no']) )) || 
                                ($api_name == 'choose_upsell_types' && ( empty($_REQUEST['visitor_group_no']) || empty($_REQUEST['ticket_id']) || empty($_REQUEST['ticket_id']) || empty($_REQUEST['upsell_ticket_ids']) )) || 
                                ($api_name == 'redeem_group_tickets' && (empty($_REQUEST['visitor_group_no']) || empty($_REQUEST['prepaid_ticket_ids']) || empty($_REQUEST['cashier_id']) || empty($_REQUEST['cashier_name']))) || 
                                ($api_name == 'confirm_postpaid_tickets' && empty($_REQUEST['pass_no']) ) || 
                                ($api_name == 'deactivate_visitor_pass' && empty($_REQUEST['visitor_id']) ) || 
                                ($api_name == 'get_postpaid_listing' && (empty($_REQUEST['visitor_group_no'] || $_REQUEST['ticket_id'])) ) || 
                                ($api_name == 'get_guest_details' && ( empty($_REQUEST['visitor_group_no']) || !isset($_REQUEST['hotel_id']) || $_REQUEST['hotel_id'] === '' )) || 
                                ($api_name == 'edit_guest_details' && ( empty($_REQUEST['visitor_group_no']) || !isset($_REQUEST['hotel_id']) || $_REQUEST['hotel_id'] === '' )) || 
                                ($api_name == 'getHotelBillList' && ( empty($_REQUEST['hotel_id']) )) || 
                                ($api_name == 'checkoutHotel' && ( empty($_REQUEST['visitor_group_no']) )) || 
                                (!in_array($api_name, $this->apis))
                            ) {
                                $invalid_request = 1;
                                $MPOS_LOGS['invalid_request'] = true;
                            }
                            
                        if ($invalid_request == 0) {
                            if(($validate_token['user_details']['cashierType'] == "1" || $validate_token['user_details']['cashierType'] == "3" ) &&  isset($validate_token['user_details']['cashierId']) && ($api_name == 'scan_pass' || $api_name == 'confirm_pass' || $api_name == 'activate_prepaid_bleep_pass' || $api_name == 'redeem_group_tickets' || $api_name == 'confirm_pass_preassigned' || $api_name == 'confirm_postpaid_tickets' || $api_name == 'extended_linked_ticket')) {
                                $user_detail->dist_cashier_id = $validate_token['user_details']['cashierId'];
                                $user_detail->dist_id = $validate_token['user_details']['distributorId'];
                                if($validate_token['user_details']['cashierType'] == "3") {
                                    $user_detail->reseller_id = $validate_token['user_details']['resellerId'];
                                    $user_detail->reseller_cashier_id = $validate_token['user_details']['resellerCashierId'];
                                    $user_detail->reseller_cashier_name = $validate_token['user_details']['name'];
                                }
                            } else {
                                $user_detail->dist_cashier_id = 0;
                                $user_detail->dist_id = 0;
                            }
                            $user_detail->cashier_type = $validate_token['user_details']['cashierType'];
                            $user_detail->app_flavour = $headers['app_flavour'];
                            $error_msg  = $this->authorization->validate_all_requests($_REQUEST, $api_name);
                            $return_array = array() ;
                            $return_array['errors'] = $error_msg;
                            $return_array['request'] = $_REQUEST;
                            $return_array['validate_token'] = $validate_token;
                            $return_array['headers'] = $headers;
                            $return_array['user_detail'] = $user_detail;
                            return $return_array;
                        }
                    } else {
                        $invalid_request = 1;
                        $MPOS_LOGS['invalid_request'] = true;
                    }
                } else {
                    $invalid_auth_header = 1;
                    $validate_token['errorMessage'] = 'This supplier cashier does not exist in DB.';
                }
            } else {
                $invalid_auth_header = 1;
            }
        } else {
            $invalid_header = 1;
        }

        $MPOS_LOGS['invalid_header invalid_request'] = array($invalid_header, $invalid_request);
        if ($invalid_header == 1 || $invalid_request) {
            header('HTTP/1.0 400 Bad Request');
            $errorCode = 'INVALID_REQUEST';
            $errorMessage = 'Invalid request contents.';
        }

        if ($invalid_auth_header == 1) {
            if((isset($validate_token['status'])) && $validate_token['status'] == 16) {
                $status = $validate_token['status'];
                $errorCode = $validate_token['errorCode'];
                $errorMessage = $validate_token['errorMessage'];
            } else {
                header('WWW-Authenticate: Basic realm="Authentication Required"');
                header('HTTP/1.0 401 Unauthorized');
                $errorCode = (isset($validate_token['errorCode'])) ? $validate_token['errorCode'] : 'AUTHORIZATION_FAILURE';
                $errorMessage = (isset($validate_token['errorMessage'])) ? $validate_token['errorMessage'] : 'The provided credentials are not valid.';
            }
        }

        $response = array();
        $response['status'] = (isset($status)) ? $status : 0;
        $response['errorCode'] = $errorCode;
        $response['errorMessage'] = $errorMessage;
        $MPOS_LOGS['response'] = $response;
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $api_name);
        echo json_encode($response);
        exit();
    }

    /**
     * @name: scan_pass()
     * @purpose: To give the response while scan and confirm prepaid and Preassigned passes
     * @where: scan from venue app
     * @return: json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 18 Nov 2017 
     */
    function scan_pass() {
        global $MPOS_LOGS;
        global $spcl_ref;
        global $internal_logs;
        $request_array = $this->check_api_request('scan_pass');
        if(empty($request_array['errors'])) {
            try {
                $user_detail = $request_array['user_detail'];
                $museum_id = $user_detail->supplier_id;
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'scan';
                $_REQUEST['pass_no'] = str_replace('http://qu.mu/', '', $_REQUEST['pass_no']); //if qu.mu pass is scanned
                if (preg_match("/^[a-zA-Z0-9_-]+$/", $_REQUEST['pass_no'])) { //check if scanned pas is alphnumeric then proceed
                    $_REQUEST['museum_id'] = $museum_id;
                    $_REQUEST['tps_id'] = (isset($_REQUEST['tps_id']) && $_REQUEST['tps_id'] > 0) ? $_REQUEST['tps_id'] : 0;
                    $_REQUEST['redeem_cluster_tickets'] = isset($_REQUEST['redeem_cluster_tickets']) ? $_REQUEST['redeem_cluster_tickets'] : 0;
                    $_REQUEST['gmt_device_time'] = ($_REQUEST['device_time']) / 1000;
                    $_REQUEST['device_time'] = strtotime($_REQUEST['formatted_device_time']) * 1000;
                    //if is_edited is not coming in request, set its value to 2.
                    if (!isset($_REQUEST['is_edited'])) {
                        $_REQUEST['is_edited'] = 2;
                    }
                    $spcl_ref = '';
                    if(isset($_REQUEST['third_party_details']) && $_REQUEST['third_party_details']['vendor_id'] != '' &&  substr($_REQUEST['pass_no'], 0, 2) == "ph") {
                        $this->load->model('V1/nyc_model');
                        $_REQUEST['pass_no'] = str_replace('ph', '', $_REQUEST['pass_no']);
                        $_REQUEST['vendor_id'] = $_REQUEST['third_party_details']['vendor_id'];
                        $response = $this->nyc_model->nyc_validate($user_detail, $_REQUEST);
                    } else {
                        $response = $this->api_model->scan_pass($user_detail, $_REQUEST);
                    }
                    $response = $this->api_error_response($response, 'scan_pass');
                    
                } else {
                    $response = $this->exception_handler->error_400();
                }
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500(0, 'INTERNAL_SYSTEM_FAILURE', $e->getMessage());
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else  {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }       
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        if($spcl_ref == ''){
            $spcl_ref = $museum_id;
        }
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['pass_no'], $spcl_ref);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['pass_no'], $spcl_ref);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : check_bleep_pass_valid()
     * @purpose: To give the response bleep passes valid or not
     * @where  : scan from venue app
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 15 March 2018 
     */
    function check_bleep_pass_valid() {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('check_bleep_pass_valid');
        if(empty($request_array['errors'])) {
            try {
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'city_card';
                $pass_no = $_REQUEST['pass_no'];    
                $response = $this->api_model->check_bleep_pass_valid($_REQUEST);
                $response = $this->api_error_response($response, 'check_bleep_pass_valid');           
            } catch (\Exception $e) {
                $response = array();
                $response = $this->exception_handler->error_500(0, '', $e->getMessage());
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
            $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $pass_no);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $pass_no);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : activate_prepaid_bleep_pass()
     * @purpose: To give the response while activate bleep pass in prepaid tickets
     * @where  : scan from venue app
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 12 jan 2018 
     */
    function activate_prepaid_bleep_pass() {
        global $MPOS_LOGS;
        global $spcl_ref;
        global $internal_logs;

        $request_array = $this->check_api_request('activate_prepaid_bleep_pass');
        if(empty($request_array['errors'])) {
            try {
                $user_detail = $request_array['user_detail'];
                $museum_id = $user_detail->supplier_id;
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'city_card';
                $pass_no = $_REQUEST['pass_no'];
                $bleep_pass_no = $_REQUEST['bleep_pass_no'];
                $pos_point_id = $_REQUEST['pos_point_id'];
                $shift_id = $_REQUEST['shift_id'];
                $pos_point_name = $_REQUEST['pos_point_name'];
                $spcl_ref = '';
                $new_validate_response = array();
                if(!empty($_REQUEST['bleep_pass_no'])) {
                    foreach($_REQUEST['bleep_pass_no'] as $bleep_pass_no_arr) {
                        $validate_response = $this->authorization->validate_request_params($bleep_pass_no_arr, [                                                     
                            'tps_id'                      => 'numeric',                                                                                                                                              
                            'ticket_type'                 => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                    
                            'clustering_id'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                        ]);
                        if(!empty($validate_response)) {
                            array_push($new_validate_response, $validate_response);
                        }
                    }                
                }
                if(!empty($new_validate_response)) {
                    $response = $this->exception_handler->error_400();
                } else {
                    $response = $this->api_model->activate_prepaid_bleep_pass($user_detail, $museum_id, $pass_no, $bleep_pass_no, $pos_point_id, $pos_point_name, $shift_id);
                    $response = $this->api_error_response($response, 'activate_prepaid_bleep_pass'); 
                }            
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $pass_no, $spcl_ref);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $pass_no, $spcl_ref);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : confirm_pass()
     * @purpose: To confirm the Voucher
     * @where  : scan from venue app voucher listing
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 29 Nov 2017 
     */
    function confirm_pass() {
        global $MPOS_LOGS;
        global $spcl_ref;
        global $internal_logs;
        $request_array = $this->check_api_request('confirm_pass');
        if(empty($request_array['errors'])) {
            try {
                $request_data['museum_id'] = $request_array['user_detail']->supplier_id;
    
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'redeem';
                $_REQUEST['museum_id'] = $request_data['museum_id'];
                $_REQUEST['user_detail'] = $request_array['user_detail'];
                $is_prepaid = $_REQUEST['is_prepaid'];
                $_REQUEST['is_edited'] = (isset($_REQUEST['is_edited'])) ? $_REQUEST['is_edited'] : 2;
                $_REQUEST['gmt_device_time'] = ($_REQUEST['device_time']) / 1000;
                $_REQUEST['device_time'] = strtotime($_REQUEST['formatted_device_time']) * 1000;
                $_REQUEST['shift_id'] = ($_REQUEST['shift_id'] > 0) ? $_REQUEST['shift_id'] : 0;
                $_REQUEST['allow_reservation_entry'] = !empty($_REQUEST['allow_reservation_entry']) ? (int) $_REQUEST['allow_reservation_entry'] : 0;
                $spcl_ref = '';
                $new_validate_response = array();
                if(!empty($_REQUEST['bleep_pass_no'])) {
                    foreach($_REQUEST['bleep_pass_no'] as $bleep_pass_no_arr) {
                        $validate_response = $this->authorization->validate_request_params($bleep_pass_no_arr, [                                                     
                            'tps_id'                      => 'numeric',                                                                                                                                              
                            'ticket_type'                 => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                      
                            'clustering_id'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                        ]);
                        if(!empty($validate_response)) {
                            array_push($new_validate_response, $validate_response);
                        }
                    }                
                }
                if(!empty($new_validate_response)) {
                    $response = $this->exception_handler->error_400();
                } else {
                    if ($is_prepaid == '1') {
                        $response = $this->api_model->confirm_listed_prepaid_tickets($_REQUEST);
                    } else {
                        $response = $this->preassigned_model->confirm_listed_voucher_tickets($_REQUEST);
                    }
                    $response = $this->api_error_response($response, 'confirm_pass');
                }
                
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        if ($spcl_ref == '') {
            $spcl_ref = $request_data['museum_id'];
        }
            $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['pass_no'], $spcl_ref);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['pass_no'], $spcl_ref);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : confirm_pass_preassigned()
     * @purpose: To confirm the Voucher
     * @where  : scan from venue app voucher listing
     * @return : json
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 21 Sep 2018 
     */
    function confirm_pass_preassigned() {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('confirm_pass_preassigned');
        if(empty($request_array['errors'])) {
            try {
                $request_data['user_detail']  = $request_array['user_detail'];
                $request_data['museum_id'] = $request_array['user_detail']->supplier_id;    
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'preassigned';
                $pre_assigned_passes_detail = $_REQUEST['pre_assigned_passes_detail'];
                $new_validate_response = array();
                if(!empty($pre_assigned_passes_detail)) {
                    foreach($pre_assigned_passes_detail as $pre_assigned_passes_detail_arr) {
                        $validate_response = $this->authorization->validate_request_params($pre_assigned_passes_detail_arr, [                                                                                                                                                                                                   
                            'add_to_pass'                   => 'numeric' ,                                                                       
                            'ticket_id'                     => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                            'tps_id'                        => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                            'is_scan_countdown'             => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              
                            'own_capacity_id'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                            'shared_capacity_id'            => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                            'selected_date'                 => 'date',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               
                        ]);
                        if(!empty($validate_response)) {
                            array_push($new_validate_response, $validate_response);
                        }
                        if(!empty($pre_assigned_passes_detail_arr['bleep_pass_no'])) {
                            foreach($pre_assigned_passes_detail_arr['bleep_pass_no'] as $bleep_pass_no_arr) {
                                $validate_response = $this->authorization->validate_request_params($bleep_pass_no_arr, [                                                     
                                    'tps_id'                      => 'numeric',                                                                                                                                              
                                    'ticket_type'                 => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                     
                                    'clustering_id'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                                ]);
                                if(!empty($validate_response)) {
                                    array_push($new_validate_response, $validate_response);
                                }
                            }                
                        }
                    }                
                }            
                if(!empty($new_validate_response)) {
                    $response = $this->exception_handler->error_400();
                } else {
                    $updateCapacity = true;
                    $capacity = array();
                    foreach ($pre_assigned_passes_detail as $voucher) {
                        if (!empty($voucher['selected_date']) && !empty($voucher['from_time']) && !empty($voucher['to_time'])) {
                            $capacity[$voucher['tps_id']]['ticket_id'] = $voucher['ticket_id'];
                            $capacity[$voucher['tps_id']]['selected_date'] = $voucher['selected_date'];
                            $capacity[$voucher['tps_id']]['from_time'] = $voucher['from_time'];
                            $capacity[$voucher['tps_id']]['to_time'] = $voucher['to_time'];
                            $capacity[$voucher['tps_id']]['slot_type'] = $voucher['slot_type'];
                            $capacity[$voucher['tps_id']]['quantity'] += 1;
                        }
                        $request_data['pass_no'] = $voucher['pass_no'];
                        $request_data['child_pass_no'] = $voucher['child_pass_no'];
                        $request_data['bleep_pass_no'] = $voucher['bleep_pass_no'];
                        $request_data['ticket_id'] = $voucher['ticket_id'];
                        $request_data['tps_id'] = $voucher['tps_id'];
                        $request_data['add_to_pass'] = $voucher['add_to_pass'];
                        $request_data['is_scan_countdown'] = $voucher['is_scan_countdown'];
                        $request_data['countdown_interval'] = $voucher['countdown_interval'];
                        $request_data['own_capacity_id'] = $voucher['own_capacity_id'];
                        $request_data['shared_capacity_id'] = $voucher['shared_capacity_id'];
                        $request_data['selected_date'] = $voucher['selected_date'];
                        $request_data['from_time'] = $voucher['from_time'];
                        $request_data['to_time'] = $voucher['to_time'];
                        $request_data['slot_type'] = $voucher['slot_type'];
                        $request_data['upsell_ticket_ids'] = $voucher['upsell_ticket_ids'];
                        $request_data['pos_point_id'] = $_REQUEST['pos_point_id'];
                        $request_data['shift_id'] = $_REQUEST['shift_id'];
                        $request_data['pos_point_name'] = $_REQUEST['pos_point_name'];
                        $request_data['allow_reservation_entry'] = !empty($voucher['allow_reservation_entry']) ? (int) $voucher['allow_reservation_entry'] : 0;
    
                        $response = $this->preassigned_model->confirm_listed_preassigned_voucher($request_data);
                        if(($response['status'] != 1) || (!empty($response['error_no']) && $response['error_no'] > 0)) {
                            $response = $this->api_error_response($response, 'pre_assigned_passes_detail');
                        } else {
                            if (!empty($voucher['selected_date']) && !empty($voucher['from_time']) && !empty($voucher['to_time'])) {
                                $capacity[$voucher['tps_id']]['capacity'] = (int) $response['capacity_update'];
                            }
                            unset($response['capacity_update']);
                            $message = str_replace(" ", "", strtolower(trim($response['message'])));
                            if($response['status']=='0' && $message=='batchcapacityisfull') {
                                $updateCapacity = false;
                                continue;
                            }
                        } 
                    }
                    $total_capacity = 0;
                    foreach ($capacity as $activate_pass) {
                        $ticket_id = $activate_pass['ticket_id'];
                        $selected_date = $activate_pass['selected_date'];
                        $from_time = $activate_pass['from_time'];
                        $to_time = $activate_pass['to_time'];
                        $slot_type = $activate_pass['slot_type'];
                        $total_capacity += $activate_pass['capacity'] * $activate_pass['quantity'];
                    }
                    if (!empty($capacity) && $updateCapacity) {
                        $this->firebase_model->update_capacity($ticket_id, $selected_date, $from_time, $to_time, $total_capacity, $slot_type);
                    }
                }           
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
       
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
            $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request_data['pass_no'], $request_data['museum_id']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $request_data['pass_no'], $request_data['museum_id']);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : redeem_group_tickets()
     * @purpose: To confirm the group checking in case of combi off
     * @where  : scan from venue app 
     * @return : json
     * @created by: komal<komalgarg.intersoft@gmail.com> on date 13 Aug 2017 
     */
    function redeem_group_tickets() {
        global $MPOS_LOGS;
        global $spcl_ref;
        global $internal_logs;
        $request_array = $this->check_api_request('redeem_group_tickets');
        if(empty($request_array['errors'])) {
            try {
                $request_data['museum_id'] = $request_array['user_detail']->supplier_id;
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'redeem';
                $_REQUEST['museum_id'] = $request_data['museum_id'];
                $_REQUEST['user_detail'] = $request_array['user_detail'];
                $_REQUEST['pass_no'] = (isset($_REQUEST['pass_no']) && ($_REQUEST['is_group_check_in'] == 3 || $_REQUEST['is_group_check_in'] == 4)) ? $_REQUEST['pass_no'] : "";
                $_REQUEST['device_time'] = strtotime($_REQUEST['formatted_device_time']) * 1000;
                $_REQUEST['pos_point_id'] = isset($_REQUEST['pos_point_id']) ? $_REQUEST['pos_point_id'] : 0;
                $_REQUEST['pos_point_name'] = isset($_REQUEST['pos_point_name']) ? $_REQUEST['pos_point_name'] : '';
                $_REQUEST['dist_id'] = $request_array['user_detail']->dist_id;
                $_REQUEST['dist_cashier_id'] = $request_array['user_detail']->dist_cashier_id;
                $_REQUEST['cashier_type'] = $request_array['user_detail']->cashier_type;
                $_REQUEST['app_flavour'] = $request_array['user_detail']->app_flavour;
                $spcl_ref = '';
                $response = $this->api_model->redeem_group_tickets($_REQUEST);
                $response = $this->api_error_response($response, 'redeem_group_tickets');
            } catch (\Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }     
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        if($spcl_ref == ''){
            $spcl_ref = $request_data['museum_id'];
        }
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no'], $spcl_ref);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no'], $spcl_ref);
        }
        echo json_encode($response);
        exit();
    }
    
    /**
     * @name   : confirm_postpaid_tickets()
     * @purpose: To confirm the postpaid ticket
     * @where  : scan from venue app 
     * @return : json
     * @created by: komal<komalgarg.intersoft@gmail.com> on date 26 Feb 2019 
     */
    function confirm_postpaid_tickets() {
        global $MPOS_LOGS;
        global $internal_logs;
        try {
            // If some data is passed in request    
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
            $MPOS_LOGS['request'] = $_REQUEST;
            $MPOS_LOGS['request_time'] = date('H:i:s');
            $MPOS_LOGS['operation_id'] = 'POSTPAID';
            $MPOS_LOGS['external_reference'] = 'redeem';
            $request_array = $this->check_api_request('confirm_postpaid_tickets');
            if(empty($request_array['errors'])) {               
                $_REQUEST['user_detail'] = $request_array['user_detail'];
                $_REQUEST['museum_id'] = $_REQUEST['user_detail']->supplier_id;
                $_REQUEST['hotel_id'] = $_REQUEST['user_detail']->dist_id;
                $_REQUEST['timezone'] = $headers['timezone'];            
                $response = $this->venue_model->confirm_postpaid_tickets($_REQUEST);
                $response = $this->api_error_response($response, 'confirm_postpaid_tickets');
            } else {
                $MPOS_LOGS['request'] = $request_array['request'];
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = $this->exception_handler->error_400();
            }
           
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no'], $_REQUEST['museum_id']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no'], $_REQUEST['museum_id']);
        }
        echo json_encode($response);
        exit();
    }
    
    /**
     * @name   : deactivate_visitor_pass()
     * @purpose: To deactivate pass in HTO
     * @where  : on click "suspect fraud" from app 
     * @return : json
     * @created by: komal<komalgarg.intersoft@gmail.com> on date 6 March 2019 
     */
    function deactivate_visitor_pass() {
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['operation_id'] = 'POSTPAID';
        $MPOS_LOGS['external_reference'] = 'deactivate_pass';
        try {
            $_REQUEST = json_decode($jsonStr, TRUE);
            $request_array = $this->check_api_request('deactivate_visitor_pass');
            $_REQUEST['user_detail'] = $request_array['user_detail'];
            $_REQUEST['museum_id'] = $_REQUEST['user_detail']->supplier_id;
            // If some data is passed in request    
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $response = $this->venue_model->deactivate_visitor_pass($_REQUEST);
            $response = $this->api_error_response($response, 'deactivate_visitor_pass');
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_id']);
        $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_id']);
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : get_ticket_details_with_extra_options()
     * @purpose: To confirm the Voucher
     * @where  : scan from venue app voucher listing
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 01 Dec 2017 
     */
    function get_ticket_details_with_extra_options() {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('get_ticket_details_with_extra_options'); 
        $user_detail =  $request_array['user_detail'];
        try {
            $museum_id = $user_detail->supplier_id;

            // If some data is passed in request    
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $MPOS_LOGS['request'] = $_REQUEST;
            $MPOS_LOGS['request_time'] = date('H:i:s');
            $MPOS_LOGS['operation_id'] = 'SCAN';
            $MPOS_LOGS['external_reference'] = 'ticket_details';
            $visitor_group_no = $_REQUEST['visitor_group_no'];
            $pass_no = $_REQUEST['pass_no'];
            $ticket_id = $_REQUEST['ticket_id'];
            $ticket_price_id = $_REQUEST['tps_id'];
            $is_prepaid = $_REQUEST['is_prepaid'];
            $is_cash_pass = $_REQUEST['is_cash_pass'];
            $extra_options_exists = 0;
            $action = $_REQUEST['action'];
            $is_pre_booked_ticket = $_REQUEST['is_pre_booked_ticket'];
            $hotel_checkout_status = $_REQUEST['hotel_checkout_status'];
            $hotel_name = $_REQUEST['hotel_name'];

            $response = $this->api_model->get_ticket_details_with_extra_options($user_detail, $visitor_group_no, $pass_no, $ticket_id, $ticket_price_id, $is_prepaid, $is_cash_pass, $extra_options_exists, $action, $is_pre_booked_ticket, $museum_id, $hotel_checkout_status, $hotel_name);
            $response = $this->api_error_response($response, 'get_ticket_details_with_extra_options');
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : confirm_group_chekin()
     * @purpose: To confirm the Voucher
     * @where  : scan from venue app voucher listing
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 01 Dec 2017 
     */
    function confirm_group_chekin() {
        global $MPOS_LOGS;
        global $spcl_ref;
        $request_array = $this->check_api_request('confirm_group_chekin');
        $user_detail =  $request_array['user_detail'];
        try {
            $museum_id = $user_detail->supplier_id;

            // If some data is passed in request    
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $MPOS_LOGS['request'] = $_REQUEST;
            $MPOS_LOGS['request_time'] = date('H:i:s');
            $MPOS_LOGS['operation_id'] = 'SCAN';
            $MPOS_LOGS['external_reference'] = 'redeem';
            $visitor_group_no = $_REQUEST['visitor_group_no'];
            $ticket_id = $_REQUEST['ticket_id'];

            $spcl_ref = '';
            $response = $this->api_model->confirm_group_chekin($user_detail, $museum_id, $ticket_id, $visitor_group_no);
            $response = $this->api_error_response($response, 'confirm_group_chekin');
        } catch (\Exception $e) {
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no'], $spcl_ref);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no'], $spcl_ref);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : get_extended_tickets_listing()
     * @purpose: To extend any ticket show linked countdown tickets listing
     * @where  : scan from venue app voucher listing
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 01 Dec 2017 
     */
    function get_extended_tickets_listing() {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('get_extended_tickets_listing');
        if(empty($request_array['errors'])) {
            try {
                $request_data['user_detail'] = $request_array['user_detail'];
                $request_data['museum_id'] = $request_array['user_detail']->supplier_id;    
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'upsell';
                $request_data['main_ticket_id'] = $_REQUEST['main_ticket_id'];
                $request_data['ticket_type'] = $_REQUEST['ticket_type'];
                $request_data['visitor_group_no'] = $_REQUEST['visitor_group_no'];
                $request_data['pass_no'] = $_REQUEST['pass_no'];
                $request_data['upsell_left_time'] = $_REQUEST['upsell_left_time'];
                $request_data['upsell_ticket_ids'] = $_REQUEST['upsell_ticket_ids'];
                $response = $this->api_model->get_extended_tickets_listing($request_data);
                $response = $this->api_error_response($response, 'get_extended_tickets_listing');
                
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
       
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : choose_upsell_types()
     * @purpose: To extend any ticket show linked countdown tickets listing
     * @where  : scan from venue app upsell types listing
     * @return : json
     * @created by: Taranjeet Singh<taran.intersoft@gmail.com> on date 01 June 2018 
     */
    function choose_upsell_types() {
        global $MPOS_LOGS;
        global $internal_logs;
        $request_array = $this->check_api_request('choose_upsell_types');
        if(empty($request_array['errors'])) {
            try {
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'upsell';
                $visitor_group_no = $_REQUEST['visitor_group_no'];
                $main_ticket_id = $_REQUEST['main_ticket_id'];
                $ticket_id = $_REQUEST['ticket_id'];
                $upsell_ticket_ids = $_REQUEST['upsell_ticket_ids'];
                $pass_no = $_REQUEST['pass_no'];
                $response = $this->api_model->choose_upsell_types($visitor_group_no, $main_ticket_id, $ticket_id, $upsell_ticket_ids, $pass_no);
                $response = $this->api_error_response($response, 'choose_upsell_types');                
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : extended_linked_ticket()
     * @Purpose  : to extende linked ticket insert new entry in main table
     * @Called   : called when tap to extend button on CSS APP.
     * @return : json
     * @created by: Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018 
     */
    function extended_linked_ticket() {
        global $MPOS_LOGS;
        global $internal_logs;
        $request_array = $this->check_api_request('extended_linked_ticket');
        if(empty($request_array['errors'])) {
            try {
                $request_data['user_detail']  = $request_array['user_detail'];
                $request_data['museum_id'] = $request_data['user_detail']->supplier_id;
    
                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'upsell';
                $request_data['is_prepaid'] = $_REQUEST['is_prepaid'];
                $request_data['main_ticket_id'] = $_REQUEST['main_ticket_id'];
                $request_data['ticket_id'] = $_REQUEST['ticket_id'];
                $request_data['countdown_interval'] = $_REQUEST['countdown_interval'];
                $request_data['upsell_left_time'] = $_REQUEST['upsell_left_time'];
                $request_data['visitor_group_no'] = $_REQUEST['visitor_group_no'];
                $request_data['ticket_types'] = $_REQUEST['ticket_types'];
                $request_data['title'] = $_REQUEST['title'];
                $request_data['payment_type'] = $_REQUEST['payment_type'];
                $request_data['channel_type'] = $_REQUEST['channel_type'];
                $request_data['disributor_cashier_id'] = $_REQUEST['disributor_cashier_id'];
                $request_data['disributor_cashier_name'] = $_REQUEST['disributor_cashier_name'];
                $request_data['shift_id'] = $_REQUEST['shift_id'];
                $request_data['pos_point_id'] = $_REQUEST['pos_point_id'];
                $request_data['pos_point_name'] = $_REQUEST['pos_point_name'];
                $request_data['start_amount'] = isset($_REQUEST['start_amount']) ? $_REQUEST['start_amount'] : 0;
                $new_validate_response = array();
                foreach($_REQUEST['ticket_types'] as $ticket_types) {
                    $validate_response = $this->authorization->validate_request_params($ticket_types, [                                                     
                        'tps_id'                => 'numeric',                                                                                                                                              
                        'price'                 => 'decimal',                                                                       
                        'tax'                   => 'decimal',                                                                       
                        'main_tps_id'           => 'numeric',                                                                                                                                               
                        'count'                 => 'numeric',                                                                                                                                                                                                                                                                                           
                        'ticket_type'           => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                          
                    ]); 
                    if(!empty($validate_response)) {
                        array_push($new_validate_response, $validate_response);
                    }
                }
                if(!empty($new_validate_response)) {
                    $response = $this->exception_handler->error_400();
                } else {
                    $response = $this->api_model->extended_linked_ticket($request_data);
                    $response = $this->api_error_response($response, 'extended_linked_ticket');
                }             
            } catch (\Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }     
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : order_details()
     * @Purpose  : to extende linked ticket insert new entry in main table
     * @Called   : called when tap to extend button on CSS APP.
     * @return : json
     * @created by: Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018 
     */
    function order_details() {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('order_details');
        if(empty($request_array['errors'])) {
            $user_detail  = $request_array['user_detail'];
            try {
                $museum_id = $user_detail->supplier_id;

                // If some data is passed in request    
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                $MPOS_LOGS['operation_id'] = 'SCAN';
                $MPOS_LOGS['external_reference'] = 'upsell';
                $visitor_group_no = $_REQUEST['visitor_group_no'];
                $channel_type = $_REQUEST['channel_type'];
                $pass_no = $_REQUEST['pass_no'];
                $response = $this->api_model->order_details($visitor_group_no, $museum_id, $user_detail, $channel_type, $pass_no);
                $response = $this->api_error_response($response, 'order_details');          
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }
    /**
     * @name   : get_postpaid_listing()
     * @Purpose  : to get types listing of prebooked tickets in case of postpaid pass scan
     * @Called   : called when user clicks on group checkin or one by one checkin button after pre booked ticket details
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on date 20 june 2019
     */
    function get_postpaid_listing() {
        global $MPOS_LOGS;
        global $internal_logs;
        $request_array = $this->check_api_request('get_postpaid_listing');
        if(empty($request_array['errors'])) {
            try {
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['operation_id'] = 'POSTPAID';
                $MPOS_LOGS['external_reference'] = 'ticket_details';
                $_REQUEST['device_time'] = strtotime($_REQUEST['formatted_device_time']) * 1000;
                $response = $this->api_model->get_postpaid_listing($_REQUEST);
                $response = $this->api_error_response($response, 'get_postpaid_listing');           
            } catch (\Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $_REQUEST['visitor_group_no']);
        }
        echo json_encode($response);
        exit();
    }
    
    /**
     * @name   : get_guest_details()
     * @purpose: get all detail related to particular guest
     * @return : guest data
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 07 march 2019 
     */
    function get_guest_details() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'POSTPAID';
        $MPOS_LOGS['external_reference'] = 'guest_info';
        $request_array = $this->check_api_request('get_guest_details');
        if(empty($request_array['errors'])) {
            try {
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $visitor_group_no = $_REQUEST['visitor_group_no'];
                $hotel_id = $_REQUEST['hotel_id'];
                $response = $this->venue_model->get_guest_detail($visitor_group_no, $hotel_id);           
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['status'] = 0;
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $visitor_group_no);
        echo json_encode($response);
        exit();
    }
    
    /**
     * @name   : edit_guest_details()
     * @purpose: change guest details
     * @return : return success message
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 08 march 2019 
     */
    function edit_guest_details() {
        $this->load->model('V1/venue_model');
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'POSTPAID';
        $MPOS_LOGS['external_reference'] = 'guest_info';
        $request_array = $this->check_api_request('edit_guest_details');
        if(empty($request_array['errors'])) {
            try {
                $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
                $_REQUEST = json_decode($jsonStr, TRUE);
                $request_data['visitor_group_no'] = $_REQUEST['visitor_group_no'];
                $request_data['hotel_id'] = $_REQUEST['hotel_id'];
                $request_data['room_no'] = $_REQUEST['room_no'];
                $request_data['check_out_date']       = $_REQUEST['check_out_date'];
                $request_data['check_out_time']       = $_REQUEST['check_out_time'];
                $request_data['receipt_email']        = $_REQUEST['receipt_email'];
                $request_data['visitor_id']           = $_REQUEST['visitor_id'];
                $request_data['age'] = $_REQUEST['age'];
                $request_data['user_image'] = $_REQUEST['user_image'];
                $request_data['gender'] = $_REQUEST['gender'];
                $request_data['reactivate_pass'] = $_REQUEST['reactivate_pass'];
                $request_data['pass_no']              = $_REQUEST['pass_no'];
                $response = $this->venue_model->edit_guest_details($request_data);
                $response = $this->api_error_response($response, 'edit_guest_details');           
            } catch (\Exception $e) {
                header('HTTP/1.0 500 Internal Server Error');
                $response['status']       = 0;
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['visitor_group_no']);
        echo json_encode($response);
        exit();
    }
    
    /**
     * @name   : getHotelBillList()
     * @purpose: used for hotel guests listing in active and completed 
     * @where  : 
     * @return : 
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 11 march 2019 
     */
    
    function getHotelBillList() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'POSTPAID';
        $MPOS_LOGS['external_reference'] = 'guest_info';
        $this->check_api_request('getHotelBillList');
        try {
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $request_data['hotel_id'] = $_REQUEST['hotel_id'];
            $request_data['filter'] = $_REQUEST['filter'];
            $request_data['offset'] = $_REQUEST['offset'];
            $request_data['searchText'] = $_REQUEST['searchText'];
            $request_data['searchPass'] = $_REQUEST['searchPass'];
            $response = $this->venue_model->getHotelBillList($request_data);
            $response = $this->api_error_response($response, 'getHotelBillList');
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['status'] = 0;
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['hotel_id']);
        echo json_encode($response);
        exit();
    }

    /**
     * @name   : Hotelcheckout()
     * @purpose: used for hotel guests listing in active and completed 
     * @where  : 
     * @return : success with invoice id
     * @created by: Anoop Kumar<anoop.aipl02@gmail.com> on date 11 march 2019 
     */
    
    function checkoutHotel() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'POSTPAID';
        $MPOS_LOGS['external_reference'] = 'guest_info';
        $this->check_api_request('checkoutHotel');
        try {
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $visitor_group_no = $_REQUEST['visitor_group_no'];
            $email = $_REQUEST['email'];
            $comment = $_REQUEST['comment'];
            $response = $this->venue_model->checkoutHotel($visitor_group_no, $email, $comment);
            $response = $this->api_error_response($response, 'checkoutHotel');           
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['status'] = 0;
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $visitor_group_no);
        echo json_encode($response);
        exit();
    }
      
    /**
     * @name: api_error_response
     * @purpose: common function to show api response
     * @param  mixed $response
     * @param  mixed $api_name
     * @return void
     * @created by:  supriya saxena<supriya10.aipl@gmail.com> on 28 april, 2020
     */
    function api_error_response($response, $api_name) {
        if ($response['status'] != 1) {
            $status = $response['status'];
            $message = ($response['errorMessage']) ? $response['errorMessage'] : $response['message'];
            $response = array();
            $response = $this->exception_handler->show_error($status, '', $message);
        } else if (!empty($response['error_no']) && $response['error_no'] > 0) {
            $response = array();
            $response = $this->exception_handler->error_500();
        }
        if($api_name == 'activate_prepaid_bleep_pass') {
            if (!empty($response['message']) && $response['message'] == 'pass_not_valid') {
                $response = array();
                $response = $this->exception_handler->error_500(0, 'INTERNAL_SYSTEM_FAILURE', 'Pass Not Valid');
            }
        }
        return $response ;
    }

    /* #endregion Scan Process Module : Cover all the functions used in scanning process of mpos */
}
?>