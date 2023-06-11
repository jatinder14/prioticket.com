

<?php
class Navigation{

var  $mainM;//array
var $subM;//array
var $topMainM;
var $topSubM;
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
			}*/
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
	
	function topMenu()
	{
	  $arr['main']=$this->topMainM;
	 $arr['sub']=$this->topSubM;
	// print_r($arr);
	  return $this->showMenu($arr);
	
	}
	function leftMenu()
	{
	  $arr['main']=$this->mainM;
	 $arr['sub']=$this->subM;
	//print_r($arr['main']);
	 return $this->showMenu($arr);
	  
	}
   
   function showMenu(){
     // global $mainMenu,$subMenu;
	
      $mainMenu=$this->mainM;
	  $subMenu=$this->subM;
	 // print_r($mainMenu);
    $actualPage = $_SERVER['PHP_SELF'];
   $actualPath = $_SERVER['REQUEST_URI'];
      
     $actualPageName = basename($actualPage);
      //echo $page;
      
      //echo "$actualPage <br/> $actualPath";
      $actMenu = '';
	
   	foreach ($mainMenu as $menu => $link) {
	
		    if ($link == $actualPageName)  $actMenu = $menu;		
		    if (isset($subMenu[$menu])){
		       foreach ($subMenu[$menu] as $menuSub => $linkSub) {
 		   	     if ($linkSub == $actualPageName) $actMenu = $menu;		
		       }
		    }
	    }
	
	
	    //Now display the menu
		 $mAnch=''; 
		 $subAnch=''; 
		 
	    foreach ($mainMenu as $menu => $link) 
		{
		  //echo $menu;
		 
			$blink= basename($link);
	      $class = ' class="mainMenuLink" '; 
	      if ($actualPageName == $blink) $class=' class="mainMenuLinkSelected" '; 
	      
		   $mAnch.='<li><a'.$class.'href="'.$link.'">'.$menu.'</a></li>';
		   
		   if ( ($actMenu == $menu) && (isset($subMenu[$menu])) ){
		  	 
		       foreach ($subMenu[$menu] as $menuSub => $linkSub) {
                 $class = ' class="subMenuLink" '; 
	              if ($actualPageName == $linkSub) $class=' class="subMenuLinkSelected" '; 
 		   	      $subAnch.='<li><a'.$class.'href="'.$linkSub.'">'.$menuSub.'</a></li>';
		       }
			  $mAnch.="<li><ul>$subAnch</ul></li>";    
		    }
	  }
    return   $mAnch="<ul>$mAnch</ul>"; 
      
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