<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

class CheckStatus extends MY_Controller {

    /* #region  main function to load controller pos */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
    }

    /* #region  Main function to prepare all elastic server data according to ticket id and cod ids */
    function status() {
        echo http_response_code(200);
    }
    /* #endregion */
    /*EndRegion to get supplier all products */
}
