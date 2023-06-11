<?php

/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014 - 2019, British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package	CodeIgniter
 */
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter Curl Class
 *
 * Permits email to be sent using Mail, Sendmail, or SMTP.
 *
 * @package	CodeIgniter
 * @subpackage	Libraries
 */
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

class Curl {

    /**
     * $CACHE_SERVER - Used as the Cache server url value. -> @var string
     * $FIREBASE_SERVER - Used as the FireBase server url value. -> @var string
     * $CLIENT
     * $API - Used for API name which is called. -> @var string
     * $BODY - Used For body of request which is being synced. -> @var string
     * $DB_TYPE - gives type of req posted -> @var string
     */
    public $CACHE_SERVER;
    public $FIREBASE_SERVER;
    public $CLIENT;
    public $API;
    public $BODY;
    public $DB_TYPE;
    
    /**
     * Constructor - Sets Curl Preferences
     *
     * The constructor can be passed an array of config values
     *
     */
    public function __construct() {
        require_once 'guzzel/vendor/autoload.php';
        $this->CLIENT = new \GuzzleHttp\Client();
        $this->CACHE_SERVER = REDIS_SERVER;
        $this->FIREBASE_SERVER = FIREBASE_SERVER;
        $this->CI = & get_instance();
        $this->CI->load->library('apilog_library');
    }

    /**
     * @name : request()
     * @purpose : to post sync request to firebase and cache servers
     * @param: 
     *       $type -> CACHE or FIREBASE request
     *       $api -> API name
     *       $params -> having headers , http method, and body of req
     */
    public function request($type = 'CACHE', $api = '', $params = array()) {
        try {
            if (!isset($params['type'])) {
                $params['type'] = 'POST';
            }
            $params['json'] = $params['body'];
            unset($params['body']);
            $params['headers'] = $this->include_authentication_headers($params['additional_headers']);
            $params['connect_timeout'] = 0;
            if($type == 'WEB_API') {
                $params['verify'] = false;
                $response = $this->CLIENT->request($params['type'], $api, $params);               
            } else {
                $response = $this->CLIENT->request($params['type'], $this->{$type . '_SERVER'} . $api, $params);
            }  
            
            if ($response->getStatusCode() != 200) {
                $this->CI->apilog_library->CreateMposLog("curl_exceptions.php", "request " . $type . " -> failure " . $api, array(json_encode($e), json_encode($e->getmessage())));
            } else {
                $this->CI->apilog_library->CreateMposLog("curl_library.php", "request " . $type . " -> success " . $api, array(json_encode($params)));
            }
            return $response->getBody();
        } catch (RequestException $e) {
            $this->CI->apilog_library->CreateMposLog("curl_exceptions.php", "request " . $type . " -> exception " . $api, array(json_encode($e), json_encode($e->getmessage())));
        }
    }

    /**
     * @name : requestASYNC()
     * @purpose : to post async request to firebase and cache servers
     * @param: 
     *       $type -> CACHE or FIREBASE request
     *       $api -> API name
     *       $params -> having headers , http method, and body of req
     */
    public function requestASYNC($type = 'CACHE', $api = '', $params = array()) {
        $this->API = $api;
        $this->BODY = $params['body'];
        $this->DB_TYPE = $type;
        $params['headers'] = $this->include_authentication_headers($params['additional_headers']);
        $params['connect_timeout'] = CURL_TIMEOUT;
        $url = $this->{$type . '_SERVER'} . $this->API;
        $request = new \GuzzleHttp\Psr7\Request($params['type'], $url, $params['headers'], json_encode($this->BODY));
        $this->CLIENT->sendAsync($request)->then(function ($response) {
            $this->CI->apilog_library->CreateMposLog("curl_library.php", "requestASYNC " . $this->DB_TYPE . " -> success " . $this->API, array(json_encode($this->BODY)));
        }, function (RequestException $e) {
            $this->CI->apilog_library->CreateMposLog("curl_exceptions.php", "requestASYNC " . $this->DB_TYPE . " -> failure " . $this->API, array(json_encode($e), json_encode($e->getmessage())));
        })->wait();
    }

    /**
     * @name : include_authentication_headers()
     * @purpose : to include all necessary headers in requests
     * @param: 
     *       $additional_headers -> headers other than authenticators
     */
    private function include_authentication_headers($additional_headers = array()) {
        if (!empty($additional_headers)) {
            $params['headers'] = array_merge(array('Content-Type' => 'application/json', 'Authorization' => (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY)), $additional_headers);
            unset($params['additional_headers']);
        } else {
            $params['headers'] = array('Content-Type' => 'application/json', 'Authorization' => (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY));
        }
        return $params['headers'];
    }

}
