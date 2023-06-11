<?php
class Hotel_Model extends MY_Model {

    /* #region  Boc of hotel_model */
    var $loginUserId;
    var $userType;
    var $base_url;
    var $uname;
    var $emailcounter = 0;
    
    /* #region main function to load Hotel_Model */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
        
        $this->load->library('email');
        //$this->load->library('encrypt');  
        $this->load->library('AdyenRecurringPayment');
        $this->load->library('log_library');
        $this->lang->load('qr', '');

        $this->load->helper('language');
        $this->load->library('session');
        if (isset($this->session->userdata['id'])) {
            $this->loginUserId = $this->session->userdata['id'];
            $this->userType = $this->session->userdata('user_type');
            $this->uname = $this->session->userdata('uname');
        }
        $this->load->model('common_model');
        $this->load->model('sendemail_model');
        $this->load->model('multi_currency_model');

        $this->base_url = $this->config->config['base_url'];
        $this->root_path = $this->config->config['root_path'];
        $this->imageDir = $this->config->config['imageDir'];
        $this->merchant_price_col = 'merchant_price';
        $this->merchant_net_price_col = 'merchant_net_price';
        $this->merchant_tax_id_col = 'merchant_tax_id';
        $this->merchant_currency_code_col = 'merchant_currency_code';
        $this->supplier_tax_id_col = 'supplier_tax_id';
        $this->admin_currency_code_col = 'admin_currency_code';
    }
    /* #endregion main function to load Hotel_Model*/
    
    /* #region getAllInvitedUserOfCompany  */
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
        $this->primarydb->db->select("u.uname as email, u.fname, u.lname, u.password");
        $this->primarydb->db->from('users u');
        $this->primarydb->db->join("rel_addinfo_cod_author_content_user racacu", "racacu.usr_id = u.id");
        $this->primarydb->db->where($st, NULL, FALSE);
        $this->primarydb->db->where('u.deleted', "0");
        $this->primarydb->db->order_by('u.id', 'desc');
        $query1 = $this->primarydb->db->get();
        if ($query1->num_rows() > 0) {
            return $query1->result_array();
        } else {
            return false;
        }
    }
    /* #endregion getAllInvitedUserOfCompany*/
    
    /* #region auth  */
    /**
     * auth
     *
     * @param  mixed $ref
     * @param  mixed $amount
     * @param  mixed $merchantRef
     * @param  mixed $shopperRef
     * @param  mixed $merchantAcountCode
     * @param  mixed $currencyCode
     * @param  mixed $parentPassNo
     * @param  mixed $shopperStatement
     * @return void
     */
    function auth($ref = "LATEST", $amount = '', $merchantRef = '', $shopperRef = '', $merchantAcountCode = '', $currencyCode = '', $parentPassNo = '', $shopperStatement = '') {

        $oREC = new CI_AdyenRecurringPayment(false);
        $amount = ($amount * 100);
        //do a recurring Payment - get Status or errorMessage        
        
        $oREC->startSOAP("Payment");     
        $oREC->authorise($ref, $amount, $merchantRef, $shopperRef, $merchantAcountCode, $currencyCode, $parentPassNo, $shopperStatement);
        return $oREC->response->paymentResult;
    }
    /* #endregion auth*/
    
    /* #region getLastVisitorGroupNo  */
    /**
     * getLastVisitorGroupNo
     *
     * @return void
     */
    function getLastVisitorGroupNo() {
        $visitor_group_no = round(microtime(true) * 1000); // Return the current Unix timestamp with microseconds
        return $visitor_group_no . '' . rand(1, 9);
    }
    /* #endregion getLastVisitorGroupNo*/

    /* #region pendingTicektsFrompVisitorGroupNo  */
    /**
     * pendingTicektsFrompVisitorGroupNo
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $is_secondary_db
     * @return void
     */
    function pendingTicektsFrompVisitorGroupNo($visitor_group_no = '', $is_secondary_db = "0") {
        $db = $this->return_db_object($is_secondary_db);
        $query = 'select vt.ticketPrice, vt.id, vt.discount, vt.isDiscountInPercent, vt.ticket_title, vt.card_name, vt.tickettype_name, (select vt2.partner_id from visitor_tickets vt2 where vt2.transaction_id = vt.transaction_id and vt2.row_type="2") as partner_id, (select vt2.partner_name from visitor_tickets vt2 where vt2.transaction_id = vt.transaction_id and vt2.row_type="2") as partner_name,  vt.creditor, vt.ticketAmt, vt.partner_gross_price, sum(vt.partner_gross_price) as total_partner_gross_price, vt.partner_net_price, sum(vt.partner_net_price) as total_partner_net_price, count(vt.id) as qty, vt.tax_value,vt.row_type, vt.isBillToHotel from visitor_tickets vt where vt.visitor_group_no = "' . $visitor_group_no . '" and vt.deleted="0"  GROUP BY vt.ticket_title, vt.ticketAmt ';
        $query.=' order by id asc';
        $res = $db->query($query);
        if ($res->num_rows() > 0) {
            return $res->result();
        } else {
            return false;
        }
    }
    /* #endregion pendingTicektsFrompVisitorGroupNo*/

    /* #region pendingTicektsFrompVisitorGroupNoV1  */
    /**
     * pendingTicektsFrompVisitorGroupNoV1
     *
     * @param  mixed $visitor_group_no
     * @param  mixed $id
     * @param  mixed $is_secondary_db
     * @return void
     */
    function pendingTicektsFrompVisitorGroupNoV1($visitor_group_no = '', $id = '', $is_secondary_db = "0") {
        $db = $this->return_db_object($is_secondary_db);
        $query = 'select vt.ticketPrice, vt.id, vt.discount, vt.isDiscountInPercent, vt.ticket_title, vt.card_name, vt.tickettype_name, (select vt2.partner_id from visitor_tickets vt2 where vt2.transaction_id = vt.transaction_id and vt2.row_type="2") as partner_id, (select vt2.partner_name from visitor_tickets vt2 where vt2.transaction_id = vt.transaction_id and vt2.row_type="2") as partner_name,  vt.creditor, vt.ticketAmt, vt.partner_gross_price, sum(vt.partner_gross_price) as total_partner_gross_price, vt.partner_net_price, sum(vt.partner_net_price) as total_partner_net_price, count(vt.id) as qty, vt.tax_value,vt.row_type, vt.isBillToHotel from visitor_tickets vt where vt.visitor_group_no = "' . $visitor_group_no . '" and vt.deleted="0" and vt.paid="0" and vt.invoice_status!="7"';
        if ($id != '') {
            $query.=' and vt.id=' . $id;
        }
        $query .= ' GROUP BY vt.ticket_title, vt.ticketAmt ';
        $query.=' order by id asc';
        $res = $db->query($query);
        if ($res->num_rows() > 0) {
            return $res->result();
        } else {
            return false;
        }
    }
    /* #endregion pendingTicektsFrompVisitorGroupNoV1*/
    /* #endregion Boc of hotel_model*/
}
