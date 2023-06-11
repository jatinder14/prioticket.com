<?php
/* LOG FILE NAME : tp_giftcard_lib_all.php  */
include_once 'common_library.php';
# ThirdParty: PHP wrapper class for thirdParty APIs
# Author: Manpreet Kaur
/* -----------------DEFINE CONSTANTS HERE------------------ */

class Giftcard extends Common_library {

    public $ticket_id = "";
    public $TICKET_DETAILS = array();
    public $response = array();
    protected $status = FALSE;
    protected $message = "";
    protected $TP_NATIVE_INFO = array();
    public $entering_date_time = '';
    private $tranaction_headers;
    public $error_reference_no = '';

    /**
     * __construct
     */
    public function __construct($params = array()) {
        $this->ticket_id = !empty($params['ticket_id']) ? $params['ticket_id'] : "";
        $this->TICKET_DETAILS = !empty($params['ticket_params']) ? $params['ticket_params'] : array();
        $this->TP_NATIVE_INFO = json_decode(TP_NATIVE_INFO, true);
        $this->timezone = !empty($params['timezone']) ? $params['timezone'] : '+1';
        $CI = & get_instance();
        $CI->load->library('Apilog_library');
        $this->apilog = new Apilog_library();
        $this->log_dir = 'thirdparty_lib/giftcard';
        $this->log_filename = 'tp_giftcard_lib_all.php';
        $this->error_reference_no = (isset($params['error_reference_no']) && !empty($params['error_reference_no'])) ? $params['error_reference_no'] : ERROR_REFERENCE_NUMBER;
        $this->entering_date_time = gmdate('Y-m-d H:i:s:') . gettimeofday()["usec"];
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
            /* CHeck the processing time */
            $processing_times = 0;
            if ($this->THIRDPARTY_PROCESSING_TIME == 1) {
                $start_time = microtime(true);
            }
            /* Create SoapClient */
            $supplier_detail = $this->TP_NATIVE_INFO['giftcard']['supplier_detail'][$all_data['supplier_id']];
            $header_body['UserName'] = $supplier_detail['tp_user'];
            $header_body['Password'] = $supplier_detail['tp_password'];

            $client = new SoapClient($this->TP_NATIVE_INFO['giftcard']['tp_wsdl_url']);
            $client->__setLocation($this->TP_NATIVE_INFO['giftcard']['tp_end_point']);
            $header = new SOAPHeader($this->TP_NATIVE_INFO['giftcard']['header_namespace_url'], 'Authenticate', $header_body);
            $client->__setSoapHeaders($header);
            $giftcard_response = $client->{$api}($request);
            $encoded_response = json_encode($giftcard_response);
            $response = json_decode($encoded_response, TRUE);
            /* CHeck the processing time */
            if ($this->THIRDPARTY_PROCESSING_TIME == 1) {
                $end_time = microtime(true);
                $processing_time = ($end_time - $start_time) * 1000;
                $processing_times = ((int) $processing_time) / 1000;
            }
            $graylogs[] = array('log_dir' => $this->log_dir, 'log_filename' => $this->log_filename, 'title' => 'Giftcard Response', 'data' => json_encode(array('request' => $request, 'response' => $response)), 'api_name' => $api, 'error_reference_no' => $this->error_reference_no, 'request_time' => $this->get_current_datetime(), 'processing_time' => $processing_times);
            $this->apilog->setlog($this->log_dir, $this->log_filename, 'Giftcard Response', json_encode(array('request' => $request, 'response' => $response)), $api);
            return $response;
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
            'CardId' => $data['ticket_code']
        );
        $response = $this->send_request('GetCard', $params, $data);
        return $response;
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
            'CardId' => $data['ticket_code'],
            'Value' => $data['card_balance']
        );
        $response = $this->send_request('ActivateCard', $params, $data);
        return $response;
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
            'CardId' => $data['ticket_code']
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
        $purchase_response = array();
        /* call getCard operation to get get info */
        $get_card_response = $this->get_card($data);
        if (isset($get_card_response['ResultCode']) && isset($get_card_response['Card']['Status'])) {
            /* ResultCode 119 means card is not activated */
            if ($get_card_response['ResultCode'] == '119') {
                $data['card_balance'] = $get_card_response['Card']['Balance'];
                 /* call activateCard operation to activate card if not activated */
                $activate_card_response = $this->activate_card($data);
                if ($activate_card_response['ResultCode'] == '0' && $activate_card_response['ExpiryDate']) {
                    /* call purchase operation to purchase ticket with 0 price. */
                    $purchase_response = $this->purchase($data);
                } else {
                    $response = $this->set_response_error($response, 'Error', 'INVALID_TICKET_CODE', 'Invalid request.', 'GCAC1:Empty response from TP.' . json_encode($activate_card_response));
                }
            } else if ($get_card_response['ResultCode'] == '0') {
                /* call purchase operation to purchase ticket with 0 price. */
                $purchase_response = $this->purchase($data);
            } else {
                $response = $this->set_response_error($response, 'Error', 'INVALID_TICKET_CODE', 'Invalid request.', 'GCGC1:Empty response from TP. ' . json_encode($get_card_response));
            }
        } else {
            $response = $this->set_response_error($response, 'Error', 'INVALID_TICKET_CODE', 'Invalid request.', 'GCGC1:Authentication.' . json_encode($get_card_response));
        }
        if (!empty($purchase_response) && $purchase_response['ResultCode'] == 0) {
            $response['data']['status'] = 'Success';
            $response['data']['third_party_parameters'] = $this->tranaction_headers;
            $response['data']['purchase_response'] = $purchase_response;
            $response['data']['get_card_response'] = $get_card_response;
        } else {
            $response = $this->set_response_error($response, 'Error', 'INVALID_TICKET_CODE', 'Invalid request.', 'GCP1:response from TP. ' . json_encode($purchase_response));
        }
        $this->apilog->setlog($this->log_dir, $this->file_name, "Giftcard Validation Response", json_encode(array('response' => $response)), $api);
        $graylogs[] = array('log_dir' => $this->log_dir, 'log_filename' => $this->file_name, 'title' => 'Giftcard Validation Response', 'data' => json_encode(array('response' => $response)), 'api_name' => $api, 'error_reference_no' => $this->error_reference_no, 'request_time' => $this->get_current_datetime());
        return $response;
    }
}
?>
