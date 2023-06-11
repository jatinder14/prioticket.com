<?php 

namespace Prio\Traits\V1\Helpers;

trait Terrors_helper {

    /**
     * @Name        : error_specification()
     * @Purpose     : To return error message according to API specification.
     * @CreatedBy   : Prabhdeep <prabhdeep.intersoft@gmail.com> on 04 May 2019
     */
    public static function error_specification($error_code = [])
    {
        $error_specification['INVALID_REQUEST'] = array(
            'header' => '400 Bad Request',
            'error' => 'INVALID_REQUEST',
            'error_description' => 'The request is missing a required parameter, includes an unsupported parameter or parameter value, repeats the same parameter, uses more than one method for including an access token, or is otherwise malformed.'
        );
        $error_specification['AUTHORIZATION_FAILURE'] = array(
            'header' => '401 Authentication Failed',
            'error' => 'INVALID_TOKEN',
            'error_description' => 'The access token provided is expired, revoked, malformed, or invalid for other reasons. The resource SHOULD respond with the HTTP 401 (Unauthorized) status code.  The client MAY request a new access token and retry the protected resource request.'
        );
        $error_specification['INVALID_API'] = array(
            'header' => '400 Bad Request',
            'error' => 'INVALID_API',
            'error_description' => 'Requested API not valid or misspelled.'
        );
        $error_specification['VALIDATION_FAILURE'] = array(
            'header' => '400 Bad Request',
            'error' => 'VALIDATION_FAILURE',
            'error_description' => 'The request object contains inconsistent or invalid data or is missing data.'
        );
        $error_specification['PERMISSION_NOT_ALLOWED'] = array(
            'header' => '401 Authentication Failed',
            'error' => 'PERMISSION_NOT_ALLOWED',
            'error_description' => 'No permission is granted for accessing details.'
        );
        $error_specification['INVALID_TOKEN'] = array(
            'header' => '401 Authentication Failed',
            'error' => 'INVALID_TOKEN',
            'error_description' => 'The access token provided is expired, revoked, malformed, or invalid for other reasons. The resource SHOULD respond with the HTTP 401 (Unauthorized) status code.  The client MAY request a new access token and retry the protected resource request..'
        );
        $error_response = isset($error_specification[trim($error_code)]) ? $error_specification[trim($error_code)] : $error_specification['INVALID_REQUEST'];
        if (isset($error_response['header'])) {
            header('HTTP/1.0 ' . $error_response['header']);
        }
        return $error_response;
    }
}
?>