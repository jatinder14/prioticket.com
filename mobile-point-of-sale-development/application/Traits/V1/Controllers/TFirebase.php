<?php

namespace Prio\Traits\V1\Controllers;

trait TFirebase
{
    
    public function __construct() 
    {
        parent::__construct();
        $this->load->Model('V1/firebase_model');
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if(!isset($headers['jwt_token']) && (!isset($headers['Authorization']) && !isset($headers['authorization']))) {
            $this->validate_request_token($headers);
        }
    }

    /* #region Order Process  Module : Covers all the api's used in cancel process */

    /**
     * @Name call
     * @Purpose Used for Firebase Booking API calls
     * @CreatedOn 1 Nov 2017
     * @Params $api api name
     * @CreatedBy Komal <komalgarg.intersoft@gmail.com>
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
            $request['user_details'] = $validate_token['user_details'];
            $MPOS_LOGS['request_params'. date('H:i:s')] = $request;
            try {
                $hotel_id = isset($_REQUEST['hotel_id']) ? $_REQUEST['hotel_id'] : $validate_token['user_details']['distributorId'];
                if ($api == 'listing' || $api == 'cancel' || $api == 'update' || $api == 'update_note' || $api == 'order_details' || $api == 'cancel_order' || $api == 'order_details_on_cancel' || $api == 'partially_cancel_order' || $api == 'sales_report_test' || $api == 'sales_report_v4' || $api == 'cancel_citycard' || $api == 'sync_third_party_order') {
                    SWITCH ($api) {
                        /* This api will send listing of bookings */
                        CASE 'listing':        
                        $MPOS_LOGS['operation_id'] = 'SHOP';
                        $MPOS_LOGS['external_reference'] = 'overview';
                            $from_date = $request['from_date'];
                            $to_date = $request['to_date'];
                            $cashier_id = $request['cashier_id'];
                            $offset = $request['offset'];
                            $limit = $request['limit'];
                            $search = $request['search'];
                            $user_role = $request['user_role'];
                            $hotel_id = $request['hotel_id'];
                            $log_ref = $request['search'];
                            if (!(isset($request['cashier_id'])) || $request['cashier_id'] == '' || $request['cashier_id'] == NULL) {
                                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Cashier id cannot be blank.");
                            } else {
                                $response = $this->firebase_model->ticket_listing($from_date, $to_date, $cashier_id, $offset, $limit, $search, $user_role, $hotel_id);
                                $response = $this->error_reponse($response, 'listing');
                            }                                        
                            break;

                        //This api is called to get details of any booking
                        CASE 'order_details':
                            $MPOS_LOGS['operation_id'] = 'SHOP';
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->order_details($hotel_id, $request);
                            $response = $this->error_reponse($response, 'order_details');                    
                            break;

                        //thsi api will return the order details of requested pass on scan
                        CASE 'order_details_on_cancel':     
                            $MPOS_LOGS['operation_id'] = 'CANCEL';
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $log_ref = $request['pass_no'];
                            $response = $this->firebase_model->order_details_on_cancel($hotel_id, $request);
                            $response = $this->error_reponse($response, 'order_details_on_cancel');                            
                            break;

                        //This api is used to update reservation details
                        CASE 'update':
                            $MPOS_LOGS['operation_id'] = 'SHOP';
                            $MPOS_LOGS['external_reference'] = 'amend';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->update_order($hotel_id, $request, $headers['timezone']);
                            $response = $this->error_reponse($response, 'update');                                                  
                            break;

                        //this api is used to update note
                        CASE 'update_note':
                            $MPOS_LOGS['operation_id'] = 'SHOP';
                            $MPOS_LOGS['external_reference'] = 'amend';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->update_note($hotel_id, $request);
                            $response = $this->error_reponse($response, 'update_note');
                            break;

                        /* This api will cancel/refund any order for any visitor_group_no, ticket_id, selected_date, from_time, to_time */
                        CASE 'cancel':
                            $MPOS_LOGS['operation_id'] = 'CANCEL';
                            $MPOS_LOGS['external_reference'] = 'cancel';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->cancel($hotel_id, $request);
                            $response = $this->error_reponse($response, 'cancel');               
                            break;

                        /* This api will cancel/refund city card activated for third party orders */
                        CASE 'cancel_citycard':
                            $MPOS_LOGS['operation_id'] = 'CANCEL';
                            $MPOS_LOGS['external_reference'] = 'city_card';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->cancel_citycard($request);
                            $response = $this->error_reponse($response, 'cancel_citycard');                            
                            break;

                        /* This api will cancel/refund complete order for any visitor_group_no, */
                        CASE 'cancel_order':
                            $MPOS_LOGS['operation_id'] = 'CANCEL';
                            $MPOS_LOGS['external_reference'] = 'cancel';
                            $log_ref = $request['order_id'];
                            $response = $this->firebase_model->cancel_order($hotel_id, $request);
                            $response = $this->error_reponse($response, 'cancel_order');
                            break;

                        /* This api will partially cancel/refund any ticket. */
                        CASE 'partially_cancel_order':
                            $MPOS_LOGS['operation_id'] = 'CANCEL';
                            $MPOS_LOGS['external_reference'] = 'cancel';
                            $log_ref = $request['order_id'];
                            $new_validate_response = array();
                            foreach($request['cancel_tickets'] as $cancel_ticket) {
                                $validate_response = $this->authorization->validate_request_params($cancel_ticket, [
                                    'tps_id'                => 'numeric',                            
                                    'cancel_quantity'       => 'numeric',                            
                                    'capacity'              => 'numeric',                                                                                                                                                                                                                                                                                           
                                    'cluster_ids'           => 'numeric'                                                                                                                                                                                                                                                                                             
                                ]);
                                if(!empty($validate_response)) {
                                    array_push($new_validate_response, $validate_response);
                                }
                            }                                                  
                            if(!empty($new_validate_response)) {
                                $response = $this->exception_handler->error_400();
                            } else {
                                $response = $this->firebase_model->partial_cancel_order($hotel_id, $request);
                                $response = $this->error_reponse($response, 'partially_cancel_order');
                            }                            
                            break;

                        CASE 'sales_report_v4':
                            $MPOS_LOGS['operation_id'] = 'SHOP';
                            $MPOS_LOGS['external_reference'] = 'dashboard';
                            $response = $this->firebase_model->sales_report_v4($request);
                            $response = $this->error_reponse($response, 'sales_report_v4');                          
                            break;
                        CASE 'sync_third_party_order' : 
                            $response = $this->firebase_model->sync_third_party_order($request);
                            break;
                        DEFAULT :
                            $response = $this->exception_handler->show_error(0, 'INVALID_API', 'Requested API not valid or misspelled.');
                            break;
                    }
                    
                } else {
                    $response = $this->exception_handler->show_error(0, 'INVALID_PRODUCT', 'The specified product does not exist.');
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
        ini_set('serialize_precision', -1);
        echo json_encode($response, true);
        exit;
    }

     /**
     * @name : error_response()
     * @purpose: common function to return error response
     * @created by : supriya saxena<supriya10.aipl@gmail.com> on 4 March, 2020
     */
    function error_reponse($response = array(), $api_name = '') {
        if ($response['status'] != 1) {
            $status = $response['status'];
            $message = ($response['errorMessage']) ? $response['errorMessage'] : $response['message'];
            $response = array();
            $response = $this->exception_handler->show_error($status, '', $message);
        } else if (!empty($response['errorCode'])) {
            $response = $this->exception_handler->show_error(0, $response['errorCode'], $response['errorMessage']);
        }
        if (empty($response)) {
            if ($api_name == 'listing') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'No Records Found.');
            } else if ($api_name == 'update' || $api_name == 'order_details' || $api_name == 'order_details_on_cancel' || $api_name == 'update_note' || $api_name == 'cancel' || $api_name == 'cancel_citycard' || $api_name == 'cancel_order' || $api_name == 'partially_cancel_order') {
                $response = $this->exception_handler->show_error(0, 'INVALID_BOOKING', 'The specified booking does not exist or is not in a valid state.');
            } else if ($api_name == 'sales_report_v4') {
                $response = $this->exception_handler->show_error(0, 'INVALID', 'No booking exist.');
            }
        }
        if (!empty($response['error_no']) && $response['error_no'] > 0) {
            $response = array();
            $response = $this->exception_handler->error_500();
        }
        return $response;
    }

    /* #endregion Order Process  Module : Covers all the api's used in cancel process */
}
