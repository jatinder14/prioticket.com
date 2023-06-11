<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class CI_Hash {
        
        var $CI;
	var $encryption_key	= '';
        // --------------------------------------------------------------------

	/**
	 * Fetch the encryption key
	 *
	 * Returns it as MD5 in order to have an exact-length 128 bit key.
	 * Mcrypt is sensitive to keys that are not the correct length
	 *
	 * @access	public
	 * @param	string
	 * @return	string
	 */
	function get_key($key = '')
	{
		if ($key == '')
		{
			if ($this->encryption_key != '')
			{
				return $this->encryption_key;
			}

			$CI =& get_instance();
			$key = $CI->config->item('encryption_key');

			if ($key === FALSE)
			{
				show_error('In order to use the encryption class requires that you set an encryption key in your config file.');
			}
		}

		return md5($key);
	}
        function hash_password($string , $key = ''){
                $options = [
                    'cost' => 12,
                    'salt'=> "qwertyuiopasdfghjklzxc"
                ];
                $key = $this->get_key($key);
                return password_hash( $string,PASSWORD_DEFAULT,$options);
                
        }
}