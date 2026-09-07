<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

$page_title = "Dashboard";
require_once __DIR__ . '/../config/database.php';require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/MeterReading.php';
require_once __DIR__ . '/../includes/ClientWallet.php';
require_once __DIR__ . '/../templates/header.php';

$total_paid = 0.0;
$total_unpaid = 0.0;
$pending_amount = 0.0;
$overdue_amount = 0.0;
$pending_count = 0;
$collection_rate = 0.0;
$current_usage = 0.0;
$previous_usage = 0.0;
$total_users = null;
$last_payment_amount = null;
$last_payment_date = null;
$isAdmin = isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin';
$auditStatus = null;
$recentBills = [];
$recentPayments = [];
$registration_billed_total = 0.0;
$registration_bills_count = 0;
$registration_collected_total = 0.0;
$registration_payments_count = 0;
$registration_outstanding_total = 0.0;
$user_registration_outstanding = 0.0;
$user_wallet_balance = 0.0;
$user_registration_balance_value = 'Paid in full';
$user_registration_balance_note = 'No registration fee balance is currently due on your account.';
$balanceCardTitle = 'Account Balance';
$balanceCardTooltip = 'Total amount currently outstanding on your account.';
$paidCardTitle = 'Total Paid';
$paidCardTooltip = 'All payments successfully recorded in the system.';
$pendingCardTitle = 'Pending Bills';
$pendingCardTooltip = 'Number of bills that are not yet fully paid.';
$collectionCardTitle = 'Collection Rate';
$collectionCardSubtitle = 'Overall ratio of payments to total billed.';
$collectionCardValue = 'N/A';
$registrationCardTitle = 'Registration Revenue';
$registrationCardTooltip = 'One-off registration fees billed and collected separately from recurring monthly bills.';

$auditStatusFile = __DIR__ . '/../logs/billing_audit_status.json';
if ($isAdmin && is_file($auditStatusFile) && is_readable($auditStatusFile)) {
    $decodedAuditStatus = json_decode((string)file_get_contents($auditStatusFile), true);
    if (is_array($decodedAuditStatus)) {
        $auditStatus = $decodedAuditStatus;
    }
}

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}
if ($db) {
    $billService = new Bill($db);
    $paymentService = new Payment($db);

    if ($isAdmin) {
        $summary = $billService->getSystemSummary();

        // For admins, show latest system-wide payments and bills
        $stmt = $db->query("SELECT p.id, p.amount, p.status, p.mpesa_receipt, COALESCE(p.transaction_date, p.created_at) AS paid_at
                            FROM payments p
                            ORDER BY COALESCE(p.transaction_date, p.created_at) DESC
                            LIMIT 5");
        $recentPayments = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtBills = $db->query("SELECT b.id, b.account_number, b.billing_month, b.amount, b.status, b.due_date
                                  FROM bills b
                                  ORDER BY b.billing_month DESC, b.id DESC
                                  LIMIT 5");
        $recentBills = $stmtBills ? $stmtBills->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $summary = $billService->getUserSummary($_SESSION['user_id']);

        // For normal users, show their own latest payments and bills
        $stmt = $db->prepare("SELECT id, amount, status, mpesa_receipt, COALESCE(transaction_date, created_at) AS paid_at
                              FROM payments
                              WHERE user_id = :uid
                              ORDER BY COALESCE(transaction_date, created_at) DESC
                              LIMIT 5");
        $stmt->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtBills = $db->prepare("SELECT id, billing_month, amount, status, due_date
                                    FROM bills
                                    WHERE user_id = :uid
                                    ORDER BY billing_month DESC, id DESC
                                    LIMIT 5");
        $stmtBills->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmtBills->execute();
        $recentBills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);

        $walletService = new ClientWallet($db);
        $user_wallet_balance = $walletService->getBalance((int)$_SESSION['user_id']);

        $latestRegistrationPayment = $paymentService->getLatestRegistrationByUserId((int)$_SESSION['user_id']);
        if ($latestRegistrationPayment && !empty($latestRegistrationPayment['bill_id'])) {
            $user_registration_outstanding = $paymentService->getBillOutstandingAmount((int)$latestRegistrationPayment['bill_id']);
        }
        if ($user_registration_outstanding > 0.01) {
            $user_registration_balance_value = 'Ksh ' . number_format($user_registration_outstanding, 2);
            $user_registration_balance_note = 'This is your remaining one-time registration fee balance.';
        } else {
            $user_registration_outstanding = 0.0;
        }
    }

    $total_paid = (float)($summary['total_paid'] ?? 0);
    $total_unpaid = (float)($summary['total_unpaid'] ?? 0);
    $pending_amount = (float)($summary['pending_amount'] ?? 0);
    $overdue_amount = (float)($summary['overdue_amount'] ?? 0);
    $pending_count = (int)($summary['pending_count'] ?? 0);

    $registrationBillPredicateSql = "(EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = b.id AND bli_reg.line_type = 'registration_fee') OR (b.consumption = 0 AND b.rate_per_unit = 0 AND b.base_amount = 0 AND b.service_charge > 0))";
    $monthlyBillPredicateSql = 'NOT ' . $registrationBillPredicateSql;
    $registrationPaymentPredicateSql = "(p.registration_id IS NOT NULL OR EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = p.bill_id AND bli_reg.line_type = 'registration_fee') OR EXISTS (SELECT 1 FROM bills b_reg WHERE b_reg.id = p.bill_id AND b_reg.consumption = 0 AND b_reg.rate_per_unit = 0 AND b_reg.base_amount = 0 AND b_reg.service_charge > 0))";
    $monthlyPaymentPredicateSql = 'NOT ' . $registrationPaymentPredicateSql;
    $monthlyOutstandingExprSql = "GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0))";

    if ($isAdmin) {
        $stmtAdminBillSummary = $db->query("SELECT
            COALESCE(SUM(CASE WHEN {$monthlyBillPredicateSql} AND b.status IN ('pending','overdue') AND b.amount > 0 THEN {$monthlyOutstandingExprSql} ELSE 0 END), 0) AS monthly_unpaid,
            COALESCE(SUM(CASE WHEN {$monthlyBillPredicateSql} AND b.status = 'pending' AND b.amount > 0 THEN {$monthlyOutstandingExprSql} ELSE 0 END), 0) AS monthly_pending_amount,
            COALESCE(SUM(CASE WHEN {$monthlyBillPredicateSql} AND b.status = 'overdue' AND b.amount > 0 THEN {$monthlyOutstandingExprSql} ELSE 0 END), 0) AS monthly_overdue_amount,
            COALESCE(SUM(CASE WHEN {$monthlyBillPredicateSql} AND b.status IN ('pending','overdue') AND b.amount > 0 AND {$monthlyOutstandingExprSql} > 0 THEN 1 ELSE 0 END), 0) AS monthly_pending_count,
            COALESCE(SUM(CASE WHEN {$registrationBillPredicateSql} AND b.amount > 0 THEN b.amount ELSE 0 END), 0) AS registration_billed_total,
            COALESCE(SUM(CASE WHEN {$registrationBillPredicateSql} AND b.amount > 0 THEN 1 ELSE 0 END), 0) AS registration_bills_count
            FROM bills b
            LEFT JOIN (
                SELECT bill_id, COALESCE(SUM(amount), 0) AS completed_paid
                FROM payments
                WHERE status = 'completed' AND bill_id IS NOT NULL
                GROUP BY bill_id
            ) p_paid ON p_paid.bill_id = b.id
            LEFT JOIN (
                SELECT p.bill_id, COALESCE(SUM(pa.amount), 0) AS approved_adjustments
                FROM payment_adjustments pa
                INNER JOIN payments p ON p.id = pa.payment_id
                WHERE pa.status = 'approved' AND p.bill_id IS NOT NULL
                GROUP BY p.bill_id
            ) pa_adj ON pa_adj.bill_id = b.id");
        $adminBillSummary = $stmtAdminBillSummary ? ($stmtAdminBillSummary->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $stmtAdminPaymentSummary = $db->query("SELECT
            COALESCE(SUM(CASE WHEN {$monthlyPaymentPredicateSql} AND p.status = 'completed' AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS monthly_paid,
            COALESCE(SUM(CASE WHEN {$registrationPaymentPredicateSql} AND p.status = 'completed' AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS registration_collected_total,
            COALESCE(SUM(CASE WHEN {$registrationPaymentPredicateSql} AND p.status = 'completed' AND p.amount > 0 THEN 1 ELSE 0 END), 0) AS registration_payments_count
            FROM payments p");
        $adminPaymentSummary = $stmtAdminPaymentSummary ? ($stmtAdminPaymentSummary->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $total_paid = (float)($adminPaymentSummary['monthly_paid'] ?? 0);
        $total_unpaid = (float)($adminBillSummary['monthly_unpaid'] ?? 0);
        $pending_amount = (float)($adminBillSummary['monthly_pending_amount'] ?? 0);
        $overdue_amount = (float)($adminBillSummary['monthly_overdue_amount'] ?? 0);
        $pending_count = (int)($adminBillSummary['monthly_pending_count'] ?? 0);
        $registration_billed_total = (float)($adminBillSummary['registration_billed_total'] ?? 0);
        $registration_bills_count = (int)($adminBillSummary['registration_bills_count'] ?? 0);
        $registration_collected_total = (float)($adminPaymentSummary['registration_collected_total'] ?? 0);
        $registration_payments_count = (int)($adminPaymentSummary['registration_payments_count'] ?? 0);
        $registration_outstanding_total = max(0, $registration_billed_total - $registration_collected_total);

        $balanceCardTitle = 'Monthly Balance';
        $balanceCardTooltip = 'Outstanding recurring monthly bills. Registration fees are excluded.';
        $paidCardTitle = 'Monthly Collected';
        $paidCardTooltip = 'Completed recurring-bill payments. Registration fees are excluded.';
        $pendingCardTitle = 'Monthly Pending Bills';
        $pendingCardTooltip = 'Recurring monthly bills that are still pending or overdue.';
        $collectionCardTitle = 'Monthly Collection Rate';
        $collectionCardSubtitle = 'Recurring monthly billing only. Registration fees are excluded.';
        $registrationCardTitle = 'Registration Revenue';
        $registrationCardTooltip = 'Registration fees billed and collected outside recurring monthly billing.';
    }

    // Build simple monthly aggregates for charts (last 12 months across bills & payments)
    $billAgg = [];
    $paymentAgg = [];
    $registrationBillAgg = [];
    $registrationPaymentAgg = [];
    $usageAgg = [];

    if ($isAdmin) {
        // System-wide bills aggregated by month
        $sqlBills = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount
                 FROM bills b
                 WHERE {$monthlyBillPredicateSql}
                 GROUP BY ym
                 ORDER BY ym ASC
                 LIMIT 24";
        $stmtBA = $db->query($sqlBills);
    } else {
        $sqlBills = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount
                 FROM bills b
                 WHERE user_id = :uid
                 AND {$monthlyBillPredicateSql}
                 GROUP BY ym
                 ORDER BY ym ASC
                 LIMIT 24";
        $stmtBA = $db->prepare($sqlBills);
        $stmtBA->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmtBA->execute();
    }

    if (!empty($stmtBA)) {
        while ($row = $stmtBA->fetch(PDO::FETCH_ASSOC)) {
            $billAgg[$row['ym']] = (float)$row['total_amount'];
        }
    }

    if ($isAdmin) {
        $sqlPay = "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym,
                           SUM(amount) AS total_amount
                    FROM payments p
                    WHERE {$monthlyPaymentPredicateSql}
                    GROUP BY ym
                    ORDER BY ym ASC
                LIMIT 24";
        $stmtPA = $db->query($sqlPay);
    } else {
        $sqlPay = "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym,
                           SUM(amount) AS total_amount
                    FROM payments p
                    WHERE user_id = :uid
                    AND {$monthlyPaymentPredicateSql}
                    GROUP BY ym
                    ORDER BY ym ASC
                LIMIT 24";
        $stmtPA = $db->prepare($sqlPay);
        $stmtPA->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmtPA->execute();
    }

    if (!empty($stmtPA)) {
        while ($row = $stmtPA->fetch(PDO::FETCH_ASSOC)) {
            $paymentAgg[$row['ym']] = (float)$row['total_amount'];
        }
    }

    if ($isAdmin) {
        $sqlRegistrationBills = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount
                    FROM bills b
                    WHERE {$registrationBillPredicateSql}
                    GROUP BY ym
                    ORDER BY ym ASC
                    LIMIT 24";
        $stmtRBA = $db->query($sqlRegistrationBills);

        if (!empty($stmtRBA)) {
            while ($row = $stmtRBA->fetch(PDO::FETCH_ASSOC)) {
                $registrationBillAgg[$row['ym']] = (float)$row['total_amount'];
            }
        }

        $sqlRegistrationPayments = "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym,
                           SUM(amount) AS total_amount
                    FROM payments p
                    WHERE {$registrationPaymentPredicateSql}
                    GROUP BY ym
                    ORDER BY ym ASC
                    LIMIT 24";
        $stmtRPA = $db->query($sqlRegistrationPayments);

        if (!empty($stmtRPA)) {
            while ($row = $stmtRPA->fetch(PDO::FETCH_ASSOC)) {
                $registrationPaymentAgg[$row['ym']] = (float)$row['total_amount'];
            }
        }
    }

    // Optional: aggregate meter readings as usage (difference between consecutive readings)
    $readingSql = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, MAX(current_reading) AS max_reading, MIN(current_reading) AS min_reading
                   FROM meter_readings" . ($isAdmin ? "" : " WHERE user_id = :uid") . "
                   GROUP BY ym
                   ORDER BY ym ASC
                   LIMIT 24";

    if ($isAdmin) {
        $stmtUA = $db->query($readingSql);
    } else {
        $stmtUA = $db->prepare($readingSql);
        $stmtUA->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmtUA->execute();
    }

    if (!empty($stmtUA)) {
        while ($row = $stmtUA->fetch(PDO::FETCH_ASSOC)) {
            $ym = $row['ym'];
            $max = (float)$row['max_reading'];
            $min = (float)$row['min_reading'];
            $usageAgg[$ym] = max(0, $max - $min);
        }
    }

    // Merge months from all series and build aligned arrays
    $allMonths = array_unique(array_merge(
        array_keys($billAgg),
        array_keys($paymentAgg),
        array_keys($registrationBillAgg),
        array_keys($registrationPaymentAgg),
        array_keys($usageAgg)
    ));
    sort($allMonths);

    $chartLabels = [];
    $chartBills = [];
    $chartPayments = [];
    $chartRegistrationBills = [];
    $chartRegistrationPayments = [];
    $chartUsage = [];

    foreach ($allMonths as $ym) {
        // Convert YYYY-MM to a nicer label like "Mar 2025"
        $dt = DateTime::createFromFormat('Y-m', $ym) ?: null;
        $label = $dt ? $dt->format('M Y') : $ym;
        $chartLabels[] = $label;
        $chartBills[] = isset($billAgg[$ym]) ? $billAgg[$ym] : 0;
        $chartPayments[] = isset($paymentAgg[$ym]) ? $paymentAgg[$ym] : 0;
        $chartRegistrationBills[] = isset($registrationBillAgg[$ym]) ? $registrationBillAgg[$ym] : 0;
        $chartRegistrationPayments[] = isset($registrationPaymentAgg[$ym]) ? $registrationPaymentAgg[$ym] : 0;
        $chartUsage[] = isset($usageAgg[$ym]) ? $usageAgg[$ym] : 0;
    }

    // Derived KPIs
    $denom = $total_paid + $total_unpaid;
    if ($denom > 0) {
        $collection_rate = round(($total_paid / $denom) * 100, 1);
        $collectionCardValue = number_format($collection_rate, 1) . '%';
    } elseif ($isAdmin) {
        $collectionCardValue = 'No bills yet';
        $collectionCardSubtitle = 'Recurring monthly billing only. The rate will appear after the first monthly bill is issued.';
    } else {
        $collectionCardValue = 'No bills yet';
        $collectionCardSubtitle = 'Your collection rate will appear after your first bill is issued.';
    }

    if (!empty($chartUsage)) {
        $current_usage = (float)end($chartUsage);
        if (count($chartUsage) > 1) {
            $previous_usage = (float)$chartUsage[count($chartUsage) - 2];
        }
    }

    if ($isAdmin) {
        $stmtUsers = $db->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'customer'");
        if ($stmtUsers) {
            $rowUsers = $stmtUsers->fetch(PDO::FETCH_ASSOC);
            if ($rowUsers && isset($rowUsers['cnt'])) {
                $total_users = (int)$rowUsers['cnt'];
            }
        }
    } else {
        if (!empty($recentPayments)) {
            $firstPayment = $recentPayments[0];
            $last_payment_amount = isset($firstPayment['amount']) ? (float)$firstPayment['amount'] : null;
            $last_payment_date = !empty($firstPayment['paid_at']) ? $firstPayment['paid_at'] : null;
        }
    }
}
?>

<div class="container-fluid mt-4 admin-shell dashboard-page">
    <div class="row">
        <div class="col-md-12">
            <?php $twoFactorEnabled = !empty($_SESSION['user_data']['two_factor_enabled']); ?>
            <div class="dash-banner">
                <!-- Background layers -->
                <div class="dash-banner-bg" aria-hidden="true">
                    <div class="dash-banner-grid"></div>
                    <div class="dash-banner-blob dash-banner-blob--a"></div>
                    <div class="dash-banner-blob dash-banner-blob--b"></div>
                    <i class="bi bi-speedometer2 dash-banner-watermark"></i>
                </div>
                <!-- Left: identity + title -->
                <div class="dash-banner-body">
                    <div class="dash-banner-eyebrow">
                        <span class="dash-banner-ops-chip">
                            <i class="bi bi-stars" aria-hidden="true"></i>
                            Operations Overview
                        </span>
                        <?php if ($isAdmin): ?>
                        <span class="dash-banner-role-chip">
                            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
                            System Administrator
                        </span>
                        <?php endif; ?>
                    </div>
                    <h2 class="dash-banner-title">Dashboard</h2>
                    <p class="dash-banner-welcome">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?></strong></p>
                </div>
                <!-- Right: 2FA + actions -->
                <div class="dash-banner-actions">
                    <?php if ($twoFactorEnabled): ?>
                        <div class="dash-banner-2fa-badge">
                            <span class="dash-banner-2fa-dot" aria-hidden="true"></span>
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            Two-step verification is <strong>enabled</strong>
                        </div>
                    <?php endif; ?>
                    <div class="dash-banner-btns">
                        <?php if ($twoFactorEnabled): ?>
                        <a href="/profile" class="btn btn-sm dash-banner-btn-manage">
                            <i class="bi bi-sliders" aria-hidden="true"></i> Manage
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm dash-banner-btn-terms" data-bs-toggle="modal" data-bs-target="#termsModal">
                            <i class="bi bi-info-circle" aria-hidden="true"></i> Community Rules &amp; Terms
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4 dashboard-summary-grid g-3">
        <div class="col-md-6 col-xl-3">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-primary dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($balanceCardTooltip); ?>">
                    <div class="card-body">
                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($balanceCardTitle); ?></h5>
                        <p class="card-text display-6 mb-0">Ksh <?php echo number_format($total_unpaid, 2); ?></p>
                        <?php if ($isAdmin): ?>
                            <p class="mb-0 small opacity-75">Recurring monthly bills still open.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=payments&payment_status=completed' : '/bills?filter=paid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-success dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($paidCardTooltip); ?>">
                    <div class="card-body">
                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($paidCardTitle); ?></h5>
                        <p class="card-text display-6 mb-0">Ksh <?php echo number_format($total_paid, 2); ?></p>
                        <?php if ($isAdmin): ?>
                            <p class="mb-0 small opacity-75">Recurring monthly bill collections only.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-2">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-warning dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($pendingCardTooltip); ?>">
                    <div class="card-body">
                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($pendingCardTitle); ?></h5>
                        <p class="card-text display-6 mb-0"><?php echo $pending_count; ?></p>
                        <?php if ($isAdmin): ?>
                            <p class="mb-0 small text-dark opacity-75">Pending monthly invoices only.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <?php if ($isAdmin): ?>
        <div class="col-md-6 col-xl-2">
            <a href="/reports?report_scope=billing" class="text-decoration-none">
                <div class="card text-white dashboard-stat-card dashboard-stat-card-registration" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($registrationCardTooltip); ?>" style="background:#0f766e !important; background-image:none !important; color:#ffffff !important; border-color:transparent !important;">
                    <div class="card-body" style="background:transparent !important;">
                        <h5 class="card-title mb-1" style="color:#d1fae5 !important; -webkit-text-fill-color:#d1fae5 !important;"><?php echo htmlspecialchars($registrationCardTitle); ?></h5>
                        <p class="card-text display-6 mb-0" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; text-shadow:none !important;">Ksh <?php echo number_format($registration_collected_total, 2); ?></p>
                        <p class="mb-0 small dashboard-registration-card-note" style="color:#d1fae5 !important; -webkit-text-fill-color:#d1fae5 !important; text-shadow:none !important;">Billed: Ksh <?php echo number_format($registration_billed_total, 2); ?> across <?php echo number_format($registration_bills_count); ?> bill(s).</p>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <div class="col-md-6 col-xl-<?php echo $isAdmin ? '2' : '3'; ?>">
            <?php if ($isAdmin): ?>
                <?php
                    $auditCardClass = 'bg-success';
                    $auditCardTitle = 'Billing Audit';
                    $auditCardValue = 'Healthy';
                    $auditCardHint = 'Latest integrity audit is fully clear.';

                    if ($auditStatus === null) {
                        $auditCardClass = 'bg-secondary';
                        $auditCardValue = 'Not Run';
                        $auditCardHint = 'Run the billing audit from Reports to populate this status.';
                    } elseif (($auditStatus['status'] ?? '') === 'failure') {
                        $auditCardClass = 'bg-danger';
                        $auditCardValue = 'Failures';
                        $auditCardHint = (string)($auditStatus['summary_line'] ?? 'Audit failures need attention.');
                    } elseif (($auditStatus['status'] ?? '') === 'warning') {
                        $auditCardClass = 'bg-warning';
                        $auditCardValue = 'Warnings';
                        $auditCardHint = (string)($auditStatus['summary_line'] ?? 'Audit warnings need review.');
                    }
                ?>
                <a href="/reports#billingIntegrityTools" class="text-decoration-none">
                    <div class="card text-white <?php echo $auditCardClass; ?> dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="Latest persisted billing integrity audit result.">
                        <div class="card-body">
                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($auditCardTitle); ?></h5>
                            <p class="card-text h4 mb-1"><?php echo htmlspecialchars($auditCardValue); ?></p>
                            <p class="mb-0 small opacity-75"><?php echo htmlspecialchars($auditCardHint); ?></p>
                        </div>
                    </div>
                </a>
            <?php else: ?>
                <div class="card text-white bg-info dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="Your unique water account identifier.">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Account Number</h5>
                        <p class="card-text h4 mb-0"><?php echo htmlspecialchars($_SESSION['user_data']['account_number'] ?? 'N/A'); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin && $auditStatus !== null && in_array((string)($auditStatus['status'] ?? ''), ['failure', 'warning'], true)): ?>
        <?php $auditGeneratedAt = !empty($auditStatus['generated_at']) ? date('d M Y, H:i', strtotime((string)$auditStatus['generated_at'])) : 'Unknown'; ?>
        <div class="row mt-2">
            <div class="col-12">
                <div class="alert <?php echo ($auditStatus['status'] ?? '') === 'failure' ? 'alert-danger' : (($auditStatus['status'] ?? '') === 'warning' ? 'alert-warning' : 'alert-success'); ?> d-flex justify-content-between align-items-start flex-wrap gap-2 mb-0">
                    <div>
                        <strong>Billing integrity audit:</strong> <?php echo htmlspecialchars((string)($auditStatus['summary_line'] ?? 'No summary available.')); ?>
                        <div class="small mt-1">Last run: <?php echo htmlspecialchars($auditGeneratedAt); ?></div>
                    </div>
                    <a href="/reports#billingIntegrityTools" class="btn btn-sm btn-outline-dark">
                        <i class="bi bi-shield-check me-1"></i> Open Integrity Tools
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row mt-3">
        <div class="col-md-6 col-xl-<?php echo $isAdmin ? '4' : '3'; ?> mb-3">
            <div class="card metric-card metric-card-primary dashboard-insight-card dashboard-insight-card-collection h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Percentage of billed amounts that have been collected.">
                <div class="card-body">
                    <h6 class="card-title text-uppercase small mb-2"><?php echo htmlspecialchars($collectionCardTitle); ?></h6>
                    <p class="<?php echo $collectionCardValue === 'No bills yet' ? 'dashboard-insight-empty-value h5' : 'h4'; ?> mb-2"><?php echo htmlspecialchars($collectionCardValue); ?></p>
                    <p class="mb-0 small dashboard-insight-note"><?php echo htmlspecialchars($collectionCardSubtitle); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-<?php echo $isAdmin ? '4' : '3'; ?> mb-3">
            <div class="card metric-card metric-card-success dashboard-insight-card dashboard-insight-card-usage h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Latest estimated water usage based on meter readings.">
                <div class="card-body">
                    <h6 class="card-title text-uppercase small mb-2">Latest Usage</h6>
                    <?php if ($current_usage > 0): ?>
                        <p class="h4 mb-1"><?php echo number_format($current_usage, 2); ?> <span class="fs-6">units</span></p>
                        <?php if ($previous_usage > 0): ?>
                            <p class="mb-0 small dashboard-insight-note">Previous month: <?php echo number_format($previous_usage, 2); ?> units</p>
                        <?php else: ?>
                            <p class="mb-0 small dashboard-insight-note">No data for previous month.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="dashboard-insight-empty-value h5 mb-2">No data</p>
                        <p class="mb-0 small dashboard-insight-note">Submit meter readings to track your usage.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-<?php echo $isAdmin ? '4' : '3'; ?> mb-3">
            <div class="card metric-card metric-card-danger dashboard-insight-card dashboard-insight-card-customers h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Quick view of your recent activity.">
                <div class="card-body">
                    <?php if ($isAdmin): ?>
                        <h6 class="card-title text-uppercase small mb-2">Registered Customers</h6>
                        <p class="h4 mb-1"><?php echo $total_users !== null ? number_format($total_users) : 'N/A'; ?></p>
                        <p class="mb-0 small dashboard-insight-note">Customer accounts only (excludes staff).</p>
                    <?php else: ?>
                        <h6 class="card-title text-uppercase small mb-2">Last Payment</h6>
                        <?php if ($last_payment_amount !== null): ?>
                            <p class="h5 mb-1">Ksh <?php echo number_format($last_payment_amount, 2); ?></p>
                            <?php if ($last_payment_date !== null): ?>
                                <p class="mb-0 small dashboard-insight-note">On <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($last_payment_date))); ?></p>
                            <?php else: ?>
                                <p class="mb-0 small dashboard-insight-note">Date not available.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="dashboard-insight-empty-value h5 mb-2">No payments yet</p>
                            <p class="mb-0 small dashboard-insight-note">Your first payment will appear here.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!$isAdmin): ?>
        <div class="col-md-6 col-xl-3 mb-3">
            <a href="/registration-payment" class="text-decoration-none">
                <div class="card metric-card dashboard-insight-card dashboard-insight-card-registration-balance h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Your own remaining one-time registration fee balance.">
                    <div class="card-body">
                        <h6 class="card-title text-uppercase small mb-2">Your Registration Balance</h6>
                        <p class="<?php echo $user_registration_outstanding > 0.01 ? 'h4' : 'dashboard-insight-empty-value h5'; ?> mb-2"><?php echo htmlspecialchars($user_registration_balance_value); ?></p>
                        <p class="mb-0 small dashboard-insight-note"><?php echo htmlspecialchars($user_registration_balance_note); ?></p>
                    </div>
                </div>
            </a>
        </div>
        <?php if ($user_wallet_balance > 0.01): ?>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card metric-card metric-card-success dashboard-insight-card h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Overpayment on your account, automatically applied to your next bill.">
                <div class="card-body">
                    <h6 class="card-title text-uppercase small mb-2">Credit Balance (Overpayment)</h6>
                    <p class="h4 mb-2">Ksh <?php echo number_format($user_wallet_balance, 2); ?></p>
                    <p class="mb-0 small dashboard-insight-note">This will be automatically applied to your next bill.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="row mt-4">
        <div class="col-lg-9 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Billing & Payments Trend</h5>
                        <small class="text-muted">
                            <?php echo $isAdmin ? 'System-wide totals by month' : 'Your bills and payments by month'; ?>
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Compares total billed amounts against payments for each month."></i>
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label for="dashboardRange" class="form-label mb-0 small text-muted" style="white-space:nowrap;">Range</label>
                        <select id="dashboardRange" class="form-select form-select-sm" style="min-width: 140px;">
                            <option value="3">Last 3 months</option>
                            <option value="6" selected>Last 6 months</option>
                            <option value="12">Last 12 months</option>
                            <option value="all">All available</option>
                        </select>
                        <button type="button" id="exportDashboardCsvBtn" class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" title="Download a CSV of the currently selected period.">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($chartLabels)): ?>
                        <div class="dashboard-chart-container">
                            <canvas id="billingTrendsChart"></canvas>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Once billing and payment history is available, a monthly trend chart will be shown here.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Status Breakdown <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Shows how much is already paid compared to what is still outstanding."></i></h5>
                </div>
                <div class="card-body">
                    <?php if ($total_paid > 0 || $total_unpaid > 0 || ($isAdmin && ($registration_collected_total > 0 || $registration_billed_total > 0))): ?>
                        <div class="mb-3" style="max-height:240px;">
                            <canvas id="statusPieChart" height="200"></canvas>
                        </div>
                        <ul class="list-unstyled small mb-0 dashboard-status-breakdown-list">
                            <li><span class="dashboard-status-swatch dashboard-status-swatch-collected"></span> <?php echo $isAdmin ? 'Monthly collected' : 'Paid'; ?>: Ksh <?php echo number_format($total_paid, 2); ?></li>
                            <li><span class="dashboard-status-swatch dashboard-status-swatch-outstanding"></span> <?php echo $isAdmin ? 'Monthly outstanding' : 'Unpaid'; ?>: Ksh <?php echo number_format($total_unpaid, 2); ?></li>
                            <li class="mt-1"><span class="dashboard-status-swatch dashboard-status-swatch-pending"></span> <?php echo $isAdmin ? 'Monthly pending bills' : 'Pending bills'; ?>: Ksh <?php echo number_format($pending_amount, 2); ?></li>
                            <li><span class="dashboard-status-swatch dashboard-status-swatch-overdue"></span> <?php echo $isAdmin ? 'Monthly overdue bills' : 'Overdue bills'; ?>: Ksh <?php echo number_format($overdue_amount, 2); ?></li>
                            <?php if ($isAdmin && ($registration_billed_total > 0 || $registration_collected_total > 0)): ?>
                                <li class="mt-1"><span class="dashboard-status-swatch dashboard-status-swatch-registration-billed"></span> Registration billed: Ksh <?php echo number_format($registration_billed_total, 2); ?></li>
                                <li><span class="dashboard-status-swatch dashboard-status-swatch-registration-collected"></span> Registration collected: Ksh <?php echo number_format($registration_collected_total, 2); ?></li>
                                <li><span class="dashboard-status-swatch dashboard-status-swatch-registration-outstanding"></span> Registration outstanding: Ksh <?php echo number_format($registration_outstanding_total, 2); ?></li>
                            <?php endif; ?>
                        </ul>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Status breakdown will appear here once there are bills and payments.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="row mt-1">
        <div class="col-lg-9 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Registration &amp; Payments Trend</h5>
                        <small class="text-muted">
                            System-wide totals by month
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Compares registration fees billed against registration payments collected for each month."></i>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($chartLabels) && (array_sum($chartRegistrationBills) > 0 || array_sum($chartRegistrationPayments) > 0)): ?>
                        <div class="dashboard-chart-container">
                            <canvas id="registrationTrendsChart"></canvas>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Once registration billing and payment history is available, a registration trend chart will be shown here.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Status Breakdown <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Shows how much registration revenue is already collected compared to what is still outstanding."></i></h5>
                </div>
                <div class="card-body">
                    <?php if ($registration_billed_total > 0 || $registration_collected_total > 0): ?>
                        <div class="mb-3" style="max-height:240px;">
                            <canvas id="registrationStatusPieChart" height="200"></canvas>
                        </div>
                        <ul class="list-unstyled small mb-0 dashboard-status-breakdown-list">
                            <li><span class="dashboard-status-swatch dashboard-status-swatch-registration-billed"></span> Registration billed: Ksh <?php echo number_format($registration_billed_total, 2); ?></li>
                            <li><span class="dashboard-status-swatch dashboard-status-swatch-registration-collected"></span> Registration collected: Ksh <?php echo number_format($registration_collected_total, 2); ?></li>
                            <li class="mt-1"><span class="dashboard-status-swatch dashboard-status-swatch-registration-outstanding"></span> Registration outstanding: Ksh <?php echo number_format($registration_outstanding_total, 2); ?></li>
                        </ul>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Registration status breakdown will appear here once there are registration bills and payments.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mt-1">
        <div class="col-lg-8 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Water Usage</h5>
                        <small class="text-muted"><?php echo $isAdmin ? 'Estimated total system consumption by month' : 'Your estimated monthly consumption (based on readings)'; ?>
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Uses the difference between highest and lowest meter readings in each month as an approximate usage."></i>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($chartLabels) && array_sum($chartUsage) > 0): ?>
                        <div class="dashboard-chart-container">
                            <canvas id="usageChart"></canvas>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Once meter readings are submitted and approved, a water usage chart will appear here.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Activity</h5>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#recentActivityBody" aria-expanded="false" aria-controls="recentActivityBody">
                        Show / Hide
                    </button>
                </div>
                <div id="recentActivityBody" class="collapse">
                    <div class="card-body">
                        <?php if (empty($recentPayments) && empty($recentBills)): ?>
                            <p class="mb-1">No recent activity found.</p>
                            <p class="mb-0 text-muted">Once you start using the system, your recent payments and bills will appear here.</p>
                        <?php else: ?>
                            <?php if (!empty($recentPayments)): ?>
                                <h6 class="mb-2">Recent Payments</h6>
                                <ul class="list-group mb-3">
                                    <?php foreach ($recentPayments as $p): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-semibold">KES <?php echo number_format((float)$p['amount'], 2); ?></div>
                                                <small class="text-muted">
                                                    <?php echo !empty($p['mpesa_receipt']) ? 'Ref: ' . htmlspecialchars($p['mpesa_receipt']) . ' · ' : ''; ?>
                                                    <?php echo !empty($p['paid_at']) ? htmlspecialchars(date('d-m-Y H:i', strtotime($p['paid_at']))) : ''; ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php echo $p['status'] === 'completed' ? 'success' : ($p['status'] === 'failed' ? 'danger' : 'secondary'); ?>">
                                                <?php echo htmlspecialchars(ucfirst($p['status'])); ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($recentBills)): ?>
                                <h6 class="mb-2">Recent Bills</h6>
                                <ul class="list-group mb-0">
                                    <?php foreach ($recentBills as $b): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-semibold">Bill for <?php echo htmlspecialchars(date('F Y', strtotime($b['billing_month']))); ?></div>
                                                <small class="text-muted">
                                                    Amount: KES <?php echo number_format((float)$b['amount'], 2); ?>
                                                    <?php if (!empty($b['due_date'])): ?> · Due: <?php echo htmlspecialchars($b['due_date']); ?><?php endif; ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php echo $b['status'] === 'paid' ? 'success' : ($b['status'] === 'overdue' ? 'danger' : 'warning'); ?>">
                                                <?php echo htmlspecialchars(ucfirst($b['status'])); ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-1">
        <div class="col-12 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Collection Rate by Month</h5>
                        <small class="text-muted">
                            How much of the billed amounts have been paid each month.
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Calculated as payments divided by billed amounts for each month."></i>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($chartLabels) && array_sum($chartBills) > 0): ?>
                        <div class="dashboard-chart-container">
                            <canvas id="collectionRateChart"></canvas>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Once there is enough billing and payment history, a collection rate chart will appear here.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
// Expose aggregated data for the dashboard charts to the frontend
if (!empty($chartLabels)) {
    $chartPayload = array(
        'labels' => $chartLabels,
        'bills' => $chartBills,
        'payments' => $chartPayments,
        'registrationBills' => $chartRegistrationBills,
        'registrationPayments' => $chartRegistrationPayments,
        'usage' => $chartUsage,
        'totals' => array(
            'paid' => $total_paid,
            'unpaid' => $total_unpaid,
            'registration_billed' => $registration_billed_total,
            'registration_collected' => $registration_collected_total,
            'registration_outstanding' => $registration_outstanding_total,
        ),
    );
    echo '<script>window.DASHBOARD_CHART_DATA = ' . json_encode($chartPayload) . ';</script>';
}

require_once __DIR__ . '/../templates/footer.php';
?>
