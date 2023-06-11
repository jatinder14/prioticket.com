<?php
/*
This class is for binding form data
Name  BindFormData
*/
//credentials
//define ("MERCHANTCODE","TassenbedruktNL");
define("SOAP_USER", SOAP_ADYEN_USER);  
define("SOAP_PW", SOAP_ADYEN_PASSWORD);

class AdyenRecurringPayment
{
    var $client; //SOAP client
    var $response; //SOAP response
    var $out; //debug output
    var $logdir;
    var $opert;
    private $DEBUG;

    /**
    *	function bind
    *	params $post, form fields data
    *	$tables, table name
    *	$dbo, database object
    *	returns binded data for each table
    *
    */

    function __construct($debug=false) {
        if (!defined("MERCHANTCODE") || !defined("SOAP_USER") || !defined("SOAP_PW")) {
            exit("Missing info for Adyen");
        }
      
        //enable logging
        $this->DEBUG = $debug; if ($this->DEBUG) { ob_start(); }
    }

    function __destruct() {
        //enable logging
        if ($this->DEBUG && $this->opert=='Payment') {
           // echo "<hr>Debug output<hr><br>";
           //echo "REQUEST:\n" . $this->client->__getLastRequest() . "\n";
           // echo "RESPONSE:\n" . $this->client->__getLastResponse() . "\n";
            
            $this->client->__getLastRequest() . "\n";
            $this->client->__getLastResponse() . "\n";
            $this->out = ob_get_clean();
            //print $this->out;
        }
    }

    public function startSOAP($operation="Payment") 
    {
        $this->opert = $operation;
        $host = PAYMENT_MODE;
        ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache
        $this->client = new SoapClient( "https://pal-$host.adyen.com/pal/{$operation}.wsdl",
        array(
            "location" => "https://pal-$host.adyen.com/pal/servlet/soap/{$operation}",
            "login" => SOAP_USER,
            "password" => SOAP_PW,
            'trace' => 1,
            'soap_version' => SOAP_1_1,
            'style' => SOAP_DOCUMENT,
            'encoding' => SOAP_LITERAL
            )
        );
            
    }
    
    public function tassen_startSOAP($operation="Payment") 
    {
        $this->opert = $operation;
        $host = PAYMENT_MODE;
        ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache
        $this->client = new SoapClient( "https://pal-$host.adyen.com/pal/{$operation}.wsdl",
        array(
            "location" => "https://pal-$host.adyen.com/pal/servlet/soap/{$operation}",
            "login" => TassenbedruktNL_SOAP_ADYEN_USER,
            "password" => TassenbedruktNL_SOAP_ADYEN_PASSWORD,
            'trace' => 1,
            'soap_version' => SOAP_1_1,
            'style' => SOAP_DOCUMENT,
            'encoding' => SOAP_LITERAL
            )
        );
    }
    public function sandeman_startSOAP($operation="Payment") 
    {
        $this->opert = $operation;
        $host = PAYMENT_MODE;
        ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache
        $this->client = new SoapClient( "https://pal-$host.adyen.com/pal/{$operation}.wsdl",
        array(
            "location" => "https://pal-$host.adyen.com/pal/servlet/soap/{$operation}",
            "login" => SANDEMAN_SOAP_ADYEN_USER,
            "password" => SANDEMAN_SOAP_ADYEN_PASSWORD,
            'trace' => 1,
            'soap_version' => SOAP_1_1,
            'style' => SOAP_DOCUMENT,
            'encoding' => SOAP_LITERAL
            )
        );
    }
    
   
    
    /*function authoriseInitialAmount( $amount, $currencyCode, $cardHolder, $cardNumber, $expm, $expy, $cvc, $reference) {
        $response = $this->client->authorise( array(
                                                "paymentRequest" => array
                                                (
                                                    "amount" => array (
                                                        "value" => $amount,
                                                        "currency" => $currencyCode),
                                                    "card" => array (
                                                        "cvc" => $cvc,
                                                        "expiryMonth" => $expm,
                                                        "expiryYear" => $expy,
                                                        "holderName" => $cardHolder,
                                                        "number" => $cardNumber,
                                                    ),
                                                    "merchantAccount" => MERCHANTCODE,
                                                    "reference" => $reference,
                                                )
                                            )
                                        );

        if ($this->DEBUG) echo var_dump($response);

        # Check the response.
        if ( $response->paymentResult->resultCode == "Authorised" ) {
            echo "Authorised. Your authorisation code is : " . $response->paymentResult->authCode . "\n";
            $authorised = true;
        } elseif($response->paymentResult->refusalReason) {
            echo "Refused: " . $response->paymentResult->refusalReason . "\n";
            $authorised = false;
        } else {
            echo "Error\n";
        }

            //if ($this->DEBUG) echo "REQUEST:\n" . $this->client->__getLastRequest() . "\n";
            //if ($this->DEBUG) echo "RESPONSE:\n" . $this->client->__getLastResponse() . "\n";

            return $authorised;
        }*/
    public function authorise( $RDref="LATEST", $amount, $orderCode, $shopRef, $merchantAcountCode,  $currencyCode='', $passNo='', $shopperStatement='') { //,
        if (empty($shopRef)) {
            exit("no shopRef for payment $orderCode");
        }

        try {
            $this->response = $this->client->authorise(
            array(
            "paymentRequest" =>
                array (
                    "amount" => array ("value" => $amount,"currency" => $currencyCode),
                    "merchantAccount" => $merchantAcountCode,
                    "reference" => "REC".$orderCode,
                    "shopperReference" => $shopRef,
                    "shopperStatement" => $shopperStatement,
                    "shopperEmail" => 'support@priopass.com',
                    "recurring"=>array("contract"=>"RECURRING"),
                    "selectedRecurringDetailReference"=>$RDref,
                    "shopperInteraction"=>"ContAuth"
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            //if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }


        # Check the response.
        if ($this->response->paymentResult->resultCode == "Authorised" ) {
            $status="AUTHORISED";
        }
        elseif ( $this->response->paymentResult->resultCode == "Received" ) { //iDeal
            $status="RECEIVED";
        }
        elseif($this->response->paymentResult->refusalReason) {
            $status="REFUSED";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }

    public function authorisecvc( $RDref="LATEST", $amount, $orderCode, $shopRef, $merchantAcountCode,  $currencyCode='', $passNo='', $shopperStatement='',$arrays=array()) { //,
          
        if (empty($shopRef)) {
            exit("no shopRef for payment $orderCode");
        }

        try {
            $this->response = $this->client->authorise(
            array(
            "paymentRequest" =>
                array (
                    "amount" => array ("value" => $amount,"currency" => $currencyCode),
                    "card"=>array("cvc"=>$arrays['cvc'], "expiryMonth"=>$arrays['expiryMonth'], "expiryYear"=>$arrays['expiryYear'],"holderName"=>"AAAAA","number"=>$arrays['number']),
                    "merchantAccount" => $merchantAcountCode,
                    "reference" => $orderCode,
                    "shopperReference" => $shopRef,
                    "shopperStatement" => $shopperStatement,
                    "shopperEmail" => 'asd@qw.com',
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }


        # Check the response.
        if ($this->response->paymentResult->resultCode == "Authorised" ) {
            $status="AUTHORISED";
        }
        elseif ( $this->response->paymentResult->resultCode == "Received" ) { //iDeal
            $status="RECEIVED";
        }
        elseif($this->response->paymentResult->refusalReason) {
            $status="REFUSED";
        }
        else {
            $status="ERROR";
        }
        $array['status']=$status;
        $array['pspReference']=$this->response->paymentResult->pspReference;
        
        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $this->response->paymentResult;
        
    }

  
    public function getRecurringDetails($shopRef, $MERCHANTCODE) {
        try {
            $this->response = $this->client->listRecurringDetails (
                array(
                    "request" => array ("merchantAccount" => $MERCHANTCODE, "shopperReference" => $shopRef,"recurring"=>array("contract"=>"RECURRING") )
                    )
                );
        }
        catch ( Exception $e ) {
            //$errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }
    }   

    public function capture($merchantAccount, $modificationAmount, $pspReference, $currency='EUR') {        
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }
        try {
            $this->response = $this->client->capture(
            array(
            "modificationRequest" =>
                array (
                    "merchantAccount" => $merchantAccount,
                    "modificationAmount" => array ("value" => $modificationAmount,"currency" => $currency),
                    "originalReference" => $pspReference                    
                    )
                )
            );
        }        
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            echo $errorMessage; exit;
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }
        if ($this->response->captureResult->response == "[capture-received]" ) {
            $status="capture-received";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }  

    public function cancel($merchantAccount, $pspReference) { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->cancel(
            array(
            "modificationRequest" =>
                array (
                    "merchantAccount" => $merchantAccount,
                    "originalReference" => $pspReference                    
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }

        # Check the response.
        
        if ($this->response->cancelResult->response == "[cancel-received]" ) {
            $status="cancel-received";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }

    public function refund($merchantAccount, $modificationAmount, $pspReference) { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->refund(
            array(
            "modificationRequest" =>
                array (
                    "merchantAccount" => $merchantAccount,
                    "modificationAmount" => array ("value" => $modificationAmount,"currency" => "EUR"),
                    "originalReference" => $pspReference                    
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }
        
        if ($this->response->refundResult->response == "[refund-received]" ) {
            $status="refund-received";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }

    public function sendNotificationResponse($response) { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->sendNotificationResponse(
            array(
            "notificationResponse" =>$response
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }

        
        
        if ($this->response->captureResult->response == "[capture-received]" ) {
            $status="capture-received";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }

    // SOAP debug
    function printHeaders($client) {
        print("<pre>");
        print("Request Headers:<br />");
        print($client->__getLastRequestHeaders());
        #print(htmlspecialchars($client->__getLastRequestHeaders()));
        print("<br /><br />Request:<br />");
        print($client->__getLastRequest());
        #print(htmlspecialchars($client->__getLastRequest()));
        print("<br /><br />Response Headers:<br />");
        print($client->__getLastResponseHeaders());
        #print(htmlspecialchars($client->__getLastResponseHeaders()));
        print("<br /><br />Response:<br />");
        print($client->__getLastResponse());
        #print(htmlspecialchars($client->__getLastResponse()));
        print("<pre>");
    }
    
    function store_recurring_token($expiryMonth,$expiryYear, $shopRef,$holderName,$number, $merchantAcountCode,  $passNo='', $shopperStatement='')
    {
        try {
            $this->response = $this->client->storeToken(
                array(
                    "request" =>
                    array (
                        "bank"=>"",
                        //"card" => array ("expiryMonth" => "06","expiryYear" => "2016","holderName"=>"as","number"=>"4111111111111111"),
                        "card" => array ("expiryMonth" => "$expiryMonth","expiryYear" => "$expiryYear","holderName"=>"$holderName","number"=>"$number"),
                        "elv"=>"",
                        "merchantAccount" => $merchantAcountCode,
                        "recurring"=>array("contract"=>"RECURRING"),
                        "shopperEmail" => "support@priopass.com",
                        "shopperReference" => $shopRef,
                        "reference"=>$passNo
                        // "paymentMethod"=>"card",
                        //"additionalData.card.encrypted.json"=>$encrypted
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            //if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
            $this->response->result->result ='Card number is not valid';
            $this->response->result->rechargeReference ='';
            $this->response->result->recurringDetailReference ='';
        }

        # Check the response.
        if ($this->response->result->result == "Success" ) {
            $status="Success";
        }
        else {
            $status="ERROR";
        }

        if (!empty($errorMessage))
            return $errorMessage;
        else
            return $status;
    }
    
    function list_recurring_details($merchantAcountCode, $shopRef)
    {
          try {
            $this->response = $this->client->listRecurringDetails(
            array(
            "request" =>
                array (
                   "recurring"=>array("contract"=>"RECURRING"),
                    "merchantAccount" => $merchantAcountCode,
                    "shopperReference" => $shopRef,
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }
        $response = $this->response->result->details->RecurringDetail;     
        return $response;
    }
    
    /* Authorise card via soap */
    function authoriseViaSoap($data, $payment_mode = 'test', $soap_user = SOAP_USER, $soap_password = SOAP_PW) {
        if($payment_mode == '') {
            $payment_mode = 'live';
        }
        $merchantAccount = $data['merchantAccount'];
        $currency = $data['currency'];
        $amount = $data['amount'];
        $reference = $data['reference'];
        $shopperEmail = $data['shopperEmail'];
        $shopperReference = $data['shopperReference'];
        $encrypted_data = $data['card.encrypted.json'];
        $shopperIP = $data['shopperIP'];
        
        if(isset($data)){

            /**
             * Create SOAP Client = new SoapClient($wsdl,$options)
             * - $wsdl points to the wsdl you are using;
             * - $options[login] = Your WS user;
             * - $options[password] = Your WS user's password.
             * - $options[cache_wsdl] = WSDL_CACHE_BOTH, we advice 
             *   to cache the WSDL since we usually never change it.
             */

           $client = new SoapClient(
                "https://pal-$payment_mode.adyen.com/pal/Payment.wsdl", array(
                    "login" => $soap_user,  
                    "password" => $soap_password,
                    "style" => SOAP_DOCUMENT,
                    "encoding" => SOAP_LITERAL,
                    "cache_wsdl" => WSDL_CACHE_BOTH,
                    "trace" => 1
                )
            );

            try{
	 	
	 	 /**
		  * The payment can be submitted by sending a PaymentRequest 
		  * to the authorise action of the web service, the request should 
		  * contain the following variables:
		  * - merchantAccount: The merchant account the payment was processed with.
		  * - amount: The amount of the payment
		  * 	- currency: the currency of the payment
		  * 	- amount: the amount of the payment
		  * - reference: Your reference
		  * - shopperIP: The IP address of the shopper (optional/recommended)
		  * - shopperEmail: The e-mail address of the shopper 
		  * - shopperReference: The shopper reference, i.e. the shopper ID
		  * - fraudOffset: Numeric value that will be added to the fraud score (optional)
		  * - paymentRequest.additionalData.card.encrypted.json: The encrypted card catched by the POST variables.
		  */
                $request = array(
                    "paymentRequest" => array(
                        "merchantAccount" => $merchantAccount, 
                        "recurring" => array(
                            "contract" => "RECURRING" // i.e.: "ONECLICK","RECURRING" or "ONECLICK,RECURRING"
                        ),
                        "amount" => array(
                            "currency" => $currency,
                            "value" => $amount,
                        ),
                        "reference" => $reference,
                        "shopperIP" => $shopperIP,
                        "shopperEmail" => $shopperEmail,
                        "shopperReference" => $shopperReference, 
                        "fraudOffset" => "0",
                        "additionalData" => array(
                            "entry" => new SoapVar(array(
                                    "key" => new SoapVar("card.encrypted.json", XSD_STRING, "string", "http://www.w3.org/2001/XMLSchema", "key", "http://payment.services.adyen.com"),
                                    "value" => new SoapVar($encrypted_data, XSD_STRING, "string", "http://www.w3.org/2001/XMLSchema", "value", "http://payment.services.adyen.com")
                            ), SOAP_ENC_OBJECT, "")
                        )
                    )
                );
                
		$result = $client->authorise($request);
		
		/**
		 * If the payment passes validation a risk analysis will be done and, depending on the
		 * outcome, an authorisation will be attempted. You receive a
		 * payment response with the following fields:
		 * - pspReference: The reference we assigned to the payment;
		 * - resultCode: The result of the payment. One of Authorised, Refused or Error;
		 * - authCode: An authorisation code if the payment was successful, or blank otherwise;
		 * - refusalReason: If the payment was refused, the refusal reason.
		 */ 
                
                return $result;
                
            } catch(SoapFault $ex) {	
                $data['paymentResult']['resultCode'] = $ex->getMessage();
                $data['paymentResult']['pspReference'] = '';
                $data = (object) $data['paymentResult'];
                $data = (object) $data;
                return $data;
            }	 
        }
    }
    
    /**
     * @name: submit_payout_request 
     * @purpose: To submit new payout request
     * @where: It is called from My controller
     * @How it works: Get information related to payout merchant account then submit payout request on Adyen
     * @params:
     *      $data - Array of merchant account information
     *      $soap_user - Username
     *      $soap_password - Password
     *      $payment_mode - Test or live

     * @returns: Response of request
     */
    function submit_payout_request($data = array(), $payment_mode = 'test', $soap_user = SOAP_USER, $soap_password = SOAP_PW) {
        $merchantAccount = $data['merchantAccount'];
        $currency = $data['currency'];
        $amount = $data['amount'];
        $reference = $data['reference'];
        $shopperEmail = $data['shopperEmail'];
        $shopperReference = $data['shopperReference'];
        $encrypted_data = $data['card.encrypted.json'];
        $shopperIP = $data['shopperIP'];

        if (isset($data)) {
            /**
             * Create SOAP Client = new SoapClient($wsdl,$options)
             * - $wsdl points to the wsdl you are using;
             * - $options[login] = Your WS user;
             * - $options[password] = Your WS user's password.
             * - $options[cache_wsdl] = WSDL_CACHE_BOTH, we advice 
             *   to cache the WSDL since we usually never change it.
             */
            $client = new SoapClient(
                    "https://pal-$payment_mode.adyen.com/pal/servlet/Payout/v12?wsdl", array(
                "login" => $soap_user,
                "password" => $soap_password,
                "style" => SOAP_DOCUMENT,
                "encoding" => SOAP_LITERAL,
                "cache_wsdl" => WSDL_CACHE_BOTH,
                "trace" => 1
                    )
            );
            try {
                /**
                 * The payment can be submitted by sending a PaymentRequest 
                 * to the authorise action of the web service, the request should 
                 * contain the following variables:
                 * - merchantAccount: The merchant account the payment was processed with.
                 * - amount: The amount of the payment
                 * 	- currency: the currency of the payment
                 * 	- amount: the amount of the payment
                 * - reference: Your reference
                 * - shopperIP: The IP address of the shopper (optional/recommended)
                 * - shopperEmail: The e-mail address of the shopper 
                 * - shopperReference: The shopper reference, i.e. the shopper ID
                 * - fraudOffset: Numeric value that will be added to the fraud score (optional)
                 * - paymentRequest.additionalData.card.encrypted.json: The encrypted card catched by the POST variables.
                 */
                $request = array(
                    'request' => array(
                        "merchantAccount" => $merchantAccount,
                        "reference" => $reference,
                        //"shopperIP" => $shopperIP,
                        "shopperEmail" => $shopperEmail,
                        "shopperReference" => $shopperReference,
                        "fraudOffset" => "0",
                        'recurring' => array(
                            'contract' => 'PAYOUT'
                        ),
//                        'bank' => array(
//                            'iban' => 'NL25RABO0185231969',
//                            'bic' => 'RABONL2U',
//                            'bankName' => 'Rabobank',
//                            'countryCode' => 'NL',
//                            'ownerName' => 'Adam Lookout BV',
//                        ),
                        'bank' => array(
                            'iban' => $data['iban'],
                            'bic' => $data['bic'],
                            'bankName' => $data['bankName'],
                            'countryCode' => $data['countryCode'],
                            'ownerName' => $data['ownerName'],
                        ),
                        'amount' => array(
                            'currency' => $currency,
                            'value' => $amount,
                        )
                    )
                );
                $result = $client->storeDetailAndSubmit($request);
                /**
                 * If the payment passes validation a risk analysis will be done and, depending on the
                 * outcome, an authorisation will be attempted. You receive a
                 * payment response with the following fields:
                 * - pspReference: The reference we assigned to the payment;
                 * - resultCode: The result of the payment. One of Authorised, Refused or Error;
                 * - authCode: An authorisation code if the payment was successful, or blank otherwise;
                 * - refusalReason: If the payment was refused, the refusal reason.
                 */
                return $result;
            } catch (SoapFault $ex) {
                $data['paymentResult']['resultCode'] = $ex->getMessage();
                $data['paymentResult']['pspReference'] = '';
                $data = (object) $data['paymentResult'];
                $data = (object) $data;
                return $data;
            }
        }
    }

    function confirm_payout_request($merchantAccount = '', $reference = '', $payment_mode = 'test', $soap_user = SOAP_USER, $soap_password = SOAP_PW) {
        if($merchantAccount != '' && $reference != ''){

            /**
             * Create SOAP Client = new SoapClient($wsdl,$options)
             * - $wsdl points to the wsdl you are using;
             * - $options[login] = Your WS user;
             * - $options[password] = Your WS user's password.
             * - $options[cache_wsdl] = WSDL_CACHE_BOTH, we advice 
             *   to cache the WSDL since we usually never change it.
             */

           $client = new SoapClient(
                   
                "https://pal-$payment_mode.adyen.com/pal/servlet/Payout/v12?wsdl", array(
                    "login" => $soap_user,  
                    "password" => $soap_password,
                    "style" => SOAP_DOCUMENT,
                    "encoding" => SOAP_LITERAL,
                    "cache_wsdl" => WSDL_CACHE_BOTH,
                    "trace" => 1
                )
            );

            try{
	 	
	 	 /**
		  * The payment can be submitted by sending a PaymentRequest 
		  * to the authorise action of the web service, the request should 
		  * contain the following variables:
		  * - merchantAccount: The merchant account the payment was processed with.
		  * - amount: The amount of the payment
		  * 	- currency: the currency of the payment
		  * 	- amount: the amount of the payment
		  * - reference: Your reference
		  * - shopperIP: The IP address of the shopper (optional/recommended)
		  * - shopperEmail: The e-mail address of the shopper 
		  * - shopperReference: The shopper reference, i.e. the shopper ID
		  * - fraudOffset: Numeric value that will be added to the fraud score (optional)
		  * - paymentRequest.additionalData.card.encrypted.json: The encrypted card catched by the POST variables.
		  */
                $request = array(
                    'request' => array(
                        "merchantAccount" => $merchantAccount, 
                        "originalReference" => $reference,
                    )
                );
                
                $result = $client->confirm($request);
		
		/**
		 * If the payment passes validation a risk analysis will be done and, depending on the
		 * outcome, an authorisation will be attempted. You receive a
		 * payment response with the following fields:
		 * - pspReference: The reference we assigned to the payment;
		 * - resultCode: The result of the payment. One of Authorised, Refused or Error;
		 * - authCode: An authorisation code if the payment was successful, or blank otherwise;
		 * - refusalReason: If the payment was refused, the refusal reason.
		 */ 
                
                return $result;
                
            } catch(SoapFault $ex) {	
                $data['paymentResult']['resultCode'] = $ex->getMessage();
                $data['paymentResult']['pspReference'] = '';
                $data = (object) $data['paymentResult'];
                $data = (object) $data;
                return $data;
            }	 
        }
    }
    
    function authPayment($data, $payment_mode = 'test', $soap_user = PRIOTICKET_SOAP_USER, $soap_password = PRIOTICKET_SOAP_PASSWORD) {
        if($payment_mode == '') {
            $payment_mode = 'live';
        }
        $merchantAccount = $data['merchantAccount'];
        $currency = $data['currency'];
        $amount = $data['amount'];
        $reference = $data['reference'];
        $shopperEmail = $data['shopperEmail'];
        $shopperReference = $data['shopperReference'];
        $shopperIP = $data['shopperIP'];

        $client = new SoapClient(
                "https://pal-$payment_mode.adyen.com/pal/Payment.wsdl", array(
                "login" => $soap_user,  
                "password" => $soap_password,     
                "style" => SOAP_DOCUMENT,
                "encoding" => SOAP_LITERAL,
                "cache_wsdl" => WSDL_CACHE_BOTH,
                "trace" => 1
            )
        );

         /**
          * A recurring payment can be submitted by sending a PaymentRequest 
          * to the authorise action, the request should contain the following
          * variables:
          * 
          * - selectedRecurringDetailReference: The recurringDetailReference you want to use for this payment. 
          *   The value LATEST can be used to select the most recently created recurring detail.
          * - recurring: This should be the same value as recurringContract in the payment where the recurring 
          *   contract was created. However if ONECLICK,RECURRING was specified initially
          *   then this field can be either ONECLICK or RECURRING.
          * - merchantAccount: The merchant account the payment was processed with.
          * - amount: The amount of the payment
          * 	- currency: the currency of the payment
          * 	- amount: the amount of the payment
          * - reference: Your reference
          * - shopperEmail: The e-mail address of the shopper 
          * - shopperReference: The shopper reference, i.e. the shopper ID
          * - shopperInteraction: ContAuth for RECURRING or Ecommerce for ONECLICK 
          * - fraudOffset: Numeric value that will be added to the fraud score (optional)
          * - shopperIP: The IP address of the shopper (optional)
          * - shopperStatement: Some acquirers allow you to provide a statement (optional)
          */
            try{
                $result = $client->authorise(array(
                        "paymentRequest" => array(
                            "selectedRecurringDetailReference" => 'LATEST', 
                            "recurring" => array(
                                "contract" => "RECURRING" // i.e.: "ONECLICK","RECURRING" or "ONECLICK,RECURRING"
                            ),
                            "merchantAccount" => $merchantAccount,
                            "amount" => array(
                                "currency" => "EUR",
                                "value" => $amount,
                            ),
                            "reference" => $reference,
                            "shopperEmail" => $shopperEmail, 
                            "shopperReference" => $shopperReference, 
                            "shopperInteraction" => "ContAuth", // ContAuth for RECURRING or Ecommerce for ONECLICK 
                            "fraudOffset" => "0",
                            "shopperIP" => $shopperIP,
                            "shopperStatement" => "", 
                        )
                    )
                );

               /**
                * If the recurring payment message passes validation a risk analysis will be done and, depending on the
                * outcome, an authorisation will be attempted. You receive a
                * payment response with the following fields:
                * - pspReference: The reference we assigned to the payment;
                * - resultCode: The result of the payment. One of Authorised, Refused or Error;
                * - authCode: An authorisation code if the payment was successful, or blank otherwise;
                * - refusalReason: If the payment was refused, the refusal reason.
                */ 
               return $result;

        }catch(SoapFault $ex){
            $data['paymentResult']['resultCode'] = $ex->getMessage();
            $data['paymentResult']['pspReference'] = '';
            $data = (object) $data['paymentResult'];
            $data = (object) $data;
            return $data;
        }
    }
    
    function authCapture($merchantAccount, $modificationAmount, $pspReference, $currency='EUR') {

        $client = new SoapClient(
                "https://pal-test.adyen.com/pal/Payment.wsdl", array(
                "login" => PRIOTICKET_SOAP_USER,  
                "password" => PRIOTICKET_SOAP_PASSWORD,   
                "style" => SOAP_DOCUMENT,
                "encoding" => SOAP_LITERAL,
                "cache_wsdl" => WSDL_CACHE_BOTH,
                "trace" => 1
            )
        );
        try {
            $this->response = $client->capture(
            array(
                "modificationRequest" =>
                array (
                    "merchantAccount" => $merchantAccount,
                    "modificationAmount" => array ("value" => $modificationAmount,"currency" => $currency),
                    "originalReference" => $pspReference                    
                    )
                )
            );
        }
        catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) { echo "SOAP Error \n" . print_r( $e, true ); }
        }

        # Check the response.       
       
        
        if ($this->response->captureResult->response == "[capture-received]" ) {
            $status="capture-received";
        }
        else {
            $status="ERROR";
        }
        return $status;
    }
}

?>