<?php
class Common_model extends My_Model {

    /* #region  Boc of common_model */
    var $base_url;
    
    /* #region  main function to load Common_model */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->library('Hash');
        if (isset($this->session->userdata['id'])) {            
            $this->loginUserId = $this->session->userdata['id'];
            $this->userType = $this->session->userdata('user_type');
            $this->uname = $this->session->userdata('uname');
            $this->fname = $this->session->userdata['fname'];
           
        }
        $this->base_url = $this->config->config['base_url'];
        $this->imageDir = $this->config->config['imageDir'];
        $this->root_path = $this->config->config['root_path'];
    }
    /* #endregion main function to load Common_model*/

    /* #region function Used to get array from object */
    /**
     * object_to_array
     *
     * @param  mixed $data
     * @return void
     */
    function object_to_array($data) {
        if (is_array($data) || is_object($data)) {
            $result = array();
            foreach ($data as $key => $value) {
                $result[$key] = $this->object_to_array($value);
            }
            return $result;
        }
        return $data;
    }
    /* #endregion function Used to get array from object*/
    
    /* #region Generic function to sort multidimentional array based on value  */
    /**
     * array_orderby
     *
     * @return void
     * @author Davinder singh <davinder.intersoft@gmail.com> on July 30, 2015
     */
    function array_orderby() {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }
    /* #endregion Generic function to sort multidimentional array based on value*/
       
    /* #region getSingleFieldValueFromTable  */
    /**
     * getSingleFieldValueFromTable
     *
     * @param  mixed $field
     * @param  mixed $tbl
     * @param  mixed $where
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getSingleFieldValueFromTable($field, $tbl, $where = '', $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else {
            $db = $this->primarydb->db;
        }
        $db->Select($field);
        $db->from($tbl);
        if ($where != '') {
            $db->where($where);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {
            $res = $query->row();
            return $res->$field;
        } else {
            return false;
        }
    }
    /* #endregion getSingleFieldValueFromTable*/
    
    /* #region getmultipleFieldValueFromTable  */
    /**
     * getmultipleFieldValueFromTable
     *
     * @param  mixed $field
     * @param  mixed $tbl
     * @param  mixed $where
     * @param  mixed $is_secondary_db
     * @param  mixed $option
     * @param  mixed $group_by
     * @param  mixed $where_in
     * @param  mixed $where_in_field
     * @param  mixed $order_by
     * @param  mixed $where_not_in
     * @param  mixed $where_not_in_field
     * @return void
     */
    function getmultipleFieldValueFromTable($field, $tbl, $where = '', $is_secondary_db = "0",$option = '', $group_by='', $where_in = '', $where_in_field = '', $order_by = '', $where_not_in = '', $where_not_in_field = '') {
        $db = $this->primarydb->db;
        $db->Select($field);
        $db->from($tbl);
        if ($where != '') {
            if(is_array($where)) {
                $db->where($where);
            } else {
                $db->where($where,NULL,FALSE);
            }
        }
        if(!empty($where_in)){
            $db->where_in($where_in_field, $where_in);
        }
        if($where_not_in !=''){
            $db->where_not_in($where_not_in_field, $where_not_in);
        }
        if($group_by != ''){
            $db->group_by($group_by);
        }
        if($order_by != ''){
            $db->order_by($order_by);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {
            if($option == 'row_array'){
                return $query->row_array();
            }else if ($option == 'list') {
                $data = array();
                $results = $query->result_array();
                foreach ($results as $row) {
                    $keys = array_keys($row);
                    $data[$row[$keys[0]]] = $row[$keys[1]];
                }
            }else if($option == 'result_array'){
                return $query->result_array();
            }else if($option == 'result'){
                return $query;
            }else{
                $res = $query->row();
                return $res;
            }
        } else {
            return array();
        }
    }
    /* #endregion getmultipleFieldValueFromTable*/
    
    /* #region getSingleRowFromTable  */
    /**
     * getSingleRowFromTable
     *
     * @param  mixed $tbl
     * @param  mixed $where
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getSingleRowFromTable($tbl, $where = array(), $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $db->Select('*');
        $db->from($tbl);
        if ($where != '') {
            $db->where($where);
        }
        $query = $db->get();
        if ($query->num_rows() > 0) {
            $res = $query->row();
            return $res;
        } else {
            return false;
        }
    }
    /* #endregion getSingleRowFromTable*/
    
    /* #region getGenericServiceValues  */
    /**
     * getGenericServiceValues
     *
     * @param  mixed $service_id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function getGenericServiceValues($service_id = '', $is_secondary_db = "0") {
        $db = $this->primarydb->db;
        if ($service_id != '') {
            $db->select('*');
            $db->from('services');
            $db->where('id', $service_id);
            $query = $db->get();
        }
        if ($query->num_rows() > 0) {
            $result = $query->row();
            return $result;
        } else {
            return false;
        }
    }
    /* #endregion getGenericServiceValues*/
    
    /* #region getGenericServiceValues  */
    /**
     * getCurrencySymbolFromHexCode
     *
     * @param  mixed $hexCode
     * @return void
     */
    function getCurrencySymbolFromHexCode($hexCode) {
        $currencySymbol = '&#x' . $hexCode;
        $currencySymbol = str_replace(', ', ';&#x', $currencySymbol);
        return $currencySymbol . ';';
    }
    /* #endregion getGenericServiceValues*/
    
    /* #region getAllInvitedUserOfCompany   */
    /**
     * getAllInvitedUserOfCompany
     *
     * @param  mixed $cod_id
     * @param  mixed $cashier_type
     * @return void
     */
    function getAllInvitedUserOfCompany($cod_id, $cashier_type = '') {
        if ($cashier_type == 1) {
            $st = "racacu.cod_id=" . $cod_id . " AND (u.user_type='hotelmerchantuser' OR u.user_type='normal')";
        } else {
            $st = "racacu.cod_id=" . $cod_id . " AND (u.user_type='museummerchantuser' OR u.user_type='normal')";
        }
        $this->primarydb->db->select("u.id as user_id, u.uname as email, u.fname, u.lname, u.password, u.user_type as loggedInUserType");
        $this->primarydb->db->from('users u');
        $this->primarydb->db->join("rel_addinfo_cod_author_content_user racacu", "racacu.usr_id = u.id");
        $this->primarydb->db->where($st, NULL, FALSE);
        $this->primarydb->db->where('u.deleted', "0");
        $this->primarydb->db->order_by('u.id', 'desc');
        $query1 = $this->primarydb->db->get();
        if ($query1->num_rows() > 0) {
            $result = $query1->result_array();
            return $result;
        } else {
            return false;
        }
    }
    /* #endregion getAllInvitedUserOfCompany*/
        
    /* #region get_all_cashiers  */
    /**
     * get_all_cashiers
     *
     * @param  mixed $cod_id
     * @return void
     */
    function get_all_cashiers($cod_id) {
        $st = "racacu.cod_id=" . $cod_id . " AND (u.user_type='hotelmerchantuser' OR u.user_type='normal')";
        $this->primarydb->db->select("u.id as user_id ,u.allow_cancel_orders, u.allow_order_discount, u.allow_order_overview, u.allow_redeem_orders, u.allow_sell_tickets,u.user_role, u.default_supplier_cashier");
        $this->primarydb->db->from('users u');
        $this->primarydb->db->join("rel_addinfo_cod_author_content_user racacu", "racacu.usr_id = u.id");
        $this->primarydb->db->where($st, NULL, FALSE);
        $this->primarydb->db->where('u.deleted', "0");
        $this->primarydb->db->where('user_type = "hotelmerchantuser" or user_type = "normal"');
        $this->primarydb->db->order_by('u.id', 'desc');
        $query1 = $this->primarydb->db->get();
        if ($query1->num_rows() > 0) {
            $result = $query1->result_array();
            return $result;
        } else {
            return false;
        }
    }
    /* #endregion get_all_cashiers*/
    
    /* #region convert_time_into_user_timezone  */
    /**
     * convert_time_into_user_timezone
     *
     * @param  mixed $time
     * @param  mixed $timezone
     * @param  mixed $action
     * @param  mixed $type
     * @return void
     */
    function convert_time_into_user_timezone($time = '', $timezone = '', $action = '', $type = '') {
        // When get the values from database
        if ($action == '0') {
            
        } else if ($action == '1') { // When save values into database
            $timezone = -($timezone);
        }
        if (strstr($time, '-')) {
            $time = str_replace('-', '', $time);
        }

        $time = strtotime($time) + ($timezone * 60 * 60);
        if ($time < strtotime('00:00:00')) {
            $time = '-' . date('H:i', $time);
        } else {
            $time = date('H:i', $time);
        }
        return $time;
    }
    /* #endregion convert_time_into_user_timezone*/
        
    /* #region companyName  */
    /**
     * companyName
     *
     * @param  mixed $codId
     * @param  mixed $is_secondary_db
     * @return void
     */
    function companyName($codId, $is_secondary_db = 0) {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $db->Select('*');
        $db->from('qr_codes');
        $db->where('cod_id', $codId);
        $query = $db->get();
        $data = $query->row();
        return $data;
    }
    /* #endregion companyName*/
        
    /* #region selectPartnertaxes  */
    /**
     * selectPartnertaxes
     *
     * @param  mixed $taxtype
     * @param  mixed $service_id
     * @param  mixed $cod_id
     * @param  mixed $tax_id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function selectPartnertaxes($taxtype = '0', $service_id = '', $cod_id = '', $tax_id = '', $is_secondary_db=0) {
        $db = $this->primarydb->db;
        if ($cod_id != "") {
            $query = 'select * from tax_names_for_sales_distributor where cod_id=' . $cod_id . '';

            if (is_array($tax_id) && !empty($tax_id)) {
                $query.=' and tax_id IN(' . "'" . implode("','", $tax_id) . "'" . ') and tax_name!=""';
            } else if ($tax_id != '') {
                $query.=' and tax_id = ' . $tax_id . ' and tax_name!=""';
            }
            $res = $db->query($query);
        }
        if ($cod_id != "" && $res->num_rows() > 0) {
            if (is_array($tax_id)) {
                return $res->result();
            } else {
                if ($res->num_rows() == 1) {
                    return $res->row();
                } else {
                    return $res->result();
                }
            }
        } else {
            $query = 'select * from store_taxes';
            if ($taxtype == '0') {
                $query.=' where tax_type="0"';
            } else if ($taxtype == '1') {
                $query.=' where tax_type="1"';
            }
            if (is_array($tax_id) && !empty($tax_id)) {
                $query.=' and id IN(' . "'" . implode("','", $tax_id) . "'" . ')';
            } else if ($tax_id != '') {
                $query.=' and tax_id = ' . $tax_id;
            }
            if ($service_id != '') {
                $query.=' and service_id=' . $service_id;
            }
            $res = $db->query($query);
            if ($res->num_rows() > 0) {
                if (is_array($tax_id)) {
                    return $res->result();
                } else {
                    if (!empty($tax_id) || $tax_id != '') {
                        return $res->row();
                    } else {
                        return $res->result();
                    }
                }
            } else {
                return false;
            }
        }
    }
    /* #endregion selectPartnertaxes*/
        
    /* #region seldealeventimage  */
    /**
     * seldealeventimage
     *
     * @param  mixed $tbl
     * @param  mixed $field
     * @param  mixed $item_id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function seldealeventimage($tbl, $field, $item_id, $is_secondary_db = "0") {
        if ($is_secondary_db == 1) {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $db->Select('*');
        $db->from($tbl);
        $db->where($field, $item_id);
        $query = $db->get();
        if ($query->num_rows() > 0) {
            return $query->row();
        } else {
            return false;
        }
    }
    /* #endregion seldealeventimage*/
       
    /* #region to format the obtained value of timezone into standard format  */
    /**
     * formatted_timezone
     *
     * @param  mixed $timezone
     * @return void
     * @author Aftab Raza <aftab.aipl@gmail.com> on 24 June, 2019
     */
    function formatted_timezone($timezone = '') {
        if (!empty($timezone)) { 
            $split_timezone = str_split($timezone, 1);
            if($split_timezone[0] != '+' && $split_timezone[0] != '-') { 
                $timezone = '+'.$timezone;
            } 
            $sign_of_timezone = str_split($timezone, 1)[0]; 
            $timezone = substr($timezone, 1); 
            $explode_timezone = explode(':', $timezone);
            if (count($explode_timezone) == 1) { 
                $explode_unformated = explode('.', $timezone);
                if (count($explode_unformated) > 1) {
                    $into_minutes = '0.'.$explode_unformated[1];
                    $explode_unformated[1] = round($into_minutes*60);
                } else {
                        $explode_unformated[1] = '00';
                } 
                if (strlen($explode_unformated[0]) == 1) {
                    $explode_unformated[0] = '0'.$explode_unformated[0];
                } 
                $new_timezone = $sign_of_timezone.$explode_unformated[0].':'.$explode_unformated[1];
                return $new_timezone;
            } else if (count($explode_timezone) > 1) {
                if (strlen($explode_timezone[0]) == 1) {
                    $explode_timezone[0] = '0'.$explode_timezone[0];
                }
                $new_timezone = $sign_of_timezone.$explode_timezone[0].':'.$explode_timezone[1];
                return $new_timezone;
            }
        }
    }
    /* #endregion to format the obtained value of timezone into standard format*/
       
    /* #region cURL hit for getting constants from API and hit to arena.   */
    /**
     * curl_request_for_arena
     *
     * @param  mixed $params
     * @return void
     * @author  Aftab Raza <aftab.aipl@gmail.com> on 27 June, 2019
     */
    function curl_request_for_arena($params = array()) {        
        /* initating cURL request at API OR Arena server */        
        /* timeout for cURL request assigning */
        $username = $params['username'];
        $password = $params['password'];
        $timeout = isset($params['timeout']) ? $params['timeout'] : 4000;
        $ch = curl_init();
        if (count($params['request'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params['request']));
        }
        curl_setopt($ch, CURLOPT_URL, $params['end_point']);
        if(!empty($username && $password)){ 
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        } else { 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $params['headers']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $params['method']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);
        $curl_response = curl_exec($ch);
        /* Get response from third party end */
        $curl_info = curl_getinfo($ch);
        $curl_error = curl_strerror(curl_errno($ch));
        curl_close($ch);
        return array('response' => json_decode($curl_response, TRUE), 'curl_info' => $curl_info, 'curl_error' => $curl_error, 'request' => $params['request']);
    }
    /* #endregion cURL hit for getting constants from API and hit to arena.*/
        
    /* #region changeIntoUsersNumberFormatwithzerosbig  */
    /**
     * changeIntoUsersNumberFormatwithzerosbig
     *
     * @param  mixed $amount
     * @param  mixed $numberformat
     * @param  mixed $addcomma
     * @return void
     */
    function changeIntoUsersNumberFormatwithzerosbig($amount = 0, $numberformat = 1, $addcomma = 'no') {
        if ($numberformat == 1) {
            $decimalSeparator = '.';
            $thousandSeparator = ',';
        } else {
            $decimalSeparator = ',';
            $thousandSeparator = '.';
        }

        if ($amount > 0) {
            $amount = number_format($amount, 2, '.', '');
            $amount2 = explode('.', $amount);
            $amount2Decimal = $amount2[1];
            $amount2Int = $amount2[0];
            $amount2Int = number_format($amount2Int, 0, '', $thousandSeparator);
            $amount2 = $amount2Int;
            if ($addcomma == 'no' || $addcomma == '') {
                if ($amount2Decimal != '') {
                    $amount2 .= $decimalSeparator . $amount2Decimal;
                }
            } else if ($addcomma == 'yes') {
                if ($amount2Decimal == '00') {
                    $amount2.=',-';
                } else {
                    $amount2 .= $decimalSeparator . $amount2Decimal;
                }
            }
            return $amount2;
        } else {
            $amount = number_format($amount, 2, '.', '');
            $amount2 = explode('.', $amount);
            $amount2Decimal = $amount2[1];
            $amount2Int = $amount2[0];
            $amount2 = $amount2Int;
            if ($amount2Decimal != '') {
                $amount2 .= $decimalSeparator . $amount2Decimal;
            } else if ($addcomma == 'yes') {
                $amount2.=',-';
            }
            return $amount2;
        }
    }
    /* #endregion changeIntoUsersNumberFormatwithzerosbig*/
    /* #region get_pickups_data  */
    /**
     * get_pickups_data
     *
     * @param  mixed $rel_target_ids
     * @return void
     */
    function get_pickups_data($rel_target_ids = array()) {
        $return = array();
        if (!empty($rel_target_ids)) {
            $db = $this->primarydb->db;
            $db->select('target_id, name, targetlocation');
            $db->from('rel_targetvalidcities');
            $db->where_in('target_id', $rel_target_ids);
            $query = $db->get();
            if ($query->num_rows() > 0) {
                $result = $query->result_array();
                foreach ($result as $results) {
                    $return[$results['target_id']] = $results;
                }
            }
        }
        return $return;
    }
    /* #endregion Boc of common_model*/

      /**
     * @name: getExtraOptionsForCash
     * @purpose: To get prepaid tickets id's of particular order
     * @where: It is called from hotel controller
     * @params: visitor_group_no - need to get ticket id's of particular order
     * @returns: array of ticket id's
     */
    /* usedData */
    function getExtraOptions($visitor_group_no, $table = 'prepaid_extra_options')
    {
        if ($table == 'prepaid_extra_options') {
            $db = $this->secondarydb->rodb;
        } else {
            $db = $this->primarydb->db;
        }
        $query = 'select *, sum(quantity) as main_quantity from ' . $table . ' where visitor_group_no = ' . $visitor_group_no;        
        $query .= ' group by ticket_price_schedule_id, description, price, selected_date, from_time order by description';
        $data = $db->query($query);
        if ($data->num_rows() > 0) {
            $result = $data->result_array();
        } else {
            $result = [];
        }
        return $result;
    }
    
    /* #region  Checks if a product is being sold by an MMT distributor */	
	/**
	 * is_mmt_product
	 *
	 * @param  mixed $product_id - ID of product which is checked if it is being sold by an MMT distributor
	 * @return int 0->Product not being sold by MMT distributor; 1->Product being sold by MMT distributor
	 * @author Rohan Singh Chauhan <rohan.aipl@gmail.com> on 19th March, 2021
	 */
	function is_mmt_product($product_id)
	{
		/* #region  BOC of is_mmt_product */
		return $this->primarydb->db
			->select('pos_ticket_id')
			->from('pos_tickets pos')
			->join('modeventcontent mec', 'pos.mec_id = mec.mec_id')
			->where('pos.mec_id', $product_id)
			->where('pos.hotel_id', MMT_DISTRIBUTOR)
			->where('pos.is_pos_list', 1)
			->where('mec.product_visibility', 1)
			->where('mec.deleted', '0')
			->where('pos.deleted', 0)
			->where('mec.endDate >= ', time())
			->get()->num_rows();
		/* #endregion EOC of is_mmt_product */
	}
	/* #endregion Checks if a product is being sold by an MMT distributor */

}
