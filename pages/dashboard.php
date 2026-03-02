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
require_once __DIR__ . '/../templates/header.php';

$total_paid = 0.0;
$total_unpaid = 0.0;
$pending_count = 0;
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
    $pending_count = (int)($summary['pending_count'] ?? 0);
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h2>Dashboard</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?>!</p>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/admin/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title">Account Balance</h5>
                        <p class="card-text display-6">Ksh <?php echo number_format($total_unpaid, 2); ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/admin/reports?report_scope=payments&payment_status=completed' : '/bills?filter=paid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Total Paid</h5>
                        <p class="card-text display-6">Ksh <?php echo number_format($total_paid, 2); ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?php echo $isAdmin ? '/admin/reports?report_scope=billing&bill_status=pending_overdue' : '/bills?filter=unpaid'; ?>" class="text-decoration-none">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title">Pending Bills</h5>
                        <p class="card-text display-6"><?php echo $pending_count; ?></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h5 class="card-title">Account Number</h5>
                    <p class="card-text h4"><?php echo htmlspecialchars($_SESSION['user_data']['account_number'] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/pay" class="btn btn-primary btn-lg">
                            <i class="bi bi-credit-card"></i> Pay Bill
                        </a>
                        <a href="/bills" class="btn btn-secondary btn-lg">
                            <i class="bi bi-receipt"></i> View Bills
                        </a>
                        <?php if(!isset($_SESSION['user_data']['role']) || $_SESSION['user_data']['role'] !== 'admin'): ?>
                            <a href="/submit-reading" class="btn btn-outline-primary btn-lg">
                                <i class="bi bi-camera"></i> Submit Meter Reading
                            </a>
                        <?php endif; ?>
                        <a href="/change-password" class="btn btn-outline-secondary btn-lg">
                            <i class="bi bi-key"></i> Change Password
                        </a>
                        <a href="/profile" class="btn btn-info btn-lg">
                            <i class="bi bi-person"></i> My Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
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
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
