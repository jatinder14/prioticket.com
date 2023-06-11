<?php

namespace Prio\Traits\V1\Controllers;
use \Prio\helpers\V1\errors_helper;


trait TTrip {
    public function __construct() 
    {
        parent::__construct();
        global $MPOS_LOGS;
        $MPOS_LOGS['API'] = 'Trip';
        $this->load->model('V1/trip_model');
        $this->load->library('Authorization', NULL, 'auth');
        /* Set the starting microtime for all request */
        $this->start_time = microtime(true);
        $this->server = $_SERVER;
        /* Get all data from request body */
        $this->request_body = json_decode(file_get_contents("php://input"), true);
        $this->headers = getallheaders();

        /* get all header of request */
    }

    /**
     * @Purpose     : All APIs for trip status
     */
    public function index()
    {
        global $MPOS_LOGS;
        $response = array();
        $MPOS_LOGS['headers'] = $this->headers;
        if (isset($this->headers['Authorization']) && !empty($this->headers['Authorization'])) {
            $MPOS_LOGS['request_body'] = $this->request_body;
            if (!empty($this->request_body['requestType']) && in_array($this->request_body['requestType'], array('tripStatus', 'updateTrip', 'deletePayment', 'editPayment', 'shiftStatus', 'approveShift', 'closeShift', 'updateShift'))) {
                /* authorizing request by JWT lib */
                $authorize = $this->authorization((string) $this->headers['Authorization']);
                $MPOS_LOGS['authorization'] = $authorize;
                if (isset($authorize['status']) && $authorize['status'] == 1) {
                    /* Execution proceed when get data in request body */
                    if (!empty($this->request_body)) {
                        $validate_request = $this->validate_request(array('params' => $this->request_body, 'api' => 'tripStatus'));
                        $MPOS_LOGS['validated_request'] = $validate_request;
                        /* check for request_type value */
                        if (isset($validate_request['status']) && $validate_request['status'] == 1) {
                            if (!empty($authorize[$this->request_body['requestType']]) && $authorize[$this->request_body['requestType']]) {
                                /* Hitting model function for DB processing */
                                switch ($this->request_body['requestType']) {
                                    case "tripStatus":
                                        $response = $this->trip_model->check_trip_status($this->request_body['data']);
                                        break;
                                    case "updateTrip":
                                        $response = $this->trip_model->update_trip_status($this->request_body['data']);
                                        break;
                                    case "deletePayment":
                                        $response = $this->trip_model->delete_payment($this->request_body['data']);
                                        break;
                                    case "editPayment":
                                        $response = $this->trip_model->edit_payment($this->request_body['data']);
                                        break;
                                    case "shiftStatus":
                                        $response = $this->trip_model->check_shift_status($this->request_body['data'], $authorize);
                                        break;
                                    case "approveShift":
                                        $response = $this->trip_model->update_shift_status($this->request_body['data']);
                                        break;
                                    case "closeShift":
                                        $response = $this->trip_model->update_shift_status($this->request_body['data']);
                                        break;
                                    case "updateShift":
                                        $response = $this->trip_model->update_shift($this->request_body['data']);
                                    break;    
                                    default:
                                        $response = errors_helper::error_specification('PERMISSION_NOT_ALLOWED');                                        
                                        $response['errors'] = 'Invalid request type';
                                }
                            } else {
                                /* preparing error response */
                                $response = errors_helper::error_specification('PERMISSION_NOT_ALLOWED');                                
                                $response['errors'] = 'C01:Token not authorized';
                            }
                        } else {
                            /* assiging validation error in final response */
                            $response = $validate_request;
                        }
                    } else {
                        /* preparing error response */
                        $response = errors_helper::error_specification('INVALID_REQUEST');                        
                        $response['errors'] = 'C03:Request Body must not be empty';
                    }
                } else {
                    /* assigning authorization error to response */
                    $response = $authorize;
                }
            } else {
                header('HTTP/1.0 401 Authentication Failed');
                $response = errors_helper::error_specification('PERMISSION_NOT_ALLOWED');
                $response['errors'] = 'Invalid request type';
            }
        } else {
            $response = errors_helper::error_specification('INVALID_TOKEN');
            $response['errors'] = 'C04:Autherisation must not be empty';            
        }
        $MPOS_LOGS['response'] = $response;
        /* calling common function for managing RESPONSE, LOGS and mail(if get error) */
        $common_response_array = array(
            'request_body' => $this->request_body,
            'response' => $response,
            'start_time' => $this->start_time,
            'log_time' => $this->start_time,
            'api_name' => 'trip_status'
        );
        $MPOS_LOGS['common_response_array'] = $common_response_array;
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $this->request_body['requestType'], 'trip_report');
        /* Hitting function to returning response, creating logs and generating mail(s) , if required  */
        $this->response_processing($common_response_array);
    }

    /**
     * @Name        : authorization()
     * @Purpose     : to authorize the request by JWT library using via permission API (a Google API)
     */
    function authorization($token = '')
    {
        /* declaring variables */
        $response = $bypassAuth = $verification_response = $permission_details = array();
        $i = 0;
        foreach ($this->request_body as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $value_key => $value_value) {
                    if(is_array($value_value) && $this->isAssoc($value_value)) {
                        foreach ($value_value as $subkey => $value) {
                            $keys[$i] = $subkey;
                            $i++;
                        }
                    } else {
                        $keys[$i] = $value_key;
                        $i++;
                    }
                }
            }
            $keys[$i] = $key;
            $i++;
        }
        $allowed_keys = $this->allowed_keys($this->request_body['requestType'], $keys);
        
        if (!empty($allowed_keys)) {
            $response = $allowed_keys;
        } else {
            $bypassAuth['editPayment'] = 1;
            $bypassAuth['deletePayment'] = 1;
            $bypassAuth['tripStatus'] = 1;
            $bypassAuth['status'] = 1;
            $bypassAuth['shiftStatus'] = 1;
            $bypassAuth['approveShift'] = 1;
            $bypassAuth['closeShift'] = 1;
            $bypassAuth['updateTrip'] = 1;
            $bypassAuth['updateShift'] = 1;
            /* checking token through PRIO user permissions */
            if (LOCAL_ENVIRONMENT == 'Local' || LOCAL_ENVIRONMENT == 'local') {
                /* Use link of staging */
                // $statusCurlUrl = "https://accounts-staging.prioticket.dev/v2.0/users/permissions";
                return $bypassAuth;
            } else if (LOCAL_ENVIRONMENT == 'Test' || LOCAL_ENVIRONMENT == 'test') {
                // $statusCurlUrl = "https://accounts-test.prioticket.dev/v2.0/users/permissions";
                return $bypassAuth;
            } else if (LOCAL_ENVIRONMENT == 'Staging2' || LOCAL_ENVIRONMENT == 'Staging' || LOCAL_ENVIRONMENT == 'staging') {
                $statusCurlUrl = "https://accounts-staging.prioticket.dev/v2.0/users/permissions";
            } else if (LOCAL_ENVIRONMENT == 'Sandbox' || LOCAL_ENVIRONMENT == 'sandbox') {
                $statusCurlUrl = "https://accounts-sandbox.prioticket.dev/v2.0/users/permissions";
            } else {
                //OLD URL
                //$statusCurlUrl = "https://accounts.prioticket.com/v2.0/users/permissions";
                // New URL Changed on 21/03/2023
                $statusCurlUrl = "https://reporting-auth.prioticket.com/v2.1/users/permissions";
            }
            $verification_response = $this->hit_curl($statusCurlUrl, array('Authorization:' . $token), '', '', '', 'GET');
            /* checking response_status for permission end-point response */
            if ($verification_response['status'] == '200') {

                $permission_response = (isset($verification_response['response']) && !empty($verification_response['response'])) ? json_decode($verification_response['response'], true) : "";
                /* check for proper response from end-point */
                if (!empty($permission_response)) {

                    $permission_details = isset($permission_response['data']['userPermissions']['permissions']) && !empty($permission_response['data']['userPermissions']['permissions']) ? $permission_response['data']['userPermissions']['permissions'] : '';

                    /* check fro permission details */
                    $permission_flag = array();
                    $permission_flag['approveShift'] = in_array('https://www.prioticketapis.com/auth/reporting/shift.approve', $permission_details) ? 1 : 0;
                    $permission_flag['closeShift'] = in_array('https://www.prioticketapis.com/auth/reporting/shift.close', $permission_details) ? 1 : 0;
                    $permission_flag['shiftStatus'] = (in_array('https://www.prioticketapis.com/auth/reporting/shift.status', $permission_details) || in_array('https://www.prioticketapis.com/auth/reporting/shift', $permission_details) || in_array('https://www.prioticketapis.com/auth/reporting/css/shift', $permission_details)) ? 1 : 0;
                    $permission_flag['tripStatus'] = in_array('https://www.prioticketapis.com/auth/reporting/trip', $permission_details) ? 1 : 0;
                    $permission_flag['updateTrip'] = in_array('https://www.prioticketapis.com/auth/reporting/trip.edit', $permission_details) ? 1 : 0;
                    $permission_flag['editPayment'] = in_array('https://www.prioticketapis.com/auth/reporting/trip.edit', $permission_details) ? 1 : 0;
                    $permission_flag['deletePayment'] = in_array('https://www.prioticketapis.com/auth/reporting/trip.edit', $permission_details) ? 1 : 0;
                    $permission_flag['updateShift'] = in_array('https://www.prioticketapis.com/auth/reporting/shift.edit', $permission_details) ? 1 : 0;
                    if (($this->request_body['requestType'] == "approveShift" && $permission_flag['approveShift']) || ($this->request_body['requestType'] == "closeShift" && $permission_flag['closeShift']) || ($this->request_body['requestType'] == "shiftStatus" && $permission_flag['shiftStatus']) || ($this->request_body['requestType'] == "tripStatus" && $permission_flag['tripStatus']) || ($this->request_body['requestType'] == "updateTrip" && $permission_flag['updateTrip']) || ($this->request_body['requestType'] == "editPayment" && $permission_flag['editPayment']) || ($this->request_body['requestType'] == "deletePayment" && $permission_flag['deletePayment']) || ($this->request_body['requestType'] == "updateShift" && $permission_flag['updateShift'])) {
                        /* If the authenticate successfully then sends a success message */
                        $response = $permission_flag;
                        $response['status'] = 1;
                    } else {
                        /* preparing error response */
                        $response = errors_helper::error_specification('PERMISSION_NOT_ALLOWED');                        
                        $response['errors'] = 'C07:Invalid token provided in header';
                    }
                } else {
                    /* preparing error response */
                    $response = errors_helper::error_specification('PERMISSION_NOT_ALLOWED');                    
                    $response['errors'] = 'C08:User Permissions Are not Approved';
                }
            } else {
                /* preparing error response */
                $response = errors_helper::error_specification('INVALID_TOKEN');                
                $response['errors'] = 'C09:User Permissions Denied.';
            }
        }
        return $response;
    }

    function validate_request($request_params = [])
    {

        /* declaring variables */
        $response = $formatted_request_data = $mandatory_values = $error_message = $date_parse = array();

        /* including values of array residing on array key into root keys of array */
        foreach ($request_params['params'] as $param_key => $parameters_values) {
            if (is_array($parameters_values)) {
                foreach ($parameters_values as $key => $values) {
                    if (is_array($values) && $this->isAssoc($values)) {
                        foreach ($values as $subkey => $value) {
                            $formatted_request_data[$subkey] = $value;
                        }
                    } else {
                        $formatted_request_data[$key] = $values;
                    }
                }
            } else {
                $formatted_request_data[$param_key] = $parameters_values;
            }
        }
        /* for error perspective, PRIO will follow error format as v2.6 supports */
        $formatted_request_data['version'] = 'shifts';

        /* Listing required params of respective APIs */
        if ($request_params['api'] == 'tripStatus') {
            /* preparing mandatory param of request */
            if ($request_params['params']['requestType'] == 'tripStatus') {
                $mandatory_values = array(
                    'parameters' => array('cashierRegisterId'),
                    'data_types' => array(
                        'cashierRegisterId' => 'array',
                    )
                );
            } else if ($request_params['params']['requestType'] == 'deletePayment') {
                $mandatory_values = array(
                    'parameters' => array('payment_id'),
                    'data_types' => array(
                        'payment_id' => 'string',
                    )
                );
            } else if ($request_params['params']['requestType'] == 'editPayment') {
                $mandatory_values = array(
                    'parameters' => array('payment_id', 'payment_amount'),
                    'data_types' => array(
                        'payment_id' => 'string',
                        'payment_amount' => 'float'
                    )
                );
            } else if ($request_params['params']['requestType'] == 'shiftStatus') {
                if(empty($request_params['params']['data']['cashierRegisterId'])){
                    $mandatory_values = array(
                        'parameters' => array('cashierId', 'shiftId', 'distributorId', 'shiftDate'),
                        'data_types' => array(
                            'distributorId' => 'string',
                            'shiftId' => 'string',
                            'cashierId' => 'string',
                            'shiftDate' => 'string',
                        )
                    );
                }
                if($request_params['params']['data']['cashierRegisterId']){
                    $mandatory_values = array(
                        'parameters' => array('cashierRegisterId'),
                        'data_types' => array(
                            'cashierRegisterId' => 'string'
                        )
                    );    
                }
            }else if ($request_params['params']['requestType'] == 'approveShift') {
                if(empty($request_params['params']['data']['cashierRegisterId'])){
                    $mandatory_values = array(
                        'parameters' => array('cashierId', 'distributorId' , 'shiftDate' , 'shiftId','userName', 'userEmail' , 'approveMomentDate'),
                        'data_types' => array(
                            'distributorId' => 'string',
                            'cashierId' => 'string',
                            'shiftId' => 'string',
                            'shiftDate' => 'string',
                            'userName' => 'string',
                            'userEmail' => 'email',
                            'approveMomentDate' => 'date'
                        )
                    );
                }
                if($request_params['params']['data']['cashierRegisterId']){
                    $mandatory_values = array(
                        'parameters' => array('cashierRegisterId', 'userName', 'userEmail' , 'approveMomentDate'),
                        'data_types' => array(
                            'userName' => 'string',
                            'cashierRegisterId' => 'string',
                            'userEmail' => 'email',
                            'approveMomentDate' => 'date'
                        )
                    );
                }
            }else if ($request_params['params']['requestType'] == 'closeShift') {
                if(empty($request_params['params']['data']['cashierRegisterId'])){
                    $mandatory_values = array(
                        'parameters' => array('cashierId', 'distributorId' , 'shiftDate' ,'shiftId' ,'userName', 'userEmail' , 'closeMomentDate'),
                        'data_types' => array(
                            'distributorId' => 'string',
                            'cashierId' => 'string',                            
                            'cashierRegisterId' => 'string',
                            'shiftDate' => 'string',
                            'userName' => 'string',
                            'userEmail' => 'email',
                            'shiftId' => 'string',
                            'closeMomentDate' => 'date'
                        )
                    );
                }
                if($request_params['params']['data']['cashierRegisterId']){
                    $mandatory_values = array(
                        'parameters' => array('cashierRegisterId', 'userName', 'userEmail' , 'closeMomentDate'),
                        'data_types' => array(
                            'cashierRegisterId' => 'string',                            
                            'userName' => 'string',
                            'userEmail' => 'email',
                            'closeMomentDate' => 'date'
                        )
                    );
                }
            }else if ($request_params['params']['requestType'] == 'updateShift') {
                $mandatory_values = array(
                    'parameters' => array('cashier_register_id', 'next_shift_deposit' ,'mannual_closing_cash_balance_note'),
                    'data_types' => array(
                        'cashier_register_id' => 'string',
                        'next_shift_deposit' => 'string',
                        'mannual_closing_cash_balance_note' => 'string'
                    )
                );                
            }
            else {
                /* preparing mandatory param of request */
                $mandatory_values = array(
                    'parameters' => array('cashier_register_id'),
                    'data_types' => array(
                        'cashier_register_id' => 'string',
                        'cashier_id' => 'string',
                        'shift_id' => 'string',
                        'shift_date' => 'date',
                        'unbalance_status' => 'string',
                        'cash_start' => 'string',
                        'cash_revenue' => 'string',
                        'cash_in' => 'string',
                        'cash_in_note' => 'string',
                        'cash_out' => 'string',
                        'cash_out_note' => 'string',
                        'cashier_name' => 'string',
                        'pos_point_id' => 'string',
                        'pos_point_name' => 'string',
                        'opening_time' => 'time',
                        'closing_time' => 'time',
                        'revenue' => 'string',
                        'hotel_name' => 'string',
                        'sale_by_card' => 'string',
                        'cash_end' => 'string',
                        'mannual_closing_cash_balance_note' => 'string',
                        'cash_count' => 'string',
                        'cash_brought_forward' => 'string',
                        'safety_deposit_total_amount' => 'string',
                        'safety_deposit_total_amount_note' => 'string',
                        'other_deposit' => 'string',
                        'other_deposit_note' => 'string',
                        'next_shift_deposit' => 'string',
                        'next_shift_deposit_note' => 'string',
                        'is_logged_out' => 'string',
                        'cash_balance_open_for_next_cashier' => 'string'
                    )
                );
                $date_parse = date_parse($formatted_request_data['shift_date']);
                /* check for zone type value to check whether given date has any timezone or belong to GMT standard */
                if (isset($date_parse['zone_type']) && $date_parse['zone_type'] > 0 && $date_parse['zone'] != 0) {
                    /* adding error value in array */
                    $error_message[] = $key . ' parameter is not in proper date/time zone';
                }
            }
        }

        if (!empty($mandatory_values)) {

            /* excuting authentication fuinction for params */
            $response = $this->auth->check_mandatory_parameters($mandatory_values, $formatted_request_data);
        }

        /* if already get error in product type details for reserve API */
        if (isset($error_message) && !empty($error_message)) {

            /* is get successful status in other than product_type param but get error in product_type details */
            unset($response['status']);

            if (isset($response['errors']) && !empty($response['errors'])) {
                $param_errors = $response['errors'];
                unset($response['errors']);
            }
            $response = errors_helper::error_specification('INVALID_REQUEST', 'shifts');            

            /* merging errors messages fro product type error and remaining params error */
            $error_details = isset($param_errors) && !empty($param_errors) ? array_merge($error_message, $param_errors) : $error_message;

            foreach ($error_details as $values) {

                /* in case of merging error arrays on keys */
                if (is_array($values)) {
                    foreach ($values as $error_value) {
                        $error_msg[] = $error_value;
                    }
                } else {
                    $error_msg[] = $values;
                }
            }
            $response['errors'] = $error_msg;
        }

        return $response;
    }

    /**
     * @param        : Contain request data, response data and several other important details
     * @purpose      : To create a common function for returning response, generating logs other output related stuff.
     */
    function response_processing($params = [])
    {
        $header = isset($params['response']['header']) ? $params['response']['header'] : '200 OK';
        /* generating High alert mail in case of any error generated */
        if ((isset($params['response']['error']) && !empty($params['response']['error'])) || (isset($params['response']['error_code']) && !empty($params['response']['error_code']))) {
            /* converting error message message to standard defined format */
            $params['response']['error'] = isset($params['response']['error']) ? $params['response']['error'] : $params['response']['error_code'];
            $params['response']['error_description'] = isset($params['response']['error_description']) ? $params['response']['error_description'] : $params['response']['error_message'];
            $err = !empty($params['response']['errors']) ? $params['response']['errors'] : array();
            $error_data = !empty($params['response']['errorNo']) ? $params['response']['errorNo'] : $err;

            /* Need to unset previous error response format as we are handling error for v2.2, v2.4, v2.6 from same code. */
            if (isset($params['response']['error_code']) || isset($params['response']['errors'])) {
                unset($params['response']['error_code'], $params['response']['error_message'], $params['response']['errorNo'], $params['response']['errors'], $params['response']['header']);
            }
            $error_data = is_array($error_data) ? $error_data : array($error_data);
            /* case for several error No responses because not all errors has 'errors' params */
            if (isset($error_data)) {

                /* managing errors details be removing errorNo from own API end */
                foreach ($error_data as $values) {

                    $params['response']['errors'][] = (strpos($values, ':') !== false) ? explode(':', $values)[1] : $values;
                }
            }

            header('Content-Type: application/json');
        }

        header('HTTP/1.0 ' . $header);
        /* Printing final response */
        $encoded_response = json_encode($params['response'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $size = (int) strlen($encoded_response);
        header('Content-Length: ' . $size);
        header('Content-Type: application/json');

        echo $encoded_response;
    }

    function allowed_keys($reques_type, $keys = [])
    {
        $response = array();
        if ($reques_type == 'tripStatus') {
            $valid_keys = array('requestType', 'cashierRegisterId', 'data');
        } else if ($reques_type == 'updateTrip') {
            $valid_keys = array('requestType', 'cashier_register_id', 'data');
        } else if ($reques_type == 'editPayment') {
            $valid_keys = array('requestType', 'payment_id', 'payment_amount');
        } else if ($reques_type == 'deletePayment') {
            $valid_keys = array('requestType', 'payment_id');
        } else if ($reques_type == 'updateShift') {
            $valid_keys = array('requestType', 'cashier_register_id', 'data');
        } else if($reques_type == 'approveShift') {
            if(in_array("cashierRegisterId",$keys)){
                $valid_keys = array('requestType', 'cashierRegisterId' , 'data', 'userName', 'userEmail' , 'approveMomentDate');
            }else{
                $valid_keys = array('requestType', 'distributorId', 'cashierId', 'shiftId', 'shiftDate', 'data', 'userName', 'userEmail' , 'approveMomentDate');
            }
        } else if($reques_type == 'closeShift') {
            if(in_array("cashierRegisterId",$keys)){
                $valid_keys = array('requestType', 'cashierRegisterId' , 'data', 'userName', 'userEmail' , 'closeMomentDate');
            }else{
                $valid_keys = array('requestType', 'distributorId', 'cashierId', 'shiftId', 'shiftDate', 'data', 'userName', 'userEmail' , 'closeMomentDate');
            }
        }
        $check = array_diff($valid_keys, $keys);
        if (!empty($check)) {
            foreach ($check as $key) {
                $error_no[] = 'C04.2:The ' . $key . ' field is required';
            }
            $response = errors_helper::error_specification('INVALID_REQUEST');            
            $response['errors'] = $error_no;
        }
        return $response;
    }

    function hit_curl($url = '', $headers = '', $username = '', $password = '', $data = '', $method = 'POST')
    {
        $status = array();
        $ch = curl_init();
        if (!empty($username) && !empty($password)) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method == 'POST' || $method == 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch);
        if (!empty(curl_errno($ch))) {
            /* return error incase of time out  */
            $response['error_message'] = curl_errno($ch);
            $response['error_code'] = curl_error($ch);
        }
        curl_close($ch);
        $status['url'] = $url;
        $status['request'] = json_encode($data);
        $status['status'] = $httpcode['http_code'];
        $status['response'] = $response;
        return $status;
    }

    function isAssoc(array $arr)
    {
        if (array() === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

}
