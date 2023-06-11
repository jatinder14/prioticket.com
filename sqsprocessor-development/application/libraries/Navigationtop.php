

<?php
class Navigationtop{

var  $topMainM;//array
var $topSubM;//array

	function __construct($params='')
	{
	
		if (count($params) > 0)
		{
			$this->initialize($params);
		}

			/*$this->mainMenu=$main;
			if($sub)
			{
			$this->subMenu=$sub;
			sss}*/
	}


	function initialize($params = '')
	{
		if (count($params) > 0)
		{
			foreach ($params as $key => $val)
			{
				$this->$key = $val;
				
			}
		}
	}
   
   function showMenu(){

   // global $mainMenu,$subMenu;
      $mainMenu=$this->topMainM;
	  $subMenu=$this->topSubM;
	  //print_r($mainMenu);
    $actualPage = $_SERVER['PHP_SELF'];
    $actualPath = $_SERVER['REQUEST_URI'];
    $actualPageName = basename($actualPage);
    //echo $page;
      
    //echo "$actualPage <br/> $actualPath";
    $actMenu = '';
	
   	/*foreach ($mainMenu as $menu => $link)
	 {
	
		  //  if ($link == $actualPageName)  
			$actMenu = $menu;		
		    if (isset($subMenu[$menu]))
			{
			  foreach ($subMenu[$menu] as $menuSub => $linkSub) 
			   {
			  	
 		   	   //  if ($linkSub == $actualPageName) 
					 $actMenu[$menu]= $menu;		
		       }
		    }
	  }
	*/
		foreach ($mainMenu as $menu => $link) {
	
		    if ($link == $actualPageName)  $actMenu = $menu;		
		    if (isset($subMenu[$menu])){
		       foreach ($subMenu[$menu] as $menuSub => $linkSub) {
 		   	     if ($linkSub == $actualPageName) $actMenu = $menu;		
		       }
		    }
	    }
	    // Now display the menu
		 $mAnch=''; 
		 $subAnch='';
		  
	    foreach ($mainMenu as $menu => $link) 
		{
	      $class = ' class="tabs" '; 
	      if ($actualPageName == $link) $class=' class="tabs" '; 
	      
		   $mAnch.='<a'.$class.'href="'.$link.'">'.$menu.'</a>';
		   
		  // if ( ($actMenu == $menu) && (isset($subMenu[$menu])) )
	 
		  if ( isset($subMenu[$menu]) )
		   { 
		 
			$subAnch=null;
		  	 foreach ($subMenu[$menu] as $menuSub => $linkSub) 
			   {
			   	
                  $class = ' class="subMenuLink" '; 
	              if ($actualPageName == $linkSub) $class=' class="subMenuLinkSelected" '; 
 		   	      $subAnch.='<a'.$class.'href="'.$linkSub.'">'.$menuSub.'</a>';
		       }
			  $mAnch.="$subAnch";    
		    
		   }
		
	  }
  
    return   $mAnch="$mAnch"; 
      
   }

}


/*	// Main menu items
	$mainMenu['Home']      = 'index.php';
	$mainMenu['Projects']  = 'projects.php';
	$mainMenu['About us']  = 'about.php';
	
	// Sub menu items
	$subMenu['Projects']['Product-1'] = 'product1.php';
	$subMenu['Projects']['Product-2'] = 'product2.php';
	
	$subMenu['About us']['Staff-1']   = 'staff1.php';


$navi = new maxNavigation($mainMenu,$subMenu);
$navi->showMenu();*/

?>