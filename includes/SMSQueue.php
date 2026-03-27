<?php
require_once __DIR__ . '/../config/database.php';

class SMSQueue {
	private $db;

	public function __construct($db = null) {
		if ($db === null) {
			throw new Exception('Database connection required for SMSQueue');
		}
		$this->db = $db;
	}

	/**
	 * Queue an SMS message for sending
	 */
	public function queue($phone, $message, $type = 'general') {
		$stmt = $this->db->prepare("
			INSERT INTO sms_queue (phone, message, type, status, created_at, retry_count)
			VALUES (?, ?, ?, 'pending', NOW(), 0)
		");
		
		return $stmt->execute([$phone, $message, $type]);
	}

	/**
	 * Get pending SMS messages (limit to prevent API throttling)
	 */
	public function getPending($limit = 50) {
		$stmt = $this->db->prepare("
			SELECT * FROM sms_queue
			WHERE status = 'pending' AND retry_count < 3
			ORDER BY created_at ASC
			LIMIT ?
		");
		$stmt->execute([$limit]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get pending SMS for admin visibility (includes all retry counts)
	 */
	public function getPendingForReview($limit = 200) {
		$stmt = $this->db->prepare("
			SELECT * FROM sms_queue
			WHERE status = 'pending'
			ORDER BY created_at ASC
			LIMIT ?
		");
		$stmt->execute([$limit]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Count queue items by status
	 */
	public function countByStatus($status) {
		$allowed = ['pending', 'sent', 'failed_permanent'];
		if (!in_array($status, $allowed, true)) {
			throw new InvalidArgumentException('Invalid SMS queue status.');
		}

		$stmt = $this->db->prepare("SELECT COUNT(*) FROM sms_queue WHERE status = ?");
		$stmt->execute([$status]);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Get queue items by status for admin review
	 */
	public function getByStatusForReview($status, $limit = 200) {
		$allowed = ['pending', 'sent', 'failed_permanent'];
		if (!in_array($status, $allowed, true)) {
			throw new InvalidArgumentException('Invalid SMS queue status.');
		}

		$stmt = $this->db->prepare("
			SELECT * FROM sms_queue
			WHERE status = ?
			ORDER BY created_at DESC
			LIMIT ?
		");
		$stmt->execute([$status, $limit]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Mark as sent and log the response
	 */
	public function markSent($queue_id, $response, $http_code) {
		$stmt = $this->db->prepare("
			UPDATE sms_queue
			SET status = 'sent', sent_at = NOW(), response = ?, http_code = ?
			WHERE id = ?
		");
		
		return $stmt->execute([$response, $http_code, $queue_id]);
	}

	/**
	 * Mark as failed and increment retry count
	 */
	public function markFailed($queue_id, $error_message) {
		$stmt = $this->db->prepare("
			UPDATE sms_queue
			SET status = 'pending', retry_count = retry_count + 1, last_error = ?, last_attempt = NOW()
			WHERE id = ?
		");
		
		return $stmt->execute([$error_message, $queue_id]);
	}

	/**
	 * Increment retry and move to failed_permanent when max retries is reached
	 */
	public function markFailedWithThreshold($queue_id, $error_message, $maxRetries = 3) {
		$this->markFailed($queue_id, $error_message);

		$stmt = $this->db->prepare("SELECT retry_count FROM sms_queue WHERE id = ? LIMIT 1");
		$stmt->execute([$queue_id]);
		$retryCount = (int)$stmt->fetchColumn();

		if ($retryCount >= (int)$maxRetries) {
			$this->markFailedPermanent($queue_id, $error_message);
			return 'failed_permanent';
		}

		return 'pending';
	}

	/**
	 * Fetch one queue message by id
	 */
	public function getById($queueId) {
		$stmt = $this->db->prepare("SELECT * FROM sms_queue WHERE id = ? LIMIT 1");
		$stmt->execute([(int)$queueId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Mark as permanently failed (max retries exceeded)
	 */
	public function markFailedPermanent($queue_id, $error_message) {
		$stmt = $this->db->prepare("
			UPDATE sms_queue
			SET status = 'failed_permanent', last_error = ?, last_attempt = NOW()
			WHERE id = ?
		");
		
		return $stmt->execute([$error_message, $queue_id]);
	}

	/**
	 * Get sent SMS by phone and date range
	 */
	public function getSentByPhone($phone, $startDate = null, $endDate = null) {
		$query = "SELECT * FROM sms_queue WHERE phone = ? AND status = 'sent'";
		$params = [$phone];

		if ($startDate) {
			$query .= " AND sent_at >= ?";
			$params[] = $startDate;
		}
		if ($endDate) {
			$query .= " AND sent_at <= ?";
			$params[] = $endDate;
		}

		$query .= " ORDER BY sent_at DESC";

		$stmt = $this->db->prepare($query);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get queue stats
	 */
	public function getStats() {
		$stmt = $this->db->prepare("
			SELECT
				status,
				COUNT(*) as count,
				COUNT(CASE WHEN retry_count > 0 THEN 1 END) as retried
			FROM sms_queue
			GROUP BY status
		");
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Ensure table exists
	 */
	public static function ensureTable($db = null) {
		if ($db === null) {
			$database = new Database();
			$db = $database->getConnection();
		}
		$db->exec("
			CREATE TABLE IF NOT EXISTS sms_queue (
				id INT AUTO_INCREMENT PRIMARY KEY,
				phone VARCHAR(20) NOT NULL,
				message TEXT NOT NULL,
				type VARCHAR(50) DEFAULT 'general',
				status ENUM('pending', 'sent', 'failed_permanent') DEFAULT 'pending',
				http_code INT NULL,
				response TEXT NULL,
				retry_count INT DEFAULT 0,
				last_error TEXT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				sent_at TIMESTAMP NULL,
				last_attempt TIMESTAMP NULL,
				INDEX idx_status (status),
				INDEX idx_phone (phone),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}
}

?>
