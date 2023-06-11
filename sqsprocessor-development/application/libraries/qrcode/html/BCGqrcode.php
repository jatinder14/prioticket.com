<?php
define('IN_CB', true);
include('include/header.php');

$default_value['size'] = '3';
$size = isset($_POST['size']) ? $_POST['size'] : $default_value['size'];
registerImageKey('size', $size);

$default_value['qrsize'] = '0x-1';
$qrsize = isset($_POST['qrsize']) ? $_POST['qrsize'] : $default_value['qrsize'];
registerImageKey('qrsize', $qrsize);

$default_value['errorlevel'] = '1';
$errorlevel = isset($_POST['errorlevel']) ? $_POST['errorlevel'] : $default_value['errorlevel'];
registerImageKey('errorlevel', $errorlevel);

$default_value['mirror'] = '0';
$mirror = isset($_POST['mirror']) ? $_POST['mirror'] : $default_value['mirror'];
registerImageKey('mirror', $mirror);

$default_value['quietzone'] = '1';
$quietzone = isset($_POST['quietzone']) ? $_POST['quietzone'] : ($_SERVER['REQUEST_METHOD'] === 'POST' ? '0' : $default_value['quietzone']);
registerImageKey('quietzone', $quietzone);

registerImageKey('code', 'BCGqrcode');

$vals = array();
for($i = 0; $i <= 127; $i++) {
    $vals[] = '%' . sprintf('%02X', $i);
}
$characters = array(
    'NUL', 'SOH', 'STX', 'ETX', 'EOT', 'ENQ', 'ACK', 'BEL', 'BS', 'TAB', 'LF', 'VT', 'FF', 'CR', 'SO', 'SI', 'DLE', 'DC1', 'DC2', 'DC3', 'DC4', 'NAK', 'SYN', 'ETB', 'CAN', 'EM', 'SUB', 'ESC', 'FS', 'GS', 'RS', 'US',
    '&nbsp;', '!', '"', '#', '$', '%', '&', '\'', '(', ')', '*', '+', ',', '-', '.', '/', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':', ';', '<', '=', '>', '?',
    '@', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', '[', '\\', ']', '^', '_',
    '`', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '{', '|', '}', '~', 'DEL'
);
?>

<ul id="specificOptions">
    <li class="option">
        <div class="title">
            <label for="size">Size</label>
        </div>
        <div class="value">
            <?php echo getSelectHtml('size', $size, array('1' => 'Smallest', '2' => 'Micro', '3' => 'Full')); ?>
        </div>
    </li>
    <li class="option">
        <div class="title">
            <label for="qrsize">QRCode Size</label>
        </div>
        <div class="value">
            <?php echo getSelectHtml('qrsize', $qrsize,
                array('0x-1' => 'Automatic',
                    'Full' => array('0x1' => '1', '0x2' => '2', '0x3' => '3', '0x4' => '4', '0x5' => '5', '0x6' => '6', '0x7' => '7', '0x8' => '8', '0x9' => '9', '0x10' => '10', '0x11' => '11', '0x12' => '12', '0x13' => '13', '0x14' => '14', '0x15' => '15', '0x16' => '16', '0x17' => '17', '0x18' => '18', '0x19' => '19', '0x20' => '20', '0x21' => '21', '0x22' => '22', '0x23' => '23', '0x24' => '24', '0x25' => '25', '0x26' => '26', '0x27' => '27', '0x28' => '28', '0x29' => '29', '0x30' => '30', '0x31' => '31', '0x32' => '32', '0x33' => '33', '0x34' => '34', '0x35' => '35', '0x36' => '36', '0x37' => '37', '0x38' => '38', '0x39' => '39', '0x40' => '40'),
                    'Micro' => array('1x1' => '1', '1x2' => '2', '1x3' => '3', '1x4' => '4')
                )); ?>
        </div>
    </li>
    <li class="option">
        <div class="title">
            <label for="errorlevel">Error Level</label>
        </div>
        <div class="value">
            <?php echo getSelectHtml('errorlevel', $errorlevel, array(0 => 'L = 0', 1 => 'M = 1', 2 => 'Q = 2', 3 => 'H = 3')); ?>
        </div>
    </li>
    <li class="option">
        <div class="title">
            <label for="mirror">Mirror</label>
        </div>
        <div class="value">
            <?php echo getCheckboxHtml('mirror', $mirror, array('value' => 1)); ?>
        </div>
    </li>
    <li class="option">
        <div class="title">
            <label for="quietzone">Quiet Zone</label>
        </div>
        <div class="value">
            <?php echo getCheckboxHtml('quietzone', $quietzone, array('value' => 1)); ?>
        </div>
    </li>
</ul>

<div id="validCharacters">
    <h3>Valid Characters</h3>
    <?php $c = count($characters); for ($i = 0; $i < $c; $i++) { echo getButton($characters[$i], $vals[$i]); } ?>
</div>

<div id="explanation">
    <h3>Explanation</h3>
    <ul>
        <li>Capable of containing virtually any data desired.</li>
        <li>Popular for its use in mobile tagging and data sharing.</li>
        <li>Can contain large amounts of data and can be spread throughout multiple barcodes..</li>
        <li>Micro and Standard versions can be used to vary the size of the barcode.</li>
        <li>Your browser may not be able to write the special characters (NUL, SOH, etc.) but you can write them with the code.</li>
    </ul>
</div>

<?php
include('include/footer.php');
?>