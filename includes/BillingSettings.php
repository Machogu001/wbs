<?php
class BillingSettings {
    private $conn;
    private $table = "billing_settings";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
    }

    public function getSettings() {
        $query = "SELECT rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee, updated_at FROM " . $this->table . " WHERE id = 1 LIMIT 1";
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
            $settings['support_phone'] = '+254 700 000 000';
        }
        if (!isset($settings['support_email']) || $settings['support_email'] === null || $settings['support_email'] === '') {
            $settings['support_email'] = 'support@waterbilling.com';
        }
        if (!isset($settings['currency_code']) || $settings['currency_code'] === null || $settings['currency_code'] === '') {
            $settings['currency_code'] = 'KES';
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

        return $settings;
    }

    public function updateSettings($rate_per_unit, $service_charge, $company_pin = null, $etims_integration_url = null, $etims_api_key = null, $company_name = null, $support_phone = null, $support_email = null, $currency_code = null, $financial_year_start_month = null, $vat_rate = null, $etims_taxation_type_code = null, $registration_fee = null) {
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
                      financial_year_start_month = :financial_year_start_month,
                      vat_rate = :vat_rate,
                      etims_taxation_type_code = :etims_taxation_type_code,
                      registration_fee = :registration_fee,
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
        $support_phone = $support_phone !== null && $support_phone !== '' ? $support_phone : '+254 700 000 000';
        $support_email = $support_email !== null && $support_email !== '' ? $support_email : 'support@waterbilling.com';
        $currency_code = $currency_code !== null && $currency_code !== '' ? strtoupper($currency_code) : 'KES';
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
        $stmt->bindParam(":financial_year_start_month", $financial_year_start_month, PDO::PARAM_INT);
        $stmt->bindParam(":vat_rate", $vat_rate);
        $stmt->bindParam(":etims_taxation_type_code", $etims_taxation_type_code);
        $stmt->bindParam(":registration_fee", $registration_fee);
        if ($stmt->execute() && $stmt->rowCount() > 0) {
            return true;
        }

        // If no row exists, create default then update
        $this->createDefault();
        return $stmt->execute();
    }

    private function createDefault() {
          $default_rate = 50.00;
          $default_service = 0.00;
          $default_registration_fee = 0.00;
          $default_company_name = 'BreMac Consultant Ltd';
          $default_support_phone = '+254 700 000 000';
          $default_support_email = 'support@waterbilling.com';
          $default_currency = 'KES';
          $default_fy_start = 1;
          $default_vat_rate = 0.0;
          $default_tax_code = '';
          $query = "INSERT INTO " . $this->table . " (id, rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee) 
              VALUES (1, :rate_per_unit, :service_charge, NULL, NULL, NULL, :company_name, :support_phone, :support_email, :currency_code, :financial_year_start_month, :vat_rate, :etims_taxation_type_code, :registration_fee)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":rate_per_unit", $default_rate);
        $stmt->bindParam(":service_charge", $default_service);
        $stmt->bindParam(":company_name", $default_company_name);
        $stmt->bindParam(":support_phone", $default_support_phone);
        $stmt->bindParam(":support_email", $default_support_email);
        $stmt->bindParam(":currency_code", $default_currency);
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
            support_phone VARCHAR(50) DEFAULT '+254 700 000 000',
            support_email VARCHAR(255) DEFAULT 'support@waterbilling.com',
            currency_code VARCHAR(10) DEFAULT 'KES',
			financial_year_start_month TINYINT UNSIGNED DEFAULT 1,
			vat_rate DECIMAL(5,2) DEFAULT 0.00,
            etims_taxation_type_code VARCHAR(10) NULL,
            registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
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
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN support_phone VARCHAR(50) DEFAULT '+254 700 000 000'");
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
    }
}
?>
