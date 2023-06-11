<?php
class Sendemail_Model extends My_Model {
    /* variable ends here */

    function __construct()
    {
        parent::__construct();
        $this->load->library('email');
        $this->load->library('encryption');
        $this->base_url = $this->config->config['base_url'];
        $this->imageDir = $this->config->config['imageDir'];
    }

    /**
     * @name: sendemailtousers
     * @purpose: To send email
     * @where: It is called from ajaxpublisher, cmapp, codes, crons, gvb, hotel, lookout, museum, signup controller 
     * codes_model, crons_model, hotel_model, merchant_model, citytour_model, merchant_soap_helper, sendemail_model
     * @How it works: Receive data to send, setup the email parameters like to, from, fromname, subject etc then send email     
     * @params: 
     *      $arraylist - it contains the email related data like subject, attachments, sender email, send to whom, html of email etc
     *      $fromemail - possible value 1, 2, 3, 4 to differentiate to send an email from which account
     *      $sendfrommaildrill - possible value 1 - send email via cron to all distributor about their sale and in this case MANDRIL is used to send email
     * @returns: No parameter is returned
     */
    function sendemailtousers($arraylist, $fromemail = '', $sendfrommaildrill = '0') {
        $isman = $fromemail;
        $to = $arraylist['emailc'];
        $html = $arraylist['html'];
        $from = $arraylist['from'];
        $fromname = $arraylist['fromname'];
        $subject = $arraylist['subject'];
        $attachments = $arraylist['attachments'];
        $bcc = array();
        if (isset($arraylist['BCC'])) {
            $bcc = $arraylist['BCC'];
        }
        /**         * ** Send from email****** */
        if ($fromemail != '') {
            $fromemail = $fromemail;
        } else {
            $fromemail = 1;
        }

        $fromemail = 1;
        if ($sendfrommaildrill == "1") { // Sending daily report emails from crons via mandrill
            $fromemail = 2;
        }
        if (MAIL_FROM_MAILGUN) {
            $fromemail = 2;
        }
        $this->CreateLog('check_gvb_email.php', 'step4.4', array("params" => json_encode($fromemail)));
        if ($fromemail == 1) {
            // CHECK EMAIL IS BOUNCED OR NOT
            $bounced = $this->db->get_where('sendgrid_bounced_emails', array("email" =>  $to))->row();
            if(isset($bounced->email) && $bounced->email != '') {
                return '1';
            } else {
                /* **** CREDENTIALS ***** */
                $url = SENDGRID_URL;
                $user = SENDGRID_USERNAME;
                $pass = SENDGRID_PASSWORD;
                /**             * *** CREDENTIALS ***** */
                if ($subject == "Don't forget to read this email before you visit Amsterdam") {
                    $from = $from;
                } else {
                    $from = PRIOPASS_REPORT_EMAIL;
                }
                if ($isman == 2) {
                    $from = PRIOPASS_NO_REPLY_EMAIL;
                } else if ($isman == 3) {
                    $from = LOOKOUT_REPORT_EMAIL;
                }
                // In case when from address should be 'receipts@prioticket.com'
                if (isset($arraylist['show_receipt']) && $arraylist['show_receipt'] == 1) {
                    $from = $arraylist['from'];
                }
                $params = array(
                    'api_user' => $user,
                    'api_key' => $pass,
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                    'text' => $html,
                    'from' => $from,
                    'fromname' => $fromname,
                );
                if (!empty($attachments) && count($attachments) > 0) {
                    foreach ($attachments as $passarr) {
                        $fileName = $passarr['name'];
                        $explodeArr = explode('system', $passarr['path']);
                        $params['files[' . $fileName . ']'] = new CURLFile('system' . $explodeArr[1]);
                    }
                }
                if (!empty($bcc)) {
                    foreach ($bcc as $key => $bcc_mail) {
                        $params['bcc[' . $key . ']'] = $bcc_mail;
                    }
                }

                $request = $url . 'api/mail.send.json';
                // Generate curl request
                $session = curl_init($request);
                // Tell curl to use HTTP POST
                curl_setopt($session, CURLOPT_POST, true);
                // Tell curl that this is the body of the POST
                curl_setopt($session, CURLOPT_POSTFIELDS, $params);
                // Tell curl not to return headers, but do return the response
                curl_setopt($session, CURLOPT_HEADER, false);
                // Tell PHP not to use SSLv3 (instead opting for TLS)
                curl_setopt($session, CURLOPT_SAFE_UPLOAD, false);
                curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($session, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                // obtain response
                $response = curl_exec($session);
                curl_close($session);
                $response = json_decode($response);
                if ($response->message == 'success') {
                    return '1';
                } else {
                    return false;
                }
            }            
        } else if ($fromemail == 2) {
            $this->load->library('Phpmailer');
            //Composer's autoload file loads all necessary files
            require 'qrcodes/scripts/mailgun/autoload.php';
            $mail = new Phpmailer;
            $mail->isSMTP();  // Set mailer to use SMTP
            $mail->Host = MAILGUN_HOST;  // Specify mailgun SMTP servers
            $mail->Port = "465";
            $mail->SMTPAuth = true; // Enable SMTP authentication
            if ($sendfrommaildrill == "1") { // Sending daily report emails from crons via mandrill
                $mail->Username = REPORT_MAILGUN_USER; // SMTP username from https://mailgun.com/cp/domains
                $mail->Password = REPORT_MAILGUN_PASSWORD; // SMTP password from https://mailgun.com/cp/domains
            } else {
                $mail->Username = MAILGUN_USER; // SMTP username from https://mailgun.com/cp/domains
                $mail->Password = MAILGUN_PASSWORD; // SMTP password from https://mailgun.com/cp/domains
            }
            
            $mail->SMTPSecure = 'ssl';   // Enable encryption, 'ssl'

            $mail->From = $from; // The FROM field, the address sending the email 
            $mail->FromName = $fromname; // The NAME field which will be displayed on arrival by the email client
            $mail->addAddress($to);     // Recipient's email address and optionally a name to identify him
            
            if (!empty($bcc)) {
                foreach ($bcc as $bccemail) {
                    $mail->AddBCC($bccemail);
                }
            }
            if (!empty($attachments) && count($attachments) > 0) {
                foreach ($attachments as $passarr) {
                    $mail->AddAttachment($passarr['path']);
                }
            }
            $mail->isHTML(true);   // Set email to be sent as HTML, if you are planning on sending plain text email just set it to false
            // The following is self explanatory
            $mail->Subject = $subject;
            $mail->Body = $html;                
            $this->CreateLog('check_gvb_email.php', 'step4.5', array("params" => json_encode($mail)));
            if (!$mail->send()) {
                $this->CreateLog('check_gvb_email.php', 'step4.6', array("params" => json_encode('if')));                
                return false;
            } else {
                $this->CreateLog('check_gvb_email.php', 'step4.7', array("params" => json_encode('else')));                
                return true;
            }
        } else if ($fromemail == 3) {
            $mail = new Phpmailer();
            $mail->CharSet = 'UTF-8';
            $mail->IsSMTP();
            $mail->Host = 'localhost';
            $mail->Port = 25;
            $mail->SetFrom($from, $fromname);
            $mail->ContentType = 'text/html';
            $mail->Subject = $subject;
            $mail->msgHTML($html);
            if (!empty($attachments) && count($attachments) > 0) {
                foreach ($attachments as $passarr) {
                    $mail->AddAttachment($passarr['path']);
                }
            }

            $mail->AddAddress($to);
            if (!empty($bcc)) {
                foreach ($bcc as $bccemail) {
                    $mail->AddBCC($bccemail);
                }
            }

            $return = $mail->Send();
            return $return;
        } else if ($fromemail == 4) {
            $mail = new Phpmailer();
            $mail->IsSMTP(); // send via SMTP
            $mail->SMTPDebug = 1;
            $mail->Debugoutput = 'html';
            $mail->Host = AMAZON_HOST;
            $mail->Port = 587;
            $mail->SMTPSecure = 'tls';
            $mail->SMTPAuth = true;
            $mail->Username = AMAZON_USERNAME;
            $mail->Password = AMAZON_PASSWORD;

            $mail->From = $from;
            $mail->FromName = $fromname;
            $mail->Subject = $subject;
            if (!empty($attachments) && count($attachments) > 0) {
                foreach ($attachments as $passarr) {
                    $mail->AddAttachment($passarr['path']);
                }
            }
            $mail->msgHTML($html);
            $mail->AddAddress($to);
            if (!empty($bcc)) {
                foreach ($bcc as $bccemail) {
                    $mail->AddBCC($bccemail);
                }
            }
            $return = $mail->Send();
            return $return;
        }
    }
    
    /**
     * @name: sendSupplierNotification
     * @purpose: this function used to send notification to supplier when thier ticket sold from any channel.
     * @where: It is called from pos_model
     * @How it works: Receive data to send, setup the email parameters like to, from, fromname, subject etc then send email     
     * @params: 
     *      $arraylist - it contains the email related data like subject, attachments, sender email, send to whom, html of email etc
     * @returns: No parameter is returned
     */
    function sendSupplierNotification ($arraylist) {
        $this->load->library('Sqs');
        $this->load->library('Sns');
        $sqs_object = new Sqs();
        $sns_object = new Sns();         
        $supplier_email_request_data = [
            "data" => [
                "notification" => [
                    "notification_event" => $arraylist['notification_event'],
                    "notification_item_id" => [
                        "order_reference" => $arraylist['visitor_group_no'],
                        "booking_reference" => $arraylist['booking_reference'] ? [$arraylist['booking_reference']] : ""
                    ]
                ]
            ]
        ];  
        if (!empty(COMMON_EMAIL_QUEUE_URL) && !empty(COMMON_EMAIL_TOPIC_ARN)) {
            $supplier_email_request_message = base64_encode(gzcompress(json_encode($supplier_email_request_data)));
            $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $supplier_email_request_message);
            $this->CreateLog('supplier_email.php', 'step_1', array('MessageId' => $MessageId)); 
            if ($MessageId) {
                $sns_object->publish('email', COMMON_EMAIL_TOPIC_ARN);
            }
        }
    }
}
