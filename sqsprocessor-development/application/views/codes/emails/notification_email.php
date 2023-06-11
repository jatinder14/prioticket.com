<?php
$imageDir = $this->config->config['imageDir'];
$base_url = $this->config->config['base_url'];
?>

<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <!--<meta name="viewport" content="width=device-width, initial-scale=1.0"/>-->
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    </head>
    <body>
        <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0" bgcolor="#ffffff" style="margin-top:15px;">
            <?php $extra_text_field_answer = '';
            $travelerHotel='';
            $total_extra_option = '';
            $museum_id_array = array();
            $production_count = 0;
            foreach ($mail_content as $key => $value) {
                $total_extra_option = '';
                if (isset($value['ticket_id'])) {
                    $key = $value['ticket_id'];
                }
                $hotel_name = $value['hotel_name'];    
                ++$production_count; 
                $all_ticket_data = '';
            ?>
                <?php if (!in_array($value['museum_id'], $museum_id_array)) {
                    array_push($museum_id_array, $value['museum_id']); ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong><?php echo 'Order ID: '?></strong><?php echo $value['visitor_group_no']; ?></span>
                    </td>
                </tr>
                <tr>
                    <td></td>
                </tr>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong><?php echo 'Supplier Name: '?></strong><?php echo $value['museum_name']; ?></span>
                    </td>
                </tr>
                <tr>
                    <td></td>
                </tr>

                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong><?php echo 'Distributor: '?></strong>
                                <?php echo $distributor_name; ?>
                        </span>                           
                    </td>
                </tr>

                <?php if(!empty($agentEmail)) { ?>     
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <span style="line-height:20px;"><strong><?php echo 'Agent Email: '?></strong>
                                    <?php echo $agentEmail; ?>
                            </span>                           
                        </td>
                    </tr>
                <?php } ?> 
                
                <?php if(!empty($value['guest_names']) || !empty($value['guest_email']) || !empty($value['phone_number']) || !empty($value['room_no']) || !empty($value['client_reference']) || !empty($value['country_of_residence']) || !empty($value['gender'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><br>Guest Details </strong></span>
                    </td>
                </tr>          
                <?php if (!empty($value['guest_names'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Name: '?></strong><?php echo $value['guest_names']; ?></span>
                    </td>
                </tr>              
                <?php } if (!empty($value['guest_email'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Email: '?></strong><?php echo $value['guest_email']; ?></span>
                    </td>
                </tr>
                <?php } ?>
                
                <?php if (!empty($value['gender'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Gender: '?></strong><?php echo $value['gender']; ?></span>
                    </td>
                </tr>              
                <?php } if (!empty($value['phone_number'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Phone number: '?></strong><?php echo $value['phone_number']; ?></span>
                    </td>
                </tr>
                <?php }  if (!empty($value['room_no'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Room Number: '?></strong><?php echo $value['room_no']; ?></span>
                    </td>
                </tr>
                <?php }  if (!empty($value['country_of_residence'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Country of residence: '?></strong><?php echo $value['country_of_residence']; ?></span>
                    </td>
                </tr>
                <?php } if (!empty($value['client_reference'])) { ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><?php echo 'Client Reference: '?></strong><?php echo $value['client_reference']; ?></span>
                    </td>
                </tr>                
                <?php }
                }
                ?>   
                <tr><td></td></tr>      
                <?php
                } ?>

              
                <?php if($production_count > 1) { ?>
                    <tr><td><hr></td></tr>
                <?php } ?>

                <?php if (!empty($value['age_groups'][$key]) && !empty($value['api_data'])){ ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <br/>
                    </td>
                </tr>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong> Participant Details: </strong>
                        <?php                         
                        foreach ($value['age_groups'][$key] as $group) {                           
                            foreach ($group['extraBookIngInformationApi'] as $row) {                             
                               echo '<tr valign="top" align="left"><td align="left" valign="top"><span><strong>Name: </strong>'. $row['name'].' ('.str_replace("(s)", "", $group['ticket_type']).')</span></td></tr>';   
                             }                   
                            } ?>
                        </span>
                    </td>
                </tr>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <br/>
                    </td>
                </tr>
                <?php } ?>

                <?php if(!empty($value['is_reservation'])) { ?>
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <?php
                            if (isset($value['selected_date']) && ($value['selected_date'] != '' || $value['selected_date'] != 0)) {
                                $date = date("d-m-Y", strtoTime(str_replace('_', '-', $value['selected_date'])));
                            } else {
                                $date = gmdate('d-m-Y');
                            } ?>
                            <span style="line-height:20px;"><strong><?php echo 'Travel Date: '?></strong><?php echo $date; ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                    </tr>
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <?php if (isset($value['from_time']) && ($value['from_time'] != '' || $value['from_time'] != 0)) {
                                if (isset($value['slot_type']) && $value['slot_type'] == 'specific') {
                                    $time = $value['to_time'];
                                } else {
                                    $time = $value['from_time'].' - '.$value['to_time'];
                                }
                            } else {
                                $time = gmdate('H:i:s', strtotime(gmdate('H:i:s').' +1 hours'));
                            } 
                            if(!empty($time)) { ?>
                                <span><strong><?php echo 'Time: '?></strong><?php echo $time; ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?> 

                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong>
                        <?php 
                            if(!empty($value['related_product_id']) && $key != $value['related_product_id']) {                      
                                $productTitle = 'Sub-product Title: ';
                            } else {
                                $productTitle = 'Product Title: ';
                            } 
                        ?>
                        <?php echo $productTitle; ?></strong><?php echo $value['ticket_title']; ?></span>
                    </td>
                </tr>
                <tr>
                    <td></td>
                </tr>
                <?php
                    foreach ($value['age_groups'][$key] as $group) {
                        if(!empty($group['ticket_type'])) { 
                            if(!empty($group['pax']) && $group['ticket_type'] == 'Family(s)') {
                                $all_ticket_data.=$group['pax'].' Pax '.'x '.$group['count'] .' '.str_replace("(s)", "", $group['ticket_type']).',';
                            } else {
                                $all_ticket_data.=$group['count'] .'x '.str_replace("(s)", "", $group['ticket_type']).',';
                            }
                        }
                    } ?>
                <?php  if(!empty($all_ticket_data)) { ?>
                        <tr valign="top" align="left">
                            <td align="left" valign="top">
                                <span style="line-height:20px;"><strong><?php echo 'Product Type: '?></strong>
                                        <?php echo rtrim($all_ticket_data, ','); ?>
                                </span>                           
                            </td>
                        </tr>
                <?php } ?>
                <?php if (!empty($extra_options_per_ticket[$key])) {                         
                        foreach($extra_options_per_ticket[$key] as $name => $row) {  
                            foreach($row as $quantity) {
                                $total_extra_option.=$quantity. 'x '. ucwords($name).',';
                            } 
                        }
                ?>
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;"><strong> Product Extra option: </strong>
                        <?php  echo rtrim($total_extra_option,','); ?>
                        </span>
                    </td>
                </tr>
                <?php } ?>   

                       
                <tr>
                    <td></td>
                </tr>
                         
		      <?php if ($value['extra_text_field'] && $value['extra_text_field_answer'] && $value['extra_text_field'] != 'undefined' && $value['extra_text_field'] != '' && $value['extra_text_field_answer'] != '') { ?>
		            <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <span style="line-height:20px;"><strong>Extra info :</strong><?php echo $value['extra_text_field']; ?></span>
                        </td>
                    </tr>
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <span style="line-height:20px;"><?php echo $value['extra_text_field_answer']; ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                    </tr>
                <?php }
                if (isset($value['pickups_data']) && !empty($value['pickups_data'])) { ?>

                   <?php if(!empty($value['pickups_data']['name'])) { ?>
                        <tr valign="top" align="left">
                            <td align="left" valign="top">
                                <span style="line-height:20px;"><strong><?php echo 'Location name: '?></strong><?php echo $value['pickups_data']['name']; ?></span>
                            </td>
                        </tr>
                   <?php } if(!empty($value['pickups_data']['targetlocation'])) {?>
                        <tr valign="top" align="left">
                            <td align="left" valign="top">
                                <span style="line-height:20px;"><strong><?php echo 'Location address: '?></strong><?php echo $value['pickups_data']['targetlocation']; ?></span>
                            </td>
                        </tr>
                   <?php } if(!empty($value['pickups_data']['time'])) {?>
                        <tr valign="top" align="left">
                            <td align="left" valign="top">
                                <span style="line-height:20px;"><strong><?php echo 'Location time: '?></strong><?php echo $value['pickups_data']['time']; ?></span>
                            </td>
                        </tr>
                    <?php
                      }
                    }
                if(!empty($value['note'])) { ?>
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <span><strong><br></strong></span>
                            <span><strong><?php echo 'Guest note: '?></strong><?php echo $value['note']; ?></span>
                        </td>
                    </tr>
                <?php } 
                if ($value['booking_email_text'] != '') { ?>                 
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span><strong><br></strong></span>
                        <span><strong><?php echo 'Internal note: '?></strong><?php echo $value['booking_email_text']; ?></span>
                    </td>
                </tr>                   
                <?php }
                }
            if($extra_text_field_answer  && $extra_text_field_answer != '') {
                $languages = array('Spanish','English','Italian','German','Catalan','French');
                $user_note = explode('-', $extra_text_field_answer);
                if(in_array(trim($user_note[count($user_note)-1]), $languages)){
                    unset($user_note[count($user_note)-1]);
                    $extra_text_field_answer = implode('-', $user_note);
                }
                if(trim($extra_text_field_answer) != '') {
                ?>
                    <tr valign="top" align="left">
                        <td align="left" valign="top">
                            <span style="line-height:20px;"><strong>Extra comment : </strong><?php echo $extra_text_field_answer; ?></span>
                        </td>
                    </tr>

                    <tr>
                        <td></td>
                    </tr>
                <?php
                }
            }
            ?>
            <?php if ($market_merchant_id == 2) { ?>
                <br />
                <tr valign="top" align="left">
                    <td align="left" valign="top">
                        <span style="line-height:20px;color:red;">This is a system generated email, please do not reply. For any queries regarding the booking, please contact "support@priohub.com"</span>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </body>
</html>
