<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/*$filename = 'php_errors.log';
if (is_file('application/storage/logs/' . $filename)) {
	if (filesize('application/storage/logs/' . $filename) > 1048576) {
		rename("application/storage/logs/$filename", "application/storage/logs/" . $filename . "_" . date("m-d-Y-H-i-s") . ".log");
	}
}
$log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r: message : " . $message . ' \n\r\r: severity : ' . $severity . ' \n\r\r: filepath : ' . $filepath . ' \n\r\r: file name : ' . $line;
$fp = fopen('application/storage/logs/' . $filename, 'a+');
fwrite($fp, "\n\r\n\r\n\r" . $log);
fclose($fp);*/
?>

<div style="border:1px solid #990000;padding-left:20px;margin:0 0 10px 0;">

<h4>A PHP Error was encountered</h4>

<p>Severity: <?php echo $severity; ?></p>
<p>Message:  <?php echo $message; ?></p>
<p>Filename: <?php echo $filepath; ?></p>
<p>Line Number: <?php echo $line; ?></p>

<?php if (defined('SHOW_DEBUG_BACKTRACE') && SHOW_DEBUG_BACKTRACE === TRUE): ?>

	<p>Backtrace:</p>
	<?php foreach (debug_backtrace() as $error): ?>

		<?php if (isset($error['file']) && strpos($error['file'], realpath(BASEPATH)) !== 0): ?>

			<p style="margin-left:10px">
			File: <?php echo $error['file'] ?><br />
			Line: <?php echo $error['line'] ?><br />
			Function: <?php echo $error['function'] ?>
			</p>

		<?php endif ?>

	<?php endforeach ?>

<?php endif ?>

</div>