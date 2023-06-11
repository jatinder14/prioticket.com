<?php

/**
 * used to get css, js using cdn 
 * cdn
 * @access	public
 * @return	string
 */
if (!function_exists('cdn')) {

    function cdn($path = null) {
        $path = (string) $path;
        if (empty($path)) {
            throw new Exception('URL missing');
        }

        $pattern = '|^/|';
        if (!preg_match($pattern, $path)) {
            $path = '/' . $path;
        }

        $ci = & get_instance();
        $cdn_enabled = $ci->config->item('cdn_enabled');
        $cdn_url = $ci->config->item('cdn_url');
        $base_url = $ci->config->item('base_url');


        if (strstr($path, '?')) {
            $cdn_version = '&ver=' . $ci->config->item('cdn_version');
        } else {
            $cdn_version = '?ver=' . $ci->config->item('cdn_version');
        }
        if ($cdn_enabled) {
            return $cdn_url . $path . $cdn_version;
        } else {
            return $base_url . $path . $cdn_version;
        }
    }
}