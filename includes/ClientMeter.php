<?php

class ClientMeter
{
    private $conn;
    private $table = 'user_meters';
    private $replacementTable = 'meter_replacements';

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTable();
        $this->ensureReplacementTable();
        $this->seedPrimaryMeters();
    }

    public function listByUserId(int $userId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, meter_number, meter_label, status, is_primary, registration_bill_id, created_by_user_id, created_at
             FROM ' . $this->table . '
             WHERE user_id = :user_id
             ORDER BY is_primary DESC, created_at ASC, id ASC'
        );
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listReplacementsByUserId(int $userId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, old_meter_id, new_meter_id, old_meter_number, new_meter_number,
                    old_final_reading, new_opening_reading, reason, replaced_by_user_id, replaced_at
             FROM ' . $this->replacementTable . '
             WHERE user_id = :user_id
             ORDER BY replaced_at DESC, id DESC'
        );
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function meterExists(string $meterNumber): bool
    {
        $meterNumber = $this->normalizeMeterNumber($meterNumber);
        if ($meterNumber === '') {
            return false;
        }

        $stmt = $this->conn->prepare('SELECT id FROM ' . $this->table . ' WHERE meter_number = :meter_number LIMIT 1');
        $stmt->bindParam(':meter_number', $meterNumber);
        $stmt->execute();
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function meterExistsForAnotherUser(string $meterNumber, int $excludeUserId = 0): bool
    {
        $meterNumber = $this->normalizeMeterNumber($meterNumber);
        if ($meterNumber === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'SELECT id FROM ' . $this->table . ' WHERE meter_number = :meter_number AND user_id <> :exclude_user_id LIMIT 1'
        );
        $stmt->bindParam(':meter_number', $meterNumber);
        $stmt->bindParam(':exclude_user_id', $excludeUserId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function addMeter(int $userId, string $meterNumber, ?string $meterLabel = null, ?int $registrationBillId = null, ?int $createdByUserId = null): int
    {
        $meterNumber = $this->normalizeMeterNumber($meterNumber);
        if ($userId <= 0 || $meterNumber === '') {
            throw new InvalidArgumentException('A valid user and meter number are required.');
        }

        $stmt = $this->conn->prepare(
              'INSERT INTO ' . $this->table . ' (user_id, meter_number, meter_label, status, is_primary, registration_bill_id, created_by_user_id)
               VALUES (:user_id, :meter_number, :meter_label, :status, 0, :registration_bill_id, :created_by_user_id)'
        );
        $status = 'active';
        $label = $meterLabel !== null && trim($meterLabel) !== '' ? trim($meterLabel) : null;
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':meter_number', $meterNumber);
        $stmt->bindParam(':meter_label', $label);
        $stmt->bindParam(':status', $status);
        $stmt->bindValue(':registration_bill_id', $registrationBillId, $registrationBillId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':created_by_user_id', $createdByUserId, $createdByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int)$this->conn->lastInsertId();
    }

    public function replaceMeter(int $userId, int $oldMeterId, string $newMeterNumber, ?string $newMeterLabel = null, float $oldFinalReading = 0.0, float $newOpeningReading = 0.0, ?string $reason = null, ?int $replacedByUserId = null): array
    {
        $newMeterNumber = $this->normalizeMeterNumber($newMeterNumber);
        $newLabel = $newMeterLabel !== null && trim($newMeterLabel) !== '' ? trim($newMeterLabel) : null;
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $oldFinalReading = round(max(0, $oldFinalReading), 2);
        $newOpeningReading = round(max(0, $newOpeningReading), 2);

        if ($userId <= 0 || $oldMeterId <= 0 || $newMeterNumber === '') {
            throw new InvalidArgumentException('A valid customer, old meter, and new meter number are required.');
        }

        $oldMeter = $this->getMeterById($oldMeterId, $userId);
        if (!$oldMeter) {
            throw new RuntimeException('Selected meter was not found for this customer.');
        }
        if (($oldMeter['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Only active meters can be replaced.');
        }
        if ($this->normalizeMeterNumber((string)$oldMeter['meter_number']) === $newMeterNumber) {
            throw new RuntimeException('The new meter number must be different from the faulty meter.');
        }
        if ($this->meterExists($newMeterNumber)) {
            throw new RuntimeException('That new meter number is already assigned to another account or meter record.');
        }

        $startedTransaction = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $isPrimary = !empty($oldMeter['is_primary']);

            $deactivate = $this->conn->prepare(
                'UPDATE ' . $this->table . ' SET status = :status, is_primary = 0, updated_at = NOW() WHERE id = :id AND user_id = :user_id'
            );
            $inactive = 'inactive';
            $deactivate->bindParam(':status', $inactive);
            $deactivate->bindParam(':id', $oldMeterId, PDO::PARAM_INT);
            $deactivate->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $deactivate->execute();

            $insert = $this->conn->prepare(
                'INSERT INTO ' . $this->table . ' (user_id, meter_number, meter_label, status, is_primary, registration_bill_id, created_by_user_id)
                 VALUES (:user_id, :meter_number, :meter_label, :status, :is_primary, NULL, :created_by_user_id)'
            );
            $active = 'active';
            $primaryFlag = $isPrimary ? 1 : 0;
            $insert->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $insert->bindParam(':meter_number', $newMeterNumber);
            $insert->bindParam(':meter_label', $newLabel);
            $insert->bindParam(':status', $active);
            $insert->bindParam(':is_primary', $primaryFlag, PDO::PARAM_INT);
            $insert->bindValue(':created_by_user_id', $replacedByUserId, $replacedByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->execute();
            $newMeterId = (int)$this->conn->lastInsertId();

            if ($isPrimary) {
                $updateUser = $this->conn->prepare('UPDATE users SET meter_number = :meter_number WHERE id = :user_id');
                $updateUser->bindParam(':meter_number', $newMeterNumber);
                $updateUser->bindParam(':user_id', $userId, PDO::PARAM_INT);
                $updateUser->execute();
            }

            $logReplacement = $this->conn->prepare(
                'INSERT INTO ' . $this->replacementTable . ' (
                    user_id, old_meter_id, new_meter_id, old_meter_number, new_meter_number,
                    old_final_reading, new_opening_reading, reason, replaced_by_user_id
                 ) VALUES (
                    :user_id, :old_meter_id, :new_meter_id, :old_meter_number, :new_meter_number,
                    :old_final_reading, :new_opening_reading, :reason, :replaced_by_user_id
                 )'
            );
            $oldMeterNumber = (string)$oldMeter['meter_number'];
            $logReplacement->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $logReplacement->bindParam(':old_meter_id', $oldMeterId, PDO::PARAM_INT);
            $logReplacement->bindParam(':new_meter_id', $newMeterId, PDO::PARAM_INT);
            $logReplacement->bindParam(':old_meter_number', $oldMeterNumber);
            $logReplacement->bindParam(':new_meter_number', $newMeterNumber);
            $logReplacement->bindParam(':old_final_reading', $oldFinalReading);
            $logReplacement->bindParam(':new_opening_reading', $newOpeningReading);
            $logReplacement->bindParam(':reason', $reason);
            $logReplacement->bindValue(':replaced_by_user_id', $replacedByUserId, $replacedByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $logReplacement->execute();

            if ($startedTransaction) {
                $this->conn->commit();
            }

            return [
                'old_meter' => $oldMeter,
                'new_meter_id' => $newMeterId,
                'new_meter_number' => $newMeterNumber,
                'new_opening_reading' => $newOpeningReading,
                'old_final_reading' => $oldFinalReading,
                'is_primary' => $isPrimary,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function getReplacementOpeningReading(int $userId, string $meterNumber): ?float
    {
        $meterNumber = $this->normalizeMeterNumber($meterNumber);
        if ($userId <= 0 || $meterNumber === '') {
            return null;
        }

        $stmt = $this->conn->prepare(
            'SELECT new_opening_reading
             FROM ' . $this->replacementTable . '
             WHERE user_id = :user_id AND new_meter_number = :meter_number
             ORDER BY replaced_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':meter_number', $meterNumber);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $row !== null ? (float)$row['new_opening_reading'] : null;
    }

    public function syncPrimaryMeter(int $userId, ?string $meterNumber): void
    {
        $meterNumber = $this->normalizeMeterNumber((string)$meterNumber);
        if ($userId <= 0 || $meterNumber === '') {
            return;
        }

        $stmt = $this->conn->prepare('SELECT id FROM ' . $this->table . ' WHERE user_id = :user_id AND is_primary = 1 LIMIT 1');
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existing) {
            $update = $this->conn->prepare(
                'UPDATE ' . $this->table . ' SET meter_number = :meter_number, status = :status, updated_at = NOW() WHERE id = :id'
            );
            $status = 'active';
            $update->bindParam(':meter_number', $meterNumber);
            $update->bindParam(':status', $status);
            $update->bindParam(':id', $existing['id'], PDO::PARAM_INT);
            $update->execute();
            return;
        }

        $insert = $this->conn->prepare(
            'INSERT INTO ' . $this->table . ' (user_id, meter_number, meter_label, status, is_primary, registration_bill_id)
             VALUES (:user_id, :meter_number, NULL, :status, 1, NULL)'
        );
        $status = 'active';
        $insert->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $insert->bindParam(':meter_number', $meterNumber);
        $insert->bindParam(':status', $status);
        $insert->execute();
    }

    public function findUserByMeter(string $meterNumber): ?array
    {
        $meterNumber = $this->normalizeMeterNumber($meterNumber);
        if ($meterNumber === '') {
            return null;
        }

        $query = 'SELECT u.*, um.id AS matched_meter_id, um.meter_number AS matched_meter_number,
                um.meter_label AS matched_meter_label, um.is_primary AS matched_meter_is_primary,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT um2.meter_number ORDER BY um2.is_primary DESC, um2.created_at ASC, um2.id ASC SEPARATOR ",")
                    FROM ' . $this->table . ' um2
                    WHERE um2.user_id = u.id AND um2.status = "active"), u.meter_number) AS meter_numbers,
                COALESCE((SELECT GROUP_CONCAT(CONCAT(um3.meter_number, "::", COALESCE(um3.meter_label, "")) ORDER BY um3.is_primary DESC, um3.created_at ASC, um3.id ASC SEPARATOR "||")
                    FROM ' . $this->table . ' um3
                    WHERE um3.user_id = u.id AND um3.status = "active"), CONCAT(COALESCE(u.meter_number, ""), "::")) AS meter_details
            FROM users u
            INNER JOIN ' . $this->table . ' um ON um.user_id = u.id
            WHERE um.meter_number = :meter_number AND um.status = "active"
            LIMIT 1';

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':meter_number', $meterNumber);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $row['primary_meter_number'] = $row['meter_number'] ?? null;
        $row['meter_number'] = $row['matched_meter_number'] ?? $row['meter_number'];
        return $row;
    }

    private function ensureTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->table . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            meter_number VARCHAR(50) NOT NULL,
            meter_label VARCHAR(191) NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            registration_bill_id INT NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_meter_number (meter_number),
            KEY idx_user_id (user_id),
            KEY idx_registration_bill_id (registration_bill_id),
            KEY idx_created_by_user_id (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->conn->exec($sql);
        $this->ensureColumn('meter_label', 'ALTER TABLE ' . $this->table . ' ADD COLUMN meter_label VARCHAR(191) NULL AFTER meter_number');
        $this->ensureColumn('status', 'ALTER TABLE ' . $this->table . ' ADD COLUMN status ENUM("active", "inactive") NOT NULL DEFAULT "active" AFTER meter_label');
        $this->ensureColumn('is_primary', 'ALTER TABLE ' . $this->table . ' ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
        $this->ensureColumn('registration_bill_id', 'ALTER TABLE ' . $this->table . ' ADD COLUMN registration_bill_id INT NULL AFTER is_primary');
        $this->ensureColumn('created_by_user_id', 'ALTER TABLE ' . $this->table . ' ADD COLUMN created_by_user_id INT NULL AFTER registration_bill_id');
        $this->ensureColumn('updated_at', 'ALTER TABLE ' . $this->table . ' ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }

    private function ensureReplacementTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->replacementTable . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            old_meter_id INT NOT NULL,
            new_meter_id INT NOT NULL,
            old_meter_number VARCHAR(50) NOT NULL,
            new_meter_number VARCHAR(50) NOT NULL,
            old_final_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_opening_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason TEXT NULL,
            replaced_by_user_id INT NULL,
            replaced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_id (user_id),
            KEY idx_old_meter_id (old_meter_id),
            KEY idx_new_meter_id (new_meter_id),
            KEY idx_new_meter_number (new_meter_number),
            KEY idx_replaced_by_user_id (replaced_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->conn->exec($sql);
        $this->ensureReplacementColumn('new_opening_reading', 'ALTER TABLE ' . $this->replacementTable . ' ADD COLUMN new_opening_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER old_final_reading');
        $this->ensureReplacementColumn('reason', 'ALTER TABLE ' . $this->replacementTable . ' ADD COLUMN reason TEXT NULL AFTER new_opening_reading');
        $this->ensureReplacementColumn('replaced_by_user_id', 'ALTER TABLE ' . $this->replacementTable . ' ADD COLUMN replaced_by_user_id INT NULL AFTER reason');
        $this->ensureReplacementColumn('replaced_at', 'ALTER TABLE ' . $this->replacementTable . ' ADD COLUMN replaced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER replaced_by_user_id');
    }

    private function ensureColumn(string $columnName, string $ddl): void
    {
        $stmt = $this->conn->query('SHOW COLUMNS FROM ' . $this->table . ' LIKE ' . $this->conn->quote($columnName));
        $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$exists) {
            $this->conn->exec($ddl);
        }
    }

    private function ensureReplacementColumn(string $columnName, string $ddl): void
    {
        $stmt = $this->conn->query('SHOW COLUMNS FROM ' . $this->replacementTable . ' LIKE ' . $this->conn->quote($columnName));
        $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$exists) {
            $this->conn->exec($ddl);
        }
    }

    private function getMeterById(int $meterId, int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, meter_number, meter_label, status, is_primary, registration_bill_id, created_by_user_id, created_at
             FROM ' . $this->table . '
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->bindParam(':id', $meterId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function seedPrimaryMeters(): void
    {
        $sql = 'INSERT INTO ' . $this->table . ' (user_id, meter_number, meter_label, status, is_primary, registration_bill_id)
            SELECT u.id, u.meter_number, NULL, "active", 1, NULL
            FROM users u
            LEFT JOIN ' . $this->table . ' um ON um.user_id = u.id AND um.is_primary = 1
            WHERE u.meter_number IS NOT NULL AND u.meter_number <> "" AND um.id IS NULL';
        $this->conn->exec($sql);
    }

    private function normalizeMeterNumber(string $meterNumber): string
    {
        $meterNumber = strtoupper(trim($meterNumber));
        return preg_replace('/\s+/', '', $meterNumber);
    }
}