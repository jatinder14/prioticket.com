<?php 
	class Error
	{
		
		function errors($para)
		{
		 $er='<div  id="headr" class="err"><div style="text-align:center">'.$para.'</div></div>';
		 $er.='<script>setTimeout(function(){ $("#headr").hide();},60000)</script>';
		 return $er;		  
		}
	}
?>