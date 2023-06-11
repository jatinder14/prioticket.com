<?php
//Include asw-autoloader file in which all sub-directories are included
require 'aws-autoloader.php';

use Aws\S3\S3Client;
//$s3Client = new S3Client();
// Instantiate the S3 client with your AWS credentials
$s3Client = S3Client::factory(array(
            'credentials' => array(
                'key' => 'AKIAJD66FDDUKRFLWTNQ',
                'secret' => 'Ci43/2rMl1SV5Dygb/hBIornkF5ekXFsPJ9ITUkj',
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

//echo "<pre>";
//print_r($iterator);
//echo "</pre>";
//exit;

foreach ($iterator as $object) {
    echo $object['Key'] . "\n";
    
    // Check if any already exist on given path then don't update the particular image
//    if ($object['Key'] != '' && !file_exists('/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/' . $object['Key'])) {
//        $result = $s3Client->getObject(array(
//            'Bucket' => 's3lookoutshopimagerepository',
//            'Key' => $object['Key'],
//            'SaveAs' => '/var/www/html/prioticket.com/qrcodes/images/shop-products-images/358/' . $object['Key']
//        ));
//    }
    echo "<br>";
}
?>