<?php

class AWS {

    static $utc_tz;
    private $ACCESS_KEY = '';
    private $SECRET_KEY = '';
    private $REGION = '';
    private $SERVICE = '';
    private $API_KEY = '';
    private $TOKEN = '';
    private $PURCHASE = '';
    private $datetime = '';
    private $date = '';
    private $request_headers = array();

    function __construct($access_key, $secret_key, $api_key, $region, $service, $host, $token = '', $purchase = '') {
        $this->ACCESS_KEY = $access_key;
        $this->SECRET_KEY = $secret_key;
        $this->API_KEY = $api_key;
        $this->REGION = $region;
        $this->SERVICE = $service;
        $this->HOST = $host;
        $this->TOKEN = $token;
        $this->PURCHASE = $purchase;
    }

    function GetSignedRequest($payload = '', $uri, $method = 'POST', $querystring='') {
        /* STEP 1 
         * SET DATETIME VALUES */
        $this->datetime = gmdate('Ymd\THis\Z');
        $this->date = gmdate('Ymd');
        
        //STEP 2: 
        //SET HEADERS TO FORM REQUEST
        $headers = array(
            'host' => $this->HOST,
            'content-type' => 'application/json',
            'x-amz-date' => $this->datetime,
            'content-length' => strlen( $payload ),
            'x-api-key' => $this->API_KEY,
            'x-amz-content-sha256' => hash('sha256', $payload),
        );

        if ($this->TOKEN != '') {
            $headers['token'] = $this->TOKEN;
        }

        if ($this->PURCHASE != '') {
            $headers['purchase'] = $this->PURCHASE;
        }

        ksort($headers);
        /* STEP 3: 
         * CREATE CANONICAL REQUEST */
        $canonical_request = $this->createCanonicalRequest($headers, $payload, $uri, $method, $querystring);
        /* STEP 4:
         * GET STRING TO SIGN */
        $hashed_canonical_request = hash('sha256', $canonical_request);
        
        /*STEP 5: 
         * 
         * GET THE KEY TO SIGN*/
        $ksecret = 'AWS4' . $this->SECRET_KEY;
        $kdate = hash_hmac('sha256', $this->date, $ksecret, true);
        $kregion = hash_hmac('sha256', $this->REGION, $kdate, true);
        $kservice = hash_hmac('sha256', $this->SERVICE , $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        
        /*STEP 6:
            GET SIGNED STRING         
         */
        /*Get Scope */
        $scope = $this->createScope($this->date, $this->REGION, $this->SERVICE);
        /*GET SIGNED STRING*/
        $sign_string = "AWS4-HMAC-SHA256\n{$this->datetime}\n$scope\n" . $hashed_canonical_request;
        
        /*STEP 7: 
         * GET THE SIGNATURE*/
        $signature = hash_hmac('sha256', $sign_string, $ksigning);
        
        /* SET THE HEADERS */
        
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential=" . $this->ACCESS_KEY . "/$this->date/$this->REGION/$this->SERVICE/aws4_request, " .
                "SignedHeaders=" . implode(";", array_keys($headers)) . ", " .
                "Signature=$signature";
        ksort($headers);
        
        /* SEND HEADERS */
        foreach ($headers as $heaer_key => $header_val)
            $this->request_headers[] = $heaer_key . ": " . $header_val;
        
        return $this->request_headers;
    }

    private function createScope($shortDate, $region, $service) {
        return "$shortDate/$region/$service/aws4_request";
    }

    private function createCanonicalRequest($params = array(), $payload = '', $uri = '', $method = 'POST', $querystring = '') {
        /*$uri = "clients/sagradafamilia/products/4/events";
        $querystring = "date=" . urlencode("19/12/2017");*/
        $canonical_request = array();
        $canonical_request[] = $method;
        $canonical_request[] = '/' . trim(ltrim($uri, '/'));
        $canonical_request[] = $querystring;
        foreach ($params as $k => $v)
            $canonical_request[] = $k . ':' . $v;
        $canonical_request[] = '';
        $canonical_request[] = implode(';', array_keys($params));
        $canonical_request[] = hash('sha256', $payload);
        $canonical_request = implode("\n", $canonical_request);
        return $canonical_request;
    }

}
?>