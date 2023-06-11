<?php 

namespace Prio\Traits\V1\Helpers;

trait Tlocal_queue_helper {

    public static function local_queue($data, $url){
        $ci = & get_instance();
        $taskrunner_url = $ci->config->item('taskrunner_url');
        $local_url = $ci->config->item('base_url');
        $master_url = $ci->config->item('master_url');
        $report_base_url = $ci->config->item('prioticket_base_url');
        if($url == '' || $url == 'VENUE_TOPIC_ARN'){
           $url = $taskrunner_url.'/pos/insert_in_db';
        } else if($url == 'UPDATE_DB_ARN') {
            $url = $taskrunner_url.'/pos/update_in_db_direct';
        } else if($url == 'CSS_ARN') {
            $url = $local_url.'index.php/city_sightseeing/process_queue';
        } else if($url == 'COMMIT_ORDER_TOPIC_ARN') {
            $url = $local_url.'index.php/common_api/process_commit_order_queue';
        } else if($url == 'CANCEL_ORDER_TOPIC_ARN') {
            $url = $local_url.'index.php/common_api/process_cancel_order_queue';
        } else if($url == 'API_COMMON_API_DB1_ARN') { 
            $url = $local_url.'index.php/queue/common_insertion_queue_process'; 
        }  else if($url == 'API_DB1_MULTIPLE_INSERTION_ARN') {
            $url = $local_url.'index.php/queue/common_db1_multiple_insertion_queue';
        }else if($url == 'API_UNCANCEL_ORDER_ARN') {
            $url = $local_url.'index.php/queue/common_uncancel_order_queue_fun';
        } else if($url == 'MPOS_JSON') {
            $url = $master_url.'/firebase/confirm_orders_from_queue';
        } else if($url == 'SCANING_ACTION_ARN') { //SCANING_ACTION_ARN
            $url = $taskrunner_url.'/scanning_action/db_update_actions';
        } else if($url == 'UPDATE_DB2') { //UPDATE_DB_ARN
            $url = $taskrunner_url.'/pos/update_in_db';
        } else if($url == 'TEST_API_SERVER_ARN') { //TEST_API_SERVER_ARN
            $url = $local_url.'/index.php/api/update_third_party_request';
        } else if($url == 'FIREBASE_EMAIL_TOPIC') { //FIREBASE_EMAIL_TOPIC
            $url = $report_base_url.'/mpos/send_email_from_mpos_queue';
        } else if($url == 'MPOS_JSON_ARN') { //MPOS_JSON_ARN
            $url = $master_url.'/firebase/confirm_orders_from_queue';
        } else if($url == 'SNS_UPDATE_TEMP_ANALYTIC') { //SNS_UPDATE_TEMP_ANALYTIC
            $url = $report_base_url.'/pos/update_temp_analytic_records_from_venue_app';
        } else if($url == 'EVENT_TOPIC_ARN') { //EVENT_TOPIC_ARN
            $url = $report_base_url.'/pos/SYNC_googleCalender';
        }else if($url == 'API_ERROR_RECORDS') { //API_ERROR_RECORDS
            $url = $local_url.'index.php/queue/api_error_records_save';
        }else if($url == 'THIRD_PARTY_REDUMPTION_ARN') { //API_ERROR_RECORDS
            $url = $local_url.'index.php/api/update_third_party_redumption';
        } else if($url == 'MANNUAl_PAYMENT_QUEUE') { // MANNUAl_PAYMENT_QUEUE
            $url = $taskrunner_url.'/pos/insert_in_db_direct';
        }
		
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
        curl_exec($handle);
        curl_close($handle);        
    }
   
}


?>