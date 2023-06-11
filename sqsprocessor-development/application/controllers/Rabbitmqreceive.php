<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Rabbitmqreceive extends MY_Controller {
	
	function __construct() {
		
		parent::__construct();
	}
	
	function index() {
		
		$this->load->library( 'rabbitmq' );
		$this->rabbitmq->get_from_queue( 'prioticket_test' );
	}
}