<?php

/**
 * @Name     : Affiliate Controller
 * @Createdby: Karan Mahna <karanmdev.aipl@gmail.com>
 * @CreatedOn: 27th Jan 2023
 */

namespace Prio\Traits\V2\Controllers;
use \Prio\helpers\V1\mpos_auth_helper;
use \Prio\helpers\V1\common_error_specification_helper;
trait TAffiliate
{
    public $apilog_library , $affiliate_model, $authorization, $common_model, $exception_handler;
    #region Function to set variable under constuction 
    public function __construct()
    {
        parent::__construct();
        $this->load->model('V2/affiliate_model');
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
                if(($api_name == "affiliate_list" || $api_name == "affiliate_tickets_list" || $api_name == "affiliate_ticket_amount" ) && ($_SERVER['REQUEST_METHOD'] == 'GET')){
                    $jsonStr = $_GET;
                }
                else if($api_name == "get_affiliate_pay_amount"){
                    $api_req = "affiliate_make_payment";
                    $jsonStr = file_get_contents("php://input", 'r');
                    if($_SERVER['REQUEST_METHOD'] == 'GET') {
                        $api_req = "affiliate_get_payment_history";
                        $jsonStr = $_GET;
                    }
                }
                /* Get data from user table */
                if(empty($jsonStr)){ 
                    $invalid_request = 1;
                    $MPOS_LOGS['invalid_request'] = true;
                }
                else{
                    $_REQUEST = json_decode($jsonStr, TRUE);
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
        if($invalid_request){
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

    /*
     * @Name get_affiliates_listing
     * @Purpose : to return affiliates list  corresponding to reseller 
    */
    function affiliates()
    {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('affiliate_list');
        $request_array['request']['reseller_id'] = $_GET['reseller_id'];
        $request_array['request']['page'] = $_GET['page'];
        $request_array['request']['items_per_page'] = $_GET['items_per_page'];
        $request_array['request']['affiliate_search_query'] = $_GET['affiliate_search_query'];
        $error_msg  = $this->authorization->validate_all_requests($request_array['request'], 'affiliate_list');
        $req_array['errors'] = $error_msg;
        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                if (isset($request_array['request']['reseller_id']) && $request_array['request']['reseller_id'] != '' && $request_array['request']['reseller_id'] != null) {
                    $api_response = $this->affiliate_model->affiliates($request_array['request']);
                    if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                        $response = $api_response;
                    } else {
                        $response = array(
                            "api_version" => "v1.0",
                            "data" => $api_response
                        );
                    }
                } else {
                    $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                    $response['errors'] = array('The reseller_id field is required.');
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
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request_array['request']['reseller_id']);
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }

    /**
     * @Name affiliate_tickets_listing
     * @Purpose : to return affiliates ticket list  corresponding to affiliate 
     */

    function affiliate_tickets_listing()
    {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('affiliate_tickets_list');
        $request_array['request']['reseller_id'] = $_GET['reseller_id'];
        $request_array['request']['affiliate_id'] = $_GET['affiliate_id'];
        $request_array['request']['page'] = $_GET['page'];
        $request_array['request']['flags'] = $_GET['flags'];
        $request_array['request']['items_per_page'] = $_GET['items_per_page'];
        $request_array['request']['affiliate_search_query'] = $_GET['affiliate_search_query'];
        $error_msg  = $this->authorization->validate_all_requests($request_array['request'], 'affiliate_tickets_list');
        $req_array['errors'] = $error_msg;

        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                if (isset($request_array['request']['affiliate_id']) && $request_array['request']['affiliate_id'] != '' && $request_array['request']['affiliate_id'] != null) {
                    if (isset($request_array['request']['reseller_id']) && $request_array['request']['reseller_id'] != '' && $request_array['request']['reseller_id'] != null) {
                        $api_response = $this->affiliate_model->affiliate_tickets_listing($request_array['request']);
                        if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                            $response = $api_response;
                        } else {
                            $response = array(
                                "api_version" => "v1.0",
                                "data" => $api_response
                            );
                        }
                    } else {
                        $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                        $response['errors'] = array('The reseller_id field is required.');
                    }
                } else {
                    $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                    $response['errors'] = array('The affiliate id field is required.');
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
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request_array['request']['affiliate_id']);
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }

    /**
     * @Name affiliate_ticket_amount
     * @Purpose : to return affiliates pay amount  corresponding to affiliate tickets 
     */
    function get_affiliate_ticket_amount($ticket_id)
    {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('affiliate_ticket_amount');
        $request_array['request']['affiliate_id'] = $_GET['affiliate_id'];
        $request_array['request']['from_date'] = $_GET['from_date'];
        $request_array['request']['to_date'] = $_GET['to_date'];
        $request_array['request']['is_reservation'] = $_GET['is_reservation'];
        $request_array['request']['product_id'] = $ticket_id;
        $error_msg  = $this->authorization->validate_all_requests($request_array['request'], 'get_affiliate_ticket_amount');
        $req_array['errors'] = $error_msg;

        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) {
            try {
                if (isset($request_array['request']['affiliate_id']) && $request_array['request']['affiliate_id'] != '' && $request_array['request']['affiliate_id'] != null) {
                    $api_response = $this->affiliate_model->affiliate_ticket_amount($request_array['request'], $ticket_id);
                    if (isset($api_response['error_reference']) && $api_response['error_reference'] != '') {
                        $response = $api_response;
                    } else {
                        $response = array(
                            "api_version" => "v1.0",
                            "data" => $api_response
                        );
                    }
                } else {
                    $response = common_error_specification_helper::error_specification('INVALID_REQUEST', 'affiliates');
                    $response['errors'] = array('The affiliate id field is required.');
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
        $MPOS_LOGS['API'] = "Affiliate_ticket_amount";
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $ticket_id);
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }

    /**
     * @Name get_affiliates_ticket_listing
     * @Purpose : to return pay amount corresponding to affiliate 
     */
    function get_affiliate_pay_amount()
    {
        global $MPOS_LOGS;
        $request_array = $this->check_api_request('get_affiliate_pay_amount');
        $headers = $request_array['headers'];
        $validate_token = $request_array['validate_token'];
        $request['request_array'] = $request_array['request'];
        if(!empty($request['request_array']['data']['payment']['product_id'])){
            $api_name = 'Affiliate_pay_amount'; 
            $error_msg  = $this->authorization->validate_all_requests($request['request_array'], 'get_affiliate_pay_amount');
            $req_array['errors'] = $error_msg;    
        }else{
            $api_name = 'Affiliate_payment_history'; 
            $request['request_array']['product_id'] = $_GET['product_id'];
            $request['request_array']['affiliate_id'] = $_GET['affiliate_id'];
            $request['request_array']['from_date'] = $_GET['from_date'];
            $request['request_array']['to_date'] = $_GET['to_date'];
            $request['request_array']['items_per_page'] = $_GET['items_per_page'];
            $request['request_array']['page'] = $_GET['page'];
            $request['request_array']['affiliate_search_query'] = $_GET['affiliate_search_query'];
            $error_msg  = $this->authorization->validate_all_requests($request['request_array'], 'get_affiliate_payment_history');
            $req_array['errors'] = $error_msg;    
        }
        if (!empty($request_array['user_detail']) && empty($req_array['errors'])) { 
            try {
                if (isset($headers['supplier_cashier_id'])) {
                    $sup_user_detail = $this->common_model->get_user_details($headers['supplier_cashier_id']);
                    $request['users_details']['sup_cashier_id'] = $headers['supplier_cashier_id'];
                    $request['users_details']['sup_cashier_name'] = $sup_user_detail->fname . ' ' . $sup_user_detail->lname;
                }
                if ($validate_token['user_details']['cashierType'] == "1" && isset($validate_token['user_details']['cashierId'])) {
                    $request['users_details']['dist_cashier_id'] = $validate_token['user_details']['cashierId'];
                    $request['users_details']['distributor_id'] = $validate_token['user_details']['distributorId'];
                    $request['users_details']['cashier_name'] = $validate_token['user_details']['name'];
                } else {
                    $request['users_details']['dist_cashier_id'] = 0;
                    $request['users_details']['distributor_id'] = 0;
                    $request['users_details']['cashier_name'] = "";
                }
                $request['cashier_type'] = $validate_token['user_details']['cashierType'];
                if ($validate_token['user_details']['cashierType'] == "1") {
                    $request['distributor_id'] = $validate_token['user_details']['distributorId'];
                } else if ($validate_token['user_details']['cashierType'] == "2") {
                    $request['supplier_id'] = $validate_token['user_details']['supplierId'];
                } else if ($validate_token['user_details']['cashierType'] == "3" || $validate_token['user_details']['cashierType'] == 3) {
                    $request['reseller_id'] = $validate_token['user_details']['resellerId'];
                    $request['users_details']['dist_cashier_id'] = isset($validate_token['user_details']['resellerCashierId']) ? $validate_token['user_details']['resellerCashierId'] : 0;
                    $request['users_details']['cashier_name'] = isset($validate_token['user_details']['name']) ? $validate_token['user_details']['name'] : "";
                }
                $MPOS_LOGS['request_params' . date('H:i:s')] = $request;
                $request['distributor_id'] = $validate_token['user_details']['distributorId'];
                $request['cashier_email'] = $validate_token['user_details']['email'];
                
                $request['request_array'] = $request_array['request'];
                $request['request_array']['product_id'] = $_GET['product_id'];
                if(empty($request['request_array']['product_id'])){
                    $api_response = $this->affiliate_model->get_affiliate_pay_amount($request);
                }else{
                    $request['request_array']['shift_id'] = $_GET['shift_id'];
                    $request['request_array']['affiliate_id'] = $_GET['affiliate_id'];
                    $request['request_array']['from_date'] = $_GET['from_date'];
                    $request['request_array']['to_date'] = $_GET['to_date'];
                    $request['request_array']['items_per_page'] = $_GET['items_per_page'];
                    $request['request_array']['page'] = $_GET['page'];
                    $request['request_array']['affiliate_search_query'] = $_GET['affiliate_search_query'];
                    $request = !empty($request['request_array']) ?  $request['request_array'] : array();
                    $api_response = $this->affiliate_model->get_affiliate_payment_history($request);
                }
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
        $MPOS_LOGS['API'] = $api_name;
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $api_name);
        if (isset($response['header'])) {
            header('HTTP/1.0 ' . $response['header']);
            unset($response['header']);
        }
        echo json_encode($response);
    }


}
