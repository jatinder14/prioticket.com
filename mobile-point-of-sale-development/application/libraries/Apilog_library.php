<?php

use function GuzzleHttp\json_encode;

error_reporting(E_ALL);
ini_set("display_errors", 0);

# ThirdParty: PHP wrapper class for GT Seller APIs
# Author: Hardeep Kaur

class Apilog_library {
    public function __construct() { }
    
    /**
     * Function to write logs for MPOS
     * @called_from : write_log()
     * @params : 
     *      $data -> complete data to be written in logs, 
     *      $log_type -> mainLog or internalLog
     *      $reference -> unique identifier
     * @created_by : Vaishali Raheja <vaishali.intersoft@gmail.com>
     * @created_on : 1 Feb 2019
     */
    public function write_log($data = array(), $log_type = 'mainLog', $reference = '', $spcl_ref = ''){
        $this->CI =& get_instance();
        $mpos_log = $this->CI->config->config['mpos_log'];
        if($log_type == 'internalLog'){
            //for internal_logs
            $file = 'internal_logs.php';
        } else if($log_type == 'venueLog'){
            //for venue app logs
            $file = 'venue_logs.php';
            $spcl_ref = ($spcl_ref != '') ? $spcl_ref : 'old_venue_app_scan';
        } else {
            //for main logs
            $file = 'mpos_logs.php';
        }
        $api_name = $data['API'];
        $logs = $data; 
        $graylogs[] = array(
                    'log_type' => isset($log_type) ? $log_type : 'mainLog',
                    'data' => json_encode($data), 
                    'api_name' => $api_name, 
                    'reference' => isset($reference) ? $reference : '',
                    'spcl_ref' => $spcl_ref
                );
        unset($logs['API']);
        switch ($mpos_log){
            case 0 : 
                if (!empty($logs)) {
                    $this->CreateMposLog($file, $api_name, array(json_encode($data)));
                    break;
                }
            case 1 :
                if (!empty($logs)) {
                    $this->CreateGrayLog($graylogs);
                    break;
                }
            case 2 : 
                if (!empty($logs)) {
                    $this->CreateMposLog($file, $api_name, array(json_encode($data)));
                    $this->CreateGrayLog($graylogs);
                }
                break;
            default :
                break;
        }
    }
    
    /**
     * Function to write logs on graylog server for MPOS
     * @param type $graylogsArray 
     * @called_from : write_log()
     */
    public function CreateGrayLog($graylogsArray = '') {
          $CI =& get_instance();
        if (!empty($graylogsArray)) {
            /* Get Ip Address */
            $ip_add_check = $_SERVER['REMOTE_ADDR'];
            if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip_add_check = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $ipAddress = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : $ip_add_check;
            $date = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000);
            $graylog_array = [];
            
            foreach ($graylogsArray as $graylogs) {
                $reference_no = isset($graylogs['reference']) ? $graylogs['reference'] : '';
                $api = isset($graylogs['api_name']) ? $graylogs['api_name'] : $_SERVER['REQUEST_URI'];
                $data = isset($graylogs['data']) ? $graylogs['data'] : '';
                $get_header['headers'] = $this->info_from_data($data, 'headers');
                $data = json_decode($data);
                unset($data->headers);
                $data = json_encode($data);
                $get_header = json_encode($get_header);
                //Prepare data to send on graylog server
                $graylog_array[] = array(
                    internal_log => ($graylogs['log_type'] == 'internalLog') ? 1 : 0,
                    error_code => $this->info_from_data($data, 'exception'),
                    exception_details => $this->info_from_data($data, 'exception_details'),

                    short_message => ucfirst($api),
                    full_message => !empty($data) ? $data : ucfirst($api), //data

                    order_reference => $reference_no, //unique identifier
                    request_reference => (isset($graylogs['spcl_ref']) && $graylogs['spcl_ref'] != '') ? $graylogs['spcl_ref'] : $this->info_from_data($data, 'request_reference'),
                    external_reference => ($this->info_from_data($data, 'external_reference') != '') ? $this->info_from_data($data, 'external_reference') : $graylogs['spcl_ref'],
                    operation_id => ($this->info_from_data($data, 'operation_id') != '') ? $this->info_from_data($data, 'operation_id') : $this->info_from_data($data, 'exception'),

                    processing_time =>  $this->info_from_data($data, 'processing_time'),
                    created_datetime => $date,

                    http_status => (http_response_code() !== '') ? http_response_code() : 200,
                    http_request => (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_SCHEME']."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'] : "",
                    http_method => (isset($_SERVER['REQUEST_METHOD']) && !empty($_SERVER['REQUEST_METHOD'])) ? $_SERVER['REQUEST_METHOD'] : "",
                    header_data => $this->info_from_data($get_header, 'headers'),

                    server => ucfirst(LOCAL_ENVIRONMENT),
                    host_name => SERVER_URL, //source on graylog server
                    source_name => $_SERVER['REQUEST_URI'],
                    source_ip => $ipAddress,

                    pos_name => 'MPOS',
                    cashier_email => $this->info_from_data($data, 'cashier_email'),
                    cashier_name => $this->info_from_data($data, 'cashier_name'),
                    user_type => $this->info_from_data($data, 'user_type'),

                    db_version => $this->info_from_data($data, 'db_version'),
                    api_version => $this->info_from_data($data, 'buildversion'),
                );
            }
            //SNS call to write $graylog_array on graylogs server
            if (!empty($graylog_array)) {
                $queue_data = $graylog_array;
                $CI = & get_instance();
                try {
                    $aws_message = json_encode($queue_data);
                    $aws_message = base64_encode(gzcompress($aws_message));
                    $queueUrl = API_GRAYLOG_LOG_QUEUE;
                    require_once 'aws-php-sdk/aws-autoloader.php';

                    $CI->load->library('Sqs');
                    $sqs_object = new Sqs();
                    if (LOCAL_ENVIRONMENT != 'Local') {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId) {
                            $CI->load->library('Sns');
                            $sns_object = new Sns();
                            $sns_object->publish($queueUrl, API_GRAYLOG_LOG_ARN);
                        }
                    }
                } catch (Exception $e) {
                    
                }
            }
        }
    }
    
    /**
     * Function to write MPOS logs
     * @called_from : write_log()
     * @params : 
     *      $filename -> mpos_logs.php or internal_logs.php, 
     *      $apiname -> gives api name for which log is to be written, 
     *      $paramsArray -> complete data to be written in json format
     */
    public function CreateMposLog($filename, $apiname, $paramsArray) {
        if(ENABLE_LOGS){
            /*Get Ip Address */
            $ip_add_check = $_SERVER['REMOTE_ADDR'];
            if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip_add_check = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $ipAddress = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : $ip_add_check;
            $log = '';
            $log .= 'IP_Address : '.(($ipAddress != '') ? $ipAddress : '')."\n";
	    $date = gmdate("m-d-Y H:i:s:").gettimeofday()["usec"]; 
            $log .= 'Time: ' . $date . "\r" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $key=>$param) {
                    if ($i == 0) {
                        $log .= $key.'=>'.$param;
                    } else {
                        $log .= ', ' . $key.'=>'.$param;
                    }
                    $i++;
                }
            }
            $log = "Start API -> ". strtoupper($apiname)."\n".$log."\nEnd\n";
            $hostname = gethostname();
            if(!empty($hostname)) {
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $filename = str_replace('.php', '', $filename).'_'.str_replace('.', '_', $hostname).'.'.$ext;
            }
            if (is_file('application/storage/logs/mpos_logs/'.$filename) && (filesize('application/storage/logs/mpos_logs/'.$filename) > 1048576)) {
                rename("application/storage/logs/mpos_logs/$filename", "application/storage/logs/mpos_logs/".$filename."_". date("m-d-Y-H-i-s") . ".php");
            }
            $fp = fopen('application/storage/logs/mpos_logs/'.$filename, 'a');               
            fwrite($fp, "\n" . $log);
            fclose($fp);
        }         
    }

    function info_from_data($data = array(), $toFetch = 'request') {
        if(!is_array($data)) {
            $data = json_decode($data, true);
        }
        $keys = array_keys($data);
        if(in_array($toFetch, $keys)) {
            return $data[$toFetch];
        }
        if($toFetch == 'processing_time' && isset($data['response']) && isset($data['response']['response_time']) && isset($data['request_time'])) {
            return strtotime($data['response']['response_time']) - strtotime($data['request_time']);
        }
        if($toFetch == 'request_reference' && isset($data['request'])) {
            if(isset($data['request']['pass_no'])) {
                return $data['request']['pass_no'];
            }
            if (isset($data['request']['passNo'])) {
                return $data['request']['passNo'];
            }
            if (isset($data['request']['visitor_group_no'])) {
                return $data['request']['visitor_group_no'];
            }
            if (isset($data['request']['vgn'])) {
                return $data['request']['vgn'];
            }
            if (isset($data['request']['prepaid_ticket_id'])) {
                return $data['request']['prepaid_ticket_id'];
            }
            if (isset($data['request']['supplier_id'])) {
                return $data['request']['supplier_id'];
            }
            if (isset($data['request']['distributor_id'])) {
                return $data['request']['distributor_id'];
            }
        }
        if($toFetch == 'exception' && isset($data['response']) && isset($data['response']['errorCode'])) {
            return $data['response']['errorCode'];
        }
        if($toFetch == 'exception_details' && isset($data['response']) && isset($data['response']['errorCode'])) {
            return (isset($data['response']['errorMessageFor_GL']) && $data['response']['errorMessageFor_GL'] != '') ? $data['response']['errorMessageFor_GL'] : $data['response']['errorMessage'];
        }
        if($toFetch == 'cashier_email' && isset($data['validated_token']) && isset($data['validated_token']['user_details']) && isset($data['validated_token']['user_details']['email'])) {
            return $data['validated_token']['user_details']['email'];
        }
        if($toFetch == 'cashier_name' && isset($data['validated_token']) && isset($data['validated_token']['user_details']) && isset($data['validated_token']['user_details']['name'])) {
            return $data['validated_token']['user_details']['name'];
        }
        if($toFetch == 'user_type' && isset($data['validated_token']) && isset($data['validated_token']['user_details']) && isset($data['validated_token']['user_details']['cashierType'])) {
            return $data['validated_token']['user_details']['cashierType'];
        }        
        if($toFetch == 'db_version') {
            $str = '';
            if(isset($data['DB'])) {
                sort($data['DB']);
                $str = is_array($data['DB']) ? implode(' ', array_unique($data['DB'])) : $data['DB'];
            }
            return 'DB1 '. $str;
        }
              
        if($toFetch == 'buildversion' && isset($data['headers']) && isset($data['headers']['buildversion']) && $data['headers']['buildversion'] != '') {
            $buildVersion = $data['headers']['buildversion'];
            if(stripos($data['headers']['user_agent'],"android")) {
                $version = 'Android - ' . $buildVersion;
            } else {
                $version = 'IOS - ' . $buildVersion;
            }
            return $version;
        }
        return '';
    }
    
}
?>
