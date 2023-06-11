<?php

/** #Region File to Get Secrets From Middleware and Set in Constant */
/**
 * Created on : - 03-05-2023
 * Created by :- <karanmdev.aipl@gmail.com>
 * USE :- Get Credentials From AWS and Set in Constant 
 */

/** Comment: Load Secret.php From Constant File  */
if (file_exists(APPPATH . 'config/secret.php')) {
    require_once(APPPATH . 'config/secret.php');
}
/** #Comment:-  Load Aws Autoloader For Credential Provider Loader */
require_once 'aws-php-sdk/aws-autoloader.php';

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Aws\Credentials\CredentialProvider;

/** */
class CI_SecretManager
{
    public function __construct()
    {
        global $_SECRET_MANAGER;
        /** #Comment : Store Secrets In Cache */
        $ci = &get_instance();
        $ci->load->driver('cache', array('adapter' => 'apc', 'backup' => 'file'));
        if (defined('AWS_SECRET_MANEGR_CACHE_KEY') && defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $cache = $ci->cache->file->get(AWS_SECRET_MANEGR_CACHE_KEY) ?? '';
        }
        if (empty($cache)) {
            $_SECRET_MANAGER = $this->getSecretCredential();
        } else if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            $this->setSecretInConstantFile($this->encryptDecrypt($cache, 'decrypt'));
        }
    }
    /**
     * Funtion : To Get Secret From AWS and Set in Constant File
     */
    public function getSecretCredential()
    {
        $secret = [];
        if (defined('SECRET_MANAGER_ENABLE') && SECRET_MANAGER_ENABLE == 1) {
            if (!in_array(LOCAL_ENVIRONMENT, ["Local", "development"])) {
                try {
                    $provider = CredentialProvider::defaultProvider();
                    $client = new SecretsManagerClient([
                        'version' => 'latest',
                        'region' => AWS_ACCESS_REGION,
                        'credentials' => $provider
                    ]);
                    $secretName = AWS_SECRET_MANAGER_NAME;
                    $result = $client->getSecretValue([
                        'SecretId' => $secretName,
                    ]);
                } catch (AwsException $e) {
                }
                if (!empty($result) && isset($result['SecretString'])) {
                    $ci = &get_instance();
                    $ci->load->driver('cache', array('adapter' => 'apc', 'backup' => 'file'));
                    $ci->cache->file->save(AWS_SECRET_MANEGR_CACHE_KEY, $this->encryptDecrypt($result['SecretString']), AWS_CAHCE_EXPIRY_TIME);
                    $this->setSecretInConstantFile($result['SecretString']);
                }
            } else {
                $secret = SECRET_MANAGER;
            }
        }
        return $secret;
    }

    /**
     * Function TO set Credentials in Constant File 
     */
    public function setSecretInConstantFile($credentials)
    {
        $secret_credentials = !empty($credentials) ? json_decode($credentials) : [];
        $secret = array();
        foreach ($secret_credentials as $key => $aws_cred) {
            $secret[$key] = $aws_cred;
        }
        define('SECRET_MANAGER', $secret);
        return $secret;
    }
    /** #Region Function to encrypt String */
    /**
     * encryptDecrypt
     */
    public function encryptDecrypt($string, $action = "encrypt")
    {
        $output = '';
        $encryptMethod = AWS_ENCRYPTION_METHOD;
        $secretKey = AWS_ENCRYPTION_KEY;
        $iv = AWS_ENCRYPTION_IV;
        /** hashing method*/
        $key = hash('sha256', $secretKey);
        try {
            if ($action == 'encrypt') {
                $output = openssl_encrypt($string, $encryptMethod, $key, 0, $iv);
                $output = base64_encode($output);
            } elseif ($action == 'decrypt') {
                $output = openssl_decrypt(base64_decode($string), $encryptMethod, $key, 0, $iv);
            }
        } catch (\Exception $e) {
            return '';
        }
        return $output;
    }
    /** #EndRegion Function to encrypt String */
}
