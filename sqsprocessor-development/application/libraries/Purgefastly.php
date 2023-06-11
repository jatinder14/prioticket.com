<?php
# ThirdParty: PHP wrapper class for GT Seller APIs
# Author: Hardeep Kaur

class Purgefastly {
    
    function __construct() {
        $this->CI = & get_instance();
        $this->CI->load->library('log_library');
    }
    //region to purge cache from fastly using surrogate keys /
    /**
    * @name: purge_fastly_cache 
    * @purpose: To purge cache from fastly
    * @params: $keys - Keys made on fastly
    * @returns: No parameter is returned
    */
    function purge_fastly_cache($purge_key = '', $reference_no = '', $fastly_purge_action = "", $chkAgain = 0){        $fastly_server_details = !empty(FASTLY_SERVER_DETAILS) ? json_decode(FASTLY_SERVER_DETAILS, true): "";
        if (!empty($fastly_server_details) && !empty($purge_key)) {
            if(!empty($fastly_server_details['FASTLY_URL']) && !empty($fastly_server_details['SERVICE_ID']) && !empty($fastly_server_details['FASTLY_KEY'])){
                $start_time =  microtime(true);
                $fastly_url = $fastly_server_details['FASTLY_URL'].$fastly_server_details['SERVICE_ID'].'/purge';
                $headers = array(
                    "Content-Type: application/json",
                    "Accept: application/json",
                    "Fastly-Key: ".$fastly_server_details['FASTLY_KEY'],
                    "Surrogate-Key: ".$purge_key,
                    "Fastly-Soft-Purge: 1"
                );
                $ch = curl_init($fastly_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);
                $response = curl_exec($ch); 
                $curl_info = curl_getinfo($ch);
                curl_close ($ch);
                if ($curl_info['http_code'] == '503' && $chkAgain == 0) {
                    $this->purge_fastly_cache($purge_key, $reference_no, $fastly_purge_action, 1);
                }
                $end_time = microtime(true);
                $processing_times = (int) (($end_time - $start_time) * 1000);
                $graylogs_data = [];
                $graylogs_data[] = array(
                    'source_name' => 'Fastly Purge Cache',
                    'reference' => $reference_no,
                    'log_name' => 'Purging keys to fastly',
                    'api_name' => 'Purging_fastly_keys',
                    'request_time' => date("Y-m-d H:i:s"),
                    'http_status' =>  !empty($curl_info['http_code']) ? $curl_info['http_code'] : '' ,
                    'fastly_purge_action' => $fastly_purge_action,
                    'processing_time' => $processing_times,
                    'operation_id' => 'FASTLY_ORDER_PURGE',
                    'data' => ['fastly_url'=> $fastly_url, 'method' => 'POST','headers' => $headers , 'http_status' =>  (!empty($curl_info['http_code']) ? $curl_info['http_code'] : ''), 'fastly_purge_action' => $fastly_purge_action , 'response' => $response],
                );

                $this->CI->log_library->CreateGrayLog($graylogs_data);
            }
        } 
    }
    // region to purge cache from fastly using surrogate keys 
    
}
?>
