<?php
class Reporting_model extends MY_Model {

    function Reporting_model() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_vt_model');
        $this->base_url = $this->config->config['base_url'];
        $this->root_path = $this->config->config['root_path'];
        $this->imageDir = $this->config->config['imageDir'];
    }

    /**
     * @name   : correct_visitor_tickets_record_model() 
     * @purpose: To insert data in VT table only
     * @Created by: Pankaj Kumar<pankajk.dev@outlook.com> on July, 2018
     */
    function correct_visitor_tickets_record_model( $visitor_group_no, $pass_no = '', $prepaid_ticket_id = '', $hto_id = '', $action_performed = '', $db_type = '1' ) {
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $this->load->library('Sns');
        $sns_object = new Sns();
        
        if ($db_type == '1') {
            $db = $this->secondarydb->db;
        } else {
            $db = $this->db;
        }
        $this->secondarydb->db->select('*');
        $this->secondarydb->db->from('hotel_ticket_overview');
        // to get the data bases on pass number or vt number
        if( !empty( $hto_id ) ) {
            $this->secondarydb->db->where('id', $hto_id);
        } else {
            if( !empty( $pass_no ) ) {
                if(!strstr($pass_no, 'http') && strlen($pass_no) == 6){
                    if(strstr($pass_no, '-')){
                        $this->secondarydb->db->where('passNo', 'http://qb.vg/'.$pass_no);
                    } else{
                        $this->secondarydb->db->where('passNo',  'http://qu.mu/'.$pass_no);
                    }                    
                } else{
                    $this->secondarydb->db->where('passNo', $pass_no);
                }                
            } else {
                $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);
            }
        }

        $this->secondarydb->db->limit(1);
        $overview_result = $this->secondarydb->db->get();
        if ($overview_result->num_rows() > 0) {
            $overview_data = $overview_result->result_array();
            $hotel_overview = $overview_result->result()[0];
            foreach ($overview_data as $value) {
                //$db->insert('hotel_ticket_overview', $value);
                $hotel_data = $value;
            }
            $is_prioticket = $hotel_data['is_prioticket'];
            $order_visitor_group_no = $hotel_data['visitor_group_no'];
            $payment_term_category = isset($hotel_data['payment_term_category']) ? $hotel_data['payment_term_category'] : 0;
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
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from('prepaid_tickets');
            // to get the data bases on pass number or vt number
            if( !empty( $prepaid_ticket_id ) ) {
                $this->secondarydb->db->where('prepaid_ticket_id', $prepaid_ticket_id);
            } else {
                if(!empty($ticket_id)){
                    $this->secondarydb->db->where('ticket_id', $ticket_id);
                }
                if (!empty($pass_no)) {
                    $this->secondarydb->db->where('passNo', $pass_no);
                } else {
                    $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);
                }
            }
            //when script not run mannually ( only for venue app condition need to be run )
            if (!empty($action_performed) && in_array($action_performed, array('1', '2', '3', '4', 'CSS_GCKN', 'SCAN_CSS_WEB'))) {
                $this->secondarydb->db->where('booking_status', '0');
            }
            $prepaid_result = $this->secondarydb->db->get();

            if ($prepaid_result->num_rows() > 0) {
                $prepaid_data = $prepaid_result->result();
                $final_visitor_data = array();
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
                    if( $action_performed == 3 ) {
                        $value->used = '1';
                    }

                    $reseller_id = $value->reseller_id;
                    $reseller_name = $value->reseller_name;
                    $saledesk_id = $value->saledesk_id;
                    $saledesk_name = $value->saledesk_name;
                    $bleep_pass_no = $value->bleep_pass_no;
                    $value->scanned_at = !empty($value->scanned_at) ? $value->scanned_at : strtotime(gmdate("Y-m-d H:i:s"));
                    
                    $confirm_data = array();
                    $confirm_data['pertransaction'] = "0";
                    $discount_data = unserialize($value->extra_discount);
                    $ticket_details[$value->ticket_id] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $value->ticket_id));
                    $hotel_info = $this->common_model->companyName($value->hotel_id);

                    $confirm_data['creation_date'] = $value->created_at;
                    $confirm_data['museum_id'] = $value->museum_id;
                    $confirm_data['museum_name'] = $value->museum_name;
                    $confirm_data['hotel_id'] = $hotel_id;
                    $confirm_data['hotel_name'] = $hotel_name;
                    $confirm_data['distributor_partner_id'] = $value->distributor_partner_id;
                    $confirm_data['distributor_partner_name'] = $value->distributor_partner_name;
                    $confirm_data['passNo'] = $value->passNo;
                    $confirm_data['is_refunded'] = $value->is_refunded;
                    $confirm_data['pass_type'] = $value->pass_type;
                    $confirm_data['is_ripley_pass'] = ($ticket_details[$ticket_id]->cod_id == RIPLEY_MUSEUM_ID && $ticket_id == RIPLEY_TICKET_ID) ? 1 : 0;
                    $confirm_data['visitor_group_no'] = $value->visitor_group_no;
                    $confirm_data['order_currency_extra_discount'] = $value->order_currency_extra_discount;
                    $confirm_data['extra_discount'] = $value->extra_discount;
                    $ticket_booking_id = $confirm_data['ticket_booking_id'] = $value->ticket_booking_id;
                    $confirm_data['ticketId'] = $value->ticket_id;
                    $confirm_data['scanned_pass'] = $bleep_pass_no;
                    $confirm_data['reseller_id'] = $reseller_id;
                    $confirm_data['reseller_name'] = $reseller_name;
                    $confirm_data['saledesk_id'] = $saledesk_id;
                    $confirm_data['saledesk_name'] = $saledesk_name;
                    $confirm_data['is_combi_discount'] = $value->is_combi_discount;
                    $confirm_data['discount'] = $value->discount;
                    $confirm_data['combi_discount_gross_amount'] = $value->combi_discount_gross_amount;
                    $confirm_data['price'] = $value->price + $value->combi_discount_gross_amount;
                    $confirm_data['discount_type'] = $discount_data['discount_type'];
                    $confirm_data['new_discount'] = $discount_data['new_discount'];
                    $confirm_data['gross_discount_amount'] = $discount_data['gross_discount_amount'];
                    $confirm_data['net_discount_amount'] = $discount_data['net_discount_amount'];

                if ($value->service_cost > 0) {
                    if ($value->service_cost_type == "1") {
                        $confirm_data['service_gross'] = $value->service_cost;
                        $confirm_data['service_cost_type'] = $value->service_cost_type;
                        $confirm_data['pertransaction'] = "0";
                        $confirm_data['price'] = $value->price - $value->service_cost + $value->combi_discount_gross_amount;
                    } else if ($value->service_cost_type == "2") {
                        $service_cost = $value->service_cost;
                        $created_date = $value->created_at;
                    }
                } else {
                    $confirm_data['service_gross'] = 0;
                    $confirm_data['service_cost_type'] = 0;
                    $confirm_data['pertransaction'] = "0";
                }
                    
                    $confirm_data['initialPayment'] = $hotel_overview;
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
                    $confirm_data['userid'] = '0';
                    $confirm_data['fname'] = 'Prepaid';
                    $confirm_data['lname'] = 'ticket';
                    $confirm_data['is_prioticket'] = $value->is_prioticket;
                    $confirm_data['check_age'] = 0;
                    $confirm_data['cmpny'] = $hotel_info;
                    $confirm_data['timeZone'] = $value->timezone;
                    $confirm_data['used'] = '1';
                    $confirm_data['booking_status'] = $value->booking_status;
                    $confirm_data['is_prepaid'] = $value->is_prepaid;
                    // $confirm_data['channel_type'] = $hotel_overview->channel_type;
                    $confirm_data['is_voucher'] = $value->is_voucher;
                    $confirm_data['is_shop_product'] = $value->product_type;
                    $confirm_data['is_pre_ordered'] = 1;

                    $confirm_data['order_status'] = $value->order_status;
                    $confirm_data['without_elo_reference_no'] = $value->without_elo_reference_no;
                    $confirm_data['ticketDetail'] = $ticket_details[$value->ticket_id];
                    $confirm_data['prepaid_ticket_id']  =   $value->prepaid_ticket_id;
                    // set vt required columns 
                    $confirm_data['redeem_method']      =   $value->redeem_method;
                    $confirm_data['visit_date']         =   $value->scanned_at;
                    $confirm_data['voucher_updated_by'] =   $value->voucher_updated_by;
                    $confirm_data['pos_point_id']       =   $value->pos_point_id;
                    $confirm_data['pos_point_name']     =   $value->pos_point_name;
                    if('2018-07-12' > date('Y-m-d', strtotime($value->created_date_time)) ){                        
                        $visitor_group_no = round(microtime(true) * 1000); // Return the current Unix timestamp with microseconds
                        $visitor_group_no = $visitor_group_no . '' . rand(10, 99);
                        $confirm_data['prepaid_ticket_id'] = $visitor_group_no.rand(101, 999);
                    }
                    if (isset($value->is_addon_ticket) && $value->is_addon_ticket != '') {
                        $confirm_data['is_addon_ticket'] = $value->is_addon_ticket;
                    } else {
                        $confirm_data['is_addon_ticket'] = 0;
                    }
                    
                    if( !empty($action_performed) ) {
                        $confirm_data['action_performed'] = '0,'.$action_performed;
                    } else {
                        $confirm_data['action_performed'] = '0';
                    }
                    $confirm_data['updated_at'] = gmdate('Y-m-d H:i:s');
                    $confirm_data['creation_date'] = $value->created_at;
                    $confirm_data['userid'] = $value->museum_cashier_id;
                    $user_name = explode(' ', $value->museum_cashier_name);
                    $confirm_data['fname'] = $user_name[0];
                    $confirm_data['lname'] = $user_name[1];
                    
                    $confirm_data['supplier_gross_price'] = $value->supplier_price;
                    $confirm_data['supplier_discount'] = $value->supplier_discount;
                    $confirm_data['supplier_ticket_amt'] = $value->supplier_original_price;
                    $confirm_data['supplier_tax_value'] = $value->supplier_tax;
                    $confirm_data['supplier_net_price'] = $value->supplier_net_price;

                    $confirm_data['booking_status'] = $value->booking_status;
                    if ($value->booking_status == '1') {
                            $booking_status = 1;
                        }
                    $confirm_data['tp_payment_method'] = $value->tp_payment_method;
                    $confirm_data['order_confirm_date'] = $value->order_confirm_date;
                    $confirm_data['payment_date'] = $value->payment_date;
                    $confirm_data['shared_capacity_id'] = !empty($value->shared_capacity_id) ? $value->shared_capacity_id : 0;
                    $pos_point_id = $value->pos_point_id;
                    $pos_point_name = $value->pos_point_name;
                    $channel_id = $value->channel_id;
                    $channel_name = $value->channel_name;
                    $confirm_data['channel_type'] = $value->channel_type;
                    $cashier_id = $value->cashier_id;
                    $cashier_name = $value->cashier_name;
                    $confirm_data['commission_json'] = $value->commission_json;
                    $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, 1);
                    $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                    $visitor_ids_array[] = $visitor_tickets_data['id'];
                }
                
                $paymentMethod = isset($hotel_data['paymentMethod']) ? $hotel_data['paymentMethod'] : '';
                $pspReference = isset($hotel_data['pspReference']) ? $hotel_data['pspReference'] : '';

                if ($paymentMethod == '' && $pspReference == '') {
                    $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                    $invoice_status = '6';
                } else {
                    $payment_method = 'Others'; //   others
                    $invoice_status = '0';
                }
           
                if ($service_cost > 0) { 
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
                    $insert_service_data['ticketwithdifferentpricing'] = $confirm_data['ticketwithdifferentpricing'];
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
                    $insert_service_data['is_refunded'] = $value->is_refunded;
                    $insert_service_data['invoice_status'] = "0";
                    $insert_service_data['transaction_type_name'] = "Service cost";
                    $insert_service_data['paymentMethodType'] = $hotel_info->paymentMethodType;
                    $insert_service_data['row_type'] = "12";
                    $insert_service_data['isBillToHotel'] = $hotel_data['isBillToHotel'];
                    $insert_service_data['activation_method'] = $hotel_data['activation_method'];
                    $insert_service_data['service_cost_type'] = "2";
                    $insert_service_data['used'] = "0";
                    $insert_service_data['scanned_pass']= $bleep_pass_no;
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
                    $insert_service_data['col7'] = gmdate('Y-m', $today_date);
                    $insert_service_data['col8'] = gmdate('Y-m-d', $today_date);
                    $service_visitors_data[] = $insert_service_data;
                    $final_visitor_data = array_merge($final_visitor_data, $service_visitors_data);

                }
                
                if (!empty($final_visitor_data)) {
                    $this->insert_batch('visitor_tickets', $final_visitor_data, $db_type);
                }
                if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                    $this->insert_batch('visitor_tickets', $final_visitor_data, 4);
                }

                // Fetch extra options
                $this->secondarydb->db->select('*');
                $this->secondarydb->db->from('prepaid_extra_options');
                $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);
                $result = $this->secondarydb->db->get();
                if ($result->num_rows() > 0) {
                    $paymentMethod = $hotel_overview->paymentMethod;
                    $pspReference = $hotel_overview->pspReference;

                    if ($paymentMethod == '' && $pspReference == '') {
                        $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
                        $invoice_status = '6';
                    } else {
                        $payment_method = 'Others'; //   others
                        $invoice_status = '0';
                    }
                    $extra_services = $result->result_array();
                    $taxes = array();
                    $eoc = 800;
                    foreach ($extra_services as $service) {
                        //$eoc++;
                        $service['used'] = '1';
                        $service['scanned_at'] = strtotime(gmdate("Y-m-d H:i:s"));
                        //$db->insert('prepaid_extra_options', $service);
                        // If quantity of service is more than one then we add multiple transactions for financials page
                        if (!in_array($service['tax'], $taxes)) {
                            $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                            $taxes[$service['tax']] = $ticket_tax_id;
                        } else {
                            $ticket_tax_id = $taxes[$service['tax']];
                        }

                        $ticket_tax_value = $service['tax'];

                        // Correction of tax in VT
                        // $ticket_tax_value   =   $this->common_model->getSingleFieldValueFromTable('tax_value', 'store_taxes', array('id' => $service['tax']));
                        // $ticket_tax_id      =   $service['tax'];

                        for ($i = 0; $i < $service['quantity']; $i++) {
                            $service_data_for_visitor = array();
                            $service_data_for_museum = array();
                            $p = 0;
                            $total_amount = $service['price'];
                            $order_curency_total_amount = $service['order_currency_price'];
                            $ticket_id = $service['ticket_id'];
                            //$x = $eoc + $i;
                            //$transaction_id = $this->get_auto_generated_id($visitor_group_no, $service['prepaid_extra_options_id'], $i);
                            $eoc++;
                            $transaction_id = $visitor_group_no."".$eoc;
                            $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '1');
                            $today_date =  strtotime($service['created']) + ($hotel_data['timezone'] * 3600);
                            $service_data_for_visitor['id'] = $visitor_ticket_id;
                            $service_data_for_visitor['is_prioticket'] = 0;
                            $service_data_for_visitor['created_date'] = gmdate('Y-m-d H:i:s');
                            $service_data_for_visitor['transaction_id'] = $transaction_id;
                            $service_data_for_visitor['visitor_group_no'] = $hotel_overview->visitor_group_no;
                            $service_data_for_visitor['invoice_id'] = '';
                            $service_data_for_visitor['reseller_id'] = $ticket_id;
                            $service_data_for_visitor['reseller_name'] = $ticket_id;
                            $service_data_for_visitor['ticketId'] = $ticket_id;
                            $service_data_for_visitor['scanned_pass'] = $bleep_pass_no;
                            $service_data_for_visitor['ticket_title'] = $museum_name . '~_~' . $service['description'];
                            $service_data_for_visitor['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                            $service_data_for_visitor['ticketwithdifferentpricing'] = $confirm_data['ticketwithdifferentpricing'];
                            $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                            $service_data_for_visitor['reseller_id'] = $reseller_id;
                            $service_data_for_visitor['reseller_name'] = $reseller_name;
                            $service_data_for_visitor['saledesk_id'] = $saledesk_id;
                            $service_data_for_visitor['saledesk_name'] = $saledesk_name;
                            $service_data_for_visitor['is_refunded'] = $value->is_refunded;
                            $service_data_for_visitor['distributor_partner_id'] = $value->distributor_partner_id;
                            $service_data_for_visitor['distributor_partner_name'] = $value->distributor_partner_name;
                            $service_data_for_visitor['selected_date'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['selected_date'] : '';
                            $service_data_for_visitor['from_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['from_time'] : '';
                            $service_data_for_visitor['to_time'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['to_time'] : '';
                            $service_data_for_visitor['slot_type'] = ($service['selected_date'] != '' && $service['selected_date'] != 0) ? $service['timeslot'] : '';
                            $service_data_for_visitor['partner_id'] = $hotel_info->cod_id;
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
                            $service_data_for_visitor['ticket_booking_id'] = $ticket_booking_id;
                            $service_data_for_visitor['visit_date_time'] = $service['created'];
                            $service_data_for_visitor['order_confirm_date'] = $confirm_data['order_confirm_date'];
                            $service_data_for_visitor['col8'] = gmdate('Y-m-d', $today_date);
                            $service_data_for_visitor['col7'] = gmdate('Y-m', $today_date);
                            $service_data_for_visitor['ticketAmt'] = $total_amount;
                            $service_data_for_visitor['debitor'] = "Guest";
                            $service_data_for_visitor['creditor'] = "Debit";
                            $service_data_for_visitor['timezone'] = $hotel_overview->timezone;
                            $service_data_for_visitor['is_prepaid'] = "1";
                            $service_data_for_visitor['booking_status'] = "1";
                            $service_data_for_visitor['channel_id'] = $channel_id;
                            $service_data_for_visitor['channel_name'] = $channel_name;
                            $service_data_for_visitor['used'] = "1";
                            $service_data_for_visitor['hotel_id'] = $hotel_id;
                            $service_data_for_visitor['hotel_name'] = $hotel_name;
                            $service_data_for_visitor['museum_id'] = $museum_id;
                            $service_data_for_visitor['museum_name'] = $museum_name;
                            $service_data_for_visitor['pos_point_id'] = $pos_point_id;
                            $service_data_for_visitor['pos_point_name'] = $pos_point_name;
                            if( !empty($action_performed) ) {
                                $service_data_for_visitor['action_performed'] = '0,'.$action_performed;
                            } else {
                                $service_data_for_visitor['action_performed'] = $confirm_data['order_confirm_date'];
                            }
                            $service_data_for_visitor['updated_at'] = gmdate('Y-m-d H:i:s');
                            $visitor_id = $db->insert("visitor_tickets", $service_data_for_visitor);
                            if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                                $this->fourthdb->db->query($db->last_query());
                            }

                            $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '2');
                            $service_data_for_museum['id'] = $visitor_ticket_id;
                            $service_data_for_museum['is_prioticket'] = 0;
                            $service_data_for_museum['created_date'] = $service['created'];
                            $service_data_for_museum['transaction_id'] = $transaction_id;
                            $service_data_for_museum['invoice_id'] = '';
                            $service_data_for_museum['ticketId'] = $ticket_id;
                            $service_data_for_museum['ticketpriceschedule_id'] = $service['ticket_price_schedule_id'];
                            $service_data_for_museum['ticketwithdifferentpricing'] = $confirm_data['ticketwithdifferentpricing'];
                            $service_data_for_museum['ticket_title'] = $service['name'] . ' (Extra option)';
                            $service_data_for_museum['partner_id'] = $museum_id;
                            $service_data_for_museum['partner_name'] = $museum_name;
                            $service_data_for_museum['is_refunded'] = $value->is_refunded;
                            $service_data_for_museum['distributor_partner_id'] = $value->distributor_partner_id;
                            $service_data_for_museum['distributor_partner_name'] = $value->distributor_partner_name;
                            $service_data_for_museum['museum_name'] = $museum_name;
                            $service_data_for_museum['ticketAmt'] = $total_amount;
                            $service_data_for_museum['vt_group_no'] = $hotel_overview->visitor_group_no;
                            $service_data_for_museum['ticket_booking_id'] = $ticket_booking_id;
                            $service_data_for_museum['reseller_id'] = $reseller_id;
                            $service_data_for_museum['reseller_name'] = $reseller_name;
                            $service_data_for_museum['saledesk_id'] = $saledesk_id;
                            $service_data_for_museum['saledesk_name'] = $saledesk_name;
                            $service_data_for_museum['visit_date_time'] = $service['created'];
                            $service_data_for_museum['order_confirm_date'] = $confirm_data['order_confirm_date'];
                            $service_data_for_museum['col7'] = gmdate('Y-m', $today_date);
                            $service_data_for_museum['col8'] = gmdate('Y-m-d', $today_date);
                            $service_data_for_museum['visit_date'] = strtotime($service['created']);
                            $service_data_for_museum['ticketPrice'] = $total_amount;
                            $service_data_for_museum['paid'] = "0";
                            $service_data_for_museum['scanned_pass']= $bleep_pass_no;
                            $service_data_for_museum['isBillToHotel'] = $hotel_overview->isBillToHotel;
                            $service_data_for_museum['activation_method'] = $hotel_overview->activation_method;
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
                            $service_data_for_museum['channel_id'] = $channel_id;
                            $service_data_for_museum['channel_name'] = $channel_name;
                            $service_data_for_museum['timezone'] = $hotel_overview->timezone;
                            $service_data_for_museum['hotel_id'] = $hotel_id;
                            $service_data_for_museum['hotel_name'] = $hotel_name;
                            $service_data_for_museum['museum_id'] = $museum_id;
                            $service_data_for_museum['museum_name'] = $museum_name;
                            $service_data_for_museum['pos_point_id'] = $pos_point_id;
                            $service_data_for_museum['pos_point_name'] = $pos_point_name;
                            if( !empty($action_performed) ) {
                                $service_data_for_museum['action_performed'] = '0,'.$action_performed;
                            } else {
                                $service_data_for_museum['action_performed'] = $confirm_data['order_confirm_date'];
                            }
                            $service_data_for_museum['updated_at'] = gmdate('Y-m-d H:i:s');
                            $db->insert("visitor_tickets", $service_data_for_museum);
                            if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                                $this->fourthdb->db->query($db->last_query());
                            }                            
                        }
                    }
                }
            }
        }
    }
}
