<?php 

namespace Prio\Traits\V1\Models;

trait THotel_model {
    
    var $loginUserId;
    var $userType;
    var $base_url;
    var $uname;
    var $emailcounter = 0;

    function __construct() 
    {
		///Call the Model constructor
		parent::__construct();
		$this->load->library('email');
		$this->load->library('CI_AdyenRecurringPayment');
		$this->load->model('V1/museum_model');
    }
    
    /************************************************ Common in hotel_model begins **************************************/
    
    function getLastVisitorGroupNo() {
		$visitor_group_no = round(microtime(true) * 1000); // Return the current Unix timestamp with microseconds
		return $visitor_group_no . '' . rand(0, 9) . '' . rand(0, 9); //add two random no's in the end of visitor_group_no 
    }
    
    /*
     * check if room no exists or not. If exists then return true else inserts entry for room no
     */
    function checkAndSaveRoom($roomno, $hotel_id) {
		if ($roomno != '' && $hotel_id != '') {
			$qry = 'select id from hotel_rooms where roomNo = "' . $roomno . '" and hotel_id=' . $hotel_id . '';
			$result = $this->primarydb->db->query($qry);
			if ($result->num_rows() > 0) {
			return true;
			} else {
			$insert = 'insert into hotel_rooms set roomNo = "' . $roomno . '", hotel_id=' . $hotel_id . '';
			$this->db->query($insert);
			return 'inserted';
			}
		}
    }           
}

?>