<?php
# PHP wrapper class for WAT freshdesk

class Watfreshdesk {
    
    protected $CI;

    public function __construct() {
        $this->CI = &get_instance();
    }
    /**
     * Function to create ticket on freshdesk for azimuth event
     * @called_from : Pos ontroller, function: insert_in_db()
     * @params : 
     *      $visitor_group_no -> order id
     * @created_by : Parul Mahajan <parul.intersoft1@gmail.com>
     * @created_on : 11 Feb 2020
     */
    public function create_ticket_on_fresh_desk_for_azimuth_event($visitor_group_no, $prepaid_data){
        $data = array();
        
        if (empty($visitor_group_no) || empty($prepaid_data)){
            return false;
        }
        
        $ticket_ids = array_unique(array_column($prepaid_data, 'ticket_id'));
        $prioticket_azimuth_products_ids = json_decode(PRIOTICKET_AZIMUTH_PRODUCT_IDS, true);
        $prioticket_azimuth_one_day_product_id = $prioticket_azimuth_products_ids['main']['day'];
        $prioticket_azimuth_week_day_product_id = $prioticket_azimuth_products_ids['main']['week'];
        $prioticket_azimuth_flight_product_ids = $prioticket_azimuth_products_ids['flights'];
        $prioticket_azimuth_accommodation_product_ids = $prioticket_azimuth_products_ids['accommodation'];
        $prioticket_azimuth_ground_transport_product_ids = $prioticket_azimuth_products_ids['ground_transport'];
        $is_azimuth_flight_product_present = '0';
        $is_azimuth_weekend_product_present = '0';
        $is_azimuth_one_day_present = '0';
        foreach ($ticket_ids as $ticket_id) {
            if ($ticket_id == $prioticket_azimuth_one_day_product_id){
                $is_azimuth_one_day_present = '1';
            }
            if (in_array($ticket_id, $prioticket_azimuth_accommodation_product_ids)){
                $is_azimuth_accomodation_present = '1';
            }
            if (in_array($ticket_id, $prioticket_azimuth_ground_transport_product_ids)){
                $is_azimuth_ground_transportaion_present = '1';
            }
            if (in_array($ticket_id, $prioticket_azimuth_flight_product_ids)){
                $is_azimuth_flight_product_present = '1';
            }
            if ($ticket_id == $prioticket_azimuth_week_day_product_id){
                $is_azimuth_weekend_product_present = '1';
            }
        }
        
        // Static array for azimuth flight data
        foreach ($prioticket_azimuth_flight_product_ids as $azimuth_flight_product) {
            
            switch($azimuth_flight_product){
                case '18663':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Economy';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'Riyadh to Alula';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_to'] = $azimuth_flight_product;
                    break;
                case '18666':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Economy';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'Alula to Riyadh';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_from'] = $azimuth_flight_product;
                    break;
                case '18660':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Economy';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'Jeddah to AlUla';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_to'] = $azimuth_flight_product;
                    break;
                case '18642':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Economy';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'AlUla to Jeddah';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_from'] = $azimuth_flight_product;
                    break;
                case '18636':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Business';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'Riyadh to Alula';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_to'] = $azimuth_flight_product;
                    break;
                case '18630':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Business';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'AlUla to Riyadh';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_from'] = $azimuth_flight_product;
                    break;
                case '18633':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Business';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'Jeddah to Alula';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_to'] = $azimuth_flight_product;
                    break;
                case '18627':
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_ticket_class'] = 'Business';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_sector'] = 'AlUla to Jeddah';
                    $azimuth_flight_static_data[$azimuth_flight_product]['flight_from'] = $azimuth_flight_product;
                    break;
            }
            
        }

        if (($is_azimuth_one_day_present == '1' && $is_azimuth_flight_product_present == '1') || $is_azimuth_weekend_product_present == '1'){

            $car_as_transport = '<br><b>Mode of Transport :</b> By car<br>';
            foreach ($prepaid_data as $prepaid_key => $prepaid_row) {
            
                // Fetch Main Product Data
                if ($prepaid_row['ticket_id'] == $prioticket_azimuth_week_day_product_id || $prepaid_row['ticket_id'] == $prioticket_azimuth_one_day_product_id){
                    if (!isset($no_of_guests)){
                        $no_of_guests = $prepaid_row['quantity'];
                    } else {
                        $no_of_guests = $no_of_guests + $prepaid_row['quantity'];
                    }
                    $package_name = $prepaid_row['title'];
                    $extra_booking_info = json_decode($prepaid_row['extra_booking_information']);
                    
                    if (!empty($extra_booking_info) && isset($extra_booking_info->per_participant_info)){
                        $per_participant_info = $extra_booking_info->per_participant_info;
                        $name = (isset($per_participant_info->name) && !empty($per_participant_info->name)) ? 'Full Name: '.$per_participant_info->name.'<br>' : '';
                        $gender = (isset($per_participant_info->gender) && !empty($per_participant_info->gender)) ? 'Gender: '.$per_participant_info->gender.'<br>' : '';
                        $date_of_birth = (isset($per_participant_info->date_of_birth) && !empty($per_participant_info->date_of_birth)) ? 'Date of Birth'.$per_participant_info->date_of_birth.'<br>' : '';
                        $id = (isset($per_participant_info->id) && !empty($per_participant_info->id)) ? 'ID/Passport no: '.$per_participant_info->id.'<br>' : '';
                        $nationality = (isset($per_participant_info->nationality) && !empty($per_participant_info->nationality)) ? 'Nationality: '.$per_participant_info->nationality.'<br>' : '';
                        $country_of_residence = (isset($per_participant_info->country_of_residence) && !empty($per_participant_info->country_of_residence)) ? 'Country reference: '.$per_participant_info->country_of_residence.'<br>' : '';
                        $phone_no = (isset($per_participant_info->phone_no) && !empty($per_participant_info->phone_no)) ? 'Phone no: '.$per_participant_info->phone_no.'<br>' : '';
                        $email = (isset($per_participant_info->email) && !empty($per_participant_info->email)) ? 'Email ID: '.$per_participant_info->email.'<br>' : '';
                        $passport_expiry = (isset($per_participant_info->passport_expiry) && !empty($per_participant_info->passport_expiry)) ? 'Passport Expiry: '.$per_participant_info->passport_expiry.'<br>' : '';

                        $participant_key = ++$prepaid_key;
                        $per_participant_data .= '<br><b>'.$prepaid_row['ticket_type'].' '.$participant_key.'</b><br>'.$name.'Gender: '.$gender.$date_of_birth.$id.$nationality.$country_of_residence.$phone_no.$email.$passport_expiry;
                        
                    }
                }
    
                // Fetch Flight Data
                if (in_array($prepaid_row['ticket_id'], $prioticket_azimuth_flight_product_ids)){
                    if ($prepaid_row['ticket_id'] == $azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_from']){
                        $flight_from_data = '<br><b>Mode of Transport from: </b><br>Flight ticket class: '.$azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_ticket_class'].'<br>Flight sector: '.$azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_sector'].'<br>Flight date: '.$prepaid_row['selected_date'].'<br>Flight Time: '.$prepaid_row['to_time'].'<br>'; 
                        $car_as_transport = '';
                    } 
                    if ($prepaid_row['ticket_id'] == $azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_to']){
                        $flight_to_data = '<br><b>Mode of Transport to: </b><br>Flight ticket class: '.$azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_ticket_class'].'<br>Flight sector: '.$azimuth_flight_static_data[$prepaid_row['ticket_id']]['flight_sector'].'<br>Flight date: '.$prepaid_row['selected_date'].'<br>Flight Time: '.$prepaid_row['to_time'].'<br>'; 
                        $car_as_transport = '';
                    }
                    
                }
                // Fetch Accomodation Data
                
                if (in_array($prepaid_row['ticket_id'], $prioticket_azimuth_accommodation_product_ids)){
                    
                    $accommodation_data[$prepaid_row['ticket_id']]['title'] = $prepaid_row['title'];
                    $hotel_data .= '<br><b>Hotel Room: </b>'.$prepaid_row['title'];
                    if (!isset($accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms']) ){
                        $accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms'] = $prepaid_row['quantity'];
                        $hotel_data .= '<br>No. of Rooms: '.$accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms'].'<br>';
                    } else {
                        $accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms'] = $accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms'] + $prepaid_row['quantity']; 
                        $hotel_data = '<br><b>Hotel Room: </b><br>'.$accommodation_data[$prepaid_row['ticket_id']]['title'].'<br>No. of Rooms: '.$accommodation_data[$prepaid_row['ticket_id']]['no_of_rooms'].'<br>';
                    }
                } 
                // Fetch Fround transportaion Data
                if(in_array($prepaid_row['ticket_id'], $prioticket_azimuth_ground_transport_product_ids)){
                    if (!isset($no_of_ground_transportaion)){
                        $no_of_ground_transportaion = $prepaid_row['quantity'];
                    } else {
                        $no_of_ground_transportaion = $no_of_ground_transportaion + $prepaid_row['quantity'];
                    }
                    $ground_transportaion_data  = '<br><b>Ground Transportaion: </b>'.$prepaid_row['title'].' - '.$no_of_ground_transportaion.'<br>';
                }
                if ($is_azimuth_one_day_present == '1' && $is_azimuth_accomodation_present == '0'){
                    $hotel_data = '';
                }
                if ($is_azimuth_one_day_present == '1' && $is_azimuth_ground_transportaion_present == '0'){
                    $ground_transportaion_data = '';
                }
                $main_booker_name = $prepaid_row['guest_names'];
                $main_booker_email = $prepaid_row['guest_emails'];
                $description = '<b>Order Id: </b>'.$visitor_group_no.
                                    '<br><br><b>No. of Guests: </b>'.$no_of_guests.
                                    '<br><b>Package Name: </b>'.$package_name.'<br>'.
                                    ''.$flight_to_data.
                                    ''.$flight_from_data.
                                    ''.$car_as_transport.
                                    ''.$hotel_data
                                    .''.$ground_transportaion_data
                                    .'<br><b>Main booker: </b><br>Name: '.$prepaid_row['guest_names']
                                    .'<br>Email: '.$prepaid_row['guest_emails']
                                    .'<br>'.$per_participant_data ;
                
            }
            $wat_freshdesk_details = json_decode(WAT_FRESHDESK_DETAILS, true);
            $data = array(
                "name" => $main_booker_name,
                "description" => $description,
                "subject" => "Welcome to Azimuth - Your tickets are here!", 
                "email" => $main_booker_email,
                "priority" => $wat_freshdesk_details['priority'], 
                "status"=> $wat_freshdesk_details['status'], 
                "group_id" => $wat_freshdesk_details['group_id'], 
                "email_config_id" => $wat_freshdesk_details['email_config_id'],
                "custom_fields"=> $wat_freshdesk_details['custom_fields']
            );
            $wat_freshdesk_details = json_decode(WAT_FRESHDESK_DETAILS, true);
            // Initialise a curl
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $wat_freshdesk_details['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    "Content-Type: application/json",
                    "Authorization: Basic ".base64_encode($wat_freshdesk_details['authorization']).""
                ),
            ));

            // execute curl request
            $response = curl_exec($ch);
            $this->CI->CreateLog('wat_freshdesk.php', $visitor_group_no, array('curl response : ' => $response ) );
            curl_close($ch);
            
        } 
        return true;
    }
    

    

    
}
?>
