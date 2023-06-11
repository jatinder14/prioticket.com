<?php
defined('BASEPATH') || exit('No direct script access allowed');
$filename = 'functionality_error_log.log';
if (is_file('application/storage/logs/' . $filename)) {
	if (filesize('application/storage/logs/' . $filename) > 1048576) {
		rename("application/storage/logs/$filename", "application/storage/logs/" . $filename . "_" . date("m-d-Y-H-i-s") . ".log");
	}
}
$log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r: message : " . $message . ' \n\r\r: severity : ' . $severity . ' \n\r\r: filepath : ' . $filepath . ' \n\r\r: file name : ' . $line . ' \r\r Heading : ' . $heading;
$fp = fopen('application/storage/logs/' . $filename, 'a+');
fwrite($fp, "\n\r\n\r" . $log);
fclose($fp);

$defaultResponse = [
	"error_code" => "INTERNAL_SYSTEM_FAILURE",
	"error_message" => "Exception Occurred that is unexpected. Please Contact Our Support api-support@prioticket.com."
];
header("HTTP/1.0 500 Internal Server Error.");
header('Content-Type: application/json');
echo json_encode($defaultResponse, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
