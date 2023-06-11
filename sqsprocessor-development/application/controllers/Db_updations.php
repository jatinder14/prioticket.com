<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Db_updations extends MY_Controller {

    var $base_url;

    function __construct() {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->library('log_library');
        $this->load->library('trans');
        $this->load->model('realtime_sync_model');
    }

    /**
     * @name    : update_actions()     
     * @Purpose : To update prepaid and visitor updation from time of reddem action
     * @params  : No parameter required
     * @return  : nothing
     * @created by: Taranjeet singh <taran.intersoft@gmail.com> on July, 2018
     */
    function update_db_queries() {
        if (STOP_UPDATE_QUEUE) {
            exit();
        }
        /* Load SQS library. */
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        $queueUrl = TEST_DB_AMENDMENT_QUEUE;
        $messages = array();
        if (SERVER_ENVIRONMENT == 'Local') {
            $postBody = file_get_contents('php://input');
            $messages = array(
                'Body' => $postBody
            );
        } else {
            $messages = $sqs_object->receiveMessage($queueUrl);
            $messages = $messages->getPath('Messages');
        }
        
        $this->CreateLog('db_amendment_queries.php', "message", array('data' => json_encode($messages)));
        if (!empty($messages)) {
            foreach ($messages as $message) {
                if (SERVER_ENVIRONMENT != 'Local') {
                    /* BOC It remove message from SQS queue for next entry. */
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                    /* EOC It remove message from SQS queue for next entry. */
                    $string = $message['Body'];
                } else {
                    $string = $message;
                }
                /* BOC extract and convert data in array from queue */
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $queue_data = json_decode($string, true);
                /* EOC Get extract and convert data in array from queue */
                /* BOC If DB! query exist in array then execute in Primary DB */
                if (isset($queue_data['db1']) && !empty($queue_data['db1'])) {
                    $multiQuery = $queue_data['db1'];
                    foreach($multiQuery as $query)
                    {
                       $this->runQuery($queue_data, $this->db , $query);
                    }
                    $this->CreateLog('pilot_phpmyadmin_query', "db1", array(json_encode($multiQuery)) , 10);
                }
                /* EOC If DB1 queries exist in array then execute in Primary DB */
                
                /* BOC If DB2 query exist in array then execute in Secondary DB */
                if (isset($queue_data['db2']) && !empty($queue_data['db2'])) {
                    
                    $multiQuery = $queue_data['db2'];
                    foreach($multiQuery as $query)
                    {
                        $this->runQuery($queue_data, $this->secondarydb->db , $query);
                    }
                    $this->CreateLog('pilot_phpmyadmin_query', "db2", array(json_encode($multiQuery)), 10);
                }
                /* EOC If DB2 queries exist in array then execute in Secondary DB */

                 /* BOC If RDS query exist in array then execute in RDS */
                 if (isset($queue_data['rds']) && !empty($queue_data['rds'])) {
                   
                    $multiQuery = $queue_data['rds'];
                    foreach($multiQuery as $query)
                    {
                        $this->runQuery($queue_data, $this->fourthdb->db , $query);
                    }
                    $this->CreateLog('pilot_phpmyadmin_query', "rds", array(json_encode($multiQuery)), 10);
                }
                /* EOC If RDS queries exist in array then execute in RDS */

                 /* BOC If DB2 + RDS query exist in array then execute in Secondary DB + RDS */
                 if (isset($queue_data['db2RDS']) && !empty($queue_data['db2RDS'])) {
                    
                    $multiQuery = $queue_data['db2RDS'];
                    foreach($multiQuery as $query)
                    {
                        $this->runQuery($queue_data, $this->secondarydb->db , $query);
                        $this->runQuery($queue_data, $this->fourthdb->db , $query);
                    }
                    $this->CreateLog('pilot_phpmyadmin_query', "db2RDS", array(json_encode($multiQuery)), 10);
                }
                /* EOC If DB2 + RDS query exist in array then execute in Secondary DB + RDS */


                if(isset($queue_data['visitorGroupNo']) && !empty($queue_data['visitorGroupNo'])) 
                {
                    $visitorGroupNo = explode(',',$queue_data['visitorGroupNo']);
                    foreach($visitorGroupNo as $value)
                    {
                        // For Bigquery sync
                        $this->realtime_sync_model->sync_data_to_bigquery(['visitor_group_no'=>trim($value)]); 
                    }
                    $this->CreateLog('pilot_phpmyadmin_query', "visitorGroupNo", array(json_encode($queue_data['visitorGroupNo'])), 10);
                }


                if(isset($queue_data['elasticData']) && !empty($queue_data['elasticData']))
                {
                    $this->insertPilotDataOnElastic([],[],$queue_data['elasticData']);
                }
            }
        }
    }

    function runQuery($queue_data , $db , $query)
    {
        $time_start = microtime(true);
        $affectedRows = 0;
        $error = '';
        $status = 0;
        if($db->query($query))
        {
            //store the affected_row value here
            $affectedRows=$db->affected_rows();
            $status = 1;
        }else{
            
            $error = $db->error();
            $error = is_array($error) ? json_encode($error) : $error;
            $status = 2;
        } 
        $execution_time = microtime(true)-$time_start;
        $this->insertPilotDataOnElastic($queue_data,['query'=>$query,'affected_row'=>$affectedRows,'execution_time'=>$execution_time,'status'=>$status,'error'=>$error]);
        
    }

    
    function insertPilotDataOnElastic($queue_data , $dataArray, $apiData = [])
    {
        // API URL
        $apiURL = PILOT_ELASTIC_URL.PILOT_ELASTIC_NODE;

        if(empty($apiData))
        {
            // API Data
            $apiData = [
                'email' => !empty($queue_data['email']) ? $queue_data['email'] : '',
                'groupno' => !empty($queue_data['groupno']) ? $queue_data['groupno'] : 0 ,
                'database' => !empty($queue_data['database']) ? $queue_data['database'] : '',
                'operation' => !empty($queue_data['operation']) ? $queue_data['operation'] : '',
                'query' => !empty($dataArray['query']) ? $dataArray['query'] : '',
                'vgn' => !empty($dataArray['visitorGroupNo']) ? $dataArray['visitorGroupNo'] : '',
                'affected_row' => !empty($dataArray['affected_row']) ? $dataArray['affected_row'] : 0,
                'execution_time' => !empty($dataArray['execution_time']) ? $dataArray['execution_time'] : 0,
                'status' => !empty($dataArray['status']) ? $dataArray['status'] : 0,
                'error_message' => !empty($dataArray['error']) ? $dataArray['error'] : '',
                'create_date_time' => gmdate("Y-m-d").'T'.gmdate("H:i:s").'Z',
            ];
        }

        // API Header
        $headers = array('Content-Type: application/json');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
		curl_exec($ch);
        curl_close($ch);
    }

    

}
