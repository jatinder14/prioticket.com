<?php
error_reporting(E_ALL);

ini_set("display_errors", 0);

# ThirdParty: PHP wrapper class for thirdParty APIs
# Author: Karan Bhardwaj
define("API_LIBRARY_PATH", FCPATH . 'application/libraries/ThirdPartyLibraries/');

class ThirdParty {
    /* ---------------Class data members used for CS APIs------------------- */

    protected $vendorLibrary = "";
    protected $venderObj = NULL;
    const LIBRARY_PATH = API_LIBRARY_PATH;
    protected $library_version = "v1";
    // General settings
    protected $base_url = "";
    protected $vendor = '';
    protected $private_key = "";
    protected $dummy_value = "";
    public $response = "";
    public $ticket_id = "";
    public $booking_status = "SUCCESS";
    public $passes = array();
    public $all_passes = array();
    public $GT_API_KEYS = array();
    public $entering_date_time;
    public $third_party_params = array();
    public $ticket_type_details = array();
    protected $gt_account = array();
    protected $tp_native_info = array();
    protected $dummy_customer_info = array();
    protected $gt_ticket_types = array();
    public $OTA_LEVEL_RESERVATION_EXPRIED_CHECK = array();

    /**
     * __construct
     */
    public function __construct($vendor = '', $ticket_id = '', $init_params = array()) {
        $this->vendor = $vendor;
        $this->entering_date_time = gmdate('Y-m-d H:i:s:').gettimeofday()["usec"];
        $init_params['ticket_id'] = $this->ticket_id = $ticket_id;
        $this->dummy_customer_info = json_decode(DUMMY_CUSTOMER_VALUE, true);
        $this->dummy_value = 1;
        $this->OTA_LEVEL_RESERVATION_EXPRIED_CHECK = json_decode(OTA_LEVEL_RESERVATION_EXPRIED_CHECK, true);
        $CI = & get_instance();
        $CI->load->library('Apilog_library');
        $this->apilog = new Apilog_library();
        /* set constant for save processing time in graylogs */
        $this->THIRDPARTY_PROCESSING_TIME = THIRDPARTY_PROCESSING_TIME;
        if ($vendor == "GT") {
            $this->tp_native_info = json_decode(TP_NATIVE_INFO, true);
            if(empty($init_params['ticket_params'])){
                $this->third_party_params = $this->get_ticket_details($ticket_id); 
            }else{
               $this->third_party_params = $init_params['ticket_params'];
            }
            if(empty($init_params['ticket_type_details']) && (!isset($init_params['api']) || (isset($init_params['api']) && $init_params['api'] != 'availability'))){
                $this->ticket_type_details = $this->get_ticket_type_details($ticket_id);
            }else if (!isset($init_params['api']) || (isset($init_params['api']) && $init_params['api'] != 'availability')){
                //conver first letter of every key to capital
                $this->ticket_type_details = array_combine(array_map('ucfirst', array_keys($init_params['ticket_type_details'])), array_values($init_params['ticket_type_details']));
            }
            $this->gt_account = $this->tp_native_info['gt'][$this->third_party_params['third_party_account']];
        }
        if ($vendor != "GT") {
            if (empty($init_params['ticket_params'])) {
                $init_params['ticket_params'] = $this->get_ticket_details($ticket_id);
            }
            $this->vendorLibrary = strtolower($this->vendor);
            /* LOAD VENDOR LIBRARY */
            $this->venderObj = $this->load_library($this->vendorLibrary, $init_params);
        }      

    }
        
    /* Load the class and return the object if successfully loaded, otherwise return Null */
    private function load_library($library = "", $constructor_params = array()) {
        if (!empty($library)) {
            include_once self::LIBRARY_PATH . (strtolower($library)) . "_" . $this->library_version . ".php";
            /* Initialize object */
            if (class_exists(strtolower($library) . "_" . $this->library_version, FALSE)) {
                $library = $library. "_" . $this->library_version;
                return new $library($constructor_params);
            }else if (class_exists($library, FALSE)) {
                return new $library($constructor_params);
            }
        }
        return NULL;
    }

    /**
     * @Name call
     * @Purpose Call the specified method of the Loaded Class if that method exists.  
     * Return : response from the called method, False otherwise.
     */
    public function call($method_name = "", $params = array()) {
        global $graylogs;
        if (!is_null($this->venderObj)) {
            /* CHECK IF THIS CLASS METHOD EXISTS */
            if (method_exists($this->venderObj, $method_name)) {
                $this->response = $this->venderObj->{$method_name}($params);
                return $this->response;
            }
        }
        return FALSE;
    }
    public function call_v2($method_name = "", $params = array()) {
        global $graylogs;
        if (!is_null($this->venderObj)) {
            /* CHECK IF THIS CLASS METHOD EXISTS */
            if (method_exists($this->venderObj, $method_name)) {
                $this->response = $this->venderObj->{$method_name}('10:20','','P602-001','2019-05-15','');
                return $this->response;
            }
        }
        return FALSE;
    }

    private function get_ticket_details($ticket_id) {
        if ($ticket_id) {
            $this->apilog->setlog('thirdparty_lib/', 'thirdparty_log.php', 'ticket_detail_query : ', json_encode(array('ticket' => $ticket_id)), 'load_library');
            $CI = & get_instance();
            $db = $CI->db;
            $db->select('third_party_parameters');
            $db->from("modeventcontent");
            $db->where("mec_id", $ticket_id);
            $query = $db->get();
            if ($query->num_rows) {
                $tp_params = $query->row_array();
                return (array) json_decode($tp_params['third_party_parameters'], TRUE);
            }
        }
        return array();
    }
    
    private function get_ticket_type_details($ticket_id) {
        if ($ticket_id) {
            $this->apilog->setlog('thirdparty_lib/', 'thirdparty_log.php', 'ticket_type_query : ', json_encode(array('ticket' => $ticket_id)), 'load_library');
            $CI = & get_instance();
            $db = $CI->db;
            $db->select('ticket_type_label,third_party_ticket_type_id');
            $db->from("ticketpriceschedule");
            $db->where("ticket_id", $ticket_id);
            $db->where("deleted", 0);
            $query = $db->get();
            if ($query->num_rows) {
                $tp_params = $query->result_array();
                foreach($tp_params as $tps_data){
                    $response[$tps_data['ticket_type_label']] = $tps_data['third_party_ticket_type_id'];
                }
                return $response;
            }
        }
        return array();
    }

    /**
     * @Name timeslots
     * @Purpose to get the timeslots from requested Vendor
     */
    public function timeslots($date_range = array(), $timezone = '1', $shared_capacity_id = 0) {
        $this->response = $this->{'get_' . strtolower($this->vendor) . '_availability'}($date_range, $timezone, $shared_capacity_id);
        return $this->response;
    }

    /**
     * @Name create_booking
     * @Purpose to create_booking on selected Vendor
     */
    public function create_booking($params = array()) {
        $this->response = $this->{'create_booking_for_' . strtolower($this->vendor)}($params);
        return $this->response;
    }

    /**
     * @Name cancel_booking
     * @Purpose to cancel_booking on selected Vendor
     */
    public function cancel_booking() {
        $this->response = $this->{'cancel_booking_for_' . strtolower($this->vendor)}();
        return $this->response;
    }

    /**
     * @Name get_gt_availability
     * @Purpose to get availability from GT API for GT product
     */
    public function get_gt_availability($date_range = array(), $timezone = '1', $shared_capacity_id = 0) {
        global $graylogs;
        
        /* CHeck the processing time */
        $processing_times = 0;
        if($this->THIRDPARTY_PROCESSING_TIME == 1) {
            $start_time = microtime(true);
        }
        
        $tp_lib_data = array();
	$graylog_api_name = 'Get gt availability';
        $url = GT_AVAILABILITY_API_URL;
        $sha256_array = array(
            'apiKey' => $this->gt_account['api_key'],
            'endDate' => $date_range['endDate'],
            'environment' => GT_ENVIRONMENT,
            'showTicketsAllocation' => '1',
            'startDate' => $date_range['startDate'],
            'userId' => $this->third_party_params['user_id']
        );
        $data = array(
            'apiKey' => $this->gt_account['api_key'],
            'endDate' => $date_range['endDate'],
            'environment' => GT_ENVIRONMENT,
            'startDate' => $date_range['startDate'],
            'userId' => $this->third_party_params['user_id'],
            'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->gt_account['secret_key'])),
            'showTicketsAllocation' => '1'
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
        $response = curl_exec($ch);
        $curl_info = curl_getinfo($ch);        
        $response = $tp_response = json_decode($response, true);
        
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        if ($curl_errno > 0 && $curl_errno == 28) {
            $response['error'] = $curl_error;
        }
                
        $tp_request = array();
        $tp_request['api_case'] = "get_availability";
        $tp_request['url'] = $url;
        $tp_request['method'] = "POST";
        $tp_request['query_string'] = '';
        $tp_request['post_data'] = $data;
        $tp_request['third_party_code'] = 'gt';
        /* Save logs in file and graylog server */
        $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_availability_log.php', 'GT_availability : ', json_encode(array('GT response' => $response)), $graylog_api_name);
        $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_availability_log.php', 'title' => 'GT_availability : ', 'data' => json_encode(array('GT response' => $response)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => ((isset($response['success']) && $response['success'] == 'true') ? 200 : 400));
        if (isset($response['success']) && $response['success'] == 'true') {
            $standard_hours = array();
            $i = 0;
            $hours = array();
            if (!empty($response['availability'])) {
                if ($response['availabilityType'] == "timeslots") {
                    foreach ($response['availability'] as $date => $timeslots) {
                        if(!empty($timeslots)){
                            $hours[$i]['date'] = $date;
                            foreach ($timeslots as $timeslot => $is_active) {
                                $multi_timeslot = explode('-', $timeslot);
                                $timeslot = trim($multi_timeslot[0]);
                                $gmt_timeslot = date('H:i', strtotime($date . ' ' . $timeslot . ' -' . $timezone . ' hours'));
                                $from_time = date('H:i', strtotime($date . ' ' . $gmt_timeslot . ' -15 minutes'));
                                $to_time = $gmt_timeslot;
                                $timeslot_id = $this->get_timeslot_id($date, date('H:i', strtotime($date . ' ' . $timeslot . ' -15 minutes')), $timeslot, $shared_capacity_id);
                                $hours[$i]['timeslots'][] = array(
                                    'actual_from_time' => $from_time,
                                    'actual_to_time' => $to_time,
                                    'timeslot_type' => 'specific',
                                    'from_time' => date('H:i', strtotime($date . ' ' . $timeslot . ' -15 minutes')),
                                    'to_time' => $timeslot,
                                    'total_capacity' => ($is_active) ? $is_active : 0,
                                    'adjustment_type' => 0,
                                    'adjustment' => 0,
                                    'is_active' => 1, //($is_active) ? 1 : 0,
                                    'is_active_slot' => 1,
                                    'vacancies' => ($is_active) ? $is_active : 0,
                                    'blocked' => 0,
                                    'bookings' => 0,
                                    'gt_active' => ($is_active) ? 1 : 0,
                                    'capacity_type' => $is_active ? $is_active : 0,
                                    'timeslot_id' => $timeslot_id,
                                    'shared_capacity_id' => $shared_capacity_id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'modified_at' => date('Y-m-d H:i:s'),
                                );
                            }
                        } else {
                            $hours[$i]['date'] = $date;
                            $timeslot_id = $this->get_timeslot_id($date, '00:00', '00:00', $shared_capacity_id);
                            $hours[$i]['timeslots'][] = array(
                                'actual_from_time' => "00:00",
                                'actual_to_time' => "00:00",
                                'timeslot_type' => '0',
                                'from_time' => "00:00",
                                'to_time' => "00:00",
                                'total_capacity' => 0,
                                'adjustment_type' => 0,
                                'adjustment' => 0,
                                'is_active' => 0,
                                'is_active_slot' => 0,
                                'vacancies' => 0,
                                'blocked' => 0,
                                'bookings' => 0,
                                'gt_active' => 0,
                                'capacity_type' => 0,
                                'timeslot_id' => $timeslot_id,
                                'shared_capacity_id' => $shared_capacity_id,
                                'created_at' => date('Y-m-d H:i:s'),
                                'modified_at' => date('Y-m-d H:i:s'),
                            );
                        }
                        $i++;
                    }
                } else if ($response['availabilityType'] == "date") {
                    if ($date_range['startDate'] == $date_range['endDate']) {
                        $dates[0] = $date_range['startDate'];
                    } else {
                        $dates_range = $this->getDatesFromRange($date_range['startDate'], $date_range['endDate']);
                        $dates = explode(',', $dates_range);
                    }
                    foreach ($dates as $date) {
                        $hours[$i]['date'] = $date;
                        
                        $timeslot_id = $this->get_timeslot_id($date, '10:00', '17:00', $shared_capacity_id);
                        $hours[$i]['timeslots'][] = array(
                            'actual_from_time' => "08:00",
                            'actual_to_time' => "15:00",
                            'timeslot_type' => 'day',
                            'from_time' => "10:00",
                            'to_time' => "17:00",
                            'total_capacity' => (in_array($date, $response['availability'])) ? 500 : 0,
                            'adjustment_type' => 0,
                            'adjustment' => 0,
                            'is_active' => 1,
                            'is_active_slot' => 1,
                            'vacancies' => (in_array($date, $response['availability'])) ? 500 : 0,
                            'blocked' => 0,
                            'bookings' => 0,
                            'gt_active' => (in_array($date, $response['availability'])) ? 1 : 0,                            
                            'capacity_type' => (in_array($date, $response['availability'])) ? 500 : 0,
                            'timeslot_id' => $timeslot_id,
                            'shared_capacity_id' => $shared_capacity_id,
                            'created_at' => date('Y-m-d H:i:s'),
                            'modified_at' => date('Y-m-d H:i:s'),
                        );
                        $i++;
                    }
                } else {
                    $hours[$i]['date'] = $date_range['startDate'];
                    $timeslot_id = $this->get_timeslot_id($date_range['startDate'], '00:00', '00:00', $shared_capacity_id);
                    $hours[$i]['timeslots'][] = array(
                        'actual_from_time' => "00:00",
                        'actual_to_time' => "00:00",
                        'timeslot_type' => '0',
                        'from_time' => "00:00",
                        'to_time' => "00:00",
                        'total_capacity' => 0,
                        'adjustment_type' => 0,
                        'adjustment' => 0,
                        'is_active' => 0,
                        'is_active_slot' => 0,
                        'vacancies' => 0,
                        'blocked' => 0,
                        'bookings' => 0,
                        'gt_active' => 0,
                        'capacity_type' => 0,
                        'timeslot_id' => $timeslot_id,
                        'shared_capacity_id' => $shared_capacity_id,
                        'created_at' => date('Y-m-d H:i:s'),
                        'modified_at' => date('Y-m-d H:i:s'),
                    );
                }
            } else if ($response['availabilityType'] == "period"){
                if ($date_range['startDate'] == $date_range['endDate']) {
                    $dates[0] = $date_range['startDate'];
                } else {
                    $dates_range = $this->getDatesFromRange($date_range['startDate'], $date_range['endDate']);
                    $dates = explode(',', $dates_range);
                }
                foreach ($dates as $date) {
                    
                    $timeslot_id = $this->get_timeslot_id($date, '00:00', '23:00', $shared_capacity_id);
                    $hours[$i]['date'] = $date;
                    $hours[$i]['timeslots'][] = array(
                        'actual_from_time' => "00:00",
                        'actual_to_time' => "23:00",
                        'timeslot_type' => 'day',
                        'from_time' => "00:00",
                        'to_time' => "23:00",
                        'total_capacity' =>  500,
                        'adjustment_type' => 0,
                        'adjustment' => 0,
                        'is_active' => 1,
                        'is_active_slot' => 1,
                        'vacancies' => 500,
                        'blocked' => 0,
                        'bookings' => 0,
                        'gt_active' => 1,                            
                        'capacity_type' => 500,
                        'timeslot_id' => $timeslot_id,
                        'shared_capacity_id' => $shared_capacity_id,
                        'created_at' => date('Y-m-d H:i:s'),
                        'modified_at' => date('Y-m-d H:i:s'),
                    );
                    $i++;
                }
            } else {
                $timeslot_id = $this->get_timeslot_id($date_range['startDate'], '00:00', '00:00', $shared_capacity_id);
                $hours[$i]['date'] = $date_range['startDate'];
                $hours[$i]['timeslots'][] = array(
                    'actual_from_time' => '0',
                    'actual_to_time' => '0',
                    'timeslot_type' => 'specific',
                    'from_time' => '0',
                    'to_time' => '0',
                    'total_capacity' => 0,
                    'adjustment_type' => 0,
                    'adjustment' => 0,
                    'is_active' => 0,
                    'is_active_slot' => 0,
                    'vacancies' => 0,
                    'blocked' => 0,
                    'bookings' => 0,
                    'gt_active' => 0,
                    'capacity_type' => 0,
                    'timeslot_id' => $timeslot_id,
                    'shared_capacity_id' => $shared_capacity_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'modified_at' => date('Y-m-d H:i:s'),
                );
            }
            $response = $hours;
        } else {
            $i = 0;
            for ($date = $date_range['startDate']; strtotime($date) <= strtotime($date_range['endDate']); $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
                $hours[$i]['date'] = $date;
                $timeslot_id = $this->get_timeslot_id($date, '00:00', '23:59', $shared_capacity_id);
                $hours[$i]['timeslots'][] = array(
                    'actual_from_time' => '00:00:00',
                    'actual_to_time' => '23:59:00',
                    'timeslot_type' => 'specific',
                    'from_time' => '00:00',
                    'to_time' => '23:59',
                    'total_capacity' => 500,
                    'adjustment_type' => 0,
                    'adjustment' => 0,
                    'is_active' => 0,
                    'is_active_slot' => 0,
                    'vacancies' => 0,
                    'blocked' => 0,
                    'bookings' => 0,
                    'gt_active' => 0,
                    'capacity_type' => 0,
                    'timeslot_id' => $timeslot_id,
                    'shared_capacity_id' => $shared_capacity_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'modified_at' => date('Y-m-d H:i:s'),
                );
                $i++;
            }
            $response = $hours;
        }
        $gt_response = array();
        $gt_response['data'] = $response;
        $tp_lib_data['tp_data']['tp_request'] = $tp_request;
        $tp_lib_data['tp_data']['tp_response'] = $tp_response;
        $tp_lib_data['tp_data']['response_status_code'] = $curl_info['http_code']; 
        $tp_lib_data['tp_data']['request_time'] = $this->entering_date_time;
        $tp_lib_data['tp_data']['response_time'] = gmdate('Y-m-d H:i:s:').gettimeofday()["usec"];
        $gt_response['tp_lib_data'] = $tp_lib_data;
	//$response['tp_lib_data'] = $tp_lib_data;
        
        /* CHeck the processing time */
        if($this->THIRDPARTY_PROCESSING_TIME == 1) {
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((int)$processing_time) / 1000;
        }
        /* Save logs in file and graylog server */
        $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_availability_log.php', 'GT_availability', json_encode(array('request' => $data, 'response' => $response,'tp_lib_data'=> $tp_lib_data)), $graylog_api_name);
        $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_availability_log.php', 'title' => 'GT_availability : ', 'data' => json_encode(array('request' => $data, 'response' => $gt_response,'tp_lib_data'=> $tp_lib_data)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'processing_time' => $processing_times);

        return $gt_response;
    }
        

    /**
     * @Name create_booking_for_gt
     * @Purpose to create booking with GT booking APIs
     */
    public function create_booking_for_gt($params = array()) {
        global $graylogs;
        
        /* CHeck the processing time */
        $processing_times = 0;
        if($this->THIRDPARTY_PROCESSING_TIME == 1) {
            $start_time = microtime(true);
        }
        
    	$graylog_api_name = 'GT Reserve';
        $tp_request = array();
        $response = array();
        $tp_response = array();
        $curl_info = array();
        $this->gt_ticket_types = json_decode(GT_TICKET_TYPES, true);
        try {
            $response_array = array();
            $url = GT_CREATE_RESERVATION_API_URL;
            $tickets = array();
            foreach ($params as $ticket_type => $count){
                if(isset($this->gt_ticket_types[$ticket_type]) && $count > 0){
                    $tickets[] = array(
                        "ticketNumber" => $count,
                        "ticketTypeId" => $this->ticket_type_details[$this->gt_ticket_types[$ticket_type]]
                    );
                }
            }
            $sha256_array = array(
                'apiKey' => $this->gt_account['api_key'],
                'environment' => GT_ENVIRONMENT,
                'ticketDate' => $params['selected_date'],
                'ticketTime' => $params['selected_timeslot'],
                'tickets' => $tickets,
                'userId' => $this->third_party_params['user_id'],
            );
            $data = array(
                'apiKey' => $this->gt_account['api_key'],
                'environment' => GT_ENVIRONMENT,
                'ticketDate' => $params['selected_date'],
                'ticketTime' => $params['selected_timeslot'],
                'tickets' => $tickets,
                'userId' => $this->third_party_params['user_id'],
                'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->gt_account['secret_key']))
            );
                
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POST, count($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
            $response = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $response = $tp_response = json_decode($response, true);
            
            if ($curl_errno > 0 && $curl_errno == 28) {
                $response['error'] = $curl_error;
            }
                    
            $curl_info = curl_getinfo($ch);
            $tp_request['api_case'] = "reservation";
            $tp_request['url'] = $url;
            $tp_request['method'] = "POST";
            $tp_request['query_string'] = array();
            $tp_request['post_data'] = $data;
            $tp_request['third_party_code'] = 'gt';
            
            /* CHeck the processing time */
            if($this->THIRDPARTY_PROCESSING_TIME == 1) {
                $end_time = microtime(true);
                $processing_time = ($end_time - $start_time) * 1000;
                $processing_times = ((int)$processing_time) / 1000;
            }
            
            /* Save logs in file and graylog server */
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'GT Reservation Data: ', json_encode(array('request' => $data, 'response' => $response)), $graylog_api_name);
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'GT Reservation Data: ', 'data' => json_encode(array('request' => $data, 'response' => $response)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => ((isset($response['success']) && $response['success'] == TRUE) ? 200 : 400), 'processing_time' => $processing_times);
            $reservationId = 0;
            if (isset($response['success']) && $response['success'] == TRUE) {
                $reservationId = $response['reservationId'];
                $response_array['reservationId'] = $reservationId;
            } else {
                $response_array = $response;
            }
        } catch (SoapFault $ex) {
            $this->booking_status = 'FAILED';
            /* Save logs in file and graylog server */
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'Reserve Exception: ', json_encode(array('exception' => $ex)), $graylog_api_name);
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'Reserve Exception: ', 'data' => json_encode(array('exception' => $ex)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => 400);
            $response_array['messsge'] = "catch" . $ex->getMessage();
        }
        $response_array['data']['tp_data']['tp_request'] = $tp_request;
        $response_array['data']['tp_data']['tp_response'] = $tp_response;
        $response_array['data']['tp_data']['response_status_code'] = $curl_info['http_code'];
        $response_array['data']['tp_data']['request_time'] = $this->entering_date_time;
        $response_array['data']['tp_data']['response_time'] = gmdate('Y-m-d H:i:s:').gettimeofday()["usec"];
        return $response_array;
    }

        

    /**
     * @Name confirm_booking_for_gt
     * @Purpose to confirm booking with GT booking APIs
     */
    function confirm_booking_for_gt($params = array()) {
        global $graylogs;
        /* CHeck the processing time */
        $processing_times = 0;
        if($this->THIRDPARTY_PROCESSING_TIME == 1) {
            $start_time = microtime(true);
        }
        $this->gt_ticket_types = json_decode(GT_TICKET_TYPES, true);
    	$graylog_api_name = 'Confirm Booking';
        $tp_request = array();
        $tp_response = array();
        $curl_info = array();
        try {
            $url = GT_COMPLETE_RESERVATION_API_URL;
            $ota = isset($params['OTA']) ? $params['OTA'] : "";
            /* Use key to creating Hmac key */
            $sha256_array_start['apiKey'] = $this->gt_account['api_key'];
            
            /* Use key for post data */
            $data_start['apiKey'] = $this->gt_account['api_key'];
            
            if($this->dummy_value == 1 || !isset($params['booking_email']) || $params['booking_email'] == ''){
                $params['booking_email'] = $this->dummy_customer_info['booking_email'];
            }
            if($this->dummy_value == 1 || !isset($params['booking_first_name']) || $params['booking_first_name'] == ''){
                $params['booking_first_name'] = $this->dummy_customer_info['booking_first_name'];
            }
            if($this->dummy_value == 1 || !isset($params['booking_last_name']) || $params['booking_last_name'] == ''){
                $params['booking_last_name'] = $this->dummy_customer_info['booking_last_name'];
            }
            
            /* Below parameters use for guest details */
            if(isset($params['booking_email']) && $params['booking_email'] != ''){
                $sha256_array_start['customerEmailAddress'] = $params['booking_email'];
                $data_start['customerEmailAddress'] = $params['booking_email'];
            }
            if(isset($params['booking_first_name']) && $params['booking_first_name'] != ''){
                $sha256_array_start['customerFirstName'] = $params['booking_first_name'];
                $data_start['customerFirstName'] = $params['booking_first_name'];
            }
            if(isset($params['booking_last_name']) && $params['booking_last_name'] != ''){
                $sha256_array_start['customerSurname'] = $params['booking_last_name'];
                $data_start['customerSurname'] = $params['booking_last_name'];
            }
            
            /* Use below array to create shmac key */
            $sha256_array_end = array(
                'environment' => GT_ENVIRONMENT,
                'reservationId' => $params['reservationId'],
                'userId' => $this->third_party_params['user_id']
            );
            $sha256_array = array_merge($sha256_array_start, $sha256_array_end);
            
            /* Use below array to post data in request */
            $data_end = array(
                'environment' => GT_ENVIRONMENT,
                'reservationId' => $params['reservationId'],
                'userId' => $this->third_party_params['user_id'],
                'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->gt_account['secret_key']))
            );
            $data = array_merge($data_start, $data_end);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POST, count($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
            $response = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $response = $tp_response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
            
            if ($curl_errno > 0 && $curl_errno == 28) {
                $response['error'] = $curl_error;
            }            
            
            $curl_info = curl_getinfo($ch);
           
            /* CHeck the processing time */
            if($this->THIRDPARTY_PROCESSING_TIME == 1) {
                $end_time = microtime(true);
                $processing_time = ($end_time - $start_time) * 1000;
                $processing_times = ((int)$processing_time) / 1000;
            }
        
            $tp_request['api_case'] = "confirm_booking";
            $tp_request['url'] = $url;
            $tp_request['method'] = "POST";
            $tp_request['query_string'] = array();
            $tp_request['post_data'] = $data;
            $tp_request['third_party_code'] = 'gt';
            /* Save logs in file and graylog server */
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'GT Confirmation: ', json_encode(array('request'=> $data, 'response' => $response)), $graylog_api_name);    
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'GT Confirmation: ', 'data' => json_encode(array('request'=> $data, 'response' => $response)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => ((isset($response['success']) && $response['success'] == TRUE) ? 200 : 400), 'processing_time' => $processing_times);
            if (isset($response['success']) && $response['success'] == TRUE) {
                $barcodes_array = array();
                $reservationId = $params['reservationId'];
                $response_array['reservationId'] = $reservationId;
                foreach ($response['ticketBarcodes'] as $barcodes) {
                    $barcodes_array[] = $barcodes;
                    foreach ($this->gt_ticket_types as $ticket_type => $ticket_types) {
                        if ($barcodes['ticketTypeId'] == $this->ticket_type_details[$ticket_types]) {
                            $this->passes[$ticket_type][] = $barcodes['barcode'];
                            break;
                        }
                    }
                }
                $barcodes_array['passes']   = $this->passes;
                $response_array['barcodes'] = $barcodes_array;
                $response_array['response_data'] = $response;
            } else {
                if(isset($response['errorMessage']) && !empty($response['errorMessage']) && (strpos($response['errorMessage'], 'The 15 minutes reservation time has expired') !== false)){
                    if(isset($this->OTA_LEVEL_RESERVATION_EXPRIED_CHECK[$ota]) && $this->OTA_LEVEL_RESERVATION_EXPRIED_CHECK[$ota] == 1){
                        $instant_response = $this->instant_booking_for_gt($params);
                        return $instant_response;
                    } else {
                        $response_array = $response; 
                    }
                } else {
                   $response_array = $response; 
                }
               
            }
        } catch (SoapFault $ex) {
            $this->booking_status = 'FAILED';
            /* Save logs in file and graylog server */
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'Confirm Exception: ', json_encode(array('exception' => $ex)), $graylog_api_name);
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'Confirm Exception: ', 'data' => json_encode(array('exception' => $ex)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => 400);
            $response_array['messsge'] = "catch" . $ex->getMessage();
        }
        $response_array['data']['tp_data']['tp_request'] = $tp_request;
        $response_array['data']['tp_data']['tp_response'] = $tp_response;
        $response_array['data']['tp_data']['response_status_code'] = $curl_info['http_code']; 
        $response_array['data']['tp_data']['request_time'] = $this->entering_date_time;
        $response_array['data']['tp_data']['response_time'] = gmdate('Y-m-d H:i:s:').gettimeofday()["usec"];
        return $response_array;
    }
    
    function instant_booking_for_gt($params = array()) {
        global $graylogs;
        /* CHeck the processing time */
        $processing_times = 0;
        if($this->THIRDPARTY_PROCESSING_TIME == 1) {
            $start_time = microtime(true);
        }
        $this->gt_ticket_types = json_decode(GT_TICKET_TYPES, true);
        $tp_request = array();
        $tp_response = array();
        $curl_info = array();
        $tickets = array();
        try{
            $url = GT_INSTANT_BOOKING_API_URL;
            foreach ($params as $ticket_type => $count){
                if(isset($this->gt_ticket_types[$ticket_type]) && $count > 0){
                    $tickets[] = array(
                        "ticketNumber" => $count,
                        "ticketTypeId" => $this->ticket_type_details[$this->gt_ticket_types[$ticket_type]]
                    );
                }
            }
            if($this->dummy_value == 1 || !isset($params['booking_email']) || $params['booking_email'] == ''){
                $params['booking_email'] = $this->dummy_customer_info['booking_email'];
            }
            if($this->dummy_value == 1 || !isset($params['booking_first_name']) || $params['booking_first_name'] == ''){
                $params['booking_first_name'] = $this->dummy_customer_info['booking_first_name'];
            }
            if($this->dummy_value == 1 || !isset($params['booking_last_name']) || $params['booking_last_name'] == ''){
                $params['booking_last_name'] = $this->dummy_customer_info['booking_last_name'];
            }
            
            /* Use key to creating Hmac key */
            $sha256_array_start['apiKey'] = $this->gt_account['api_key'];
            
            /* Use key for post data */
            $data_start['apiKey'] = $this->gt_account['api_key'];
            
            /* Below parameters use for guest details */
            if(isset($params['booking_email']) && $params['booking_email'] != ''){
                $sha256_array_start['customerEmailAddress'] = $params['booking_email'];
                $data_start['customerEmailAddress'] = $params['booking_email'];
            }
            if(isset($params['booking_first_name']) && $params['booking_first_name'] != ''){
                $sha256_array_start['customerFirstName'] = $params['booking_first_name'];
                $data_start['customerFirstName'] = $params['booking_first_name'];
            }
            if(isset($params['booking_last_name']) && $params['booking_last_name'] != ''){
                $sha256_array_start['customerSurname'] = $params['booking_last_name'];
                $data_start['customerSurname'] = $params['booking_last_name'];
            }
            /* Use below array to create shmac key */
            $sha256_array_end = array(
                'environment' => GT_ENVIRONMENT,
                'ticketDate' => $params['selected_date'],
                'ticketTime' => gmdate('H:i',strtotime($params['selected_timeslot'])),
                'tickets' => $tickets,
                'userId' => $this->third_party_params['user_id'],
            );
            $sha256_array = array_merge($sha256_array_start, $sha256_array_end);
            
            /* Use below array to post data in request */
            $data_end = array(
                'environment' => GT_ENVIRONMENT,
                'ticketDate' => $params['selected_date'],
                'ticketTime' => gmdate('H:i',strtotime($params['selected_timeslot'])),
                'tickets' => $tickets,
                'userId' => $this->third_party_params['user_id'],
                'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->gt_account['secret_key']))
            );
            $data = array_merge($data_start, $data_end);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POST, count($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
            
            $response = curl_exec($ch);
            
            /* CHeck the processing time */
            if($this->THIRDPARTY_PROCESSING_TIME == 1) {
                $end_time = microtime(true);
                $processing_time = ($end_time - $start_time) * 1000;
                $processing_times = ((int)$processing_time) / 1000;
            }
            $response = $tp_response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
            
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            if ($curl_errno > 0 && $curl_errno == 28) {
                $response['error'] = $curl_error;
            }
            
            
            $curl_info = curl_getinfo($ch);
            
            $tp_request['api_case'] = "confirm_booking";
            $tp_request['url'] = $url;
            $tp_request['method'] = "POST";
            $tp_request['query_string'] = array();
            $tp_request['post_data'] = $data;
            $tp_request['third_party_code'] = 'gt';
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'GT Instant Booking: ', json_encode(array('url'=>$url ,'request'=> $data, 'response' => $response)), $graylog_api_name);    
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'GT Instant Booking: ', 'data' => json_encode(array('url'=>$url ,request=> $data, 'response' => $response)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => ((isset($response['success']) && $response['success'] == TRUE) ? 200 : 400), 'processing_time' => $processing_times);
            
            if (isset($response['success']) && $response['success'] == TRUE) {
                $barcodes_array = array();
                foreach ($response['ticketBarcodes'] as $barcodes) {
                    $barcodes_array[] = $barcodes;
                    foreach ($this->gt_ticket_types as $ticket_type => $ticket_types) {
                        if ($barcodes['ticketTypeId'] == $this->ticket_type_details[$ticket_types]) {
                            $this->passes[$ticket_type][] = $barcodes['barcode'];
                            break;
                        }
                    }
                }
                $barcodes_array['passes']   = $this->passes;
                $response_array['barcodes'] = $barcodes_array;
                $response_array['reservationId'] = isset($params['reservationId']) ? $params['reservationId'] : rand(1000, 10000);
            } else {
                $response_array = $tp_response;
            }
        } catch (Exception $ex) {
            $this->booking_status = 'FAILED';
            /* Save logs in file and graylog server */
            $this->apilog->setlog('thirdparty_lib/gt', 'thirdparty_log.php', 'Instant Booking Exception: ', json_encode(array('exception' => $ex)), $graylog_api_name);
            $graylogs[] = array('log_dir'=>'thirdparty_lib/gt', 'log_filename' => 'thirdparty_log.php', 'title' => 'Instant Booking Exception: ', 'data' => json_encode(array('exception' => $ex)), 'api_name' => $graylog_api_name, 'request_time' => $this->get_current_datetime(), 'http_status' => 400);
            $response_array['messsge'] = "catch" . $ex->getMessage();
        }
        $response_array['data']['tp_data']['tp_request'] = $tp_request;
        $response_array['data']['tp_data']['tp_response'] = $tp_response;
        $response_array['data']['tp_data']['response_status_code'] = $curl_info['http_code']; 
        $response_array['data']['tp_data']['request_time'] = $this->entering_date_time;
        $response_array['data']['tp_data']['response_time'] = gmdate('Y-m-d H:i:s:').gettimeofday()["usec"];
        return $response_array;
    }
    
    function getDatesFromRange($start, $end) {
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);
        $endDate->modify('+1 day');
        $daterange = new DatePeriod($startDate, new DateInterval('P1D'), $endDate);
        $result = '';

        foreach ($daterange as $date) {
            if (strtotime($date->format("Y-m-d")) == strtotime($end)) {
                $result .= $date->format("Y-m-d");
            } else {
                $result .= $date->format("Y-m-d") . ",";
            }
        }
        return $result;
    }
    /**
     * Function to get current datetime with seconds for controller
     * 14 nov, 2018
     */
    function get_current_datetime() {
        //return gmdate("Y-m-d H:i:s:").round(gettimeofday()["usec"]/1000).':'.gettimeofday()["usec"];
        
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("Y-m-d H:i:s",$date_array[1]);
        return $date.":" . number_format($date_array[0],3);
    }
         
    /**
     * @Name get_timeslot_id
     * @Purpose to set timeslot_id in redis data from all third party APIs.    
     * @Params : $date, $from_time, $to_time, $ticket_id. 
     * @CreatedBy : Pm <prashantmishra@intersoftprofessional.com> on 14 May 2019
     */
    function get_timeslot_id ($date = '', $from_time = '', $to_time = '', $shared_capacity_id = '') {
        $srch = array('-', ':');
        $replce = array('');
        $timeslot_id = str_replace($srch, $replce, ($date.$from_time.$to_time.$shared_capacity_id));        
        return $timeslot_id;
    }
}

?>
