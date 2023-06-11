<?php

namespace Prio\Traits\V1\Models;
use \Prio\helpers\V1\errors_helper;

trait TTrip_model {
      /**
     * @Name    : check_trip_status()
     * @Purpose : To check the current status of trip
     */
    public function check_trip_status($request_params = array()) {
        global $MPOS_LOGS;
        $logs = array();
        /* declaring variables */
        $response = $cashier_data = $cashiers_data = $payment_data = $payment_details = array();
        /* on getting request details from controller */
        if (!empty($request_params)) {
            /* getting Data from DB corresponding to distributor_id, chasier_id and shift_id */
            $this->db->select('cashier_register_id as cashierRegisterId, unbalance_status, opening_cash_balance as cash_start, sale_by_cash as cash_revenue, manual_cash_sale_amount as cash_in, manual_cash_sale_amount_note as cash_in_note, manual_deposit as cash_out, manual_deposit_note as cash_out_note, cashier_id, cashier_name, pos_point_id, pos_point_name, opening_time, closing_time, total_closing_balance as revenue, hotel_name, sale_by_card, mannual_closing_cash_balance as cash_end, mannual_closing_cash_balance_note, cash_count, cash_brought_forward, safety_deposit_total_amount, safety_deposit_total_amount_note, other_deposit, other_deposit_note, next_shift_deposit, next_shift_deposit_note, is_logged_out, cash_balance_open_for_next_cashier, shift_id, created_at, hotel_id, reseller_cashier_id');
            $this->db->from('cashier_register');
            $this->db->where("is_deleted != 1");
            $this->db->where('pos_point_name is NOT NULL', NULL, FALSE);
            $this->db->where_in('cashier_register_id', $request_params['cashierRegisterId']);
            $cashier_data = $this->db->get()->result_array();
            $logs['query_from_cashier_register'] = $this->db->last_query();
            /* check whether obtain any data from DB or not in DB */
            if (count($cashier_data) > 0) {
                foreach ($cashier_data as $data) {
                    $key = $data['shift_id'].$data['hotel_id'].$data['reseller_cashier_id'];
                    $cashiers_data[$key] = $data;
                    $shifts[] = $data['shift_id'];
                    $hotels[] = $data['hotel_id'];
                    $cashiers[] = $data['reseller_cashier_id'];
                }
                $secondarydb = $this->load->database('secondary', true);
                $MPOS_LOGS['DB'][] = 'DB2';
                $secondarydb->select('*');
                $secondarydb->from('order_payment_details');
                $secondarydb->where("partner_type", '2');
                $secondarydb->where_in('shift_id', array_unique($shifts));
                $secondarydb->where_in('distributor_id', array_unique($hotels));
                $secondarydb->where_in('cashier_id', array_unique($cashiers));
                $payment_data = $secondarydb->get()->result_array();
                $logs['query_from_order_payment_details'] = $secondarydb->last_query();
                $payment_data = $this->get_max_version_data($payment_data, 'id');
                $ticketids = array_unique(array_column($payment_data, 'ticket_id'));
                $tickets = array_filter($ticketids, function ($value) {
                    return !is_null($value) && $value !== '' && $value !== 'undefined';
                });
                if (!empty($tickets)) {
                    $this->db->select('mec_id, postingEventTitle');
                    $this->db->from('modeventcontent');
                    $this->db->where_in('mec_id', $tickets);
                    $ticketData = $this->db->get()->result_array();
                    $logs['query_from_modeventcontent'] = $this->db->last_query();
                    foreach ($ticketData as $row) {
                        $keys = array_keys($row);
                        $ticket_titles[$row[$keys[0]]] = $row[$keys[1]];
                    }
                }
                foreach($payment_data as $row) {
                    if($row['deleted'] == "0" && isset($cashiers_data[$row['shift_id'].$row['distributor_id'].$row['cashier_id']]) && !empty($cashiers_data[$row['shift_id'].$row['distributor_id'].$row['cashier_id']])) {
                        $payment_details = array();
                        $payment_details['payment_id'] = $row['id'];
                        $payment_details['order_amount'] = $row['order_amount'];
                        $payment_details['product_name'] = isset($ticket_titles[$row['ticket_id']]) ? $ticket_titles[$row['ticket_id']] : '';
                        $payment_details['product_id'] = $row['ticket_id'];
                        $payment_details['created_date'] = $row['created_at'];
                        $cashiers_data[$row['shift_id'].$row['distributor_id'].$row['cashier_id']]['supplier_payments'][] = $payment_details;
                    }
                }
                $response['data'] = array_values($cashiers_data);
            } else {
                /* preparing error for not effecting any row in DB */
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = 'M04:Provide valid data in request parameters';
            }
        } else {
            /* preparing error response for not receiving proper request */
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'M05:Request data is empty';
        }
        $MPOS_LOGS['check_trip_status'] = $logs;
        return $response;
    }


    function check_shift_status($request = [], $authorize= []) {
        /* declaring variables */
        global $MPOS_LOGS;
        $response = $cashier_data = array();
        $logs = array();
        if (!empty($request)) {
                /* If date is not in GMT format */
                $date_time = date_parse($request['shiftDate']);
                if (isset($date_time['zone']) && !empty($date_time['zone'])) {

                    /* Convert date from other than GMT to GMT format by a common function */
                    $shift_date = strtotime($date_time['year'] . '-' . $date_time['month'] . '-' . $date_time['day'] . ' ' . $date_time['hour'] . ':' . $date_time['minute'] . ':00' . ($date_time['zone'] > 0 ? ' +' : '') . $date_time['zone'] . ' minute');
                } else {
                    /* if shiftDate already in GMT format */
                    $shift_date = strtotime($request['shiftDate']);
                }

                /* preparing Request Array */
                $request_params = array(
                    'request_body' => $request,
                    'date' => strtotime(date('Y-m-d', $shift_date)),
                    'auth_details' => $authorize
                );
                if (!empty($request_params)) {
                    /* getting Data from DB corresponding to distributor_id, chasier_id and shift_id */
                    $this->primarydb->db->select('cashier_register_id as cashierRegisterId, unbalance_status, opening_cash_balance as cash_start, approve_date, shift_close_date, sale_by_cash as cash_revenue, manual_cash_sale_amount as cash_in, manual_cash_sale_amount_note as cash_in_note, manual_deposit as cash_out, manual_deposit_note as cash_out_note, cashier_id, cashier_name, pos_point_id, pos_point_name, opening_time, closing_time, total_closing_balance as revenue, hotel_name, sale_by_card, mannual_closing_cash_balance as cash_end, mannual_closing_cash_balance_note, cash_count, cash_brought_forward, safety_deposit_total_amount, safety_deposit_total_amount_note, other_deposit, other_deposit_note, next_shift_deposit, next_shift_deposit_note, is_logged_out, cash_balance_open_for_next_cashier, shift_id, created_at, hotel_id, reseller_cashier_id, status');
                    $this->primarydb->db->from('cashier_register');
                    if($request_params['request_body']['cashierRegisterId'] != ''){
                        $this->primarydb->db->where(array('cashier_register_id' => $request_params['request_body']['cashierRegisterId']));
                    }else{
                        $this->primarydb->db->where(array('hotel_id' => $request_params['request_body']['distributorId'], 'cashier_id' => $request_params['request_body']['cashierId'], 'shift_id' => $request_params['request_body']['shiftId'], "date(created_at)" => (string) date('Y-m-d', $request_params['date'])));
                    }
                    $cashier_data = $this->primarydb->db->get()->result_array();
                    $logs['query_from_cashier_register'] = $this->primarydb->db->last_query();
                    /* check whether obtain any data from DB or not in DB */
                    if (count($cashier_data) > 0) {
                        $approved_status = ($cashier_data[0]['unbalance_status'] == '1' && $request_params['auth_details']['approveShift']) ? 'Approved' : 'NotApproved';
                        $close_status = ($cashier_data[0]['status'] == '2' && $request_params['auth_details']['closeShift']) ? 'Closed' : 'Open';
                        if ($approved_status == 'Approved') {
                            $approved_response = array(
                                'shiftApproveDetails' => array(
                                    'status' => $approved_status,
                                    'approve_date_moment' => !in_array($cashier_data[0]['approve_date'], array('0000-00-00 00:00:00.000000', '1970-01-01 00:00:00.000000')) ? date('Y-m-d\TH:i:s\Z', strtotime($cashier_data[0]['approve_date'])) : '',
                                )
                            );
                        } else {
                            $approved_response = array(
                                'shiftApproveDetails' => array(
                                    'status' => $approved_status,
                                )
                            );
                        }
                        if ($close_status == 'Closed') {
                            $closed_response = array(
                                'shiftCloseDetails' => array(
                                    'status' => $close_status,
                                    'close_moment_date' => !in_array($cashier_data[0]['shift_close_date'], array('0000-00-00 00:00:00.000000', '1970-01-01 00:00:00.000000')) ? date('Y-m-d\TH:i:s\Z', strtotime($cashier_data[0]['shift_close_date'])) : '',
                                )
                            );
                        } else {
                            $closed_response = array(
                                'shiftCloseDetails' => array(
                                    'status' => $close_status,
                                )
                            );
                        }
                        
                        foreach ($cashier_data as $data) {
                            $key = $data['shift_id'].$data['hotel_id'].$data['reseller_cashier_id'];
                            unset($data['status']);
                            unset($data['approve_date']);
                            unset($data['shift_close_date']);
                            $cashier_data[$key] = $data;
                        }
                        $shiftdetails = array(
                            'shiftDetails' => $cashier_data[$key]
                        );
                        $response = array('data' => array_merge($approved_response, $closed_response ,$shiftdetails));
                    } else {
                        /* preparing error for not effecting any row in DB */
                        $response = errors_helper::error_specification('INVALID_REQUEST');
                        $response['errors'] = 'M04:Provide valid data in request parameters';
                    }
                } else {
                    /* preparing error response for not receiving proper request */
                    $response = errors_helper::error_specification('INVALID_REQUEST');
                    $response['errors'] = 'M05:Request data is empty';
                }
        } else {
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'C05:Request must not be empty';
        }
        $MPOS_LOGS['check_trip_status'] = $logs;
        return $response;
    }

    function update_shift_status($request_params = []) {
        /* declaring variables */
        global $MPOS_LOGS;
        $logs = array();
        $response = $cashier_data = $update_db_query = array();
        $shift_date = '';
        $update_data = "0";
        /* on getting request details from controller */

        $date_time = date_parse($this->request_body['data']['shiftDate']);
        if (isset($date_time['zone']) && !empty($date_time['zone'])) {

            /* Convert date from other than GMT to GMT format by a common function */
            $shift_date = strtotime($date_time['year'] . '-' . $date_time['month'] . '-' . $date_time['day'] . ' ' . $date_time['hour'] . ':' . $date_time['minute'] . ':00' . ($date_time['zone'] > 0 ? ' +' : '') . $date_time['zone'] . ' minute');
        } else {
            /* if shiftDate already in GMT format */
            $shift_date = strtotime($this->request_body['data']['shiftDate']);
        }

        $process_flag = $this->request_body['requestType'] == 'approveShift' ? 1 : ($this->request_body['requestType'] == 'closeShift' ? 2 : 0);

        /* validation proper value in requestType param */
        if ($process_flag > 0) {
            if (($process_flag === 1) || ($process_flag === 2)) {
                /* preparing request for final execution after validation and authorization */
                $request_params = array(
                    'request_body' => $this->request_body,
                    'process_check' => $process_flag,
                    'date' => strtotime(date('Y-m-d', $shift_date))
                );
                if (!empty($request_params)) {
                    /* getting Data from DB corresponding to distributor_id, chasier_id and shift_id */
                    $this->primarydb->db->select('cashier_register_id , unbalance_status, status, opening_cash_balance as cash_start, approve_date, shift_close_date, sale_by_cash as cash_revenue, manual_cash_sale_amount as cash_in, manual_cash_sale_amount_note as cash_in_note, manual_deposit as cash_out, manual_deposit_note as cash_out_note, cashier_id, cashier_name, pos_point_id, pos_point_name, opening_time, closing_time, total_closing_balance as revenue, hotel_name, sale_by_card, mannual_closing_cash_balance as cash_end, mannual_closing_cash_balance_note, cash_count, cash_brought_forward, safety_deposit_total_amount, safety_deposit_total_amount_note, other_deposit, other_deposit_note, next_shift_deposit, next_shift_deposit_note, is_logged_out, cash_balance_open_for_next_cashier, shift_id, created_at, hotel_id, reseller_cashier_id');
                    $this->primarydb->db->from('cashier_register');
                    if($request_params['request_body']['data']['cashierRegisterId'] != ''){
                        $where = array('cashier_register_id' => $request_params['request_body']['data']['cashierRegisterId']);
                    }else{
                        $where = array('hotel_id' => $request_params['request_body']['data']['distributorId'], 'cashier_id' => $request_params['request_body']['data']['cashierId'], 'shift_id' => $request_params['request_body']['data']['shiftId'], "date(created_at)" => (string) date('Y-m-d', $request_params['date']));
                    }
                    $this->primarydb->db->where($where);
                    $cashier_data = $this->primarydb->db->get()->result_array();
                    $logs['query_to_cashier_register'] = $this->primarydb->db->last_query();
                    $logs['process_check'] = $request_params['process_check'];
                    /* for both requestType cheking if already date exist in DB which inserted only in case when same data executed */
                    if ($request_params['process_check'] === 1) {
                        $shift_date = isset($cashier_data[0]['approve_date']) ? $cashier_data[0]['approve_date'] : '';
                        $updation_array = array(
                            'unbalance_status' => '1',
                            'user_name' => $request_params['request_body']['data']['userName'],
                            'user_email' => $request_params['request_body']['data']['userEmail'],
                            'approve_date' => date('Y-m-d H:i:s', strtotime($request_params['request_body']['data']['approveMomentDate']))
                        );
                    } else {
                        $shift_date = isset($cashier_data[0]['shift_close_date']) ? $cashier_data[0]['shift_close_date'] : '';
                        $updation_array = array(
                            'status' => '2',
                            'is_logged_out' => '1',
                            'shift_close_user_name' => $request_params['request_body']['data']['userName'],
                            'shift_close_user_email' => $request_params['request_body']['data']['userEmail'],
                            'shift_close_date' => date('Y-m-d H:i:s', strtotime($request_params['request_body']['data']['closeMomentDate'])),
                            'closing_time' => date('H:i:s', strtotime($request_params['request_body']['data']['shiftCloseTime']))
                        );
                    }
                    $logs['updation_data'] = $updation_array;
                    /* If data exist in DB for corresponding to that details */
                    if (count($cashier_data) > 0) {
                        
                        /* check for date which inserted at first hit of request */                            
                            /* updating DB */
                            if ((empty($cashier_data[0]['unbalance_status']) || $cashier_data[0]['unbalance_status'] == '0') || (empty($cashier_data[0]['status']) || $cashier_data[0]['status'] == '0')) {
                                if($request_params['request_body']['data']['cashierRegisterId'] != ''){
                                    $this->db->update('cashier_register', $updation_array, array('cashier_register_id' => $request_params['request_body']['data']['cashierRegisterId']));

                                }else{
                                    $this->db->update('cashier_register', $updation_array, array('shift_id' => $request_params['request_body']['data']['shiftId'], 'cashier_id' => $request_params['request_body']['data']['cashierId'], 'hotel_id' => $request_params['request_body']['data']['distributorId'], "date(created_at)" => (string) date('Y-m-d', $request_params['date'])));
                                }
                                if($this->db->affected_rows() > 0) {
                                    $update_data = "1";
                                }
                                $logs['query_to_update_shift_staus'] = $this->db->last_query();
                            }
                            /* check whether any row uodated or not in DB */
                            if ($update_data == '0' && $this->db->affected_rows() == 0) {
                                if ($request_params['process_check'] === 1 ) {
                                    $status = 'Already Approved';
                                } else {
                                    $status = 'Already Closed';
                                }
                            } else {
                                if ($request_params['process_check'] === 1) {
                                    $status = 'Approved';
                                } else {
                                    $status = 'Closed';
                                }
                            }
                            $logs['status'] = $status;
                            $this->db->select('cashier_register_id , unbalance_status, status, opening_cash_balance as cash_start, approve_date, shift_close_date, sale_by_cash as cash_revenue, manual_cash_sale_amount as cash_in, manual_cash_sale_amount_note as cash_in_note, manual_deposit as cash_out, manual_deposit_note as cash_out_note, cashier_id, cashier_name, pos_point_id, pos_point_name, opening_time, closing_time, total_closing_balance as revenue, hotel_name, sale_by_card, mannual_closing_cash_balance as cash_end, mannual_closing_cash_balance_note, cash_count, cash_brought_forward, safety_deposit_total_amount, safety_deposit_total_amount_note, other_deposit, other_deposit_note, next_shift_deposit, next_shift_deposit_note, is_logged_out, cash_balance_open_for_next_cashier, shift_id, created_at, hotel_id, reseller_cashier_id');
                            $this->db->from('cashier_register');
                            $this->db->where($where);
                            $logs['fetch_data_cashier_register'] = $this->db->last_query();
                            $res = $this->db->get()->result_array();
                            $logs['result_data'] = $res;
                            foreach ($res as $data) {
                                $key = $data['shift_id'].$data['hotel_id'].$data['reseller_cashier_id'];
                                $res[$key]['status'] = $status;
                                unset($data['status']);
                                unset($data['approve_date']);
                                unset($data['shift_close_date']);
                                $res[$key] = $data;
                                $res[$key]['user_name'] = !empty($request_params['request_body']['data']['userName']) ? $request_params['request_body']['data']['userName'] : '';
                                $res[$key]['user_email'] = !empty($request_params['request_body']['data']['userEmail']) ? $request_params['request_body']['data']['userEmail'] : '';
                                if (in_array($status, ['Already Approved', 'Approved'])) {
                                    $res[$key]['approve_date_moment'] = !in_array($request_params['request_body']['data']['approveMomentDate'], array('0000-00-00 00:00:00.000000', '1970-01-01 00:00:00.000000')) ? date('Y-m-d\TH:i:s\Z', strtotime($request_params['request_body']['data']['approveMomentDate'])) : '';
                                }else{
                                    $res[$key]['close_moment_date'] = !in_array($request_params['request_body']['data']['closeMomentDate'], array('0000-00-00 00:00:00.000000', '1970-01-01 00:00:00.000000')) ? date('Y-m-d\TH:i:s\Z', strtotime($request_params['request_body']['data']['closeMomentDate'])) : '';
                                }
                            }
                            if (in_array($status, ['Already Approved', 'Approved'])) {
                                $response['data']['status'] = $status;
                                $response['data']['shiftApproveDetails'] = $res[$key];
                            } else {
                                $response['data']['status'] = $status;
                                $response['data']['shiftCloseDetails'] = $res[$key];
                            }
                    } else {
                        /* preparing error for not effecting any row in DB */
                        $response = errors_helper::error_specification('INVALID_REQUEST');
                        $response['errors'] = 'M02:No data found. Please provide valid details in request';
                    }
                } else {
                    $response = errors_helper::error_specification('INVALID_REQUEST');
                    $response['errors'] = 'M03:Request data is empty';
                }
            } else {
                /* preparing error response */
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = 'C01:Token Not autherised';
            }
        } else {
            /* preparing error response */
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'C02:Invalid requestType data';
        }


        $MPOS_LOGS['update_shift_status'] = $logs;

        return $response;
    }

    /**
     * @Name    : update_trip_status()
     * @Purpose : To update the status of trip
     */
    public function update_trip_status($request_params = array()) {
        $response = $query_params = $arranged_data = array();
        global $MPOS_LOGS;
        $logs = array();
        if (!empty($request_params)) {
            /** Prepare an array to update */
            $arranged_data = $this->rearrange_array_to_update($request_params);
            if ($arranged_data['status']) {
                $query_params = $arranged_data['params'];
                if (!empty($query_params)) {
                    $this->db->where("is_deleted != 1");
                    $this->db->where('pos_point_name is NOT NULL', NULL, FALSE);
                    $this->db->update('cashier_register', $query_params, array('cashier_register_id' => $request_params['cashier_register_id']));
                    $logs['query_to_cashier_register'] = $this->db->last_query();
                    /* prepare a response by calling the check_trip_status()  */
                    if ($this->db->affected_rows() == '1') {
                        $check_trip_status = $this->check_trip_status(array('cashierRegisterId' => $request_params['cashier_register_id']));
                        /* In case the data is update */
                        date_default_timezone_set('Asia/Kolkata');
                        $response['data']['status'] = "success";
                        $response['data']['tripReportDetails'] = current($check_trip_status['data']);
                    } else {
                        /* In case data already updated */
                        $response = errors_helper::error_specification('INVALID_REQUEST');
                        $response['errors'] = 'M01:The requested data is already updated';
                    }
                } else {
                    /* In case there is no data to update */
                    $response = errors_helper::error_specification('INVALID_REQUEST');
                    $response['errors'] = 'M02:Please enter atleast one parameter to update.';
                }
            } else {
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = $arranged_data['error'];
            }
        }
        $MPOS_LOGS['update_trip_status'] = $logs;
        return $response;
    }

    public function get_payment_details($request_params = array()) {
        /* declaring variables */
        global $MPOS_LOGS;
        $logs = array();
        $response = $payment_data = $payment_details = array();
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        /* on getting request details from controller */
        if (!empty($request_params) && isset($request_params['payment_id'])) {
            /* getting Data from DB corresponding to distributor_id, chasier_id and shift_id */
            $secondarydb->select('*');
            $secondarydb->from('order_payment_details');
            $secondarydb->where_in('id', $request_params['payment_id']);
            $payment_data = $secondarydb->get()->result_array();
            $logs['query_from_order_payment_details'] = $secondarydb->last_query();
            $payment_data = $this->get_max_version_data($payment_data, 'id');
            $logs['order_payment_details_filtered_data'] = $payment_data;
            foreach($payment_data as $row) {
                if($row['deleted'] == "0") {
                    $payment_details[] = $row;
                }
            }
            /* check whether obtain any data from DB or not in DB */
            if (!empty($payment_details)) {
                $response = $payment_details;
            } else {
                /* preparing error for not effecting any row in DB */
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = 'M04:Provide valid data in request parameters';
            }
        } else {
            /* preparing error response for not receiving proper request */
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'M05:Request data is empty';
        }
        $MPOS_LOGS['get_payment_details'] = $logs;
        return $response;
    }

    public function delete_payment($request_params) {
        global $MPOS_LOGS;
        $logs = array();
        $response = $updated_data = array();
        if (!empty($request_params) && !empty($request_params['payment_id'])) {
            $cols_to_update = array(
                'deleted' => '1'
            );
            $logs['cols_to_update'] = $cols_to_update;
            $updated_data = $this->updated_payment_details($request_params, $cols_to_update);
            $logs['updated_data'] = $updated_data;
            if(!empty($updated_data)) {
                $secondarydb = $this->load->database('secondary', true);
                $MPOS_LOGS['DB'][] = 'DB2';
                $secondarydb->insert_batch('order_payment_details', $updated_data);
                $logs['update_query'] = $secondarydb->last_query();
                /* In case the data is update */
                date_default_timezone_set('Asia/Kolkata');
                $response['data']['status'] = "success";
            } else {
                /* In case data already updated */
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = 'M01:The requested data is already updated';
            }
        } else {
            /* In case there is no data to update */
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'M02:Please enter atleast one parameter to update.';
        }
        $MPOS_LOGS['delete_payment'] = $logs;
        return $response;
    }

    public function edit_payment($request_params) {
        global $MPOS_LOGS;
        $logs = array();
        $response = array();
        if (!empty($request_params)) {
            foreach($request_params as $i => $request) {
                if(isset($request['payment_id']) && isset($request['payment_amount'])) {
                    $cols_to_update = array(
                        'order_amount' => $request['payment_amount']
                    );
                    $logs['cols_to_update_'.$i] = $cols_to_update;
                    $newData = $this->updated_payment_details(array('payment_id' => $request['payment_id']), $cols_to_update);
                    if(!empty($newData)) {
                        $updated_data[] = $newData[0];
                    }
                }
            }
            $logs['updated_data'] = $updated_data;
            if(!empty($updated_data)) {
                $secondarydb = $this->load->database('secondary', true);
                $MPOS_LOGS['DB'][] = 'DB2';
                $secondarydb->insert_batch('order_payment_details', $updated_data);
                $logs['update_query'] = $secondarydb->last_query();
                /* In case the data is update */
                date_default_timezone_set('Asia/Kolkata');
                $response['data']['status'] = "success";
            } else {
                /* In case data already updated */
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = 'M01:The requested data is already updated';
            }
        } else {
            /* In case there is no data to update */
            $response = errors_helper::error_specification('INVALID_REQUEST');
            $response['errors'] = 'M02:Please enter atleast one parameter to update.';
        }
        $MPOS_LOGS['edit_payment'] = $logs;
        return $response;
    }

    /**
     * @Name    : update_trip_status()
     * @Purpose : To update the status of trip
     */
    public function updated_payment_details($request_params = array(), $cols_to_update = array()) {
        $payment_details = $updated_data = array();
        if (!empty($request_params) && !empty($cols_to_update) && !empty($request_params['payment_id'])) {
            $payment_details = $this->get_payment_details(array('payment_id' => $request_params['payment_id']));
            if (!isset($payment_details['error'])) {
                foreach ($payment_details as $data) {
                    foreach ($cols_to_update as $col => $val) {
                        if ($col != 'version') {
                            $data[$col] = $val;
                        }
                    }
                    $data['version']++;
                    $data['created_at'] = $data['updated_at'] = gmdate('Y-m-d H:i:s');
                    unset($data['last_modified_at']);
                    $updated_data[] = $data;
                }
            }
        }
        return $updated_data;
    }

    /**
     * @Name    : rearrange_array_to_update()
     * @Purpose : To rearrange a params for to update the data in cashier_register
     */
    public function rearrange_array_to_update($request_params = array()) {
        $response['status'] = 1;
        if (!empty($request_params)) {
            $check_params = "unbalance_status,opening_cash_balance as cash_start,sale_by_cash as cash_revenue,manual_cash_sale_amount as cash_in,manual_cash_sale_amount_note as cash_in_note,manual_deposit as cash_out,manual_deposit_note as cash_out_note,cashier_id,cashier_name,pos_point_id,pos_point_name,opening_time,closing_time,total_closing_balance as revenue,hotel_name,sale_by_card,mannual_closing_cash_balance as cash_end,mannual_closing_cash_balance_note,cash_count,cash_brought_forward,safety_deposit_total_amount,safety_deposit_total_amount_note,other_deposit,other_deposit_note,next_shift_deposit,next_shift_deposit_note,is_logged_out,cash_balance_open_for_next_cashier,shift_id";
            $explode_params = explode(",", $check_params);
            foreach ($explode_params as $explode_params_key => $explode_params_value) {
                $param_array[$explode_params_key] = explode(" as ", $explode_params_value);
                $param_array[$explode_params_key]['count'] = count($param_array[$explode_params_key]);
            }
            foreach ($param_array as $param_array_value) {
                if ($param_array_value['count'] == 2) {
                    /* If the param named change from table columns */
                    if (isset($request_params[$param_array_value['1']])) {
                        $validate_params[$param_array_value['0']] = $request_params[$param_array_value['1']];
                    }
                } else {
                    /* If the param name same as the table columns */
                    if (isset($request_params[$param_array_value['0']])) {
                        $validate_params[$param_array_value['0']] = $request_params[$param_array_value['0']];
                    }
                }
            }
            $validate_param = $this->validate_param_datatype('cashier_register', $validate_params);
        }
        if ($validate_param['status']) {
            $error_count = 0;
            foreach ($param_array as $param_array_value) {
                if ($param_array_value['count'] == 2) {
                    /* If the param named change from table columns */
                    if (!empty($validate_param['error'][$param_array_value[0]])) {
                        /* check if param have any validation error */
                        if ($validate_param['error'][$param_array_value[0]]['datatype_error']) {
                            /* prepare an error when datatype not correct */
                            if ($validate_param['error'][$param_array_value[0]]['datatype'] == 'tinyint') {
                                /* when error is of tinyint datatype then we have to add message */
                                $response['error'][$error_count] = "The requested parameter " . $param_array_value[1] . " should be a numeric string and not be greater than 127 digit.";
                            } else {
                                /* when error is not tinyint datatype */
                                $response['error'][$error_count] = "The requested parameter " . $param_array_value[1] . " should be a numeric string.";
                            }
                            if ($validate_param['error'][$param_array_value[0]]['size_error']) {
                                /* The error message combine when the datatype and size error exist for same parameter */
                                $response['error'][$error_count] .= " and not be greater than " . $validate_param['error'][$param_array_value[0]]['size'];
                            }
                        }
                        if (!$validate_param['error'][$param_array_value[0]]['datatype_error'] && $validate_param['error'][$param_array_value[0]]['size_error']) {
                            /* When the error message only of size */
                            $response['error'][$error_count] = "The " . $param_array_value[1] . " value should not be greater than " . $validate_param['error'][$param_array_value[0]]['size'];
                        }
                        $error_count++;
                    }
                } else {
                    /* If the param name same as the table columns */
                    if (!empty($validate_param['error'][$param_array_value[0]])) {
                        /* check if param have any validation error */
                        if ($validate_param['error'][$param_array_value[0]]['datatype_error']) {
                            /* prepare an error when datatype not correct */
                            if ($validate_param['error'][$param_array_value[0]]['datatype'] == 'tinyint') {
                                /* when error is of tinyint datatype then we have to add message */
                                $response['error'][$error_count] = "The requested parameter " . $param_array_value[0] . " should be a numeric string and not be greater than 127 digit.";
                            } else {
                                /* when error is not tinyint datatype */
                                $response['error'][$error_count] = "The requested parameter " . $param_array_value[0] . " should be a numeric string.";
                            }
                            if ($validate_param['error'][$param_array_value[0]]['size_error']) {
                                /* The error message combine when the datatype and size error exist for same parameter */
                                $response['error'][$error_count] .= " and not be greater than " . $validate_param['error'][$param_array_value[0]]['size'];
                            }
                        }
                        if (!$validate_param['error'][$param_array_value[0]]['datatype_error'] && $validate_param['error'][$param_array_value[0]]['size_error']) {
                            /* When the error message only of size */
                            $response['error'][$error_count] = "The " . $param_array_value[0] . " value should not be greater than " . $validate_param['error'][$param_array_value[0]]['size'];
                        }
                        $error_count++;
                    }
                }
            }
        }
        if (strtotime($validate_params['opening_time']) > strtotime($validate_params['closing_time'])) {
            $response['error'][$error_count] = 'The opening time is not greater than the closing time.';
        }
        if (!empty($response['error'])) {
            $response['status'] = 0;
        } else {
            $response['params'] = $validate_params;
        }
        return $response;
    }

    /**
     * @Name    : validate_param_datatype()
     * @Purpose : To validate the param datatype with requested table datatypes
     */
    public function validate_param_datatype($table_name = '', $params = array()) {
        $db = $this->db;
        $response['status'] = 1;
        if (!empty($table_name)) {
            $table_full_structure = $db->query("DESCRIBE `" . $table_name . "`")->result();
            foreach ($table_full_structure as $table_structure) {
                if (!empty($params[$table_structure->Field])) {
                    /* Set an requested with their name, datatypes and size */
                    $validate_params[$table_structure->Field]['param'] = $table_structure->Field;
                    /* break the type to datatype and their size */
                    $type_size = str_replace(")", "", str_replace("(", "-", $table_structure->Type));
                    $type_size_explode = explode("-", $type_size);
                    $validate_params[$table_structure->Field]['datatype'] = $type_size_explode[0];
                    $validate_params[$table_structure->Field]['size'] = $type_size_explode[1];
                    $validate_params[$table_structure->Field]['value'] = $params[$table_structure->Field];
                }
            }
            if (!empty($validate_params)) {
                $validate_errors = array();
                foreach ($validate_params as $validate_param) {
                    $temp_value = $temp_size = array();
                    switch ($validate_param['datatype']) {
                        case 'int':
                            /* check if the datatype is integer */
                            if (!preg_match("/^[0-9]+$/", $validate_param['value'])) {
                                /* Check the value is integer and positive */
                                $validate_errors[$validate_param['param']]['datatype_error'] = 1;
                                $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                            }
                            if (!empty($validate_param['size']) && strlen($validate_param['value']) > $validate_param['size']) {
                                $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                                $validate_errors[$validate_param['param']]['size'] = $validate_param['size'];
                                $validate_errors[$validate_param['param']]['size_error'] = 1;
                            }
                            break;
                        case 'float':
                        case 'decimal':
                            /* whenever datatype is float or decimal */
                            if (!preg_match("/^[+]?([0-9]+(?:[\.][0-9]*)?|\.[0-9]+)$/", $validate_param['value'])) {
                                $validate_errors[$validate_param['param']]['datatype_error'] = 1;
                                $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                            }
                            if (!empty($validate_param['size'])) {
                                /* check if size is given then check the size not exceeds */
                                /* break the number value from decimal value (10.01) 10 is number value 01 is decimal value */
                                $temp_value = explode('.', $validate_param['value']);
                                /* break the integer size (10,2) 10 number sizer , 2 is decimal size */
                                $temp_size = explode(',', $validate_param['size']);
                                if (strlen($temp_value[0]) > ($temp_size[0] - $temp_size[1])) {
                                    $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                                    $validate_errors[$validate_param['param']]['size'] = $temp_size[0] - $temp_size[1];
                                    $validate_errors[$validate_param['param']]['size_error'] = 1;
                                }
                            }
                            break;
                        case 'tinyint':
                            if (!preg_match("/^[0-9]+$/", $validate_param['value']) || $validate_param['value'] > 127) {
                                /* Check the value is integer, positive maximum set as 127  */
                                $validate_errors[$validate_param['param']]['datatype_error'] = 1;
                                $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                            }
                            break;
                        case 'varchar':
                            if (!empty($validate_param['size']) && strlen($validate_param['value']) > $validate_param['size']) {
                                $validate_errors[$validate_param['param']]['datatype'] = $validate_param['datatype'];
                                $validate_errors[$validate_param['param']]['size'] = $validate_param['size'];
                                $validate_errors[$validate_param['param']]['size_error'] = 1;
                            }
                            break;
                        default :
                    }
                }
                if (!empty($validate_errors)) {
                    $response['error'] = $validate_errors;
                } else {
                    /* If there is no error then it will give signal of success */
                    $response['status'] = 0;
                }
            } else {
                $response['error'] = "Please enter the correct params";
            }
        }
        return $response;
    }

    /**
     * @Name    : update_shift()
     * @Purpose : To update the status of shift
     */
    public function update_shift($request_params = array()) {
        $response = $query_params = $arranged_data = array();
        global $MPOS_LOGS;
        $logs = array();
        if (!empty($request_params)) {
            /** Prepare an array to update */
            $arranged_data = $this->rearrange_array_to_update($request_params);
            if ($arranged_data['status']) {
                $query_params = $arranged_data['params'];
                if (!empty($query_params)) {
                    $this->db->where("is_deleted != 1");
                    $this->db->where('pos_point_name is NOT NULL', NULL, FALSE);
                    $this->db->update('cashier_register', $query_params, array('cashier_register_id' => $request_params['cashier_register_id']));
                    $logs['query_to_cashier_register'] = $this->db->last_query();
                    /* prepare a response by calling the check_trip_status()  */
                    if ($this->db->affected_rows() == '1') {
                        $check_shift_status = $this->check_shift_status(array('cashierRegisterId' => $request_params['cashier_register_id']));
                        if(!empty($check_shift_status['data']['shiftDetails'])){
                            /* In case the data is update */
                            date_default_timezone_set('Asia/Kolkata');
                            $response['data']['status'] = "success";
                            $response['data']['shiftReportDetails'] = $check_shift_status['data']['shiftDetails'];
                        }else{
                            $response = $check_shift_status; 
                        }
                    } else {
                        /* In case data already updated */
                        $response = errors_helper::error_specification('INVALID_REQUEST');
                        $response['errors'] = 'M01:The requested data is already updated';
                    }
                } else {
                    /* In case there is no data to update */
                    $response = errors_helper::error_specification('INVALID_REQUEST');
                    $response['errors'] = 'M02:Please enter atleast one parameter to update.';
                }
            } else {
                $response = errors_helper::error_specification('INVALID_REQUEST');
                $response['errors'] = $arranged_data['error'];
            }
        }
        $MPOS_LOGS['update_shift'] = $logs;
        return $response;
    }
}

?>
