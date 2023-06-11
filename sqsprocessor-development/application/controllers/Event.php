<?php

class Event extends MY_Controller {

    function __construct() {
        parent::__construct();
        $this->load->model('sendemail_model');
        $this->load->library('log_library');
        $this->load->helper('timezone');
    }

    /**
     * @name    : send_email()
     * @Purpose : To send an email and HERE SYC GOOGLE calendar is not in working state, PLEASE check google libraries before
     * @where   : when SNS hit the link on confirm API
     * @params  : No parameter required
     * @return  : nothing
     */
    function send_email() {
        $_REQUEST = file_get_contents('php://input');
        if (!empty($_REQUEST)) {
            $queueUrl = QUEUE_URL_SYNC_GOOGLE_CALENDAR;
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $this->CreateLog('sync_event_details.php', 'Event Details Request', array('EventStart' => gmdate('Y-m-d H:i:s'), 'request' => $_REQUEST));
            /* $this->load->library('googleCalenderAPI');  /*commented for some time as calender library is not on sqs branch */
            if (SERVER_ENVIRONMENT == 'Local') {
                $messages[] = file_get_contents('php://input');
            } else {
                $sqs_object = new Sqs();
                $messages = $sqs_object->receiveMessage($queueUrl);
                $messages = $messages->getPath('Messages');
            }
            if ($messages) {
                foreach ($messages as $message) {
                    if (SERVER_ENVIRONMENT == 'Local') {
                        $string = $message;
                    } else {
                        $string = $message['Body'];
                    }
                    $string = gzuncompress(base64_decode($string));
                    $string = utf8_decode($string);

                    $data = json_decode($string, true);
                    if (!empty($data)) {
                        $this->CreateLog('sync_event_details.php', 'Queue Event Details Request', array('Query details' => json_encode($data)));
                        /*  events are NOT WORKING STATE need to have google calender library in this branch */
                        if (!empty($data['events'])) {
                            $this->CreateLog('GoogleCalenderEvent.php', 'Request Event details', array('EventDetailsData' => json_encode($data['events'])));
                            foreach ($data['events'] as $msg) {
                                if (isset($msg['museum_id']) && $msg['museum_id'] != '') {
                                    if ($msg['museum_id'] == GOOGLE_EVENT_SUPPLIER) { // if supplier has more than 1 email id
                                        if (in_array($msg['ticket_id'], $folder1_ticket_ids)) {
                                            $google_calendar_url = 'google_calendar/credentials/' . $msg['museum_id'] . '/1/client_secret.json';
                                            $credential_path = 'google_calendar/credentials/' . $msg['museum_id'] . '/1/calendar-php-qickstart.json';
                                        } else if (in_array($msg['ticket_id'], $folder2_ticket_ids)) {
                                            $google_calendar_url = 'google_calendar/credentials/' . $msg['museum_id'] . '/2/client_secret.json';
                                            $credential_path = 'google_calendar/credentials/' . $msg['museum_id'] . '/2/calendar-php-qickstart.json';
                                        }
                                    } else {
                                        $google_calendar_url = 'google_calendar/credentials/' . $msg['museum_id'] . '/client_secret.json';
                                        $credential_path = 'google_calendar/credentials/' . $msg['museum_id'] . '/calendar-php-qickstart.json';
                                    }
                                    if (file_exists($google_calendar_url) && file_exists($credential_path)) {
                                        $secret_path = $google_calendar_url;
                                        $obj = new googleCalenderAPI($secret_path, $credential_path);
                                    } else {
                                        $obj = new googleCalenderAPI();
                                    }
                                } else {
                                    $obj = new googleCalenderAPI();
                                }
                                $this->CreateLog('GoogleCalenderEvent.php', 'Request Event details', array('Obj' => json_encode($obj)));
                                $time = explode('+', $msg['endDateTime']);
                                $date = explode('T', $msg['endDateTime']);
                                $time = date('H:i', strtotime($time[0]));
                                $date = date('Y-m-d', strtotime($date[0]));
                                $event = $this->pos_model->find('ticket_capacity_v1', array('select' => 'google_event_id, sold', 'where' => 'ticket_id = "' . $msg['ticket_id'] . '" and date = "' . $date . '" and to_time = "' . $time . '"'));
                                $this->CreateLog('GoogleCalenderEvent.php', 'Request Event details', array('ticketcapacity_v1' => json_encode($event)));
                                /* Create Event on Calender */
                                $obj->EventDetails = array(
                                    'summary' => $event[0]['sold'] . ' PAX - ' . $msg['title'],
                                    'location' => $msg['location'],
                                    'description' => $msg['reservationReference'] . ':' . $msg['description'],
                                    'start' => array(
                                        'dateTime' => $msg['startDateTime'],
                                        'timeZone' => $msg['timezone'],
                                    ),
                                    'end' => array(
                                        'dateTime' => $msg['endDateTime'],
                                        'timeZone' => $msg['timezone'],
                                    ),
                                    'reminders' => array(
                                        'useDefault' => FALSE,
                                        'overrides' => array(
                                            array('method' => 'email', 'minutes' => 24 * 60),
                                            array('method' => 'popup', 'minutes' => 10),
                                        )
                                    )
                                );
                                /* Update event on Calendar */
                                if (!empty($event) && $event[0]['google_event_id'] != '') {
                                    $details = array();
                                    $details['summary'] = $event[0]['sold'] . ' PAX - ' . $msg['title'];
                                    $details['description'] = $msg['reservationReference'] . ':' . $msg['description'];
                                    $this->CreateLog('GoogleCalenderEvent.php', 'Request Event details', array('details' => json_encode($details), 'event_id' => $event[0]['google_event_id']));
                                    $event_id = $obj->update_event($event[0]['google_event_id'], $details);
                                    $this->CreateLog('GoogleCalenderEvent.php', 'Event_id', array('Event_id' => $event_id));
                                    if (isset($event_id) && $event_id != $event[0]['google_event_id']) {
                                        $this->pos_model->update('ticket_capacity_v1', array('google_event_id' => $event_id), array('ticket_id' => $msg['ticket_id'], 'date' => $date, 'to_time' => $time));
                                        $this->CreateLog('GoogleCalenderEvent.php', 'Event created after Deletion', array('EventDetails' => json_encode($obj->EventDetails), 'UpdatedEventId' => $event_id, 'query' => $this->db->last_query()));
                                    } else {
                                        $this->CreateLog('GoogleCalenderEvent.php', 'Event Updated', array('EventDetails' => json_encode($details), 'RequestedEventId' => $event[0]['google_event_id']));
                                    }
                                } else {
                                    $event_id = $obj->create_event();
                                    $this->pos_model->update('ticket_capacity_v1', array('google_event_id' => $event_id), array('ticket_id' => $msg['ticket_id'], 'date' => $date, 'to_time' => $time));
                                    $this->CreateLog('GoogleCalenderEvent.php', 'New Event Created', array('EventDetails' => json_encode($obj->EventDetails), 'response' => $event_id, 'query' => $this->db->last_query()));
                                }
                            }
                        }
                        /* send email to msgclaim and notification email ids. */
                        if (!empty($data['send_email_details'])) {
                            foreach ($data['send_email_details'] as $email) {

                                $this->sendemail_model->sendemailtousers($email, 2); // via mailgun
                            }
                        }
                        if (SERVER_ENVIRONMENT != 'Local') {
                            $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                        }
                    } else {
                        if (SERVER_ENVIRONMENT != 'Local') {
                            $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                        }
                    }
                }
            }
        }
    }
    
    /**
    * @Name        : process_queue
    * @Purpose     : to process the request in the queue name : API_NOTIFICATION_PROCESS
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    public function process_queue($data = '') {
        try {
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $postBody = file_get_contents('php://input');
            if (strtoupper(SERVER_ENVIRONMENT) == 'LOCAL') {
                $queue_messages[] = $postBody;
            } else {
                $sqs_object = new Sqs();
                $queueUrl = OTA_NOTIFICATION_PROCESS;
                /* It return Data from message */
                $messages = $sqs_object->receiveMessage($queueUrl);
                $queue_messages = $messages->getPath('Messages');
            }
            if (!empty($queue_messages)) {
                foreach ($queue_messages as $message) {
                    if (strtoupper(SERVER_ENVIRONMENT) == 'LOCAL') {
                        $data_string = gzuncompress(base64_decode($message));
                    } else {
                        $data_string = gzuncompress(base64_decode($message['Body']));
                    }
                    $data_string = utf8_decode($data_string);
                    $data = json_decode($data_string, TRUE);
                    if (!empty($data)) {
                        $request_type = (isset($data['request_type']) && !empty($data['request_type'])) ? $data['request_type'] : "";
                        if(strtoupper($request_type) == "AVAILABILITY_NOTIFICATON"){
                            $this->availability_notificatons($data);
                        }
                    }
                    if (strtoupper(SERVER_ENVIRONMENT) != 'LOCAL') {
                        $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    }
                }
            }
        } catch (Exception $e){
            $this->CreateLog('GYG_NOTIFY.php', 'Exception 1: ', array('EventStart' => gmdate('Y-m-d H:i:s'), 'exception' => json_encode($e->getMessage())));
        }
    }
    
    /**
    * @Name        : availability_notificatons
    * @Purpose     : When we have to update the availability at OTA so need to notify them about this updations
    * $request params : is_reservation, is_own_capacity, shared_capacity_id, own_capacity_id, product_id -from mec , from_date, to_date
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    function availability_notificatons($params = []) 
    {   
        try {
            $this->load->model('api_model');
            $this->load->helper('curl_request_helper');
            if (!empty($params)) {
                $params['is_reservation'] = $params['is_reservation'] + 1;
                $get_constants['const_name'] = ['GYG_TICKETS_LIST', 'GYG_INACTIVE_PRODUCTS', 'CAPACITY_FROM_REDIS', 'CRON_REDIS_SERVER', 'REDIS_AUTH_KEY', 'ENVIRONMENT', 'GYG_SERVER', 'GYG_AUTH_CSS', 'GYG_AUTH'];
                $constants = $this->api_model->get_constant_from_api($get_constants);
                if(!empty($constants) && $constants['CAPACITY_FROM_REDIS'] != 'INVALID CONSTANT') {
                    $available_capacity = $this->send_redis_data($params, $constants);
                    if($params['is_own_capacity'] == 3 && $params['own_capacity_id'] > 0){
                        $params['shared_capacity_id'] = $params['own_capacity_id'];
                        $own_data = $this->send_redis_data($params, $constants);
                        $available_capacity = $this->compare_both_type_sharedcapacity($own_data, $available_capacity);
                    }
                    if($available_capacity['curl_errno'] != 28){
                        $mec_data = $this->api_model->get_mec_data($params['product_id']);
                        $response = $this->generate_availability($available_capacity, $mec_data, $params);
                    }
                    if(!empty($response)&& in_array($params['product_id'], array_keys($constants['GYG_TICKETS_LIST'])) && !in_array($params['product_id'], $constants['GYG_INACTIVE_PRODUCTS'])){
                        $generate_gyg_notify_request = $this->generate_gyg_notify_request($constants['GYG_TICKETS_LIST'][$params['product_id']], $response);
                        $this->CreateLog('GYG_NOTIFY.php', 'Notification request1', array('EventStart' => gmdate('Y-m-d H:i:s'), 'request' => json_encode($generate_gyg_notify_request)));
                        if(!empty($generate_gyg_notify_request['data']['availabilities'])){
                            if($mec_data['third_party_id'] == 5 || $mec_data['second_party_id'] == 5){
                                $gyg_auth = $constants['GYG_AUTH_CSS'];
                            } else {
                                $gyg_auth = $constants['GYG_AUTH'];
                            }
                            $gyg_request_data = [
                                'end_point' => $constants['GYG_SERVER'] . '/1/notify-availability-update',
                                'request'   => $generate_gyg_notify_request,
                                'headers'   => 'Content-Type: application/json',
                                'method'    => 'POST',
                                'basic_auth' => 1,
                                'auntherisation' => $gyg_auth,
                                'timeout'    => 1000
                            ];
                            $this->CreateLog('GYG_NOTIFY.php', 'Notification request2', array('EventStart' => gmdate('Y-m-d H:i:s'), 'request' => json_encode($gyg_request_data)));
                            if(strtoupper($constants['ENVIRONMENT']) == 'LIVE') {
                                $get_gyg_data = SendRequest::send_request($gyg_request_data);
                            }
                        }
                        $this->CreateLog('GYG_NOTIFY.php', 'Notification request3', array('EventStart' => gmdate('Y-m-d H:i:s'), 'request' => json_encode($get_gyg_data)));
                    }
                }
            }
        } catch (Exception $e){
            $this->CreateLog('GYG_NOTIFY.php', 'Exception 2: ', array('EventStart' => gmdate('Y-m-d H:i:s'), 'exception' => json_encode($e->getMessage())));
        }
    }
    
    /**
    * @Name        : send_redis_data
    * @Purpose     : generate data that we have to send to redis to get data from redis
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    function send_redis_data($params = [], $constants = [])
    {
        $get_redis_data['response'] = [];
        if(!empty($params) && !empty($constants)){
            $data = array("ticket_id" => $params['product_id'], "ticket_class" => $params['is_reservation'], "from_date" => $params['from_date'], "to_date" => $params['to_date'], 'shared_capacity_id' => $params['shared_capacity_id']);
            $redis_headers = $this->set_headers([] , $constants['REDIS_AUTH_KEY']);
            $redis_request_data = [
                'end_point' => $constants['CRON_REDIS_SERVER'] . '/listcapacity',
                'request'   => $data,
                'headers'   => $redis_headers,
                'method'    => 'POST'
            ];
            $get_redis_data = SendRequest::send_request($redis_request_data);
        }
        return $get_redis_data['response'];
    }
    
    /**
    * @Name        : set_headers
    * @Purpose     : if we get any specific headers that we have to send to redis
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    function set_headers($header_request = array(), $redis_auth_key = '')
    {
        $headers = array();
         if(!empty($header_request)){
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . (!empty($redis_auth_key) ? $redis_auth_key : REDIS_AUTH_KEY),
                'museum_id : ' . (!empty($header_request['museum_id']) ? $header_request['museum_id'] : 0),
                'pos_type : ' . (!empty($header_request['pos_type']) ? $header_request['pos_type'] : 0),
                'channel_type : ' . (!empty($header_request['channel_type']) ? $header_request['channel_type'] : 0),
                'hotel_id : ' . (!empty($header_request['hotel_id']) ? $header_request['hotel_id'] : 0),
                'ticket_id : ' . (!empty($header_request['ticket_id']) ? $header_request['ticket_id'] : 0),
                'action : ' . (!empty($header_request['action']) ? $header_request['action'] : "")
            );
        } else {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . (!empty($redis_auth_key) ? $redis_auth_key : REDIS_AUTH_KEY)
            );
        }
        return $headers;
    }
    
    /**
    * @Name        : compare_both_type_sharedcapacity
    * @Purpose     : when we get both type product then we have to generate the vacancies as per both the shared_Capacity is
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    public function compare_both_type_sharedcapacity($owned_capacity_array, $shared_capacity_array) 
    {
        if ((isset($owned_capacity_array['curl_errno']) && $owned_capacity_array['curl_errno'] == 28) || (isset($shared_capacity_array['curl_errno']) && $shared_capacity_array['curl_errno'] == 28)) {
            $owned_capacity_array['curl_errno'] = 28;
        } else {
            /* for lopp for Owned Capacity Timeslot */
            foreach ($owned_capacity_array['data'] as $key => $value_owned) {
                /* for loop for owned capacity timeslot */
                foreach ($value_owned['timeslots'] as $key_time => $timeslots_owned) {
                    /* For loop for shared capacity timeslot */
                    foreach ($shared_capacity_array['data'] as $key_inner => $value_shared) {
                        if (isset($shared_capacity_array['data'][$key]) && !empty($shared_capacity_array['data'][$key]['timeslots'][0]['from_time'])) {
                            /* check for shared capacity date and own capacity date */
                            if ($value_owned['date'] == $value_shared['date']) {
                                /* compare the timeslot for check capacity of both */
                                foreach ($value_shared['timeslots'] as $timeslots_shared) {
                                    /* Use to check same key data exist in both array */
                                    if ($timeslots_owned['from_time'] == $timeslots_shared['from_time'] && $timeslots_owned['to_time'] == $timeslots_shared['to_time']) {
                                        /* get shared vacancy */
                                        $shared_vacancy = $timeslots_shared['total_capacity'] - $timeslots_shared['bookings'];
                                        if ($timeslots_shared['adjustment_type'] == '2') {
                                            $shared_vacancy = ($timeslots_shared['total_capacity'] - $timeslots_shared['adjustment']) - $timeslots_shared['bookings'];
                                        }
                                        $shared_vac = ($timeslots_shared['adjustment_type'] == '1') ? (($timeslots_shared['total_capacity'] + $timeslots_shared['adjustment']) - $timeslots_shared['bookings']) : $shared_vacancy;
                                        /* get owned vacancy */
                                        $owned_vacancy = $timeslots_owned['total_capacity'] - $timeslots_owned['bookings'];
                                        if ($timeslots_owned['adjustment_type'] == '2') {
                                            $owned_vacancy = ($timeslots_owned['total_capacity'] - $timeslots_owned['adjustment']) - $timeslots_owned['bookings'];
                                        }
                                        $owned_vac = ($timeslots_owned['adjustment_type'] == '1') ? (($timeslots_owned['total_capacity'] + $timeslots_owned['adjustment']) - $timeslots_owned['bookings']) : $owned_vacancy;
                                        /* check if shared vacancy is minimize then get shared vacancy otherwise owned vacancy */
                                        if ($shared_vac < $owned_vac) {
                                            $owned_capacity_array['data'][$key]['timeslots'][$key_time] = $timeslots_shared;
                                        }
                                        $owned_capacity_array['data'][$key]['timeslots'][$key_time]['is_shared_capacity'] = 1;
                                        $owned_capacity_array['data'][$key]['timeslots'][$key_time]['is_active'] = ($timeslots_shared['is_active'] == 0) ? 0 : (($timeslots_owned['is_active'] == 0) ? 0 : 1);
                                    }
                                }
                            }
                        } else {
                            unset($owned_capacity_array['data'][$key]);
                        }
                    }
                }
            }
        }
        return $owned_capacity_array;
    }
    
    /**
    * @Name        : generate_availability
    * @Purpose     : generate the standard avalability response as per redis response, i.e. calculate vacancies on the basis of each parameter required
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    function generate_availability($redis_response_data = [], $mec_data = [], $requested_data = [])
    {
        if (!empty($redis_response_data)) {
            $counts = $total_array = $response = [];
            foreach ($redis_response_data['data'] as $row) {
                foreach ($row['timeslots'] as $slot) {
                    $counts[$row['date']][] = $slot;
                    $total_array[$row['date'] . '_' . $slot['from_time'] . '_' . $slot['to_time']] = $slot['bookings'];
                }
            }
            $local_timezone_name = !empty($mec_data['local_timezone_name']) ? $mec_data['local_timezone_name'] : '';
            $ticket_class = $mec_data['is_reservation'];
            $is_cut_off_time = $mec_data['is_cut_off_time'];
            $cut_off_time = $mec_data['cut_off_time'];
            $ticket_start_date = date('Y-m-d', $mec_data['startDate']);
            $ticket_end_date = date('Y-m-d', $mec_data['endDate']);
            $array_key = 0;
            foreach ($counts as $date => $slots) {
                foreach ($slots as $slot) {
                    if ($slot['from_time'] !== '0' && $slot['is_active'] == '1') {
                        if ($slot['adjustment_type'] == '1') {
                            $vacancies = ($slot['total_capacity'] + $slot['adjustment']) - ($total_array[$date . '_' . $slot['from_time'] . '_' . $slot['to_time']]);
                        } else if ($slot['adjustment_type'] == '2') {
                            $vacancies = ($slot['total_capacity'] - $slot['adjustment']) - ($total_array[$date . '_' . $slot['from_time'] . '_' . $slot['to_time']]);
                        } else {
                            $vacancies = $slot['total_capacity'] - ($total_array[$date . '_' . $slot['from_time'] . '_' . $slot['to_time']]);
                        }
                        $current_date = gmdate('Y-m-d');

                        /* set timezone as per local_timezone_name */
                        $set_timezone = !empty($slot['local_timezone']) ? $slot['local_timezone'] : get_timezone_from_text($local_timezone_name, $date);
                        $datetime_including_timezone = get_datetime_including_timezone($set_timezone);
                        if ($is_cut_off_time == 1 && isset($cut_off_time) && $cut_off_time != '') { /* cut_off_time case */
                            $actual_time = $datetime_including_timezone;
                            $with_cut_off_time = vsprintf(" +%d hours +%d minutes +%d seconds", explode(':', $cut_off_time));
                            $current_time = strtotime(gmdate('Y-m-d H:i:s', strtotime($actual_time . $with_cut_off_time)));
                        } else {
                            $current_time = strtotime($datetime_including_timezone);
                        }
                        $response[$array_key]['product_id'] = $requested_data['product_id'];
                        $response[$array_key]['date'] = $date;
                        $response[$array_key]['from_time'] = $slot['from_time'];
                        $response[$array_key]['to_time'] = $slot['to_time'];
                        $response[$array_key]['timeslot_type'] = $slot['timeslot_type'];
                        $response[$array_key]['is_active'] = $slot['is_active'];
                        $response[$array_key]['local_timezone'] = !empty($slot['local_timezone']) ? $slot['local_timezone'] : $set_timezone;
                        $response[$array_key]['is_shared_capacity'] = !empty($slot['shared_capacity_id']) ? $slot['shared_capacity_id'] : $mec_data['shared_capacity_id'];
                        $response[$array_key]['third_party_timeslot_id'] = isset($slot['third_party_timeslot_id']) ? $slot['third_party_timeslot_id'] : 0;
                        $check_ticket_time = 0;
                        /* specifying timeslot to get the availabiligty count */
                        if ($slot['timeslot_type'] == 'day' || $slot['timeslot_type'] == 'specific') {
                            $check_ticket_time = $slot['to_time'];
                        } else {
                            $check_ticket_time = $slot['from_time'];
                        }
                        /* Generate the vacancy of the date and timeslot */
                        if (in_array($requested_data['product_id'], array(993, 994)) && strtotime($current_date) == strtotime($date) && strtotime('16:00') < $current_time) {
                            $response[$array_key]['vacancies'] = (int) 0;
                        } else if ((isset($slot['is_active_slot']) && $slot['is_active_slot'] == '0') || ($slot['timeslot_type'] == 'day' && strtotime($slot['to_time']) < $current_time && strtotime($date) == strtotime($current_date)) || (isset($slot['is_active']) && $slot['is_active'] == '0')) { /* if end time is less then current time for current date of date type products */
                            $response[$array_key]['vacancies'] = (int) 0;
                        } else if ($ticket_class == '0' && (strtotime($ticket_start_date) <= strtotime($date) && strtotime($date) <= $ticket_end_date) && strtotime($date . ' ' . $check_ticket_time . ":00") > $current_time) {
                            $response[$array_key]['vacancies'] = (int) $vacancies;
                        } else if (strtotime($date . ' ' . $check_ticket_time . ":00") > $current_time) { /* check condition for cut off time and if it not cutt of time ticket then check with current time */
                            $response[$array_key]['vacancies'] = (int) $vacancies;
                        } else {
                            $response[$array_key]['vacancies'] = (int) 0;
                        }
                    } else { /* Set vacancy as 0 if is_active = 0 */
                        $response[$array_key]['product_id'] = $requested_data['product_id'];
                        $response[$array_key]['date'] = $date;
                        $response[$array_key]['is_active'] = $slot['is_active'];
                        $response[$array_key]['from_time'] = ($slot['from_time'] === '0') ? '00:00' : $slot['from_time'];
                        $response[$array_key]['to_time'] = ($slot['to_time'] === '0') ? '00:00' : $slot['to_time'];
                        $response[$array_key]['local_timezone'] = !empty($slot['local_timezone']) ? $slot['local_timezone'] : get_timezone_from_text($local_timezone_name, $date);
                        $response[$array_key]['timeslot_type'] = !empty($slot['timeslot_type']) ? $slot['timeslot_type'] : "specific";
                        $response[$array_key]['vacancies'] = (int) 0;
                        $response[$array_key]['is_reservaton'] = $ticket_class;
                    }
                    $array_key++;
                }
            }
        }
        return $response;
    }
    
    /**
    * @Name        : generate_gyg_notify_request
    * @Purpose     : generate the request that we have to send to GYG
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> 2020-04-14
    */
    function generate_gyg_notify_request($data = [], $get_redis_data = [])
    {
        $response = $response['data']['availabilities'] = array();
        if(!empty($data) && !empty($get_redis_data)){
            foreach($get_redis_data as $key => $available_data){
                if(!empty($available_data['timeslot_type'])){
                    $response['data']['productId'] = $get_redis_data[$key]['product_id'];
                    $response['data']['availabilities'][$key]['vacancies'] = $available_data['vacancies'];
                    if($data['is_reservation'] == '1' || $available_data['timeslot_type'] == 'day'){
                        $response['data']['availabilities'][$key]['dateTime'] = $available_data['date'] . 'T00:00:00' . $available_data['local_timezone'];
                        $response['data']['availabilities'][$key]['openingTimes'][] = array(
                            'fromTime' => $available_data['from_time'],
                            'toTime' => $available_data['to_time']
                        );
                    } else {
                        if($available_data['timeslot_type'] == 'specific'){
                            $available_time = $available_data['to_time'];
                        } else {
                            $available_time = $available_data['from_time'];
                        }
                        $response['data']['availabilities'][$key]['dateTime'] = $available_data['date'] . 'T' . $available_time . ':00' . $available_data['local_timezone'];
                    }
                }
            }
        }
        sort($response['data']['availabilities']);
        return $response;
    }
}

?>
