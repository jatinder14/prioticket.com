<?php

namespace Prio\Traits\V1\Helpers;

trait Tmpos_auth_helper {

    public static function verify_token($token, $cashier_id = 0){
        //split token to get kid from headers
        $token_parts = explode('.', $token);
        $token_headers = json_decode(base64_decode($token_parts[0]));
        $kid = $token_headers->kid;
        if($kid != '') {
            //get all public keys with curl
            $url = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            $res = curl_exec($ch);
            curl_close($ch);
            $get_all_keys = json_decode($res);
            //public key to decode the token
            $public_key = $get_all_keys->$kid;
            //decode jwt token and check if it belongs to same cashier
            try {
                $decoded = \JWT::decode($token, $public_key, array('RS256'));
                $decoded_array = (array) $decoded;
                if($decoded_array['aud'] != FIREBASE_PROJECT_ID || $decoded_array['iss'] != 'https://securetoken.google.com/'.FIREBASE_PROJECT_ID) {
                    $response['status'] = 0;
                    $response['errorCode'] = 'VALIDATION_FAILURE';
                    $response['errorMessage'] = 'This token does not belongs to our project.';
                }
                elseif($cashier_id != $decoded_array['cashierId']) {
                    $response['status'] = 0;
                    $response['errorCode'] = 'AUTHORIZATION_FAILURE';
                    $response['errorMessage'] = 'This token does not belongs to '.$cashier_id.' cashier.';
                } else {
                    $response['status'] = 1;
                    $response['user_details'] = $decoded_array; 
                    if($decoded_array['cashierType'] == 3) {
                        $response['user_details']['distributorId'] = $decoded_array['defaultDistributorId'];
                        $response['user_details']['cashierId'] = $decoded_array['defaultCashierId'];
                        $response['user_details']['resellerCashierId'] = $decoded_array['cashierId'];
                    } 
                }

            } catch (\Exception $e) {
                if($e->getMessage() == 'Expired token'){
                    $response['status'] = 16;
                    $response['errorCode'] = 'TOKEN_EXPIRED';
                    $response['errorMessage'] = 'Access token expired';
                } else {
                    $response['status'] = 0;
                    $response['errorCode'] = 'AUTHORIZATION_FAILURE';
                    $response['errorMessage'] = 'Invalid token';
                }
            } 
        } else {
            $response['status'] = 0;
            $response['errorCode'] = 'VALIDATION_FAILURE';
            $response['errorMessage'] = 'Invalid token';
        }
        return $response;
    }

    /**
     * get access token from header
     * */
    public static function getBearerToken() {
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        }
        else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } else if (isset($_SERVER['AUTHORIZATION'])) { 
            $headers = trim($_SERVER["AUTHORIZATION"]);
        }
        else if (isset($_REQUEST['access_token'])) {
             $headers = trim($_REQUEST['access_token']);
        }
        elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            } else {
                return $headers;
            }
        }
        return null;
    }
} 
?>