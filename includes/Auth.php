<?php
class Auth {
    private $conn;
    private $table = "user_sessions";
    private static $permissionCache = [];
    
    public function __construct($db) {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        // Ensure a session-level CSRF token exists for form protection
        if (empty($_SESSION['app_csrf_token'])) {
            $_SESSION['app_csrf_token'] = bin2hex(random_bytes(32));
        }
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

        // Refresh role from DB once per request so admin role changes take effect immediately.
        // Using a static variable (not session) so it resets every request.
        static $roleRefreshed = false;
        if (!$roleRefreshed && $this->conn) {
            $roleRefreshed = true;
            try {
                $stmt = $this->conn->prepare("SELECT role, status FROM users WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $newRole = strtolower((string)($row['role'] ?? 'customer'));
                    $oldRole = strtolower((string)($_SESSION['user_data']['role'] ?? ''));
                    if ($newRole !== $oldRole) {
                        $_SESSION['user_data']['role'] = $newRole;
                        // Clear cached permissions so the new role's permissions load fresh
                        self::$permissionCache = [];
                    }
                    // Force logout if account was deactivated
                    if ((string)($row['status'] ?? '') === 'inactive') {
                        $this->logout();
                        return false;
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal: continue with session-cached role
            }
        }

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
    // Permissions are loaded from the role_permissions DB table (per-request cached).
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin (superuser) can do everything
        if ($this->hasRole('admin')) {
            return true;
        }

        $permission = (string)$permission;
        $role = strtolower((string)$this->getRole());

        // Load DB-driven permissions (per-request cache)
        $dbPerms = $this->loadRolePermissionsFromDb($role);
        if ($dbPerms !== null) {
            return in_array($permission, $dbPerms, true);
        }

        // Fallback hardcoded defaults (used when role_permissions table is not yet created)
        $map = [
            'customer' => ['view_own_bills', 'submit_own_reading'],
            'reader'   => ['view_invoicing', 'send_messages'],
            'finance'  => ['view_customers', 'view_accounting', 'view_reports', 'view_payments', 'view_invoicing', 'view_bill_detail', 'manage_demand_notices', 'manage_approvals', 'send_messages'],
            'support'  => ['handle_support', 'view_customers', 'view_bill_detail', 'send_messages'],
        ];

        if (!isset($map[$role])) {
            return false;
        }

        return in_array($permission, $map[$role], true);
    }

    // Load permissions for a given role from the DB.
    // Returns an array of permission strings on success, or null if table unavailable.
    private function loadRolePermissionsFromDb(string $role): ?array {
        if (array_key_exists($role, self::$permissionCache)) {
            return self::$permissionCache[$role];
        }

        if (!$this->conn) {
            return null;
        }

        try {
            $stmt = $this->conn->prepare("SELECT permission FROM role_permissions WHERE role = :role");
            $stmt->bindParam(':role', $role);
            $stmt->execute();
            self::$permissionCache[$role] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            // Table may not exist on older installs; return null to trigger fallback
            return null;
        }

        return self::$permissionCache[$role];
    }

    // Clear the per-request permission cache.
    // Call after saving role permissions so subsequent checks reflect the change.
    public static function clearPermissionCache(): void {
        self::$permissionCache = [];
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
