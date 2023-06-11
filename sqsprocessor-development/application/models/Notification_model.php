<?php

/**
 * @description     : This file contains Notifications related code.
 * @author          : Gagan Sood <gagandeveloper.aipl@gmail.com>
 * @date            : 24 Nov 2020
 */
class Notification_model extends MY_Model {

    function __construct()
    {
        /* Call the Model constructor */
        parent::__construct();
        $this->load->library('log_library');
        $this->load->model('common_model');
        $this->events_for_order = ['ORDER_CREATE', 'ORDER_UPDATE', 'ORDER_CANCEL', 'PAYMENT_CREATE', 'PAYMENT_REFUND', 'VOUCHER_RELEASE', 'VOUCHER_REVOKE', 'REDEMPTION'];
        $this->graylogs = [];
        $this->api_token = "";
    }
    
    /**
    * @Name        : checkUsersForProduct
    * @Purpose     : to get the users of product
    * @param type  : int
    * @return      : array
    * @CreatedBy   : Gagan Sood
    */
    function checkUsersForProduct($product_id = 0, $event_name = 'PRODUCT_CREATE', $distributor_ids = array())
    {
        $user_ids = $mec_data = [];
        if(!empty($product_id)) {
            $product_status = '0';
            if ($event_name == 'PRODUCT_DELETE') {
                $product_status = '1';
            }
            $primarydb1_connection = $this->primarydb->db;
            $primarydb2_connection = clone $this->primarydb->db;
            $primarydb1_connection->select('cod_id');
            $primarydb1_connection->from('modeventcontent');
            $primarydb1_connection->where(array('mec_id' => $product_id, 'deleted' => $product_status));
            $mec_details = $primarydb1_connection->get()->result_array();
            if (!empty($mec_details[0])) {
                $mec_data = $mec_details[0];
            }
            if (!empty($mec_data['cod_id'])) {
                $user_ids[] = $mec_data['cod_id'];
                //if ($event_name != 'PRODUCT_CREATE') {
                    /* Fetch pos_ticket details */
                    $product_assign = '1';
                    if ($event_name == 'PRODUCT_UNASSIGN') {
                        $product_assign = '0';
                    }
                    $primarydb2_connection->select('hotel_id');
                    $primarydb2_connection->from('pos_tickets');
                    $primarydb2_connection->where(array('museum_id' => $mec_data['cod_id'], 'mec_id' => $product_id, 'is_pos_list' => $product_assign));
                    if ($event_name != 'PRODUCT_UNASSIGN' && $event_name != 'PRODUCT_ASSIGN') {
                        $pos_data = $primarydb2_connection->get()->result_array();
                    } else if (!empty($distributor_ids)) {
                        $primarydb2_connection->where_in('hotel_id', $distributor_ids);
                        $pos_data = $primarydb2_connection->get()->result_array();
                        /* Handle case if PRODUCT_ASSIGN & PRODUCT_UNASSIGN for wrong distributor */
                        if (empty($pos_data)) {
                            $user_ids = [];    
                        }
                    } else {
                        $user_ids = [];
                    }
                    if (!empty($pos_data)) {
                        $user_ids = array_unique(array_merge($user_ids, array_column($pos_data, 'hotel_id')));
                    }
                //}
                /* Fetch resellers of all users */
                if (!empty($user_ids)) {
                    $this->primarydb->db->select('reseller_id');
                    $this->primarydb->db->from('qr_codes');
                    $this->primarydb->db->where_in('cod_id', $user_ids);
                    $reseller_ids = $this->primarydb->db->get()->result_array();
                    if (!empty($reseller_ids)) {
                        $user_ids = array_unique(array_merge($user_ids, array_column($reseller_ids, 'reseller_id')));
                    }
                }
            }
        }
        return $user_ids;
    }

    /* #region function used to send the order notification from 3.3 */
    /**
     * sendOrderNotification
     *
     * @param string $reference_id
     * @param string $event_name
     * @param string $distributor_id
     * @return void
     */
    function sendOrderNotification($reference_id = '', $event_name = '', $distributor_id = '' , $version = "") {        
        $this->api_token = $this->get_token();
        $notification_params['headers'] = array(
                                            'Content-Type: application/json'
                                        );
        if (!empty($this->api_token) && SERVER_ENVIRONMENT != 'Local') {
            $notification_params['headers'][] = 'Authorization: Bearer '.$this->api_token;
        }
        if (empty($version) || $version == "3.3") {
            $notification_params['end_point'] = DISTRIBUTOR_API_ENDPOINT . 'notifications';
        } else {
            $notification_params['end_point'] = DISTRIBUTOR_GLOBAL_API_ENDPOINT . 'v' . $version . '/distributor/notifications';
        }  
        $notification_params['request'] = array(
                                                'data' => array(
                                                    'notification' => array(
                                                        'notification_event' => (string) $event_name,
                                                        "notification_item_id" => (string) $reference_id,
                                                    )
                                                )
                                            );
        if (!empty($distributor_id)) {
            $notification_params['request']['data']['notification']['distributor_id'] = (string) $distributor_id;
        }
        /* Send order notification */
        $send_notification_response = $this->common_model->curl_request_for_arena($notification_params);
        $grayLogData['log_name'] = 'WebhookCall';
        $grayLogData['data'] = json_encode($send_notification_response);
        $grayLogData['http_status'] = isset($send_notification_response['curl_info']['http_code']) ? $send_notification_response['curl_info']['http_code'] : '';
        $this->graylogs[] = $grayLogData;
    }
    /* #endregion function used to send the order notification from 3.3 */
    
    /* #region Function used to check and send notification for different type of order events */
    /**
     * checkNotificationEventExist
     *
     * @param array $request
     * @return void
     */
    function checkNotificationEventExist($notification_request = array())
    {
        $events = !empty(NOTIFICATION_EVENTS) ? json_decode(NOTIFICATION_EVENTS, true) : array();
        /* Sending multiple notifications */
        foreach ($notification_request as $request) {
            $distributor_ids = $user_ids = array();
            if (!empty($request['reference_id']) && !empty($request['event']) && !empty($events)) {
                $is_order_event = false;
                foreach ($request['event'] as $requested_event) {
                    if (in_array($requested_event, $events)) {
                        $eventNameValues[] = array_search($requested_event, $events);
                        $is_order_event = in_array($requested_event, $this->events_for_order);
                    }
                }
                if (!empty($eventNameValues)) {
                    /* Handle case if notification event exists or active in notification_events table */
                    $check_notification_event = $this->primarydb->db->query("Select * from notification_events where status = '1' and name in (".implode(',',$eventNameValues).")")->result_array();
                    if (!empty($check_notification_event)) {
                        /* Fetch user_ids if it is not coming in request */
                        if (!empty($request['distributor_id']) || !empty($request['museum_id']) || !empty($request['reseller_id'])) {
                            if (!empty($request['distributor_id'])) {
                                if (is_array($request['distributor_id'])) {
                                    $distributor_ids = array_merge($distributor_ids, $request['distributor_id']);
                                    $user_ids = array_merge($user_ids, $request['distributor_id']);
                                } else {
                                    $distributor_ids[] = $request['distributor_id'];
                                    $user_ids[] = $request['distributor_id'];
                                }
                            }
                            if (!empty($request['reseller_id'])) {
                                if (is_array($request['reseller_id'])) {
                                    $user_ids = array_merge($user_ids, $request['reseller_id']);
                                } else {
                                    $user_ids[] = $request['reseller_id'];
                                }
                            }
                            if (!empty($request['museum_id'])) {
                                if (is_array($request['museum_id'])) {
                                    $user_ids = array_merge($user_ids, $request['museum_id']);
                                } else {
                                    $user_ids[] = $request['museum_id'];
                                }
                            }
                        }
                        /* Handle case for order events */
                        if (!empty($is_order_event) && $is_order_event && empty($user_ids)) {        
                            $fetch_order_details = $this->secondarydb->db->query("Select visitor_group_no, hotel_id, museum_id, reseller_id from prepaid_tickets where visitor_group_no =".$request['reference_id'])->result_array();
                            if (!empty($fetch_order_details)) {
                                $user_ids = array_unique(array_merge(array_column($fetch_order_details, 'hotel_id'), array_column($fetch_order_details, 'museum_id'), array_column($fetch_order_details, 'reseller_id')));
                                /*Handle in case of supplier admin*/
                                $Product_supplier_ids = array_column($fetch_order_details, 'museum_id');
                                if (!empty($Product_supplier_ids)) {
                                    $this->primarydb->db->select('reseller_id');
                                    $this->primarydb->db->from('qr_codes');
                                    $this->primarydb->db->where_in('cod_id',$Product_supplier_ids);
                                    $data = $this->primarydb->db->get()->result_array() ?? '';
                                    if (!empty($data)) {
                                        $user_ids = array_merge($user_ids,array_column($data,'reseller_id'));
                                    }
                                }
                                
                            }
                        } else {
                            /* Handle case for product events */
                            $user_ids = $this->checkUsersForProduct($request['reference_id'], $events[$check_notification_event[0]['name']], $distributor_ids);
                        }
                        /* Check webhooks are created for any users in webhook_details */
                        if (!empty($user_ids)) {
                            $this->primarydb->db->select('*');
                            $this->primarydb->db->from('webhook_details');
                            $this->primarydb->db->where('status', '1');
                            $this->primarydb->db->where('is_deleted', '0');
                            $this->primarydb->db->where_in('event_name', array_column($check_notification_event, 'name'));
                            $this->primarydb->db->where_in('user_type', array('1', '2', '3'));
                            $this->primarydb->db->where_in('user_id', $user_ids);
                            $check_webhooks = $this->primarydb->db->get();
                            if ($check_webhooks->num_rows() > 0) {
                                $webhook_data = $check_webhooks->result_array();
                                $existing_webhooks = array_unique(array_column($webhook_data, 'event_name'));
                                $version = max(array_unique(array_column($webhook_data, 'version')));
                                /* Call notification api for different events */
                                foreach ($existing_webhooks as $event_name) {
                                    if (!empty($distributor_ids) && ($events[$event_name] == 'PRODUCT_ASSIGN' || $events[$event_name] == 'PRODUCT_UNASSIGN')) {
                                        /* Handle case if product assign or unassign to multiple OTA's */
                                        foreach ($distributor_ids as $distributor_id) {
                                            if (in_array($distributor_id, $user_ids)) {
                                                $this->sendOrderNotification($request['reference_id'], $events[$event_name], $distributor_id, $version);
                                            }
                                        }
                                    } else {
                                        $this->sendOrderNotification($request['reference_id'], $events[$event_name], '', $version);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
         /** #Comment: START Save event version details.*/
        if (!empty($notification_request[0])) {
            $this->insertMaxOrderVersionEvents($notification_request[0]);
        }
        /** #Comment: END Save event version details.*/
        /* Write logs after completing process */
        if (!empty($this->graylogs)) {
            if (SERVER_ENVIRONMENT != 'Local') {
                $this->log_library->CreateGrayLog($this->graylogs);
            } else {
                $this->CreateLog('send_notification.php', 'notification', array('data' => json_encode($this->graylogs)));
            }
        }
    }
    /* #endregion Function used to check and send notification for different type of order events */
    
     /**
     * @Name getTokenFromFile
     * @Purpose to get token from file
     *  array which contains token and status.
     */
    private function getTokenFromFile() {
        $folder = "application/storage/webhook_notification/";
        if(substr(sprintf('%o', fileperms('application/storage/webhook_notification')), -4) != 777){
            chmod("application/storage/webhook_notification",0777);
        }
        if(substr(sprintf('%o', fileperms('application/storage/webhook_notification/token.php')), -4) != 777){
            chmod("application/storage/webhook_notification/token.php",0777);
        }
        if (!is_dir($folder)) {
            $this->mkdir_r($folder);
        }
        $filename = $folder . "token.php";
        if (is_file($filename)) {
            $fp = fopen($filename, 'r');
            $filedata = fread($fp, filesize($filename));
            fclose($fp);
            if ($filedata != '') {
                $filedata = json_decode($filedata, true);
                if (!empty($filedata['access_token'])) {
                    $token = $filedata;
                } else {
                    $token['access_token'] = '';
                }
            } else {
                $token['access_token'] = '';
            }
        } else {
            $token['access_token'] = '';
        }
        return $token;
    }
    
    /**
     * @Name saveTokenInFile
     * @Purpose to save token to file    
     */
    private function saveTokenInFile($token_response = []) {
        if (!empty($token_response)) {
            $folder = "application/storage/webhook_notification/";
            $this->mkdir_r($folder);
            $data = json_encode($token_response);
            $filename = $folder . "token.php";
            $fp = fopen($filename, 'w+');
            fwrite($fp, $data);
            fclose($fp);
            return true;
        }
    }
    
    /**
     * @Name refresh_token
     * @Purpose to get refresh token.
     */
    private function refresh_token() {
        $authentication_response = [];
        $getfiledata = $this->getTokenFromFile();
        $current_date_time = gmdate("Y-m-d H:i:s");
        $check_date_time = strtotime($current_date_time . '+ 5 minute');
        if (!empty($getfiledata['access_token']) && !empty($getfiledata['expire_time']) && $check_date_time < $getfiledata['expire_time']) {
            $authentication_response['response'] = $getfiledata;
        } else {
            $notification_params = array(
                'headers' => array(
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(NOTIFICATION_API_USER . ':' . NOTIFICATION_API_PASSWORD)
                ),
                'end_point' => DISTRIBUTOR_API_ENDPOINT . 'oauth2/token',
                'method' => 'POST'
            );
            /* Check authorization of user */
            if (SERVER_ENVIRONMENT != 'Local') {
                $authentication_response = $this->common_model->curl_request_for_arena($notification_params);
                $grayLogData = array(
                    'log_name' => 'WebhookAuthToken',
                    'log_type' => 'Notification',
                    'data' => json_encode($authentication_response),
                    'api_name' => 'SQS Notification',
                    'request_time' => gmdate("Y-m-d H:i:s"),
                    'reference' => strtotime(gmdate("Y-m-d H:i:s"))
                );
                $this->graylogs[] = $grayLogData;
                if (!empty($authentication_response['response']['access_token'])) {
                    if (!empty($authentication_response['response']['expires_in'])) {
                        $current_date_time = strtotime(gmdate("Y-m-d H:i:s"));
                        $authentication_response['response']['expire_time'] = $current_date_time + $authentication_response['response']['expires_in'];
                        $authentication_response['other_data']['direct_token_call'] = 1;
                    }
                }
            }
        }
        return $authentication_response;
    }

    /**
     * @Name get_token
     * @Purpose to get token to call further API.  
     */
    public function get_token() {
        /* preformed login and access token API */
        $token = '';
        $tokenid_response = $this->refresh_token();
        if (!empty($tokenid_response['response']['access_token'])) {
            $token = $tokenid_response['response']['access_token'];
            if(!empty($tokenid_response['other_data']['direct_token_call'])){
               $this->saveTokenInFile($tokenid_response['response']); 
            }
            
        }
        return $token;
    }
    /* function to traverse with complete path and make folder if not exist
     * @param string $dirName (path)
     * @param  $rights
     */
    public function mkdir_r($dirName, $rights = 0777) {
        $dirs = explode('/', $dirName);
        $dir = '';
        foreach ($dirs as $part) {
            $dir.=$part . '/';
            if (!@is_dir($dir) && strlen($dir) > 0) {
                @mkdir($dir, $rights);
            }
        }
    }
    /** 
     * Function to set the header parameters    
     * setHeaderValues
     *
     * @param  Array $headers_arr
     * @return Array
     */
    public function setHeaderValues($headers_arr){
        $header_params = [];
        foreach ($headers_arr as $key => $val) {
            $header_params[] = $key . ": " . $val;
        }
        return $header_params;
    }

    /**
     * @name :purgeFastlyNotifiaction
     * @purpose :  to Send Purging Data in Queue notifaction server
     * @created by : kunaldev.aipl@gmail.com
     * @created at: 20-04-2022
     */
    function purgeFastlyNotifiaction($vgn, $requeset_type = 'ORDER_UPDATE', $distributor_ids = [], $supplier_ids = []) {
        $queue_msg = array(
            'visitor_group_no'  => $vgn,
            'request_type'      => $requeset_type,
            'distributor_ids'   => $distributor_ids,
            'supplier_ids'     => $supplier_ids,
            'queue_type'       => 'FASTLY_PURGE_DATA'
        );
        $aws_message = base64_encode(gzcompress(json_encode($queue_msg)));
        if (SERVER_ENVIRONMENT != 'Local' && defined('NOTIFICATION_SERVER_COMMON_QUEUE') && defined('NOTIFICATION_SERVER_COMMON_QUEUE_ARN')) {
            $sqs_object = new Sqs();
            $MessageIds = $sqs_object->sendMessage(NOTIFICATION_SERVER_COMMON_QUEUE, $aws_message);
            if ($MessageIds) {
                $this->load->library('Sns');
                $sns_object = new Sns();
                $sns_object->publish($MessageIds, NOTIFICATION_SERVER_COMMON_QUEUE_ARN);
            }
        }
    }
    /* #endregion function used to call notification api manually */
        /* #region to save insertMaxOrderVersionEvents */
     /**
     * insertMaxOrderVersionEvents
     * @Purpose     : insert order version events
     * @param  array $event_data
     * @return void
     */
    public function insertMaxOrderVersionEvents($event_data)
    {
        if (!empty($event_data['reference_id'])) {
            $visitor_group_no = $event_data['reference_id'];
            $cashier_data = [];
            $prepaid_ticket_data = $this->getMaxPtVersionData($visitor_group_no);
            if (!empty($prepaid_ticket_data)) {
                $cashier_id =  !empty($prepaid_ticket_data->order_updated_cashier_id) ? $prepaid_ticket_data->order_updated_cashier_id : $prepaid_ticket_data->cashier_id;
                if (!empty($cashier_id)) {
                    $cashier_data = $this->getCashierDetails($cashier_id);
                }
            }
            if (!empty($event_data['event'][0])) {
                $event_name = $event_data['event'][0];
            }
            if (!empty($event_data['eventPartial'])) {
                $event_name = $event_data['eventPartial'];
            }
            $events_data = $this->getOrderEvents($event_name);
            if (!empty($events_data) && $prepaid_ticket_data) {
                $insertData['visitor_group_no'] = $visitor_group_no;
                $insertData['event_id'] = $events_data->event_id;
                $insertData['event_name'] = $events_data->event_name;
                $insertData['event_order_version'] = $prepaid_ticket_data->version;
                $insertData['event_created_cashier_name'] = !empty($prepaid_ticket_data->order_updated_cashier_name) ? $prepaid_ticket_data->order_updated_cashier_name : $prepaid_ticket_data->cashier_name;
                $insertData['event_created_cashier_email'] = $cashier_data->uname ?? '';
                $insertData['event_created_cashier_id'] = $cashier_id ?? '';
                $insertData['event_created_cashier_role'] = $event_data['cashier_type'] ?? 1;
                $this->secondarydb->db->insert('order_events_version_details', $insertData);
            }
            $this->CreateLog('Order_events_version_details.php', "VGN: " . $visitor_group_no, array('Request' => json_encode($event_data)));
        }
    }

    /* #endregion to save insertMaxOrderVersionEvents */

    /* #region Function to getMaxPtVersionData */
    /**
     * getMaxPtVersionData
     *
     * @param  int $visitor_group_no
     * @return object
     */
    public function getMaxPtVersionData($visitor_group_no = '')
    {
        return $this->secondarydb->rodb->select('version, cashier_id, cashier_name, order_updated_cashier_id, order_updated_cashier_name')->from('prepaid_tickets')->where('visitor_group_no = ' . $visitor_group_no)->order_by("last_modified_at", "DESC")->get()->row();
    }
   /* #endregion Function to getMaxPtVersionData */

    /* #region Function to getOrderEvents */
    /**
     * getOrderEvents
     *
     * @param  mixed $event_name
     * @return object
     */
    public function getOrderEvents($event_name = "")
    {
        return $this->primarydb->db->select('*')->from('order_events')->where('event_name', $event_name)->where('deleted', '0')->get()->row();
    }
    /* #endregion Function to getOrderEvents */

  

    /* #region  to  getCashierDetails */
    /**
     * getCashierDetails
     * @Purpose     : to get user name.
     * @param  string $cashier_id
     */
    public function getCashierDetails($cashier_id)
    {
        return $this->primarydb->db->select('uname')->from('users')->where('id', $cashier_id)->get()->row();
    }
   /* #endregion to  getCashierDetails */

}
