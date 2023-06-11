<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Primarynewdb {

    var $db = NULL;

    function __construct() {
        $CI = &get_instance();
        $this->db = $CI->load->database('primarynew', TRUE);
    }
   
}
