<?php
require_once __DIR__ . '/../config/database.php';

class ApprovalWorkflow {
	private $db;

	public function __construct($db = null) {
		if ($db === null) {
			throw new Exception('Database connection required for ApprovalWorkflow');
		}
		$this->db = $db;
	}

	/**
	 * Create a new approval workflow for a meter reading
	 * Starts at stage 1 (Supervisor approval)
	 */
	public function createWorkflow($meter_reading_id, $submitted_by_user_id) {
		$stmt = $this->db->prepare("
			INSERT INTO approval_workflows (meter_reading_id, submitted_by, current_stage, status, created_at)
			VALUES (?, ?, 1, 'pending_supervisor', NOW())
		");

		if (!$stmt->execute([$meter_reading_id, $submitted_by_user_id])) {
			return false;
		}

		$workflow_id = $this->db->lastInsertId();

		// Create stage 1 approval record (Supervisor)
		$this->createApprovalStage($workflow_id, 1, 'Supervisor Approval', 'supervisor');

		return $workflow_id;
	}

	/**
	 * Create an approval stage record
	 */
	private function createApprovalStage($workflow_id, $stage_number, $stage_name, $role_required) {
		$stmt = $this->db->prepare("
			INSERT INTO approval_stages (workflow_id, stage_number, stage_name, role_required, status)
			VALUES (?, ?, ?, ?, 'pending')
		");

		return $stmt->execute([$workflow_id, $stage_number, $stage_name, $role_required]);
	}

	/**
	 * Get workflow by meter reading ID
	 */
	public function getWorkflowByReading($meter_reading_id) {
		$stmt = $this->db->prepare("
			SELECT * FROM approval_workflows
			WHERE meter_reading_id = ?
			LIMIT 1
		");
		$stmt->execute([$meter_reading_id]);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Approve a stage and advance workflow
	 */
	public function approveStage($workflow_id, $stage_number, $approved_by_user_id, $comments = null) {
		// Update the stage as approved
		$stmt = $this->db->prepare("
			UPDATE approval_stages
			SET status = 'approved', approved_by = ?, approved_at = NOW(), comments = ?
			WHERE workflow_id = ? AND stage_number = ?
		");
		$stmt->execute([$approved_by_user_id, $comments, $workflow_id, $stage_number]);

		// Check if all stages are approved
		$stmtCheck = $this->db->prepare("
			SELECT COUNT(*) as pending_count
			FROM approval_stages
			WHERE workflow_id = ? AND status = 'pending'
		");
		$stmtCheck->execute([$workflow_id]);
		$result = $stmtCheck->fetch(PDO::FETCH_ASSOC);

		if ($result['pending_count'] == 0) {
			// All stages approved, update workflow status
			$stmtWorkflow = $this->db->prepare("
				UPDATE approval_workflows
				SET status = 'approved', approved_at = NOW(), final_approved_by = ?
				WHERE id = ?
			");
			$stmtWorkflow->execute([$approved_by_user_id, $workflow_id]);

			return 'completed';
		} else {
			// More stages to go, update current stage
			$nextStage = $stage_number + 1;
			$stmtNext = $this->db->prepare("
				UPDATE approval_workflows
				SET current_stage = ?, status = ?
				WHERE id = ?
			");

			$stageNames = [
				2 => 'pending_finance',
				// Add more if needed
			];

			$nextStatus = $stageNames[$nextStage] ?? 'pending_approval';
			$stmtNext->execute([$nextStage, $nextStatus, $workflow_id]);

			return 'next_stage';
		}
	}

	/**
	 * Reject a stage - sends back to pending
	 */
	public function rejectStage($workflow_id, $stage_number, $rejected_by_user_id, $reason) {
		$stmt = $this->db->prepare("
			UPDATE approval_stages
			SET status = 'rejected', approved_by = ?, approved_at = NOW(), comments = ?
			WHERE workflow_id = ? AND stage_number = ?
		");
		$stmt->execute([$rejected_by_user_id, $reason, $workflow_id, $stage_number]);

		// Mark workflow as rejected
		$stmtWorkflow = $this->db->prepare("
			UPDATE approval_workflows
			SET status = 'rejected', rejected_at = NOW()
			WHERE id = ?
		");
		$stmtWorkflow->execute([$workflow_id]);

		return true;
	}

	/**
	 * Get pending approvals for a user role
	 */
	public function getPendingApprovals($role) {
		$stmt = $this->db->prepare("
			SELECT aw.*, ast.stage_number, ast.stage_name, mr.meter_number, u.first_name, u.last_name
			FROM approval_workflows aw
			JOIN approval_stages ast ON aw.id = ast.workflow_id
			JOIN meter_readings mr ON aw.meter_reading_id = mr.id
			JOIN users u ON mr.user_id = u.id
			WHERE ast.role_required = ? AND ast.status = 'pending'
			ORDER BY aw.created_at ASC
		");
		$stmt->execute([$role]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get approval history for a reading
	 */
	public function getApprovalHistory($meter_reading_id) {
		$stmt = $this->db->prepare("
			SELECT aw.*, u.first_name, u.last_name
			FROM approval_workflows aw
			LEFT JOIN users u ON aw.final_approved_by = u.id
			WHERE aw.meter_reading_id = ?
		");
		$stmt->execute([$meter_reading_id]);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Ensure tables exist
	 */
	public static function ensureTables($db = null) {
		if ($db === null) {
			$database = new Database();
			$db = $database->getConnection();
		}

		$db->exec("
			CREATE TABLE IF NOT EXISTS approval_workflows (
				id INT AUTO_INCREMENT PRIMARY KEY,
				meter_reading_id INT NOT NULL UNIQUE,
				submitted_by INT NOT NULL,
				current_stage INT DEFAULT 1,
				status ENUM('pending_supervisor', 'pending_finance', 'approved', 'rejected') DEFAULT 'pending_supervisor',
				approved_at TIMESTAMP NULL,
				rejected_at TIMESTAMP NULL,
				final_approved_by INT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (meter_reading_id) REFERENCES meter_readings(id),
				FOREIGN KEY (submitted_by) REFERENCES users(id),
				FOREIGN KEY (final_approved_by) REFERENCES users(id),
				INDEX idx_status (status),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		$db->exec("
			CREATE TABLE IF NOT EXISTS approval_stages (
				id INT AUTO_INCREMENT PRIMARY KEY,
				workflow_id INT NOT NULL,
				stage_number INT NOT NULL,
				stage_name VARCHAR(100),
				role_required VARCHAR(50),
				status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
				approved_by INT NULL,
				approved_at TIMESTAMP NULL,
				comments TEXT NULL,
				FOREIGN KEY (workflow_id) REFERENCES approval_workflows(id),
				FOREIGN KEY (approved_by) REFERENCES users(id),
				UNIQUE KEY unique_workflow_stage (workflow_id, stage_number),
				INDEX idx_status (status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}
}

?>
