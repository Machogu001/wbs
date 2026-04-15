<?php
require_once __DIR__ . '/../includes/Accounting.php';

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: install.php');
    exit;
}

$db_host    = trim($_POST['db_host'] ?? 'localhost');
$db_name    = trim($_POST['db_name'] ?? 'water_billing');
$db_user    = trim($_POST['db_user'] ?? 'root');
$db_pass    = $_POST['db_pass'] ?? '';
$admin_phone  = trim($_POST['admin_phone'] ?? '254717996492');
$admin_pass   = $_POST['admin_pass'] ?? 'admin123';
$admin_name   = trim($_POST['admin_name']  ?? 'System Administrator');
$admin_email  = trim($_POST['admin_email'] ?? 'admin@bremac.co.ke');
$company_name   = trim($_POST['company_name']   ?? 'Water Billing System');
$support_phone  = trim($_POST['support_phone']  ?? '254724400202');
$support_email  = trim($_POST['support_email']  ?? 'support@bremac.co.ke');
$locale_code    = trim($_POST['locale_code']    ?? 'en-KE');
$timezone_name  = trim($_POST['timezone_name']  ?? 'Africa/Nairobi');

// Sanitise: strip any shell/SQL special chars from display-only strings
$admin_name   = htmlspecialchars_decode(strip_tags($admin_name));
$company_name = htmlspecialchars_decode(strip_tags($company_name));

function installerUpsertEnvKey(string $envPath, string $key, string $value, bool $overwrite = false): bool {
    if (!is_file($envPath) || !is_readable($envPath) || !is_writable($envPath)) {
        return false;
    }

    $content = file_get_contents($envPath);
    if ($content === false) {
        return false;
    }

    $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
    $hasKey = preg_match($pattern, $content) === 1;

    if ($hasKey) {
        if (!$overwrite) {
            return true;
        }
        $newLine = $key . '=' . $value;
        $updated = preg_replace($pattern, $newLine, $content, 1);
    } else {
        $separator = (strlen($content) > 0 && substr($content, -1) !== "\n") ? "\n" : '';
        $updated = $content . $separator . $key . '=' . $value . "\n";
    }

    if ($updated === null) {
        return false;
    }

    return file_put_contents($envPath, $updated, LOCK_EX) !== false;
}

try {
    $conn = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->exec("USE `$db_name`");

    // Users table
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        account_number VARCHAR(20) UNIQUE NOT NULL,
        username VARCHAR(50) UNIQUE NULL,
        full_name VARCHAR(100) NOT NULL,
        phone_number VARCHAR(15) UNIQUE NOT NULL,
        email VARCHAR(100),
        id_number VARCHAR(20),
        tax_pin VARCHAR(60) NULL,
        address TEXT,
        meter_number VARCHAR(50) UNIQUE NULL,
        connection_type ENUM('domestic', 'commercial', 'industrial') DEFAULT 'domestic',
        location_label VARCHAR(191) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        password_hash VARCHAR(255) NOT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
        two_factor_method VARCHAR(10) NOT NULL DEFAULT 'sms',
        role ENUM('customer', 'admin', 'reader', 'finance', 'support') DEFAULT 'customer',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
        INDEX idx_phone (phone_number),
        INDEX idx_account (account_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bills table
    $conn->exec("CREATE TABLE IF NOT EXISTS bills (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        account_number VARCHAR(20),
        billing_month DATE NOT NULL,
        previous_reading DECIMAL(10,2) DEFAULT 0,
        current_reading DECIMAL(10,2) NOT NULL,
        consumption DECIMAL(10,2) NOT NULL,
        rate_per_unit DECIMAL(10,2) DEFAULT 50.00,
        service_charge DECIMAL(10,2) DEFAULT 0.00,
        base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount DECIMAL(10,2) NOT NULL,
        due_date DATE NOT NULL,
        penalty DECIMAL(10,2) DEFAULT 0,
        status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
        tariff_plan_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Billing settings table
    $conn->exec("CREATE TABLE IF NOT EXISTS billing_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        rate_per_unit DECIMAL(10,2) NOT NULL DEFAULT 50.00,
        service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        enforce_location_accuracy TINYINT(1) NOT NULL DEFAULT 0,
        company_pin VARCHAR(60) NULL,
        company_name VARCHAR(255) DEFAULT 'Water Billing System',
        support_phone VARCHAR(50) DEFAULT '254724400202',
        support_email VARCHAR(255) DEFAULT 'support@bremac.co.ke',
        currency_code VARCHAR(10) DEFAULT 'KES',
        locale_code VARCHAR(20) DEFAULT 'en-KE',
        timezone_name VARCHAR(100) DEFAULT 'Africa/Nairobi',
        financial_year_start_month TINYINT UNSIGNED DEFAULT 1,
        vat_rate DECIMAL(5,2) DEFAULT 0.00,
        etims_integration_url VARCHAR(255) NULL,
        etims_api_key VARCHAR(255) NULL,
        etims_taxation_type_code VARCHAR(10) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS tariff_plans (
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

    $conn->exec("CREATE TABLE IF NOT EXISTS tariff_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tariff_plan_id INT NOT NULL,
        from_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        to_unit DECIMAL(10,2) NULL,
        rate_per_unit DECIMAL(10,4) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_plan_from_unit (tariff_plan_id, from_unit),
        CONSTRAINT fk_tariff_blocks_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS bill_line_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        line_type ENUM('usage','service_charge','registration_fee','tax','penalty','adjustment') NOT NULL,
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit_rate DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        line_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bill_line (bill_id),
        CONSTRAINT fk_bill_line_items_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Payments table
    $conn->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        bill_id INT NOT NULL,
        user_id INT NOT NULL,
        registration_id INT NULL,
        mpesa_receipt VARCHAR(50),
        phone_number VARCHAR(15),
        amount DECIMAL(10,2) NOT NULL,
        transaction_date DATETIME,
        status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
        merchant_request_id VARCHAR(100),
        checkout_request_id VARCHAR(100),
        result_code VARCHAR(10),
        result_desc TEXT,
        etims_status VARCHAR(20) NULL,
        etims_sent_at DATETIME NULL,
        etims_last_status_code INT NULL,
        etims_last_response TEXT NULL,
        etims_last_error TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_receipt (mpesa_receipt),
        INDEX idx_checkout (checkout_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // User sessions table
    $conn->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        session_token VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Meter readings table
    $conn->exec("CREATE TABLE IF NOT EXISTS meter_readings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        account_number VARCHAR(20),
        meter_number VARCHAR(50),
        current_reading DECIMAL(10,2) NOT NULL,
        billing_month DATE NOT NULL,
        due_date DATE NOT NULL,
        photo_path VARCHAR(255) NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_by INT NULL,
        approved_at TIMESTAMP NULL,
        bill_id INT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Complaints table
    $conn->exec("CREATE TABLE IF NOT EXISTS complaints (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        subject VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Login attempts table (rate-limiting brute force protection)
    $conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NOT NULL,
        INDEX idx_identifier_ip (identifier, ip_address),
        INDEX idx_last_attempt_at (last_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Activity logs table
    $conn->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NULL,
        entity_id INT NULL,
        description TEXT NULL,
        metadata TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_id),
        INDEX idx_user (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Credit notes table
    $conn->exec("CREATE TABLE IF NOT EXISTS credit_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bill_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        units_credited DECIMAL(10,2) DEFAULT 0.00,
        amount_credited DECIMAL(10,2) NOT NULL,
        type VARCHAR(20) NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note TEXT NULL,
        INDEX idx_bill_id (bill_id),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // SMS Queue table
    $conn->exec("CREATE TABLE IF NOT EXISTS sms_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'general',
        status ENUM('pending', 'sent', 'failed_permanent') DEFAULT 'pending',
        http_code INT NULL,
        response TEXT NULL,
        retry_count INT DEFAULT 0,
        last_error TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL,
        last_attempt TIMESTAMP NULL,
        INDEX idx_status (status),
        INDEX idx_phone (phone),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Error logs table
    $conn->exec("CREATE TABLE IF NOT EXISTS error_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Admin SMS broadcast history table
    $conn->exec("CREATE TABLE IF NOT EXISTS admin_broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_by INT NOT NULL,
        audience ENUM('clients_all','clients_selected','staff_all','staff_selected') NOT NULL,
        subject VARCHAR(191) NOT NULL,
        message TEXT NOT NULL,
        recipient_count INT NOT NULL DEFAULT 0,
        target_ids_json TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Internal staff chat messages table
    $conn->exec("CREATE TABLE IF NOT EXISTS internal_chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_sender_id (sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Reusable SMS templates table
    $conn->exec("CREATE TABLE IF NOT EXISTS sms_message_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_by INT NOT NULL,
        recipient_group ENUM('clients','staff') NOT NULL,
        title VARCHAR(120) NOT NULL,
        subject VARCHAR(191) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group_created (recipient_group, created_at),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Approval workflows table
    $conn->exec("CREATE TABLE IF NOT EXISTS approval_workflows (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Approval stages table
    $conn->exec("CREATE TABLE IF NOT EXISTS approval_stages (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Customer credits table
    $conn->exec("CREATE TABLE IF NOT EXISTS customer_credits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        credit_limit DECIMAL(10,2) DEFAULT 10000.00,
        available_credit DECIMAL(10,2) DEFAULT 10000.00,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Support chat threads table
    $conn->exec("CREATE TABLE IF NOT EXISTS support_threads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        last_message_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_status (user_id, status),
        INDEX idx_last_message_at (last_message_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Support chat messages table
    $conn->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        sender_type ENUM('user','admin') NOT NULL,
        sender_id INT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_thread (thread_id),
        INDEX idx_created_at (created_at),
        CONSTRAINT fk_support_messages_thread FOREIGN KEY (thread_id)
            REFERENCES support_threads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Support chat typing indicators table
    $conn->exec("CREATE TABLE IF NOT EXISTS support_typing (
        thread_id INT NOT NULL,
        actor ENUM('user','admin') NOT NULL,
        is_typing TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (thread_id, actor),
        CONSTRAINT fk_support_typing_thread FOREIGN KEY (thread_id)
            REFERENCES support_threads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Demand notices table
    $conn->exec("CREATE TABLE IF NOT EXISTS demand_notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        user_id INT NOT NULL,
        notice_number VARCHAR(40) NOT NULL UNIQUE,
        notice_type ENUM('overdue','final') DEFAULT 'overdue',
        amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','sent','acknowledged','resolved','cancelled') DEFAULT 'draft',
        channel VARCHAR(30) NULL,
        note TEXT NULL,
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL,
        resolved_at TIMESTAMP NULL,
        INDEX idx_bill_status (bill_id, status),
        INDEX idx_user_status (user_id, status),
        INDEX idx_generated_at (generated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Finance approval items table
    $conn->exec("CREATE TABLE IF NOT EXISTS financial_approval_items (
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

    // Integration health checks table
    $conn->exec("CREATE TABLE IF NOT EXISTS integration_health_checks (
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

    Accounting::ensureTables($conn);

    // IP geo-lookup cache table (used by activity log)
    $conn->exec("CREATE TABLE IF NOT EXISTS activity_ip_lookup (
        ip_address VARCHAR(45) PRIMARY KEY,
        location_label VARCHAR(191) NULL,
        network_org VARCHAR(191) NULL,
        asn VARCHAR(64) NULL,
        checked_at DATETIME NOT NULL,
        INDEX idx_checked_at (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Country dial codes table (used by international phone selectors)
    $conn->exec("CREATE TABLE IF NOT EXISTS country_dial_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        country_name VARCHAR(100) NOT NULL,
        iso2 CHAR(2) DEFAULT NULL,
        dial_code VARCHAR(8) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_iso2 (iso2),
        KEY idx_dial_code (dial_code),
        KEY idx_country_name (country_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed country dial codes (Kenya first as default)
    $dialCodesCount = $conn->query("SELECT COUNT(*) AS total FROM country_dial_codes")->fetch(PDO::FETCH_ASSOC);
    if (!$dialCodesCount || (int)$dialCodesCount['total'] === 0) {
        $conn->exec("INSERT INTO country_dial_codes (country_name, iso2, dial_code) VALUES
            ('Kenya','KE','254'),('Afghanistan','AF','93'),('Albania','AL','355'),
            ('Algeria','DZ','213'),('Andorra','AD','376'),('Angola','AO','244'),
            ('Argentina','AR','54'),('Armenia','AM','374'),('Australia','AU','61'),
            ('Austria','AT','43'),('Azerbaijan','AZ','994'),('Bahrain','BH','973'),
            ('Bangladesh','BD','880'),('Belarus','BY','375'),('Belgium','BE','32'),
            ('Benin','BJ','229'),('Bolivia','BO','591'),('Botswana','BW','267'),
            ('Brazil','BR','55'),('Bulgaria','BG','359'),('Burkina Faso','BF','226'),
            ('Burundi','BI','257'),('Cambodia','KH','855'),('Cameroon','CM','237'),
            ('Canada','CA','1'),('Chad','TD','235'),('Chile','CL','56'),
            ('China','CN','86'),('Colombia','CO','57'),('Congo','CG','242'),
            ('Costa Rica','CR','506'),('Croatia','HR','385'),('Cyprus','CY','357'),
            ('Czech Republic','CZ','420'),('Denmark','DK','45'),('Djibouti','DJ','253'),
            ('DR Congo','CD','243'),('Egypt','EG','20'),('Eritrea','ER','291'),
            ('Estonia','EE','372'),('Eswatini','SZ','268'),('Ethiopia','ET','251'),
            ('Finland','FI','358'),('France','FR','33'),('Gabon','GA','241'),
            ('Gambia','GM','220'),('Georgia','GE','995'),('Germany','DE','49'),
            ('Ghana','GH','233'),('Greece','GR','30'),('Guinea','GN','224'),
            ('Hungary','HU','36'),('India','IN','91'),('Indonesia','ID','62'),
            ('Iran','IR','98'),('Iraq','IQ','964'),('Ireland','IE','353'),
            ('Israel','IL','972'),('Italy','IT','39'),('Japan','JP','81'),
            ('Jordan','JO','962'),('Kazakhstan','KZ','7'),('Kuwait','KW','965'),
            ('Latvia','LV','371'),('Lebanon','LB','961'),('Lesotho','LS','266'),
            ('Liberia','LR','231'),('Libya','LY','218'),('Lithuania','LT','370'),
            ('Luxembourg','LU','352'),('Madagascar','MG','261'),('Malawi','MW','265'),
            ('Malaysia','MY','60'),('Mali','ML','223'),('Malta','MT','356'),
            ('Mauritania','MR','222'),('Mauritius','MU','230'),('Mexico','MX','52'),
            ('Morocco','MA','212'),('Mozambique','MZ','258'),('Namibia','NA','264'),
            ('Nepal','NP','977'),('Netherlands','NL','31'),('New Zealand','NZ','64'),
            ('Niger','NE','227'),('Nigeria','NG','234'),('Norway','NO','47'),
            ('Oman','OM','968'),('Pakistan','PK','92'),('Peru','PE','51'),
            ('Philippines','PH','63'),('Poland','PL','48'),('Portugal','PT','351'),
            ('Qatar','QA','974'),('Romania','RO','40'),('Russia','RU','7'),
            ('Rwanda','RW','250'),('Saudi Arabia','SA','966'),('Senegal','SN','221'),
            ('Serbia','RS','381'),('Sierra Leone','SL','232'),('Singapore','SG','65'),
            ('Slovakia','SK','421'),('Slovenia','SI','386'),('Somalia','SO','252'),
            ('South Africa','ZA','27'),('South Sudan','SS','211'),('Spain','ES','34'),
            ('Sri Lanka','LK','94'),('Sudan','SD','249'),('Sweden','SE','46'),
            ('Switzerland','CH','41'),('Syria','SY','963'),('Tanzania','TZ','255'),
            ('Thailand','TH','66'),('Tunisia','TN','216'),('Turkey','TR','90'),
            ('Uganda','UG','256'),('Ukraine','UA','380'),('United Arab Emirates','AE','971'),
            ('United Kingdom','GB','44'),('United States','US','1'),('Uruguay','UY','598'),
            ('Yemen','YE','967'),('Zambia','ZM','260'),('Zimbabwe','ZW','263')
        ");
    }


    // ----------------------------------------------------------------

    $settingsCount = $conn->query("SELECT COUNT(*) as count FROM billing_settings")->fetch(PDO::FETCH_ASSOC);
    if (!$settingsCount || (int)$settingsCount['count'] === 0) {
        $stmtSettings = $conn->prepare(
            "INSERT INTO billing_settings
             (id, rate_per_unit, service_charge, registration_fee, enforce_location_accuracy, company_name, support_phone,
              support_email, currency_code, locale_code, timezone_name, financial_year_start_month, vat_rate,
              company_pin, etims_integration_url, etims_api_key, etims_taxation_type_code)
             VALUES (1, 50.00, 0.00, 0.00, 0, :company_name, :support_phone,
                     :support_email, 'KES', :locale_code, :timezone_name, 1, 0.00, NULL, NULL, NULL, NULL)"
        );
        $stmtSettings->execute([
            ':company_name'  => $company_name,
            ':support_phone' => $support_phone,
            ':support_email' => $support_email,
            ':locale_code'   => $locale_code,
            ':timezone_name' => $timezone_name,
        ]);
    }

    $tariffCount = $conn->query("SELECT COUNT(*) as count FROM tariff_plans")->fetch(PDO::FETCH_ASSOC);
    if (!$tariffCount || (int)$tariffCount['count'] === 0) {
        $stmtTariff = $conn->prepare("INSERT INTO tariff_plans
            (name, category, effective_from, base_rate_per_unit, service_charge, vat_rate, is_active)
            VALUES
            ('Default Standard Tariff', 'all', :effective_from, 50.0000, 0.00, 0.00, 1)");
        $stmtTariff->execute([
            ':effective_from' => date('Y-m-01'),
        ]);

        $defaultTariffId = (int)$conn->lastInsertId();
        if ($defaultTariffId > 0) {
            $stmtTariffBlock = $conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit)
                VALUES (:tariff_plan_id, 0.00, NULL, 50.0000)");
            $stmtTariffBlock->execute([
                ':tariff_plan_id' => $defaultTariffId,
            ]);
        }
    }

    $password_hash = password_hash($admin_pass, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT IGNORE INTO users
        (account_number, full_name, phone_number, email, id_number, address, meter_number,
         connection_type, password_hash, role, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['ADMIN001', $admin_name, $admin_phone, $admin_email,
                    '00000000', 'System Address', 'ADMIN001', 'domestic', $password_hash, 'admin', 'active']);

    $test_account = "WB" . date("ym") . "0001";
    $test_password = password_hash("test123", PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT IGNORE INTO users
        (account_number, full_name, phone_number, email, id_number, address, meter_number,
         connection_type, password_hash, role, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$test_account, 'John Doe', '254712345678', 'john@example.com',
                    '12345678', '123 Main St, Nairobi', 'MTR001234', 'domestic',
                    $test_password, 'customer', 'active']);

    $testUser = $conn->prepare("SELECT id FROM users WHERE account_number = ?");
    $testUser->execute([$test_account]);
    $testUserRow = $testUser->fetch(PDO::FETCH_ASSOC);
    if ($testUserRow) {
        $conn->prepare("INSERT IGNORE INTO customer_credits
            (user_id, credit_limit, available_credit, status) VALUES (?, 10000.00, 10000.00, 'active')")
            ->execute([$testUserRow['id']]);
    }

    // Best-effort: ensure key .env entries exist for a fresh install.
    // Existing values are preserved (no overwrite) to avoid clobbering real secrets.
    $envSetupStatus = 'Not attempted';
    $envSetupNotes = [];
    $projectRoot = realpath(__DIR__ . '/..');
    $envPath = $projectRoot ? ($projectRoot . '/.env') : (__DIR__ . '/../.env');

    if (is_file($envPath) && is_readable($envPath) && is_writable($envPath)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $callbackDefault = $scheme . '://' . $host . '/api/payments/payment_callback';
        $generatedPaymentLinkSecret = bin2hex(random_bytes(32));

        $envDefaults = [
            'MPESA_ENV' => 'sandbox',
            'MPESA_SHORTCODE' => '',
            'MPESA_CONSUMER_KEY' => '',
            'MPESA_CONSUMER_SECRET' => '',
            'MPESA_PASSKEY' => '',
            'MPESA_CALLBACK_URL' => $callbackDefault,
            'PAYMENT_LINK_SECRET' => $generatedPaymentLinkSecret,
        ];

        $allOk = true;
        foreach ($envDefaults as $k => $v) {
            $ok = installerUpsertEnvKey($envPath, $k, $v, false);
            $allOk = $allOk && $ok;
            if (!$ok) {
                $envSetupNotes[] = 'Could not set ' . $k . ' in .env.';
            }
        }

        if ($allOk) {
            $envSetupStatus = 'Prepared .env defaults (existing values preserved).';
        } else {
            $envSetupStatus = 'Partially prepared .env. Please verify M-Pesa keys manually.';
        }
    } else {
        $envSetupStatus = '.env is not writable by installer; configure M-Pesa and PAYMENT_LINK_SECRET manually.';
    }

    // ----------------------------------------------------------------
    // Write config files
    // ----------------------------------------------------------------
    $config_content = '<?php
class Database {
    private $host = "' . addslashes($db_host) . '";
    private $db_name = "' . addslashes($db_name) . '";
    private $username = "' . addslashes($db_user) . '";
    private $password = "' . addslashes($db_pass) . '";
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            return null;
        }
        return $this->conn;
    }

    public static function testConnection(): bool {
        try {
            $instance = new self();
            $conn = $instance->getConnection();
            return $conn !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
?>';

    file_put_contents('../config/database.php', $config_content);
    file_put_contents('../config/installed.lock', 'installed=' . date('c') . "\n");

    echo '<!DOCTYPE html>
<html>
<head>
    <title>Installation Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3 class="text-center mb-0">Installation Complete!</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-success">
                        <h4>' . htmlspecialchars($company_name) . ' installed successfully!</h4>
                    </div>
                    <h5>Initial System Settings:</h5>
                    <ul>
                        <li><strong>Registration Fee:</strong> KES 0.00</li>
                        <li><strong>GPS Enforcement:</strong> <span class="badge bg-secondary">Off</span> <span class="text-muted">(can be enabled later in Settings)</span></li>
                    </ul>
                    <h5>Admin Credentials:</h5>
                    <ul>
                        <li><strong>Name:</strong> ' . htmlspecialchars($admin_name) . '</li>
                        <li><strong>Phone:</strong> ' . htmlspecialchars($admin_phone) . '</li>
                        <li><strong>Email:</strong> ' . htmlspecialchars($admin_email) . '</li>
                        <li><strong>Password:</strong> ' . htmlspecialchars($admin_pass) . '</li>
                    </ul>
                    <div class="alert alert-warning">
                        <strong>Important:</strong>
                        <ul>
                            <li>Change the admin password immediately after login</li>
                            <li>Delete the <code>install/</code> directory for security</li>
                            <li>Configure M-Pesa credentials in <code>.env</code> (MPESA_ENV, MPESA_SHORTCODE or MPESA_SHORT_CODE, MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_PASSKEY, MPESA_CALLBACK_URL)</li>
                            <li>Ensure <code>PAYMENT_LINK_SECRET</code> is set in <code>.env</code></li>
                        </ul>
                        <p class="mb-1"><strong>.env setup:</strong> ' . htmlspecialchars($envSetupStatus) . '</p>
                        ' . (!empty($envSetupNotes) ? '<p class="mb-0 small text-danger">' . htmlspecialchars(implode(' ', $envSetupNotes)) . '</p>' : '') . '
                    </div>
                    <div class="text-center mt-4">
                        <a href="/login" class="btn btn-success btn-lg">Login Now</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>';

} catch(PDOException $e) {
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Installation Failed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h3 class="text-center mb-0">Installation Failed</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <h4>Installation Failed!</h4>
                        <p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>
                    </div>
                    <div class="text-center">
                        <a href="install.php" class="btn btn-primary">Try Again</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>';
}
?>
