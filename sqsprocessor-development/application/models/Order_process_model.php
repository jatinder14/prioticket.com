<?php
class Order_process_model extends MY_Model {
    
    /* #region for construct */
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
        $this->load->model('transaction_fee_model');
        $this->load->model('sendemail_model');
        $this->base_url = $this->config->config['base_url'];
        $this->mpos_api_server = $this->config->config['mpos_api_server'];
        $this->merchant_price_col = 'merchant_price';
        $this->merchant_net_price_col = 'merchant_net_price';
        $this->merchant_tax_id_col = 'merchant_tax_id';
        $this->merchant_currency_code_col = 'merchant_currency_code';
        $this->supplier_tax_id_col = 'supplier_tax_id';
        $this->admin_currency_code_col = 'admin_currency_code';
        $this->ADAM_TOWER_SUPPLIER_ID = "";
    }
    /* #endregion */

    /* #region function To insert data of main tables in secondary DB. */
    /**
     * insertdata
     *
     * @param  mixed $data
     * @param  mixed $is_deleted_order
     * @param  mixed $is_secondary_db
     * @param  mixed $insert_vt_by_mpos
     * @return void
     * @author Davinder singh<davinder.intersoft@gmail.com> onNovemver, 2016
     */
    function insertdata($data, $is_deleted_order = 0, $is_secondary_db = 1, $insert_vt_by_mpos = 0) 
    {
        $mpos_order_id = $data['hotel_ticket_overview_data'][0]['visitor_group_no'];
        $channel_type =  $data['hotel_ticket_overview_data'][0]['channel_type'];
        $this->CreateLog('add_log.php', 'step-1', array('data'=> json_encode($data))); 
        global $MPOS_LOGS;
        $this->CreateLog('Mpos_orders_logs.php','data_step1_inside_insertdate_'.date("H:i:s.u"), array("order" => $mpos_order_id), $channel_type); 
        /* initializing params for preparing request and notifying to ARENA_PRXY_LISTENER and ADAM_TOWER*/
        $arena_flag =  $adam_tower_flag = $notify_api_flag = 0;
        $adam_tower_array = $arena_array = $notify_api_array = [];
        $update_credit_limit_data = array();
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_message = array();
        //Firebase Updations
        $sync_all_tickets = array();
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } 
        else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } 
        else {
            $db = $this->db;
        }
        $upsell_order = 0;
        $upsell_third_party_order = 0;
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
        $merchantAccountCode = '';

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
        $final_array_of_api = array();

        // array of activation methods
        $allowed_activation_methods     =   array(14,15,16,17,18);
        if (!empty($is_secondary_db) && $is_secondary_db == '1' && !empty($data['prepaid_tickets_data'])) {
            /* Start save order_flags block. */
            $flag_entities = $this->set_entity($data);
            $flag_details = $this->get_flags_details($flag_entities);
            if (!empty($flag_details)) {
                $this->set_order_flags($flag_details, $data['prepaid_tickets_data'][0]['visitor_group_no']);
            }
            /* End save order_flags block. */
        }
        // If array contain data of hotel_ticket_overview an then insert in hotel_ticket_overview table.
        if (isset($data['hotel_ticket_overview_data']) && !empty($data['hotel_ticket_overview_data'])) {
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
                $merchantAccountCode = $hotel_data['merchantAccountCode'];
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

                if(isset($mainharray['last_modified_at'])) {
                    unset($mainharray['last_modified_at']);
                }
                if (INSERT_BATCH_ON == 0) {
                    $db->insert("hotel_ticket_overview", $mainharray);
                }
                $final_hto_data[] = $mainharray;
                $insertedId = $hotel_data['id'];
                $hto_ids[$hotel_data['passNo']] = $insertedId;
                $arrpass[] = $hotel_data['passNo'];

                if ($hotel_id == 0) {
                    $details['hotel_ticket_overview'] = (object) $hotel_data;
                    $visitor_group_no = $hotel_data['visitor_group_no'];
                    $hotel_id = $hotel_data['hotel_id'];
                    $hotel_name = $hotel_data['hotel_name'];
                    $room_no = $hotel_data['roomNo'] != 'Prio' ? $hotel_data['roomNo'] : '';
                    $guest_names = $hotel_data['guest_names'] != '' ? $hotel_data['guest_names'] : '';
                    $guest_email = $hotel_data['guest_emails'] != '' ? $hotel_data['guest_emails'] : '';
                    $channel_type = isset($hotel_data['channel_type']) ? $hotel_data['channel_type'] : 0;
                    $phone_number = isset($hotel_data['phone_number']) ? $hotel_data['phone_number'] : '';
                    $activation_method = $hotel_data['activation_method'];
                    $is_prioticket = $hotel_data['is_prioticket'];
                    $isBillToHotel = $hotel_data['isBillToHotel'];
                    $timezone = $hotel_data['timezone'];
                    $createdOn = $hotel_data['createdOn'];
                }
            }
            $this->CreateLog('Mpos_orders_logs.php','data_step2_before_hto_insertion_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);   
            if (INSERT_BATCH_ON && !empty($final_hto_data)) {
                $this->insert_batch('hotel_ticket_overview', $final_hto_data, $is_secondary_db);
            }
            $this->CreateLog('Mpos_orders_logs.php','data_step2_after_hto_insertion_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            /* #endregion to insert data in  HTO table */
        } else {
            // If hotel_ticket_overview_data array empty then insert data in prepaid_ticket table.
            if (isset($data['prepaid_tickets_data']) && !empty($data['prepaid_tickets_data'])) {
                $hotel_data = $this->secondarydb->rodb->get_where("hotel_ticket_overview", array("visitor_group_no" => $data['prepaid_tickets_data'][0]['visitor_group_no']))->row_array();
                $details['hotel_ticket_overview'] = (object) $hotel_data;
                $visitor_group_no = $hotel_data['visitor_group_no'];
                /* #handle check to process multiple queue for combi ticket bulk order. */
                if (empty($visitor_group_no) && !empty($data['visitor_group_no']) && !empty($data['combi_multiple_queue_process_v3x'])) {
                    $visitor_group_no = $data['visitor_group_no'];
                }
                $hotel_id = $hotel_data['hotel_id'];
                $hotel_name = $hotel_data['hotel_name'];
                $room_no = $hotel_data['roomNo'] != 'Prio' ? $hotel_data['roomNo'] : '';
                $guest_names = $hotel_data['guest_names'] != '' ? $hotel_data['guest_names'] : '';
                $guest_email = $hotel_data['guest_emails'] != '' ? $hotel_data['guest_emails'] : '';
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
        $mail_content = array();
        $visitor_ids_array = array();
        $split_payment = array();
        $extra_service_options_for_email = array();
        $shop_products_array_for_rds = array();
        $hotel_info = $this->common_model->companyName($hotel_id); // Hotel Information
        $this->CreateLog('Mpos_orders_logs.php','data_step_after_getting_hotel_info_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        $this->CreateLog('add_log.php', 'step-2', array('data'=> json_encode($data))); 
        $country_code = $hotel_info->country_code;
        $mrkt_merchant_id = $hotel_info->market_merchant_id;
        $sub_catalog_id = $hotel_info->sub_catalog_id;
        $this->load->helper('common');
        $this->CreateLog('Mpos_orders_logs.php','data_step_before_getting_hotel_info_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        $get_tax_value = get_tax_value($hotel_id,$country_code);
        $this->CreateLog('Mpos_orders_logs.php','data_step_after_getting_tax_value'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        $today_date =  strtotime("now") + ($timeZone * 3600);
        $paymentMethod = isset($hotel_data['paymentMethod']) ? $hotel_data['paymentMethod'] : '';
        $pspReference = isset($hotel_data['pspReference']) ? $hotel_data['pspReference'] : '';

        if ($paymentMethod == '' && $pspReference == '') {
            $payment_method = trim($hotel_info->company); // 0 = Bill to hotel
            $invoice_status = '6';
        } else {
            $payment_method = 'Others'; //   others
            $invoice_status = '0';
        }

        $is_discount_code = $ticket_reseller_id = 0;
        $discount_codes_details = array();
        $is_discount_code_prepaid_id = 0;       
        
        $cc_rows_already_inserted = 0;
        
        $bookings_listing['hotel_id'] = $hotel_data['hotel_id'];
        $bookings_listing['is_bill_to_hotel'] = $hotel_data['isBillToHotel'];
        $bookings_listing['room_no'] = $hotel_data['roomNo'];
        $bookings_listing['guest_names'] = $hotel_data['guest_names'];
        $bookings_listing['client_reference'] = $hotel_data['client_reference'];
        $bookings_listing['merchant_reference'] = $hotel_data['merchantReference'];

        $this->CreateLog('Mpos_orders_logs.php','data_step_before_shop_products_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        // If array contain data of shop_products then insert in visitor_tickets table.
        if (isset($data['shop_products_data']) && !empty($data['shop_products_data'])) {

            $this->CreateLog('Mpos_orders_logs.php','data_step_inside_shop_products_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            /* #region  to insert shop products in PT and VT Table */
            if (empty($data['prepaid_tickets_data'])) {
                $details['prepaid_tickets'] = (object) $data['shop_products_data'];
            } else {
                $details['prepaid_tickets'] = $data['shop_products_data'];
            }
            $final_prepaid_shop = array();
            foreach ($data['shop_products_data'] as $shop_products_data) {
                $pos_point_id = $shop_products_data['pos_point_id'];
                $pos_point_name = $shop_products_data['pos_point_name'];
                $channel_id = $shop_products_data['channel_id'];
                $channel_name = $shop_products_data['channel_name'];
                $cashier_id = $shop_products_data['cashier_id'];
                $cashier_name = $shop_products_data['cashier_name'];
                $reseller_id = $shop_products_data['reseller_id'];
                $reseller_name = $shop_products_data['reseller_name'];
                $saledesk_id = $shop_products_data['saledesk_id'];
                $saledesk_name = $shop_products_data['saledesk_name'];
                if (empty($pos_point_id)) {
                    $pos_point_id = 0;
                }
                if (empty($pos_point_name)) {
                    $pos_point_name = '';
                }
                if (empty($channel_id)) {
                    $channel_id = 0;
                }
                if (empty($reseller_id)) {
                    $reseller_id = 0;
                }
                if (empty($reseller_name)) {
                    $reseller_name = '';
                }
                if (empty($channel_name)) {
                    $channel_name = '';
                }
                $shop_products_data['is_prioticket'] = '0';
                if(isset($shop_products_data['last_modified_at'])) {
                    unset($shop_products_data['last_modified_at']);
                }
                if (INSERT_BATCH_ON == 0) {
                    $db->insert("prepaid_tickets", $shop_products_data);
                }                
                $insertedId = $shop_products_data['prepaid_ticket_id'];
                if ($shop_products_data['activation_method'] == '1') {
                    $payment_method = 'credit card';
                } else {
                    $payment_method = 'cash';
                }
                if ($shop_products_data['activation_method'] == '10') {
                    $payment_method = 'voucher';
                }
                if ($shop_products_data['channel_type'] == '') {
                    $shop_products_data['channel_type'] = "3";
                }

                if (isset($data['split_payment']) && !empty($data['split_payment'])) {
                    $split_payment = $data['split_payment'];
                }
                // Prepare array to insert shop data in visitor_ticket table. Modified By: Taran on 21 Sept, 2016.
                $confirm_visitor_data = array(
                    'merchantAccountCode' => $merchantAccountCode,
                    'visitor_group_no' => $shop_products_data['visitor_group_no'],
                    'created_date' => $shop_products_data['created_at'],
                    'product_type' => $shop_products_data['product_type'],
                    'used' => $shop_products_data['used'],
                    'ticket_id' => $shop_products_data['ticket_id'],
                    'selected_date' => $shop_products_data['selected_date'],
                    'hotel_id' => $hotel_id,
                    'hotel_name' => $hotel_name,
                    'pos_point_id' => $pos_point_id,
                    'pos_point_name' => $pos_point_name,
                    'channel_id' => $channel_id,
                    'channel_name' => $channel_name,
                    'reseller_id' => $reseller_id,
                    'reseller_name' => $reseller_name,
                    'saledesk_id' => $saledesk_id,
                    'saledesk_name' => $saledesk_name,
                    'cashier_id' => $cashier_id,
                    'cashier_name' => $cashier_name,
                    'payment_method' => $payment_method,
                    'visit_date_time' => $shop_products_data['created_at'],
                    'order_confirm_date' => $shop_products_data['created_at'],
                    'payment_method' => $payment_method,
                    'price' => $shop_products_data['price'],
                    'manual_note' => $shop_products_data['manual_payment_note'],
                    'is_voucher' => $shop_products_data['is_voucher'],
                    'tax' => $shop_products_data['tax'],
                    'quantity' => $shop_products_data['quantity'],
                    'discount_applied_on_how_many_tickets' => $shop_products_data['discount_applied_on_how_many_tickets'],
                    'timezone' => $shop_products_data['timezone'],
                    'channel_type' => $shop_products_data['channel_type'],
                    'prepaid_ticket_id' => $insertedId,
                    'product_title' => $shop_products_data['title'],
                    'extra_discount' => $shop_products_data['extra_discount'],
                    'discount' => $shop_products_data['discount'],
                    'isDiscountInPercent' => $shop_products_data['is_discount_in_percent'],
                    'shop_category_name' => $shop_products_data['shop_category_name'],
                    'account_number' => $shop_products_data['account_number'],
                    'split_payment' => $split_payment,
                    'order_total_price' => (isset($hotel_data['total_price']) && $hotel_data['total_price'] != '') ? $hotel_data['total_price'] : '0.00',
                    'order_total_tickets' => (isset($hotel_data['quantity']) && $hotel_data['quantity'] != '') ? $hotel_data['total_price'] : '0',
                    'distributor_type' => (isset($hotel_data['distributor_type']) && $hotel_data['distributor_type'] != '') ? $hotel_data['distributor_type'] : '',
                    'ticket_ids' => (isset($hotel_data['ticket_ids']) && $hotel_data['ticket_ids'] != '') ? $hotel_data['ticket_ids'] : '',
                    'voucher_updated_by' => (isset($hotel_data['voucher_updated_by']) && $hotel_data['voucher_updated_by'] != '') ? $hotel_data['voucher_updated_by'] : '',
                    'country_tax_array' => $get_tax_value,
                );
                
                $visitor_tickets_data = array();
                $visitor_tickets_data_response = $this->order_process_vt_model->confirm_shop_products($confirm_visitor_data, $is_secondary_db); // return visitor ticket id.
                $visitor_tickets_data['id'] = $visitor_tickets_data_response['inserted_id'];
                $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data_response['final_visitors_data']);
                $inserted_count++;
                $shop_products_data['visitor_tickets_id'] = $visitor_tickets_data['id'];
                $final_prepaid_shop[] = $shop_products_data;      
                if (INSERT_BATCH_ON == 0) {
                    //$db->update('prepaid_tickets', array('visitor_tickets_id' => $visitor_tickets_data['id']), array('prepaid_ticket_id' => $insertedId));
                }
                //$sns_message[] = 'update prepaid_tickets set visitor_tickets_id = "' . $visitor_tickets_data['id'] . '" where prepaid_ticket_id = "' . $insertedId . '"';
                $visitor_ids_array[] = $visitor_tickets_data['id'];
            }
            $final_prepaid_data = array_merge($final_prepaid_data, $final_prepaid_shop);
        }
        $this->CreateLog('Mpos_orders_logs.php','data_step_after_shop_products_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        $booking_status = 0;
         /** This condition is for API 3.2, when we use settlement type VENUE this will work. */
        /*if($channel_type == 6 && $activation_method == 16) {
            $booking_status = 0;
        } */ 
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
        $this->CreateLog('add_log.php', 'step-3', array('data'=> json_encode($data))); 
        $mec_is_combi = 0;
        $bundle_main_product_id = [];
        /** Manage selected dates on the basis of visitor_group_no **/
        $selected_dates_array = array();
        $this->CreateLog('Mpos_orders_logs.php','data_step_before_prepaid_tickets_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
        // If array set with hotel_ticket_overview_data, shop_data and prepaid_tickets_data then insert data in prepaid_ticket table.
        if (isset($data['prepaid_tickets_data']) && !empty($data['prepaid_tickets_data'])) {
            $this->CreateLog('Mpos_orders_logs.php','data_step_inside_prepaid_tickets_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
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
            $pickup_locations_data = array();
            $total_quantity = 0;
            $cluster_net_price = array();
            $all_museum_id = array();
            $bundle_booking_ids = [];
            foreach ($data['prepaid_tickets_data'] as $prepaid_tickets_data) {
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_for_each_prepaid_tickets_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
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
                /** #Comment : need to Save Bundle Main ID in Visitor tickets */
                if ($prepaid_tickets_data['product_type'] == '8') {
                    $bundle_booking_ids[$prepaid_tickets_data['ticket_booking_id']] = $prepaid_tickets_data['related_product_id'];
                }
                $distributor_partner_id =  !empty($prepaid_tickets_data['distributor_partner_id']) ? $prepaid_tickets_data['distributor_partner_id'] : 0;
                $order_confirm_date     = $prepaid_tickets_data['order_confirm_date'];
                $order_date = strtotime(date('Y-m-d 00:00:00', strtotime($order_confirm_date))) + ($prepaid_tickets_data['timezone'] * 3600);
            }

            $this->CreateLog('Mpos_orders_logs.php','data_step_after_for_each_prepaid_tickets_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            /* #endregion to prepare common variables from PT data like cluster ticket ids */
            $this->CreateLog('Mpos_orders_logs.php','data_step_before_reltarget_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            if (!empty($rel_target_ids)) {
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_reltarget_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                $pickup_locations_data = $this->get_pickups_data($rel_target_ids);
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_reltarget_ids_if_after_function'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            }
            $this->CreateLog('Mpos_orders_logs.php','data_step_after_reltarget_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            if (!empty($main_ticket_ids)) {

                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_main_tickets_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                // get main ticket details from sub products
                $main_ticket_combi_data = $this->main_ticket_combi_data($main_ticket_ids);
            }
            $this->CreateLog('Mpos_orders_logs.php','data_step_after_main_tickets_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            if (!empty($cluster_tps_ids)) {
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_cluster_tps_ids_if_cluster_tps_ids_array'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id, "cluster_tps_ids" => json_encode($cluster_tps_ids) ), $channel_type); 
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_cluster_tps_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                $cluster_tickets_data = $this->cluster_tickets_detail_data($cluster_tps_ids);
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_cluster_tps_ids_if_cluster_tickets_data'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id, "cluster_tps_ids" => json_encode($cluster_tickets_data) ), $channel_type); 
            }
            $this->CreateLog('Mpos_orders_logs.php','data_step_after_cluster_tps_ids_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            if ((SYNCH_WITH_RDS_REALTIME) && $is_secondary_db == "4" && isset($data['is_amend_order_version_update'])) {
                $rds_db2_db = $this->fourthdb->db;
            } else {
                $rds_db2_db = $this->secondarydb->rodb;
            }
            /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
            if ($uncancel_order == 1) {
                $this->CreateLog('Mpos_orders_logs.php','data_step_inside_uncancel_order_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                $product_vt_cancelled = $product_cancelled = array();
                $pt_version_data = $rds_db2_db->select('version,ticket_booking_id, is_refunded')->from($prepaid_table)->where('visitor_group_no', $visitor_group_no)->order_by("last_modified_at", "ASC")->get()->result_array();
                $vt_versions = $pt_versions = array();
                if (!empty($pt_version_data)) {
                    foreach ($pt_version_data as $pt_version_detail) {
                        $pt_versions[] = $pt_version_detail['version'];
                        $ticket_pt_versions[$pt_version_detail['ticket_booking_id']][] = $pt_version_detail['version'];
                        $product_cancelled[$pt_version_detail['ticket_booking_id']][] = $pt_version_detail['is_refunded'];
                    }
                    $pt_version = max($pt_versions);
                }
                $this->CreateLog('Mpos_orders_logs.php','data_step_after_pt_version_data_if'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                $vt_version_data = $rds_db2_db->select('version, ticket_booking_id, is_refunded')->from($vt_table)->where('visitor_group_no', $visitor_group_no)->order_by("last_modified_at", "ASC")->get()->result_array();
                $this->CreateLog('Mpos_orders_logs.php','data_step_after_get_vt_version_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
                if (!empty($vt_version_data)) {
                    foreach ($vt_version_data as $vt_version_detail) {
                        $vt_versions[] = $vt_version_detail['version'];
                        $ticket_vt_versions[$vt_version_detail['ticket_booking_id']][] = $vt_version_detail['version'];
                        $product_vt_cancelled[$vt_version_detail['ticket_booking_id']][] = $vt_version_detail['is_refunded'];
                    }
                    $vt_version = max($vt_versions);
                }
            }
            // get transaction fee data related to order
            $total_order_amount = 0;
            $this->CreateLog('Mpos_orders_logs.php','data_step_before_reseller_channel_id_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            $reseller_channel_id = $this->common_model->getSingleFieldValueFromTable('channel_id', 'resellers', array('reseller_id' => $hotel_info->reseller_id));
            $this->CreateLog('Mpos_orders_logs.php','data_step_after_reseller_channel_id_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            /* get reseller sub catalog id */
            $ticket_reseller_subcatalog_id = $this->getResellerSubcatalogIds( $data['prepaid_tickets_data'] ,$hotel_info->reseller_id);
            $this->CreateLog('Mpos_orders_logs.php','data_step_after_ticket_reseller_subcatalog_id_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            
            $amend_ticket_booking_ids = array();
            $i =0;
            $amend_ticket_booking_ids = array();

            $this->CreateLog( 'debug_vt_entry_log.txt', 'step-1', array( 'prepaid_tickets_data'=> json_encode( $data['prepaid_tickets_data'] ) ) ); 

            /* #region to prepare PT table Data  */
            $this->CreateLog('Mpos_orders_logs.php','data_step_brfore_prepare_pt_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_major_loop_start'.date("H:i:s.u"), array("pt_data" => json_encode($data['prepaid_tickets_data']), "order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 
            $version_increment_block = 0;
            foreach ($data['prepaid_tickets_data'] as $prepaid_tickets_data) {
                $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_step_inside_pt_data_'.date("H:i:s.u"), array("db_pt_data" => json_encode($prepaid_tickets_data), "order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 
                $data['is_amend_vt_order_version_update'] = $data['is_amend_pt_vt_order_version_update'] = 0;
                /* PT, VT version handling for 3.3 distributor amend order api. */
                if ($uncancel_order == 1 && isset($data['is_amend_order_version_update'])) {
                   /* handle version case if cancel entries inserted late in amendment. */
                    if (isset($product_cancelled[$prepaid_tickets_data['ticket_booking_id']])) {
                        $last_entry_refund_status = end($product_cancelled[$prepaid_tickets_data['ticket_booking_id']]);
                        if ($last_entry_refund_status == 0) {
                            $data['is_amend_pt_vt_order_version_update'] = 1;
                        }
                    }
                    if (isset($product_vt_cancelled[$prepaid_tickets_data['ticket_booking_id']])) {
                        $last_entry_refund_status = end($product_vt_cancelled[$prepaid_tickets_data['ticket_booking_id']]);
                        if ($last_entry_refund_status == 0) {
                            $data['is_amend_vt_order_version_update'] = 1;
                        }
                    }
                    if (!in_array($prepaid_tickets_data['ticket_booking_id'], $amend_ticket_booking_ids)) {
                        if (isset($ticket_pt_versions[$prepaid_tickets_data['ticket_booking_id']])) {
                            $pt_version = max($ticket_pt_versions[$prepaid_tickets_data['ticket_booking_id']]);
                        }
                        if (isset($ticket_vt_versions[$prepaid_tickets_data['ticket_booking_id']])) {
                            $vt_version = max($ticket_vt_versions[$prepaid_tickets_data['ticket_booking_id']]);
                        } 
                        if (($data['is_amend_order_version_update'] == 1 && ($version_increment_block == 0 || isset($ticket_pt_versions[$prepaid_tickets_data['ticket_booking_id']])))|| $data['is_amend_pt_vt_order_version_update'] == 1) {
                            /* #Comment: Handle to increase version only once for new bookings.*/
                            $version_increment_block = 1;
                            $this->CreateLog('check_version_issue.php', '_before_channel_check'.date("H:i:s.u"), array("pt_version_single" => $pt_version, "pt_version" => json_encode($pt_version)), $channel_type);

                            $pt_version = $pt_version + 1;
                            $vt_version = $vt_version + 1;
                            $this->CreateLog('check_version_issue.php', '_after_channel_check'.date("H:i:s.u"), array("pt_version_single" => $pt_version, "pt_version" => json_encode($pt_version)), $channel_type);

                        } else if ($data['is_amend_vt_order_version_update'] == 1) {
                            $vt_version = $vt_version + 1;
                        }
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
                if(strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                    $pt_hotel_id = $prepaid_tickets_data['hotel_id'];
                } else {
                    $pt_hotel_id = $hotel_id;
                }
                if (isset($prepaid_tickets_data['activation_method']) && ($prepaid_tickets_data['activation_method'] != '' || isset($data['is_amend_order_version_update']))) {
                    $activation_method = $prepaid_tickets_data['activation_method'];
                }
                if(strstr($prepaid_tickets_data['action_performed'], '0, PST_INSRT') || strstr($prepaid_tickets_data['action_performed'], '0, API_SYX_PST') ){
                    $redeem_prepaid_ticket_ids[] = $prepaid_tickets_data['prepaid_ticket_id'];
                }
                //Firebase updations
                $order_type = (isset($prepaid_tickets_data['order_type']) && ($prepaid_tickets_data['order_type'] != '')) ? ($prepaid_tickets_data['order_type']) :'';
                if($prepaid_tickets_data['channel_type'] == "10" || $prepaid_tickets_data['channel_type'] == "11") {
                    $order_type = 'firebase_order';
                }
                if(strpos($prepaid_tickets_data['action_performed'], 'UPSELL_INSERT') !== false) {
                    $upsell_order = 1;
                    if(in_array($prepaid_tickets_data['channel_type'], array(4, 6, 7, 8, 9, 13, 5))) {
                        $upsell_third_party_order = 1;
                    }
                    $main_ticket_id = $prepaid_tickets_data['main_ticket_id'];
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
                $cashier_register_id = !empty($prepaid_tickets_data['cashier_register_id']) ? $prepaid_tickets_data['cashier_register_id'] : '';
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
                /* Set default value of account number and assign value of account number if exist */
                $account_number =   '0';
                if(isset($prepaid_tickets_data['account_number']) && !empty($prepaid_tickets_data['account_number'])){
                    $account_number =   $prepaid_tickets_data['account_number'];
                }
                /* Set default value of chart number and assign value of chart number if exist */
                $chart_number =   '0';
                if(isset($prepaid_tickets_data['chart_number']) && !empty($prepaid_tickets_data['chart_number'])){
                    $chart_number =   $prepaid_tickets_data['chart_number'];
                }
                $custom_settings_data['account_number'] =   $account_number;
                $custom_settings_data['chart_number'] = $chart_number;
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
                if (isset($data['cc_row_data']) && !empty($data['cc_row_data'])) {
                    $cc_row_data = $data['cc_row_data'];
                    // Preapre array to update CC rows value in prepaid Tickets in case of Credit Card.
                    if ($is_cc_row_updated == 0 && $cc_row_data['Amtwithtax'] > 0) {
                        // Get previous cc cost amount if any
                        if ($cc_row_data['is_add_to_prioticket'] == "1") {
                            // Only in case of Add to prioticket option
                            $cc_rows_value = $db->get_where("prepaid_tickets", array("visitor_group_no" => $visitor_group_no, "visitor_tickets_id" => "0"))->row()->cc_rows_value;
                            if ($cc_rows_value != '' && $cc_rows_value != NULL) {
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
                $sale_variation_amount = $resale_variation_amount = 0;
                $voucher_reference = '';
                if(!empty($prepaid_extra_booking_information)) {
                    $per_participant_info = isset($prepaid_extra_booking_information['per_participant_info']) ? $prepaid_extra_booking_information['per_participant_info'] : array();
                    if (!empty($per_participant_info)) {
                        $prepaid_tickets_data['secondary_guest_name'] = !empty($per_participant_info['name']) ? $per_participant_info['name'] : '';
                        $prepaid_tickets_data['secondary_guest_email'] = !empty($per_participant_info['email']) ? $per_participant_info['email'] : '';
                        $prepaid_tickets_data['passport_number'] = !empty($per_participant_info['id']) ? $per_participant_info['id'] : '';
                        $prepaid_tickets_data['phone_number'] = !empty($per_participant_info['phone_no']) ? $per_participant_info['phone_no'] : '';
                    }
                    $voucher_reference = $prepaid_extra_booking_information['voucher_reference'];
                    $is_commission_on_variation = 0;
                    if (isset($prepaid_extra_booking_information['partner_cost'])) {
                        $prepaid_tickets_data['partner_cost'] = $prepaid_extra_booking_information['partner_cost'];
                    }

                    if (isset($prepaid_extra_booking_information['supplier_cost'])) {
                        $prepaid_tickets_data['supplier_cost'] = $prepaid_extra_booking_information['supplier_cost'];
                    }
                    if (isset($prepaid_extra_booking_information['is_commission_on_variation'])) {
                        $is_commission_on_variation = $prepaid_extra_booking_information['is_commission_on_variation'];
                    }
                    if (isset($prepaid_extra_booking_information['sale_variation_amount'])) {
                        $is_discount_on_variation = $prepaid_extra_booking_information['is_discount_on_variation'];
                        if ($is_discount_on_variation == 1) {
                            $variation_discount = $prepaid_extra_booking_information['sale_variation_amount'] * $prepaid_extra_booking_information['discount']/ 100;
                            $sale_variation_amount = $prepaid_extra_booking_information['sale_variation_amount'] - $variation_discount;
                        } else {
                            $sale_variation_amount = $prepaid_extra_booking_information['sale_variation_amount'];
                        }
                        
                    }
                    if (isset($prepaid_extra_booking_information['resale_variation_amount'])) {
                        $resale_variation_amount = $prepaid_extra_booking_information['resale_variation_amount'];
                    }
                    if (isset($prepaid_extra_booking_information['product_type_pricing']['price_variations'])) {
                        foreach ($prepaid_extra_booking_information['product_type_pricing']['price_variations'] as $price_variation) {
                            if ($price_variation['variation_type'] == 'PRODUCT_MARKUP') {
                                $markup_price = $price_variation['variation_amount'];
                            }
                        }
                    }
                    if (isset($prepaid_extra_booking_information['is_commission_applicable_varation'])) {
                        $is_commission_applicable_varation = $prepaid_extra_booking_information['is_commission_applicable_varation'];
                    }
                    $distributor_fee_percentage =   '';
                    if(isset($prepaid_extra_booking_information['ticket_type_data'])) {
                        $distributor_fee_percentage =   $prepaid_extra_booking_information['ticket_type_data'][$prepaid_tickets_data['tps_id']]['prices']['product_type_pricing']['distributor_fee_percentage'];
                    }
                    if (isset($prepaid_extra_booking_information['bundle_discount'])) {
                        $bundle_discount = $prepaid_extra_booking_information['bundle_discount'];
                    }
                }
                $prepaid_tickets_data['is_voucher'] = (isset($prepaid_tickets_data['is_voucher'])) ? $prepaid_tickets_data['is_voucher'] : ($prepaid_tickets_data['activation_method'] == 10 ? 1: 0);
                if (INSERT_BATCH_ON == 0) {
                    $db->insert($prepaid_table, $prepaid_tickets_data);
                }

                $insertedId = $prepaid_tickets_data['prepaid_ticket_id'];
                $inserted_count++;
                $first_ticket_id = $ticket_id = $prepaid_tickets_data['ticket_id'];
                
                $keyReprice = $prepaid_tickets_data['ticket_id'] . "_" . $prepaid_tickets_data['tps_id'] . "_" . $prepaid_tickets_data['visitor_group_no']."_".$i;
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
                    $ticket_reseller_id = $ticket_details[$ticket_id]->reseller_id;

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
                $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_confirm_data_preparation_starting_'.date("H:i:s.u"), array("order" => $mpos_order_id), $channel_type);    
                $selected_date = !empty($prepaid_tickets_data['selected_date']) ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                $from_time     = !empty($prepaid_tickets_data['from_time']) ? $prepaid_tickets_data['from_time'] : '';
                $to_time       = !empty($prepaid_tickets_data['to_time']) ? $prepaid_tickets_data['to_time'] : '';
                $slot_type     = !empty($prepaid_tickets_data['timeslot']) ? $prepaid_tickets_data['timeslot'] : '';
                $confirm_data = array();
                $confirm_data['pertransaction'] = "0";
                $confirm_data['scanned_pass'] = isset($prepaid_tickets_data['scanned_pass']) ? $prepaid_tickets_data['scanned_pass'] : '';
                $discount_data = unserialize($prepaid_tickets_data['extra_discount']);
                $confirm_data['creation_date'] = $prepaid_tickets_data['created_at'];
                $confirm_data['visit_date'] = (isset($prepaid_tickets_data['scanned_at']) && $prepaid_tickets_data['scanned_at'] != '') ? $prepaid_tickets_data['scanned_at'] : $prepaid_tickets_data['created_at'];
                $confirm_data['visit_date_time'] = (isset($prepaid_tickets_data['visit_date_time']) && $prepaid_tickets_data['visit_date_time'] != '') ? $prepaid_tickets_data['visit_date_time'] : '';
                unset($prepaid_tickets_data['visit_date_time']);
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
                $confirm_data['cashier_register_id'] = $cashier_register_id;
                $confirm_data['voucher_reference'] = $voucher_reference;
                $confirm_data['museum_name'] = !empty($prepaid_tickets_data['museum_name']) ? $prepaid_tickets_data['museum_name'] : $museum_details[$ticket_id];
                $confirm_data['ticket_reseller_id'] = $ticket_reseller_id;
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
                $confirm_data['order_updated_cashier_id'] = $prepaid_tickets_data['order_updated_cashier_id'];
                $confirm_data['order_updated_cashier_name'] = $prepaid_tickets_data['order_updated_cashier_name'];
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
                $confirm_data['related_product_id'] = !empty($prepaid_tickets_data['related_product_id']) ? $prepaid_tickets_data['related_product_id']:0;
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
                $this->CreateLog('add_log.php', 'step-4', array('data'=> json_encode($data))); 
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
                    $confirm_data['is_reservation'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $ticket_details[$ticket_id]->is_reservation : '0';                   
                } else {
                    $confirm_data['selected_date'] = $prepaid_tickets_data['selected_date'] != '' ? $prepaid_tickets_data['selected_date'] : date('Y-m-d');
                    $confirm_data['from_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['from_time'] : '0';
                    $confirm_data['to_time'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['to_time'] : '0';
                    $confirm_data['slot_type'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $prepaid_tickets_data['timeslot'] : '0';
                    $confirm_data['is_reservation'] = $ticket_details[$ticket_id]->is_reservation == '1' ? $ticket_details[$ticket_id]->is_reservation : '0';
                }
                $confirm_data['ticketpriceschedule_id'] = $prepaid_tickets_data['tps_id'];
                $confirm_data['ticketwithdifferentpricing'] = $ticket_details[$ticket_id]->ticketwithdifferentpricing;
                $confirm_data['booking_selected_date']      = '';
                if( $ticket_details[$ticket_id]->is_reservation == '0' && isset($prepaid_tickets_data['booking_selected_date']) ) {
                    $confirm_data['booking_selected_date']      = $prepaid_tickets_data['booking_selected_date'];
                }
                if($prepaid_tickets_data['activation_method'] == 0 || $prepaid_tickets_data['activation_method'] == "0"){
                    $activation_method = $prepaid_tickets_data['activation_method'];
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
                $order_status = $confirm_data['order_status'] = $prepaid_tickets_data['order_status'];
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
                    $confirm_data['ctd_currency'] = '';
                } else {
                    $cluster_tickets_transaction_id_array[$prepaid_tickets_data['ticket_id'] . '::' . $prepaid_tickets_data['cluster_group_id'] . '::' . $prepaid_tickets_data['clustering_id']][] = $transaction_id;
                    $this->CreateLog('checktransaction.php', json_encode($cluster_tickets_transaction_id_array), array()); 
                    $add_on[$prepaid_tickets_data['cluster_group_id']][$prepaid_tickets_data['clustering_id']][] = $add_on_transaction = $transaction_id_array[trim($prepaid_tickets_data['cluster_group_id'])][trim($prepaid_tickets_data['clustering_id'])];
                    $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_name'];
                    $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['merchant_admin_id'];
                    $confirm_data['merchant_currency_code'] = $confirm_data['supplier_currency_code'];
                    $confirm_data['channel_id'] = $reseller_channel_id;
                    $confirm_data['ctd_currency'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['currency'];
                    $confirm_data['main_ticket_id'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['main_ticket_id'];
                    $confirm_data['main_tps_id'] = $cluster_tickets_data[$prepaid_tickets_data['tps_id']]['main_ticket_price_schedule_id'];
                    $confirm_data['hotel_reseller_id'] = $hotel_info->reseller_id;
                    $confirm_data['transaction_id'] = $transaction_id_array[$prepaid_tickets_data['cluster_group_id']][$prepaid_tickets_data['clustering_id']][0];
                    $this->CreateLog('checktransaction.php', 'step2', array('cluster_group_id' => $prepaid_tickets_data['cluster_group_id'], 'clustering_d' => $prepaid_tickets_data['clustering_id'], 'transaction_id' => $confirm_data['transaction_id'],'transaction_data' => json_encode($transaction_id_array))); 
                }
                if (!empty($main_ticket_combi_data) && isset($main_ticket_combi_data[$prepaid_tickets_data['ticket_id']])){
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
                // Insert activation method as per PT Data
                $confirm_data['activation_method']  = $prepaid_tickets_data['activation_method'];
                $confirm_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                $confirm_data['payment_date'] = !empty($prepaid_tickets_data['payment_date']) ? $prepaid_tickets_data['payment_date'] : '0000-00-00 00:00';
                /* Assign chart number value */
                $confirm_data['chart_number'] = $chart_number;
                /* Assign account number value */
                $confirm_data['account_number'] = $account_number;
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
                $confirm_data['tax_exception_applied'] = $prepaid_tickets_data['tax_exception_applied'];
                $confirm_data['tax_id'] = $prepaid_tickets_data['tax_id'];
                $confirm_data['tax_name'] = $prepaid_tickets_data['tax_name'];
                $confirm_data['tax'] = $prepaid_tickets_data['tax'];
		        $confirm_data['sale_variation_amount'] = $sale_variation_amount;
                $confirm_data['resale_variation_amount'] = $resale_variation_amount;
                $confirm_data['is_discount_on_variation'] = $is_discount_on_variation;
                $confirm_data['is_commission_on_variation'] = $is_commission_on_variation;
                $confirm_data['markup_price'] = $markup_price;
                $confirm_data['is_commission_applicable_varation'] = $is_commission_applicable_varation;
                $confirm_data['distributor_fee_percentage'] = $distributor_fee_percentage;
                $confirm_data['bundle_discount'] = $bundle_discount;
                /* #region to fetch tps_id of related product */ 
                if( $confirm_data['related_product_id'] != '' ) {
                    $ticket_data =   $this->get_tps_id_of_related_product( $confirm_data['related_product_id'] );
                    $main_ticket_tps_ids    =    array_column($ticket_data,'tps_id');
                    /* Prepare array in case of bundle product to get the commissions of main ticket */
                    if( !empty($ticket_data) && $ticket_data[0]['is_combi'] == '4'){
                        if(count($main_ticket_tps_ids) > 1 ){
                            $confirm_data['related_product_tps_id'] =   $main_ticket_tps_ids;
                        } else {
                            $confirm_data['related_product_tps_id'] =   $ticket_data[0]['tps_id'];
                        }
                        $mec_is_combi = $ticket_data[0]['is_combi'];
                        $bundle_main_product_id[] = $confirm_data['related_product_id'] ?? '';
                    }
                }
                /* #endregion to fetch tps_id of related product */ 
                $this->CreateLog('ticket_status.php', 'step 2', array("activated" => $confirm_data['ticket_status'], "prepaid_tickets_data" => $prepaid_tickets_data['activated']));
                
                if( in_array( $prepaid_tickets_data['channel_type'], array( '5', '10', '11' ) ) ) {
                    $confirm_data['voucher_updated_by'] = $prepaid_tickets_data['voucher_updated_by'];
                    $confirm_data['voucher_updated_by_name'] = $prepaid_tickets_data['voucher_updated_by_name'];
                }
                $node_api_response = $confirm_data['node_api_response'] = isset($prepaid_tickets_data['node_api_response']) ? $prepaid_tickets_data['node_api_response'] : 0;
                
                /* pass reseller sub catalog ids data further */
                $confirm_data['ticket_reseller_subcatalog_id'] = $ticket_reseller_subcatalog_id;
                
                unset($prepaid_tickets_data['node_api_response']);
                $extra_option_merchant_data[$ticket_id] = 0;
                //$extra_option_merchant_data[$ticket_id] = $prepaid_tickets_data['merchant_admin_id'];
                //insert in vt when booking_status is 1 only.
                if( $booking_status == '1' ) {
                    /* Viator API Booking Amendment case because we donot have secondary DB connectivity on API branch */
                    if ($uncancel_order == 1 && !empty($vt_version)) {
                        $confirm_data['version'] = (int)(($vt_version['version'] > $vt_version) ? $vt_version['version']: $vt_version);
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
                    $this->CreateLog('final_visitor_data.php', 'confirm_data 2', array("data" => json_encode($confirm_data)));
                    $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_step_confirm_data'.date("H:i:s.u"), array("confirm_data" => json_encode($confirm_data), "order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type);                     
                    $visitor_tickets_data = $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, $is_secondary_db);
                    $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_step_visitor_tickets_data'.date("H:i:s.u"), array("visitor_tickets_data" => json_encode($visitor_tickets_data), "order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 
                    $this->CreateLog('Mpos_orders_logs.php','data_step2_visitor_tickets_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);

                    $prepaid_tickets_data['tax_id'] = $visitor_tickets_data['ticket_tax_id'];
                    if($prepaid_tickets_data['is_discount_code']  == '1' || isset( $prepaid_tickets_data['reprice_discount'] )) {
                        $is_discount_code = $prepaid_tickets_data['is_discount_code'];              
                        
                        if( 
                            isset( $prepaid_tickets_data['reprice_discount'] ) && 
                            isset( $prepaid_tickets_data['reprice_discount'][$keyReprice] )
                        ) {
                            $this->CreateLog('discount_code_issue.php','is_discount_code_'.date("H:i:s.u"), array('val' => 1), "10");

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
                                'ticket_level_commissions' => $visitor_tickets_data['ticket_level_commissions']
                            );
                        } 
                        else {
                            $this->CreateLog('discount_code_issue.php','is_discount_code_'.date("H:i:s.u"), array('val' => 2 ), "10");

                            $discount_codes_details = $prepaid_extra_booking_information["discount_codes_details"] ? $prepaid_extra_booking_information["discount_codes_details"] : $discount_codes_details; 
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
                    $this->CreateLog('Mpos_orders_logs.php','below_is_discount_code_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);

                    if ($confirm_data['is_addon_ticket'] != "2") {
                        
                        $transaction_id_wise_ticketAmt[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['ticketAmt'];
                        $transaction_id_wise_discount[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['discount'];
                        $transaction_id_wise_clustering_id[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['targetlocation'];
                        $clustering_id_wise_ticket_id[$visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['transaction_id']] = $visitor_tickets_data['visitor_per_ticket_rows_batch'][0]['ticketId'];
                    }

                    $group_booking_email_data = array();
                } else {
                    $this->CreateLog('Mpos_orders_logs.php','Above_getAgeGroupAndDiscount_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                    /* #region  to setup supplier email notification data */
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
                        $group_booking_email_data['age_groups'] = array('0' => array('ticket_type' => $ticket_type, 'count' => '1', 'age_group' => $age_groups,'tps_id' => $prepaid_tickets_data['tps_id'],'pax' => $prepaid_tickets_data['pax']));
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
                $this->CreateLog('Mpos_orders_logs.php','Above_visitor_per_ticket_rows_batch_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {
                    foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $key => $visitor_row_batch) {
                        if (isset($clustering_id_wise_ticket_id[$visitor_row_batch['transaction_id']])) {
                            $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['targetcity'] = $clustering_id_wise_ticket_id[$visitor_row_batch['transaction_id']];
                        }
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
                            $supplier_price += $visitor_row_batch['supplier_gross_price'];
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
                            if ($confirm_data['is_addon_ticket'] == "2") {
                                
                               $supplier_price += $visitor_row_batch['supplier_gross_price'];
                               $supplier_discount += $visitor_row_batch['supplier_discount'];
                               $supplier_net_price += $visitor_row_batch['supplier_net_price'];
                               $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['ticketAmt'] = $transaction_id_wise_ticketAmt[$visitor_row_batch['transaction_id']];
                               $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['discount'] = $transaction_id_wise_discount[$visitor_row_batch['transaction_id']];
                               $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['targetlocation'] = $transaction_id_wise_clustering_id[$visitor_row_batch['transaction_id']];                                         
                               $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['targetcity'] = $clustering_id_wise_ticket_id[$visitor_row_batch['transaction_id']];
                               $this->CreateLog('checktransaction.php', 'step0000', array('targetcity' => $clustering_id_wise_ticket_id[$visitor_row_batch['transaction_id']], 'transaction_id' => $visitor_row_batch['transaction_id'], 'row'=> json_encode($visitor_row_batch))); 
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
                        
                        /** push dates in selected dates array to update in promocode rows **/
                        if( !empty( $confirm_data['selected_date'] ) ) {

                            $selected_dates_array[$confirm_data['visitor_group_no']][] = strtotime( $confirm_data['selected_date'] );
                        }
                    }
                    $this->CreateLog('add_log.php', 'step-7', array('data'=> json_encode($data))); 
                    $this->CreateLog('checktransaction.php', 'step3', array('transaction_id_wise_ticketAmt' => json_encode($transaction_id_wise_ticketAmt), 'transaction_id_wise_discount' => json_encode($transaction_id_wise_discount), 'targetlocation' => json_encode($transaction_id_wise_clustering_id),'targetcity' => json_encode($clustering_id_wise_ticket_id))); 
                                    // Add new column in case of booking amandement
                   /* if ($uncancel_order == 1) {
                        foreach($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $key => $visitor_per_ticket_rows_batch) {
                            $visitor_tickets_data['visitor_per_ticket_rows_batch'][$key]['nights'] =1; 
                        }
                    }*/
    
                    //$final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                    // create transaction_fee_data for main ticket
                    /* #region  to prepare data for transaction_fee rows for main ticket*/
                    $this->CreateLog('Mpos_orders_logs.php','Above_transaction_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                    if ($prepaid_tickets_data['is_addon_ticket'] != 2) {
                        // group by booking id
                                           
                        $transaction_key = $prepaid_tickets_data['ticket_id']."_".$ticket_type_id."_".$prepaid_tickets_data['selected_date']."_".$prepaid_tickets_data['from_time']."_".$prepaid_tickets_data['to_time'];
                        if (isset($transaction_data[$transaction_key])) {
                            $transaction_data[$transaction_key]['total_quantity'] += $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['total_pax'] += $prepaid_tickets_data['pax'];
                            $transaction_data[$transaction_key]['total_order_amount'] += $general_fee;
                            $transaction_data[$transaction_key]['quantity'] += $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['pax'] += $prepaid_tickets_data['pax'];
                        } else {
                            $this->CreateLog('Mpos_orders_logs.php','Inside_transaction_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                            $transaction_data[$transaction_key] = $prepaid_tickets_data;
                            $transaction_data[$transaction_key]['quantity'] = $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['total_quantity'] = $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['pax'] = $prepaid_tickets_data['pax'];
                            $transaction_data[$transaction_key]['total_pax'] = $prepaid_tickets_data['pax'];
                            $transaction_data[$transaction_key]['total_order_amount'] = $general_fee;
                            $transaction_fee_data =  $this->transaction_fee_model->get_transaction_fee_data($hotel_info, $prepaid_tickets_data['ticket_id'],$ticket_type_id, $prepaid_tickets_data['museum_id'], $distributor_partner_id, $order_date, $merchant_admin_id, $prepaid_tickets_data['from_time'], $prepaid_tickets_data['to_time']);
                            $transaction_data[$transaction_key]['transaction_fee_data'] = $transaction_fee_data;
                        }
                        $transaction_data[$transaction_key]['data'] = $guest_row;
                    }
                    $this->CreateLog('Mpos_orders_logs.php','Below_transaction_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                    /* #endregion to prepare data for transaction_fee rows for main ticket */
                    
                }
                $this->CreateLog('checktransaction.php', 'step3', array('transaction_id_wise_ticketAmt' => json_encode($transaction_id_wise_ticketAmt), 'transaction_id_wise_discount' => json_encode($transaction_id_wise_discount), 'targetlocation' => json_encode($transaction_id_wise_clustering_id),'targetcity' => json_encode($clustering_id_wise_ticket_id))); 
                $final_visitor_data = array_merge($final_visitor_data, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                // partner row not exist
                if (empty($partner_used_limit_exist)){
                    $partner_used_limit = 0;
                }
                $this->CreateLog('add_log.php', 'step-8', array('data'=> json_encode($data))); 
                $this->CreateLog('Mpos_orders_logs.php', $mpos_order_id.'_before_update_credit_limit'.date("H:i:s.u"), array("order" => $mpos_order_id, "pt_data_prepared" => json_encode($prepaid_tickets_data), "channel_type" => $channel_type), $channel_type);
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
                /* #endregion to prepare update_credit_limit data */
                //$extra_option_merchant_data[$ticket_id] = $market_merchant_id;
                $extra_option_merchant_data[$ticket_id] = $new_merchant_admin_id;
                /* #region to prepare data for supplier notification email  */
                if (isset($visitor_tickets_data) && $visitor_tickets_data['museum_id'] != '') {
                    $mail_content[$mail_key] = $visitor_tickets_data;
                    $mail_content[$mail_key]['selected_date'] = $confirm_data['selected_date'];
                    $mail_content[$mail_key]['extra_text_field'] = $confirm_data['extra_text_field'];
                    $mail_content[$mail_key]['extra_text_field_answer'] = $confirm_data['extra_text_field_answer'];
                    $mail_content[$mail_key]['from_time'] = $confirm_data['from_time'];
                    $mail_content[$mail_key]['to_time'] = $confirm_data['to_time'];
                    $mail_content[$mail_key]['ticket_booking_id'] = $confirm_data['ticket_booking_id'];
                    $mail_content[$mail_key]['is_reservation'] = !empty($confirm_data['is_reservation'])?1:0;
                    if($ticket_id != '') {
                        $mail_content[$mail_key]['ticket_id'] = $ticket_id;
                    }
                } else if(!empty($group_booking_email_data)) {
                    $mail_content[$mail_key] = $group_booking_email_data;
                    $mail_content[$mail_key]['selected_date'] = $confirm_data['selected_date'];
                    $mail_content[$mail_key]['extra_text_field'] = $confirm_data['extra_text_field'];
                    $mail_content[$mail_key]['extra_text_field_answer'] = $confirm_data['extra_text_field_answer'];
                    $mail_content[$mail_key]['from_time'] = $confirm_data['from_time'];
                    $mail_content[$mail_key]['to_time'] = $confirm_data['to_time'];
                    $mail_content[$mail_key]['is_reservation'] = !empty($confirm_data['is_reservation'])?1:0;
                    if($ticket_id != '') {
                        $mail_content[$mail_key]['ticket_id'] = $ticket_id;
                    }
                }
                if (!empty($pickup_locations_data) && !empty($mail_content)) {
                    $mail_content[$mail_key]['pickups_data'] = $pickup_locations_data[$prepaid_tickets_data['financial_id']];
                    $mail_content[$mail_key]['pickups_data']['time'] = $prepaid_tickets_data['financial_name'];
                }
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
                $prepaid_tickets_data['supplier_original_price'] = $supplier_original_price;
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
                $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_before_channel_check'.date("H:i:s.u"), array("order" => $mpos_order_id, "pt_data_prepared" => json_encode($prepaid_tickets_data), "channel_type" => $channel_type), $channel_type);
                if ($uncancel_order == 1 && !empty($pt_version)) {
                    $prepaid_tickets_data['version'] = (int)((isset($pt_version['version']) && $pt_version['version'] > $pt_version) ? $pt_version['version']: $pt_version);
                }
                /* Overwrite account number value fetched from TLC/CLC level */
                if( $account_number == '0' && $visitor_tickets_data['ticket_level_commissions']->account_number > '0') {
                    $account_number =   $prepaid_tickets_data['account_number'] =   $visitor_tickets_data['ticket_level_commissions']->account_number;
                } 
                /* Overwrite chart number value fetched from TLC/CLC level */
                if( $chart_number == '0' && !empty($visitor_tickets_data['ticket_level_commissions']->chart_number) ) {
                    $chart_number =   $prepaid_tickets_data['chart_number'] =   $visitor_tickets_data['ticket_level_commissions']->chart_number;
                } 
                $final_prepaid_data[] = $prepaid_tickets_data;
                $visitor_ids_array[] = $visitor_tickets_data['id'];
                $this->CreateLog('Mpos_orders_logs.php','Above_ARENA_SUPPLIER_ID_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                if($prepaid_tickets_data['museum_id'] == ARENA_SUPPLIER_ID) {

                    /* increment in flag for arena notification which will later on used in check for sending request to arena server*/
                    $arena_flag += 1;
                    $arena_array[] = $prepaid_tickets_data;
                }
                $this->CreateLog('Mpos_orders_logs.php','Below_ARENA_SUPPLIER_ID_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                /* Handle case for to send notification via api */
                if(!empty(NOTIFY_SUPPLIER_NOT_ARENA)) {
                    $notify_api_flag += 1;
                    $notify_api_array[] = $prepaid_tickets_data;
                }
                $this->CreateLog('Mpos_orders_logs.php','Below_NOTIFY_SUPPLIER_NOT_ARENA_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);

                if($channel_type != "11" && $channel_type != "10"){
                    $this->ADAM_TOWER_SUPPLIER_ID = json_decode(ADAM_TOWER_SUPPLIER_ID, true);
                    if(in_array($prepaid_tickets_data['museum_id'],$this->ADAM_TOWER_SUPPLIER_ID)) {

                        /* increment in flag for adam tower notification which will later on used in check for sending request to adam tower server*/
                        $adam_tower_flag += 1;
                        $adam_tower_array[] = $prepaid_tickets_data;
                    }
                    $this->CreateLog('Mpos_orders_logs.php','Below_ADAM_TOWER_SUPPLIER_ID_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                }
                $this->CreateLog('Mpos_orders_logs.php','Below_ADAM_TOWER_SUPPLIER_ID_after_channel_check'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
                $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_before_channel_check'.date("H:i:s.u"), array("order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type);
                // Group booking case or GYG case
                if ($channel_type == 2) {
                    if ($order_status == 4 && in_array( $activation_method, $allowed_activation_methods ) ) {
                        $is_order_confirmed = 1;
                    } else {
                        $is_order_confirmed = 0;
                    }
                    $update_isprepaid = 1;
                } else {
                    $is_order_confirmed = 1;
                    $update_isprepaid = 0;
                }
                $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_after_channel_check'.date("H:i:s.u"), array("order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type);
            }
            $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_loop_end'.date("H:i:s.u"), array("order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 

            $this->CreateLog('Mpos_orders_logs.php','Below_ADAM_TOWER_SUPPLIER_ID_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);

            $this->CreateLog('Mpos_orders_logs.php','data_step_after_prepare_pt_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type); 
            /* #endregion to prepare PT table Data */
            /* Notifying to Arena proxy Listener */
            if(!empty($arena_array) && $arena_flag > 0 && $is_secondary_db == "1") {
                $this->load->model('api_model');
                /* sending data for further request sending to arena_proxy_listener */
                $this->api_model->arena_instant_booking_notification($arena_array);
            }
            $this->CreateLog('Mpos_orders_logs.php','Below_arena_instant_booking_notification_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
            /* Notifying to adam tower*/
            if(!empty($adam_tower_array) && $adam_tower_flag > 0 && $is_secondary_db == "1") {
                $this->load->model('api_model');
                /* sending data for further request sending to adam tower */
                $this->api_model->adam_tower_instant_booking_notification($adam_tower_array);
            }
            $this->CreateLog('Mpos_orders_logs.php','Below_adam_tower_instant_booking_notificationf_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), "10");
            $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_step_prepaid_tickets_data_loop_end'.date("H:i:s.u"), array("order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 
        } 
        if (isset($activation_method) && $activation_method == "1" && $cc_rows_already_inserted == 1 && $is_prepaid == "1" && $is_prioticket == "1") {
            $this->hotel_model->captureThePendingPaymentsv1('', $pspReference, 0, $hotel_id, $visitor_group_no, '0', $is_secondary_db);
        }
        $this->CreateLog('new_mpos_orders_logs.php', $mpos_order_id.'_data_step_prepaid_tickets_data_loop_outside_loop'.date("H:i:s.u"), array("order" => $mpos_order_id, "channel_type" => $channel_type), $channel_type); 

        $this->CreateLog('Mpos_orders_logs.php','Below_update_credit_limit_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        $vt_max_version_data = $transaction_ids_arr = [];
        /* #region to get transaction fee rows  */
        if (!empty($transaction_data)) {
            $row_count = 950;
            // insert transaction fee rows
            // For all debitor types except Market merchant
            if ((SYNCH_WITH_RDS_REALTIME) && $is_secondary_db == "4") {
                $rds_db2_db = $this->fourthdb->db;
            } else {
                $rds_db2_db = $this->secondarydb->rodb;
            }
            $vt_max_version_data = $rds_db2_db->select('max(version) as version, transaction_id, row_type')->from('visitor_tickets')->where('vt_group_no', $visitor_group_no)->group_by("transaction_id")->get()->result_array();
            if (!empty($vt_max_version_data)) {
                foreach ($vt_max_version_data as $vt_max_version_data_single) {
                    if (in_array($vt_max_version_data_single['row_type'], ['18', '19'])) {
                        $transaction_ids_arr[] = $vt_max_version_data_single['transaction_id'];
                    }
                }
                if (!empty($transaction_ids_arr)) {
                    $max_transaction_id = max($transaction_ids_arr);
                    $row_count = substr($max_transaction_id, -3);
                    $row_count++;
                }
            }
            $transaction_fee_rows = $this->transaction_fee_model->transaction_data($transaction_data, $total_order_amount, $total_quantity, $row_count);
            if (!empty($transaction_fee_rows)) {
                foreach ($transaction_fee_rows as $row) {
                    $final_visitor_data = array_merge($final_visitor_data, $row);
                }
            }
        }
        $this->CreateLog('Mpos_orders_logs.php','Below_transaction_dataa_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        /* #endregion to get transaction fee rows*/
        $eoc = 800;
        if (isset($data['prepaid_extra_options_data'])) {
            if($channel_type == 3 || $channel_type == 1) {
                $booking_status = 1;
            }
            $details['extra_services'] = $extra_services = $data['prepaid_extra_options_data'];
            $taxes = array();
            $extra_service_visitors_data = array();
            $prepaid_extra_options_data = array();
            $extra_option_id_increment = 0;
            foreach ($data['prepaid_extra_options_data'] as $service) {
                $service['from_time'] = trim($service['from_time']);
                $service['to_time'] = trim($service['to_time']);
                $tp_payment_method = $service['tp_payment_method'];
                if (INSERT_BATCH_ON == 0) {
                    $db->insert("prepaid_extra_options", $service);
                }
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

                /* If variation type 0 then insert add extra option sale rows */
                if($booking_status == '1' && ((!isset($service['variation_type'])) || (isset($service['variation_type']) && $service['variation_type'] != '1'))) {
                    
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
                    $today_date =  strtotime($service['created']) + ($timezone * 3600);
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
                    $service_data_for_visitor['cashier_register_id'] = $cashier_register_id;
                    $service_data_for_visitor['museum_id'] = $ticket_details[$ticket_id]->cod_id;
                    $service_data_for_visitor['museum_name'] = $museum_details[$ticket_id];
                    $service_data_for_visitor['cashier_id'] = $cashier_id;
                    $service_data_for_visitor['cashier_name'] = $cashier_name;
                    $service_data_for_visitor['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
                    $service_data_for_visitor['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date'])+($timezone * 3600));
                    if ($activation_method == '10') {
                        $service_data_for_visitor['is_voucher'] = '1';
                    }
                    $service_data_for_visitor['reseller_id'] = $hotel_info->reseller_id;
                    $service_data_for_visitor['reseller_name'] = $hotel_info->reseller_name;
                    $service_data_for_visitor['saledesk_id'] = $hotel_info->saledesk_id;
                    $service_data_for_visitor['saledesk_name'] = $hotel_info->saledesk_name;
                    $service_data_for_visitor['is_custom_setting'] = $custom_settings_detail['is_custom_setting'];
                    $service_data_for_visitor['external_product_id'] = $custom_settings_detail['external_product_id'];
                    /* Assign account number value */
                    $service_data_for_visitor['account_number'] = $account_number;
                    $service_data_for_visitor['chart_number'] = $chart_number;
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
                        $service_data_for_museum['cashier_register_id'] = $cashier_register_id;
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
                        /* Assign account number value */
                        $service_data_for_museum['account_number'] = $account_number;
                        $service_data_for_museum['chart_number'] = $chart_number;
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
        $this->CreateLog('Mpos_orders_logs.php','Below_prepaid_extra_options_data_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        /* #endregion to prepare and insert extra options rows */
        
        $vt_max_version  = 0;
        /* #region to insert discount code or repricing rows  */
        if (($is_discount_code == '1' && $booking_status == '1') || !empty($discount_codes_details)) {
            $this->CreateLog('vt_max_version_process.php', 'discount_codes_details', array('data'=> json_encode($discount_codes_details))); 
            $this->CreateLog('vt_max_version_process.php', 'data', array('data'=> json_encode($data))); 
            $discount_code_values = array();
            $promo_code_taxes = array();
            $tax_value = array();
            foreach ($discount_codes_details as $d_discount_codes_detail) {
                if ($d_discount_codes_detail['is_reprice'] !== 1 && !empty($d_discount_codes_detail['promocode'])) {
                    $discount_code_values[$d_discount_codes_detail['promocode']] = $d_discount_codes_detail['promocode'];
                }
            }
            $this->CreateLog('discount_code_issue.php', 'promocode_tax'.$visitor_group_no, array('discount_codes_detail' => json_encode($discount_codes_details), 'discount_code_values' => json_encode($discount_code_values)), "10");
            if (!empty($discount_code_values)) {
                $promocode_tax = $this->common_model->getmultipleFieldValueFromTable('tax_id,tax_value,promocode', 'promocode', '', 0, 'result_array', '', $discount_code_values, 'promocode');
                $this->CreateLog('discount_code_issue.php', $visitor_group_no, array('promocode query' => $this->primarydb->db->last_query()), "10");
                $tax_array = $this->common_model->selectPartnertaxes('0', SERVICE_NAME, '', array_column($promocode_tax, 'tax_id'));
                $this->CreateLog('discount_code_issue.php', $visitor_group_no, array('tax query' => $this->primarydb->db->last_query()), "10");
                foreach ($tax_array as $tax) {
                    $tax_value[$tax->tax_id ] = $tax->tax_name;
                }
                foreach($promocode_tax as $p_tax) {
                    $promo_code_taxes[strtolower($p_tax['promocode'])] = $p_tax;
                    $promo_code_taxes[strtolower($p_tax['promocode'])]['tax_name'] = $tax_value[$p_tax['tax_id']];
                }
            }
            $this->CreateLog('discount_code_issue.php', 'promocode_tax'.$visitor_group_no, array('promocode_tax' => json_encode($promocode_tax), 'tax_value' => json_encode($tax_value), 'promo_code_taxes' => json_encode($promo_code_taxes)), "10");
            $discount_visitors_data = array();
            //discount code transaction id in case of reprcing
            $dis_count= $visitor_group_no . 900;
            $discount_flag = 0;
             /* Handle promocode entry id for amaned booking with promocode */
             if (isset($data['is_amend_booking_with_promocode']) && $data['is_amend_booking_with_promocode'] == 1) {
                $promocode_count = count($discount_codes_details);
                if(empty($vt_max_version_data)){
                    $vt_max_version_data = $rds_db2_db->select('max(version) as version, transaction_id')->from('visitor_tickets')->where('vt_group_no', $visitor_group_no)->group_by("transaction_id")->get()->result_array();
                }
                $vt_max_version = max(array_column($vt_max_version_data, 'version'));
                $vt_table_transaction_ids = array_unique(array_column($vt_max_version_data, 'transaction_id'));
                $this->CreateLog('vt_max_version_process.php', 'vt_max_version_data', array('data' => json_encode($vt_max_version_data)));
                $this->CreateLog('vt_max_version_process.php', 'vt_max_version', array('data' => $vt_max_version));
                $last_promo_entry_no_exist = ($promocode_count * $vt_max_version);
                for ($j = $last_promo_entry_no_exist; $j > 0; $j--) {
                    $transaction_to_check = $dis_count + $j;
                    if (in_array($transaction_to_check, $vt_table_transaction_ids)) {
                        $dis_count = $transaction_to_check;
                        break;
                    }
                }
            }
            /* Handle promocode entry id for amaned booking with promocode */
            $this->CreateLog('discount_code_issue.php','discount_code_detail_array_'.date("H:i:s.u"), array("discount_codes_details_array" => json_encode($discount_codes_details)), "10");
            foreach ($discount_codes_details as $discount_codes_detail) {
                if( isset( $discount_codes_detail['is_reprice'] ) && $discount_codes_detail['is_reprice'] === 1  && isset($discount_codes_detail['max_discount_code']) && $discount_flag == 0) {
                    $dis_count = $discount_codes_detail['max_discount_code'] + 1;
                    $discount_flag = 1;
                } 
                $ticket_level_commissions = $discount_codes_detail["ticket_level_commissions"];
                $dis_count = $dis_count + 1;
                $discount_code = $discount_codes_detail["promocode"];
                $discount = $discount_codes_detail["discount_amount"];
                $visit_date = $createdOn;                
                // subtrtact discount code from used_limit
                $this->CreateLog('discount_code_issue.php','tax_values_'.date("H:i:s.u"), array('discount_code' => $discount_code,"tax_details" => json_encode($promo_code_taxes[trim($discount_code)])), "10");
                $update_credit_limit_data[$prepaid_tickets_data['museum_id']]['used_limit']  -= $discount;
                $ticket_tax_id = isset($promo_code_taxes[strtolower($discount_code)]['tax_id']) ? $promo_code_taxes[strtolower($discount_code)]['tax_id'] : 0;
                $ticket_tax_value = isset($promo_code_taxes[strtolower($discount_code)]['tax_value']) ? $promo_code_taxes[strtolower($discount_code)]['tax_value'] : 0.00;
                $ticket_tax_name = isset($promo_code_taxes[strtolower($discount_code)]['tax_name']) ? $promo_code_taxes[strtolower($discount_code)]['tax_name'] : 'BTW';
                $this->CreateLog('discount_code_issue.php','tax_details_'.date("H:i:s.u"), array("tax_details" => $ticket_tax_id.'-'.$ticket_tax_value), "10");
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
                $distributor_discount = $admin_discount = 0;
                $reprice_distributor_commission_percentage = 0;
                

                $invoice_status = '0';
                if ($order_status == 3 && $node_api_response == 2) {
                    $invoice_status = '3';
                }
                $current_date = date('Y-m-d H:i:s', $createdOn);
                $visit_date_time = date('Y-m-d H:i:s', $createdOn);
                $transaction_id = $dis_count;
                $visitor_ticket_id = $transaction_id . '01';
                $insert_discount_code_data['id'] = $visitor_ticket_id;
                $insert_discount_code_data['created_date'] = $current_date;
                $insert_discount_code_data['transaction_id'] = $transaction_id;
                $insert_discount_code_data['booking_status'] = "1";
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

                /** Check selected date on the basis of visitor_group_no and set in VT entries selected_date **/
                $promo_selected_date = "";
                if( isset( $selected_dates_array[$visitor_group_no] ) && !empty( $selected_dates_array[$visitor_group_no] ) ) {

                    $promo_selected_date = date( "Y-m-d", min( array_filter( $selected_dates_array[$visitor_group_no] ) ) );
                }
                
                $insert_discount_code_data['ticket_title'] = 'Discount code - ' . $discount_code;
                $insert_discount_code_data['extra_text_field_answer'] = isset($discount_codes_detail['promotion_title']) ? $discount_codes_detail['promotion_title'] : "";
                $insert_discount_code_data['selected_date'] = $promo_selected_date;
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
                $insert_discount_code_data['visit_date_time'] = $prepaid_tickets_data['created_at'] != '' ? gmdate('Y/m/d H:i:s', $prepaid_tickets_data['created_at']) : gmdate('Y/m/d H:i:s');
                $insert_discount_code_data['order_confirm_date'] = $prepaid_tickets_data['order_confirm_date'];
                $insert_discount_code_data['version'] = $prepaid_tickets_data['version'];
                $insert_discount_code_data['payment_date'] = $prepaid_tickets_data['payment_date'];
                $insert_discount_code_data['timezone'] = $timezone;
                $insert_discount_code_data['paid'] = "1";
                $insert_discount_code_data['payment_method'] = $discount_code;
                $insert_discount_code_data['all_ticket_ids'] = $voucher_reference;
                $insert_discount_code_data['captured'] = "1";
                $insert_discount_code_data['is_prepaid'] = "1";
                $insert_discount_code_data['debitor'] = 'Guest';
                $insert_discount_code_data['creditor'] = 'Credit';
                $insert_discount_code_data['partner_category_id'] = $prepaid_tickets_data['partner_category_id'];
                $insert_discount_code_data['partner_category_name'] = $prepaid_tickets_data['partner_category_name'];
                $insert_discount_code_data['tax_name'] = $ticket_tax_name;
                $insert_discount_code_data['partner_gross_price'] = $partner_gross_price = $discount; // Discount amount for adult
                $insert_discount_code_data['total_gross_commission'] = $discount; // Discount amount for adult
                $insert_discount_code_data['ticketAmt'] = $discount; // Discount amount for adult
                $discount_net = ($discount * 100) / ($ticket_tax_value + 100);
                $discount_net = round($discount_net, 2);
                $insert_discount_code_data['partner_net_price'] =  $partner_net_price = $discount_net;

                $insert_discount_code_data['tax_id'] = $ticket_tax_id;
                $insert_discount_code_data['tax_value'] = $ticket_tax_value;
                $this->CreateLog('reprice_overview.php', 'step 23', array(json_encode($taxesForDiscount), "rePrice" => $rePrice));
                if( $rePrice === true && !empty( $taxesForDiscount['1'] ) ) {
                    $insert_discount_code_data['targetlocation'] = $discount_codes_detail['clustering_id'];
                    $insert_discount_code_data['selected_date'] = $discount_codes_detail['selected_date'];
                    $insert_discount_code_data['slot_type'] = $discount_codes_detail['slot_type'];
                    $insert_discount_code_data['from_time'] = $discount_codes_detail['from_time'];
                    $insert_discount_code_data['to_time'] = $discount_codes_detail['to_time'];
                    $insert_discount_code_data['ticketId'] = $discount_codes_detail['ticket_id'];
                    $insert_discount_code_data['ticketpriceschedule_id'] = $discount_codes_detail['tps_id'];
                    $ticketTypeDetail = $this->order_process_vt_model->getTicketTypeFromTicketpriceschedule_id($discount_codes_detail['tps_id']);
                    $ticketType = 0;
                    $tickettype_name = '';
                    if(!empty($ticketTypeDetail)) {
                        if($ticketTypeDetail->parent_ticket_type == 'Custom'){
                            $tickettype_name = $ticketTypeDetail->ticket_type_label;
                        } else {
                            $tickettype_name = $ticketTypeDetail->tickettype_name;
                        }
                        $ticketType = $ticketTypeDetail->ticketType;
                    }
                    $insert_discount_code_data['ticketType'] = $ticketType;
                    $insert_discount_code_data['tickettype_name'] = $tickettype_name;
                    $insert_discount_code_data['tax_id'] = $taxesForDiscount['1']['tax_id'];
                    $insert_discount_code_data['tax_name'] = $taxesForDiscount['1']['tax_name'];
                    $insert_discount_code_data['tax_value'] = $taxesForDiscount['1']['tax_value'];
                    if( $insert_discount_code_data['tax_value'] > 0 ) {
                        
                        $insert_discount_code_data['partner_net_price'] = $partner_net_price = round(($insert_discount_code_data['partner_net_price'] * 100) / ($taxesForDiscount['1']['tax_value'] + 100),2);
                        $insert_discount_code_data['partner_gross_price'] = $partner_gross_price = round($insert_discount_code_data['partner_gross_price'],2);
                    }
                }

                
                $insert_discount_code_data['invoice_status'] = $invoice_status;
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
                if (isset($data['is_amend_booking_with_promocode']) && $data['is_amend_booking_with_promocode'] == 1) {
                   $insert_discount_code_data['action_performed'] = isset($prepaid_tickets_data['action_performed']) ? $prepaid_tickets_data['action_performed'] : ''; 
                }else{
                   $insert_discount_code_data['action_performed'] = '0';
                }
                $insert_discount_code_data['updated_at'] = $current_date;
                $insert_discount_code_data['col7'] = gmdate('Y-m', strtotime($prepaid_tickets_data['order_confirm_date']));
                $insert_discount_code_data['col8'] = gmdate('Y-m-d', strtotime($prepaid_tickets_data['order_confirm_date']) + ($timezone * 3600));
                $discount_visitors_data[] = $insert_discount_code_data;

                if($rePrice) {
                    if ($ticket_level_commissions->is_hotel_prepaid_commission_percentage == 1) {
                        // In case of % divide in distributor and admin according to %
                        $net_discount = $insert_discount_code_data['partner_net_price'];
                        $distributor_discount_net = ($net_discount * $ticket_level_commissions->hotel_prepaid_commission_percentage) / 100 ;
                        $admin_discount_net = $net_discount - $distributor_discount_net ;
                        $this->createLog('visitor_info_reprice.php', 'step4', array('net_discount'=> $net_discount, 'hotel_percenatge' => $ticket_level_commissions->hotel_prepaid_commission_percentage, 'distributor_discount_net'=> $distributor_discount_net, 'admin_discount_net' => $admin_discount_net, 'distributor_discount'=> $distributor_discount, 'admin_discount' => $admin_discount));
                    } else {
                        // In case of fixed
                        if ($ticket_level_commissions->commission_on_sale_price == 1) {
                            // if commission on sale price is on everytime admin commission will affect
                            $admin_discount_net = $insert_discount_code_data['partner_net_price'];
                            $distributor_discount = $distributor_discount_net = 0;
                        } else {
                            //if commission on sale price off
                            if ($discount_codes_detail['surcharge_type']  == 1) {
                                //  in case of surchages commission will add in distributor
                                /* Assign discount value to distributor */ 
                                $distributor_discount   =   $discount;
                                $distributor_discount_net = $insert_discount_code_data['partner_net_price'];
                                $admin_discount = $admin_discount_net = 0;
                            } else {
                                /* In case of TPS reprice amount will be insert in row_type 3..changes on 13/09/2022 */
                                if(!empty($ticket_level_commissions)){
                                    // in case of discount first distributor commission will affect after that admin
                                    if ($ticket_level_commissions->hotel_commission_net_price >= $insert_discount_code_data['partner_net_price']) {
                                        // if hotel commission is > then discount amount then admin will be 0
                                        $distributor_discount_net = $insert_discount_code_data['partner_net_price'];
                                    $admin_discount = $admin_discount_net = 0;
                                    } else {
                                        $net_discount = $insert_discount_code_data['partner_net_price'];
                                        $distributor_discount_net = $ticket_level_commissions->hotel_commission_net_price ;
                                        $admin_discount_net = $net_discount - $ticket_level_commissions->hotel_commission_net_price;
                                    }
                                } else {
                                    $distributor_discount   =   $discount;
                                    $distributor_discount_net = $insert_discount_code_data['partner_net_price'];
                                }    
                            } 
                        }   
                    }
                    /* If commissions are set on other level except TPS */ 
                    if(!empty($ticket_level_commissions)){
                        $distributor_discount = round(($distributor_discount_net * ((100 + $ticket_level_commissions->hotel_commission_tax_value) / 100)), 2);
                        $admin_discount = round(($admin_discount_net * ((100 + $ticket_level_commissions->hgs_commission_tax_value) / 100)), 2);
                    }    
                }
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
                if( $rePrice === true ) {
                    if( !empty( $taxesForDiscount['3']['tax_id'] ) && $taxesForDiscount['3']['tax_value'] > 0 ) {
                        $insert_discount_code_data['tax_id'] = $taxesForDiscount['3']['tax_id'];
                        $insert_discount_code_data['tax_name'] = $taxesForDiscount['3']['tax_name'];
                        $insert_discount_code_data['tax_value'] = $taxesForDiscount['3']['tax_value'];
                    }
                    $insert_discount_code_data['partner_net_price'] =  $distributor_discount_net;
                    $insert_discount_code_data['partner_gross_price'] = $distributor_discount;
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
                if( $rePrice === true) {
                    
                    if( !empty( $taxesForDiscount['4']['tax_id'] ) && $taxesForDiscount['4']['tax_value'] > 0 ) {
                        
                        $insert_discount_code_data['tax_id'] = $taxesForDiscount['4']['tax_id'];
                        $insert_discount_code_data['tax_name'] = $taxesForDiscount['4']['tax_name'];
                        $insert_discount_code_data['tax_value'] = $taxesForDiscount['4']['tax_value'];
                    }
                    $insert_discount_code_data['partner_net_price'] =  $admin_discount_net;
                    $insert_discount_code_data['partner_gross_price'] = $admin_discount;
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
        /* #region to Prepare data array for bundle discount rows in case of distributor commission in percentage */
        if($mec_is_combi == '4') {
            /* prepare key for unique tps_id */
            foreach ( $visitor_tickets_data['ticket_level_commissions_bundle'] as $bundle_result){
                $bundle_commissions_values[$bundle_result->ticketpriceschedule_id] = (array) $bundle_result;
            }

            /* To get the bundle discount rows from prepaid extra options table */
            $result_prepaid_extra_options   =   $this->get_prepaid_extra_option_data($bundle_main_product_id, $visitor_group_no);
            if ($uncancel_order == "1" && !empty($data['prepaid_extra_options_data']) && isset ($data['prepaid_extra_options_data'][count($data['prepaid_extra_options_data'])-1]['prepaid_extra_options_id'])) {
                /** #Comment : Fetch Latest Bundle Discount Entry And Save in DB at time of Amend only in Case of Bundle */
                $this->secondarydb->db->select('transaction_id');
                $this->secondarydb->db->from('visitor_tickets');
                $this->secondarydb->db->where("vt_group_no", $visitor_group_no);
                $this->secondarydb->db->where("row_type", '1');
                $this->secondarydb->db->where("deleted", '0');
                $this->secondarydb->db->where("transaction_type_name", 'Bundle Discount');
                $result = $this->secondarydb->db->group_by("transaction_id")->get();
                if ($result->num_rows() > 0) {
                    $result = $result->result();
                    $bundle_data = array_column($result, 'transaction_id');
                    $last_vt_trans_id = !empty($bundle_data) ?  max($bundle_data) : $visitor_group_no . 851;
                }
                $dis_count  =   $last_vt_trans_id + 1;
            } else {
                $dis_count  =   $visitor_group_no . 851;
            }
            if (!empty($result_prepaid_extra_options)) {
                foreach ($result_prepaid_extra_options as $bundle_discount_key) {
                    /* Bundle pricing rows will insert only in case of distributor commission in percetage */
                    if($bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]) {
                        if( $bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]['is_hotel_prepaid_commission_percentage'] != '1') { 
                            continue;
                        }
                    } else {
                        if(isset($visitor_tickets_data['ticket_level_commissions_bundle_row']) && $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->is_hotel_prepaid_commission_percentage != '1') { 
                            continue;
                        }
                    }
                    if ($uncancel_order == "1" && !in_array($bundle_discount_key['ticket_booking_id'], $amend_ticket_booking_ids)) {
                        continue;
                    }
                    $current_date = date('Y-m-d H:i:s', $createdOn);
                    $transaction_id = $dis_count;
                    /* Prepare row type = 1 for bundle discount */
                    $visitor_ticket_id = $transaction_id . '01';
                    $insert_bundle_discount_data['id'] = $visitor_ticket_id;
                    $insert_bundle_discount_data['created_date'] = $current_date;
                    $insert_bundle_discount_data['transaction_id'] = $transaction_id;
                    $insert_bundle_discount_data['booking_status'] = "1";
                    $insert_bundle_discount_data['row_type'] = '1';
                    $insert_bundle_discount_data['ticket_title'] = 'Bundle Discount';
                    $insert_bundle_discount_data['visitor_group_no'] = $visitor_group_no;
                    $insert_bundle_discount_data['vt_group_no'] = $visitor_group_no;
                    $insert_bundle_discount_data['invoice_id'] = '';
                    $insert_bundle_discount_data['ticketId'] = $bundle_discount_key['ticket_id'];
                    $insert_bundle_discount_data['ticket_booking_id'] = $bundle_discount_key['ticket_booking_id'];
                    $insert_bundle_discount_data['partner_gross_price'] = $bundle_discount_key['price'];
                    $insert_bundle_discount_data['partner_gross_price_without_combi_discount'] = $bundle_discount_key['price'];
                    $ticket_tax_value   =   $bundle_discount_key['tax'];
                    $insert_bundle_discount_data['partner_net_price'] = $bundle_discount_key['net_price'];
                    $insert_bundle_discount_data['partner_net_price_without_combi_discount'] = $bundle_discount_key['net_price'];
                    $insert_bundle_discount_data['hotel_name'] = $hotel_name;
                    $insert_bundle_discount_data['hotel_id'] = $hotel_id;
                    $insert_bundle_discount_data['is_shop_product'] = $prepaid_tickets_data['product_type'];
                    $insert_bundle_discount_data['transaction_type_name'] = "Bundle Discount";
                    $insert_bundle_discount_data['debitor'] = 'Guest';
                    $insert_bundle_discount_data['creditor'] = 'Credit';
                    $insert_bundle_discount_data['museum_id'] =  isset($visitor_tickets_data['museum_id']) ? $visitor_tickets_data['museum_id'] : "";
                    $insert_bundle_discount_data['museum_name'] =  isset($visitor_tickets_data['museum_name']) ? $visitor_tickets_data['museum_name'] : "";
                    $insert_bundle_discount_data['reseller_id'] =  isset($hotel_info->reseller_id) ? $hotel_info->reseller_id : "";
                    $insert_bundle_discount_data['reseller_name'] =  isset($hotel_info->reseller_name) ? $hotel_info->reseller_name : "";
                    $insert_bundle_discount_data['group_quantity'] =  $bundle_discount_key['quantity'];
                    $insert_bundle_discount_data['tax_value'] =  ($ticket_tax_value != '0') ? $ticket_tax_value : $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->ticket_tax_value;
                    $insert_bundle_discount_data['ticketpriceschedule_id'] =  ($bundle_discount_key['ticket_price_schedule_id'] != '0') ? $bundle_discount_key['ticket_price_schedule_id'] : $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->ticketpriceschedule_id;
                    $insert_bundle_discount_data['version'] = $prepaid_tickets_data['version'];
                    $insert_bundle_discount_data['action_performed'] = isset($prepaid_tickets_data['action_performed']) ? $prepaid_tickets_data['action_performed'] : '0'; 
                    $insert_bundle_discount_data['used'] = isset($prepaid_tickets_data['used']) ? $prepaid_tickets_data['used'] : '0'; 
                    if (!empty($confirm_data['related_product_id'])) {
                        $insert_bundle_discount_data['external_product_id'] = $confirm_data['related_product_id'];
                    }
                    $insert_bundle_discount_data['selected_date'] = '';
                    $insert_bundle_discount_data['targetcity'] = $bundle_discount_key['ticket_id'] ?? '';
                    $insert_bundle_discount_data['order_confirm_date'] = $confirm_data['order_confirm_date'];
                    $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->ticketpriceschedule_id;
                    $bundle_discount_visitors_data[] = $insert_bundle_discount_data;
                    /* Prepare row type = 2 for bundle discount */
                    $visitor_ticket_id = $transaction_id . '02';
                    $insert_bundle_discount_data['id'] = $visitor_ticket_id;
                    $insert_bundle_discount_data['hotel_name'] = $hotel_name;
                    $insert_bundle_discount_data['hotel_id'] = $hotel_id;
                    $insert_bundle_discount_data['row_type'] = '2';
                    $insert_bundle_discount_data['ticket_title'] = 'Bundle Discount';
                    $insert_bundle_discount_data['transaction_type_name'] = "Ticket Cost";
                    $insert_bundle_discount_data['partner_gross_price'] = '0';
                    $insert_bundle_discount_data['partner_gross_price_without_combi_discount'] = '0';
                    $insert_bundle_discount_data['partner_net_price'] = '0';
                    $insert_bundle_discount_data['partner_net_price_without_combi_discount'] = '0';
                    $insert_bundle_discount_data['debitor'] = '';
                    $insert_bundle_discount_data['creditor'] = 'Debit';
                    $bundle_discount_visitors_data[] = $insert_bundle_discount_data;
                    
                    /* Prepare row type = 3 for bundle discount */
                    $visitor_ticket_id = $transaction_id . '03';
                    $insert_bundle_discount_data['id'] = $visitor_ticket_id;
                    $insert_bundle_discount_data['hotel_name'] = $hotel_name;
                    $insert_bundle_discount_data['hotel_id'] = $hotel_id;
                    $insert_bundle_discount_data['row_type'] = '3';
                    $insert_bundle_discount_data['ticket_title'] = 'Bundle Discount';
                    $insert_bundle_discount_data['transaction_type_name'] = "Distributor Fee";
                    /* #region In case of bundle product parter net price and partner gross price will calculate on the basis of tps_id */
                    if($bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]) {
                        $partner_gross_price =    $bundle_discount_key['price'] * $bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]['hotel_prepaid_commission_percentage']/100;
                        $partner_net_price  =   $bundle_discount_key['net_price'] * $bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]['hotel_prepaid_commission_percentage']/100;
                    } else {
                        $partner_gross_price =    $bundle_discount_key['price'] * $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->hotel_prepaid_commission_percentage/100;
                        $partner_net_price  =   $bundle_discount_key['net_price'] * $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->hotel_prepaid_commission_percentage/100;
                    }
                    /* #endregion In case of bundle product parter net price and partner gross price will calculate on the basis of tps_id */
                    $insert_bundle_discount_data['partner_gross_price'] = $partner_gross_price;
                    $insert_bundle_discount_data['partner_gross_price_without_combi_discount'] = $partner_gross_price;
                    $insert_bundle_discount_data['partner_net_price']   =   $partner_net_price;
                    $insert_bundle_discount_data['partner_net_price_without_combi_discount'] = $partner_net_price;
                    $insert_bundle_discount_data['debitor'] = $hotel_name;
                    $insert_bundle_discount_data['creditor'] = 'Debit';
                    $bundle_discount_visitors_data[] = $insert_bundle_discount_data;
                    
                    /* Prepare row type = 4 for bundle discount */
                    $visitor_ticket_id = $transaction_id . '04';
                    $insert_bundle_discount_data['id'] = $visitor_ticket_id;
                    $insert_bundle_discount_data['hotel_name'] = $hotel_name;
                    $insert_bundle_discount_data['hotel_id'] = $hotel_id;
                    $insert_bundle_discount_data['row_type'] = '4';
                    $insert_bundle_discount_data['ticket_title'] = 'Bundle Discount';
                    $insert_bundle_discount_data['transaction_type_name'] = "Provider Cost";
                    /* #region In case of bundle product parter net price and partner gross price will calculate on the basis of tps_id */
                    if($bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]) {
                        $partner_gross_price    =    $bundle_discount_key['price'] * $bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]['hgs_prepaid_commission_percentage']/100;
                        $partner_net_price  =   $bundle_discount_key['net_price'] * $bundle_commissions_values[$bundle_discount_key['ticket_price_schedule_id']]['hgs_prepaid_commission_percentage']/100;
                    } else {
                        $partner_gross_price =    $bundle_discount_key['price'] * $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->hgs_prepaid_commission_percentage/100;
                        $partner_net_price  =   $bundle_discount_key['net_price'] * $visitor_tickets_data['ticket_level_commissions_bundle_row'][0]->hgs_prepaid_commission_percentage/100;
                    }
                    /* #endregion In case of bundle product parter net price and partner gross price will calculate on the basis of tps_id */
                    $insert_bundle_discount_data['partner_gross_price'] = $partner_gross_price;
                    $insert_bundle_discount_data['partner_gross_price_without_combi_discount'] = $partner_gross_price;
                    $insert_bundle_discount_data['partner_net_price'] = $partner_net_price;
                    $insert_bundle_discount_data['partner_net_price_without_combi_discount'] = $partner_net_price;
                    $insert_bundle_discount_data['debitor'] = 'Hotel guest service';
                    $insert_bundle_discount_data['creditor'] = 'Debit';
                    $bundle_discount_visitors_data[] = $insert_bundle_discount_data;
                    $dis_count++;
                }
                if(!empty($bundle_discount_visitors_data)){
                    $final_visitor_data = array_merge($final_visitor_data, $bundle_discount_visitors_data);
                }
            }
        }
        /* #endregion to Prepare data array for bundle discount rows in case of distributor commission in percentage */
        $this->CreateLog('Mpos_orders_logs.php','Below_booking_status_check_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        /* #endregion to insert discount code or repricing rows */

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
            $insert_service_data['cashier_register_id'] = $cashier_register_id;
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
            $insert_service_data['action_performed'] = isset($final_visitor_data[0]['action_performed']) ? $final_visitor_data[0]['action_performed'] : (isset($prepaid_tickets_data['action_performed']) ? $prepaid_tickets_data['action_performed'] : '0');
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
        $this->CreateLog('Mpos_orders_logs.php','Below_service_cost_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        /* #endregion to save service cost row in case of service cost per transaction */
        if (!empty($update_credit_limit_data) && $is_secondary_db == "1"){
            $this->update_credit_limit($update_credit_limit_data, 0, $published_reseller_limit);
        }

        $this->CreateLog('Mpos_orders_logs.php','Below_update_credit_limit_dataaa_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db, "order" => $mpos_order_id), $channel_type);
        $flag = 0;
        
        /* #region to prepare data for VT bigquery  */
        $bg_notify = [];
        if ($uncancel_order == 1) {
            /* statement checking */
            if ((in_array($channel_type, ["6", "7"]) || (!empty($data['prepaid_tickets_data'][0]['is_invoice']) && $data['prepaid_tickets_data'][0]['is_invoice'] == "6")) && ((isset($data['prepaid_tickets_data'][0]['reseller_id']) && $data['prepaid_tickets_data'][0]['reseller_id'] == STATEMENT_RESELLER_ID) || (isset($final_prepaid_data[0]['merchant_admin_id']) && $final_prepaid_data[0]['merchant_admin_id'] == STATEMENT_RESELLER_ID))) {
                $this->load->model('api_model');
                $bg_notify = $this->api_model->statement_checking(array('visitor_group_no' => $visitor_group_no, 'hotel_id' => $hotel_id, "is_amend_order_call" => '1'));
            }
        }
        $final_visitor_data_to_insert_big_query_transaction_specific = array();
        foreach ($final_visitor_data as $vt_key => $final_visitor_data_row) {
            /** #Comment: Handle invoce status for amend orders. */
            if(!empty($bg_notify) && !empty($final_visitor_data)){
                $final_visitor_data[$vt_key]['invoice_status'] = $bg_notify['0']['invoice_status']['row_type'][$final_visitor_data_row['row_type']]  ?? ($final_visitor_data_row['invoice_status'] ?? '');
            }
            if (!empty($bundle_booking_ids[$final_visitor_data_row['ticket_booking_id']])){
                $final_visitor_data[$vt_key]['targetcity'] = $bundle_booking_ids[$final_visitor_data_row['ticket_booking_id']] ?? '';
            }
            $final_visitor_data_to_insert_big_query_transaction_specific[$final_visitor_data_row["transaction_id"]][] = $final_visitor_data_row;
        }
        /* #endregion to prepare data for VT bigquery */

        /* To Batch insert in Main Tables */
        if (INSERT_BATCH_ON) {
            $this->CreateLog('Mpos_orders_logs.php','data_step2_final_prepaid_data_outside_batch_'.date("H:i:s.u"), array("is_secondary_db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);                 
            if (!empty($final_prepaid_data)) {
                $flag = $this->insert_batch($prepaid_table, $final_prepaid_data, $is_secondary_db);
            }
            if (!empty($prepaid_extra_options_data)) {  
                $flag = $this->insert_batch('prepaid_extra_options', $prepaid_extra_options_data, $is_secondary_db);
            }
            $this->CreateLog('Mpos_orders_logs.php', 'step 3_final_visitor_data_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
            if (!empty($final_visitor_data)) {
                $flag = $this->insert_batch($vt_table, $final_visitor_data, $is_secondary_db);
            }
        } else {
            if (!empty($final_visitor_data)) {
                $this->insert_without_batch($vt_table, $final_visitor_data, $is_secondary_db);
                $flag = 1;
            }
        }
        
        if( !empty($redeem_prepaid_ticket_ids) && $is_secondary_db != '4' ){
            $update_redeem_table = array();
            $update_redeem_table['visitor_group_no']    = $prepaid_tickets_data['visitor_group_no'];
            $update_redeem_table['prepaid_ticket_ids']  = $redeem_prepaid_ticket_ids;
            $update_redeem_table['museum_cashier_id']   = $prepaid_tickets_data['museum_cashier_id'];
            $update_redeem_table['redeem_date_time']    = gmdate("Y-m-d H:i:s");
            $update_redeem_table['museum_cashier_name'] = $prepaid_tickets_data['museum_cashier_name'];
            $update_redeem_table['hotel_id'] = $prepaid_tickets_data['hotel_id'];

            if (!empty($update_redeem_table)) {
                include_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $this->load->library('Sns');
                $request_array = array();
                $request_array['update_redeem_table'] = $update_redeem_table;
                $request_string = json_encode($request_array);
                $this->CreateLog('order_sync.php', 'redeem', array($request_string));
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;
                if (SERVER_ENVIRONMENT == 'Local') {
                    local_queue($aws_message, 'UPDATE_DB2');
                } else {
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                    
                    $err = '';
                    if ($MessageId != false) {
                        $this->load->library('Sns');
                        $sns_object = new Sns();
                        $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }  
                }                         
            }
            $this->CreateLog('Mpos_orders_logs.php', 'Below_redeem_prepaid_ticket_ids_check_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
        }                   
        if (!empty($cluster_tickets_transaction_id_array)) {
            foreach ($cluster_tickets_transaction_id_array as $main_key => $cluster_ticket_transaction_id_data) {
                $explose_main_key = explode("::", $main_key);
                for ($i = 0; $i < count($transaction_id_array[$explose_main_key[1]][$explose_main_key[2]]); $i++) {
                    foreach($cluster_ticket_transaction_id_data as $cluster_transaction_id) {                  
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
                    }
                }
            }
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_cluster_tickets_transaction_id_array_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
        /* #endregion to prapre data for bigquery VT table */
        
        // To enter the CC Cost rows in visitor_tickets DB
        if (isset($activation_method) && $activation_method == "1" && $cc_rows_already_inserted == 1 && $is_prepaid == "1" && $is_prioticket == "1") {
            $this->hotel_model->captureThePendingPaymentsv1('', $pspReference, 0, $hotel_id, $visitor_group_no, '0', $is_secondary_db);
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_captureThePendingPaymentsv1_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), "10");
        /* #endregion To enter the CC Cost rows in visitor_tickets DB. Not in use */

        // Group booking case or GYG case
        if ($channel_type == 2) {
            if ($order_status == 4 && in_array( $activation_method, $allowed_activation_methods ) ) {
                $is_order_confirmed = 1;
            } else {
                $is_order_confirmed = 0;
            }
            if(isset($data['add_to_prioticket']) && $data['add_to_prioticket'] == "1") {
                $is_order_confirmed = 1;
            }
            $update_isprepaid = '1';            
        } else {
            $is_order_confirmed = 1;            
        }

        $api_channel_type = array('4','6','7','8', '9', '12', '15');
        // Send email to museum's regarding tickets purchased, if (inapp pos case, adam website case, pospaid ticket case
        if ($is_secondary_db != 4 && $confirm_data['order_status'] != 3) {
            if ($mail_content) {
                $client_reference = $prepaid_tickets_data['without_elo_reference_no'];

                $booking_details = unserialize($prepaid_tickets_data['booking_details']);
                $extra_booking_information = '';
                $extra_booking_information = json_decode($prepaid_tickets_data['extra_booking_information']); 
                $per_participant_info = isset($prepaid_extra_booking_information->per_participant_info) ? $prepaid_extra_booking_information->per_participant_info : array();
                $country_of_residence = ''; 
                $note = '';
                $gender = '';
                if(!empty($per_participant_info->gender)) {
                    $gender = $per_participant_info->gender;
                }
                if($extra_booking_information != '') {
                    $order_custom_fields = $extra_booking_information->order_custom_fields;
                    foreach($order_custom_fields as $custom_field) {
                        if(trim($custom_field->custom_field_name) == 'country_of_residence') {
                            $country_of_residence = trim($custom_field->custom_field_value);
                        }                        
                        if($custom_field->custom_field_name == 'per_participants') {
                            foreach ($custom_field->custom_field_value as  $result) {
                                $final_array_of_api[] = $result;
                            }                      
                        }
                    }
                }
                if(isset($booking_details['travelerHotel']) && !empty($booking_details['travelerHotel'])) {
                    $traveler_hotel = $booking_details['travelerHotel'];
                }
                if(isset($booking_details['primary_host_notes']['note_administration']) && !empty($booking_details['primary_host_notes']['note_administration'])) {
                    $note = $booking_details['primary_host_notes']['note_administration'];
                }
                if(!empty($prepaid_tickets_data['phone_number'])) {
                    $phone_number = $prepaid_tickets_data['phone_number'];
                }
                $museums = array();
                $age_groups_array = array();
                $ticket_count = array();
                
                foreach ($mail_content as $content) {
                    $museum_id = $content['museum_id'];
                    $ticket_id = $content['ticket_id'];
                    if(!empty($content['is_reservation'])) {
                        $ticket_id = $content['ticket_id'].'-'.$content['ticket_booking_id'].'-'.$content['selected_date'].'-'.$content['from_time'].'-'.$content['to_time'];
                    } else {
                        $ticket_id = $content['ticket_id'].'-'.$content['ticket_booking_id'];
                    }
                    $museums[$museum_id][$ticket_id]['museum_id'] = $museum_id;
                    $museums[$museum_id][$ticket_id]['museum_name'] = $content['museum_name'];
                    $museums[$museum_id][$ticket_id]['museum_email'] = $content['museum_email'];
                    $museums[$museum_id][$ticket_id]['booking_email_text'] = !empty($content['booking_email_text'])?$content['booking_email_text']:'';
                    $museums[$museum_id][$ticket_id]['museum_additional_email'] = $content['museum_additional_email'];
                    $museums[$museum_id][$ticket_id]['ticket_title'] = $content['ticket_title'];
                    $museums[$museum_id][$ticket_id]['related_product_id'] = !empty($content['related_product_id'])?$content['related_product_id']:0;
                    $museums[$museum_id][$ticket_id]['extra_text_field'] = isset($content['extra_text_field']) ? $content['extra_text_field'] : '';
                    $museums[$museum_id][$ticket_id]['extra_text_field_answer'] = isset($content['extra_text_field_answer']) ? $content['extra_text_field_answer'] : '';
                    $museums[$museum_id][$ticket_id]['selected_date'] = $content['selected_date'];
                    $museums[$museum_id][$ticket_id]['from_time'] = $content['from_time'];
                    $museums[$museum_id][$ticket_id]['to_time'] = $content['to_time'];
                    $museums[$museum_id][$ticket_id]['is_reservation'] = !empty($content['is_reservation'])?1:0;
                    $museums[$museum_id][$ticket_id]['ticketpriceschedule_id'] = $content['ticketpriceschedule_id'];
                    $museums[$museum_id][$ticket_id]['pickups_data'] = $content['pickups_data'];
                    $museums[$museum_id][$ticket_id]['ticket_booking_id'] = $content['ticket_booking_id'];
                    $market_merchant_id = $museums[$museum_id][$ticket_id]['market_merchant_id'] = $prepaid_tickets_data['market_merchant_id'];
                    $museums[$museum_id][$ticket_id]['slot_type'] = "";
                    if (isset($content['visitor_per_ticket_rows_batch'][0]["slot_type"])) {
                        $museums[$museum_id][$ticket_id]['slot_type'] = $content['visitor_per_ticket_rows_batch'][0]["slot_type"];
                    }
                    if ($room_no != '' && !in_array($channel_type, $api_channel_type)) {
                        $museums[$museum_id][$ticket_id]['room_no'] = $room_no;
                    }
                    if (isset($traveler_hotel) && $traveler_hotel != '') {
                        $museums[$museum_id][$ticket_id]['travelerHotel'] = $traveler_hotel;
                    }
                    if ($guest_email != '') {
                        $museums[$museum_id][$ticket_id]['guest_email'] = $guest_email;
                    }
                    if ($guest_names != '') {
                        $museums[$museum_id][$ticket_id]['guest_names'] = $guest_names;
                    }
                    if ($phone_number != '') {
                        $museums[$museum_id][$ticket_id]['phone_number'] = $phone_number;
                    }
                    if ($country_of_residence != '') {
                        $museums[$museum_id][$ticket_id]['country_of_residence'] = $country_of_residence;
                    }
                    
                    if (!empty($gender)) {
                        $museums[$museum_id][$ticket_id]['gender'] = $gender;
                    }
                    if (isset($note) && $note != '') {
                        $museums[$museum_id][$ticket_id]['note'] = $note;
                    }
                    if (in_array($channel_type, $api_channel_type) && isset($prepaid_tickets_data['without_elo_reference_no']) && !empty($prepaid_tickets_data['without_elo_reference_no'])) {
                        $museums[$museum_id][$ticket_id]['client_reference'] = $client_reference;
                    }
                    foreach ($content['age_groups'] as $group) {
                        if (in_array($group['tps_id'], $age_groups_array[$museum_id][$ticket_id])) {
                            $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['count'] = $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['count'] + 1;
                        } else {
                            $age_groups_array[$museum_id][$ticket_id][] = $group['tps_id'];
                            $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['count'] = 1;
                        }
                        $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['type'] = $group['ticket_type'];
                        $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['pax'] = $group['pax'];
                        $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['ticketpriceschedule_id'] = $content['ticketpriceschedule_id'];

                        if(!empty($final_array_of_api)) {                           
                            $final_array_of_api = json_decode(json_encode($final_array_of_api),true);
                            foreach ($final_array_of_api as $row) {                                    
                                if(!empty($row[$content['ticketpriceschedule_id']])) {
                                     $ticket_count[$museum_id][$ticket_id][$group['tps_id']]['extra_booking_information_api'] = $row[$content['ticketpriceschedule_id']];
                                     $museums[$museum_id][$ticket_id]['api_data'] = 1;
                                }
                             }
                        }

                    }
                    $museums[$museum_id][$ticket_id]['tps_id'] = $ticket_count;
                }
            }

            foreach ($museums as $key => $value1) {

                $notification_mail_contents = array();
                $ticket_booking_id = "";
                foreach ($value1 as $key2 => $value) {
                    $notification_emails = array();
                    $ticket_detail_for_supplier_notification = array();
                    /* for priohub suppliers */
                    if($value['market_merchant_id'] == '2' && in_array($channel_type, $api_channel_type)) {
                        $subject = 'Booking confirmed through priohub';
                    } else {
                        $subject = 'Booking confirmed through Prioticket';
                    }
                    $notification_emails[] = $value['museum_email'];
                    $additional_email = array();
                    $additional_email = explode("::", $value['museum_additional_email']);
                    $notification_emails = array_unique(array_merge($notification_emails, $additional_email));
                    $attachments = array();
                    $ticket_detail_for_supplier_notification['ticket_id'] = $key2;
                    $ticket_detail_for_supplier_notification['museum_name'] = $value['museum_name'];
                    $ticket_detail_for_supplier_notification['museum_id'] = $value['museum_id'];
                    $ticket_detail_for_supplier_notification['visitor_group_no'] = $visitor_group_no;
                    $ticket_detail_for_supplier_notification['ticket_title'] = $value['ticket_title'];
                    $ticket_detail_for_supplier_notification['related_product_id'] = $value['related_product_id']; 
                    $ticket_detail_for_supplier_notification['extra_text_field'] = $value['extra_text_field'];
                    $ticket_detail_for_supplier_notification['extra_text_field_answer'] = $value['extra_text_field_answer'];
                    $ticket_detail_for_supplier_notification['selected_date'] = $value['selected_date'];
                    $ticket_detail_for_supplier_notification['from_time'] = $value['from_time'];
                    $ticket_detail_for_supplier_notification['to_time'] = $value['to_time'];
                    $ticket_detail_for_supplier_notification['is_reservation'] = $value['is_reservation'];
                    $ticket_detail_for_supplier_notification['pickups_data'] = $value['pickups_data'];
                    $ticket_detail_for_supplier_notification['market_merchant_id'] = $value['market_merchant_id'];
                    $ticket_booking_id = $value['ticket_booking_id'];
                    $ticket_detail_for_supplier_notification['booking_email_text'] = !empty($value['booking_email_text'])?$value['booking_email_text']:'';

                    if ($value['room_no'] != '') {
                        $ticket_detail_for_supplier_notification['room_no'] = $value['room_no'];
                    }
                    if ($value['guest_email'] != '') {
                        $ticket_detail_for_supplier_notification['guest_email'] = $value['guest_email'];
                    }
                    if ($value['guest_names'] != '') {
                        $ticket_detail_for_supplier_notification['guest_names'] = $value['guest_names'];
                    }
                    if ($value['phone_number'] != '') {
                        $ticket_detail_for_supplier_notification['phone_number'] = $value['phone_number'];
                    }
                    if ($value['client_reference'] != '') {
                        $ticket_detail_for_supplier_notification['client_reference'] = $value['client_reference'];
                    }
                    if (!empty($value['api_data'])) {
                        $ticket_detail_for_supplier_notification['api_data'] = $value['api_data'];
                    }
                    if (isset($value['travelerHotel'])&& $value['travelerHotel'] != '') {
                        $ticket_detail_for_supplier_notification['travelerHotel'] = $value['travelerHotel'];
                    }
                    if (isset($value['gender'])&& $value['gender'] != '') {
                        $ticket_detail_for_supplier_notification['gender'] = $value['gender'];
                    }
                    if (isset($value['country_of_residence'])&& $value['country_of_residence'] != '') {
                        $ticket_detail_for_supplier_notification['country_of_residence'] = $value['country_of_residence'];
                    }
                    if (isset($value['note'])&& $value['note'] != '') {
                        $ticket_detail_for_supplier_notification['note'] = $value['note'];
                    }
                    $age_groups = array();
                    foreach ($value['tps_id'][$key][$key2] as $key1 => $data1) {
                        $age_group['tps_id'] = $data1['ticketpriceschedule_id'];
                        $age_group['ticket_id'] = $key2 . '_' . $data1['ticketpriceschedule_id'];
                        $age_group['count'] = $data1['count'];
                        $age_group['ticket_type'] = $data1['type'];
                        $age_group['pax'] = $data1['pax'];
                        if(!empty($data1['extra_booking_information_api'])) {
                            $age_group['extraBookIngInformationApi'] = $data1['extra_booking_information_api'];
                        } 
                        $age_groups[$key2][] = $age_group;
                    }                    
                    $this->CreateLog('api_test_one.php', 'step-4', array('api_test_data' => json_encode($value['age_group'])));
                    $ticket_detail_for_supplier_notification['age_groups'] = $age_groups;
                    $ticket_detail_for_supplier_notification['hotel_name'] = $hotel_name;
                    $ticket_detail_for_supplier_notification['slot_type'] = $value["slot_type"];
                    $notification_emails = array_filter($notification_emails);
                    if (!empty($notification_emails)) {
                        foreach ($notification_emails as $notification_email) {
                            $notification_mail_contents[$notification_email][] = $ticket_detail_for_supplier_notification;
                        }
                    }
                }
                $this->CreateLog('notification_data.php', 'step-1', array('$notification_mail_contents' => json_encode($notification_mail_contents) , 'visitr_group_np' => $visitor_group_no));
                /**  Here agen name start here */ 
                $guest_contacts = array();
                if(!empty($data['order_contacts'])) {
                    foreach ($data['order_contacts'] as $contact) {
                        $condition = ['where' => 'contact_uid="'.$contact['contact_uid'].'" and version='.$contact['version'],'order_by' => 'version desc'];  
                        $data = $this->find('guest_contacts', $condition, 'object', 1);
                        if (empty($data)) {
                            if (isset($contact['version']) && $contact['version'] > 0) {
                                unset($contact['visitor_group_no']);
                            } else {
                                $contact['version'] = 1;
                            }
                            $guest_contacts[] = array_filter($contact, function($value){
                                    return isset($value);
                            });
                        }
                    }
                    if(!empty($guest_contacts)) {
                        $main_insert_data =  array_values($guest_contacts);
                        foreach ($main_insert_data as $insert_data) {
                            if(!empty($insert_data['type']) && $insert_data['type'] == 8 && !empty($insert_data['email'])) {
                                $agentEmailData = $insert_data['email'];
                            }
                        }
                    }                   
                    $this->CreateLog('api_test_one.php', 'step-final', array('guest_contacts' => json_encode($guest_contacts)));
                }         
               
                $extra_options_per_ticket = array();                   
                foreach ($prepaid_extra_options_data as $extra_option) {
                    if(!empty($extra_option['from_time']) && !empty($extra_option['to_time'])) {
                        $extra_options_per_ticket[$extra_option['ticket_id'].'-'.$extra_option['ticket_booking_id'].'-'.$extra_option['selected_date'].'-'.$extra_option['from_time'].'-'.$extra_option['to_time']][$extra_option['description']][] = $extra_option['quantity'];
                    } else {
                        $extra_options_per_ticket[$extra_option['ticket_id'].'-'.$extra_option['ticket_booking_id']][$extra_option['description']][] = $extra_option['quantity'];
                    }
                }

                if (!empty($notification_mail_contents)) {
                    $this->CreateLog('notification_data.php', 'step-1.1', array('visitr_group_np' => $visitor_group_no ));

                    $arraylist = array(
                        "notification_event" => "ORDER_CREATE_SUPPLIER",
                        "visitor_group_no" => $visitor_group_no,
                        "booking_reference" => $ticket_booking_id
                    );

                    $return = $this->sendemail_model->sendSupplierNotification($arraylist);
                    if($return) {
                        $this->CreateLog('notification_data.php', 'step-1.2', array('visitr_group_np' => $visitor_group_no));
                    }
                }

                /**  Here agent  name end here */                
                foreach ($notification_mail_contents as $email => $mail_content) {

                    if(empty($mail_content) || empty($email)) {
                        continue;
                    }
                    if(!empty($agentEmailData)) {
                        $content_data['agentEmail'] = $agentEmailData;
                    }
                    $content_data['mail_content'] = $mail_content;
                    $content_data['extra_services'] = $extra_service_options_for_email;
                    $content_data['distributor_name'] = $hotel_name;
                    $content_data['extra_options_per_ticket'] = $extra_options_per_ticket;
                    $content_data['market_merchant_id'] = $market_merchant_id;
                    $this->CreateLog('notification_data.php', 'step-2', array('email' => $email ,  '$extra_service_options_for_email' => json_encode($extra_service_options_for_email) , 'visitr_group_np' => $visitor_group_no));
                    $this->CreateLog('notification_data.php', 'step-2.0', array('visitr_group_np' => $visitor_group_no));
                    $arraylist['emailc'] = trim($email);
                    if($market_merchant_id == '2' && in_array($channel_type, $api_channel_type)) {
                        $arraylist['from'] = PRIOHUB_SUPPLIER_EMAIL_FROM;
                        $arraylist['fromname'] = PRIOHUB_SUPPLIER_EMAIL_FROM_NAME;
                        $arraylist['market_merchant_id'] = "2";
                    } else {
                        $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
                        $arraylist['fromname'] = MESSAGE_SERVICE_NAME;
                        $arraylist['market_merchant_id'] = "1";
                    }
                    $arraylist['market_merchant_id'] = $market_merchant_id;
                    $arraylist['museum_id'] = $key;
                    $arraylist['subject'] = $subject;
                    $arraylist['attachments'] = $attachments;
                    $arraylist['BCC'] = array();
                    $arraylist['reseller_id'] = $hotel_info->reseller_id;
                    $arraylist['visitor_group_no'] = $visitor_group_no;                                         
                    $this->CreateLog('notification_data.php', 'step-2.1', array('visitr_group_np' => $visitor_group_no));
                    if ($email != '' && false) {
                        $this->CreateLog('notification_data.php', 'step-2.2', array('visitr_group_np' => $visitor_group_no));
                        $return = $this->sendemail_model->sendSupplierNotification($arraylist);
                        if($return) {
                            $this->CreateLog('notification_data.php', 'step-3', array('visitr_group_np' => $visitor_group_no));
                        }                        
                    }
                }
            }
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_order_status_check_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
        /* #endregion to Send email to museum's regarding tickets purchased, if (inapp pos case, adam website case, pospaid ticket case */  
        
        
        //Firebase Updations
        if($channel_type == 10 || $channel_type == 11) {
            $this->update('mpos_requests', array('status' => 1), array('visitor_group_no' => $visitor_group_no));
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_mpos_requests_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
        // Update tickets at distributor level in Firebase if venue_app active for the user
        if (SYNC_WITH_FIREBASE == 1 && $is_secondary_db != 4) {
            $firebase_tickets = explode(',', FIREBASE_TICKET_IDS);
            $update_firebase = 0;
            if($upsell_order == 1) {
                $update_firebase = 1;
            }
            $hotel_data = $this->find('qr_codes', array('select' => 'is_venue_app_active', 'where' => 'cod_id = "' . $pt_hotel_id . '"'));
            if ($hotel_data[0]['is_venue_app_active'] == 1 || $upsell_third_party_order) {
                if (isset($data['prepaid_extra_options_data']) && !empty($data['prepaid_extra_options_data'])) {
                    foreach ($data['prepaid_extra_options_data'] as $option) {
                        $this->CreateLog('order_sync.php', '12booking_array-  ' . $option['ticket_id'] . '__' . $update_firebase, array('tickets' => json_encode($firebase_tickets)));
                        if ($option['selected_date'] == '' || $option['selected_date'] == '0') {
                            $option['selected_date'] = '';
                            $option['from_time'] = '';
                            $option['to_time'] = '';
                        }
                        $key = $option['visitor_group_no'] . '_' . $option['ticket_id'] . '_' . $option['selected_date'] . '_' . $option['from_time'] . '_' . $option['to_time'] . '_' . $option['created'];
                        $extra_option_price[$key] = array(
                            'price' => $extra_option_price[$key]['price'] + ($option['price'] * $option['quantity'])
                        );
                        if($option['ticket_price_schedule_id'] == "0") {
                            $per_ticket_extra_options[$key][$option['description']] = array(
                                'main_description' => $option['main_description'],
                                'description' => $option['description'],
                                'quantity' => (int)$option['quantity'],
                                'refund_quantity' => (int)0,
                                'price' => (float)$option['price'],
                            );
                        } else {
                            $per_age_group_extra_options[$key][$option['ticket_price_schedule_id']][$option['description']] = array(
                                'main_description' => $option['main_description'],
                                'description' => $option['description'],
                                'quantity' => (int)$option['quantity'],
                                'refund_quantity' => (int)0,
                                'price' => (float)$option['price'],
                            );
                        }
                    }
                }
                if (isset($data['prepaid_tickets_data']) && !empty($data['prepaid_tickets_data'])) {
                    $notify_data = array();
                    foreach ($data['prepaid_tickets_data'] as $prepaid_data) {
                        $booking_date = $prepaid_data['selected_date']; 
                        $booking_time = $prepaid_data['from_time'];

                        if ($prepaid_data['selected_date'] == '' || $prepaid_data['selected_date'] == '0') {
                            $prepaid_data['selected_date'] = '';
                            $prepaid_data['from_time'] = '';
                            $prepaid_data['to_time'] = '';
                        }
                        $key = base64_encode($prepaid_data['visitor_group_no'] . '_' . $prepaid_data['ticket_id'] . '_' . $prepaid_data['selected_date'] . '_' . $prepaid_data['from_time'] . '_' . $prepaid_data['to_time'] . '_' . $prepaid_data['created_date_time']);
                        $cashier_id = $prepaid_data['cashier_id'];
                        $hotel_id = $prepaid_data['hotel_id'];
                        $created_date_time = $prepaid_data['created_date_time'];
                        if ($prepaid_data->activation_method == '0') {
                            if ($bookings_listing['is_bill_to_hotel'] == 0) {
                                $prepaid_data->activation_method = '1';
                            } else {
                                $prepaid_data->activation_method = '3';
                            }
                        } 
                        if ($prepaid_data['channel_type'] == 3) {
                            if ($prepaid_data['without_elo_reference_no'] != '') {
                                $order_reference = $prepaid_data['without_elo_reference_no'];
                            }
                        } else {
                            if ($bookings_listing['room_no'] != '') {
                                $order_reference = $bookings_listing['room_no'];
                            }
                        }
                        
                        if ($prepaid_data['channel_type'] == 10 || $prepaid_data['channel_type'] == 11) {
                            $update_firebase = '1';
                        }
                        $passes[$prepaid_data['tps_id']][$prepaid_data['passNo']] = $prepaid_data['passNo']; // sync extended ticket passes to firebase
                        sort($per_age_group_extra_options[$key][$prepaid_ticket['ticket_price_schedule_id']]);
                        $tickets_type[$key][$prepaid_data['tps_id']] = array(
                            'tps_id' => (int)$prepaid_data['tps_id'],
                            'tax' => (float) $prepaid_data['tax'],
                            'age_group' => $prepaid_data['age_group'],
                            'price' => (float) $prepaid_data['price'],
                            'net_price' => (float) $prepaid_data['net_price'],
                            'type' => $prepaid_data['ticket_type'],
                            'quantity' => (int) $tickets_type[$key][$prepaid_data['tps_id']]['quantity'] + 1,
                            'combi_discount_gross_amount' => (float) $prepaid_data['combi_discount_gross_amount'],
                            'refund_quantity' => (int) 0,
                            'refunded_by' => array(),
                            'passes' =>  array_values($passes[$prepaid_data['tps_id']]),
                            'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$key][$prepaid_ticket['ticket_price_schedule_id']])) ? $per_age_group_extra_options[$sync_key][$prepaid_ticket['ticket_price_schedule_id']] : array(),
                        );
                        sort($per_ticket_extra_options[$key]);
                        $additional_info = unserialize($prepaid_data['additional_information']);
                        $actual_seller = isset($additional_info['actual_seller']) ? $additional_info['actual_seller'] : 0;
                        $extra_price = (isset($extra_option_price[$prepaid_data['visitor_group_no'] . '_' . $prepaid_data['ticket_id']]) && !empty($extra_option_price[$prepaid_data['visitor_group_no'] . '_' . $prepaid_data['ticket_id']])) ? $extra_option_price[$prepaid_data['visitor_group_no'] . '_' . $prepaid_data['ticket_id']]['price'] : 0;
                        if ((isset($prepaid_data['is_addon_ticket']) && $prepaid_data['is_addon_ticket'] != "2") || !isset($prepaid_data['is_addon_ticket'])) {
                            $bookings_array[$key] = array(
                                'booking_date_time' => $prepaid_data['created_date_time'],
                                'shift_id' => (int) $prepaid_data['shift_id'],
                                'reservation_date' => ($prepaid_data['from_time'] != '' && $prepaid_data['from_time'] != '0' && $prepaid_data['to_time'] != '' && $prepaid_data['to_time'] != '0') ? $prepaid_data['selected_date'] : "",
                                'main_ticket_id' => !empty($main_ticket_id) ?(int) $main_ticket_id : 0,
                                'from_time' => ($prepaid_data['from_time'] != '' && $prepaid_data['from_time'] != '0') ? $prepaid_data['from_time'] : "",
                                'to_time' => ($prepaid_data['to_time'] != '' && $prepaid_data['to_time'] != '0') ? $prepaid_data['to_time'] : "",
                                'order_id' => $prepaid_data['visitor_group_no'],
                                'reference' => $order_reference,
                                'museum' => $prepaid_data['museum_name'],
                                'ticket_id' => (int) $prepaid_data['ticket_id'],
                                'pos_point_id' => (int) $prepaid_data['pos_point_id'],
                                'pos_point_name' => $prepaid_data['pos_point_name'],
                                'cashier_register_id' => $prepaid_data['cashier_register_id'],
                                'group_id' => (int) $additional_info['group_id'],
                                'group_name' => ($additional_info['group_id'] > 0) ? $additional_info['group_name'] : '',
                                'cashier_name' => $prepaid_data['cashier_name'],
                                'channel_type' => (int) $prepaid_data['channel_type'],
                                'is_discount_code' => (int) $prepaid_data['is_discount_code'],
                                'discount_code_amount' => (float) $prepaid_data['discount_code_amount'],
                                'service_cost_type' => (int) $prepaid_data['service_cost_type'],
                                'service_cost_amount' => (float) $prepaid_data['service_cost'],
                                'timezone' => (int) $prepaid_data['timezone'],
                                'status' => (int) 2,
                                'ticket_title' => $prepaid_data['title'],
                                'is_reservation' => (int) ($prepaid_data['selected_date'] != 0 && $prepaid_data['selected_date'] != '' && $prepaid_data['from_time'] != '' && $prepaid_data['from_time'] != 0) ? (int) 1 : (int) 0,
                                'booking_name' => ($bookings_listing['guest_names'] != '') ? $bookings_listing['guest_names'] : '',
                                "merchant_reference" => ($bookings_listing['merchant_reference'] != '') ? $bookings_listing['merchant_reference'] : "",
                                'payment_method' => (int) $prepaid_data['activation_method'],
                                'quantity' => (int) $bookings_array[$key]['quantity'] + 1,
                                'amount' => (float) ($bookings_array[$key]['amount'] + $prepaid_data['price'] + $extra_price),
                                'cancelled_tickets' => (int) 0,
                                'change' => (int) 0,
                                'activated_by' => (int) 0,
                                'activated_at' => (int) 0,
                                'slot_type' => ($prepaid_data['timeslot'] != '' ) ? $prepaid_data['timeslot'] : "",
                                'is_combi_ticket' => !empty($prepaid_data['is_combi_ticket']) ?(int) $prepaid_data['is_combi_ticket'] : 0,
                                'pass_type' => !empty($prepaid_data['pass_type']) ? (int) $prepaid_data['pass_type'] : 0,
                                'is_extended_ticket' => ($upsell_order == "1") ? (int) 1: (int)0,
                                'ticket_types' => (!empty($tickets_type[$key])) ? $tickets_type[$key] : array(),
                                'per_ticket_extra_options' => (!empty($per_ticket_extra_options[$key])) ? $per_ticket_extra_options[$key] : array(),
                                'passes' => ($bookings_array[$key]['passes'] != '' && $prepaid_data['is_combi_ticket'] != 1) ? $bookings_array[$key]['passes'] . ', ' . $prepaid_data['passNo'] : $prepaid_data['passNo']
                            );
                            unset($extra_option_price[$prepaid_data['visitor_group_no'].'_'.$prepaid_data['ticket_id']]);
                        }
                        //data for third party tickets for CSS notify 
                        if(($prepaid_data['channel_type'] == '10' || $prepaid_data['channel_type'] == '11') && $details['modeventcontent'][$prepaid_data['ticket_id']]->third_party_id == 5){
                            $notify_key = $prepaid_data['ticket_id'].'_'.$booking_date.'_'.$booking_time; 
                            $notify_data[$notify_key]['request_type'] = 'booking';
                            $notify_data[$notify_key]['booking_data']['distributor_id'] = $prepaid_data['hotel_id'];
                            $notify_data[$notify_key]['booking_data']['channel_type'] = $prepaid_data['channel_type'];
                            $notify_data[$notify_key]['booking_data']['ticket_id'] = $prepaid_data['ticket_id'];
                            $notify_data[$notify_key]['booking_data']['booking_date'] = $booking_date;
                            $notify_data[$notify_key]['booking_data']['booking_time'] = $booking_time;
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$prepaid_data['tps_id']]['tps_id'] = (int) $prepaid_data['tps_id']; 
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$prepaid_data['tps_id']]['ticket_type'] = $prepaid_data['ticket_type']; 
                            $notify_data[$notify_key]['booking_data']['ticket_type'][$prepaid_data['tps_id']]['count'] += 1; 
                            $notify_data[$notify_key]['booking_data']['booking_reference'] = isset($order_reference) ? $order_reference : '';
                            $notify_data[$notify_key]['booking_data']['barcode'] = $prepaid_data['passNo'];
                            $notify_data[$notify_key]['booking_data']['integration_booking_code'] =  $prepaid_data['visitor_group_no'];
                            $notify_data[$notify_key]['booking_data']['customer']['name'] = '';
                            $notify_data[$notify_key]['third_party_data']['agent'] = 'CEXcursiones';  
                        }
                    }
                    
                    if(!empty($notify_data)) {
                        foreach($notify_data as $key => $data) {
                            sort($data['booking_data']['ticket_type']);
                            $notify_data[$key] = $data;
                        }
                        sort($notify_data);
                        $this->CreateLog('order_sync.php', 'data_notify' , array(json_encode($notify_data)));
                        $request_string = json_encode($notify_data);
                        $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                        $queueUrl = THIRD_PARTY_NOTIFY_QUEUE;
                        if (SERVER_ENVIRONMENT == 'Local') {
                            local_queue($aws_message, 'THIRD_PARTY_NOTIFY_ARN');
                        } else {
                            $this->load->library('Sqs');
                            $sqs_object = new Sqs();
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                            if ($MessageId) {
                                $this->load->library('Sns');
                                $sns_object = new Sns();
                                $sns_object->publish($MessageId.'#~#'.$queueUrl, THIRD_PARTY_NOTIFY_ARN); //SNS link
                            }
                        }
                    }

                    
                    $headers = $this->all_headers_new('Order_process_completed' , $ticket_id , '' ,'' ,$cashier_id);
                    
                    $logs[$update_firebase.'sync_firebase_for_'. $prepaid_data['visitor_group_no']] = $bookings_array;
                    if($update_firebase == "1" && !empty($bookings_array) && ($mpos_postpaid_order != 1)) {
                        if(isset($order_type) && $order_type == 'firebase_order' && ($upsell_order != 1)) {
                            foreach($bookings_array as $key => $booking) {
                                $update_values = array(
                                    'status' => (int) 2
                                );
                                $params = json_encode(array("node" => 'distributor/bookings_list/' . $hotel_id . '/' . $cashier_id . '/' . date("Y-m-d", strtotime($created_date_time)).'/'.$key, 'details' => $update_values));
                                $logs['sync_req_1'] = $params;
                                $ch = curl_init();
                                
                                
                                $headers = $this->all_headers_new('update_bookings_list_for_2_from_SQS' , '' , $hotel_id, '' , $cashier_id);

                                curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                                $getdata = curl_exec($ch);
                                $logs['sync_res_1'] = $getdata;
                                curl_close($ch);
                            
                                $update_order_list = array(
                                    'status' => (int) 2
                                );
                                
                                
                                $headers = $this->all_headers_new('update_orders_list_for_2_from_SQS' , '' , $hotel_id, '' , $cashier_id);

                                $params = json_encode(array("node" => 'distributor/orders_list/' . $hotel_id . '/' . $cashier_id . '/' . gmdate("Y-m-d") . '/' . $booking['order_id'], 'details' => $update_order_list));
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                                curl_exec($ch);
                                curl_close($ch);
                            }
                        } else {
                            $params = json_encode(array("node" => 'distributor/bookings_list/' . $hotel_id . '/' . $cashier_id . '/' . date("Y-m-d", strtotime($created_date_time)), 'details' => $bookings_array));
                            $logs['sync_req_2'] = $params;
                            $ch = curl_init();
                            

                            $headers = $this->all_headers_new('update_bookings_list_for_2_from_SQS_for_upsell' , '' , $hotel_id ,'' ,$cashier_id);

                            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            $getdata = curl_exec($ch);
                            $logs['sync_res_1'] = $getdata;
                            curl_close($ch);
                        
                            $update_order_list = array(
                                'status' => (int) 2
                            );
                            
                            

                            $headers = $this->all_headers_new('update_orders_list_for_2_from_SQS_for_upsell' , '' , $hotel_id, '' , $cashier_id);

                            $params = json_encode(array("node" => 'distributor/orders_list/' . $hotel_id . '/' . $cashier_id . '/' . gmdate("Y-m-d") . '/' . $visitor_group_no, 'details' => $update_order_list));
                            $logs['sync_req1'] = $params;
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            $getdata = curl_exec($ch);
                            curl_close($ch);
                        }
                    } else {
                        $params = json_encode(array("node" => 'distributor/bookings_list/' . $hotel_id . '/' . $cashier_id . '/' . date("Y-m-d", strtotime($created_date_time)), 'details' => $bookings_array));
                        $logs['sync_req_2'] = $params;
                        $ch = curl_init();
                        

                        $headers = $this->all_headers_new('update_bookings_list_for_2_from_SQS_case3' , '' , $hotel_id, '' , $cashier_id);

                        curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        $getdata = curl_exec($ch);
                        $logs['sync_res_2'] = $getdata;
                        curl_close($ch);
                    }
                }
                if (((isset($order_type) && $order_type == 'firebase_order') || $upsell_order == "1") && (!empty($sync_all_tickets['ticket_id']))) {
                    $ticket_sold_details = $this->find('pos_tickets', array('select' => 'mec_id, latest_sold_date, per_day_sold_count, ticket_sold_count, museum_id', 'where' => 'hotel_id = "' . $hotel_id . '" and is_pos_list="1" and mec_id in (' . implode(',', $sync_all_tickets['ticket_id']) . ')'));
                    foreach ($ticket_sold_details as $ticket) {
                        try {
                            

                            $headers = $this->all_headers_new('sold_count_update_on_Order_process' , $ticket['mec_id']);

                            $update_values = array(
                                'latest_sold_date' => date("Y-m-d", strtotime($ticket['latest_sold_date'])),
                                'per_day_sold_count' => (int) $ticket['per_day_sold_count'],
                                'ticket_sold_count' => (int) $ticket['ticket_sold_count'],
                            );
                            $params = json_encode(array("node" => 'distributor/tickets/' . $hotel_id . '/' . $ticket['mec_id'] . '/' , 'details' => $update_values));
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            $getdata = curl_exec($ch);
                            curl_close($ch);
                        } catch (Exception $e) {

                        }
                    }
                }
                $this->CreateLog('firebase_user_type', __CLASS__.'-'.__FUNCTION__, array('user_type' => $this->session->userdata['user_type'], 'cashier_email' => $this->session->userdata['uname'], 'cashier_type' => $this->get_cashier_type_from_tokenId()));
            }
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_SYNC_WITH_FIREBASE_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);
        /* Check pending request from firebase and process in queue for sending email and cancel order */
        $firebase_pending_request = $this->find('firebase_pending_request', array('select' => '*', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and request_type != "scaning_actions"'));       
        $logs['firebase_pending_request_data'] = $firebase_pending_request;
        $logs['db__host'] = array($is_secondary_db, $_SERVER['HTTP_HOST']);
        if (!empty($firebase_pending_request) && $is_secondary_db != 4 && !strstr($_SERVER['HTTP_HOST'],'10.10.10.')) {
            $this->CreateLog('send_email_from_queue.php', 'pending_all_req', array('pending_all_request' => json_encode($firebase_pending_request)));
            foreach($firebase_pending_request as $pending_request) {
                $this->CreateLog('send_email_from_queue.php', 'pending_req', array('req' => $pending_request['request_type'],'pending_request' => json_encode($pending_request)));
                $request_details = json_decode($pending_request['request']);
                if($pending_request['request_type'] == 'send-email') {
                    $request_details = array(
                        'request_type' => $pending_request['request_type'],
                        'pdf_type' => '1',
                        'visitor_group_no' => $pending_request['visitor_group_no'],
                        'hotel_id' => $bookings_listing['hotel_id'],
                        'flag' => '2',
                        'email' => $request_details->email
                    );
                    // Load SQS library.
                    $aws_message = json_encode($request_details);
                    $this->CreateLog('send_email_from_queue.php', 'send email for firebase order', array('request' => $aws_message));
                    $aws_message = base64_encode(gzcompress($aws_message));
                    $queueUrl = FIREBASE_EMAIL_QUEUE; // live_event_queue
                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                    if($MessageId != false) {
                        $this->load->library('Sns');
                        $sns_object = new Sns();
                        $sns_object->publish($MessageId.'#~#'.$queueUrl, FIREBASE_EMAIL_TOPIC);
                    }
                }
                if($pending_request['request_type'] == 'cancel-order') {
                    $hotel_details = $this->find('qr_codes', array('select' => 'token', 'where' => 'cod_id = "'.$hotel_id.'"'));
                    $cancel_request_details = array(
                        'order_id' => $request_details->order_id,
                        'cashier_id' => $request_details->cashier_id,
                        'hotel_id' => $hotel_id,
                        'cashier_type' => $request_details->cashier_type
                    );
                    $this->createLog('cancel_pending_order.php', 'cancel-order 11..  '.$hotel_id.'---'.$hotel_details[0]['token'].'_____'.$this->base_url, array());
                    $api_url = $this->mpos_api_server.'/firebase/cancel_order';                  
                    $this->createLog('cancel_pending_order.php', base_url() . 'url: ' . $api_url, array('hotel_id' => $hotel_id, 'token' => $hotel_details[0]['token'], 'request' => json_encode($request_details)));
                    try {
                        $headers = array(
                            'Content-Type: application/json',
                            'Authorization: ' . REDIS_AUTH_KEY
                        );
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                        curl_setopt($ch, CURLOPT_USERPWD, $hotel_id.":".$hotel_details[0]['token']);
                        curl_setopt($ch, CURLOPT_URL, $api_url);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancel_request_details));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        $getdata = curl_exec($ch);
                        $this->createLog('cancel_pending_order.php', 'res', array('res' => $getdata));
                        curl_close($ch);
                    } catch (Exception $e) {

                    }
                }
            }
            $logs['firebase_pending_request_deleted_for_vgn_'] = $visitor_group_no;
            $this->delete('firebase_pending_request', array('visitor_group_no' => $visitor_group_no));
        }
        $this->CreateLog('Mpos_orders_logs.php', 'Below_firebase_pending_request_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);

        if(isset($data['add_new_rows']) && $data['add_new_rows'] == '1') {
            if($visitor_group_no != '') {
                $this->secondarydb->db->query('update visitor_tickets set action_performed = CONCAT(action_performed, ", CPOS_EOL") where vt_group_no = "'.$visitor_group_no.'"');
            }
        }   
        $this->CreateLog('Mpos_orders_logs.php', 'Below_visitor_tickets_updation_'.date("H:i:s.u"), array("db" => $is_secondary_db,"order" => $mpos_order_id), $channel_type);     
        $result_array = array();
        $result_array['sns_message'] = $sns_message;
        $result_array['final_visitor_data_to_insert_big_query_transaction_specific'] = $final_visitor_data_to_insert_big_query_transaction_specific;
        $result_array['final_prepaid_data_to_insert_big_query'] = $final_prepaid_data;
        $result_array['flag'] = $flag;
        $result_array['notify_api_flag'] = $notify_api_flag;
        $result_array['notify_api_array'] = $notify_api_array;
        $MPOS_LOGS['insertdata'] = $logs;
        /* Notifying through api3.3 */
       /* if(!empty($notify_api_array) && $notify_api_flag > 0 && !empty($is_secondary_db) && $is_secondary_db == '1') {
            $this->load->model('api_model');
            $this->api_model->sendOrderProcessNotification($notify_api_array);
        } */
        return $result_array;
    }
    /* #endregion function To insert data of main tables in secondary DB.*/

    /* #region to set entities details  */
    /**
    * @name set_entity .
    * @called from insert_in_db and insert_in_db_direct form Pos controller. 
    * @param array $prepaid_tickets_data_array.
    * @purpose : This function use to set entity.
    * @created by : Neha<nehadev.aipl@gamil.com>
    * @created at: 07 July 2020
    */
    function set_entity($data) {
        $flag_entities['supplier_id'] = $flag_entities['ticket_id'] = $flag_entities['partner_id'] = $flag_entities['distributor_id'] = $flag_entities['contact_uid'] = $flag_entities['reseller_id'] = $flag_entities['cashier_id'] = $flag_entities['timeslot_id'] = $flag_entities['shift_id'] = [];
        if (!empty($data['prepaid_tickets_data'])) {
            foreach ($data['prepaid_tickets_data'] as $prepaid_tickets_data) {
                $shared_capacity_id = '';
                if (!isset($data['uncancel_order'])) {
                    if (!empty($prepaid_tickets_data['distributor_partner_id']) && !in_array($prepaid_tickets_data['distributor_partner_id'], $flag_entities['partner_id'])) {
                        $flag_entities['partner_id'][] = $prepaid_tickets_data['distributor_partner_id'];
                    }
                    if (!empty($prepaid_tickets_data['hotel_id']) && !in_array($prepaid_tickets_data['hotel_id'], $flag_entities['distributor_id'])) {
                        $flag_entities['distributor_id'][] = $prepaid_tickets_data['hotel_id'];
                    }
                    if (!empty($prepaid_tickets_data['reseller_id']) && !in_array($prepaid_tickets_data['reseller_id'], $flag_entities['reseller_id'])) {
                        $flag_entities['reseller_id'][] = $prepaid_tickets_data['reseller_id'];
                    }
                    if (!empty($prepaid_tickets_data['cashier_id']) && !in_array($prepaid_tickets_data['cashier_id'], $flag_entities['cashier_id'])) {
                        $flag_entities['cashier_id'][] = $prepaid_tickets_data['cashier_id'];
                    }
                    /* start block for contiki product flag. */
                    if(!empty($prepaid_tickets_data['extra_booking_information'])){
                        $prepaid_extra_booking_information = json_decode(stripslashes(stripslashes($prepaid_tickets_data['extra_booking_information'])), true);
                        if($prepaid_extra_booking_information['order_custom_fields']){
                            $contiki_flag_product_id = 0;
                            foreach ($prepaid_extra_booking_information['order_custom_fields'] as $custom_fields){
                                if(!empty($custom_fields['custom_field_name']) && !empty($custom_fields['custom_field_value']) && $custom_fields['custom_field_name'] == "contiki_flag_product_id"){
                                    $flag_entities['ticket_id'][] = $custom_fields['custom_field_value']; 
                                    $flag_entities['contiki_product'][$prepaid_tickets_data['ticket_id']][] = $custom_fields['custom_field_value'];
                                    $contiki_flag_product_id++;
                                }
                            }
                            /* check details for order_reference if contiki product set. */
                            if ($contiki_flag_product_id > 0) {
                                foreach ($prepaid_extra_booking_information['order_custom_fields'] as $custom_fields) {
                                    if (!empty($custom_fields['custom_field_name']) && !empty($custom_fields['custom_field_value']) && $custom_fields['custom_field_name'] == "main_reservation_reference") {
                                        $flag_entities['main_reservation_reference'][] = $custom_fields['custom_field_value'];
                                    }
                                }
                            }
                        }
                    }
                    /* end block for contiki product flag. */
                }
                if (!empty($prepaid_tickets_data['museum_id']) && !in_array($prepaid_tickets_data['museum_id'], $flag_entities['supplier_id'])) {
                    $flag_entities['supplier_id'][] = $prepaid_tickets_data['museum_id'];
                }
                if (!empty($prepaid_tickets_data['ticket_id']) && !in_array($prepaid_tickets_data['ticket_id'], $flag_entities['ticket_id'])) {
                    $flag_entities['ticket_id'][] = $prepaid_tickets_data['ticket_id'];
                }
                if (!empty($prepaid_tickets_data['shift_id']) && !in_array($prepaid_tickets_data['shift_id'], $flag_entities['shift_id'])) {
                    $flag_entities['shift_id'][] = $prepaid_tickets_data['shift_id'];
                }
                if (!empty($prepaid_tickets_data['shared_capacity_id'])) {
                    $shared_capacity_ids = explode(',', $prepaid_tickets_data['shared_capacity_id']);
                    foreach ($shared_capacity_ids as $shared_capacity_id) {
                        $timeslot_id = '';
                        $timeslot_id = (string) $this->getAvailabilityTimeslotId($prepaid_tickets_data['selected_date'], $prepaid_tickets_data['from_time'], $prepaid_tickets_data['to_time'], $shared_capacity_id);
                        $flag_entities['timeslot_id'][] = str_replace(' ', '', $timeslot_id);
                    }
                }
            }
        }
        return $flag_entities;
        
    }
    /* #endregion to to set entities details.*/
    
    /* #region to get entities flag details  */
   /**
    * @name get_flags_details .
    * @called from insert_in_db and insert_in_db_direct form Pos controller. 
    * @param array $flag_entities.
    * @purpose : This function to get entities flag details from flags and flag_entities table.
    * @created by : Neha<nehadev.aipl@gamil.com>
    * @created at: 07 July 2020
    */
    function get_flags_details($flag_entities = []) {
        $flag_entity_details = $flag_entity_sorted_details = [];
        $count = 0;
        $this->createLog('flag_logs.php', 'flag_entities', array("data" => json_encode($flag_entities)));
        $db = $this->primarydb->db;
        $db->select("fe.entity_id, fe.entity_type, f.name, f.value, f.id, f.flag_uid, f.type ");
        $db->from("flag_entities fe");
        $db->join('flags f', 'fe.item_id = f.id', 'left');
        $db->where('f.is_deleted = "0" AND f.status = "1" AND fe.deleted = "0"');
        $where ='(';
        if (!empty($flag_entities['supplier_id'])) {
            $count = 1;
            $where .= '(fe.entity_type = "1" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['supplier_id'])) . '"))';
        }
        if (!empty($flag_entities['reseller_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "2" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['reseller_id'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "2" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['reseller_id'])) . '"))';
            }
        }
        if (!empty($flag_entities['distributor_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "3" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['distributor_id'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "3" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['distributor_id'])) . '"))';
            }
        }
        if (!empty($flag_entities['partner_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "4" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['partner_id'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "4" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['partner_id'])) . '"))';
            }
        }
        if (!empty($flag_entities['ticket_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "5" AND fe.entity_id IN ("' . implode('", "', array_map('trim',$flag_entities['ticket_id'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "5" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['ticket_id'])) . '"))';
            }
        }

        if (!empty($flag_entities['contact_uid'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "6" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['contact_uid'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "6" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['contact_uid'])) . '"))';
            }
        }
        if (!empty($flag_entities['cashier_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "7" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['cashier_id'])) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "7" AND fe.entity_id IN ("' . implode('", "', array_map('trim', $flag_entities['cashier_id'])) . '"))';
            }
        }
        if (!empty($flag_entities['timeslot_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "8" AND fe.entity_id IN ("' . implode('", "', array_unique(array_map('trim', $flag_entities['timeslot_id']))) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "8" AND fe.entity_id IN ("' . implode('", "', array_unique(array_map('trim', $flag_entities['timeslot_id']))) . '"))';
            }
        }
        if (!empty($flag_entities['shift_id'])) {
            if (empty($count)) {
                $count = 1;
                $where .= '(fe.entity_type = "9" AND fe.entity_id IN ("' . implode('", "', array_unique(array_map('trim', $flag_entities['shift_id']))) . '"))';
            } else {
                $where .= ' || (fe.entity_type = "9" AND fe.entity_id IN ("' . implode('", "', array_unique(array_map('trim', $flag_entities['shift_id']))) . '"))';
            }
        }
        $where .=')';
        $db->where($where);
        $db->order_by('fe.entity_type', 'asc');
        $query = $db->get();
        $flag_entity_details = $query->result();
        $this->createLog('flag_logs.php', 'query', array("data" => $db->last_query()));
        $this->createLog('flag_logs.php', 'flags_details', array("data" => json_encode($flag_entity_details)));
        /* sort all detail related to flag. */
        if (count($flag_entity_details) > 1) {
            foreach ($flag_entity_details as $flag_entity_detail) {
                $flag_entity_detail_sort[$flag_entity_detail->entity_type][] = $flag_entity_detail;
                if (!in_array($flag_entity_detail->entity_type, $entity_type)) {
                    $entity_type[] = $flag_entity_detail->entity_type;
                }
            }
            foreach ($entity_type as $type) {
                foreach ($flag_entity_detail_sort[$type] as $data) {
                    $sorted_details[$type][$data->flag_uid] = $data;
                }
                ksort($sorted_details[$type]);
                foreach ($sorted_details[$type] as $data) {
                    $flag_entity_sorted_details[] = $data;
                }
            }
        } else {
            $flag_entity_sorted_details = $flag_entity_details;
        }
        /* start block for contiki product flag. */
        if (!empty($flag_entities['contiki_product'])) {
            foreach ($flag_entity_sorted_details as $flag_key => $details) {
                if ($details->entity_type == '5') {
                    foreach ($flag_entities['contiki_product'] as $product_key => $values) {
                        if (in_array($details->entity_id, $values)) {
                            $flag_entity_sorted_details[$flag_key]->entity_id = $product_key;
                        }
                    }
                }
            }
            /* check details for order_reference if contiki product set. */
            if (!empty($flag_entities['main_reservation_reference']) && count($flag_entities['main_reservation_reference']) > 0 && is_array($flag_entities['main_reservation_reference'])) {
                $db = $this->secondarydb->db;
                $db->select('flag_id, flag_uid, flag_type, flag_entity_type, flag_entity_id, flag_name, flag_value');
                $db->from('order_flags');
                $db->where_in('order_id', $flag_entities['main_reservation_reference']);
                $db->where('flag_entity_type', '8');
                $query = $db->get();
                if ($query->num_rows() > 0) {
                    $result = $query->result_array();
                    if (!empty($result)) {
                        foreach ($result as $result_details) {
                            $order_reference_flag = [];
                            $order_reference_flag['id'] = $result_details['flag_id'];
                            $order_reference_flag['flag_uid'] = $result_details['flag_uid'];
                            $order_reference_flag['type'] = $result_details['flag_type'];
                            $order_reference_flag['entity_type'] = $result_details['flag_entity_type'];
                            $order_reference_flag['entity_id'] = $result_details['flag_entity_id'];
                            $order_reference_flag['name'] = $result_details['flag_name'];
                            $order_reference_flag['value'] = $result_details['flag_value'];
                            $flag_entity_sorted_details[] = (object) $order_reference_flag;
                        }
                    }
                    $this->createLog('flag_logs.php', 'query order_flags :', array("query" => $db->last_query(), "data" => json_encode($result), "order_reference" => json_encode($flag_entities['order_reference'])));
                }
            }
        }
        /* end block for contiki product flag. */
        return $flag_entity_sorted_details;
    }

    /* #endregion to get entities flag details.*/
    
   /* #region to save order flag details  */
   /**
    * @name set_order_flags .
    * @called from insert_in_db and insert_in_db_direct form Pos controller. 
    * @param array $flag_details.
    * @param string $order_id.
    * @purpose : This function use to save flag detail in order_flags table.
    * @created by : Neha<nehadev.aipl@gamil.com>
    * @created at: 07 July 2020
    */
    function set_order_flags($flag_details = [], $order_id = '') {
        $this->createLog('flag_logs.php', 'flag_details', array("data" => json_encode($flag_details)));
        if (!empty($flag_details) && !empty($order_id)) {
            $condition = ['where' => 'order_id="'.$order_id.'"'];
            $data_order = $this->find('order_flags', $condition, 'object', 1);
            $final_order_flags = $order_flags = [];
            foreach ($flag_details as $key => $flag_detail) {
                $already_exist = 0;
                if (!empty($data_order)) {
                    foreach ($data_order as $already_exist_data) {
                        if ($already_exist_data->flag_uid == $flag_detail->flag_uid && $already_exist_data->flag_id == $flag_detail->id && $already_exist_data->flag_entity_type == $flag_detail->entity_type && $already_exist_data->flag_entity_id == $flag_detail->entity_id) {
                            $already_exist = 1;
                            break;
                        }
                    }
                }
                if ($already_exist == 0) {
                    if (!empty($data_order)) {
                        $add_count = count($data_order) + ($key + 1);
                        $order_flags['id'] = $order_id . $add_count;
                    } else {
                        $order_flags['id'] = $order_id . ($key + 1);
                    }
                    $order_flags['order_id'] = $order_id;
                    $order_flags['flag_id'] = $flag_detail->id;
                    $order_flags['flag_uid'] = $flag_detail->flag_uid;
                    $order_flags['flag_type'] = $flag_detail->type;
                    $order_flags['flag_entity_type'] = $flag_detail->entity_type;
                    $order_flags['flag_entity_id'] = $flag_detail->entity_id;
                    $order_flags['flag_name'] = trim($flag_detail->name);
                    $order_flags['flag_value'] = trim($flag_detail->value);
                    $order_flags['created_at'] = gmdate("Y-m-d H:i:s");
                    $order_flags['updated_at'] = gmdate("Y-m-d H:i:s");
                    $final_order_flags[] = $order_flags;
                }
            }
            if (count($final_order_flags) > 0) {
                $this->insert_batch('order_flags', $final_order_flags, 1);
                // To update the data in RDS realtime
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                     $this->insert_batch('order_flags', $final_order_flags, 4);
                }
            }
            $this->createLog('flag_logs.php', 'query', array("data" => $this->db->last_query()));
        }
    }

    /* #endregion to save order flag details.*/

    /* #region Function to create timeslot id for availability */
    /**
     * @name getAvailabilityTimeslotId .
     * @param  mixed $date
     * @param  mixed $from_time
     * @param  mixed $to_time
     * @param  mixed $shared_capacity_id
     * @created by : Neha<nehadev.aipl@gamil.com>
     * @created at: 12 Oct 2020
     * @return String
     */
    function getAvailabilityTimeslotId($date, $from_time, $to_time, $shared_capacity_id = 0)
    {
        $srch = array('-', ':');
        $replce = array('');
        return str_replace($srch, $replce, ($date.$from_time.$to_time.$shared_capacity_id));
    }
    /* #endregion Function to create timeslot id for availability */

    /* #region get_pickups_data  */
    /**
     * get_pickups_data
     *
     * @param  mixed $rel_target_ids
     * @return void
     */
    function get_pickups_data($rel_target_ids = array()) {
        $return = array();
        if (!empty($rel_target_ids)) {
            $db = $this->primarydb->db;
            $db->select('target_id, name, targetlocation');
            $db->from('rel_targetvalidcities');
            $db->where_in('target_id', $rel_target_ids);
            $query = $db->get();
            if ($query->num_rows() > 0) {
                $result = $query->result_array();
                foreach ($result as $results) {
                    $return[$results['target_id']] = $results;
                }
            }
        }
        return $return;
    }
    /* #endregion */

    /* #region main_ticket_combi_data  */
    /**
     * main_ticket_combi_data
     *
     * @param  mixed $main_ticket_ids
     * @return void
     */
    function main_ticket_combi_data($main_ticket_ids = array()) 
    {
        $return = array();
        if (!empty($main_ticket_ids)) {
            $db = $this->primarydb->db;
            $db->select('mec_id ,is_combi');
            $db->from('modeventcontent');
            $db->where_in('mec_id', $main_ticket_ids);
            $query = $db->get();
            if ($query->num_rows() > 0) {
                $result = $query->result_array();
                foreach ($result as $results) {
                    $return[$results['mec_id']] = $results['is_combi'];
                }
            }
        }
        return $return;
    }
    /* #endregion main_ticket_combi_data*/

    /* #region cluster_tickets_detail_data  */
    /**
     * cluster_tickets_detail_data
     *
     * @param  mixed $main_tps_ids
     * @return void
     * @author priya Aggarwal <priya.intersoft1@gmail.com> on 12 Augest, 2018
     */
    function cluster_tickets_detail_data($main_tps_ids = array()) 
    {
        $return = array();
        if (!empty($main_tps_ids)) {
            $db = $this->primarydb->db;
            $db->select('merchant_admin_id, merchant_admin_name, ticket_price_schedule_id, currency, main_ticket_price_schedule_id, main_ticket_id');
            $db->from('cluster_tickets_detail');
            $db->where_in('main_ticket_price_schedule_id', $main_tps_ids);
            $query = $db->get();
            if ($query->num_rows() > 0) {
                $result = $query->result_array();
                foreach ($result as $results) {
                    $return[$results['ticket_price_schedule_id']] = $results;
                }
            }
        }
        return $return;
    }
    /* #endregion cluster_tickets_detail_data*/

    /* #region function tp update credit limits  */
    /**
     * update_credit_limit
     *
     * @param  mixed $data
     * @param  mixed $reduce_credit_limit
     * @return void
     * @author priya Aggarwal <priya.intersoft1@gmail.com> on 12 Augest, 2018
     */
    function update_credit_limit($data = array(), $reduce_credit_limit = '0', $published_reseller_limit = array()) {
        $this->CreateLog('update_credit_limit.php', "Step 1", array('data' => json_encode($data)));
        $museum_id_array = array_keys($data);
        $update_query = '';
        $select_query = '';
        $update_limits = array();
        $museum_reseller_id = $this->common_model->getmultipleFieldValueFromTable('reseller_id,cod_id,market_merchant_id', 'qr_codes', '', 0, 'result_array', '', $museum_id_array, 'cod_id');
        $this->CreateLog('update_credit_limit.php', "Step 2", array('reseller_id' => json_encode($museum_reseller_id)));
        if (!empty($museum_reseller_id)) {
            foreach ($museum_reseller_id as $row) { 
                $hotel_name = $data[$row['cod_id']]['hotel_name'];
                $partner_name = $data[$row['cod_id']]['cashier_name'];
                $currency = $data[$row['cod_id']]['supplier_currency_symbol'];
                if (!empty($data[$row['cod_id']]['merchant_admin_id'])) {
                    $supplier_reseller_id = $data[$row['cod_id']]['merchant_admin_id'];
                } else {
                    $supplier_reseller_id = $row['reseller_id'];
                }
                $market_merchant_id = $row['market_merchant_id'];
                
                
                $select_query .= ' when (admin_id="'.$supplier_reseller_id.'" and cod_id="'.$data[$row['cod_id']]['reseller_id'].'") then credit_notification_settings when (admin_id="'.$data[$row['cod_id']]['reseller_id'].'" and cod_id="'.$data[$row['cod_id']]['hotel_id'].'") then credit_notification_settings when (admin_id="'.$data[$row['cod_id']]['reseller_id'].'" and cod_id="'.$data[$row['cod_id']]['partner_id'].'") then credit_notification_settings ';
                
                $update_limits[$supplier_reseller_id.'_'.$data[$row['cod_id']]['reseller_id']] += $data[$row['cod_id']]['used_limit'];
                $update_limits[$data[$row['cod_id']]['reseller_id'].'_'.$data[$row['cod_id']]['hotel_id']] += $data[$row['cod_id']]['distributor_used_limit'];
                $update_limits[$data[$row['cod_id']]['reseller_id'].'_'.$data[$row['cod_id']]['partner_id']] += $data[$row['cod_id']]['partner_used_limit'];
            }
            foreach ($update_limits as $key => $value) {
                if (!empty($published_reseller_limit) && isset($key, $published_reseller_limit)) {
                    $value += $published_reseller_limit[$key];
                }
                $new_key = explode('_', $key);
                if ($reduce_credit_limit == 1) {
                    $update_query .= ' WHEN (admin_id = "'.$new_key[0].'" and cod_id = "'.$new_key[1].'") THEN used_limit -"'.$value.'" ';
                } else {
                    $update_query .= ' WHEN (admin_id = "'.$new_key[0].'" and cod_id = "'.$new_key[1].'") THEN used_limit +"'.$value.'" ';
                }
            }
            $this->CreateLog('update_credit_limit.php', "Step 3".$row->admin_id."_".$row->cod_id, array('query' => json_encode($update_limits)));
            // send notification to Admin if used_limit > trigger_amount
            if ($select_query != '') {
                $sel_query = 'Select (CASE'.$select_query.' ELSE "" END) as notification, credit_limit, trigger_amount, used_limit, type, admin_id, cod_id from credit_limit_details where credit_limit > 0 having notification != ""';
                $this->CreateLog('update_credit_limit.php', "Step 4", array('select query' => $sel_query));
                $result = $this->primarydb->db->query($sel_query);
                if ($result->num_rows() > 0) {
                    $query = 'Update credit_limit_details set used_limit = (CASE'.$update_query.' ELSE used_limit END) where credit_limit > 0';
                    $this->CreateLog('update_credit_limit.php', "Step 3", array('query' => $query));
                    $this->db->query($query);
                    $sel_query = 'Select (CASE'.$select_query.' ELSE "" END) as notification, credit_limit, trigger_amount, used_limit, type, admin_id, cod_id from credit_limit_details where credit_limit > 0 having notification != ""';
                    $result = $this->primarydb->db->query($sel_query);
                    $get_data = $result->result();
                    $attachments = array();
                    foreach ($get_data as $row) {
                        //to sync used amount
                        if(SYNC_WITH_FIREBASE == 1 && isset($update_limits[$row->admin_id.'_'.$row->cod_id])) {
                            
                            $headers = $this->all_headers_new('update_credit_limit' , '' , $row->cod_id);
                            
                            if ($row->type == 3) {
                                $node = 'reseller_settings/' . $row->cod_id . '/' . $row->admin_id . '/credit_limit_details';
                            } else if ($row->type == 1) {
                                $node = 'distributor/settings/' . $row->cod_id . '/credit_limit_details';
                            }
                            $update_value = $update_limits[$row->admin_id.'_'.$row->cod_id];
                            if($reduce_credit_limit == '1') {
                                $update_value = '-'.$update_limits[$row->admin_id.'_'.$row->cod_id];
                            }
                            $params = json_encode(array("node" => $node, 'details' => array(), 'update_key' => 'used_limit', 'update_value' => $update_value));
                            $this->CreateLog('update_credit_limit.php', "firebase_updation", array($params));
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_values_in_array");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                            curl_exec($ch);
                            curl_close($ch);
                            $this->CreateLog('firebase_user_type', __CLASS__.'-'.__FUNCTION__, array('user_type' => $this->session->userdata['user_type'], 'cashier_email' => $this->session->userdata['uname'], 'cashier_type' => $this->get_cashier_type_from_tokenId()));
                        }
                        
                        $send_notification = json_decode($row->notification, true);
                        $this->CreateLog('send_credit_limit_email','step1', array('user_type' => json_encode($send_notification), 'used_limit' => $row->used_limit, 'trigger_amount' => $row->trigger_amount));
                        if ($row->used_limit >= $row->trigger_amount && $send_notification['notification_email'] != '' && $send_notification['send_notification'] == 1) {
                            $this->CreateLog('send_credit_limit_email','step2', array('user_type' => json_encode($send_notification)));
                            $left_limit = $row->credit_limit - $row->used_limit;
                            if ($row->type != 2) {
                                $display_name = $hotel_name;
                            } else {
                                $display_name = $partner_name;
                            }
                            $msg = 'Hi, <p>Credit limit is almost reached for "'.$display_name.'" Credit limit = '.$row->credit_limit.' '.$currency.' Current exposure = '.$row->used_limit.' '.$currency.' Balance = '.$left_limit.' '.$currency.'</p>Thanks';
                            $arraylist['emailc'] = $send_notification['notification_email'];
                            $arraylist['html'] = $msg;
                            if(isset($market_merchant_id) && $market_merchant_id == "2") {
                                $arraylist['from'] = PRIOHUB_NO_REPLY_EMAIL;
                                $arraylist['fromname'] = PRIOHUB_FROM_NAME;
                                $arraylist['market_merchant_id'] = "2";
                            } else {
                                $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
                                $arraylist['fromname'] = SECOND_MESSAGE_SERVICE_NAME;
                            }
                            
                            $arraylist['subject'] = 'Credit Limit Notification';
                            $arraylist['attachments'] = $attachments;
                            $this->CreateLog('send_credit_limit_email','step3', array('user_type' => json_encode($arraylist)));
                            $this->sendemail_model->sendemailtousers($arraylist, 2);
                            $this->CreateLog('send_credit_limit_email','step4', array('user_type' => 'Done'));
                        } 
                    }
                }
            }
        }
    }
    /* #endregion function tp update credit limits */

    /*#region Contact Add/Update*/     
    /**
     * contact_add
     *
     * @param  mixed $contacts
     * @param  mixed $visitor_group_no
     * @return void
     */
    function contact_add(array $contacts, $visitor_group_no)
    {  
        $order_contacts = $guest_contacts = $contact_uids = array();
        foreach ($contacts as $contact) {
            $condition = ['where' => 'contact_uid="'.$contact['contact_uid'].'" and version='.$contact['version'],'order_by' => 'version desc'];  
            $data = $this->find('guest_contacts', $condition, 'object', 1);
            if (empty($data)) {
                if (isset($contact['version']) && $contact['version'] > 0) {
                    unset($contact['visitor_group_no']);
                } else {
                    $contact['version'] = 1;
                }
                $guest_contacts[] = array_filter($contact, function($value){
                        return isset($value);
                });
                    }
            $order_contact = ['contact_uid' => $contact['contact_uid'], 
                'contact_version' => $contact['version'],
                'visitor_group_no' => $visitor_group_no,
                'created_at' => $contacts['created_at'],
                'updated_at' => $contacts['updated_at']
            ];
            $condition = ['where' => 'contact_uid="'.$contact['contact_uid'].'" and contact_version='.$contact['version'].' and visitor_group_no="'.$visitor_group_no.'"'];  
            $data_order = $this->find('order_contacts', $condition, 'object', 1);
            if (empty($data_order)) {
                $order_contacts[] = $order_contact;
                if(!empty($contact['contact_uid']) && !in_array($contact['contact_uid'], $contact_uids['contact_uid'])){
                    $contact_uids['contact_uid'][] = strval($contact['contact_uid']);
                }
            }
        }
          
        if (count($guest_contacts) > 0) {
            $this->insert_batch('guest_contacts', array_values($guest_contacts), 1);
            /* to insert data in RDS realtime */
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                $this->insert_batch('guest_contacts', array_values($guest_contacts), 4);
            }
        }

        if (!empty($order_contacts)) {
            $this->insert_batch('order_contacts', $order_contacts, 1);
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                $this->insert_batch('order_contacts', $order_contacts, 4);
            }
        }
        /* save new contact id details in order flag table.*/
        if(!empty($contact_uids['contact_uid'])){
            $flag_details = $this->get_flags_details($contact_uids);
            if (!empty($flag_details)) {
                $this->set_order_flags($flag_details, $visitor_group_no);
            }
        }
    }
    /*#endregion */

   /* #region to save payment details.  */
   /**
    * @name add_payments .
    * @called from insert_in_db and insert_in_db_direct form Pos controller. 
    * @param array $order_payment_details.
    * @purpose : This function use to save payment detail in order_payment_details table.
    * @created at: 09 July 2020
    */
    function add_payments($order_payment_details, $is_insert = 0, $data = array()) {
        $this->createLog('paymnts.php', 'order paymnts', array(json_encode($order_payment_details)));
        $this->createLog('paymnts.php', 'order paymnts data', array(json_encode($data)));
        $version_increase = 1;
        if (!empty($data['pending_cancel_payment'])) {
            $version_increase = 0;
        }
        /* handle multiple entries for payment cancel. */
        $seconds = 2;
        if(!empty($data['insert_paid_payment_detail_case'])){
            $seconds = 6;
        }
        if (isset($order_payment_details[0]) && !isset($data['booking_level_order_payment_details'])) {
            foreach ($order_payment_details as $payment_key => $order_payment_detail_data){
                if(!isset($order_payment_details[$payment_key]['created_at'])) {
                    $order_payment_details[$payment_key]['created_at'] = gmdate("Y-m-d H:i:s");
                }
                if (((!isset($order_payment_detail_data['is_active'])) || $order_payment_detail_data['is_active'] != '0') && (((!isset($order_payment_detail_data['status'])) && !isset($order_payment_detail_data->status)) || (isset($order_payment_detail_data['status']) && $order_payment_detail_data['status'] != '9') || (isset($order_payment_detail_data->status) && $order_payment_detail_data->status != '9'))){
                   $order_payment_details[$payment_key]['updated_at'] = gmdate("Y-m-d H:i:s", strtotime("+$seconds sec"));
                   $seconds = $seconds + 2;
                }
                /** #Comment : unset date with 0000-00-00 values. */
                $order_payment_details[$payment_key] = $this->unsetdatewithzero($order_payment_details[$payment_key]);
            }
            if (isset($data['is_amend_order_version_update'])) {
                $order_payment_details = $this->set_max_version($order_payment_details, $version_increase);
            }
            $this->insert_batch('order_payment_details', $order_payment_details, 1);
            /* to insert data in RDS realtime */
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                $this->insert_batch('order_payment_details', $order_payment_details, 4);
            }
        } else {
            if(!empty($data['insert_paid_payment_detail'])){
                $seconds = 6; 
            }
            if (!empty($order_payment_details['shift_id']) && isset($order_payment_details['status']) && $order_payment_details['status'] == '2') {
                $shift_id = $order_payment_details['shift_id'];
                unset($order_payment_details['shift_id']);
            }
            if(!isset($order_payment_details['created_at'])) {
                $order_payment_details['created_at'] = gmdate("Y-m-d H:i:s");
            }
            $order_payment_details['updated_at'] = gmdate("Y-m-d H:i:s", strtotime("+$seconds sec"));
            /* Handle case to save details in booking level. */
            if (!empty($data['booking_level_order_payment_details'])) {
                if (isset($data['is_amend_order_version_update'])) {
                    $data['booking_level_order_payment_details'] = $this->set_max_version($data['booking_level_order_payment_details'], $version_increase);
                }
                foreach ($data['booking_level_order_payment_details'] as $key => $booking_level_order_payment_detail){
                    if (!isset($booking_level_order_payment_detail['status']) || $booking_level_order_payment_detail['status'] != '9') {
                        $data['booking_level_order_payment_details'][$key]['updated_at'] = gmdate("Y-m-d H:i:s", strtotime("+$seconds sec"));
                    }
                    $seconds = $seconds + 2;
                    if (!empty($booking_level_order_payment_detail['shift_id']) && isset($booking_level_order_payment_detail['status']) && $booking_level_order_payment_detail['shift_id'] == '2') {
                        $shift_id = $booking_level_order_payment_detail['shift_id'];
                        unset($data['booking_level_order_payment_details'][$key]['shift_id']);
                    }
                    /** #Comment : unset date with 0000-00-00 values. */
                    $data['booking_level_order_payment_details'][$key] = $this->unsetdatewithzero($data['booking_level_order_payment_details'][$key]);
                }
                $this->insert_batch('order_payment_details', $data['booking_level_order_payment_details'], 1);
                /* to insert data in RDS realtime */
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                    $this->insert_batch('order_payment_details', $data['booking_level_order_payment_details'], 4);
                }
            } else {
                if (isset($data['is_amend_order_version_update'])) {
                    $order_payment_details = $this->set_max_version($order_payment_details, $version_increase);
                }
                 /** #Comment : unset date with 0000-00-00 values. */
                $order_payment_details = $this->unsetdatewithzero($order_payment_details);
                $this->secondarydb->db->insert('order_payment_details', $order_payment_details);
                if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                    $this->fourthdb->db->insert('order_payment_details', $order_payment_details);
                }
            }

            //if ($is_insert == 1 && ((isset($data['uncancel_order']) && ($data['uncancel_order'] == 1) && $order_payment_details['status'] == 2 && $order_payment_details['settlement_type'] != 2) || ($order_payment_details['settlement_type'] == 2 && !isset($data['uncancel_order'])))) {
            if ($is_insert == 1 && (!isset($data['is_full_paymet_done']) || empty($data['is_full_paymet_done']))) {
                $is_inactive_found = 0;
                if (isset($data['uncancel_order']) && ($data['uncancel_order'] == 1)) {
                    $payment_details_from_db = $this->secondarydb->db->select('*')->from("order_payment_details")->where('visitor_group_no', $order_payment_details['visitor_group_no'])->get()->result_array();
                    if (!empty($payment_details_from_db)) {
                        $active_status = array_column($payment_details_from_db, 'is_active');
                        if (in_array("0", $active_status)) {
                            $is_inactive_found = 1;
                        }
                    }
                }
                if ($is_inactive_found == 0) {
                    $order_payment_details['is_active'] = 0;
                    if (empty($order_payment_details['original_payment_id'])) {
                        $order_payment_details['original_payment_id'] = $order_payment_details['id'];
                        $order_payment_details['original_group_payment_id'] = $order_payment_details['group_payment_id'];
                        if (!empty($shift_id)) {
                            $order_payment_details['shift_id'] = $shift_id;
                        }
                    }
                    if (!empty($data['booking_level_order_payment_details'])) {
                        $booking_level_order_payment_details_arr = [];
                        $group_payment_id = $this->generate_uuid();
                        foreach ($data['booking_level_order_payment_details'] as $booking_level_order_payment_details) {
                            $booking_level_order_payment_details['is_active'] = 0;
                            if (empty($booking_level_order_payment_details['original_payment_id'])) {
                                $booking_level_order_payment_details['original_payment_id'] = $booking_level_order_payment_details['id'];
                                $booking_level_order_payment_details['original_group_payment_id'] = $booking_level_order_payment_details['group_payment_id'];
                            }
                            $booking_level_order_payment_details['id'] = $this->generate_uuid();
                            if(count($data['booking_level_order_payment_details']) > 1){
                                 $booking_level_order_payment_details['group_payment_id'] =  $group_payment_id;
                            }else{
                                $booking_level_order_payment_details['group_payment_id'] = $booking_level_order_payment_details['id'];
                            }
                            if (!empty($shift_id)) {
                                $booking_level_order_payment_details['shift_id'] = $shift_id;
                            }
                            $booking_level_order_payment_details_arr[] = $booking_level_order_payment_details;
                        }
                        $this->insert_batch('order_payment_details', $booking_level_order_payment_details_arr, 1);
                        /* to insert data in RDS realtime */
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->insert_batch('order_payment_details', $booking_level_order_payment_details_arr, 4);
                        }
                    } else {
                        $order_payment_details['is_active'] = 0;
                        if (empty($order_payment_details['original_payment_id'])) {
                            $order_payment_details['original_payment_id'] = $order_payment_details['id'];
                            $order_payment_details['original_group_payment_id'] = $order_payment_details['group_payment_id'];
                        }
                        $order_payment_details['group_payment_id'] = $order_payment_details['id'] = $this->generate_uuid();
                        $this->secondarydb->db->insert('order_payment_details', $order_payment_details);
                        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                            $this->fourthdb->db->insert('order_payment_details', $order_payment_details);
                        }
                    }
                }
            }
        }
    }

    /* #endregion */

   /* #region to save payment details.  */
   /**
    * @name update_payments .
    * @called from update_in_db and update_in_db_direct form Pos controller. 
    * @param array $order_payment_details.
    * @purpose : This function use to update payment detail in order_payment_details table.
    * @created at: 28 oct 2020
    */
    function update_payments($order_payment_details) {
        if (isset($order_payment_details[0])) {
            $this->secondarydb->db->update_batch('order_payment_details', $order_payment_details, 'id');
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                $this->fourthdb->db->update_batch('order_payment_details', $order_payment_details, 'id');
            }
        } else {
            $this->secondarydb->db->update('order_payment_details', $order_payment_details, array('id' => $order_payment_details['id']));
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                $this->fourthdb->db->update('order_payment_details', $order_payment_details, array('id' => $order_payment_details['id']));
            }
        }
    }

    /* #endregion */
    
    /* #region  to deleteBlockedRecord */
    /**
     * deleteBlockedRecord
     *
     * @param  mixed $delete_where
     * @return void
     */
    function deleteBlockedRecord($delete_where)
    {
        $this->db->delete('block_order_details', $delete_where);
    }
    /* #endregion */

    /* #region Function to get reseller id of selected ticket's supplier */
    /**
     * @name getResellerSubcatalogIds .
     * @param  mixed $date
     * @created by : Jatinder kumar<jatinder.aipl@gamil.com>
     * @created at: 26 Feb 2021
     * @return String
     */
    function getResellerSubcatalogIds( $ptData = array(), $reseller_id = '') {      
        $result = array();
        $ticketIds = array_column( $ptData, 'ticket_id' );
        $resellerCatalogIds = $this->primarydb->db->select('mec.mec_id, mec.reseller_id, rps.sub_catalog_id')
            ->from("modeventcontent mec")
            ->join("related_partners_subcatalog rps", "rps.admin_id = mec.reseller_id")
            ->where_in( 'mec.mec_id', $ticketIds )
            ->where( 'rps.partner_id', $reseller_id )
            ->get()->result_array();
        if( !empty( $resellerCatalogIds ) ) {
            
            foreach( $resellerCatalogIds as $value ) {
                
                $result[$value['mec_id']][$value['reseller_id']] = ( !empty( $value['sub_catalog_id'] )? $value['sub_catalog_id']: 0 );
            }
        }
        return $result;
    }
    /* #endregion Function to get reseller id of selected ticket's supplier */
    
      /* #region to set_max_version  details.  */

    /**
     * @name set_max_version .
     * @called from add_payment function fromorder_process_model. 
     * @param array $order_payment_details.
     * @purpose : This function use to set max version fororder_payment_details table.
     * @created at: 14 dec 2021
     */
    function set_max_version($order_payment_details, $version_increase = 1) {
        if (isset($order_payment_details[0])) {
            $ticket_booking_ids = array_column($order_payment_details, 'ticket_booking_id');
            $visitor_group_no = $order_payment_details[0]['visitor_group_no'];
        } else {
            $ticket_booking_ids[] = $order_payment_details['ticket_booking_id'];
            $visitor_group_no = $order_payment_details['visitor_group_no'];
        }

        $payment_details_from_db = $this->secondarydb->db->select('max(version) as version , visitor_group_no, ticket_booking_id')->from("order_payment_details")->where('visitor_group_no', $visitor_group_no)->get()->result_array();
        $this->createLog('paymnts_db_details.php', 'last query', array(json_encode($this->secondarydb->db->last_query())));
        $this->createLog('paymnts_db_details.php', 'order paymnts', array(json_encode($payment_details_from_db)));
        if (!empty($payment_details_from_db)) {
            $detail_based_on_ticket_booking_id = [];
            foreach ($payment_details_from_db as $payment_details) {
                if (in_array($payment_details['ticket_booking_id'], $ticket_booking_ids)) {
                    $payment_details_data[$payment_details['ticket_booking_id']] = $payment_details;
                }
            }
            $this->createLog('paymnts_db_details.php', 'detail_based_on_ticket_booking_id', array(json_encode($detail_based_on_ticket_booking_id)));
            $max_version = max(array_column($payment_details_from_db, 'version'));
            $this->createLog('paymnts_db_details.php', 'max_version  ', array($max_version));
            if (isset($order_payment_details[0])) {
                $second = 1;
                foreach ($order_payment_details as $key => $order_payment_detail) {
                    if (!empty($payment_details_data[$order_payment_detail['ticket_booking_id']]['version'])) {
                        if (!empty($version_increase)) {
                            $order_payment_details[$key]['version'] = $payment_details_data[$order_payment_detail['ticket_booking_id']]['version'] + 1;
                        } else {
                            $order_payment_details[$key]['version'] = $payment_details_data[$order_payment_detail['ticket_booking_id']]['version'];
                        }
                    } else{
                        if (!empty($version_increase)) {
                            $order_payment_details[$key]['version'] = $max_version + 1;
                        } else {
                            $order_payment_details[$key]['version'] = $max_version;
                        }
                    }

                    $second++;
                }
                $ticket_booking_ids = array_column($order_payment_details, 'ticket_booking_id');
                $visitor_group_no = $order_payment_details[0]['visitor_group_no'];
            } else {
                if (isset($payment_details_data[$order_payment_details['ticket_booking_id']]['version'])) {
                    if (!empty($version_increase)) {
                        $order_payment_details['version'] = $payment_details_data[$order_payment_details['ticket_booking_id']]['version'] + 1;
                    } else {
                        $order_payment_details['version'] = $payment_details_data[$order_payment_details['ticket_booking_id']]['version'];
                    }
                } else {
                    if (!empty($version_increase)) {
                        $order_payment_details['version'] = $max_version + 1;
                    } else {
                        $order_payment_details['version'] = $max_version;
                    }
                }
            }
        }
        $this->createLog('paymnts_db_details.php', 'order_payment_details return ', array(json_encode($order_payment_details)));
        return $order_payment_details;
    }

    /* #endregion */
    /* #region  to unsetdatewithzero */
    /**
     * unsetdatewithzero
     *
     * @param  mixed $delete_where
     * @return void
     */
    function unsetdatewithzero($order_payment_details)
    {   
        $default_value = "1970-01-01 00:00:00";
        /** #Comment : unset date with 0000-00-00 values. */
        if (isset($order_payment_details['created_at']) && empty(strtotime($order_payment_details['created_at']))){
            $order_payment_details['created_at'] = $default_value;
        }
        if (isset($order_payment_details['updated_at']) && empty(strtotime($order_payment_details['updated_at']))){
            $order_payment_details['updated_at'] = $default_value;
        }
        if (isset($order_payment_details['settled_on']) && empty(strtotime($order_payment_details['settled_on']))){
            $order_payment_details['settled_on'] = $default_value;
        }
        if (isset($order_payment_details['last_modified_at']) && empty(strtotime($order_payment_details['last_modified_at']))){
            $order_payment_details['last_modified_at'] = $default_value;
        }
        return  $order_payment_details;
    }
    /* #endregion */
    
    /* #region  to deleteBlockedPaymentRecord */
    /**
     * deleteBlockedPaymentRecord
     *
     * @param  mixed $delete_where
     * @return void
     */
    function deleteBlockedPaymentRecord($delete_where)
    {
        $this->db->delete('block_order_payments', $delete_where);
    }
    /* #endregion */

    /**
     * get_tps_id_of_related_product
     *
     * @param  ticket_id 
     * @purpose  To fetch the tps_id of main ticket id 
     * @author Gourav Sadana <gourav.aipl@gmail.com> on 23 June, 2022
     */
    private function get_tps_id_of_related_product($ticket_id = '') {
        if( !empty($ticket_id) ) {
            $db =   $this->primarydb->db;
            $db->select('mec.mec_id,mec.is_combi, tp.id as tps_id');
            $db->from('modeventcontent mec');
            $db->join('ticketpriceschedule tp','mec.mec_id = tp.ticket_id','left');
            $db->where('mec.mec_id', $ticket_id);
            $db->where('mec.deleted', '0');
            $db->where('tp.deleted', '0');
            $query  =   $db->get();
            return $query->result_array();
        } else {
            return false;
        }
    }

    /**
     * get_prepaid_extra_option_data
     *
     * @param  ticket_id 
     * @param  visitor_group_no 
     * @purpose : This function used to get the bundle discount rows from prepaid extra options table
     * @author Gourav Sadana <gourav.aipl@gmail.com> on 27 June, 2022
     */
    private function get_prepaid_extra_option_data($ticket_id = [], $visitor_group_no = '') {
        if( !empty($ticket_id) && !empty($visitor_group_no) ) {
            $db =   $this->primarydb->db;
            $db->select('po.visitor_group_no,po.ticket_id,po.price,po.tax_id,po.tax,quantity,po.net_price,po.ticket_price_schedule_id,tp.ticket_type_label as ticket_type, po.ticket_booking_id as ticket_booking_id');
            $db->from('prepaid_extra_options po');
            $db->join('ticketpriceschedule tp','tp.ticket_id = po.ticket_id and po.ticket_price_schedule_id = tp.id','left');
            $db->where_in('po.ticket_id', $ticket_id);
            $db->where('po.visitor_group_no', $visitor_group_no);
            $db->where('po.variation_type', '1');
            $db->where('po.is_cancelled', '0');
            $query  =   $db->get();
            $result =   $query->result_array();
            $final_array    =   array();
            foreach ($result as $value) {
                $final_array[$value['ticket_booking_id'].'_'.$value['ticket_price_schedule_id']] = $value;
            }
            return $final_array;
        } else {
            return false;
        }
    }
}

?>
