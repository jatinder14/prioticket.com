<?php
/*
This class is for QRcodes
Name  QRcode
*/

class CI_Qrcode
{
	/*variables are following*/
		/*
		var width
		adjust the generated pixels of qrCodes
		*/
		var $width="";

		/*
		var Url
		Url For website
		*/
		var $url="";

		/*
		var params
		Parameter that will generate qrCode
		In this case only Title will genrate Qrcode
		*/
		var $params="";



/*functions are following*/
		
		/*
		Construtor with a parameter
		Parameter that is site url
		return: generated Qrcode Image
		*/
		function __construct($surl)
		{	
			$this->url=$surl['url'];
		}
		
		/*
		genarateQrcode with a parameter
		Parameters are
		url,width,param,id
		*/
		function genarate_Qrcode($width,$params)
		{
			$siteUrl=$this->url;
			$title=$params;
			$this->width=$width;
			$this->params=$params;
			$url= "taqbox.com/".$title;
			$percut=(13*$width)/100;
			if($width<250)
			{
				$lblwidth=($width-(2*$percut));$siz=12+(($width-180)/10);$fixsiz=$width;
			}
			else
			{
				$lblwidth=199.06;$siz=20.8;$fixsiz=250;
			}
			
			//http://chart.apis.google.com/chart?cht=qr&chs=250x250&chl=taqbox.com/abcdef
			//http://api.qrserver.com/v1/create-qr-code/?data=taqbox.com/abcdef&size=250x250	
			
			//$qrImg='<img id="qrcodeImgId" style="width:'.$fixsiz.'px;height:'.$fixsiz.'px;" src="http://chart.apis.google.com/chart?cht=qr&chs='.$width.'x'.$width.'&chl='.$url.'">';
			$qrImg='<img id="qrcodeImgId" style="width:'.$fixsiz.'px;height:'.$fixsiz.'px;" src="http://api.qrserver.com/v1/create-qr-code/?data='.$url.'&size='.$width.'x'.$width.'">';
			$qrImg .= '<div id="txt_colorLabel" style="width:'.$lblwidth.'px;font-size:'.$siz.'px;color:#FFF;background-color:#000; margin:0 auto;">save article</div>';
			$qrImg .= '*<div id="slider_size" style="text-align:center; margin:10px 0 0 62px;">Size '.$width.' x '.$width.'px</div>';
			$qrImg .='*'.$width;
			
			return $qrImg;
		}
}
// END QRcode class

/* End of file QRcode.php */
/* Location: /application/libraries/Input.php */