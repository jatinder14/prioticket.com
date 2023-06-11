<?php
use Aws\Sns\SnsClient;
use Aws\Credentials\CredentialProvider;
class Sns {
    function __construct() {
        
    }
    
    function publish($message, $topic_arn = VENUE_TOPIC_ARN) {     
        if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $provider = CredentialProvider::defaultProvider();
        } else {   
            $provider = array(
                'key'     => AWS_ACCESS_KEY,
                'secret'  => AWS_SECRET_KEY,
            );
        }
        $client = SnsClient::factory(array(
            'credentials' => $provider,
            'region'  => 'eu-west-1',
            'version' => 'latest'
        ));
        $result = $client->publish(array(
            'TopicArn' => $topic_arn,
            'Message'  => $message,
        ));
    }
}

?>