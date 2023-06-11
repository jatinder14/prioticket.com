<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * CodeIgniter 
 *
 * An open source application development framework for PHP 4.3.2 or newer
 *
 * @package		CodeIgniter
 * @author		ExpressionEngine Dev Team
 * @copyright	Copyright (c) 2008 - 2010, EllisLab, Inc.
 * @license		http://codeigniter.com/user_guide/license.html
 * @link		http://codeigniter.com
 * @since		Version 1.0
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * CodeIgniter File Helpers
 *
 * @package		CodeIgniter
 * @subpackage	Helpers
 * @category	Helpers
 * @author		ExpressionEngine Dev Team
 * @link		http://codeigniter.com/user_guide/helpers/file_helpers.html
 */

// ------------------------------------------------------------------------

/**
 * Read File
 *
 * Opens the file specfied in the path and returns it as a string.
 *
 * @access	public
 * @param	string	path to file
 * @return	string
 */	
if ( ! function_exists('get_timezone_from_text'))
{
	function get_timezone_from_text($timezone = 'Europe/Amsterdam', $date_to_check = ""){
            if(empty($date_to_check) || strtotime($date_to_check) <= 0){
                $date_to_check  = date("Y-m-d h:i:s");
            }else{
                $date_to_check  = date("Y-m-d h:i:s", strtotime($date_to_check));
            }
            if(empty($timezone)){
                $timezone = get_timezone_of_date($date_to_check);
            }
             $date = new DateTime($date_to_check, new DateTimeZone($timezone) );
            return $date->format('P');
        }
}

/**
* 
* @param type $date corresponding to which timezone is required
* @param type $db_timezone the timezone that needs to be store in db
* @return type
*/
if ( ! function_exists('get_timezone_of_date'))
{
        function get_timezone_of_date($date = "", $db_timezone = 0){
            $current_time =!empty($date) ? gmdate("H:i:s",strtotime($date)) : gmdate("H:i:s");
            $date = !empty($date) ? gmdate('Y-m-d',strtotime($date)) : gmdate("Y-m-d");
            $last_sunday_march = date('Y-m-d', strtotime(date('Y-04-01', strtotime($date)) . ' last sunday'));
            $last_sunday_october = date('Y-m-d', strtotime(date('Y-11-01', strtotime($date)) . ' last sunday'));
            if (strtotime($date) >= strtotime($last_sunday_march) && strtotime($date) < strtotime($last_sunday_october)) {
                if(strtotime($date) == strtotime($last_sunday_march) && $current_time < "02:00:00"){
                    $timezone = ($db_timezone == 1) ? '+1' : '+01:00';
                } else {
                    $timezone = ($db_timezone == 1) ? '+2' : '+02:00';
                }
            } else {
                if(strtotime($date) == strtotime($last_sunday_october) && $current_time < "02:00:00"){
                    $timezone = ($db_timezone == 1) ? '+2' : '+02:00';
                }else{
                    $timezone = ($db_timezone == 1) ? '+1' : '+01:00';
                }
            }
            return $timezone;
        }
}

/**
* @Name        : formatted_timezone
* @Purpose     : function used to convert any row format of timezone value into standard format with '+' or '-' sign in it
* @param type $timezone
* @return string
* @CreatedBy   : Aftab Raza <aftab.aipl@gmail.com> on 23 DEC 2018
*/
if ( ! function_exists('formatted_timezone')) {
    /* Get the format of timezone Like '+02:00' */
    function formatted_timezone($timezone = '') {
        if (!empty($timezone)) { 
            $split_timezone = str_split($timezone, 1);
            if($split_timezone[0] != '+' && $split_timezone[0] != '-') { 
                $timezone = '+'.$timezone;
            } 
            $sign_of_timezone = str_split($timezone, 1)[0]; 
            $timezone = substr($timezone, 1); 
            $explode_timezone = explode(':', $timezone);
            if (count($explode_timezone) == 1) { 
                $explode_unformated = explode('.', $timezone);
                if (count($explode_unformated) > 1) {
                    $into_minutes = '0.'.$explode_unformated[1];
                    $explode_unformated[1] = round($into_minutes*60);
                } else {
                        $explode_unformated[1] = '00';
                } 
                if (strlen($explode_unformated[0]) == 1) {
                    $explode_unformated[0] = '0'.$explode_unformated[0];
                } 
                $new_timezone = $sign_of_timezone.$explode_unformated[0].':'.$explode_unformated[1];
                return $new_timezone;
            } else if (count($explode_timezone) > 1) {
                if (strlen($explode_timezone[0]) == 1) {
                    $explode_timezone[0] = '0'.$explode_timezone[0];
                }
                $new_timezone = $sign_of_timezone.$explode_timezone[0].':'.$explode_timezone[1];
                return $new_timezone;
            }
        }
    }
}
/**
* @Name        : formatted_timespan
* @Purpose     : function used to convert ISO 8601 format (e.g 'PT3H35M44S') value into standard HH:MM:SS format
* @param type timespan
* @return string
* @CreatedBy   : Aftab Raza <aftab.aipl@gmail.com> on 07 Mar 2019
*/
if (!function_exists('formatted_timespan')) {
    function formatted_timespan($timespan = '') {
        $split_string = $explode_formatted_string = $final_array = array();
        $edit_string = $pos = $response = '';
        $count = $i = 0;
        if(!empty($timespan)) {
            
            $split_string = str_split($timespan, 1);
            $arr = ['P', 'T', 'S'];
            foreach ($split_string as $value) {
                if (is_numeric($value)) {
                    $edit_string .= $value;
                } else if (!in_array($value, $arr)) {
                    $edit_string .= ':';
                    $count++;
                }
            }
            if ($count < 2) {
                if (!in_array('H', $split_string)) {
                    $edit_string = '00:' . $edit_string;
                }
                if (!in_array('M', $split_string)) {
                    $pos = strpos($edit_string, ':');
                    $edit_string = substr_replace($edit_string, ':00', $pos, 0);
                }
            }
            if (!in_array('S', $split_string)) {
                $edit_string = $edit_string . '00';
            }
            $explode_formatted_string = explode(':', $edit_string);
            foreach ($explode_formatted_string as $value) {
                if (strlen($value) < 2) {
                    $final_array[$i] = '0' . $value;
                } else {
                    $final_array[$i] = $value;
                }
                $i++;
            }
            $response = implode(':', $final_array);
        }
        return $response;
    }
}

/**
* @Name        : get_datetime_including_timezone
* @Purpose     : to get the date and time after adding timezone to it
* @param type date and time after timezone
* @return string
*/
if ( ! function_exists('get_datetime_including_timezone')) {
    /* Get the correct date time after adding timezone in UTC date time */
        function get_datetime_including_timezone($time, $date = ''){    
            $flag = '+';
            $finaldate = '';
            if(!empty($time)) {
                if(strstr($time, '-')) {
                    $flag = '-';
                    $time = str_replace('-', '', $time);
                }
                $time = explode(':', $time);
                $minutes = ($time[0]*60) + (isset($time[1]) ? $time[1] : 0) + (isset($time[2]) ? ($time[2]/60) : 0);
            } else {
                $minutes = 0;
            }
            if($date != ''){
                $finaldate = gmdate("Y-m-d H:i:s", strtotime("$flag $minutes minutes", strtotime($date)));
            } else {
                $finaldate = gmdate("Y-m-d H:i:s", strtotime("$flag $minutes minutes"));
            }
            return $finaldate;
        }
} 

if ( ! function_exists('getDateTimeWithTimeZone')) {
/**
     * @Name        : getDateTimeWithTimeZone()
     * @Purpose     : This function will get date with passed timezone name timezone .
     * @CreatedBy   :Neha <nehadev.aipl@gmail.com>
     * @CreatedDate :1-Apirl-2020
     */
     function getDateTimeWithTimeZone($timezone_name = '', $datetime = '', $no_t_formate = 0) { /* $timezone_name = Canada/Newfoundland, $datetime = 2018-07-26 11:30:00 */
        if ($timezone_name) {
            $time_zone = get_timezone_from_text($timezone_name, $datetime);
        } else {
            $time_zone = get_timezone_of_date($datetime);
        }
        list($hours, $minutes) = explode(':', $time_zone);
        $seconds = $hours * 60 * 60 + $minutes * 60;

        $timestamp = strtotime($datetime) + $seconds;
        if ($no_t_formate == 1) {
            return date('Y-m-d H:i:s', $timestamp);
        } else {
            return date('Y-m-d', $timestamp) . 'T' . date('H:i:s', $timestamp) . $time_zone;
        }
    }
}