<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
use Dompdf\Dompdf;

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
	header("Location: /login");
	exit;
}

$page_title = "Admin - Financial Reports";

// Default reporting period and filters
$period = isset($_GET['period']) ? $_GET['period'] : 'this_month';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$report_scope = isset($_GET['report_scope']) ? $_GET['report_scope'] : 'all'; // all, payments, billing
$payment_status_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : 'all'; // all, completed, pending, failed
$bill_status_filter = isset($_GET['bill_status']) ? trim($_GET['bill_status']) : 'all'; // all, pending_overdue, paid, cancelled
$search_term = isset($_GET['search_term']) ? trim($_GET['search_term']) : '';
$page_size = 5;
$payments_page = isset($_GET['payments_page']) ? max(1, (int)$_GET['payments_page']) : 1;
$bills_page = isset($_GET['bills_page']) ? max(1, (int)$_GET['bills_page']) : 1;

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
		if ($from_date && $to_date) {
			$from = new DateTime($from_date);
			$to = new DateTime($to_date);
		} else {
			$from = new DateTime(date('Y-m-01'));
			$to = clone $today;
		}
		break;
	case 'this_month':
	default:
		$from = new DateTime(date('Y-m-01'));
		$to = clone $today;
		break;
}

$from_str = $from->format('Y-m-d');
$to_str = $to->format('Y-m-d');

// Display-friendly dates (dd-mm-yyyy)
$from_display = date('d-m-Y', strtotime($from_str));
$to_display = date('d-m-Y', strtotime($to_str));

// Helper for building download URLs (reusing current filters)
$baseQuery = $_GET;
unset($baseQuery['payments_page'], $baseQuery['bills_page'], $baseQuery['export']);

// Load settings for display (currency, company name)
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = isset($settings['currency_code']) && $settings['currency_code'] ? $settings['currency_code'] : 'KES';

// Handle CSV exports for payments or bills before rendering HTML
if (isset($_GET['export'])) {
	$exportType = $_GET['export'];
	// Build common WHERE fragments for reuse
	if (in_array($exportType, ['payments', 'payments_pdf'], true)) {
		$sqlWhere = "WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search)";
		}
		$sql = "SELECT COALESCE(p.transaction_date, p.created_at) AS tx_date, u.account_number, u.full_name, p.amount, p.mpesa_receipt, p.status
			FROM payments p
			LEFT JOIN bills b ON p.bill_id = b.id
			LEFT JOIN users u ON b.user_id = u.id
			" . $sqlWhere . "
			ORDER BY COALESCE(p.transaction_date, p.created_at) DESC";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$like = '%' . $search_term . '%';
			$stmt->bindParam(':search', $like, PDO::PARAM_STR);
		}
		$stmt->execute();
		if ($exportType === 'payments') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'payments_report_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Date', 'Account', 'Customer', 'Amount (' . $currency . ')', 'MPESA Ref', 'Status']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				fputcsv($out, [
					$row['tx_date'],
					$row['account_number'],
					$row['full_name'],
					$row['amount'],
					$row['mpesa_receipt'],
					$row['status'],
				]);
			}
			fclose($out);
		} else {
			// payments_pdf
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			$totalAmount = 0.0;
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$totalAmount += (float)$row['amount'];
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars($row['tx_date']) . '</td>'
					. '<td>' . htmlspecialchars($row['account_number']) . '</td>'
					. '<td>' . htmlspecialchars($row['full_name']) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>'
					. '<td>' . htmlspecialchars($row['mpesa_receipt']) . '</td>'
					. '<td>' . htmlspecialchars(ucfirst($row['status'])) . '</td>'
				. '</tr>';
			}
			$html = '<html><head><meta charset="UTF-8"><title>Payments Report</title>
				<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111827;}h1{font-size:18px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 6px;}th{background:#f9fafb;text-align:left;font-size:10px;}td{text-align:left;font-size:10px;}</style>
				</head><body>' .
				'<h1>Payments Report</h1>' .
				'<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>' .
				'<p>Grand Total (' . htmlspecialchars($currency) . '): <strong>' . number_format($totalAmount, 2) . '</strong></p>' .
				'<table><thead><tr>' .
				'<th>Date</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>MPESA Ref</th><th>Status</th>' .
				'</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';
			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			$dompdf->stream('payments_report_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	} elseif (in_array($exportType, ['bills', 'bills_pdf'], true)) {
		$sqlWhere = "WHERE DATE(b.billing_month) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search)";
		}
		$sql = "SELECT b.billing_month, u.account_number, u.full_name, b.amount, b.due_date, b.status
			FROM bills b
			LEFT JOIN users u ON b.user_id = u.id
			" . $sqlWhere . "
			ORDER BY b.billing_month DESC, b.id DESC";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$likeBills = '%' . $search_term . '%';
			$stmt->bindParam(':search', $likeBills, PDO::PARAM_STR);
		}
		$stmt->execute();
		if ($exportType === 'bills') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'bills_report_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Billing Month', 'Account', 'Customer', 'Amount (' . $currency . ')', 'Due Date', 'Status']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				fputcsv($out, [
					$row['billing_month'],
					$row['account_number'],
					$row['full_name'],
					$row['amount'],
					$row['due_date'],
					$row['status'],
				]);
			}
			fclose($out);
		} else {
			// bills_pdf
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			$totalAmount = 0.0;
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$totalAmount += (float)$row['amount'];
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars(date('Y-m', strtotime($row['billing_month']))) . '</td>'
					. '<td>' . htmlspecialchars($row['account_number']) . '</td>'
					. '<td>' . htmlspecialchars($row['full_name']) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>'
					. '<td>' . htmlspecialchars($row['due_date']) . '</td>'
					. '<td>' . htmlspecialchars(ucfirst($row['status'])) . '</td>'
				. '</tr>';
			}
			$html = '<html><head><meta charset="UTF-8"><title>Bills Report</title>
				<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111827;}h1{font-size:18px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 6px;}th{background:#f9fafb;text-align:left;font-size:10px;}td{text-align:left;font-size:10px;}</style>
				</head><body>' .
				'<h1>Bills Report</h1>' .
				'<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>' .
				'<p>Grand Total (' . htmlspecialchars($currency) . '): <strong>' . number_format($totalAmount, 2) . '</strong></p>' .
				'<table><thead><tr>' .
				'<th>Billing Month</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>Due Date</th><th>Status</th>' .
				'</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';
			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			$dompdf->stream('bills_report_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	}
}

// Summary metrics
$billService = new Bill($db);
$paymentService = new Payment($db);

// Total billed in period (based on billing_month)
$stmtBilled = $db->prepare("SELECT 
	COALESCE(SUM(amount),0) AS total_billed,
	COUNT(*) AS bills_count
	FROM bills
	WHERE DATE(billing_month) BETWEEN :from AND :to");
$stmtBilled->bindParam(':from', $from_str);
$stmtBilled->bindParam(':to', $to_str);
$stmtBilled->execute();
$billedRow = $stmtBilled->fetch(PDO::FETCH_ASSOC) ?: ['total_billed' => 0, 'bills_count' => 0];

// Total collected in period (completed payments, use transaction_date if available)
$stmtCollected = $db->prepare("SELECT 
	COALESCE(SUM(amount),0) AS total_collected,
	COUNT(*) AS payments_count
	FROM payments
	WHERE status = 'completed'
	AND DATE(COALESCE(transaction_date, created_at)) BETWEEN :from AND :to");
$stmtCollected->bindParam(':from', $from_str);
$stmtCollected->bindParam(':to', $to_str);
$stmtCollected->execute();
$collectedRow = $stmtCollected->fetch(PDO::FETCH_ASSOC) ?: ['total_collected' => 0, 'payments_count' => 0];

// Total outstanding overall (not just in period)
$stmtOutstanding = $db->query("SELECT 
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END),0) AS total_outstanding,
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END),0) AS outstanding_bills
	FROM bills");
$outstandingRow = $stmtOutstanding->fetch(PDO::FETCH_ASSOC) ?: ['total_outstanding' => 0, 'outstanding_bills' => 0];

// Accounts receivable aging (overall)
$stmtAging = $db->query("SELECT
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND DATEDIFF(CURDATE(), due_date) <= 0 THEN amount ELSE 0 END),0) AS current_bucket,
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN amount ELSE 0 END),0) AS bucket_1_30,
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END),0) AS bucket_31_60,
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END),0) AS bucket_61_90,
	COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END),0) AS bucket_over_90
	FROM bills");
$agingRow = $stmtAging->fetch(PDO::FETCH_ASSOC) ?: [
	'current_bucket' => 0,
	'bucket_1_30' => 0,
	'bucket_31_60' => 0,
	'bucket_61_90' => 0,
	'bucket_over_90' => 0,
];

// Recent payments in period (detailed list) with optional search filter + pagination
$payments = [];
$payments_total = 0;
$payments_total_pages = 1;
$payments_grand_total = 0.0;
if ($report_scope === 'all' || $report_scope === 'payments') {
	// Count
	$sqlPaymentsWhere = "WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to";
	if ($payment_status_filter === 'completed') {
		$sqlPaymentsWhere .= " AND p.status = 'completed'";
	} elseif ($payment_status_filter === 'pending') {
		$sqlPaymentsWhere .= " AND p.status = 'pending'";
	} elseif ($payment_status_filter === 'failed') {
		$sqlPaymentsWhere .= " AND p.status = 'failed'";
	}
	if ($search_term !== '') {
		$sqlPaymentsWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search)";
	}
	$sqlPaymentsCount = "SELECT COUNT(*) AS cnt
		FROM payments p
		LEFT JOIN bills b ON p.bill_id = b.id
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlPaymentsWhere;
	$stmtPaymentsCount = $db->prepare($sqlPaymentsCount);
	$stmtPaymentsCount->bindParam(':from', $from_str);
	$stmtPaymentsCount->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$like = '%' . $search_term . '%';
		$stmtPaymentsCount->bindParam(':search', $like, PDO::PARAM_STR);
	}
	$stmtPaymentsCount->execute();
	$rowCount = $stmtPaymentsCount->fetch(PDO::FETCH_ASSOC);
	$payments_total = isset($rowCount['cnt']) ? (int)$rowCount['cnt'] : 0;
	$payments_total_pages = max(1, (int)ceil($payments_total / $page_size));
	if ($payments_page > $payments_total_pages) {
		$payments_page = $payments_total_pages;
	}
	$payments_offset = ($payments_page - 1) * $page_size;

	// Page data
	$sqlPayments = "SELECT p.*, 
		u.full_name, u.account_number
		FROM payments p
		LEFT JOIN bills b ON p.bill_id = b.id
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlPaymentsWhere . "
		ORDER BY COALESCE(p.transaction_date, p.created_at) DESC
		LIMIT :limit OFFSET :offset";
	$stmtPayments = $db->prepare($sqlPayments);
	$stmtPayments->bindParam(':from', $from_str);
	$stmtPayments->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$like = '%' . $search_term . '%';
		$stmtPayments->bindParam(':search', $like, PDO::PARAM_STR);
	}
	$stmtPayments->bindParam(':limit', $page_size, PDO::PARAM_INT);
	$stmtPayments->bindParam(':offset', $payments_offset, PDO::PARAM_INT);
	$stmtPayments->execute();
	$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

	// Grand total for filtered payments in period
	$sqlPaymentsTotal = "SELECT COALESCE(SUM(p.amount),0) AS total_amount
		FROM payments p
		LEFT JOIN bills b ON p.bill_id = b.id
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlPaymentsWhere;
	$stmtPaymentsTotal = $db->prepare($sqlPaymentsTotal);
	$stmtPaymentsTotal->bindParam(':from', $from_str);
	$stmtPaymentsTotal->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$likeTotal = '%' . $search_term . '%';
		$stmtPaymentsTotal->bindParam(':search', $likeTotal, PDO::PARAM_STR);
	}
	$stmtPaymentsTotal->execute();
	$totalRow = $stmtPaymentsTotal->fetch(PDO::FETCH_ASSOC);
	$payments_grand_total = isset($totalRow['total_amount']) ? (float)$totalRow['total_amount'] : 0.0;
}

// Bills in period (based on billing_month) with optional search filter + pagination
$bills = [];
$bills_total = 0;
$bills_total_pages = 1;
$bills_grand_total = 0.0;
if ($report_scope === 'all' || $report_scope === 'billing') {
	$sqlBillsWhere = "WHERE DATE(b.billing_month) BETWEEN :from AND :to";
	if ($bill_status_filter === 'pending_overdue') {
		$sqlBillsWhere .= " AND b.status IN ('pending','overdue')";
	} elseif ($bill_status_filter === 'paid') {
		$sqlBillsWhere .= " AND b.status = 'paid'";
	} elseif ($bill_status_filter === 'cancelled') {
		$sqlBillsWhere .= " AND b.status = 'cancelled'";
	}
	if ($search_term !== '') {
		$sqlBillsWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search)";
	}
	// Count
	$sqlBillsCount = "SELECT COUNT(*) AS cnt
		FROM bills b
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlBillsWhere;
	$stmtBillsCount = $db->prepare($sqlBillsCount);
	$stmtBillsCount->bindParam(':from', $from_str);
	$stmtBillsCount->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$likeBills = '%' . $search_term . '%';
		$stmtBillsCount->bindParam(':search', $likeBills, PDO::PARAM_STR);
	}
	$stmtBillsCount->execute();
	$rowBillsCount = $stmtBillsCount->fetch(PDO::FETCH_ASSOC);
	$bills_total = isset($rowBillsCount['cnt']) ? (int)$rowBillsCount['cnt'] : 0;
	$bills_total_pages = max(1, (int)ceil($bills_total / $page_size));
	if ($bills_page > $bills_total_pages) {
		$bills_page = $bills_total_pages;
	}
	$bills_offset = ($bills_page - 1) * $page_size;

	// Page data
	$sqlBills = "SELECT b.*, u.full_name, u.account_number
		FROM bills b
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlBillsWhere . "
		ORDER BY b.billing_month DESC, b.id DESC
		LIMIT :limit OFFSET :offset";
	$stmtBills = $db->prepare($sqlBills);
	$stmtBills->bindParam(':from', $from_str);
	$stmtBills->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$likeBills = '%' . $search_term . '%';
		$stmtBills->bindParam(':search', $likeBills, PDO::PARAM_STR);
	}
	$stmtBills->bindParam(':limit', $page_size, PDO::PARAM_INT);
	$stmtBills->bindParam(':offset', $bills_offset, PDO::PARAM_INT);
	$stmtBills->execute();
	$bills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);

	// Grand total for filtered bills in period
	$sqlBillsTotal = "SELECT COALESCE(SUM(b.amount),0) AS total_amount
		FROM bills b
		LEFT JOIN users u ON b.user_id = u.id
		" . $sqlBillsWhere;
	$stmtBillsTotal = $db->prepare($sqlBillsTotal);
	$stmtBillsTotal->bindParam(':from', $from_str);
	$stmtBillsTotal->bindParam(':to', $to_str);
	if ($search_term !== '') {
		$likeBillsTotal = '%' . $search_term . '%';
		$stmtBillsTotal->bindParam(':search', $likeBillsTotal, PDO::PARAM_STR);
	}
	$stmtBillsTotal->execute();
	$totalBillsRow = $stmtBillsTotal->fetch(PDO::FETCH_ASSOC);
	$bills_grand_total = isset($totalBillsRow['total_amount']) ? (float)$totalBillsRow['total_amount'] : 0.0;
}

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4 mb-4">
	<div class="financial-dashboard-header mb-4">
		<div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
			<div>
				<h2 class="financial-dashboard-header-title mb-1 d-flex align-items-center gap-2">
					<i class="bi bi-graph-up-arrow text-primary"></i>
					<span>Financial Dashboard</span>
				</h2>
				<p class="financial-dashboard-header-subtitle mb-2">Key financial KPIs, aging, and detailed payment/billing reports.</p>
				<p class="financial-dashboard-tagline mb-0">
					<strong>Water Billing System</strong> &middot; Efficient water bill management with M-Pesa integration.
				</p>
			</div>
			<div class="text-end financial-dashboard-summary">
				<div><span class="text-uppercase text-muted small">Company</span><br><strong><?php echo htmlspecialchars($settings['company_name'] ?? ''); ?></strong></div>
				<div class="mt-2"><span class="text-uppercase text-muted small">Reporting Currency</span><br><span><?php echo htmlspecialchars($currency); ?></span></div>
				<div class="mt-2"><span class="text-uppercase text-muted small">Period</span><br><span><?php echo htmlspecialchars($from_display); ?> &ndash; <?php echo htmlspecialchars($to_display); ?></span></div>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header d-flex justify-content-between align-items-center">
			<div>
				<div class="financial-section-title mb-1">Filters</div>
				<small class="text-muted">Adjust period, scope, and search to update the dashboard below.</small>
			</div>
		</div>
		<div class="card-body">
			<form class="row gy-2 gx-3 align-items-end" method="get" action="/admin/reports">
				<div class="col-sm-3 col-md-2">
					<label for="period" class="form-label">Period</label>
					<select name="period" id="period" class="form-select form-select-sm">
						<option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
						<option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
						<option value="this_year" <?php echo $period === 'this_year' ? 'selected' : ''; ?>>This Year</option>
						<option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
					</select>
				</div>
				<div class="col-sm-3 col-md-2">
					<label for="from_date" class="form-label">From</label>
					<input type="date" name="from_date" id="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_str); ?>">
				</div>
				<div class="col-sm-3 col-md-2">
					<label for="to_date" class="form-label">To</label>
					<input type="date" name="to_date" id="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_str); ?>">
				</div>
				<div class="col-sm-4 col-md-3">
					<label for="report_scope" class="form-label">Show</label>
					<select name="report_scope" id="report_scope" class="form-select form-select-sm">
						<option value="all" <?php echo $report_scope === 'all' ? 'selected' : ''; ?>>Payments and Billing</option>
						<option value="payments" <?php echo $report_scope === 'payments' ? 'selected' : ''; ?>>Payments only</option>
						<option value="billing" <?php echo $report_scope === 'billing' ? 'selected' : ''; ?>>Billing only</option>
					</select>
				</div>
				<div class="col-sm-8 col-md-3">
					<label for="search_term" class="form-label">Search (Account / Name / MPESA)</label>
					<input type="text" name="search_term" id="search_term" class="form-control form-control-sm" placeholder="e.g. MTR0001 or John" value="<?php echo htmlspecialchars($search_term); ?>">
				</div>
				<div class="col-sm-12 col-md-2 mt-2 mt-md-0 d-flex align-items-end justify-content-start justify-content-md-end gap-2">
					<button type="submit" class="btn btn-primary btn-sm">
						<i class="bi bi-funnel me-1"></i> Apply
					</button>
					<a href="/admin/reports" class="btn btn-outline-secondary btn-sm">Reset</a>
				</div>
			</form>
		</div>
	</div>

	<h5 class="mb-2 financial-section-title">Key Metrics</h5>
	<div class="row g-3 mb-3">
		<div class="col-lg-8">
			<div class="row g-3">
				<div class="col-md-4">
					<div class="card shadow-sm h-100 metric-card metric-card-primary">
						<div class="card-body">
							<div class="d-flex justify-content-between align-items-center mb-1">
								<span class="text-muted text-uppercase small">Total Billed</span>
								<span class="badge bg-light text-dark">Bills: <?php echo (int)$billedRow['bills_count']; ?></span>
							</div>
							<div class="h5 mb-0"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$billedRow['total_billed'], 2); ?></div>
							<div class="text-muted small mt-1">For period <?php echo htmlspecialchars($from_display); ?> to <?php echo htmlspecialchars($to_display); ?></div>
						</div>
					</div>
				</div>
				<div class="col-md-4">
					<div class="card shadow-sm h-100 metric-card metric-card-success">
						<div class="card-body">
							<div class="d-flex justify-content-between align-items-center mb-1">
								<span class="text-muted text-uppercase small">Total Collected</span>
								<span class="badge bg-light text-dark">Payments: <?php echo (int)$collectedRow['payments_count']; ?></span>
							</div>
							<div class="h5 mb-0 text-success"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$collectedRow['total_collected'], 2); ?></div>
							<div class="text-muted small mt-1">Completed payments in selected period.</div>
						</div>
					</div>
				</div>
				<div class="col-md-4">
					<div class="card shadow-sm h-100 metric-card metric-card-danger">
						<div class="card-body">
							<div class="d-flex justify-content-between align-items-center mb-1">
								<span class="text-muted text-uppercase small">Outstanding</span>
								<span class="badge bg-warning text-dark">Bills: <?php echo (int)$outstandingRow['outstanding_bills']; ?></span>
							</div>
							<div class="h5 mb-0 text-danger"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$outstandingRow['total_outstanding'], 2); ?></div>
							<div class="text-muted small mt-1">Pending and overdue balances (overall).</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-4 d-flex flex-column gap-3">
			<div class="card quick-links-card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h6 class="card-title mb-0"><i class="bi bi-lightning-charge me-1"></i> Quick Links</h6>
				</div>
				<div class="card-body py-3">
					<div class="quick-links-list d-flex flex-wrap gap-2">
						<a href="/dashboard" class="btn btn-sm btn-quick-link" title="Go to main dashboard">
							<i class="bi bi-speedometer2"></i>
							<span>Dashboard</span>
						</a>
						<a href="/bills" class="btn btn-sm btn-quick-link" title="View customer bills">
							<i class="bi bi-receipt"></i>
							<span>My Bills</span>
						</a>
						<a href="/pay" class="btn btn-sm btn-quick-link" title="Initiate bill payment">
							<i class="bi bi-credit-card"></i>
							<span>Pay Bill</span>
						</a>
						<a href="/complaints" class="btn btn-sm btn-quick-link" title="View and manage complaints">
							<i class="bi bi-chat-left-text"></i>
							<span>Complaints</span>
						</a>
						<a href="/admin/invoicing" class="btn btn-sm btn-quick-link" title="Open invoicing workspace">
							<i class="bi bi-file-earmark-text"></i>
							<span>Invoicing</span>
						</a>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="row g-3 mb-3">
			<div class="col-12">
				<div class="card shadow-sm h-100 aging-card">
					<div class="card-header bg-light">
						<h6 class="mb-0">Accounts Receivable Aging</h6>
					</div>
					<div class="card-body">
						<div class="table-responsive">
							<table class="table table-sm mb-0 align-middle">
								<thead class="table-light">
									<tr>
										<th>Bucket</th>
										<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>Current (Not Yet Due)</td>
										<td class="text-end"><?php echo number_format((float)$agingRow['current_bucket'], 2); ?></td>
									</tr>
									<tr>
										<td>1 - 30 Days Overdue</td>
										<td class="text-end"><?php echo number_format((float)$agingRow['bucket_1_30'], 2); ?></td>
									</tr>
									<tr>
										<td>31 - 60 Days Overdue</td>
										<td class="text-end"><?php echo number_format((float)$agingRow['bucket_31_60'], 2); ?></td>
									</tr>
									<tr>
										<td>61 - 90 Days Overdue</td>
										<td class="text-end"><?php echo number_format((float)$agingRow['bucket_61_90'], 2); ?></td>
									</tr>
									<tr>
										<td>Over 90 Days Overdue</td>
										<td class="text-end text-danger"><?php echo number_format((float)$agingRow['bucket_over_90'], 2); ?></td>
									</tr>
								</tbody>
							</table>
						</div>
						<p class="text-muted small mt-2 mb-0">This aging view follows a standard 0/30/60/90+ day breakdown for receivables.</p>
					</div>
				</div>
			</div>
		</div>

	<h5 class="mt-4 mb-2 financial-section-title">Detailed Reports</h5>
	<div class="row g-3">
		<?php if ($report_scope === 'all' || $report_scope === 'payments'): ?>
		<div class="col-12" id="paymentsReportSection">
			<div class="card h-100">
				<div class="card-header d-flex justify-content-between align-items-center">
					<div>
						<h5 class="card-title mb-0">Payment Report</h5>
						<small class="text-muted">All payments for the selected period and filters.</small>
					</div>
					<div>
						<?php $paymentsExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payments']))); ?>
						<?php $paymentsExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payments_pdf']))); ?>
						<a href="<?php echo $paymentsExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm me-1">
							<i class="bi bi-download"></i> CSV
						</a>
						<a href="<?php echo $paymentsExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm">
							<i class="bi bi-file-earmark-pdf"></i> PDF
						</a>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle">
							<thead class="table-light">
								<tr>
									<th>Date</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>MPESA Ref</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($payments)): ?>
								<tr>
									<td colspan="6" class="text-center text-muted py-3">No payments found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($payments as $p): ?>
									<tr>
										<td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($p['transaction_date'] ?? $p['created_at']))); ?></td>
										<td><?php echo htmlspecialchars($p['account_number'] ?? '-'); ?></td>
										<td><?php echo htmlspecialchars($p['full_name'] ?? ''); ?></td>
										<td class="text-end"><?php echo number_format((float)$p['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars($p['mpesa_receipt'] ?? '-'); ?></td>
										<td>
											<span class="badge bg-<?php echo $p['status'] === 'completed' ? 'success' : ($p['status'] === 'failed' ? 'danger' : 'warning'); ?>">
												<?php echo htmlspecialchars(ucfirst($p['status'])); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end">
						<strong>Grand Total (<?php echo htmlspecialchars($currency); ?>):</strong>
						<?php echo number_format($payments_grand_total, 2); ?>
					</div>
					<?php if ($payments_total_pages > 1): ?>
					<nav class="mt-2">
						<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
							<?php
								$paymentsPrevPage = max(1, $payments_page - 1);
								$paymentsNextPage = min($payments_total_pages, $payments_page + 1);
							?>
							<li class="page-item <?php echo $payments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $paymentsFirstUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payments_page' => 1, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-payments-page-link" href="<?php echo $paymentsFirstUrl; ?>" aria-label="First" title="Go to first page">
									<span aria-hidden="true">&laquo;&laquo;</span>
								</a>
							</li>
							<li class="page-item <?php echo $payments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $paymentsPrevUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payments_page' => $paymentsPrevPage, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-payments-page-link" href="<?php echo $paymentsPrevUrl; ?>" aria-label="Previous" title="Go to previous page">
									<span aria-hidden="true">&laquo;</span>
								</a>
							</li>
							<?php for ($i = 1; $i <= $payments_total_pages; $i++): ?>
								<?php $paymentsPageUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payments_page' => $i, 'bills_page' => $bills_page]))); ?>
								<li class="page-item <?php echo $i === $payments_page ? 'active' : ''; ?>">
									<a class="page-link js-payments-page-link" href="<?php echo $paymentsPageUrl; ?>" title="Go to page <?php echo $i; ?>"><?php echo $i; ?></a>
								</li>
							<?php endfor; ?>
							<li class="page-item <?php echo $payments_page >= $payments_total_pages ? 'disabled' : ''; ?>">
								<?php $paymentsNextUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payments_page' => $paymentsNextPage, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-payments-page-link" href="<?php echo $paymentsNextUrl; ?>" aria-label="Next" title="Go to next page">
									<span aria-hidden="true">&raquo;</span>
								</a>
							</li>
							<li class="page-item <?php echo $payments_page >= $payments_total_pages ? 'disabled' : ''; ?>">
								<?php $paymentsLastUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payments_page' => $payments_total_pages, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-payments-page-link" href="<?php echo $paymentsLastUrl; ?>" aria-label="Last" title="Go to last page">
									<span aria-hidden="true">&raquo;&raquo;</span>
								</a>
							</li>
						</ul>
					</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php if ($report_scope === 'all' || $report_scope === 'billing'): ?>
		<div class="col-12" id="billsReportSection">
			<div class="card h-100">
				<div class="card-header d-flex justify-content-between align-items-center">
					<div>
						<h5 class="card-title mb-0">Billing Report</h5>
						<small class="text-muted">Bills issued for the selected period and filters.</small>
					</div>
					<div>
						<?php $billsExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'bills']))); ?>
						<?php $billsExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'bills_pdf']))); ?>
						<a href="<?php echo $billsExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm me-1">
							<i class="bi bi-download"></i> CSV
						</a>
						<a href="<?php echo $billsExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm">
							<i class="bi bi-file-earmark-pdf"></i> PDF
						</a>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle">
							<thead class="table-light">
								<tr>
									<th>Billing Month</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Due Date</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($bills)): ?>
								<tr>
									<td colspan="6" class="text-center text-muted py-3">No bills found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($bills as $bill): ?>
									<tr>
										<td><?php echo htmlspecialchars(date('M Y', strtotime($bill['billing_month']))); ?></td>
										<td><?php echo htmlspecialchars($bill['account_number'] ?? '-'); ?></td>
										<td><?php echo htmlspecialchars($bill['full_name'] ?? ''); ?></td>
										<td class="text-end"><?php echo number_format((float)$bill['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars($bill['due_date']); ?></td>
										<td>
											<span class="badge bg-<?php echo $bill['status'] === 'paid' ? 'success' : (in_array($bill['status'], ['pending','overdue'], true) ? 'warning' : 'secondary'); ?>">
												<?php echo htmlspecialchars(ucfirst($bill['status'])); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end">
						<strong>Grand Total (<?php echo htmlspecialchars($currency); ?>):</strong>
						<?php echo number_format($bills_grand_total, 2); ?>
					</div>
					<?php if ($bills_total_pages > 1): ?>
					<nav class="mt-2">
						<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
							<?php
								$billsPrevPage = max(1, $bills_page - 1);
								$billsNextPage = min($bills_total_pages, $bills_page + 1);
							?>
							<li class="page-item <?php echo $bills_page <= 1 ? 'disabled' : ''; ?>">
								<?php $billsFirstUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['bills_page' => 1, 'payments_page' => $payments_page]))); ?>
								<a class="page-link js-bills-page-link" href="<?php echo $billsFirstUrl; ?>" aria-label="First" title="Go to first page">
									<span aria-hidden="true">&laquo;&laquo;</span>
								</a>
							</li>
							<li class="page-item <?php echo $bills_page <= 1 ? 'disabled' : ''; ?>">
								<?php $billsPrevUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['bills_page' => $billsPrevPage, 'payments_page' => $payments_page]))); ?>
								<a class="page-link js-bills-page-link" href="<?php echo $billsPrevUrl; ?>" aria-label="Previous" title="Go to previous page">
									<span aria-hidden="true">&laquo;</span>
								</a>
							</li>
							<?php for ($i = 1; $i <= $bills_total_pages; $i++): ?>
								<?php $billsPageUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['bills_page' => $i, 'payments_page' => $payments_page]))); ?>
								<li class="page-item <?php echo $i === $bills_page ? 'active' : ''; ?>">
									<a class="page-link js-bills-page-link" href="<?php echo $billsPageUrl; ?>" title="Go to page <?php echo $i; ?>"><?php echo $i; ?></a>
								</li>
							<?php endfor; ?>
							<li class="page-item <?php echo $bills_page >= $bills_total_pages ? 'disabled' : ''; ?>">
								<?php $billsNextUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['bills_page' => $billsNextPage, 'payments_page' => $payments_page]))); ?>
								<a class="page-link js-bills-page-link" href="<?php echo $billsNextUrl; ?>" aria-label="Next" title="Go to next page">
									<span aria-hidden="true">&raquo;</span>
								</a>
							</li>
							<li class="page-item <?php echo $bills_page >= $bills_total_pages ? 'disabled' : ''; ?>">
								<?php $billsLastUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['bills_page' => $bills_total_pages, 'payments_page' => $payments_page]))); ?>
								<a class="page-link js-bills-page-link" href="<?php echo $billsLastUrl; ?>" aria-label="Last" title="Go to last page">
									<span aria-hidden="true">&raquo;&raquo;</span>
								</a>
							</li>
						</ul>
					</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>

<script>
// AJAX-style pagination for Payment and Billing reports on the Financial Dashboard
(function() {
	function attachPaginationHandlers() {
		var paymentsContainer = document.getElementById('paymentsReportSection');
		if (paymentsContainer) {
			var paymentLinks = paymentsContainer.querySelectorAll('.js-payments-page-link');
			paymentLinks.forEach(function(link) {
				link.addEventListener('click', function (e) {
					e.preventDefault();
					loadSection('paymentsReportSection', this.getAttribute('href'));
				});
			});
		}
		var billsContainer = document.getElementById('billsReportSection');
		if (billsContainer) {
			var billLinks = billsContainer.querySelectorAll('.js-bills-page-link');
			billLinks.forEach(function(link) {
				link.addEventListener('click', function (e) {
					e.preventDefault();
					loadSection('billsReportSection', this.getAttribute('href'));
				});
			});
		}
	}

	function loadSection(containerId, url) {
		var container = document.getElementById(containerId);
		if (!container) return;

		// Show loading overlay with spinner
		var originalPosition = container.style.position;
		var changedPosition = false;
		if (window.getComputedStyle && getComputedStyle(container).position === 'static') {
			container.style.position = 'relative';
			changedPosition = true;
		}
		var overlay = document.createElement('div');
		overlay.className = 'position-absolute top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center bg-dark bg-opacity-25';
		overlay.innerHTML = '' +
			'<div class="p-3 bg-white rounded shadow-sm d-flex flex-column align-items-center">' +
				'<div class="spinner-border text-primary mb-2" role="status">' +
					'<span class="visually-hidden">Loading...</span>' +
				'</div>' +
				'<div class="small text-muted">Loading data...</div>' +
			'</div>';
		container.appendChild(overlay);

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function(res) { return res.text(); })
			.then(function(html) {
				var parser = new DOMParser();
				var doc = parser.parseFromString(html, 'text/html');
				var newContainer = doc.getElementById(containerId);
				if (!newContainer) return;
				container.innerHTML = newContainer.innerHTML;
				// Reattach handlers for new pagination links
				attachPaginationHandlers();
			})
			.catch(function(err) {
				if (window.showToast) {
					showToast('Failed to load page: ' + (err && err.message ? err.message : ''), 'danger');
				}
			})
			.finally(function() {
				if (overlay && overlay.parentNode === container) {
					container.removeChild(overlay);
				}
				if (changedPosition) {
					container.style.position = originalPosition;
				}
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', attachPaginationHandlers);
	} else {
		attachPaginationHandlers();
	}
})();
</script>
