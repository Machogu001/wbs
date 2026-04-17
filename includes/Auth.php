<?php
class Auth {
    private $conn;
    private $table = "user_sessions";
    
    public function __construct($db) {
        session_start();
        $this->conn = $db;
    }

    // Return current authenticated user data (or null if not logged in)
    // This is a lightweight helper primarily for API endpoints.
    public function check() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        // Prefer the richer user_data payload if available
        if (isset($_SESSION['user_data']) && is_array($_SESSION['user_data'])) {
            return $_SESSION['user_data'];
        }

        // Fallback: build a minimal user array from the ID
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if (!$userId) {
            return null;
        }

        return ['id' => $userId];
    }
    
    // Login user
    public function login($user_id, $user_data) {
        // Prevent session fixation by regenerating the session ID on login
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_data'] = $user_data;
        $_SESSION['last_activity'] = time();
        
        // Create session record in database
        $session_token = bin2hex(random_bytes(32));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $query = "INSERT INTO " . $this->table . "
                SET user_id = :user_id,
                    session_token = :session_token,
                    ip_address = :ip_address,
                    user_agent = :user_agent,
                    expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":session_token", $session_token);
        $stmt->bindParam(":ip_address", $ip_address);
        $stmt->bindParam(":user_agent", $user_agent);
        
        if($stmt->execute()) {
            $_SESSION['session_token'] = $session_token;
        }
        
        return true;
    }
    
    // Check if user is logged in
    public function isLoggedIn() {
        if(!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity'])) {
            return false;
        }
        
        // Check session timeout (1 hour)
        if(time() - $_SESSION['last_activity'] > 3600) {
            $this->logout();
            return false;
        }
        
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    // Check if user is admin
    public function isAdmin() {
        if(!$this->isLoggedIn()) {
            return false;
        }
        
        return $this->hasRole('admin');
    }

    // Get current user's primary role (defaults to 'customer' if not set)
    public function getRole() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $role = $_SESSION['user_data']['role'] ?? null;
        if (!is_string($role) || $role === '') {
            return 'customer';
        }
        return strtolower($role);
    }

    // Check if the current user has one of the given roles
    // $role can be a string role name or an array of names
    public function hasRole($role) {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $current = strtolower((string)$this->getRole());
        if (is_array($role)) {
            foreach ($role as $r) {
                if ($current === strtolower((string)$r)) {
                    return true;
                }
            }
            return false;
        }

        return $current === strtolower((string)$role);
    }

    // Check if the current user has a named permission.
    // Admin users implicitly have all permissions.
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin (superuser) can do everything
        if ($this->hasRole('admin')) {
            return true;
        }

        $permission = (string)$permission;

        // Default role-to-permissions mapping.
        // This can be expanded as more granular checks are needed.
        $map = [
            'customer' => [
                'view_own_bills',
                'submit_own_reading',
            ],
            'reader' => [
                'submit_reading',
            ],
            'finance' => [
                'view_payments',
                'view_reports',
                'manage_settings',
            ],
            'support' => [
                'handle_complaints',
            ],
        ];

        $role = strtolower((string)$this->getRole());
        if (!isset($map[$role])) {
            return false;
        }

        return in_array($permission, $map[$role], true);
    }
    
    // Get current user ID
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    // Logout user
    public function logout() {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        if ($userId > 0) {
            try {
                $stmt = $this->conn->prepare("UPDATE support_agent_availability SET is_available = 0, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id");
                $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();
            } catch (\Throwable $e) {
                // Keep logout working even when chat availability storage is unavailable.
            }
        }

        if(isset($_SESSION['session_token'])) {
            // Delete session from database
            $query = "DELETE FROM " . $this->table . " 
                     WHERE session_token = :session_token";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":session_token", $_SESSION['session_token']);
            $stmt->execute();
        }
        
        // Clear session data
        $_SESSION = array();
        
        // Destroy session cookie
        if(ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
}
?>
