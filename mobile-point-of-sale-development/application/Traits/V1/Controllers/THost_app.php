<?php 

namespace Prio\Traits\V1\Controllers;

trait THost_app {

    /**
    *To load commonaly used files in all APIs
    */
    public function __construct() 
    {
        parent::__construct();
        $this->load->Model('V1/Host_App_Model');
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if (!isset($headers['jwt_token'])) {
              $this->validate_request_token($headers);
        }
        
        $this->apis = array(
            'search',
            'activate',
            'remove_pass',
            'guest_details',
            'update_guest_details'
        );
    }
    /* #region Host App Module : Covers all the api's used in host app */
    
    /**
    * To call API related functions in respective models
    * $api - API name 
    */
    function call($api = '') {
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['API'] = $api;
        $request_array = $this->validate_api($api);
        if(empty($request_array['errors'])) {
            $request = $request_array['request'];
            $headers = $request_array['headers'];
            $validate_token = $request_array['validate_token'];
            try {
                if (isset($headers['supplier_cashier_id'])) {
                    $sup_user_detail = $this->Host_App_Model->get_user_details($headers['supplier_cashier_id']);
                    $request['users_details']['sup_cashier_id'] = $headers['supplier_cashier_id'];
                    $request['users_details']['sup_cashier_name'] = $sup_user_detail->fname . ' ' . $sup_user_detail->lname;
                }
                if ($validate_token['user_details']['cashierType'] == "1" && isset($validate_token['user_details']['cashierId'])) {
                    $request['users_details']['dist_cashier_id'] = $validate_token['user_details']['cashierId'];
                    $request['users_details']['distributor_id'] = $validate_token['user_details']['distributorId'];
                } else {
                    $request['users_details']['dist_cashier_id'] = 0;
                    $request['users_details']['distributor_id'] = 0;
                }
                $request['app_flavour'] = isset($headers['app_flavour']) ? strtoupper($headers['app_flavour']) : "WAT";
                $MPOS_LOGS['app_flavour_in_req'] = $request['app_flavour'];
                if (in_array($api, $this->apis)) {
                    $MPOS_LOGS['operation_id'] = 'WAT';
                    SWITCH ($api) {
                        CASE 'search':
                            $request['hidden_tickets'] = $sup_user_detail->hide_tickets;
                            $log_ref = $request['supplier_id'] . '_' . $request['search_value'];
                            $spcl_ref = $request['show_orders'];
                            if (isset($request['search_key']) && $request['search_value'] != '' && isset($request['supplier_id']) && $request['supplier_id'] != '') {
                                $response = $this->Host_App_Model->search($request);
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'activate':
                            $log_ref = $request['bleep_pass_no'];
                            $spcl_ref = $request['allow_activation'];
                            if (($headers['app_flavour'] == "cntk" || $headers['app_flavour'] == "CNTK" || (isset($request['bleep_pass_no']) && $request['bleep_pass_no'] != '')) && $request['supplier_id'] != '') {
                                $new_validate_response = array();
                                if(!empty($request['add_to_pass'])) {
                                    foreach($request['add_to_pass'] as $add_to_pass_arr) {
                                        $validate_response = $this->authorization->validate_request_params($add_to_pass_arr, [                                                     
                                            'ticket_id'             => 'numeric',                                                                                                                                              
                                            'visitor_group_no'      => 'numeric',                                                                       
                                            'prepaid_ticket_id'     => 'numeric'                                                                                                                                                                                                                                                                                                                                                                
                                        ]);
                                        if(!empty($validate_response)) {
                                            array_push($new_validate_response, $validate_response);
                                        }
                                    }
                                }
                                if(!empty($request['guest_details'])) {
                                    $validate_response = $this->authorization->validate_request_params($request['guest_details'], [                                                                                                                           
                                        'dob'                               => 'date',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              
                                        'activated_by_cashier_id'           => 'numeric'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            
                                    ]);
                                    if(!empty($validate_response)) {
                                        array_push($new_validate_response, $validate_response);
                                    }
                                }
                                if(!empty($new_validate_response)) {
                                    $response = $this->exception_handler->error_400();
                                } else {
                                    $response = $this->Host_App_Model->activate($request);
                                }
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'remove_pass':
                            $log_ref = $request['bleep_pass_no'];
                            $spcl_ref = $request['deactivate_pass'];
                            if (isset($request['bleep_pass_no']) && $request['bleep_pass_no'] != '') {
                                $response = $this->Host_App_Model->remove_pass($request);                            
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'guest_details':
                            $log_ref = $request['guest_details']['email_id'];
                            if (isset($request['guest_details']['email_id']) && $request['guest_details']['email_id'] != '') {
                                $new_validate_response = array();
                                if(!empty($request['guest_details'])) {
                                    $validate_response = $this->authorization->validate_request_params($request['guest_details'], [                                                                                                                                                                                                                                                                                                                                                                                                                    
                                        'activated_by_cashier_id'           => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              
                                        'related_booking_ids'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                                    ]);
                                    if(!empty($validate_response)) {
                                        array_push($new_validate_response, $validate_response);
                                    }
                                }
                                if(!empty($new_validate_response)) {
                                    $response = $this->exception_handler->error_400();
                                } else {
                                    $response = $this->Host_App_Model->guest_details($request);
                                }
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        CASE 'update_guest_details':
                            $log_ref = $request['updated_guest_details']['email_id'];
                            if (isset($request['guest_details']['email_id']) && $request['guest_details']['email_id'] != '') {
                                $new_validate_response = array();
                                if(!empty($request['guest_details'])) {
                                    $validate_response = $this->authorization->validate_request_params($request['guest_details'], [                                                     
                                        'name'                              => 'string',                                                                                                                                              
                                        'email_id'                          => 'email',                                                                       
                                        'dob'                               => 'date',                                                                                                                                                                                                                                                                                                                                                                
                                        'passport_no'                       => 'alpha_numeric',                                                                                                                                                                                                                                                                                                                                                                
                                        'activated_by_cashier_id'           => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                                        'activated_by_cashier_name'         => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'gender'                            => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'nationality'                       => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'related_booking_ids'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                                    ]);
                                    if(!empty($validate_response)) {
                                        array_push($new_validate_response, $validate_response);
                                    }
                                }
                                $validate_response = $this->validate_request_params($request, [                                                     
                                    'supplier_id'                       => ['numeric'],                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            
                                ]);
                                if(!empty($validate_response)) {
                                    array_push($new_validate_response, $validate_response);
                                }
                                if(!empty($request['updated_guest_details'])) {
                                    $validate_response = $this->validate_request_params($request['guest_details'], [                                                     
                                        'name'                              => 'string',                                                                                                                                              
                                        'email_id'                          => 'email',                                                                       
                                        'dob'                               => 'date',                                                                                                                                                                                                                                                                                                                                                                
                                        'passport_no'                       => 'alpha_numeric',                                                                                                                                                                                                                                                                                                                                                                
                                        'activated_by_cashier_id'           => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                                        'activated_by_cashier_name'         => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'gender'                            => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'nationality'                       => 'string',                                                                                                                                                                                                                                                                                                                                                                
                                        'related_booking_ids'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                                
                                    ]);
                                    if(!empty($validate_response)) {
                                        array_push($new_validate_response, $validate_response);
                                    }
                                }
                                if(!empty($new_validate_response)) {
                                    $response = $this->exception_handler->error_400();
                                } else {
                                    $response = $this->Host_App_Model->update_guest_details($request);
                                }                            
                            } else {
                                $response = $this->exception_handler->error_400();
                            }
                            break;
                        DEFAULT :
                            $response = $this->exception_handler->show_error(0, 'INVALID_API', 'Requested API is not valid or misspelled.');
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
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $log_ref, $spcl_ref);
        if (!empty($internal_logs)) {
            $internal_logs['API'] = $api;
            $this->apilog_library->write_log($internal_logs, 'internalLog', $log_ref);
        }
        echo json_encode($response);
    }    

    /* #endregion Host App Module : Covers all the api's used in host app */

}

?>