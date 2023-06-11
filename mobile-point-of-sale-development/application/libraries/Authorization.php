<?php

use Prio\helpers\V1\errors_helper;


/**
 * @description     : To Validate the parameters and Authorize users
 * @author          : Haripriya <haripriya.intersoft@gmail.com>
 * @date            : 2019-05-08
 */
class Authorization
{

    public function __construct()
    {
        $CI = &get_instance();
    }

    /**
     * 
     * @param array $mandatory_parameters
     * @param array $request, handled data types for mandatory parameters : array, string, numeric, boolean
     * @return array
     */
    function check_mandatory_parameters(array $mandatory_parameters, array $request)
    {
        $response = array();
        $error_flag = 0;
        foreach ($mandatory_parameters as $parameters_type => $parameter_array) {
            if ($parameters_type === 'parameters') {
                foreach ($parameter_array as $parameters) {
                    if ((isset($request[$parameters]) && empty($request[$parameters]) && $request[$parameters] != '0') || !isset($request[$parameters])) {
                        $error_flag += 1;
                        $error_no[] = 'H10:' . $parameters . ' is a required parameter';
                        $response['error_parameter'] = $parameters;
                    }
                }
            } else if ($parameters_type === 'data_types') {
                foreach ($parameter_array as $parameter => $parameter_data_type) {
                    if ($parameter_data_type == 'boolean') {
                        $parameter_data_type = 'bool';
                    }
                    if($parameter_data_type == 'email' && !empty($request[$parameter]) && isset($request[$parameter]) && !preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $request[$parameter])){
                        $error_flag += 1;
                        $error_no[] = 'H10:Requested parameter ' . $parameter . ' is not in proper email format';
                    }
                    if (in_array($parameter_data_type, ['numeric', 'array', 'string', 'bool', 'int']) && !empty($request[$parameter])) {
                        $parameter_data_type_check = 'is_' . $parameter_data_type;
                        if (isset($request[$parameter]) && !$parameter_data_type_check($request[$parameter])) {
                            $error_flag += 1;
                            $error_no[] = 'H10:Requested parameter ' . $parameter . ' must be ' . $parameter_data_type;
                        }
                    } else if ($parameter_data_type == 'date' && isset($request[$parameter]) && !$this->ISO8601_validate($request[$parameter]) && !empty($request[$parameter])) {
                        $error_flag += 1;
                        $error_no[] = 'H10:Requested parameter ' . $parameter . ' is not a proper date/time';
                    } else if ($parameter_data_type == 'time' && isset($request[$parameter]) && !$this->time_validate($request[$parameter]) && !empty($request[$parameter])) {
                        /* Validate time between 00:00:00 to 23:59:59 */
                        $error_flag += 1;
                        $error_no[] = 'H10:Requested parameter ' . $parameter . ' has not a proper time format';
                    }
                }
            } else if ($parameters_type === 'values') {
                foreach ($parameter_array as $parameter => $parameter_value) {
                    if (isset($request[$parameter]) && is_array($parameter_value) && !in_array($request[$parameter], $parameter_value)) {
                        $error_flag += 1;
                        $error_no[] = 'H11:Requested parameter ' . $parameter . ' does not belongs to expected values ' . json_encode($parameter_value);
                    } else if (!is_array($parameter_value) && $request[$parameter] != $parameter_value) {
                        $error_flag += 1;
                        $error_no[] = 'H11:Requested parameter ' . $parameter . ' does not belongs to expected values ' . $parameter_value;
                    }
                }
            } else if ($parameters_type === 'set_parameter') {
                foreach ($parameter_array as $required_parameters) {
                    if (!isset($request[$required_parameters])) {
                        $error_flag += 1;
                        $error_no[] = 'H12:' . $required_parameters . ' is a required parameter';
                        $response['error_parameter'] = $required_parameters;
                    }
                }
            } else if ($parameters_type === 'not_values') {
                foreach ($parameter_array as $parameter => $parameter_value) {
                    if (isset($request[$parameter]) && is_array($parameter_value) && in_array($request[$parameter], $parameter_value)) {
                        $error_flag += 1;
                        $error_no[] = 'H11:Requested parameter ' . $parameter . ' does not belongs to expected values ' . json_encode($parameter_value);
                    } else if (!is_array($parameter_value) && $request[$parameter] == $parameter_value) {
                        $error_flag += 1;
                        $error_no[] = 'H11:Requested parameter ' . $parameter . ' does not belongs to expected values ' . $parameter_value;
                    }
                }
            }
        }
        /* RETURN ERROR */
        if ($error_flag > 0) {
            header('HTTP/1.0 400 Bad Request');
            $validate_response = errors_helper::error_specification('INVALID_REQUEST');
            $validate_response['errors'] = $error_no;
            $response = $validate_response;
        } else {
            $response['status'] = 1;
        }
        return $response;
    }

    /**
     * @Name ISO8601_validate
     * @Purpose To validate the datetime in ISO8601 format
     * @Params $dateTime - DateTime in ISO8601 format
     * @CreatedOn 25 Aug 2016
     */
    function ISO8601_validate($dateTime = '')
    {
        return (preg_match('/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/', $dateTime) > 0);
    }

    /**
     * @Name time_validate
     * @Purpose To validate the time format
     */
    function time_validate($time = '')
    {
        /* Validate time between 00:00:00 to 23:59:59 */
        return (preg_match("/^([01]?\d|2[0-3]):([0-5]\d):([0-5]\d)$/", $time) > 0);
    }

    function recursion($request){
        if (!is_array($request)) { 
            return FALSE; 
        } 
        $result = array(); 
        foreach ($request as $key => $value) { 
            if (is_array($value)) { 
              $result = array_merge($result, $this->recursion($value)); 
            } 
            else { 
              $result[$key] = $value; 
            } 
        } 
        return $result;         
    }

       /**
     * @name : validate_all_requests()
     * @purpose : to validate  request param(s) for  type and mandatory
     * @created by : supriya saxena<supriya10.aipl@gmail.com> on 30 july, 2021
     */
    function validate_all_requests($request, $api) {
        $validate_response  = array();
        // Validate mandatory fields for MPOS api's
        if($api == "signOut") {
            $mandatory_values = array(
                // 'parameters' => array('shift_id') ,
                'data_types' => array(
                    'start_time'         => 'date_time', 
                    'end_time'           => 'date_time'
                ),               
            );
        }

        // Validate mandatory field for SCANNING API's 
        if($api == "confirm_postpaid_tickets") {
            $mandatory_values = array(
                'parameters' => array('pass_no') ,
                'data_types' => array(
                    'from_time'         => 'time', 
                    'to_time'           => 'time'
                ),               
            );
        }

        // Validate mandatory fields for FIREBASE API'S
        if($api == "update_note") {
            $mandatory_values = array(
                'data_types' => array( 
                    'booking_email'     => 'email'        
                ),               
            );
        }
        if($api == "update") {
            $mandatory_values = array(
                'data_types' => array(
                    'from_time'         => 'time',
                    'to_time'           => 'time',        
                ),               
            );
        }
        if($api == "sales_report_v4") {
            $mandatory_values = array(
                'data_types' => array(
                    'date'         => 'date'  
                ),               
            );
        }

        if($api == "tickets_listing") {
            $mandatory_values = array(
                'data_types' => array(
                    'flag_ids'         => 'numeric'  
                ),               
            );
        }

        // Validate mandatory fields for GUEST MANIFEST API'S
        if($api == "get_timeslots") {
            $mandatory_values = array(
                'parameters' => array('shared_capacity_id', 'to_date', 'from_date') ,
                'data_types' => array(
                    'start_time'         => 'time',
                    'end_time'           => 'time',          
                ),               
            );
        }
        if($api == "get_timeslot_bookings") {
            $mandatory_values = array(
                'parameters' => array('shared_capacity_id', 'supplier_id', 'from_time', 'to_time') ,
                'data_types' => array(
                    'from_time'         => 'time',
                    'to_time'           => 'time', 
                ),               
            );
        }


        // Validate mandatory fields for CONTIKI API'S
        if($api == "tour_details") {
            $mandatory_values = array(
                'parameters' => array('ticket_id','start_time', 'end_time', 'supplier_id', 'shift_id') ,
                'data_types' => array(
                    'start_time'         => 'datetime',
                    'end_time'           => 'datetime',          
                ),               
            );
        }
        if ($api == 'trip_overview') {
            $mandatory_values = array(
                'parameters' => array('product_id', 'shift_id','product_supplier_id', 'current_time')
            );
        }
        if($api == 'affiliates_listing') {
            $mandatory_values = array(
                'parameters' => array('reseller_id', 'page', 'item_per_page')                
            );
        }
        if($api == 'affiliate_tickets_listing') {
            $mandatory_values = array(
                'parameters' => array('affiliate_id','page', 'item_per_page')                
            );
        }     
        if($api == 'affiliate_tickets_amount' || $api == 'affiliate_pay_amount') {
            $mandatory_values = array(
                'parameters' => array('affiliate_id','ticket_id', 'start_date', 'end_date', 'start_time', 'end_time') ,
                'data_types' => array(
                    'start_date'         => 'date',
                    'end_date'           => 'date',
                    'start_time'         => 'time',
                    'end_time'           => 'time'            
                ),               
            );
        }   
        if($api == "affiliate_payment_history") {
            $mandatory_values = array(
                'parameters' => array('affiliate_id','ticket_id', 'start_date', 'end_date') ,
                'data_types' => array(
                    'start_date'         => 'date',
                    'end_date'           => 'date'           
                ),               
            );
        }

        if($api == 'affiliate_list') {
            $mandatory_values = array(
                'parameters' => array('reseller_id')
            );
        }
        if($api == 'affiliate_tickets_list') {
            $mandatory_values = array(
                'parameters' => array('affiliate_id', 'reseller_id' )
            );
        }     
        if($api == 'get_affiliate_ticket_amount') {
            $mandatory_values = array(
                'parameters' => array('affiliate_id','product_id', 'from_date', 'to_date', 'is_reservation') ,
                'data_types' => array(
                    'is_reservation'    => 'numeric',
                ),               
            );
        }   
        if($api == 'get_affiliate_pay_amount') {
            $request = $this->recursion($request);
            $mandatory_values = array(
                'parameters' => array('affiliate_id','product_id', 'payment_from_date_time', 'payment_to_date_time', 'payment_amount', 'payment_type') ,
                'data_types' => array(
                    'payment_from_date_time' => 'date_time',
                    'is_reservation'    => 'numeric',
                    'payment_to_date_time' => 'date_time',
                    'payment_amount' => 'decimal',
                    'payment_type' => 'numeric'
                ),               
            );
        }          
        if($api == "get_affiliate_payment_history") {
            $mandatory_values = array(
                'parameters' => array('affiliate_id','product_id', 'from_date', 'to_date') ,
                'data_types' => array(
                    'items_per_page'     => 'numeric'
                ),               
            );
        }
        if($api == "get_tour_details") {
            $mandatory_values = array(
                'parameters' => array('product_id','availability_from_date_time', 'availability_to_date_time', 'product_supplier_id', 'shift_id') ,
                'data_types' => array(   
                ),               
            );
        }
        if($api == "shift_report"){
            $mandatory_values = array(
                'parameters' => array('cashier_shift_id', 'supplier_cashier_id', 'cashier_supplier_id', 'cashier_shift_date'),
                'data_types' => array(
                ),
            );
        }
        if (!empty($mandatory_values)) { 
            /* excuting authentication fuinction for params */
            $error_response = $this->check_mandatory_params($mandatory_values, $request);
        }
        if(!empty($error_response)) {
            $validate_response = $error_response;
        }
        //  check for valid fields values
        $param_response = $this->validate_request_params($request, [
            'cashier_id'                    => 'numeric',                   'from_date'                         => 'date_time',                            
            'to_date'                       => 'date_time',                      'limit'                             => 'numeric',                                                                       
            'offset'                        => 'numeric',                   'hotel_id'                          => 'numeric',                                                                                                                                                                                                                                                                                                                
            'order_id'                      => 'numeric',                   'selected_date'                     => 'date',          
            'ticket_id'                     => 'numeric',                   'cashier_type'                      => 'numeric',                                                                                                                                          
            'booking_date_time'             => 'date_time',                 'main_ticket'                       => 'numeric',                                                                                                                                                             
            'channel_type'                  => 'numeric',                   'payment_type'                      => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             
            'booking_id'                    => 'alphanumeric',              'shift_id'                          => 'numeric',   
            'cluster_ids'                   => 'numeric',                   'refunded_by'                       => 'numeric', 
            'upsell_order'                  => 'numeric',                   'action_performed'                  => 'numeric', 
            'start_amount'                  => 'decimal',                   'tps_ids'                           => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     
            'own_supplier_id'               => 'numeric',                   'supplier_cashier_id'               => 'numeric',                                                                                                                                                                                                                                                                                                                                                       
            'reseller_id'                   => 'numeric',                   'shared_capacity_id'                => 'numeric',                                                                                                                                                            
            'actual_capacity'               => 'numeric',                   'adjustment'                        => 'numeric',                                                                                                                                               
            'adjustment_type'               => 'numeric',                   'active'                            => 'numeric',                                                                                                                                                                
            'supplier_id'                   => 'numeric',                   'prepaid_ticket_id'                 => 'numeric',  
            'current_time'                  => 'numeric',                   'is_combi_ticket'                   => 'numeric', 
            'allow_activation'              => 'numeric',                   'user_id'                           => 'numeric',                                                                                           
            'tps_id'                        => 'numeric',                   'activated_by_cashier_id'           => 'numeric',                          
            'booking_ids'                   => 'numeric' ,                  'pos_point_id'                      => 'numeric',                                                                                                                       
            'visitor_group_no'              => 'numeric',                   'product_id'                        => 'numeric',                   
            'museum_id'                     => 'numeric',                   'channel_id'                        => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                   
            'main_ticket_id'                => 'numeric',                   'shift_id'                         => 'numeric',             
            'ticket_ids'                    => 'numeric',                   'order_references'                 => 'numeric',                  
            'amount'                        => 'decimal',                   'affiliate_id'                      => 'numeric', 
            'is_scan_countdown'             => 'numeric',                   'disributor_cashier_name'           => 'string',
            'is_prepaid'                    => 'numeric',                   'upsell_ticket_ids'                 => 'numeric',
            'is_voucher'                    => 'numeric',                   'is_reservation'                    => 'numeric',                         
            'prepaid_ticket_ids'            => 'numeric',                   'is_edited'                         => 'numeric',
            'own_capacity_id'               => 'numeric',                   'capacity'                          => 'numeric', 
            'ticket_price'                  => 'numeric',                   'save_amount'                       => 'decimal',                               
            'disributor_cashier_id'         => 'numeric',                   'is_endshift'                       => 'numeric',
            'sale_by_cash'                  => 'decimal',                   'refunded_cash_amount'              => 'decimal',
            'ticket_type_label'             => 'string',                    'title'                             => 'alphanumeric',              
            'pass_no'                       => 'alphanumeric',              'upsell_left_time'                  => 'alphanumeric'
            ]);

        if(!empty($param_response)) {
            $validate_response = $param_response;
        }
        return $validate_response;
    }

    /**
     * @name : validate_request_params()
     * @purpose : to validate  request param(s)
     * @created by : supriya saxena<supriya10.aipl@gmail.com> on 4 nov, 2020
     */
    function validate_request_params($request, $validate_array) {
        $response = array();
        if( !empty($request) && !empty($validate_array)) {
            foreach($request as $param => $value) {
                if( $value != '' || !empty($value)) {
                    if($validate_array[$param] == 'numeric') {
                        if(is_array($value) && !empty($value)) {
                            foreach($value as $key => $val) {
                                if (!preg_match('/^[0-9]+$/', $val)) {
                                    $errors[] = $param. " at key " .$key. " is not in valid format";
                                }
                            }
                        } else {
                            $explode_array = explode(",", $value);
                            foreach($explode_array as $key =>  $value) {
                                if (!preg_match('/^[0-9]+$/', $value)) {
                                    $errors[] = $param. " is not in valid format";
                                } 
                            }                   
                        }                 
                    }
                    if($validate_array[$param] == 'alphanumeric') {
                        if(is_array($value)) {
                            foreach($value as $key => $val) {
                                if(!preg_match('/^[a-zA-Z0-9_\s&\'-]*$/', $val)) {
                                    $errors[] = $param. " at key  " .$key. " is not in valid format";
                                }
                            }
                        } else {
                            if(!preg_match('/^[a-zA-Z0-9_\s&\'-]*$/', $value)) {
                                $errors[] = $param. " is not in valid format";
                            }
                        }
                    }
                    if($validate_array[$param] == 'datetime') {
                        if(!(DateTime::createFromFormat('Y-m-d H:i', $value))) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'date') {
                        if(!(DateTime::createFromFormat('Y-m-d', $value))) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'time') {
                        if(!(DateTime::createFromFormat('H:i', $value))) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'date_time') {
                        if(!preg_match('/^[+T0-9:\s-]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'alpha_numeric') {
                        if(!preg_match('/^[a-zA-Z0-9-_.@\s#]*$/', $value)) {
                        $errors[] = $param. "is not in valid format";
                        }    
                    }
                    if($validate_array[$param] == 'email') {
                        if(!preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'string') {
                        if(!preg_match('/^[a-zA-Z\s]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'decimal') {
                        if(!preg_match('/^[0-9.]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'numeric_val') {
                        if(!preg_match('/^[0-9-]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'int_val') {
                        if(!preg_match('/^[a-zA-Z0-9-_]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'check_num') {
                        if(!preg_match('/^[0-9+]*$/',$value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    if($validate_array[$param] == 'spcl_alpha_numeric') {
                        if(is_array($value)) {
                            foreach($value as $key => $val) {
                                if (!preg_match('/^[a-zA-Z0-9:\/.\s_-]*$/', $val)) {
                                    $errors[] = $param. " at key " .$key. " is not in valid format";
                                }
                            }
                        } else if(!preg_match('/^[a-zA-Z0-9:\/.\s_-]*$/', $value)) {
                            $errors[] = $param. " is not in valid format";
                        }
                    }
                    // $res = $this->validate_type($value, $param, $validate_array[$param]); 
                    // if(!empty($res)) {
                    //     array_push($response, $res);
                    // }
                }                
            } 
        }
        if (!empty($errors)) {
            $response = $errors;
        } 
        return $response;      
    }
    /**
     * 
     * @param array $mandatory_parameters
     * @return array
     */
    function check_mandatory_params(array $mandatory_parameters, array $request) {
        $response = array();
        foreach ($mandatory_parameters as $parameters_type => $parameter_array) {
            if ($parameters_type === 'parameters') {
                foreach ($parameter_array as $parameters) {
                    if ((isset($request[$parameters]) && empty($request[$parameters]) && $request[$parameters] != '0') || !isset($request[$parameters])) {
                        $errors[] =  $parameters . ' is a required parameter';
                    }
                }
            } else if($parameters_type == 'data_types') {
                $param_response = $this->validate_request_params($request, $parameter_array);
                if(!empty($param_response)) {
                    $errors = $param_response;
                }
            }
        }
        /* RETURN ERROR */
        if (!empty($errors)) {
            $response = $errors;
        } 
        return $response;
    }

    
}
