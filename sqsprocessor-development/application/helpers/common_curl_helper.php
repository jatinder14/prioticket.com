<?php  
 final class Common_curl
 {
     
     /**
     * @Name        : send_request()
     * @Purpose     : To send curl request.
     *  * 20 Sept, 2021
     * CreatedBy Kavita <kavita.aipl@gmail.com>
     */
     static function send_request($url = '', $method = 'GET', $post_data = [], $request_headers = [], $other_checks = [])
     {
        $response = [];
            if (!empty($url) && !empty($request_headers)) {
                try {
                    $ch = curl_init();
                    $ssl = (!empty($other_checks['ssl_verifypeer']) && $other_checks['ssl_verifypeer'] == false) ? $other_checks['ssl_verifypeer'] : true;
                    $curl_info  = [];
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    /* If not empty post data  then send post fields */
                    if (!empty($post_data)) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
                    }
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl);
                    if (!empty($other_checks['binary_transfer'])){
                        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
                    }
                    /* This block is used to set curl timeout in common request */
                    if (!empty($other_checks['curl_timeout']) && !empty($other_checks['curl_timeout_value']) && is_numeric($other_checks['curl_timeout_value'])) {
                        if (!empty($other_checks['timeout_type']) && $other_checks['timeout_type'] == 'seconds') {
                            if (empty($other_checks['is_direct_call'])) {
                                curl_setopt($ch, CURLOPT_TIMEOUT, $other_checks['curl_timeout_value'] + 3);
                            } else {
                                curl_setopt($ch, CURLOPT_TIMEOUT, $other_checks['curl_timeout_value']);
                            }
                        } else {
                            if (empty($other_checks['is_direct_call'])) {
                                curl_setopt($ch, CURLOPT_TIMEOUT_MS, $other_checks['curl_timeout_value'] + 100);
                            } else {
                                curl_setopt($ch, CURLOPT_TIMEOUT_MS, $other_checks['curl_timeout_value']);
                            }
                        }
                    }
                    /* This block use if any third party use Any basic Authorization and send all params use in authrization */
                    if (!empty($other_checks['basic_auth']) && !empty($other_checks['auth_user'])) {
                        $auth_key = !empty($other_checks['auth_key']) ? $other_checks['auth_key'] : '';
                        curl_setopt($ch, CURLOPT_USERPWD, $other_checks['auth_user'] . ":" . $auth_key);
                        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    }
                    /* use to set curl encoding id third party send its reuire value */
                    if (!empty($other_checks['curl_encoding']) && !empty($other_checks['curl_encoding_value'])) {
                        curl_setopt($ch, CURLOPT_ENCODING, $other_checks['curl_encoding_value']);
                    }
                    /* this block use for multi redirection */
                    if (!empty($other_checks['multi_redirection'])) {
                        $multi_val = !empty($other_checks['multi_redirection_value']) ? $other_checks['multi_redirection_value'] : 5;
                        curl_setopt($ch, CURLOPT_COOKIEJAR, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_POSTREDIR, $multi_val);
                    }
                    $response_tp = curl_exec($ch);
                    $curl_info = curl_getinfo($ch);
                    $response['curl_error'] = curl_strerror(curl_errno($ch));
                    $response['response'] = $response_tp;
                    $response['curl_status'] = $curl_info['http_code'];
                } catch (Exception $e) {
                    $response['error'] = $e->getMessage();
                    return json_encode($response, true);
                }
                curl_close($ch);
            } else {
                $response['curl_status'] = 400;
            }
        return $response;
     }
 }
