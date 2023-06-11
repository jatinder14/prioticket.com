<?php
/**
 *--------------------------------------------------------------------
 *
 * Class to create QRCode barcode.
 * This class doesn't support:
 *   - Kanji.
 *
 *--------------------------------------------------------------------
 * Copyright (C) Jean-Sebastien Goupil
 * http://www.barcodephp.com
 */
include_once('BCGArgumentException.php');
include_once('BCGDrawException.php');
include_once('BCGParseException.php');
include_once('BCGBarcode.php');
include_once('BCGBarcode2D.php');

class BCGqrcode extends BCGBarcode2D {
    const DEBUG = false;

    const QRCODE_SIZE_SMALLEST = 1;
    const QRCODE_SIZE_MICRO = 2;
    const QRCODE_SIZE_FULL = 3;

    const QRCODE_FNC1_NONE = 0;
    const QRCODE_FNC1_GS1 = 1;
    const QRCODE_FNC1_AIM = 2;

    const _GF = 256;

    private $data;                      // _BCGqrcode_Pixel[][]

    private $errorLevel;                // int (0=L, 1=M, 2=Q, 3=H)
    private $size;                      // int (QRCODE_SIZE_*)
    private $quietZone;                 // bool
    private $mirror;                    // bool
    private $mask;                      // int (0-8)
    private $qrSize;                    // int (micro: 1-4, full: 1-40)
    private $qrMicro;                   // bool
    private $symbolNumber;              // int (for Structured Append)
    private $symbolTotal;               // int (for Structured Append)
    private $symbolData;                // string (for Structured Append)

    private $fnc1;                      // int
    private $fnc1Id;                    // int

    private $acceptECI;                 // bool

    private $symbols;                   // _BCGqrcode_Info[]
    private $symbol;                    // _BCGqrcode_Info (current symbol)

    private $modeByteExcl;              // string (supported data)

    private $hasECI = false;            // bool

    private $alignmentPatterns = array(
        1 => array(),
        array(6, 18),
        array(6, 22),
        array(6, 26),
        array(6, 30),
        array(6, 34),
        array(6, 22, 38),
        array(6, 24, 42),
        array(6, 26, 46),
        array(6, 28, 50),
        array(6, 30, 54),
        array(6, 32, 58),
        array(6, 34, 62),
        array(6, 26, 46, 66),
        array(6, 26, 48, 70),
        array(6, 26, 50, 74),
        array(6, 30, 54, 78),
        array(6, 30, 56, 82),
        array(6, 30, 58, 86),
        array(6, 34, 62, 90),
        array(6, 28, 50, 72, 94),
        array(6, 26, 50, 74, 98),
        array(6, 30, 54, 78, 102),
        array(6, 28, 54, 80, 106),
        array(6, 32, 58, 84, 110),
        array(6, 30, 58, 86, 114),
        array(6, 34, 62, 90, 118),
        array(6, 26, 50, 74, 98, 122),
        array(6, 30, 54, 78, 102, 126),
        array(6, 26, 52, 78, 104, 130),
        array(6, 30, 56, 82, 108, 134),
        array(6, 34, 60, 86, 112, 138),
        array(6, 30, 58, 86, 114, 142),
        array(6, 34, 62, 90, 118, 146),
        array(6, 30, 54, 78, 102, 126, 150),
        array(6, 24, 50, 76, 102, 128, 154),
        array(6, 28, 54, 80, 106, 132, 158),
        array(6, 32, 58, 84, 110, 136, 162),
        array(6, 26, 54, 82, 110, 138, 166),
        array(6, 30, 58, 86, 114, 142, 170)
    );

    private $logTable = array(-255, 255, 1, 25, 2, 50, 26, 198, 3, 223, 51, 238, 27, 104, 199, 75, 4, 100, 224, 14, 52, 141, 239, 129, 28, 193, 105, 248, 200, 8, 76, 113, 5, 138, 101, 47, 225, 36, 15, 33, 53, 147, 142, 218, 240, 18, 130, 69, 29, 181, 194, 125, 106, 39, 249, 185, 201, 154, 9, 120, 77, 228, 114, 166, 6, 191, 139, 98, 102, 221, 48, 253, 226, 152, 37, 179, 16, 145, 34, 136, 54, 208, 148, 206, 143, 150, 219, 189, 241, 210, 19, 92, 131, 56, 70, 64, 30, 66, 182, 163, 195, 72, 126, 110, 107, 58, 40, 84, 250, 133, 186, 61, 202, 94, 155, 159, 10, 21, 121, 43, 78, 212, 229, 172, 115, 243, 167, 87, 7, 112, 192, 247, 140, 128, 99, 13, 103, 74, 222, 237, 49, 197, 254, 24, 227, 165, 153, 119, 38, 184, 180, 124, 17, 68, 146, 217, 35, 32, 137, 46, 55, 63, 209, 91, 149, 188, 207, 205, 144, 135, 151, 178, 220, 252, 190, 97, 242, 86, 211, 171, 20, 42, 93, 158, 132, 60, 57, 83, 71, 109, 65, 162, 31, 45, 67, 216, 183, 123, 164, 118, 196, 23, 73, 236, 127, 12, 111, 246, 108, 161, 59, 82, 41, 157, 85, 170, 251, 96, 134, 177, 187, 204, 62, 90, 203, 89, 95, 176, 156, 169, 160, 81, 11, 245, 22, 235, 122, 117, 44, 215, 79, 174, 213, 233, 230, 231, 173, 232, 116, 214, 244, 234, 168, 80, 88, 175);
    private $aLogTable = array(1, 2, 4, 8, 16, 32, 64, 128, 29, 58, 116, 232, 205, 135, 19, 38, 76, 152, 45, 90, 180, 117, 234, 201, 143, 3, 6, 12, 24, 48, 96, 192, 157, 39, 78, 156, 37, 74, 148, 53, 106, 212, 181, 119, 238, 193, 159, 35, 70, 140, 5, 10, 20, 40, 80, 160, 93, 186, 105, 210, 185, 111, 222, 161, 95, 190, 97, 194, 153, 47, 94, 188, 101, 202, 137, 15, 30, 60, 120, 240, 253, 231, 211, 187, 107, 214, 177, 127, 254, 225, 223, 163, 91, 182, 113, 226, 217, 175, 67, 134, 17, 34, 68, 136, 13, 26, 52, 104, 208, 189, 103, 206, 129, 31, 62, 124, 248, 237, 199, 147, 59, 118, 236, 197, 151, 51, 102, 204, 133, 23, 46, 92, 184, 109, 218, 169, 79, 158, 33, 66, 132, 21, 42, 84, 168, 77, 154, 41, 82, 164, 85, 170, 73, 146, 57, 114, 228, 213, 183, 115, 230, 209, 191, 99, 198, 145, 63, 126, 252, 229, 215, 179, 123, 246, 241, 255, 227, 219, 171, 75, 150, 49, 98, 196, 149, 55, 110, 220, 165, 87, 174, 65, 130, 25, 50, 100, 200, 141, 7, 14, 28, 56, 112, 224, 221, 167, 83, 166, 81, 162, 89, 178, 121, 242, 249, 239, 195, 155, 43, 86, 172, 69, 138, 9, 18, 36, 72, 144, 61, 122, 244, 245, 247, 243, 251, 235, 203, 139, 11, 22, 44, 88, 176, 125, 250, 233, 207, 131, 27, 54, 108, 216, 173, 71, 142, 1);

    private $aLogRS = array(
        2 => array(3, 2),
        5 => array(31, 198, 63, 147, 116),
        6 => array(248, 1, 218, 32, 227, 38),
        7 => array(127, 122, 154, 164, 11, 68, 117),
        8 => array(255, 11, 81, 54, 239, 173, 200, 24),
        10 => array(216, 194, 159, 111, 199, 94, 95, 113, 157, 193),
        13 => array(137, 73, 227, 17, 177, 17, 52, 13, 46, 43, 83, 132, 120),
        14 => array(14, 54, 114, 70, 174, 151, 43, 158, 195, 127, 166, 210, 234, 163),
        15 => array(29, 196, 111, 163, 112, 74, 10, 105, 105, 139, 132, 151, 32, 134, 26),
        16 => array(59, 13, 104, 189, 68, 209, 30, 8, 163, 65, 41, 229, 98, 50, 36, 59),
        17 => array(119, 66, 83, 120, 119, 22, 197, 83, 249, 41, 143, 134, 85, 53, 125, 99, 79),
        18 => array(239, 251, 183, 113, 149, 175, 199, 215, 240, 220, 73, 82, 173, 75, 32, 67, 217, 146),
        20 => array(152, 185, 240, 5, 111, 99, 6, 220, 112, 150, 69, 36, 187, 22, 228, 198, 121, 121, 165, 174),
        22 => array(89, 179, 131, 176, 182, 244, 19, 189, 69, 40, 28, 137, 29, 123, 67, 253, 86, 218, 230, 26, 145, 245),
        24 => array(122, 118, 169, 70, 178, 237, 216, 102, 115, 150, 229, 73, 130, 72, 61, 43, 206, 1, 237, 247, 127, 217, 144, 117),
        26 => array(246, 51, 183, 4, 136, 98, 199, 152, 77, 56, 206, 24, 145, 40, 209, 117, 233, 42, 135, 68, 70, 144, 146, 77, 43, 94),
        28 => array(252, 9, 28, 13, 18, 251, 208, 150, 103, 174, 100, 41, 167, 12, 247, 56, 117, 119, 233, 127, 181, 100, 121, 147, 176, 74, 58, 197),
        30 => array(212, 246, 77, 73, 195, 192, 75, 98, 5, 70, 103, 177, 22, 217, 138, 51, 181, 246, 72, 25, 18, 46, 228, 74, 216, 195, 11, 106, 130, 150),
        32 => array(116, 64, 52, 174, 54, 126, 16, 194, 162, 33, 33, 157, 176, 197, 225, 12, 59, 55, 253, 228, 148, 47, 179, 185, 24, 138, 253, 20, 142, 55, 172, 88),
        34 => array(206, 60, 154, 113, 6, 117, 208, 90, 26, 113, 31, 25, 177, 132, 99, 51, 105, 183, 122, 22, 43, 136, 93, 94, 62, 111, 196, 23, 126, 135, 67, 222, 23, 10),
        36 => array(28, 196, 67, 76, 123, 192, 207, 251, 185, 73, 124, 1, 126, 73, 31, 27, 11, 104, 45, 161, 43, 74, 127, 89, 26, 219, 59, 137, 118, 200, 237, 216, 31, 243, 96, 59),
        40 => array(210, 248, 240, 209, 173, 67, 133, 167, 133, 209, 131, 186, 99, 93, 235, 52, 40, 6, 220, 241, 72, 13, 215, 128, 255, 156, 49, 62, 254, 212, 35, 99, 51, 218, 101, 180, 247, 40, 156, 38),
        42 => array(108, 136, 69, 244, 3, 45, 158, 245, 1, 8, 105, 176, 69, 65, 103, 107, 244, 29, 165, 52, 217, 41, 38, 92, 66, 78, 34, 9, 53, 34, 242, 14, 139, 142, 56, 197, 179, 191, 50, 237, 5, 217),
        44 => array(174, 128, 111, 118, 188, 207, 47, 160, 252, 165, 225, 125, 65, 3, 101, 197, 58, 77, 19, 131, 2, 11, 238, 120, 84, 222, 18, 102, 199, 62, 153, 99, 20, 50, 155, 41, 221, 229, 74, 46, 31, 68, 202, 49),
        46 => array(129, 113, 254, 129, 71, 18, 112, 124, 220, 134, 225, 32, 80, 31, 23, 238, 105, 76, 169, 195, 229, 178, 37, 2, 16, 217, 185, 88, 202, 13, 251, 29, 54, 233, 147, 241, 20, 3, 213, 18, 119, 112, 9, 90, 211, 38),
        48 => array(61, 3, 200, 46, 178, 154, 185, 143, 216, 223, 53, 68, 44, 111, 171, 161, 159, 197, 124, 45, 69, 206, 169, 230, 98, 167, 104, 83, 226, 85, 59, 149, 163, 117, 131, 228, 132, 11, 65, 232, 113, 144, 107, 5, 99, 53, 78, 208),
        50 => array(247, 51, 213, 209, 198, 58, 199, 159, 162, 134, 224, 25, 156, 8, 162, 206, 100, 176, 224, 36, 159, 135, 157, 230, 102, 162, 46, 230, 176, 239, 176, 15, 60, 181, 87, 157, 31, 190, 151, 47, 61, 62, 235, 255, 151, 215, 239, 247, 109, 167),
        52 => array(248, 5, 177, 110, 5, 172, 216, 225, 130, 159, 177, 204, 151, 90, 149, 243, 170, 239, 234, 19, 210, 77, 74, 176, 224, 218, 142, 225, 174, 113, 210, 190, 151, 31, 17, 243, 235, 118, 234, 30, 177, 175, 53, 176, 28, 172, 34, 39, 22, 142, 248, 10),
        54 => array(196, 6, 56, 127, 89, 69, 31, 117, 159, 190, 193, 5, 11, 149, 54, 36, 68, 105, 162, 43, 189, 145, 6, 226, 149, 130, 20, 233, 156, 142, 11, 255, 123, 240, 197, 3, 236, 119, 59, 208, 239, 253, 133, 56, 235, 29, 146, 210, 34, 192, 7, 30, 192, 228),
        56 => array(52, 59, 104, 213, 198, 195, 129, 248, 4, 163, 27, 99, 37, 56, 112, 122, 64, 168, 142, 114, 169, 81, 215, 162, 205, 66, 204, 42, 98, 54, 219, 241, 174, 24, 116, 214, 22, 149, 34, 151, 73, 83, 217, 201, 99, 111, 12, 200, 131, 170, 57, 112, 166, 180, 111, 116),
        58 => array(211, 248, 6, 131, 97, 12, 222, 104, 173, 98, 28, 55, 235, 160, 216, 176, 89, 168, 57, 139, 227, 21, 130, 27, 73, 54, 83, 214, 71, 42, 190, 145, 51, 201, 143, 96, 236, 44, 249, 64, 23, 43, 48, 77, 204, 218, 83, 233, 237, 48, 212, 161, 115, 42, 243, 51, 82, 197),
        60 => array(104, 132, 6, 205, 58, 21, 125, 141, 72, 141, 86, 193, 178, 34, 86, 59, 24, 49, 204, 64, 17, 131, 4, 167, 7, 186, 124, 86, 34, 189, 230, 211, 74, 148, 11, 140, 230, 162, 118, 177, 232, 151, 96, 49, 107, 3, 50, 127, 190, 68, 174, 172, 94, 12, 162, 76, 225, 128, 39, 44),
        62 => array(190, 112, 31, 67, 188, 9, 27, 199, 249, 113, 1, 236, 74, 201, 4, 61, 105, 118, 128, 26, 169, 120, 125, 199, 94, 30, 9, 225, 101, 5, 94, 206, 50, 152, 121, 102, 49, 156, 69, 237, 235, 232, 122, 164, 41, 197, 242, 106, 124, 64, 28, 17, 6, 207, 98, 43, 204, 239, 37, 110, 103, 52),
        64 => array(193, 10, 255, 58, 128, 183, 115, 140, 153, 147, 91, 197, 219, 221, 220, 142, 28, 120, 21, 164, 147, 6, 204, 40, 230, 182, 14, 121, 48, 143, 77, 228, 81, 85, 43, 162, 16, 195, 163, 35, 149, 154, 35, 132, 100, 100, 51, 176, 11, 161, 134, 208, 132, 244, 176, 192, 221, 232, 171, 125, 155, 228, 242, 245),
        66 => array(32, 199, 138, 150, 79, 79, 191, 10, 159, 237, 135, 239, 231, 152, 66, 131, 141, 179, 226, 246, 190, 158, 171, 153, 206, 226, 34, 212, 101, 249, 229, 141, 226, 128, 238, 57, 60, 206, 203, 106, 118, 84, 161, 127, 253, 71, 44, 102, 155, 60, 78, 247, 52, 5, 252, 211, 30, 154, 194, 52, 179, 3, 184, 182, 193, 26),
        68 => array(131, 115, 9, 39, 18, 182, 60, 94, 223, 230, 157, 142, 119, 85, 107, 34, 174, 167, 109, 20, 185, 112, 145, 172, 224, 170, 182, 107, 38, 107, 71, 246, 230, 225, 144, 20, 14, 175, 226, 245, 20, 219, 212, 51, 158, 88, 63, 36, 199, 4, 80, 157, 211, 239, 255, 7, 119, 11, 235, 12, 34, 149, 204, 8, 32, 29, 99, 11)
    );

    private $formatErrorCodeMicro = array(0x4445, 0x4172, 0x4E2B, 0x4B1C, 0x55AE, 0x5099, 0x5FC0, 0x5AF7, 0x6793, 0x62A4, 0x6DFD, 0x68CA, 0x7678, 0x734F, 0x7C16, 0x7921, 0x06DE, 0x03E9, 0x0CB0, 0x0987, 0x1735, 0x1202, 0x1D5B, 0x186C, 0x2508, 0x203F, 0x2F66, 0x2A51, 0x34E3, 0x31D4, 0x3E8D, 0x3BBA);
    private $formatErrorCodeFull = array(0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0, 0x77C4, 0x72F3, 0x7DAA, 0x789D, 0x662F, 0x6318, 0x6C41, 0x6976, 0x1689, 0x13BE, 0x1CE7, 0x19D0, 0x0762, 0x0255, 0x0D0C, 0x083B, 0x355F, 0x3068, 0x3F31, 0x3A06, 0x24B4, 0x2183, 0x2EDA, 0x2BED);
    private $versionErrorCode = array(7 => 0x07C94, 0x085BC, 0x09A99, 0x0A4D3, 0x0BBF6, 0x0C762, 0x0D847, 0x0E60D, 0x0F928, 0x10B78, 0x1145D, 0x12A17, 0x13532, 0x149A6, 0x15683, 0x168C9, 0x177EC, 0x18EC4, 0x191E1, 0x1AFAB, 0x1B08E, 0x1CC1A, 0x1D33F, 0x1ED75, 0x1F250, 0x209D5, 0x216F0, 0x228BA, 0x2379F, 0x24B0B, 0x2542E, 0x26A64, 0x27541, 0x28C69);
    private $errorCorrectionBinaryIndicator = array(1, 0, 3, 2);

    private $errorCode = array('L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3);

    /**
     * Constructor to create QRCode barcode.
     *
     * This method calls other public functions to set default values.
     */
    public function __construct() {
        $this->initialize();

        parent::__construct();

        $this->data = array();
        $this->symbol = null;

        $this->setAcceptECI(false);
        $this->setSize(self::QRCODE_SIZE_FULL);
        $this->setQuietZone(true);
        $this->setMirror(false);
        $this->setErrorLevel(1);
        $this->setStructuredAppend(0, 0, null);
        $this->setFNC1(self::QRCODE_FNC1_NONE);
        $this->setMask(-1);
        $this->setScale(4);
        $this->setQRSize(-1, false);
    }

    /**
     * Parses the $text to create the barcode.
     *
     * @param string $text
     */
    public function parse($text) {
        if (strlen($text) === 0) {
            return;
        }

        $seq = $this->getSequence($text);
        if ($seq !== null) {
            $bitstream = $this->createBinaryStream($text, $seq);
            $this->setData($bitstream);
        }
    }

    /**
     * Draws the barcode.
     *
     * @param resource $im
     */
    public function draw($im) {
        if ($this->symbol === null) {
            throw new BCGDrawException('Undefined symbol');
        }

        $quietSize = $this->quietZone ? (($this->symbol->micro) ? 4 : 8) : 0;

        $c = _BCGqrcode_Info::getSize($this->symbol);

        // Draws the quiet zone square
        if ($this->quietZone) {
            parent::drawFilledRectangle($im, 0, 0, $c + $quietSize - 1, $c + $quietSize - 1, BCGBarcode::COLOR_BG);
        }

        for ($x = 0; $x < $c; $x++) {
            for ($y = 0; $y < $c; $y++) {
                $xD = $this->mirror ? $c - $x - 1 : $x;

                $this->drawPixel($im, $x, $y, $this->data[$xD][$y]->pixel ? BCGBarcode::COLOR_FG : BCGBarcode::COLOR_BG);

                // DEBUG
                if ($this->data[$xD][$y]->debug !== null) {
                    imagestring($im, 0, $x * $this->scale + $quietSize / 2 * $this->scale + 1, $y * $this->scale + $quietSize / 2 * $this->scale, $this->data[$xD][$y]->debug, $this->getColor($im, $this->data[$xD][$y]->pixel ? BCGBarcode::COLOR_BG : BCGBarcode::COLOR_FG));
                }
            }
        }

        $this->drawText($im, 0, 0, $c + $quietSize, $c + $quietSize);

        // DEBUG
        if (self::DEBUG) {
            imagestring($im, 0, 2, ($c * $this->scale) + $quietSize * $this->scale - 10, 'Version ' . $this->symbol->version.', Error ' . $this->errorLevel .', Mask ' . $this->mask, $this->getColor($im, self::COLOR_FG));
        }
    }

    /**
     * Returns the maximal size of a barcode.
     *
     * @param int $w
     * @param int $h
     * @return int[]
     */
    public function getDimension($w, $h) {
        if ($this->symbol === null) {
            return array($w + 1, $h + 1);
        } else {
            $size = _BCGqrcode_Info::getSize($this->symbol);
            $quietSize = ($this->quietZone) ? (($this->symbol->micro) ? 4 : 8) : 0;
            $wh = $size + $quietSize;

            $w += $wh;
            $h += $wh;
            return parent::getDimension($w, $h);
        }
    }

    /**
     * Sets the error level code (0=L, 1=M, 2=Q, or 3=H)
     *
     * @param int $level
     */
    public function setErrorLevel($level) {
        if (is_string($level)) {
            if (!isset($this->errorCode[$level])) {
                throw new BCGArgumentException('This error level doesn\'t exist.', 'level');
            }

            $this->errorLevel = $this->errorCode[$level];
        } else {
            $level = (int)$level;
            if ($level < 0 || $level > 3) {
                throw new BCGArgumentException('The error level must be between 0 and 3.', 'level');
            }

            $this->errorLevel = $level;
        }
    }

    /**
     * Sets the size of the barcode. Could be different value:
     *  - QRCODE_SIZE_SMALLEST: generates the smallest size (default)
     *  - QRCODE_SIZE_MICRO: generates a micro size
     *  - QRCODE_SIZE_FULL: generates a full size
     *
     * @param int $size
     */
    public function setSize($size) {
        $size = (int)$size;
        if ($size !== self::QRCODE_SIZE_SMALLEST && $size !== self::QRCODE_SIZE_MICRO && $size !== self::QRCODE_SIZE_FULL) {
            throw new BCGArgumentException('The size argument must be BCGqrcode::QRCODE_SIZE_SMALLEST, BCGqrcode::QRCODE_SIZE_MICRO, or BCGqrcode::QRCODE_SIZE_FULL.', 'size');
        }

        $this->size = $size;
    }

    /**
     * Sets the FNC1 type for the barcode. The argument $fnc1Type can be:
     *  - QRCODE_FNC1_NONE: No FNC1 will be used
     *  - QRCODE_FNC1_GS1: FNC1 will be used with GS1 standard.
     *  - QRCODE_FNC1_AIM: FNC1 will be used with AIM standard, the $fnc1Id is required
     *
     * @param int $fnc1Type
     * @param mixed $fnc1Id
     */
    public function setFNC1($fnc1Type, $fnc1Id = null) {
        $fnc1Type = (int)$fnc1Type;
        if ($fnc1Type !== self::QRCODE_FNC1_NONE && $fnc1Type !== self::QRCODE_FNC1_GS1 && $fnc1Type !== self::QRCODE_FNC1_AIM) {
            throw new BCGArgumentException('The FNC1 type must be BCGqrcode::QRCODE_FNC1_NONE, BCGqrcode::QRCODE_FNC1_GS1, or BCGqrcode::QRCODE_FNC1_AIM.', 'fnc1Type');
        }

        $this->fnc1 = $fnc1Type;

        if ($this->fnc1 === self::QRCODE_FNC1_AIM) {
            if ((is_string($fnc1Id) && strlen($fnc1Id) === 1 && strtolower($fnc1Id[0]) >= 'a' && strtolower($fnc1Id[0]) <= 'z') || (is_int($fnc1Id) && $fnc1Id >= 0 && $fnc1Id <= 99)) {
                $this->fnc1Id = $fnc1Id;
            } else {
                throw new BCGArgumentException('In FNC1 AIM mode, you need to provide to the $fnc1Id one letter or a number between 0 and 99.', 'fnc1Id');
            }
        } else {
            $this->fnc1Id = null;
        }
    }

    /**
     * QRCode symbol can be appended to another one.
     * The $symbolTotal must remain the same across all the QRCodes group.
     * Up to 16 symbols total.
     * The first symbol is 1.
     * If you want to reset and not use this Structured Append, set the $symbolNumber to 0.
     * Returns true on success, false on failure.
     * The $symbolData should be the full data that you encode across all the symbols. The operation is
     * costly and you can simply put a string that will identify the barcode, as long as it remains
     * the same across the symbols.
     *
     * @param int $symbolNumber
     * @param int $symbolTotal
     * @param string $symbolData
     * @return bool
     */
    public function setStructuredAppend($symbolNumber, $symbolTotal = 0, $symbolData = null) {
        if ($symbolTotal == 0) { // Keep weak
            $this->symbolNumber = 0;
            $this->symbolTotal = 0;
            $this->symbolData = null;
            return true;
        } else {
            $symbolNumber = (int)$symbolNumber;
            $symbolTotal = (int)$symbolTotal;

            if ($symbolNumber <= 0) {
                throw new BCGArgumentException('The symbol number must be equal or bigger than 1.', 'symbolNumber');
            }

            if ($symbolNumber > $symbolTotal) {
                throw new BCGArgumentException('The symbol number must be equal or lower than the symbol total.', 'symbolNumber');
            }

            if ($symbolTotal > 16) {
                throw new BCGArgumentException('The symbol total must be equal or lower than 16.', 'symbolTotal');
            }

            $this->symbolNumber = $symbolNumber;
            $this->symbolTotal = $symbolTotal;
            $this->symbolData = $symbolData;

            return true;
        }
    }

    /**
     * Accepts ECI code to be process as a special character.
     * If true, you can do this:
     *  - \\    : to make ONE backslash
     *  - \xxxxxx    : with x a number between 0 and 9
     *
     * @param bool $accept
     */
    public function setAcceptECI($accept) {
        $this->acceptECI = (bool)$accept;
    }

    /**
     * Quiet zone is 4 element for micro, 8 for full
     *
     * @param bool $quietZone
     */
    public function setQuietZone($quietZone) {
        $this->quietZone = (bool)$quietZone;
    }

    /**
     * Sets the image to be output as a mirror following the standard.
     *
     * @param bool $mirror
     */
    public function setMirror($mirror) {
        $this->mirror = (bool)$mirror;
    }

    /**
     * Specifies the masking to be used from 0 to 7.
     * If -1 is set, the best masking will be used.
     *
     * @param int $mask
     */
    public function setMask($mask) {
        $mask = (int)$mask;
        if ($mask < -1 || $mask > 7) {
            throw new BCGArgumentException('The mask number must be between 0 and 7. You can set -1 for automatic.', 'mask');
        }

        $this->mask = $mask;
    }

    /**
     * Sets the QRSize you wish to use.
     * -1 is automatic.
     * For micro, you can use 1 to 4
     * For full, you can use 1 to 40
     *
     * @param int $qrSize
     * @param bool $qrMicro
     */
    public function setQRSize($qrSize, $qrMicro = false) {
        $this->qrMicro = (bool)$qrMicro;
        $maxSize = $this->qrMicro ? 4 : 40;

        $qrSize = (int)$qrSize;
        if ($qrSize < -1 || $qrSize === 0 || $qrSize > $maxSize) {
            throw new BCGArgumentException('The QR size number must be between 1 and 4 for Micro mode, or between 1 and 40 for Full mode. You can set -1 for automatic.', 'qrSize');
        }

        $this->qrSize = $qrSize;
    }

    /**
     * Draws a pixel at the position $x, $y with the color $color.
     * It gives more space if there is a quiet zone.
     *
     * @param resource $im
     * @param int $x
     * @param int $y
     * @param int $color
     */
    protected function drawPixel($im, $x, $y, $color = self::COLOR_FG) {
        $adder = $this->quietZone ? ($this->symbol->micro ? 2 : 4) : 0;
        parent::drawPixel($im, $x + $adder, $y + $adder, $color);
    }

    /**
     * Draws a filled rectangle with the top left position $x1, $y1 and bottom right $x2, $y2 with the color $color.
     * It gives more space if there is a quiet zone.
     *
     * @param resource $im
     * @param int $x1
     * @param int $y1
     * @param int $x2
     * @param int $y2
     * @param int $color
     */
    protected function drawFilledRectangle($im, $x1, $y1, $x2, $y2, $color = BCGBarcode::COLOR_FG) {
        $adder = $this->quietZone ? ($this->symbol->micro ? 2 : 4) : 0;
        parent::drawFilledRectangle($im, $x1 + $adder, $y1 + $adder, $x2 + $adder, $y2 + $adder, $color);
    }

    /**
     * Sets the value of a pixel on an horizontal line at the position $x, $y for a lenght $l.
     * If $pixel is true, the line is black.
     * IF $info is true, the line contains non-data information.
     *
     * @param int $x
     * @param int $y
     * @param int $l
     * @param bool $pixel
     * @param bool $info
     */
    private function setLineHorizontal($x, $y, $l, $pixel, $info) {
        for ($i = 0; $i < $l; $i++) {
            $this->data[$x + $i][$y]->set($pixel, $info);
        }
    }

    /**
     * Sets the value of a pixel on an vertical line at the position $x, $y for a lenght $l.
     * If $pixel is true, the line is black.
     * IF $info is true, the line contains non-data information.
     *
     * @param int $x
     * @param int $y
     * @param int $l
     * @param bool $pixel
     * @param bool $info
     */
    private function setLineVertical($x, $y, $l, $pixel, $info) {
        for ($i = 0; $i < $l; $i++) {
            $this->data[$x][$y + $i]->set($pixel, $info);
        }
    }

    /**
     * Draws an unfilled rectangle at position $x, $y with a width $w and height $h
     * If $pixel is true, the rectangle is black.
     * IF $info is true, the rectangle contains non-data information.
     *
     * @param int $x
     * @param int $y
     * @param int $w
     * @param int $h
     * @param bool $pixel
     * @param bool $info
     */
    private function setRectangle($x, $y, $w, $h, $pixel, $info) {
        $this->setLineHorizontal($x, $y, $w, $pixel, $info);
        $this->setLineHorizontal($x, $y + $h - 1, $w, $pixel, $info);
        $this->setLineVertical($x, $y, $h, $pixel, $info);
        $this->setLineVertical($x + $w - 1, $y, $h, $pixel, $info);
    }

    /**
     * Draws a finder pattern at the position $x, $y
     *
     * @param int $x
     * @param int $y
     */
    private function setFinderPattern($x, $y) {
        $this->setRectangle($x, $y, 7, 7, true, true);
        $this->setRectangle($x + 1, $y + 1, 5, 5, false, true);
        $this->setRectangle($x + 2, $y + 2, 3, 3, true, true);
        $this->data[$x + 3][$y + 3]->set(true, true);
    }

    /**
     * Draws all the required finder pattern for an image.
     */
    private function setFinderPatterns() {
        // Pattern 1
        $this->setFinderPattern(0, 0);

        if (!$this->symbol->micro) {
            $limit = _BCGqrcode_Info::getSize($this->symbol) - 7;

            // Pattern 2 & 3
            $this->setFinderPattern($limit, 0);
            $this->setFinderPattern(0, $limit);
        }
    }

    /**
     * Draws the timing (black & white sequence)
     */
    private function setTiming() {
        // Skip also the separator
        $c = _BCGqrcode_Info::getSize($this->symbol) - 8;
        $y = 6;
        if ($this->symbol->micro) {
            $y = 0;
            $c += 8;
        }

        for ($x = 8; $x < $c; $x++) {
            $color = true;
            if (($x % 2) === 1) {
                $color = false;
            }

            $this->data[$x][$y]->set($color, true);
            $this->data[$y][$x]->set($color, true);
        }
    }

    /**
     * Draws an alignment pattern at the position $x, $y
     *
     * @param int $x
     * @param int $y
     */
    private function setAlignmentPattern($x, $y) {
        $this->setRectangle($x, $y, 5, 5, true, true);
        $this->setRectangle($x + 1, $y + 1, 3, 3, false, true);
        $this->data[$x + 2][$y + 2]->set(true, true);
    }

    /**
     * Draws all the required alignment patterns for an image.
     */
    private function setAlignmentPatterns() {
        if (!$this->symbol->micro) {
            $patternPositions = $this->alignmentPatterns[$this->symbol->version];
            $c = count($patternPositions);
            $positions = array();
            for ($i = 0; $i < $c; $i++) {
                for ($j = 0; $j < $c; $j++) {
                    // We don't add 6x6, 6xBiggest, Biggestx6
                    if ($patternPositions[$i] === $patternPositions[$j] && $patternPositions[$i] === 6) {
                        continue;
                    }

                    if ($patternPositions[$i] === 6 && $patternPositions[$j] === $patternPositions[$c - 1]) {
                        continue;
                    }

                    if ($patternPositions[$j] === 6 && $patternPositions[$i] === $patternPositions[$c - 1]) {
                        continue;
                    }

                    $positions[] = array($patternPositions[$i], $patternPositions[$j]);
                }
            }

            $c = count($positions);
            for ($i = 0; $i < $c; $i++) {
                $this->setAlignmentPattern($positions[$i][0] - 2, $positions[$i][1] - 2);
            }
        }
    }

    /**
     * Draws the version information into the image at the top right and bottom left if required.
     * If $blank is true, we simply set the value "true" in the $info of the pixel.
     *
     * @param bool $blank
     */
    private function setVersion($blank) {
        // We draw a blank version to set the pixels to an "info" state.
        if (!$this->symbol->micro && $this->symbol->version >= 7) {
            $size = _BCGqrcode_Info::getSize($this->symbol) - 11;
            if ($blank) {
                for ($i = 0; $i < 6; $i++) {
                    for ($j = 0; $j < 3; $j++) {
                        $this->data[$j + $size][$i]->set(false, true);
                        $this->data[$i][$j + $size]->set(false, true);
                    }
                }
            } else {
                $final = $this->versionErrorCode[$this->symbol->version];
                for ($i = 0; $i < 6; $i++) {
                    for ($j = 0; $j < 3; $j++) {
                        $bit = 1 << ($j + (3 * $i));
                        $color = ($final & $bit) === $bit;
                        $this->data[$j + $size][$i]->set($color, true);
                        $this->data[$i][$j + $size]->set($color, true);
                    }
                }
            }
        }
    }

    /**
     * Draws the white separator around the finder patterns
     */
    private function setSeparator() {
        // Pattern 1
        $this->setLineHorizontal(0, 7, 8, false, true);
        $this->setLineVertical(7, 0, 8, false, true);

        if (!$this->symbol->micro) {
            $limit = _BCGqrcode_Info::getSize($this->symbol) - 8;

            // Pattern 2
            $this->setLineHorizontal($limit, 7, 8, false, true);
            $this->setLineVertical($limit, 0, 8, false, true);

            // Pattern 3
            $this->setLineHorizontal(0, $limit, 8, false, true);
            $this->setLineVertical(7, $limit, 8, false, true);
        }
    }

    /**
     * Draws the format information.
     * If $blank is true, we simply set the value "true" in the $info of the pixel.
     *
     * @param bool $blank
     */
    private function setFormat($blank) {
        if ($blank) {
            $limit = _BCGqrcode_Info::getSize($this->symbol) - 8;

            // Timing is called later and will erase a part of it. Good :)
            // Pattern 1
            $this->setLineHorizontal(0, 8, 9, true, true);
            $this->setLineVertical(8, 0, 9, true, true);

            if (!$this->symbol->micro) {
                // Pattern 2
                $this->setLineHorizontal($limit, 8, 8, false, true);

                // Pattern 3
                $this->setLineVertical(8, $limit, 8, false, true);
            }
        } else {
            if ($this->symbol->micro) {
                $code = null;
                switch ($this->symbol->version) {
                    case 1:
                        $code = 0;
                        break;

                    case 2:
                        $code = ($this->errorLevel === 0) ? 1 : 2;
                        break;

                    case 3:
                        $code = ($this->errorLevel === 0) ? 3 : 4;
                        break;

                    case 4:
                        $code = ($this->errorLevel === 0) ? 5 : (($this->errorLevel === 1) ? 6 : 7);
                        break;
                }

                $code = $code << 2 | $this->mask;

                $final = $this->formatErrorCodeMicro[$code];
                self::debug('MICRO Format Code: ' . self::decbin($final, 15));

                for ($i = 0, $j = 14; $i <= 7; $i++, $j--) {
                    $bit1 = 1 << $i;
                    $color1 = ($final & $bit1) === $bit1;
                    $bit2 = 1 << $j;
                    $color2 = ($final & $bit2) === $bit2;
                    $this->data[8][$i + 1]->set($color1, true); // Vertical line going down
                    $this->data[$i + 1][8]->set($color2, true); // Horizontal going right
                }
            } else {
                // Find the binary
                $code = $this->errorCorrectionBinaryIndicator[$this->errorLevel] << 3 | $this->mask;
                $final = $this->formatErrorCodeFull[$code];
                self::debug('FULL Format Code: ' . self::decbin($final, 15));

                $limit = _BCGqrcode_Info::getSize($this->symbol) - 1;
                for ($i = 0; $i <= 7; $i++) {
                    $bit = 1 << $i;
                    $color = ($final & $bit) === $bit;
                    $this->data[8][$i]->set($color, true); // Vertical line going down
                    $this->data[$limit - $i][8]->set($color, true); // Horizontal line going left
                }

                // Starting bit 14
                for ($i = 14, $counter = 0; $i >= 8; $i--, $counter++) {
                    $bit = 1 << $i;
                    $color = ($final & $bit) === $bit;
                    $this->data[$counter][8]->set($color, true); // Horizontal going right
                    $this->data[8][$limit - $counter]->set($color, true); // Vertical going up
                }

                // Fix the timing of going down
                $this->data[8][8] = $this->data[8][7];
                $this->data[8][7] = $this->data[8][6];
                $this->data[8][6] = new _BCGqrcode_Pixel;
                $this->data[8][6]->set(true, true);

                $this->data[7][8] = $this->data[6][8];
                $this->data[6][8] = new _BCGqrcode_Pixel;
                $this->data[6][8]->set(true, true);

                // Dark module
                $this->data[8][$limit - 7]->set(true, true);
            }
        }
    }

    /**
     * Sets the data in the pixel array at the right position based on the size of the symbol.
     *
     * @param string[] $data
     */
    private function setDataIntoVariable($data) {
        $c = _BCGqrcode_Info::getSize($this->symbol);

        $x = $y = $c - 1;
        $up = true;
        $left = true;

        $color = false;
        $numberOfGroups = intval($this->symbol->bits / 8);
        for ($j = 0; $j < $numberOfGroups; $j++) {
            $color = !$color;

            // Special case for M1 and M3, we have only 4 bits on the last.
            // So we have to do a strlen
            $codewordSize = strlen($data[$j]);
            for ($i = 0; $i < $codewordSize; $i++) {
                $a = $this->getBitPosition($x, $y, $up, $left, $c - 1);
                $a->set($data[$j][$i] ? true : false, false);

                // Uncomment the following line to see the bit number on the graphic
                ////$a->debug = 7 - $i;
            }
        }
    }

    /**
     * Gets the pixel at the right position on the graphic avoiding any "info" pixel.
     *
     * TODO Check if we call one of too many, will it go in an infinite loop?
     *
     * @param int $x Current Position in X
     * @param int $y Current Position in Y
     * @param bool $up Going up if true
     * @param bool $left Going to the left if true
     * @param int $max The size of the barcode in element
     * @return _BCGqrcode_Pixel at the correct position
     */
    private function getBitPosition(&$x, &$y, &$up, &$left, $max) {
        $data = null;
        if (!$this->data[$x][$y]->info) {
            $data = $this->data[$x][$y];
        }

        if ($left) {
            $x--;
            $left = !$left;
        } else {
            if ($up) {
                $x++;
                $y--;
                $left = !$left;

                if ($y < 0) {
                    $x -= 2;
                    $y++;
                    $up = false;
                }
            } else {
                $x++;
                $y++;
                $left = !$left;

                if ($y > $max) {
                    $x -= 2;
                    $y--;
                    $up = true;
                }
            }
        }

        // If we are on the left timing line in a full QR Code, we go one left
        if ($x === 6 && !$this->symbol->micro) {
            $x--;
        }

        // If we fell on a "info" pixel, we will re-execute this method until we find
        // a non-"info" pixel to return.
        if ($data === null) {
            $data = $this->getBitPosition($x, $y, $up, $left, $max);
        }

        return $data;
    }

    /**
     * Applies the masking on the $this->data
     */
    private function applyMasking() {
        $maskInstance = _BCGqrcode_CodeMaskBase::getMaskClass($this->symbol, $this->mask);
        $this->data = $maskInstance->getMaskData($this->data);
        self::debug('Masking passed ' . $this->mask);
        $this->mask = $maskInstance->getMaskId();
        self::debug('Masking chosen ' . $this->mask);
    }

    /**
     * Sets the data and prepares the drawing but does not draw it.
     *
     * @param string[] $data
     */
    private function setData($data) {
        // Initialize the data
        $c = _BCGqrcode_Info::getSize($this->symbol);
        $this->data = array();
        for ($i = 0; $i < $c; $i++) {
            $this->data[$i] = array();
            for ($j = 0; $j < $c; $j++) {
                $this->data[$i][$j] = new _BCGqrcode_Pixel;
            }
        }

        $this->setFinderPatterns();
        $this->setSeparator();
        $this->setAlignmentPatterns();
        $this->setFormat(true);
        $this->setVersion(true);
        $this->setTiming();
        $this->setDataIntoVariable($data);
        $this->applyMasking();
        $this->setFormat(false);
        $this->setVersion(false);
    }

    /**
     * Returns the starting sequence of the $text.
     *
     * @param string $text
     * @return string
     */
    private function findStartingSequence($textSeq) {
        $sizeType = array();

        // Micro
        $sizeType[5] = $sizeType[6] = 'B';
        $sizeType[4] = 'A';
        $sizeType[3] = 'N';

        // Full
        // Byte
        $sizeType[0] = $sizeType[1] = $sizeType[2] = 'B';

        // See Annex J.2.a
        // Alphanumeric
        if ($textSeq[0] === 'E') {
            throw new Exception('[DEBUG] Do not provide a E to $findStartingSequence method');
        }

        if ($textSeq[0] === 'A') {
            $temp = substr($textSeq, 0, 6);
            if (strlen($temp) === 6 && strpos($temp, 'B') === false) {
                $sizeType[0] = 'A';
                if (isset($textSeq[6]) && $textSeq[6] !== 'B') {
                    $sizeType[1] = 'A';
                    if (isset($textSeq[7]) && $textSeq[7] !== 'B') {
                        $sizeType[2] = 'A';
                    }
                }
            }

            // Micro
            $temp = substr($textSeq, 0, 3);
            if (strlen($temp) === 3 && strpos($temp, 'B') === false) {
                $sizeType[5] = 'A';
                if (isset($textSeq[3]) && $textSeq[3] !== 'B') {
                    $sizeType[6] = 'A';
                }
            }
        }

        // Numeric
        if ($textSeq[0] === 'N') {
            if (substr($textSeq, 0, 7) === 'NNNNNNN') {
                $sizeType[0] = 'N';
                if (isset($textSeq[7]) && $textSeq[7] === 'N') {
                    $sizeType[1] = 'N';
                    if (isset($textSeq[8]) && $textSeq[8] === 'N') {
                        $sizeType[2] = 'N';
                    }
                }
            }

            // Micro
            if (strpos($textSeq, 'B') === false) {
                $sizeType[4] = $sizeType[5] = $sizeType[6] = 'A';
                $temp = substr($textSeq, 0, 3);
                if (strlen($temp) === 3 && $temp === 'NNN') {
                    $sizeType[4] = 'N';
                    if (isset($textSeq[3]) && $textSeq[3] === 'N') {
                        $sizeType[5] = 'N';
                        if (isset($textSeq[4]) && $textSeq[4] === 'N') {
                            $sizeType[6] = 'N';
                        }
                    }
                }
            } else {
                $temp = substr($textSeq, 0, 2);
                if (strlen($temp) === 2 && $temp === 'NN') {
                    $sizeType[5] = 'N';
                    if (isset($textSeq[2]) && $textSeq[2] === 'N') {
                        $sizeType[6] = 'N';
                    }
                }
            }

            // Full
            $temp = substr($textSeq, 0, 4);
            if (strlen($temp) === 4 && strpos($temp, 'B') === false) {
                $sizeType[0] = ($sizeType[0] !== 'N') ? 'A' : 'N';
                $sizeType[1] = ($sizeType[1] !== 'N') ? 'A' : 'N';
                if (isset($textSeq[4]) && $textSeq[4] !== 'B') {
                    $sizeType[2] = 'A';
                }
            }
        }

        return $sizeType;
    }

    /**
     * Depending on the $text, it will return the correct
     * sequence to encode the text. The sequence is composed of an array of 7 data.
     * Each data is for the Version 1-9, 10-26 and 27-40 respectivitely. Followed by the Micro 1 to 4.
     * The data will contain letters indicating each character has to be encoded in which
     * type. N=Numeric, A=AlphaNumeric, K=Kanji, B=Byte
     *
     * @param string $text
     * @return List of Sequence List
     */
    private function getSequence(&$text) {
        // N=> Numeric
        // A=> Alphanumeric
        // B=> Byte
        // K=> Kanji
        // E=> ECI

        // We return a different sequence based on the version of the barcode.
        // [0] => Version 1-9
        // [1] => Version 10-26
        // [2] => Version 27-40
        // [3] => M1
        // [4] => M2
        // [5] => M3
        // [6] => M4

        $textSeq = '';
        $textLen = strlen($text);
        for ($i = 0; $i < $textLen; $i++) {
            if ($escapedCharacter = $this->isEscapedCharacter($text, $i)) {
                if ($escapedCharacter === 1) {
                    $textSeq .= 'E' . substr($text, $i + 1, 6);
                    $i += 6;
                } else {
                    $text = substr($text, 0, $i) . substr($text, $i + 1);
                    $textLen--;
                    $textSeq .= 'B';
                }
            } elseif ($this->isCharNumeric($text[$i])) {
                $textSeq .= 'N';
            } elseif ($this->isCharAlphanumeric($text[$i])) {
                $textSeq .= 'A';
            } elseif ($this->isCharByte($text[$i])) {
                $textSeq .= 'B';
            } // TODO Kanji
        }

        $seqLen = strlen($textSeq);

        // TODO Kanji

        // Impossible
        $e = 99999;

        // See Annex J.2.bcd
        $codeSeqAlpha = array(13, 15, 17, 1, 3, 4, 5);
        $codeSeqByte2Kanji = array(9, 12, 13, $e, $e, $e, $e);
        $codeSeqByte2Alpha = array(11, 15, 16, $e, $e, 3, 4);
        $codeSeqByte2Nume2 = array(6, 7, 8, $e, $e, 2, 3);

        // Do all the sizeType
        $finalSequence = array();
        for ($j = 0; $j < 7; $j++) {
            $micro = $j >= 3;

            $seq = array();
            $currentSeq = null;

            // If we had starting E, we add it now
            $counter = array('B' => 0, 'A' => 0, 'N' => 0, 'K' => 0, 'E' => 0);
            for ($i = 0; $i < $seqLen; $i++) {
                if ($currentSeq === null) {
                    if ($textSeq[$i] === 'E') {
                        $currentSeq = $this->addESequence($j, $micro, $textSeq, $i, $seq, $counter);
                    } else {
                        $sizeType = $this->findStartingSequence($textSeq);
                        $currentSeq = $sizeType[$j];
                    }
                }

                // This would happen if the user entered only the ECI only
                if ($currentSeq === null) {
                    break;
                }

                $this->addSequence($j, $currentSeq, 1, $micro, $seq, $counter);

                if (!isset($textSeq[$i + 1])) {
                    break;
                }

                if ($currentSeq === 'B') {
                    // TODO Kanji
                    if ($textSeq[$i + 1] === 'E') {
                        $i++;
                        $currentSeq = $this->addESequence($j, $micro, $textSeq, $i, $seq, $counter);
                    } elseif (substr($textSeq, $i + 1, $codeSeqByte2Alpha[$j]) === str_repeat('A', $codeSeqByte2Alpha[$j])) {
                        $currentSeq = 'A';
                        $this->addSequence($j, $currentSeq, $codeSeqByte2Alpha[$j], $micro, $seq, $counter);
                        $i += $codeSeqByte2Alpha[$j];
                        $currentSeq = isset($textSeq[$i + 1]) ? $textSeq[$i + 1] : null;
                    } elseif (substr($textSeq, $i + 1, $codeSeqByte2Nume2[$j]) === str_repeat('N', $codeSeqByte2Nume2[$j])) { // We skipped J2b3
                        $currentSeq = 'N';
                        $this->addSequence($j, $currentSeq, $codeSeqByte2Nume2[$j], $micro, $seq, $counter);
                        $i += $codeSeqByte2Nume2[$j];
                        $currentSeq = isset($textSeq[$i + 1]) ? $textSeq[$i + 1] : null;
                    }
                } elseif ($currentSeq === 'A') {
                    // TODO Kanji
                    if ($textSeq[$i + 1] === 'E') {
                        $i++;
                        $currentSeq = $this->addESequence($j, $micro, $textSeq, $i, $seq, $counter);
                    } elseif ($textSeq[$i + 1] === 'B') {
                        $currentSeq = 'B';
                    } elseif (substr($textSeq, $i + 1, $codeSeqAlpha[$j]) === str_repeat('N', $codeSeqAlpha[$j])) {
                        // Copy at least those characters
                        $currentSeq = 'N';
                        $this->addSequence($j, $currentSeq, $codeSeqAlpha[$j], $micro, $seq, $counter);
                        $i += $codeSeqAlpha[$j];
                        $currentSeq = isset($textSeq[$i + 1]) ? $textSeq[$i + 1] : null;
                    }
                } elseif ($currentSeq === 'N') {
                    // TODO Kanji
                    if ($textSeq[$i + 1] === 'E') {
                        $i++;
                        $currentSeq = $this->addESequence($j, $micro, $textSeq, $i, $seq, $counter);
                    } elseif ($textSeq[$i + 1] === 'B') {
                        $currentSeq = 'B';
                    } elseif ($textSeq[$i + 1] === 'A') {
                        $currentSeq = 'A';
                    }
                }
            }

            $finalSequence[$j] = $seq;
        }

        // Delete the sequences that can't exist
        // M1 can contain only N
        // M2 can contain only N and A
        // M3 and M4 can't contain E
        for ($j = 3; $j < 7; $j++) {
            $c = count($finalSequence[$j]);
            for ($i = 0; $i < $c; $i++) {
                $code = $finalSequence[$j][$i][0];

                switch ($j) {
                    case 3:
                        if ($code !== 'N') {
                            $finalSequence[$j] = null;
                            continue 2;
                        }

                        break;
                    case 4:
                        if ($code !== 'N' && $code !== 'A') {
                            $finalSequence[$j] = null;
                            continue 2;
                        }

                        break;
                    case 5:
                    case 6:
                        if ($code === 'E') {
                            $finalSequence[$j] = null;
                            continue 2;
                        }

                        break;
                }
            }
        }

        self::debug("Sequences: " . print_r($finalSequence, true));

        return $finalSequence;
    }

    /**
     * Adds the E Sequence and returns the next in the sequence.
     *
     * @param int $currentEncoding [0-6]
     * @param bool $micro Indicates if micro
     * @param string $textSeq
     * @param int $i
     * @param string[] $seq The sequences
     * @param int[] $counter BANK[]
     * @return string Next Sequence
     */
    private function addESequence($currentEncoding, $micro, $textSeq, &$i, &$seq, &$counter) {
        $this->hasECI = true;
        while (isset($textSeq[$i]) && $textSeq[$i] === 'E') {
            $this->addSequence($currentEncoding, 'E', 1, $micro, $seq, $counter);

            // We save the ECI number in the amount code. We will use it later.
            $seq[count($seq) - 1][1] = intval(substr($textSeq, $i + 1, 6));
            $i += 7;
        }

        if (isset($textSeq[$i])) {
            $sizeType = $this->findStartingSequence(substr($textSeq, $i));
            return $sizeType[$currentEncoding];
        } else {
            return null;
        }
    }

    /**
     * Creates an array of sequence in $seq. Each sequence contains of [$currentSeq, NumberOfCharToEncode].
     * This function takes care to not bust a sequence size. It creates a new sequence in that case.
     * It creates a new sequence if the current sequence type is different from the previous one.
     *
     * @param int $sizeTypeNumber Section number, 0 to 2
     * @param string $currentSeq Current Sequence, BANK
     * @param int $amount Amount to add to the sequence
     * @param bool $micro Indicates if micro
     * @param string[] $seq The sequences
     * @param int[] $counter BANK[]
     */
    private function addSequence($sizeTypeNumber, $currentSeq, $amount, $micro, &$seq, &$counter) {
        // This may never be used... check if we can bust those numbers?
        $maxAmount = array(
            'B' => array(255, 65535, 65535),
            'A' => array(511, 2047, 8191),
            'N' => array(1023, 4095, 16383),
            'K' => array(255, 1023, 4095),
            'E' => array(1, 1, 1)
        );

        // We reset
        if ($counter[$currentSeq] === 0) {
            $counter = array('B' => 0, 'A' => 0, 'N' => 0, 'K' => 0, 'E' => 0);
            $seq[] = array($currentSeq, 0);
        }

        $numberToAddNow = $micro ? $amount : min($amount, $maxAmount[$currentSeq][$sizeTypeNumber] - $counter[$currentSeq]);
        if ($numberToAddNow > 0) {
            $seq[count($seq) - 1][1] += $numberToAddNow;
            $counter[$currentSeq] += $numberToAddNow;
        }

        if ($amount > $numberToAddNow) {
            $counter[$currentSeq] = 0;
            $this->addSequence($sizeTypeNumber, $currentSeq, $amount - $numberToAddNow, $micro, $seq, $counter);
        }
    }

    /**
     * Returns if the character represents the ECI code \Exxxxxx
     * Returns 1 if ECI
     * Returns 2 if \
     *
     * @param string $text
     * @param int $i
     * @return int
     */
    private function isEscapedCharacter($text, $i) {
        if ($this->acceptECI) {
            if ($text[$i] === '\\') {
                $temp = substr($text, $i + 1, 6);
                if (strlen($temp) === 6 && is_numeric($temp)) {
                    return 1;
                } elseif (isset($text[$i + 1]) && $text[$i + 1] === '\\') {
                    return 2;
                } else {
                    throw new BCGParseException('qrcode', 'Incorrect ECI code detected. ECI code must contain a backslash followed by 6 digits or double the backslash to write one backslash.');
                }
            }
        }

        return 0;
    }

    /**
     * Returns if a character is numeric [0-9].
     *
     * @return bool
     */
    private function isCharNumeric($character) {
        $o = ord($character);
        return ($o >= 0x30 && $o <= 0x39);
    }

    /**
     * Returns if all the characters are numeric [0-9].
     *
     * @return bool
     */
    private function isCharsNumeric($chars) {
        $result = true;
        $pos = 0;
        $length = strlen($chars);
        do
        {
            $result = $this->isCharNumeric($chars[$pos++]);
        } while ($result && $pos < $length);

        return $result;
    }

    /**
     * Returns if a character is alpha-numeric [0-9A-Z $%*+-./:]
     * If $exclusive, it will return false if it's numeric [0-9].
     *
     * @return bool
     */
    private function isCharAlphanumeric($character, $exclusive = false) {
        $o = ord($character);

        $numeric = $this->isCharNumeric($character);
        $alpha = (($o >= 0x41 && $o <= 0x5A) || ($o === 0x20 || $o === 0x24 || $o === 0x25 || $o === 0x2A || $o === 0x2B || $o === 0x2D || $o === 0x2E || $o === 0x2F || $o === 0x3A));

        return (!$exclusive && ($alpha || $numeric)) || ($exclusive && $alpha && !$numeric);
    }

    /**
     * Returns if all the characters are alpha-numeric
     * If $exclusive, it will return false if there is a character which is numeric [0-9].
     *
     * @param bool $exclusive
     * @return bool
     */
    private function isCharsAlphanumeric($chars, $exclusive = false) {
        $result = true;
        $pos = 0;
        $length = strlen($chars);
        do
        {
            $result = $this->isCharAlphanumeric($chars[$pos++], $exclusive);
        } while ($result && $pos < $length);

        return $result;
    }

    /**
     * Returns if a character is byte
     * If $exclusive, it will return false if it's alpha-numeric.
     *
     * @param bool $exclusive
     * @return bool
     */
    private function isCharByte($character, $exclusive = false) {
        $o = ord($character);
        return (!$exclusive && $o >= 0 && $o <= 255) || ($exclusive && !$this->isCharAlphanumeric($character));
    }

    /**
     * Returns if a character is Kanji.
     *
     * @param bool $exclusive
     * @return bool
     */
    private function isCharKanji($character, $exclusive = false) {
        return false; // TODO
    }

    /**
     * Initializes the class with all the possible barcode sizes and other variables.
     */
    private function initialize() {
        // Ordered List
        $this->symbols[] = new _BCGqrcode_InfoMicro1(1,                true,        36,            array(20,    0,    0,    0),                    array(1,    0,    0,    0));
        $this->symbols[] = new _BCGqrcode_InfoMicro2(2,                true,        80,            array(40,    32,    0,    0),                    array(1,    1,    0,    0));
        $this->symbols[] = new _BCGqrcode_InfoMicro3(3,                true,        132,        array(84,    68,    0,    0),                    array(1,    1,    0,    0));
        $this->symbols[] = new _BCGqrcode_InfoMicro4(4,                true,        192,        array(128,    112,    80,    0),                array(1,    1,    1,    0));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(1,            false,        208,        array(152,    128,    104,    72),        array(1,    1,    1,    1));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(2,            false,        359,        array(272,    224,    176,    128),        array(1,    1,    1,    1));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(3,            false,        567,        array(440,    352,    272,    208),        array(1,    1,    2,    2));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(4,            false,        807,        array(640,    512,    384,    288),        array(1,    2,    2,    4));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(5,            false,        1079,        array(864,    688,    496,    368),        array(1,    2,    array(2, 2),    array(2, 2)));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(6,            false,        1383,        array(1088,    864,    608,    480),        array(2,    4,    4,    4));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(7,            false,        1568,        array(1248,    992,    704,    528),        array(2,    4,    array(2, 4),    array(4, 1)));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(8,            false,        1936,        array(1552,    1232,    880,    688),        array(2,    array(2, 2),    array(4, 2),    array(4, 2)));
        $this->symbols[] = new _BCGqrcode_InfoFullSmall(9,            false,        2336,        array(1856,    1456,    1056,    800),        array(2,    array(3, 2),    array(4, 4),    array(4, 4)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(10,        false,        2768,        array(2192,    1728,    1232,    976),        array(array(2, 2),    array(4, 1),    array(6, 2),    array(6, 2)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(11,        false,        3232,        array(2592,    2032,    1440,    1120),        array(4,    array(1, 4),    array(4, 4),    array(3, 8)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(12,        false,        3728,        array(2960,    2320,    1648,    1264),        array(array(2, 2),    array(6, 8),    array(4, 6),    array(7, 4)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(13,        false,        4256,        array(3424,    2672,    1952,    1440),        array(4,    array(8, 1),    array(8, 4),    array(12, 4)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(14,        false,        4651,        array(3688,    2920,    2088,    1576),        array(array(3, 1),    array(4, 5),    array(11, 5),    array(11, 5)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(15,        false,        5243,        array(4184,    3320,    2360,    1784),        array(array(5, 1),    array(5, 5),    array(5, 7),    array(11, 7)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(16,        false,        5867,        array(4712,    3624,    2600,    2024),        array(array(5, 1),    array(7, 3),    array(15, 2),    array(3, 13)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(17,        false,        6523,        array(5176,    4056,    2936,    2264),        array(array(1, 5),    array(10, 1),    array(1, 15),    array(2, 17)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(18,        false,        7211,        array(5768,    4504,    3176,    2504),        array(array(5, 1),    array(9, 4),    array(17, 1),    array(2, 19)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(19,        false,        7931,        array(6360,    5016,    3560,    2728),        array(array(3, 4),    array(3, 11),    array(17, 4),    array(9, 16)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(20,        false,        8683,        array(6888,    5352,    3880,    3080),        array(array(3, 5),    array(3, 13),    array(15, 5),    array(15, 10)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(21,        false,        9252,        array(7456,    5712,    4096,    3248),        array(array(4, 4),    17,    array(17, 6),    array(19, 6)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(22,        false,        10068,        array(8048,    6256,    4544,    3536),        array(array(2, 7),    17,    array(7, 16),    34));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(23,        false,        10916,        array(8752,    6880,    4912,    3712),        array(array(4, 5),    array(4, 14),    array(11, 14),    array(16, 14)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(24,        false,        11796,        array(9392,    7312,    5312,    4112),        array(array(6, 4),    array(6, 14),    array(11, 16),    array(30, 2)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(25,        false,        12708,        array(10208,    8000,    5744,    4304),    array(array(8, 4),    array(8, 13),    array(7, 22),    array(22, 13)));
        $this->symbols[] = new _BCGqrcode_InfoFullMedium(26,        false,        13652,        array(10960,    8496,    6032,    4768),    array(array(10, 2),    array(19, 4),    array(28, 6),    array(33, 4)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(27,            false,        14628,        array(11744,    9024,    6464,    5024),    array(array(8, 4),    array(22, 3),    array(8, 26),    array(12, 28)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(28,            false,        15371,        array(12248,    9544,    6968,    5288),    array(array(3, 10),    array(3, 23),    array(4, 31),    array(11, 31)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(29,            false,        16411,        array(13048,    10136,    7288,    5608),    array(array(7, 7),    array(21, 7),    array(1, 37),    array(19, 26)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(30,            false,        17483,        array(13880,    10984,    7880,    5960),    array(array(5, 10),    array(19, 10),    array(15, 25),    array(23, 25)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(31,            false,        18587,        array(14744,    11640,    8264,    6344),    array(array(13, 3),    array(2, 29),    array(42, 1),    array(23, 28)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(32,            false,        19723,        array(15640,    12328,    8920,    6760),    array(17,    array(10, 23),    array(10, 35),    array(19, 35)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(33,            false,        20891,        array(16568,    13048,    9368,    7208),    array(array(17, 1),    array(14, 21),    array(29, 19),    array(11, 46)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(34,            false,        22091,        array(17528,    13800,    9848,    7688),    array(array(13, 6),    array(14, 23),    array(44, 7),    array(59, 1)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(35,            false,        23008,        array(18448,    14496,    10288,    7888),    array(array(12, 7),    array(12, 26),    array(39, 14),    array(22, 41)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(36,            false,        24272,        array(19472,    15312,    10832,    8432),    array(array(6, 14),    array(6, 34),    array(46, 10),    array(2, 64)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(37,            false,        25568,        array(20528,    15936,    11408,    8768),    array(array(17, 4),    array(29, 14),    array(49, 10),    array(24, 46)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(38,            false,        26896,        array(21616,    16816,    12016,    9136),    array(array(4, 18),    array(13, 32),    array(48, 14),    array(42, 32)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(39,            false,        28256,        array(22496,    17728,    12656,    9776),    array(array(20, 4),    array(40, 7),    array(43, 22),    array(10, 67)));
        $this->symbols[] = new _BCGqrcode_InfoFullLarge(40,            false,        29648,        array(23648,    18672,    13328,    10208),    array(array(19, 6),    array(18, 31),    array(34, 34),    array(20, 61)));

        $this->hasECI = false;
    }

    /**
     * Calculates the number of bits required per symbol size
     * See section 6.4.x.
     * $sizeType contains the string sequences (BANK)
     * It returns the size of each sequence.
     *
     * @param string[] $seq
     * @return int[]
     */
    private function calculateBits($seq) {
        $bitsNumeric = array(10, 12, 14, 3, 4, 5, 6);
        $bitsAlphanumeric = array(9, 11, 13, 0, 3, 4, 5);
        $bitsByte = array(8, 16, 16, 0, 0, 4, 5);
        $bitsKanji = array(8, 10, 12, 0, 0, 3, 4);
        $bitsIndicator = array(4, 4, 4, 0, 1, 2, 3);

        $c = count($seq);
        $sizeBits = array_fill(0, $c, 0);
        for ($i = 0; $i < $c; $i++) {
            $c2 = count($seq[$i]);
            for ($j = 0; $j < $c2; $j++) {
                $mode = $seq[$i][$j][0];
                $value = $seq[$i][$j][1];

                if ($mode === 'E') {
                    $sizeBits[$i] += 4;
                    if ($value <= 127) {
                        $sizeBits[$i] += 8;
                    } elseif ($value <= 16383) {
                        $sizeBits[$i] += 16;
                    } else {
                        $sizeBits[$i] += 24;
                    }

                    self::debug("Bits $i: $value");
                } elseif ($mode === 'N') {
                    // B = M + C + 10(D DIV 3) + R
                    $R_Temp = $value % 3;
                    $R = ($R_Temp === 2) ? 7 : (($R_Temp === 1) ? 4 : (($R_Temp === 0) ? 0 : 0));
                    $sizeBits[$i] += $bitsIndicator[$i] + $bitsNumeric[$i] + 10 * intval($value / 3) + $R;
                    self::debug("Bits $i: $value N=" . ($bitsIndicator[$i] + $bitsNumeric[$i] + 10 * intval($value / 3) + $R));
                } elseif ($mode === 'A') {
                    // B = M + C + 11(D DIV 2) + 6(D MOD 2)
                    $sizeBits[$i] += $bitsIndicator[$i] + $bitsAlphanumeric[$i] + 11 * intval($value / 2) + 6 * intval($value % 2);
                    self::debug("Bits $i: $value A=" . ($bitsIndicator[$i] + $bitsAlphanumeric[$i] + 11 * intval($value / 2) + 6 * intval($value % 2)));
                } elseif ($mode === 'B') {
                    // B = M + C + 8D
                    $sizeBits[$i] += $bitsIndicator[$i] + $bitsByte[$i] + 8 * $value;
                    self::debug("Bits $i: $value B=" . ($bitsIndicator[$i] + $bitsByte[$i] + 8 * $value));
                } elseif ($mode === 'K') {
                    // B = M + C + 13D
                    $sizeBits[$i] += $bitsIndicator[$i] + $bitsKanji[$i] + 13 * $value;
                    self::debug("Bits $i: $value K=" . ($bitsIndicator[$i] + $bitsKanji[$i] + 13 * $value));
                }
            }
        }

        // If we have a Structured Append, we need to add 20 bits.
        $bitAdder = 0;
        if ($this->symbolNumber > 0) {
            $bitAdder += 20;
        }

        // If we have a FNC1 GS1, we add 4 bits
        if ($this->fnc1 === self::QRCODE_FNC1_GS1) {
            $bitAdder += 4;
        }

        // If we have a FNC1 AIM, we add 12 bits
        if ($this->fnc1 === self::QRCODE_FNC1_AIM) {
            $bitAdder += 12;
        }

        if ($bitAdder > 0) {
            for ($i = 0; $i < 3; $i++) {
                $sizeBits[$i] += $bitAdder;
            }
        }

        return $sizeBits;
    }

    /**
     * To speed up the lookup, the $this->symbols is ordered, we know what are the index of our version
     * Version is 1 to 4 in micro, index 0 to 3
     * Version is 1 to 40 in full, index is 4 to 43
     *
     * @param int $version
     * @param bool $micro
     * @return int
     */
    private function findSymbolIndex($version, $micro) {
        if ($micro) {
            return $version - 1;
        } else {
            return $version + 3;
        }
    }

    /**
     * Finds the perfect Symbol based on the version we allow and the number of bits we want to fit.
     *
     * @param int $versionMin Minimum Version
     * @param int $versionMax Maximal Version
     * @param bool $micro Indicates if we want micro
     * @param int $bits Number of bits required
     * @return _BCGqrcode_Info Symbol found or null
     */
    private function findPerfectSymbolRange($versionMin, $versionMax, $micro, $bits) {
        if ($bits > 0) {
            $c = $this->findSymbolIndex($versionMax, $micro);
            for ($i = $this->findSymbolIndex($versionMin, $micro); $i <= $c; $i++) {
                $symbol = $this->symbols[$i];

                // No check for $micro because we have the correct index.
                if ($bits <= $symbol->data[$this->errorLevel]) {
                    // We check if we have enough place for the terminator.
                    // If it's flush, we do not put the terminator, otherwise, we need at least 3 free bits
                    if ($bits === $symbol->data[$this->errorLevel] || $bits + 3 <= $symbol->data[$this->errorLevel]) {
                        return $symbol;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Finds the perfect symbol based on the number of bits in each stream.
     *
     * @param int[] $sizeBits Number of bits for sequence Version 1-9, 10-26, 27-40, M1, M2, M3, M4.
     * @return Index of the sequence taken.
     */
    private function findPerfectSymbol($sizeBits) {
        $this->symbol = null;

        if ($this->qrSize === -1) {
            // We can't have a micro if we have a structured append, FNC1 or ECI
            if ($this->symbolNumber === 0 && $this->fnc1 === self::QRCODE_FNC1_NONE && !$this->hasECI && ($this->size === self::QRCODE_SIZE_SMALLEST || $this->size === self::QRCODE_SIZE_MICRO)) {
                for ($i = 0; $i < 4; $i++) {
                    $this->symbol = $this->findPerfectSymbolRange($i + 1, $i + 1, true, $sizeBits[$i + 3]);
                    if ($this->symbol !== null) {
                        return $i + 3;
                    }
                }
            }

            if ($this->size === self::QRCODE_SIZE_SMALLEST || $this->size === self::QRCODE_SIZE_FULL) {
                $this->symbol = $this->findPerfectSymbolRange(1, 9, false, $sizeBits[0]);
                if ($this->symbol !== null) {
                    return 0;
                }

                $this->symbol = $this->findPerfectSymbolRange(10, 26, false, $sizeBits[1]);
                if ($this->symbol !== null) {
                    return 1;
                }

                $this->symbol = $this->findPerfectSymbolRange(27, 40, false, $sizeBits[2]);
                if ($this->symbol !== null) {
                    return 2;
                }
            }
        } else {
            $i = $this->findSymbolIndex($this->qrSize, $this->qrMicro);

            $keyIndex = 0;
            if ($this->qrMicro) {
                $keyIndex = $this->qrSize + 2;
            } else {
                $keyIndex = ($this->qrSize <= 40) ? (($this->qrSize <= 26) ? (($this->qrSize <= 9) ? 0 : 1) : 2) : -1;
            }

            $symbol = $this->symbols[$i];

            // We need space for the terminator OR less than 4 bits
            $spaceForTerminator = $symbol->data[$this->errorLevel] - $sizeBits[$keyIndex];
            if ($symbol !== null && $sizeBits[$keyIndex] > 0 &&
                ($sizeBits[$keyIndex] === $symbol->data[$this->errorLevel] ||
                    ($sizeBits[$keyIndex] < $symbol->data[$this->errorLevel] &&
                        ($spaceForTerminator < 4 || $spaceForTerminator >= strlen($symbol->getCodeTerminator()))))) {
                $this->symbol = $symbol;
            }

            if ($this->symbol !== null) {
                return $keyIndex;
            }
        }

        throw new BCGParseException('qrcode', 'No barcode can fit the data you provided. Accept bigger barcodes or change your data.');
    }

    /**
     * Creates the binary stream based on the $text and the 3 sequences $seq passed.
     * The function choses the best symbol based on the inputs.
     * The output will be an array of string containing 8 bits 1 and 0 (binary as string)
     *
     * @param string $text
     * @param string[] $seq
     * @return string[]
     */
    private function createBinaryStream($text, $seq) {
        // We will decide what would be the perfect symbol now because we don't want to carry 7 bitstreams
        $sizeBits = $this->calculateBits($seq);
        $sequenceTaken = $this->findPerfectSymbol($sizeBits);

        self::debug("Sequence Taken: $sequenceTaken, Symbol: {$this->symbol->version}, ErrorLevel: {$this->errorLevel}, DataBits: {$this->symbol->data[$this->errorLevel]}, Number of Bits total: $sizeBits[$sequenceTaken]");
        $realSeq = $seq[$sequenceTaken];
        $binaryData = $this->encode($text, $realSeq);

        return $binaryData;
    }

    /**
     * Creates the binary for the Structured Append if needed.
     *
     * @return string
     */
    private function encodeStructuredAppend() {
        if ($this->symbolNumber > 0 && $this->symbol instanceof _BCGqrcode_InfoMicro) {
            throw new BCGParseException('qrcode', 'A Micro QRCode cannot contain a structured append. Make sure to allow Full mode or don\'t use structured append.');
        } elseif ($this->symbolNumber > 0) {
            $binary = $this->symbol->getCodeStructuredAppend();
            $binary .= self::decbin($this->symbolNumber - 1, 4);
            $binary .= self::decbin($this->symbolTotal - 1, 4);

            $symbolData = 0;
            $c = strlen($this->symbolData);
            for ($i = 0; $i < $c; $i++)  {
                $symbolData ^= ord($this->symbolData[$i]);
            }

            $binary .= self::decbin($symbolData, 8);
            return $binary;
        } else {
            return '';
        }
    }

    /**
     * Creates the binary for the FNC1 if needed.
     *
     * @param int $pos The FNC1 type to encode
     * @return string
     */
    private function encodeFNC1($pos) {
        if ($this->fnc1 !== self::QRCODE_FNC1_NONE && $this->symbol instanceof _BCGqrcode_InfoMicro) {
            throw new BCGParseException('qrcode', 'A Micro QRCode cannot contain a FNC1. Make sure to allow Full mode or don\'t use FNC1.');
        } elseif ($pos === self::QRCODE_FNC1_GS1 && $this->fnc1 === self::QRCODE_FNC1_GS1) {
            return $this->symbol->getCodeFNC1(0);
        } elseif ($pos === self::QRCODE_FNC1_AIM && $this->fnc1 === self::QRCODE_FNC1_AIM) {
            $binary = $this->symbol->getCodeFNC1(1);
            if (is_string($this->fnc1Id)) {
                $binary .= self::decbin(ord($this->fnc1Id) + 100, 8);
            } else {
                $binary .= self::decbin((int)$this->fnc1Id, 8);
            }

            return $binary;
        } else {
            return '';
        }
    }

    private function encodeECI($value) {
        $binary = $this->symbol->getCodeECI();
        if ($value <= 127) {
            $binary .= '0' . self::decbin($value, 7);
        } elseif ($value <= 16383) {
            $binary .= '10' . self::decbin($value, 14);
        } else {
            $binary .= '110' . self::decbin($value, 21);
        }

        return $binary;
    }

    /**
     * Encodes the first ECI if there is one.
     */
    private function encodeFirstECI(&$text, &$realSeq) {
        if (count($realSeq) > 0 && $realSeq[0][0] === 'E') {
            if ($this->symbol instanceof _BCGqrcode_InfoMicro) {
                throw new BCGParseException('qrcode', 'A Micro QRCode cannot contain a ECI code. Make sure to allow Full mode or don\'t use ECI.');
            } else {
                $text = substr($text, 7);
                $value = $realSeq[0][1];
                $realSeq = array_splice($realSeq, 1);
                return $this->encodeECI($value);
            }
        } else {
            return '';
        }
    }

    /**
     * Encodes the text based on the sequence provided.
     * @param string text
     * @param string[] $realSeq
     * @return string[] Binary Data
     */
    private function encode($text, $realSeq) {
        $binary = $this->encodeStructuredAppend();
        $binary .= $this->encodeFNC1(self::QRCODE_FNC1_GS1);
        $binary .= $this->encodeFirstECI($text, $realSeq);
        $binary .= $this->encodeFNC1(self::QRCODE_FNC1_AIM);

        $c = count($realSeq);
        $valueIndex = 0;
        for ($i = 0; $i < $c; $i++) {
            $mode = $realSeq[$i][0];
            $value = $realSeq[$i][1];

            if ($mode === 'E') {
                $binary .= $this->encodeECI($value);
                $value = 7;
            } elseif ($mode === 'N') {
                $binary .= $this->encodeNumeric(substr($text, $valueIndex, $value));
            } elseif ($mode === 'A') {
                $binary .= $this->encodeAlphanumeric(substr($text, $valueIndex, $value));
            } elseif ($mode === 'B') {
                $binary .= $this->encodeByte(substr($text, $valueIndex, $value));
            } elseif ($mode === 'K') {
                $binary .= $this->encodeKanji(substr($text, $valueIndex, $value));
            }

            $valueIndex += $value;
        }

        self::debug("Before terminator (length=" . strlen($binary) . ")");

        // Append Terminator if needed. We don't check more than that because the findPerfectSymbol has taken care of the size
        // TODO Comment wrong, fix next line
        $terminator = substr($this->symbol->getCodeTerminator(), 0, min($this->symbol->data[$this->errorLevel] - strlen($binary), strlen($this->symbol->getCodeTerminator())));
        $binary .= $terminator;

        self::debug("After terminator (length=" . strlen($binary) . ")");

        $pad = (8 - strlen($binary) % 8) % 8;

        $binary .= str_repeat('0', $pad);

        self::debug("After First Padding (length=" . strlen($binary) . ")");

        $padding = array('11101100', '00010001');
        $c = ceil(($this->symbol->data[$this->errorLevel] - strlen($binary)) / 8);

        self::debug("Adding Groups: $c");
        for ($i = 0; $i < $c; $i++) {
            $binary .= $padding[$i % 2];
        }

        // We have a special case for M1 and M3.
        // * If we had group padding, we removed the last 8 bits and put 0000
        // * If we didn't have padding, make sure we remove the last 4 bit of padding
        if ($this->symbol->micro && ($this->symbol->version === 1 || $this->symbol->version === 3)) {
            if ($c >= 1) {
                $binary = substr($binary, 0, -8) . '0000';
            } else {
                $binary = substr($binary, 0, -4);
            }
        }

        self::debug("After padding (length=" . strlen($binary) . ")");

        // TODO Verify if we NEED to fit it for Micro
        $dataBinary = str_split($binary, 8);

        $numberOfErrorCodewords = (int)floor(($this->symbol->bits - $this->symbol->data[$this->errorLevel]) / 8);
        $errorBinary = $this->computeError($dataBinary, $numberOfErrorCodewords);
        $finalBinary = $this->assemble($dataBinary, $errorBinary);

        self::debug("Data binary: " . print_r($dataBinary, true));
        self::debug("Error binary: " . print_r($errorBinary, true));

        // Add Remainder
        $remainder = $this->symbol->bits % 8;
        self::debug("Remainder: $remainder");
        if ($remainder > 0) {
            $finalBinary[] = str_repeat('0', $remainder);
        }

        self::debug("Full binary: " . print_r($finalBinary, true));

        return $finalBinary;
    }

    /**
     * Computes the error on the $dataBinary and returns $nc Number of error codewords in binary string.
     *
     * @param string[] $dataBinary
     * @param int $nc
     * @return string[]
     */
    private function computeError($dataBinary, $nc) {
        $nbBlocks = $this->symbol->blocks[$this->errorLevel];
        if (!is_array($nbBlocks)) {
            $nbBlocks = array($nbBlocks);
        }

        $totalBlocks = array_sum($nbBlocks);
        $codePerBlocks = ($this->symbol->data[$this->errorLevel] / 8) / $totalBlocks;
        $codePerBlock1 = intval($codePerBlocks);
        $codePerBlock2 = is_int($codePerBlocks) ? 0 : $codePerBlock1 + 1;

        $errorPerBlock = $nc / $totalBlocks;
        $errorCodeDecimal = array();
        $index = 0;
        for ($i = 0; $i < $nbBlocks[0]; $i++) {
            $dataDecimal = array();
            for ($j = 0; $j < $codePerBlock1; $j++) {
                $index = $j + $i * $codePerBlock1;
                $dataDecimal[$j] = bindec($dataBinary[$index]);
            }

            $errorCodeDecimal = array_merge($errorCodeDecimal, $this->reedSolomon($dataDecimal, $codePerBlock1, $errorPerBlock));
        }

        $index++;

        if (count($nbBlocks) > 1) {
            for ($i = 0; $i < $nbBlocks[1]; $i++) {
                $dataDecimal = array();
                for ($j = 0; $j < $codePerBlock2; $j++) {
                    $dataDecimal[$j] = bindec($dataBinary[$j + $i * $codePerBlock2 + $index]);
                }

                $errorCodeDecimal = array_merge($errorCodeDecimal, $this->reedSolomon($dataDecimal, $codePerBlock2, $errorPerBlock));
            }
        }

        $errorBinary = array();
        for ($i = 0; $i < $nc; $i++) {
            $errorBinary[] = self::decbin($errorCodeDecimal[$i], 8);
        }

        return $errorBinary;
    }

    /**
     * Reed Solomon
     *
     * @param int[] $wd
     * @param int $nd
     * @param int $nc
     * @return int[]
     */
    private function reedSolomon($wd, $nd, $nc) {
        $t = $nd + $nc;
        for ($i = $nd; $i <= $t; $i++) {
            $wd[$i] = 0;
        }

        for ($i = 0; $i < $nd; $i++) {
            $k = $wd[$nd] ^ $wd[$i];

            for ($j = 0; $j < $nc; $j++) {
                $wd[$nd + $j] = $wd[$nd + $j + 1] ^ self::prod($k, $this->aLogRS[$nc][$j], $this->logTable, $this->aLogTable);
            }
        }

        $r = array();
        for ($i = $nd; $i < $t; $i++) {
            $r[] = $wd[$i];
        }

        return $r;
    }

    /**
     * Assembles the $dataBinary and $errorBinary based on the selected symbol.
     *
     * @param string[]
     * @param string[]
     * @return string[]
     */
    private function assemble($dataBinary, $errorBinary) {
        $nbBlocks = $this->symbol->blocks[$this->errorLevel];
        if (!is_array($nbBlocks)) {
            $nbBlocks = array($nbBlocks);
        }

        $totalBlocks = array_sum($nbBlocks);
        $codePerBlocks = ($this->symbol->data[$this->errorLevel] / 8) / $totalBlocks;

        // Special case for M1 and M3
        if ($this->symbol->micro && ($this->symbol->version === 1 || $this->symbol->version === 3)) {
            $codePerBlocks = (int)ceil($codePerBlocks);
        }

        self::debug('Assembling DATA');
        $data1 = self::assembleFromBlocks($dataBinary, $nbBlocks, $codePerBlocks);

        self::debug('Assembling ERROR');
        $data2 = self::assembleFromBlocks($errorBinary, $nbBlocks, intval(($this->symbol->bits - $this->symbol->data[$this->errorLevel]) / 8) / $totalBlocks);

        return array_merge($data1, $data2);
    }

    /**
     * Encodes Kanji $text based on the symbol.
     * We assume here the $text is valid.
     *
     * @param string $text
     * @return string
     */
    private function encodeKanji($text) {
        throw new BCGParseException('qrcode', 'Not implemented');
    }

    /**
     * Encodes Byte $text based on the symbol.
     * We assume here the $text is valid.
     *
     * @param string $text
     * @return string
     */
    private function encodeByte($text) {
        $binary = '';
        $c = strlen($text);
        for ($i = 0; $i < $c; $i++) {
            $binary .= self::decbin(ord($text[$i]), 8);
        }

        $bank = $this->symbol->getBANK();
        self::debug("Encoding Byte (length=" . strlen($this->symbol->getCodeByte() . self::decbin(strlen($text), $bank['B']) . $binary) . "): " . $this->symbol->getCodeByte() . self::decbin(strlen($text), $bank['B']) . $binary);
        return $this->symbol->getCodeByte() . self::decbin(strlen($text), $bank['B']) . $binary;
    }

    /**
     * Encodes Alphanumeric $text based on the symbol.
     * We assume here the $text is valid.
     *
     * @param string $text
     * @return string
     */
    private function encodeAlphanumeric($text) {
        $keyValue = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:'; // TODO CONSTANT

        $binary = '';
        $c = strlen($text);
        for ($i = 0; $i < $c; $i += 2) {
            $pos = strpos($keyValue, $text[$i]);

            if (isset($text[$i + 1])) {
                $pos2 = strpos($keyValue, $text[$i + 1]);
                $binary .= self::decbin($pos * 45 + $pos2, 11);
            } else {
                $binary .= self::decbin($pos, 6);
            }
        }

        $bank = $this->symbol->getBANK();
        self::debug("Encoding Alphanumeric (length=" . strlen($this->symbol->getCodeAlphanumeric() . self::decbin(strlen($text), $bank['A']) . $binary) . "): " . $this->symbol->getCodeAlphanumeric() . self::decbin(strlen($text), $bank['A']) . $binary);
        return $this->symbol->getCodeAlphanumeric() . self::decbin(strlen($text), $bank['A']) . $binary;
    }

    /**
     * Encodes Numeric $text based on the symbol.
     * We assume here the $text is valid.
     *
     * @param string $text
     * @return string
     */
    private function encodeNumeric($text) {
        $binary = '';

        // TODO Manage character not supported?
        $groups = str_split($text, 3);
        $c = count($groups);
        for ($i = 0; $i < $c; $i++) {
            $len = strlen($groups[$i]);
            if ($len === 3) {
                $binary .= self::decbin(intval($groups[$i]), 10);
            } elseif ($len === 2) {
                $binary .= self::decbin(intval($groups[$i]), 7);
            } elseif ($len === 1) {
                $binary .= self::decbin(intval($groups[$i]), 4);
            }
        }

        $bank = $this->symbol->getBANK();
        self::debug("Encoding Numeric (length=" . strlen($this->symbol->getCodeNumeric() . self::decbin(strlen($text), $bank['N']) . $binary) . "): " . $this->symbol->getCodeNumeric() . self::decbin(strlen($text), $bank['N']) . $binary);
        return $this->symbol->getCodeNumeric() . self::decbin(strlen($text), $bank['N']) . $binary;
    }

    /**
     * products x times y in array
     *
     * @param int $x
     * @param int $y
     * @param int[] $log
     * @param int[] $alog
     * @return int
     */
    private static function prod($x, $y, $log, $alog) {
        if ($x === 0 || $y === 0) { return 0; }
        else { return $alog[($log[$x] + $log[$y]) % (self::_GF - 1)]; }
    }

    /**
     * Transforms the number $number from decimal to binary.
     * And pads with 0 on the left to have $bits number of bits.
     *
     * @param int $number
     * @param int $bits
     * @return string
     */
    private static function decbin($number, $bits) {
        return str_pad(decbin($number), $bits, '0', STR_PAD_LEFT);
    }

    /**
     * Assembles the $data into $nbBlocks with a maximum of $codePerBlocks
     *
     * @param string[] $data
     * @param int or int[] $nbBlocks
     * @param int or float $codePerBlocks
     * @return string[]
     */
    private static function assembleFromBlocks($data, $nbBlocks, $codePerBlocks) {
        $codePerBlock1 = intval($codePerBlocks);
        $codePerBlock2 = is_int($codePerBlocks) ? 0 : $codePerBlock1 + 1;

        $blockRow1 = 0;
        $blockRow2 = 0;

        // We have 1 line
        if ($codePerBlock2 === 0) {
            $blockRow1 = array_sum($nbBlocks);
            $blockRow2 = 0;
        } else {
            // We have 2 lines
            $blockRow1 = $nbBlocks[0];
            $blockRow2 = $nbBlocks[1];
        }

        $finalData = array();

        $totalBlocks = array_sum($nbBlocks);
        for ($i = 0; $i < $codePerBlock1; $i++) {
            for ($j = 0; $j < $totalBlocks; $j++) {
                $index = $j * $codePerBlock1 + $i;
                $index += ($j > $blockRow1) ? 1 : 0;

                self::debug($index + 1);

                $finalData[] = $data[$index];
            }
        }

        $start = ($blockRow1 + 1) * $codePerBlock1;
        for ($i = 0; $i < $blockRow2; $i++) {
            $index = $start + $codePerBlock2 * $i;

            self::debug('A' . ($index + 1));

            $finalData[] = $data[$index];
        }

        return $finalData;
    }

    /**
     * Debug
     *
     * @param string $text
     * @param bool $newline
     */
    private static function debug($text, $newline = true) {
        if (self::DEBUG) {
            echo $text;
            if ($newline === true) {
                echo "<br />\n";
            }
        }
    }
}

/**
 * Abstract class representing a barcode version
 */
abstract class _BCGqrcode_Info {
    /**
     * Version, integer 1 to 40
     */
    public $version;

    /**
     * Micro, true or false
     */
    public $micro;

    /**
     * Total number of bits supported
     */
    public $bits;

    /**
     * Number of bits supported for DATA based on errorLevel
     */
    public $data;

    /**
     * Number of error blocks
     */
    public $blocks;

    /**
     * Constructor
     *
     * @param int $version
     * @param bool $micro
     * @param int $bits
     * @param int[] $data
     * @param int[][] or int[] $blocks
     */
    public function __construct($version, $micro, $bits, $data, $blocks) {
        $this->version = (int)$version;
        $this->micro = (bool)$micro;
        $this->bits = (int)$bits;
        $this->data = $data;
        $this->blocks = $blocks;
    }

    /**
     * Number of bits per mode (Byte, Alpha, Numeric, Kanji)
     *
     * @return int[]
     */
    abstract public function getBANK();

    /**
     * Gets the ECI code
     *
     * @return string
     */
    abstract public function getCodeECI();

    /**
     * Gets the Numeric code
     *
     * @return string
     */
    abstract public function getCodeNumeric();

    /**
     * Gets the Alphanumeric code
     *
     * @return string
     */
    abstract public function getCodeAlphanumeric();

    /**
     * Gets the Byte code
     *
     * @return string
     */
    abstract public function getCodeByte();

    /**
     * Gets the Kanji code
     *
     * @return string
     */
    abstract public function getCodeKanji();

    /**
     * Gets the Structured Append code
     *
     * @return string
     */
    abstract public function getCodeStructuredAppend();

    /**
     * Gets the FNC1 code based on position
     *
     * @param int $pos
     * @return string
     */
    abstract public function getCodeFNC1($pos);

    /**
     * Gets the Terminator code
     *
     * @return string
     */
    abstract public function getCodeTerminator();

    /**
     * Gets the size of the $qrInfo in pixel.
     *
     * @return int
     */
    public static function getSize($symbol) {
        if ($symbol->micro) {
            return ($symbol->version - 1) * 2 + 11;
        } else {
            return ($symbol->version - 1) * 4 + 21;
        }
    }
}

/**
 * Class representing a micro code
 */
abstract class _BCGqrcode_InfoMicro extends _BCGqrcode_Info {
    public function getCodeECI() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeStructuredAppend() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeFNC1($pos) {
        throw new BCGParseException('qrcode', 'Not supported');
    }
}

/**
 * Class representing a full code
 */
abstract class _BCGqrcode_InfoFull extends _BCGqrcode_Info {
    public function getCodeECI() {
        return '0111';
    }

    public function getCodeNumeric() {
        return '0001';
    }

    public function getCodeAlphanumeric() {
        return '0010';
    }

    public function getCodeByte() {
        return '0100';
    }

    public function getCodeKanji() {
        return '1000';
    }

    public function getCodeStructuredAppend() {
        return '0011';
    }

    public function getCodeFNC1($pos) {
        if ($pos === 0) {
            return '0101';
        } else {
            return '1001';
        }
    }

    public function getCodeTerminator() {
        return '0000';
    }
}

/**
 * Class representing a micro code M1
 */
class _BCGqrcode_InfoMicro1 extends _BCGqrcode_InfoMicro
{
    const BITS_NUMERIC = 3;
    const BITS_ALPHANUMERIC = null;
    const BITS_BYTE = null;
    const BITS_KANJI = null;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }

    public function getCodeNumeric() {
        // We only support numeric, and there is no value returned here.
        return '';
    }

    public function getCodeAlphanumeric() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeByte() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeKanji() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeTerminator() {
        return '000';
    }
}

/**
 * Class representing a micro code M2
 */
class _BCGqrcode_InfoMicro2 extends _BCGqrcode_InfoMicro
{
    const BITS_NUMERIC = 4;
    const BITS_ALPHANUMERIC = 3;
    const BITS_BYTE = null;
    const BITS_KANJI = null;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }

    public function getCodeNumeric() {
        return '0';
    }

    public function getCodeAlphanumeric() {
        return '1';
    }

    public function getCodeByte() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeKanji() {
        throw new BCGParseException('qrcode', 'Not supported');
    }

    public function getCodeTerminator() {
        return '00000';
    }
}

/**
 * Class representing a micro code M3
 */
class _BCGqrcode_InfoMicro3 extends _BCGqrcode_InfoMicro
{
    const BITS_NUMERIC = 5;
    const BITS_ALPHANUMERIC = 4;
    const BITS_BYTE = 4;
    const BITS_KANJI = 3;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }

    public function getCodeNumeric() {
        return '00';
    }

    public function getCodeAlphanumeric() {
        return '01';
    }

    public function getCodeByte() {
        return '10';
    }

    public function getCodeKanji() {
        return '11';
    }

    public function getCodeTerminator() {
        return '0000000';
    }
}

/**
 * Class representing a micro code M4
 */
class _BCGqrcode_InfoMicro4 extends _BCGqrcode_InfoMicro
{
    const BITS_NUMERIC = 6;
    const BITS_ALPHANUMERIC = 5;
    const BITS_BYTE = 5;
    const BITS_KANJI = 4;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }

    public function getCodeNumeric() {
        return '000';
    }

    public function getCodeAlphanumeric() {
        return '001';
    }

    public function getCodeByte() {
        return '010';
    }

    public function getCodeKanji() {
        return '011';
    }

    public function getCodeTerminator() {
        return '000000000';
    }
}

/**
 * Class representing a full code Small (1-9)
 */
class _BCGqrcode_InfoFullSmall extends _BCGqrcode_InfoFull
{
    const BITS_NUMERIC = 10;
    const BITS_ALPHANUMERIC = 9;
    const BITS_BYTE = 8;
    const BITS_KANJI = 8;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }
}

/**
 * Class representing a micro code Medium (10-26)
 */
class _BCGqrcode_InfoFullMedium extends _BCGqrcode_InfoFull
{
    const BITS_NUMERIC = 12;
    const BITS_ALPHANUMERIC = 11;
    const BITS_BYTE = 16;
    const BITS_KANJI = 10;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }
}

/**
 * Class representing a micro code Large (27-40)
 */
class _BCGqrcode_InfoFullLarge extends _BCGqrcode_InfoFull
{
    const BITS_NUMERIC = 14;
    const BITS_ALPHANUMERIC = 13;
    const BITS_BYTE = 16;
    const BITS_KANJI = 12;

    public function getBANK() {
        return array('B' => self::BITS_BYTE, 'A' => self::BITS_ALPHANUMERIC, 'N' => self::BITS_NUMERIC, 'K' => self::BITS_KANJI);
    }
}

/**
 * Class representing 1 pixel on the image.
 */
class _BCGqrcode_Pixel {
    /**
     * Pixel color, true is dark
     */
    public $pixel;

    /**
     * Value indicating if it's info data. true is non-data.
     */
    public $info;

    /**
     * Debug value that would be displayed on the graphic if you uncomment a specific line.
     */
    public $debug;

    /**
     * Sets the pixel color and if we have a "info" or "data"
     * If $pixel is true, then the pixel is dark
     *
     * @param bool $pixel
     * @param bool $info
     */
    public function set($pixel, $info) {
        $this->pixel = (bool)$pixel;
        $this->info = (bool)$info;
    }
}

/**
 * Class representing the Masking
 */
abstract class _BCGqrcode_CodeMaskBase {
    protected $qrInfo;
    protected $maskId;
    protected $data;

    /**
     * Constructor
     */
    protected function __construct(_BCGqrcode_Info $qrInfo, $maskId) {
        $this->qrInfo = $qrInfo;
        $this->maskId = $maskId;
    }

    /**
     * Creates a statement that can be eval-ed
     *
     * @param string $condition
     * @return string
     */
    private function createCondition($condition) {
        return 'return ' . $condition . ';';
    }

    /**
     * Tries to find the best mask that would be appropriate for the barcode.
     *
     * @return _BCGqrcode_Pixel[][] Data Masked
     */
    private function findPerfectMask() {
        $maskConditions = $this->getMaskConditions();
        $numberOfMasks = count($maskConditions);

        $masks = array_fill(0, $numberOfMasks, null);
        $c = _BCGqrcode_Info::getSize($this->qrInfo);

        // PHP is able to create array even if the parent object is null.
        for ($x = 0; $x < $c; $x++) {
            for ($y = 0; $y < $c; $y++) {
                // If we have "info" value, we simply copy it over into the mask without math operations
                if ($this->data[$x][$y]->info) {
                    for ($i = 0; $i < $numberOfMasks; $i++) {
                        $masks[$i][$x][$y] = clone $this->data[$x][$y];
                    }
                } else {
                    for ($i = 0; $i < $numberOfMasks; $i++) {
                        $this->applyMaskingToMask($masks[$i], $x, $y, eval($this->createCondition($maskConditions[$i])));
                    }
                }
            }
        }

        $maskId = $this->applyMaskConditions($masks);
        $this->maskId = $maskId;

        return $masks[$this->maskId];
    }

    /**
     * Gets an array of condition in string format
     * with $x and $y as variable
     *
     * @return string[]
     */
    abstract protected function getMaskConditions();

    /**
     * Executes code that checks which masks is the most suitable
     * to be used. Returns the index of the best mask
     *
     * @param _BCGqrcode_Pixel[][][] $masks
     * @return int
     */
    abstract protected function applyMaskConditions($masks);

    /**
     * Gets the mask id that has been selected.
     * The information is available after getMaskData() has been called.
     *
     * @return int
     */
    public function getMaskId() {
        return $this->maskId;
    }

    /**
     * Returns the perfect data mask based on the $data passed.
     *
     * @param _BCGqrcode_Pixel[][] $data
     * @return _BCGqrcode_Pixel[][]
     */
    public function getMaskData($data) {
        $this->data = $data;
        if ($this->maskId === -1) {
            return $this->findPerfectMask();
        } else {
            $maskConditions = $this->getMaskConditions();
            return $this->createMask($maskConditions[$this->maskId]);
        }
    }

    /**
     * Returns the correct instance to calculate the mask based on the
     * size of the symbol
     *
     * @param _BCGqrcode_Info $qrInfo
     * @param int $maskId
     * @return _BCGqrcode_CodeMaskBase
     */
    public static function getMaskClass(_BCGqrcode_Info $qrInfo, $maskId) {
        if ($qrInfo->micro) {
            return new _BCGqrcode_CodeMaskMicro($qrInfo, $maskId);
        } else {
            return new _BCGqrcode_CodeMaskFull($qrInfo, $maskId);
        }
    }

    /**
     * Applies the mask operation $xorValue on the pixel at the position $x, $y
     *
     * @param _BCGqrcode_Pixel[][] $mask
     * @param int $x
     * @param int $y
     * @param bool $xorValue
     */
    protected function applyMaskingToMask(&$mask, $x, $y, $xorValue) {
        $mask[$x][$y] = clone $this->data[$x][$y];
        $mask[$x][$y]->pixel = (intval($mask[$x][$y]->pixel) ^ intval($xorValue)) === 1;
    }

    /**
     * Creates a mask based on the condition
     *
     * @param string $codition The condition will be "eval-ed" from a string.
     * @return _BCGqrcode_Pixel[][] Data Masked
     */
    protected function createMask($condition) {
        $condition = $this->createCondition($condition);

        $c = _BCGqrcode_Info::getSize($this->qrInfo);
        $mask = array();
        for ($x = 0; $x < $c; $x++) {
            for ($y = 0; $y < $c; $y++) {
                if ($this->data[$x][$y]->info) {
                    $mask[$x][$y] = clone $this->data[$x][$y];
                } else {
                    $this->applyMaskingToMask($mask, $x, $y, eval($condition));
                }
            }
        }

        return $mask;
    }
}

/**
 * Class reprensenting the masking for Micro code
 */
class _BCGqrcode_CodeMaskMicro extends _BCGqrcode_CodeMaskBase {
    const DEBUG = BCGqrcode::DEBUG;

    private $maskConditions = array(
            '$y % 2 == 0',
            '(intval($y / 2) + intval($x / 3)) % 2 == 0',
            '(($y * $x) % 2 + ($y * $x) % 3) % 2 == 0',
            '(($y + $x) % 2 + ($y * $x) % 3) % 2 == 0'
        );

    public function __construct(_BCGqrcode_Info $qrInfo, $maskId) {
        parent::__construct($qrInfo, $maskId);
    }

    protected function getMaskConditions() {
        return $this->maskConditions;
    }

    /**
     * Calculates how many dark module is on the right and lower side of the mask.
     * Then take the smallest number times 16 + the biggest number.
     * The one that scores the most is the mask selected
     *
     * @param _BCGqrcode_Pixel[][][] $mask
     * @param int $c Size of the barcode
     * @return int Key of the best mask
     */
    protected function applyMaskConditions($masks) {
        $c = _BCGqrcode_Info::getSize($this->qrInfo);
        $numberOfMasks = 4;
        $penalty = array_fill(0, $numberOfMasks, 0);
        for ($i = 0; $i < $numberOfMasks; $i++) {
            $sum1 = 0;
            $sum2 = 0;
            for ($j = 1; $j < $c; $j++) {
                $sum1 += $masks[$i][$c - 1][$j]->pixel ? 1 : 0;
                $sum2 += $masks[$i][$j][$c - 1]->pixel ? 1 : 0;
            }

            // Make sure we have the smallest number in $sum1
            if ($sum1 > $sum2) {
                $sum1 ^= $sum2 ^= $sum1 ^= $sum2;
            }

            $penalty[$i] = ($sum1 * 16) + $sum2;
            self::debug('[MASKING] Evaluation Score Mask ' . $i . ': ' . $penalty[$i]);
        }

        // We want the biggest number
        arsort($penalty, SORT_NUMERIC);
        $maskWin = each($penalty);

        return $maskWin['key'];
    }

    /**
     * Debug
     *
     * @param string $text
     * @param bool $newline
     */
    private static function debug($text, $newline = true) {
        if (self::DEBUG) {
            echo $text;
            if ($newline === true) {
                echo "<br />\n";
            }
        }
    }
}

/**
 * Class reprensenting the masking for Full code
 */
class _BCGqrcode_CodeMaskFull extends _BCGqrcode_CodeMaskBase {
    const DEBUG = BCGqrcode::DEBUG;

    private $maskConditions = array(
            '($x + $y) % 2 == 0',
            '$y % 2 == 0',
            '$x % 3 == 0',
            '($y + $x) % 3 == 0',
            '(intval($y / 2) + intval($x / 3)) % 2 == 0',
            '($y * $x) % 2 + ($y * $x) % 3 == 0',
            '(($y * $x) % 2 + ($y * $x) % 3) % 2 == 0',
            '(($y + $x) % 2 + ($y * $x) % 3) % 2 == 0'
        );

    public function __construct(_BCGqrcode_Info $qrInfo, $maskId) {
        parent::__construct($qrInfo, $maskId);
    }

    protected function getMaskConditions() {
        return $this->maskConditions;
    }

    /**
     * Condition 1: Adjacent modules in row/column in same color.
     * Condition 2: Block of modules in same color.
     * Condition 3: Existence of the pattern.
     * Condition 4: Proportion of the dark module in the entire symbol.
     *
     * Condition 1
     * We analyze row by row and column by column to find 6 or more
     * pixels of the same color.
     *
     * Condition 2
     * We have to find blocks bigger than 2x2
     * We do not do this since it's really cpu consuming.
     *
     * Condition 3
     * We check if we have an existence of the pattern.
     * To do this, we use a state machine that remembers if we find
     * 1:1:3:1:1 (dark:light:dark:light:dark) preceded or followed by 4 light
     *
     * Condition 4
     * We save the number of black pixel and we check the value $k
     * 50 � (5 � k)% to 50 � (5 � (k + 1))%
     *
     * @param _BCGqrcode_Pixel[][][] $mask
     * @param int $c Size of the barcode
     * @return int Key of the best mask
     */
    protected function applyMaskConditions($masks) {
        $c = _BCGqrcode_Info::getSize($this->qrInfo);
        $numberOfMasks = 8;
        $condition3StateMachine = new _BCGqrcode_Condition3StateMachine();

        $penalty1 = array_fill(0, $numberOfMasks, 3);
        $penalty2 = array_fill(0, $numberOfMasks, 0);
        $penalty3 = array_fill(0, $numberOfMasks, 0);
        $penalty4 = array_fill(0, $numberOfMasks, 0);
        for ($i = 0; $i < $numberOfMasks; $i++) {
            $black = 0;

            for ($x = 0; $x < $c; $x++) {
                $condition3StateMachine->reset();

                $counter = 0;
                $color = $masks[$i][$x][0]->pixel;

                for ($y = 0; $y < $c; $y++) {
                    if ($masks[$i][$x][$y]->pixel) {
                        $black++;
                    }

                    if ($condition3StateMachine->insert($masks[$i][$x][$y]->pixel)) {
                        self::debug('[MASKING] Found pattern in vertical in mask ' . $i);
                        $penalty3[$i] += 40;
                    }

                    if ($masks[$i][$x][$y]->pixel === $color) {
                        $counter++;
                    } else {
                        if ($counter > 5) {
                            $penalty1[$i] += $counter - 5;
                        }

                        $color = $masks[$i][$x][$y]->pixel;
                        $counter = 1;
                    }
                }

                if ($counter > 5) {
                    $penalty1[$i] += $counter - 5;
                }
            }

            for ($y = 0; $y < $c; $y++) {
                $condition3StateMachine->reset();

                $counter = 0;
                $color = $masks[$i][0][$y]->pixel;

                for ($x = 0; $x < $c; $x++) {
                    if ($condition3StateMachine->insert($masks[$i][$x][$y]->pixel)) {
                        self::debug('[MASKING] Found pattern in horizontal in mask ' . $i);
                        $penalty3[$i] += 40;
                    }

                    if ($masks[$i][$x][$y]->pixel === $color) {
                        $counter++;
                    } else {
                        if ($counter > 5) {
                            $penalty1[$i] += $counter - 5;
                        }

                        $color = $masks[$i][$x][$y]->pixel;
                        $counter = 1;
                    }
                }

                if ($counter > 5) {
                    $penalty1[$i] += $counter - 5;
                }
            }

            // Calculates the penalty4. abs(intval(((black/total) * 100) - 50) / 5))
            $k = abs(intval((($black / ($c * $c) * 100) - 50) / 5));
            $penalty4[$i] = 10 * $k;
        }

        $final = array_fill(0, $numberOfMasks, 0);
        for ($i = 0; $i < $numberOfMasks; $i++) {
            $final[$i] += $penalty1[$i];
            $final[$i] += $penalty2[$i];
            $final[$i] += $penalty3[$i];
            $final[$i] += $penalty4[$i];

            self::debug('MASK ' . $i . ': ' . $final[$i]);
        }

        // We want the smallest number
        asort($final, SORT_NUMERIC);
        $maskWin = each($final);

        return $maskWin['key'];
    }

    /**
     * Debug
     *
     * @param string $text
     * @param bool $newline
     */
    private static function debug($text, $newline = true) {
        if (self::DEBUG) {
            echo $text;
            if ($newline === true) {
                echo "<br />\n";
            }
        }
    }
}

/**
 * State machine for condition 3 in masking.
 * This checks if we have 1 : 1 : 3 : 1 : 1 (d:l:d:l:d)
 * preceded by 4 light modules (path 1) or followed by
 * 4 light modules (path 2).
 */
class _BCGqrcode_Condition3StateMachine {
    private $length1;
    private $length2;

    /**
     * Constructor. Resets the path length
     */
    public function __construct() {
        $this->reset();
    }

    /**
     * Resets the path
     */
    public function reset() {
        $this->length1 = 0;
        $this->length2 = 0;
    }

    /**
     * Verifies if we have a matching path for 1 or 2 based
     * on the input $pixelColor
     *
     * @param bool $pixelColor true is dark
     * @return bool
     */
    public function insert($pixelColor) {
        $pattern = false;
        $pattern |= $this->doPath1($pixelColor);
        $pattern |= $this->doPath2($pixelColor);

        return $pattern;
    }

    private function doPath1($pixelColor) {
        switch ($this->length1) {
            case 0:
            case 1:
            case 2:
            case 3:
            case 5:
            case 9:
                // Wrong color
                if ($pixelColor) {
                    $this->length1 = 0;
                    return false;
                }

                break;
            case 4:
            case 6:
            case 7:
            case 8:
            case 10:
                // Wrong color
                if (!$pixelColor) {
                    $this->length1 = 0;
                    return false;
                }

                break;
        }

        if ($this->length1 === 10) {
            $this->length1 = 0;
            return true;
        }

        $this->length1++;

        return false;
    }

    private function doPath2($pixelColor) {
        switch ($this->length2) {
            case 1:
            case 5:
            case 7:
            case 8:
            case 9:
            case 10:
                // Wrong color
                if ($pixelColor) {
                    $this->length2 = 0;
                    return false;
                }

                break;
            case 0:
            case 2:
            case 3:
            case 4:
            case 6:
                // Wrong color
                if (!$pixelColor) {
                    $this->length2 = 0;
                    return false;
                }

                break;
        }

        if ($this->length2 === 10) {
            $this->length2 = 0;
            return true;
        }

        $this->length2++;

        return false;
    }
}
?>