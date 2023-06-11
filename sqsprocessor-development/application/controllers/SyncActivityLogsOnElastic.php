<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

use Elasticsearch\ClientBuilder;
require_once '././././elasticsearch/vendor/autoload.php';

class SyncActivityLogsOnElastic extends MY_Controller {

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
    }

    function syncLogData() {
        if ( SERVER_ENVIRONMENT == 'Local') {
            $messagesBody = file_get_contents('php://input');
            $this->CreateLog('Sync_logs_on_elastic.php', 'step 1', array("params" => $postBody));
            $messages[] = array("Body" => $messagesBody );
        } else {
            include_once 'vendor/autoload.php';
            $postBody = file_get_contents('php://input');
            $this->CreateLog('Sync_logs_on_elastic.php', 'step 1', array("params" => $postBody));
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqsObject = new Sqs();
            $queueUrl = SYNC_ACTIVITY_LOGS_URL;
            $messages = $sqsObject->receiveMessage($queueUrl);
            $messages = $messages->getPath('Messages');
        }
        
        $hosts = [
            'host' => $this->config->config['elastic_search_url'],
        ];

        $clientBuilder = ClientBuilder::create();
        $clientBuilder->setHosts($hosts);
        $client = $clientBuilder->build();
        $params = array();

        if ($messages) {
            foreach ($messages as $message) {
                $string = $message['Body'];
                $string = json_decode( $string, true );
                $string = gzuncompress(base64_decode($string['data']));
                $string = utf8_decode($string);
                $string = str_replace("?", "", $string);
                $this->CreateLog('Sync_logs_on_elastic.php', 'step 2', array('Message Data' => $string ));
                $mainRequest = json_decode($string, true);
                if( !empty($message['ReceiptHandle']) ) {
                    $sqsObject->deleteMessage($queueUrl, $message['ReceiptHandle']);
                }
                $data = $mainRequest;
                if (isset($data['batch_insert'])) {

                    foreach($data['batch_insert'] as $rowArray) {
                        $params['body'][] = [
                            'index' => [
                                '_index' => ELASTIC_ACTIVITY_LOGS_NODE,
                            ]
                        ];
                        $params['body'][] = $rowArray;
                    }

                    $responses = $client->bulk($params);
                    $this->CreateLog('Sync_logs_on_elastic.php', 'step 3', array('Responses' => json_encode($responses)));

                } else {
                    $params = [
                        'index' => ELASTIC_ACTIVITY_LOGS_NODE,
                        'body'  => $data
                    ];
                    $response = $client->index($params);
                    $this->CreateLog('Sync_logs_on_elastic.php', 'step 3', array('Response' => json_encode($response)));
                }
            }
        }
    }
}
