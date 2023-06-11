<?php

/**
 * used to get css, js using cdn 
 * cdn
 * @access	public
 * @return	string
 */
if (!function_exists('get_tax_value')) {
    /**
    * get_tax_value
    *
    * This function get tax name on the basis of hotel's country and tax value.
    *
    * @access   public
    * @param	int $tax - Tax %
    * @param	int $hotel_id - Distributor hotel id	 
    * @return	Tax name with %
    * @author   Priya Aggarwal <priya.intersoft1@gmail.com> on Jan 08, 2019
    */
    function get_tax_value($hotel_id = 0, $country_code = "") {
        $ci =& get_instance();
        if(empty($country_code)) {
            /* Code to get distributor country details */
            $ci->primarydb->db->select('country_code');
            $ci->primarydb->db->from('qr_codes');
            if(!empty($hotel_id)){
                $ci->primarydb->db->where('cod_id',$hotel_id);
            }
            $query = $ci->primarydb->db->get();
            $country_code =  $query->row()->country_code;
            if(empty($country_code)){
                $country_code = 'NL';
            }
        }
        /* Code to get currency details */
        $ci->primarydb->db->select('id,tax_name,tax_value');
        $ci->primarydb->db->from('store_taxes');
        $ci->primarydb->db->where('country_code',$country_code);
        $query = $ci->primarydb->db->get();
        $result =  $query->result_array();
        global $tax_result ;
        if($query->num_rows() > 0 ) {
            foreach($result as $row){
                $tax_result[$row['tax_value']] = $row;
            }
        }
        return $tax_result;
    }
}