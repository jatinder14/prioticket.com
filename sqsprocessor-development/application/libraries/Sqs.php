<?php
use Aws\Sqs\SqsClient;
class Sqs {
    function __construct() {
        
    }
    
    function sendMessage($queueUrl, $aws_message) {
        $client = SqsClient::factory(array(
            'credentials' => array(
                'key'     => AWS_ACCESS_KEY,
                'secret'  => AWS_SECRET_KEY,
            ),
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));

        $message_result = $client->sendMessage(array(
            'QueueUrl'    => $queueUrl,
            'MessageBody' => $aws_message,
        ));
        
        if($message_result->getPath('MessageId') != '') {
            return $message_result->getPath('MessageId');
        } else {
            return false;
        }
        
    }
    
    function receiveMessage($queueUrl) {
        if(in_array($queueUrl, [SCANING_ACTION_URL, OTA_NOTIFICATION_PROCESS])) {
            $wait_time_seconds = 1;
        } else {
            $wait_time_seconds = 4;
        }
        $client = SqsClient::factory(array(
            'credentials' => array(
                'key'     => AWS_ACCESS_KEY,
                'secret'  => AWS_SECRET_KEY,
            ),
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
        
        $messages = $client->receiveMessage(array(
            'QueueUrl'            => $queueUrl,
            'MaxNumberOfMessages' => '10',
            'WaitTimeSeconds'     => $wait_time_seconds
        ));
        return $messages;
    }
    
    function deleteMessage($queueUrl, $ReceiptHandle) {
        $client = SqsClient::factory(array(
            'credentials' => array(
                'key' => AWS_ACCESS_KEY,
                'secret' => AWS_SECRET_KEY,
            ),
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
        
        $returnMsg = $client->deleteMessage(array(
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $ReceiptHandle
        ));
        return $returnMsg;
    }

    function sendMessageBatch($queueUrl, $aws_message) {
        $client = SqsClient::factory(array(
            'credentials' => array(
                'key'     => AWS_ACCESS_KEY,
                'secret'  => AWS_SECRET_KEY,
            ),
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));

        $message_result = $client->sendMessageBatch(array(
            'QueueUrl'    => $queueUrl,
            'Entries' => $aws_message,
        ));
        
        if($message_result->Successful != '') {
            return true;
        } else {
            return false;
        }
        
    }
}

?>