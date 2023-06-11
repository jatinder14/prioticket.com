<?php

/**
 * product Sorting Updation in pos ticket Class
 * 
 * @access   public
 * @package  Controller
 * @category Controller
 * @author   Krishna Chaturvedi <krishnachaturvedi.aipl@gmail.com> on 15 Sept, 2021
 */

class Product_sorting_model extends My_Model {

    /* #region  main function to load Common_model */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();        
    }

    /**
     *
     * @called from Product_sorting_pos/update_product_sorting_order
     * @author Krishna Chaturvedi <krishnachaturvedi.aipl@gmail.com> on Sept 15, 2021
	*/
    public function delete_fetch_sort($type_id, $type, $value)
    {
        $this->primarydb->db->select('sort_order, product_id');
        $this->primarydb->db->from('product_sorting_order');
        $this->primarydb->db->where(array("type_id" => $type_id, "type" => $type, "deleted" => '0'));
        $this->primarydb->db->where_in('product_id', $value);
        $sortOrderQuery = $this->primarydb->db->get();
        if($sortOrderQuery->num_rows() > 0)
        {
            return $sortOrderQuery->result_array();    
        }
        return false;
    }
    /* #endregion main function to load Common_model*/
}