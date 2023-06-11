<?php

namespace Prio\Traits\V1\Helpers;
trait Tcommon_error_specification_helper
{

    /**
     * @Name        : error_specification()
     * @Purpose     : To return error message according to API specification.
     * @CreatedBy   : Prabhdeep <prabhdeep.intersoft@gmail.com> on 04 May 2019
     */
    static function error_specification($error_code = [], $api = "supplier_redeem_v3_1")
    {
        if ($api == 'affiliates') {
            $error_specification['INVALID_REQUEST'] = array(
                'header' => '400 Bad Request',
                'error' => 'INVALID_REQUEST',
                'error_reference' => ERROR_REFERENCE_NUMBER,
                'error_message' => 'Something went wrong. Please reload the page or try again later.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_specification['INVALID_VOUCHER_BLOCK_CODE'] = array(
                'header' => '422 Unprocessable Entity',
                'error' => 'INVALID_VOUCHER_BLOCK_CODE',
                'error_message' => 'This voucher block is not availble for allocate.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request was well-formed but was unable to be followed due to semantic errors.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_specification['INVALID_VOUCHER_BATCH'] = array(
                'header' => '422 Unprocessable Entity',
                "error" => "INVALID_VOUCHER_BATCH",
                "error_reference" => "3020260098743",
                "error_message" => "Something went wrong. Please reload the page or try again later.",
                "error_description" => "The request was well-formed but was unable to be followed due to semantic errors.",
                "error_uri" => "https://support.prioticket.com/docs/",
                "errors" => [
                    "The specified voucher batch number or requested block is not in a valid state, does not exist or is not linked to the account. "
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        } else if ($api == 'partners') {
            $error_specification['INVALID_REQUEST'] = array(
                'header' => '400 Bad Request',
                'error' => 'INVALID_REQUEST',
                'error_reference' => ERROR_REFERENCE_NUMBER,
                'error_message' => 'Something went wrong. Please reload the page or try again later.',
                'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.',
                'error_uri' => 'https://support.prioticket.com/docs/'
            );
            $error_specification['INVALID_PARTNER_ID'] = array(
                'header' => '422 Unprocessable Entity',
                'error' => 'INVALID_PARTNER_ID',
                'error_reference' => ERROR_REFERENCE_NUMBER,
                'error_message' => 'This patner_id is not linked with the distributor_id.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request was well-formed but was unable to be followed due to semantic errors.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        } elseif ($api == 'voucher_block_details') {
            $error_specification['INVALID_REQUEST'] = array(
                'header' => '400 Bad Request',
                'error' => 'INVALID_REQUEST',
                'error_reference' => ERROR_REFERENCE_NUMBER,
                'error_message' => 'Something went wrong. Please reload the page or try again later.',
                'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.',
                'error_uri' => 'https://support.prioticket.com/docs/'
            );
            $error_specification['INVALID_PASS_ID'] = array(
                'header' => '422 Unprocessable Entity',
                'error' => 'INVALID_PASS_ID',
                'error_message' => 'This patner_id is not linked with the distributor_id.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request was well-formed but was unable to be followed due to semantic errors.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        } elseif ($api == 'voucher_block_listing') {
            $error_specification['INVALID_BATCH_REFRENCE'] = array(
                'header' => '422 Unprocessable Entity',
                'error' => 'INVALID_BATCH_REFRENCE',
                'error_message' => 'Batch refrence is not valid.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request was well-formed but was unable to be followed due to semantic errors.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );

            $error_specification['INVALID_REQUEST'] = array(
                'header' => '400 Bad Request',
                'error' => 'INVALID_REQUEST',
                'error_message' => 'Request is not valid.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        } elseif ($api == 'token') {
            $error_specification['INVALID_TOKEN'] = array(
                'header' => '400 Bad Request',
                'error' => 'INVALID_TOKEN',
                'error_message' => 'Something went wrong. Please reload the page or try again later.',
                'error_uri' => 'https://support.prioticket.com/docs/prioticket-distributor-booking-api-v3-2',
                'error_description' => 'The access token provided is expired, revoked, malformed, or invalid for other reasons. The resource SHOULD respond with the HTTP 401 (Unauthorized) status code.  The client MAY request a new access token and retry the protected resource request.',
                'errors' => [
                    'The specified voucher block is not in a valid state, does not exist or is not linked to the account.'
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        } else if($api == 'shift_report'){
            $error_specification['INVALID_REQUEST'] = array(
                'header' => '404 Not Found',
                'error' => 'NO_BOOKING_FOUND',
                'error_reference' => ERROR_REFERENCE_NUMBER,
                'error_message' => 'There are no bookings.',
                'error_uri' => 'https://support.prioticket.com/docs/',
                'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.',
                'errors' => [
                    "There are no booking to show."
                ]
            );
            $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
            return $error_response;
        }
    }
}

/* End of file file_helper.php */
/* Location: ./system/helpers/file_helper.php *////
