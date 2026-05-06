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

// Date range for financial statements
$reconFromDate = isset($_GET['recon_from']) ? (string)$_GET['recon_from'] : date('Y-01-01');
$reconToDate   = isset($_GET['recon_to'])   ? (string)$_GET['recon_to']   : date('Y-m-d');
$reconFromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconFromDate) ? $reconFromDate : date('Y-01-01');
$reconToDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconToDate)   ? $reconToDate   : date('Y-m-d');

// Financial statements
try { $balanceSheet   = $accounting->getBalanceSheet($reconToDate); }   catch (Throwable $e) { $balanceSheet = []; }
try { $profitAndLoss  = $accounting->getProfitAndLoss($reconFromDate, $reconToDate); } catch (Throwable $e) { $profitAndLoss = []; }
try { $cashFlow       = $accounting->getCashFlow($reconFromDate, $reconToDate); }      catch (Throwable $e) { $cashFlow = []; }
try { $arAging        = $accounting->getAccountsReceivableAging(); }                   catch (Throwable $e) { $arAging = []; }

// Normalize report payloads to support both legacy list format and current associative format.
$balanceSections = [];
if (isset($balanceSheet['sections']) && is_array($balanceSheet['sections'])) {
	$balanceLabelMap = [
		'asset' => 'Assets',
		'liability' => 'Liabilities',
		'equity' => 'Equity',
	];
	foreach ($balanceLabelMap as $key => $label) {
		$balanceSections[] = [
			'label' => $label,
			'accounts' => is_array($balanceSheet['sections'][$key] ?? null) ? $balanceSheet['sections'][$key] : [],
			'total' => (float)($balanceSheet['totals'][$key . 's'] ?? 0),
		];
	}
} elseif (is_array($balanceSheet)) {
	foreach ($balanceSheet as $section) {
		if (is_array($section) && isset($section['label'])) {
			$balanceSections[] = $section;
		}
	}
}

$plSections = [];
if (isset($profitAndLoss['sections']) && is_array($profitAndLoss['sections'])) {
	$plSections[] = [
		'label' => 'Revenue',
		'accounts' => is_array($profitAndLoss['sections']['revenue'] ?? null) ? $profitAndLoss['sections']['revenue'] : [],
		'total' => (float)($profitAndLoss['totals']['revenue'] ?? 0),
	];
	$plSections[] = [
		'label' => 'Cost of Sales',
		'accounts' => is_array($profitAndLoss['sections']['cost_of_sales'] ?? null) ? $profitAndLoss['sections']['cost_of_sales'] : [],
		'total' => (float)($profitAndLoss['totals']['cost_of_sales'] ?? 0),
	];
	$plSections[] = [
		'label' => 'Expenses',
		'accounts' => is_array($profitAndLoss['sections']['expenses'] ?? null) ? $profitAndLoss['sections']['expenses'] : [],
		'total' => (float)($profitAndLoss['totals']['expenses'] ?? 0),
	];
	$plSections[] = [
		'label' => 'Net Profit',
		'total' => (float)($profitAndLoss['totals']['net_profit'] ?? 0),
		'highlight' => true,
	];
} elseif (is_array($profitAndLoss)) {
	foreach ($profitAndLoss as $section) {
		if (is_array($section) && isset($section['label'])) {
			$plSections[] = $section;
		}
	}
}

$cashFlowSections = [];
if (isset($cashFlow['activities']) && is_array($cashFlow['activities'])) {
	$operating = [];
	$activities = $cashFlow['activities'];
	if (isset($activities['operating_inflows'])) {
		$operating[] = ['name' => 'Operating Inflows', 'amount' => (float)$activities['operating_inflows']];
	}
	if (isset($activities['operating_outflows'])) {
		$operating[] = ['name' => 'Operating Outflows', 'amount' => (float)$activities['operating_outflows']];
	}
	$cashFlowSections[] = [
		'label' => 'Operating Activities',
		'items' => $operating,
		'total' => (float)(($activities['operating_inflows'] ?? 0) + ($activities['operating_outflows'] ?? 0)),
	];
	$cashFlowSections[] = [
		'label' => 'Net Cash Flow',
		'items' => [],
		'total' => (float)($cashFlow['net_cash_flow'] ?? 0),
	];
	$cashFlowSections[] = [
		'label' => 'Closing Cash Balance',
		'items' => [],
		'total' => (float)($cashFlow['closing_balance'] ?? 0),
	];
} elseif (is_array($cashFlow)) {
	foreach ($cashFlow as $section) {
		if (is_array($section) && isset($section['label'])) {
			$cashFlowSections[] = $section;
		}
	}
}

$arItems = [];
if (isset($arAging['items']) && is_array($arAging['items'])) {
	$arItems = $arAging['items'];
} elseif (is_array($arAging) && array_is_list($arAging)) {
	$arItems = $arAging;
}

$is_admin_page = true;
$page_title = 'Financial Reports — Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<?php include __DIR__ . '/../../templates/accounting_styles.php'; ?>
<div class="container-fluid px-4 py-4">

	<!-- Banner -->
	<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
		<div>
			<h2 class="fw-bold mb-1"><i class="bi bi-bar-chart me-2 text-primary"></i>Financial Reports</h2>
			<p class="text-muted mb-0">Balance Sheet · Profit &amp; Loss · Cash Flow · AR Aging</p>
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

	<!-- Date range selector -->
	<form method="get" action="/accounting/reports" class="row g-2 align-items-end mb-4">
		<div class="col-auto">
			<label class="form-label mb-1 small fw-semibold">From</label>
			<input type="date" name="recon_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($reconFromDate); ?>">
		</div>
		<div class="col-auto">
			<label class="form-label mb-1 small fw-semibold">To</label>
			<input type="date" name="recon_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($reconToDate); ?>">
		</div>
		<div class="col-auto">
			<button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Load</button>
		</div>
	</form>

	<!-- Financial Statements 2×2 grid -->
	<div class="row g-4 mb-4">
		<!-- Balance Sheet -->
		<div class="col-12 col-xl-6">
			<div class="card admin-table-card h-100">
				<div class="card-header"><h6 class="mb-0"><i class="bi bi-bank me-1"></i>Balance Sheet <small class="text-muted ms-2">as at <?php echo htmlspecialchars($reconToDate); ?></small></h6></div>
				<div class="card-body p-0">
					<?php if (empty($balanceSections)): ?>
						<p class="text-muted p-3 mb-0">No balance sheet data.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm table-hover mb-0">
								<tbody>
								<?php foreach ($balanceSections as $bsSection): ?>
									<tr class="table-light"><td colspan="2" class="fw-semibold py-2 px-3"><?php echo htmlspecialchars($bsSection['label']); ?></td></tr>
									<?php foreach (($bsSection['accounts'] ?? []) as $bsAcc): ?>
										<tr><td class="px-4"><?php echo htmlspecialchars($bsAcc['code'] . ' — ' . $bsAcc['name']); ?></td><td class="text-end px-3"><?php echo number_format((float)$bsAcc['balance'], 2); ?></td></tr>
									<?php endforeach; ?>
									<tr class="fw-bold border-top"><td class="px-3">Total <?php echo htmlspecialchars($bsSection['label']); ?></td><td class="text-end px-3"><?php echo number_format((float)$bsSection['total'], 2); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Profit & Loss -->
		<div class="col-12 col-xl-6">
			<div class="card admin-table-card h-100">
				<div class="card-header"><h6 class="mb-0"><i class="bi bi-receipt me-1"></i>Profit &amp; Loss <small class="text-muted ms-2"><?php echo htmlspecialchars($reconFromDate); ?> – <?php echo htmlspecialchars($reconToDate); ?></small></h6></div>
				<div class="card-body p-0">
					<?php if (empty($plSections)): ?>
						<p class="text-muted p-3 mb-0">No P&amp;L data.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm table-hover mb-0">
								<tbody>
								<?php foreach ($plSections as $plSection): ?>
									<tr class="table-light"><td colspan="2" class="fw-semibold py-2 px-3"><?php echo htmlspecialchars($plSection['label']); ?></td></tr>
									<?php if (!empty($plSection['accounts']) && is_array($plSection['accounts'])): ?>
										<?php foreach ($plSection['accounts'] as $plAcc): ?>
											<tr><td class="px-4"><?php echo htmlspecialchars($plAcc['code'] . ' — ' . $plAcc['name']); ?></td><td class="text-end px-3"><?php echo number_format((float)$plAcc['balance'], 2); ?></td></tr>
										<?php endforeach; ?>
										<tr class="fw-bold border-top"><td class="px-3">Total <?php echo htmlspecialchars($plSection['label']); ?></td><td class="text-end px-3"><?php echo number_format((float)$plSection['total'], 2); ?></td></tr>
									<?php else: ?>
										<tr class="fw-bold <?php echo isset($plSection['highlight']) && $plSection['highlight'] ? 'table-success' : ''; ?>"><td class="px-3"><?php echo htmlspecialchars($plSection['label']); ?></td><td class="text-end px-3 <?php echo ((float)($plSection['total'] ?? 0) >= 0 ? 'accounting-amount-positive' : 'accounting-amount-negative'); ?>"><?php echo number_format((float)($plSection['total'] ?? 0), 2); ?></td></tr>
									<?php endif; ?>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Cash Flow -->
		<div class="col-12 col-xl-6">
			<div class="card admin-table-card h-100">
				<div class="card-header"><h6 class="mb-0"><i class="bi bi-cash-stack me-1"></i>Cash Flow Statement <small class="text-muted ms-2"><?php echo htmlspecialchars($reconFromDate); ?> – <?php echo htmlspecialchars($reconToDate); ?></small></h6></div>
				<div class="card-body p-0">
					<?php if (empty($cashFlowSections)): ?>
						<p class="text-muted p-3 mb-0">No cash flow data.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm table-hover mb-0">
								<tbody>
								<?php foreach ($cashFlowSections as $cfSection): ?>
									<tr class="table-light"><td colspan="2" class="fw-semibold py-2 px-3"><?php echo htmlspecialchars($cfSection['label']); ?></td></tr>
									<?php if (!empty($cfSection['items'])): ?>
										<?php foreach ($cfSection['items'] as $cfItem): ?>
											<tr><td class="px-4"><?php echo htmlspecialchars($cfItem['name']); ?></td><td class="text-end px-3 <?php echo ((float)$cfItem['amount'] >= 0 ? 'accounting-amount-positive' : 'accounting-amount-negative'); ?>"><?php echo number_format((float)$cfItem['amount'], 2); ?></td></tr>
										<?php endforeach; ?>
									<?php endif; ?>
									<tr class="fw-bold border-top"><td class="px-3">Net <?php echo htmlspecialchars($cfSection['label']); ?></td><td class="text-end px-3 <?php echo ((float)($cfSection['total'] ?? 0) >= 0 ? 'accounting-amount-positive' : 'accounting-amount-negative'); ?>"><?php echo number_format((float)($cfSection['total'] ?? 0), 2); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- AR Aging -->
		<div class="col-12 col-xl-6">
			<div class="card admin-table-card h-100">
				<div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-1"></i>Accounts Receivable Aging</h6></div>
				<div class="card-body p-0">
					<?php if (empty($arItems) && (float)($arAging['total_outstanding'] ?? 0) <= 0): ?>
						<p class="text-muted p-3 mb-0">No AR aging data.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm table-hover mb-0">
								<thead class="table-light">
									<tr>
										<th class="px-3">Customer</th>
										<th class="text-end">Current</th>
										<th class="text-end">1–30 d</th>
										<th class="text-end">31–60 d</th>
										<th class="text-end">61–90 d</th>
										<th class="text-end">90+ d</th>
										<th class="text-end">Total</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($arItems as $ar): ?>
									<tr>
										<td class="px-3"><?php echo htmlspecialchars($ar['customer'] ?? $ar['name'] ?? ''); ?></td>
										<td class="text-end"><?php echo number_format((float)($ar['current'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($ar['days_1_30'] ?? $ar['bucket_30'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($ar['days_31_60'] ?? $ar['bucket_60'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($ar['days_61_90'] ?? $ar['bucket_90'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($ar['days_over_90'] ?? $ar['bucket_90plus'] ?? 0), 2); ?></td>
										<td class="text-end fw-semibold"><?php echo number_format((float)($ar['total'] ?? 0), 2); ?></td>
									</tr>
								<?php endforeach; ?>
								<?php if (isset($arAging['buckets']) && is_array($arAging['buckets'])): ?>
									<tr class="fw-bold border-top table-light">
										<td class="px-3">Aging Totals</td>
										<td class="text-end"><?php echo number_format((float)($arAging['buckets']['current'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($arAging['buckets']['days_1_30'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($arAging['buckets']['days_31_60'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($arAging['buckets']['days_61_90'] ?? 0), 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($arAging['buckets']['days_over_90'] ?? 0), 2); ?></td>
										<td class="text-end fw-semibold"><?php echo number_format((float)($arAging['total_outstanding'] ?? 0), 2); ?></td>
									</tr>
								<?php endif; ?>
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
