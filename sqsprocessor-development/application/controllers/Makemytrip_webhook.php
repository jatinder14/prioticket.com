<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Makemytrip_webhook extends MY_Controller {
    public $log_dir = '';
    public $log_file_name = '';
    public $is_filelog = '';
    public $is_gralog = ''; 
    /* #region  BOC of class Notification */

    var $base_url;

    /* #region main function to load controller notification */

    /**
     * __construct
     *
     * @return void
     */
    function __construct() {

        parent::__construct();

        $this->load->model('Makemytrip_webhook_model','makemytrip_webhook_model');
        $this->load->library('api/Apilog_library', 'apilog_library');
        $this->log_dir = "makemytrip";
        $this->log_file_name = "makemytrip_event.php";
        $this->is_gralog = 1;
        $this->is_filelog = 1;
        $this->internal_processing_time = microtime(true);      
        $microtime = microtime();
        $search = array('0.', ' ');
        $replace = array('', '');
        $error_reference_no = str_replace($search, $replace, $microtime);
        define('ERROR_REFERENCE_NUMBER', $error_reference_no);

    }
    public function get_mec_id() {

        include_once 'aws-php-sdk/aws-autoloader.php';
        $queueUrl = MMT_WEBHOOK;
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $messages = $sqs_object->receiveMessage($queueUrl);
        $messages = $messages->getPath('Messages');

        if (!empty($messages)) {

            foreach ($messages as $message) {
                $this->CreateLog('mmt_webhook_debug.txt', 'step1', array("message" => $message['Body']));
                $incoming_mes = json_decode(gzuncompress(base64_decode($message['Body'])),true);
                if( !empty( $message['ReceiptHandle'] ) ) {   
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                }
                $product_id = $incoming_mes['product_id']; 
                if( isset( $incoming_mes['status'] ) ) {
                    $_REQUEST['active'] = $incoming_mes['status'];
                }
                $this->CreateLog('mmt_webhook_debug.txt', 'step2', array("_REQUEST" => $_REQUEST));
                $this->update_product_mmt($product_id);
            }
        }
    }
    
    /**
     * @name    : update_product_mmt
     * @Purpose : To  update products at MMT
     * @created by: Kavita Sharma <kavita.aipl@gmail.com> on Jun 15 , 2021
     */
    /* #region   To create and update products */
    public function update_product_mmt($mec_id = 0,$direct = 1) {

        $this->CreateLog('mmt_webhook_debug.txt', 'step2', array("mec_id" => $mec_id));
        global $filelogs;
        global $graylogs;
        global $mmt_other_response_data;
        $error = 0;
        $api = "MakeMytrip_update_product_mmt";
        $status_code = 200;
        $this->log_file_name = "MakeMytrip_update_product_mmt.php";


        $this->CreateLog('mmt_webhook_debug.txt', 'step3', array("_REQUEST" => $_REQUEST));

        $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'Request Response', 'data' => json_encode([ 'request' => $_REQUEST, 'server' => $_SERVER]), 'api_name' => "Webhook notification", 'http_status' => $status_code, 'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
       // $this->authorization();
         if($mec_id > 0) {    
            $hotel_id =  MMT_OTA_ID;      
            if($hotel_id) {
                 // Update venue//
                $ticket_related_to_hotel_ids[$hotel_id][] = $mec_id;
                $ticket_id[] = $mec_id;
                $other_data['ticket_related_to_hotel_ids'] = $ticket_related_to_hotel_ids;
              
                $db_records = $this->makemytrip_webhook_model->get_db_records(1, $hotel_id, $ticket_id, $other_data , 1);
              
                if($db_records) {
                    $mmt_parameter = json_decode($db_records['pos_data'][$hotel_id][$mec_id]['third_party_parameters'], true);
                    if($db_records[$mec_id]['mec_data']['deleted'] == 1 && $mmt_parameter['mmt']['rateplan_data'][$mec_id]['is_active'] == 1) {
                        $request_data['update_event']['update_rateplans'][$mec_id][] = array("is_active" => 0,"hotel_id" => $hotel_id , "ticket_ids" => array($mec_id));
                    } else {
                        $product_details = $this->makemytrip_webhook_model->get_product_details_from_mmt($db_records['pos_data'][$hotel_id][$mec_id]);
                        $request_data['update_event']['update_venues'] = array($mec_id => array( "basic_info" => 1, "city_id" => 357, "hotel_id" => $hotel_id));
                        $request_data['update_event']['update_products'] = array($mec_id => array( "basic_info" => 1,"hotel_id" => $hotel_id));
                        if($mmt_parameter['mmt']['rateplan_data'][$mec_id]['is_active'] == 0) {
                            $request_data['database_query'] = $db_records;
                            $request_data['mmt_parameter'] =  $mmt_parameter;
                            $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'High Alert Notification', 'data' => json_encode(['Body' =>   $request_data , 'Reason' => 'This product rate plan data is inactive at prio end.', 'server' => $_SERVER]), 'api_name' => $api.'_Notification', 'http_status' => '400', 'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
                            $error = 1;
                            $status_code = 400;               
                        } else {
                            $updateRateplans = array(["basic_info" => 1,"hotel_id" => $hotel_id , "ticket_ids" => array($mec_id)]);
                        } 
                        $error = 0;
                        if($error == 0) {
                            $request_data['update_event']['update_rateplans'][$mec_id] = $updateRateplans;
                            if ($db_records[$mec_id]['mec_data']['is_cancel_allow'] <= 0) {
                                $request_data['update_event']['update_cancel_policy'][$mec_id] = array("is_cancelable" => false, "cancelday_count" =>  0, "hotel_id" =>  $hotel_id);
                            } else {
                                $cancellation_time = explode(":", $db_records[$mec_id]['mec_data']['cancellation_time']);
                                $hour = $cancellation_time[0];
                                $minute = $cancellation_time[1];
                                $second = $cancellation_time[2];
                                if($hour > 0 ) {
                                    $day = ($hour/24);
                                    if(is_float($day)) {
                                        $day = (int) $day + 1;
                                    }
                                    $request_data['update_event']['update_cancel_policy'][$mec_id] = array("is_cancelable" => true, "cancelday_count" => $day, "hotel_id" =>  $hotel_id);
                                } else if($minute > 0 || $second > 0){
                                    // always set for refundab
                                    $request_data['update_event']['update_cancel_policy'][$mec_id] = array("is_cancelable" => true, "cancelday_count" => 0, "hotel_id" =>  $hotel_id);
                                }  else {
                                      // if nothing set then set is always refundable
                                      $request_data['update_event']['update_cancel_policy'][$mec_id] = array("is_cancelable" => true, "cancelday_count" =>  0, "hotel_id" =>  $hotel_id);
                                }
                            }
                           
                            $producy_detail_array = json_decode($product_details, true);
                            if (!empty($product_details)) {
                                $mmt_images = $deleteimagesarray = [];
                                foreach ($producy_detail_array['mediaArray'] as $images) {
                                    $mmt_images[$images['MediaId']] = $images['Title'];
                                }
                                $eventImage[] = $db_records[$mec_id]['mec_data']['eventImage'];
                                $more_images = explode(",", $db_records[$mec_id]['mec_data']['more_images']);
                                $prioimages = array_merge($eventImage, $more_images);
                                $addimage = array_diff($prioimages, $mmt_images);
                                $deleteimages = array_diff($mmt_images, $prioimages);
                                $request_data['update_event']['add_media'][$mec_id] = array("images" => $addimage,  "hotel_id" =>  $hotel_id);
                                if (!empty($deleteimages)) {
                                    foreach ($deleteimages as $key => $image) {
                                        $deleteimagesarray[$image] = array("hotel_id" => $hotel_id, "is_active" => 0, "media_key" => $key);
                                    }
                                    if (!empty($deleteimagesarray)) {
                                        $request_data['update_event']['update_media'][$mec_id] = $deleteimagesarray;
                                    }
                                }                      
                            } else {
                                $status_code = 400;
                                $response['error']['update_create_media'] = "Unable to create and update media. Ticket Detail API response is null";
                            }                  
                        } 
                    }
                    if($error == 0) {
                        $response['updation_modules'] = $this->makemytrip_webhook_model->common_update_event($db_records, $request_data, $other_data);
                    } else {
                        $response['error'] = "UPDATE_MMT:C04 Unable to update product at mmt end because rate plan is in inactive state.Check high alert email for more details.";
                    }
                } else {
                    $response['error'] = "UPDATE_MMT:C03 : Empty response from db.";
                }               
            }  else {
                $response['error'] = "UPDATE_MMT:C02 :MMT_OTA_ID is empty or not sent.";
            }
            $request_format = '{"update_event":{"update_venues":{"149495":{"city_id":1,"basic_info":1,"hotel_id":872}},"update_products":{"149495":{"basic_info":1,"is_active":1,"hotel_id":872}},"update_rateplans":{"149495":[{"is_active":1,"basic_info":1,"hotel_id":872,"ticket_ids":["89","89"]}]},"add_rateplans":{"149495":{"ticket_ids":["89"],"hotel_id":872}},"add_media":{"149495":{"images":["images_name"],"hotel_id":872}},"update_media":{"149495":{"img_1571404875_Sevilla-02_P_5_dbe94ebd-e668-4b6d-90ac-d847f62c842a.jpeg":{"basic_info":{"priority":1},"hotel_id":872,"is_active":1}}},"update_cancel_policy":{"149495":{"is_cancelable":1,"cancelday_count":1,"hotel_id":872}}}}';
         } else {
            $response['error'] = "UPDATE_MMT :C01 :Ticket ID is required";
         }
         $graylogs[] = $filelogs[] = ['log_dir' => $this->log_dir, 'log_filename' => $this->log_file_name, 'title' => 'Request Response', 'data' => json_encode(['response' => $response , 'request' => $_REQUEST, 'request_data' => $request_data, 'server' => $_SERVER]), 'api_name' => $api, 'http_status' => $status_code, 'request_time' => $this->get_current_datetime(), 'error_reference_no' => ERROR_REFERENCE_NUMBER];
         if($this->is_gralog == 1){
            //$graylogs[] = $lgs;
            $this->apilog_library->set_graylog($graylogs, 0);
            $graylogs = [];
        }
        if ($this->is_filelog == 1) {
            //$filelogs[] = $lgs;
            $this->apilog_library->create_filelog($filelogs);
            $filelogs = [];
        }
        //header('HTTP/1.0 400 Bad Request');
        header('Content-Type: application/json');
        if($direct == 0) {
           // echo !empty($response) ? json_encode($response, JSON_PRETTY_PRINT) : "";    
        }
        $this->CreateLog('mmt_webhook_debug.txt', 'step3', array("response" => json_encode($response)));
        echo json_encode($response, JSON_PRETTY_PRINT);    
    }

    

}

