<?php
include_once 'common_library.php';

/*
 * This library contains all functions related to intersolver process 
 * Created on 16 Aug 2019 by Vaishali Raheja <vaishali.intersoft@gmail.com>  
 */

class Intersolver extends Common_library {

    protected $status = FALSE;
    protected $message = "";
    protected $TP_NATIVE_INFO = array();
    private $tranaction_headers;

    /**
     * __construct
     */
    public function __construct($params = array()) {
        $this->TP_NATIVE_INFO = json_decode(TP_NATIVE_INFO, true);
        $unique_number = round(microtime(true) * 1000) . '' . rand(0, 9) . '' . rand(0, 9);
        /* set transaction headers to send in request. */
        $this->tranaction_headers = array(
            'TransactionTime' => date('Y-m-d') . 'T' . date("H:i:s"),
            'OperatorId' => 'Prioticket API',
            'ClientReference' => 'PRIO_' . time(),
            'RefPos' => 'PRIO' . $unique_number
        );
    }

    /**
     * @Name send_request
     * @Purpose to send soap request to third party.
     */
    function send_request($api, $request, $all_data = array()) {
        global $graylogs;
        $giftcard_response = $encoded_response = $response = array();
        if (!empty($api) && !empty($request)) {
            /* Create SoapClient */
            $supplier_detail = $this->TP_NATIVE_INFO['intersolver'][$all_data['service']]['supplier_detail'][$all_data['museum_id']];
            $header_body['UserName'] = $supplier_detail['tp_user'];
            $header_body['Password'] = $supplier_detail['tp_password'];

            $client = new SoapClient($this->TP_NATIVE_INFO['intersolver'][$all_data['service']]['tp_wsdl_url']);
            $client->__setLocation($this->TP_NATIVE_INFO['intersolver'][$all_data['service']]['tp_end_point']);
            $header = new SOAPHeader($this->TP_NATIVE_INFO['intersolver'][$all_data['service']]['header_namespace_url'], 'Authenticate', $header_body);
            $client->__setSoapHeaders($header);
            $giftcard_response = $client->{$api}($request);
            $encoded_response = json_encode($giftcard_response);
            return json_decode($encoded_response, TRUE);
        }
    }

    /**
     * @Name get_card
     * @Purpose GetCard opertion will provide the card status, 
     * this operation requires creditials information in header (UserName, Password). 
     * cardId is required input. With the help of resultCode in response we can check the status of card. 
     * @Response array which contains GetCard response.
     */
    function get_card($data = array()) {
        $params = array(
            'CardId' => $data['scanned_code']
        );
        return $this->send_request('GetCard', $params, $data);
    }

    /**
     * @Name activate_card
     * @Purpose in getCard operation if we are getting resultCode as 119 then we are calling ActivateCard operation.
     * Activate card requires same header information as GetCard.
     * In ActivateCard operation, we need TransactionHeaders, CardId, Value(this will be the balance value received from GetCard operation.)
     * If we get resultCode = 0 with ExpiryDate in response then it means card is Activated.
     * @Response array which contains activateCard response.
     */
    function activate_card($data = array()) {
        $params = array(
            'TransactionHeader' => $this->tranaction_headers,
            'CardId' => $data['scanned_code'],
            'Value' => $data['card_balance']
        );
        return $this->send_request('ActivateCard', $params, $data);
    }
    /**
     * @Name purchase
     * @Purpose Purchase API call to purchase ticket at LIAB with 0 price.
     * Header information is same as GetCard, ActivateCard
     * In Purchase operation, TransactionHeader, Value, CardId needed as input.
     * We are sending purchase call with 0 value.
     * if getting ResultCode as 0 then it means purchase is done. 
     * @Response array which contains Purchase  response.
     */
    function purchase($data = array()) {
        $params = array(
            'TransactionHeader' => $this->tranaction_headers,
            'Value' => 0,
            'CardId' => $data['scanned_code']
    
        );
        return $response = $this->send_request('Purchase', $params, $data);
    }

    /**
     * @Name validate_card
     * @Purpose to validate card, first call getCard Api,if card not activated then activateCard api.
     * once card is activated then call purchase api to purchase ticket of 0 price.
     * @Response array which contains status, Purchase response, transactions headers as third_party_parameters.
     */
    function validate_card($data = array()) {
        global $graylogs;
        $api = 'validate_card';
        $response = $purchase_response = array();
        /* call getCard operation to get get info */
        $get_card_response = $this->get_card($data);
        if (isset($get_card_response['ResultCode']) && isset($get_card_response['Card']['Status'])) {
            /* ResultCode 119 means card is not activated */
            if ($get_card_response['ResultCode'] == '119') {
                $data['card_balance'] = $get_card_response['Card']['Balance'];
                 /* call activateCard operation to activate card if not activated */
                $activate_card_response = $this->activate_card($data);
            }
            /* if card is activated successfully then purchase ticket with 0 price */
            if (($get_card_response['ResultCode'] == '119' && $activate_card_response['ResultCode'] == '0' && $activate_card_response['ExpiryDate']) || ($get_card_response['ResultCode'] == '0')) {
                $purchase_response = $this->purchase($data);
            } else if (isset($activate_card_response)) {
                //error in activating card
                $status = 0;
                $message = 'Pass not valid';
                $response['status'] = $status;
                        
                        
                        
                $response['data'] = array();
                $response['message'] = $message;
                $response['activate_card_response'] = $activate_card_response;
                    } else {
                //error was in get card 
                $status = 0;
                $message = 'Pass not valid';
                $response['status'] = $status;
                $response['data'] = array();
                $response['message'] = $message;
                $response['get_card_response'] = $get_card_response;
                    }
                } else {
            $status = 0;
            $message = 'Pass not valid';
            $response['status'] = $status;
            $response['data'] = array();
            $response['message'] = $message;
            $response['get_card_response'] = $get_card_response;
                }
        if (!empty($purchase_response) && $purchase_response['ResultCode'] == 0) {
                $response['data']['status'] = 'Success';
                $response['data']['third_party_parameters'] = $this->tranaction_headers;
            $response['data']['purchase_response'] = $purchase_response;
                $response['data']['get_card_response'] = $get_card_response;
        } else if (!empty($purchase_response)){
            $response = array();
            //error was in get card 
            $status = 0;
            $message = ($purchase_response['ResultCode'] == 103 || $purchase_response['ResultCode'] == 102) ? $purchase_response['ResultDescription'] : 'Pass not valid';
            $response['status'] = $status;
            $response['data'] = array();
            $response['message'] = $message;
            $response['purchase_failed'] = $purchase_response;
        }
        return $response;
    }
    
}
?>
