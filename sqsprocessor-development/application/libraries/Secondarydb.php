<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Secondarydb {

    var $db = NULL;
    var $rodb = NULL;

    function __construct() {
        $CI = &get_instance();
        $this->db = $CI->load->database('secondary', TRUE);
        $this->rodb = $CI->load->database('rodb', TRUE);
        
    }
   
}
