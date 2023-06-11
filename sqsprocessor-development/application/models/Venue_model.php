<?php
class Venue_model extends MY_Model {
    function __construct() {
	///Call the Model constructor
	parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_model');
        $this->load->model('pos_model');
        $this->load->model('order_process_update_model');
	$this->base_url  = $this->config->config['base_url'];
	$this->root_path = $this->config->config['root_path'];
	$this->imageDir  = $this->config->config['imageDir'];
        $this->types = array(
            'adult'     => 1,
            'baby'      => 2,
            'infant'    => 13,
            'child'     => 3,
            'elderly'   => 4,
            'handicapt' => 5,
            'student'   => 6,
            'military'  => 7,
            'youth'     => 8,
            'senior'    => 9,
            'group'     => 10,
            'family'    => 11,
            'resident'  => 12
        );
        $this->api_channel_types = array(4, 6, 7, 8, 9, 13); // API Chanell types
    }   
    
    /**
     * @name   : update_pre_assigned_records()
     * @purpose: When insert Record in Prepaid Tickets table and HTO Table for pre_assigned code vouchers and  SNS HIT FROM VENUE app SCAN API
     * @where  : It is called from pos.php
     * @returns: No parameter is returned
     */
    function update_pre_assigned_records($request_data =  array()){
        global $MPOS_LOGS;
        $museum_id  = $request_data['museum_id'];
        $ticket_id  = $request_data['ticket_id'];
        $tps_id     = $request_data['tps_id'];
        $shift_id     = $request_data['shift_id'];
        $pos_point_id     = $request_data['pos_point_id'];
        $pos_point_name     = $request_data['pos_point_name'];
        $dist_id     = isset($request_data['dist_id']) ? $request_data['dist_id'] : 0;
        $dist_cashier_id     = isset($request_data['dist_cashier_id']) ? $request_data['dist_cashier_id'] : 0;
        $pos_point_id_on_redeem     = $request_data['pos_point_id_on_redeem'];
        $pos_point_name_on_redeem     = $request_data['pos_point_name_on_redeem'];
        $url        = $request_data['pass_no'];
        $child_pass_no  = $request_data['child_pass_no'];
        $bleep_pass_no  = $request_data['bleep_pass_no'];
        $extra_discount_save  = ($request_data['extra_discount'] == NULL) ? '' : $request_data['extra_discount'];
        $order_currency_extra_discount_save  = $request_data['order_currency_extra_discount'];
        $discount_save  = $request_data['discount'];
        $merchant_admin_id  = $request_data['merchant_admin_id'];
        $batch_rule_id  = $request_data['batch_rule_id'];
        $batch_id  = isset($request_data['batch_id']) ? $request_data['batch_id'] : 0;
        $cashier_type =  $request_data['cashier_type'];
        $reseller_id =  $request_data['reseller_id'];
        $reseller_cashier_id =  $request_data['reseller_cashier_id'];

        $used = '0';
        $ticket = $prepaid_ticket = array();
        if(isset($request_data['used'])){
            $used=(string) $request_data['used'];
        }
        //params for reservation ticket
        if(isset($request_data['selected_date']) && isset($request_data['from_time']) && isset($request_data['to_time']) && isset($request_data['slot_type']) &&  isset($request_data['shared_capacity_id'])){
//             $selected_dates  = ($request_data['to_time'] != '00:00' && $request_data['slot_type'] != '') ? $request_data['selected_date'] : '0';
             $from_times      = ($request_data['to_time'] != '00:00' && $request_data['slot_type'] != '') ? $request_data['from_time'] : '0';
             $current_day = strtotime(date("Y-m-d"));
             $current_time = strtotime(date("H:i"));
             $to_time = strtotime($request_data['to_time']);
             $check_select_date = strtotime($request_data['selected_date']);
             $to_times      = ($request_data['to_time'] != '00:00' && $request_data['slot_type'] != '') ? $request_data['to_time'] : '0';
             $slot_types      = $request_data['slot_type'];
             $shared_capacity_id      = $request_data['shared_capacity_id'];
        }       
        if(!strstr($url, 'http') && strlen($url) == 6) {
            if(!strstr($url, '-')) {
                $url = 'http://qu.mu/'.$url;
            } else {
                $url = 'http://qb.vg/'.$url;
            }
        }
        if(strlen($child_pass_no) == PASSLENGTH && !empty($child_pass_no)) {
            $child_pass_no = 'http://qu.mu/'.$child_pass_no;
        } else if(strlen($url) == PASSLENGTH && !empty($url)) {
            $url = 'http://qu.mu/'.$url;
        } 

        //get details from pre_assigned
        $this->primarydb->db->select("*");
        $this->primarydb->db->from("pre_assigned_codes");
        
        if(!empty($child_pass_no)){
            $this->primarydb->db->where('child_pass_no', $child_pass_no);
        } else {
            $child_pass_no = $url;
            $this->primarydb->db->where("url", $url);
        }        
        $this->primarydb->db->where(array("tps_id" => $tps_id, "ticket_id" => $ticket_id));
        $result = $this->primarydb->db->get();
        $logs['preassigned_db_query_'.date('H:i:s')]=$this->primarydb->db->last_query();
        if($result->num_rows() > 0) {
            $pass_data = $result->result();
            // check if pass is not active
            $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
            $timezone = $servicedata->timeZone;
            $previous_quantity = 0;
            if (isset($pass_data[0]->visitor_group_no) && $pass_data[0]->visitor_group_no != '' && $pass_data[0]->visitor_group_no != 'NULL') {
                $visitor_group_no = $pass_data[0]->visitor_group_no;
                $previous_data = $this->primarydb->db->query('select quantity,  total_price, total_net_price from hotel_ticket_overview where visitor_group_no = "' . $visitor_group_no . '"')->row();
                if (!empty($previous_data)) {
                    $previous_quantity = $previous_data->quantity;
                }
            } else {
                $visitor_group_no = $this->hotel_model->getLastVisitorGroupNo();
            }
            $query = "select cod_id, company, distributor_type, channel_id, channel_name, saledesk_id, saledesk_name, reseller_id, reseller_name from qr_codes where cod_id IN('".$pass_data[0]->hotel_id."', '".$museum_id."')";
            $channel_details = $this->db->query($query)->result_array();
            foreach ($channel_details as $detail) {
                $channel_detail[$detail['cod_id']] = $detail;
            }
            $financial_details = $this->primarydb->db->select("financial_id, financial_name")->from("channels")->where("channel_id", $channel_detail[$pass_data[0]->hotel_id]['channel_id'])->get()->row();
            $insertData = array();

            //Prepare array for HTO
            $insertData['id'] = (int) (microtime(true) * 1000000000);
            $insertData['createdOn'] = strtotime(gmdate('Y-m-d H:i:s'));
            $insertData['updatedOn'] = strtotime(gmdate('Y-m-d H:i:s'));
            $insertData['visitor_group_no_old'] = $visitor_group_no;
            $insertData['hotel_id'] = $pass_data[0]->hotel_id;
            $insertData['channel_id'] = $channel_detail[$pass_data[0]->hotel_id]['channel_id'];
            $insertData['hotel_name'] = $channel_detail[$pass_data[0]->hotel_id]['company'];
            if ($pass_data[0]->partner && $pass_data[0]->partner != 'NULL') {
                $insertData['partner_name'] = $pass_data[0]->partner;
            }
            $insertData['activation_type'] = '0';
            $insertData['activation_method'] = '2';
            if (isset($pass_data[0]->parent_pass_no) && $pass_data[0]->parent_pass_no != '' && $pass_data[0]->parent_pass_no != 'NULL') {
                $insertData['parentPassNo'] = $pass_data[0]->parent_pass_no;
            } else {
                $insertData['parentPassNo'] = $child_pass_no;
            }
            $insertData['passNo'] = $child_pass_no;
            $insertData['roomNo'] = '';
            $insertData['createdOnByGuest'] = strtotime(gmdate('m/d/Y H:i:s'));
            $insertData['gender'] = 'Male';
            $insertData['user_image'] = cdn('/qrcodes/images/no_upload1.png');
            $insertData['amount'] = 0;
            $insertData['nights'] = 0;
            $insertData['updatedBy'] = 0;
            $insertData['uid'] = 0;
            $insertData['host_name'] = '';
            $insertData['distributor_type'] = $channel_detail[$pass_data[0]->hotel_id]['distributor_type'];
            if (isset($ticket['timeslot'])) {
                $insertData['expectedCheckoutTime'] = strtotime(date('Y-m-d 23:59:59', strtotime($ticket['selected_date']))) - ($timezone * 3600);
            } else {
                $insertData['expectedCheckoutTime'] = strtotime(date('Y-m-d 23:59:59', strtotime(' +5 days'))) - ($timezone * 3600);
            }

            $insertData['guest_names'] = '';
            $insertData['receiptEmail'] = '';
            $insertData['visitor_group_no'] = $visitor_group_no;
            $insertData['isBillToHotel'] = '1';
            $insertData['creditcard_group_no'] = 0;
            $insertData['shopperReference'] = '';
            $insertData['merchantReference'] = '';
            $insertData['merchantAccountCode'] = '';
            $insertData['guest_emails'] = '';
            $insertData['authResult'] = '';
            $insertData['card_name'] = '';
            $insertData['card_number'] = '';
            $insertData['pspReference'] = '';
            $insertData['timezone'] = $timezone;
            $insertData['hotel_checkout_status'] = '0';
            $insertData['paymentStatus'] = '1';
            $insertData['paymentMethod'] = '';
            $insertData['additional_information'] = '';
            $insertData['user_age'] = 0;
            $insertData['is_pass_for_combi_ticket'] = 0;
            $insertData['is_prioticket'] = '0';
            $insertData['is_order_from_mobile_app'] = 1;
            $insertData['is_order_updated'] = 0;
            if ($pass_data[0]->no_of_tickets != NULL && $pass_data[0]->no_of_tickets > 0) {
                $insertData['quantity'] = $pass_data[0]->no_of_tickets + $previous_quantity;
            } else {
                $insertData['quantity'] = $previous_quantity + 1;
            }

            if (empty($pass_data[0]->voucher_creation_date)) {
                $insertData['voucher_creation_date'] = gmdate('Y-m-d H:i:s');
            } else {
                $insertData['voucher_creation_date'] = $pass_data[0]->voucher_creation_date;
            }
            $insertData['product_type'] = 0;
            $insertData['ticket_ids'] = $pass_data[0]->ticket_id;

            $insertData['isprepaid'] = '0';
            $insertData['channel_type'] = '5';
            $insertData['tp_payment_method'] = '1';
            $this->db->insert('hotel_ticket_overview', $insertData);
            $logs['insert_in_HTO_' . date('H:i:s')] = $this->db->last_query();
            $hto_id = $this->db->insert_id();

            $this->primarydb->db->select('mec.additional_information,mec.countdown_interval, mec.postingEventTitle, mec.location,mec.is_scan_countdown, mec.eventImage as image, mec.highlights, mec.is_reservation, mec.museum_name, tps.id as tps_id, tps.priceText, tps.ticket_net_price as tps_ticket_net_price, tps.agefrom, tps.ageto, tps.newPrice, tps.discountType as tps_discountType, tps.discount as tps_discount, tps.ticket_type_label, tps.ticket_tax_value as tps_ticket_tax_value, tps.saveamount as tps_saveamount, tps.start_date, tps.end_date, mec.is_combi');
            $this->primarydb->db->from('modeventcontent mec');
            $this->primarydb->db->join('ticketpriceschedule tps', 'mec.mec_id = tps.ticket_id', 'left');
            $this->primarydb->db->where('mec.mec_id', $pass_data[0]->ticket_id);
            if ($pass_data[0]->tps_id > 0) {
                $this->primarydb->db->where('tps.id', $pass_data[0]->tps_id);
            }
            $ticket_data = $this->primarydb->db->get();
            if ($ticket_data->num_rows() > 0) {
                $ticket_detail = $ticket_data->row();
                $additional_information = array();
                if (isset($ticket_detail->start_date) && $ticket_detail->start_date != '') {
                    $additional_information['start_date'] = date('Y-m-d', $ticket_detail->start_date);
                }
                if (isset($ticket_detail->end_date) && $ticket_detail->end_date != '') {
                    $additional_information['end_date'] = date('Y-m-d', $ticket_detail->end_date);
                }
                $additional_information = serialize($additional_information);
                $original_price   = $pass_data[0]->oroginal_price;
                $discounted_price = $pass_data[0]->price;
                $net_price = ($pass_data[0]->net_price != NULL && $pass_data[0]->net_price > 0) ? $pass_data[0]->net_price : 0;
                $ticket_tax_value = $pass_data[0]->tax;
                if (!empty($pass_data[0]->extra_discount)) {
                    $extra_discount = unserialize($pass_data[0]->extra_discount);
                    $ticket_discount_type = $extra_discount['discount_type'];
                } else {
                    $ticket_discount_type = '0';
                }
                $age_group = $pass_data[0]->age_group;
                $tax_name = $this->common_model->getSingleFieldValueFromTable('tax_name', 'store_taxes', array('tax_value' => $ticket_tax_value, 'tax_type' => '0'));
                // Check if ticket is of reservation type or not
                if ($ticket_detail->is_reservation == '1') {
                    $timeslot_details = $this->fetch_current_timeslot($pass_data[0]->ticket_id); // fetch current timelsot of ticket
                    $timeslot_details = explode('_', $timeslot_details);
                    $selected_date = gmdate('Y-m-d');
                    $from_time = trim($timeslot_details[0]);
                    $to_time = trim($timeslot_details[1]);
                } else {
                    // save selected date for specific tickets for reporting system
                    if ($pass_data[0]->ticket_id == 584 || $pass_data[0]->ticket_id == 585) {
                        $selected_date = gmdate('Y-m-d');
                    } else {
                        $selected_date = 0;
                    }
                    $from_time = $to_time = '';
                }
                if ($pass_data[0]->no_of_tickets != NULL && $pass_data[0]->no_of_tickets > 0) {
                    $counter = $pass_data[0]->no_of_tickets;
                } else {
                    $counter = 1;
                }
                $batch_prepaid_ticket_data = array();

                if ($ticket_detail->is_scan_countdown == 1) {
                    $countdown_values = explode('-', $ticket_detail->countdown_interval);
                    $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                    $valid_till = strtotime(gmdate('m/d/Y H:i:s', strtotime('+ ' . $countdown_time . ' seconds')));
                } else {
                    $valid_till = '';
                }
                $last_inserted_count = str_shuffle(substr(strtotime(gmdate("Y-m-d H:i:s")), 6));
                $update_scanned_at = 0;

                /* Send extra values which need to insert in prepaid_tickets DB2 */
                $extra_params['merchant_admin_id'][$pass_data[0]->ticket_id] = $merchant_admin_id;
                /* Send extra values which need to insert in prepaid_tickets DB2 */

                $countPtRecords = $counter;

                /* get ticket_booking_id */
                $ticketBookingId = array();
                $this->primarydb->db->select('passNo, ticket_id, selected_date, from_time, to_time, ticket_booking_id');
                $this->primarydb->db->from('prepaid_tickets');
                $this->primarydb->db->where(array("visitor_group_no" => $visitor_group_no));               
                $ticket_booking_id_data = $this->primarydb->db->get();
                if($ticket_booking_id_data->num_rows() > 0) {
                    $ticket_booking_detail = $ticket_booking_id_data->result_array();  
                    foreach($ticket_booking_detail as $val) {
                        $ticketBookingId[$visitor_group_no."_".$val['passNo']."_".$val['ticket_id']."_".$val['selected_date']."_".$val['from_time']."_".$val['to_time']] = $val['ticket_booking_id'];
                    }
                }
                
                /* check if ticket_booking_id exists else generate new one */
                $chkDate = !empty($request_data['selected_date']) && !empty($from_times) && !empty($to_times)? $request_data['selected_date']: gmdate("Y-m-d");
                $checkKey = implode("_", array($visitor_group_no, 
                                                (strstr($child_pass_no, 'http')? substr($child_pass_no, -PASSLENGTH): $child_pass_no), 
                                                $pass_data[0]->ticket_id, 
                                                $chkDate, 
                                                (!empty($from_times) ? $from_times : '0'), 
                                                (!empty($to_times) ? $to_times : '0')));
                if(isset($ticketBookingId[$checkKey])) {
                    $ticket_booking_id = $ticketBookingId[$checkKey];
                }
                else {
                    /* get_ticket booking_id VGN plus 5 length random alphanumeric value */
                    $ticket_booking_id = $this->get_ticket_booking_id($visitor_group_no);
                    $ticketBookingId[$checkKey] = $ticket_booking_id;
                }

                for ($i = 0; $i < $counter; $i++) {
                    ++$last_inserted_count;
                    $prepaid_ticket_data = array();

                    $prepaid_ticket_data['prepaid_ticket_id'] = (int) (microtime(true) * 1000000000);
                    $prepaid_ticket_data['visitor_tickets_id'] = $prepaid_ticket_data['prepaid_ticket_id'] . '01';
                    $prepaid_ticket_data['created_date_time'] = gmdate('Y-m-d H:i:s', $pass_data[0]->scanned_at);
                    $prepaid_ticket_data['hotel_ticket_overview_id'] = isset($hto_id) ? $hto_id : 0;
                    $prepaid_ticket_data['hotel_id'] = $pass_data[0]->hotel_id;
                    $prepaid_ticket_data['hotel_name'] = $channel_detail[$pass_data[0]->hotel_id]['company'];
                    $prepaid_ticket_data['age_group'] = $age_group;
                    $prepaid_ticket_data['museum_id'] = $pass_data[0]->museum_id;
                    $prepaid_ticket_data['reseller_id'] = $channel_detail[$pass_data[0]->hotel_id]['reseller_id'];
                    $prepaid_ticket_data['reseller_name'] = $channel_detail[$pass_data[0]->hotel_id]['reseller_name'];
                    $prepaid_ticket_data['distributor_partner_id'] = $pass_data[0]->partner_id;
                    $prepaid_ticket_data['distributor_partner_name'] = $pass_data[0]->partner;
                    $prepaid_ticket_data['museum_name'] = $channel_detail[$pass_data[0]->museum_id]['company'];
                    $prepaid_ticket_data['visitor_group_no'] = $visitor_group_no;
                    $prepaid_ticket_data['ticket_booking_id'] = $ticket_booking_id;
                    $prepaid_ticket_data['title'] = ($pass_data[0]->ticket_title != '' && $pass_data[0]->ticket_title != null) ? $pass_data[0]->ticket_title : $ticket_detail->postingEventTitle;
                    $prepaid_ticket_data['additional_information'] = $additional_information;
                    $prepaid_ticket_data['price'] = $discounted_price;
                    $prepaid_ticket_data['discount'] = $discount_save;
                    $prepaid_ticket_data['extra_discount'] = $extra_discount_save;
                    $prepaid_ticket_data['tax'] = $ticket_tax_value;
                    $prepaid_ticket_data['tax_name'] = $tax_name;
                    $prepaid_ticket_data['net_price'] = $net_price;
                    $prepaid_ticket_data['ticket_id'] = $pass_data[0]->ticket_id;
                    $prepaid_ticket_data['related_product_id'] = $pass_data[0]->ticket_id;
                    $prepaid_ticket_data['tps_id'] = $pass_data[0]->tps_id;
                    $prepaid_ticket_data['pax'] = isset($pass_data[0]->pax) ? $pass_data[0]->pax : 0;
                    $prepaid_ticket_data['capacity'] = isset($pass_data[0]->capacity) ? $pass_data[0]->capacity : 1;

                    if (!empty($pass_data[0]->bleep_pass_no)) {
                        $prepaid_ticket_data['bleep_pass_no'] = $pass_data[0]->bleep_pass_no;
                    } else if (!empty($bleep_pass_no)) {
                        $prepaid_ticket_data['bleep_pass_no'] = $bleep_pass_no;
                    }
                    if (strstr($child_pass_no, 'http')) {
                        $prepaid_ticket_data['passNo'] = substr($child_pass_no, -PASSLENGTH);
                    } else {
                        $prepaid_ticket_data['passNo'] = $child_pass_no;
                    }

                    $prepaid_ticket_data['ticket_type'] = $ticket_detail->ticket_type_label;
                    $prepaid_ticket_data['quantity'] = 1;
                    $prepaid_ticket_data['selected_date'] = !empty($request_data['selected_date']) && !empty($from_times) && !empty($to_times) ? $request_data['selected_date'] : gmdate("Y-m-d");
                    $prepaid_ticket_data['from_time'] = !empty($from_times) ? $from_times : '0';
                    $prepaid_ticket_data['to_time'] = !empty($to_times) ? $to_times : '0';
                    $prepaid_ticket_data['timeslot'] = !empty($slot_types) ? $slot_types : '';
                    $prepaid_ticket_data['timezone'] = $timezone;
                    $prepaid_ticket_data['shared_capacity_id'] = !empty($shared_capacity_id) ? $shared_capacity_id : '';
                    $prepaid_ticket_data['shift_id'] = !empty($shift_id) ? $shift_id : 0;
                    $prepaid_ticket_data['pos_point_id'] = !empty($pos_point_id) ? $pos_point_id : 0;
                    $prepaid_ticket_data['pos_point_name'] = !empty($pos_point_name) ? $pos_point_name : '';
                    if ($pass_data[0]->is_combi_ticket != NULL && $pass_data[0]->is_combi_ticket > 0) {
                        $prepaid_ticket_data['is_combi_ticket'] = '1';
                    } else {
                        $prepaid_ticket_data['is_combi_ticket'] = '0';
                    }
                    $prepaid_ticket_data['is_combi_discount'] = '0';
                    $prepaid_ticket_data['combi_discount_gross_amount'] = '0';
                    $prepaid_ticket_data['pass_type'] = '0';
                    $prepaid_ticket_data['is_prioticket'] = '0';
                    $prepaid_ticket_data['activation_method'] = '2';
                    $prepaid_ticket_data['channel_type'] = '5';
                    $prepaid_ticket_data['clustering_id'] = $pass_data[0]->clustering_id;
                    if (($ticket_detail->is_reservation == '1' && $ticket_detail->is_scan_countdown == '1' && $check_select_date == $current_day) || ($ticket_detail->is_scan_countdown == '1' && $ticket_detail->is_reservation == '0')) {
                        $prepaid_ticket_data['scanned_at'] = $pass_data[0]->scanned_at;
                        $prepaid_ticket_data['redeem_date_time'] = gmdate("Y-m-d H:i:s", $pass_data[0]->scanned_at);
                        $prepaid_ticket_data['payment_conditions'] = $valid_till;
                        $update_scanned_at = 1;
                    } else if ($ticket_detail->is_reservation == '1' && $ticket_detail->is_scan_countdown == '1' && $check_select_date > $current_day) {
                        $prepaid_ticket_data['scanned_at'] = '';
                        $prepaid_ticket_data['payment_conditions'] = '';
                    } else if ($used == '0' && ($check_select_date != $current_day || (empty($pass_data[0]->bleep_pass_no) && $current_time > $to_time))) {
                        $prepaid_ticket_data['scanned_at'] = '';
                    } else {
                        $prepaid_ticket_data['scanned_at'] = $pass_data[0]->scanned_at;
                        $prepaid_ticket_data['redeem_date_time'] = gmdate("Y-m-d H:i:s", $pass_data[0]->scanned_at);
                        $update_scanned_at = 1;
                    }
                    if ($update_scanned_at == 1 || !empty($pass_data[0]->bleep_pass_no)) {
                        $prepaid_ticket_data['museum_cashier_id'] = $pass_data[0]->museum_cashier_id;
                        $prepaid_ticket_data['museum_cashier_name'] = $pass_data[0]->museum_cashier_name;
                        if ($used == 1 && $ticket_detail->is_scan_countdown == '1') {
                            $prepaid_ticket_data['redeem_users'] = $pass_data[0]->museum_cashier_id . '_' . gmdate('Y-m-d');
                        }
                    }
                    $prepaid_ticket_data['used'] = (string) $update_scanned_at;
                    if (empty($pass_data[0]->bleep_pass_no)) {
                        if($prepaid_ticket_data['scanned_at'] != '' && $ticket_detail->is_scan_countdown == '1') {
                            $prepaid_ticket_data['action_performed'] = 'SCAN_PRE_CSS, SCAN_TB';
                        } else {
                            $prepaid_ticket_data['action_performed'] = 'SCAN_PRE_CSS';
                        }                      
                    } else {
                        if($prepaid_ticket_data['scanned_at'] != '' && $ticket_detail->is_scan_countdown == '1') {
                            $prepaid_ticket_data['action_performed'] = 'CSS_ACT, SCAN_PRE_CSS, SCAN_TB';
                        } else {
                            $prepaid_ticket_data['action_performed'] = 'CSS_ACT, SCAN_PRE_CSS';
                        }
                    }
                    $supplier_original_price = $ticket_detail->priceText;
                    $supplier_discount = $ticket_detail->tps_saveamount;
                    $supplier_tax       = $ticket_detail->tps_ticket_tax_value;
                    $supplier_net_price = $ticket_detail->tps_ticket_net_price;
                    $extra_params['created_at']               = $pass_data[0]->scanned_at;
                    $extra_params['channel_id']               = $channel_detail[$pass_data[0]->hotel_id]['channel_id'];
                    $extra_params['channel_name']             = $channel_detail[$pass_data[0]->hotel_id]['channel_name'];
                    $extra_params['saledesk_id']              = $channel_detail[$pass_data[0]->hotel_id]['saledesk_id'];
                    $extra_params['saledesk_name']            = $channel_detail[$pass_data[0]->hotel_id]['saledesk_name'];
                    $extra_params['financial_id']             = $financial_details->financial_id;
                    $extra_params['financial_name']           = $financial_details->financial_name;
                    $extra_params['location']                 = $ticket_detail->location;
                    $extra_params['image']                    = $ticket_detail->image;
                    $extra_params['highlights']               = $ticket_detail->highlights;
                    $extra_params['oroginal_price']           = $original_price;
                    $extra_params['order_currency_extra_discount']   = $order_currency_extra_discount_save;
                    $extra_params['is_discount_in_percent']   = $ticket_discount_type;
                    $extra_params['related_product_title']    = $pass_data[0]->ticket_title;
                    $extra_params['pos_point_id_on_redeem'] = !empty($pos_point_id_on_redeem) ? $pos_point_id_on_redeem : 0;
                    $extra_params['pos_point_name_on_redeem'] = !empty($pos_point_name_on_redeem) ? $pos_point_name_on_redeem: '';
                    $extra_params['distributor_id_on_redeem'] = $dist_id;
                    $extra_params['distributor_cashier_id_on_redeem'] = $dist_cashier_id;
                    $extra_params['is_discount_code']          = 0;
                    $extra_params['is_pre_selected_ticket']   =  1;
                    if(empty($pass_data[0]->voucher_creation_date)){
                        $extra_params['voucher_creation_date'] = gmdate('Y-m-d H:i:s');
                    } else {
                        $extra_params['voucher_creation_date'] = $pass_data[0]->voucher_creation_date;
                    }
                    $extra_params['voucher_updated_by']        = $pass_data[0]->museum_cashier_id;
                    $extra_params['voucher_updated_by_name']   = $pass_data[0]->museum_cashier_name;
                    $extra_params['redeem_method']             = 'Voucher';
                    $extra_params['discount_code_value'] = $extra_params['image'] = $extra_params['net_service_cost'] = $extra_params['rezgo_id'] = $extra_params['rezgo_ticket_price'] = $extra_params['rezgo_ticket_id'] = $extra_params['is_iticket_product']='';
                    $extra_params['last_imported_date'] = $extra_params['updated_at'] = $extra_params['order_confirm_date'] = gmdate('Y-m-d H:i:s');
                    $extra_params['supplier_tax']             = $supplier_tax;
                    $extra_params['supplier_net_price']       = $supplier_net_price;
                    $extra_params['batch_id']                 = $batch_id;
                    $extra_params['supplier_original_price']  = $supplier_original_price;
                    $extra_params['supplier_discount']        = $supplier_discount;
                    
                    $prepaid_ticket_data['booking_status']     = '1';
                    $prepaid_ticket_data['tp_payment_method']  = '1';
                    if ($ticket_detail->newPrice > 0) {
                        $supplier_price = $ticket_detail->newPrice;
                    } else {
                        $supplier_price = $ticket_detail->priceText;
                    }
                    $prepaid_ticket_data['supplier_price']           = $supplier_price;
                    $created_date = date("Y/m/d");
                    $batch_prepaid_ticket_data[] = $prepaid_ticket_data;
                    $this->db->insert('prepaid_tickets', $prepaid_ticket_data);
                    $logs['insert_pt_query_' . $i] = $this->db->last_query();
                    if (SYNC_WITH_FIREBASE == 1 && !empty($prepaid_ticket_data['bleep_pass_no'])) {
                        $museum_id = $prepaid_ticket_data['museum_id'];
                        $museum_cashier_id = $prepaid_ticket_data['museum_cashier_id'];
                        $visitor_group_no = $prepaid_ticket_data['visitor_group_no'];
                        $selected_date = ($prepaid_ticket_data['selected_date'] != 0 && $prepaid_ticket_data['selected_date'] != '') ? $prepaid_ticket_data['selected_date'] : '';
                        $from_time = ($prepaid_ticket_data['from_time'] != '' && $prepaid_ticket_data['from_time'] != '0') ? $prepaid_ticket_data['from_time'] : "0";
                        $to_time = ($prepaid_ticket_data['to_time'] != 0 && $prepaid_ticket_data['to_time'] != '') ? $prepaid_ticket_data['to_time'] : '0';
                        $sync_key = base64_encode($prepaid_ticket_data['visitor_group_no'] . '_' . $prepaid_ticket_data['ticket_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $prepaid_ticket_data['created_date_time'] . '_' . $prepaid_ticket_data['passNo']);
                        $ticket_types[$sync_key][$prepaid_ticket_data['tps_id']] = array(
                            'tps_id' => (int) $prepaid_ticket_data['tps_id'],
                            'age_group' => $prepaid_ticket_data['age_group'],
                            'price' => (float) $prepaid_ticket_data['price'],
                            'net_price' => (float) $prepaid_ticket_data['net_price'],
                            'type' => ucfirst(strtolower($prepaid_ticket_data['ticket_type'])),
                            'quantity' => (int) $ticket_types[$sync_key][$prepaid_ticket_data['tps_id']]['quantity'] + 1,
                            'combi_discount_gross_amount' => (float) $prepaid_ticket_data['combi_discount_gross_amount'],
                            'refund_quantity' => (int) 0,
                            'refunded_by' => array(),
                            'per_age_group_extra_options' => array(),
                        );
                        $bookings[$sync_key]['amount'] = (float) ($bookings[$sync_key]['amount'] + ((float) $prepaid_ticket_data['price']) + (float) $prepaid_ticket_data['combi_discount_gross_amount']);
                        $bookings[$sync_key]['quantity'] = (int) ($bookings[$sync_key]['quantity'] + 1);
                        $bookings[$sync_key]['passes'] = ($bookings[$sync_key]['passes'] != '' && $prepaid_ticket_data['is_combi_ticket'] == 0 ) ? $bookings[$sync_key]['passes'] . ', ' . $prepaid_ticket_data['passNo'] : $prepaid_ticket_data['passNo'];
                        $bookings[$sync_key]['bleep_passes'] = ($bookings[$sync_key]['bleep_passes'] != '') ? $bookings[$sync_key]['bleep_passes'] . ', ' . $prepaid_ticket_data['bleep_pass_no'] : $prepaid_ticket_data['bleep_pass_no'];
                        $bookings[$sync_key]['booking_date_time'] = $prepaid_ticket_data['created_date_time'];
                        $bookings[$sync_key]['booking_name'] = '';
                        $bookings[$sync_key]['cashier_name'] = $prepaid_ticket_data['cashier_name'];
                        $bookings[$sync_key]['shift_id'] = ($prepaid_ticket_data['shift_id'] > 0) ? (int) $prepaid_ticket_data['shift_id'] : (int) 0;
                        $bookings[$sync_key]['pos_point_id'] = ($prepaid_ticket_data['pos_point_id'] > 0) ? (int) $prepaid_ticket_data['pos_point_id'] : (int) 0;
                        $bookings[$sync_key]['pos_point_name'] = ($prepaid_ticket_data['pos_point_name'] != '') ? $prepaid_ticket_data['pos_point_name'] : '';
                        if ($prepaid_ticket['channel_type'] == '9') {
                            $bookings[$sync_key]['group_id'] = (int) 1;
                            $bookings[$sync_key]['group_name'] = 'City Expert';
                        } else {
                            $bookings[$sync_key]['group_id'] = (int) 3;
                            $bookings[$sync_key]['group_name'] = 'OTA';
                        }
                        $bookings[$sync_key]['channel_type'] = (int) $prepaid_ticket_data['channel_type'];
                        $bookings[$sync_key]['service_cost_amount'] = (float) 0;
                        $bookings[$sync_key]['activated_by'] = (int) $prepaid_ticket_data['museum_cashier_id'];
                        $bookings[$sync_key]['activated_at'] = gmdate('Y-m-d h:i:s');
                        $bookings[$sync_key]['cancelled_tickets'] = (int) 0;
                        $bookings[$sync_key]['is_voucher'] = (int) 1;
                        $bookings[$sync_key]['ticket_types'] = (!empty($ticket_types[$sync_key])) ? $ticket_types[$sync_key] : array();
                        $bookings[$sync_key]['per_ticket_extra_options'] = array();
                        $bookings[$sync_key]['from_time'] = ($prepaid_ticket_data['from_time'] != '' && $prepaid_ticket_data['from_time'] != '0') ? $prepaid_ticket_data['from_time'] : "";
                        $bookings[$sync_key]['is_reservation'] = (int) !empty($prepaid_ticket_data['selected_date']) && !empty($prepaid_ticket_data['from_time']) && !empty($prepaid_ticket_data['to_time']) ? 1 : 0;
                        $bookings[$sync_key]['merchant_reference'] = '';
                        $bookings[$sync_key]['museum'] = $prepaid_ticket_data['museum_name'];
                        $bookings[$sync_key]['order_id'] = $prepaid_ticket_data['visitor_group_no'];
                        $bookings[$sync_key]['payment_method'] = (int) $prepaid_ticket_data['activation_method'];
                        $bookings[$sync_key]['reservation_date'] = ($prepaid_ticket_data['selected_date'] != '' && $prepaid_ticket_data['selected_date'] != '0') ? $prepaid_ticket_data['selected_date'] : "";
                        $bookings[$sync_key]['ticket_id'] = (int) $prepaid_ticket_data['ticket_id'];
                        $bookings[$sync_key]['ticket_title'] = $prepaid_ticket_data['title'];
                        $bookings[$sync_key]['timezone'] = (int) $prepaid_ticket_data['timezone'];
                        $bookings[$sync_key]['to_time'] = ($prepaid_ticket_data['to_time'] != '' && $prepaid_ticket_data['to_time'] != '0') ? $prepaid_ticket_data['to_time'] : "";
                        $bookings[$sync_key]['status'] = (int) 2;
                        $bookings[$sync_key]['is_extended_ticket'] = (int) 0;
                    }
                    $prepaid_ticket_id = $this->db->insert_id();
                    $total_price = (float) $discounted_price;
                    $total_net_price = (float) $net_price;
                    $total_quantity = 1;
                    /** To insert Cluster sub tickets entry * */
                    if (!empty($pass_data[0]->clustering_id) || $ticket_detail->is_combi == "2") {
                        $prepaid_tickets_data = $prepaid_ticket_data;
                        $hotel_id_check_cond = 'hotel_id = "' . $prepaid_tickets_data['hotel_id'] . '" and ';
                        if($ticket_detail->is_combi == "2") {
                            $hotel_id_check_cond  = '';
                        }
                        $main_ticket_price_schedule_id  = $tps_id;
                        $cluster_tickets_details = $this->pos_model->find('cluster_tickets_detail', array('select' => '*', 'where' => $hotel_id_check_cond . ' main_ticket_id="' . $ticket_id . '" and main_ticket_price_schedule_id ="' . $tps_id . '" and is_deleted = "0"'));
                        $this->createLog('update_in_db_queries.php', 'extend cluster ticket :  ' . $this->primarydb->db->last_query(), array('data' => json_encode($cluster_tickets_details)));
                        foreach ($cluster_tickets_details as $cluster_details) {
                            $this->primarydb->db->select('mec.mec_id, mec.cod_id, mec.museum_name, mec.postingEventTitle as title, mec.additional_information, mec.location, mec.highlights, mec.eventImage, tps.id, tps.ticketType, tps.group_type_ticket, tps.group_price,  tps.group_linked_with, tps.ticket_tax_id, tps.ticket_tax_value, tps.pricetext, tps.newPrice, tps.ticket_net_price, tps.saveamount, tps.original_price, tps.discountType, tps.ticket_type_label, tps.agefrom, tps.ageto, tps.start_date, tps.end_date, mec.merchant_admin_id', false);
                            $this->primarydb->db->from("modeventcontent mec");
                            $this->primarydb->db->join("ticketpriceschedule tps", 'mec.mec_id=tps.ticket_id', 'left');
                            $this->primarydb->db->where(array("mec.mec_id" => $cluster_details['cluster_ticket_id'],
                                "mec.active" => '1', "mec.deleted" => '0', "tps.id" => $cluster_details['ticket_price_schedule_id']));
                            $ticket_query = $this->primarydb->db->get();

                            if ($ticket_query->num_rows() > 0) {
                                $ticket_detail = $ticket_query->row();
                            }
                            $additional_information = array();
                            if (isset($ticket_detail->start_date) && $ticket_detail->start_date != '') {
                                $additional_information['start_date'] = date('Y-m-d', $ticket_detail->start_date);
                            }
                            if (isset($ticket_detail->end_date) && $ticket_detail->end_date != '') {
                                $additional_information['end_date'] = date('Y-m-d', $ticket_detail->end_date);
                            }
                            $additional_information = serialize($additional_information);
                            $logs['cluster_ticket_detail_query'] = $this->primarydb->db->last_query();
                            $prepaid_tickets_data['prepaid_ticket_id'] = (int) (microtime(true) * 1000000000);
                            $prepaid_tickets_data['visitor_tickets_id'] = $prepaid_tickets_data['prepaid_ticket_id'] . '02';
                            $prepaid_tickets_data['ticket_id'] = $cluster_details['cluster_ticket_id'];
                            $prepaid_tickets_data['museum_id'] = $ticket_detail->cod_id;
                            $prepaid_tickets_data['museum_name'] = $ticket_detail->museum_name;
                            $prepaid_tickets_data['tps_id'] = $ticket_detail->id;
                            $prepaid_tickets_data['is_addon_ticket'] = '2';
                            $prepaid_tickets_data['title'] = $cluster_details['cluster_ticket_title'];
                            $prepaid_tickets_data['additional_information'] = $additional_information;
                            $prepaid_tickets_data['age_group'] = $ticket_detail->agefrom . '-' . $ticket_detail->ageto;
                            $prepaid_tickets_data['ticket_type'] = $ticket_detail->ticket_type_label;
                            $prepaid_tickets_data['price'] = $ticket_detail->pricetext;
                            $total_price += $ticket_detail->pricetext;
                            $total_net_price += $ticket_detail->ticket_net_price;
                            $total_quantity += 1;
                            $prepaid_tickets_data['net_price'] = $ticket_detail->ticket_net_price;
                            $prepaid_tickets_data['discount'] = $ticket_detail->saveamount;
                            if ($ticket_detail->newPrice > 0) {
                                $supplier_price = $ticket_detail->newPrice;
                            } else {
                                $supplier_price = $ticket_detail->pricetext;
                            }
                            
                            $prepaid_tickets_data['supplier_price']          = $supplier_price;
                            $prepaid_tickets_data['tax']               = $ticket_detail->ticket_tax_value;
                            $prepaid_tickets_data['group_type_ticket'] = $ticket_detail->group_type_ticket;
                            $prepaid_tickets_data['group_price']       = $ticket_detail->group_price;
                            $prepaid_tickets_data['used']              = '0';
                            $prepaid_tickets_data['from_time']         = '0';
                            $prepaid_tickets_data['to_time']            = '0';
                            $prepaid_tickets_data['pax']                  = '1';
                            $prepaid_tickets_data['capacity']             = '1';
                            $prepaid_tickets_data['pos_point_id']             = !empty($pos_point_id) ? $pos_point_id : '';
                            $prepaid_tickets_data['pos_point_name']           = !empty($pos_point_name) ? $pos_point_name: '';
                            $prepaid_tickets_data['selected_date']         = gmdate("Y-m-d");
                            $prepaid_tickets_data['timeslot']         = '0';
                            $prepaid_tickets_data['shared_capacity_id']         = '0';
                            $prepaid_tickets_data['redeem_users']      = '';
                            $prepaid_tickets_data['museum_cashier_id']        = 0;
                            $prepaid_tickets_data['museum_cashier_name']      = '';
//                            $prepaid_tickets_data['merchant_admin_id'] = $cluster_details['merchant_admin_id'] ;
                            if(!empty($pass_data[0]->bleep_pass_no)){
                                $prepaid_tickets_data['museum_cashier_id']        = $pass_data[0]->museum_cashier_id;
                                $prepaid_tickets_data['museum_cashier_name']      = $pass_data[0]->museum_cashier_name;
                                $extra_params['voucher_updated_by']       = $pass_data[0]->museum_cashier_id;
                                $extra_params['voucher_updated_by_name']  = $pass_data[0]->museum_cashier_name;
                                $extra_params['redeem_method']            = 'Voucher';
                            }
                            if (!empty($pass_data[0]->bleep_pass_no)) {
                                $prepaid_tickets_data['action_performed'] = 'CSS_ACT, SCAN_PRE_CSS';
                            } else if (!empty($bleep_pass_no)) {
                                $prepaid_tickets_data['action_performed'] = '';
                            }
                            $extra_params["merchant_admin_id"][$prepaid_tickets_data['ticket_id']] = 0;
                            if (!empty($ticket_detail->merchant_admin_id)) {
                                $extra_params["merchant_admin_id"][$prepaid_tickets_data['ticket_id']] = $ticket_detail->merchant_admin_id;
                            }

                            unset($prepaid_tickets_data['scanned_at']);
                            unset($prepaid_tickets_data['redeem_date_time']);
                            $logs['pt_subticket_data'] = $prepaid_tickets_data;
                            $this->db->insert('prepaid_tickets', $prepaid_tickets_data);
                            $extra_params['image']                    = $ticket_detail->eventImage;
                            $extra_params['location']                 = $ticket_detail->location;
                            $extra_params['highlights']               = $ticket_detail->highlights;
                            $extra_params['oroginal_price']           = $ticket_detail->original_price;
                            $extra_params['order_currency_oroginal_price'] = $ticket_detail->original_price;
                            $extra_params['order_currency_net_price']      = $ticket_detail->ticket_net_price;
                            $extra_params['is_discount_in_percent']        = $ticket_detail->discountType == '2' ? 1 : 0;
                            $extra_params['order_currency_discount']       = $ticket_detail->saveamount;
                            $extra_params['supplier_original_price']       = $ticket_detail->original_price;
                            $extra_params['discount_code_value']='';
                            $extra_params['redeem_method'] = $extra_params['image'] = $extra_params['oroginal_price'] = $extra_params['net_service_cost'] = $extra_params['rezgo_id'] = $extra_params['rezgo_ticket_price'] = $extra_params['rezgo_ticket_id'] = $extra_params['is_iticket_product'] = '';
                            $extra_params['last_imported_date']=gmdate("Y-m-d H:i:s");
                            $extra_params['is_discount_code']       = 0;
                            $extra_params['group_linked_with'] = $ticket_detail->group_linked_with;
                            $extra_params['supplier_discount']       = $ticket_detail->saveamount;
                            $extra_params['supplier_tax']            = $ticket_detail->ticket_tax_value;
                            $extra_params['supplier_net_price']      = $ticket_detail->ticket_net_price;
                            $extra_params['ticket_amount_before_extra_discount']        = $ticket_detail->pricetext;
                            $extra_params['main_tps_id']   = $main_ticket_price_schedule_id;
                        }
                    }
                    /** To insert Cluster sub tickets entry * */
                }
                $logs["extra_params " . date('H:i:s')] = $extra_params;
                /* UPDATE redumption of cashier in batch_rule table */
                if (!empty($batch_rule_id) && $countPtRecords > 0) {
                    $this->db->query("UPDATE batch_rules SET quantity_redeemed = (quantity_redeemed+{$countPtRecords}) WHERE batch_rule_id = {$batch_rule_id}");
                    $logs['update_batch_rules_' . date('H:i:s')] = $this->db->last_query();
                }
                /* UPDATE redumption of cashier in batch_rule table */

                if (!empty($bookings)) {
                    try {
                        $headers = $this->all_headers(array(
                            'ticket_id' => $ticket_id,
                            'hotel_id' => $pass_data[0]->hotel_id,
                            'museum_id' => $museum_id,
                            'user_id' => $museum_cashier_id,
                            'channel_type' => $prepaid_ticket_data['channel_type'],
                            'action' => 'sync_voucher_scans_on_pre_assigned_records'
                        ));
                        if($cashier_type == "3" && $reseller_id != '') {
                            $node = 'resellers/' . $reseller_id . '/voucher_scans/' . $reseller_cashier_id . '/' . gmdate("Y-m-d");
                        } else {
                            $node = 'suppliers/' . $museum_id . '/voucher_scans/' . $museum_cashier_id . '/' . gmdate("Y-m-d");
                        }
                        $params = json_encode(array("node" =>  $node, 'search_key' => '', 'search_value' => '', 'details' => $bookings));
                        $logs['order_sync_req_' . date('H:i:s')] = json_decode($params);
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        $getdata = curl_exec($ch);
                        $logs['order_sync_res_' . date('H:i:s')] = json_decode($getdata);
                        curl_close($ch);
                    } catch (Exception $e) {
                        $logs['exception'] = $e->getMessage();
                    }
                }
                $update_redeem_table = array();
                $update_redeem_table['visitor_group_no']    = $visitor_group_no;
                $update_redeem_table['prepaid_ticket_ids']    = array( $prepaid_ticket_id );
                $update_redeem_table['museum_cashier_id']   = $pass_data[0]->museum_cashier_id;
                $update_redeem_table['shift_id']            = $shift_id;
                $update_redeem_table['redeem_date_time']    = gmdate("Y-m-d H:i:s");
                $update_redeem_table['museum_cashier_name'] = $pass_data[0]->museum_cashier_name;                   
                $update_redeem_table['hotel_id']            =  $pass_data[0]->hotel_id;                   
                $this->db->update("hotel_ticket_overview", array("total_price" => ($total_price), "total_net_price" => ($total_net_price), "quantity" => $total_quantity), array("visitor_group_no" => $visitor_group_no));
            }
            $MPOS_LOGS['update_pre_assigned_records'] = $logs;
            $logs = array();

            $this->order_process_update_model->update_order_table($hto_id, 1, '1', $extra_params);
            if (SYNCH_WITH_RDS_REALTIME) {
                $this->order_process_update_model->update_order_table($hto_id, 1, '4', $extra_params);
            }
            $this->update_redeem_table($update_redeem_table);
        } else {
            return false;
        }
    }
    
    function update_redeem_table($data = ''){        
        global $MPOS_LOGS;
        $visitor_group_no   = $data['visitor_group_no'];
        $museum_id          = $data['museum_id'];
        $ticket_id          = $data['ticket_id'];
        $selected_date      = $data['selected_date'];
        $from_time          = $data['from_time'];
        $to_time            = $data['to_time'];
        $shift_id            = $data['shift_id'];
        $redeem_date_time            = $data['redeem_date_time'];
        $prepaid_ticket_ids = $data['prepaid_ticket_ids'];
        $clustering_ids = $data['clustering_id_in'];
        $hotel_id           = (isset($data['hotel_id'])? $data['hotel_id']: 0);
        $update_on_pass_no  = isset($data['update_on_pass_no']) ? $data['update_on_pass_no'] : 0;
        $pass_no = $data['pass_no'];
        $cashier_type = $data['cashier_type'];
        if(!empty($data)){            
            $prepaid_table = 'prepaid_tickets';
            //Get data from PT
            $this->secondarydb->db->select('is_refunded, activated, price as total_amount, version, prepaid_ticket_id, museum_id,distributor_partner_id,distributor_partner_name, partner_category_id,partner_category_name, museum_name, hotel_id, hotel_name, reseller_id, reseller_name, is_addon_ticket, clustering_id, ticket_id, tps_id, title, ticket_type, distributor_type, age_group, visitor_group_no, passNo, bleep_pass_no, channel_type, action_performed');
            $this->secondarydb->db->from($prepaid_table);
            if(!empty($prepaid_ticket_ids)){
                $this->secondarydb->db->where_in('prepaid_ticket_id', $prepaid_ticket_ids);
            } else {
                $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);                
                if(!empty($museum_id) && $cashier_type != "3"){
                    $this->secondarydb->db->where('museum_id', $museum_id);
                }
                if($clustering_ids != '' && $clustering_ids != null) {
                    $this->secondarydb->db->where('clustering_id IN ('.$clustering_ids.')');
                } else if(!empty($ticket_id)){
                    $this->secondarydb->db->where('ticket_id', $ticket_id);
                }
                if(!empty($selected_date)){
                    $this->secondarydb->db->where(array("selected_date" => $selected_date, "from_time" => $from_time, "to_time" => $to_time));
                }
                if($update_on_pass_no == 1){
                    $this->secondarydb->db->where(array("passNo" => $pass_no));
                }
            }         
            $this->secondarydb->db->where(array("used" => '1'));
            $this->secondarydb->db->where_in("order_status", array('0', '2', '3'));

            $result = $this->secondarydb->db->get();           
            $logs['db2_pt_query_num_rows -> '.$result->num_rows().'_'.date('H:i:s')]=$this->secondarydb->db->last_query();
            if ($result->num_rows() > 0) {
                $prepaid_data_all = $result->result_array();
                $prepaid_data_all = $this->get_max_version_data($prepaid_data_all, 'prepaid_ticket_id');
                $prepaid_data = array_filter(array_values($prepaid_data_all), function ($val) {
                    return ($val['is_refunded'] != 1 && $val['activated'] == 1);
                });
                $logs['db2_pt_query_after fileter_num_rows -> '.date('H:i:s')]= sizeof($prepaid_data);
                //Prepare array to insert data in redeem_cashiers_Details table
                foreach($prepaid_data as $prepaid_row) {
                    $prepaid_row = (object)$prepaid_row;
                    if( !empty($data['action']) && !strstr($prepaid_row->action_performed, $data['action'])){
                        continue;
                    }                    
                    $redeem_data                     = array();
                    $redeem_data['id']               = (int) (microtime(true) * 1000000000);
                    $redeem_data['supplier_id']      = (int)$prepaid_row->museum_id;
                    $redeem_data['prepaid_ticket_id']      = (int)$prepaid_row->prepaid_ticket_id;
                    $redeem_data['supplier_name']    = $prepaid_row->museum_name;
                    $redeem_data['distributor_id']   = (int)$prepaid_row->hotel_id;
                    $redeem_data['distributor_name'] = $prepaid_row->hotel_name; 
                    $redeem_data['distributor_partner_id']   = (int)$prepaid_row->distributor_partner_id;
                    $redeem_data['distributor_partner_name'] = $prepaid_row->distributor_partner_name; 
                    $redeem_data['partner_category_id']   = (int)$prepaid_row->partner_category_id;
                    $redeem_data['partner_category_name'] = $prepaid_row->partner_category_name; 
                    $redeem_data['reseller_id']      = (int)$prepaid_row->reseller_id;
                    $redeem_data['reseller_name']    = $prepaid_row->reseller_name;
                    $redeem_data['partner_id']       = (int)$prepaid_row->distributor_partner_id;
                    $redeem_data['partner_name']     = $prepaid_row->distributor_partner_name;
                    $redeem_data['is_addon_ticket']  = (int)$prepaid_row->is_addon_ticket;
                    $redeem_data['clustering_id']    = $prepaid_row->clustering_id;
                    $redeem_data['ticket_id']        = (int)$prepaid_row->ticket_id;
                    $redeem_data['tps_id']           = (int)$prepaid_row->tps_id;
                    $redeem_data['ticket_title']     = $prepaid_row->title;
                    $redeem_data['age_group']        = $prepaid_row->age_group;
                    $redeem_data['price']            = (float)$prepaid_row->total_amount;
                    $redeem_data['ticket_type']      = $prepaid_row->ticket_type;                   
                    $redeem_data['cashier_id']       = !empty($data['museum_cashier_id']) ? (int)$data['museum_cashier_id'] : '0';
                    $redeem_data['shift_id']       = !empty($shift_id) ? (int)$shift_id : $prepaid_row->shift_id;
                    $redeem_data['cashier_name']     = !empty($data['museum_cashier_name']) ? $data['museum_cashier_name'] : '';
                    $redeem_data['voucher_updated_by']      = !empty($data['museum_cashier_id']) ? (int)$data['museum_cashier_id'] : '0';
                    $redeem_data['voucher_updated_by_name'] = !empty($data['museum_cashier_name']) ? $data['museum_cashier_name'] : '';
                    $redeem_data['redeem_time']      = (isset($redeem_date_time)) ? $redeem_date_time : gmdate("Y-m-d H:i:s");
                    $redeem_data['count']            = 1;
                    $redeem_data['visitor_group_no'] = $prepaid_row->visitor_group_no;                   
                    $redeem_data['pass_no']          = !empty($prepaid_row->bleep_pass_no) ? $prepaid_row->bleep_pass_no : $prepaid_row->passNo;
                    $redeem_data['channel_type']     = (int)$prepaid_row->channel_type;
                    $redeem_data['action_performed'] = $prepaid_row->action_performed;
                    $redeem_data['distributor_type'] = $prepaid_row->distributor_type;                   
                    $redeem_data['created_at']       = gmdate('Y-m-d h:i:s');
                    $redeem_data['last_modified_at']       = gmdate('Y-m-d H:i:s');
                    $total_redeem_data_db1[] = $redeem_data;
                }
                if (!empty($total_redeem_data_db1)) {
                    $this->pos_model->insert_batch('redeem_cashiers_details', $total_redeem_data_db1);
                    $logs['redeem_cashiers_details_db1_'.date('H:i:s')]=$total_redeem_data_db1;           
                    if (SYNCH_WITH_RDS_REALTIME) {
                        $this->pos_model->insert_batch('redeem_cashiers_details', $total_redeem_data_db1, '4');
                        $logs['redeem_cashiers_details_db4_'.date('H:i:s')]= 'same as db1 data';
                    }
                }
                
            }
        }
        $MPOS_LOGS['update_redeem_table']=$logs;
    }
    
    function fetch_current_timeslot($ticket_id = '') {
        $is_reservation = $this->common_model->getSingleFieldValueFromTable('is_reservation', 'modeventcontent', array('mec_id' => $ticket_id));
        $timezone = $this->common_model->getSingleFieldValueFromTable('timezone', 'modeventcontent', array('mec_id' => $ticket_id));
        $timezone = $timezone*60*60;
        
        $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
        $current_timeZone    = $servicedata->timeZone*60*60;
        if($is_reservation == 1) {
            $current_date = gmdate('Y-m-d');

            $current_timestamp = strtotime(gmdate('H:i:s'))+$current_timeZone;
            $current_time = date('H:i:s', $current_timestamp);
            $current_day  = date('l', strtotime($current_date));
            $timeslot_details = '';
            $current_timeslot = array();
            $this->primarydb->db->select('*');
            $this->primarydb->db->from('standardticketopeninghours');
            $this->primarydb->db->where('ticket_id', $ticket_id);
            $this->primarydb->db->where('days', $current_day);
            $result = $this->primarydb->db->get();
            if($result->num_rows() > 0) {
                $data = $result->result_array();
                foreach($data as $value) {
                    if($value['start_from'] < $current_time && $value['end_to'] > $current_time) {
                        $current_timeslot = $value;
                        break;
                    } else {
                        continue;
                    }
                }
                if(strstr($current_timeslot['start_from'], '-')) {
                    $current_timeslot['start_from'] = substr($current_timeslot['start_from'], 1, strlen($current_timeslot['start_from']));
                }
                $start_from = strtotime($current_timeslot['start_from'])+$timezone;
                $end_to     = strtotime($current_timeslot['end_to'])+$timezone;
                $timeslot   = $current_timeslot['timeslot'];
                if($timeslot == 'specific' || $timeslot == 'day') {
                    $timeslot_details = date('H:i', $start_from).'_'.date('H:i', $end_to).'_'.$timeslot;
                } else if($timeslot == 'hour') {
                    $time_difference = '3600';
                } else if($timeslot == '30min') {
                    $time_difference = '1800';
                } else if($timeslot == '15min') {
                    $time_difference = '900';
                }
                if($timeslot_details == '') {
                    for($i = $start_from; $i < $end_to; $i+=$time_difference) { 
                        $to_time = $i+$time_difference;
                        if($i < $current_timestamp && $to_time > $current_timestamp) {
                            $timeslot_details = date('H:i', $i).'_'.date('H:i', $to_time).'_'.$timeslot;
                            break;
                        } else {
                            continue;
                        }
                    }
                }
                return $timeslot_details; 
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * @name   : extended_linked_ticket_from_css_app()
     * @purpose: When extend any ticket from css app with any existing ticket
     * @where  : It is called from pos.php
     * @returns: No parameter is returned
     */
    function extended_linked_ticket_from_css_app($request_data = array(), $is_whole_order = 0){
        global $MPOS_LOGS;
        $logs['request'.date('H:i:s')]=$request_data;
        $is_prepaid     = $request_data['is_prepaid'];
        $museum_id      = $request_data['museum_id'];
        $cashier_id     = $request_data['cashier_id'];
        $cashier_name   = $request_data['cashier_name'];
        $main_ticket_id = $request_data['main_ticket_id'];
        $ticket_id      = $request_data['ticket_id'];
        $visitor_group_no = $request_data['visitor_group_no'];
        $where_string     = $request_data['where_string'];
        $upsell_left_time = $request_data['upsell_left_time'];
        $payment_type     = $request_data['payment_type'];
        $disributor_cashier_id     = $request_data['disributor_cashier_id'];
        $disributor_cashier_name   = $request_data['disributor_cashier_name'];
        $channel_type   = $request_data['channel_type'];
        $pos_point_id   = $request_data['pos_point_id'];
        $pos_point_name   = $request_data['pos_point_name'];
        $shift_id   = $request_data['shift_id'];
        $start_amount   = $request_data['start_amount'];
        $ticket_booking_id = $request_data['ticket_booking_id'];
        $this->createLog('extended_linked_ticket_from_app.php',array('logs' => json_encode($logs)));
        return $this->single_type_upsell( $main_ticket_id, $where_string, $visitor_group_no, $ticket_id, $cashier_id, $cashier_name, $upsell_left_time, $payment_type, $disributor_cashier_id, $disributor_cashier_name , $channel_type, $pos_point_id, $pos_point_name, $shift_id, $start_amount, $ticket_booking_id); 
    }
    
    function single_type_upsell($main_ticket_id = '', $where_string = '', $visitor_group_no = '', $ticket_id = 0, $cashier_id = '', $cashier_name = '', $upsell_left_time = '', $payment_type = 0, $disributor_cashier_id = '', $disributor_cashier_name = '' , $channel_type = 0, $pos_point_id = 0, $pos_point_name = '', $shift_id = 0, $start_amount = 0, $ticket_booking_id = 0){
        $final_prepaid_tickets_data = array();
        global $MPOS_LOGS;
        $logs['single_type_upsell_data'] = array('main_ticket_id' => $main_ticket_id, 'visitor_group_no' => $visitor_group_no, 'ticket_id' => $ticket_id, 'cashier_id' => $cashier_id, 'upsell_left_time' => $upsell_left_time, 'disributor_cashier_id' => $disributor_cashier_id, 'channel_type' => $channel_type, 'pos_point_id' => $pos_point_id, 'shift_id' => $shift_id, 'start_amount' => $start_amount);
            $pt_table = 'prepaid_tickets';
            $cashier_details = '';
            if(!empty($cashier_id)){
                $cashier_details = reset($this->pos_model->find('users', array('select' => 'cod_id, company', 'where' => 'id = "'.$cashier_id.'"')));
            }

            if($ticket_booking_id == 0){
                $random = substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", 5)), 0, 5);
                $ticket_booking_id = $visitor_group_no . $random;
            }
            // Fetch redemption_notified_at from DB2 as this field is not in DB1
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from($pt_table);  
            $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);
            $result_count = $this->secondarydb->db->get();
            $pt_data = $result_count->result_array();
            $last_count = 0;
            if(!empty($pt_data)) {
                $pt_ids_data = $this->get_max_version_data($pt_data, 'prepaid_ticket_id');
                $pt_data = array_filter($pt_ids_data, function ($val) {
                    return ($val['is_refunded'] != 1);
                });
                $pt_data = array_values($pt_data);
                $redemption_notified_at = $pt_data[0]['redemption_notified_at'];
                $pos_point_id_on_redeem = $pt_data[0]['pos_point_id_on_redeem'];
                $pos_point_name_on_redeem = $pt_data[0]['pos_point_name_on_redeem'];
                $distributor_id_on_redeem = $pt_data[0]['distributor_id_on_redeem'];
                $distributor_cashier_id_on_redeem = $pt_data[0]['distributor_cashier_id_on_redeem'];
                $service_cost_type = $pt_data[0]['service_cost_type'];
                $service_cost = $pt_data[0]['service_cost'];
                $net_service_cost = $pt_data[0]['net_service_cost'];
                $channel_id = $pt_data[0]['channel_id'];
                $channel_name = $pt_data[0]['channel_name'];
                $saledesk_id = $pt_data[0]['saledesk_id'];
                $saledesk_name = $pt_data[0]['saledesk_name'];
                $extra_discount = $pt_data[0]['extra_discount'];
                $extra_fee = $pt_data[0]['extra_fee'];
                $order_currency_price = $pt_data[0]['order_currency_price'];
                $ticket_scan_price = $pt_data[0]['ticket_scan_price'];
                $supplier_currency_code = $pt_data[0]['supplier_currency_code'];
                $supplier_currency_symbol = $pt_data[0]['supplier_currency_symbol'];
                $order_currency_code = $pt_data[0]['order_currency_code'];
                $order_currency_symbol = $pt_data[0]['order_currency_symbol'];
                $last_count = sizeof($pt_ids_data);
                $this->createLog('extended_linked_ticket_check.php','last count :  '.$last_count, array('data' => json_encode($pt_ids_data)));
            }
            // Fetch all other details from DB1
            $this->db->select('*');
            $this->db->from('prepaid_tickets');  
            $this->db->where('visitor_group_no', $visitor_group_no);
            $this->db->where('ticket_id', $main_ticket_id);
            $this->db->where($where_string);
            $this->db->where(array("is_addon_ticket" => '0', "activated" => '1'));            
            $this->db->where_in("is_refunded", array('0', '2'));
            $this->db->where_in("order_status", array('0', '2', '3'));
            $result = $this->db->get();
            $created_date_time = gmdate('Y-m-d H:i:s');
            $logs['db2_query_pt_'.date('H:i:s')]=$this->db->last_query();
            if($result->num_rows() > 0) {
                $prepaid_tickets_all_data = $result->result_array();
                $n = 0;
                foreach($prepaid_tickets_all_data as $prepaid_tickets_detail) {
                    $valid_till = '';
                    $timezone = $prepaid_tickets_detail['timezone'];
                    $additional_information = unserialize($prepaid_tickets_detail['additional_information']);
                    $start_date = (date("Y-m-d", strtotime($additional_information['start_date'])));
                    $end_date = (date("Y-m-d", strtotime($additional_information['end_date'])));
                    $prepaid_tickets_data = array();
                    $prepaid_tickets_data = $prepaid_tickets_detail;
                    $is_combi_ticket = $prepaid_tickets_data['is_combi_ticket'];
                    $extra_discount_information = $prepaid_tickets_detail['extra_discount'];
                    if($is_combi_ticket == "1") {
                        $transaction_id = $prepaid_tickets_data['prepaid_ticket_id'];
                        $new_pass_no = $prepaid_tickets_data['passNo'];
                        $new_bleep_pass_no = $prepaid_tickets_data['bleep_pass_no'];
                    } else {
                        $new_pass_no = $prepaid_tickets_data['passNo'];
                    }
                    $new_ticket_id = $prepaid_tickets_data['ticket_id'];
                    $old_shared_capacity_id =  $prepaid_tickets_data['shared_capacity_id'];
                    $old_timezone  =  $prepaid_tickets_data['timezone'];
                    $new_tps_id = $prepaid_tickets_data['tps_id'];
                    $prepaid_tickets_data['prepaid_ticket_id'] = $visitor_group_no.str_pad(++$last_count, 3, "0", STR_PAD_LEFT);
                    $prepaid_tickets_data['visitor_tickets_id'] = $prepaid_tickets_data['prepaid_ticket_id'].'01';
                    $age_group = explode("-", $prepaid_tickets_detail['age_group']);
                    $tps_ticket_type = (!empty($this->types[strtolower($prepaid_tickets_detail['ticket_type'])]) && ($this->types[strtolower($prepaid_tickets_detail['ticket_type'])] > 0)) ? $this->types[strtolower($prepaid_tickets_detail['ticket_type'])] : 10;
                    $this->primarydb->db->select('mec.mec_id, mec.allow_city_card, mec.cod_id, mec.museum_name, mec.postingEventTitle as title, mec.additional_information, mec.is_scan_countdown, mec.countdown_interval, mec.location, mec.highlights, mec.eventImage, mec.shared_capacity_id, mec.own_capacity_id, mec.timezone, mec.is_combi, tps.id, tps.ticketType, tps.group_type_ticket, tps.group_price,  tps.group_linked_with, tps.ticket_tax_id, tps.ticket_tax_value, tps.pricetext, tps.newPrice, tps.ticket_net_price, tps.saveamount, tps.original_price, tps.discountType, tps.ticket_type_label, tps.agefrom, tps.ageto', false);
                    $this->primarydb->db->from("modeventcontent mec");            
                    $this->primarydb->db->join("ticketpriceschedule tps", 'mec.mec_id=tps.ticket_id', 'left');
                    $this->primarydb->db->where(array("mec.mec_id" => $ticket_id, "mec.active" => '1', "mec.deleted" => '0', 
                        "tps.ticketType" => $tps_ticket_type, "tps.agefrom" => $age_group[0], "tps.ageto" => $age_group[1]));
                    $this->primarydb->db->where(array());
                    
                    if(isset($additional_information['start_date']) && isset($additional_information['end_date']) && date("Y", strtotime($additional_information['end_date'])) != 2286){
                        $this->primarydb->db->where("DATE_FORMAT(FROM_UNIXTIME(tps.start_date + ".($timezone * 3600)."), '%Y-%m-%d') = '".$start_date."'");
                        $this->primarydb->db->where("(DATE_FORMAT(FROM_UNIXTIME(tps.end_date + ".($timezone * 3600)."), '%Y-%m-%d') = '". $end_date."' || tps.end_date = '9999999999')"); 
                    }
                    
                    $ticket_query  =   $this->primarydb->db->get();
                    if($ticket_query->num_rows() > 0){
                        $ticket_detail = $ticket_query->row();
                    }
                    $logs['mod_event_query_'.date('H:i:s')]=$this->primarydb->db->last_query();
                    if(!empty($disributor_cashier_id)) {
                        $user_detail = $this->pos_model->find('users', array('select' => 'cod_id, company', 'where' => 'id = "'.$disributor_cashier_id.'"'));
                        $hotel_id = $user_detail[0]['cod_id'];
                        $hotel_name = $user_detail[0]['company'];
                    }
                    if($ticket_detail->is_scan_countdown == 1 && $prepaid_tickets_data['scanned_at'] > 0) {
                        $countdown_values = explode('-', $ticket_detail->countdown_interval);
                        $countdown_time = $this->get_count_down_time($countdown_values[1], $countdown_values[0]);
                        $valid_till = $prepaid_tickets_data['scanned_at'] + $countdown_time;
                    }
                    //Prepare main ticket details array 
                    $prepaid_tickets_data['ticket_booking_id']   = $ticket_booking_id;
                    $prepaid_tickets_data['payment_conditions']  = $valid_till;
                    $prepaid_tickets_data['ticket_id']           = $ticket_id;
                    $prepaid_tickets_data['tps_id']              = $ticket_detail->id;
                    $prepaid_tickets_data['museum_id']           = $ticket_detail->cod_id;
                    $prepaid_tickets_data['museum_name']         = $ticket_detail->museum_name;
                    $actual_cashier_id = $prepaid_tickets_data['cashier_id'];
                    $prepaid_tickets_data['hotel_name']          = !empty($disributor_cashier_id) ? $hotel_name : $prepaid_tickets_data['hotel_name'] ;
                    $prepaid_tickets_data['hotel_id']            = !empty($disributor_cashier_id) ? $hotel_id : $prepaid_tickets_data['hotel_id'] ;
                    $prepaid_tickets_data['cashier_id']          = !empty($disributor_cashier_id) ? $disributor_cashier_id : $prepaid_tickets_data['cashier_id'] ;
                    $prepaid_tickets_data['cashier_name']        = !empty($disributor_cashier_name) ? $disributor_cashier_name : $prepaid_tickets_data['cashier_name'] ;
                    $prepaid_tickets_data['created_date_time']   = $created_date_time;
                    $prepaid_tickets_data['action_performed']    = 'UPSELL_INSERT';
                    $prepaid_tickets_data['ticket_id']           = $ticket_id;
                    $prepaid_tickets_data['from_time']           = !empty($prepaid_tickets_data['from_time']) ? $prepaid_tickets_data['from_time'] : 0;
                    $prepaid_tickets_data['to_time']           = !empty($prepaid_tickets_data['to_time']) ? $prepaid_tickets_data['to_time'] : 0;
                    $prepaid_tickets_data['timeslot']           = !empty($prepaid_tickets_data['timeslot']) ? $prepaid_tickets_data['timeslot'] : 0;
                    $prepaid_tickets_data['selected_date']           = !empty($prepaid_tickets_data['selected_date']) ? $prepaid_tickets_data['selected_date'] : 0;
                    $prepaid_tickets_data['booking_selected_date']   = !empty($prepaid_tickets_data['booking_selected_date']) ? $prepaid_tickets_data['booking_selected_date'] : '';
                    $prepaid_tickets_data['activation_method']   = $payment_type;
                    $prepaid_tickets_data['pos_point_id']   = !empty($pos_point_id) ? $pos_point_id : $prepaid_tickets_data['pos_point_id'] ;
                    $prepaid_tickets_data['pos_point_name']   = !empty($pos_point_name) ? $pos_point_name : $prepaid_tickets_data['pos_point_name'] ;
                    $prepaid_tickets_data['shift_id']   = !empty($shift_id) ? $shift_id : $prepaid_tickets_data['shift_id'] ;
                    $prepaid_tickets_data['related_product_id']   = $ticket_id;            
                    unset($prepaid_tickets_data['last_modified_at']);
                    $prepaid_tickets_data['is_prepaid']          = 1;
                    if($prepaid_tickets_data['channel_type'] == '2'){
                        $prepaid_tickets_data['booking_status']      = $prepaid_tickets_data['booking_status']; 
                    } else{
                        $prepaid_tickets_data['booking_status']      = 1;
                    }
                    $prepaid_tickets_data['tp_payment_method']   = 1;

                    $prepaid_tickets_data['title']                    = $ticket_detail->title;
                    if( empty($prepaid_tickets_data['additional_information']) ) {
                        $prepaid_tickets_data['additional_information'] = serialize( array('actual_seller'=>$actual_cashier_id) );
                    } else {
                        $additional_information = unserialize($prepaid_tickets_data['additional_information']);
                        $additional_information['actual_seller'] = $actual_cashier_id;
                        $prepaid_tickets_data['additional_information'] = serialize( $additional_information );
                    }
                    if($prepaid_tickets_data['channel_type'] == "5") {
                        $additional_information = unserialize($prepaid_tickets_data['additional_information']);
                        $additional_information['extended_preassigned'] = 1;
                        $prepaid_tickets_data['additional_information'] = serialize( $additional_information );
                    }
                    $prepaid_tickets_data['channel_type']   = !empty($channel_type) ? $channel_type : $prepaid_tickets_data['channel_type'] ;
                    $prepaid_tickets_data['age_group']                = $ticket_detail->agefrom.'-'.$ticket_detail->ageto;
                    $prepaid_tickets_data['ticket_type']              = $ticket_detail->ticket_type_label;                
                    $prepaid_tickets_data['price']                         = $ticket_detail->pricetext;
                    $prepaid_tickets_data['net_price']                     = $ticket_detail->ticket_net_price;
                    $prepaid_tickets_data['discount']                      = $ticket_detail->saveamount;
                    if ($ticket_detail->newPrice > 0) {
                        $supplier_price = $ticket_detail->newPrice;
                    } else {
                        $supplier_price = $ticket_detail->pricetext;
                    }
                    if ($ticket_detail->own_capacity_id > 0) {
                        $prepaid_tickets_data['shared_capacity_id']  = $ticket_detail->shared_capacity_id.','.$ticket_detail->own_capacity_id;
                    } else {
                        $prepaid_tickets_data['shared_capacity_id']  = $ticket_detail->shared_capacity_id;
                    }
                    // insert unpaid entry in PT table
                    if ($payment_type == "") {
                        $prepaid_tickets_data['tp_payment_method'] = 0;
                        $prepaid_tickets_data['activation_method'] = 0;
                    }
                    $prepaid_tickets_data['supplier_price']          = $supplier_price;           
                    $prepaid_tickets_data['extra_discount']                             =  $extra_discount_information;                    
                    $prepaid_tickets_data['is_combi_discount']                          = '0';
                    $prepaid_tickets_data['combi_discount_gross_amount']                = '0';                  
                    $prepaid_tickets_data['tax']               = $ticket_detail->ticket_tax_value;
                    $prepaid_tickets_data['quantity']          = 1;                   
                    $prepaid_tickets_data['is_prioticket']     = '0';
                    $prepaid_tickets_data['group_type_ticket'] = $ticket_detail->group_type_ticket;
                    $prepaid_tickets_data['group_price']       = $ticket_detail->group_price;
                    $prepaid_tickets_data['group_quantity']    = 1;                                
                    $update_cashier_register_data['activation_method'] = $prepaid_tickets_data['activation_method'];
                    $total_price += $prepaid_tickets_data['price'];
                    $update_cashier_register_data['hotel_id'] = $prepaid_tickets_data['hotel_id'];
                    $update_cashier_register_data['cashier_id'] = $prepaid_tickets_data['cashier_id'];
                    $update_cashier_register_data['shift_id'] = $prepaid_tickets_data['shift_id'];
                    $update_cashier_register_data['pos_point_id'] = $prepaid_tickets_data['pos_point_id'];
                    $update_cashier_register_data['pos_point_name'] = $prepaid_tickets_data['pos_point_name'];
                    $update_cashier_register_data['cashier_name'] = $prepaid_tickets_data['cashier_name'];
                    $update_cashier_register_data['hotel_name'] = $prepaid_tickets_data['hotel_name'];
                    $update_cashier_register_data['timezone'] = $prepaid_tickets_data['timezone'];
                    $this->db->insert('prepaid_tickets', $prepaid_tickets_data);
                    $logs['pt_insert_'.$n] = $this->db->last_query();
                    $prepaid_ticket_id = $this->db->insert_id();
                    $prepaid_tickets_data['prepaid_ticket_id'] = $prepaid_ticket_id;                   
                    $prepaid_tickets_data['redemption_notified_at'] = $redemption_notified_at;                   
                    $prepaid_tickets_data['main_ticket_id'] = $main_ticket_id;
                    $prepaid_tickets_data['scanned_pass']  = isset($prepaid_tickets_data['bleep_pass_no']) ? $prepaid_tickets_data['bleep_pass_no'] : '';
                    $prepaid_tickets_data['voucher_updated_by']   = $cashier_id;
                    $prepaid_tickets_data['voucher_updated_by_name'] = $cashier_name;
                    $prepaid_tickets_data['created_at']          = strtotime($created_date_time);
                    $prepaid_tickets_data['order_confirm_date']  = $prepaid_tickets_data['payment_date'] = $prepaid_tickets_data['updated_at'] = $prepaid_tickets_data['visit_date_time'] = $created_date_time;
                    $prepaid_tickets_data['related_product_title']   = $ticket_detail->title;
                    $prepaid_tickets_data['pos_point_id_on_redeem']   = isset($pos_point_id_on_redeem) ? $pos_point_id_on_redeem : 0 ;
                    $prepaid_tickets_data['pos_point_name_on_redeem']   = isset($pos_point_name_on_redeem) ? $pos_point_name_on_redeem : '' ;
                    $prepaid_tickets_data['distributor_id_on_redeem']   = isset($distributor_id_on_redeem) ? $distributor_id_on_redeem : 0 ;
                    $prepaid_tickets_data['distributor_cashier_id_on_redeem']   = isset($distributor_cashier_id_on_redeem) ? $distributor_cashier_id_on_redeem : '' ;
                    if($payment_type == 10) {
                        $prepaid_tickets_data['is_voucher'] = 1;
                    } else {
                        $prepaid_tickets_data['is_voucher'] = 0;
                    }
                    $prepaid_tickets_data['location']                 = $ticket_detail->location;
                    $prepaid_tickets_data['highlights']               = $ticket_detail->highlights;
                    $prepaid_tickets_data['image']                    = $ticket_detail->eventImage;
                    $prepaid_tickets_data['oroginal_price']           = $ticket_detail->original_price;
                    $prepaid_tickets_data['order_currency_oroginal_price'] = $ticket_detail->original_price;
                    $prepaid_tickets_data['order_currency_net_price']      = $ticket_detail->ticket_net_price;
                    $prepaid_tickets_data['is_discount_in_percent']        = $ticket_detail->discountType == '2' ? 1 : 0;
                    $prepaid_tickets_data['order_currency_discount']       = $ticket_detail->saveamount;
                    $prepaid_tickets_data['supplier_original_price']       = $ticket_detail->original_price;
                    $prepaid_tickets_data['supplier_discount']       = $ticket_detail->saveamount;
                    $prepaid_tickets_data['supplier_tax']            = $ticket_detail->ticket_tax_value;
                    $prepaid_tickets_data['supplier_net_price']      = $ticket_detail->ticket_net_price;
                    $prepaid_tickets_data['ticket_amount_before_extra_discount']        = $ticket_detail->pricetext;
                    $prepaid_tickets_data['order_currency_extra_discount']              = '';
                    $prepaid_tickets_data['order_currency_combi_discount_gross_amount'] = '0';
                    $prepaid_tickets_data['is_discount_code']                           = '0';
                    $prepaid_tickets_data['discount_code_value']                        = '';
                    $prepaid_tickets_data['discount_code_amount']                       = '0';
                    $prepaid_tickets_data['discount_code_promotion_title']              = '';
                    $prepaid_tickets_data['service_cost_type']                          = ($service_cost_type == "2") ? '2':'0';
                    $prepaid_tickets_data['service_cost']                               = ($service_cost_type == "2") ? $service_cost:'0';
                    $prepaid_tickets_data['net_service_cost']                           = ($service_cost_type == "2") ? $net_service_cost:'0';
                    $prepaid_tickets_data['refund_quantity']   = 0; 
                    $prepaid_tickets_data['group_linked_with'] = $ticket_detail->group_linked_with; 
                    $prepaid_tickets_data['channel_id']               = $channel_id ;
                    $prepaid_tickets_data['channel_name']             = $channel_name ;
                    $prepaid_tickets_data['saledesk_id']              = $saledesk_id ;
                    $prepaid_tickets_data['saledesk_name']            = $saledesk_name ;
                    // $prepaid_tickets_data['extra_discount']           = $extra_discount_information ;
                    $prepaid_tickets_data['extra_fee']                = $extra_fee ;
                    $prepaid_tickets_data['order_currency_price']     = $order_currency_price ;
                    $prepaid_tickets_data['ticket_scan_price']        = $ticket_scan_price ;
                    $prepaid_tickets_data['supplier_currency_code']   = $supplier_currency_code ;
                    $prepaid_tickets_data['supplier_currency_symbol'] = $supplier_currency_symbol ;
                    $prepaid_tickets_data['order_currency_code']      = $order_currency_code ;
                    $prepaid_tickets_data['order_currency_symbol']    = $order_currency_symbol ;
                    $final_prepaid_tickets_data[] = $prepaid_tickets_data;  
                    /*To update capcity of perticuler ticket*/
                    $shared_capacity_ids = $prepaid_tickets_data['shared_capacity_id'];
                    $shared_capacity_id  = 0;
                    $own_capacity_id     = 0;
                    if( !empty($shared_capacity_ids) && !empty($prepaid_tickets_data['selected_date']) ) {
                        if(strstr($shared_capacity_ids, ',')){
                            $shared_capacity_ids = explode(',', $shared_capacity_ids);
                            $shared_capacity_id  = $shared_capacity_ids[0];
                            $own_capacity_id     = $shared_capacity_ids[1];
                        } else {
                            $shared_capacity_id = $shared_capacity_ids;
                        }
                        $selected_date      = $prepaid_tickets_data['selected_date'];
                        $timeslot_type      = $prepaid_tickets_data['timeslot'];
                        $from_time          = $prepaid_tickets_data['from_time'];
                        $to_time            = $prepaid_tickets_data['to_time'];                        
                        
                        $deactived_shared_capacity_id    = 0;
                        $deactivated_own_capacity_id     = 0;
                        if( !empty($old_shared_capacity_id) && strstr($old_shared_capacity_id, ',')){
                            $old_shared_capacity_id = explode(',', $old_shared_capacity_id);
                            $deactived_shared_capacity_id    = $old_shared_capacity_id[0];
                            $deactivated_own_capacity_id     = $old_shared_capacity_id[1];
                        } else {
                            $deactived_shared_capacity_id = $old_shared_capacity_id;
                        }
                        $this->update_deactivate_ticket_capacity($ticket_id, $selected_date, $from_time, $to_time, 1, $timeslot_type, $old_timezone, $deactived_shared_capacity_id, $deactivated_own_capacity_id);
                        $this->update_upsell_capacity($ticket_id, $selected_date, $from_time, $to_time, 1, $timeslot_type, $ticket_detail->timezone, $shared_capacity_id, $own_capacity_id,  $ticket_detail->cod_id);
                    }
                    //check if ticket is cluster ticket and prepare sub tickets array              
                    if($prepaid_tickets_data['is_addon_ticket'] == '0' && (!empty($prepaid_tickets_data['clustering_id']) || $ticket_detail->is_combi == "2")){
                        $hotel_id_check_cond = 'hotel_id = "' . $prepaid_tickets_data['hotel_id'] . '" and ';
                        if($ticket_detail->is_combi == "2") {
                            $hotel_id_check_cond  = '';
                        }
                        $cluster_tickets_details = $this->pos_model->find('cluster_tickets_detail', array('select' => '*', 'where' => $hotel_id_check_cond . ' main_ticket_id="'.$ticket_id.'" and main_ticket_price_schedule_id ="'.$ticket_detail->id.'" and is_deleted = "0"'));
                        $this->createLog('extended_linked_ticket_check.php','extend cluster ticket :  '.$this->primarydb->db->last_query(), array('data' => json_encode($cluster_tickets_details)));
                        $reseller_id = $this->pos_model->find('qr_codes', array('select' => 'reseller_id, reseller_name', 'where' => 'cod_id = "'.$prepaid_tickets_data['museum_id'].'"'));
                        foreach($cluster_tickets_details as $cluster_details) {
                            $this->primarydb->db->select('mec.mec_id, mec.cod_id, mec.museum_name, mec.postingEventTitle as title, mec.additional_information, mec.location, mec.highlights, mec.eventImage, tps.id, tps.ticketType, tps.group_type_ticket, tps.group_price,  tps.group_linked_with, tps.ticket_tax_id, tps.ticket_tax_value, tps.pricetext, tps.newPrice, tps.ticket_net_price, tps.saveamount, tps.original_price, tps.discountType, tps.ticket_type_label, tps.agefrom, tps.ageto', false);
                            $this->primarydb->db->from("modeventcontent mec");            
                            $this->primarydb->db->where("mec.mec_id", $cluster_details['cluster_ticket_id']);
                            $this->primarydb->db->where("mec.active", '1');
                            $this->primarydb->db->where("mec.deleted", '0');
                            $this->primarydb->db->join("ticketpriceschedule tps", 'mec.mec_id=tps.ticket_id', 'left');  
                            $this->primarydb->db->where("tps.id", $cluster_details['ticket_price_schedule_id']);                            
                            $ticket_query  =   $this->primarydb->db->get(); 
                            $logs['mod_event_query_'.date('H:i:s')]=$this->primarydb->db->last_query();
                            $logs['cluster_tickets_details']=$cluster_tickets_details;
                            if($ticket_query->num_rows() > 0){
                                $ticket_detail = $ticket_query->row();
                            }                           
                            $prepaid_tickets_data['prepaid_ticket_id']   = $visitor_group_no.str_pad(++$last_count, 3, "0", STR_PAD_LEFT);
                            $prepaid_tickets_data['visitor_tickets_id'] = $prepaid_tickets_data['prepaid_ticket_id'].'02';
                            $prepaid_tickets_data['ticket_id']           = $cluster_details['cluster_ticket_id'];
                            $prepaid_tickets_data['museum_id']           = $cluster_details['ticket_museum_id'];
                            $prepaid_tickets_data['museum_name']         = $cluster_details['ticket_museum_name'];
                            $prepaid_tickets_data['tps_id']              = $cluster_details['ticket_price_schedule_id'];
                            $prepaid_tickets_data['is_addon_ticket']     = '2';
                            $prepaid_tickets_data['booking_selected_date']   = gmdate('Y-m-d');
                            $prepaid_tickets_data['title']               = $cluster_details['cluster_ticket_title'];                         
                            $prepaid_tickets_data['age_group']                = $cluster_details['age_from'].'-'.$cluster_details['age_to'];
                            $prepaid_tickets_data['ticket_type']              = $cluster_details['age_group'];                           
                            $prepaid_tickets_data['price']                         = $cluster_details['new_price'];
                            $prepaid_tickets_data['net_price']                     = $cluster_details['ticket_net_price'];                          
                            $prepaid_tickets_data['discount']                      = $ticket_detail->saveamount;                            
                            $prepaid_tickets_data['reseller_id']       = $reseller_id[0]['reseller_id'];
                            $prepaid_tickets_data['reseller_name']       = $reseller_id[0]['reseller_name'];

                            if ($ticket_detail->newPrice > 0) {
                                $supplier_price = $ticket_detail->newPrice;
                            } else {
                                $supplier_price = $ticket_detail->pricetext;
                            }
                            $prepaid_tickets_data['supplier_price']          = $supplier_price;                           
                            $prepaid_tickets_data['tax']               = $cluster_details['ticket_tax_value'];
                            $prepaid_tickets_data['group_type_ticket'] = $ticket_detail->group_type_ticket;
                            $prepaid_tickets_data['group_price']       = $ticket_detail->group_price;                          
                            $prepaid_tickets_data['timeslot']          = '';
                            $prepaid_tickets_data['from_time']         = '';
                            $prepaid_tickets_data['pax']               = 1;
                            $prepaid_tickets_data['capacity']          = 1;
                            $prepaid_tickets_data['to_time']           = '';
                            $prepaid_tickets_data['shared_capacity_id']= 0;
                            $prepaid_tickets_data['redeem_date_time'] = '1970-01-01 00:00:01';
                            $prepaid_tickets_data['redeem_users']= 0;
                            $prepaid_tickets_data['selected_date']     = gmdate("Y-m-d");
                            $prepaid_tickets_data['used']              = '0';
                            $prepaid_tickets_data['ticket_booking_id']   = $ticket_booking_id;
                            unset($prepaid_tickets_data['scanned_at']);
                            unset($prepaid_tickets_data['scanned_pass']);
                            unset($prepaid_tickets_data['main_ticket_id']);
                            unset($prepaid_tickets_data['redemption_notified_at']);
                            unset($prepaid_tickets_data['version']);
                            unset($prepaid_tickets_data['visit_date_time']);
                            $prepaid_tickets_data = $this->unset_columns($prepaid_tickets_data);
                            $this->db->insert('prepaid_tickets', $prepaid_tickets_data);
                            $prepaid_ticket_id = $this->db->insert_id();
                            $prepaid_tickets_data['prepaid_ticket_id'] = $prepaid_ticket_id;
                            $prepaid_tickets_data['main_ticket_id'] = $main_ticket_id;
                            $prepaid_tickets_data['redemption_notified_at'] = $redemption_notified_at;
                            $prepaid_tickets_data['scanned_pass']  = isset($prepaid_tickets_data['bleep_pass_no']) ? $prepaid_tickets_data['bleep_pass_no'] : '';
                            $prepaid_tickets_data['location']                 = $ticket_detail->location;
                            $prepaid_tickets_data['highlights']               = $ticket_detail->highlights;
                            $prepaid_tickets_data['image']                    = $ticket_detail->eventImage;
                            $prepaid_tickets_data['oroginal_price']           = $cluster_details['ticket_gross_price'];
                            $prepaid_tickets_data['order_currency_oroginal_price'] = $cluster_details['ticket_gross_price'];
                            $prepaid_tickets_data['order_currency_net_price']      = $cluster_details['ticket_net_price'];
                            $prepaid_tickets_data['is_discount_in_percent']        = $ticket_detail->discountType == '2' ? 1 : 0;
                            $prepaid_tickets_data['order_currency_discount']       = $ticket_detail->saveamount;
                            $prepaid_tickets_data['supplier_original_price']       = $cluster_details['ticket_gross_price'];
                            $prepaid_tickets_data['supplier_discount']       = $ticket_detail->saveamount;
                            $prepaid_tickets_data['supplier_tax']            = $cluster_details['ticket_tax_value'];
                            $prepaid_tickets_data['supplier_net_price']      = $cluster_details['ticket_net_price'];
                            $prepaid_tickets_data['ticket_amount_before_extra_discount']        = $cluster_details['new_price'];
                            $prepaid_tickets_data['group_linked_with'] = $ticket_detail->group_linked_with;
                            $prepaid_tickets_data['pos_point_id_on_redeem'] = '0';
                            $prepaid_tickets_data['pos_point_name_on_redeem'] = '';
                            $prepaid_tickets_data['distributor_id_on_redeem'] = '0';
                            $prepaid_tickets_data['distributor_cashier_id_on_redeem'] = '0';
                            $prepaid_tickets_data['channel_id']               = $channel_id ;
                            $prepaid_tickets_data['channel_name']             = $channel_name ;
                            $prepaid_tickets_data['saledesk_id']              = $saledesk_id ;
                            $prepaid_tickets_data['saledesk_name']            = $saledesk_name ;
                            $prepaid_tickets_data['extra_discount']           = $extra_discount ;
                            $prepaid_tickets_data['extra_fee']                = $extra_fee ;
                            $prepaid_tickets_data['order_currency_price']     = $order_currency_price ;
                            $prepaid_tickets_data['ticket_scan_price']        = $ticket_scan_price ;
                            $prepaid_tickets_data['supplier_currency_code']   = $supplier_currency_code ;
                            $prepaid_tickets_data['supplier_currency_symbol'] = $supplier_currency_symbol ;
                            $prepaid_tickets_data['order_currency_code']      = $order_currency_code ;
                            $prepaid_tickets_data['order_currency_symbol']    = $order_currency_symbol ;
                            $prepaid_tickets_data['related_product_title']   = $ticket_detail->title;
                            $prepaid_tickets_data['created_at']          = strtotime($created_date_time);
                            $prepaid_tickets_data['order_confirm_date']  = $prepaid_tickets_data['payment_date'] = $prepaid_tickets_data['updated_at'] = $prepaid_tickets_data['visit_date_time'] = $created_date_time;
                            $final_prepaid_tickets_data[] = $prepaid_tickets_data;            
                            
                        }
                    }
                    
                    $visitor_group_no = $prepaid_tickets_data['visitor_group_no'];

                    $created_date = date("Y/m/d");
                    
                    $this->db->query('UPDATE expedia_prepaid_tickets SET action_performed = concat(action_performed, ", UPSELL") WHERE passNo="'.$new_pass_no.'" AND `visitor_group_no` = "'.$visitor_group_no.'" AND `tps_id` = "'.$new_tps_id.'" AND `ticket_id` = "'.$new_ticket_id.'" AND `order_status` = "0" AND `is_refunded` = "0"   LIMIT 1 ');
                    if($is_combi_ticket == "1"){
                        $this->db->query('UPDATE prepaid_tickets SET activated = 0, action_performed = concat(action_performed, ", UPSELL") WHERE `visitor_group_no` = "'.$visitor_group_no.'" AND (passNo="'.$new_pass_no.'" and bleep_pass_no="'.$new_bleep_pass_no.'") AND `tps_id` = "'.$new_tps_id.'" AND `ticket_id` = "'.$new_ticket_id.'" AND `is_refunded` != "1"  AND `activated` = "1" LIMIT 1 ');
                        $insert_pt_db2['activated'] = 0;
                        $insert_pt_db2['CONCAT_VALUE'] = array("action_performed" => ', UPSELL');
                        $insert_pt_where = ' visitor_group_no = "'.$visitor_group_no.'" AND (passNo="'.$new_pass_no.'" and bleep_pass_no="'.$new_bleep_pass_no.'") AND `tps_id` = "'.$new_tps_id.'" AND `ticket_id` = "'.$new_ticket_id.'" AND `is_refunded` != "1"  AND `activated` = "1" ';
                        $insert_vt['ticket_status'] = 0;
                        $insert_vt['CONCAT_VALUE'] = array("action_performed" => ', UPSELL');
                        $insert_vt_where = '  (passNo="'.$new_pass_no.'" and transaction_id = '.$transaction_id.') AND `vt_group_no` = "'.$visitor_group_no.'" AND `ticketpriceschedule_id` = "'.$new_tps_id.'" AND `ticketId` = "'.$new_ticket_id.'" AND `is_refunded` = "0" ';
                    } else {
                        $this->db->query('UPDATE prepaid_tickets SET activated = 0, action_performed = concat(action_performed, ", UPSELL") WHERE `visitor_group_no` = "'.$visitor_group_no.'" AND (passNo="'.$new_pass_no.'" or bleep_pass_no="'.$new_pass_no.'") AND `tps_id` = "'.$new_tps_id.'" AND `ticket_id` = "'.$new_ticket_id.'" AND `is_refunded` != "1"  AND `activated` = "1" LIMIT 1 ');
                        $insert_pt_db2['activated'] = 0;
                        $insert_pt_db2['CONCAT_VALUE'] = array("action_performed" => ', UPSELL');
                        $insert_pt_where = ' visitor_group_no = "'.$visitor_group_no.'" AND (passNo="'.$new_pass_no.'" or bleep_pass_no="'.$new_pass_no.'") AND `tps_id` = "'.$new_tps_id.'" AND `ticket_id` = "'.$new_ticket_id.'" AND `is_refunded` != "1"  AND `activated` = "1" ';
                        $insert_vt['ticket_status'] = 0;
                        $insert_vt['CONCAT_VALUE'] = array("action_performed" => ', UPSELL');
                        $insert_vt_where = ' (passNo="'.$new_pass_no.'") AND `vt_group_no` = "'.$visitor_group_no.'" AND `ticketpriceschedule_id` = "'.$new_tps_id.'" AND `ticketId` = "'.$new_ticket_id.'" AND `is_refunded` != "1" and ticket_status = "1" ';
                    }
                    $logs['db_queries_'.$n.'_'.date('H:i:s')]=$this->db->last_query();
                    $insert_in_pt = array("table" => 'prepaid_tickets', "columns" =>$insert_pt_db2, "where" => $insert_pt_where);
                    $this->set_insert_queries($insert_in_pt);
                    $logs['upsell->pt_data->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                    unset($MPOS_LOGS['get_insert_queries']);
                    $insert_in_vt = array("table" => 'visitor_tickets', "columns" =>$insert_vt, "where" => $insert_vt_where);
                    $this->set_insert_queries($insert_in_vt);
                    $logs['upsell->VT_data->set_insert_queries_'] = $MPOS_LOGS['get_insert_queries'];
                    unset($MPOS_LOGS['get_insert_queries']);
                }
                $update_cashier_register_data['start_amount'] = $start_amount;
                $update_cashier_register_data['price'] = $total_price;
                if($prepaid_tickets_data['channel_type'] == '10' || $prepaid_tickets_data['channel_type'] == '11'){
                    $this->update_cashier_register_data($update_cashier_register_data);
                    $logs['update_cashier_register_data_query_on_upsell' . date('H:i:s')] = $this->db->last_query();
                }
            }    
            //fetch updated version details
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from($pt_table);  
            $this->secondarydb->db->where('visitor_group_no', $visitor_group_no);
            $this->secondarydb->db->where($where_string);
            $result_data = $this->secondarydb->db->get();
            $pt_version_data = $result_data->result_array();
            $logs['query for max version _'] = $this->secondarydb->db->last_query();
            $pt_max_version_data = $this->get_max_version_data($pt_version_data, 'prepaid_ticket_id');
            $pt_final_version_data = array_values($pt_max_version_data);
            $current_date_time =  gmdate("Y-m-d H:i:s"); 
            foreach($final_prepaid_tickets_data as $final_prepaid_ticket_data) {
                $final_prepaid_ticket_data['created_at'] = strtotime($current_date_time);
                $final_prepaid_ticket_data['updated_at'] = $current_date_time;
                $final_prepaid_ticket_data['version'] = (int) $pt_final_version_data[0]['version'] + 1;
                $new_final_prepaid_tickets_data[] = $final_prepaid_ticket_data;
            }
        if(!empty($new_final_prepaid_tickets_data)){
            $aws_data = array();
            $aws_data['add_to_prioticket']          = '1';
            $aws_data['hotel_ticket_overview_data'] = array();
            $aws_data['shop_products_data']         = array();
            $aws_data['prepaid_extra_options_data'] = array();
            $aws_data['prepaid_tickets_data']       = $new_final_prepaid_tickets_data;
            $aws_data['cc_row_data']                = array();
            $logs['data_to_queue_VENUE_TOPIC_ARN_'.date('H:i:s')]=$aws_data;
            $aws_message = base64_encode(gzcompress(json_encode($aws_data)));
            if (SERVER_ENVIRONMENT == 'Local') {
                local_queue($aws_message, 'VENUE_TOPIC_ARN');
            } else {
                $queueUrl = QUEUE_URL;

                // Load SQS library.
                include_once 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new Sqs();
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                if ($MessageId != false) {
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    // Load SQS library.
                    $sns_object->publish($MessageId . '#~#' . $queueUrl);
                }
            }   
        }
        //=============== New Code for insert data in OPD from MPOS If else implemented fully==================
        if($payment_type == 0){
            $pending_payment_id  = $this->common_model->generate_uuid();
            $id_value = $this->common_model->generate_uuid();;
            $paid_order_payment_details = [
                "id" => $id_value,
                "original_payment_id" => $pending_payment_id,
                "group_payment_id" => $id_value,
                "original_group_payment_id" => $pending_payment_id,
                "visitor_group_no" => $visitor_group_no,
                "distributor_id" => $pt_final_version_data[0]['hotel_id'] ? $pt_final_version_data[0]['hotel_id'] : '',
                "reseller_id" => $pt_final_version_data[0]['reseller_id'] ? $pt_final_version_data[0]['reseller_id'] : '',
                "merchant_reference" => isset($pt_final_version_data[0]['merchantReference']) ? $$pt_final_version_data[0]['merchantReference'] : "",
                "psp_merchant_account_id" =>  $visitor_group_no,
                "psp_reference" => (isset($pt_final_version_data[0]['pspReference'])) ? $pt_final_version_data[0]['pspReference'] : "",
                "ticket_booking_id" => $ticket_booking_id,
                "psp_original_reference" => '',
                "currency_rate" => isset($pt_final_version_data[0]['order_currency_rate']) ? $pt_final_version_data[0]['order_currency_rate'] :  '1.0',
                "currency_code" => isset($pt_final_version_data[0]['order_currency_code']) ? $pt_final_version_data[0]['order_currency_code']: '',
                "currency_symbol" => isset($pt_final_version_data[0]['order_currency_symbol']) ? $pt_final_version_data[0]['order_currency_symbol']: '',
                "order_amount" => ($total_price > 0) ? $total_price : 0, 
                "order_total" => 0,
                "amount" => ($total_price > 0) ? $total_price : 0, 
                "total" => 0,
                "method" => '',
                "status" => 2,
                "is_active" => 1,
                "type" => 0,
                "psp_type" => '',
                "settlement_type" => '',
                "cashier_id" => $cashier_id,
                "cashier_name" => $cashier_name,
                "cashier_email" => $cashier_details['uname'],
                "shift_id"   =>  $shift_id,
                "updated_at"=> date("Y-m-d H:i:s"),
                "created_at"=> date("Y-m-d H:i:s"),
                "platform_id" => isset($pt_final_version_data[0]['market_merchant_id']) ? $pt_final_version_data[0]['market_merchant_id']: '',
            ];
            if (!empty($pending_payment_id)) {
                /* prepare pending data for insertion in db */
                $pending_order_payment_details = [
                    "id" => $pending_payment_id,
                    "group_payment_id" => $pending_payment_id,
                    "status" => 2,
                    "visitor_group_no" => $visitor_group_no,
                    "distributor_id" => $pt_final_version_data[0]['hotel_id'] ? $pt_final_version_data[0]['hotel_id'] : '',
                    "reseller_id" => $pt_final_version_data[0]['reseller_id'] ? $pt_final_version_data[0]['reseller_id'] : '',
                    "merchant_reference" => isset($pt_final_version_data[0]['merchantReference']) ? $$pt_final_version_data[0]['merchantReference'] : "",
                    "psp_reference" => '',
                    "psp_merchant_account_id" =>  $visitor_group_no,
                    "psp_original_reference" => '',
                    "ticket_booking_id" => $ticket_booking_id,
                    "currency_rate" => isset($pt_final_version_data[0]['order_currency_rate']) ? $pt_final_version_data[0]['order_currency_rate'] :  '1.0',
                    "currency_code" => isset($pt_final_version_data[0]['order_currency_code']) ? $pt_final_version_data[0]['order_currency_code']: '',
                    "currency_symbol" => isset($pt_final_version_data[0]['order_currency_symbol']) ? $pt_final_version_data[0]['order_currency_symbol']: '',
                    "amount" => ($total_price > 0) ? $total_price : 0, 
                    "order_amount" => ($total_price > 0) ? $total_price : 0, 
                    "total" => '0',
                    "psp_type" => '',
                    "order_total" => '0',
                    "is_active" => 0,
                    "method" => '',
                    "type" => 0,
                    "cashier_id" => $cashier_id,
                    "cashier_name" => $cashier_name,
                    "cashier_email" => $cashier_details['uname'],
                    "shift_id"   =>  $shift_id,
                    "updated_at"=> date("Y-m-d H:i:s"),
                    "created_at"=> date("Y-m-d H:i:s"),
                    "payment_category" =>1,
                    "platform_id" => isset($pt_final_version_data[0]['market_merchant_id']) ? $pt_final_version_data[0]['market_merchant_id']: '',
                ];
                $all_order_payment_details[] = $pending_order_payment_details;
            }
            $all_order_payment_details[] = $paid_order_payment_details;
            $orderupsell = [
                "is_amend_order_version_update" => 1
            ];
            $this->order_process_model->add_payments($all_order_payment_details, 1, $orderupsell);
        } else{
            //=============== Old Code for insert data in OPD from MPOS==================
            // $order_payment_details = array(
            //     'visitor_group_no' => array($visitor_group_no),
            //     "amount" => ($total_price > 0) ? $total_price : 0, 
            //     "total" => ($total_price > 0) ? $total_price : 0,
            //     "status" => 1, //paid
            //     "method" => 2, //cash
            //     "type" => 1, //captured payment
            //     "settlement_type"=> 4, //external
            //     "settled_on" => date("Y-m-d H:i:s"),
            //     "distributor_id" => $hotel_id,
            //     "updated_at"=> date("Y-m-d H:i:s"),
            //     "created_at"=> date("Y-m-d H:i:s")
            // );
            // if(isset($reseller_id) && !empty($reseller_id)) {
            //     $order_payment_details['reseller_id'] = $reseller_id[0]['reseller_id'];
            // }
            // if(isset($distributor_cashier_id_on_redeem) && $distributor_cashier_id_on_redeem != "") {
            //     $order_payment_details['cashier_id'] = $distributor_cashier_id_on_redeem;
            // }
            // $logs['order_payment_details_array'] = $order_payment_details;
            // $this->pos_model->update_payment_detaills($order_payment_details);
            /////============= ENd of Old Code ==============
            // ======New code in case of paid entry ==========
            $method = "";
            $settlement_type = "";
            if ($pt_final_version_data[0]['activation_method'] == 1) { //card
                $method = 1; 
                $settlement_type = 1;
            } else if ($pt_final_version_data[0]['activation_method'] == 2) { //cash
                $method = 2;
                $settlement_type = 4;
            } else if ($pt_final_version_data[0]['activation_method'] == 10) { //voucher
                $method = 11;
                $settlement_type = 4;
            } else if ($pt_final_version_data[0]['activation_method'] == 12) { //external
                $method = 5;
                $settlement_type = 4;
            } else if ($pt_final_version_data[0]['activation_method'] == 3) { //hotel bill 
                $method = 6;
                $settlement_type = 4;
            }  else {
                $method = 5;
                $settlement_type = 4;
            }
            $pending_payment_id  = $this->common_model->generate_uuid();
            $id_value = $this->common_model->generate_uuid();;
            $paid_order_payment_details = [
                "id" => $id_value,
                "original_payment_id" => $pending_payment_id,
                "group_payment_id" => $id_value,
                "original_group_payment_id" => $pending_payment_id,
                "visitor_group_no" => $visitor_group_no,
                "distributor_id" => $pt_final_version_data[0]['hotel_id'] ? $pt_final_version_data[0]['hotel_id'] : '',
                "reseller_id" => $pt_final_version_data[0]['reseller_id'] ? $pt_final_version_data[0]['reseller_id'] : '',
                "merchant_reference" => isset($pt_final_version_data[0]['merchantReference']) ? $$pt_final_version_data[0]['merchantReference'] : "",
                "psp_merchant_account_id" =>  $visitor_group_no,
                "psp_reference" => (isset($pt_final_version_data[0]['pspReference'])) ? $pt_final_version_data[0]['pspReference'] : "",
                "ticket_booking_id" => $ticket_booking_id,
                "psp_original_reference" => '',
                "currency_rate" => isset($pt_final_version_data[0]['order_currency_rate']) ? $pt_final_version_data[0]['order_currency_rate'] :  '1',
                "currency_code" => isset($pt_final_version_data[0]['order_currency_code']) ? $pt_final_version_data[0]['order_currency_code']: '',
                "currency_symbol" => isset($pt_final_version_data[0]['order_currency_symbol']) ? $pt_final_version_data[0]['order_currency_symbol']: '',
                "order_amount" => ($total_price > 0) ? $total_price : 0, 
                "order_total" => ($total_price > 0) ? $total_price : 0, 
                "amount" => ($total_price > 0) ? $total_price : 0, 
                "total" => ($total_price > 0) ? $total_price : 0, 
                "method" => $method,
                "status" => 1,
                "is_active" => 1,
                "type" => 1,
                "psp_type" => '',
                "settlement_type" => $settlement_type,
                "cashier_id" => $cashier_id,
                "cashier_name" => $cashier_name,
                "shift_id"   =>  $shift_id,
                "updated_at"=> date("Y-m-d H:i:s"),
                "created_at"=> date("Y-m-d H:i:s"),
                "payment_category" =>1
            ];
            if(isset($reseller_id) && !empty($reseller_id)) {
                $paid_order_payment_details['reseller_id'] = $reseller_id[0]['reseller_id'];
            }
            if(isset($distributor_cashier_id_on_redeem) && $distributor_cashier_id_on_redeem != "") {
                $paid_order_payment_details['cashier_id'] = $distributor_cashier_id_on_redeem;
            }
            if (!empty($pending_payment_id)) {
                /* prepare pending data for insertion in db */
                $pending_order_payment_details = [
                    "id" => $pending_payment_id,
                    "group_payment_id" => $pending_payment_id,
                    "status" => 2,
                    "visitor_group_no" => $visitor_group_no,
                    "distributor_id" => $pt_final_version_data[0]['hotel_id'] ? $pt_final_version_data[0]['hotel_id'] : '',
                    "reseller_id" => $pt_final_version_data[0]['reseller_id'] ? $pt_final_version_data[0]['reseller_id'] : '',
                    "merchant_reference" => isset($pt_final_version_data[0]['merchantReference']) ? $$pt_final_version_data[0]['merchantReference'] : "",
                    "psp_reference" => '',
                    "psp_merchant_account_id" =>  $visitor_group_no,
                    "psp_original_reference" => '',
                    "ticket_booking_id" => $ticket_booking_id,
                    "currency_rate" => isset($pt_final_version_data[0]['order_currency_rate']) ? $pt_final_version_data[0]['order_currency_rate'] :  '1',
                    "currency_code" => isset($pt_final_version_data[0]['order_currency_code']) ? $pt_final_version_data[0]['order_currency_code']: '',
                    "currency_symbol" => isset($pt_final_version_data[0]['order_currency_symbol']) ? $pt_final_version_data[0]['order_currency_symbol']: '',
                    "amount" => ($total_price > 0) ? $total_price : 0, 
                    "order_amount" => ($total_price > 0) ? $total_price : 0, 
                    "total" => '0',
                    "psp_type" => '',
                    "order_total" => '0',
                    "is_active" => 0,
                    "method" => $method,
                    "settlement_type" => $settlement_type,
                    "type" => 0,
                    "cashier_id" => $cashier_id,
                    "cashier_name" => $cashier_name,
                    "shift_id"   =>  $shift_id,
                    "updated_at"=> date("Y-m-d H:i:s"),
                    "created_at"=> date("Y-m-d H:i:s"),
                    "payment_category" =>1
                ];
                if(isset($reseller_id) && !empty($reseller_id)) {
                    $pending_order_payment_details['reseller_id'] = $reseller_id[0]['reseller_id'];
                }
                if(isset($distributor_cashier_id_on_redeem) && $distributor_cashier_id_on_redeem != "") {
                    $pending_order_payment_details['cashier_id'] = $distributor_cashier_id_on_redeem;
                }
                $all_order_payment_details[] = $pending_order_payment_details;
            }
            $all_order_payment_details[] = $paid_order_payment_details;
            $orderupsell = [
                "is_amend_order_version_update" => 1
            ];
            $this->order_process_model->add_payments($all_order_payment_details, 1, $orderupsell);
        }
        //// ====== end of New Code ============
        $MPOS_LOGS['single_type_upsell_'.date('H:i:s')]=$logs;
        $this->createLog('extended_linked_process.php','getting version for _'.$max_version, array("logs" => json_encode($logs)));  
        return $new_final_prepaid_tickets_data;
    }
    
    function update_deactivate_ticket_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity = 1, $timeslot_type, $old_timezone, $shared_capacity_id, $own_capacity_id){
        $total_updated_quantity = 1;
        $shared_capacity_ids = $shared_capacity_id;
        if(!empty($own_capacity_id) && $own_capacity_id > 0){
            $shared_capacity_ids =  $shared_capacity_id.','.$own_capacity_id;
        }
        $query = 'Update ticket_capacity_v1 set sold = sold - ' . $total_updated_quantity . ', modified = "' . gmdate("Y-m-d H:i:s") . '" where shared_capacity_id in (' . $shared_capacity_ids . ') and date = "' . $selected_date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '"';

        $headers = $this->all_headers_new('update_deactivate_ticket_capacity' , $ticket_id);

        $this->db->query($query);
        try {
            if (!empty($own_capacity_id)) {
                $data = json_encode(array("shared_capacity_id" => $own_capacity_id));
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/deleteticket");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                $getdata = curl_exec($ch);
                curl_close($ch);
                //Firebase Updations
                if (SYNC_WITH_FIREBASE == 1) {
                    /* Update timeslots on Firebase. */
                    /* Fetch timeslots from Redis */
                    $data = json_decode($data);
                    $request = json_encode(array("ticket_id" => $ticket_id, "shared_capacity_id" => $own_capacity_id, "ticket_class" => '2', "from_date" => date("Y-m-d", time()), "to_date" => date('Y-m-d', strtotime('+30 days'))));
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/listcapacity");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    $getdata = curl_exec($ch);
                    curl_close($ch);
                    $available_capacity = json_decode($getdata, true);
                    $standard_hours = array();
                    foreach ($available_capacity['data'] as $hours) {
                        $timeslots = array();
                        foreach ($hours['timeslots'] as $timeslot) {
                            if ($timeslot['adjustment_type'] == 1) {
                                $total_capacity = $timeslot['total_capacity'] + $timeslot['adjustment'];
                            } else if ($timeslot['adjustment_type'] == 2) {
                                $total_capacity = $timeslot['total_capacity'] - $timeslot['adjustment'];
                            } else {
                                $total_capacity = $timeslot['total_capacity'];
                            }
                            if ($timeslot['from_time'] !== '0') {
                                $id = str_replace("-", "", $hours['date']) . str_replace(":" ,"", $timeslot['from_time']) . str_replace(":", "",$timeslot['to_time']) . $own_capacity_id;
                                $timeslots[$timeslot['from_time'].'_'.$timeslot['to_time']] = array(
                                    'slot_id' => (string) $id,
                                    'from_time' => $timeslot['from_time'],
                                    'to_time' => $timeslot['to_time'],
                                    'type' => isset($timeslot['timeslot_type']) ? $timeslot['timeslot_type'] : 'day',
                                    'is_active' => ($timeslot['is_active'] == 1 && $timeslot['is_active_slot'] == 1) ? true : false,
                                    'bookings' => (int) $timeslot['bookings'],
                                    'total_capacity' => (int) $total_capacity,
                                    'blocked' => (int) isset($timeslot['blocked']) ? $timeslot['blocked'] : 0,
                                );
                            }
                        }
                        ksort($timeslots);
                        $timeslots = array_values($timeslots);
                        if (!empty($timeslots)) {
                            $standard_hours[$hours['date']] = array(
                                'timeslots' => $timeslots
                            );
                        }
                    }
                    if (!empty($standard_hours)) {
                        /* Delete availability node on Firebase. */
                        $params = json_encode(array("node" => 'ticket/availabilities/' . $own_capacity_id));

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/delete_details");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        $getdata = curl_exec($ch);
                        curl_close($ch);
                        /* Update availability for tickets on Firebase. */
                        $params = json_encode(array("node" => 'ticket/availabilities/' . $own_capacity_id, 'details' => $standard_hours));
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                        $getdata = curl_exec($ch);
                        curl_close($ch);
                    }
                }
            }
            
            $data = json_encode(array("shared_capacity_id" => $shared_capacity_id));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/deleteticket");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
            $getdata = curl_exec($ch);
            curl_close($ch);
            //Firebase Updations
            if (SYNC_WITH_FIREBASE == 1) {
                /* Update timeslots on Firebase. */
                /* Fetch timeslots from Redis */
                $data = json_decode($data);
                $request = json_encode(array("ticket_id" => $ticket_id, "shared_capacity_id" => $shared_capacity_id, "ticket_class" => '2', "from_date" => date("Y-m-d", time()), "to_date" => date('Y-m-d', strtotime('+30 days'))));
                $this->CreateLog('update_in_db_queries.php', 'extended_linked_ticket_from_css_app->redislist=>', array('jsonreq ' => $request));
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/listcapacity");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                $getdata = curl_exec($ch);
                curl_close($ch);
                $available_capacity = json_decode($getdata, true);
                $standard_hours = array();
                foreach ($available_capacity['data'] as $hours) {
                    $timeslots = array();
                    foreach ($hours['timeslots'] as $timeslot) {
                        if ($timeslot['adjustment_type'] == 1) {
                            $total_capacity = $timeslot['total_capacity'] + $timeslot['adjustment'];
                        } else if ($timeslot['adjustment_type'] == 2) {
                            $total_capacity = $timeslot['total_capacity'] - $timeslot['adjustment'];
                        } else {
                            $total_capacity = $timeslot['total_capacity'];
                        }
                        if ($timeslot['from_time'] !== '0') {
                            $id = str_replace("-", "", $hours['date']) . str_replace(":" ,"", $timeslot['from_time']) . str_replace(":", "",$timeslot['to_time']) . $shared_capacity_id;
                            $timeslots[$timeslot['from_time'].'_'.$timeslot['to_time']] = array(
                                'slot_id' => (string) $id,
                                'from_time' => $timeslot['from_time'],
                                'to_time' => $timeslot['to_time'],
                                'type' => isset($timeslot['timeslot_type']) ? $timeslot['timeslot_type'] : 'day',
                                'is_active' => ($timeslot['is_active'] == 1 && $timeslot['is_active_slot'] == 1) ? true : false,
                                'bookings' => (int) $timeslot['bookings'],
                                'total_capacity' => (int) $total_capacity,
                                'blocked' => (int) isset($timeslot['blocked']) ? $timeslot['blocked'] : 0,
                            );
                        }
                    }
                    ksort($timeslots);
                    $timeslots = array_values($timeslots);
                    if (!empty($timeslots)) {
                        $standard_hours[$hours['date']] = array(
                            'timeslots' => $timeslots
                        );
                    }
                }
                if (!empty($standard_hours)) {
                    /* Delete availability node on Firebase. */
                    $params = json_encode(array("node" => 'ticket/availabilities/' . $shared_capacity_id));

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/delete_details");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    $getdata = curl_exec($ch);
                    curl_close($ch);
                    /* Update availability for tickets on Firebase. */
                    $params = json_encode(array("node" => 'ticket/availabilities/' . $shared_capacity_id, 'details' => $standard_hours));
                    $this->CreateLog('update_in_db_queries.php', 'extended_linked_ticket_from_css_app->firebasesync=>', array('jsonreq ' => $params));
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
        } catch (Exception $e) {

        }        
    }
    
    function update_upsell_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 1, $timeslot_type = '', $timezone, $shared_capacity_id, $own_capacity_id, $museum_id = 0) {
        $selected_date = trim($selected_date);
        $from_time = trim($from_time);
        $to_time = trim($to_time);
        $timezone[0]           = $timezone;       
        if(!empty($own_capacity_id)){
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $own_capacity_id, $museum_id);
        } else { 
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
        }  
        return true;
    }
    
    function update_cashier_register_data_on_cancel($data = array()) {
        if (!empty($data)) {
            $refund_by_cash = $refund_by_card = 0;
            $hotel_id = $data['hotel_id'];
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $pos_point_id = $data['pos_point_id'];
            $start_amount = isset($data['start_amount']) ? $data['start_amount'] : 0;
            $update_cashier_register = '';
            if ($data['activation_method'] == 2 || $data['activation_method'] == 9) { //sale by cash or cancel on split_payment order
                $refund_by_cash = $data['price'];
                if ($refund_by_cash > 0) {
                    $update_cashier_register = 'refunded_cash_amount = refunded_cash_amount + ' . $refund_by_cash . ', is_refunded_by_cash = "1", closing_cash_balance = closing_cash_balance - ' . $refund_by_cash . ', total_closing_balance = total_closing_balance - ' . $refund_by_cash . ' ';
                }
            } else if ($data['activation_method'] == 1 || $data['activation_method'] == 12) { //sale by card or external card
                $refund_by_card = $data['price'];
                if ($refund_by_card > 0) {
                    $update_cashier_register = 'refunded_card_amount = refunded_card_amount + ' . $refund_by_card . ', is_refunded_by_card = "1", closing_card_balance = closing_card_balance - ' . $refund_by_card . ', total_closing_balance = total_closing_balance - ' . $refund_by_card . ' ';
                }
            }
            $where = 'where pos_point_id = "' . $pos_point_id . '" and cashier_id= "' . $cashier_id . '" and date(created_at) = "' . gmdate('Y-m-d') . '" and shift_id= "' . $shift_id . '" and hotel_id= "' . $hotel_id . '"';
            if ($update_cashier_register != '') {
                $update_cashier_register_query = 'update cashier_register set ' . $update_cashier_register . $where;
                $this->db->query($update_cashier_register_query);
                if ($this->db->affected_rows() == '0') {
                    $pos_point_details = $this->common_model->find('pos_points_setting', array('select' => 'location_code, financier_email_address', 'where' => 'pos_point_id = "' . $pos_point_id . '" and hotel_id = "' . $hotel_id . '"'));
                    $total_sale = $sale_by_cash + $sale_by_card + $sale_by_voucher;
                    $insertData = array(
                        "created_at" => gmdate('Y-m-d H:i:s'),
                        "modified_at" => gmdate('Y-m-d H:i:s'),
                        "timezone" => $data['timezone'],
                        "hotel_id" => $hotel_id,
                        "shift_id" => $shift_id,
                        "cashier_id" => $cashier_id,
                        "status" => '1',
                        "pos_point_id" => $pos_point_id,
                        "pos_point_name" => $data['pos_point_name'],
                        "location_code" => $pos_point_details[0]['location_code'],
                        "pos_point_admin_email" => $pos_point_details[0]['financier_email_address'],
                        "hotel_name" => $data['hotel_name'],
                        "cashier_name" => $data['cashier_name'],
                        "reference_id" => time(),
                        "opening_time" => date("H:i:s", strtotime(gmdate('Y-m-d H:i:s'))),
                        "opening_cash_balance" => $start_amount,
                        "refunded_cash_amount" => ($refund_by_cash > 0) ? $refund_by_cash : 0,
                        "is_refunded_by_cash" => ($refund_by_cash > 0) ? 1 : 0,
                        "refunded_card_amount" => ($refund_by_card > 0) ? $refund_by_card : 0,
                        "is_refunded_by_card" => ($refund_by_card > 0) ? 1 : 0,
                        "closing_cash_balance" => ($refund_by_cash > 0) ? $start_amount - $refund_by_cash : 0,
                        "closing_card_balance" => ($refund_by_card > 0) ? $start_amount - $refund_by_card : 0,
                        "total_sale" => 0,
                        "total_closing_balance" => $start_amount - $data['price'],
                    );
                    $this->db->insert('cashier_register', $insertData);
                }
            }
        }
    }

    function update_cashier_register_data($data = array()) {
        if (!empty($data)) {
            $sale_by_cash = $sale_by_card = $sale_by_voucher = 0;
            $hotel_id = $data['hotel_id'];
            $cashier_id = $data['cashier_id'];
            $shift_id = $data['shift_id'];
            $pos_point_id = $data['pos_point_id'];
            $start_amount = isset($data['start_amount']) ? $data['start_amount'] : 0;
            $update_cashier_register = '';
            if ($data['activation_method'] == 2) { //sale by cash
                $sale_by_cash = $data['price'];
                if ($sale_by_cash > 0) {
                    $update_cashier_register = 'sale_by_cash = sale_by_cash + ' . $sale_by_cash . ', total_sale = total_sale + ' . $sale_by_cash . ', closing_cash_balance = closing_cash_balance + ' . $sale_by_cash . ', total_closing_balance = total_closing_balance + ' . $sale_by_cash . ' ';
                }
            } else if ($data['activation_method'] == 1 || $data['activation_method'] == 12) { //sale by card or external card
                $sale_by_card = $data['price'];
                if ($sale_by_card > 0) {
                    $update_cashier_register = 'sale_by_card = sale_by_card + ' . $sale_by_card . ', total_sale = total_sale + ' . $sale_by_card . ', closing_card_balance = closing_card_balance + ' . $sale_by_card . ', total_closing_balance = total_closing_balance + ' . $sale_by_card . ' ';
                }
            } else if ($data['activation_method'] == 10) { //sale by voucher
                $sale_by_voucher = $data['price'];
                if ($sale_by_voucher > 0) {
                    $update_cashier_register = 'sale_by_voucher = sale_by_voucher +' . $sale_by_voucher . ', total_sale = total_sale + ' . $sale_by_voucher . ', total_closing_balance = total_closing_balance + ' . $sale_by_voucher . ' ';
                }
            }
            $where = 'where pos_point_id = "' . $pos_point_id . '" and cashier_id= "' . $cashier_id . '" and date(created_at) = "' . gmdate('Y-m-d') . '" and shift_id= "' . $shift_id . '" and hotel_id= "' . $hotel_id . '"';
            if ($update_cashier_register != '') {
                $update_cashier_register_query = 'update cashier_register set modified_at = "' . gmdate('Y-m-d H:i:s') . '", ' . $update_cashier_register . $where;
                $this->db->query($update_cashier_register_query);
                if ($this->db->affected_rows() == '0') {
                    $pos_point_details = $this->common_model->find('pos_points_setting', array('select' => 'location_code, financier_email_address', 'where' => 'pos_point_id = "' . $pos_point_id . '" and hotel_id = "' . $hotel_id . '"'));
                    $insertData = array(
                        "created_at" => gmdate('Y-m-d H:i:s'),
                        "modified_at" => gmdate('Y-m-d H:i:s'),
                        "timezone" => $data['timeZone'],
                        "hotel_id" => $hotel_id,
                        "shift_id" => $shift_id,
                        "cashier_id" => $cashier_id,
                        "status" => '1',
                        "pos_point_id" => $pos_point_id,
                        "pos_point_name" => $data['pos_point_name'],
                        "location_code" => $pos_point_details[0]['location_code'],
                        "pos_point_admin_email" => $pos_point_details[0]['financier_email_address'],
                        "hotel_name" => $data['hotel_name'],
                        "cashier_name" => $data['cashier_name'],
                        "reference_id" => time(),
                        "opening_time" => date("H:i:s", strtotime(gmdate('Y-m-d H:i:s'))),
                        "opening_cash_balance" => $start_amount,
                        "sale_by_cash" => ($sale_by_cash > 0) ? $sale_by_cash : 0,
                        "sale_by_voucher" => ($sale_by_voucher > 0) ? $sale_by_voucher : 0,
                        "sale_by_card" => ($sale_by_card > 0) ? $sale_by_card : 0,
                        "closing_cash_balance" => ($sale_by_cash > 0) ? $sale_by_cash : 0,
                        "closing_card_balance" => ($sale_by_card > 0) ? $sale_by_card : 0,
                        "total_sale" => $sale_by_cash + $sale_by_card + $sale_by_voucher,
                        "total_closing_balance" => $start_amount + $sale_by_cash + $sale_by_card + $sale_by_voucher,
                    );
                    $this->db->insert('cashier_register', $insertData);
                }
            }
        }
    }
    
    /**
     * @name:unset_columns()
     * @purpose: function to unset db columns for db1 prepaid tickets
     * @createdby: supriya saxena<supriya10.aipl@gmail.com> on 21 april, 2020 
     */
    function unset_columns($prepaid_tickets_array = array()) {
        $db2_pt_columns = array('channel_id', 'channel_name', 'saledesk_id', 'saledesk_name','location','highlights', 'image','oroginal_price', 'order_currency_oroginal_price','order_currency_discount', 'is_discount_in_percent','order_currency_price', 'ticket_scan_price','cc_rows_value', 'order_currency_net_price','ticket_amount_before_extra_discount','extra_fee','order_currency_extra_discount','order_currency_combi_discount_gross_amount','is_discount_code','discount_code_value','discount_code_amount','discount_code_promotion_title', 'service_cost_type', 'service_cost', 'net_service_cost','discount_applied_on_how_many_tickets','refund_quantity', 'created_at', 'shop_category_name','rezgo_id', 'rezgo_ticket_id', 'rezgo_ticket_price', 'group_linked_with','group_id','supplier_currency_code','supplier_currency_symbol', 'order_currency_code','order_currency_symbol','currency_rate', 'selected_quantity', 'min_qty','max_qty', 'booking_type','split_payment_detail','is_pre_selected_ticket','time_based_done','is_voucher','is_iticket_product', 'related_product_title','pos_point_id_on_redeem','pos_point_name_on_redeem','distributor_id_on_redeem','distributor_cashier_id_on_redeem','supplier_original_price', 'supplier_discount','supplier_tax','supplier_net_price','museum_net_fee', 'distributor_net_fee','hgs_net_fee', 'museum_gross_fee','distributor_gross_fee','hgs_gross_fee', 'batch_id','batch_reference','cashier_code','location_code','voucher_updated_by','voucher_updated_by_name','redeem_method', 'order_confirm_date','payment_date', 'refund_note', 'manual_payment_note', 'financial_id','financial_name', 'is_custom_setting','account_number','chart_number','split_card_amount','split_cash_amount','split_voucher_amount','split_direct_payment_amount', 'is_data_moved','last_imported_date','redeem_by_ticket_id','redeem_by_ticket_title','updated_at', 'commission_type', 'barcode_type','payment_term_category','order_cancellation_date','voucher_creation_date','order_updated_cashier_id','order_updated_cashier_name','primary_host_name');
        foreach($prepaid_tickets_array as $key => $value) {
            if(in_array($key ,$db2_pt_columns)) {
                unset($prepaid_tickets_array[$key]);
            }
        }
        return $prepaid_tickets_array;
    }
    

}
