<?php

defined('BASEPATH') || exit('No direct script access allowed');

class Block_capacity_model extends My_Model
{
    /* #region  Fetches corresponding Product ID for a given Shared Capacity ID */    
    function __construct()
    {
        parent::__construct();
    }
    /**
     * get_product_id
     *
     * @param  mixed $shared_capacity_id - Shared Capacity's ID for which its Product ID is to be fetched
     * @return array Product's data of the given Shared Capacity ID
     * @author Rohan Singh Chauhan <rohan.aipl@gmail.com> on 12th May, 2020
     */
    function get_product_id($shared_capacity_id)
    {
       /* #region  BOC of get_product_id */
        return $this->primarydb->db->select('mec_id AS product_id, timezone, cod_id AS supplier_id')
            ->from('modeventcontent')
            ->where('shared_capacity_id', $shared_capacity_id)
            ->get()->row();
       /* #endregion EOC of get_product_id */
    }
    /* #endregion Fetches corresponding Product ID for a given Shared Capacity ID */

    /* #region  To notify availability update to OTA */
    /**
     * notify_ota
     *
     * @param  mixed $requested_data
     * @return void
     * @author Haripriya <h.soni@prioticket.com> on 14th April, 2020
     */
    function notify_ota($requested_data = []) 
    {
        /* #region  BOC of notify_ota */
        $selected_date = isset($requested_data['selected_date']) ? $requested_data['selected_date'] : date('Y-m-d');
        $end_date = isset($requested_data['end_date']) ? $requested_data['end_date'] : date('Y-m-d', strtotime('+7 days',strtotime($selected_date)));
        if (empty($requested_data['mec_data'])) {
            $mec_query_data = $this->find('modeventcontent', array('select' => 'is_own_capacity, shared_capacity_id, own_capacity_id, is_reservation', 'where' => "mec_id = " . $requested_data['ticket_id']));
            $mec_data = $mec_query_data[0];
        } else {
            $mec_data = $requested_data['mec_data'];
        }
        $queue_data = [
            'request_type' => 'AVAILABILITY_NOTIFICATON',
            'is_reservation' => $mec_data['is_reservation'],
            'is_own_capacity' => $mec_data['is_own_capacity'],
            'shared_capacity_id' => $mec_data['shared_capacity_id'],
            'own_capacity_id' => $mec_data['own_capacity_id'],
            'product_id' => $requested_data['ticket_id'],
            'from_date' => $selected_date,
            'to_date' => $end_date,
        ];
        try {
            $aws_message = json_encode($queue_data);
            $aws_message = base64_encode(gzcompress($aws_message));
            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            if (ENVIRONMENT == 'Local') {
                local_queue($aws_message, 'OTA_NOTIFICATION_PROCESS');
            } else {
                $MessageId = $sqs_object->sendMessage(OTA_NOTIFICATION_PROCESS, $aws_message);
                if ($MessageId !== false) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish($MessageId . '#~#' . OTA_NOTIFICATION_PROCESS, OTA_NOTIFICATION_PROCESS_ARN);
                }
            }
            $this->CreateLog("GYG_OTA_NOTIFY_Check.log", 'Exception', array('MessageId' => $MessageId, 'request_Data' => json_encode($queue_data)));
        } catch (Exception $e) {
            $this->CreateLog("OTA_NOTIFY.log", 'Exception', array('exception' => json_encode($e->getMessage())));
        }
        /* #endregion EOC of notify_ota */
    }
    /* #endregion To notify availability update to OTA */

    /* #region  to get ticket capacity data */
    /**
     * get_ticket_capacity_data
     *
     * @param  mixed $data
     * @return array
     */
    function get_ticket_capacity_data($data = array())
    {
        /* #region  BOC of get_ticket_capacity_data */
        if (empty($data)) {
            return array();
        }

        $this->primarydb->db->select('*');
        $this->primarydb->db->from('ticket_capacity_v1');
        $query = '';
        foreach($data['date'] as $key => $value){
            if ($key == 0){
                $query .= '(date="'.$data['date'][$key].'" AND from_time="'.$data['from_time'][$key].'" AND to_time="'.$data['to_time'][$key].'")';
            } else {
                $query .= ' OR (date="'.$data['date'][$key].'" AND from_time="'.$data['from_time'][$key].'" AND to_time="'.$data['to_time'][$key].'")' ;
            }
        }
        $query = '('.$query.')';
        $this->primarydb->db->where('shared_capacity_id IN ('.$data['shared_capacity_id'].')');
        if ($query != ''){
            $this->primarydb->db->where($query);
        }
        $result = $this->primarydb->db->get();

        if ($result->num_rows() > 0) {
            $data = $result->result_array();
            foreach ($data as $value) {
                $key = $value['date']."_".$value['from_time']." - ".$value['to_time'];
                $final_data[$key] = $value;
            }
            return $final_data;
        } else {
            return array();
        }
        /* #endregion BOC of get_ticket_capacity_data */
    }
    /* #endregion to get ticket capacity data */

    /* #region  get information for service like currency, numberformat, timezone etc */
    /**
     * getGenericServiceValues
     *
     * @param  mixed $service_id - About which servies information have to fetch
     * @param  mixed $is_secondary_db
     * @return mixed Array of service infomation
     */
    function getGenericServiceValues($service_id = '', $is_secondary_db = "0") 
    {
        /* #region  BOC of getGenericServiceValues */
        $db = $this->primarydb->db;
        /* #region  If Service ID given */
        if ($service_id != '') {
            $db->select('*');
            $db->from('services');
            $db->where('id', $service_id);
            $query = $db->get();
        }
        /* #endregion If Service ID given */
        /* #region  If query ran and any record(s) found */
        if ($query->num_rows() > 0) {
            return $query->row();
        } else {
            return false;
        }
        /* #endregion If query ran and any record(s) found */
        /* #endregion EOC of getGenericServiceValues */
    }
    /* #endregion get information for service like currency, numberformat, timezone etc */

    /* #region  To convert values into GMT from user's timezone and to user's timezone from GMT */
    /**
     * convert_time_into_user_timezone
     *
     * @param  mixed $time - Time to be saved/displayed.
     * @param  mixed $timezone - Current timezone of the user
     * @param  mixed $action - 0->If value is to be fetched from database; 1->If value is to be saved into database
     * @param  mixed $type
     * @return string Time as per timezone will be returned
     * @author Hemant Goel <hemant.intersoft@gmail.com> on 13th August, 2015
     */
    function convert_time_into_user_timezone($time = '', $timezone = '', $action = '', $type = '') 
    {
        /* #region  BOC of convert_time_into_user_timezone */
        /* #region  When save values into database */
        if ($action == '1') {
            $timezone = -($timezone);
        }
        /* #endregion When save values into database */
        if (strstr($time, '-')) {
            $time = str_replace('-', '', $time);
        }

        $time = strtotime($time) + ($timezone * 60 * 60);
        if ($time < strtotime('00:00:00')) {
            $time = '-' . date('H:i', $time);
        } else {
            $time = date('H:i', $time);
        }
        return $time;
        /* #endregion EOC of convert_time_into_user_timezone */
    }
    /* #endregion To convert values into GMT from user's timezone and to user's timezone from GMT */

    /* #region  Fetches all the Shared Capacity IDs for the given Supplier */
    /**
     * get_shared_capacity_ids
     *
     * @param  mixed $supplier_id - Supplier's ID whose Shared Capacities are to be fetched
     * @return array Shared Capacity IDs of the given Supplier
     */
    function get_shared_capacity_ids($supplier_id)
    {
        /* #region  BOC of get_shared_capacity_ids */
        return $this->primarydb->db->select('shared_capacity_id, own_capacity_id')
            ->from('modeventcontent')
            ->where('cod_id', $supplier_id)
            ->get()->result_array();
        /* #endregion EOC of get_shared_capacity_ids */
    }
    /* #endregion Fetches all the Shared Capacity IDs for the given Supplier */
}