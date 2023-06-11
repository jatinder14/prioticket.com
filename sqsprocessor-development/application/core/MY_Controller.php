<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class MY_Controller extends CI_Controller {        
    function __construct()
    {
        
        parent::__construct();
        $this->load->helper('url');      
    }
    
    /**
    * @Name : CreateLog()
    * @Purpose : To Create logs    
    */
    function CreateLog($filename='', $apiname='', $paramsArray=array(), $channel_type = '') {
        if(ENABLE_LOGS || $channel_type == "10" || $channel_type == "11") {            
            $log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $key=>$param) {
                    if ($i == 0) {
                        $log .= $key.'=>'.$param;
                    } else {
                        $log .= ', ' . $key.'=>'.$param;
                    }
                    $i++;
                }
            }
            if (is_file('application/storage/logs/'.$filename)) {
                if (filesize('application/storage/logs/'.$filename) > 1048576) {
                    rename("application/storage/logs/$filename", "application/storage/logs/".$filename."_". date("m-d-Y-H-i-s") . ".php");
                }
            }
            $fp = fopen('application/storage/logs/'.$filename, 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }

    function set_unset_expedia_prepaid_columns($prepaid_array = array())
    {
        $return = array();
        if (!empty($prepaid_array)) {
            $expedia_prepaid_columns = array('expedia_prepaid_ticket_id','prepaid_ticket_id','is_combi_ticket','visitor_group_no','ticket_id','shared_capacity_id','ticket_booking_id','hotel_ticket_overview_id','hotel_id','distributor_partner_id','distributor_partner_name','hotel_name','pos_point_id','pos_point_name','channel_id','channel_name','reseller_id','reseller_name','saledesk_id','saledesk_name','title','age_group','museum_id','museum_name','additional_information','location','highlights','image','oroginal_price','discount','is_discount_in_percent','price','ticket_scan_price','cc_rows_value','tax','tax_name','net_price','ticket_amount_before_extra_discount','extra_discount','is_combi_discount','combi_discount_gross_amount','is_discount_code','discount_code_value','discount_code_amount','service_cost_type','service_cost','net_service_cost','ticket_type','discount_applied_on_how_many_tickets','quantity','refund_quantity','timeslot','from_time','to_time','selected_date','booking_selected_date','created_date_time','created_at','scanned_at','action_performed','is_prioticket','product_type','shop_category_name','rezgo_id','rezgo_ticket_id','rezgo_ticket_price','tps_id','group_type_ticket','group_price','group_quantity','group_linked_with','group_id','selected_quantity','min_qty','max_qty','passNo','pass_type','used','visitor_tickets_id','activation_method','split_payment_detail','timezone','is_pre_selected_ticket','is_prepaid','is_cancelled','deleted','time_based_done','booking_status','order_status','is_refunded','without_elo_reference_no','is_voucher','is_iticket_product','reference_id','is_addon_ticket','cluster_group_id','clustering_id','third_party_type','batch_id','batch_reference','cashier_id','cashier_name','redeem_users','cashier_code','location_code','voucher_updated_by','voucher_updated_by_name','redeem_method','museum_cashier_id','museum_cashier_name','extra_text_field_answer','pick_up_location','refund_note','manual_payment_note','channel_type','financial_id','financial_name','is_custom_setting','external_product_id','account_number','chart_number','is_invoice','split_card_amount','split_cash_amount','split_voucher_amount','split_direct_payment_amount','last_imported_date','redeem_by_ticket_id','redeem_by_ticket_title','updated_at','commission_type','third_party_booking_reference','barcode_type','is_data_moved','last_modified_at','shift_id','bleep_pass_no','third_party_response_data','supplier_original_price','supplier_discount','supplier_price','supplier_tax','supplier_net_price','second_party_type','second_party_booking_reference','second_party_passNo');
            foreach ($prepaid_array as $prepaid_key => $prepaid_value) {
                if (in_array($prepaid_key, $expedia_prepaid_columns)) {
                    $return[$prepaid_key] = $prepaid_value;
                }
            }
        }
        return $return;
    }

	/**
	 * common_log_start
	 *
	 * @param  mixed $source_name
	 * @param  mixed $request_reference
	 * @param  mixed $operation_id
	 * @return void
	 */
	function common_log_start($source_name='' , $request_reference ='' , $operation_id='')
	{
        
		//initialize empty common log array in the beginning of the my_controller
		$this->commonLogArray = [];
		$this->commonLogArray['startTime'] = round(microtime(true) * 1000);
		$this->commonLogArray['custom'] = [];
		$this->commonLogArray['custom']['source_name'] = $source_name;
		$this->commonLogArray['custom']['request_reference'] = $request_reference;
		$this->commonLogArray['custom']['operation_id'] = ($operation_id) ? $operation_id :  $source_name ;
		$this->commonLogArray['data'] = [];
	}

	/* #endregion */
	
	
	/* #region common_log_end*/

	/**
	 * common_log_end
	 *
	 * @param  mixed $logs
	 * @param  mixed $request_reference_addition
	 * @param  mixed $exception
	 * @return void
	 */
	function common_log_end($logs , $request_reference_addition = '' , $exception = '')
	{
		if($this->commonLogArray && $logs)
		{
			$this->commonLogArray['custom']['internal_processing_time'] = (round(microtime(true) * 1000)) - $this->commonLogArray['startTime']; 
			$this->commonLogArray['custom']['operation_id'] =  strtoupper($this->commonLogArray['custom']['operation_id']);
			$this->commonLogArray['custom']['http_method'] = "POST"; 
			if($request_reference_addition)
			{
				$this->commonLogArray['custom']['request_reference'] .= '-'.$request_reference_addition;
			}
			if($exception)
			{
				$logs['error'] = $exception->getMessage();
			}
			$this->Createlog($this->commonLogArray['custom']['source_name'], $this->commonLogArray['custom']['request_reference'] , array('data' => json_encode($logs)),$this->commonLogArray['custom']);
		}
		// return empty array to set $log = [];
		return [];
	}

    /**
     * Function to get current datetime with seconds for controller
     * 20 Sept, 2021
     * CreatedBy Kavita <kavita.aipl@gmail.com>
     */
    function get_current_datetime() {
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("Y-m-d H:i:s",$date_array[1]);
    }

     /**
     * @Name : all_headers_new()
     * @Purpose : To get the all required headers for redis and firebase APIs.
     * @CreatedBy : Vikas Jindal <vikasdev.aipl@gmail.com>
     * 
     * same function also defined in model
     */
    function all_headers_new($action = '' , $ticketId = '' , $codId = '' , $vgn = '' ,  $user_id = '') 
    {
        if($vgn)
        {
            $this->primarydb->db->select("prepaid_tickets.museum_id,prepaid_tickets.ticket_id,prepaid_tickets.channel_type,prepaid_tickets.hotel_id,prepaid_tickets.visitor_group_no,prepaid_tickets.cashier_id, qr_codes.pos_type");
            $this->primarydb->db->from("prepaid_tickets");
            $this->primarydb->db->join("qr_codes", "qr_codes.cod_id = prepaid_tickets.hotel_id");
            $this->primarydb->db->where("prepaid_tickets.visitor_group_no", $vgn);
            $ptTableData = $this->primarydb->db->get()->row();
            $headerMuseumId = (isset($ptTableData->museum_id)) ? $ptTableData->museum_id : '';
            $headerTicketId = (isset($ptTableData->ticket_id)) ? $ptTableData->ticket_id : '';
            $headerChannelType = (isset($ptTableData->channel_type)) ? $ptTableData->channel_type : '';
            $headerHotelId = (isset($ptTableData->hotel_id)) ? $ptTableData->hotel_id : '';
            $headerVGN = (isset($ptTableData->visitor_group_no)) ? $ptTableData->visitor_group_no : '';
            $headerPosType = (isset($ptTableData->pos_type)) ? $ptTableData->pos_type : '';
            $user_id = (isset($ptTableData->cashier_id)) ? $ptTableData->cashier_id : '';
        }else if($ticketId)
        {
            $this->primarydb->db->select("modeventcontent.mec_id,qr_codes.cod_id, qr_codes.pos_type , qr_codes.cashier_type");
            $this->primarydb->db->from("modeventcontent");
            $this->primarydb->db->join("qr_codes", "qr_codes.cod_id = modeventcontent.cod_id");
            $this->primarydb->db->where("modeventcontent.mec_id", $ticketId);
            $getCodIdAndPosType = $this->primarydb->db->get()->row();
            $headerTicketId = (isset($getCodIdAndPosType->mec_id)) ? $getCodIdAndPosType->mec_id : '';
            $headerPosType = (isset($getCodIdAndPosType->pos_type)) ? $getCodIdAndPosType->pos_type : '';
            $headerCashierType = (isset($getCodIdAndPosType->cashier_type)) ? $getCodIdAndPosType->cashier_type : '';
            if($headerCashierType == '1' )
            {
                $headerMuseumId = '';
                $headerHotelId = (isset($getCodIdAndPosType->cod_id)) ? $getCodIdAndPosType->cod_id : '';
            }else
            {
                $headerMuseumId = (isset($getCodIdAndPosType->cod_id)) ? $getCodIdAndPosType->cod_id : '';
                $headerHotelId = '';
            }
        }else if($codId)
        {
            $this->primarydb->db->select("cod_id, pos_type , cashier_type");
            $this->primarydb->db->from("qr_codes");
            $this->primarydb->db->where("cod_id", $codId);
            $getCodIdAndPosType = $this->primarydb->db->get()->row();
            $headerPosType = (isset($getCodIdAndPosType->pos_type)) ? $getCodIdAndPosType->pos_type : '';
            $headerCashierType = (isset($getCodIdAndPosType->cashier_type)) ? $getCodIdAndPosType->cashier_type : '';
            if($headerCashierType == '1' )
            {
                $headerMuseumId = '';
                $headerHotelId = (isset($getCodIdAndPosType->cod_id)) ? $getCodIdAndPosType->cod_id : '';
            }else
            {
                $headerMuseumId = (isset($getCodIdAndPosType->cod_id)) ? $getCodIdAndPosType->cod_id : '';
                $headerHotelId = '';
            }
        }

        if(isset($headerHotelId) && empty($headerHotelId))
        {
            $headerPosType = 0;
        }

        return array(
            'Content-Type: application/json',
            'Authorization: ' . REDIS_AUTH_KEY,
            'hotel_id: '.((isset($headerHotelId) && !empty($headerHotelId)) ? $headerHotelId : 0),
            'museum_id: '.((isset($headerMuseumId) && !empty($headerMuseumId)) ? $headerMuseumId : 0),
            'ticket_id: '.((isset($headerTicketId) && !empty($headerTicketId)) ? $headerTicketId : 0),
            'channel_type: '.((isset($headerChannelType) && !empty($headerChannelType)) ? $headerChannelType : 0),
            'pos_type: '.((isset($headerPosType) && !empty($headerPosType)) ? $headerPosType : 0),
            'action: '.((isset($action) && !empty($action)) ? strtoupper($action) : 0),
            'vgn: '.((isset($headerVGN) && !empty($headerVGN)) ? $headerVGN : 0),
            'user_id: '.((isset($user_id) && !empty($user_id)) ? $user_id : 0)
        );
	}
}
?>
