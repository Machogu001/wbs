<?php
require_once __DIR__ . '/../config/sms_config.php';

class SMS {
	private $baseUrl;
	private $sendEndpoint;
	private $apiToken;
	private $senderId;

	public function __construct() {
		$this->baseUrl = rtrim(SmsConfig::BASE_URL, '/');
		$this->sendEndpoint = SmsConfig::SEND_ENDPOINT;
		$this->apiToken = SmsConfig::API_TOKEN;
		$this->senderId = SmsConfig::SENDER_ID;
	}

	public function send($phone, $message) {
		if (empty($this->apiToken) || empty($this->senderId)) {
			return [
				'success' => false,
				'message' => 'SMS credentials not configured'
			];
		}

		$payload = [
			'senderID' => $this->senderId,
			'message' => $message,
			'phone' => $this->normalizePhone($phone)
		];

		$url = $this->baseUrl . $this->sendEndpoint;

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, SmsConfig::TIMEOUT);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->apiToken
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			return [
				'success' => false,
				'message' => 'SMS request failed: ' . $error
			];
		}

		$decoded = json_decode($response, true);
		$success = ($httpCode >= 200 && $httpCode < 300) && isset($decoded['status']) && $decoded['status'] === true;

		return [
			'success' => $success,
			'http_code' => $httpCode,
			'response' => $response
		];
	}

	private function normalizePhone($phone) {
		$phone = preg_replace('/\D/', '', $phone);
		if (substr($phone, 0, 1) === '0') {
			$phone = '254' . substr($phone, 1);
		} elseif (substr($phone, 0, 3) !== '254') {
			$phone = '254' . $phone;
		}
		return $phone;
	}
}
?>
