<?php
# ThirdParty: PHP wrapper class for GT Seller APIs
# Author: Hardeep Kaur

class Log_library {
    
    /**
     * Function to write logs for MPOS
     * @called_from : write_log()
     * @params : 
     *      $data -> complete data to be written in logs, 
     *      $log_type -> mainLog or internalLog
     *      $reference -> unique identifier
     * @created_by : Vaishali Raheja <vaishali.intersoft@gmail.com>
     * @created_on : 12 Feb 2019
     */
    public function write_log($data = array(), $log_type = 'mainLog', $reference = '', $exception = ''){
        $this->CI =& get_instance();
        $mpos_log = $this->CI->config->config['mpos_log'];
//        $mpos_log = '2';
        if($log_type == 'internalLog'){
            //for internal_logs
            $file = 'internal_logs.php';
        } else if ($log_type == 'arena') { /* For Arena Proxy Listner Logs */
            $file = 'arena_proxy_listener.php';
        } else if ($log_type == 'api_refund') {
            $file = 'api_refunded_records.php';
        } else if($log_type == 'import_booking') {
            $file = 'import_booking_logs.php';
        } else {
            //for main logs
            $file = 'mpos_logs.php';
        }

        /* separate case for api refund logging */
        if(!empty($data['queue']) && $data['queue'] == 'API_refund_orders') {
            unset($data['queue']);
            foreach($data as $values) {
                $graylogs[] = array(
                    'log_type'           => isset($log_type) ? $log_type : 'mainLog',
                    'data'               => $values['data'], 
                    'api_name'           => 'SQS/api_refund', 
                    'request_time'       => date("Y-m-d H:i:s"),
                    'reference'          => isset($reference) ? $reference : '',
                    'write_in_mpos_logs' => 0
                );
            }
        } else {
            $graylogs[] = array(
                'log_type'           => isset($log_type) ? $log_type : 'mainLog',
                'data'               => json_encode($data), 
                'api_name'           => (isset($data['write_in_mpos_logs']) && $data['write_in_mpos_logs'] == 1) ? 'MPOS_SQS/'.$data['queue'] : 'SQS', 
                'request_time'       => date("Y-m-d H:i:s"),
                'reference'          => isset($reference) ? $reference : '',
                'write_in_mpos_logs' => (isset($data['write_in_mpos_logs']) && $data['write_in_mpos_logs'] == 1) ? 1 : 0
            );
        }
        switch ($mpos_log){
            case 0 : 
                $this->CreateMposLog($file, $data['queue'], array(json_encode($data)));
                break;
            case 1 :
                $this->CreateGrayLog($graylogs,$exception);
                break;
            case 2 : 
                $this->CreateMposLog($file, $data['queue'], array(json_encode($data)));
                $this->CreateGrayLog($graylogs,$exception);
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
    public function CreateGrayLog($graylogsArray = '',$exception = '') {
         $CI =& get_instance();
        if (!empty($graylogsArray)) {
            /* Get Ip Address */
            $ipAddress = (!empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']));
            $date = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000);
            $graylog_array = [];
            
            foreach ($graylogsArray as $graylogs) {
                $host = (isset($graylogs['write_in_mpos_logs']) && $graylogs['write_in_mpos_logs'] == 1) ? 'mposSQS.prioticket.com' :  'SQS.prioticket.com';
                $source = (isset($graylogs['write_in_mpos_logs']) && $graylogs['write_in_mpos_logs'] == 1) ? 'mpos' :  'SQS.prioticket.com';
                $reference_no = isset($graylogs['reference']) ? $graylogs['reference'] : '';
                $api = isset($graylogs['api_name']) ? $graylogs['api_name'] : 'test';
                $log_type = isset($graylogs['log_type']) ? $graylogs['log_type'] : 'check';
                $data = isset($graylogs['data']) ? $graylogs['data'] : '';
                $host_name = isset($graylogs['host_name']) ? $graylogs['host_name'] : $host;
                $http_status = isset($graylogs['http_status']) ? $graylogs['http_status'] : 200;
                $source_name = isset($graylogs['source_name']) ? $graylogs['source_name'] : $source;
                $date = (isset($graylogs['request_time']) && !empty($graylogs['request_time'])) ? $graylogs['request_time'] : $date;
                $log_name = !empty($graylogs['log_name']) ? $graylogs['log_name'] : "";
                $processing_time = !empty($graylogs['processing_time']) ? $graylogs['processing_time'] : 0;

                //Prepare data to send on graylog server
                $graylog_array[] = array(
                    'source_name' => $source_name,
                    'source_ip' => $ipAddress,
                    'request_reference' => $reference_no, //unique identifier
                    'short_message' => !empty($log_name) ? $log_name : (ucfirst($api) . ': ' . $log_type),
                    'exception' => $exception,
                    'full_message' => !empty($data) ? $data : (ucfirst($api) . ': ' . $log_type), //data
                    'created_datetime' => $date,
                    'server' => $CI->config->item('base_url'),
                    'host_name' => $host_name, //source on graylog server
                    'http_status' => $http_status,
                    'http_request' => (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_SCHEME']."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'] : "",
                    'processing_time' => $processing_time,
                    'http_method' => (isset($_SERVER['REQUEST_METHOD']) && !empty($_SERVER['REQUEST_METHOD'])) ? $_SERVER['REQUEST_METHOD'] : "",
                    'operation_id' => !empty($graylogs['operation_id']) ? $graylogs['operation_id'] : '',
                    'fastly_purge_action' => !empty($graylogs['fastly_purge_action']) ? $graylogs['fastly_purge_action'] : '',
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
                    include_once 'aws-php-sdk/aws-autoloader.php';

                    $CI->load->library('Sqs');
                    $sqs_object = new Sqs();
                    if (SERVER_ENVIRONMENT == 'Local') {
                        //local_queue($aws_message, 'API_COMMON_API_DB1_ARN');                        
                    } else {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId != false) {
//                            $this->load->library('Sns');
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
        if(ENABLE_LOGS) {
            $log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
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

            if (is_file('application/storage/logs/'.$filename)) {
                if (filesize('application/storage/logs/'.$filename) > 1048576) {                    
                    rename("application/storage/logs/$filename", "application/storage/logs/".$filename."_". date("m-d-Y-H-i-s") . ".php");
                }
            }
            $fp = fopen('application/storage/logs/'.$filename, 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }
    
}
?>
