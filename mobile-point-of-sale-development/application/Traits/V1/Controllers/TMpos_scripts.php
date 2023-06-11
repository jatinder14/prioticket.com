<?php 

namespace Prio\Traits\V1\Controllers;

trait TMpos_scripts {
   
    public function __construct()  {
        parent::__construct();
        global $MPOS_LOGS;
        global $internal_logs;
        $this->firebase_redis_urls = json_decode(FIREBASE_REDIS_URLS, true);
    }

    /**
     * @Name : check_queue_data()
     * @Purpose : To check strucked records in a queue.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 13 March 2019
     */
    function check_queue_data($queue_name = 'test_graylog_queue', $delete = 0, $vgn = "") {
        // Load SQS library.
        require_once 'aws-php-sdk/aws-autoloader.php';
        $this->load->library('Sqs');
        $sqs_object = new \Sqs();
        $queueUrl = 'https://sqs.eu-west-1.amazonaws.com/783885816093/' . $queue_name;
        $messages = $sqs_object->receiveMessage($queueUrl);
        $messages = $messages->getPath('Messages');
        if (!empty($messages)) {
            foreach ($messages as $message) {
                $string = $message['Body'];
                $string = gzuncompress(base64_decode($string));
                $string = utf8_decode($string);
                $data = json_decode($string, true);
                if (isset($data['hotel_ticket_overview_data'][0]['visitor_group_no']) && ($data['hotel_ticket_overview_data'][0]['visitor_group_no'] == $vgn) && $delete == 1 && $queue_name == "mpos_live_orders_queue") {
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                }elseif(($queue_name != "mpos_live_orders_queue") && $delete == 1){
                    $sqs_object->deleteMessage($queueUrl, $message['ReceiptHandle']);
                }
                    
                echo '<pre>';
                print_r($data);
                echo '</pre>';
            }
        }
    }
    
    /*
    * @Name     : sync_encrypted_passwords()
    * @Purpose  : to sync encrypted passwords of all suppliers users on firebase
    * live_md.php : a php file having $data = (suppliers json) which is exported from live firebase
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 5 April 2019 
    */
    function sync_encrypted_passwords() {
        ini_set('memory_limit', '-1');
        include '/home/intersoft-admin/Desktop/live_md.php';
        $all_suppliers = json_decode($data, true);
        foreach ($all_suppliers as $sup_id => $suppliers) {
            if(isset($suppliers['users']) && !empty($suppliers['users'])){
                $supplier_data[$sup_id] = $suppliers['users'];
            }
        }
        foreach ($supplier_data as $sup_id => $users) {
            foreach ($users as $key => $user) {
                $fetch_passwords = $this->common_model->find('users', array('select' => 'password', 'where' => 'id = "' . $user['user_id'] . '"'), 'row_array');
                $users[$key]['password'] = (string) $fetch_passwords['password'];
            }
            $getdata = $this->curl->request('FIREBASE', '/update_details', array(
                'type' => 'POST',
                'additional_headers' => $this->common_model->all_headers(array('action' => 'sync_encrypted_passwords_script', 'museum_id' => $sup_id)),
                'body' => array("node" => '/suppliers/' . $sup_id . '/users', 'details' => $users)
            ));
            echo '<pre>';
            print_r(json_decode($getdata, true));
            echo '<br>';
        }
    }
    
    /*
    * @Name     : find_unnotified_records()
    * @Purpose  : to find 3rd party records in pt which are not notified to CSS
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 6 May 2019 
    */
    function find_unnotified_records($start_date, $end_date){
        $start_date = $start_date.' 00:00:00';
        $end_date = $end_date.' 23:59:00';
        $query = 'select passNo, redemption_notified_at, used, bleep_pass_no, second_party_type, third_party_type, is_addon_ticket, action_performed from prepaid_tickets where redemption_notified_at >= "'.$start_date.'" and redemption_notified_at <= "'.$end_date.'" and (used = "1" or (used = "0" and bleep_pass_no != "")) and (second_party_type = "5" or third_party_type = "5") and is_addon_ticket = "0" GROUP by passNo';
        
        $records = $this->secondarydb->db->query($query)->result_array();
        foreach ($records as $record){
            if(strpos($record['action_performed'], 'CSS_ACT') !== false || strpos($record['action_performed'], 'SCAN_CSS') !== false || strpos($record['action_performed'], 'SCAN_TB') !== false || strpos($record['action_performed'], 'CSS_GCKN') !== false || strpos($record['action_performed'], 'SCAN_PRE_CSS') !== false){
                $passes[$record['passNo']] = $record;
            }
        }
        
        $notified_query = 'select passNo, scanned_at, visitor_group_no, notified_at from notify_to_third_parties where notified_at >= "'.$start_date.'" and notified_at <= "'.$end_date.'"';
        
        $notified_records = $this->primarydb->db->query($notified_query)->result_array();
        foreach ($notified_records as $notified_record){
            $notified_passes[$notified_record['passNo']] = $notified_record;
        }
        $string = '';
        foreach($passes as $pass_no => $data){
            //pass from pt exists in notified ppasses or not
            if (!array_key_exists($pass_no, $notified_passes)){
                $unnotified_passes[] = $pass_no;
                $string .= '"'.$pass_no.'", ';
            }
        }
        
        echo $string;
        echo '<pre>';
        print_r($unnotified_passes);
        echo '</pre>';
        exit;
    }
    /*
    * @Name     : import_prev_users()
    * @Purpose  : to import users that are not on firebase
    * @Created  : komal <komalgarg.intersoft@gmail.com>
    */
    function import_prev_users($cod_id = 0, $cashier_id = 0, $tanantID = 0) {
        if((isset($cod_id) && !(is_numeric($cod_id))) || (isset($cashier_id) && !(is_numeric($cashier_id))) || (isset($tanantID) && !(is_numeric($tanantID)))){
            echo "<pre>";
            echo "cannot sync users";
            exit;
        }
        // Static Check for not syncing reporting-auth-live-sync@prioticket.com
        if($cashier_id == "1444216"){
            echo "<pre>";
            echo "cannot sync this ID 1444216";
            exit;
        }
        $platform_details = $this->common_model->find('platform_settings', array('select' => 'id, title'), 'list');
        $this->primarydb->db->select('cod_id, own_supplier_id, pos_type, is_venue_app_active, cashier_type, reseller_id, reseller_name')->from('qr_codes');
        if($cod_id != '' && $cod_id != 0) {
            $this->primarydb->db->where_in('cod_id', $cod_id);
        }
        $company_details = $this->primarydb->db->get()->result_array();
        foreach($company_details as $data) {
            $company[$data['cod_id']] = array(
                'own_supplier_id' => $data['own_supplier_id'],
                'cashier_type' => $data['cashier_type'],
                'pos_type' => $data['pos_type'],
                'reseller_id' => $data['reseller_id'],
                'reseller_name' => $data['reseller_name'],
                'is_venue_app_active' => $data['is_venue_app_active']
            );
        }
        $cashier_condition = '';
        if($cashier_id > 0) {
            $cashier_condition = ' and id in ('.$cashier_id.')';
        }
        if($cod_id != 0) {
            $all_users = $this->common_model->find('users', array('select' => 'id, feature_type, user_type, uname, fname, lname, password, firebase_user_id, id, cod_id, reseller_id, company, market_merchant_id,default_distributor, default_cashier', 'where' => 'cod_id IN ('.implode(',', array_keys($company)).') and deleted = "0"'.$cashier_condition ));
        } else {
            $all_users = $this->common_model->find('users', array('select' => 'id, feature_type, user_type, uname, fname, lname, password, firebase_user_id, id, cod_id, reseller_id, company, market_merchant_id,default_distributor, default_cashier', 'where' => 'deleted = "0"'.$cashier_condition ));
        }
        echo 'all users from DB';
        echo '<pre>';
        print_r($all_users);
        echo '</pre>';
        foreach($all_users as $user) {
            if($user['id'] == "1444216" || $user['uname'] == "reporting-auth-live-sync@prioticket.com"){
                echo "<pre>";
                echo "cannot sync the ID 1444216 or email reporting-auth-live-sync@prioticket.com passed.";
                continue;
            }
            if($user['firebase_user_id'] != '') {
                $firebase_user_id = $user['firebase_user_id'];
            } else {
                $firebase_user_id = base64_encode($user['cod_id'].'_'.$user['uname']);
                $this->common_model->update('users', array('firebase_user_id' => $firebase_user_id), array('id' => $user['id']));
            }
            if($company[$user['cod_id']]['cashier_type'] == 1) {
                $custom_claims = array(
                    'cashierType' => 1,
                    'cashierId' => (string)$user['id'],
                    'distributorId' => (string)$user['cod_id'],
                    'distributorName' => (string)$user['company'],
                    'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                    'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                    'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                    'posType' => ($company[$user['cod_id']]['pos_type'] == 2 && $company[$user['cod_id']]['is_venue_app_active'] == 1) ? 6 : $company[$user['cod_id']]['pos_type']
                );
		        $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'hotel_id' => $user['cod_id'], 'pos_type' => $custom_claims['posType']));
            } else if($company[$user['cod_id']]['cashier_type'] == 2) {
                $custom_claims = array(
                    'cashierType' => 2,
                    'cashierId' => (string)$user['id'],
                    'supplierId' => (string)$user['cod_id'],
                    'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                    'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                    'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                );
                $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'museum_id' => $user['cod_id'], 'pos_type' => $custom_claims['posType']));
            } else {
              
                if($user['user_type'] == 'superAdmin') { //superAdmin user
                    $custom_claims = array(
                        'cashierType' => 4,
                        'cashierId' => (string)$user['id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : ''
                    );                   
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id']));
                } else if($user['feature_type'] == '3') {
                    $platform_settings = $this->common_model->find('platform_settings', array('select' => 'title', 'where' => 'id = "'.$user['cod_id'].'"'));
                    $custom_claims = array(
                        'cashierType' => 5,
                        'cashierId' => (string)$user['id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['cod_id']]) ? (string) $platform_details[$user['cod_id']] : ''
                    );
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'platformId' => $user['cod_id'], 'platformName' => $platform_settings[0]['title']));
                } else if($user['cod_id'] == 0 || $user['cod_id'] == NULL || $user['cod_id'] == '') {
                    $distributor = $this->common_model->find('qr_codes', array('select' => 'cod_id', 'where' => 'reseller_id = "'.$user['reseller_id'].'" and is_venue_app_active = 1'));
                    $reseller_details = $this->common_model->find('resellers', array('select' => 'reseller_id, country_code', 'where' => 'reseller_id = "'.$user['reseller_id'].'"'));
                    $reseller_regional_setting = $this->get_regional_settings($user['reseller_id'], $reseller_details[0]['country_code']);
                    $defaultDistributorData = "";
                    if(isset($_GET['defaultCashierId'])) {
                        $defaultCashierData = $this->common_model->find('users', array('select' => 'fname, lname, company', 'where' => 'id = "'. $_GET['defaultCashierId'] .'" and deleted = "0"'));
                    }else{
                        if(isset($user['default_cashier']) && $user['default_cashier']!= null){
                            $defaultCashierData = $this->common_model->find('users', array('select' => 'fname, lname, company', 'where' => 'id = "'. $user['default_cashier'] .'" and deleted = "0"'));
                        }
                    }
                    $custom_claims = array(
                        'cashierType' => 3,
                        'cashierId' => (string)$user['id'],
                        'resellerId' => (string)$user['reseller_id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                        'defaultDistributorId' => isset($_GET['defaultDistributorId']) ? $_GET['defaultDistributorId'] : (string) $user['default_distributor'],
                        'defaultCashierId' => isset($_GET['defaultCashierId']) ? $_GET['defaultCashierId'] : ($user['default_cashier'] ? $user['default_cashier'] : (string) 0), //update cashier  id received from request else 0
                        'defaultCashierName' => ( !empty($defaultCashierData)) ? $defaultCashierData[0]['fname']." ".$defaultCashierData[0]['lname'] : "",
                        'defaultDistributorName' => (!empty($defaultCashierData)) ? $defaultCashierData[0]['company'] : "",
                        'regional_settings' => $reseller_regional_setting
                    );
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'reseller_id' => $user['reseller_id'], 'pos_type' => $custom_claims['posType']));
                } else {
                    $custom_claims = array(
                        'cashierType' => 2,
                        'cashierId' => (string)$user['id'],
                        'supplierId' => (string)$user['cod_id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                        'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                        'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                    );
                }
            }
            if($tanantID == 1){
                if($user['market_merchant_id'] != ''){
                    $platform_details_tenant = $this->common_model->find('platform_settings', array('select' => 'id, tenant_id'), 'list');
                    $tenant_id = $platform_details_tenant[$user['market_merchant_id']] ;
                    $user_details = array(
                        'username' => $user['fname'].' '.$user['lname'],
                        'email' => $user['uname'],
                        'password' => $user['password'],
                        'uid' => $firebase_user_id,
                        'custom_claims' => $custom_claims,
                        'tenantId' => $tenant_id
                    );
                    $getdata = $this->curl->request('FIREBASE', '/import_users_v1', array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => $user_details
                    ));
                }
            }else{
                $user_details = array(
                    'username' => $user['fname'].' '.$user['lname'],
                    'email' => $user['uname'],
                    'password' => $user['password'],
                    'uid' => $firebase_user_id,
                    'custom_claims' => $custom_claims
                );
                $getdata = $this->curl->request('FIREBASE', '/import_users', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => $user_details
                ));
            }
            
            echo '<pre>';
            print_r($user_details);
            echo '</pre>';
            echo json_encode($user_details);
            
            print_r($getdata);
            print_r(json_decode($getdata, true));
        }
    }
    
    /*
    * @Name     : import_users()
    * @Purpose  : to import users from db and sync in firebase
    * @Created  : supriya saxena <supriya10.aipl@gmail.com> on 10 sept, 2019
    */
    function import_users() {
        exit;
        try {
            $response = array();
            // If some data is passed in request    
            $jsonStr = file_get_contents("php://input", 'r'); //read the HTTP body.
            $_REQUEST = json_decode($jsonStr, TRUE);
            $platform_details = $this->common_model->find('platform_settings', array('select' => 'id, title'), 'list');
            $user_name = $_REQUEST['username'];
            $password = md5($_REQUEST['password']);
            $passwrd = $this->hash->hash_password($password);
            $user_details = $this->common_model->find('users', array('select' => 'id, uname, fname, lname, password, firebase_user_id, id, cod_id, company', 'where' => 'uname = "' . $user_name . '" and password = "' . $passwrd . '" and deleted = "0"'));
            $cashier_id = $user_details[0]['cod_id'];
            $cashier_details = $this->common_model->find('qr_codes', array('select' => 'cod_id, own_supplier_id, pos_type, is_venue_app_active, cashier_type, reseller_id, reseller_name', 'where' => 'cod_id = "' . $cashier_id . '"'));
            $cashier_type = $cashier_details['0']['cashier_type'];

            //  sync users in firebase
            if ($user_details[0]['firebase_user_id'] != '') {
                $firebase_user_id = $user_details[0]['firebase_user_id'];
            } else {
                $firebase_user_id = base64_encode($user_details[0]['cod_id'] . '_' . $user_details[0]['uname']);
                $this->common_model->update('users', array('firebase_user_id' => $firebase_user_id), array('id' => $user_details[0]['id']));
            }
            if ($cashier_type == 1) {
                $custom_claims = array(
                    'cashierType' => 1,
                    'cashierId' => (string) $user_details[0]['id'],
                    'distributorId' => (string) $user_details[0]['cod_id'],
                    'distributorName' => (string)$user_details[0]['company'],
                    'platformId' => isset($user_details[0]['market_merchant_id']) ? (string) $user_details[0]['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user_details[0]['market_merchant_id']]) ? (string) $platform_details[$user_details[0]['market_merchant_id']] : '',
                    'adminId' => isset($cashier_details[0]['reseller_id']) ? (string)  $cashier_details[0]['reseller_id'] : '0',
                    'adminName' => isset($cashier_details[0]['reseller_name']) ? (string)  $cashier_details[0]['reseller_name'] : '',
                    'posType' => ($cashier_details[0]['pos_type'] == 2 && $cashier_details[0]['is_venue_app_active'] == 1) ? 6 : $cashier_details[0]['pos_type']
                );
                $headers = $this->common_model->all_headers(array('action' => 'import_users_script', 'user_id' => $user_details[0]['id'], 'hotel_id' => $user_details[0]['cod_id'], 'pos_type' => $custom_claims['posType']));
            } else if ($cashier_type == 2) {
                $custom_claims = array(
                    'cashierType' => 2,
                    'cashierId' => (string) $user_details[0]['id'],
                    'supplierId' => (string) $user_details[0]['cod_id'],
                    'platformId' => isset($user_details[0]['market_merchant_id']) ? (string) $user_details[0]['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user_details[0]['market_merchant_id']]) ? (string) $platform_details[$user_details[0]['market_merchant_id']] : '',
                    'adminId' => isset($cashier_details[0]['reseller_id']) ? (string)  $cashier_details[0]['reseller_id'] : '0',
                    'adminName' => isset($cashier_details[0]['reseller_name']) ? (string)  $cashier_details[0]['reseller_name'] : ''
                );
                $headers = $this->common_model->all_headers(array('action' => 'import_users_script', 'user_id' => $user_details[0]['id'], 'museum_id' => $user_details[0]['cod_id']));
            }
            $response = $this->curl->request('FIREBASE', '/create_user', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => array(
                    'username' => $user_details[0]['fname'] . ' ' . $user_details[0]['lname'],
                    'email' => $user_details[0]['uname'],
                    'password' => $user_details[0]['password'],
                    'uid' => $firebase_user_id,
                    'custom_claims' => $custom_claims
                )
            ));
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500(0, 'INTERNAL_SYSTEM_FAILURE', $e->getMessage());
            $response['exception'] = $e->getMessage();
        }
        echo json_encode($response);
    }
    
    
    /*
    * @Name     : delete_prev_users()
    * @Purpose  : to delete users from firebase
    * @Created  : komal <komalgarg.intersoft@gmail.com>
    */
    function delete_prev_users($cod_id = '') {
        $where = '';
        if($cod_id != '') {
            $where = ' and cod_id IN ('.$cod_id.')';
        }
        $all_users = $this->common_model->find('users', array('select' => 'firebase_user_id', 'where' => 'firebase_user_id != ""'.$where));
        foreach($all_users as $user) {
            $getdata = $this->curl->request('FIREBASE', '/create_user', array(
                'type' => 'POST',
                'additional_headers' => $this->common_model->all_headers(array('action' => 'delete_users_script', 'user_id' => $user['firebase_user_id'], 'museum_id' => $cod_id)),
                'body' => array(
                    'uid' => $user['firebase_user_id']
                )
            ));
            echo '<pre>';
            print_r(json_decode($getdata, true));
        }
    }
    
      /* 
     * purpose : To generate random bleep passes
     * parameters : $limit : number of passes to be generated.
     * result : prints a list of passes and query to be executed in database.
     */
    function generate_bleep_passes($limit = 0) {
        $j = 0;
        $passes = array();
        $all_passes = '';
        for ($i = 0; $i < $limit; $i++) {
            $random = $this->generate_random_number();
            /*check for duplicate passes*/
            $check_query = 'SELECT count(pass_no) as count from bleep_pass_nos where pass_no = ' . $random . '';
            $duplicate_entries = $this->db->query($check_query)->result_array();
            if ($duplicate_entries[0]['count'] == 0) {
                if ($j == 0) {
                    $all_passes .= '(NULL, "' . $random . '")';
                } else {
                    $all_passes .= ', (NULL, "' . $random . '")';
                }
                $passes[] = $random;
                $j++;
            }
        }
        $insert_query = 'INSERT INTO `bleep_pass_nos` (id, pass_no) VALUES ' . $all_passes;
        foreach($passes as $pass){
            echo $pass.'<br>';
        }
        echo $insert_query;
    }
    
    /*
    * @Name     : remote_logout_script()
    * @Purpose  : script to forcefully logout users
    * @Created  : komal <komalgarg.intersoft@gmail.com>
    */
    function remote_logout_script($user_id = '') {
        if ($user_id != '') {
            $user_data = $this->common_model->find('users', array('select' => 'firebase_user_id', 'where' => 'firebase_user_id != "" and id IN (' . $user_id . ') '));
            //get fcm tokens from firebase
            foreach ($user_data as $users) {
                echo $firebase_user_id.'<br>';
                $firebase_user_id = $users['firebase_user_id'];
                $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                    'type' => 'POST',
                    'additional_headers' => $this->common_model->all_headers(array('action' => 'remote_logout_from_script', 'user_id' => $user_id)),
                    'body' => array("node" => 'users/' . $firebase_user_id . '/loggedInDevices' )
                ));
                $user_details = json_decode($getdata);
                $all_fcm_tokens = $user_details->data;
                foreach($all_fcm_tokens as $machine_id => $token) {
                    $device_token[] = $token->fcmToken;
                    $details = array(
                        'logout' => true
                    );
                    $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                        'type' => 'POST',
                        'additional_headers' => $this->common_model->all_headers(array('action' => 'remote_logout_from_script', 'user_id' => $user_id)),
                        'body' => array("node" => 'users/'.$firebase_user_id.'/loggedInDevices/'.$machine_id, 'details' => $details)
                    )); 
                }
                $req_data = array(
                    "message" => "Remotely Logged Out",
                    "reason" => "Logged out forcefully by admin",
                    "notification_type" => 2
                );
                $getdata = $this->curl->request('FIREBASE', '/send_android_notification', array(
                    'type' => 'POST',
                    'additional_headers' => $this->common_model->all_headers(array('action' => 'remote_logout_from_script', 'user_id' => $user_id)),
                    'body' => array(
                        "reqData" => $req_data,
                        "device_token" => $device_token,
                        "auth_key" => "key=" . FIREBASE_SERVER_KEY
                    )
                ));
                print_r(json_decode($getdata, true));
            }
        } else {
            $headers = $this->common_model->all_headers(array('action' => 'remote_logout_from_script', 'user_id' => $user_id));
            $getdata = $this->curl->request('FIREBASE', '/get_details', array(
                    'type' => 'POST',
                    'additional_headers' => $headers,
                    'body' => array("node" => 'users' )
                ));
            $user_details = json_decode($getdata);
            $users_data = $user_details->data;
            foreach($users_data as $user_id => $array) {
                if(isset($array->loggedInDevices)) {
                    echo $user_id.'<br>';
                    foreach($array->loggedInDevices as $machine_id => $token) {
                        $device_token[] = $token->fcmToken;
                        $details = array(
                            'logout' => true
                        );
                        $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                            'type' => 'POST',
                            'additional_headers' => $headers,
                            'body' => array("node" => 'users/'.$firebase_user_id.'/loggedInDevices/'.$machine_id, 'details' => $details)
                        )); 
                    }
                    $req_data = array(
                        "message" => "Remotely Logged Out",
                        "reason" => "Logged out forcefully by admin",
                        "notification_type" => 2
                    );
                    $getdata = $this->curl->request('FIREBASE', '/send_android_notification', array(
                        'type' => 'POST',
                        'additional_headers' => $this->common_model->all_headers(array('action' => 'remote_logout_from_script', 'user_id' => $user_id)),
                        'body' => array(
                            "reqData" => $req_data,
                            "device_token" => $device_token,
                            "auth_key" => "key=".FIREBASE_SERVER_KEY
                        )
                    ));
                    print_r($getdata);
                }
            }
        }
        exit();
    }
    
    /*
    * @Name     : hash_password()
    * @Purpose  : to convert a password into hash format
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 15 May 2019 
    */
    function hash_password($password){
        $passwrd = $this->hash->hash_password(md5($password));
        echo $passwrd;
    }
    
    /*
    * @Name     : generate_codes()
    * @Purpose  : to generate and insert new random bleep passes in local db 
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 15 May 2019 
    */
    function generate_codes($limit = 5000) { 
        $all_passes = '';
        for($i =0; $i< $limit; $i++) {
            $random = $this->generate_random_number();
            if ($i == 0) {
                $all_passes .= '(NULL, "' . $random . '")';
            } else {
                $all_passes .= ', (NULL, "' . $random . '")';
            }
        }
        $insert_query = 'INSERT INTO `random` (id, code) VALUES ' . $all_passes;        
        $this->db->query($insert_query);
    }
    
    /*
    * @Name     : print_codes()
    * @Purpose  : to print bleep passes from local db
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 15 May 2019 
    */
    function print_codes(){
        $query = 'select code from random';
        $data = $this->db->query($query)->result_array();
        foreach ($data as $value){
            echo $value['code'].'<br>';
        }
    }
    
    /*
    * @Name     : print_query()
    * @Purpose  : to print query from local db
    * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 15 May 2019 
    * NOTE -> firstly comment Insert query  from my model -> insert_batch()
    */
    function print_query() { 
        $select_query = 'select code as pass_no from random ';
        $result = $this->db->query($select_query)->result_array();
        $arr = array();
        foreach($result as $data){
            $arr[] = $data;
        }
        $this->common_model->insert_batch('bleep_pass_nos', $arr);
    }
    
    /* 
     * purpose : To generate random passes to be used as postpaid passes, to save in mpos_assigned_passes
     * parameters : $hotel_id > distributor to which these passes belong, $limit => number of passes to be generated.
     * result : prints a list of passes which are successfully added in db.
     * @Created  : Vaishali Raheja <vaishali.intersoft@gmail.com> on date 26 june 2019 
     */
    function get_assigned_passes($hotel_id = 0, $limit = 5){
        if($hotel_id != 0) {
            $hotel_details = $this->common_model->find('qr_codes', array('select' => 'cashier_type', 'where' => 'cod_id = "' . $hotel_id . '"'), 'row_array');
            if($hotel_details['cashier_type'] == 1){
                $passes = '';
                $p = 0;
                do {
                    //generate random passes
                    $pass = 'P' . $this->common_model->get_sixteen_digit_pass_no();
                    $check_query = 'SELECT count(pass_no) as count from mpos_assigned_passes where pass_no = "' . $pass . '"';
                    $duplicate_entries = $this->db->query($check_query)->result_array();
                    if ($duplicate_entries[0]['count'] == 0) {
                        if ($p == 0) {
                            $passes .= '(' . $hotel_id . ', "' . $pass . '")';
                        } else {
                            $passes .= ', (' . $hotel_id . ', "' . $pass . '")';
                        }
                        $random_passes[] = $pass;
                        $p++;
                    }
                } while ($p < $limit);
                $insert_query = 'INSERT INTO `mpos_assigned_passes` (distributor_id, pass_no) VALUES ' . $passes;
                $this->db->query($insert_query);
                echo "passes are : " ;
                echo '<br>';
                foreach($random_passes as $pass){
                    echo $pass.'<br>';
                }
            } else {
                echo "enter distributor id";
            }
        } else {
            echo "enter valid hotel_id";
        }
    }
    
    /*
     * purpose : To find records which are redeemed in PT but not in VT n vice versa
     * AND To print Queries to update incorrect orders
     * created by : <vaishali.intersoft@gmail.com> Vaishali Raheja on 10 oct 2019
     */
    function mismatch_PT_VT_DB2($museum_id = '', $from_date = '', $to_date = '') {
        exit;
        ini_set('memory_limit','-1');
        ini_set('max_execution_time', '0'); // for infinite time of execution 

        if ($museum_id != '') {
            if ($from_date == '' || $to_date == '') {
                $from_date = gmdate("y-m-d 00:00:00");
                $to_date = gmdate("y-m-d 23:59:59");
            }
            echo $from_date . " to " . $to_date;
            echo "<br>";
            $from_date = strtotime($from_date);
            $to_date = strtotime($to_date);
            echo $from_date . " to " . $to_date;
            echo "<br>";
            $records_from_pt = $this->records_from_pt($museum_id, $from_date, $to_date);
            $records_from_vt = $this->records_from_vt($museum_id, $from_date, $to_date);
            
            echo "used records_from_pt are " . count($records_from_pt) . " , ";
            echo "used records_from_vt are " . count($records_from_vt);
            echo "<br>";
            $in_pt_but_not_in_vt_keys = array_diff(array_keys($records_from_pt), array_keys($records_from_vt));
            $in_vt_but_not_in_pt_keys = array_diff(array_keys($records_from_vt), array_keys($records_from_pt));

            echo "mismatches in PT " . count($in_pt_but_not_in_vt_keys) . " , ";
            echo "mismatches in VT " . count($in_vt_but_not_in_pt_keys);
            echo "<br>";
            foreach ($in_pt_but_not_in_vt_keys as $key) {
                $in_pt_but_not_in_vt[$key] = $records_from_pt[$key];
            }
            
            foreach ($in_vt_but_not_in_pt_keys as $key) {
                $in_vt_but_not_in_pt[$key] = $records_from_vt[$key];
            }
            
            echo '_______________________________________________________________________';
            echo "<br>";
            echo "<br>";
            foreach ($in_pt_but_not_in_vt as $vgn => $pt_id_data) {
                foreach ($pt_id_data as $transaction_id => $ticket_data) {
                    foreach ($ticket_data as $ticket_id => $data) {
                        foreach ($data as $pt_data) {
                            $update_vt_query = 'update visitor_tickets set action_performed = "' . $pt_data['action_performed'] . '", updated_by_username = "' . $pt_data['voucher_updated_by_name'] . '", updated_by_id = "' . $pt_data['voucher_updated_by'] . '", used = "' . $pt_data['used'] . '", visit_date = "' . $pt_data['scanned_at'] . '", redeem_method = "Voucher", voucher_updated_by = "' . $pt_data['voucher_updated_by'] . '", voucher_updated_by_name = "' . $pt_data['voucher_updated_by_name'] . '", booking_status = "1", updated_at = "' . $pt_data['updated_at'] . '"'
                                    . ' where vt_group_no = "' . $vgn . '" and transaction_id = "' . $transaction_id . '" and ticketId= "' . $ticket_id . '" ';
                            if ($pt_data['clustering_id'] != "" && $pt_data['clustering_id'] != "0" && $pt_data['clustering_id'] != NULL) {
                                $update_vt_query .= ' and targetlocation = "' . $pt_data['clustering_id'] . '"';
                            }
                            $update_vt_query .= ";";
                            echo $update_vt_query;
                            echo "<br>";
                            echo "<br>";
                        }
                    }
                }

                echo "<br>";
                echo "<br>";
            }

            foreach ($in_vt_but_not_in_pt as $vgn => $pt_id_data) {
                foreach ($pt_id_data as $transaction_id => $ticket_data) {
                    foreach ($ticket_data as $ticket_id => $data) {
                        foreach ($data as $vt_data) {
                            $update_pt_query = 'update prepaid_tickets set action_performed = "' . $vt_data['action_performed'] . '", museum_cashier_id = "' . $vt_data['voucher_updated_by'] . '", museum_cashier_name = "' . $vt_data['voucher_updated_by_name'] . '", used = "' . $vt_data['used'] . '", scanned_at = "' . $vt_data['visit_date'] . '", redeem_method = "Voucher", voucher_updated_by = "' . $vt_data['voucher_updated_by'] . '", voucher_updated_by_name = "' . $vt_data['voucher_updated_by_name'] . '", booking_status = "1", updated_at = "' . $vt_data['updated_at'] . '", '
                                    . 'redeem_date_time = "' . date("Y-m-d H:i:s", $vt_data['visit_date']) . '", scanned_at = "' . $vt_data['visit_date'] . '" '
                                    . ' where museum_id = "' . $vt_data['museum_id'] . '" and visitor_group_no = "' . $vgn . '" and ticketId= "' . $ticket_id . '"';
                            if ($vt_data['targetlocation'] != '' && $vt_data['targetlocation'] != "0") {
                                $update_pt_query .= ' and clustering_id = "' . $vt_data['targetlocation'] . '"';
                            }
                            $update_pt_query .= ";";
                            echo $update_pt_query;
                            echo "<br>";
                            echo "<br>";
                        }
                    }
                }
                echo "<br>";
                echo "<br>";
            }
        }
    }
    
    /*
     * purpose : To find records which are redeemed in PT 
     * called from : mpos_scripts/mismatch_PT_VT_DB2
     * created by : <vaishali.intersoft@gmail.com> Vaishali Raheja on 10 oct 2019
     */
    function records_from_pt($museum_id,$from_date, $to_date) {
       $pt_records = array();
       $query = 'select visitor_group_no, prepaid_ticket_id, ticket_id, clustering_id, used, visitor_tickets_id, action_performed, voucher_updated_by_name, voucher_updated_by, used, scanned_at, redeem_method, voucher_updated_by, voucher_updated_by_name, booking_status, updated_at from prepaid_tickets where museum_id = "'.$museum_id.'" and used = "1" and is_refunded != "1" and scanned_at >= "'.$from_date.'" and scanned_at <= "'.$to_date.'" ';
       echo  $query;
       echo "<br>";
       $records_from_pt = $this->secondarydb->db->query($query)->result_array();
       echo "rows_from_pt are ". count($records_from_pt);
       echo "<br>";
       foreach ($records_from_pt as $pt_record) {
            if($pt_record['clustering_id'] == "0" || $pt_record['clustering_id'] == "" || $pt_record['clustering_id'] == NULL) {
                $pt_records[$pt_record['visitor_group_no']][$pt_record['prepaid_ticket_id']][$pt_record['ticket_id']][] = $pt_record;
            } else {
                $pt_records[$pt_record['visitor_group_no']][$pt_record['prepaid_ticket_id']][$pt_record['ticket_id']][$pt_record['clustering_id']] = $pt_record;
            }
       }
       return $pt_records;
        
    }
    
    /*
     * purpose : To find records which are redeemed in VT
     * called from : mpos_scripts/mismatch_PT_VT_DB2
     * created by : <vaishali.intersoft@gmail.com> Vaishali Raheja on 10 oct 2019
     */
    function records_from_vt($museum_id,$from_date, $to_date) {
       $vt_records = array();
       $query = 'select vt_group_no, transaction_id, action_performed, voucher_updated_by, visit_date, museum_id, targetlocation, voucher_updated_by_name, used, updated_at, ticketId from visitor_tickets where museum_id = "'.$museum_id.'"  and used = "1" and is_refunded != "1" and visit_date >= "'.$from_date.'" and visit_date <= "'.$to_date.'"';
       echo  $query;
       echo "<br>";
       $records_from_vt = $this->secondarydb->db->query($query)->result_array();
       echo "rows_from_vt are ". count($records_from_vt);
       echo "<br>";
       foreach ($records_from_vt as $vt_record) {
            if($vt_record['targetlocation'] == "0" || $vt_record['targetlocation'] == "" || $vt_record['targetlocation'] == NULL) {
                $vt_records[$vt_record['vt_group_no']][$vt_record['transaction_id']][$vt_record['ticketId']][] = $vt_record;
            } else {
                $vt_records[$vt_record['vt_group_no']][$vt_record['transaction_id']][$vt_record['ticketId']][$vt_record['targetlocation']] = $vt_record;
            }
       }
       return $vt_records;
        
    }

    function create_cluster($hotel_id = 0, $main_ticket = 0, $sub_tickets_list = '') 
    {
        if (!($hotel_id == 0 || $main_ticket == 0 || $sub_tickets_list == '')) {
            $details = $this->common_model->find('modeventcontent', array('select' => 'mec_id, postingEventTitle, cod_id, is_reservation, museum_name', 'where' => 'mec_id in (' . $main_ticket.','.$sub_tickets_list . ') and deleted = "0"'), 'array');
            $tps_details = $this->common_model->find('ticketpriceschedule', array('select' => '*', 'where' => 'ticket_id in (' . $main_ticket.','.$sub_tickets_list . ') and deleted = "0"'), 'array');
            foreach ($details as $detail) {
                $ticket_detail[$detail['mec_id']] = $detail;
            }
            foreach ($tps_details as $typedetail) {
                $typedetails[$typedetail['ticket_id']][strtolower($typedetail['ticket_type_label'])."_".$typedetail['agefrom']."_".$typedetail['ageto']] = $typedetail;
            }
            $main_ticket_data = $typedetails[$main_ticket];
            unset($typedetails[$main_ticket]);
            foreach($typedetails as $subticket_id => $sub_ticket_data) {
                foreach ($sub_ticket_data as $type_age => $subtype_data) {
                    if(isset($main_ticket_data[$type_age])) {
                        $types = explode('_', $type_age);
                        $data = array(
                            'created_at' => date('Y-m-d H:i:s'),
                            'hotel_id' => $hotel_id,
                            'main_ticket_id' => $main_ticket,
                            'main_ticket_price_schedule_id' => $main_ticket_data[$type_age]['id'],
                            'cluster_ticket_id' => $subticket_id,
                            'cluster_ticket_title' => $ticket_detail[$subticket_id]['postingEventTitle'],
                            'ticket_museum_id' => $ticket_detail[$subticket_id]['cod_id'],
                            'ticket_museum_name' => $ticket_detail[$subticket_id]['museum_name'],
                            'scan_price' => $subtype_data['museum_price'],
                            'list_price' => $subtype_data['original_price'], 
                            'new_price' => $subtype_data['newPrice'],
                            'ticket_gross_price' => $subtype_data['pricetext'], 
                            'ticket_tax_id' => $subtype_data['ticket_tax_id'], //-----------------------
                            'ticket_tax_value' => $subtype_data['ticket_tax_value'], //-----------------------
                            'ticket_net_price' => $subtype_data['ticket_net_price'],
                            'is_reservation' => ($ticket_detail[$subticket_id]['is_reservation'] != '') ? $ticket_detail[$subticket_id]['is_reservation'] : '0',
                            'museum_id' => $ticket_detail[$subticket_id]['cod_id'],
                            'ticket_price_schedule_id' => $subtype_data['id'],
                            'ticket_type' => (int) (!empty($this->common_model->types[$types[0]]) && ($this->common_model->types[$types[0]] > 0)) ? $this->common_model->types[$types[0]] : 10,
                            'age_from' => $types[1],
                            'age_to' => $types[2],
                            'age_group' => ucfirst($types[0]),
                            'updated_at' => date('Y-m-d H:i:s'),
                            'pax' => $subtype_data['pax'],
                            'adjust_capacity' => $subtype_data['adjust_capacity']
                        );
                        echo "<br>";
                        echo $this->db->insert_string('cluster_tickets_detail', $data);
                        echo "<br>";
                    } else {
                        echo "sub ticket type ".$type_age." was not found in main ticket";
                    }
                }
            }
        }
    }
    
    /**
     * @Name : release_blocked_capacity()
     * @Purpose : To release blocked capacity.
     * @Call from : Cron
     * @Created : Jatinder Kumar <jatinder.aipl@gmail.com> on 25 Feb 2020
    */
    /*function release_blocked_capacity() {    
        $this->load->Model('V1/firebase_model');
        $this->firebase_model->release_blocked_capacity();
    }*/
    
    /*
     * @Name     : delete_multiple_nodes()
     * @Purpose  : To delete last date or date specific availability from firebase.
     * @Created  : Jatinder Kumar <jatinder.aipl@gmail.com> on date 02 March 2019
    */
    function delete_multiple_nodes($startDate='', $endDate='', $ids='') {
        
        if(empty(trim($startDate))) {
            $startDate = date('Y-m-d', strtotime('-31 days', strtotime(date('Y-m-d'))));
            $endDate = $startDate;
        }
        if(strtotime($startDate) >= time()) {
            echo "start date can not be current or future date."; exit;
        }
        if(strtotime($startDate) >= strtotime(date('Y-m-d'))) {
            echo "start date can not be current or future date."; exit;
        }
        if(strtotime($endDate) >= strtotime(date('Y-m-d'))) {
            echo "end date can not be current or future date."; exit;
        }
        if(!empty($startDate) && strtotime('-30 days', strtotime(date('Y-m-d'))) <= strtotime($startDate)) {
            echo "start date should be less then 30 days from current date."; exit;
        }
        if(!empty($endDate) && strtotime('-30 days', strtotime(date('Y-m-d'))) <= strtotime($endDate)) {
            echo "end date should be less then 30 days from current date."; exit;
        }
        
        $condition = '';
        if(!empty($ids)) {
            $condition = ' AND shared_capacity_id IN ('.$ids.')';
        }
        
        $tickets = $this->common_model->find('modeventcontent', array('select' => 'shared_capacity_id, own_capacity_id', 'where' => 'deleted="0" AND is_reservation="1" AND shared_capacity_id!="0" ' . $condition . ''), 'array');
        echo "<pre>QUERY: "; print_r($this->primarydb->db->last_query()); echo "</pre>";
        if(!empty($tickets)) {
            
            $headers = $this->common_model->all_headers(array('action' => 'remove_availability_from_firebase_from_script'));
            $shared_capacity_ids    = array_filter(array_column($tickets, 'shared_capacity_id'));
            $own_capacity_ids       = array_filter(array_column($tickets, 'own_capacity_id'));
            $allCapacityIds         = array_unique(array_merge($shared_capacity_ids, $own_capacity_ids));
            if(count($allCapacityIds) > 1000) {
                
                $chunks = array_chunk($allCapacityIds, 1000);
                $batches = count($chunks);
                echo "<pre>Batches: "; print_r($batches); echo "</pre>";
                foreach($chunks as $val) {
                    
                    $allIds = implode(",", $val);
                    $data = json_encode(array("shared_capacity_ids" => $allIds, "startDate" => $startDate, "endDate" => $endDate));
                    echo "<pre>"; print_r($allIds); echo "</pre>";
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/delete_multiple_nodes");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    $get_data = curl_exec($ch);
                    curl_close($ch);
                    echo "<pre>Firebase: "; print_r($get_data); echo "</pre>";
                }
            }
            else {
                
                $shared_capacity_ids = array_filter(array_column($tickets, 'shared_capacity_id'));
                $own_capacity_ids = array_filter(array_column($tickets, 'own_capacity_id'));
                $allIds = implode(",", array_unique(array_merge($shared_capacity_ids, $own_capacity_ids)));
                $data = json_encode(array("shared_capacity_ids" => $allIds, "startDate" => $startDate, "endDate" => $endDate));
                echo "<pre>"; print_r($allIds); echo "</pre>";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, FIREBASE_SERVER . "/delete_multiple_nodes");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $get_data = curl_exec($ch);
                curl_close($ch);
                echo "<pre> Firebase: "; print_r($get_data); echo "</pre>";
            }
        }
    }
    
    /*
     * @Name     : delete_multiple_nodes_redis()
     * @Purpose  : To delete last date or date specific availability from redis.
     * @Created  : Jatinder Kumar <jatinder.aipl@gmail.com> on date 02 March 2019
    */
    function delete_multiple_nodes_redis() {
        
        if(empty(trim($startDate))) {
            $startDate = date('Y-m-d', strtotime('-31 days', strtotime(date('Y-m-d'))));
            $endDate = $startDate;
        }
        
        $headers = $this->common_model->all_headers(array('action' => 'remove_availability_from_firebase_from_script'));
        $data = json_encode(array("shared_capacity_ids" => '', "startDate" => $startDate, "endDate" => $endDate));
        echo "<pre>REQUEST: "; print_r($data); echo "</pre>";

        $ch1 = curl_init();
        curl_setopt($ch1, CURLOPT_URL, REDIS_SERVER . "/delete_multiple_nodes");
        curl_setopt($ch1, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);
        $get_data1 = curl_exec($ch1);
        curl_close($ch1);
        echo "<pre>Redis: "; print_r($get_data1); echo "</pre>";
    }
    
    /*
     * @Name     : disable_availability()
     * @Purpose  : To create json of tickets , which we need to stop sale
     * @Created  : Karanjeet Singh
    */
    function disable_availability($start_date = '', $end_date = '', $museum_id = '0', $product_id = '0') {
        if($museum_id != '0')  {
            $data = $this->db->query('select shared_capacity_id, own_capacity_id from modeventcontent where cod_id in ('.$museum_id.') and deleted = "0" and shared_capacity_id > 0')->result_array();
        } else if($product_id != '0')  {
            $data = $this->db->query('select shared_capacity_id, own_capacity_id from modeventcontent where mec_id in ('.$product_id.') and deleted = "0"  and shared_capacity_id > 0')->result_array();
        }
        $redis_config = array();
        foreach($data as $key) {
            $redis_config[$key['shared_capacity_id']]['shared_capacity_id'] = $key['shared_capacity_id'];
            $redis_config[$key['shared_capacity_id']]['start_date'] = $start_date;
            $redis_config[$key['shared_capacity_id']]['end_date'] = $end_date;
            
            if($key['own_capacity_id'] > 0) {
                $redis_config[$key['own_capacity_id']]['shared_capacity_id'] = $key['own_capacity_id'];
                $redis_config[$key['own_capacity_id']]['start_date'] = $start_date;
                $redis_config[$key['own_capacity_id']]['end_date'] = $end_date;
            }
        }
        echo '<pre>';
        print_r($redis_config);
        echo json_encode($redis_config);
    }

    
    /*
     * @Name     : change_logs_status()
     * @Purpose  : To enable/disable logs on redis and firebase on all servers
     * @Created  : <vaishali.intersoft@gmail.com> Vaishali Raheja on 24 April 2020
    */

    function change_logs_status ($server = 'local', $enable = 0) {
        if (isset($this->firebase_redis_urls[$server])) {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY),
                'action: change_logs_status_with_script'
            );
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->firebase_redis_urls[$server]['firebase'] . "/update_details");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array("node" => '/graylogs', 'details' => array('LOGS_ENABLED' => (int) $enable))));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->firebase_redis_urls[$server]['cache'] . "/create_key");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array("key" => 'LOGS_ENABLED', 'value' => (int) $enable)));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
            curl_close($ch);
            echo "status updated to ". $enable;
        } else {
            echo "UnKnown Server";
        }
    }    
    
    /*
     * @Name       : generate_random_number()
     * @Purpose    : To generate random number for passes
     * @Created by : <supriya10.aipl@gmail.com> Supriya Saxena on 20 May, 2020
     */
    function generate_random_number() {
        $random = round(microtime(true) * 1000); // Return the current Unix timestamp with microseconds
        return substr($random, 2, strlen($random)).rand(1,9).rand(1,9).rand(1,9).rand(1,9).rand(1,9);
    }

      /*
    * @Name       : getPrices_v1()
    * @Purpose    : script to fetch price variation data
    * @Created by : <supriya10.aipl@gmail.com> Supriya Saxena on 2 march, 2021
    */
    function getPrices_v1() {
        $jsonStr = file_get_contents("php://input");
        $_REQUEST = json_decode($jsonStr, TRUE);
        $distributor_ids  = $_REQUEST['distributor_ids'];
        $ticket_id  = $_REQUEST['ticket_id'];
        $reseller_id  = $_REQUEST['reseller_id'];
        $this->load->library('Apilog_library');
        $thisLogs = new \Apilog_library();
        $final_res = array();
        $response = array();
        $thisLogs->CreateMposLog("pricning_variations.txt", "request ".$_REQUEST['ticket_id'], array(json_encode($_REQUEST)));
        $validate_response = $this->authorization->validate_request_params($_REQUEST, [                                                     
            'ticket_id'                      => 'numeric',                                                                                                                                              
            'distributor_ids'                => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                     
            'reseller_id'                    => 'numeric',   
            'start_date'                     => 'date'  ,
            'end_date'                       => 'date'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        
        ]);
        if(!empty($validate_response)) {
            echo json_encode($final_res);
        } else {
            if(!empty($ticket_id)) {       
                $modeventcontent_data = $this->common_model->find(
                    'modeventcontent', array(
                        'select' => 'mec_id, shared_capacity_id, own_capacity_id, startDate', 
                        'where' => "mec_id = '".$ticket_id."'"
                    ), 'array');          
                    
                if(!empty($distributor_ids)) {
                     /* BOC to fetch price variation based on reseller distributors */
                    $dist_reseller_query_result = $this->common_model->find( // fetch resellers of all distributors
                        'qr_codes',
                        array(
                        'select' => ' reseller_id, cod_id',
                        'where' => 'cod_id IN('.implode(',', $distributor_ids).') AND cashier_type = "1"'),
                        'array'
                    ); 
                    foreach($dist_reseller_query_result as $dist_res_arr) {
                        $all_resellers_ids[] = $dist_res_arr['reseller_id'];
                        $new_dis_r_arr[$dist_res_arr['cod_id']] = $dist_res_arr['reseller_id'];
                    } 
                    /* EOC to fetch price variation based on reseller distributors */
    
                    /* BOC to fetch price variation based on catalog and sub  of distributors */
                    $where = array();   
                    $where[] = 'ticket_id = "' .  $ticket_id . '"'; 
                    $where[] = 'partner_id IN("' . implode('","', array_unique($all_resellers_ids) ) . '")';
                    $where[] = 'partner_type = "1"';      
                    $where[] = 'deleted = "0" ';
                    $price_variations = $this->get_price_variations($thisLogs, $where);     
                    if(!empty($price_variations)) {  // if price variation exist
                        foreach($price_variations as $price_variation_data) {
                            $reseller_price_variation[$price_variation_data['partner_id']][$price_variation_data['ticket_id']][] = $price_variation_data;
                        }
                        foreach($new_dis_r_arr as $p_dist_id => $p_reseller_id) {
                            $ticketWise_reseller_variation[$p_dist_id] =  $reseller_price_variation[$p_reseller_id];
                        }
                        $res_response  = $this->price_variation_data($thisLogs, $ticketWise_reseller_variation,$modeventcontent_data[0]['startDate'], $_REQUEST);
                        if(!empty($res_response)) {
                            $response = $this->prepare_response($res_response, $response);
                        }
                    }
                    $catalog_query_result = $this->common_model->find( // fetch price variation on the basis of catalog
                        'qr_codes',
                        array(
                        'select' => 'catalog_id, sub_catalog_id, cod_id',
                        'where' => 'cod_id IN('.implode(',', $distributor_ids).')'),
                        'array'
                    ); 
                    $catalog_ids = array();
                    $sub_catalog_ids = array();
                    foreach($catalog_query_result as $catalog_data) {
                        array_push($catalog_ids,  $catalog_data['catalog_id'] );
                        array_push($sub_catalog_ids,  $catalog_data['sub_catalog_id'] );
                        $catalog_arr[$catalog_data['cod_id']]['catalog_id'] = $catalog_data['catalog_id'];
                        $sub_catalog_arr[$catalog_data['cod_id']]['sub_catalog_id'] = $catalog_data['sub_catalog_id'];               
                    }
    
                    /* BOC to fetch price variation based on catalog of distributors */
                    $where = array();  
                    $where[] = 'ticket_id = "' .  $ticket_id . '" '; 
                    $where[] = 'partner_id IN("' . implode('","', $catalog_ids ) . '") ';
                    $where[] = 'partner_type = "3" ';
                    $where[] = 'deleted = "0" ';
                    $price_variations = $this->get_price_variations($thisLogs, $where);   
                    if(!empty($price_variations)) {  // if price variation exist
                        foreach($price_variations as $price_variation_data) {
                            $catalog_price_variation[$price_variation_data['partner_id']][$price_variation_data['ticket_id']][] = $price_variation_data;
                        }                
                        foreach($catalog_arr as $t_dist_id => $f_catalog_id) {
                            if(array_key_exists($f_catalog_id['catalog_id'], $catalog_price_variation)) {
                                $ticketWise_catalog_variation[$t_dist_id] =  $catalog_price_variation[$f_catalog_id['catalog_id']];
                            }                    
                        }
                        if(!empty($ticketWise_catalog_variation)) {  //price variation based on catalog
                            $catalog_response  = $this->price_variation_data($thisLogs, $ticketWise_catalog_variation, $modeventcontent_data[0]['startDate'], $_REQUEST);
                            if(!empty($catalog_response)) {
                                $response = $this->prepare_response($catalog_response,  $response);
                            }
                        }
                    }
                    /* EOC to fetch price variation based on catalog of distributors */
    
                    /* BOC to fetch price variation based on sub catalog of distributors */
                    $where = array();   
                    $where[] = 'ticket_id = "' .  $ticket_id . '" ';
                    $where[] = 'partner_id IN("' . implode('","', $sub_catalog_ids ) . '") ';
                    $where[] = 'partner_type = "5" ';
                    $where[] = 'deleted = "0" ';
                    $price_variations = $this->get_price_variations($thisLogs, $where);   
                    if(!empty($price_variations)) {  // if price variation exist
                        foreach($price_variations as $price_variation_data) {
                            $sub_catalog_price_variation[$price_variation_data['partner_id']][$price_variation_data['ticket_id']][] = $price_variation_data;
                        }   
                                   
                        foreach($sub_catalog_arr as $t_dist_id => $f_catalog_id) {
                            if(array_key_exists($f_catalog_id['sub_catalog_id'], $sub_catalog_price_variation)) {
                                $ticketWise_sub_catalog_variation[$t_dist_id] =  $sub_catalog_price_variation[$f_catalog_id['sub_catalog_id']];
                            }                    
                        }
                        if(!empty($ticketWise_sub_catalog_variation)) {  //price variation based on sub catalog
                            $sub_catalog_response  = $this->price_variation_data($thisLogs, $ticketWise_sub_catalog_variation,$modeventcontent_data[0]['startDate'], $_REQUEST);
                            if(!empty($sub_catalog_response)) {
                                $response = $this->prepare_response($sub_catalog_response,  $response);
                            }
                        }
                    }
                    /* EOC to fetch price variation based on sub catalog of distributors */
    
                    /* BOC to fetch price variation based distributors */
                    $where = array();
                    $where[] = 'ticket_id = "' .  $ticket_id . '" ';
                    $where[] = 'partner_id IN("' . implode('","', $distributor_ids ) . '") ';  
                    $where[] = 'partner_type = "2" ';
                    $where[] = 'deleted = "0" ';
                    $price_variations = $this->get_price_variations($thisLogs, $where);     
                    if(!empty($price_variations)) {  // if price variation exist
                        foreach ($price_variations as $variation) {
                            $ticketWise_dist_variation[$variation['partner_id']][$variation['ticket_id']][] = $variation;
                        }    
                        $dist_response  = $this->price_variation_data($thisLogs, $ticketWise_dist_variation, $modeventcontent_data[0]['startDate'], $_REQUEST);
                        if(!empty($dist_response)) {
                            $response = $this->prepare_response($dist_response,  $response);
                        }
                    }               
                    foreach($response as $date_range_key => $date_range_value) {
                        $exp  = explode("_", $date_range_key);
                        $distributor_data[$exp[0]][$date_range_key] = $date_range_value;
                    }
                    foreach($distributor_data as $v_key=> $v_details) {
                        $distributor_res = $this->get_final_data($v_details);
                        $final_res[$v_key] = $distributor_res[$v_key];
                    }               
                    
                    /* EOC to fetch price variation based distributors */
                } else if(!empty($reseller_id)) {  // price variation in case of default reseller
                    $where = array();
                    $where[] = 'ticket_id = "' .  $ticket_id . '" ';
                    $where[] = 'supplier_admin_id = '.$reseller_id;
                    $where[] = ' partner_type = "0"';
                    $where[] = 'deleted = "0" ';
                    $price_variations = $this->get_price_variations($thisLogs, $where);     
                    if(!empty($price_variations)) { // if price variation exist
                        foreach ($price_variations as $variation) {
                            $ticketWise_ticket_variation[$variation['supplier_admin_id']][$variation['ticket_id']][] = $variation;
                        }
                        $ticket_response  = $this->price_variation_data($thisLogs, $ticketWise_ticket_variation, $modeventcontent_data[0]['startDate'], $_REQUEST);
                        $reseller_res = $this->get_final_data($ticket_response);
                        $final_res["Reseller-".$reseller_id] = $reseller_res[$reseller_id];
                    }
                }
                if(!empty($final_res)) {
                    if($modeventcontent_data[0]['shared_capacity_id'] > 0) {
                        header("shared_capacity_id: " . $modeventcontent_data[0]['shared_capacity_id']);
                    }
                    if($modeventcontent_data[0]['own_capacity_id'] > 0) {
                        header("own_capacity_id: " . $modeventcontent_data[0]['own_capacity_id']);
                    }
                }
                
                echo json_encode($final_res);
            } 
        }         
    }
     /*
     * @Name       : get_final_date()
     * @Purpose    : to createv response based on distributor or reseller
     */
    function get_final_data($dis_details) {
        $range_wise = array();
        foreach($dis_details as $date_range_key => $date_range_value) {
            $exp  = explode("_", $date_range_key);         
            foreach($date_range_value as $range => $range_details){
                $variation[$exp[2].'_'.$range][] = $range_details['variations'];
                $range_wise[$exp[0]][$exp[1]][$exp[2]][$range] =  array("time_range" => $range_details['time_range'], "variations" => array_values($variation[$exp[2].'_'.$range]));                       
            } 
            $final_response[$exp[0]][$exp[1]][$exp[2]] = array_values($range_wise[$exp[0]][$exp[1]][$exp[2]]);
        }
        return  $final_response;

    }

    /*
     * @Name       : get_price_variations()
     * @Purpose    : To fetch price variation data
     */
    public function get_price_variations($thisLogs, $where = array()) {
        $price_variations = array();
        if(!empty($where)) {
            $price_variations = $this->common_model->find(
                'dynamic_price_variations', array(
                    'select' => '*', 
                    'where' => implode(' and ', $where) . ' ',
                    'order_by' => 'variation_type ASC'
                ), 'array'); 
            $thisLogs->CreateMposLog("pricning_variations.txt", "fom DB ".$this->primarydb->db->last_query(), array(json_encode($price_variations)));
        }
        return $price_variations;
    }

    /*
    * @Name       : prepare_response()
    * @Purpose    : prepare resonse fo price variation
    * @Created by : <supriya10.aipl@gmail.com> Supriya Saxena on 2 march, 2021
    */
    public function prepare_response($price_variation_details = array(), $response_arr = array()) {
        if(!empty($response_arr)) { // if higher level variation exist then overwrite lower level w.r.t date and time range
            if(!empty($price_variation_details)) { // price variation data
                foreach($price_variation_details as $key => $details) {
                    $response_arr[$key] =  $details;
                    ksort($response_arr[$key]);
                }
            } 
        } else {
            $response_arr = $price_variation_details;
        }
        return $response_arr;
    }
    
    /*
     * @Name       : price_variation_data()
     * @Purpose    :  create array of price variation
     * @Created by : <supriya10.aipl@gmail.com> Supriya Saxena on 2 march, 2021
     */
    public function price_variation_data($thisLogs, $ticketWise_variation = array(), $ticket_start_date = '', $main_req = array()) {
        try {
            $range = '+3 months';
            $today = strtotime(date('Y-m-d'));
            if($ticket_start_date > $today ) {
                $today = $ticket_start_date;
            }
            if(isset($main_req['start_date']) && $main_req['start_date'] != "") {
                $today = strtotime($main_req['start_date']);
            }
            $new_date_range = date("Y-m-d" , $today);
            $max_date = strtotime(date('Y-m-d', strtotime($new_date_range. $range)));
            if(isset($main_req['end_date']) && $main_req['end_date'] != "") {
                $max_date = strtotime($main_req['end_date']);
            }
            if(!empty($ticketWise_variation)) {  // if  ticket wise price variation exist
                $thisLogs->CreateMposLog("pricning_variations.txt", "ticketWise variation ", array(json_encode($ticketWise_variation)));
                foreach($ticketWise_variation as $dist_id => $ticket_variationss) {     // loop through ticket variation array              
                    foreach($ticket_variationss as $ticket_variations) {
                        for ($date = $today; $date <= $max_date; $date += (86400)) {
                            foreach ($ticket_variations as $variation) {
                                $season_start_date = strtotime($variation['start_date']);
                                $season_end_date = strtotime($variation['end_date']);
                                
                                if ($variation['show_commission']  == 1 && $variation['show_discount'] == 1) {
                                    $discount_or_commission = 3;
                                } else if ($variation['show_commission']  == 1 && $variation['show_discount'] == 0) {
                                    $discount_or_commission = 2;
                                } else if ($variation['show_commission'] == 0 && $variation['show_discount'] == 1) {
                                    $discount_or_commission = 1;
                                } else if ($variation['show_commission'] == 0 && $variation['show_discount'] == 0) {
                                    $discount_or_commission = 0;
                                }
                                if ($date >= $season_start_date && $date <= $season_end_date ) {  
                                    if($variation['variation_type'] == '1') {  // based on day
                                        $default_range = array('from_time' => "00:00" , 'to_time' => "23:59");
                                        /** season start n end time should be the range for that particular days */
                                        if ($date == $season_start_date) {
                                            $default_range['from_time'] = substr($variation['from_time'], 0, 5);
                                        }
                                        if ($date == $season_end_date) {
                                            $default_range['to_time'] = substr($variation['to_time'], 0, 5);
                                        }                        
                                        $range_key = implode("_", $default_range);
                                        if(date("l", $date) == $variation['days']) { // if day match with variation day
                                            $resale_price =   $variation['resale_variation'] == '' ||  $variation['resale_variation'] == null ?  0 :  $variation['resale_variation'];
                                            $tps_data = $variation['sale_variation'].";".$variation['tps_id'].";". $resale_price .";". $variation['variation_type'] .";". $discount_or_commission.";1";     
                                            $data[$dist_id.'_'.$variation['ticket_id'].'_'.date("Y-m-d", $date).'_'.$variation['tps_id']][$range_key] = array("time_range" => $range_key , "variations" => $tps_data );                                                           
                                        }
                                    }
                                    if($variation['variation_type'] == '2') {  // based on day  and time                                     
                                        $default_range = array('from_time' => substr($variation['from_time'], 0, 5) , 'to_time' => substr($variation['to_time'], 0, 5));                      
                                        $range_key = implode("_", $default_range);
                                        if(date("l", $date) == $variation['days']) { // if day match with variation day
                                            $resale_price =   $variation['resale_variation'] == '' ||  $variation['resale_variation'] == null ?  0 :  $variation['resale_variation'];
                                            $tps_data = $variation['sale_variation'].";".$variation['tps_id'].";". $resale_price .";". $variation['variation_type'] .";". $discount_or_commission.";1";                             
                                            $data[$dist_id.'_'.$variation['ticket_id'].'_'.date("Y-m-d", $date).'_'.$variation['tps_id']][$range_key] = array("time_range" => $range_key , "variations" => $tps_data );                               
                                        }
                                    }                        
                                    if($variation['variation_type'] == '3' && (date("Y-m-d", $date) == $variation['travel_date'])) {  // based on date  and time range  if travel date match with date                 
                                        $default_range = array('from_time' => substr($variation['from_time'], 0, 5) , 'to_time' => substr($variation['to_time'], 0, 5));                      
                                        $range_key = implode("_", $default_range);
                                        $availability_pricing_variation_type  = 0;
                                        if($variation['date_variation_apply_for'] == "1") {
                                            $availability_pricing_variation_type  = 3;
                                        } 
                                        if($variation['date_variation_apply_for'] == "2") {
                                            $availability_pricing_variation_type  = 2;
                                        } 
                                        $resale_price =   $variation['resale_variation'] == '' ||  $variation['resale_variation'] == null ?  0 :  $variation['resale_variation'];
                                        $tps_data = $variation['sale_variation'].";".$variation['tps_id'].";". $resale_price .";". $variation['variation_type'] .";". $discount_or_commission.";". $availability_pricing_variation_type;  
                                        if($range_key == "00:00_23:59") {                              
                                            unset($data[$dist_id.'_'.$variation['ticket_id'].'_'.date("Y-m-d", $date).'_'.$variation['tps_id']]);
                                        }  
                                        $data[$dist_id.'_'.$variation['ticket_id'].'_'.date("Y-m-d", $date).'_'.$variation['tps_id']][$range_key] = array("time_range" => $range_key , "variations" => $tps_data );                                                     
                                    } 
                                }
                            } 
                        }
                    }
                }
                $thisLogs->CreateMposLog("pricning_variations.txt", "price_variations ", array(json_encode($data)));
                return $data;
            }
        } catch(\Exception $e) {
            echo $e->getMessage();
        }

    }

    /*
     * @Name       :  get_regional_settings()
     * @Purpose    :  sync regional setting on firebase
     * @Created by : <supriya10.aipl@gmail.com> Supriya Saxena on 3rd may, 2021
     */
    function get_regional_settings($reseller_id = '', $country_code = ''){
		$result = '';
		if (!empty($reseller_id)) {
            // check setting corresponding to admin
            $reseller_regional_details = $this->common_model->find('resellers', array('select' => 'date_format, time_format, currency_position, thousand_separator, decimal_separator, no_of_decimals, language, timezone', 'where' => 'reseller_id = "'.$reseller_id.'"'),  'array');			
        }
    
		if (empty($reseller_regional_details) || !empty($reseller_regional_details) && $reseller_regional_details[0]['date_format'] == '' ) {
			// check default settings
			if (empty($country_code)) {
				$country_code = 'NL';
            }
            $reseller_regional_details = $this->common_model->find('regional_settings', array('select' => 'date_format, time_format, currency_position, thousand_separator, decimal_separator, no_of_decimals, language, timezone', 'where' => 'country_iso_a2 = "'.$country_code.'"'),  'array');			
		}
		if (!empty($reseller_regional_details)) {
			// encode the result
			$result = base64_encode(json_encode($reseller_regional_details));
		}
		return $result;
    }

    #region start - Webhook for adyen payments   
        /*
        * @Name       : process_orders_with_adyen_amount
        * @Purpose    : to process adyen payment orders wrt  to webhook created
        */
        public function process_orders_with_adyen_amount () {
            echo '[accepted]';
            $logs = new \Apilog_library();
            $graylogs = array();
            if(!empty($_SERVER) && $_SERVER['PHP_AUTH_USER'] == ADYEN_AUTH_USER && $_SERVER['PHP_AUTH_PW'] == ADYEN_AUTH_PWD) {
                $jsonStr = file_get_contents("php://input"); /* read the HTTP body. */
                $adyen_params = json_decode($jsonStr, true);
                $logs->CreateMposLog("adyen_params.txt", "request string ", array(json_encode($adyen_params)));        
                if(!empty($adyen_params) && $adyen_params['notificationItems'] && is_array($adyen_params['notificationItems'])) {
                    foreach($adyen_params['notificationItems'] as $notificationItem) {
                        if(!empty($notificationItem['NotificationRequestItem']) && $notificationItem['NotificationRequestItem']['success'] == 'true' && $notificationItem['NotificationRequestItem']['eventCode'] == 'AUTHORISATION' && isset($notificationItem['NotificationRequestItem']['merchantReference'])  && isset($notificationItem['NotificationRequestItem']['pspReference'])) {
                            $merchant_ref = $notificationItem['NotificationRequestItem']['merchantReference'];
                            $psp_ref = $notificationItem['NotificationRequestItem']['pspReference'];
                            $vgn = trim(ltrim($merchant_ref, 'venueapp_'));
                            $logs->CreateMposLog("adyen_process.txt", "request data " . $vgn, array(json_encode(array("vgn" => $vgn, "merchant_ref" => $merchant_ref, "psp_ref" => $psp_ref))));        
                            $order_json = $this->common_model->find('mpos_requests', array('select' => 'json, visitor_group_no', 'where' => 'visitor_group_no = "'.$vgn.'" and status = "2"'), 'array');
                            $logs->CreateMposLog("adyen_process.txt", "order json from mpos_requests " . $vgn, array(json_encode($order_json)));        
                            if(!empty($order_json)) {
                                $order_req = json_decode(gzuncompress(base64_decode($order_json[0]['json'])), true);
                                $logs->CreateMposLog("adyen_process.txt", "order_req from mpos_requests -> " . $vgn, array(json_encode($order_req)));        
                                if($order_req['is_confirmed'] == "0") {
                                    $order_req['is_confirmed'] = "1";
                                    if(isset($order_req['adyen_details']) && $order_req['adyen_details']['merchantAccount'] == '' && $order_req['adyen_details']['merchantReference'] == '' && $order_req['adyen_details']['pspReference'] == '') {
                                        $order_req['adyen_details']['merchantAccount'] = $notificationItem['NotificationRequestItem']['merchantAccountCode'];
                                        $order_req['adyen_details']['merchantReference'] = $merchant_ref;
                                        $order_req['adyen_details']['pspReference'] = $psp_ref;
                                    }
                                    $logs->CreateMposLog("adyen_process_by_webhook.txt", "order_req processed from webHook -> " . $vgn, array(json_encode($order_req)));        
                                    $ch = curl_init();
                                    curl_setopt($ch, CURLOPT_URL, $this->config->config['base_url'] . "/process_adyen_order");
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_req));
                                    curl_setopt($ch, CURLOPT_USERPWD, ADYEN_AUTH_USER.':'.ADYEN_AUTH_PWD);
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                        'Content-Type: application/json',
                                        'visitor_group_no: ' . $vgn,
                                        'eventCode: ' . 'CAPTURE'
                                    ));
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                    curl_exec($ch);
                                    curl_close($ch);
                                    $data = $this->process_orders_res('Succesfully Processed with WebHook.', $vgn, $merchant_ref, $psp_ref);
                                } else {
                                    $data = $this->process_orders_res('Order already processed by App.', $vgn, $merchant_ref, $psp_ref);
                                }
                                $logs->CreateMposLog("adyen_process_by_webhook.txt", "order_req processed from webHook -> " . $vgn, array(json_encode($order_req)));        
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $this->config->config['base_url'] . "/process_adyen_order");
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_req));
                                curl_setopt($ch, CURLOPT_USERPWD, ADYEN_AUTH_USER.':'.ADYEN_AUTH_PWD);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                    'Content-Type: application/json',
                                    'visitor_group_no: ' . $vgn,
                                    'eventCode: ' . 'CAPTURE'
                                ));
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                $get_data = curl_exec($ch);
                                curl_close($ch);
                                $data = $this->process_orders_res('Succesfully Processed with WebHook.', $vgn, $merchant_ref, $psp_ref);
                            } else {
                                $data = $this->process_orders_res('Order already processed by App.', $vgn, $merchant_ref, $psp_ref);
                            }                        
                        } else {
                            $data[] = $notificationItem;
                        }
                    }
                }
            } else {
                $data = $adyen_params['notificationItems'];
            }
            $graylogs[] = array(
                'log_type' => 'mainLog',
                'data' => json_encode($data), 
                'api_name' => 'process_orders_with_adyen_amount'
            );
            $logs->CreateGrayLog($graylogs);
        }

        /*
        * @Name       : process_orders_res
        * @Purpose    : to prepare response to send on logs for adyen webhook
        */
        function process_orders_res ($msg, $vgn, $merchant_ref, $psp_ref) {
            return array(
                'message' => $msg,
                'visitor_group_no' => $vgn,
                'merchantReference' => $merchant_ref,
                'psp_ref' => $psp_ref,
            );
        }
    #region end - Webhook for adyen payments

    /*
    * @Name     : import_prev_users_batch()
    * @Purpose  : to import users that are not on firebase in Batch
    * @Created  : Vishal Katna <vishal24.aipl@gmail.com>
    */
    function import_prev_users_batch($limit , $offset , $tanantID = 0, $tenant = '') {
        exit;
        if(isset($tanantID) && ($tanantID !="1") && ($tanantID !="0")){
            echo "Please enter Valid Value to of sync inside tenant (0/1).";
            exit;
        }
        if(!isset($limit) || !isset($offset) || !is_numeric($limit) || !is_numeric($offset)){
            echo "Please enter Limit and Offset in query string";
            exit;
        }
        $platform_details = $this->common_model->find('platform_settings', array('select' => 'id, title'), 'list');
        $this->primarydb->db->select('cod_id, own_supplier_id, pos_type, is_venue_app_active, cashier_type, reseller_id, reseller_name')->from('qr_codes');
        $company_details = $this->primarydb->db->get()->result_array();
        foreach($company_details as $data) {
            $company[$data['cod_id']] = array(
                'own_supplier_id' => $data['own_supplier_id'],
                'cashier_type' => $data['cashier_type'],
                'pos_type' => $data['pos_type'],
                'reseller_id' => $data['reseller_id'],
                'reseller_name' => $data['reseller_name'],
                'is_venue_app_active' => $data['is_venue_app_active']
            );
        }
        $this->primarydb->db->select('id, feature_type, user_type, uname, fname, lname, password, firebase_user_id, id, cod_id, reseller_id, company, market_merchant_id,default_distributor, default_cashier');
        $this->primarydb->db->where('deleted', "0");
        if(isset($tenant) && !empty($tenant) && isset($tanantID) && ($tanantID == "1")){
            $this->primarydb->db->where('market_merchant_id', $tenant);    
        }
        $this->primarydb->db->limit($limit);
        $this->primarydb->db->offset($offset);
        $this->primarydb->db->from('users');
        $all_users = $this->primarydb->db->get()->result_array();
        if(empty($all_users)){
            echo "No user to Sync in Firebase, Please enter different Values of Limit/Offset/Market Merchant ID.";
            exit;
        }
        $users_to_sync = array();
        foreach($all_users as $user) {

            if($user['firebase_user_id'] != '') {
                $firebase_user_id = $user['firebase_user_id'];
            } else {
                $firebase_user_id = base64_encode($user['cod_id'].'_'.$user['uname']);
                $this->common_model->update('users', array('firebase_user_id' => $firebase_user_id), array('id' => $user['id']));
            }

            if($company[$user['cod_id']]['cashier_type'] == 1) {
                $custom_claims = array(
                    'cashierType' => 1,
                    'cashierId' => (string)$user['id'],
                    'distributorId' => (string)$user['cod_id'],
                    'distributorName' => (string)$user['company'],
                    'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                    'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                    'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                    'posType' => ($company[$user['cod_id']]['pos_type'] == 2 && $company[$user['cod_id']]['is_venue_app_active'] == 1) ? 6 : $company[$user['cod_id']]['pos_type']
                );
		        $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'hotel_id' => $user['cod_id'], 'pos_type' => $custom_claims['posType']));
            } else if($company[$user['cod_id']]['cashier_type'] == 2) {
                $custom_claims = array(
                    'cashierType' => 2,
                    'cashierId' => (string)$user['id'],
                    'supplierId' => (string)$user['cod_id'],
                    'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                    'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                    'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                    'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                );
                $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'museum_id' => $user['cod_id'], 'pos_type' => $custom_claims['posType']));
            } else {
              
                if($user['user_type'] == 'superAdmin') { //superAdmin user
                    $custom_claims = array(
                        'cashierType' => 4,
                        'cashierId' => (string)$user['id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : ''
                    );                   
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id']));
                } else if($user['feature_type'] == '3') {
                    $platform_settings = $this->common_model->find('platform_settings', array('select' => 'title', 'where' => 'id = "'.$user['cod_id'].'"'));
                    $custom_claims = array(
                        'cashierType' => 5,
                        'cashierId' => (string)$user['id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['cod_id']]) ? (string) $platform_details[$user['cod_id']] : ''
                    );
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'platformId' => $user['cod_id'], 'platformName' => $platform_settings[0]['title']));
                } else if($user['cod_id'] == 0 || $user['cod_id'] == NULL || $user['cod_id'] == '') {
                    $distributor = $this->common_model->find('qr_codes', array('select' => 'cod_id', 'where' => 'reseller_id = "'.$user['reseller_id'].'" and is_venue_app_active = 1'));
                    $reseller_details = $this->common_model->find('resellers', array('select' => 'reseller_id, country_code', 'where' => 'reseller_id = "'.$user['reseller_id'].'"'));
                    $reseller_regional_setting = $this->get_regional_settings($user['reseller_id'], $reseller_details[0]['country_code']);
                    $defaultDistributorData = "";
                    if(isset($_GET['defaultCashierId'])) {
                        $defaultCashierData = $this->common_model->find('users', array('select' => 'fname, lname, company', 'where' => 'id = "'. $_GET['defaultCashierId'] .'" and deleted = "0"'));
                    }else{
                        if(isset($user['default_cashier']) && $user['default_cashier']!= null){
                            $defaultCashierData = $this->common_model->find('users', array('select' => 'fname, lname, company', 'where' => 'id = "'. $user['default_cashier'] .'" and deleted = "0"'));
                        }
                    }
                    $custom_claims = array(
                        'cashierType' => 3,
                        'cashierId' => (string)$user['id'],
                        'resellerId' => (string)$user['reseller_id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                        'defaultDistributorId' => isset($_GET['defaultDistributorId']) ? $_GET['defaultDistributorId'] : (string) $user['default_distributor'],
                        'defaultCashierId' => isset($_GET['defaultCashierId']) ? $_GET['defaultCashierId'] : ($user['default_cashier'] ? $user['default_cashier'] : (string) 0), //update cashier  id received from request else 0
                        'defaultCashierName' => ( !empty($defaultCashierData)) ? $defaultCashierData[0]['fname']." ".$defaultCashierData[0]['lname'] : "",
                        'defaultDistributorName' => (!empty($defaultCashierData)) ? $defaultCashierData[0]['company'] : "",
                        'regional_settings' => $reseller_regional_setting
                    );
                    $headers = $this->common_model->all_headers(array('action' => 'import_prev_users_script', 'user_id' => $user['id'], 'reseller_id' => $user['reseller_id'], 'pos_type' => $custom_claims['posType']));
                } else {
                    $custom_claims = array(
                        'cashierType' => 2,
                        'cashierId' => (string)$user['id'],
                        'supplierId' => (string)$user['cod_id'],
                        'platformId' => isset($user['market_merchant_id']) ? (string) $user['market_merchant_id']  :'0',
                        'platformName' => isset($platform_details[$user['market_merchant_id']]) ? (string) $platform_details[$user['market_merchant_id']] : '',
                        'adminId' => isset($company[$user['cod_id']]['reseller_id']) ? (string)  $company[$user['cod_id']]['reseller_id'] : '0',
                        'adminName' => isset($company[$user['cod_id']]['reseller_name']) ? (string)  $company[$user['cod_id']]['reseller_name'] : '',
                    );
                }
            }
            if($tanantID == "1"){
                if($user['market_merchant_id'] != ''){
                    $platform_details_tenant = $this->common_model->find('platform_settings', array('select' => 'id, tenant_id'), 'list');
                    $tenant_id = $platform_details_tenant[$user['market_merchant_id']] ;
                    $user_details = array(
                        'username' => $user['fname'].' '.$user['lname'],
                        'email' => $user['uname'],
                        'password' => $user['password'],
                        'uid' => $firebase_user_id,
                        'custom_claims' => $custom_claims,
                        'tenantId' => $tenant_id
                    ); 
                }
            }else{
                $user_details = array(
                    'username' => $user['fname'].' '.$user['lname'],
                    'email' => $user['uname'],
                    'password' => $user['password'],
                    'uid' => $firebase_user_id,
                    'custom_claims' => $custom_claims
                );
            }
            array_push($users_to_sync, $user_details);
        }
        if($tanantID == 1 && !empty($tenant)){
            $this->primarydb->db->select('id, title, tenant_id');
            $this->primarydb->db->where('id', $tenant);    
            $this->primarydb->db->from('platform_settings');
            $tenant_details = $this->primarydb->db->get()->result_array();
            $tenant_id_to_sync = $tenant_details[0]['tenant_id'];
            $users_to_sync = [
                'tenantId' => $tenant_id_to_sync,
                'users' => $users_to_sync
            ];
            $getdata = $this->curl->request('FIREBASE', '/import_users_batch_v1', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => $users_to_sync
            ));
        }else{ 
            if(isset($users_to_sync[0]['tenantId'])){
                echo "Market Merchant ID is not defined";
                exit;
            }
            $getdata = $this->curl->request('FIREBASE', '/import_users_batch', array(
                'type' => 'POST',
                'additional_headers' => $headers,
                'body' => $users_to_sync
            ));
        }
        
        echo '<pre>';
        print_r($users_to_sync);
        echo '</pre>';
        echo json_encode($users_to_sync);
        
        print_r($getdata);
        print_r(json_decode($getdata, true));
    }


    function update_redis($server = 'local') {
        $records = $this->common_model->find(
            'redis_keys', array(
                'select' => 'id', 
                'where' => 'status = 0',
                'limit' => 10
        ), 'array');
        $keys = array_column($records, 'id');
        if(!empty($keys)) {
            $jsonDetails = json_encode(array('shared_capacity_id' => $keys, 'from_date' => '2020-01-01', 'to_date' => '2022-10-31'));
            $headers = array(
                'Content-Type: application/json',
                'Authorization: ' . (SECRET_MANAGER['REDIS_AUTH_KEY'] ?? REDIS_AUTH_KEY),
                'action: deleting old redis keys'
            );
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->firebase_redis_urls['live']['cache'] . "/deleteticket");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDetails);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $getdata = curl_exec($ch);
            echo json_encode($getdata);
            curl_close($ch); 
            $this->db->query('update redis_keys set status = 1 where id IN (' . implode(',', $keys) . ')', 0);
        }
    }

}

?>
