<?php
class Database {
    // Default values (used if no environment configuration is provided)
    // These are intentionally left blank so real values
    // are provided via .env (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
    private $host = '';
    private $db_name = '';
    private $username = '';
    private $password = '';
    private $conn;
    private static $envCache = null;

    public function __construct() {
        $this->loadEnvConfig();
    }

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

    public static function testConnection() {
        $db = new self();
        return $db->getConnection() !== null;
    }

    public static function env(string $name, $default = null) {
        $direct = getenv($name);
        if ($direct !== false && $direct !== null && $direct !== '') {
            return $direct;
        }

        $envValues = self::loadEnvValues();
        if (array_key_exists($name, $envValues) && $envValues[$name] !== '') {
            return $envValues[$name];
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }

        return $default;
    }

    private static function loadEnvValues(): array {
        if (self::$envCache !== null) {
            return self::$envCache;
        }

        $values = [];
        $projectRoot = dirname(__DIR__);
        $envFile = $projectRoot . '/.env';

        if (file_exists($envFile) && is_readable($envFile)) {
            $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }

                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $len = strlen($value);
                    if ($len >= 2) {
                        $firstChar = $value[0];
                        $lastChar = $value[$len - 1];
                        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                            $value = substr($value, 1, -1);
                        }
                    }

                    if ($key !== '') {
                        $values[$key] = $value;
                    }
                }
            }
        }

        self::$envCache = $values;
        return self::$envCache;
    }

    /**
     * Load database configuration from environment variables or .env file.
     * Falls back to the default values above if no env values are set.
     */
    private function loadEnvConfig(): void {
        $envValues = self::loadEnvValues();
        foreach ($envValues as $name => $value) {
            if (getenv($name) === false) {
                $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }

        // Now read configuration from env, falling back to defaults
        $host = getenv('DB_HOST');
        $dbName = getenv('DB_DATABASE') ?: getenv('DB_NAME');
        $username = getenv('DB_USERNAME');
        $password = getenv('DB_PASSWORD');

        if (!empty($host)) {
            $this->host = $host;
        }
        if (!empty($dbName)) {
            $this->db_name = $dbName;
        }
        if (!empty($username)) {
            $this->username = $username;
        }
        if ($password !== false && $password !== null) {
            // Allow empty string password if explicitly set
            $this->password = $password;
        }
    }
}
?>
