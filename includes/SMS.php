<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sms_config.php';
require_once __DIR__ . '/SMSQueue.php';
require_once __DIR__ . '/ErrorLog.php';

class SMS {
	private $baseUrl;
	private $sendEndpoint;
	private $apiToken;
	private $senderId;
	private $queue;
	private $db;

	public function __construct($db = null) {
		// If no DB provided, create a new connection
		if ($db === null) {
			$database = new Database();
			$db = $database->getConnection();
		}
		
		$this->db = $db;
		$this->baseUrl = rtrim(SmsConfig::BASE_URL, '/');
		$this->sendEndpoint = SmsConfig::SEND_ENDPOINT;
		// Load credentials from environment-aware config helpers
		$this->apiToken = SmsConfig::getApiToken();
		$this->senderId = SmsConfig::getSenderId();
		$this->queue = new SMSQueue($this->db);
	}

	/**
	 * Queue an SMS message for sending (async/queued)
	 */
	public function queue($phone, $message, $type = 'general') {
		return $this->queue->queue($phone, $message, $type);
	}

	/**
	 * Send SMS immediately (blocking - used for critical messages)
	 */
	public function send($phone, $message) {
		if (empty($this->apiToken) || empty($this->senderId)) {
			// Queue for later if no credentials
			$this->queue->queue($phone, $message, 'critical');
			return [
				'success' => false,
				'message' => 'SMS credentials not configured - queued for later'
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
		$httpOk = ($httpCode >= 200 && $httpCode < 300);
		$success = false;

		if ($httpOk) {
			if (is_array($decoded)) {
				$statusValue = null;
				if (array_key_exists('status', $decoded)) {
					$statusValue = $decoded['status'];
				} elseif (array_key_exists('success', $decoded)) {
					$statusValue = $decoded['success'];
				} elseif (array_key_exists('ok', $decoded)) {
					$statusValue = $decoded['ok'];
				}

				if (is_bool($statusValue)) {
					$success = $statusValue;
				} elseif (is_numeric($statusValue)) {
					$success = ((int)$statusValue) === 1;
				} elseif (is_string($statusValue)) {
					$success = in_array(strtolower(trim($statusValue)), ['true', '1', 'ok', 'success', 'sent', 'queued', 'accepted'], true);
				}

				// Some providers return message IDs or accepted states without a status key.
				if (!$success) {
					$hasMessageId = !empty($decoded['message_id']) || !empty($decoded['messageId']) || !empty($decoded['id']);
					$state = strtolower((string)($decoded['state'] ?? $decoded['result'] ?? ''));
					$success = $hasMessageId || in_array($state, ['ok', 'success', 'sent', 'queued', 'accepted'], true);
				}
			} else {
				// If provider returns non-JSON but HTTP 2xx, treat as accepted.
				$success = true;
			}
		}

		return [
			'success' => $success,
			'http_code' => $httpCode,
			'response' => $response
		];
	}

	/**
	 * Process queued SMS messages (usually called by a cron job)
	 * Returns number of messages processed
	 */
	public function processPendingQueue($limit = 50) {
		$errorLog = new ErrorLog();
		$pending = $this->queue->getPending($limit);

		if (empty($pending)) {
			return 0;
		}

		$processed = 0;

		foreach ($pending as $item) {
			try {
				$result = $this->send($item['phone'], $item['message']);

				if ($result['success']) {
					$this->queue->markSent($item['id'], $result['response'], $result['http_code']);
					$processed++;
				} else {
					// Try to mark as failed so it retries
					$this->queue->markFailedWithThreshold($item['id'], 'HTTP ' . ($result['http_code'] ?? 'unknown'), 3);

					// Log the error
					$errorLog->logApiError(
						'MobileSasa',
						$this->sendEndpoint,
						$result['http_code'] ?? 0,
						'SMS delivery failed',
						['phone' => $item['phone'], 'message' => $item['message']],
						$result['response'] ?? null
					);
				}
			} catch (Exception $e) {
				// Log exception and mark as failed
				$this->queue->markFailedWithThreshold($item['id'], $e->getMessage(), 3);
				$errorLog->logSystemError('SMS', 'Exception processing queue: ' . $e->getMessage(), __FILE__, __LINE__);
			}
		}

		return $processed;
	}

	/**
	 * Get SMS statistics
	 */
	public function getQueueStats() {
		return $this->queue->getStats();
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
