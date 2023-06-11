<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

use Prio\helpers\V1\mpos_auth_helper;

/**
* Name:  MY_Controller
* 
* Author:  Karan <karan.intersoft@gmail.com>
* Created:  02 Sep 2015
* 
* Description:  Class to extend the CodeIgniter Controller Class.  All controllers should extend this class.
*/

class MY_Controller extends CI_Controller {
    
    var $base_url;
    var $imageDir;
    var $currency_code;
    var $currency_symbol;
    var $flag_error;
    
    function __construct() {
        parent::__construct();
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        $response = $logs = array();
        $logs['headers'] = $headers;
        if(stripos($headers['user_agent'],"android") && $headers['buildversion'] == "18.0.55" && $headers['cashier_id'] == "19487") {
            $response['status'] = (int) 0;
            $response['message'] = 'This version is no longer supported. Please update your build.';
            $logs['response'] = $response;
            $this->apilog_library->write_log($logs, 'mainLog', "Older Version", 'Bad Request by ' . $headers['cashier_id']);
            header('HTTP/1.0 400 Bad Request');
            header('Content-Type: application/json');
            header('Cache-control: no-store');
            header('Pragma: no-cache');
            echo json_encode($response);
            exit;
        }
        $this->load->helper('url');
        $this->load->library('user_agent');
        $this->load->library('Authorization', NULL, 'auth');
        $this->load->helper('language');
        $this->config->set_item('language', "english");
        $this->base_url = $this->config->config['base_url'];
        $this->imageDir = $this->config->config['imageDir'];
        $this->currency_code = 'EUR';
        $this->currency_symbol = '&euro;';
        
        $todayDate    = strtotime(gmdate("m/d/Y H:i:s"));
        $tomorrowDate = $todayDate + (1 * 24 * 60 * 60);

        header("Expires: " . gmdate("D, d M Y H:i:s", $tomorrowDate) . " GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");

        session_cache_limiter("must-revalidate");
        
        // response on adyen payment failure
        define('CAPTURE_FAILED', 'CAPTURE_FAILED');
        
        // Base url and image directory path contants
        define('BASE_URL', $this->config->config['base_url']);
        define('IMAGE_DIR', $this->config->config['imageDir']);
        
        $this->load->library("jwt/JWT", NULL, 'jwt');
        $this->db->db_debug = FALSE; //primary write db
        $this->primarydb->db->db_debug = FALSE; //primary read db
        $this->DEBUG = true;        
        $this->load->Model('V1/Common_model');
    }
    
     /* function to traverse with complete path and make folder if not exist
     * @param string $dirName (path)
     * @param  $rights
     */
    public function mkdir_r($dirName, $rights = 0777) {
        $dirs = explode('/', $dirName);
        $dir = '';
        foreach ($dirs as $part) {
            $dir .= $part . '/';
            if (!@is_dir($dir) && strlen($dir) > 0) {
                @mkdir($dir, $rights);
            }
        }
    }
    
    /**
     * @Name ISO8601_validate
     * @Purpose To validate the datetime in ISO8601 format
     * @Params $dateTime - DateTime in ISO8601 format
     * @CreatedOn 25 Aug 2016
     */
    function ISO8601_validate($dateTime = '') {
        if (preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/', $dateTime) > 0) {
            return true;
	} else {
	    return false;
        }
    }
     /**
     * @Name        : is_request_valid()
     * @Purpose     : To validate the request parameters.
     * @CreatedBy   : Karan <karan.intersoft@gmail.com> on 18 April 2016
     */
       function is_request_valid($string, $api, $other = array()) {
        $this->load->library('CI_Input');
        $this->load->library('CI_Security');
        return $this->stripHTMLtags($this->security->xss_clean($string));
    }

    /**
     * @Name        : stripHTMLtags()
     * @Purpose     : To remove the tags that are submitted in the input fields.
     * @Params      : $str - String to be cleaned.
     * @CreatedBy   : Karan <karan.intersoft@gmail.com> on 18 April 2016
     */
    function stripHTMLtags($str) {
        if (is_array($str)) {
            while (list($key) = each($str)) {
                $str[$key] = $this->stripHTMLtags($str[$key]);
            }
            return $str;
        }
        $t = preg_replace('/<[^<|>]+?>/', '', htmlspecialchars_decode($str));
        $t = str_replace(array("\n", "\r", "\n\r", "\t"), ' ', $t);
        return str_replace(array("=", "#", "%"), array("&#61;", "&#35;", "&#37;"), $t);
    }
     /* @purpose : To get the timezone for the date specfied, otherwise return the current timezone based on current date.
     * @parameters : 1) date : table to update
     * @Return : timezone 1 or 2
     */
    public function get_timezone_of_date($date = ""){
        $current_time = !empty($date)?gmdate("H:i:s",strtotime($date)):gmdate("H:i:s");
        $date = !empty($date) && strtotime($date) ? $date : gmdate("Y-m-d");
        $last_sunday_march = date('Y-m-d', strtotime(date('Y-04-01', strtotime($date)) . ' last sunday'));
        $last_sunday_october = date('Y-m-d', strtotime(date('Y-11-01', strtotime($date)) . ' last sunday'));
        if (strtotime($date) >= strtotime($last_sunday_march) && strtotime($date) < strtotime($last_sunday_october)) {
            if(strtotime($date) == strtotime($last_sunday_march) && $current_time < "02:00:00"){
                $timezone = '+1';
            }else{
                $timezone = '+2';
            }
        } else {
            if(strtotime($date) == strtotime($last_sunday_october) && $current_time < "02:00:00"){
                $timezone = '+2'; 
            }else{
                $timezone = '+1';
            }
        }
        return $timezone;
    }
    
    /**
     * Function to get current datetime with seconds for controller
     * 14 nov, 2018
     */
    function get_current_datetime() {
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("Y-m-d H:i:s",$date_array[1]);
        return $date.":" . number_format($date_array[0],3);        
    }
        
    function replace_hyphens_with_underscores($array = array()) {
        if (!empty($array)) {
            $array_return = array();
            foreach ($array as $key => $value) {
                $array_return[str_replace('-', '_', strtolower($key))] = $value;
            }
            return $array_return;
        }
    }
    /**
     * @name : validate_request_token()
     * @purpose : to check jwt_token is set or not
     * @created by : supriya saxena<supriya10.aipl@gmail.com> on 4 March, 2020
     */
    function validate_request_token($headers = array()) {
            $jsonStr = file_get_contents("php://input"); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $logs['headers ' . date('H:i:s')] = $headers;
            $logs['request ' . date('H:i:s')] = $_REQUEST;
            $response = array();
            $response['status'] = (int) 0;
            $response['errorCode'] = 'VALIDATION_FAILURE..';
            $response['errorMessage'] = 'Please update latest version of app.';
            $logs['response' . date('H:i:s')] = $response;
            $MPOS_LOGS['API'] = $this->uri->segment(2);
            $MPOS_LOGS[$this->uri->segment(2)] = $logs;
            $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $headers['cashier_id']);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
    }
    /**
     * @name : validate_api()
     * @purpose : to validate  api(s)
     * @created by : supriya saxena<supriya10.aipl@gmail.com> on 22 may, 2020
     */
    function validate_api($api) {
        global $MPOS_LOGS;
        $MPOS_LOGS['headers'] = $headers = $this->replace_hyphens_with_underscores(apache_request_headers()); 
        $segment = $this->uri->segment(1); 
        if($api != '') {
            try {
                if(empty($_REQUEST)) {
                    /* If some data is passed in request */
                    $jsonStr = file_get_contents("php://input"); /* read the HTTP body. */
                    $_REQUEST = json_decode($jsonStr, TRUE);
                }
                if($api != "update_shift_report"){
                    $_REQUEST = $this->is_request_valid($_REQUEST, $api);
                }
                $MPOS_LOGS['request'] = $_REQUEST;
                $MPOS_LOGS['request_time'] = date('H:i:s');
                /* Initialisation section */
                $response = array();
                if($headers['jwt_token'] == 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjNhYTE0OGNkMDcyOGUzMDNkMzI2ZGU1NjBhMzVmYjFiYTMyYTUxNDkiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiZGV2IHVzZXIiLCJjYXNoaWVyVHlwZSI6MSwiY2FzaGllcklkIjoiMTQyODkzMSIsImRpc3RyaWJ1dG9ySWQiOiIzODMxMyIsIm93blN1cHBsaWVySWQiOiIzODMxMSIsInBvc1R5cGUiOiIyIiwicmVnaW9uYWxfc2V0dGluZ3MiOiJleUprWVhSbFgyWnZjbTFoZENJNklsbGNMMjFjTDJRaUxDSjBhVzFsWDJadmNtMWhkQ0k2SWtnNmFTSXNJbU4xY25KbGJtTjVYM0J2YzJsMGFXOXVJam9pY21sbmFIUmZkMmwwYUc5MWRGOXpjR0ZqWlNJc0luUm9iM1Z6WVc1a1gzTmxjR0Z5WVhSdmNpSTZJaTRpTENKa1pXTnBiV0ZzWDNObGNHRnlZWFJ2Y2lJNklpNGlMQ0p1YjE5dlpsOWtaV05wYldGc2N5STZJakVpTENKc1lXNW5kV0ZuWlNJNkltNXNMVTVNSWl3aWRHbHRaWHB2Ym1VaU9pSkJjMmxoWEM5VWFHbHRjR2gxSW4wPSIsImFkbWluSWQiOjE4OTksImFkbWluTmFtZSI6Ik1wb3MgRGV2IiwiZGlzdHJpYnV0b3JOYW1lIjoiTXBvcyBEZXYiLCJwbGF0Zm9ybUlkIjoxLCJwbGF0Zm9ybU5hbWUiOiJQcmlvdGlja2V0IiwiaXNzIjoiaHR0cHM6Ly9zZWN1cmV0b2tlbi5nb29nbGUuY29tL3Rlc3QtcHJpb3RpY2tldCIsImF1ZCI6InRlc3QtcHJpb3RpY2tldCIsImF1dGhfdGltZSI6MTY0MzQ0MDQ2NywidXNlcl9pZCI6Ik16Z3pNVE5mWTJGemFHbGxja0J0Y0c5ekxtUmxkZz09Iiwic3ViIjoiTXpnek1UTmZZMkZ6YUdsbGNrQnRjRzl6TG1SbGRnPT0iLCJpYXQiOjE2NDM0NDA0NjcsImV4cCI6MTY0MzQ0NDA2NywiZW1haWwiOiJjYXNoaWVyQG1wb3MuZGV2IiwiZW1haWxfdmVyaWZpZWQiOmZhbHNlLCJmaXJlYmFzZSI6eyJpZGVudGl0aWVzIjp7ImVtYWlsIjpbImNhc2hpZXJAbXBvcy5kZXYiXX0sInNpZ25faW5fcHJvdmlkZXIiOiJwYXNzd29yZCJ9fQ.i5BtzKYzaTDNXNn7FLXQClaWj0_0ISLUXYSNtQffs9VzHxgivuByLiAUbYyL6zYpvd5vd0wCG507vgINgILroe1QXPwqSQyfIt1Abwe9fW_c5QMgzaE42uddGs_1DJol9CWig6U20A29iodBg12QNYpk0YkYpB70Emr0eaEue-iej_Rq5UwsAICAClPeDeXAj8-rg9nGwfxJKdc-fsafXQV6r7191Z_ua0oWkQL3cu2aLbtrJzpmp1cPnTYYasS_cVQIxB5O--uHLZBHWq7fxo5sXf1l99tcsxlgpwnsd5uQx9nmI_SsRQecxZeZPYBR0mN9bmHNq_RXfU1HGCsFJw') { //static token for dev server
                    $error_msg  = $this->authorization->validate_all_requests($_REQUEST, $api);
                    $return_array = array() ;
                    $return_array['errors'] = $error_msg;
                    $return_array['request'] = $_REQUEST;
                    $return_array['validate_token'] = json_decode('{"status":1,"user_details":{"name":"dev user","cashierType":1,"cashierId":"1428931","distributorId":"38313","ownSupplierId":"38311","posType":"2","regional_settings":"eyJkYXRlX2Zvcm1hdCI6IllcL21cL2QiLCJ0aW1lX2Zvcm1hdCI6Ikg6aSIsImN1cnJlbmN5X3Bvc2l0aW9uIjoicmlnaHRfd2l0aG91dF9zcGFjZSIsInRob3VzYW5kX3NlcGFyYXRvciI6Ii4iLCJkZWNpbWFsX3NlcGFyYXRvciI6Ii4iLCJub19vZl9kZWNpbWFscyI6IjEiLCJsYW5ndWFnZSI6Im5sLU5MIiwidGltZXpvbmUiOiJBc2lhXC9UaGltcGh1In0=","adminId":1899,"adminName":"Mpos Dev","distributorName":"Mpos Dev","platformId":1,"platformName":"Prioticket","iss":"https:\/\/securetoken.google.com\/test-prioticket","aud":"test-prioticket","auth_time":1643440467,"user_id":"MzgzMTNfY2FzaGllckBtcG9zLmRldg==","sub":"MzgzMTNfY2FzaGllckBtcG9zLmRldg==","iat":1643440467,"exp":1643444067,"email":"cashier@mpos.dev","email_verified":false,"firebase":{"identities":{"email":["cashier@mpos.dev"]},"sign_in_provider":"password"}}}', true);
                    $return_array['headers'] = $headers;
                    return $return_array;
                } else if ((isset($headers['jwt_token']) && !empty($headers['jwt_token']) && $headers['cashier_id'] > 0) ||
                 (isset($headers['Authorization']) && !empty($headers['Authorization']) && $headers['Authorization'] == (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)) ||
                 (isset($headers['authorization']) && !empty($headers['authorization']) && $headers['authorization'] == (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)) ||
                 (!isset($headers['jwt_token']) && !empty($_SERVER) && $_SERVER['PHP_AUTH_USER'] == ADYEN_AUTH_USER && $_SERVER['PHP_AUTH_PW'] == ADYEN_AUTH_PWD && $headers['eventcode'] == 'CAPTURE')
                ) {
                    /* Will proceed in case of GET request or POST request with json contents */
                    if($_SERVER['PHP_AUTH_USER'] == ADYEN_AUTH_USER && $_SERVER['PHP_AUTH_PW'] == ADYEN_AUTH_PWD && $headers['eventcode'] == 'CAPTURE') {
                        $return_array = array() ;
                        $return_array['request'] = $_REQUEST;
                        $MPOS_LOGS['external_reference'] = 'order_process_from_webHook';
                        return $return_array;
                    } else if ($api == 'cancel_order' && ((!empty($headers['Authorization']) && $headers['Authorization']== (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)) || (!empty($headers['authorization']) && $headers['authorization'] == (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)))){
                        $error_msg  = $this->authorization->validate_all_requests($_REQUEST, $api);
                        $return_array = array() ;
                        $return_array['errors'] = $error_msg;
                        $return_array['request'] = $_REQUEST;
                        $return_array['headers'] = $headers;
                        $MPOS_LOGS['external_reference'] = 'Cancel_order_from_firebase_pending_request';
                        return $return_array;
                    } else if(
                        $_SERVER['REQUEST_METHOD'] == 'GET' || 
                        (!empty($_REQUEST) && $_SERVER['REQUEST_METHOD'] == 'POST' && (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false))
                        || (empty($_REQUEST) && $_SERVER['REQUEST_METHOD'] == 'POST' && $api == 'tickets_listing')) {
                        $validate_token = mpos_auth_helper::verify_token($headers['jwt_token'], $headers['cashier_id']);  
                        $MPOS_LOGS['validated_token'] = $validate_token;
                        if ($validate_token['status'] == 1 && !empty($validate_token['user_details']) || isset($headers['Authorization'])) { 
                            //validate requets parameters 
                            $error_msg  = $this->authorization->validate_all_requests($_REQUEST, $api);
                            $return_array = array() ;
                            $return_array['errors'] = $error_msg;
                            $return_array['request'] = $_REQUEST;
                            $return_array['validate_token'] = $validate_token;
                            $return_array['headers'] = $headers;
                            return $return_array;
                        } else if ($validate_token['status'] == 16) {
                            $response = $this->exception_handler->show_error($validate_token['status'], $validate_token['errorCode'], $validate_token['errorMessage']);
                        } else {
                            header('WWW-Authenticate: Basic realm="Authentication Required"');
                            $response = $this->exception_handler->error_401($validate_token['status'], $validate_token['errorCode'], $validate_token['errorMessage']);
                        }    
                    } else {
                        $response = $this->exception_handler->error_400();
                    }
                }  else if(isset($_SERVER['PHP_AUTH_USER']) && $headers['eventcode'] == 'CAPTURE'&& ($_SERVER['PHP_AUTH_USER'] != ADYEN_AUTH_USER || $_SERVER['PHP_AUTH_PW'] == ADYEN_AUTH_PWD)) {
                    $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $headers['visitor_group_no'], $spcl_ref);
                    $graylogs[] = array(
                        'log_type' => 'mainLog',
                        'data' => json_encode(array(
                            'message' => 'AUTHORIZATION_FAILURE',
                            'PHP_AUTH_USER' => $_SERVER['PHP_AUTH_USER'],
                            'visitor_group_no' => $headers['visitor_group_no']
                        )), 
                        'api_name' => 'processed_order_with_adyen_amount'
                    );
                    $this->apilog_library->CreateGrayLog($graylogs);
                    $response = $this->exception_handler->show_error();
                } else {
                    $response = $this->exception_handler->show_error();
                }    
            } catch (Exception $e) {
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            }
        } else {
            $response = $this->exception_handler->show_error(0, 'INVALID_API', 'Requested API not valid or misspelled.');
        }
        $log_ref = '';
        $spcl_ref = '';
        if ($segment == 'mpos') {
                if($api == 'signOut') {
                    $log_ref = $_REQUEST['user_id'];
                } else if($api == 'checkout') {
                    $spcl_ref = $_REQUEST['hotel_id'];
                    $activation_method = $_REQUEST['payment_method'];
                    if ($activation_method == 19) { //scan exception
                        $spcl_ref = 'scan_exception_order_ ' . $_REQUEST['hotel_id'];
                    }
                    $log_ref = $_REQUEST['visitor_group_no'];
                } else if($api == 'manual_payment' || $api == 'activate_priopass') {
                    $log_ref = $_REQUEST['visitor_group_no'];
                } else if($api == 'authorise_creditcard') {
                    $log_ref =  $_REQUEST['shopperEmail'];
                } else if ($api == 'refund_cc_payment' || $api == 'refund_cc_authorized_payment') {
                    $log_ref =  $_REQUEST['merchant_account'];
                } else if($api == 'third_party_tickets_availability' || $api == 'reserve_third_party_tickets' || $api == 'cancel_third_party_tickets') {
                    $log_ref =  $_REQUEST['ticket_id'];
                } else if($api == 'update_shift_report') {
                    $log_ref =  $_SERVER['PHP_AUTH_USER'];
                } else if($api == 'check_priopass') {
                    $log_ref =  $_REQUEST['passes'];
                }
        } else if ($segment == 'firebase') {
            if($api == 'listing') {
                $log_ref = $_REQUEST['search'];
            } else if($api == 'order_details' || $api == 'update' || $api == 'update_note' || $api == 'cancel' || $api == 'cancel_citycard' || $api == 'cancel_order' || $api == 'partially_cancel_order') {
                $log_ref = $_REQUEST['order_id'];
            } else if($api == 'order_details_on_cancel') {
                $log_ref = $_REQUEST['pass_no'];
            } else if($api == 'sales_report_v4') {
                $log_ref = $_REQUEST['cashier_id'] . '_' . $_REQUEST['shift_id'];
            }
        } else if ($segment == 'host_app') {
            if($api == 'search') {
                $log_ref = $_REQUEST['supplier_id'] . '_' . $_REQUEST['search_value'];
                $spcl_ref = $_REQUEST['show_orders'];
            } else if($api == 'activate') {
                $log_ref = $_REQUEST['bleep_pass_no'];
                $spcl_ref = $_REQUEST['allow_activation'];
            } else if($api == 'remove_pass') {
                $log_ref = $_REQUEST['bleep_pass_no'];
                $spcl_ref = $_REQUEST['deactivate_pass'];
            } else if($api == 'guest_details') {
                $log_ref = $_REQUEST['guest_details']['email_id'];
            } else if($api == 'update_guest_details') {
                $log_ref = $_REQUEST['updated_guest_details']['email_id'];
            }
        } else if ($segment == 'contiki_tours' && ($api == 'tour_details' || $api == 'trip_overview')) {
            $log_ref = $_REQUEST['ticket_id'];
        } else if($segment == 'guest_manifest') {
            if($api == 'update_timeslots') {
                $log_ref = $_REQUEST['shared_capacity_id'] . "_" . $_REQUEST['from_time'] . "_" . $_REQUEST['to_time'];
            } else if($api == 'get_timeslots') {
                $log_ref = $_REQUEST['shared_capacity_id'] . "_" . $_REQUEST['from_date'] . "_" . $_REQUEST['to_date'];    
            } else if($api == 'get_timeslot_bookings') {
                $log_ref = $_REQUEST['supplier_id'] . "_" . $_REQUEST['shared_capacity_id'] . "_" . $_REQUEST['from_time'] . "_" . $_REQUEST['to_time'];
            }
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $log_ref, $spcl_ref);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }




   
}

/* End of file MY_Controller.php */
/* Location: ./application/libraries/MY_Controller.php */
?>
