<?php
class Firebase_model extends MY_Model {
    function __construct() {
	///Call the Model constructor
	parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_model');
	$this->base_url  = $this->config->config['base_url'];
	$this->root_path = $this->config->config['root_path'];
	$this->imageDir  = $this->config->config['imageDir'];
    }   
    
    /**
     * @name   : update_visitor_for_cancel_orders()
     * @purpose: update visitor_tickets, temp_analytic_records when cancel any order and sns hit from firebase model of api server
     * @where  : It is called from pos.php
     * @returns: No parameter is returned
     * @createdBy: komal <komal.intersoft@gmail.com> on 16 Dec 2017
     */
    function update_visitor_for_cancel_orders($data = array(), $update_ids_in_vt = array(), $hotel_id=0) {
        global $MPOS_LOGS;
        $logs['req_'.date('H:i:s')]=$data;
        $logs['update_ids_in_vt']=$update_ids_in_vt;
        $logs['hotel_id']=$hotel_id;
        $transactions_data = array();
        
            
        $visitor_table = 'visitor_tickets';
        
        /*
        * Get the rows from visitor tickets table of a particular ticket of an order which aren't till used.
        * If ticket is already confirmed then update row as refunded and insert new rows for refunded transactions.
        * else if ticket is not confirmed yet (current status is block/option/quote) then just udpate invoice_status = 10
        */
        $visitor_tickets_id = $data['visitor_tickets_id'];
        $action_performed = $data['action_performed'];
        $order_confirm_date = $data['order_confirm_date'];
        $refunded_by = $data['refunded_by'];
        $refunded_by_user = $data['refunded_by_user'];
        $order_cancellation_date = $data['order_cancellation_date'];
        unset($data['action_performed']);
        unset($data['visitor_tickets_id']);
        unset($data['order_confirm_date']);
        unset($data['refunded_by']);
        unset($data['refunded_by_user']);
        unset($data['order_cancellation_date']);
        $this->secondarydb->db->select('*');
        $this->secondarydb->db->from($visitor_table);
        $this->secondarydb->db->where($data);
        $this->secondarydb->db->where_in('transaction_type_name', array('General sales', 'Ticket cost'));
        $this->secondarydb->db->where('id IN (' . implode(',', $visitor_tickets_id) . ')');
        $query = $this->secondarydb->db->get();
        if ($query->num_rows() > 0) {
            $result = $query->result_array();
            $result = $this->get_max_version_data($result, 'id');
        }
        $logs['vt_query_'.date('H:i:s')]=$this->secondarydb->db->last_query();
        $count = 0;
        $transections_ids = array();
        foreach ($result as $visitor_tickets) {
            $channel_type = $visitor_tickets['channel_type'];
            if (!in_array($visitor_tickets['transaction_id'], $transections_ids) && $visitor_tickets['row_type'] == 1) {
                $transections_ids[] = $visitor_tickets['transaction_id'];
                $transactions_data[$count]['transaction_id'] = $visitor_tickets['transaction_id'];
                $transactions_data[$count++]['booking_status'] = $visitor_tickets['booking_status'];
            }
        }
        
        $sendForRevision = false;
        /* CHECK for distributor if exists in revision constant */
        $revision_distributors = json_decode(REVISION_DISTRIBUTORS);
        if (!empty($result[0]['hotel_id']) && in_array($result[0]['hotel_id'], $revision_distributors)) {
            $sendForRevision = true;
        }
        
        if (!empty($transactions_data)) {
             $final_insert_data = array();
             $j = 0;
            foreach ($transactions_data as $transaction) {
                
                $where_in_visitor_tickets = array(
                    'transaction_id' => $transaction['transaction_id'],
                );
                if ($transaction['booking_status'] == 1) {
                    
                    $visitor_records = $this->secondarydb->db->order_by('row_type', 'ASC')->get_where($visitor_table, $where_in_visitor_tickets)->result_array();
                    $visitor_records = $this->get_max_version_data($visitor_records, 'id');
                    $version = (max(array_unique(array_column($visitor_records, 'version')))+1);
                    
                    /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                    $updateCols = array("is_refunded" => '2', "ticket_status" => "0", "order_cancellation_date" => $order_cancellation_date, 
                                            "order_updated_cashier_id" => $refunded_by, "order_updated_cashier_name" => $refunded_by_user, 
                                            "CONCAT_VALUE" => array("action_performed" => ', '.$action_performed), 
                                            "updated_at" => gmdate("Y-m-d H:i:s"));
                    $arrayIns = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => 'transaction_id = ' . $transaction['transaction_id']);
                    
                    $visitor_ticket_id = 0;        
                    foreach ($visitor_records as $visitor_record) {
                        //Make array to notify third party arena
                        if ($visitor_record['row_type'] == 1 && $visitor_record['museum_id'] == ARENA_SUPPLIER_ID) {
                            $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['ticket_type'] = $visitor_record['tickettype_name'];
                            $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['count'] = $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['count'] + 1;
                            
                            if ($visitor_records[0]['selected_date'] != '' && $visitor_records[0]['selected_date'] != "0") {
                                $arena_refund_order[$visitor_record['ticketId']] = array(
                                    "request_type" => "cancel_booking",
                                    "data" => array(
                                        "distributor_id" => $visitor_records[0]['hotel_id'],
                                        "distributor_reference" => isset($visitor_records[0]['without_elo_reference_no']) && !empty($visitor_records[0]['without_elo_reference_no']) ? $visitor_records[0]['without_elo_reference_no'] : 'PRIO' . time(),
                                        "booking_reference" => $visitor_records[0]['vt_group_no'],
                                        "booking_type" => array(
                                            "ticket_id" => $visitor_record['ticketId'],
                                            "from_date_time" => $visitor_record['selected_date']."T".$visitor_record['from_time'],
                                            "to_date_time" => $visitor_record['selected_date']."T".$visitor_record['to_time'],
                                            "booking_details" => $arena_refund_booking_details[$visitor_record['ticketId']],
                                        ),
                                        "booking_name" => "",
                                        "booking_email" => ""
                                    )
                                );
                            } else {
                                $arena_refund_order[$visitor_record['ticketId']] = array(
                                    "request_type" => "cancel_booking",
                                    "data" => array(
                                        "distributor_id" => $visitor_records[0]['hotel_id'],
                                        "distributor_reference" => isset($visitor_records[0]['without_elo_reference_no']) && !empty($visitor_records[0]['without_elo_reference_no']) ? $visitor_records[0]['without_elo_reference_no'] : 'PRIO' . time(),
                                        "booking_reference" => $visitor_records[0]['vt_group_no'],
                                        "booking_type" => array(
                                            "ticket_id" => $visitor_record['ticketId'],
                                            "booking_details" => $arena_refund_booking_details[$visitor_record['ticketId']],
                                        ),
                                        "booking_name" => "",
                                        "booking_email" => ""
                                    )
                                );
                            }
                        }
                        
                        $insert_visitor_row = array();
                        $insert_visitor_row = $visitor_record;
                        
                        $insert_visitor_row['id'] = $insert_visitor_row['id'] . '' . '11';
                        $insert_visitor_row['transaction_id'] = $insert_visitor_row['transaction_id'] . '' . '11';
                        if(!empty($update_ids_in_vt)){                            
                            $insert_visitor_row['transaction_id']  = $update_ids_in_vt[$insert_visitor_row['vt_group_no'].'_'.$insert_visitor_row['ticketId'].'_'.$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['passNo']][0]['prepaid_ticket_id'];
                            $visitor_group_no  = $insert_visitor_row['vt_group_no'];
                            $ticketId          = $insert_visitor_row['ticketId'];
                            $ticketpriceschedule_id = $insert_visitor_row['ticketpriceschedule_id'];
                            $passNo                 = $insert_visitor_row['passNo'];
                            if($insert_visitor_row['row_type'] == '1' ){
                                $visitor_ticket_id = $update_ids_in_vt[$insert_visitor_row['vt_group_no'].'_'.$insert_visitor_row['ticketId'].'_'.$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['passNo']][0]['visitor_tickets_id'];
                                $insert_visitor_row['id'] = $visitor_ticket_id;
                            } else {
                                if($insert_visitor_row['row_type'] > '9'){
                                    $visitor_ticket_id        =  $insert_visitor_row['transaction_id'].$insert_visitor_row['row_type'];
                                } else{
                                    $visitor_ticket_id        =  $insert_visitor_row['transaction_id'].'0'.$insert_visitor_row['row_type'];
                                }
                                $insert_visitor_row['id']     =  $visitor_ticket_id;
                            }
                            $insert_visitor_row['transaction_id']  = $update_ids_in_vt[$insert_visitor_row['vt_group_no'].'_'.$insert_visitor_row['ticketId'].'_'.$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['passNo']][0]['transaction_id'];
                            if ($update_ids_in_vt[$insert_visitor_row['vt_group_no'] . '_' . $insert_visitor_row['ticketId'] . '_' . $insert_visitor_row['ticketpriceschedule_id'] . '_' . $insert_visitor_row['passNo']][0]['is_addon_ticket'] == "2" && !empty($update_ids_in_vt)) {
                                unset($update_ids_in_vt[$visitor_group_no . '_' . $ticketId . '_' . $ticketpriceschedule_id . '_' . $passNo][0]);
                                sort($update_ids_in_vt[$visitor_group_no . '_' . $ticketId . '_' . $ticketpriceschedule_id . '_' . $passNo]);
                            }
                        }
                        $insert_visitor_row['invoice_status'] = "11";
                        $insert_visitor_row['is_refunded'] = '1';
                        $insert_visitor_row['ticket_status'] = '0';
                        $insert_visitor_row['order_cancellation_date'] = $order_cancellation_date;
                        $insert_visitor_row['order_updated_cashier_id'] = $refunded_by;
                        $insert_visitor_row['order_updated_cashier_name'] = $refunded_by_user;
                        $insert_visitor_row['action_performed'] = '0, '.$action_performed;
                        $insert_visitor_row['updated_at'] = gmdate("Y-m-d H:i:s");
                        $insert_visitor_row['last_modified_at'] = gmdate("Y-m-d H:i:s");
                        $insert_visitor_row['order_confirm_date'] = $order_confirm_date;
                        $insert_visitor_row['col8'] = gmdate('Y-m-d', strtotime(gmdate('Y-m-d H:i:s')) + ($visitor_record['timezone'] * 3600));
                        unset($insert_visitor_row['last_modified_at']);
                        if (strtolower($insert_visitor_row['creditor']) == "credit") {
                            $insert_visitor_row['creditor'] = 'Debit';
                        } else {
                            $insert_visitor_row['creditor'] = 'Credit';
                        }
                        if(!empty($version)) {
                            $insert_visitor_row['version'] = $version;
                        }
                        $final_insert_data[] = $insert_visitor_row;
                    }
                    if(!empty($update_ids_in_vt)){
                        unset($update_ids_in_vt[$visitor_group_no.'_'.$ticketId.'_'.$ticketpriceschedule_id.'_'.$passNo][0]);
                        sort($update_ids_in_vt[$visitor_group_no.'_'.$ticketId.'_'.$ticketpriceschedule_id.'_'.$passNo]);
                    }
                } else {                   
                    /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                    $updateCols = array("invoice_status" => '10');
                    $arrayIns = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => 'used = "0" and transaction_id = '.$transaction['transaction_id']);
                }
                
                $this->set_insert_queries($arrayIns, array());
                $logs['vt_for_cancel->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                unset($MPOS_LOGS['get_insert_queries']);
                
                
                $logs['update_vt_query_'.$j.'_'.date('H:i:s')]=$this->secondarydb->db->last_query();
                $j++;
            }
            if (!empty($arena_refund_order)) {
                $i = 1;
                foreach($arena_refund_order as $arena_refund_data) {
                    sort($arena_refund_data['data']['booking_type']['booking_details']);
                    $logs['arena_request'.$i.'_'.date('H:i:s')]=$arena_refund_data;
                    $notified_at = date('Y-m-d H:i:s');
                    $arena_response = $this->arena_refund_listener($arena_refund_data);
                    $logs['arena_response'.$i.'_'.date('H:i:s')]=$arena_response;
                    if ($arena_response == 202) {
                        $arena_response_status = "No error";
                    } else {
                        $arena_response_status = "Error";
                    }

                    $notify_to_third_parties = array(
                        "third_party_id" => 24,
                        "visitor_group_no" => $arena_refund_data['data']['booking_reference'],
                        "status_code" => $arena_response,
                        "response_status" => $arena_response_status,
                        "notified_at" => $notified_at,
                        "action_performed" => "cancel_booking",
                        "channel_type" => $channel_type,
                        "created_date_time" => date('Y-m-d H:i:s')
                    );
                    $logs['notify_to_third_parties_data'.$i.'_'.date('H:i:s')] = $notify_to_third_parties;
                    $this->db->insert('notify_to_third_parties', $notify_to_third_parties);
                }
                $i++;
            }
            if(!empty($final_insert_data)){
                $this->insert_batch($visitor_table, $final_insert_data, '1');
                
                $logs['insert_vt_query_'.date('H:i:s')]=$this->secondarydb->db->last_query();
                // To update the data in RDS realtime
                if( SYNCH_WITH_RDS_REALTIME ) {
                    $this->insert_batch('visitor_tickets', $final_insert_data, "4");
                }
            }
        }

        $MPOS_LOGS['update_visitor_for_cancel_orders']=$logs;
    }
    
   
    
    /**
     * @name   : update_visitor_for_partial_cancel_orders()
     * @purpose: update visitor_tickets, temp_analytic_records when cancel any order and sns hit from firebase model of api server
     * @where  : It is called from pos.php
     * @returns: No parameter is returned
     * @createdBy: komal <komal.intersoft@gmail.com> on 27 Dec 2017
     */
    function update_visitor_for_partial_cancel_orders($data = array(), $update_ids_in_vt = array()) {
        global $MPOS_LOGS;
        $logs['req_'.date('H:i:s')]=$data;
        $logs['vt_ids']=$update_ids_in_vt;
        $transactions_data = array();
        /*
        * Get the rows from visitor tickets table of a particular ticket of an order which aren't till used.
        * If ticket is already confirmed then update row as refunded and insert new rows for refunded transactions.
        * else if ticket is not confirmed yet (current status is block/option/quote) then just udpate invoice_status = 10
        */
        $visitor_tickets_id = $data['visitor_tickets_id'];
        $refunded_by = $data['refunded_by'];
        $refunded_by_user = $data['refunded_by_user'];
        $order_cancellation_date = $data['order_cancellation_date'];
        unset($data['visitor_tickets_id']);
        unset($data['visitor_ids']);
        unset($data['refunded_by']);
        unset($data['refunded_by_user']);
        unset($data['order_cancellation_date']);
        $cancel_tickets = $data['cancel_tickets'];
        unset($data['cancel_tickets']);
        $action_performed = $data['action_performed'];
        unset($data['action_performed']);
        $order_confirm_date = $data['order_confirm_date'];
        unset($data['order_confirm_date']);
        unset($data['hotel_id']);
        $i = 0;
        
        $visitor_table = 'visitor_tickets';
        
        foreach($cancel_tickets as $cancel) {
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from($visitor_table);
            $this->secondarydb->db->where($data);
            $this->secondarydb->db->where('ticketpriceschedule_id', $cancel['tps_id']);
            $this->secondarydb->db->where_in('transaction_type_name', array('General sales', 'Ticket cost'));
            $this->secondarydb->db->where('id IN (' . implode(',', $visitor_tickets_id) . ')');
            $this->secondarydb->db->limit(2 * $cancel['cancel_quantity']);
            $query = $this->secondarydb->db->get();
            if ($query->num_rows() > 0) {
                $result = $query->result_array();
            }
            
            $count = 0;
            $transections_ids = array();
            $transactions_data = array();
            foreach ($result as $visitor_tickets) {
                $channel_type = $visitor_tickets['channel_type'];
                if (!in_array($visitor_tickets['transaction_id'], $transections_ids) && ($visitor_tickets['row_type'] == 1)) {
                    $transections_ids[] = $visitor_tickets['transaction_id'];
                    $transactions_data[$count]['transaction_id'] = $visitor_tickets['transaction_id'];
                    $transactions_data[$count++]['booking_status'] = $visitor_tickets['booking_status'];
                }
            }

            if (!empty($transactions_data)) {
                $final_insert_data = array();
                $j = 0;
                foreach ($transactions_data as $transaction) {

                    $where_in_visitor_tickets = array(
                        'transaction_id' => $transaction['transaction_id'],
                    );
                    if ($transaction['booking_status'] == 1) {

                        $visitor_records = $this->secondarydb->db->order_by('row_type', 'ASC')->get_where($visitor_table, $where_in_visitor_tickets)->result_array();
                        $visitor_records = $this->get_max_version_data($visitor_records, 'id');
                        $version = (max(array_unique(array_column($visitor_records, 'version')))+1);
                        
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("is_refunded" => '2', "ticket_status" => '0', "order_cancellation_date" => $order_cancellation_date, 
                                                "order_updated_cashier_id" => $refunded_by, "order_updated_cashier_name" => $refunded_by_user, 
                                                "CONCAT_VALUE" => array("action_performed" => ', '.$action_performed), 
                                                "updated_at" => gmdate("Y-m-d H:i:s"));
                        $arrayIns = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => 'transaction_id = ' . $transaction['transaction_id']);
                        
                        $visitor_ticket_id    = 0;
                        foreach ($visitor_records as $visitor_record) {
                            //Make array to notify third party arena
                            if ($visitor_record['row_type'] == 1 && $visitor_record['museum_id'] == ARENA_SUPPLIER_ID) {
                                $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['ticket_type'] = $visitor_record['tickettype_name'];
                                $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['count'] = $arena_refund_booking_details[$visitor_record['ticketId']][$visitor_record['ticketpriceschedule_id']]['count'] + 1;

                                if ($visitor_records[0]['selected_date'] != '' && $visitor_records[0]['selected_date'] != "0") {
                                    $arena_refund_order[$visitor_record['ticketId']] = array(
                                        "request_type" => "cancel_booking",
                                        "data" => array(
                                            "distributor_id" => $visitor_records[0]['hotel_id'],
                                            "distributor_reference" => isset($visitor_records[0]['without_elo_reference_no']) && !empty($visitor_records[0]['without_elo_reference_no']) ? $visitor_records[0]['without_elo_reference_no'] : 'PRIO' . time(),
                                            "booking_reference" => $visitor_records[0]['vt_group_no'],
                                            "booking_type" => array(
                                                "ticket_id" => $visitor_record['ticketId'],
                                                "from_date_time" => $visitor_record['selected_date']."T".$visitor_record['from_time'],
                                                "to_date_time" => $visitor_record['selected_date']."T".$visitor_record['to_time'],
                                                "booking_details" => $arena_refund_booking_details[$visitor_record['ticketId']],
                                            ),
                                            "booking_name" => "",
                                            "booking_email" => ""
                                        )
                                    );
                                } else {
                                    $arena_refund_order[$visitor_record['ticketId']] = array(
                                        "request_type" => "cancel_booking",
                                        "data" => array(
                                            "distributor_id" => $visitor_records[0]['hotel_id'],
                                            "distributor_reference" => isset($visitor_records[0]['without_elo_reference_no']) && !empty($visitor_records[0]['without_elo_reference_no']) ? $visitor_records[0]['without_elo_reference_no'] : 'PRIO' . time(),
                                            "booking_reference" => $visitor_records[0]['vt_group_no'],
                                            "booking_type" => array(
                                                "ticket_id" => $visitor_record['ticketId'],
                                                "booking_details" => $arena_refund_booking_details[$visitor_record['ticketId']],
                                            ),
                                            "booking_name" => "",
                                            "booking_email" => ""
                                        )
                                    );
                                }
                            }
                            $insert_visitor_row = array();
                            $insert_visitor_row = $visitor_record;
                            $insert_visitor_row['id'] = $insert_visitor_row['id'] . '' . '11';
                            $insert_visitor_row['transaction_id'] = $insert_visitor_row['transaction_id'] . '' . '11';
                            if(!empty($update_ids_in_vt)){
                                $insert_visitor_row['transaction_id']  = $update_ids_in_vt[$insert_visitor_row['vt_group_no'].'_'.$insert_visitor_row['ticketId'].'_'.$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['passNo']][0]['prepaid_ticket_id'];
                                $visitor_group_no  = $insert_visitor_row['vt_group_no'];
                                $ticketId          = $insert_visitor_row['ticketId'];
                                $ticketpriceschedule_id = $insert_visitor_row['ticketpriceschedule_id'];
                                $passNo                 = $insert_visitor_row['passNo'];
                                if ($insert_visitor_row['row_type'] == '1') {
                                    $visitor_ticket_id = $update_ids_in_vt[$insert_visitor_row['vt_group_no'] . '_' . $insert_visitor_row['ticketId'] . '_' . $insert_visitor_row['ticketpriceschedule_id'] . '_' . $insert_visitor_row['passNo']][0]['visitor_tickets_id'];
                                    $insert_visitor_row['id'] = $visitor_ticket_id;
                                } else {
                                    if ($insert_visitor_row['row_type'] > '9') {
                                        $visitor_ticket_id = $insert_visitor_row['transaction_id'] . $insert_visitor_row['row_type'];
                                    } else {
                                        $visitor_ticket_id = $insert_visitor_row['transaction_id'] . '0' . $insert_visitor_row['row_type'];
                                    }
                                    $insert_visitor_row['id'] = $visitor_ticket_id;
                                }
                                $insert_visitor_row['transaction_id']  = $update_ids_in_vt[$insert_visitor_row['vt_group_no'].'_'.$insert_visitor_row['ticketId'].'_'.$insert_visitor_row['ticketpriceschedule_id'].'_'.$insert_visitor_row['passNo']][0]['transaction_id'];
                                if ($update_ids_in_vt[$insert_visitor_row['vt_group_no'] . '_' . $insert_visitor_row['ticketId'] . '_' . $insert_visitor_row['ticketpriceschedule_id'] . '_' . $insert_visitor_row['passNo']][0]['is_addon_ticket'] == "2" && !empty($update_ids_in_vt)) {
                                    unset($update_ids_in_vt[$visitor_group_no . '_' . $ticketId . '_' . $ticketpriceschedule_id . '_' . $passNo][0]);
                                    sort($update_ids_in_vt[$visitor_group_no . '_' . $ticketId . '_' . $ticketpriceschedule_id . '_' . $passNo]);
                                }
                            }
                            $insert_visitor_row['invoice_status'] = "11";
                            $insert_visitor_row['is_refunded'] = '1';
                            $insert_visitor_row['ticket_status'] = '0';
                            $insert_visitor_row['order_cancellation_date'] = $order_cancellation_date;
                            $insert_visitor_row['order_updated_cashier_id'] = $refunded_by;
                            $insert_visitor_row['order_updated_cashier_name'] = $refunded_by_user;
                            $insert_visitor_row['action_performed'] = '0, '.$action_performed;
                            $insert_visitor_row['updated_at'] = gmdate("Y-m-d H:i:s");
                            $insert_visitor_row['last_modified_at'] = gmdate("Y-m-d H:i:s");
                            $insert_visitor_row['order_confirm_date'] = $order_confirm_date;
                            $insert_visitor_row['col8'] = gmdate('Y-m-d', strtotime(gmdate('Y-m-d H:i:s')) + ($visitor_record['timezone'] * 3600));
                            unset($insert_visitor_row['last_modified_at']);
                            if (strtolower($insert_visitor_row['creditor']) == "credit") {
                                $insert_visitor_row['creditor'] = 'Debit';
                            } else {
                                $insert_visitor_row['creditor'] = 'Credit';
                            }
                            if(!empty($version)) {
                                $insert_visitor_row['version'] = $version;
                            }
                            $final_insert_data[] = $insert_visitor_row;
                        }
                        if(!empty($update_ids_in_vt)){
                            unset($update_ids_in_vt[$visitor_group_no.'_'.$ticketId.'_'.$ticketpriceschedule_id.'_'.$passNo][0]);
                            sort($update_ids_in_vt[$visitor_group_no.'_'.$ticketId.'_'.$ticketpriceschedule_id.'_'.$passNo]);
                        }
                    } else { 
                        /* COLUMNS TO MERGE NEW VALUES IN INSERT QUERY (ARRAY TO INSERT INSTEAD OF UPDATION) */
                        $updateCols = array("invoice_status" => '10');
                        $arrayIns = array("table" => 'visitor_tickets', "columns" => $updateCols, "where" => 'used = "0" and transaction_id = '.$transaction['transaction_id']);
                    }
                    
                    $this->set_insert_queries($arrayIns, array());
                    $logs['vt_for_partial_cancel->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                    unset($MPOS_LOGS['get_insert_queries']);                    
                    
                    $logs['update_vt_query_'.$j.'_'.date('H:i:s')]=$this->secondarydb->db->last_query();
                    $j++;
                }
                if(!empty($final_insert_data)){
                    $this->insert_batch($visitor_table, $final_insert_data, '1');
                    $logs['update_vt_'.date('H:i:s')] = $this->secondarydb->db->last_query();
                    // To update the data in RDS realtime
                    if( SYNCH_WITH_RDS_REALTIME ) {
                        $this->insert_batch('visitor_tickets', $final_insert_data, "4");
                    }
                }
            }
        }
        if (!empty($arena_refund_order)) {
            $i = 1;
            foreach($arena_refund_order as $arena_refund_data) {
                sort($arena_refund_data['data']['booking_type']['booking_details']);
                $logs['arena_request'.$i.'_'.date('H:i:s')]=$arena_refund_data;
                $notified_at = date('Y-m-d H:i:s');
                $arena_response = $this->arena_refund_listener($arena_refund_data);
                $logs['arena_response'.$i.'_'.date('H:i:s')]=$arena_response;
                if ($arena_response == 202) {
                    $arena_response_status = "No error";
                } else {
                    $arena_response_status = "Error";
                }

                $notify_to_third_parties = array(
                    "third_party_id" => 24,
                    "visitor_group_no" => $arena_refund_data['data']['booking_reference'],
                    "status_code" => $arena_response,
                    "response_status" => $arena_response_status,
                    "notified_at" => $notified_at,
                    "action_performed" => "cancel_booking",
                    "channel_type" => $channel_type,
                    "created_date_time" => date('Y-m-d H:i:s')
                );
                $logs['notify_to_third_parties_data'.$i.'_'.date('H:i:s')] = $notify_to_third_parties;
                $this->db->insert('notify_to_third_parties', $notify_to_third_parties);
                $i++;
            }
        }
        
        $MPOS_LOGS['update_visitor_for_partial_cancel_orders']=$logs;
    }
    
    /**
     * @Name        : arena_refund_listener() 
     * @Purpose     : notify arena proxy listener for tickets refund.
     * @Working     : call arena proxy listener api.
     * @Params      :
     * $arena_refund_data  : refund data
     * @Created by  : Komal garg <komalgarg.intersoft@gmail.com> on Jul 8, 2019
     */
    function arena_refund_listener ($arena_refund_data = array() ) {
        if(!empty($arena_refund_data) ) {            
            $url = ARENA_SERVER_URL;
            $token = ARENA_API_KEY_TOKEN;
            
            $request_identifier = 'PRIO_'.$arena_refund_data['data']['distributor_id'].'_'.time();
            $authentication_string = $request_identifier.':'.$token;
            $request_authentication = base64_encode(hash('sha256', utf8_encode($authentication_string), TRUE));
            /* SEND REQUEST TO API */
            $request_headers = array(
                'Content-Type: application/json',
                'x-request-authentication: '.$request_authentication,
                'x-request-identifier: '.$request_identifier,
            );
            $ch = curl_init();
            if (count($arena_refund_data)) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arena_refund_data));
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
            curl_exec($ch);
            $curl_info = curl_getinfo($ch);
            curl_close($ch);
            return $curl_info['http_code'];
        }
    }
    
    /**
     * @Name           : insert_in_hto
     * @Purpose        : to save data in hotel_ticket_overview table.
     * @Params         : $data: array which is needed to insert record.
     * @Created By     : komal <komalgarg.intersoft@gmail.com> ON May 31, 2019
     */
    function insert_in_hto($data = array(), $types_data = array()) {
        global $MPOS_LOGS;
        $count = 1;
        $ticket_id = $data['ticket_id'];
        $insertData['id'] = $data['visitor_group_no'] . str_pad($count, 3, "0", STR_PAD_LEFT);
        $insertData['createdOn'] = strtotime($data['current_date_time']);
        $insertData['updatedOn'] = strtotime($data['current_date_time']);
        $insertData['visitor_group_no_old'] = $data['visitor_group_no'];
        $insertData['hotel_id'] = $data['distributor_id'];
        $insertData['channel_id'] = $data['distributor_detail']['channel_id'];
        $insertData['hotel_name'] = $data['distributor_detail']['company'];
        $insertData['partner_name'] = $data['distributor_detail']['partner_name'];
        $insertData['activation_type'] = '0';
        $insertData['activation_method'] = '14';
        $insertData['parentPassNo'] = $data['ticket_code'];
        $insertData['passNo'] = $data['ticket_code'];
        $insertData['roomNo'] = '';
        $insertData['createdOnByGuest'] = strtotime($data['current_date_time']);
        $insertData['gender'] = 'Male';
        $insertData['user_image'] = $this->base_url . '/assets/images/no_upload1.png';
        $insertData['amount'] = (!empty($types_data)) ? $types_data[0]['ticket_gross_price_amount'] : $data['ticket_gross_price_amount'];
        $insertData['nights'] = 0;
        $insertData['updatedBy'] = $data['dist_cashier_id'];
        $insertData['uid'] = $data['dist_cashier_id'];
        $insertData['voucher_updated_by'] = $data['dist_cashier_id'];
        $insertData['host_name'] = $data['dist_cashier_name'];
        $insertData['distributor_type'] = $data['distributor_detail']['distributor_type'];
        $insertData['expectedCheckoutTime'] = strtotime(date('Y-m-d 23:59:59', strtotime(' +1 days'))) - $data['timezone_in_seconds'];
        $insertData['voucher_creation_date'] = $data['current_date_time'];
        $insertData['guest_names'] = isset($data['guest_name']) ? $data['guest_name'] : ''; 
        $insertData['receiptEmail'] = '';
        $insertData['visitor_group_no'] = $data['visitor_group_no'];
        $insertData['isBillToHotel'] = "0";
        $insertData['creditcard_group_no'] = 0;
        $insertData['shopperReference'] = '';
        $insertData['merchantReference'] = '';
        $insertData['merchantAccountCode'] = '';
        $insertData['guest_emails'] = '';
        $insertData['authResult'] = '';
        $insertData['card_name'] = '';
        $insertData['card_number'] = '';
        $insertData['pspReference'] = '';
        $insertData['timezone'] = $data['timezone'];
        $insertData['client_reference'] = isset($data['thirdparty_response']['data']['third_party_parameters']['ClientReference']) ? $data['thirdparty_response']['data']['third_party_parameters']['ClientReference'] : ''; 
        $insertData['without_elo_reference_no'] = isset($data['thirdparty_response']['data']['third_party_parameters']['ClientReference']) ? $data['thirdparty_response']['data']['third_party_parameters']['ClientReference'] : ''; 
        $insertData['hotel_checkout_status'] = '0';
        $insertData['paymentStatus'] = '1';
        $insertData['paymentMethod'] = '';
        $insertData['additional_information'] = '';
        $insertData['user_age'] = 0;
        $insertData['is_pass_for_combi_ticket'] = 0;
        $insertData['is_prioticket'] = '0';
        $insertData['is_order_from_mobile_app'] = 1;
        $insertData['is_order_updated'] = 1;
        $insertData['quantity'] = 1;
        $insertData['total_price'] = $types_data[0]['ticket_gross_price_amount'];
        $insertData['total_net_price'] = $types_data[0]['total_net_price_hto'];
        $insertData['ticket_ids'] = $ticket_id;
        $insertData['product_type'] = 0;
        $insertData['isprepaid'] = '1';
        $insertData['channel_type'] = $data['channel_type'];
        $insertData['tp_payment_method'] = '0';
        $logs['hto_data_'.date('H:i:s')] = json_encode($insertData);
        $hotel_ticket_overview_data[] = $insertData;
        $this->db->insert('hotel_ticket_overview', $insertData);
        $this->db->last_query();
        $MPOS_LOGS['insert_in_hto']=$logs;
        return $hotel_ticket_overview_data;
    }
    
    /**
     * @Name           : insert_in_pt_vt
     * @Purpose        : to save data in prepaid_tickets table, and in secondary db(pt,vt using aws queue), redeem_cashiers_details table(using SCANING_ACTION_URL url).
     * @Params         : $data: array which is needed to insert record, $types_data :supplier prices.
     * @Created By     : komal <komalgarg.intersoft@gmail.com> ON May 31, 2019
     */
    function insert_in_pt_vt($data = array(), $types_data = array()) {
        global $MPOS_LOGS;
        $logs['pt_vt_data_'.date('H:i:s')] = $data;
        $logs['supplier_data_'.date('H:i:s')] = $types_data;
        $count = 1;
        $pt_data = $this->primarydb->db->select("prepaid_ticket_id, visitor_group_no, passNo,ticket_id, selected_date, from_time,  to_time, ticket_booking_id")->from("prepaid_tickets")->where("visitor_group_no", $data['visitor_group_no'])->get()->result_array();
        $max_pt_id = 0;
        foreach($pt_data as $val) {
            $ticketBookingId[$val['visitor_group_no']."_".$val['passNo']."_".$val['ticket_id']."_".$val['selected_date']."_".$val['from_time']."_".$val['to_time']] = $val['ticket_booking_id'];
            if($val['prepaid_ticket_id'] > $max_pt_id) {
                $max_pt_id = $val['prepaid_ticket_id'];
            }
        }
        $logs['pt_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
        $logs['pt_data_'.date('H:i:s')] = json_encode($pt_data);
        if (!empty($pt_data)) {
            $pt_max_id_last_three_digit = substr($max_pt_id, -3);
            $last_three_count = $pt_max_id_last_three_digit + 1;
        } else {
            $last_three_count = 001;
        }
        foreach ($types_data as $type_data) {
            for ($i = 0; $i < $type_data['quantity']; $i++) {
                $prepaid_selected_data = array();
                $last_three_new_count = str_pad($last_three_count, 3, "0", STR_PAD_LEFT);
                $pt_id = $data['visitor_group_no'] . $last_three_new_count;
                $last_three_count++;
                $tps_id = $type_data['ticketpriceschedule_id'];
                $ticket_id = $data['ticket_id'];
                $ticket = $data['ticket_data'];
                //update shared_capacity_id
                $prepaid_shared_capacity_id = 0;
                if ($ticket['own_capacity_id'] > 0 && $ticket['shared_capacity_id'] > 0) {
                    $prepaid_shared_capacity_id = $ticket['shared_capacity_id'] . ', ' . $ticket['own_capacity_id'];
                } else if ($ticket['own_capacity_id'] > 0 && $ticket['shared_capacity_id'] == 0) {
                    $prepaid_shared_capacity_id = $ticket['own_capacity_id'];
                } else {
                    $prepaid_shared_capacity_id = $ticket['shared_capacity_id'];
                }
                $checkKey = implode("_", array($data['visitor_group_no'], $data['ticket_code'], $ticket_id, $ticket['selected_date'], $ticket['from_tim'], $ticket['to_time']));
                if(isset($ticketBookingId[$checkKey])) {
                    $ticket_booking_id = $ticketBookingId[$checkKey];
                }
                else {
                    /* get_ticket booking_id VGN plus 5 length random alphanumeric value */
                    $ticket_booking_id = $this->get_ticket_booking_id($data['visitor_group_no']);
                    $ticketBookingId[$checkKey] = $ticket_booking_id;
                }
                $pt_ids[] = $prepaid_selected_data['prepaid_ticket_id'] = $pt_id;
                $prepaid_selected_data['ticket_booking_id'] = $ticket_booking_id;
                $prepaid_selected_data['visitor_tickets_id'] = $pt_id . '01';
                $prepaid_selected_data['created_date_time'] = $data['current_date_time'];
                $prepaid_selected_data['hotel_ticket_overview_id'] = $data['visitor_group_no'] . str_pad($count, 3, "0", STR_PAD_LEFT);
                $prepaid_selected_data['hotel_id'] = $data['distributor_id'];
                $prepaid_selected_data['cashier_id'] = $data['dist_cashier_id'];
                $prepaid_selected_data['cashier_name'] = $data['dist_cashier_name'];
                $prepaid_selected_data['hotel_name'] = $data['distributor_detail']['company'];
                $prepaid_selected_data['pax'] = isset($type_data['adjust_capacity']) ? $type_data['pax'] : '0';
                $prepaid_selected_data['capacity'] = isset($type_data['adjust_capacity']) ? $type_data['adjust_capacity'] : '';
                $prepaid_selected_data['age_group'] = $type_data['agefrom'] . '-' . $$type_data['ageto'];
                $prepaid_selected_data['museum_id'] = $ticket['museum_id'];
                $prepaid_selected_data['reseller_id'] = $data['distributor_detail']['reseller_id'];
                $prepaid_selected_data['reseller_name'] = $data['distributor_detail']['reseller_name'];
                $prepaid_selected_data['distributor_partner_id'] = $data['distributor_detail']['partner_id'];
                $prepaid_selected_data['distributor_partner_name'] = $data['distributor_detail']['partner_name'];
                $prepaid_selected_data['museum_name'] = $ticket['museum_name'];
                $prepaid_selected_data['visitor_group_no'] = $data['visitor_group_no'];
                $prepaid_selected_data['title'] = $ticket['postingEventTitle'];
                $prepaid_selected_data['additional_information'] = $ticket['description'];
                $prepaid_selected_data['pass_type'] = $ticket['barcode_type'];
                $prepaid_selected_data['price'] = $type_data['ticket_gross_price_amount'];
                $prepaid_selected_data['discount'] = $type_data['saveamount'];
                $prepaid_selected_data['tax'] = $type_data['ticket_tax_value'];
                $prepaid_selected_data['tax_name'] = $this->common_model->getSingleFieldValueFromTable('tax_name', 'store_taxes', array('tax_value' => $prepaid_selected_data['tax'], 'tax_type' => '0'));
                $prepaid_selected_data['net_price'] = $prepaid_selected_data['price'] - ($prepaid_selected_data['price'] * $prepaid_selected_data['tax']) / ($prepaid_selected_data['tax'] + 100);
                $prepaid_selected_data['ticket_id'] = $ticket_id;
                $prepaid_selected_data['tps_id'] = $tps_id;
                $prepaid_selected_data['ticket_type'] = ucfirst(strtolower($type_data['ticket_type']));
                $prepaid_selected_data['passNo'] = $data['ticket_code'];
                $prepaid_selected_data['guest_names'] = isset($data['guest_name']) ? $data['guest_name'] : '';
                $prepaid_selected_data['without_elo_reference_no'] = isset($data['thirdparty_response']['data']['third_party_parameters']['ClientReference']) ? $data['thirdparty_response']['data']['third_party_parameters']['ClientReference'] : '';
                $prepaid_selected_data['quantity'] = 1;
                $prepaid_selected_data['selected_date'] = $selected_date = $ticket['selected_date'];
                $prepaid_selected_data['from_time'] = $from_time = $ticket['from_time'];
                $prepaid_selected_data['to_time'] = $to_time = $ticket['to_time'];
                $prepaid_selected_data['timeslot'] = $timeslot = $ticket['timeslot'];
                $prepaid_selected_data['booking_selected_date'] = $ticket['booking_selected_date'];
                $prepaid_selected_data['timezone'] = $data['timezone'];
                $prepaid_selected_data['is_addon_ticket'] = $prepaid_selected_data['tp_payment_method'] = $prepaid_selected_data['is_combi_ticket'] = $prepaid_selected_data['is_combi_discount'] = $prepaid_selected_data['combi_discount_gross_amount'] = $prepaid_selected_data['pass_type'] = $prepaid_selected_data['is_prioticket'] = '0';
                $prepaid_selected_data['activation_method'] = '14';
                $prepaid_selected_data['channel_type'] = $data['channel_type'];
                $prepaid_selected_data['booking_status'] = $prepaid_selected_data['is_order_confirmed'] = $prepaid_selected_data['used'] = '1';
                $prepaid_selected_data['action_performed'] = $data['action_performed'];
                $prepaid_selected_data['scanned_at'] = strtotime($data['current_date_time']);
                $prepaid_selected_data['supplier_price'] = $type_data['supplier_price'];
                $prepaid_selected_data['third_party_response_data'] = json_encode($data['thirdparty_response']);
                $prepaid_selected_data['shared_capacity_id'] = $prepaid_shared_capacity_id;
                $prepaid_selected_data['redeem_date_time'] = $data['current_date_time'];
                $prepaid_selected_data['museum_cashier_id'] = isset($data['museum_cashier_id']) ? $data['museum_cashier_id'] : 0;
                $prepaid_selected_data['museum_cashier_name'] = isset($data['museum_cashier_name']) ? $data['museum_cashier_name'] : '';
                /* insert record in prepaid_tickets table */
                $this->db->insert('prepaid_tickets', $prepaid_selected_data);
                $logs['pt_insert_data_' . date('H:i:s')] = $this->db->last_query();
                $prepaid_selected_data['updated_at'] = $data['current_date_time'];
                $prepaid_selected_data['channel_id'] = $data['distributor_detail']['channel_id'];
                $prepaid_selected_data['channel_name'] = $data['distributor_detail']['channel_name'];
                $prepaid_selected_data['saledesk_id'] = $data['distributor_detail']['saledesk_id'];
                $prepaid_selected_data['saledesk_name'] = $data['distributor_detail']['saledesk_name'];
                $prepaid_selected_data['supplier_tax'] = $type_data['supplier_tax'];
                $prepaid_selected_data['supplier_net_price'] = $type_data['supplier_net_price'];
                $prepaid_selected_data['order_confirm_date'] = $data['current_date_time'];
                $prepaid_selected_data['location'] = '';
                $prepaid_selected_data['image'] = $ticket['image'];
                $prepaid_selected_data['highlights'] = $ticket['highlights'];
                $prepaid_selected_data['oroginal_price'] = $type_data['pricetext'];
                $prepaid_selected_data['ticket_scan_price'] = $type_data['ticket_gross_price_amount'];
                $prepaid_selected_data['created_at'] = strtotime($data['current_date_time']);
                $prepaid_selected_data['supplier_currency_code'] = $data['supplier_detail']['currency_code'];
                $prepaid_selected_data['supplier_currency_symbol'] = $this->common_model->getCurrencySymbolFromHexCode($data['supplier_detail']['hex_code']);
                $prepaid_selected_data['order_currency_code'] = $data['distributor_detail']['currency_code'];
                $prepaid_selected_data['order_currency_symbol'] = $this->common_model->getCurrencySymbolFromHexCode($data['distributor_detail']['hex_code']);
                $prepaid_selected_data['is_pre_selected_ticket'] = 1;
                $prepaid_selected_data['pos_point_id_on_redeem'] = $data['pos_point_id'];
                $prepaid_selected_data['pos_point_name_on_redeem'] = $data['pos_point_name'];
                $prepaid_selected_data['distributor_id_on_redeem'] = isset($data['pos_point_id']) ? $data['distributor_id'] : 0;
                $prepaid_selected_data['distributor_cashier_id_on_redeem'] = $data['dist_cashier_id'];
                $prepaid_selected_data['supplier_original_price'] = $type_data['supplier_original_price'];
                $prepaid_selected_data['supplier_discount'] = $type_data['supplier_discount'];
                $prepaid_selected_data['voucher_updated_by'] = isset($data['museum_cashier_id']) ? $data['museum_cashier_id'] : 0;
                $prepaid_selected_data['voucher_updated_by_name'] = isset($data['museum_cashier_name']) ? $data['museum_cashier_name'] : '';
                $prepaid_selected_data['financial_id'] = $data['financial_details']['financial_id'];
                $prepaid_selected_data['financial_name'] = $data['financial_details']['financial_name'];
                $prepaid_selected_data['voucher_creation_date'] = $data['current_date_time'];

                $prepaid_tickets_data[] = $prepaid_selected_data;
                //sync firebase, redis and ticket_capacity_v1 for reservation ticket
                if ($ticket['ticket_class'] == '1' && $from_time != '' && $to_time != '' && $timeslot != '') {
                    $logs['updatye_capacity_called_with_' . date('H:i:s')] = array('ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => 1, 'timeslot' => $timeslot);
                    $this->update_capacity($ticket_id, $selected_date, $from_time, $to_time, 1, $timeslot);
                }
            }
        }
        
        if (!empty($data['hotel_ticket_overview_data'])) {
            $aws_data['hotel_ticket_overview_data'] = $data['hotel_ticket_overview_data'];
        } else {
            $aws_data['hotel_ticket_overview_data'] = array();
        }
        $aws_data['add_to_prioticket'] = '1';
        $aws_data['shop_products_data'] = array();
        $aws_data['prepaid_extra_options_data'] = array();
        $aws_data['prepaid_tickets_data'] = $prepaid_tickets_data;
        $aws_data['cc_row_data'] = array();
        $logs['send_to_db2 on'] = date('H:i:s');
        $this->order_process_model->insertdata($aws_data);
        if( SYNCH_WITH_RDS_REALTIME ) {
            $this->order_process_model->insertdata($aws_data, 0, 4);
        }
        
        
        /* update total_prices,qunatity in hto table */
        if(!empty($data['hto_record'])){
            $this->db->query('update hotel_ticket_overview set total_price = "'.($data['hto_record']['total_price'] + $prepaid_selected_data['price']).'", total_net_price = "'.($data['hto_record']['total_net_price'] + $prepaid_selected_data['net_price']).'", quantity = "'.($data['hto_record']['quantity'] + 1).'", ticket_ids = CONCAT(ticket_ids, ", '.$prepaid_selected_data['ticket_id'].'") where visitor_group_no = "'.$data['visitor_group_no'].'"');
            $this->secondarydb->db->query('update hotel_ticket_overview set total_price = "'.($data['hto_record']['total_price'] + $prepaid_selected_data['price']).'", total_net_price = "'.($data['hto_record']['total_net_price'] + $prepaid_selected_data['net_price']).'", quantity = "'.($data['hto_record']['quantity'] + 1).'", ticket_ids = CONCAT(ticket_ids, ", '.$prepaid_selected_data['ticket_id'].'") where visitor_group_no = "'.$data['visitor_group_no'].'"');
            if( SYNCH_WITH_RDS_REALTIME ) {
                $this->fourthdb->db->query('update hotel_ticket_overview set total_price = "'.($data['hto_record']['total_price'] + $prepaid_selected_data['price']).'", total_net_price = "'.($data['hto_record']['total_net_price'] + $prepaid_selected_data['net_price']).'", quantity = "'.($data['hto_record']['quantity'] + 1).'", ticket_ids = CONCAT(ticket_ids, ", '.$prepaid_selected_data['ticket_id'].'") where visitor_group_no = "'.$data['visitor_group_no'].'"');
            }
            $logs['hto_query_'.date('H:i:s')] = $this->db->last_query();
        }  
        
        $update_redeem_table = array();
        $update_redeem_table['visitor_group_no'] = $data['visitor_group_no'];
        $update_redeem_table['prepaid_ticket_ids'] = $pt_ids;
        $update_redeem_table['museum_cashier_id'] = $data['museum_cashier_id'];
        $update_redeem_table['redeem_date_time'] = $data['current_date_time'];
        $update_redeem_table['museum_cashier_name'] = $data['museum_cashier_name'];
        $update_redeem_table['hotel_id'] = $data['distributor_id'];
        $this->load->model('venue_model');
        $this->venue_model->update_redeem_table($update_redeem_table);
        $logs['data_to_update_redeem_table'] = $update_redeem_table;
        $MPOS_LOGS['insert_in_pt_vt']=$logs;
    }
    
    /**
     * @name   : refund_prepaid_orders()
     * @purpose: update prepaid_tickets table on refund
     * @where  : It is called from pos.php and scaning_action.php
     * @returns: No parameter is returned
     * @createdBy: komal <komal.intersoft@gmail.com> on 16 Dec 2017
     */
    function refund_prepaid_orders($refund_prepaid_orders = array(), $is_mpos_order_refund = 0 , $update_status_query="") {
        global $MPOS_LOGS;
        foreach ($refund_prepaid_orders as $key => $prepaid_order) {
            $channel_type = $prepaid_order['channel_type'];
            $visitor_group_no = $prepaid_order['visitor_group_no'];
            $pt_ids[$key] = $key;
        }
        $this->CreateLog('refund_prepaid_orders_call.txt', "step1", array( 'visitor_group_no : ' => $visitor_group_no ) ); 
        $prepaid_table = 'prepaid_tickets';

        if ($channel_type == '10' || $channel_type == '11' || $channel_type == '13') {
            $this->CreateLog('refund.php', 'ids', array(json_encode($pt_ids)));
            $pt_db2_data = $this->find($prepaid_table, array('select' => '*', 'where' => 'prepaid_ticket_id IN (' . implode(',', array_keys($pt_ids)) . ')'), 'array', '1');
            $this->CreateLog('refund.php', 'query ' . $this->secondarydb->rodb->last_query(), array());

            $pt_db2_data = $this->get_max_version_data($pt_db2_data, 'prepaid_ticket_id');

            $this->CreateLog('refund.php', 'query_data ' . json_encode($pt_db2_data), array());

            foreach ($pt_db2_data as $data) {
                $pt_fields_update[$data['prepaid_ticket_id']] = array(
                    'museum_net_fee' => $data['museum_net_fee'],
                    'related_product_title' => $data['related_product_title'],
                    'market_merchant_id' => $data['market_merchant_id'],
                    'booking_details' => $data['booking_details'],
                    'booking_information' => $data['booking_information'],
                    'order_currency_code' => $data['order_currency_code'],
                    'location_code' => $data['location_code'],
                    'is_order_confirmed' => $data['is_order_confirmed'],
                    'dist_currency_discount' => $data['dist_currency_discount'],
                    'dist_currency_oroginal_price' => $data['dist_currency_oroginal_price'],
                    'dist_currency_price' => $data['dist_currency_price'],
                    'dist_currency_net_price' => $data['dist_currency_net_price'],
                    'dist_currency_combi_Discount_gross_amount' => $data['dist_currency_combi_Discount_gross_amount'],
                    'dist_currency_rate' => $data['dist_currency_rate'],
                    'total_fixed_amount' => $data['total_fixed_amount'],
                    'currency_based_price' => $data['currency_based_price'],
                    'currency_based_net_price' => $data['currency_based_net_price'],
                    'tax_exception_applied' => $data['tax_exception_applied'],
                    'tax_id' => $data['tax_id'],
                    'supplier_cost' => $data['supplier_cost'],
                    'partner_cost' => $data['partner_cost'],
                    'native_financial_company_id' => $data['native_financial_company_id'],
                    'platform_financial_company_id' => $data['platform_financial_company_id'],
                    'order_currency_discount' => $data['order_currency_discount'],
                    'order_currency_price' => $data['order_currency_price'],
                    'ticket_scan_price' => $data['ticket_scan_price'],
                    'supplier_original_price' => $data['supplier_original_price'],
                    'supplier_currency_symbol' => $data['supplier_currency_symbol'],
                    'supplier_currency_code' => $data['supplier_currency_code'],
                    'order_currency_symbol' => $data['order_currency_symbol'],
                    'hgs_net_fee' => $data['hgs_net_fee'],
                    'distributor_net_fee' => $data['distributor_net_fee'],
                    'museum_gross_fee' => $data['museum_gross_fee'],
                    'hgs_gross_fee' => $data['hgs_gross_fee'],
                    'distributor_gross_fee' => $data['distributor_gross_fee'],
                    'merchant_admin_id' => $data['merchant_admin_id'],
                    'channel_id' => $data['channel_id'],
                    'channel_name' => $data['channel_name'],
                    'saledesk_id' => $data['saledesk_id'],
                    'saledesk_name' => $data['saledesk_name'],
                    'oroginal_price' => isset($data['oroginal_price']) ? $data['oroginal_price'] : "0",
                    'order_currency_extra_discount' => $data['order_currency_extra_discount'],
                    'service_cost_type' => $data['service_cost_type'],
                    'service_cost' => $data['service_cost'],
                    'net_service_cost' => $data['net_service_cost'],
                    'pos_point_id_on_redeem' => $data['pos_point_id_on_redeem'],
                    'pos_point_name_on_redeem' => $data['pos_point_name_on_redeem'],
                    'distributor_id_on_redeem' => $data['distributor_id_on_redeem'],
                    'distributor_cashier_id_on_redeem' => $data['distributor_cashier_id_on_redeem'],
                    'supplier_tax' => $data['supplier_tax'],
                    'supplier_net_price' => $data['supplier_net_price'],
                    'voucher_updated_by' => $data['voucher_updated_by'],
                    'voucher_updated_by_name' => $data['voucher_updated_by_name'],
                    'redeem_method' => $data['redeem_method'],
                    'financial_id' => $data['financial_id'],
                    'financial_name' => $data['financial_name'],
                    'order_cancellation_date' => $data['order_cancellation_date'],
                    'secondary_guest_name' => $data['secondary_guest_name'],
                    'secondary_guest_email' => $data['secondary_guest_email'],
                    'version' => $data['version']
                );
            }
            $this->CreateLog('refund.php', 'data', array(json_encode($pt_fields_update)));
            $max_prepaid_id = '';
            $prepaid_data = reset($this->find('prepaid_tickets', array('select' => 'created_date_time, max(prepaid_ticket_id) as max_prepaid_id', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" ')));
            if (date('Y-m-d', strtotime($prepaid_data['created_date_time'])) >= '2018-07-12') {
                $max_prepaid_id = (string) $prepaid_data['max_prepaid_id'];
            }
        }
        $i = 0;

        $total_price = 0;
        foreach ($refund_prepaid_orders as $pid => $prepaid_order) {
            if (($channel_type == '10' || $channel_type == '11' || $channel_type == '13') && $max_prepaid_id != '') {
                $prepaid_order['prepaid_ticket_id'] = ++$max_prepaid_id;
                if ($prepaid_order['is_addon_ticket'] == '2') {
                    $prepaid_order['visitor_tickets_id'] = $prepaid_order['prepaid_ticket_id'] . '02';
                } else {
                    $transaction_id[$prepaid_order['clustering_id']] = $prepaid_order['prepaid_ticket_id'];
                    $prepaid_order['visitor_tickets_id'] = $prepaid_order['prepaid_ticket_id'] . '01';
                }
                $update_ids_in_vt[$prepaid_order['visitor_group_no'] . '_' . $prepaid_order['ticket_id'] . '_' . $prepaid_order['tps_id'] . '_' . $prepaid_order['passNo']][] = array('prepaid_ticket_id' => $prepaid_order['prepaid_ticket_id'], 'visitor_tickets_id' => $prepaid_order['visitor_tickets_id'], 'transaction_id' => $transaction_id[$prepaid_order['clustering_id']], 'is_addon_ticket' => $prepaid_order['is_addon_ticket']);
            }
            if (($channel_type == '10' || $channel_type == '11' || $channel_type == '13') && !empty($pt_fields_update)) {
                $museum_net_fee = $pt_fields_update[$pid]['museum_net_fee'];
                $market_merchant_id = $pt_fields_update[$pid]['market_merchant_id'];
                $booking_details = $pt_fields_update[$pid]['booking_details'];
                $booking_information = $pt_fields_update[$pid]['booking_information'];
                $order_currency_code = $pt_fields_update[$pid]['order_currency_code'];
                $location_code = $pt_fields_update[$pid]['location_code'];
                $is_order_confirmed = $pt_fields_update[$pid]['is_order_confirmed'];
                $dist_currency_oroginal_price = $pt_fields_update[$pid]['dist_currency_oroginal_price'];
                $dist_currency_discount = $pt_fields_update[$pid]['dist_currency_discount'];
                $dist_currency_price = $pt_fields_update[$pid]['dist_currency_price'];
                $dist_currency_net_price = $pt_fields_update[$pid]['dist_currency_net_price'];
                $dist_currency_combi_Discount_gross_amount = $pt_fields_update[$pid]['dist_currency_combi_Discount_gross_amount'];
                $dist_currency_rate = $pt_fields_update[$pid]['dist_currency_rate'];
                $total_fixed_amount = $pt_fields_update[$pid]['total_fixed_amount'];
                $currency_based_price = $pt_fields_update[$pid]['currency_based_price'];
                $currency_based_net_price = $pt_fields_update[$pid]['currency_based_net_price'];
                $tax_id = $pt_fields_update[$pid]['tax_id'];
                $tax_exception_applied = $pt_fields_update[$pid]['tax_exception_applied'];
                $supplier_cost = $pt_fields_update[$pid]['supplier_cost'];
                $partner_cost = $pt_fields_update[$pid]['partner_cost'];
                $native_financial_company_id = $pt_fields_update[$pid]['native_financial_company_id'];
                $platform_financial_company_id = $pt_fields_update[$pid]['platform_financial_company_id'];
                $order_currency_discount = $pt_fields_update[$pid]['order_currency_discount'];
                $order_currency_price = $pt_fields_update[$pid]['order_currency_price'];
                $ticket_scan_price = $pt_fields_update[$pid]['ticket_scan_price'];
                $supplier_original_price = $pt_fields_update[$pid]['supplier_original_price'];
                $supplier_currency_symbol = $pt_fields_update[$pid]['supplier_currency_symbol'];
                $supplier_currency_code = $pt_fields_update[$pid]['supplier_currency_code'];
                $order_currency_symbol = $pt_fields_update[$pid]['order_currency_symbol'];
                $hgs_net_fee = $pt_fields_update[$pid]['hgs_net_fee'];
                $distributor_net_fee = $pt_fields_update[$pid]['distributor_net_fee'];
                $museum_gross_fee = $pt_fields_update[$pid]['museum_gross_fee'];
                $hgs_gross_fee = $pt_fields_update[$pid]['hgs_gross_fee'];
                $distributor_gross_fee = $pt_fields_update[$pid]['distributor_gross_fee'];
                $refund_merchant_admin_id = $pt_fields_update[$pid]['merchant_admin_id'];
                $channel_id = $pt_fields_update[$pid]['channel_id'];
                $channel_name = $pt_fields_update[$pid]['channel_name'];
                $saledesk_id = $pt_fields_update[$pid]['saledesk_id'];
                $related_product_title = $pt_fields_update[$pid]['related_product_title'];
                $saledesk_name = $pt_fields_update[$pid]['saledesk_name'];
                $oroginal_price = isset($pt_fields_update[$pid]['oroginal_price']) ? $pt_fields_update[$pid]['oroginal_price'] : 0;
                $order_currency_extra_discount = $pt_fields_update[$pid]['order_currency_extra_discount'];
                $service_cost_type = $pt_fields_update[$pid]['service_cost_type'];
                $service_cost = $pt_fields_update[$pid]['service_cost'];
                $net_service_cost = $pt_fields_update[$pid]['net_service_cost'];
                $pos_point_id_on_redeem = $pt_fields_update[$pid]['pos_point_id_on_redeem'];
                $pos_point_name_on_redeem = $pt_fields_update[$pid]['pos_point_name_on_redeem'];
                $distributor_id_on_redeem = $pt_fields_update[$pid]['distributor_id_on_redeem'];
                $distributor_cashier_id_on_redeem = $pt_fields_update[$pid]['distributor_cashier_id_on_redeem'];
                $supplier_tax = $pt_fields_update[$pid]['supplier_tax'];
                $supplier_net_price = $pt_fields_update[$pid]['supplier_net_price'];
                $voucher_updated_by = $pt_fields_update[$pid]['voucher_updated_by'];
                $voucher_updated_by_name = $data['voucher_updated_by_name'];
                $redeem_method = $pt_fields_update[$pid]['redeem_method'];
                $financial_id = $pt_fields_update[$pid]['financial_id'];
                $financial_name = $pt_fields_update[$pid]['financial_name'];
                $order_cancellation_date = $pt_fields_update[$pid]['order_cancellation_date'];
                $secondary_guest_email = $pt_fields_update[$pid]['secondary_guest_email'];
                $secondary_guest_name = $pt_fields_update[$pid]['secondary_guest_name'];
                $version = $pt_fields_update[$pid]['version'];
            }

            $start_amount = $prepaid_order['start_amount'];
            unset($prepaid_order['start_amount']);
//            $prepaid_order_db1 = $this->set_unset_array_values($prepaid_order);
//            if (in_array($prepaid_order_db1['hotel_id'], json_decode(EXPEDIA_HOTELS, true))) {
//                $expedia_prepaid_data = $this->set_unset_expedia_prepaid_columns($prepaid_order);
//                $this->db->insert('expedia_prepaid_tickets', $expedia_prepaid_data);
//            }
            $prepaid_order_db1 = $this->set_unset_array_values($prepaid_order);
            $this->db->insert('prepaid_tickets', $prepaid_order_db1);

            if (($channel_type == '10' || $channel_type == '11') && !empty($pt_fields_update)) {
                unset($prepaid_order['market_merchant_id']);
            }
            $update_cashier_register_data['activation_method'] = $prepaid_order['activation_method'];
            if ($prepaid_order['is_addon_ticket'] == '0') {
                $total_price += $prepaid_order['price'];
            }
            $update_cashier_register_data['hotel_id'] = $prepaid_order['hotel_id'];
            $update_cashier_register_data['cashier_id'] = $prepaid_order['cashier_id'];
            $update_cashier_register_data['shift_id'] = $prepaid_order['shift_id'];
            $update_cashier_register_data['pos_point_id'] = $prepaid_order['pos_point_id'];
            $update_cashier_register_data['pos_point_name'] = $prepaid_order['pos_point_name'];
            $update_cashier_register_data['timezone'] = $prepaid_order['timezone'];
            $update_cashier_register_data['hotel_name'] = $prepaid_order['hotel_name'];
            $update_cashier_register_data['cashier_name'] = $prepaid_order['cashier_name'];
            if (($channel_type == '10' || $channel_type == '11') && !empty($pt_fields_update)) {
                $prepaid_order['market_merchant_id'] = $market_merchant_id;
                $prepaid_order['order_currency_code'] = $order_currency_code;
                $prepaid_order['location_code'] = $location_code;
                $prepaid_order['is_order_confirmed'] = $is_order_confirmed;
                /*
                $prepaid_order['dist_currency_oroginal_price'] = $dist_currency_oroginal_price;
                $prepaid_order['dist_currency_discount'] = $dist_currency_discount;
                $prepaid_order['dist_currency_price'] = $dist_currency_price;
                $prepaid_order['dist_currency_net_price'] = $dist_currency_net_price;
                $prepaid_order['dist_currency_rate'] = $dist_currency_rate;
                $prepaid_order['total_fixed_amount'] = $total_fixed_amount;
                $prepaid_order['currency_based_price'] = $currency_based_price;
                $prepaid_order['currency_based_net_price'] = $currency_based_net_price;
                $prepaid_order['native_financial_company_id'] = $native_financial_company_id;
                $prepaid_order['platform_financial_company_id'] = $platform_financial_company_id;
                 */
                $prepaid_order['order_currency_discount'] = $order_currency_discount;
                $prepaid_order['order_currency_price'] = $order_currency_price;
                $prepaid_order['ticket_scan_price'] = $ticket_scan_price;
                $prepaid_order['supplier_original_price'] = $supplier_original_price;
                $prepaid_order['supplier_currency_symbol'] = $supplier_currency_symbol;
                $prepaid_order['supplier_currency_code'] = $supplier_currency_code;
                $prepaid_order['order_currency_symbol'] = $order_currency_symbol;
                $prepaid_order['museum_net_fee'] = $museum_net_fee;
                $prepaid_order['hgs_net_fee'] = $hgs_net_fee;
                $prepaid_order['distributor_net_fee'] = $distributor_net_fee;
                $prepaid_order['museum_gross_fee'] = $museum_gross_fee;
                $prepaid_order['hgs_gross_fee'] = $hgs_gross_fee;
                $prepaid_order['distributor_gross_fee'] = $distributor_gross_fee;
                $prepaid_order['merchant_admin_id'] = $refund_merchant_admin_id;
                $prepaid_order['channel_id'] = $channel_id;
                $prepaid_order['channel_name'] = $channel_name;
                $prepaid_order['saledesk_id'] = $saledesk_id;
                $prepaid_order['saledesk_name'] = $saledesk_name;
                $prepaid_order['oroginal_price'] = isset($oroginal_price) ? $oroginal_price : 0;
                $prepaid_order['order_currency_extra_discount'] = $order_currency_extra_discount;
                $prepaid_order['service_cost_type'] = isset($service_cost_type) ? $service_cost_type : 0;
                $prepaid_order['service_cost'] = isset($service_cost) ? $service_cost : 0;
                $prepaid_order['net_service_cost'] = isset($net_service_cost) ? $net_service_cost : 0;
                $prepaid_order['pos_point_id_on_redeem'] = $pos_point_id_on_redeem;
                $prepaid_order['pos_point_name_on_redeem'] = $pos_point_name_on_redeem;
                $prepaid_order['distributor_id_on_redeem'] = $distributor_id_on_redeem;
                $prepaid_order['distributor_cashier_id_on_redeem'] = $distributor_cashier_id_on_redeem;
                $prepaid_order['supplier_tax'] = $supplier_tax;
                $prepaid_order['supplier_net_price'] = $supplier_net_price;
                $prepaid_order['voucher_updated_by'] = $voucher_updated_by;
                $prepaid_order['voucher_updated_by_name'] = $voucher_updated_by_name;
                $prepaid_order['redeem_method'] = $redeem_method;
                $prepaid_order['financial_id'] = $financial_id;
                $prepaid_order['financial_name'] = $financial_name;
                $prepaid_order['order_cancellation_date'] = $order_cancellation_date;
                $prepaid_order['version'] = $version;
            }
            
            $secondary_prepaid_data = $prepaid_order;  // prepare array for insert in secondary DB.     
            $secondary_prepaid_data['prepaid_ticket_id'] = $this->db->insert_id();
            $this->secondarydb->db->insert($prepaid_table, $secondary_prepaid_data);
            $logs['update_pt_db2_' . $i . '_' . date('H:i:s')] = $this->secondarydb->db->last_query();
            // To update the data in RDS realtime
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                // unset($secondary_prepaid_data['extra_booking_information']);
                $this->fourthdb->db->insert('prepaid_tickets', $secondary_prepaid_data);
            }
            $i++;
        }

        $update_cashier_register_data['price'] = $total_price;
        $update_cashier_register_data['start_amount'] = $start_amount;
        $this->load->model('venue_model');
        if (($channel_type == '10' || $channel_type == '11' || $channel_type == '13') && $is_mpos_order_refund == 1) {
            $this->venue_model->update_cashier_register_data_on_cancel($update_cashier_register_data);
            $logs['update_cashier_register_data_query_' . date('H:i:s')] = $this->db->last_query();
        }
        $ref = 'update_in_db->refund_prepaid_orders';
        if (isset($update_status_query) && !empty($update_status_query)) {
            $this->secondarydb->db->query($update_status_query);
        }
        $MPOS_LOGS['refund_prepaid_orders'] = $logs;
    }
    
    /**
     * @Name           : insert_order_contact
     * @Purpose        : to save contact data in order_contacts table
     * @Params         : $contact_uid => contact_uid, $vgn => visitor group no, $date => order creation time
     * @Created By     : Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function insert_order_contact($contact_uid = "", $vgn = "", $date = "") {
        global $MPOS_LOGS;
        if ($contact_uid != "" && $vgn != "") {
            $guest_data = $this->find('guest_contacts', array(
                'select' => 'version', 
                'where' => 'contact_uid = "'.$contact_uid.'"',
                'order_by' => 'version desc'
                ), 'array', '1');
            $MPOS_LOGS['guest_contacts_query'] = $this->secondarydb->rodb->last_query();
            $MPOS_LOGS['guest_contacts_data'] = $guest_data;
            if(!empty($guest_data)) { //guest exists
                $contact_data['contact_uid'] = $contact_uid;
                $contact_data['contact_version'] = $guest_data[0]['version'];
                $contact_data['visitor_group_no'] = $vgn;
                $contact_data['created_at'] = ($date != "") ? $date : gmdate('Y-m-d H:i:s');
                $contact_data['updated_at'] = ($date != "") ? $date : gmdate('Y-m-d H:i:s');
                $this->secondarydb->db->insert("order_contacts", $contact_data);
                $MPOS_LOGS['order_contacts_query'] = $this->secondarydb->db->last_query();
            }
        }
    }

}
