<?php

class Order_process_update_model extends MY_Model {
    
    /* #region for construct  */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_model');
        $this->load->model('order_process_vt_model');
    }
    /* #endregion */

    /* #region function insert pre-assigned pass data in DB2  */
    /**
     * update_order_table
     *
     * @param  mixed $order_no
     * @param  mixed $from_css_app
     * @param  mixed $db_type
     * @param  mixed $extra_params
     * @return void
     * @author Hemant <hemant.intersoft@gmail.com> on November, 2016
     */
    function update_order_table($order_no, $from_css_app = 0, $db_type = '1', $extra_params=array()) 
    {
        global $MPOS_LOGS;
        if($db_type == '1') {
            $db = $this->secondarydb->db;
        } else if($db_type == '4') {
            if(STOP_RDS_UPDATE_QUEUE == '1' ) {
                return true;
            }
            $db = $this->fourthdb->db;
        } else {
            $db = $this->db;
        }
        $commission_json = '';
        $final_visitor_data_to_insert_big_query = $confirm_data = array();
        
        // Load SQS library.
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        // Insert entry into hotel_ticket_overview table
        $this->db->select('*');
        $this->db->from('hotel_ticket_overview');
        $this->db->where('id', $order_no);
        $overview_result = $this->db->get();
        if ($overview_result->num_rows() > 0) {
            $overview_data = $overview_result->result_array();
            foreach ($overview_data as $value) {
                $this->CreateLog('value_check.php', "VT refunded entries", array(json_encode($value)));
                foreach($value as $key => $val) {
                    if($val==null) {
                        $val="";
                    }
                    $value[$key]=$val;
                }
                unset($value['updated_at']);
                $db->insert('hotel_ticket_overview', $value);
                $hotel_data = $value;
            }        
            $order_visitor_group_no = $hotel_data['visitor_group_no'];  
            $this->CreateLog(' order_visitor_group_no.php', "VT refunded entries", array(json_encode($order_visitor_group_no)));
            $pass_number = $hotel_data['passNo'];  
            $channel_type = $hotel_data['channel_type'];  
            $preassigned_data = $this->order_process_update_model->find('pre_assigned_codes', array('select' => 'commission_json', 'where' => 'child_pass_no = "'.$pass_number.'"'));
            $commission_json = $preassigned_data[0]['commission_json'];
            // Insert entry into prepaid_tickets table
            $this->db->select('*');
            $this->db->from('prepaid_tickets');
            $this->db->where('hotel_ticket_overview_id', $order_no);
            $prepaid_result = $this->db->get();
            $logs['pt_query_'.date('H:i:s')]=$this->db->last_query();
            if ($prepaid_result->num_rows() > 0) {
                $prepaid_data = $prepaid_result->result();
                $final_visitor_data = array();
                foreach ($prepaid_data as $key => $value) {
                    if ($key == 0) {
                        $museum_id = $value->museum_id;
                        $museum_name = $value->museum_name;
                        $hotel_id = $value->hotel_id;
                        $hotel_name = $value->hotel_name;
                        $visitor_group_no = $value->visitor_group_no;
                    }
                    
                    $value->merchant_admin_id = 0;
                    if(!empty($extra_params) ) {
                        $value->merchant_admin_id = (!empty($extra_params['merchant_admin_id']) && isset($extra_params['merchant_admin_id'][$value->ticket_id])) ? $extra_params['merchant_admin_id'][$value->ticket_id] : 0;
                        $value->created_at = isset($extra_params['created_at']) ? $extra_params['created_at'] : strtotime(gmdate('m/d/Y H:i:s'));
                        $value->channel_id = isset($extra_params['channel_id']) ? $extra_params['channel_id'] : 0;
                        $value->channel_name = isset($extra_params['channel_name']) ? $extra_params['channel_name'] : '';
                        $value->saledesk_id = isset($extra_params['saledesk_id']) ? $extra_params['saledesk_id'] : 0;
                        $value->saledesk_name = isset($extra_params['saledesk_name']) ? $extra_params['saledesk_name'] : '';
                        $value->financial_id = isset($extra_params['financial_id']) ? $extra_params['financial_id'] : 0;
                        $value->financial_name = isset($extra_params['financial_name']) ? $extra_params['financial_name'] : '';
                        $value->location = isset($extra_params['location']) ? $extra_params['location'] : '';
                        $value->image = isset($extra_params['image']) ? $extra_params['image'] : '';
                        $value->highlights = isset($extra_params['highlights']) ? $extra_params['highlights'] : '';
                        $value->oroginal_price = isset($extra_params['oroginal_price']) ? $extra_params['oroginal_price'] : 0;
                        $value->order_currency_extra_discount = isset($extra_params['order_currency_extra_discount']) ? $extra_params['order_currency_extra_discount'] : 0;
                        $value->is_discount_in_percent = isset($extra_params['is_discount_in_percent']) ? $extra_params['is_discount_in_percent'] : 0;
                        $value->related_product_title = isset($extra_params['related_product_title']) ? $extra_params['related_product_title'] : '';
                        $value->pos_point_id_on_redeem = isset($extra_params['pos_point_id_on_redeem']) ? $extra_params['pos_point_id_on_redeem'] : 0;
                        $value->pos_point_name_on_redeem = isset($extra_params['pos_point_name_on_redeem']) ? $extra_params['pos_point_name_on_redeem'] : '';
                        $value->distributor_id_on_redeem = isset($extra_params['distributor_id_on_redeem']) ? $extra_params['distributor_id_on_redeem'] : 0;
                        $value->distributor_cashier_id_on_redeem = isset($extra_params['distributor_cashier_id_on_redeem']) ? $extra_params['distributor_cashier_id_on_redeem'] : 0;
                        $value->is_discount_code = isset($extra_params['is_discount_code']) ? $extra_params['is_discount_code'] : 0;
                        $value->is_pre_selected_ticket = isset($extra_params['is_pre_selected_ticket']) ? $extra_params['is_pre_selected_ticket'] : 1;
                        $value->voucher_creation_date = isset($extra_params['voucher_creation_date']) ? $extra_params['voucher_creation_date'] : gmdate('Y-m-d H:i:s');
                        $value->voucher_updated_by = isset($extra_params['voucher_updated_by']) ? $extra_params['voucher_updated_by'] : 0;
                        $value->voucher_updated_by_name = isset($extra_params['voucher_updated_by_name']) ? $extra_params['voucher_updated_by_name'] : '';
                        $value->redeem_method = isset($extra_params['redeem_method']) ? $extra_params['redeem_method'] : '';
                        $value->discount_code_value = isset($extra_params['discount_code_value']) ? $extra_params['discount_code_value'] : '';
                        $value->image = isset($extra_params['image']) ? $extra_params['image'] : '';
                        $value->net_service_cost = isset($extra_params['net_service_cost']) ? $extra_params['net_service_cost'] : '';
                        $value->rezgo_id = isset($extra_params['rezgo_id']) ? $extra_params['rezgo_id'] : '';
                        $value->rezgo_ticket_price = isset($extra_params['rezgo_ticket_price']) ? $extra_params['rezgo_ticket_price'] : '';
                        $value->rezgo_ticket_id = isset($extra_params['rezgo_ticket_id']) ? $extra_params['rezgo_ticket_id'] : '';
                        $value->is_iticket_product = isset($extra_params['is_iticket_product']) ? $extra_params['is_iticket_product'] : '';
                        $value->last_imported_date = isset($extra_params['last_imported_date']) ? $extra_params['last_imported_date'] : gmdate('Y-m-d h:i:s');
                        $value->updated_at = isset($extra_params['updated_at']) ? $extra_params['updated_at'] : gmdate('Y-m-d h:i:s');
                        $value->order_confirm_date = isset($extra_params['order_confirm_date']) ? $extra_params['order_confirm_date'] : gmdate('Y-m-d H:i:s');
                        $value->supplier_tax = isset($extra_params['supplier_tax']) ? $extra_params['supplier_tax'] : '';
                        $value->supplier_net_price = isset($extra_params['supplier_net_price']) ? $extra_params['supplier_net_price'] : '';
                        $value->batch_id = isset($extra_params['batch_id']) ? $extra_params['batch_id'] : 0;
                        $value->supplier_original_price = isset($extra_params['supplier_original_price']) ? $extra_params['supplier_original_price'] : 0;
                        $value->supplier_discount = isset($extra_params['supplier_discount']) ? $extra_params['supplier_discount'] : 0;
                        $value->redeem_by_ticket_id = isset($extra_params['redeem_by_ticket_id']) ? $extra_params['redeem_by_ticket_id'] : 0;
                        $value->redeem_by_ticket_title = isset($extra_params['redeem_by_ticket_title']) ? $extra_params['redeem_by_ticket_title'] : 0;
                        $logs['cashier_register_id_get_data'] = array($value->shift_id, $extra_params['pos_point_id_on_redeem'], $value->hotel_id, $extra_params['distributor_cashier_id_on_redeem']);
                        if($extra_params['distributor_cashier_id_on_redeem'] != '0' && $extra_params['pos_point_id_on_redeem'] != '0')  {
                            $cashier_register_id = $this->get_cashier_register_id($value->shift_id, $extra_params['pos_point_id_on_redeem'], $value->hotel_id, $extra_params['distributor_cashier_id_on_redeem']);
                            $confirm_data['cashier_register_id'] = $value->cashier_register_id = $cashier_register_id;
                            $logs['cashier_register_id__db_query'] = array($cashier_register_id, $this->db->last_query());
                        }
                    }
                    
                    $value->booking_status = '1';
                    $reseller_id = $value->reseller_id;
                    $reseller_name = $value->reseller_name;
                    $saledesk_id = $value->saledesk_id;
                    $saledesk_name = $value->saledesk_name;
                    if($value->redeem_date_time == null) {
                                   unset($value->redeem_date_time);
                    }
                    /*** $value->scanned_at = !empty($value->scanned_at) ? $value->scanned_at : strtotime(gmdate("Y-m-d H:i:s")); ***/
                    
                    if (!empty($value->voucher_creation_date)) {                    
                        $confirm_data['voucher_creation_date'] = $value->voucher_creation_date;
                    }  
                    $confirm_data['pertransaction'] = "0";
                    $discount_data = unserialize($value->extra_discount);
                    $confirm_data['order_currency_extra_discount'] = $value->extra_discount;
                    $ticket_details[$value->ticket_id] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $value->ticket_id));
                    $hotel_info = $this->common_model->companyName($value->hotel_id);

                    $confirm_data['creation_date'] = ($value->scanned_at != '') ? $value->scanned_at : $value->created_at;
                    $confirm_data['museum_id'] = $value->museum_id;
                    $confirm_data['museum_name'] = $value->museum_name;
                    $confirm_data['extra_discount'] = $value->extra_discount;
                    $confirm_data['cluster_ticket_net_price'] = $value->net_price;
                    $confirm_data['ticketsales'] = $value->is_prepaid;
                    $confirm_data['merchant_admin_id'] = $ticket_details[$value->ticket_id]->merchant_admin_id;
                    $confirm_data['merchant_admin_name'] = $ticket_details[$value->ticket_id]->merchant_admin_name;
                    $confirm_data['pax'] = $value->pax;
                    $confirm_data['capacity'] = $value->capacity;
                    $confirm_data['hotel_id'] = $hotel_id;
                    $confirm_data['hotel_name'] = $hotel_name;
                    $confirm_data['distributor_partner_id']   = $value->distributor_partner_id;
                    $confirm_data['distributor_partner_name'] = $value->distributor_partner_name;
                    $confirm_data['passNo'] = $value->passNo;
                    $confirm_data['pass_type'] = '0';
                    $confirm_data['is_ripley_pass'] = 0;
                    $confirm_data['visitor_group_no'] = $value->visitor_group_no;
                    $confirm_data['ticket_booking_id'] = $value->ticket_booking_id;
                    $confirm_data['ticketId'] = $value->ticket_id;
                    $confirm_data['reseller_id'] = $reseller_id;
                    $confirm_data['reseller_name'] = $reseller_name;
                    $confirm_data['saledesk_id'] = $saledesk_id;
                    $confirm_data['saledesk_name'] = $saledesk_name;
                    $confirm_data['is_combi_discount'] = $value->is_combi_discount;
                    $confirm_data['combi_discount_gross_amount'] = $value->combi_discount_gross_amount;
                    $confirm_data['price'] = $value->price + $value->combi_discount_gross_amount;
                    $confirm_data['discount'] = $value->discount;
                    $confirm_data['discount_type'] = $discount_data['discount_type'];
                    $confirm_data['new_discount'] = $discount_data['new_discount'];
                    $confirm_data['gross_discount_amount'] = $discount_data['gross_discount_amount'];
                    $confirm_data['net_discount_amount'] = $discount_data['net_discount_amount'];
                    $confirm_data['service_gross'] = 0;
                    $confirm_data['service_cost_type'] = 0;
                    $confirm_data['pertransaction'] = "0";
                    $confirm_data['visit_date'] = $value->scanned_at;
                    $confirm_data['clustering_id'] = $value->clustering_id;

                    if($value->channel_type == '5') {
                        $pre_assigned_pass = '5';
                        $confirm_data['main_tps_id'] = $extra_params['main_tps_id'];
                    } else {
                        $pre_assigned_pass = '0';
                    }
                    //in case of website repley passes are save without http://qb.vg,
                    //so in case of repley ticket we are fetching records on base of visitor group no from hto table
                    $confirm_data['initialPayment'] = $this->order_process_vt_model->getInitialPaymentDetail((array) $value, $hotel_data[0]);
                    $logs['get_initialPayment_query_'.date('H:i:s')] = $this->primarydb->db->last_query();
                    $confirm_data['ticketpriceschedule_id'] = $value->tps_id;
                    $confirm_data['ticketwithdifferentpricing'] = $ticket_details[$value->ticket_id]->ticketwithdifferentpricing;
                    $confirm_data['selected_date'] = $value->selected_date != '' &&  $value->selected_date != null && $value->selected_date != '0' && $value->from_time != '' && $value->from_time != null && $value->from_time != '0' ? $value->selected_date : gmdate("Y-m-d", strtotime($value->order_confirm_date));
                    /*** $confirm_data['selected_date'] = gmdate("Y-m-d", strtotime($value->order_confirm_date)); ***/
                    $confirm_data['from_time'] = $value->from_time;
                    $confirm_data['to_time'] = $value->to_time;
                    $confirm_data['slot_type'] = $value->timeslot;
                    $confirm_data['prepaid_type'] = $value->activation_method;
                    $confirm_data['userid'] = '0';
                    $confirm_data['fname'] = 'Prepaid';
                    $confirm_data['lname'] = 'ticket';
                    $confirm_data['financial_id'] = $value->financial_id;
                    $confirm_data['financial_name'] = $value->financial_name;
                    $confirm_data['is_prioticket'] = $value->is_prioticket;
                    $confirm_data['check_age'] = 0;
                    $confirm_data['cmpny'] = $hotel_info;
                    $confirm_data['timeZone'] = $value->timezone;
                    $confirm_data['used'] = $value->used;
                    $confirm_data['pos_point_id'] = $value->pos_point_id;
                    $confirm_data['shift_id'] = $value->shift_id;
                    $confirm_data['pos_point_name'] = $value->pos_point_name;
                    $confirm_data['booking_status'] = '1';
                    $confirm_data['is_prepaid'] = $value->is_prepaid;
                    $confirm_data['is_voucher'] = $value->is_voucher;
                    $confirm_data['is_shop_product'] = $value->product_type;
                    $confirm_data['is_pre_ordered'] = 1;
                    $confirm_data['order_status'] = $value->order_status;
                    $confirm_data['without_elo_reference_no'] = $value->without_elo_reference_no;
                    $confirm_data['ticketDetail'] = $ticket_details[$value->ticket_id];
                    $confirm_data['prepaid_ticket_id'] = $value->prepaid_ticket_id;
                    $confirm_data['is_addon_ticket'] = $value->is_addon_ticket;  
                    $confirm_data['action_performed'] = $value->action_performed; 
                    $confirm_data['channel_type'] = $value->channel_type; 
                    $confirm_data['shared_capacity_id'] = $value->shared_capacity_id; 
                    $confirm_data['updated_at']       = gmdate('Y-m-d H:i:s');
                    $confirm_data['userid']           = $value->museum_cashier_id;
                    $user_name = explode(' ', $value->museum_cashier_name);
                    $confirm_data['fname']            = $user_name[0];
                    $confirm_data['lname']            = $user_name[1];
                    $confirm_data['supplier_gross_price'] = $value->supplier_price;
                    $confirm_data['supplier_discount']    = $value->supplier_discount;
                    $confirm_data['supplier_ticket_amt']  = $value->supplier_original_price;
                    $confirm_data['supplier_tax_value']   = $value->supplier_tax;
                    $confirm_data['supplier_net_price']   = $value->supplier_net_price;
                    $confirm_data['tp_payment_method']  =  $value->tp_payment_method;
                    $confirm_data['order_confirm_date'] =  $value->order_confirm_date;
                    $confirm_data['scanned_pass'] =  $value->bleep_pass_no;
                    $confirm_data['ticket_status'] = $value->activated;
                    if($value->channel_type == '5') {
                        $confirm_data['voucher_updated_by'] =  $value->voucher_updated_by;
                        $confirm_data['voucher_updated_by_name'] =  $value->voucher_updated_by_name;
                    } 
                    
                    //commission_json for main ticket -> value from preassigned codes                    
                    $value->commission_json = $commission_json;
                    /***
                    if($db_type == 4) {
                        unset($value->extra_booking_information);
                    }
                    ***/
                    $confirm_data['commission_json'] = $value->commission_json;
                    $logs['confirm_array_' . date('H:i:s')] = $confirm_data;
                    
                    $this->CreateLog('confirm_data.php', "confirm_data", array(json_encode($confirm_data)));
                    
                    $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, $db_type);
                    $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                    $logs['final_visitor_data_' . date('H:i:s')] = $final_visitor_data;
                    $museum_net_fee = 0.00;
                    $hgs_net_fee = 0.00;
                    $distributor_net_fee = 0.00;
                    $museum_gross_fee = 0.00;
                    $hgs_gross_fee = 0.00;
                    $distributor_gross_fee = 0.00;
                    if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {
                        foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $visitor_row_batch) {
                            if ($visitor_row_batch['row_type'] == '2') {
                                $museum_net_fee = $visitor_row_batch['partner_net_price'];
                                $museum_gross_fee = $visitor_row_batch['partner_gross_price'];
                            } else if ($visitor_row_batch['row_type'] == '3') {
                                $distributor_net_fee = $visitor_row_batch['partner_net_price'];
                                $distributor_gross_fee = $visitor_row_batch['partner_gross_price'];
                            } else if ($visitor_row_batch['row_type'] == '4') {
                                $hgs_net_fee = $visitor_row_batch['partner_net_price'];
                                $hgs_gross_fee = $visitor_row_batch['partner_gross_price'];
                            }
                        }
                    }
                    $value->museum_net_fee = $museum_net_fee;
                    $value->distributor_net_fee = $distributor_net_fee;
                    $value->hgs_net_fee =  $hgs_net_fee;
                    $value->museum_gross_fee = $museum_gross_fee;
                    $value->distributor_gross_fee = $distributor_gross_fee;
                    $value->hgs_gross_fee = $hgs_gross_fee;
                    $value->selected_date = $value->selected_date != '' &&  $value->selected_date != NULL && $value->selected_date != '0' && $value->from_time != '' && $value->from_time != NULL && $value->from_time != '0' ? $value->selected_date : gmdate("Y-m-d", strtotime($value->order_confirm_date));
                    /*** $value->selected_date = gmdate("Y-m-d", strtotime($value->order_confirm_date)); ***/
                    $prepaid_table = 'prepaid_tickets';
                    $vt_table = 'visitor_tickets';
                    $db->insert($prepaid_table, $this->common_model->object_to_array($value));
                    $logs['insert_pt_data_'.$key.'_'.date('H:i:s')]=array($db->last_query(), $value);                    
                    /***
                     * $db->update('prepaid_tickets', array('visitor_tickets_id' => $visitor_tickets_data['id']), array('prepaid_ticket_id' => $value->prepaid_ticket_id));
                    **/
                }
                $this->CreateLog('order_visitor_group_no.php', "final_visitor_data", array(json_encode($final_visitor_data)));
                if (!empty($final_visitor_data)) {
                    $this->insert_batch($vt_table, $final_visitor_data, $db_type);
                    $final_visitor_data_to_insert_big_query = $final_visitor_data;
                }

                // Fetch extra options
                $this->primarydb->db->select('*');
                $this->primarydb->db->from('prepaid_extra_options');
                $this->primarydb->db->where('visitor_group_no', $order_no);
                $result = $this->primarydb->db->get();
                if ($result->num_rows() > 0) {
                    $paymentMethod = $hotel_overview->paymentMethod;
                    $pspReference = $hotel_overview->pspReference;

                    if ($paymentMethod == '' && $pspReference == '') {
                        $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                    } else {
                        $payment_method = 'Others'; //   others
                    }
                    $extra_services = $result->result_array();
                    $taxes = array();
                    $eoc = 800;
                    foreach ($extra_services as $service) {
                        $service['used'] = '1';
                        $service['scanned_at'] = strtotime(gmdate("Y-m-d H:i:s"));
                        // insert in DB2 only
                        if($db_type != '4' ) {
                            $db->insert('prepaid_extra_options', $service);
                        }

                        // If quantity of service is more than one then we add multiple transactions for financials page
                        if (!in_array($service['tax'], $taxes)) {
                            $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                            $taxes[$service['tax']] = $ticket_tax_id;
                        } else {
                            $ticket_tax_id = $taxes[$service['tax']];
                        }

                        $ticket_tax_value = $service['tax'];
                       
                        for ($i = 0; $i < $service['quantity']; $i++) {
                            $eoc++;
                            $p = 0;
                            $service_data_for_visitor = array();
                            $service_data_for_museum = array();

                            $total_amount = $service['price'];
                            $order_curency_total_amount = $service['order_currency_price'];
                            $ticket_id = $service['ticket_id'];
                            /*** $x = $eoc + $i; ***/
                            $transaction_id = $visitor_group_no."".$eoc;
                            $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '1');
                    
                            /***
                            $transaction_id = $this->get_auto_generated_id($visitor_group_no, $service['prepaid_extra_options_id'], $i);
                            $visitor_ticket_id = $this->get_auto_generated_id($visitor_group_no, $service['prepaid_extra_options_id'], $i . '1');
                            ***/
                            $service_data_for_visitor['id'] = $visitor_ticket_id;
                            $service_data_for_visitor['is_prioticket'] = 0;
                            $service_data_for_visitor['created_date'] = gmdate('Y-m-d H:i:s');
                            $service_data_for_visitor['transaction_id'] = $transaction_id;
                            $service_data_for_visitor['visitor_group_no'] = $hotel_overview->visitor_group_no;
                            $service_data_for_visitor['invoice_id'] = '';
                            $service_data_for_visitor['reseller_id'] = $ticket_id;
                            $service_data_for_visitor['reseller_name'] = $ticket_id;
                            $service_data_for_visitor['ticketId'] = $ticket_id;
                            $service_data_for_visitor['ticket_title'] = $museum_name . '~_~' . $service['description'];
                            $service_data_for_visitor['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                            $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                            $service_data_for_visitor['reseller_id'] = $reseller_id;
                            $service_data_for_visitor['reseller_name'] = $reseller_name;
                            $service_data_for_visitor['saledesk_id'] = $saledesk_id;
                            $service_data_for_visitor['saledesk_name'] = $saledesk_name;
                            $service_data_for_visitor['distributor_partner_id']   = $value->distributor_partner_id;
                            $service_data_for_visitor['distributor_partner_name'] =  $value->distributor_partner_name;
                            $service_data_for_visitor['selected_date'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['selected_date'] : '';
                            $service_data_for_visitor['from_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['from_time'] : '';
                            $service_data_for_visitor['to_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['to_time'] : '';
                            $service_data_for_visitor['slot_type'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['timeslot'] : '';
                            $service_data_for_visitor['partner_id'] = $hotel_info->cod_id;
                            $service_data_for_visitor['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
                            $service_data_for_visitor['paid'] = "1";
                            $service_data_for_visitor['payment_method'] = $payment_method;
                            $service_data_for_visitor['captured'] = "1";
                            $service_data_for_visitor['debitor'] = "Guest";
                            $service_data_for_visitor['creditor'] = "Debit";
                            $service_data_for_visitor['partner_gross_price'] = $total_amount;
                            $service_data_for_visitor['order_currency_partner_gross_price'] = $order_curency_total_amount;
                            $service_data_for_visitor['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                            $service_data_for_visitor['order_currency_partner_net_price'] = ($order_curency_total_amount * 100) / ($ticket_tax_value + 100);
                            $service_data_for_visitor['tax_id'] = $ticket_tax_id;
                            $service_data_for_visitor['tax_value'] = $ticket_tax_value;
                            $service_data_for_visitor['invoice_status'] = "0";
                            $service_data_for_visitor['transaction_type_name'] = "Extra option sales";
                            $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                            $service_data_for_visitor['paymentMethodType'] = $hotel_info->paymentMethodType;
                            $service_data_for_visitor['row_type'] = "1";
                            $service_data_for_visitor['isBillToHotel'] = $hotel_overview->isBillToHotel;
                            $service_data_for_visitor['activation_method'] = $hotel_overview->activation_method;
                            $service_data_for_visitor['channel_type'] = $hotel_overview->channel_type;
                            $service_data_for_visitor['vt_group_no'] = $hotel_overview->visitor_group_no;
                            $service_data_for_visitor['visit_date_time'] = gmdate('m/d/Y H:i:s');
                            $service_data_for_visitor['ticketAmt'] = $total_amount;
                            $service_data_for_visitor['debitor'] = "Guest";
                            $service_data_for_visitor['creditor'] = "Debit";
                            $service_data_for_visitor['timezone'] = $hotel_overview->timezone;
                            $service_data_for_visitor['is_prepaid'] = "1";
                            $service_data_for_visitor['booking_status'] = "1";
                            $service_data_for_visitor['used'] = "1";
                            $service_data_for_visitor['hotel_id'] = $hotel_id;
                            $service_data_for_visitor['hotel_name'] = $hotel_name;
                            $service_data_for_visitor['action_performed'] = '0, PRE_ASSIGN_CNF';
                            $service_data_for_visitor['updated_at'] = gmdate('Y-m-d H:i:s');
                            
                            $final_visitor_data_to_insert_big_query[] = $service_data_for_visitor;
                            
                            $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '2');
                            $service_data_for_museum['id'] = $visitor_ticket_id;
                            $service_data_for_visitor['is_prioticket'] = 0;
                            $service_data_for_museum['created_date'] = gmdate('Y-m-d H:i:s');
                            $service_data_for_museum['transaction_id'] = $transaction_id;
                            $service_data_for_museum['invoice_id'] = '';
                            $service_data_for_museum['ticketId'] = $ticket_id;
                            $service_data_for_museum['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                            $service_data_for_museum['ticket_title'] = $service['name'] . ' (Extra option)';
                            $service_data_for_museum['partner_id'] = $museum_id;
                            $service_data_for_museum['partner_name'] = $museum_name;
                            $service_data_for_museum['distributor_partner_id']   = $value->distributor_partner_id;
                            $service_data_for_museum['distributor_partner_name'] =  $value->distributor_partner_name;
                            $service_data_for_museum['museum_name'] = $museum_name;
                            $service_data_for_museum['ticketAmt'] = $total_amount;
                            $service_data_for_museum['vt_group_no'] = $hotel_overview->visitor_group_no;
                            $service_data_for_museum['reseller_id'] = $reseller_id;
                            $service_data_for_museum['reseller_name'] = $reseller_name;
                            $service_data_for_museum['saledesk_id'] = $saledesk_id;
                            $service_data_for_museum['saledesk_name'] = $saledesk_name;
                            $service_data_for_museum['visit_date_time'] = gmdate('m/d/Y H:i:s');
                            $service_data_for_museum['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
                            $service_data_for_museum['ticketPrice'] = $total_amount;
                            $service_data_for_museum['paid'] = "0";
                            $service_data_for_museum['isBillToHotel'] = $hotel_overview->isBillToHotel;
                            $service_data_for_museum['debitor'] = $museum_name;
                            $service_data_for_museum['creditor'] = 'Credit';
                            $service_data_for_museum['commission_type'] = "0";
                            $service_data_for_museum['partner_gross_price'] = $total_amount;
                            $service_data_for_museum['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                            $service_data_for_museum['isCommissionInPercent'] = "0";
                            $service_data_for_museum['tax_id'] = $ticket_tax_id;
                            $service_data_for_museum['tax_value'] = $ticket_tax_value;
                            $service_data_for_museum['invoice_status'] = "0";
                            $service_data_for_museum['row_type'] = "2";
                            $service_data_for_museum['paymentMethodType'] = $hotel_info->paymentMethodType;
                            $service_data_for_museum['service_name'] = SERVICE_NAME;
                            $service_data_for_museum['transaction_type_name'] = "Extra service cost";
                            $service_data_for_museum['is_prepaid'] = "1";
                            $service_data_for_museum['channel_type'] = $hotel_overview->channel_type;
                            $service_data_for_museum['used'] = "1";
                            $service_data_for_museum['booking_status'] = "1";
                            $service_data_for_museum['timezone'] = $hotel_overview->timezone;
                            $service_data_for_museum['hotel_id'] = $hotel_id;
                            $service_data_for_museum['hotel_name'] = $hotel_name;
                            $service_data_for_museum['action_performed'] = '0, PRE_ASSIGN_CNF';
                            $service_data_for_museum['updated_at'] = gmdate('Y-m-d H:i:s');
                            
                            $final_visitor_data_to_insert_big_query[] = $service_data_for_museum;
                            
                            $db->insert($vt_table, $service_data_for_museum);
                        }
                    }
                }
		
		        /** code start to publish visitor data to insert in aggregate bigquery table */
                if($db_type == '1') {
                    
                    if(in_array($channel_type, array('5')) && !empty($pass_number)) {

                        /* Get data on the basis of passNo if channel_type='5' */
                        $final_visitor_data_to_insert_big_query = $this->secondarydb->db->query('SELECT activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id FROM visitor_tickets WHERE passNo = "' . $pass_number . '"')->result_array();
                        $this->CreateLog('big_query_agg_vt_insert_update_order_table.php', 'final_visitor_data_vt_query_for_channel_type_5_', array("passNo" => $pass_number, 'final_visitor_data_vt_query_for_big_query' => $this->secondarydb->db->last_query()));
                        $this->CreateLog('big_query_agg_vt_insert_update_order_table.php', 'final_visitor_data_vt_data_for_channel_type_5_', array("passNo" => $pass_number, 'final_visitor_data_vt_data_for_big_query' => json_encode($final_visitor_data_to_insert_big_query)));

                        $results = $this->secondarydb->db->query('SELECT * FROM prepaid_tickets WHERE passNo="'.$pass_number.'"')->result();
                        $this->CreateLog('big_query_agg_pt_insert_update_order_table.php', 'final_visitor_data_pt_query_for_channel_type_5_', array("passNo" => $pass_number, 'final_visitor_data_pt_query_for_big_query' => $this->secondarydb->db->last_query()));
                        $this->CreateLog('big_query_agg_pt_insert_update_order_table.php', 'final_prepaid_data_to_insert_big_query_compressed', array("passNo" => $pass_number, 'final_prepaid_data_to_insert_big_query_compressed' => json_encode($results)));

                        if(!empty($results) && SERVER_ENVIRONMENT != 'Local') {

                            $aws_message2 = json_encode($results);
                            $aws_message2 = base64_encode(gzcompress($aws_message2));
                            $logs['data_to_queue_LIVE_SCAN_REPORT_QUEUE_URL_' . date("H:i:s")] = $aws_message2;
                            $MessageIdss = $sqs_object->sendMessage(LIVE_SCAN_REPORT_QUEUE_URL, $aws_message2);
                            if ($MessageIdss) {
                                $sns_object->publish($MessageIdss, LIVE_SCAN_REPORT_QUEUE_URL_ARN);
                            }

                            /* Select data and sync on BQ */
                            $this->order_process_model->getVtDataToSyncOnBQ($visitor_group_no);
                        }
                    }

                    if (!empty($final_visitor_data_to_insert_big_query)) {
                        $this->CreateLog('big_query_agg_vt_insert_update_order_table.php', 'final_visitor_data_to_insert_big_query', array("visitor_group_no" => $visitor_group_no, 'final_visitor_data_to_insert_big_query' => json_encode($final_visitor_data_to_insert_big_query)));
                        $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress(json_encode($final_visitor_data_to_insert_big_query)));

                        if (!empty($final_visitor_data_to_insert_big_query_compressed)) {

                            if (SERVER_ENVIRONMENT == 'Local') {
                                local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
                            } else {
                                $this->CreateLog('big_query_agg_vt_insert_update_order_table.php', 'final_visitor_data_to_insert_big_query_compressed', array("visitor_group_no" => $visitor_group_no, 'final_visitor_data_to_insert_big_query_compressed' => json_encode($final_visitor_data_to_insert_big_query_compressed)));
                                /*$this->load->library('Gpubsub')
                                $google_pub = new Gpubsub()
                                $topicName = BIG_QUERY_AGG_VT_INSERT_TOPIC          
                                $google_pub->publish_message($topicName, $final_visitor_data_to_insert_big_query_compressed)*/

                                $agg_vt_insert_queueUrl = AGG_VT_INSERT_QUEUEURL;
                                // This Fn used to send notification with data on AWS panel. 
                                $MessageId = $sqs_object->sendMessage($agg_vt_insert_queueUrl, $final_visitor_data_to_insert_big_query_compressed);
                                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                if ($MessageId) {
                                    $sns_object->publish($agg_vt_insert_queueUrl, AGG_VT_INSERT_ARN);
                                }

                            }
                        }
                    }
                }
                /**
         * code end to publish visitor data to insert in aggregate bigquery table 
        */
            }

            if(SYNCH_WITH_RDS) {
                $aws_data = array();
                $aws_data['rds']['prepaid_tickets'] = 1;
                $aws_data['rds']['visitor_group_number'] = !empty($order_visitor_group_no) ? $order_visitor_group_no : 0;
                $aws_data['rds']['visitor_tickets'] = 1;
                $aws_data['rds']['delete_all_records'] = 1;

                $aws_message = base64_encode(gzcompress(json_encode($aws_data)));
                $MessageId = $sqs_object->sendMessage(RDS_UPDATE_QUEUE_URL, $aws_message);
                if ($MessageId) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $sns_object->publish('hello', RDS_UPDATE_TOPIC_ARN);
                }    
            }
        }
    }
    /* #endregion function insert pre-assigned pass data in DB2 */

    /* #region To insert data of main tables in secondary DB.  */
    /**
     * update_visitor_tickets_direct
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $pass_no
     * @param  mixed $prepaid_ticket_id
     * @param  mixed $hto_id
     * @param  mixed $action_performed
     * @param  mixed $sns_message_pt
     * @param  mixed $sns_message_vt
     * @param  mixed $ticket_id
     * @param  mixed $db_type
     * @param  mixed $order_confirm_date
     * @param  mixed $all_prepaid_ticket_ids
     * @param  mixed $voucher_updated_by
     * @param  mixed $voucher_updated_by_name
     * @param  mixed $scanned_at
     * @param  mixed $multiple_hto_ids
     * @param  mixed $pre_selected_date
     * @param  mixed $pre_from_time 
     * @param  mixed $pre_to_time
     * @return void
     * @author Davinder singh<davinder.intersoft@gmail.com> onNovemver, 2016
     */
    function update_visitor_tickets_direct($visitor_group_no, $pass_no = '', $prepaid_ticket_id = '', $hto_id = '', $action_performed = '', $sns_message_pt = '', $sns_message_vt = '', $ticket_id = '', $db_type = '1', $order_confirm_date = '',$all_prepaid_ticket_ids = '', $voucher_updated_by = 0, $voucher_updated_by_name = '',$scanned_at = '', $multiple_hto_ids = '', $pre_selected_date = '', $pre_from_time = '', $pre_to_time = '', $multiple_vgns = array(), $hto_update_for_card = array()) 
    {
        global $MPOS_LOGS;
        $logs['request_'.date('H:i:s')]=array('visitor_group_no'=>$visitor_group_no,'pass_no'=>$pass_no,'prepaid_ticket_id'=>$prepaid_ticket_id,'hto_id'=>$hto_id,'action_performed'=>$action_performed,'sns_message_pt'=>$sns_message_pt,'sns_message_vt'=>$sns_message_vt,'order_confirm_date'=>$order_confirm_date, 'scanned_at' => $scanned_at, "multiple_hto_ids" => $multiple_hto_ids, "ticket_id" => $ticket_id, "all_prepaid_ticket_ids" => $all_prepaid_ticket_ids, 'multiple_vgns' => json_encode($multiple_vgns), "hto_update_for_card" => $hto_update_for_card);
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        $update_credit_limit_data = array();
        if ($db_type == '1') {
            $db = $this->secondarydb->db;
        } else {
            $db = $this->db;
        }
        $this->secondarydb->rodb->select('*');
        $this->secondarydb->rodb->from('hotel_ticket_overview');
        // to get the data bases on pass number or vt number
        if(!empty($hto_id) ) {
            $this->secondarydb->rodb->where('id', $hto_id);
        } else if(!empty($multiple_hto_ids) ) { //for mpos contiki cases.
            $this->secondarydb->rodb->where_in('id', $multiple_hto_ids);
        } else {
            if(!empty($pass_no) ) {
                if(!strstr($pass_no, 'http') && strlen($pass_no) == 6) {
                    if(strstr($pass_no, '-')) {
                        $this->secondarydb->rodb->where('passNo', 'http://qb.vg/'.$pass_no);
                    } else{
                        $this->secondarydb->rodb->where('passNo',  'http://qu.mu/'.$pass_no);
                    }                    
                } else{
                    $this->secondarydb->rodb->where('passNo', $pass_no);
                }                
            } else if (!empty($visitor_group_no)) {
                $this->secondarydb->rodb->where('visitor_group_no', $visitor_group_no);
            } else if (!empty($multiple_vgns)) {
                $this->secondarydb->rodb->where_in('visitor_group_no', implode(',', $multiple_vgns));
            }
        }

        $this->CreateLog('update_visitor_tickets_direct.php', 'response2', array('visitor_group_no' => $visitor_group_no, 'pass_no' => $pass_no, 'pt_id' => $prepaid_ticket_id, 'hto_id' => $hto_id, 'action' => $action_performed, 'all_prepaid_ticket_ids' => $all_prepaid_ticket_ids, '$voucher_updated_by' => $voucher_updated_by, '$voucher_updated_by_name' => $voucher_updated_by_name, 'hto_query' => $this->secondarydb->rodb->last_query()));

        $this->secondarydb->rodb->limit(1);
        $overview_result = $this->secondarydb->rodb->get();
        $logs['db2_HTO_query_'.date('H:i:s')]=$this->secondarydb->rodb->last_query();
        if ($overview_result->num_rows() > 0) {
            $overview_data = $overview_result->result_array();
            $logs['sizeof_overview_data_'.date('H:i:s')] = sizeof($overview_data);
            $logs['multiple_hto_ids_'.date('H:i:s')] = $multiple_hto_ids;
            $hotel_overview = $overview_result->result()[0];       
            
            $prepaid_table ='prepaid_tickets';
            $vt_table ='visitor_tickets';
            foreach ($overview_data as $value) {
                if($multiple_hto_ids == '') { //normal flow ===>  $multiple_hto_ids will be there only for mpos contiki cases.
                    $last_element = sizeof($overview_data) - 1;
                    $hotel_data = $overview_data[$last_element];
                    $overview_data = array();
                }
                $is_prioticket = $hotel_data['is_prioticket'];
                $service_cost = 0;
                $selected_date = '';
                $from_time = '';
                $to_time = '';
                $slot_type = '';
                $pos_point_id = 0;
                $pos_point_name = '';
                $channel_id = 0;
                $channel_name = '';
                $cashier_id = 0;
                $cashier_name = '';
                $booking_status = 0;
                $visitor_ids_array = array();
                $pt_where_query = '';
                // to get the data bases on pass number or vt number
                if(!empty($prepaid_ticket_id) ) {
                    $pt_where_query .= ' AND prepaid_ticket_id = "'.$prepaid_ticket_id.'"';
                } else {
                    if(!empty($ticket_id)) {
                        $pt_where_query .= ' AND ticket_id = "'.$ticket_id.'"';
                    }
                    if (!empty($pass_no)) {
                        $pt_where_query .= ' AND passNo = "'.$pass_no.'"';
                    } else if (!empty($visitor_group_no)) {
                        $pt_where_query .= ' AND visitor_group_no = "'.$visitor_group_no.'"';
                    } else if (!empty($multiple_vgns)) {
                        $pt_where_query .= ' AND visitor_group_no  IN ("' . implode('","', $multiple_vgns) . '")';
                    } 
                    if($all_prepaid_ticket_ids != '') {
                        $pt_where_query .= ' AND prepaid_ticket_id IN ('.$all_prepaid_ticket_ids.')';
                    }
                }
                if ($pre_selected_date != '') {
                    $pt_where_query .= ' AND selected_date = "'.$pre_selected_date.'"';
                }
                if ($pre_from_time != '') {
                    $pt_where_query .= ' AND from_time = "'.$pre_from_time.'"';
                }
                if ($pre_to_time != '') {
                    $pt_where_query .= ' AND to_time = "'.$pre_to_time.'"';
                }
                //when script not run mannually ( only for venue app condition need to be run )
                // update PT of DB2
                if( !empty($sns_message_pt) && isset($sns_message_pt) ) {
                    foreach ($sns_message_pt as $query) {
                        $query = trim($query);
                        $this->secondarydb->db->query($query);
                        // To update the data in RDS realtime
                        if(( strstr($query, 'visitor_tickets') || strstr($query, 'prepaid_tickets') || strstr($query, 'hotel_ticket_overview') ) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0') ) {
                            $this->fourthdb->db->query($query);                        
                        }
                    }
                }
                
                $pt_query = 'SELECT * FROM '.$prepaid_table.' pt1 WHERE deleted = "0" AND is_refunded = "0" AND is_cancelled = "0" AND order_status IN ("0","3") '.$pt_where_query.' AND pt1.version = (SELECT MAX(version) FROM '.$prepaid_table.' pt2 WHERE pt1.prepaid_ticket_id = pt2.prepaid_ticket_id '.$pt_where_query.') order by is_addon_ticket ASC';
                $prepaid_ticket_records     = $this->secondarydb->db->query($pt_query)->result();                
                $logs['db2_query_PT_'.date('H:i:s')] = $this->secondarydb->db->last_query();
                
                $this->CreateLog('update_visitor_tickets_direct.php', 'pt records', array($this->secondarydb->db->last_query()));

                $this->CreateLog('update_visitor_tickets_direct.php', 'pt records', array(json_encode($prepaid_ticket_records)));
                if (!empty($prepaid_ticket_records)) {
                    $prepaid_data = $prepaid_ticket_records;
                    $final_visitor_data = array();
                    $sns_messages = array();
                    foreach ($prepaid_data as $key => $value) {
                        if ($key == 0) {
                            $ticket_id = $value->ticket_id;
                            $museum_id = $value->museum_id;
                            $museum_name = $value->museum_name;
                            $hotel_id = $value->hotel_id;
                            $hotel_name = $value->hotel_name;
                            $visitor_group_no = $value->visitor_group_no;
                        }
                        $value->booking_status = '1';
                        if( $action_performed == 3  || $action_performed == 'ADMN_GM') {
                            $value->used = '1';
                        }
                        $voucher_reference = '';
                        if(!empty($value->extra_booking_information)) {
                            $prepaid_extra_booking_information = json_decode(stripslashes(stripslashes($value->extra_booking_information)), true); 
                            $voucher_reference = $prepaid_extra_booking_information['voucher_reference'];
                        }
                        $reseller_id = $value->reseller_id;
                        $reseller_name = $value->reseller_name;
                        $saledesk_id = $value->saledesk_id;
                        $saledesk_name = $value->saledesk_name;
                        $value->scanned_at = !empty($value->scanned_at) ? $value->scanned_at : strtotime(gmdate("Y-m-d H:i:s"));

                        $confirm_data = array();
                        $confirm_data['pertransaction'] = "0";
                        $discount_data = unserialize($value->extra_discount);
                        $ticket_details[$value->ticket_id] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $value->ticket_id));
                        $hotel_info = $this->common_model->companyName($value->hotel_id);
                        $version = $value->version;
                        if($action_performed == 'ADMN_GM') {
                            $version += 1;
                        }
                        $confirm_data['voucher_updated_by'] = $confirm_data['resuid'] = $value->voucher_updated_by;
                        $confirm_data['version'] = $version;
                        $confirm_data['voucher_reference'] = $voucher_reference;
                        $confirm_data['resfname'] = $value->voucher_updated_by_name;
                        $confirm_data['used'] = $value->used;
                        $confirm_data['museum_id'] = $value->museum_id;
                        $confirm_data['museum_name'] = $value->museum_name;
                        $confirm_data['hotel_id'] = $hotel_id;
                        $confirm_data['hotel_name'] = $hotel_name;
                        $confirm_data['distributor_partner_id'] = $value->distributor_partner_id;
                        $confirm_data['distributor_partner_name'] = $value->distributor_partner_name;
                        $confirm_data['partner_category_id'] = isset($value->partner_category_id) ? $value->partner_category_id : "";
                        $confirm_data['shift_id'] = isset($value->shift_id) ? $value->shift_id : "";
                        $confirm_data['pos_point_name'] = isset($value->pos_point_name) ? $value->pos_point_name : "";
                        $confirm_data['partner_category_name'] = isset($value->partner_category_name) ? $value->partner_category_name : "";
                        $confirm_data['visit_date'] = (isset($value->scanned_at) && $value->scanned_at != '') ? $value->scanned_at : strtotime(gmdate('Y-m-d H:i:s'));
                        $confirm_data['redeem_method'] = $value->redeem_method;
                        $confirm_data['order_updated_cashier_id'] = $value->order_updated_cashier_id;
                        $confirm_data['order_updated_cashier_name'] = $value->order_updated_cashier_name;
                        $confirm_data['passNo'] = $value->passNo;
                        $confirm_data['pax'] = $value->pax;
                        $confirm_data['capacity'] = $value->capacity;
                        $confirm_data['pass_type'] = $value->pass_type;
                        $confirm_data['is_ripley_pass'] = ($ticket_details[$ticket_id]->cod_id == RIPLEY_MUSEUM_ID && $ticket_id == RIPLEY_TICKET_ID) ? 1 : 0;
                        $confirm_data['visitor_group_no'] = $value->visitor_group_no;
                        $confirm_data['order_currency_extra_discount'] = $value->order_currency_extra_discount;
                        $confirm_data['extra_discount'] = $value->extra_discount;
                        $ticket_booking_id = $confirm_data['ticket_booking_id'] = $value->ticket_booking_id;
                        $confirm_data['ticketId'] = $value->ticket_id;
                        $confirm_data['clustering_id'] = $value->clustering_id;
                        $confirm_data['prepaid_reseller_id'] = $confirm_data['reseller_id'] = $reseller_id;
                        $confirm_data['prepaid_reseller_name'] = $confirm_data['reseller_name'] = $reseller_name;
                        $confirm_data['saledesk_id'] = $saledesk_id;
                        $confirm_data['saledesk_name'] = $saledesk_name;
                        $confirm_data['is_combi_discount'] = $value->is_combi_discount;
                        $confirm_data['discount'] = $value->discount;
                        $confirm_data['combi_discount_gross_amount'] = $value->combi_discount_gross_amount;
                        $confirm_data['price'] = $value->price + $value->combi_discount_gross_amount;
                        if (!empty($action_performed) && ($action_performed == 'OAPI33_OP' || strpos($action_performed, 'OAPI3') !== false)) {
                            $confirm_data['order_currency_price'] = $value->order_currency_price;
                            $confirm_data['order_currency_code'] = $value->order_currency_code;
                            $confirm_data['order_currency_symbol'] = $value->order_currency_symbol;
                            $confirm_data['supplier_currency_code'] = $value->supplier_currency_code;
                            $confirm_data['supplier_currency_symbol'] =  $value->supplier_currency_symbol;
                            $confirm_data['cashier_name'] = $value->cashier_name;
                            $confirm_data['action_from'] = 'V3.2';
                        }
                        $confirm_data['discount_type'] = $discount_data['discount_type'];
                        $confirm_data['new_discount'] = $discount_data['new_discount'];
                        $confirm_data['gross_discount_amount'] = $discount_data['gross_discount_amount'];
                        $confirm_data['net_discount_amount'] = $discount_data['net_discount_amount'];

                        if ($value->service_cost > 0 && $value->service_cost_type == "1") {
                            $confirm_data['service_gross'] = $value->service_cost;
                            $confirm_data['service_cost_type'] = $value->service_cost_type;
                            $confirm_data['pertransaction'] = "0";
                        }

                        $confirm_data['initialPayment'] = $this->order_process_vt_model->getInitialPaymentDetail((array) $value, (array) $hotel_overview);
                        $confirm_data['ticketpriceschedule_id'] = $value->tps_id;
                        $confirm_data['ticketwithdifferentpricing'] = $ticket_details[$value->ticket_id]->ticketwithdifferentpricing;
                        $confirm_data['selected_date'] = $value->selected_date;
                        $selected_date = !empty($value->selected_date) ? $value->selected_date : '';
                        $from_time = !empty($value->from_time) ? $value->from_time : '';
                        $to_time = !empty($value->to_time) ? $value->to_time : '';
                        $slot_type = !empty($value->timeslot) ? $value->timeslot : '';
                        $confirm_data['from_time'] = $value->from_time;
                        $confirm_data['to_time'] = $value->to_time;
                        $confirm_data['slot_type'] = $value->timeslot;
                        $confirm_data['prepaid_type'] = $value->activation_method;
                        if($voucher_updated_by > 0) {
                            $confirm_data['userid'] = $voucher_updated_by;
                            $voucher_updated_by_array = explode(' ', $voucher_updated_by_name);
                            $confirm_data['fname'] = $voucher_updated_by_array[0];
                            $confirm_data['lname'] = $voucher_updated_by_array[1];
                        } else if($value->museum_cashier_id > 0) {
                            $confirm_data['userid'] = $value->museum_cashier_id;
                            $user_name = explode(' ', $value->museum_cashier_name);
                            $confirm_data['fname'] = $user_name[0];
                            $confirm_data['lname'] = $user_name[1];
                        } else {
                            $confirm_data['userid'] = '0';
                            $confirm_data['fname'] = 'Prepaid';
                            $confirm_data['lname'] = 'ticket';
                        }

                        if(strpos($action_performed, 'CPOS_GC') !== false) {
                            $confirm_data['cpos_created_date'] = ($value->created_at != '') ? date('Y-m-d H:i:s', $value->created_at) : date('Y-m-d H:i:s', $value->scanned_at);
                            $confirm_data['creation_date'] = ($value->scanned_at != '') ? $value->scanned_at : $value->created_at;
                            $confirm_data['userid'] = $confirm_data['voucher_updated_by'];
                        } else {
                            $confirm_data['creation_date'] = ($scanned_at != '') ? $scanned_at : $value->created_at;
                        }
                        $confirm_data['is_prioticket'] = $value->is_prioticket;
                        $confirm_data['check_age'] = 0;
                        $confirm_data['cmpny'] = $hotel_info;
                        $confirm_data['timeZone'] = $value->timezone;
                        $confirm_data['booking_status'] = $value->booking_status;
                        $confirm_data['is_prepaid'] = $value->is_prepaid;
                        $confirm_data['is_voucher'] = $value->is_voucher;
                        $confirm_data['is_shop_product'] = $value->product_type;
                        $confirm_data['is_pre_ordered'] = 1;

                        $confirm_data['order_status'] = $value->order_status;
                        $confirm_data['without_elo_reference_no'] = $value->without_elo_reference_no;
                        $confirm_data['ticketDetail'] = $ticket_details[$value->ticket_id];
                        $confirm_data['prepaid_ticket_id'] = $value->prepaid_ticket_id;
                        if (isset($value->is_addon_ticket) && $value->is_addon_ticket != '') {
                            $confirm_data['is_addon_ticket'] = $value->is_addon_ticket;
                        } else {
                            $confirm_data['is_addon_ticket'] = 0;
                        }

                        if(!empty($action_performed) ) {
                            $confirm_data['action_performed'] = '0,'.$action_performed;
                        } else {
                            $confirm_data['action_performed'] = '0';
                        }
                        $confirm_data['updated_at'] = gmdate('Y-m-d H:i:s');

                        $confirm_data['supplier_gross_price'] = $value->supplier_price;
                        $confirm_data['supplier_discount'] = $value->supplier_discount;
                        $confirm_data['supplier_ticket_amt'] = $value->supplier_original_price;
                        $confirm_data['supplier_tax_value'] = $value->supplier_tax;
                        $confirm_data['supplier_net_price'] = $value->supplier_net_price;
                        $confirm_data['commission_json'] = $value->commission_json;

                        $confirm_data['booking_status'] = $value->booking_status;
                        if ($value->booking_status == '1') {
                                $booking_status = 1;
                        }
                        $confirm_data['tp_payment_method'] = $value->tp_payment_method;
                        $confirm_data['order_confirm_date'] = ($order_confirm_date != '') ? $order_confirm_date : $value->order_confirm_date;
                        $confirm_data['payment_date'] = $value->payment_date;
                        $payment_date = $value->payment_date;
                        $confirm_data['shared_capacity_id'] = !empty($value->shared_capacity_id) ? $value->shared_capacity_id : 0;
                        $confirm_data['distributor_reseller_id'] = $hotel_info->reseller_id;
                        $confirm_data['distributor_reseller_name'] = $hotel_info->reseller_name;
                        $confirm_data['pos_point_id'] = $pos_point_id = $value->pos_point_id;
                        $confirm_data['currency_rate'] = $value->currency_rate;
                        $confirm_data['is_data_moved'] = $value->is_data_moved;
                        $confirm_data['financial_id'] = $value->financial_id;
                        $pos_point_name = $value->pos_point_name;
                        $channel_id = $value->channel_id;
                        $channel_name = $value->channel_name;
                        $confirm_data['channel_type'] = $value->channel_type;
                        $confirm_data['market_merchant_id'] = $value->market_merchant_id;
                        $confirm_data['cashier_id'] = $cashier_id = $value->cashier_id;
                        $confirm_data['ticket_status'] = $value->activated;
                        $cashier_name = $value->cashier_name;
                        if ($confirm_data['is_addon_ticket'] != "2") {
                            $cluster_tickets_data = $this->order_process_model->cluster_tickets_detail_data(array($value->tps_id));
                        }
                        $this->CreateLog('update_visitor_tickets_direct1.php', 'pt records', array('hto_ids' => $multiple_hto_ids, 'action_performed' => $action_performed));
                        if($multiple_hto_ids != '' ||  strpos($action_performed, 'OAPI3') !== false ||  strpos($action_performed, 'ADMND_CNF') !== false ||  strpos($action_performed, 'CPOS_GC') !== false) { //mpos contiki case
                            $confirm_data['price'] = $value->price;
                            $confirm_data['cashier_id'] = $value->cashier_id;
                            $confirm_data['cashier_name'] = $value->cashier_name;
                            if ($confirm_data['is_addon_ticket'] != "2") {
                                $confirm_data['merchant_admin_id'] = $ticket_details[$ticket_id]->merchant_admin_id;
                                $confirm_data['merchant_admin_name'] = $ticket_details[$ticket_id]->merchant_admin_name;
                                $confirm_data['ctd_currency'] = $this->admin_currency_code_col;
                            } else if (!empty($cluster_tickets_data)) {
                                $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$value->tps_id]['merchant_admin_name'] ?? '';
                                $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$value->tps_id]['merchant_admin_id'] ?? '';
                                $confirm_data['ctd_currency'] = $cluster_tickets_data[$value->tps_id]['currency'] ?? '';                                
                                $confirm_data['main_ticket_id'] = $cluster_tickets_data[$value->tps_id]['main_ticket_id'] ?? '';
                                $confirm_data['main_tps_id'] = $cluster_tickets_data[$value->tps_id]['main_ticket_price_schedule_id'] ?? '';
                            }
                            if($value->is_addon_ticket == '0') {
                                $main_pt_id[$value->visitor_group_no.'_'.$value->ticket_id] = $value->prepaid_ticket_id;
                            } else {
                                $transaction_id_for_sub_ticket = $main_pt_id[$value->visitor_group_no.'_'.$value->related_product_id];
                            }
                        }
                        if($value->activation_method == "9") {
                            $confirm_data['split_payment'][] = array(
                                "card_amount" => $value->split_card_amount,
                                "cash_amount" => $value->split_cash_amount,
                                "coupon_amount" => $value->split_voucher_amount,
                                "direct_amount" => $value->split_direct_payment_amount,
                             );
                        }
                        $logs['transaction_id_for_sub_ticket_'.$key.'_'.date('H:i:s')] = $transaction_id_for_sub_ticket;
                        $this->CreateLog('update_visitor_tickets_direct.php', 'prepared data for VT', array(json_encode($confirm_data) ));
                        $logs['confirm_data_'.$key.'_'.date('H:i:s')]=$confirm_data;
                        
                        $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, 1, $hto_update_for_card);
                        $this->CreateLog('update_visitor_tickets_direct.php', 'visitor_tickets_data', array(json_encode($visitor_tickets_data) ));                        
                        $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);                        
                        $visitor_ids_array[] = $visitor_tickets_data['id'];

                        $museum_net_fee = 0.00;
                        $hgs_net_fee = 0.00;
                        $distributor_net_fee = 0.00;
                        $museum_gross_fee = 0.00;
                        $hgs_gross_fee = 0.00;
                        $distributor_gross_fee = 0.00;
                        $reseller_used_limit = 0.00;
                        $distributor_used_limit = 0.00;
                        $partner_used_limit = 0.00;                        
                        if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {
                            foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $visitor_row_batch) {
                                if($visitor_row_batch['row_type'] == '2') {
                                    $museum_net_fee = $visitor_row_batch['partner_net_price'];
                                    $museum_gross_fee = $visitor_row_batch['partner_gross_price'];
                                    $reseller_used_limit = $visitor_row_batch['partner_gross_price'];
                                    $distributor_used_limit = $visitor_row_batch['partner_gross_price'];
                                } else if($visitor_row_batch['row_type'] == '3') {
                                    $distributor_net_fee = $visitor_row_batch['partner_net_price'];
                                    $distributor_gross_fee = $visitor_row_batch['partner_gross_price'];
                                } else if($visitor_row_batch['row_type'] == '4') {
                                    $hgs_net_fee = $visitor_row_batch['partner_net_price'];
                                    $hgs_gross_fee = $visitor_row_batch['partner_gross_price'];
                                    $distributor_used_limit += $visitor_row_batch['partner_gross_price'];
                                } else if($visitor_row_batch['row_type'] == '1') {
                                    $partner_used_limit = $visitor_row_batch['partner_gross_price'];
                                } else if($visitor_row_batch['row_type'] == '17') { 
                                    $reseller_used_limit += $visitor_row_batch['partner_gross_price'];
                                    $distributor_used_limit += $visitor_row_batch['partner_gross_price'];
                                }  else if($visitor_row_batch['row_type'] == '15') { 
                                    $partner_used_limit -= $visitor_row_batch['partner_gross_price'];
                                }
                            }
                        }                        
                        $update_credit_limit                        = array();
                        $update_credit_limit['museum_id']           = $value->museum_id;
                        $update_credit_limit['reseller_id']         = $value->reseller_id;
                        $update_credit_limit['hotel_id']            = $value->hotel_id;
                        $update_credit_limit['partner_id']          = $value->distributor_partner_id;
                        $update_credit_limit['cashier_name']        = $value->distributor_partner_name;
                        $update_credit_limit['hotel_name']          = $value->hotel_name;
                        $update_credit_limit['visitor_group_no']    = $value->visitor_group_no;
                        $update_credit_limit['merchant_admin_id']   = $value->merchant_admin_id;                        
                        if (array_key_exists($value->museum_id, $update_credit_limit_data)) {
                            $update_credit_limit_data[$value->museum_id]['used_limit']             += $reseller_used_limit;
                            $update_credit_limit_data[$value->museum_id]['distributor_used_limit'] += $distributor_used_limit;
                            $update_credit_limit_data[$value->museum_id]['partner_used_limit']     += $partner_used_limit;
                        } else {
                            $update_credit_limit['used_limit']              = $reseller_used_limit;
                            $update_credit_limit['distributor_used_limit']  = $distributor_used_limit;
                            $update_credit_limit['partner_used_limit']      = $partner_used_limit;
                            $update_credit_limit_data[$value->museum_id] = $update_credit_limit;
                        }                        
                        $prepaid_tickets_data['museum_net_fee'] = $museum_net_fee;
                        $prepaid_tickets_data['distributor_net_fee'] = $distributor_net_fee;
                        $prepaid_tickets_data['hgs_net_fee'] = $hgs_net_fee;
                        $prepaid_tickets_data['museum_gross_fee'] = $museum_gross_fee;
                        $prepaid_tickets_data['distributor_gross_fee'] = $distributor_gross_fee;
                        $prepaid_tickets_data['hgs_gross_fee'] = $hgs_gross_fee;
                        $is_discount_code = 0;
                        /* handle promocodes for version 3.3 make payment after order api. */                        
                        if ($action_performed == 'OAPI33_OP' || strpos($action_performed, 'OAPI3') !== false) {                            
                            if (!empty($value->extra_booking_information)) {
                                $prepaid_extra_booking_information = json_decode(stripslashes(stripslashes($value->extra_booking_information)), true);                    
                            } else {
                                $prepaid_extra_booking_information = array();
                            } 
                            if($value->is_discount_code  == '1') {
                                $is_discount_code = $value->is_discount_code;
                                $discount_codes_details = $prepaid_extra_booking_information["discount_codes_details"] ? $prepaid_extra_booking_information["discount_codes_details"] : array(); 
                                if (empty($discount_codes_details)) {
                                    $discount_codes_details[$value->discount_code_value] = array(
                                        'tax_id' => 0,
                                        'tax_value' => 0.00,
                                        'promocode' => $value->discount_code_value,
                                        'discount_amount' => $value->discount_code_amount,
                                    );
                                }
                            }
                        }                        
                        if($action_performed != 'ADMND_CNF' && $action_performed != 'ADMN_GM' && $action_performed != 'ADMND_GM') {                            
                            $insert_in_pt = array("table" => 'prepaid_tickets', "columns" => $prepaid_tickets_data, "where" => ' prepaid_ticket_id = "'.$value->prepaid_ticket_id.'" and deleted = "0" and booking_status = "1"');                            
                            $this->set_insert_queries($insert_in_pt);       
                            $logs['vt_direct->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                            unset($MPOS_LOGS['get_insert_queries']);                     
                        }                         
                    }                    
                    if (!empty($update_credit_limit_data)) {
                        $this->order_process_model->update_credit_limit($update_credit_limit_data);
                    }                    
                        $paymentMethod = isset($hotel_data['paymentMethod']) ? $hotel_data['paymentMethod'] : '';
                        $pspReference = isset($hotel_data['pspReference']) ? $hotel_data['pspReference'] : '';

                        if ($paymentMethod == '' && $pspReference == '') {
                            $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                        } else {
                            $payment_method = 'Others'; //   others
                        }
                        
                        if ($service_cost > 0) { 
                            if($hotel_data['activation_method'] == '13'){
                                $used = '0';
                            } else {
                                $used = '1';
                            }
                            // To save service cost row in case of service cost per transaction
                            $today_date = strtotime($created_date) + ($hotel_data['timezone'] * 3600);
                            $tax = $this->common_model->getSingleFieldValueFromTable('tax_value', 'store_taxes', array('id' => $hotel_info->service_cost_tax));
                            $service_visitors_data = array();
                            $net_service_cost = ($service_cost * 100) / ($tax + 100);
                            $transaction_id = $visitor_ids_array[count($visitor_ids_array) - 1];
                            $visitor_ticket_id = substr($transaction_id, 0, -2) . '12';
                            $insert_service_data['id'] = $visitor_ticket_id;
                            $insert_service_data['is_prioticket'] = $is_prioticket;
                            $insert_service_data['created_date'] = gmdate('Y-m-d H:i:s', $created_date);
                            $insert_service_data['selected_date'] = $selected_date;
                            $insert_service_data['from_time'] = $from_time;
                            $insert_service_data['to_time'] = $to_time;
                            $insert_service_data['slot_type'] = $slot_type;
                            $insert_service_data['transaction_id'] = $transaction_id;
                            $insert_service_data['visitor_group_no'] = $visitor_group_no;
                            $insert_service_data['vt_group_no'] = $visitor_group_no;
                            $insert_service_data['ticket_booking_id'] = $ticket_booking_id;
                            $insert_service_data['invoice_id'] = '';
                            $insert_service_data['ticketId'] = '0';
                            $insert_service_data['ticketpriceschedule_id'] = '0';
                            $insert_service_data['tickettype_name'] = '';
                            $insert_service_data['ticket_title'] = "Service cost fee for transaction " . implode(",", $visitor_ids_array);
                            $insert_service_data['partner_id'] = $hotel_info->cod_id;
                            $insert_service_data['hotel_id'] = $hotel_id;
                            $insert_service_data['pos_point_id'] = $pos_point_id;
                            $insert_service_data['pos_point_name'] = $pos_point_name;
                            $insert_service_data['partner_name'] = $hotel_name;
                            $insert_service_data['hotel_name'] = $hotel_name;
                            $insert_service_data['visit_date'] = $created_date;
                            $insert_service_data['visit_date_time'] = gmdate('Y-m-d H:i:s', $created_date);
                            $insert_service_data['paid'] = "1";
                            $insert_service_data['payment_method'] = $payment_method;
                            $insert_service_data['captured'] = "1";
                            $insert_service_data['debitor'] = "Guest";
                            $insert_service_data['creditor'] = "Debit";
                            $insert_service_data['partner_gross_price'] = $service_cost;
                            $insert_service_data['order_currency_partner_gross_price'] = $service_cost;
                            $insert_service_data['partner_net_price'] = $net_service_cost;
                            $insert_service_data['order_currency_partner_net_price'] = $net_service_cost;
                            $insert_service_data['tax_id'] = "0";
                            $insert_service_data['tax_value'] = $tax;
                            $insert_service_data['invoice_status'] = "0";
                            $insert_service_data['transaction_type_name'] = "Service cost";
                            $insert_service_data['paymentMethodType'] = $hotel_info->paymentMethodType;
                            $insert_service_data['row_type'] = "12";
                            $insert_service_data['isBillToHotel'] = $hotel_data['isBillToHotel'];
                            $insert_service_data['activation_method'] = $hotel_data['activation_method'];
                            $insert_service_data['service_cost_type'] = "2";
                            $insert_service_data['used'] = $used;
                            $insert_service_data['timezone'] = $hotel_data['timezone'];
                            $insert_service_data['booking_status'] = $booking_status;
                            $insert_service_data['channel_id'] = $channel_id;
                            $insert_service_data['channel_name'] = $channel_name;
                            $insert_service_data['cashier_id'] = $cashier_id;
                            $insert_service_data['cashier_name'] = $cashier_name;
                            $insert_service_data['reseller_id'] = $hotel_info->reseller_id;
                            $insert_service_data['reseller_name'] = $hotel_info->reseller_name;
                            if( !empty($action_performed) ) {
                                $insert_service_data['action_performed'] = '0,'.$action_performed;
                            } else {
                                $insert_service_data['action_performed'] = '0';
                            }

                            if( $action_performed == 3 ) {
                                $insert_service_data['updated_at'] = gmdate('Y-m-d H:i:s');
                            } else {
                                $insert_service_data['updated_at'] = gmdate('Y-m-d H:i:s', $created_date);
                            }

                            $insert_service_data['saledesk_id'] = $hotel_info->saledesk_id;
                            $insert_service_data['saledesk_name'] = $hotel_info->saledesk_name;
                            $insert_service_data['order_confirm_date'] = $confirm_data['order_confirm_date'];
                            $insert_service_data['payment_date'] = $confirm_data['payment_date'];
                            $insert_service_data['distributor_partner_id'] = $confirm_data['distributor_partner_id'];
                            $insert_service_data['distributor_partner_name'] = $confirm_data['distributor_partner_name'];
                            $insert_service_data['partner_category_name'] = $confirm_data['partner_category_name'];
                            $insert_service_data['partner_category_id'] = $confirm_data['partner_category_id'];
                            $insert_service_data['shift_id'] = $confirm_data['shift_id'];
                            $insert_service_data['pos_point_name'] = $confirm_data['pos_point_name'];
                            $insert_service_data['col7'] = gmdate('Y-m', $today_date);
                            $insert_service_data['col8'] = gmdate('Y-m-d', $today_date);
                            $service_visitors_data[] = $insert_service_data;
                            $final_visitor_data = array_merge($final_visitor_data, $service_visitors_data);
                            $logs['final_visitor_data'.date('H:i:s')]=$final_visitor_data;
                        }

                        if (!empty($final_visitor_data)) {
                            $this->insert_batch($vt_table, $final_visitor_data, $db_type);
                            $logs['insert_vt_'.date('H:i:s')]=$this->secondarydb->db->last_query();                            
                        }
                        if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0') ) {
                            $this->insert_batch($vt_table, $final_visitor_data, 4);
                        }

                        $final_visitor_data_to_insert_big_query = $final_visitor_data;

                        // update VT of DB2
                        if( !empty($sns_message_vt) && isset($sns_message_vt) ) {
                            foreach ($sns_message_vt as $query) {
                                $query = trim($query);
                                $this->secondarydb->db->query($query);
                                $this->CreateLog('update_in_db_queries.php', 'db2query=>', array('queries ' => $query));
                                // To update the data in RDS realtime
                                if( ( strstr($query,'visitor_tickets') || strstr($query,'prepaid_tickets') || strstr($query,'hotel_ticket_overview') ) && SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0') ) {
                                    $this->fourthdb->db->query($query);
                                }
                            }
                        }

                        // send VT id update request in PT table of DB1 and DB2
                        $this->CreateLog('vt_id_update.php', 'Db2 update ==>>', array("UPDATE_SECONDARY_DB===>>>" => UPDATE_SECONDARY_DB));
                        if (UPDATE_SECONDARY_DB && !empty($sns_messages)) {
                            $request_array = array();
                            $request_array['db1'] = $sns_messages;
                            $request_array['db2'] = $sns_messages;
                            $request_string = json_encode($request_array);
                            $logs['data_to_queue_UPDATE_DB_ARN_'.date('H:i:s')]=$request_string;
                            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $queueUrl = UPDATE_DB_QUEUE_URL;
                            // This Fn used to send notification with data on AWS panel. 
                            $this->CreateLog('vt_id_update.php', 'Db2 update1 ==>>', array("data" => $request_string));                        
                            $MessageId  = $sqs_object->sendMessage($queueUrl, $aws_message);
                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                            $err = '';
                            $this->CreateLog('vt_id_update.php', 'Db2 update2 ==>>', array("msgid -->" => $MessageId));
                            if ($MessageId) {                            
                                $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                                $this->CreateLog('vt_id_update.php', 'Db2 update3 ==>>', array("err" => $err));
                            }
                        }


                        // Fetch extra options
                        $this->secondarydb->rodb->select('*');
                        $this->secondarydb->rodb->from('prepaid_extra_options');
                        $this->secondarydb->rodb->where('visitor_group_no', $visitor_group_no);
                        $result = $this->secondarydb->rodb->get();
                        if ($result->num_rows() > 0) {
                            $paymentMethod = $hotel_overview->paymentMethod;
                            $pspReference = $hotel_overview->pspReference;

                            if ($paymentMethod == '' && $pspReference == '') {
                                $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                            } else {
                                $payment_method = 'Others'; //   others
                            }
                            $extra_services = $result->result_array();
                            $taxes = array();
                            $eoc = 800;
                            foreach ($extra_services as $service) {
                                if($hotel_data['activation_method'] == '13'){
                                    $used = '0';
                                } else {
                                    $used = '1';
                                }
                                $service['used'] = $used;
                                $service['scanned_at'] = strtotime(gmdate("Y-m-d H:i:s"));
                                // If quantity of service is more than one then we add multiple transactions for financials page
                                if (!in_array($service['tax'], $taxes)) {
                                    $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                                    $taxes[$service['tax']] = $ticket_tax_id;
                                } else {
                                    $ticket_tax_id = $taxes[$service['tax']];
                                }

                                $ticket_tax_value = $service['tax'];
                                $created_extra_options = gmdate('Y-m-d H:i:s');
                                for ($i = 0; $i < $service['quantity']; $i++) {
                                    $service_data_for_visitor = array();
                                    $service_data_for_museum = array();
                                    $p = 0;
                                    $total_amount = $service['price'];
                                    $order_curency_total_amount = $service['order_currency_price'];
                                    $ticket_id = $service['ticket_id'];
                                    $eoc++;
                                    $transaction_id = $visitor_group_no."".$eoc;
                                    $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '1');
                                    $today_date =  strtotime($created_extra_options) + ($hotel_data['timezone'] * 3600);
                                    $ticketTypeDetail = $this->order_process_vt_model->getTicketTypeFromTicketpriceschedule_id($service['ticket_price_schedule_id']);
                                    $tickettype_name = '';
                                    if(!empty($ticketTypeDetail)){
                                        if($ticketTypeDetail->parent_ticket_type == 'Group'){
                                            $tickettype_name = $ticketTypeDetail->ticket_type_label;
                                        } else {
                                            $tickettype_name = $ticketTypeDetail->tickettype_name;
                                        }
                                    }
                                    $extra_option_ticket_booking_id = $ticket_booking_id;
                                    if (!empty($service['ticket_booking_id'])) {
                                        $extra_option_ticket_booking_id = $service['ticket_booking_id'];
                                    }
                                    $service_data_for_visitor['id'] = $visitor_ticket_id;
                                    $service_data_for_visitor['is_prioticket'] = 0;
                                    $service_data_for_visitor['created_date'] = $created_extra_options;
                                    $service_data_for_visitor['transaction_id'] = $transaction_id;
                                    $service_data_for_visitor['visitor_group_no'] = $hotel_overview->visitor_group_no;
                                    $service_data_for_visitor['invoice_id'] = '';
                                    $service_data_for_visitor['reseller_id'] = $ticket_id;
                                    $service_data_for_visitor['reseller_name'] = $ticket_id;
                                    $service_data_for_visitor['ticketId'] = $ticket_id;
                                    $service_data_for_visitor['ticket_title'] = $museum_name . '~_~' . $service['description'];
                                    $service_data_for_visitor['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                                    $service_data_for_visitor['tickettype_name'] = $tickettype_name;
                                    $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                                    $service_data_for_visitor['reseller_id'] = $reseller_id;
                                    $service_data_for_visitor['reseller_name'] = $reseller_name;
                                    $service_data_for_visitor['saledesk_id'] = $saledesk_id;
                                    $service_data_for_visitor['saledesk_name'] = $saledesk_name;
                                    $service_data_for_visitor['distributor_partner_id'] = $value->distributor_partner_id;
                                    $service_data_for_visitor['distributor_partner_name'] = $value->distributor_partner_name;
                                    $service_data_for_visitor['selected_date'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['selected_date'] : '0';
                                    $service_data_for_visitor['from_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['from_time'] : '0';
                                    $service_data_for_visitor['to_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['to_time'] : '0';
                                    $service_data_for_visitor['slot_type'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['timeslot'] : '';
                                    $service_data_for_visitor['partner_id'] = $hotel_info->cod_id;
                                    $service_data_for_visitor['version'] = $version;
                                    $service_data_for_visitor['visit_date'] = strtotime($service['created']);
                                    $service_data_for_visitor['paid'] = "1";
                                    $service_data_for_visitor['payment_method'] = $payment_method;
                                    $service_data_for_visitor['captured'] = "1";
                                    $service_data_for_visitor['debitor'] = "Guest";
                                    $service_data_for_visitor['creditor'] = "Debit";
                                    $service_data_for_visitor['partner_gross_price'] = $total_amount;
                                    $service_data_for_visitor['order_currency_partner_gross_price'] = $order_curency_total_amount;
                                    $service_data_for_visitor['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                                    $service_data_for_visitor['order_currency_partner_net_price'] = ($order_curency_total_amount * 100) / ($ticket_tax_value + 100);
                                    $service_data_for_visitor['tax_id'] = $ticket_tax_id;
                                    $service_data_for_visitor['tax_value'] = $ticket_tax_value;
                                    $service_data_for_visitor['invoice_status'] = "0";
                                    $service_data_for_visitor['transaction_type_name'] = "Extra option sales";
                                    $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                                    $service_data_for_visitor['paymentMethodType'] = $hotel_info->paymentMethodType;
                                    $service_data_for_visitor['row_type'] = "1";
                                    $service_data_for_visitor['isBillToHotel'] = $hotel_overview->isBillToHotel;
                                    $service_data_for_visitor['activation_method'] = $hotel_overview->activation_method;
                                    $service_data_for_visitor['channel_type'] = $hotel_overview->channel_type;
                                    $service_data_for_visitor['vt_group_no'] = $hotel_overview->visitor_group_no;
                                    $service_data_for_visitor['visit_date_time'] = $service['created'];
                                    $service_data_for_visitor['col8'] = gmdate('Y-m-d', $today_date);
                                    $service_data_for_visitor['col7'] = gmdate('Y-m', $today_date);
                                    $service_data_for_visitor['ticketAmt'] = $total_amount;
                                    $service_data_for_visitor['debitor'] = "Guest";
                                    $service_data_for_visitor['creditor'] = "Debit";
                                    $service_data_for_visitor['timezone'] = $hotel_overview->timezone;
                                    $service_data_for_visitor['is_prepaid'] = "1";
                                    $service_data_for_visitor['booking_status'] = "1";
                                    $service_data_for_visitor['used'] = $used;
                                    if ($action_performed == 'OAPI33_OP' || strpos($action_performed, 'OAPI3') !== false) {
                                        $service_data_for_visitor['cashier_id'] = $cashier_id;
                                        $service_data_for_visitor['cashier_name'] = $cashier_name;
                                        $service_data_for_visitor['supplier_gross_price'] = $total_amount;
                                        $service_data_for_visitor['supplier_ticket_amt'] = $total_amount;
                                        $service_data_for_visitor['supplier_tax_value'] = $ticket_tax_value;
                                        $service_data_for_visitor['supplier_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                                        $service_data_for_visitor['activation_method'] = $value->activation_method;
                                        $order_currency_total_amount = $service['order_currency_price'];
                                        $service_data_for_visitor['order_currency_partner_gross_price'] = $order_currency_total_amount;
                                        $service_data_for_visitor['order_currency_partner_net_price'] = ($order_currency_total_amount * 100) / ($ticket_tax_value + 100);
                                        $service_data_for_visitor['channel_id'] = $channel_id;
                                        $service_data_for_visitor['channel_name'] = $channel_name;
                                        $service_data_for_visitor['ticket_booking_id'] = $extra_option_ticket_booking_id;
                                        $service_data_for_visitor['used'] = $value->used;
                                        $service_data_for_visitor['financial_id'] = $value->financial_id;
                                        $service_data_for_visitor['financial_name'] = $value->financial_name;
                                        $service_data_for_visitor['currency_rate'] = isset($confirm_data['currency_rate']) ? $confirm_data['currency_rate'] : '1';
                                        $service_data_for_visitor['order_currency_code'] = isset($confirm_data['order_currency_code']) ? $confirm_data['order_currency_code'] : '';
                                        $service_data_for_visitor['order_currency_symbol'] = isset($confirm_data['order_currency_symbol']) ? $confirm_data['order_currency_symbol'] : '';
                                    }
                                    $service_data_for_visitor['hotel_id'] = $hotel_id;
                                    $service_data_for_visitor['hotel_name'] = $hotel_name;
                                    $service_data_for_visitor['market_merchant_id'] = $value->market_merchant_id;
                                    $service_data_for_visitor['merchant_admin_id'] = $extra_option_merchant_data[$ticket_id];
                                     /* here added some extra data  */
                                     $service_data_for_visitor['order_confirm_date'] = ($order_confirm_date != '') ? $order_confirm_date : date("Y-m-d H:i:s");
                                    if (!empty($museum_name)) {
                                        $service_data_for_visitor['museum_name'] = $museum_name;
                                    }
                                    if (!empty($museum_id)) {
                                        $service_data_for_visitor['museum_id'] = $museum_id;
                                    }
                                    if (!empty($payment_date)) {
                                        $service_data_for_visitor['payment_date'] = $payment_date;
                                    }
                                    if( !empty($action_performed) ) {
                                        $service_data_for_visitor['action_performed'] = '0,'.$action_performed;
                                    } else {
                                        $service_data_for_visitor['action_performed'] = '0';
                                    }
                                    $service_data_for_visitor['updated_at'] = $created_extra_options;
                                    /** add row to big query data */
                                    $final_visitor_data_to_insert_big_query[] = $service_data_for_visitor;
                                    $db->insert($vt_table, $service_data_for_visitor);
                                    if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0') ) {
                                        $this->fourthdb->db->query($db->last_query());
                                    }

                                    $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '2');
                                    $service_data_for_museum['id'] = $visitor_ticket_id;
                                    $service_data_for_museum['is_prioticket'] = 0;
                                    $service_data_for_museum['created_date'] = $created_extra_options;
                                    $service_data_for_museum['transaction_id'] = $transaction_id;
                                    $service_data_for_museum['invoice_id'] = '';
                                    $service_data_for_museum['ticketId'] = $ticket_id;
                                    $service_data_for_museum['selected_date'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['selected_date'] : '0';
                                    $service_data_for_museum['from_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['from_time'] : '0';
                                    $service_data_for_museum['to_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['to_time'] : '0';
                                    $service_data_for_museum['slot_type'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['timeslot'] : '';
                                    $service_data_for_museum['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                                    $service_data_for_museum['tickettype_name'] = $tickettype_name;
                                    $service_data_for_museum['ticket_title'] = $service['name'] . ' (Extra option)';
                                    $service_data_for_museum['partner_id'] = $museum_id;
                                    $service_data_for_museum['partner_name'] = $museum_name;
                                    $service_data_for_museum['distributor_partner_id'] = $value->distributor_partner_id;
                                    $service_data_for_museum['distributor_partner_name'] = $value->distributor_partner_name;
                                    $service_data_for_museum['museum_name'] = $museum_name;
                                    $service_data_for_museum['ticketAmt'] = $total_amount;
                                    $service_data_for_museum['vt_group_no'] = $hotel_overview->visitor_group_no;
                                    $service_data_for_museum['reseller_id'] = $reseller_id;
                                    $service_data_for_museum['reseller_name'] = $reseller_name;
                                    $service_data_for_museum['saledesk_id'] = $saledesk_id;
                                    $service_data_for_museum['saledesk_name'] = $saledesk_name;
                                    $service_data_for_museum['visit_date_time'] = $service['created'];
                                    $service_data_for_museum['col7'] = gmdate('Y-m', $today_date);
                                    $service_data_for_museum['col8'] = gmdate('Y-m-d', $today_date);
                                    $service_data_for_museum['visit_date'] = strtotime($service['created']);
                                    $service_data_for_museum['ticketPrice'] = $total_amount;
                                    $service_data_for_museum['paid'] = "0";
                                    $service_data_for_museum['isBillToHotel'] = $hotel_overview->isBillToHotel;
                                    $service_data_for_museum['debitor'] = $museum_name;
                                    $service_data_for_museum['creditor'] = 'Credit';
                                    $service_data_for_museum['commission_type'] = "0";
                                    $service_data_for_museum['partner_gross_price'] = $total_amount;
                                    $service_data_for_museum['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                                    $service_data_for_museum['isCommissionInPercent'] = "0";
                                    $service_data_for_museum['version'] = $version;
                                    $service_data_for_museum['tax_id'] = $ticket_tax_id;
                                    $service_data_for_museum['tax_value'] = $ticket_tax_value;
                                    $service_data_for_museum['invoice_status'] = "0";
                                    $service_data_for_museum['row_type'] = "2";
                                    $service_data_for_museum['paymentMethodType'] = $hotel_info->paymentMethodType;
                                    $service_data_for_museum['service_name'] = SERVICE_NAME;
                                    $service_data_for_museum['transaction_type_name'] = "Extra service cost";
                                    $service_data_for_museum['is_prepaid'] = "1";
                                    $service_data_for_museum['channel_type'] = $hotel_overview->channel_type;
                                    $service_data_for_museum['used'] = $used;
                                    $service_data_for_museum['booking_status'] = "1";
                                    if ($action_performed == 'OAPI33_OP' || strpos($action_performed, 'OAPI3') !== false) {
                                        $service_data_for_museum['cashier_id'] = $cashier_id;
                                        $service_data_for_museum['currency_rate'] = isset($confirm_data['currency_rate']) ? $confirm_data['currency_rate'] : '1';
                                        $service_data_for_museum['cashier_name'] = $cashier_name;
                                        $service_data_for_museum['activation_method'] = $value->activation_method;
                                        $service_data_for_museum['order_currency_partner_gross_price'] = $order_currency_total_amount;
                                        $service_data_for_museum['order_currency_partner_net_price'] = ($order_currency_total_amount * 100) / ($ticket_tax_value + 100);
                                        $service_data_for_museum['channel_id'] = $channel_id;
                                        $service_data_for_museum['channel_name'] = $channel_name;
                                        $service_data_for_museum['ticket_booking_id'] = $extra_option_ticket_booking_id;
                                        $service_data_for_museum['financial_id'] = $value->financial_id;
                                        $service_data_for_museum['financial_name'] = $value->financial_name;
                                        $service_data_for_museum['used'] = $value->used;
                                        $service_data_for_museum['order_currency_code'] = isset($confirm_data['order_currency_code']) ? $confirm_data['order_currency_code'] : '';
                                        $service_data_for_museum['order_currency_symbol'] = isset($confirm_data['order_currency_symbol']) ? $confirm_data['order_currency_symbol'] : '';
                                        $service_data_for_museum['supplier_gross_price'] = $total_amount;
                                        $service_data_for_museum['supplier_ticket_amt'] = $total_amount;
                                        $service_data_for_museum['supplier_tax_value'] = $ticket_tax_value;
                                        $service_data_for_museum['supplier_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                                    }
                                    $service_data_for_museum['timezone'] = $hotel_overview->timezone;
                                    $service_data_for_museum['hotel_id'] = $hotel_id;
                                    $service_data_for_museum['hotel_name'] = $hotel_name;
                                    $service_data_for_museum['market_merchant_id'] = $value->market_merchant_id;
                                    $service_data_for_museum['merchant_admin_id'] = $extra_option_merchant_data[$ticket_id];
                                    if( !empty($action_performed) ) {
                                        $service_data_for_museum['action_performed'] = '0,'.$action_performed;
                                    } else {
                                        $service_data_for_museum['action_performed'] = '0';
                                    }
                                    $service_data_for_museum['updated_at'] = gmdate('Y-m-d H:i:s');
                                    $service_data_for_museum['order_confirm_date'] = ($order_confirm_date != '') ? $order_confirm_date : date("Y-m-d H:i:s");
                                    if (!empty($museum_name)) {
                                        $service_data_for_museum['museum_name'] = $museum_name;
                                    }
                                    if (!empty($museum_id)) {
                                        $service_data_for_museum['museum_id'] = $museum_id;
                                    }
                                    if (!empty($payment_date)) {
                                        $service_data_for_museum['payment_date'] = $payment_date;
                                    }

                                    /** add row to big query data */
                                    $final_visitor_data_to_insert_big_query[] = $service_data_for_museum;
                                    $db->insert($vt_table, $service_data_for_museum);
                                    if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                                        $this->fourthdb->db->query($db->last_query());
                                    }                            
                                }
                            }
                        }
                        /* insert entries for promocodes in visitor_tickets table. */
                        if (isset($is_discount_code) && $is_discount_code == '1' && $booking_status == '1' && !empty($discount_codes_details)) {
                            $discount_visitors_data = array();
                            $dis_count= 900;
                            if (!empty($final_visitor_data[0]['created_date'])) {
                                $created_date = $final_visitor_data[0]['created_date'];
                            } else {
                                $created_date = gmdate('Y-m-d H:i:s');
                            }
                            foreach ($discount_codes_details as $discount_codes_detail) {
                                $dis_count++; 
                                $discount_code = $discount_codes_detail["promocode"];
                                $discount = $discount_codes_detail["discount_amount"];
                                $ticket_tax_id = isset($discount_codes_detail['tax_id']) ? $discount_codes_detail['tax_id'] : 0;
                                $ticket_tax_value = isset($discount_codes_detail['tax_value']) ? $discount_codes_detail['tax_value'] : 0.00;

                                $transaction_id = $visitor_group_no . $dis_count;
                                $visitor_ticket_id = $transaction_id . '01';
                                $insert_discount_code_data['id'] = $visitor_ticket_id;
                                $insert_discount_code_data['created_date'] = $created_date;
                                $insert_discount_code_data['transaction_id'] = $transaction_id;
                                $insert_discount_code_data['visitor_group_no'] = $visitor_group_no;
                                $insert_discount_code_data['vt_group_no'] = $visitor_group_no;
                                $insert_discount_code_data['invoice_id'] = '';
                                $insert_discount_code_data['ticketId'] = 0;
                                if ($hotel_id == ARENA_WEBSITE_DISTRIBUTOR) {
                                    $insert_discount_code_data['museum_id'] = ARENA_SUPPLIER_ID;
                                    $insert_discount_code_data['museum_name'] = ARENA_SUPPLIER_NAME;
                                } else {
                                    $insert_discount_code_data['museum_id'] = "";
                                    $insert_discount_code_data['museum_name'] = "";
                                }
                                $insert_discount_code_data['ticket_title'] = 'Discount code - ' . $discount_code;
                                $insert_discount_code_data['extra_text_field_answer'] = isset($discount_codes_detail['promotion_title']) ? $discount_codes_detail['promotion_title'] : "";
                                $insert_discount_code_data['selected_date'] = "";
                                $insert_discount_code_data['slot_type'] = "";
                                $insert_discount_code_data['from_time'] = "";
                                $insert_discount_code_data['to_time'] = "";
                                $insert_discount_code_data['passNo'] = "";
                                $insert_discount_code_data['ticketType'] = 0;
                                $insert_discount_code_data['partner_name'] = $hotel_name;
                                $insert_discount_code_data['partner_id'] = $hotel_id;
                                $insert_discount_code_data['hotel_name'] = $hotel_name;
                                $insert_discount_code_data['hotel_id'] = $hotel_id;
                                $insert_discount_code_data['channel_id'] = $channel_id;
                                $insert_discount_code_data['channel_name'] = $channel_name;
                                $insert_discount_code_data['channel_type'] = $hotel_data['channel_type'];
                                $insert_discount_code_data['pass_type'] = '';
                                $insert_discount_code_data['visit_date'] = '';
                                $insert_discount_code_data['visit_date_time'] = '';
                                $insert_discount_code_data['order_confirm_date'] = $value->order_confirm_date;
                                $insert_discount_code_data['version'] = $value->version;
                                $insert_discount_code_data['payment_date'] = $value->payment_date;
                                $insert_discount_code_data['timezone'] = $hotel_data['timezone'];
                                $insert_discount_code_data['paid'] = "1";
                                $insert_discount_code_data['payment_method'] = $discount_code;
                                $insert_discount_code_data['all_ticket_ids'] = '';
                                $insert_discount_code_data['captured'] = "1";
                                $insert_discount_code_data['is_prepaid'] = "1";
                                $insert_discount_code_data['debitor'] = 'Guest';
                                $insert_discount_code_data['creditor'] = 'Credit';
                                $insert_discount_code_data['partner_category_id'] = $value->partner_category_id;
                                $insert_discount_code_data['partner_category_name'] = $value->partner_category_name;
                                $insert_discount_code_data['tax_name'] = 'BTW';

                                $insert_discount_code_data['partner_gross_price'] = $discount; // Discount amount for adult
                                $insert_discount_code_data['total_gross_commission'] = $discount; // Discount amount for adult
                                $insert_discount_code_data['ticketAmt'] = $discount; // Discount amount for adult
                                $discount_net = ($discount * 100) / ($ticket_tax_value + 100);
                                $discount_net = round($discount_net, 2);
                                $insert_discount_code_data['partner_net_price'] = $discount_net;

                                $insert_discount_code_data['tax_id'] = $ticket_tax_id;
                                $insert_discount_code_data['tax_value'] = $ticket_tax_value;
                                $insert_discount_code_data['invoice_status'] = "0";
                                $insert_discount_code_data['transaction_type_name'] = "Discount";
                                $insert_discount_code_data['paymentMethodType'] = 1;
                                $insert_discount_code_data['row_type'] = "1";
                                $insert_discount_code_data['isBillToHotel'] = '0';
                                $insert_discount_code_data['activation_method'] = $value->activation_method;
                                $insert_discount_code_data['service_cost_type'] = "1";
                                $insert_discount_code_data['used'] = '0';
                                $insert_discount_code_data['reseller_id'] = $hotel_info->reseller_id;
                                $insert_discount_code_data['reseller_name'] = $hotel_info->reseller_name;
                                $insert_discount_code_data['saledesk_id'] = $hotel_info->saledesk_id;
                                $insert_discount_code_data['saledesk_name'] = $hotel_info->saledesk_name;
                                if(!empty($action_performed) ) {
                                    $insert_discount_code_data['action_performed'] = '0,'.$action_performed;
                                } else {
                                    $insert_discount_code_data['action_performed'] = '0';
                                }
                                $insert_discount_code_data['updated_at'] = $created_date;
                                $insert_discount_code_data['col7'] = gmdate('Y-m', strtotime($value->order_confirm_date));
                                $insert_discount_code_data['col8'] = gmdate('Y-m-d', strtotime($value->order_confirm_date) + ($hotel_data['timezone'] * 3600));
                                $discount_visitors_data[] = $insert_discount_code_data;

                                $visitor_ticket_id = $transaction_id . '02';
                                $insert_discount_code_data['id'] = $visitor_ticket_id;
                                $insert_discount_code_data['partner_name'] = 0;
                                $insert_discount_code_data['partner_id'] = 0;
                                $insert_discount_code_data['hotel_name'] = $hotel_name;
                                $insert_discount_code_data['hotel_id'] = $hotel_id;
                                $insert_discount_code_data['partner_gross_price'] = 0;
                                $insert_discount_code_data['partner_net_price'] = 0;
                                $insert_discount_code_data['transaction_type_name'] = "Discount Cost";
                                $insert_discount_code_data['debitor'] = '';
                                $insert_discount_code_data['creditor'] = 'Debit';
                                $insert_discount_code_data['row_type'] = "2";
                                $discount_visitors_data[] = $insert_discount_code_data;

                                $visitor_ticket_id = $transaction_id . '03';
                                $insert_discount_code_data['id'] = $visitor_ticket_id;
                                $insert_discount_code_data['tax_id'] = $ticket_tax_id;
                                $insert_discount_code_data['tax_value'] = $ticket_tax_value;
                                $discount_net = ($discount * 100) / ($ticket_tax_value + 100);
                                $discount_net = round($discount_net, 2);

                                $insert_discount_code_data['partner_name'] = $hotel_name;
                                $insert_discount_code_data['partner_id'] = $hotel_id;
                                $insert_discount_code_data['hotel_name'] = $hotel_name;
                                $insert_discount_code_data['hotel_id'] = $hotel_id;
                                $insert_discount_code_data['partner_net_price'] = $discount_net;
                                $insert_discount_code_data['partner_gross_price'] = $discount;
                                $insert_discount_code_data['partner_net_price'] = $discount_net;
                                $insert_discount_code_data['transaction_type_name'] = "Distributor fee";
                                $insert_discount_code_data['debitor'] = $hotel_name;
                                $insert_discount_code_data['creditor'] = 'Debit';
                                $insert_discount_code_data['row_type'] = "3";
                                $discount_visitors_data[] = $insert_discount_code_data;

                                $visitor_ticket_id = $transaction_id . '04';
                                $insert_discount_code_data['id'] = $visitor_ticket_id;
                                $insert_discount_code_data['partner_name'] = $ticket_details[$ticket_id]->hgs_provider_name;
                                $insert_discount_code_data['partner_id'] = $ticket_details[$ticket_id]->hgs_provider_id;
                                $insert_discount_code_data['hotel_name'] = $hotel_name;
                                $insert_discount_code_data['hotel_id'] = $hotel_id;
                                $insert_discount_code_data['partner_gross_price'] = 0;
                                $insert_discount_code_data['partner_net_price'] = 0;
                                $insert_discount_code_data['transaction_type_name'] = "Provider Cost";
                                $insert_discount_code_data['debitor'] = 'Hotel guest service';
                                $insert_discount_code_data['creditor'] = 'Debit';
                                $insert_discount_code_data['row_type'] = "4";
                                $insert_discount_code_data['updated_at'] = $created_date;
                                $discount_visitors_data[] = $insert_discount_code_data;
                            }

                            if (!empty($discount_visitors_data)) {
                                $this->insert_batch($vt_table, $discount_visitors_data, $db_type);
                                $logs['insert_vt_'.date('H:i:s')]=$this->secondarydb->db->last_query();
                            }
                            if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0') ) {
                                $this->insert_batch($vt_table, $discount_visitors_data, 4);
                            }
                            $final_visitor_data_to_insert_big_query = array_merge($final_visitor_data_to_insert_big_query, $discount_visitors_data);
                        }

                        /** code start to publish visitor data to insert in aggregate bigquery table */
                        if (!empty($final_visitor_data_to_insert_big_query)) {
                            $this->CreateLog('big_query_agg_vt_insert_update_visitor_tickets_direct.php', 'final_visitor_data_to_insert_big_query', array("visitor_group_no" => $visitor_group_no, 'final_visitor_data_to_insert_big_query' => json_encode($final_visitor_data_to_insert_big_query)));
                            $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress(json_encode($final_visitor_data_to_insert_big_query)));

                            if (SERVER_ENVIRONMENT == 'Local') {
                                local_queue($final_visitor_data_to_insert_big_query_compressed, 'BIQ_QUERY_AGG_VT_INSERT');
                            } else {
                                $this->CreateLog('big_query_agg_vt_insert_update_visitor_tickets_direct.php', 'final_visitor_data_to_insert_big_query_compressed', array("visitor_group_no" => $visitor_group_no, 'final_visitor_data_to_insert_big_query_compressed' => json_encode($final_visitor_data_to_insert_big_query_compressed)));
                                $this->load->library('Gpubsub');
                                $google_pub = new Gpubsub();
                                $topicName = BIG_QUERY_AGG_VT_INSERT_TOPIC;          

                                $google_pub->publish_message($topicName, $final_visitor_data_to_insert_big_query_compressed);
                            }
                        }
                        /** code end to publish visitor data to insert in aggregate bigquery table */
                    }
            }
        }
        $MPOS_LOGS['update_visitor_tickets_direct']=$logs;
    }
    /* #endregion To insert data of main tables in secondary DB.*/
}