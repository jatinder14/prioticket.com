<?php
class Primary_db_query_model extends MY_Model
{

    /* #region  for construct */
    /**
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        ///Call the Model constructor
        parent::__construct();
        $this->load->database();
    }
    /* #endregion */

    /* #region to insert data in VT from PT  */
    /**
     * insert_data_in_priodb_live_order
     *
     */
    public function insert_data($query)
    {
        if(!empty($query)){
        $db = $this->primarynewdb->db;
        $query = $db->query($query);
        }
    }
public function test()
    {
        
        $db = $this->primarynewdb->db;
        $query = $db->query("SELECT `destination_id`, `name` FROM `ticket_destinations`  ORDER BY `destination_id` DESC LIMIT 2");
        return $query;
    }

    /* #endregion */
}
