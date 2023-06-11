<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Primarydb {

    var $db = NULL;
    var $db3 = NULL;

    function __construct() {
        $CI = &get_instance();
        $this->db = $CI->load->database('primary', TRUE);
        
    }
   
}
