<?php

if (!function_exists('local_queue')) {
    function local_queue($data, $url){        
        $ci = & get_instance();
        $taskrunner_url = $ci->config->item('taskrunner_url');
        $bigquery_server_url = $ci->config->item('bigquery_server_url');
        $api_url = $ci->config->item('api_url');
        if($url == 'UPDATE_DB2') { //UPDATE_DB_ARN
            $url = $taskrunner_url.'/pos/update_in_db';
        } else if($url == 'VENUE_TOPIC_ARN'){
            $url = $taskrunner_url.'/pos/insert_in_db';
        } else if($url == 'THIRD_PARTY_NOTIFY_ARN'){
            $url = $api_url.'/queue/update_css_hq';
        } else if($url == 'BIQ_QUERY_AGG_VT_INSERT'){
            $url = $bigquery_server_url.'/bigquery/big_query_agg_vt_insertion';
        } else if($url == 'EVENT_TOPIC_ARN'){
            $url = $admin_url .'/pos/SYNC_googleCalender';
        } else if($url == 'UPDATE_DB_ARN') {
            $url = $taskrunner_url.'/pos/update_in_db_direct';
        }
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
        curl_exec($handle);
        curl_close($handle);
    }
}