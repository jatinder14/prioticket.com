<?php 
/**
 * this library is used for transactions
 * created by Vaishali Raheja <vaishali.intersoft@gmail.com> on 24 Jan 2020
 * Here, we are using all DB connections for which transactions are made on SQS server
 * **/
class Trans
{
    function __construct() {
        $this->CI = & get_instance();
        $this->DBS = array('default', 'secondarydb', 'fourthdb');
    }

    /*
     * @purpose : to begin the transaction 
     */
    function begin() 
    {
        foreach($this->DBS as $db_name) { //transactions begin for all db connections
            if ($db_name == 'default') {
                $this->CI->db->db_debug = FALSE;
                $this->CI->db->trans_begin();
            } else {
                $this->CI->$db_name->db->db_debug = FALSE;
                $this->CI->$db_name->db->trans_begin();
            }
        }
    }

    /*
     * @purpose : to check the transaction status and rollback or commit all the transactions if any failure exsits
     */
    function close()
    {
        global $MPOS_LOGS;
        $failed_queries = array();
        $filename = 'failed_transactions.php';
        //check for failed transactions in all db connections n collect all failed queries
        foreach($this->DBS as $db_name) {
            if ($db_name == 'default') {
                $this->CI->$db_name->db = $this->CI->db;
            }  
            if($this->CI->$db_name->db->trans_status() === FALSE) {
                
                $log = "Time: " . date('m/d/Y H:i:s') . "\r\r: TRANSACTION_FAILED : " . $db_name . 
                        "\r\r queries \r\r : ".json_encode($this->CI->$db_name->db->queries) .
                        "\r\r query_times : ".json_encode($this->CI->$db_name->db->query_times) .
                        "\r\r error : ".json_encode($this->CI->$db_name->db->error());
                if (is_file('application/storage/logs/'.$filename)) {
                     if (filesize('application/storage/logs/'.$filename) > 1048576) {
                         rename("application/storage/logs/$filename", "application/storage/logs/".$filename."_". date("m-d-Y-H-i-s") . ".php");
                     }
                 }
                 $fp = fopen('application/storage/logs/'.$filename, 'a');
                 fwrite($fp, "\n\r\n\r\n\r" . $log);
                 fclose($fp);
            
                foreach($this->CI->$db_name->db->query_times as $index => $time) {
                    if ($time === 0) {
                        $failed_queries[$db_name]['queries'][] = $this->CI->$db_name->db->queries[$index];
                        $errors = $this->CI->$db_name->db->error();
                        if (!empty($errors)) {
                            $failed_queries[$db_name]['errors'][] = $this->CI->$db_name->db->error();
                        }
                    }
                }
            }
        }
        //if there are failed queries, then rollback complete transactions
        if(!empty($failed_queries)) {
            $MPOS_LOGS['failed_queries_before_rollback'] = $failed_queries;
            foreach($this->DBS as $db_name) {
                if ($db_name == 'default') {
                    $this->CI->db->trans_rollback();
                } else {
                    $this->CI->$db_name->db->trans_rollback();
                }
            }
            throw new Exception("rollback_done");
        } else { //no failed transactions so commit all 
            foreach($this->DBS as $db_name) {
                if ($db_name == 'default') {
                    $this->CI->db->trans_commit();
                } else {
                    $this->CI->$db_name->db->trans_commit();
                }
            }
        }
    }
    
    /*
     * @purpose : to check the transaction status and return failed transactions if any failure exsits
     */
    function check_trans_status () {
        $failed_queries = array();
        foreach($this->DBS as $db_name) {
            if ($db_name == 'default') {
                $this->CI->$db_name->db = $this->CI->db;
            }  
            if($this->CI->$db_name->db->trans_status() === FALSE){
                foreach($this->CI->$db_name->db->query_times as $index => $time) {
                    if ($time === 0) {
                        $failed_queries[$db_name][] = $this->CI->$db_name->db->queries[$index];
                    }
                }
            }
        }
        return $failed_queries;
    }
}
?>