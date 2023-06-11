<?php
/*
 * purpose : library to handle all exceptions and validations 
 *           Extending the default errors to always give JSON errors
 * created_by : Vaishali Raheja <vaishali.intersoft@gmail.com> 
 * created_on : 18 April 2019
 */

class Exception_handler {    
    
   /*
    * 400 Page Not Found Handler -> error in request 
    */
    function error_400($status = '', $errorCode = '', $errorMessage = ''){
        header('HTTP/1.1 400 Bad Request');
        $return = array(
            'status' => (isset($status)) ? (int) $status : 0,
            'errorCode' => (isset($errorCode) && $errorCode != '' ) ? (string) $errorCode : 'INVALID_REQUEST',
            'errorMessage' => (isset($errorMessage) && $errorMessage != '') ? (string) $errorMessage : 'Invalid or inconsistent request.'
        );
        return $return;
    }
    
   /*
    * 401 Access denied Handler -> incorrect password
    */
    function error_401($status = '', $errorCode = '', $errorMessage = '') {
        header('HTTP/1.0 401 Unauthorized');
        $return = array(
            'status' => (isset($status)) ? (int) $status : 0,
            'errorCode' => (isset($errorCode) && $errorCode != '' ) ? (string) $errorCode : 'AUTHORIZATION_FAILURE',
            'errorMessage' => (isset($errorMessage) && $errorMessage != '') ? (string) $errorMessage : 'The provided credentials are not valid.'
        );
        return $return;
    }

   /*
    * 402 -> already logged in user
    */
    function error_402(){
        header('HTTP/1.0 402 Unauthorized');
        $return = array(
            'status' => (int) 2,
            'errorCode' => 'ALREADY_LOGGED_IN',
            'errorMessage' => 'User is already logged in.'
        );
        return $return;
    }
    
   /*
    * 500 exception handler -> system failure or any exception in db queries.
    */
    function error_500($status = '', $errorCode = '', $errorMessage = ''){
        header('HTTP/1.0 500 Internal Server Error');
        $return = array(
            'status' => isset($status) ? $status : (int) 0,
            'errorCode' => isset($errorCode) && $errorCode != '' ? $errorCode : 'INTERNAL_SYSTEM_FAILURE',
            'errorMessage' => isset($errorMessage) && $errorMessage != '' ? $errorMessage : 'An error occurred that is unexpected and/or doesn\'t fit any of the types above.'
        );
        return $return;
    }
    
   /* 
    * General Error Page - if specific msgs are required to return then this function is used.
    * This function takes status (as int), error code (as string) and error message (as string) as input
    * and returns it using the specified template. 
    */
    function show_error($status = '', $errorCode = '', $errorMessage = ''){
        $msg_for_GL = '';
        if($status == ''){
            $status = 0;
        }
        if($errorCode == ''){
            $errorCode = 'VALIDATION_FAILURE';
        }
        if($errorMessage == ''){
            $errorMessage = "The request object contains inconsistent or invalid data or is missing data.";
        }
        if (stripos($errorMessage, 'Already Redeemed') !== false) {
            $msg_for_GL = "Pass Already Redeemed";
        }
        if (stripos($errorMessage, 'expired') !== false) {
            $msg_for_GL = "Pass has Already Expired";
        }
        $return = array(
            'status' => (int) $status,
            'errorCode' => (string) $errorCode,
            'errorMessage' => (string) $errorMessage,
            'errorMessageFor_GL' => ($msg_for_GL != '') ? $msg_for_GL : ''
        );
        return $return;
    }
    
}
?>
