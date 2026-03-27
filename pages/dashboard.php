<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

$page_title = "Dashboard";
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/MeterReading.php';
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
$recentBills = [];
$recentPayments = [];

$database = new Database();
$db = $database->getConnection();
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
    }

    $total_paid = (float)($summary['total_paid'] ?? 0);
    $total_unpaid = (float)($summary['total_unpaid'] ?? 0);
    $pending_amount = (float)($summary['pending_amount'] ?? 0);
    $overdue_amount = (float)($summary['overdue_amount'] ?? 0);
    $pending_count = (int)($summary['pending_count'] ?? 0);

    // Build simple monthly aggregates for charts (last 12 months across bills & payments)
    $billAgg = [];
    $paymentAgg = [];
    $usageAgg = [];

    if ($isAdmin) {
        // System-wide bills aggregated by month
        $sqlBills = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount
                 FROM bills
                 GROUP BY ym
                 ORDER BY ym ASC
                 LIMIT 24";
        $stmtBA = $db->query($sqlBills);
    } else {
        $sqlBills = "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount
                 FROM bills
                 WHERE user_id = :uid
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
                    FROM payments
                    GROUP BY ym
                    ORDER BY ym ASC
                LIMIT 24";
        $stmtPA = $db->query($sqlPay);
    } else {
        $sqlPay = "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym,
                           SUM(amount) AS total_amount
                    FROM payments
                    WHERE user_id = :uid
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
    $allMonths = array_unique(array_merge(array_keys($billAgg), array_keys($paymentAgg), array_keys($usageAgg)));
    sort($allMonths);

    $chartLabels = [];
    $chartBills = [];
    $chartPayments = [];
    $chartUsage = [];

    foreach ($allMonths as $ym) {
        // Convert YYYY-MM to a nicer label like "Mar 2025"
        $dt = DateTime::createFromFormat('Y-m', $ym) ?: null;
        $label = $dt ? $dt->format('M Y') : $ym;
        $chartLabels[] = $label;
        $chartBills[] = isset($billAgg[$ym]) ? $billAgg[$ym] : 0;
        $chartPayments[] = isset($paymentAgg[$ym]) ? $paymentAgg[$ym] : 0;
        $chartUsage[] = isset($usageAgg[$ym]) ? $usageAgg[$ym] : 0;
    }

    // Derived KPIs
    $denom = $total_paid + $total_unpaid;
    if ($denom > 0) {
        $collection_rate = round(($total_paid / $denom) * 100, 1);
    }

    if (!empty($chartUsage)) {
        $current_usage = (float)end($chartUsage);
        if (count($chartUsage) > 1) {
            $previous_usage = (float)$chartUsage[count($chartUsage) - 2];
        }
    }

    if ($isAdmin) {
        $stmtUsers = $db->query("SELECT COUNT(*) AS cnt FROM users");
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
            <div class="admin-page-header dashboard-hero-header">
                <div class="dashboard-hero-main">
                    <p class="dashboard-hero-eyebrow mb-2"><i class="bi bi-stars"></i> Operations Overview</p>
                    <h2 class="mb-1">Dashboard</h2>
                    <p class="admin-page-subtitle mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?>.</p>
                </div>
                <div class="dashboard-hero-actions">
                    <?php if ($twoFactorEnabled): ?>
                        <div class="dashboard-hero-chip text-success">
                            <i class="bi bi-shield-check"></i>
                            Two-step verification is <strong>enabled</strong>
                        </div>
                        <a href="/profile" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-sliders"></i> Manage
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#termsModal">
                        <i class="bi bi-info-circle"></i> Community Rules &amp; Terms
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-primary dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="Total amount currently outstanding on your account.">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Account Balance</h5>
                        <p class="card-text display-6 mb-0">Ksh <?php echo number_format($total_unpaid, 2); ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=payments&payment_status=completed' : '/bills?filter=paid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-success dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="All payments successfully recorded in the system.">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Total Paid</h5>
                        <p class="card-text display-6 mb-0">Ksh <?php echo number_format($total_paid, 2); ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-warning dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="Number of bills that are not yet fully paid.">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Pending Bills</h5>
                        <p class="card-text display-6 mb-0"><?php echo $pending_count; ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info dashboard-stat-card" data-bs-toggle="tooltip" data-bs-placement="top" title="Your unique water account identifier.">
                <div class="card-body">
                    <h5 class="card-title mb-1">Account Number</h5>
                    <p class="card-text h4 mb-0"><?php echo htmlspecialchars($_SESSION['user_data']['account_number'] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-4 mb-3">
            <div class="card metric-card metric-card-primary h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Percentage of billed amounts that have been collected.">
                <div class="card-body">
                    <h6 class="card-title text-muted text-uppercase small mb-1">Collection Rate</h6>
                    <p class="h4 mb-1"><?php
                        if (($total_paid + $total_unpaid) > 0) {
                            echo number_format($collection_rate, 1) . '%';
                        } else {
                            echo 'N/A';
                        }
                    ?></p>
                    <p class="mb-0 small text-muted">Overall ratio of payments to total billed.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card metric-card metric-card-success h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Latest estimated water usage based on meter readings.">
                <div class="card-body">
                    <h6 class="card-title text-muted text-uppercase small mb-1">Latest Usage</h6>
                    <?php if ($current_usage > 0): ?>
                        <p class="h4 mb-1"><?php echo number_format($current_usage, 2); ?> <span class="fs-6">units</span></p>
                        <?php if ($previous_usage > 0): ?>
                            <p class="mb-0 small text-muted">Previous month: <?php echo number_format($previous_usage, 2); ?> units</p>
                        <?php else: ?>
                            <p class="mb-0 small text-muted">No data for previous month.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="h5 mb-1">No data</p>
                        <p class="mb-0 small text-muted">Submit meter readings to track your usage.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card metric-card metric-card-danger h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Quick view of your recent activity.">
                <div class="card-body">
                    <?php if ($isAdmin): ?>
                        <h6 class="card-title text-muted text-uppercase small mb-1">Registered Customers</h6>
                        <p class="h4 mb-1"><?php echo $total_users !== null ? number_format($total_users) : 'N/A'; ?></p>
                        <p class="mb-0 small text-muted">Total user accounts in the system.</p>
                    <?php else: ?>
                        <h6 class="card-title text-muted text-uppercase small mb-1">Last Payment</h6>
                        <?php if ($last_payment_amount !== null): ?>
                            <p class="h5 mb-1">Ksh <?php echo number_format($last_payment_amount, 2); ?></p>
                            <?php if ($last_payment_date !== null): ?>
                                <p class="mb-0 small text-muted">On <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($last_payment_date))); ?></p>
                            <?php else: ?>
                                <p class="mb-0 small text-muted">Date not available.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="h5 mb-1">No payments yet</p>
                            <p class="mb-0 small text-muted">Your first payment will appear here.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
                    <?php if ($total_paid > 0 || $total_unpaid > 0): ?>
                        <div class="mb-3" style="max-height:240px;">
                            <canvas id="statusPieChart" height="200"></canvas>
                        </div>
                        <ul class="list-unstyled small mb-0">
                            <li><span class="badge bg-success me-1">&nbsp;</span> Paid: Ksh <?php echo number_format($total_paid, 2); ?></li>
                            <li><span class="badge bg-primary me-1">&nbsp;</span> Unpaid: Ksh <?php echo number_format($total_unpaid, 2); ?></li>
                            <li class="mt-1"><span class="badge bg-warning text-dark me-1">&nbsp;</span> Pending bills: Ksh <?php echo number_format($pending_amount, 2); ?></li>
                            <li><span class="badge bg-danger me-1">&nbsp;</span> Overdue bills: Ksh <?php echo number_format($overdue_amount, 2); ?></li>
                        </ul>
                    <?php else: ?>
                        <p class="mb-0 text-muted small">Status breakdown will appear here once there are bills and payments.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

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
        'usage' => $chartUsage,
        'totals' => array(
            'paid' => $total_paid,
            'unpaid' => $total_unpaid,
        ),
    );
    echo '<script>window.DASHBOARD_CHART_DATA = ' . json_encode($chartPayload) . ';</script>';
}

require_once __DIR__ . '/../templates/footer.php';
?>
