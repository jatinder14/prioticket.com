<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);
include_once 'aws.php';

# ThirdParty: PHP wrapper class for thirdParty APIs
# Author: Hardeep Kaur

class Clorian {

    protected $agent = "";
    protected $request_indempotency_key = "";
    protected $request_headers = array();
    protected $response_status_code;
    public $ticket_id = "";
    //public $CLORIAN_TICKETS = array();
    public $TICKET_DETAILS = array();
    public $response = array();
    protected $status = "FALSE";
    protected $message = "failed";

    /**
     * __construct
     */
    public function __construct($params = array()) {

        $this->ticket_id = (isset($params['ticket_id']) && !empty($params['ticket_id'])) ? $params['ticket_id'] : "";        
        $this->TICKET_DETAILS = (isset($params['ticket_params']) &&  !empty($params['ticket_params'])) ? $params['ticket_params'] : array();
        $this->TIMEZONE = (isset($params['timezone']) &&  !empty($params['timezone'])) ? $params['timezone'] : '+1';
    }

    /**
     * @Name get_gt_availability
     * @Purpose to get availability from GT API for GT product
     */
    private function send_request($url = "", $method = "POST", $query_string, $post_data = array()) {
        /* SEND REQUEST TO CLORIAN */
        if ($query_string) {
            $url = $url . "?" . $query_string;
        }
        $ch = curl_init();
        //echo $url.$method;
        if (count($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->request_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
        $response = curl_exec($ch);
        
        $response = json_decode($response, true);
        $curl_info = curl_getinfo($ch);
        $curl_error = curl_strerror(curl_errno($ch));
        
        //$this->CreateLog('clorian_lib_log.php', "$url ", array('query_string' => json_encode($query_string), 'Post_data' => json_encode($post_data),'response' => json_encode($response), 'curl_error' => json_encode($curl_error), 'curl_status' => $curl_info['http_code']));
        
        curl_close($ch);
        $this->response_status_code = $curl_info['http_code'];
        return $response;
    }

    public function login($params = array()) {
        $url = CLORIAN_LOGIN_API_URL;
        $post_data = array(
            'username' => $params['username'],
            'password' => $params['password'],
        );
        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME);

        $this->request_headers = $AWSObj->GetSignedRequest(json_encode($post_data), 'login', 'POST');

        $response = $this->send_request($url, "POST", "", $post_data);
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
       // $this->CreateLog('clorian_lib_log.php', "login response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function clients($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $post_data = array();

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token);

        $this->request_headers = $AWSObj->GetSignedRequest("", 'clients', 'GET');
        $response = $this->send_request($url, "GET", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "clients response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function products($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];
        $clientUrlName = strtolower($params['clientUrlName']);
        $post_data = array();

        $url = $url . '/' . $clientUrlName . '/products';

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest("", 'clients/' . $clientUrlName . '/products', 'GET');
        $response = $this->send_request($url, "GET", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Products response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function product($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];
        $clientUrlName = strtolower($params['clientUrlName']);
        $id = $params['id'];
        
        $post_data = array();

        $url = $url . '/' . $clientUrlName . '/products/'.$id;

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest("", 'clients/' . $clientUrlName . '/products/'.$id, 'GET');
        $response = $this->send_request($url, "GET", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Product detail response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function events($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];
        $clientUrlName = strtolower($params['clientUrlName']);
        $product_id = $params['product_id'];
        $date = $params['date'];
       
        $post_data = array();

        $querystring = 'date=' . urlencode($date);
        
        $url = $url . '/' . $clientUrlName . '/products/' . $product_id . '/events?date=' . $date;

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest("", 'clients/' . $clientUrlName . '/products/' . $product_id . '/events', 'GET', $querystring);
        $response = $this->send_request($url, "GET", "", array());       
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Events response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function reservation($params = array()) {
        //$this->CreateLog('cloarian_hit.php', 'reservation', array('response' => json_encode($params)));
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];

        $post_data = $params['body'];
        $clientUrlName = strtolower($post_data['clientUrlName']);
        unset($post_data['clientUrlName']);
        
        $post_data['events'] = (object) $post_data['events'];
        $url = $url . '/' . $clientUrlName . '/reservation'; 
          

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest(json_encode($post_data), 'clients/' . $clientUrlName . '/reservation', 'POST');
        $response = $this->send_request($url, "POST", "", $post_data);
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->success = TRUE;
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Reservation response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, 'success'=>TRUE,  "data" => $this->response);
    }

    public function reservationdelete($params = array()) {
        $login_response = $this->login(array('username' => CLORIAN_USERNAME, 'password' => CLORIAN_PASSWORD));
        $token = $login_response['data']['token'];

        $purchase = $params['purchase'];
        
        $url = CLORIAN_CLIENTS_API_URL;
        $id = $params['id'];
        $clientUrlName = strtolower($params['client_url_name']);;
        
        $url = $url . '/' . $clientUrlName . '/reservation/'.$id;        
        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest('', 'clients/' . $clientUrlName . '/reservation/'.$id, 'DELETE');       
        $response = $this->send_request($url, "DELETE", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Reservation delete response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->response, "data" => $this->response);
    }

    public function reschedule($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        
        $post_data = $params['body'];
        $clientUrlName = strtolower($post_data['clientUrlName']);
        $id = $post_data['id'];
        unset($post_data['clientUrlName']);
        unset($post_data['id']);
        
        $post_data['events'] = (object) $post_data['events'];
        
        $url = $url . '/' . $clientUrlName . '/reservation/'.$id.'/reschedule';

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token);

        $this->request_headers = $AWSObj->GetSignedRequest(json_encode($post_data), 'clients/' . $clientUrlName . '/reservation/'.$id.'/reschedule', 'PUT');
        $response = $this->send_request($url, "PUT", "", $post_data);
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Reschedule response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function purchase_add($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];
        $clientUrlName = strtolower($params['client_url_name']);
        
        $url = $url . '/' . $clientUrlName . '/purchase';

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest('', 'clients/' . $clientUrlName . '/purchase', 'PUT');
        $response = $this->send_request($url, "PUT", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->success = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
       // $this->CreateLog('clorian_lib_log.php', "Purchase Add response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response, 'success' => $this->success);
    }

    public function purchase_get($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $token = $params['token'];
        $purchase = $params['purchase'];
        $clientUrlName = strtolower($params['client_url_name']);
        $reference = $params['reference'];
        
        $querystring = 'reference=' . urlencode($reference);
        
        $url = $url . '/' . $clientUrlName . '/purchase?reference='.$reference;

        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest('', 'clients/' . $clientUrlName . '/purchase', 'GET', $querystring);
        $response = $this->send_request($url, "GET", "", array());
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Purchase get response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    public function purchase_delete($params = array()) {
        $url = CLORIAN_CLIENTS_API_URL;
        $login_response = $this->login(array('username' => CLORIAN_USERNAME, 'password' => CLORIAN_PASSWORD));
        $token = $login_response['data']['token'];

        $purchase = $params['purchase'];        
        $clientUrlName = strtolower($params['client_url_name']);
        
        $url = $url . '/' . $clientUrlName . '/purchase';       
        $AWSObj = new AWS(CLORIAN_AWS_ACCESS_KEY, CLORIAN_AWS_SECRET_KEY, CLORIAN_API_KEY, CLORIAN_AWS_REGION, CLORIAN_AWS_SERVICE_NAME, CLORIAN_HOST_NAME, $token, $purchase);

        $this->request_headers = $AWSObj->GetSignedRequest('', 'clients/' . $clientUrlName . '/purchase', 'DELETE');
        $response = $this->send_request($url, "DELETE", "", array());        
        if ($this->response_status_code == "200") {
            $this->status = TRUE;
            $this->message = "success";
            $this->response = $response;
        } else if (!empty($response['errorMessage'])) {
            $this->status = FALSE;
            $this->message = $response['errorMessage'];
        }
        //$this->CreateLog('clorian_lib_log.php', "Purchase delete response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $this->response);
    }

    /**
     * @Name get_availability
     * @Purpose to get availablity from the CS APIs, process the array and return it.
     * Response array from CS APIs.
     * Response array Structure to return array(array('date', 'timeslot'=> array()))
     */
    public function get_availability($params = array()) {
       
        $login_response = $this->login(array('username' => CLORIAN_USERNAME, 'password' => CLORIAN_PASSWORD));
        $data['token'] = $login_response['data']['token'];

        $purchase = $this->get_uuid_token_v4();
        $data['purchase'] = trim($purchase);

        $data['clientUrlName'] = $this->TICKET_DETAILS['client_url_name'];
        $data['product_id'] = $this->TICKET_DETAILS['clorian_ticket_id'];
        $startdate_timestamp = strtotime($params['startDate']);
        $enddate_timestamp = strtotime($params['endDate']);
        $timezone = $this->TIMEZONE;
        $timeslots = [];
        for ($i = $startdate_timestamp; $i <= $enddate_timestamp; $i = $i + 86400) {
            $data['date'] = date('d/m/Y', $i);
            
            $response = $this->events($data);
          
            if ($response['message'] == 'success') {
                $standard_hours = array();
                $ii = 0;
                $hours = array();
                if (!empty($response['data']['events'])) {
                    $this->status = TRUE;
                    $this->message = "success";
                    foreach ($response['data']['events'] as $events) {

                        if (count($events['venueCapacityList']) > 0) {
                            foreach ($events['venueCapacityList'] as $venueCapacityList) {
                                if (count($venueCapacityList['eventList']) > 0) {
                                    foreach ($venueCapacityList['eventList'] as $eventList) {
                                        $eventId = $eventList['eventId'];
                                        $startDatetime = $eventList['startDatetime'];
                                        $endDatetime = $eventList['endDatetime'];
                                        $capacity = $eventList['capacity'];
                                        $totalAvailability = $eventList['totalAvailability'];
                                        $gt_active = $eventList['status'] == 'enabled' ? 1 : 0;

                                        $gmt_from_time = date('H:i:s', strtotime(date('Y-m-d H:i:s', $startDatetime). ' ' . $timezone . ' hours'));
                                        $gmt_to_time = date('H:i:s', strtotime(date('Y-m-d H:i:s', $endDatetime). ' ' . $timezone . ' hours'));
                                          
                                        $hours['date'] = date('Y-m-d', $i);
                                        $hours['timeslots'][] = array(
                                            'third_party_timeslot_id' => $eventId,
                                            'actual_from_time' => $gmt_from_time,
                                            'actual_to_time' => $gmt_to_time,
                                            'timeslot_type' => 'specific',
                                            'from_time' => date('H:i', $startDatetime),
                                            'to_time' => date('H:i', $endDatetime),
                                            'total_capacity' => $capacity,
                                            'adjustment_type' => 0,
                                            'adjustment' => 0,
                                            'is_active' => 1, //($is_active) ? 1 : 0,
                                            'is_active_slot' => 1,
                                            'vacancies' => $totalAvailability,
                                            'blocked' => 0,
                                            'bookings' => 0,
                                            'gt_active' => $gt_active,
                                        );
                                    }
                                }
                                
                            }
                        }
                        $ii++;
                    }
                } else {
                    $ii = 0;
                    $hours[$ii]['date'] = date('Y-m-d', $i);;
                    $hours[$ii]['timeslots'][] = array(
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
                        'gt_active' => 0
                    );
                }

                $timeslots[] = $hours;
            }
        }
        /* else {
          $this->status = FALSE;
          $this->message = !empty($this->response['error']) ? $this->response['error'] : $this->message;
          $this->response = '';//$this->send_empty_response($params['startDate'], $params['endDate']);
          } */
        //$this->CreateLog('clorian_lib_log.php', "get availability response ", array('response' => json_encode($this->response)));
        return array('status' => $this->status, "message" => $this->message, "data" => $timeslots);
    }

    function get_uuid_token_v4() {
        $url = 'https://www.uuidgenerator.net/api/version4';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
        $response = curl_exec($ch);
        return $response;
    }
    
     /**
     * @Name create_booking_for_clorian
     * @Purpose to create booking with clorian booking APIs
     */
    public function create_booking_for_clorian($params = array()) {
        try {
            $response_array = array();

            // get token
            $login_params['username'] = CLORIAN_USERNAME;
            $login_params['password'] = CLORIAN_PASSWORD;
            $login_response = $this->login($login_params);
            $token = $login_response['data']['token'];
            $cl_params['token'] = $token;

            // get Version 4 UUID token
            $purchase = trim($this->get_uuid_token_v4());
            $cl_params['purchase'] = $purchase;

            $clorian_ticket_id = $params['clorian_ticket_id'];           
            if(isset($params['no_of_tickets']) && $params['no_of_tickets']>0 && isset($params['adult_ticket_type_id']))
            {
                $buyer_types[$params['adult_ticket_type_id']] = $params['no_of_tickets'];
            }
            if(isset($params['child_no_of_tickets']) && $params['child_no_of_tickets']>0 && isset($params['child_ticket_type_id']) && $params['child_ticket_type_id']>0)
            {
                $buyer_types[$params['child_ticket_type_id']] = $params['child_no_of_tickets'];
            }
            if(isset($params['Infants_no_of_tickets']) && $params['Infants_no_of_tickets']>0 && isset($params['Infants_no_of_tickets']) && $params['infants_ticket_type_id']>0)
            {
                $buyer_types[$params['infants_ticket_type_id']] = $params['Infants_no_of_tickets'];
            }
            $selected_date = $params['selected_date'];
            $ticketTime = $params['selected_timeslot'];
            $third_party_timeslot_id = $params['third_party_timeslot_id'];
            $clientUrlName = $params['client_url_name'];
            
            $cl_params['body'] = array(
                'productId' => $clorian_ticket_id,
                'buyerTypes' => $buyer_types,
                'events' => array("0" => $third_party_timeslot_id),
                'distributorReference' => 'priopass',
                'clientUrlName' => $clientUrlName
            );
            $response = $this->reservation($cl_params);                       
            $reservationId = 0;
            if ($response['success'] == TRUE) {
                //$reservationId = $response['reservationId'];
                $reservationId = $response['data']['purchase']['reservationList'][0]['reservationId'];
                $response_array['reservationId'] = $reservationId;
                $response_array['token'] = $token;
                $response_array['purchase'] = $purchase;
            } else {
                $response_array = $response;
            }
        } catch (SoapFault $ex) {
            $this->booking_status = 'FAILED';
            $this->CreateLog('thirdparty_log.php', 'reserve_exception_log : ', array('exception' => json_encode($ex)));
            $response_array['messsge'] = "catch" . $ex->getMessage();
        } 
        return $response_array;
    }
    
    /**
     * @Name confirm_booking_for_clorian
     * @Purpose to confirm booking with CLORIAN booking APIs
     */
      function confirm_booking_for_clorian($params = array()) {
        try {
            $login_params['username'] = CLORIAN_USERNAME;
            $login_params['password'] = CLORIAN_PASSWORD;
            $login_response = $this->login($login_params);
            $params['token'] = $login_response['data']['token'];
            $response = $this->purchase_add($params);            
            //$this->CreateLog('thirdparty_log.php', 'GT_confirmation : ', array('request' => json_encode($data), 'response' => json_encode($response)));
            if ($response['message'] == 'success') {
                $params['reference'] = $response['data']['payment']['reference'];
                $response_array['clorian_booking_reference'] = $response['data']['payment']['reference'];                
                $response = $this->purchase_get($params);               
                $barcodes_array = array();
                $reservationId = $params['reservationId'];
                $response_array['reservationId'] = $reservationId;
                
                foreach ($response['data']['purchase']['reservationList'][0]['ticketList'] as $barcodes) {
                    $barcodes_array[] = $barcodes;                    
                    //if ($barcodes['ticketTypeId'] == $this->GT_API_KEYS[$params['ticket_id']]['GT_ADULT_TICKET_TYPE_ID']) {  // Adult passes
                    if ($barcodes['buyerTypeId'] == $params['adult_ticket_type_id']) {  // Adult passes
                        $this->passes['Adults'][] = $barcodes['barcode'];
                    } else if ($barcodes['buyerTypeId'] == $params['child_ticket_type_id']) { // Child passes
                        $this->passes['Childs'][] = $barcodes['barcode'];
                    } else if ($barcodes['buyerTypeId'] == $params['infants_ticket_type_id']) { // infant passes
                        $this->passes['Infants'][] = $barcodes['barcode'];
                    }                   
                }               
                $response_array['barcodes'] = $this->passes;
            } else {
                $response_array = $response;
            }
        } catch (SoapFault $ex) {
            $this->booking_status = 'FAILED';
            $this->CreateLog('thirdparty_log.php', 'confirm_exception_log : ', array('exception' => json_encode($ex)));
            $response_array['messsge'] = "catch" . $ex->getMessage();
        }
        return $response_array;
    }
    

}

?>
