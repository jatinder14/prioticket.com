<?php 

namespace Prio\Traits\V1\Models;

trait TCommon_model {
    var $base_url;
    var $arena_const_values;

    function __construct() {
        parent::__construct();       
        $this->load->model('V1/hotel_model');
        $this->load->model('V1/museum_model');
        $this->load->model('V1/api_model');
	    $this->target_city_mode = 1;
        $this->log_dir = "common/";
    }
                
    function getGenericServiceValues($service_id = '') {
        if ($service_id != '') {
            $this->primarydb->db->select('*');
            $this->primarydb->db->from('services');
            $this->primarydb->db->where('id', $service_id);
            $query = $this->primarydb->db->get();
        }
        if ($query->num_rows() > 0) {
            return $query->row();
        } else {
            return false;
        }
    }
    
    function getSingleFieldValueFromTable($field, $tbl, $where = '', $is_secondary_db = "0") {
        $db = $this->primarydb->db;
        $db->select($field);
        $db->from($tbl);
        if ($where != '') {
            $db->where($where);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {
            $res = $query->row();
            return $res->$field;
        } else {
            return false;
        }
    }
    
    function seldealeventimage($tbl, $field, $item_id, $used = '') {
        $check_checkout_condition = 0; 
        if($tbl == 'hotel_ticket_overview') {
            if(!strstr($item_id, '-'))
            {
                $check_checkout_condition = 1;
            }
        }
        $this->primarydb->db->Select('*');
        $this->primarydb->db->from($tbl);
        $main_pass = str_replace("http://qu.mu/", '', $item_id);
        if(isset($main_pass) && $main_pass != ''){
            $where = '('.$field.' = "'.$item_id.'" or '.$field.' = "'.$main_pass.'")';
        } else {
            $where = $field.' = "'.$item_id.'"';
        }
        $this->primarydb->db->where($where);
        if($check_checkout_condition == 1) {
            $this->primarydb->db->where('hotel_checkout_status', '0');
        }
        if($used == 1) {
            $this->primarydb->db->where('is_used', 0);
        }
        $query = $this->primarydb->db->get();
        if ($query->num_rows() > 0) {
            return $query->row();
        } else {
            return false;
        }
    }
    
    /*
     * 	Used to get array from object
     */
    function object_to_array($data) {
        if (is_array($data) || is_object($data)) {
            $result = array();
            foreach ($data as $key => $value) {
                $result[$key] = $this->object_to_array($value);
            }
            return $result;
        }
        return $data;
    }
            
    /*
     * @name: convert_time_into_user_timezone
     * @purpose: To convert values into GMT from user's timezone and to user's timezone from GMT
     * @working: It first checks action to performed. If action is to display values then timezone will remain as it is.
     * If action is to save values into database then timezone value will be changed to -ve. After that time is updated according to timezone
     * If time is less then 00:00 then time will be upadated to -ve else time will be returned as it is.
     * @params: $time: Time to be saved/displayed.
     * $timezone: Current timezone of the user
     * $action: 0 = If value is to be fetched from database
     * 1: If value is to be saved into database
     * @return: Time as per timezone will be returned
     * @created by: Hemant Goel <hemant.intersoft@gmail.com> on August 13, 2015
     */
    function convert_time_into_user_timezone($time = '', $timezone = '', $action = '', $type = '') {
        /* When get the values from database*/
        if ($action == '1') { /* When save values into database */
            $timezone = -($timezone);
        }
        if (strstr($time, '-')) {
            $time = str_replace('-', '', $time);
        }

        $time = strtotime($time) + ($timezone * 60 * 60);
        if ($time < strtotime('00:00:00')) {
            $time = '-' . date('H:i', $time);
        } else {
            $time = date('H:i', $time);
        }
        return $time;
    }
    
    function getISO3CurrencySymbolFromCodId($cod_id) {
        $this->primarydb->db->Select('currencyCode');
        $this->primarydb->db->from('country_currency_codes ccc');
        $this->primarydb->db->join('qr_codes qc', 'ccc.id = qc.currency', 'left');
        $this->primarydb->db->where('qc.cod_id', $cod_id);
        $query = $this->primarydb->db->get();
        $currency = '';
        if ($query->num_rows() > 0) {
            $result = $query->row();
            $currency = $result->currencyCode;
        }
        return $currency;
    }
          
    function companyName($codId) {
        $this->primarydb->db->Select('*');
        $this->primarydb->db->from('qr_codes');
        $this->primarydb->db->where('cod_id', $codId);
        $query = $this->primarydb->db->get();
        return $query->row();
    }
    
    function auth($ref = "LATEST", $amount = '', $merchantRef = '', $shopperRef = '', $merchantAcountCode = '', $currencyCode = '', $parentPassNo = '', $shopperStatement = '') {
        $oREC   = new \CI_AdyenRecurringPayment($debug = FALSE);
        $amount = ($amount * 100);
        /* do a recurring Payment - get Status or errorMessage */     
        $oREC->startSOAP("Payment");
            if($merchantAcountCode == 'Prioticket') {
                $oREC->startSOAP("Payment");            
            } else {
                $oREC->tassen_startSOAP("Payment");
            }
        $oREC->authorise($ref, $amount, $merchantRef, $shopperRef, $merchantAcountCode, $currencyCode, $parentPassNo, $shopperStatement);
        return $oREC->response->paymentResult;
    }
    
    /*
     * Send payment deduction request to adyen
     */
    function capturePayment($merchantAccount = '', $modificationAmount = '', $pspReference = '', $currency = '') {
        $oREC = new \CI_AdyenRecurringPayment($debug = true);
        $modificationAmount = $modificationAmount * 100; /* Multiplied by 100 because adyen receives amount 3 as .03 */

        $oREC->startSOAP("Payment");
        $oREC->capture($merchantAccount, $modificationAmount, $pspReference, $currency);
        return $oREC->response->captureResult->response;
    }
    
    function addFixedVariableTaxToAmount($ticket_amount, $fixed_amount, $variable_amount, $tax_value) {
        $tax = ($ticket_amount * ($variable_amount / 100));
        $tax = round($tax, 2);
        $variablewithtax = $tax + (($tax * $tax_value) / 100);
        $fixedwithtax    = $fixed_amount + (($fixed_amount * $tax_value) / 100);

        $ticketAmt = $ticket_amount + round($variablewithtax, 2) + round($fixedwithtax, 2);
        $data['ticketAmt']       = $ticketAmt;
        $data['variable_amount'] = $tax;
        $data['fixed_amount']    = $fixed_amount;
        return $data;
    }
    
    /* 
     * It fetches barcodes based on museum and ticket id.
     * It fetches the barcode based on passed ticket type Child or Adult 
     * 1 for Child, 2 for Adult
     * $lmt : speicifies the number of passes to be fetched
     */
    function getbarcodePass($hotel_id, $ticket_type, $limit = 10, $museum_id = 0, $ticket_id = 0, $selected_date = '') 
    {        
        $batch_id = (isset($selected_date) && !empty($selected_date)) ? explode('-',$selected_date)[0] : explode('-',date("Y-m-d"))[0];    
        $this->primarydb->db->order_by('rand()');
        $this->primarydb->db->select('barcode, code_end_timestamp, adult_vs_child, ticket_id');
        $this->primarydb->db->from('assigned_barcodes');
        if($museum_id > 0) {
            $this->primarydb->db->where('museum_id', $museum_id);
        }
        if($ticket_id > 0) {
            $this->primarydb->db->where('ticket_id', $ticket_id);
        }
        if(!empty($batch_id) && is_numeric($batch_id) && 1 == 0) {          /* 1==1 if for local testing,  will removed when code will be on live and  batch_id comdition properly implemented. */
            $this->primarydb->db->where('batch_id', $batch_id);
        }
        $this->primarydb->db->where('adult_vs_child', $ticket_type);
        $this->primarydb->db->where('is_assigned', '0');
        $this->primarydb->db->where('is_expire', '0');
        /*Firebase Updations*/
            $this->primarydb->db->where('(machine_id = "" OR machine_id is NULL)', null, false);
            $this->primarydb->db->limit($limit);
            $res = $this->primarydb->db->get();   
        if ($res->num_rows() > 0 && $res->num_rows() == $limit) {            
            return $res->result();
        } else {
            return false;
        }
    }
                
    /*
     * @name:    
     * @working: Fetches qrcodes (only for ripley museum) from purchased_qrcodes based on type passed (like adult or child)
     * @created by: Hemant Goel <hemant.intersoft@gmail.com> on August 3, 2016
     */
    function get_purchased_qrcodes($ticket_type, $lmt = 10, $museum_id = '', $ticket_id = '') {
        $this->primarydb->db->select('qr_code');
        $this->primarydb->db->from('purchased_qrcodes');
        $this->primarydb->db->where('is_assigned', '0');
        $this->primarydb->db->where('adult_vs_child', $ticket_type);
        $this->primarydb->db->where('museum_id', $museum_id);
        $this->primarydb->db->where('ticket_id', $ticket_id);
        /*Firebase Updations*/
        $this->primarydb->db->where('machine_id', '');
        $this->primarydb->db->limit($lmt);
        $res = $this->primarydb->db->get();
        if ($res->num_rows() > 0) {
            return $res->result();
        } else {
            return false;
        }
    }
    
    function getPrepaidTax() {
        $this->primarydb->db->select("ccf.*, st.tax_value");
        $this->primarydb->db->from("credit_card_fees ccf");
        $this->primarydb->db->join("store_taxes st", "st.id=ccf.card_tax_id");
        $this->primarydb->db->where("ccf.id", "6");
        $query = $this->primarydb->db->get();
        if ($query->num_rows() > 0) {
            return $query->row();
        } else {
            return false;
        }
    }    
    /*
     * to sort ticket types array in order
     * called from apis used in order processing and scan APIs of MPOS
     */
    function sort_ticket_types($data){
        if(!empty($data)){
            $sorted_types = array();
            $types = array(
                'elderly'   => 1,
                'senior'    => 2,
                'adult'     => 3,
                'youth'     => 4,
                'child'     => 5,
                'infant'    => 6,
                'baby'      => 7,
                'handicapt' => 8,
                'student'   => 9,
                'military'  => 10,
                'group'     => 11,
                'family'    => 12,
                'resident'  => 13
            );
            
            foreach($data as $key => $val) {
                $val['actual_key'] = $key;
                $sorted_types[$types[strtolower($val['ticket_type_label'])]][] = $val;
            }
            ksort($sorted_types);
            foreach($sorted_types as $key => $values){
                foreach($values as $val){
                    $actual_key = $val['actual_key'];
                    unset($val['actual_key']);
                    $final[$actual_key] = $val;
                }
            }
        } else {
            $final = array();
        }
        return $final;
    }
        
    /**function to generate random 16 digit passno
    *created by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 june 2019
    */
    function get_sixteen_digit_pass_no(){
        return rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9);
    }
    
    function update_third_party_redumption($passNo, $ticketId, $scan_from = "", $date_time = "", $other_data = array()) {
        global $graylogs;
        $this->api_model->CSSNotifyLog('CSS Notify REQ from venue app=>', array($passNo,$ticketId, $scan_from, $date_time), 0);
        $this->load->library("apilog_library", NULL, 'apilog');
        $error_reference_number = (isset($other_data['error_reference_no']) && !empty($other_data['error_reference_no'])) ? $other_data['error_reference_no'] : ERROR_REFERENCE_NUMBER;
        if (strstr($passNo, 'http')) {
            $redumption_pass = substr($passNo, -PASSLENGTH);
        } else {
            $redumption_pass = $passNo;
        }
        /* Check passno from Notify_to_third_party table */
        $verify_third_party_redemption = $this->primarydb->db->select("passNo")->get_where("notify_to_third_parties", array("passNo"=> $redumption_pass, 'status_code' => 200))->row();
        
        if(!empty($redumption_pass) && empty($verify_third_party_redemption) && empty($verify_third_party_redemption->passNo)) {
            $this->apilog->setlog($this->log_dir, 'tp_redemption_call.php', "MSH Request", json_encode(array('queue_redemption_pass'=>$redumption_pass)), 'CSS_Redemption');
            $graylogs[] = array(
                'log_dir' => $this->log_dir, 
                'log_filename' => 'tp_redemption_call.php', 
                'title' => 'MSH Request', 
                'data' => json_encode(array('queue_redemption_pass'=>$redumption_pass)), 
                'api_name' => 'CSS_Redemption',
                'error_reference_no' => $error_reference_number
            );
            
            $redumption_where['passNo'] = $redumption_pass;
            $redumtion_orders = $this->primarydb->db->select("visitor_group_no, third_party_type, second_party_type, passNo, second_party_passNo, without_elo_reference_no, hotel_id, channel_type, third_party_response_data")
                                ->get_where('prepaid_tickets', $redumption_where)->row();
            if ($redumtion_orders && $redumtion_orders->second_party_type == 5 || $redumtion_orders->third_party_type == 5) {
                /* Get the againt from PT table in case of CSS Own APi*/
                $css_agent = "";
                if($redumtion_orders->channel_type == 9 || $redumtion_orders->channel_type == 13) {                   
                   $third_party_response_data =  !empty($redumtion_orders->third_party_response_data) ? json_decode($redumtion_orders->third_party_response_data, TRUE) : array();
                   if(isset($third_party_response_data) && !empty($third_party_response_data)) {
                       $second_party_param_agent = '';
                       
                       if(isset($third_party_response_data['second_party_params']['agent']) && !empty($third_party_response_data['second_party_params']['agent'])) {
                          $second_party_param_agent =  $third_party_response_data['second_party_params']['agent'];
                       }
                        $css_agent = isset($third_party_response_data['third_party_params']['agent']) && !empty($third_party_response_data['third_party_params']['agent']) ?  $third_party_response_data['third_party_params']['agent']  : $second_party_param_agent;
                       
                   }
                } 
                /*GET AGENT NAME FROM QR_CODES*/
                if(!empty($css_agent)) {
                    $csw_agent['CSW Agent'] = $css_agent;
                } else {
                    /*GET AGENT NAME FROM QR_CODES*/
                    $csw_agent_detail = $this->primarydb->db->select("third_party_parameters")->get_where("qr_codes", array("cod_id"=> $redumtion_orders->hotel_id))->row();
                    $csw_agent =  !empty($csw_agent_detail) && !empty($csw_agent_detail->third_party_parameters) ? json_decode($csw_agent_detail->third_party_parameters, TRUE) : array();
                }
                
                /*Set default agent 'webposcex' for 2667 hotel_id*/
                if($redumtion_orders->hotel_id == "2667" && (!empty($csw_agent['CSW Agent']) && $csw_agent['CSW Agent'] == 'city-sightseeing.com')) {
                    $csw_agent['CSW Agent'] = "webposcex";
                }
                
                /*GET THIRD PARTY PARAMETERS/SECOND PARTY PARAMETERS FROM MEC */
                $redumtion_ticket = $this->primarydb->db->get_where('modeventcontent', array('mec_id' => $ticketId))->row();
                if ($redumtion_ticket->second_party_id == 5) {
                    $decoded_third_party_parameters = json_decode($redumtion_ticket->second_party_parameters, TRUE);
                } else {
                    $decoded_third_party_parameters = json_decode($redumtion_ticket->third_party_parameters, true);
                }
                if ($redumtion_orders->second_party_type == 5) {
                    $redumption_pass = $redumtion_orders->second_party_passNo;
                } else {
                    $redumption_pass = $redumtion_orders->passNo;
                }
                $this->api_model->CSSNotifyLog('CSS Notify request venue app=>', array( json_encode($redumtion_orders),json_encode($redumtion_ticket) ), 0);
                $ticket_redemption_data=  array(
                    "barcode" => $redumption_pass, 
                    "supplier_id" => $decoded_third_party_parameters['supplier_id'],
                    "date_time" => $date_time,
                    "agent" => !empty($csw_agent['CSW Agent']) ? trim($csw_agent['CSW Agent']) : "",
                    "original_internal_id" => !empty($redumtion_orders->without_elo_reference_no) ? $redumtion_orders->without_elo_reference_no : $redumtion_orders->visitor_group_no,
                    "scan_api" => $scan_from,
                    "error_reference_no" => $error_reference_number
                );
                $graylogs[] = array(
                    'log_dir' => $this->log_dir, 
                    'log_filename' => 'tp_redemption_call.php', 
                    'title' => 'TP MSH Request', 
                    'data' => json_encode(array('redemption_pass'=>$ticket_redemption_data)), 
                    'api_name' => 'CSS_Redemption',
                    'error_reference_no' => $error_reference_number
                );
                $this->apilog->setlog($this->log_dir, 'tp_redemption_call.php', "TP MSH Request", json_encode(array('redemption_pass'=>$ticket_redemption_data)), 'CSS_Redemption');
                /* if agent is empty no call to third */
                if(!empty($csw_agent['CSW Agent'])){
                    $this->load->library('ThirdParty');
                    $tp_init_array = array(
                                'error_reference_no' => $error_reference_number);
                    $date_time = !empty($date_time) ? $date_time : gmdate("Y-m-d H:i:s");
                    $third_party_obj = new \ThirdParty($decoded_third_party_parameters['third_party_code'],'',$tp_init_array);
                    $third_party_result = $third_party_obj->call('ticket_redemption', $ticket_redemption_data);
                    $graylogs[] = array(
                        'log_dir' => $this->log_dir, 
                        'log_filename' => 'tp_redemption_call.php', 
                        'title' => 'TP MSH Response', 
                        'data' => json_encode(array('tp_redemption_response'=>$third_party_result,'init_param' => $tp_init_array)), 
                        'api_name' => 'CSS_Redemption',
                        'error_reference_no' => $error_reference_number
                    );
                    $this->apilog->setlog($this->log_dir, 'tp_redemption_call.php', "TP MSH Response", json_encode(array('tp_redemption_response'=>$third_party_result,'init_param' => $tp_init_array)), 'CSS_Redemption');
                    $this->api_model->CSSNotifyLog('CSS Notify RES from venue app=>', array( json_encode($third_party_result) ), 0);
                } else {
                    /* email in case of agent is empty */
                    $mail_data = array();
                    $mail_data['api'] = 'Redemption Process';
                    $mail_data['response'] = json_encode(array('Redemption Details'=>$ticket_redemption_data));
                    $mail_data['subject'] = LOCAL_ENVIRONMENT.' Agent name empty.';
                    $mail_data['emailc'] = NOTIFICATION_EMAILS;
                    $this->apilog->setlog($this->log_dir, 'tp_redemption_duplicate_call.php', "mail data", json_encode(array('mail data'=>$mail_data)), 'Mail Data');
                }
            }
        } else {
            $graylogs[] = array(
                'log_dir' => $this->log_dir, 
                'log_filename' => 'tp_redemption_duplicate_call.php', 
                'title' => 'TP MSH Request', 
                'data' => json_encode(array('redemption_pass'=>$redumption_pass)), 
                'api_name' => 'CSS_Redemption',
                'error_reference_no' => $error_reference_number
            );
            $this->apilog->setlog($this->log_dir, 'tp_redemption_duplicate_call.php', "TP MSH Request", json_encode(array('redemption_pass'=>$redumption_pass)), 'CSS_Redemption');
            
            
        }
    }
    
    /**
     * @Name get_distributors_of_reseller
     * @Purpose : to return distributor IDs corresponding to a reseller 
     * @return array of distributors
     * @param : $reseller_id
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 Aug 2020
     */ 
    function get_distributors_of_reseller ($reseller_id = '') {
        global $MPOS_LOGS;
        if($reseller_id != '') {
            $distributors_data = $this->find(
                'qr_codes',
                array(
                    'select' => 'cod_id',
                    'where' => 'reseller_id = "'.$reseller_id.'" and cashier_type = "1"'),
                "array"
            );
            $MPOS_LOGS['get_distributors_of_reseller'] = $this->primarydb->db->last_query();
            return array_unique(array_column($distributors_data, 'cod_id'));
        } else {
            return array();
        }
    }
    
    /**
     * @Name get_supplier_of_reseller
     * @Purpose : to return supplier IDs corresponding to a reseller 
     * @return array of suppliers
     * @param : $reseller_id
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 Aug 2020
     */ 
    function get_supplier_of_reseller ($reseller_id = '') {
        global $MPOS_LOGS;
        if($reseller_id != '') {
            $suppliers_data = $this->find(
                'qr_codes',
                array(
                    'select' => 'cod_id',
                    'where' => 'reseller_id = "'.$reseller_id.'" and cashier_type = "2"'),
                "array"
            );
            $MPOS_LOGS['get_supplier_of_reseller'] = $this->primarydb->db->last_query();
            return array_unique(array_column($suppliers_data, 'cod_id'));
        } else {
            return array();
        }
    }
    
    /**
     * @Name get_own_supplier_of_dist
     * @Purpose : to return supplier ID corresponding to a distributor 
     * @return array of suppliers
     * @param : $distributor_id
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 25 Aug 2020
     */
    function get_own_supplier_of_dist ($distributor_id = '') {
        global $MPOS_LOGS;
        if($distributor_id != '') {
            $suppliers_data = $this->find(
                    'qr_codes', array(
                'select' => 'own_supplier_id',
                'where' => 'cod_id = "' . $distributor_id . '"'), "array"
            );
            $MPOS_LOGS['suppliers_query'] = $this->primarydb->db->last_query();
            return array_unique(array_column($suppliers_data, 'own_supplier_id'));
        } else {
            return array();
        }
    }
    
    /**
     * @Name return_pass_not_valid
     * @Purpose : to return not valid passes from Mpos scan
     * @return array with information
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 02 Feb 2020
     */
    function return_pass_not_valid ($status = 0, $is_ticket_listing = 0, $add_to_pass = 2, $meassgae = '', $expired = 0, $expired_on = '') {
        global $MPOS_LOGS;
        $pt_response['status'] = $status;
        $pt_response['data'] = array();
        $pt_response['message'] = ($meassgae != NULL && $meassgae != '') ? $meassgae : 'Pass not valid';
        $pt_response['is_ticket_listing'] = $is_ticket_listing;
        $pt_response['add_to_pass'] = ($add_to_pass == 2) ? 0 : $add_to_pass;
        if($expired && $expired_on != '') {
            $pt_response['message'] = 'Pass has been expired on '. $expired_on;
            $MPOS_LOGS['pass_expired'] = $pt_response;
        } else {
            $MPOS_LOGS['pass_not_valid'] = $pt_response;
        }
        return $pt_response;
    }
    
}

?>