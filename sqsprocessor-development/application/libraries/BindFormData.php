<?php
/*
This class is for binding form data
Name  BindFormData
*/

class BindFormData
{
		var $dbo='';
		
	/**
	*	function bind
	*	params $post, form fields data
	*	$tables, table name
	*	$dbo, database object
	*	returns binded data for each table
	*
	*/
	
	function bind($post, $tables, $dbo)
	{
		$form = '';
		foreach($tables as $table )
		{
			$fields = $dbo->list_fields($table);
			if(count($fields)>0)
			{				
				foreach($fields as $k=>$v)
				{
					if(isset($post[$v]))
					{
						$form[$table][$v] = is_array($post[$v]) ? serialize($post[$v]) : $post[$v];
					}
				}
			}
		}
		return $form;
	}
	
	function checkDefaultValue($post)
	{
		if(count($post)>0)
		{
			foreach($post as $k=>$v)
			{
				if($post[$k] == lang($k))
				{
					$post[$k] = '';					
				}						
			}			
		}
		return $post;
	}
}

?>