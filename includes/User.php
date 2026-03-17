<?php
class User {
    private $conn;
    private $table = "users";
    
    public $id;
    public $account_number;
    public $full_name;
    public $phone_number;
    public $email;
    public $id_number;
    public $tax_pin;
    public $address;
    public $meter_number;
    public $connection_type;
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
        $this->ensureTwoFactorColumns();
    }
    
    // Create new user
    public function create() {
        $query = "INSERT INTO " . $this->table . "
                SET account_number = :account_number,
                    full_name = :full_name,
                    phone_number = :phone_number,
                    email = :email,
                    id_number = :id_number,
                    tax_pin = :tax_pin,
                    address = :address,
                    meter_number = :meter_number,
                    connection_type = :connection_type,
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
        $stmt->bindParam(":full_name", $this->full_name);
        $stmt->bindParam(":phone_number", $this->phone_number);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":id_number", $this->id_number);
        $stmt->bindParam(":tax_pin", $this->tax_pin);
        $stmt->bindParam(":address", $this->address);
        $stmt->bindParam(":meter_number", $this->meter_number);
        $stmt->bindParam(":connection_type", $this->connection_type);
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
            return true;
        }
        
        return false;
    }
    
    // Check if phone exists
    public function phoneExists($phone) {
        $query = "SELECT id FROM " . $this->table . " 
                 WHERE phone_number = :phone_number 
                 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":phone_number", $phone);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
        // Login user with account number, phone number, or email
        public function login($identifier, $password) {
         $query = "SELECT id, account_number, full_name, phone_number, 
                    email, id_number, tax_pin, address, meter_number,
                    connection_type, password_hash, role, status, must_change_password,
                    two_factor_enabled, two_factor_method
                FROM " . $this->table . " 
                WHERE status = 'active'
                  AND (phone_number = :identifier 
                    OR account_number = :identifier
                    OR email = :identifier)
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
          $query = "SELECT id, account_number, full_name, phone_number,
                            email, id_number, tax_pin, address, meter_number,
                            connection_type, password_hash, role, status, must_change_password,
                            two_factor_enabled, two_factor_method
                 FROM " . $this->table . "
                 WHERE phone_number = :identifier
                     OR account_number = :identifier
                     OR email = :identifier
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
        $query = "SELECT * FROM " . $this->table . " WHERE account_number = :account_number LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":account_number", $account_number);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get user by meter number
    public function getByMeterNumber($meter_number) {
        $query = "SELECT * FROM " . $this->table . " WHERE meter_number = :meter_number LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":meter_number", $meter_number);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Search by name or account/meter (supports partial matches for typeahead)
    public function searchByNameOrAccount($term, $limit = 10) {
        $like = '%' . $term . '%';
        $query = "SELECT * FROM " . $this->table . "
                  WHERE account_number LIKE :like
                     OR meter_number LIKE :like
                     OR full_name LIKE :like
                  ORDER BY full_name ASC
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":like", $like);
        $stmt->bindValue(":limit", (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listAll() {
        $query = "SELECT id, account_number, full_name, phone_number, meter_number, status FROM " . $this->table . " ORDER BY full_name ASC";
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
}
?>
