<?php
class Order_process_vt_model extends MY_Model {
    
    /* #region  for construct */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
        $this->load->library('AdyenRecurringPayment');
        $this->load->library('log_library');
        $this->load->model('common_model');
        $this->load->model('multi_currency_model');

        $this->base_url = $this->config->config['base_url'];
        $this->merchant_price_col = 'merchant_price';
        $this->merchant_net_price_col = 'merchant_net_price';
        $this->merchant_tax_id_col = 'merchant_tax_id';
        $this->merchant_currency_code_col = 'merchant_currency_code';
        $this->supplier_tax_id_col = 'supplier_tax_id';
        $this->admin_currency_code_col = 'admin_currency_code';
        $this->subProductArray = array();

    }
    /* #endregion */

    /* #region return_db_object */
    /**
     * return_db_object
     *
     * @param  mixed $is_secondary_db
     * @return void
     */
    function return_db_object($is_secondary_db) {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else if ($is_secondary_db == "5") {
            $db = $this->db; 
        } else {
            $db = $this->primarydb->db;
        }
        return $db;
    }
    /* #endregion return_db_object*/
    
    /* #region To insert confirm shop product data in visitor_ticket.  */
    /**
     * confirm_shop_products
     *
     * @param  mixed $confirm_visitor_data
     * @param  mixed $is_secondary_db
     * @return void
     * @author Taranjeet Singh <taran.intersoft@gmail.com> on Sept 21, 2016
     */
    function confirm_shop_products($confirm_visitor_data, $is_secondary_db = "0") {
        $final_shop_visitor_data = array();
        $db = $this->return_db_object($is_secondary_db);
        $visitor_group_no = $confirm_visitor_data['visitor_group_no'];
        $merchantAccountCode = $confirm_visitor_data['merchantAccountCode'];
        $hotel_id = $confirm_visitor_data['hotel_id'];
        $hotel_name = $confirm_visitor_data['hotel_name'];
        $reseller_id = $confirm_visitor_data['reseller_id'];
        $reseller_name = $confirm_visitor_data['reseller_name'];
        $saledesk_id = $confirm_visitor_data['saledesk_id'];
        $saledesk_name = $confirm_visitor_data['saledesk_name'];
        $cashier_id = $confirm_visitor_data['cashier_id'];
        $cashier_name = $confirm_visitor_data['cashier_name'];
        $manual_note = $confirm_visitor_data['manual_note'];
        $payment_method = $confirm_visitor_data['payment_method'];
        $created_date = gmdate('Y-m-d H:i:s', $confirm_visitor_data['created_date']);
        $visit_date = $confirm_visitor_data['created_date'];
        $visit_date_time = gmdate('Y-m-d H:i:s', $confirm_visitor_data['visit_date_time']);
        $product_title = $confirm_visitor_data['product_title'];
        $price = $confirm_visitor_data['price'];
        $quantity = $confirm_visitor_data['quantity'];
        $tax = number_format((float)$confirm_visitor_data['tax'], 2, '.', '');
        $timezone = $confirm_visitor_data['timezone'];
        $channel_type = $confirm_visitor_data['channel_type'];
        $prepaid_ticket_id = $confirm_visitor_data['prepaid_ticket_id'];
        $is_voucher = $confirm_visitor_data['is_voucher'] ? $confirm_visitor_data['is_voucher'] : 0;
        $discount_applied_on_how_many_tickets = $confirm_visitor_data['discount_applied_on_how_many_tickets'];
        $country_tax_array = $confirm_visitor_data['country_tax_array'];
        $tax_id = $country_tax_array[$tax]['id'];
        $tax_name = $country_tax_array[$tax]['tax_name'];
        // get next transaction id
        $insert_visitor = array();
        $sales_type = 'General sales';
        $transaction_id = $prepaid_ticket_id;        
        $visitor_tickets_id = $prepaid_ticket_id.'01';

        // For Shop product genral sale Row
        $insert_visitor['created_date'] = $created_date;
        $insert_visitor['updated_at'] = $created_date;
        if($merchantAccountCode != '') {
            $insert_visitor['merchantAccountCode'] = $merchantAccountCode;
        }
        $insert_visitor['id'] = $visitor_tickets_id;
        $insert_visitor['transaction_id'] = $transaction_id;
        $insert_visitor['ticketId'] = $confirm_visitor_data['ticket_id'];
        $insert_visitor['partner_id'] = $hotel_id;
        $insert_visitor['hotel_id'] = $hotel_id;
        $insert_visitor['hotel_name'] = $hotel_name;
        $insert_visitor['pos_point_id'] = $confirm_visitor_data['pos_point_id'];
        $insert_visitor['pos_point_name'] = $confirm_visitor_data['pos_point_name'];
        $insert_visitor['channel_id'] = $confirm_visitor_data['channel_id'];
        $insert_visitor['channel_name'] = $confirm_visitor_data['channel_name'];
        $insert_visitor['reseller_id'] = $reseller_id;
        $insert_visitor['reseller_name'] = $reseller_name;
        $insert_visitor['saledesk_id'] = $saledesk_id;
        $insert_visitor['saledesk_name'] = $saledesk_name;
        $insert_visitor['manual_payment_note'] = $manual_note;
        $insert_visitor['cashier_id'] = $cashier_id;
        $insert_visitor['cashier_name'] = $cashier_name;
        $insert_visitor['ticket_title'] = trim(strip_tags($product_title)); // product name
        $insert_visitor['visitor_group_no'] = $visitor_group_no;
        $insert_visitor['vt_group_no'] = $visitor_group_no;
        $insert_visitor['visit_date_time'] = $visit_date_time;
        $insert_visitor['order_confirm_date'] = $visit_date_time;
        $insert_visitor['partner_name'] = $hotel_name; //hotel name
        $insert_visitor['hotel_name'] = $hotel_name; //hotel name
        $insert_visitor['paid'] = '1';
        $insert_visitor['channel_type'] = $channel_type;
        $insert_visitor['tax_id'] = $tax_id;
        $insert_visitor['tax_name'] = $tax_name;
        $insert_visitor['is_shop_product'] = $confirm_visitor_data['product_type'];
        $insert_visitor['used'] = (isset($confirm_visitor_data['used']) && !empty($confirm_visitor_data['used'])) ? $confirm_visitor_data['used'] : 0;

        if ($payment_method == 'cash' || $payment_method == 'voucher') {
            $insert_visitor['payment_method'] = $hotel_name; //hotel name
            $insert_visitor['isBillToHotel'] = '1';
            if ($payment_method == 'voucher') {
                $insert_visitor['activation_method'] = '10';
            } else {
                $insert_visitor['activation_method'] = '2';
            }
            $insert_visitor['invoice_status'] = "0";
        } else {
            $insert_visitor['payment_method'] = 'Adyen'; //hotel name
            $insert_visitor['isBillToHotel'] = '0';
            $insert_visitor['activation_method'] = '1';
            $insert_visitor['invoice_status'] = "5";
        }
        $insert_visitor['shop_category_name'] = $confirm_visitor_data['shop_category_name'];
        $insert_visitor['account_number'] = $confirm_visitor_data['account_number'];
        $insert_visitor['debitor'] = 'Guest';
        $insert_visitor['creditor'] = 'Debit';
        $insert_visitor['tax_value'] = $tax;
        $insert_visitor['timezone'] = $timezone;
        $insert_visitor['transaction_type_name'] = $sales_type;
        $insert_visitor['partner_gross_price'] = $price;        
        $insert_visitor['partner_net_price'] = ($price * 100) / ($tax + 100);
        $insert_visitor['tax_value'] = $tax;
        $insert_visitor['row_type'] = "1";
        $insert_visitor['ticketAmt'] = $price;
        $insert_visitor['manual_payment_note'] = $manual_note;
        $insert_visitor['visit_date'] = $visit_date;
        $insert_visitor['tickettype_name'] = $quantity . 'Pcs';
        $insert_visitor['discount_applied_on_how_many_tickets'] = $discount_applied_on_how_many_tickets;
        $insert_visitor['invoice_status'] = "0";
        $insert_visitor['service_name'] = SERVICE_NAME;
        $insert_visitor['is_prepaid'] = '1';
        $insert_visitor['is_voucher'] = $is_voucher;
        $insert_visitor['isDiscountInPercent'] = isset($confirm_visitor_data['isDiscountInPercent']) ? $confirm_visitor_data['isDiscountInPercent'] : '0';
        $insert_visitor['discount'] = isset($confirm_visitor_data['discount']) ? $confirm_visitor_data['discount'] : '0';
        if (isset($confirm_visitor_data['split_payment']) && !empty($confirm_visitor_data['split_payment'])) {
            $insert_visitor['activation_method'] = '9';
            $insert_visitor['split_card_amount'] = $confirm_visitor_data['split_payment'][0]['card_amount'];
            $insert_visitor['split_cash_amount'] = $confirm_visitor_data['split_payment'][0]['cash_amount'];
            $insert_visitor['split_direct_payment_amount'] = $confirm_visitor_data['split_payment'][0]['direct_amount'];
            $insert_visitor['split_voucher_amount'] = isset($confirm_visitor_data['split_payment'][0]['coupon_amount']) ? $confirm_visitor_data['split_payment'][0]['coupon_amount'] : 0;
        }
        $insert_visitor['col7'] = gmdate('Y-m', strtotime($visit_date_time));
        $insert_visitor['col8'] = gmdate('Y-m-d', strtotime($visit_date_time) + ($timezone * 3600));
        if ($is_secondary_db != "1" && $is_secondary_db != "4") {
            $db->insert("visitor_tickets", $insert_visitor);
        }
        $final_shop_visitor_data[] = $insert_visitor;
        $inserted_id = $visitor_tickets_id;

        // For Shop product credit Row
        $visitor_tickets_id = $prepaid_ticket_id."02";
        $insert_visitor['debitor'] = $hotel_name;
        $insert_visitor['id'] = $visitor_tickets_id;
        $insert_visitor['creditor'] = 'Credit';
        $insert_visitor['row_type'] = "3";
        $insert_visitor['transaction_type_name'] = "Distributor fee";
        $insert_visitor['partner_id'] = $hotel_id;
        $insert_visitor['hotel_name'] = $hotel_name;
        $insert_visitor['hotel_id'] = $hotel_id;
        $insert_visitor['pos_point_id'] = $confirm_visitor_data['pos_point_id'];
        $insert_visitor['pos_point_name'] = $confirm_visitor_data['pos_point_name'];
        $insert_visitor['channel_id'] = $confirm_visitor_data['channel_id'];
        $insert_visitor['channel_name'] = $confirm_visitor_data['channel_name'];
        $insert_visitor['tax_id'] = $tax_id;
        $insert_visitor['tax_name'] = $tax_name;
        if ($is_secondary_db != "1" && $is_secondary_db != "4") {
            $db->insert("visitor_tickets", $insert_visitor);
        }
        $final_shop_visitor_data[] = $insert_visitor;
        
        $response = array();
        $response['final_visitors_data'] = $final_shop_visitor_data;
        $response['inserted_id'] = $inserted_id;
        if ($is_secondary_db == "1" || $is_secondary_db == "4") {
            return $response;
        } else {
            return $inserted_id;
        }

    }
    /* #endregion To insert confirm shop product data in visitor_ticket.*/

    /* #region getInitialPaymentDetail  */
    /**
     * getInitialPaymentDetail
     *
     * @param  mixed $fieldvalue
     * @param  mixed $field
     * @param  mixed $hotel_id
     * @param  mixed $is_secondary_db
     * @param  mixed $visitor_group_no
     * @return void
     */
    function getInitialPaymentDetail($prepaid_tickets_data = array(), $hto_data = array()) {
        $final_hto_data->id = $hto_data['id'];
        $final_hto_data->activation_method= $prepaid_tickets_data['activation_method'];
        $final_hto_data->parentPassNO     = $prepaid_tickets_data['pass_no'];
        $final_hto_data->pspReference     = $prepaid_tickets_data['pspReference'];
        $final_hto_data->paymentStatus    = 1;
        $final_hto_data->authorisedAmtRequestCnt = 0;
        $final_hto_data->card_name        = $hto_data['card_name'];
        $final_hto_data->issuer_country_code  = $hto_data['issuer_country_code'];
        $final_hto_data->redeem_method        = $prepaid_tickets_data['redeem_method'];
        $final_hto_data->paymentMethod        = $hto_data['redeem_method'];
        $final_hto_data->visitor_group_no     = $prepaid_tickets_data['visitor_group_no'];        
        $final_hto_data->roomNo               = $prepaid_tickets_data['without_elo_reference_no'];
        $final_hto_data->guest_names          = $prepaid_tickets_data['guest_names'];
        $final_hto_data->merchantAccountCode  = $prepaid_tickets_data['merchantAccountCode'];
        $final_hto_data->merchantReference    = $prepaid_tickets_data['merchantReference'];
        $final_hto_data->pspReference         = $prepaid_tickets_data['pspReference'];

        return $final_hto_data;
    }
    /* #endregion getInitialPaymentDetail*/

    /* #region To save one ticket recoreds in visitor_tickets table, and update the right pannel amounts.  */
    /**
     * confirmprepaidTicketAtMuseum
     *
     * @param  mixed $confirmarray
     * @param  mixed $is_secondary_db
     * @return void
     */
    function confirmprepaidTicketAtMuseum($confirmarray = array(), $is_secondary_db = 0, $hto_update_for_card = array()) {
        $db = $this->return_db_object($is_secondary_db);
        extract($confirmarray);
        $museum_name_main = $confirmarray['museum_name'];
        $ticket_reseller_id = $confirmarray['ticket_reseller_id'] ?? 0;
        $discount_type = $confirmarray['discount_type'];
        $ticketDetail = (array) $confirmarray['ticketDetail'];
        extract($ticketDetail);
        if (!isset($service_gross)) {
            $service_gross = '0';
            $pertransaction = '0';
            $service_cost_type = '0';
        }
        if (empty($pos_point_id)) {
            $pos_point_id = 0;
        }
        if (empty($pos_point_name)) {
            $pos_point_name = '';
        }
        
        $parentPassNo = $initialPayment->parentPassNo;
        if ($parentPassNo && $parentPassNo != '' || $ticketId == 1218 || $ticketId == 1227 || $ticketId == 1228 || $parentPassNo == 0) {
            // If is_vouchet not 1 then it should be 0.
            if ($confirmarray['is_voucher'] == '1') {
                $confirmarray['is_voucher'] = '1';
            } else {
                $confirmarray['is_voucher'] = '0';
            }
            // check ticket is available ( this condition is also not required )
            if ($is_prepaid == '1') {
                if ($museum_id != RIJKMUSEUM_ID) {
                    if (($prepaid_type == 1 || $prepaid_type == 3) && $is_prioticket == '1' && $check_age > 0) {
                        $confirm_ticket_array['passNo'] = '';
                        $confirm_ticket_array['pass_type'] = '0';
                    } else {
                        $confirm_ticket_array['passNo'] = $passNo;
                        $confirm_ticket_array['pass_type'] = '1';
                    }
                } else {
                    $confirm_ticket_array['pass_type'] = '2';
                    $confirm_ticket_array['passNo'] = $passNo;
                }
            } else {
                $confirm_ticket_array['passNo'] = $passNo;
            }

            $confirm_ticket_array['cashier_register_id'] = isset($cashier_register_id) ? $cashier_register_id : 0;
            $confirm_ticket_array['cluster_net_price'] = $cluster_net_price;
            $confirm_ticket_array['scanned_pass'] = $scanned_pass;
            $confirm_ticket_array['tp_payment_method'] = !empty($tp_payment_method) ? $tp_payment_method : 0;
            $confirm_ticket_array['order_confirm_date'] = !empty($order_confirm_date) ? $order_confirm_date : 0;
            $confirm_ticket_array['payment_date'] = !empty($payment_date) ? $payment_date : 0;
            $confirm_ticket_array['pass_type'] = isset($pass_type) ? $pass_type : $confirm_ticket_array['pass_type'];
            $confirm_ticket_array['created_at'] = $creation_date;
            $confirm_ticket_array['visit_date'] = $visit_date;
            $confirm_ticket_array['clustering_id'] = $clustering_id;
            $confirm_ticket_array['discount_label'] = $discount_label;
            $confirm_ticket_array['distributor_partner_id'] = $confirmarray['distributor_partner_id'];
            $confirm_ticket_array['distributor_partner_name'] = $confirmarray['distributor_partner_name'];
            $confirm_ticket_array['pax'] = $confirmarray['pax'];
            $confirm_ticket_array['capacity'] = $confirmarray['capacity'];
            $confirm_ticket_array['is_prepaid'] = $confirmarray['is_prepaid'];
            $confirm_ticket_array['hotel_id'] = $hotel_id;
            $confirm_ticket_array['hotel_name'] = $hotel_name;
            $confirm_ticket_array['primary_host_name'] = $primary_host_name;
            $confirm_ticket_array['pos_point_id'] = $pos_point_id;
            $confirm_ticket_array['shift_id'] = $shift_id;
            $confirm_ticket_array['pos_point_name'] = $pos_point_name;
            $confirm_ticket_array['is_prioticket'] = $is_prioticket;
            $confirm_ticket_array['is_addon_ticket'] = $is_addon_ticket;
            $confirm_ticket_array['subtotal'] = $subtotal;
            $confirm_ticket_array['museum_id'] = $museum_id;
            $confirm_ticket_array['discount_type'] = $discount_type;
            $confirm_ticket_array['invoice_type'] = $cmpny->invoice_type;
            $confirm_ticket_array['museum_id'] = $museum_id;
            $confirm_ticket_array['channel_type'] = $channel_type;
            $confirm_ticket_array['museum_name'] = $museum_name_main;
            $confirm_ticket_array['ticket_reseller_id'] = $ticket_reseller_id;
            $confirm_ticket_array['is_refunded'] = $is_refunded;
            $confirm_ticket_array['ticketId'] = $ticketId;
            $confirm_ticket_array['ticketAmt'] = $price;
            $confirm_ticket_array['distributor_ticketAmt'] = $order_currency_price;
            $confirm_ticket_array['partner_category_id'] = $partner_category_id;
            $confirm_ticket_array['partner_category_name'] = $partner_category_name;
            $confirm_ticket_array['supplier_currency_code'] = $supplier_currency_code;
            $confirm_ticket_array['supplier_currency_symbol'] = $supplier_currency_symbol;
            $confirm_ticket_array['order_currency_code'] = $order_currency_code;
            $confirm_ticket_array['order_currency_symbol'] = $order_currency_symbol;
            $confirm_ticket_array['currency_rate'] = $currency_rate;
            $confirm_ticket_array['prepaid_ticket_id'] = $prepaid_ticket_id;
            $confirm_ticket_array['new_discount'] = $new_discount;
            $confirm_ticket_array['gross_discount_amount'] = $gross_discount_amount;
            $confirm_ticket_array['net_discount_amount'] = $net_discount_amount;
            $confirm_ticket_array['service_gross'] = $service_gross;
            $confirm_ticket_array['pertransaction'] = $pertransaction;
            $confirm_ticket_array['insertonce'] = $insertonce;
            $confirm_ticket_array['service_cost_type'] = $service_cost_type;
            $confirm_ticket_array['ticketpriceschedule_id'] = $ticketpriceschedule_id;
            $confirm_ticket_array['ticket_title'] = $postingEventTitle;
            $confirm_ticket_array['is_block'] = $isPassActive->is_block;
            $confirm_ticket_array['channel_id'] = $cmpny->channel_id;            
            $confirm_ticket_array['channel_name'] = $cmpny->channel_name;
            if($is_addon_ticket==2){
                $confirm_ticket_array['reseller_id'] = $prepaid_reseller_id;
                $confirm_ticket_array['reseller_name'] = $prepaid_reseller_name;
                $confirm_ticket_array['channel_id'] = $confirmarray['channel_id'];
            }else{
                $confirm_ticket_array['reseller_id'] = $cmpny->reseller_id;
                $confirm_ticket_array['reseller_name'] = $cmpny->reseller_name;
            }
            $confirm_ticket_array['saledesk_id'] = $cmpny->saledesk_id;
            $confirm_ticket_array['saledesk_name'] = $cmpny->saledesk_name;
            $confirm_ticket_array['financial_id'] = $financial_id;
            $confirm_ticket_array['financial_name'] = $financial_name;
            $confirm_ticket_array['museumCommission'] = $museumCommission;
            $confirm_ticket_array['deal_type_free'] = $deal_type_free;
            $confirm_ticket_array['discount'] = isset($confirmarray['discount']) ? $confirmarray['discount'] : '0';
            $confirm_ticket_array['is_discount_in_percent'] = isset($confirmarray['is_discount_in_percent']) ? $confirmarray['is_discount_in_percent'] : ' 0';
            $confirm_ticket_array['prepaid_type'] = $prepaid_type;
            $confirm_ticket_array['saveamount'] = $saveamount;
            $confirm_ticket_array['hotelCommission'] = $hotelCommission;
            $confirm_ticket_array['calculated_hotel_commission'] = $calculated_hotel_commission;
            $confirm_ticket_array['hgsCommission'] = $hgsCommission;
            $confirm_ticket_array['calculated_hgs_commission'] = $calculated_hgs_commission;
            $confirm_ticket_array['isCommissionInPercent'] = $isCommissionInPercent;
            $confirm_ticket_array['museum_tax_id'] = $museum_tax_id;
            $confirm_ticket_array['hotel_tax_id'] = $hotel_tax_id;
            $confirm_ticket_array['hgs_tax_id'] = $hgs_tax_id;
            $confirm_ticket_array['ticket_tax_id'] = $ticket_tax_id;
            $confirm_ticket_array['timeZone'] = $timeZone;
            $confirm_ticket_array['ticketwithdifferentpricing'] = $is_shop_product == 1 ? '' : $ticketwithdifferentpricing;
            $confirm_ticket_array['totalCommission'] = $totalCommission;
            $confirm_ticket_array['museumNetPrice'] = $museumNetPrice;

            $confirm_ticket_array['hotelNetPrice'] = $hotelNetPrice;
            $confirm_ticket_array['hgsnetprice'] = $hgsnetprice;
            $confirm_ticket_array['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
            $confirm_ticket_array['is_combi_discount'] = $is_combi_discount;
            $confirm_ticket_array['combi_discount_gross_amount'] = $combi_discount_gross_amount;
            $confirm_ticket_array['order_currency_combi_discount_gross_amount'] = $order_currency_combi_discount_gross_amount;
            $confirm_ticket_array['voucher_reference'] = $voucher_reference;

            $confirm_ticket_array['totalNetCommission'] = $totalNetCommission;
            $confirm_ticket_array['ticket_net_price'] = $ticket_net_price;
            $confirm_ticket_array['hgs_provider_id'] = $hgs_provider_id;
            $confirm_ticket_array['hgs_provider_name'] = $hgs_provider_name;
            $confirm_ticket_array['initialPayment'] = $initialPayment;
            $confirm_ticket_array['cashier_id'] = $cashier_id;
            $confirm_ticket_array['cashier_name'] = $cashier_name;
            $confirm_ticket_array['resuid'] = $userid;
            $confirm_ticket_array['resfname'] = $fname;
            $confirm_ticket_array['reslname'] = $lname;
            $confirm_ticket_array['paymentMethodType'] = "1";
            $confirm_ticket_array['chkclaimAgainClaim'] = '';
            $confirm_ticket_array['parentPassNo'] = $parentPassNo;
            $confirm_ticket_array['ticketsales'] = $is_prepaid;
            $confirm_ticket_array['is_pre_ordered'] = isset($is_pre_ordered) ? $is_pre_ordered : 0;
            $confirm_ticket_array['order_status'] = isset($order_status) ? $order_status : 0;
            $confirm_ticket_array['ticket_booking_id'] = $ticket_booking_id;
            $confirm_ticket_array['without_elo_reference_no'] = $confirmarray['without_elo_reference_no'];
            $confirm_ticket_array['is_voucher'] = $confirmarray['is_voucher'] == 1 ? $confirmarray['is_voucher'] : '0';
            $confirm_ticket_array['extra_text_field_answer'] = $confirmarray['extra_text_field_answer'];
            $confirm_ticket_array['update_primary_db'] = isset($confirmarray['update_primary_db']) ? $confirmarray['update_primary_db'] : '0';
            $confirm_ticket_array['extra_discount'] = isset($confirmarray['extra_discount']) ? $confirmarray['extra_discount'] : '';
            $confirm_ticket_array['order_currency_extra_discount'] = isset($confirmarray['order_currency_extra_discount']) ? $confirmarray['order_currency_extra_discount'] : '';
            $confirm_ticket_array['booking_information'] = isset($confirmarray['booking_information']) ? $confirmarray['booking_information'] : '';
            $confirm_ticket_array['commission_type'] = isset($confirmarray['commission_type']) ? $confirmarray['commission_type'] : '0';
            $confirm_ticket_array['action_performed'] = !empty($confirmarray['action_performed']) ? $confirmarray['action_performed'] : '0';
            $confirm_ticket_array['updated_at'] = isset($confirmarray['updated_at']) ? $confirmarray['updated_at'] : gmdate("Y-m-d H:i:s");
            $confirm_ticket_array['supplier_gross_price'] = isset($confirmarray['supplier_gross_price']) ? $confirmarray['supplier_gross_price'] : '0:00';
            $confirm_ticket_array['supplier_discount'] = isset($confirmarray['supplier_discount']) ? $confirmarray['supplier_discount'] : '0';
            $confirm_ticket_array['supplier_ticket_amt'] = isset($confirmarray['supplier_ticket_amt']) ? $confirmarray['supplier_ticket_amt'] : '0.00';
            $confirm_ticket_array['supplier_tax_value'] = isset($confirmarray['supplier_tax_value']) ? $confirmarray['supplier_tax_value'] : '0.00';
            $confirm_ticket_array['supplier_net_price'] = isset($confirmarray['supplier_net_price']) ? $confirmarray['supplier_net_price'] : '0.00';

            $confirm_ticket_array['chart_number'] = isset($confirmarray['chart_number']) ? $confirmarray['chart_number'] : '';
            $confirm_ticket_array['account_number'] = !empty($confirmarray['account_number']) ? $confirmarray['account_number'] : '';
            $confirm_ticket_array['transaction_id'] = !empty($confirmarray['transaction_id']) ? $confirmarray['transaction_id'] : '';
            $confirm_ticket_array['voucher_creation_date'] = !empty($confirmarray['voucher_creation_date']) ? $confirmarray['voucher_creation_date'] : '';
            $confirm_ticket_array['tickettype_name'] = !empty($confirmarray['tickettype_name']) ? $confirmarray['tickettype_name'] : '';
            $confirm_ticket_array['commission_json'] = !empty($confirmarray['commission_json']) ? $confirmarray['commission_json'] : '';
	    $confirm_ticket_array['merchant_admin_id'] = !empty($confirmarray['merchant_admin_id']) ? $confirmarray['merchant_admin_id'] : '';
	    $confirm_ticket_array['is_combi'] = !empty($confirmarray['is_combi']) ? $confirmarray['is_combi'] : '0';
            $confirm_ticket_array['merchant_admin_name'] = !empty($confirmarray['merchant_admin_name']) ? $confirmarray['merchant_admin_name'] : '';
            $confirm_ticket_array['ctd_currency'] = !empty($confirmarray['ctd_currency']) ? $confirmarray['ctd_currency'] : '';            
            $confirm_ticket_array['visitor_group_no'] = !empty($confirmarray['visitor_group_no']) ? $confirmarray['visitor_group_no'] : '';            
            $confirm_ticket_array['hotel_reseller_id'] = !empty($confirmarray['hotel_reseller_id']) ? $confirmarray['hotel_reseller_id'] : '';

            $confirm_ticket_array['main_ticket_id'] = !empty($confirmarray['main_ticket_id']) ? $confirmarray['main_ticket_id'] : '';
            
            $confirm_ticket_array['main_tps_id'] = !empty($confirmarray['main_tps_id']) ? $confirmarray['main_tps_id'] : '';
            $confirm_ticket_array['is_iticket_product'] = !empty($confirmarray['is_iticket_product']) ? $confirmarray['is_iticket_product'] : '';
            $confirm_ticket_array['visit_date_time'] = isset($confirmarray['visit_date_time']) ? $confirmarray['visit_date_time'] : '';
            if(isset($confirmarray['version'])) {
                $confirm_ticket_array['version'] = $confirmarray['version'];
            }
            // check is credit card hotel?
            // if Bill to hotel,  And activation was made via credit card. ( this can be removed )
            if ($initialPayment->activation_method == 1 && $channel_type != "10" && $channel_type != "11") {
                $confirm_ticket_array['prepaid_type'] = "1";
            }
            $this->CreateLog('update_visitor_tickets_direct.php', 'card_values', array("hto data" => json_encode($initialPayment), "card_values_from_queue" => json_encode($hto_update_for_card)));

            // check card is authorized			    
            if (!empty($hto_update_for_card) || ($initialPayment->paymentStatus == 1 && ($initialPayment->pspReference != '') && $initialPayment->authorisedAmtRequestCnt == 0)) { // card is authorised                
                $confirm_ticket_array['pspReference'] = $initialPayment->pspReference;
                $confirm_ticket_array['captured'] = 1;
                $confirm_ticket_array['paidStatus'] = 1;
                $confirm_ticket_array['billtohotel'] = "0";
                $confirm_ticket_array['is_block'] = "0";
                $confirm_ticket_array['prepaid'] = "1";
                $confirm_ticket_array['insertedId'] = 0;
                // confirm the ticket
            } else { // bill to hotel
                $confirm_ticket_array['pspReference'] = '';
                $confirm_ticket_array['captured'] = 1;
                $confirm_ticket_array['paidStatus'] = 1;
                $confirm_ticket_array['billtohotel'] = "1";
                $confirm_ticket_array['is_block'] = "0";
                $confirm_ticket_array['voucher_updated_by'] = $voucher_updated_by;
                if(($channel_type!='5' || $channel_type!='10' || $channel_type!='11') && $upsell_order!= 1 ){ //check 
                     $confirm_ticket_array['resuid'] = $userid; 
                }else{
                    $this->CreateLog('reddem_method.php', 'action_performed 1 => '.$reddem_method, array(json_encode($confirmarray)));//check channel
                    $confirm_ticket_array['resuid'] = $userid;
                    $confirm_ticket_array['action_performed'] = $action_performed;
                    $confirm_ticket_array['updated_by_username'] = $fname.' '.$lname;
                    $confirm_ticket_array['voucher_updated_by'] = $userid;
                    $confirm_ticket_array['voucher_updated_by_name'] = $fname.' '.$lname;
                    $confirm_ticket_array['redeem_method'] =(isset($redeem_method) && !empty($redeem_method)) ? $redeem_method : "Voucher";
                     
                }
                $confirm_ticket_array['prepaid'] = "1";
            }
            $confirm_ticket_array['affiliate_data'] = $affiliate_data;
            $confirm_ticket_array['museum_tax_value'] = $museum_tax_value;
            $confirm_ticket_array['museum_tax_name'] = $museum_tax_name;
            $confirm_ticket_array['hotel_tax_value'] = $hotel_tax_value;
            $confirm_ticket_array['hotel_tax_name'] = $hotel_tax_name;
            $confirm_ticket_array['hgs_tax_value'] = $hgs_tax_value;
            $confirm_ticket_array['hgs_tax_name'] = $hgs_tax_name;
            $confirm_ticket_array['ticket_tax_value'] = $ticket_tax_value;
            $confirm_ticket_array['ticket_tax_name'] = $ticket_tax_name;
            $confirm_ticket_array['selected_date'] = $selected_date;
            $confirm_ticket_array['booking_selected_date'] = $booking_selected_date;
            $confirm_ticket_array['from_time'] = $from_time;
            $confirm_ticket_array['to_time'] = $to_time;
            $confirm_ticket_array['slot_type'] = $slot_type;
            /* In case of payment from coupon on lookout then invoice status should be 5 (paid) for general sales entry and 0 for distrubutor entry */
            if (isset($payment_method) && $payment_method == 'coupon') {
                $confirm_ticket_array['payment_method'] = $payment_method;
                $confirm_ticket_array['coupon_code'] = $coupon_code;
            } else {
                $confirm_ticket_array['payment_method'] = '';
            }
            $confirm_ticket_array['discount_code'] = $discount_code;
            $confirm_ticket_array['is_shop_product'] = $is_shop_product;
            $confirm_ticket_array['age_group'] = $age_group;
            $confirm_ticket_array['cluster_ticket_net_price'] = $cluster_ticket_net_price;
            $confirm_ticket_array['group_type_ticket'] = $group_type_ticket;
            $confirm_ticket_array['group_price'] = $group_price;
            $confirm_ticket_array['group_quantity'] = $group_quantity;
            $confirm_ticket_array['group_linked_with'] = $group_linked_with;
            $confirm_ticket_array['tp_payment_method']  = $tp_payment_method;
            $confirm_ticket_array['order_confirm_date'] = !empty($order_confirm_date) ? $order_confirm_date : gmdate('Y-m-d h:i:s');
            $confirm_ticket_array['payment_date'] = !empty($payment_date) ? $payment_date : 0;
            if (isset($used)) {
                $confirm_ticket_array['used'] = $used;
            }
            $confirm_ticket_array['split_payment'] = isset($confirmarray['split_payment']) ? $confirmarray['split_payment'] : '';
            $confirm_ticket_array['uncancel_order'] = $confirmarray['uncancel_order'];
            $confirm_ticket_array['distributor_reseller_id'] = $confirmarray['distributor_reseller_id'];
            $confirm_ticket_array['distributor_reseller_name'] = $confirmarray['distributor_reseller_name'];
            
            /* pass reseller sub catalog ids data further */
            $confirm_ticket_array['ticket_reseller_subcatalog_id'] = $confirmarray['ticket_reseller_subcatalog_id'];
            
            $confirm_ticket_array['market_merchant_id'] = $confirmarray['market_merchant_id'];
            $confirm_ticket_array['tax'] = $confirmarray['tax'];
            $confirm_ticket_array['tax_name'] = $confirmarray['tax_name'];
            $confirm_ticket_array['tax_id'] = $confirmarray['tax_id'];
            $confirm_ticket_array['tax_exception_applied'] = $confirmarray['tax_exception_applied'];

            $confirm_ticket_array['ticket_status'] = $confirmarray['ticket_status'];
            $this->CreateLog('update_visitor_tickets_direct.php', 'confirm_ticket_array', array(json_encode($confirm_ticket_array)));
            $logs['confirm_ticket_array'] = $confirm_ticket_array;
            $confirm_ticket_array['sub_catalog_id'] = $confirmarray['sub_catalog_id'];
            $confirm_ticket_array['node_api_response'] = $confirmarray['node_api_response'];
            $confirm_ticket_array['sale_variation_amount'] = $confirmarray['sale_variation_amount'];
            $confirm_ticket_array['resale_variation_amount'] = $confirmarray['resale_variation_amount'];
            $confirm_ticket_array['markup_price'] = $confirmarray['markup_price'];
            $confirm_ticket_array['is_discount_on_variation'] = $confirmarray['is_discount_on_variation'];
            $confirm_ticket_array['is_commission_on_variation'] = $confirmarray['is_commission_on_variation'];
            $confirm_ticket_array['merchant_currency_code'] = $confirmarray['merchant_currency_code'];
            $confirm_ticket_array['is_commission_applicable_varation'] = $confirmarray['is_commission_applicable_varation'];
            $confirm_ticket_array['distributor_fee_percentage'] = $confirmarray['distributor_fee_percentage'];
            if(isset($cpos_created_date)) {
                $confirm_ticket_array['cpos_created_date'] = $cpos_created_date;
            }            
            $confirm_ticket_array['is_data_moved'] = $confirmarray['is_data_moved'];
            /* case handle for v3.2 API.*/
            if(!empty($confirmarray['action_from'])){
                $confirm_ticket_array['action_from'] = $confirmarray['action_from']; 
            }
            
            if( in_array( $channel_type, array( '5', '10', '11' ) ) ) {
                $confirm_ticket_array['voucher_updated_by'] = $voucher_updated_by;
                $confirm_ticket_array['voucher_updated_by_name'] = $voucher_updated_by_name;
            }
            $confirm_ticket_array['order_updated_cashier_id'] = $confirmarray['order_updated_cashier_id'];
            $confirm_ticket_array['order_updated_cashier_name'] = $confirmarray['order_updated_cashier_name'];
            $confirm_ticket_array['bundle_discount'] = $confirmarray['bundle_discount'];
            $confirm_ticket_array['related_product_id'] =   $confirmarray['related_product_id'];
            $confirm_ticket_array['related_product_tps_id'] =   $confirmarray['related_product_tps_id'];
            $confirm_ticket_array['mec_is_combi'] =   $confirmarray['mec_is_combi'];
            $visitor_response = $this->confirmTicketAtMuseum($confirm_ticket_array, $is_secondary_db, $hto_update_for_card);
            $id = $visitor_response['insertedId'];
            $ticket_tax_id = $visitor_response['ticket_tax_id'];
            $visitor_per_ticket_rows_batch = $visitor_response['visitor_ticket_rows_batch'];
            if ($id > 0) {
                $params['message'] = 'success';
                $params['id'] = $id;
            } else {
                echo "failed";
            }
        }

        if ($params['id'] != '') {
                     
            $mail_content['id'] = $params['id'];
            // Prepare array if notification email id exists for this tickets
            if($is_combi == '2' && in_array($museum_id,WONDER_SUPPLIER_ID) && $msgClaim) {               
                $this->subProductArray[$ticketId] =  $combi_ticket_ids;
            } 
            if ($msgClaim != '' || (!empty($this->subProductArray[$related_product_id]) && $is_addon_ticket == 2)) {
                if ($ticketpriceschedule_id > 0) {
                    $ticket_age_groups = $this->getAgeGroupAndDiscount($ticketpriceschedule_id);
                    $ticket_type = $ticket_age_groups->ticketType;
                    $age_groups = $ticket_age_groups->agefrom . '-' . $ticket_age_groups->ageto. '-' . $ticket_age_groups->ticketType;
                    if ($ticket_type == '1') {
                        $ticket_type = 'Adult(s)';
                    } else if ($ticket_type == '3') {
                        $ticket_type = 'Child(s)';
                    } else {
                        $ticket_type = $ticket_age_groups->ticket_type_label .'(s)';
                    }
                } else {
                    $ticket_type = 'Adult(s)';
                    $age_groups = 'between 1 and 99 years old';
                }
                if((!empty($this->subProductArray[$related_product_id]) && $is_addon_ticket == 2)) {
                    $ticketData  = $this->common_model->getSingleRowFromTable('modeventcontent', array('mec_id' => $related_product_id));
                    $msgClaim = $ticketData->msgClaim;
                    $additional_notification_emails =  $ticketData->additional_notification_emails;
                    $ticket_type = '';
                    $age_groups = '';
                }
                $mail_content['museum_email'] = $msgClaim;
                $mail_content['museum_additional_email'] = $additional_notification_emails;
                $mail_content['booking_email_text'] = !empty($booking_email_text)?$booking_email_text:'';
                $mail_content['ticket_id'] = $ticketId;
                $mail_content['ticket_title'] = $postingEventTitle;
                $mail_content['related_product_id'] = $related_product_id;
                $mail_content['is_reservation'] = $ticketDetail['is_reservation'];
                $mail_content['age_groups'] = array('0' => array('ticket_type' => $ticket_type, 'count' => '1', 'age_group' => $age_groups,'tps_id' => $ticketpriceschedule_id,'pax' => $confirmarray['pax']));
            }
            $mail_content['museum_id'] = $museum_id;
            $mail_content['museum_name'] = $museum_name;
            $mail_content['ticketpriceschedule_id'] = $ticketpriceschedule_id;
            $mail_content['ticket_tax_id'] = $ticket_tax_id;
            $mail_content['ticket_tax_value'] = $ticket_tax_value;
            $mail_content['visitor_per_ticket_rows_batch'] = $visitor_per_ticket_rows_batch;
            $mail_content['ticket_level_commissions'] = $visitor_response['ticket_level_commissions'];
            /* #region to prepare main ticket array for bundle products */
            $mail_content['ticket_level_commissions_bundle'] = $visitor_response['ticket_level_commissions_bundle'];
            $mail_content['ticket_level_commissions_bundle_row'] = $visitor_response['ticket_level_commissions_bundle_row'];
            /* #endregion to prepare main ticket array for bundle products */
            return $mail_content;
        }
    }
    /* #endregion To save one ticket recoreds in visitor_tickets table, and update the right pannel amounts.*/

    /* #region  To get the ticket data as per ticket confirm detail. */
    /**
     * confirmTicketAtMuseum
     *
     * @param  mixed $confirm_ticket_array
     * @param  mixed $is_secondary_db
     * @return void
     * @author Hemant Goel <hemant.intersoft@gmail.com>
     */
   function confirmTicketAtMuseum($confirm_ticket_array, $is_secondary_db = 0, $hto_update_for_card = array())
   {

       $this->CreateLog('update_visitor_tickets_check.php', 'confirm_ticket_array', array(json_encode($confirm_ticket_array)));
        $visitor_ticket_rows_batch = array();
        $db = $this->return_db_object($is_secondary_db);
        if (isset($confirm_ticket_array['extra_discount']) && $confirm_ticket_array['extra_discount'] != '') {
            $extra_discount_array = @unserialize($confirm_ticket_array['extra_discount']);
            if (isset($extra_discount_array['real_discount']) && $extra_discount_array['real_discount'] > 0) {
                $confirm_ticket_array['discount'] = $extra_discount_array['real_discount'];
            }
        }
        $voucher_creation_date = $confirm_ticket_array['voucher_creation_date'];
        $uncancel_order = isset($confirm_ticket_array['uncancel_order']) ? $confirm_ticket_array['uncancel_order'] : 0;
        $update_primary_db = $confirm_ticket_array['update_primary_db'];
        $museum_id = $confirm_ticket_array['museum_id'];
        $museum_name = $confirm_ticket_array['museum_name'];
        $ticket_reseller_id = $confirm_ticket_array['ticket_reseller_id'] ?? 0;
        $passNo = $confirm_ticket_array['passNo'];
        if (isset($confirm_ticket_array['pass_type'])) {
            $pass_type = $confirm_ticket_array['pass_type'];
        } else {
            $pass_type = "0";
        }
        $created_at = isset($confirm_ticket_array['created_at']) ? $confirm_ticket_array['created_at'] : '';
        $visit_date = isset($confirm_ticket_array['visit_date']) ? $confirm_ticket_array['visit_date'] : '';
        $pre_visit_date_time = isset($confirm_ticket_array['visit_date_time']) ? $confirm_ticket_array['visit_date_time'] : '';
        $ticketId = $confirm_ticket_array['ticketId'];
        $ticketAmt = $confirm_ticket_array['ticketAmt'];
        $version = $confirm_ticket_array['version'];
        $voucher_reference = $confirm_ticket_array['voucher_reference'];

        $distributor_ticketAmt = $confirm_ticket_array['distributor_ticketAmt'];
        $discount_from_sales = $confirm_ticket_array['discount']?$confirm_ticket_array['discount']:0;        
        $discount_type = $confirm_ticket_array['discount_type'];
        $new_discount = $confirm_ticket_array['new_discount'];
        $net_discount_amount = $confirm_ticket_array['net_discount_amount'];
        $billtohotel = $confirm_ticket_array['billtohotel'];
        $partner_category_id = $confirm_ticket_array['partner_category_id'];
        $partner_category_name = $confirm_ticket_array['partner_category_name'];
        $is_combi_discount = isset($confirm_ticket_array['is_combi_discount']) ? $confirm_ticket_array['is_combi_discount'] : '0';
        $combi_discount_gross_amount = isset($confirm_ticket_array['combi_discount_gross_amount']) ? $confirm_ticket_array['combi_discount_gross_amount'] : '0';
        $order_currency_combi_discount_gross_amount = isset($confirm_ticket_array['order_currency_combi_discount_gross_amount']) ? $confirm_ticket_array['order_currency_combi_discount_gross_amount'] : '0';

        $pax = $confirm_ticket_array['pax'];
        $ticketpriceschedule_id = $confirm_ticket_array['ticketpriceschedule_id'];
        $paidStatus = $confirm_ticket_array['paidStatus'];
        $pspReference = (!empty($hto_update_for_card) && $hto_update_for_card['psp_reference'] != "") ? $hto_update_for_card['psp_reference'] : $confirm_ticket_array['pspReference'];
        $is_refunded = $confirm_ticket_array['is_refunded'];
        $captured = $confirm_ticket_array['captured'];
        $ticket_title = $confirm_ticket_array['ticket_title'];
        $museumCommission = $confirm_ticket_array['museumCommission'];
        $hotelCommission = $confirm_ticket_array['hotelCommission'];
        $calculated_hotel_commission = $confirm_ticket_array['calculated_hotel_commission'];
        $hgsCommission = $confirm_ticket_array['hgsCommission'];
        $isCommissionInPercent = $confirm_ticket_array['isCommissionInPercent'];
        $museum_tax_id = $confirm_ticket_array['museum_tax_id'];
        $hotel_tax_id = $confirm_ticket_array['hotel_tax_id'];
        $ticket_tax_id = $confirm_ticket_array['ticket_tax_id'];
        $hgs_tax_id = $confirm_ticket_array['hgs_tax_id'];
        $timeZone = $confirm_ticket_array['timeZone'];
        $ticketwithdifferentpricing = $confirm_ticket_array['ticketwithdifferentpricing'];
        $totalCommission = $confirm_ticket_array['totalCommission'];
        $museumNetPrice = $confirm_ticket_array['museumNetPrice'];
        $hotelNetPrice = $confirm_ticket_array['hotelNetPrice'];
        $hgsnetprice = $confirm_ticket_array['hgsnetprice'];
        $totalNetCommission = $confirm_ticket_array['totalNetCommission'];
        $hgs_provider_name = $confirm_ticket_array['hgs_provider_name'];
        $initialPayment = $confirm_ticket_array['initialPayment'];
        $cashier_id = $confirm_ticket_array['cashier_id'];
        $cashier_name = $confirm_ticket_array['cashier_name'];
        $reseller_id = $confirm_ticket_array['reseller_id'];
        $reseller_name = $confirm_ticket_array['reseller_name'];
        $saledesk_id = $confirm_ticket_array['saledesk_id'];
        $saledesk_name = $confirm_ticket_array['saledesk_name'];
        $resuid = $confirm_ticket_array['resuid'];
        $resfname = $confirm_ticket_array['resfname'];
        $reslname = $confirm_ticket_array['reslname'];
        $deal_type_free = $confirm_ticket_array['deal_type_free'];
        $discount1 = $confirm_ticket_array['discount'];
        $saveamount = $confirm_ticket_array['saveamount'];
        $ticketPrice = $confirm_ticket_array['ticketPrice'];
        $is_block = $confirm_ticket_array['is_block'];
        $scanned_pass = $confirm_ticket_array['scanned_pass'];
        $cashier_register_id = $confirm_ticket_array['cashier_register_id'];
        $groupTransactionId = $confirm_ticket_array['groupTransactionId'];
        $selected_date = $confirm_ticket_array['selected_date'];
        $booking_selected_date = $confirm_ticket_array['booking_selected_date'];
        $from_time = $confirm_ticket_array['from_time'];
        $to_time = $confirm_ticket_array['to_time'];
        $slot_type = $confirm_ticket_array['slot_type'];
        $is_shop_product = $confirm_ticket_array['is_shop_product'];
        $is_prioticket = isset($confirm_ticket_array['is_prioticket']) ? $confirm_ticket_array['is_prioticket'] : '1';
        $is_pre_ordered = $confirm_ticket_array['is_pre_ordered'];
        $order_status = $confirm_ticket_array['order_status'];
        $ticket_booking_id = $confirm_ticket_array['ticket_booking_id'];
        $is_voucher = $confirm_ticket_array['is_voucher'];
        $without_elo_reference_no = $confirm_ticket_array['without_elo_reference_no'];
        $extra_text_field_answer = $confirm_ticket_array['extra_text_field_answer'];
        $prepaid_ticket_id = $confirm_ticket_array['prepaid_ticket_id'];
        $merchant_fees_assigned = '0';
        $group_type_ticket = $confirm_ticket_array['group_type_ticket'];
        $group_price = $confirm_ticket_array['group_price'];
        $group_quantity = $confirm_ticket_array['group_quantity'];
        $group_linked_with = $confirm_ticket_array['group_linked_with'];
        $extra_discount = $confirm_ticket_array['extra_discount'];
        $distributor_partner_id = $confirm_ticket_array['distributor_partner_id'];
        $distributor_partner_name = $confirm_ticket_array['distributor_partner_name'];
        $is_prepaid = $confirm_ticket_array['is_prepaid'];
        $transaction_id = $confirm_ticket_array['transaction_id'];
        $tp_payment_method  = $confirm_ticket_array['tp_payment_method'];
        $order_confirm_date = $confirm_ticket_array['order_confirm_date'];
        $ticket_type_name = $confirm_ticket_array['tickettype_name'];
        $merchant_admin_id = $confirm_ticket_array['merchant_admin_id'];
        $is_combi = $confirm_ticket_array['is_combi'];
        $merchant_admin_name = $confirm_ticket_array['merchant_admin_name'];
        $ctd_currency = $confirm_ticket_array['ctd_currency'];
        $visitor_group_no = $confirm_ticket_array['visitor_group_no'];
        $hotel_reseller_id = $confirm_ticket_array['hotel_reseller_id'];
        $main_ticket_id = $confirm_ticket_array['main_ticket_id'];
        $main_tps_id = $confirm_ticket_array['main_tps_id'];
        $is_iticket_product = $confirm_ticket_array['is_iticket_product'];
        $market_merchant_id = $confirm_ticket_array['market_merchant_id'];
        $sub_catalog_id = $confirm_ticket_array['sub_catalog_id'];
        $node_api_response = isset($confirm_ticket_array['node_api_response'])? $confirm_ticket_array['node_api_response']: 0;
        $ticket_status = $confirm_ticket_array['ticket_status'];
        $merchant_currency_code = $confirm_ticket_array['merchant_currency_code'];
        $voucher_updated_by = ( isset( $confirm_ticket_array['voucher_updated_by'] )? $confirm_ticket_array['voucher_updated_by']: '' );
        $voucher_updated_by_name = ( isset( $confirm_ticket_array['voucher_updated_by_name'] )? $confirm_ticket_array['voucher_updated_by_name']: '' );
        $mposChannelTypes = array('5', '10', '11');
        $sale_variation_amount = $confirm_ticket_array['sale_variation_amount'];
        $is_discount_on_variation = $confirm_ticket_array['is_discount_on_variation'];
        $is_commission_on_variation = $confirm_ticket_array['is_commission_on_variation'];
        $order_updated_cashier_id = $confirm_ticket_array['order_updated_cashier_id'];
        $order_updated_cashier_name = $confirm_ticket_array['order_updated_cashier_name'];
        $redeem_method = ($confirm_ticket_array['redeem_method']) ? $confirm_ticket_array['redeem_method'] : '';
        
        
        $resale_variation_amount = $confirm_ticket_array['resale_variation_amount'];
        $markup_price = $confirm_ticket_array['markup_price'];
        $is_commission_applicable_varation = $confirm_ticket_array['is_commission_applicable_varation'];        
        $distributor_fee_percentage = $confirm_ticket_array['distributor_fee_percentage'];
        $bundle_discount    =   $confirm_ticket_array['bundle_discount'];
        $related_product_id    =   $confirm_ticket_array['related_product_id'];
        $related_product_tps_id =   $confirm_ticket_array['related_product_tps_id'];
        $mec_is_combi =   $confirm_ticket_array['mec_is_combi'];
        // $ticketAmt += $sale_variation_amount;
        
        $pricing_level = 1; // TPS prices applied
        $commission_level = 1;
        $payment_date =  !empty($confirm_ticket_array['payment_date']) ? $confirm_ticket_array['payment_date'] : ''; 
        if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
            $split_cash_amount = $confirm_ticket_array['split_payment'][0]['cash_amount'];
            $split_card_amount = $confirm_ticket_array['split_payment'][0]['card_amount'];
            $split_direct_amount = $confirm_ticket_array['split_payment'][0]['direct_amount'];
            $split_voucher_amount = isset($confirm_ticket_array['split_payment'][0]['coupon_amount']) ? $confirm_ticket_array['split_payment'][0]['coupon_amount'] : 0;
        }
        $card_name = $initialPayment->card_name;
        $country = $initialPayment->issuer_country_code;
        $userdata = $initialPayment;
        $hotel_id = $confirm_ticket_array['hotel_id'];
        $hotel_name = $confirm_ticket_array['hotel_name'];
        $primary_host_name = $confirm_ticket_array['primary_host_name'];
        $pos_point_id = $confirm_ticket_array['pos_point_id'];
        $shift_id = $confirm_ticket_array['shift_id'];
        $pos_point_name = $confirm_ticket_array['pos_point_name'];
        $hto_id = $userdata->id;
        $paymentMethod = $userdata->paymentMethod;
        $channel_type = $confirm_ticket_array['channel_type'];

        if($redeem_method == '') {
            $redeem_method = (isset($confirm_ticket_array['initialPayment']->redeem_method) && !empty($confirm_ticket_array['initialPayment']->redeem_method)) ? $confirm_ticket_array['initialPayment']->redeem_method : 'Voucher';
        }
        $booking_status = ($channel_type == 2) ? $is_pre_ordered == 1 ? 1 : 0 : 1;
        
        if (isset($confirm_ticket_array['booking_information']) && $confirm_ticket_array['booking_information'] != '') {
            $booking_information = unserialize($confirm_ticket_array['booking_information']);
            // do not used hotel model 'find' function query beacuse records are not coming if having multiple spaces in booking name. 
            $qery = 'select id from nav_customers where name like "' . addslashes($booking_information['booking_name']) . '" and distributer_id="' . $hotel_id . '"';
            $result = $this->primarydb->db->query($qery);
            if ($result->num_rows() > 0) {
                $result = $result->row();
                $booking_id = $result->id;
            }
            $booking_name = $booking_information['booking_name'];
        }
        $targetcity = '';
        $is_addon_ticket = $confirm_ticket_array['is_addon_ticket'];
        // New initialization introduced on Oct 26, by Hemant Goel
        $subtotal_with_combi_discount = 0;
        $hotel_gross_price_without_combi_discount = 0;
        $hotel_net_price_without_combi_discount = 0;
        $hgs_gross_price_without_combi_discount = 0;
        $hgs_net_price_without_combi_discount = 0;
        $current_season_tps_id = 0;
        if($channel_type == '2' || $channel_type == '5'){
            $current_season_tps_id = $this->get_current_season_tps_id($ticketId,$ticket_type_name);
        }
        
        $extra_net_discount = 0;
        $extra_gross_discount = 0;
        $order_currency_extra_net_discount = 0;
        $order_currency_extra_gross_discount = 0;
        if (isset($confirm_ticket_array['extra_discount']) && $confirm_ticket_array['extra_discount'] != '') {
            $extra_discount_array = @unserialize($confirm_ticket_array['extra_discount']);
            if (isset($extra_discount_array['gross_discount_amount']) && $extra_discount_array['gross_discount_amount'] > 0) {
                $extra_gross_discount = round($extra_discount_array['gross_discount_amount'], 2);
            }
            if (isset($extra_discount_array['net_discount_amount']) && $extra_discount_array['net_discount_amount'] > 0) {
                $extra_net_discount = round($extra_discount_array['net_discount_amount'], 2);
            }
        }
        if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id != 0) {
            if(!empty($current_season_tps_id)){
                $discountandage = $this->getAgeGroupAndDiscount($current_season_tps_id);
            } else {
                $discountandage = $this->getAgeGroupAndDiscount($ticketpriceschedule_id);
            }
            if ($is_addon_ticket == 2 && !empty($main_tps_id)) {
                $main_ticket_tps_data = $this->getAgeGroupAndDiscount($main_tps_id);
                $ctd_clc_supplier_prices['currency_code'] = $main_ticket_tps_data->currency_code;
                $ctd_clc_market_merchant_prices['currency_code'] = $main_ticket_tps_data->currency_code;
            }
            $ticketPrice = $discountandage->pricetext;
            $ageGroup = $discountandage->agefrom . '-' . $discountandage->ageto;
            $totalCommission = $discountandage->totalCommission;
            $totalNetCommission = $discountandage->totalNetCommission;
            $museumCommission = $discountandage->museumCommission;
            $hotelCommission = $discountandage->hotelCommission;
            $hgsCommission = $discountandage->hgsCommission;
            $calculated_hotel_commission = $discountandage->calculated_hotel_commission;
            $isCommissionInPercent = $discountandage->isCommissionInPercent;
            if ($confirm_ticket_array['tax_exception_applied'] == '1') {
                $museum_tax_id = $confirm_ticket_array['tax_id'];
                $hotel_tax_id = $confirm_ticket_array['tax_id'];
                $hgs_tax_id = $confirm_ticket_array['tax_id'];
                $ticket_tax_id = $confirm_ticket_array['tax_id'];
            } else {
                $museum_tax_id = $discountandage->museum_tax_id;
                $hotel_tax_id = $discountandage->hotel_tax_id;
                $hgs_tax_id = $discountandage->hgs_tax_id;
                $ticket_tax_id = $discountandage->ticket_tax_id;
            }
            $museumNetPrice = $discountandage->museumNetPrice;
            $hotelNetPrice = $discountandage->hotelNetPrice;
            $supplier_hotelNetPrice = $discountandage->hotelNetPrice;
            $hgsnetprice = $discountandage->hgsnetprice;
            $supplier_hgsnetprice = $discountandage->hgsnetprice;
            $ticket_net_price = $discountandage->ticket_net_price;
            $hgs_provider_id = $discountandage->hgs_provider_id;
            $hgs_provider_name = $discountandage->hgs_provider_name;
            if ($is_different_currency == 1) {
                $currency_ticketPrice = $discountandage->pricetext * $currency_rate;
                $currency_museumCommission = $discountandage->museumCommission * $currency_rate;
                $currency_museumNetPrice = $discountandage->museumNetPrice * $currency_rate;
                $currency_hotelNetPrice = $discountandage->hotelNetPrice * $currency_rate;
                $currency_calculated_hotel_commission = $discountandage->calculated_hotel_commission * $currency_rate;
                $currency_hgsnetprice = $discountandage->hgsnetprice * $currency_rate;
            }
            // get ticket partners
            $partnersData = $this->cityadminpartnercommission($ticketpriceschedule_id, $ticketwithdifferentpricing);
        } else {
            if ($ticketTypeDetail->agefrom != '') {
                $ticketPrice = $ticketTypeDetail->pricetext;
                $ageGroup = $ticketTypeDetail->agefrom . '-' . $ticketTypeDetail->ageto;
            } 
            else {
                $ageGroup = '0';
            }
            if ($is_different_currency == 1) {
                $currency_ticketPrice = $ticketTypeDetail->pricetext * $currency_rate;
            }
            // get ticket partners
            $partnersData = $this->cityadminpartnercommission($ticketId, $ticketwithdifferentpricing);
        }
        $code_discount_tax_value = 0;
        $tax_array_for_discount = $this->common_model->selectPartnertaxes('0', SERVICE_NAME, $hotel_id, array($hotel_tax_id, $ticket_tax_id, $hgs_tax_id, $museum_tax_id));
        if ($confirm_ticket_array['tax_exception_applied'] == '1') {
            $hotel_tax_value = $confirm_ticket_array['tax'];
            $supplier_hotel_tax_value = $confirm_ticket_array['tax'];
            $ticket_tax_value = $confirm_ticket_array['tax'];
            $code_discount_tax_value = $confirm_ticket_array['tax'];
            $supplier_museum_tax_value = $confirm_ticket_array['tax'];
            $supplier_hgs_tax_value = $confirm_ticket_array['tax'];
            $hotel_tax_name = $confirm_ticket_array['tax_name'];
            $ticket_tax_name = $confirm_ticket_array['tax_name'];
        } else {
            foreach ($tax_array_for_discount as $tax) {
                if ($tax->tax_id == $hotel_tax_id || $tax->id == $hotel_tax_id) {
                    $hotel_tax_value = $tax->tax_value;
                    $hotel_tax_name = $tax->tax_name;
                    $supplier_hotel_tax_value = $tax->tax_value;
                }
                if ($tax->tax_id == $ticket_tax_id || $tax->id == $ticket_tax_id) {
                    $ticket_tax_value = $tax->tax_value;
                    $ticket_tax_name = $tax->tax_name;
                    $code_discount_tax_value = $ticket_tax_value;
                }
                if ($tax->tax_id == $museum_tax_id || $tax->id == $museum_tax_id) {
                    $supplier_museum_tax_value = $tax->tax_value;
                }
                if ($tax->tax_id == $hgs_tax_id || $tax->id == $hgs_tax_id) {
                    $supplier_hgs_tax_value = $tax->tax_value;
                }
            }
            /* #endregion to get taxes name from tax id of PT */
        }
        //get channel id
        $channel_id = $confirm_ticket_array['channel_id'];
        $distributor_reseller_id = $confirm_ticket_array['distributor_reseller_id'];
        $ticket_reseller_subcatalog_id = $confirm_ticket_array['ticket_reseller_subcatalog_id'];
        // Get Ticket level/Hotel level commissions values
        $market_merchant_prices = array();
        $supplier_prices = array();
        $all_commissions = array();
        $all_commissions_bundle = array();
        $combi_clc_supplier_prices = array();
        $combi_ticket_result = array();
        if ($confirm_ticket_array['is_addon_ticket'] != "2") {
            /* fetch prices of main ticket in case of bundle products */   
            if( $mec_is_combi == '4' ){
                $ticketId                   =   $related_product_id;
                $ticketpriceschedule_id     =   $related_product_tps_id;
                /* #region to get commission from tlc/clc/subcatalog for main ticket in case of bundle products  */
                $all_commissions_bundle = $this->get_ticket_hotel_level_commission_bundle($hotel_id, $ticketId, $ticketpriceschedule_id, $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, '', ($ticket_reseller_id ?? 0));
                $ticket_level_commissions_bundle = $ticket_level_commissions_bundle_row = $all_commissions_bundle['result'];
                $this->CreateLog('multi_currency_bundle.php', 'step1', array("result" => json_encode($all_commissions_bundle)));
            }
            /* Overwrite sub ticket_id and tps_id in case of budnle products */
            $ticketId   =   $confirm_ticket_array['ticketId'];
            $ticketpriceschedule_id =   $confirm_ticket_array['ticketpriceschedule_id'];
            $all_commissions = $this->get_ticket_hotel_level_commission($hotel_id, $ticketId, $ticketpriceschedule_id, $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, '', ($ticket_reseller_id ?? 0));
            $ticket_level_commissions = $all_commissions['result'];
            /* set value of subtotal net amount of tickets */ 
            $subtotal_net_amount    =   $ticket_level_commissions->subtotal_net_amount;
            $this->CreateLog('multi_currency.php', 'step1', array("result" => json_encode($all_commissions)));
            if (isset($all_commissions['supplier_merchant_data']) && !empty($all_commissions['supplier_merchant_data'])) {
                $supplier_merchant_commissions = $this->multi_currency_model->get_supplier_merchant_commission($all_commissions['supplier_merchant_data']);
                $this->CreateLog('multi_currency.php', 'step2', array("result" => json_encode($supplier_merchant_commissions)));
                $supplier_prices = $supplier_merchant_commissions['supplier_prices'];
                $market_merchant_prices = $supplier_merchant_commissions['market_merchant_prices'];
                $admin_prices = $supplier_merchant_commissions['admin_prices'];
            }
            /* #endregion to get commission from tlc/clc/subcatalog for main ticket */
        } else if(!empty($main_tps_id)){
            // get currency data of main ticket 
            /* #region to get main ticket data for subtickets from tlc/clc/subcatalog  */
            $all_commissions = $this->get_ticket_hotel_level_commission($hotel_id, $main_ticket_id, $main_tps_id,  $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, '', ($ticket_reseller_id ?? 0));

            if (isset($all_commissions['supplier_merchant_data']) && !empty($all_commissions['supplier_merchant_data'])) {
                
                $supplier_merchant_commissions = $this->multi_currency_model->get_supplier_merchant_commission($all_commissions['supplier_merchant_data'], 2);
                $ctd_clc_supplier_prices = $supplier_merchant_commissions['supplier_prices'];
                $ctd_clc_market_merchant_prices = $supplier_merchant_commissions['market_merchant_prices'];
                $ctd_clc_admin_prices = $supplier_merchant_commissions['admin_prices'];
            }
            /* #endregion to get main ticket data for subtickets from tlc/clc/subcatalog  */
        }
        $variation_discount = 0;
        if ($is_discount_on_variation == 1) {
            $variation_discount = $sale_variation_amount * $ticket_level_commissions->ticket_discount/ 100;
            $sale_variation_amount -= $variation_discount;
        }
        if ($is_commission_on_variation == 1) {
            $ticketAmt -= $variation_discount;
            $sale_variation_amount = 0;
        } else {
            $ticketAmt -= $sale_variation_amount;
        }
        $resale_net_variation_amount = round(($resale_variation_amount * 100) / ($ticket_tax_value + 100),2);
        $sale_net_variation_amount = round(($sale_variation_amount * 100) / ($ticket_tax_value + 100),2);
        
        if ($ticketpriceschedule_id != '') {
            if(!empty($current_season_tps_id)){
                $ticketTypeDetail = $this->getTicketTypeFromTicketpriceschedule_id($current_season_tps_id);
            } else {
                $ticketTypeDetail = $this->getTicketTypeFromTicketpriceschedule_id($ticketpriceschedule_id);
            }
            $this->CreateLog('combi_ticket_prices.php', 'step1', array("ticketTypeDetail " => json_encode($ticketTypeDetail), "is_combi" => $is_combi));
            $discount_from_sales_net = round((($discount_from_sales * 100) / (100 + $ticketTypeDetail->ticket_tax_value)), 2);
            $ticketType = $ticketTypeDetail->ticketType;
            if ($ticketTypeDetail->parent_ticket_type == 'Custom') {
                $tickettype_name = $ticketTypeDetail->ticket_type_label;
            } else {
                $tickettype_name = $ticketTypeDetail->tickettype_name;
            }
            $ticketTypeDetail->subtotal = round(($ticketAmt * 100) / ($ticket_tax_value + 100) + $extra_net_discount,2) - $ticketTypeDetail->museumNetPrice - round(($combi_discount_gross_amount * 100) / ($ticket_tax_value + 100), 2) - $confirm_ticket_array['cluster_net_price'];
            $this->CreateLog('combi_ticket_prices.php', 'step2', array("subtotal " => $ticketTypeDetail->subtotal, "is_combi" => "combi != 2"));
            
            if($ticketTypeDetail->subtotal > 0){
                $subtotal = $ticketTypeDetail->subtotal;
                $original_subtotal = $ticketTypeDetail->subtotal;   
                $debugLogs['CASE_13 subtotal = ticketTypeDetail->subtotal'] = $subtotal;  
            } else  {
                $subtotal = $original_subtotal = 0;
                $debugLogs['CASE_12 subtotal = 0'] = $subtotal;
            }
            
        } else {
            $ticketType = '0';
            $tickettype_name = '';
            $subtotal = $confirm_ticket_array['subtotal'];
            $original_subtotal = $confirm_ticket_array['subtotal'];
        }
        $is_different_currency = 0;
        $currency_rate = 1;
        if (strtolower($confirm_ticket_array['supplier_currency_symbol']) != strtolower($confirm_ticket_array['order_currency_symbol'])) {
            $is_different_currency = 1;
            $currency_rate = isset($confirm_ticket_array['currency_rate']) ? $confirm_ticket_array['currency_rate'] : 1;
        }
        if ($is_different_currency == 1) {
            $currency_subtotal = $subtotal * $currency_rate;
            $currency_original_subtotal = $original_subtotal * $currency_rate;
        } else {
            $currency_subtotal = $subtotal;
            $currency_original_subtotal = $original_subtotal;
        }
        
        if (isset($confirm_ticket_array['order_currency_extra_discount']) && $confirm_ticket_array['order_currency_extra_discount'] != '') {
            $order_currency_extra_discount_array = unserialize(stripslashes($confirm_ticket_array['order_currency_extra_discount']));
            if (isset($order_currency_extra_discount_array['gross_discount_amount']) && $order_currency_extra_discount_array['gross_discount_amount'] > 0) {
                $order_currency_extra_gross_discount = $order_currency_extra_discount_array['gross_discount_amount'];
            }
            if (isset($order_currency_extra_discount_array['net_discount_amount']) && $order_currency_extra_discount_array['net_discount_amount'] > 0) {
                $order_currency_extra_net_discount = $order_currency_extra_discount_array['net_discount_amount'];
            }
        }
        if (isset($order_currency_extra_discount_array) && !empty($order_currency_extra_discount_array)) {
            $currency_new_discount = $order_currency_extra_discount_array['new_discount'];
            $currency_net_discount_amount = $order_currency_extra_discount_array['net_discount_amount'];
            $currency_gross_discount_amount = $order_currency_extra_discount_array['gross_discount_amount'];
        } else {
            $currency_new_discount = 0;
            $currency_net_discount_amount = 0;
        }
        if ($currency_new_discount > 0) {
            $currency_subtotal = $currency_subtotal - $currency_net_discount_amount;
        }
        $ispercenttype = '0';
        //In case of cluster tickets we have to use ticket amount as museum commision and net price and museum net price
        if ($confirm_ticket_array['is_addon_ticket'] == "2") {
            $museumCommission = $confirm_ticket_array['ticketAmt'];
            $supplier_museumCommission = $confirm_ticket_array['ticketAmt'];
            $ticketPrice = $confirm_ticket_array['ticketAmt'];
            $currency_ticketPrice = $confirm_ticket_array['distributor_ticketAmt'];
            $museumNetPrice = $confirm_ticket_array['cluster_ticket_net_price'];
            $supplier_museumNetPrice = $confirm_ticket_array['cluster_ticket_net_price'];
        }
        $this->CreateLog('visitor_info.php', 'museum commission', array("data10 " => $museumCommission, "data11" => $museumNetPrice));
        $amount_to_deduct_from_museum_comission = 0;
        $discount_amnt = 0;
        $promocode = array();
        if (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0) {

            $promocode = $this->common_model->find('coupons', array('select' => 'LCASE(coupon_code) as coupon_code, discount_amount,is_discount_per_ticket,discount_type', 'where' => 'discount_amount > 0 and LCASE(coupon_code) = "' . strtolower($confirm_ticket_array['discount_code']) . '"'));
            if (isset($promocode[0]['coupon_code']) && $promocode[0]['discount_amount'] != '') {
                $discount_amnt = $promocode[0]['discount_amount'];
            }

            $discount_net_amnt = $discount_amnt - (($code_discount_tax_value * $discount_amnt) / ($code_discount_tax_value + 100));
            if (!($subtotal >= 0 && $subtotal < $discount_net_amnt)) {
                $subtotal = $subtotal - $discount_net_amnt;
                $debugLogs['CASE_11 subtotal - discount_net_amnt'] = $subtotal;
            }
        }

        //get channel id
        $channel_id = $confirm_ticket_array['channel_id'];

        
        $distributor_reseller_id = $confirm_ticket_array['distributor_reseller_id'];
        $ticket_reseller_subcatalog_id = $confirm_ticket_array['ticket_reseller_subcatalog_id'];
            
        // Get Ticket level/Hotel level commissions values
        $market_merchant_prices = array();
        $supplier_prices = array();
        $all_commissions = array();
        $combi_clc_supplier_prices = array();
        $combi_ticket_result = array();
        if ($confirm_ticket_array['is_addon_ticket'] != "2") { 
            $all_commissions = $this->get_ticket_hotel_level_commission($hotel_id, $ticketId, $ticketpriceschedule_id, $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, '', ($ticket_reseller_id ?? 0));
            $ticket_level_commissions = $all_commissions['result'];
            $this->CreateLog('multi_currency.php', 'step1', array("result" => json_encode($all_commissions)));
            if (isset($all_commissions['supplier_merchant_data']) && !empty($all_commissions['supplier_merchant_data'])) {
                $supplier_merchant_commissions = $this->multi_currency_model->get_supplier_merchant_commission($all_commissions['supplier_merchant_data']);
                $this->CreateLog('multi_currency.php', 'step2', array("result" => json_encode($supplier_merchant_commissions)));
                $supplier_prices = $supplier_merchant_commissions['supplier_prices'];
                $market_merchant_prices = $supplier_merchant_commissions['market_merchant_prices'];
                $admin_prices = $supplier_merchant_commissions['admin_prices'];
            }
        } else if(!empty($main_tps_id)){
            // get currency data of main ticket 
            $all_commissions = $this->get_ticket_hotel_level_commission($hotel_id, $main_ticket_id, $main_tps_id,  $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, '', ($ticket_reseller_id ?? 0));

            if (isset($all_commissions['supplier_merchant_data']) && !empty($all_commissions['supplier_merchant_data'])) {
                
                $supplier_merchant_commissions = $this->multi_currency_model->get_supplier_merchant_commission($all_commissions['supplier_merchant_data'], 2);
                $ctd_clc_supplier_prices = $supplier_merchant_commissions['supplier_prices'];
                $ctd_clc_market_merchant_prices = $supplier_merchant_commissions['market_merchant_prices'];
                $ctd_clc_admin_prices = $supplier_merchant_commissions['admin_prices'];
            }
        }
        // get price data of sub ticket 
        $combi_commissions = $this->get_ticket_hotel_level_commission($hotel_id, $ticketId, $ticketpriceschedule_id,  $channel_id, $sub_catalog_id, $distributor_reseller_id, $ticket_reseller_subcatalog_id, 2, $ticket_reseller_id);

        if (isset($combi_commissions['result']) && !empty($combi_commissions['result'])) {
            $combi_ticket_result[] = $combi_commissions['result'];
            $supplier_merchant_commissions = $this->multi_currency_model->get_supplier_merchant_commission($combi_ticket_result, 2);
            $combi_clc_supplier_prices = $supplier_merchant_commissions['supplier_prices'];
        }
        $distributor_currency_museumCommission = $distributor_currency_museumNetPrice = 0;
        $commission_on_sale_price = 0;
        $is_resale_percentage = 0;
        $is_merchant_percentage = 0;
        // If commission set with ticket level and $ticket_level_commissions have a value of perticuler tickets from ticket_level_commissions table then if condition will true.
        // These value reflect in visitor table when click on complete button in ticket reciept.       
        $debugLogs['ticket_level_commissions'] = json_encode($ticket_level_commissions);        
        if ($ticket_level_commissions) {    
            $pricing_level = $ticket_level_commissions->pricing_level; // qr_codes prices applied
            $commission_level = $ticket_level_commissions->commission_level; // qr_codes prices applied
            $commission_on_sale_price = $ticket_level_commissions->commission_on_sale_price;
            if ($commission_on_sale_price == 1) {
                $ticketAmt += $sale_variation_amount;
            }
            // BOC for custom settings to save in prepaid tickets
            $is_custom_setting = $ticket_level_commissions->is_custom_setting;
            if ($is_custom_setting > 0) {
                $external_product_id = $ticket_level_commissions->external_product_id;
                $account_number = $ticket_level_commissions->account_number;
                $chart_number = $ticket_level_commissions->chart_number;
            }else{
                $account_number = $confirm_ticket_array['account_number'];
                $chart_number = $confirm_ticket_array['chart_number'];
            }
            // EOC for custom settings to save in prepaid tickets
            // if subtotal_net_amount set in ticket_level_commissions table then overrite these values.
            if (isset($ticket_level_commissions->subtotal_net_amount) && $ticket_level_commissions->is_adjust_pricing == 1) {
                if (isset($ticket_level_commissions->merchant_admin_id) && $ticket_level_commissions->merchant_admin_id != '0') {
                    $merchant_fees_assigned = '1';
                    if ($confirm_ticket_array['is_addon_ticket'] != "2") {
                        $merchant_admin_id = $ticket_level_commissions->merchant_admin_id;
                        $merchant_admin_name = $ticket_level_commissions->merchant_admin_name;
                    }
                } 
                $commission_on_sale_price =  $ticket_level_commissions->commission_on_sale_price;
                $is_resale_percentage = $ticket_level_commissions->is_resale_percentage;
                $is_merchant_percentage = $ticket_level_commissions->is_merchant_fee_percentage;
                if ($is_merchant_percentage == 1) {
                    // if merchant fee is setup in %
                    $merchant_fee_percentage = $ticket_level_commissions->merchant_fee_percentage;
                    $vt_partner_gross_price = $ticketAmt + $extra_gross_discount;
                    $ticket_level_commissions->merchant_gross_commission = round((($vt_partner_gross_price * $merchant_fee_percentage) / 100), 2);
                    $ticket_level_commissions->merchant_net_commission = ($ticket_level_commissions->merchant_gross_commission * 100) / ($ticket_tax_value + 100);
                }
                if ($is_resale_percentage == 1) {
                    // if museum cost is setup in %
                    $resale_percentage = $ticket_level_commissions->resale_percentage;
                    /***
                     * Uninitialize value used
                    $ticket_level_commissions->museum_gross_commission = round(((($ticketAmt + $extra_gross_discoun) * $resale_percentage) / 100), 2);
                    ***/
                    $ticket_level_commissions->museum_gross_commission = round(((($ticketAmt + 0) * $resale_percentage) / 100), 2);
                    $ticket_level_commissions->museum_net_commission = round(($ticket_level_commissions->museum_gross_commission * 100) / ($ticket_tax_value + 100),2);
                }
                $amount_to_deduct_from_museum_comission = 0;
                $amount_to_deduct_from_museum_net_price = 0;
                
                $debugLogs['CASE_10.1 subtotal = ticket_level_commissions->subtotal_net_amount'] = $ticket_level_commissions->subtotal_net_amount;
                if(!isset($ticket_level_commissions->is_cluster_ticket_added) || (isset($ticket_level_commissions->is_cluster_ticket_added) && $ticket_level_commissions->is_cluster_ticket_added == '0') || 1){
                    $ticket_level_commissions->subtotal_net_amount = round(($ticketAmt * 100) / ($ticket_tax_value + 100) + $extra_net_discount,2) - $ticket_level_commissions->museum_net_commission - $confirm_ticket_array['cluster_net_price'];
                    $debugLogs['CASE_10.2 subtotal = ticket_level_commissions->subtotal_net_amount'] = json_encode(array(
                        ($ticketAmt * 100),
                        ($ticket_tax_value + 100),
                        $extra_net_discount,
                        $ticket_level_commissions->museum_net_commission,
                        $confirm_ticket_array['cluster_net_price']
                    ));
                }
                $subtotal = $ticket_level_commissions->subtotal_net_amount;
                $debugLogs['CASE_10 subtotal = ticket_level_commissions->subtotal_net_amount'] = $subtotal;
                $combi_net = round(($combi_discount_gross_amount * 100) / ($ticket_tax_value + 100), 2);
                $debugLogs['combi_net'] = $combi_net;
                if($combi_net > 0){
                    $subtotal = $subtotal - $combi_net;
                    $debugLogs['CASE_9 subtotal-combi_net if combi_net > 0'] = $subtotal;
                }
                if ($new_discount > 0) {
                    if($extra_discount != '') {
                        $temp_discount = $net_discount_amount;                        
                    } else {
                        $temp_discount = $net_discount_amount + floatval((($ticket_level_commissions->ticket_new_price - $ticket_level_commissions->ticket_gross_price) * 100) / (100 + $ticket_tax_value));                        
                    }
                    if($subtotal >= $temp_discount) {
                        $subtotal = $subtotal - $temp_discount;
                        $debugLogs['CASE_9 subtotal-temp_discount'] = $subtotal;
                    }
                    
                    $subtotal = ($subtotal > 0) ? $subtotal : 0;
                }             
                $subtotal = round($subtotal, 2);

                $museumCommission = $ticket_level_commissions->museum_gross_commission;
                $museumNetPrice = $ticket_level_commissions->museum_net_commission;
                $ticketPrice = $ticket_level_commissions->ticket_new_price;
                $ispercenttype = $ticket_level_commissions->is_discount_in_percent;
                
                // set currency prices
                if ($is_different_currency == 1) {

                    $currency_amount_to_deduct_from_museum_comission = 0;
                    if(isset($ticket_level_commissions->currency_ticket_new_price) && $ticket_level_commissions->currency_ticket_new_price>0) {

                        $ticket_level_commissions->currency_subtotal_net_amount = $ticket_level_commissions->currency_ticket_net_price - $ticket_level_commissions->currency_museum_net_commission;
                        $currency_subtotal = $ticket_level_commissions->currency_subtotal_net_amount;
                        if ($is_combi_discount == '1') {
                            $currency_subtotal_with_combi_discount = $currency_subtotal + ($ticket_level_commissions->currency_combi_discount_net_amount);
                        }
                        else {
                            $currency_subtotal = $currency_subtotal + ($ticket_level_commissions->currency_combi_discount_net_amount);
                        }
                        
                        if ($currency_new_discount > 0) {
                            $currency_subtotal = $currency_subtotal - $currency_net_discount_amount + floatval((($ticket_level_commissions->currency_ticket_new_price - $ticket_level_commissions->currency_ticket_gross_price) * 100) / (100 + $ticket_tax_value));
                            $currency_subtotal = ($currency_subtotal > 0) ? $currency_subtotal : 0;
                        }
                        $currency_museumCommission = $ticket_level_commissions->currency_museum_gross_commission ;
                        $currency_museumNetPrice = $ticket_level_commissions->currency_museum_net_commission;
                   }
                   else {
                        $currency_subtotal = $ticket_level_commissions->subtotal_net_amount * $currency_rate;
                    
                        if ($is_combi_discount == '1') {
                            $currency_subtotal_with_combi_discount = $currency_subtotal + ($ticket_level_commissions->combi_discount_net_amount * $currency_rate);
                        }
                        else {
                            $currency_subtotal = $currency_subtotal + ($ticket_level_commissions->combi_discount_net_amount* $currency_rate);
                        }
                        
                        if ($currency_new_discount > 0) {
                            $currency_subtotal = $currency_subtotal - $currency_net_discount_amount + floatval((($ticket_level_commissions->ticket_new_price * $currency_rate - $ticket_level_commissions->ticket_gross_price * $currency_rate) * 100) / (100 + $ticket_tax_value));
                            $currency_subtotal = ($currency_subtotal > 0) ? $currency_subtotal : 0;
                        }
                        $currency_museumCommission = $ticket_level_commissions->museum_gross_commission * $currency_rate;
                        $currency_museumNetPrice = $ticket_level_commissions->museum_net_commission* $currency_rate;
                        $currency_ispercenttype = $ticket_level_commissions->is_discount_in_percent;
                    }
                }
                
                
                if(isset($ticket_level_commissions->currency_ticket_new_price) && $ticket_level_commissions->currency_ticket_new_price>0) {  
                    $is_currency_prices_set = 1;   
                }
            } else if (!isset($ticket_level_commissions->is_adjust_pricing) || $ticket_level_commissions->is_adjust_pricing == 0) {
                $subtotal = round(($ticketAmt * 100) / ($ticket_tax_value + 100) + $extra_net_discount,2) - $museumNetPrice - $confirm_ticket_array['cluster_net_price'];  
                $debugLogs['CASE_18 subtotal-is_adjust_pricing'] = $subtotal;
            }

            /*
             * This IF condition will be executed in case of group booking. If discount given on ticket is reduces the ticket amount upto
             * the amount so that ticket amount becomes less than museum commission. Then in that case whole amount will be paid to museum
             * and hotel and hgs will get nothing
            */
            if (
                ( !isset($ticket_level_commissions->subtotal_net_amount) || $ticket_level_commissions->is_adjust_pricing == 0 ) && 
                (
                    $new_discount > 0 || (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0)
                )
            ) {
                
                if ($new_discount > 0 || (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0)) {
                    /*
                     * This IF condition will be executed in case of group booking. If discount given on ticket is reduces the ticket amount upto
                     * the amount so that ticket amount becomes less than museum commission. Then in that case whole amount will be paid to museum
                     * and hotel and hgs will get nothing
                     */                
                    $net_new_discount = $net_discount_amount;                
                    if ($net_new_discount <= $subtotal && $net_new_discount > 0) {                        
                        $subtotal = $subtotal - $net_new_discount;
			$debugLogs['CASE_8 subtotal-net_discount_amount'] = $subtotal;
                    }
                }
            }
            // In case of Merchant fee setup
            if ($merchant_fees_assigned == '1' && ($is_combi != 2 || 1)) {
                $subtotal = $subtotal - $ticket_level_commissions->merchant_net_commission;
                $debugLogs['CASE_7 subtotal-ticket_level_commissions->merchant_net_commission'] = $subtotal;
                $this->CreateLog('ticket_subtotal.php', 'step3 Market Merchant deduction', array("subtotal " => $ticketTypeDetail->subtotal));
            }
            $hotel_tax_id = $ticket_level_commissions->hotel_commission_tax_id;
            $hgs_tax_id = $ticket_level_commissions->hgs_commission_tax_id;
            if (isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) {
                $hotel_commission_percentage = $ticket_level_commissions->hotel_prepaid_commission_percentage;
                $hgs_commission_percentage = $ticket_level_commissions->hgs_prepaid_commission_percentage;
            } else {
                $hotel_commission_percentage = $ticket_level_commissions->hotel_postpaid_commission_percentage;
                $hgs_commission_percentage = $ticket_level_commissions->hgs_postpaid_commission_percentage;
            }
            if ($is_combi_discount == '1') {
                $subtotal_with_combi_discount = $subtotal + $ticket_level_commissions->combi_discount_net_amount;
            }
            
            if ($ticket_level_commissions->is_hotel_prepaid_commission_percentage == 0) {
                $hotelNetPrice = $ticket_level_commissions->hotel_commission_net_price;
                $debugLogs['hotelNetPrice_5'] = $hotelNetPrice;
            } else {
                if ($commission_on_sale_price == 1){
                    $hotelNetPrice = round((($ticket_level_commissions->ticket_net_price * $hotel_commission_percentage) / 100), 2);
                    $debugLogs['hotelNetPrice_4'] = $hotelNetPrice;
                } else {
                    $hotelNetPrice = round((($subtotal * $hotel_commission_percentage) / 100), 2);
                    $debugLogs['hotelNetPrice_3'] = $hotelNetPrice;
                }
            }
            if(isset($ticket_level_commissions->hotel_commission_tax_value) & !empty($ticket_level_commissions->hotel_commission_tax_value)){
                $calculated_hotel_commission = $hotelNetPrice + round((($hotelNetPrice * $ticket_level_commissions->hotel_commission_tax_value) / 100), 2);
            } else {
                $calculated_hotel_commission = round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2);
            }
            $isCommissionInPercent = "1";
            $hgsnetprice = round(($subtotal - $hotelNetPrice), 2);
            $hotel_net_price_without_combi_discount = round((($subtotal_with_combi_discount * $hotel_commission_percentage) / 100), 2);
            $hotel_gross_price_without_combi_discount = $hotel_net_price_without_combi_discount + round((($hotel_net_price_without_combi_discount * $ticket_level_commissions->hotel_commission_tax_value) / 100), 2);
            $hgs_net_price_without_combi_discount = round((($subtotal_with_combi_discount * $hgs_commission_percentage) / 100), 2);
            
            if ($is_different_currency == 1) {
                $ticket_new_price = 0;
                if(isset($ticket_level_commissions->currency_ticket_new_price) && $ticket_level_commissions->currency_ticket_new_price>0) {         
                    $currency_check_price = $ticket_level_commissions->currency_ticket_new_price;
                    $ticket_new_price = $ticket_level_commissions->currency_ticket_new_price;
                }else if ($ticket_level_commissions->ticket_new_price != '' && $ticket_level_commissions->ticket_new_price > 0) {
                    $currency_check_price = $ticket_level_commissions->ticket_new_price * $currency_rate;
                    $ticket_new_price = $ticket_level_commissions->ticket_new_price;
                } else {
                    $currency_check_price = $ticket_level_commissions->ticket_list_price * $currency_rate;
                    $ticket_new_price = $ticket_level_commissions->ticket_new_price;
                }


                if ($currency_new_discount > 0 && ($currency_museumCommission > $currency_check_price - $currency_gross_discount_amount)) {
                    if (!empty($ticket_new_price)) {
                        $currency_amount_to_deduct_from_museum_comission = $currency_museumCommission - ($currency_check_price - $currency_gross_discount_amount);
                        if ($currency_museumCommission == $currency_amount_to_deduct_from_museum_comission) {
                            $currency_amount_to_deduct_from_museum_net_price = $currency_museumNetPrice;
                        } else {
                            $currency_amount_to_deduct_from_museum_net_price = round((($currency_amount_to_deduct_from_museum_comission * 100) / (100 + $ticket_tax_value)), 2);
                        }
                    } else {
                        if ($currency_net_discount_amount > $currency_original_subtotal) {
                            $currency_original_subtotal_with_tax = $currency_original_subtotal + (($ticket_tax_value * $currency_original_subtotal) / ($ticket_tax_value + 100));
                            $currency_amount_to_deduct_from_museum_comission = $currency_gross_discount_amount - $currency_original_subtotal_with_tax;
                            $currency_amount_to_deduct_from_museum_net_price = round($currency_net_discount_amount - $currency_original_subtotal, 2);
                        }
                    }
                }
                $hotel_tax_id = $ticket_level_commissions->hotel_commission_tax_id;
                $hgs_tax_id = $ticket_level_commissions->hgs_commission_tax_id;
                if (isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) {
                    $hotel_commission_percentage = $ticket_level_commissions->hotel_prepaid_commission_percentage;
                    $hgs_commission_percentage = $ticket_level_commissions->hgs_prepaid_commission_percentage;
                } else {
                    $hotel_commission_percentage = $ticket_level_commissions->hotel_postpaid_commission_percentage;
                    $hgs_commission_percentage = $ticket_level_commissions->hgs_postpaid_commission_percentage;
                }
                $currency_hotelNetPrice = round((($currency_subtotal * $hotel_commission_percentage) / 100), 2);
                $currency_calculated_hotel_commission = $currency_hotelNetPrice + round((($currency_hotelNetPrice * $ticket_level_commissions->hotel_commission_tax_value) / 100), 2);
                $isCommissionInPercent = "1";
                $currency_hgsnetprice = round((($currency_subtotal * $hgs_commission_percentage) / 100), 2);
            }
        } else {
            $is_custom_setting = '';
            $external_product_id = '';
            $account_number = $confirm_ticket_array['account_number'];
            $chart_number = $confirm_ticket_array['chart_number'];
            if ($discount_from_sales) {
                $calculated_hotel_commission = round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2) + $combi_discount_gross_amount; 
                if ($isCommissionInPercent == '1' || $discount_type == 2) {
                    $hotelNetPrice = round((($subtotal * $hotelCommission) / 100), 2);                    
                    $hgsnetprice = round((($subtotal * $hgsCommission) / 100), 2);
                } else {
                    if ($subtotal > $hotelNetPrice) {
                        $hotelNetPrice = $subtotal - $calculated_hotel_commission;
                        $calculated_hotel_commission = $hotelNetPrice - round(($hotelNetPrice * $hotel_tax_value) / 100, 2);
                        $hgsnetprice = $subtotal - $calculated_hotel_commission;
                    } else {
                        $hotelNetPrice = $subtotal;
                        $calculated_hotel_commission = $hotelNetPrice - round(($hotelNetPrice * $hotel_tax_value) / 100, 2);
                        $hgsnetprice = 0;
                    }
                }
            }
            if ($new_discount > 0 || (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0)) {
                /*
                 * This IF condition will be executed in case of group booking. If discount given on ticket is reduces the ticket amount upto
                 * the amount so that ticket amount becomes less than museum commission. Then in that case whole amount will be paid to museum
                 * and hotel and hgs will get nothing
                 */                
                $net_new_discount = $net_discount_amount;  
                if ($merchant_fees_assigned == '1') {
                    $subtotal = $subtotal + $ticket_level_commissions->merchant_net_commission;
                }              
                if ($net_new_discount <= $subtotal && $net_new_discount > 0) {
                    if ($net_new_discount > 0) {
                        $subtotal = $subtotal - $net_new_discount;
                    }
                    if ($merchant_fees_assigned == '1') {
                        $subtotal = $subtotal - $ticket_level_commissions->merchant_net_commission;
                    }
                    if ($isCommissionInPercent == '1' || $discount_type == 2) {
                        $hotelNetPrice = round((($subtotal * $hotelCommission) / 100), 2);
                    } else {
                        if ($subtotal > $calculated_hotel_commission) {
                            $hotelNetPrice = $subtotal - $calculated_hotel_commission;
                        } else {
                            $hotelNetPrice = $subtotal;
                        }
                    }
                }
            }
            if (($is_different_currency == 1) && 
                (
                    $currency_new_discount > 0 || (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0)
                )
            ) {

                /*
                 * This IF condition will be executed in case of group booking. If discount given on ticket is reduces the ticket amount upto
                 * the amount so that ticket amount becomes less than museum commission. Then in that case whole amount will be paid to museum
                 * and hotel and hgs will get nothing
                 */
                if ($currency_new_discount > 0 && ($currency_museumCommission > ($currency_ticketPrice - $currency_gross_discount_amount))) {
                    $currency_amount_to_deduct_from_museum_comission = $currency_museumCommission - ($currency_ticketPrice - $currency_gross_discount_amount);
                    $currency_amount_to_deduct_from_museum_net_price = round((($currency_amount_to_deduct_from_museum_comission * 100) / (100 + $ticket_tax_value)), 2);
                } else {
                    if ($isCommissionInPercent == '1' || $discount_type == 2) {                       
                        $currency_hotelNetPrice = round((($currency_subtotal * $hotelCommission) / 100), 2);
                        $currency_calculated_hotel_commission = $currency_hotelNetPrice - round(($currency_hotelNetPrice * $hotel_tax_value) / 100, 2);
                        $currency_hgsnetprice = round((($currency_subtotal * $hgsCommission) / 100), 2);
                    } else {                         
                        if ($currency_subtotal > $currency_calculated_hotel_commission) {
                            $currency_hotelNetPrice = $currency_subtotal - $currency_calculated_hotel_commission;
                            $currency_calculated_hotel_commission = $currency_hotelNetPrice - round(($currency_hotelNetPrice * $hotel_tax_value) / 100, 2);
                            $currency_hgsnetprice = $currency_subtotal - $currency_calculated_hotel_commission;
                        } else {
                            $currency_hotelNetPrice = $currency_subtotal;
                            $currency_calculated_hotel_commission = $currency_hotelNetPrice - round(($currency_hotelNetPrice * $hotel_tax_value) / 100, 2);
                            $currency_hgsnetprice = 0;
                        }
                    }
                }  
            }
            
            $calculated_hotel_commission = round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2) + $combi_discount_gross_amount; 
            $hgsnetprice = $subtotal - $hotelNetPrice;
        }

        $dist_net_commission    =   '';
        $distributor_commission    =   '';
        if ( $is_commission_applicable_varation == 1 && $ticket_level_commissions->is_hotel_prepaid_commission_percentage == 1) { 
            $distributor_commission =   $distributor_fee_percentage * $sale_variation_amount/100;
            $dist_tax_value  =   $confirm_ticket_array['tax'] == '' ? '0.00' : $confirm_ticket_array['tax'];
            $dist_commission =   $distributor_commission * $dist_tax_value/(100 + $dist_tax_value);
            $dist_net_commission =   $distributor_commission - $dist_commission;
        }
        
        $dist_net_commission        =   '';
        $distributor_commission     =   '';
        /* calculate gross price and net price if distributor commission is in percentage */ 
        if ( $is_commission_applicable_varation == 1 && $ticket_level_commissions->is_hotel_prepaid_commission_percentage == 1) {
            $distributor_commission =   $distributor_fee_percentage * $sale_variation_amount/100;
            $dist_tax_value  =   $confirm_ticket_array['tax'] == '' ? '0.00' : $confirm_ticket_array['tax'];
            $dist_commission =   $distributor_commission * $dist_tax_value/(100 + $dist_tax_value);
            $dist_net_commission =   $distributor_commission - $dist_commission;
        }
        $tax_array = $this->common_model->selectPartnertaxes('0', SERVICE_NAME, $hotel_id, array($museum_tax_id, $hotel_tax_id, $hgs_tax_id, $ticket_tax_id));
        if ($confirm_ticket_array['tax_exception_applied'] == '1') {
            $hotel_tax_value = $confirm_ticket_array['tax'];
            $supplier_hotel_tax_value = $confirm_ticket_array['tax'];
            $ticket_tax_value = $confirm_ticket_array['tax'];
            $supplier_museum_tax_value = $confirm_ticket_array['tax'];
            $supplier_hgs_tax_value = $confirm_ticket_array['tax'];
            $hotel_tax_name = $confirm_ticket_array['tax_name'];
            $ticket_tax_name = $confirm_ticket_array['tax_name'];
        } else {
            foreach ($tax_array as $tax) {
                if ($tax->tax_id == $museum_tax_id || $tax->id == $museum_tax_id) {
                    $museum_tax_value = $tax->tax_value;
                    $museum_tax_name = $tax->tax_name;
                }
                if ($tax->tax_id == $hotel_tax_id || $tax->id == $hotel_tax_id) {
                    $hotel_tax_value = $tax->tax_value;
                    $hotel_tax_name = $tax->tax_name;
                }
                if ($tax->tax_id == $hgs_tax_id || $tax->id == $hgs_tax_id) {
                    $hgs_tax_value = $tax->tax_value;
                    $hgs_tax_name = $tax->tax_name;
                }
                if ($tax->tax_id == $ticket_tax_id || $tax->id == $ticket_tax_id) {
                    $ticket_tax_value = $tax->tax_value;
                    $ticket_tax_name = $tax->tax_name;
                }
            }
        }
        if ($amount_to_deduct_from_museum_comission != 0) {
            $calculated_hotel_commission = 0;
            $hotelNetPrice = 0;
            $hgsnetprice = 0;
            $hotelCommission = 0;
            $hgsCommission = 0;
            $calculated_hgs_commission = 0;
        }
        
        // Get the ticket or hotel level affiliates
        $affiliate_data = $this->get_ticket_hotel_level_affiliates($hotel_id, $ticketId, $ticketpriceschedule_id, '0', $channel_id);
        if ($ticketAmt == '0') {
            $captured = '1';
            $paidStatus = '1';
        }
        $left_hotel_aff_price = $subtotal;
        $total_aff = 0;
        $partner_net_price_row_1 = round(($ticketAmt * 100) / ($ticket_tax_value + 100) + $extra_net_discount,2);
        if ($affiliate_data && count($affiliate_data) > 0) {
            foreach ($affiliate_data as $affiliate) {
                if ($hotelNetPrice <= 0) {
                    continue;
                }
                if (isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) {
                    $affiliate_commission_percentage = $affiliate->affiliate_prepaid_commission;
                } else {
                    $affiliate_commission_percentage = $affiliate->affiliate_postpaid_commission;
                }
                if ($affiliate->is_affiliate_fee_percentage == 0 || $ticket_level_commissions->is_hotel_prepaid_commission_percentage == 0) {
                    // if affiliates fee setup in fixed amount
                    $partner_net_price = $affiliate->affiliate_fee;
                } else {
                    if ($commission_on_sale_price == 1) {
                        $partner_net_price = round((($insert_visitor['partner_net_price'] * $affiliate_commission_percentage) / 100), 2);
                    } else {
                        $partner_net_price = round((($subtotal * $affiliate_commission_percentage) / 100), 2);
                    }
                } 
                $total_aff += $partner_net_price;
                
            }
        }
        $total_left_commission = $total_aff +$ticket_level_commissions->hotel_commission_net_price + $ticket_level_commissions->hgs_commission_net_price;
        if ($commission_on_sale_price == 0 && (empty($ticket_level_commissions) || ($ticket_level_commissions->hotel_prepaid_commission_percentage == 0 &&  $ticket_level_commissions->hgs_prepaid_commission_percentage == 0) || $total_left_commission > $subtotal)){
            // assign affiliate first and subtract hotel commission
            $hotelNetPrice -= $total_aff;
        }
        if ($hotelNetPrice < 0) {
            // assign affiliate first and zero in hotel net commission
            $hotelNetPrice = 0.00;
        }
        $visit_date = $visit_date != '' ? $visit_date : $created_at;
        $current_date = $created_at != '' ? gmdate('Y-m-d H:i:s', $created_at) : gmdate('Y-m-d H:i:s');
        $visit_date_time = ($pre_visit_date_time != '') ? $pre_visit_date_time : ($created_at != '' ? gmdate('Y/m/d H:i:s', $created_at) : gmdate('Y/m/d H:i:s'));
        $today_date =  strtotime($visit_date_time) + ($timeZone * 3600);
        $paymentMethodType = 1;
        $ticketpriceschedule_id = $ticketpriceschedule_id ? $ticketpriceschedule_id : 0;
        $ticketType = $ticketType ? $ticketType : 0;
        $ticket_tax_id = $ticket_tax_id ? $ticket_tax_id : 0;
        $ticket_tax_value = $ticket_tax_value == '' ? '0.00' : $ticket_tax_value;
        $visitor_invoice_id = '';
        $invoice_status = '0';
        if ($is_block == 2) {
            $payment_method = $hotel_name;
        } else if ($paymentMethod == '' && $pspReference == '') {
            $payment_method = $hotel_name; // 0 = Bill to hotel
        } else if (strtolower($paymentMethod) == 'visa') {
            $payment_method = 'Visa'; // 0 = Visa
        } else if (strtolower($paymentMethod) == 'amex') {
            $payment_method = 'Amex'; // 0 = Amex
        } else if (strtolower($paymentMethod) == 'mc') {
            $payment_method = 'Master card'; // 4 = Master card
        } else {
            $payment_method = 'Others'; //   others
        }
        if ($paymentMethodType == '4' || $paymentMethodType == '2') {
            if ($paymentMethodType == '4') {
                $sales_type = 'Guest Transaction';
            } else {
                $sales_type = 'General sales';
            }
            $partnername = $hotel_name;
        } else {
            if ($initialPayment->activation_method == '0') {
                $partnername = $card_name;
            }
            $sales_type = 'General sales';
        }
        if ($order_status > 0) {
            if ($order_status == 1) {
                $invoice_status = '10';
            } else if ($order_status == 2) {
                $invoice_status = '11';
            } else if ($order_status == 3) {
                if ($node_api_response == 2) {
                    $invoice_status = '3';
                } else {
                    $invoice_status = '0';
                }
                
            }
        }

        // get next transaction id
        if(empty($transaction_id)) {
            $transaction_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id);
        }
        $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "01");
        // BOF for visitor
        $visitor_debitor = 'Guest';
        $visitor_creditor = 'Debit';
        if (isset($confirm_ticket_array['prepaid_type'])) {
            $insert_visitor['activation_method'] = $confirm_ticket_array['prepaid_type'];
            if ($confirm_ticket_array['prepaid_type'] == '1') {
                $insert_visitor['time_based_done'] = "1";
                if ($paymentMethod == '' && $pspReference == '') {
                    $payment_method = $hotel_name; // 0 = Bill to hotel
                } else if (strtolower($paymentMethod) == 'visa') {
                    $payment_method = 'Visa'; // 0 = Visa
                } else if (strtolower($paymentMethod) == 'amex') {
                    $payment_method = 'Amex'; // 0 = Amex
                } else if (strtolower($paymentMethod) == 'mc') {
                    $payment_method = 'Master card'; // 4 = Master card
                } else {
                    $payment_method = 'Others'; //   others
                }
            } else if ($confirm_ticket_array['prepaid_type'] == '2') {
                $insert_visitor['time_based_done'] = "1";
            }
        } else if ($billtohotel == '0') {
            $insert_visitor['activation_method'] = "1";
        } else {
            $insert_visitor['activation_method'] = "3";
        }
        if (isset($confirm_ticket_array['discount']) && $confirm_ticket_array['discount'] > 0) {
            $ispercenttype = $confirm_ticket_array['is_discount_in_percent'];
        }
        $discount = $confirm_ticket_array['discount'];
        if (isset($confirm_ticket_array['time_based_done'])) {
            $insert_visitor['time_based_done'] = "1";
        }
        if (!isset($confirm_ticket_array['distributor_type']) || $confirm_ticket_array['distributor_type'] == '') {
            $hto_data = $this->order_process_vt_model->find('qr_codes', array('select' => 'distributor_type', 'where' => 'cod_id = "' . $hotel_id . '"'));
            $confirm_ticket_array['distributor_type'] = ($hto_data[0]['distributor_type'] != '') ? $hto_data[0]['distributor_type'] : '';
        }
        $insert_visitor['id'] = $visitor_tickets_id;
        if(!empty($voucher_creation_date)){
           $insert_visitor['voucher_creation_date'] = $voucher_creation_date;
        }
        $insert_visitor['is_prioticket'] = $is_prioticket;
        $insert_visitor['targetlocation'] = $confirm_ticket_array['clustering_id'];
        $insert_visitor['card_name'] = $confirm_ticket_array['discount_label'];
        $insert_visitor['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
        $insert_visitor['tp_payment_method']  = $tp_payment_method;
        $insert_visitor['order_confirm_date'] = $order_confirm_date;        
        $insert_visitor['transaction_id'] = $transaction_id;
        $insert_visitor['visitor_invoice_id'] = $transaction_id;
        $insert_visitor['invoice_id'] = $visitor_invoice_id;
        $insert_visitor['channel_id'] = $channel_id;
        $insert_visitor['channel_name'] = $confirm_ticket_array['channel_name'];
        $insert_visitor['reseller_id'] = $reseller_id;
        $insert_visitor['reseller_name'] = $reseller_name;
        $insert_visitor['saledesk_id'] = $saledesk_id;
        $insert_visitor['partner_category_id'] = $partner_category_id;
        $insert_visitor['partner_category_name'] = $partner_category_name;
        $insert_visitor['saledesk_name'] = $saledesk_name;
        $insert_visitor['financial_id'] = $confirm_ticket_array['financial_id'];
        $insert_visitor['financial_name'] = $confirm_ticket_array['financial_name'];
        $insert_visitor['ticketId'] = $ticketId;
        if (isset($confirm_ticket_array['invoice_type'])) {
            $insert_visitor['invoice_type'] = $confirm_ticket_array['invoice_type'];
        }

        $insert_visitor['ticket_title'] = $ticket_title;
        $insert_visitor['all_ticket_ids'] = $voucher_reference;
        $insert_visitor['version'] = $version;
        $insert_visitor['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
        $insert_visitor['ticketpriceschedule_id'] = $ticketpriceschedule_id;
        $insert_visitor['hto_id'] = $hto_id;
        if ((isset($confirm_ticket_array['service_gross']) && $confirm_ticket_array['service_gross'] > 0) && ($confirm_ticket_array['service_cost_type'] == '1' || $confirm_ticket_array['service_cost_type'] == '0')) {
            $insert_visitor['service_cost'] = $confirm_ticket_array['service_gross'];
            $service_net = ($confirm_ticket_array['service_gross'] * 100) / ($ticket_tax_value + 100);
            $service_net = round($service_net, 2);
            $insert_visitor['service_cost_net_amount'] = $service_net;
            $insert_visitor['service_cost_type'] = $confirm_ticket_array['service_cost_type'];
        }
        $insert_visitor['visitor_group_no'] = $visitor_group_no;
        $insert_visitor['vt_group_no'] = $visitor_group_no;
        $insert_visitor['visit_date_time'] = $visit_date_time;
        $insert_visitor['partner_id'] = $hto_id;
        $insert_visitor['partner_name'] = $partnername;
        $insert_visitor['is_custom_setting'] = $pax;
        $insert_visitor['museum_name'] = $museum_name;
        $insert_visitor['museum_id'] = $museum_id;
        $insert_visitor['hotel_name'] = $hotel_name;
        $insert_visitor['primary_host_name'] = $primary_host_name;
        $insert_visitor['hotel_id'] = $hotel_id;
        $insert_visitor['is_refunded'] = $is_refunded;
        $insert_visitor['shift_id'] = $shift_id;
        $insert_visitor['pos_point_id'] = $pos_point_id;
        $insert_visitor['pos_point_name'] = $pos_point_name;
        $insert_visitor['passNo'] = $passNo;
        $insert_visitor['pass_type'] = $pass_type;
        $insert_visitor['ticketAmt'] = $ticketAmt;
        $insert_visitor['visit_date'] = $visit_date;
        $insert_visitor['ticketType'] = $ticketType;
        $insert_visitor['tickettype_name'] = $tickettype_name;
        $insert_visitor['paid'] = $paidStatus;
        $insert_visitor['payment_method'] = ((isset($confirm_ticket_array['payment_method']) && $confirm_ticket_array['payment_method'] == 'coupon')) ? ucfirst($confirm_ticket_array['coupon_code']) : $payment_method;
        $insert_visitor['isBillToHotel'] = $billtohotel;
        $insert_visitor['pspReference'] = $pspReference;
        $insert_visitor['card_type'] = $paymentMethod;
        $insert_visitor['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
        $insert_visitor['captured'] = $captured;
        $insert_visitor['age_group'] = $ageGroup;
        if($discount_from_sales && ($channel_type == 2 || ($channel_type == 0 && strstr($confirm_ticket_array['action_performed'], 'ADMND_COR'))) || (!empty($confirm_ticket_array['action_from']) && $confirm_ticket_array['action_from'] == 'V3.2')) {
            $insert_visitor['discount'] = $discount_from_sales;
        } else {
            $insert_visitor['discount'] = ($discount != '' && ($channel_type == 5 || empty($extra_discount))) ? $discount : '0';
        }
        $insert_visitor['is_block'] = $is_block;
        $insert_visitor['isDiscountInPercent'] = $ispercenttype;
        $insert_visitor['debitor'] = $visitor_debitor;
        $insert_visitor['creditor'] = $visitor_creditor;
        $insert_visitor['total_gross_commission'] = $totalCommission;
        $insert_visitor['total_net_commission'] = ($totalNetCommission != '') ? $totalNetCommission : 0;
        $partner_gross_price_row_1 = $ticketAmt + $extra_gross_discount;
        
        $insert_visitor['partner_gross_price_without_combi_discount'] = $ticketAmt + $extra_gross_discount;
        $partner_net_price_row_1 = round(($ticketAmt * 100) / ($ticket_tax_value + 100) + $extra_net_discount,2);
        if($commission_on_sale_price == 1) {
            $insert_visitor['partner_net_price'] = $partner_net_price_row_1;
            $insert_visitor['partner_gross_price'] = $partner_gross_price_row_1;
        } else {
            $insert_visitor['partner_net_price'] = $partner_net_price_row_1 + $sale_net_variation_amount;
            $insert_visitor['partner_gross_price'] = $partner_gross_price_row_1 + $sale_variation_amount;
        }
        

        $insert_visitor['order_currency_partner_gross_price'] = $distributor_ticketAmt + $order_currency_extra_gross_discount;

        $insert_visitor['order_currency_partner_net_price'] = ($distributor_ticketAmt * 100) / ($ticket_tax_value + 100) + $order_currency_extra_net_discount;
        $insert_visitor['partner_net_price_without_combi_discount'] = $insert_visitor['partner_net_price'];
        $insert_visitor['isCommissionInPercent'] = "0";
        $insert_visitor['tax_id'] = $ticket_tax_id;
        $insert_visitor['tax_value'] = $ticket_tax_value ;
        $insert_visitor['extra_discount'] = $extra_discount;
        $insert_visitor['distributor_partner_id'] = $distributor_partner_id;
        $insert_visitor['distributor_partner_name'] = $distributor_partner_name;
        $insert_visitor['tp_payment_method'] = $tp_payment_method;
        $insert_visitor['order_confirm_date'] = $order_confirm_date;
        $insert_visitor['payment_date'] = $payment_date;
        $insert_visitor['tax_name'] = $ticket_tax_name;
        $insert_visitor['timezone'] = $timeZone;
        $insert_visitor['adyen_status'] = $pricing_level;
        /* In case of payment from coupon on lookout then invoice status should be 5 (paid) for general sales entry and 0 for distrubutor entry */
        if (isset($confirm_ticket_array['payment_method']) && $confirm_ticket_array['payment_method'] == 'coupon' && $order_status != '3') {
            $insert_visitor['invoice_status'] = '5';
        } else {
            $insert_visitor['invoice_status'] = $invoice_status;
        }
        $insert_visitor['row_type'] = "1";
        $insert_visitor['ticket_status'] = $ticket_status;
        $insert_visitor['merchant_admin_id'] = $merchant_admin_id ;
        $insert_visitor['merchant_admin_name'] = $merchant_admin_name;
        $insert_visitor['market_merchant_id'] = $market_merchant_id;
        $insert_visitor['updated_by_id'] = $resuid;
        $insert_visitor['updated_by_username'] = $resfname . ' ' . $reslname;
        $insert_visitor['voucher_updated_by'] = ( ( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) )? $voucher_updated_by: $resuid );
        $insert_visitor['voucher_updated_by_name'] = ( ( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by_name ) )? $voucher_updated_by_name: $resfname . ' ' . $reslname );
        $insert_visitor['redeem_method'] =$redeem_method;
        $insert_visitor['cashier_id'] = $cashier_id;
        $insert_visitor['cashier_name'] = $cashier_name;
        $insert_visitor['roomNo'] = $userdata->roomNo;
        $insert_visitor['nights'] = $is_iticket_product;
        $insert_visitor['user_name'] = $userdata->guest_names;
        $insert_visitor['user_age'] = $userdata->user_age;
        $insert_visitor['gender'] = $userdata->gender;
        $insert_visitor['user_image'] = $userdata->user_image;
        $insert_visitor['visitor_country'] = $userdata->visitor_country;
        $insert_visitor['merchantAccountCode'] = $userdata->merchantAccountCode;
        $insert_visitor['merchantReference'] = $userdata->merchantReference;
        $insert_visitor['original_pspReference'] = $userdata->pspReference;
        $insert_visitor['targetcity'] = $targetcity;
        $insert_visitor['paymentMethodType'] = $paymentMethodType;
        $insert_visitor['service_name'] = SERVICE_NAME;
        $insert_visitor['distributor_status'] = "6";
        $insert_visitor['transaction_type_name'] = $sales_type;
        $insert_visitor['shopperReference'] = $userdata->shopperReference;
        $insert_visitor['issuer_country_code'] = $country;
        $insert_visitor['selected_date'] = $selected_date;
        $insert_visitor['booking_selected_date'] = $booking_selected_date;
        $insert_visitor['from_time'] = $from_time;
        $insert_visitor['to_time'] = $to_time;
        $insert_visitor['slot_type'] = $slot_type;
        $insert_visitor['booking_status'] = $booking_status;
        $insert_visitor['channel_type'] = $channel_type;
        $insert_visitor['ticket_booking_id'] = $ticket_booking_id;
        $insert_visitor['without_elo_reference_no'] = $without_elo_reference_no;
        $insert_visitor['extra_text_field_answer'] = $extra_text_field_answer;
        $insert_visitor['is_voucher'] = $is_voucher;
        $insert_visitor['group_type_ticket'] = $group_type_ticket;
        $insert_visitor['group_price'] = $group_price;
        $insert_visitor['group_quantity'] = $group_quantity;
        $insert_visitor['group_linked_with'] = $group_linked_with;
        $insert_visitor[$this->admin_currency_code_col] = !empty($admin_prices['currency_code']) ? $admin_prices['currency_code'] : $confirm_ticket_array['supplier_currency_code'];
        $insert_visitor['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
        $insert_visitor['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
        $insert_visitor['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
        $insert_visitor['currency_rate'] = $confirm_ticket_array['currency_rate'];
        $insert_visitor['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
        $insert_visitor['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
        
        if ($is_voucher == '1') {
            // $insert_visitor['activation_method'] = '10';
            $insert_visitor['activation_method'] = $confirm_ticket_array['prepaid_type'];
        }
        $insert_visitor['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
        $insert_visitor['used'] = isset($confirm_ticket_array['used']) ? $confirm_ticket_array['used'] : '0';
        $insert_visitor['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
        $insert_visitor['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
        $insert_visitor['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
        if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
            $insert_visitor['activation_method'] = '9';
            $insert_visitor['split_cash_amount'] = $split_cash_amount;
            $insert_visitor['split_card_amount'] = $split_card_amount;
            $insert_visitor['split_direct_payment_amount'] = $split_direct_amount;
            $insert_visitor['split_voucher_amount'] = $split_voucher_amount;
        }
        $is_used = isset($confirm_ticket_array['used']) ? $confirm_ticket_array['used'] : '0';
        if (isset($confirm_ticket_array['scanned_pass'])) {
            $insert_visitor['scanned_pass'] = $scanned_pass;
            $insert_visitor['groupTransactionId'] = $groupTransactionId;
        }
        $insert_visitor['cashier_register_id'] = $cashier_register_id;
        if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
            $insert_visitor['is_prepaid'] = "1";
        }        
        if (!empty($is_custom_setting) && $is_custom_setting > 0) {
            $insert_visitor['external_product_id'] = $external_product_id;
        } else if ('4' == $mec_is_combi) {
            /* external_product_id to be added (23-12-2022) */
            $insert_visitor['external_product_id'] = $related_product_id;
        }

        $insert_visitor['account_number'] = $account_number;
        $insert_visitor['chart_number'] = $chart_number;
        $insert_visitor['order_updated_cashier_id'] = $order_updated_cashier_id;
        $insert_visitor['order_updated_cashier_name'] = $order_updated_cashier_name;
        $insert_visitor['redeem_method'] = $redeem_method;
        if ($confirm_ticket_array['is_addon_ticket'] == "1") {
            $insert_visitor['col2'] = $confirm_ticket_array['is_addon_ticket'];
        }
        if (isset($confirm_ticket_array['insertedId']) && $confirm_ticket_array['insertedId'] > 0) {
            $idinfo = explode('_', $confirm_ticket_array['insertedId']);
            $whereArr['id'] = $idinfo[0];
            $insert_visitor['deleted'] = "0";
            $insert_visitor['paid'] = "1";
            $insert_visitor['pspReference'] = $idinfo[1];
            $insert_visitor['updated_at'] = $confirm_ticket_array['updated_at'];
            //In case of cluster ticket don't need to save data of this row
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                if (isset($confirm_ticket_array['action_performed']) && !empty($confirm_ticket_array['action_performed'])) {
                    $db->set('action_performed', "CONCAT(action_performed, ', ".$confirm_ticket_array['action_performed']."')", FALSE); // update action in visitor_tickets from Order Overview
                }
                /* COMMENTED $db->update("visitor_tickets", $insert_visitor, $whereArr); */
            }

            if ($update_primary_db == 1 && $is_secondary_db == 1 && ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket']))) {
                if (isset($confirm_ticket_array['action_performed']) && !empty($confirm_ticket_array['action_performed'])) {
                    $db->set('action_performed', "CONCAT(action_performed, ', ".$confirm_ticket_array['action_performed']."')", FALSE); // update action in visitor_tickets from Order Overview
                }
                /* COMMENTED $this->db->update("visitor_tickets", $insert_visitor, $whereArr); */
            }
            $insertedId = $idinfo[0];
        } else {
            $insert_visitor['supplier_gross_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_gross_price'][1] : $confirm_ticket_array['supplier_gross_price'];
            $insert_visitor['supplier_discount'] = !empty($supplier_prices) ? $supplier_prices['supplier_discount'] : $confirm_ticket_array['supplier_discount'];
            $insert_visitor['supplier_ticket_amt'] = $confirm_ticket_array['supplier_ticket_amt'];
            $insert_visitor['supplier_tax_value'] = $confirm_ticket_array['supplier_tax_value'];
            $insert_visitor['supplier_net_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_net_price'][1] : $confirm_ticket_array['supplier_net_price'];
            
            if (isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) {
                $insert_visitor['paid'] = "1";
            }
            if (isset($confirm_ticket_array['prepaid_type']) && $confirm_ticket_array['prepaid_type'] == '2' && $order_status != '3') {
                $insert_visitor['paid'] = "1";
                $insert_visitor['invoice_status'] = "5";
            } else if (isset($confirm_ticket_array['prepaid_type']) && $confirm_ticket_array['prepaid_type'] == '3') {
                $insert_visitor['paid'] = "1";
                $insert_visitor['invoice_status'] = $invoice_status;
            }
            /* In case of payment from coupon on lookout then invoice status should be 5 (paid) for general sales entry and 0 for distrubutor entry */
            if (isset($confirm_ticket_array['payment_method']) && $confirm_ticket_array['payment_method'] == 'coupon' && $order_status != '3') {
                $insert_visitor['invoice_status'] = '5';
            }
            if ($channel_type == 2 || $channel_type == 4) {
                $insert_visitor['invoice_status'] = $invoice_status;
            }
            $insert_visitor['action_performed'] = $confirm_ticket_array['action_performed'];
            $insert_visitor['updated_at']       = $current_date;
            
            if ($uncancel_order == 1) {
                if($channel_type == 7 || $channel_type == 6){
                    $insert_visitor['invoice_status'] = '0';
                    $insert_visitor['action_performed'] = $confirm_ticket_array['action_performed'];
                } else {
                    $insert_visitor['invoice_status'] = '12';
                    $insert_visitor['action_performed'] = $confirm_ticket_array['action_performed'].', CSS_RFN_CANCEL';
                }
                $insert_visitor['is_refunded'] = '0';
                //$insert_visitor['created_date'] = gmdate('Y-m-d H:i:s');
                //$insert_visitor['visit_date_time'] = gmdate('Y/m/d H:i:s');
                //$insert_visitor['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
                $insert_visitor['updated_at'] = gmdate('Y-m-d H:i:s');                
            }
            $insert_visitor[$this->merchant_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_gross_price'][1] : $insert_visitor['partner_gross_price'];
            $insert_visitor[$this->merchant_net_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_net_price'][1] : $insert_visitor['partner_net_price'];
            $insert_visitor[$this->merchant_tax_id_col] = !empty($market_merchant_prices) ? $market_merchant_prices['ticket_tax_id'] : $insert_visitor['tax_id'];
            $insert_visitor[$this->supplier_tax_id_col] = !empty($supplier_prices) ? $supplier_prices['ticket_tax_id'] : $insert_visitor['tax_id'];
            $insert_visitor[$this->merchant_currency_code_col] = !empty($market_merchant_prices) ? $market_merchant_prices['currency_code'] : $insert_visitor[$this->admin_currency_code_col];
            $insert_visitor['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_visitor[$this->admin_currency_code_col];

            //In case of cluster ticket don't need to save data of this row
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                $visitor_ticket_rows_batch [] = $insert_visitor;
            }
            $insertedId = $visitor_tickets_id;
        }
        $quantity = 1;
        if ($group_price == '1') {
            $quantity = $group_quantity;
        }
        $temp_analytic_details = array(
            'visitor_group_no' => $insert_visitor['vt_group_no'],
            'supplier_id' => $insert_visitor['museum_id'],
            'supplier_name' => isset($insert_visitor['museum_name']) ? $insert_visitor['museum_name'] : '',
            'booking_date' => date("Y-m-d H:i:s", strtotime('+' . $insert_visitor['timezone'] . ' hours', strtotime($insert_visitor['visit_date_time']))),
            'reservation_date' => $insert_visitor['selected_date'] != '' ? $insert_visitor['selected_date'] : '',
            'distributor_id' => $insert_visitor['hotel_id'],
            'distributor_name' => $insert_visitor['hotel_name'] != '' ? $insert_visitor['hotel_name'] : '',
            'distributor_type' => $insert_visitor['distributor_type'] != '' ? $insert_visitor['distributor_type'] : '',
            'channel_id' => $insert_visitor['channel_id'] != '' ? $insert_visitor['channel_id'] : 0,
            'channel_name' => ($insert_visitor['channel_name'] != '') ? $insert_visitor['channel_name'] : '',
            'cashier_id' => $insert_visitor['cashier_id'] != '' ? $insert_visitor['cashier_id'] : '',
            'cashier_name' => $insert_visitor['cashier_name'] != '' ? $insert_visitor['cashier_name'] : '',
            'ticket_id' => $insert_visitor['ticketId'],
            'ticket_name' => $insert_visitor['ticket_title'],
            'reseller_id' => isset($insert_visitor['reseller_id']) ? $insert_visitor['reseller_id'] : '0',
            'reseller_name' => isset($insert_visitor['reseller_name']) ? $insert_visitor['reseller_name'] : ' ',
            'saledesk_id' => isset($insert_visitor['saledesk_id']) ? $insert_visitor['saledesk_id'] : '0',
            'saledesk_name' => isset($insert_visitor['saledesk_name']) ? $insert_visitor['saledesk_name'] : '',
            'tax_id' => $insert_visitor['tax_id'],
            'tax_value' => $insert_visitor['tax_value'],
            'ticket_type' => ($insert_visitor['tickettype_name'] != '') ? $insert_visitor['tickettype_name'] : 'Adult',
            'total_sold_count' => $quantity,
            'total_sold_amount_for_supplier' => 0,
            'total_sold_net_amount_for_supplier' => 0,
            'total_sold_amount_for_distributor' => $insert_visitor['partner_gross_price'],
            'total_sold_net_amount_for_distributor' => $insert_visitor['partner_net_price'],
            'country' => $insert_visitor['issuer_country_name'] != '' ? $insert_visitor['issuer_country_name'] : ''
        );
        //Combi discount row sarted
        if (trim($is_combi_discount) == '1') {
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "13");
            $insert_combi_data['id'] = $visitor_tickets_id;
            if(!empty($voucher_creation_date)){
                $insert_combi_data['voucher_creation_date'] = $voucher_creation_date;
             }
            $insert_combi_data['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
            $insert_combi_data['tp_payment_method']  = $tp_payment_method;
            $insert_combi_data['order_confirm_date'] = $order_confirm_date;  
            $insert_combi_data['transaction_id'] = $transaction_id;
            $insert_combi_data['visitor_invoice_id'] = $transaction_id;
            $insert_combi_data['visitor_group_no'] = $visitor_group_no;
            $insert_combi_data['ticket_booking_id'] = $ticket_booking_id;
            $insert_combi_data['vt_group_no'] = $visitor_group_no;
            $insert_combi_data['invoice_id'] = '';
            $insert_combi_data['ticketAmt'] = $ticketAmt;
            $insert_combi_data['hto_id']     = $hto_id;
            $insert_combi_data['is_prioticket'] = $is_prioticket;
            $insert_combi_data['targetlocation'] = $confirm_ticket_array['clustering_id'];
            $insert_combi_data['card_name'] = $confirm_ticket_array['discount_label'];
            $insert_combi_data['discount'] = $insert_visitor['discount'];
            $insert_combi_data['channel_id'] = $channel_id;
            $insert_combi_data['channel_name'] = $confirm_ticket_array['channel_name'];
            $insert_combi_data['financial_id'] = $confirm_ticket_array['financial_id'];
            $insert_combi_data['financial_name'] = $confirm_ticket_array['financial_name'];
            $insert_combi_data['selected_date'] = $selected_date;
            $insert_combi_data['booking_selected_date'] = $booking_selected_date;
            $insert_combi_data['from_time'] = $from_time;
            $insert_combi_data['to_time'] = $to_time;
            $insert_combi_data['slot_type'] = $slot_type;
            $insert_combi_data['reseller_id'] = $reseller_id;
            $insert_combi_data['reseller_name'] = $reseller_name;
            $insert_combi_data['is_refunded'] = $is_refunded;
            $insert_combi_data['saledesk_id'] = $saledesk_id;
            $insert_combi_data['saledesk_name'] = $saledesk_name;
            $insert_combi_data['partner_category_id'] = $partner_category_id;
            $insert_combi_data['partner_category_name'] = $partner_category_name;
            $insert_combi_data['museum_name'] = $museum_name;
            $insert_combi_data['museum_id'] = $museum_id;
            $insert_combi_data['hotel_name'] = $hotel_name;
            $insert_combi_data['primary_host_name'] = $primary_host_name;
            $insert_combi_data['hotel_id'] = $hotel_id;
            $insert_combi_data['shift_id'] = $shift_id;
            $insert_combi_data['pos_point_id'] = $pos_point_id;
            $insert_combi_data['pos_point_name'] = $pos_point_name;
            $insert_combi_data['cashier_id'] = $cashier_id;
            $insert_combi_data['cashier_name'] = $cashier_name;
            $insert_combi_data['ticketId'] = $ticketId;
            $insert_combi_data['ticketpriceschedule_id'] = $ticketpriceschedule_id;
            $insert_combi_data['tickettype_name'] = $tickettype_name;
            $insert_combi_data['ticket_title'] = $ticket_title;
            $insert_combi_data['partner_id'] = $hotel_id;
            $insert_combi_data['passNo'] = $passNo;
            $insert_combi_data['pass_type'] = $pass_type;
            $insert_combi_data['visit_date'] = $visit_date;
            $insert_combi_data['visit_date_time'] = $visit_date_time;
            $insert_combi_data['timezone'] = $timeZone;
            $insert_combi_data['paid'] = "1";
            $insert_combi_data['payment_method'] = ((isset($confirm_ticket_array['payment_method']) && $confirm_ticket_array['payment_method'] == 'coupon')) ? ucfirst($confirm_ticket_array['coupon_code']) : $payment_method;
            $insert_combi_data['all_ticket_ids'] = $voucher_reference;
            $insert_combi_data['captured'] = "1";
            $insert_combi_data['distributor_partner_id'] = $distributor_partner_id;
            $insert_combi_data['distributor_partner_name'] = $distributor_partner_name;
            $insert_combi_data['debitor'] = $visitor_debitor;
            $insert_combi_data['creditor'] = 'Credit';
            $insert_combi_data['partner_gross_price'] = $combi_discount_gross_amount;
            $insert_combi_data['order_currency_partner_gross_price'] = 0.00;
            $insert_combi_data['partner_gross_price_without_combi_discount'] = $combi_discount_gross_amount;
            $combi_net = round(($combi_discount_gross_amount * 100) / ($ticket_tax_value + 100), 2);
            $order_currency_combi_net = round(($order_currency_combi_discount_gross_amount * 100) / ($ticket_tax_value + 100), 2);
            $insert_combi_data['partner_net_price'] = $combi_net ;
            $insert_combi_data['order_currency_partner_net_price'] = 0.00;
            $insert_combi_data['partner_net_price_without_combi_discount'] =  $combi_net ;
            $insert_combi_data['tax_id'] = $ticket_tax_id;
            $insert_combi_data['tax_value'] = $ticket_tax_value;
            $insert_combi_data['invoice_status'] = $invoice_status;
            $insert_combi_data['transaction_type_name'] = "Combi discount";
            $insert_combi_data['paymentMethodType'] = $paymentMethodType;
            $insert_combi_data['row_type'] = "13";
            $insert_combi_data['nights'] = $is_iticket_product;
            $insert_combi_data['ticket_status'] = $insert_visitor['ticket_status'];
            $insert_combi_data['merchant_admin_id'] =  $merchant_admin_id ;
            $insert_combi_data['merchant_admin_name'] = $merchant_admin_name;
            $insert_combi_data['market_merchant_id'] = $market_merchant_id;
            $insert_combi_data['isBillToHotel'] = $billtohotel;
            $insert_combi_data['channel_type'] = $channel_type;
            $insert_combi_data['activation_method'] = $insert_visitor['activation_method'];
            $insert_combi_data['service_cost_type'] = "1";
            $insert_combi_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
            $insert_combi_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
            $insert_combi_data['tax_name'] = $museum_tax_name;
            $insert_combi_data['used'] = $is_used;
            $insert_combi_data['action_performed'] = $confirm_ticket_array['action_performed'];
            $insert_combi_data['updated_at'] = $current_date;
            $insert_combi_data['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
            $insert_combi_data['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
            $insert_combi_data['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
            $insert_combi_data['group_type_ticket'] = $group_type_ticket;
            $insert_combi_data['group_price'] = $group_price;
            $insert_combi_data['group_quantity'] = $group_quantity;
            $insert_combi_data['group_linked_with'] = $group_linked_with;
            $insert_combi_data['extra_discount'] = $extra_discount;
            $insert_combi_data['supplier_currency_code'] = $confirm_ticket_array['supplier_currency_code'];
            $insert_combi_data['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
            $insert_combi_data['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
            $insert_combi_data['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
            $insert_combi_data['currency_rate'] = $confirm_ticket_array['currency_rate'];
            $insert_combi_data['payment_date'] = $payment_date;
            $insert_combi_data['adyen_status'] = $pricing_level;
            $insert_combi_data['order_updated_cashier_id'] = $order_updated_cashier_id;
            $insert_combi_data['order_updated_cashier_name'] = $order_updated_cashier_name;
            $insert_combi_data['redeem_method'] = $redeem_method;
            if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                $insert_combi_data['voucher_updated_by'] = $voucher_updated_by;
                $insert_combi_data['voucher_updated_by_name'] = $voucher_updated_by_name;
            }
            
            if (!empty($is_custom_setting) && $is_custom_setting > 0) {
                $insert_combi_data['external_product_id'] = $external_product_id;
            } else if ('4' == $mec_is_combi) {
                /* external_product_id to be added (23-12-2022) */
                $insert_combi_data['external_product_id'] = $related_product_id;
            }
            $insert_combi_data['account_number'] = $account_number;
            $insert_combi_data['chart_number'] = $chart_number;
            $insert_combi_data['is_voucher'] = $is_voucher;
            if ($is_voucher == '1') {
                $insert_combi_data['activation_method'] = '10';
            }
            //In case of cluster ticket don't need to save data of this row            
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                
                $visitor_ticket_rows_batch [] = $insert_combi_data;
            }
        }

        if (isset($confirm_ticket_array['discount_code']) && $confirm_ticket_array['discount_code'] != '' && $ticketAmt > 0) {
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "14");
            $insert_discount_code_data['id'] = $visitor_tickets_id;
            if(!empty($voucher_creation_date)){
                $insert_discount_code_data['voucher_creation_date'] = $voucher_creation_date;
             }
            $insert_discount_code_data['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
            $insert_discount_code_data['tp_payment_method']  = $tp_payment_method;            
            $insert_discount_code_data['discount'] = $insert_visitor['discount'];
            $insert_discount_code_data['order_confirm_date'] = $order_confirm_date; 
	        $insert_discount_code_data['payment_date'] = $payment_date; 
            $insert_discount_code_data['transaction_id'] = $transaction_id;
            $insert_discount_code_data['visitor_invoice_id'] = $transaction_id;
            $insert_discount_code_data['visitor_group_no'] = $visitor_group_no;
            $insert_discount_code_data['ticketAmt'] = $ticketAmt;
            $insert_discount_code_data['invoice_id'] = '';
            $insert_discount_code_data['is_prioticket'] = $is_prioticket;
            $insert_discount_code_data['targetlocation'] = $confirm_ticket_array['clustering_id'];
            $insert_discount_code_data['card_name'] = $confirm_ticket_array['discount_label'];
            $insert_discount_code_data['channel_id'] = $channel_id;
            $insert_discount_code_data['channel_name'] = $confirm_ticket_array['channel_name'];
            $insert_discount_code_data['financial_id'] = $confirm_ticket_array['financial_id'];
            $insert_discount_code_data['financial_name'] = $confirm_ticket_array['financial_name'];
            $insert_discount_code_data['reseller_id'] = $reseller_id;
            $insert_discount_code_data['reseller_name'] = $reseller_name;
            $insert_discount_code_data['saledesk_id'] = $saledesk_id;
            $insert_discount_code_data['saledesk_name'] = $saledesk_name;
            $insert_discount_code_data['museum_name'] = $museum_name;
            $insert_discount_code_data['partner_category_id'] = $partner_category_id;
            $insert_discount_code_data['partner_category_name'] = $partner_category_name;
            $insert_discount_code_data['museum_id'] = $museum_id;
            $insert_discount_code_data['is_refunded'] = $is_refunded;
            $insert_discount_code_data['hotel_name'] = $hotel_name;
            $insert_discount_code_data['primary_host_name'] = $primary_host_name;
            $insert_discount_code_data['distributor_partner_id'] = $distributor_partner_id;
            $insert_discount_code_data['distributor_partner_name'] = $distributor_partner_name;
            $insert_discount_code_data['hotel_id'] = $hotel_id;
            $insert_discount_code_data['ticketId'] = $ticketId;
            $insert_discount_code_data['ticketpriceschedule_id'] = $ticketpriceschedule_id;
            $insert_discount_code_data['ticket_title'] = $ticket_title;
            $insert_discount_code_data['partner_id'] = $hotel_id;
            $insert_discount_code_data['passNo'] = $passNo;
            $insert_discount_code_data['pass_type'] = $pass_type;
            $insert_discount_code_data['visit_date'] = $visit_date;
            $insert_discount_code_data['timezone'] = $timeZone;
            $insert_discount_code_data['paid'] = "1";
            $insert_discount_code_data['payment_method'] = $confirm_ticket_array['discount_code'];
            $insert_discount_code_data['all_ticket_ids'] = $voucher_reference;
            $insert_discount_code_data['captured'] = "1";
            $insert_discount_code_data['debitor'] = $visitor_debitor;
            $insert_discount_code_data['creditor'] = 'Credit';
            $insert_discount_code_data['extra_discount'] = $extra_discount;
            $insert_discount_code_data['supplier_currency_code'] = $confirm_ticket_array['supplier_currency_code'];
            $insert_discount_code_data['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
            $insert_discount_code_data['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
            $insert_discount_code_data['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
            $insert_discount_code_data['currency_rate'] = $confirm_ticket_array['currency_rate'];
            $insert_discount_code_data['selected_date'] = $selected_date;
            $insert_discount_code_data['booking_selected_date'] = $booking_selected_date;
            $insert_discount_code_data['from_time'] = $from_time;
            $insert_discount_code_data['to_time'] = $to_time;
            $insert_discount_code_data['slot_type'] = $slot_type;
            $insert_discount_code_data['tickettype_name'] = $tickettype_name;
            $insert_discount_code_data['adyen_status'] = $pricing_level;
            $insert_discount_code_data['order_updated_cashier_id'] = $order_updated_cashier_id;
            $insert_discount_code_data['order_updated_cashier_name'] = $order_updated_cashier_name;
            $insert_discount_code_data['redeem_method'] = $redeem_method;
            $discount = 0;
            $promocode = array();
            $promocode = $this->common_model->find('coupons', array('select' => 'LCASE(coupon_code) as coupon_code, discount_amount,is_discount_per_ticket,discount_type', 'where' => 'discount_amount > 0 and LCASE(coupon_code) = "' . strtolower($confirm_ticket_array['discount_code']) . '"'));
            if (isset($promocode[0]['coupon_code']) && $promocode[0]['discount_amount'] != '') {
                $discount = $promocode[0]['discount_amount'];
            }

            $insert_discount_code_data['partner_gross_price'] = $discount; // Discount amount for adult
            $insert_discount_code_data['partner_gross_price_without_combi_discount'] = $discount ;
            $discount_net = round(($discount * 100) / ($ticket_tax_value + 100), 2);
            $insert_discount_code_data['partner_net_price'] = $discount_net ;
            $insert_discount_code_data['partner_net_price_without_combi_discount'] =  $discount_net;
            $insert_discount_code_data['tax_id'] = $ticket_tax_id;
            $insert_discount_code_data['tax_value'] = $ticket_tax_value;
            $insert_discount_code_data['invoice_status'] = $invoice_status;
            $insert_discount_code_data['transaction_type_name'] = "Discount code";
            $insert_discount_code_data['paymentMethodType'] = $paymentMethodType;
            $insert_discount_code_data['row_type'] = "14";
            $insert_discount_code_data['nights'] = $is_iticket_product;
            $insert_discount_code_data['ticket_status'] = $insert_visitor['ticket_status'];
            $insert_discount_code_data['merchant_admin_id'] = $merchant_admin_id;
            $insert_discount_code_data['merchant_admin_name'] = $merchant_admin_name ;
            $insert_discount_code_data['market_merchant_id'] = $market_merchant_id;
            $insert_discount_code_data['isBillToHotel'] = $billtohotel;
            $insert_discount_code_data['activation_method'] = $insert_visitor['activation_method'];
            $insert_discount_code_data['service_cost_type'] = "1";
            $insert_discount_code_data['used'] = $is_used;
            $insert_discount_code_data['action_performed'] = $confirm_ticket_array['action_performed'];
            $insert_discount_code_data['updated_at'] = $current_date;
            $insert_discount_code_data['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
            $insert_discount_code_data['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
            $insert_discount_code_data['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
            $insert_discount_code_data['group_type_ticket'] = $group_type_ticket;
            $insert_discount_code_data['group_price'] = $group_price;
            $insert_discount_code_data['group_quantity'] = $group_quantity;
            $insert_discount_code_data['group_linked_with'] = $group_linked_with;
            $insert_discount_code_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
            $insert_discount_code_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
            if (!empty($is_custom_setting) && $is_custom_setting > 0) {
                $insert_discount_code_data['external_product_id'] = $external_product_id;
            } else if ('4' == $mec_is_combi) {
                /* external_product_id to be added (23-12-2022) */
                $insert_combi_data['external_product_id'] = $related_product_id;
            }
            $insert_discount_code_data['is_voucher'] = $is_voucher;
            $insert_discount_code_data['account_number'] = $account_number;
            $insert_discount_code_data['chart_number'] = $chart_number;
            if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                $insert_discount_code_data['voucher_updated_by'] = $voucher_updated_by;
                $insert_discount_code_data['voucher_updated_by_name'] = $voucher_updated_by_name;
            }
            
            if ($is_voucher == '1') {
                $insert_discount_code_data['activation_method'] = '10';
            }
            //In case of cluster ticket don't need to save data of this row            
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
               
                $visitor_ticket_rows_batch [] = $insert_discount_code_data;
            }
        }

        //Service-cost-row-starts
        if (isset($confirm_ticket_array['service_gross']) && $confirm_ticket_array['pertransaction'] == '0' && $confirm_ticket_array['service_gross'] > 0 && $confirm_ticket_array['service_cost_type'] != 0) {
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "12");
            $insert_service_data['id'] = $visitor_tickets_id;
            if(!empty($voucher_creation_date)){
                $insert_service_data['voucher_creation_date'] = $voucher_creation_date;
             }
            $insert_service_data['is_prioticket'] = $is_prioticket;
            $insert_service_data['targetlocation'] = $confirm_ticket_array['clustering_id'];
            $insert_service_data['card_name'] = $confirm_ticket_array['discount_label'];
            $insert_service_data['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
            $insert_service_data['discount'] = $insert_visitor['discount'];
            $insert_service_data['tp_payment_method']  = $tp_payment_method;
            $insert_service_data['order_confirm_date'] = $order_confirm_date; 
            $insert_service_data['payment_date'] = $payment_date; 
            $insert_service_data['transaction_id'] = $transaction_id;
            $insert_service_data['visitor_invoice_id'] = $transaction_id;
            $insert_service_data['ticket_booking_id'] = $ticket_booking_id;
            $insert_service_data['visitor_group_no'] = $visitor_group_no;
            $insert_service_data['vt_group_no'] = $visitor_group_no;
            $insert_service_data['visit_date_time'] = $visit_date_time;
            $insert_service_data['invoice_id'] = '';
            $insert_service_data['channel_id'] = $channel_id;
            $insert_service_data['ticketAmt'] = $ticketAmt;
            $insert_service_data['is_refunded'] = $is_refunded;
            $insert_service_data['channel_name'] = $confirm_ticket_array['channel_name'];
            $insert_service_data['financial_id'] = $confirm_ticket_array['financial_id'];
            $insert_service_data['financial_name'] = $confirm_ticket_array['financial_name'];
            $insert_service_data['reseller_id'] = $reseller_id;
            $insert_service_data['reseller_name'] = $reseller_name;
            $insert_service_data['saledesk_id'] = $saledesk_id;
            $insert_service_data['saledesk_name'] = $saledesk_name;
            $insert_service_data['museum_name'] = $museum_name;
            $insert_service_data['partner_category_id'] = $partner_category_id;
            $insert_service_data['partner_category_name'] = $partner_category_name;
            $insert_service_data['distributor_partner_id'] = $distributor_partner_id;
            $insert_service_data['distributor_partner_name'] = $distributor_partner_name;
            $insert_service_data['museum_id'] = $museum_id;
            $insert_service_data['hotel_name'] = $hotel_name;
            $insert_service_data['primary_host_name'] = $primary_host_name;
            $insert_service_data['hotel_id'] = $hotel_id;
            $insert_service_data['shift_id'] = $shift_id;
            $insert_service_data['pos_point_id'] = $pos_point_id;
            $insert_service_data['pos_point_name'] = $pos_point_name;
            $insert_service_data['cashier_id'] = $cashier_id;
            $insert_service_data['cashier_name'] = $cashier_name;
            $insert_service_data['ticketId'] = $ticketId;
            $insert_service_data['ticketpriceschedule_id'] = $ticketpriceschedule_id;
            $insert_service_data['ticket_title'] = $ticket_title;
            $insert_service_data['partner_id'] = $hotel_id;
            $insert_service_data['passNo'] = $passNo;
            $insert_service_data['pass_type'] = $pass_type;
            $insert_service_data['visit_date'] = $visit_date;
            $insert_service_data['timezone'] = $timeZone;
            $insert_service_data['paid'] = "1";
            $insert_service_data['payment_method'] = $payment_method;
            $insert_service_data['order_updated_cashier_id'] = $order_updated_cashier_id;
            $insert_service_data['order_updated_cashier_name'] = $order_updated_cashier_name;
            $insert_service_data['redeem_method'] = $redeem_method;
            if( isset($confirm_ticket_array['payment_method']) && $confirm_ticket_array['payment_method'] == 'coupon' ) {
                $insert_service_data['payment_method'] = ucfirst($confirm_ticket_array['coupon_code']);
            }
            $insert_service_data['all_ticket_ids'] = $voucher_reference;
            $insert_service_data['captured'] = "1";
            $insert_service_data['debitor'] = $visitor_debitor;
            $insert_service_data['creditor'] = $visitor_creditor;
            $insert_service_data['tp_payment_method'] = $tp_payment_method;
            $insert_service_data['order_confirm_date'] = $order_confirm_date;
            $insert_service_data['payment_date'] = $payment_date;
            $insert_service_data['tax_name'] = $ticket_tax_name;
            $insert_service_data['partner_gross_price'] = $confirm_ticket_array['service_gross'] ;
            $insert_service_data['order_currency_partner_gross_price'] = 0.00;
            $insert_service_data['partner_gross_price_without_combi_discount'] = $confirm_ticket_array['service_gross'] ;
            $service_net = round(($confirm_ticket_array['service_gross'] * 100) / ($ticket_tax_value + 100), 2);
            $insert_service_data['partner_net_price'] =  $service_net;
            $insert_service_data['order_currency_partner_net_price'] = 0.00;
            $insert_service_data['partner_net_price_without_combi_discount'] = $service_net ;
            $insert_service_data['tax_id'] = $ticket_tax_id ;
            $insert_service_data['tax_value'] = $ticket_tax_value;
            $insert_service_data['invoice_status'] = $invoice_status;
            $insert_service_data['transaction_type_name'] = "Service cost";
            $insert_service_data['paymentMethodType'] = $paymentMethodType;
            $insert_service_data['row_type'] = "12";
            $insert_service_data['nights'] = $is_iticket_product;
            $insert_service_data['ticket_status'] = $insert_visitor['ticket_status'];
            $insert_service_data['version'] = $version;
            $insert_service_data['merchant_admin_id'] = $merchant_admin_id ;
            $insert_service_data['merchant_admin_name'] =  $merchant_admin_name;
            $insert_service_data['market_merchant_id'] = $market_merchant_id;
            $insert_service_data['isBillToHotel'] = $billtohotel;
            $insert_service_data['activation_method'] = $insert_visitor['activation_method'];
            $insert_service_data['service_cost_type'] = "1";
            $insert_service_data['used'] = $is_used;
            $insert_service_data['group_type_ticket'] = $group_type_ticket;
            $insert_service_data['group_price'] = $group_price;
            $insert_service_data['group_quantity'] = $group_quantity;
            $insert_service_data['group_linked_with'] = $group_linked_with;
            $insert_service_data['action_performed'] = $confirm_ticket_array['action_performed'];
            $insert_service_data['updated_at'] = $current_date;
            $insert_service_data['supplier_currency_code'] = $confirm_ticket_array['supplier_currency_code'];
            $insert_service_data['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
            $insert_service_data['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
            $insert_service_data['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
            $insert_service_data['currency_rate'] = $confirm_ticket_array['currency_rate'];
            $insert_service_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
            $insert_service_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));            
            $insert_service_data['extra_discount'] = $extra_discount;
            $insert_service_data['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
            $insert_service_data['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
            $insert_service_data['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
            $insert_service_data['selected_date'] = $selected_date;
            $insert_service_data['booking_selected_date'] = $booking_selected_date;
            $insert_service_data['from_time'] = $from_time;
            $insert_service_data['to_time'] = $to_time;
            $insert_service_data['slot_type'] = $slot_type;
            $insert_service_data['tickettype_name'] = $tickettype_name;
            $insert_service_data['adyen_status'] = $pricing_level;
            if (!empty($is_custom_setting) && $is_custom_setting > 0) {
                $insert_service_data['external_product_id'] = $external_product_id;
            } else if ('4' == $mec_is_combi) {
                /* external_product_id to be added (23-12-2022) */
                $insert_combi_data['external_product_id'] = $related_product_id;
            }
            $insert_service_data['is_voucher'] = $is_voucher;
            $insert_service_data['account_number'] = $account_number;
            $insert_service_data['chart_number'] = $chart_number;
            if ($is_voucher == '1') {
                $insert_service_data['activation_method'] = '10';
            }
            if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                $insert_service_data['voucher_updated_by'] = $voucher_updated_by;
                $insert_service_data['voucher_updated_by_name'] = $voucher_updated_by_name;
            }
            //In case of cluster ticket don't need to save data of this row
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                $visitor_ticket_rows_batch [] = $insert_service_data;
            }
        }

        $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "02");

        $museum_tax_id = $museum_tax_id ? $museum_tax_id : 0;
        $museum_tax_value = $museum_tax_value == '' ? '0.00' : $museum_tax_value;
        $museumCommission = $museumCommission ? $museumCommission : 0.00;
        $museumNetPrice = $museumNetPrice ? $museumNetPrice : 0.00;
        $hotelNetPrice = $hotelNetPrice ? $hotelNetPrice : 0.00;
        
        $museum_invoice_id = '';
        $museum_debitor = $museum_name;
        $museum_creditor = 'Credit';
        if (isset($confirm_ticket_array['prepaid_type'])) {
            $insert_museum['activation_method'] = $confirm_ticket_array['prepaid_type'];
            if ($confirm_ticket_array['prepaid_type'] == '2') {
                $insert_museum['time_based_done'] = "1";
            }
        } else if ($billtohotel == '0') {
            $insert_museum['activation_method'] = "1";
        } else {
            $insert_museum['activation_method'] = "3";
        }

        $insert_museum['is_prioticket'] = $is_prioticket;
        $insert_museum['targetlocation'] = $confirm_ticket_array['clustering_id'];
        $insert_museum['card_name'] = $confirm_ticket_array['discount_label'];
        if(!empty($voucher_creation_date)){
            $insert_museum['voucher_creation_date'] = $voucher_creation_date;
        }
        $insert_museum['id'] = $visitor_tickets_id;
        $insert_museum['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
        $insert_museum['slot_type'] = $slot_type;
        $insert_museum['tp_payment_method']  = $tp_payment_method;
        $insert_museum['order_confirm_date'] = $order_confirm_date; 
	    $insert_museum['payment_date'] = $payment_date; 
        $insert_museum['transaction_id'] = $transaction_id;
        $insert_museum['visitor_invoice_id'] = $transaction_id;
        $insert_museum['invoice_id'] = $museum_invoice_id;
        $insert_museum['channel_id'] = $channel_id;
        $insert_museum['is_refunded'] = $is_refunded;
        $insert_museum['channel_name'] = $confirm_ticket_array['channel_name'];
        $insert_museum['financial_id'] = $confirm_ticket_array['financial_id'];
        $insert_museum['financial_name'] = $confirm_ticket_array['financial_name'];

        $insert_museum['ticketId'] = $ticketId;
        if (isset($confirm_ticket_array['invoice_type'])) {
            $insert_museum['invoice_type'] = $confirm_ticket_array['invoice_type'];
        }
        $insert_museum['extra_discount'] = $extra_discount;
        $insert_museum['ticket_title'] = $ticket_title;
        $insert_museum['all_ticket_ids'] = $voucher_reference;
        $insert_museum['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
        $insert_museum['version'] = $version;
        $insert_museum['ticketpriceschedule_id'] = $ticketpriceschedule_id;
        $insert_museum['user_name'] = $userdata->guest_names;
        $insert_museum['partner_id'] = $museum_id;
        $insert_museum['partner_name'] = $museum_name;
        $insert_museum['is_custom_setting'] = $pax;
        $insert_museum['museum_name'] = $museum_name;
        $insert_museum['reseller_id'] = $reseller_id;
        $insert_museum['reseller_name'] = $reseller_name;
        $insert_museum['saledesk_id'] = $saledesk_id;
        $insert_museum['saledesk_name'] = $saledesk_name;
        $insert_museum['partner_category_id'] = $partner_category_id;
        $insert_museum['partner_category_name'] = $partner_category_name;
        $insert_museum['museum_id'] = $museum_id;
        $insert_museum['hotel_name'] = $hotel_name;
        $insert_museum['primary_host_name'] = $primary_host_name;
        $insert_museum['hotel_id'] = $hotel_id;
        $insert_museum['shift_id'] = $shift_id;
        $insert_museum['pos_point_id'] = $pos_point_id;
        $insert_museum['pos_point_name'] = $pos_point_name;
        $insert_museum['passNo'] = $passNo;
        $insert_museum['pass_type'] = $pass_type;
        $insert_museum['ticketAmt'] = $ticketAmt;
        $insert_museum['vt_group_no'] = $visitor_group_no;
        $insert_museum['visit_date_time'] = $visit_date_time;
        $insert_museum['visit_date'] = $visit_date;
        $insert_museum['ticketType'] = $ticketType;
        $insert_museum['distributor_partner_id'] = $distributor_partner_id;
        $insert_museum['distributor_partner_name'] = $distributor_partner_name;
        $insert_museum['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
        $insert_museum['tickettype_name'] = $tickettype_name;
        $insert_museum['paid'] = "0";
        $insert_museum['discount'] = $insert_visitor['discount'];
        $insert_museum['is_block'] = $is_block;
        $insert_museum['isDiscountInPercent'] = $ispercenttype;
        $insert_museum['isBillToHotel'] = $billtohotel;
        $insert_museum['debitor'] = $museum_debitor;
        $insert_museum['creditor'] = $museum_creditor;
        $insert_museum['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
        $insert_museum['order_updated_cashier_id'] = $order_updated_cashier_id;
        $insert_museum['order_updated_cashier_name'] = $order_updated_cashier_name;
        $insert_museum['redeem_method'] = $redeem_method;
        $new_museumCommission = $insert_visitor['partner_gross_price'] - round($combi_discount_gross_amount, 2) - round($extra_gross_discount, 2);
        $new_museum_netCommission = 0;
        $set_museum_flag = 0;
        if ($is_resale_percentage == 1) {
            // if museum cost is setup in %
            $resale_percentage = $ticket_level_commissions->resale_percentage;
            $museumCommission = round((($insert_visitor['partner_gross_price'] * $resale_percentage) / 100), 2);
        }
        
        $new_museum_netCommission = $insert_visitor['partner_net_price'] - round($combi_net, 2) - round($extra_net_discount, 2);
        if ($commission_on_sale_price == 1) {
            // in case of distributor toggle button enabled
            if ($ticket_level_commissions->is_hotel_prepaid_commission_percentage == 0) {
                $hotelNetPrice = $ticket_level_commissions->hotel_commission_net_price;
                $debugLogs['hotelNetPrice_2'] = $hotelNetPrice;
            } else {
                $hotelNetPrice = round((($insert_visitor['partner_net_price'] * $hotel_commission_percentage) / 100), 2);
                $debugLogs['hotelNetPrice_1'] = $hotelNetPrice;
            }
            if(isset($ticket_level_commissions->hotel_commission_tax_value) & !empty($ticket_level_commissions->hotel_commission_tax_value)){
                $calculated_hotel_commission = $hotelNetPrice + round((($hotelNetPrice * $ticket_level_commissions->hotel_commission_tax_value) / 100), 2);
            } else {
                $calculated_hotel_commission = round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2);
            }
            $hgsnetprice = round(($subtotal - $hotelNetPrice), 2);
            $debugLogs['CASE_6 subtotal-hotelNetPrice'] = $hgsnetprice;
            $hotel_net_price_without_combi_discount = round((($subtotal_with_combi_discount * $hotel_commission_percentage) / 100), 2);
            $hotel_gross_price_without_combi_discount = $hotel_net_price_without_combi_discount + round((($hotel_net_price_without_combi_discount * $ticket_level_commissions->hotel_commission_tax_value) / 100), 2);
            $hgs_net_price_without_combi_discount = round((($subtotal_with_combi_discount * $hgs_commission_percentage) / 100), 2);
            $new_hotelCommission = $insert_visitor['partner_net_price'] - round($combi_net, 2) - round($extra_net_discount, 2);
            
            $new_museumCommission -= $calculated_hotel_commission;
            $museumNetPrice += $resale_net_variation_amount;
            $museumCommission += $resale_variation_amount;
            //if run time hotel commission greater than existing hotel commission
            if ($hotelNetPrice >= $new_hotelCommission){
                $hotelNetPrice = $new_hotelCommission;
                $calculated_hotel_commission = round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2) + $combi_discount_gross_amount; 
                $museumCommission = 0;
                $museumNetPrice = 0;
                $set_museum_flag = 1;
                $hgsnetprice = 0;
                $debugLogs['CASE_5'] = $hgsnetprice;
                $hgsCommission = 0;
                $calculated_hgs_commission = 0;
                $affiliate_data = array();
            } else if ($museumCommission >= $new_museumCommission) {
                // calaculate run time museum fee
                $museumNetPrice = $new_museum_netCommission - $hotelNetPrice;
                $museumCommission = round((($museumNetPrice * (100 + $ticket_tax_value)) / 100), 2);
                $set_museum_flag =1;
                $hgsnetprice = 0;
                $debugLogs['CASE_4'] = $hgsnetprice;
                $hgsCommission = 0;
                $calculated_hgs_commission = 0;
                $affiliate_data = array();
            }
            if($merchant_fees_assigned == '1'){
                // calaculate run time merchant fee
                
                $new_merchantfee = $new_museum_netCommission - $hotelNetPrice - $museumNetPrice;
                if ($ticket_level_commissions->merchant_net_commission > $new_merchantfee) {
                    $ticket_level_commissions->merchant_net_commission = $new_merchantfee;
                    $ticket_level_commissions->merchant_gross_commission = round((($ticket_level_commissions->merchant_net_commission * (100 + $ticket_tax_value)) / 100), 2);
                }
            }
        }
      
        if(($museumCommission >= $new_museumCommission && $commission_on_sale_price == 0)) {
            $museumCommission = $new_museumCommission;
            $this->createLog('visitor_info.php', 'step4', array('$museumNetPrice'=> $museumNetPrice, '$new_museum_netCommission' => $new_museum_netCommission));
            if ($set_museum_flag == 0 && $confirm_ticket_array['is_addon_ticket'] != 2) {
                $museumNetPrice = $new_museum_netCommission;
            }
            $museumCommission = round((($museumNetPrice * (100 + $ticket_tax_value)) / 100), 2);
            if (!empty($ctd_currency) && !empty($ctd_clc_supplier_prices['currency_code']) && $ctd_currency != $ctd_clc_supplier_prices['currency_code'] && $confirm_ticket_array['is_addon_ticket'] == 2) {
                // if admin and subticket currency is different
                $insert_museum['partner_net_price'] = 0.00;        
                $insert_museum['partner_gross_price'] = 0.00;            
                $insert_museum['partner_net_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_net_commission'];
                $insert_museum['partner_gross_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_gross_commission'];
                $insert_museum['supplier_currency_code'] = $ctd_clc_supplier_prices['currency_code'];
            } else {
                if ($commission_on_sale_price == 1) {
                    $insert_museum['partner_net_price'] = $museumNetPrice ;        
                    $insert_museum['partner_gross_price'] = $museumCommission; 
                } else {
                    $insert_museum['partner_net_price'] = $museumNetPrice + $resale_net_variation_amount;        
                    $insert_museum['partner_gross_price'] = $museumCommission + $resale_variation_amount; 
                }          
                $insert_museum['partner_net_price_without_combi_discount'] = $insert_museum['partner_net_price'];
                $insert_museum['partner_gross_price_without_combi_discount'] = $insert_museum['partner_gross_price'];
                if ($confirm_ticket_array['is_addon_ticket'] == 2 && isset($combi_clc_supplier_prices['museum_net_commission'])) {
                    $insert_museum['partner_net_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_net_commission'];
                    $insert_museum['partner_gross_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_gross_commission'];
                }
                $insert_museum['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_museum[$this->admin_currency_code_col];
            }
            
            $calculated_hotel_commission = 0;
            $hotelNetPrice = 0;
            $hgsnetprice = 0;
            $hotelCommission = 0;
            $hgsCommission = 0;
            $calculated_hgs_commission = 0;
            $debugLogs['CASE_3'] = $hgsnetprice;
        } else {  
            if ($set_museum_flag == 0 && $confirm_ticket_array['is_addon_ticket'] != 2) {
                //$museumNetPrice = $new_museum_netCommission;
            }
            $museumCommission = round((($museumNetPrice * (100 + $ticket_tax_value)) / 100), 2);
            if (!empty($ctd_currency) && !empty($ctd_clc_supplier_prices['currency_code']) && ($ctd_currency != $ctd_clc_supplier_prices['currency_code']) && $confirm_ticket_array['is_addon_ticket'] == 2) {
                // if admin and subticket currency is different

                $insert_museum['partner_net_price'] = 0.00;        
                $insert_museum['partner_gross_price'] = 0.00;            
                $insert_museum['partner_net_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_net_commission'];
                $insert_museum['partner_gross_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_gross_commission'];
                $insert_museum['supplier_currency_code'] = $ctd_clc_supplier_prices['currency_code'];
            } else {
                if ($commission_on_sale_price == 1) {
                    $insert_museum['partner_net_price'] = $museumNetPrice ;        
                    $insert_museum['partner_gross_price'] = $museumCommission; 
                } else {
                    $insert_museum['partner_net_price'] = $museumNetPrice + $resale_net_variation_amount;        
                    $insert_museum['partner_gross_price'] = $museumCommission + $resale_variation_amount; 
                }
                $insert_museum['partner_net_price_without_combi_discount'] = $museumNetPrice ;
                $insert_museum['partner_gross_price_without_combi_discount'] = $museumCommission;
                if ($confirm_ticket_array['is_addon_ticket'] == 2 && isset($combi_clc_supplier_prices['museum_net_commission'])) {
                    $insert_museum['partner_net_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_net_commission'];
                    $insert_museum['partner_gross_price_without_combi_discount'] = $combi_clc_supplier_prices['museum_gross_commission'];
                }
                $insert_museum['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_museum[$this->admin_currency_code_col];
            }
        }
        $debugLogs['CASE_21 museumCommission'] = $museumCommission;
        $new_merchant_fee = $insert_visitor['partner_gross_price'] - round($combi_discount_gross_amount, 2) - round($extra_gross_discount, 2) - $museumCommission;
        $debugLogs['CASE_21.1 new_merchant_fee'] = $new_merchant_fee;
        $debugLogs['CASE_21.2 merchant_gross_commission'] = $ticket_level_commissions->merchant_gross_commission;
        if ($new_merchant_fee < 0) {
            $ticket_level_commissions->merchant_net_commission = 0;
            $ticket_level_commissions->merchant_gross_commission = 0;
        }
        if ($new_merchant_fee <= $ticket_level_commissions->merchant_gross_commission && $ticket_level_commissions->merchant_gross_commission > 0) {
            $debugLogs['CASE_21.3 check merchant'] = $new_merchant_fee."_".$ticket_level_commissions->merchant_gross_commission;
            $ticket_level_commissions->merchant_net_commission = round((($new_merchant_fee * 100) / ($ticket_tax_value + 100)),2);
            $ticket_level_commissions->merchant_gross_commission = $new_merchant_fee;
            $calculated_hotel_commission = 0;
            $hotelNetPrice = 0;
            $hgsnetprice = 0;
            $hotelCommission = 0;
            $hgsCommission = 0;
            $calculated_hgs_commission = 0;
            $debugLogs['CASE_2'] = $hgsnetprice;
        }
        $currency_museumCommission = round($museumCommission * $currency_rate,2);
        $currency_museumNetPrice = round($museumNetPrice * $currency_rate,2);
        if ($currency_amount_to_deduct_from_museum_comission != 0) {
            $currency_calculated_hotel_commission = 0;
            $currency_hotelNetPrice = 0;
            $currency_hgsnetprice = 0;
            $currency_calculated_hgs_commission = 0;
        }
        if ($is_different_currency == 1) {

            $distributor_currency_museumCommission = $currency_museumCommission;
            $distributor_currency_museumNetPrice = $currency_museumNetPrice;
            $distributor_currency_hotel_price = $currency_calculated_hotel_commission;
            $distributor_currency_hotel_net_price = $currency_hotelNetPrice;
        } else {
            $distributor_currency_museumCommission = ($museumNetPrice * (100 + $museum_tax_value)) / 100;            
            $distributor_currency_museumNetPrice = $museumNetPrice;
            $distributor_currency_hotel_price = $calculated_hotel_commission;
            $distributor_currency_hotel_net_price = $hotelNetPrice;
        }
        $insert_museum['order_currency_partner_net_price'] = 0.00;   
        $insert_museum['order_currency_partner_gross_price'] = 0.00;
        $insert_museum['isCommissionInPercent'] = "0";
        $insert_museum['tax_id'] = $museum_tax_id ;
        $insert_museum['tax_value'] = $museum_tax_value;
        $insert_museum['tax_name'] = $museum_tax_name;
        $insert_museum['timezone'] = $timeZone;
        $insert_museum['invoice_status'] = $invoice_status;
        $insert_museum['row_type'] = "2";
        $insert_museum['nights'] = $is_iticket_product;
        $insert_museum['ticket_status'] = $insert_visitor['ticket_status'];
        $insert_museum['merchant_admin_id'] =  $merchant_admin_id ;
        $insert_museum['merchant_admin_name'] =  $merchant_admin_name ;
        $insert_museum['market_merchant_id'] = $market_merchant_id;        
        $insert_museum['targetcity'] = $targetcity;
        $insert_museum['paymentMethodType'] = $paymentMethodType;
        $insert_museum['service_name'] = SERVICE_NAME;
        $insert_museum['transaction_type_name'] = "Ticket cost";
        $insert_museum['updated_by_id'] = $resuid;
        $insert_museum['updated_by_username'] = $resfname . ' ' . $reslname;
        $insert_museum['voucher_updated_by'] = $resuid;
        $insert_museum['voucher_updated_by_name'] = $resfname . ' ' . $reslname;
        $insert_museum['redeem_method'] =$redeem_method;
        $insert_museum['cashier_id'] = $cashier_id;
        $insert_museum['cashier_name'] = $cashier_name;
        $insert_museum['issuer_country_code'] = $country;
        $insert_museum['used'] = $is_used;
        $insert_museum['ticket_booking_id'] = $ticket_booking_id;
        $insert_museum['without_elo_reference_no'] = $without_elo_reference_no;
        $insert_museum['extra_text_field_answer'] = $extra_text_field_answer;
        $insert_museum['is_voucher'] = $is_voucher;
        $insert_museum['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
        $insert_museum['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
        if ($is_voucher == '1') {
            // $insert_museum['activation_method'] = '10';
            $insert_visitor['activation_method'] = $confirm_ticket_array['prepaid_type'];
        }
        $insert_museum['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
        if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
            $insert_museum['activation_method'] = '9';
            $insert_museum['split_cash_amount'] = $split_cash_amount;
            $insert_museum['split_card_amount'] = $split_card_amount;
            $insert_museum['split_direct_payment_amount'] = $split_direct_amount;
            $insert_museum['split_voucher_amount'] = $split_voucher_amount;
        }
        if (isset($confirm_ticket_array['scanned_pass'])) {
            $insert_museum['scanned_pass'] = $scanned_pass;
            $insert_museum['groupTransactionId'] = $groupTransactionId;
        }

        if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
            $insert_museum['is_prepaid'] = "1";
        }
        $insert_museum['selected_date'] = $selected_date;
        $insert_museum['booking_selected_date'] = $booking_selected_date;
        $insert_museum['from_time'] = $from_time;
        $insert_museum['to_time'] = $to_time;
        $insert_museum['hto_id'] = $hto_id;
        $insert_museum['action_performed'] = $confirm_ticket_array['action_performed'];
        $insert_museum['updated_at'] = $current_date;
        $insert_museum['booking_status'] = $booking_status;
        $insert_museum['channel_type'] = $channel_type;
        $insert_museum['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
        $insert_museum['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
        $insert_museum['group_type_ticket'] = $group_type_ticket;
        $insert_museum['group_price'] = $group_price;
        $insert_museum['group_quantity'] = $group_quantity;
        $insert_museum['group_linked_with'] = $group_linked_with;
        $insert_museum[$this->admin_currency_code_col] = !empty($admin_prices['currency_code']) ? $admin_prices['currency_code'] : $confirm_ticket_array['supplier_currency_code'];
        $insert_museum['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
        $insert_museum['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
        $insert_museum['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
        $insert_museum['currency_rate'] = $confirm_ticket_array['currency_rate'];
        $insert_museum['adyen_status'] = $pricing_level;
        if (!empty($is_custom_setting) && $is_custom_setting > 0) {
            $insert_museum['external_product_id'] = $external_product_id;
        } else if ('4' == $mec_is_combi) {
            /* external_product_id to be added (23-12-2022) */
            $insert_combi_data['external_product_id'] = $related_product_id;
        }
        $insert_museum['account_number'] = $account_number;
        $insert_museum['chart_number'] = $chart_number;        
        $insert_museum['supplier_gross_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_gross_price'][2] : $museumCommission;
        $insert_museum['supplier_discount'] = !empty($supplier_prices) ? $supplier_prices['supplier_discount'] : $confirm_ticket_array['supplier_discount'];
        $insert_museum['supplier_ticket_amt'] = $confirm_ticket_array['supplier_ticket_amt'];
        $insert_museum['supplier_tax_value'] = $supplier_museum_tax_value;
        $insert_museum['supplier_net_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_net_price'][2] : $museumNetPrice;

        if ( !empty($ctd_currency) && !empty($ctd_clc_market_merchant_prices['currency_code']) &&($ctd_currency != $ctd_clc_market_merchant_prices['currency_code']) && $confirm_ticket_array['is_addon_ticket'] == 2) {
            //if merchant and subticket ticket currency is different. 
            $insert_museum[$this->merchant_price_col] = 0.00;
            $insert_museum[$this->merchant_net_price_col] = 0.00;
            $insert_museum[$this->merchant_currency_code_col] = $ctd_clc_market_merchant_prices['currency_code'];
            
        } else {
            $insert_museum[$this->merchant_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_gross_price'][2] : $insert_museum['partner_gross_price'];
            $insert_museum[$this->merchant_net_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_net_price'][2] : $insert_museum['partner_net_price'];
            $insert_museum[$this->merchant_currency_code_col] = !empty($market_merchant_prices) ? $market_merchant_prices['currency_code'] : $insert_museum[$this->admin_currency_code_col];
            
        }
        
        $insert_museum[$this->merchant_tax_id_col] = !empty($market_merchant_prices) ? $market_merchant_prices['ticket_tax_id'] : $insert_museum['tax_id'];
        $insert_museum[$this->supplier_tax_id_col] = !empty($supplier_prices) ? $supplier_prices['ticket_tax_id'] : $insert_museum['tax_id'];
        
        
        if ($uncancel_order == 1) {
            if($channel_type == 7 || $channel_type == 6){
                $insert_museum['invoice_status'] = '0';
                $insert_museum['action_performed'] = $confirm_ticket_array['action_performed']; 
            } else {
                $insert_museum['invoice_status'] = '12';
                $insert_museum['action_performed'] = $confirm_ticket_array['action_performed'].', CSS_RFN_CANCEL';
            }
            $insert_museum['is_refunded'] = '0';                    
            //$insert_museum['created_date'] = gmdate('Y-m-d H:i:s');
            //$insert_museum['visit_date_time'] = gmdate('Y/m/d H:i:s');
            //$insert_museum['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
            $insert_museum['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($confirm_ticket_array['is_addon_ticket'] == "2") {
            $insert_museum['col2'] = 2;
        } else {
            $insert_museum['col2'] = $confirm_ticket_array['is_addon_ticket'];
        }        
        
        if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
            $insert_museum['voucher_updated_by'] = $voucher_updated_by;
            $insert_museum['voucher_updated_by_name'] = $voucher_updated_by_name;
        }
        
        $visitor_ticket_rows_batch [] = $insert_museum;
        $temp_analytic_details['tax_id'] = $insert_museum['tax_id'];
        $temp_analytic_details['tax_value'] = $insert_museum['tax_value'];
        $temp_analytic_details['total_sold_amount_for_supplier'] = $insert_museum['partner_gross_price'];
        $temp_analytic_details['total_sold_net_amount_for_supplier'] = $insert_museum['partner_net_price'];

        // EOF for Museum
        // BOF for Distributor
        $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "03");
        $hotel_tax_id = $hotel_tax_id ? $hotel_tax_id : 0;
        $hotel_tax_value = $hotel_tax_value == '' ? '0.00' : $hotel_tax_value;
        $hotelNetPrice = $hotelNetPrice ? $hotelNetPrice : 0.00;
        $hotelCommission = $hotelCommission ? $hotelCommission : 0.00;
        $isCommissionInPercent = $isCommissionInPercent ? $isCommissionInPercent : 0;
        $distributer_invoice_id = '';

        $distributer_debitor = $hotel_name;
        $distributer_creditor = 'Credit';
        if (isset($confirm_ticket_array['prepaid_type'])) {
            $insert_distributer['activation_method'] = $confirm_ticket_array['prepaid_type'];
        } else if ($billtohotel == '0') {
            $insert_distributer['activation_method'] = "1";
        } else {
            $insert_distributer['activation_method'] = "3";
        }
        $insert_distributer['id'] = $visitor_tickets_id;
        if(!empty($voucher_creation_date)){
            $insert_distributer['voucher_creation_date'] = $voucher_creation_date;
        }
        $insert_distributer['is_prioticket'] = $is_prioticket;
        $insert_distributer['targetlocation'] = $confirm_ticket_array['clustering_id'];
        $insert_distributer['card_name'] = $confirm_ticket_array['discount_label'];
        $insert_distributer['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
        $insert_distributer['tp_payment_method']  = $tp_payment_method;
        $insert_distributer['order_confirm_date'] = $order_confirm_date;  
	    $insert_distributer['payment_date'] = $payment_date;
        $insert_distributer['transaction_id'] = $transaction_id;
        $insert_distributer['visitor_invoice_id'] = $transaction_id;
        $insert_distributer['invoice_id'] = $distributer_invoice_id;
        $insert_distributer['channel_id'] = $channel_id;
        $insert_distributer['partner_category_id'] = $partner_category_id;
        $insert_distributer['partner_category_name'] = $partner_category_name;
        $insert_distributer['channel_name'] = $confirm_ticket_array['channel_name'];
        $insert_distributer['financial_id'] = $confirm_ticket_array['financial_id'];
        $insert_distributer['financial_name'] = $confirm_ticket_array['financial_name'];
        $insert_distributer['order_updated_cashier_id'] = $order_updated_cashier_id;
        $insert_distributer['order_updated_cashier_name'] = $order_updated_cashier_name;
        $insert_distributer['redeem_method'] = $redeem_method;
        $insert_distributer['ticketId'] = $ticketId;
        if (isset($confirm_ticket_array['invoice_type'])) {
            $insert_distributer['invoice_type'] = $confirm_ticket_array['invoice_type'];
        }
        $insert_distributer['ticket_title'] = $ticket_title;
        $insert_distributer['all_ticket_ids'] = $voucher_reference;
        $insert_distributer['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
        $insert_distributer['version'] = $version;
        $insert_distributer['ticketpriceschedule_id'] = $ticketpriceschedule_id;
        $insert_distributer['user_name'] = $userdata->guest_names;
        $insert_distributer['partner_id'] = $hotel_id;
        $insert_distributer['partner_name'] = $hotel_name;
        $insert_distributer['is_custom_setting'] = $pax;
        $insert_distributer['is_refunded'] = $is_refunded;
        $insert_distributer['museum_name'] = $museum_name;
        $insert_distributer['reseller_id'] = $reseller_id;
        $insert_distributer['reseller_name'] = $reseller_name;
        $insert_distributer['saledesk_id'] = $saledesk_id;
        $insert_distributer['saledesk_name'] = $saledesk_name;
        $insert_distributer['museum_id'] = $museum_id;
        $insert_distributer['hotel_name'] = $hotel_name;
        $insert_distributer['primary_host_name'] = $primary_host_name;
        $insert_distributer['hotel_id'] = $hotel_id;
        $insert_distributer['shift_id'] = $shift_id;
        $insert_distributer['pos_point_id'] = $pos_point_id;
        $insert_distributer['pos_point_name'] = $pos_point_name;
        $insert_distributer['passNo'] = $passNo;
        $insert_distributer['pass_type'] = $pass_type;
        $insert_distributer['ticketAmt'] = $ticketAmt;
        $insert_distributer['visit_date'] = $visit_date;
        $insert_distributer['distributor_partner_id'] = $distributor_partner_id;
        $insert_distributer['distributor_partner_name'] = $distributor_partner_name;        
        $insert_distributer['discount'] = $insert_visitor['discount'];
        $insert_distributer['vt_group_no'] = $visitor_group_no;
        $insert_distributer['visit_date_time'] = $visit_date_time;
        $insert_distributer['is_block'] = $is_block;
        $insert_distributer['isDiscountInPercent'] = $ispercenttype;
        $insert_distributer['ticketType'] = $ticketType;
        $insert_distributer['tickettype_name'] = $tickettype_name;
        $insert_distributer['paid'] = "0";
        $insert_distributer['isBillToHotel'] = $billtohotel;
        $insert_distributer['debitor'] = $distributer_debitor;
        $insert_distributer['creditor'] = $distributer_creditor;
        $insert_distributer['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
        $insert_distributer['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
        $insert_distributer['partner_gross_price'] =  round((($hotelNetPrice * (100 + $hotel_tax_value)) / 100), 2) + $distributor_commission;
        $insert_distributer['order_currency_partner_gross_price'] = 0.00;
        $partner_gross_price_without_combi_discount = $is_combi_discount == '1' ? $hotel_gross_price_without_combi_discount : $calculated_hotel_commission;
        $insert_distributer['partner_gross_price_without_combi_discount'] =$partner_gross_price_without_combi_discount + $distributor_commission;
        $insert_distributer['partner_net_price'] = $hotelNetPrice + $dist_net_commission;
        $insert_distributer['order_currency_partner_net_price'] = 0.00;
        $partner_net_price_without_combi_discount = $is_combi_discount == '1' ? $hotel_net_price_without_combi_discount : $hotelNetPrice;
        $insert_distributer['partner_net_price_without_combi_discount'] = $partner_net_price_without_combi_discount + $dist_net_commission;
        /* #region In case of bundle product parter net price and partner gross price will calculate on the basis of main ticket hotel commision */
        if($mec_is_combi == '4'){
            $ticket_level_commissions_bundle_row   =   $ticket_level_commissions_bundle;
            foreach( $ticket_level_commissions_bundle as $bundle_result ){
                $bundle_commissions_values = (array) $bundle_result;
                /* #region to prepare valuesticket type wise if exist otherwise fetch commissions of linked tickrt type */
                if(in_array($ticket_level_commissions->ticket_type, $bundle_commissions_values)){
                    $partner_net_price_bundle   =   $subtotal_net_amount * $bundle_commissions_values['hotel_prepaid_commission_percentage']/100;
                    $partner_gross_price_bundle   =   ($partner_net_price_bundle * $ticket_level_commissions->ticket_tax_value /100) + $partner_net_price_bundle;
                } else {
                    $partner_net_price_bundle   =   $subtotal_net_amount * $ticket_level_commissions_bundle_row[0]->hotel_prepaid_commission_percentage/100;
                    $partner_gross_price_bundle   =   ($partner_net_price_bundle * $ticket_level_commissions->ticket_tax_value /100) + $partner_net_price_bundle;
                }
                /* #endregion to prepare valuesticket type wise if exist otherwise fetch commissions of linked tickrt type */
                $insert_distributer['partner_net_price'] =  $partner_net_price_bundle;
                $insert_distributer['partner_net_price_without_combi_discount'] = $partner_net_price_bundle;
                $insert_distributer['partner_gross_price'] = $partner_gross_price_bundle ;
                $insert_distributer['partner_gross_price_without_combi_discount'] = $partner_gross_price_bundle;
            }   
        }
         /* #endregion In case of bundle product parter net price and partner gross price will calculate on the basis of main ticket hotel commision */
        if($markup_price > 0) {
            $insert_distributer['partner_gross_price'] += $markup_price;
            $insert_distributer['partner_net_price'] +=  round(($markup_price * 100) / ($hotel_tax_value + 100),2);
        }
        $insert_distributer['isCommissionInPercent'] = $isCommissionInPercent;
        $insert_distributer['tax_id'] = $hotel_tax_id ;        
        $insert_distributer['targetcity'] = $targetcity;
        $insert_distributer['paymentMethodType'] = $paymentMethodType;
        $insert_distributer['service_name'] = SERVICE_NAME;
        $insert_distributer['transaction_type_name'] = "Distributor fee";
        $insert_distributer['tax_value'] =  $hotel_tax_value ;
        $insert_distributer['issuer_country_code'] = $country;
        $insert_distributer['used'] = $is_used;
        $insert_distributer['ticket_booking_id'] = $ticket_booking_id;
        $insert_distributer['without_elo_reference_no'] = $without_elo_reference_no;
        $insert_distributer['extra_text_field_answer'] = $extra_text_field_answer;
        $insert_distributer['is_voucher'] = $is_voucher;
        $insert_distributer['group_type_ticket'] = $group_type_ticket;
        $insert_distributer['group_price'] = $group_price;
        $insert_distributer['group_quantity'] = $group_quantity;
        $insert_distributer['group_linked_with'] = $group_linked_with;
        $insert_distributer['action_performed'] = $confirm_ticket_array['action_performed'];
        $insert_distributer[$this->admin_currency_code_col] = !empty($admin_prices['currency_code']) ? $admin_prices['currency_code']: $confirm_ticket_array['supplier_currency_code'];
        $insert_distributer['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
        $insert_distributer['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
        $insert_distributer['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
        $insert_distributer['currency_rate'] = $confirm_ticket_array['currency_rate'];
	    $insert_distributer['updated_at'] = $current_date;
        if ($is_voucher == '1') {
            // $insert_distributer['activation_method'] = '10';
            $insert_distributer['activation_method'] = $confirm_ticket_array['prepaid_type'];
        }
        if ($confirm_ticket_array['is_addon_ticket'] == "1") {
            $insert_distributer['col2'] = $confirm_ticket_array['is_addon_ticket'];
        }
        $insert_distributer['extra_discount'] = $extra_discount;
        $insert_distributer['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
        if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
            $insert_distributer['activation_method'] = '9';
            $insert_distributer['split_cash_amount'] = $split_cash_amount;
            $insert_distributer['split_card_amount'] = $split_card_amount;
            $insert_distributer['split_direct_payment_amount'] = $split_direct_amount;
            $insert_distributer['split_voucher_amount'] = $split_voucher_amount;
        }
        if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
            $insert_distributer['is_prepaid'] = "1";
        }
        $insert_distributer['tax_name'] = $hotel_tax_name;
        $insert_distributer['timezone'] = $timeZone;
        $insert_distributer['invoice_status'] = $invoice_status;

        $insert_distributer['row_type'] = "3";
        $insert_distributer['nights'] = $is_iticket_product;
        $insert_distributer['ticket_status'] = $insert_visitor['ticket_status'];
        $insert_distributer['merchant_admin_id'] = $merchant_admin_id ;
        $insert_distributer['merchant_admin_name'] = $merchant_admin_name;
        $insert_distributer['market_merchant_id'] = $market_merchant_id;
        if (isset($confirm_ticket_array['scanned_pass'])) {
            $insert_distributer['scanned_pass'] = $scanned_pass;
            $insert_distributer['groupTransactionId'] = $groupTransactionId;
        }
        $insert_distributer['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
        $insert_distributer['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date)+ ($timeZone * 3600));
        $insert_distributer['updated_by_id'] = $resuid;
        $insert_distributer['updated_by_username'] = $resfname . ' ' . $reslname;
        $insert_distributer['voucher_updated_by'] = $resuid;
        $insert_distributer['voucher_updated_by_name'] = $resfname . ' ' . $reslname;
        $insert_distributer['redeem_method'] =$redeem_method;
        $insert_distributer['cashier_id'] = $cashier_id;
        $insert_distributer['cashier_name'] = $cashier_name;
        $insert_distributer['selected_date'] = $selected_date;
        $insert_distributer['booking_selected_date'] = $booking_selected_date;
        $insert_distributer['from_time'] = $from_time;
        $insert_distributer['to_time'] = $to_time;
        $insert_distributer['slot_type'] = $slot_type;
        $insert_distributer['hto_id'] = $hto_id;
        $insert_distributer['booking_status'] = $booking_status;
        $insert_distributer['channel_type'] = $channel_type;
        $insert_distributer['supplier_gross_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_gross_price'][3] : $insert_distributer['partner_gross_price'];
        $insert_distributer['supplier_discount'] = !empty($supplier_prices) ? $supplier_prices['supplier_discount'] : $confirm_ticket_array['supplier_discount'];
        $insert_distributer['supplier_ticket_amt'] = $confirm_ticket_array['supplier_ticket_amt'];
        $insert_distributer['supplier_tax_value'] = $supplier_hotel_tax_value;
        $insert_distributer['supplier_net_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_net_price'][3] : $insert_distributer['partner_net_price'];
        $insert_distributer[$this->merchant_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_gross_price'][3] : $insert_distributer['partner_gross_price'];
        $insert_distributer[$this->merchant_net_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_net_price'][3] : $insert_distributer['partner_net_price'];
        $insert_distributer[$this->merchant_tax_id_col] = !empty($market_merchant_prices) ? $market_merchant_prices['ticket_tax_id'] : $insert_distributer['tax_id'];
        $insert_distributer[$this->supplier_tax_id_col] = !empty($supplier_prices) ? $supplier_prices['ticket_tax_id'] : $insert_distributer['tax_id'];
        $insert_distributer[$this->merchant_currency_code_col] = !empty($market_merchant_prices) ? $market_merchant_prices['currency_code'] : $insert_distributer[$this->admin_currency_code_col];
        $insert_distributer['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_distributer[$this->admin_currency_code_col];
        $insert_distributer['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
        $insert_distributer['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
        $insert_distributer['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
        $insert_distributer['adyen_status'] = $commission_level;
        if (!empty($is_custom_setting) && $is_custom_setting > 0) {
            $insert_distributer['external_product_id'] = $external_product_id;
        } else if ('4' == $mec_is_combi) {
            /* external_product_id to be added (23-12-2022) */
            $insert_combi_data['external_product_id'] = $related_product_id;
        }
        $insert_distributer['account_number'] = $account_number;
        $insert_distributer['chart_number'] = $chart_number;
        if ($uncancel_order == 1) {
            if($channel_type == 7 || $channel_type == 6){
                $insert_distributer['invoice_status'] = '0';                
            } else {
                $insert_distributer['invoice_status'] = '12';
                $insert_distributer['action_performed'] = $insert_distributer['action_performed'].', CSS_RFN_CANCEL';        
            }
            $insert_distributer['is_refunded'] = '0';                
            //$insert_distributer['created_date'] = gmdate('Y-m-d H:i:s');
            //$insert_distributer['visit_date_time'] = gmdate('Y/m/d H:i:s');
            //$insert_distributer['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
            $insert_distributer['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        
        if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
            $insert_distributer['voucher_updated_by'] = $voucher_updated_by;
            $insert_distributer['voucher_updated_by_name'] = $voucher_updated_by_name;
        }
        
        //In case of cluster ticket don't need to save data of this row
        if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {            
            $visitor_ticket_rows_batch [] = $insert_distributer;
        }
        // EOF for Distributor
        // BOF for Partners
        if ($partnersData && count($partnersData) > 0) {
            $partner_count = 0;
            foreach ($partnersData as $partner) {
                $partner_count++;
                if ($partner->agent_type == "0") {
                    $partner_id = $partner->partner_id;
                    $partner_name = $partner->partnerName;
                    $calculated_partner_commission = $partner->calculated_partner_commission;
                    $isCommissionInPercent = $partner->isCommissionInPercent;
                    $partner_net_price = $partner->partnerNetPrice;
                    $agent_tax_id = $partner->agent_tax_id;
                    $agent_tax_value = $partner->agent_tax_value;
                    $partner_invoice_id = '';

                    $partner_debitor = $partner_name;
                    $partner_creditor = 'Credit';

                    $agent_tax_id = $agent_tax_id ? $agent_tax_id : 0;
                    $agent_tax_value = $agent_tax_value == '' ? '0.00' : $agent_tax_value;
                    $manual_row_type = 15 + $partner_count;
                    $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, $manual_row_type);
                    $insert_partner['id'] = $visitor_tickets_id;
                    if(!empty($voucher_creation_date)){
                        $insert_partner['voucher_creation_date'] = $voucher_creation_date;
                    }
                    $insert_partner['is_prioticket'] = $is_prioticket;
                    $insert_partner['targetlocation'] = $confirm_ticket_array['clustering_id'];
                    $insert_partner['card_name'] = $confirm_ticket_array['discount_label'];
                    $insert_partner['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
                    $insert_partner['tp_payment_method']  = $tp_payment_method;
                    $insert_partner['order_confirm_date'] = $order_confirm_date; 
		            $insert_partner['payment_date'] = $payment_date;   
                    $insert_partner['transaction_id'] = $transaction_id;
                    $insert_partner['visitor_invoice_id'] = $transaction_id;
                    $insert_partner['invoice_id'] = $partner_invoice_id;
                    $insert_partner['channel_id'] = $channel_id;
                    $insert_partner['channel_name'] = $confirm_ticket_array['channel_name'];
                    $insert_partner['financial_id'] = $confirm_ticket_array['financial_id'];
                    $insert_partner['financial_name'] = $confirm_ticket_array['financial_name'];
                    $insert_partner['reseller_id'] = $reseller_id;
                    $insert_partner['reseller_name'] = $reseller_name;
                    $insert_partner['saledesk_id'] = $saledesk_id;
                    $insert_partner['saledesk_name'] = $saledesk_name;
                    $insert_partner['ticketId'] = $ticketId;
                    $insert_partner['is_refunded'] = $is_refunded;
                    $insert_partner['order_updated_cashier_id'] = $order_updated_cashier_id;
                    $insert_partner['order_updated_cashier_name'] = $order_updated_cashier_name;
                    $insert_partner['redeem_method'] = $redeem_method;
                    if (isset($confirm_ticket_array['prepaid_type'])) {
                        $insert_partner['activation_method'] = $confirm_ticket_array['prepaid_type'];
                    } else if ($billtohotel == '0') {
                        $insert_partner['activation_method'] = "1";
                    } else {
                        $insert_partner['activation_method'] = "3";
                    }
                    $insert_partner['ticket_title'] = $ticket_title;
                    $insert_partner['all_ticket_ids'] = $voucher_reference;
                    $insert_partner['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
                    $insert_partner['version'] = $version;
                    $insert_partner['ticketpriceschedule_id'] = $ticketpriceschedule_id;
                    $insert_partner['partner_id'] = $partner_id;
                    if (isset($confirm_ticket_array['invoice_type'])) {
                        $insert_partner['invoice_type'] = $confirm_ticket_array['invoice_type'];
                    }
                    $insert_partner['partner_name'] = $partner_name;
                    $insert_partner['is_custom_setting'] = $pax;
                    $insert_partner['museum_name'] = $museum_name;
                    $insert_partner['museum_id'] = $museum_id;
                    $insert_partner['hotel_name'] = $hotel_name;
                    $insert_partner['primary_host_name'] = $primary_host_name;
                    $insert_partner['hotel_id'] = $hotel_id;
                    $insert_partner['shift_id'] = $shift_id;
                    $insert_partner['pos_point_id'] = $pos_point_id;
                    $insert_partner['pos_point_name'] = $pos_point_name;
                    $insert_partner['passNo'] = $passNo;
                    $insert_partner['pass_type'] = $pass_type;
                    $insert_partner['partner_category_id'] = $partner_category_id;
                    $insert_partner['partner_category_name'] = $partner_category_name;
                    $insert_partner['ticketAmt'] = $ticketAmt;
                    $insert_partner['visit_date'] = $visit_date;
                    $insert_partner['ticketType'] = $ticketType;
                    $insert_partner['vt_group_no'] = $visitor_group_no;
                    $insert_partner['distributor_partner_id'] = $distributor_partner_id;
                    $insert_partner['distributor_partner_name'] = $distributor_partner_name;
                    $insert_partner['visit_date_time'] = $visit_date_time;
                    $insert_partner['tickettype_name'] = $tickettype_name;
                    $insert_partner['paid'] = "0";
                    $insert_partner['isBillToHotel'] = $billtohotel;                    
                    $insert_partner['discount'] = $insert_visitor['discount'];
                    $insert_partner['is_block'] = $is_block;
                    $insert_partner['isDiscountInPercent'] = $ispercenttype;
                    $insert_partner['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
                    $insert_partner['debitor'] = $partner_debitor;
                    $insert_partner['creditor'] = $partner_creditor;
                    $insert_partner['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
                    $insert_partner['partner_gross_price'] = $calculated_partner_commission ;
                    $insert_partner['partner_gross_price_without_combi_discount'] = $calculated_partner_commission ;
                    $insert_partner['partner_net_price'] =  $partner_net_price;
                    $insert_partner['partner_net_price_without_combi_discount'] =  $partner_net_price;
                    $insert_partner['isCommissionInPercent'] = $isCommissionInPercent;
                    $insert_partner['tax_id'] = $agent_tax_id;
                    $insert_partner['tax_value'] = $agent_tax_value;
                    $insert_partner['timezone'] = $timeZone;
                    $insert_partner['invoice_status'] = $invoice_status;
                    $insert_partner['used'] = $is_used;
                    $insert_partner['without_elo_reference_no'] = $without_elo_reference_no;
                    $insert_partner['extra_text_field_answer'] = $extra_text_field_answer;
                    $insert_partner['is_voucher'] = $is_voucher;
                    $insert_partner['group_type_ticket'] = $group_type_ticket;
                    $insert_partner['group_price'] = $group_price;
                    $insert_partner['group_quantity'] = $group_quantity;
                    $insert_partner['group_linked_with'] = $group_linked_with;
		            $insert_partner['supplier_currency_code'] = $confirm_ticket_array['supplier_currency_code'];
                    $insert_partner['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
                    $insert_partner['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
                    $insert_partner['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
                    $insert_partner['currency_rate'] = $confirm_ticket_array['currency_rate'];
                    $insert_partner['action_performed'] = $confirm_ticket_array['action_performed'];
		            $insert_partner['updated_at'] = $current_date;
                    $insert_partner['tp_payment_method'] = $tp_payment_method;
                    $insert_partner['order_confirm_date'] = $order_confirm_date;
                    $insert_partner['payment_date'] = $payment_date;
                    $insert_partner['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
                    $insert_partner['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
                    $insert_partner['adyen_status'] = $pricing_level;
                    if ($is_voucher == '1') {
                        // $insert_partner['activation_method'] = '10';
                        $insert_partner['activation_method'] = $confirm_ticket_array['prepaid_type'];
                    }
                    if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
                        $insert_partner['activation_method'] = '9';
                        $insert_partner['split_cash_amount'] = $split_cash_amount;
                        $insert_partner['split_card_amount'] = $split_card_amount;
                        $insert_partner['split_direct_payment_amount'] = $split_direct_amount;
                        $insert_partner['split_voucher_amount'] = $split_voucher_amount;
                    }
                    if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
                        $insert_partner['is_prepaid'] = "1";
                    }
                    $insert_partner['extra_discount'] = $extra_discount;
                    $insert_partner['transaction_type_name'] = "Service fee";
                    $insert_partner['row_type'] = "5";
                    $insert_partner['nights'] = $is_iticket_product;
                    $insert_partner['ticket_status'] = $insert_visitor['ticket_status'];
                    $insert_partner['merchant_admin_id'] = $merchant_admin_id ;
                    $insert_partner['merchant_admin_name'] =  $merchant_admin_name;
                    $insert_partner['market_merchant_id'] = $market_merchant_id;
                    $insert_partner['targetcity'] = $targetcity;
                    $insert_partner['paymentMethodType'] = $paymentMethodType;
                    $insert_partner['service_name'] = SERVICE_NAME;
                    if (isset($confirm_ticket_array['scanned_pass'])) {
                        $insert_partner['scanned_pass'] = $scanned_pass;
                        $insert_partner['groupTransactionId'] = $groupTransactionId;
                    }
                    $insert_partner['updated_by_id'] = $resuid;
                    $insert_partner['updated_by_username'] = $resfname . ' ' . $reslname;
                    $insert_partner['cashier_id'] = $cashier_id;
                    $insert_partner['cashier_name'] = $cashier_name;
                    $insert_partner['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
                    $insert_partner['selected_date'] = $selected_date;
                    $insert_partner['booking_selected_date'] = $booking_selected_date;
                    $insert_partner['from_time'] = $from_time;
                    $insert_partner['to_time'] = $to_time;
                    $insert_partner['slot_type'] = $slot_type;
                    $insert_partner['hto_id'] = $hto_id;
                    $insert_partner['ticket_booking_id'] = $ticket_booking_id;
                    $insert_partner['booking_status'] = $booking_status;
                    $insert_partner['channel_type'] = $channel_type;                    
                    $insert_partner['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
                    $insert_partner['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
                    if (!empty($is_custom_setting) && $is_custom_setting > 0) {
                        $insert_partner['external_product_id'] = $external_product_id;
                    } else if ('4' == $mec_is_combi) {
                        /* external_product_id to be added (23-12-2022) */
                        $insert_combi_data['external_product_id'] = $related_product_id;
                    }
                    $insert_partner['account_number'] = $account_number;
                    $insert_partner['chart_number'] = $chart_number;
                    if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                        $insert_partner['voucher_updated_by'] = $voucher_updated_by;
                        $insert_partner['voucher_updated_by_name'] = $voucher_updated_by_name;
                    }
                    //In case of cluster ticket don't need to save data of this row
                    if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {                        
                        $visitor_ticket_rows_batch [] = $insert_partner;
                    }
                }
            }
        }

        // EOF for Partners
        // BOF for Affiliates
        if ($affiliate_data && count($affiliate_data) > 0) {
            $partner_count = 0;
            $new_aff = $partner_net_price_row_1- round($combi_discount_net_amount, 2) - round($extra_net_discount, 2) - $museumNetPrice - $ticket_level_commissions->merchant_net_commission;
            foreach ($affiliate_data as $affiliate) {
                $partner_count++;
                $partner_id = $affiliate->cod_id;
                $partner_name = $affiliate->company;
                $isCommissionInPercent = "1";
                if ($left_hotel_aff_price < 0) {
                    continue;
                }
                
                if (isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) {
                    $affiliate_commission_percentage = $affiliate->affiliate_prepaid_commission;
                } else {
                    $affiliate_commission_percentage = $affiliate->affiliate_postpaid_commission;
                }
                if ($affiliate->is_affiliate_fee_percentage == 0 || $ticket_level_commissions->is_hotel_prepaid_commission_percentage == 0) {
                    // if affiliates fee setup in fixed amount
                    $partner_net_price = $affiliate->affiliate_fee;
                    $partner_net_price_without_combi_discount = $partner_net_price;
                } else {
                    if ($commission_on_sale_price == 1) {
                        $partner_net_price = round((($insert_visitor['partner_net_price'] * $affiliate_commission_percentage) / 100), 2);
                    } else {
                        $partner_net_price = round((($subtotal * $affiliate_commission_percentage) / 100), 2);
                    }
                    
                    $partner_net_price_without_combi_discount = round((($subtotal_with_combi_discount * $affiliate_commission_percentage) / 100), 2);
                } 
                
                if ($new_aff < $partner_net_price) {
                    $partner_net_price = $new_aff;
                    $partner_net_price_without_combi_discount = $new_aff;
                }
                $new_aff -= $partner_net_price;
                $calculated_partner_commission = $partner_net_price + round((($partner_net_price * $affiliate->affiliate_commission_tax_value) / 100), 2);
                $partner_gross_price_without_combi_discount = $partner_net_price_without_combi_discount + round((($partner_net_price_without_combi_discount * $affiliate->affiliate_commission_tax_value) / 100), 2);
                $hgsnetprice -= $partner_net_price;
                if ($commission_on_sale_price == 0 && (empty($ticket_level_commissions) || ($ticket_level_commissions->hotel_prepaid_commission_percentage == 0 &&  $ticket_level_commissions->hgs_prepaid_commission_percentage == 0) || $total_left_commission > $subtotal)){
                    // assign affiliate first and subtract hotel commission
                    $left_hotel_aff_price -= $partner_net_price;
                }
                $agent_tax_id = $affiliate->affiliate_commission_tax_id;
                $agent_tax_value = $affiliate->affiliate_commission_tax_value;
                $partner_invoice_id = '';
                $partner_debitor = $partner_name;
                $partner_creditor = 'Credit';
                $agent_tax_id = $agent_tax_id ? $agent_tax_id : 0;
                $agent_tax_value = $agent_tax_value == '' ? '0.00' : $agent_tax_value;
                $insert_partner = array();
                $manual_row_type = 25 + $partner_count;
                $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, $manual_row_type);
                $insert_partner['id'] = $visitor_tickets_id;
                $insert_partner['is_prioticket'] = $is_prioticket;
                $insert_partner['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
                $insert_partner['targetlocation'] = $confirm_ticket_array['clustering_id'];
                $insert_partner['card_name'] = $confirm_ticket_array['discount_label'];
                $insert_partner['tp_payment_method']  = $tp_payment_method;
                $insert_partner['order_confirm_date'] = $order_confirm_date;  
		        $insert_partner['payment_date'] = $payment_date; 
                $insert_partner['transaction_id'] = $transaction_id;
                $insert_partner['visitor_invoice_id'] = $transaction_id;
                $insert_partner['invoice_id'] = $partner_invoice_id;
                $insert_partner['is_refunded'] = $is_refunded;
                $insert_partner['channel_id'] = $channel_id;
                $insert_partner['channel_name'] = $confirm_ticket_array['channel_name'];
                $insert_partner['financial_id'] = $confirm_ticket_array['financial_id'];
                $insert_partner['financial_name'] = $confirm_ticket_array['financial_name'];
                $insert_partner['reseller_id'] = $reseller_id;
                $insert_partner['reseller_name'] = $reseller_name;
                $insert_partner['saledesk_id'] = $saledesk_id;
                $insert_partner['saledesk_name'] = $saledesk_name;
                $insert_partner['ticketId'] = $ticketId;
                $insert_partner['partner_category_id'] = $partner_category_id;
                $insert_partner['partner_category_name'] = $partner_category_name;
                if (isset($confirm_ticket_array['prepaid_type'])) {
                    $insert_partner['activation_method'] = $confirm_ticket_array['prepaid_type'];
                } else if ($billtohotel == '0') {
                    $insert_partner['activation_method'] = "1";
                } else {
                    $insert_partner['activation_method'] = "3";
                }
                $insert_partner['ticket_title'] = $ticket_title;
                $insert_partner['all_ticket_ids'] = $voucher_reference;
                $insert_partner['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
                $insert_partner['version'] = $version;
                $insert_partner['ticketpriceschedule_id'] = $ticketpriceschedule_id;
                $insert_partner['partner_id'] = $partner_id;
                if (isset($confirm_ticket_array['invoice_type'])) {
                    $insert_partner['invoice_type'] = $confirm_ticket_array['invoice_type'];
                }
                $insert_partner['partner_name'] = $partner_name;
                $insert_partner['is_custom_setting'] = $pax;
                $insert_partner['museum_name'] = $museum_name;
                $insert_partner['museum_id'] = $museum_id;
                $insert_partner['hotel_name'] = $hotel_name;
                $insert_partner['primary_host_name'] = $primary_host_name;
                $insert_partner['hotel_id'] = $hotel_id;
                $insert_partner['shift_id'] = $shift_id;
                $insert_partner['pos_point_id'] = $pos_point_id;
                $insert_partner['pos_point_name'] = $pos_point_name;
                $insert_partner['passNo'] = $passNo;
                $insert_partner['pass_type'] = $pass_type;
                $insert_partner['ticketAmt'] = $ticketAmt;
                $insert_partner['visit_date'] = $visit_date;
                $insert_partner['ticketType'] = $ticketType;
                $insert_partner['vt_group_no'] = $visitor_group_no;
                $insert_partner['visit_date_time'] = $visit_date_time;
                $insert_partner['tickettype_name'] = $tickettype_name;
                $insert_partner['paid'] = "0";
                $insert_partner['isBillToHotel'] = $billtohotel;
                $insert_partner['distributor_partner_id'] = $distributor_partner_id;
                $insert_partner['distributor_partner_name'] = $distributor_partner_name;                
                $insert_partner['discount'] = $insert_visitor['discount'];
                $insert_partner['is_block'] = $is_block;
                $insert_partner['isDiscountInPercent'] = $ispercenttype;
                $insert_partner['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
                $insert_partner['debitor'] = $partner_debitor;
                $insert_partner['creditor'] = $partner_creditor;
                $insert_partner['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
                $insert_partner['partner_gross_price'] =  $calculated_partner_commission;
                $partner_gross_price_without_combi_discount = (isset($is_combi_discount) && $is_combi_discount == 1) ? $partner_gross_price_without_combi_discount : $calculated_partner_commission;
                $insert_partner['partner_gross_price_without_combi_discount'] = $partner_gross_price_without_combi_discount ;
                $insert_partner['partner_net_price'] =  $partner_net_price ;
                $partner_net_price_without_combi_discount = (isset($is_combi_discount) && $is_combi_discount == 1) ? $partner_net_price_without_combi_discount : $partner_net_price; 
                $insert_partner['partner_net_price_without_combi_discount'] =$partner_net_price_without_combi_discount;
                $insert_partner['isCommissionInPercent'] = $isCommissionInPercent;
                $insert_partner['tax_id'] = $agent_tax_id ;
                $insert_partner['tax_value'] =  $agent_tax_value ;
                $insert_partner['timezone'] = $timeZone;
                $insert_partner['invoice_status'] = $invoice_status;
                $insert_partner['used'] = $is_used;
                $insert_partner['action_performed'] = $confirm_ticket_array['action_performed'];
                $insert_partner['updated_at'] = $current_date;
                if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
                    $insert_partner['is_prepaid'] = "1";
                }
                $insert_partner['row_type'] = "11";
                $insert_partner['nights'] = $is_iticket_product;
                $insert_partner['ticket_status'] = $insert_visitor['ticket_status'];
                $insert_partner['merchant_admin_id'] = $merchant_admin_id;
                $insert_partner['merchant_admin_name'] = $merchant_admin_name ;
                $insert_partner['market_merchant_id'] = $market_merchant_id;
                $insert_partner['transaction_type_name'] = "Affiliate fee";
                $insert_partner['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
                $insert_partner['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));                
                $insert_partner['targetcity'] = $targetcity;
                $insert_partner['paymentMethodType'] = $paymentMethodType;
                $insert_partner['service_name'] = SERVICE_NAME;
                if (isset($confirm_ticket_array['scanned_pass'])) {
                    $insert_partner['scanned_pass'] = $scanned_pass;
                    $insert_partner['groupTransactionId'] = $groupTransactionId;
                }
                $insert_partner['updated_by_id'] = $resuid;
                $insert_partner['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
                $insert_partner['updated_by_username'] = $resfname . ' ' . $reslname;
                $insert_partner['cashier_id'] = $cashier_id;
                $insert_partner['cashier_name'] = $cashier_name;
                $insert_partner['selected_date'] = $selected_date;
                $insert_partner['booking_selected_date'] = $booking_selected_date;
                $insert_partner['from_time'] = $from_time;
                $insert_partner['to_time'] = $to_time;
                $insert_partner['slot_type'] = $slot_type;
                $insert_partner['hto_id'] = $hto_id;
                $insert_partner['booking_status'] = $booking_status;
                $insert_partner['channel_type'] = $channel_type;
                $insert_partner['group_type_ticket'] = $group_type_ticket;
                $insert_partner['group_price'] = $group_price;
                $insert_partner['group_quantity'] = $group_quantity;
                $insert_partner['group_linked_with'] = $group_linked_with;
                $insert_partner['supplier_currency_code'] = $confirm_ticket_array['supplier_currency_code'];
                $insert_partner['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
                $insert_partner['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
                $insert_partner['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
                $insert_partner['currency_rate'] = $confirm_ticket_array['currency_rate'];                
                $insert_partner['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
                $insert_partner['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
                if (!empty($is_custom_setting) && $is_custom_setting > 0) {
                    $insert_partner['external_product_id'] = $external_product_id;
                } else if ('4' == $mec_is_combi) {
                    /* external_product_id to be added (23-12-2022) */
                    $insert_combi_data['external_product_id'] = $related_product_id;
                }
                $insert_partner['account_number'] = $account_number;
                $insert_partner['chart_number'] = $chart_number;
                $insert_partner['adyen_status'] = $pricing_level;
                if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                    $insert_partner['voucher_updated_by'] = $voucher_updated_by;
                    $insert_partner['voucher_updated_by_name'] = $voucher_updated_by_name;
                }
                //In case of cluster ticket don't need to save data of this row
                if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                    
                    $visitor_ticket_rows_batch [] = $insert_partner;
                }
            }
        }
        // EOF for Affiliates
        // BOF for HGS
        if($hgsnetprice < 0 ){
            $hgsnetprice = 0.00;
        }
        $hgs_tax_id = $hgs_tax_id ? $hgs_tax_id : 0;
        $hgs_tax_value = $hgs_tax_value == '' ? '0.00' : $hgs_tax_value;
        $hgsnetprice = $hgsnetprice ? $hgsnetprice : 0.00;
        $debugLogs['CASE_1'] = $hgsnetprice;
        $hgs_net_price_without_combi_discount = $hgs_net_price_without_combi_discount ? $hgs_net_price_without_combi_discount : 0.00;
        $hotelCommission = $hotelCommission ? $hotelCommission : 0.00;

        $calculated_hgs_commission = $hgsnetprice + ($hgsnetprice * ($hgs_tax_value / 100));
        $calculated_hgs_commission = round($calculated_hgs_commission, 2);
        $currency_calculated_hgs_commission = $currency_hgsnetprice + ($hgsnetprice * ($hgs_tax_value / 100));
        $currency_calculated_hgs_commission = round($currency_calculated_hgs_commission, 2);
        $hgs_gross_price_without_combi_discount = $hgs_net_price_without_combi_discount + round(($hgs_net_price_without_combi_discount * ($hgs_tax_value / 100)), 2);
        if ($is_different_currency == 1) {
            $distributor_currency_hgs_price = $currency_calculated_hgs_commission;
            $distributor_currency_hgs_net_price = $currency_hgsnetprice;
        } else {
            $distributor_currency_hgs_price = $calculated_hgs_commission;
            $distributor_currency_hgs_net_price = $hgsnetprice;
        }

        $hgs_debitor = $hgs_provider_name;
        $hgs_creditor = 'Credit';
        $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "04");
        if (isset($confirm_ticket_array['prepaid_type'])) {
            $insert_hgs['activation_method'] = $confirm_ticket_array['prepaid_type'];
        } else if ($billtohotel == '0') {
            $insert_hgs['activation_method'] = "1";
        } else {
            $insert_hgs['activation_method'] = "3";
        }
        $insert_hgs['id'] = $visitor_tickets_id;
        if(!empty($voucher_creation_date)){
            $insert_hgs['voucher_creation_date'] = $voucher_creation_date;
        }
        $insert_hgs['is_prioticket'] = $is_prioticket;
        $insert_hgs['targetlocation'] = $confirm_ticket_array['clustering_id'];
        $insert_hgs['card_name'] = $confirm_ticket_array['discount_label'];
        $insert_hgs['created_date'] = isset($confirm_ticket_array['cpos_created_date']) ? $confirm_ticket_array['cpos_created_date'] : $current_date;
        $insert_hgs['transaction_id'] = $transaction_id;
        $insert_hgs['visitor_invoice_id'] = $transaction_id;
        $insert_hgs['invoice_id'] = "";
        $insert_hgs['channel_id'] = $channel_id;
        $insert_hgs['channel_name'] = $confirm_ticket_array['channel_name'];
        $insert_hgs['financial_id'] = $confirm_ticket_array['financial_id'];
        $insert_hgs['financial_name'] = $confirm_ticket_array['financial_name'];
        $insert_hgs['reseller_id'] = $reseller_id;
        $insert_hgs['reseller_name'] = $reseller_name;
        $insert_hgs['saledesk_id'] = $saledesk_id;
        $insert_hgs['saledesk_name'] = $saledesk_name;
        if (isset($confirm_ticket_array['invoice_type'])) {
            $insert_hgs['invoice_type'] = $confirm_ticket_array['invoice_type'];
        }
        $insert_hgs['order_updated_cashier_id'] = $order_updated_cashier_id;
        $insert_hgs['order_updated_cashier_name'] = $order_updated_cashier_name;
        $insert_hgs['redeem_method'] = $redeem_method;
        $insert_hgs['ticketId'] = $ticketId;
        $insert_hgs['ticket_title'] = $ticket_title;
        $insert_hgs['all_ticket_ids'] = $voucher_reference;
        $insert_hgs['is_refunded'] = $is_refunded;
        $insert_hgs['user_name'] = $userdata->guest_names;
        $insert_hgs['ticketwithdifferentpricing'] = $ticketwithdifferentpricing;
        $insert_hgs['version'] = $version;
        $insert_hgs['ticketpriceschedule_id'] = $ticketpriceschedule_id;
        $insert_hgs['partner_id'] = $confirm_ticket_array['distributor_reseller_id'];
        $insert_hgs['partner_name'] = $confirm_ticket_array['distributor_reseller_name'];
        $insert_hgs['is_custom_setting'] = $pax;
        $insert_hgs['museum_name'] = $museum_name;
        $insert_hgs['museum_id'] = $museum_id;
        $insert_hgs['hotel_name'] = $hotel_name;
        $insert_hgs['primary_host_name'] = $primary_host_name;
        $insert_hgs['hotel_id'] = $hotel_id;
        $insert_hgs['shift_id'] = $shift_id;
        $insert_hgs['pos_point_id'] = $pos_point_id;
        $insert_hgs['pos_point_name'] = $pos_point_name;
        $insert_hgs['passNo'] = $passNo;
        $insert_hgs['extra_discount'] = $extra_discount;
        $insert_hgs['pass_type'] = $pass_type;
        $insert_hgs['ticketAmt'] = $ticketAmt;
        $insert_hgs['visit_date'] = $visit_date;
        $insert_hgs['partner_category_id'] = $partner_category_id;
        $insert_hgs['partner_category_name'] = $partner_category_name;
        $insert_hgs['vt_group_no'] = $visitor_group_no;
        $insert_hgs['visit_date_time'] = $visit_date_time;
        $insert_hgs['ticketType'] = $ticketType;
        $insert_hgs['tickettype_name'] = $tickettype_name;
        $insert_hgs['paid'] = "0";
        $insert_hgs['distributor_partner_id'] = $distributor_partner_id;
        $insert_hgs['distributor_partner_name'] = $distributor_partner_name;
        $insert_hgs['isBillToHotel'] = $billtohotel;
        $insert_hgs['commission_type'] = isset($confirm_ticket_array['commission_type']) ? $confirm_ticket_array['commission_type'] : '0';
        $insert_hgs['ticketPrice'] = ($ticketPrice != '') ? $ticketPrice : '';
        $insert_hgs['debitor'] = $hgs_debitor;        
        $insert_hgs['discount'] = $insert_visitor['discount'];
        $insert_hgs['is_block'] = $is_block;
        $insert_hgs['isDiscountInPercent'] = $ispercenttype;
        $insert_hgs['creditor'] = $hgs_creditor;
        if ($commission_on_sale_price == 0) {
            $insert_hgs['partner_gross_price'] =  $calculated_hgs_commission + $sale_variation_amount - $resale_variation_amount - $distributor_commission;
            $reseller_sale_net_amount = round(($sale_variation_amount * 100) / ($hgs_tax_value + 100),2);
            $insert_hgs['partner_net_price'] =  $hgsnetprice + $reseller_sale_net_amount - $resale_net_variation_amount - $dist_net_commission;
        } else {
            $insert_hgs['partner_gross_price'] =  $calculated_hgs_commission ;
            $insert_hgs['partner_net_price'] =  $hgsnetprice;
        }
        $insert_hgs['order_currency_partner_gross_price'] = $insert_visitor['order_currency_partner_gross_price'];
        $partner_gross_price_without_combi_discount = $is_combi_discount == '1' ? $hgs_gross_price_without_combi_discount : $calculated_hgs_commission;
        $insert_hgs['partner_gross_price_without_combi_discount'] = $partner_gross_price_without_combi_discount ;
        
        $insert_hgs['order_currency_partner_net_price'] = $insert_visitor['order_currency_partner_net_price'];
        $partner_net_price_without_combi_discount = $is_combi_discount == '1' ? $hgs_net_price_without_combi_discount : $hgsnetprice;
        $insert_hgs['partner_net_price_without_combi_discount'] = $partner_net_price_without_combi_discount;
        /* #region In case of bundle product parter net price and partner gross price will calculate on the basis of main ticket hotel commision */
        if($mec_is_combi == '4'){
            $ticket_level_commissions_bundle_row   =   $ticket_level_commissions_bundle;
            foreach( $ticket_level_commissions_bundle as $bundle_result ){
                $bundle_commissions_values = (array) $bundle_result;
                if(in_array($ticket_level_commissions->ticket_type, $bundle_commissions_values)){
                    $partner_net_price_bundle_row4  =   $subtotal_net_amount * $bundle_commissions_values['hgs_prepaid_commission_percentage']/100;
                    $partner_gross_price_bundle_row4  =   ($partner_net_price_bundle_row4 * $ticket_level_commissions->ticket_tax_value /100) + $partner_net_price_bundle_row4;
                } else {
                    $partner_net_price_bundle   =   $subtotal_net_amount * $ticket_level_commissions_bundle_row[0]->hgs_prepaid_commission_percentage/100;
                    $partner_gross_price_bundle   =   ($partner_net_price_bundle * $ticket_level_commissions->ticket_tax_value /100) + $partner_net_price_bundle;
                }
                $insert_hgs['partner_net_price'] =  $partner_net_price_bundle_row4;
                $insert_hgs['partner_net_price_without_combi_discount'] =  $partner_net_price_bundle_row4;
                $insert_hgs['partner_gross_price'] = $partner_gross_price_bundle_row4;
                $insert_hgs['partner_gross_price_without_combi_discount'] = $partner_gross_price_bundle_row4;
            }
            
        }
        /* #endregion In case of bundle product parter net price and partner gross price will calculate on the basis of main ticket hotel commision */
        if($markup_price > 0) {
            $insert_hgs['partner_gross_price'] += $markup_price;
            $insert_hgs['partner_net_price'] +=  round(($markup_price * 100) / ($hgs_tax_value + 100),2);
        }
        $insert_hgs['isCommissionInPercent'] = "0";
        $insert_hgs['tax_id'] =  $hgs_tax_id ;
        $insert_hgs['tax_value'] =  $hgs_tax_value ;
        $insert_hgs['used'] = $is_used;
        $insert_hgs['without_elo_reference_no'] = $without_elo_reference_no;
        $insert_hgs['extra_text_field_answer'] = $extra_text_field_answer;
        $insert_hgs['is_voucher'] = $is_voucher;
        $insert_hgs['action_performed'] = $confirm_ticket_array['action_performed'];
        $insert_hgs['updated_at'] = $current_date;
        $insert_hgs['tp_payment_method'] = $tp_payment_method;
        $insert_hgs['order_confirm_date'] = $order_confirm_date;
        $insert_hgs['payment_date'] = $payment_date;
        if ($is_voucher == '1') {
            // $insert_hgs['activation_method'] = '10';
            $insert_hgs['activation_method'] = $confirm_ticket_array['prepaid_type'];
        }
        if ($confirm_ticket_array['is_addon_ticket'] == "1") {
            $insert_hgs['col2'] = $confirm_ticket_array['is_addon_ticket'];
        }
        $insert_hgs['tax_name'] = $hgs_tax_name;
        $insert_hgs['timezone'] = $timeZone;
        $insert_hgs['invoice_status'] = $invoice_status;
        $insert_hgs['row_type'] = "4";
        $insert_hgs['nights'] = $is_iticket_product;
        $insert_hgs['ticket_status'] = $insert_visitor['ticket_status'];
        $insert_hgs['merchant_admin_id'] =  $merchant_admin_id;
        $insert_hgs['merchant_admin_name'] = $merchant_admin_name;
        $insert_hgs['market_merchant_id'] = $market_merchant_id;        
        $insert_hgs['targetcity'] = $targetcity;
        $insert_hgs['paymentMethodType'] = $paymentMethodType;
        $insert_hgs['service_name'] = SERVICE_NAME;
        $insert_hgs['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
        $insert_hgs['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
        if (isset($confirm_ticket_array['scanned_pass'])) {
            $insert_hgs['scanned_pass'] = $scanned_pass;
            $insert_hgs['groupTransactionId'] = $groupTransactionId;
        }
        $insert_hgs['transaction_type_name'] = "Provider cost";
        $insert_hgs['updated_by_id'] = $resuid;
        $insert_hgs['cashier_id'] = $cashier_id;
        $insert_hgs['cashier_name'] = $cashier_name;
        $insert_hgs['voucher_updated_by'] = $resuid;
        $insert_hgs['voucher_updated_by_name'] = $resfname . ' ' . $reslname;
        $insert_hgs['redeem_method'] =$redeem_method;
        $insert_hgs['is_shop_product'] = (isset($is_shop_product) && $is_shop_product != '') ? $is_shop_product : '0';
        if (isset($confirm_ticket_array['split_payment']) && !empty($confirm_ticket_array['split_payment'])) {
            $insert_hgs['activation_method'] = '9';
            $insert_hgs['split_cash_amount'] = $split_cash_amount;
            $insert_hgs['split_card_amount'] = $split_card_amount;
            $insert_hgs['split_direct_payment_amount'] = $split_direct_amount;
            $insert_hgs['split_voucher_amount'] = $split_voucher_amount;
        }
        $insert_hgs['updated_by_username'] = $resfname . ' ' . $reslname;
        if (((isset($confirm_ticket_array['ticketsales']) && $confirm_ticket_array['ticketsales'] > 0) || isset($confirm_ticket_array['is_pre_booked_ticket']) && $confirm_ticket_array['is_pre_booked_ticket'] > 0) || $is_prepaid == "1") {
            $insert_hgs['is_prepaid'] = "1";
        }
        $insert_hgs['selected_date'] = $selected_date;
        $insert_hgs['booking_selected_date'] = $booking_selected_date;
        $insert_hgs['from_time'] = $from_time;
        $insert_hgs['to_time'] = $to_time;
        $insert_hgs['slot_type'] = $slot_type;
        $insert_hgs['hto_id'] = $hto_id;
        $insert_hgs['ticket_booking_id'] = $ticket_booking_id;
        $insert_hgs[$this->admin_currency_code_col] = !empty($admin_prices['currency_code']) ? $admin_prices['currency_code'] : $confirm_ticket_array['supplier_currency_code'];
        $insert_hgs['booking_status'] = $booking_status;
        $insert_hgs['channel_type'] = $channel_type;
        $insert_hgs['supplier_gross_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_gross_price'][4] : $insert_hgs['partner_gross_price'];
        $insert_hgs['supplier_discount'] = !empty($supplier_prices) ? $supplier_prices['supplier_discount'] : $confirm_ticket_array['supplier_discount'];
        $insert_hgs['supplier_ticket_amt'] = $confirm_ticket_array['supplier_ticket_amt'];
        $insert_hgs['supplier_tax_value'] = $supplier_hgs_tax_value;
        $insert_hgs['supplier_net_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_net_price'][4] : $insert_hgs['partner_net_price'];
        $insert_hgs[$this->merchant_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_gross_price'][4] : $insert_hgs['partner_gross_price'];
        $insert_hgs[$this->merchant_net_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_net_price'][4] : $insert_hgs['partner_net_price'];
        $insert_hgs[$this->merchant_tax_id_col] = !empty($market_merchant_prices) ? $market_merchant_prices['ticket_tax_id'] : $insert_hgs['tax_id'];
        $insert_hgs[$this->supplier_tax_id_col] = !empty($supplier_prices) ? $supplier_prices['ticket_tax_id'] : $insert_hgs['tax_id'];
        $insert_hgs[$this->merchant_currency_code_col] = !empty($market_merchant_prices) ? $market_merchant_prices['currency_code'] : $insert_hgs[$this->admin_currency_code_col];
        $insert_hgs['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_hgs[$this->admin_currency_code_col];
        $insert_hgs['issuer_country_name'] = isset($confirm_ticket_array['issuer_country_name']) ? $confirm_ticket_array['issuer_country_name'] : '';
        $insert_hgs['distributor_type'] = isset($confirm_ticket_array['distributor_type']) ? $confirm_ticket_array['distributor_type'] : '';
        $insert_hgs['group_type_ticket'] = $group_type_ticket;
        $insert_hgs['group_price'] = $group_price;
        $insert_hgs['group_quantity'] = $group_quantity;
        $insert_hgs['group_linked_with'] = $group_linked_with;
        $insert_hgs['supplier_currency_symbol'] = $confirm_ticket_array['supplier_currency_symbol'];
        $insert_hgs['order_currency_code'] = $confirm_ticket_array['order_currency_code'];
        $insert_hgs['order_currency_symbol'] = $confirm_ticket_array['order_currency_symbol'];
        $insert_hgs['currency_rate'] = $confirm_ticket_array['currency_rate'];
        $insert_hgs['adyen_status'] = $commission_level;
        if (!empty($is_custom_setting) && $is_custom_setting > 0) {
            $insert_hgs['external_product_id'] = $external_product_id;
        } else if ('4' == $mec_is_combi) {
            /* external_product_id to be added (23-12-2022) */
            $insert_combi_data['external_product_id'] = $related_product_id;
        }
        $insert_hgs['account_number'] = $account_number;
        $insert_hgs['chart_number'] = $chart_number;
        if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
            $insert_hgs['voucher_updated_by'] = $voucher_updated_by;
            $insert_hgs['voucher_updated_by_name'] = $voucher_updated_by_name;
        }
        if ($uncancel_order == 1) {
            if($channel_type == 7 || $channel_type == 6){
                $insert_hgs['invoice_status'] = '0';                
            } else {
                $insert_hgs['invoice_status'] = '12';
                $insert_hgs['action_performed'] = $insert_hgs['action_performed'].', CSS_RFN_CANCEL';          
            }
            $insert_hgs['is_refunded'] = '0';                          
            //$insert_hgs['created_date'] = gmdate('Y-m-d H:i:s');
            //$insert_hgs['visit_date_time'] = gmdate('Y/m/d H:i:s');
            //$insert_hgs['visit_date'] = strtotime(gmdate('m/d/Y H:i:s'));
            $insert_hgs['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        $debugLogs['insert_hgs'] = json_encode($insert_hgs);
        $this->CreateLog("debug_hgs_pricing.txt", 'insert_hgs_array_'.$insert_visitor['vt_group_no'], $debugLogs);
        //In case of cluster ticket don't need to save data of this row
        if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
           
            $visitor_ticket_rows_batch [] = $insert_hgs;
        }
        $is_row_15_inserted = 0;
        if (isset($order_currency_extra_discount_array['gross_discount_amount']) && $order_currency_extra_discount_array['gross_discount_amount'] > 0) {
            $is_row_15_inserted = 1;
            $insert_extra_discount_data = $insert_museum;
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "15");
            $insert_extra_discount_data['id'] = $visitor_tickets_id;
            if(!empty($voucher_creation_date)){
                $insert_extra_discount_data['voucher_creation_date'] = $voucher_creation_date;
            }
            $insert_extra_discount_data['partner_id'] = isset($booking_id) ? $booking_id : '';
            $insert_extra_discount_data['partner_name'] = isset($booking_name) ? $booking_name : '';
            if (isset($extra_discount_array['is_different_tax_rate'])) {
                $insert_extra_discount_data['tax_value'] = $extra_discount_array['tax_value'];
                $tax_value = $extra_discount_array['tax_value'];
                $qery = "select * from store_taxes where trim(tax_value) =  $tax_value";
                $result = $this->primarydb->db->query($qery);
                if ($result->num_rows() > 0) {
                        $store_tax_data = $result->row();                       
                        $insert_extra_discount_data['tax_name'] = $store_tax_data->tax_name;
                        $insert_extra_discount_data['tax_id'] = $store_tax_data->id;
                    }
            }
            $insert_extra_discount_data['partner_gross_price'] = $extra_discount_array['gross_discount_amount'];
            $insert_extra_discount_data['order_currency_partner_gross_price'] = 0.00;
            $insert_extra_discount_data['partner_gross_price_without_combi_discount'] = $extra_discount_array['gross_discount_amount'];
            $insert_extra_discount_data['partner_net_price'] = $extra_discount_array['net_discount_amount'];
            $insert_extra_discount_data['order_currency_partner_net_price'] = 0.00;
            $insert_extra_discount_data['partner_net_price_without_combi_discount'] = $extra_discount_array['net_discount_amount'];
            $insert_extra_discount_data['transaction_type_name'] = "Partner fees";
            $insert_extra_discount_data['row_type'] = "15";
            $insert_extra_discount_data['nights'] = $is_iticket_product;
            $insert_extra_discount_data['ticket_status'] = $insert_visitor['ticket_status'];
            $insert_extra_discount_data['merchant_admin_id'] = $merchant_admin_id;
            $insert_extra_discount_data['merchant_admin_name'] =  $merchant_admin_name;
            $insert_extra_discount_data['market_merchant_id'] = $market_merchant_id;
            $insert_extra_discount_data['ticketAmt'] = $ticketAmt;
            $insert_extra_discount_data['supplier_gross_price'] = "0.00";
            $insert_extra_discount_data['supplier_discount']    = "0.00";
            $insert_extra_discount_data['supplier_ticket_amt']  = "0.00";
            $insert_extra_discount_data['supplier_tax_value']   = "0.00";
            $insert_extra_discount_data['supplier_net_price']   = "0.00";
            $insert_extra_discount_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
            $insert_extra_discount_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
            $insert_extra_discount_data['targetlocation'] = $confirm_ticket_array['clustering_id'];
            $insert_extra_discount_data['card_name'] = $confirm_ticket_array['discount_label'];
            $insert_extra_discount_data['discount'] = $insert_visitor['discount'];
            $insert_extra_discount_data['adyen_status'] = $pricing_level;
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                $visitor_ticket_rows_batch [] = $insert_extra_discount_data;
            }
        }
        if (isset($extra_discount_array['gross_discount_amount']) && $extra_discount_array['gross_discount_amount'] > 0 && $is_row_15_inserted == 0 && ($channel_type == 3 || (!empty($confirm_ticket_array['action_from']) && $confirm_ticket_array['action_from'] == 'V3.2'))) {
            $insert_extra_discount_data = $insert_museum;
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "15");
            $insert_extra_discount_data['id'] = $visitor_tickets_id;
            $insert_extra_discount_data['partner_id'] = isset($booking_id) ? $booking_id : '';
            $insert_extra_discount_data['partner_name'] = isset($booking_name) ? $booking_name : '';
            $insert_extra_discount_data['partner_gross_price'] = $extra_discount_array['gross_discount_amount'];
            $insert_extra_discount_data['order_currency_partner_gross_price'] = 0.00;
            $insert_extra_discount_data['partner_gross_price_without_combi_discount'] =  $extra_discount_array['gross_discount_amount'];
            $insert_extra_discount_data['partner_net_price'] =  $extra_discount_array['net_discount_amount'];
            $insert_extra_discount_data['order_currency_partner_net_price'] = 0.00;
            $insert_extra_discount_data['partner_net_price_without_combi_discount'] = $extra_discount_array['net_discount_amount'];
            $insert_extra_discount_data['transaction_type_name'] = "Partner fees";
            $insert_extra_discount_data['row_type'] = "15";
            $insert_extra_discount_data['nights'] = $is_iticket_product;
            $insert_extra_discount_data['ticket_status'] = $insert_visitor['ticket_status'];
            $insert_extra_discount_data['merchant_admin_id'] =  $merchant_admin_id;
            $insert_extra_discount_data['merchant_admin_name'] =  $merchant_admin_name;
            $insert_extra_discount_data['market_merchant_id'] = $market_merchant_id;
            $insert_extra_discount_data['partner_category_id'] = $partner_category_id;
            $insert_extra_discount_data['partner_category_name'] = $partner_category_name;
            $insert_extra_discount_data['supplier_gross_price'] = "0.00";
            $insert_extra_discount_data['supplier_discount']    = "0.00";
            $insert_extra_discount_data['supplier_ticket_amt']  = "0.00";
            $insert_extra_discount_data['supplier_tax_value']   = "0.00";
            $insert_extra_discount_data['supplier_net_price']   = "0.00";
	        $insert_extra_discount_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
            $insert_extra_discount_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
            if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
                $insert_extra_discount_data['voucher_updated_by'] = $voucher_updated_by;
                $insert_extra_discount_data['voucher_updated_by_name'] = $voucher_updated_by_name;
            }
            if ((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket'])) {
                $visitor_ticket_rows_batch [] = $insert_extra_discount_data;
            }
        }
        
        $insert_merchant_data = $insert_museum;
        $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "17");
        $insert_merchant_data['id'] = $visitor_tickets_id;
        if(!empty($voucher_creation_date)){
            $insert_merchant_data['voucher_creation_date'] = $voucher_creation_date;
        }
            // Comission for merchant admin.
            
        if($merchant_fees_assigned == '1'){
            $insert_merchant_data['partner_gross_price'] = $ticket_level_commissions->merchant_gross_commission ;
            $insert_merchant_data['partner_gross_price_without_combi_discount'] = $ticket_level_commissions->merchant_gross_commission;
            $insert_merchant_data['partner_net_price'] = $ticket_level_commissions->merchant_net_commission ; 
            $insert_merchant_data['partner_net_price_without_combi_discount'] = $insert_merchant_data['partner_net_price'] ;
            $insert_merchant_data['tax_id'] =  $ticket_tax_id ;
            $insert_merchant_data['tax_value'] =  $ticket_tax_value ;
            $insert_merchant_data['tax_name'] =  $ticket_tax_name;            
            $insert_merchant_data['merchant_admin_id'] =  $merchant_admin_id ;
            $insert_merchant_data['partner_id'] =  $merchant_admin_id ;
            $insert_merchant_data['partner_name'] =$merchant_admin_name ;
        } else {
            $insert_merchant_data['partner_gross_price'] =  '0';
            $insert_merchant_data['partner_gross_price_without_combi_discount'] = '0';
            $insert_merchant_data['partner_net_price'] = '0'; 
            $insert_merchant_data['partner_net_price_without_combi_discount'] = '0';
            $insert_merchant_data['tax_id'] = '0';
            $insert_merchant_data['tax_value'] = '';
            $insert_merchant_data['tax_name'] = '';            
            $insert_merchant_data['merchant_admin_id'] = $merchant_admin_id;
            $insert_merchant_data['partner_id'] = $merchant_admin_id ;
            $insert_merchant_data['partner_name'] = $merchant_admin_name;
        }
        $insert_merchant_data['order_updated_cashier_id'] = $order_updated_cashier_id;
        $insert_merchant_data['order_updated_cashier_name'] = $order_updated_cashier_name;
        $insert_merchant_data['redeem_method'] = $redeem_method;
        $insert_merchant_data['transaction_type_name'] = "Merchant fee";
        $insert_merchant_data['row_type'] = "17";
        $insert_merchant_data['nights'] = $is_iticket_product;
        $insert_merchant_data['ticket_status'] = $insert_visitor['ticket_status'];
        $insert_merchant_data['merchant_admin_id'] = $merchant_admin_id;
        $insert_merchant_data['merchant_admin_name'] =  $merchant_admin_name;
        $insert_merchant_data['market_merchant_id'] = $market_merchant_id;
        $insert_merchant_data['ticketAmt'] = $ticketAmt;
        $insert_merchant_data['col7'] = gmdate('Y-m', strtotime($order_confirm_date));
        $insert_merchant_data['col8'] = gmdate('Y-m-d', strtotime($order_confirm_date) + ($timeZone * 3600));
        $insert_merchant_data['discount'] = $insert_visitor['discount'];
        $insert_merchant_data['adyen_status'] = $pricing_level;
        $insert_merchant_data['supplier_gross_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_gross_price'][17] : $insert_merchant_data['partner_gross_price'];
        $insert_merchant_data['supplier_net_price'] = !empty($supplier_prices) ? $supplier_prices['supplier_net_price'][17] : $insert_merchant_data['partner_net_price'];
        $insert_merchant_data[$this->merchant_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_gross_price'][17] : $insert_merchant_data['partner_gross_price'];
        $insert_merchant_data[$this->merchant_net_price_col] = !empty($market_merchant_prices) ? $market_merchant_prices['supplier_net_price'][17] : $insert_merchant_data['partner_net_price'];
        $insert_merchant_data[$this->merchant_tax_id_col] = !empty($market_merchant_prices) ? $market_merchant_prices['ticket_tax_id'] : $insert_merchant_data['tax_id'];
        $insert_merchant_data[$this->supplier_tax_id_col] = !empty($supplier_prices) ? $supplier_prices['ticket_tax_id'] : $insert_merchant_data['tax_id'];
        $insert_merchant_data[$this->merchant_currency_code_col] = !empty($market_merchant_prices) ? $market_merchant_prices['currency_code'] : $insert_merchant_data[$this->admin_currency_code_col];
        $insert_merchant_data['supplier_currency_code'] = !empty($confirm_ticket_array['supplier_currency_code']) ? $confirm_ticket_array['supplier_currency_code'] : $insert_merchant_data[$this->admin_currency_code_col];
        if( in_array($channel_type, $mposChannelTypes) && !empty( $voucher_updated_by ) ) {
            $insert_merchant_data['voucher_updated_by'] = $voucher_updated_by;
            $insert_merchant_data['voucher_updated_by_name'] = $voucher_updated_by_name;
        }
        if (((isset($confirm_ticket_array['is_addon_ticket']) && $confirm_ticket_array['is_addon_ticket'] != "2") || !isset($confirm_ticket_array['is_addon_ticket']))) {
            $visitor_ticket_rows_batch [] = $insert_merchant_data;
        }
        if ($sale_variation_amount != 0 && $confirm_ticket_array['is_addon_ticket'] != "2") {
            //Insert row type 20 for sale variation amount if amount != 0 like +1/-1 and for main ticket
            $insert_sale_variation_data = $insert_visitor;
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "20");
            $insert_sale_variation_data['id'] = $visitor_tickets_id;
            $insert_sale_variation_data['partner_gross_price'] = $sale_variation_amount ;
            $insert_sale_variation_data['order_currency_partner_gross_price'] = 0.00 ;
            $insert_sale_variation_data['partner_gross_price_without_combi_discount'] = $sale_variation_amount;
            $insert_sale_variation_data['partner_net_price'] = $sale_net_variation_amount ; 
            $insert_sale_variation_data['order_currency_partner_net_price'] = 0.00; 
            $insert_sale_variation_data['partner_net_price_without_combi_discount'] = $sale_net_variation_amount ;
            $insert_sale_variation_data['transaction_type_name'] = "Sale Variation Amount";
            $insert_sale_variation_data['row_type'] = "20";
            $insert_sale_variation_data['supplier_gross_price'] = $sale_variation_amount;
            $insert_sale_variation_data['supplier_net_price'] = $sale_net_variation_amount;
            $insert_sale_variation_data[$this->merchant_price_col] = $sale_variation_amount;
            $insert_sale_variation_data[$this->merchant_net_price_col] = $sale_net_variation_amount;
            $visitor_ticket_rows_batch [] = $insert_sale_variation_data;
        }
        if ($is_commission_on_variation == 1 && $confirm_ticket_array['is_addon_ticket'] != "2") {
            //Insert row type 22 for sale variation amount if amount != 0 like +1/-1 and for main ticket
            $insert_resale_variation_data = $insert_museum;
            $hotel_sale_variation = $sale_variation_amount * $ticket_level_commissions->hotel_prepaid_commission_percentage / 100;
            $hotel_sale_net_variation = round(($hotel_sale_variation * 100) / ($ticket_tax_value + 100),2);
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "22");
            $insert_resale_variation_data['id'] = $visitor_tickets_id;
            $insert_resale_variation_data['partner_gross_price'] = $hotel_sale_variation ;
            $insert_resale_variation_data['order_currency_partner_gross_price'] = 0.00 ;
            $insert_resale_variation_data['partner_gross_price_without_combi_discount'] = $hotel_sale_variation;
            $insert_resale_variation_data['partner_net_price'] = $hotel_sale_net_variation ; 
            $insert_resale_variation_data['order_currency_partner_net_price'] = 0.00; 
            $insert_resale_variation_data['partner_net_price_without_combi_discount'] = $hotel_sale_net_variation ;
            $insert_resale_variation_data['transaction_type_name'] = "Distributor Fee Variation Amount";
            $insert_resale_variation_data['row_type'] = "22";
            $insert_resale_variation_data['supplier_gross_price'] = $hotel_sale_variation;
            $insert_resale_variation_data['supplier_net_price'] = $hotel_sale_net_variation;
            $insert_resale_variation_data[$this->merchant_price_col] = $hotel_sale_variation;
            $insert_resale_variation_data[$this->merchant_net_price_col] = $hotel_sale_net_variation;
            $visitor_ticket_rows_batch [] = $insert_resale_variation_data;
        }

        /* #endregion to insert row_type= 22 i.e. Distributor Variation Row */
        /* #region  to insert row_type= 22 i.e. Markup price row*/
        if ($markup_price > 0 && $confirm_ticket_array['is_addon_ticket'] != "2") {
            //Insert row type 22 for sale variation amount if amount != 0 like +1/-1 and for main ticket
            $insert_resale_variation_data = $insert_museum;
            $hotel_sale_variation = $markup_price;
            $hotel_sale_net_variation = round(($hotel_sale_variation * 100) / ($ticket_tax_value + 100),2);
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "22");
            $insert_resale_variation_data['id'] = $visitor_tickets_id;
            $insert_resale_variation_data['partner_gross_price'] = $hotel_sale_variation ;
            $insert_resale_variation_data['order_currency_partner_gross_price'] = 0.00 ;
            $insert_resale_variation_data['partner_gross_price_without_combi_discount'] = $hotel_sale_variation;
            $insert_resale_variation_data['partner_net_price'] = $hotel_sale_net_variation ; 
            $insert_resale_variation_data['order_currency_partner_net_price'] = 0.00; 
            $insert_resale_variation_data['partner_net_price_without_combi_discount'] = $hotel_sale_net_variation ;
            $insert_resale_variation_data['transaction_type_name'] = "Distributor Fee Markup Price";
            $insert_resale_variation_data['row_type'] = "22";
            $insert_resale_variation_data['supplier_gross_price'] = $hotel_sale_variation;
            $insert_resale_variation_data['supplier_net_price'] = $hotel_sale_net_variation;
            $insert_resale_variation_data[$this->merchant_price_col] = $hotel_sale_variation;
            $insert_resale_variation_data[$this->merchant_net_price_col] = $hotel_sale_net_variation;
            $visitor_ticket_rows_batch [] = $insert_resale_variation_data;
        }
        /* #endregion to insert row_type= 22 i.e. Markup price row*/
        /* #region  to insert row_type= 21 i.e. Resale Variation Row */

        if ($resale_variation_amount != 0 && $confirm_ticket_array['is_addon_ticket'] != "2") {
            //Insert row type 21 for resale variation amount if amount != 0 like +1/-1 and for main ticket
            $insert_resale_variation_data = $insert_museum;
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "21");
            $insert_resale_variation_data['id'] = $visitor_tickets_id;
            $insert_resale_variation_data['partner_gross_price'] = $resale_variation_amount ;
            $insert_resale_variation_data['order_currency_partner_gross_price'] = 0.00 ;
            $insert_resale_variation_data['partner_gross_price_without_combi_discount'] = $resale_variation_amount;
            $insert_resale_variation_data['partner_net_price'] = $resale_net_variation_amount ; 
            $insert_resale_variation_data['order_currency_partner_net_price'] = 0.00; 
            $insert_resale_variation_data['partner_net_price_without_combi_discount'] = $resale_net_variation_amount ;
            $insert_resale_variation_data['transaction_type_name'] = "Resale Variation Amount";
            $insert_resale_variation_data['row_type'] = "21";
            $insert_resale_variation_data['supplier_gross_price'] = $resale_variation_amount;
            $insert_resale_variation_data['supplier_net_price'] = $resale_net_variation_amount;
            $insert_resale_variation_data[$this->merchant_price_col] = $resale_variation_amount;
            $insert_resale_variation_data[$this->merchant_net_price_col] = $resale_net_variation_amount;
            $visitor_ticket_rows_batch [] = $insert_resale_variation_data;
        }
        /* #endregion to insert row_type= 21 i.e. Resale Variation Row */
        if ($is_commission_applicable_varation == 1 && $ticket_level_commissions->is_hotel_prepaid_commission_percentage == 1) {
            //Insert row type 23 for Commission amount if amount != 0 like +1/-1 and for main ticket
            $insert_resale_variation_data = $insert_museum;
            $visitor_tickets_id = $this->get_auto_generated_id_dpos($visitor_group_no, $prepaid_ticket_id, "23");
            $insert_resale_variation_data['id'] = $visitor_tickets_id;
            $insert_resale_variation_data['partner_gross_price'] = $distributor_commission;
            $insert_resale_variation_data['order_currency_partner_gross_price'] = 0.00 ;
            $insert_resale_variation_data['partner_gross_price_without_combi_discount'] = $distributor_commission;
            $insert_resale_variation_data['partner_net_price'] = $dist_net_commission; 
            $insert_resale_variation_data['order_currency_partner_net_price'] = 0.00; 
            $insert_resale_variation_data['partner_net_price_without_combi_discount'] = $dist_net_commission;
            $insert_resale_variation_data['transaction_type_name'] = "Distributor Commission";
            $insert_resale_variation_data['row_type'] = "23";
            $insert_resale_variation_data['supplier_gross_price'] = $distributor_commission;
            $insert_resale_variation_data['supplier_net_price'] = $dist_net_commission;
            $insert_resale_variation_data[$this->merchant_price_col] = $distributor_commission;
            $insert_resale_variation_data[$this->merchant_net_price_col] = $dist_net_commission;
            $visitor_ticket_rows_batch [] = $insert_resale_variation_data;
        }
        $response = array();
        $response['insertedId'] = $insertedId;
        $response['visitor_ticket_rows_batch'] = $visitor_ticket_rows_batch;
        $response['ticket_tax_id'] = $ticket_tax_id;
        $response['ticket_level_commissions'] = $ticket_level_commissions;
        /* #region to prepare main ticket array for bundle products */
        $response['ticket_level_commissions_bundle'] = $ticket_level_commissions_bundle;
        $response['ticket_level_commissions_bundle_row'] = $ticket_level_commissions_bundle_row;
        /* #endregion to prepare main ticket array for bundle products */
        return $response;
    }
    /* #endregion To get the ticket data as per ticket confirm detail*/
    
    /* #region get age group and discount from ticketschedule id   */
    /**
     * getAgeGroupAndDiscount
     *
     * @param  mixed $ticketpriceschedule_id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getAgeGroupAndDiscount($ticketpriceschedule_id = '') {
        
        $qry = 'select ticketType, pricetext, agefrom, ageto, discount, saveamount, deal_type_free, totalCommission, totalNetCommission, museumCommission, hotelCommission, calculated_hotel_commission, hgsCommission, calculated_hgs_commission, isCommissionInPercent, museum_tax_id, hotel_tax_id, hgs_tax_id, ticket_tax_id, museumNetPrice, hotelNetPrice, hgsnetprice, ticket_net_price, hgs_provider_id, hgs_provider_name, ticket_type_label, currency_code from ticketpriceschedule where id=' . $ticketpriceschedule_id;
        $res = $this->primarydb->db->query($qry);
        return $res->row();
    }
    /* #endregion get age group and discount from ticketschedule id*/

    /* #region get the currenct season of ticket for ticket_type in case of channel_type 2,5  */
    /**
     * get_current_season_tps_id
     *
     * @param  mixed $ticketId
     * @param  mixed $tickettype_name
     * @return void
     */
    function get_current_season_tps_id($ticketId,$tickettype_name){
        $timestamp = time();
        $qry = 'select id from ticketpriceschedule where ticket_id=' . $ticketId. ' and LOWER(ticket_type_label) = "'.strtolower($tickettype_name).'" and start_date <= "'.$timestamp.'" and end_date >= "'.$timestamp.'"';
        $res = $this->primarydb->db->query($qry);
        return $res->row()->id;
    }
    /* #endregion get the currenct season of ticket for ticket_type in case of channel_type 2,5*/
  
    /* #region getTicketTypeFromTicketpriceschedule_id  */
    /**
     * getTicketTypeFromTicketpriceschedule_id
     *
     * @param  mixed $ticketpriceschedule_id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getTicketTypeFromTicketpriceschedule_id($ticketpriceschedule_id = '', $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $query = 'select tps.*, tt.ticketType as tickettype_name from ticketpriceschedule tps left join ticket_types tt on tt.id=tps.ticketType and tt.status="1" where tps.id = ' . $ticketpriceschedule_id;
        $data = $db->query($query);
        if ($data->num_rows() > 0) {
            return $data->row();
        } else {
            return false;
        }
    }
    /* #endregion getTicketTypeFromTicketpriceschedule_id */
   
    /* #region used to capture the pending payments by cron and also when user check outs.  */
    /**
     * captureThePendingPaymentsv1
     *
     * @param  mixed $invoice_id
     * @param  mixed $pspReferenceNo
     * @param  mixed $checks
     * @param  mixed $cod_id
     * @param  mixed $visitor_group_number
     * @param  mixed $check_instant_payment
     * @param  mixed $is_secondary_db
     * @return void
     */
    function captureThePendingPaymentsv1($invoice_id = '', $pspReferenceNo = '', $checks = 0, $cod_id = '', $visitor_group_number = '', $check_instant_payment = 1, $is_secondary_db = "0") {
        echo "started at :" . date('H:i:s');
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_object = new Sns();
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $db = $this->secondarydb->db;        
        $records = $this->getRecordsToCaptureTicketPaymentv1($pspReferenceNo, $cod_id, $visitor_group_number, $is_secondary_db);
        $check_in_option = '';
        $flag = 0;
        $paid_array = array();
        $visitor_group_no_array = array();
        if ($cod_id != '') {
            $hotelDetail = $this->common_model->seldealeventimage('qr_codes', 'cod_id', $cod_id);
            $check_in_option = $hotelDetail->check_in_option;
        }
        $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
        $timeZone = $servicedata->timeZone;
        $currencyCode = $servicedata->currency;
        $cancelflag = 1;
        $failreturnmsg = '';
        if ($pspReferenceNo == '') {
            $cancelflag = 0;
        }
        $cc_row_count= 950;
        if ($records && count($records) > 0) {
            //get data from service table            
            foreach ($records as $reckey => $record) {
                $pspReference = $record->pspReference;
                $order_merchantAccountCode = $record->merchantAccountCode;
                $isprepaid = $record->isprepaid;
                $visitor_group_no = $record->visitor_group_no;
                $card_tax_id = $record->card_tax_id;
                $tax_value = $record->tax_value;
                $hotel_name = $record->hotel_name;
                $parentPassNo = $record->parentPassNo;
                $shopperReference = $record->shopperReference;
                $payment_method = $record->paymentMethod;
                $shopperStatement = $record->shopperStatement;
                $total_fixed_amount = $record->total_fixed_amount;
                $total_variable_amount = $record->total_variable_amount;
                $total_amount_without_tax = $record->total_amount_without_tax;
                $total_amount_with_tax = $record->total_amount_with_tax;
                $hotel_id = $record->hotel_id;
                $refused = $record->refused;
                $is_block = $record->is_block;
                $visitor_tickets_ids = $record->all_ticket_ids;
                $cmpny = $this->common_model->companyName($hotel_id);
                $is_credit_card_fee_charged_to_guest = $cmpny->is_credit_card_fee_charged_to_guest;

                $array = explode(',', $visitor_tickets_ids);
                $transaction_code = $array [1];
                $manual_code = time() . '' . $reckey;
                // get next transaction id                
                $transaction_id = $manual_code;

                /* Credit card fee will be always charged from hotel for all GVB orders */
                if (substr($record->merchantReference, 0, 4) == 'GVB-') {
                    $is_credit_card_fee_charged_to_guest = '0';
                }

                $visit_date = strtotime(gmdate('m/d/Y H:i:s'));
                $partner_data = array();
                $partnersdata = array();
                $partnersdata['partner_id'] = $hotel_id;
                $partnersdata['partner_name'] = $cmpny->company;
                if ($is_credit_card_fee_charged_to_guest == "0") {
                    $amounttocapture = $total_amount_without_tax;
                    $debitor = 'Credit card distributor';
                    $invoice_status = "0";
                    $partner_data['partner_id'] = $hotel_id;
                    $partner_data['partner_name'] = $cmpny->company;
                } else {
                    $debitor = 'Guest';
                    $invoice_status = "3";
                    $amounttocapture = $total_amount_with_tax;
                }

                $totalFixedAmt = $total_fixed_amount;
                $totalVariableAmt = $total_variable_amount;

                $hotelDetail = $this->common_model->seldealeventimage('qr_codes', 'cod_id', $hotel_id);
                $check_in_option = $hotelDetail->check_in_option;
                $initial_payment_charged_to_guest = $hotelDetail->initial_payment_charged_to_guest;

                $flag = 0;
                $addtohotel = 0;

                if (($check_in_option == "2" || $check_in_option == "1" || $isprepaid == 1) && $initial_payment_charged_to_guest == 1 && $invoice_id == '') {
                    $initial_payment = $this->common_model->getSingleFieldValueFromTable('initial_payment_done', 'hotel_ticket_overview', array('pspReference' => $pspReference), $is_secondary_db);
                    if ($initial_payment == "0") {
                        $init_price = 0.1;
                        $initial_price = round(($init_price + (($init_price * $tax_value) / 100)), 2);
                        //insert 0.1 row in transaction table
                        $data1 = array();
                        if ($is_credit_card_fee_charged_to_guest == '1') {
                            $amounttocapture = $amounttocapture + $initial_price;
                            $data1['invoice_status'] = "5";
                            $data1['visitor_group_no'] = $visitor_group_no;
                            $data1['invoice_id'] = $invoice_id;
                        } else {
                            $data1['invoice_status'] = "0";
                            $data1['visitor_group_no'] = "";
                            $data1['invoice_id'] = "";
                            $addtohotel = 1;
                        }
                        $visitor_tickets_id = $visitor_group_no.$cc_row_count++.''.$this->get_two_digit_unique_no();
                        $data1['id'] = $visitor_tickets_id;
                        $data1['transaction_id'] = $transaction_id;
                        $data1['ticket_title'] = "Inital paynment " . substr($visitor_tickets_ids, 1);
                        $data1['visit_date'] = $visit_date;
                        $data1['timeZone'] = $timeZone;
                        $data1['debitor'] = "Guest";
                        $data1['creditor'] = "Debit";
                        $data1['partner_gross_price'] = $initial_price;
                        $data1['partner_net_price'] = $init_price;
                        $data1['tax_id'] = $card_tax_id;
                        $data1['tax_value'] = $tax_value;
                        $data1['row_type'] = 10;
                        $data1['all_ticket_ids'] = $visitor_tickets_ids;
                        $data1['transaction_type_name'] = "CC-initial fees";
                        $data1['paymentMethodType'] = '0';
                        $data1['is_credit_card_fee_charged_to_guest'] = $is_credit_card_fee_charged_to_guest;
                        $data1['order_total_price'] = (isset($record->order_total_price) && $record->order_total_price != '') ? $record->order_total_price : '0.00';
                        $data1['order_total_net_price'] = (isset($record->order_total_net_price) && $record->order_total_net_price != '') ? $record->order_total_net_price : '0.00';
                        $data1['order_total_tickets'] = (isset($record->order_total_tickets) && $record->order_total_tickets != '') ? $record->order_total_tickets : '0';
                        $data1['distributor_type'] = (isset($record->distributor_type) && $record->distributor_type != '') ? $record->distributor_type : '';
                        $data1['ticket_ids'] = (isset($record->ticket_ids) && $record->ticket_ids != '') ? $record->ticket_ids : '';
                        $data1['voucher_updated_by'] = (isset($record->voucher_updated_by) && $record->voucher_updated_by != '') ? $record->voucher_updated_by : '';
                        /* COMMENTED $updates_initial = 'update hotel_ticket_overview set initial_payment_done="1" where pspReference = "' . $pspReference . '"'; */
                        $updates_initial = '';
                    } else {
                        $updates_initial = '';
                    }
                } else {
                    $updates_initial = '';
                }
                if ($amounttocapture > 0) {
                    if (($pspReferenceNo == '0' || $pspReferenceNo == '' || $checks == 0) && $refused <= 0 && $is_block == '0') {
                        $newPspReference = $pspReference;
                        $merchantReference = substr($parentPassNo, -PASSLENGTH) . '-' . $hotel_name . '-' . $transaction_code;
                        $merchantAccountCode = $order_merchantAccountCode != '' ? $order_merchantAccountCode : MERCHANTCODE;
                        if ($isprepaid == 0) {
                            $result = $this->auth("LATEST", $amounttocapture, $merchantReference, $shopperReference, $merchantAccountCode, $currencyCode, $parentPassNo, $shopperStatement);
                            $responseAuthorized = $result->resultCode;
                            $responsePspReference = $result->pspReference;
                            $alarmpspReference = $result->pspReference;
                        } else {
                            $cancelflag = 0;
                            $responseAuthorized = 'Authorised';
                            $responsePspReference = $pspReference;
                            $alarmpspReference = $pspReference;
                        }
                        if ($responseAuthorized == 'Authorised') {
                            // capture ticket payment
                            if ($isprepaid == 0) {
                                $result2 = $this->capturePayment(trim($merchantAccountCode), $amounttocapture, $responsePspReference, $currencyCode);
                            } else {
                                $result2 = '[capture-received]';
                            }
                            if ($result2 == '[capture-received]') {
                                $flag = 1;
                                $paid_array[$visitor_group_no] = $responsePspReference;
                                $newPspReference = $responsePspReference;
                            } else {
                                $flag = 2;
                            }
                        } else if ($check_in_option == "2" || $check_in_option == "3") {
                            $flag = 2;
                            $failreturnmsg = 'error';
                        }
                    } else if ($pspReferenceNo != '' && $check_in_option == "1") {
                        $merchantAccountCode = $order_merchantAccountCode != '' ? $order_merchantAccountCode : MERCHANTCODE;
                        $this->updatePaidStatus($pspReference, '', '0', $pspReference, $is_secondary_db);
                        $result2 = $this->capturePayment($merchantAccountCode, $amounttocapture, $pspReference, $currencyCode);
                        if ($result2 == '[capture-received]') {
                            $this->updateCapturedStatus($pspReference, 5, $is_secondary_db);
                            $flag = 1;
                            $cancelflag = 0;
                        }
                        $newPspReference = $pspReference;
                    } else if ($check_in_option == "2" || $check_in_option == "3") {
                        $cancelflag = 0;
                    }
                    if ($flag == 1) {
                        if ($payment_method == 'ot') {
                            $mthod = 'others';
                        } else {
                            $mthod = $payment_method;
                        }
                        // add cc initial cost to hotel
                        if ($addtohotel == 1) {
                            $data2['hotel_id'] = $hotel_id;
                            $data2['ticketAmt'] = $init_price;
                            $data2['qty'] = 1;
                            $data2['partner_name'] = "static";
                            $data2['partner_id'] = 0;
                            $data2['ticket_title'] = "CC-initial fees";
                            $data2['total_partner_net_price'] = $init_price;
                            $data2['tax_value'] = $tax_value;
                            $data2['total_partner_gross_price'] = $initial_price;
                            $data2['used'] = "0";
                            $data2['cc_initial_payment'] = "1";
                            $data2['completed'] = "0";
                            $db->insert("failed_tickets", $data2);
                            $sns_messages = array();
                            $sns_messages[] = $this->secondarydb->db->last_query();
                            if (!empty($sns_messages)) {
                                $request_array['db1'] = $sns_messages;
                                $request_array['visitor_group_no'] = $visitor_group_no;
                                $request_string = json_encode($request_array);
                                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                $queueUrl = UPDATE_DB_QUEUE_URL;                                
                                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                                
                                if ($MessageId) {
                                    $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                                }
                            }
                        }
                        $ticket_title = 'Credit card fees for transaction ' . substr($visitor_tickets_ids, 1) . ' ' . $mthod;
                        $variable_partner_gross_price = ($totalVariableAmt + (($totalVariableAmt * $tax_value) / 100));
                        $variable_partner_gross_price = round($variable_partner_gross_price, 2);
                        // variable tax row
                        $creditor = 'Debit';
                        $transaction_type_name = 'CC-variable fees';
                        $data = array();
                        $visitor_tickets_id = $visitor_group_no.$cc_row_count++.''.$this->get_two_digit_unique_no();
                        $data['id'] = $visitor_tickets_id;
                        $data['isprepaid'] = $isprepaid;
                        $data['invoice_status'] = $invoice_status;
                        $data['transaction_id'] = $transaction_id;
                        $data['visitor_group_no'] = $visitor_group_no;
                        $data['ticket_title'] = $ticket_title;
                        $data['visit_date'] = $visit_date;
                        $data['timeZone'] = $timeZone;
                        $data['debitor'] = $debitor;
                        $data['creditor'] = $creditor;
                        $data['partner_gross_price'] = $variable_partner_gross_price;
                        $data['partner_net_price'] = $totalVariableAmt;
                        $data['tax_id'] = $card_tax_id;
                        $data['tax_value'] = $tax_value;
                        $data['row_type'] = 6;
                        $data['invoice_id'] = $invoice_id;
                        $data['all_ticket_ids'] = $visitor_tickets_ids;
                        $data['transaction_type_name'] = $transaction_type_name;
                        $data['paymentMethodType'] = '0';
                        $data['is_credit_card_fee_charged_to_guest'] = $is_credit_card_fee_charged_to_guest;
                        $data['order_total_price'] = (isset($record->order_total_price) && $record->order_total_price != '') ? $record->order_total_price : '0.00';
                        $data['order_total_net_price'] = (isset($record->order_total_net_price) && $record->order_total_net_price != '') ? $record->order_total_net_price : '0.00';
                        $data['order_total_tickets'] = (isset($record->order_total_tickets) && $record->order_total_tickets != '') ? $record->order_total_tickets : '0';
                        $data['distributor_type'] = (isset($record->distributor_type) && $record->distributor_type != '') ? $record->distributor_type : '';
                        $data['ticket_ids'] = (isset($record->ticket_ids) && $record->ticket_ids != '') ? $record->ticket_ids : '';
                        $data['voucher_updated_by'] = (isset($record->voucher_updated_by) && $record->voucher_updated_by != '') ? $record->voucher_updated_by : '';

                        $this->insertCcTransactionfees($data, $partner_data, $is_secondary_db);
                        // Fixed tax row
                        $fixed_partner_gross_price = ($totalFixedAmt + (($totalFixedAmt * $tax_value) / 100));
                        $fixed_partner_gross_price = round($fixed_partner_gross_price, 2);
                        $creditor = 'Debit';
                        $transaction_type_name = 'CC-fixed fees';

                        $fixed_data = array();
                        $visitor_tickets_id = $visitor_group_no.$cc_row_count++.''.$this->get_two_digit_unique_no();
                        $fixed_data['id'] = $visitor_tickets_id;
                        $fixed_data['isprepaid'] = $isprepaid;
                        $fixed_data['invoice_status'] = $invoice_status;
                        $fixed_data['transaction_id'] = $transaction_id;
                        $fixed_data['visitor_group_no'] = $visitor_group_no;
                        $fixed_data['ticket_title'] = $ticket_title;
                        $fixed_data['visit_date'] = $visit_date;
                        $fixed_data['timeZone'] = $timeZone;
                        $fixed_data['debitor'] = $debitor;
                        $fixed_data['creditor'] = $creditor;
                        $fixed_data['partner_gross_price'] = $fixed_partner_gross_price;
                        $fixed_data['partner_net_price'] = $totalFixedAmt;
                        $fixed_data['tax_id'] = $card_tax_id;
                        $fixed_data['tax_value'] = $tax_value;
                        $fixed_data['row_type'] = 7;
                        $fixed_data['invoice_id'] = $invoice_id;
                        $fixed_data['all_ticket_ids'] = $visitor_tickets_ids;
                        $fixed_data['transaction_type_name'] = $transaction_type_name;
                        $fixed_data['paymentMethodType'] = '0';
                        $fixed_data['is_credit_card_fee_charged_to_guest'] = $is_credit_card_fee_charged_to_guest;
                        $fixed_data['order_total_price'] = (isset($record->order_total_price) && $record->order_total_price != '') ? $record->order_total_price : '0.00';
                        $fixed_data['order_total_net_price'] = (isset($record->order_total_net_price) && $record->order_total_net_price != '') ? $record->order_total_net_price : '0.00';
                        $fixed_data['order_total_tickets'] = (isset($record->order_total_tickets) && $record->order_total_tickets != '') ? $record->order_total_tickets : '0';
                        $fixed_data['distributor_type'] = (isset($record->distributor_type) && $record->distributor_type != '') ? $record->distributor_type : '';
                        $fixed_data['ticket_ids'] = (isset($record->ticket_ids) && $record->ticket_ids != '') ? $record->ticket_ids : '';
                        $fixed_data['voucher_updated_by'] = (isset($record->voucher_updated_by) && $record->voucher_updated_by != '') ? $record->voucher_updated_by : '';
                        $this->insertCcTransactionfees($fixed_data, $partner_data, $is_secondary_db);
                        $variableAndFixedAmt = $totalVariableAmt + $totalFixedAmt;
                        $gross_price = $variable_partner_gross_price + $fixed_partner_gross_price;

                        //insert initial payment row
                        if ($updates_initial != '' && $invoice_id == '') {
                            // update as initial paid
                            $sns_messages = array();
                            $sns_messages[] = $updates_initial;
                            if (!empty($sns_messages)) {
                                $request_array['db1'] = $sns_messages;
                                $request_array['db2'] = $sns_messages;
                                $request_array['visitor_group_no'] = $visitor_group_no;
                                $request_string = json_encode($request_array);
                                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                $queueUrl = UPDATE_DB_QUEUE_URL;
                                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                if ($MessageId) {
                                    $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                                }
                            }
                            $gross_price = $gross_price + $data1['partner_gross_price'];
                            $variableAndFixedAmt = $variableAndFixedAmt + $data1['partner_net_price'];
                            // insert initial cost row
                            $this->insertCcTransactionfees($data1, $partnersdata, $is_secondary_db);
                        }

                        $debitor = 'FSPCC';
                        $creditor = 'Credit';
                        $transaction_type_name = 'CC-costs';

                        $total_data = array();
                        $visitor_tickets_id = $visitor_group_no.$cc_row_count++.''.$this->get_two_digit_unique_no();
                        $total_data['id'] = $visitor_tickets_id;
                        $total_data['isprepaid'] = $isprepaid;
                        $total_data['invoice_status'] = $invoice_status;
                        $total_data['transaction_id'] = $transaction_id;
                        $total_data['visitor_group_no'] = '';
                        $total_data['ticket_title'] = $ticket_title;
                        $total_data['visit_date'] = $visit_date;
                        $total_data['timeZone'] = $timeZone;
                        $total_data['debitor'] = $debitor;
                        $total_data['creditor'] = $creditor;
                        $total_data['partner_gross_price'] = $gross_price;
                        $total_data['partner_net_price'] = $variableAndFixedAmt;
                        $total_data['tax_id'] = $card_tax_id;
                        $total_data['tax_value'] = $tax_value;
                        $total_data['row_type'] = 8;
                        $total_data['invoice_id'] = "";
                        $total_data['all_ticket_ids'] = $visitor_tickets_ids;
                        $total_data['transaction_type_name'] = $transaction_type_name;
                        $total_data['paymentMethodType'] = '0';
                        $total_data['is_credit_card_fee_charged_to_guest'] = $is_credit_card_fee_charged_to_guest;
                        $total_data['order_total_price'] = (isset($record->order_total_price) && $record->order_total_price != '') ? $record->order_total_price : '0.00';
                        $total_data['order_total_net_price'] = (isset($record->order_total_net_price) && $record->order_total_net_price != '') ? $record->order_total_net_price : '0.00';
                        $total_data['order_total_tickets'] = (isset($record->order_total_tickets) && $record->order_total_tickets != '') ? $record->order_total_tickets : '0';
                        $total_data['distributor_type'] = (isset($record->distributor_type) && $record->distributor_type != '') ? $record->distributor_type : '';
                        $total_data['ticket_ids'] = (isset($record->ticket_ids) && $record->ticket_ids != '') ? $record->ticket_ids : '';
                        $total_data['voucher_updated_by'] = (isset($record->voucher_updated_by) && $record->voucher_updated_by != '') ? $record->voucher_updated_by : '';

                        $this->insertCcTransactionfees($total_data, array(), $is_secondary_db);                        
                        // reset all amount in hto table
                        $visitor_group_no_array[] = $visitor_group_no;
                    } else if ($is_block == "0") {
                        if ($check_in_option == '2' || $check_in_option == '3') {
                            $block_pass = '1';
                            // get the tickets amount from distributor in case of fail
                            $err = $this->addTicketAmountToHotel($visitor_group_no, $hotel_id, 0, 0, $is_secondary_db);
                        } else {
                            $block_pass = '0';
                        }
                        $this->addticketPaymentFailCount($pspReference, $is_secondary_db);
                        /* COMMENTED 
                        $updates = 'update visitor_tickets set invoice_status="7", adyen_status="7", distributor_status="7" where original_pspReference = "' . $newPspReference . '" and paid="0" and is_block!="2"';
                        $sns_messages[] = $updates;
                        */
                        $sns_messages = array();
                        if (!empty($sns_messages)) {
                            $request_array['db1'] = $sns_messages;
                            $request_array['db2'] = $sns_messages;
                            $request_array['visitor_group_no'] = $visitor_group_no;
                            $request_string = json_encode($request_array);
                            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $queueUrl = UPDATE_DB_QUEUE_URL;                            
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                            
                            $err = '';
                            if ($MessageId) {
                                $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }
                        }

                        /* COMMENTED $refusedv = 'update hotel_ticket_overview set refused = refused+1, is_block ="' . $block_pass . '" where visitor_group_no = "' . $visitor_group_no . '"'; */

                        $sns_messages = array();
                        //$sns_messages[] = $refusedv;
                        if (!empty($sns_messages)) {
                            $request_array['db1'] = $sns_messages;
                            $request_array['db2'] = $sns_messages;
                            $request_array['visitor_group_no'] = $visitor_group_no;
                            $request_string = json_encode($request_array);
                            $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $queueUrl = UPDATE_DB_QUEUE_URL;                            
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                            
                            $err = '';
                            if ($MessageId) {
                                $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }
                        }                        
                    }
                }
            }
            if (!empty($visitor_group_no_array)) {
                $visitor_group_nos = implode(",", $visitor_group_no_array);
                /* COMMENTED 
                $query = 'update hotel_ticket_overview set ';
                $query .=' ticketPaymentFailCount = 0, ';
                $query .=' total_variable_amount = 0, ';
                $query .=' total_fixed_amount = 0, ';
                $query .=' isprepaid = "0", ';
                $query .=' total_amount_with_tax = 0, ';
                $query .=' all_ticket_ids = "", ';
                $query .=' latest_ticket_time = "0", ';
                $query .=' total_amount_without_tax = 0';
                $query .=' where visitor_group_no in (' . $visitor_group_nos . ')';
                $sns_messages = array();
                $sns_messages[] = $query;
                */
                $sns_messages = array();

                /* COMMENTED $updates = 'update visitor_tickets set captured = "1", paid = "1" where vt_group_no in (' . $visitor_group_nos . ')';
                $sns_messages[] = $updates;
                */
                if (!empty($sns_messages)) {
                    $request_array['db1'] = $sns_messages;
                    $request_array['db2'] = $sns_messages;
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    $queueUrl = UPDATE_DB_QUEUE_URL;                    
                    $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                    
                    $err = '';
                    if ($MessageId) {
                        $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                    }
                }
            }
            $sns_messages = array();
            foreach ($paid_array as $vgn => $pspref) {
                /* COMMENTED 
                $updates = 'update visitor_tickets set pspReference = "' . $pspref . '", adyen_status="9", captured = "1", paid = "1" where vt_group_no = "' . $vgn . '"';
                $sns_messages[] = $updates;
                */
            }

            if (!empty($sns_messages)) {
                $request_array['db1'] = $sns_messages;
                $request_array['db2'] = $sns_messages;
                $request_array['visitor_group_no'] = $visitor_group_no;
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;                
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                
                $err = '';
                if ($MessageId) {
                    $err = $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                }
            }
        }

        if ($pspReferenceNo != '' && $checks == "0") {
            // get all pending amount
            $pendingAmt = $this->getUnpaidTicketAmount('', $pspReferenceNo, $is_secondary_db);

            if ($pendingAmt > 0) {
                $this->captureThePendingPaymentsv1($invoice_id, $pspReferenceNo, 1, '', '', "1", $is_secondary_db);
            }
            if ($cancelflag == 1 && $check_in_option != "2" && $check_in_option != "3") {
                $merchantAccountCode = $this->common_model->getSingleFieldValueFromTable('merchantAccountCode', 'hotel_ticket_overview', array('pspReference' => $pspReferenceNo), $is_secondary_db);
                $this->cancelPayment($merchantAccountCode, $pspReferenceNo, 'checkoutHotel');
            }
        }
        $datacron['status'] = "0";
        $where['cron_id'] = "1";
        $this->db->update("crons_status", $datacron, $where);
        echo "<br>Ended at " . date('H:i:s') . '<br>';
        echo "<pre>";
        print_r($records);
        echo "</pre>";
        return $failreturnmsg;
    }
    /* #endregion used to capture the pending payments by cron and also when user check outs.*/

    /* #region cancelPayment  */
    /**
     * cancelPayment
     *
     * @param  mixed $merchantAccount
     * @param  mixed $pspReference
     * @param  mixed $from
     * @return void
     */
    function cancelPayment($merchantAccount = '', $pspReference = '', $from = 'None') {
        $oREC = new CI_AdyenRecurringPayment(false);
        $oREC->startSOAP("Payment");
        $oREC->cancel($merchantAccount, $pspReference);
        return $oREC->response->cancelResult->response;
    }
    /* #endregion cancelPayment*/

    /* #region To get The hotel or ticket level commission values  */
    /**
     * get_ticket_hotel_level_commission
     *
     * @param  mixed $hotel_id
     * @param  mixed $ticketId
     * @param  mixed $ticketpriceschedule_id
     * @param  mixed $is_secondary_db
     * @param  mixed $channel_id
     * @param  mixed $sub_catalog_id
     * @return void
     * @author Pankaj Kumar <pankajk.dev@outlook.com> 
     */
    function get_ticket_hotel_level_commission($hotel_id, $ticketId, $ticketpriceschedule_id, $channel_id = '', $sub_catalog_id = 0, $distributor_reseller_id = 0, $ticket_reseller_subcatalog_id = array(), $is_addon_ticket = 0, $ticket_reseller_id = 0) {
        $db = $this->primarydb->db;
        /* check if reseller id of supplier and distributor reseller id matches */
        $this->CreateLog('price_level.php', 'step0', array("ticket_reseller_id" => $ticket_reseller_id));
        $resellerMatched = false;
        if( 
            isset( $ticket_reseller_subcatalog_id[$ticketId] ) && 
            !empty( $ticket_reseller_subcatalog_id[$ticketId] ) && 
            $distributor_reseller_id == current( array_keys( $ticket_reseller_subcatalog_id[$ticketId] ) )
        ) {
            $resellerMatched = true;
        }
        $clc_adjust_price = false;
        $ticket_level_commission_flag = 0;
        $ticket_level_own_commission_flag = 0;
        $sub_catalog_level_commission_own_flag = 0;
        /* #Comment: Level 1: TLC  : ticket_level_commission */
        $db->select("*");
        $db->from("ticket_level_commission");
        $db->where("hotel_id", $hotel_id);
        $db->where("ticket_id", $ticketId);
        if (!empty($is_addon_ticket)) {
            $db->where("resale_currency_level", 1); 
        }
        $db->where('deleted', '0');
        if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
            $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
        }
        $db->order_by('resale_currency_level', 'DESC');
        $query = $db->get();
        $this->CreateLog('price_level.php', 'step1', array("tlc_level" => $db->last_query()));
        if ($query->num_rows() > 0) {
            $results = $query->result();
            $result = $results[0];
            $this->CreateLog('price_level.php', 'step1_data', array("tlc_result" => json_encode($result)));
            $result->pricing_level = 1; // To identify price level
            $result->commission_level = 1;
            if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                //BOC for tlc adjust checking & creating overwrite variable list
                if ($result->is_adjust_pricing != 1) {
                    $tlc_commission_array = array();
                    $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                    $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                    $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                    $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                    $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                    $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                    $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                    $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                    $tlc_commission_array['commission_level'] = $result->commission_level;
                    $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                    $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                    $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                    $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                    $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                    $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                    $clc_adjust_price = true;
                    //set  flag to run clc level adjust pricings
                    $ticket_level_commission_flag = 1;
                } else {
                    $data['result'] = $result;
                    if ($result->resale_currency_level != 2) {
                        unset($results[0]);
                    }
                    $data['supplier_merchant_data'] = $results;
                    return $data;
                }
                //EOC for tlc adjust checking & creating overwrite variable list
            } else {
                $ticket_level_commission_flag = 1;
            }
        } else {
            $ticket_level_commission_flag = 1;
        }
        /* #region to check Tlc product level   */
        if ($ticket_level_commission_flag == 1) {
            /* #Comment: Level 2: TLC Product Level : own_account_commissions */
            $db->select("*");
            $db->from("own_account_commissions");
            $db->where("partner_id", $hotel_id);
            $db->where("ticket_id", $ticketId);
            $db->where('deleted', '0');
            $query = $db->get();
            $this->CreateLog('price_level.php', 'step2', array("tlc_product_level" => $db->last_query()));
            if ($query->num_rows() > 0) {
                $result = $query->row();
                $this->CreateLog('price_level.php', 'step2_data', array("tlc_product_level" => json_encode($result)));
                $result->commission_level = 2;
                $tlc_commission_array = array();
                $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                $tlc_commission_array['commission_level'] = $result->commission_level;
                $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                $clc_adjust_price = true;
                //set  flag to run clc level adjust pricings
                $ticket_level_commission_flag = 1;
                $ticket_level_own_commission_flag = 1;
            }
        }
        /* #endregion to check Tlc product level */
        if ($ticket_level_commission_flag == 1) {
            /* #Comment: Level 3: CLC Sub Catalog: channel_level_commission */
            // BOC channel commission
            /* #region to check subcatalog product_type level from clc  */
            if (!empty($sub_catalog_id) && isset($sub_catalog_id) && $sub_catalog_id > 0) {
                $catalog_array = array($sub_catalog_id);
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where_in("channel_id", array(0));
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $db->where_in("catalog_id", $catalog_array);
                $db->where("channel_level",1);
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by("catalog_id", 'DESC');
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step3', array("clc_product_type_level" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step3_data', array("clc_product_type_level" => json_encode($result)));
                    $result->pricing_level = 3; // To identify price level
                    $result->commission_level = 3;
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2 && $result->is_adjust_pricing == 0) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
                $channel_level_commission_flag = 1;
            } else {
                $channel_level_commission_flag = 1;
            }
            /* #endregion to check subcatalog product_type level from clc  */

            /* #region to check subcatalog product level from own_accounts_commissions  */
            if ($channel_level_commission_flag == 1 && !empty($sub_catalog_id) && $sub_catalog_id > 0 && $ticket_level_own_commission_flag == 0) {
                /* #Comment: Level 4: CLC Sub Catalog Product Level: own_account_commissions */
                // check prices from subcatalog product level i.e. own_accounts_commissions
                $db->select("*");
                $db->from("own_account_commissions");
                $db->where("catalog_id", $sub_catalog_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step4', array("clc_product_level" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $result = $query->row();
                    $this->CreateLog('price_level.php', 'step4_data', array("clc_product_level" => json_encode($result)));
                    $result->commission_level = 10;
                    $tlc_commission_array = array();
                    $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                    $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                    $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                    $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                    $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                    $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                    $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                    $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                    $tlc_commission_array['commission_level'] = $result->commission_level;
                    $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                    $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                    $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                    $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                    $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                    $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                    $clc_adjust_price = true;
                    //set  flag to run clc level adjust pricings
                    $ticket_level_commission_flag = 1;
                    $sub_catalog_level_commission_own_flag = 1;
                }
            }
            /* #endregion to check subcatalog product level from own_accounts_commissions  */

            /* #region to check pricelist/Clc product_type level from clc  */
            if (!empty($channel_id) && isset($channel_id)) {
                $catalog_array = array(0);
                /* #Comment: Level 5: CLC Main Catalog Level: channel_level_commission */
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where_in("channel_id", array($channel_id));
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                if(!empty($catalog_array)) {
                    $db->where_in("catalog_id", $catalog_array);
                }
                $db->where("channel_level",1);
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by("catalog_id", 'DESC');
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step5', array("clc_product_level1" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step5_data', array("clc_product_level1" => json_encode($result)));
                    $result->pricing_level = 4; // To identify price level
                    $result->commission_level = 4;
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2 && $result->is_adjust_pricing == 0) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
                $channel_level_commission_flag = 1;
            } else {
                $channel_level_commission_flag = 1;
            }
            /* #endregion to check pricelist/Clc product_type level from clc  */

            /* #region to check pricelist/Clc product level   */
            if ($channel_level_commission_flag == 1 && $ticket_level_own_commission_flag == 0 && $sub_catalog_level_commission_own_flag == 0) {
                /* #Comment: Level 6: CLC Product Level: own_account_commissions */
                // check prices from Tlc product level i.e. own_accounts_commissions
                $db->select("*");
                $db->from("own_account_commissions");
                $db->where("channel_id", $channel_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step6', array("query" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $result = $query->row();
                    $this->CreateLog('price_level.php', 'step6_data', array("data" => json_encode($result)));
                    $result->commission_level = 5;
                    $tlc_commission_array = array();
                    $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                    $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                    $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                    $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                    $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                    $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                    $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                    $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                    $tlc_commission_array['commission_level'] = $result->commission_level;
                    $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                    $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                    $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                    $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                    $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                    $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                    $clc_adjust_price = true;
                    //set  flag to run clc level adjust pricings
                    $ticket_level_commission_flag = 1;
                }
            }
            /* #endregion to check pricelist/Clc product level   */

            /* #Comment: Level 7:  CLC Default reseller SubCatalog Level: related_partners_subcatalog . */
            /* #region to check Reseller sub/main catalog level */
            if( $ticket_level_commission_flag == 1 && $resellerMatched === false && !empty($ticket_reseller_subcatalog_id[$ticketId])) {
                $reseller_id = current(array_keys($ticket_reseller_subcatalog_id[$ticketId]));
                $subCatalogId = $ticket_reseller_subcatalog_id[$ticketId][$reseller_id];
                $catalog_array = array($subCatalogId);
                
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where("reseller_id", '0');
                $db->where("ticket_id", $ticketId);
                if (!empty($catalog_array)) {
                    $db->where_in("catalog_id", $catalog_array); 
                }
                $db->where('deleted', '0');
                $db->order_by("catalog_id", 'DESC');
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step7', array("reseller_deafaultlevel" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step7_data', array("reseller_deafaultlevel" => json_encode($result)));
                    if (!empty($subCatalogId)) {
                        $result->pricing_level = 6; // To identify price level
                        $result->commission_level = 6;
                    } else {
                        $result->pricing_level = 7; // To identify price level
                        $result->commission_level = 7;
                    }
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2 && $result->is_adjust_pricing == 0) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
            }
            /* #endregion to check Reseller sub/main catalog level */

            /* #Comment: Level 8: CLC Default reseller Catalog Level: channel_level_commission .*/
            /* #region to check Reseller sub/main catalog level */
            if( $ticket_level_commission_flag == 1 && $resellerMatched === false && !empty($ticket_reseller_id)) {

                $db->select("*");
                $db->from("channel_level_commission");
                $db->where("reseller_id", $ticket_reseller_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $db->order_by("catalog_id", 'DESC');
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step8', array("reseller_deafaultlevel" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step8_data', array("reseller_deafaultlevel" => json_encode($result)));

                    $result->pricing_level = 8; // To identify price level
                    $result->commission_level = 8;
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2 && $result->is_adjust_pricing == 0) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
            }
            /* #endregion to check Reseller sub/main catalog level */
            
            /* #endregion to check Tlc product level */
            // EOC channel commission
            // BOC hotel commission
            if ($channel_level_commission_flag == 1) {
                $db->select("prepaid_commission_percentage as hotel_prepaid_commission_percentage, postpaid_commission_percentage as hotel_postpaid_commission_percentage, commission_tax_id as hotel_commission_tax_id, commission_tax_value as hotel_commission_tax_value, hgs_prepaid_commission_percentage, hgs_postpaid_commission_percentage, hgs_commission_tax_id, hgs_commission_tax_value");
                $db->from("qr_codes");
                $db->where("cod_id", $hotel_id);
                $result = $db->get()->row();
                $this->CreateLog('price_level.php', 'step8', array("qr_codes" => $db->last_query()));
                $this->CreateLog('price_level.php', 'step8_data', array("qr_codes" => json_encode($result)));
                if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                    $result->pricing_level = 9; // To identify price level
                    $result->commission_level = 8;
                    if (!empty($tlc_commission_array)) {
                        $result = (array) $result;  //conversion of object to array for tlc commission changes                        
                        $result = array_replace($result, $tlc_commission_array);
                        $result = (object) $result; //conversion of array to object again
                    }
                    $data['result'] = $result;
                    $data['supplier_merchant_data'] = $result;
                    return $data;
                } else {
                    if (!empty($tlc_commission_array)) {
                        $tlc_commission_array['pricing_level'] = 9; // To identify price level
                        $result = (object) $tlc_commission_array; //conversion of array to object again
                        $data['result'] = $result;
                        $data['supplier_merchant_data'] = $result;
                        return $data;
                    } else {
                        return false;
                    }
                }
            }
            // EOC hotel commission
        } //EOC ticket level flag
    }
    /* #endregion To get The hotel or ticket level commission values*/
   
    /* #region To get The hotel or ticket level commission values for single ticket type or multiple types  */
    /**
     * get_ticket_hotel_level_commission_bundle
     *
     * @param  mixed $hotel_id
     * @param  mixed $ticketId
     * @param  mixed $ticketpriceschedule_id
     * @param  mixed $is_secondary_db
     * @param  mixed $channel_id
     * @param  mixed $sub_catalog_id
     * @return void
     * @author Gourav Sadana <gourav.aipl@gmail.com> 
     */
    function get_ticket_hotel_level_commission_bundle($hotel_id, $ticketId, $ticketpriceschedule_id, $channel_id = '', $sub_catalog_id = 0, $distributor_reseller_id = 0, $ticket_reseller_subcatalog_id = array(), $is_addon_ticket = 0, $ticket_reseller_id = 0) {
        $db = $this->primarydb->db;
        /* check if reseller id of supplier and distributor reseller id matches */
        
        $resellerMatched = false;
        if( 
            isset( $ticket_reseller_subcatalog_id[$ticketId] ) && 
            !empty( $ticket_reseller_subcatalog_id[$ticketId] ) && 
            $distributor_reseller_id == current( array_keys( $ticket_reseller_subcatalog_id[$ticketId] ) )
        ) {
            $resellerMatched = true;
        }
        $clc_adjust_price = false;
        $ticket_level_commission_flag = 0;
        $ticket_level_commission_own_flag = 0;
        $db->select("*");
        $db->from("ticket_level_commission");
        $db->where("hotel_id", $hotel_id);
        $db->where("ticket_id", $ticketId);
        if (!empty($is_addon_ticket)) {
            $db->where("resale_currency_level", 1); 
        }
        $db->where('deleted', '0');
        if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0 && is_array($ticketpriceschedule_id)) {
            $db->where_in("ticketpriceschedule_id", $ticketpriceschedule_id);
            $tps_query1 = 'tlc.ticketpriceschedule_id in (' . implode(',', $ticketpriceschedule_id) . ')';
        } else {
            $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
            $tps_query1 = 'tlc.ticketpriceschedule_id = '.$ticketpriceschedule_id.'';
        }
        /* ADD subquery for highest currency level */
        $sub_tlc_query1 = 'Select max(tlc.resale_currency_level) from  ticket_level_commission tlc Where tlc.hotel_id = "'.$hotel_id. '" and tlc.ticket_id = '.$ticketId.' and '.$tps_query1.' and tlc.deleted != "1"';
        $db->where('resale_currency_level = ('.$sub_tlc_query1.') ');
        $db->order_by('resale_currency_level', 'DESC');
        $query = $db->get();
        $this->CreateLog('price_level.php', 'step1', array("tlc_level" => $db->last_query()));
        if ($query->num_rows() > 0) {
            $results = $query->result();
            foreach ( $results as $result) {
                $this->CreateLog('price_level.php', 'step1_data', array("tlc_result" => json_encode($result)));
                $result->pricing_level = 1; // To identify price level
                $result->commission_level = 1;
                if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                    //BOC for tlc adjust checking & creating overwrite variable list
                    if ($result->is_adjust_pricing != 1) {
                        $tlc_commission_array = array();
                        $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                        $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                        $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                        $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                        $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                        $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                        $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                        $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                        $tlc_commission_array['commission_level'] = $result->commission_level;
                        $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                        $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                        $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                        $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                        $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                        $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                        $clc_adjust_price = true;
                        //set  flag to run clc level adjust pricings
                        $ticket_level_commission_flag = 1;
                    } else {
                        $data['result'] = $results;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC for tlc adjust checking & creating overwrite variable list
                } else {
                    $ticket_level_commission_flag = 1;
                }
            }    
        } else {
            $ticket_level_commission_flag = 1;
        }
        /* #region to check Tlc product level   */
        if ($ticket_level_commission_flag == 1) {
            // check prices from Tlc product level i.e. own_accounts_commissions
            $db->select("*");
            $db->from("own_account_commissions");
            $db->where("partner_id", $hotel_id);
            $db->where("ticket_id", $ticketId);
            $db->where('deleted', '0');
            $query = $db->get();
            $this->CreateLog('price_level.php', 'step2', array("tlc_product_level" => $db->last_query()));
            if ($query->num_rows() > 0) {
                $result = $query->row();
                $this->CreateLog('price_level.php', 'step2_data', array("tlc_product_level" => json_encode($result)));
                $result->commission_level = 2;
                $tlc_commission_array = array();
                $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                $tlc_commission_array['commission_level'] = $result->commission_level;
                $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                $clc_adjust_price = true;
                //set  flag to run clc level adjust pricings
                $ticket_level_commission_flag = 1;
                $ticket_level_commission_own_flag = 1;
            }
        }
        /* #endregion to check Tlc product level */
        if ($ticket_level_commission_flag == 1) {

            // BOC channel commission
            /* #region to check subcatalog product_type level from clc  */
            if (!empty($sub_catalog_id) && isset($sub_catalog_id) && $sub_catalog_id > 0) {
                $catalog_array3 = '';
                $catalog_array = array($sub_catalog_id);
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where_in("channel_id", array(0));
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $db->where_in("catalog_id", $catalog_array);
                $db->where("channel_level",1);
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0  && is_array($ticketpriceschedule_id)) {
                    $db->where_in("ticketpriceschedule_id", $ticketpriceschedule_id);
                    $tps_query3 = 'clc.ticketpriceschedule_id in (' . implode(',', $ticketpriceschedule_id) . ')';
                    $catalog_array3 = ' and clc.catalog_id in (' . implode(',', $catalog_array) . ')';
                } else {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                    $tps_query3 = 'clc.ticketpriceschedule_id = '.$ticketpriceschedule_id.'';
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by("catalog_id", 'DESC');
                /* ADD subquery for highest currency level */
                if (!empty($catalog_array3)) {
                    $sub_clc_query3 = 'Select max(clc.resale_currency_level) from  channel_level_commission as clc Where clc.channel_id = "0" and clc.ticket_id = '.$ticketId.$catalog_array3.' and '.$tps_query3.' and clc.channel_level = "1" and clc.deleted != "1"';
                } else {
                    $sub_clc_query3 = 'Select max(clc.resale_currency_level) from  channel_level_commission as clc Where clc.channel_id = "0" and clc.ticket_id = '.$ticketId.' and '.$tps_query3.' and clc.channel_level = "1" and clc.deleted != "1"';
                }
                $db->where('resale_currency_level = ('.$sub_clc_query3.') ');
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step3', array("clc_product_type_level" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step3_data', array("clc_product_type_level" => json_encode($result)));
                    $result->pricing_level = 3; // To identify price level
                    $result->commission_level = 3;
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        if( !empty ($tlc_commission_array) ) {
                            $results_commission_array   =   array();
                            foreach ( $results as $value ) {
                                $value  =   (array) $value;
                                $results_commission = array_replace($value, $tlc_commission_array);
                                $results_commission_array[]   =   (object) $results_commission;
                            }
                        }
                        if ( !empty($results_commission_array) ) {
                            $results    =   array();
                            $results    =   $results_commission_array;
                        }
                        $data['result'] = $results;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $results;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
                $channel_level_commission_flag = 1;
            } else {
                $channel_level_commission_flag = 1;
            }
            /* #endregion to check subcatalog product_type level from clc  */

            /* #region to check subcatalog product level from own_accounts_commissions  */
            if ($channel_level_commission_flag == 1 && !empty($sub_catalog_id) && $sub_catalog_id > 0 && $ticket_level_commission_own_flag != '1') {
                // check prices from subcatalog product level i.e. own_accounts_commissions
                $db->select("*");
                $db->from("own_account_commissions");
                $db->where("catalog_id", $sub_catalog_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step4', array("clc_product_level" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $result = $query->row();
                    $this->CreateLog('price_level.php', 'step4_data', array("clc_product_level" => json_encode($result)));
                    $result->commission_level = 10;
                    $tlc_commission_array = array();
                    $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                    $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                    $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                    $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                    $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                    $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                    $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                    $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                    $tlc_commission_array['commission_level'] = $result->commission_level;
                    $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                    $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                    $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                    $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                    $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                    $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                    $clc_adjust_price = true;
                    //set  flag to run clc level adjust pricings
                    $ticket_level_commission_flag = 1;
                }
            }
            /* #endregion to check subcatalog product level from own_accounts_commissions  */

            /* #region to check pricelist/Clc product_type level from clc  */
            if (!empty($channel_id) && isset($channel_id)) {
                $catalog_array = array(0);
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where_in("channel_id", array($channel_id));
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                if(!empty($catalog_array)) {
                    $db->where_in("catalog_id", $catalog_array);
                }
                $db->where("channel_level",1);
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0  && is_array($ticketpriceschedule_id)) {
                    $db->where_in("ticketpriceschedule_id", $ticketpriceschedule_id);
                    $tps_query5 = 'clc.ticketpriceschedule_id in (' . implode(',', $ticketpriceschedule_id) . ')';
                    $catalog_array5 = 'clc.catalog_id in (' . implode(',', $catalog_array) . ')';
                } else {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                    $tps_query5 = 'clc.ticketpriceschedule_id = '.$ticketpriceschedule_id.'';
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                /* ADD subquery for highest currency level */
                if (!empty($catalog_array5)) {
                    $sub_clc_query5 = 'Select max(clc.resale_currency_level) from  channel_level_commission clc Where clc.channel_id = '.$channel_id.' and clc.ticket_id = '.$ticketId.' and '.$catalog_array5.' and '.$tps_query5.' and clc.channel_level = "1" and clc.deleted != "1"';
                } else if (!empty($tps_query3)) {
                    $sub_clc_query5 = 'Select max(clc.resale_currency_level) from  channel_level_commission as clc Where clc.channel_id = "0" and clc.ticket_id = '.$ticketId.' and '.$tps_query3.' and clc.channel_level = "1" and clc.deleted != "1"';
                } else {
                    $sub_clc_query5 = 'Select max(clc.resale_currency_level) from  channel_level_commission as clc Where clc.channel_id = "0" and clc.ticket_id = '.$ticketId.' and clc.channel_level = "1" and clc.deleted != "1"';
                }
                $db->where('resale_currency_level = ('.$sub_clc_query5.') ');
                $db->order_by("catalog_id", 'DESC');
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step5', array("clc_product_level1" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $results = $query->result();
                    foreach( $results as $result){
                        $this->CreateLog('price_level.php', 'step5_data', array("clc_product_level1" => json_encode($result)));
                        $result->pricing_level = 4; // To identify price level
                        $result->commission_level = 4;
                        if ($result->is_adjust_pricing != 1) {
                            $result->pricing_level = 9;
                        }
                        //BOC to pass (overwrite) the TLC level commission
                        if ($clc_adjust_price) {
                            if (!empty($tlc_commission_array)) {

                                $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                                $result = array_replace($result, $tlc_commission_array);
                                $result = (object) $result; //conversion of array to object again
                            }
                            if( !empty ($tlc_commission_array) ) {
                                $results_commission_array   =   array();
                                foreach ( $results as $value ) {
                                    $value  =   (array) $value;
                                    $results_commission = array_replace($value, $tlc_commission_array);
                                    $results_commission_array[]   =   (object) $results_commission;
                                }
                            }
                            if ( !empty($results_commission_array) ) {
                                $results    =   array();
                                $results    =   $results_commission_array;
                            }
                            $data['result'] = $results;
                            $data['supplier_merchant_data'] = $results;
                            return $data;
                        }
                        //EOC to pass (overwrite) the TLC level commission

                        if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                            $data['result'] = $results;
                            $data['supplier_merchant_data'] = $results;
                            return $data;
                        }
                    }    
                }
                $channel_level_commission_flag = 1;
            } else {
                $channel_level_commission_flag = 1;
            }
            /* #endregion to check subcatalog product_type level from clc  */

            /* #region to check pricelist/Clc product level   */
            if ($channel_level_commission_flag == 1) {
                // check prices from Tlc product level i.e. own_accounts_commissions
                $db->select("*");
                $db->from("own_account_commissions");
                $db->where("channel_id", $channel_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step6', array("query" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    $result = $query->row();
                    $this->CreateLog('price_level.php', 'step6_data', array("data" => json_encode($result)));
                    $result->commission_level = 5;
                    $tlc_commission_array = array();
                    $tlc_commission_array['hotel_prepaid_commission_percentage'] = $result->hotel_prepaid_commission_percentage;
                    $tlc_commission_array['hotel_postpaid_commission_percentage'] = $result->hotel_postpaid_commission_percentage;
                    $tlc_commission_array['hotel_commission_tax_id'] = $result->hotel_commission_tax_id;
                    $tlc_commission_array['hotel_commission_tax_value'] = $result->hotel_commission_tax_value;
                    $tlc_commission_array['hgs_prepaid_commission_percentage'] = $result->hgs_prepaid_commission_percentage;
                    $tlc_commission_array['hgs_postpaid_commission_percentage'] = $result->hgs_postpaid_commission_percentage;
                    $tlc_commission_array['hgs_commission_tax_id'] = $result->hgs_commission_tax_id;
                    $tlc_commission_array['hgs_commission_tax_value'] = $result->hgs_commission_tax_value;
                    $tlc_commission_array['commission_level'] = $result->commission_level;
                    $tlc_commission_array['is_hotel_prepaid_commission_percentage'] = $result->is_hotel_prepaid_commission_percentage;
                    $tlc_commission_array['commission_on_sale_price'] = $result->commission_on_sale_price;
                    $tlc_commission_array['hotel_commission_gross_price'] = $result->hotel_commission_gross_price;
                    $tlc_commission_array['hotel_commission_net_price'] = $result->hotel_commission_net_price;
                    $tlc_commission_array['hgs_commission_gross_price'] = $result->hgs_commission_gross_price;
                    $tlc_commission_array['hgs_commission_net_price'] = $result->hgs_commission_net_price;
                    $clc_adjust_price = true;
                    //set  flag to run clc level adjust pricings
                    $ticket_level_commission_flag = 1;
                }
            }
            /* #endregion to check pricelist/Clc product level   */

           /* #Comment: Level 7:  CLC Default reseller SubCatalog Level: related_partners_subcatalog . */
            /* #region to check Reseller sub/main catalog level */
            if( $ticket_level_commission_flag == 1 && $resellerMatched === false && !empty($ticket_reseller_subcatalog_id[$ticketId])) {
                $reseller_id = current(array_keys($ticket_reseller_subcatalog_id[$ticketId]));
                $subCatalogId = $ticket_reseller_subcatalog_id[$ticketId][$reseller_id];
                $catalog_array = array($subCatalogId);
                
                $db->select("*");
                $db->from("channel_level_commission");
                $db->where("reseller_id", '0');
                $db->where("ticket_id", $ticketId);
                if (!empty($catalog_array)) {
                    $db->where_in("catalog_id", $catalog_array); 
                }
                $db->where('deleted', '0');
                $db->order_by("catalog_id", 'DESC');
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0 && !is_array($ticketpriceschedule_id)) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                } else if ($ticketpriceschedule_id != '' && is_array($ticketpriceschedule_id) && count($ticketpriceschedule_id) > 0) {
                    $db->where("ticketpriceschedule_id", implode(',', $ticketpriceschedule_id));
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step7', array("reseller_deafaultlevel" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step7_data', array("reseller_deafaultlevel" => json_encode($result)));
                    if (!empty($subCatalogId)) {
                        $result->pricing_level = 6; // To identify price level
                        $result->commission_level = 6;
                    } else {
                        $result->pricing_level = 7; // To identify price level
                        $result->commission_level = 7;
                    }
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
            }
            /* #endregion to check Reseller sub/main catalog level */

            /* #Comment: Level 8: CLC Default reseller Catalog Level: channel_level_commission .*/
            /* #region to check Reseller sub/main catalog level */
            if( $ticket_level_commission_flag == 1 && $resellerMatched === false && !empty($ticket_reseller_id)) {

                $db->select("*");
                $db->from("channel_level_commission");
                $db->where("reseller_id", $ticket_reseller_id);
                $db->where("ticket_id", $ticketId);
                $db->where('deleted', '0');
                $db->order_by("catalog_id", 'DESC');
                if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0 && !is_array($ticketpriceschedule_id)) {
                    $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                } else if ($ticketpriceschedule_id != '' && is_array($ticketpriceschedule_id) && count($ticketpriceschedule_id) > 0) {
                    $db->where("ticketpriceschedule_id", implode(',', $ticketpriceschedule_id));
                }
                if (!empty($is_addon_ticket)) {
                    $db->where("resale_currency_level", 1); 
                }
                $db->order_by('resale_currency_level', 'DESC');
                $query = $db->get();
                $this->CreateLog('price_level.php', 'step8', array("reseller_deafaultlevel" => $db->last_query()));
                if ($query->num_rows() > 0) {
                    
                    $results = $query->result();
                    $result = $results[0];
                    $this->CreateLog('price_level.php', 'step8_data', array("reseller_deafaultlevel" => json_encode($result)));

                    $result->pricing_level = 8; // To identify price level
                    $result->commission_level = 8;
                    if ($result->is_adjust_pricing != 1) {
                        $result->pricing_level = 9;
                    }
                    //BOC to pass (overwrite) the TLC level commission
                    if ($clc_adjust_price) {
                        if (!empty($tlc_commission_array)) {

                            $result = (array) $result;  //conversion of object to array for tlc commission changes                            
                            $result = array_replace($result, $tlc_commission_array);
                            $result = (object) $result; //conversion of array to object again
                        }
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                    //EOC to pass (overwrite) the TLC level commission

                    if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                        $data['result'] = $result;
                        if ($result->resale_currency_level != 2) {
                            unset($results[0]);
                        }
                        $data['supplier_merchant_data'] = $results;
                        return $data;
                    }
                }
            }
            /* #endregion to check Reseller sub/main catalog level */
            
            /* #endregion to check Tlc product level */
            // EOC channel commission
            // BOC hotel commission
            if ($channel_level_commission_flag == 1) {
                $db->select("prepaid_commission_percentage as hotel_prepaid_commission_percentage, postpaid_commission_percentage as hotel_postpaid_commission_percentage, commission_tax_id as hotel_commission_tax_id, commission_tax_value as hotel_commission_tax_value, hgs_prepaid_commission_percentage, hgs_postpaid_commission_percentage, hgs_commission_tax_id, hgs_commission_tax_value");
                $db->from("qr_codes");
                $db->where("cod_id", $hotel_id);
                $result = $db->get()->row();
                $this->CreateLog('price_level.php', 'step8', array("qr_codes" => $db->last_query()));
                $this->CreateLog('price_level.php', 'step8_data', array("qr_codes" => json_encode($result)));
                if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                    $result->pricing_level = 9; // To identify price level
                    $result->commission_level = 8;
                    if (!empty($tlc_commission_array)) {
                        $result = (array) $result;  //conversion of object to array for tlc commission changes                        
                        $result = array_replace($result, $tlc_commission_array);
                        $result = (object) $result; //conversion of array to object again
                    }
                    $data['result'] = $result;
                    $data['supplier_merchant_data'] = $result;
                    return $data;
                } else {
                    if (!empty($tlc_commission_array)) {
                        $tlc_commission_array['pricing_level'] = 9; // To identify price level
                        $result = (object) $tlc_commission_array; //conversion of array to object again
                        $data['result'] = $result;
                        $data['supplier_merchant_data'] = $result;
                        return $data;
                    } else {
                        return false;
                    }
                }
            }
            // EOC hotel commission
        } //EOC ticket level flag
    }
    /* #endregion To get The hotel or ticket level commission values commission values for single ticket type or multiple types*/

    /* #region  To get The hotel or ticket level affiliates values */
    /**
     * get_ticket_hotel_level_affiliates
     *
     * @param  mixed $hotel_id
     * @param  mixed $ticketId
     * @param  mixed $ticketpriceschedule_id
     * @param  mixed $is_secondary_db
     * @param  mixed $channel_id
     * @return void
     * @author  Davinder singh <davinder.intersoft@gmail.com>
     */
    function get_ticket_hotel_level_affiliates($hotel_id, $ticketId, $ticketpriceschedule_id, $is_secondary_db = 0, $channel_id = '') {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $db->select("*");
        $db->from("ticket_level_commission");
        $db->where("hotel_id", $hotel_id);
        $db->where("ticket_id", $ticketId);
        if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
            $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {
            $result = $query->row();
            if ($result->hotel_prepaid_commission_percentage > 0 || $result->hgs_prepaid_commission_percentage > 0) {
                $ticket_level_commission_flag = 0;
            } else {
                $ticket_level_commission_flag = 1;
            }
        } else {
            $ticket_level_commission_flag = 1;
        }
        if ($ticket_level_commission_flag == 0 || 1) {
            $db->select("*");
            $db->from("ticket_level_affiliates");
            $db->where("hotel_id", $hotel_id);
            $db->where("deleted", 0);
            $db->where_in("ticket_id", array($ticketId,0));
            if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                $db->where_in("ticketpriceschedule_id", array($ticketpriceschedule_id,0));
            }
            $db->order_by('ticket_id');
            $query = $db->get();
            if ($query->num_rows() > 0) {
                return $query->result();
            } else {
                //BOC channel level affiliates
                if (!empty($channel_id) && isset($channel_id)) {

                    $db->select("*");
                    $db->from("channel_level_affiliates");
                    $db->where("channel_id", $channel_id);
                    $db->where("deleted", 0);
                    $db->where("ticket_id", $ticketId);
                    if ($ticketpriceschedule_id != '' && $ticketpriceschedule_id > 0) {
                        $db->where("ticketpriceschedule_id", $ticketpriceschedule_id);
                    }
                    $db->order_by('ticket_id');
                    $query = $db->get();
                    if ($query->num_rows() > 0) {
                        return $query->result();
                    }

                    $channel_level_affiliates_flag = 1;
                } else {
                    $channel_level_affiliates_flag = 1;
                }            
                if ($channel_level_affiliates_flag == 1) {
                    $db->select("*");
                    $db->from("hotel_level_affiliates");
                    $db->where("hotel_id", $hotel_id);
                    $query = $db->get();
                    if ($query->num_rows() > 0) {
                        return $query->result();
                    } else {
                        return false;
                    }
                }
                // EOC hotel level affiliates
            }
        }
    }
    /* #endregion To get The hotel or ticket level affiliates values*/
       
    /* #region cityadminpartnercommission  */
    /**
     * cityadminpartnercommission
     *
     * @param  mixed $mec_id
     * @param  mixed $ticketwithdifferentpricing
     * @param  mixed $is_secondary_db
     * @return void
     */
    function cityadminpartnercommission($mec_id = '', $ticketwithdifferentpricing = '', $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $query = 'select *  from ticketfinancialagent';
        if ($ticketwithdifferentpricing == 0) {
            $query .= ' where ticket_id = ' . $mec_id;
        } else {
            $query .= ' where ticketpriceschedule_id = ' . $mec_id;
        }
        $res = $db->query($query);
        if ($res->num_rows() > 0) {
            return $res->result();
        } else {
            return false;
        }
    }
    /* #endregion cityadminpartnercommission*/

    /* #region  It fetches data from hotel_ticket_overview function based on $pspReferenceNo (if exists) */
    /**
     * getRecordsToCaptureTicketPaymentv1
     *
     * @param  mixed $pspReferenceNo
     * @param  mixed $cod_id
     * @param  mixed $visitor_group_number
     * @param  mixed $is_secondary_db
     * @return void
     * @author Hemant Goel <hemant.intersoft@gmail.com> on May 20, 2015
     */
    function getRecordsToCaptureTicketPaymentv1($pspReferenceNo = '', $cod_id = '', $visitor_group_number = '', $is_secondary_db = "0") {
        $db = $this->return_db_object($is_secondary_db);
        $visit_date = strtotime(gmdate('m/d/Y H:i:s'));
        if ($pspReferenceNo == '') {
            $query = 'select distinct hto.id, hto.distributor_type, hto.quantity as order_total_tickets, hto.total_price as order_total_price, hto.total_net_price as order_total_net_price, hto.ticket_ids, hto.voucher_updated_by, hto.total_variable_amount, hto.merchantAccountCode, hto.isprepaid, hto.hotel_name, hto.parentPassNo, hto.all_ticket_ids, hto.total_fixed_amount, hto.total_amount_with_tax, hto.total_amount_without_tax, hto.visitor_group_no, hto.hotel_id, hto.pspReference, hto.shopperStatement, hto.merchantReference, hto.merchantAccountCode, hto.shopperReference, hto.paymentMethod,hto.refused, hto.is_block from hotel_ticket_overview hto where hto.total_amount_without_tax!=0 and hto.latest_ticket_time <="' . $visit_date . '"' . 'and hto.hotel_checkout_status="0" and hto.channel_type != "3" ';
            if ($visitor_group_number != '') {
                $query .= ' and hto.visitor_group_no = "' . $visitor_group_number . '"';
            }
            $query .= ' group by hto.visitor_group_no';
            $query .= ' order by hto.id asc limit 10';
            $res = $db->query($query);
            if ($res->num_rows() > 0) {
                $result = $res->result();
                $card_query = 'select ccf.card_tax_id, ccf.calculated_on, st.tax_value from credit_card_feess_for_hotels ccf left join store_taxes st on st.id = ccf.card_tax_id where ccf.cod_id = "' . $result[0]->hotel_id . '" and ccf.card_name_code = "' . $result[0]->paymentMethod . '" and st.service_id=' . SERVICE_NAME . ' and ccf.service_id=' . SERVICE_NAME;
                $card_result = $this->primarydb->db->query($card_query);
                if ($card_result->num_rows() > 0) {
                    $card_data = $card_result->result()[0];
                    foreach ($result as $key => $data) {
                        $result[$key]->card_tax_id = $card_data->card_tax_id;
                        $result[$key]->calculated_on = $card_data->calculated_on;
                        $result[$key]->tax_value = $card_data->tax_value;
                    }
                } else {
                    foreach ($result as $key => $data) {
                        $result[$key]->card_tax_id = "11";
                        $result[$key]->calculated_on = "0";
                        $result[$key]->tax_value = "21";
                    }
                }
                return $result;
            } else {
                return false;
            }
        } else {
            $query = 'select distinct hto.id, hto.distributor_type, hto.quantity as order_total_tickets, hto.total_price as order_total_price, hto.total_net_price as order_total_net_price, hto.ticket_ids, hto.voucher_updated_by, hto.total_variable_amount, hto.merchantAccountCode, hto.hotel_name, hto.isprepaid, hto.parentPassNo, hto.all_ticket_ids, hto.total_fixed_amount, hto.total_amount_with_tax, hto.total_amount_without_tax, hto.visitor_group_no, hto.hotel_id, hto.pspReference, hto.shopperStatement, hto.merchantReference, hto.merchantAccountCode, hto.shopperReference, hto.paymentMethod, hto.refused, hto.is_block from hotel_ticket_overview hto join visitor_tickets vt on hto.id=vt.hto_id and vt.deleted="0" and vt.is_edited="0" where hto.total_amount_without_tax != 0 and hto.pspReference="' . $pspReferenceNo . '"';
            $res = $db->query($query);
            if ($res->num_rows() > 0) {
                $result = $res->result();
                $card_query = 'select ccf.card_tax_id, ccf.calculated_on, st.tax_value from credit_card_feess_for_hotels ccf left join store_taxes st on st.id = ccf.card_tax_id where ccf.cod_id = "' . $result[0]->hotel_id . '" and ccf.card_name_code = "' . $result[0]->paymentMethod . '" and st.service_id=' . SERVICE_NAME . ' and ccf.service_id=' . SERVICE_NAME;
                $card_result = $this->primarydb->db->query($card_query);
                if ($card_result->num_rows() > 0) {
                    $card_data = $card_result->result()[0];
                    foreach ($result as $key => $data) {
                        $result[$key]->card_tax_id = $card_data->card_tax_id;
                        $result[$key]->calculated_on = $card_data->calculated_on;
                        $result[$key]->tax_value = $card_data->tax_value;
                    }
                }
                return $result;
            } else {
                $query = 'select distinct hto.id, hto.distributor_type, hto.quantity as order_total_tickets, hto.total_price as order_total_price, hto.total_net_price as order_total_net_price, hto.ticket_ids, hto.voucher_updated_by, hto.total_variable_amount, hto.merchantAccountCode, hto.hotel_name, hto.isprepaid, hto.parentPassNo, hto.all_ticket_ids, hto.total_fixed_amount, hto.total_amount_with_tax, hto.total_amount_without_tax, hto.visitor_group_no, hto.hotel_id, hto.pspReference, hto.shopperStatement, hto.merchantReference, hto.merchantAccountCode, hto.shopperReference, hto.paymentMethod, hto.refused, hto.is_block from hotel_ticket_overview hto join visitor_tickets vt on hto.id=vt.hto_id and vt.deleted="0" and vt.is_edited="0" where hto.total_amount_without_tax != 0 and hto.pspReference="' . $pspReferenceNo . '"';
                $res = $db->query($query);
                if ($res->num_rows() > 0) {
                    $result = $res->result();
                    $card_query = 'select ccf.card_tax_id, ccf.calculated_on, st.tax_value from credit_card_feess_for_hotels ccf left join store_taxes st on st.id = ccf.card_tax_id where ccf.card_name_code = "' . $result[0]->paymentMethod . '" and st.service_id=' . SERVICE_NAME . ' and ccf.service_id=' . SERVICE_NAME;
                    $card_result = $this->primarydb->db->query($card_query);
                    if ($card_result->num_rows() > 0) {
                        $card_data = $card_result->result()[0];
                        foreach ($result as $key => $data) {
                            $result[$key]->card_tax_id = $card_data->card_tax_id;
                            $result[$key]->calculated_on = $card_data->calculated_on;
                            $result[$key]->tax_value = $card_data->tax_value;
                        }
                    }
                    return $result;
                } else {
                    return false;
                }
            }
        }
    }
    /* #endregion It fetches data from hotel_ticket_overview function based on $pspReferenceNo (if exists)*/

    /* #region updatePaidStatus  */
    /**
     * updatePaidStatus
     *
     * @param  mixed $pspReference
     * @param  mixed $passNo
     * @param  mixed $captured
     * @param  mixed $responsePspReference
     * @param  mixed $is_secondary_db
     * @return void
     */
    function updatePaidStatus($pspReference, $passNo = '', $captured = '0', $responsePspReference = '', $is_secondary_db = "0") {
        
        return True;
    }
    /* #endregion updatePaidStatus*/
    
    /* #region addTicketAmountToHotel  */
    /**
     * addTicketAmountToHotel
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $hotel_id
     * @param  mixed $id
     * @param  mixed $move_flag
     * @param  mixed $status
     * @param  mixed $is_secondary_db
     * @return void
     */
    function addTicketAmountToHotel($visitor_group_no = '', $hotel_id = '', $id = '', $move_flag = 0, $status = 0, $is_secondary_db = "0") {
        $db = $this->db;
        // get all pending ticket amounts
        if ($status != "3" && $status != "2" && $status != '5') {
            $allrecords = $this->pendingTicektsFrompVisitorGroupNoV1($visitor_group_no, $id, $is_secondary_db);
        } else {
            $allrecords = $this->pendingTicektsFrompVisitorGroupNo($visitor_group_no, $id, $is_secondary_db);
        }
        foreach ($allrecords as $res) {
            $data = array();
            $data['hotel_id'] = $hotel_id;
            $data['ticketAmt'] = $res->ticketAmt;
            $data['qty'] = $res->qty;
            $data['partner_name'] = $res->partner_name;
            $data['partner_id'] = $res->partner_id;
            $data['ticket_title'] = $res->ticket_title;
            $data['total_partner_net_price'] = $res->total_partner_net_price;
            $data['tax_value'] = $res->tax_value;
            $data['total_partner_gross_price'] = $res->total_partner_gross_price;
            $data['used'] = "0";
            $data['cc_initial_payment'] = "0";
            $data['completed'] = "0";
            $data['visitor_ticket_id'] = $res->id;
            $db->insert("failed_tickets", $data);
            //move this amount from adyen to collector
        }
    }
    /* #endregion addTicketAmountToHotel*/  

    /* #region updateCapturedStatus   */
    /**
     * updateCapturedStatus
     *
     * @param  mixed $pspReference
     * @param  mixed $invoice_status
     * @param  mixed $is_secondary_db
     * @return void
     */
    function updateCapturedStatus($pspReference = '', $invoice_status = "3", $is_secondary_db = "0") {
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_object = new Sns();
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $db = $this->return_db_object($is_secondary_db);
        if ($pspReference != '') {
            /* COMMENTED $update = 'update visitor_tickets set captured = "1", invoice_status="' . $invoice_status . '" where pspReference = "' . $pspReference . '"'; 
            $db->query($update); */
            $this->send_data_to_update_sns($db, $sqs_object, $sns_object, $is_secondary_db);            
        }
    }
    /* #endregion updateCapturedStatus*/

    /* #region capturePayment  */
    /**
     * capturePayment
     *
     * @param  mixed $merchantAccount
     * @param  mixed $modificationAmount
     * @param  mixed $pspReference
     * @param  mixed $currency
     * @return void
     */
    function capturePayment($merchantAccount = '', $modificationAmount = '', $pspReference = '', $currency = '') {
        $oREC = new CI_AdyenRecurringPayment(false);
        $modificationAmount = $modificationAmount * 100;
        $oREC->startSOAP("Payment");
        $oREC->capture($merchantAccount, $modificationAmount, $pspReference, $currency);
        return $oREC->response->captureResult->response;
    }
    /* #endregion capturePayment*/

    /* #region getUnpaidTicketAmount */
    /**
     * getUnpaidTicketAmount
     *
     * @param  mixed $passNo
     * @param  mixed $pspReference
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getUnpaidTicketAmount($passNo = '', $pspReference = '', $is_secondary_db = "0") {        
        $db = $this->secondarydb->rodb;
        $query = 'select sum(vt.ticketAmt) as ticketAmt from visitor_tickets vt left join hotel_ticket_overview hto on hto.id=vt.hto_id and vt.paid="0" and vt.deleted="0" where hto.pspReference="' . $pspReference . '"';
        $res = $db->query($query);
        if ($res->num_rows() > 0) {
            $result = $res->row();
            return $result->ticketAmt > 0 ? $result->ticketAmt : 0;
        } else {
            return 0;
        }
    }
    /* #endregion getUnpaidTicketAmount*/

    /* #region addticketPaymentFailCount  */
    /**
     * addticketPaymentFailCount
     *
     * @param  mixed $pspReference
     * @param  mixed $is_secondary_db
     * @return void
     */
    function addticketPaymentFailCount($pspReference, $is_secondary_db = "0") {
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_object = new Sns();
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $db = $this->return_db_object($is_secondary_db);
        /* COMMENTED $query = 'update hotel_ticket_overview set ticketPaymentFailCount=ticketPaymentFailCount+1 where pspReference="' . $pspReference . '"';
        $db->query($query);
        */
        $this->send_data_to_update_sns($db, $sqs_object, $sns_object, $is_secondary_db);        
    }
    /* #endregion addticketPaymentFailCount*/

    /* #region send_data_to_update_sns  */
    /**
     * send_data_to_update_sns
     *
     * @param  mixed $db
     * @param  mixed $sqs_object
     * @param  mixed $sns_object
     * @param  mixed $is_secondary_db
     * @return void
     */
    function send_data_to_update_sns($db, $sqs_object, $sns_object, $is_secondary_db) {

        if ($is_secondary_db == "1" || $is_secondary_db == "4") {
            $sns_messages = array();
            $sns_messages[] = $db->last_query();
            if (!empty($sns_messages)) {

                $request_array['db1'] = $sns_messages;
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;                    
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                    
                if ($MessageId) {
                    $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                }
            }
        }
    }
    /* #endregion send_data_to_update_sns*/

    /* #region insertCcTransactionfees  */
    /**
     * insertCcTransactionfees
     *
     * @param  mixed $data
     * @param  mixed $partner_data
     * @param  mixed $is_secondary_db
     * @return void
     */
    function insertCcTransactionfees($data = '', $partner_data = '', $is_secondary_db = "0") {        
        $invoice_status = $data['invoice_status'];
        $transaction_id = $data['transaction_id'];
        $visitor_group_no = $data['visitor_group_no'];
        $id = $data['id'];
        $ticket_title = $data['ticket_title'];
        $visit_date = $data['visit_date'];
        $timeZone = $data['timeZone'];
        $debitor = $data['debitor'];
        $creditor = $data['creditor'];
        $partner_gross_price = $data['partner_gross_price'];
        $partner_net_price = $data['partner_net_price'];
        $tax_id = $data['tax_id'];
        $tax_value = $data['tax_value'];
        $row_type = $data['row_type'];
        $invoice_id = $data['invoice_id'];
        $transaction_type_name = $data['transaction_type_name'];
        $paymentMethodType = $data['paymentMethodType'];
        $is_credit_card_fee_charged_to_guest = $data['is_credit_card_fee_charged_to_guest'];
        $all_ticket_ids = $data['all_ticket_ids'];

        if ($partner_data != '') {
            $partner_name = $partner_data['partner_name'];
            $partner_id = $partner_data['partner_id'];
        } else {
            $partner_name = '';
            $partner_id = '';
        }
        if ($is_credit_card_fee_charged_to_guest == 0) {// if credit card cost charge to distributor
            if ($row_type == '8') {
                $invoice_status = '5';
                $payment_method = "Bank";
            } else {
                $payment_method = $partner_name;
            }
        } else {
            $payment_method = "Adyen";
        }

        $insert = 'insert into visitor_tickets set ';
        $insert .= 'id = ' . $id . ', ';
        $insert .= 'transaction_id = ' . $transaction_id . ', ';
        if ($visitor_group_no != '') {
            $insert .= 'visitor_group_no = ' . $visitor_group_no . ', ';
            $insert .= 'vt_group_no = ' . $visitor_group_no . ', ';
        }
        if ($is_credit_card_fee_charged_to_guest == 0) {// if credit card cost charge to distributor
            if ($row_type == '8') {
                $insert .= 'adyen_status = "9", ';
            }
            $insert .= 'adjustment_method = "2", ';
            $invoice_id = '';
        }
        if ($invoice_id != '') {
            $insert .= 'invoice_id="' . $invoice_id . '", ';
        } else {
            $insert .= 'invoice_id="", ';
        }
        if (isset($data['isprepaid']) && $data['isprepaid'] != '') {
            $insert .= 'is_prepaid="' . $data['isprepaid'] . '", ';
        }
        $insert .= 'ticketId = 0, ';
        $insert .= 'ticket_title = "' . $ticket_title . '", ';
        $insert .= 'visit_date = "' . $visit_date . '", ';
        $insert .= 'created_date = "' . gmdate('Y/m/d H:i:s') . '", ';
        $insert .= 'visit_date_time = "' . gmdate('Y/m/d H:i:s') . '", ';
        $insert .= 'timeZone = "' . $timeZone . '", ';
        $insert .= 'paid = "1", ';
        $insert .= 'payment_method = "' . $payment_method . '", ';
        $insert .= 'all_ticket_ids = "' . $all_ticket_ids . '", ';
        $insert .= 'captured = "1", ';
        $insert .= 'debitor = "' . $debitor . '", ';
        $insert .= 'creditor = "' . $creditor . '", ';
        $insert .= 'partner_gross_price = ' . $partner_gross_price . ', ';
        $insert .= 'partner_net_price = ' . $partner_net_price . ', ';
        $insert .= 'tax_id = ' . $tax_id . ', ';
        $insert .= 'tax_value = ' . $tax_value . ', ';
        $insert .= 'invoice_status = "' . $invoice_status . '", ';
        $insert .= 'transaction_type_name = "' . $transaction_type_name . '", ';
        $insert .= 'paymentMethodType = ' . $paymentMethodType . ', ';
        if ($partner_name != '') {
            $insert .= 'partner_name = "' . $partner_name . '", ';
            $insert .= 'partner_id = ' . $partner_id . ', ';
            $insert .= 'distributor_status = "0", ';
            $insert .= 'isBillToHotel = "1", ';
        }
        $insert .= 'row_type = "' . $row_type . '"';
        $sns_messages = array();
        $sns_messages[] = $insert;
        if ($is_secondary_db == "1") {

            include_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sns');
            $sns_object = new Sns();
            $this->load->library('Sqs');
            $sqs_object = new Sqs();
            if (!empty($sns_messages)) {

                $request_array['db2'] = $sns_messages;
                $request_array['visitor_group_no'] = $visitor_group_no;
                $request_string = json_encode($request_array);
                $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                $queueUrl = UPDATE_DB_QUEUE_URL;                
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);                
                if ($MessageId) {
                    $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                }
            }
        }
    }
    /* #endregion insertCcTransactionfees */
}
?>