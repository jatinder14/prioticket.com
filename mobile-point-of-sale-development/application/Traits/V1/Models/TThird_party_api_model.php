<?php

namespace Prio\Traits\V1\Models;

trait TThird_party_api_model {
     /* variable ends here */

     function __construct() {
        // Call the Model constructor
        parent::__construct();
        $this->tp_tickets_details = json_decode(TP_NATIVE_INFO, true);
        $this->ITICKET_SERVER = ITICKET_SERVER;
        $this->ITICKET_SERVER_LOCATION = ITICKET_SERVER_LOCATION;
    }

    /* #region Third Party Module  : This module covers third party api's  */

    /**
     * @name: get_iticket_availability
     * @purpose: To check availability for Itickets
     * @ Firebase Updations
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Sep 2, 2017
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_iticket_availability($info) {
        global $MPOS_LOGS;
        $TimetableQuery = array();
        $iticket_api_key = isset($info['third_party_details']['third_party_account']) ? $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5'][$info['third_party_details']['third_party_account']] : $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5']['prio'];
        /* Code to get capacity from iTicket APIs */
        $client = \SoapClient($this->ITICKET_SERVER, array('trace' => true, 'location' => $this->ITICKET_SERVER_LOCATION));
        if(!empty($info['third_party_details']['iticket_location_code'])){
            $TimetableQuery['LocationFilter']['Code'] = $info['third_party_details']['iticket_location_code'];
        }
        $TimetableQuery['ProductFilter']['Code'] = $info['third_party_details']['iticket_product_id'];
        $TimetableQuery['StartingAfter'] =  $info['selected_date'] . 'T00:00:00Z';
        $TimetableQuery['StartingUntil'] = $info['selected_date'] . 'T23:59:00Z';
        $params = array(
            'ApiKey' => $iticket_api_key,
            'TimetableQuery' => $TimetableQuery
        );
        $logs['SearchCalendar_req_' . date('H:i:s')] = json_encode($params);
        $response = $client->SearchCalendar($params);
        $logs['SearchCalendar_res_' . date('H:i:s')] = json_encode($response);

        if (!empty($response)) {
            $response = json_encode($response);
            $data = json_decode($response, true);
            $final = array();
            if (!empty($data['SearchCalendarResult']['Events']['Event'][0])) {
                foreach ($data['SearchCalendarResult']['Events']['Event'] as $event) {
                    $new_timeslot_datetime = date('Y-m-d H:i:s', strtotime($event['DateTime']));
                    if(isset($final[$event['ProductCodes']['string']][date('Y-m-d', strtotime($new_timeslot_datetime))][$new_timeslot_datetime]) &&  
                        $final[$event['ProductCodes']['string']][date('Y-m-d', strtotime($new_timeslot_datetime))][$new_timeslot_datetime]["AvailableCount"] < $event['Activities']['Activity']['AvailableCount']
                    ) {
                        continue;
                    }
                    $final[$event['ProductCodes']['string']][date('Y-m-d', strtotime($new_timeslot_datetime))][$new_timeslot_datetime] = array(
                        'DateTime' => $new_timeslot_datetime,
                        'Duration' => $event['Duration'],
                        'AvailableCount' => $event['Activities']['Activity']['AvailableCount'],
                        'ReservedCount' => $event['Activities']['Activity']['ReservedCount'],
                        'TourCode' => $event['TourCode'],
                        'OffsetMinutes' => 0
                    );
                }
            } else {
                $session = $data['SearchCalendarResult']['Events']['Event'];
                $new_timeslot_datetime = date('Y-m-d H:i:s', strtotime($session['DateTime']));
                $final[$session['ProductCodes']['string']][date('Y-m-d', strtotime($new_timeslot_datetime))][$new_timeslot_datetime] = array(
                    'DateTime' => $new_timeslot_datetime,
                    'Duration' => $session['Duration'],
                    'AvailableCount' => $session['Activities']['Activity']['AvailableCount'],
                    'ReservedCount' => $session['Activities']['Activity']['ReservedCount'],
                    'TourCode' => $session['TourCode'],
                    'OffsetMinutes' => 0
                );
            }
            if (!empty($final[$info['third_party_details']['iticket_product_id']])) {
                foreach ($final[$info['third_party_details']['iticket_product_id']] as $date => $slots) {
                    $availability_response[$date]['date'] = $date;
                    $response = array(
                        'date' => $date,
                        'timeslots' => array()
                    );
                    if (!empty($slots)) {
                        $response['status'] = (int) 1;
                        foreach ($slots as $slot) {

                            $iticket_from_time = date('H:i', strtotime($slot['DateTime']));
                            if ($iticket_from_time != '00:00') {
                                $timeslot_type = 'specific';
                                $to_time = $iticket_from_time;
                                $from_time = date('H:i', strtotime($date . ' ' . $iticket_from_time . ' -15 minutes'));
                            } else {
                                $timeslot_type = 'day';
                                $from_time = $iticket_from_time;
                                $to_time = date('23:59', strtotime($slot['DateTime']));
                            }
                            $response['timeslots'][] = array(
                                'from_time' => $from_time,
                                'to_time' => $to_time,
                                'total_capacity' => is_null($slot['AvailableCount']) ? $this->default_capacity : $slot['AvailableCount'],
                                'bookings' => 0,
                                'type' => $timeslot_type,
                                'is_active' => true,
                            );
                        }
                    }
                }
            } else {
                $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', 'There is no availability for ' . $info['selected_date']);
            }
        } else {
            $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', 'There is no availability for ' . $info['selected_date']);
        }
        $logs['get_iticket_availability_res_' . date('H:i:s')] = $response;
        $MPOS_LOGS['third_party_tickets_availability']['get_iticket_availability'] = $logs;
        return $response;
    }

    /**
     * @name: get_gt_availability
     * @purpose: To check availability for GT tickets
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Sep 2, 2017
     * @updatedOn 23 april 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_gt_availability($request) {
        global $MPOS_LOGS;
        $url = GT_AVAILABILITY_API_URL;
        try {
            if (isset($request['ticket_id']) && isset($request['third_party_details']) && $request['selected_date']) {
                $third_party_params = $request['third_party_details'];
                $third_party_account_details = $this->tp_tickets_details['gt'][$third_party_params['third_party_account']];
                $sha256_array = array(
                    'apiKey' => $third_party_account_details['api_key'],
                    'endDate' => $request['selected_date'],
                    'environment' => GT_ENVIRONMENT,
                    'startDate' => $request['selected_date'],
                    'userId' => $third_party_params['user_id']
                );
                $req_data = array(
                    'apiKey' => $third_party_account_details['api_key'],
                    'endDate' => $request['selected_date'],
                    'environment' => GT_ENVIRONMENT,
                    'startDate' => $request['selected_date'],
                    'userId' => $third_party_params['user_id'],
                    'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $third_party_account_details['secret_key']))
                );
                $logs['gt_availability_req_' . date('H:i:s')] = json_encode($req_data);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_POST, count($req_data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
                $data = curl_exec($ch);
                $logs['gt_availability_res_' . date('H:i:s')] = $data;
                $data = json_decode($data, true);
                if ($data['success'] == 'true') {
                    if (!empty($data['availability'])) {
                        if ($data['availabilityType'] == "timeslots") {
                            foreach ($data['availability'] as $availability) {
                                foreach ($availability as $timeslot => $value) {
                                    $timeslots[] = array(
                                        'from_time' => date("H:i", strtotime($timeslot, '-15 minutes')),
                                        'to_time' => $timeslot,
                                        'total_capacity' => ($value == 1) ? (int) 500 : (int) 0,
                                        'bookings' => ($value == 1) ? (int) 0 : (int) 500,
                                        'type' => 'specific',
                                        'is_active' => ($value == 1) ? true : false
                                    );
                                }
                            }
                        } else if ($data['availabilityType'] == "date" && (in_array($request['selected_date'], $data['availability']))) {
                            $timeslots[] = array(
                                'from_time' => "10:00",
                                'to_time' => "17:00",
                                'total_capacity' => (int) 500,
                                'bookings' => (int) 0,
                                'type' => 'day',
                                'is_active' => true
                            );
                        } else {
                            $response['status'] = (int) 0;
                            $response['errorMessage'] = "No Availability for selected date";
                        }
                    } else {
                        $response['status'] = (int) 0;
                        $response['errorMessage'] = "No Availability";
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['message'] = "Invalid data";
                }
            } else {
                $response = $this->exception_handler->show_error();
            }
            if (isset($timeslots) && !empty($timeslots)) {
                $response = array(
                    'status' => (int) 1,
                    'date' => $request['selected_date'],
                    'timeslots' => $timeslots
                );
            }
            $logs['response_' . date('H:i:s')] = $response;
        } catch (SoapFault $ex) {
            $response['status'] = (int) 0;
            $response['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $MPOS_LOGS['third_party_tickets_availability']['get_gt_availability'] = $logs;
        return $response;
    }

    /**
     * @name: search_price
     * @purpose: To get data from SearchPrice Third Party API
     * @called_within: send_iticket_booking_request()
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 9 jan 2019
     */
    function search_price($third_party_account = '', $location_code = '', $product_code = '', $article_code = '',  $selected_date = '', $selected_from_timeslot_with_date = '') {
        global $MPOS_LOGS;
        $iticket_api_key = isset($third_party_account) ? $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5'][$third_party_account] : $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5']['prio'];
        $client = new \SoapClient($this->ITICKET_SERVER, array('location' => $this->ITICKET_SERVER_LOCATION));
        if (isset($location_code) && $location_code != '') {
            $PriceQuery['LocationFilter']['Code'] = $location_code;
        }
        $time_slot = explode("T", $selected_from_timeslot_with_date);
        $from_time = end($time_slot); 
        $to_time = ($from_time == '00:00') ?  '23:59' : $from_time;
        $PriceQuery['ProductFilter']['Code'] = $product_code;
        $PriceQuery['StartingAfter'] = $selected_date . 'T'. $from_time .'Z';
        $PriceQuery['StartingUntil'] = $selected_date . 'T'. $to_time .'Z';
        $params = array(
            'ApiKey' => $iticket_api_key,
            'PriceQuery' => $PriceQuery
        );
        $logs['SearchPrice_req_' . date('H:i:s')] = json_encode($params);
        $SearchPrice = $client->SearchPrice($params);
        $search_price_result = json_encode($SearchPrice);
        $search_price_result = json_decode($search_price_result, TRUE);
        $logs['SearchPrice_res_' . date('H:i:s')] = json_encode($SearchPrice);
        $location_tour_ids = $tour_ids = $SearchPriceResult_ArticleAvailabilities = $filtered_TourId = array();
        $iticket_tours = array();
        /* if only has one Tour in response then set at 0 index. */
        if (isset($search_price_result['SearchPriceResult']['Tours']['Tour'][0])) {
            $iticket_tours = $search_price_result['SearchPriceResult']['Tours']['Tour'];
        } else {
            $iticket_tours[0] = $search_price_result['SearchPriceResult']['Tours']['Tour'];
        }
        /* prepare array bases on location code and datetime so that can be used to get quote of correspoding location and timeslot. */
        $iticket_location_codes = array();
        if (!empty($iticket_tours)) {
            if (!empty($article_code) && !empty($search_price_result['SearchPriceResult']['ArticleAvailabilities']['ArticleAvailabilty'])) {
                if (isset($search_price_result['SearchPriceResult']['ArticleAvailabilities']['ArticleAvailabilty'][0])) {
                    $SearchPriceResult_ArticleAvailabilities = $search_price_result['SearchPriceResult']['ArticleAvailabilities']['ArticleAvailabilty'];
                } else {
                    $SearchPriceResult_ArticleAvailabilities[] = $search_price_result['SearchPriceResult']['ArticleAvailabilities']['ArticleAvailabilty'];
                }
            }
            if (!empty($SearchPriceResult_ArticleAvailabilities)) {
                foreach ($SearchPriceResult_ArticleAvailabilities as $SearchPriceResult_ArticleAvailability) {
                    if ($SearchPriceResult_ArticleAvailability['ArticleCode'] == $article_code) {
                        $filtered_TourId[] = $SearchPriceResult_ArticleAvailability['TourId'];
                    }
                }
            }
            foreach ($iticket_tours as $tours) {
                if (isset($tours['LocationCodes'][0])) {
                    $iticket_location_codes = $tours['LocationCodes'];
                } else {
                    $iticket_location_codes[0] = $tours['LocationCodes'];
                }
                if (!empty($iticket_location_codes)) {
                    foreach ($iticket_location_codes as $tour_location_codes) {
                        if ($tours['DateTime'] == $selected_from_timeslot_with_date) {
                            if (empty($filtered_TourId)) {
                                $tour_ids[$tours['DateTime'] . '_' . $tour_location_codes['Code']] = $tours['TourId'];
                                $location_tour_ids[$tour_location_codes['Code']][] = $tours['TourId'];
                            } else {
                                if (in_array($tours['TourId'], $filtered_TourId)) {
                                    $tour_ids[$tours['DateTime'] . '_' . $tour_location_codes['Code']] = $tours['TourId'];
                                    $location_tour_ids[$tour_location_codes['Code']][] = $tours['TourId'];
                                }
                            }
                        }
                    }
                }
            }
        }
        /* if only has one quote in response then set at 0 index. */
        $search_quote = array();
        if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote'][0])) {
            foreach ($search_price_result['SearchPriceResult']['Quotes']['Quote'] as $all_Quotes) {
                if (isset($all_Quotes['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount']) && ($all_Quotes['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount'] > 0) && empty($filtered_TourId)) {
                    $search_quote[] = $all_Quotes;
                } else if (!empty($filtered_TourId)) {
                    if (isset($all_Quotes['QuoteItems']['QuoteItem'][0])) {
                        $QuoteItems = $all_Quotes['QuoteItems']['QuoteItem'];
                    } else {
                        $QuoteItems[0] = $all_Quotes['QuoteItems']['QuoteItem'];
                    }
                    if (!empty($QuoteItems) && ($all_Quotes['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount'] > 0)) {
                        foreach ($QuoteItems as $QuoteItem) {
                            if (in_array($QuoteItem['TourId'], $filtered_TourId)) {
                                $search_quote[] = $all_Quotes;
                            }
                        }
                    }
                } else {
                    $error = 1;
                }
            }
        } else if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote']) && !empty($search_price_result['SearchPriceResult']['Quotes']['Quote'])) {
            if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote']['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount']) && ($search_price_result['SearchPriceResult']['Quotes']['Quote']['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount'] > 0) && empty($filtered_TourId)) {
                $search_quote[0] = $search_price_result['SearchPriceResult']['Quotes']['Quote'];
            } else if (!empty($filtered_TourId)) {
                if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote']['QuoteItems']['QuoteItem'][0])) {
                    $QuoteItems = $search_price_result['SearchPriceResult']['Quotes']['Quote']['QuoteItems']['QuoteItem'];
                } else {
                    $QuoteItems[0] = $search_price_result['SearchPriceResult']['Quotes']['Quote']['QuoteItems']['QuoteItem'];
                }
                if (!empty($QuoteItems) && ($search_price_result['SearchPriceResult']['Quotes']['Quote']['ParticipantAvailabilities']['ParticipantAvailabilty']['AvailableCount'] > 0)) {
                    foreach ($QuoteItems as $QuoteItem) {
                        if (in_array($QuoteItem['TourId'], $filtered_TourId)) {
                            $search_quote[] = $search_price_result['SearchPriceResult']['Quotes']['Quote'];
                        }
                    }
                }
            } else {
                $error = 1;
            }
        }
        $search_price_result['SearchPriceResult']['Quotes']['Quote'] = $product_slot_indexes = $article_codes = [];
        /* error = 0 means availlability count is possitive */
        if ($error == 0) {
            /* if tour id found of requsted timeslot and location then get that tourid quote */
            if (!empty($location_code)) {
                if (isset($tour_ids[$selected_from_timeslot_with_date . '_' . $location_code]) && (!empty($search_quote))) {
                        /* get quote of matching location. */
                        foreach ($search_quote as  $s_quote) {
                            if (strpos($s_quote['QuoteId'], $tour_ids[$selected_from_timeslot_with_date . '_' . $location_code]) !== false) {
                                $search_price_result['SearchPriceResult']['Quotes']['Quote'][0] = $s_quote;
                            }
                        }
                }
                if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote'][0])) {
                    $search_price_result['SearchPriceResult']['Quotes']['Quote'] = $search_price_result['SearchPriceResult']['Quotes']['Quote'][0];
                }
                $quote = $search_price_result['SearchPriceResult']['Quotes']['Quote'];
                $quotes_items = array();
                if (!empty($quote)) {
                    /* if only has one QuoteItem in response then set at 0 index. */
                    if (isset($quote['QuoteItems']['QuoteItem'][0])) {
                        $quotes_items = $quote['QuoteItems']['QuoteItem'];
                    } else {
                        $quotes_items[0] = $quote['QuoteItems']['QuoteItem'];
                    }
                    if (!empty($quotes_items)) {
                        foreach ($quotes_items as $quote_item) {
                            if ($quote_item['TourId'] == $tour_ids[$selected_from_timeslot_with_date . '_' . $location_code]) {
                                $product_slot_indexes[] = $quote_item['ProductSlotIndex'];
                            }
                        }
                    }
                }
            } else {
                if (!empty($tour_ids) && !empty($search_quote[0]['QuoteId'])) {
                    foreach ($tour_ids as $tourid) {
                        if (strpos($search_quote[0]['QuoteId'], $tourid) !== false) {
                            $search_price_result['SearchPriceResult']['Quotes']['Quote'][0] = $search_quote[0];
                            break;
                        }
                    }
                }
                if (isset($search_price_result['SearchPriceResult']['Quotes']['Quote'][0])) {
                    $search_price_result['SearchPriceResult']['Quotes']['Quote'] = $search_price_result['SearchPriceResult']['Quotes']['Quote'][0];
                }
                $quote = $search_price_result['SearchPriceResult']['Quotes']['Quote'];
                $quotes_items = array();
                if (!empty($quote)) {
                    /* if only has one QuoteItem in response then set at 0 index. */
                    if (isset($quote['QuoteItems']['QuoteItem'][0])) {
                        $quotes_items = $quote['QuoteItems']['QuoteItem'];
                    } else {
                        $quotes_items[0] = $quote['QuoteItems']['QuoteItem'];
                    }
                    if (!empty($quotes_items)) {
                        $all_tour_ids = array_values($tour_ids);
                        foreach ($quotes_items as $quote_item) {
                            if (in_array($quote_item['TourId'], $all_tour_ids)) {
                                $product_slot_indexes[] = $quote_item['ProductSlotIndex'];
                            }
                        }
                    }
                }
            }
            /* if only has one ProductSlot in response then set at 0 index. */
            $api_product_slots = array();
            if (isset($search_price_result['SearchPriceResult']['Products']['Product']['ProductSlots']['ProductSlot'][0])) {
                $api_product_slots = $search_price_result['SearchPriceResult']['Products']['Product']['ProductSlots']['ProductSlot'];
            } else if (isset($search_price_result['SearchPriceResult']['Products']['Product']['ProductSlots']['ProductSlot'])) {
                $api_product_slots[0] = $search_price_result['SearchPriceResult']['Products']['Product']['ProductSlots']['ProductSlot'];
            }
            $article_slots_arr = array();
            if (!empty($api_product_slots) && !empty($product_slot_indexes)) {
                foreach ($api_product_slots as $product_slots) {
                    foreach ($product_slot_indexes as $product_slot_index) {
                        if ($product_slot_index == $product_slots['ProductSlotIndex']) {
                            if (isset($product_slots['ArticleSlots']['ArticleSlot'][0])) {
                                $article_slots_arr = $product_slots['ArticleSlots']['ArticleSlot'];
                            } else {
                                $article_slots_arr[0] = $product_slots['ArticleSlots']['ArticleSlot'];
                            }
                            foreach ($article_slots_arr as $article_slots) {
                                $article_codes[$product_slot_index][] = $article_slots['ArticleCode'];
                            }
                        }
                    }
                }
            }
        }
        /* set article_codes, product_slot_indexes in search_price_result to use in further request CreateBooking,update_booking_request, RegisterPurchase */
        $search_price_result['article_codes'] = $article_codes;
        $search_price_result['product_slot_indexes'] = $product_slot_indexes;
        $logs['search_price_result'] = json_encode($search_price_result);
        $MPOS_LOGS['search_price'] = $logs;
        return $search_price_result;
    }

    /**
     * @name: send_iticket_booking_request
     * @purpose: to book iticket
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function send_iticket_booking_request($data) {
        global $MPOS_LOGS;
        $iticket_api_key = isset($data['third_party_details']['third_party_account']) ? $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5'][$data['third_party_details']['third_party_account']] : $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5']['prio'];
        if (PAYMENT_MODE == 'live' || PAYMENT_MODE == 'test') {
            try {
                if (strpos($data['selected_timeslot'], ' - ')) {
                    $timeslot = explode('-', $data['selected_timeslot']);
                    $data['selected_timeslot'] = trim($timeslot[0]);
                }
                /* Create SoapClient */
                $client = new \SoapClient($this->ITICKET_SERVER, array('location' => $this->ITICKET_SERVER_LOCATION));

                $third_party_account = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
                $location_code = isset($data['third_party_details']['iticket_location_code']) ? $data['third_party_details']['iticket_location_code'] : '';
                $product_slot_index = (int) isset($data['third_party_details']['product_slot_index']) ? $data['third_party_details']['product_slot_index'] : 1;
                $article_code = isset($data['third_party_details']['iticket_article_code']) ? $data['third_party_details']['iticket_article_code'] : '';
                $product_code = $data['third_party_details']['iticket_product_id'];
                $selected_date_timeslot = $data['selected_date'] . 'T' . $data['selected_timeslot'];
                $search_price_result = $this->search_price($third_party_account, $location_code, $product_code, $article_code, $data['selected_date'], $selected_date_timeslot);
                $quoteId = '';
                if(isset($search_price_result['SearchPriceResult']['Quotes']['Quote']['QuoteId'])) {
                   $quoteId =  $search_price_result['SearchPriceResult']['Quotes']['Quote']['QuoteId'];
                }
                $quote_id = isset($search_price_result['SearchPriceResult']['Quotes']['Quote'][0]) ? $search_price_result['SearchPriceResult']['Quotes']['Quote'][0]['QuoteId'] : $quoteId;
                if ($quote_id && !empty($search_price_result['SearchPriceResult']['Prices'])) {
                /* iTicket API call to create Order ID */
                $params = array(
                    'ApiKey' => $iticket_api_key,
                    'OrderReference' => 'PRIO_' . time()
                );

                $logs['CreateOrder_req_' . date('H:i:s')] = json_encode($params);
                $response = $client->CreateOrder($params);
                $logs['CreateOrder_res_' . date('H:i:s')] = json_encode($response);
                    if (isset($response->CreateOrderResult->OrderId) && $response->CreateOrderResult->OrderId != '') {

                    $order_id = $response->CreateOrderResult->OrderId;
                        $quote = array("QuoteId" => $quote_id, "QuoteItems" => array("QuoteItem" => array("ProductSlotIndex" => (int) $product_slot_index)));


                        /* iTicket CreateBooking API call to generate Booking ID */
                        $params = array(
                            'ApiKey' => $iticket_api_key,
                            'OrderId' => $order_id,
                            'Quote' => $quote
                        );
                        $logs['CreateBooking_req_' . date('H:i:s')] = json_encode($params);
                        $response = $client->CreateBooking($params);
                        $logs['CreateBooking_res_' . date('H:i:s')] = json_encode($response);
                        if ($response->CreateBookingResult->BookingId != '') {

                            $iticket_ticket_types = array();
                            $total_count = 0;
                            $ticket_types = $data['items'];
                            if (!empty($ticket_types)) {
                                foreach ($ticket_types as $ticket_type) {
                                    if ($ticket_type['category'] == 'adult') {
                                        $iticket_ticket_types['ticket_types']['ADULT'] = $ticket_type['count'];
                                    } else if ($ticket_type['category'] == 'child') {
                                        $iticket_ticket_types['ticket_types']['CHILD1'] = $ticket_type['count'];
                                    } else if ($ticket_type['category'] == 'infant') {
                                        $iticket_ticket_types['ticket_types']['CHILD2'] = $ticket_type['count'];
                                    } else {
                                        $iticket_ticket_types['ticket_types']['ADULT'] = $iticket_ticket_types['ticket_types']['adult'] + $ticket_type['count'];
                                    }
                                    $total_count += $ticket_type['count'];
                                }
                            }
                            $iticket_ticket_types['total_count'] = $total_count;
                            if (!empty($iticket_ticket_types)) {
                                foreach ($iticket_ticket_types['ticket_types'] as $ticket_type => $count) {
                                    $ParticipantConfiguration['ParticipantConfiguration'][] = array(
                                        'CategoryCode' => $ticket_type,
                                        'Count' => $count
                                    );
                                }
                            }
                            foreach ($search_price_result['article_codes'] as $product_slot_index => $article_codess) {
                                foreach ($article_codess as $article_code) {
                                    $ArticleConfiguration['ArticleConfiguration'][] = array(
                                        'ArticleCode' => $article_code,
                                        'Quantity' => $iticket_ticket_types['total_count'],
                                        'ProductSlotIndex' => $product_slot_index,
                                    );
                                }
                            }
                            /* iTicket API call to UpdateBooking */
                            $params = array(
                                'ApiKey' => $iticket_api_key,
                                'BookingId' => $response->CreateBookingResult->BookingId,
                                'BookingConfiguration' => array(
                                    'ArticleConfigurations' => $ArticleConfiguration,
                                    'ParticipantConfigurations' => $ParticipantConfiguration
                                ),
                            );
                            $logs['UpdateBookingRequest_req_' . date('H:i:s')] = json_encode($params);
                            $response = $client->UpdateBookingRequest($params);
                            $logs['UpdateBookingRequest_res_' . date('H:i:s')] = json_encode($response);
                            if ($response->UpdateBookingRequestResult->BookingId != '') {
                                $response_array['status'] = (int) 1;
                                $response_array['booking_id'] = $response->UpdateBookingRequestResult->BookingId;
                                $response_array['order_id'] = $order_id;
                            } else {
                                $response_array = $this->exception_handler->show_error();
                            }
                        } else {
                            $response_array = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Booking can't be created");
                        }
                    } else {
                        $response_array = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Invalid order id or api keys');
                    }
                } else {
                    $response_array = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', 'No timeslots available.');
                }
            } catch (SoapFault $ex) {
                $response_array['status'] = (int) 0;
                $response_array['errorMessage'] = $ex->getMessage();
            }
        }
        $logs['send_iticket_booking_request_res_' . date('H:i:s')] = $response_array;
        $MPOS_LOGS['reserve_third_party_tickets']['send_iticket_booking_request'] = $logs;
        return $response_array;
    }

    /**
     * @name: send_iticket_confirmation_request
     * @purpose: To confirm booking of iticket
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function send_iticket_confirmation_request($data) {
        global $MPOS_LOGS;
        $iticket_api_key = isset($data['third_party_details']['third_party_account']) ? $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5'][$data['third_party_details']['third_party_account']] : $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5']['prio'];
        $product_code = $data["third_party_details"]["iticket_product_id"];
        $article_code = isset($data["third_party_details"]["iticket_article_code"]) ? $data["third_party_details"]["iticket_article_code"] : '';
        $third_party_account = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
        if (PAYMENT_MODE == 'live' || PAYMENT_MODE == 'test') {
            try {
                /* Create SoapClient */
                $client = new \SoapClient($this->ITICKET_SERVER, array('trace' => true, 'location' => $this->ITICKET_SERVER_LOCATION));
                if ($data['booking_id'] != '') {
                    /* iTicket API call to CommitBookingRequest */
                    $params = array(
                        'ApiKey' => $iticket_api_key,
                        'BookingId' => (int) $data['booking_id'],
                        'PointOfSale' => 'Prioticket API',
                        'Booker' => 'Prioticket API'
                    );
                    $logs['CommitBooking_req_' . date('H:i:s')] = json_encode($params);
                    $response = $client->CommitBooking($params);
                    $logs['CommitBooking_res_' . date('H:i:s')] = json_encode($response);
                    $commit_booking_response = $this->common_model->object_to_array($response);
                    if (empty($commit_booking_response['CommitBookingResult']['BookingVersion']['BookingId'])) {
                        $response_array['status'] = (int) 0;
                        $response_array['errorCode'] = 'VALIDATION_FAILURE';
                        $response_array['errorMessage'] = $commit_booking_response['CommitBookingResult']['BookingVersion']['ValidationErrors'];
                    } else {
                        $prices = array();

                        $location_code = isset($data['third_party_details']['iticket_location_code']) ? $data['third_party_details']['iticket_location_code'] : '';
                        $selected_date_timeslot = $data['selected_date'] . 'T' . $data['selected_timeslot'];
                         /* iTicket SearchPrice API call */
                        $SearchPrice = $this->search_price($third_party_account, $location_code, $product_code, $article_code, $data['selected_date'], $selected_date_timeslot);
                        $data['prices'] = (array) $SearchPrice->SearchPriceResult->Prices->Price;

                        $search_price_article_codes = $SearchPrice['article_codes'];
                        foreach ($data['prices'] as $product_price) {
                            $prices[$article_code . '_' . $product_price->CategoryCode] = $product_price;
                            $prices[$product_price->CategoryCode] = $product_price->UnitPrice;
                        }
                        $ticket_types = $data['ticket_types'];
                        foreach ($ticket_types as $type => $details) {
                            if (!empty($search_price_article_codes)) {
                                foreach ($search_price_article_codes as $article_codes) {
                                    foreach ($article_codes as $article_code) {
                            if ($type == 'adult') {
                                $taxes = $prices[$article_code . '_ADULT']->Taxes;
                                $purchase_items['PurchaseItem'][] = array(
                                    'ArticleCode' => $article_code,
                                    'CategoryCode' => 'ADULT',
                                    'ProductCode' => $product_code,
                                    'Quantity' => (string) $details['count'],
                                    'Taxes' => $taxes,
                                    'TotalPrice' => $prices->ADULT * $details['count']
                                );
                            }
                            if ($type == 'child') {
                                $taxes = $prices[$article_code . '_CHILD1']->Taxes;
                                $purchase_items['PurchaseItem'][] = array(
                                    'ArticleCode' => $article_code,
                                    'CategoryCode' => 'CHILD1',
                                    'ProductCode' => $product_code,
                                    'Quantity' => (string) $details['count'],
                                    'Taxes' => $taxes,
                                    'TotalPrice' => $prices->CHILD1 * $details['count']
                                );
                            }
                            if ($type == 'infant') {
                                $taxes = $prices[$article_code . '_CHILD2']->Taxes;
                                $purchase_items['PurchaseItem'][] = array(
                                    'ArticleCode' => $article_code,
                                    'CategoryCode' => 'CHILD2',
                                    'ProductCode' => $product_code,
                                    'Quantity' => (string) $details['count'],
                                    'Taxes' => $taxes,
                                    'TotalPrice' => $prices->CHILD2 * $details['count']
                                );
                            }
                        }
                                }
                            }
                        }
                        $params = array(
                            'ApiKey' => $iticket_api_key,
                            'OrderId' => (int) $data['order_id'],
                            'PurchaseItems' => $purchase_items
                        );
                        $logs['RegisterPurchase_req_' . date('H:i:s')] = json_encode($params);
                        $response = $client->RegisterPurchase($params);
                        $logs['RegisterPurchase_res_' . date('H:i:s')] = json_encode($response);
                        if ($response != FALSE) {
                            $params = array(
                                'ApiKey' => $iticket_api_key,
                                'OrderId' => (int) $data['order_id'],
                            );
                            $logs['GetTickets_req_' . date('H:i:s')] = json_encode($params);
                            $get_ticket_response = json_encode($client->GetTickets($params));
                            $logs['GetTickets_res_' . date('H:i:s')] = json_encode($get_ticket_response);

                            if ($get_ticket_response != FALSE) {
                                $get_ticket_response = json_decode($get_ticket_response, true);
                                $tickets = isset($get_ticket_response['GetTicketsResult']['TicketGroup']['Tickets']) ? $get_ticket_response['GetTicketsResult']['TicketGroup']['Tickets'] : $get_ticket_response['GetTicketsResult']['TicketGroup'][0]['Tickets'];
                                $ticket_group_code = '';
                                if (isset($data['third_party_details']['combi_pass']) && $data['third_party_details']['combi_pass'] == 1) {
                                    $ticket_group_code = ((isset($get_ticket_response['GetTicketsResult']['TicketGroup']['TicketGroupNumber']) && !empty($get_ticket_response['GetTicketsResult']['TicketGroup']['TicketGroupNumber'])) ? $get_ticket_response['GetTicketsResult']['TicketGroup']['TicketGroupNumber'] : ((isset($get_ticket_response['GetTicketsResult']['TicketGroup'][0]['TicketGroupNumber']) && !empty($get_ticket_response['GetTicketsResult']['TicketGroup'][0]['TicketGroupNumber'])) ? $get_ticket_response['GetTicketsResult']['TicketGroup'][0]['TicketGroupNumber'] : ""));
                                }
                                $ticket_type_response = array();
                                if (!empty($tickets)) {
                                    if (isset($tickets['Ticket'][0])) {
                                        foreach ($tickets['Ticket'] as $ticket) {
                                            if ($ticket['CategoryCode'] == 'CHILD1') {
                                                $ticket['CategoryCode'] = 'CHILD';
                                            } else if ($ticket['CategoryCode'] == 'CHILD2') {
                                                $ticket['CategoryCode'] = 'INFANT';
                                            }
                                            $ticket_type_response[] = array(
                                                'ticket_type' => $ticket['CategoryCode'],
                                                'ticket_code' => isset($ticket_group_code) ? $ticket_group_code : $ticket['TicketNumber']
                                            );
                                        }
                                    } else if (isset($tickets['Ticket']['TicketNumber'])) {
                                        if ($tickets['Ticket']['CategoryCode'] == 'CHILD1') {
                                            $tickets['Ticket']['CategoryCode'] = 'CHILD';
                                        } else if ($tickets['Ticket']['CategoryCode'] == 'CHILD2') {
                                            $tickets['Ticket']['CategoryCode'] = 'INFANT';
                                        }
                                        $ticket_type_response[] = array(
                                            'ticket_type' => $tickets['Ticket']['CategoryCode'],
                                            'ticket_code' => isset($ticket_group_code) ? $ticket_group_code : $tickets['Ticket']['TicketNumber']
                                        );
                                    }
                                }
                            } else {
                                $response_array = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Invalid response from Third party");
                            }
                        } else {
                            $response_array = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Invalid response from Third party");
                        }
                        $third_party['status'] = (int) 1;
                        $third_party['ticket_data'] = $ticket_type_response;
                        $third_party['booking_id'] = $data['booking_id'];
                        $third_party['reference_id'] = $data['booking_id'];
                        $third_party['combi_pass'] = isset($ticket_group_code) ? $ticket_group_code : $tickets['Ticket']['TicketNumber'];
                        $response_array = $third_party;
                    }
                } else {
                    $response_array = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Booking id is blank");
                }
            } catch (SoapFault $ex) {
                $response_array['status'] = (int) 0;
                $response_array['errorMessage'] = $ex->getMessage();
            }
        }
        $logs['send_iticket_confirmation_request_res_' . date('H:i:s')] = $response_array;
        $MPOS_LOGS['confirm_third_party_tickets']['send_iticket_confirmation_request'] = $logs;
        return $response_array;
    }

    /**
     * @name: cancel_iticket_booking_request
     * @purpose: To cancel booking of iticket
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function cancel_iticket_booking_request($data) {
        global $MPOS_LOGS;
        $iticket_api_key = isset($data['third_party_details']['third_party_account']) ? $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5'][$data['third_party_details']['third_party_account']] : $this->tp_tickets_details['iticket']['ITICKET_API_KEY_V5']['prio'];
        try {
            if (!empty($data['booking_ids'])) {
                foreach ($data['booking_ids'] as $booking_id) {
                    /* Create SoapClient object */
                    $client = new \SoapClient($this->ITICKET_SERVER, array('location' => $this->ITICKET_SERVER_LOCATION));

                    /* iTicket API call to CancelBooking */
                    $params = array(
                        'ApiKey' => $iticket_api_key,
                        'BookingId' => (int) $booking_id,
                    );
                    $logs['CancelBooking_req_' . date('H:i:s')] = json_encode($params);
                    $response = $client->CancelBooking($params);
                    $logs['CancelBooking_res_' . date('H:i:s')] = json_encode($response);
                    /* iTicket API call to CommitBookingRequest */
                    $params = array(
                        'ApiKey' => $iticket_api_key,
                        'BookingId' => (int) $booking_id,
                        'PointOfSale' => 'Prioticket API',
                        'Booker' => 'Prioticket API'
                    );
                    $logs['CommitBooking_req_' . date('H:i:s')] = json_encode($params);
                    $response = $client->CommitBooking($params);
                    $logs['CommitBooking_res_' . date('H:i:s')] = json_encode($response);
                    $response_array['status'] = (int) 1;
                }
            }
        } catch (SoapFault $ex) {
            $response_array['status'] = (int) 1;
            $response_array['errorMessage'] = $ex->getMessage();
        }
        $logs['cancel_iticket_booking_request_res_' . date('H:i:s')] = $response_array;
        $MPOS_LOGS['cancel_third_party_tickets']['cancel_iticket_booking_request'] = $logs;
        return $response_array;
    }

    /**
     * @name: send_gt_ticket_booking_request
     * @purpose: reservation of gt tickets
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     * @updatedOn 24 April 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function send_gt_ticket_booking_request($request) {
        global $MPOS_LOGS;
        if (PAYMENT_MODE == 'live' || PAYMENT_MODE == 'test') {
            try {
                $url = GT_CREATE_RESERVATION_API_URL;
                if (isset($request['ticket_id']) && isset($request['third_party_details']) && $request['selected_date']) {
                    $third_party_params = $request['third_party_details'];
                    $third_party_account_details = $this->tp_tickets_details['gt'][$third_party_params['third_party_account']];
                    $ticket_types_array = array();
                    $ticket_types = $request['items'];
                    if (!empty($ticket_types)) {
                        foreach ($ticket_types as $ticket_type) {
                            $ticket_types_array[] = array(
                                "ticketNumber" => $ticket_type['count'],
                                "ticketTypeId" => $ticket_type['third_party_ticket_type_id'],
                            );
                        }
                    }
                    $sha256_array = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'environment' => GT_ENVIRONMENT,
                        'ticketDate' => $request['selected_date'],
                        'ticketTime' => $request['selected_timeslot'],
                        'tickets' => $ticket_types_array,
                        'userId' => $third_party_params['user_id']
                    );
                    $req_data = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'environment' => GT_ENVIRONMENT,
                        'ticketDate' => $request['selected_date'],
                        'ticketTime' => $request['selected_timeslot'],
                        'tickets' => $ticket_types_array,
                        'userId' => $third_party_params['user_id'],
                        'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $third_party_account_details['secret_key']))
                    );
                    $logs['gt_booking_request_req_' . date('H:i:s')] = json_encode($req_data);
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_POST, count($req_data));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
                    $data = curl_exec($ch);
                    $logs['gt_booking_request_res_' . date('H:i:s')] = $data;
                    $response = json_decode($data, true);
                } else {
                    $this->exception_handler->show_error();
                }
            } catch (SoapFault $ex) {
                $response['status'] = (int) 0;
                $response['errorMessage'] = $ex->getMessage();
            }
        }
        $logs['send_gt_ticket_booking_request_res_' . date('H:i:s')] = $response;
        $MPOS_LOGS['reserve_third_party_tickets']['send_gt_ticket_booking_request'] = $logs;
        return $response;
    }

    /**
     * @name: send_gt_ticket_confirmation_request
     * @purpose: Confirm booking of gt tickets
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     */
    function send_gt_ticket_confirmation_request($data) {
        global $MPOS_LOGS;
        if (PAYMENT_MODE == 'live' || PAYMENT_MODE == 'test') {
            try {
                $types = array();
                $data['booking_id'] = trim($data['booking_id']);
                $url = GT_COMPLETE_RESERVATION_API_URL;
                if (isset($data['ticket_id']) && isset($data['third_party_details']) && $data['selected_date']) {
                    $items = $data['items'];
                    foreach ($items as $types_data) {
                        $types[$types_data['category']] = $types_data['third_party_ticket_type_id'];
                    }
                    $third_party_params = $data['third_party_details'];
                    $third_party_account_details = $this->tp_tickets_details['gt'][$third_party_params['third_party_account']];
                    $sha256_array = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'customerEmailAddress' => 'karan.intersoft@gmail.com',
                        'customerFirstName' => 'PrioTicket',
                        'customerSurname' => 'Test',
                        'environment' => GT_ENVIRONMENT,
                        'reservationId' => $data['booking_id'],
                        'userId' => $third_party_params['user_id']
                    );
                    $data = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'customerEmailAddress' => 'karan.intersoft@gmail.com',
                        'customerFirstName' => 'PrioTicket',
                        'customerSurname' => 'Test',
                        'environment' => GT_ENVIRONMENT,
                        'reservationId' => $data['booking_id'],
                        'userId' => $third_party_params['user_id'],
                        'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $third_party_account_details['secret_key']))
                    );
                    $logs['gt_confirm_API_request'] = json_encode($data);
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_POST, count($data));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    $response = curl_exec($ch);
                    $logs['gt_confirm_API_res_' . date('H:i:s')] = $response;
                    $response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
                    if ($response['success'] == TRUE) {
                        $response_array = array();
                        $barcodes_array = array();
                        foreach ($response['ticketBarcodes'] as $barcodes) {
                            $type = array_search($barcodes['ticketTypeId'], $types);
                            $barcodes_array[$type][] = $barcodes['barcode'];
                        }
                        $response_array['status'] = (int) 1;
                        $response_array['combi_pass'] = "";
                        $response_array['reference_id'] = (string) $data['reservationId'];
                        $response_array['barcodes'] = $barcodes_array;
                    } else {
                        $response_array['status'] = (int) 0;
                        $response_array['errorMessage'] = 'Invalid request';
                    }
                }
            } catch (SoapFault $ex) {
                $response_array['status'] = (int) 0;
                $response_array['errorMessage'] = $ex->getMessage();
            }
        }
        $logs['response' . date('H:i:s')] = $response_array;
        $MPOS_LOGS['confirm_third_party_tickets']['send_gt_ticket_confirmation'] = $logs;
        return $response_array;
    }

    /**
     * @name: cancel_gt_ticket_booking_request
     * @purpose: Cancel booking of gt tickets
     * @created by: Komal garg  <komalgarg.intersoft@gmail.com> on Aug 31, 2017
     */
    function cancel_gt_ticket_booking_request($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['ticket_id']) && isset($data['third_party_details']) && $data['booking_ids']) {
                $third_party_params = $data['third_party_details'];
                $third_party_account_details = $this->tp_tickets_details['gt'][$third_party_params['third_party_account']];
                foreach ($data['booking_ids'] as $reservationId) {
                    $url = GT_CANCEL_RESERVATION_API_URL;
                    $sha256_array = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'environment' => GT_ENVIRONMENT,
                        'reservationId' => $reservationId,
                        'userId' => $third_party_params['user_id']
                    );
                    $req_data = array(
                        'apiKey' => $third_party_account_details['api_key'],
                        'environment' => GT_ENVIRONMENT,
                        'reservationId' => $reservationId,
                        'userId' => $third_party_params['user_id'],
                        'HMACKey' => base64_encode(hash_hmac('sha256', json_encode($sha256_array), $third_party_account_details['secret_key']))
                    );
                    $logs['gt_cancel_API_request'] = json_encode($req_data);
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_POST, count($req_data));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
                    $response = curl_exec($ch);
                    $logs['gt_cancel_API_response'] = $response;
                    $response = json_decode($response, true);
                }
            }
        } catch (SoapFault $ex) {
            $response['status'] = (int) 0;
            $response['errorMessage'] = $ex->getMessage();
        }
        $logs['response' . date('H:i:s')] = $response;
        $MPOS_LOGS['cancel_third_party_tickets']['cancel_gt_ticket_booking_request'] = $logs;
        return $response;
    }

    /**
     * @name: get_boverties_availability
     * @purpose: To fetch availabilities of boverties tickets (T&T)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on May 8, 2019
     */
    function get_boverties_availability($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['third_party_details']) && !empty($data['third_party_details']) && isset($data['selected_date'])) {
                $selected_date = $data['selected_date'];
                $product_id = $data['third_party_details']['productId'];
                $from_date = $selected_date . 'T00:00:00';
                $to_date = $selected_date . 'T23:59:59';
                $logs['timeslot_req'] = array('product_id' => $product_id, 'from_time' => $from_date, 'to_time' => $to_date);
                $url = $this->tp_tickets_details['tickethub']['tp_end_point'] . '/timeslot?productId=' . $product_id . '&from=' . $from_date . '&to=' . $to_date;
                $keys = base64_encode($this->tp_tickets_details['tickethub']['tp_user'] . ':' . $this->tp_tickets_details['tickethub']['tp_password']);
                /* SEND REQUEST TO tickethub */
                $headers = array(
                    'Content-Type: application/json',
                    'Authorization :Basic ' . $keys
                );
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                $curl_response = curl_exec($ch);
                $logs['timeslot_res'] = $curl_response;
                $timeslots_response = json_decode($curl_response, true);
                if (!empty($timeslots_response) && count($timeslots_response) > 0 && !isset($timeslots_response['errorCode'])) {
                    foreach ($timeslots_response as $timeslot) {
                        $start_date_time = explode('T', $timeslot['time']);
                        $start_time = explode(':', $start_date_time[1]);
                        $from_time = $start_time[0] . ':' . $start_time[1];
                        $end_date_time = explode('T', $timeslot['endTime']);
                        $end_time = explode(':', $end_date_time[1]);
                        $to_time = $end_time[0] . ':' . $end_time[1];
                        $used_capacity = $timeslot['totalCapacity'] - $timeslot['availableCapacity'];
                        $is_active = ($timeslot['totalCapacity'] == 0 || $used_capacity == $timeslot['totalCapacity']) ? false : true;
                        $difference = strtotime($to_time) - strtotime($from_time);
                        $timeslot_type = "day"; /* DEFAULT VALUE */
                        switch ($difference) {
                            case 0 :
                                $timeslot_type = "specific";
                                break;
                            case 900 :
                                $timeslot_type = "15min";
                                break;
                            case 1200 :
                                $timeslot_type = "20min";
                                break;
                            case 1800 :
                                $timeslot_type = "30min";
                                break;
                            case 3600 :
                                $timeslot_type = "hour";
                                break;
                            default:
                                if ($difference > 21600) {
                                    $timeslot_type = "day";
                                } else {
                                    $timeslot_type = "time_interval";
                                }
                                break;
                        }
                        $timeslots[] = array(
                            'from_time' => (string) $from_time,
                            'to_time' => (string) $to_time,
                            'timeslot_id' => isset($timeslot['timeslotId']) ? (int) $timeslot['timeslotId'] : 0,
                            'total_capacity' => (int) $timeslot['totalCapacity'],
                            'bookings' => ($used_capacity > 0) ? (int) $used_capacity : (int) 0,
                            'type' => $timeslot_type,
                            'is_active' => $is_active
                        );
                    }
                } else if (isset($timeslots_response['errorCode']) && $timeslots_response['errorCode'] == '12' && $timeslots_response['errorName'] == 'Not_Capacity_Product') {
                    $timeslots[] = array(
                        'from_time' => "00:00",
                        'to_time' => "23:59",
                        'total_capacity' => 999999,
                        'bookings' => 0,
                        'type' => 'day',
                        'is_active' => true
                    );
                } else {
                    $response['status'] = (int) 0;
                    $response['message'] = $timeslots_response['errorName'];
                }
                if (isset($timeslots) && !empty($timeslots)) {
                    $response = array(
                        'status' => (int) 1,
                        'date' => $selected_date,
                        'timeslots' => $timeslots
                    );
                }
            } else {
                $response['status'] = (int) 0;
                $response['message'] = 'Invalid Data';
            }
        } catch (SoapFault $ex) {
            $response['status'] = (int) 0;
            $response['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $logs['response_' . date('H:i:s')] = $response;
        $MPOS_LOGS['third_party_tickets_availability']['get_boverties_availability'] = $logs;
        return $response;
    }

    /**
     * @name: send_boverties_booking_request
     * @purpose: To reserve boverties tickets (T&T)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on May 8, 2019
     */
    function send_boverties_booking_request($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['third_party_details']) && !empty($data['third_party_details']) && isset($data['selected_date'])) {
                $items = $data['items'];
                foreach ($items as $types_data) {
                    $types[$types_data['category']]['third_party_ticket_type_id'] = $types_data['third_party_ticket_type_id'];
                    $types[$types_data['category']]['quantity'] = $types_data['count'];
                    $types[$types_data['category']]['extra_options'] = $types_data['extra_options'];
                }
                $timeslot_id = (isset($data['timeslot_id']) && $data['timeslot_id'] > 0) ? $data['timeslot_id'] : 0;
                $product_id = $data['third_party_details']['productId'];
                $lines = array();
                foreach ($types as $type_data) {
                    $meal_ids = '';
                    foreach ($type_data['extra_options'] as $option) {
                        $meal_ids .= str_repeat($option['third_party_id'] . ' ', $option['quantity']);
                    }
                    $meal_ids = ($meal_ids != '') ? explode(' ', trim($meal_ids)) : array();
                    $category_data = array(
                        "productId" => $product_id,
                        "qty" => (int) $type_data['quantity'],
                        "ticketCategoryId" => (int) $type_data['third_party_ticket_type_id'],
                    );
                    if (isset($timeslot_id) && $timeslot_id > 0) {
                        $category_data["timeslotId"] = $timeslot_id;
                    } else {
                        $category_data["startDate"] = $data['selected_date'];
                    }
                    if (!empty($meal_ids)) {
                        $category_data["details"] = array(
                            "mealIds" => $meal_ids
                        );
                    }
                    $lines[] = $category_data;
                }

                $url = $this->tp_tickets_details['tickethub']['tp_end_point'] . '/salesorder/create-reservation';
                $keys = base64_encode($this->tp_tickets_details['tickethub']['tp_user'] . ':' . $this->tp_tickets_details['tickethub']['tp_password']);
                /* SEND REQUEST TO tickethub */
                $headers = array(
                    'Content-Type: application/json',
                    'Authorization :Basic ' . $keys
                );
                $req_data = array(
                    'lines' => $lines
                );
                $logs['create-reservation_req'] = $req_data;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_POST, count($req_data));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
                $response = curl_exec($ch);
                $response = json_decode($response, true);
                $logs['create-reservation_res'] = $response;
                if ($response['reservationId'] != '') {
                    $response_data['status'] = (int) 1;
                    $response_data['booking_id'] = $response['reservationId'];
                } else if ($response['errorCode'] == 52 || $response['errorCode'] == 53 || $response['errorCode'] == 54) {
                    $response_data['status'] = (int) 0;
                    $response_data['errorMessage'] = 'Invalid Extra Options';
                } else {
                    $response_data['status'] = (int) 0;
                    $response_data['errorMessage'] = 'Reservation Fail';
                }
            } else {
                $response_data['status'] = (int) 0;
                $response_data['message'] = 'Invalid Data';
            }
        } catch (SoapFault $ex) {
            $response_data['status'] = (int) 0;
            $response_data['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $MPOS_LOGS['reserve_third_party_tickets']['send_boverties_booking_request'] = $logs;
        return $response_data;
    }

    /**
     * @name: send_boverties_confirmation_request
     * @purpose: To confirm boverties tickets (T&T)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on May 8, 2019
     */
    function send_boverties_confirmation_request($data) {
        global $MPOS_LOGS;
        try {
            $items = $data['items'];
            foreach ($items as $types_data) {
                $types[$types_data['category']] = $types_data['third_party_ticket_type_id'];
            }
            $url = $this->tp_tickets_details['tickethub']['tp_end_point'] . '/salesorder/create-booking';
            $keys = base64_encode($this->tp_tickets_details['tickethub']['tp_user'] . ':' . $this->tp_tickets_details['tickethub']['tp_password']);
            /* SEND REQUEST TO tickethub */
            $headers = array(
                'Content-Type: application/json',
                'Authorization :Basic ' . $keys
            );
            $req_data = array(
                'reservationId' => $data['booking_id']
            );
            $logs['create-booking_req'] = $req_data;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POST, count($req_data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
            $response = curl_exec($ch);
            $response = json_decode($response, true);
            $logs['create-booking_res_' . date('H:i:s')] = $response;
            if (!isset($response['errorCode'])) {
                $reference_id = $response['salesOrderId'];
                foreach ($response['lines'] as $lines) {
                    $type = array_search($lines['ticketCategoryId'], $types);
                    $barcodes_array[$type] = $lines['barcodes'];
                }
                $response_array['status'] = (int) 1;
                $response_array['reference_id'] = (string) $reference_id;
                $response_array['barcodes'] = $barcodes_array;
            } else {
                $response_array['status'] = (int) 0;
                $response_array = $response;
            }
        } catch (SoapFault $ex) {
            $response_array['status'] = (int) 0;
            $response_array['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
            $MPOS_LOGS['get_gt_availability'] = $logs;
        }
        $logs['response_' . date('H:i:s')] = $response_array;
        $MPOS_LOGS['confirm_third_party_tickets']['send_boverties_confirmation_res'] = $logs;
        return $response_array;
    }

    /**
     * @name: cancel_boverties_booking_request
     * @purpose: To cancel boverties tickets (T&T) booking
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on May 8, 2019
     */
    function cancel_boverties_booking_request() {
        $response['status'] = 1;
        return $response;
    }
    
    /**
     * @name: get_enviso_availability
     * @purpose: To get available timeslots and available capacity for enviso tickets (Rijksmuseum Tickets)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_enviso_availability($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['third_party_details']) && !empty($data['third_party_details']) && isset($data['selected_date'])) {
                $user = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
                $tp_api_secret_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_secret_key'];
                $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
                $login_response = $this->login($user);
                $token = $login_response['data']['token'];
                if (!empty($token) && $login_response['data']['status'] == 'Success') {
                    $selected_date = $data['selected_date'];
                    $offer_id = $data['third_party_details']['third_party_ticket_id'];
                    $from_date = $selected_date . 'T00:00:00Z';
                    $to_date = $selected_date . 'T23:59:59Z';
                    $logs['get_offer_req'] = '/offers/' . $offer_id;
                    $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/offers/' . $offer_id;
                    $headers = array(
                        'Content-Type:application/json',
                        'x-tenantsecretkey:' . $tp_api_secret_key,
                        'x-api-key:' . $tp_api_key,
                        'Authorization:Bearer ' . $token . ''
                    );
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $offer_response = curl_exec($ch);
                    curl_close($ch);
                    if (isset($offer_response) && !empty($offer_response)) {
                        $offer_response = json_decode($offer_response, true);
                        $logs['get_offer_res'] = $offer_response;
                        $capacity_type = $offer_response['capacityAllocation']['type'];
                        if ($capacity_type == 3) { //per slot type offer -> fetch timeslots
                            $logs['get_offer_timeslots_req'] = array('offer_id' => '/offers/' . $offer_id . '/timeslots?fromdate=' . $from_date . '&todate=' . $to_date);
                            $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/offers/' . $offer_id . '/timeslots?fromdate=' . $from_date . '&todate=' . $to_date;
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                            $timeslots_response = curl_exec($ch);
                            curl_close($ch);
                            $timeslots_response = json_decode($timeslots_response, true);
                            $logs['get_offer_timeslots_res'] = $timeslots_response;
                            foreach ($timeslots_response as $key => $slot) {
                                $logs['get_timeslots_capacities_req_' . $key] = array('offer_id' => '/offers/' . $offer_id . '/capacities?fromdate=' . $selected_date . '&todate=' . $selected_date . '&timeslotid=' . $slot['id']);
                                $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/offers/' . $offer_id . '/capacities?fromdate=' . $selected_date . '&todate=' . $selected_date . '&timeslotid=' . $slot['id'];
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $url);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                                $capacity_response = curl_exec($ch);
                                curl_close($ch);
                                $capacity_response = json_decode($capacity_response, true);
                                $logs['get_timeslots_capacities_res_' . $key] = $capacity_response;
                                $start_date_time = explode('T', $slot['startTime']);
                                $start_time = explode(':', $start_date_time[1]);
                                $from_time = $start_time[0] . ':' . $start_time[1];
                                $end_date_time = explode('T', $slot['endTime']);
                                $end_time = explode(':', $end_date_time[1]);
                                $to_time = $end_time[0] . ':' . $end_time[1];
                                $used_capacity = $capacity_response['allocatedQuantity'] - $capacity_response['capacities'][0]['availableQuantity'];
                                $is_active = ($capacity_response['allocatedQuantity'] == 0 || $used_capacity == $capacity_response['allocatedQuantity']) ? false : true;
                                $difference = strtotime($to_time) - strtotime($from_time);
                                $timeslot_type = "day"; /* DEFAULT VALUE */
                                switch ($difference) {
                                    case 0 :
                                        $timeslot_type = "specific";
                                        break;
                                    case 900 :
                                        $timeslot_type = "15min";
                                        break;
                                    case 1200 :
                                        $timeslot_type = "20min";
                                        break;
                                    case 1800 :
                                        $timeslot_type = "30min";
                                        break;
                                    case 3600 :
                                        $timeslot_type = "hour";
                                        break;
                                    default:
                                        if ($difference > 21600) {
                                            $timeslot_type = "day";
                                        } else {
                                            $timeslot_type = "time_interval";
                                        }
                                        break;
                                }
                                $timeslots[] = array(
                                    'from_time' => (string) $from_time,
                                    'to_time' => (string) $to_time,
                                    'timeslot_id' => isset($slot['id']) ? (int) $slot['id'] : 0,
                                    'total_capacity' => (int) $capacity_response['allocatedQuantity'],
                                    'bookings' => ($used_capacity > 0) ? (int) $used_capacity : (int) 0,
                                    'type' => $timeslot_type,
                                    'is_active' => $is_active
                                );
                            }
                        } else { //per day or total type capacity
                            $logs['get_capacities_req'] = array('offer_id' => '/offers/' . $offer_id . '/capacities?fromdate=' . $selected_date . '&todate=' . $selected_date . '&timeslotid=' . $slot['id']);
                            $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/offers/' . $offer_id . '/capacities?fromdate=' . $selected_date . '&todate=' . $selected_date;
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                            $capacity_response = curl_exec($ch);
                            curl_close($ch);
                            $capacity_response = json_decode($capacity_response, true);
                            $logs['get_capacities_res'] = $capacity_response;
                            if ($capacity_response['allocatedQuantity'] == '-1') { //unlimited capacity
                                $used_capacity = 0;
                                $is_active = true;
                            } else {
                                $used_capacity = $capacity_response['allocatedQuantity'] - $capacity_response['capacities'][0]['availableQuantity'];
                                $is_active = ($capacity_response['allocatedQuantity'] == 0 || $used_capacity == $capacity_response['allocatedQuantity']) ? false : true;
                            }
                            $timeslots[] = array(
                                'from_time' => "00:00",
                                'to_time' => "23:59",
                                'total_capacity' => ($capacity_response['allocatedQuantity'] == '-1') ? 999999 : (int) $capacity_response['allocatedQuantity'],
                                'bookings' => ($used_capacity > 0) ? (int) $used_capacity : (int) 0,
                                'type' => 'day',
                                'is_active' => $is_active
                            );
                        }
                        if (isset($timeslots) && !empty($timeslots)) {
                            $response = array(
                                'status' => (int) 1,
                                'date' => $selected_date,
                                'timeslots' => $timeslots
                            );
                        }
                    } else {
                        $response['status'] = (int) 0;
                        $response['message'] = 'Invalid Data';
                    }
                } else {
                    $response['status'] = (int) 0;
                    $response['message'] = 'Invalid Data';
                }
            } else {
                $response['status'] = (int) 0;
                $response['message'] = 'Authentication Failed';
            }
        } catch (SoapFault $ex) {
            $response['status'] = (int) 0;
            $response['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $logs['response_' . date('H:i:s')] = $response;
        $MPOS_LOGS['third_party_tickets_availability']['get_enviso_availability'] = $logs;
        return $response;
    }
    /**
     * @name: send_enviso_booking_request
     * @purpose: To reserve a slot of enviso tickets (Rijksmuseum Tickets)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function send_enviso_booking_request($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['third_party_details']) && !empty($data['third_party_details']) && isset($data['selected_date'])) {
                $user = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
                $tp_api_secret_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_secret_key'];
                $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
                $login_response = $this->login($user);
                $token = $login_response['data']['token'];
                if (!empty($token) && $login_response['data']['status'] == 'Success') {    
                    $items = $data['items'];
                    foreach ($items as $types_data) {
                        $types[$types_data['category']]['third_party_ticket_type_id'] = $types_data['third_party_ticket_type_id'];
                        $types[$types_data['category']]['quantity'] = $types_data['count'];
                    }
                    $timeslot_id = (isset($data['timeslot_id']) && $data['timeslot_id'] > 0) ? $data['timeslot_id'] : 0;
                    foreach ($types as $type_data) {
                        $category_data = array(
                            "productId" => (int) $type_data['third_party_ticket_type_id'],
                            "quantity" => (int) $type_data['quantity']
                        );
                        if (isset($timeslot_id) && $timeslot_id > 0) {
                            $category_data["timeSlotId"] = $timeslot_id;
                        } else {
                            $category_data["visitDate"] = $data['selected_date'];
                        }
                        $products[] = $category_data;
                    }
                    $logs['reserve_req'] = $products;
                    $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/products/reservations';
                    /* SEND REQUEST TO Rijksmuseum */
                    $headers = array(
                        'Content-Type:application/json',
                        'x-tenantsecretkey:' . $tp_api_secret_key,
                        'x-api-key:' . $tp_api_key,
                        'Authorization:Bearer ' . $token . ''
                    );
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($products));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $reservation_response = curl_exec($ch);
                    $reservation_response = json_decode($reservation_response, true);
                    $logs['reservation_res'] = $reservation_response;
                    foreach ($reservation_response as $reservation) {
                        if (isset($reservation['errors']) && !empty($reservation['errors'])) {
                            $response_data['status'] = (int) 0;
                            $response_data['errorMessage'] = 'Invalid Data';
                            $response_data['message'] = $reservation['errors'][0]['message'];
                        } else {
                            $reserve_ids[] = $reservation['id'];
                        }
                    }
                    if (!empty($reserve_ids)) {
                        $reservation_ids = implode('~', $reserve_ids);
                        $response_data['status'] = (int) 1;
                        $response_data['booking_id'] = $reservation_ids;
                    } else {
                        $response_data['status'] = (int) 0;
                        $response_data['errorMessage'] = 'Reservation Fail';
                    }
                } else {
                    $response_data['status'] = (int) 0;
                    $response_data['message'] = 'Invalid Data';
                }
            } else {
                $response_data['status'] = (int) 0;
                $response_data['message'] = 'Authentication Failed';
            }
        } catch (SoapFault $ex) {
            $response_data['status'] = (int) 0;
            $response_data['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $MPOS_LOGS['reserve_third_party_tickets']['send_enviso_booking_request'] = $logs;
        return $response_data;
    }
    /**
     * @name: send_enviso_confirmation_request
     * @purpose: To confirm enviso tickets (Rijksmuseum Tickets)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function send_enviso_confirmation_request($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['items']) && !empty($data['items']) && isset($data['booking_id']) && (isset($data['third_party_details']) && !empty($data['third_party_details']))) {
                $user = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
                $tp_api_secret_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_secret_key'];
                $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
                $login_response = $this->login($user);
                $token = $login_response['data']['token'];
                if (!empty($token) && $login_response['data']['status'] == 'Success') {   
                    $items = $data['items'];
                    foreach ($items as $types_data) {
                        $types[$types_data['category']] = $types_data['third_party_ticket_type_id'];
                    }
                    $booking_ids = explode('~', $data['booking_id']);
                    foreach ($booking_ids as $id) {
                        $order_items[] = array("reservationId" => $id);
                    }
                    $req_data = array(
                        "orderItems" => $order_items,
                        "countryName" => "Netherlands",
                        "groupVisit" => "false"
                    );
                    $logs['confirm_req'] = $req_data;
                    $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/orders';
                    /* SEND REQUEST TO Rijksmuseum */
                    $headers = array(
                        'Content-Type:application/json',
                        'x-tenantsecretkey:' . $tp_api_secret_key,
                        'x-api-key:' . $tp_api_key,
                        'Authorization:Bearer ' . $token . ''
                    );
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $confirmation_response = curl_exec($ch);
                    $confirmation_response = json_decode($confirmation_response, true);
                    $logs['confirm_res'] = $confirmation_response;
                    if (isset($confirmation_response) && !empty($confirmation_response)) {
                        if (isset($confirmation_response[0]['errors'][0]) && !empty($confirmation_response[0]['errors'][0])) {
                            $response_data['status'] = (int) 0;
                            $response_data['message'] = $confirmation_response[0]['errors'][0]['message'];
                        } else {
                            if (!empty($confirmation_response['orderItems'])) {
                                foreach ($confirmation_response['orderItems'] as $orderItem) {
                                    $type_id = $orderItem['product']['id'];
                                    foreach ($orderItem['ticketBarcodes'] as $barcodes) {
                                        $type = array_search($type_id, $types);
                                        $barcodes_array[$type][] = $barcodes['barcodeString'];
                                    }
                                }
                            }
                            $response_array['status'] = (int) 1;
                            $response_array['reference_id'] = (string) $confirmation_response['id'];
                            $response_array['barcodes'] = $barcodes_array;
                        }
                    } else {
                        $response_array['status'] = (int) 0;
                        $response_array['message'] = 'Invalid Data';
                    }
                } else {
                    $response_array['status'] = (int) 0;
                    $response_array['message'] = 'Invalid Data';
                }
            } else {
                $response_array['status'] = (int) 0;
                $response_array['message'] = 'Authentication Failed';
            }
        } catch (SoapFault $ex) {
            $response_array['status'] = (int) 0;
            $response_array['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $MPOS_LOGS['confirm_third_party_tickets']['send_enviso_booking_request'] = $logs;
        return $response_array;
    }
    /**
     * @name: cancel_enviso_booking_request
     * @purpose: To cancel enviso reservation (Rijksmuseum Tickets)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function cancel_enviso_booking_request() {
        $response['status'] = 1;
        return $response;
    }
    /**
     * @name: cancel_enviso_order
     * @purpose: To cancel enviso reservation (Rijksmuseum Tickets)
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function cancel_enviso_order($data) {
        global $MPOS_LOGS;
        try {
            if (isset($data['booking_id']) && (isset($data['third_party_details']) && !empty($data['third_party_details']))) {
                $user = isset($data['third_party_details']['third_party_account']) ? $data['third_party_details']['third_party_account'] : 'prio';
                $tp_api_secret_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_secret_key'];
                $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
                $login_response = $this->login($user);
                $token = $login_response['data']['token'];
                if (!empty($token) && $login_response['data']['status'] == 'Success') {    
                    $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/orders/' . $data['booking_id'] . '/cancel';
                    $logs['cancel-order_req'] = '/orders/' . $data['booking_id'] . '/cancel';
                    $headers = array(
                        'Content-Type:application/json',
                        'x-tenantsecretkey:' . $tp_api_secret_key,
                        'x-api-key:' . $tp_api_key,
                        'Authorization:Bearer ' . $token . ''
                    );
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $response = curl_exec($ch);
                    $response = json_decode($response, true);
                    $logs['cancel-order_res'] = $response;
                    if (!empty($response) && $response['status'] == 2) {
                        $response_array['status'] = (int) 1;
                        $response_array['message'] = 'booking cancelled successfully';
                    } else {
                        $response_array['status'] = (int) 0;
                        $response_array['message'] = $response['errors'][0]['message'];
                    }
                } else {
                    $response_array['status'] = (int) 0;
                    $response_array['message'] = 'unable to cancel, add booking id';
                }
            } else {
                $response['status'] = (int) 0;
                $response['message'] = 'Authentication Failed';
            }
        } catch (SoapFault $ex) {
            $response_array['status'] = (int) 0;
            $response_array['errorMessage'] = $ex->getMessage();
            $logs['exception'] = $ex->getMessage();
        }
        $MPOS_LOGS['cancel_enviso_order'] = $logs;
        return $response_array;
    }
    /**
     * @Name login
     * @Purpose to generate authorization token for enviso tickets
     * Response array which contains token and status.
     */
    function login($user = 'prio') {
        $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
        $fromFile = false;
        // get token from file
        $token = $this->get_token_from_file($user);
        if ($token['status'] == '200' && $token['token'] != '') {
            $response['authToken'] = $token['token'];
            $curl_info['http_code'] = '200';
            $fromFile = true;
        } else {
            $url = $this->tp_tickets_details['rijksmuseum']['tp_end_point'] . '/apis/login';
            $date_signature = $this->get_signature($user);
            $timestamp = $date_signature['timestamp'];
            $signature = $date_signature['signature'];
            $headers = array(
                'Content-Type: application/json',
                'Cache-Control: no-cache'
            );
            $post_data = array(
                'apikey' => $tp_api_key,
                'timestamp' => $timestamp,
                'signature' => $signature
            );
            $ch = curl_init();
            if (count($post_data)) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            $response = curl_exec($ch);
            $response = json_decode($response, true);
            $curl_info = curl_getinfo($ch);
            curl_close($ch);
        }
        if ($curl_info['http_code'] == "200") {
            $response['data']['status'] = 'Success';
            $response['data']['token'] = $response['authToken'];
            if ($fromFile == false) {
                $this->save_token_in_file($response['authToken'], $user);
            }
        } else {
            $response['data']['status'] = 'Error';
            $response['data']['token'] = '';
            $response['data']['error']['error_code'] = 'LOGIN_FAILURE';
            $response['data']['error']['error_message'] = isset($response['message']) ? $response['message'] : '';
            $response['data']['error']['error_details'] = 'L1:' . json_encode($response);
        }
        return $response;
    }
    /**
     * @Name get_signature
     * @Purpose to generate authorization token and timestamp for enviso tickets
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_signature($user = 'prio'){
        $tp_api_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_key'];
        $tp_api_public_key = $this->tp_tickets_details['rijksmuseum']['third_party_account'][$user]['tp_api_public_key'];
        $key = $tp_api_key;
        $publicKey = $tp_api_public_key;
        $timestamp = date('Y-m-d') . 'T' . date('H:i:s') . '.871Z';
        require_once "system/application/libraries/rsa/RSA.php";
        $rsa = new RSA($publicKey);
        $data = hash('sha256', $key . '_' . $timestamp);
        $signature = $rsa->base64Encrypt($data);
        return array('timestamp' => $timestamp, 'signature' => $signature);
    }
    /**
     * @Name get_token_from_file
     * @Purpose to fetch existing token from file for enviso tickets
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_token_from_file($user = 'prio') {
        $folder = "system/application/storage/rijksmuseum/";
        if (!is_dir($folder)) {
            mkdir($folder);
        }
        $folder = $folder.$user.'/';
        if (!is_dir($folder)) {
            mkdir($folder);
        }
        $filename = $folder . "token_for_mpos.php";
        $token['status'] = '';
        $token['token'] = '';
        if (is_file($filename)) {
            $fp = fopen($filename, 'r');
            $filedata = fread($fp, filesize($filename));
            fclose($fp);
            if ($filedata != '') {
                $filedata = json_decode($filedata, true);
                $timestamp = $filedata['timestamp'];
                $diff = time() - $timestamp;
                $fiftyeightmin = 58 * 60;
                if ($diff < $fiftyeightmin) {
                    $token['status'] = '200';
                    $token['token'] = $filedata['token'];
                } else {
                    $token['status'] = '400';
                    $token['token'] = '';
                }
            }
        } else {
            $token['status'] = '400';
            $token['token'] = '';
        }
        return $token;
    }
    /**
     * @Name login
     * @Purpose to save newly generated token in file for enviso tickets
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function save_token_in_file($token = '', $user = 'prio') {
        if ($token != '') {
            $folder = "system/application/storage/rijksmuseum/";
            if (!is_dir($folder)) {
                mkdir($folder);
            }
            $folder = $folder.$user.'/';
            if (!is_dir($folder)) {
                mkdir($folder);
            }
            $data = array('timestamp' => time(), 'token' => $token);
            $data = json_encode($data);
            $filename = $folder . "token_for_mpos.php";
            $fp = fopen($filename, 'w+');
            fwrite($fp, $data);
            fclose($fp);
        }
        return true;
    }

    /* #endregion Third Party Module  : This module covers third party api's  */
}


?>