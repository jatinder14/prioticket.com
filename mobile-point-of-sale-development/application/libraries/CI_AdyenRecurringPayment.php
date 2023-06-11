<?php

/*
  This class is for binding form data
  Name  BindFormData
 */
//credentials

define("SOAP_USER", SOAP_ADYEN_USER);
define("SOAP_PW", SOAP_ADYEN_PASSWORD);

class CI_AdyenRecurringPayment {

    var $client; //SOAP client
    var $response; //SOAP response
    var $out; //debug output
    var $logdir;
    private $DEBUG;

    /**
     * 	function bind
     * 	params $post, form fields data
     * 	$tables, table name
     * 	$dbo, database object
     * 	returns binded data for each table
     *
     */
    function __construct($debug = false) {
        if (!defined("MERCHANTCODE") || !defined("SOAP_USER") || !defined("SOAP_PW")) {
            exit("Missing info for Adyen");
        }
        //enable logging
        $this->DEBUG = $debug;
        if ($this->DEBUG) {
            ob_start();
            print "<PRE>";
        }
    }

    function __destruct() {
        //enable logging
        if ($this->DEBUG) {
            $this->client->__getLastRequest();
            $this->client->__getLastResponse();
            $this->out = ob_get_clean();
        }
    }

    public function startSOAP($operation = "Payment") {
        $host = PAYMENT_MODE;
        ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache

        $this->client = new SoapClient("https://pal-$host.adyen.com/pal/{$operation}.wsdl", array(
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

    public function tassen_startSOAP($operation = "Payment") {
        $host = PAYMENT_MODE;
        ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache

        $this->client = new SoapClient("https://pal-$host.adyen.com/pal/{$operation}.wsdl", array(
            "location" => "https://pal-$host.adyen.com/pal/servlet/soap/{$operation}",
            "login" => TASSEN_SOAP_ADYEN_USER,
            "password" => TASSEN_SOAP_ADYEN_PASSWORD,
            'trace' => 1,
            'soap_version' => SOAP_1_1,
            'style' => SOAP_DOCUMENT,
            'encoding' => SOAP_LITERAL
                )
        );
    }

    public function authorise($RDref = "LATEST", $amount, $orderCode, $shopRef, $merchantAcountCode, $currencyCode = '', $passNo = '', $shopperStatement = '') { //,
        if (empty($shopRef)) {
            exit("no shopRef for payment $orderCode");
        }

        try {
            $this->response = $this->client->authorise(
                    array(
                        "paymentRequest" =>
                        array(
                            "amount" => array("value" => $amount, "currency" => $currencyCode),
                            "merchantAccount" => $merchantAcountCode,
                            "reference" => $orderCode,
                            "shopperReference" => $shopRef,
                            "shopperStatement" => $shopperStatement,
                            "shopperEmail" => PRIOPASS_NO_REPLY_EMAIL,
                            "recurring" => array("contract" => "RECURRING"),
                            "selectedRecurringDetailReference" => $RDref,
                            "shopperInteraction" => "ContAuth"
                        )
                    )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }


        # Check the response.
        if ($this->response->paymentResult->resultCode == "Authorised") {
            $status = "AUTHORISED";
        } elseif ($this->response->paymentResult->resultCode == "Received") { //iDeal
            $status = "RECEIVED";
        } elseif ($this->response->paymentResult->refusalReason) {
            $status = "REFUSED";
        } else {
            $status = "ERROR";
        }

        if (!empty($errorMessage)) {
            return $errorMessage;
        } else {
            return $status;
        }
    }

    public function getRecurringDetails($shopRef, $MERCHANTCODE) {
        try {
            $this->response = $this->client->listRecurringDetails(
                    array(
                        "request" => array("merchantAccount" => $MERCHANTCODE, "shopperReference" => $shopRef, "recurring" => array("contract" => "RECURRING"))
                    )
            );
        } catch (Exception $e) {
            if ($this->DEBUG) {
                echo "SOAP Error \n" . print_r($e, true);
            }
        }
    }

    public function capture($merchantAccount, $modificationAmount, $pspReference, $currency = 'EUR') { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->capture(
                    array(
                        "modificationRequest" =>
                        array(
                            "merchantAccount" => $merchantAccount,
                            "modificationAmount" => array("value" => $modificationAmount, "currency" => $currency),
                            "originalReference" => $pspReference
                        )
                    )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) {
                echo "SOAP Error \n" . print_r($e, true);
            }
        }

        if ($this->response->captureResult->response == "[capture-received]") {
            $status = "capture-received";
        } else {
            $status = "ERROR";
        }

        if (!empty($errorMessage)) {
            return $errorMessage;
        } else {
            return $status;
        }
    }

    public function cancel($merchantAccount, $pspReference) { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->cancel(
                    array(
                        "modificationRequest" =>
                        array(
                            "merchantAccount" => $merchantAccount,
                            "originalReference" => $pspReference
                        )
                    )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) {
                echo "SOAP Error \n" . print_r($e, true);
            }
        }

        # Check the response.

        if ($this->response->cancelResult->response == "[cancel-received]") {
            $status = "cancel-received";
        } else {
            $status = "ERROR";
        }

        if (!empty($errorMessage)) {
            return $errorMessage;
        } else {
            return $status;
        }
    }

    public function refund($merchantAccount, $modificationAmount, $pspReference) { //,
        if (empty($pspReference)) {
            exit("no shopRef for payment $pspReference");
        }

        try {
            $this->response = $this->client->refund(
                    array(
                        "modificationRequest" =>
                        array(
                            "merchantAccount" => $merchantAccount,
                            "modificationAmount" => array("value" => $modificationAmount, "currency" => "EUR"),
                            "originalReference" => $pspReference
                        )
                    )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) {
                echo "SOAP Error \n" . print_r($e, true);
            }
        }
        if ($this->response->refundResult->response == "[refund-received]") {
            $status = "refund-received";
        } else {
            $status = "ERROR";
        }

        if (!empty($errorMessage)) {
            return $errorMessage;
        } else {
            return $status;
        }
    }

    public function sendNotificationResponse($response) {
        try {
            $this->response = $this->client->sendNotificationResponse(
                    array(
                        "notificationResponse" => $response
                    )
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            if ($this->DEBUG) {
                echo "SOAP Error \n" . print_r($e, true);
            }
        }
        if ($this->response->captureResult->response == "[capture-received]") {
            $status = "capture-received";
        } else {
            $status = "ERROR";
        }

        if (!empty($errorMessage)) {
            return $errorMessage;
        } else {
            return $status;
        }
    }

    /* Authorise card via soap */

    function authoriseViaSoap($data, $payment_mode = 'test', $soap_user = SOAP_USER, $soap_password = SOAP_PW) {
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
                    "https://pal-$payment_mode.adyen.com/pal/Payment.wsdl", array(
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
            } catch (SoapFault $ex) {
                $data['paymentResult']['resultCode'] = $ex->getMessage();
                $data['paymentResult']['pspReference'] = '';
                $data = (object) $data['paymentResult'];
                $data = (object) $data;
                return $data;
            }
        }
    }

    function authPayment($data, $payment_mode = 'test') {
        $merchantAccount = $data['merchantAccount'];
        $currency = $data['currency'];
        $amount = $data['amount'];
        $reference = $data['reference'];
        $shopperEmail = $data['shopperEmail'];
        $shopperReference = $data['shopperReference'];
        $shopperIP = $data['shopperIP'];

        $client = new SoapClient(
                "https://pal-$payment_mode.adyen.com/pal/Payment.wsdl", array(
            "login" => PRIOTICKET_SOAP_USER,
            "password" => PRIOTICKET_SOAP_PASSWORD,
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
        try {
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
        } catch (SoapFault $ex) {
            $data['paymentResult']['resultCode'] = $ex->getMessage();
            $data['paymentResult']['pspReference'] = '';
            $data = (object) $data['paymentResult'];
            $data = (object) $data;
            return $data;
        }
    }

}

?>