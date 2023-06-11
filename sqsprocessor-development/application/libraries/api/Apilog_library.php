<?php
# Class is used for file logging and graylog logging 
# Author: Prabhdeep Kaur <prabhdeep.intersoft@gmail.com>

final class Apilog_library {

    private $log_file_path = "application/storage/logs/api_logs/";
    private $log_file_name = '';
    private $log_setting = '';

    public function __construct() {
        $this->log_file_name = "log-" . date('Y-m-d');
        $this->log_setting = json_decode(API_LOG_SETTING, true);
    }

    /**
     * @Name : set_filelog()
     * @Purpose : To Create logs in files
     * Created by : Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> 16 March / 2020
     */
    public function set_filelog($filelog_contents = [], $is_encode = 1) {
        /* check if file logging is enabled */
        if (!empty($this->log_setting['file_logging'] && $filelog_contents)) {
             /* Get Ip Address */
            $ipAddress = (!empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']));
            $hostname = gethostname();
            $file_log_data = [];
            foreach ($filelog_contents as $filelog) {
                $filelog['error_reference_no'] = !empty($filelog['error_reference_no']) ? $filelog['error_reference_no'] : ERROR_REFERENCE_NUMBER;
                /* Create log directory if not exist */
                $filelog['log_dir'] = !empty($filelog['log_dir']) ? $filelog['log_dir'] : "";
                $filelog['log_filename'] = !empty($filelog['log_filename']) ? $filelog['log_filename'] : $this->log_file_name;
                if (empty($filelog['request_time'])) {
                    $filelog['request_time'] = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000).':'.gettimeofday()["usec"];
                }
                $filelog['data'] = !empty($filelog['data']) ? $filelog['data'] : "";
                if(empty($filelog['encode']) && $is_encode == 1){
                    $filelog['data'] = base64_encode(gzcompress(json_encode($filelog['data'])));
                }
                $filelog['ip_address'] = $ipAddress;
                $filelog['hostname'] = $hostname;
                $filelog['is_encode'] = $is_encode;
                $file_log_data[] = $filelog;
            }
            if (!empty($file_log_data)) {
                if (empty($this->CI)) {
                    $this->CI = & get_instance();
                }
                try {
                    $aws_message = base64_encode(gzcompress(json_encode($file_log_data)));
                    if (SERVER_ENVIRONMENT != "Local" && 1 == 0) {
                        $queueUrl = API_GRAYLOG_LOG_QUEUE;
                        include_once 'aws-php-sdk/aws-autoloader.php';
                        $this->CI->load->library('Sqs');
                        $sqs_object = new Sqs();
                        $message_id = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($message_id != false) {
                            $this->CI->load->library('Sns');
                            $sns_object = new Sns();
                            $sns_object->publish($queueUrl, API_GRAYLOG_LOG_ARN);
                        }
                    } else {
                        $this->CI->load->helper('local_queue');
                        local_queue_handler($aws_message, "API_GRAYLOG_LOG_ARN");
                    }
                } catch (Exception $e) {
                }
            }
        }
    }
    
    /**
     * function to traverse with complete path and make folder if not exist
     * @param string $dirName (path)
     * @param  $rights
     * Created by : Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> 16 March / 2020
     */
    public function mkdir_r($dirName, $rights = 0777) {
        $dirs = explode('/', $dirName);
        $dir = '';
        foreach ($dirs as $part) {
            $dir .= $part . '/';
            if (!is_dir($dir) && strlen($dir) > 0) {
                mkdir($dir, $rights);
            }
        }
    }

    /**
     * Function to set_graylog on server
     * @param type $graylogscontent = []
     * $is_encode : default value 1.Which is sued to compress code.IF its value 0 then we will save data at graylog withot compress
     * Created by : Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> 16 March / 2020
     */
    public function set_graylog($graylogscontent = [], $is_encode = 1) {
        global $api_global_constant_values;
        if ((!empty($this->log_setting['gray_logging'] && $graylogscontent)) && (SERVER_ENVIRONMENT != 'Local' || (SERVER_ENVIRONMENT == 'Local' && $_SERVER['SERVER_NAME'] == '10.10.10.20'))) {
            /* Get Ip Address */
            $ipAddress = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);
            $date = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000).':'.gettimeofday()["usec"];
            $graylog_data = [];
            $loop = $strlen = 0;
            foreach ($graylogscontent as $graylogs) {
                $api = !empty($graylogs['api_name']) ? $graylogs['api_name'] : 'Cron API';
                $title = !empty($graylogs['title']) ? $graylogs['title'] : 'OUTPUT';
                /* Prepare data to send  on graylog server */
                $full_message = !empty($graylogs['data']) ? $graylogs['data'] : (ucfirst($api) . ': ' . $title);
                if(empty($graylogs['encode']) && $is_encode == 1){
                    $full_message = base64_encode(gzcompress(json_encode($full_message)));
                }
                $graylog_data[$loop] = [
                    'source_name' => !empty($graylogs['log_dir']) ? $graylogs['log_dir'] : '',
                    'source_ip' => $ipAddress,
                    'request_reference' => !empty($graylogs['error_reference_no']) ? $graylogs['error_reference_no'] : ERROR_REFERENCE_NUMBER,
                    'short_message' => ucfirst($api) . '_' . $title,
                    'full_message' => $full_message,
                    'server' => $_SERVER['HTTP_HOST'],
                    'host_name' => gethostname(),
                    'created_datetime' => !empty($graylogs['request_time']) ? $graylogs['request_time'] : $date,
                    'http_status' => !empty($graylogs['http_status']) ? $graylogs['http_status'] : 200,
                    'processing_time' => !empty($graylogs['processing_time']) ? ($graylogs['processing_time'] *1000) : 0,
                    'internal_processing_time' => (isset($graylogs['internal_processing_time']) && !empty($graylogs['internal_processing_time'])) ? ($graylogs['internal_processing_time'] *1000) : 0,
                    'external_processing_time' => (isset($graylogs['external_processing_time']) && !empty($graylogs['external_processing_time'])) ? ($graylogs['external_processing_time'] *1000) : 0,
                    'http_request' => !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_SCHEME'] ."://". $_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'] : "",
                    'http_method' => !empty($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : "",
                    'error_code' => !empty($graylogs['error']) ? $graylogs['error'] : "",
                    'header_data' => !empty($graylogs['header_data']) ? $graylogs['header_data'] : ""
                ];
                $strlen += strlen(base64_encode(gzcompress(json_encode($graylog_data[$loop]))));
                $graylog_data[$loop]['short_message'] =  $graylog_data[$loop]['short_message'];
                $loop++;
            }
            if (empty($this->CI)) {
                $this->CI = & get_instance();
            }
            try {
                    $aws_message = base64_encode(gzcompress(json_encode($graylog_data)));
                    $queueUrl = API_GRAYLOG_LOG_QUEUE;
                    include_once 'aws-php-sdk/aws-autoloader.php';
                    $this->CI->load->library('Sqs');
                    $sqs_object = new Sqs();
                    $messageid = $sqs_object->sendMessage($queueUrl, $aws_message);
                    if ($messageid != false) {
                        $this->CI->load->library('Sns');
                        $sns_object = new Sns();
                        $sns_object->publish($queueUrl, API_GRAYLOG_LOG_ARN);
                    }
                } catch (Exception $e) {

            }
            
        }
    }
     /**
     * @Name : create_filelog()
     * @Purpose : To Create logs in files
     * Created by : Prabhdeep Kaur <prabhdeep.intersoft@gmail.com> 17 March / 2020
     */
    public function create_filelog($filelog_contents = []) {
        /* check if file logging is enabled */
        if (!empty($this->log_setting['file_logging'] && $filelog_contents)) {
            if (!is_dir($this->log_file_path)) {
                $this->mkdir_r($this->log_file_path);
            }
            foreach ($filelog_contents as $filelog) {
                $filelog['error_reference_no'] = !empty($filelog['error_reference_no']) ? $filelog['error_reference_no'] : ERROR_REFERENCE_NUMBER;
                /* Create log directory if not exist */
                $filelog['log_dir'] = !empty($filelog['log_dir']) ? $filelog['log_dir'] : "";
                $filelog['log_filename'] = !empty($filelog['log_filename']) ? $filelog['log_filename'] : $this->log_file_name;
                if (strlen($filelog['log_dir']) && !is_dir($this->log_file_path . $filelog['log_dir'])) {
                    $this->mkdir_r($this->log_file_path . $filelog['log_dir']);
                }
                if (!empty($filelog['hostname']) && !empty($filelog['log_filename'])) {
                    $filelog['log_filename'] = str_replace('.php', '', $filelog['log_filename']) . '_' . str_replace('.', '_', $filelog['hostname']);
                }
                $filepath = $this->log_file_path . (strlen($filelog['log_dir']) ? $filelog['log_dir'] . '/' : '') . $filelog['log_filename'] . ".log";
                $define = "";
                $log_data = "";
                if (!file_exists($filepath)) {
                    $define = "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>\n";
                } elseif (is_file($filepath)) {
                    if (filesize($filepath) > 1048576) {
                        rename($filepath, $filepath . "_" . date("m-d-Y-H-i-s") . ".log");
                    }
                }
                if (empty($filelog['request_time'])) {
                    $filelog['request_time'] = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000).':'.gettimeofday()["usec"];
                }
                /* Added by PM */
                $start_reference = '';
                $end_reference = '';
                if ($filelog['error_reference_no']) {
                    $start_reference = 'Start' . $filelog['error_reference_no'] . PHP_EOL;
                    $end_reference = 'End' . $filelog['error_reference_no'] . PHP_EOL . "\n";
                }
                $filelog['data'] = !empty($filelog['data']) ? $filelog['data'] : "";
                $title = !empty($filelog['title']) ? $filelog['title'] : 'OUTPUT';
                $api = !empty($filelog['api_name']) ? $filelog['api_name'] : 'Cron API';
                if(isset($filelog['ip_address'])){
                    $logAddress = "IP_Address : " . (isset($filelog['ip_address']) ? $filelog['ip_address'] : '') . "\n";
                }
                $log_data = $define . $start_reference . $logAddress . ucfirst($api) . '_' . $title . ' : ' . $filelog['request_time'] . PHP_EOL . $filelog['data'] . PHP_EOL;
                /* Added by PM */
                $log_data = $log_data . $end_reference;
                $fp = fopen($filepath, 'a');
                fwrite($fp, $log_data);
                fclose($fp);
               
            }
        } else {
            echo "Came";
        }
       
    }
}
?>
