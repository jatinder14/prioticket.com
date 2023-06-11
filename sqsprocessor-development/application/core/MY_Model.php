<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');
class MY_Model extends CI_Model {
    public function __construct(){
        parent::__construct();
        
        /* DB2 prepaid_tickets, visitor_tickets insertion tables array */
        $this->keys = array("prepaid_tickets" => 'prepaid_ticket_id', "visitor_tickets" => 'id');
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
        if ($is_secondary_db == "2") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "1") {
            $db = $this->secondarydb->rodb;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else {
            $db = $this->primarydb->db;
        }
        if (!is_null($table_name) && !empty($details)) {

            // To make the query with parameters in the array
            if (!empty($details)) {
                foreach ($details as $key => $value) {
                    if (is_array($value)) {
                        call_user_func_array(array($db, $key), $value);                        
                    } else {
                        $db->$key($value);
                    }
                }
            }
            // Get records from table
            $results = $db->get($table_name);
            //echo $db->last_query(); echo "<br>";//exit;
            // If found the return array of result
            if ($results->num_rows() > 0) {
                if ($option == 'array') {
                    return $results->result_array();
                } else if ($option == 'object') {
                    return $results->result();
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
        if (!is_null($table) && !empty($data)) {
            $this->db->insert($table, $data);
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
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else {
            $db = $this->db;
        }
        if (!is_null($table)) {
            if ($db->update($table, $data, $where))
                return true;
            else
                return false;
        } else {
            return false;
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
            if ($this->db->delete($table, $where))
                return true;
            else
                return false;
        } else {
            return false;
        }
    }

    /**
     * @Name : CreateLog()
     * @Purpose : To Create logs    
     */
    function CreateLog($filename='', $apiname='', $paramsArray=array(),  $channel_type = '') {
        if (ENABLE_LOGS ||  $channel_type == "10" || $channel_type == "11" ) {
            $log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $apiname . ': ';
            if (count($paramsArray) > 0) {
                $i = 0;
                foreach ($paramsArray as $key => $param) {
                    if ($i == 0) {
                        $log .= $key . '=>' . $param;
                    } else {
                        $log .= ', ' . $key . '=>' . $param;
                    }
                    $i++;
                }
            }

            if (is_file('application/storage/logs/' . $filename)) {
                if (filesize('application/storage/logs/' . $filename) > 1048576) {
                    rename("application/storage/logs/$filename", "application/storage/logs/" . $filename . "_" . date("m-d-Y-H-i-s") . ".php");
                }
            }
            $fp = fopen('application/storage/logs/' . $filename, 'a');
            fwrite($fp, "\n\r\n\r\n\r" . $log);
            fclose($fp);
        }
    }

    function update_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '', $capacity_ticket_ids = 0) {
        $this->createLog("booking_count.log", 'request', array('ticket_id' => $ticket_id, 'selected_date' => $selected_date, 'from_time' => $from_time, 'to_time' => $to_time, 'quantity' => $quantity));
        $selected_date = trim($selected_date);
        $from_time = trim($from_time);
        $to_time = trim($to_time);
        $timezone           = $this->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id, timezone, cod_id', 'where' => "mec_id = $ticket_id "));
        $shared_capacity_id = $timezone[0]['shared_capacity_id'];
        $own_capacity_id    = $timezone[0]['own_capacity_id'];        
        $museum_id    = $timezone[0]['cod_id'];        
        if(!empty($own_capacity_id)){
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $own_capacity_id, $museum_id);
        } else { 
            $this->update_actual_capacity($ticket_id, $selected_date, $from_time, $to_time, $quantity, $timeslot_type, $timezone, $shared_capacity_id, $museum_id);
        }  
        return true;
    }
    
    function update_actual_capacity($ticket_id = 0, $selected_date = '', $from_time = '', $to_time = '', $quantity = 0, $timeslot_type = '', $timezone,  $shared_capacity_id = 0, $museum_id = 0){
        $this->CreateLog('update_actual_capacity.php', 'REQUEST:', array($ticket_id, $selected_date,$from_time, $to_time, $quantity, $timeslot_type, $timezone , $shared_capacity_id, $museum_id));
        $sold = $this->find('ticket_capacity_v1', array('select' => 'count(*) as total, ticket_capacity_id, is_active, sold, actual_capacity, adjustment_type, adjustment', 'where' => "shared_capacity_id in($shared_capacity_id) and date = '$selected_date' and from_time = '$from_time' and to_time = '$to_time'"));
        $this->CreateLog('update_actual_capacity.php', 'query2.sold', array('result' => json_encode($sold)));
        if ($sold) {
            $this->CreateLog('update_actual_capacity.php', 'Update capacity - existing entry1', array('shared_capacity_id' => $shared_capacity_id, 'date' => $selected_date, 'from time' => $from_time, 'to time' => $to_time, 'count in database' => $sold[0]['sold'], 'total_capacity' => $sold[0]['actual_capacity']));
        }
        $total_capacity = $sold[0]['actual_capacity'];
        if (empty($sold[0]['ticket_capacity_id'])) {
            $data = array();
            $data['created']            = gmdate('Y-m-d H:i:s');
            $data['ticket_id']          = $ticket_id;
            $data['shared_capacity_id'] = $shared_capacity_id;
            $data['date']               = $selected_date;
            $data['from_time']          = $from_time;
            $data['to_time']            = $to_time;
            $data['sold']               = $quantity;
            $data['timeslot']           = $timeslot_type;
            if ($museum_id > 0) {
                $data['museum_id'] = $museum_id;
            }
            $selected_day    = date('l', strtotime($selected_date));           
            $start_time      = $this->common_model->convert_time_into_user_timezone($from_time, $timezone[0]['timezone'], 1);
            $end_time        = $this->common_model->convert_time_into_user_timezone($to_time, $timezone[0]['timezone'], 1);  
            if($timeslot_type == 'specific' && $start_time > $end_time)
            {
                $initial_time = '-'.$start_time;
            }
            if($timeslot_type == 'day' || $timeslot_type == 'specific'){
                $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and timeslot = '".$timeslot_type."' and start_from = '$start_time' and end_to = '$end_time' "));
            } else {
                $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and timeslot = '".$timeslot_type."' and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
            }
            $this->CreateLog('update_actual_capacity.php', 'STOH data', array('query' => $this->primarydb->db->last_query(),'result' => json_encode($actual_capacity)));
            if (isset($actual_capacity[0]['capacity']) && $actual_capacity[0]['capacity'] != '') {
                $total_capacity = $data['actual_capacity'] = $actual_capacity[0]['capacity'];
            } 
            $this->CreateLog('update_actual_capacity.php', 'Update capacity'.$total_capacity, array('New entry in update capacity' => json_encode($data), 'actual_capacity' => json_encode($actual_capacity), 'query' => "ticket_id = $ticket_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
            $this->save('ticket_capacity_v1', $data);
        } else {
            if ($sold[0]['actual_capacity'] === NULL || $sold[0]['actual_capacity'] == '' || $sold[0]['actual_capacity'] == 0) {
                $selected_day    = date('l', strtotime($selected_date));
                $start_time      = $this->common_model->convert_time_into_user_timezone($from_time, $timezone[0]['timezone'], 1);
                $end_time        = $this->common_model->convert_time_into_user_timezone($to_time, $timezone[0]['timezone'], 1);
                if($timeslot_type == 'day' || $timeslot_type == 'specific'){
                   $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from = '$start_time' and end_to = '$end_time' ")); 
                } else {
                    $actual_capacity = $this->find('standardticketopeninghours', array('select' => 'capacity, is_active', 'where' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
                }            
                $this->CreateLog('update_actual_capacity.php', 'STOH data22', array('query' => $this->primarydb->db->last_query(),'result' => json_encode($actual_capacity)));
                $total_capacity  = $actual_capacity[0]['capacity'];
                if (isset($actual_capacity[0]['capacity']) && $actual_capacity[0]['capacity'] != '') {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "', actual_capacity = " . $actual_capacity[0]['capacity'] . " WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                } else {
                    $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                    $this->db->query($sql);
                }
                $this->CreateLog('update_actual_capacity.php', 'Update capacity', array('update query' => $sql, 'actual_capacity' => json_encode($actual_capacity), 'query' => "shared_capacity_id = $shared_capacity_id and days = '$selected_day' and start_from <= '$start_time' and end_to >= '$end_time' "));
            } else {
                $total_capacity  = $sold[0]['actual_capacity'];
                $sql = "UPDATE `ticket_capacity_v1` SET `sold` = sold + " . $quantity . ", modified = '" . gmdate('Y-m-d H:i:s') . "' WHERE `shared_capacity_id` in(" . $shared_capacity_id . ") and `date` = '" . $selected_date . "' AND `from_time` = '" . $from_time . "' AND `to_time` = '" . $to_time . "'";
                $this->CreateLog('update_actual_capacity.php', 'Update capacity', array('update query' => $sql));
                $this->db->query($sql);
            }
        }

        try {
            /* Get availability for particular date range REDIS SERVER */
            
            $headers = $this->all_headers_new('update_actual_capacity_on_from_SQS' , $ticket_id);

            $data = json_encode(array("shared_capacity_id" => $shared_capacity_id, "date" => $selected_date, "from_time" => $from_time, 'to_time' => $to_time, 'seats' => -$quantity));
            $this->createLog("update_actual_capacity.log", 'redis req', array('data' => json_encode($data)));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/updatecapacity");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
            $getdata = curl_exec($ch);
            $this->createLog("update_actual_capacity.log", 'redis res', array('data' => json_encode($getdata)));
            curl_close($ch);
            //Firebase Updations
            /* SYNC firebase if target point reached */
            if ($sold[0]['adjustment_type'] == '1') {
                $total_capacity = $total_capacity + $sold[0]['adjustment'];
            } else {
                $total_capacity = $total_capacity - $sold[0]['adjustment'];
            }
            $firebase_tickets = explode(',', FIREBASE_TICKET_IDS);
            if (SYNC_WITH_FIREBASE == 1 && (FIREBASE_TICKET_IDS == '' || in_array($ticket_id, $firebase_tickets)) ) {
                // $this->createLog('update_actual_capacity.php', 'update_capacity Start at : ' . date("Y-m-d H:i:s"));
                if (1) {
                    $is_active = (isset($sold[0]['is_active']) && $sold[0]['is_active'] != Null) ? $sold[0]['is_active'] : $actual_capacity[0]['is_active'];
                    $selected_date = date('Y-m-d', strtotime($selected_date));
                    $id = str_replace("-", "", $selected_date) . str_replace(":" ,"", $from_time) . str_replace(":", "",$to_time) . $shared_capacity_id;
                    $update_values = array(
                        'slot_id' => (string) $id,
                        'bookings' => (int) ($sold[0]['sold'] + $quantity),
                        'from_time' => $from_time,
                        'is_active' => ($is_active == 1) ? true : false,
                        'to_time' => $to_time,
                        'total_capacity' => ($total_capacity != '') ? (int) $total_capacity : (int) 100,
                        'type' => ($timeslot_type != '') ? $timeslot_type : "day",
                        'blocked' => (int) 0
                    );
                    $params = json_encode(array("node" => 'ticket/availabilities/' . $shared_capacity_id . '/' . $selected_date . '/timeslots', 'search_key' => 'to_time', 'search_value' => $to_time, 'details' => $update_values));
                    $this->CreateLog('update_actual_capacity.php', 'extended_linked_ticket_from_css_app->sync=>', array('jsonreq ' => $params));
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_details_in_array");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, CURL_TIMEOUT);
                    if($shared_capacity_id > 0) {
                        $getdata = curl_exec($ch);
                    }
                    $this->CreateLog('update_actual_capacity.php', 'extended_linked_ticket_from_css_app->sync=>', array('jsonres ' => $getdata));
                    curl_close($ch);
                    $this->CreateLog('firebase_user_type', __CLASS__.'-'.__FUNCTION__, array('user_type' => $this->session->userdata['user_type'], 'cashier_email' => $this->session->userdata['uname'], 'cashier_type' => $this->get_cashier_type_from_tokenId()));
                }
            }
        } catch(Exception $e){
            
        }

        return true;
    }

    function get_auto_generated_id($visitor_group_no, $prepaid_tickets_id, $row_type = '') {
        return $prepaid_tickets_id . '' . substr($visitor_group_no, -6) . '' . $row_type;
    }
    function get_auto_generated_id_dpos($visitor_group_no, $prepaid_tickets_id, $row_type = '') {
        return $prepaid_tickets_id . '' . $row_type;
    }

    /**
     * @Name      : insert_without_batch()
     * @Purpose   : To insert single - single rows in db.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $table_name, $main_insert_data .
     * @Return params : none
     * @Created : Taranjeet <taran.intersoft@gmail.com> on 20 July, 2017
     */
    function insert_without_batch($table_name = '', $main_insert_data = array(), $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "4") {
            $db = $this->fourthdb->db;
        } else {
            $db = $this->db;
        }
        if ($table_name != '') {
            if (!empty($main_insert_data)) {
                foreach ($main_insert_data as $key => $insert_data) {
                    $db->insert($table_name, $insert_data);
                }
            }
        }
    }

    /**
     * @Name      : insert_batch()
     * @Purpose   : To insert multi dimensional array in db in batch.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $table_name, $main_insert_data .
     * @Return params : none
     * @Created : Taranjeet <taran.intersoft@gmail.com> on 20 July, 2017
     */
    function insert_batch($table_name = '', $main_insert_data = array(), $is_secondary_db = "0") {
        if ($is_secondary_db == "1") {
            $db = $this->secondarydb->db;
        } else if ($is_secondary_db == "4") {
            if ( STOP_RDS_INSERT_QUEUE == 1 ) {
                return true;
                exit;
            }
            $db = $this->fourthdb->db;
        } else {
            $db = $this->db;
        }
        $this->CreateLog('insert_batch.php', $table_name.'__'.$is_secondary_db, array(json_encode($main_insert_data)));
        $insert_batch_data = $columns = array();
        $fileds = '';
        $values = '';
        $write_log = '0';
        $mpos_order_id = '0';
        $use_default_insert_bacth = 0;
        $channel_type = '';
        if ($table_name != '') {
            $table_full_structure = $db->query("DESCRIBE `" . $table_name . "`")->result();
            if (!empty($main_insert_data)) {
                $key = 0;
                /* use insert batch default function of codeigniter for RET third party, to save correct aztec codes. */
                foreach ($main_insert_data as $insert_data) {
                    foreach ($table_full_structure as $table_structure) {
                        /* use default codeigniter function for 3.X apis */
                        if ($table_name == 'prepaid_tickets' && (($table_structure->Field == 'third_party_type'
                                && isset($insert_data[$table_structure->Field]) && $insert_data[$table_structure->Field] == 72) 
                                ||  ($table_structure->Field == 'extra_booking_information' && !empty($insert_data[$table_structure->Field])))
                        ) {
                            if ($table_structure->Field == 'extra_booking_information') {
                                $extra_booking_information = json_decode($insert_data[$table_structure->Field]);
                                if (isset($extra_booking_information->api_version)) {
                                    $use_default_insert_bacth = 1;
                                }
                            } else {
                                $use_default_insert_bacth = 1;
                            }
                        }
                    }
                }
                foreach ($main_insert_data as $insert_data) {
                    /*if ($is_secondary_db == "4" && $table_name == 'prepaid_tickets') {
                        unset($insert_data['extra_booking_information']);
                    }*/
                    //unset($insert_data['redemption_notified_at']);
                    $main_data = array();
                    foreach ($table_full_structure as $table_structure) {                        
                        if($insert_data['channel_type'] == '10' || $insert_data['channel_type'] == '11' ){
                            $write_log = '1';
                            if($table_name == 'prepaid_tickets') {
                                $mpos_order_id =  $insert_data['visitor_group_no'];
                            } else if($table_name == 'visitor_tickets') {
                                $mpos_order_id =  $insert_data['vt_group_no'];
                            }

                            $channel_type = $insert_data['channel_type'];
                        }
                        if($table_structure->Field == "last_modified_at"){
                            continue;
                        }
                        /*if($table_structure->Field == "redemption_notified_at"){
                            continue;
                        }*/
                        // Set all Columns which need to insert.
                        if ($key == 0) {
                            $columns[] = $table_structure->Field;
                        }
                        $is_update_date = 1;
                        if ($table_name == 'guest_contacts' && in_array($table_structure->Field, ['deleted_at'])) {
                            $is_update_date = 0;
                        }
                        if (strstr($table_structure->Type, 'datetime') && ($insert_data[$table_structure->Field] == '' || $insert_data[$table_structure->Field] == '0' ) && $table_structure->Field != 'redeem_date_time' && $table_structure->Field != 'settled_on' && $is_update_date == 1) {
                            $insert_data[$table_structure->Field] = date('Y-m-d H:i:s');
                        }
                        if (!isset($insert_data[$table_structure->Field])) {
                            if ($table_structure->Key == 'PRI' && $table_structure->Extra == 'auto_increment') {
                                $insert_data[$table_structure->Field] = NULL;
                            } else if ($table_structure->Key == 'PRI') {
                                continue;
                            } else if ($table_structure->Default != NULL) {
                                $insert_data[$table_structure->Field] = $table_structure->Default;
                            } else if ($table_structure->Null == 'NO') {
                                $insert_data[$table_structure->Field] = "";
                                if (strstr($table_structure->Type, 'bigint') || strstr($table_structure->Type, 'int') || strstr($table_structure->Type, 'decimal') || strstr($table_structure->Type, 'float')) {
                                    $insert_data[$table_structure->Field] = 0;
                                }
                            } else {
                                if($table_name == 'guest_contacts' && $table_structure->Field == 'age') {
                                    $insert_data[$table_structure->Field] = "NULL";
                                } else {
                                    $insert_data[$table_structure->Field] = "";
                                    if (strstr($table_structure->Type, 'bigint') || strstr($table_structure->Type, 'int') || strstr($table_structure->Type, 'decimal') || strstr($table_structure->Type, 'float')) {
                                        $insert_data[$table_structure->Field] = 0;
                                    }
                                }
                            }
                        } else {
                            if($table_name == 'guest_contacts' && $table_structure->Field == 'age' && $insert_data[$table_structure->Field] === '') {
                                $insert_data[$table_structure->Field] = "NULL";
                            } else {
                                if ($insert_data[$table_structure->Field] === '' && $table_structure->Default != NULL) {
                                    $insert_data[$table_structure->Field] = $table_structure->Default;
                                }
                            }
                        }
                        if(!isset($insert_data[$table_structure->Field]) && $table_name == 'prepaid_tickets' && $table_structure->Field == 'redeem_date_time'){
                            $insert_data[$table_structure->Field] = '1970-01-01 00:00:01';
                        }
                        if ($use_default_insert_bacth == 0) {
                            $insert_data[$table_structure->Field] = str_replace('\"', '"', $insert_data[$table_structure->Field]);
                            $insert_data[$table_structure->Field] = str_replace('"', '\"', $insert_data[$table_structure->Field]);
                         }
                        
                        $main_data[$table_structure->Field] = $insert_data[$table_structure->Field];
                    }
                    $insert_data = array_values($main_data);
                    $insert_batch_data[] = $main_data;
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
            if ($table_name == 'guest_contacts'){
                $values =  str_replace('"NULL"', 'NULL', $values);
            }

            if($write_log == 1) {
                $this->CreateLog('insert_batch.php', $table_name.'_'.date("H:i:s.u"), array('use_default_insert_bacth' => $use_default_insert_bacth, "data" => json_encode($main_insert_data)),  $channel_type);
            }
            if ($use_default_insert_bacth == 1) {
                $this->CreateLog('insert_batch.php', $table_name.'_'.date("H:i:s.u"), array('use_default_insert_bacth_inside' => $use_default_insert_bacth, "order" => $mpos_order_id),  $channel_type);
                while ($rows = array_splice($insert_batch_data, 0, 200)) {
                    $flag = $db->insert_batch($table_name, $rows);
                }
                
            } else {
                 $insert_batch_query = "insert into `" . $table_name . "` (`" . $fileds . "`) VALUES" . $values . ';';
    //           $this->CreateLog('insert_batch.php', $table_name, array('query' => $insert_batch_query));
                if($table_name == 'visitor_tickets' || $table_name == 'guest_contacts'){
                    $this->CreateLog('insert_batch.php', $table_name, array("order" => $mpos_order_id),  $channel_type);
                } else {
                    $this->CreateLog('insert_batch.php', $table_name.'_'.date("H:i:s.u"), array('use_default_insert_bacth_outside' => $use_default_insert_bacth, "order" => $mpos_order_id),  $channel_type);
                }
                $flag = $db->query($insert_batch_query);
            }
            return $flag;
        }
    }
    
    /**
     * @Name      : update_batch()
     * @Purpose   : To update multi dimensional array in db in batch.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $table_name, $main_insert_data .
     * @Return params : none
     * @Created : Taranjeet <taran.intersoft@gmail.com> on 20 July, 2017
     */
    public function update_batch($table_name, $rows_array, $primary_key) {
        $column_query = array();
        $primary_key_array = array();
        foreach ($rows_array as $key => $row) {
            $primary_key_array[] = $row[$primary_key];
            foreach ($row as $col => $val) {
                $column_query[$col] .= ' when (' . $primary_key . '="' . $row[$primary_key] . '") then "' . $val . '" ';
            }
        }
        foreach ($column_query as $col => $col_query) {
            $sub_queries[] = ' ' . $col . '= (CASE ' . $col_query . ' ELSE ' . $col . ' END)';
        }
        $main_query = 'UPDATE ' . $table_name . ' SET ';
        $main_query .= implode(", ", $sub_queries);
        $main_query .= ' where ' . $primary_key . ' in(' . implode(", ", $primary_key_array) . '); ';
        if ($main_query) {
            $this->db->query($main_query);
        }
    }

    /**
     * @Name : get_two_digit_unique_no()
     * @Purpose : To get the digits unique no from addition of day, month, hour and min.
     * @CallFrom : common function.
     * @CreatedBy : Priya Aggarwal <priya.intersoft1@gmail.com> on 21 Jun 2018
     */
    function get_two_digit_unique_no() {
        $day = date('d');
        $month = date('m');
        $hour = date('H');
        $min = date('i');
        $number = $day + $month + $hour + $min;
        $number = substr($number, -2);
        return str_pad($number, 2, "0", STR_PAD_LEFT);
    }
    
    /**
     * @Name : all_headers()
     * @Purpose : To get the all required headers for redis and firebase APIs.
     * @CreatedBy : Vaishali Raheja <vaishali.intersoft1@gmail.com>
     */
    function all_headers($headers = array()) {
        return array(
            'Content-Type: application/json',
            'Authorization: ' . REDIS_AUTH_KEY,
            'hotel_id: '.(isset($headers['hotel_id']) ? $headers['hotel_id'] : 0),
            'museum_id: '.(isset($headers['museum_id']) ? $headers['museum_id'] : 0),
            'ticket_id: '.(isset($headers['ticket_id']) ? $headers['ticket_id'] : 0),
            'channel_type: '.(isset($headers['channel_type']) ? $headers['channel_type'] : 0),
            'pos_type: '.(isset($headers['pos_type']) ? $headers['pos_type'] : 0),
            'action: '.(isset($headers['action']) ? $headers['action'] : 0),
            'vgn: '.(isset($headers['vgn']) ? $headers['vgn'] : 0),
            'user_id: '.(isset($headers['user_id']) ? $headers['user_id'] : 0)
        );
    }
    
    /**
     * @Name      : get_ticket_booking_id()
     * @Purpose   : To generate ticket_booking_id.
     * @Call from : Can be called form any model and controller.
     * @Receiver params : $visitor_group_no .
     * @Return params : $ticket_booking_id
     * @Created : Jatinder Kumar <jatinder.aipl@gmail.com> on 17 April, 2020
     */
    function get_ticket_booking_id($vgn='') {
        
        if(empty($vgn)) { return false; }
        
        $random = substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", 5)), 0, 5);
        return $vgn . $random;
    }
    
    /* #region DB2 insertion Module  : Cover all the functions used for insertion in DB2 regarding all the updates */
    
    /**
     * @name    : set_insert_queries()     
     * @Purpose : To create batch array for the insertion query on the basis of where condition mentioned in update query
     * @where   : FROM MPOS
     * @params  : $array_keys = array()
     * @return  : nothing
     * @created by: Jatinder Kumar <jatinder.aipl@gmail.com> on 27 April, 2020
     */
    function set_insert_queries($array_keys=array(), $result=array()) {
        
        global $MPOS_LOGS;
        $array_keys_for_logs = $array_keys;
        $other_data = [];
        unset($array_keys_for_logs['columns']);
        $logs['request_'.date('H:i:s')] = $array_keys_for_logs;
        if(!empty($array_keys['table']) && !empty($array_keys['columns']) && !empty($array_keys['where'])) {
            /* handle version in case of the update booking notes and amendment */
            $other_data['is_update_booking_notes'] = $array_keys['is_update_booking_notes'] ?? 0;
            $other_data['update_booking_value']  = $array_keys['update_booking_value'] ?? 0;
            $other_data['update_booking_version']  = $array_keys['update_booking_version'] ?? 0;
            $tableName = $array_keys['table'];
            $db_key = $this->keys[$tableName];
            if(empty($result)) {
                $db_data = $this->common_model->find($tableName, array('select' => '*', 'where' => $array_keys['where']), "array", "2");
                $logs['query_'.date('H:i:s')] = $this->secondarydb->db->last_query();
                $logs['query_data_'.date('H:i:s')] = count($db_data);
            }
            else {
                $db_data = $result;
            }
            
            //to get max bersion rows of each id
            $result = $this->get_max_version_data($db_data, $db_key);
            $this->CreateLog('import_booking_excetion_result.php', "step 1", array(json_encode($result)));
            if ($array_keys['activated'] == 1) {
                $result = $this->filter_activated_rows($tableName, $result);
            }
            $is_call_redeem = 1;
            if (isset($array_keys['is_distributor_api_call'])) {
                $is_call_redeem = 0;
            }
            /* do not call function for distributor apis. */
            if ($is_call_redeem == 1) {
                $result = $this->filter_redeemed_rows( $tableName, $result, $array_keys['group_checkin']);
                $logs['filter_redeemed_rows_internal'.date('H:i:s')] = $MPOS_LOGS['filter_redeemed_rows'];
                unset($MPOS_LOGS['filter_redeemed_rows']);
            }
            
            $unset_updated_at = 1;
            if( isset( $array_keys['unset_updated_at'] )  && $array_keys['unset_updated_at'] == 0 ) {
                $unset_updated_at = 0;
            }
            $logs['result_updated_'.date('H:i:s')] = count($result);
            if(!empty($result)) {
                
                if(isset($array_keys['limit']) && !empty($array_keys['limit']) && is_numeric($array_keys['limit']) && count($result) > $array_keys['limit']) {
                    $result = $this->filterLimit($result, $array_keys['limit']);
                }
                
                $redeem = (isset($array_keys['redeem']) && $array_keys['redeem']==1 ? $array_keys['redeem']: 0);
                $result = $this->update_keys_value($result, $array_keys['columns'], $redeem, $unset_updated_at, $other_data);
                if(!empty($result)) {
                    
                    $this->insert_batch($tableName, $result, '1');
                    $batch_query = $this->secondarydb->db->last_query();
                    if (SYNCH_WITH_RDS_REALTIME && (STOP_RDS_UPDATE_QUEUE == '0')) {
                        $this->fourthdb->db->query($batch_query);
                    }
                }
            } else {
                $logs['Error'] = "No data found in DB";
                $MPOS_LOGS['get_insert_queries'] = $logs;
                return false;
            }
        }
        
        $MPOS_LOGS['get_insert_queries'] = $logs;

        return true;
    }
    
    /**
     * @name    : update_keys_value()     
     * @Purpose : To update existing array key values
     * @where   : set_insert_queries
     * @params  : $data=array(), $updation=array()
     * @return  : array()
     * @created by: Jatinder Kumar <jatinder.aipl@gmail.com> on 27 April, 2020
     */
    function update_keys_value($data=array(), $updation=array(), $redeem=0, $unset_updated_at = 1 ,$other_data = []) {
        $is_update_booking_notes = $other_data['is_update_booking_notes'] ?? 0;
        $update_booking_value = $other_data['update_booking_value'] ?? 0;
        $update_booking_version = $other_data['update_booking_version'] ?? 0;
        if(empty($data) || empty($updation)) {
            return array();
        }
        
        $concat = array();
        $concatKeys = array();
        if(isset($updation['CONCAT_VALUE'])) {
            $concat = $updation['CONCAT_VALUE'];
            unset($updation['CONCAT_VALUE']);
            $concatKeys = array_keys($concat);
        }
        
        $updateColumnKeys   = array_keys($updation);
        foreach($data as $key => $get_keys) {
            
            
            foreach($get_keys as $key1 => $value) {
                
                if(in_array($key1, $updateColumnKeys) && isset($updation[$key1]['case']) && !empty($updation[$key1]['case'])) {
                    $data[$key][$key1] = $this->resolve_cases_in_updations($key1, $updation[$key1], $data[$key]);
                } else if(in_array($key1, $updateColumnKeys)) {
                    $data[$key][$key1] = $updation[$key1];
                }
                if(!empty($concatKeys) && in_array($key1, $concatKeys)) {
                    $data[$key][$key1] = $data[$key][$key1].$concat[$key1];
                }
                if ($key1 == 'version') {
                    if ($redeem == 1 || $is_update_booking_notes == 1
                    ) {
                        $data[$key][$key1] = ($data[$key][$key1] + 0.1);
                    } elseif (!empty($update_booking_value) || !empty($update_booking_version)) {
                        $data[$key][$key1] = (floor($data[$key][$key1]) + 1.0);
                    } else {
                        $data[$key][$key1] = $data[$key][$key1] + 1.0;
                    }
                }

                if( ( $unset_updated_at == 1 ) && ( $key1 == 'last_modified_at' || $key1 == 'updated_at') ) {
                    unset($data[$key][$key1]);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * @name    : resolve_cases_in_updations()     
     * @Purpose : To get values for col having updations on basis of cases for the insertion query
     * @where   : FROM update_keys_value()
     * @params  : 
     *              $col_name (string) : having column name to be updated
     *              $updation_value : array(), having case to be resolved
     *              $values_in_db : array(), data from DB
     * @return  : $got_value : having correct value after resloving all the cases
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 29 April, 2020
     */
    function resolve_cases_in_updations ($col_name = '', $updation_value = array(), $values_in_db = array()) {
        if(isset($updation_value['case']['key']) && isset ($updation_value['case']['value'])) {
            //for direct comparison of columns
            $default_col_value = ($data['default_col'] != '') ? $values_in_db[$data['default_col']] : "";
            $got_value = $this->get_final_value($values_in_db[$updation_value['case']['key']], $updation_value['case']['value'], $updation_value['case']['update'], $values_in_db[$col_name], $updation_value['default'], $default_col_value);
        } else {
            if(isset($updation_value['case']['separator'])) {
                //for single separators
                $updation_value['case'][] = $updation_value['case'];
            }
            foreach ($updation_value['case'] as $data) {
                $default_col_value = ($data['default_col'] != '') ? $values_in_db[$data['default_col']] : ""; //if any specific col value is to be updated in updating column.
                $condition_true = $condition_false = 0;
                if(isset($data['separator'])) {
                    foreach ($data['conditions'] as $checks) {
                        //group of condtions to be satisfied here
                        if($data['separator'] == '||') {
                            //for || if one condition is true, we can return
                            if(isset($checks['clause']) && $checks['clause']=='NOT_EQUAL') {
                                
                                if($values_in_db[$checks['key']] != $checks['value']) {
                                    $condition_true = 1;
                                    $condition_false = 0;
                                    return $data['update'];
                                }
                                else {
                                    $condition_false = 1;
                                }
                            }
                            else if($values_in_db[$checks['key']] == $checks['value']) {
                                $condition_true = 1;
                                $condition_false = 0;
                                return $data['update'];
                             }
                        } else if($data['separator'] == '&&'){                            
                            //for && all conditions are required to be checked
                            if(isset($checks['clause']) && $checks['clause']=='NOT_EQUAL') {
                                
                                if($values_in_db[$checks['key']] != $checks['value']) {
                                    $condition_true = 1; 
                                }
                                else {
                                    $condition_false = 1;
                                }
                            }
                            else if($values_in_db[$checks['key']] == $checks['value']) {
                                $condition_true = 1; 
                            } else {
                                $condition_false = 1;
                            }
                        }
                    }
                    if($condition_true == 1 && $condition_false != 1) {
                        // it must be true for every case, if it is false i.e. $condition_false is 1 then we should not update it (in && case)
                       return $data['update'];
                    } else {
                        if($default_col_value !== '') {
                            $got_value = isset($data['default']) ? $data['default'] : $default_col_value;
                        } else {
                            $got_value = isset($data['default']) ? $data['default'] : $values_in_db[$col_name];
                        }
                    }
                } else {
                    $got_value = $this->get_final_value($values_in_db[$data['key']], $data['value'], $data['update'], $values_in_db[$col_name], $updation_value['default'], $default_col_value);
                }
            }
            if($condition_true !== 1) {
                $default_col_value = ($data['default_col'] != '') ? $values_in_db[$data['default_col']] : "";
                if($default_col_value !== '') {
                    $got_value = isset($data['default']) ? $data['default'] : $default_col_value;
                } else {
                    $got_value = isset($data['default']) ? $data['default'] : $values_in_db[$col_name];
                }
            }
        }
        return $got_value; 
    }

    /**
     * @name    : get_final_value()     
     * @Purpose : To get final value
     * @where   : FROM resolve_cases_in_updations()
     * @return  : $got_value : having correct value after resloving all the cases
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 29 April, 2020
     */
    function get_final_value ($case_key = '', $case_value = '', $update_value = '', $db_val = '', $default_case_value = '', $default_col = '') {
        if($default_col != '') {
            $default_value = ($default_case_value) ? $default_case_value : $default_col;
        } else {
            $default_value = ($default_case_value) ? $default_case_value : $db_val;
        }
        return ($case_key == $case_value) ? $update_value : $default_value;
    }

    /**
     * @name    : get_max_version_data()     
     * @Purpose : To get max version rows from data
     * @where   : FROM set_insert_queries()
     * @return  : $result : having correct value after comapring all versions
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 29 April, 2020
     */
    public function get_max_version_data ($db_data = array(), $db_key = '') {
        $versions= array();
        foreach ($db_data as $db_row) {
            if(!isset($versions[$db_row[$db_key]]) || (isset($versions[$db_row[$db_key]]) && $db_row['version'] > $versions[$db_row[$db_key]])){
                $versions[$db_row[$db_key]] = $db_row['version'];
                $result[$db_row[$db_key]] = $db_row;
            }
        }
        return $result;
    }
    
    /**
     * @name    : filterLimit()     
     * @Purpose : To remove keys from an array which are exceeding the limit passed for function
     * @where   : set_insert_queries
     * @params  : $data=array(), $limit=array()
     * @return  : array()
     * @created by: Jatinder Kumar <jatinder.aipl@gmail.com> on 05 May, 2020
     */
    private function filterLimit($data=array(), $limit=0) {
        
        if(empty($data) || empty($limit)) {
            return false;
        }
        
        $limitChk = 1;
        foreach($data as $keyArr => $valArray) {
            
            if($limitChk > $limit) {
                unset($data[$keyArr]);
            }
            $limitChk++;
        }
        
        return $data;
    }
    
    /**
     * @name    : filter_redeemed_rows()     
     * @Purpose : To remove rows from array which are already redeemed
     * @where   : set_insert_queries
     * @params  : $tableName='', $data=array()
     * @return  : array()
     * @created by: Jatinder Kumar <jatinder.aipl@gmail.com> on 17 July, 2020
     */
    public function filter_redeemed_rows( $tableName = '', $data = array() , $group_checkin = 0) {
        
        global $MPOS_LOGS;
        $logs['tablename_'.date('H:i:s')] = $tableName;
        
        if( !empty( $tableName ) && !empty( $data ) ) {
            
            $checkCancel    = false;
            if( $tableName == 'prepaid_tickets' ) {
                $refund_id  = 'prepaid_ticket_id';
                $id         = 'passNo';
                $passNo     = 'passNo';
                $ticketId   = 'ticket_id';
                $tpsId      = 'tps_id';
                $activated  = 'activated';
                $checkCancel = true;
            }
            else {
                $refund_id  =  $id = 'transaction_id';
                $passNo     = 'passNo';
                $ticketId   = 'ticketId';
                $tpsId      = 'ticketpriceschedule_id';
                $activated  = 'ticket_status';
                $checkCancel = false;
            }
            
            $redeemed = array(); //this array will contain all the rows to be removed from data
            foreach( $data as $key => $val ) {
                
                if( $checkCancel == true && $val[$activated] == '1' && $val['used'] == '1') {
                    $redeemed[ $val[$id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] = 1;
                }
                if( $checkCancel == false && $val[$activated] == '1' && $val['used'] == '1') {
                    $redeemed[ $val[$id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] = 1;
                }
                if($val['is_refunded'] == '0' || $val['is_refunded'] == '1') {
                    $refunded[ $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] = $val[$refund_id];
                } else if ($val['is_refunded'] == '2' && isset($refunded[ $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ])) {
                    if( $tableName == 'prepaid_tickets' ) {
                        $refunded_removal[ 
                            $refunded[ $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ]
                            . "_" . $val[$id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] = 1;
                    } else {
                        $refunded_removal[ 
                            $refunded[ $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ]
                            . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] = 1;
                    }
                }
            }
            
            $logs['redeemed_data_'.date('H:i:s')] = $redeemed;
            $logs['refunded_'.date('H:i:s')] = $refunded;
            $logs['refunded_removal_'.date('H:i:s')] = $refunded_removal;
            
            if( !empty( $redeemed ) ||  !empty( $refunded_removal ) ) {
                
                foreach( $data as $key => $val ) {
                    if($group_checkin == 1) {
                    if( isset( $redeemed[ $val[$id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] ) ) {
                        
                        unset( $data[$key] );
                    }
                }
                    if( $tableName == 'prepaid_tickets' ) { 
                        if( isset( $refunded_removal[ $val[$refund_id] . "_" . $val[$id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] ) ) {
                            unset( $data[$key] );
            }            
                    } else {
                        if( isset( $refunded_removal[ $val[$refund_id] . "_" . $val[$passNo] . "_" . $val[$ticketId] . "_" . $val[$tpsId] ] ) ) {
                            unset( $data[$key] );
        }
                    }
                }
            }            
        }
        
        $MPOS_LOGS['filter_redeemed_rows'] = $logs;
        
        return $data;
    }

    /**
     * @name    : filter_activated_rows()     
     * @Purpose : To remove rows from array which are deactivted
     * @where   : set_insert_queries
     * @params  : $tableName='', $data=array()
     * @return  : array()
     */
    public function filter_activated_rows($tableName = '', $data = array()) {
        global $MPOS_LOGS;
        $logs['tablename_'.date('H:i:s')] = $tableName;
        $logs['init_rows_'.date('H:i:s')] = count($data);
        if( !empty( $tableName ) && !empty( $data ) ) {
            $activated_col = ($tableName == 'prepaid_tickets' ) ? 'activated' : 'ticket_status'; 
        }
        foreach($data as $key => $record) {
            if($record[$activated_col] != '1') {
                unset($data[$key]);
            }
        }
        $logs['final_activated_rows_'.date('H:i:s')] = count($data);
        $MPOS_LOGS['filter_activated_rows'] = $logs;
        return $data;
    }
    
    /* #endregion DB2 insertion Module  : Cover all the functions used for insertion in DB2 regarding all the updates */
    
    /* #region  To set prepaid tickets primary array */

    /**
     * set_unset_array_values
     *
     * @param  mixed $prepaid_array
     * @return void
     */
    function set_unset_array_values($prepaid_array = array()) {
        $return = array();
        if (!empty($prepaid_array)) {
            $used_pt_columns_in_db1 = array('prepaid_ticket_id', 'is_combi_ticket', 'visitor_group_no', 'ticket_id', 'shared_capacity_id', 'ticket_booking_id', 'hotel_ticket_overview_id', 'hotel_id', 'own_supplier_id', 'distributor_partner_id', 'distributor_partner_name', 'hotel_name', 'shift_id', 'pos_point_id', 'title', 'age_group', 'museum_id', 'museum_name', 'additional_information', 'discount', 'price', 'tax', 'distributor_type', 'tax_name', 'net_price', 'extra_discount', 'is_combi_discount', 'combi_discount_gross_amount', 'ticket_type', 'timeslot', 'from_time', 'to_time', 'selected_date', 'valid_till', 'created_date_time', 'scanned_at', 'action_performed', 'is_prioticket', 'tps_id', 'group_price', 'group_quantity', 'passNo', 'bleep_pass_no', 'pass_type', 'used', 'activated', 'visitor_tickets_id', 'activation_method', 'timezone', 'is_prepaid', 'refunded_by', 'deleted', 'booking_status', 'order_status', 'is_refunded', 'without_elo_reference_no', 'is_addon_ticket', 'cluster_group_id', 'clustering_id', 'related_product_id', 'third_party_type', 'third_party_booking_reference', 'third_party_response_data', 'supplier_price', 'second_party_type', 'cashier_id', 'cashier_name', 'redeem_users', 'tp_payment_method', 'museum_cashier_id', 'museum_cashier_name', 'extra_text_field_answer', 'channel_type', 'guest_names', 'guest_emails', 'order_status_hto', 'pspReference', 'merchantReference', 'payment_conditions', 'partner_category_id', 'partner_category_name', 'is_order_confirmed', 'pax', 'capacity', 'extra_booking_information', 'quantity', 'booking_selected_date', 'redeem_date_time', 'reference_id', 'second_party_booking_reference', 'second_party_passNo', 'pick_up_location', 'external_product_id', 'market_merchant_id', 'is_invoice', 'last_modified_at', 'group_type_ticket', 'reseller_id', 'reseller_name', 'order_currency_code', 'product_type', 'invoice_method_label', 'pos_point_name', 'is_cancelled', 'batch_id', 'barcode_type');

            foreach ($prepaid_array as $prepaid_key => $prepaid_value) {
                if (in_array($prepaid_key, $used_pt_columns_in_db1)) {
                    $return[$prepaid_key] = $prepaid_value;
                }
            }
        }
        return $return;
    }
    
    /* #endregion To set prepaid tickets primary array */

    /* #region to update payment details. */
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
    /* #endregion to update payment details.*/
    /* #endregion To set prepaid tickets primary array */


    
    /**
     * get_cashier_type_from_tokenId
     * The purpose of this funciton is to extract the cashier type from the idToken stored in the session
     *
     * @return void
     * @author Avinash <avinashphp.aipl@gmail.com> Oct 4th, 2021
     */
    function get_cashier_type_from_tokenId(){
        /* #region BOC for get_cashier_type_from_tokenId */
        $token_id_array = explode('.', $this->session->userdata['idToken']);
        $token_id = json_decode(base64_decode($token_id_array[1]));
        $cashier_type = $token_id->cashierType;
        return $cashier_type;
        /* #endregion EOC for get_cashier_type_from_tokenId */
    }

    /**
     * @name    : get_count_down_time()     
     * @Purpose : common method to get exact countdown time
     * @return  : current_count_down_time
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 06 Oct, 2021
     */
    function get_count_down_time($countdown_text, $countdown_period) {
        $current_time = strtotime(date('Y-m-d H:i:s'));
        $newDate = strtotime('+ '.$countdown_period.' '.$countdown_text);
        return ($newDate - $current_time);
    }

    /**
     * @Name        : get_constant()
     * @Purpose     : To get the constant values from API'S
     *  20 Sept, 2021
     * CreatedBy Kavita <kavita.aipl@gmail.com>
     */
    function common_get_constant($constants = []){
        
        if(!empty($constants)){
            global $api_global_constant_values;
            $this->load->helper('common_curl');
            if(!in_array("API_GRAYLOG_LOG_ARN", $constants)){
                $constants[] = "API_GRAYLOG_LOG_ARN";
            }
            if(!in_array("API_GRAYLOG_LOG_QUEUE", $constants)){
                $constants[] = "API_GRAYLOG_LOG_QUEUE";
            }
            $post_data['request_type'] = "get_constants";
            $post_data['data']['distributor_id'] = API_DISTRIBUTOR;
            $post_data['data']['constant_name'] = $constants;
            $url = $this->config->item('api_url').'/v2.2/booking_service';
            $headers = [
                'Content-Type:application/json',
                "Token: " . API_DISTRIBUTOR_TOKEN
            ];
            $api_resp = common_curl::send_request($url, 'POST', json_encode($post_data), $headers);
            if(!empty($api_resp['response'])){
                $gt_constant_response  = json_decode($api_resp['response'], true);
                if(!empty($gt_constant_response['data']['constants'])){
                    foreach($gt_constant_response['data']['constants'] as $value){
                        $api_global_constant_values[$value['name']] = $value;
                    }
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

       /**
     * Function to get current datetime with seconds
     * 20 Sept, 2021
     * CreatedBy Kavita <kavita.aipl@gmail.com>
     */
    function get_current_datetime() {
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("Y-m-d H:i:s",$date_array[1]);
    }

    /**    
     * @Name        : common_sqs_server_call
     * @Purpose     : create a common function for sending data to SQS
     * @params
     *          $queue_data -> data need to send in SQS queue 
     *          $queue_url  -> URL of queue (obtained from constants)
     *          $message_ref-> reference used for operating queue operation on LOCAL server 
     *          $publish_ref-> reference used when publishing data on SNS
     * @return 
     * * 20 Sept, 2021
     * CreatedBy Kavita <kavita.aipl@gmail.com>
     */
    function common_sqs_server_call($queue_url = '', $message_ref = '', $publish_ref = '', $queue_data = []) {
        
        /* executing further process when having process end_poitn and sending details */
        if (!empty($queue_data) && !empty($queue_url)) {
            try {
                $aws_message = json_encode($queue_data);
                $aws_message = base64_encode(gzcompress($aws_message));
                if (SERVER_ENVIRONMENT == 'Local') {
                    /* If don't have an empty value in reference */
                    if(!empty($message_ref)) {
                        $this->load->helper('local_queue');
                        local_queue($aws_message, $message_ref);  
                    }
                } else {
                    include_once 'aws-php-sdk/aws-autoloader.php';
                    $this->load->library('Sqs');
                    $sqs_object = new Sqs();
                    $MessageId = $sqs_object->sendMessage($queue_url, $aws_message);
                    if ($MessageId != false) {  
                        $this->load->library('Sns');
                        $sns_object = new Sns();
                        $sns_object->publish($MessageId . '#~#' . $queue_url, $publish_ref);
                    }
                }
            } catch (Exception $e) {
                // Handler
            }
        }
    }

    
    
    /**
     * @Name : all_headers_new()
     * @Purpose : To get the all required headers for redis and firebase APIs.
     * @CreatedBy : Vikas Jindal <vikasdev.aipl@gmail.com>
     * 
     * same function also defined in controller
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
    
    /**
     * @name    : get_cashier_register_id()     
     * @Purpose : To cashir_register_id of latest user on coressponding shift and pos point
     * @return  : cashier_register_id
     * @created by: Vaishali Raheja <vaishali.intersoft@gmail.com> on 10 nov, 2020
     */
    function get_cashier_register_id($shift_id = '0', $pos_point_id = '0', $hotel_id = '0', $cashier_id = '0') {
        if($shift_id != '0' && $pos_point_id != '0' && $hotel_id != '0' && $cashier_id != '0') {
            $this->db->select('cashier_register_id');
            $this->db->from('cashier_register');
            $this->db->where('hotel_id', $hotel_id);
            $this->db->where('shift_id', $shift_id);
            $this->db->where('pos_point_id', $pos_point_id);
            $this->db->where('cashier_id', $cashier_id);
            $this->db->order_by('created_at', 'DESC');
            $this->db->limit(1);
            $cashier_register_data = $this->db->get();
            $cashier_register_values = $cashier_register_data->result_array();
            return $cashier_register_values[0]['cashier_register_id'];
        }
    }

    /**
     * @name getVtDataToSyncOnBQ()
     * 
     * @param $vgn vgn
     * 
     * @Purpose : To sync data on BQ
     * 
     * @return  : void()
     * 
     * @created by: Jatinder Kumar <j.kumar@prioticket.com> on 17 April, 2023
     */
    function getVtDataToSyncOnBQ($vgn='') {

        $this->createLog("debugVTSync.log", 'request_' . $vgn, array('vgn' => $vgn));
        if (STOP_VT2SCANREPORT == '1') {

            $this->createLog("debugVTSync.log", 'request_' . $vgn, array('STOP_VT2SCANREPORT' => "OFF"));
            return false;
        }

        /* Do not execute on staging and sandbox due to missing tables */
        if (strpos($_SERVER['HTTP_HOST'], "staging") !== false || strpos($_SERVER['HTTP_HOST'], "sandbox") !== false || strpos($_SERVER['HTTP_HOST'], "prioticket.dev") !== false) {

            $this->createLog("debugVTSync.log", 'request_' . $vgn, array('SERVER' => "WRONG", "INFO" => $_SERVER['HTTP_HOST']));
            return false;
        }

        if (empty(trim($vgn)) || SERVER_ENVIRONMENT == 'Local') {

            $this->createLog("debugVTSync.log", 'request_' . $vgn, array('VGN' => "EMPTY", "SERVER_INFO" => SERVER_ENVIRONMENT));
            return false;
        }

        $this->secondarydb->rodb->select("vt1.*")->from("visitor_tickets vt1");
        if (is_array($vgn)) {
            $this->secondarydb->rodb->where_in("vt1.vt_group_no", $vgn);
            $this->secondarydb->rodb->where('vt1.version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.vt_group_no in (' . implode(',', $vgn) . '))');
        }
        else {
            $this->secondarydb->rodb->where("vt1.vt_group_no", $vgn);
            $this->secondarydb->rodb->where('vt1.version = (select max(version) from visitor_tickets vt2 where vt2.id = vt1.id and vt1.vt_group_no="' . $vgn . '")');
        }
        $this->secondarydb->rodb->where("vt1.last_modified_at > '" . date('Y-m-d H:i:s', strtotime(' -1 minute')) . "'");
        $data = $this->secondarydb->rodb->get()->result_array();
        $this->createLog("debugVTSync.log", 'request_' . $vgn, array('QUERY' => $this->secondarydb->rodb->last_query()));
        if (empty($data)) {
            $this->createLog("debugVTSync.log", 'request_' . $vgn, array('DATA' => "EMPTY"));
            return false;
        }

        // Load SQS library.
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        
        $MessageId = $sqs_object->sendMessage(VT2SCANREPORT, base64_encode(gzcompress(json_encode($data))));
        $this->createLog("debugVTSync.log", 'request_' . $vgn, array('QUEUE_PUSH' => $MessageId, "QUEUE" => VT2SCANREPORT, "ARN" => VT2SCANREPORT_ARN));
        if ($MessageId) {

            $this->load->library('Sns');
            $sns_object = new Sns();
            $sns_object->publish($MessageId, VT2SCANREPORT_ARN);
        }
    }
}
