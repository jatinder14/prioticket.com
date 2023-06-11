<?php

defined('BASEPATH') || exit('No direct script access allowed');

class Block_capacity extends MY_Controller
{
    /* #region  Turns off timeslot(s) */  
    function __construct()
    {
        parent::__construct();
    }  
    /**
     * turn_off_timeslot
     *
     * @param  mixed $supplier_id - Supplier's ID whose product's timeslot(s) must be turned off
     * @param  mixed $shared_capacity_id - Shared Capacity ID of the product for which the timeslot(s) must be turned off
     * @param  mixed $start_date - Start Date of the date range for which the timeslot(s) must be turned off
     * @param  mixed $end_date - End Date of the date range for which the timeslot(s) must be turned off
     * @param  mixed $limit - Total no. of Shared Capacities to for whom timeslot(s) must be turned off
     * @param  mixed $offset - The amount of Shared Capcities to ignore (in ascending order) for whom timeslot will not be updated
     * @return void
     * @author Rohan Singh Chauhan <rohan.aipl@gmail.com> on 12th of May, 2020
     */
    function turn_off_timeslot($supplier_id, $shared_capacity_id, $start_date, $end_date, $turn_on_timeslots = 0, $limit = 10, $offset = 0)
    {
        /* #region  BOC of turn_off_timeslot */
        $this->load->model('block_capacity_model','block_capacity_model');

        /* #region  IF: Shared Capacity ID provided, ELSE IF: No Shared Cpacity ID, but Supplier ID provided */
        if (!empty($shared_capacity_id)) {
            $product = $this->block_capacity_model->get_product_id($shared_capacity_id);
            $product_id = $product->product_id;
            $timezone = $product->timezone;
            /* #region  If the Supplier passed in URL is 0 */
            if (empty($supplier_id)) {
                $supplier_id = $product->supplier_id;
            }
            /* #endregion If the Supplier passed in URL is 0 */

            $data_to_get_timeslots = array(
                'shared_capacity_id' => $shared_capacity_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'ticket_id' => $product_id,
                'timezone' => $timezone,
                'supplier_id' => $supplier_id,
                'turn_on_timeslots' => $turn_on_timeslots
            );

            $this->get_timeslots_to_block($data_to_get_timeslots);
        } else if (!empty($supplier_id)) {
            $shared_capacity_ids = $this->block_capacity_model->get_shared_capacity_ids($supplier_id);
            $own_capacity_ids = array_column($shared_capacity_ids, 'own_capacity_id');
            $shared_capacity_ids = array_column($shared_capacity_ids, 'shared_capacity_id');
            $shared_capacity_ids = array_unique(array_merge($shared_capacity_ids, $own_capacity_ids));
            sort($shared_capacity_ids);

            /* #region  Turn off timeslot for each Shared Capacity of the given Supplier (within date range and corresponding to Limit and Offset data supplied) */
            for ($i = 0; $i < $limit; $i++) {
                /* #region  If Shared Capacity ID found/present (0 treated as no Shared Capacity ID found/present) */
                if ($shared_capacity_ids[$i + $offset] != 0) {
                    $this->turn_off_timeslot($supplier_id, $shared_capacity_ids[$i + $offset], $start_date, $end_date, $turn_on_timeslots);
                }
                /* #endregion If Shared Capacity ID found/present (0 treated as no Shared Capacity ID found/present) */
            }
            /* #endregion Turn off timeslot for each Shared Capacity of the given Supplier (within date range and corresponding to Limit and Offset data supplied) */
        }
        /* #endregion IF: Shared Capacity ID provided, ELSE IF: No Shared Cpacity ID, but Supplier ID provided */
        /* #endregion EOC of turn_off_timeslot */
    }
    /* #endregion Turns off timeslot(s) */

    /* #region  Fetches timeslots that must be turned off */    
    /**
     * get_timeslots_to_block
     *
     * @param  mixed $post - Relevant data to fetch the timeslot(s) that must be turned off
     * @return void
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function get_timeslots_to_block($post)
    {
        /* #region  BOC of get_timeslots_to_block */
        $end_date = $post['end_date'];
        $shared_capacity_id = $post['shared_capacity_id'];
        $date = $post['start_date'];
        $calendar_start_date = $date;
        $ticket_id = $post['ticket_id'];
        $timeslot = array();
        while(strtotime($date) <= strtotime($end_date)) {
            $dates[] = date ("Y-m-d", strtotime($date));
            $date = date ("Y-m-d", strtotime("+1 day", strtotime($date)));
        }

        /* Get availability for particular date range REDIS SERVER */
        
        $headers = $this->all_headers_new('get_timeslots_to_block' , $ticket_id);

        $request = json_encode(array("ticket_id" => $ticket_id, "from_date" => $calendar_start_date, "to_date" => $end_date, "shared_capacity_id" => $shared_capacity_id));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/listcapacity");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $getdata = curl_exec($ch);
        curl_close($ch);
        $available_capacity = json_decode($getdata, true);
        $timeslots = array_column($available_capacity['data'], 'timeslots');
        
        /* NEED TO NOTIFY GYG IF TICKET CONNECTED WITH GYG START*/
        $request_data = [
            'ticket_id' => $ticket_id,
            'selected_date' => $calendar_start_date,
            'end_date' => $end_date,
        ];
        $this->block_capacity_model->notify_ota($request_data);
        /* NEED TO NOTIFY GYG IF TICKET CONNECTED WITH GYG END*/

        $datas = array();
        foreach ($timeslots as $timeslots_key => $timeslot) {
            foreach ($timeslot as $key => $timesolt_value) {
                $from_time = $timesolt_value['from_time'];
                $to_time = $timesolt_value['to_time'];

                $timeslot[$key]['timeslot_modifications'] = $this->block_capacity_model->find('ticket_capacity_v1', array('select' => '*', 'where' => 'shared_capacity_id in( ' . $shared_capacity_id . ') and date = "' . $dates[$timeslots_key] . '" and from_time = "' . $from_time . '" and to_time = "' . $to_time . '"', 'order_by' => 'created desc'));
                if (!empty($timeslot[$key]['timeslot_modifications'])) {
                    $timeslot[$key]['timeslot_modifications'] = $timeslot[$key]['timeslot_modifications'][0];
                    if ($timeslot[$key]['timeslot_modifications']['actual_capacity'] == 0 || is_null($timeslot[$key]['timeslot_modifications']['actual_capacity'])) {
                        $this->db->update('ticket_capacity_v1', array('actual_capacity' => $timeslot['actual_capacity'], 'updated_by' => 'manual_script'), array('shared_capacity_id' => $shared_capacity_id, 'date' => $dates[$timeslots_key], 'from_time' => $from_time, 'to_time' => $to_time));
                        $timeslot[$key]['timeslot_modifications']['actual_capacity'] = $timeslot['actual_capacity'];
                    }
                } else {
                    $timeslot[$key]['timeslot_modifications'] = array();
                }
            }
            $datas[$timeslots_key] = $timeslot;
        }
        $data = $post;
        $data['timeslots'] = $datas;
        $data['dates'] = $dates;
        $this->generate_batch_wise_timeslots($data);
        /* #endregion EOC of get_timeslots_to_block */
    }
    /* #endregion Fetches timeslots that must be turned off */
    
    /* #region  Segregating timeslot(s) data in chunks of maximum 500 for every given Shared Capacity for which timeslot(s) must be turned off */
    /**
     * generate_batch_wise_timeslots
     *
     * @param  mixed $data - Data of timeslot(s) which must be turned off
     * @return void
     * @author Rohan Singh Chauhan <rohan.aipl@gmail.com> on 12th May 2020
     */
    private function generate_batch_wise_timeslots($data)
    {
        /* #region  BOC of generate_batch_wise_timeslots */
        extract($data);

        $i = 1;
        /* #region  Generate array of timeslot(s) data which must be turned off */
        foreach ($timeslots as $timeslot_key => $timeslot) {
            foreach ($timeslot as $value) {
                $timeslot_type = $value['timeslot_type'];
                $timeslot_value = $value['from_time'].' - '.$value['to_time'];

                $records['record_' . $i++] = array(
                    'timeslot' => $timeslot_value,
                    'timeslot_type' => $timeslot_type,
                    'date' => $dates[$timeslot_key],
                    'actual_capacity' => $value['total_capacity'],
                    'available_capacity' => $value['vacancies']
                );
            }
        }
        /* #endregion Generate array of timeslot(s) data which must be turned off */

        $total_record_count = count($records);
        $batch_count = $record = 1;

        $date = $timeslot = $available_capacity = $actual_capacity = $timeslot_type = [];
        $active_slot = '';
        
        /* #region  Turns off timeslot(s) for a given Shared Capacity in chunks of 500 at max */
        while($total_record_count > 0) {
            /* #region  Generate a chunk of 500 (at max) timeslots of the given Shared Capacity ID which must be turned off */
            for (; $record <= $batch_count * 500 && array_key_exists('record_' . $record, $records); $record++) {
                $timeslot[] = $records['record_' . $record]['timeslot'];
                $timeslot_type[] = $records['record_' . $record]['timeslot_type'];
                $date[] = $records['record_' . $record]['date'];
                $actual_capacity[] = $records['record_' . $record]['actual_capacity'];
                $available_capacity[] = $records['record_' . $record]['available_capacity'];
                $active_slot = $turn_on_timeslots;
            }
            /* #endregion Generate a chunk of 500 (at max) timeslots of the given Shared Capacity ID which must be turned off */
            $total_record_count -= $record - 1;
            $batch_count++;

            $post = array(
                'shared_capacity_id' => $shared_capacity_id,
                'date' => $date,
                'timeslot_value' => $timeslot,
                'timeslot' => $timeslot_type,
                'available' => $available_capacity,
                'actual_capacity' => $actual_capacity,
                'ticket_id' => $ticket_id,
                'active' => $active_slot,
                'museum_id' => $supplier_id
            );
            $this->block_timeslots($post);
        }
        /* #endregion Turns off timeslot(s) for a given Shared Capacity in chunks of 500 */

        echo 'Timeslots have been successfully updated for Shared Capacity ID = ' . $shared_capacity_id . '<br>';
        /* #endregion EOC of generate_batch_wise_timeslots */
    }
    /* #endregion Segregating timeslot(s) data in chunks of maximum 500 for every given Shared Capacity for which timeslot(s) must be turned off */
    
    /* #region  Turns off timeslot(s) */
    /**
     * block_timeslots
     *
     * @param  mixed $post - Timeslots (and their relevant data) which must be turned off
     * @return void
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function block_timeslots($post)
    {
        /* #region  BOC of block_timeslots */
        $this->load->model('block_capacity_model','block_capacity_model');

        $active = $post['active']; 
        /* #region  If timeslot(s) data sent */
        if (isset($post)) {
            $data = $post;
        }
        /* #endregion If timeslot(s) data sent */
        $shared_capacity_id = $post['shared_capacity_id'];
            
        foreach($data['timeslot_value'] as $timeslot_key => $timeslot_value) {
            $timeslot = explode(' - ', $timeslot_value);
            $data['from_time'][] = $timeslot[0];
            if ($data['timeslot'][$timeslot_key] == 'specific') {
                $to_time = strtotime("+15 minutes", strtotime($timeslot[0]));
                $data['to_time'][] = date('H:i', $to_time);
            } else {
                $data['to_time'][] = $timeslot[1];
            }
        }
        unset($data['timeslot_value']);

        $this->primarydb->db->select('*');
        $this->primarydb->db->from('modeventcontent');
        $this->primarydb->db->where('own_capacity_id', $shared_capacity_id);
        $this->primarydb->db->where('is_own_capacity', '3');
        $mec_query = $this->primarydb->db->get();
        $mec_result = $mec_query->result();
        if ($mec_result[0]->shared_capacity_id != '') {
            $active_query = 'SELECT is_active from ticket_capacity_v1 where shared_capacity_id = ' . $mec_result[0]->shared_capacity_id . ' and date="'.$data['date'].'" and from_time="'.$data['from_time'].'" and to_time="'.$data['to_time'].'"';
            $active_result = $this->primarydb->db->query($active_query)->row();

            if ($active_result->is_active == '0') {
                echo 'Cannot enable '.$data['from_time'].'-'.$data['to_time'].' timeslot as shared timeslot is disabled.';
                exit;
            }
        }
        $data['shared_capacity_id'] = $shared_capacity_id;
        $shared_capacities = explode(',', $shared_capacity_id);
        $data_from_ticket_capacity = $this->block_capacity_model->get_ticket_capacity_data($data);
        
        /* #region  Generate Insert and/or Update data for every DB (Firebase, Redis, MySQL) */
        if (isset($post['timeslot_value'])) {
            $insert_update_array = $this->generate_insert_update_array($post, $data_from_ticket_capacity, $shared_capacities, $data);
            $insertArray = $insert_update_array['insert_array'];
            $updateArray = $insert_update_array['update_array'];
            $ticket_capacity_ids = $insert_update_array['ticket_capacity_ids'];
            $insert_capacity_data = $insert_update_array['insert_capacity_data'];
        }
        /* #endregion Generate Insert and/or Update data for every DB (Firebase, Redis, MySQL) */

        /* #region  Insert/Update Firebase and Redis data */
        if(!empty($insertArray) || !empty($updateArray)) {
            $this->insert_update_firebase_redis($post, $insertArray, $updateArray);
        }
        /* #endregion Insert/Update Firebase and Redis data */

        if (!empty($insert_capacity_data)) {
            $this->block_capacity_model->insert_batch('ticket_capacity_v1', $insert_capacity_data);
        }
        if (!empty($ticket_capacity_ids)) {
            $cap_status = '';
            if ($active == 1) {
                $cap_status ='_ON';
            } else if ($active == 0) {
                $cap_status ='_OFF';
            }

            $this->db->set('is_active', $active); 
            $this->db->set('modified', gmdate('Y-m-d h:i:s'));
            $this->db->set('action_performed', 'if(action_performed IS NULL,"ADMND_CAP'. $cap_status.'",CONCAT(action_performed,",","ADMND_CAP'. $cap_status.'"))', FALSE);
            $this->db->set('updated_by', 'manual_script');
            $this->db->where_in('ticket_capacity_id', $ticket_capacity_ids);
            $this->db->update('ticket_capacity_v1');
        }
        /* #endregion EOC of block_timeslots */
    }
    /* #endregion Turns off timeslots */
    
    /* #region  Generates Insert and/or Update data for every DB (Firebase, Redis, MySQL) */
    /**
     * generate_insert_update_array
     *
     * @param  mixed $post - Timeslots (and their relevant data) which must be turned off
     * @param  mixed $data_from_ticket_capacity
     * @param  mixed $shared_capacities
     * @param  mixed $data
     * @return array Insert/Update data for every DB (Firebase, Redis, MySQL)
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function generate_insert_update_array($post, $data_from_ticket_capacity, $shared_capacities, $data)
    {
        /* #region  BOC of generate_insert_update_array */
        $insertArray = $updateArray = array();
        $counter = 0;

        foreach ($post['timeslot_value'] as $timeslot_key => $timeslot_value) {
            $key = $post['date'][$timeslot_key]."_".$timeslot_value;
            if (!empty($data_from_ticket_capacity) && array_key_exists($key, $data_from_ticket_capacity)) {
                foreach ($data_from_ticket_capacity as $capacity_key => $capacity_value) {
                    if ($key == $capacity_key) {
                        $ticket_capacity_ids[] = $capacity_value['ticket_capacity_id'];
                        $keyMatch = implode("_", array_filter(array($post['shared_capacity_id'], $data['date'][$timeslot_key], $data['from_time'][$timeslot_key], $data['to_time'][$timeslot_key])));
                        array_push($updateArray, array(
                            "ticket_id" => $post['shared_capacity_id'], 
                            "date" => $data['date'][$timeslot_key], 
                            "from_time" => $data['from_time'][$timeslot_key], 
                            "to_time" => $data['to_time'][$timeslot_key], 
                            "adjustment_type" => $capacity_value['adjustment_type'], 
                            "adjustment" => $capacity_value['adjustment'], 
                            "is_active" => $post['active'],
                            "own_capacity_ids" => $shared_capacities, 
                            "key_match" => $keyMatch
                        ));
                    }
                }
            } else {
                $counter++;
                $new_capacity_data['date'] = $data['date'][$timeslot_key];
                $new_capacity_data['timeslot'] = $data['timeslot'][$timeslot_key];
                $new_capacity_data['from_time'] = $data['from_time'][$timeslot_key];
                $new_capacity_data['to_time'] = $data['to_time'][$timeslot_key];
                $new_capacity_data['actual_capacity'] = $data['actual_capacity'][$timeslot_key];
                $new_capacity_data['sold'] = $data['actual_capacity'][$timeslot_key] - $data['available'][$timeslot_key];
                $new_capacity_data['created'] = gmdate('Y-m-d h:i:s');
                $new_capacity_data['modified'] = gmdate('Y-m-d h:i:s');
                $new_capacity_data['ticket_id'] = $post['ticket_id'];
                $new_capacity_data['shared_capacity_id'] = $post['shared_capacity_id'];
                $new_capacity_data['is_active'] = $post['active']; 
                $cap_status = '';
                if ($post['active'] == 1) {
                    $cap_status ='_ON';
                } else if ($post['active'] == 0) {
                    $cap_status ='_OFF';
                }
                $new_capacity_data['action_performed'] = 'ADMND_CAP'.$cap_status;
                $new_capacity_data['updated_by'] = $this->session->userdata['uname'];
                $new_capacity_data['museum_id'] = $post['museum_id'];
                $new_capacity_data['updated_by'] = 'manual_script';
                $insert_capacity_data[] = $new_capacity_data;
                if ($counter % 100 == 0) {
                    $this->block_capacity_model->insert_batch('ticket_capacity_v1', $insert_capacity_data);
                    $insert_capacity_data = array();
                }
                $keyMatch = implode("_", array_filter(array($post['shared_capacity_id'], $data['date'][$timeslot_key], $data['from_time'][$timeslot_key], $data['to_time'][$timeslot_key])));
                array_push($insertArray, array(
                    "ticket_id" => $post['shared_capacity_id'], 
                    "date" => $data['date'][$timeslot_key], 
                    "from_time" => $data['from_time'][$timeslot_key], 
                    "to_time" => $data['to_time'][$timeslot_key], 
                    "adjustment_type" => 0, 
                    "adjustment" => 0, 
                    "is_active" => (int) $post['active'],
                    "own_capacity_ids" => $shared_capacities, 
                    "key_match"  => $keyMatch
                ));
            }
        }

        return array(
            'insert_array' => $insertArray,
            'update_array' => $updateArray,
            'ticket_capacity_ids' => $ticket_capacity_ids,
            'insert_capacity_data' => $insert_capacity_data
        );
        /* #endregion EOC of generate_insert_update_array */
    }
    /* #endregion Generates Insert and/or Update data for every DB (Firebase, Redis, MySQL) */
    
    /* #region  Inserts/Updates data on Firebase and Redis */
    /**
     * insert_update_firebase_redis
     *
     * @param  mixed $post - Timeslots (and their relevant data) which must be turned off
     * @param  mixed $insertArray - Data to be inserted
     * @param  mixed $updateArray - Date to be updated
     * @return void
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function insert_update_firebase_redis($post, $insertArray, $updateArray)
    {
        /* #region  BOC of insert_update_firebase_redis */
        
        $headers = $this->all_headers_new('Manage capacity block date range' , $post['ticket_id']);

        
        $from_date = current($post['date']);
        $to_date = end($post['date']);

        $json_data = json_decode($json_data);
        $request = json_encode(array(
            "ticket_id" => $post['ticket_id'],
            "shared_capacity_id" => $post['shared_capacity_id'], 
            "ticket_class" => '2',
            "from_date" => $from_date,
            "to_date" => $to_date
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/listcapacity");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $getdata = curl_exec($ch);
        curl_close($ch);

        if (empty($getdata)) {
            return;
        }
        
        $available_capacity = json_decode($getdata, true);
        $dates = array_column($available_capacity['data'], 'date');
        
        if (empty($dates)) {
            return;
        }        

        $requestDelete = json_encode(array("shared_capacity_id" => $post['shared_capacity_id'], "dates" => $dates));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/delete_avaliability_by_date");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestDelete);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        curl_close($ch);
        $timeSlots = array();
        $keyMatchUpd = (!empty($updateArray) ? array_column($updateArray, 'key_match'): array());
        $keyMatchIns = (!empty($insertArray) ? array_column($insertArray, 'key_match'): array());

        $redisUpdateData = array();
        foreach($available_capacity['data'] as $hours) {

            $dataTimeSlot = array();
            $timeslot_array = $this->generate_timeslot_array($keyMatchUpd, $keyMatchIns, $hours, $post['shared_capacity_id'], $redisUpdateData, $post['active']);
            $dataTimeSlot = $timeslot_array['dataTimeSlot'];
            
            $timeSlots[] = array($hours['date'] => array("timeslots" => $dataTimeSlot));
        }

        if(!empty($timeSlots)) {
            /* Update availability for tickets on Firebase. */
            $params = json_encode(array("node" => 'ticket/availabilities/' . $post['shared_capacity_id'], 'details' => $timeSlots));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/update_avaliability_by_date");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
            curl_close($ch);
        }
        $json_update = json_encode(array(
            "shared_capacity_id" => $post['shared_capacity_id'],
            "modified_at" => gmdate("Y-m-d H:i:s"),
            "data" => $redisUpdateData,
            "action" => (bool) $post['active']
        ), true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, REDIS_SERVER . "/update_all_timeslot_settings");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_update);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        curl_close($ch);
        /* #endregion EOC of insert_update_firebase_redis */
    }
    /* #endregion Inserts/Updates data on Firebase and Redis */
    
    /* #region  Generates timeslot data array to be updated in Firebase and Redis */
    /**
     * generate_timeslot_array
     *
     * @param  mixed $keyMatchUpd
     * @param  mixed $keyMatchIns
     * @param  mixed $hours
     * @param  mixed $shared_capacity_id - Shared Capacity ID of the Shared Capacity for which timeslots must be tunred off
     * @return array Timeslot data array to be updated in Firebase and Redis
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function generate_timeslot_array($keyMatchUpd, $keyMatchIns, $hours, $shared_capacity_id, &$redisUpdateData, $turn_on_timeslots)
    {
        /* #region  BOC of generate_timeslot_array */
        $replace = array("-", ":", "/", "_");
        $date = $hours['date'];

        foreach ($hours['timeslots'] as $timeslot) {

            $keyFind = $shared_capacity_id . '_' . $date . '_' . $timeslot['from_time'] . '_' . $timeslot['to_time'];
            $slot_id = implode("", array(
                str_replace($replace, "", $date), 
                str_replace($replace, "", $timeslot['from_time']), 
                str_replace($replace, "", $timeslot['to_time']), 
                $shared_capacity_id
            ));
            $total_capacity = $timeslot['total_capacity'];
            if ($timeslot['adjustment_type'] == 1) {
                $total_capacity = $timeslot['total_capacity'] + $timeslot['adjustment'];
            } else if ($timeslot['adjustment_type'] == 2) {
                $total_capacity = $timeslot['total_capacity'] - $timeslot['adjustment'];
            }

            $activeMod = false;
            if(in_array($keyFind, $keyMatchUpd) || in_array($keyFind, $keyMatchIns)) {
                $activeMod = true;
            }

            if (!empty($timeslot['from_time'])) {
                $this->generate_data_time_slot_array($dataTimeSlot, $slot_id, $total_capacity, $activeMod, $timeslot, $turn_on_timeslots);
            }
            if( strlen((string) array_search($keyFind, $keyMatchUpd)) > 0 || strlen((string) array_search($keyFind, $keyMatchIns)) > 0 ) {
                $redisUpdateData[$date][] = $timeslot['from_time'] . '_' . $timeslot['to_time'];
            }
        }

        return array('dataTimeSlot' => $dataTimeSlot, 'redisUpdateData' => $redisUpdateData);
        /* #endregion EOC of generate_timeslot_array */
    }
    /* #endregion Generates Timeslot Data array to be updated in Firebase and Redis */
    
    /* #region  Generates timeslot data array to be updated in Firebase */
    /**
     * generate_data_time_slot_array
     *
     * @param  mixed $dataTimeSlot - Timeslot data array to be updated in Firebase
     * @param  mixed $slot_id
     * @param  mixed $total_capacity
     * @param  mixed $activeMod
     * @param  mixed $timeslot
     * @return void
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 17th July, 2019
     */
    private function generate_data_time_slot_array(&$dataTimeSlot, $slot_id, $total_capacity, $activeMod, $timeslot, $turn_on_timeslots)
    {
        /* #region  BOC of generate_data_time_slot_array */
        array_push($dataTimeSlot, array(
            'from_time' => $timeslot['from_time'],
            'to_time' => $timeslot['to_time'],
            'type'=> $timeslot['timeslot_type'],
            'is_active' => $activeMod ? (bool) $turn_on_timeslots : (bool) $timeslot['is_active'], 
            'slot_id' => $slot_id, 
            'bookings' => (int) $timeslot['bookings'],
            'total_capacity' => (int) $total_capacity, 
            'blocked' => (int) isset($timeslot['blocked']) ? $timeslot['blocked'] : 0,
        ));
        /* #endregion EOC of generate_data_time_slot_array */
    }
    /* #endregion Generates timeslot data array to be updated in Firebase */

    /* #region  To block timeslots via local queue */
    /**
     * generate_data_time_slot_array
     *
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 08th June, 2020
     */
    public function block_timeslots_via_local_queue() {
        $postBody = file_get_contents('php://input');
        $this->CreateLog('block_timeslots', 'step 1', array("params" => $postBody));
        $string = gzuncompress(base64_decode($postBody));
        $string = utf8_decode($string);
        $data = json_decode($string, true);
        if (!empty($data['shared_capacity_ids'])) {
            foreach ($data['shared_capacity_ids'] as $shared_capacity_id) {
                $this->turn_off_timeslot(0,$shared_capacity_id,$data['start_date'],$data['end_date'], $data['turn_on_timeslots']);
            }
        }
    }
    /* #endregion To block timeslots via queue */

    /* #region  To block timeslots via queue */
    /**
     * generate_data_time_slot_array
     *
     * @author Jatin Mittal <jatin.aipl01@gmail.com> on 08th June, 2020
     */
    public function block_timeslots_via_queue() {
        $postBody = file_get_contents('php://input');
        $this->CreateLog('block_timeslots', 'step 1', array("params" => $postBody));
        include_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new Sqs();
        $queue_url = BLOCK_BATCH_QUEUE_URL;
        $messages = $sqs_object->receiveMessage($queue_url);
        $messages = $messages->getPath('Messages');
        if ($messages) {
            foreach ($messages as $message) {
                $string = $message['Body'];
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $this->CreateLog('block_timeslots', 'step 2', array("params" => $string));
                $string = str_replace("?", "", $string);
                $main_request = json_decode($string, true);
                foreach ($main_request['shared_capacity_ids'] as $shared_capacity_id) {
                    $this->turn_off_timeslot(0,$shared_capacity_id,$main_request['start_date'],$main_request['end_date'], $main_request['turn_on_timeslots']);
                }
                $sqs_object->deleteMessage($queue_url, $message['ReceiptHandle']);
            }
        }
    }
    /* #endregion To block timeslots via queue */
}