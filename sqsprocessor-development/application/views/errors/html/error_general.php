<?php                  
$filename = 'error_general.php';
if (is_file('application/storage/logs/'.$filename)) {
    if (filesize('application/storage/logs/'.$filename) > 1048576) {                    
        rename("application/storage/logs/$filename", "application/storage/logs/".$filename."_". date("m-d-Y-H-i-s") . ".php");
    }
}
$log = 'Time: ' . date('m/d/Y H:i:s') . "\r\r:" . $message . '';
$fp = fopen('application/storage/logs/'.$filename, 'a');
fwrite($fp, "\n\r\n\r\n\r" . $log);
fclose($fp);
?>
<html>
<head>
<title>PrioTicket</title>
<link rel="shortcut icon" href="<?php echo SERVER_URL; ?>/qrcodes/images/prioticket_favicon.ico" />
<link rel="stylesheet" href="<?php echo SERVER_URL; ?>/qrcodes/fonts/helveticaneue/helveticaneue.css" />
<link rel="stylesheet" href="<?php echo SERVER_URL; ?>/qrcodes/fonts/font-icons/font-awesome/css/font-awesome.min.css" />
<style type="text/css">
    .error-code { max-width: 980px; margin: 50px auto; padding: 0 15px; font-family: 'HelveticaNeue'; }
    .error-code .brand { margin: 0 0 50px; }
    .error-code .brand img { width: 200px; }
    .error-code .left { float: left; width: 100%; }
    .error-code .right { float: right; width: 100%; text-align: center; padding-top: 50px; }
    .error-code .right img { max-width: 220px; }
    .error-code h1 { font-size: 40px; line-height: 48px; margin: 10px 0; }
    .error-code h2 { font-size: 28px; line-height: 34px; margin: 20px 0 15px; font-weight: normal; }
    .error-code p { font-size: 16px; line-height: 20px; margin:  10px 0 20px; }
    .error-code i { position: relative; top:2px; }
    .error-code a { color: #1cb8c3; text-decoration: none; }
    .error-code a:hover { color: #1cb8c3; text-decoration: underline }
    .clear { clear: both; }
    @media(min-width:768px) {
        .error-code .left,
        .error-code .right { width: 50%;  }
        .error-code .right { padding-top: 140px; }
    }
</style>
</head>
<body>


<div class="error-code">
    <div class="brand">
        <img src="<?php echo SERVER_URL ?>/assets/images/prioticket_logo_black.png" alt="logo">
    </div>
    <div class="clearfix">
        <div class="left">
            <h1>Oops!</h1>
            <h2>Well, this is unexpected... </h2>
            <p>Error code:500</p>
            <p>An error has occurred and we're working to fix the problem! We'll be up and running shortly.</p>
            <p>If you need immediate help from our customer service team about an ongoing reservation, please call us. If it isn't an urgent matter,
        please visit our <a href="https://support.prioticket.com/">support.prioticket.com</a> for additional information. Thanks for your patience! </p>
            <p>For urgent situations please call us &nbsp; <i class="fa fa-phone"></i> <a href="tel:+31880008830">+31 88 000 8830</a> </p>
        </div>
        <div class="right">
            <img src="<?php echo SERVER_URL ?>/assets/images/error-code.svg" alt="error-code">
        </div>
        <div class="clear"></div>
    </div>
    <?php if(PAYMENT_MODE=='test'){ ?>
    <div>Error message: <?php echo $message; ?></div>
    <?php } ?>
</div>
</body>
</html>