<?php if (!defined('BASEPATH')) {exit('No direct script access allowed');}

class database_update extends MY_Controller
{
    /* #region  BOC of class Pos */
    public $base_url;

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        $this->load->library('Primarynewdb');
        $this->load->model('Primary_db_query_model');
    }
    /* #endregion main function to load controller pos*/

    /* #region getActionWiseOrder  */
    /**
     * getActionWiseOrder
     *
     * @return void
     */
    public function update_primary_db( $data='', $rabbitMQ=false )
    {
        $data =  $this->Primary_db_query_model->test();
        echo "<pre>";
        print_r($data->result_array()); 
        echo "</pre>";
        
        if( PRIMARYDB_TWO_QUERY_EXECUTION == 0 ) {
            exit;
        }
        
        if ( $rabbitMQ && !empty( $data ) ) {
            $messages[] = array("Body" => $data );
        }
        elseif (SERVER_ENVIRONMENT == 'Local') {
            $messagesBody = file_get_contents('php://input');
            $messages[] = array("Body" => $messagesBody );
        } 
        else if( $rabbitMQ === false ) {
            include_once 'aws-php-sdk/aws-autoloader.php';
            $queueUrl = PRIMARY_DB_QUERY_EXECUTION;
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            $messages = $sqs_object->receiveMessage($queueUrl, TEST_AWS_ACCESS_KEY, TEST_AWS_SECRET_KEY);
            $messages = $messages->getPath('Messages');
        }

        echo "<pre>messages: "; print_r($messages); echo "</pre>";
        $this->CreateLog('primarydb2_queries_received.txt', 'QUERY', array('data' => $messages)); 
        // It return Data from message.
        if (!empty($messages)) {

            foreach ($messages as $message) {
                
                $incoming_mes = json_decode(gzuncompress(base64_decode($message['Body'])));
                if( !empty( $message['ReceiptHandle'] ) ) {
                        
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle'], TEST_AWS_ACCESS_KEY, TEST_AWS_SECRET_KEY);
                }
                $this->CreateLog('primasrydb2_query.txt', 'all', array("query" => $message['Body']));
                $mess =  str_replace("= ,","= '',","$mess");
                foreach ($incoming_mes as $mess) {
                    $this->CreateLog('primasrydb2_query.txt', 'all', array("query" => $mess)); 
                    echo "<pre>mess: "; print_r($mess); echo "</pre>";
                    $this->Primary_db_query_model->insert_data($mess);
                }
                
            }
            
        }
}





    function run_exchange_sqs( $error_reporting=0 ) {
    
        if( $error_reporting == '1' ) {
            
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        if( Rabbitqueue::ENABLE === 1 ) {

            $this->load->library( 'rabbitmq' );
            $this->rabbitmq->get_from_exchange( 'sqs_processes', 'direct' );
        }
        die( json_encode( array( "success" => 'OK' ) ) );
    }
}
