<?php 
 if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Commonfunctions{


	function Commonfunctions(){
		$CI =& get_instance();
	$CI->load->helper('url');
	//print_r($CI);
	$CI->config->item('base_url');
	
	}
	function getYearOfDates($filterFrom,$filterTo){
	//echo $filterFrom ." ,".$filterTo;
		$timeFrom=$filterFrom;
		$timeFrom=strtotime($timeFrom);
		$dateYear[]=date('Y',$timeFrom);
		$timeTo=$filterTo;
		$timeTo=strtotime($timeTo);
		$dateYear[]= date('Y',$timeTo);
		for($i=$dateYear[0]; $i<=$dateYear[1]; $i=$i+1){
		$xrange[]=$i;
		}
		return $xrange;
	}
	
	
	function getMonthOfDates($filterFrom,$filterTo){
	//echo $filterFrom ." ,".$filterTo;
	
		$timeFrom=$filterFrom;
		$timeFrom=strtotime($timeFrom);
		$monthYear[]=date("M-Y",$timeFrom);
		$timeTo=$filterTo;
		$timeTo=strtotime($timeTo);
		$monthYear[]= date("Y-M",$timeTo);
		$dateDiff=$timeTo-$timeFrom;
		$dayRange = floor($dateDiff/(60*60*24*30));
		$xrange=array();
		for($i=0; $i<$dayRange; $i=$i+1){
		$xrange[]=date("M-Y",strtotime($monthYear[0]."+$i months"));
		//date($monthYear[0],strtotime("+".$i." months"));
		//$xrange[]=$i;
		}
		return $xrange;
	}
	
	
	function dateDiff($dformat, $endDate, $beginDate)
	{
	$date_parts1=explode($dformat, $beginDate);
	$date_parts2=explode($dformat, $endDate);
	$start_date=gregoriantojd($date_parts1[0], $date_parts1[1], $date_parts1[2]);
	$end_date=gregoriantojd($date_parts2[0], $date_parts2[1], $date_parts2[2]);
	echo $dateDiff= $end_date - $start_date;
	return $dateDiff;
	}
	

	function yAxisData($max){
	
	$rem=$max%6;
	$rem=6-$rem;
	$max=$max+$rem;
	$step=$max/6;
	/*for($i=0; $i<=6; $i++){
	$step1[]=$step*$i;
	}*/
		return $step;
	
	}
	function graphLine($options){
		
	//	foreach()
	
	}
	function getArrayValues($arr,$cont){
	
		for($i=0; $i<$cont; $i++){
			$newArr[]=$arr[$i];			
		}
		return $newArr;
	}
	function monthCal(){
		$month='<option value="01">Jan</option>'.'<option value="02">Feb</option>'.'<option value="03">Mar</option>'.'<option value="04">Apr</option>'.'<option value="05">May</option>'.'<option value="06">Jun</option>'.'<option value="07">Jul</option>'.'<option value="08">Aug</option>'.'<option value="09" selected>Sep</option>'.'<option value="10">Oct</option>'.'<option value="11">Nov</option>'.'<option value="12">Dec</option>';
		$date ="";
		for($i=1; $i<=31;$i++){
		$date .='<option value="'.$i.'">'.$i.'</option>';
		}
		
		$year ="";
		for($y=1999; $y<=2011;$y++){
		$year .='<option value="'.$y.'">'.$y.'</option>';
		}
		$cal = array('date'=>$date,'year'=>$year,'month'=>$month);
		return $cal;
	}
	
	
	function secondsToTime($seconds)
	{
		// extract days
		$days = floor($seconds / (24 * 60 * 60));
		$divisor_for_hrs = $seconds % (24 * 60 * 60);
		
		// extract hours
		
		$hours = floor($divisor_for_hrs / (60 * 60));
	 
		// extract minutes
		$divisor_for_minutes = $seconds % (60 * 60);
		$minutes = floor($divisor_for_minutes / 60);
	 
		// extract the remaining seconds
		$divisor_for_seconds = $divisor_for_minutes % 60;
		$seconds = ceil($divisor_for_seconds);
	 
		// return the final array
		$obj = array(
			"d" => (int) $days,
			"h" => (int) $hours,
			"m" => (int) $minutes,
			"s" => (int) $seconds,
		);
		return $obj;
	}
	
}
?>