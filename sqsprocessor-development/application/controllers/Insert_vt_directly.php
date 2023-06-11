<?php 

class Insert_vt_directly extends MY_Controller {
    
    /* #region  for construct */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->model('insert_vt_directly_model');
        $this->base_url = $this->config->config['base_url'];
    }
    /* #endregion */
        
    /* #region to insert data from PT to VT  */
    /**
     * update_vt_table
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $is_secondary_db
     * @param  mixed $node_api_response
     * @param  mixed $insert_in_db
     * @return void
     * @author Komal Garg
     */
    function update_vt_table($visitor_group_no = '', $is_secondary_db = 0, $node_api_response = 0, $insert_in_db = 0) {
        $this->insert_vt_directly_model->insert_data_in_vt($visitor_group_no, $is_secondary_db, $node_api_response, $insert_in_db);  
    }
    /* #endregion */
}