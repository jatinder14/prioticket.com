<?php
require_once('class/BCGFontFile.php');
require_once('class/BCGColor.php');
require_once('class/BCGDrawing.php');
require_once('class/BCGqrcode.barcode2d.php');

// Don't forget to sanitize user inputs
$text = isset($_GET['text']) ? $_GET['text'] : 'QRCode';

// Label, this part is optional
$label = new BCGLabel();
$label->setFont(new BCGFontFile('./font/Arial.ttf', 11));
$label->setPosition(BCGLabel::POSITION_BOTTOM);
$label->setAlignment(BCGLabel::ALIGN_CENTER);
$label->setText($text);

// The arguments are R, G, B for color.
$color_black = new BCGColor(0, 0, 0);
$color_white = new BCGColor(255, 255, 255);

$drawException = null;
try {
    // QRCode Part
    $code = new BCGqrcode();
    $code->setScale(3);
    $code->setSize(BCGqrcode::QRCODE_SIZE_FULL);
    $code->setErrorLevel('M'); // Error correction level
    $code->setMirror(false);
    $code->setQuietZone(true);
    $code->setForegroundColor($color_black); // Color of bars
    $code->setBackgroundColor($color_white); // Color of spaces

    // Remove the following line if you don't want any label
    $code->addLabel($label);

    $code->parse($text);
} catch(Exception $exception) {
    $drawException = $exception;
}

// Drawing Part
$color_white = new BCGColor(255, 255, 255);
$drawing = new BCGDrawing('', $color_white);
if($drawException) {
    $drawing->drawException($drawException);
} else {
    $drawing->setBarcode($code);
    $drawing->draw();
}

// Header that says it is an image (remove it if you save the barcode to a file)
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="barcode.png"');

$drawing->finish(BCGDrawing::IMG_FORMAT_PNG);
?>