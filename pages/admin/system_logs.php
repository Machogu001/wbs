<?php
session_start();
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ErrorLog.php';
require_once __DIR__ . '/../../includes/SMSQueue.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../config/sms_config.php';
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();
$smsQueue = new SMSQueue($db);

$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: /login');
    exit;
}

if (empty($_SESSION['system_logs_csrf'])) {
    $_SESSION['system_logs_csrf'] = bin2hex(random_bytes(32));
}

$actionMessage = null;
$actionMessageType = 'success';

if (!empty($_SESSION['system_logs_flash']) && is_array($_SESSION['system_logs_flash'])) {
    $actionMessage = (string)($_SESSION['system_logs_flash']['message'] ?? '');
    $actionMessageType = (string)($_SESSION['system_logs_flash']['type'] ?? 'success');
    unset($_SESSION['system_logs_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectAnchor = '';
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['system_logs_csrf'], $token)) {
        $actionMessage = 'Security validation failed. Please refresh and try again.';
        $actionMessageType = 'danger';
    } else {
        $rawAction = $_POST['log_action'] ?? '';
        $action = $rawAction;
        $retrySmsId = 0;
        if (strpos($rawAction, 'retry_sms_now:') === 0) {
            $action = 'retry_sms_now';
            $retrySmsId = (int)substr($rawAction, strlen('retry_sms_now:'));
        }

        try {
            if ($action === 'delete_selected') {
                $redirectAnchor = 'recentErrorLogsSection';
                $ids = $_POST['log_ids'] ?? [];
                $ids = array_values(array_filter(array_map('intval', (array)$ids), function($id) {
                    return $id > 0;
                }));

                if (empty($ids)) {
                    $actionMessage = 'No logs selected.';
                    $actionMessageType = 'warning';
                } else {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $db->prepare("DELETE FROM error_logs WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $deleted = (int)$stmt->rowCount();
                    $actionMessage = $deleted . ' log(s) deleted successfully.';
                }
            } elseif ($action === 'delete_all') {
                $redirectAnchor = 'recentErrorLogsSection';
                $stmt = $db->prepare("DELETE FROM error_logs");
                $stmt->execute();
                $deleted = (int)$stmt->rowCount();
                $actionMessage = $deleted . ' log(s) deleted successfully.';
            } elseif ($action === 'delete_sms_selected') {
                $status = $_POST['sms_status'] ?? '';
                if ($status === 'pending') {
                    $redirectAnchor = 'pendingSmsSection';
                } elseif ($status === 'sent') {
                    $redirectAnchor = 'sentSmsSection';
                } elseif ($status === 'failed_permanent') {
                    $redirectAnchor = 'failedSmsSection';
                }
                $validStatuses = ['pending', 'sent', 'failed_permanent'];
                if (!in_array($status, $validStatuses, true)) {
                    $actionMessage = 'Invalid SMS status selected.';
                    $actionMessageType = 'warning';
                } else {
                    $ids = $_POST['sms_ids'] ?? [];
                    $ids = array_values(array_filter(array_map('intval', (array)$ids), function($id) {
                        return $id > 0;
                    }));

                    if (empty($ids)) {
                        $actionMessage = 'No SMS records selected.';
                        $actionMessageType = 'warning';
                    } else {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $stmt = $db->prepare("DELETE FROM sms_queue WHERE id IN ($placeholders) AND status = ?");
                        $params = $ids;
                        $params[] = $status;
                        $stmt->execute($params);
                        $deleted = (int)$stmt->rowCount();
                        $actionMessage = $deleted . ' SMS record(s) deleted from ' . str_replace('_', ' ', $status) . '.';
                    }
                }
            } elseif ($action === 'delete_sms_status') {
                $status = $_POST['sms_status'] ?? '';
                if ($status === 'pending') {
                    $redirectAnchor = 'pendingSmsSection';
                } elseif ($status === 'sent') {
                    $redirectAnchor = 'sentSmsSection';
                } elseif ($status === 'failed_permanent') {
                    $redirectAnchor = 'failedSmsSection';
                }
                $validStatuses = ['pending', 'sent', 'failed_permanent'];
                if (!in_array($status, $validStatuses, true)) {
                    $actionMessage = 'Invalid SMS status selected.';
                    $actionMessageType = 'warning';
                } else {
                    $stmt = $db->prepare("DELETE FROM sms_queue WHERE status = ?");
                    $stmt->execute([$status]);
                    $deleted = (int)$stmt->rowCount();
                    $actionMessage = $deleted . ' SMS record(s) deleted from ' . str_replace('_', ' ', $status) . '.';
                }
            } elseif ($action === 'retry_sms_now') {
                $smsId = $retrySmsId > 0 ? $retrySmsId : (int)($_POST['sms_id'] ?? 0);
                $retryStatus = $_POST['sms_status'] ?? '';
                if ($retryStatus === 'pending') {
                    $redirectAnchor = 'pendingSmsSection';
                } elseif ($retryStatus === 'failed_permanent') {
                    $redirectAnchor = 'failedSmsSection';
                }
                if ($smsId <= 0) {
                    $actionMessage = 'Invalid SMS record selected for retry.';
                    $actionMessageType = 'warning';
                } else {
                    $stmt = $db->prepare("SELECT * FROM sms_queue WHERE id = ? LIMIT 1");
                    $stmt->execute([$smsId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row) {
                        $actionMessage = 'SMS record not found.';
                        $actionMessageType = 'warning';
                    } elseif (($row['status'] ?? '') === 'sent') {
                        $actionMessage = 'This SMS is already marked as sent.';
                        $actionMessageType = 'info';
                    } elseif (!SmsConfig::getApiToken() || !SmsConfig::getSenderId()) {
                        $actionMessage = 'SMS credentials are not configured; retry cannot be sent now.';
                        $actionMessageType = 'warning';
                    } else {
                        $sms = new SMS($db);
                        $result = $sms->send((string)$row['phone'], (string)$row['message'], false);

                        if (!empty($result['success'])) {
                            $smsQueue->markSent($smsId, (string)($result['response'] ?? ''), (int)($result['http_code'] ?? 200));
                            $actionMessage = 'SMS retried and sent successfully.';
                            $actionMessageType = 'success';
                        } else {
                            $errorMessage = (string)($result['message'] ?? ('HTTP ' . ($result['http_code'] ?? 'unknown')));
                            $stmt = $db->prepare("UPDATE sms_queue SET status = 'pending', retry_count = retry_count + 1, last_error = ?, last_attempt = NOW() WHERE id = ?");
                            $stmt->execute([$errorMessage, $smsId]);

                            $stmt = $db->prepare("SELECT retry_count FROM sms_queue WHERE id = ? LIMIT 1");
                            $stmt->execute([$smsId]);
                            $retryCount = (int)$stmt->fetchColumn();
                            $newStatus = 'pending';

                            if ($retryCount >= 3) {
                                $stmt = $db->prepare("UPDATE sms_queue SET status = 'failed_permanent', last_error = ?, last_attempt = NOW() WHERE id = ?");
                                $stmt->execute([$errorMessage, $smsId]);
                                $newStatus = 'failed_permanent';
                            }

                            if ($newStatus === 'failed_permanent') {
                                $actionMessage = 'Retry failed and message moved to permanent failure.';
                            } else {
                                $actionMessage = 'Retry failed; message remains pending for next cron attempt.';
                            }
                            $actionMessageType = 'warning';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $actionMessage = ($action === 'retry_sms_now')
                ? 'Failed to retry SMS. Please try again.'
                : 'Failed to delete logs. Please try again.';
            $actionMessageType = 'danger';
        }
    }

    $_SESSION['system_logs_flash'] = [
        'message' => $actionMessage,
        'type' => $actionMessageType,
    ];

    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    if (!is_string($redirectUrl) || $redirectUrl === '') {
        $redirectUrl = '/system-logs';
    }
    if ($redirectAnchor !== '') {
        $redirectUrl .= '#' . $redirectAnchor;
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$errorLog = new ErrorLog($db);
// Self-heal critical monitoring tables for environments with partial migrations.
ErrorLog::ensureTable($db);
SMSQueue::ensureTable($db);

// Get data for display
$recentErrors = $errorLog->getRecent(50);
$errorStats = $errorLog->getErrorCountByService(24);
$pendingSmsItems = [];
$sentSmsItems = [];
$failedSmsItems = [];
$totalPending = 0;
$totalSent = 0;
$totalFailed = 0;

$allowedStatuses = ['pending', 'sent', 'failed_permanent'];

$fetchSmsByStatus = function(string $status, int $limit = 200) use ($db, $allowedStatuses): array {
    if (!in_array($status, $allowedStatuses, true)) {
        return [];
    }

    $safeLimit = max(1, min(1000, $limit));
    $sql = "SELECT * FROM sms_queue WHERE status = :status ORDER BY created_at DESC LIMIT " . (int)$safeLimit;
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$countSmsByStatus = function(string $status) use ($db, $allowedStatuses): int {
    if (!in_array($status, $allowedStatuses, true)) {
        return 0;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM sms_queue WHERE status = :status");
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
};

try {
    $pendingSmsItems = $fetchSmsByStatus('pending', 200);
    $sentSmsItems = $fetchSmsByStatus('sent', 200);
    $failedSmsItems = $fetchSmsByStatus('failed_permanent', 200);

    $totalPending = $countSmsByStatus('pending');
    $totalSent = $countSmsByStatus('sent');
    $totalFailed = $countSmsByStatus('failed_permanent');
} catch (Throwable $e) {
    if ($actionMessage === null) {
        $actionMessage = 'SMS queue details could not be loaded. Please verify sms_queue table schema.';
        $actionMessageType = 'warning';
    }
}

$totalSmsRecords = (int)$totalPending + (int)$totalSent + (int)$totalFailed;
$totalErrors24 = 0;
foreach ($errorStats as $statItem) {
    $totalErrors24 += (int)($statItem['error_count'] ?? 0);
}

$page_title = 'System Logs';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
    
    <div class="container-fluid mt-4 admin-shell system-monitor-board">
        <div class="row" id="recentErrorLogsSection">
            <div class="col-md-12">
                <div class="pb-banner pb-banner--slate mb-4">
                    <div class="pb-bg" aria-hidden="true">
                        <div class="pb-grid"></div>
                        <div class="pb-blob pb-blob--a"></div>
                        <div class="pb-blob pb-blob--b"></div>
                        <i class="bi bi-server pb-watermark"></i>
                    </div>
                    <div class="pb-inner">
                        <div class="pb-left">
                            <div class="pb-eyebrow-row">
                                <span class="pb-eyebrow-chip"><i class="bi bi-server"></i> Operations Center</span>
                            </div>
                            <h2 class="pb-title">System Monitoring Dashboard</h2>
                            <p class="pb-subtitle">Track SMS delivery health, service errors, and recent system events in one place.</p>
                        </div>
                        <div class="pb-right">
                            <div class="pb-kpi-row">
                                <div class="pb-kpi">
                                    <span class="pb-kpi-label">SMS Records</span>
                                    <span class="pb-kpi-value"><?php echo (int)$totalSmsRecords; ?></span>
                                </div>
                                <div class="pb-kpi">
                                    <span class="pb-kpi-label">Errors (24h)</span>
                                    <span class="pb-kpi-value"><?php echo (int)$totalErrors24; ?></span>
                                </div>
                            </div>
                            <div class="pb-btn-row">
                                <button type="button" class="pb-btn" data-density-toggle data-density-target=".system-monitor-board" data-density-key="system-logs-table" data-density-compact-text="Compact View" data-density-comfy-text="Comfortable View">
                                    <i class="bi bi-arrows-collapse"></i> <span class="js-density-label">Compact View</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SMS Queue Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-6">
                <a href="#pendingSmsSection" class="text-decoration-none system-kpi-link">
                <div class="card admin-kpi-card system-kpi-card system-kpi-pending">
                    <div class="card-body">
                        <div class="system-kpi-top">
                            <h5 class="card-title mb-0">Pending SMS</h5>
                            <span class="system-kpi-icon"><i class="bi bi-hourglass-split"></i></span>
                        </div>
                        <h3 class="system-kpi-value"><?php echo $totalPending; ?></h3>
                        <small>Waiting to be sent</small>
                    </div>
                </div>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="#sentSmsSection" class="text-decoration-none system-kpi-link">
                <div class="card admin-kpi-card system-kpi-card system-kpi-sent">
                    <div class="card-body">
                        <div class="system-kpi-top">
                            <h5 class="card-title mb-0">Sent SMS</h5>
                            <span class="system-kpi-icon"><i class="bi bi-check2-circle"></i></span>
                        </div>
                        <h3 class="system-kpi-value"><?php echo $totalSent; ?></h3>
                        <small>Successfully delivered</small>
                    </div>
                </div>
                </a>
            </div>
            <div class="col-lg-4 col-md-12">
                <a href="#failedSmsSection" class="text-decoration-none system-kpi-link">
                <div class="card admin-kpi-card system-kpi-card system-kpi-failed">
                    <div class="card-body">
                        <div class="system-kpi-top">
                            <h5 class="card-title mb-0">Failed SMS</h5>
                            <span class="system-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
                        </div>
                        <h3 class="system-kpi-value"><?php echo $totalFailed; ?></h3>
                        <small>Permanent failures</small>
                    </div>
                </div>
                </a>
            </div>
        </div>

        <div class="row mb-4" id="pendingSmsSection">
            <div class="col-md-12">
                <div class="card admin-table-card system-section-card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">Pending SMS Queue Details (Latest 200)</h5>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge bg-warning text-dark">Pending: <?php echo (int)$totalPending; ?></span>
                            <button type="submit" form="pendingSmsForm" name="log_action" value="delete_sms_selected" class="btn btn-sm btn-outline-danger" id="deleteSelectedPendingBtn" disabled data-confirm-message="Delete selected pending SMS records?">Delete Selected</button>
                            <button type="submit" form="pendingSmsForm" name="log_action" value="delete_sms_status" class="btn btn-sm btn-danger" data-confirm-message="Delete ALL pending SMS records? This cannot be undone.">Delete All Pending</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <form method="POST" id="pendingSmsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['system_logs_csrf']); ?>">
                            <input type="hidden" name="sms_status" value="pending">
                        <div class="table-responsive system-table-wrap" style="max-height: 360px; overflow-y: auto;">
                            <table class="table table-striped table-sm mb-0 align-middle system-data-table table-density-target">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAllPendingSms"></th>
                                        <th>ID</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Retry</th>
                                        <th>Created</th>
                                        <th>Last Attempt</th>
                                        <th>Next Auto Retry</th>
                                        <th>Last Error</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($pendingSmsItems)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted py-3">No pending SMS in queue.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pendingSmsItems as $item): ?>
                                        <?php
                                            $baseRetryTime = !empty($item['last_attempt']) ? (string)$item['last_attempt'] : (string)($item['created_at'] ?? '');
                                            $nextRetryLabel = '-';
                                            if ($baseRetryTime !== '') {
                                                $nextRetryTs = strtotime($baseRetryTime . ' +5 minutes');
                                                if ($nextRetryTs !== false) {
                                                    $nextRetryLabel = date('d-m-Y H:i', $nextRetryTs);
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><input type="checkbox" class="pending-sms-checkbox" name="sms_ids[]" value="<?php echo (int)($item['id'] ?? 0); ?>"></td>
                                            <td><?php echo (int)($item['id'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars($item['phone'] ?? ''); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['type'] ?? 'general'); ?></span></td>
                                            <td><small class="view-sms-message" style="cursor: pointer; text-decoration: underline;" title="Click to view full message" data-full-message="<?php echo htmlspecialchars($item['message'] ?? ''); ?>"><?php echo htmlspecialchars(substr((string)($item['message'] ?? ''), 0, 120)); ?></small></td>
                                            <td><?php echo (int)($item['retry_count'] ?? 0); ?></td>
                                            <td><small><?php echo !empty($item['created_at']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['created_at']))) : '-'; ?></small></td>
                                            <td><small><?php echo !empty($item['last_attempt']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['last_attempt']))) : '-'; ?></small></td>
                                            <td><small><?php echo htmlspecialchars($nextRetryLabel); ?></small></td>
                                            <td><small class="text-danger"><?php echo !empty($item['last_error']) ? htmlspecialchars(substr((string)$item['last_error'], 0, 80)) : '-'; ?></small></td>
                                            <td>
                                                <button type="submit" form="pendingSmsForm" class="btn btn-sm btn-outline-primary" name="log_action" value="retry_sms_now:<?php echo (int)($item['id'] ?? 0); ?>" data-confirm-message="Retry sending this SMS now?">Retry Now</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4" id="sentSmsSection">
            <div class="col-md-12">
                <div class="card admin-table-card system-section-card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">Sent SMS Details (Latest 200)</h5>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge bg-success">Sent: <?php echo (int)$totalSent; ?></span>
                            <button type="submit" form="sentSmsForm" name="log_action" value="delete_sms_selected" class="btn btn-sm btn-outline-danger" id="deleteSelectedSentBtn" disabled data-confirm-message="Delete selected sent SMS records?">Delete Selected</button>
                            <button type="submit" form="sentSmsForm" name="log_action" value="delete_sms_status" class="btn btn-sm btn-danger" data-confirm-message="Delete ALL sent SMS records? This cannot be undone.">Delete All Sent</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <form method="POST" id="sentSmsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['system_logs_csrf']); ?>">
                            <input type="hidden" name="sms_status" value="sent">
                        <div class="table-responsive system-table-wrap" style="max-height: 360px; overflow-y: auto;">
                            <table class="table table-striped table-sm mb-0 align-middle system-data-table table-density-target">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAllSentSms"></th>
                                        <th>ID</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Sent At</th>
                                        <th>HTTP Code</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($sentSmsItems)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-3">No sent SMS records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sentSmsItems as $item): ?>
                                        <tr>
                                            <td><input type="checkbox" class="sent-sms-checkbox" name="sms_ids[]" value="<?php echo (int)($item['id'] ?? 0); ?>"></td>
                                            <td><?php echo (int)($item['id'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars($item['phone'] ?? ''); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['type'] ?? 'general'); ?></span></td>
                                            <td><small class="view-sms-message" style="cursor: pointer; text-decoration: underline;" title="Click to view full message" data-full-message="<?php echo htmlspecialchars($item['message'] ?? ''); ?>"><?php echo htmlspecialchars(substr((string)($item['message'] ?? ''), 0, 120)); ?></small></td>
                                            <td><small><?php echo !empty($item['sent_at']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['sent_at']))) : '-'; ?></small></td>
                                            <td><?php echo isset($item['http_code']) ? (int)$item['http_code'] : '-'; ?></td>
                                            <td><small><?php echo !empty($item['created_at']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['created_at']))) : '-'; ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4" id="failedSmsSection">
            <div class="col-md-12">
                <div class="card admin-table-card system-section-card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">Failed SMS Details (Latest 200)</h5>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge bg-danger">Failed: <?php echo (int)$totalFailed; ?></span>
                            <button type="submit" form="failedSmsForm" name="log_action" value="delete_sms_selected" class="btn btn-sm btn-outline-danger" id="deleteSelectedFailedBtn" disabled data-confirm-message="Delete selected failed SMS records?">Delete Selected</button>
                            <button type="submit" form="failedSmsForm" name="log_action" value="delete_sms_status" class="btn btn-sm btn-danger" data-confirm-message="Delete ALL failed SMS records? This cannot be undone.">Delete All Failed</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <form method="POST" id="failedSmsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['system_logs_csrf']); ?>">
                            <input type="hidden" name="sms_status" value="failed_permanent">
                        <div class="table-responsive system-table-wrap" style="max-height: 360px; overflow-y: auto;">
                            <table class="table table-striped table-sm mb-0 align-middle system-data-table table-density-target">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAllFailedSms"></th>
                                        <th>ID</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Retry</th>
                                        <th>Last Attempt</th>
                                        <th>Last Error</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($failedSmsItems)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-3">No failed SMS records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($failedSmsItems as $item): ?>
                                        <tr>
                                            <td><input type="checkbox" class="failed-sms-checkbox" name="sms_ids[]" value="<?php echo (int)($item['id'] ?? 0); ?>"></td>
                                            <td><?php echo (int)($item['id'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars($item['phone'] ?? ''); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['type'] ?? 'general'); ?></span></td>
                                            <td><small class="view-sms-message" style="cursor: pointer; text-decoration: underline;" title="Click to view full message" data-full-message="<?php echo htmlspecialchars($item['message'] ?? ''); ?>"><?php echo htmlspecialchars(substr((string)($item['message'] ?? ''), 0, 120)); ?></small></td>
                                            <td><?php echo (int)($item['retry_count'] ?? 0); ?></td>
                                            <td><small><?php echo !empty($item['last_attempt']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['last_attempt']))) : '-'; ?></small></td>
                                            <td><small class="text-danger"><?php echo !empty($item['last_error']) ? htmlspecialchars(substr((string)$item['last_error'], 0, 100)) : '-'; ?></small></td>
                                            <td><small><?php echo !empty($item['created_at']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['created_at']))) : '-'; ?></small></td>
                                            <td>
                                                <button type="submit" form="failedSmsForm" class="btn btn-sm btn-outline-primary" name="log_action" value="retry_sms_now:<?php echo (int)($item['id'] ?? 0); ?>" data-confirm-message="Retry sending this SMS now?">Retry Now</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Statistics by Service (Last 24 hours) -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card admin-table-card system-section-card">
                    <div class="card-header">
                        <h5>Error Count by Service (Last 24 Hours)</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-striped system-data-table table-density-target">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Error Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($errorStats)): ?>
                                    <tr><td colspan="2" class="text-center">No errors in the last 24 hours</td></tr>
                                <?php else: ?>
                                    <?php foreach ($errorStats as $stat): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-danger"><?php echo htmlspecialchars($stat['service']); ?></span>
                                            </td>
                                            <td><?php echo $stat['error_count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Error Logs -->
        <div class="row">
            <div class="col-md-12">
                <div class="card admin-table-card system-section-card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">Recent Error Logs</h5>
                        <div class="d-flex gap-2">
                            <button type="submit" form="logsActionForm" name="log_action" value="delete_selected" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" disabled data-confirm-message="Delete selected logs? This action cannot be undone.">
                                Delete Selected
                            </button>
                            <button type="submit" form="logsActionForm" name="log_action" value="delete_all" class="btn btn-sm btn-danger" data-confirm-message="Delete ALL logs? This action cannot be undone.">
                                Delete All Logs
                            </button>
                        </div>
                    </div>
                    <div class="card-body system-table-wrap" style="max-height: 600px; overflow-y: auto;">
                        <form method="POST" id="logsActionForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['system_logs_csrf']); ?>">
                        <table class="table table-striped table-sm system-data-table table-density-target">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="selectAllLogs"></th>
                                    <th>Timestamp</th>
                                    <th>Service</th>
                                    <th>Category</th>
                                    <th>Message</th>
                                    <th>HTTP Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentErrors)): ?>
                                    <tr><td colspan="6" class="text-center">No errors logged</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentErrors as $error): ?>
                                        <tr>
                                            <td><input type="checkbox" class="log-checkbox" name="log_ids[]" value="<?php echo (int)$error['id']; ?>"></td>
                                            <td><small><?php echo date('M d, H:i', strtotime($error['created_at'])); ?></small></td>
                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($error['service']); ?></span></td>
                                            <td>
                                                <?php if ($error['category']): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($error['category']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small><?php echo htmlspecialchars(substr($error['error_message'], 0, 50)); ?></small></td>
                                            <td><?php echo $error['http_code'] ?? '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Message Modal -->
    <div class="modal fade" id="smsChatModal" tabindex="-1" aria-labelledby="smsChatModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="smsChatModalLabel">Full SMS Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="smsMessageContent" style="word-wrap: break-word; white-space: pre-wrap;"></p>
                    <div id="smsMessageLinks" class="d-none mb-3"></div>
                    <button type="button" class="btn btn-sm btn-secondary" id="copySmsMessageBtn" title="Copy to clipboard">
                        <i class="bi bi-clipboard"></i> Copy Message
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.WbsAdminUi) {
            window.WbsAdminUi.init({
                flashMessage: <?php echo json_encode($actionMessage); ?>,
                flashType: <?php echo json_encode($actionMessageType); ?>
            });
        }

        function initBulkSelect(selectAllId, checkboxSelector, buttonId) {
            var selectAll = document.getElementById(selectAllId);
            var deleteSelectedBtn = document.getElementById(buttonId);
            var checkboxes = Array.prototype.slice.call(document.querySelectorAll(checkboxSelector));

            function syncBulkActionState() {
                if (!deleteSelectedBtn) return;
                var selectedCount = checkboxes.filter(function(cb) { return cb.checked; }).length;
                deleteSelectedBtn.disabled = selectedCount === 0;
                if (selectAll && checkboxes.length > 0) {
                    selectAll.checked = selectedCount === checkboxes.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(function(cb) {
                        cb.checked = selectAll.checked;
                    });
                    syncBulkActionState();
                });
            }

            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', syncBulkActionState);
            });

            syncBulkActionState();
        }

        initBulkSelect('selectAllLogs', '.log-checkbox', 'deleteSelectedBtn');
        initBulkSelect('selectAllPendingSms', '.pending-sms-checkbox', 'deleteSelectedPendingBtn');
        initBulkSelect('selectAllSentSms', '.sent-sms-checkbox', 'deleteSelectedSentBtn');
        initBulkSelect('selectAllFailedSms', '.failed-sms-checkbox', 'deleteSelectedFailedBtn');

        // Handle SMS message preview
        document.querySelectorAll('.view-sms-message').forEach(function(element) {
            element.addEventListener('click', function() {
                var fullMessage = this.getAttribute('data-full-message');
                var messageContent = document.getElementById('smsMessageContent');
                var linksWrap = document.getElementById('smsMessageLinks');
                messageContent.textContent = fullMessage;

                var matches = fullMessage ? fullMessage.match(/https?:\/\/[^\s]+/g) : null;
                linksWrap.innerHTML = '';
                if (matches && matches.length) {
                    linksWrap.classList.remove('d-none');
                    var heading = document.createElement('div');
                    heading.className = 'small text-muted mb-2';
                    heading.textContent = 'Detected link' + (matches.length > 1 ? 's' : '');
                    linksWrap.appendChild(heading);

                    matches.forEach(function(link) {
                        var row = document.createElement('div');
                        row.className = 'd-flex flex-wrap gap-2 align-items-center mb-2';

                        var openLink = document.createElement('a');
                        openLink.className = 'btn btn-sm btn-outline-primary';
                        openLink.href = link;
                        openLink.target = '_blank';
                        openLink.rel = 'noopener';
                        openLink.textContent = 'Open Link';

                        var copyLink = document.createElement('button');
                        copyLink.type = 'button';
                        copyLink.className = 'btn btn-sm btn-outline-secondary';
                        copyLink.textContent = 'Copy Link';
                        copyLink.addEventListener('click', function() {
                            navigator.clipboard.writeText(link).then(function() {
                                alert('Link copied to clipboard!');
                            }).catch(function() {
                                alert('Failed to copy link');
                            });
                        });

                        var linkText = document.createElement('code');
                        linkText.className = 'small';
                        linkText.style.whiteSpace = 'normal';
                        linkText.textContent = link;

                        row.appendChild(openLink);
                        row.appendChild(copyLink);
                        row.appendChild(linkText);
                        linksWrap.appendChild(row);
                    });
                } else {
                    linksWrap.classList.add('d-none');
                }
                var modal = new bootstrap.Modal(document.getElementById('smsChatModal'));
                modal.show();
            });
        });

        // Copy to clipboard functionality
        document.getElementById('copySmsMessageBtn').addEventListener('click', function() {
            var messageContent = document.getElementById('smsMessageContent').textContent;
            navigator.clipboard.writeText(messageContent).then(function() {
                alert('Message copied to clipboard!');
            }).catch(function() {
                alert('Failed to copy message');
            });
        });
    });
    </script>

    <?php include __DIR__ . '/../../templates/footer.php'; ?>
