<?php
error_reporting(E_ALL);

ini_set("display_errors", 1);

# ThirdParty: PHP wrapper class for thirdParty APIs
# Author: Karan Bhardwaj

class ThirdParty {

	// General settings
	protected $base_url = "";
	protected $vendor = '';
	protected $private_key = "";
	public $response = "";
	public $ticket_id = "";
	public $booking_status = "SUCCESS";
	public $passes = array();
	public $heineken_passes = array();
	public $all_passes = array();
	public $GT_API_KEYS = array();

	/**
	 * __construct
	 */
	public function __construct($vendor = '', $ticket_id = '') {
            $this->vendor = $vendor;
            $this->ticket_id = $ticket_id;
            $this->GT_API_KEYS = array(
                VAN_GOGH_TICKET_ID_FOR_BLUEBOAT => array(
                    'API_KEY' => API_KEY_FOR_BLUEBOAT,
                    'SECRET_KEY' => SECRET_KEY_FOR_BLUEBOAT
                ),
                VAN_GOUGH_GT_TICKET_ID => array(
                    'API_KEY' => GT_API_KEY,
                    'SECRET_KEY' => GT_API_SECRET
                ),
                Heineken_ticket_id => array(
                    'API_KEY' => GT_API_KEY,
                    'SECRET_KEY' => GT_API_SECRET
                ),
                Heineken_ticket_id_for_blueboat => array(
                    'API_KEY' => API_KEY_FOR_BLUEBOAT,
                    'SECRET_KEY' => SECRET_KEY_FOR_BLUEBOAT
                ),
                ROCK_THE_CITY => array(
                    'API_KEY' => API_KEY_FOR_ROCK_THE_CITY,
                    'SECRET_KEY' => SECRET_KEY_FOR_ROCK_THE_CITY
                ),
                ROCK_THE_CITY_BLUEBOAT => array(
                    'API_KEY' => API_KEY_FOR_ROCK_THE_CITY_BLUEBOAT,
                    'SECRET_KEY' => SECRET_KEY_FOR_ROCK_THE_CITY_BLUEBOAT
                ),
            );
            $this->user_id = array(
                VAN_GOGH_TICKET_ID_FOR_BLUEBOAT => VAN_GOUGH_GT_USER_ID,
                VAN_GOUGH_GT_TICKET_ID => VAN_GOUGH_GT_USER_ID,
                Heineken_ticket_id => Heineken_user_id,
                Heineken_ticket_id_for_blueboat => Heineken_user_id,
                ROCK_THE_CITY => USER_ID_ROCK_THE_CITY,
                ROCK_THE_CITY_BLUEBOAT => USER_ID_ROCK_THE_CITY_BLUEBOAT
            );
        }
        
        /**
         * @Name timeslots
         * @Purpose to get the timeslots from requested Vendor
         */
        public function timeslots($date = '') {
            $this->response = $this->{'get_'.strtolower($this->vendor).'_availability'}($date);
            return $this->response;
        }
        
        /**
         * @Name create_booking
         * @Purpose to create_booking on selected Vendor
         */
        public function create_booking($params = array()) {
            $this->response = $this->{'create_booking_for_'.strtolower($this->vendor)}($params);
            return $this->response;
        }
        
        /**
         * @Name cancel_booking
         * @Purpose to cancel_booking on selected Vendor
         */
        public function cancel_booking() {
            $this->response = $this->{'cancel_booking_for_'.strtolower($this->vendor)}();
            return $this->response;
        }
        
        /**
         * @Name get_gt_availability
         * @Purpose to get availability from GT API for GT product
         */
        public function get_gt_availability($selected_date = '', $timezone = '1') {
            $gt_tickets = json_decode(GT_TICKETS ,true);
            if(array_key_exists($this->ticket_id, $gt_tickets) && !empty($gt_tickets[$this->ticket_id])){
                $user_id = $gt_tickets[$this->ticket_id]['USER_ID'];
                $api_key        = $gt_tickets[$this->ticket_id]['API_KEY'];
                $secret_key     = $gt_tickets[$this->ticket_id]['API_SECRET'];
                $url = GT_AVAILABILITY_API_URL;

                $sha256_array = array(
                    'apiKey' => $api_key,
                    'endDate' => $selected_date,
                    'environment' => GT_ENVIRONMENT,
                    'startDate' => $selected_date,
                    'userId' => $user_id
                );
                $data = array(
                    'apiKey' => $api_key,
                    'endDate' => $selected_date,
                    'environment' => GT_ENVIRONMENT,
                    'startDate' => $selected_date,
                    'userId' => $user_id,
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $secret_key))
                );
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true);
                
//                exit;
                if ($response['success'] == 'true') {
                    $standard_hours = array();
                    foreach($response['availability'][$selected_date] as $timeslot => $status) {
                        $hours = array();
                        $gmt_timeslot  = date('H:i', strtotime($selected_date.' '.$timeslot.' -'.$timezone.' hours'));
                        $from_time = date('H:i', strtotime($selected_date.' '.$gmt_timeslot.' -15 minutes'));
                        $to_time = $gmt_timeslot;
                        if($status) {
                            $hours['active'] = 1;
                            $hours['timeslot_value'] = '<span class="hide">'.$timeslot.'</span><span>'.$timeslot.'</span><span  class="tickets-left"> (Available)</span>';
                        } else {
                            $hours['active'] = 0;
                            $hours['timeslot_value'] = '<span class="hide">'.$timeslot.'</span><span>'.$timeslot.'</span><span  class="tickets-left"> (0 left)</span>';
                        }
                        $hours['is_timeslot_on'] = 1;
                        $hours['actual_timeslot'] = $from_time.'_'.$to_time;
                        $standard_hours[] = $hours;
                    }
                    $response['response_data'] = $standard_hours;
                    $response['response'] = $response;
                } else {
                    $response['message'] = "No ticket available";
                    $response['response_data'] = array();
                }
                return $response;
            }
        }
        
        /**
         * @Name create_booking_for_gt
         * @Purpose to create booking with GT booking APIs
         */
        public function create_booking_for_gt($params = array()) {
            try {
                $response_array = array();
                $url = GT_CREATE_RESERVATION_API_URL;    
                $tickets = array();
                if($params['Adults'] > 0) {
                    $tickets[] = array(
                        "ticketNumber" => $params['Adults'],
                        "ticketTypeId" => VAN_GOUGH_GT_ADULT_TICKET_TYPE_ID,
                        "ticketAmount" => VAN_GOUGH_GT_ADULT_TICKET_AMOUNT
                    );
                }
                if($params['Childs'] > 0) {
                    $tickets[] = array(
                        "ticketNumber" => $params['Childs'],
                        "ticketTypeId" => VAN_GOUGH_GT_CHILD_TICKET_TYPE_ID,
                        "ticketAmount" => VAN_GOUGH_GT_CHILD_TICKET_AMOUNT
                    );
                }
                $sha256_array = array(
                    'apiKey'      => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,    
                    'ticketDate'  => $params['selected_date'],
                    'ticketTime'  => $params['selected_timeslot'],
                    'tickets'     => $tickets,
                    'userId'      => VAN_GOUGH_GT_USER_ID
                );                
                $data = array(
                    'apiKey'      => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,
                    'ticketDate'  => $params['selected_date'],
                    'ticketTime'  => $params['selected_timeslot'],
                    'tickets'     => $tickets,
                    'userId'      => VAN_GOUGH_GT_USER_ID,
                    'HMACKey'   => base64_encode(hash_hmac('sha256',json_encode($sha256_array),$this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY']))
                ); 
                
                $ch = curl_init();                
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true);
                $reservationId = 0;
                if ($response['success'] == TRUE) {
                    try {
                        $url = GT_COMPLETE_RESERVATION_API_URL;
                        $sha256_array = array(
                            'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                            'environment' => GT_ENVIRONMENT,
                            'reservationId' => $response['reservationId'],
                            'userId' => VAN_GOUGH_GT_USER_ID
                        );
                        $data = array(
                            'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                            'environment' => GT_ENVIRONMENT,
                            'reservationId' => $response['reservationId'],
                            'userId' => VAN_GOUGH_GT_USER_ID,
                            'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY']))
                        );
                        $reservationId = $response['reservationId'];
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        curl_setopt($ch, CURLOPT_POST, count($data));
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        $response = curl_exec($ch);
                        $response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
                        if ($response['success'] == TRUE) {
                            $barcodes_array = array();
                            $response_array['reservationId'] = $reservationId;
                            foreach($response['ticketBarcodes'] as $barcodes) {
                                $barcodes_array[] = $barcodes;
                                if($barcodes['ticketTypeId'] == VAN_GOUGH_GT_ADULT_TICKET_TYPE_ID) {  // Adult passes
                                    $this->passes['Adults'][] = $barcodes['barcode'];
                                } else if($barcodes['ticketTypeId'] == VAN_GOUGH_GT_CHILD_TICKET_TYPE_ID) { // Child passes
                                    $this->passes['Childs'][] = $barcodes['barcode'];
                                }
                                $this->all_passes[] = $barcodes['barcode'];
                            }
                            $response_array['barcodes'] = $barcodes_array;
                        } else {
                            $response_array = $response;
                        }
                    } catch (SoapFault $ex) {
                        $this->booking_status = 'FAILED';
                        $response_array['messsge'] = $ex->getMessage();
                    }
                } else {
                    $response_array = $response;
                }
                
            } catch (SoapFault $ex) {
                $this->booking_status = 'FAILED';
                $response_array['messsge'] = $ex->getMessage();
            }
            return $response_array;
        }
         /**
         * @Name send_gt_request_for_booking
         * @Purpose to hold booking with GT booking APIs
         */
        function send_gt_request_for_booking($perameters){
            $gt_tickets = json_decode(GT_TICKETS ,true);
            $api_key = $this->GT_API_KEYS[$this->ticket_id]['API_KEY'];
            $secret_key = $this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY'];
                
                if($perameters['ticket_id'] == ROCK_THE_CITY){
                    $ticket_type_adult_id = ADULT_TYPE_ID_ROCK_THE_CITY;
                    $adult_amount = "25.00";
                    $user_id = USER_ID_ROCK_THE_CITY;
                } else if($perameters['ticket_id'] == ROCK_THE_CITY_BLUEBOAT){
                    $ticket_type_adult_id = ADULT_TYPE_ID_ROCK_THE_CITY_BLUEBOAT;
                    $adult_amount = "25.00";
                    $user_id = USER_ID_ROCK_THE_CITY_BLUEBOAT;
                } else if(array_key_exists($perameters['ticket_id'], $gt_tickets) && !empty($gt_tickets[$perameters['ticket_id']])){
                    $user_id = $gt_tickets[$perameters['ticket_id']]['USER_ID'];
                    $ticket_type_adult_id = $gt_tickets[$perameters['ticket_id']]['ADULT_TICKET_TYPE_ID'];
                    $ticket_type_child_id = $gt_tickets[$perameters['ticket_id']]['CHILD_TICKET_TYPE_ID'];
                    $adult_amount = $gt_tickets[$perameters['ticket_id']]['ADULT_TICKET_AMOUNT'];
                    $child_amount = $gt_tickets[$perameters['ticket_id']]['CHILD_TICKET_AMOUNT'];
                    $api_key        = $gt_tickets[$perameters['ticket_id']]['API_KEY'];
                    $secret_key     = $gt_tickets[$perameters['ticket_id']]['API_SECRET'];
                    if (isset($gt_tickets[$perameters['ticket_id']]['INFANT_TICKET_TYPE_ID']) && isset($gt_tickets[$perameters['ticket_id']]['INFANT_TICKET_AMOUNT'])) {
                        $ticket_type_kinderkaartje_id = $gt_tickets[$perameters['ticket_id']]['INFANT_TICKET_TYPE_ID'];
                        $kinderkaartje_id_amount = $gt_tickets[$perameters['ticket_id']]['INFANT_TICKET_AMOUNT'];
                    } else {
                        $ticket_type_kinderkaartje_id = Heineken_GT_kinderkaartje_TICKET_TYPE_ID;
                        $kinderkaartje_id_amount = "0.00";
                    }
                }
                $tickets_array = [];
                if(strtolower($perameters['age_group']) == 'adult' ){
                    $tickets_array[] = array(
                        "ticketNumber" => $perameters['quantity'],
                        "ticketTypeId" => $ticket_type_adult_id,
                        "ticketAmount" => $adult_amount
                    );

                }else if( strtolower($perameters['age_group']) == 'child' ){
                    $tickets_array[] = array(
                        "ticketNumber" => $perameters['quantity'],
                        "ticketTypeId" => $ticket_type_child_id,
                        "ticketAmount" => $child_amount
                    );
                }else if (strtolower($perameters['age_group']) == 'kinderkaartje' ) {
                    $tickets_array[] = array(
                        "ticketNumber" => $perameters['quantity'],
                        "ticketTypeId" => $ticket_type_kinderkaartje_id,
                        "ticketAmount" => $kinderkaartje_id_amount
                    );
                }
               
                try {                
                    $url = GT_CREATE_RESERVATION_API_URL;
                   
                    if (isset($tickets_array)) {
                        $sha256_array = array(
                            'apiKey' => $api_key,
                            'environment' => GT_ENVIRONMENT,
                            'ticketDate' => $perameters['reservation_date'],
                            'ticketTime' => $perameters['reservation_to_time'],
                            'tickets' =>  $tickets_array,
                            'userId' => $user_id
                        );
                        
                        $data_van_gogh = array(
                            'apiKey' => $api_key,
                            'environment' => GT_ENVIRONMENT,
                            'ticketDate' => $perameters['reservation_date'],
                            'ticketTime' => $perameters['reservation_to_time'],
                            'tickets' => $tickets_array,
                            'userId' => $user_id,
                            'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $secret_key))
                        );
                    }
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_POST, count($data_van_gogh));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data_van_gogh));
                    $response_van_gogh = curl_exec($ch);
                    $response_van_gogh = json_decode($response_van_gogh, true);
                    $reservationId = 0;
                    
                    if ($response_van_gogh['success'] == TRUE) {
                        return $response_van_gogh['reservationId'];
                    } else {
                            return 'false';
//                        $this->booking_status = 'FAILED';
//                        $response_array['messsge'] = $ex->getMessage();
                    }
                } catch (SoapFault $ex) {
                        return 'false';
//                    $this->booking_status = 'FAILED';
//                    $response_array['messsge'] = $ex->getMessage();
                }
            

        }
         /**
         * @Name send_gt_request_to_confirm_tickets_booking
         * @Purpose to Booking booking with GT booking APIs
         */
        function send_gt_request_to_confirm_tickets_booking($ticket_id , $reservation_id){
            $user_id = $this->user_id[$this->ticket_id];
            try {
                $url = GT_COMPLETE_RESERVATION_API_URL;
                $sha256_array = array(
                    'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id
                );
                $data = array(
                    'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id,
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY']))
                );
                $reservationId = $reservation_id;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);

    
//                $myfile = fopen("".$reservationId.".txt", "w") or die("Unable to open file!");
//                
//                $txt = "\n".$reservationId.'----'.  json_encode($response);
//                fwrite($myfile, $txt);
//                fclose($myfile);
                if ($response['success'] == TRUE) {
                    $barcodes_array = array();
                    $response_array['reservationId'] = $reservationId;
                    foreach($response['ticketBarcodes'] as $barcodes) {
                        $barcodes_array[] = $barcodes;
                        if($barcodes['ticketTypeId'] == VAN_GOUGH_GT_ADULT_TICKET_TYPE_ID) {  // Adult passes
                            $this->passes['Adults'][] = $barcodes['barcode'];
                        } else if($barcodes['ticketTypeId'] == VAN_GOUGH_GT_CHILD_TICKET_TYPE_ID) { // Child passes
                            $this->passes['Childs'][] = $barcodes['barcode'];
                        }
                        $this->all_passes[] = $barcodes['barcode'];
                    }
                    $response_array['barcodes'] = $barcodes_array;
                } else {
                    $this->booking_status = 'FAILED';
                    //$response_array['messsge'] = $ex->getMessage();
                }
            } catch (SoapFault $ex) {
                $this->booking_status = 'FAILED';
                $response_array['messsge'] = $ex->getMessage();
            }
        }
        function send_gt_request_to_confirm_heineken_tickets_booking($ticket_id , $reservation_id){
            $gt_tickets = json_decode(GT_TICKETS ,true);
            if(isset($this->user_id[$this->ticket_id])) {
                $user_id = $this->user_id[$this->ticket_id];
                $api_key = $this->GT_API_KEYS[$this->ticket_id]['API_KEY'];
                $secret_key = $this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY'];
            }
            $user_id = $gt_tickets[$this->ticket_id]['USER_ID'];
            $ticket_type_adult_id = $gt_tickets[$this->ticket_id]['ADULT_TICKET_TYPE_ID'];
            $ticket_type_child_id = ($gt_tickets[$this->ticket_id]['CHILD_TICKET_TYPE_ID']) ? $gt_tickets[$this->ticket_id]['CHILD_TICKET_TYPE_ID'] : '0';
            $api_key        = $gt_tickets[$this->ticket_id]['API_KEY'];
            $secret_key     = $gt_tickets[$this->ticket_id]['API_SECRET'];
            
            if (isset($gt_tickets[$this->ticket_id]['INFANT_TICKET_TYPE_ID']) && isset($gt_tickets[$this->ticket_id]['INFANT_TICKET_AMOUNT'])) {
                $ticket_type_kinderkaartje_id = $gt_tickets[$this->ticket_id]['INFANT_TICKET_TYPE_ID'];
            } else {
                $ticket_type_kinderkaartje_id = Heineken_GT_kinderkaartje_TICKET_TYPE_ID;
            }
            try {
                $url = GT_COMPLETE_RESERVATION_API_URL;
                $sha256_array = array(
                    'apiKey' => $api_key,
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id
                );
                $data = array(
                    'apiKey' => $api_key,
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id,
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $secret_key))
                );
                $reservationId = $reservation_id;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);

//                $myfile = fopen("".$reservationId.".txt", "w") or die("Unable to open file!");
//                
//                $txt = "\n".$reservationId.'----'.  json_encode($response);
//                fwrite($myfile, $txt);
//                fclose($myfile);
                if ($response['success'] == TRUE) {
                    $barcodes_array = array();
                    $response_array['reservationId'] = $reservationId;
                    foreach($response['ticketBarcodes'] as $barcodes) {
                        $barcodes_array[] = $barcodes;
                        if($barcodes['ticketTypeId'] == $ticket_type_adult_id) {  // Adult passes
                            $this->heineken_passes[$reservationId]['Adults'][] = $barcodes['barcode'];
                        } else if($barcodes['ticketTypeId'] == $ticket_type_child_id) { // Child passes
                            $this->heineken_passes[$reservationId]['Childs'][] = $barcodes['barcode'];
                        } else if($barcodes['ticketTypeId'] == $ticket_type_kinderkaartje_id ) { // kinderkaartje passes
                            $this->heineken_passes[$reservationId]['kinderkaartje'][] = $barcodes['barcode'];
                        }
                        $this->all_passes[] = $barcodes['barcode'];
                    }
                    $response_array['barcodes'] = $barcodes_array;
                } else {
                    $this->booking_status = 'FAILED';
                    //$response_array['messsge'] = $ex->getMessage();
                }
            } catch (SoapFault $ex) {
                $this->booking_status = 'FAILED';
                $response_array['messsge'] = $ex->getMessage();
            }
        }
        function send_gt_request_to_confirm_rock_the_city_tickets_booking($ticket_id , $reservation_id){
            $user_id = $this->user_id[$this->ticket_id];
            try {
                $url = GT_COMPLETE_RESERVATION_API_URL;
                $sha256_array = array(
                    'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id
                );
                $data = array(
                    'apiKey' => $this->GT_API_KEYS[$this->ticket_id]['API_KEY'],
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservation_id,
                    'userId' => $user_id,
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $this->GT_API_KEYS[$this->ticket_id]['SECRET_KEY']))
                );
                $reservationId = $reservation_id;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
                if ($response['success'] == TRUE) {
                    $barcodes_array = array();
                    if(!empty($response['ticketBarcodes'])){
                        foreach ($response['ticketBarcodes'] as $barcode){
                            $barcodes_array[] = $barcode['barcode'];
                        }
                        return $barcodes_array;
                    }else{
                        $this->booking_status = 'FAILED';
                    }
                } else {
                    $this->booking_status = 'FAILED';
                    //$response_array['messsge'] = $ex->getMessage();
                }
            } catch (SoapFault $ex) {
                $this->booking_status = 'FAILED';
                $response_array['messsge'] = $ex->getMessage();
            }
        }
        /**
         * @Name cancel_booking_for_gt
         * @Purpose to cancel booking with GT cancel APIs
         */
        public function cancel_booking_for_gt($reservationId = '') {
            try {
                $url = GT_CANCEL_RESERVATION_API_URL;            
                $sha256_array = array(
                    'apiKey' => GT_API_KEY,
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservationId,
                    'userId' => VAN_GOUGH_GT_USER_ID
                );
                $data = array(
                    'apiKey' => GT_API_KEY,
                    'environment' => GT_ENVIRONMENT,
                    'reservationId' => $reservationId,
                    'userId' => VAN_GOUGH_GT_USER_ID,
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), GT_API_SECRET))
                );
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $response = json_decode($response, true);
                echo json_encode($response);
            } catch (SoapFault $ex) {
                
            }
        }
    
        /**
         * @name: create_booking_for_rezgo
         * @purpose: To book ticket at rezgo
         * @where: It is called from POS after tiket allocation and clicked on confirm
         * @params: rezgo_id = Museum ID at Rezgo
         * rezgo_ticket_id : Ticket Id at Rezgo
         * rezgo_api_key : Rezgo museum's API key
         * booking_date : date for which to book the ticket
         * @return: Returns ture or false
         * @created by: Davinder singh  <davinder.intersoft@gmail.com> on July 13, 2015
         */    
        function create_booking_for_rezgo($params = array()) {
            if(PAYMENT_MODE == 'live') {
                $infant_count = 0;
                $child_count  = 0;
                $adult_count  = 0;
                foreach($params['booking_type'][$params['booking_date']] as $key => $value) {
                    if($key == 'Baby') {
                        $infant_count+=$value;
                    } 
                    if($key == 'Child') {
                        $child_count+=$value;
                    } 
                    if($key == 'Adult') {
                        $adult_count+=$value;
                    }
                }
                $fp = fopen("application/storage/logs/rezgo.php", "a+");
                fwrite($fp, $adult_count.'--'.$child_count.'--'.$infant_count.'---'.date("Y/m/d H:i:s")."\n");        
                $first_name = (isset($params['hotel_name'])) ? $params['hotel_name'] : 'Samuel';
                $date = str_replace('_', '-', $params['booking_date']);
                $url = 'http://xml.rezgo.com/xml';
                $ch = curl_init();
                $headers[] = 'Content-type: application/xml';
                //set the url, number of POST vars, POST data
                curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query(array(
                    'transcode' => $params['rezgo_id'],
                    'key'  => $params['rezgo_api_key'],
                    'i'    => "commit",
                    'date' => $date,
                    'book' => $params['rezgo_ticket_id'],
                    'adult_num' => $adult_count,
                    'child_num' => $child_count,
                    'senior_num' => $infant_count,
                    "tour_first_name" => 'Priopass',
                    "tour_last_name"  => $first_name.' '.date("Y/m/d H:i:s"),
                    "tour_address_1"  => "Rokin 69",
                    "tour_address_2"  => "1012 KL",
                    "tour_city"       => "Amsterdam",
                    "tour_stateprov"  => "Amsterdam",
                    "tour_country"    => "NL",
                    "tour_postal_code"   => "1012KL",
                    "tour_phone_number"  => "3188-000-8830",
                    "tour_email_address" => "support@priopass.com",
                    "payment_method"     => "Priopass",
                    "agree_terms"        => "1",
                    "ip"    => "122.176.112.152",
                    "refid" => $first_name 
                )));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                $xml_snippet = simplexml_load_string($response);
                fwrite($fp, json_encode($xml_snippet)."\n");
                fclose($fp);
                echo json_encode($xml_snippet);
            } else {
                $arr['message'] = 'Booking complete';
                echo json_encode($arr);
            }
        }
}

?>
