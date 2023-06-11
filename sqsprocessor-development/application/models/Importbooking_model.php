<?php

class Importbooking_model extends MY_Model {

	function __construct() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->model('common_model');
        $this->base_url = $this->config->config['base_url'];
        $this->root_path = $this->config->config['root_path'];
        $this->imageDir = $this->config->config['imageDir'];
        $this->load->library('log_library');
        /* distributor and token for getting ARENA constant value from API end */
        $this->api_url = $this->config->config['api_url'];
        $microtime = microtime();
        $search = array('0.', ' ');
        $replace = array('', '');
        $error_reference_no = str_replace($search, $replace, $microtime);
        define('ERROR_REFERENCE_NUMBER', $error_reference_no);
        /* constant details for authorization at API end */
        $this->get_api_const = json_decode(TEST_API_DISTRIBUTOR, TRUE);
        $this->load->model('pos_model');
        $this->load->model('common_model');
        $this->load->model('order_process_model');
        $this->load->model('order_process_vt_model');
    }
	
	/**
     * update processed status in import_booking_orders_to_insert_in_vt table
     * @param array $visitor_group_nos          visitor group no for which status need to update
     * @param string $processed_status          processed status
     * @return boolean
     */
    function update_processed_status_in_import_booking_orders_to_insert_in_vt($visitor_group_nos, $processed_status="1") {
        
        if (empty($visitor_group_nos) || $processed_status == "") { return false; }
        $this->secondarydb->db->set(array("processed_status" => $processed_status, "last_modified_at" => gmdate("Y-m-d H:i:s")));
        $this->secondarydb->db->where_in("visitor_group_no", $visitor_group_nos);
        $this->secondarydb->db->update('import_booking_orders_to_insert_in_vt');
        return true;
    }
	
	/**
     * @Name        : vtDataPrepare
     * @Purpose     : To get data from PT for the visitor_group_no batches and process to execute in batches. 
     * @param type  : $vgns = array(), $overview_data=array(), $hotel_data=array(), $db_type = '1'
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    public function vtDataPrepare($vgns = array(), $overview_data = array(), $hotel_data = array(), $ticket_details = array(), $hotel_info = array(), $tpsDetails = array(), $tax = array(), $tpsPartnerFin = array(), $allCurrentSeasonTpsId = array(), $allCurrentTpsIds = array(), $print='1', $db_type = '1') {

        if(empty($vgns) || !is_array($vgns)) { return false; }
        echo "<pre>"; print_r("START = " . date('Y-m-d H:i:s')); echo "</pre>"; 

        global $MPOS_LOGS;
        if ($db_type == '1') {
            $db = $this->secondarydb->db;
        } else {
            $db = $this->db;
        }

        $dataVt = $this->visitorTicketGnerateData($vgns, $ticket_details, $hotel_info, $hotel_data, $tpsDetails, $tax, $tpsPartnerFin, $allCurrentSeasonTpsId, $allCurrentTpsIds, $db_type, $print);
        if(!empty($dataVt)) {
            return $dataVt;
        }

        echo "<pre>"; print_r("STOP = " . date('Y-m-d H:i:s')); echo "</pre>"; 
    }
	
    /**
     * @Name        : visitorTicketGnerateData
     * @Purpose     : Execution of visitor_group_no and its quantity rows. 
     * @param type  : $visitorGroupNo=array(), $ticket_details=array(), $hotel_info=array(), $hotel_data=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    private function visitorTicketGnerateData($visitorGroupNo=array(), $ticket_details=array(), $hotel_info=array(), $hotel_data=array(), $tpsDetails=array(), $tax=array(), $tpsPartnerFin=array(), $allCurrentSeasonTpsId=array(), $allCurrentTpsIds=array(), $db_type='1', $print='1') {
        global $logsData;
        if(empty($visitorGroupNo) || empty($ticket_details) || empty($hotel_info)) {
            return false;
        }
        
        $vt_table = (!empty(VT_TABLE)? VT_TABLE: 'visitor_tickets');

        $VTGroupData 		= $prepareBq = $ticketBookingId = $ptColumnUpd = array();
        foreach($visitorGroupNo as $vgn) {

            $final_visitor_data = $sns_messages = array();
            $firstKey 			= current($vgn);
            $ticket_id 			= $firstKey['ticket_id'];
            $museum_id 			= $firstKey['museum_id'];
            $museum_name 		= $firstKey['museum_name'];
            $hotel_id 			= $firstKey['hotel_id'];
            $hotel_name 		= $firstKey['hotel_name'];
            $visitor_group_no 	= $firstKey['visitor_group_no'];
            $vgno 				= $firstKey['visitor_group_no'];
            $tpsIds 			= array_filter(array_unique(array_column($vgn, 'tps_id')));
            $channelId 			= $hotel_info[$hotel_id]['channel_id'];
            $sub_catalog_id             = $hotel_info[$hotel_id]['sub_catalog_id'];
            $bookingInformation = array_column($vgn, 'booking_information');
            $channelTypeChk     = array_unique(array_column($vgn, 'channel_type'));
            $bookingNames		= $bookingInfo = array();
            if(!empty($bookingInformation)) {

                $bookingNames 	= array_unique(array_map(function($b) { 
                                        $b = unserialize($b); 
                                        return (!empty(trim($b['booking_name']))? trim($b['booking_name']): '');
                                    }, array_filter($bookingInformation)));
            }
            if(!empty($bookingNames)) {
                $bookingInfo 	= $this->getAllBookingInfo($bookingNames, $hotel_id);
            }

            if(!empty($tpsIds)) {
                $tlcLevelData	= $this->getTlcClcLevelData($tpsIds, $channelId, $hotel_id, $ticket_id);
                $hotelLevelData	= $this->getHotelLevelAff($tpsIds, $channelId, $hotel_id, $ticket_id);
            }

            if(!empty($tpsDetails)) {
                $tax_check_ids 	= array_unique(array_filter(array_column($tpsDetails, 'tax_key')));
                $taxData 		= $this->getTaxDataIn('0',SERVICE_NAME, $hotel_id, $tax_check_ids);
            }

            $is_prioticket 		= $hotel_data[$vgno]['is_prioticket'];
            $service_cost 		= $pos_point_id = $channel_id = $cashier_id = $booking_status = 0;
            $selected_date 		= $from_time = $to_time = $slot_type = $pos_point_name = $channel_name = $cashier_name = '';
            $visitor_ids_array 	= array();
            $transaction_id_array = array();
            $rel_target_ids = array();
            $cluster_net_price = array();
            $cluster_tps_ids = array();
            $cluster_tickets_data = array();
            $total_quantity = 0;
            $total_pax = 0; 
            
            foreach($vgn as $value) {
                
                if ($value['is_addon_ticket'] == '0') {
                    $cluster_tps_ids[] = $value['tps_id'];
                    $main_ticket_ids[$value['ticket_id']] = $value['ticket_id'];
                    $cluster_net_price[$value['clustering_id']] += 0;
                } else if ($value['is_addon_ticket'] == '2' && $value['clustering_id'] != '' && $value['clustering_id'] != '0') {
                    $cluster_net_price[$value['clustering_id']] += $value['net_price'];
                }
                
                if ($value['financial_id'] > 0) {
                    $rel_target_ids[] = $value['financial_id'];
                }
            }
            $this->load->model('pos_model');
            if (!empty($main_ticket_ids)) {
                $main_ticket_combi_data = $this->order_process_model->main_ticket_combi_data($main_ticket_ids);
            }
            if (!empty($cluster_tps_ids)) {
                $cluster_tickets_data = $this->order_process_model->cluster_tickets_detail_data($cluster_tps_ids);
            }
            $museum_details = array();
            //Processing the quantity exists in VGN
            foreach($vgn as $key => $value) {

                $used				= $value['used'];

                $selected_date 		= (!empty($value['selected_date'])? $value['selected_date']: '');
                $from_time 			= (!empty($value['from_time'])? $value['from_time']: '');
                $to_time 			= (!empty($value['to_time'])? $value['to_time']: '');
                $slot_type 			= (!empty($value['timeslot'])? $value['timeslot']: '');
                $updateByName                   = $value['voucher_updated_by_name'] != '' ? explode(' ', $value['voucher_updated_by_name']): '';

                $pos_point_id 				= $value['pos_point_id'];
                $pos_point_name 			= $value['pos_point_name'];
                $channel_id 				= $value['channel_id'];
                $channel_name 				= $value['channel_name'];
                $cashier_id 				= $value['cashier_id'];
                $cashier_name 				= $value['cashier_name'];
                $cluster_tps_ids[]                      = $value['tps_id'];

                if (!array_key_exists($value['ticket_id'], $museum_details)) {
                    $details['modeventcontent'][$value['ticket_id']] = $ticket_details[$value['ticket_id']] = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $value['ticket_id']));
                    $museum_details[$value['ticket_id']] = $ticket_details[$value['ticket_id']]->museum_name;
                }
                if (!empty($cluster_tps_ids)) {
                    $cluster_tickets_data = $this->order_process_model->cluster_tickets_detail_data($cluster_tps_ids);
                }
                $merchant_admin_id = $ticket_details[$value['ticket_id']]->merchant_admin_id;
                $merchant_admin_name = $ticket_details[$value['ticket_id']]->merchant_admin_name;
                $merchant_data = $this->get_price_level_merchant($value['ticket_id'], $hotel_id, $value['tps_id']);
                if(!empty($merchant_data)) {
                    $merchant_admin_id = $merchant_data->merchant_admin_id;
                    $merchant_admin_name = $merchant_data->merchant_admin_name;
                }
                $value['booking_status'] 	= '1';
                $value['scanned_at'] 		= ($used=='1' && !empty($value['scanned_at'])? $value['scanned_at']: '');

                $paymentMethod 		= (isset($hotel_data[$vgno]['paymentMethod'])? $hotel_data[$vgno]['paymentMethod'] : '');
                $pspReference 		= (isset($hotel_data[$vgno]['pspReference'])? $hotel_data[$vgno]['pspReference']: '');
                $confirm_data 		= $value;
                $confirm_data['pertransaction'] = "0";
                $discount_data = unserialize($value['extra_discount']);

                $confirm_data['creation_date'] 		= (!empty($scanned_at)? $scanned_at: $value['created_at']);
		        $confirm_data['created_date'] 		= $value['created_date_time'];
                $confirm_data['resuid'] 			= $value['voucher_updated_by'];
                $confirm_data['resfname'] 			= $value['voucher_updated_by_name'];
                $confirm_data['hotel_id'] 			= $hotel_id;
                $confirm_data['hotel_name'] 		= $hotel_name;
                $confirm_data['is_ripley_pass'] 	= ($ticket_details[$value['ticket_id']]->cod_id == RIPLEY_MUSEUM_ID && $ticket_id == RIPLEY_TICKET_ID)? 1: 0;
                $confirm_data['ticketId'] 			= $value['ticket_id'];
                $confirm_data['clustering_id']      = $value['clustering_id'];
                $confirm_data['shared_capacity_id'] = !empty($value['shared_capacity_id']) ? $value['shared_capacity_id'] : 0;
                $confirm_data['prepaid_reseller_id'] = $confirm_data['reseller_id'] = $value['reseller_id'];
                $confirm_data['prepaid_reseller_name'] = $confirm_data['reseller_name'] = $value['reseller_name'];
                $confirm_data['price'] 							= $value['price'] + $value['combi_discount_gross_amount'];
                $confirm_data['service_gross'] 					= 0;
                $confirm_data['service_cost_type'] 				= 0;
                $confirm_data['pertransaction'] 				= "0";
                if ($value['service_cost'] > 0) {

                    if ($value['service_cost_type'] == "1") {
                        $confirm_data['service_gross'] 		= $value['service_cost'];
                        $confirm_data['service_cost_type'] 	= $value['service_cost_type'];
                        $confirm_data['price'] 				= $value['price'] - $value['service_cost'] + $value['combi_discount_gross_amount'];
                    }

                    if ($value['service_cost_type'] == "2") {
                        $service_cost 						= $value['service_cost'];
                        $created_date 						= $value['created_at'];
                    }
                }
                //
                 //* Make sure, the passNo is passed with full URL
                 //* Ripley ticket condition is added becuase it contains http in its url when saved in hotel_ticket_overview and pass
                 //* is scanned through venue app
                ///

                $confirm_data['initialPayment'] 				= (object) $hotel_data[$vgno];
                $confirm_data['ticketpriceschedule_id'] 		= $value['tps_id'];
                $confirm_data['ticketwithdifferentpricing'] 	= $ticket_details[$value['ticket_id']]->ticketwithdifferentpricing;
                $confirm_data['slot_type'] 						= $value['timeslot'];
                $confirm_data['prepaid_type'] 					= $value['activation_method'];
                $confirm_data['userid'] 						= $value['voucher_updated_by'];
                $confirm_data['fname'] 							= (!empty($updateByName) && is_array($updateByName)? current($updateByName): 'Prepaid');
                $confirm_data['lname'] 							= (!empty($updateByName) && is_array($updateByName)? end($updateByName): 'ticket');
                $confirm_data['is_prioticket'] 					= $value['is_prioticket'];
                $confirm_data['check_age'] 						= 0;
                $confirm_data['cmpny'] 							= (object) $hotel_info[$hotel_id];
                $confirm_data['timeZone'] 						= $value['timezone'];
                $confirm_data['used']							= $used;
                $confirm_data['is_shop_product'] 				= $value['product_type'];
                $confirm_data['is_pre_ordered'] 				= 1;
                $confirm_data['ticketDetail'] 					= $ticket_details[$value['ticket_id']];
                $confirm_data['prepaid_ticket_id']              = $value['prepaid_ticket_id'];
                $confirm_data['supplier_gross_price'] 			= $value['supplier_price'];
                $confirm_data['supplier_ticket_amt'] 			= $value['supplier_original_price'];
                $confirm_data['supplier_tax_value'] 			= $value['supplier_tax'];
                if ($value['booking_status'] == '1') {
                    $booking_status = 1;
                }
                if (isset($value['is_addon_ticket']) && $value['is_addon_ticket'] != '') {
                    $confirm_data['is_addon_ticket'] = $value['is_addon_ticket'];
                } else {
                    $confirm_data['is_addon_ticket'] = 0;
                }
               if ($value['is_addon_ticket'] != "2") {                   
                   $confirm_data['merchant_admin_id'] =  $merchant_admin_id;
                   $confirm_data['merchant_admin_name'] = $merchant_admin_name;
               } else {
                   $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$value['tps_id']]['merchant_admin_name'];
                   $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$value['tps_id']]['merchant_admin_id'];
               }
                $confirm_data['distributor_reseller_id'] 		= $hotel_info[$hotel_id]['reseller_id'];
                $confirm_data['distributor_reseller_name'] 		= $hotel_info[$hotel_id]['reseller_name'];
                $confirm_data['tps_data'] 						= $tpsDetails;
                $confirm_data['tps_partner_financial'] 			= $tpsPartnerFin;
                $confirm_data['taxData'] 						= $taxData;
                $confirm_data['bookingInfo'] 					= $bookingInfo;
                $confirm_data['allCurrentSeasonTpsId'] 			= $allCurrentSeasonTpsId;
                $confirm_data['allCurrentTpsIds'] 			    = $allCurrentTpsIds;
                $confirm_data['tlcLevelData'] 			        = $tlcLevelData;
                $confirm_data['hotelLevelData'] 			    = $hotelLevelData;
                $confirm_data['is_refunded'] 			        = $value['is_refunded'];
                $confirm_data['related_product_id'] 			= $value['related_product_id'];
                $confirm_data['updated_at'] 			        = $value['updated_at'];
                $confirm_data['sub_catalog_id'] 			= $sub_catalog_id;
                $confirm_data['discount']                               = $value['discount'];
                $confirm_data['is_discount_in_percent']                 = $value['is_discount_in_percent'];
                $chlKey = implode("_", array($value['visitor_group_no'], $value['related_product_id'], $value['clustering_id']));
                if($value['is_addon_ticket'] == '2' && isset($transaction_id_array[$chlKey])) {
                    $transaction_id = $transaction_id_array[$chlKey];
                }
                else {
                    $transaction_id = $this->get_auto_generated_id_dpos($value['visitor_group_no'], $value['prepaid_ticket_id']);
                    $createKey = implode("_", array($value['visitor_group_no'], $value['ticket_id'], $value['clustering_id']));
                    $transaction_id_array[$createKey] = $transaction_id;
                    
                    $keyTicketBookingId = $value['visitor_group_no']."_".$value['ticket_id']."_".$value['selected_date']."_".$value['from_time']."_".$value['to_time'];
                    $ticketBookingId[$keyTicketBookingId] = $value['ticket_booking_id'];
                }
                $confirm_data['transaction_id'] 			    = $transaction_id;
		        $confirm_data['version'] 			            = $value['version'];
                $confirm_data['visit_date'] = (isset($value['scanned_at']) && $value['scanned_at'] != '') ? $value['scanned_at'] : strtotime(gmdate('Y-m-d H:i:s'));
                if ($confirm_data['is_addon_ticket'] == "2") {
                    $cluster_tickets_transaction_id_array[$value['ticket_id'] . '::' . $value['cluster_group_id'] . '::' . $value['clustering_id']][] = $transaction_id;
                    $this->CreateLog('checktransaction.php', json_encode($cluster_tickets_transaction_id_array), array()); 
                    $add_on[$value['cluster_group_id']][$value['clustering_id']][] = $add_on_transaction = $transaction_id_array[trim($value['cluster_group_id'])][trim($value['clustering_id'])];
                    $confirm_data['merchant_admin_name'] = $cluster_tickets_data[$value['tps_id']]['merchant_admin_name'];
                    $confirm_data['merchant_admin_id'] = $cluster_tickets_data[$value['tps_id']]['merchant_admin_id'];
                }
                
                if (!empty($main_ticket_combi_data) && isset($main_ticket_combi_data[$value['ticket_id']])) {
                    $confirm_data['is_combi'] = $main_ticket_combi_data[$value['ticket_id']];
                } else {
                    $confirm_data['is_combi'] = 0;
                }
                
                if ($confirm_data['is_addon_ticket'] == 0) {
                    $confirm_data['cluster_net_price'] = $cluster_net_price[$value['clustering_id']];
                } else {
                    $confirm_data['cluster_net_price'] = 0;
                }
                
                $confirm_data['scanned_pass'] = isset($value['scanned_pass']) ? $value['scanned_pass'] : '';
                
                $this->CreateLog('update_visitor_tickets_direct.php', 'prepared data for VT', array(json_encode($confirm_data)));
                $logs['confirm_data_'.date('H:i:s')]			= $confirm_data;

                $visitor_tickets_data 		= $this->order_process_vt_model->confirmprepaidTicketAtMuseum($confirm_data, 1);

                $VTGroupData                = $this->mergeVtArray($VTGroupData, $visitor_tickets_data['visitor_per_ticket_rows_batch']);
                $visitor_ids_array[] 		= $visitor_tickets_data['id'];
                
		$museum_net_fee = $distributor_net_fee = $hgs_net_fee = $museum_gross_fee = $distributor_gross_fee = $hgs_gross_fee = 0.00;
				
                if (!empty($visitor_tickets_data['visitor_per_ticket_rows_batch'])) {

                    foreach ($visitor_tickets_data['visitor_per_ticket_rows_batch'] as $visitor_row_batch) {

						if ($visitor_row_batch['row_type'] == '1') {
                            $general_fee = $visitor_row_batch['partner_gross_price'];
                        }
                        if($visitor_row_batch['row_type'] == '2') {
                            $museum_net_fee = $visitor_row_batch['partner_net_price'];
                            $museum_gross_fee = $visitor_row_batch['partner_gross_price'];
                        } else if($visitor_row_batch['row_type'] == '3') {
                            $distributor_net_fee = $visitor_row_batch['partner_net_price'];
                            $distributor_gross_fee = $visitor_row_batch['partner_gross_price'];
                        } else if($visitor_row_batch['row_type'] == '4') {
                            $hgs_net_fee = $visitor_row_batch['partner_net_price'];
                            $hgs_gross_fee = $visitor_row_batch['partner_gross_price'];
                        } 
                    }
                }
				
				$ptColumnUpd[$value['prepaid_ticket_id']]['museum_net_fee'] 			= $museum_net_fee;
                $ptColumnUpd[$value['prepaid_ticket_id']]['distributor_net_fee'] 		= $distributor_net_fee;
                $ptColumnUpd[$value['prepaid_ticket_id']]['hgs_net_fee'] 				= $hgs_net_fee;
                $ptColumnUpd[$value['prepaid_ticket_id']]['museum_gross_fee'] 			= $museum_gross_fee;
                $ptColumnUpd[$value['prepaid_ticket_id']]['distributor_gross_fee'] 		= $distributor_gross_fee;
                $ptColumnUpd[$value['prepaid_ticket_id']]['hgs_gross_fee'] 				= $hgs_gross_fee;
                $update_credit_limit                        = array();
                $update_credit_limit['museum_id']           = $value['museum_id'];
                $update_credit_limit['reseller_id']         = $value['reseller_id'];
                $update_credit_limit['hotel_id']            = $value['hotel_id'];
                $update_credit_limit['partner_id']          = $value['distributor_partner_id'];
                $update_credit_limit['cashier_name']        = $value['distributor_partner_name'];
                $update_credit_limit['hotel_name']          = $value['hotel_name'];
                $update_credit_limit['visitor_group_no']    = $value['visitor_group_no'];
                $update_credit_limit['merchant_admin_id']   = $value['merchant_admin_id'];

                if (array_key_exists($value['museum_id'], $update_credit_limit_data)) {
                    $update_credit_limit_data[$value['museum_id']]['used_limit']  += $general_fee;
                } 
                else {
                    $update_credit_limit['used_limit']  			= $general_fee;
                    $update_credit_limit_data[$value['museum_id']] 	= $update_credit_limit;
                }

                if ($paymentMethod == '' && $pspReference == '') {
                    $payment_method 		= trim($hotel_info[$hotel_id]['company']); // 0 = Bill to hotel
                } 
                else {
                    $payment_method 		= 'Others'; //   others
                }

                if ($service_cost > 0) {

                    $service_visitors_data 		= array();

                    $today_date 				= strtotime($value['created_at']) + ($hotel_info[$hotel_id]['timezone'] * 3600);
                    $service_visitors_data 		= array();
                    $net_service_cost 			= ($service_cost * 100) / ($tax[$hotel_info[$hotel_id]['service_cost_tax']] + 100);
                    $transaction_id 			= $visitor_ids_array[count($visitor_ids_array) - 1];
                    $visitor_ticket_id 			= substr($transaction_id, 0, -2) . '12';

                    $insert_service_data['id'] 					= $visitor_ticket_id;
                    $insert_service_data['is_prioticket'] 		= $is_prioticket;
                    $insert_service_data['created_date'] 		= gmdate('Y-m-d H:i:s', $value['created_at']);
                    $insert_service_data['selected_date'] 		= $selected_date;
                    $insert_service_data['from_time'] 			= $from_time;
                    $insert_service_data['to_time'] 			= $to_time;
                    $insert_service_data['slot_type'] 			= $slot_type;
                    $insert_service_data['transaction_id'] 		= $transaction_id;
                    $insert_service_data['visitor_group_no'] 	= $visitor_group_no;
                    $insert_service_data['vt_group_no'] 		= $visitor_group_no;
                    $insert_service_data['ticket_booking_id'] 	= $value['ticket_booking_id'];
                    $insert_service_data['invoice_id'] 			= '';
                    $insert_service_data['ticketId'] 			= '0';
                    $insert_service_data['ticketpriceschedule_id'] 	= '0';
                    $insert_service_data['tickettype_name'] 		= '';
                    $insert_service_data['ticket_title'] 		= "Service cost fee for transaction " . implode(",", $visitor_ids_array);
                    $insert_service_data['partner_id'] 			= $hotel_info[$hotel_id]['cod_id'];
                    $insert_service_data['hotel_id'] 			= $hotel_id;
                    $insert_service_data['pos_point_id'] 		= $pos_point_id;
                    $insert_service_data['pos_point_name'] 		= $pos_point_name;
                    $insert_service_data['partner_name'] 		= $hotel_name;
                    $insert_service_data['hotel_name'] 			= $hotel_name;
                    $insert_service_data['visit_date'] 			= $value['created_at'];
                    $insert_service_data['visit_date_time'] 	= gmdate('Y-m-d H:i:s', $value['created_at']);
                    $insert_service_data['paid'] 				= "1";
                    $insert_service_data['payment_method'] 		= $payment_method;
                    $insert_service_data['captured'] 			= "1";
                    $insert_service_data['debitor'] 			= "Guest";
                    $insert_service_data['creditor'] 			= "Debit";
                    $insert_service_data['partner_gross_price'] 				= $service_cost;
                    $insert_service_data['order_currency_partner_gross_price'] 	= $service_cost;
                    $insert_service_data['partner_net_price'] 					= $net_service_cost;
                    $insert_service_data['order_currency_partner_net_price'] 	= $net_service_cost;
                    $insert_service_data['tax_id'] 				= "0";
                    $insert_service_data['tax_value'] 			= $tax;
                    $insert_service_data['invoice_status'] 		= "0";
                    $insert_service_data['transaction_type_name'] 		= "Service cost";
                    $insert_service_data['paymentMethodType'] 			= $hotel_info[$hotel_id]['paymentMethodType'];
                    $insert_service_data['row_type'] 					= "12";
                    $insert_service_data['isBillToHotel'] 				= $hotel_data[$vgno]['isBillToHotel'];
                    $insert_service_data['activation_method'] 			= $hotel_data[$vgno]['activation_method'];
                    $insert_service_data['service_cost_type'] 			= "2";
                    $insert_service_data['used'] 				= $used;
                    $insert_service_data['timezone'] 			= $hotel_data[$vgno]['timezone'];
                    $insert_service_data['booking_status'] 		= $booking_status;
                    $insert_service_data['channel_id'] 			= $channel_id;
                    $insert_service_data['channel_name'] 		= $channel_name;
                    $insert_service_data['cashier_id'] 			= $cashier_id;
                    $insert_service_data['cashier_name'] 		= $cashier_name;
                    $insert_service_data['reseller_id'] 		= $hotel_info[$hotel_id]['reseller_id'];
                    $insert_service_data['reseller_name'] 		= $hotel_info[$hotel_id]['reseller_name'];
                    $insert_service_data['action_performed'] 	= $value['action_performed'];
                    $insert_service_data['updated_at'] 			= $value['updated_at'];
                    $insert_service_data['saledesk_id'] 		= $hotel_info[$hotel_id]['saledesk_id'];
                    $insert_service_data['saledesk_name'] 		= $hotel_info[$hotel_id]['saledesk_name'];
                    $insert_service_data['order_confirm_date'] 			= $confirm_data['order_confirm_date'];
                    $insert_service_data['payment_date'] 				= $confirm_data['payment_date'];
                    $insert_service_data['distributor_partner_id'] 		= $confirm_data['distributor_partner_id'];
                    $insert_service_data['distributor_partner_name']	= $confirm_data['distributor_partner_name'];
                    $insert_service_data['col7'] 				= gmdate('Y-m', $today_date);
                    $insert_service_data['col8'] 				= gmdate('Y-m-d', $today_date);
					$insert_service_data['version'] 			= $version;
                    $service_visitors_data[] 					= $insert_service_data;
                    $VTGroupData                                = $this->mergeVtArray($VTGroupData, $service_visitors_data);
                }
            }
            // Fetch extra options
            $this->secondarydb->rodb->select('*');
            $this->secondarydb->rodb->from('prepaid_extra_options');
            $this->secondarydb->rodb->where('visitor_group_no', $visitor_group_no);
            $result = $this->secondarydb->rodb->get();
            if ($result->num_rows() > 0) {
                
                $paymentMethod          = $hotel_data[$vgno]['paymentMethod'];
                $pspReference           = $hotel_data[$vgno]['pspReference'];
                if (!empty(trim($paymentMethod)) && !empty(trim($pspReference))) {
                    $payment_method 		= trim($hotel_info[$hotel_id]['company']); // 0 = Bill to hotel
                    $invoice_status = '6';
                } else {
                    $payment_method = 'Others'; //   others
                    $invoice_status = '0';
                }
                
                $extra_services = $result->result_array();
                $taxes          = array();
                $eoc            = 800;
                foreach ($extra_services as $service) {
                    
                    $used = '1';
                    if($hotel_data[$vgno]['activation_method'] == '13') {
                        $used = '0';
                    }
                    
                    $service['used']        = $used;
                    $service['scanned_at']  = strtotime(gmdate("Y-m-d H:i:s"));
                    
                    // If quantity of service is more than one then we add multiple transactions for financials page
                    if (!in_array($service['tax'], $taxes)) {
                        $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                        $taxes[$service['tax']] = $ticket_tax_id;
                    } else {
                        $ticket_tax_id = $taxes[$service['tax']];
                    }
                    $ticket_tax_value       = $service['tax'];
                    
                    for ($i = 0; $i < $service['quantity']; $i++) {
                        
                        $service_data_for_visitor = $service_data_for_museum = array();
                        $eoc++;
                        $p = 0;
                        $transaction_id                 = $visitor_group_no."".$eoc;
                        $visitor_ticket_id              = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '1');
                        
                        $total_amount                   = $service['price'];
                        $order_curency_total_amount     = $service['order_currency_price'];
                        $ticket_id                      = $service['ticket_id'];
                        $today_date                     = strtotime($service['created']) + ($hotel_data[$vgno]['timezone'] * 3600);
                        
                        if(isset($tpsDetails[$service['ticket_price_schedule_id']]) && !empty($tpsDetails[$service['ticket_price_schedule_id']])) {
                            $ticketTypeDetail = (object) $tpsDetails[$service['ticket_price_schedule_id']];
                        }
                        else {
                            $ticketTypeDetail = $this->getTicketTypeFromTicketpriceschedule_id($service['ticket_price_schedule_id']);
                        }
                        
                        $tickettype_name = '';
                        if(!empty($ticketTypeDetail)) {
                            
                            $tickettype_name = $ticketTypeDetail->tickettype_name;
                            if($ticketTypeDetail->parent_ticket_type == 'Group') {
                                $tickettype_name = $ticketTypeDetail->ticket_type_label;
                            }
                        }
                        
                        $service_data_for_visitor['id']                         = $visitor_ticket_id;
                        $service_data_for_visitor['is_prioticket']              = 0;
                        $service_data_for_visitor['created_date']               = gmdate('Y-m-d H:i:s');
                        $service_data_for_visitor['transaction_id']             = $transaction_id;
                        
                        $optionKey = $vgno."_".$ticket_id."_".$service['selected_date']."_".$service['from_time']."_".$service['to_time'];
                        if(isset($ticketBookingId[$optionKey])) {
                            $service_data_for_visitor['ticket_booking_id']          = $ticketBookingId[$optionKey];
                        }
                        
                        $service_data_for_visitor['visitor_group_no']           = $hotel_data[$vgno]['visitor_group_no'];
                        $service_data_for_visitor['invoice_id']                 = '';
                        $service_data_for_visitor['ticketId']                   = $ticket_id;
                        $service_data_for_visitor['ticket_title']               = $museum_name . '~_~' . $service['description'];
                        $service_data_for_visitor['ticketpriceschedule_id']     = $service['ticket_price_schedule_id'];
                        $service_data_for_visitor['tickettype_name']            = $tickettype_name;
                        $service_data_for_visitor['ticket_extra_option_id']     = $service['extra_option_id'];
                        $service_data_for_visitor['reseller_id']                = $value['reseller_id'];
                        $service_data_for_visitor['reseller_name']              = $value['reseller_name'];
                        $service_data_for_visitor['saledesk_id']                = $value['saledesk_id'];
                        $service_data_for_visitor['saledesk_name']              = $value['saledesk_name'];
                        $service_data_for_visitor['distributor_partner_id']     = $value['distributor_partner_id'];
                        $service_data_for_visitor['distributor_partner_name']   = $value['distributor_partner_name'];
                        $service_data_for_visitor['selected_date']              = ($service['selected_date']!='' && $service['selected_date']!=0)? $service['selected_date']: '0';
                        $service_data_for_visitor['from_time']                  = ($service['selected_date']!='' && $service['selected_date']!=0)? $service['from_time']: '0';
                        $service_data_for_visitor['to_time']                    = ($service['selected_date']!='' && $service['selected_date']!=0)? $service['to_time']: '0';
                        $service_data_for_visitor['slot_type']                  = ($service['selected_date']!='' && $service['selected_date']!=0)? $service['timeslot']: '';
                        
                        $service_data_for_visitor['partner_id']                 = $hotel_info[$hotel_id]['cod_id'];
                        $service_data_for_visitor['visit_date']                 = strtotime($service['created']);
                        $service_data_for_visitor['paid']                       = "1";
                        $service_data_for_visitor['payment_method']             = $payment_method;
                        $service_data_for_visitor['captured']                   = "1";
                        $service_data_for_visitor['debitor']                    = "Guest";
                        $service_data_for_visitor['creditor']                   = "Debit";
                        $service_data_for_visitor['partner_gross_price']        = $total_amount;
                        $service_data_for_visitor['order_currency_partner_gross_price']     = $order_curency_total_amount;
                        $service_data_for_visitor['partner_net_price']          = ($total_amount * 100) / ($ticket_tax_value + 100);
                        $service_data_for_visitor['order_currency_partner_net_price']       = ($order_curency_total_amount * 100) / ($ticket_tax_value + 100);
                        
                        $service_data_for_visitor['tax_id']                     = $ticket_tax_id;
                        $service_data_for_visitor['tax_value']                  = $ticket_tax_value;
                        $service_data_for_visitor['invoice_status']             = "0";
                        $service_data_for_visitor['transaction_type_name']      = "Extra option sales";
                        $service_data_for_visitor['ticket_extra_option_id']     = $service['extra_option_id'];
                        
                        $service_data_for_visitor['paymentMethodType']          = $hotel_info[$hotel_id]['paymentMethodType'];
                        $service_data_for_visitor['row_type']                   = "1";
                        $service_data_for_visitor['isBillToHotel']              = $hotel_data[$vgno]['isBillToHotel'];
                        $service_data_for_visitor['activation_method']          = $hotel_data[$vgno]['activation_method'];
                        $service_data_for_visitor['channel_type']               = $hotel_data[$vgno]['channel_type'];
                        $service_data_for_visitor['vt_group_no']                = $hotel_data[$vgno]['visitor_group_no'];
                        $service_data_for_visitor['visit_date_time']            = $service['created'];
                        $service_data_for_visitor['col8']                       = gmdate('Y-m-d', $today_date);
                        $service_data_for_visitor['col7']                       = gmdate('Y-m', $today_date);
                        $service_data_for_visitor['ticketAmt']                  = $total_amount;
                        $service_data_for_visitor['timezone']                   = $hotel_data[$vgno]['timezone'];
                        $service_data_for_visitor['is_prepaid']                 = "1";
                        $service_data_for_visitor['booking_status']             = "1";
                        $service_data_for_visitor['used']                       = $used;
                        $service_data_for_visitor['hotel_id']                   = $hotel_id;
                        $service_data_for_visitor['hotel_name']                 = $hotel_name;
                        $service_data_for_visitor['market_merchant_id']         = $value['market_merchant_id'];
                        $service_data_for_visitor['merchant_admin_id']          = $extra_option_merchant_data[$ticket_id];
                        
                        /* here added some extra data  */
                        $service_data_for_visitor['order_confirm_date']         = ($order_confirm_date != '') ? $order_confirm_date : date("Y-m-d H:i:s");
                        if (!empty($museum_name)) {
                            $service_data_for_visitor['museum_name']            = $museum_name;
                        }
                        if (!empty($museum_id)) {
                            $service_data_for_visitor['museum_id']              = $museum_id;
                        }
                        if (!empty($value['payment_date'])) {
                            $service_data_for_visitor['payment_date']           = $value['payment_date'];
                        }
                        if( !empty($value['action_performed']) ) {
                            $service_data_for_visitor['action_performed']       = $value['action_performed'];
                        } else {
                            $service_data_for_visitor['action_performed']       = '0';
                        }
                        $service_data_for_visitor['updated_at']                 = gmdate('Y-m-d H:i:s');
                        $extra_option_visitors_data[] 					        = $service_data_for_visitor;
                        $VTGroupData                                            = $this->mergeVtArray($VTGroupData, $extra_option_visitors_data);
                        
                        $service_data_for_museum                                = $service_data_for_visitor;
                        unset($service_data_for_museum['visitor_group_no'], 
                                $service_data_for_museum['ticket_extra_option_id'], 
                                $service_data_for_museum['payment_method'], 
                                $service_data_for_museum['captured'], 
                                $service_data_for_museum['order_currency_partner_gross_price'], 
                                $service_data_for_museum['order_currency_partner_net_price'],
                                $service_data_for_museum['ticket_extra_option_id'], 
                                $service_data_for_museum['activation_method']);
                        
                        
                        $visitor_ticket_id = $this->get_auto_generated_id_dpos($visitor_group_no, $transaction_id, $p . '2');
                        $service_data_for_museum['id']                              = $visitor_ticket_id;
                        $service_data_for_museum['ticket_title']                    = $service['name'] . ' (Extra option)';
                        $service_data_for_museum['partner_id']                      = $museum_id;
                        $service_data_for_museum['partner_name']                    = $museum_name;
                        $service_data_for_museum['paid']                            = "0";
                        $service_data_for_museum['debitor']                         = $museum_name;
                        $service_data_for_museum['creditor']                        = 'Credit';
                        $service_data_for_museum['transaction_type_name']           = "Extra service cost";
                        $service_data_for_museum['row_type']                        = "2";
                        $service_data_for_museum['ticketPrice']                     = $total_amount;
                        $service_data_for_museum['commission_type']                 = "0";
                        $service_data_for_museum['isCommissionInPercent']           = "0";
                        $service_data_for_museum['service_name']                    = SERVICE_NAME;
                        $VTGroupData                                                = $this->mergeVtArray($VTGroupData, array($service_data_for_museum));
                    }
                }
            }
        }
            
		// echo "<pre>";
			// print_r($VTGroupData);
		// echo "</pre>";
		// exit;
		
        if(!empty($VTGroupData)) {

			$db_debug = $this->secondarydb->db->db_debug; //save setting
			$this->secondarydb->db->db_debug = FALSE; //disable debugging for queries
			
            if(!$this->insert_batch($vt_table, $VTGroupData, $db_type)) {
				echo "<pre> ERROR = "; print_r($this->secondarydb->db->error()); echo "</pre>";
			}
            $logsData['vt_update_query_'.date("Y-m-d H:i:s")] = $this->secondarydb->db->last_query();            
			$this->db->db_debug = $db_debug; //restore setting
            $this->CreateLog('update_in_db_queries.php', 'vtquery=>', array('queries ' => $this->secondarydb->db->last_query()));
            $logs['insert_vt_'.date('H:i:s')]=$this->secondarydb->db->last_query();
            if(SYNCH_WITH_RDS_REALTIME) {
                $this->insert_batch($vt_table, $VTGroupData, 4);
            }
			
            // update VT of DB2
            if( !empty($sns_message_vt) && isset($sns_message_vt) ) {

                foreach ($sns_message_vt as $query) {

                    $query = trim($query);
                    $this->secondarydb->db->query($query);
                    $this->CreateLog('update_in_db_queries.php', 'db2query=>', array('queries ' => $query));
                    // To update the data in RDS realtime
                    if( ( strstr($query,'visitor_tickets') || strstr($query,'prepaid_tickets') || strstr($query,'hotel_ticket_overview') ) && SYNCH_WITH_RDS_REALTIME ) {
                        $this->fourthdb->db->query($query);
                    }
                }
            }

            // send VT id update request in PT table of DB1 and DB2
            $this->CreateLog('vt_id_update.php', 'Db2 update ==>>', array("UPDATE_SECONDARY_DB===>>>" => UPDATE_SECONDARY_DB));
            
            $prepareBq 				= $this->prepare_bq_data($VTGroupData);
			if(!empty($prepareBq)) {
                $preparebqchunks = array_chunk($prepareBq, 5);
                $this->CreateLog('vt_bq_insertion_import_booking.php', 'ENVIORNMENT ==>>', array("SERVER_ENVIRONMENT===>>>" => SERVER_ENVIRONMENT));
                
				if (SERVER_ENVIRONMENT != 'Local') {
					
					/* Load SQS library. */
					include_once 'aws-php-sdk/aws-autoloader.php';
					$this->load->library('Sqs');
					$sqs_object = new Sqs();
					$this->load->library('Sns');
                    $sns_object = new Sns();
                    $agg_vt_insert_queueUrl = AGG_VT_INSERT_QUEUEURL;
                    foreach($preparebqchunks as $bquery_chunk) {
                        $this->CreateLog('vt_bq_insertion_import_booking.php', 'DATA ARRAY ==>>', array("DATA===>>>" => json_encode($bquery_chunk)));				
                        $final_visitor_data_to_insert_big_query_compressed = base64_encode(gzcompress(json_encode($bquery_chunk)));									
                        // This Fn used to send notification with data on AWS panel. 
                        $MessageId = $sqs_object->sendMessage($agg_vt_insert_queueUrl, $final_visitor_data_to_insert_big_query_compressed);
                        // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                        if ($MessageId) {
                            $sns_object->publish($agg_vt_insert_queueUrl, AGG_VT_INSERT_ARN);
                        }
                    }
				}
			}
			
            return array_unique(array_column($VTGroupData, 'visitor_group_no'));
        }
    }
	
	private function prepare_bq_data($data= array()) {
		
        $vt_table = (!empty(VT_TABLE)? VT_TABLE: 'visitor_tickets');
        
        if(!empty($data)) {
            $db = $this->secondarydb->db;
            $db->Select("activation_method,time_based_done,id,is_prioticket,targetlocation,card_name,created_date,tp_payment_method,order_confirm_date,transaction_id,visitor_invoice_id,invoice_id,channel_id,channel_name,reseller_id,reseller_name,saledesk_id,partner_category_id,partner_category_name,saledesk_name,financial_id,financial_name,ticketId,invoice_type,ticket_title,ticketwithdifferentpricing,ticketpriceschedule_id,hto_id,visitor_group_no,vt_group_no,visit_date_time,partner_id,partner_name,is_custom_setting,museum_name,museum_id,hotel_name,primary_host_name,hotel_id,is_refunded,shift_id,pos_point_id,pos_point_name,passNo,pass_type,ticketAmt,visit_date,ticketType,tickettype_name,paid,payment_method,isBillToHotel,pspReference,card_type,ticketPrice,captured,age_group,discount,is_block,isDiscountInPercent,debitor,creditor,total_gross_commission,total_net_commission,partner_gross_price,partner_gross_price_without_combi_discount,partner_net_price,order_currency_partner_gross_price,order_currency_partner_net_price,partner_net_price_without_combi_discount,isCommissionInPercent,tax_id,tax_value,extra_discount,distributor_partner_id,distributor_partner_name,payment_date,tax_name,timezone,adyen_status,invoice_status,row_type,merchant_admin_id,order_updated_cashier_id,order_updated_cashier_name,market_merchant_id,updated_by_id,updated_by_username,voucher_updated_by,voucher_updated_by_name,redeem_method,cashier_id,cashier_name,roomNo,nights,user_name,user_age,gender,user_image,visitor_country,merchantAccountCode,merchantReference,original_pspReference,targetcity,paymentMethodType,service_name,distributor_status,transaction_type_name,shopperReference,issuer_country_code,selected_date,booking_selected_date,from_time,to_time,slot_type,ticket_status,booking_status,channel_type,ticket_booking_id,without_elo_reference_no,extra_text_field_answer,is_voucher,group_type_ticket,group_price,group_quantity,group_linked_with,supplier_currency_code,supplier_currency_symbol,order_currency_code,order_currency_symbol,currency_rate,col7,col8,is_shop_product,used,issuer_country_name,distributor_type,commission_type,scanned_pass,groupTransactionId,is_prepaid,account_number,chart_number,supplier_gross_price,supplier_discount,supplier_ticket_amt,supplier_tax_value,supplier_net_price,action_performed,updated_at,col2,last_modified_at,deleted, merchant_currency_code, merchant_price, merchant_tax_id, admin_currency_code, all_ticket_ids AS voucher_reference, cashier_register_id")->from($vt_table)->where_in("vt_group_no", array_unique(array_column($data, 'visitor_group_no')));
            $query = $db->get();
            if($query->num_rows() > 0 ) {
                return $query->result_array();
            } else {
                return array();
            }
        } else {
            return array();
        }	
		
		
 	}
		
    /**
     * @Name        : companyNameIn
     * @Purpose     : Get all the hotel's data for the passed hotel ids.
     * @param type  : $hotelIds=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function companyNameIn($hotelIds = array()) {
        
        if(empty($hotelIds) || !is_array($hotelIds)) {
            return false;
        }
        
        $data = array();
        if(!empty($hotelIds)) {
            
            $db = $this->primarydb->db;
            $db->Select("service_cost_tax, channel_id, reseller_id, reseller_name, company, timezone, cod_id, paymentMethodType, saledesk_id, 
    saledesk_name, invoice_type, channel_name, financial_id, financial_name, distributor_type, sub_catalog_id")->from("qr_codes")->where_in("cod_id", $hotelIds);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                
                $data = array_combine(array_column($result, 'cod_id'), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : ticketIdsIn
     * @Purpose     : Get all the ticket's data for the passed ticket ids.
     * @param type  : $ticketIds=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function ticketIdsIn($ticketIds = array()) {
        
        if(empty($ticketIds) || !is_array($ticketIds)) {
            return false;
        }
        
        $data = array();
        if(!empty($ticketIds)) {
            
            $db = $this->primarydb->db;
            $db->Select('mec_id, cod_id, ticketwithdifferentpricing, postingEventTitle, is_reservation, subtotal, museum_name, 
                                    museumCommission, deal_type_free, discount, saveamount, hotelCommission, calculated_hotel_commission,
                                    hgsCommission, calculated_hgs_commission, isCommissionInPercent, museum_tax_id, hotel_tax_id, 
                                    hgs_tax_id, ticket_tax_id, timeZone, ticketwithdifferentpricing, totalCommission, museumNetPrice,
                                    hotelNetPrice, hgsnetprice, ticketPrice, totalNetCommission, ticket_net_price, hgs_provider_id,
                                    hgs_provider_name, hgs_tax_value, ticket_tax_value')
            ->from("modeventcontent")->where_in("mec_id", $ticketIds);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                
                $data = array_combine(array_column($result, 'mec_id'), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : hotelTaxIn
     * @Purpose     : Get all the hotel's tax data for the passed tax ids.
     * @param type  : $hotelTaxIds=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function hotelTaxIn($hotelTaxIds = array()) {
        
        if(empty($hotelTaxIds) || !is_array($hotelTaxIds)) {
            return false;
        }
        
        $data = array();
        if(!empty($hotelTaxIds)) {
            
            $db = $this->primarydb->db;
            $db->Select('tax_value')->from("store_taxes")->where_in("id", $hotelTaxIds);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                
                $data = array_combine(array_column($result, 'tax_value'), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : tpsIdsIn
     * @Purpose     : Get all the ticket pricing data for the passed tps_ids.
     * @param type  : $tpsIdsIn=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function tpsIdsIn($tpsIdsIn = array()) {
        
        if(empty($tpsIdsIn) || !is_array($tpsIdsIn)) {
            return false;
        }
        
        $data = array();
        if(!empty($tpsIdsIn)) {
            
            $db = $this->primarydb->db;
            $db->select('tps.id, tps.ticketType, tps.pricetext, tps.agefrom, tps.ageto, tps.discount, tps.saveamount, 
                        tps.deal_type_free, tps.totalCommission, tps.totalNetCommission, tps.museumCommission, 
                        tps.hotelCommission, tps.calculated_hotel_commission, tps.hgsCommission, 
                        tps.calculated_hgs_commission, tps.isCommissionInPercent, 
                        tps.museum_tax_id, tps.hotel_tax_id, tps.hgs_tax_id, tps.ticket_tax_id, tps.museumNetPrice, 
                        tps.hotelNetPrice, tps.hgsnetprice, tps.ticket_net_price, tps.hgs_provider_id, tps.hgs_provider_name, 
                        tps.ticket_type_label, tt.ticketType as tickettype_name')
                ->from("ticketpriceschedule tps")
                ->join("ticket_types tt", "tt.id = tps.ticketType AND tt.status='1'", "LEFT")
                ->where_in("tps.id", $tpsIdsIn);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                
                $result = array_map(function($a) { 
                    return $a = array_merge($a, array("tax_key" => implode("_", array($a['hotel_tax_id'], $a['ticket_tax_id'], 
                                                                                      $a['hgs_tax_id'], $a['museum_tax_id'])))); 
                }, $result);
                $data 				= array_combine(array_column($result, 'id'), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : tpsPartnerFinancialIn
     * @Purpose     : Get all the financial agent pricing data for the passed tps_ids.
     * @param type  : $tpsIdsIn=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function tpsPartnerFinancialIn($tpsIdsIn = array()) {
        
        if(empty($tpsIdsIn) || !is_array($tpsIdsIn)) {
            return false;
        }
        
        $data = array();
        if(!empty($tpsIdsIn)) {
            
            $db = $this->primarydb->db;
            $db->Select("*")->from("ticketfinancialagent")->where_in("ticketpriceschedule_id", $tpsIdsIn);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                
                $data = array_combine(array_column($result, 'ticketpriceschedule_id'), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : getTaxDataIn
     * @Purpose     : Get all the tax data for the passed tax_ids and cod_id.
     * @param type  : $taxtype = '0', $service_id = 0, $cod_id = 0, $taxIds = array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getTaxDataIn($taxtype = '0', $service_id = 0, $cod_id = 0, $taxIds = array()) {
        
        if(empty($taxIds) || !is_array($taxIds)) {
            return false;
        }
        
        $db = $this->primarydb->db;
        $responseArray = array();
        if (!empty($taxIds) && is_array($taxIds)) {
            
            foreach($taxIds as $val) {
                
                $ids = array_filter(explode("_", $val));
                
                $db->Select("id, tax_id, tax_value, tax_name")->from("tax_names_for_sales_distributor")->where("cod_id", $cod_id);
                $db->where("tax_name <>", '');
                $db->where_in("tax_id", $ids);
                $res = $db->get();
                if (!empty($cod_id) && $res->num_rows() > 0) {
                    $responseArray[$val] = $res->result();
                }
                else {
                    
                    $db->Select("id, tax_value, tax_name")->from("store_taxes");
                    if ($taxtype == '0') {
                        $db->where("tax_type", '0');
                    } 
                    else if ($taxtype == '1') {
                        $db->where("tax_type", '1');
                    }
                    
                    if (!empty($taxIds) && is_array($taxIds)) {
                        $db->where_in("id", $ids);
                    }
                    else if (!empty($taxIds)) {
                        $db->where_in("tax_id", $tax_id);
                    }
                    
                    if (!empty($service_id)) {
                        $db->where("service_id", $service_id);
                    }
                    
                    $res = $db->get();
                    $responseArray[$val] = $res->result();
                }
            }
        }
        
        return $responseArray;
    }
	
    /**
     * @Name        : getTlcClcLevelData
     * @Purpose     : Get all the TLC/CLC pricing data for the passed hotel_id and cod_id.
     * @param type  : $taxtype = '0', $service_id = 0, $cod_id = 0, $taxIds = array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getTlcClcLevelData($tpsIds=array(), $channelId=array(), $hotel_id=0, $ticketId=0) {
        
        if(empty($tpsIds) || empty($hotel_id) || empty($ticketId)) {
            return false;
        }
        
        $db = $this->primarydb->db;
        $data = $tpsClc = array();
        $tlc_commission_array = array();
        
        if(!empty($tpsIds)) {
            
            $db->select("*");
            $db->from("ticket_level_commission");
            $db->where("hotel_id", $hotel_id);
            $db->where("ticket_id", $ticketId);
            $db->where_in("ticketpriceschedule_id", $tpsIds);
            $query = $db->get();
            if ($query->num_rows() > 0) {
                
                $resultTlc = $query->result_array();
                foreach($resultTlc as $valueTlc) {
                    
                    $tpid = $valueTlc['ticketpriceschedule_id'];
                    $keyMrg = $hotel_id . '_' . $ticketId . '_' . $tpid;
                    if($valueTlc['hotel_prepaid_commission_percentage'] > 0 || $valueTlc['hgs_prepaid_commission_percentage'] > 0) {
                        
                        //BOC for tlc adjust checking & creating overwrite variable list
                        if ($valueTlc['is_adjust_pricing'] != 1) {

                            $tlc_commission_array[$keyMrg]['hotel_prepaid_commission_percentage'] 	= $valueTlc['hotel_prepaid_commission_percentage'];
                            $tlc_commission_array[$keyMrg]['hotel_postpaid_commission_percentage'] 	= $result['hotel_postpaid_commission_percentage'];
                            $tlc_commission_array[$keyMrg]['hotel_commission_tax_id'] 				= $valueTlc['hotel_commission_tax_id'];
                            $tlc_commission_array[$keyMrg]['hotel_commission_tax_value'] 			= $valueTlc['hotel_commission_tax_value'];
                            $tlc_commission_array[$keyMrg]['hgs_prepaid_commission_percentage'] 	= $valueTlc['hgs_prepaid_commission_percentage'];
                            $tlc_commission_array[$keyMrg]['hgs_postpaid_commission_percentage'] 	= $valueTlc['hgs_postpaid_commission_percentage'];
                            $tlc_commission_array[$keyMrg]['hgs_commission_tax_id'] 				= $valueTlc['hgs_commission_tax_id'];
                            $tlc_commission_array[$keyMrg]['hgs_commission_tax_value'] 				= $valueTlc['hgs_commission_tax_value'];

                            $clc_adjust_price[$hotel_id . '_' . $ticketId . '_' . $tpid] = true;
                            $tpsClc[] = $hotel_id . '_' . $ticketId . '_' . $tpid;
                        } 
                        else {
                            $data[$hotel_id . '_' . $ticketId . '_' . $tpid] = $valueTlc;
                        }
                    }
                    else {
                        $tpsClc[] = $hotel_id . '_' . $ticketId . '_' . $tpid;	
                    }
                }
            }
            else {
                $tpsClc = $tpsIds;
            }
            
            $clcFlag = array();
            if (!empty($channelId) && !empty($tpsClc)) {
                
                $getTpsIds = array_map(function($a) { 
                                    $exp = explode("_", $a); 
                                    return end($exp);
                                }, $tpsClc);
                
                $db->select("*");
				/*$db->select("ticketpriceschedule_id, hotel_prepaid_commission_percentage, hgs_prepaid_commission_percentage, 
				hotel_postpaid_commission_percentage, hotel_commission_tax_id, hotel_commission_tax_value, 
				hgs_prepaid_commission_percentage, hgs_postpaid_commission_percentage, hgs_commission_tax_id, 
				hgs_commission_tax_value");*/
                $db->from("channel_level_commission");
                $db->where("channel_id", $channelId);
                $db->where("ticket_id", $ticketId);
                $db->where_in("ticketpriceschedule_id", $getTpsIds);
                $query = $db->get();
                if ($query->num_rows() > 0) {
                    
                    $result = $query->result_array();
                    foreach($result as $valClc) {
                        
                        $cTpid = $valClc['ticketpriceschedule_id'];
                        if ($clc_adjust_price[$hotel_id . '_' . $ticketId . '_' . $cTpid]) {
                            
                            if (!empty($tlc_commission_array[$hotel_id . '_' . $ticketId . '_' . $cTpid])) {
                                
                                //replace clc level commission with tlc level commissions
                                $result = array_replace($valClc, $tlc_commission_array[$hotel_id . '_' . $ticketId . '_' . $cTpid]);
                            }
                            $data[$hotel_id . '_' . $ticketId . '_' . $cTpid] = $valClc;
                        }
                        
                        //EOC to pass (overwrite) the TLC level commission
                        if ($valClc['hotel_prepaid_commission_percentage'] > 0 || $valClc['hgs_prepaid_commission_percentage'] > 0) {
                            $data[$hotel_id . '_' . $ticketId . '_' . $cTpid] = $valClc;
                        }
                    }
                }
                else {
                    $clcFlag = $tpsClc;
                }
            }
            else {
                $clcFlag = $tpsClc;
            }
            
            if(!empty($clcFlag)) {
                
                $db->select("*");
                $db->from("qr_codes");
                $db->where("cod_id", $hotel_id);
                $result = $db->get()->row_array();
                if(!empty($result)) {
                    
                    if ($result['hotel_prepaid_commission_percentage'] > 0 || $result['hgs_prepaid_commission_percentage'] > 0) {
                        
                        foreach($clcFlag as $valCC) {
                            $data[$valCC] = array_replace($result, $tlc_commission_array);
                        }
                    } 
                    else {
                        
                        foreach($clcFlag as $valCC) {
                            
                            if (!empty($tlc_commission_array[$valCC])) {
                                $data[$valCC] = $tlc_commission_array[$valCC];
                            }
                        }
                    }
                }
            }
        }
        
        return $data;
    }
	
    /**
     * @Name        : getHotelLevelAff
     * @Purpose     : Get all the hotel level commission from TLC/CLC pricing data for the passed hotel_id, tps_id and channel_id.
     * @param type  : $taxtype = '0', $service_id = 0, $cod_id = 0, $taxIds = array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getHotelLevelAff($tpsIds=array(), $channelId=array(), $hotel_id=0, $ticketId=0) {
        
        if(empty($tpsIds) || empty($hotel_id) || empty($ticketId)) {
            return false;
        }
        
        $ticket_level_commission_flag = 1;
        
        $db = $this->primarydb->db;
        $db->select("*")->from("ticket_level_commission");
        $db->where(array("hotel_id" => $hotel_id, "ticket_id" => $ticketId));
        if(!empty($tpsIds)) {
            $db->where_in("ticketpriceschedule_id", $tpsIds);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {

            $result = $query->row();
            if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                $ticket_level_commission_flag = 0;
            }
        }

        if ($ticket_level_commission_flag == 0) {

            $db->select("*");
            $db->from("ticket_level_affiliates");
            $db->where("hotel_id", $hotel_id);
            $db->where("ticket_id", $ticketId);
            if(!empty($tpsIds)) {
                $db->where_in("ticketpriceschedule_id", $tpsIds);
            }
            $query = $db->get();
            if ($query->num_rows() > 0) {
                
                $data   = $query->result_array();
                $result = array_map(function($a) {
                                return $a = array_merge($a, array("key" => implode("_", array($a['hotel_id'], $a['ticket_id'], 
                                                                                          $a['ticketpriceschedule_id'])))); 
                            }, $data);
                return array_combine(array_column($result, 'key'), $result);
            }
            else {
                return false;
            }
        }
        
        //BOC channel level affiliates
        if (!empty($channelId) && isset($channelId)) {

            $db->select("*");
            $db->from("channel_level_affiliates");
            $db->where("channel_id", $channelId);
            $db->where("ticket_id", $ticketId);
            if ($tpsIds != '' && $tpsIds > 0) {
                $db->where_in("ticketpriceschedule_id", $tpsIds);
            }
            $query = $db->get();
            if ($query->num_rows() > 0) {

                $data   = $query->result_array();
                $result = array_map(function($a) use ($hotel_id) {
                    return $a = array_merge($a, array("key" => implode("_", array($hotel_id, $a['ticket_id'], 
                                                                                      $a['ticketpriceschedule_id'])))); 
                }, $data);
                return array_combine(array_column($result, 'key'), $result);
            }
        } 
        //EOC channel level affiliates
        
        // BOC hotel level affiliates
        $db->select("*");
        $db->from("hotel_level_affiliates");
        $db->where("hotel_id", $hotel_id);
        $query = $db->get();
        if ($query->num_rows() > 0) {

            $data   = $query->result();
            $result = array_map(function($a) use ($hotel_id) {
                return $a = array_merge($a, array("key" => implode("_", array($hotel_id, $a['ticket_id'])))); 
            }, $data);
            return array_combine(array_column($result, 'key'), $result);

        } 
        
        return false;
        // EOC hotel level affiliates
    }
    
    /**
     * @Name        : emptyChk
     * @Purpose     : check value if not empty, isset and return the respective value or returns the default value.
     * @param type  : $value, $defaultValue=''
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function emptyChk($value, $defaultValue='') {
        
        return (!empty($value)? $value: $defaultValue);
    }
	
    /**
     * @Name        : getAllBookingInfo
     * @Purpose     : return all the partner names on the basis of booking names and hotel_id
     * @param type  : $bookingNames=array(), $hotel_id=0
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getAllBookingInfo($bookingNames=array(), $hotel_id=0) {
        
        if(empty($bookingNames) || !is_array($bookingNames) || empty($hotel_id)) {
            return false;
        }
        
        $data = array();
        if(!empty($bookingNames)) {
            
            $db = $this->primarydb->db;
            $db->Select('id, name, distributer_id')->from("nav_customers");
            $db->group_start();
            foreach($bookingNames as $key => $val) {
                
                if($key==0) {
                    $db->like("name", $val);
                }
                else {
                    $db->or_like("name", $val);
                }
            }
            $db->group_end();
            $db->where("distributer_id", $hotel_id);
            
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                $data = array_combine(array_map(function($a) { return $a['name'] . '_' . $a['distributer_id']; }, $result), $result);
            }
        }
        return $data;
    }
	
    /**
     * @Name        : mergeVtArray
     * @Purpose     : Pushes new key and element in existing array
     * @param type  : $existing=array(), $newArray=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    private function mergeVtArray($existing=array(), $newArray=array()) {
        
        if(!empty($newArray)) {
            foreach($newArray as $val) {
                array_push($existing, $val);
            }
        }
        return $existing;
    }
    
    /**
     * @Name        : getCurrentSeasonTpsId
     * @Purpose     : returns current date pricing on the basis of ticket_id, ticket name and current datetime
     * @param type  : $array=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getCurrentSeasonTpsId($array=array()) {
        
        if(empty($array) || !is_array($array)) {
            return false;
        }
        
        $db             = $this->primarydb->db;
        $responseArray  = array();
        $timestamp      = time();
        foreach($array as $val) {
            
            $expVal = explode("_", $val);
            $db->Select("id")->from("ticketpriceschedule")
                ->where(array("ticket_id" => $expVal[0], "LOWER(ticket_type_label)" => strtolower($expVal[1])));
            $db->where("start_date <= '{$timestamp}' AND end_date >= '{$timestamp}'");
            $res = $db->get();
            if ($res->num_rows() > 0) {
                $responseArray[$val] = $res->row()->id;
            }
            else {
                $responseArray[$val] = 0;
            }
        }
        
        return $responseArray;
    }
    
    /**
     * @Name        : getCurrentSeasonTpsIdDetails
     * @Purpose     : returns pricing on the basis of passed tps_ids.
     * @param type  : $ids=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getCurrentSeasonTpsIdDetails($ids = array()) {
        
        if(empty($ids) || !is_array($ids)) {
            return false;
        }
        
        $data       = array();
        if(!empty($ids)) {
            
            $db = $this->primarydb->db;
            $db->Select('id, ticketType, pricetext, agefrom, ageto, discount, saveamount, deal_type_free, totalCommission, 
                        totalNetCommission, museumCommission, hotelCommission, calculated_hotel_commission, hgsCommission, 
                        calculated_hgs_commission, isCommissionInPercent, museum_tax_id, hotel_tax_id, hgs_tax_id, 
                        ticket_tax_id, museumNetPrice, hotelNetPrice, hgsnetprice, ticket_net_price, 
                        hgs_provider_id, hgs_provider_name')->from("ticketpriceschedule")->where_in("id", $ids);
            $query = $db->get();
            $result = $query->result_array();
            if(!empty($result)) {
                $data = array_combine(array_column($result, 'id'), $result);
            }
        }
        
        return $data;
    }
	
	/**
     * @Name        : array_except
     * @Purpose     : Unset multiple keys from an array.
     * @param type  : $array=array(), $keys=array()
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    private function array_except($array, $keys) {
        
        foreach($keys as $key) {
            unset($array[$key]);
        }
        
        return $array;
    }
    
    /**
     * @Name        : getTicketTypeFromTicketpriceschedule_id
     * @Purpose     : Unset multiple keys from an array.
     * @param type  : $ticketpriceschedule_id
     * @return array
     * @CreatedBy   : Jatinder Kumar <jatinder.aipl@gmail.com> on 10 September, 2019
     */
    function getTicketTypeFromTicketpriceschedule_id($ticketpriceschedule_id = '') {
        
        $db = $this->primarydb->db;
        $query = 'select tps.*, tt.ticketType as tickettype_name from ticketpriceschedule tps left join ticket_types tt on tt.id=tps.ticketType and tt.status="1" where tps.id = ' . $ticketpriceschedule_id;
        $data = $db->query($query);
        if ($data->num_rows() > 0) {
            $result = $data->row();
            return $result;
        } else {
            return false;
        }
    }
       /* #region to get entities flag details  */
   /**
    * @name get_flags_details .
    * @called from insert_batch_orders_table in  Pos controller. 
    * @param array $flag_entities.
    * @purpose : This function to get entities flag details from flags and flag_entities table.
    * @created by : supriya saxena<supriya10.aipl@gmail.com>
    * @created at: 20 oct , 2020
    */
    function get_flags_details($flag_entities = []) {
        $flag_entity_details = $flag_entity_sorted_details = [];
        $count = 0;
        $this->createLog('flag_logs.php', 'flag_entities', array("data" => json_encode($flag_entities)));
        $db = $this->primarydb->db;
        $db->select("fe.entity_id, fe.entity_type,fe.id as f_en_id, f.name, f.value, f.id, f.flag_uid, f.type ");
        $db->from("flag_entities fe");
        $db->join('flags f', 'fe.item_id = f.id', 'left');
        $db->where('f.is_deleted = "0" AND f.status = "1"');
        if(!empty($flag_entities)) {
            $where ='(';
            if (!empty($flag_entities['supplier_id'])) {
                $count = 1;
                $where .= '(fe.entity_type = "1" AND fe.entity_id IN (' . implode(',', $flag_entities['supplier_id']) . '))';
            }
            if (!empty($flag_entities['reseller_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "2" AND fe.entity_id IN (' . implode(',', $flag_entities['reseller_id']) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "2" AND fe.entity_id IN (' . implode(',', $flag_entities['reseller_id']) . '))';
                }
            }
            if (!empty($flag_entities['distributor_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "3" AND fe.entity_id IN (' . implode(',', $flag_entities['distributor_id']) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "3" AND fe.entity_id IN (' . implode(',', $flag_entities['distributor_id']) . '))';
                }
            }
            if (!empty($flag_entities['partner_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "4" AND fe.entity_id IN (' . implode(',', $flag_entities['partner_id']) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "4" AND fe.entity_id IN (' . implode(',', $flag_entities['partner_id']) . '))';
                }
            }
            if (!empty($flag_entities['ticket_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "5" AND fe.entity_id IN (' . implode(',', $flag_entities['ticket_id']) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "5" AND fe.entity_id IN (' . implode(',', $flag_entities['ticket_id']) . '))';
                }
            }

            if (!empty($flag_entities['contact_uid'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "6" AND fe.entity_id IN ("' . implode('", "', $flag_entities['contact_uid']) . '"))';
                } else {
                    $where .= ' || (fe.entity_type = "6" AND fe.entity_id IN ("' . implode('", "', $flag_entities['contact_uid']) . '"))';
                }
            }
            if (!empty($flag_entities['cashier_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "7" AND fe.entity_id IN (' . implode(',', $flag_entities['cashier_id']) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "7" AND fe.entity_id IN (' . implode(',', $flag_entities['cashier_id']) . '))';
                }
            }
            if (!empty($flag_entities['timeslot_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "8" AND fe.entity_id IN (' . implode(',', array_unique($flag_entities['timeslot_id'])) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "8" AND fe.entity_id IN (' . implode(',', array_unique($flag_entities['timeslot_id'])) . '))';
                }
            }
            if (!empty($flag_entities['shift_id'])) {
                if (empty($count)) {
                    $count = 1;
                    $where .= '(fe.entity_type = "9" AND fe.entity_id IN (' . implode(',', array_unique($flag_entities['shift_id'])) . '))';
                } else {
                    $where .= ' || (fe.entity_type = "9" AND fe.entity_id IN (' . implode(',', array_unique($flag_entities['shift_id'])) . '))';
                }
            }
            $where .=')';
        }
        
        $db->where($where);
        $db->order_by('fe.entity_type', 'asc');
        $query = $db->get();
 
        $flag_entity_details = $query->result();
        $this->createLog('flag_logs.php', 'query', array("data" => $db->last_query()));
        $this->createLog('flag_logs.php', 'get_flags_details', array("data" => json_encode($flag_entity_details)));
        
        /* sort all detail related to flag. */
        if (count($flag_entity_details) > 0) {
            foreach ($flag_entity_details as $flag_entity_detail) {
                $flag_entity_detail_sort[$flag_entity_detail->entity_type][] = $flag_entity_detail;
                if (!in_array($flag_entity_detail->entity_type, $entity_type)) {
                    $entity_type[] = $flag_entity_detail->entity_type;
                }
            }
            foreach ($entity_type as $type) {
                foreach ($flag_entity_detail_sort[$type] as $data) {
                    $sorted_details[$type][$data->f_en_id] = $data;
                }
                ksort($sorted_details[$type]);
                foreach ($sorted_details[$type] as $data) {
                    $data->entity_name = $this->get_entity_name($data->entity_type);
                    $flag_entity_sorted_details[] = $data;
                }
            }
            $this->createLog('flag_logs.php', 'flag_entity_sorted_details', array("data" => json_encode($flag_entity_sorted_details)));
            return $flag_entity_sorted_details;
        }        
        
    }
    /**
    * @name get_flags_details .
    * @called from get_flags_details. 
    * @param $entity type.
    * @purpose : This function to get entities name.
    * @created by : supriya saxena<supriya10.aipl@gmail.com>
    * @created at: 20 oct , 2020
    */
    function get_entity_name($entity_name) {
      $response = '';
      switch ($entity_name){
                case 1:
                        $response =  "supplier_id";
                        break;
                case 2:
                        $response =  "reseller_id";
                        break;
                case 3:
                        $response =  "distributor_id";
                        break;
                case 4:
                        $response =  "partner_id";
                        break; 
                case 5:
                        $response =  "ticket_id";
                        break; 
                case 6:
                        $response =  "contact_uid";
                        break; 
                case 7:
                        $response =  "timeslot_id";
                        break;     
                case 8:
                        $response =  "shift_id";
                        break;
            }
            return $response;
    }

    /* #endregion to get entities flag details.*/
   /* #region to save order flag details  */
   /**
    * @name set_order_flags .
    * @called from insert_batch_order_table in  Pos controller. 
    * @param array $flag_details.
    * @purpose : This function use to save flag detail in order_flags table.
    * @created by : supriya saxena<supriya10.aipl@gmail.com>
    * @created at: 20 oct, 2020
    */
    function set_order_flags($flag_details = []) {

        $this->createLog('flag_logs.php', 'set_flag_details', array("data" => json_encode($flag_details)));
        if (!empty($flag_details)) {
            $order_id_arr = array();
            foreach($flag_details as $key => $flag_detail) {
               if(!in_array($key ,$order_id_arr )) {
                   array_push($order_id_arr,$key );
               } 
            }
            $query = "Select count(order_id) as order_count, order_id from order_flags where order_id In (".implode(",", $order_id_arr).") Group By order_id";
            $data_order = $this->secondarydb->db->query($query)->result_object();
             $this->createLog('flag_logs.php', 'order_fetch_query', array("query" => $this->secondarydb->db->last_query()));
            foreach($data_order as $value) {
                $new_data_order[$value->order_id] = $value->order_count;
            }
            $final_order_flags = $order_flags = [];
           
            foreach ($flag_details as $vgn => $flag_detail) { //prepare array for order_flags table
                foreach($flag_detail as $k => $val) {
                    if (!empty($new_data_order[$vgn])) {
                   
                    $add_count = $new_data_order[$vgn] + ($k + 1);
                    $order_flags['id'] = $vgn . $add_count;
                    } else {
                        $order_flags['id'] = $vgn . $k + 1;
                    }
                    $order_flags['order_id'] = $vgn;
                    $order_flags['flag_id'] = $val->id;
                    $order_flags['flag_uid'] = $val->flag_uid;
                    $order_flags['flag_type'] = $val->type;
                    $order_flags['flag_entity_type'] = $val->entity_type;
                    $order_flags['flag_entity_id'] = $val->entity_id;
                    $order_flags['flag_name'] = $val->name;
                    $order_flags['flag_value'] = $val->value;
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
            $this->createLog('flag_logs.php', 'set flag final query', array("data" => $this->secondarydb->db->last_query()));
        }
    }

    /* #endregion to save order flag details.*/
    
    /**
    * @name update_order_in_db .
    * @called from update_in_db in  Pos controller. 
    * @param array visitor_group_no.
    * @purpose : This function use to soft delte whole order if pass no is updated in exception.
    * @created by : supriya saxena<supriya10.aipl@gmail.com>
    * @created at: 8 Feb, 2021
    */
    function update_order_in_db($exception_order_ids = array()) {
        $db2_pt_query = "UPDATE prepaid_tickets SET deleted = '1', updated_at = '".gmdate("Y-m-d H:i:s")."' WHERE visitor_group_no In (".implode(',', $exception_order_ids ).")";
        $db2_vt_query = "UPDATE visitor_tickets SET deleted = '2', updated_at = '".gmdate("Y-m-d H:i:s")."'  WHERE vt_group_no In (".implode(',', $exception_order_ids ).")";
        $this->secondarydb->db->query($db2_pt_query);
        $this->secondarydb->db->query($db2_vt_query);
        // Send data in RDS queue realtime
        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
             $this->fourthdb->db->query($db2_pt_query);
             $this->fourthdb->db->query($db2_vt_query);
        }
    }
     /**
    * @name update_order_in_db .
    * @called from update_in_db in  Pos controller. 
    * @param array visitor_group_no.
    * @purpose : This function use to soft delte whole order if pass no is updated in mpos exception.
    * @created by : supriya saxena<supriya10.aipl@gmail.com>
    * @created at: 8 Feb, 2021
    */
    function update_mposexception_in_db($mpos_exception_orders) {
        $pt_orders_to_delete = $mpos_exception_orders['pt_delete_orders'];
        $vt_orders_to_delete = $mpos_exception_orders['vt_delete_orders'];
        
        $where_pt_orders = implode(" OR ", $pt_orders_to_delete);
        $where_vt_orders = implode(" OR ", $vt_orders_to_delete);
        $db2_pt_query = "UPDATE prepaid_tickets SET deleted = '1' , updated_at = '".gmdate("Y-m-d H:i:s")."'  WHERE  (".$where_pt_orders.")";
        $db2_vt_query = "UPDATE visitor_tickets SET deleted = '2' , updated_at = '".gmdate("Y-m-d H:i:s")."' WHERE (". $where_vt_orders.")";
        $this->secondarydb->db->query($db2_pt_query);
        $this->secondarydb->db->query($db2_vt_query);
        // Send data in RDS queue realtime
        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
             $this->fourthdb->db->query($db2_pt_query);
             $this->fourthdb->db->query($db2_vt_query);
        }
    }
    /**
     * @Name     : get_price_level_merchant()
     * @Purpose  : To get merchant details from TLC/CLC level if exists
     * @Created  : supriya saxena<supriya10.aipl@gmail.com> on 19 april, 2021
    */
    function get_price_level_merchant($ticketId = '', $hotelId=0, $tps_id = 0) {
		if(!empty($ticketId)) {
            $data = $this->primarydb->db->select(array("merchant_admin_id", "merchant_admin_name"))
                                            ->from("channel_level_commission")
                                            ->where(array("ticket_id" => $ticketId, "is_adjust_pricing" => 1, 'ticketpriceschedule_id'=> $tps_id))
                                            ->get()->row();
		}
		$ticket_level_data = array();
        if(!empty($ticketId) && !empty($hotelId)) {
            $ticket_level_data = $this->primarydb->db->select(array("merchant_admin_id", "merchant_admin_name"))
                                            ->from("ticket_level_commission")
                                            ->where(array("ticket_id" => $ticketId, "hotel_id" => $hotelId, "is_adjust_pricing" => 1, 'ticketpriceschedule_id'=> $tps_id))
                                            ->get()->row();
		} 
		if(!empty($ticket_level_data) && !empty($ticket_level_data->merchant_admin_id)) {
			return $ticket_level_data;
		} else if (!empty($data) && !empty($data->merchant_admin_id)) {
			return $data;
		} else {
			return false;
		}
    }
}
