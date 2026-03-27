<?php
class ActivityLog {
    private $conn;
    private $table = "activity_log";
    private $lookupTable = "activity_ip_lookup";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
        $this->ensureLookupTable();
        $this->purgeOlderThanDays(30);
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100) NULL,
            entity_id INT NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_user (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        try {
            $this->conn->exec($sql);
        } catch (\PDOException $e) {
            // Fallback for older MySQL that may not support JSON
            try {
                $sqlFallback = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                $this->conn->exec($sqlFallback);
            } catch (\PDOException $e2) {
                // If even this fails, silently ignore to avoid breaking the app
            }
        }
    }

    private function purgeOlderThanDays($days) {
        $days = (int)$days;
        if ($days <= 0) {
            return;
        }

        try {
            $sql = "DELETE FROM " . $this->table . " WHERE created_at < (NOW() - INTERVAL :days DAY)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Do not break main flow if cleanup fails
        }
    }

    private function ensureLookupTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS " . $this->lookupTable . " (
                ip_address VARCHAR(45) PRIMARY KEY,
                location_label VARCHAR(191) NULL,
                network_org VARCHAR(191) NULL,
                asn VARCHAR(64) NULL,
                checked_at DATETIME NOT NULL,
                INDEX idx_checked_at (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $this->conn->exec($sql);
        } catch (\PDOException $e) {
            // Optional cache table; ignore failures.
        }
    }

    public function log($userId, $action, $entityType = null, $entityId = null, $description = '', array $metadata = array()) {
        try {
            $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']
                ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
            $sapi = strtolower((string)php_sapi_name());

            if (empty($metadata['execution_context'])) {
                $metadata['execution_context'] = $sapi;
            }

            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
            if ($userAgent !== '' && empty($metadata['user_agent'])) {
                $metadata['user_agent'] = $userAgent;
            }
            if (empty($metadata['device_type'])) {
                $metadata['device_type'] = $this->detectDeviceType($userAgent);
            }
            if (empty($metadata['browser'])) {
                $metadata['browser'] = $this->detectBrowser($userAgent);
            }
            if (empty($metadata['os'])) {
                $metadata['os'] = $this->detectOs($userAgent);
            }
            if (empty($metadata['location'])) {
                $metadata['location'] = $this->resolveLocationLabel($ip);
            }

            // CLI/cron/system tasks do not have browser/IP details; label them clearly.
            if (($ip === null || trim((string)$ip) === '') && ($sapi === 'cli' || strpos($sapi, 'phpdbg') !== false)) {
                $metadata['device_type'] = 'Server Task';
                $metadata['browser'] = 'CLI';
                if (empty($metadata['os'])) {
                    $metadata['os'] = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : 'Server OS';
                }
                $metadata['location'] = 'Server (CLI)';
                if (empty($metadata['network_org'])) {
                    $metadata['network_org'] = 'Local Server';
                }
                if (empty($metadata['asn'])) {
                    $metadata['asn'] = 'N/A';
                }
            }

            if ($this->isPublicIp($ip)) {
                $network = $this->lookupPublicNetwork($ip);
                if (!empty($network['location']) && (
                    empty($metadata['location']) || $this->isGenericLocationLabel((string)$metadata['location'])
                )) {
                    $metadata['location'] = (string)$network['location'];
                }
                if (!empty($network['network_org']) && empty($metadata['network_org'])) {
                    $metadata['network_org'] = (string)$network['network_org'];
                }
                if (!empty($network['asn']) && empty($metadata['asn'])) {
                    $metadata['asn'] = (string)$network['asn'];
                }
            }

            $sql = "INSERT INTO " . $this->table . " (user_id, action, entity_type, entity_id, description, metadata, ip_address)
                    VALUES (:user_id, :action, :entity_type, :entity_id, :description, :metadata, :ip_address)";
            $stmt = $this->conn->prepare($sql);
            $json = !empty($metadata) ? json_encode($metadata) : null;
            $userIdParam = $userId !== null ? (int)$userId : null;

            $stmt->bindParam(':user_id', $userIdParam, $userIdParam === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':entity_type', $entityType);
            $stmt->bindParam(':entity_id', $entityId);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':metadata', $json);
            $stmt->bindParam(':ip_address', $ip);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Do not break the main flow if logging fails
        }
    }

    private function detectDeviceType($userAgent) {
        $ua = strtolower((string)$userAgent);
        if ($ua === '') {
            return 'Unknown';
        }
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'android') !== false) {
            return 'Mobile';
        }
        if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
            return 'Tablet';
        }
        if (strpos($ua, 'bot') !== false || strpos($ua, 'crawler') !== false || strpos($ua, 'spider') !== false) {
            return 'Bot';
        }
        return 'Desktop';
    }

    private function detectBrowser($userAgent) {
        $ua = strtolower((string)$userAgent);
        if ($ua === '') {
            return 'Unknown';
        }
        if (strpos($ua, 'edg/') !== false) return 'Edge';
        if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) return 'Opera';
        if (strpos($ua, 'chrome/') !== false && strpos($ua, 'edg/') === false) return 'Chrome';
        if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) return 'Safari';
        if (strpos($ua, 'firefox/') !== false) return 'Firefox';
        if (strpos($ua, 'msie') !== false || strpos($ua, 'trident/') !== false) return 'Internet Explorer';
        return 'Unknown';
    }

    private function detectOs($userAgent) {
        $ua = strtolower((string)$userAgent);
        if ($ua === '') {
            return 'Unknown';
        }
        if (strpos($ua, 'windows') !== false) return 'Windows';
        if (strpos($ua, 'android') !== false) return 'Android';
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'iOS';
        if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) return 'macOS';
        if (strpos($ua, 'linux') !== false) return 'Linux';
        return 'Unknown';
    }

    private function resolveLocationLabel($ip) {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return 'Server/Unknown Origin';
        }

        if (!$this->isPublicIp($ip)) {
            return 'Private/Local Network';
        }

        if (function_exists('geoip_country_name_by_name')) {
            $country = @geoip_country_name_by_name($ip);
            if (!empty($country)) {
                return (string)$country;
            }
        }

        return 'Public Network';
    }

    private function isPublicIp($ip) {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isGenericLocationLabel($label) {
        $normalized = strtolower(trim((string)$label));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, array(
            'public network',
            'private/local network',
            'server/unknown origin',
            'unknown',
        ), true);
    }

    private function lookupPublicNetwork($ip) {
        $ip = trim((string)$ip);
        if (!$this->isPublicIp($ip)) {
            return array();
        }

        $cached = $this->readLookupCache($ip);
        if (!empty($cached)) {
            return $cached;
        }

        $fresh = $this->fetchNetworkDetails($ip);
        if (!empty($fresh)) {
            $this->writeLookupCache($ip, $fresh);
            return $fresh;
        }

        return array();
    }

    private function readLookupCache($ip) {
        try {
            $stmt = $this->conn->prepare("SELECT location_label, network_org, asn, checked_at FROM " . $this->lookupTable . " WHERE ip_address = :ip LIMIT 1");
            $stmt->bindValue(':ip', $ip);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return array();
            }

            $checkedAt = isset($row['checked_at']) ? strtotime((string)$row['checked_at']) : false;
            if ($checkedAt !== false && $checkedAt >= (time() - (30 * 24 * 60 * 60))) {
                return array(
                    'location' => (string)($row['location_label'] ?? ''),
                    'network_org' => (string)($row['network_org'] ?? ''),
                    'asn' => (string)($row['asn'] ?? ''),
                );
            }

            // Return stale cache as fallback in case external lookup fails.
            return array(
                'location' => (string)($row['location_label'] ?? ''),
                'network_org' => (string)($row['network_org'] ?? ''),
                'asn' => (string)($row['asn'] ?? ''),
            );
        } catch (\Throwable $e) {
            return array();
        }
    }

    private function writeLookupCache($ip, array $data) {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO " . $this->lookupTable . " (ip_address, location_label, network_org, asn, checked_at)
                 VALUES (:ip, :location_label, :network_org, :asn, NOW())
                 ON DUPLICATE KEY UPDATE
                    location_label = VALUES(location_label),
                    network_org = VALUES(network_org),
                    asn = VALUES(asn),
                    checked_at = NOW()"
            );
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':location_label', (string)($data['location'] ?? 'Public Network'));
            $stmt->bindValue(':network_org', (string)($data['network_org'] ?? ''));
            $stmt->bindValue(':asn', (string)($data['asn'] ?? ''));
            $stmt->execute();
        } catch (\Throwable $e) {
            // Cache failures should not block activity logging.
        }
    }

    private function fetchNetworkDetails($ip) {
        $url = 'https://ipwho.is/' . rawurlencode($ip);
        $json = null;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $code >= 200 && $code < 300) {
                $json = $resp;
            }
        } elseif (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(array('http' => array('timeout' => 2)));
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                $json = $resp;
            }
        }

        if (!is_string($json) || trim($json) === '') {
            return array();
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || (isset($decoded['success']) && !$decoded['success'])) {
            return array();
        }

        $country = trim((string)($decoded['country'] ?? ''));
        $city = trim((string)($decoded['city'] ?? ''));
        $connection = isset($decoded['connection']) && is_array($decoded['connection']) ? $decoded['connection'] : array();
        $org = trim((string)($connection['org'] ?? ($connection['isp'] ?? '')));
        $asnRaw = (string)($connection['asn'] ?? '');
        $asn = $asnRaw !== '' ? (stripos($asnRaw, 'AS') === 0 ? $asnRaw : ('AS' . $asnRaw)) : '';

        $location = 'Public Network';
        if ($city !== '' && $country !== '') {
            $location = $city . ', ' . $country;
        } elseif ($country !== '') {
            $location = $country;
        }

        return array(
            'location' => $location,
            'network_org' => $org,
            'asn' => $asn,
        );
    }
}
?>
