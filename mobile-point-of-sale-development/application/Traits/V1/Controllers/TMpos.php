<?php

namespace Prio\Traits\V1\Controllers;

use \Prio\helpers\V1\local_queue_helper;
use \Prio\helpers\V1\mpos_auth_helper;

trait TMpos {
    public function __construct() {
        parent::__construct();
        $this->load->Model('V1/firebase_model');
		$this->load->Model('V1/mpos_model');

        $this->mpos_channels = array(10, 11); // API Chanell types
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['API'] = $this->router->method;
        $internal_logs['API'] = $this->router->method;
        $MPOS_LOGS['headers'] = $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
        $jsonStr = file_get_contents("php://input"); //read the HTTP body.
        $_REQUEST = json_decode($jsonStr, TRUE);
        if ($this->uri->segment(1) != 'process_adyen_order_v1' && $this->uri->segment(1) != 'process_adyen_order' && $this->uri->segment(2) != 'signIn' && $this->uri->segment(2) != 'send_mail' && $this->uri->segment(2) != 'signOut' && (!isset($headers['jwt_token']))) {
            $this->validate_request_token($headers);
        }
    }
    
    /* #region Order Process  Module  : This module covers order process api's  */

    /**
     * @Name : signIn()
     * @Purpose : To login the user from Venue app linked with firebase.
     * @Call from : When user click on SignIn button from APP then this link is hit from APP.
     * @Functionality : When user click on SignIn button from APP then this link is hit from APP and we return JWT token to authenticate the app with firebase.
     * @Created : Karan <karan.intersoft@gmail.com> on 10 May 2017
     * @Modified : komal <komalgarg.intersoft@gmail.com> on 15 June 2017
     */
    function signIn($mode = 'live') {
        global $MPOS_LOGS;
        $jsonStr = file_get_contents("php://input"); //read the HTTP body.
        $_REQUEST = json_decode($jsonStr, TRUE);
        header('Content-Type: application/json');
        $response = array();
        $req = $_REQUEST;
        unset($req['password']);
        $MPOS_LOGS['request'] = $req;
        $MPOS_LOGS['request_time'] = date('H:i:s');
        $MPOS_LOGS['operation_id'] = 'AUTH';
        $MPOS_LOGS['external_reference'] = 'signIn';
        try {
            if (!empty($_REQUEST) && $_REQUEST['username'] != '' && $_REQUEST['password'] != '') {
                $username = $_REQUEST['username'];
                $password = $_REQUEST['password'];
                $machine_id = $_REQUEST['machine_id'];
                $fcm_token = $_REQUEST['fcm_token'];
                $logged_in_date = $_REQUEST['logged_in_date'];
                $attempt_failure = (isset($_REQUEST['failure']) && !empty($_REQUEST['failure']) ? $_REQUEST['failure'] : '');
                $firebase_web_api_url = 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key='.FIREBASE_WEB_API_KEY;

                $spcl_ref = (isset($_REQUEST['failure']) && !empty($_REQUEST['failure'])) ? 'mpos_login' : 'sspos_login';
                //users' detail
                $user_details = $this->common_model->find('users', array('select' => 'id, cod_id, uname, password, fcm_tokens, user_pos_locations, firebase_user_id, cashier_code, fname, lname, allow_cancel_orders, user_type, user_role, machine_id, is_logged_in, add_city_card', 'where' => 'uname = "' . $username . '" and deleted = "0"'));
                $logs['users_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['user_details_' . date('H:i:s')] = $user_details;
                $dbpassword = $user_details[0]['password'];
                $passwrd = $this->hash->hash_password(md5($password));
                $logs['req_password__dbpassword'] = array($passwrd, $dbpassword);
                if ($passwrd == $dbpassword) {
                    //If login user exist, fetch its details from qr_codes i.e. user is from distributor or supplier
                    $cod_id = $user_details[0]['cod_id'];
                    $distributor = $this->common_model->find('qr_codes', array('select' => 'cod_id, own_supplier_id, token, adyen_merchant_account, adyen_api_key, cashier_type, is_venue_app_active, multi_user_login,adyen_sdk_username,adyen_sdk_password, details_on_scan, pos_type', 'where' => 'cod_id = "' . $cod_id . '"'));
                    /* Update user password in user node on firebase */
                    if ($attempt_failure) {
                        //pos_type =  2 is for cpos, is_venue_app_active = 1 is for mpos(users activated mpos app) so if mpos and cpos both are activated than pos_type is set to 6  
                        if ($distributor[0]['pos_type'] == \PosType::CPOS && $distributor[0]['is_venue_app_active'] == \MposConst::Enabled) {
                            $distributor[0]['pos_type'] = \PosType::MPOS;
                        }

                        $updFirebaseId["update"] = 0;

                        $name = implode(" ", array_filter(array(strip_tags(trim($user_details[0]['fname'])), strip_tags(trim($user_details[0]['lname'])))));
                        $firebase_uid = '';
                        if (isset($user_details[0]['firebase_user_id']) && $user_details[0]['firebase_user_id'] != '') {
                            $firebase_uid = $user_details[0]['firebase_user_id'];
                        } else {
                            $firebase_uid = base64_encode($cod_id . '_' . $user_details[0]['uname']);
                            $updFirebaseId['update'] = 1;
                        }

                        $updFirebaseId["user_id"] = $user_details[0]['id'];
                        $updFirebaseId["username"] = $name;
                        $updFirebaseId["email"] = $username;
                        $updFirebaseId["password"] = $_REQUEST['password'];
                        $updFirebaseId["uid"] = base64_encode($cod_id . '_' . $user_details[0]['uname']);
                        $updFirebaseId["firebase_web_api_url"] = $firebase_web_api_url;

                        if ($distributor[0]['cashier_type'] == \CashierType::Supplier) {

                            $updFirebaseId["custom_claims"] = array('cashierType' => (int) $distributor[0]['cashier_type'],
                                'cashierId' => (string) $user_details[0]['id'],
                                'supplierId' => $cod_id);

                            $this->update_user_firebase($firebase_uid, $name, $_REQUEST['password'], $user_details[0]['user_role'], 2, $logs, $updFirebaseId);
                        } else if ($distributor[0]['cashier_type'] == \CashierType::Distributor && $distributor[0]['is_venue_app_active'] == \MposConst::Enabled) {

                            $updFirebaseId["custom_claims"] = array('cashierType' => (int) $distributor[0]['cashier_type'],
                                'cashierId' => (string) $user_details[0]['id'],
                                'distributorId' => $cod_id,
                                'ownSupplierId' => $distributor[0]['own_supplier_id'],
                                'posType' => $distributor[0]['pos_type']);

                            $this->update_user_firebase($firebase_uid, $name, $_REQUEST['password'], $user_details[0]['user_role'], 1, $logs, $updFirebaseId);
                        }
                    }
                    /* Update user password in user node on firebase */

                    $logs['qr_codes_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                    $internal_log['qr_codes_result_' . date('H:i:s')] = $distributor;
                    if ($user_details[0]['machine_id'] == '' || ($user_details[0]['machine_id'] == $machine_id && $user_details[0]['is_logged_in'] == '1') || $user_details[0]['is_logged_in'] == '0' || $distributor[0]['multi_user_login'] == "1") {
                        if (!empty($distributor)) {
                            // supplier user or distributor user with venue app active
                            if (($distributor[0]['cashier_type'] == \CashierType::Supplier) || ($distributor[0]['cashier_type'] == \CashierType::Distributor && $distributor[0]['is_venue_app_active'] == \MposConst::Enabled)) {
                                $firebase_uid = '';
                                if (isset($user_details[0]['firebase_user_id']) && $user_details[0]['firebase_user_id'] != '') {
                                    $firebase_uid = $user_details[0]['firebase_user_id'];
                                } else {
                                    if (isset($_REQUEST['machine_id']) && $_REQUEST['machine_id'] != '') {
                                        $firebase_uid = base64_encode($cod_id . '_' . $user_details[0]['uname'] . '_' . $_REQUEST['machine_id']);
                                    } else {
                                        $firebase_uid = base64_encode($cod_id . '_' . $user_details[0]['uname']);
                                    }
                                }

                                //update users table
                                if ($user_details[0]['fcm_tokens'] != '' && $user_details[0]['fcm_tokens'] != NULL) {
                                    $this->firebase_model->query('update users set machine_id = "' . $_REQUEST['machine_id'] . '", is_logged_in = "1", firebase_user_id = "' . $firebase_uid . '",  fcm_tokens = concat(fcm_tokens, ",' . $fcm_token . '") where id = "' . $user_details[0]['id'] . '"');
                                } else if (isset($fcm_token)) {
                                    $this->firebase_model->query('update users set machine_id = "' . $_REQUEST['machine_id'] . '", is_logged_in = "1", firebase_user_id = "' . $firebase_uid . '", fcm_tokens = "' . $fcm_token . '" where id = "' . $user_details[0]['id'] . '"');
                                } else {
                                    $this->firebase_model->query('update users set machine_id = "' . $_REQUEST['machine_id'] . '", is_logged_in = "1", firebase_user_id = "' . $firebase_uid . '" where id = "' . $user_details[0]['id'] . '"');
                                }
                            }
                            if ($distributor[0]['cashier_type'] == \CashierType::Supplier) { /* If login is done from supplier user */

                                //call firebase api to get token 
                                $user_details = array();
                                $user_details['email'] = $username;
                                $user_details['password'] = $password;
                                $user_details['returnSecureToken'] = true;
                        
                                $getdata = $this->curl->request('WEB_API', $firebase_web_api_url , array(
                                    'type' => 'POST',
                                    'headers' => $this->firebase_model->all_headers(array('action' => 'create_token')),
                                    'body' => $user_details
                                ));
                                $fdata = json_decode($getdata, true);
                                if ($fdata['status'] != 'SUCCESS') {
                                    $logs['supplier_get_token_res_' . date('H:i:s')] = $fdata;
                                }
                                $res = $this->get_user_from_user_vs_machineids($machine_id);
                                $user_token = $res->token;
                                $resuname = strtolower($res->uname);
                                $respassword = $res->password;
                                $new_token = bin2hex(openssl_random_pseudo_bytes(8));
                                
                                $data['user_id'] = $user_details[0]['id'];
                                $data['token'] = empty($user_token) ? $new_token : $user_token;
                                $where['machine_id'] = $machine_id;
                                
                                //login from registered machine_id
                                if ($res  && ($res->machine_id) && ($res->machine_id == $machine_id) && ($resuname == $username) && ($respassword == $user_details[0]['password'])) {
                                    // Display Message
                                    $where['user_id'] = $user_details[0]['id'];
                                    $this->firebase_model->update('user_vs_machineids', $data, $where);
                                } else if ($res  && ($resuname != $username)) {
                                    /*
                                     * If user login from different machine and this machine id is already registered with the name of another user then update
                                     * user id of logged in user corresponding to the existing machine
                                     */
                                    $this->firebase_model->update('user_vs_machineids', $data, $where);
                                } else if (!$res) {
                                    // check if this userid and machine id already exists
                                    $ifExists = $this->common_model->getSingleFieldValueFromTable('id', 'user_vs_machineids', array('user_id' => $user_details[0]['id'], 'machine_id' => $machine_id, 'token' => $user_token));
                                    // if machine id is not registered 
                                    if (!$ifExists) {
                                        $data['machine_id'] = $machine_id;
                                        $this->db->insert('user_vs_machineids', $data);
                                    }
                                } else {
                                    $this->firebase_model->update('user_vs_machineids', $data, $where);
                                }

                                $pos_points_list = array();
                                if (!empty($user_details[0]['user_pos_locations'])) {
                                    $pos_points_list = explode(',', $user_details[0]['user_pos_locations']);
                                }
                                if (!empty($pos_points_list)) {
                                    //replace null with blank
                                    array_walk_recursive($pos_points_list, function (&$item) {
                                        $item = is_numeric($item) ? (int) $item : $item;
                                        $item = null === $item ? 0 : $item;
                                    });
                                }

                                $response['status'] = (int) 1;
                                $response['JWT_token'] = $fdata['Token'];
                                $response['cashier_type'] = (int) $distributor[0]['cashier_type'];
                                $response['supplier_id'] = (int) $distributor[0]['cod_id'];
                                $response['auth_token'] = $distributor[0]['token'];
                                $response['message'] = "Enjoy your day.";
                                $response['data']['details_on_scan'] = (int) $distributor[0]['details_on_scan'];
                                $response['data']['user_id'] = (int) $user_details[0]['id'];
                                $response['data']['token'] = empty($user_token) ? $new_token : $user_token;
                                $response['data']['fname'] = $user_details[0]['fname'];
                                $response['data']['lname'] = $user_details[0]['lname'];
                                $response['data']['email'] = $user_details[0]['uname'];
                                $response['data']['add_to_pass'] = (int) $user_details[0]['add_city_card'];
                                $response['data']['hotel_id'] = (int) $distributor[0]['cod_id'];
                            } else if ($distributor[0]['cashier_type'] == \CashierType::Distributor && $distributor[0]['is_venue_app_active'] == \MposConst::Enabled) { /* If login is done from distributor user */
                                //call firebase api to get token 
                                $user_details = array();
                                $user_details['email'] = $username;
                                $user_details['password'] = $password;
                                $user_details['returnSecureToken'] = true;
                        
                                $getdata = $this->curl->request('WEB_API', $firebase_web_api_url , array(
                                    'type' => 'POST',
                                    'headers' => $this->firebase_model->all_headers(array('action' => 'create_token')),
                                    'body' => $user_details
                                ));
                                $data = json_decode($getdata, true);
                                if ($data['status'] != 'SUCCESS') {
                                    $logs['distributor_get_token_res_' . date('H:i:s')] = $data;
                                }

                                if (isset($data['Token']) && $data['Token'] != '') {
                                    $shifts = $this->common_model->find('cashier_register', array('select' => 'shift_id, status, modified_at, pos_point_id, opening_cash_balance, created_at', 'where' => 'DATE(created_at) like "%' . $logged_in_date . '%" and hotel_id = ' . $distributor[0]['cod_id'] . ' and cashier_id = ' . $user_details[0]['id'] . ' and status != 0 ', 'order_by' => 'modified_at DESC'));
                                    $logs['cashier_register_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                                    $internal_log['cashier_register_response_' . date('H:i:s')] = $shifts;
                                    foreach ($shifts as $shift) {
                                        if ($shift['status'] == 2 && $shift['shift_id'] != NULL) {
                                            $closed_shift[] = $shift['shift_id'];
                                        } else {
                                            $selected_shift = array(
                                                'pos_point_id' => (int) $shift['pos_point_id'],
                                                'shift_id' => (int) $shift['shift_id'],
                                                'start_amount' => (int) $shift['opening_cash_balance'],
                                                'start_time' => $shift['created_at'],
                                            );
                                        }
                                    }
                                    $response['status'] = (int) 1;
                                    $response['cashier_type'] = (int) $distributor[0]['cashier_type'];
                                    $response['distributor_id'] = (int) $cod_id;
                                    $response['cashier_id'] = (int) $user_details[0]['id'];
                                    $response['cashier_code'] = $user_details[0]['cashier_code'];
                                    $response['cashier_name'] = $user_details[0]['fname'] . ' ' . $user_details[0]['lname'];
                                    $response['API_token'] = $distributor[0]['token'];
                                    $response['JWT_token'] = $data['Token'];
                                    $response['own_supplier_id'] = isset($distributor[0]['own_supplier_id']) ? $distributor[0]['own_supplier_id'] : "0";
                                    $response['adyen_sdk_username'] = isset($distributor[0]['adyen_sdk_username']) ? $distributor[0]['adyen_sdk_username'] : "";
                                    $response['adyen_sdk_password'] = isset($distributor[0]['adyen_sdk_password']) ? $distributor[0]['adyen_sdk_password'] : "";
                                    $response['adyen_api_key'] = isset($distributor[0]['adyen_api_key']) ? $distributor[0]['adyen_api_key'] : "";
                                    $response['is_cancel_allowed'] = (isset($user_details[0]['allow_cancel_orders']) && $user_details[0]['allow_cancel_orders'] == 1) ? (int) 1 : (int) 0;
                                    $response['adyen_merchant_account'] = (isset($distributor[0]['adyen_merchant_account']) && $distributor[0]['adyen_merchant_account'] != '') ? $distributor[0]['adyen_merchant_account'] : "";
                                    $response['adyen_public_key'] = ADYEN_PUBLIC_KEY;
                                    $response['adyen_secret_key'] = ADYEN_SECRET_KEY;
                                    $response['closed_shifts'] = isset($closed_shift) ? array_values(array_unique($closed_shift)) : array();
                                    $response['selected_shift'] = isset($selected_shift) ? $selected_shift : array('pos_point_id' => '', 'shift_id' => '', 'start_amount' => '');
                                    if (isset($_REQUEST['machine_id']) && $_REQUEST['machine_id'] != '') {
                                        $update = array();
                                        $update['user_id'] = $user_details[0]['id'];
                                        $update['machine_id'] = $_REQUEST['machine_id'];
                                        $update['loginAt'] = date('Y-m-d H:i:s');
                                        $this->common_model->save('user_vs_machineids', $update); //insert new entry
                                    }
                                } else {
                                    $response = $this->exception_handler->error_500();
                                }
                            } else {
                                header('HTTP/1.0 401 Unauthorized');
                                $response = $this->exception_handler->show_error(0, 'AUTHORIZATION_FAILURE', "POS app is inactive for this distributor.");
                            }
                        } else {
                            $response = $this->exception_handler->error_401();
                        }
                    } else {
                        $response = $this->exception_handler->error_402();
                    }
                } else {
                    $response = $this->exception_handler->error_401();
                }
            } else {
                $response = $this->exception_handler->error_400();
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['signin'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['username'], $spcl_ref);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @Name : update_user_firebase()
     * @Purpose : To update hash key encrypted password on firebase database.
     * @Call from : Call from signup function in case of second attempt of login when failed to login from firebase DB.
     * @Functionality : When user attempts the login from API under condition of unsuccessfull login from firebase.
     * @Created : Jatinder <jatinder.aipl@gmail.com> on 21 June, 2019
     */
    function update_user_firebase($firebase_user_id, $name, $password, $user_role, $type, $logs = array(), $updFirebaseId = array()) {

        global $MPOS_LOGS;
        $MPOS_LOGS['signin'] = $logs;
        $response = array();
        try {
            $firebase_web_api_url = $updFirebaseId['firebase_web_api_url'];
            unset($updFirebaseId['firebase_web_api_url']);
            if (empty($firebase_user_id) || empty($name) || empty($password) || empty($user_role) || empty($type) || empty($updFirebaseId['user_id'])) {
                $response['Error_response'] = $this->exception_handler->error_400();
            }

            if (!empty($updFirebaseId) && !empty($updFirebaseId['user_id']) && $updFirebaseId['update'] == 1) {
                $this->firebase_model->query("update users set firebase_user_id='" . $firebase_user_id . "' where id='" . $updFirebaseId['user_id'] . "'");
            }

            //call firebase api to update user password 
            $MPOS_LOGS['api_update_request_data_' . date('H:i:s')] = json_encode(array('password' => $password, 'username' => $name, 'uid' => $firebase_user_id));
            $MPOS_LOGS['DB'][] = 'FIREBASE';
            $upd = $this->curl->request('FIREBASE', '/update_user', array(
                'type' => 'POST',
                'additional_headers' => $this->firebase_model->all_headers(array('action' => 'update_existing_user', 'user_id' => $updFirebaseId['user_id'])),
                'body' => array(
                        'password' => $password, 
                        'username' => $name, 
                        'uid' => $firebase_user_id
                    )
            ));

            $MPOS_LOGS['api_update_response_data_' . date('H:i:s')] = $upd;
            $updResponse = json_decode($upd, true);

            /* Create user on firebase if not exists but user exists in DB and update its firebase_user_id in DB */
            if ($updResponse['response'] == 0 && $updResponse['message']['code'] == 'auth/user-not-found') {

                $fbUid = $updFirebaseId['user_id'];
                $firebase_user_id = $updFirebaseId['uid'];
                unset($updFirebaseId['update'], $updFirebaseId['user_id']);

                $MPOS_LOGS['api_create_user_request_data_' . date('H:i:s')] = $updFirebaseId;
                $this->curl->requestASYNC('FIREBASE', '/create_user', array(
                    'type' => 'POST',
                    'additional_headers' => $this->firebase_model->all_headers(array('action' => 'create_a_new_user', 'user_id' => $fbUid)),
                    'body' => $updFirebaseId
                ));

                if (!empty($updFirebaseId) && !empty($fbUid)) {
                    $this->firebase_model->query("update users set firebase_user_id='" . $firebase_user_id . "' where id='" . $fbUid . "'");
                    $MPOS_LOGS['user_firebase_id_update_query_' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                }
            }
            /* Create user on firebase if not exists but user exists in DB and update its firebase_user_id in DB */

            //call firebase api to get token 
            $user_details = array();
            $user_details['email'] = $updFirebaseId['email'];
            $user_details['password'] = $updFirebaseId['password'];
            $user_details['returnSecureToken'] = true;
      
            $getdata = $this->curl->request('WEB_API', $firebase_web_api_url , array(
                'type' => 'POST',
                'headers' => $this->firebase_model->all_headers(array('action' => 'create_token')),
                'body' => $user_details
            ));

            $data = json_decode($getdata, true);
            $MPOS_LOGS['response_token_' . date('H:i:s')] = $data;
          
            if (!isset($data['idToken']) && empty($data['idToken'])) {
                $response['Error_response'] = $this->exception_handler->error_500();
            }
            $response['status'] = (int) 1;
            $response['JWT_token'] = $data['idToken'];
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }

        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_REQUEST['username'], 'mpos_login');
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * @Name : signOut()
     * @Purpose : To logout the user from Venue app update amounts in cashier_register table.
     * @Call from : When user click on Logout button from APP then this link is hit from APP.
     * @Functionality : When user click on SignOut button from APP then this link is hit from APP  and update entry in cashier_register table.
     * @Created : Karan <komalgarg.intersoft@gmail.com> on 03 Mar 2018
     */
    function signOut() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'AUTH';
        $MPOS_LOGS['external_reference'] = 'signOut';
        try {
            $request_array = $this->validate_api('signOut');
            if(empty($request_array['errors'])) {
                $request = $request_array['request'];
                $validate_token = $request_array['validate_token'];
                $shift_id = $request['shift_id'];
                $cashier_id = $request['user_id'];
                $response = array();
                $user_details = $this->common_model->find('users', array('select' => 'id, uname, fname, lname', 'where' => 'id = "' . $cashier_id . '" and deleted = "0"'));
                //if pos point id exist in request, then update total amounts and refunded amounts in cashier register table
                if (isset($request['is_endshift']) && $request['is_endshift'] != 0 && isset($request['pos_point_id']) && $request['pos_point_id'] != '') {
                    if ($request['enable_inApp_pos']) {//CPOS features are enabled   
                        $update_status['cash_balance_open_for_next_cashier'] = $request['cash_balance_open_for_next_cashier'];
                        $update_status['safety_deposit_total_amount'] = isset($request['safety_deposit_total_amount']) ? $request['safety_deposit_total_amount'] : 0;
                        if ($request['enable_cash_count'] == 1) {
                            $update_status['other_deposit'] = isset($request['other_deposit']) ? $request['other_deposit'] : 0;
                            $update_status['next_shift_deposit'] = isset($request['next_shift_deposit']) ? $request['next_shift_deposit'] : 0;
                            $update_status['cash_count_total'] = isset($request['cash_count_total']) ? $request['cash_count_total'] : 0;
                            $update_status['cash_count'] = isset($request['cash_count']) ? serialize($request['cash_count']) : '';
                            $update_status['is_logged_out'] = 1;
                        } else {
                            $update_status['is_logged_out'] = 2;
                            $update_status['other_deposit'] = $update_status['next_shift_deposit'] = $update_status['cash_count_total'] = $update_status['cash_count'] = 0;
                            if ($request['cash_balance_open_for_next_cashier'] == 1) { //if user has not given cash count
                                $manual_payment = 0;
                                if (!empty($request['manual_payment'])) {
                                    $cash_out = $request['manual_payment']['cash_out'];
                                    $cash_in = $request['manual_payment']['cash_in'] + $request['manual_payment']['card'];
                                    $manual_payment = $cash_in - $cash_out;
                                }
                                $cashier_open_balace = $this->common_model->find('cashier_register', array('select' => 'closing_cash_balance', 'where' => 'created_at like "%' . date('Y-m-d') . '%" and cashier_id = ' . $cashier_id . ' and pos_point_id =' . $request['pos_point_id']));
                                $total_closing = $cashier_open_balace[0]['closing_cash_balance'] + $manual_payment;
                                $logs['total_closing_' . $cashier_open_balace[0]['closing_cash_balance'] . '_' . $manual_payment] = $total_closing;
                                $update_status['next_shift_deposit'] = $request['next_shift_deposit'] + $total_closing;
                            }
                        }
                    } else {
                        $update_status['is_logged_out'] = 1;
                    }
                    $update_status['note'] = isset($request['note']) && !empty($request['note']) ? $request['note'] : NULL; // if extra note is set on time of end shift
                    $update_status['closing_time'] = gmdate("H:i:s");
                    $update_status['shift_close_date'] = gmdate("Y-m-d H:i:s");
                    $update_status['shift_close_user_email'] = $user_details[0]['uname'];
                    $update_status['shift_close_user_name'] = $user_details[0]['fname'] ." ". $user_details[0]['lname'];
                    $update_status['status'] = 2; // status 1 is for shift currently active, 2 is for shift closed, 0  for shift changed  so when shift is closed the status is set to 2 
                    $logs['update_status' . date('H:i:s')] = $update_status;
                    $this->firebase_model->update("cashier_register", $update_status, array('shift_id' => $shift_id, 'cashier_id' => $cashier_id, 'DATE(created_at) like' => "%" . gmdate('Y-m-d') . "%"));
                    $logs['update_cashier_register_' . date('H:i:s')] = $this->db->last_query();
                }

                //Get closed shifts
                $cashier_details = $this->firebase_model->query('select * from cashier_register where status = "2" and cashier_id = "' . $cashier_id . '" and DATE(created_at) like "%' . gmdate('Y-m-d') . '%"');
                foreach ($cashier_details as $details) {
                    $closedShifts[$details['shift_id']] = (int) $details['shift_id'];
                }
                //Sync closed shifts.
                $additional_headers = $this->firebase_model->all_headers(array('action' => 'signOut_from_mpos', 'user_id' => $cashier_id));
                $logs['firebase_update_req1_' . date('H:i:s')] = array("node" => 'users/' . $validate_token['user_details']['user_id'] . '/shifts/' . date("Y-m-d") . '/closedShifts', 'details' => array_keys($closedShifts));
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                    'type' => 'POST',
                    'additional_headers' => $additional_headers,
                    'body' => array(
                        "node" => 'users/' . $validate_token['user_details']['user_id'] . '/shifts/' . date("Y-m-d") . '/closedShifts',
                        "details" => array_keys($closedShifts)
                    )
                ));

                $this->curl->requestASYNC('FIREBASE', '/delete_details', array(
                    'type' => 'POST',
                    'additional_headers' => $additional_headers,
                    'body' => array(
                        "node" => 'users/' . $validate_token['user_details']['user_id'] . '/shifts/' . date("Y-m-d") . '/selectedShift'
                    )
                ));
                $response['status'] = (int) 1;
                $response['message'] = 'User loggedout successfully.';
            } else {
                $MPOS_LOGS['request'] = $request_array['request'];
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = $this->exception_handler->error_400();
            }
           
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['signOut'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['user_id']);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @Name reserve_barcodes
     * @Purpose Used to get the barcodes and block the barcodes
     * @CreatedOn 12 june 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function reserve_barcodes() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'BARCODES';
        $MPOS_LOGS['external_reference'] = 'reserve';
        try {
            $request_array = $this->validate_api("reserve_barcodes");
            if(empty($request_array['errors'])) {
                $request = $request_array['request'];
                $validate_token = $request_array['validate_token'];
                /* Assign request parameters */
                $hotel_id = $validate_token['user_details']['distributorId'];
                $ticket_id = $request['product_id'];
                $museum_id = $request['museum_id'];
                $machine_id = $request['machine_id'];
                $barcode_type = $request['barcode_type'];
                $items = $request['items'];
                $release_combi_passes = $request['release_combi_passes'];
                $total_passes = 0;
                foreach ($items as $key => $item) {
                    $passes[$key][$item['category']] = $item['count'];
                    $total_passes += $item['count'];
                }
                $combi_bar_codes = array();
                //Release previous blocked barcodes which are not used due to any reason.
                if ($request['release_blocked_passes']) {
                    $this->common_model->update('purchased_qrcodes', array('machine_id' => ''), array('machine_id' => $machine_id, 'is_assigned' => '0'));
                    $this->common_model->update('assigned_barcodes', array('machine_id' => NULL), array('machine_id' => $machine_id, 'is_assigned' => '0'));
                }
                //Check all request params, if any parameter is blank, then return error response
                if ($ticket_id == '' || $ticket_id == '0') {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Ticket id is either blank or invalid.");
                } else if ($museum_id == '' || $museum_id == '0') {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Museum id is either blank or invalid.");
                } else if ($machine_id == '' || $machine_id == '0') {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Machine id is either blank or invalid.");
                } else if ($total_passes <= 0) {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', "Number of barcodes to reserve should be greater than zero");
                } else {
                    /* If ticket_id is replay_ticket_id, then block the barcode in purchased_qrcodes. */
                    if ($barcode_type == 3) {
                        if (isset($request['release_passes']) && !empty($request['release_passes'])) {
                            $this->firebase_model->query('update purchased_qrcodes set machine_id = "" where qr_code in (' . implode(',', $request['release_passes']) . ')');
                        }
                        foreach ($passes as $key => $passes) {
                            foreach ($passes as $category => $count) {
                                if ($category == "adult") {
                                    $adult_vs_child = 2;
                                } else if ($category == "child") {
                                    $adult_vs_child = 1;
                                }
                                //Get barcodes from table and block it
                                $barcodes[$key][$category] = $this->common_model->get_purchased_qrcodes($adult_vs_child, $count, $museum_id, $ticket_id);
                                if (empty($barcodes[$key][$category]) || count($barcodes[$key][$category]) != $count) {
                                    $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', $category . ' passes are not available.');
                                }
                                foreach ($barcodes[$key][$category] as $barcode) {
                                    $this->common_model->update('purchased_qrcodes', array('machine_id' => $machine_id, 'updated_at' => strtotime(gmdate('m/d/Y H:i:s'))), array('qr_code' => $barcode->qr_code));
                                }
                            }
                        }
                        if (empty($response)) {
                            foreach ($barcodes as $key => $barcode) {
                                foreach ($barcode as $category => $code) {
                                    foreach ($code as $pases) {
                                        $response['status'] = (int) 1;
                                        $response[$key]['category'] = $category;
                                        $response[$key]['passes'][] = $pases->barcode;
                                        $response['combi_barcodes'] = $combi_bar_codes;
                                    }
                                }
                            }
                        }
                    } else {
                        //If barcode type is 2, then fetch barcodes from assigned_barcodes table.
                        //Release previously blocked passes which are not used.
                        if (isset($request['release_passes']) && !empty($request['release_passes'])) {
                            $this->firebase_model->query('update assigned_barcodes set machine_id = NULL where barcode in (' . implode(',', $request['release_passes']) . ')');
                        }
                        if (!empty($release_combi_passes)) {
                            foreach ($release_combi_passes as $barcode) {
                                $this->common_model->update('assigned_barcodes', array('machine_id' => NULL, 'updated_at' => strtotime(gmdate('m/d/Y H:i:s'))), array('barcode' => $barcode['child']));
                            }
                        }
                        /*  In db ticket_id corresponding to rijksmuseum-id = 0 in assigned_barcodes. */
                        if ($museum_id == RIJKMUSEUM_ID) {
                            $ticket_id = 0;
                        }
                        /* Block the barcode in assigned_barcodes. */
                        foreach ($passes as $key => $passes) {
                            foreach ($passes as $category => $count) {
                                if ($category == "adult") {
                                    $adult_vs_child = 2;
                                } else if ($category == "child") {
                                    $adult_vs_child = 1;
                                }
                                //Get passes from table and block it
                                $barcodes[$key][$category] = $this->common_model->getbarcodePass($hotel_id, $adult_vs_child, $count, $museum_id, $ticket_id);
                                if (empty($barcodes[$key][$category]) || count($barcodes[$key][$category]) != $count) {
                                    $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', $category . ' passes are not available.');
                                }
                                foreach ($barcodes[$key][$category] as $barcode) {
                                    $this->common_model->update('assigned_barcodes', array('machine_id' => $machine_id, 'updated_at' => strtotime(gmdate('m/d/Y H:i:s'))), array('barcode' => $barcode->barcode));
                                }
                            }
                        }
                        if (empty($response)) {
                            foreach ($barcodes as $key => $barcode) {
                                foreach ($barcode as $category => $code) {
                                    foreach ($code as $pases) {
                                        $response['status'] = (int) 1;
                                        $response[$key]['category'] = $category;
                                        $response[$key]['passes'][] = $pases->barcode;
                                    }
                                }
                            }
                            //If ticket is combi ticket, then fetch its child passes for attached combi ticket.
                            if ($ticket_id == ARTIS_COMBI_TICKET_ID) {
                                foreach ($barcodes as $key => $barcode) {
                                    foreach ($barcode as $category => $pases) {
                                        if ($category == 'adult') {
                                            $adult_vs_child = 2;
                                        } else if ($category == 'child') {
                                            $adult_vs_child = 1;
                                        }
                                        foreach ($pases as $code) {
                                            $combi_passes = $this->common_model->getbarcodePass($hotel_id, $adult_vs_child, 1, ARTIS_ROYAL_ID, ARTIS_ROYAL_ZOO_TICKET_ID);
                                            if ($combi_passes[0]->barcode != '') {
                                                $combi_bar_codes[] = array(
                                                    'title' => 'MICROPIA COMBI TICKET',
                                                    'parent' => $code->barcode,
                                                    'child' => (isset($combi_passes[0]->barcode)) ? $combi_passes[0]->barcode : '',
                                                );
                                                $this->common_model->update('assigned_barcodes', array('machine_id' => $machine_id, 'updated_at' => strtotime(gmdate('m/d/Y H:i:s'))), array('barcode' => $combi_passes[0]->barcode));
                                            } else {
                                                $response = array();
                                                header('WWW-Authenticate: Basic realm="Authentication Required"');
                                                header('HTTP/1.0 401 Unauthorized');
                                                $response = $this->exception_handler->show_error(0, 'VALIDATION FAILURE', 'Barcodes are not available.');
                                                header('Content-Type: application/json');
                                                $logs['response'] = $response;
                                                $MPOS_LOGS['reserve_barcodes'] = $logs;
                                                $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
                                                echo json_encode($response);
                                                exit;
                                            }
                                        }
                                    }
                                }
                            }
                            $response['combi_barcodes'] = $combi_bar_codes;
                        }
                    }
                }
            } else {
                $MPOS_LOGS['request'] = $request_array['request'];
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = $this->exception_handler->error_400();
            }
           
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['reserve_barcodes'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name release_barcodes
     * @Purpose Release barcodes if not confirmed
     * @CreatedOn 12 june 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function release_barcodes() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'BARCODES';
        $MPOS_LOGS['external_reference'] = 'release';
            try {
            $request_array = $this->validate_api("release_barcodes");
            $request = $request_array['request'];
            /* Assign request parameters */
            $ticket_id = $request['product_id'];
            $items = $request['release_passes'];
            $combi_barcodes = $request['combi_barcodes'];
            if ($ticket_id == '' || $ticket_id == '0') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Ticket id is either blank or invalid.');
            } else {
                //Released al barcodes if ticket is not confirmed due to any reason.
                if (!empty($items)) {
                    if (RIPLEY_TICKET_ID == $ticket_id) {

                        $this->common_model->query('update purchased_qrcodes set machine_id = NULL, updated_at ="' . strtotime(gmdate('m/d/Y H:i:s')) . '" where qr_code in ("' . implode('", "', $items) . '")');
                    } else {
                        $this->common_model->query('update assigned_barcodes set machine_id = NULL, updated_at ="' . strtotime(gmdate('m/d/Y H:i:s')) . '" where barcode in ("' . implode('", "', $items) . '")');

                        if (!empty($combi_barcodes)) {

                            $this->common_model->query('update assigned_barcodes set machine_id = NULL, updated_at ="' . strtotime(gmdate('m/d/Y H:i:s')) . '" where barcode in ("' . implode('", "', $combi_barcodes) . '")');
                        }
                    }
                    $response['status'] = 1;
                } else {
                    $response = $this->exception_handler->show_error(0, 'INVALID_PASSES', 'The provided passes are not valid.');
                }
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name send_mail
     * @Purpose Send order details in email
     * @CreatedOn 22 june 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function send_mail() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'SHOP';
        $MPOS_LOGS['external_reference'] = 'overview';
        $jsonStr = file_get_contents("php://input"); //read the HTTP body.
        $_REQUEST = json_decode($jsonStr, TRUE);
        $response = array();
        $MPOS_LOGS['request'] = $_REQUEST;
        $MPOS_LOGS['request_time'] = date('H:i:s');
        try {
            $headers = $this->replace_hyphens_with_underscores(apache_request_headers());
			$validate_token = mpos_auth_helper::verify_token($headers['jwt_token'], $headers['cashier_id']);
            $MPOS_LOGS['validated_token'] = $validate_token;
            if(empty($_REQUEST)) {
                $response = $this->exception_handler->error_400();                              
            } else {                
                $validate_response = $this->authorization->validate_request_params($_REQUEST, [                                                     
                    'reference_id'                  => 'numeric',                                                                                                                                                                                                                  
                    'visitor_group_no'              => 'numeric',                                                                                                                                                                                                                                                    
                    'hotel_id'                      => 'numeric',                                                                                                                                                                                                                                                    
                    'cashier_id'                    => 'numeric',                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        
                ]);
                if(!empty($validate_response)) {
                    $response = $this->exception_handler->error_400();    
                } else {
                    /* Assign request parameters */
                    if (!empty($_REQUEST['reference_id'])) {  //for processed order
                        $logs_ref = $_REQUEST['reference_id'];
                        $visitor_group_no = $_REQUEST['reference_id'];
                        $email = $_REQUEST['email'];
                        if ($visitor_group_no == '') {
                            $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter reference_id');
                        } else if ($email == '') {
                            $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter email id');
                        } else {
                            //Check if requested visitor_group_no exist in DB or order is still in processing
                            $process_email = 1;
                            $order_details = $this->common_model->find('prepaid_tickets', array('select' => 'hotel_id, channel_type', 'where' => 'visitor_group_no = "' . $visitor_group_no . '"'), 'array', 0);
                            if (in_array($order_details[0]['channel_type'], $this->mpos_channels)) {
                                $mpos_details = $this->common_model->find('mpos_requests', array('select' => '*', 'where' => 'visitor_group_no = "' . $visitor_group_no . '" and status = "1"'), 'array', 0);
                                if(empty($mpos_details)) {
                                    $process_email = 0;
                                }
                            }
                            $logs['process_email and order_details' . date('H:i:s')] = array('process_email' => $process_email, 'order_details' => json_encode($order_details));
                            if (!empty($order_details) && $process_email == 1) {
                                //If order is processed, prepare array and send it in queue
                                if (LOCAL_ENVIRONMENT !== 'Local') {
                                    $common_email_request_data = [
                                        "data" => [
                                            "notification" => [
                                                "notification_event" => "ORDER_CREATE",
                                                "notification_item_id" => [
                                                    "order_reference" =>  $visitor_group_no
                                                ],
                                                "guest_email" => $email
                                            ]
                                        ]
                                    ];
                                    $logs['common_email_request_data_' . date('H:i:s')] = json_encode($common_email_request_data);
                                    require 'aws-php-sdk/aws-autoloader.php';
                                    $this->load->library('Sqs');
                                    $sqs_object = new \Sqs();
                                    $common_email_request_message = base64_encode(gzcompress(json_encode($common_email_request_data)));
                                    $MessageId = $sqs_object->sendMessage(COMMON_EMAIL_QUEUE_URL, $common_email_request_message);
                                    if ($MessageId) {
                                        $this->load->library('Sns');
                                        $sns_object = new \Sns();
                                        $sns_object->publish('create', COMMON_EMAIL_TOPIC_ARN);
                                    }
                                    $logs['data posted to queue_ COMMON_EMAIL_QUEUE_URL_at'] = date('H:i:s');
                                }
                                $response['status'] = (int) 1;
                            } else {
                                //If order is still in processing, add entry in DB and return response
                                $this->common_model->save('firebase_pending_request', array('visitor_group_no' => $visitor_group_no, 'request_type' => 'send-email', 'request' => json_encode($_REQUEST), 'added_at' => gmdate("Y-m-d H:i:s")));
                                $response['status'] = (int) 1;
                                $response['message'] = 'added_in_queue';
                            }
                        }
                    } else { // for shift report
                        $logs_ref = $_REQUEST['email'];
                        $email = $_REQUEST['email'];
                        if ($email == '' || empty($_REQUEST['data'])) {
                            $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter email id and required params');
                        } else {
                            if ($_REQUEST['show_redeems_on_endshift']) {
                                $show_redeems = 1;
                            } else {
                                $show_redeems = 0;
                            }
                            $email_details = array(
                                'request_type' => 'send-shift-report',
                                'hotel_id' => $_REQUEST['hotel_id'],
                                'cashier_id' => $_REQUEST['cashier_id'],
                                'email' => $_REQUEST['email'],
                                'cashier_name' => $_REQUEST['cashier_name'],
                                'end_shift_date' => $_REQUEST['end_shift_date'],
                                'data' => $_REQUEST['data'],
                                'show_redeems' => $show_redeems
                            );
                            $logs['data_to_queue_FIREBASE_EMAIL_QUEUE_' . date('H:i:s')] = $email_details;
                            // Load SQS library.
                            require 'aws-php-sdk/aws-autoloader.php';
                            $aws_message = json_encode($email_details);
                            $aws_message = base64_encode(gzcompress($aws_message));
                            $queueUrl = FIREBASE_EMAIL_QUEUE; // live_event_queue
                            $this->load->library('Sqs');
                            $sqs_object = new \Sqs();
                            if (LOCAL_ENVIRONMENT == 'Local') {
                                local_queue_helper::local_queue($aws_message, 'FIREBASE_EMAIL_TOPIC');
                            } else {
                                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                                if ($MessageId) {
                                    $this->load->library('Sns');
                                    $sns_object = new \Sns();
                                    $sns_object->publish($MessageId . '#~#' . $queueUrl, FIREBASE_EMAIL_TOPIC);
                                }
                            }
                            $response['status'] = (int) 1;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['send_email'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $logs_ref);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }
    /**
     * @Name update_cashier_register
     * @Purpose insert new entry in cashier register on the basis of cashier and pos point when login from firebase app
     * @CreatedOn 12 oct 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function update_cashier_register_v1() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'AUTH';
        $MPOS_LOGS['external_reference'] = 'start_shift';
        try {
            $request_array = $this->validate_api("update_cashier_register_v1");
            $_request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $hotel_id = $validate_token['user_details']['distributorId'];
            $company_details = $this->common_model->find('qr_codes', array('select' => 'company', 'where' => 'cod_id = "' . $hotel_id . '"'));
            $hotel_name = $company_details[0]['company'];
            $new_validate_response = array();
            foreach ($_request['shifts_data'] as $request) {
                $validate_response = $this->authorization->validate_request_params($request, [
                    'cashier_id'                    => 'numeric',
                    'pos_point_id'                  => 'numeric',
                    'start_amount'                  => 'decimal',
                    'shift_id'                      => 'numeric'
                ]);
                if (!empty($validate_response)) {
                    array_push($new_validate_response, $validate_response);
                }
            }
            if(!empty($new_validate_response)) {
                $logs['errors_array'] = $new_validate_response;
                $response = $this->exception_handler->error_400();
            } else {                
                foreach ($_request['shifts_data'] as $request) {
                    //start_end_shift == 1 for a new or terminated shift
                    if ($request['start_end_shift'] == "1") {
                        //Starting a new shift
                        $shift_id = $request['shift_id'];
                        $cashier_id = $request['cashier_id'];
                        $pos_point_id = $request['pos_point_id'];
                        $pos_point_name = $request['pos_point_name'];
                        $location_code = $request['location_code'];
                        $pos_point_admin_email = $request['pos_point_admin_email'];
                        $start_amount = $request['start_amount'];
                        $start_date = $request['start_time'];
                        $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
                        $timeZone = $servicedata->timeZone;
                        $reseller_cashier_id = $validate_token['user_details']['cashierType'] == "3" ? $validate_token['user_details']['resellerCashierId'] : "0";

                        //fetch active shifts
                        $cashier_details = $this->common_model->find('cashier_register', array('select' => '*', 'where' => 'pos_point_id = "' . $pos_point_id . '" and cashier_id = "' . $cashier_id . '" and shift_id = "' . $shift_id . '" and status = 1 and DATE(created_at) like "%' . gmdate('Y-m-d') . '%"'));
                        if (!empty($cashier_details)) {
                            $logs['fetch_cashier_register_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                            $internal_log['fetch_cashier_register_response_' . date('H:i:s')] = $cashier_details;
                            //If entry already exist in table for same shift, then update same (will occur only in status 0 case)
                            $update_cashier_register = array(
                                "modified_at" => $start_date,
                                "status" => 1,
                                "opening_time" => date("H:i:s", strtotime($start_date)),
                            );
                            $selected_shift = array(
                                'posPointId' => (int) $pos_point_id,
                                'shiftId' => (int) $shift_id,
                                'startAmount' => (float) $start_amount,
                                'startTime' => $cashier_details[0]['created_at']
                            );
                            $this->firebase_model->update("cashier_register", $update_cashier_register, array('pos_point_id' => $pos_point_id, 'cashier_id' => $cashier_id, 'shift_id' => $shift_id, 'created_at like' => "%" . gmdate('Y-m-d') . "%", 'reseller_cashier_id' => $reseller_cashier_id));
                        } else {
                            //fetch cashier name from db
                            $cashier_name_details = $this->common_model->find('users', array('select' => 'fname, lname', 'where' => 'id = "' . $cashier_id . '"'));
                            //If no entry exists, add new entry in DB
                            $cashier_register = array(
                                "created_at" => $start_date,
                                "modified_at" => $start_date,
                                "timezone" => $timeZone,
                                "hotel_id" => $hotel_id,
                                "shift_id" => $shift_id,
                                "cashier_id" => $cashier_id,
                                "pos_point_id" => $pos_point_id,
                                "pos_point_name" => $pos_point_name,
                                "location_code" => $location_code,
                                "pos_point_admin_email" => $pos_point_admin_email,
                                "hotel_name" => $hotel_name,
                                "cashier_name" => $cashier_name_details[0]['fname'] ." ". $cashier_name_details[0]['lname'],
                                "reference_id" => time(),
                                "opening_time" => date("H:i:s", strtotime($start_date)),
                                "opening_cash_balance" => $start_amount,
                                "closing_cash_balance" => $start_amount,
                                "total_closing_balance" => $start_amount,
                                "status" => '1',
                                "reseller_cashier_id" => $reseller_cashier_id
                            );
                            $this->db->insert("cashier_register", $cashier_register);
                            $selected_shift = array(
                                'posPointId' => (int) $pos_point_id,
                                'shiftId' => (int) $shift_id,
                                'startAmount' => (float) $start_amount,
                                'startTime' => $start_date
                            );
                        }
                        //update selected shift
                        $MPOS_LOGS['DB'][] = 'FIREBASE';
                        $this->curl->requestASYNC('FIREBASE', '/update_details', array(
                            'type' => 'POST',
                            'additional_headers' => $this->firebase_model->all_headers(array('action' => 'start_a_new_shift_from_mpos', 'user_id' => $cashier_id, 'hotel_id' => $hotel_id)),
                            'body' => array(
                                "node" => 'users/' . $validate_token['user_details']['user_id'] . '/shifts/' . date("Y-m-d") . '/selectedShift',
                                'details' => $selected_shift
                            )
                        ));
                    } else {
                        //if user changes pos point from app -> update previous shift's data -> status = 0
                        $shift_id = $request['shift_id'];
                        $cashier_id = $request['cashier_id'];
                        $pos_point_id = $request['pos_point_id'];
                        $update_cashier_register = array(
                            "status" => 0,
                            "modified_at" => $request['end_time'],
                            "closing_time" => date("H:i:s", strtotime($request['end_time'])),
                        );
                        $reseller_cashier_id = $validate_token['user_details']['cashierType'] == "3" ? $validate_token['user_details']['resellerCashierId'] : "0";
                        $this->firebase_model->update("cashier_register", $update_cashier_register, array('pos_point_id' => $pos_point_id, 'cashier_id' => $cashier_id, 'shift_id' => $shift_id, 'created_at like' => "%" . gmdate('Y-m-d') . "%", 'reseller_cashier_id' => $reseller_cashier_id));
                    }
                    $logs['cashier_register_query_' . 'start_end_shift' . '=>' . $request['start_end_shift'] . ' ' . date('H:i:s')] = str_replace('\n', '', $this->db->last_query());
                }
                $response['status'] = (int) 1;
                $response['message'] = 'successfully updated.';
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['update_cashier_register_v1'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }
      /**
     * @Name : checkout()
     * @Purpose : To complete the order and update in database placed from venue app.
     * @Call from : When user Pay and complete the order from app.
     * @Created : Karan <karan.intersoft@gmail.com> on 10 May 2017
     * @Modified : komal <komalgarg.intersoft@gmail.com> on 18 May 2017
     */
    //Firebase Updations
    function checkout($status = 0) {
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['operation_id'] = 'SHOP';
        $MPOS_LOGS['external_reference'] = 'order_process_from_app';
        try {
            $request_array = $this->validate_api("checkout");
            $user_details = $request_array['validate_token']['user_details'];
            $request = $request_array['request'];
            $this->load->model('V1/hotel_model');
            $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
            $timezone = $servicedata->timeZone;
            $currencyCode = $servicedata->currency;
            $add_booking = isset($request['add_booking']) && $request['add_booking'] == 1 ? 1 : 0;
            //add check for empty prepaid tickets array and  ticket types in prepaid tickets array  in request  
            if(empty($request['prepaid_tickets'])) {
                $response['status'] = (int) 1;
                $response['message'] = 'Something went wrong !! Please place the order again.';
                $logs['response'] = $response;
                $MPOS_LOGS['checkout'] = $logs;
                $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], "empty_array_" . $request['hotel_id']);
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            } else {
                foreach($request['prepaid_tickets'] as $req_prepaid_tickets) {
                    if(empty($req_prepaid_tickets['ticket_types'])) {
                        $response['status'] = (int) 1;
                        $response['message'] = 'Something went wrong !! Please place the order again.';
                        $logs['response'] = $response;
                        $MPOS_LOGS['checkout'] = $logs;
                        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], "empty_array_" . $request['hotel_id']);
                        header('Content-Type: application/json');
                        echo json_encode($response);
                        exit;
                    } else {
                        foreach($req_prepaid_tickets['ticket_types'] as $req_tickets_types) {
                            if(empty($req_tickets_types)) {
                                $response['status'] = (int) 1;
                                $response['message'] = 'Something went wrong !! Please place the order again.';
                                $logs['response'] = $response;
                                $MPOS_LOGS['checkout'] = $logs;
                                $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], "empty_array_" . $request['hotel_id']);
                                header('Content-Type: application/json');
                                header('Cache-control: no-store');
                                header('Pragma: no-cache');
                                echo json_encode($response);
                                exit;
                            }
                        }
                    }

                }
            }

            //check if req is already in DB for requested visitor_group_no
            $mpos_requests = $this->firebase_model->query('select id, visitor_group_no, json, status from mpos_requests where visitor_group_no = ' . $request['visitor_group_no'], 1);
            $logs['mpos_req_query_response'] = $mpos_requests;
            if (isset($request['is_confirmed']) && ($request['is_confirmed'] == 1) && $mpos_requests[0]['status'] == "2") { //confirmed req for order with status 2 in mpos_requests table
                $order_req = json_decode(gzuncompress(base64_decode($mpos_requests[0]['json'])), true);
                if(isset($MPOS_LOGS['headers']) && $MPOS_LOGS['headers']['eventcode'] == "CAPTURE" && isset($order_req['decoded_token'])) {
                    $MPOS_LOGS['validated_token'] = $order_req['decoded_token'];
                }
                foreach ($request['prepaid_tickets'] as $pt_ticket) {
                    $passes_to_insert[] = $pt_ticket['passNo'];
                }
                $mpos_request_update['passNo'] = implode(',', $passes_to_insert);
                $mpos_request_update['booking_date_time'] = $request['booking_date_time'];
                $mpos_request_update['json'] = base64_encode(gzcompress(json_encode($request)));
                $mpos_request_update['status'] = 0;
                $mpos_request_update['updated_at'] = date('Y-m-d h:i:s');
                $this->firebase_model->update('mpos_requests', $mpos_request_update, array('visitor_group_no' => $request['visitor_group_no']));
            } else if (!empty($mpos_requests) && $add_booking == 0) {
                $internal_log['mpos_response_' . date('H:i:s')] = $mpos_requests;
                //If visitor_group_no already exist, return error res.
                $response['reference_id'] = $request['visitor_group_no'];
                $response['status'] = (int) 1;
                $response['message'] = 'Order already placed.';
                $logs['response'] = $response;
                $MPOS_LOGS['checkout'] = $logs;
                $internal_logs['checkout'] = $internal_log;
                $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], $request['hotel_id']);
                $this->apilog_library->write_log($internal_logs, 'internalLog', $request['visitor_group_no'], $request['hotel_id']);
                header('Content-Type: application/json');
                header('Cache-control: no-store');
                header('Pragma: no-cache');
                echo json_encode($response);
                exit;
            } else {
                foreach ($request['prepaid_tickets'] as $pt_ticket) {
                    $passes_to_insert[] = $pt_ticket['passNo'];
                }
                $insert_passes = implode(',', $passes_to_insert);
                //if visitor_group_no not exist, add entry in DB and preocess req.
                if (isset($request['is_confirmed']) && ($request['is_confirmed'] == 0)) {
                    $request['decoded_token'] = $MPOS_LOGS['validated_token'];
                }
                $mpos_message = base64_encode(gzcompress(json_encode($request))); // To compress heavy data data to pass inSQS  message.
                $mpos_request = array();
                $mpos_request['created_at'] = date('Y-m-d h:i:s');
                $mpos_request['visitor_group_no'] = $request['visitor_group_no'];
                $mpos_request['passNo'] = $insert_passes;
                $mpos_request['booking_date_time'] = $request['booking_date_time'];
                $mpos_request['json'] = $mpos_message;
                $mpos_request['status'] = isset($request['is_confirmed']) && ($request['is_confirmed'] == 0) ? 2 : 0;
                $mpos_request['updated_at'] = date('Y-m-d h:i:s');
                $this->db->insert('mpos_requests', $mpos_request);
                if (isset($request['is_confirmed']) && ($request['is_confirmed'] == 0)) {
                    $response['reference_id'] = $request['visitor_group_no'];
                    $response['status'] = (int) 1;
                    $response['message'] = 'Request Processed Successfully.';
                    $logs['response'] = $response;
                    $MPOS_LOGS['checkout'] = $logs;
                    $internal_logs['checkout'] = $internal_log;
                    $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], $request['hotel_id']);
                    $this->apilog_library->write_log($internal_logs, 'internalLog', $request['visitor_group_no'], $request['hotel_id']);
                    header('Content-Type: application/json');
                    header('Cache-control: no-store');
                    header('Pragma: no-cache');
                    echo json_encode($response);
                    exit;
                }
            }
            
            $valid_request = 1;
            if ($request['add_to_new_priopass'] == '1' && !empty($request['guest_details'])) {
                foreach ($request['guest_details'] as $pass => $guest_detail) {
                    if ($valid_request == 1 && strlen($pass) < 15) { //all passes should be of length 16
                        $valid_request = 0;
                        $invalid_pass = $pass;
                    }
                }
            }
            if ($valid_request == 0) {
                $logs['invalid_pass_' . date('H:i:s')] = $invalid_pass;
                //If visitor_group_no already exist, return error res.
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Invalid Card');
                $logs['response'] = $response;
                $MPOS_LOGS['checkout'] = $logs;
                $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], "invalid_pass_" . $request['hotel_id']);
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }

            //Check if payment is done by card, then capture payment. 
            // payment_method = 1(card),  payment_type = 5 (sumup) , payment_type = 4(adyen terminal)
            if ($request['payment_method'] == 1 && $request['adyen_details']['merchantAccount'] != '' && ($request['payment_type'] != "5" && $request['payment_type'] != "4")) {
                $this->capturePayment($request['adyen_details']['merchantAccount'], $request['adyen_details']['total_amount'], $request['adyen_details']['pspReference'], $currencyCode);
            }
            if ($request['payment_type'] == "5") {
                $request['adyen_details']['cardType'] = $request['adyen_details']['cardType'] . '_adyen';
            }
            if ($request['payment_type'] == "4") {
                $request['adyen_details']['cardType'] = $request['adyen_details']['cardType'] . '_sumup';
            }
            $prepaid_tickets = $request['prepaid_tickets'];
            if (!isset($request['selected_partner'])) {
                $request['selected_partner'] = $request['partner_name'];
            }
            $request['reseller_cashier_id'] = isset($user_details['cashierType']) && $user_details['cashierType'] == "3" ? $request['get_id'] : "0";
            $request['get_id'] = isset($user_details['cashierType']) && $user_details['cashierType'] == "3" ? $user_details['defaultCashierId'] : $request['get_id'];
            $request['add_to_prioticket'] = (!isset($request['add_to_new_priopass']) && $request['add_to_new_priopass'] != '') ? '1' : $request['add_to_new_priopass'];
            $request['prepaid_tickets'] = array();
            /* Update combi barcodes when combi ticket is booked. */
            if (isset($request['combi_barcodes']) && !empty($request['combi_barcodes'])) {
                $barcode_array = array();
                foreach ($request['combi_barcodes'] as $barcode) {
                    $barcode_array[] = $barcode['parent'];
                    $barcode_array[] = $barcode['child'];
                    $data = array();
                    $data['ticket_id'] = ARTIS_COMBI_TICKET_ID;
                    $data['visitor_group_no'] = $request['visitor_group_no'];
                    $data['parent_bar_code'] = $barcode['parent'];
                    $data['bar_code'] = $barcode['child'];
                    $barcode_batch[] = $data;
                }
                if (!empty($barcode_array)) {
                    $this->firebase_model->query('update assigned_barcodes set is_assigned = "1" and updated_at = "' . strtotime(gmdate('m/d/Y H:i:s')) . '" where barcode in (' . implode(',', $barcode_array) . ')');
                    $this->common_model->insert_batch('assigned_combi_bar_codes', $barcode_batch);
                }
            }
            $visitor_group_no = $request['visitor_group_no'];
            //calculate extra option prices to update bookings listing on firebase
            foreach ($request['extra_options_per_ticket'] as $key => $extra_options) {
                foreach ($extra_options as $option) {
                    $option_selected_date = $option['selected_date'] != '' ? $option['selected_date'] : gmdate("Y-m-d");
                    $option_from_time = $option['from_time'] != '' ? $option['from_time'] : "0";
                    $option_to_time = $option['to_time'] != '' ? $option['to_time'] : "0";
                    $option_sync_key = base64_encode($visitor_group_no . '_' . $key . '_' . $option_selected_date . '_' . $option_from_time . "_" . $option_to_time . "_" . $request['booking_date_time']);
                    if ($option['ticket_price_schedule_id'] == "0") {
                        $per_ticket_extra_options[$option_sync_key][$option['ticket_id']][$option['description']] = array(
                            'main_description' => $option['main_service_name'],
                            'description' => $option['description'],
                            'quantity' => (int) $option['quantity'],
                            'refund_quantity' => (int) 0,
                            'price' => (float) $option['price'],
                        );
                    } else {
                        $per_age_group_extra_options[$option_sync_key][$option['ticket_id']][$option['ticket_price_schedule_id']][$option['description']] = array(
                            'main_description' => $option['main_service_name'],
                            'description' => $option['description'],
                            'quantity' => (int) $option['quantity'],
                            'refund_quantity' => (int) 0,
                            'price' => (float) $option['price'],
                        );
                    }
                    $bookings[$option_sync_key]['amount'] = (float) ($bookings[$option_sync_key]['amount'] + ($option['price'] * $option['quantity']));
                }
            }
            $assigned_barcode_passes = array();
            $purchased_qrcodes_passes = array();
            //prepare all details of order to sync on firebase
            foreach ($prepaid_tickets as $prepaid_ticket) {
                $selected_date = $prepaid_ticket['selected_date'] != '' ? $prepaid_ticket['selected_date'] : gmdate("Y-m-d");
                $from_time = $prepaid_ticket['from_time'] != '' ? $prepaid_ticket['from_time'] : "0";
                $to_time = $prepaid_ticket['to_time'] != '' ? $prepaid_ticket['to_time'] : "0";
                $sync_key = base64_encode($visitor_group_no . '_' . $prepaid_ticket['ticket_id'] . '_' . $selected_date . '_' . $from_time . "_" . $to_time . "_" . $request['booking_date_time']);
                $new_request = array();
                $temp = $prepaid_ticket;
                $temp['ticket_types'] = array();

                /* discount_code_amount on the basis of ticket_id (collectively for all types) */
                $total_discount_code_amount[$sync_key]['discount'] = (isset($total_discount_code_amount[$sync_key]['discount'])) ? $total_discount_code_amount[$sync_key]['discount'] : 0;
                foreach ($prepaid_ticket['ticket_types'] as $ticket_type) {

                    // combi on and off cases
                    if ($prepaid_ticket['is_combi_ticket'] == '1') {
                        for ($i = 0; $i < $ticket_type['count']; $i++) {
                            $passes[$ticket_type['ticket_price_schedule_id']][$i] = $prepaid_ticket['passNo'];
                        }
                    } else {
                        $passes[$prepaid_ticket['ticket_price_schedule_id']][$prepaid_ticket['passNo']] = $prepaid_ticket['passNo'];
                    }
                    if (isset($ticket_type['cashier_discount']['quantity']) && $ticket_type['cashier_discount']['quantity'] > 0) {
                        $bookings[$sync_key]['is_discount_code'] = $request['is_discount_code'];
                        $bookings[$sync_key]['discount_code_type'] = $request['discount_type'];

                        if ($request['discount_type'] == 1) { /* percentage discount */
                            $dis_value = ((($ticket_type['price'] + $ticket_type['combi_discount_gross_amount']) * $request['discount_amount']) / 100);
                        } else { /* fixed discount */
                            $dis_value = $request['discount_amount'];
                        }
                        $ticket_type_cashier_discount[$sync_key][$ticket_type['ticket_price_schedule_id']]['discount'] = $ticket_type_cashier_discount[$sync_key][$ticket_type['ticket_price_schedule_id']]['discount'] + ($ticket_type['cashier_discount']['quantity'] * $dis_value);
                        $total_discount_code_amount[$sync_key]['discount'] = $total_discount_code_amount[$sync_key]['discount'] + ($ticket_type['cashier_discount']['quantity'] * $dis_value);
                    } else if (!isset($bookings[$sync_key]['is_discount_code'])) {
                        $bookings[$sync_key]['discount_code_type'] = 0;
                        $bookings[$sync_key]['is_discount_code'] = 0;
                    }
                    if (isset($ticket_type['ticket_price_schedule_id'])) {
                        $temp['price'] = $ticket_type['price'];
                        $temp['oroginal_price'] = $prepaid_ticket['original_price'];
                        $temp['ticket_types'][$ticket_type['ticket_price_schedule_id']] = $ticket_type;
                    } else {
                        $temp['price'] = $ticket_type['price'];
                        $temp['oroginal_price'] = $prepaid_ticket['original_price'];
                        $temp['ticket_types'][] = $ticket_type;
                    }
                    sort($per_age_group_extra_options[$sync_key][$prepaid_ticket['ticket_id']][$ticket_type['ticket_price_schedule_id']]);
                    $ticket_types[$sync_key][$ticket_type['ticket_price_schedule_id']] = array(
                        'tps_id' => (int) $ticket_type['ticket_price_schedule_id'],
                        'age_group' => $ticket_type['age_group'],
                        'price' => (float) ($ticket_type['price'] + $ticket_type['combi_discount_gross_amount']),
                        'net_price' => (float) $ticket_type['net_price'],
                        'tax' => (float) $ticket_type['tax'],
                        'type' => $ticket_type['type'],
                        'cashier_discount' => ($ticket_type_cashier_discount[$sync_key][$ticket_type['ticket_price_schedule_id']]['discount'] > 0) ? (float) $ticket_type_cashier_discount[$sync_key][$ticket_type['ticket_price_schedule_id']]['discount'] : (float) 0,
                        'cashier_discount_quantity' => (int) ($ticket_types[$sync_key][$ticket_type['ticket_price_schedule_id']]['cashier_discount_quantity'] + $ticket_type['cashier_discount']['quantity']),
                        'quantity' => (int) $ticket_types[$sync_key][$ticket_type['ticket_price_schedule_id']]['quantity'] + $ticket_type['count'],
                        'combi_discount_gross_amount' => (float) $ticket_type['combi_discount_gross_amount'],
                        'refund_quantity' => (int) 0,
                        'refunded_by' => array(),
                        'per_age_group_extra_options' => (!empty($per_age_group_extra_options[$sync_key][$prepaid_ticket['ticket_id']][$ticket_type['ticket_price_schedule_id']])) ? $per_age_group_extra_options[$sync_key][$prepaid_ticket['ticket_id']][$ticket_type['ticket_price_schedule_id']] : array(),
                        'passes' => array_values($passes[$ticket_type['ticket_price_schedule_id']]),
                    );
                    $bookings[$sync_key]['total_combi_discount'] = (float) ($bookings[$sync_key]['total_combi_discount'] + $ticket_type['total_combi_discount']);
                    $bookings[$sync_key]['amount'] = (float) ($bookings[$sync_key]['amount'] + (($ticket_type['price'] + $ticket_type['combi_discount_gross_amount']) * $ticket_type['count']));
                    $bookings[$sync_key]['quantity'] = (int) ($bookings[$sync_key]['quantity'] + $ticket_type['count']);
                    $bookings[$sync_key]['passes'] = ($bookings[$sync_key]['passes'] != '' && $prepaid_ticket['is_combi_ticket'] == 0 && $prepaid_ticket['passNo'] != 'SKIP') ? $bookings[$sync_key]['passes'] . ', ' . $prepaid_ticket['passNo'] : $prepaid_ticket['passNo'];

                    $order_details['booking_date_time'] = (string) $request['booking_date_time'];
                    $order_details['booking_email'] = (string) $request['booking_email'];
                    $order_details['booking_name'] = (string) $request['booking_name'];
                    $order_details['note'] = (isset($request['extra_note']) && $request['extra_note'] != '') ? (string) $request['extra_note'] : '';
                    $order_details['payment_method'] = (int) $request['payment_method'];
                    $order_details['status'] = (int) 1;
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['museum_id'] = (int) $prepaid_ticket['museum_id'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['museum_name'] = (string) $prepaid_ticket['company'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['title'] = (string) $prepaid_ticket['title'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['ticket_id'] = (int) $prepaid_ticket['ticket_id'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['types'][$ticket_type['ticket_price_schedule_id']]['tps_id'] = (int) $ticket_type['ticket_price_schedule_id'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['types'][$ticket_type['ticket_price_schedule_id']]['age_group'] = (string) $ticket_type['age_group'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['types'][$ticket_type['ticket_price_schedule_id']]['quantity'] += (int) $ticket_type['count'];
                    $order_details['tickets'][$prepaid_ticket['ticket_id']]['types'][$ticket_type['ticket_price_schedule_id']]['type'] = (string) $ticket_type['type'];
                }
                if (isset($request['subticket_details']) && !empty($request['subticket_details']) && $prepaid_ticket['product_type'] == '2') {
                    foreach($request['subticket_details'] as $item_reference => $subticket_detail){
                        foreach($subticket_detail as $ticket_id => $ticket_details) {
                            $ticket_details['pass_type'] = (int) $ticket_details['pass_type'];
                            $ticket_details['ticket_id'] = (int) $ticket_details['ticket_id'];
                            foreach($ticket_details['type_details'] as $type => $type_details){
                                $type_details['ticket_type'] = (int) $type_details['ticket_type'];
                                $type_details['tps_id'] = (int) $type_details['tps_id'];
                                $newTypeDetail[$type] = $type_details;
                            }
                            $ticket_details['type_details'] = $newTypeDetail;
                            $new_ticketdetails_array[$ticket_id] = $ticket_details;
                        }
                        $new_subticket_array[$item_reference] =  $new_ticketdetails_array;
                    }
                    $sub_tickets[$prepaid_ticket['ticket_id']] = isset($request['subticket_details'][$prepaid_ticket['item_reference']]) ? $new_subticket_array[$prepaid_ticket['item_reference']] : array();
                }
                /* Set values to sync bookings on firebase. */
                sort($per_ticket_extra_options[$sync_key][$prepaid_ticket['ticket_id']]);
                $bookings[$sync_key]['booking_date_time'] = $request['booking_date_time'];
                $bookings[$sync_key]['booking_name'] = $request['booking_name'];
                $bookings[$sync_key]['cashier_name'] = $request['cashier_name'];
                $bookings[$sync_key]['pos_point_id'] = (int) $request['get_pos_point_id'];
                $bookings[$sync_key]['pos_point_name'] = $request['pos_point_name'];
                $bookings[$sync_key]['group_id'] = $request['payment_method'] == "10" || $request['payment_method'] == "19" ? (int) $request['group_id'] : (int) 0;
                $bookings[$sync_key]['group_name'] = $request['payment_method'] == "10" || $request['payment_method'] == "19" ? $request['group_name'] : '';
                $bookings[$sync_key]['channel_type'] = (int) $request['channel_type'];
                $bookings[$sync_key]['discount_code_amount'] = (float) $total_discount_code_amount[$sync_key]['discount'];
                $bookings[$sync_key]['service_cost_type'] = ($prepaid_ticket['apply_tax'] == "1") ? (int) $request['service_cost_type'] : (int) 0;
                $bookings[$sync_key]['service_cost_amount'] = ($prepaid_ticket['apply_tax'] == "1") ? (float) $request['service_cost_amount'] : (float) 0;
                $bookings[$sync_key]['shift_id'] = (int) $request['shift_id'];
                $bookings[$sync_key]['activated_by'] = (int) 0;
                $bookings[$sync_key]['activated_at'] = (int) 0;
                $bookings[$sync_key]['cancelled_tickets'] = (int) 0;
                $bookings[$sync_key]['ticket_types'] = (!empty($ticket_types[$sync_key])) ? $ticket_types[$sync_key] : array();
                $bookings[$sync_key]['subticket_details'] = (!empty($sub_tickets[$prepaid_ticket['ticket_id']])) ? $sub_tickets[$prepaid_ticket['ticket_id']] : array();
                $bookings[$sync_key]['per_ticket_extra_options'] = (!empty($per_ticket_extra_options[$sync_key][$prepaid_ticket['ticket_id']])) ? $per_ticket_extra_options[$sync_key][$prepaid_ticket['ticket_id']] : array();
                $bookings[$sync_key]['from_time'] = ($prepaid_ticket['from_time'] != '' && $prepaid_ticket['from_time'] != '0') ? $prepaid_ticket['from_time'] : "";
                $bookings[$sync_key]['is_reservation'] = (int) $prepaid_ticket['is_reservation'];
                $bookings[$sync_key]['merchant_reference'] = $request['adyen_details']['merchantReference'];
                $bookings[$sync_key]['museum'] = $prepaid_ticket['company'];
                $bookings[$sync_key]['order_id'] = (isset($visitor_group_no) && $visitor_group_no != '') ? $visitor_group_no : $request['visitor_group_no'];
                $bookings[$sync_key]['payment_method'] = (int) $request['payment_method'];
                $bookings[$sync_key]['reservation_date'] = ($prepaid_ticket['selected_date'] != '' && $prepaid_ticket['selected_date'] != '0') ? $prepaid_ticket['selected_date'] : "";
                $bookings[$sync_key]['ticket_id'] = (int) $prepaid_ticket['ticket_id'];
                $bookings[$sync_key]['ticket_title'] = $prepaid_ticket['title'];
                $bookings[$sync_key]['guest_notification'] = $prepaid_ticket['guest_notification'];
                $bookings[$sync_key]['product_type'] = (int) $prepaid_ticket['product_type'];
                $bookings[$sync_key]['pass_allocation_type'] = (int) $prepaid_ticket['pass_allocation_type'];
                $bookings[$sync_key]['timezone'] = (int) $timezone;
                $bookings[$sync_key]['to_time'] = ($prepaid_ticket['to_time'] != '' && $prepaid_ticket['to_time'] != '0') ? $prepaid_ticket['to_time'] : "";
                $bookings[$sync_key]['status'] = (int) 1;
                $bookings[$sync_key]['change'] = (int) !empty($prepaid_ticket['change']) ? 1 : 0;
                $bookings[$sync_key]['slot_type'] = ($prepaid_ticket['slot_type'] != '' ) ? $prepaid_ticket['slot_type'] : "";
                $bookings[$sync_key]['is_combi_ticket'] = !empty($prepaid_ticket['is_combi_ticket']) ? (int) $prepaid_ticket['is_combi_ticket'] : 0;
                $bookings[$sync_key]['is_extended_ticket'] = (int) 0;
                $bookings[$sync_key]['pass_type'] = !empty($prepaid_ticket['pass_type']) ? (int) $prepaid_ticket['pass_type'] : 0;
                $bookings[$sync_key]['voucher_exception_code'] = isset($request['voucher_exception_code']) && $request['voucher_exception_code'] != '' ? $request['voucher_exception_code'] : '';
                $new_request[] = $temp;
                $request['prepaid_tickets'][] = $new_request;
                if ($prepaid_ticket['pass_type'] == 2 && empty($prepaid_ticket['third_party_details'])) {
                    $assigned_barcode_passes[] = $prepaid_ticket['passNo'];
                }
                if ($prepaid_ticket['pass_type'] == 3 && empty($prepaid_ticket['third_party_details'])) {
                    $purchased_qrcodes_passes[] = $prepaid_ticket['passNo'];
                }
                $activation_method = $request['payment_method'];
            }
            if (!empty($assigned_barcode_passes)) {
                $this->firebase_model->query('update assigned_barcodes set is_assigned = "1" where barcode in ("' . implode('","', $assigned_barcode_passes) . '")');
            }
            if (!empty($purchased_qrcodes_passes)) {
                $this->firebase_model->query('update purchased_qrcodes set is_assigned = "1" and assigned_date = "' . strtotime(gmdate('Y-m-d H:i:s')) . '" where qr_code in ("' . implode('","', $purchased_qrcodes_passes) . '")');
            }
            $logs['update_barcodes_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            /* SYNC bookings */
            $additional_headers = $this->firebase_model->all_headers(array(
                'action' => 'order_process_from_mpos',
                'user_id' => $cashier_id,
                'hotel_id' => $request['hotel_id'],
                'museum_id' => $prepaid_ticket['museum_id'],
                'ticket_id' => $prepaid_ticket['ticket_id'],
                'channel_type' => $request['channel_type']
            ));
            $params = array(
                'type' => 'POST',
                'additional_headers' => $additional_headers,
                'body' => array(
                    "node" => 'distributor/bookings_list/' . $request['hotel_id'] . '/' . $request['get_id'] . '/' . date("Y-m-d", strtotime($request['booking_date_time'])),
                    'details' => $bookings
                )
            );
            $logs['order_sync_req_' . date('H:i:s')] = $params['body'];
            $MPOS_LOGS['DB'][] = 'FIREBASE';
            $this->curl->requestASYNC('FIREBASE', '/update_details', $params);

            if (!empty($order_details)) {
                $params = array(
                    'type' => 'POST',
                    'additional_headers' => $additional_headers,
                    'body' => array("node" => 'distributor/orders_list/' . $request['hotel_id'] . '/' . $request['get_id'] . '/' . gmdate("Y-m-d") . '/' . $request['visitor_group_no'], 'details' => $order_details)
                );
                $logs['order_list_sync_req_' . date('H:i:s')] = $params['body'];
                $this->curl->requestASYNC('FIREBASE', '/update_details', $params);
            }

            $company_details = $this->common_model->find('qr_codes', array('select' => 'is_credit_card_fee_charged_to_guest, initial_payment_charged_to_guest, service_cost', 'where' => 'cod_id = "' . $request['hotel_id'] . '"'));
            $logs['qr_codes_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['qr_codes_response_' . date('H:i:s')] = $company_details;
            $shopperRef = $request['adyen_details']['shopperReference'];
            $merchantAcountCode = $request['adyen_details']['merchantAccount'];
            $merchantRef = $request['adyen_details']['merchantReference'];
            $shopperEmail = strip_tags($request['adyen_details']['shopperEmail']);
            $amount = $total_amount = strip_tags($request['adyen_details']['total_amount']);

            /* in case of "add to existing pass", if payment is done with credit card, then call function to deduct payment and make entry on adyen. */
            if ($total_amount > 0 && $request['is_existing_pass'] == 1) {
                $is_credit_card_fee_charged_to_guest = $company_details[0]['is_credit_card_fee_charged_to_guest'];
                $initial_payment_charged_to_guest = $company_details[0]['initial_payment_charged_to_guest'];
                $service_gross_price = $company_details[0]['service_cost'];

                if ($is_credit_card_fee_charged_to_guest == 1) { // If cc cost is charged from Guest       
                    $taxarray = $this->common_model->getPrepaidTax(); // Get credit card tax amounts for POS activations
                    if ($taxarray) {
                        if ($taxarray->calculated_on == '0') { // If caculation is per transaction
                            // Add fix and variable tax, and tax on cc cost to the total amount  
                            $ticketAmtarray = $this->common_model->addFixedVariableTaxToAmount($total_amount, $taxarray->fixed_amount, $taxarray->variable_amount, $taxarray->tax_value);
                            $amount = $ticketAmtarray['ticketAmt'];
                        } else { // Per ticket calculation
                            $mainamountwithtax = 0;
                            // Loop for all the tickets to apply cc cost per ticket                            
                            foreach ($request['prices'] as $perticketamount) {
                                if ($perticketamount > 0) {
                                    // Add fix and variable tax, and tax on cc cost to ticket amount
                                    $ticketAmtarray = $this->common_model->addFixedVariableTaxToAmount($perticketamount, $taxarray->fixed_amount, $taxarray->variable_amount, $taxarray->tax_value);
                                    $mainamountwithtax = $mainamountwithtax + $ticketAmtarray['ticketAmt'];
                                }
                            }
                            // If there is service cost per transaction
                            if ($service_gross_price > 0) {
                                // Add only varible tax cost
                                $servicewithtax = ($service_gross_price * ($taxarray->variable_amount / 100));
                                $servicewithtax = round($servicewithtax, 2);
                                $totalservicewithtax = $servicewithtax + (($servicewithtax * $taxarray->tax_value) / 100);
                                $totalticketAmt = $service_gross_price + round($totalservicewithtax, 2);
                                $mainamountwithtax = $mainamountwithtax + $totalticketAmt;
                            }
                            $amount = $mainamountwithtax;
                        }
                        if ($initial_payment_charged_to_guest == 1) { // If initial payment is charged from guest
                            $initial_price = (0.1 + ((0.1 * $taxarray->tax_value) / 100));
                            $initial_price = round($initial_price, 2);
                            $amount = $amount + $initial_price;
                        }
                    }
                }
                $result = $this->deduct_from_card($merchantAcountCode, $currencyCode, $amount, $merchantRef, $shopperEmail, $shopperRef, PAYMENT_MODE);
                if ($result->paymentResult->resultCode == 'Authorised') {
                    $this->capturePayment($merchantAcountCode, $total_amount, $result->paymentResult->pspReference, $this->currency_code);
                }
            }
            $request['existing_pass_details'] = (!empty($pass_exist_or_not)) ? $pass_exist_or_not : array();
            $request['order_type'] = 'firebase_order';
            $request['reseller_from_token'] = $user_details['resellerId'];
            $logs['reseller_from_token'] = $request['reseller_from_token'];
            $logs['data_to_queue_MPOS_JSON_QUEUE_' . date('H:i:s')] = 'same as request from app end';
            $aws_message = base64_encode(gzcompress(json_encode($request))); // To compress heavy data data to pass inSQS  message.
            $response = array();

            // This Fn used to send notification with data on AWS panel.
            // To load Amazon sqs library.
            require_once 'aws-php-sdk/aws-autoloader.php';
            $this->load->library('Sns');
            $this->load->library('Sqs');
            $sns_object = new \Sns();
            $sqs_object = new \Sqs();
            $queueUrl = MPOS_JSON_QUEUE;
            if (LOCAL_ENVIRONMENT == 'Local') {
                local_queue_helper::local_queue($aws_message, 'MPOS_JSON_ARN');
            } else {
                $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                if ($MessageId) {
                    $sns_object->publish($MessageId . '#~#' . $queueUrl, MPOS_JSON_ARN);
                }
            }
            $response['reference_id'] = $visitor_group_no;
            $response['status'] = (int) 1;
            $response['message'] = 'Order placed successfully.';
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['checkout'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $spcl_ref = $request['hotel_id'];
        if ($activation_method == 19) { //scan exception
            $spcl_ref = 'scan_exception_order_ ' . $request['hotel_id'];
        }
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no'], $spcl_ref);
        if (!empty($internal_log)) {
            $internal_logs['checkout_' . date('H:i:s')] = $internal_log;
            $this->apilog_library->write_log($internal_logs, 'internalLog', $request['visitor_group_no'], $spcl_ref);
        }
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @name: Manual Payment
     * @purpoase: used to insert manual payment  data
     * @createdby : Supriya Saxena<supriya10.aipl@gmail.com> on 3rd september, 2019
     */
    function manual_payment() {
        global $MPOS_LOGS;
        global $internal_logs;
        $MPOS_LOGS['operation_id'] = 'PAYMENT';
        $MPOS_LOGS['external_reference'] = 'manual_payment';
        try {
            $request_array = $this->validate_api("manual_payment");
            if(empty($request_array['errors'])) {
                $request = $request_array['request'];
                $manual_payment_request = array();
                $manual_payment_request = $request;
                    //check if req is already in DB for requested visitor_group_no
                $pre_payment_requests = $this->firebase_model->query('select id, visitor_group_no, json from mpos_requests where visitor_group_no = ' . $manual_payment_request['visitor_group_no'], 1);
                if (!empty($pre_payment_requests)) {
                    $internal_log['pre_manual_payment_response_' . date('H:i:s')] = $manual_payment_request;
                    //If visitor_group_no already exist, return error res.
                    $response['reference_id'] = $request['visitor_group_no'];
                    $response['status'] = (int) 1;
                    $response['message'] = 'Payment already done.';
                    $logs['response'] = $response;
                    $MPOS_LOGS['manual_payment_response'] = $logs;
                    $internal_logs['manual_payment_response'] = $internal_log;
                    $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no']);
                    $this->apilog_library->write_log($internal_logs, 'internalLog', $request['visitor_group_no']);
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit;
                } else {
                    //if visitor_group_no not exist, add entry in DB and preocess req.
                    $mpos_message = base64_encode(gzcompress(json_encode($manual_payment_request))); // To compress heavy data.
                    $mpos_request = array();
                    $mpos_request['created_at'] = $manual_payment_request['date'];
                    $mpos_request['visitor_group_no'] = $manual_payment_request['visitor_group_no'];
                    $mpos_request['booking_date_time'] = $manual_payment_request['date'];
                    $mpos_request['json'] = $mpos_message;
                    $mpos_request['updated_at'] = $manual_payment_request['date'];
                    $this->db->insert('mpos_requests', $mpos_request);
                    $response = $this->mpos_model->process_manual_payment($manual_payment_request);
                }
            } else {
                $MPOS_LOGS['request'] = $request_array['request'];
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = $this->exception_handler->error_400();
            }              
           
        } catch (\Exception $e) {
            header('HTTP/1.0 500 Internal Server Error');
            $response['message'] = 'An error occurred that is unexpected.';
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no']);
        if (!empty($internal_logs)) {
            $this->apilog_library->write_log($internal_logs, 'internalLog', $request['visitor_group_no']);
        }
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

     /**
     * @name: Reserve
     * @purpoase: used to block quantity when added to cart
     * @createdby : Supriya Saxena<supriya10.aipl@gmail.com> on 3rd september, 2019
     */
    function reserve() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'SHOP';
        $MPOS_LOGS['external_reference'] = 'reserve';
        try {
            $request_array = $this->validate_api("reserve");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $hotel_id = $validate_token['user_details']['distributorId'];
            $release_requests = $request['release'];
            $block_requests = $request['block'];
            $channel_type = $request['channel_type'];
             //validate request params
            $new_validate_response = array();
            if(!empty($release_requests)) {
                foreach($release_requests as $release_request) {
                    $validate_response = $this->authorization->validate_request_params($release_request, [                                                     
                        'selected_date'         => 'date',                                                                                                                                              
                        'from_time'             => 'time',                                                                       
                        'to_time'               => 'time',                                                                       
                        'shared_capacity_id'    => 'numeric',                                                                                                                                              
                        'ticket_id'             => 'numeric',                                                                                                                                               
                        'own_capacity_id'       => 'numeric',                                                                                                                                            
                        'booking_id'            => 'numeric',
                        'quantity'              => 'numeric' ,
                        'museum_id'             => 'numeric'
                    ]); 
                    if(!empty($validate_response)) {
                        array_push($new_validate_response, $validate_response);
                    }
                }
            }
            if(!empty($block_requests)) {
                foreach($block_requests as $block_request) {
                    $validate_response = $this->authorization->validate_request_params($block_request, [                                                     
                        'selected_date'         => 'date',                                                                                                                                              
                        'from_time'             => 'time',                                                                       
                        'to_time'               => 'time',                                                                       
                        'shared_capacity_id'    => 'numeric',                                                                                                                                               
                        'ticket_id'             => 'numeric',                                                                                                                                               
                        'own_capacity_id'       => 'numeric',                                                                                                                                               
                        'booking_id'            => 'numeric',                                                                                                                                               
                        'quantity'              => 'numeric' ,
                        'museum_id'             => 'numeric'                                                                                                                                
                    ]); 
                    if(!empty($validate_response)) {
                        array_push($new_validate_response, $validate_response);
                    }
                }
            }
            
            if(!empty($new_validate_response)) {
                $response = $this->exception_handler->error_400();
            } else {
                if (!empty($release_requests) && !empty($block_requests)) {
                    $keys_found = 0;
                    foreach ($release_requests as $release_req) {
                        $release[$release_req['booking_id'] . "_" . $release_req['selected_date'] . "_" . $release_req['from_time'] . "_" . $release_req['to_time']] = $release_req;
                    }
                    foreach ($block_requests as $block_req) {
                        $blocks[$block_req['booking_id'] . "_" . $block_req['selected_date'] . "_" . $block_req['from_time'] . "_" . $block_req['to_time']] = $block_req;
                    }
                    foreach ($block_requests as $block_req) {
                        $block_key = $block_req['booking_id'] . "_" . $block_req['selected_date'] . "_" . $block_req['from_time'] . "_" . $block_req['to_time'];
                        if (in_array($block_key, array_keys($release))) {
                            $keys_found = 1;
                            if ($release[$block_key]['quantity'] > $block_req['quantity']) { //release case
                                $release[$block_key]['quantity'] = $release[$block_key]['quantity'] - $block_req['quantity'];
                                $final_releases[] = $release[$block_key];
                            } else if ($release[$block_key]['quantity'] < $block_req['quantity']) { //block case
                                $block_req['quantity'] = $block_req['quantity'] - $release[$block_key]['quantity'];
                                $final_blocks[] = $block_req;
                            }
                        } else {
                            $final_blocks[] = $block_req;
                        }
                    }
                    if ($keys_found == 0) {
                        $final_releases = $release_requests;
                    }
                } else {
                    $final_releases = $release_requests;
                    $final_blocks = $block_requests;
                }
            
                if (!empty($final_releases)) {
                    foreach ($final_releases as $release_request) {
                        $response_release_array[] = $this->firebase_model->release_ticket_capacity($release_request, $channel_type, $hotel_id);
                    }
                }
                if (!empty($final_blocks)) {
                    foreach ($final_blocks as $block_request) {
                        $response_block_array[] = $this->firebase_model->block_ticket_capacity($block_request, $channel_type, $hotel_id);
                    }
                }

                $response_arr['release'] = !empty($response_release_array) ? $response_release_array : array();
                $response_arr['block'] = !empty($response_block_array) ? $response_block_array : array();
                $response_arr['status'] = (int) 1;
                $response = $response_arr;
            }           
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /* #endregion Order Process  Module  : This module covers order process api's  */

    /**
     * @Name validate_pass
     * @Purpose To validate pass to activate on add to prioticket process from venue app.
     * @CreatedOn 28 june 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function validate_pass() {
        $MPOS_LOGS['operation_id'] = 'VENUE_APP';
        $MPOS_LOGS['external_reference'] = 'validate_pass';
        global $MPOS_LOGS;
        try {
            $request_array = $this->validate_api("validate_pass");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $hotel_id = $validate_token['user_details']['distributorId'];
            $pass_no = $request['passNo'];
            if ($pass_no == '') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter pass number.');
            } else {
                $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
                $timezone = $servicedata->timeZone;
                //Check if requested pass_no already exist in DB
                $order_details = $this->common_model->find('hotel_ticket_overview', array('select' => 'hotel_id, is_prioticket, parentPassNo, visitor_group_no, paymentMethod, activation_method, shopperReference, merchantReference, pspReference, roomNo, guest_names, receiptEmail, user_age', 'where' => 'passNo = "' . $pass_no . '" and hotel_checkout_status = "0" and (activation_method = "1" or activation_method = "3") and isPassActive = "1" and is_prioticket = "1" and (expectedCheckoutTime = "0" or (expectedCheckoutTime != "0" and expectedCheckoutTime > "' . (strtotime(date("m/d/Y H:i:s")) - $timezone * 3600) . '" ))'), 'array', "1");
                $logs['hotel_ticket_overview_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
                $internal_log['hotel_ticket_overview_response_' . date('H:i:s')] = $order_details;
                if (empty($order_details) || $order_details[0]['hotel_id'] != $hotel_id) {
                    //If the pass not exist , return error response
                    $response = $this->exception_handler->show_error(0, 'INVALID_PASS', 'The provided pass not valid.');
                } else {
                    //If the pass exist , return required vales in res.
                    $response['is_prioticket'] = $order_details[0]['is_prioticket'];
                    $response['parent_pass_no'] = $order_details[0]['parentPassNo'];
                    $response['visitor_group_no'] = $order_details[0]['visitor_group_no'];
                    $response['payment_method'] = $order_details[0]['paymentMethod'];
                    $response['activation_method'] = $order_details[0]['activation_method'];
                    $response['shopperReference'] = $order_details[0]['shopperReference'];
                    $response['merchantReference'] = $order_details[0]['merchantReference'];
                    $response['merchantAccountCode'] = MERCHANTCODE;
                    $response['shopperEmail'] = PRIOPASS_NO_REPLY_EMAIL;
                    $response['pspReference'] = $order_details[0]['pspReference'];
                    $response['payment_details']['reference'] = $order_details[0]['roomNo'];
                    $response['payment_details']['name'] = $order_details[0]['guest_names'];
                    $response['payment_details']['email'] = ($order_details[0]['receiptEmail'] != '') ? $order_details[0]['receiptEmail'] : '';
                    $response['ages'][] = $order_details[0]['user_age'];
                    $response['passes'][] = substr($order_details[0]['parentPassNo'], -PASSLENGTH);
                }
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['validate_pass'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }
    
    /**
     * @Name authorise_creditcard
     * @Purpose Authorise Credit card request from Venue app and deduct payment from card if details are valid.
     * @CreatedOn 27 june 2017
     * @CreatedBy Karan <karan.intersoft@gmail.com>
     * @ModifiedBy komal <komalgarg.intersoft@gmail.com>
     */
    function authorise_creditcard() {
        $MPOS_LOGS['operation_id'] = 'PAYMENT';
        $MPOS_LOGS['external_reference'] = 'authorise_creditcard';
        global $MPOS_LOGS;
        try {
            $request_array = $this->validate_api("authorise_creditcard");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $hotel_id = $validate_token['user_details']['distributorId'];
            $company_details = $this->common_model->find('qr_codes', array('select' => 'is_credit_card_fee_charged_to_guest, initial_payment_charged_to_guest, service_cost', 'where' => 'cod_id = "' . $hotel_id . '"'));
            $logs['qr_codes_query_' . date('H:i:s')] = str_replace('\n', '', $this->primarydb->db->last_query());
            $internal_log['qr_codes_response_' . date('H:i:s')] = $company_details;

            $shopperRef = $request['shopperReference'];
            $merchantAcountCode = $request['merchantAccount'];
            $merchantRef = $request['merchantReference'];
            $shopperEmail = strip_tags($request['shopperEmail']);
            $total_amount = strip_tags($request['total_amount']);

            if ($total_amount > 0) {
                $is_credit_card_fee_charged_to_guest = $company_details[0]['is_credit_card_fee_charged_to_guest'];
                $initial_payment_charged_to_guest = $company_details[0]['initial_payment_charged_to_guest'];
                $service_gross_price = $company_details[0]['service_cost'];

                if ($is_credit_card_fee_charged_to_guest == 1) { // If cc cost is charged from Guest       
                    $taxarray = $this->common_model->getPrepaidTax(); // Get credit card tax amounts for POS activations
                    if ($taxarray) {
                        if ($taxarray->calculated_on == '0') { // If caculation is per transaction
                            /*  Add fix and variable tax, and tax on cc cost to the total amount */
                            $ticketAmtarray = $this->common_model->addFixedVariableTaxToAmount($total_amount, $taxarray->fixed_amount, $taxarray->variable_amount, $taxarray->tax_value);
                            $amount = $ticketAmtarray['ticketAmt'];
                        } else { /* Per ticket calculation */
                            $mainamountwithtax = 0;
                            /* Loop for all the tickets to apply cc cost per ticket   */
                            foreach ($request['prices'] as $perticketamount) {
                                if ($perticketamount > 0) {
                                    /* Add fix and variable tax, and tax on cc cost to ticket amount */
                                    $ticketAmtarray = $this->common_model->addFixedVariableTaxToAmount($perticketamount, $taxarray->fixed_amount, $taxarray->variable_amount, $taxarray->tax_value);
                                    $mainamountwithtax = $mainamountwithtax + $ticketAmtarray['ticketAmt'];
                                }
                            }
                            /*  If there is service cost per transaction */
                            if ($service_gross_price > 0) {
                                /* Add only varible tax cost */
                                $servicewithtax = ($service_gross_price * ($taxarray->variable_amount / 100));
                                $servicewithtax = round($servicewithtax, 2);
                                $totalservicewithtax = $servicewithtax + (($servicewithtax * $taxarray->tax_value) / 100);
                                $totalticketAmt = $service_gross_price + round($totalservicewithtax, 2);
                                $mainamountwithtax = $mainamountwithtax + $totalticketAmt;
                            }
                            $amount = $mainamountwithtax;
                        }
                        if ($initial_payment_charged_to_guest == 1) { /* If initial payment is charged from guest */
                            $initial_price = (0.1 + ((0.1 * $taxarray->tax_value) / 100));
                            $initial_price = round($initial_price, 2);
                            $amount = $amount + $initial_price;
                        }
                    }
                } else {
                    $amount = $total_amount;
                }
                $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
                $currencyCode = $servicedata->currency;
                if ($currencyCode == '') {
                    $currencyCode = 'EUR';
                }
                $card_result = $this->authorise_card($merchantAcountCode, $currencyCode, ($amount * 100), $merchantRef, $shopperEmail, $shopperRef, $request['adyen_encrypted_data'], PAYMENT_MODE);
                if ($card_result->paymentResult->resultCode == 'Success' || $card_result->paymentResult->resultCode == 'Authorised') {
                    $creditcard_cost = $amount - $total_amount;
                    $response['status'] = 1;
                    $response['creditcard_cost'] = $creditcard_cost;
                    $response['pspReference'] = $card_result->paymentResult->pspReference;
                } else {
                    header('WWW-Authenticate: Basic realm="Authentication Required"');
                    header('HTTP/1.0 401 Unauthorized');
                    $response = $this->exception_handler->error_401();
                }
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['authorise_creditcard'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['shopperEmail']);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name refund_cc_payment
     * @Purpose Refund credit card payment in case user cancel the order
     * @CreatedOn 16 Sep 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function refund_cc_payment() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'PAYMENT';
        $MPOS_LOGS['external_reference'] = 'refund_cc_payment';
        try {
            $request_array = $this->validate_api("refund_cc_payment");
            $request = $request_array['request'];
            $logs['request_params_'.date("H:i:s")] = $request;
            $this->load->model('V1/hotel_model');

            $merchantAccount = $request['merchant_account'];
            $pspReference = strip_tags($request['psp_reference']);
            $modificationAmount = strip_tags($request['refund_amount']);
            // if amount to be refunded > 0, then call adyen api to refund
            if ($modificationAmount > 0) {
                $card_result = $this->refund($merchantAccount, ($modificationAmount * 100), $pspReference);
                if ($card_result == 'refund-received') {
                    $response['status'] = (int) 1;
                    $response['message'] = 'The amount has been refunded successfully.';
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', $card_result);
                }
            } else {
                header('WWW-Authenticate: Basic realm="Authentication Required"');
                header('HTTP/1.0 401 Unauthorized');
                $response = $this->exception_handler->show_error(0, 'AUTHORIZATION_FAILURE', 'Send valid amount to refund.');
            }

            header('Content-Type: application/json');
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['firebase_cc_refund'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['merchant_account']);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @Name refund_cc_authorized_payment
     * @Purpose Refund credit card payment in case user cancel the order before confirmation
     * @CreatedOn 18 Sep 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     */
    function refund_cc_authorized_payment() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'PAYMENT';
        $MPOS_LOGS['external_reference'] = 'refund_cc_authorized_payment';
        try {
            $request_array = $this->validate_api("refund_cc_authorized_payment");
            $request = $request_array['request'];
            $logs['request_params_'.date("H:i:s")] = $request;
            $this->load->model('V1/hotel_model');
            $merchantAccount = $request['merchant_account'];
            $pspReference = strip_tags($request['psp_reference']);
            //To refund authorized amount, call adyen api
            if (isset($pspReference) && isset($merchantAccount)) {
                $card_result = $this->cancel($merchantAccount, $pspReference);
                if ($card_result == 'cancel-received') {
                    $response['status'] = (int) 1;
                    $response['message'] = 'The amount has been cancelled successfully.';
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', $card_result);
                }
            } else {
                header('WWW-Authenticate: Basic realm="Authentication Required"');
                $response = $this->exception_handler->error_401();
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['refund_cc_authorized_payment'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['merchant_account']);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name third_party_tickets_availability
     * @Purpose To get the availability for third party tickets 
     * @CreatedOn 2 Sep 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function third_party_tickets_availability() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'THIRD_PARTY';
        $MPOS_LOGS['external_reference'] = 'availability_check';
        try {
            $request_array = $this->validate_api("third_party_tickets_availability");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $this->load->model('V1/third_party_api_model');
            $hotel_id = $validate_token['user_details']['distributorId'];
            $third_party = $request['third_party_details']['third_party'];
            if ($request['ticket_id'] == '') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter ticket id.');
            } else if ($request['selected_date'] == '' || $request['selected_date'] < gmdate("Y-m-d")) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter selected date.');
            } else if (empty($request['third_party_details'])) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please send third party details');
            } else {
                $availability_info = array(
                    'ticket_id' => $request['ticket_id'],
                    'third_party_details' => $request['third_party_details'],
                    'hotel_id' => $hotel_id,
                    'selected_date' => $request['selected_date'],
                );
                $logs['availability_info_' . date('H:i:s')] = $availability_info;
                if ($third_party == 1) { //GT booking
                    $response = $this->third_party_api_model->get_gt_availability($availability_info);
                } else if ($third_party == 2) { //Iticket booking
                    $response = $this->third_party_api_model->get_iticket_availability($availability_info);
                } else if ($third_party == 20) { //Boverties booking
                    $response = $this->third_party_api_model->get_boverties_availability($availability_info);
                } else if ($third_party == 17) { //enviso booking (Rizksmuseum Tickets)
                    $response = $this->third_party_api_model->get_enviso_availability($availability_info);
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter valid third party api.');
                }
                $MPOS_LOGS['third_party_tickets_availability'] = array_merge($MPOS_LOGS['third_party_tickets_availability'], $logs);
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $hotel_id . '_' . $request['ticket_id']);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name reserve_third_party_tickets
     * @Purpose Reserve third party tickets
     * @CreatedOn 23 Aug 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function reserve_third_party_tickets() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'THIRD_PARTY';
        $MPOS_LOGS['external_reference'] = 'reservation';
        try {
            $request_array = $this->validate_api("reserve_third_party_tickets");
            $request = $request_array['request'];
            $this->load->model('V1/third_party_api_model');
            $flag = 0;
            $invalid_category = 0;
            $categories = ['adult', 'child', 'infant'];
            //check all requested parameters if it contains valid values
            foreach ($request['items'] as $ticketcount) {
                if ($ticketcount['count'] == 0) {
                    $flag = 1;
                }
                if (!in_array($ticketcount['category'], $categories)) {
                    $invalid_category = 1;
                }
            }
            $third_party = $request['third_party_details']['third_party'];
            if ($request['ticket_id'] == '') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter ticket id.');
            } else if ($request['selected_date'] == '' || $request['selected_date'] < gmdate("Y-m-d")) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter valid selected date.');
            } else if (empty($request['third_party_details'])) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please send third party details.');
            } else if ($flag != 0) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'ticket count should be greater then 0');
            } else if (!preg_match('#^([01]?[0-9]|2[0-3]):[0-5][0-9]?$#', $request['selected_timeslot'])) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter timeslot in proper format');
            } else if ($invalid_category != 0) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Invalid ticket type');
            } else {
                $booking_details = array(
                    'ticket_id' => $request['ticket_id'],
                    'selected_date' => $request['selected_date'],
                    'selected_timeslot' => $request['selected_timeslot'],
                    'third_party_details' => $request['third_party_details'],
                    'contact' => $request['contact'],
                    'items' => $request['items'],
                );
                foreach ($request['items'] as $row) {
                    $booking_details[$row['category']] = $row['count'];
                }

                $logs['booking_details_' . date('H:i:s')] = $booking_details;
                if ($third_party == 2) {
                    /* Itickets booking */
                    if (!empty($request['cancel_booking_ids'])) {
                        //if in any caes, there is error in any booking, then cancel, confirmed bookiings
                        $cancel_booking_details = array(
                            'ticket_id' => $request['ticket_id'],
                            'booking_ids' => $request['cancel_booking_ids']
                        );
                        $response_data = $this->third_party_api_model->cancel_iticket_booking_request($cancel_booking_details);
                    }
                    $response = $this->third_party_api_model->send_iticket_booking_request($booking_details);
                } else if ($third_party == 1) {
                    /* GT Tickets booking */
                    if (!empty($request['cancel_booking_ids'])) {
                        //if in any caes, there is error in any booking, then cancel, confirmed bookiings
                        $cancel_booking_details = array(
                            'ticket_id' => $request['ticket_id'],
                            'booking_ids' => $request['cancel_booking_ids']
                        );
                        $response_data = $this->third_party_api_model->cancel_gt_ticket_booking_request($cancel_booking_details);
                    }
                    $response_data = $this->third_party_api_model->send_gt_ticket_booking_request($booking_details);
                    if ($response_data['reservationId'] > 0) {
                        $response['status'] = (int) 1;
                        $response['booking_id'] = $response_data['reservationId'];
                    } else if (!$response_data['success']) {
                        if ($response_data['errorMessage'] != '') {
                            $errormsg = $response_data['errorMessage'];
                        } else {
                            $errormsg = 'The reservation or booking call cannot be fulfilled because there is insufficient availability.';
                        }
                        $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', $errormsg);
                    } else {
                        $response = $this->exception_handler->show_error(0, 'NO_AVAILABILITY', 'The reservation or booking call cannot be fulfilled because there is insufficient availability.');
                    }
                } else if ($third_party == 20) {
                    /* Boverties reservation */
                    $booking_details['timeslot_id'] = $request['timeslot_id'];
                    $response = $this->third_party_api_model->send_boverties_booking_request($booking_details);
                } else if ($third_party == 17) {
                    /* enviso Tickets' bookings (Rizksmuseum Tickets) */
                    if (!empty($request['cancel_booking_ids'])) {
                        //if in any caes, there is error in any booking, then cancel all confirmed bookiings
                        $response_data = $this->third_party_api_model->cancel_enviso_booking_request();
                    }
                    $booking_details['timeslot_id'] = isset($request['timeslot_id']) ? $request['timeslot_id'] : 0;
                    $response = $this->third_party_api_model->send_enviso_booking_request($booking_details);
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter valid third party api');
                }
            }
            $MPOS_LOGS['reserve_third_party_tickets'] = array_merge($MPOS_LOGS['reserve_third_party_tickets'], $logs);
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['ticket_id']);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name confirm_third_party_tickets
     * @Purpose To confirm booking of third party tickets
     * @CreatedOn 23 Aug 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com>
     */
    function confirm_third_party_tickets() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'THIRD_PARTY';
        $MPOS_LOGS['external_reference'] = 'confirm';
        try {
            $request_array = $this->validate_api("confirm_third_party_tickets");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $logs['validate_token_gives_' . date('H:i:s')] = $validate_token;
            $this->load->model('V1/third_party_api_model');
            if (!empty($request['bookings'])) {
                $confirmed_bookings = array();
                //check all requested parameters if it contains valid values
                foreach ($request['bookings'] as $booking) {
                    $flag = 0;
                    $invalid_category = 0;
                    $categories = ['adult', 'child', 'infant'];
                    foreach ($booking['items'] as $ticketcount) {
                        if ($ticketcount['count'] == 0) {
                            $flag = 1; // ticket count is less than 1
                        }
                        if (!in_array($ticketcount['category'], $categories)) {
                            $invalid_category = 1;
                        }
                    }
                    $third_party = $booking['third_party_details']['third_party'];
                    if ($booking['ticket_id'] == '') {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter ticket id.');
                    } else if ($booking['selected_date'] == '' || $booking['selected_date'] < gmdate("Y-m-d")) {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter valid selected date.');
                    } else if (empty($booking['third_party_details'])) {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please send third party details');
                    } else if (!preg_match('#^([01]?[0-9]|2[0-3]):[0-5][0-9]?$#', $booking['selected_timeslot'])) {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter timeslot in proper format');
                    } else if ($booking['booking_id'] == '') {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Booking id is blank');
                    } else if ($flag != 0) {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'ticket count should be greater then 0');
                    } else if ($invalid_category != 0) {
                        $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Invalid ticket type.');
                    } else {
                        $booking_details = array(
                            'ticket_id' => $booking['ticket_id'],
                            'selected_date' => $booking['selected_date'],
                            'selected_timeslot' => $booking['selected_timeslot'],
                            'third_party_details' => $booking['third_party_details'],
                            'booking_id' => $booking['booking_id'],
                            'order_id' => $booking['order_id'],
                            'confirmed_bookings' => $confirmed_bookings,
                            'prices' => isset($booking['prices']) ? $booking['prices'] : array()
                        );
                        foreach ($booking['items'] as $row) {
                            $booking_details['ticket_types'][$row['category']]['count'] = $row['count'];
                        }
                        if ($third_party == 2) {
                            /* Itickets booking */
                            $booking_details['third_party_details']['combi_pass'] = 1; //itickets are always combi on
                            $response_array = $this->third_party_api_model->send_iticket_confirmation_request($booking_details);
                            if ($response_array['status'] == 1) {
                                foreach ($response_array['ticket_data'] as $type) {
                                    if ($type['ticket_type'] == "CHILD") {
                                        $child_pass_nos[] = $type['ticket_code'];
                                    }
                                    if ($type['ticket_type'] == "ADULT") {
                                        $adult_pass_nos[] = $type['ticket_code'];
                                    }
                                    if ($type['ticket_type'] == "INFANT") {
                                        $infant_pass_nos[] = $type['ticket_code'];
                                    }
                                }
                                $response_array['passes']["adult"] = isset($adult_pass_nos) ? $adult_pass_nos : array();
                                $response_array['passes']["child"] = isset($child_pass_nos) ? $child_pass_nos : array();
                                $response_array['passes']["infant"] = isset($infant_pass_nos) ? $infant_pass_nos : array();
                                $bookings = array(
                                    'booking_id' => $booking['booking_id'],
                                    'reference_id' => isset($response_array['reference_id']) ? (string) $response_array['reference_id'] : (string) $booking['booking_id'],
                                    'passes' => $response_array['passes'],
                                    'combi_pass' => isset($response_array['combi_pass']) ? $response_array['combi_pass'] : ""
                                );
                                $response['status'] = 1;
                                $response['bookings'][] = $bookings;
                                $booking_ids[] = $booking['booking_id'];
                                $confirmed_bookings[] = array(
                                    'third_party' => 1,
                                    'ticket_id' => $booking['ticket_id'],
                                    'booking_ids' => $booking_ids
                                );
                            } else {
                                /* If failed to confirm all bookings, then cancel all */
                                if (!empty($confirmed_bookings)) {
                                    foreach ($confirmed_bookings as $row) {
                                        if ($row['third_party'] == 1) {
                                            $response = $this->third_party_api_model->cancel_iticket_booking_request($row);
                                        } else if ($row['third_party'] == 4) {
                                            $response = $this->third_party_api_model->cancel_gt_ticket_booking_request($row);
                                        }
                                    }
                                    $response['status'] = 0;
                                    $response['message'] = 'Unable to confirm all bookings. Cancelled';
                                } else {
                                    $response['status'] = 0;
                                    $response['message'] = 'Unable to confirm all bookings. Cancelled';
                                }
                            }
                        } else if ($third_party == 1) {
                            /* GT Tickets booking */
                            unset($booking_details['ticket_types']);
                            $booking_details['items'] = $booking['items'];
                            $response_array = $this->third_party_api_model->send_gt_ticket_confirmation_request($booking_details);
                            if ($response_array['status'] == 1) {
                                $bookings = array(
                                    'booking_id' => $booking['booking_id'],
                                    'reference_id' => isset($response_array['reference_id']) ? (string) $response_array['reference_id'] : (string) $booking['booking_id'],
                                    'passes' => $response_array['barcodes'],
                                    'combi_pass' => isset($response_array['combi_pass']) ? $response_array['combi_pass'] : ""
                                );
                                $response['status'] = 1;
                                $response['bookings'][] = $bookings;
                                $booking_ids[] = $booking['booking_id'];
                                $confirmed_bookings[] = array(
                                    'third_party' => 4,
                                    'ticket_id' => $booking['ticket_id'],
                                    'booking_ids' => $booking_ids
                                );
                            } else {
                                /* If failed to confirm all bookings, then cancel all */
                                if (!empty($confirmed_bookings)) {
                                    foreach ($confirmed_bookings as $row) {
                                        if ($row['third_party'] == 1) {
                                            $response = $this->third_party_api_model->cancel_iticket_booking_request($row);
                                        } else if ($row['third_party'] == 4) {
                                            $response = $this->third_party_api_model->cancel_gt_ticket_booking_request($row);
                                        }
                                    }
                                    $response['status'] = 0;
                                    $response['message'] = 'Unable to confirm all bookings. Cancelled';
                                } else {
                                    $response['status'] = 0;
                                    $response['message'] = 'Unable to confirm all bookings. Cancelled';
                                }
                            }
                        } elseif ($third_party == 20) {
                            /* Boverties booking */
                            $booking_details = array();
                            $booking_details['booking_id'] = $booking['booking_id'];
                            $booking_details['items'] = $booking['items'];
                            $response_array = $this->third_party_api_model->send_boverties_confirmation_request($booking_details);
                            if ($response_array['status'] == 1) {
                                $bookings = array(
                                    'booking_id' => $booking['booking_id'],
                                    'reference_id' => isset($response_array['reference_id']) ? (string) $response_array['reference_id'] : (string) $booking['booking_id'],
                                    'passes' => $response_array['barcodes'],
                                    'combi_pass' => isset($response_array['combi_pass']) ? $response_array['combi_pass'] : ""
                                );
                                $response['status'] = 1;
                                $response['bookings'][] = $bookings;
                                $booking_ids[] = $booking['booking_id'];
                            } else {
                                $response['status'] = 0;
                                $response['message'] = 'Unable to confirm booking';
                            }
                        } else if ($third_party == 17) {
                            /* Enviso (Rizksmuseum Tickets) booking */
                            $booking_details = array();
                            $booking_details['booking_id'] = $booking['booking_id'];
                            $booking_details['items'] = $booking['items'];
                            $booking_details['third_party_details'] = $booking['third_party_details'];
                            $response_array = $this->third_party_api_model->send_enviso_confirmation_request($booking_details);
                            if ($response_array['status'] == 1) {
                                $bookings = array(
                                    'booking_id' => $booking['booking_id'],
                                    'reference_id' => isset($response_array['reference_id']) ? (string) $response_array['reference_id'] : (string) $booking['booking_id'],
                                    'passes' => $response_array['barcodes'],
                                    'combi_pass' => isset($response_array['combi_pass']) ? $response_array['combi_pass'] : ""
                                );
                                $response['status'] = 1;
                                $response['bookings'][] = $bookings;
                                $booking_ids[] = $response_array['booking_id'];
                            } else {
                                $response['status'] = 0;
                                $response['message'] = 'Unable to confirm booking';
                            }
                        } else {
                            $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter valid third party api.');
                        }
                        $MPOS_LOGS['confirm_third_party_tickets'] = array_merge($MPOS_LOGS['confirm_third_party_tickets'], $logs);
                    }
                }
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog');
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name cancel_third_party_tickets
     * @Purpose this api will further send request to specific apis and will cancel the tickets
     * @CreatedOn 23 Aug 2017
     * @CreatedBy komal <komalgarg.intersoft@gmail.com>
     * @updatedOn 9 jan 2019
     * @UpdatedBy Vaishali Raheja <vaishali.intersoft@gmail.com> 
     */
    function cancel_third_party_tickets() {
        global $MPOS_LOGS;
        $MPOS_LOGS['operation_id'] = 'THIRD_PARTY';
        $MPOS_LOGS['external_reference'] = 'cancel';
        try {
            $request_array = $this->validate_api("cancel_third_party_tickets");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $logs['validate_token_gives_' . date('H:i:s')] = $validate_token;
            $third_party = $request['third_party_details']['third_party'];
            if ($request['ticket_id'] == '') {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please enter ticket id.');
            } else if (empty($request['third_party_details'])) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Please send third party details');
            } else if (empty($request['booking_ids'])) {
                $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Booking id is blank');
            } else {
                $booking_details = array(
                    'ticket_id' => $request['ticket_id'],
                    'booking_ids' => $request['booking_ids'],
                    'third_party_details' => isset($request['third_party_details']) ? $request['third_party_details'] : array()
                );
                if ($third_party == 2) {
                    /* Itickets booking */
                    $response_data = $this->third_party_api_model->cancel_iticket_booking_request($booking_details);
                    if (!empty($response_data) && isset($response_data['status']) && $response_data['status'] == 0) {
                        $response['status'] = (int) 0;
                    } else {
                        $response['status'] = (int) 1;
                    }
                } else if ($third_party == 1) {
                    /* GT Tickets booking */
                    $response_data = $this->third_party_api_model->cancel_gt_ticket_booking_request($booking_details);
                    if ($response_data['success']) {
                        $response['status'] = (int) 1;
                    } else {
                        $response['status'] = (int) 0;
                    }
                } else if ($third_party == 20) {
                    /* cancel boverties booking */
                    $response_data = $this->third_party_api_model->cancel_boverties_booking_request();
                    if (!empty($response_data) && isset($response_data['status']) && $response_data['status'] == 0) {
                        $response['status'] = (int) 0;
                    } else {
                        $response['status'] = (int) 1;
                    }
                } else if ($third_party == 17) {
                    /* cancel enviso booking */
                    $response_data = $this->third_party_api_model->cancel_enviso_booking_request();
                    if (!empty($response_data) && isset($response_data['status']) && $response_data['status'] == 0) {
                        $response['status'] = (int) 0;
                    } else {
                        $response['status'] = (int) 1;
                    }
                } else {
                    $response = $this->exception_handler->show_error(0, 'VALIDATION_FAILURE', 'Enter valid third party api.');
                }
                $MPOS_LOGS['cancel_third_party_tickets'] = array_merge($MPOS_LOGS['cancel_third_party_tickets'], $logs);
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = $e->getMessage();
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['ticket_id']);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    function access_log_files() {
        try {
            if (isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] == 'test-firebase' && $_SERVER['PHP_AUTH_PW'] == 'secret123456') {
                $log_file = $_REQUEST['log_name'];
                $file = (FCPATH . "system/application/storage/logs/" . $log_file . ".php");
                echo $file;
                header("Content-Type:application/octet-stream");
                header("Accept-Ranges: bytes");
                header("Content-Length: " . filesize($file));
                header("Content-Disposition: attachment; filename=" . $file);
                readfile($file);
            } else {
                $response = $this->exception_handler->error_401();
            }
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
        }
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * @Name     : get_user_from_user_vs_machineids().
     * @Purpose  : It fetches users data using email_id by using a join between users and user_vs_machineids table
     * @Called   : called from signin api.
     * @parameters : $email_id => users unique email_id
     */
    function get_user_from_user_vs_machineids($machineId, $user_id = '0') {
        if ($user_id == '0') {
            if ($machineId != '') {
                $qry = 'select distinct ae.*, usermachine.machine_id, users.id as uid, users.uname, usermachine.token, users.password, users.user_type, users.fname, users.lname, users.is_allow_postpaid, users.is_fast_scan, users.cmntAuthor as author, users.country, users.timeZone, users.numberformat, users.language, users.currency, users.cashier_location, users.cashier_city from activated_email ae join users on (ae.user_id = users.id) join user_vs_machineids usermachine on (ae.user_id = usermachine.user_id) and usermachine.machine_id = "' . $machineId . '" where usermachine.machine_id = "' . $machineId . '" and users.deleted = "0" order by users.id desc';
                $res = $this->primarydb->db->query($qry);
                if ($res->num_rows() > 0) {
                    return $res->row();
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            if ($user_id != '') {
                $qry = 'select users.id as uid, users.uname, users.password, users.user_type, users.fname, users.lname, users.is_allow_postpaid, users.is_fast_scan, users.timeZone, users.numberformat, users.language, users.currency, users.cashier_location, users.cashier_city from users  where id = "' . $user_id . '" and users.deleted = "0" order by users.id desc';
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
    }

    /* Function to Authorise the Credit card from APP and to call webservice to Adyen */

    function authorise_card($merchantAccount, $currency, $amount, $reference, $shopper_email, $shopper_reference, $encrypted_data, $payment_mode = 'test') {
        $deatils = array();
        $deatils['merchantAccount'] = $merchantAccount;
        $deatils['currency'] = $currency;
        $deatils['amount'] = $amount;
        $deatils['reference'] = $reference;
        $deatils['shopperEmail'] = $shopper_email;
        $deatils['shopperReference'] = $shopper_reference;
        $deatils['card.encrypted.json'] = $encrypted_data;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $data['shopperIP'] = $ip;

        $oREC = new \CI_AdyenRecurringPayment(false);
        return $oREC->authoriseViaSoap($deatils, $payment_mode);
    }

    function refund($merchantAccount = '', $modificationAmount = '', $pspReference = '') {
        $oREC = new \CI_AdyenRecurringPayment(false);
        $oREC->startSOAP();
        return $oREC->refund($merchantAccount, $modificationAmount, $pspReference);
    }

    function cancel($merchantAccount = '', $pspReference = '') {
        $oREC = new \CI_AdyenRecurringPayment(false);
        $oREC->startSOAP();
        return $oREC->cancel($merchantAccount, $pspReference);
    }

    function capturePayment($merchantAccount = '', $modificationAmount = '', $pspReference = '', $currency = '') {
        $oREC = new \CI_AdyenRecurringPayment(false);
        $modificationAmount = $modificationAmount * 100; // modificationAmount  is the total amount deducted from card
        $oREC->startSOAP("Payment");
        $oREC->capture($merchantAccount, $modificationAmount, $pspReference, $currency);
        return $oREC->response->captureResult->response;
    }

    function deduct_from_card($merchantAccount, $currency, $amount, $reference, $shopper_email, $shopper_reference, $payment_mode) {
        $deatils = array();
        $deatils['merchantAccount'] = $merchantAccount;
        $deatils['currency'] = $currency;
        $deatils['amount'] = (100 * $amount);
        $deatils['reference'] = $reference;
        $deatils['shopperEmail'] = $shopper_email;
        $deatils['shopperReference'] = $shopper_reference;

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $data['shopperIP'] = $ip;

        $oREC = new \CI_AdyenRecurringPayment(false);
        if ($merchantAccount == 'AdamLookout' || $merchantAccount == '360Amsterdam') {
            $response = $oREC->authPayment($deatils, $payment_mode, PRIOTICKET_SOAP_USER, PRIOTICKET_SOAP_PASSWORD);
        } else {
            $response = $oREC->authPayment($deatils, $payment_mode, SOAP_ADYEN_USER, SOAP_ADYEN_PASSWORD);
        }
        return $response;
    }

    /**
     * @Name : update_shift_report()
     * @Purpose : To update shift report for offline firebase management.
     * @Call from : Called by app when user get online after getting internet connection.
     * @Functionality : It will sync up the shift report firebase node corresponding to user/location_id/shift_id on firebase.
     * @Created : Karan <karan.intersoft@gmail.com> on 12 March 2018
     */
    function update_shift_report() {
        global $MPOS_LOGS;
        try {
            $request_array = $this->validate_api("update_shift_report");
            $request = $request_array['request'];
            $headers = $request_array['headers'];
            $validate_token = $request_array['validate_token'];
            if ($request['path'] == '' || $request['search_key'] == '' || $request['search_value'] == '' || $request['amount'] === '') {
                $response = $this->exception_handler->error_400();
            } else if ($request['amount'] > 0) {
                $headers = $this->firebase_model->all_headers(array(
                    'hotel_id' => $validate_token['user_details']['distributorId'],
                    'action' => 'update_shift_report_from_mpos',
                    'user_id' => $validate_token['user_details']['cashierId']
                ));
                $MPOS_LOGS['DB'][] = 'FIREBASE';
                if ($request['search_value'] == 8 || $request['search_value'] == 9) {
                    if ($request['index'] != '') { //existing payment type for manual or split
                        $node = $request['path'] . '/' . $request['index'] . '/payments';
                        $logs['existing_node_is'] = $node;
                        $i = 0;
                        foreach ($request['payments'] as $payments) {
                            $this->curl->requestASYNC('FIREBASE', '/update_values_in_array', array(
                                'type' => 'POST',
                                'additional_headers' => $headers,
                                'body' => array(
                                    "node" => $node . '/' . $payments['paymentType'],
                                    'details' => (object) array(),
                                    'update_key' => ($request['is_refunded']) ? 'refundedAmount' : 'amount',
                                    'update_value' => $payments['amount']
                                )
                            ));
                            $i++;
                        }
                    } else { //create a new node for manual or split payment
                        $payment_details["1"] = array("amount" => 0, "commission" => 0, "paymentType" => 1, "refundedAmount" => 0, "webEndrefundedAmount" => 0);
                        $payment_details["2"] = array("amount" => 0, "commission" => 0, "paymentType" => 2, "refundedAmount" => 0, "webEndrefundedAmount" => 0);
                        if ($request['search_value'] == 9) { // split payment only
                            $payment_details["10"] = array("amount" => 0, "commission" => 0, "paymentType" => 10, "refundedAmount" => 0, "webEndrefundedAmount" => 0);
                            $payment_details["6"] = array("amount" => 0, "commission" => 0, "paymentType" => 6, "refundedAmount" => 0, "webEndrefundedAmount" => 0);
                        }
                        foreach ($request['payments'] as $payments) {
                            $detail = array(
                                "amount" => ($request['is_refunded']) ? 0 : $payments['amount'],
                                "commission" => 0,
                                "paymentType" => isset($payments['paymentType']) ? (int) $payments['paymentType'] : (int) 1,
                                "refundedAmount" => ($request['is_refunded']) ? $payments['amount'] : 0,
                                "webEndrefundedAmount" => 0
                            );
                            $payment_details[$payments['paymentType']] = $detail;
                        }
                        $params = array(
                            'type' => 'POST',
                            'additional_headers' => $headers,
                            'body' => array(
                                "node" => $request['path'],
                                'search_key' => '',
                                'search_value' => '',
                                'details' => array(
                                    "paymentType" => $request['search_value'],
                                    "payments" => $payment_details
                                )
                            )
                        );
                        $logs['data_to_sync_details'] = $params['body'];
                        $this->curl->requestASYNC('FIREBASE', '/update_details_in_array', $params);
                    }
                } else {
                    $this->curl->requestASYNC('FIREBASE', '/update_details_in_shift_report', array(
                        'type' => 'POST',
                        'additional_headers' => $headers,
                        'body' => array(
                            'path' => isset($request['path']) ? $request['path'] : '',
                            'search_key' => isset($request['search_key']) ? $request['search_key'] : '',
                            'search_value' => isset($request['search_value']) ? (int) $request['search_value'] : (int) 1,
                            'amount' => isset($request['amount']) ? $request['amount'] : 0,
                            'is_refunded' => isset($request['is_refunded']) ? $request['is_refunded'] : false,
                        )
                    ));
                }
                $response['status'] = (int) 1;
            }

            $logs['response'] = $response;
        } catch (\Exception $e) {
            $response = $this->exception_handler->error_500();
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
        }
        $MPOS_LOGS['update_shift_report'] = $logs;
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $_SERVER['PHP_AUTH_USER']);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @Name : check_priopass()
     * @Purpose : To check if passes are valid to assign in priopass.
     * @Call from : Called by app when user scans passes and clicks on button to enter gusest details.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 12 june 2019
     */
    function check_priopass() {
        global $MPOS_LOGS;
        try {
            $request_array = $this->validate_api("check_priopass");
            $request = $request_array['request'];
            $validate_token = $request_array['validate_token'];
            $hotel_id = $validate_token['user_details']['distributorId'];
            $passes = $request['passes'];
            $i = 0;
            $chk_passes = $this->common_model->query('select visitor_group_no, passNo from prepaid_tickets where hotel_id = ' . $hotel_id . ' and passNo IN ("' . implode('", "', $passes) . '")', "1");
            $logs['check_for_passes_in_PT_' . date('H:i:s')] = $this->primarydb->db->last_query();
            $internal_log['pt_response_' . $i . '_' . date('H:i:s')] = $chk_passes;
            if (empty($chk_passes)) {
                $chk_pass = $this->common_model->query('select pass_no from mpos_assigned_passes where distributor_id = ' . $hotel_id . ' and pass_no IN ("' . implode('", "', $passes) . '") and active = "0"', "1");
                $logs['mpos_assigned_passes_check_' . date('H:i:s')] = $this->primarydb->db->last_query();
                $internal_log['mpos_assigned_passes_response_' . $i . '_' . date('H:i:s')] = $chk_pass;
                if (empty($chk_pass)) {
                    $invalid_passes[] = $passes;
                }
            } else {
                foreach ($chk_passes as $pass_detail) {
                    $pass_info[$pass_detail['passNo']] = $pass_detail['visitor_group_no'];
                }
                foreach ($passes as $pass) {
                    if (array_key_exists($pass, $pass_info)) {
                        $invalid_passes[] = $pass;
                    } else {
                        $check_mpos_assigned_pass[] = $pass;
                    }
                }
                if (!empty($check_mpos_assigned_pass)) {
                    $chk_pass = $this->common_model->query('select pass_no from mpos_assigned_passes where distributor_id = ' . $hotel_id . ' and pass_no IN ("' . implode('", "', $check_mpos_assigned_pass) . '") and active = "0"', "1");
                    $logs['mpos_assigned_passes_check_' . date('H:i:s')] = $this->primarydb->db->last_query();
                    $internal_log['mpos_assigned_passes_response_' . $i . '_' . date('H:i:s')] = $chk_pass;
                    foreach ($check_mpos_assigned_pass as $inactive_pass) {
                        if (!in_array($inactive_pass, $chk_pass)) {
                            $invalid_passes[] = $inactive_pass;
                        }
                    }
                }
            }
            if (isset($invalid_passes) && !empty($invalid_passes)) {
                $response = $this->exception_handler->show_error(0, "INVALID PASSES", "passes are not valid");
                $response['invalid_passes'] = $invalid_passes;
            } else {
                $response['status'] = 1;
            }
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['check_priopass'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['passes']);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    /**
     * @Name : activate_priopass()
     * @Purpose : To activate valid passes as priopass.
     * @Call from : Called by app when user clicks on botton after adding gusest details.
     * @Created : Vaishali Raheja <vaishali.intersoft@gmail.com> on 12 june 2019
     */
    function activate_priopass() {
        global $MPOS_LOGS;
        try {
            $sns_messages = array();
            $request_data = $this->validate_api("activate_priopass");
            if(empty($request_data['errors'])) {
                $request = $request_data['request'];
                $headers = $request_data['headers'];
                $validate_token = $request_data['validate_token'];
                $hotel_id = $validate_token['user_details']['distributorId'];
                $timezone = ltrim($headers['timezone'], 'GMT');
                // EOC to validate request param
            
                $with_pass = $request['with_pass'];
                $pass_details = $request['pass_details'];
                $guests = $request['guests'];
                $hotel_name = $request['hotel_name'];
                $room_no = (!empty($request['user_room_no'])) ? $request['user_room_no'] : $request['reference'];
                $user_id = $request['user_id'];
                $host_name = $request['host_name'];
                $distributor_type = $request['distributor_type'];
                $nights = $request['nights'];
                $payment_method_type = $request['payment_method_type'];
                $user_name = $request['user_name'];
                $receipt_email = isset($request['receipt_email']) ? $request['receipt_email'] : "";
                $visitor_group_no = $request['visitor_group_no'];
                $shopper_reference = isset($request['shopper_reference']) ? $request['shopper_reference'] : "";
                $merchant_account = isset($request['merchant_account']) ? $request['merchant_account'] : "";
                $merchant_reference = 'MPOS-' . $visitor_group_no;
                $pass_activation_via = $request['pass_activation_via'];
                $channel_type = $request['channel_type'];
                $shopper_email = strip_tags($request['shopper_email']);
            
                if ($with_pass == 0) {  //without pass activation
                    for ($p = 1; $p <= $guests; $p++) {
                        //generate random passes
                        $passes[] = 'P' . $this->common_model->get_sixteen_digit_pass_no();
                    }
                } else { //with pass activation
                    foreach ($pass_details as $pass) {
                        $passes[] = $pass['pass'];
                    }
                }
                $servicedata = $this->common_model->getGenericServiceValues(SERVICE_NAME);
                if ($payment_method_type == 1) { //card payment
                    if ($servicedata->currency == '') {
                        $servicedata->currency = 'EUR';
                    }
                    $card_result = $this->authorise_card($merchant_account, $servicedata->currency, 0, $merchant_reference, $shopper_email, $shopper_reference, $request['adyen_encrypted_data'], PAYMENT_MODE);
                    if ($card_result->paymentResult->resultCode != 'Authorised') {
                        header('WWW-Authenticate: Basic realm="Authentication Required"');
                        $response = $this->exception_handler->error_401(0, 'AUTHORIZATION_FAILURE', 'Unauthorized Card');
                        $logs['response'] = $response;
                        $MPOS_LOGS['activate_priopass'] = $logs;
                        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no']);
                        header('Content-Type: application/json');
                        header('Cache-control: no-store');
                        header('Pragma: no-cache');
                        echo json_encode($response);
                        exit;
                    }
                }
                /* Update pass details in hotel_ticket_overview */
                $prepaid_insertion_count = 0;
                $current_time = strtotime(gmdate('m/d/Y 23:59')) - $timezone * 3600;
                $i = 0;
                foreach ($passes as $row) {
                    $prepaid_insertion_count++;
                    if ($payment_method_type == 2) {
                        $payment_method_val = '3';
                    } else if ($payment_method_type == 1) {
                        $payment_method_val = '1';
                    } else {
                        $payment_method_val = '0';
                    }
                    $insertData = array(
                        'id' => $visitor_group_no . str_pad($prepaid_insertion_count, 3, "0", STR_PAD_LEFT),
                        'channel_id' => 0,
                        'hotel_id' => $hotel_id,
                        'hotel_name' => $hotel_name,
                        'product_type' => 0,
                        'isBillToHotel' => ($payment_method_type == 2) ? '1' : '0',
                        'visitor_group_no' => $visitor_group_no,
                        'visitor_group_no_old' => $visitor_group_no,
                        'parentPassNo' => $row,
                        'passNo' => $row,
                        'roomNo' => $room_no,
                        'nights' => $nights,
                        'user_age' => str_replace('+', '', $pass_details[$i]['age']),
                        'user_name' => $user_name,
                        'user_email' => $receipt_email,
                        'createdOn' => strtotime(gmdate('m/d/Y H:i:s')),
                        'merchantAccountCode' => $merchant_account,
                        'eventCode' => isset($card_result->paymentResult->resultCode) ? $card_result->paymentResult->resultCode : '',
                        'merchantReference' => ($payment_method_type == 1) ? $merchant_reference : "",
                        'shopperLocale' => '',
                        'paymentMethod' => ($payment_method_type == 1) ? 'visa' : '', //default payment method is hotel bill
                        'pspReference' => isset($card_result->paymentResult->pspReference) ? $card_result->paymentResult->pspReference : '',
                        'shopperReference' => $shopper_reference,
                        'shopperEmail' => $shopper_email,
                        'success' => isset($card_result->paymentResult->resultCode) ? $card_result->paymentResult->resultCode : '',
                        'paymentStatus' => 1,
                        'updatedBy' => $user_id,
                        'updatedOn' => strtotime(gmdate('m/d/Y H:i:s')),
                        'expectedCheckoutTime' => ($nights != '') ? strtotime("+ " . $nights . " days", $current_time) : '',
                        'receiptEmail' => $receipt_email,
                        'is_prioticket' => '1',
                        'guest_names' => $user_name,
                        'guest_emails' => $receipt_email,
                        'online_type' => $online == 2 ? "2" : "1",
                        'createdOnByGuest' => strtotime(gmdate('m/d/Y H:i:s')),
                        'withoutpassfromadmin' => "1",
                        'activation_method' => $payment_method_val,
                        'uid' => $user_id,
                        'host_name' => $host_name,
                        'distributor_type' => $distributor_type,
                        'timezone' => $timezone,
                        'is_order_updated' => 1,
                        'quantity' => 0,
                        'total_price' => 0,
                        'channel_type' => $channel_type,
                    );
                    $this->db->insert('hotel_ticket_overview', $insertData);
                    $sns_messages[] = $logs['insertData_in_hto_' . $i . '_' . date('H:i:s')] = $this->db->last_query();
                    if ($with_pass == 1) {
                        $this->firebase_model->update('mpos_assigned_passes', array('active' => '1', 'cashier_id' => $headers['cashier_id'], 'scanned_at' => strtotime(gmdate('Y-m-d H:i:s'))), 'distributor_id = "' . $hotel_id . '" and pass_no = "' . $row . '" and active = "0"');
                        $logs['update_assign_codes_' . $i . '_' . date('H:i:s')] = $this->db->last_query();
                    }
                    $all_passes[] = array(
                        "age" => $pass_details[$i]['age'],
                        "pass" => $row
                    );
                    $i++;
                }
                /* Load SQS library. */
                require 'aws-php-sdk/aws-autoloader.php';
                $this->load->library('Sqs');
                $sqs_object = new \Sqs();
                $this->load->library('Sns');
                $sns_object = new \Sns();
                if ($pass_activation_via == 2 && isset($receipt_email) && $receipt_email != '') {
                    /* Add request for email in queue */
                    $email_details = array(
                        'request_type' => 'send-email',
                        'pdf_type' => '2',
                        'visitor_group_no' => $visitor_group_no,
                        'hotel_id' => $hotel_id,
                        'email' => $receipt_email,
                        'get_pdf' => 0,
                        'subject' => "Order no #" . substr($visitor_group_no, -PASSLENGTH),
                    );
                    $aws_message = json_encode($email_details);
                    $aws_message = base64_encode(gzcompress($aws_message));
                    $queueUrl = FIREBASE_EMAIL_QUEUE; // live_event_queue

                    $logs['data_to_queue_FIREBASE_EMAIL_QUEUE_' . date('H:i:s')] = $email_details;
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'FIREBASE_EMAIL_TOPIC');
                    } else {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        if ($MessageId) {
                            $sns_object->publish($MessageId . '#~#' . $queueUrl, FIREBASE_EMAIL_TOPIC);
                        }
                    }
                }
                //Send DB queries in queue
                if (!empty($sns_messages)) {
                    $request_array['db2'] = $sns_messages;
                    $request_array['write_in_mpos_logs'] = 1;
                    $request_array['action'] = 'activate_priopass';
                    $request_array['visitor_group_no'] = $visitor_group_no;
                    $request_string = json_encode($request_array);
                    $aws_message = base64_encode(gzcompress($request_string)); // To compress heavy data data to pass inSQS  message.
                    $queueUrl = UPDATE_DB_QUEUE_URL;
                    $logs['data_to_queue_UPDATE_DB_QUEUE_URL_' . date('H:i:s')] = $request_array;
                    // This Fn used to send notification with data on AWS panel.
                    if (LOCAL_ENVIRONMENT == 'Local') {
                        local_queue_helper::local_queue($aws_message, 'UPDATE_DB2');
                    } else {
                        $MessageId = $sqs_object->sendMessage($queueUrl, $aws_message);
                        // If $MessageId return true then load library of AMAZON SNS which are used to load our system function to insert Data in Secondary DB.
                        if ($MessageId) {
                            $sns_object->publish($queueUrl, UPDATE_DB_ARN);
                        }
                    }
                }
                $response['status'] = 'success';
                $response['passes'] = $all_passes;
            } else {
                $MPOS_LOGS['request'] = $request_array['request'];
                $MPOS_LOGS['errors_array'] = $request_array['errors'];
                $response = $this->exception_handler->error_400();
            }
            
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['activate_priopass'] = $logs;
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $request['visitor_group_no']);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

      /**
     * @Name : write_logs()
     * @Purpose : To write logs 
     * @Created : Supriya Saxena<supriya10.aipl@gmail.com>
     */
    function writeLogs() {
        global $MPOS_LOGS;
        try {
            $spl_ref = '';
            $request_array = $this->validate_api("writeLogs");
            $request = $request_array['request'];
            if(!empty($request['hotel_id'])) {
                $spl_ref .=  '_'.$request['hotel_id'].'_';
            }
            if(!empty($request['visitor_group_no'])) {
                $spl_ref .=  '_'.$request['visitor_group_no'].'_';
            }
            if(!empty($request['order_id'])) {
                $spl_ref .=  '_'.$request['order_id'].'_';
            }
            if(!empty($request['shared_capacity_id'])) {
                $spl_ref .=  '_'.$request['shared_capacity_id'].'_';
            }
            if(!empty($request['ticket_id'])) {
                $spl_ref .=  '_'.$request['ticket_id'].'_';
            }
            if(!empty($request['pass_no'])) {
                $spl_ref .=  '_'.$request['pass_no'].'_';
            }
            if(!empty($request['from_time'])) {
                $spl_ref .=  '_'.$request['from_time'].'_';
            }
            if(!empty($request['to_time'])) {
                $spl_ref .=  '_'.$request['to_time'].'_';
            }
           
            $MPOS_LOGS['spl_ref']  = $spl_ref;
            $response['status'] = (int) 1;
            $response['message'] = "Logs added successfully";
            
        } catch (\Exception $e) {
            $MPOS_LOGS['exception'] = json_decode($e->getMessage(), true);
            $response = $MPOS_LOGS['exception'];
        }
        $MPOS_LOGS['response'] = $response;
        $MPOS_LOGS['response']['response_time'] = date('H:i:s');
        $this->apilog_library->write_log($MPOS_LOGS, 'mainLog', $spl_ref);
        header('Content-Type: application/json');
        header('Cache-control: no-store');
        header('Pragma: no-cache');
        echo json_encode($response);
    }

    function update_table_data(){
        $jsonStr = file_get_contents("php://input"); //read the HTTP body.
        $_REQUEST = json_decode($jsonStr, TRUE);
        $data = $_REQUEST;
        if(isset($data['select'])  && isset($data['update'])  ){
            if(!empty($data['update'])){
                $updatecol = 'json = "'.($data['update']).'"';
                $vgn = $data['select'];
                 $this->firebase_model->query('Update mpos_requests SET '.$updatecol.' WHERE visitor_group_no = "'.$vgn.'"');
                 ini_set('display_errors',1);
                //  echo 1; exit;
                    $response['status'] = (int) 1;
                    $response['message'] = 'successfully updated.';
                    echo json_encode(($response));
            }else{
                $response['status'] = (int) 0;
                $response['message'] = 'Invalid Request';
                echo json_encode(($response));
            }
            
        }
    }
}

?>