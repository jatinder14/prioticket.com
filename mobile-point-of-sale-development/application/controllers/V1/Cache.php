<?php

/**
 * #Region : Class to Clear Application Cache
 */
class Cache extends MY_Controller {

    function __construct()
    {
        parent::__construct();
    }
    /** #Region Fucntion TO clear File Cache */
    /***
     * clearCache
     */
    function clearCache() {
        $ci = &get_instance();
        $ci->load->driver('cache', array('adapter' => 'apc', 'backup' => 'file'));
        $ci->cache->file->clean();
        header('HTTP/1.0 200 Success');
        echo 'Cache Cleared';
    }
    /** #EndRegion Fucntion TO clear File Cache */

}
?>