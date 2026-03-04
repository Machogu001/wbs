<?php
class Auth {
    private $conn;
    private $table = "user_sessions";
    
    public function __construct($db) {
        session_start();
        $this->conn = $db;
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
        
        return isset($_SESSION['user_data']['role']) && 
               $_SESSION['user_data']['role'] == 'admin';
    }
    
    // Get current user ID
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    // Logout user
    public function logout() {
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
