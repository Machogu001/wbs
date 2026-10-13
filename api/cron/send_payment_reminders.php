<?php
/**
 * Send automatic payment reminders once daily.
 *
 * Rules:
 * - due_tomorrow reminder: send once per bill
 * - overdue reminder: resend every 3 days while balance remains unpaid
 *
 * Recommended cron entry:
 * 0 8 * * * /usr/bin/php /var/www/wbs/api/cron/send_payment_reminders.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';

set_time_limit(900);

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log('Payment reminders: database connection failed');
    exit(1);
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS payment_reminder_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bill_id INT NOT NULL,
        reminder_type ENUM('due_tomorrow', 'overdue') NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bill_type_sent (bill_id, reminder_type, sent_at),
        INDEX idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $indexesStmt = $db->query("SHOW INDEX FROM payment_reminder_log WHERE Key_name = 'uniq_bill_reminder'");
        $legacyUniqueIndex = $indexesStmt ? $indexesStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($legacyUniqueIndex) {
            $db->exec("ALTER TABLE payment_reminder_log DROP INDEX uniq_bill_reminder");
        }
    } catch (Throwable $e) {
        // Keep reminder processing resilient on older installs.
    }

    try {
        $indexesStmt = $db->query("SHOW INDEX FROM payment_reminder_log WHERE Key_name = 'idx_bill_type_sent'");
        $modernIndex = $indexesStmt ? $indexesStmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$modernIndex) {
            $db->exec("ALTER TABLE payment_reminder_log ADD INDEX idx_bill_type_sent (bill_id, reminder_type, sent_at)");
        }
    } catch (Throwable $e) {
        // Keep reminder processing resilient on older installs.
    }

    // Keep bill status aligned with the due date before selecting overdue reminders.
    $db->exec("UPDATE bills
        SET status = 'overdue'
        WHERE status = 'pending' AND due_date < CURDATE()");

    $query = "SELECT b.id, b.user_id, b.billing_month, b.amount, b.due_date,
            u.full_name, u.phone_number, u.account_number,
            COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS paid_amount,
            CASE WHEN b.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'due_tomorrow' ELSE 'overdue' END AS reminder_type,
            due_log.last_sent_at AS due_tomorrow_last_sent_at,
            overdue_log.last_sent_at AS overdue_last_sent_at
        FROM bills b
        INNER JOIN users u ON u.id = b.user_id
        LEFT JOIN payments p ON p.bill_id = b.id
        LEFT JOIN (
            SELECT bill_id, MAX(sent_at) AS last_sent_at
            FROM payment_reminder_log
            WHERE reminder_type = 'due_tomorrow'
            GROUP BY bill_id
        ) due_log ON due_log.bill_id = b.id
        LEFT JOIN (
            SELECT bill_id, MAX(sent_at) AS last_sent_at
            FROM payment_reminder_log
            WHERE reminder_type = 'overdue'
            GROUP BY bill_id
        ) overdue_log ON overdue_log.bill_id = b.id
        WHERE b.status IN ('pending', 'overdue')
            AND (b.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) OR b.due_date < CURDATE())
            AND u.phone_number IS NOT NULL
            AND u.phone_number <> ''
        GROUP BY b.id, b.user_id, b.billing_month, b.amount, b.due_date,
            u.full_name, u.phone_number, u.account_number, due_log.last_sent_at, overdue_log.last_sent_at
        HAVING b.amount - paid_amount > 0.01
            AND (
                (reminder_type = 'due_tomorrow' AND due_tomorrow_last_sent_at IS NULL)
                OR
                (reminder_type = 'overdue' AND (overdue_last_sent_at IS NULL OR overdue_last_sent_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)))
            )
        ORDER BY b.due_date ASC, b.id ASC";

    $stmt = $db->query($query);
    $bills = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $sms = new SMS($db);
    $sent = 0;

    foreach ($bills as $bill) {
        $reminderType = (string)$bill['reminder_type'];
        $clientName = !empty($bill['full_name']) ? (string)$bill['full_name'] : 'Customer';
        $billingMonth = date('M Y', strtotime((string)$bill['billing_month']));
        $amountDue = number_format(max(0, (float)$bill['amount'] - (float)$bill['paid_amount']), 2);
        $dueDate = date('d/m/Y', strtotime((string)$bill['due_date']));
        $paymentLink = PaymentLink::generateLink((int)$bill['id']);

        if ($reminderType === 'due_tomorrow') {
            $message = "Dear {$clientName}, your {$billingMonth} water bill for Account {$bill['account_number']} amounting to KES {$amountDue} is due tomorrow ({$dueDate}). Kindly pay on time to avoid service interruption. Pay here: {$paymentLink}";
        } else {
            $message = "Dear {$clientName}, your {$billingMonth} water bill for Account {$bill['account_number']} amounting to KES {$amountDue} was due on {$dueDate} and is now overdue. Kindly settle it to avoid service interruption. Pay here: {$paymentLink}";
        }

        try {
            $result = $sms->sendWithFallback($bill['phone_number'], $message, 'payment_reminder');
            if (!empty($result['success']) || !empty($result['queued'])) {
                $log = $db->prepare('INSERT INTO payment_reminder_log (bill_id, reminder_type) VALUES (?, ?)');
                $log->execute([(int)$bill['id'], $reminderType]);
                $sent++;
            }
        } catch (Throwable $e) {
            error_log('Payment reminder failed for bill #' . (int)$bill['id'] . ': ' . $e->getMessage());
        }
    }

    if ($sent > 0) {
        error_log("Payment reminders: sent or queued {$sent} reminder(s) at " . date('Y-m-d H:i:s'));
    }
    exit(0);
} catch (Throwable $e) {
    error_log('Payment reminders error: ' . $e->getMessage());
    exit(1);
}
