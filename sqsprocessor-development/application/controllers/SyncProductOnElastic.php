<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

use Elasticsearch\ClientBuilder;
require_once '././././elasticsearch/vendor/autoload.php';

class SyncProductOnElastic extends MY_Controller {

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
    }

    /* #region  Main function to prepare all elastic server data according to ticket id and cod ids */
    function insert_data($id = '',$product_id = '') {
        $data['cache'] = 'false';
        $data['product_supplier'] = $id;
        if($product_id != '') {
            $data['product_id'] = $product_id;
        }
        $data['start_index'] = 1;
        $data['items_per_page'] = 500;
        $data['product_elastic_synced'] = 1;
        /* #region  TO call api url */
        $data = http_build_query($data); 
        $this->log_data[] = $data;
        $api_urls_array = array(PRODUCTS_LISTING_v3_6);
        $elastic_nodes_array = array(PRODUCTS_LISTING_v3_6 => $this->config->config['products_listing_node_v3.5']);
        foreach($api_urls_array as $api_url) {
            $this->nodes_array[$api_url.'?'.$data] = $elastic_nodes_array[$api_url];
            $this->api_urls_array[] = $api_url.'?'.$data;
        }
        /* #endregion */
    }
    /* #endregion */

    /* #region  to get all languages data which exist on api according to ticket id and other params */
    function get_all_languages_data($pass_data,$product_languages,$language_index = 0) {
        $count = count($product_languages);
        if($language_index < $count) {
            $product_language = $product_languages[$language_index];
            $language_index++;
            /* #region  to call api according to languages */
            $pass_data['product_content_language'] = $product_language;
            $data_for_search = http_build_query($pass_data); 
            $api_urls_array = array(PRODUCTS_LISTING_v3_6);
            $elastic_nodes_array = array(PRODUCTS_LISTING_v3_6 => $this->config->config['products_listing_node_v3.5']);
            foreach($api_urls_array as $api_url) {
                $this->nodes_array[$api_url.'?'.$data_for_search] = $elastic_nodes_array[$api_url];
                $this->api_urls_languages[] = $api_url.'?'.$data_for_search;
            }
            /* #endregion */ 
            /* #region  calling function in loop to get all languages data */
            $this->get_all_languages_data($pass_data,$product_languages,$language_index);
            /* #endregion */
        }

    }
    /* #endregion */

    function get_already_inserted_data($cod_ids,$ticket_ids = array()){
        if(!empty($ticket_ids)) {
            $query = '{"query": {"bool": {"must": [{"terms": {"supplier_id": ['.implode(',',$cod_ids).']}},{"terms": {"product_id": ['.implode(',',$ticket_ids).']}},{"terms": {"product_view_type": ["supplier"]}}]}}}';
        } else {
            $query = '{"query": {"bool": {"must": [{"terms": {"supplier_id": ['.implode(',',$cod_ids).']}},{"terms": {"product_view_type": ["supplier"]}}]}}}';
        }
        $hosts = [
            'host' => $this->config->config['elastic_search_url'],
        ];
        $clientBuilder = ClientBuilder::create();   // Instantiate a new ClientBuilder
        $clientBuilder->setHosts($hosts);           // Set the hosts
        $client = $clientBuilder->build();
        $elastic_nodes_array = array(PRODUCTS_LISTING_v3_6 => $this->config->config['products_listing_node_v3.5']);
        foreach($elastic_nodes_array as $node) {
            $params = [
                'index' => $node,
                'size'   => 10000,
                'body'  => $query
            ];
            //$this->CreateLog('Sync_ticket_in_queue.php','Already_inserted_query',array('Query' => $query));
            $results = $client->search($params);
            //$this->CreateLog('Sync_ticket_in_queue.php','Already_inserted_query_results',array('Results' => json_encode($results)));
            $result_count  = count($results['hits']['hits']);
            if ($result_count > 0) {
                $get_data = $results['hits']['hits'];
                foreach($get_data as $val) {
                    $this->already_inserted_ids[$node][] = $val['_id'];
                }
            }
        }
    }

    private function encryptDecrypt($string, $action = "encrypt")
    {
        $output = false;
        $encryptMethod = $this->config->config['PRIO_ENCRYPTION_METHOD'];
        $secretKey = $this->config->config['PRIO_ENCRYPTION_KEY'];
        $iv = $this->config->config['PRIO_ENCRYPTION_IV'];

        // hash
        $key = hash('sha256', $secretKey);
        try {
        if ($action == 'encrypt') {
        $output = openssl_encrypt($string, $encryptMethod, $key, 0, $iv);
        $output = base64_encode($output);
        } elseif ($action == 'decrypt') {
        $output = openssl_decrypt(base64_decode($string), $encryptMethod, $key, 0, $iv);
        }
        } catch (Exception $e) {
            $this->CreateLog('decrypt_encrypt_issue.txt','Message',array('Error' => $e->getMessage()));
        }
        return $output;
    }

    private function checktokenTime() {
        
		if ( is_file('application/storage/logs/elastic_search_session_token.json') ) {
			$data = file_get_contents ("application/storage/logs/elastic_search_session_token.json");
            $decrypted = $this->encryptDecrypt($data,'decrypt');
            $json = json_decode($decrypted, true);
            $currentTime = time();
			$minutes = floor(round(abs($currentTime - $json['session_time']) / 60,2));
			if( $minutes > 10 ) {
				return $this->createSession();
			}

			if( empty( $json['idToken'] ) ) {
				return $this->createSession();
			}
			return $json['idToken'];
		}
		return $this->createSession(); 
    }
    /* #region  to create session to call api data */
    private function createSession(){
        $url = 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key=' . FIREBASE_PROJECT_KEY;
        $apiData = json_encode(array(
            "email" => SUPERADMIN_USERNAME_FOR_SCRIPT,
            "password" => SUPERADMIN_PASSWORD_FOR_SCRIPT, 
            "returnSecureToken" => true
        ));
        $headers = array(
            'Content-Type: application/json'                
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $apiData);
        $response = curl_exec($ch);
        curl_close($ch);
        //$this->CreateLog('Sync_ticket_in_queue.php','Create_session_response',array('Response' => $response));
        $response_data = json_decode( $response, true );
        if ( !isset( $response_data['error'] ) && !empty( $response_data['idToken'] ) ) {
            
            $sessionData['idToken'] = $response_data['idToken'];
            $sessionData['refreshToken'] = $response_data['refreshToken'];
            $sessionData['session_time'] = time();
			$encrypted = $this->encryptDecrypt(json_encode($sessionData, JSON_PRETTY_PRINT));
			$fp = fopen('application/storage/logs/elastic_search_session_token.json', 'w');
			fwrite($fp, $encrypted);
			fclose($fp);
			
			return $sessionData['idToken'];
        }
    }
    /* #endregion */
    
    function processProductListingUrls() {
        include_once 'vendor/autoload.php';
         /* #region  To create session To get ticket data from products api */
         /* #endregion */
        $postBody = file_get_contents('php://input');
        //$this->CreateLog('Sync_ticket_in_queue.php', 'step 1', array("params" => $postBody));
        if (SERVER_ENVIRONMENT == 'Local') {
            $receive_data = json_decode(file_get_contents('php://input'), true);
            $messages = $receive_data['Messages'];
            $this->CreateLog('Sync_ticket_in_queue_local.php', 'step 1', array("Messages" => json_encode($messages)));
        } else {
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            $queue_url = SYNC_TICKET_QUEUE_URL;
            $messages = $sqs_object->receiveMessage($queue_url);
            $messages = $messages->getPath('Messages');
        }
        //$this->CreateLog('Sync_ticket_in_queue.php', 'step 2', array("Messages" => json_encode($messages)));
        if ($messages) {
            foreach ($messages as $message) {
                $webhookData = array();
                $this->main_insert_data = $this->api_urls_array = $this->api_urls_languages = $this->already_inserted_ids = $this->nodes_array = [];
                $string = $message['Body'];
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                //$this->CreateLog('Sync_ticket_in_queue.php', $string, array());
                $string = str_replace("?", "", $string);
                $main_request = json_decode($string, true);
                if (SERVER_ENVIRONMENT != 'Local') {
                    $sqs_object->deleteMessage($queue_url, $message['ReceiptHandle']);
                }
                //$this->CreateLog('Sync_ticket_in_queue.php', 'Main_Request', array('Main_Request' => $string));
                $ticket_id = $main_request['ticket_id'];
                $cod_id = $main_request['cod_id'];
                $webhookData = $main_request['webhook_data'];
                $query = '';
                if($ticket_id != '' && $ticket_id != '0') {
                    if( strstr( $ticket_id, ',' ) ) {
                        $ticket_ids = array_map( 'trim', explode( ',', $ticket_id ) );
                    } else {
                        $ticket_ids[] = $ticket_id;
                    }
                    $query = 'select mec_id, cod_id from modeventcontent where  mec_id IN('.implode(',',$ticket_ids).')';
                } else if($cod_id != '' && $cod_id != '0') {
                    if( strstr( $cod_id, ',' ) ) {
                        $supplier_cod_ids = array_map( 'trim', explode( ',', $cod_id ) );
                    } else {
                        $supplier_cod_ids[] = $cod_id;
                    }
                    if(!empty($supplier_cod_ids)) {
                        $api_urls_array = array(PRODUCTS_LISTING_v3_6);
                        foreach ($api_urls_array as $api_url) {
                            foreach($supplier_cod_ids as $id) {
                                $start = 1;
                                $count = 1;
                                $i = 1;
                                for ($i = 1;$i <= $count;$i++) {
                                    $total_items = $this->insert_data_supplier($id,$start,$api_url);
                                    if($total_items >= 500) {
                                        $count = ceil($total_items/500);
                                        $start = ($start + 500);
                                    } else {
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                /* #region  to fetch hotels from pos_tickets*/
                $cod_ids = [];
                $ticket_suppliers = [];
                $data_parameters = [];

                if($query != '') {
                    $results = $this->primarydb->db->query($query)->result_array();
                }
                if (!empty($results)) {
                    foreach ($results as $mec_data) {
                        $ticket_suppliers[$mec_data["mec_id"]] = $mec_data["cod_id"];
                        $cod_ids[] = $mec_data["cod_id"];
                        $data_parameters[] = $mec_data["mec_id"] .'_'.  $mec_data["cod_id"];
                    }
                    $cod_ids = array_unique($cod_ids);
                }
                    
         
                /* #endregion */
                //$this->CreateLog('Sync_ticket_in_queue.php', 'Query_results', array('results' => json_encode($results)));
                if($cod_id != '' && $cod_id != '0') {
                    $this->get_already_inserted_data($supplier_cod_ids);
                } else {
                    $this->get_already_inserted_data($cod_ids,$ticket_ids);
                }
                //$this->CreateLog('Sync_ticket_in_queue.php', 'data_parameters', array('data_parameters' => json_encode($data_parameters)));
                if(!empty( $data_parameters)) {
                    foreach($data_parameters as $params) {
                        $parameters = explode('_',$params);
                        /* #region  call main function which will perpare all elastic data to sync */
                        $this->insert_data($parameters[1], $parameters[0]);
                        /* #endregion */ 
                    }
                }
                $idToken = $this->checktokenTime();
                $headers = array(
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$idToken
                );
                //$this->CreateLog('Sync_ticket_in_queue.php', 'Api_all_urls', array('Api_url' => json_encode($this->api_urls_array)));
                $asyncRequest = new AsyncRequest\AsyncRequest();
                /* #region  to send async request to get api data for insertion */
                if(!empty($this->api_urls_array)) {
                    foreach($this->api_urls_array as $request_urls) {
                        $request = new AsyncRequest\Request($request_urls);
                        $request->setOption(CURLOPT_RETURNTRANSFER, 1);
                        $request->setOption(CURLOPT_HTTPHEADER, $headers);
                        $request->setOption(CURLOPT_CUSTOMREQUEST, 'GET');
                        $asyncRequest->enqueue($request, function(AsyncRequest\Response $response) {
                            $parts = parse_url($response->getUrl());
                            $current_url = $response->getUrl();
                            parse_str($parts['query'], $query);
                            $api_url_pass_field = 'product_supplier';
                            $api_url_pass_id = $query['product_supplier'];
                            $api_field_type = 'SUPPLIER';
                            $response = json_decode($response->getBody(), true);
                            $items = $response['data']['items'];
                            $api_version = $response['api_version'];
                            //$this->CreateLog('Sync_ticket_in_queue.php', 'Node_check', array('Step' => '1','Url' => $current_url,'array' => json_encode($this->nodes_array)));
                            $node = $this->nodes_array[$current_url];
                            if(!empty($items)) {
                                foreach($items as $item) {
                                    $product_languages = $item['product_content_languages'];
                                    if(count($product_languages) > 1) {
                                        $data_to_pass[$api_url_pass_field] = $api_url_pass_id;
                                        $data_to_pass['product_id'] = $item['product_id'];
                                        /* #region  to make language wise data if product content language is more than one */
                                        $this->get_all_languages_data($data_to_pass,$product_languages,0);
                                        /* #endregion */
                                    } else {
                                        $insert_data['product_id'] = $item['product_id'];
                                        $insert_data['supplier_id'] = $item['product_supplier_id'];
                                        $insert_data['admin_id'] = '';
                                        $insert_data['distributor_id'] = $item['product_distributor_id'];
                                        $insert_data['product_view_type'] = $api_field_type;
                                        $insert_data['product_default_language'] = $product_languages[0];
                                        $insert_data['api_version'] = $api_version;
                                        $insert_data['data'] = $item;
                                        $insert_data['action_performed'] = 'Sqs Insertion';
                                        $insert_data['insertion_date'] = date("Y-m-d H:i:s"); 
                                        $this->main_insert_data[$node][] = $insert_data;
                                        //$this->CreateLog('Sync_ticket_in_queue.php', 'Data_check', array('Step' => json_encode($this->main_insert_data)));
                                    }
                                }
                            }
                        });
                    }
                    $asyncRequest->run();
                }
                /* #endregion */
                //$this->CreateLog('Sync_ticket_in_queue.php', 'Api_all_urls_data', array('Api_url_data' => json_encode($this->main_insert_data)));
                //$this->CreateLog('Sync_ticket_in_queue.php', 'Api_all_languages', array('Api_all_languages_url' => json_encode($this->api_urls_languages)));
                /* #region  to send async request to get data from api for all languages */
                if(!empty($this->api_urls_languages)) {
                    foreach($this->api_urls_languages as $languages_url) {
                        $request = new AsyncRequest\Request($languages_url);
                        $request->setOption(CURLOPT_RETURNTRANSFER, 1);
                        $request->setOption(CURLOPT_HTTPHEADER, $headers);
                        $request->setOption(CURLOPT_CUSTOMREQUEST, 'GET');
                        $asyncRequest->setParallelLimit(100);
                        $asyncRequest->enqueue($request, function(AsyncRequest\Response $response) {
                            $parts = parse_url($response->getUrl());
                            $current_url = $response->getUrl();
                            parse_str($parts['query'], $query);
                            $api_field_type = 'SUPPLIER';
                            if(isset($query['product_content_language']) && $query['product_content_language'] != '') {
                                $api_content_language = $query['product_content_language'];
                            } else {
                                $api_content_language = '';
                            }
                            $response = json_decode($response->getBody(), true);
                            //$this->CreateLog('Sync_ticket_in_queue.php', 'Api_all_languages_result', array('Api_all_languages_url_result' => json_encode($response)));
                            $items = $response['data']['items'];
                            $api_version = $response['api_version'];
                            $node = $this->nodes_array[$current_url];
                            if(!empty($items)) {
                                foreach($items as $item) {
                                    $insert_data['product_id'] = $item['product_id'];
                                    $insert_data['supplier_id'] = $item['product_supplier_id'];
                                    $insert_data['admin_id'] = '';
                                    $insert_data['distributor_id'] = $item['product_distributor_id'];
                                    $insert_data['product_view_type'] = $api_field_type;
                                    if($api_content_language != '') {
                                        $insert_data['product_default_language'] = $api_content_language;
                                    } else {
                                        $insert_data['product_default_language'] = $item['product_default_language'];
                                    }
                                    $insert_data['api_version'] = $api_version;
                                    $insert_data['data'] = $item;
                                    $insert_data['action_performed'] = 'Sqs Insertion';
                                    $insert_data['insertion_date'] = date("Y-m-d H:i:s");
                                    $this->main_insert_data[$node][] = $insert_data;
                                }
                            }
                        });
                    }
                    $asyncRequest->run();
                }
                /* #endregion */
                //$this->CreateLog('Sync_ticket_in_queue.php', 'items_to_insert', array('items_to_insert' => json_encode($this->main_insert_data)));
                /* #region  to make elastic client and send data to elastic server */
                $hosts = [
                    'host' => $this->config->config['elastic_search_url'],
                ];
                $params = array();
                $clientBuilder = ClientBuilder::create();   // Instantiate a new ClientBuilder
                $clientBuilder->setHosts($hosts);           // Set the hosts
                $client = $clientBuilder->build();
                if(!empty($this->main_insert_data)) {
                    foreach ($this->main_insert_data as $key => $val) {
                        foreach($val as $v) {
                            $params['body'][] = [
                                'index' => [
                                    '_index' => $key,
                                ]
                            ];
                            $params['body'][] = $v;
                        }
                    }
                    $params['refresh'] = true;
                    try {
                        $responseInsert = $client->bulk($params);
                    } 
                    catch(Exception $e) {
                    $this->CreateLog('Elastic_exception.php', 'Error', array('Error in Elastic Insertion' => $e->getMessage()));
                    }
                    if ( !empty( $webhookData ) && !empty( $responseInsert ) ) {

                        $this->product_update_webhook($webhookData[0],$webhookData[1],$webhookData[2],$webhookData[3],$webhookData[4]);
                    }
                }
                /* #endregion */

                $params = array();
                //$this->CreateLog('Sync_ticket_in_queue.php', 'items_already_inserted', array('items_already_inserted' => json_encode($this->already_inserted_ids)));
                /* #region  to delete already inserted data from elastic server */
                if( isset($this->already_inserted_ids) && !empty($this->already_inserted_ids)) {
                    foreach($this->already_inserted_ids as $ind => $ids) {
                        foreach($ids as $id) {
                            $params ['body'][] = array(  
                                'delete' => array(  
                                    '_index' => $ind,  
                                    '_type' => '_doc',  
                                    '_id' => $id  
                                )  
                            );  
                        }
                    }
                    $params['refresh'] = true;
                    $responseUpdate = $client->bulk($params);
                    if ( !empty( $webhookData ) && !empty( $responseUpdate ) ) {

                        $this->product_update_webhook($webhookData[0],$webhookData[1],$webhookData[2],$webhookData[3],$webhookData[4]);
                    }
                }
                /* #endregion */
            }
        }

    }
    /*Region to get supplier all products */
    private function insert_data_supplier($id = '', $start = 1, $api_url = '') {
        $elastic_nodes_array = array(PRODUCTS_LISTING_v3_6 => $this->config->config['products_listing_node_v3.5']);
        $node = $elastic_nodes_array[$api_url];
        if($id != '') {
            $data['cache'] = 'false';
            $total_items = 0;
            $id_token = $this->checktokenTime();
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$id_token
            );
            $data['product_supplier'] = $id;
            $data['start_index'] = $start;
            $data['items_per_page'] = 500;
            $data['product_elastic_synced'] = 1;
            $data = http_build_query($data);
            $curlurl = $api_url.'?'.$data;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $curlurl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            $api_response = curl_exec($ch);
            curl_close ($ch);
            $response = json_decode($api_response,true);
            $items = $response['data']['items'];
            $api_version = $response['api_version'];
            if(!empty($items)) {
                $total_items = $response['data']['total_items'];
                foreach($items as $item) {
                    $product_languages = $item['product_content_languages'];
                    if(count($product_languages) > 1) {
                        $data_to_pass['product_supplier'] = $id;
                        $data_to_pass['product_id'] = $item['product_id'];
                        $this->get_all_languages_data($data_to_pass,$product_languages,0);
                    } else {
                        $insert_data['product_id'] = $item['product_id'];
                        $insert_data['supplier_id'] = $item['product_supplier_id'];
                        $insert_data['admin_id'] = '';
                        $insert_data['distributor_id'] = $item['product_distributor_id'];
                        $insert_data['product_view_type'] = 'SUPPLIER';
                        $insert_data['product_default_language'] = $product_languages[0];
                        $insert_data['api_version'] = $api_version;
                        $insert_data['data'] = $item;
                        $insert_data['action_performed'] = 'Sqs Insertion';
                        $insert_data['insertion_date'] = date("Y-m-d H:i:s"); 
                        $this->main_insert_data[$node][] = $insert_data;
                    }
                } 
            }
            return $total_items;
            }
    }
    /*EndRegion to get supplier all products */

    private function product_update_webhook($product_id ,$event, $distributor_id = '', $reseller_id = '' ,$museum_id = '')
	{
        //validation for required data
        if(empty($product_id) || empty($event) || empty($museum_id) ){
            return false;
        }

        try {
        
            /** Purge data on fastly before the webhook call **/
            $this->purge_fastly_cache( $product_id );

            $data = [
                "reference_id" => $product_id, 
                "museum_id" => $museum_id, 
                "distributor_id" => $distributor_id, 
                "reseller_id" => $reseller_id, 
                "event" => [ $event ]
            ];

            $ci =& get_instance();

            // send request in queue    
            if ($_SERVER['HTTP_HOST'] != 'localhost' && !strstr($_SERVER['HTTP_HOST'], '10.10.10.')) {
                $message = array( $data );
                $aws_message = base64_encode(gzcompress(json_encode($message)));
                include_once 'aws-php-sdk/aws-autoloader.php';
                $queueUrl = PRODUCT_UPDATE_WEBHOOK; // live_event_queue
                $ci->load->library('Sqs');
                $sqs_object = new Sqs();
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId != false) {
                    $ci->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, PRODUCT_UPDATE_WEBHOOK_ARN);
                }
                $message['MESSAGE_ID'] = $MessageId;
            }
            $this->CreateLog('WebhookResponse.php', 'Response',  array('Queue Url' => PRODUCT_UPDATE_WEBHOOK, 'Queue Arn' => PRODUCT_UPDATE_WEBHOOK_ARN, 'MESSAGE' => json_encode($message)));
            $this->check_update_mmt_product($product_id);
        }
        catch ( exception $e ){
            $message['error'] = $e;
            $this->CreateLog('WebhookResponse.txt', 'Response',  array('Queue Url' => PRODUCT_UPDATE_WEBHOOK, 'Queue Arn' => PRODUCT_UPDATE_WEBHOOK_ARN, 'MESSAGE' => json_encode($message)));
        }
		
	}

    /**
	 * check_update_mmt_product
	 *
	 * @param  mixed $product_id - ID of product which must be checked and updated in MMT system
	 * @return void
	 * @author Rohan Singh Chauhan <rohan.aipl@gmail.com> on 19th March, 2021
	 */
	function check_update_mmt_product($product_id)
	{
        $this->load->model('common_model');

		/* #region  BOC of check_update_mmt_product */
		/* #region  If a product is MMT product, then call the webhook */
		if ($this->common_model->is_mmt_product($product_id)) {
            
			if ($_SERVER['HTTP_HOST'] != 'localhost' && !strstr($_SERVER['HTTP_HOST'], '10.10.10.')) {
                $message = array("product_id" => $product_id);
                $aws_message = base64_encode(gzcompress(json_encode($message)));
                include_once 'aws-php-sdk/aws-autoloader.php';
                $queueUrl = MMT_WEBHOOK; // live_event_queue
                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId != false) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, MMT_WEBHOOK_ARN);
                }
                $this->CreateLog('mmt_webhook', $product_id, array('MESSAGE_ID' => $MessageId,));
            } else {
                $url = $this->config->config['cron_url'] . '/api/update_product_mmt/' . $product_id . '/1';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                $reponse = curl_exec($ch);
                $this->CreateLog('mmt_webhook', $product_id, array('url' => $url, 'response' => $reponse, 'response_http_code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE)));
                curl_close($ch);
            }
        }
        
        
		/* #endregion If a product is MMT product, then call the webhook */
		/* #endregion EOC of check_update_mmt_product */
	}
    /* #endregion Function to check if a product is being sold by MMT distributor, if yes, then update MMT's system */
    
    /* #region Function to purge data on fastly */
    /**
	 * purge_fastly_cache
	 *
	 * @param  mixed $product_id - ID of product for which the purging will be done
	 * @return void
	 * @author Jatinder kumar <jatinder.aipl@gmail.com> on 15th Feb, 2022
    */
    private function purge_fastly_cache( $ticket_id ) {

        $this->load->model('common_model');
        $all_ticket_templates = $this->common_model->find('template_level_tickets', array('select' => 'DISTINCT(template_id) as template_id', 'where' => 'ticket_id = ' . $ticket_id.' and deleted = "0"'));
        $purge_key['surrogate_keys'] = [];
        foreach($all_ticket_templates as $template) 
        {
            array_push($purge_key['surrogate_keys'],'product/template/'.$template['template_id']);
        }

        if( $purge_key['surrogate_keys'] )
        {
            if ($purge_key['surrogate_keys']['time_out']) {

                unset($purge_key['surrogate_keys']['time_out']);
                $curl_time_out = 2000;
            }

            /** Get details of fastly **/
            $fastly_details = json_decode( FASTLY_SERVER_DETAILS, true );
            $FASTLYURL = $fastly_details['FASTLY_URL'];
            $FASTLY_KEY = $fastly_details['FASTLY_KEY'];

            $purging_array = [];
            $startTime = round(microtime(true) * 1000);
            $purging_array['custom']['api_key'] = $FASTLY_KEY;
            $purging_array['full_message']['request_key'] = $purge_key;
            $fastly_url = $FASTLYURL.'/purge';
            $headers = array(
                "Accept: application/json",
                "Fastly-Key: ".$FASTLY_KEY,
            );
            $purging_array['full_message']['headers'] = $headers;

            $ch = curl_init($fastly_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode( $purge_key ) );
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
            if (!empty($curl_time_out)) {
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, $curl_time_out);
            }

            $response = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);    
            curl_close ($ch);

            /* #region  BOC for hitting fastly url again if the response is 503 (2 times) */
            if( $http_status == 503 ) {

                for($i = 0; $i < 2; $i++){

                    if($http_status == 200) {
                        break;
                    }
                    $ch = curl_init($fastly_url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, true);
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    $response = curl_exec($ch);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close ($ch);
                }
            }
            /* #endregion EOC for hitting fastly url again if the response is 503 (2 times) */

            $this->CreateLog('purging_response_log_for_livn.txt', 'Response',  array( 'MESSAGE' => json_encode( $response ) ) );
        }
    }
    /* #endregion Function to purge data on fastly */

    /*EndRegion to get supplier all products */
}
