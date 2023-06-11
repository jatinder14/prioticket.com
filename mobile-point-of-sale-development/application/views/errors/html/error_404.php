<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$filename = '404_page_not_found.log';
if (is_file('application/storage/logs/' . $filename)) {
	if (filesize('application/storage/logs/' . $filename) > 1048576) {
		rename("application/storage/logs/$filename", "application/storage/logs/" . $filename . "_" . date("m-d-Y-H-i-s") . ".log");
	}
}
$log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r: Message" . $message . ' \r\r Heading : ' . $heading . '\r\n Data => ' . json_encode(array('Server : ' => $_SERVER, 'request' => $_REQUEST));
$fp = fopen('application/storage/logs/' . $filename, 'a+');
fwrite($fp, "\n\r\n\r" . $log);
fclose($fp);

$defaultResponse = [
	"error_code" => "INTERNAL_SYSTEM_FAILURE",
	"error_message" => "Page Not found that is unexpected. Please Contact Our Support api-support@prioticket.com."
];
header("HTTP/1.0 404 Page Not Found.");
header('Content-Type: application/json');
echo json_encode($defaultResponse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>