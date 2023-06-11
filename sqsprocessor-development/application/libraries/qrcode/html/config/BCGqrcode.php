<?php
$classFile = 'BCGqrcode.barcode2d.php';
$className = 'BCGqrcode';
$baseClassFile = 'BCGBarcode2D.php';
$codeVersion = '4.1.0';
$defaultScale = 4;

function customSetup($barcode, $get) {
    if (isset($get['quietzone'])) {
        $barcode->setQuietZone($get['quietzone'] === '1' ? true : false);
    }
    if (isset($get['size'])) {
        $barcode->setSize(intval($get['size']));
    }
    if (isset($get['mirror'])) {
        $barcode->setMirror($get['mirror'] === '1' ? true : false);
    }
    if (isset($get['errorlevel'])) {
        $barcode->setErrorLevel(intval($get['errorlevel']));
    }
    if (isset($get['qrsize'])) {
        $qrsize = explode('x', $get['qrsize']);
        $barcode->setQRSize($qrsize[1], $qrsize[0] === "1" ? true : false);
    }
}
?>