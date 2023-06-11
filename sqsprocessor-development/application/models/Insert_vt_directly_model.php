<?php
class Insert_vt_directly_model extends MY_Model {
    
    /* #region  for construct */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_vt_model');
        $this->load->model('order_process_model');
        $this->base_url = $this->config->config['base_url'];
        $this->mpos_api_server = $this->config->config['mpos_api_server'];
        $this->merchant_price_col = 'merchant_price';
        $this->merchant_net_price_col = 'merchant_net_price';
        $this->merchant_tax_id_col = 'merchant_tax_id';
        $this->merchant_currency_code_col = 'merchant_currency_code';
        $this->supplier_tax_id_col = 'supplier_tax_id';
        $this->admin_currency_code_col = 'admin_currency_code';
    }
    /* #endregion */
    
    /* #region to insert data in VT from PT  */
    /**
     * insert_data_in_vt
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $is_secondary_db
     * @param  mixed $node_api_response
     * @param  mixed $insert_in_db
     * @author Komal Garg
     * @return void
     */
    function insert_data_in_vt($visitor_group_no, $is_secondary_db, $node_api_response, $insert_in_db) 
    {
        global $MPOS_LOGS;
        /* initializing params for preparing request and notifying to ARENA_PRXY_LISTENER and ADAM_TOWER*/
        $update_credit_limit_data = array();
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_message = array();
        //Firebase Updations
        $sync_all_tickets = array();
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else {
            $db = $this->db;
        }
        $upsell_order = 0;
        $hotel_id = 0;
        $hotel_name = 0;
        $pos_point_id = 0;
        $pos_point_name = '';
        $channel_id = 0;
        $channel_name = '';
        $inserted_count = 0;
        $museum_details = array();
        $hto_ids = array();
        $arrpass = array();        
        $transaction_id_array = array();
        $cluster_tickets_transaction_id_array = array();        
        $transaction_id_wise_ticketAmt = array();
        $transaction_id_wise_discount = array();
        $transaction_id_wise_clustering_id = array();
        $clustering_id_wise_ticket_id = array();
        $row_key = 0;
        $all_visitor_tickets_ids = '';

        $final_hto_data = array();
        $final_prepaid_data = array();
        $final_visitor_data = array();
        $extra_option_merchant_data = array();
        $uncancel_order = isset($data['uncancel_order']) ? $data['uncancel_order'] : 0;
        $booking_information = '';
        $contact_information = '';
        $booking_details = '';
        $phone_number = '';
        $contact_details = '';
        
        $data['prepaid_tickets_data'] = $this->find('prepaid_tickets', array('select' => '*' ,'where' => 'visitor_group_no = "'.$visitor_group_no.'"'));
        $data['hotel_ticket_overview_data'] = $this->find('hotel_ticket_overview', array('select' => '*' ,'where' => 'visitor_group_no = "'.$visitor_group_no.'"'));
        $data['prepaid_extra_options_data'] = $this->find('prepaid_extra_options', array('select' => '*' ,'where' => 'visitor_group_no = "'.$visitor_group_no.'"'));
        if($insert_in_db == 1) {
            $this->secondarydb->db->delete('visitor_tickets', array('vt_group_no' => $visitor_group_no));
        }
        // array of activation methods
        $allowed_activation_methods     =   array(14,15,16,17,18);
        // If array contain data of hotel_ticket_overview an then insert in hotel_ticket_overview table.
        if (isset($data['hotel_ticket_overview_data']) && !empty($data['hotel_ticket_overview_data'])) {
            if(!empty($is_secondary_db) && $is_secondary_db == '1' && !empty($data['prepaid_tickets_data'])) {
                /* Start save order_flags block. */
                $flag_entities = $this->set_entity($data);
                $flag_details = $this->get_flags_details($flag_entities);
                if (!empty($flag_details)) {
                    $this->set_order_flags($flag_details, $data['prepaid_tickets_data'][0]['visitor_group_no']);
                }
                /* End save order_flags block. */
            }

            foreach ($data['hotel_ticket_overview_data'] as $hotel_data) {
                if (isset($hotel_data['existingvisitorgroup'])) {
                    unset($hotel_data['existingvisitorgroup']);
                }                
                if ($hotel_data['roomNo'] == '') {
                    $hotel_data['roomNo'] = substr($hotel_data['visitor_group_no'], 9, 12);
                }
                if ($row_key == 0) {                    
                    $row_key++;
                }

                if ($hotel_data['gender'] == '') {
                    $hotel_data['gender'] = 'Male';
                }

                $mainharray = array();
                foreach ($hotel_data as $hkey => $hdata) {
                    if (isset($hdata['existingvisitorgroup'])) {
                        unset($hdata['existingvisitorgroup']);
                        continue;
                    }
                    if ($hdata !== '') {
                        $mainharray[$hkey] = $hdata;
                    }
                }
                if(isset($mainharray['booking_information']) && $mainharray['booking_information'] !='') {
                    $booking_information = $mainharray['booking_information'];
                }  
                if(isset($mainharray['contact_information']) && $mainharray['contact_information'] !='') {
                    $contact_information = $mainharray['contact_information'];
                }   
                if(isset($mainharray['booking_details']) && $mainharray['booking_details'] !='') {
                    $booking_details = $mainharray['booking_details'];
                }   
                if(isset($mainharray['phone_number']) && $mainharray['phone_number'] !='') {
                    $phone_number = $mainharray['phone_number'];
                }   
                if(isset($mainharray['contact_details']) && $mainharray['contact_details'] !='') {
                    $contact_details = $mainharray['contact_details'];
                }

                $hto_ids[$hotel_data['passNo']] = $insertedId;
                $arrpass[] = $hotel_data['passNo'];

                if ($hotel_id == 0) {
                    $details['hotel_ticket_overview'] = (object) $hotel_data;
                    $visitor_group_no = $hotel_data['visitor_group_no'];
                    $hotel_id = $hotel_data['hotel_id'];
                    $hotel_name = $hotel_data['hotel_name'];
                    $channel_type = isset($hotel_data['channel_type']) ? $hotel_data['channel_type'] : 0;
                    $phone_number = isset($hotel_data['phone_number']) ? $hotel_data['phone_number'] : '';
                    $activation_method = $hotel_data['activation_method'];
                    $is_prioticket = $hotel_data['is_prioticket'];
                    $isBillToHotel = $hotel_data['isBillToHotel'];
                    $timezone = $hotel_data['timezone'];
                    $createdOn = $hotel_data['createdOn'];
                }
            }
            if (INSERT_BATCH_ON && !empty($final_hto_data)) {
                $this->insert_batch('hotel_ticket_overview', $final_hto_data, $is_secondary_db);
            }
        } else {
            // If hotel_ticket_overview_data array empty then insert data in prepaid_ticket table.
            if (isset($data['prepaid_tickets_data']) && !empty($data['prepaid_tickets_data'])) {
                $hotel_data = $this->secondarydb->rodb->get_where("hotel_ticket_overview", array("visitor_group_no" => $data['prepaid_tickets_data'][0]['visitor_group_no']))->row_array();
                $details['hotel_ticket_overview'] = (object) $hotel_data;
                $visitor_group_no = $hotel_data['visitor_group_no'];
                $hotel_id = $hotel_data['hotel_id'];
                $hotel_name = $hotel_data['hotel_name'];
                $phone_number = isset($hotel_data['phone_number']) ? $hotel_data['phone_number'] : '';
                $channel_type = isset($hotel_data['channel_type']) ? $hotel_data['channel_type'] : 0;
                $activation_method = $hotel_data['activation_method'];
                $is_prioticket = $hotel_data['is_prioticket'];
                $isBillToHotel = $hotel_data['isBillToHotel'];
                $timezone = $hotel_data['timezone'];
                $createdOn = $hotel_data['createdOn'];
                $order_status = isset($hotel_data['order_status']) ? $hotel_data['order_status'] : 0;
            }
        }
        $prepaid_table = 'prepaid_tickets';
        $vt_table = 'visitor_tickets';
        $this->CreateLog('table_name.php', 'Request', array('$prepaid_table' => $prepaid_table, 'vt_table' => $vt_table, 'hotel_id' => $hotel_id));
        $service_cost = 0;
        $mail_key = 0;
        $visitor_ids_array = array();
        $extra_service_options_for_email = array();
        $hotel_info = $this->common_model->companyName($hotel_id); // Hotel Information
        
        $sub_catalog_id = $hotel_info->sub_catalog_id;
        $this->load->helper('common');
        $paymentMethod = isset($hotel_data['paymentMethod']) ? $hotel_data['paymentMethod'] : '';
        $pspReference = isset($hotel_data['pspReference']) ? $hotel_data['pspReference'] : '';

        if ($paymentMethod == '' && $pspReference == '') {
            $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
            $invoice_status = '6';
        } else {
            $payment_method = 'Others'; //   others
            $invoice_status = '0';
        }

        $is_discount_code = 0;
        $discount_codes_details = array();
        
        $cc_rows_already_inserted = 0;
        
        $bookings_listing['hotel_id'] = $hotel_data['hotel_id'];
        $bookings_listing['is_bill_to_hotel'] = $hotel_data['isBillToHotel'];
        $bookings_listing['room_no'] = $hotel_data['roomNo'];
        $bookings_listing['guest_names'] = $hotel_data['guest_names'];
        $bookings_listing['client_reference'] = $hotel_data['client_reference'];
        $bookings_listing['merchant_reference'] = $hotel_data['merchantReference'];

        
        $booking_status = 0;
         /**
     * This condition is for API 3.2, when we use settlement type VENUE this will work. 
        */
//        if(($channel_type == 6 || (!empty($data['prepaid_tickets_data'][0]['is_invoice']) && $data['prepaid_tickets_data'][0]['is_invoice'] == "6")) && $activation_method == 16) {
//            $booking_status = 0;
//        }  
        // set booking status default 1 when it is not group booking order
        if($channel_type != 2 ) {
            $booking_status = 1;
        }
        $selected_date = '';
        $from_time     = '';
        $to_time       = '';
        $slot_type     = '';
        $redeem_prepaid_ticket_ids = array();
        $main_ticket_ids = array();
        $main_ticket_combi_data = array();
        // If array set with hotel_ticket_overview_data, shop_data and prepaid_tickets_data then insert data in prepaid_ticket table.
        if (isset($data['prepaid_tickets_data']) && !empty($data['prepaid_tickets_data'])) {
            if (!empty($details['prepaid_tickets'])) {
                $temp_prepaid_data = array_merge($details['prepaid_tickets'], $data['prepaid_tickets_data']);
            } else {
                $temp_prepaid_data = $data['prepaid_tickets_data'];
            }
            $details['prepaid_tickets'] = (object) $temp_prepaid_data;
            $is_cc_row_updated = 0;
            $cc_rows_values = '';
            $custom_settings = array();
            $cluster_tps_ids = array();
            $cluster_tickets_data = array();
            $rel_target_ids = array();
            $total_quantity = 0;
            $cluster_net_price = array();
            $all_museum_id = array();
            foreach ($data['prepaid_tickets_data'] as $prepaid_tickets_data) {
                if ($prepaid_tickets_data['is_addon_ticket'] == '0') {
                    $cluster_tps_ids[] = $prepaid_tickets_data['tps_id'];
                    $main_ticket_ids[$prepaid_tickets_data['ticket_id']] = $prepaid_tickets_data['ticket_id'];
                    $cluster_net_price[$prepaid_tickets_data['clustering_id']] += 0;
                    
                } else if ($prepaid_tickets_data['is_addon_ticket'] == '2' && $prepaid_tickets_data['clustering_id'] != '' && $prepaid_tickets_data['clustering_id'] != '0') {
                    $cluster_net_price[$prepaid_tickets_data['clustering_id']] += $prepaid_tickets_data['net_price'];
                }
                if ($prepaid_tickets_data['financial_id'] > 0) {
                    $rel_target_ids[] = $prepaid_tickets_data['financial_id'];
                }
                if ($prepaid_tickets_data['is_addon_ticket'] != '2') {
                    $all_tickets[] = $prepaid_tickets_data['ticket_id'];
                    $all_museum_id[] = $prepaid_tickets_data['museum_id'];
                    $total_quantity += $prepaid_tickets_data['quantity'];
                }
                $distributor_partner_id =  !empty($prepaid_tickets_data['distributor_partner_id']) ? $prepaid_tickets_data['distributor_partner_id'] : 0;
                $order_confirm_date     = $prepaid_tickets_data['order_confirm_date'];
                $order_date = strtotime(date('Y-m-d 00:00:00', strtotime($order_confirm_date))) + ($prepaid_tickets_data['timezone'] * 3600);
            }
            if (!empty($main_ticket_ids)) {
                $main_ticket_combi_data = $this->order_process_model->main_ticket_combi_data($main_ticket_ids);
            }
            if (!empty($cluster_tps_ids)) {
                $cluster_tickets_data = $this->order_process_model->cluster_tickets_detail_data($cluster_tps_ids);
            }
            /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
            if ($uncancel_order == 1) {
                $pt_version_data = $this->secondarydb->rodb->select('version,ticket_booking_id')->from($prepaid_table)->where('visitor_group_no', $visitor_group_no)->get()->result_array();
                $vt_versions = $pt_versions = array();
                if (!empty($pt_version_data)) {
                    foreach ($pt_version_data as $pt_version_detail) {
                        $pt_versions[] = $pt_version_detail['version'];
                        $ticket_pt_versions[$pt_version_detail['ticket_booking_id']][] = $pt_version_detail['version'];
                    }
                    $pt_version = max($pt_versions);
                }
                $vt_version_data = $this->secondarydb->rodb->select('version, ticket_booking_id')->from($vt_table)->where('visitor_group_no', $visitor_group_no)->get()->result_array();
                if (!empty($vt_version_data)) {
                    foreach ($vt_version_data as $vt_version_detail) {
                        $vt_versions[] = $vt_version_detail['version'];
                        $ticket_vt_versions[$vt_version_detail['ticket_booking_id']][] = $vt_version_detail['version'];
                    }
                    $vt_version = max($vt_versions);
                }
            }
            // get transaction fee data related to order
            $total_order_amount = 0;
            $reseller_channel_id = $this->common_model->getSingleFieldValueFromTable('channel_id', 'resellers', array('reseller_id' => $hotel_info->reseller_id));
            
            /* get reseller sub catalog id */
            $ticket_reseller_subcatalog_id = $this->getResellerSubcatalogIds( $data['prepaid_tickets_data'] ,$hotel_info->reseller_id);
            
            $amend_ticket_booking_ids = array();
            $i =0;
            foreach ($data['prepaid_tickets_data'] as $prepaid_tickets_data) {
                /* PT, VT version handling for 3.3 distributor amend order api. */
                if ($uncancel_order == 1 && isset($data['is_amend_order_version_update'])) {
                    if (isset($ticket_pt_versions[$prepaid_tickets_data['ticket_booking_id']])) {
                        $pt_version = max($ticket_pt_versions[$prepaid_tickets_data['ticket_booking_id']]);
                    }
                    if (isset($ticket_vt_versions[$prepaid_tickets_data['ticket_booking_id']])) {
                        $vt_version = max($ticket_vt_versions[$prepaid_tickets_data['ticket_booking_id']]);
                    }
                    if ($data['is_amend_order_version_update'] == 1 && !in_array($prepaid_tickets_data['ticket_booking_id'], $amend_ticket_booking_ids)) {
                        $pt_version['version'] = $pt_version['version'] + 1;
                        $vt_version['version'] = $vt_version['version'] + 1;
                    }
                    $amend_ticket_booking_ids[] = $prepaid_tickets_data['ticket_booking_id'];
                }
                $shared_capacity_id = $prepaid_tickets_data['shared_capacity_id'];
                if(!empty($prepaid_tickets_data['extra_booking_information'])) {
                    $prepaid_extra_booking_information = json_decode(stripslashes(stripslashes($prepaid_tickets_data['extra_booking_information'])), true);                    
                } else {
                    $prepaid_extra_booking_information = array();
                }                
                if (!empty($booking_information)) {
                    $prepaid_tickets_data['booking_information']= $booking_information;
                }
                if (!empty($contact_information)) {
                    $prepaid_tickets_data['contact_information']= $contact_information;
                }
                if (!empty($booking_details) && empty($prepaid_tickets_data['booking_details'])) {
                    $prepaid_tickets_data['booking_details']= $booking_details;
                }
                if (!empty($phone_number)) {
                    $prepaid_tickets_data['phone_number']= $phone_number;
                }
                if (!empty($contact_details)) {
                    $prepaid_tickets_data['contact_details']= $contact_details;   
                }             
                
                if(isset($prepaid_tickets_data['activation_method']) && $prepaid_tickets_data['activation_method'] != '') {
                    $activation_method = $prepaid_tickets_data['activation_method'];
                }
                if(strstr($prepaid_tickets_data['action_performed'], '0, PST_INSRT') || strstr($prepaid_tickets_data['action_performed'], '0, API_SYX_PST') ) {
                    $redeem_prepaid_ticket_ids[] = $prepaid_tickets_data['prepaid_ticket_id'];
                }
                //Firebase updations
                if(strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                    $upsell_order = 1;
                    unset($prepaid_tickets_data['main_ticket_id']);
                }
                unset($prepaid_tickets_data['order_type']);
                $pos_point_id   = $prepaid_tickets_data['pos_point_id'];
                $shift_id   = $prepaid_tickets_data['shift_id'];
                $pos_point_name = $prepaid_tickets_data['pos_point_name'];
                $channel_id = $prepaid_tickets_data['channel_id'];
                $channel_name = $prepaid_tickets_data['channel_name'];
                $cashier_id = $prepaid_tickets_data['cashier_id'];
                $cashier_name = $prepaid_tickets_data['cashier_name'];
                $prepaid_tickets_data['payment_date'] = !empty($prepaid_tickets_data['payment_date']) ? $prepaid_tickets_data['payment_date'] : '0000-00-00 00:00';
                if (empty($prepaid_tickets_data['group_type_ticket'])) {
                    $group_type_ticket = '0';
                } else {
                    $group_type_ticket = $prepaid_tickets_data['group_type_ticket'];
                }
                if (empty($prepaid_tickets_data['group_price'])) {
                    $group_price = '0';
                } else {
                    $group_price = $prepaid_tickets_data['group_price'];
                }
                if (empty($prepaid_tickets_data['group_quantity'])) {
                    $group_quantity = '0';
                } else {
                    $group_quantity = $prepaid_tickets_data['group_quantity'];
                }

                if (empty($prepaid_tickets_data['group_linked_with'])) {
                    $group_linked_with = '0';
                } else {
                    $group_linked_with = $prepaid_tickets_data['group_linked_with'];
                }

                if (empty($pos_point_id)) {
                    $pos_point_id = 0;
                }
                if (empty($shift_id)) {
                    $shift_id = 0;
                }
                if (empty($pos_point_name)) {
                    $pos_point_name = '';
                }
                // BOC for custom settings to save in prepaid tickets
                $custom_settings_data = array();
                $is_custom_setting = $prepaid_tickets_data['is_custom_setting'];
                $custom_settings_data['is_custom_setting'] = !empty($is_custom_setting) ? $is_custom_setting : 0;
                if ($custom_settings_data['is_custom_setting'] > 0) {
                    $custom_settings_data['external_product_id'] = $prepaid_tickets_data['external_product_id'];
                    
                } else {
                    $custom_settings_data['external_product_id'] = '';
                }
                $custom_settings_data['account_number'] = $prepaid_tickets_data['account_number'];
                $custom_settings_data['chart_number'] = $prepaid_tickets_data['chart_number'];
                $custom_settings[$prepaid_tickets_data['ticket_id'] . '_' . $prepaid_tickets_data['tps_id']] = $custom_settings_data;
                $custom_ticket_settings[$prepaid_tickets_data['ticket_id']] = $custom_settings_data;
                // EOC for custom settings to save in prepaid tickets
                if (isset($prepaid_tickets_data['net_price\t']) && $prepaid_tickets_data['net_price\t'] != '') {
                    $prepaid_tickets_data['net_price'] = $prepaid_tickets_data['net_price\t'];
                }
                $orignal_pass_no = $prepaid_tickets_data['passNo'];                
                if (isset($prepaid_tickets_data['is_combi_ticket']) && $prepaid_tickets_data['is_combi_ticket'] == "1") {
                    $prepaid_tickets_data['is_combi_ticket'] = "1";
                } else {
                    $prepaid_tickets_data['is_combi_ticket'] = "0";
                }
                if ($prepaid_tickets_data['passNo'] != '') {
                    $check_pass = $prepaid_tickets_data['passNo'];
                    if (((strlen($prepaid_tickets_data['passNo']) == 6 && $prepaid_tickets_data['third_party_type'] < '1') || $prepaid_tickets_data['ticket_id'] == RIPLEY_TICKET_ID || (isset($prepaid_tickets_data['is_iticket_product']) && $prepaid_tickets_data['is_iticket_product'] == '1')) && !strstr($prepaid_tickets_data['passNo'], 'http') && $prepaid_tickets_data['passNo'] != '') {
                        if ($prepaid_tickets_data['ticket_id'] == RIPLEY_TICKET_ID) {
                            if (!strstr($check_pass, 'qb.vg')) {
                                $check_pass = 'qb.vg/' . $check_pass;
                            }
                        } else {
                            if (!strstr($check_pass, '-')) {
                                if (!strstr($check_pass, 'qu.mu')) {
                                    $check_pass = 'qu.mu/' . $check_pass;
                                }
                            } else {
                                if (!strstr($check_pass, 'qb.vg')) {
                                    $check_pass = 'qb.vg/' . $check_pass;
                                }
                            }
                        }
                        $check_pass = "http://" . $check_pass;
                    }
                    if (!(isset($prepaid_tickets_data['hotel_ticket_overview_id']) && $prepaid_tickets_data['hotel_ticket_overview_id'] > 0)) {
                        $prepaid_tickets_data['hotel_ticket_overview_id'] = $hto_ids[$check_pass];
                    }
                } else {
                    if (!(isset($prepaid_tickets_data['hotel_ticket_overview_id']) && $prepaid_tickets_data['hotel_ticket_overview_id'] > 0) && $prepaid_tickets_data['is_prioticket'] == "0") {
                        $prepaid_tickets_data['hotel_ticket_overview_id'] = $hto_ids[$arrpass[0]];
                    }
                }
                if (isset($data['cc_row_data']) && !empty($data['cc_row_data']) && 0) {
                    $cc_row_data = $data['cc_row_data'];
                    // Preapre array to update CC rows value in prepaid Tickets in case of Credit Card.
                    if ($is_cc_row_updated == 0 && $cc_row_data['Amtwithtax'] > 0) {
                        // Get previous cc cost amount if any
                        if ($cc_row_data['is_add_to_prioticket'] == "1") {
                            // Only in case of Add to prioticket option
                            $cc_rows_value = $db->get_where("prepaid_tickets", array("visitor_group_no" => $visitor_group_no, "visitor_tickets_id" => "0"))->row()->cc_rows_value;
                            if ($cc_rows_value != '' && $cc_rows_value != null) {
                                $cc_rows_value_array = unserialize($cc_rows_value);
                                $cc_row_data['Amtwithtax'] = $cc_row_data['Amtwithtax'] + $cc_rows_value_array['Amtwithtax'];
                                $cc_row_data['totalamount'] = $cc_row_data['totalamount'] + $cc_rows_value_array['totalamount'];
                                $cc_row_data['totalFixedAmt'] = $cc_row_data['totalFixedAmt'] + $cc_rows_value_array['totalFixedAmt'];
                                $cc_row_data['totalVariableAmt'] = $cc_row_data['totalVariableAmt'] + $cc_rows_value_array['totalVariableAmt'];
                            }
                        }
                        $cc_rows_value_array = array(
                            'Amtwithtax' => $cc_row_data['Amtwithtax'],
                            'totalamount' => $cc_row_data['totalamount'],
                            'totalFixedAmt' => $cc_row_data['totalFixedAmt'],
                            'totalVariableAmt' => $cc_row_data['totalVariableAmt'],
                            'calculated_on' => $cc_row_data['calculated_on'],
                            'isprepaid' => $cc_row_data['isprepaid']
                        );
                        $cc_rows_values = serialize($cc_rows_value_array);
                        $is_cc_row_updated = 1;
                    }
                }
                $prepaid_tickets_data['cc_rows_value'] = $cc_rows_values;
                //Firebase Updations
                $sync_all_tickets['ticket_id'][] = $prepaid_tickets_data['ticket_id'];
                if(isset($prepaid_tickets_data['last_modified_at'])) {
                    unset($prepaid_tickets_data['last_modified_at']);
                }
                                            
                //guest details
                $logs['channel_type'] = $channel_type;
                $logs['prepaid_extra_booking_information'] = $prepaid_extra_booking_information;

                $prepaid_tickets_data['secondary_guest_name'] = !empty($prepaid_tickets_data['guest_names']) ? $prepaid_tickets_data['guest_names'] : '';
                $prepaid_tickets_data['secondary_guest_email'] = !empty($prepaid_tickets_data['guest_emails']) ? $prepaid_tickets_data['guest_emails'] : '';
                $prepaid_tickets_data['phone_number'] = !empty($prepaid_tickets_data['phone_number']) ? $prepaid_tickets_data['phone_number'] : '';
                $prepaid_tickets_data['passport_number'] = '';
                if(!empty($prepaid_extra_booking_information)) {
                    $per_participant_info = isset($prepaid_extra_booking_information['per_participant_info']) ? $prepaid_extra_booking_information['per_participant_info'] : array();
                    if (!empty($per_participant_info)) {
                        $prepaid_tickets_data['secondary_guest_name'] = !empty($per_participant_info['name']) ? $per_participant_info['name'] : '';
                        $prepaid_tickets_data['secondary_guest_email'] = !empty($per_participant_info['email']) ? $per_participant_info['email'] : '';
                        $prepaid_tickets_data['passport_number'] = !empty($per_participant_info['id']) ? $per_participant_info['id'] : '';
                        $prepaid_tickets_data['phone_number'] = !empty($per_participant_info['phone_no']) ? $per_participant_info['phone_no'] : '';
                    }

                    if (isset($prepaid_extra_booking_information['partner_cost'])) {
                        $prepaid_tickets_data['partner_cost'] = $prepaid_extra_booking_information['partner_cost'];
                    }

                    if (isset($prepaid_extra_booking_information['supplier_cost'])) {
                        $prepaid_tickets_data['supplier_cost'] = $prepaid_extra_booking_information['supplier_cost'];
                    }
                }
                
                if (INSERT_BATCH_ON == 0) {
                    $db->insert($prepaid_table, $prepaid_tickets_data);
                }

                $insertedId = $prepaid_tickets_data['prepaid_ticket_id'];
                $inserted_count++;
                $ticket_id = $prepaid_tickets_data['ticket_id'];
                
                $keyReprice = $prepaid_tickets_data['ticket_id'] . "_" . $prepaid_tickets_data['tps_id'] . "_" . $prepaid_tickets_data['visitor_group_no']."_".$i;
                if($prepaid_tickets_data['is_discount_code']  == '1' || isset( $prepaid_tickets_data['reprice_discount'] )) {
                    $is_discount_code = $prepaid_tickets_data['is_discount_code'];             
                    
                    if( 
                        isset( $prepaid_tickets_data['reprice_discount'] ) && 
                        isset( $prepaid_tickets_data['reprice_discount'][$keyReprice] )
                    ) {
                        $discount_codes_details[$keyReprice] = array(
                            'tax_id' => 0,
                            'tax_value' => 0.00,
                            'promocode' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['discount_code_value'],
                            'discount_amount' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['discount_code_amount'], 
                            'is_reprice' => 1, 
                            'user_type' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['user_type'], 
                            'surcharge_type' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['surcharge_type'],
                            'selected_date' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['selected_date'],
                            'from_time' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['from_time'],
                            'to_time' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['to_time'],
                            'ticket_id' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['ticket_id'],
                            'tps_id' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['tps_id'],
                            'slot_type' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['slot_type'],
                            'max_discount_code' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['max_discount_code'],
                            'clustering_id' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['clustering_id'],
                            'prepaid_ticket_id' => $prepaid_tickets_data['reprice_discount'][$keyReprice]['prepaid_ticket_id'],
                        );
                    } 
                    else {
                        $discount_codes_details = $prepaid_extra_booking_information["discount_codes_details"] ? $prepaid_extra_booking_information["discount_codes_details"] : array(); 
                        if (empty($discount_codes_details)) {
                            $discount_codes_details[$prepaid_tickets_data['discount_code_value']] = array(
                                'tax_id' => 0,
                                'tax_value' => 0.00,
                                'promocode' => $prepaid_tickets_data['discount_code_value'],
                                'discount_amount' => $prepaid_tickets_data['discount_code_amount'],
                            );
                        }
                    }
                }
                $i++;
                if (($prepaid_tickets_data['cc_rows_value'] != '' && $prepaid_tickets_data['cc_rows_value'] != 0) && $cc_rows_already_inserted == 0) {
                    $cc_rows_value = unserialize($prepaid_tickets_data['cc_rows_value']);
                    $Amtwithtax = $cc_rows_value['Amtwithtax'] ? $cc_rows_value['Amtwithtax'] : 0;
                    $totalamount = $cc_rows_value['totalamount'] ? $cc_rows_value['totalamount'] : 0;
                    $totalFixedAmt = $cc_rows_value['totalFixedAmt'] ? $cc_rows_value['totalFixedAmt'] : 0;
                    $totalVariableAmt = $cc_rows_value['totalVariableAmt'] ? $cc_rows_value['totalVariableAmt'] : 0;
                    $calculated_on = $cc_rows_value['calculated_on'];
                    $is_prepaid = $cc_rows_value['isprepaid'];
                    $cc_rows_already_inserted = 1;
                }
                if (!array_key_exists($ticket_id, $museum_details)) {
                    $details['modeventcontent'][$ticket_id] = $ticket_details[$ticket_id] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $ticket_id));
                    $museum_details[$ticket_id] = $ticket_details[$ticket_id]->museum_name;
                }
                if($prepaid_tickets_data['booking_status'] == '1') {
                    $booking_status = 1;
                }
                if ($upsell_order) {
                    $booking_status = $prepaid_tickets_data['booking_status'];
                }
                if (empty($prepaid_tickets_data['selected_date'])) {
                    // for open tickets
                    $prepaid_tickets_data['selected_date'] = date('Y-m-d');
                }
                    
                $selected_date = !empty($prepaid_tickets_data['selected_date']) ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                $from_time     = !empty($prepaid_tickets_data['from_time']) ? $prepaid_tickets_data['from_time'] : '';
                $to_time       = !empty($prepaid_tickets_data['to_time']) ? $prepaid_tickets_data['to_time'] : '';
                $slot_type     = !empty($prepaid_tickets_data['timeslot']) ? $prepaid_tickets_data['timeslot'] : '';
                $confirm_data = array();
                $logs['shift__pos_point__channel'] = array($shift_id, $pos_point_id, $prepaid_tickets_data['channel_type']);
                if($shift_id != '0' && $pos_point_id != '0' && in_array($prepaid_tickets_data['channel_type'], array(10, 11)))  {
                    $cashier_register_id = $this->get_cashier_register_id($shift_id, $pos_point_id, $prepaid_tickets_data['hotel_id'], $prepaid_tickets_data['cashier_id']);
                    $confirm_data['cashier_register_id'] = $prepaid_tickets_data['cashier_register_id'] = $cashier_register_id;
                    $logs['cashier_register_id__query'] = array($cashier_register_id, $this->db->last_query());
                }
                $confirm_data['pertransaction'] = "0";
                $confirm_data['scanned_pass'] = isset($prepaid_tickets_data['scanned_pass']) ? $prepaid_tickets_data['scanned_pass'] : '';
                $discount_data = unserialize($prepaid_tickets_data['extra_discount']);
                $confirm_data['creation_date'] = $prepaid_tickets_data['created_at'];
                $confirm_data['visit_date'] = (isset($prepaid_tickets_data['scanned_at']) && $prepaid_tickets_data['scanned_at'] != '') ? $prepaid_tickets_data['scanned_at'] : strtotime(gmdate('Y-m-d H:i:s'));
                $confirm_data['distributor_partner_id'] = !empty($prepaid_tickets_data['distributor_partner_id']) ? $prepaid_tickets_data['distributor_partner_id'] : 0;
                $confirm_data['distributor_partner_name'] = $prepaid_tickets_data['distributor_partner_name'];
                $confirm_data['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                $confirm_data['hotel_id'] = $prepaid_tickets_data['hotel_id'];
                $confirm_data['channel_type'] = $prepaid_tickets_data['channel_type'];
                $confirm_data['partner_category_id'] = !empty($prepaid_tickets_data['partner_category_id']) ? $prepaid_tickets_data['partner_category_id'] : 0;
                $confirm_data['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                $confirm_data['hotel_name'] = $prepaid_tickets_data['hotel_name'];
                $confirm_data['resuid'] = $prepaid_tickets_data['cashier_id'];
                $confirm_data['resfname'] = $prepaid_tickets_data['cashier_name'];
                $confirm_data['channel_id'] = $channel_id;
                $confirm_data['channel_name;'] = $channel_name;
                $confirm_data['shift_id'] = $shift_id;
                $confirm_data['pos_point_id'] = $pos_point_id;
                $confirm_data['pos_point_name'] = $pos_point_name;
                $confirm_data['museum_name'] = !empty($prepaid_tickets_data['museum_name']) ? $prepaid_tickets_data['museum_name'] : $museum_details[$ticket_id];
                if ($is_prioticket == "0") {
                    $confirm_data['passNo'] = $prepaid_tickets_data['passNo'];
                } else if ($is_prioticket == "1") {
                    $confirm_data['passNo'] = strlen($prepaid_tickets_data['passNo']) > 6 && !strstr($prepaid_tickets_data['passNo'], 'http') ? $prepaid_tickets_data['passNo'] : '';
                } else {
                    $confirm_data['passNo'] = '';
                }
                $confirm_data['pass_type'] = $prepaid_tickets_data['pass_type'];
                $confirm_data['prepaid_ticket_id'] = $insertedId;
                $confirm_data['is_ripley_pass'] = ($ticket_details[$ticket_id]->cod_id == RIPLEY_MUSEUM_ID && $ticket_id == RIPLEY_TICKET_ID) ? 1 : 0;
                $confirm_data['visitor_group_no'] = $visitor_group_no;
                $ticket_booking_id = $confirm_data['ticket_booking_id'] = $prepaid_tickets_data['ticket_booking_id'];
                $confirm_data['ticketId'] = $ticket_id;
                $confirm_data['is_combi_discount'] = $prepaid_tickets_data['is_combi_discount'];
                $confirm_data['combi_discount_gross_amount'] = $prepaid_tickets_data['combi_discount_gross_amount'];
                $confirm_data['order_currency_combi_discount_gross_amount'] = $prepaid_tickets_data['order_currency_combi_discount_gross_amount'];
                $confirm_data['price'] = $prepaid_tickets_data['price'];
                $confirm_data['order_currency_price'] = $prepaid_tickets_data['order_currency_price'];
                $confirm_data['supplier_currency_code'] = $prepaid_tickets_data['supplier_currency_code'];
                $confirm_data['supplier_currency_symbol'] = $prepaid_tickets_data['supplier_currency_symbol'];
                $confirm_data['order_currency_code'] = $prepaid_tickets_data['order_currency_code'];
                $confirm_data['order_currency_symbol'] = $prepaid_tickets_data['order_currency_symbol'];
                $confirm_data['currency_rate'] = $prepaid_tickets_data['currency_rate'];
                $confirm_data['discount_type'] = $discount_data['discount_type'];
                $confirm_data['new_discount'] = $discount_data['new_discount'];
                $confirm_data['gross_discount_amount'] = $discount_data['gross_discount_amount'];
                $confirm_data['net_discount_amount'] = $discount_data['net_discount_amount'];
                if(isset($discount_data['discount_label']) && $discount_data['discount_label'] != '') {
                    $confirm_data['discount_label'] = $discount_data['discount_label'];
                } else {
                    $confirm_data['discount_label'] = '';
                }                
                $confirm_data['group_type_ticket'] = $group_type_ticket;
                $confirm_data['group_price'] = $group_price;
                $confirm_data['group_quantity'] = $group_quantity;
                $confirm_data['group_linked_with'] = $group_linked_with;
                $confirm_data['pax'] = $prepaid_tickets_data['pax'];
                $confirm_data['capacity'] = $prepaid_tickets_data['capacity'];
                $confirm_data['clustering_id'] = $prepaid_tickets_data['clustering_id'];
                $confirm_data['version'] = $prepaid_tickets_data['version'];
                if ($prepaid_tickets_data['service_cost'] > 0 && !isset( $prepaid_tickets_data['reprice_discount'] )) {
                    if ($prepaid_tickets_data['service_cost_type'] == "1") {
                        $confirm_data['service_gross'] = $prepaid_tickets_data['service_cost'];
                        $confirm_data['service_cost_type'] = $prepaid_tickets_data['service_cost_type'];
                        $confirm_data['pertransaction'] = "0";
                        $confirm_data['price'] = $prepaid_tickets_data['price'] - $prepaid_tickets_data['service_cost'] + $prepaid_tickets_data['combi_discount_gross_amount'];
                    } else if ($prepaid_tickets_data['service_cost_type'] == "2") {
                        $service_cost = $prepaid_tickets_data['service_cost'];
                        $created_date = $prepaid_tickets_data['created_at'];
                    }else if ($prepaid_tickets_data['service_cost_type'] == "0") {
                        $confirm_data['service_gross'] = $prepaid_tickets_data['service_cost'];
                        $confirm_data['service_cost_type'] = $prepaid_tickets_data['service_cost_type'];
                    }
                } else {
                    $confirm_data['service_gross'] = 0;
                    $confirm_data['service_cost_type'] = 0;
                    $confirm_data['pertransaction'] = "0";
                }
                
                
                
                /*
                 * Make sure, the passNo is passed with full URL
                 * Ripley ticket condition is added becuase it contains http in its url when saved in hotel_ticket_overview and pass
                 * is scanned through venue app
                 */
                if ((strlen($prepaid_tickets_data['passNo']) == 6 || $ticket_id == RIPLEY_TICKET_ID || (isset($prepaid_tickets_data['is_iticket_product']) && $prepaid_tickets_data['is_iticket_product'] == '1')) && !strstr($prepaid_tickets_data['passNo'], 'http') && $prepaid_tickets_data['passNo'] != '') {
                    if ($ticket_id == RIPLEY_TICKET_ID) {
                        if (!strstr($prepaid_tickets_data['passNo'], 'qb.vg')) {
                            $prepaid_tickets_data['passNo'] = 'qb.vg/' . $prepaid_tickets_data['passNo'];
                        }
                    } else {
                        if (!strstr($prepaid_tickets_data['passNo'], '-')) {
                            if (!strstr($prepaid_tickets_data['passNo'], 'qu.mu')) {
                                $prepaid_tickets_data['passNo'] = 'qu.mu/' . $prepaid_tickets_data['passNo'];
                            }
                        } else {
                            if (!strstr($prepaid_tickets_data['passNo'], 'qb.vg')) {
                                $prepaid_tickets_data['passNo'] = 'qb.vg/' . $prepaid_tickets_data['passNo'];
                            }
                        }
                    }
                    $prepaid_tickets_data['passNo'] = "http://" . $prepaid_tickets_data['passNo'];
                }
                $confirm_data['initialPayment'] = $this->order_process_vt_model->getInitialPaymentDetail($prepaid_tickets_data, $final_hto_data[0]);
                if(!$confirm_data['initialPayment']) {
                    $this->CreateLog('initialPayment_false.php', json_encode($prepaid_tickets_data), array()); 
                    return false;
                }
                 $this->CreateLog('initialPayment_false_check_hotel.php', json_encode($prepaid_tickets_data), array($hotel_name)); 
                if(strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                    $confirm_data['selected_date'] = $prepaid_tickets_data['selected_date'] != '' ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                    $confirm_data['from_time'] = $prepaid_tickets_data['from_time'] != '' ? $prepaid_tickets_data['from_time'] : '0';
                    $confirm_data['to_time'] = $prepaid_tickets_data['to_time'] != '' ? $prepaid_tickets_data['to_time'] : '0';
                    $confirm_data['slot_type'] = $prepaid_tickets_data['timeslot'] != '' ? $prepaid_tickets_data['timeslot'] : '0';
                } else {
                    $confirm_data['selected_date'] = $prepaid_tickets_data['selected_date'] != '' ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                    $confirm_data['from_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['from_time'] : '0';
                    $confirm_data['to_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['to_time'] : '0';
                    $confirm_data['slot_type'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['timeslot'] : '0';
                }
                $confirm_data['ticketpriceschedule_id'] = $prepaid_tickets_data['tps_id'];
                $confirm_data['ticketwithdifferentpricing'] = $ticket_details[$ticket_id]->ticketwithdifferentpricing;

                $confirm_data['booking_selected_date']      = '';
                if( $ticket_details[$ticket_id]->is_reservation == '0' && isset($prepaid_tickets_data['booking_selected_date']) ) {
                    $confirm_data['booking_selected_date']      = $prepaid_tickets_data['booking_selected_date'];
                }
                
                $confirm_data['prepaid_type'] = $activation_method;
                $confirm_data['cashier_id'] = $prepaid_tickets_data['cashier_id'];
                $confirm_data['cashier_name'] = $prepaid_tickets_data['cashier_name'];
                $confirm_data['action_performed'] = isset($prepaid_tickets_data['action_performed']) ? $prepaid_tickets_data['action_performed'] : '';
                $mpos_postpaid_order = 0;
                if(strpos($prepaid_tickets_data['action_performed'], 'MPOS_PST_INSRT') !== false) {
                    $confirm_data['action_performed'] =  "MPOS_PST_INSRT";
                    $mpos_postpaid_order = 1 ;
                }
                
                if ($upsell_order || ($prepaid_tickets_data['activation_method'] == "19" && $prepaid_tickets_data['is_addon_ticket'] == "0" ) || $mpos_postpaid_order == 1) {
                    $confirm_data['userid'] = $prepaid_tickets_data['museum_cashier_id'];
                    $user_name = explode(' ', $prepaid_tickets_data['museum_cashier_name']);
                    $confirm_data['fname'] = $user_name[0];
                    array_shift($user_name);
                    $confirm_data['lname'] = implode(" ", $user_name);
                } else if(isset($prepaid_tickets_data['bleep_pass_no']) && !empty($prepaid_tickets_data['bleep_pass_no'])) {
                    $confirm_data['userid'] = $prepaid_tickets_data['voucher_updated_by'];
                    $voucher_updated_by_name = explode(' ', $prepaid_tickets_data['voucher_updated_by_name']);
                    $confirm_data['fname'] = $voucher_updated_by_name[0];
                    array_shift($voucher_updated_by_name);
                    $confirm_data['lname'] =  implode(" ", $voucher_updated_by_name);
                    $confirm_data['scanned_pass'] = ($prepaid_tickets_data['channel_type'] == 10 || $prepaid_tickets_data['channel_type'] == 11) ? $prepaid_tickets_data['bleep_pass_no'] : '';
                } else{
                    $confirm_data['userid'] = '0';
                    $confirm_data['fname'] = 'Prepaid';
                    $confirm_data['lname'] = 'ticket';

                }
                $confirm_data['financial_id'] = $prepaid_tickets_data['financial_id'];
                $confirm_data['financial_name'] = $prepaid_tickets_data['financial_name'];
                $confirm_data['is_prioticket'] = $is_prioticket;
                $confirm_data['check_age'] = 0;
                $confirm_data['cmpny'] = $hotel_info;
                $confirm_data['timeZone'] = $prepaid_tickets_data['timezone'];
                $confirm_data['used'] = $prepaid_tickets_data['used'];
                if(!isset($prepaid_tickets_data['is_prepaid'])) {
                    $prepaid_tickets_data['is_prepaid'] = "1";
                }
                $confirm_data['is_prepaid'] = (isset($prepaid_tickets_data['channel_type']) && $prepaid_tickets_data['channel_type'] == 2) ? 1 : $prepaid_tickets_data['is_prepaid'];
                $confirm_data['is_voucher'] = $prepaid_tickets_data['is_voucher'];
                $confirm_data['is_shop_product'] = $prepaid_tickets_data['product_type'];
                $confirm_data['is_pre_ordered'] = $prepaid_tickets_data['used'] == '1' ? 1 : 0;
                if (in_array($activation_method, $allowed_activation_methods) && $payment_conditions == 7 || $prepaid_tickets_data['booking_status'] == '1') {
                    $confirm_data['is_pre_ordered'] = 1;
                }
                $confirm_data['order_status'] = $prepaid_tickets_data['order_status'];
                $confirm_data['extra_text_field'] = $ticket_details[$ticket_id]->extra_text_field;
                $confirm_data['extra_text_field_answer'] = $prepaid_tickets_data['extra_text_field_answer'];
                $confirm_data['is_iticket_product'] = $prepaid_tickets_data['is_iticket_product'];
                $confirm_data['without_elo_reference_no'] = $prepaid_tickets_data['without_elo_reference_no'];
                if (isset($prepaid_tickets_data['is_addon_ticket']) && $prepaid_tickets_data['is_addon_ticket'] != '') {
                    $confirm_data['is_addon_ticket'] = $prepaid_tickets_data['is_addon_ticket'];
                } else {
                    $confirm_data['is_addon_ticket'] = '';
                }
                
                $transaction_id = $this->get_auto_generated_id_dpos($confirm_data['visitor_group_no'], $confirm_data['prepaid_ticket_id']);
                
                if ($confirm_data['is_addon_ticket'] != "2") {
                    $transaction_id_array[$prepaid_tickets_data['cluster_group_id']][$prepaid_tickets_data['clustering_id']][] = $transaction_id;
                    $this->CreateLog('checktransaction.php', json_encode($transaction_id_array), array()); 
                    $confirm_data['merchant_admin_id'] = $ticket_details[$ticket_id]->merchant_admin_id;
                    $confirm_data['merchant_admin_name'] = $ticket_details[$ticket_id]->merchant_admin_name;
                    $confirm_data['merchant_currency_code'] = $merchant_currency_code;
                    
                } else {
                    $cluster_tickets_transaction_id_array[$prepaid_tickets_data['ticket_id'] . '::' . $prepaid_tickets_data['cluster_group_id'] . '::' . $prepaid_tickets_data['clustering_id']][] = $transaction_id;
                    $this->CreateLog('checktransaction.php', json_encode($cluster_tickets_transaction_id_array), array()); 
                    $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_name'];
                    $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_id'];
                    $confirm_data['merchant_currency_code'] = $confirm_data['supplier_currency_code'];
                    $confirm_data['channel_id'] = $reseller_channel_id;
                }
                if (!empty($main_ticket_combi_data) && isset($main_ticket_combi_data[$prepaid_tickets_data['ticket_id']])) {
                    $confirm_data['is_combi'] = $main_ticket_combi_data[$prepaid_tickets_data['ticket_id']];
                } else {
                    $confirm_data['is_combi'] = 0;
                }

                $confirm_data['ticketDetail'] = $ticket_details[$ticket_id];
                $confirm_data['cluster_ticket_net_price'] = $prepaid_tickets_data['net_price'];
                $confirm_data['discount'] = $prepaid_tickets_data['discount'];
                $confirm_data['is_discount_in_percent'] = $prepaid_tickets_data['is_discount_in_percent'];
                $confirm_data['split_payment'] = $data['split_payment'];
                $details['visitor_tickets'][] = $confirm_data;                
                $confirm_data['extra_discount'] = $prepaid_tickets_data['extra_discount'];
                $confirm_data['order_currency_extra_discount'] = $prepaid_tickets_data['order_currency_extra_discount'];
                $confirm_data['commission_type'] = isset($prepaid_tickets_data['commission_type']) ? $prepaid_tickets_data['commission_type'] : 0;
                $confirm_data['booking_information'] = isset($mainharray['booking_information']) ? $mainharray['booking_information'] : '';
                $confirm_data['updated_at'] = $prepaid_tickets_data['created_at'];
                $confirm_data['supplier_gross_price'] = $prepaid_tickets_data['supplier_price'];
                $confirm_data['supplier_discount'] = $prepaid_tickets_data['supplier_discount'];
                $confirm_data['supplier_ticket_amt'] = $prepaid_tickets_data['supplier_original_price'];
                $confirm_data['supplier_tax_value'] = $prepaid_tickets_data['supplier_tax'];
                $confirm_data['supplier_net_price'] = $prepaid_tickets_data['supplier_net_price'];
                if( 
                    isset( $prepaid_tickets_data['reprice_discount'] ) && 
                    isset( $prepaid_tickets_data['reprice_discount'][$keyReprice] )
                ) {
                    if ( $prepaid_tickets_data['reprice_discount'][$keyReprice]['surcharge_type'] == 1) {
                        $confirm_data['price'] = ( $prepaid_tickets_data['price'] - $prepaid_tickets_data['reprice_discount'][$keyReprice]['discount_code_amount'] );
                    } else {
                        $confirm_data['price'] = ( $prepaid_tickets_data['price'] + $prepaid_tickets_data['reprice_discount'][$keyReprice]['discount_code_amount'] );
                        $confirm_data['discount_type'] = 0;
                        $confirm_data['new_discount'] = 0;
                        $confirm_data['gross_discount_amount'] = 0;
                        $confirm_data['discount'] = 0;
                        $confirm_data['gross_discount_amount'] = 0;
                    }
                    
                }
                $confirm_data['tp_payment_method']  = $prepaid_tickets_data['tp_payment_method'];
                $confirm_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                $confirm_data['payment_date'] = !empty($prepaid_tickets_data['payment_date']) ? $prepaid_tickets_data['payment_date'] : '0000-00-00 00:00';
                $confirm_data['chart_number'] = $prepaid_tickets_data['chart_number'];
                $confirm_data['account_number'] = $prepaid_tickets_data['account_number'];
                $confirm_data['uncancel_order'] = $uncancel_order;
                $confirm_data['primary_host_name'] = $prepaid_tickets_data['primary_host_name'];
                $confirm_data['ticketsales'] = $prepaid_tickets_data['is_prepaid'];
                $confirm_data['distributor_reseller_id'] = $hotel_info->reseller_id;
                $confirm_data['distributor_reseller_name'] = $hotel_info->reseller_name;
                $confirm_data['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                $confirm_data['tickettype_name'] = $prepaid_tickets_data['ticket_type'];
                $confirm_data['prepaid_reseller_id'] = $prepaid_tickets_data['reseller_id'];
                $confirm_data['prepaid_reseller_name'] = $prepaid_tickets_data['reseller_name'];
                $confirm_data['sub_catalog_id'] = $sub_catalog_id;
                $confirm_data['ticket_status'] = isset($prepaid_tickets_data['activated']) ? $prepaid_tickets_data['activated'] : 1;
                $this->CreateLog('ticket_status.php', 'step 2', array("activated" => $confirm_data['ticket_status'], "prepaid_tickets_data" => $prepaid_tickets_data['activated']));
                
                if( in_array( $prepaid_tickets_data['channel_type'], array( '5', '10', '11' ) ) ) {
                    $confirm_data['voucher_updated_by'] = $prepaid_tickets_data['voucher_updated_by'];
                    $confirm_data['voucher_updated_by_name'] = $prepaid_tickets_data['voucher_updated_by_name'];
                }
                $confirm_data['node_api_response'] = isset($prepaid_tickets_data['node_api_response']) ? $prepaid_tickets_data['node_api_response'] : 0;
                
                /* pass reseller sub catalog ids data further */
                $confirm_data['ticket_reseller_subcatalog_id'] = $ticket_reseller_subcatalog_id;
                
                unset($prepaid_tickets_data['node_api_response']);
                $extra_option_merchant_data[$ticket_id] = 0;
                //insert in vt when booking_status is 1 only.
                if( $booking_status == '1' ) {
                    /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
                    if ($uncancel_order == 1 && !empty($vt_version)) {
                        $confirm_data['version'] = (int)$vt_version['version'];
                    }
                    /* case handle for v3.2 API.*/
                    if(!empty($data['action_from'])){
                        $confirm_data['action_from'] = $data['action_from']; 
                    }
                    if ($confirm_data['is_addon_ticket'] == 0) {
                        $confirm_data['cluster_net_price'] = $cluster_net_price[$confirm_data['clustering_id']];
                    } else {
                        $confirm_data['cluster_net_price'] = 0;
                    }
                    if(isset($prepaid_tickets_data['is_refunded'])) {
                        $confirm_data['is_refunded'] = $prepaid_tickets_data['is_refunded'];
                    }
                    $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, $is_secondary_db);
                    $prepaid_tickets_data['tax_id'] = $visitor_tickets_data['ticket_tax_id'];
                    $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                    $transaction_id_wise_ticketAmt[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['ticketAmt'];
                    $transaction_id_wise_discount[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['discount'];
                    $transaction_id_wise_clustering_id[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['targetlocation'];
                    $clustering_id_wise_ticket_id[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['ticketId'];

                    $group_booking_email_data = array();
                } else {
                    $ticket_age_groups = $this->order_process_vt_model->getAgeGroupAndDiscount($prepaid_tickets_data['tps_id']);
                    $prepaid_tickets_data['tax_id'] = $ticket_age_groups->ticket_tax_id;
                    if ($ticket_details[$ticket_id]->msgClaim != '') {
                        $ticket_type = $prepaid_tickets_data['ticket_type']."(s)";
                        $age_groups = $ticket_age_groups->agefrom . '-' . $ticket_age_groups->ageto;
                        $group_booking_email_data['museum_email'] = $ticket_details[$ticket_id]->msgClaim;
                        $group_booking_email_data['museum_additional_email'] = $ticket_details[$ticket_id]->additional_notification_emails;
                        $group_booking_email_data['ticket_id'] = $ticket_id;
                        $group_booking_email_data['ticket_title'] = $ticket_details[$ticket_id]->postingEventTitle;
                        $group_booking_email_data['is_reservation'] = $ticket_details[$ticket_id]->is_reservation;
                        $group_booking_email_data['age_groups'] = array('0' => array('ticket_type' => $ticket_type, 'count' => '1', 'age_group' => $age_groups));
                        if(!empty($ticket_details[$ticket_id]->booking_email_text)) {
                            $group_booking_email_data['booking_email_text'] = $ticket_details[$ticket_id]->booking_email_text;
                        }
                    }
                    $group_booking_email_data['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                    $group_booking_email_data['museum_name'] = $ticket_details[$ticket_id]->museum_name;
                    $group_booking_email_data['ticketpriceschedule_id'] = $prepaid_tickets_data['tps_id'];
                    $group_booking_email_data['ticket_tax_id'] = $ticket_details[$ticket_id]->ticket_tax_id;
                    $group_booking_email_data['ticket_tax_value'] = $ticket_details[$ticket_id]->ticket_tax_value;
                }
                $museum_net_fee = 0.00;
                $reseller_used_limit = 0.00;
                $distributor_used_limit = 0.00;
                $partner_used_limit = 0.00;
                $hgs_net_fee = 0.00;
                $distributor_net_fee = 0.00;
                $merchant_price = 0.00;
                $merchant_net_price = 0.00;
                $museum_gross_fee = 0.00;
                $hgs_gross_fee = 0.00;
                $distributor_gross_fee = 0.00;
                $market_merchant_id  = 0;
                $new_merchant_admin_id  = 0;
                $partner_used_limit_exist = 0;
                $supplier_original_price = 0;
                $supplier_discount = 0;
                $supplier_net_price = 0;
                $supplier_price = 0;
                $transaction_fee_rows = array();
                $merchant_admin_id = array();
                if ($confirm_data['is_addon_ticket'] == 0) {
                    $taxesForDiscount = array();
                }
                if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {
                    foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $visitor_row_batch) {
                        if ($visitor_row_batch['row_type'] == '1') {
                            $all_visitor_tickets_ids = $all_visitor_tickets_ids . ',' . $visitor_row_batch['id'];
                            $market_merchant_id = $visitor_row_batch['market_merchant_id'];
                            $partner_used_limit = $visitor_row_batch['partner_gross_price'];
                            $ordered_by_reseller_id = $visitor_row_batch['reseller_id'];
                            $general_fee = $visitor_row_batch['partner_gross_price'];
                            $guest_row = $visitor_row_batch;
                            $total_order_amount += $general_fee;
                            $supplier_currency_code = $visitor_row_batch['supplier_currency_code'];
                            $supplier_original_price += $visitor_row_batch['supplier_gross_price'];
                            $supplier_price += $visitor_row_batch['supplier_price'];
                            $supplier_discount += $visitor_row_batch['supplier_discount'];
                            $supplier_net_price += $visitor_row_batch['supplier_net_price'];
                            $supplier_tax_id = $visitor_row_batch[$this->supplier_tax_id_col];
                            $merchant_currency_code = $visitor_row_batch[$this->merchant_currency_code_col];
                            $merchant_price += $visitor_row_batch[$this->merchant_price_col];
                            $merchant_net_price += $visitor_row_batch[$this->merchant_net_price_col];
                            $merchant_tax_id = $visitor_row_batch[$this->merchant_tax_id_col];
                            $admin_currency_code = $visitor_row_batch[$this->admin_currency_code_col];
                            
                            $taxesForDiscount['1'] = array("tax_id" => $visitor_row_batch['tax_id'], 
                                                            "tax_name" => $visitor_row_batch['tax_name'], 
                                                            "tax_value" => $visitor_row_batch['tax_value']);
                            
                        } else if ($visitor_row_batch['row_type'] == '2') {
                            $museum_net_fee = $visitor_row_batch['partner_net_price'];
                            $museum_gross_fee = $visitor_row_batch['partner_gross_price'];
                            $reseller_used_limit = $visitor_row_batch['partner_gross_price'];
                            $distributor_used_limit = $visitor_row_batch['partner_gross_price'];
                            if ($ordered_by_reseller_id > 0 && $visitor_row_batch['reseller_id'] != $ordered_by_reseller_id) {
                                $published_reseller_limit[$visitor_row_batch['reseller_id'].'_'.$ordered_by_reseller_id] += $visitor_row_batch['partner_gross_price'];
                                $published_reseller_limit[$ordered_by_reseller_id.'_'.$visitor_row_batch['hotel_id']] += $visitor_row_batch['partner_gross_price'];
                            }
                        } else if ($visitor_row_batch['row_type'] == '3') {
                            $distributor_net_fee = $visitor_row_batch['partner_net_price'];
                            $distributor_gross_fee = $visitor_row_batch['partner_gross_price'];
                            $taxesForDiscount['3'] = array("tax_id" => $visitor_row_batch['tax_id'], 
                                                            "tax_name" => $visitor_row_batch['tax_name'], 
                                                            "tax_value" => $visitor_row_batch['tax_value']);
                        } else if ($visitor_row_batch['row_type'] == '4') {
                            $hgs_net_fee = $visitor_row_batch['partner_net_price'];
                            $hgs_gross_fee = $visitor_row_batch['partner_gross_price'];
                            $distributor_used_limit += $visitor_row_batch['partner_gross_price'];
                            $taxesForDiscount['4'] = array("tax_id" => $visitor_row_batch['tax_id'], 
                                                            "tax_name" => $visitor_row_batch['tax_name'], 
                                                            "tax_value" => $visitor_row_batch['tax_value']);
                        } else if ($visitor_row_batch['row_type'] == '17') { 
                            $new_merchant_admin_id = $visitor_row_batch['merchant_admin_id'];
                            $reseller_used_limit += $visitor_row_batch['partner_gross_price'];
                            $distributor_used_limit += $visitor_row_batch['partner_gross_price'];
                            if (!empty($new_merchant_admin_id)) {
                                $merchant_admin_id[] = $new_merchant_admin_id;
                            }
                            
                        } else if ($visitor_row_batch['row_type'] == '15') { 
                            $partner_used_limit_exist = 1;
                            $partner_used_limit -= $visitor_row_batch['partner_gross_price'];
                        }
                        $ticket_type_id = $visitor_row_batch['ticketType']  ;                 
                    }
                    
                    // create transaction_fee_data for main ticket
                    if ($prepaid_tickets_data['is_addon_ticket'] != 2) {
                        // group by booking id
                        
                        $transaction_key = $prepaid_tickets_data['ticket_id']."_".$ticket_type_id."_".$prepaid_tickets_data['selected_date']."_".$prepaid_tickets_data['from_time']."_".$prepaid_tickets_data['to_time'];
                        if (isset($transaction_data[$transaction_key])) {
                            $transaction_data[$transaction_key]['total_order_amount'] += $general_fee;
                        } else {
                            $transaction_data[$transaction_key] = $prepaid_tickets_data;
                            $transaction_data[$transaction_key]['quantity'] = $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['pax'] = $prepaid_tickets_data['pax'];
                            $transaction_data[$transaction_key]['total_order_amount'] = $general_fee;
                            $transaction_fee_data =  $this->get_transaction_fee_data($hotel_info, $prepaid_tickets_data['ticket_id'],$ticket_type_id, $prepaid_tickets_data['museum_id'], $distributor_partner_id, $order_date, $merchant_admin_id, $prepaid_tickets_data['from_time'], $prepaid_tickets_data['to_time']);
                            $transaction_data[$transaction_key]['transaction_fee_data'] = $transaction_fee_data;
                        }
                        $transaction_data[$transaction_key]['data'] = $guest_row;
                    }
                    
                    
                }
                // partner row not exist
                if (empty($partner_used_limit_exist)){
                    $partner_used_limit = 0;
                }
                // Update used limit of distributor/Partner/reseller partner
                $update_credit_limit                        = array();
                $update_credit_limit['museum_id']           = $prepaid_tickets_data['museum_id'];
                $update_credit_limit['reseller_id']         = $prepaid_tickets_data['reseller_id'];
                $update_credit_limit['hotel_id']            = $prepaid_tickets_data['hotel_id'];
                $update_credit_limit['partner_id']          = $prepaid_tickets_data['distributor_partner_id'];
                $update_credit_limit['cashier_name']        = $prepaid_tickets_data['distributor_partner_name'];
                $update_credit_limit['hotel_name']          = $prepaid_tickets_data['hotel_name'];
                $update_credit_limit['visitor_group_no']    = $prepaid_tickets_data['visitor_group_no'];
                $update_credit_limit['merchant_admin_id']   = $prepaid_tickets_data['merchant_admin_id'];
                
                if (array_key_exists($prepaid_tickets_data['museum_id'], $update_credit_limit_data)) {
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['used_limit']             += $reseller_used_limit;
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['distributor_used_limit'] += $distributor_used_limit;
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['partner_used_limit']     += $partner_used_limit;
                } else {
                    $update_credit_limit['used_limit']              = $reseller_used_limit;
                    $update_credit_limit['distributor_used_limit']  = $distributor_used_limit;
                    $update_credit_limit['partner_used_limit']      = $partner_used_limit;
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']] = $update_credit_limit;
                }
                $extra_option_merchant_data[$ticket_id] = $market_merchant_id;
                
                
                $mail_key++;                
                if($prepaid_tickets_data['is_addon_ticket'] == 2) {
                    $visitor_tickets_data['id'] =  $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['id'];
                }                
                if(isset($visitor_tickets_data) && !empty($visitor_tickets_data) && isset($visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['id']) && $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['id'] != 0) {
                    $prepaid_tickets_data['visitor_tickets_id'] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['id'];
                }
                $prepaid_tickets_data['museum_net_fee'] = $museum_net_fee;
                $prepaid_tickets_data['distributor_net_fee'] = $distributor_net_fee;
                $prepaid_tickets_data['hgs_net_fee'] = $hgs_net_fee;
                $prepaid_tickets_data['museum_gross_fee'] = $museum_gross_fee;
                $prepaid_tickets_data['distributor_gross_fee'] = $distributor_gross_fee;
                $prepaid_tickets_data['hgs_gross_fee'] = $hgs_gross_fee;
                $prepaid_tickets_data['passNo'] = $orignal_pass_no; 
                $prepaid_tickets_data['merchant_admin_id'] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['merchant_admin_id'];
                
                /* #region  to update multi currency prices */
                $prepaid_tickets_data['supplier_currency_code'] = $supplier_currency_code;
                $prepaid_tickets_data['supplier_gross_price'] = $supplier_original_price;
                $prepaid_tickets_data['supplier_price'] = $supplier_price;
                $prepaid_tickets_data['supplier_discount'] = $supplier_discount;
                $prepaid_tickets_data['supplier_net_price'] = $supplier_net_price;
                $prepaid_tickets_data[$this->supplier_tax_id_col] = $supplier_tax_id;
                $prepaid_tickets_data[$this->merchant_currency_code_col] = $merchant_currency_code;
                $prepaid_tickets_data[$this->merchant_price_col] = $merchant_price;
                $prepaid_tickets_data[$this->merchant_net_price_col] = $merchant_net_price;
                $prepaid_tickets_data[$this->merchant_tax_id_col] = $merchant_tax_id;
                $prepaid_tickets_data[$this->admin_currency_code_col] = $admin_currency_code;
                /* #endregion  to update multi currency prices */
                /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
                if ($uncancel_order == 1 && !empty($pt_version)) {
                    $prepaid_tickets_data['version'] = (int)$pt_version['version'];
                }
                $visitor_ids_array[] = $visitor_tickets_data['id'];
            }
        }        
        /* #region to get transaction fee rows  */
        if (!empty($transaction_data)) {
            // insert transaction fee rows
            // For all debitor types except Market merchant
            $transaction_fee_rows = $this->transaction_data($transaction_data, $total_order_amount, $total_quantity);
            if (!empty($transaction_fee_rows)) {
                foreach ($transaction_fee_rows as $row) {
                    $final_visitor_data = array_merge($final_visitor_data, $row);
                }
            }
        }
        /* #endregion to get transaction fee rows*/
        $eoc = 800;
        if (isset($data['prepaid_extra_options_data'])) {
            if($channel_type == 3 || $channel_type == 1) {
                $booking_status = 1;
            }
            $details['extra_services'] = $data['prepaid_extra_options_data'];
            $taxes = array();
            $extra_service_visitors_data = array();
            $prepaid_extra_options_data = array();
            $extra_option_id_increment = 0;
            foreach ($data['prepaid_extra_options_data'] as $service) {
                $service['from_time'] = trim($service['from_time']);
                $service['to_time'] = trim($service['to_time']);
                
                $prepaid_extra_options_data[] = $service;
                $extra_service_options_for_email[$service['ticket_id'] . '_' . $service['ticket_price_schedule_id']][] = $service['description'] . ' ' . $service['quantity'];
                // If quantity of service is more than one then we add multiple transactions for financials page
                if (!in_array($service['tax'], $taxes)) {
                    $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                    $taxes[$service['tax']] = $ticket_tax_id;
                } else {
                    $ticket_tax_id = $taxes[$service['tax']];
                }

                $ticket_tax_value = $service['tax'];
                $custom_settings_detail = $custom_settings[$service['ticket_id'] . '_' . $service['ticket_price_schedule_id']];
                $custom_ticket_settings_details = $custom_ticket_settings[$service['ticket_id']];

                if($booking_status == '1') {
                    
                for ($i = 0; $i < $service['quantity']; $i++) {
                    // Add extra option per transaction in used_limit
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['used_limit']  += $service['price'];
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['distributor_used_limit']  += $service['price'];
                    $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['partner_used_limit']  += $service['price'];
                    $extra_option_id_increment++;
                    $service_data_for_visitor = array();
                    $service_data_for_museum = array();
                    $p = 0;
                    $total_amount = $service['price'];
                    $order_currency_total_amount = $service['order_currency_price'];
                    $ticket_id = $service['ticket_id'];
                    $eoc++;
                    $transaction_id = $visitor_group_no."".$eoc;
                    $ticketpriceschedule_id = isset($service['ticket_price_schedule_id']) ? $service['ticket_price_schedule_id'] : 0;
                    $tickettype_name = '';
                    $ticketTypeDetail = $this->order_process_vt_model->getTicketTypeFromTicketpriceschedule_id($ticketpriceschedule_id);
                    if(!empty($ticketTypeDetail)) {
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
                        $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '1');
                        $service_data_for_visitor['id'] = $visitor_ticket_id;
                        $service_data_for_visitor['is_prioticket'] = 0;
                        $service_data_for_visitor['created_date'] = $service['created'];
                        $service_data_for_visitor['transaction_id'] = $transaction_id;
                        $service_data_for_visitor['ticket_booking_id'] = $extra_option_ticket_booking_id;
                        $service_data_for_visitor['visitor_group_no'] = $visitor_group_no;
                        $service_data_for_visitor['invoice_id'] = '';
                        $service_data_for_visitor['ticketId'] = $ticket_id;
                        $service_data_for_visitor['ticket_title'] = $museum_details[$ticket_id] . '~_~' . $service['description'];
                        $service_data_for_visitor['ticketpriceschedule_id'] = isset($service['ticket_price_schedule_id']) ? $service['ticket_price_schedule_id'] : 0;
                        $service_data_for_visitor['tickettype_name'] = $tickettype_name;
                        $service_data_for_visitor['version'] = $prepaid_tickets_data['version'];
                        $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                        $service_data_for_visitor['selected_date'] = $service['selected_date'];
                        $service_data_for_visitor['from_time']     = $service['from_time'];
                        $service_data_for_visitor['to_time']       = $service['to_time'];
                        $service_data_for_visitor['slot_type']     = $service['timeslot'];            
                        $service_data_for_visitor['partner_id'] = $hotel_info->cod_id;
                        $service_data_for_visitor['distributor_partner_id']   = $confirm_data['distributor_partner_id'];
                        $service_data_for_visitor['distributor_partner_name'] = $confirm_data['distributor_partner_name'];                    
                        $service_data_for_visitor['visit_date'] = strtotime($service['created']);
                        $service_data_for_visitor['visit_date_time'] = $service['created'];
                        $service_data_for_visitor['paid'] = "1";
                        $service_data_for_visitor['payment_method'] = $payment_method;
                        $service_data_for_visitor['captured'] = "1";
                        $service_data_for_visitor['debitor'] = "Guest";
                        $service_data_for_visitor['creditor'] = "Debit";
                        $service_data_for_visitor['split_cash_amount'] = isset($confirm_data['split_payment'][0]['cash_amount']) ? $confirm_data['split_payment'][0]['cash_amount'] : 0;
                        $service_data_for_visitor['split_card_amount'] = isset($confirm_data['split_payment'][0]['card_amount']) ? $confirm_data['split_payment'][0]['card_amount'] : 0;
                        $service_data_for_visitor['split_direct_payment_amount'] = isset($confirm_data['split_payment'][0]['direct_amount']) ? $confirm_data['split_payment'][0]['direct_amount'] : 0;
                        $service_data_for_visitor['split_voucher_amount'] = isset($confirm_data['split_payment'][0]['coupon_amount']) ? $confirm_data['split_payment'][0]['coupon_amount'] : 0;
                        $service_data_for_visitor['partner_gross_price'] = $total_amount;
                        $service_data_for_visitor['order_currency_partner_gross_price'] = $order_currency_total_amount;
                        $service_data_for_visitor['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                        $service_data_for_visitor['order_currency_partner_net_price'] = ($order_currency_total_amount * 100) / ($ticket_tax_value + 100);
                        $service_data_for_visitor['tax_id'] = $ticket_tax_id;
                        $service_data_for_visitor['tax_value'] = $ticket_tax_value;
                        $service_data_for_visitor['invoice_status'] = $invoice_status;
                        $service_data_for_visitor['transaction_type_name'] = "Extra option sales";
                        $service_data_for_visitor['ticket_extra_option_id'] = $service['extra_option_id'];
                        $service_data_for_visitor['paymentMethodType'] = $hotel_info->paymentMethodType;
                        $service_data_for_visitor['row_type'] = "1";
                        $service_data_for_visitor['isBillToHotel'] = $isBillToHotel;
                        $service_data_for_visitor['activation_method'] = $activation_method;
                        $service_data_for_visitor['channel_type'] = $channel_type;
                        $service_data_for_visitor['vt_group_no'] = $visitor_group_no;
                        $service_data_for_visitor['ticketAmt'] = $total_amount;
                        $service_data_for_visitor['debitor'] = "Guest";
                        $service_data_for_visitor['creditor'] = "Debit";
                        $service_data_for_visitor['timezone'] = $timezone;
                        $service_data_for_visitor['is_prepaid'] = "1";
                        $service_data_for_visitor['used'] = "0";
                        $service_data_for_visitor['booking_status'] = $booking_status;
                        $service_data_for_visitor['hotel_name'] = $hotel_name;
                        $service_data_for_visitor['hotel_id'] = $hotel_id;
                        $service_data_for_visitor['channel_id'] = $channel_id;
                        $service_data_for_visitor['channel_name'] = $channel_name;
                        $service_data_for_visitor['shift_id'] = $shift_id;
                        $service_data_for_visitor['pos_point_id'] = $pos_point_id;
                        $service_data_for_visitor['pos_point_name'] = $pos_point_name;
                        $service_data_for_visitor['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                        $service_data_for_visitor['museum_name'] = $museum_details[$ticket_id];
                        $service_data_for_visitor['cashier_id'] = $cashier_id;
                        $service_data_for_visitor['cashier_name'] = $cashier_name;
                        $service_data_for_visitor['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
                        $service_data_for_visitor['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date']) +($timezone * 3600));
                        if ($activation_method == '10') {
                            $service_data_for_visitor['is_voucher'] = '1';
                        }
                        $service_data_for_visitor['reseller_id'] = $hotel_info->reseller_id;
                        $service_data_for_visitor['reseller_name'] = $hotel_info->reseller_name;
                        $service_data_for_visitor['saledesk_id'] = $hotel_info->saledesk_id;
                        $service_data_for_visitor['saledesk_name'] = $hotel_info->saledesk_name;
                        $service_data_for_visitor['is_custom_setting'] = $custom_settings_detail['is_custom_setting'];
                        $service_data_for_visitor['external_product_id'] = $custom_settings_detail['external_product_id'];
                        $service_data_for_visitor['account_number'] = $custom_ticket_settings_details['account_number'];
                        $service_data_for_visitor['chart_number'] = $custom_ticket_settings_details['chart_number'];
                        $service_data_for_visitor['updated_at'] = $service['created'];
                        $service_data_for_visitor['supplier_gross_price'] = $total_amount;
                        $service_data_for_visitor['supplier_ticket_amt'] = $total_amount;
                        $service_data_for_visitor['supplier_tax_value'] = $ticket_tax_value;
                        $service_data_for_visitor['supplier_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);


                        $service_data_for_visitor['supplier_currency_code'] = isset($confirm_data['supplier_currency_code']) ? $confirm_data['supplier_currency_code'] : '';
                        $service_data_for_visitor['supplier_currency_symbol'] = isset($confirm_data['supplier_currency_symbol']) ? $confirm_data['supplier_currency_symbol'] : '';
                        $service_data_for_visitor['order_currency_code'] = isset($confirm_data['order_currency_code']) ? $confirm_data['order_currency_code'] : '';
                        $service_data_for_visitor['order_currency_symbol'] = isset($confirm_data['order_currency_symbol']) ? $confirm_data['order_currency_symbol'] : '';

                        $service_data_for_visitor['currency_rate'] = isset($confirm_data['currency_rate']) ? $confirm_data['currency_rate'] : '1';
                        $service_data_for_visitor['tp_payment_method'] = $prepaid_tickets_data['tp_payment_method'];
                  
                        $service_data_for_visitor['payment_date'] = $prepaid_tickets_data['payment_date'];
                        $service_data_for_visitor['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                        $service_data_for_visitor['partner_category_id'] = $prepaid_tickets_data['partner_category_id'];
                        $service_data_for_visitor['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                        $service_data_for_visitor['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                        $service_data_for_visitor['merchant_admin_id'] = $extra_option_merchant_data[$ticket_id];

                        $extra_service_visitors_data[] = $service_data_for_visitor;
                        $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '2');
                        $service_data_for_museum['id'] = $visitor_ticket_id;
                        $service_data_for_museum['is_prioticket'] = 0;
                        $service_data_for_museum['created_date'] = $service['created'];
                        $service_data_for_museum['transaction_id'] = $transaction_id;
                        $service_data_for_museum['ticket_booking_id'] = $extra_option_ticket_booking_id;
                        $service_data_for_museum['invoice_id'] = '';
                        $service_data_for_museum['ticketId'] = $ticket_id;
                        $service_data_for_museum['ticket_title'] = $service['name'] . ' (Extra option)';
                        $service_data_for_museum['selected_date'] = $service['selected_date'];
                        $service_data_for_museum['from_time']     = $service['from_time'];
                        $service_data_for_museum['to_time']       = $service['to_time'];
                        $service_data_for_museum['slot_type']     = $service['slot_type']; 
                        $service_data_for_museum['ticketpriceschedule_id'] = isset($service['ticket_price_schedule_id']) ? $service['ticket_price_schedule_id'] : 0;
                        $service_data_for_museum['tickettype_name'] = $tickettype_name;
                        $service_data_for_museum['partner_id'] = $ticket_details[$ticket_id]->cod_id;
                        $service_data_for_museum['partner_name'] = $museum_details[$ticket_id];
                        $service_data_for_museum['museum_name'] = $museum_details[$ticket_id];
                        $service_data_for_museum['ticketAmt'] = $total_amount;
                        $service_data_for_museum['version'] = $prepaid_tickets_data['version'];
                        $service_data_for_museum['booking_status'] = $booking_status;
                        $service_data_for_museum['vt_group_no'] = $visitor_group_no;
                        $service_data_for_museum['visit_date_time'] = $service['created'];
                        $service_data_for_museum['order_confirm_date'] = $service['created'];
                        $service_data_for_museum['visit_date'] = strtotime($service['created']);
                        $service_data_for_museum['ticketPrice'] = $total_amount;
                        $service_data_for_museum['paid'] = "0";
                        $service_data_for_museum['isBillToHotel'] = $isBillToHotel;
                        $service_data_for_museum['activation_method']   =   $activation_method;
                        $service_data_for_museum['debitor'] = $museum_details[$ticket_id];
                        $service_data_for_museum['creditor'] = 'Credit';
                        $service_data_for_museum['commission_type'] = "0";
                        $service_data_for_museum['split_cash_amount'] = isset($confirm_data['split_payment'][0]['cash_amount']) ? $confirm_data['split_payment'][0]['cash_amount'] : 0;
                        $service_data_for_museum['split_card_amount'] = isset($confirm_data['split_payment'][0]['card_amount']) ? $confirm_data['split_payment'][0]['card_amount'] : 0;
                        $service_data_for_museum['split_direct_payment_amount'] = isset($confirm_data['split_payment'][0]['direct_amount']) ? $confirm_data['split_payment'][0]['direct_amount'] : 0;
                        $service_data_for_museum['split_voucher_amount'] = isset($confirm_data['split_payment'][0]['coupon_amount']) ? $confirm_data['split_payment'][0]['coupon_amount'] : 0;
                        $service_data_for_museum['partner_gross_price'] = $total_amount;
                        $service_data_for_museum['order_currency_partner_gross_price'] = $order_currency_total_amount;
                        $service_data_for_museum['partner_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);
                        $service_data_for_museum['order_currency_partner_net_price'] = ($order_currency_total_amount * 100) / ($ticket_tax_value + 100);
                        $service_data_for_museum['isCommissionInPercent'] = "0";
                        $service_data_for_museum['tax_id'] = $ticket_tax_id;
                        $service_data_for_museum['tax_value'] = $ticket_tax_value;
                        $service_data_for_museum['invoice_status'] = "0";
                        $service_data_for_museum['row_type'] = "2";
                        $service_data_for_museum['paymentMethodType'] = $hotel_info->paymentMethodType;
                        $service_data_for_museum['channel_type'] = $channel_type;
                        $service_data_for_museum['service_name'] = SERVICE_NAME;
                        $service_data_for_museum['transaction_type_name'] = "Extra service cost";
                        $service_data_for_museum['is_prepaid'] = "1";
                        $service_data_for_museum['used'] = "0";
                        $service_data_for_museum['hotel_id'] = $hotel_id;
                        $service_data_for_museum['hotel_name'] = $hotel_name;
                        $service_data_for_museum['channel_id'] = $channel_id;
                        $service_data_for_museum['channel_name'] = $channel_name;
                        $service_data_for_museum['shift_id'] = $shift_id;
                        $service_data_for_museum['pos_point_id'] = $pos_point_id;
                        $service_data_for_museum['pos_point_name'] = $pos_point_name;
                        $service_data_for_museum['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                        $service_data_for_museum['cashier_id'] = $cashier_id;
                        $service_data_for_museum['cashier_name'] = $cashier_name;
                        $service_data_for_museum['reseller_id'] = $hotel_info->reseller_id;
                        $service_data_for_museum['reseller_name'] = $hotel_info->reseller_name;
                        $service_data_for_museum['saledesk_id'] = $hotel_info->saledesk_id;
                        $service_data_for_museum['saledesk_name'] = $hotel_info->saledesk_name;
                        $service_data_for_museum['distributor_partner_id']   = $confirm_data['distributor_partner_id'];
                        $service_data_for_museum['distributor_partner_name'] = $confirm_data['distributor_partner_name'];
                        $service_data_for_museum['is_custom_setting'] = $custom_settings_detail['is_custom_setting'];
                        $service_data_for_museum['external_product_id'] = $custom_settings_detail['external_product_id'];
                        $service_data_for_museum['account_number'] = $custom_ticket_settings_details['account_number'];
                        $service_data_for_museum['chart_number'] = $custom_ticket_settings_details['chart_number'];
                        $service_data_for_museum['timezone'] = $timezone;
                        $service_data_for_museum['updated_at'] = $service['created'];


                        $service_data_for_museum['supplier_gross_price'] = $total_amount;
                        $service_data_for_museum['supplier_ticket_amt'] = $total_amount;
                        $service_data_for_museum['supplier_tax_value'] = $ticket_tax_value;
                        $service_data_for_museum['supplier_net_price'] = ($total_amount * 100) / ($ticket_tax_value + 100);


                        $service_data_for_museum['supplier_currency_code'] = isset($confirm_data['supplier_currency_code']) ? $confirm_data['supplier_currency_code'] : '';
                        $service_data_for_museum['supplier_currency_symbol'] = isset($confirm_data['supplier_currency_symbol']) ? $confirm_data['supplier_currency_symbol'] : '';
                        $service_data_for_museum['order_currency_code'] = isset($confirm_data['order_currency_code']) ? $confirm_data['order_currency_code'] : '';
                        $service_data_for_museum['order_currency_symbol'] = isset($confirm_data['order_currency_symbol']) ? $confirm_data['order_currency_symbol'] : '';

                        $service_data_for_museum['tp_payment_method'] = $prepaid_tickets_data['tp_payment_method'];
                        $service_data_for_museum['payment_date'] = $prepaid_tickets_data['payment_date'];
                        $service_data_for_museum['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];

                        $service_data_for_museum['currency_rate'] = isset($confirm_data['currency_rate']) ? $confirm_data['currency_rate'] : '1';
                        $service_data_for_museum['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
                        $service_data_for_museum['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date']) + ($timezone * 3600));
                        $service_data_for_museum['partner_category_id'] = $prepaid_tickets_data['partner_category_id'];
                        $service_data_for_museum['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                        $service_data_for_museum['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                        $service_data_for_museum['merchant_admin_id'] = $extra_option_merchant_data[$ticket_id];
                        $extra_service_visitors_data[] = $service_data_for_museum;
                    }

                }
            }
            $final_visitor_data = array_merge($final_visitor_data, $extra_service_visitors_data);
        }
        
        if (($is_discount_code == '1' && $booking_status == '1') || !empty($discount_codes_details)) {
            $discount_visitors_data = array();
            //discount code transaction id in case of reprcing
            $dis_count= $visitor_group_no . 900;
            $discount_flag = 0;
            foreach ($discount_codes_details as $discount_codes_detail) {
                if( isset( $discount_codes_detail['is_reprice'] ) && $discount_codes_detail['is_reprice'] === 1  && isset($discount_codes_detail['max_discount_code']) && $discount_flag == 0) {
                    $dis_count = $discount_codes_detail['max_discount_code'] + 1;
                    $discount_flag = 1;
                } 
                $dis_count = $dis_count + 1;
                $discount_code = $discount_codes_detail["promocode"];
                $discount = $discount_codes_detail["discount_amount"];              
                // subtrtact discount code from used_limit
                $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['used_limit']  -= $discount;
                $ticket_tax_id = isset($discount_codes_detail['tax_id']) ? $discount_codes_detail['tax_id'] : 0;
                $ticket_tax_value = isset($discount_codes_detail['tax_value']) ? $discount_codes_detail['tax_value'] : 0.00;
                
                $rePrice = false;
                if( isset( $discount_codes_detail['is_reprice'] ) && $discount_codes_detail['is_reprice'] === 1 ) {
                    $rePrice = true;
                }
                if($discount_codes_detail['is_reprice'] === 1) {
                    if ($discount_codes_detail['surcharge_type']  == 1) {
                        $transaction_type_name =  'Reprice Surcharge';
                    } else {
                        $transaction_type_name =  'Reprice Discount';
                    }
                }

                
                $current_date = date('Y-m-d H:i:s', $createdOn);
                $visit_date_time = date('Y-m-d H:i:s', $createdOn);
                $transaction_id = $dis_count;
                $visitor_ticket_id = $transaction_id . '01';
                $insert_discount_code_data['id'] = $visitor_ticket_id;
                $insert_discount_code_data['created_date'] = $current_date;
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
                $insert_discount_code_data['channel_type'] = $channel_type;
                $insert_discount_code_data['pass_type'] = '';
                $insert_discount_code_data['visit_date'] = '';
                $insert_discount_code_data['visit_date_time'] = '';
                $insert_discount_code_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                $insert_discount_code_data['version'] = $prepaid_tickets_data['version'];
                $insert_discount_code_data['payment_date'] = $prepaid_tickets_data['payment_date'];
                $insert_discount_code_data['timezone'] = $timezone;
                $insert_discount_code_data['paid'] = "1";
                $insert_discount_code_data['payment_method'] = $discount_code;
                $insert_discount_code_data['all_ticket_ids'] = '';
                $insert_discount_code_data['captured'] = "1";
                $insert_discount_code_data['is_prepaid'] = "1";
                $insert_discount_code_data['debitor'] = 'Guest';
                $insert_discount_code_data['creditor'] = 'Credit';
                $insert_discount_code_data['partner_category_id'] = $prepaid_tickets_data['partner_category_id'];
                $insert_discount_code_data['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                $insert_discount_code_data['tax_name'] = 'BTW';
                $insert_discount_code_data['partner_gross_price'] = $discount; // Discount amount for adult
                $insert_discount_code_data['total_gross_commission'] = $discount; // Discount amount for adult
                $insert_discount_code_data['ticketAmt'] = $discount; // Discount amount for adult
                $discount_net = ($discount * 100) / ($ticket_tax_value + 100);
                $discount_net = round($discount_net, 2);
                $insert_discount_code_data['partner_net_price'] = $discount_net;
                $insert_discount_code_data['tax_id'] = $ticket_tax_id;
                $insert_discount_code_data['tax_value'] = $ticket_tax_value;
                if( $rePrice === true && !empty( $taxesForDiscount['1'] ) ) {
                    $insert_discount_code_data['targetlocation'] = $discount_codes_detail['clustering_id'];
                    $insert_discount_code_data['selected_date'] = $discount_codes_detail['selected_date'];
                    $insert_discount_code_data['slot_type'] = $discount_codes_detail['slot_type'];
                    $insert_discount_code_data['from_time'] = $discount_codes_detail['from_time'];
                    $insert_discount_code_data['to_time'] = $discount_codes_detail['to_time'];
                    $insert_discount_code_data['ticketId'] = $discount_codes_detail['ticket_id'];
                    $insert_discount_code_data['ticketpriceschedule_id'] = $discount_codes_detail['tps_id'];
                    $insert_discount_code_data['tax_id'] = $taxesForDiscount['1']['tax_id'];
                    $insert_discount_code_data['tax_name'] = $taxesForDiscount['1']['tax_name'];
                    $insert_discount_code_data['tax_value'] = $taxesForDiscount['1']['tax_value'];
                    if( $insert_discount_code_data['tax_value'] > 0 ) {
                        
                        $insert_discount_code_data['partner_net_price'] = $partner_net_price = round(($insert_discount_code_data['partner_net_price'] * 100) / ($taxesForDiscount['1']['tax_value'] + 100),2);
                        $insert_discount_code_data['partner_gross_price'] = $partner_gross_price = round($insert_discount_code_data['partner_gross_price'],2);
                    }
                }

                
                $insert_discount_code_data['invoice_status'] = "0";
                $insert_discount_code_data['transaction_type_name'] = ( $rePrice === true? $transaction_type_name: "Discount" );
                $insert_discount_code_data['visitor_invoice_id'] = ( $rePrice === true? $discount_codes_detail['prepaid_ticket_id']: "" );
                $insert_discount_code_data['paymentMethodType'] = 1;
                $insert_discount_code_data['row_type'] = "1";
                $insert_discount_code_data['isBillToHotel'] = '0';
                $insert_discount_code_data['activation_method'] = $activation_method;
                $insert_discount_code_data['service_cost_type'] =  ( $rePrice === true?  "0" : "1");
                $insert_discount_code_data['used'] = '0';
                $insert_discount_code_data['reseller_id'] = $hotel_info->reseller_id;
                $insert_discount_code_data['reseller_name'] = $hotel_info->reseller_name;
                $insert_discount_code_data['saledesk_id'] = $hotel_info->saledesk_id;
                $insert_discount_code_data['saledesk_name'] = $hotel_info->saledesk_name;
                $insert_discount_code_data['action_performed'] = '0';
                $insert_discount_code_data['updated_at'] = $current_date;
                $insert_discount_code_data['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
                $insert_discount_code_data['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date']) + ($timezone * 3600));
                $discount_visitors_data[] = $insert_discount_code_data;

                $visitor_ticket_id = $transaction_id . '02';
                $insert_discount_code_data['id'] = $visitor_ticket_id;
                $insert_discount_code_data['partner_name'] = 0;
                $insert_discount_code_data['partner_id'] = 0;
                $insert_discount_code_data['hotel_name'] = $hotel_name;
                $insert_discount_code_data['hotel_id'] = $hotel_id;
                $insert_discount_code_data['partner_gross_price'] = 0;
                $insert_discount_code_data['partner_net_price'] = 0;
                $insert_discount_code_data['transaction_type_name'] = ( $rePrice === true? $transaction_type_name: "Discount Cost" );
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
                $insert_discount_code_data['partner_net_price'] =  $rePrice === true ? 0.00 : $discount_net;
                $insert_discount_code_data['partner_gross_price'] = $rePrice === true ? 0.00 : $discount;
                $insert_discount_code_data['transaction_type_name'] = ( $rePrice === true? $transaction_type_name: "Distributor fee" );
                $insert_discount_code_data['debitor'] = $hotel_name;
                $insert_discount_code_data['creditor'] = 'Debit';
                $insert_discount_code_data['row_type'] = "3";
                if( $rePrice === true && $discount_codes_detail['user_type'] == 'distributor' ) {
                    if( !empty( $taxesForDiscount['3']['tax_id'] ) && $taxesForDiscount['3']['tax_value'] > 0 ) {
                        $insert_discount_code_data['tax_id'] = $taxesForDiscount['3']['tax_id'];
                        $insert_discount_code_data['tax_name'] = $taxesForDiscount['3']['tax_name'];
                        $insert_discount_code_data['tax_value'] = $taxesForDiscount['3']['tax_value'];
                    }
                    $insert_discount_code_data['partner_net_price'] = $partner_net_price;
                    $insert_discount_code_data['partner_gross_price'] = $partner_gross_price;
                }
                $discount_visitors_data[] = $insert_discount_code_data;

                $visitor_ticket_id = $transaction_id . '04';
                $insert_discount_code_data['id'] = $visitor_ticket_id;
                $insert_discount_code_data['partner_name'] = $ticket_details[$ticket_id]->hgs_provider_name;
                $insert_discount_code_data['partner_id'] = $ticket_details[$ticket_id]->hgs_provider_id;
                $insert_discount_code_data['hotel_name'] = $hotel_name;
                $insert_discount_code_data['hotel_id'] = $hotel_id;
                $insert_discount_code_data['partner_net_price'] =  $rePrice === true ? 0.00 : $discount_net;
                $insert_discount_code_data['partner_gross_price'] = $rePrice === true ? 0.00 : $discount;
                $insert_discount_code_data['transaction_type_name'] = ( $rePrice === true? $transaction_type_name: "Provider Cost" );
                $insert_discount_code_data['debitor'] = 'Hotel guest service';
                $insert_discount_code_data['creditor'] = 'Debit';
                $insert_discount_code_data['row_type'] = "4";
                $insert_discount_code_data['updated_at'] = $current_date;
                if( $rePrice === true && $discount_codes_detail['user_type'] == 'reseller' ) {
                    
                    if( !empty( $taxesForDiscount['4']['tax_id'] ) && $taxesForDiscount['4']['tax_value'] > 0 ) {
                        
                        $insert_discount_code_data['tax_id'] = $taxesForDiscount['4']['tax_id'];
                        $insert_discount_code_data['tax_name'] = $taxesForDiscount['4']['tax_name'];
                        $insert_discount_code_data['tax_value'] = $taxesForDiscount['4']['tax_value'];
                    }
                    $insert_discount_code_data['partner_net_price'] = $partner_net_price;
                    $insert_discount_code_data['partner_gross_price'] = $partner_gross_price;
                } else {
                    $insert_discount_code_data['partner_net_price'] = 0.00;
                    $insert_discount_code_data['partner_gross_price'] = 0.00;
                }
                $discount_visitors_data[] = $insert_discount_code_data;
                
                if( $rePrice === true ) {
                    
                    $visitor_ticket_id = $transaction_id . '17';
                    $insert_discount_code_data['id'] = $visitor_ticket_id;
                    $insert_discount_code_data['partner_name'] = 0;
                    $insert_discount_code_data['partner_id'] = 0;
                    $insert_discount_code_data['hotel_name'] = $hotel_name;
                    $insert_discount_code_data['hotel_id'] = $hotel_id;
                    $insert_discount_code_data['partner_gross_price'] = 0;
                    $insert_discount_code_data['partner_net_price'] = 0;
                    $insert_discount_code_data['transaction_type_name'] = $transaction_type_name;
                    $insert_discount_code_data['debitor'] = '';
                    $insert_discount_code_data['creditor'] = 'Debit';
                    $insert_discount_code_data['row_type'] = "17";
                    $insert_discount_code_data['updated_at'] = $current_date;
                    $discount_visitors_data[] = $insert_discount_code_data;
                }
            }            
            
            $final_visitor_data = array_merge($final_visitor_data, $discount_visitors_data);
        }

        if ($service_cost > 0 && $booking_status == '1') { // To save service cost row in case of service cost per transaction
            // Add Service cost per transaction in used_limit
            $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['used_limit']  += $service_cost;
            $tax = $this->common_model->getSingleFieldValueFromTable('tax_value', 'store_taxes', array('id' => $hotel_info->service_cost_tax));
            $service_visitors_data = array();
            $net_service_cost = ($service_cost * 100) / ($tax + 100);
            $transaction_id = $visitor_ids_array[count($visitor_ids_array) - 1];
            $visitor_ticket_id = substr($transaction_id, 0, -2) . '12';
            $insert_service_data['id'] = $visitor_ticket_id;
            $insert_service_data['is_prioticket'] = $is_prioticket;
            $insert_service_data['created_date'] = gmdate('Y-m-d H:i:s', $created_date);
            $insert_service_data['updated_at'] = gmdate('Y-m-d H:i:s', $created_date);           
            $insert_service_data['selected_date'] = $selected_date;
            $insert_service_data['from_time'] = $from_time;
            $insert_service_data['to_time'] = $to_time;
            $insert_service_data['slot_type'] = $slot_type;            
            $insert_service_data['transaction_id'] = $transaction_id;
            $insert_service_data['visitor_group_no'] = $visitor_group_no;
            $insert_service_data['vt_group_no'] = $visitor_group_no;
            $insert_service_data['ticket_booking_id'] = $ticket_booking_id;
            $insert_service_data['ticketAmt'] = $service_cost;
            $insert_service_data['invoice_id'] = '';
            $insert_service_data['ticketId'] = '0';
            $insert_service_data['ticketpriceschedule_id'] = '0';
            $insert_service_data['tickettype_name'] = '';
            $insert_service_data['ticket_title'] = "Service cost fee for transaction " . implode(",", $visitor_ids_array);
            $insert_service_data['partner_id'] = $hotel_info->cod_id;
            $insert_service_data['hotel_id'] = $hotel_id;
            $insert_service_data['shift_id'] = $shift_id;
            $insert_service_data['pos_point_id'] = $pos_point_id;
            $insert_service_data['pos_point_name'] = $pos_point_name;
            $insert_service_data['partner_name'] = $hotel_name;
            $insert_service_data['hotel_name'] = $hotel_name;
            $insert_service_data['visit_date'] = $created_date;
            $insert_service_data['version'] = $prepaid_tickets_data['version'];
            $insert_service_data['visit_date_time'] = gmdate('Y-m-d H:i:s', $created_date);
            $today_date =  strtotime($insert_service_data['visit_date_time']) + ($timezone * 3600);
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
            $insert_service_data['isBillToHotel'] = $isBillToHotel;
            $insert_service_data['activation_method'] = $activation_method;
            $insert_service_data['service_cost_type'] = "2";
            $insert_service_data['used'] = "0";
            $insert_service_data['timezone'] = $timezone;
            $insert_service_data['booking_status'] = $booking_status;
            $insert_service_data['channel_id'] = $channel_id;
            $insert_service_data['channel_name'] = $channel_name;
            $insert_service_data['cashier_id'] = $cashier_id;
            $insert_service_data['cashier_name'] = $cashier_name;
            $insert_service_data['reseller_id'] = $hotel_info->reseller_id;
            $insert_service_data['reseller_name'] = $hotel_info->reseller_name;
            $insert_service_data['action_performed'] = '0';
            $insert_service_data['updated_at'] = gmdate('Y-m-d H:i:s', $created_date);
            $insert_service_data['saledesk_id'] = $hotel_info->saledesk_id;
            $insert_service_data['saledesk_name'] = $hotel_info->saledesk_name;
            $insert_service_data['distributor_partner_id']   = $confirm_data['distributor_partner_id'];
            $insert_service_data['distributor_partner_name'] = $confirm_data['distributor_partner_name'];
            $insert_service_data['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
            $insert_service_data['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date'])+ ($timezone * 3600));
            $insert_service_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
            $insert_service_data['partner_category_id'] = $prepaid_tickets_data['partner_category_id'];
            $insert_service_data['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
            $insert_service_data['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
            $service_visitors_data[] = $insert_service_data;
            $final_visitor_data = array_merge($final_visitor_data, $service_visitors_data);
        }

        $flag = 0;
        /* To Batch insert in Main Tables */
        if($insert_in_db == 1) {
            if (INSERT_BATCH_ON) {

                if (!empty($final_visitor_data)) {
                    $flag = $this->insert_batch($vt_table, $final_visitor_data, $is_secondary_db);
                }
            } else {
                if (!empty($final_visitor_data)) {
                    $this->insert_without_batch($vt_table, $final_visitor_data, $is_secondary_db);
                    $flag = 1;
                }
            }
        }
        $final_visitor_data_to_insert_big_query_transaction_specific = array();
        foreach ($final_visitor_data as $final_visitor_data_row) {
            $final_visitor_data_to_insert_big_query_transaction_specific[$final_visitor_data_row["transaction_id"]][] = $final_visitor_data_row;
        }
         
        
                         
        if (!empty($cluster_tickets_transaction_id_array)) {
            foreach ($cluster_tickets_transaction_id_array as $main_key => $cluster_ticket_transaction_id_data) {
                $explose_main_key = explode("::", $main_key);
                for ($i = 0; $i < count($transaction_id_array[$explose_main_key[1]][$explose_main_key[2]]); $i++) {
                    foreach($cluster_ticket_transaction_id_data as $cluster_transaction_id) {                  
                        $this->secondarydb->db->update('visitor_tickets', array('transaction_id' => $transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i], 'ticketAmt' => $transaction_id_wise_ticketAmt[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'discount' => $transaction_id_wise_discount[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'targetlocation' => $transaction_id_wise_clustering_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'targetcity' => $clustering_id_wise_ticket_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]]), array('transaction_id' => $cluster_transaction_id));
                        if (!empty($final_visitor_data_to_insert_big_query_transaction_specific[$cluster_ticket_transaction_id_data[$i]])) {
                            foreach ($final_visitor_data_to_insert_big_query_transaction_specific[$cluster_ticket_transaction_id_data[$i]] as $final_visitor_data_row_to_insert_big_query_key => $final_visitor_data_row_to_insert_big_query) {
                                $final_visitor_data_row_to_insert_big_query_updated = $final_visitor_data_row_to_insert_big_query;
                                $final_visitor_data_row_to_insert_big_query_updated["transaction_id"] = $transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i];
                                $final_visitor_data_row_to_insert_big_query_updated["ticketAmt"] = $transaction_id_wise_ticketAmt[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]];
                                $final_visitor_data_row_to_insert_big_query_updated["discount"] = $transaction_id_wise_discount[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]];
                                $final_visitor_data_row_to_insert_big_query_updated["targetlocation"] = $transaction_id_wise_clustering_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]];
                                $final_visitor_data_row_to_insert_big_query_updated["targetcity"] = $clustering_id_wise_ticket_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]];
                               
                               $final_visitor_data_to_insert_big_query_transaction_specific[$cluster_ticket_transaction_id_data[$i]][$final_visitor_data_row_to_insert_big_query_key] = $final_visitor_data_row_to_insert_big_query_updated;
                            }
                        }
                        
                        if(SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0') && $insert_in_db == 1) {
                            $this->fourthdb->db->update('visitor_tickets', array('transaction_id' => $transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i], 'ticketAmt' => $transaction_id_wise_ticketAmt[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'discount' => $transaction_id_wise_discount[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'targetlocation' => $transaction_id_wise_clustering_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]], 'targetcity' => $clustering_id_wise_ticket_id[$transaction_id_array[$explose_main_key[1]][$explose_main_key[2]][$i]]), array('transaction_id' => $cluster_transaction_id));
                        }
                    }
                }
            }
        }
        
        // To enter the CC Cost rows in visitor_tickets DB
        if (isset($activation_method) && $activation_method == "1" && $cc_rows_already_inserted == 1 && $is_prepaid == "1" && $is_prioticket == "1") {
            $this->order_process_vt_model->captureThePendingPaymentsv1('', $pspReference, 0, $hotel_id, $visitor_group_no, '0', $is_secondary_db);
        }

        /* Check pending request from firebase and process in queue for sending email and cancel order */
        $this->find('firebase_pending_request', array('select' => '*', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and request_type != "scaning_actions"'));       
        if(isset($data['add_new_rows']) && $data['add_new_rows'] == '1' && $visitor_group_no != '') {
            $this->secondarydb->db->query('update visitor_tickets set action_performed = CONCAT(action_performed, ", CPOS_EOL") where vt_group_no = "'.$visitor_group_no.'"');
        }        
        $result_array = array();
        $result_array['sns_message'] = $sns_message;
        $result_array['final_visitor_data_to_insert_big_query_transaction_specific'] = $final_visitor_data_to_insert_big_query_transaction_specific;
        $result_array['final_prepaid_data_to_insert_big_query'] = $final_prepaid_data;
        $result_array['flag'] = $flag;
        /* Notifying through api3.3 */
        return $result_array;
    }

    /* #endregion */
}
?>