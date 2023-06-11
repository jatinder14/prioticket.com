<?php
/**
 * This helper will send request to any other server via curl request.
 * @CreatedBy   : Haripriya <h.priya@prioticket.com> on 03 April 2020
 */
class SendRequest {
    
    /**
    * @Name        : static send_request()
    * @Purpose     : To send the request to another server.
    * @CreatedBy   : Haripriya <h.priya@prioticket.com> on 03 April 2020
    */
    static function  send_request($params){
        $timeout = isset($params['timeout']) ? $params['timeout'] : 2000; /* timeout for CURL request handling */
        $headers = is_array($params['headers']) ? $params['headers'] : array($params['headers']);
        $ch = curl_init();
        if (count($params['request'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params['request']));
        }
        if(!empty($params['basic_auth'])){
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $params['auntherisation']); /*Your credentials goes here*/
        }
        curl_setopt($ch, CURLOPT_URL, $params['end_point']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $params['method']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);
        $curl_response = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_error = curl_strerror(curl_errno($ch));
        curl_close($ch);
        return array('response' => json_decode($curl_response, TRUE), 'curl_info' => $curl_info, 'curl_error' => $curl_error, 'request' => $params['request']);
    }
}
?>