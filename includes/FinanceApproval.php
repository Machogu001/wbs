<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/InstallmentPlan.php';
require_once __DIR__ . '/CreditNote.php';

class FinanceApproval {
    private $db;

    public function __construct($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        if ($db === null) {
            throw new Exception('Database connection required for FinanceApproval');
        }

        $this->db = $db;
        self::ensureTable($this->db);
    }

    public static function ensureTable($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        $db->exec("CREATE TABLE IF NOT EXISTS financial_approval_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            reference_no VARCHAR(50) NULL,
            title VARCHAR(191) NOT NULL,
            amount DECIMAL(10,2) DEFAULT 0.00,
            submitted_by INT NULL,
            current_approver_role VARCHAR(50) DEFAULT 'finance',
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            metadata_json LONGTEXT NULL,
            comments TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            rejected_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_entity (entity_type, entity_id),
            INDEX idx_status_role (status, current_approver_role),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getSummary() {
        $stmt = $this->db->query("SELECT status, COUNT(*) AS total FROM financial_approval_items GROUP BY status");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status] = (int)($row['total'] ?? 0);
            }
        }
        return $summary;
    }

    public function getItems($status = '', $limit = 100) {
        $limit = max(1, min(500, (int)$limit));
        $sql = "SELECT fai.*, u.full_name AS submitted_by_name, au.full_name AS approved_by_name
            FROM financial_approval_items fai
            LEFT JOIN users u ON u.id = fai.submitted_by
            LEFT JOIN users au ON au.id = fai.approved_by";
        $params = [];
        if ($status !== '') {
            $sql .= " WHERE fai.status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY fai.created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createFromPayment($paymentId, $amount, $submittedBy, $referenceNo = null) {
        $stmt = $this->db->prepare("INSERT INTO financial_approval_items
            (entity_type, entity_id, reference_no, title, amount, submitted_by, current_approver_role, status, created_at)
            VALUES ('payment', :entity_id, :reference_no, :title, :amount, :submitted_by, 'finance', 'pending', NOW())
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), reference_no = VALUES(reference_no), title = VALUES(title)");
        return $stmt->execute([
            ':entity_id' => (int)$paymentId,
            ':reference_no' => $referenceNo,
            ':title' => 'Payment Approval #' . (int)$paymentId,
            ':amount' => (float)$amount,
            ':submitted_by' => $submittedBy ? (int)$submittedBy : null,
        ]);
    }

    public function createBillWriteOffRequest(int $billId, float $amount, int $submittedBy, string $reason = '', string $requestType = 'writeoff'): bool {
        $entityType = $requestType === 'waiver' ? 'bill_waiver' : 'bill_writeoff';
        $requestAmount = round(max(0, $amount), 2);
        if ($requestAmount <= 0) {
            return false;
        }

        $bill = $this->getBillSnapshot($billId);
        if (!$bill) {
            return false;
        }

        $outstanding = $this->getBillOutstandingAmount($billId);
        if ($outstanding <= 0) {
            return false;
        }

        $requestAmount = min($requestAmount, $outstanding);
        $payload = [
            'requested_amount' => $requestAmount,
            'reason' => trim($reason),
            'requested_at' => date('c'),
            'requested_by' => $submittedBy,
        ];

        $titlePrefix = $requestType === 'waiver' ? 'Bill Waiver' : 'Bill Write-off';
        $referenceNo = strtoupper($requestType === 'waiver' ? 'WAV' : 'WOF') . '-' . $billId;
        $stmt = $this->db->prepare("INSERT INTO financial_approval_items
            (entity_type, entity_id, reference_no, title, amount, submitted_by, current_approver_role, status, metadata_json, created_at)
            VALUES (:entity_type, :entity_id, :reference_no, :title, :amount, :submitted_by, 'finance', 'pending', :metadata_json, NOW())
            ON DUPLICATE KEY UPDATE
                reference_no = VALUES(reference_no),
                title = VALUES(title),
                amount = VALUES(amount),
                submitted_by = VALUES(submitted_by),
                metadata_json = VALUES(metadata_json),
                comments = NULL,
                approved_by = NULL,
                approved_at = NULL,
                rejected_at = NULL,
                status = 'pending'");

        return $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $billId,
            ':reference_no' => $referenceNo,
            ':title' => $titlePrefix . ' Request for Bill #' . $billId,
            ':amount' => $requestAmount,
            ':submitted_by' => $submittedBy,
            ':metadata_json' => json_encode($payload),
        ]);
    }

    public function createInstallmentPlanRequest(int $billId, int $submittedBy, float $amount, int $count, string $frequency, string $startDate, string $reason = ''): bool {
        $bill = $this->getBillSnapshot($billId);
        if (!$bill) {
            return false;
        }

        $outstanding = $this->getBillOutstandingAmount($billId);
        $requestedAmount = round(max(0, $amount), 2);
        if ($outstanding <= 0 || $requestedAmount <= 0) {
            return false;
        }

        $requestedAmount = min($requestedAmount, $outstanding);
        $count = max(2, min(36, $count));
        $frequency = in_array($frequency, ['weekly', 'monthly'], true) ? $frequency : 'monthly';
        $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
            return false;
        }

        $payload = [
            'requested_amount' => $requestedAmount,
            'installment_count' => $count,
            'frequency' => $frequency,
            'start_date' => $startDate,
            'reason' => trim($reason),
            'requested_at' => date('c'),
            'requested_by' => $submittedBy,
        ];

        $stmt = $this->db->prepare("INSERT INTO financial_approval_items
            (entity_type, entity_id, reference_no, title, amount, submitted_by, current_approver_role, status, metadata_json, created_at)
            VALUES ('bill_installment', :entity_id, :reference_no, :title, :amount, :submitted_by, 'finance', 'pending', :metadata_json, NOW())
            ON DUPLICATE KEY UPDATE
                reference_no = VALUES(reference_no),
                title = VALUES(title),
                amount = VALUES(amount),
                submitted_by = VALUES(submitted_by),
                metadata_json = VALUES(metadata_json),
                comments = NULL,
                approved_by = NULL,
                approved_at = NULL,
                rejected_at = NULL,
                status = 'pending'");

        return $stmt->execute([
            ':entity_id' => $billId,
            ':reference_no' => 'INS-' . $billId,
            ':title' => 'Installment Plan Request for Bill #' . $billId,
            ':amount' => $requestedAmount,
            ':submitted_by' => $submittedBy,
            ':metadata_json' => json_encode($payload),
        ]);
    }

    public function updateStatus($itemId, $status, $approvedBy = null, $comments = null) {
        $allowed = ['approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $sql = "UPDATE financial_approval_items
            SET status = :status,
                comments = :comments,
                approved_by = :approved_by,
                approved_at = " . ($status === 'approved' ? 'NOW()' : 'approved_at') . ",
                rejected_at = " . ($status === 'rejected' ? 'NOW()' : 'rejected_at') . "
            WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':comments' => $comments,
            ':approved_by' => $approvedBy ? (int)$approvedBy : null,
            ':id' => (int)$itemId,
        ]);
    }

    public function decideItem(int $itemId, string $decision, ?int $approvedBy = null, ?string $comments = null): bool {
        $status = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : '');
        if ($status === '') {
            return false;
        }

        $stmt = $this->db->prepare("SELECT * FROM financial_approval_items WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$item) {
            return false;
        }

        if (($item['status'] ?? '') !== 'pending') {
            return false;
        }

        $this->db->beginTransaction();
        try {
            if ($status === 'approved') {
                $this->applyApprovedItem($item, $approvedBy, $comments);
            }

            $ok = $this->updateStatus($itemId, $status, $approvedBy, $comments);
            if (!$ok) {
                throw new RuntimeException('Failed to update approval status.');
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Finance approval decision failed for item #' . $itemId . ': ' . $e->getMessage());
            return false;
        }
    }

    private function applyApprovedItem(array $item, ?int $approvedBy = null, ?string $comments = null): void {
        $entityType = (string)($item['entity_type'] ?? '');
        if (!in_array($entityType, ['bill_writeoff', 'bill_waiver', 'bill_installment'], true)) {
            return;
        }

        $billId = (int)($item['entity_id'] ?? 0);
        $bill = $this->getBillSnapshot($billId);
        if (!$bill) {
            throw new RuntimeException('Referenced bill not found.');
        }

        $metadata = $this->decodeMetadata($item['metadata_json'] ?? null);

        if ($entityType === 'bill_installment') {
            $this->applyInstallmentPlan($item, $bill, $metadata, $approvedBy);
            return;
        }

        $requestedAmount = (float)($metadata['requested_amount'] ?? $item['amount'] ?? 0);
        $outstanding = $this->getBillOutstandingAmount($billId);
        $amount = round(min(max(0.0, $requestedAmount), $outstanding), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Bill has no outstanding amount to adjust.');
        }

        $newAmount = max(0.0, (float)$bill['amount'] - $amount);
        $isSettled = $newAmount <= 0.0001;
        $newStatus = $isSettled ? 'cancelled' : (string)$bill['status'];

        $stmt = $this->db->prepare("UPDATE bills
            SET amount = :amount,
                status = :status
            WHERE id = :id");
        $stmt->execute([
            ':amount' => round($newAmount, 2),
            ':status' => $newStatus,
            ':id' => $billId,
        ]);

        $notePrefix = $entityType === 'bill_waiver' ? 'Waiver approved' : 'Write-off approved';
        $note = $notePrefix . ' via approval #' . (int)$item['id'];
        if ($comments !== null && trim($comments) !== '') {
            $note .= '. ' . trim($comments);
        }

        $creditService = new CreditNote($this->db);
        $creditService->create(
            $billId,
            (int)$bill['user_id'],
            0,
            $amount,
            $entityType === 'bill_waiver' ? 'waiver' : 'writeoff',
            (int)($approvedBy ?? 0),
            $note
        );

        $accounting = new Accounting($this->db);
        $memo = ($entityType === 'bill_waiver' ? 'Bill waiver approved' : 'Bill write-off approved')
            . ' for bill #' . $billId;
        $accounting->postReceivableReduction(
            $entityType,
            (int)$item['id'],
            $billId,
            (int)$bill['user_id'],
            $amount,
            $memo,
            $approvedBy
        );
    }

    private function applyInstallmentPlan(array $item, array $bill, array $metadata, ?int $approvedBy = null): void {
        $outstanding = $this->getBillOutstandingAmount((int)$bill['id']);
        if ($outstanding <= 0) {
            throw new RuntimeException('Bill is already settled.');
        }

        $amount = (float)($metadata['requested_amount'] ?? $item['amount'] ?? 0);
        $amount = round(min(max(0.0, $amount), $outstanding), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Invalid installment request amount.');
        }

        $count = max(2, min(36, (int)($metadata['installment_count'] ?? 3)));
        $frequency = (string)($metadata['frequency'] ?? 'monthly');
        if (!in_array($frequency, ['weekly', 'monthly'], true)) {
            $frequency = 'monthly';
        }

        $startDate = (string)($metadata['start_date'] ?? date('Y-m-d'));
        $dateObj = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $startDate) {
            $startDate = date('Y-m-d');
        }

        $planner = new InstallmentPlan($this->db);
        $planner->createOrReplacePlan(
            (int)$bill['id'],
            (int)$bill['user_id'],
            $amount,
            $count,
            $frequency,
            $startDate,
            $approvedBy,
            (int)$item['id']
        );
        $planner->allocateExistingCompletedPaymentsForBill((int)$bill['id']);
    }

    private function decodeMetadata($value): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getBillSnapshot(int $billId): ?array {
        if ($billId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $billId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getBillOutstandingAmount(int $billId): float {
        $bill = $this->getBillSnapshot($billId);
        if (!$bill) {
            return 0.0;
        }

        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
        $stmt->execute([':bill_id' => $billId]);
        $paid = (float)$stmt->fetchColumn();

        return max(0.0, round((float)$bill['amount'] - $paid, 2));
    }
}
