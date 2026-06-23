<?php

class ShortUrl
{
    private $db;
    private const BASE_URL = 'https://wbs.bremac.co.ke';
    private const SHORT_CODE_LENGTH = 7;
    private const CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct($db)
    {
        $this->db = $db;
        $this->createTable();
    }

    /**
     * Create short_urls table if it doesn't exist
     */
    private function createTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS short_urls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            short_code VARCHAR(10) UNIQUE NOT NULL,
            full_url LONGTEXT NOT NULL,
            bill_id INT,
            clicks INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            INDEX idx_short_code (short_code),
            INDEX idx_bill_id (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        try {
            $this->db->exec($sql);
        } catch (Exception $e) {
            error_log('Short URL table creation error: ' . $e->getMessage());
        }
    }

    /**
     * Generate a short URL for a given full URL
     * @param string $fullUrl The full URL to shorten
     * @param int $billId Optional bill ID for tracking
     * @return string Short URL like https://wbs.bremac.co.ke/s/abc123
     */
    public function shortenUrl(string $fullUrl, int $billId = null): string
    {
        try {
            // Check if this URL already exists for this bill
            if ($billId !== null && $billId > 0) {
                $stmt = $this->db->prepare('SELECT short_code FROM short_urls WHERE bill_id = ? AND full_url = ? LIMIT 1');
                $stmt->execute([$billId, $fullUrl]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    return rtrim(self::BASE_URL, '/') . '/s/' . $result['short_code'];
                }
            }

            // Generate unique short code
            $shortCode = $this->generateUniqueShortCode();

            // Insert into database
            $stmt = $this->db->prepare(
                'INSERT INTO short_urls (short_code, full_url, bill_id) VALUES (?, ?, ?)'
            );
            $stmt->execute([$shortCode, $fullUrl, $billId]);

            return rtrim(self::BASE_URL, '/') . '/s/' . $shortCode;
        } catch (Exception $e) {
            error_log('Short URL generation error: ' . $e->getMessage());
            // Fallback to original URL if shortening fails
            return $fullUrl;
        }
    }

    /**
     * Get the full URL from a short code
     * @param string $shortCode The short code
     * @return string|null The full URL or null if not found
     */
    public function getFullUrl(string $shortCode): ?string
    {
        try {
            $stmt = $this->db->prepare('SELECT full_url FROM short_urls WHERE short_code = ? LIMIT 1');
            $stmt->execute([$shortCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                // Increment click counter
                $this->incrementClicks($shortCode);
                return $result['full_url'];
            }

            return null;
        } catch (Exception $e) {
            error_log('Short URL retrieval error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Increment click counter for analytics
     * @param string $shortCode The short code
     */
    private function incrementClicks(string $shortCode): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE short_urls SET clicks = clicks + 1 WHERE short_code = ?');
            $stmt->execute([$shortCode]);
        } catch (Exception $e) {
            error_log('Click counter update error: ' . $e->getMessage());
        }
    }

    /**
     * Generate a unique short code
     * @return string A unique short code
     */
    private function generateUniqueShortCode(): string
    {
        $maxAttempts = 10;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $shortCode = $this->generateRandomCode();

            // Check if it already exists
            $stmt = $this->db->prepare('SELECT id FROM short_urls WHERE short_code = ? LIMIT 1');
            $stmt->execute([$shortCode]);

            if (!$stmt->fetch()) {
                return $shortCode;
            }

            $attempt++;
        }

        // If we've tried 10 times, append timestamp for uniqueness
        return $this->generateRandomCode() . substr(time(), -2);
    }

    /**
     * Generate a random short code
     * @return string A random code
     */
    private function generateRandomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::SHORT_CODE_LENGTH; $i++) {
            $code .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
        }
        return $code;
    }

    /**
     * Get analytics for a short URL
     * @param string $shortCode The short code
     * @return array|null Array with url info or null if not found
     */
    public function getAnalytics(string $shortCode): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT short_code, full_url, bill_id, clicks, created_at FROM short_urls WHERE short_code = ? LIMIT 1');
            $stmt->execute([$shortCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Analytics retrieval error: ' . $e->getMessage());
            return null;
        }
    }
}
