<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * product Sorting Updation in pos ticket Class
 * 
 * @access   public
 * @package  Controller
 * @category Controller
 * @author   Krishna Chaturvedi <krishnachaturvedi.aipl@gmail.com> on 15 Sept, 2021
 */

class Product_sorting_pos extends MY_Controller
{
    /* #region  BOC of class Pos */
    var $base_url;

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct()
    {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('Product_sorting_model');
        $this->load->library('log_library');
        $this->base_url = $this->config->config['base_url'];
    }
    /* #endregion main function to load controller pos*/

    function update_product_sorting_order()
    {
        try {
            $this->common_log_start('sqs_processor_save_sorting', 'Pos');
            $gray_logs = array();
            $deleteMessage = true;
            if (
                $_SERVER['HTTP_HOST'] != 'localhost' &&
                $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.10.10.') &&
                $_SERVER['HTTP_HOST'] != strstr($_SERVER['HTTP_HOST'], '10.8.0.')
            ) {

                include_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $queue_url = SORT_UPDATION_QUEUE_URL;
                $messages = $sqs_object->receiveMessage($queue_url);
                $messages = $messages->getPath('Messages');
            } else {
                $postBody = file_get_contents('php://input');
                $this->CreateLog('update_product_sorting_order.txt', 'step 1', array("params" => $postBody));
                $messages = array(array("Body" => $postBody));
                $deleteMessage = false;
            }
            if (!empty($messages)) {

                 foreach ($messages as $message) {

                    $string         = $message['Body'];
                    $string         = gzuncompress(base64_decode($string));
                    $string         = utf8_decode($string);
                    $data           = json_decode($string, true);
                    $updateArray        = array();
                    $distributorId      = $data['distributor_id'];
                    $ticket_ids         = $data['ticket_ids'];
                    $deleted_order      = (isset($data['DELETE_ORDER']) && $data['DELETE_ORDER'] == '1' ? true : false);
                    $sortingOrder       = array_flip($ticket_ids);

                    $this->primarydb->db->select('hotel_id, mec_id, pos_ticket_id')->from('pos_tickets')->where(array("hotel_id" => $distributorId))->where_in('mec_id', $ticket_ids);
                    $results = $this->primarydb->db->get()->result_array();
                    $gray_logs['fetching_from_pos_tickets'] = $this->primarydb->db->last_query();
                    if (!empty($results)) {

                        foreach ( $results as $val ) {

                            if ( isset( $sortingOrder[$val['mec_id']] ) && in_array( $data['type'], array(1, 2, 3, 4) ) ) {

                                $all_tickets_sorting_data[$val['pos_ticket_id']] = $val['mec_id'];
                            }
                        }
                        /*Deleted=2 is a case for reset to previous sort */
                        if($data['delete'] == 2 && isset($data['previous_sort'])) {

                            $flip_values    = array_flip($all_tickets_sorting_data);
                            $previous_sort_keys = array_keys($data['previous_sort']);
                            foreach($flip_values as $key=>$val)
                            {
                                $sort = array_search($key, $previous_sort_keys);
                                $sort++;
                                $updateArray[] = array("pos_ticket_id" => $val, "sorting_order" => $sort);
                            }
                        } 
                        /*Deleted=1 is a case for reset to default sort */
                        elseif($data['delete'] == 1 && !empty( $all_tickets_sorting_data ))
                        {
                            $sorting_data = $this->fetch_sort_data($data, $all_tickets_sorting_data, $data['type']); 
                            foreach( $sorting_data as $pos_id => $order ) {
                                $updateArray[] = array("pos_ticket_id" => $pos_id, "sorting_order" => $order);
                            }
                        }
                        /*Deleted=0 is a case when a new sorting is added */
                        elseif($data['delete']==0)
                        {
                            foreach($results as $val)
                            {
                                $sort = array_search($val['mec_id'], $data['ticket_ids']);
                                $sort++;
                                $updateArray[] = array("pos_ticket_id" => $val['pos_ticket_id'], "sorting_order" => $sort);
                            }
                        }
                    }
                    if (!empty($updateArray)) {

                        $this->db->update_batch('pos_tickets', $updateArray, 'pos_ticket_id');
                        $gray_logs['updatebatch_pos_tickets'] = $this->db->last_query();
                    }
                    if ($deleteMessage === true) {

                        $sqs_object->deleteMessage($queue_url, $message['ReceiptHandle']);
                    }
                }
                $this->common_log_end($gray_logs, '');
            }
        } catch (Exception $e) {
            $this->common_log_end($gray_logs, '', $e);
        }
    }
    

/* #region  To fetch the sorting order in case of reset to default sorting*/
    /** 
     *
     * @called_from pos/update_from_product_sorting 
     *
     * @author Krishna Chaturvedi <krishnachaturvedi.aipl@gmail.com> on Sept 1, 2021
	*/

    public function fetch_sort_data($data, $value, $check)
    {   
       
        if(!empty($data['sub_distributor_id']) && ($data['catalog_type']==2)){
            $check=4;
        }
        //No fetching from type 3 in any case
        if ($check == 3) {
            $check = 2;
        }
        //To fetch from type 3
        if ($check == 4) {
            $sortOrder = $this->Product_sorting_model->delete_fetch_sort($data['sort_catalog_id'], 3, $value);
            if(!empty($sortOrder))
            {         
                $flip_values = array_flip($value);
                foreach( $sortOrder as $valSort ) {
                    $tickets_sort[$flip_values[$valSort['product_id']]]=$valSort['sort_order'];
                }
                return $tickets_sort;
            }
            if (empty($sortOrder)) 
            { 
                    $check = $check - 2;
            }       
        }
        //To fetch from type 1
        if ($check == 2) {
            $sortOrder =$this->Product_sorting_model->delete_fetch_sort($data['channel_id'], 1, $value);
            $flip_values = array_flip($value);
            if(!empty($sortOrder))
            {   
                foreach( $sortOrder as $valSort ) {
                    $tickets_sort[$flip_values[$valSort['product_id']]]=$valSort['sort_order'];
                }
                return $tickets_sort;
            }
            else
            {
                foreach( $flip_values as $valSort ) 
                {
                    $tickets_sort[$valSort] = 0;
                }    
                return $tickets_sort;
            }
        }
        //For the case of type 1 where sorting needs to be 0
        if ($check == 1)
        {
            $flip_values = array_flip($value);
            foreach( $flip_values as $valSort ) {
                $tickets_sort[$valSort] = 0;
            }        
            return $tickets_sort;
        }
    }
    /* #END region  To fetch the sorting order in case of reset to default sorting*/
}
