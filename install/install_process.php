<?php
if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: install.php');
    exit;
}

$db_host = $_POST['db_host'];
$db_name = $_POST['db_name'];
$db_user = $_POST['db_user'];
$db_pass = $_POST['db_pass'];
$admin_phone = $_POST['admin_phone'];
$admin_pass = $_POST['admin_pass'];

// Test database connection
try {
    $conn = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $conn->exec("CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->exec("USE $db_name");
    
    // Create tables
    $sql = "
    -- Users table
    CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        account_number VARCHAR(20) UNIQUE NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone_number VARCHAR(15) UNIQUE NOT NULL,
        email VARCHAR(100),
        id_number VARCHAR(20),
        tax_pin VARCHAR(60) NULL,
        address TEXT,
        meter_number VARCHAR(50) UNIQUE NOT NULL,
        connection_type ENUM('domestic', 'commercial', 'industrial') DEFAULT 'domestic',
        location_label VARCHAR(191) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('customer', 'admin', 'reader') DEFAULT 'customer',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
        INDEX idx_phone (phone_number),
        INDEX idx_account (account_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    
    -- Bills table
    CREATE TABLE IF NOT EXISTS bills (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        account_number VARCHAR(20),
        billing_month DATE NOT NULL,
        previous_reading DECIMAL(10,2) DEFAULT 0,
        current_reading DECIMAL(10,2) NOT NULL,
        consumption DECIMAL(10,2) NOT NULL,
        rate_per_unit DECIMAL(10,2) DEFAULT 50.00,
        service_charge DECIMAL(10,2) DEFAULT 0.00,
        amount DECIMAL(10,2) NOT NULL,
        due_date DATE NOT NULL,
        penalty DECIMAL(10,2) DEFAULT 0,
        status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Billing settings table
    CREATE TABLE IF NOT EXISTS billing_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        rate_per_unit DECIMAL(10,2) NOT NULL DEFAULT 50.00,
        service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        company_pin VARCHAR(60) NULL,
        etims_integration_url VARCHAR(255) NULL,
        etims_api_key VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    
    -- Payments table
    CREATE TABLE IF NOT EXISTS payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        bill_id INT NOT NULL,
        user_id INT NOT NULL,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    
    -- User sessions table
    CREATE TABLE IF NOT EXISTS user_sessions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        session_token VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Meter readings table (pending approval)
    CREATE TABLE IF NOT EXISTS meter_readings (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Complaints table
    CREATE TABLE IF NOT EXISTS complaints (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        subject VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $conn->exec($sql);

    // Seed billing settings if empty
    $settingsCount = $conn->query("SELECT COUNT(*) as count FROM billing_settings")->fetch(PDO::FETCH_ASSOC);
    if(!$settingsCount || (int)$settingsCount['count'] === 0) {
        $conn->exec("INSERT INTO billing_settings (id, rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key) VALUES (1, 50.00, 0.00, NULL, NULL, NULL)");
    }
    
    // Create admin user
    $account_number = "ADMIN001";
    $full_name = "System Administrator";
    $email = "admin@waterbilling.com";
    $password_hash = password_hash($admin_pass, PASSWORD_BCRYPT);
    
    $stmt = $conn->prepare("INSERT INTO users (account_number, full_name, phone_number, email, id_number, tax_pin, address, meter_number, connection_type, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$account_number, $full_name, $admin_phone, $email, '00000000', null, 'System Address', 'ADMIN001', 'domestic', $password_hash, 'admin', 'active']);
    
    // Create test customer
    $test_account = "WB" . date("ym") . "0001";
    $test_password = password_hash("test123", PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (account_number, full_name, phone_number, email, id_number, tax_pin, address, meter_number, connection_type, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$test_account, 'John Doe', '254712345678', 'john@example.com', '12345678', null, '123 Main St, Nairobi', 'MTR001234', 'domestic', $test_password, 'customer', 'active']);
    
    // Update database configuration file
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
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
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
}
?>';
    
    file_put_contents('../config/database.php', $config_content);

    // Create installation lock file
    file_put_contents('../config/installed.lock', 'installed=' . date('c') . "\n");
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Installation Complete</title>
        <link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">
    </head>
    <body>
        <div class=\"container mt-5\">
            <div class=\"row justify-content-center\">
                <div class=\"col-md-8\">
                    <div class=\"card\">
                        <div class=\"card-header bg-success text-white\">
                            <h3 class=\"text-center mb-0\">Installation Complete!</h3>
                        </div>
                        <div class=\"card-body\">
                            <div class=\"alert alert-success\">
                                <h4><i class=\"bi bi-check-circle\"></i> Water Billing System has been successfully installed!</h4>
                            </div>
                            
                            <h5>Admin Credentials:</h5>
                            <ul>
                                <li><strong>Phone:</strong> $admin_phone</li>
                                <li><strong>Password:</strong> $admin_pass</li>
                            </ul>
                            
                            <div class=\"alert alert-warning\">
                                <strong>Important:</strong> 
                                <ul>
                                    <li>Change the admin password immediately after login</li>
                                    <li>Delete the <code>install/</code> directory for security</li>
                                    <li>Configure M-Pesa credentials in <code>config/mpesa_config.php</code></li>
                                </ul>
                            </div>
                            
                            <div class=\"text-center mt-4\">
                                <a href=\"../index.php\" class=\"btn btn-primary btn-lg\">
                                    Go to Homepage
                                </a>
                                    <a href=\"/login\" class=\"btn btn-success btn-lg\">
                                        Login Now
                                    </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
    
} catch(PDOException $e) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Installation Failed</title>
        <link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">
    </head>
    <body>
        <div class=\"container mt-5\">
            <div class=\"row justify-content-center\">
                <div class=\"col-md-8\">
                    <div class=\"card\">
                        <div class=\"card-header bg-danger text-white\">
                            <h3 class=\"text-center mb-0\">Installation Failed</h3>
                        </div>
                        <div class=\"card-body\">
                            <div class=\"alert alert-danger\">
                                <h4><i class=\"bi bi-exclamation-triangle\"></i> Installation Failed!</h4>
                                <p>Error: " . $e->getMessage() . "</p>
                            </div>
                            <div class=\"text-center\">
                                <a href=\"install.php\" class=\"btn btn-primary\">
                                    Try Again
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}
?>
