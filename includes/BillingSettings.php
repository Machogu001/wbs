<?php
class BillingSettings {
    private $conn;
    private $table = "billing_settings";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
    }

    public function getSettings() {
        $query = "SELECT rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, locale_code, timezone_name, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee, enforce_location_accuracy, updated_at FROM " . $this->table . " WHERE id = 1 LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $this->createDefault();
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Ensure we always have sensible defaults
        if (!isset($settings['company_name']) || $settings['company_name'] === null || $settings['company_name'] === '') {
            $settings['company_name'] = 'BreMac Consultant Ltd';
        }
        if (!isset($settings['support_phone']) || $settings['support_phone'] === null || $settings['support_phone'] === '') {
            $settings['support_phone'] = '254724400202';
        }
        if (!isset($settings['support_email']) || $settings['support_email'] === null || $settings['support_email'] === '') {
            $settings['support_email'] = 'support@waterbilling.com';
        }
        if (!isset($settings['currency_code']) || $settings['currency_code'] === null || $settings['currency_code'] === '') {
            $settings['currency_code'] = 'KES';
        }
        if (!isset($settings['locale_code']) || $settings['locale_code'] === null || $settings['locale_code'] === '') {
            $settings['locale_code'] = 'en-KE';
        }
        if (!isset($settings['timezone_name']) || $settings['timezone_name'] === null || $settings['timezone_name'] === '') {
            $settings['timezone_name'] = 'Africa/Nairobi';
        }
        if (!isset($settings['financial_year_start_month']) || (int)$settings['financial_year_start_month'] < 1 || (int)$settings['financial_year_start_month'] > 12) {
            $settings['financial_year_start_month'] = 1; // January
        }
        // Ensure VAT/taxation defaults
        if (!isset($settings['vat_rate']) || $settings['vat_rate'] === null || $settings['vat_rate'] === '') {
            $settings['vat_rate'] = 0.0;
        }
        $settings['vat_rate'] = (float)$settings['vat_rate'];
        if (!isset($settings['etims_taxation_type_code']) || $settings['etims_taxation_type_code'] === null) {
            $settings['etims_taxation_type_code'] = '';
        } else {
            $settings['etims_taxation_type_code'] = strtoupper(trim((string)$settings['etims_taxation_type_code']));
        }
        // Ensure registration fee default
        if (!isset($settings['registration_fee']) || $settings['registration_fee'] === null || $settings['registration_fee'] === '') {
            $settings['registration_fee'] = 0.00;
        }
        $settings['registration_fee'] = (float)$settings['registration_fee'];
        // Ensure enforce_location_accuracy default
        if (!isset($settings['enforce_location_accuracy'])) {
            $settings['enforce_location_accuracy'] = 0;
        }
        $settings['enforce_location_accuracy'] = (int)$settings['enforce_location_accuracy'];

        return $settings;
    }

    public function updateSettings($rate_per_unit, $service_charge, $company_pin = null, $etims_integration_url = null, $etims_api_key = null, $company_name = null, $support_phone = null, $support_email = null, $currency_code = null, $financial_year_start_month = null, $vat_rate = null, $etims_taxation_type_code = null, $registration_fee = null, $locale_code = null, $timezone_name = null, $enforce_location_accuracy = null) {
        $query = "UPDATE " . $this->table . " 
                  SET rate_per_unit = :rate_per_unit,
                      service_charge = :service_charge,
                      company_pin = :company_pin,
                      etims_integration_url = :etims_integration_url,
                      etims_api_key = :etims_api_key,
                      company_name = :company_name,
                      support_phone = :support_phone,
                      support_email = :support_email,
                      currency_code = :currency_code,
                      locale_code = :locale_code,
                      timezone_name = :timezone_name,
                      financial_year_start_month = :financial_year_start_month,
                      vat_rate = :vat_rate,
                      etims_taxation_type_code = :etims_taxation_type_code,
                      registration_fee = :registration_fee,
                      enforce_location_accuracy = :enforce_location_accuracy,
                      updated_at = NOW()
                  WHERE id = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":rate_per_unit", $rate_per_unit);
        $stmt->bindParam(":service_charge", $service_charge);
        $stmt->bindParam(":company_pin", $company_pin);
        $stmt->bindParam(":etims_integration_url", $etims_integration_url);
        $stmt->bindParam(":etims_api_key", $etims_api_key);
        $company_name = $company_name !== null && $company_name !== '' ? $company_name : 'BreMac Consultant Ltd';
        $stmt->bindParam(":company_name", $company_name);
        $support_phone = $support_phone !== null && $support_phone !== '' ? $support_phone : '254724400202';
        $support_email = $support_email !== null && $support_email !== '' ? $support_email : 'support@waterbilling.com';
        $currency_code = $currency_code !== null && $currency_code !== '' ? strtoupper($currency_code) : 'KES';
        $locale_code = $locale_code !== null && $locale_code !== '' ? trim((string)$locale_code) : 'en-KE';
        $timezone_name = $timezone_name !== null && $timezone_name !== '' ? trim((string)$timezone_name) : 'Africa/Nairobi';
        $financial_year_start_month = (int)($financial_year_start_month ?? 1);
        if ($financial_year_start_month < 1 || $financial_year_start_month > 12) {
            $financial_year_start_month = 1;
        }
        $vat_rate = $vat_rate !== null && $vat_rate !== '' ? (float)$vat_rate : 0.0;
        if ($vat_rate < 0) {
            $vat_rate = 0.0;
        }
        $etims_taxation_type_code = $etims_taxation_type_code !== null ? strtoupper(trim((string)$etims_taxation_type_code)) : '';
        $registration_fee = $registration_fee !== null && $registration_fee !== '' ? (float)$registration_fee : 0.00;
        $stmt->bindParam(":support_phone", $support_phone);
        $stmt->bindParam(":support_email", $support_email);
        $stmt->bindParam(":currency_code", $currency_code);
        $stmt->bindParam(":locale_code", $locale_code);
        $stmt->bindParam(":timezone_name", $timezone_name);
        $stmt->bindParam(":financial_year_start_month", $financial_year_start_month, PDO::PARAM_INT);
        $stmt->bindParam(":vat_rate", $vat_rate);
        $stmt->bindParam(":etims_taxation_type_code", $etims_taxation_type_code);
        $stmt->bindParam(":registration_fee", $registration_fee);
        $enforce_location_accuracy = ($enforce_location_accuracy !== null) ? (int)$enforce_location_accuracy : 0;
        $stmt->bindParam(":enforce_location_accuracy", $enforce_location_accuracy, PDO::PARAM_INT);
        if ($stmt->execute() && $stmt->rowCount() > 0) {
            return true;
        }

        // If no row exists, create default then update
        $this->createDefault();
        return $stmt->execute();
    }

    public function listTariffPlans(bool $activeOnly = false): array {
        $sql = "SELECT * FROM tariff_plans";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY effective_from DESC, id DESC";
        $stmt = $this->conn->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getActiveTariffPlan(?string $billingDate = null, string $connectionType = 'domestic'): ?array {
        $billingDate = $billingDate ?: date('Y-m-d');
        $stmt = $this->conn->prepare("SELECT * FROM tariff_plans
            WHERE is_active = 1
                AND effective_from <= :billing_date
                AND (effective_to IS NULL OR effective_to >= :billing_date)
                AND category IN ('all', :category)
            ORDER BY CASE WHEN category = :category2 THEN 0 ELSE 1 END, effective_from DESC, id DESC
            LIMIT 1");
        $stmt->execute([
            ':billing_date' => $billingDate,
            ':category' => $connectionType,
            ':category2' => $connectionType,
        ]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $blockStmt = $this->conn->prepare("SELECT * FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id ORDER BY from_unit ASC");
        $blockStmt->execute([':tariff_plan_id' => (int)$plan['id']]);
        $plan['blocks'] = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $plan;
    }

    public function getTariffPlanById(int $planId): ?array {
        if ($planId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT * FROM tariff_plans WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $blockStmt = $this->conn->prepare("SELECT * FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id ORDER BY from_unit ASC");
        $blockStmt->execute([':tariff_plan_id' => $planId]);
        $plan['blocks'] = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $plan;
    }

    public function saveTariffPlan(array $data, array $blocks): int {
        $name = trim((string)($data['name'] ?? ''));
        $category = trim((string)($data['category'] ?? 'all'));
        $effectiveFrom = trim((string)($data['effective_from'] ?? ''));
        $effectiveTo = trim((string)($data['effective_to'] ?? ''));
        $baseRate = (float)($data['base_rate_per_unit'] ?? 0);
        $serviceCharge = (float)($data['service_charge'] ?? 0);
        $vatRate = (float)($data['vat_rate'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $planId = (int)($data['id'] ?? 0);

        $allowedCategories = ['domestic', 'commercial', 'industrial', 'all'];
        if ($name === '') {
            throw new InvalidArgumentException('Tariff name is required.');
        }
        if (!in_array($category, $allowedCategories, true)) {
            throw new InvalidArgumentException('Invalid tariff category.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            throw new InvalidArgumentException('Effective from date is required.');
        }
        if ($effectiveTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)) {
            throw new InvalidArgumentException('Effective to date format is invalid.');
        }
        if ($baseRate < 0 || $serviceCharge < 0 || $vatRate < 0) {
            throw new InvalidArgumentException('Rates and charges cannot be negative.');
        }

        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            $from = (float)($block['from_unit'] ?? 0);
            $toRaw = $block['to_unit'] ?? null;
            $to = ($toRaw === null || $toRaw === '') ? null : (float)$toRaw;
            $rate = (float)($block['rate_per_unit'] ?? 0);
            if ($rate < 0) {
                continue;
            }
            if ($to !== null && $to <= $from) {
                continue;
            }
            $normalizedBlocks[] = [
                'from_unit' => $from,
                'to_unit' => $to,
                'rate_per_unit' => $rate,
            ];
        }

        if (empty($normalizedBlocks)) {
            $normalizedBlocks[] = [
                'from_unit' => 0,
                'to_unit' => null,
                'rate_per_unit' => $baseRate,
            ];
        }

        usort($normalizedBlocks, static function (array $a, array $b): int {
            return ($a['from_unit'] <=> $b['from_unit']);
        });

        $this->conn->beginTransaction();
        try {
            if ($planId > 0) {
                $stmt = $this->conn->prepare("UPDATE tariff_plans
                    SET name = :name,
                        category = :category,
                        effective_from = :effective_from,
                        effective_to = :effective_to,
                        base_rate_per_unit = :base_rate_per_unit,
                        service_charge = :service_charge,
                        vat_rate = :vat_rate,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':effective_from' => $effectiveFrom,
                    ':effective_to' => $effectiveTo !== '' ? $effectiveTo : null,
                    ':base_rate_per_unit' => $baseRate,
                    ':service_charge' => $serviceCharge,
                    ':vat_rate' => $vatRate,
                    ':is_active' => $isActive,
                    ':id' => $planId,
                ]);

                $del = $this->conn->prepare("DELETE FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id");
                $del->execute([':tariff_plan_id' => $planId]);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO tariff_plans
                    (name, category, effective_from, effective_to, base_rate_per_unit, service_charge, vat_rate, is_active)
                    VALUES (:name, :category, :effective_from, :effective_to, :base_rate_per_unit, :service_charge, :vat_rate, :is_active)");
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':effective_from' => $effectiveFrom,
                    ':effective_to' => $effectiveTo !== '' ? $effectiveTo : null,
                    ':base_rate_per_unit' => $baseRate,
                    ':service_charge' => $serviceCharge,
                    ':vat_rate' => $vatRate,
                    ':is_active' => $isActive,
                ]);
                $planId = (int)$this->conn->lastInsertId();
            }

            $stmtBlock = $this->conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit)
                VALUES (:tariff_plan_id, :from_unit, :to_unit, :rate_per_unit)");
            foreach ($normalizedBlocks as $block) {
                $stmtBlock->execute([
                    ':tariff_plan_id' => $planId,
                    ':from_unit' => (float)$block['from_unit'],
                    ':to_unit' => $block['to_unit'] !== null ? (float)$block['to_unit'] : null,
                    ':rate_per_unit' => (float)$block['rate_per_unit'],
                ]);
            }

            $this->conn->commit();
            return $planId;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function setTariffPlanStatus(int $planId, int $isActive): bool {
        if ($planId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE tariff_plans SET is_active = :is_active, updated_at = NOW() WHERE id = :id");
        return $stmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $planId,
        ]);
    }

    public function deleteTariffPlan(int $planId): bool {
        if ($planId <= 0) {
            return false;
        }

        $countStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans");
        $totalPlans = (int)$countStmt->fetchColumn();
        if ($totalPlans <= 1) {
            throw new RuntimeException('At least one tariff plan must remain in the system.');
        }

        $planStmt = $this->conn->prepare("SELECT id, is_active FROM tariff_plans WHERE id = :id LIMIT 1");
        $planStmt->execute([':id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return false;
        }

        $this->conn->beginTransaction();
        try {
            $delStmt = $this->conn->prepare("DELETE FROM tariff_plans WHERE id = :id");
            $deleted = $delStmt->execute([':id' => $planId]);

            if ($deleted && !empty($plan['is_active'])) {
                $activeCountStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans WHERE is_active = 1");
                $activeCount = (int)$activeCountStmt->fetchColumn();
                if ($activeCount === 0) {
                    $fallbackStmt = $this->conn->query("SELECT id FROM tariff_plans ORDER BY effective_from DESC, id DESC LIMIT 1");
                    $fallbackId = (int)$fallbackStmt->fetchColumn();
                    if ($fallbackId > 0) {
                        $activateStmt = $this->conn->prepare("UPDATE tariff_plans SET is_active = 1, updated_at = NOW() WHERE id = :id");
                        $activateStmt->execute([':id' => $fallbackId]);
                    }
                }
            }

            $this->conn->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    private function createDefault() {
          $default_rate = 50.00;
          $default_service = 0.00;
          $default_registration_fee = 0.00;
          $default_company_name = 'BreMac Consultant Ltd';
          $default_support_phone = '254724400202';
          $default_support_email = 'support@waterbilling.com';
          $default_currency = 'KES';
          $default_locale = 'en-KE';
          $default_timezone = 'Africa/Nairobi';
          $default_fy_start = 1;
          $default_vat_rate = 0.0;
          $default_tax_code = '';
          $query = "INSERT INTO " . $this->table . " (id, rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, locale_code, timezone_name, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee, enforce_location_accuracy) 
              VALUES (1, :rate_per_unit, :service_charge, NULL, NULL, NULL, :company_name, :support_phone, :support_email, :currency_code, :locale_code, :timezone_name, :financial_year_start_month, :vat_rate, :etims_taxation_type_code, :registration_fee, 0)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":rate_per_unit", $default_rate);
        $stmt->bindParam(":service_charge", $default_service);
        $stmt->bindParam(":company_name", $default_company_name);
        $stmt->bindParam(":support_phone", $default_support_phone);
        $stmt->bindParam(":support_email", $default_support_email);
        $stmt->bindParam(":currency_code", $default_currency);
        $stmt->bindParam(":locale_code", $default_locale);
        $stmt->bindParam(":timezone_name", $default_timezone);
        $stmt->bindParam(":financial_year_start_month", $default_fy_start, PDO::PARAM_INT);
        $stmt->bindParam(":vat_rate", $default_vat_rate);
        $stmt->bindParam(":etims_taxation_type_code", $default_tax_code);
        $stmt->bindParam(":registration_fee", $default_registration_fee);
        $stmt->execute();
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
            id INT PRIMARY KEY AUTO_INCREMENT,
            rate_per_unit DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            company_pin VARCHAR(60) NULL,
            etims_integration_url VARCHAR(255) NULL,
            etims_api_key VARCHAR(255) NULL,
            company_name VARCHAR(255) DEFAULT 'BreMac Consultant Ltd',
            support_phone VARCHAR(50) DEFAULT '254724400202',
            support_email VARCHAR(255) DEFAULT 'support@waterbilling.com',
            currency_code VARCHAR(10) DEFAULT 'KES',
			locale_code VARCHAR(20) DEFAULT 'en-KE',
			timezone_name VARCHAR(100) DEFAULT 'Africa/Nairobi',
			financial_year_start_month TINYINT UNSIGNED DEFAULT 1,
			vat_rate DECIMAL(5,2) DEFAULT 0.00,
            etims_taxation_type_code VARCHAR(10) NULL,
            registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            enforce_location_accuracy TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->conn->exec($sql);

        // In case the table already existed without the new columns, attempt to add them.
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_pin VARCHAR(60) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_integration_url VARCHAR(255) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_api_key VARCHAR(255) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_name VARCHAR(255) DEFAULT 'BreMac Consultant Ltd'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN support_phone VARCHAR(50) DEFAULT '254724400202'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN support_email VARCHAR(255) DEFAULT 'support@waterbilling.com'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN currency_code VARCHAR(10) DEFAULT 'KES'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN locale_code VARCHAR(20) DEFAULT 'en-KE'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN timezone_name VARCHAR(100) DEFAULT 'Africa/Nairobi'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN financial_year_start_month TINYINT UNSIGNED DEFAULT 1");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN vat_rate DECIMAL(5,2) DEFAULT 0.00");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_taxation_type_code VARCHAR(10) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN enforce_location_accuracy TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        $this->ensureTariffTables();
    }

    private function ensureTariffTables(): void {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('domestic', 'commercial', 'industrial', 'all') NOT NULL DEFAULT 'all',
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            base_rate_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tariff_dates (effective_from, effective_to),
            INDEX idx_tariff_category_active (category, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tariff_plan_id INT NOT NULL,
            from_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            to_unit DECIMAL(10,2) NULL,
            rate_per_unit DECIMAL(10,4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plan_from_unit (tariff_plan_id, from_unit),
            CONSTRAINT fk_tariff_blocks_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $countStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans");
        $count = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        if ($count === 0) {
            $settings = $this->getSettings();
            $stmt = $this->conn->prepare("INSERT INTO tariff_plans
                (name, category, effective_from, base_rate_per_unit, service_charge, vat_rate, is_active)
                VALUES (:name, 'all', :effective_from, :base_rate_per_unit, :service_charge, :vat_rate, 1)");
            $stmt->execute([
                ':name' => 'Default Standard Tariff',
                ':effective_from' => date('Y-m-01'),
                ':base_rate_per_unit' => (float)($settings['rate_per_unit'] ?? 50.0),
                ':service_charge' => (float)($settings['service_charge'] ?? 0.0),
                ':vat_rate' => (float)($settings['vat_rate'] ?? 0.0),
            ]);

            $planId = (int)$this->conn->lastInsertId();
            if ($planId > 0) {
                $blockStmt = $this->conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit)
                    VALUES (:tariff_plan_id, 0.00, NULL, :rate_per_unit)");
                $blockStmt->execute([
                    ':tariff_plan_id' => $planId,
                    ':rate_per_unit' => (float)($settings['rate_per_unit'] ?? 50.0),
                ]);
            }
        }
    }
}
?>
