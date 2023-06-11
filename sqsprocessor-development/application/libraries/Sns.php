<?php
use Aws\Sns\SnsClient;
class Sns {
    function __construct() {
        
    }
    
    function publish($message, $topic_arn = TOPIC_ARN) {
        $client = SnsClient::factory(array(
            'credentials' => array(
                'key' => AWS_ACCESS_KEY,
                'secret' => AWS_SECRET_KEY,
            ),
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
        $result = $client->publish(array(
            'TopicArn' => $topic_arn,
            'Message' => $message,

        ));
        return $result;
    }
}

?>