<?php
/**
 * SMS Queue Processor - Cron Job
 * Set up a cron job to run this script every minute:
 * * * * * /usr/bin/php /var/www/wbs/api/cron/process_sms_queue.php
 * /usr/bin/php /var/www/wbs/api/cron/process_sms_queue.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/SMS.php';

// Prevent timeout for cron job
set_time_limit(900);

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log('SMS Queue: Database connection failed');
    exit(1);
}

$sms = new SMS();

try {
    $processed = 0;
    $batchSize = 250;
    $maxBatches = 4;

    for ($batchIndex = 0; $batchIndex < $maxBatches; $batchIndex++) {
        $batchProcessed = $sms->processPendingQueue($batchSize);
        $processed += $batchProcessed;

        if ($batchProcessed < $batchSize) {
            break;
        }
    }
    
    if ($processed > 0) {
        error_log("SMS Queue: Processed $processed messages at " . date('Y-m-d H:i:s'));
    }
    
    exit(0);
} catch (Exception $e) {
    error_log('SMS Queue Error: ' . $e->getMessage());
    exit(1);
}

?>
