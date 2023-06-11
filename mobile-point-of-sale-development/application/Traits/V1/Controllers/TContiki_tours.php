<?php 

namespace Prio\Traits\V1\Controllers;

trait  TContiki_tours {

     /**
    *To load commonaly used files in all APIs
    */
    public function __construct() 
    {
        parent::__construct();
        $this->load->Model('V1/Contiki_Tours_Model');
        $this->load->Model('V1/Guests_Model');
        $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        if (!isset($headers['jwt_token'])) {
            $this->validate_request_token($headers);
        }
        
        $this->apis = array(
            'tour_details',
            'trip_overview',
            'confirm',
            'partners_listing',
            'affiliates_listing',
            'affiliate_tickets_listing',
            'affiliate_tickets_amount',
            'affiliate_pay_amount',
            'affiliate_payment_history'
        );
    }
    
    /* #region Contiki tours Module : Cover all the functions used in contiki tours */

    /**
    * To call API related functions in respective models
    * $api - API name 
    */
    function call($api = ''){
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['API'] = $api;
        $request_array = $this->validate_api($api);
        if(empty($request_array['errors'])) {
            $request = !empty($request_array['request']) ?  $request_array['request'] : array();
            $headers = $request_array['headers'];
            $validate_token = $request_array['validate_token'];
            try {
                if (isset($headers['supplier_cashier_id'])) {
                    $sup_user_detail = $this->Contiki_Tours_Model->get_user_details($headers['supplier_cashier_id']);
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
                $log_ref = '';
                $MPOS_LOGS['request_params'. date('H:i:s')] = $request;
                if (in_array($api, $this->apis)) {
                    $MPOS_LOGS['operation_id'] = 'CONTKI';
                    SWITCH ($api) {
                        CASE "tour_details" :
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $log_ref = $request['ticket_id'];     
                            $response = $this->Contiki_Tours_Model->get_tour_details($request);                     
                            break;
                        CASE "trip_overview" :
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $log_ref = $request['ticket_id'];
                            $request['timezone'] = $headers['timezone'];
                            $response = $this->Contiki_Tours_Model->get_trip_overview($request);
                            break;
                        CASE "confirm" :
                            $request['timezone'] = $headers['timezone'];
                            $MPOS_LOGS['external_reference'] = 'pay';
                            $log_ref = implode(",", $request['order_references']);
                            $request['timezone'] = $headers['timezone'];
                            $validate_response = $this->validate_request_params($request['split_payment'], [
                                'card_amount'      => 'decimal',                            
                                'cash_amount'      => 'decimal',                           
                                'direct_amount'    => 'decimal',                           
                                'coupon_amount'    => 'decimal'                                                     
                            ]);
                            if(empty($request['order_references'])) {
                                $response = $this->exception_handler->error_400(0, 'INVALID_REQUEST', "Order References can not be blank.");
                            } else if(!empty($validate_response)) {
                                $response = $this->exception_handler->error_400();
                            } else {
                                $response = $this->Guests_Model->confirm($request);
                            }                        
                            break;
                        CASE 'partners_listing':
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $request = array();
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
                            $response = $this->Contiki_Tours_Model->get_partners_listing($request);
                            break;
                        CASE 'affiliates_listing':
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $response = $this->Contiki_Tours_Model->get_affiliates_listing($request_array['request']);
                        break;
                        CASE 'affiliate_tickets_listing':
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $response = $this->Contiki_Tours_Model->get_affiliate_tickets_listing($request);
                        break;
                        CASE 'affiliate_tickets_amount':
                            $MPOS_LOGS['external_reference'] = 'overview';
                            $response = $this->Contiki_Tours_Model->get_affiliate_tickets_amount($request);
                        break;
                        CASE 'affiliate_pay_amount' :
                            $MPOS_LOGS['external_reference'] = 'pay';
                            $request['distributor_id'] = $validate_token['user_details']['distributorId'];
                            $request['cashier_email'] = $validate_token['user_details']['email'];
                            $response = $this->Contiki_Tours_Model->get_affiliate_pay_amount($request);                        
                            break;
                        CASE 'affiliate_payment_history' ;
                        $MPOS_LOGS['external_reference'] = 'overview';
                        $response = $this->Contiki_Tours_Model->get_affiliate_payment_history($request);                   
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
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $log_ref);
        if (!empty($internal_logs)) {
            $internal_logs['API'] = $api;
            $this->apilog_library->write_log($internal_logs, 'internalLog', $log_ref);
        }
        echo json_encode($response);
    }  

    /* #endregion Contiki tours Module : Cover all the functions used in contiki tours */ 

}


?>
