<?php

namespace Prio\Traits\V1\Models;

trait TMuseum_model {
    var $loginUserId;
    var $userType;
    var $base_url;
    var $uname;

    function __construct() {
        parent::__construct();
    }

    function makeInstantTicketPaymentv1($passNo = '', $ticketAmt = '', $currencyCode = '', $hotel_id = '', $ticketId = '', $activation_method = '', $ticket_price_data = array(), $extra_options_amount = 0) {
        // Defined in common functions
        $expedia_hotel_list = explode(',',Expedia_Hotel_List);    
        $record = $this->getInitialPaymentDetailv1($passNo, 'passNo', $hotel_id); // Defined in common functions
        $pspReference     = $record->pspReference;
        $shopperStatement = $record->shopperStatement;
        $paymentMethod    = $record->paymentMethod;
        $is_block         = $record->is_block;
        $shopperReference = $record->shopperReference;
        $authorisedAmt    = $record->initial_authorised_amount;
        $hotelDetail      = $this->common_model->seldealeventimage('qr_codes', 'cod_id', $hotel_id);
        $check_in_option  = $hotelDetail->check_in_option;
        // check if visitor has already made initial payment
        if ($pspReference != '') {
            $visitor_group_no = $record->visitor_group_no;
            $fixed_amount     = $record->fixed_amount;
            $variable_amount  = $record->variable_amount;
            $calculated_on    = $record->calculated_on;
            $tax_value        = $record->tax_value;
                
            if ($calculated_on == '1') {// per ticket
                // Defined in common functions
                if(!empty($ticket_price_data)) {
                    foreach($ticket_price_data as $data) {
                        for($i = 0; $i < $data['quantity']; $i++) {
                            $ticketAmtarray   = $this->common_model->addFixedVariableTaxToAmount($data['price'], $fixed_amount, $variable_amount, $tax_value);
                            $Amtwithtax       = $ticketAmtarray['ticketAmt'];
                            $totalFixedAmt    = $ticketAmtarray['fixed_amount'];
                            $totalVariableAmt = $ticketAmtarray['variable_amount'];
                            $totalAmtToPay+=$Amtwithtax;
                        }
                    }                    
                    if($extra_options_amount > 0) {
                        $ticketAmtarray   = $this->common_model->addFixedVariableTaxToAmount($extra_options_amount, 0, $variable_amount, $tax_value);
                        $Amtwithtax       = $ticketAmtarray['ticketAmt'];
                        $totalFixedAmt    = $ticketAmtarray['fixed_amount'];
                        $totalVariableAmt = $ticketAmtarray['variable_amount'];
                        $totalAmtToPay+=$Amtwithtax;
                    }
                } else {
                    $ticketAmtarray   = $this->common_model->addFixedVariableTaxToAmount($ticketAmt, $fixed_amount, $variable_amount, $tax_value);
                    $Amtwithtax       = $ticketAmtarray['ticketAmt'];
                    $totalFixedAmt    = $ticketAmtarray['fixed_amount'];
                    $totalVariableAmt = $ticketAmtarray['variable_amount'];
                    $totalAmtToPay    = $Amtwithtax;
                }
            
            } else {
                // Defined in common functions
                if(empty($ticket_price_data)) {
                    $tickettotalAmt = $ticketAmt;
                } else {
                    foreach($ticket_price_data as $data) {
                        for($i = 0; $i < $data['quantity']; $i++) {
                            $tickettotalAmt +=$data['price'];
                        }
                    }
                    if($extra_options_amount > 0) {
                        $tickettotalAmt+=$extra_options_amount;
                    }
                }        
                $ticketAmtarray = $this->common_model->addFixedVariableTaxToAmount($tickettotalAmt, $fixed_amount, $variable_amount, $tax_value);
                $totalAmtToPay  = $ticketAmtarray['ticketAmt'];
                $Amtwithtax     = $ticketAmtarray['ticketAmt'];
                $totalFixedAmt  = $ticketAmtarray['fixed_amount'];
                $totalVariableAmt = $ticketAmtarray['variable_amount'];
            }
            $cmpny                               = $this->common_model->companyName($hotel_id);
            $is_credit_card_fee_charged_to_guest = $cmpny->is_credit_card_fee_charged_to_guest;
            $initial_payment_charged_to_guest    = $cmpny->initial_payment_charged_to_guest;
            $hotel_name                          = $cmpny->company;
            if ($is_credit_card_fee_charged_to_guest == '0') {
                if (empty($ticket_price_data)) {
                    $totalAmtToPay = $ticketAmt;
                }
            }
            if ($authorisedAmt > $totalAmtToPay || $check_in_option == '2' || $check_in_option == '3' || $activation_method == 1 || $activation_method == 0) { // ticket amt to pay should be less or equal to authorized amount || card was activated via POS with cc prepaid tickets
                $servicedata  = $this->common_model->getGenericServiceValues(SERVICE_NAME);
                $currencyCode = $servicedata->currency;
                if (($check_in_option == "1" || $check_in_option == "2") && $initial_payment_charged_to_guest == 1) {
                    $initial_payment = $this->common_model->getSingleFieldValueFromTable('initial_payment_done', 'hotel_ticket_overview', array('pspReference' => $pspReference));
                    if ($initial_payment == "0") {
                    $updates_initial = 'update hotel_ticket_overview set initial_payment_done="1" where pspReference = "' . $pspReference . '"';
                    $init_price    = 0.1;
                    $initial_price = ($init_price + (($init_price * $tax_value) / 100));
                    $initial_price = round($initial_price, 2);

                    if ($is_credit_card_fee_charged_to_guest == '1') {
                        $totalAmtToPay = $totalAmtToPay + $initial_price;
                    }
                    $initial_guest = "1";
                    } else {
                    $initial_guest   = "0";
                    $updates_initial = '';
                    }
                } else {
                    $initial_guest   = "0";
                    $updates_initial = '';
                }
                $totalAmtToPay = round($totalAmtToPay, 2);
                if ($totalAmtToPay > 0) {
                    $parentPassNo = $record->parentPassNo;
                            if(strpos($parentPassNo, 'http') != false){
                                $merchantReference   = trim(substr($parentPassNo, -PASSLENGTH)) . '-' . trim($hotel_name);
                            } else {
                                $merchantReference   = 'MPOS-'.$visitor_group_no.'-'.$parentPassNo;
                            }
                    $merchantAccountCode = $record->merchantAccountCode != '' ? $record->merchantAccountCode : MERCHANTCODE;
                    $result = $this->common_model->auth($ref = "LATEST", $totalAmtToPay, $merchantReference, $shopperReference, $merchantAccountCode, $currencyCode, $parentPassNo, $shopperStatement);
                    $responseAuthorized   = $result->resultCode;
                    $responsePspReference = $result->pspReference;
                    if ($responseAuthorized == 'Authorised') {
                                $this->common_model->capturePayment($merchantAccountCode, $totalAmtToPay, $responsePspReference, $currencyCode);
                    if ($updates_initial != '') {                            
                                    $this->db->update('hotel_ticket_overview', array('initial_payment_done' => "1"), array('pspReference' => $pspReference));
                                    
                                    if(UPDATE_SECONDARY_DB) {
                                        $this->load->library('Sns');
                                        $sns_object = new \Sns();
                                        require_once 'aws-php-sdk/aws-autoloader.php';
                                        // Load SQS library.
                                        $this->load->library('Sqs');
                                        $sqs_object = new \Sqs();
                                        $sns_messages = array();
                                        $sns_messages[] = $this->db->last_query();
                                        if (!empty($sns_messages)) {                                       
                                            $request_array['db2'] = $sns_messages;
                                            $request_array['visitor_group_no'] = $visitor_group_no;
                                            $request_string = json_encode($request_array);
                                            $aws_message    = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                            $queueUrl = UPDATE_DB_QUEUE_URL;
                                            // This Fn used to send notification with data on AWS panel. 
                                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                            if ($MessageId) {
                                                $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                                            }                
                                        }
                                    }
                    }
                    $authorizedData = array();
                    $authorizedData['ticket_id']             = 0;			
                    $authorizedData['amounttocapture']       = $totalAmtToPay;
                    $authorizedData['currencyCode']          = $currencyCode;
                    $authorizedData['responsePspReference']  = $responsePspReference;
                    $authorizedData['initial_payment']       = $initial_guest;
                    $authorizedData['hotel_name']            = $hotel_name;
                    $authorizedData['hotel_id']              = $hotel_id;
                    $authorizedData['parentPassNo']          = $parentPassNo;
                    $authorizedData['total_fixed_amount']    = $totalFixedAmt;
                    $authorizedData['total_variable_amount'] = $totalVariableAmt;
                    $authorizedData['visitor_group_no']      = $visitor_group_no;
                    $authorizedData['shopperStatement']      = $shopperStatement;
                    $authorizedData['merchantReference']     = $merchantReference;
                    $authorizedData['merchantAccountCode']   = $merchantAccountCode;
                    $authorizedData['shopperReference']      = $shopperReference;
                    $authorizedData['paymentMethod']         = $paymentMethod;
                    $authorizedData['is_block']              = $is_block;
                    $authorizedData['refused']               = "0";
                    $authorizedData['pspReference']          = $pspReference;
                    $this->db->insert("ticket_authorization", $authorizedData); // Insert entry into ticket_authorization table
                                
                    $this->primarydb->db->select('cc_rows_value');
                    $this->primarydb->db->from('prepaid_tickets');
                    $this->primarydb->db->where('visitor_group_no', $record->visitor_group_no);
                    $this->primarydb->db->where('visitor_tickets_id', 0);
                    $this->primarydb->db->limit('1');
                    $prepaid_data = $this->primarydb->db->get();
                    if($prepaid_data->num_rows() > 0) {
                        $cc_result = $prepaid_data->result()[0];
                        $cc_data = unserialize($cc_result->cc_rows_value);
                    } else {
                        $cc_data = array();
                    }
                                
                    // Preapre array to update CC rows value in prepaid Tickets
                    $cc_rows_value_array = array(
                        'Amtwithtax'       => round($Amtwithtax, 2) + (isset($cc_data) ? $cc_data['Amtwithtax'] : 0),
                        'totalamount'      => round($totalAmtToPay, 2) + (isset($cc_data) ? $cc_data['totalamount'] : 0),
                        'totalFixedAmt'    => round($totalFixedAmt, 2) + (isset($cc_data) ? $cc_data['totalFixedAmt'] : 0),
                        'totalVariableAmt' => round($totalVariableAmt, 2) + (isset($cc_data) ? $cc_data['totalVariableAmt'] : 0),
                        'calculated_on'    => $calculated_on,
                        'isprepaid'        => '1'
                    );
                    $cc_rows_value = serialize($cc_rows_value_array);
                    if(!empty($cc_data)) {

                        $sns_prepaid_expedia = array();
                        $this->db->update('prepaid_tickets', array('cc_rows_value' => $cc_rows_value), array('visitor_group_no' => $record->visitor_group_no, 'visitor_tickets_id' => 0));
                        
                        $sns_prepaid_expedia[] = $this->db->last_query();
                        
                        if (in_array($hotel_id, $expedia_hotel_list))
                        {
                            $this->db->update('expedia_prepaid_tickets', array('cc_rows_value' => $cc_rows_value), array('visitor_group_no' => $record->visitor_group_no, 'visitor_tickets_id' => 0));
                            $sns_prepaid_expedia[] = $this->db->last_query();
                        }
                        if(UPDATE_SECONDARY_DB) {
                            $this->load->library('Sns');
                            $sns_object = new \Sns();
                            require_once 'aws-php-sdk/aws-autoloader.php';
                            // Load SQS library.
                            $this->load->library('Sqs');
                            $sqs_object = new \Sqs();
                            $sns_messages = array();
                            $sns_messages = $sns_prepaid_expedia;
                            if (!empty($sns_messages)) {                                       
                                $request_array['db2'] = $sns_messages;
                                $request_array['visitor_group_no'] = $record->visitor_group_no;
                                $request_string = json_encode($request_array);
                                $aws_message    = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                                $queueUrl = UPDATE_DB_QUEUE_URL;
                                // This Fn used to send notification with data on AWS panel. 
                                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                                if ($MessageId) {
                                    $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                                }                
                            }
                        }
                    }
                                
                    $returnedResult['message']  = 'success';
                    $returnedResult['responsePspReference'] = '';
                    $returnedResult['captured'] = '0';
                    $returnedResult['cc_rows']  = array();
                    return $returnedResult;
                    } else {
                    $returnedResult['message'] = 'Your ticket could not be confirmed';
                    $returnedResult['responsePspReference'] = '';
                    $returnedResult['captured'] = '0';
                    return $returnedResult;
                    }
                } else {
                    $returnedResult['message']  = 'success';
                    $returnedResult['responsePspReference'] = '';
                    $returnedResult['captured'] = '0';
                    return $returnedResult;
                }
            } else {
                $returnedResult['message'] = 'Your ticket could not be confirmed';
                $returnedResult['responsePspReference'] = '';
                $returnedResult['captured'] = '0';
            }
        } else {
            $returnedResult['message'] = 'You don\'t have recurring detail.';
            $returnedResult['responsePspReference'] = '';
            $returnedResult['captured'] = '0';
        }
        return $returnedResult;
    }
    
    function makeTicketPaymentv1($passNo = '', $ticketAmt = '', $currencyCode = '', $hotel_id = '', $activation_method = '') {
        // Defined in common functions
        $expedia_hotel_list = explode(',',Expedia_Hotel_List); 
        $record        = $this->getInitialPaymentDetailv1($passNo, 'passNo', $hotel_id);
        $pspReference  = $record->pspReference;
	// check if visitor has already made initial payment
        if ($pspReference != '') {
            $fixed_amount             = $record->fixed_amount;
            $variable_amount          = $record->variable_amount;
            $calculated_on            = $record->calculated_on;
            $tax_value                = $record->tax_value;
            if ($calculated_on == '1') {// per tickets
            $ticketAmtarray   = $this->common_model->addFixedVariableTaxToAmount($ticketAmt, $fixed_amount, $variable_amount, $tax_value);
            $Amtwithtax       = $ticketAmtarray['ticketAmt'];
            $totalFixedAmt    = $ticketAmtarray['fixed_amount'];
            $totalVariableAmt = $ticketAmtarray['variable_amount'];
            $totalAmtToPay    = $Amtwithtax;
            } else {
                    $tickettotalAmt   = $ticketAmt;
            $ticketAmtarray   = $this->common_model->addFixedVariableTaxToAmount($tickettotalAmt, $fixed_amount, $variable_amount, $tax_value);
            $totalAmtToPay    = $ticketAmtarray['ticketAmt'];
            $Amtwithtax       = $ticketAmtarray['ticketAmt'];
            $totalFixedAmt    = $ticketAmtarray['fixed_amount'];
            $totalVariableAmt = $ticketAmtarray['variable_amount'];
            }
                $this->primarydb->db->select('cc_rows_value');
                $this->primarydb->db->from('prepaid_tickets');
                $this->primarydb->db->where('visitor_group_no', $record->visitor_group_no);
                $this->primarydb->db->where('visitor_tickets_id', 0);
                $this->primarydb->db->limit('1');
                $prepaid_data = $this->primarydb->db->get();
                if($prepaid_data->num_rows() > 0) {
                    $cc_result = $prepaid_data->result()[0];
                    $cc_data   = unserialize($cc_result->cc_rows_value);
                } else {
                    $cc_data   = array();
                }

                // Preapre array to update CC rows value in prepaid Tickets
                $cc_rows_value_array = array(
                    'Amtwithtax'       => round($Amtwithtax, 2) + (isset($cc_data) ? $cc_data['Amtwithtax'] : 0),
                    'totalamount'      => round($totalAmtToPay, 2) + (isset($cc_data) ? $cc_data['totalamount'] : 0),
                    'totalFixedAmt'    => round($totalFixedAmt, 2) + (isset($cc_data) ? $cc_data['totalFixedAmt'] : 0),
                    'totalVariableAmt' => round($totalVariableAmt, 2) + (isset($cc_data) ? $cc_data['totalVariableAmt'] : 0),
                    'calculated_on'    => $calculated_on,
                    'isprepaid'        => '0'
                );
                
                $cc_rows_value = serialize($cc_rows_value_array);
                if(!empty($cc_data)) {
                    
                    $sns_prepaid_expedia = array();
                    $this->db->update('prepaid_tickets', array('cc_rows_value' => $cc_rows_value), array('visitor_group_no' => $record->visitor_group_no, 'visitor_tickets_id' => 0));
                    $sns_prepaid_expedia[] = $this->db->last_query();
                    if (in_array($hotel_id, $expedia_hotel_list))
                    {
                        $this->db->update('expedia_prepaid_tickets', array('cc_rows_value' => $cc_rows_value), array('visitor_group_no' => $record->visitor_group_no, 'visitor_tickets_id' => 0));
                        $sns_prepaid_expedia[] = $this->db->last_query();
                    }
                
                    if(UPDATE_SECONDARY_DB) {
                        $this->load->library('Sns');
                        $sns_object = new \Sns();
                        require_once 'aws-php-sdk/aws-autoloader.php';
                        // Load SQS library.
                        $this->load->library('Sqs');
                        $sqs_object = new \Sqs();
                        $sns_messages = array();
                        $sns_messages = $sns_prepaid_expedia;
                        if (!empty($sns_messages)) {                                       
                            $request_array['db2'] = $sns_messages;
                            $request_array['visitor_group_no'] = $record->visitor_group_no;
                            $request_string = json_encode($request_array);
                            $aws_message    = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                            $queueUrl = UPDATE_DB_QUEUE_URL;
                            // This Fn used to send notification with data on AWS panel. 
                            $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                            // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                            if ($MessageId) {
                                $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                            }                
                        }
                    }
                }
                
                $returnedResult['message'] = 'success';
                $returnedResult['responsePspReference'] = '';
                $returnedResult['captured'] = '0';
                $returnedResult['cc_rows']  = $cc_rows_value;
        } else {
            $returnedResult['message'] = 'You don\'t have recurring detail.';
            $returnedResult['responsePspReference'] = '';
            $returnedResult['captured'] = '0';
        }
	    return $returnedResult;
    }
    
    function getInitialPaymentDetailv1($fieldvalue = '', $field = 'passNo', $cod_id = '') 
    {
        /*
         * Because credit card authorization process takes sometime to complete i.e. why at first credit card details are checked for the first time
         * and second time they are not checked
         */
        $query = 'select distinct hto.hotel_id, hto.visitor_group_no, hto.isBillToHotel, hto.visitor_group_no, hto.ticketPaymentFailCount, hto.shopperReference, hto.merchantAccountCode, hto.shopperStatement, hto.paymentMethod, hto.pspReference, hto.parentPassNo, hto.total_variable_amount, hto.total_amount_with_tax, hto.total_amount_without_tax, hto.initial_authorised_amount, hto.is_block, ccf.fixed_amount, ccf.variable_amount, ccf.card_tax_id, ccf.calculated_on, st.tax_value from hotel_ticket_overview hto join credit_card_feess_for_hotels ccf on hto.paymentMethod = ccf.card_name_code left join store_taxes st on st.id = ccf.card_tax_id where hto.pspReference != "" and ccf.cod_id = "' . $cod_id . '" and ' . $field . '="' . $fieldvalue . '" and hotel_checkout_status="0" and st.service_id=' . SERVICE_NAME . ' and ccf.service_id=' . SERVICE_NAME;
        $data = $this->primarydb->db->query($query);
        if ($data->num_rows() > 0) {
            return $data->row();
        } else {
            $query = 'select distinct hto.hotel_id, hto.visitor_group_no, hto.isBillToHotel, hto.visitor_group_no, hto.ticketPaymentFailCount, hto.shopperReference, hto.merchantAccountCode, hto.shopperStatement, hto.paymentMethod, hto.pspReference, hto.parentPassNo, hto.total_variable_amount, hto.total_amount_with_tax, hto.total_amount_without_tax, hto.initial_authorised_amount, hto.is_block, ccf.fixed_amount, ccf.variable_amount, ccf.card_tax_id, ccf.calculated_on, st.tax_value from hotel_ticket_overview hto left join credit_card_fees ccf on hto.paymentMethod = ccf.card_name_code left join store_taxes st on st.id = ccf.card_tax_id where hto.pspReference != "" and ' . $field . '="' . $fieldvalue . '" and hotel_checkout_status="0" and st.service_id=' . SERVICE_NAME . ' and ccf.service_id=' . SERVICE_NAME;
            $data  = $this->primarydb->db->query($query);
            if ($data->num_rows() > 0) {
            return $data->row();
            } else {
            return false;
            }
        }
    }
}

?>