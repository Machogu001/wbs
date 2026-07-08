<?php
/**
 * ClientWallet — prepayment credit balance for customers.
 *
 * Excess manual payments are stored here and automatically applied
 * to the next bill raised for the same account.
 *
 * Tables created on first instantiation:
 *   customer_wallet              — one row per user, stores current balance
 *   customer_wallet_transactions — full debit/credit ledger
 */
class ClientWallet
{
    private $db;
    private static bool $schemaEnsured = false;

    public function __construct($db)
    {
        $this->db = $db;
        if (!self::$schemaEnsured && !$this->db->inTransaction()) {
            $this->ensureTables();
            self::$schemaEnsured = true;
        }
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    private function ensureTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS customer_wallet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS customer_wallet_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type ENUM('credit','debit') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reference VARCHAR(100) NULL,
            bill_id INT NULL,
            payment_id INT NULL,
            note TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_bill (bill_id),
            INDEX idx_payment (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // -------------------------------------------------------------------------
    // Balance query
    // -------------------------------------------------------------------------

    /**
     * Return current wallet balance for a user (0 if no record yet).
     */
    public function getBalance(int $userId): float
    {
        if ($userId <= 0) {
            return 0.0;
        }
        $stmt = $this->db->prepare('SELECT balance FROM customer_wallet WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return round((float)($stmt->fetchColumn() ?: 0), 2);
    }

    /**
     * Return recent wallet transactions for display.
     */
    public function getTransactions(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            'SELECT * FROM customer_wallet_transactions
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Mutations  (always call inside existing transaction or start own)
    // -------------------------------------------------------------------------

    /**
     * Add funds to the wallet (e.g. from an excess manual payment).
     *
     * @param int    $userId
     * @param float  $amount      Must be > 0
     * @param string $reference   M-Pesa ref / receipt number
     * @param int    $paymentId   Related payments.id if known
     * @param string $note        Free-text note for display
     * @param int    $createdBy   Admin user id who triggered this
     * @return float New balance
     */
    public function addCredit(
        int $userId,
        float $amount,
        string $reference = '',
        int $paymentId = 0,
        string $note = '',
        int $createdBy = 0
    ): float {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet credit amount must be > 0.');
        }

        // Upsert wallet row
        $this->db->prepare(
            'INSERT INTO customer_wallet (user_id, balance) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE balance = balance + ?, updated_at = NOW()'
        )->execute([$userId, $amount, $amount]);

        $newBalance = $this->getBalance($userId);

        $this->db->prepare(
            'INSERT INTO customer_wallet_transactions
             (user_id, type, amount, balance_after, reference, payment_id, note, created_by)
             VALUES (?, "credit", ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $amount,
            $newBalance,
            $reference !== '' ? $reference : null,
            $paymentId > 0 ? $paymentId : null,
            $note !== '' ? $note : null,
            $createdBy > 0 ? $createdBy : null,
        ]);

        return $newBalance;
    }

    /**
     * Deduct funds from the wallet (e.g. when applied to a bill).
     * Returns the amount actually deducted (may be less than requested if
     * balance is insufficient).
     *
     * @return float Amount deducted
     */
    public function applyTowardsBill(
        int $userId,
        int $billId,
        float $requestedAmount,
        string $note = '',
        int $createdBy = 0
    ): float {
        $balance = $this->getBalance($userId);
        if ($balance <= 0) {
            return 0.0;
        }

        $deduct = round(min($requestedAmount, $balance), 2);
        if ($deduct <= 0) {
            return 0.0;
        }

        $this->db->prepare(
            'UPDATE customer_wallet SET balance = balance - ?, updated_at = NOW() WHERE user_id = ?'
        )->execute([$deduct, $userId]);

        $newBalance = $this->getBalance($userId);

        $this->db->prepare(
            'INSERT INTO customer_wallet_transactions
             (user_id, type, amount, balance_after, bill_id, note, created_by)
             VALUES (?, "debit", ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $deduct,
            $newBalance,
            $billId > 0 ? $billId : null,
            $note !== '' ? $note : null,
            $createdBy > 0 ? $createdBy : null,
        ]);

        return $deduct;
    }
}
