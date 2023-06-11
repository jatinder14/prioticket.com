<?php
class Transaction_fee_model extends MY_Model {
    
    /* #region   */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->model('common_model');
    }
    /* #endregion */

    /* #region to get transaction fee for all rows  */
    /**
     * get_transaction_fee_data
     *
     * @param  mixed $hotel_info
     * @param  mixed $ticket_ids
     * @param  mixed $museum_ids
     * @param  mixed $distributor_partner_id
     * @return void
     * @author Priya Aggarwal <priya.intersoft1@gmail.com> on June, 2020
     */
    function get_transaction_fee_data($hotel_info,$ticket_id,$ticket_type_id,$museum_id, $distributor_partner_id, $order_confirm_date, $admin_merchant = array(), $from_time = '', $to_time = '') 
    {
        $db = $this->primarydb->db;
        $admin = implode(',', $admin_merchant);
        $admin_where = '';
        if (!empty($admin)) {
            foreach ($admin as $row_admin) {
                $admin_where .= ' WHEN partners = 8 THEN (FIND_IN_SET('.$row_admin.', debit_partner_id) OR debit_partner_id = 0) ';
            }
        }
        $day = date('l', $order_confirm_date);
        $time = '"'.$from_time .'_'.$to_time.'"';
        $debit_where_query = ' (CASE WHEN partners = 1 THEN (FIND_IN_SET('.$hotel_info->market_merchant_id.', debit_partner_id) OR debit_partner_id = 0) WHEN partners = 2 THEN (FIND_IN_SET('.$hotel_info->reseller_id.', debit_partner_id) OR debit_partner_id = 0) WHEN partners IN (4,7) THEN (FIND_IN_SET('.$hotel_info->cod_id.', debit_partner_id) OR debit_partner_id = 0)  WHEN partners = 5 THEN (FIND_IN_SET('.$museum_id.', debit_partner_id) OR debit_partner_id = 0) WHEN partners = 6 THEN (FIND_IN_SET('.$distributor_partner_id.', debit_partner_id) OR debit_partner_id = 0) '.$admin_where.' ELSE 0  END ) ';
        $where_query = ' ((start_date <= "'.$order_confirm_date.'" AND end_date >= "'.$order_confirm_date.'") OR end_date = "99999999") AND FIND_IN_SET ("'.$day.'", apply_on_days) AND '.$debit_where_query;
        // for subject type booking/Timeinterval - product
        $product_where = " (FIND_IN_SET(".$ticket_id.", st.product_id) OR st.product_id = 0) AND (FIND_IN_SET(".$ticket_type_id.", st.product_type) OR st.product_type = 0) AND subject_type IN(2,4) AND st.deleted = 0 AND subject_type != '3' AND ";
        // for subject type Timeslot - timeslot
        $timeslot_where = " (FIND_IN_SET(".$ticket_id.", st.product_id) OR st.product_id = 0) AND (FIND_IN_SET(".$ticket_type_id.", st.product_type) OR st.product_type = 0) AND (FIND_IN_SET(".$time.", st.timeslot) OR st.timeslot = 0) AND subject_type IN(3) AND subject_type != '2' AND st.deleted = 0 AND ";
        // for subject type Timeinterval - channel - supplier
        $supplier_where = " (FIND_IN_SET(".$museum_id.", st.suppliers) OR st.suppliers = 0) AND subject_type IN(4) AND st.deleted = 0 AND ";
        // for subject type Timeinterval - channel - Distributor
        $distributors_where = " (FIND_IN_SET(".$hotel_info->cod_id.", st.distributor_ids) OR st.distributor_ids = 0 ) AND subject_type IN(4) AND st.deleted = 0 AND ";
        // for subject type Timeinterval - channel - Resellers
        $resellers_where = " (FIND_IN_SET(".$hotel_info->reseller_id.", st.reseller_ids) OR st.reseller_ids = 0) AND subject_type IN(4) AND st.deleted = 0 AND ";
        
       
        // select fields
        $where_query .= ' and tf.deleted = 0 ' ;
        $query = 'Select *, tf.transaction_fee_id as tf_id from transaction_fee as tf LEFT JOIN subject_types as st on tf.transaction_fee_id = st.transaction_fee_id where (CASE WHEN company_type = 1 THEN company_id = 0  
        WHEN company_type = 2 THEN company_id = '.$hotel_info->market_merchant_id.' WHEN company_type = 3 THEN company_id = '.$hotel_info->reseller_id.'  ELSE 0  END) AND' ;
        $main_query =  $query . $where_query . '  and subject_type = 1 UNION ALL '. $query .  $product_where . $where_query . ' UNION ALL '. $query .  $supplier_where . $where_query . ' UNION ALL '. $query .  $distributors_where . $where_query . ' UNION ALL '. $query .  $resellers_where . $where_query . ' UNION ALL '. $query .  $timeslot_where . $where_query; 
        $this->createLog('transaction_fee.php','query', array("data"=>$main_query));
        $result = $db->query($main_query);
        $final_result = array();
        $final_data = array();
        if ($result->num_rows() > 0) {
            $get_data = $result->result();
            $tier = array();
            $this->createLog('transaction_fee.php','get_data', array("data"=>json_encode($get_data)));
            foreach ($get_data as $row) {
                $row->transaction_fee_id = $row->tf_id;
                $transaction_id[] = $row->transaction_fee_id;
                $subject_type = $row->subject_type;
                $apply_per = $row->apply_per;
                $charge_per = $row->charge_per;
                if ($subject_type == 1) {
                    // in case of order type subject type
                    $data['data'] = (array) $row;
                    $final_data[] = $data;
                } else if (($row->product_id == 0 || !empty($row->product_id)) && ($subject_type == 2 || $subject_type == 4 || $subject_type == 3) && ($apply_per == 'product' || $charge_per == 'product')) {
                    
                    /* #region  to check & group product ids */
                    if ($row->product_id != 0) {
                        $data['ticket_ids'] = explode(",", $row->product_id);
                    } else {
                        $data['ticket_ids'] = 0;
                    } 
                    /* #endregion  to check product ids */  
                     /* #region to check & group Timeslot  */
                    if ($row->timeslot != 0) {
                        $data['timeslot'] = explode(",", $row->timeslot);
                    } else {
                        $data['timeslot'] = 0;
                    } 

                    /* #region to check & group product type id  */
                    if ($row->product_type != 0) {
                        $data['product_type'] = explode(",", $row->product_type);
                    } else {
                        $data['product_type'] = 0;
                    } 
                    $data['data'] = (array) $row;
                    $final_data[] = $data;
                    /* #endregion to check & group product type id  */
                } elseif (($row->suppliers == 0 ||  !empty($row->suppliers)) && $subject_type == 4 && $charge_per =='suppliers') {
                    /* #region to check & group Suppliers  */
                    if ($row->suppliers != 0) {
                        $data['suppliers'] = explode(",", $row->suppliers);
                    } else {
                        $data['suppliers'] = 0;
                    } 
                    $data['data'] = (array) $row;
                    $final_data[] = $data;
                    /* #endregion to check & group Suppliers  */
                } elseif (($row->timeslot == 0 || !empty($row->timeslot)) && $subject_type == 3) {
                    /* #region to check & group Timeslot  */
                    if ($row->timeslot != 0) {
                        $data['timeslot'] = explode(",", $row->timeslot);
                    } else {
                        $data['timeslot'] = 0;
                    } 
                    $data['data'] = (array) $row;
                    $final_data[] = $data;
                    /* #endregion to check & group Timeslot  */
                } elseif (!empty($row->channels) && $subject_type == 4) {
                    if ($row->channels ==  'direct_sales' || $row->channels ==  'agents'){
                        /* #region to check & group Distributors ie. Direct & Agent  */
                        if ($row->distributor_ids != 0) {
                            $data['distributors'] = explode(",", $row->distributor_ids);
                        } else {
                            $data['distributors'] = 0;
                        } 
                        $data['data'] = (array) $row;
                        $final_data[] = $data;
                        /* #endregion to check & group Distributors ie. Direct & Agent */
                    } else {
                        /* #region to check & group Resellers */
                        if ($row->reseller_ids != 0) {
                            $data['resellers'] = explode(",", $row->reseller_ids);
                        } else {
                            $data['resellers'] = 0;
                        } 
                        $data['data'] = (array) $row;
                        $final_data[] = $data;
                        /* #endregion to check & group Resellers  */
                    }
                }
            }
            if (!empty($transaction_id)) {
                // Get transaction tiers
                $db->select('*');
                $db->from('transaction_price_tier');
                $db->where_in('transaction_fee_id', $transaction_id);
                $db->where('deleted', 0);
                $result = $db->get();
                if ($result->num_rows() > 0) {
                    $tier_result = $result->result_array();
                    foreach ($tier_result as $tier_row) {
                        $tier_data[$tier_row['transaction_fee_id']][$tier_row['to_range']] = $tier_row;
                    }
                    $tier['tier_setting'] = $tier_data;
                }
            }
        }
        $final_result['data'] = $final_data;
        $final_result['tier_setting'] = $tier;
        $this->createLog('transaction_fee.php','data', array("data"=>json_encode($final_result)));
        return $final_result;
    }
    /* #endregion to get transaction fee for all rows.*/
    
    /* #region to return trsnaction data according to subject type  */
    /**
     * transaction_data
     *
     * @param  mixed $transaction_data
     * @param  mixed $total_order_amount
     * @param  mixed $total_quantity
     * @return void
     * @author Priya Aggarwal <priya.intersoft1@gmail.com> on June, 2020
     */
    function transaction_data($transaction_data = array(), $total_order_amount = 0, $total_quantity = 1, $row_count = 950) {
        foreach($transaction_data as $row) {
            $transaction_fee_data = $row['transaction_fee_data']['data'];
            foreach($transaction_fee_data as $child_row) {
                $timeslot_booking_flag = 0;
                if ($child_row['data']['subject_type'] == 3 && $child_row['data']['when_charge'] == 2) {
                    $timeslot_booking_flag = $this->get_timeslot_booking_count($row['data']['ticketId'], $row['data']['selected_date'], $row['data']['selected_date'], $row['data']['from_time'], $row['data']['to_time'], $row['quantity']);
                }
                $timeslot_per_flag = 0;
                if ($child_row['data']['subject_type'] == 3 && $child_row['data']['when_charge'] == 3) {
                    //Charge per flag
                    $falg_value_counts = $this->get_transaction_flag_value_count($child_row['data']['transaction_fee_id']);
                    $child_row['data']['amount'] = $child_row['data']['amount'] * $falg_value_counts;
                    $timeslot_per_flag = 1;
                }
                if ($child_row['data']['subject_type'] == 1) {
                    // for subject type order
                    $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting']);
                } 
                elseif ($child_row['data']['subject_type'] == 2 || ($child_row['data']['subject_type'] == 3 && ($timeslot_booking_flag == 1 || $timeslot_per_flag == 1))) {
                    //for subject type product and timeslot
                    if (((isset($child_row['product_type']) && $child_row['product_type'] == 0) || (in_array($row['data']['ticketType'], $child_row['product_type']))) && (in_array($row['data']['ticketId'], $child_row['ticket_ids']) ||  (isset($child_row['ticket_ids']) && $child_row['ticket_ids'] == 0))) {
                        if ($child_row['data']['transaction_type'] == 1) {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting'], $timeslot_per_flag, $timeslot_booking_flag);
                        } 
                        else {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['pax'], $row['transaction_fee_data']['tier_setting'], $timeslot_per_flag, $timeslot_booking_flag);
                        }
                    }
                } 
                elseif ($child_row['data']['subject_type'] == 4) {
                    // for subject type time interval
                    $charge_per = $child_row['data']['charge_per'];
                    if ($charge_per == 'product' && ((isset($child_row['product_type']) && $child_row['product_type'] == 0) || (in_array($row['data']['ticketType'], $child_row['product_type']))) && (in_array($row['data']['ticketId'], $child_row['ticket_ids']) ||  (isset($child_row['ticket_ids']) && $child_row['ticket_ids'] == 0))) {
                        if ($child_row['data']['transaction_type'] == 1) {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting']);
                        } 
                        else {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['pax'], $row['transaction_fee_data']['tier_setting']);
                        }
                    } 
                    else if ($charge_per == 'suppliers' && isset($child_row['suppliers']) && (in_array($row['data']['museum_id'], $child_row['suppliers']) || $child_row['suppliers'] == 0)) {
                        $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting']);
                    } 
                    else if ($charge_per == 'channels') {
                        if ($child_row['data']['channels'] == 'resellers' && isset($child_row['resellers']) && (in_array($row['data']['reseller_id'], $child_row['resellers']) || $child_row['resellers'] == 0)) {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting']);
                        } 
                        else if (isset($child_row['distributors']) && (in_array($row['data']['hotel_id'], $child_row['distributors']) || $child_row['distributors'] == 0)) {
                            $transaction_fee_rows[] =$this->get_transaction_fee_rows($row['data'], $child_row, $row_count++, $row['total_order_amount'], $row['quantity'], $row['transaction_fee_data']['tier_setting']);
                        }
                    }
                }
            }
            
        }
        return $transaction_fee_rows;
    }
    
    /* #endregion to return trsnaction data according to subject type*/
    
    /* #region to get transaction fee rows 18 and 19  */
    /**
     * get_transaction_fee_rows
     *
     * @param  mixed $visitor_row_batch
     * @param  mixed $transaction_fee_data
     * @param  mixed $row_count
     * @param  mixed $total_order_amount
     * @param  mixed $quanitity
     * @return void
     * @author Priya Aggarwal <priya.intersoft1@gmail.com> on June, 2020
     */
    function get_transaction_fee_rows($visitor_row_batch, $transaction_fee_data_array,$row_count = 950, $total_order_amount = 0, $quanitity = 1, $tier_setting = array(), $timeslot_per_flag = 0, $timeslot_booking_flag = 0) 
    {
        $transaction_fee_data = $transaction_fee_data_array['data'];
        $where['id'] = $transaction_fee_data['tax'];
        $transaction_id = $visitor_row_batch['visitor_group_no']. $row_count;
        $visitor_ticket_id = $transaction_id . '18';
        $tax = $this->common_model->getSingleRowFromTable('store_taxes', $where);
        $general_fee = $total_order_amount;
        $guest_debit = $visitor_row_batch;
        $tier_group_amount = 0;
        $tier_group_amount_type = 0;
        if ($transaction_fee_data['price_tier'] == 1 && !empty($tier_setting['tier_setting']) && isset($tier_setting['tier_setting'][$transaction_fee_data['transaction_fee_id']])) {
            foreach($tier_setting['tier_setting'][$transaction_fee_data['transaction_fee_id']] as $tier_row) {
                if ($quanitity >= $tier_row['from_range'] && $quanitity <= $tier_row['to_range']) {
                    $tier_group_amount = $tier_row['amount'];
                    $tier_group_amount_type = $tier_row['amount_type'];
                }
            }
        } else {
            $tier_group_amount = $transaction_fee_data['amount'];
            $tier_group_amount_type = $transaction_fee_data['amount_type'];
        }
        $gross_amount = 0;
        if ($tier_group_amount_type == 2) {
            $gross_amount = round((($general_fee * $tier_group_amount) / 100), 2);
            if ($transaction_fee_data['price_tier'] == 0 && $transaction_fee_data['transaction_type'] == 2) {
                $gross_amount = $gross_amount * $quanitity;
            }
        } else if ($transaction_fee_data['price_tier'] == 0 && $timeslot_per_flag != 1 && $timeslot_booking_flag != 1) {
            $gross_amount = $quanitity * $tier_group_amount;
        } else {
            $gross_amount = $tier_group_amount;
        }
        $net_amount = round((($gross_amount * 100) / ($tax->tax_value + 100)), 2);
        $guest_debit['transaction_id'] = $transaction_id ;
        $guest_debit['timezone'] = $transaction_fee_data['transaction_fee_id'] ;
        $guest_debit['id'] = $visitor_ticket_id ;
        $guest_debit['partner_gross_price'] = $gross_amount ;
        $guest_debit['order_currency_partner_gross_price'] = $gross_amount ;
        $guest_debit['partner_gross_price_without_combi_discount'] = $gross_amount;
        $guest_debit['partner_net_price'] = $net_amount ; 
        $guest_debit['order_currency_partner_net_price'] = $net_amount; 
        $guest_debit['partner_net_price_without_combi_discount'] = $net_amount ;
        $guest_debit['tax_id'] =  $transaction_fee_data['tax'] ;
        $guest_debit['tax_value'] =  $tax->tax_value ;
        $guest_debit['tax_name'] =  $tax->tax_name;
        $guest_debit['partner_id'] =  $transaction_fee_data['debit_partner_id'] ;
        $guest_debit['partner_name'] =$transaction_fee_data['debit_partner_name'] ;
        $guest_debit['row_type'] = '18' ;
        $guest_debit['transaction_type_name'] = 'Transaction Fee' ;
        $transaction_fee_rows[] = $guest_debit;

        $guest_credit = $guest_debit;
        $guest_credit['partner_id'] =  $transaction_fee_data['credit_partner_id'] ;
        $guest_credit['id'] = $transaction_id . '19' ;
        $guest_credit['partner_name'] =$transaction_fee_data['credit_partner_name'] ;
        $guest_credit['row_type'] = '19' ;
        $transaction_fee_rows[] = $guest_credit;
        if ($tier_group_amount == 0) {
            $transaction_fee_rows = array();
        }
        return $transaction_fee_rows;
    }
    /* #endregion to get trsnaction fee rows 18 and 19 */
    
    /* #region to check booking time slot sold count  */
    /**
     * get_timeslot_booking_count
     *
     * @param  mixed $ticket_id
     * @param  mixed $start_date
     * @param  mixed $end_date
     * @param  mixed $quantity
     * @return void
     * @author Priya Aggarwal <priya.intersoft1@gmail.com> on June, 2020
     */
    function get_timeslot_booking_count($ticket_id = '', $start_date = '', $end_date = '', $from_time = '', $to_time = '',  $quantity = 1) {
        $ticket_details = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $ticket_id));
        $this->CreateLog('transaction_fee_log.php', "step1", array('ticket_details' => json_encode($ticket_details),'query' => $this->primarydb->db->last_query()));
        if($ticket_details->is_reservation == 1 && $ticket_details->is_own_capacity != 2) { 
            /* To get data from redis if value is set to 1 */
            if (CAPACITY_FROM_REDIS || 1) {
                $this->CreateLog('transaction_fee_log.php', "step2", array('CAPACITY_FROM_REDIS' => json_encode(CAPACITY_FROM_REDIS)));
                /* Get availability for particular date range REDIS SERVER */
               
                $headers = $this->all_headers_new('get_timeslot_booking_count' , $ticket_id);

                $request = json_encode(array("ticket_id" => $ticket_id, "from_date" => $start_date, "to_date" => $end_date, "shared_capacity_id" => $ticket_details->shared_capacity_id));
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/listcapacity");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $getdata = curl_exec($ch);
                curl_close($ch);
                $available_capacity = json_decode($getdata, true);
                foreach ($available_capacity['data'] as $timeslots) {
                    $date = $timeslots['date'];
                    foreach ($timeslots['timeslots'] as $slot) {
                        if ($slot['from_time'] != $from_time && $slot['to_time'] != $to_time) {
                            continue;
                        } elseif ($slot['from_time'] == $from_time && $slot['to_time'] == $to_time && $slot['bookings'] == $quantity) {
                            $flag = 1;
                        } else {
                            $flag = 0;
                        }
                    }
                }
            }  
        } else {
            $flag = 0;
        }
        return $flag;
    }
    /* #endregion */
        
    /* #region  to get transaction flag values count */
    /**
     * get_transaction_flag_value_count
     *
     * @param  mixed $transaction_fee_id
     * @return void
     * @created by Priya.intersoft1@gmail.com @ 14th Dec, 2021
     */
    function get_transaction_flag_value_count($transaction_fee_id=''){
        if (!empty($transaction_fee_id)) {
            return $this->primarydb->db
			->select('id')
			->from('flag_entities')
			->where('entity_id', $transaction_fee_id)
			->where('entity_type', "10")
			->where('deleted', "0")
			->get()->num_rows();
        }
    }
    /* #endregion */
}
?>