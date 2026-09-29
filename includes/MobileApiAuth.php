<?php

require_once __DIR__ . '/SMS.php';
require_once __DIR__ . '/Email.php';

class MobileApiAuth
{
    private $conn;
    private $tokenTable = 'mobile_api_tokens';
    private $challengeTable = 'mobile_api_login_challenges';

    public function __construct(PDO $db)
    {
        $this->conn = $db;
        $this->ensureTokenTable();
        $this->ensureChallengeTable();
    }

    public function issueAccessToken(int $userId, ?string $deviceName = null, int $ttlSeconds = 2592000): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user for access token issuance.');
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 12);
        $device = $deviceName !== null && trim($deviceName) !== '' ? trim($deviceName) : null;
        $expiresAt = date('Y-m-d H:i:s', time() + max(3600, $ttlSeconds));

        $stmt = $this->conn->prepare(
            'INSERT INTO ' . $this->tokenTable . ' (user_id, token_hash, token_prefix, device_name, last_used_at, expires_at)
             VALUES (:user_id, :token_hash, :token_prefix, :device_name, NOW(), :expires_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':token_prefix' => $tokenPrefix,
            ':device_name' => $device,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'expires_in' => max(3600, $ttlSeconds),
            'device_name' => $device,
        ];
    }

    public function authenticate(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken);
        $stmt = $this->conn->prepare(
            'SELECT t.id AS token_id, t.user_id AS token_user_id, t.device_name, t.last_used_at, t.expires_at,
                    u.*
             FROM ' . $this->tokenTable . ' t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at >= NOW())
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $this->touchToken((int)$row['token_id']);
        return $row;
    }

    public function revokeAccessToken(string $plainToken): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return false;
        }

        $stmt = $this->conn->prepare(
            'UPDATE ' . $this->tokenTable . ' SET revoked_at = NOW() WHERE token_hash = :token_hash AND revoked_at IS NULL'
        );
        $stmt->execute([':token_hash' => hash('sha256', $plainToken)]);
        return $stmt->rowCount() > 0;
    }

    public function startTwoFactorChallenge(array $userRow, string $identifier, string $clientIp): array
    {
        $userId = (int)($userRow['id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user for verification challenge.');
        }

        $availableMethods = $this->getAvailableMethods($userRow);
        if (empty($availableMethods)) {
            throw new RuntimeException('Two-step verification is enabled, but no delivery method is configured.');
        }

        $preferredMethod = isset($userRow['two_factor_method']) ? strtolower((string)$userRow['two_factor_method']) : 'sms';
        if (!in_array($preferredMethod, ['sms', 'email'], true)) {
            $preferredMethod = 'sms';
        }

        $code = (string)random_int(100000, 999999);
        $sendResult = $this->deliverTwoFactorCode($userRow, $preferredMethod, $code, $availableMethods);
        if (empty($sendResult['success'])) {
            throw new RuntimeException((string)($sendResult['message'] ?? 'Failed to send verification code.'));
        }

        $challengeToken = bin2hex(random_bytes(24));
        $codeHash = hash('sha256', $code);

        $this->conn->prepare(
            'UPDATE ' . $this->challengeTable . '
             SET consumed_at = NOW()
             WHERE user_id = :user_id AND consumed_at IS NULL'
        )->execute([':user_id' => $userId]);

        $stmt = $this->conn->prepare(
            'INSERT INTO ' . $this->challengeTable . ' (
                user_id, challenge_token, code_hash, method, identifier, ip_address, available_methods_json,
                attempts, expires_at, last_sent_at
             ) VALUES (
                :user_id, :challenge_token, :code_hash, :method, :identifier, :ip_address, :available_methods_json,
                0, :expires_at, NOW()
             )'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':challenge_token' => $challengeToken,
            ':code_hash' => $codeHash,
            ':method' => $sendResult['method'],
            ':identifier' => $identifier,
            ':ip_address' => $clientIp,
            ':available_methods_json' => json_encode($availableMethods),
            ':expires_at' => date('Y-m-d H:i:s', time() + 300),
        ]);

        return [
            'challenge_token' => $challengeToken,
            'method' => $sendResult['method'],
            'available_methods' => $availableMethods,
            'expires_in' => 300,
        ];
    }

    public function verifyTwoFactorChallenge(string $challengeToken, string $code): array
    {
        $challenge = $this->getChallengeRow($challengeToken, false);
        if (!$challenge) {
            throw new RuntimeException('No active verification challenge was found. Please log in again.');
        }

        $expiresAt = strtotime((string)$challenge['expires_at']);
        if ($expiresAt !== false && time() > $expiresAt) {
            throw new RuntimeException('The verification code has expired. Please request a new code.');
        }

        $attempts = (int)($challenge['attempts'] ?? 0);
        if ($attempts >= 5) {
            $this->markChallengeConsumed((int)$challenge['id']);
            throw new RuntimeException('Too many incorrect codes. Please log in again.');
        }

        $codeHash = hash('sha256', trim($code));
        if (!hash_equals((string)$challenge['code_hash'], $codeHash)) {
            $stmt = $this->conn->prepare('UPDATE ' . $this->challengeTable . ' SET attempts = attempts + 1 WHERE id = :id');
            $stmt->execute([':id' => (int)$challenge['id']]);
            throw new RuntimeException('Invalid verification code.');
        }

        $stmt = $this->conn->prepare(
            'UPDATE ' . $this->challengeTable . '
             SET verified_at = NOW(), consumed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => (int)$challenge['id']]);

        return $challenge;
    }

    public function resendTwoFactorChallenge(string $challengeToken, ?string $preferredMethod = null): array
    {
        $challenge = $this->getChallengeRow($challengeToken, true);
        if (!$challenge) {
            throw new RuntimeException('No verification challenge was found. Please log in again.');
        }

        if (!empty($challenge['consumed_at']) && empty($challenge['verified_at'])) {
            throw new RuntimeException('This verification challenge is no longer active. Please log in again.');
        }

        $lastSentAt = strtotime((string)($challenge['last_sent_at'] ?? ''));
        if ($lastSentAt !== false) {
            $secondsSinceLastSend = time() - $lastSentAt;
            if ($secondsSinceLastSend < 30) {
                throw new RuntimeException('Please wait ' . (30 - $secondsSinceLastSend) . ' seconds before requesting another code.');
            }
        }

        $userRow = $this->getUserById((int)$challenge['user_id']);
        if (!$userRow) {
            throw new RuntimeException('User not found. Please log in again.');
        }

        $availableMethods = $this->getAvailableMethods($userRow);
        if (empty($availableMethods)) {
            throw new RuntimeException('No verification delivery method is available.');
        }

        $requestedMethod = $preferredMethod !== null ? strtolower(trim($preferredMethod)) : '';
        $method = $requestedMethod !== '' ? $requestedMethod : (string)($challenge['method'] ?? 'sms');
        if (!in_array($method, ['sms', 'email'], true)) {
            $method = 'sms';
        }

        $code = (string)random_int(100000, 999999);
        $sendResult = $this->deliverTwoFactorCode($userRow, $method, $code, $availableMethods);
        if (empty($sendResult['success'])) {
            throw new RuntimeException((string)($sendResult['message'] ?? 'Failed to resend verification code.'));
        }

        $stmt = $this->conn->prepare(
            'UPDATE ' . $this->challengeTable . '
             SET code_hash = :code_hash,
                 method = :method,
                 available_methods_json = :available_methods_json,
                 attempts = 0,
                 expires_at = :expires_at,
                 last_sent_at = NOW(),
                 consumed_at = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':code_hash' => hash('sha256', $code),
            ':method' => $sendResult['method'],
            ':available_methods_json' => json_encode($availableMethods),
            ':expires_at' => date('Y-m-d H:i:s', time() + 300),
            ':id' => (int)$challenge['id'],
        ]);

        return [
            'challenge_token' => $challengeToken,
            'method' => $sendResult['method'],
            'available_methods' => $availableMethods,
            'expires_in' => 300,
        ];
    }

    private function touchToken(int $tokenId): void
    {
        if ($tokenId <= 0) {
            return;
        }

        $stmt = $this->conn->prepare(
            'UPDATE ' . $this->tokenTable . '
             SET last_used_at = NOW()
             WHERE id = :id
               AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))'
        );
        $stmt->execute([':id' => $tokenId]);
    }

    private function getChallengeRow(string $challengeToken, bool $includeConsumed): ?array
    {
        $challengeToken = trim($challengeToken);
        if ($challengeToken === '') {
            return null;
        }

        $sql = 'SELECT c.*, u.*
            FROM ' . $this->challengeTable . ' c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.challenge_token = :challenge_token';

        if (!$includeConsumed) {
            $sql .= ' AND c.consumed_at IS NULL';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':challenge_token' => $challengeToken]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function markChallengeConsumed(int $challengeId): void
    {
        if ($challengeId <= 0) {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE ' . $this->challengeTable . ' SET consumed_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $challengeId]);
    }

    private function getUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getAvailableMethods(array $userRow): array
    {
        $available = [];
        $phone = trim((string)($userRow['phone_number'] ?? ''));
        $email = trim((string)($userRow['email'] ?? ''));

        if ($phone !== '') {
            $available[] = 'sms';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $available[] = 'email';
        }

        return $available;
    }

    private function deliverTwoFactorCode(array $userRow, string $preferredMethod, string $code, array $availableMethods): array
    {
        $method = $preferredMethod;
        if (!in_array($method, $availableMethods, true)) {
            $method = $availableMethods[0];
        }

        $appName = getenv('APP_NAME') ?: 'Water Billing System';
        $otpDomain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? (getenv('APP_DOMAIN') ?: ''));
        $otpSuffix = $otpDomain !== '' ? "\n\n@{$otpDomain} #{$code}" : '';
        $messageText = "{$code} is your {$appName} mobile API verification code. It expires in 5 minutes.{$otpSuffix}";

        $attemptedMethods = [$method];
        $lastError = '';

        $sendByMethod = function (string $deliveryMethod) use ($userRow, $messageText, &$lastError): bool {
            if ($deliveryMethod === 'sms') {
                $sms = new SMS($this->conn);
                $result = $sms->sendWithFallback((string)$userRow['phone_number'], $messageText, 'mobile_api_login_otp');
                $deliveryMode = (string)($result['delivery_mode'] ?? 'failed');
                if ($deliveryMode === 'immediate') {
                    return true;
                }

                $lastError = (string)($result['message'] ?? 'SMS send failed');
                return false;
            }

            $email = new Email();
            $result = $email->send((string)$userRow['email'], 'Your mobile app verification code', $messageText);
            if (!empty($result['success'])) {
                return true;
            }

            $lastError = (string)($result['message'] ?? 'Email send failed');
            return false;
        };

        if ($sendByMethod($method)) {
            return ['success' => true, 'method' => $method];
        }

        foreach ($availableMethods as $alternateMethod) {
            if ($alternateMethod === $method) {
                continue;
            }

            $attemptedMethods[] = $alternateMethod;
            if ($sendByMethod($alternateMethod)) {
                return ['success' => true, 'method' => $alternateMethod];
            }
        }

        return [
            'success' => false,
            'message' => 'Failed to send verification code via ' . implode(' then ', $attemptedMethods) . ': ' . $lastError,
        ];
    }

    private function ensureTokenTable(): void
    {
        $this->conn->exec('CREATE TABLE IF NOT EXISTS ' . $this->tokenTable . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_prefix VARCHAR(24) NOT NULL,
            device_name VARCHAR(120) NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mobile_api_token_hash (token_hash),
            KEY idx_mobile_api_token_user (user_id),
            KEY idx_mobile_api_token_expiry (expires_at),
            KEY idx_mobile_api_token_revoked (revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function ensureChallengeTable(): void
    {
        $this->conn->exec('CREATE TABLE IF NOT EXISTS ' . $this->challengeTable . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            challenge_token VARCHAR(96) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            method ENUM("sms", "email") NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            available_methods_json TEXT NULL,
            attempts INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            last_sent_at DATETIME NULL,
            verified_at DATETIME NULL,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mobile_api_challenge_token (challenge_token),
            KEY idx_mobile_api_challenge_user (user_id),
            KEY idx_mobile_api_challenge_expiry (expires_at),
            KEY idx_mobile_api_challenge_consumed (consumed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}