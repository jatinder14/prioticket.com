<?php
//print_r($_REQUEST);
include_once("nusoap.php");
$client = new nusoapclient("http://nbgroup.com.au/parv/qrMVC/index.php/soapQrcode");
//$client = new nusoapclient("http://localhost/projects/qrcodeMVC/index.php/soapQrcode");
//echo $_REQUEST['title'];
 if($client->fault)
        {
            $text = 'Error: '.$client->fault;
        }
        else
        {
		  
            if ($client->getError())
            {
                $text = 'Error: '.$client->getError();
            }
            else
            {
			$row = $client->call(
                  'getAllCodeInfo',
                   array('url' => "http://nbgroup.com.au/parv/qrMVC/Article.html",
					      'machineId' => "4106123106"
					),
                    'urn:soapQrcode',
                    'urn:soapQrcode#getAllCodeInfo'
                );
			
			/*
			 if($_REQUEST['title'] != ""){
			    $title = $_REQUEST['title'];

                $row = $client->call(
                  'titleExists',
                    array('title' => $title),
                    'urn:soapQrcode',
                    'urn:soapQrcode#titleExists'
                );
				
				if($row == false){
				  echo $tit = "Title Available";
				}
				if($row == true){
				  echo $tit = "Title Already Exists";
				}
			}
			
			if($_REQUEST['data'] != ""){
			    $allCodeData = $_REQUEST['data'];
			    $r = $client->call(
				 'saveAllCodeInfo',
				 array('allCodeData' => $allCodeData),
				 'urn:soapQrcode',
                 'urn:soapQrcode#saveAllCodeInfo'
				);
			    echo $r;
          }
		  
		  

            
			
			  */}
        }
		
 echo '<pre><h2>Request</h2>';
 print_r($row);
echo '<pre>' . htmlspecialchars($client->request, ENT_QUOTES) . '</pre>';
echo '<h2>Response</h2>';
echo '<pre>' . htmlspecialchars($client->response, ENT_QUOTES) . '</pre>';
			
?>
