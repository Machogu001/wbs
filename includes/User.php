<?php
require_once __DIR__ . '/ClientMeter.php';

class User {
    private $conn;
    private $table = "users";
    
    public $id;
    public $account_number;
    public $username;
    public $full_name;
    public $customer_type;
    public $company_name;
    public $contact_person_name;
    public $company_registration_number;
    public $phone_number;
    public $email;
    public $id_number;
    public $tax_pin;
    public $address;
    public $meter_number;
    public $connection_type;
    public $unit_rate;
    public $location_label;
    public $latitude;
    public $longitude;
    public $password;
    public $password_hash;
    public $role;
    public $status;
    public $created_at;
    
    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTaxPinColumn();
        $this->ensureMustChangePasswordColumn();
        $this->ensureLocationColumns();
        $this->ensureCustomerProfileColumns();
        $this->ensureTwoFactorColumns();
        $this->ensureThemePreferenceColumn();
        $this->ensureMeterNumberNullable();
        $this->ensureUnitRateColumn();
        $this->ensureUsernameColumn();
        $this->ensureUsernameUniqueIndex();
    }
    
    // Create new user
    public function create() {
        $query = "INSERT INTO " . $this->table . "
                SET account_number = :account_number,
                    username = :username,
                    full_name = :full_name,
                    customer_type = :customer_type,
                    company_name = :company_name,
                    contact_person_name = :contact_person_name,
                    company_registration_number = :company_registration_number,
                    phone_number = :phone_number,
                    email = :email,
                    id_number = :id_number,
                    tax_pin = :tax_pin,
                    address = :address,
                    meter_number = :meter_number,
                    connection_type = :connection_type,
                    unit_rate = :unit_rate,
                    location_label = :location_label,
                    latitude = :latitude,
                    longitude = :longitude,
                    password_hash = :password_hash,
                    role = :role,
                    status = :status";
        
        $stmt = $this->conn->prepare($query);
        
        // Hash password
        $this->password_hash = password_hash($this->password, PASSWORD_BCRYPT);
        
        // Bind parameters
        $stmt->bindParam(":account_number", $this->account_number);
        $username = $this->username !== null && $this->username !== '' ? $this->username : null;
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":full_name", $this->full_name);
        $customerType = $this->customer_type !== null && $this->customer_type !== '' ? $this->customer_type : 'individual';
        $companyName = $this->company_name !== null && $this->company_name !== '' ? $this->company_name : null;
        $contactPersonName = $this->contact_person_name !== null && $this->contact_person_name !== '' ? $this->contact_person_name : null;
        $companyRegistrationNumber = $this->company_registration_number !== null && $this->company_registration_number !== '' ? $this->company_registration_number : null;
        $stmt->bindParam(":customer_type", $customerType);
        $stmt->bindParam(":company_name", $companyName);
        $stmt->bindParam(":contact_person_name", $contactPersonName);
        $stmt->bindParam(":company_registration_number", $companyRegistrationNumber);
        $stmt->bindParam(":phone_number", $this->phone_number);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":id_number", $this->id_number);
        $stmt->bindParam(":tax_pin", $this->tax_pin);
        $stmt->bindParam(":address", $this->address);
        $stmt->bindParam(":meter_number", $this->meter_number);
        $stmt->bindParam(":connection_type", $this->connection_type);
        $unitRate = $this->unit_rate !== null && $this->unit_rate !== '' ? (float)$this->unit_rate : null;
        $stmt->bindParam(":unit_rate", $unitRate);
        $locationLabel = $this->location_label !== null && $this->location_label !== '' ? $this->location_label : null;
        // Latitude/longitude are optional; allow null
        $lat = $this->latitude !== null && $this->latitude !== '' ? $this->latitude : null;
        $lng = $this->longitude !== null && $this->longitude !== '' ? $this->longitude : null;
        $stmt->bindParam(":location_label", $locationLabel);
        $stmt->bindParam(":latitude", $lat);
        $stmt->bindParam(":longitude", $lng);
        $stmt->bindParam(":password_hash", $this->password_hash);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":status", $this->status);
        
        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            try {
                $clientMeterService = new ClientMeter($this->conn);
                $clientMeterService->syncPrimaryMeter((int)$this->id, (string)$this->meter_number);
            } catch (Throwable $e) {
                error_log('Primary meter sync failed for user #' . (int)$this->id . ': ' . $e->getMessage());
            }
            return true;
        }
        
        return false;
    }

    private function ensureUnitRateColumn(): void {
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN unit_rate DECIMAL(10,4) NULL AFTER connection_type");
        } catch (PDOException $e) {
            // Ignore if the column already exists.
        }
    }
    
    // Check if phone exists
    public function meterNumberExists(string $meterNumber, int $excludeId = 0): bool {
        $clientMeterService = new ClientMeter($this->conn);
        return $clientMeterService->meterExistsForAnotherUser($meterNumber, $excludeId);
    }

    public function phoneExists($phone) {
        $query = "SELECT id FROM " . $this->table . " 
                 WHERE phone_number = :phone_number 
                 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":phone_number", $phone);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }

    // Check if username exists
    public function usernameExists($username) {
        $query = "SELECT id FROM " . $this->table . "
                 WHERE username = :username
                 LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
    
        // Login user with account number, phone number, or email
        public function login($identifier, $password) {
                 $query = "SELECT id, account_number, username, full_name, phone_number,
                    email, id_number, tax_pin, address, meter_number,
                    connection_type, password_hash, role, status, must_change_password,
                                        two_factor_enabled, two_factor_method, theme_preference
                FROM " . $this->table . " 
                WHERE status = 'active'
                  AND (phone_number = :identifier 
                    OR account_number = :identifier
                                        OR email = :identifier
                                        OR username = :identifier)
                LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":identifier", $identifier);
        $stmt->execute();
        
        if($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(password_verify($password, $row['password_hash'])) {
                // Return user data without password
                unset($row['password_hash']);
                return $row;
            }
        }
        
        return false;
    }

     // Fetch full auth row (including password_hash) by identifier, regardless of status
     public function getAuthRowByIdentifier($identifier) {
          $query = "SELECT id, account_number, username, full_name, phone_number,
                            email, id_number, tax_pin, address, meter_number,
                            connection_type, password_hash, role, status, must_change_password,
                            two_factor_enabled, two_factor_method, theme_preference
                 FROM " . $this->table . "
                 WHERE phone_number = :identifier
                     OR account_number = :identifier
                     OR email = :identifier
                     OR username = :identifier
                 LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":identifier", $identifier);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Find user by identifier (account number, phone, or email), ignoring status
    public function findByIdentifier($identifier) {
        $query = "SELECT id, status FROM " . $this->table . " 
                  WHERE phone_number = :identifier 
                     OR account_number = :identifier
                            OR email = :identifier
                            OR username = :identifier
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":identifier", $identifier);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    // Get user by ID
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get user by account number
    public function getByAccountNumber($account_number) {
        $query = "SELECT u.*,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT um.meter_number ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR ',')
                        FROM user_meters um
                        WHERE um.user_id = u.id AND um.status = 'active'), u.meter_number) AS meter_numbers,
                    COALESCE((SELECT GROUP_CONCAT(CONCAT(um.meter_number, '::', COALESCE(um.meter_label, '')) ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR '||')
                        FROM user_meters um
                        WHERE um.user_id = u.id AND um.status = 'active'), CONCAT(COALESCE(u.meter_number, ''), '::')) AS meter_details
                  FROM " . $this->table . " u WHERE u.account_number = :account_number LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":account_number", $account_number);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get user by meter number
    public function getByMeterNumber($meter_number) {
        $clientMeterService = new ClientMeter($this->conn);
        return $clientMeterService->findUserByMeter((string)$meter_number);
    }

    // Search by name or account/meter (supports partial matches for typeahead)
    public function searchByNameOrAccount($term, $limit = 10) {
        $like = '%' . $term . '%';
          $query = "SELECT u.*,
                          COALESCE((SELECT GROUP_CONCAT(DISTINCT um.meter_number ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR ',')
                                FROM user_meters um
                                WHERE um.user_id = u.id AND um.status = 'active'), u.meter_number) AS meter_numbers,
                          COALESCE((SELECT GROUP_CONCAT(CONCAT(um.meter_number, '::', COALESCE(um.meter_label, '')) ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR '||')
                                FROM user_meters um
                                WHERE um.user_id = u.id AND um.status = 'active'), CONCAT(COALESCE(u.meter_number, ''), '::')) AS meter_details
                        FROM " . $this->table . " u
                        WHERE u.account_number LIKE :like
                            OR u.meter_number LIKE :like
                            OR u.full_name LIKE :like
                            OR EXISTS (
                                SELECT 1 FROM user_meters um2
                                WHERE um2.user_id = u.id AND um2.status = 'active' AND um2.meter_number LIKE :meter_like
                            )
                            OR EXISTS (
                                SELECT 1 FROM user_meters um3
                                WHERE um3.user_id = u.id AND um3.status = 'active' AND COALESCE(um3.meter_label, '') LIKE :meter_label_like
                            )
                        ORDER BY u.full_name ASC
                        LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":like", $like);
          $stmt->bindParam(":meter_like", $like);
        $stmt->bindParam(":meter_label_like", $like);
        $stmt->bindValue(":limit", (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listAll() {
          $query = "SELECT u.id, u.account_number, u.full_name, u.phone_number, u.meter_number, u.status,
                          COALESCE((SELECT GROUP_CONCAT(DISTINCT um.meter_number ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR ',')
                                FROM user_meters um
                                WHERE um.user_id = u.id AND um.status = 'active'), u.meter_number) AS meter_numbers,
                          COALESCE((SELECT GROUP_CONCAT(CONCAT(um.meter_number, '::', COALESCE(um.meter_label, '')) ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR '||')
                                FROM user_meters um
                                WHERE um.user_id = u.id AND um.status = 'active'), CONCAT(COALESCE(u.meter_number, ''), '::')) AS meter_details
                        FROM " . $this->table . " u
                        ORDER BY u.full_name ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureTaxPinColumn() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'tax_pin'");
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN tax_pin VARCHAR(60) NULL AFTER id_number");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors here; login/registration will continue using existing columns.
        }
    }

    private function ensureMustChangePasswordColumn() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'must_change_password'");
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors here as well.
        }
    }

    private function ensureLocationColumns() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'location_label'");
            $existsLabel = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'latitude'");
            $existsLat = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'longitude'");
            $existsLng = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existsLabel) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN location_label VARCHAR(191) NULL AFTER connection_type");
            }
            if (!$existsLat) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_label");
            }
            if (!$existsLng) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; core auth/registration can continue without GPS.
        }
    }

    private function ensureCustomerProfileColumns() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'customer_type'");
            $existsCustomerType = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'company_name'");
            $existsCompanyName = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'contact_person_name'");
            $existsContactPersonName = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'company_registration_number'");
            $existsCompanyRegistrationNumber = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existsCustomerType) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN customer_type VARCHAR(20) NOT NULL DEFAULT 'individual' AFTER full_name");
            }
            if (!$existsCompanyName) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_name VARCHAR(191) NULL AFTER customer_type");
            }
            if (!$existsContactPersonName) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN contact_person_name VARCHAR(191) NULL AFTER company_name");
            }
            if (!$existsCompanyRegistrationNumber) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_registration_number VARCHAR(100) NULL AFTER contact_person_name");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; registration can continue without company profile fields.
        }
    }

    private function ensureTwoFactorColumns() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'two_factor_enabled'");
            $existsEnabled = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'two_factor_method'");
            $existsMethod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existsEnabled) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password");
            }
            if (!$existsMethod) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN two_factor_method VARCHAR(10) NOT NULL DEFAULT 'sms' AFTER two_factor_enabled");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; login/registration still works without 2FA settings.
        }
    }

    private function ensureThemePreferenceColumn() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'theme_preference'");
            $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$exists) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN theme_preference VARCHAR(10) NOT NULL DEFAULT 'system' AFTER two_factor_method");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; the API can still fall back to the default theme preference.
        }
    }

    private function ensureMeterNumberNullable() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'meter_number'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($col && isset($col['Null']) && strtoupper((string)$col['Null']) === 'NO') {
                $this->conn->exec("ALTER TABLE " . $this->table . " MODIFY meter_number VARCHAR(50) NULL");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; some installations may already have nullable meter numbers.
        }
    }

    private function ensureUsernameColumn() {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'username'");
            $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$exists) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN username VARCHAR(50) NULL AFTER account_number");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; existing installations may have manual customizations.
        }
    }

    private function ensureUsernameUniqueIndex() {
        try {
            $stmt = $this->conn->query("SHOW INDEX FROM " . $this->table . " WHERE Key_name = 'uniq_username'");
            $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$exists) {
                $this->conn->exec("ALTER TABLE " . $this->table . " ADD UNIQUE KEY uniq_username (username)");
            }
        } catch (\PDOException $e) {
            // Ignore schema errors; if duplicate usernames exist, validation will still prevent new duplicates.
        }
    }
}
?>
