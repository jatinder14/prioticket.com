<?php 
# Includes the autoloader for libraries installed with composer
require __DIR__ . '/gpubsub/vendor/autoload.php';

use Google\Cloud\PubSub\PubSubClient;
class Gpubsub{
    private $config = array();
    private $projectId = BigqueryConstants::PROJECTID; // Google cloud Project ID
    
    function __construct(){
        $this->config = [
            'projectId' => $this->projectId,
            'keyFilePath' => BigqueryConstants::GOOGLE_CLOUD_BIGQUERY_KEY_FILE,
        ];
    }
    /**
     * Publishes a message for a Pub/Sub topic.
     *
     * @param string $projectId  The Google project ID.
     * @param string $topicName  The Pub/Sub topic name.
     * @param string $message  The message to publish.
     */
    function publish_message($topicName, $message)
    {
        $pubsub = new PubSubClient($this->config);
        $topic = $pubsub->topic($topicName);
        $topic->publish(['data' => $message]);
        //print('Message published' . PHP_EOL);
    }
    
    /**
     * Pulls all Pub/Sub messages for a subscription.
     *
     * @param string $projectId  The Google project ID.
     * @param string $subscriptionName  The Pub/Sub subscription name.
     */
    function pull_messages($subscriptionName)
    {
        $pubsub = new PubSubClient($this->config);
        return $subscription = $pubsub->subscription($subscriptionName);
        
    }
}
?>