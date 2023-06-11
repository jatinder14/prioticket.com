<?php
class Multi_currency_model extends MY_Model {

    /* #region  Boc of hotel_model */    
    /* #region main function to load Hotel_Model */
    /**
     * __construct
     *
     * @return void
     */
    function __construct() {
        ///Call the Model constructor
        parent::__construct();
    }

  /* #region  to update multi currency prices */
    /**
     * insertCcTransactionfees
     *
     * @param  mixed $data
     * 
     * @return array different currency prices
     */
    public function get_supplier_merchant_commission($data = array(), $is_addon = 0)
    {
        $market_merchant_prices = array();
        $supplier_prices = array();
        $admin_prices = array();
        //print_R($data);
        if (!empty($data)) {
            foreach ($data as $value) {
                if ($value->resale_currency_level == 1) {
                    $prices_column = 'supplier_prices';
                } else if ($value->resale_currency_level == 2) {
                    $prices_column = 'market_merchant_prices';
                } else if ($value->resale_currency_level == 3) {
                    $prices_column = 'admin_prices';
                }
                if (empty($is_addon)) {
                    $$prices_column = array(
                        'supplier_gross_price' => array(
                            1 => $value->ticket_new_price,
                            2 => $value->museum_gross_commission,
                            3 => $value->hotel_commission_gross_price,
                            4 => $value->hgs_commission_gross_price,
                            17 => $value->merchant_gross_commission
                        ),
                        'supplier_net_price' => array(
                            1 => $value->ticket_net_price,
                            2 => $value->museum_net_commission,
                            3 => $value->hotel_commission_net_price,
                            4 => $value->hgs_commission_net_price,
                            17 => $value->merchant_net_commission
                        ),
                        'currency_code' => $value->currency,
                        'ticket_tax_id' => $value->ticket_tax_id,
                        'supplier_discount' => $value->ticket_discount
                    );
                } else {
                    $$prices_column = array(
                        'currency_code' => $value->currency,
                        'museum_gross_commission' => $value->museum_gross_commission,
                        'museum_net_commission' => $value->museum_net_commission
                    );
                }
            }

            //print_r($prices_column);
            //print_r($$prices_column);
            if (empty($admin_prices) && empty($market_merchant_prices)) {
                $admin_prices = $supplier_prices;
            } else if(empty($admin_prices)){
                $admin_prices = $market_merchant_prices;
            }
            if (empty($market_merchant_prices)) {
                $market_merchant_prices = $supplier_prices;
            }
            
        }
        return array(
            'market_merchant_prices' => $market_merchant_prices,
            'supplier_prices'        => $supplier_prices,
            'admin_prices'        => $admin_prices
        );
    }
    /* #endregion to update multi currency prices */

    /* #endregion Boc of hotel_model*/
}
