<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../../includes/InstallmentPlan.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

function mobileApiRunReportsMaintenanceCommand(string $scriptPath, array $args = []): array
{
    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $commandParts = [escapeshellarg($phpBinary), escapeshellarg($scriptPath)];
    foreach ($args as $arg) {
        $commandParts[] = escapeshellarg($arg);
    }
    $command = implode(' ', $commandParts) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    return ['command' => $command, 'output' => $output, 'exit_code' => $exitCode, 'succeeded' => $exitCode === 0];
}

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_reports']);
    new FinanceApproval($db);
    new InstallmentPlan($db);
    $settings = (new BillingSettings($db))->getSettings();
    $currency = !empty($settings['currency_code']) ? (string)$settings['currency_code'] : 'KES';
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $period = trim((string)($_GET['period'] ?? 'this_month'));
        $fromDate = trim((string)($_GET['from_date'] ?? ''));
        $toDate = trim((string)($_GET['to_date'] ?? ''));
        $today = new DateTime('today');
        switch ($period) {
            case 'today':
                $from = clone $today;
                $to = clone $today;
                break;
            case 'this_year':
                $from = new DateTime(date('Y-01-01'));
                $to = new DateTime(date('Y-12-31'));
                break;
            case 'custom':
                $from = $fromDate !== '' ? new DateTime($fromDate) : new DateTime(date('Y-m-01'));
                $to = $toDate !== '' ? new DateTime($toDate) : clone $today;
                break;
            default:
                $from = new DateTime(date('Y-m-01'));
                $to = clone $today;
                break;
        }
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $paymentsStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'completed' AND DATE(COALESCE(transaction_date, created_at)) BETWEEN :from AND :to");
        $paymentsStmt->execute([':from' => $fromStr, ':to' => $toStr]);
        $completedPaymentsTotal = (float)$paymentsStmt->fetchColumn();

        $billsStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM bills WHERE DATE(created_at) BETWEEN :from AND :to");
        $billsStmt->execute([':from' => $fromStr, ':to' => $toStr]);
        $billedTotal = (float)$billsStmt->fetchColumn();

        $recentPaymentsStmt = $db->prepare("SELECT p.id, p.amount, p.status, p.mpesa_receipt, COALESCE(p.transaction_date, p.created_at) AS tx_date, u.account_number, u.full_name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to ORDER BY COALESCE(p.transaction_date, p.created_at) DESC LIMIT 100");
        $recentPaymentsStmt->execute([':from' => $fromStr, ':to' => $toStr]);

        $recentBillsStmt = $db->prepare("SELECT b.id, b.account_number, b.amount, b.status, b.billing_month, b.due_date, u.full_name FROM bills b LEFT JOIN users u ON u.id = b.user_id WHERE DATE(b.created_at) BETWEEN :from AND :to ORDER BY b.created_at DESC LIMIT 100");
        $recentBillsStmt->execute([':from' => $fromStr, ':to' => $toStr]);

        $auditStatusFile = __DIR__ . '/../../../logs/billing_audit_status.json';
        $auditStatus = null;
        if (is_file($auditStatusFile) && is_readable($auditStatusFile)) {
            $decoded = json_decode((string)file_get_contents($auditStatusFile), true);
            if (is_array($decoded)) {
                $auditStatus = $decoded;
            }
        }

        mobileApiJson(200, 'success', 'Reports loaded.', [
            'currency' => $currency,
            'period' => ['from' => $fromStr, 'to' => $toStr, 'key' => $period],
            'summary' => ['completed_payments_total' => $completedPaymentsTotal, 'billed_total' => $billedTotal],
            'recent_payments' => $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'recent_bills' => $recentBillsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'audit_status' => $auditStatus,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $maintenanceAction = trim((string)($data['maintenance_action'] ?? ''));
        $scriptBase = realpath(__DIR__ . '/../../../scripts');
        if ($scriptBase === false) {
            mobileApiJson(500, 'error', 'Reports maintenance scripts are unavailable.');
        }
        if ($maintenanceAction === 'run_audit') {
            mobileApiJson(200, 'success', 'Billing integrity audit completed.', ['result' => mobileApiRunReportsMaintenanceCommand($scriptBase . '/billing_integrity_audit.php')]);
        }
        if ($maintenanceAction === 'preview_repair') {
            mobileApiJson(200, 'success', 'Billing repair preview completed.', ['result' => mobileApiRunReportsMaintenanceCommand($scriptBase . '/repair_billing_journals.php')]);
        }
        if ($maintenanceAction === 'apply_repair') {
            mobileApiJson(200, 'success', 'Billing repair apply completed.', ['result' => mobileApiRunReportsMaintenanceCommand($scriptBase . '/repair_billing_journals.php', ['--apply'])]);
        }
        mobileApiJson(422, 'error', 'Unsupported reports maintenance action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin reports failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load reports right now.');
}