<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

class Syncmissingrecords extends MY_Controller {

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
    }
    
    function syncRecords() {
        include_once 'vendor/autoload.php';
        $postBody = file_get_contents('php://input');
        //$this->CreateLog('Syncmissingrecords.php','Step 1',array('Data' => $postBody),'11');
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $queue_url = SYNC_RECORDS_QUEUE_URL;
        $messages = $sqs_object->receiveMessage($queue_url);
        $messages = $messages->getPath('Messages');
        $secondaryDb = $this->load->database('secondary', true);
        $rdsDb = $this->load->database('fourth', true);
        if ($messages) {
            foreach ($messages as $message) {
                $string = $message['Body'];
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                //$this->CreateLog('Syncmissingrecords.php','Step 2',array('String' => $string));
                $string = str_replace("?", "", $string);
                $main_request = json_decode($string, true);
                $sqs_object->deleteMessage($queue_url, $message['ReceiptHandle']);
                $insertQuery = $main_request['query'];
                //$this->CreateLog('Syncmissingrecords.php','Step 3',array('QUERY' => $insertQuery),'11');
                if (!$secondaryDb->query($insertQuery)) {
                    $error = $secondaryDb->error();
                    $this->CreateLog('Syncmissingrecords.php','Step 3',array('ERROR-SECONDARY' => $error, 'QUERY' => $insertQuery),'11');
                }
                if (!$rdsDb->query($insertQuery)) {
                    $error = $rdsDb->error();
                    $this->CreateLog('Syncmissingrecords.php','Step 4',array('ERROR-RDS' => $error, 'QUERY' => $insertQuery),'11');
                }
            }

        }
    }
}
