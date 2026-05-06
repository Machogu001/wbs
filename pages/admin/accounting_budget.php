<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Accounting.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_accounting'))) {
    header('Location: /login');
    exit;
}
if (empty($_SESSION['accounting_csrf'])) {
    $_SESSION['accounting_csrf'] = bin2hex(random_bytes(32));
}
$accounting = new Accounting($db);

$message = '';
$messageType = 'success';
if (!empty($_SESSION['accounting_flash']) && is_array($_SESSION['accounting_flash'])) {
    $message = (string)($_SESSION['accounting_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['accounting_flash']['type'] ?? 'success');
    unset($_SESSION['accounting_flash']);
}

// POST: save_budget
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['accounting_csrf'], (string)$_POST['csrf_token'])) {
        $_SESSION['accounting_flash'] = ['message' => 'Invalid CSRF token.', 'type' => 'danger'];
        header('Location: /accounting/budget');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_budget') {
        $budgetAccountId = (int)($_POST['budget_account_id'] ?? 0);
        $financialYear   = preg_replace('/[^0-9]/', '', (string)($_POST['financial_year'] ?? date('Y')));
        $budgetMode      = (string)($_POST['budget_mode'] ?? 'monthly');
        $elimDecimals    = !empty($_POST['eliminate_decimals']);
        $months = [];
        if ($budgetMode === 'monthly') {
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = (float)($_POST['month_' . $m] ?? 0);
            }
        } elseif ($budgetMode === 'quarterly') {
            for ($q = 1; $q <= 4; $q++) {
                $qVal = (float)($_POST['quarter_' . $q] ?? 0);
                $perMonth = $elimDecimals ? floor($qVal / 3) : round($qVal / 3, 2);
                $startMonth = ($q - 1) * 3 + 1;
                for ($m = $startMonth; $m < $startMonth + 3; $m++) {
                    $months[$m] = $perMonth;
                }
            }
        } elseif ($budgetMode === 'yearly') {
            $yearlyBudget = (float)($_POST['yearly_budget'] ?? 0);
            $perMonth = $elimDecimals ? floor($yearlyBudget / 12) : round($yearlyBudget / 12, 2);
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = $perMonth;
            }
        }
        try {
            $accounting->saveBudget($budgetAccountId, $financialYear, $months);
            $_SESSION['accounting_flash'] = ['message' => 'Budget saved.', 'type' => 'success'];
        } catch (Throwable $e) {
            $_SESSION['accounting_flash'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
        }
        header('Location: /accounting/budget?budget_year=' . urlencode($financialYear));
        exit;
    }
    header('Location: /accounting/budget');
    exit;
}

// GET data
$budgetYear     = preg_replace('/[^0-9]/', '', (string)($_GET['budget_year'] ?? date('Y')));
$budgetAccounts = [];
try {
    $allAccounts = $accounting->getAccounts();
    foreach ($allAccounts as $acct) {
        if (!empty($acct['is_active'])) {
            $budgetAccounts[] = $acct;
        }
    }
} catch (Throwable $e) { $budgetAccounts = []; }
$budgetVsActual = [];
try { $budgetVsActual = $accounting->getBudgetVsActual($budgetYear); } catch (Throwable $e) { $budgetVsActual = []; }

$is_admin_page = true;
$page_title = 'Budget Planning — Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<?php include __DIR__ . '/../../templates/accounting_styles.php'; ?>
<div class="container-fluid px-4 py-4">

	<!-- Banner -->
	<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
		<div>
			<h2 class="fw-bold mb-1"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Budget Planning</h2>
			<p class="text-muted mb-0">Set monthly, quarterly, or annual budgets per account and track variance</p>
		</div>
		<a href="/accounting" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Overview</a>
	</div>

	<?php include __DIR__ . '/../../templates/accounting_subnav.php'; ?>

	<?php if ($message): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show mb-4" role="alert">
			<?php echo htmlspecialchars($message); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php endif; ?>

	<div class="card admin-table-card mb-4" id="budget">
		<div class="card-header">
			<h5 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Budget Planning</h5>
		</div>
		<div class="card-body">
			<!-- Year selector -->
			<form method="get" action="/accounting/budget" class="row g-2 align-items-end mb-4">
				<div class="col-auto">
					<label class="form-label mb-1 small fw-semibold">Financial Year</label>
					<input type="number" name="budget_year" class="form-control form-control-sm" value="<?php echo htmlspecialchars($budgetYear); ?>" min="2000" max="2099" style="width:110px">
				</div>
				<div class="col-auto">
					<button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Load</button>
				</div>
			</form>

			<!-- Input mode tabs -->
			<ul class="nav nav-tabs mb-3" id="budgetModeTabs">
				<li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#budgetMonthly">Monthly</a></li>
				<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#budgetQuarterly">Quarterly</a></li>
				<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#budgetYearly">Yearly</a></li>
				<li class="nav-item ms-auto"><a class="nav-link" data-bs-toggle="tab" href="#budgetVsActual">Budget vs Actual</a></li>
			</ul>

			<div class="tab-content">
				<!-- Monthly input -->
				<div class="tab-pane fade show active" id="budgetMonthly">
					<p class="text-muted small">Select an account and enter monthly budget amounts for <?php echo htmlspecialchars($budgetYear); ?>.</p>
					<form method="post" action="/accounting/budget">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="save_budget">
						<input type="hidden" name="budget_mode" value="monthly">
						<input type="hidden" name="financial_year" value="<?php echo htmlspecialchars($budgetYear); ?>">
						<div class="row g-2 mb-3">
							<div class="col-md-4">
								<label class="form-label mb-1 small fw-semibold">Account</label>
								<select name="budget_account_id" class="form-select form-select-sm" required>
									<option value="">— select account —</option>
									<?php foreach ($budgetAccounts as $ba): ?>
										<option value="<?php echo (int)$ba['id']; ?>"><?php echo htmlspecialchars($ba['code'] . ' – ' . $ba['name'] . ' (' . $ba['account_type'] . ')'); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="row g-2 mb-3">
							<?php $monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; ?>
							<?php for ($mi = 1; $mi <= 12; $mi++): ?>
								<div class="col-6 col-sm-4 col-md-3 col-xl-2">
									<label class="form-label mb-1 small"><?php echo $monthNames[$mi-1]; ?></label>
									<input type="number" step="0.01" min="0" name="month_<?php echo $mi; ?>" class="form-control form-control-sm" value="0">
								</div>
							<?php endfor; ?>
						</div>
						<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save Monthly Budget</button>
					</form>
				</div>

				<!-- Quarterly input -->
				<div class="tab-pane fade" id="budgetQuarterly">
					<p class="text-muted small">Enter a quarterly budget; it will be split evenly into 3 monthly amounts.</p>
					<form method="post" action="/accounting/budget">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="save_budget">
						<input type="hidden" name="budget_mode" value="quarterly">
						<input type="hidden" name="financial_year" value="<?php echo htmlspecialchars($budgetYear); ?>">
						<div class="row g-2 mb-3">
							<div class="col-md-4">
								<label class="form-label mb-1 small fw-semibold">Account</label>
								<select name="budget_account_id" class="form-select form-select-sm" required>
									<option value="">— select account —</option>
									<?php foreach ($budgetAccounts as $ba): ?>
										<option value="<?php echo (int)$ba['id']; ?>"><?php echo htmlspecialchars($ba['code'] . ' – ' . $ba['name']); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-auto d-flex align-items-end">
								<div class="form-check form-check-inline mb-0">
									<input class="form-check-input" type="checkbox" name="eliminate_decimals" id="qElimDec">
									<label class="form-check-label small" for="qElimDec">Whole numbers</label>
								</div>
							</div>
						</div>
						<div class="row g-2 mb-3">
							<?php for ($qi = 1; $qi <= 4; $qi++): ?>
								<div class="col-6 col-md-3">
									<label class="form-label mb-1 small">Q<?php echo $qi; ?></label>
									<input type="number" step="0.01" min="0" name="quarter_<?php echo $qi; ?>" class="form-control form-control-sm" value="0">
								</div>
							<?php endfor; ?>
						</div>
						<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save Quarterly Budget</button>
					</form>
				</div>

				<!-- Yearly input -->
				<div class="tab-pane fade" id="budgetYearly">
					<p class="text-muted small">Enter an annual budget; it will be split evenly into 12 monthly amounts.</p>
					<form method="post" action="/accounting/budget">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="save_budget">
						<input type="hidden" name="budget_mode" value="yearly">
						<input type="hidden" name="financial_year" value="<?php echo htmlspecialchars($budgetYear); ?>">
						<div class="row g-2 align-items-end mb-3">
							<div class="col-md-4">
								<label class="form-label mb-1 small fw-semibold">Account</label>
								<select name="budget_account_id" class="form-select form-select-sm" required>
									<option value="">— select account —</option>
									<?php foreach ($budgetAccounts as $ba): ?>
										<option value="<?php echo (int)$ba['id']; ?>"><?php echo htmlspecialchars($ba['code'] . ' – ' . $ba['name']); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-3">
								<label class="form-label mb-1 small fw-semibold">Annual Budget</label>
								<input type="number" step="0.01" min="0" name="yearly_budget" class="form-control form-control-sm" value="0">
							</div>
							<div class="col-auto d-flex align-items-end">
								<div class="form-check form-check-inline mb-0">
									<input class="form-check-input" type="checkbox" name="eliminate_decimals" id="yElimDec">
									<label class="form-check-label small" for="yElimDec">Whole numbers</label>
								</div>
							</div>
							<div class="col-auto">
								<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save</button>
							</div>
						</div>
					</form>
				</div>

				<!-- Budget vs Actual -->
				<div class="tab-pane fade" id="budgetVsActual">
					<?php if (empty($budgetVsActual)): ?>
						<p class="text-muted">No budget data for <?php echo htmlspecialchars($budgetYear); ?>. Enter budgets in Monthly / Quarterly / Yearly tabs first.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm table-bordered align-middle">
								<thead class="table-light">
									<tr>
										<th>Account</th>
										<th class="text-end">Budget</th>
										<th class="text-end">Actual</th>
										<th class="text-end">Variance</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($budgetVsActual as $bva): ?>
										<?php $hasBudget = $bva['budget_total'] != 0 || $bva['actual_total'] != 0; ?>
										<?php if (!$hasBudget) continue; ?>
										<?php $varClass = $bva['variance_total'] >= 0 ? 'text-success' : 'text-danger'; ?>
										<tr>
											<td><span class="badge bg-secondary me-1"><?php echo htmlspecialchars($bva['code']); ?></span><?php echo htmlspecialchars($bva['name']); ?></td>
											<td class="text-end"><?php echo number_format($bva['budget_total'], 2); ?></td>
											<td class="text-end"><?php echo number_format($bva['actual_total'], 2); ?></td>
											<td class="text-end <?php echo $varClass; ?>"><?php echo ($bva['variance_total'] >= 0 ? '+' : '') . number_format($bva['variance_total'], 2); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
