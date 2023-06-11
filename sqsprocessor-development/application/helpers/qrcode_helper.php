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
if ( ! function_exists('read_file'))
{
	function read_file($file)
	{
		if ( ! file_exists($file))
		{
			return FALSE;
		}
	
		if (function_exists('file_get_contents'))
		{
			return file_get_contents($file);		
		}

		if ( ! $fp = @fopen($file, FOPEN_READ))
		{
			return FALSE;
		}
		
		flock($fp, LOCK_SH);
	
		$data = '';
		if (filesize($file) > 0)
		{
			$data =& fread($fp, filesize($file));
		}

		flock($fp, LOCK_UN);
		fclose($fp);

		return $data;
	}
}
	
// ------------------------------------------------------------------------


if ( ! function_exists('resizeMarkup'))
{
	function resizeMarkup($markup, $dimensions)
	{
		$w = $dimensions['width'];
		$h = $dimensions['height'];
		
		$patterns = array();
		$replacements = array();
		if( !empty($w) )
		{
		$patterns[] = '/width="([0-9]+)"/';
			$patterns[] = '/width:([0-9]+)/';
			$replacements[] = 'width="'.$w.'"';
			$replacements[] = 'width:'.$w;
			}
		
		if( !empty($h) )
		{
			$patterns[] = '/height="([0-9]+)"/';
			$patterns[] = '/height:([0-9]+)/';
			
			$replacements[] = 'height="'.$h.'"';
			$replacements[] = 'height:'.$h;
		}
			$patterns[] = '/width="([0-9]+)"/';
			$replacements[] = ' wmode="transparent" width="'.$w.'"';
			
			$patterns[] = '/<embed /';
			$replacements[] = '<param name="wmode" value="transparent"><embed ';
			
		return preg_replace($patterns, $replacements, $markup);
	}
}
	

if ( ! function_exists('remove_http'))
{
	function remove_http($url = '')
	{
	   $list = array('http://', 'https://');
		foreach ($list as $word)
			if (strncasecmp($url, $word, strlen($word)) == 0)
				return substr($url, strlen($word));
		return $url;
	}
}
	
/* End of file file_helper.php */
/* Location: ./system/helpers/file_helper.php *////