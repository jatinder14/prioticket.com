<?php
use Aws\Sqs\SqsClient;
use Aws\Credentials\CredentialProvider;
class Sqs {
    
    private $log_file_path = "system/application/storage/logs/api_logs/";
    private $log_file_name = "log-";
    private $save_logs = '';
    
    function __construct() {
        $this->log_file_name = " log-" . date('Y-m-d');
        $this->api_log_setting = json_decode(API_LOG_SETTING, true);
        $this->save_logs = 1;
    }
    
    function sendMessage($queueUrl, $aws_message) {
        $start_time = microtime(true);
        if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $provider = CredentialProvider::defaultProvider();
        } else {   
            $provider = array(
                'key'     => AWS_ACCESS_KEY,
                'secret'  => AWS_SECRET_KEY,
            );
        }
        $client = SqsClient::factory(array(
            'credentials' => $provider,
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
         if(strpos($queueUrl, '.fifo') != false){
            $message_result = $client->sendMessage(array(
                'QueueUrl'    => $queueUrl,
                'MessageDeduplicationId' => md5($aws_message),
                'MessageBody' => $aws_message,
                'MessageGroupId' => $start_time,
                'DelaySeconds'  => 0
            ));
        } else {
            $message_result = $client->sendMessage(array(
                'QueueUrl'    => $queueUrl,
                'MessageBody' => $aws_message,
            ));
        }
        if($this->save_logs == 1) {
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((int)$processing_time) / 1000;
            if($processing_times > 1) {
                $sqs_request_id = isset($message_result["@metadata"]["headers"]["x-amzn-requestid"]) ? $message_result["@metadata"]["headers"]["x-amzn-requestid"] : '';
                $this->setsqs_file_log('queue_check_status', 'SQS_Check_Status.php', 'Send Request Response', json_encode(array("start_time" => $start_time, "queueUrl" => $queueUrl, "x-amzn-requestid" => $sqs_request_id, "messageId" => !empty($message_result->getPath('MessageId')) ? $message_result->getPath('MessageId') : '', "end_time"=> $end_time, "processing_times" => $processing_times)));
            }
        }
        if($message_result->getPath('MessageId') != '') {
            return $message_result->getPath('MessageId');
        } else {
            return false;
        }
        
    }
    
    function receiveMessage($queueUrl) {
        if (in_array($queueUrl, [OTA_NOTIFICATION_PROCESS])) {
            $wait_time_seconds = 1;
        } else {
            $wait_time_seconds = 5;
        }
        $start_time = microtime(true);
        if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $provider = CredentialProvider::defaultProvider();
        } else{
            $provider = array(
                'key' => AWS_ACCESS_KEY,
                'secret' => AWS_SECRET_KEY,
            );
        }
        $client = SqsClient::factory(array(
            'credentials' => $provider,
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));

        $messages = $client->receiveMessage(array(
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => '10',
            'WaitTimeSeconds' => $wait_time_seconds
        ));
        
        if($this->save_logs == 1) {
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((int)$processing_time) / 1000;
            if($processing_times > 1) {
                /* Get the All Message Ids */
                $sqs_message_id = array();
                if(isset($messages['Messages']) && !empty($messages['Messages'])) {
                    foreach($messages['Messages'] as $allMessages){
                        $sqs_message_id[] = isset($allMessages['MessageId']) ? $allMessages['MessageId'] : "" ;
                    }
                }
                $sqs_request_id = isset($messages["@metadata"]["headers"]["x-amzn-requestid"]) ? $messages["@metadata"]["headers"]["x-amzn-requestid"] : '';
                $this->setsqs_file_log('queue_check_status', 'SQS_Check_Status.php', 'Receive Request Response', json_encode(array("start_time" => $start_time, "queueUrl" => $queueUrl, "x-amzn-requestid" => $sqs_request_id, "messages" => $sqs_message_id, "end_time"=> $end_time, "processing_times" => $processing_times)));
            }
        }
        return $messages;
    }
    
    function deleteMessage($queueUrl, $ReceiptHandle) {
        $start_time = microtime(true);
        if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $provider = CredentialProvider::defaultProvider();
        } else{
            $provider = array(
                'key' => AWS_ACCESS_KEY,
                'secret' => AWS_SECRET_KEY,
            );
        }
        $client = SqsClient::factory(array(
            'credentials' => $provider,
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
        
        $message_result = $client->deleteMessage(array(
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $ReceiptHandle
        ));
        
        if($this->save_logs == 1) {
            $end_time = microtime(true);
            $processing_time = ($end_time - $start_time) * 1000;
            $processing_times = ((int)$processing_time) / 1000;
            if($processing_times > 1) {
                $sqs_request_id = isset($message_result["@metadata"]["headers"]["x-amzn-requestid"]) ? $message_result["@metadata"]["headers"]["x-amzn-requestid"] : '';
                $this->setsqs_file_log('queue_check_status', 'SQS_Check_Status.php', 'Receive Request Response', json_encode(array("start_time" => $start_time, "queueUrl" => $queueUrl, "ReceiptHandle" => $ReceiptHandle, "x-amzn-requestid" => $sqs_request_id, "end_time"=> $end_time, "processing_times" => $processing_times)));
            }
        }
    }
    
    
    /**
     * @Name : CreateLog()
     * @Purpose : To Create logs in files
     */
    public function setsqs_file_log($dir = "", $log_file_name = "log-", $title = "OUTPUT", $param = '', $api = 'Api', $reference_no = '') {
        if ($reference_no != '') {
            $error_reference_no = $reference_no;
        } else {
            $error_reference_no = ERROR_REFERENCE_NUMBER;
        }

        $log_file_name = !empty($log_file_name) ? $log_file_name : $this->log_file_name;

        /* Get Ip Address */
        $ipAddress = (!empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']));

        // check if file logging is enabled
        if ($this->api_log_setting['file_logging'] == 1) {

            /* CREATE LOG DIRECTORY IF IT DOESN'T EXIST */
            if (!@is_dir($this->log_file_path)) {
                @mkdir($this->log_file_path);
            }

            if (strlen($dir) && !@is_dir($this->log_file_path . $dir)) {
                $this->mkdir_r($this->log_file_path . $dir);
            }

            $hostname = gethostname();
            if (!empty($hostname) && !empty($log_file_name)) {
                $log_file_name = str_replace('.php', '', $log_file_name) . '_' . str_replace('.', '_', $hostname);
            }

            $filepath = $this->log_file_path . (strlen($dir) ? $dir . '/' : '' ) . $log_file_name . ".log";
            $define = "";
            $log_data = "";
            if (!file_exists($filepath)) {
                $define = "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>\n\n";
            } else if (is_file($filepath)) {
                if (filesize($filepath) > 1048576) {
                    rename($filepath, $filepath . "_" . date("m-d-Y-H-i-s") . ".log");
                }
            }

            $date = gmdate("Y-m-d H:i:s:") . round(gettimeofday()["usec"] / 1000).':'.gettimeofday()["usec"];

            // Added by PM
            $start_reference = '';
            $end_reference = '';
            if ($error_reference_no) {
                $start_reference = 'Start' . $error_reference_no . PHP_EOL;
                $end_reference = 'End' . $error_reference_no . PHP_EOL . "\n\n";
            }

            $logAddress = "IP_Address : " . (($ipAddress != '') ? $ipAddress : '') . "\n";

            $log_data = $define . $start_reference . $logAddress . ucfirst($api) . ': ' . $title . ' : ' . $date . PHP_EOL . $param . PHP_EOL;
            // Added by PM
            $log_data = $log_data . $end_reference;

            $fp = fopen($filepath, 'a');
            fwrite($fp, $log_data);
            fclose($fp);
        }
    }
    
    /**
     * function to traverse with complete path and make folder if not exist
     * @param string $dirName (path)
     * @param  $rights
     */
    public function mkdir_r($dirName, $rights = 0777) {
        $dirs = explode('/', $dirName);
        $dir = '';
        foreach ($dirs as $part) {
            $dir .= $part . '/';
            if (!@is_dir($dir) && strlen($dir) > 0)
                @mkdir($dir, $rights);
        }
    }
}
?>