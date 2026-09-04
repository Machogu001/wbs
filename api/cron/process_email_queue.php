<?php
/**
 * Email queue processor.
 * Run every minute:
 * * * * * /usr/bin/php /var/www/wbs/api/cron/process_email_queue.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/EmailQueue.php';

set_time_limit(900);

try {
    $database = new Database();
    $db = $database->getConnection();
    if ($db === null) {
        throw new RuntimeException('Database connection failed');
    }

    $queue = new EmailQueue($db);
    $email = new Email();
    $processed = 0;

    foreach ($queue->getPending(100) as $item) {
        $result = $email->send(
            (string)$item['recipient_email'],
            (string)$item['subject'],
            (string)$item['body']
        );
        if (!empty($result['success'])) {
            $queue->markSent((int)$item['id']);
        } else {
            $queue->markFailed((int)$item['id'], (string)($result['message'] ?? 'Email delivery failed'));
        }
        $processed++;
    }

    if ($processed > 0) {
        error_log("Email queue: processed {$processed} message(s) at " . date('Y-m-d H:i:s'));
    }
    exit(0);
} catch (Throwable $e) {
    error_log('Email queue error: ' . $e->getMessage());
    exit(1);
}
