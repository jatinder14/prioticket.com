<?php 

namespace Prio\Traits\V1\Controllers;

trait TGuest_manifest {

    /**
    *To load commonaly used files in all APIs
    */
    public function __construct() 
    {
        parent::__construct();
        $this->load->Model('V1/Guest_Manifest_Model');
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if (!isset($headers['jwt_token'])) {
              $this->validate_request_token($headers);
        }
        $this->apis = array(
            'update_timeslots',
            'get_timeslots',
            'get_timeslot_bookings',
            'tickets_listing'
        );
    }

    /* #region Guest Manifest Module : Cover all api's used in guest manifest module */

    /**
    * To call API related functions in respective models
    * $api - API name 
    */
    function call($api = '') {
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['API'] = $api;
        $MPOS_LOGS['operation_id'] = 'SHOP';
        $MPOS_LOGS['external_reference'] = 'guest_manifest';
        $request_array = $this->validate_api($api);
        if(empty($request_array['errors'])) {
            $request = isset($request_array['request']) ? $request_array['request'] : array();
            $validate_token = $request_array['validate_token'];
            $MPOS_LOGS['request_params'. date('H:i:s')] = $request;    
            try {
                if (in_array($api, $this->apis)) {
                    SWITCH ($api) {
                        CASE 'update_timeslots':
                            $log_ref = $request['shared_capacity_id'] . "_" . $request['from_time'] . "_" . $request['to_time'];
                            if(isset($request['action'])) {
                                $new_validate_response = array();
                                foreach($request['slots'] as $slots) {
                                    $validate_response = $this->authorization->validate_request_params($slots, [                                                     
                                        'date'                  => 'date',                                                                                                                                              
                                        'from_time'             => 'time',                                                                       
                                        'to_time'               => 'time',                                                                       
                                        'shared_capacity_id'    => 'numeric',                                                                                                                                              
                                        'actual_capacity'       => 'numeric',                                                                                                                                               
                                        'adjustment'            => 'numeric',                                                                                                                                               
                                        'adjustment_type'       => 'numeric'                                                                                                                                                   
                                    ]);  
                                    if(!empty($validate_response)) {
                                        array_push($new_validate_response, $validate_response);
                                    }
                                }
                                if(!empty($new_validate_response)) {
                                    $response = $this->exception_handler->error_400();
                                } else {
                                    $response = $this->Guest_Manifest_Model->update_multiple_timeslots($request);
                                }
                            } else if (isset($request['shared_capacity_id']) && $request['shared_capacity_id'] > 0) {         
                                $response = $this->Guest_Manifest_Model->update_timeslots($request);                          
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'get_timeslots':
                            $log_ref = $request['shared_capacity_id'] . "_" . $request['from_date'] . "_" . $request['to_date'];
                            $request['reseller_id'] = $validate_token['user_details']['resellerId'];
                            if (isset($request['shared_capacity_id']) && $request['shared_capacity_id'] > 0 && $request['from_date'] != "" && $request['to_date'] != "" && $request['from_date'] <= $request['to_date']) {
                                $response = $this->Guest_Manifest_Model->get_timeslots($request);                       
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'get_timeslot_bookings':
                            $log_ref = $request['supplier_id'] . "_" . $request['shared_capacity_id'] . "_" . $request['from_time'] . "_" . $request['to_time'];
                            if (empty($request)) {
                                $response = $this->exception_handler->show_error();
                            } else {
                                $response = $this->Guest_Manifest_Model->get_timeslot_bookings($request);
                                if ($response['status'] != 1) {
                                    $status = $response['status'];
                                    $message = ($response['errorMessage']) ? $response['errorMessage'] : $response['message'];
                                    $response = array();
                                    $response = $this->exception_handler->show_error($status, '', $message);
                                } else if (!empty($response['errorCode'])) {
                                    $response = $this->exception_handler->show_error(0, $response['errorCode'], $response['errorMessage']);
                                }                                                       
                            }
                            break;
                        CASE 'tickets_listing':
                            $request = ($request == '' || !isset($request) || $request == NULL) ? array() : $request;
                            $request['cashier_type'] = isset($request_array['validate_token']['user_details']['cashierType']) ? $request_array['validate_token']['user_details']['cashierType'] : $request_array['validate_token']['user_details']['cashiertype'];
                            $request['supplier_cashier'] = ($request_array['headers']['supplier_cashier_id'] != NULL) ? $request_array['headers']['supplier_cashier_id'] : "";
                            if ($request['cashier_type'] == "1") {
                                $request['distributor_id'] = ($request_array['validate_token']['user_details']['distributorId'] != NULL) ? $request_array['validate_token']['user_details']['distributorId'] : "";
                            } else if ($request['cashier_type'] == "2") {
                                $request['supplier_id'] = ($request_array['validate_token']['user_details']['supplierId'] != NULL) ? $request_array['validate_token']['user_details']['supplierId'] : "";
                            } else if ($request['cashier_type'] == "3") {
                                $request['supplier_cashier'] = $request_array['validate_token']['user_details']['resellerCashierId'];
                                $request['reseller_id'] = ($request_array['validate_token']['user_details']['resellerId'] != Null) ? $request_array['validate_token']['user_details']['resellerId'] : "";
                            }
                            $MPOS_LOGS['req'] = $request;
                            $new_validate_response = array();
                            $response = $this->Guest_Manifest_Model->tickets_listing($request);  
                            break;
                        DEFAULT :
                            $response = $this->exception_handler->show_error(0, 'INVALID_API', 'Requested API not valid or misspelled.');
                            break;
                    }
                } else {
                    $response = $this->exception_handler->show_error(0, 'INVALID_PRODUCT', 'The specified API does not exist.');
                }
            } catch (\Exception $e) {
                $response = $this->exception_handler->error_500();
                $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            }
        } else {
            $MPOS_LOGS['request'] = $request_array['request'];
            $MPOS_LOGS['errors_array'] = $request_array['errors'];
            $response = $this->exception_handler->error_400();
        }
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $log_ref);
        if (!empty($internal_logs)) {
            $internal_logs['API'] = $api;
            $this->apilog_library->write_log($internal_logs, 'internalLog', $log_ref);
        }
        echo json_encode($response);
    }

    /* #region Guest Manifest Module : Cover all api's used in guest manifest module */
}


?>