<?php

/**
 * @Name     : Shift report Controller
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 8th May 2023
 */

namespace Prio\Traits\V2\Controllers;
use \Prio\helpers\V1\mpos_auth_helper;
use \Prio\helpers\V1\common_error_specification_helper;
trait TShift_report
{
    public $apilog_library, $shift_report_model, $authorization, $common_model, $exception_handler;
    #region Function to set variable under constuction 
    public function __construct()
    {
        parent::__construct();
        $this->load->model('V2/shift_report_model');
        global $MPOS_LOGS;
        $MPOS_LOGS['API'] = $this->router->method;
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        $MPOS_LOGS['headers'] = $headers;
        $CI = &get_instance();
        $CI->load->library('exception_handler');
        $CI->load->library('Authorization');
        $microtime = microtime();
        $search = array('0.', ' ');
        $replace = array('', '');
        $error_reference_no = str_replace($search, $replace, $microtime);
        define('ERROR_REFERENCE_NUMBER', $error_reference_no);
    }
    #endregion Function to set variable under constuction 

    /* #region Function to validate the request of the api. */
    /**
     * check_api_request
     *
     * @param  apiname $api_name
     * @return array
     */    

    function check_api_request($api_name = '')
    {
        /* Intialize variable */
        global $MPOS_LOGS;
        header('Content-Type: application/json');
        $api_req = '';
        $requestauthentication = mpos_auth_helper::getBearerToken();
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if ($requestauthentication && !empty($requestauthentication) && $requestauthentication != null && isset($headers['cashier_id']) && $headers['cashier_id'] > 0) {
            $validate_token = mpos_auth_helper::verify_token($requestauthentication, $headers['cashier_id']);
            $MPOS_LOGS['validated_token'] = $validate_token;

            /* Check if token verify and validate with user details */
            if ($validate_token['status'] == 1 && !empty($validate_token['user_details'])) {
                if (($api_name == "shift_report") && ($_SERVER['REQUEST_METHOD'] == 'POST')) {
                    $jsonStr = file_get_contents("php://input", 'r');
                } 
                /* Get data from user table */
                if (empty($jsonStr)) {
                    $invalid_request = 1;
                    $MPOS_LOGS['invalid_request'] = true;
                } else {
                    $_REQUEST = json_decode($jsonStr, TRUE);
                    $user_detail = $this->common_model->get_user_details(isset($headers['supplier_cashier_id']) ? $headers['supplier_cashier_id'] : '');
                    $user_detail->cashierId = isset($validate_token['user_details']['cashierId']) ? $validate_token['user_details']['cashierId'] : '';
                    $user_detail->dist_id = $validate_token['user_details']['distributorId'];
                    if ($validate_token['user_details']['cashierType'] == "3") {
                        $user_detail->reseller_id = $validate_token['user_details']['resellerId'];
                        $user_detail->reseller_cashier_id = $validate_token['user_details']['resellerCashierId'];
                        $user_detail->reseller_cashier_name = $validate_token['user_details']['name'];
                    }
                    $user_detail->cashier_type = $validate_token['user_details']['cashierType'];
                    $user_detail->app_flavour = isset($headers['app_flavour']) ? $headers['app_flavour'] : '';
                    $return_array = array();
                    $return_array['errors'] = [];
                    $return_array['api_req'] = $api_req;
                    $return_array['request'] = $_REQUEST;
                    $return_array['validate_token'] = $validate_token;
                    $return_array['headers'] = $headers;
                    $return_array['user_detail'] = $user_detail;
                    return $return_array;
                }
            } else {
                $invalid_auth_header = 1;
            }
        } else {
            $invalid_header = 1;
        }
        $MPOS_LOGS['invalid_header invalid_request'] = array($invalid_header, $invalid_request);
        if ($invalid_request) {
            header('HTTP/1.0 405 Method Not Allowed');
            $header = '405 Method Not Allowed';
            $error = 'INVALID_REQUEST';
            $error_message = 'Invalid request contents.';
            $error_uri = 'https://support.prioticket.com/docs/';
            $error_description = 'A request method is not supported for the requested resource; for example, a GET request on a form that requires data to be presented via POST, or a PUT request on a read-only resource.';
            $errors = [
                'Invalid request contents.'
            ];
        }
        if ($invalid_header || $invalid_auth_header) {
            header('HTTP/1.0 400 Bad Request');
            $header = '400 Bad Request';
            $error = 'INVALID_TOKEN';
            $error_message = 'Something went wrong. Please reload the page or try again later';
            $error_uri = 'https://support.prioticket.com/docs/';
            $error_description = 'The access token provided is expired, revoked, malformed, or invalid for other reasons. The resource SHOULD respond with the HTTP 401 (Unauthorized) status code.  The client MAY request a new access token and retry the protected resource request.';
            $errors = [
                'Invalid signature.'
            ];
        }
        if ($invalid_auth_header == 1) {
            if ((isset($validate_token['status'])) && $validate_token['status'] == 16) {
                $status = $validate_token['status'];
                $error = $validate_token['errorCode'];
                $error_message = $validate_token['errorMessage'];
            } else {
                header('WWW-Authenticate: Basic realm="Authentication Required"');
                header('HTTP/1.0 401 Unauthorized');
                $error = (isset($validate_token['errorCode'])) ? $validate_token['errorCode'] : 'AUTHORIZATION_FAILURE';
                $error_message = (isset($validate_token['errorMessage'])) ? $validate_token['errorMessage'] : 'The provided credentials are not valid.';
            }
        }
        $response = array();
        $response['header'] = $header;
        $response['error'] = $error;
        $response['error_description'] = $error_description;
        $response['error_url'] = $error_uri;
        $response['error_reference'] = ERROR_REFERENCE_NUMBER;
        $response['error_message'] = $error_message;
        $response['errors'] = $errors;
        $MPOS_LOGS['response'] = $response;
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $api_name);
        echo json_encode($response);
        exit();
    }
    
    /* #region Function to create Shift report api for getting details of the shift. */
    /**
     * end_shift_report
     *
     * @param  
     * @return array
     */    
    function end_shift_report()
    {
        global $MPOS_LOGS;
        try{
            $request_array = $this->check_api_request('shift_report');
            $req['cashier_id'] = $request_array['user_detail']->cashierId;
            $req['distributor_id'] = $request_array['user_detail']->dist_id;
            $req['reseller_id'] = ($request_array['user_detail']->reseller_id > 0 && !empty($request_array['user_detail']->reseller_id))  ?$request_array['user_detail']->reseller_id : "";
            $req['cashierType'] = $request_array['user_detail']->cashierType;
            $req['cashier_shift_id'] = $request_array['request']['data']['shift']["cashier_shift_id"];
            $req['supplier_cashier_id'] = $request_array['request']['data']['shift']["supplier_cashier_id"];
            $req['cashier_supplier_id'] = $request_array['request']['data']['shift']["cashier_supplier_id"];
            $req['cashier_shift_date'] = $request_array['request']['data']['shift']["cashier_shift_date"];
            $error_msg  = $this->authorization->validate_all_requests($req, 'shift_report');
            $request_array['errors'] = $error_msg;
            if (!empty($request_array['user_detail']) && empty($request_array['errors'])) {
                try {
                    $api_response = $this->shift_report_model->sales_report($req);
                    if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                        $response = $api_response;
                    } else {
                        $response = array(
                            "api_version" => "v1.0",
                            "data" => array(
                                "kind" => "shift_report",
                                "shift" => $api_response
                            ) 
                        );
                    }
                } catch (\Exception $e) {
                    $response['message'] = 'An error occurred that is unexpected.';
                    $response = $this->exception_handler->error_500();
                    $MPOS_LOGS['exception'] = $e->getMessage();
                }
            } else {
                $MPOS_LOGS['request'] = $req;
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                $response['error_reference'] = ERROR_REFERENCE_NUMBER;
                $response['errors'] = array_values($request_array['errors']);
            }
        }catch (\Exception $e){
            echo "<pre>"; print_r($e->getMessage());
        }
        
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        // $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', 'shift_report');
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response); exit();
    }
}
