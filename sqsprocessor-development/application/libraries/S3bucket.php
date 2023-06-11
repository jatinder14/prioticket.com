<?php

use Aws\S3\S3Client;

class S3bucket {

    function S3bucket() {
        
    }

    function get_iterator() {
        $s3Client = S3Client::factory(array(
                    'credentials' => array(
                        'key' => 'AKIAJD66FDDUKRFLWTNQ',
                        'secret' => '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',
                    ),
                    'region' => 'eu-west-1',
                    'scheme' => 'http',
                    'version' => 'latest',
        ));

//downloadBucket function is used to save images directly on given path
//$result = $s3Client->downloadBucket('/var/www/test.prioticket.com/qrcodes/images/shop-products-images/311/', 's3lookoutshopimagerepository');
//Get list of all objects of particular bucket
        $iterator = $s3Client->getIterator('ListObjects', array(
            'Bucket' => 's3lookoutshopimagerepository'
        ));

        foreach ($iterator as $object) {
            // Check if any already exist on given path then don't update the particular image
            if ($object['Key'] != '' && strpos($object['Key'], 'ADAM Lookout BV/') !== false) {
                $image_key = str_replace('ADAM Lookout BV/', '', $object['Key']);
                
                $for_image_extension = explode(".", $image_key);
                $image_extension =  $for_image_extension[1];
                if($image_extension != '' && $image_extension != 'jpg'  && $image_extension != 'jpeg') {
                    if (!file_exists('/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/' . $image_key)) {
                        $result = $s3Client->getObject(array(
                            'Bucket' => 's3lookoutshopimagerepository',
                            'Key' => $object['Key'],
                            'SaveAs' => '/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/updated-' . $image_key,
                        ));
                        $product_image = '/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/updated-' . $image_key;
                        //$imgData = $this->resize_image($product_image, '171', '171', true);
                        $resizedFilename = '/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/' . $image_key;

                        $thumb = new CI_Thumbnail();
                        $thumb->smart_resize_image($product_image, $w = 171, $h = 171, true, $resizedFilename, false);

                        // save the image on the given filename
                        //imagepng($imgData, $resizedFilename);
                        unlink($product_image);
                        //rename($resizedFilename, $product_image);
//                        $imgData = $this->compress($resizedFilename, $product_image, 9);
//                        unlink($resizedFilename);
                        $product_number = explode(".", $image_key);
                        $product_number = $product_number[0];
                        $arr[$product_number] =  $image_key;
                    }
                }
            }
        }
        return $arr;
    }

    function get_iterator_v1() {
        $s3Client = S3Client::factory(array(
                    'credentials' => array(
                        'key' => 'AKIAJD66FDDUKRFLWTNQ',
                        'secret' => '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',
                    ),
                    'region' => 'eu-west-1',
                    'scheme' => 'http',
                    'version' => 'latest',
        ));

//downloadBucket function is used to save images directly on given path
//$result = $s3Client->downloadBucket('/var/www/test.prioticket.com/qrcodes/images/shop-products-images/311/', 's3lookoutshopimagerepository');
//Get list of all objects of particular bucket
        $iterator = $s3Client->getIterator('ListObjects', array(
            'Bucket' => 's3lookoutshopimagerepository'
        ));
        $arr=array();
        echo "All images in bucket<br><pre>";
        print_r($iterator);
        echo "</pre>";
        //exit;
        foreach ($iterator as $object) {
            // Check if any already exist on given path then don't update the particular image
                $for_image_extension = explode(".", $image_key);
                $image_extension =  $for_image_extension[1];
                if($image_extension != '' && $image_extension != 'jpg'  && $image_extension != 'jpeg') {
                    if ($object['Key'] != '' && strpos($object['Key'], 'ADAM Lookout BV/') !== false) {
                        $image_key = str_replace('ADAM Lookout BV/', '', $object['Key']);
                        $product_number = explode(".", $image_key);
                        $product_number = $product_number[0];
                        $arr[$product_number] =  $image_key;
                    }
                }
                
        }
        return $arr;
    }

    function compress($source, $destination, $quality) {
        //Get file extension
        $exploding = explode(".", $source);
        $ext = end($exploding);

        switch ($ext) {
            case "png":
                $src = imagecreatefrompng($source);
                break;
            case "jpeg":
            case "jpg":
                $src = imagecreatefromjpeg($source);
                break;
            case "gif":
                $src = imagecreatefromgif($source);
                break;
            default:
                $src = imagecreatefromjpeg($source);
                break;
        }

        switch ($ext) {
            case "png":
                imagepng($src, $destination, $quality);
                break;
            case "jpeg":
            case "jpg":
                imagejpeg($src, $destination, $quality);
                break;
            case "gif":
                imagegif($src, $destination, $quality);
                break;
            default:
                imagejpeg($src, $destination, $quality);
                break;
        }

        return $destination;
    }

}

?>