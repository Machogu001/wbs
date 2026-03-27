<?php
require_once __DIR__ . '/../config/database.php';

class CustomerCredit {
	private $db;

	public function __construct($db = null) {
		if ($db === null) {
			throw new Exception('Database connection required for CustomerCredit');
		}
		$this->db = $db;
	}

	/**
	 * Initialize credit profile for a new customer
	 */
	public function initializeCredit($user_id, $credit_limit = 10000) {
		$stmt = $this->db->prepare("
			INSERT INTO customer_credits (user_id, credit_limit, available_credit, status)
			VALUES (?, ?, ?, 'active')
			ON DUPLICATE KEY UPDATE credit_limit = ?, available_credit = ?
		");

		return $stmt->execute([
			$user_id,
			$credit_limit,
			$credit_limit,
			$credit_limit,
			$credit_limit
		]);
	}

	/**
	 * Get credit profile
	 */
	public function getProfile($user_id) {
		$stmt = $this->db->prepare("
			SELECT * FROM customer_credits WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Update available credit (deduct when bill is created)
	 */
	public function deductCredit($user_id, $amount) {
		$stmt = $this->db->prepare("
			UPDATE customer_credits
			SET available_credit = available_credit - ?,
				last_activity = NOW()
			WHERE user_id = ?
		");

		if ($stmt->execute([$amount, $user_id])) {
			// Check if we need to flag account
			$this->checkAndUpdateDelinquency($user_id);
			return true;
		}

		return false;
	}

	/**
	 * Restore credit when bill is paid
	 */
	public function restoreCredit($user_id, $amount) {
		$stmt = $this->db->prepare("
			UPDATE customer_credits
			SET available_credit = LEAST(credit_limit, available_credit + ?),
				payment_count = payment_count + 1,
				last_payment = NOW(),
				last_activity = NOW()
			WHERE user_id = ?
		");

		if ($stmt->execute([$amount, $user_id])) {
			// Clear delinquency if all arrears paid
			$this->checkAndClearDelinquency($user_id);
			return true;
		}

		return false;
	}

	/**
	 * Check if customer has exceeded credit limit
	 */
	public function hasExceededLimit($user_id) {
		$stmt = $this->db->prepare("
			SELECT available_credit FROM customer_credits WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result && $result['available_credit'] < 0;
	}

	/**
	 * Get available credit for customer
	 */
	public function getAvailableCredit($user_id) {
		$stmt = $this->db->prepare("
			SELECT available_credit, credit_limit FROM customer_credits WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result ? max(0, $result['available_credit']) : 0;
	}

	/**
	 * Update credit limit
	 */
	public function updateCreditLimit($user_id, $new_limit) {
		$stmt = $this->db->prepare("
			UPDATE customer_credits
			SET credit_limit = ?,
				available_credit = ?
			WHERE user_id = ?
		");

		// Get current balance
		$stmtCurrent = $this->db->prepare("
			SELECT (credit_limit - available_credit) as balance FROM customer_credits WHERE user_id = ?
		");
		$stmtCurrent->execute([$user_id]);
		$currentProfile = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
		$newAvailable = $new_limit - ($currentProfile ? $currentProfile['balance'] : 0);

		return $stmt->execute([$new_limit, $newAvailable, $user_id]);
	}

	/**
	 * Check and update delinquency status
	 */
	private function checkAndUpdateDelinquency($user_id) {
		$stmt = $this->db->prepare("
			SELECT available_credit, delinquency_days FROM customer_credits WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		$profile = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($profile && $profile['available_credit'] < 0) {
			// Account is now delinquent
			$stmtUpdate = $this->db->prepare("
				UPDATE customer_credits
				SET delinquency_status = 'delinquent', delinquency_flagged_at = NOW()
				WHERE user_id = ? AND delinquency_status != 'delinquent'
			");
			$stmtUpdate->execute([$user_id]);
		}
	}

	/**
	 * Check and clear delinquency if arrears cleared
	 */
	private function checkAndClearDelinquency($user_id) {
		$stmt = $this->db->prepare("
			SELECT available_credit FROM customer_credits WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		$profile = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($profile && $profile['available_credit'] >= 0) {
			// Arrears cleared
			$stmtUpdate = $this->db->prepare("
				UPDATE customer_credits
				SET delinquency_status = 'active', delinquency_resolved_at = NOW()
				WHERE user_id = ?
			");
			$stmtUpdate->execute([$user_id]);
		}
	}

	/**
	 * Get delinquent customers
	 */
	public function getDelinquentCustomers($limit = 100) {
		$stmt = $this->db->prepare("
			SELECT cc.*, u.first_name, u.last_name, u.phone_number,
				   (cc.credit_limit - cc.available_credit) as arrears_amount
			FROM customer_credits cc
			JOIN users u ON cc.user_id = u.id
			WHERE cc.delinquency_status = 'delinquent'
			ORDER BY cc.delinquency_flagged_at DESC
			LIMIT ?
		");
		$stmt->execute([$limit]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get customer payment behavior score (0-100)
	 */
	public function getPaymentBehaviorScore($user_id) {
		$stmt = $this->db->prepare("
			SELECT
				payment_count,
				late_payment_count,
				is_suspended,
				COALESCE(delinquency_status, 'active') as status
			FROM customer_credits
			WHERE user_id = ?
		");
		$stmt->execute([$user_id]);
		$profile = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$profile) {
			return 50; // Default score for new customers
		}

		$score = 100;

		// Deduct points for late payments
		if ($profile['late_payment_count'] > 0) {
			$score -= min(30, $profile['late_payment_count'] * 10);
		}

		// Deduct for suspended account
		if ($profile['is_suspended']) {
			$score -= 40;
		}

		// Deduct for current delinquency
		if ($profile['status'] === 'delinquent') {
			$score -= 20;
		}

		// Bonus for good payment history
		if ($profile['payment_count'] >= 12) {
			$score = min(100, $score + 10);
		}

		return max(0, $score);
	}

	/**
	 * Suspend customer account for non-payment
	 */
	public function suspend($user_id, $reason = null) {
		$stmt = $this->db->prepare("
			UPDATE customer_credits
			SET is_suspended = 1, suspended_at = NOW(), suspension_reason = ?
			WHERE user_id = ?
		");

		return $stmt->execute([$reason, $user_id]);
	}

	/**
	 * Unsuspend customer account
	 */
	public function unsuspend($user_id) {
		$stmt = $this->db->prepare("
			UPDATE customer_credits
			SET is_suspended = 0, unsuspended_at = NOW()
			WHERE user_id = ?
		");

		return $stmt->execute([$user_id]);
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
			CREATE TABLE IF NOT EXISTS customer_credits (
				id INT AUTO_INCREMENT PRIMARY KEY,
				user_id INT NOT NULL UNIQUE,
				credit_limit DECIMAL(10, 2) DEFAULT 10000.00,
				available_credit DECIMAL(10, 2) DEFAULT 10000.00,
				status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
				delinquency_status ENUM('active', 'delinquent') DEFAULT 'active',
				delinquency_flagged_at TIMESTAMP NULL,
				delinquency_resolved_at TIMESTAMP NULL,
				delinquency_days INT DEFAULT 0,
				payment_count INT DEFAULT 0,
				late_payment_count INT DEFAULT 0,
				is_suspended BOOLEAN DEFAULT FALSE,
				suspended_at TIMESTAMP NULL,
				unsuspended_at TIMESTAMP NULL,
				suspension_reason TEXT NULL,
				last_payment TIMESTAMP NULL,
				last_activity TIMESTAMP NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (user_id) REFERENCES users(id),
				INDEX idx_delinquency (delinquency_status),
				INDEX idx_suspended (is_suspended)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}
}

?>
