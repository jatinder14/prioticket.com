
<?php

use Elasticsearch\ClientBuilder;

require_once '././././elasticsearch/vendor/autoload.php';

class Elastic_search 
{

    function __construct() 
    {
        $this->CI = & get_instance();
        $this->CI->load->library('log_library');
        $this->CI->load->library('purgefastly');
    }

    /**
     * Sync data function call publicaly
     * 
     * sync_data
     *
     * @Params : $visitor_group_no visitor_group_no
     * @Params : $other_data       other_data
     * 
     * @Return : void()
     * 
     * @Created by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    public function sync_data($visitor_group_no = '', $other_data = []) 
    {

        $this->CI->log_library->write_log(array('Library_Called' => 'True'), 'internalLog', 'Function_called_' . $visitor_group_no);
        global $ELASTIC_SEARCH_LOGS;
        $logs = $response = array();

        $logs['visitor_group_no'] = $visitor_group_no;
        if (!empty($visitor_group_no)) {

            $this->index_array = $this->delete_ids = $this->final_insert_data = $this->api_urls_array = array();
            $this->other_data = $other_data;
            $this->visitor_group_no = $visitor_group_no;
            $urls_array = [ELASTIC_SEARCH_URL_v3_6];
            $index_array = array(ELASTIC_SEARCH_URL_v3_6 => $this->CI->config->config['elastic_search_node_v3.5']);
            foreach ($urls_array as $url) {

                /* Call to private function to get URL's and indexes */
                $this->_apiResults($visitor_group_no, $url, $index_array[$url]);
            }
        }
        include_once 'vendor/autoload.php';
        $asyncRequest = new AsyncRequest\AsyncRequest();
        $idToken = $this->_checktokenTime();
        /* If token not found, terminate the syncing to elastic and shot another email */
        if (!$idToken) {
            $this->_shootErrorEmail(array("Error" => "Syncing error", "Details" => "No token found in sync_data, Syncing to elastic terminated", "Subject" => 'Syncing terminated'));
            return false;
        }
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$idToken
        );
        if (!empty($this->api_urls_array)) {

            foreach ($this->api_urls_array as $request_urls) {

                $request = new AsyncRequest\Request($request_urls);
                $request->setOption(CURLOPT_RETURNTRANSFER, 1);
                $request->setOption(CURLOPT_HTTPHEADER, $headers);
                $request->setOption(CURLOPT_CUSTOMREQUEST, 'GET');
                $asyncRequest->enqueue($request, function(AsyncRequest\Response $response) {

                    $current_url = $response->getUrl();
                    $chk_errorsdata = json_decode($response->getBody(), true);
                    if (isset($chk_errorsdata['errors']) && !empty($chk_errorsdata['errors'])) {

                        if ("The JWT has expired." == trim($chk_errorsdata['errors'])) {

                            /* Call to private function */
                            $idToken = $this->_createSession();
                            $headers = array(
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $idToken
                            );

                            /* Converted to private function syntax */
                            $responseData = $this->_requestCurl($current_url . '/' . $this->visitor_group_no . '?cache=false&write_db=true', $headers, '', 'GET');
                            $chk_errorsdata = json_decode($responseData, true);
                            if (isset($chk_errorsdata['errors']) && !empty($chk_errorsdata['errors'])) {

                                /* Start code to purge keys as per the distributer on fastly */
                                if (!empty($this->other_data['hotel_id'])) {
                                    $this->purge_fastly_data_call($this->other_data['hotel_id'], $this->visitor_group_no, $this->other_data);
                                }
                                /* end code to purge keys as per the distributer on fastly */
                                $this->CI->log_library->write_log($chk_errorsdata['errors'], 'exception', 'exception_' . $this->visitor_group_no, 'INVALID_BOOKING_REFERENCE');
                                return $chk_errorsdata['errors'];
                            } elseif (!empty($chk_errorsdata['data']['order']['order_distributor_id'])) {

                                /* Start code to purge keys as per the distributer on fastly */
                                $this->purge_fastly_data_call($chk_errorsdata['data']['order']['order_distributor_id'], $this->visitor_group_no, $this->other_data);
                                /* end code to purge keys as per the distributer on fastly */
                            }
                        }
                    }
                    $response = json_decode($response->getBody(), true);
                    $item = $response['data']['order'];
                    $api_version = $response['api_version'];
                    if (!empty($item)) {
                        $insert_data[$item['order_reference'] . '_' . $item['order_version']] = $item;
                    } else {
                        $this->CI->log_library->write_log($response, 'exception', 'exception_' . $this->visitor_group_no, 'RESULTS_NOT_FOUND');
                    }
                    if (!empty($item['order_distributor_id']) && empty($fastlykey)) {
                        $this->purge_fastly_data_call($item['order_distributor_id'], $this->visitor_group_no, $this->other_data);
                    }
                    $node = $this->index_array[$current_url];
                    foreach ($insert_data as $key => $data) {

                        $main_data                                  = $data;
                        $main_insert_data['order_created_date']     = $main_data['order_created'];
                        $main_insert_data['order_distributor_id']   = $main_data['order_distributor_id'];
                        $main_insert_data['order_reference']        = $main_data['order_reference'];
                        $this->delete_ids[$node][]                  = $main_data['order_reference'];

                        $main_insert_data['order_version']          = $main_data['order_version'];
                        $main_insert_data['api_version']            = $api_version;
                        $main_insert_data['data']                   = $main_data;
                        $main_insert_data['action_performed']       = 'Sqs Insertion';
                        $main_insert_data['insertion_date']         = date("Y-m-d H:i:s");
                        $this->final_insert_data[$node][]           = $main_insert_data;
                    }
                });
            }

            $asyncRequest->run();
            $hosts = [
                'host' => $this->CI->config->config['elastic_search_url']
            ];
    
            $clientBuilder = ClientBuilder::create();   // Instantiate a new ClientBuilder
            $clientBuilder->setHosts($hosts);           // Set the hosts
            $client = $clientBuilder->build();
            if (!empty($this->delete_ids)) {
                foreach ($this->delete_ids as $key => $ids) {
                    $this->_deletePreviousOrderVersions($ids, $client, $key);
                }
            }
            if (!empty($this->final_insert_data)) {

                foreach ($this->final_insert_data as $key => $val) {

                    foreach ($val as $v) {

                        $params['body'][] = [
                            'index' => [
                                '_id' => $v['order_reference'].'_'.$v['order_version'],
                                '_index' => $key,
                            ]
                        ];
                        $params['body'][] = $v;
                    }
                }
                $client->bulk($params);
            }
        }
        $this->CI->log_library->write_log($ELASTIC_SEARCH_LOGS, 'internalLog', 'sync_data_' . $visitor_group_no);
    }

    /**
     * Hit curl request
     * 
     * _requestCurl
     * 
     * @Params : $url, 
     * @Params : $headers, 
     * @Params : $data, 
     * @Params : $requestType
     * 
     * @return array()
     * 
     * @Created by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _requestCurl($url='', $headers=array(), $apiData='', $customRequestType='') 
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        //curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (!empty($apiData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $apiData);
        }
        if (!empty($customRequestType)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequestType);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * Prepare API urls and indexes
     * 
     * _apiResults
     *
     * @Params : $vgn     vgn
     * @Params : $limit   limit
     * @Params : $offsets offsets
     * 
     * @return : string
     * 
     * @Created by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _apiResults($vgn, $url, $index) 
    {

        global $ELASTIC_SEARCH_LOGS;

        $this->api_urls_array[] = $url . '/' . $vgn . '?cache=false&write_db=true';
        $this->index_array[$url . '/' . $vgn . '?cache=false&write_db=true'] = $index;
    }

    /**
     * Create session 
     * 
     * @Name : _createSession
     *
     * @return : $idToken
     * 
     * @Created by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _createSession() 
    {

        global $ELASTIC_SEARCH_LOGS;
        $logs = array();
        $url = 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key=' . FIREBASE_PROJECT_KEY;
        $apiData = json_encode(array("email" => SUPERADMIN_USERNAME_FOR_SCRIPT, "password" => SUPERADMIN_PASSWORD_FOR_SCRIPT, "returnSecureToken" => true));
        $headers = array(
            'Content-Type: application/json'
        );

        $logs['url'] = $url;
        $logs['headers'] = $headers;
        /* Converted to private function syntax */
        $response = $this->_requestCurl($url, $headers, $apiData);
        $ELASTIC_SEARCH_LOGS['api_results_' . date('Y-m-d H:i:s')] = $logs;
        $response_data = json_decode($response, true);
        if (!isset($response_data['error']) && !empty($response_data['idToken'])) {

            $sessionData['idToken'] = $response_data['idToken'];
            $sessionData['refreshToken'] = $response_data['refreshToken'];
            $sessionData['session_time'] = time();
            $encrypted = $this->_encryptDecrypt(json_encode($sessionData, JSON_PRETTY_PRINT));
            $fp = fopen('application/storage/logs/elastic_search_session_token.json', 'w');
            fwrite($fp, $encrypted);
            fclose($fp);

            return $sessionData['idToken'];
        }

        /* If token not found, then shoot an email */
        if (isset($response_data['error'])) {

            $this->_shootErrorEmail(array("Error" => (!empty($response_data['error']['message'])? $response_data['error']['message']: "Something went wrong"), "Details" => $apiData, "Subject" => 'Failure on token generation for elastic syncing'));
            return false;
        }
    }

    /**
     * Check token if exists
     * 
     * @Name : checktokenTime
     * 
     * @Purpose : Checks if token file exists or last token session time is exceeded then 45 minutes then new idToken request gets hit's
     * 
     * @return : $idToken
     * 
     * @created_by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _checktokenTime() 
    {

        global $ELASTIC_SEARCH_LOGS;

        $logs                   = array();
        $currentTime            = time();
        $logs['current_time']   = $currentTime;
        if (is_file('application/storage/logs/elastic_search_session_token.json')) {

            $data                   = file_get_contents("application/storage/logs/elastic_search_session_token.json");
            $json                   = json_decode($this->_encryptDecrypt($data, 'decrypt'), true);
            $minutes                = floor(round(abs($currentTime - $json['session_time']) / 60, 2));
            $logs['file_data']      = $json;
            $logs['minutes_left']   = $minutes;
            $ELASTIC_SEARCH_LOGS['checktokenTime_' . date('Y-m-d H:i:s')] = $logs;
            if ($minutes > 10) {
                /* Call to private function to create session */
                return $this->_createSession();
            }

            if (empty($json['idToken'])) {
                /* Call to private function to create session */
                return $this->_createSession();
            }
            return $json['idToken'];
        }
        /* Call to private function to create session */
        return $this->_createSession();
    }

    /**
     * Delete previous version orders
     * 
     * _deletePreviousOrderVersions
     * 
     * @param $delete_ids delete_ids
     * @param $client     client
     * @param $index      index
     * 
     * @return : $idToken
     * 
     * @created_by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _deletePreviousOrderVersions($delete_ids, $client, $index) 
    {

        if (!empty($delete_ids)) {

            $query = '{"query": {"bool": {"must": [{"terms": {"order_reference": [' . implode(',', $delete_ids) . ']}}]}}}';
            $params = [
                'index' => $index,
                'body' => $query
            ];
            $res = $client->search($params);
            $params = array();
            if (!empty($res)) {

                $total = $res['hits']['total']['value'];
                $results = $res['hits']['hits'];
                if ($total > 0) {

                    foreach ($results as $row) {

                        $params ['body'][] = array(
                            'delete' => array(
                                '_index' => $index,
                                '_type' => '_doc',
                                '_id' => $row['_id']
                            )
                        );
                    }
                    $client->bulk($params);
                }
            }
        }
    }

    /**
     * Decrypt string
     * 
     * _encryptDecrypt
     * 
     * @param $string string
     * @param $action action
     * 
     * @return : $idToken
     * 
     * @created_by : Jatinder Kumar <jatinder.aipl@gmail.com> on Oct 19, 2020
     */
    private function _encryptDecrypt($string, $action = "encrypt") {
        $output = false;
        $encryptMethod = $this->CI->config->config['PRIO_ENCRYPTION_METHOD'];
        $secretKey = $this->CI->config->config['PRIO_ENCRYPTION_KEY'];
        $iv = $this->CI->config->config['PRIO_ENCRYPTION_IV'];

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
            $this->CI->CreateLog('decrypt_encrypt_issue.txt', 'Message', array('Error' => $e->getMessage()));
        }
        return $output;
    }
    
   /* #region  : call function to purge distributor keys in fastly */
   /**
    * Call puring platform to purge cache
    *
    * @Name : purge_fastly_data_call
    *
    * @Params : $hotel_id          hotel_id
    * @Params : $request_reference request_reference
    * @Params : $other_data        other_data
    *
    * @Created by : Neha <nehadev.aipl@gmail.com> on july 28, 2021
    */
   function purge_fastly_data_call($hotel_id = '', $request_reference = '', $other_data = []) 
   {

        if (!empty($hotel_id) && !empty($request_reference)) {

            $fastlykey = 'order/account/' . $hotel_id;
            $fastly_purge_action = (isset($other_data['fastly_purge_action']) ? $other_data['fastly_purge_action'] : '');
            $this->CI->purgefastly->purge_fastly_cache($fastlykey, $request_reference, $fastly_purge_action);
        }
    }
    /* #endregion :  call function to purge distributor keys in fastly */

    /**
     * Send error email to selected persons
     * 
     * _shootErrorEmail
     * 
     * @param $args array of arguments
     * 
     * @return : boolean
     * 
     * @created_by : Jatinder Kumar <jatinder.aipl@gmail.com> on Sep 16, 2022
     */
    private function _shootErrorEmail($args) 
    {

        if (!isset($args['Error']) && empty($args['Error'])) {
            return false;
        }

        $details = 'No details found';
        if (isset($args['Details']) && !empty($args['Details'])) {
            $details = (is_array($args['Details'])? json_encode($args['Details']): $args['Details']);
        }

        /* Load model to send email */
        $this->CI->load->model('sendemail_model');

        /* Call function to send email */
        $arraylist['emailc']        = 'p.tayal@prioticket.com';
        $arraylist['html']          = '<p> Hello Admin, </br>There is an failure while generating the token. Details are as follow:- </br>' . $details . ' </p>';
        $arraylist['from']          = PRIOPASS_NO_REPLY_EMAIL;
        $arraylist['fromname']      = 'Prioticket';
        $arraylist['subject']       = (isset($args['Subject']) && !empty($args['Subject'])? $args['Subject']: 'Failure on token gererante');
        $arraylist['attachments']   = array();
        $arraylist['BCC']           = array('j.kumar@prioticket.com');
        $this->CI->sendemail_model->sendemailtousers($arraylist, 2);

        return true;
    }
}