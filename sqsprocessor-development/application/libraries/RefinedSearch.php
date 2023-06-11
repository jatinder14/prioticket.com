<?php
/*
This class is for QRcodes
Name  Refinedsearch
in follwing way this search class will work
		$subArrUsr[]="star";
		$subArrUsr[]="voted";
		$user['tabl']="users";
		$user['field']="usertype";
		$user['subArr']=$subArrUsr;
		$age['tabl']="users";
		$age['field']="age";
		$age['subArr']=$subArrUsr;
		$gender['tabl']="user";
		$gender['field']="gender";
		$gender['subArr']=$subArrUsr;
		
		$rootar[]=$user;
		$rootar[]=$age;
		$rootar[]=$gender;
		echo '<pre>';
		print_r($rootar);
		$this->load->library('refinedsearch');
echo	$this->refinedsearch->getSearch($rootar);
*/
class CI_Refinedsearch
{
/*
*var rootArr
root elements in search options
*/
var	$rootArr; 


/*
*var errorMsg
error reporting in class errorMsg
*/
var	$errorMsg;

/*constructor */
	function __construct()
	{
		$this->rootArr;
	
	}
	
/*	getSearch
param1	rootArr
param2	subArr 
*/

	function getSearch($rootArr='')
	{
		$this->rootArr=$rootArr;
			
		is_array($rootArr) ? $this->errorMsg.='' : $this->errorMsg.='#srcErr11 parameter First not an Array';
	
		
		if($this->errorMsg)
		{
			return $this->errorMsg;
		}
		else
		{
		return 	$this->makeSearchString($rootArr);
		}

	}	
	
	function makeSearchString($rootArr='')
	{
	
		foreach($rootArr as $key=>$arr)
		{
			
			is_array($arr) ? $this->errorMsg.='' : $this->errorMsg.='#srcErr13 parameters First not an Array';
			if($this->errorMsg)
			{
				return $this->errorMsg;
			}
			else
			{
					//$tblfeild=$arr['tabl'].".".$arr['field'];
					if(isset($arr))
					if(is_array($arr))
					{
					$subqry=$this->makeSubString($arr);
					$newArr[]="($subqry)";
					}
			}
		}
			return  $tblfeild=implode(" AND ",$newArr);
	}
	
	function makeSubString($subArr='')
	{
		foreach($subArr as $key=>$arr)
		{
					
				$pos = strpos($key, "#");
				if($pos)
				{
					list($key2,$val)=split("#",$key);
					if($key2=="rvuc.vid")
					{
						//$newArr[]="$key2 > '$arr'";
						$newArr[]="`rscu`.`usr_id` = `rvuc`.`usr_id`";
					}
					else
					{
						$newArr[]="$key2 = '$arr'";
					}
				}
			
		}
					/*if($key2=="rvuc.vid" && count($subArr)==1)
					{$tblfeild=1;}
					else
					{
					}*/
					$tblfeild=implode(" OR ",$newArr);
					
					
			return  $tblfeild;
	}
	
	
	
	
	
}//end of the class here 
// END QRcode class

/* End of file QRcode.php */
/* Location: /application/libraries/Input.php */