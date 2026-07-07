<?php

class InstallmentPlan {
    private $db;
    private static bool $schemaEnsured = false;

    public function __construct($db) {
        $this->db = $db;
        if (!self::$schemaEnsured && !$this->db->inTransaction()) {
            $this->ensureTables();
            self::$schemaEnsured = true;
        }
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

        $this->db->exec("CREATE TABLE IF NOT EXISTS installment_payment_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            plan_item_id INT NOT NULL,
            payment_id INT NOT NULL,
            allocated_amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payment_item (payment_id, plan_item_id),
            INDEX idx_plan_payment (plan_id, payment_id),
            INDEX idx_plan_item (plan_item_id),
            CONSTRAINT fk_allocations_plan FOREIGN KEY (plan_id) REFERENCES installment_plans(id) ON DELETE CASCADE,
            CONSTRAINT fk_allocations_plan_item FOREIGN KEY (plan_item_id) REFERENCES installment_plan_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_allocations_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
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

    public function getLatestPlanByBillId(int $billId, array $statuses = ['active', 'completed']): ?array {
        if ($billId <= 0) {
            return null;
        }

        $allowed = ['active', 'completed', 'cancelled'];
        $statuses = array_values(array_filter($statuses, static function ($status) use ($allowed) {
            return in_array((string)$status, $allowed, true);
        }));
        if (empty($statuses)) {
            $statuses = ['active', 'completed'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge([$billId], $statuses);
        $stmt = $this->db->prepare("SELECT * FROM installment_plans WHERE bill_id = ? AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $plan['items'] = $this->getPlanItemsWithAllocation((int)$plan['id']);
        return $plan;
    }

    public function getAllocationLedgerByPlanId(int $planId): array {
        if ($planId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("SELECT a.*, i.sequence_no, i.due_date, i.due_amount,
                p.amount AS payment_amount,
                p.status AS payment_status,
                p.mpesa_receipt,
                COALESCE(p.transaction_date, p.created_at) AS payment_date
            FROM installment_payment_allocations a
            INNER JOIN installment_plan_items i ON i.id = a.plan_item_id
            INNER JOIN payments p ON p.id = a.payment_id
            WHERE a.plan_id = :plan_id
            ORDER BY COALESCE(p.transaction_date, p.created_at) ASC, p.id ASC, i.sequence_no ASC, a.id ASC");
        $stmt->execute([':plan_id' => $planId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPlanItemsWithAllocation(int $planId): array {
        $stmtPlan = $this->db->prepare("SELECT * FROM installment_plans WHERE id = :id LIMIT 1");
        $stmtPlan->execute([':id' => $planId]);
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return [];
        }

        $stmtItems = $this->db->prepare("SELECT i.*, COALESCE(a.allocated_total, 0) AS allocated_total
            FROM installment_plan_items i
            LEFT JOIN (
                SELECT plan_item_id, SUM(allocated_amount) AS allocated_total
                FROM installment_payment_allocations
                WHERE plan_id = :plan_id_alloc
                GROUP BY plan_item_id
            ) a ON a.plan_item_id = i.id
            WHERE i.plan_id = :plan_id
            ORDER BY i.sequence_no ASC");
        $stmtItems->execute([
            ':plan_id_alloc' => $planId,
            ':plan_id' => $planId,
        ]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $today = date('Y-m-d');
        foreach ($items as &$item) {
            $due = (float)$item['due_amount'];
            $allocated = min($due, max(0.0, (float)($item['allocated_total'] ?? 0)));
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

    public function allocatePayment(int $paymentId): void {
        if ($paymentId <= 0) {
            return;
        }

        $stmtPayment = $this->db->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
        $stmtPayment->execute([':id' => $paymentId]);
        $payment = $stmtPayment->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$payment || (string)($payment['status'] ?? '') !== 'completed') {
            return;
        }

        $billId = (int)($payment['bill_id'] ?? 0);
        if ($billId <= 0) {
            return;
        }

        $plan = $this->getActivePlanByBillId($billId);
        if (!$plan) {
            return;
        }

        // Keep allocation idempotent if payment completion callback retries.
        $stmtExisting = $this->db->prepare("SELECT COUNT(*) FROM installment_payment_allocations WHERE payment_id = :payment_id");
        $stmtExisting->execute([':payment_id' => $paymentId]);
        if ((int)$stmtExisting->fetchColumn() > 0) {
            return;
        }

        $remaining = round(max(0.0, (float)$payment['amount']), 2);
        if ($remaining <= 0) {
            return;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $planItems = $this->getPlanItemsOutstanding((int)$plan['id']);
            $stmtInsert = $this->db->prepare("INSERT INTO installment_payment_allocations
                (plan_id, plan_item_id, payment_id, allocated_amount)
                VALUES (:plan_id, :plan_item_id, :payment_id, :allocated_amount)");

            foreach ($planItems as $item) {
                if ($remaining <= 0) {
                    break;
                }

                $itemBalance = (float)($item['balance_amount'] ?? 0);
                if ($itemBalance <= 0) {
                    continue;
                }

                $allocated = round(min($remaining, $itemBalance), 2);
                if ($allocated <= 0) {
                    continue;
                }

                $stmtInsert->execute([
                    ':plan_id' => (int)$plan['id'],
                    ':plan_item_id' => (int)$item['id'],
                    ':payment_id' => $paymentId,
                    ':allocated_amount' => $allocated,
                ]);
                $remaining = round($remaining - $allocated, 2);
            }

            $this->syncPlanCompletionStatus((int)$plan['id']);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function allocateExistingCompletedPaymentsForBill(int $billId): void {
        if ($billId <= 0) {
            return;
        }

        $stmt = $this->db->prepare("SELECT id FROM payments WHERE bill_id = :bill_id AND status = 'completed' ORDER BY COALESCE(transaction_date, created_at) ASC, id ASC");
        $stmt->execute([':bill_id' => $billId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($payments as $payment) {
            $this->allocatePayment((int)$payment['id']);
        }
    }

    private function getPlanItemsOutstanding(int $planId): array {
        $stmt = $this->db->prepare("SELECT i.*,
            COALESCE(a.allocated_total, 0) AS allocated_total,
            GREATEST(0, i.due_amount - COALESCE(a.allocated_total, 0)) AS balance_amount
            FROM installment_plan_items i
            LEFT JOIN (
                SELECT plan_item_id, SUM(allocated_amount) AS allocated_total
                FROM installment_payment_allocations
                WHERE plan_id = :plan_id_alloc
                GROUP BY plan_item_id
            ) a ON a.plan_item_id = i.id
            WHERE i.plan_id = :plan_id
            ORDER BY i.sequence_no ASC, i.id ASC");
        $stmt->execute([
            ':plan_id_alloc' => $planId,
            ':plan_id' => $planId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function syncPlanCompletionStatus(int $planId): void {
        $items = $this->getPlanItemsWithAllocation($planId);
        $allPaid = !empty($items);
        foreach ($items as $item) {
            if (((string)($item['item_status'] ?? '')) !== 'paid') {
                $allPaid = false;
                break;
            }
        }

        if ($allPaid) {
            $stmt = $this->db->prepare("UPDATE installment_plans SET status = 'completed', updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $planId]);
        }
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
