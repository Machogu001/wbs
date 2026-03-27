<?php
require_once __DIR__ . '/../config/mpesa_config.php';

class Mpesa {
    private $consumerKey;
    private $consumerSecret;
    private $shortCode;
    private $passKey;
    private $callbackUrl;
    private $baseUrl;
    
    public function __construct() {
        $this->consumerKey = MpesaConfig::getConsumerKey();
        $this->consumerSecret = MpesaConfig::getConsumerSecret();
        $this->shortCode = MpesaConfig::getShortCode();
        $this->passKey = MpesaConfig::getPassKey();
        $this->callbackUrl = MpesaConfig::getCallbackUrl();
        $this->baseUrl = MpesaConfig::getBaseUrl();
    }
    
    // Get access token
    public function getAccessToken() {
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $credentials
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($httpCode == 200) {
            $result = json_decode($response);
            return isset($result->access_token) ? $result->access_token : null;
        }
        
        error_log("MPesa Access Token Error: HTTP $httpCode - $response");
        return null;
    }
    
    // Generate password for STK Push
    private function generatePassword() {
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortCode . $this->passKey . $timestamp);
        return array(
            'password' => $password,
            'timestamp' => $timestamp
        );
    }
    
    // Initiate STK Push
    public function stkPush($phone, $amount, $accountReference, $description = "Water Bill Payment") {
        $accessToken = $this->getAccessToken();
        
        if(!$accessToken) {
            return array('error' => 'Failed to get access token');
        }
        
        $passwordData = $this->generatePassword();
        $phone = $this->formatPhoneNumber($phone);
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->baseUrl . '/mpesa/stkpush/v1/processrequest');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ));
        
        $data = array(
            'BusinessShortCode' => $this->shortCode,
            'Password' => $passwordData['password'],
            'Timestamp' => $passwordData['timestamp'],
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->shortCode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => $description
        );
        
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        if($httpCode == 200 && isset($result['ResponseCode']) && $result['ResponseCode'] == '0') {
            return $result;
        } else {
            error_log("MPesa STK Push Error: HTTP $httpCode - " . print_r($result, true));
            return array(
                'error' => 'STK Push failed',
                'details' => $result,
                'http_code' => $httpCode
            );
        }
    }
    
    // Format phone number
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/\D/', '', $phone);
        
        if(substr($phone, 0, 1) == '0') {
            $phone = '254' . substr($phone, 1);
        } elseif(substr($phone, 0, 3) != '254') {
            $phone = '254' . $phone;
        }
        
        return $phone;
    }
}
?>
