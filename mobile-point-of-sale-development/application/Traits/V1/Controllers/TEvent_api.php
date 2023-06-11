<?php 
namespace Prio\Traits\V1\Controllers;

trait TEvent_Api {
    function __construct() {
        parent::__construct();
        $this->load->model('V1/Event_Api_Model');
    }

    /* #region event process queue module. */

    /**
     * @Name : process_queue()
     * @Purpose : to process the request in the queue name : API_NOTIFICATION_PROCESS
     * @Call from : Call from queue hit by API Server.
     * @Functionality : Notify GYG for ticket availiabilty updation.
     * @Created : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     * @Modified : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    public function process_queue($data = '', $messages = '', $rabbit = false) {
        try {
            require_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $postBody = file_get_contents('php://input');

            if (!empty($messages) && $rabbit === true) {
                $queue_messages = array(array("Body" => $messages));
            } else if (strtoupper(LOCAL_ENVIRONMENT) == 'LOCAL') {
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
                    if (strtoupper(LOCAL_ENVIRONMENT) == 'LOCAL') {
                        $data_string = gzuncompress(base64_decode($message));
                    } else {
                        $data_string = gzuncompress(base64_decode($message['Body']));
                    }
                    $data_string = utf8_decode($data_string);
                    $data = json_decode($data_string, TRUE);
                    if (!empty($data)) {
                        $request_type = (isset($data['request_type']) && !empty($data['request_type'])) ? $data['request_type'] : "";
                        if (strtoupper($request_type) == "AVAILABILITY_NOTIFICATON") {
                            $this->availability_notificatons($data);
                        } else if ($data['action'] == "travelbox_curl_timeout") {
                            /*
                              Call this function when OTA call for cancel booking and in confirm booking there was curl timeout in case of TravelBox
                              In TPPO there will be entry with STATUS =  4 thirdpart_refund.[Travelbox] Need to handle OTAX_BookConfirmRQ  timeout in both v2.6 & another OTA (GYG & v2.4)
                             */
                            $this->Event_Api_model->get_constant();
                            $this->Event_Api_model->send_cancel_booking_email_TP($data);
                        }
                    }
                    if (strtoupper(LOCAL_ENVIRONMENT) != 'LOCAL' && $rabbit === false) {
                        $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    }
                }
            }
        } catch (Exception $e) {
            $event_logs['operation_id'] = "API_EVENT_EXCEPTION";
            $event_logs['API'] = "OTA_NOTIFICATION_EVENT";
            $event_logs['internal_log'] = 1;
            $event_logs['error_code'] = "EXCEPTION";
            $event_logs['http_status'] = 500;
            $event_logs['exception_details'] = json_encode($e->getMessage());
            $event_logs['created_date_time'] = gmdate('Y-m-d H:i:s');
            $this->apilog_library->write_log($event_logs, 'mainLog');
        }
    }

    /* #endregion event process queue module. */

    /* #region Availabilty Notification. */

    /**
     * @Name        : availability_notificatons
     * @Purpose     : When we have to update the availability at OTA so need to notify them about this updations
     * $request params : $params
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function availability_notificatons($params = []) {
        try {
            $this->load->helper('curl_request_helper');
            if (!empty($params)) {
                $params['product_id'] = empty($params['product_id']) ? $params['ticket_id'] : $params['product_id'];
                $params['from_date'] = empty($params['from_date']) ? $params['selected_date'] : $params['from_date'];
                $params['to_date'] = empty($params['to_date']) ? $params['end_date'] : $params['to_date'];
                $get_constants['const_name'] = ['GYG_TICKETS_LIST', 'GYG_INACTIVE_PRODUCTS', 'CAPACITY_FROM_REDIS', 'CRON_REDIS_SERVER', 'REDIS_AUTH_KEY', 'ENVIRONMENT', 'GYG_SERVER', 'GYG_AUTH_CSS', 'GYG_AUTH'];
                $constants = $this->Event_Api_model->get_constant_from_api($get_constants);
                if (!empty($constants) && $constants['CAPACITY_FROM_REDIS'] != 'INVALID CONSTANT') {
                    $total_availability = $this->send_redis_data($params, $constants);
                    if (!empty($total_availability)) {
                        $available_capacity = !empty($total_availability['data'][$params['shared_capacity_id']]) ? $total_availability['data'][$params['shared_capacity_id']] : [];
                        if ($params['is_own_capacity'] == 3 && $params['own_capacity_id'] > 0) {
                            $own_data = !empty($total_availability['data'][$params['own_capacity_id']]) ? $total_availability['data'][$params['own_capacity_id']] : [];
                            $available_capacity = $this->compare_both_type_sharedcapacity($own_data, $available_capacity);
                        }
                        if (empty($available_capacity['curl_errno']) || (isset($available_capacity['curl_errno']) && $available_capacity['curl_errno'] != 28)) {
                            $mec_data = $this->Event_Api_model->get_mec_data($params['product_id']);
                            $response = $this->generate_availability($available_capacity, $mec_data, $params);
                        }
                        $gyg_actual_products = array_keys($constants['GYG_TICKETS_LIST']);
                        $active_gyg_products = array_diff($gyg_actual_products, $constants['GYG_INACTIVE_PRODUCTS']);
                        if (!empty($response) && in_array($params['product_id'], $active_gyg_products)) {
                            $generate_gyg_notify_request = $this->generate_gyg_notify_request($constants['GYG_TICKETS_LIST'][$params['product_id']], $response);
                            $event_logs = [];
                            $event_logs['operation_id'] = "GENERATE_NOTIFY_REQUEST";
                            $event_logs['API'] = "OTA_NOTIFICATION_EVENT";
                            $event_logs['internal_log'] = 0;
                            $event_logs['http_status'] = 200;
                            $event_logs['full_message'] =json_encode($generate_gyg_notify_request);
                            $event_logs['created_date_time'] = gmdate('Y-m-d H:i:s');
                            $this->apilog_library->write_log($event_logs, 'mainLog');
                            if (!empty($generate_gyg_notify_request['data']['availabilities'])) {
                                if ($mec_data['third_party_id'] == 5 || $mec_data['second_party_id'] == 5) {
                                    $gyg_auth = $constants['GYG_AUTH_CSS'];
                                } else {
                                    $gyg_auth = $constants['GYG_AUTH'];
                                }
                                $gyg_request_data = [
                                    'end_point' => $constants['GYG_SERVER'] . '/1/notify-availability-update',
                                    'request' => $generate_gyg_notify_request,
                                    'headers' => 'Content-Type: application/json',
                                    'method' => 'POST',
                                    'basic_auth' => 1,
                                    'auntherisation' => $gyg_auth,
                                    'timeout' => 1000
                                ];
                                $event_logs = [];
                                $event_logs['operation_id'] = "GYG_NOTIFY_REQUEST";
                                $event_logs['API'] = "OTA_NOTIFICATION_EVENT";
                                $event_logs['internal_log'] = 0;
                                $event_logs['http_status'] = 200;
                                $event_logs['full_message'] =json_encode($gyg_request_data);
                                $event_logs['created_date_time'] = gmdate('Y-m-d H:i:s');
                                $this->apilog_library->write_log($event_logs, 'mainLog');
                                if (strtoupper(LOCAL_ENVIRONMENT) == 'LIVE') {
                                    $get_gyg_data = SendRequest::send_request($gyg_request_data);
                                }
                            }
                            $event_logs = [];
                            $event_logs['operation_id'] = "GYG_NOTIFY_RESPONSE";
                            $event_logs['API'] = "OTA_NOTIFICATION_EVENT";
                            $event_logs['internal_log'] = 0;
                            $event_logs['http_status'] = 200;
                            $event_logs['full_message'] =json_encode($get_gyg_data);
                            $event_logs['created_date_time'] = gmdate('Y-m-d H:i:s');
                            $this->apilog_library->write_log($event_logs, 'mainLog');
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $event_logs = [];
            $event_logs['operation_id'] = "GYG_NOTIFY_EXCEPTION";
            $event_logs['API'] = "OTA_NOTIFICATION_EVENT";
            $event_logs['internal_log'] = 1;
            $event_logs['http_status'] = 500;
            $event_logs['full_message'] =json_encode($e->getMessage());
            $event_logs['created_date_time'] = gmdate('Y-m-d H:i:s');
            $this->apilog_library->write_log($event_logs, 'mainLog');
        }
    }

    /* #endregion availabilty notification. */

    /* #region send redis data. */

    /**
     * @Name        : send_redis_data
     * @Purpose     : generate data that we have to send to redis to get data from redis
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function send_redis_data($params = [], $constants = []) {
        $get_redis_data['response'] = [];
        if (!empty($params) && !empty($constants)) {
            if ($params['is_own_capacity'] == 3 && $params['own_capacity_id'] > 0) { /* Check if ticket is Both type */
                $ticket_data[] = array('ticket_id' => $params['ticket_id'], 'shared_capacity_id' => $params['shared_capacity_id'], "from_date" => $params['from_date'], "to_date" => $params['to_date']);
                $ticket_data[] = array('ticket_id' => $params['ticket_id'], 'shared_capacity_id' => $params['own_capacity_id'], "from_date" => $params['from_date'], "to_date" => $params['to_date']);
            } else {
                $ticket_data[] = array('ticket_id' => $params['ticket_id'], 'shared_capacity_id' => $params['shared_capacity_id'], "from_date" => $params['from_date'], "to_date" => $params['to_date']);
            }
            $redis_headers = $this->set_headers([], $constants['REDIS_AUTH_KEY']);
            $redis_request_data = [
                'end_point' => $constants['CRON_REDIS_SERVER'] . '/listcapacitymultiple',
                'request' => $ticket_data,
                'headers' => $redis_headers,
                'method' => 'POST'
            ];
            $get_redis_data = SendRequest::send_request($redis_request_data);
        }
        return $get_redis_data['response'];
    }

    /* #endregion send redis data. */

    /* #region set headers data. */

    /**
     * @Name        : set_headers
     * @Purpose     : if we get any specific headers that we have to send to redis
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function set_headers($header_request = array(), $redis_auth_key = '') {
        $headers = array();
        if (!empty($header_request)) {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . (!empty($redis_auth_key) ? $redis_auth_key : (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)),
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
                'Authorization: ' . (!empty($redis_auth_key) ? $redis_auth_key : (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY))
            );
        }
        return $headers;
    }

    /* #endregion set headers data. */

    /* #region compare both type shared capacity data. */

    /**
     * @Name        : compare_both_type_sharedcapacity
     * @Purpose     : when we get both type product then we have to generate the vacancies as per both the shared_Capacity is
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    public function compare_both_type_sharedcapacity($owned_capacity_array, $shared_capacity_array) {
        if ((isset($owned_capacity_array['curl_errno']) && $owned_capacity_array['curl_errno'] == 28) || (isset($shared_capacity_array['curl_errno']) && $shared_capacity_array['curl_errno'] == 28)) {
            $owned_capacity_array['curl_errno'] = 28;
        } else {
            /* for lopp for Owned Capacity Timeslot */
            foreach ($owned_capacity_array['data'] as $key => $value_owned) {
                /* for loop for owned capacity timeslot */
                foreach ($value_owned['timeslots'] as $key_time => $timeslots_owned) {
                    /* For loop for shared capacity timeslot */
                    foreach ($shared_capacity_array['data'] as $value_shared) {
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

    /* #endregion compare both type shared capacity data. */


    /* #region generate availabilty. */

    /**
     * @Name        : generate_availability
     * @Purpose     : generate the standard avalability response as per redis response, i.e. calculate vacancies on the basis of each parameter required
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function generate_availability($redis_response_data = [], $mec_data = [], $requested_data = []) {
        if (!empty($redis_response_data)) {
            $counts = $total_array = $response = [];
            foreach ($redis_response_data as $row) {
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
                if (!empty($slots)) {
                    foreach ($slots as $slot) {
                        if (!empty($slot)) {
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
                                $set_timezone = !empty($slot['local_timezone']) ? $slot['local_timezone'] : $this->get_timezone_from_text($local_timezone_name, $date);
                                $datetime_including_timezone = $this->get_datetime_including_timezone($set_timezone);
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
                                $response[$array_key]['local_timezone'] = !empty($slot['local_timezone']) ? $slot['local_timezone'] : $this->get_timezone_from_text($local_timezone_name, $date);
                                $response[$array_key]['timeslot_type'] = !empty($slot['timeslot_type']) ? $slot['timeslot_type'] : "specific";
                                $response[$array_key]['vacancies'] = (int) 0;
                                $response[$array_key]['is_reservaton'] = $ticket_class;
                            }
                            $array_key++;
                        }
                    }
                }
            }
        }
        return $response;
    }

    /* #endregion generate availabilty. */

    /* #region get timezone from text. */

    /**
     * @Name        : get_timezone_from_text
     * @Purpose     : function to get timezone details froom text.
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function get_timezone_from_text($timezone = 'Europe/Amsterdam', $date_to_check = "") {
        if (empty($date_to_check) || strtotime($date_to_check) <= 0) {
            $date_to_check = date("Y-m-d h:i:s");
        } else {
            $date_to_check = date("Y-m-d h:i:s", strtotime($date_to_check));
        }
        if (empty($timezone)) {
            $timezone = $this->get_timezone_of_date($date_to_check);
        }
        $date = new DateTime($date_to_check, new DateTimeZone($timezone));
        return $date->format('P');
    }

    /* #endregion get timezone from text. */

    /* #region get datetime including timezone . */

    /**
     * @Name        : get_datetime_including_timezone
     * @Purpose     : Get the correct date time after adding timezone in UTC date time 
     * @param type date and time after timezone
     * @return string
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function get_datetime_including_timezone($time, $date = '') {
        $flag = '+';
        $finaldate = '';
        if (!empty($time)) {
            if (strstr($time, '-')) {
                $flag = '-';
                $time = str_replace('-', '', $time);
            }
            $time = explode(':', $time);
            $minutes = ($time[0] * 60) + (isset($time[1]) ? $time[1] : 0) + (isset($time[2]) ? ($time[2] / 60) : 0);
        } else {
            $minutes = 0;
        }
        if ($date != '') {
            $finaldate = gmdate("Y-m-d H:i:s", strtotime("$flag $minutes minutes", strtotime($date)));
        } else {
            $finaldate = gmdate("Y-m-d H:i:s", strtotime("$flag $minutes minutes"));
        }
        return $finaldate;
    }

    /* #region get datetime including timezone . */

    /* #region generate gyg notify request. */

    /**
     * @Name        : generate_gyg_notify_request
     * @Purpose     : generate the request that we have to send to GYG
     * @CreatedBy : Neha <nehadev.aipl@gmail.com> on 02 July 2021
     */
    function generate_gyg_notify_request($data = [], $get_redis_data = []) {
        $response = $response['data']['availabilities'] = array();
        if (!empty($data) && !empty($get_redis_data)) {
            foreach ($get_redis_data as $key => $available_data) {
                if (!empty($available_data['timeslot_type'])) {
                    $response['data']['productId'] = $get_redis_data[$key]['product_id'];
                    $response['data']['availabilities'][$key]['vacancies'] = $available_data['vacancies'];
                    if ($data['is_reservation'] == '1' || $available_data['timeslot_type'] == 'day') {
                        $response['data']['availabilities'][$key]['dateTime'] = $available_data['date'] . 'T00:00:00' . $available_data['local_timezone'];
                        $response['data']['availabilities'][$key]['openingTimes'][] = array(
                            'fromTime' => $available_data['from_time'],
                            'toTime' => $available_data['to_time']
                        );
                    } else {
                        if ($available_data['timeslot_type'] == 'specific') {
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

    /* #endregion generate gyg notify request. */
}
?>