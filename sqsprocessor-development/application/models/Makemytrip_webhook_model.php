    <?php
    class Makemytrip_webhook_model extends MY_Model {

    /* #region  Boc of hotel_model */    
    /* #region main function to load Hotel_Model */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        //Call the Model constructor
        parent::__construct();
        $this->tp_ota_info = json_decode(TP_OTA_INFO, true);
        $this->load->helper('common_curl');
        $this->event_verification_credentials = json_decode(EVENT_VERIFICATION_CREDENTIALS, true);
        $this->load->helper('timezone');      
        $this->log_dir = "makemytrip";
        $this->log_file_name = "makemytrip_event.php";

    }

        /**
    * @Name : common_request()
    * @Purpose : common_request
    * @Return params : common_request
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 03 May 2020
    */
    /* #region   common_request */
    private function common_request($url = '', $method = '', $api = 'MMT', $tp_request = []){
        global $filelogs;
        global $graylogs;
        $request = $output= [];
        if(!empty($url) && !empty($method)){
            $request['post_data'] = json_encode($tp_request);
            $request['method'] = $method;
            $request['url'] = $url;
            $request['curl_timeout'] = 1;
            $request['curl_timeout_value'] = 6000;
            $request['print_log'] = 1;
            $request['log_name'] = "MMT_".$api;
            $request['api'] = "MMT_".$api;
            $headers = [
                'Authorization:' . $this->tp_ota_info['MMT']['authorization'],
                'Content-Type:application/json',
            ];
            $request['header_token'] = json_encode($headers);
            $url = STAPI_SERVER_URL.'/common_third_party_request.php';
            $other_data['curl_timeout'] = 1;
            $other_data['curl_timeout_value'] = 6000;
            $method1 ="GET";
            $start_time = microtime(true);
            $output = $response = common_curl::send_request($url, $method1, json_encode($request), $headers, $other_data);
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((int) $processing_time) / 1000;
            $this->external_processing_time += $processing_times;
            if(!empty($output['response'])){
                $response = json_decode($output['response'], true);
                if(!empty($response['response'])){
                        $response = json_decode($response['response'], true);
                }
            }
        } else {
            $response = false;
            $this->http_status = 400;
        }
        if(!isset($response['error'])) {
            $this->http_status = 200;
            $this->status = ($this->status != 400) ?  200 : 400;
        } else {
            $this->http_status = 400;
        }
         $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'Request Response', 'data' => json_encode(['request' => $request , 'response' => $output]), 'api_name' => $api,'http_status' => $this->http_status ,  'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
        
        return $response;
    }
        /**
     * @name    : get_db_records
     * @Purpose : To get_db_records
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
     */
    /* #region  To get_db_records  */
    function get_db_records($is_supplier_data = 1, $hotel_ids = [], $ids = [], $other_data = [], $update_mmt = 0){
        global $mmt_other_response_data;
        $api = "get_db_records";
        global $filelogs;
        global $graylogs;
        $error = [];
        $location_type_array= ['venue Address' , 'company' , 'date point' , 'meeting point' , 'destination' , 'info' , 'stop' , 'venue address' , 'other' , 'shop' , 'entry' , 'departure location' ];
        if(!empty($ids)){
            $all_last_quries['supplier_data'] = $is_supplier_data;
            $all_last_quries['hotel_ids'] = $hotel_ids;
            $all_last_quries['ticket_ids'] = $ids;
            $all_last_quries['other_data'] = $other_data;
            $this->primarydb->db->select('*');
            $this->primarydb->db->from('modeventcontent');
            $this->primarydb->db->where_in('mec_id', $ids);
            $query = $this->primarydb->db->get();
            $all_last_quries['last_quries'][] = $this->primarydb->db->last_query();
            if ($query->num_rows() > 0) {
                $this->get_constant();
                $refine_data = $distributer_details = $supplier_info = [];
                $mec_data = $query->result_array();
                if(!empty($is_supplier_data)){
                    $supplier_ids = array_column($mec_data, 'cod_id');
                    $supplier_ids = array_merge($supplier_ids , array($hotel_ids));
                    $this->primarydb->db->select('*');
                    $this->primarydb->db->from('qr_codes');
                    $this->primarydb->db->where_in('cod_id', $supplier_ids);
                    $query = $this->primarydb->db->get();
                    $all_last_quries['last_quries'][] = $this->primarydb->db->last_query();
                    if($query->num_rows() > 0){
                        $supplier_data = $query->result_array();
                        foreach($supplier_data as $supplier){
                            if(in_array($supplier['cod_id'] , $hotel_ids)){
                                $distributer_details[$supplier['cod_id']] = $supplier;
                            } else {
                                $supplier_info[$supplier['cod_id']] = $supplier;
                            }
                        }
                    }
                }
                if(!empty($hotel_ids)) { 
                    $this->primarydb->db->select('*');
                    $this->primarydb->db->from('pos_tickets');
                    $this->primarydb->db->where_in('mec_id', $ids);
                    $this->primarydb->db->where_in('hotel_id', $hotel_ids);
                    $query = $this->primarydb->db->get();
                    $pos_data = $query->result_array();
                    if(!empty($pos_data)){
                        foreach($pos_data as $hotel_data){
                            $refine_data['pos_data'][$hotel_data['hotel_id']][$hotel_data['mec_id']] =  $hotel_data;
                        }
                    }
                }
                $cluster_tickets = [];
                foreach($mec_data as $check_cluster_data){
                    if($check_cluster_data['product_type'] == "3" && !empty($check_cluster_data['combi_ticket_ids'])){
                        $cluster_tickets = array_merge($cluster_tickets, json_decode($check_cluster_data['combi_ticket_ids'], true));
                        $refine_data[$check_cluster_data['mec_id']]['cluster_tickets'] = json_decode($check_cluster_data['combi_ticket_ids'], true);
                    }
                }
                if(!empty($cluster_tickets)){
                    $cluster_with_normal = array_merge(array_values(array_unique($cluster_tickets)), $ids);
                } else {
                    $cluster_with_normal = $ids;
                }
                $this->primarydb->db->select('id as ticketpriceschedule_id, deleted, adjust_capacity, start_date, ticket_type_label, end_date, default_listing, ticket_id, pax, ticketType, agefrom, ageto, group_type_ticket, group_price, group_linked_with, min_qty, max_qty, is_pos_list, newPrice, discountType, original_price, newPrice, discountType, discount, saveamount, pricetext, museumCommission, hotelCommission, ticket_tax_value, apply_service_tax, hgsCommission, isCommissionInPercent, museum_tax_id, hotel_tax_id, hgs_tax_id, ticket_tax_id, hgs_provider_name, hgs_provider_id, third_party_ticket_type_id,hotelNetPrice,calculated_hotel_commission,museumCommission,museumNetPrice,hgsnetprice,calculated_hgs_commission');
                $this->primarydb->db->from('ticketpriceschedule');
                $this->primarydb->db->where_in('ticket_id', $cluster_with_normal);
                $this->primarydb->db->where('deleted', 0);
                $query = $this->primarydb->db->get();
                $all_last_quries['last_quries'][] = $this->primarydb->db->last_query();
                $tpsids = [];
                if ($query->num_rows() > 0) {
                    $ticketpriceschedule_data = $query->result_array();
                    foreach($ticketpriceschedule_data as $tps_data){
                        $refine_data[$tps_data['ticket_id']]['tps_data'][$tps_data['ticketpriceschedule_id']] = $tps_data;
                    }
                    $tpsids = array_column($ticketpriceschedule_data, 'ticketpriceschedule_id');
                }
                $ticket_prices = $this->get_ticket_price_mmt($distributer_details, $cluster_with_normal, $tpsids, $other_data);
                if(!empty($ticket_prices)){
                    $refine_data['ticket_prices'] = $ticket_prices;
                }
                $all_supplier_ids = $supplier_of_open = [];
                
                if(!empty($cluster_tickets)){
                    $this->primarydb->db->select('*');
                    $this->primarydb->db->from('modeventcontent');
                    $this->primarydb->db->where_in('mec_id', array_values(array_unique($cluster_tickets)));
                    $this->primarydb->db->where('deleted', "0");
                    $query = $this->primarydb->db->get();
                    $cluster_tickets_data = $query->result_array();
                    if(!empty($cluster_tickets_data)){
                        foreach($cluster_tickets_data as $cluster_tickets_data_v1){
                            $supplier_of_open[] = $cluster_tickets_data_v1['cod_id'];
                            $refine_data['cluster_tickets'][$cluster_tickets_data_v1['mec_id']] = $cluster_tickets_data_v1;
                        }
                    }
                }
                foreach($mec_data as $data){
                    $refine_data[$data['mec_id']]['mec_data'] = $data;
                    if(empty($data['is_reservation'])){
                        $supplier_of_open[] = $data['cod_id'];
                    }
                    $all_supplier_ids[] = $data['cod_id'];
                    if(!empty($supplier_info[$data['cod_id']])){
                        $refine_data[$data['mec_id']]['supplier_data'] = $supplier_info[$data['cod_id']];
                    }
                }
                if(!empty($supplier_of_open)){
                    $this->primarydb->db->select('*');
                    $this->primarydb->db->from('companystandardopeninghours');
                    $this->primarydb->db->where_in('cod_id', array_values(array_unique($supplier_of_open)));
                    $query = $this->primarydb->db->get();
                    if ($query->num_rows() > 0) {
                        $companystandardopeninghours = $query->result_array();
                        foreach($companystandardopeninghours as $openinghours){
                            $refine_data['openinghours'][$openinghours['cod_id']][strtolower($openinghours['days'])] = $openinghours;
                        }
                    }
                }
                if(!empty($cluster_with_normal)){
                    $this->primarydb->db->select('target_id, module_item_id, longitude, is_pickup_calculation_active, name, pickup_calculation_code, is_checkin_location, latitude, targetlocation , location_type');
                    $this->primarydb->db->from('rel_targetvalidcities');
                    $this->primarydb->db->where_in('module_item_id', $cluster_with_normal);
                    $query = $this->primarydb->db->get();
                    $all_last_quries['last_quries'][] = $this->primarydb->db->last_query();
                    if ($query->num_rows() > 0) {
                        $rel_targetvalidcities = $query->result_array();
                        if(!empty($rel_targetvalidcities)){
                            foreach($rel_targetvalidcities as $rel_targetvalidcities_v1){
                                if(isset($refine_data[$rel_targetvalidcities_v1['module_item_id']]['mec_data'])){
                                    $ticketdata = $refine_data[$rel_targetvalidcities_v1['module_item_id']]['mec_data'];
                                } else if(isset($refine_data['cluster_tickets'][$rel_targetvalidcities_v1['module_item_id']])){
                                    $ticketdata = $refine_data['cluster_tickets'][$rel_targetvalidcities_v1['module_item_id']];
                                }
                                if(!empty($rel_targetvalidcities_v1['is_pickup_calculation_active']) && !empty($ticketdata) && in_array($ticketdata['checkin_points_mandatory'], ["1", "2"])){
                                    $refine_data[$rel_targetvalidcities_v1['module_item_id']]['pickup_points']['is_mandatory'] = $ticketdata['checkin_points_mandatory'];
                                    $refine_data[$rel_targetvalidcities_v1['module_item_id']]['pickup_points']['pickup_data'][] = $rel_targetvalidcities_v1;
                                } else if(empty($rel_targetvalidcities_v1['is_pickup_calculation_active']) && !empty($ticketdata) && in_array($ticketdata['checkin_points_mandatory'], ["1", "2"]) && (!empty($rel_targetvalidcities_v1['targetlocation']) || !empty($rel_targetvalidcities_v1['name'])) && $rel_targetvalidcities_v1['location_type'] == 'Pick up point'){ 
                                    $refine_data[$rel_targetvalidcities_v1['module_item_id']]['pickup_points']['is_mandatory'] = $ticketdata['checkin_points_mandatory'];
                                    $refine_data[$rel_targetvalidcities_v1['module_item_id']]['pickup_points']['pickup_data'][] = $rel_targetvalidcities_v1;
                                } else if(in_array($ticketdata['checkin_points_mandatory'], ["1", "2"]) && !empty($rel_targetvalidcities_v1['is_checkin_location']) && !in_array(strtolower($rel_targetvalidcities_v1['location_type']) , $location_type_array)) {
                                    $error['pickup_issue_ids'][]=$ticketdata['mec_id'];
                                    $mmt_other_response_data['pick_ticket_issue'] = $error;
                                    
                                }
                            }
                        }
                    }
                    $this->primarydb->db->select('target_id, module_item_id, location_type, longitude, cod_id, is_pickup_calculation_active, name, pickup_calculation_code, is_checkin_location, latitude, targetlocation');
                    $this->primarydb->db->from('rel_targetvalidcities');
                    $this->primarydb->db->where_in('cod_id', $all_supplier_ids);
                    $this->primarydb->db->where('is_checkin_location', "0");
                    $this->primarydb->db->where('is_pickup_calculation_active', "0");
                    $query = $this->primarydb->db->get();
                    $all_last_quries['last_quries'][] = $this->primarydb->db->last_query();
                    if ($query->num_rows() > 0) {
                        $location_type_flag =[];
                        $rel_targetvalidcities_supplier_loc = $query->result_array();  
                        foreach($rel_targetvalidcities_supplier_loc as $rel_targetvalidcities_loc){
                            if(strtolower($rel_targetvalidcities_loc['location_type']) == "venue address" && in_array($rel_targetvalidcities_loc['module_item_id'], $cluster_with_normal)) {
                                if(!empty($rel_targetvalidcities_loc['longitude']) && !empty($rel_targetvalidcities_loc['latitude']) && !empty($rel_targetvalidcities_loc['targetlocation'])){
                                    $location_type_flag[$rel_targetvalidcities_loc['cod_id']]['location_type'] = 1;
                                    $refine_data[$rel_targetvalidcities_loc['cod_id']]['location_info'] = $rel_targetvalidcities_loc;
                                    break;
                                }
                            } else {
                                if(strtolower($rel_targetvalidcities_loc['location_type']) == "venue address" &&  !empty($rel_targetvalidcities_loc['longitude']) && !empty($rel_targetvalidcities_loc['latitude']) && !empty($rel_targetvalidcities_loc['targetlocation'])){
                                    $refine_data[$rel_targetvalidcities_loc['cod_id']]['location_info'] = $rel_targetvalidcities_loc;
                                }
                            }                                                      
                        }          
                    }
                }
            
                echo ERROR_REFERENCE_NUMBER;
                $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'Request Response', 'data' => json_encode(['request' => $all_last_quries , 'response' => $refine_data , 'error' => $error]), 'api_name' => $api, 'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
                return $refine_data;
            } else {
                return false;
            }
        } else{
            return false;
        }
    }

        /**
     * @name    : get_product_details_from_mmt
     * @Purpose : To get_product_details_from_mmt
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
     */
    
    function get_product_details_from_mmt($pos_data) {
        $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
        $tp_product_id = !empty($third_party_parameters['mmt']['tp_products']['tp_product_id']) ? $third_party_parameters['mmt']['tp_products']['tp_product_id'] : "";
        if($tp_product_id) {
            $url = $this->tp_ota_info['MMT']['tp_end_point']."/product/".$tp_product_id;
            $tp_pro_response = $this->common_request($url, 'GET', "GET_product_info", $tp_product_data=[]);
            return $tp_pro_response;
        } else {
            return false;
        }

    }


        /**
    * @Name : common_update_event()
    * @Purpose : common_update_event
    * @Return params : common_update_event
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 03 May 2020
    */
    /* #region   common_update_event */
    function common_update_event($db_records = [], $request_data = [], $other_data = []){
        $response = [];
        if(!empty($request_data['update_event']['update_venues'])){
            $response['update_venues'] = $this->update_venues($request_data['update_event']['update_venues'], $db_records);
        }
        if(!empty($request_data['update_event']['update_products'])){
            $response['update_products'] = $this->update_products($request_data['update_event']['update_products'], $db_records, $other_data);
        }
        if(!empty($request_data['update_event']['update_rateplans'])){
            $response['update_rateplans'] = $this->update_rateplans($request_data['update_event']['update_rateplans'], $db_records);
        }
        if(!empty($request_data['update_event']['add_rateplans'])){
            $add_update_rateplans = $response['add_rateplans'] = $this->update_add_rateplans($request_data['update_event']['add_rateplans'], $db_records);
        }
        if(!empty($request_data['update_event']['add_media'])){
            $update_create_media = $response['add_media'] = $this->update_create_media($request_data['update_event']['add_media'], $db_records);
        }
        if(!empty($request_data['update_event']['update_media'])){
            $response['update_media'] = $this->update_media($request_data['update_event']['update_media'], $db_records);
        }
        if(!empty($request_data['update_event']['update_cancel_policy'])){
            $response['update_cancel_policy'] = $this->update_cancel_policy($request_data['update_event']['update_cancel_policy'], $db_records);
        }
        
        if(!empty($add_update_rateplans['success']) || !empty($update_create_media) || !empty($response['update_rateplans']) ){
            if(!empty($other_data['ticket_related_to_hotel_ids'])){
                foreach($other_data['ticket_related_to_hotel_ids'] as $hotel_id => $ticket_related_to_hotel_ids){
                    foreach($ticket_related_to_hotel_ids as $ticket_id){
                        $pos_data = !empty($db_records['pos_data'][$hotel_id][$ticket_id]) ? $db_records['pos_data'][$hotel_id][$ticket_id] : [];
                        $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                        $enter = 0;
                        if(!empty($add_update_rateplans['success'][$ticket_id])){
                            foreach($add_update_rateplans['success'][$ticket_id] as $sesion_key => $rateplans){
                                $enter = 1;
                                $third_party_parameters['mmt']['rateplan_data'][$sesion_key]['rate_id'] = $rateplans['rate_id'];
                                $third_party_parameters['mmt']['rateplan_data'][$sesion_key]['is_active'] = 1;
                            }
                        }
                        if(!empty($response['update_rateplans']['success'][$ticket_id])){

                            foreach($response['update_rateplans']['success'][$ticket_id] as $status => $rate_plan_array){
                                $enter = 1;
                                    if($status == 'active') {
                                        foreach ($rate_plan_array as $ticket_id1 => $rate_plan_id) {
                                            $third_party_parameters['mmt']['rateplan_data'][$ticket_id1]['is_active'] = 1;
                                        }                                   
                                    } else {
                                        foreach ($rate_plan_array as $ticket_id1 => $rate_plan_id) {
                                            $third_party_parameters['mmt']['rateplan_data'][$ticket_id1]['is_active'] = 0;
                                        }      
                                    }
                                    
                            }
                        }
                        
                        
                        if(!empty($update_create_media['success'][$ticket_id])){
                            foreach($update_create_media['success'][$ticket_id] as $image_name => $image_id){
                                    $enter = 1;
                                    $third_party_parameters['mmt']['media'][$image_name] = $image_id;
                            }
                        }
                        if(!empty($enter)){
                            $this->db->update("pos_tickets", ["third_party_parameters" => json_encode($third_party_parameters)], ['hotel_id' => $hotel_id, 'mec_id' => $ticket_id]);
                        }
                    }
                }
            }
        }
        return $response;
    }

            /**
     * @name    : update_venues
     * @Purpose : To update_venues
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
     */
    /* #region  To update_venues */
    private function update_venues($update_venues = [], $db_records = []){
        if(!empty($update_venues)){
            foreach($update_venues as $ticket_id => $update_venue){
                $pos_data = !empty($db_records['pos_data'][$update_venue['hotel_id']][$ticket_id]) ? $db_records['pos_data'][$update_venue['hotel_id']][$ticket_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] ? $db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] : []));
                $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                $tp_venue_id = !empty($third_party_parameters['mmt']['venues']['venue_id']) ? $third_party_parameters['mmt']['venues']['venue_id'] : "";
                $supplier_data = $db_records[$ticket_id]['supplier_data'];
                $refine_reltarget = !empty($db_records[$supplier_data['cod_id']]['location_info']) ? $db_records[$supplier_data['cod_id']]['location_info'] : [];
                if(!empty($refine_reltarget) && !empty($tp_venue_id)){
                    if(!empty($refine_reltarget['latitude'])){
                        $venu_request['latitude'] = (float)$refine_reltarget['latitude'];
                    }
                    if(!empty($refine_reltarget['longitude'])){
                        $venu_request['longitude'] = (float)$refine_reltarget['longitude'];
                    }
                    if(!empty($refine_reltarget['targetlocation'])){
                        $venu_request['address'] = $refine_reltarget['targetlocation'];
                    }

                    if(!empty($update_venue['city_id'])){
                        $venu_request['cityId'] = (int)$update_venue['city_id'];
                    }
                    $venu_request['contact'] = !empty($supplier_data['phone']) ? $supplier_data['phone'] : "8007746482";  
                    if(!empty($this->tp_ota_info['MMT']['tp_end_point'])){
                        $url = $this->tp_ota_info['MMT']['tp_end_point']."/venue/".$tp_venue_id;
                        $tp_venue_response = $this->common_request($url, 'PUT', "update_venues", $venu_request);
                        if(!empty($tp_venue_response['status']) && $tp_venue_response['status'] == "success"){
                            $venue_response['success'][$ticket_id][] = $tp_venue_id;
                        } else {
                            $venue_response['error'][$ticket_id] = $tp_venue_response;
                        }
                    } else {
                        $venue_response['error'][$ticket_id] = "R11 :Invalid request.";
                    }
                } else {
                    $venue_response['error'][$ticket_id] = "R12 :Invalid request.";
                }
            }
        } else {
            $venue_response['error'] = "R13 :Invalid request.";
        }
        return $venue_response;
    }

        /**
     * @name    : update_products
     * @Purpose : To update_products
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
     */
    /* #region  To update_products */
    private function update_products($update_products = [], $db_records = [], $other_data = []){
        $tp_response = [];
        foreach($update_products as $ticket_id  => $product){
            $pos_data = !empty($db_records['pos_data'][$product['hotel_id']][$ticket_id]) ? $db_records['pos_data'][$product['hotel_id']][$ticket_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id]) ? $db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] : []);
            $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
            $tp_product_id = !empty($third_party_parameters['mmt']['tp_products']['tp_product_id']) ? $third_party_parameters['mmt']['tp_products']['tp_product_id'] : "";
            if(!empty($third_party_parameters['mmt']['rateplan_data'])) {
                if(isset($product['is_active']) && ($product['is_active'] == 0 || $product['is_active'] == "1")){
                    $rate_plan_of_ticket = $third_party_parameters['mmt']['rateplan_data'];
                    if(!empty($this->tp_ota_info['MMT']['tp_end_point']) && !empty($pos_data)){
                        $tp_product_data['isActive'] = [] ;
                        $tp_product_data['isActive'] = (string) (!empty($product['is_active']) ? $product['is_active'] : 0);
                        $all_rateplain = $rate_linked_with_ticket = [];
                        foreach($rate_plan_of_ticket as $rate_ticket_id => $rate_ticket){
                                $rate_linked_with_ticket[$rate_ticket['rate_id']] = $rate_ticket_id;
                                $all_rateplain[] = (int)$rate_ticket['rate_id'];
                            }
                        if(!empty($all_rateplain)){
                            foreach($all_rateplain as $rateplain){
                                $url = $this->tp_ota_info['MMT']['tp_end_point']."/rateplan/".(int)$rateplain;
                                $tp_pro_response = $this->common_request($url, 'PUT', "update_product", $tp_product_data);
                                if(!empty($tp_pro_response['status']) && $tp_pro_response['status'] == "success"){
                                    if(!empty($tp_product_data['isActive'])) {
                                        $tp_response['success'][$ticket_id]['active'][$rate_linked_with_ticket[$rateplain]] = $rateplain;
                                    } else {
                                        $tp_response['success'][$ticket_id]['in_active'][$rate_linked_with_ticket[$rateplain]] = $rateplain;
                                    }
                                } else {
                                    $tp_response['error'][$ticket_id] = $tp_pro_response;
                                }
                            }
                        } else {
                            $tp_response['error'][$ticket_id] = "R5 :Empty rateplan."; 
                        }
                    } else {
                            $tp_response['error'][$ticket_id] = "R6 :Invalid endpoint or empty pos_data.";
                    }
                } else {
                    $product_data = $this->prepaired_product_data("update", $ticket_id, $db_records);
                    if(!empty($this->tp_ota_info['MMT']['tp_end_point']) && !empty($product_data) && empty($product_data['error'])){
                        $url = $this->tp_ota_info['MMT']['tp_end_point']."/product/".$tp_product_id;
                        $tp_pro_response = $this->common_request($url, 'PUT', "update_product", $product_data);
                        if(!empty($tp_pro_response['status']) && $tp_pro_response['status'] == "success"){
                            $tp_response['success'][$ticket_id]['basic_updation'] = $tp_product_id;
                        } else {
                            $tp_response['error'][$ticket_id] = $tp_pro_response;
                        }
                    } else {
                            $tp_response['error'][$ticket_id] = "R7 :Empty response.";
                    }
                }
            }   
            else {
                $tp_response['error'][$ticket_id] = "R7.1 :Empty third_party params.";
            }
        }
        return $tp_response;
    }

        /**
     * @name    : update_rateplans
     * @Purpose : To update_rateplans
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
     */
    /* #region   To update_rateplans */
    private function update_rateplans($update_rateplans = [], $db_records = []){
        if(!empty($update_rateplans)){
            foreach($update_rateplans as $ticket_id => $update_rateplan){
                if(!empty($update_rateplan)){
                    foreach($update_rateplan as $rateplan){
                        $pos_data = !empty($db_records['pos_data'][$rateplan['hotel_id']][$ticket_id]) ? $db_records['pos_data'][$rateplan['hotel_id']][$ticket_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] ? $db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] : []));
                        $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                        if(!empty($db_records[$ticket_id]['tps_data'])){
                            $tps_data = $db_records[$ticket_id]['tps_data'];
                        }
                        $rate_plan_of_ticket = !empty($third_party_parameters['mmt']['rateplan_data']) ? $third_party_parameters['mmt']['rateplan_data'] : [];
                        if(isset($rateplan['is_active']) && ($rateplan['is_active'] == 0 || $rateplan['is_active'] == 1)){
                            if(!empty($rate_plan_of_ticket) && !empty($rateplan['ticket_ids'])){
                                $all_rateplain = $rate_linked_with_ticket = [];
                                foreach($rateplan['ticket_ids'] as $rate_ticket){
                                    if(!empty($rate_plan_of_ticket[$rate_ticket]['rate_id']) && !in_array($rate_plan_of_ticket[$rate_ticket]['rate_id'], $all_rateplain)){
                                        $all_rateplain[] = $rate_plan_of_ticket[$rate_ticket]['rate_id'];
                                        $rate_linked_with_ticket[$rate_plan_of_ticket[$rate_ticket]['rate_id']] = $rate_ticket;
                                    }
                                }
                                if(!empty($all_rateplain)){
                                    $tp_rateplan_data['isActive'] = (string) $rateplan['is_active'];
                                    foreach($all_rateplain as $rateplain){
                                        $url = $this->tp_ota_info['MMT']['tp_end_point']."/rateplan/".$rateplain;
                                        $tp_pro_response = $this->common_request($url, 'PUT', "update_rateplans_is_active ", $tp_rateplan_data);
                                        if(!empty($tp_pro_response['status']) && $tp_pro_response['status'] == "success"){
                                            if(!empty($rateplan['is_active'])){
                                                $respone['success'][$ticket_id]['active'][$rate_linked_with_ticket[$rateplain]] = $rateplain;
                                            } else {
                                                $respone['success'][$ticket_id]['in_active'][$rate_linked_with_ticket[$rateplain]] = $rateplain;
                                            }
                                        } else {
                                            $respone['error'][$ticket_id][$rate_linked_with_ticket[$rateplain]] = 'Issue with '.$rateplain. 'rate_id'.json_encode($tp_pro_response);
                                        }
                                    }
                                } else {
                                    $respone['error'][$ticket_id] = "R22 :Empty rateplan."; 
                                }
                            } else {
                                $respone['error'][$ticket_id] = "R23 :No data found in db.";
                            }
                        } else {
                            $respone[$ticket_id]['update_rateplan_basic_info'] = $this->update_rateplan_basic_info($ticket_id, $rateplan, $db_records, $rate_plan_of_ticket);
                        }
                    }
                } else {
                    $respone['error'][$ticket_id] = "R25 :Empty request.";
                }
            }
        } else {
            $respone['error'] = "R26 :Invalid request.";
        }
        return $respone;
    }

        /**
     * @name    : add_update_rateplans
     * @Purpose : To add_update_rateplans
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on May 05,2020
     */
    /* #region To add_update_rateplans  */
    private function update_add_rateplans($add_update_rateplans = [], $db_records = []){
        $rateplain_response = [];
        foreach($add_update_rateplans as $product_id => $requested_tps_ids){
            $pos_data = !empty($db_records['pos_data'][$requested_tps_ids['hotel_id']][$product_id]) ? $db_records['pos_data'][$requested_tps_ids['hotel_id']][$product_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$product_id]) ? $db_records['pos_data'][$_REQUEST['hotel_id']][$product_id] : []);
            $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
            $tp_product_id = !empty($third_party_parameters['mmt']['tp_products']['tp_product_id']) ? $third_party_parameters['mmt']['tp_products']['tp_product_id'] : "";
            $is_cluster_ticket = 0;
            if($db_records[$product_id]['mec_data']['product_type'] == 3 && !empty($db_records[$product_id]['cluster_tickets'])){
                $is_cluster_ticket = 1;
                $rate_tickets = $db_records[$product_id]['cluster_tickets'];
            } else {
                $rate_tickets[] =  $product_id;
            }
            foreach($rate_tickets as $rate_ticket_id){
                if(empty($is_cluster_ticket)){
                    $product_data = $db_records[$product_id]['mec_data'];
                    $ticket_id = $product_id;
                } else {
                    $ticket_id = $rate_ticket_id;
                    $product_data = $db_records['cluster_tickets'][$rate_ticket_id];
                }
            if($is_cluster_ticket == 1 && !in_array($ticket_id, $requested_tps_ids['ticket_ids'])){
                continue;
            }
            if(isset($db_records[$ticket_id]['pickup_points']['is_mandatory']) && in_array($db_records[$ticket_id]['pickup_points']['is_mandatory'], ["1", "2"])){
                $dynamic_form_info  = $this->create_dynamicform($ticket_id, $db_records);
            }
            $vendorRatePlanData = [];
            if(!empty($tp_product_id)) { 
                if(empty($is_cluster_ticket)){
                    $product_data = $db_records[$ticket_id]['mec_data'];
                } else {
                    $product_data = $db_records['cluster_tickets'][$ticket_id];
                }
                $tps_data_seasion = $ticket_prices = $tps_data = $seasion_dates = [];
                $display_price = $price_level_default = $tps_data_seasion_default_price = "";
                if(!empty($db_records['ticket_prices'][$ticket_id])){
                    $ticket_prices = $db_records['ticket_prices'][$ticket_id];
                }
                if(!empty($db_records[$ticket_id]['tps_data'])){
                    $tps_data = $db_records[$ticket_id]['tps_data'];
                }
                foreach($tps_data as $tps_data_v1){
                    if(empty($tps_data_v1['deleted']) && $tps_data_v1['end_date'] > strtotime(gmdate('Y-m-d H:i:s'))){
                        $seasion_dates[] = $tps_data_v1['start_date'];
                        $seasion_dates[] = $tps_data_v1['end_date'];
                        $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['seasion_startdate'] = $tps_data_v1['start_date'];
                        $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['seasion_enddate'] = $tps_data_v1['end_date'];
                        if(in_array(strtoupper($tps_data_v1['ticket_type_label']), ['ALL AGE', 'ALL_AGES', 'ALLAGE', 'ALLAGES', 'ALL AGES', 'ALL_AGE']) || ($tps_data_v1['agefrom'] == 1 && $tps_data_v1['ageto'] == "99")){
                            $rticket_type_label = "PERSON";
                        } else {
                            $rticket_type_label = strtoupper($tps_data_v1['ticket_type_label']);
                        }
                        $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['ticket_type'][$rticket_type_label] = $tps_data_v1['ticketpriceschedule_id'];
                        if(!empty($tps_data_v1['default_listing']) && !empty($tps_data_v1['newPrice']) && empty($tps_data_seasion_default_price)){
                            $tps_data_seasion_default_price = $tps_data_v1['newPrice'];
                        }
                    }
                }
                    if(!empty($ticket_prices)){
                    foreach($ticket_prices as $prices){
                        if(empty($prices['deleted']) && !empty($prices['default_listing']) && !empty($prices['ticket_gross_price']) && empty($price_level_default)){
                            $price_level_default = $prices['ticket_gross_price'];
                        }
                    }
                }
                $rateplan_data = [];
                $rateplan_data['name'] = $product_data['postingEventTitle'];
                if(!empty($product_data['is_cut_off_time']) && !empty($product_data['cut_off_time'])) {
                    $cutoff = $this->time_to_decimal($product_data['cut_off_time']);
                    if(!empty($cutoff)){
                        $rateplan_data['cutOff'] = (int) $cutoff;
                    }
                }
                $rateplan_data['startDate'] = gmdate("Y-m-d", strtotime(getDateTimeWithTimeZone($product_data['local_timezone_name'], gmdate("Y-m-d H:i:s", min($seasion_dates)), 1))); 
                $rateplan_data['endDate'] = gmdate("Y-m-d", strtotime(getDateTimeWithTimeZone($product_data['local_timezone_name'], gmdate("Y-m-d H:i:s", max($seasion_dates)), 1)));
                if(empty($product_data['is_reservation'])){
                    $rateplan_data['calculationType'] = "Booking Date";
                    $rateplan_data['validityDays'] = (int)7;
                }
                if(!empty($product_data['min_order_qty'])){
                    $rateplan_data['minimumOccupancy'] = (int)$product_data['min_order_qty'];
                }
                if(!empty($product_data['max_order_qty'])){
                    $rateplan_data['maximumOccupancy'] = ($product_data['max_order_qty'] <= 99 ) ? (int)$product_data['max_order_qty'] : 99;
                }
                $rateplan_data['inclusions'] = !empty($product_data['whats_included']) ? explode("~~~", $product_data['whats_included']) : ['No inclusions'];
                $rateplan_data['isSlotAvailable'] = true;
                $duration = $this->time_to_decimal($product_data['duration']);
                if(!empty($duration)){
                    $rateplan_data['duration'] = (int) $duration;
                }
                $display_price = !empty($price_level_default) ? $price_level_default : (!empty($tps_data_seasion_default_price) ? $tps_data_seasion_default_price : "");
                if(!empty($display_price)) { 
                    $rateplan_data['mrp'] = (float) round($display_price, 2);
                }
                $rateplan_data['vendorRateplanCode'] = (string)$ticket_id;
                $rateplan_data['vendorRatePlanData'] = json_encode($vendorRatePlanData);
                $rateplan_data['isActive'] = (string)"1";
                if(!empty($dynamic_form_info)){
                    $rateplan_data['dynamicForm'] = json_encode($dynamic_form_info);
                }
                if(!empty($this->tp_ota_info['MMT']['tp_end_point']) && !empty($tp_product_id)){
                    $url = $this->tp_ota_info['MMT']['tp_end_point']."/product/".$tp_product_id."/rateplan";
                    $tp_rate_response = $this->common_request($url, 'POST', "update_add_rateplans", $rateplan_data);
                    if(!empty($tp_rate_response['rateplanId'])){
                        $rateplain_response['success'][$product_id][$ticket_id]['rate_id'] = $tp_rate_response['rateplanId'];
                        $rateplain_response['success'][$product_id][$ticket_id]['is_active'] = 1;
                    } else {
                        $rateplain_response['error'][$product_id][$ticket_id] = $tp_rate_response;
                    }
                } else {
                    $rateplain_response['error'][$product_id][$ticket_id] = "R28 :TP endpoint or tp_ticket is empty.";
                }
            } else {
                $rateplain_response['error'][$product_id] = "R29 :TP ticket_id is empty.";
            }
        }
        }
        return $rateplain_response;
    }

            /**
    * @name    : update_create_media
    * @Purpose : To update_create_media
    * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 06,2020
    */
    /* #region   update_create_media */
    private function update_create_media($update_create_media = [], $db_records = []){
        global $filelogs;
        global $graylogs;
        $api = "update_create_media";
        $tp_product_data = $response = [];
        if(!empty($this->tp_ota_info['MMT']['tp_end_point']) && !empty($this->config->item('prio_admin_base_url'))){
            foreach ($update_create_media as $product_id => $response_data) {
                $product_data = $db_records[$product_id]['mec_data'];
                $pos_data = !empty($db_records['pos_data'][$response_data['hotel_id']][$product_id]) ? $db_records['pos_data'][$response_data['hotel_id']][$product_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$product_id]) ? $db_records['pos_data'][$_REQUEST['hotel_id']][$product_id] : []);
                $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                $tp_product_id = !empty($third_party_parameters['mmt']['tp_products']['tp_product_id']) ? $third_party_parameters['mmt']['tp_products']['tp_product_id'] : "";
                $all_files = [];
                if(!empty($response_data['images']) && !empty($tp_product_id)){
                    foreach($response_data['images'] as $image_name){
                        if(!empty($image_name)){
                            $file = '';
                            if(SERVER_ENVIRONMENT == 'Local') {
                                $file = $this->config->item('prio_admin_base_url').'/qrcodes/images/tickets/'.$product_data['cod_id'].'/xl_thumbnails/'.$image_name;
                            } else {
                                $file = $this->config->item('prio_admin_base_url').'/tickets/'.$product_data['cod_id'].'/NEW/480x270_'.$image_name;
                            }
                            $all_files[$product_id][] = $file;
                        }
                    }
                    $tp_media_endpoints['tp_'.$product_id] = $this->tp_ota_info['MMT']['tp_end_point'].'/product/'.$tp_product_id.'/media';
                } else {
                    $response['error'][$product_id] = "R35 : Invalid request.";
                }
            }
            if (!empty($all_files)) {
                $tp_product_data['media_data'] = $all_files;
                $tp_product_data['tp_end_points'] = $tp_media_endpoints;
                $headers = [
                    'x-auth:' . $this->tp_ota_info['MMT']['authorization'],
                    'Content-Type:application/json',
                    'Authorization: Basic '. base64_encode($this->event_verification_credentials['user'].':'.$this->event_verification_credentials['password'])
                ];
                $url = STAPI_SERVER_URL.'/mmt_media_event.php';
                $output = common_curl::send_request($url, 'POST', json_encode($tp_product_data), $headers);
                if (!empty($output['response'])) {
                    $response = json_decode($output['response'], true);
                } else {
                    $response = $output;
                }
            } else {
                $response['error'] = "R17.1 :Empty media.";
            }
        } else {
            $response['error'] = "R17 :Invalid Tp_endpoint or admin endpoint.";
        }
        $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'Request Response', 'data' => json_encode(['request' => $tp_product_data , 'response' => $response]), 'api_name' => $api, 'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
        return $response;    
    }

            /**
    * @name    : update_media
    * @Purpose : To update_media
    * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on Apr 21,2020
    */
    /* #region  To update_media */
    private function update_media($update_media = [], $db_records = []){
        global $filelogs;
        global $graylogs;
        $api = "update_media";
        $response = [];
        if(!empty($update_media)){
            foreach($update_media as $ticket_id => $medias){
                    if(!empty($medias) && !empty($this->tp_ota_info['MMT']['tp_end_point'])){
                    foreach($medias as $image_name => $media){
                        $hotel_id = !empty($media['basic_info']['hotel_id']) ? $media['basic_info']['hotel_id'] : (!empty($media['hotel_id']) ? $media['hotel_id'] : "");
                        $pos_data = !empty($db_records['pos_data'][$hotel_id][$ticket_id]) ? $db_records['pos_data'][$hotel_id][$ticket_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id]) ? $db_records['pos_data'][$_REQUEST['hotel_id']][$ticket_id] : []);
                        $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                        if(!empty($media['media_key'])){
                            if(isset($media['is_active']) && $media['is_active'] == 0){
                                $url = $this->tp_ota_info['MMT']['tp_end_point']."/media/".$media['media_key'];
                                $media_request = [];
                                $media_request['isactive'] = false;
                                $media_request['print_log'] = 1;
                                $tp_response = $this->common_request($url, 'PUT', "update_media", $media_request);
                                if(!empty($tp_response['status']) && $tp_response['status'] == "success"){
                                    $response['success'][$ticket_id][$image_name] = "success";
                                } else {
                                    $response['error'][$ticket_id][$image_name] = $tp_response;
                                }
                            } else {
                                $url = $this->tp_ota_info['MMT']['tp_end_point']."/media/".$media['media_key'];

                                $media_request = [];
                                $media_request['isactive'] = true;
                                if(!empty($media['basic_info']['priority'])){
                                    $media_request['priority'] = (int)$media['basic_info']['priority'];
                                    if($media['basic_info']['priority'] == 1){
                                        $media_request['description'] = "main";
                                        $media_request['imageTag'] = "main";
                                    } else if($media['basic_info']['priority'] == 2){
                                        $media_request['description'] = "cover";
                                        $media_request['imageTag'] = "cover";
                                    } else {
                                        $media_request['description'] = "this is what to expect image";
                                        $media_request['imageTag'] = "what_to_expect";
                                    }
                                }
                                $tp_response = $this->common_request($url, 'PUT', "update_media", $media_request);
                                if(!empty($tp_response['status']) && $tp_response['status'] == "success"){
                                    $response['success'][$ticket_id][$image_name] = $third_party_parameters['mmt']['media'][$image_name];
                                } else {
                                    $response['error'][$ticket_id][$image_name] = $tp_response;
                                }
                            }
                        } else {
                            $response['error'][$ticket_id][$image_name] = "R18 :Image ID not found.";
                        }
                    }
                } else {
                    $response['error'][$ticket_id] = "R19 :Invalid request or endpoint.";
                }
            }
        } else {
            $response['error'] = "R20 :Invalid media.";
        }
        return $response;
    }

        /**
    * @name    : update_cancel_policy
    * @Purpose : To update_cancel_policy
    * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on May 07,2020
    */
    /* #region update_cancel_policy  */
    private function update_cancel_policy($cancel_policy_ticket = [], $db_records = []){
        global $filelogs;
        global $graylogs;
        $cancel_policy_response = [];
        if(!empty($cancel_policy_ticket)){
            foreach($cancel_policy_ticket as $product_id => $ticket_data){
                $pos_data = !empty($db_records['pos_data'][$ticket_data['hotel_id']][$product_id]) ? $db_records['pos_data'][$ticket_data['hotel_id']][$product_id] : (!empty($db_records['pos_data'][$_REQUEST['hotel_id']][$product_id]) ? $db_records['pos_data'][$_REQUEST['hotel_id']][$product_id] : []);
                $third_party_parameters = !empty($pos_data['third_party_parameters']) ? json_decode($pos_data['third_party_parameters'], true) : [];
                $tp_product_id = !empty($third_party_parameters['mmt']['tp_products']['tp_product_id']) ? $third_party_parameters['mmt']['tp_products']['tp_product_id'] : "";
                    if(!empty($tp_product_id) && !empty($this->tp_ota_info['MMT']['tp_end_point'])){
                        $cancel_request = $cancel_policy = [];
                        if(!empty($ticket_data['is_cancelable'])) {
                        $cancel_policy['chargesStartDay'] = (int) 365;
                        $cancel_policy['chargesEndDay'] = (int) (!empty($ticket_data['cancelday_count']) ? $ticket_data['cancelday_count'] : 0);
                        $cancel_policy['chargesType'] = (string) "percent";
                        $cancel_policy['chargesValue'] = (int) 0;
                        $cancel_policy['description'] = (string) (!empty($ticket_data['is_cancelable']) ? "100% refund on cancellation" : "No refund on cancellation");
                        $cancel_request['cancellationPolicies'][] = $cancel_policy;
                        if(!empty($cancel_policy['chargesEndDay'])){
                            $cancel_policy['chargesStartDay'] = (int) $cancel_policy['chargesEndDay'];
                            $cancel_policy['chargesEndDay'] = (int) 0;
                            $cancel_policy['chargesType'] = (string) "percent";
                            $cancel_policy['chargesValue'] = (int) 100;
                            $cancel_policy['description'] = (string) "No refund on cancellation";
                            $cancel_request['cancellationPolicies'][] = $cancel_policy;
                        }
                    } else {
                        $cancel_policy['chargesStartDay'] = (int) 365;
                        $cancel_policy['chargesEndDay'] = (int) 0;
                        $cancel_policy['chargesType'] = (string) "percent";
                        $cancel_policy['chargesValue'] = (int) 100;
                        $cancel_policy['description'] = (string) "No refund on cancellation";
                        $cancel_request['cancellationPolicies'][] = $cancel_policy;
                    }
                    if(!empty($cancel_request)){
                        $url = $this->tp_ota_info['MMT']['tp_end_point']."/product/".$tp_product_id."/cancellationPolicy";
                        $tp_cancelpolicy_response = $this->common_request($url, 'POST', "update_cancel_policy", $cancel_request);
                        if(!empty($tp_cancelpolicy_response['status']) && $tp_cancelpolicy_response['status'] == "success"){
                            $cancel_policy_response['success'][$product_id]['status'] = "success";
                        } else {
                            $cancel_policy_response['error'][$product_id] = $tp_cancelpolicy_response;
                        }
                    } else {
                        $cancel_policy_response['error'][$product_id] = "R31 :Invalid request or empty tp endpoint.";
                    }
                } else {
                    $cancel_policy_response['error'][$product_id] = "R32 :Invalid request.";
                }
            }
        }
        return $cancel_policy_response;
    }

     /**
     * @Name        : get_constant()
     * @Purpose     : To get the constant values from API'S
     * @created by: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> on May 11,2020
     */
    /* #region  get_constant */
    private function get_constant(){
        global $api_global_constant_values;
        $constants[] = 'TP_PICKUP_LINKED_WITH_REL_TARGET';
        $constants[] = "CRON_REDIS_SERVER";
        $constants[] = "BATCH_REDIS_SERVER";
        $constants[] = "API_REDIS_SERVER";
        $constants[] = "REDIS_AUTH_KEY";
        $constants[] = "DEFAULT_CAPACITY";
        $constants[] = "CAPACITY_FROM_REDIS";
        $constants[] = "ENVIRONMENT";
        $constants[] = "NOTIFICATION_EMAILS";
        $this->common_get_constant($constants);
    }
    
         /**
    * @Name : get_ticget_ticket_price_mm()
    * @Purpose :Get ticket price from ticket_level_commissions and if is not present in ticket_level_commissions, then get value from channel_level_commission
    * @Return params : return price from tlc or clc.
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 21 Apr 2020
    */
   /* #region  get_ticket_price_mmt  */
    function get_ticket_price_mmt($distributer_details = [], $ids, $tps_id = [], $other_data = [])
   {
       $ticket_price_array = [];
       if (!empty($other_data['ticket_related_to_hotel_ids'])) {
           foreach ($other_data['ticket_related_to_hotel_ids'] as $hotel_id => $ticket_id) {
               $channel_id = $distributer_details[$hotel_id]['channel_id'];
               if (!is_array($tps_id)) {
                   $tps_id = array($tps_id);
               }
               $tps_id = array_filter($tps_id);
               $this->primarydb->db->select("*");
               $this->primarydb->db->from("ticket_level_commission");
               $this->primarydb->db->where("hotel_id", $hotel_id);
               if (!is_array($ids)) {
                   $this->primarydb->db->where("ticket_id", $ids);
               } else {
                   $this->primarydb->db->where_in("ticket_id", $ids);
               }
               $this->primarydb->db->where("is_adjust_pricing", "1");
               if (!empty($tps_id)) {
                   $this->primarydb->db->where_in("ticketpriceschedule_id", $tps_id);
               }
               $query = $this->primarydb->db->get();
               if ($query->num_rows() > 0) {
                   $details_from_tlc = $query->result_array();
                   foreach ($details_from_tlc as $detail) {
                       if (!is_array($ids)) {
                           $ticket_price_array[$detail['ticketpriceschedule_id']] = $detail;
                       } else {
                           $ticket_price_array[$detail['ticket_id']][$detail['ticketpriceschedule_id']] = $detail;
                       }
                   }
               } elseif ($channel_id != '') {
                   $this->primarydb->db->select("*");
                   $this->primarydb->db->from("channel_level_commission");
                   $this->primarydb->db->where("channel_id", $channel_id);
                   if (!is_array($ids)) {
                       $this->primarydb->db->where("ticket_id", $ids);
                   } else {
                       $this->primarydb->db->where_in("ticket_id", $ids);
                   }
                   $this->primarydb->db->where("is_adjust_pricing", "1");
                   if (!empty($tps_id)) {
                       $this->primarydb->db->where_in("ticketpriceschedule_id", $tps_id);
                   }
                   $query = $this->primarydb->db->get();
                   if ($query->num_rows() > 0) {
                       $details_from_clc = $query->result_array();
                       foreach ($details_from_clc as $detail) {
                           if (!is_array($ids)) {
                               $ticket_price_array[$detail['ticketpriceschedule_id']] = $detail;
                           } else {
                               $ticket_price_array[$detail['ticket_id']][$detail['ticketpriceschedule_id']] = $detail;
                           }
                       }
                   }
               }
           }
       } 
       return $ticket_price_array;
   }
  /* #endregion */

      /**
    * @name: send_email()
    * @created by:Prabhdeep kaur <prabhdeep.intersoft@gmail.com> on 11 May, 2020
    */
   /* #region   send_email */
   public function send_email($call_from_cron = 0, $hotel_id = '', $requets_data = [], $callfromwebhoook = 0){
    if (!empty($requets_data)) {
        global $api_global_constant_values;
        $this->load->model('sendemail_model');
        $mail_data = $event_details = [];
        $msg = '';
        if (!empty($hotel_id)) {
            $msg .='<b>Hotel id : </b>' . $hotel_id . '<br/>';
        }
        $msg .= '<b>Call from cron: </b>' . $call_from_cron . '<br>';
        if ($callfromwebhoook == 1) {
            $msg .=
                '<b>Error : </b> Product rateplan is_active is 0' . '<br>';
        } else {
            $msg .=
                '<b>Error : </b> Capacity_from_redis is 0' . '<br>';
        }
        if (!empty($requets_data)) {
            $msg .= '<b>Request : </b> ' . json_encode($requets_data) . '<br>';
        }
        
        $mail_data['emailc'] = $api_global_constant_values['NOTIFICATION_EMAILS']['value'];
        $mail_data['html'] = $msg;
        $mail_data['from'] = PRIOPASS_NO_REPLY_EMAIL;
        $mail_data['from_email'] = PRIOPASS_NO_REPLY_EMAIL;
        $mail_data['fromname'] = MESSAGE_SERVICE_NAME;
        $mail_data['subject'] = SERVER_ENVIRONMENT. (($callfromwebhoook == 1) ? ' Product not auto updated at MMT end' :  ' Inventory not updated at MMT end');
        $mail_data['attachments'] = [];
        $mail_data['BCC'] = [];
        $event_details['send_email_details'][] = $mail_data;
        if (!empty($event_details['send_email_details'])) {
            $this->common_sqs_server_call(QUEUE_URL_EVENT, 'EVENT_TOPIC_ARN', EVENT_TOPIC_ARN, $event_details);
            }
         }
    }
        /**
    * @Name : prepaired_product_data()
    * @Purpose : prepaired_product_data
    * @Return params : prepaired_product_data
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 03 May 2020
    */
    /* #region  prepaired_product_data */
    private function prepaired_product_data($request_type = "create", $product_id = "", $db_records = [], $request_data = []){
        $supplier_data = !empty($db_records[$product_id]['supplier_data']) ? $db_records[$product_id]['supplier_data'] : [];
        $display_price = $default_price = '';
        $ticket_prices = $tps_data = [];
        if(!empty($db_records['ticket_prices'][$product_id])){
            $ticket_prices = $db_records['ticket_prices'][$product_id];
        }
        if(!empty($db_records[$product_id]['tps_data'])){
            $tps_data = $db_records[$product_id]['tps_data'];
        }
        if(!empty($ticket_prices)){
            foreach($ticket_prices as $prices){
                if(empty($prices['deleted']) && !empty($prices['default_listing']) && !empty($prices['ticket_gross_price'])){
                    $display_price = $prices['ticket_gross_price'];
                    break;
                }
            }
        }
        if(!empty($tps_data) && empty($display_price)){
            foreach($tps_data as $tpprices){
                if(empty($tpprices['deleted']) && $tpprices['end_date'] > strtotime(gmdate('Y-m-d H:i:s')) && !empty($tpprices['default_listing']) && !empty((int)$tpprices['newPrice'])){
                    $display_price = $tpprices['newPrice'];
                    break;
                } else if(in_array(strtoupper($tpprices['ticket_type_label']), ["ADULT", "PERSON", "SENIOR", "FAMILY"]) && !empty((int)$tpprices['newPrice']) && empty($default_price)){
                    $default_price = $tpprices['newPrice'];
                }
            }
        }
        if(empty((int)$display_price)){
            if(!empty($default_price)){
                $display_price = $default_price;
            } else {
                $response['error'] = "DisplayPrice can't be blank";
                return $response;
            }
        }
        $product_data = !empty($db_records[$product_id]['mec_data']) ? $db_records[$product_id]['mec_data'] : [];
        if(empty($product_data['deleted']) && $product_data['active'] == 1 && $product_data['endDate'] >= strtotime(gmdate('Y-m-d H:i:s'))){
            $desc = !empty($product_data['longDesc']) ? $product_data['longDesc'] : (!empty($product_data['super_desc']) ? $product_data['super_desc'] : (!empty($product_data['MoreDesc']) ? $product_data['MoreDesc'] : (!empty($product_data['shortDesc']) ? $product_data['shortDesc'] : $product_data['postingEventTitle'])));
            $instantConfirmation = false;
            if(empty($product_data['is_reservation'])){
                $instantConfirmation = true;
            }
            
            if($db_records[$product_id]['mec_data']['product_type'] == 3) {
                $instantConfirmation = false;
            }
                      
            $duration = $this->time_to_decimal($product_data['duration']);
            $tp_product_data['title'] = $product_data['postingEventTitle'];
            $tp_product_data['description'] = $desc;
            $tp_product_data['inclusions'] = !empty($product_data['whats_included']) ? explode("~~~", $product_data['whats_included']) : ['No inclusions'];
            $tp_product_data['exclusions'] = !empty($product_data['whats_not_included']) ? explode("~~~", $product_data['whats_not_included']) : ['No exclusions'];
            $tp_product_data['tnc'][] = !empty($product_data['termsConditions']) ? $product_data['termsConditions'] : "No termsConditions";
            $tp_product_data['duration'] = (int) !empty($duration) ? $duration : 0;
            $tp_product_data['displayPrice'] = (float) round($display_price, 2);
            $tp_product_data['mrp'] = (float) round($display_price, 2);
            $tp_product_data['whyShouldIDoThis'] = !empty($product_data['highlights']) ? explode("~~~", $product_data['highlights']) : [$product_data['postingEventTitle']];
            $tp_product_data['howToRedeem'][] = !empty($product_data['scan_info']) ? $product_data['scan_info'] : "No scan info";
            $tp_product_data['whatToExpect'][] = !empty($product_data['guest_notification']) ? $product_data['guest_notification'] : "No guest notification";
            $currency_code = !empty($supplier_data['currency_code']) ? $supplier_data['currency_code'] : $product_data['currency_code'];
            if(!empty($currency_code)) {
                $tp_product_data['currencyCode'] = $currency_code;
            }
            if($request_type == "create"){
                
                $tp_product_data['tag'] = (!empty($request_data['create_products'][$product_id]['tag'])) ? $request_data['create_products'][$product_id]['tag'] : array(143);
                $tp_product_data['essence'] = (!empty($request_data['create_products'][$product_id]['essence'])) ? $request_data['create_products'][$product_id]['essence'] : array(462);
                $tp_product_data['amenities'] = (!empty($request_data['create_products'][$product_id]['amenities'])) ? $request_data['create_products'][$product_id]['amenities'] : array(235);
                $tp_product_data['unitType'] = "adult";
                $tp_product_data['vendorProductCode'] = (string) $product_data['mec_id'];
                $tp_product_data['isFreehold'] = $instantConfirmation;
                $tp_product_data['instantConfirmation'] = $instantConfirmation;
            }
            return $tp_product_data;
        } else {
            return false;
        }
    }

        /**
    * @Name : time_to_decimal()
    * @Purpose : time_to_decimal
    * @Return params : return time in decimal
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 21 Apr 2020
    */
    /* #region  time_to_decimal  */
    private function time_to_decimal($time = '') {
        if(!empty($time)){
            $timeArr = explode(':', $time);
            $hour = $min =  0;
            if(count($timeArr) == 1){
                if (strpos($time,'hour') !== false) {
                    $hour = (int)trim($time);
                } else if(strpos($time, 'min') !== false){
                    $min = (int)trim($time);
                } 
            } else {
                if(isset($timeArr[0])){
                  $hour = (int)trim($timeArr[0]);  
                }
                if(isset($timeArr[1])){
                  $min = (int)trim($timeArr[1]);  
                }
            }
            return  ($hour * 60) + $min;

        } else {
            return false;
        }
    }

         /**
    * @Name : update_rateplan_basic_info()
    * @Purpose : update_rateplan_basic_info
    * @Return params : update_rateplan_basic_info
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 05 May 2020
    */
   /* #region   update_rateplan_basic_info */
   private function update_rateplan_basic_info($product_id = '', $rateplan = [], $db_records = [], $rate_plan_of_ticket = []){
    $product_data = !empty($db_records[$product_id]['mec_data']) ? $db_records[$product_id]['mec_data'] : [];
    $is_cluster_ticket = 0;
    if($product_data['product_type'] == 3 && !empty($db_records[$product_id]['cluster_tickets'])){
        $is_cluster_ticket = 1;
        $rate_tickets = $db_records[$product_id]['cluster_tickets'];
    } else {
         $rate_tickets[] =  $product_id;
    }
    $rateplain_response = $seasion_dates = [];
    foreach($rate_tickets as $rate_ticket){
        if(empty($is_cluster_ticket)){
            $product_data = $db_records[$product_id]['mec_data'];
            $ticket_id = $product_id;
        } else {
            $ticket_id = $rate_ticket;
            $product_data = $db_records['cluster_tickets'][$rate_ticket];
        }
        if(isset($rateplan['is_all_rateplan']) && $rateplan['is_all_rateplan'] != 1){
            if(empty($rateplan['ticket_ids']) || !in_array($ticket_id, $rateplan['ticket_ids'])){
                continue;
            } 
        }
        $ticket_prices = $tps_data = $vendorRatePlanData = [];
        $display_price =  $price_level_default = $default_tps_price = "";
        if(!empty($db_records['ticket_prices'][$ticket_id])){
            $ticket_prices = $db_records['ticket_prices'][$ticket_id];
        }
        if(!empty($db_records[$ticket_id]['tps_data'])){
            $tps_data = $db_records[$ticket_id]['tps_data'];
            foreach($tps_data as $tps_data_v1){
                $seasion_dates[] = $tps_data_v1['start_date'];
                $seasion_dates[] = $tps_data_v1['end_date'];
                $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['seasion_startdate'] = $tps_data_v1['start_date'];
                $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['seasion_enddate'] = $tps_data_v1['end_date'];
                if(in_array(strtoupper($tps_data_v1['ticket_type_label']), ['ALL AGE', 'ALL_AGES', 'ALLAGE', 'ALLAGES', 'ALL AGES', 'ALL_AGE']) || ($tps_data_v1['agefrom'] == 1 && $tps_data_v1['ageto'] == "99")){
                    $rticket_type_label = "PERSON";
                } else {
                    $rticket_type_label = strtoupper($tps_data_v1['ticket_type_label']);
                }
                $vendorRatePlanData[$tps_data_v1['start_date'].'-'.$tps_data_v1['end_date']]['ticket_type'][$rticket_type_label] = $tps_data_v1['ticketpriceschedule_id'];
                if(!empty($tps_data_v1['default_listing']) && !empty($tps_data_v1['newPrice']) && empty($default_tps_price)){
                    $default_tps_price = $tps_data_v1['newPrice'];
                }
            }
        }
        if(!empty($ticket_prices)){
            foreach($ticket_prices as $prices){
                if(empty($prices['deleted']) && !empty($prices['default_listing']) && !empty($prices['ticket_gross_price']) && empty($price_level_default)){
                    $price_level_default = $prices['ticket_gross_price'];
                    break;
                }
            }
        }
        if(isset($db_records[$ticket_id]['pickup_points']['is_mandatory']) && in_array($db_records[$ticket_id]['pickup_points']['is_mandatory'], ["1", "2"])){
            $dynamic_form_info  = $this->create_dynamicform($ticket_id, $db_records);
        }
      
        if(!empty($tps_data) && !empty($this->tp_ota_info['MMT']['tp_end_point'])) {
            $rateplan_data = [];
            if(!empty($product_data['postingEventTitle'])){
                $rateplan_data['name'] = $product_data['postingEventTitle'];
            }
            //add startDate in request but not getting updated in MMT end//
           
            //$rateplan_data['startDate'] = (string)gmdate("Y-m-d", strtotime(getDateTimeWithTimeZone($product_data['local_timezone_name'], gmdate("Y-m-d H:i:s", min($product_data['startDate'])), 1)));
            $rateplan_data['endDate'] = (string)gmdate("Y-m-d", strtotime(getDateTimeWithTimeZone($product_data['local_timezone_name'], gmdate("Y-m-d H:i:s", $product_data['endDate']), 1)));
            
            if(!empty($product_data['is_cut_off_time']) && !empty($product_data['cut_off_time'])) {
                $cutoff = $this->time_to_decimal($product_data['cut_off_time']);
                if(!empty($cutoff)){
                    $rateplan_data['cutOff'] = (int) $cutoff;
                }
            }
            if(!empty($product_data['min_order_qty'])){
                $rateplan_data['minimumOccupancy'] = (int)$product_data['min_order_qty'];
            }
            if(!empty($product_data['max_order_qty'])){
                $rateplan_data['maximumOccupancy'] = ($product_data['max_order_qty'] <= 99) ? (int)$product_data['max_order_qty'] : 99;
            }
            $rateplan_data['inclusions'] = !empty($product_data['whats_included']) ? explode("~~~", $product_data['whats_included']) : ['No inclusions'];
            $duration = $this->time_to_decimal($product_data['duration']);
            $rateplan_data['duration'] = (int) (!empty($duration) ? $duration : 0);
            $display_price = !empty($price_level_default) ? $price_level_default : (!empty($default_tps_price) ? $default_tps_price : "");
            if(!empty($display_price)) { 
                $rateplan_data['mrp'] = (float) round($display_price,2);
            }
            $rateplan_data['vendorRateplanCode'] = (string)$ticket_id;
            $rateplan_data['vendorRatePlanData'] = json_encode($vendorRatePlanData);
            $rateplan_data['isActive'] = (string)"1";
             /* commission code */
            $commissionArray = array();
            if(!empty($ticket_prices)){
                $firstKey = '';
                foreach($ticket_prices as $key => $ticket_price){
                    if(strtolower($ticket_price['ticket_type']) == 'adult')
                    {
                        $commissionArray = $ticket_price;
                    }
                    if(!$firstKey){
                        $firstKey = $key;
                    }
                }
                if(empty($commissionArray)){
                    $commissionArray = $ticket_prices[$firstKey];
                }
                $commission = $this->set_commission($commissionArray['commission_on_sale_price'],$commissionArray['hotel_prepaid_commission_percentage'],$commissionArray['subtotal_net_amount'],$commissionArray['ticket_new_price']);                   
                if($commission > 0) {
                    $rateplan_data['commissionValue'] = (float) round($commission, 2 );
                }
            }
            
            if(!empty($dynamic_form_info)){
                $rateplan_data['dynamicForm'] = json_encode($dynamic_form_info);
            }
            if(!empty($rate_plan_of_ticket[$ticket_id]['rate_id']) && $rate_plan_of_ticket[$ticket_id]['is_active'] == 1) {                  
                $url = $this->tp_ota_info['MMT']['tp_end_point']."/rateplan/".$rate_plan_of_ticket[$ticket_id]['rate_id'];
                $tp_rate_response = $this->common_request($url, 'PUT', "update_rateplan_basic_info", $rateplan_data);
                if(!empty($tp_rate_response['status']) && $tp_rate_response['status'] == "success"){
                    $rate_plan_of_ticket[$ticket_id]['rate_id'];
                    $rateplain_response['success'][$product_id][$ticket_id] = $rate_plan_of_ticket[$ticket_id]['rate_id'];
                } else {
                    $rateplain_response['error'][$product_id][$ticket_id] = $tp_rate_response;
                }
            } else {
                $rateplain_response['error'][$product_id][$ticket_id] = "R271: Rate key not found of this seasion or the rateplan is in inactive state.";
            }
        } else {
            $rateplain_response['error'][$product_id] = "R27 :Empty seasion or Tp endpoint is empty.";
        }
    }
    return $rateplain_response;
}

    /* #endregion */
    /**
    * @Name : create_dynamicform()
    * @Purpose : create_dynamicform
    * @Return params : dynamic form array.
    * @Created : Prabhdeep Kaur<prabhdeep.intersoft@gmail.com> on 24 Apr 2020
    */
    /* #region  create_dynamicform */
    private function create_dynamicform($mec_id = '', $db_records = [])
    {
        global $mmt_other_response_data;
        global $api_global_constant_values;
         $this->get_constant();
         
        $dynamic_form_values = [];
        $pickup_data = isset($db_records[$mec_id]['pickup_points']['pickup_data']) ? $db_records[$mec_id]['pickup_points']['pickup_data'] : [];
        $product_data = $db_records[$mec_id]['mec_data'];
        $dynamic_form_values['fields']['questionId1']['key'] = "questionId1";
        $dynamic_form_values['fields']['questionId1']["displayName"] = $product_data['postingEventTitle'].' Pickup points';
        $dynamic_form_values['fields']['questionId1']['mandatory'] = $product_data['checkin_points_mandatory'] == 1 ? true : false;
        $dynamic_form_values['fields']['questionId1']['type'] = "select";

          if(!empty($pickup_data)){
             $t = 1;
            $k =  0;
            $all_pick = [];
            foreach($pickup_data as $pickup_data_v1){
                if((!empty(trim($pickup_data_v1['pickup_calculation_code'])) && !in_array(trim($pickup_data_v1['pickup_calculation_code']), $all_pick)) || !empty(trim($pickup_data_v1['targetlocation'])) || !empty(trim($pickup_data_v1['name']))){
                    $dynamic_form_values['fields']['questionId1']['values'][$k]['key'] = $pickup_data_v1['target_id'];
                    $dynamic_form_values['fields']['questionId1']['values'][$k]['value'] = !empty(trim($pickup_data_v1['targetlocation'])) ? trim($pickup_data_v1['targetlocation']) : (!empty(trim($pickup_data_v1['name'])) ? trim($pickup_data_v1['name']) : trim($pickup_data_v1['pickup_calculation_code']));
                    $all_pick[] = $dynamic_form_values['fields']['questionId1']['values'][$k]['key'];
                    $k++; $t++;
                }
            }
        }   
        else {
            $checkin_points_array = explode(',', $product_data['checkin_points']);
            $checkin_points_array = array_filter($checkin_points_array);
            if (!empty($checkin_points_array)) {
                $checkin_point_ids_array = explode(',', $product_data['checkin_point_ids']);
                foreach ($checkin_points_array as $key => $value) {
                    $dynamic_form_values['fields']['questionId1']['values'][$key]['key'] = $checkin_point_ids_array[$key];
                    $dynamic_form_values['fields']['questionId1']['values'][$key]['value'] = str_replace("#~#",",",$value);
                }
            }
        }
        $dynamic_form_values['fields']['questionId1']['defaultValue'] = "select";
        $dynamic_form_values['fields']['questionId1']['disabled'] = false;
        $dynamic_form_values['fieldsOrder'] = ['questionId1'];
        if(!empty($dynamic_form_values['fields']['questionId1']['values'])){
            return $dynamic_form_values;
        } else {
            return false;
        }
    }

         /* #endregion */
    /**
     * @name: set_commission()
     * @created by:Kavita Sharma <kavita.aipl@gmail.com> on 16 Nov, 2020
     */
    function set_commission($commission_on_sale_price, $hotel_prepaid_commission_percentage ,$subtotal_net_amount, $ticket_new_price){
        $commission = 0;
        
        if($commission_on_sale_price == 1) {
            //toggle On
             $commission = $hotel_prepaid_commission_percentage;
        } else {
            //toggle Off
            if(!empty($hotel_prepaid_commission_percentage) && $hotel_prepaid_commission_percentage > 0 ){
                $commission = ($subtotal_net_amount/$ticket_new_price)*100;
            } 
        }
        return $commission;
    }
}

