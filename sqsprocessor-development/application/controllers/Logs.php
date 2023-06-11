<?php
if (!defined('BASEPATH')){
    exit('No direct script access allowed');
}
class Logs extends MY_Controller {
    var $base_url;
    function __construct() {
        parent::__construct();
        $this->load->helper(array('form', 'url'));
        $this->load->model('common_model');
        $this->load->model('hotel_model');
        $this->load->model('pos_model');
        $this->load->library('session');
        $this->load->library('log_library');
        $this->base_url = $this->config->config['base_url'];
        $this->LANGUAGE_CODES['en'] = 'english';
        $this->LANGUAGE_CODES['es'] = 'spanish';
        $this->LANGUAGE_CODES['nl'] = 'dutch';
        $this->LANGUAGE_CODES['de'] = 'german';
        $this->LANGUAGE_CODES['it'] = 'italian';
    }

    
    function logs_login() {
        if ($this->session->userdata('username')) {
            redirect('logs/logs_listing');
        } else {
            $this->load->view('logs/logs_login');
        }
    }

    function postLogin() {
        $this->load->library('form_validation');
        $this->form_validation->set_rules('email', 'Email', 'required');
        $this->form_validation->set_rules('password', 'Password', 'required');
        if ($this->form_validation->run() != FALSE) {
            $credentials = array(
                'uname' =>  $this->input->post('email', true),
                'user_type' => "superAdmin"
            );
            $checkLogin = $this->common_model->getSingleRowFromTable('users',$credentials);
            if($checkLogin && password_verify(md5($this->input->post('password', true)), $checkLogin->password)) {
                $this->session->set_userdata('username',$checkLogin->uname);
                redirect('logs/logs_listing');
            }
        }
        $this->session->set_flashdata('loginError','Please enter valid details.');
        redirect('logs/logs_login');
    }

    function logs_listing() {
        //check is hotel admin logged in or not?
        if ($this->session->userdata('username')) {
            $this->load->view('logs/logs_listing');
        } else {
            redirect('logs/logs_login');
        }
    }

    function filter_log() {
        $base_url = $this->config->config['base_url'];
        if ($this->session->userdata('username')) {
            if($_REQUEST['search']) {
                $directory = 'application/storage/logs/'.$_REQUEST['search'];
                $all_logs_files = glob($directory . "*");
            } else {  
                $directory = 'application/storage/logs/';
                $all_logs_files = glob('application/storage/logs/{*.log,*.php}', GLOB_BRACE);
            }
            
            $log_files = array();
            if(!empty($all_logs_files)){
                $num_count = count($all_logs_files);
                $html ='';
                if($num_count == 0) {
                    $html ='<tr><td>No Record</td></tr>';    
                }
                $count =1;
                for($i=0; $i < $num_count; $i++){
                    if($count <= 10) {
                        str_replace('application/storage/logs/', '', $all_logs_files[$i]);
                        if(strstr(strtolower($all_logs_files[$i]), '.php') || strstr(strtolower($all_logs_files[$i]), '.log') || strstr(strtolower($all_logs_files[$i]), '.txt')){
                            $log_files[] = $all_logs_files[$i];
                            $ext = 'No Extension';
                            if(strstr(strtolower($all_logs_files[$i]), '.php')){                        
                                $ext = '.php';
                            } else if(strstr(strtolower($all_logs_files[$i]), '.log')){ 
                                $ext = '.log';
                            }  else if(strstr(strtolower($all_logs_files[$i]), '.txt')){ 
                                $ext = '.txt';
                            }
                            $url =    str_replace('application/storage/logs/', '', $all_logs_files[$i]); 
                            $html .="<tr>";
                            $html .='<td><a href="'.$base_url.'/logs/logs_content/5/'.$url.'">'.$count.'</td>';
                            $html .='<td><a href="'.$base_url.'/logs/logs_content/5/'.$url.'">'.$ext.'</td>';
                            $html .='<td><a href="'.$base_url.'/logs/logs_content/5/'.$url.'">'. str_replace('application/storage/logs/', '', $all_logs_files[$i]).'</td></tr>';
                            //$html .='<td><a href="'.$base_url.'/logs/download_logs_file/5/'.$url.'">Download &nbsp;&nbsp;<a  onclick="return confirm(\'Are you sure you want to delete this item?\');" href="'.$base_url.'/logs/delete_logs_file/'.$url.'/1">Delete </a></td>';
                            $count++; 
                        }
                    } else  {
                        $num_count = $count;
                    } 
                }
            }
            echo $html;
        }  else {
            return false;
        }
    }
    
    function logs_content($cod_id = '5', $file_name){
        //check is hotel admin logged in or not?
        if ($this->session->userdata('username')) {
            $handle = fopen('application/storage/logs/'.$file_name, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {echo '<br>'.$line;
                    // process the line read.
                }
                fclose($handle);
            } else {
                echo ' error opening the file.';
            }
        } else {
            redirect('logs/logs_login');
        }
    }

}