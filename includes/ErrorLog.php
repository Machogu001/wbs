<?php
require_once __DIR__ . '/../config/database.php';

class ErrorLog {
	private $db;

	public function __construct($db = null) {
		if ($db === null) {
			throw new Exception('Database connection required for ErrorLog');
		}
		$this->db = $db;
	}

	/**
	 * Log an API error (M-Pesa, MobileSasa, etc)
	 */
	public function logApiError($service, $endpoint, $http_code, $error_message, $request_data = null, $response_data = null) {
		$stmt = $this->db->prepare("
			INSERT INTO error_logs (service, endpoint, http_code, error_message, request_data, response_data, created_at)
			VALUES (?, ?, ?, ?, ?, ?, NOW())
		");

		$request_json = $request_data ? json_encode($request_data) : null;
		$response_json = $response_data ? json_encode($response_data) : null;

		return $stmt->execute([
			$service,
			$endpoint,
			$http_code,
			$error_message,
			$request_json,
			$response_json
		]);
	}

	/**
	 * Log a system error
	 */
	public function logSystemError($category, $message, $file = null, $line = null, $context = null) {
		$stmt = $this->db->prepare("
			INSERT INTO error_logs (service, category, error_message, file, line, context, created_at)
			VALUES (?, ?, ?, ?, ?, ?, NOW())
		");

		$context_json = $context ? json_encode($context) : null;

		return $stmt->execute([
			'system',
			$category,
			$message,
			$file,
			$line,
			$context_json
		]);
	}

	/**
	 * Get recent errors
	 */
	public function getRecent($limit = 100, $service = null) {
		$query = "SELECT * FROM error_logs WHERE 1=1";
		$params = [];

		if ($service) {
			$query .= " AND service = :service";
		}

		$query .= " ORDER BY created_at DESC LIMIT :limit";

		$stmt = $this->db->prepare($query);
		if ($service) {
			$stmt->bindParam(':service', $service);
		}
		$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get error count by service for the last N hours
	 */
	public function getErrorCountByService($hours = 24) {
		$stmt = $this->db->prepare("
			SELECT service, COUNT(*) as error_count
			FROM error_logs
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
			GROUP BY service
			ORDER BY error_count DESC
		");
		$stmt->execute([$hours]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get errors within a date range
	 */
	public function getByDateRange($startDate, $endDate, $service = null) {
		$query = "SELECT * FROM error_logs WHERE created_at BETWEEN ? AND ?";
		$params = [$startDate, $endDate];

		if ($service) {
			$query .= " AND service = ?";
			$params[] = $service;
		}

		$query .= " ORDER BY created_at DESC";

		$stmt = $this->db->prepare($query);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Clear old logs (older than X days)
	 */
	public function archiveOldLogs($days = 90) {
		$stmt = $this->db->prepare("
			DELETE FROM error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
		");
		return $stmt->execute([$days]);
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
			CREATE TABLE IF NOT EXISTS error_logs (
				id INT AUTO_INCREMENT PRIMARY KEY,
				service VARCHAR(50) NOT NULL,
				endpoint VARCHAR(255) NULL,
				category VARCHAR(100) NULL,
				http_code INT NULL,
				error_message TEXT NOT NULL,
				file VARCHAR(255) NULL,
				line INT NULL,
				request_data LONGTEXT NULL,
				response_data LONGTEXT NULL,
				context LONGTEXT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_service (service),
				INDEX idx_created (created_at),
				INDEX idx_http_code (http_code)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}
}

?>
