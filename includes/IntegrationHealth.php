<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa_config.php';
require_once __DIR__ . '/../config/sms_config.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/Etims.php';

class IntegrationHealth {
    private $db;

    public function __construct($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        if ($db === null) {
            throw new Exception('Database connection required for IntegrationHealth');
        }

        $this->db = $db;
        self::ensureTable($this->db);
    }

    public static function ensureTable($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        $db->exec("CREATE TABLE IF NOT EXISTS integration_health_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(50) NOT NULL,
            status ENUM('healthy','warning','down') DEFAULT 'warning',
            last_success_at DATETIME NULL,
            last_error_at DATETIME NULL,
            last_error_message TEXT NULL,
            metrics_json LONGTEXT NULL,
            checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service_checked (service_name, checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getLiveSummary() {
        $summary = [];

        $summary['sms'] = [
            'configured' => (bool)(SmsConfig::getApiToken() && SmsConfig::getSenderId()),
            'pending' => $this->countTable("SELECT COUNT(*) FROM sms_queue WHERE status = 'pending'"),
            'failed' => $this->countTable("SELECT COUNT(*) FROM sms_queue WHERE status = 'failed_permanent'"),
            'last_success' => $this->singleValue("SELECT MAX(sent_at) FROM sms_queue WHERE status = 'sent'"),
            'last_error' => $this->singleRow("SELECT created_at, error_message FROM error_logs WHERE service IN ('MobileSasa','SMS') ORDER BY created_at DESC LIMIT 1"),
        ];

        $summary['mpesa'] = [
            'configured' => (bool)(MpesaConfig::getConsumerKey() && MpesaConfig::getConsumerSecret() && MpesaConfig::getShortCode()),
            'pending' => $this->countTable("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'failed' => $this->countTable("SELECT COUNT(*) FROM payments WHERE status = 'failed'"),
            'last_success' => $this->singleValue("SELECT MAX(transaction_date) FROM payments WHERE status = 'completed'"),
            'last_error' => $this->singleRow("SELECT created_at, error_message FROM error_logs WHERE service IN ('M-Pesa','Mpesa') ORDER BY created_at DESC LIMIT 1"),
        ];

        $summary['email'] = [
            'configured' => (bool)(EmailConfig::getHost() && EmailConfig::getFromAddress()),
            'last_error' => $this->singleRow("SELECT created_at, error_message FROM error_logs WHERE service IN ('Email','SMTP') ORDER BY created_at DESC LIMIT 1"),
        ];

        $summary['etims'] = [
            'configured' => (new Etims($this->db))->isConfigured(),
            'failed' => $this->countTable("SELECT COUNT(*) FROM payments WHERE etims_status = 'failed'"),
            'last_success' => $this->singleValue("SELECT MAX(etims_sent_at) FROM payments WHERE etims_status = 'sent'"),
            'last_error' => $this->singleRow("SELECT created_at, error_message FROM error_logs WHERE service IN ('ETIMS','eTIMS') ORDER BY created_at DESC LIMIT 1"),
        ];

        return $summary;
    }

    private function countTable($sql) {
        try {
            $stmt = $this->db->query($sql);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function singleValue($sql) {
        try {
            $stmt = $this->db->query($sql);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function singleRow($sql) {
        try {
            $stmt = $this->db->query($sql);
            return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
