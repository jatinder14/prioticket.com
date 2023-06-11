<?php
function baseCustomSetup($barcode, $get) {
    $font_dir = '..' . DIRECTORY_SEPARATOR . 'font';

    if ($get['font_family'] !== '0' && intval($get['font_size']) >= 1) {
        $text = convertText($get['text']);

        $label = new BCGLabel();
        $label->setFont(new BCGFontFile($font_dir . '/' . $get['font_family'], intval($get['font_size'])));
        $label->setPosition(BCGLabel::POSITION_BOTTOM);
        $label->setAlignment(BCGLabel::ALIGN_CENTER);
        $label->setText($text);
        $barcode->addLabel($label);
    }
}
?>