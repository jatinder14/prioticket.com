<?php
if (!defined('BASEPATH')) 
    exit('No direct script access allowed');

/**
 * @description	All common functions for models can be defined here and can be used in complete application.
 * 
 * @author	karan <karan.intersoft@gmail.com>
 * @date	05 Sep 2015
 */
class MY_Model extends CI_Model {
    public $order_item_ticket_types = array();
    function __construct() {
        parent::__construct();

        $this->types = array(
            'adult' => 1,
            'baby' => 2,
            'infant' => 13,
            'child' => 3,
            'elderly' => 4,
            'handicapt' => 5,
            'student' => 6,
            'military' => 7,
            'youth' => 8,
            'senior' => 9,
            'custom' => 10,
            'family' => 11,
            'resident' => 12
        );
        $this->lang->load('qr', '');
        $this->load->helper('language');
        $this->base_url  = $this->config->config['base_url'];
        $this->imageDir  = $this->config->config['imageDir'];
        $this->root_path = $this->config->config['root_path'];
        $this->load->model('V1/Common_model');
    }
    
    /**
    * @Name : update()
    * @Purpose : To update the records from the database table.
    * @Call from : Can be called form any model and controller.
    * @Functionality : To fetch the records from the table.
    * @Receiver params : .
    * @Return params : return true if record is saved else return false.
    * @Created : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    * @Modified : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    */
    function find($table_name = null, $details = array(), $option = 'array', $is_secondary_db = "0") {
        $db = $this->primarydb->db;
        
        if (!is_null($table_name) && !empty($details)) {
            // To make the query with parameters in the array
            if(!empty($details)) {
                foreach($details as $key => $value) {
                    if(is_array($value)) {
                        call_user_func_array(array($db, $key), $value);
                    } else {
                        $db->$key($value);
                    }
                }
            }
            // Get records from table
            $results = $db->get($table_name);
            $this->check_db_errors($db);
            // If found the return array of result
            if ($results->num_rows() > 0) {
                if ($option == 'array') {
                    return $results->result_array();
                } else if ($option == 'object') {
                    return $results->result();
                } else if ($option == 'row_array') {
                    return $results->row_array();
                } else if ($option == 'row_object') {
                    return $results->row();
                } else if ($option == 'list') {
                    $data = array();
                    $results = $results->result_array();
                    foreach ($results as $row) {
                        $keys = array_keys($row);
                        $data[$row[$keys[0]]] = $row[$keys[1]];
                    }
                    return $data;
                }
            } else {
                // Else return empty array
                return array();
            }
        }
    }

    /**
    * @Name : save()
    * @Purpose : To save the records from the database table.
    * @Call from : Can be called form any model and controller.
    * @Functionality : Simply insert record in the database.
    * @Receiver params : $table name, $data to insert in database.
    * @Return params : return true if record is saved else return false.
    * @Created : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    * @Modified : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    */
    function save($table = null, $data = array()) {
        if(!is_null($table) && !empty($data)) {
            $this->db->insert($table, $data);
            $this->check_db_errors($this->db);
            return $this->db->insert_id();
        } else {
            return false;
        }
    }
    
    /**
    * @Name : update()
    * @Purpose : To update the records from the database table.
    * @Call from : Can be called form any model and controller.
    * @Functionality : Simply update the record in database table corresponding to the conditions passed.
    * @Receiver params : $table name, $data to update in record, $where conditions corresponding to which records to be update.
    * @Return params : return true if record is saved else return false.
    * @Created : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    * @Modified : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    */
    function update($table = null, $data = array(), $where = array(), $is_secondary_db = "0") {
        $db = $this->db;
        if(!is_null($table)) {
            if($db->update($table, $data, $where)){
                return true;
            } else {
                $this->check_db_errors($db);
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
    * @Name : query()
    * @Purpose : To implement a query of select or update in database tables.
    * @Call from : Can be called form any model and controller.
    * @Functionality : Simply fetch or update the record in database table corresponding to the conditions passed.
    * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 13 march 2019
    */
    function query($query = '', $db_type = "0"){
        if($db_type == '1'){
            $db = $this->primarydb->db;
        } else {
        $db = $this->db;
        }
        if($query != ''){
            $data = $db->query($query);
            if($data){
                if(strpos($query, 'select') === 0){
                    return $data->result_array();
                } else {
                    return true;
                }
            } else {
                $this->check_db_errors($db);
                return false;
            }
        }
    }

    /**
    * @Name : delete()
    * @Purpose : To update the records from the database table.
    * @Call from : Can be called form any model and controller.
    * @Functionality : delete the entry in specified database table.
    * @Receiver params : $data array of values to save in database.
    * @Return params : return true if record is saved else return false.
    * @Created : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    * @Modified : Karan <karan.intersoft@gmail.com> on 05 Sep 2015
    */
    function delete($table = null, $where = array()) {
        if (!is_null($table) && !empty($where)) {
            return $this->db->delete($table, $where);
        } else {
            return false;
        }
    }
        
    /**
    * @Name : check_db_errors()
    * @Purpose : to check errors in DB queries
    * @created_by : Vaishali Raheja <vaishali.intersoft@gmail.com> on 13 March 2019 
    */
    function check_db_errors($db = '') {
        if ($db != '') {
            $error = $db->error();
            $error_no = $error['code'];
            $error_msg = $error['message'];
            $data = array(
                "error_no" => $error_no,
                "error_msg" => $error_msg,
                "query" => $db->last_query()
            );
            $data = json_encode($data);
            if ($error_no > 0) {
                throw new Exception($data);
            }
        }
    }

    /**
     * function to traverse with complete path and make folder if not exist
     * @param string $dirName (path)
     * @param  $rights
     */
    public function mkdir_r($dirName, $rights = 0777) {
        $dirs = explode('/', $dirName);
        $dir = '';
        foreach ($dirs as $part) {
            $dir .= $part . '/';
            if (!@is_dir($dir) && strlen($dir) > 0) {
                @mkdir($dir, $rights);
            }
        }
    }
     
    /**
    * @Name : get_ticket_price()
    * @Purpose :Get ticket price from ticket_level_commissions and if is not present in ticket_level_commissions, then get value from channel_level_commission
    * @Call from : Called from reserve_v1 from getyourguide_model.php
    * @Receiver params : $hotel_id,$ticket_id,$tps_id,$channel_id
    * @Return params : return price from tlc or clc.
    * @Created : komal <komalgarg.intersoft@gmail.com> on 1 June 2017
    * @Modified : komal <komalgarg.intersoft@gmail.com> on 1 June 2017
    */
    function get_ticket_price($hotel_id, $ticket_id, $tps_id, $channel_id) {
        $tlc_tps_ids = array();
        if(!is_array($tps_id)){
            $tps_id = array($tps_id);
        }
        $tps_id = array_filter($tps_id);
        $ticket_price_array = array();
        $this->primarydb->db->select("*");
        $this->primarydb->db->from("ticket_level_commission");
        $this->primarydb->db->where("hotel_id", $hotel_id);
        if (!is_array($ticket_id)) {
            $this->primarydb->db->where("ticket_id", $ticket_id);
        } else {
            $this->primarydb->db->where_in("ticket_id", $ticket_id);
        }
        $this->primarydb->db->where("is_adjust_pricing", "1");
        if (!empty($tps_id)) {
            $this->primarydb->db->where_in("ticketpriceschedule_id", $tps_id);
        }
        $query = $this->primarydb->db->get();
        if ($query->num_rows() > 0) {
            $details_from_tlc = $query->result_array();
            foreach ($details_from_tlc as $detail) {
                 if (!is_array($ticket_id)) {
                 $ticket_price_array[$detail['ticketpriceschedule_id']] = $detail;
                 } else {
                     $ticket_price_array[$detail['ticket_id']][$detail['ticketpriceschedule_id']] = $detail;
                 }
            }
            $tlc_tps_ids = array_column($details_from_tlc, "ticketpriceschedule_id");
        } 
        if($channel_id != '' || $query->num_rows()!=count($tps_id)){
            $this->primarydb->db->select("*");
            $this->primarydb->db->from("channel_level_commission");
            $this->primarydb->db->where("channel_id", $channel_id);
            if (!is_array($ticket_id)) {
                $this->primarydb->db->where("ticket_id", $ticket_id);
            } else {
                $this->primarydb->db->where_in("ticket_id", $ticket_id);
            }
            $this->primarydb->db->where("is_adjust_pricing", "1");
            if (!empty($tlc_tps_ids)) {
                $tps_id = array_diff($tps_id,$tlc_tps_ids);
                $this->primarydb->db->where_not_in("ticketpriceschedule_id", $tlc_tps_ids);
            }
            if (!empty($tps_id)) {
                $this->primarydb->db->where_in("ticketpriceschedule_id", $tps_id);
            }
            $query = $this->primarydb->db->get();
            if ($query->num_rows() > 0) {
                $details_from_clc = $query->result_array();
                foreach($details_from_clc as $detail){
                     if (!is_array($ticket_id)) {
                     	  $ticket_price_array[$detail['ticketpriceschedule_id']] = $detail;
                     }
                     else{
                          $ticket_price_array[$detail['ticket_id']][$detail['ticketpriceschedule_id']] = $detail;
                     }
                }
            }
        }
       
        return $ticket_price_array;
    }

    /**
     * @Name      : insert_batch()
     * @Purpose   : To insert multi rows in db.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $table_name, $main_insert_data .
     * @Return params : none
     * @Created : Taranjeet <taran.intersoft@gmail.com> on 20 July, 2017
     */
    function insert_batch($table_name = '', $main_insert_data = array(), $is_secondary_db = "0") {
        global $graylogs;
        $insert_ids_batch = array();
        $db = $this->db;
        $columns = array();
        $fileds = '';
        $values = '';
        if ($table_name != '') {
            $table_full_structure = $db->query("DESCRIBE `" . $table_name . "`")->result();
            if (!empty($main_insert_data)) {
                $key = 0;
                foreach ($main_insert_data as $insert_data) {
                    $main_data = array();
                    foreach ($table_full_structure as $table_structure) {
                        // Set all Columns which need to insert.
                        if ($key == 0) {
                            $columns[] = $table_structure->Field;
                        }
                        if (strstr($table_structure->Type, 'datetime') && ($insert_data[$table_structure->Field] == '' || $insert_data[$table_structure->Field] == '0' ) && $table_structure->Field != 'redeem_date_time') {
                            $insert_data[$table_structure->Field] = date('Y-m-d H:i:s');
                        }
                        if (!isset($insert_data[$table_structure->Field])) {
                            if ($table_structure->Key == 'PRI' && $table_structure->Extra == 'auto_increment') {
                                $insert_data[$table_structure->Field] = NULL;
                            } else if ($table_structure->Key == 'PRI') {
                                continue;
                            } else if($table_structure->Default != NULL) {
                                $insert_data[$table_structure->Field] = $table_structure->Default;
                            } else if($table_structure->Null == 'NO') {
			       $insert_data[$table_structure->Field] = "";
			        if (strstr($table_structure->Type, 'bigint') || strstr($table_structure->Type, 'int') || strstr($table_structure->Type, 'decimal') || strstr($table_structure->Type, 'float')) {
                                    $insert_data[$table_structure->Field] = 0;
                                }
                            } else {
                                $insert_data[$table_structure->Field] = "";
                                if (strstr($table_structure->Type, 'bigint') || strstr($table_structure->Type, 'int') || strstr($table_structure->Type, 'decimal') || strstr($table_structure->Type, 'float')) {
                                    $insert_data[$table_structure->Field] = 0;
                                }
                            }
                        } else {
                            if($insert_data[$table_structure->Field] === '' && $table_structure->Default != NULL) {
                               $insert_data[$table_structure->Field] = $table_structure->Default;
                            }
                        }
                        if(!isset($insert_data[$table_structure->Field]) && $table_name == 'prepaid_tickets' && $table_structure->Field == 'redeem_date_time'){
                            $insert_data[$table_structure->Field] = '1970-01-01 00:00:01';
                        }
                        $insert_data[$table_structure->Field] = str_replace('\"', '"', $insert_data[$table_structure->Field]);
                        $insert_data[$table_structure->Field] = str_replace('"', '\"', $insert_data[$table_structure->Field]);
                        $main_data[$table_structure->Field] = $insert_data[$table_structure->Field];
                    }
                    $insert_data = array_values($main_data);
                    // Now Set all Values which need to insert in batch.                    
                    if ($key == 0) {
                        $values .= '("' . implode('", "', $insert_data) . '")';
                    } else {
                        $values .= ', ("' . implode('", "', $insert_data) . '")';
                    }
                    $key++;
                }
            }
            $fileds = implode('`, `', $columns);
            $insert_batch_query = "insert into `" . $table_name . "` (`" . $fileds . "`) VALUES" . $values . ';';
            $insert_ids_batch['insert_db_flag'] = $db->query($insert_batch_query);
            $insert_ids_batch['table_name'] = $table_name;
            $insert_ids_batch[] = $db->last_query();
        }
        return $insert_ids_batch;
    }
        
    /* @purpose : To get the timezone for the date specfied, otherwise return the current timezone based on current date.
     * @parameters : 1) date : table to update
     * @Return : timezone 1 or 2
     */
    public function get_timezone_of_date($date = ""){
        $current_time = !empty($date)?gmdate("H:i:s",strtotime($date)):gmdate("H:i:s");
        $date = !empty($date) && strtotime($date) ? gmdate("Y-m-d",strtotime($date)) : gmdate("Y-m-d");
        $last_sunday_march = date('Y-m-d', strtotime(date('Y-04-01', strtotime($date)) . ' last sunday'));
        $last_sunday_october = date('Y-m-d', strtotime(date('Y-11-01', strtotime($date)) . ' last sunday'));

        if (strtotime($date) >= strtotime($last_sunday_march) && strtotime($date) < strtotime($last_sunday_october)) {
            if(strtotime($date) == strtotime($last_sunday_march) && $current_time < "02:00:00"){
                $timezone = '+1';
            }else{
                $timezone = '+2';
            }
        } else {
            if(strtotime($date) == strtotime($last_sunday_october) && $current_time < "02:00:00"){
               $timezone = '+2'; 
            }else{
                $timezone = '+1';
            }
        }
        return $timezone;
    }
        
     /**
     * @Name        : prepare_ordered_ticket_types()
     * @Purpose     : get ticket types array for ticket. called from own_api 2.4,2.2,2.5 model
     * @Params      :   $different_price_tickets (tps data), $ticket_details (mec detail for ticket), $api (called from which api).       
     * @CreatedBy   : Manpreet Kaur
     */
    public function prepare_ordered_ticket_types($different_price_tickets = array(), $ticket_details = array(), $api = '') {
        $processed_different_price_tickets = array();        
        if (!empty($different_price_tickets)) {
            foreach ($different_price_tickets as $tps_schedule_row) {
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['groups'][] = $tps_schedule_row;
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['ticketType'] = $tps_schedule_row['ticketType'];
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['agefrom'] = $tps_schedule_row['agefrom'];
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['ageto'] = $tps_schedule_row['ageto'];
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['group_type_ticket'] = $tps_schedule_row['group_type_ticket'];
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['ticketpriceschedule_id'] = $tps_schedule_row['ticketpriceschedule_id'];
                $processed_different_price_tickets[$tps_schedule_row['agefrom'] . '-' . $tps_schedule_row['ageto'] . '-' . $tps_schedule_row['ticketType']]['third_party_ticket_type_id'] = $tps_schedule_row['third_party_ticket_type_id'];
                $ticket_details['seasonal_tps_ids'][] = $tps_schedule_row['ticketpriceschedule_id'];
            }
        }
        if (!empty($processed_different_price_tickets)) {
            $ticket_types = array(
                StaticTicketTypes::Child => "CHILD",
                StaticTicketTypes::Student => "STUDENT",
                StaticTicketTypes::Military => "MILITARY",
                StaticTicketTypes::Youth => "YOUTH",
                StaticTicketTypes::Senior => "SENIOR",
                StaticTicketTypes::Group => "GROUP",
                StaticTicketTypes::Family => "FAMILY",
                StaticTicketTypes::Resident => "RESIDENT",
                StaticTicketTypes::Infant => "INFANT"
            );
            foreach ($processed_different_price_tickets as $tps_details) {
                $ticket_details['ticket_details']['different_price_tickets'][$tps_details['ticketpriceschedule_id']] = $tps_details;
                if ($tps_details['groups'][0]['ticketType'] == 1) {
                    if($api != 'v2.5' && $tps_details['agefrom'] == 1 && $tps_details['ageto'] == 99){
                         $category = 'PERSON';
                    }else{
                         $category = 'ADULT';
                    }
                } else if (isset($ticket_types[$tps_details['groups'][0]['ticketType']])) {
                    $category = $ticket_types[$tps_details['groups'][0]['ticketType']];
                }
                if (!isset($ticket_details['ticket_details']['different_price_tickets'][$category])) {
                    $ticket_details['ticket_details']['different_price_tickets'][$category]['agefrom'] = $tps_details['agefrom'];
                    $ticket_details['ticket_details']['different_price_tickets'][$category]['ageto'] = $tps_details['ageto'];
                    $ticket_details['ticket_details']['different_price_tickets'][$category]['group_type_ticket'] = $tps_details['group_type_ticket'];
                    $ticket_details['ticket_details']['different_price_tickets'][$category]['third_party_ticket_type_id'] = $tps_details['third_party_ticket_type_id'];
                }
                $ticket_details['ticket_details']['different_price_tickets'][$category]['groups'] = $tps_details['groups'];
            }
        }
        return $ticket_details;
    }
            
    public function update_batch($table_name, $rows_array, $primary_key) {
        $column_query = array();
        $primary_key_array = array();
         //columns on which is done actual update if primary key(where conditions) is an array
         if(is_array($primary_key)) {
            $columns = [];
            foreach ($rows_array[0] as $key => $value) {
                if (!in_array($key, $primary_key)){
                    $columns[] = $key;
                }
            }
         }
        //preparing conditon string for fields to be updated
        foreach ($rows_array as $key => $row) {
            if(is_array($primary_key)) { //if primary key(where conditions) is an array
                foreach ($columns as $column) {
                    $condition_str = ' WHEN ('; 
                    foreach ($primary_key as $col_name) {
                         $condition_str .=  $col_name . '= "'.$row[$col_name] . '" AND ';
                         $where[$col_name][] = $row[$col_name];
                    }
                    $condition_str = substr($condition_str, 0, -4);
                    $condition_str .= ') THEN "' . $row[$column] . '"';
                    $column_query[$column] .= $condition_str;
                }
            } else  {
                $primary_key_array[] = $row[$primary_key];
                foreach ($row as $col => $val) {
                    $column_query[$col] .= ' when (' . $primary_key . '="' . $row[$primary_key] . '") then "' . $val . '" ';
                }
            }           
        }


        foreach ($column_query as $col => $col_query) {
            $sub_queries[] = ' ' . $col . '= (CASE ' . $col_query . ' ELSE ' . $col . ' END)';
        }
        //preparing update query
        $main_query = 'UPDATE ' . $table_name . ' SET ';
        $main_query .= implode(", ", $sub_queries);
        if(is_array($primary_key)) {
            $main_query .= ' where ';
            foreach ($primary_key as $col_name) {
                if (count($where[$col_name]) > 0) {
                    $unique_where = array_unique($where[$col_name]);
                    $main_query .= $col_name . ' in ("' . implode('","', $unique_where) . '") AND ';
                }
            }
            $main_query = substr($main_query, 0, -4);
        } else {
            $main_query .= ' where ' . $primary_key . ' in("' . implode('", "', $primary_key_array) . '"); ';
        }
        if ($main_query) {
            $this->db->query($main_query);

        }
    }
    
    /**
     * Function to get current datetime with seconds for controller
     * 14 nov, 2018
    */
    function get_current_datetime() {
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("Y-m-d H:i:s",$date_array[1]);
        return $date.":" . number_format($date_array[0],3);
    }
    
    /**
     * @Name set_response_error
     * @Purpose to set response error if error exist.    
     * @Params : $response(existing response if have),$error_no,$error_code, $error_message, $error_details). 
     * @CreatedBy : Manpreet <manpreet.intersoft@gmail.com> on 15 feb 2019
     */
    protected function set_response_error($response = array(), $error_no = '', $error_code = '', $error_message = ''){
        $response['errorNo'] = $error_no;
        $response['error_code'] = $error_code;
        $response['error_message'] = $error_message;
        return $response;
    } 
   
    /**
     * @Name get_timeslot_id
     * @Purpose to set timeslot_id in redis data from all third party APIs.    
     * @Params : $date, $from_time, $to_time, $ticket_id. 
     * @CreatedBy : Pm <prashantmishra@intersoftprofessional.com> on 14 May 2019
     */
    function get_timeslot_id($date = '', $from_time = '', $to_time = '', $shared_capacity_id = '') {
        $srch = array('-', ':');
        $replce = array('');
        $timeslot_id = str_replace($srch, $replce, ($date.$from_time.$to_time.$shared_capacity_id));        
        return $timeslot_id;
    }

    /**
     * @Name     : validate_cashier_limit()
     * @Purpose  : to return if cashier has enough limit to scan pass for the specific timeslot.
     * @Called   : called from scan process.
     * @Created  : Jatinder Verma <jatinder.aipl@gmail.com> on date 22 July 2019
     */
    protected function validate_cashier_limit($data=array()) {
        
        if(!CASHIER_REDUMPTION_VALIDATE) { return false; }
        
        global $MPOS_LOGS;
        $MPOS_LOGS['validate_cashier_limit_data_' . date('H:i:s')] = json_encode($data);
        
        if(empty($data)) { return false; }
        if(empty($data['cashier_id'])) { return false; }
        if(empty($data['ticket_id'])) { return false; }
        if(empty($data['shared_capacity_id'])) { return false; }
        if(empty($data['from_time'])) { return false; }
        if(empty($data['to_time'])) { return false; }
        if(empty($data['capacity_type']) || !in_array($data['capacity_type'], array('3'))) { return false; }
        
        $userRole = $this->find('users', array("select" => 'user_role', "where" => "id = '{$data['cashier_id']}'"), 'row_array');
        $MPOS_LOGS['login_cashier_role_' . date('H:i:s')] = json_encode($userRole);
        if($userRole['user_role'] != '4') { return false; }
		
        $shared_capacity_id     = $data['shared_capacity_id'];
        $cashier_id             = $data['cashier_id'];
        $cod_id                 = $data['cod_id'];
        $from_time		= $data['from_time'];
        $to_time		= $data['to_time'];
		
        $select = "GROUP_CONCAT(batch_id) AS batch_id, selected_date, cod_id, shared_capacity_id, start_time, end_time, timezone, timeslot, batch_minimum_capacity, batch_capacity, capacity, season_start_date, season_end_date";
        $where  = "is_deleted = '0' AND is_activated = '1' AND batch_activation_type = '2' AND shared_capacity_id = '{$shared_capacity_id}'";
        $where .= " AND (DATE_FORMAT(start_from, '%H:%i') = '{$from_time}' AND DATE_FORMAT(end_to, '%H:%i') = '{$to_time}')";
        if(!empty($cod_id)) {
            $where .= " AND cod_id = '{$cod_id}'";
        }
        
        $getData = $this->find('batches', array('select' => $select, 'where' => $where), 'row_array');
        $MPOS_LOGS['validate_cashier_batches_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
        if(empty($getData) || $getData['batch_id']==null) { 
            $MPOS_LOGS['validate_cashier_batches_query_data_' . date('H:i:s')] = json_encode(array("status" => 'batch_not_found'));
            return array("status" => 'batch_not_found');
        }
        $MPOS_LOGS['validate_cashier_batches_query_data_' . date('H:i:s')] = json_encode($getData);
		
        $selectRules  = "batch_rule_id, timeslot, ticket_start_time, ticket_end_time, ticket_timezone, cod_id, cut_off_time, notification, batch_capacity, minimum_quantity, maximum_quantity, quantity_redeemed, confirmation_required, last_scan, trigger_point";
        $whereRules   = "(is_deleted = '0' AND is_activated = '1')";
        $whereRules  .= " AND (batch_id IN ({$getData['batch_id']}) AND shared_capacity_id = '{$shared_capacity_id}' AND cashier_id = '{$cashier_id}')";
        if(!empty($cod_id)) {
            $whereRules .= " AND cod_id = '{$cod_id}'";
        }
        
        $getRules = $this->find('batch_rules', array('select' => $selectRules, 'where' => $whereRules), 'row_array');
        $MPOS_LOGS['validate_cashier_batch_rules_query_' . date('H:i:s')] = $this->primarydb->db->last_query();
        if(empty($getRules)) {
            $MPOS_LOGS['validate_cashier_batch_rules_query_data_' . date('H:i:s')] = json_encode(array("status" => 'batch_not_found'));
            return array("status" => 'batch_not_found');
        }
        $MPOS_LOGS['validate_cashier_batch_rules_query_data_' . date('H:i:s')] = json_encode($getRules);
		
		$lastScan = (!empty($getRules['last_scan']) && $getRules['last_scan']!='0000-00-00'? $getRules['last_scan']: '0000-00-00');
		if(strtotime($lastScan) < strtotime(date('Y-m-d'))) {
			$getRules['quantity_redeemed'] = 0;
			$getRules['last_scan'] = $lastScan;
			$this->query("UPDATE batch_rules SET quantity_redeemed = 0, last_scan = '".date('Y-m-d')."' WHERE batch_rule_id = {$getRules['batch_rule_id']}");
			$MPOS_LOGS['update_last_scan_quantity_query_and_reset_quantity_0_' . date('H:i:s')] = $this->db->last_query();
		}
		
        $leftQuantity = ($getRules['maximum_quantity']-$getRules['quantity_redeemed']);
        $MPOS_LOGS['validate_cashier_left_quantity_' . date('H:i:s')] = $leftQuantity;
        if($leftQuantity < 1) {
            
            $MPOS_LOGS['validate_cashier_error_data_' . date('H:i:s')] = json_encode(array("status" => 'qty_expired', "cashier_id" => $cashier_id, 
                        "shared_capacity_id" => $shared_capacity_id, "batch_id" => $getData['batch_id'], 
                        "batch_rule_id" => $getRules['batch_rule_id']));
            return array("status" => 'qty_expired', "cashier_id" => $cashier_id, 
                        "shared_capacity_id" => $shared_capacity_id, "batch_id" => $getData['batch_id'], 
                        "batch_rule_id" => $getRules['batch_rule_id']);
        }
        else {
            
            $MPOS_LOGS['validate_cashier_success_data_' . date('H:i:s')] = json_encode(array("status" => 'redeem', "batch_id" => $getData['batch_id'], "batch_rule_id" => $getRules['batch_rule_id'], 
                        "maximum_quantity" => $getRules['maximum_quantity'], "quantity_redeemed" => $getRules['quantity_redeemed'], 
                        "last_scan" => (!empty($getRules['last_scan']) && $getRules['last_scan']!='0000-00-00'? $getRules['last_scan']: ''), 
						"rules_data" => $getRules));
            return array("status" => 'redeem', "batch_id" => $getData['batch_id'], "batch_rule_id" => $getRules['batch_rule_id'], 
                        "maximum_quantity" => $getRules['maximum_quantity'], "quantity_redeemed" => $getRules['quantity_redeemed'], 
                        "last_scan" => (!empty($getRules['last_scan']) && $getRules['last_scan']!='0000-00-00'? $getRules['last_scan']: ''), 
						"rules_data" => $getRules);
        }
    }
	
    /**
     * @Name     : emailNotification()
     * @Purpose  : Send notification on empty redumption quantity for cashier.
     * @Called   : called from validate_cashier_limit.
     * @Created  : Jatinder Verma <jatinder.aipl@gmail.com> on date 20 SEPTEMBER 2019
     */
    function emailNotification($array=array(), $notifyQuantity=0) {

        if ((!empty($array)) &&
                (!empty(trim($array['notification'])) && filter_var($array['notification'], FILTER_VALIDATE_EMAIL)) &&
                (!empty($array['trigger_point']) && $array['trigger_point'] > 0) &&
                ((!empty($notifyQuantity)) && ($notifyQuantity >= $array['trigger_point']))
        ) {

            $msg = 'Hi,<br /><br />Batch limit is almost reached for ' . $array['batch_name'] . ' and ' . $array['timeslot'] . '.<br /><br />';
            $msg .= 'Thanks<br>Team Prioticket';
            $arraylist['emailc'] = trim($array['notification']);
            $arraylist['html'] = $msg;
            $arraylist['from'] = PRIOPASS_NO_REPLY_EMAIL;
            $arraylist['fromname'] = MESSAGE_SERVICE_NAME;
            $arraylist['subject'] = 'Batch rule notification';
            $arraylist['attachments'] = array();
            $arraylist['BCC'] = array();
            $event_details['send_email_details'][] = (!empty($arraylist)) ? $arraylist : array();
            if (!empty($event_details)) {

                try {
                    /* Send request to send email */
                    require_once 'aws-php-sdk/aws-autoloader.php';
                    $this->load->library('Sns');
                    $sns_object = new Sns();
                    $aws_message = json_encode($event_details);
                    $aws_message = base64_encode(gzcompress($aws_message));
                    $queueUrl = QUEUE_URL_EVENT;
                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    if (ENVIRONMENT != 'Local') {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId) {
                            $sns_object = new Sns();
                            $sns_object->publish($MessageId . '#~#' . $queueUrl, EVENT_TOPIC_ARN);
                        }
                    }
                } catch (Exception $e) {
                    
                }
            }
        }
    }

    /**
     * @Name     : get_user_details().
     * @Purpose  : It fetches users data from users table
     * @Created  : vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function get_user_details($user_id = '0') {
        if ($user_id > '0') {
            $qry = $qry = 'select company, hide_tickets, id as uid, cod_id as supplier_id, uname, add_city_card, password, user_type, fname, lname, is_allow_postpaid, is_fast_scan, cmntAuthor as author, country, timeZone, numberformat, language, currency, cashier_location, cashier_city from users where id = "' . $user_id . '" and deleted = "0"';
            $res = $this->primarydb->db->query($qry);
            if ($res->num_rows() > 0) {
                return $res->row();
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * @Name      : get_arrival_time_from_slot()
     * @Purpose   : To get arrival time difference from reserved slot.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 4 oct 2021
     */
    function get_arrival_time_from_slot($timezone = '', $from_date_time ='', $to_date_time = '', $type = '', $today_strtime = '') { 
        global $MPOS_LOGS;
        $today_strtime = ($today_strtime == '' || $today_strtime == '0') ? strtotime(gmdate("Y-m-d H:i:s")) : $today_strtime;
        $arrival_msg = '';
        if($timezone != '') {
            $date = new DateTimeZone($timezone);
            $offset = $date->getOffset(new DateTime());
        } else {
            $offset = 0;
        }
        $current_hr = $today_strtime + $offset;
        $from_date_time = strtotime($from_date_time);
        $to_date_time = strtotime($to_date_time);
        $i = 0;
        $logs['from_date_time__current_hr__to_date_time_' . $i] = array($from_date_time, $current_hr, $to_date_time);
        if ($from_date_time < $current_hr && $current_hr < $to_date_time) {
            //on schedule
            $arrival_msg = '';
            $arrival_status = 0;
        } else if ($to_date_time < $current_hr) {
            //late
            $arrival_status = 2;
            $time_to_check = $to_date_time;
        } else if ($current_hr < $from_date_time) {
            //early
            $arrival_status = 1;
            $time_to_check = ($type != 'specific') ? $from_date_time : $to_date_time;
        }
        $logs['arrival_status'] = $arrival_status;
        if ($arrival_status != 0) { //early or late
            $current_time = new DateTime(date("Y-m-d H:i:s", $current_hr));
            $time_diff = $current_time->diff(new DateTime(date("Y-m-d H:i:s", $time_to_check)));
            $logs['time_diff'] = $time_diff;
            $arrival_msg = $this->get_time_diff_string_from_date_obj($time_diff);
            $arrival_msg .= ($arrival_status == 1) ? "early" : "late";
        }       
        $arrival_time = array(
            "arrival_msg" => $arrival_msg,
            "arrival_status" => $arrival_status
        );
        $logs['arrival_time'] = $arrival_time;
        $MPOS_LOGS['get_arrival_time_from_slot_'.strtotime(gmdate("Y-m-d H:i:s"))] = $logs;
        return $arrival_time;
    }

    /**
     * @Name      : get_arrival_status_from_time()
     * @Purpose   : To get arrival time difference from last scanned time or fom current time based on timer available.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 4 oct 2021
     */
    function get_arrival_status_from_time($device_time = '', $scanned_time_to_validate ='', $scan_countdown_time = '') { 
        global $MPOS_LOGS;
        $arrival_msg = '';
        $logs['device_time__scanned_time_to_validate__scan_countdown_time'] = array( $device_time, $scanned_time_to_validate, $scan_countdown_time );
        if (empty($scanned_time_to_validate) || $scanned_time_to_validate == '' || $scanned_time_to_validate == null) { //scanned for the first time
            $time_to_compare = $scan_countdown_time;
        } else {
            $difference = $device_time - $scanned_time_to_validate;
            if ($scan_countdown_time > $difference || $scan_countdown_time == $difference) {
                $time_to_compare = $scan_countdown_time - $difference;
            } else {
                $time_to_compare = $difference - $scan_countdown_time;
            }
        }
        $logs['time_to_compare'] = $time_to_compare;
        $current_time = new DateTime(gmdate("Y-m-d H:i:s", $device_time));
        $time_diff = $current_time->diff(new DateTime(date("Y-m-d H:i:s", $device_time+$time_to_compare)));
        $logs['time_diff'] = $time_diff;
        $arrival_msg = $this->get_time_diff_string_from_date_obj($time_diff);
        $logs['arrival_msg'] = $arrival_msg;
        $MPOS_LOGS['get_arrival_status_from_time_'.strtotime(gmdate("Y-m-d H:i:s"))] = $logs;
        return $arrival_msg;
    }

    /**
     * @Name      : get_time_diff_string_from_date_obj()
     * @Purpose   : To differnec in date objects.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 4 oct 2021
     */
    function get_time_diff_string_from_date_obj($time_diff) {
        $years = ($time_diff->y > 0) ? $time_diff->y : 0;
        $months = ($time_diff->m > 0) ? $time_diff->m : 0;
        $days = ($time_diff->d > 0) ? $time_diff->d : 0;
        $hours = ($time_diff->h > 0) ? $time_diff->h : 0;
        $mins = ($time_diff->i > 0) ? $time_diff->i :0;
        $secs = ($time_diff->s > 0) ? $time_diff->s : 0;
        $units_added = 0;
        $arrival_msg = '';
        if ($years > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($years > 1) ? $years ." years " : $years ." year ";
        } 
        if ($months > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($months > 1) ? $months ." months " : $months ." month ";
        }
        if ($days > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($days > 1) ? $days ." days " : $days ." day ";
        } 
        if ($hours > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($hours > 1) ? $hours ." hours " : $hours ." hour ";
        } 
        if ($mins > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($mins > 1) ? $mins ." mins " : $mins ." min ";
        } 
        if ($secs > 0 && $units_added < 2) {
            $units_added++;
            $arrival_msg .= ($secs > 1) ? $secs ." secs " : $secs ." sec ";
        }
        return $arrival_msg; 
    }

    /**
     * @Name : all_headers()
     * @Purpose : To get the all required headers for redis and firebase APIs.
     * @CreatedBy : Vaishali Raheja <vaishali.intersoft1@gmail.com>
     */
    function all_headers($headers = array()) {
        return array(
            'Content-Type: application/json',
            'Authorization: ' . (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY),
            'hotel_id: '.(isset($headers['hotel_id']) ? $headers['hotel_id'] : 0),
            'museum_id: '.(isset($headers['museum_id']) ? $headers['museum_id'] : 0),
            'ticket_id: '.(isset($headers['ticket_id']) ? $headers['ticket_id'] : 0),
            'channel_type: '.(isset($headers['channel_type']) ? $headers['channel_type'] : 0),
            'pos_type: '.(isset($headers['pos_type']) ? $headers['pos_type'] : 0),
            'action: '.(isset($headers['action']) ? $headers['action'] : 0),
            'user_id: '.(isset($headers['user_id']) ? $headers['user_id'] : 0)
        );
    }
    
    /**
     * @Name : get_time_wrt_timezone()
     * @Purpose : To get the time with respect to timezone provided.
     * @CreatedBy : Vaishali Raheja <vaishali.intersoft1@gmail.com>
     */
    function get_time_wrt_timezone($timezone = '', $timestamp = '') {
        if ($timezone != '') {
            if ($timestamp == '') {
                $time = date('Y-m-d H:i:s');
                $timestamp = strtotime($time);
            } else {
                $time = date('Y-m-d H:i:s', $timestamp);
            }
            $current_timezone = new DateTimeZone($timezone);
            $timezone_in_seconds = $current_timezone->getOffset(new DateTime($time));
            return $timezone_in_seconds + $timestamp;
        }
    }

    function get_timezone_from_text($timezone = 'Europe/Amsterdam', $date_to_check = ""){
        if(empty($date_to_check) || strtotime($date_to_check) <= 0){
            $date_to_check  = date("Y-m-d H:i:s");
        }else{
            $date_to_check  = date("Y-m-d H:i:s", strtotime($date_to_check));
        }
        if(empty($timezone)){
            $timezone = $this->get_timezone_of_date($date_to_check);
        }
        $date = new DateTime($date_to_check, new DateTimeZone($timezone) );
        return $date->format('P');
    }    

    /**
     * @Name      : get_ticket_booking_id()
     * @Purpose   : To generate ticket_booking_id.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $visitor_group_no .
     * @Return params : $ticket_booking_id
     * @Created : Jatinder Kumar <jatinder.aipl@gmail.com> on 20 April, 2020
     */
    function get_ticket_booking_id($vgn='') {
        
        if(empty($vgn)) { return false; }
        
        $random = substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", 5)), 0, 5);
        return $vgn . $random;
    }
    
    /**
     * @Name      : get_existing_ticket_booking_ids($vgn)
     * @Purpose   : To get ticket_booking_id's if exist for the specific ticket_id, selected_date, from_time, to_time.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $visitor_group_no .
     * @Return params : array()
     * @Created : Jatinder Kumar <jatinder.aipl@gmail.com> on 20 April, 2020
     */
    function get_existing_ticket_booking_ids($vgn='', $data=array()) {
        
        if(empty($vgn)) { return $data; }
        
        $this->primarydb->db->select('passNo, ticket_id, selected_date, from_time, to_time, ticket_booking_id');
        $this->primarydb->db->from('prepaid_tickets');
        $this->primarydb->db->where('visitor_group_no', $vgn);
        $resultTicketBookingId = $this->primarydb->db->get();
        if($resultTicketBookingId->num_rows() > 0) {
            
            $dataBookingId = $resultTicketBookingId->result_array(); 
            foreach($dataBookingId as $val) {
                
                $data[$vgn."_".$val['passNo']."_".$val['ticket_id']."_".$val['selected_date']."_".$val['from_time']."_".$val['to_time']] = $val['ticket_booking_id'];
            }
        }
        
        return $data;
    }

    /**
     * @name    : get_max_version_data()     
     * @Purpose : To get max version rows from data
     * @return  : $result : having correct value after comapring all versions
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 11 June, 2020
     */
    public function get_max_version_data ($db_data = array(), $db_key = '') {
        $versions = $result = array();
        foreach ($db_data as $db_row) {
            if(!isset($versions[$db_row[$db_key]]) || (isset($versions[$db_row[$db_key]]) && $db_row['version'] > $versions[$db_row[$db_key]])){
                $versions[$db_row[$db_key]] = $db_row['version'];
                $result[$db_row[$db_key]] = $db_row;
            }
        }
        return $result;
    }

    /**
     * queue()
     *
     * @purpose - to send data to a queue
     * @param array $request_array - array to pass to the queue
     * @return void
     * created by - Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    public function queue($aws_message = '', $queue = '', $sns = ''){
        require_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sns');
        $sns_object = new Sns();
        $this->load->library('Sqs');
        $sqs_object = new Sqs();

        $MessageId = $sqs_object->sendMessage($queue, $aws_message);
        if ($MessageId) {
            $sns_object->publish($queue, $sns);
        }     
        return true;
    }

    /**
     * @Name update_capacity_on_cancel
     * @Purpose Update capacity on Redis and Firebase when tickets are cancelled and refunded
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 3 Nov 2017
     */
    function update_capacity_on_cancel($capacity = 0, $ticket_id = '', $date = '', $from_time = '', $to_time = '') {
        global $MPOS_LOGS;
        $date = trim($date);
        $from_time = trim($from_time);
        $to_time = trim($to_time);
        $timezone = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, timezone, cod_id', 'where' => "mec_id = $ticket_id "));
        $shared_capacity_id = $timezone[0]['shared_capacity_id'];
        $own_capacity_id = $timezone[0]['own_capacity_id'];
        $museum_id = $timezone[0]['cod_id'];
        $MPOS_LOGS['modeventcontent_data_in_cancel'] = $timezone;
        if (!empty($own_capacity_id)) {
            $this->update_cancel_capacity($capacity, $ticket_id, $date, $from_time, $to_time, $shared_capacity_id, $museum_id);
            $this->update_cancel_capacity($capacity, $ticket_id, $date, $from_time, $to_time, $own_capacity_id, $museum_id);
        } else {
            $this->update_cancel_capacity($capacity, $ticket_id, $date, $from_time, $to_time, $shared_capacity_id, $museum_id);
        }
        return true;
    }

    function update_cancel_capacity($capacity = 0, $ticket_id = '', $date = '', $from_time = '', $to_time = '', $shared_capacity_id = '', $museum_id = 0) {
        global  $MPOS_LOGS;
        if ($from_time != "0" && $to_time != "0" && $from_time != '' && $to_time != '') {
        $query = 'Update ticket_capacity_v1 set sold = sold - ' . $capacity . ' where shared_capacity_id = "' . $shared_capacity_id . '" and date = "' . $date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '"';
        $this->query($query);
        $MPOS_LOGS['updated_TCV1'] = $query;
        try {
            /* Update availability for particular date timeslot range on REDIS SERVER */
            $headers = $this->all_headers(array(
                'ticket_id' => $ticket_id,
                'action' => 'update_cancel_capacity_on_cancel_from_MPOS',
                'museum_id' => $museum_id
            ));           
            $MPOS_LOGS['DB'][] = 'CACHE';
             $this->curl->requestASYNC('CACHE', '/updatecapacity', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => array(
                    "shared_capacity_id" => $shared_capacity_id, 
                    "date" => $date, 
                    "from_time" => $from_time, 
                    'to_time' => $to_time, 
                    'seats' => $capacity
                )
             ));

            $MPOS_LOGS['req_sent_to_cache_in_cancel'] = array("shared_capacity_id" => $shared_capacity_id, "date" => $date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => $capacity);
            /* Firebase Updations */
            if (SYNC_WITH_FIREBASE == 1) {
                $bookings = $this->hotel_model->find('ticket_capacity_v1', array('select' => 'actual_capacity as total_capacity, timeslot as timeslot_type, sold, is_active, blocked, adjustment, adjustment_type', 'where' => 'shared_capacity_id = "' . $shared_capacity_id . '" and date = "' . $date . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '"'));
                $MPOS_LOGS['data_from_TCV1'] = $bookings;
                $total_capacity = $bookings[0]['total_capacity'];
                if ($bookings[0]['adjustment_type'] == '1') {
                    $total_capacity = $total_capacity + $bookings[0]['adjustment'];
                } else {
                    $total_capacity = $total_capacity - $bookings[0]['adjustment'];
                }
                $id = str_replace("-", "", $date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
                $update_values = array(
                    'slot_id' => (string) $id,
                    'from_time' => $from_time,
                    'to_time' => $to_time,
                    'type' => $bookings[0]['timeslot_type'],
                    'is_active' => ($bookings[0]['is_active'] == 1) ? true : false,
                    'bookings' => (int) $bookings[0]['sold'],
                    'total_capacity' => (int) $total_capacity,
                    'blocked' => (int) $bookings[0]['blocked'],
                );
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                $MPOS_LOGS['req_sent_to_firebase_in_cancel'] = $update_values;
                $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array(
                        "node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $date . '/timeslots', 
                        'search_key' => 'to_time', 
                        'search_value' => $to_time,
                        'details' => $update_values
                    )
                ));
            }
        } catch (Exception $e) {
            $logs['exception'] = json_decode($e->getMessage(), true);
            return $logs['exception'];
        }
        } else {
            $MPOS_LOGS['from_time__to_time_issue_in_cancel_capacity'] = array('from_time' => $from_time, 'to_time' => $to_time);
        }
    }

    /**
     * @Name update_capacity
     * @Purpose Update capacity on Rediticket_capacity_v1s and Firebase when reservation date and timeslots are updated
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function update_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '') {
        global $MPOS_LOGS;
        $selected_date = trim($selected_date);
        $from_time = trim($from_time);
        $to_time = trim($to_time);
        $timezone = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, timezone, cod_id', 'where' => "mec_id = $ticket_id "));
        $shared_capacity_id = $timezone[0]['shared_capacity_id'];
        $own_capacity_id = $timezone[0]['own_capacity_id'];
        $museum_id = $timezone[0]['cod_id'];
        $MPOS_LOGS['update_capacity_data'] = array(
            'ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $quantity, 'timeslot_type' => $timeslot_type, 'timezone' => $timezone[0]['timezone'], 'alert_capacity_count' => $timezone[0]['alert_capacity_count'], 'shared_capacity_id' => $shared_capacity_id, 'own_capacity_id' => $own_capacity_id
        );
        if (!empty($own_capacity_id)) {
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $own_capacity_id, $museum_id);
        } else {
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
        }
        return true;
    }

    function update_actual_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '', $timezone = array(), $shared_capacity_id = 0, $museum_id = 0) {
        global $MPOS_LOGS;
        if ($from_time != '0' && $to_time != '0' && $from_time != '' && $to_time != '') {
        $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, modified, sold, actual_capacity, blocked, is_active, adjustment, adjustment_type, ', 'where' => "shared_capacity_id in ($shared_capacity_id) and date = '$selected_date' and from_time = '$from_time' and to_time = '$to_time'"));
        $logs['fetch sold from ticket_capacity_v1_for_' . $shared_capacity_id] = $this->primarydb->db->last_query();
        $logs['data from ticket_capacity_v1_for_' . $shared_capacity_id] = $sold;
        $total_capacity = $sold[0]['actual_capacity'];
        if ($sold[0]['total'] == '0') {
            $data = array();
            $data['created'] = gmdate('Y-m-d H:i:s');
            $data['ticket_id'] = $ticket_id;
            $data['shared_capacity_id'] = $shared_capacity_id;
            $data['date'] = $selected_date;
            $data['from_time'] = $from_time;
            $data['to_time'] = $to_time;
            $data['sold'] = $quantity;
            $data['timeslot'] = $timeslot_type;
            if ($museum_id > 0) {
                $data['museum_id'] = $museum_id;
            }
            $selected_day = date('l', strtotime($selected_date));
            $start_time = $this->common_model->convert_time_into_user_timezone($from_time, $timezone[0]['timezone'], 1);
            $end_time = $this->common_model->convert_time_into_user_timezone($to_time, $timezone[0]['timezone'], 1);
            $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
            $logs['fetch from standardticketopeninghours_for_' . $shared_capacity_id . '_' . $selected_day] = $this->primarydb->db->last_query();
            $logs['Data from standardticketopeninghours_for_' . $shared_capacity_id . '_' . $selected_day] = $actual_capacity;
            if (isset($actual_capacity[0]['capacity']) && $actual_capacity[0]['capacity'] != '') {
                $total_capacity = $data['actual_capacity'] = $actual_capacity[0]['capacity'];
            }
            $this->save('ticket_capacity_v1', $data);
            $logs['Update ticket_capacity_v1_for_' . $shared_capacity_id] = $this->db->last_query();
        } else {
            if ($sold[0]['actual_capacity'] === NULL || $sold[0]['actual_capacity'] == '' || $sold[0]['actual_capacity'] == 0) {
                $selected_day = date('l', strtotime($selected_date));
                $start_time = $this->common_model->convert_time_into_user_timezone($from_time, $timezone[0]['timezone'], 1);
                $end_time = $this->common_model->convert_time_into_user_timezone($to_time, $timezone[0]['timezone'], 1);
                $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
                $logs['fetch from standardticketopeninghours_for_' . $shared_capacity_id . '_' . $selected_day] = $this->primarydb->db->last_query();
                $logs['data from standardticketopeninghours_for_' . $shared_capacity_id . '_' . $selected_day] = $actual_capacity;
                $total_capacity = $actual_capacity[0]['capacity'];
                if (isset($actual_capacity[0]['capacity']) && $actual_capacity[0]['capacity'] != '') {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "', actual_capacity = " . $actual_capacity[0]['capacity'] . " WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->query($sql);
                } else {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->query($sql);
                }
            } else {
                $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                $this->query($sql);
            }
            $logs['update ticket_capacity_v1_for_' . $shared_capacity_id] = $sql;
        }

        try {
            /* Get availability for particular date range REDIS SERVER */
            $headers = $this->all_headers(array(
                'ticket_id' => $ticket_id,
                'action' => 'update_actual_capacity_on_from_MPOS',
                'museum_id' => $museum_id
            ));
            $MPOS_LOGS['DB'][] = 'CACHE';
            $this->curl->requestASYNC('CACHE', '/updatecapacity', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => array("shared_capacity_id" => $shared_capacity_id, "date" => $selected_date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => -$quantity)
            ));
            
            /* Firebase Updations */
            /* SYNC firebase if target point reached */
            if ($sold[0]['adjustment_type'] == '1') {
                $total_capacity = $total_capacity + $sold[0]['adjustment'];
            } else {
                $total_capacity = $total_capacity - $sold[0]['adjustment'];
            }
            $is_active = (isset($sold[0]['is_active']) && $sold[0]['is_active'] != Null) ? $sold[0]['is_active'] : $actual_capacity[0]['is_active'];
            if ($timezone[0]['alert_capacity_count'] <= ($total_capacity - ($sold[0]['sold'] + $quantity)) || 1) {
                $selected_date = date('Y-m-d', strtotime($selected_date));
                $id = str_replace("-", "", $selected_date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
                $update_values = array(
                    'slot_id' => (string) $id,
                    'from_time' => $from_time,
                    'to_time' => $to_time,
                    'type' => $timeslot_type,
                    'is_active' => ($is_active == 1) ? true : false,
                    'bookings' => (int) ($sold[0]['sold'] + $quantity),
                    'total_capacity' => (int) $total_capacity,
                    'blocked' => (int) $sold[0]['blocked'],
                );
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $to_time, 'details' => $update_values)
                ));
            }
            $MPOS_LOGS['update_capacity']['update_actual_capacity_for_' . $shared_capacity_id] = $logs;
        } catch (Exception $e) {
            $logs['exception'] = $e->getMessage();
            $MPOS_LOGS['update_capacity']['update_actual_capacity_for_' . $shared_capacity_id] = $logs;
            return $logs['exception'];
        }
        } else {
            $MPOS_LOGS['from_time__to_time_issue_in_update_capacity'] = array('from_time' => $from_time, 'to_time' => $to_time);
        }
    }

    /**
     * @Name update_capacity
     * @Purpose Update capacity on Redis and Firebase when reservation date and timeslots are updated
     * @CreatedBy komal <komalgarg.intersoft@gmail.com> on 7 Nov 2017
     */
    function update_prev_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '') {
        global $MPOS_LOGS;
        $selected_date = trim($selected_date);
        $from_time = trim($from_time);
        $to_time = trim($to_time);
        $timezone = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, timezone, cod_id', 'where' => "mec_id = $ticket_id "));
        $shared_capacity_id = $timezone[0]['shared_capacity_id'];
        $own_capacity_id = $timezone[0]['own_capacity_id'];
        $museum_id = $timezone[0]['cod_id'];
        $logs['update_capacity_data'] = array(
            'ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $quantity, 'timeslot_type' => $timeslot_type, 'timezone' => $timezone[0]['timezone'], 'alert_capacity_count' => $timezone[0]['alert_capacity_count'], 'shared_capacity_id' => $shared_capacity_id, 'own_capacity_id' => $own_capacity_id
        );
        if (!empty($own_capacity_id)) {
            $this->update_actual_prev_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
            $this->update_actual_prev_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $own_capacity_id, $museum_id);
        } else {
            $this->update_actual_prev_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
        }
        return true;
    }

    function update_actual_prev_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '', $timezone = array(), $shared_capacity_id = 0, $museum_id = 0) {
        global $MPOS_LOGS;
        if ($from_time != "0" && $to_time != "0" && $from_time != '' && $to_time != '') {
        $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, modified, sold, actual_capacity, blocked, is_active, adjustment_type, adjustment', 'where' => "shared_capacity_id = '" . $shared_capacity_id . "' and date = '$selected_date' and from_time = '$from_time' and to_time = '$to_time'"));
        $total_capacity = $sold[0]['actual_capacity'];
        $logs['fetch sold from ticket_capacity_v1_for_' . $shared_capacity_id] = $this->primarydb->db->last_query();
        $logs['data from ticket_capacity_v1_for_' . $shared_capacity_id] = $sold;
        if (!empty($sold)) {
            if ($sold[0]['actual_capacity'] === NULL || $sold[0]['actual_capacity'] == '' || $sold[0]['actual_capacity'] == 0) {
                $selected_day = date('l', strtotime($selected_date));
                $start_time = $this->common_model->convert_time_into_user_timezone($from_time, $timezone[0]['timezone'], 1);
                $end_time = $this->common_model->convert_time_into_user_timezone($to_time, $timezone[0]['timezone'], 1);
                $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
                $total_capacity = $actual_capacity[0]['capacity'];
                if (isset($actual_capacity[0]['capacity']) && $actual_capacity[0]['capacity'] != '') {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold - " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "', actual_capacity = " . $actual_capacity[0]['capacity'] . " WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->query($sql);
                } else {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold - " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->query($sql);
                }
            } else {
                $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold - " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                $this->query($sql);
            }
            $logs['update ticket_capacity_v1_for_' . $shared_capacity_id] = $sql;

            try {
                /* Get availability for particular date range REDIS SERVER */
                $headers = $this->all_headers(array(
                    'ticket_id' => $ticket_id,
                    'action' => 'update_actual_capacity_on_from_MPOS',
                    'museum_id' => $museum_id
                ));
                $MPOS_LOGS['DB'][] = 'CACHE';
                $this->curl->requestASYNC('CACHE', '/updatecapacity', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("shared_capacity_id" => $shared_capacity_id, "date" => $selected_date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => $quantity)
                ));
                
                /* Firebase Updations */
                /* SYNC firebase if target point reached */
                if ($sold[0]['adjustment_type'] == '1') {
                    $total_capacity = $total_capacity + $sold[0]['adjustment'];
                } else {
                    $total_capacity = $total_capacity - $sold[0]['adjustment'];
                }
                // This check is to update the firebase when availability count left less than "alert_capacity_count". 
                // We are skipping this check for now, will enable it after complete stability of app.
                if ($timezone[0]['alert_capacity_count'] <= ($total_capacity - ($sold[0]['sold'] + $quantity)) || 1) {
                    $selected_date = date('Y-m-d', strtotime($selected_date));
                    $id = str_replace("-", "", $selected_date) . str_replace(":", "", $from_time) . str_replace(":", "", $to_time) . $shared_capacity_id;
                    $update_values = array(
                        'slot_id' => (string) $id,
                        'from_time' => $from_time,
                        'to_time' => $to_time,
                        'type' => $timeslot_type,
                        'is_active' => ($sold[0]['is_active'] == 1) ? true : false,
                        'bookings' => (int) ($sold[0]['sold'] - $quantity),
                        'total_capacity' => (int) $total_capacity,
                        'blocked' => (int) $sold[0]['blocked'],
                    );
                    $MPOS_LOGS['DB'][] = 'FIREBASE';
                    $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => array("node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $to_time, 'details' => $update_values)
                    ));
                }
                $MPOS_LOGS['update_prev_capacity']['update_actual_prev_capacity_for_' . $shared_capacity_id] = $logs;
            } catch (Exception $e) {
                $logs['exception'] = $e->getMessage();
                $MPOS_LOGS['update_prev_capacity']['update_actual_prev_capacity_for_' . $shared_capacity_id] = $logs;
                return $logs['exception'];
            }
        }
        } else {
            $MPOS_LOGS['from_time__to_time_issue_in_prev_capacity_update'] = array('from_time' => $from_time, 'to_time' => $to_time);
        }
    }

    public function get_contact_info($pt_data = array(), $contacts = array(), $update_form_extra_booking_info = 1) {
        if($update_form_extra_booking_info == 1) {
            $contact_name = $this->get_guest_name($pt_data['secondary_guest_name'], $pt_data['guest_names'], $pt_data['extra_booking_information']);
            $contact_email =  $this->get_guest_email($pt_data['secondary_guest_email'], $pt_data['guest_emails'], $pt_data['extra_booking_information']);
            $contact_passport = ($pt_data['passport_number'] != '' && $pt_data['passport_number'] != NULL) ? $pt_data['passport_number'] : "";
            $contact_uid = $pt_data['reserved_1'];
        } else if (!empty($contacts) && $update_form_extra_booking_info == 0){
            $contact_email = strtolower(trim($contacts[$pt_data['reserved_1']]['email']));
            $contact_name_first = strtolower(trim($contacts[$pt_data['reserved_1']]['first_name']));
            $contact_name_last = strtolower(trim($contacts[$pt_data['reserved_1']]['last_name']));
            $contact_passport = $contacts[$pt_data['reserved_1']]['passport_id'];
            $contact_uid = $pt_data['reserved_1'];
        }
        return array(
            'first_name' => $contact_name_first,
            'last_name' => $contact_name_last,
            'email' => $contact_email,
            'passport' => $contact_passport,
            'contact_uid' => $contact_uid
        );
    }

       
    /**
     * @Name get_guest_name
     * @Purpose used to get correct name of guest
     * @return $secondary_guest_name -> correct guest name
     * @param 
     *      $secondary_guest_name -> name of the guest from db2,
     *      $guest_names -> name of guest from db1,
     *      $extra_booking_information -> from DB (json format), 
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 27 march 2020
     */
    public function get_guest_name($secondary_guest_name = '', $guest_names = '', $extra_booking_information = '') {
        if ($secondary_guest_name == '' || $secondary_guest_name == NULL) {
            if ($guest_names == '') {
                $extra_booking_info = json_decode(stripslashes($extra_booking_information), true);
                if (isset($extra_booking_info['per_participant_info']) && !empty($extra_booking_info['per_participant_info'])) {
                    $secondary_guest_name = $extra_booking_info['per_participant_info']['name'];
                } else if (isset($extra_booking_info['order_contact']) && !empty($extra_booking_info['order_contact'])) {
                    $order_contactname = $extra_booking_info['order_contact']['contact_name_first'] . " " . $extra_booking_info['order_contact']['contact_name_last'];
                    $secondary_guest_name = $order_contactname;
                }
            } else {
                return strtolower(trim($guest_names));
            }
        }
        return strtolower(trim($secondary_guest_name));
    }

    /**
     * @Name get_guest_email
     * @Purpose used to get correct email of guest
     * @return $secondary_guest_email -> correct guest email
     * @param 
     *      $secondary_guest_email -> email of the guest from db2,
     *      $guest_emails -> email of guest from db1,
     *      $extra_booking_information -> from DB (json format), 
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 27 march 2020
     */
    public function get_guest_email($secondary_guest_email = '', $guest_emails = '', $extra_booking_information = '') {
        if ($secondary_guest_email == '' || $secondary_guest_email == NULL) {
            if ($guest_emails == '') {
                $extra_booking_info = json_decode(stripslashes($extra_booking_information), true);
                if (isset($extra_booking_info['per_participant_info']) && !empty($extra_booking_info['per_participant_info'])) {
                    $secondary_guest_email = $extra_booking_info['per_participant_info']['email'];
                } else if (isset($extra_booking_info['order_contact']) && !empty($extra_booking_info['order_contact'])) {
                    $secondary_guest_email = $extra_booking_info['order_contact']['contact_email'];
                }
            } else {
                return strtolower(trim($guest_emails));
            }
        }
        return strtolower(trim($secondary_guest_email));
    }
    /**
     * @Name     : get_count_down_time().
     * @Purpose  : common method to get exact countdown time
     * @parameters :$request_para data.
     * @Created  : Taranjeet Singh <taran.intersoft@gmail.com> on date 17 March 2018
     */
    function get_count_down_time($countdown_text, $countdown_period) {
        $current_time = strtotime(date('Y-m-d H:i:s'));
        $newDate = strtotime('+ '.$countdown_period.' '.$countdown_text);
        return ($newDate - $current_time);
    }

    function get_difference_bw_time($time) {
        $start_date = new DateTime("$time");
        $since_start = $start_date->diff(new DateTime('NOW'));          
        $hours = ($since_start->format('%d') * 24) + $since_start->format('%h');
        $min = $since_start->format('%i');
        if ($hours < 1) {
            return $min . ' min';
        } else {
            if ($min == 0) {
                $hours = $hours - 1;
                $min = 59;
            }
            return $hours . ' hours ' . $min . ' min';
        }
    }

    /**
     * @Name     : get_price_level_merchant()
     * @Purpose  : To get merchant details from TLC/CLC level if exists
     * @Called   : Called from scan pass.
     * @Created  : Jatinder Kumar<jatinder.aipl@gmail.com> on date 26 June 2019
    */
    public function get_price_level_merchant($ticketId, $hotelId=0, $tps_id = 0) {

        if(!empty($ticketId) && !empty($hotelId)) {

            $data = $this->primarydb->db->select(array("merchant_admin_id", "merchant_admin_name"))
                                            ->from("ticket_level_commission")
                                            ->where(array("ticket_id" => $ticketId, "hotel_id" => $hotelId, "is_adjust_pricing" => 1, "ticketpriceschedule_id" => $tps_id))
                                            ->get()->row();
            if (!empty($data) && !empty($data->merchant_admin_id)) {
                return $data;
            }
        } 
        if(!empty($ticketId)) {

            $data = $this->primarydb->db->select(array("merchant_admin_id", "merchant_admin_name"))
                                            ->from("channel_level_commission")
                                            ->where(array("ticket_id" => $ticketId, "is_adjust_pricing" => 1, "ticketpriceschedule_id" => $tps_id))
                                            ->get()->row();
            if (!empty($data) && !empty($data->merchant_admin_id)) {
                return $data;
            }
        }

        return false;
    }

/* #region tours/guests Module : Cover all the functions used in for tours or guests modules */
    
    /**
     * get_locations_of_tickets
     * @purpose To get start location and end location of tickets
     * @return 
     *      $locations - array(), having start_location and end_location of ticket 
     * @param 
     *      $ticket_ids - array()
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 01 April 2020
     */ 
    public function get_locations_of_tickets($ticket_ids = array()) {
        global $MPOS_LOGS;
        $locations = array();
        $target_locations = $this->find(
                'rel_targetvalidcities', array(
            'select' => 'module_item_id, location_type, name',
            'where' => 'module_item_id = "'.$ticket_ids.'" and location_type in ("Departure location", "Destination")'),
                'array'
        );
        $logs_from_get_locations_of_tickets['target_locations_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
        foreach ($target_locations as $target_location) {
            if ($target_location['location_type'] == "Departure location") {
                $locations[$target_location['module_item_id']]['start_location'] = $target_location['name'];
            }
            if ($target_location['location_type'] == "Destination") {
                $locations[$target_location['module_item_id']]['end_location'] = $target_location['name'];
            }
        }
        $logs_from_get_locations_of_tickets['target_locations'] = $locations;
        $MPOS_LOGS['get_locations_of_tickets'] = $logs_from_get_locations_of_tickets;
        return $locations;
    }

    /**
     * get_duration_from_start_time
     * @return 
     *      $from_date_time - string, having selected date and time of ticket 
     * @param 
     *      $period - string, duration of ticket
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 01 April 2020
     */
    public function get_duration_from_start_time($from_date_time = "", $period = "") {
        $period = str_replace("-", " ", $period);
        return date("Y-m-d", strtotime($from_date_time . " +" . $period)).'T'.date("H:i:s", strtotime($from_date_time . " +" . $period)).'+00:00';
    }

    /**
     * notes function
     *          - used to get all notes corresponding to a contact_uid (reserved_1)
     * @param array $order - data from PT
     * @param array $contacts - having all the contacts' details
     * @return array $notes - having all notes of a entry 
     * @created_by Vaishali Raheja <vaishali.intersoft@gmail.com> on 23 Nov 2020
     */
    public function notes($order = array(), $contacts = array()) {
        $notes = array();
        $contact_notes = json_decode($contacts[$order['reserved_1']]['notes'], true);
        if (!empty($contact_notes)) {
            foreach ($contact_notes as $note) {
                $notes[] = $note['note_value'];
            }
        }
        return $notes;
    }
    
    /**
     * total_addons function
     *              - used to get total number of addons of a main ticket 
     * @param array $order - data from PT
     * @return int - number of addons 
     */
    public function total_addons($order = array()) {
        $extra_booking_info = json_decode(stripslashes($order['extra_booking_information']), true);
        $new_addons = 0;
        if ($order['is_addon_ticket'] == '0') {
            foreach ($extra_booking_info['order_custom_fields'] as $custom_fields) {
                if($custom_fields['custom_field_name'] == "addons_count") {
                    $new_addons = $custom_fields["custom_field_value"];
                }
            }
        }
        return (int) $new_addons;
    }
    
    /**
     * get_contacts function
     *
     * @param array $contact_uids - list of contact_uids from PT
     * @return array - having all contacts' details
     */
    public function get_contacts($contact_uids = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        $secondarydb = $this->load->database('secondary', true);
        $contacts_query = 'select * from guest_contacts where contact_uid in ("' . implode('","', $contact_uids) . '")';
        $logs_from_get_contacts = $contacts_query;
        $MPOS_LOGS['DB'][] = 'DB2';
        $totalcontacts_in_DB = $secondarydb->query($contacts_query)->result_array();
        $totalcontacts_in_DB = $this->get_max_version_data($totalcontacts_in_DB, 'contact_uid');
        $internallogs['contacts with max versions'] = $totalcontacts_in_DB;
        foreach ($totalcontacts_in_DB as $contact) {
            if ($contact['is_deleted'] == "0" && $contact['contact_uid'] != '' && $contact['contact_uid'] != null) {
                $contacts[$contact['contact_uid']] = $contact;
            }
        }
        $MPOS_LOGS['get_contacts'] = $logs_from_get_contacts;
        $internal_logs['get_contacts'] = $internallogs;
        return $contacts;
    }
    
    /**
     * flags_of_a_entity function
     *
     * @param 
     *      array $ticket_ids - array of tickets for flags,
     *      string product type - 5 - for product flags, 
     *      array $req - from app
     * @return array - having all flags' details
     */
    public function flags_of_a_entity($entity_id = "", $product_type = "5", $req = array()) {
        global $MPOS_LOGS;
        global $internal_logs;
        $flags = $flag_entities =array();
        $flag_entities = $this->get_flag_entities($entity_id, $product_type);
        $logs_from_flags_of_a_entity['flag_entities_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
        $internallogs['flag_entities_data'] = $flag_entities;

        if (!empty($flag_entities)) {
            $where_user = '';
            if ($req['cashier_type'] == '3') {
                $where_user = ' and reseller_id = "'.$req['reseller_id'].'"';
            }
            $flag_ids = array_keys($flag_entities);
            $flag_details = $this->find(
                'flags',
                    array(
                    'select' => '*',
                    'where' => 'id in (' . implode(',', $flag_ids) .') and status = "1" and is_deleted = "0"'.$where_user),
                'array'
                );
            $logs_from_flags_of_a_entity['flags_query_'.date("H:i:s")] = $this->primarydb->db->last_query();
            $internallogs['flag_entities_data'] = $flag_details;
        }
        foreach($flag_details as $flag) {
            $flagg['flag_id'] = $flag['flag_uid'];
            $flagg['flag_type'] = $flag['type'];
            $flagg['flag_name'] = $flag['name'];
            $flagg['flag_value'] = $flag['value'];
            $flagg['entity_id'] = $flag_entities[$flag['id']];
            $flags[] = $flagg;
        }
        $MPOS_LOGS['flags_of_a_entity_'.$entity_id] = $logs_from_flags_of_a_entity;
        $internal_logs['flags_of_a_entity_'.$entity_id] = $internallogs;
        return $flags;
    }
    
    /**
     * get_flag_entities function
     * to get flag ids from entities
     * @param 
     *      string product type,
     *      string $entity - entity id
     * @return array - having all flag entities
     */
    public function get_flag_entities($entity = "", $product_type = "0") {
        $flag_entities = array();
        $where = '';
        if ($product_type == "8" && $entity != "") {
            $where = 'entity_id like "%'.$entity.'" and entity_type = "'.$product_type.'" and deleted = "0"';
        }
        if($product_type != "0" && $entity != "") {
            $flag_entities = $this->find(
            'flag_entities',
                array(
                    'select' => 'item_id, entity_id',
                    'where' => ($where !== '') ? $where : 'entity_id = '.$this->primarydb->db->escape($entity).' and entity_type = "'.$product_type.'" and deleted = "0"'),
                'list'
            );
        }
        return $flag_entities;
    }
    
    /**
     * get_addons_of_tickets_from_reseller function
     *
     * @param 
     *      array $ticket_ids - array of main tickets,
     *      string $reseller_id - resellerId of loggedIn user
     *      @return array - with guests count of addons
     */
    public function get_addons_of_tickets_from_reseller($ticket_ids = array(), $reseller_id = '') {
        global $MPOS_LOGS;
        $secondarydb = $this->load->database('secondary', true);
        $MPOS_LOGS['DB'][] = 'DB2';
        $addons_query = 'select version, prepaid_ticket_id, ticket_id, tp_payment_method, reserved_1, is_cancelled, deleted, is_refunded, extra_booking_information from prepaid_tickets '
        . ' where reseller_id = "'.$reseller_id.'" and related_product_id in (' . implode(',', $this->primarydb->db->escape($ticket_ids)) . ') and is_addon_ticket = "1" and is_prepaid = "1"';
        $addons_order_in_DB = $secondarydb->query($addons_query)->result_array();
        $MPOS_LOGS['addons_db2_query_'.date("H:i:s")] = $addons_query;

        $addons_order_in_DB = $this->get_max_version_data($addons_order_in_DB, 'prepaid_ticket_id');


        $contactUids = $addon_orders_in_DB = array();
        foreach($addons_order_in_DB as $orders) {
            if($orders['is_refunded'] == "0" && $orders['deleted'] == "0" && $orders['is_cancelled'] == "0" && $orders['reserved_1'] != "" && $orders['reserved_1'] != null && $orders['reserved_1'] != '0') {
                $extra_booking_info = json_decode(stripslashes($orders['extra_booking_information']), true);
                foreach ($extra_booking_info['order_custom_fields'] as $custom_fields) {
                    if($custom_fields['custom_field_name'] == "main_reservation_reference") {
                        $addon_orders_in_DB[$custom_fields["custom_field_value"]]['count']++;
                        if($orders['tp_payment_method'] == 0 && !in_array($orders['reserved_1'], $contactUids)) {
                            $contactUids[] = $orders['reserved_1'];
                            $addon_orders_in_DB[$custom_fields["custom_field_value"]]['pending_guest']++;
                        }
                    }
                }
            }
        }
        return $addon_orders_in_DB;
    }
     /**
     * @name    : generate_uuid()     
     * @Purpose : To generate random uuid for order_payments_details table
     * @where   : update_payment_detaills
     * @return  : generated string
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on sept 03, 2020
     */
    function generate_uuid() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
/* #end region tours/guests Module : Cover all the functions used in for tours or guests modules */
    
#region start - get Date and time
    /*
        @purpose : to split date of "2021-10-15T00:00:00+05:30" format into respective format.
        @created by Vaishali Raheja <vaishali.intersoft@gmail.com> on 15 oct 2021
    */
    function get_date ($date_T_time_timezone = '') {
        if($date_T_time_timezone != '') {
            $date_time = explode("T", $date_T_time_timezone);
            return $date_time[0];
        }
        return gmdate("Y-m-d");
    }

    /*
        @purpose : to split date of "2021-10-15T00:00:00+05:30" format into respective format.
        @created by Vaishali Raheja <vaishali.intersoft@gmail.com> on 15 oct 2021
    */
    function get_timeZone($date_T_time_timezone = '') {
        if($date_T_time_timezone != '') {
            $date = explode("T", $date_T_time_timezone);
            $time = explode("+", $date[1]);
            $time_zone = explode(":", $time[1]);
        }
        return  $time_zone[0];
    }
    function get_date_time($date_T_time_timezone = '') {
        if($date_T_time_timezone != '') {
            $date = explode("T", $date_T_time_timezone);
            $time = explode("+", $date[1]);
            $time_zone = explode(":", $time[1]);
            $excluding_timezone = strripos($date_T_time_timezone,"+");
            $date_format = substr($date_T_time_timezone, 0, $excluding_timezone);
            return str_replace('T', ' ', $date_format);
        }
        return gmdate("Y-m-d H:i:s");
    }

    /*
        @purpose : to split date of "2021-10-15T00:00:00+05:30" format into respective format.
        @created by Vaishali Raheja <vaishali.intersoft@gmail.com> on 15 oct 2021
    */
    function get_time_in_H_i($date_T_time_timezone = '') {
        if($date_T_time_timezone != '') {
            $date_time = explode("T", $date_T_time_timezone);
            $time_units = explode(":", $date_time[1]);
            return $time_units[0].":".$time_units[1];
        }
        return '0';
    }
#region end - get Date and time

}

/* End of file MY_Model.php */
/* Location: ./application/libraries/MY_Model.php */
