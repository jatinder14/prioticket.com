<?php 

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\local_queue_helper;

trait TMpos_model {

    function __construct() {

        /* Call the Model constructor */
        parent::__construct();
    }

    /* #region Order Process  Module  : This module covers order process api's  */

    /**
     * @name     : processManualPayment()
     * @Purpose  : used to insert manual payment record
     * @Called   : called from api controller
     * @created by: Supriya Saxena<supriya10.aipl@gmail.com> on 3rd september, 2019
     */
    function process_manual_payment($manual_payment_request) {
        global $MPOS_LOGS;
        global $internal_logs;
        $response = array();
        if (!empty($manual_payment_request)) {
            $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
            $hotel_details = $this->common_model->find('qr_codes', array('select' => '*', 'where' => 'cod_id =' . "'" . $manual_payment_request['hotel_id'] . "'"));
            $logs['qr_codes_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $visitor_group_no = $manual_payment_request['visitor_group_no'] ? $manual_payment_request['visitor_group_no'] : round(microtime(true) * 1000) . '' . rand(0, 9) . '' . rand(0, 9);
            $total_price = $manual_payment_request['manual_amount'];
            $ticket_tax_value = $manual_payment_request['tax_value'];
            $total_net_price = ($total_price * 100) / ($ticket_tax_value + 100);
            if (isset($manual_payment_request['payment_type']) && $manual_payment_request['payment_type'] != '') {
                if ($manual_payment_request['payment_type'] == '1') { // 1 - card , 2 - cash and 15 - cash out
                    $prepaid_type = "1";
                    $title = 'Manual Payment'; // card
                } else if ($manual_payment_request['payment_type'] == '15') {
                    $prepaid_type = "2";
                    $title = "Cash Deposit"; // cash out
                } else if ($manual_payment_request['payment_type'] == '2') {
                    $prepaid_type = "2";
                    $title = "Manual Payment"; // cash
                }
            } else {
                $prepaid_type = "2";
                $title = 'Manual Payment';
            }
            $user_detail = $this->common_model->find('users', array('select' => '*', 'where' => 'id =' . "'" . $manual_payment_request['cashier_id'] . "'"));
            $logs['users_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $cashier_name = $user_detail[0]['fname'] . ' ' . $user_detail[0]['lname'];
            $insertData = array();
            $count = 1;
            // array to insert in HTO 
            $parent_pass_number = 'shop' . time();
            $parent_pas = $this->common_model->find('hotel_ticket_overview', array('select' => 'parentPassNo', 'where' => 'visitor_group_no =' . "'" . $visitor_group_no . "'"));
            if (!empty($parent_pas[0]['parentPassNo'])) {
                $parent_pass_number = $parent_pas[0]['parentPassNo'];
                $real_pass = $parent_pass_number . 'shop';
            } else {
                $real_pass = $parent_pass_number;
            }
            $insertData['id'] = $visitor_group_no . str_pad($count, 3, "0", STR_PAD_LEFT);
            $insertData['quantity'] = '1';
            $insertData['total_price'] = $total_price;
            $insertData['total_net_price'] = $total_net_price;
            $insertData['createdOnByGuest'] = $insertData['updatedOn'] = $insertData['createdOn'] = strtotime($manual_payment_request['date']);
            $insertData['visitor_group_no'] = $visitor_group_no;
            $insertData['visitor_group_no_old'] = $visitor_group_no;
            $insertData['hotel_id'] = $manual_payment_request['hotel_id'];
            $insertData['hotel_name'] = $hotel_details[0]['company'];
            $insertData['channel_id'] = $manual_payment_request['channel_id'];
            $insertData['activation_type'] = '0';
            $insertData['isBillToHotel'] = '0';
            $insertData['company_name'] = '';
            $insertData['activation_method'] = $prepaid_type;
            $insertData['is_voucher'] = '0';
            $insertData['parentPassNo'] = $parent_pass_number;
            $insertData['passNo'] = $real_pass;
            $insertData['roomNo'] = '';
            $insertData['gender'] = 'Male';
            $insertData['amount'] = 0;
            $insertData['nights'] = 0;
            $insertData['updatedBy'] = $manual_payment_request['cashier_id'];
            $insertData['uid'] = $manual_payment_request['cashier_id'];
            $insertData['host_name'] = $cashier_name;
            $insertData['visitor_group_no'] = $visitor_group_no;
            $insertData['creditcard_group_no'] = 0;
            $insertData['timezone'] = $servicedata->timeZone;
            $insertData['hotel_checkout_status'] = '0';
            $insertData['paymentStatus'] = '1';
            $insertData['channel_type'] = $manual_payment_request['channel_type'];
            $insertData['user_age'] = 0;
            $insertData['is_prioticket'] = '0';
            $insertData['is_order_from_mobile_app'] = 0;
            $insertData['isprepaid'] = '1';
            $insertData['product_type'] = '1';
            if (isset($manual_payment_request['adyen_details']) && !empty($manual_payment_request['adyen_details']) && $manual_payment_request['payment_type'] == '1') {
                $insertData['pspReference'] = !empty($manual_payment_request['adyen_details']['pspReference']) ? $manual_payment_request['adyen_details']['pspReference'] : '';
                $insertData['merchantReference'] = !empty($manual_payment_request['adyen_details']['merchantReference']) ? $manual_payment_request['adyen_details']['merchantReference'] : '';
                $insertData['paymentMethod'] = !empty($manual_payment_request['adyen_details']['cardType']) ? $manual_payment_request['adyen_details']['cardType'] : '';
                $insertData['merchantAccountCode'] = !empty($manual_payment_request['adyen_details']['merchantAccount']) ? $manual_payment_request['adyen_details']['merchantAccount'] : '';
                $insertData['shopperReference'] = !empty($manual_payment_request['adyen_details']['shopperReference']) ? $manual_payment_request['adyen_details']['shopperReference'] : '';
                $insertData['shopperEmail'] = !empty($manual_payment_request['adyen_details']['shopperEmail']) ? $manual_payment_request['adyen_details']['shopperEmail'] : '';
            }
            $this->db->insert('hotel_ticket_overview', $insertData);

            $logs['hto_insertion_query_' . date('H:i:s')] = $this->db->last_query();
            $hotel_ticket_overview_data[] = $insertData;
            $internal_logs['hto_response'] = $insertData;
            // prepare array to insert in PT 
            $insert_pt_data = array();
            $insert_pt_data['visitor_group_no'] = $visitor_group_no;
            $insert_pt_data['title'] = $title;
            $insert_pt_data['total_price'] = $total_price;
            $insert_pt_data['ticket_tax_value'] = $ticket_tax_value;
            $insert_pt_data['tax_name'] = $manual_payment_request['tax_name'];
            $insert_pt_data['quantity'] = '1';
            $insert_pt_data['cashier_id'] = $manual_payment_request['cashier_id'];
            $insert_pt_data['cashier_name'] = $cashier_name;
            $insert_pt_data['cashier_code'] = $manual_payment_request['cashier_code'] . '~_~' . $manual_payment_request['location_code'];
            $insert_pt_data['timezone'] = $servicedata->timeZone;
            $insert_pt_data['prepaid_type'] = $prepaid_type;
            $insert_pt_data['date'] = $manual_payment_request['date'];
            $insert_pt_data['hotel_id'] = $manual_payment_request['hotel_id'];
            $insert_pt_data['manual_note'] = $manual_payment_request['manual_note'];
            $insert_pt_data['pos_point_id'] = $manual_payment_request['pos_point_id'];
            $insert_pt_data['pos_point_name'] = $manual_payment_request['pos_point_name'];
            $insert_pt_data['channel_id'] = $manual_payment_request['channel_id'];
            $insert_pt_data['channel_name'] = $manual_payment_request['channel_name'];
            $insert_pt_data['reseller_id'] = $hotel_details[0]['reseller_id'];
            $insert_pt_data['reseller_name'] = $hotel_details[0]['reseller_name'];
            $insert_pt_data['saledesk_id'] = $hotel_details[0]['saledesk_id'];
            $insert_pt_data['saledesk_name'] = $hotel_details[0]['saledesk_name'];
            $insert_pt_data['channel_type'] = $manual_payment_request['channel_type'];
            if (isset($manual_payment_request['adyen_details']) && !empty($manual_payment_request['adyen_details']) && $manual_payment_request['payment_type'] == '1') {
                $insert_pt_data['pspReference'] = !empty($manual_payment_request['adyen_details']['pspReference']) ? $manual_payment_request['adyen_details']['pspReference'] : '';
                $insert_pt_data['merchantReference'] = !empty($manual_payment_request['adyen_details']['merchantReference']) ? $manual_payment_request['adyen_details']['merchantReference'] : '';
            }
            $manual_payment_pt_array = $this->insert_manual_payment_in_prepaid($insert_pt_data);


            // Load AWS library.
            require_once 'aws-php-sdk/aws-autoloader.php';
            // Load SNS library.
            $this->load->library('Sns');
            $sns_object = new \Sns();
            // Load SQS library.
            $this->load->library('Sqs');
            $sqs_object = new \Sqs();


            $aws_data['hotel_ticket_overview_data'] = $hotel_ticket_overview_data;
            $aws_data['shop_products_data'] = $manual_payment_pt_array;
            $aws_data['is_manual_payment'] = '1';
            $aws_data['action'] = "process_manual_paayment";
            $aws_data['visitor_group_no'] = $visitor_group_no;
            $aws_message = base64_encode(gzcompress(json_encode($aws_data)));
            $queueUrl = QUEUE_URL;
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'MANNUAl_PAYMENT_QUEUE');
            } else {
                // This Fn used to send notification with data on AWS panel. 
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                if ($MessageId) {
                    $sns_object->publish($MessageId . '#~#' . $queueUrl);
                }
            }
            // prepare response array
            $response['status'] = (int) 1;
            $response['message'] = 'Payment done successfully';
            $response['reference_id'] = $visitor_group_no;
        } else {
            $response['status'] = (int) 0;
            $response['message'] = 'Oops, Something went wrong.';
        }
        $MPOS_LOGS['db_queries'] = $logs;
        return $response;
    }

    /**
     * @name     : confirm_shop_products_offline()
     * @Purpose  : used to insert manual payment record in PT
     * @Called   : called from processManualPayment() in api_model
     * @created by: Supriya Saxena<supriya10.aipl@gmail.com> on 3rd september, 2019
     */
    function insert_manual_payment_in_prepaid($prepaid_data) {
        global $MPOS_LOGS;
        global $internal_logs;
        $manual_payment_record = $insert_data = array();
        $i = 1;
        $insert_data['visitor_group_no'] = $prepaid_data['visitor_group_no'];
        $insert_data['activation_method'] = $prepaid_data['prepaid_type'];
        $insert_data['ticket_id'] = 0;
        $insert_data['tps_id'] = 0;
        $insert_data['title'] = trim(strip_tags($prepaid_data['title'])); // product name
        $insert_data['price'] = $ticketAmt = $prepaid_data['total_price'];
        $insert_data['tax'] = $ticket_tax_value = $prepaid_data['ticket_tax_value'];
        $insert_data['tax_name'] = $prepaid_data['tax_name'];
        $insert_data['net_price'] = ($ticketAmt * 100) / ($ticket_tax_value + 100);
        $insert_data['quantity'] = $prepaid_data['quantity'];
        $insert_data['created_date_time'] = $prepaid_data['date'];
        $insert_data['product_type'] = '3';
        $insert_data['cashier_id'] = $prepaid_data['cashier_id'];
        $insert_data['pos_point_id'] = ($prepaid_data['pos_point_id'] != "") ? $prepaid_data['pos_point_id'] : "";
        $insert_data['timezone'] = $prepaid_data['timezone'];
        $insert_data['cashier_name'] = $prepaid_data['cashier_name'];
        $insert_data['reseller_id'] = $prepaid_data['reseller_id'];
        $insert_data['reseller_name'] = $prepaid_data['reseller_name'];
        $insert_data['channel_type'] = $prepaid_data['channel_type'];
        $insert_data['hotel_id'] = $prepaid_data['hotel_id'];
        $insert_data['is_addon_ticket'] = '3';
        $insert_data['pspReference'] = isset($prepaid_data['pspReference']) && !empty($prepaid_data['pspReference']) ? $prepaid_data['pspReference'] : NULL;
        $insert_data['merchantReference'] = isset($prepaid_data['merchantReference']) && !empty($prepaid_data['merchantReference']) ? $prepaid_data['merchantReference'] : NULL;
        $insert_data['prepaid_ticket_id'] = $prepaid_data['visitor_group_no'] . str_pad($i, 3, "0", STR_PAD_LEFT);
        $insert_data['visitor_tickets_id'] = $insert_data['prepaid_ticket_id'] . '01';
        $this->db->insert("prepaid_tickets", $insert_data); // insert record in PT
        $logs['pt_insertion_query_' . date('H:i:s')] = $this->db->last_query();
        
        $insert_data['pos_point_name'] = isset($prepaid_data['pos_point_name']) ? $prepaid_data['pos_point_name'] : "";
        $insert_data['channel_id'] = isset($prepaid_data['channel_id']) ? $prepaid_data['channel_id'] : "";
        $insert_data['channel_name'] = isset($prepaid_data['channel_name']) ? $prepaid_data['channel_name'] : "";
        $insert_data['saledesk_id'] = isset($prepaid_data['saledesk_id']) ? $prepaid_data['saledesk_id'] : "";
        $insert_data['saledesk_name'] = isset($prepaid_data['saledesk_name']) ? $prepaid_data['saledesk_name'] : "";
        $insert_data['oroginal_price'] = isset($prepaid_data['total_price']) ? $prepaid_data['total_price'] : "";
        $insert_data['created_at'] = strtotime($prepaid_data['date']);
        $insert_data['time_based_done'] = '1';
        $insert_data['is_voucher'] = '0';
        $insert_data['updated_at'] = $insert_data['order_confirm_date'] = $prepaid_data['date'];
        $insert_data['manual_payment_note'] = $prepaid_data['manual_note'];
        
        if ($prepaid_data['cashier_code'] != '') {
            $cashier_data = explode('~_~', $prepaid_data['cashier_code']);
            $insert_data['cashier_code'] = (isset($cashier_data[0]) && $cashier_data[0] != '') ? $cashier_data[0] : 'RF';
            $insert_data['location_code'] = (isset($cashier_data[1]) && $cashier_data[1] != '') ? $cashier_data[1] : 'ENTREE';
        } else {
            $insert_data['cashier_code'] = 'RF';
            $insert_data['location_code'] = 'ENTREE';
        }
        $manual_payment_record[] = $insert_data;
        $internal_logs['pt_response'] = $manual_payment_record;
        $MPOS_LOGS['pt_query'] = $logs;
        return $manual_payment_record;
    }

    /* #endregion Order Process  Module  : This module covers order process api's  */
}

?>