<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reporting extends MY_Controller {

    function __construct()
    {
        parent::__construct();
        $this->load->library('encryption');
        $this->payment_mode = 'live';
        $this->base_url = $this->config->config['base_url']; 
        
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');

        $this->load->library('PHPExcel');
        $this->load->Model('common_model');
        $this->load->Model('hotel_model');
        $this->load->Model('Reporting_model');
    }

    /**
     * @Name correct_visitor_tickets_record
     * @Purpose To insert the data in VT table
     * @CreatedBy: Pankaj Kumar<pankajk.dev@outlook.com>
     */
    function correct_visitor_tickets_record($visitor_group_no = '', $pass_no = '', $prepaid_ticket_id = '', $hto_id = '', $action_performed = '') {
        if ($visitor_group_no != '' || $pass_no != '') {
            // need to delete order bases on VGN except channel type 5, for channel type 5 pass number need to be send in params
            if ($pass_no == '') {
                // Delete enteries from DB2/DB4
                $this->secondarydb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                $this->fourthdb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                echo 'Deleted from VT of DB2 and DB4 <br/>';
            }
            $response = $this->Reporting_model->correct_visitor_tickets_record_model($visitor_group_no, $pass_no, $prepaid_ticket_id, $hto_id, $action_performed);
            echo 'Success';
        } else {
            echo "VGN or PassNo Not Found";
        }
    }

    /**
     * @name    : update_vt_directly()     
     * @created by: Pankaj Kumar <pankajk.dev@outlook.com> on 3 April, 2018
     */
    function update_vt_directly($limit = '10') {
        // get data from bulk_updated_orders of DB4
        $query = 'select DISTINCT visitor_group_no from bulk_updated_orders where is_data_moved = 0 order by id ASC limit ' . $limit;
        $result = $this->primarydb->db->query($query);
        $data = $result->result_array();

        foreach ($data as $vt_num) {
            $visitor_group_no = $vt_num['visitor_group_no'];

            if (!empty($visitor_group_no)) {
                // Delete enteries from DB2/DB4
                $this->secondarydb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                $this->fourthdb->db->query("delete from visitor_tickets where vt_group_no IN ('" . $visitor_group_no . "')");
                echo 'Deleted from VT of DB2 and DB4 <br/>';

                $this->Reporting_model->correct_visitor_tickets_record_model($visitor_group_no);
                echo 'Done';
                echo '<br/>';
            }

            $this->db->query('update bulk_updated_orders set is_data_moved = 1 where visitor_group_no =' . $visitor_group_no);
        }
    }


}
