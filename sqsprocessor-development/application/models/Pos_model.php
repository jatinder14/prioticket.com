<?php
class Pos_Model extends MY_Model
{
    
    /* #region  Boc of model pos */
    /* #region main function to load Model pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() 
    {
        parent::__construct();
        $this->load->model('common_model');
        $this->load->model('order_process_vt_model');
        $this->load->model('order_process_model');
        $this->base_url = $this->config->config['base_url'];
        $this->root_path = $this->config->config['root_path'];
        $this->imageDir = $this->config->config['imageDir'];
        $this->mpos_api_server = $this->config->config['mpos_api_server'];
        $this->merchant_price_col = 'merchant_price';
        $this->merchant_net_price_col = 'merchant_net_price';
        $this->merchant_tax_id_col = 'merchant_tax_id';
        $this->merchant_currency_code_col = 'merchant_currency_code';
        $this->supplier_tax_id_col = 'supplier_tax_id';
        $this->admin_currency_code_col = 'admin_currency_code';
    }
    /* #endregion main function to load Model pos*/

    /* #region To get JSON details to sync on firebase.  */
    /**
     * SYNC_firebase
     *
     * @param  mixed $hotel_id
     * @param  mixed $ticket_id
     * @param  mixed $exit
     * @param  mixed $q
     * @return void
     */
    function SYNC_firebase($hotel_id = '', $ticket_id = '', $exit = 0, $q = '') {
        /* Initialization section */
        $all_ticket_ids = array();
        $hotel_tickets = array();
        $type_details = array();
        $option_details = array();
        $response = array();
        $settings = array();
        $details = array();
        $menu_options = array();
        $printer_settings = array();
        $payment_options = array();
        $receipt_settings = array();
        $general_settings = array();
        $pos_point_settings = array();
        $all_categories = array();
        $ticket_location = array();
        $supplier_settings = array();
        $shifts_details = array();
        
        /* Converte request node string to array */
        $array_keys = array();
        if (isset($_REQUEST['q']) && $_REQUEST['q'] != '') {
            $q = $_REQUEST['q'];
        }
        if ($q != '') {
            $array_keys = explode('/', $q);
        }
        /* Set distributor id in array */
        $distributors = array();
        if ($hotel_id != '') {
            $distributors = array($hotel_id);
        }
        $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
        $timezone = $servicedata->timeZone;
        /* Get all ticket categories */
        if (empty($array_keys) || in_array('categories', $array_keys)) {
            $categories = $this->pos_model->find('ticket_categories', array('select' => 'category_id, name, category_logo, parent_category_id'));
            if (!empty($categories)) {
                foreach ($categories as $category) {
                    $all_categories[] = array(
                        'category_id' => (int) $category['category_id'],
                        'category_name' => $category['name'],
                        'icon' => base_url() . $category['category_logo'],
                        'parent_category_id' => (int) $category['parent_category_id'],
                    );
                }
                $response['categories'] = $all_categories;
            }
        }

        /* Get ticket level details about ticket like ticket prices, discounts */
        $is_combi = array();

        if (empty($array_keys) || in_array('tickets', $array_keys) && !empty($distributors)) {
            if ($ticket_id != '') {
                $ticket_settings = $this->pos_model->find('ticket_level_commission', array('select' => '*', 'where' => 'is_adjust_pricing = 1 and hotel_id in (' . implode(",", $distributors) . ') and ticket_id = "' . $ticket_id . '"'));
            } else {
                $ticket_settings = $this->pos_model->find('ticket_level_commission', array('select' => '*', 'where' => 'is_adjust_pricing = 1 and hotel_id in (' . implode(",", $distributors) . ')'));
            }
            if (!empty($ticket_settings)) {
                foreach ($ticket_settings as $setting) {
                    if ($setting['ticket_new_price']) {
                        $price = ($setting['ticket_new_price'] > 0) ? (float) $setting['ticket_new_price'] : (float) $setting['ticket_list_price'];
                        $net_price = $price - round((($price * $setting['ticket_tax_value']) / (100 + $setting['ticket_tax_value'])), 2);
                        $ticket_level_settings[$setting['hotel_id']][$setting['ticket_id']][$setting['ticketpriceschedule_id']] = array(
                            'tps_id' => (int) $setting['ticketpriceschedule_id'],
                            'price' => $price,
                            'discount_type' => (int) $setting['is_discount_in_percent'] + 1,
                            'discount' => ($setting['ticket_discount']) ? (float) $setting['ticket_discount'] : (float) 0,
                            'net_price' => (float) $net_price,
                            'price_after_discount' => ($setting['ticket_gross_price'] != 0) ? (float) $setting['ticket_gross_price'] : (float) 0,
                            'net_price_after_discount' => ($setting['ticket_net_price']) ? (float) $setting['ticket_net_price'] : (float) 0,
                            'combi_discount' => ($setting['combi_discount_gross_amount']) ? (float) $setting['combi_discount_gross_amount'] : (float) 0,
                        );
                        if ($setting['combi_discount_gross_amount'] > 0) {
                            $is_combi[$setting['hotel_id']][$setting['ticket_id']] = 1;
                        } else {
                            $is_combi[$setting['hotel_id']][$setting['ticket_id']] = (isset($is_combi[$setting['hotel_id']][$setting['ticket_id']]) && $is_combi[$setting['hotel_id']][$setting['ticket_id']] == 1) ? 1 : 0;
                        }
                    } else {
                        $ticket_level_settings[$setting['hotel_id']][$setting['ticket_id']] = array();
                    }
                }
            }
            $hotel_channel_details = $this->find('qr_codes', array('select' => 'channel_id', 'where' => 'cod_id = "' . $hotel_id . '"'));
            $channel_id = (isset($_REQUEST['channel_id']) && $_REQUEST['channel_id'] != '') ? $_REQUEST['channel_id'] : $hotel_channel_details[0]['channel_id'];
            /* Get prices at channel level */
            if ($ticket_id != '') {
                $where = 'ticket_id = "' . $ticket_id . '" and channel_id = "' . $channel_id . '" and ( clc.hotel_prepaid_commission_percentage > 0 OR clc.hgs_prepaid_commission_percentage > 0 ) and clc.is_adjust_pricing = "1" and commission_updated_at!="" ';
            } else {
                $where = 'channel_id = "' . $channel_id . '" and ( clc.hotel_prepaid_commission_percentage > 0 OR clc.hgs_prepaid_commission_percentage > 0 ) and clc.is_adjust_pricing = "1" and commission_updated_at!="" ';
            }

            $channel_data = $this->find('channel_level_commission clc', array('select' => 'clc.is_discount_in_percent as clc_is_discount_in_percent ,
                                                                                clc.ticket_discount as clc_ticket_discount ,
                                                                                clc.ticket_new_price as clc_ticket_new_price ,
                                                                                clc.ticket_gross_price as clc_ticket_gross_price ,
                                                                                clc.ticket_net_price as clc_ticket_net_price ,
                                                                                clc.is_combi_discount as clc_is_combi_discount ,
                                                                                clc.ticket_tax_value as clc_ticket_tax_value ,
                                                                                clc.combi_discount_gross_amount as clc_combi_discount_gross_amount ,
                                                                                clc.ticket_id,
                                                                                clc.channel_id,
                                                                                clc.ticketpriceschedule_id,
                                                                                clc.museum_gross_commission as museum_commission'
                , 'where' => $where));
            if (!empty($channel_data)) {
                $channel_level_settings = array();
                foreach ($channel_data as $channel) {
                    $price = (float) $channel['clc_ticket_new_price'];
                    $net_price = $price - round((($price * $channel['clc_ticket_tax_value']) / (100 + $channel['clc_ticket_tax_value'])), 2);
                    $channel_level_settings[$hotel_id][$channel['ticket_id']][$channel['ticketpriceschedule_id']] = array(
                        'tps_id' => (int) $channel['ticketpriceschedule_id'],
                        'price' => $price,
                        'discount_type' => (int) $channel['clc_is_discount_in_percent'] + 1,
                        'discount' => ($channel['clc_ticket_discount']) ? (float) $channel['clc_ticket_discount'] : (float) 0,
                        'price_after_discount' => ($channel['clc_ticket_gross_price'] != 0) ? (float) $channel['clc_ticket_gross_price'] : (float) 0,
                        'net_price' => (float) $net_price,
                        'net_price_after_discount' => ($channel['clc_ticket_net_price']) ? (float) $channel['clc_ticket_net_price'] : (float) 0,
                        'combi_discount' => ($channel['clc_combi_discount_gross_amount']) ? (float) $channel['clc_combi_discount_gross_amount'] : (float) 0,
                    );
                    if ($channel['clc_combi_discount_gross_amount'] > 0) {
                        $is_combi[$hotel_id][$channel['ticket_id']] = 1;
                    } else {
                        $is_combi[$hotel_id][$channel['ticket_id']] = (isset($is_combi[$hotel_id][$channel['ticket_id']]) && $is_combi[$hotel_id][$channel['ticket_id']] == 1) ? 1 : 0;
                    }
                }
            }
        }

        /* Get all ticket visible for distributor */
        if (!empty($distributors) || in_array('tickets', $array_keys)) {
            if ($ticket_id != '') {
                $pos_tickets = $this->pos_model->find('pos_tickets', array('select' => '*', 'where' => 'hotel_id in (' . implode(',', $distributors) . ') and deleted = "0" and end_date >= "' . strtotime(date('Y-m-d')) . '" and mec_id = "' . $ticket_id . '"'));
            } else {
                $pos_tickets = $this->pos_model->find('pos_tickets', array('select' => '*', 'where' => 'hotel_id in (' . implode(',', $distributors) . ') and deleted = "0" and end_date >= "' . strtotime(date('Y-m-d')) . '"'));
            }

            foreach ($pos_tickets as $tickets) {
                $all_ticket_ids[$tickets['mec_id']]['mec_id'] = $tickets['mec_id'];
                $all_ticket_tax[$tickets['mec_id']] = array(
                    'tax' => (float) $tickets['tax_value'],
                );
                $all_ticket_price[$tickets['mec_id']] = array(
                    'price' => (float) $tickets['ticketPrice'],
                );
                $all_ticket_ids[$tickets['mec_id']]['is_pos_list'] = (int) $tickets['is_pos_list'];

                $prices = array();
                if (isset($ticket_level_settings[$tickets['hotel_id']][$tickets['mec_id']]) && !empty($ticket_level_settings[$tickets['hotel_id']][$tickets['mec_id']])) {
                    $prices = $ticket_level_settings[$tickets['hotel_id']][$tickets['mec_id']];
                }
                if (empty($prices) && isset($channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']]) && !empty($channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']])) {
                    $prices = $channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']];
                } else if (!empty($prices) && isset($channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']]) && !empty($channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']])) {
                    foreach ($channel_level_settings[$tickets['hotel_id']][$tickets['mec_id']] as $channel_level_commission) {
                        if (!key_exists($channel_level_commission['tps_id'], $prices)) {
                            $prices[$channel_level_commission['tps_id']] = $channel_level_commission;
                        }
                    }
                }
                sort($prices);
                $is_combi_ticket = ($tickets['is_reservation'] == 1) ? $tickets['is_combi_ticket_allowed'] : $tickets['is_booking_combi_ticket_allowed'];
                $hotel_tickets[$tickets['hotel_id']][] = array(
                    'list' => ((bool) ($tickets['is_pos_list'] == 1)),
                    "ticket_id" => (int) $tickets['mec_id'],
                    "per_day_sold_count" => ($tickets['per_day_sold_count']) ? (int) $tickets['per_day_sold_count'] : (int) 0,
                    "ticket_sold_count" => ($tickets['ticket_sold_count']) ? (int) $tickets['ticket_sold_count'] : (int) 0,
                    "latest_sold_date" => ($tickets['latest_sold_date'] != '') ? date('Y-m-d', strtotime($tickets['latest_sold_date'])) : '',
                    "is_combi_ticket" => ($is_combi_ticket) ? (int) $is_combi_ticket : (int) 0,
                    "is_combi_discount" => (isset($is_combi[$tickets['hotel_id']][$tickets['mec_id']])) ? (int) $is_combi[$tickets['hotel_id']][$tickets['mec_id']] : (int) 0,
                    "category_id" => ($tickets['cat_id']) ? (int) $tickets['cat_id'] : (int) 0,
                    "prices" => $prices
                );
            }
        }
        
        /*Get cashier role settings for distributor*/
        if (!empty($distributors) || in_array('cashier_roles', $array_keys)) {
            $hotel_all_users = $this->common_model->get_all_cashiers($hotel_id);
            $role_settings = $this->find('user_role_settings', array('select' => '*', 'where' => 'user_id = "'.$hotel_id.'"'));
            foreach($hotel_all_users as $user) {
                if($user['user_role'] == 1) {
                    $permissions = 'cashier_permissions';
                } else if($user['user_role'] == 2) {
                    $permissions = 'supervisor_permissions';
                } else if($user['user_role'] == 3) {
                    $permissions = 'admin_permissions';
                }else if($user['user_role'] == 5) {
                    $permissions = 'mpm_permissions';
                }  else if($user['user_role'] == 6) {
                    $permissions = 'streetsellers_permissions';
                }           
                $role_settings = $role_settings[0][$permissions];
                $hotel_cashiers[$hotel_id][$user['user_id']] = array(
                    'allow_cancel_orders' => (int)$user['allow_cancel_orders'],
                    'allow_order_discount' => (int)$user['allow_order_discount'],
                    'allow_order_overview' => (int)$user['allow_order_overview'],
                    'allow_redeem_orders' => (int)$user['allow_redeem_orders'],
                    'allow_sell_tickets' => (int)$user['allow_sell_tickets'],
                    'user_role' => (int)$user['user_role'],
                    'default_supplier_cashier' => (int)$user['default_supplier_cashier'],
                    'show_guide_reference' => (in_array("1", $role_settings)) ? (int)1 : (int)0,
                );
            }
        }
        
        /* Get all distributor and supplier settings */
        if (!empty($distributors) || in_array('ticket', $array_keys) || in_array('guides', $array_keys)) {
            $hotel_details = $this->pos_model->find('qr_codes', array('select' => '*', 'where' => '(cod_id = "' . $hotel_id . '" or cashier_type = "2")'));
            $service_cost_tax = $this->pos_model->find('store_taxes', array('select' => 'id, tax_value'), 'list');
            $supplier_id = '';
            foreach ($hotel_details as $hotel) {
                if ($hotel['cashier_type'] == '1') {
                    if($hotel['own_supplier_id'] != '' && $hotel['own_supplier_id'] != NULL && $hotel['own_supplier_id'] != '0') {
                        $supplier_id = $hotel['own_supplier_id'];
                    }
                    $iban = '';
                    if( $hotel['iban'] != '' ) {
                        $iban = $hotel['iban'];
                    }
                    else if( !empty( $hotel['iban_no'] ) ) {
                        $iban = $hotel['iban_no'];
                    }
                    $details[$hotel['cod_id']] = array(
                        'id' => (int) $hotel['cod_id'],
                        'name' => $hotel['company'] != '' ? $hotel['company'] : '',
                        'logo' => base_url() . '/qrcodes/images/companies/' . $hotel['genericComPhoto'],
                        'address' => $hotel['address'] != '' ? $hotel['address'] : '',
                        'zipcode' => $hotel['postalCode'] != '' ? (string) $hotel['postalCode'] : '',
                        'country' => $hotel['country'] != '' ? $hotel['country'] : '',
                        'telephone' => $hotel['phone'] != '' ? (string) $hotel['phone'] : (string) '',
                        'email' => $hotel['email'] != '' ? $hotel['email'] : '',
                        'btw' => $hotel['btw_code'] != '' ? $hotel['btw_code'] : '',
                        'iban' => $iban,
                        'bic' => $hotel['bic'] != '' ? $hotel['bic'] : '',
                        'kvk' => $hotel['kvk_code'] != '' ? $hotel['kvk_code'] : ''
                    );
                    if ($hotel['discount_labels'] != '' && $hotel['discount_labels'] != NULL) {
                        $discount_labels = explode(',', $hotel['discount_labels']);
                        $discount_labels[] = 'others';
                    }
                    $update_theme_colors = array();
                    $custom_theme = false;
                    if($hotel['mpos_theme_colors'] != '' && $hotel['mpos_theme_colors'] != NULL) {
                        $theme_colors = json_decode($hotel['mpos_theme_colors']);
                        if( $theme_colors->custom_theme == "true" ) {
                            $custom_theme = true;
                        }
                        $update_theme_colors = array(
                            'colorAccent' => $theme_colors->color_accent,
                            'colorPrimary' => $theme_colors->color_primary,
                            'colorPrimaryDark' => $theme_colors->color_primary_dark,
                        );
                    } else {
                        $update_theme_colors = array(
                            'colorAccent' => '#ab8b53',
                            'colorPrimary' => '#1cb8c3',
                            'colorPrimaryDark' => '#29A2AB',
                        );
                    }
                    $settings[$hotel['cod_id']]['service_cost_type'] = (int) $hotel['service_cost_type'];
                    $settings[$hotel['cod_id']]['discount_labels'] = $discount_labels;
                    $settings[$hotel['cod_id']]['pass_activation_payment_method'] = (int) $hotel['paymentMethodType'];
                    $settings[$hotel['cod_id']]['distributor_type'] = $hotel['distributor_type'];
                    $settings[$hotel['cod_id']]['service_cost_tax'] = (int) $service_cost_tax[$hotel['service_cost_tax']];
                    $settings[$hotel['cod_id']]['service_cost_amount'] = (float) $hotel['service_cost'];
                    $settings[$hotel['cod_id']]['instant_ticket_charge'] = ($hotel['instant_ticket_charge'] != '') ? (int) $hotel['instant_ticket_charge'] : (int)0;
                    $settings[$hotel['cod_id']]['google_api_map_type'] = (int) $hotel['google_api_map_type'];
                    $settings[$hotel['cod_id']]['dashboard_type'] = (int) ($hotel['dashboard_type'] == 2) ? (int)2 : (int)1;
                    $settings[$hotel['cod_id']]['is_credit_card_fee_charged_to_guest'] = (int) $hotel['is_credit_card_fee_charged_to_guest'];
                    $settings[$hotel['cod_id']]['pos_type'] = (int) $hotel['pos_type'];
                    $settings[$hotel['cod_id']]['channel_id'] = (int) $hotel['channel_id'];
                    $settings[$hotel['cod_id']]['channel_name'] = $hotel['channel_name'];
                    if($hotel['add_room_no'] == 1) { $settings[$hotel['cod_id']]['add_room_no'] = true; }
                    else { $settings[$hotel['cod_id']]['add_room_no'] = false; }
                    if($hotel['add_booking_name'] == 1) { $settings[$hotel['cod_id']]['add_booking_name'] = true; }
                    else { $settings[$hotel['cod_id']]['add_booking_name'] = false; }
                    if($hotel['add_email'] == 1) { $settings[$hotel['cod_id']]['add_email'] = true; }
                    else { $settings[$hotel['cod_id']]['add_email'] = false; }
                    $settings[$hotel['cod_id']]['timezone'] = $timezone;
                    $settings[$hotel['cod_id']]['theme']['custom_colors'] = $custom_theme;
                    $settings[$hotel['cod_id']]['theme']['colors'] = $update_theme_colors;
                    $all_methods = array('credit_card', 'hotel_bill', 'cash', 'card');
                    $payment_options = str_replace(array('1', '2', ',3', '4', '5'), array('credit_card', 'hotel_bill', '', 'cash', 'card'), $hotel['pos_payment_option']);
                    $payment_options = explode(',', $payment_options);
                    foreach ($all_methods as $option) {
                        $in_payment_option = false;
                        if( in_array( $option, $payment_options ) ) {
                            $in_payment_option = true;
                        }
                        $settings[$hotel['cod_id']]['payment_options'][$option] = $in_payment_option;
                    }
                    if($hotel['guide_details'] != NULL && $hotel['guide_details'] != '') {
                        $guide_codes = unserialize($hotel['guide_details']);
                        foreach($guide_codes as $key => $guide_name) {
                           $all_guide_codes[$hotel['cod_id']][] = array(
                               'code' => (string)$key,
                               'name' => $guide_name
                           );
                        }
                    }
                } else {
                    $all_suppliers[$hotel['cod_id']] = $hotel;
                    $supplier_settings[$hotel['cod_id']] = array(
                        'company' => $hotel['company'],
                        'is_combi_ticket_allowed' => (int) ($hotel['is_combi_ticket_allowed']) ? $hotel['is_combi_ticket_allowed'] : 0,
                        'is_booking_combi_ticket_allowed' => (int) ($hotel['is_booking_combi_ticket_allowed']) ? $hotel['is_booking_combi_ticket_allowed'] : 0,
                    );
                }
            }
            if($supplier_id != '') {
                if ($all_suppliers[$supplier_id]['welcomeBulletinBoard'] == '') {
                    $all_suppliers[$supplier_id]['welcomeBulletinBoard'] = '(b) ' . lang("approach_customers_with_a_friendly_smile");
                }
                $hotel_users = array();
                // Get all users of museum
                $hotelmuseumdata = $this->common_model->getAllInvitedUserOfCompany($supplier_id, $all_suppliers[$supplier_id]['cashier_type']);
                foreach($hotelmuseumdata as $user) {
                    $hotel_users[] = array(
                        'email' => $user['email'],
                        'fname' => ($user['fname'] != '') ? $user['fname'] : '',
                        'lname' => ($user['lname'] != '') ? $user['lname'] : '',
                        'loggedInUserType' => $user['loggedInUserType'],
                        'password' => $user['password'],
                        'user_id' => (int)$user['user_id'],
                    );
                }
                $supplier_details[$supplier_id] = array(
                    'company'=> $all_suppliers[$supplier_id]['company'],
                    'is_hotel_museum'=> (int)$all_suppliers[$supplier_id]['is_hotel_museum'],
                    'keyboard_type'=> (int)$all_suppliers[$supplier_id]['keyboard_type'],
                    'multiUserAccess'=> (int)$all_suppliers[$supplier_id]['multiuser_access'],
                    'paymentMethodType'=> (int)$all_suppliers[$supplier_id]['paymentMethodType'],
                    'name_on_activation'=> (int)$all_suppliers[$supplier_id]['name_on_activation'],
                    'check_in_option'=> (int)$all_suppliers[$supplier_id]['check_in_option'],
                    'warning_text'=> $all_suppliers[$supplier_id]['warning_text'],
                    'welcomeBulletinBoard'=> str_replace('(b)', '^', $all_suppliers[$supplier_id]['welcomeBulletinBoard']),
                    'is_inc_tax'=> (int)$all_suppliers[$supplier_id]['is_inc_tax'],
                    'is_confirm_checkout_page_on'=> (int)$all_suppliers[$supplier_id]['is_confirm_checkout_page_on'],
                    'display_detail_on_checkout_overview'=> (int)$all_suppliers[$supplier_id]['display_detail_on_checkout_overview'],
                    'merchantAdminstativeInstructionIsMandatory'=> (int)$all_suppliers[$supplier_id]['merchantAdminstativeInstructionIsMandatory'],
                    'merchantAdminstativeInstruction'=> ($all_suppliers[$supplier_id]['merchantAdminstativeInstruction'] == '' || $all_suppliers[$supplier_id]['merchantAdminstativeInstruction'] == 'NULL') ? '' : $all_suppliers[$supplier_id]['merchantAdminstativeInstruction'],
                    'guest_image'=> (int)$all_suppliers[$supplier_id]['is_guest_image'],
                    'displayNight'=> (int)$all_suppliers[$supplier_id]['is_display_night'],
                    'is_group_check_in_allowed'=> (int)$all_suppliers[$supplier_id]['is_group_check_in_allowed'],
                    'add_to_pass'=> (int)$all_suppliers[$supplier_id]['add_to_pass'],
                    'is_one_by_one_check_in_allowed'=> (int)1,
                    'activeTicketCount'=> (int)0,
                    'pendingTicketCount'=> (int)0,
                    'users'=> (!empty($hotel_users)) ? $hotel_users : array(),
                    'country' => ucfirst($servicedata->selected_country),
                    'language'=> $servicedata->language,
                    'currency'=> $this->currency_code,
                    'numberFormat'=> (int)$servicedata->numberformat,
                );
            }
        }
        /* Get pos_point_settings for selected distributor or all active distributors */
        if (!empty($distributors)) {
            $pos_point_setting = $this->pos_model->find('pos_points_setting', array('select' => '*', 'where' => 'hotel_id in (' . implode(',', $distributors) . ') and deleted = "0"'));
            $cashier_shifts = $this->pos_model->find('cashier_shifts', array('select' => '*', 'where' => 'distributor_id in (' . implode(',', $distributors) . ') '));
            //get cashier shifts details array
     
            foreach($cashier_shifts as $shifts ) {
                $shifts_details[$shifts['pos_point_id']][] =  array(
                    'shift_id' => (int)$shifts['shift_id'],
                    'shift_name' => $shifts['shift_name'],
                    'start_time' => $shifts['start_time'],
                    'end_time' => $shifts['end_time']
                 );
            }
            foreach ($pos_point_setting as $pos_point) {
                $menu_options = array(
                    'default_menu' => (int) $pos_point['show_default_menu'],
                    'custom_menu' => (int) $pos_point['show_custom_menu'],
                    'shop' => (int) $pos_point['show_shop'],
                    'gvb' => (int) $pos_point['show_gvb'],
                    'prioticket' => (int) $pos_point['show_prioticket'],
                    'voucher' => (int) $pos_point['show_voucher'],
                );
                $printer_settings = array(
                    'auto_print_receipt' => (int) $pos_point['auto_print_receipt'],
                    'auto_print_ticket' => (int) $pos_point['auto_print_ticket'],
                    'email' => (int) $pos_point['scan_pass_email'],
                    'scan' => (int) $pos_point['scan_pass_scan'],
                );
                $payment_options = array(
                    'cash' => (int) $pos_point['show_cash'],
                    'bill' => (int) $pos_point['show_hotel_bill'],
                    'card_terminal' => (int) $pos_point['show_card'],
                    'credit_card' => (int) $pos_point['show_credit_card'],
                    'add_to_prioticket' => (int) $pos_point['add_to_prioticket'],
                    'show_voucher_payment' => (int) $pos_point['show_voucher_payment'],
                );
                $receipt_settings = array(
                    'device' => (int)$pos_point['selected_device'],
                    'receipt_type' => (int)$pos_point['selected_receipt_type'],
                    'receipt_template' => (int)$pos_point['selected_receipt_template'],
                    'vat' => (int)$pos_point['selected_vat_type'],
                    'header_footer' => (int)$pos_point['header_type'],
                );
                $general_settings = array(
                    'boca_ip' => $pos_point['boca_ip'],
                    'default_start_amount' => (float) $pos_point['default_start_amount'],
                    'financier_email_address' => $pos_point['financier_email_address'],
                    'ticket_show_style' => 1,
                    'cashier_shifts' => $shifts_details[$pos_point['pos_point_id']],
                    'ticket_show_style' =>  (int)$pos_point['ticket_show_style'],
                    'scan_per_ticket'   =>  (int)$pos_point['scan_per_ticket']
                );
                $cancellation_upon_scan = unserialize($pos_point['cancellation_upon_scan']);
                $allow_cancellation_upon_scan = array();
                foreach($cancellation_upon_scan as $cancel_values) {
                    if($cancel_values['user_role'] == 'admin') {
                        $user_role = 3;
                    } else if($cancel_values['user_role'] == 'supervisor') {
                        $user_role = 2;
                    } else if($cancel_values['user_role'] == 'cashier') {
                        $user_role = 1;
                    } else if($cancel_values['user_role'] == 'mpm') {
                        $user_role = 5;
                    } else if($cancel_values['user_role'] == 'streetseller') {
                        $user_role = 6;
                    }
                    $allow_cancellation_upon_scan[] = array(
                        'user_role' => (int)$user_role,
                        'allow_cancellation' => (int)$cancel_values['allow_cancellation'],
                        'cancel_time_limit' => $cancel_values['cancel_time_limit'],
                    );
                }
                $pos_point_settings[$pos_point['hotel_id']][] = array(
                    'pos_point_id' => (int) $pos_point['pos_point_id'],
                    'pos_point_name' => $pos_point['pos_point_name'],
                    'email_address' => $pos_point['financier_email_address'],
                    'device_id' => (string) ($pos_point['device_id']),
                    'location_code' => $pos_point['location_code'],
                    'menu_options' => $menu_options,
                    'printer_settings' => $printer_settings,
                    'payment_options' => $payment_options,
                    'receipt_settings' => $receipt_settings,
                    'general_settings' => $general_settings,
                    'allow_cancellation_upon_scan' => $allow_cancellation_upon_scan,
                );
            }
        }

        /* Get ticket, ticket types and extra options details */
        if (empty($array_keys) || in_array('ticket', $array_keys)) {
            $this->primarydb->db->select("mec.*");
            $this->primarydb->db->from("modeventcontent mec");
            $this->primarydb->db->join("ticketpriceschedule tps", "mec.mec_id = tps.ticket_id");
            if ($ticket_id != '') {
                $this->primarydb->db->where("mec.mec_id", $ticket_id);
            } else {
                
                $this->primarydb->db->where("mec.active", '1');
                $this->primarydb->db->where("mec.deleted", '0');
                $this->primarydb->db->where('(mec.is_commission_assigned = "1" or tps.is_commission_assigned = "1")');
                $this->primarydb->db->where("mec.startDate <", strtotime(date('Y-m-d')));
                $this->primarydb->db->where("mec.endDate >", strtotime(date('Y-m-d')));
            }
            $ticket_details = $this->primarydb->db->get()->result_array();
            if ($ticket_id != '') {
                $extra_option_details = $this->pos_model->find('ticket_extra_options', array('select' => '*', 'where' => 'ticket_id = "' . $ticket_id . '"'));
            } else {
                $extra_option_details = $this->pos_model->find('ticket_extra_options', array('select' => '*'));
            }
            foreach ($extra_option_details as $options) {
                $options['description'] = unserialize($options['description']);
                $options['amount'] = unserialize($options['amount']);
                $options['net_amount'] = unserialize($options['net_amount']);
                $options['tax'] = unserialize($options['tax']);
                $data = array();
                foreach ($options['description'] as $key => $val) {
                    $data['extra_option_id'] = (int) $options['ticket_extra_option_id'];
                    $data['main_description'] = $options['main_description'];
                    $data['optional'] = (int) $options['optional_vs_mandatory'];
                    $data['single'] = (int) $options['single_vs_multiple'];
                    $tax = explode('_', $options['tax'][$key]);
                    $data['options'][] = array(
                        'description' => $val,
                        'price' => (float) $options['amount'][$key],
                        'net_price' => (float) round($options['net_amount'][$key], 2),
                        'tax' => (int) $tax[1],
                    );
                }
                $option_details[$options['ticket_id']][$options['schedule_id']][] = $data;
            }
            if ($ticket_id != '') {
                $ticket_type_details = $this->pos_model->find('ticketpriceschedule', array('select' => '*', 'where' => 'ticket_id = "' . $ticket_id . '"'));
            } else {
                $ticket_type_details = $this->pos_model->find('ticketpriceschedule', array('select' => '*'));
            }
            foreach ($ticket_type_details as $ticket_type) {
                $type_text = ($ticket_type['ticketType'] == 1) ? 'adult' : 'child';
                $net_price = $ticket_type['pricetext'] - round(($ticket_type['pricetext'] * $ticket_type['ticket_tax_value']) / (100 + $ticket_type['ticket_tax_value']), 2);
                $type_details[$ticket_type['ticket_id']][] = array(
                    'start_date' => ($ticket_type['start_date'] != '') ? date('Y-m-d H:i:s', $ticket_type['start_date']) : '',
                    'end_date' => ($ticket_type['end_date'] != '') ? date('Y-m-d H:i:s', $ticket_type['end_date']) : '',
                    'age_range' => $ticket_type['agefrom'] . '-' . $ticket_type['ageto'],
                    'price' => (float) $ticket_type['pricetext'],
                    'tax' => (float) $ticket_type['ticket_tax_value'],
                    'net_price' => (float) $net_price,
                    'discount_type' => (int) $ticket_type['discountType'],
                    'discount' => ($ticket_type['discountType'] == "2") ? (float)$ticket_type['discount'] : (float)$ticket_type['saveamount'],
                    'price_after_discount' => ($ticket_type['newPrice'] != 0) ? (float) $ticket_type['newPrice'] : (float) $ticket_type['pricetext'],
                    'net_price_after_discount' => (float) $ticket_type['ticket_net_price'],
                    'apply_service_tax' => (int) $ticket_type['apply_service_tax'],
                    'tps_id' => (int) $ticket_type['id'],
                    'type' => (int) $ticket_type['ticketType'],
                    'type_text' => $type_text,
                    'extra_options' => !empty($option_details[$ticket_type['ticket_id']][$ticket_type['id']]) ? $option_details[$ticket_type['ticket_id']][$ticket_type['id']] : array()
                );
            }
            if ($ticket_id != '') {
                $ticket_locations = $this->pos_model->find('rel_targetvalidcities', array('select' => '*', 'where' => 'module_item_id = "' . $ticket_id . '"'));
            } else {
                $ticket_locations = $this->pos_model->find('rel_targetvalidcities', array('select' => '*'));
            }
            foreach ($ticket_locations as $location) {
                $ticket_location[$location['module_item_id']][] = array(
                    'target_location' => $location['targetlocation'],
                    'target_city' => $location['targetcity'],
                    'target_country' => $location['targetcountry'],
                    'latitude' => (float) $location['latitude'],
                    'longitude' => (float) $location['longitude'],
                );
            }
            
            foreach ($ticket_details as $ticket) {
                $ticket_images = array();

                $image = base_url() . 'qrcodes/images/tickets/' . $ticket['cod_id'] . '/large_thumbnails/' . trim($ticket['eventImage']);
                $ticket_images[] = $image;
                $more_images = explode(',', $ticket['more_images']);
                foreach ($more_images as $image) {
                    if ($image != '') {
                        $image = base_url() . 'qrcodes/images/tickets/' . $ticket['cod_id'] . '/large_thumbnails/' . trim($image);
                        $ticket_images[] = $image;
                    }
                }
                $third_party_details = array();
                if ($ticket['iticket_product_id'] != '' && $ticket['iticket_location_code'] != '') {
                    $third_party = 1;
                    $third_party_details = array(
                        'third_party' => (int) 1,
                        'iticket_product_id' => $ticket['iticket_product_id'],
                        'iticket_location_code' => $ticket['iticket_location_code']
                    );
                } else if (($ticket['rezgo_ticket_id'] != '' && $ticket['rezgo_ticket_id'] != 0) || ($ticket['rezgo_id'] != '' && $ticket['rezgo_id'] != 0)) {
                    $third_party = 2;
                    $third_party_details = array(
                        'third_party' => (int) 2,
                        'rezgo_ticket_id' => $tickets['rezgo_ticket_id'],
                        'rezgo_id' => $tickets['rezgo_id'],
                        'rezgo_key' => $tickets['rezgo_key']
                    );
                } else if (($ticket['tourcms_tour_id'] != '' && $ticket['tourcms_tour_id'] != 0) || $ticket['tourcms_channel_id'] != '' && $ticket['tourcms_channel_id'] != 0) {
                    $third_party = 3;
                    $third_party_details = array(
                        'third_party' => (int) 3,
                        'tourcms_tour_id' => $tickets['tourcms_tour_id'],
                        'tourcms_channel_id' => $tickets['tourcms_channel_id']
                    );
                } else if ($ticket['is_gt_ticket'] == 1) {
                    $third_party = 4;
                    $third_party_details = array(
                        'third_party' => (int) 4
                    );
                } else {
                    $third_party = 0;
                }


                $response['ticket']['details'][$ticket['mec_id']] = array(
                    'book_size_max' => (int)20,
                    'book_size_min' => (int)1,
                    'booking_start_date' => $ticket['startDate'] != '' ? date('Y-m-d', $ticket['startDate']) : '',
                    'category_id' => (int) $ticket['cat_id'],
                    'currency' => 'EUR',
                    'duration' => str_replace('-', ' ', $ticket['duration']),
                    'end_date' => ($ticket['endDate'] != '') ? date('Y-m-d', $ticket['endDate']) : '',
                    'highlights' => ($ticket['highlights'] != '') ? explode('~~~', $ticket['highlights']) : array(),
                    'images' => $ticket_images,
                    'is_combi_ticket_allowed' => ($supplier_settings[$ticket['cod_id']]['is_combi_ticket_allowed']) ? (int) $supplier_settings[$ticket['cod_id']]['is_combi_ticket_allowed'] : (int) 0,
                    'is_booking_combi_ticket_allowed' => ($supplier_settings[$ticket['cod_id']]['is_booking_combi_ticket_allowed']) ? (int) $supplier_settings[$ticket['cod_id']]['is_booking_combi_ticket_allowed'] : (int) 0,
                    'ticket_class' => (int) $ticket['is_reservation'],
                    'museum_id' => (int) $ticket['cod_id'],
                    'short_description' => $ticket['shortDesc'],
                    'start_date' => ($ticket['startDate'] != '') ? date('Y-m-d', $ticket['startDate']) : '',
                    'ticket_id' => (int) $ticket['mec_id'],
                    'title' => $ticket['postingEventTitle'],
                    'venue_name' => $supplier_settings[$ticket['cod_id']]['company'],
                    'guest_notification' => $ticket['guest_notification'],
                    'third_party_ticket' => ($third_party != 0) ? (int) '1' : (int) '0',
                    'pass_type' => (int)$ticket['barcode_type'] + 1,
                    'scan_information' => $ticket['scan_info'],
                    'alert_pass_count' => (int) $ticket['alert_pass_count'],
                    'alert_capacity_count' => (int) $ticket['alert_capacity_count'],
                    'offline_capacity_count' => (int) 20,
                    'deleted' => (int) $ticket['deleted'],
                    'price' => (float) $ticket['ticketPrice'],
                    'totalclaim' => ($ticket['totalclaim']) ? (int) $ticket['totalclaim'] : (int) 0,
                    'ticket_location' => $ticket_location[$ticket['mec_id']],
                    'ticketextrainfo' => $ticket['extra_text_field'],
                    'is_extra_option' => (int) $ticket['is_extra_options'],
                    'checkin_points_label' => ($ticket['checkin_points_label']) ? $ticket['checkin_points_label'] : '',
                    'checkin_points_mandatory' => (int) $ticket['checkin_points_mandatory'],
                    'checkin_points' => $ticket['checkin_points'] != '' ? explode(',', $ticket['checkin_points']) : array(),
                    'nav_item_no' => $ticket['nav_item_no'],
                    'ticket_type_details' => $type_details[$ticket['mec_id']],
                    'per_ticket_extra_options' => !empty($option_details[$ticket['mec_id']][0]) ? $option_details[$ticket['mec_id']][0] : array(),
                    'third_party_details' => $third_party_details
                );

                if ($ticket['is_reservation'] == '1') {
                    
                    $headers = $this->all_headers_new('SYNC_firebase' , $ticket['mec_id']);

                    $request = json_encode(array("ticket_id" => $ticket['mec_id'], "ticket_class" => '2', "from_date" => date('Y-m-d'), "to_date" => date('Y-m-d', strtotime('+5 days'))));

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
                            $is_active = false;
                            if( $timeslot['is_active'] == 1 && $timeslot['is_active_slot'] == 1 ) {
                                $is_active = true;
                            }

                            if ($timeslot['from_time'] !== '0') {
                                $timeslots[] = array(
                                    'from_time' => $timeslot['from_time'],
                                    'to_time' => $timeslot['to_time'],
                                    'type' => $timeslot['timeslot_type'],
                                    'is_active' => $is_active,
                                    'bookings' => (int) $timeslot['bookings'],
                                    'total_capacity' => (int) $total_capacity
                                );
                            }
                        }
                        if (!empty($timeslots)) {
                            $standard_hours[$hours['date']] = array(
                                'timeslots' => $timeslots
                            );
                        }
                    }
                    if (!empty($standard_hours)) {
                        $response['ticket']['availabilities'][$ticket['mec_id']] = $standard_hours;
                    }
                }
            }
        }

        /* Set all details of distributors under distributor section */
        $response['distributor']['details'] = $details;
        $response['distributor']['settings'] = $settings;
        $response['distributor']['tickets'] = $hotel_tickets;
        $response['distributor']['pos_point_settings'] = $pos_point_settings;
        $response['distributor']['cashier_roles'] = $hotel_cashiers;
        $response['suppliers'] = $supplier_details;
        $response['distributor']['guides'] = $all_guide_codes; 
        /* Filter the json array according to the requested node */


        if (!empty($array_keys)) {
            foreach ($array_keys as $key) {
                $response = $response[$key];
            }
        }

        if ($exit == 1) {
            echo '<pre>';
            print_r($response);
            exit;
        }
        if ($exit == 2) {
            $file = 'venueapp_' . date('Y-m-d_H:i:s');
            header('Content-disposition: attachment; filename=' . $file . '.json');
            header('Content-type: application/json');
            echo json_encode($response);
            exit;
        }

        return json_encode($response);
    }
    /* #endregion To get JSON details to sync on firebase.*/
    
    /* #region function To insert data in VT table only  */
    /**
     * correct_visitor_tickets_record_model
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $pass_no
     * @param  mixed $prepaid_ticket_id
     * @param  mixed $hto_id
     * @param  mixed $action_performed
     * @param  mixed $db_type
     * @return void
     * @author Pankaj Kumar<pankajk.dev@outlook.com> on July, 2018
     */
    function correct_visitor_tickets_record_model( $visitor_group_no, $pass_no = '', $prepaid_ticket_id = '', $hto_id = '', $action_performed = '', $db_type = '1' ) 
    {
        
        if ($db_type == '1') {
            $db = $this->secondarydb->db;
        } else {
            $db = $this->db;
        }
        $this->secondarydb->db->select('*');
        $this->secondarydb->db->from('hotel_ticket_overview');
        // to get the data bases on pass number or vt number
        if(!empty($hto_id) ) {
            $this->secondarydb->db->where('id', $hto_id);
        } else {
            if(!empty($pass_no) ) {
                if(!strstr($pass_no, 'http') && strlen($pass_no) == 6) {
                    if(strstr($pass_no, '-')) {
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
                $hotel_data = $value;
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
            $this->secondarydb->db->select('*');
            $this->secondarydb->db->from('prepaid_tickets');
            // to get the data bases on pass number or vt number
            if(!empty($prepaid_ticket_id) ) {
                $this->secondarydb->db->where('prepaid_ticket_id', $prepaid_ticket_id);
            } else {
                if(!empty($ticket_id)) {
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
                    if($action_performed == 3 ) {
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
                    $confirm_data['is_iticket_product'] =  $value->is_iticket_product;
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
                    if('2018-07-12' > date('Y-m-d', strtotime($value->created_date_time)) ) {                        
                        $visitor_group_no = round(microtime(true) * 1000); // Return the current Unix timestamp with microseconds
                        $visitor_group_no = $visitor_group_no . '' . rand(10, 99);
                        $confirm_data['prepaid_ticket_id'] = $visitor_group_no.rand(101, 999);
                    }
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
                } else {
                    $payment_method = 'Others'; //   others
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
                    if(!empty($action_performed) ) {
                        $insert_service_data['action_performed'] = '0,'.$action_performed;
                    } else {
                        $insert_service_data['action_performed'] = '0';
                    }

                    if($action_performed == 3 ) {
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
                    } else {
                        $payment_method = 'Others'; //   others
                    }
                    $extra_services = $result->result_array();
                    $taxes = array();
                    $eoc = 800;
                    foreach ($extra_services as $service) {                        
                        $service['used'] = '1';
                        $service['scanned_at'] = strtotime(gmdate("Y-m-d H:i:s"));                        
                        // If quantity of service is more than one then we add multiple transactions for financials page
                        if (!in_array($service['tax'], $taxes)) {
                            $ticket_tax_id = $this->common_model->getSingleFieldValueFromTable('id', 'store_taxes', array('tax_value' => $service['tax']));
                            $taxes[$service['tax']] = $ticket_tax_id;
                        } else {
                            $ticket_tax_id = $taxes[$service['tax']];
                        }

                        $ticket_tax_value = $service['tax'];

                        // Correction of tax in VT
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
                            if(!empty($action_performed) ) {
                                $service_data_for_visitor['action_performed'] = '0,'.$action_performed;
                            } else {
                                $service_data_for_visitor['action_performed'] = $confirm_data['order_confirm_date'];
                            }
                            $service_data_for_visitor['updated_at'] = gmdate('Y-m-d H:i:s');
                            $db->insert("visitor_tickets", $service_data_for_visitor);
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
                            if(!empty($action_performed) ) {
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
    /* #endregion function To insert data in VT table only */

    /**
     * @name: update_cashier_register
     * @purpose: to update the cashier register in case of inapp pos cashier
     * @where: It is called from the Adyen in-app pos
     * @params:
     * $amount : Total order amount
     * $payment_type : cash or  card
     * $user_id : Logged in user id
     * $pos_point_id  : Selected pos point
     * @return: nothing, it redirects to recipt of user
     * @created by: Davinder singh <davinder.intersoft@gmail.com> on Apr 22, 2016
     */
    function update_cashier_register($amount = '', $payment_type = '', $user_id = '', $pos_point_id = '', $date = '', $payment_method = '', $visitor_group_no, $hotel_id) {
        if(trim($user_id) == '') {
            $user_id = 1;
        }
        if ($amount && $amount != '' && $amount != 'NULL' && $amount != 'NaN') {
            if (strstr($amount, ',')) {
                $amount = str_replace(".", "", $amount);
                $amount = str_replace(",", ".", $amount);
            }
            if (strstr($amount, ';')) {
                $amount = str_replace(";", ".", $amount);
            }
            if (strstr($amount, "'")) {
                $amount = str_replace("'", ".", $amount);
            }
            $query = 'update cashier_register set ';
            if ($payment_type == 'cash') {
                $query .= ' sale_by_cash = sale_by_cash + ' . $amount;
            }
            if ($payment_type == 'cash_redeem') {
                $query .= ' sale_by_cash_redeem = sale_by_cash_redeem + ' . $amount;
            }
            if ($payment_type == 'card') {
                $query .= ' sale_by_card = sale_by_card + ' . $amount;
            }
            if ($payment_type == 'card_redeem') {
                $query .= ' sale_by_card_redeem = sale_by_card_redeem + ' . $amount;
            }
            if ($payment_type == 'refund') {
                $query .= ' refunded_cash_amount = refunded_cash_amount + ' . $amount;
            }
            // To insert voucher payment in cashier register.
            if ($payment_type == 'voucher') {
                $query .= ' sale_by_voucher = sale_by_voucher + ' . $amount;
            }
            if ($payment_type == 'voucher_redeem') {
                $query .= ' sale_by_voucher_redeem = sale_by_voucher_redeem + ' . $amount;
            }
            if ($payment_type == 'manual') {
                if ($payment_method == '1') {
                    $query .= ' manual_card_sale_amount = manual_card_sale_amount + ' . $amount;
                } else {
                    $query .= ' manual_cash_sale_amount = manual_cash_sale_amount + ' . $amount;
                }
            }
            if ($payment_type == 'deposit') {
                $query .= ' manual_deposit = manual_deposit + ' . $amount;
            }
            if ($payment_type == 'direct') {
                $query .= ' sale_by_direct_payment = sale_by_direct_payment + ' . $amount;
            }
            if ($date == '') {
                $date = date('Y-m-d');
            }
            $query .= ', total_sale  = sale_by_cash + sale_by_card + sale_by_voucher + sale_by_direct_payment + manual_card_sale_amount +manual_cash_sale_amount - refunded_cash_amount + sale_by_cash_redeem + sale_by_card_redeem + sale_by_voucher_redeem, closing_cash_balance = opening_cash_balance + sale_by_cash + manual_cash_sale_amount- refunded_cash_amount  + sale_by_cash_redeem, closing_card_balance = sale_by_card + manual_card_sale_amount + sale_by_card_redeem, closing_direct_payment_balance = sale_by_direct_payment, total_closing_balance = closing_cash_balance + closing_card_balance + sale_by_voucher - refunded_cash_amount, modified_at = "' . gmdate("Y-m-d H:i:s") . '" where created_at like "%' . $date . '%" and cashier_id = ' . $user_id . ' and pos_point_id = ' . $pos_point_id;
            $this->db->query($query);           
            $this->CreateLog('cashier_register_records.php', $query . '-->' . $visitor_group_no, array("effected" => $this->db->affected_rows()));
        } else {
            $this->CreateLog('cashier_register_records.php', '--> ISSUE here : ' . $query . '-->' . $visitor_group_no, array());
        }
    }
    /* #endregion to update the cashier register in case of inapp pos cashier*/

    /*#region Contact Add/Update*/ 
    public function contact_update(array $contacts, array $order_contacts = null)
    {  
        $this->insert_batch('guest_contacts', [$contacts], 1);
        /* to insert data in RDS realtime */
        if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
            $this->insert_batch('guest_contacts', [$contacts], 4);
        }
        if (count($order_contacts) > 0) {
            $this->insert_batch('order_contacts', $order_contacts, 1);
            if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_INSERT_QUEUE == '0')) {
                $this->insert_batch('order_contacts', $order_contacts, 4);
            }
        }
    }
    /*#endregion */

    /* #endregion to save order flag details.*/
    
    /**
     * @name    : update_payment_detaills()     
     * @Purpose : To create array for insertion in order_payments_details table
     * @return  : void
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on sept 03, 2020
     */

    function update_payment_detaills($data = array(), $refund_entry = 0) {
        $this->createLog('paymnts.php', 'step 1 paymnts', array("req data" => json_encode($data)), "10");
        $version_update = [];
        if (isset($data['cashier_id']) && $data['cashier_id'] != "" && $data['cashier_id'] != NULL) {
            $users_data = $this->find('users', array('select' => 'uname, fname, lname', 'where' => 'id = "' . $data['cashier_id'] . '"'));
        }
        $data['visitor_group_no'] = is_array($data['visitor_group_no']) ? $data['visitor_group_no'] : array($data['visitor_group_no']);
        $payment_details_from_db = $this->secondarydb->rodb->select('*')->from("order_payment_details")->where_in('visitor_group_no', $data['visitor_group_no'])->order_by('created_at', 'DESC')->get()->result_array();
        
        $this->createLog('paymnts.php', 'step 2 paymnts', array("payment_details_from_db" => json_encode($payment_details_from_db)), "10");
        if(!empty($payment_details_from_db)) {
            $existing_entry = array();
            $lastRefundedAmount = $maxPrice = 0;
            foreach ($payment_details_from_db as $db_data) {
                if($db_data['status'] == "2" && $db_data['is_active'] == 0) { //for inactive records
                    $payment_details[$db_data['visitor_group_no']] = $db_data;
                }
                if($refund_entry == 1 && $db_data['type'] == "2") { //existing refund entry in DB
                    $lastRefundedAmount = $lastRefundedAmount + $db_data['order_amount'];
                }
                if($db_data['status'] == "1" && $db_data['is_active'] == 1) {
                    if ($db_data['order_total'] > $maxPrice) {
                        $existing_entry[$db_data['visitor_group_no']] = $db_data;
                        $maxPrice = $db_data['order_total'];
                    }
                }
            }
            $vgns = $data['visitor_group_no'];
            unset($data['visitor_group_no']);
            foreach($vgns as $vgn) {
                $data['visitor_group_no'] = $vgn;
                $updated_payment_details = array();
                if(in_array($vgn, array_keys($payment_details))) { //for the vgn having entry in table with inactive status
                    $updated_payment_details = array_merge($payment_details[$vgn], $data);
                    $updated_payment_details['cashier_email'] = ($users_data[0]['uname'] != "" && $users_data[0]['uname'] != Null) ? $users_data[0]['uname'] : "";
                    $updated_payment_details['cashier_name'] = ($data['cashier_name'] != "" && $data['cashier_name'] != Null) ? $data['cashier_name'] : $users_data[0]['fname'] . " " . $users_data[0]['lname'];
                    if(!isset($data['order_total']) && isset($data['total'])) {
                        $updated_payment_details['order_total'] = $data['total'];
                    }
                    if(!isset($data['order_amount']) && isset($data['amount'])) {
                        $updated_payment_details['order_amount'] = $data['amount'];
                    }
                    $updated_payment_details['cashier_name'] = ($data['cashier_name'] != "" && $data['cashier_name'] != Null) ? $data['cashier_name'] : $users_data[0]['fname'] . " " . $users_data[0]['lname'];
                    $updated_payment_details['is_active'] = "1";
                    $this->createLog('paymnts.php', 'update OPD for vgn ' . $vgn, array("updated_payment_details" => json_encode($updated_payment_details)), "10");
                    $this->order_process_model->update_payments($updated_payment_details);
                } else {
                    if($refund_entry) { // refund case
                        $data = array_merge($existing_entry[$vgn], $data);
                        $data['original_payment_id'] = $data['id'];
                        $version_update['is_amend_order_version_update'] = 1;
                    }
                    $data['id'] = $this->generate_uuid();
                    $data['platform_id'] = "1";
                    $data['cashier_email'] = ($users_data[0]['uname'] != "" && $users_data[0]['uname'] != Null) ? $users_data[0]['uname'] : "";
                    $data['cashier_name'] = ($data['cashier_name'] != "" && $data['cashier_name'] != Null) ? $data['cashier_name'] : $users_data[0]['fname'] . " " . $users_data[0]['lname'];
                    if((!isset($data['order_amount']) || $refund_entry) && isset($data['amount'])) {
                        $data['order_amount'] = $data['amount'];
                        $data['amount'] = $data['amount'];

                    }
                    if((!isset($data['order_total']) || $refund_entry) && isset($data['total'])) {
                        if(in_array($vgn, array_keys($existing_entry))) { //for the vgn having entry in table
                            if($refund_entry) { // refund case
                                $data['order_total'] = $existing_entry[$vgn]['order_total'] - ($lastRefundedAmount + $data['total']) ;
                                $data['total'] = $data['order_total'];
                            } else { // add_booking case
                                $data['order_total'] = $existing_entry[$vgn]['order_total'] + $data['total'];
                                $data['total'] = $existing_entry[$vgn]['total'] + $data['total'];    
                            }
                        } else {
                            $data['order_total'] = $data['total'];
                        }
                    }
                    $this->createLog('paymnts.php', 'add new entry for existing vgn refund 1 ' . $vgn, array("new entry data-" => json_encode($data)), "10");
                    $this->order_process_model->add_payments($data,'', $version_update);
                }
            }
        } else { //for the vgns not having entry in table
            $this->createLog('paymnts.php', 'for the vgns not having entry in table '. $vgn, array("entry not exist in db" => $vgn ), "10");
            $vgns = $data['visitor_group_no'];
            unset($data['visitor_group_no']);
            foreach($vgns as $vgn) {
                $data['visitor_group_no'] = $vgn;
                $data['id'] = $this->generate_uuid();
                $data['platform_id'] = "1";
                $data['cashier_email'] = ($users_data[0]['uname'] != "" && $users_data[0]['uname'] != Null) ? $users_data[0]['uname'] : "";
                $data['cashier_name'] = ($data['cashier_name'] != "" && $data['cashier_name'] != Null) ? $data['cashier_name'] : $users_data[0]['fname'] . " " . $users_data[0]['lname'];
                if(!isset($data['order_total']) && isset($data['total'])) {
                    $data['order_total'] = $data['total'];
                }
                if(!isset($data['order_amount']) && isset($data['amount'])) {
                    $data['order_amount'] = $data['amount'];
                }
                $this->createLog('paymnts.php', 'add new entry for new vgn 2 '. $vgn, array("new entry data" => json_encode($data)), "10");
                $this->order_process_model->add_payments($data, '', $version_update);
            }
        }
    }
    /* #endregion to save payment details.*/
    /* #endregion Eoc of model pos*/
}
