<?php

class InstallmentPlan {
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureTables();
    }

    public function ensureTables(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS installment_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bill_id INT NOT NULL,
            user_id INT NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            installment_count INT NOT NULL,
            frequency ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
            start_date DATE NOT NULL,
            status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            approved_item_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_bill_active (bill_id, status),
            INDEX idx_user_status (user_id, status),
            INDEX idx_bill (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS installment_plan_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            sequence_no INT NOT NULL,
            due_date DATE NOT NULL,
            due_amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_sequence (plan_id, sequence_no),
            INDEX idx_plan_due (plan_id, due_date),
            CONSTRAINT fk_installment_items_plan FOREIGN KEY (plan_id) REFERENCES installment_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function createOrReplacePlan(int $billId, int $userId, float $totalAmount, int $installmentCount, string $frequency, string $startDate, ?int $createdBy = null, ?int $approvedItemId = null): int {
        $installmentCount = max(1, min(36, $installmentCount));
        $frequency = in_array($frequency, ['weekly', 'monthly'], true) ? $frequency : 'monthly';
        $totalAmount = round(max(0, $totalAmount), 2);
        if ($totalAmount <= 0) {
            throw new InvalidArgumentException('Installment amount must be greater than zero.');
        }

        $this->db->beginTransaction();
        try {
            $stmtClose = $this->db->prepare("UPDATE installment_plans SET status = 'cancelled', updated_at = NOW() WHERE bill_id = :bill_id AND status = 'active'");
            $stmtClose->execute([':bill_id' => $billId]);

            $stmtInsert = $this->db->prepare("INSERT INTO installment_plans
                (bill_id, user_id, total_amount, installment_count, frequency, start_date, status, created_by, approved_item_id, created_at)
                VALUES (:bill_id, :user_id, :total_amount, :installment_count, :frequency, :start_date, 'active', :created_by, :approved_item_id, NOW())");
            $stmtInsert->execute([
                ':bill_id' => $billId,
                ':user_id' => $userId,
                ':total_amount' => $totalAmount,
                ':installment_count' => $installmentCount,
                ':frequency' => $frequency,
                ':start_date' => $startDate,
                ':created_by' => $createdBy,
                ':approved_item_id' => $approvedItemId,
            ]);

            $planId = (int)$this->db->lastInsertId();
            $items = $this->buildSchedule($totalAmount, $installmentCount, $frequency, $startDate);

            $stmtItem = $this->db->prepare("INSERT INTO installment_plan_items (plan_id, sequence_no, due_date, due_amount)
                VALUES (:plan_id, :sequence_no, :due_date, :due_amount)");
            foreach ($items as $row) {
                $stmtItem->execute([
                    ':plan_id' => $planId,
                    ':sequence_no' => $row['sequence_no'],
                    ':due_date' => $row['due_date'],
                    ':due_amount' => $row['due_amount'],
                ]);
            }

            $this->db->commit();
            return $planId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getActivePlanByBillId(int $billId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM installment_plans WHERE bill_id = :bill_id AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':bill_id' => $billId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $plan['items'] = $this->getPlanItemsWithAllocation((int)$plan['id']);
        return $plan;
    }

    public function getPlanItemsWithAllocation(int $planId): array {
        $stmtPlan = $this->db->prepare("SELECT * FROM installment_plans WHERE id = :id LIMIT 1");
        $stmtPlan->execute([':id' => $planId]);
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return [];
        }

        $stmtItems = $this->db->prepare("SELECT * FROM installment_plan_items WHERE plan_id = :plan_id ORDER BY sequence_no ASC");
        $stmtItems->execute([':plan_id' => $planId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtPaid = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
        $stmtPaid->execute([':bill_id' => (int)$plan['bill_id']]);
        $remainingPaymentPool = (float)$stmtPaid->fetchColumn();

        $today = date('Y-m-d');
        foreach ($items as &$item) {
            $due = (float)$item['due_amount'];
            $allocated = min($due, max(0.0, $remainingPaymentPool));
            $remainingPaymentPool = max(0.0, $remainingPaymentPool - $allocated);
            $balance = round($due - $allocated, 2);
            $item['allocated_amount'] = round($allocated, 2);
            $item['balance_amount'] = $balance;
            if ($balance <= 0) {
                $item['item_status'] = 'paid';
            } elseif ((string)$item['due_date'] < $today) {
                $item['item_status'] = 'overdue';
            } else {
                $item['item_status'] = 'pending';
            }
        }
        unset($item);

        $allPaid = !empty($items);
        foreach ($items as $row) {
            if (($row['item_status'] ?? '') !== 'paid') {
                $allPaid = false;
                break;
            }
        }

        if ($allPaid && (string)$plan['status'] === 'active') {
            $stmt = $this->db->prepare("UPDATE installment_plans SET status = 'completed', updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $planId]);
        }

        return $items;
    }

    private function buildSchedule(float $totalAmount, int $count, string $frequency, string $startDate): array {
        $rows = [];
        $base = floor(($totalAmount / $count) * 100) / 100;
        $running = 0.0;
        $date = new DateTime($startDate);

        for ($i = 1; $i <= $count; $i++) {
            $amount = ($i === $count) ? round($totalAmount - $running, 2) : $base;
            $running += $amount;

            if ($i > 1) {
                if ($frequency === 'weekly') {
                    $date->modify('+7 days');
                } else {
                    $date->modify('+1 month');
                }
            }

            $rows[] = [
                'sequence_no' => $i,
                'due_date' => $date->format('Y-m-d'),
                'due_amount' => round($amount, 2),
            ];
        }

        return $rows;
    }
}
