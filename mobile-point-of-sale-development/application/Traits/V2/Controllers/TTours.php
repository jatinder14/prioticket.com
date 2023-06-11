<?php

/**
 *  -- -- Contiki Tour and trips Controller -- -- 
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 5th April 2023
 */
namespace Prio\Traits\V2\Controllers;
use \Prio\helpers\V1\mpos_auth_helper;
use \Prio\helpers\V1\common_error_specification_helper;
trait TTours
 {
    public $apilog_library, $tour_model, $authorization, $common_model, $exception_handler;
    #region Function to set variable under constuction 
    public function __construct()
    {
        parent::__construct();
        $this->load->model('V2/tour_model');
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
                if (($api_name == "trip_overview" || $api_name == "tour_details" || $api_name == "ticket_listing") && ($_SERVER['REQUEST_METHOD'] == 'GET')) {
                    $_REQUEST = $_GET;
                }
                /* Get data from user table */
                if (empty($_REQUEST)) {
                    $invalid_request = 1;
                    $MPOS_LOGS['invalid_request'] = true;
                } else {
                    $user_detail = $this->common_model->get_user_details(isset($headers['supplier_cashier_id']) ? $headers['supplier_cashier_id'] : '');
                    $user_detail->dist_cashier_id = isset($validate_token['user_details']['cashierId']) ? $validate_token['user_details']['cashierId'] : '';
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

    // Function to get overview of trip
    function trip_overview()
    {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('trip_overview');
        $headers = $request_array['headers'];
        $validate_token = $request_array['validate_token'];
        $request['request_array'] = $request_array['request'];
        $request['request_array']['timezone'] = $headers['timezone'];
        if (isset($headers['supplier_cashier_id'])) {
            $sup_user_detail = $this->common_model->get_user_details($headers['supplier_cashier_id']);
            $request['request_array']['users_details']['sup_cashier_id'] = $headers['supplier_cashier_id'];
            $request['request_array']['users_details']['sup_cashier_name'] = $sup_user_detail->fname . ' ' . $sup_user_detail->lname;
        }
        if ($validate_token['user_details']['cashierType'] == "1" && isset($validate_token['user_details']['cashierId'])) {
            $request['request_array']['users_details']['dist_cashier_id'] = $validate_token['user_details']['cashierId'];
            $request['request_array']['users_details']['distributor_id'] = $validate_token['user_details']['distributorId'];
            $request['request_array']['users_details']['cashier_name'] = $validate_token['user_details']['name'];
        } else {
            $request['request_array']['users_details']['dist_cashier_id'] = 0;
            $request['request_array']['users_details']['distributor_id'] = 0;
            $request['request_array']['users_details']['cashier_name'] = "";
        }
        $request['request_array']['cashier_type'] = $validate_token['user_details']['cashierType'];
        if ($validate_token['user_details']['cashierType'] == "1") {
            $request['request_array']['distributor_id'] = $validate_token['user_details']['distributorId'];
        } else if ($validate_token['user_details']['cashierType'] == "2") {
            $request['request_array']['supplier_id'] = $validate_token['user_details']['supplierId'];
        } else if ($validate_token['user_details']['cashierType'] == "3" || $validate_token['user_details']['cashierType'] == 3) {
            $request['request_array']['reseller_id'] = $validate_token['user_details']['resellerId'];
            $request['request_array']['users_details']['dist_cashier_id'] = isset($validate_token['user_details']['resellerCashierId']) ? $validate_token['user_details']['resellerCashierId'] : 0;
            $request['request_array']['users_details']['cashier_name'] = isset($validate_token['user_details']['name']) ? $validate_token['user_details']['name'] : "";
        }
        $MPOS_LOGS['request_params' . date('H:i:s')] = $request['request_array'];
        $request['request_array']['distributor_id'] = $validate_token['user_details']['distributorId'];
        $request['request_array']['cashier_email'] = $validate_token['user_details']['email'];
        $error_msg  = $this->authorization->validate_all_requests($request['request_array'], 'trip_overview');
        $req_array['errors'] = $error_msg;
        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                $api_response = $this->tour_model->trip_overview($request['request_array']);
                if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                    $response = $api_response;
                } else {
                    $response = array(
                        "api_version" => "v1.0",
                        "data" => $api_response
                    );
                }
            } catch (\Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
            $response['error_reference'] = ERROR_REFERENCE_NUMBER;
            $response['errors'] = array_values($req_array['errors']);
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', "trip_overview");
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }

    /* Function to get details of the tour */
    function tour_details(){
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('tour_details');
        $request['request_array'] = $request_array['request'];
        $error_msg  = $this->authorization->validate_all_requests($request['request_array'], 'get_tour_details');
        $req_array['errors'] = $error_msg;
        $request['request_array']['cashier_type'] = isset($request_array['validate_token']['user_details']['cashierType']) ? $request_array['validate_token']['user_details']['cashierType'] : $request_array['validate_token']['user_details']['cashiertype'];
        $request['request_array']['supplier_cashier'] = ($request_array['headers']['supplier_cashier_id'] != NULL) ? $request_array['headers']['supplier_cashier_id'] : "";
        if ($request['request_array']['cashier_type'] == "1") {
            $request['request_array']['distributor_id'] = ($request_array['validate_token']['user_details']['distributorId'] != NULL) ? $request_array['validate_token']['user_details']['distributorId'] : "";
        } else if ($request['request_array']['cashier_type'] == "2") {
            $request['request_array']['supplier_id'] = ($request_array['validate_token']['user_details']['supplierId'] != NULL) ? $request_array['validate_token']['user_details']['supplierId'] : "";
        } else if ($request['request_array']['cashier_type'] == "3") {
            $request['request_array']['supplier_cashier'] = $request_array['validate_token']['user_details']['resellerCashierId'];
            $request['request_array']['reseller_id'] = ($request_array['validate_token']['user_details']['resellerId'] != Null) ? $request_array['validate_token']['user_details']['resellerId'] : "";
        }
        $MPOS_LOGS['req'] = $request['request_array'];

        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                $api_response = $this->tour_model->tour_details($request['request_array']);
                if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                    $response = $api_response;
                } else {
                    $response = array(
                        "api_version" => "v1.0",
                        "data" => $api_response
                    );
                }
            } catch (\Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        }else{
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
            $response['error_reference'] = ERROR_REFERENCE_NUMBER;
            $response['errors'] = array_values($req_array['errors']);
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', "trip_overview");
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }

    // Function to get listing of tickets
    function ticket_listing(){
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('ticket_listing');
        $request['request_array'] = $request_array['request'];
        $error_msg  = $this->authorization->validate_all_requests($request['request_array'], 'ticket_listing');
        $req_array['errors'] = $error_msg;
        $request = ($request == '' || !isset($request) || $request == NULL) ? array() : $request;
        $request['request_array']['cashier_type'] = isset($request_array['validate_token']['user_details']['cashierType']) ? $request_array['validate_token']['user_details']['cashierType'] : $request_array['validate_token']['user_details']['cashiertype'];
        $request['request_array']['supplier_cashier'] = ($request_array['headers']['supplier_cashier_id'] != NULL) ? $request_array['headers']['supplier_cashier_id'] : "";
        if ($request['request_array']['cashier_type'] == "1") {
            $request['request_array']['distributor_id'] = ($request_array['validate_token']['user_details']['distributorId'] != NULL) ? $request_array['validate_token']['user_details']['distributorId'] : "";
        } else if ($request['request_array']['cashier_type'] == "2") {
            $request['request_array']['supplier_id'] = ($request_array['validate_token']['user_details']['supplierId'] != NULL) ? $request_array['validate_token']['user_details']['supplierId'] : "";
        } else if ($request['request_array']['cashier_type'] == "3") {
            $request['request_array']['supplier_cashier'] = $request_array['validate_token']['user_details']['resellerCashierId'];
            $request['request_array']['reseller_id'] = ($request_array['validate_token']['user_details']['resellerId'] != Null) ? $request_array['validate_token']['user_details']['resellerId'] : "";
        }
        $MPOS_LOGS['req'] = $request['request_array'];
        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                $api_response = $this->tour_model->tickets_listing($request['request_array']);
                if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                    $response = $api_response;
                } else {
                    $response = array(
                        "api_version" => "v1.0",
                        "data" => $api_response
                    );
                }
            } catch (Exception $e) {
                $response['message'] = 'An error occurred that is unexpected.';
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = $e->getMessage();
            }
        }else{
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
            $response['error_reference'] = ERROR_REFERENCE_NUMBER;
            $response['errors'] = array_values($req_array['errors']);
        }
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', "ticket_listing");
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }
}
