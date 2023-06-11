<?php 

class Transaction_fee_script extends MY_Controller {
    
    /* #region   */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        parent::__construct();
        $this->load->helper('url');
        $this->load->model('common_model');
        $this->load->model('transaction_fee_model');
        $this->base_url = $this->config->config['base_url'];
    }
    /* #endregion */    

    /* #region to insert only transaction fee rows for existing orders  */
    /**
     * insert_transaction_fee_rows
     *
     * @param  mixed $vt_group_no
     * @return void
     * @author: Priya Aggarwa <priya.intersoft1@gmail.com> 
     */
    function insert_transaction_fee_rows($vt_group_no="") {
        $transaction_data       = array();
        $final_visitor_data     = array();
        $total_quantity         = 0;
        $total_order_amount     = 0;
        if (!empty($vt_group_no)) {
            $query          = 'Select * from prepaid_tickets where visitor_group_no= "' . $vt_group_no . '" and is_refunded = "0"';
            $query_result   = $this->secondarydb->rodb->query($query)->result_array();
            if(!empty($query_result)) {
                $hotel_id               = $query_result[0]['hotel_id'];
                $hotel_info             = $this->common_model->companyName($hotel_id); // Hotel Information
                
                foreach ($query_result as $prepaid_tickets_data) {
                    // create transaction_fee_data for main ticket
                    if ($prepaid_tickets_data['is_addon_ticket'] != 2) {
                        $total_quantity         += $prepaid_tickets_data['quantity'];
                        $shared_capacity_id     = $prepaid_tickets_data['shared_capacity_id'];          
                        $distributor_partner_id =  !empty($prepaid_tickets_data['distributor_partner_id']) ? $prepaid_tickets_data['distributor_partner_id'] : 0;
                        $order_confirm_date     = $prepaid_tickets_data['order_confirm_date'];
                        $order_date             = strtotime(date('Y-m-d 00:00:00', strtotime($order_confirm_date))) + ($prepaid_tickets_data['timezone'] * 3600);
                        $vt_query               = 'Select * from visitor_tickets where visitor_group_no= "' . $vt_group_no . '" and is_refunded = "0" and transaction_id="'.$prepaid_tickets_data['prepaid_ticket_id'].'"';
                        $vt_query_result        = $this->secondarydb->rodb->query($vt_query)->result_array();
                        // group by booking id
                        foreach ($vt_query_result as $visitor_row_batch) {
                            if ($visitor_row_batch['row_type'] == '1') {
                                $guest_row                  = $visitor_row_batch;
                                $general_fee                = $visitor_row_batch['partner_gross_price'];
                                $total_order_amount         += $general_fee;
                            } else if ($visitor_row_batch['row_type'] == '17') { 
                                $new_merchant_admin_id      = $visitor_row_batch['merchant_admin_id'];
                                if (!empty($new_merchant_admin_id)) {
                                    $merchant_admin_id[]    = $new_merchant_admin_id;
                                }
                            }
                            $ticket_type_id                 = $visitor_row_batch['ticketType']  ; 
                        }
                        $transaction_key                    = $prepaid_tickets_data['ticket_id']."_".$ticket_type_id."_".$prepaid_tickets_data['selected_date']."_".$prepaid_tickets_data['from_time']."_".$prepaid_tickets_data['to_time'];
                        if (isset($transaction_data[$transaction_key])) {
                            $transaction_data[$transaction_key]['total_order_amount'] += $general_fee;
                        } else {
                            $transaction_data[$transaction_key]                         = $prepaid_tickets_data;
                            $transaction_data[$transaction_key]['quantity']             = $prepaid_tickets_data['quantity'];
                            $transaction_data[$transaction_key]['pax']                  = $prepaid_tickets_data['pax'];
                            $transaction_data[$transaction_key]['total_order_amount']   = $general_fee;
                            $transaction_fee_data                                       =  $this->transaction_fee_model->get_transaction_fee_data($hotel_info, $prepaid_tickets_data['ticket_id'],$ticket_type_id, $prepaid_tickets_data['museum_id'], $distributor_partner_id, $order_date, $merchant_admin_id, $prepaid_tickets_data['from_time'], $prepaid_tickets_data['to_time']);
                            $transaction_data[$transaction_key]['transaction_fee_data'] = $transaction_fee_data;
                        }
                        $transaction_data[$transaction_key]['data']                     = $guest_row;
                    }
                }

                /* #region to get transaction fee rows  */
                if (!empty($transaction_data)) {
                    // insert transaction fee rows
                    // For all debitor types except Market merchant
                    $transaction_fee_rows = $this->transaction_fee_model->transaction_data($transaction_data, $total_order_amount, $total_quantity);
                    if (!empty($transaction_fee_rows)) {
                        foreach ($transaction_fee_rows as $row) {
                            $final_visitor_data = array_merge($final_visitor_data, $row);
                        }
                    }
                }
                /* #endregion to get transaction fee rows*/
                if (!empty($final_visitor_data)) {
                    $this->secondarydb->db->insert_batch('visitor_tickets', $final_visitor_data);
                    echo "Inserted successfully";
                } else {
                    echo "No settings found for: ". $vt_group_no;
                }
            } else {
                echo "No record Found in prepaid_tickets for: ". $vt_group_no;
            }
        } else {
            echo "Please enter Visitor group no";
        }
        
    }
    /* #endregion */
}