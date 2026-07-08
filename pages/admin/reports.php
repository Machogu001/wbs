<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../includes/InstallmentPlan.php';
use Dompdf\Dompdf;

function runReportsMaintenanceCommand(string $scriptPath, array $args = []): array {
	$phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
	$commandParts = [escapeshellarg($phpBinary), escapeshellarg($scriptPath)];
	foreach ($args as $arg) {
		$commandParts[] = escapeshellarg($arg);
	}
	$command = implode(' ', $commandParts) . ' 2>&1';
	$output = [];
	$exitCode = 1;
	exec($command, $output, $exitCode);

	return [
		'command' => $command,
		'output' => $output,
		'exit_code' => $exitCode,
		'succeeded' => $exitCode === 0,
	];
}

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Ensure finance audit tables are available for reports across installs.
new FinanceApproval($db);
new InstallmentPlan($db);

if(!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_reports'))) {
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
unset($baseQuery['payments_page'], $baseQuery['bills_page'], $baseQuery['allocations_page'], $baseQuery['adjustments_page'], $baseQuery['export']);

$registrationBillPredicateSql = "(EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = b.id AND bli_reg.line_type = 'registration_fee') OR (b.consumption = 0 AND b.rate_per_unit = 0 AND b.base_amount = 0 AND b.service_charge > 0))";
$monthlyBillPredicateSql = 'NOT ' . $registrationBillPredicateSql;
$registrationPaymentPredicateSql = "(p.registration_id IS NOT NULL OR EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = p.bill_id AND bli_reg.line_type = 'registration_fee') OR EXISTS (SELECT 1 FROM bills b_reg WHERE b_reg.id = p.bill_id AND b_reg.consumption = 0 AND b_reg.rate_per_unit = 0 AND b_reg.base_amount = 0 AND b_reg.service_charge > 0))";
$monthlyPaymentPredicateSql = 'NOT ' . $registrationPaymentPredicateSql;
$billTypeCaseSql = "CASE WHEN {$registrationBillPredicateSql} THEN 'registration' ELSE 'monthly' END";
$paymentTypeCaseSql = "CASE WHEN {$registrationPaymentPredicateSql} THEN 'registration' ELSE 'monthly' END";

// Load settings for display (currency, company name)
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = isset($settings['currency_code']) && $settings['currency_code'] ? $settings['currency_code'] : 'KES';
$maintenanceResult = null;
$auditStatus = null;

$auditStatusFile = __DIR__ . '/../../logs/billing_audit_status.json';
if (is_file($auditStatusFile) && is_readable($auditStatusFile)) {
	$decodedAuditStatus = json_decode((string)file_get_contents($auditStatusFile), true);
	if (is_array($decodedAuditStatus)) {
		$auditStatus = $decodedAuditStatus;
	}
}

if (empty($_SESSION['reports_csrf'])) {
	$_SESSION['reports_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = (string)($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['reports_csrf'], $csrfToken)) {
		http_response_code(403);
		die('Security validation failed. Please refresh and try again.');
	}
	$maintenanceAction = isset($_POST['maintenance_action']) ? trim((string)$_POST['maintenance_action']) : '';
	$scriptBase = realpath(__DIR__ . '/../../scripts');
	if ($scriptBase !== false) {
		if ($maintenanceAction === 'run_audit') {
			$maintenanceResult = runReportsMaintenanceCommand($scriptBase . '/billing_integrity_audit.php');
			$maintenanceResult['title'] = 'Billing Integrity Audit';
		} elseif ($maintenanceAction === 'preview_repair') {
			$maintenanceResult = runReportsMaintenanceCommand($scriptBase . '/repair_billing_journals.php');
			$maintenanceResult['title'] = 'Billing Repair Preview';
		} elseif ($maintenanceAction === 'apply_repair') {
			$maintenanceResult = runReportsMaintenanceCommand($scriptBase . '/repair_billing_journals.php', ['--apply']);
			$maintenanceResult['title'] = 'Billing Repair Apply';
		}
	}
}

// Handle CSV/PDF exports for payments, bills, or usage before rendering HTML
if (isset($_GET['export'])) {
	$exportType = $_GET['export'];
	// Build common WHERE fragments for reuse
	if (in_array($exportType, ['payments', 'payments_pdf'], true)) {
		$sqlWhere = "WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search)";
		}
		$sql = "SELECT COALESCE(p.transaction_date, p.created_at) AS tx_date, u.account_number, u.full_name, p.amount, p.mpesa_receipt, p.status, {$paymentTypeCaseSql} AS payment_type,
			b.amount AS bill_amount,
			GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0)) AS bill_balance
			FROM payments p
			LEFT JOIN bills b ON p.bill_id = b.id
			LEFT JOIN users u ON b.user_id = u.id
			LEFT JOIN (
				SELECT bill_id, COALESCE(SUM(amount), 0) AS completed_paid
				FROM payments
				WHERE status = 'completed' AND bill_id IS NOT NULL
				GROUP BY bill_id
			) p_paid ON p_paid.bill_id = b.id
			LEFT JOIN (
				SELECT p2.bill_id, COALESCE(SUM(pa.amount), 0) AS approved_adjustments
				FROM payment_adjustments pa
				INNER JOIN payments p2 ON p2.id = pa.payment_id
				WHERE pa.status = 'approved' AND p2.bill_id IS NOT NULL
				GROUP BY p2.bill_id
			) pa_adj ON pa_adj.bill_id = b.id
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
			fputcsv($out, ['Date', 'Type', 'Account', 'Customer', 'Amount (' . $currency . ')', 'Bill Balance (' . $currency . ')', 'MPESA Ref', 'Status']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$csvBalance = '-';
				if (($row['status'] ?? '') === 'completed' || ($row['status'] ?? '') === 'pending') {
					$csvBalance = isset($row['bill_balance']) ? $row['bill_balance'] : '';
				} elseif (($row['status'] ?? '') === 'failed') {
					$csvBalance = 'Not applied';
				}
				fputcsv($out, [
					$row['tx_date'],
					ucfirst((string)$row['payment_type']),
					$row['account_number'],
					$row['full_name'],
					$row['amount'],
					$csvBalance,
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
				$pdfBalance = '-';
				if (($row['status'] ?? '') === 'completed' || ($row['status'] ?? '') === 'pending') {
					$pdfBalance = isset($row['bill_balance']) ? number_format((float)$row['bill_balance'], 2) : '-';
				} elseif (($row['status'] ?? '') === 'failed') {
					$pdfBalance = 'Not applied';
				}
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars($row['tx_date']) . '</td>'
					. '<td>' . htmlspecialchars(ucfirst((string)$row['payment_type'])) . '</td>'
					. '<td>' . htmlspecialchars($row['account_number']) . '</td>'
					. '<td>' . htmlspecialchars($row['full_name']) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>'
					. '<td style="text-align:right;">' . htmlspecialchars($pdfBalance) . '</td>'
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
				'<th>Date</th><th>Type</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>Bill Balance (' . htmlspecialchars($currency) . ')</th><th>MPESA Ref</th><th>Status</th>' .
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
		$sql = "SELECT b.billing_month, u.account_number, u.full_name, b.amount, b.due_date, b.status, {$billTypeCaseSql} AS bill_type
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
			fputcsv($out, ['Billing Month', 'Type', 'Account', 'Customer', 'Amount (' . $currency . ')', 'Due Date', 'Status']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				fputcsv($out, [
					$row['billing_month'],
					ucfirst((string)$row['bill_type']),
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
					. '<td>' . htmlspecialchars(ucfirst((string)$row['bill_type'])) . '</td>'
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
				'<th>Billing Month</th><th>Type</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>Due Date</th><th>Status</th>' .
				'</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';
			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			$dompdf->stream('bills_report_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	} elseif (in_array($exportType, ['usage', 'usage_pdf'], true)) {
		$sqlWhere = "WHERE DATE(mr.billing_month) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search)";
		}
		$sql = "SELECT DATE_FORMAT(mr.billing_month, '%Y-%m') AS ym, u.account_number, u.full_name,
				MAX(mr.current_reading) - MIN(mr.current_reading) AS usage_units
			FROM meter_readings mr
			LEFT JOIN users u ON mr.user_id = u.id
			" . $sqlWhere . "
			GROUP BY ym, u.id
			ORDER BY ym DESC, u.account_number";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$likeUsage = '%' . $search_term . '%';
			$stmt->bindParam(':search', $likeUsage, PDO::PARAM_STR);
		}
		$stmt->execute();
		if ($exportType === 'usage') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'usage_report_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Month', 'Account', 'Customer', 'Usage (units)']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$usageUnits = isset($row['usage_units']) ? (float)$row['usage_units'] : 0.0;
				fputcsv($out, [
					$row['ym'],
					$row['account_number'],
					$row['full_name'],
					$usageUnits,
				]);
			}
			fclose($out);
		} else {
			// usage_pdf
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$usageUnits = isset($row['usage_units']) ? (float)$row['usage_units'] : 0.0;
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars($row['ym']) . '</td>'
					. '<td>' . htmlspecialchars($row['account_number']) . '</td>'
					. '<td>' . htmlspecialchars($row['full_name']) . '</td>'
					. '<td style="text-align:right;">' . number_format($usageUnits, 2) . '</td>'
				. '</tr>';
			}
			$html = '<html><head><meta charset="UTF-8"><title>Usage Report</title>
				<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111827;}h1{font-size:18px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 6px;}th{background:#f9fafb;text-align:left;font-size:10px;}td{text-align:left;font-size:10px;}</style>
				</head><body>' .
				'<h1>Water Usage Report</h1>' .
				'<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>' .
				'<table><thead><tr>' .
				'<th>Month</th><th>Account</th><th>Customer</th><th>Usage (units)</th>' .
				'</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';
			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			$dompdf->stream('usage_report_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	} elseif (in_array($exportType, ['payment_adjustments', 'payment_adjustments_pdf'], true)) {
		$sqlWhere = "WHERE pa.status = 'approved' AND DATE(COALESCE(pa.processed_at, pa.approved_at, pa.created_at)) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search OR CAST(pa.payment_id AS CHAR) LIKE :search)";
		}

		$sql = "SELECT
				COALESCE(pa.processed_at, pa.approved_at, pa.created_at) AS decided_at,
				pa.id,
				pa.adjustment_type,
				pa.amount,
				pa.reason,
				pa.payment_id,
				pa.bill_id,
				p.mpesa_receipt,
				u.account_number,
				u.full_name,
				au.full_name AS approver_name
			FROM payment_adjustments pa
			INNER JOIN payments p ON p.id = pa.payment_id
			LEFT JOIN users u ON u.id = pa.user_id
			LEFT JOIN users au ON au.id = pa.approved_by
			" . $sqlWhere . "
			ORDER BY COALESCE(pa.processed_at, pa.approved_at, pa.created_at) DESC, pa.id DESC";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$like = '%' . $search_term . '%';
			$stmt->bindParam(':search', $like, PDO::PARAM_STR);
		}
		$stmt->execute();

		if ($exportType === 'payment_adjustments') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'payment_adjustments_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Decision Date', 'Adjustment ID', 'Type', 'Payment ID', 'Bill ID', 'MPESA Ref', 'Account', 'Customer', 'Amount (' . $currency . ')', 'Reason', 'Approved By']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				fputcsv($out, [
					$row['decided_at'],
					$row['id'],
					ucfirst((string)$row['adjustment_type']),
					$row['payment_id'],
					$row['bill_id'],
					$row['mpesa_receipt'],
					$row['account_number'],
					$row['full_name'],
					$row['amount'],
					$row['reason'],
					$row['approver_name'],
				]);
			}
			fclose($out);
		} else {
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			$totalAdjusted = 0.0;
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$totalAdjusted += (float)$row['amount'];
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars((string)$row['decided_at']) . '</td>'
					. '<td>#' . (int)$row['id'] . '</td>'
					. '<td>' . htmlspecialchars(ucfirst((string)$row['adjustment_type'])) . '</td>'
					. '<td>#' . (int)$row['payment_id'] . '</td>'
					. '<td>' . (!empty($row['bill_id']) ? ('#' . (int)$row['bill_id']) : '-') . '</td>'
					. '<td>' . htmlspecialchars((string)($row['mpesa_receipt'] ?? '-')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['account_number'] ?? '-')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['full_name'] ?? '')) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['reason'] ?? '')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['approver_name'] ?? 'System')) . '</td>'
				. '</tr>';
			}

			$html = '<html><head><meta charset="UTF-8"><title>Payment Adjustment Audit</title>'
				. '<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111827;}h1{font-size:17px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 5px;}th{background:#f9fafb;text-align:left;font-size:9px;}td{font-size:9px;}</style>'
				. '</head><body>'
				. '<h1>Payment Adjustment Audit</h1>'
				. '<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>'
				. '<p>Total Approved Adjustments (' . htmlspecialchars($currency) . '): <strong>' . number_format($totalAdjusted, 2) . '</strong></p>'
				. '<table><thead><tr>'
				. '<th>Date</th><th>Adjustment</th><th>Type</th><th>Payment</th><th>Bill</th><th>Ref</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>Reason</th><th>Approved By</th>'
				. '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
				. '</body></html>';

			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'landscape');
			$dompdf->render();
			$dompdf->stream('payment_adjustments_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	} elseif (in_array($exportType, ['installment_allocations', 'installment_allocations_pdf'], true)) {
		$sqlWhere = "WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search OR CAST(b.id AS CHAR) LIKE :search)";
		}

		$sql = "SELECT
				COALESCE(p.transaction_date, p.created_at) AS payment_date,
				p.id AS payment_id,
				p.mpesa_receipt,
				p.amount AS payment_amount,
				ip.id AS plan_id,
				ip.status AS plan_status,
				ipi.sequence_no,
				ipi.due_date,
				a.allocated_amount,
				b.id AS bill_id,
				u.account_number,
				u.full_name
			FROM installment_payment_allocations a
			INNER JOIN installment_plans ip ON ip.id = a.plan_id
			INNER JOIN installment_plan_items ipi ON ipi.id = a.plan_item_id
			INNER JOIN payments p ON p.id = a.payment_id
			INNER JOIN bills b ON b.id = ip.bill_id
			LEFT JOIN users u ON u.id = b.user_id
			" . $sqlWhere . "
			ORDER BY COALESCE(p.transaction_date, p.created_at) DESC, p.id DESC, ipi.sequence_no ASC";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$like = '%' . $search_term . '%';
			$stmt->bindParam(':search', $like, PDO::PARAM_STR);
		}
		$stmt->execute();

		if ($exportType === 'installment_allocations') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'installment_allocations_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Payment Date', 'Payment ID', 'MPESA Ref', 'Bill ID', 'Account', 'Customer', 'Plan ID', 'Plan Status', 'Item #', 'Item Due Date', 'Allocated Amount (' . $currency . ')', 'Payment Amount (' . $currency . ')']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				fputcsv($out, [
					$row['payment_date'],
					$row['payment_id'],
					$row['mpesa_receipt'],
					$row['bill_id'],
					$row['account_number'],
					$row['full_name'],
					$row['plan_id'],
					$row['plan_status'],
					$row['sequence_no'],
					$row['due_date'],
					$row['allocated_amount'],
					$row['payment_amount'],
				]);
			}
			fclose($out);
		} else {
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			$totalAllocated = 0.0;
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$totalAllocated += (float)$row['allocated_amount'];
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars((string)$row['payment_date']) . '</td>'
					. '<td>#' . (int)$row['payment_id'] . '</td>'
					. '<td>' . htmlspecialchars((string)($row['mpesa_receipt'] ?? '-')) . '</td>'
					. '<td>#' . (int)$row['bill_id'] . '</td>'
					. '<td>' . htmlspecialchars((string)($row['account_number'] ?? '-')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['full_name'] ?? '')) . '</td>'
					. '<td>#' . (int)$row['plan_id'] . '</td>'
					. '<td>' . htmlspecialchars(ucfirst((string)($row['plan_status'] ?? ''))) . '</td>'
					. '<td>' . (int)$row['sequence_no'] . '</td>'
					. '<td>' . htmlspecialchars((string)$row['due_date']) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['allocated_amount'], 2) . '</td>'
				. '</tr>';
			}

			$html = '<html><head><meta charset="UTF-8"><title>Installment Allocation Audit</title>'
				. '<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111827;}h1{font-size:17px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 5px;}th{background:#f9fafb;text-align:left;font-size:9px;}td{font-size:9px;}</style>'
				. '</head><body>'
				. '<h1>Installment Allocation Audit</h1>'
				. '<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>'
				. '<p>Total Allocated (' . htmlspecialchars($currency) . '): <strong>' . number_format($totalAllocated, 2) . '</strong></p>'
				. '<table><thead><tr>'
				. '<th>Payment Date</th><th>Payment</th><th>Ref</th><th>Bill</th><th>Account</th><th>Customer</th><th>Plan</th><th>Status</th><th>Item #</th><th>Due Date</th><th>Allocated (' . htmlspecialchars($currency) . ')</th>'
				. '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
				. '</body></html>';

			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'landscape');
			$dompdf->render();
			$dompdf->stream('installment_allocations_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	} elseif (in_array($exportType, ['writeoff_waiver', 'writeoff_waiver_pdf'], true)) {
		$sqlWhere = "WHERE fai.entity_type IN ('bill_writeoff','bill_waiver') AND fai.status = 'approved' AND DATE(COALESCE(fai.approved_at, fai.created_at)) BETWEEN :from AND :to";
		if ($search_term !== '') {
			$sqlWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR CAST(fai.entity_id AS CHAR) LIKE :search OR fai.reference_no LIKE :search)";
		}

		$sql = "SELECT
				fai.id,
				fai.entity_type,
				fai.entity_id AS bill_id,
				fai.reference_no,
				fai.amount,
				fai.comments,
				fai.metadata_json,
				COALESCE(fai.approved_at, fai.created_at) AS decided_at,
				u.account_number,
				u.full_name,
				au.full_name AS approver_name
			FROM financial_approval_items fai
			LEFT JOIN bills b ON b.id = fai.entity_id
			LEFT JOIN users u ON u.id = b.user_id
			LEFT JOIN users au ON au.id = fai.approved_by
			" . $sqlWhere . "
			ORDER BY COALESCE(fai.approved_at, fai.created_at) DESC, fai.id DESC";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':from', $from_str);
		$stmt->bindParam(':to', $to_str);
		if ($search_term !== '') {
			$like = '%' . $search_term . '%';
			$stmt->bindParam(':search', $like, PDO::PARAM_STR);
		}
		$stmt->execute();

		if ($exportType === 'writeoff_waiver') {
			header('Content-Type: text/csv; charset=utf-8');
			$filename = 'writeoff_waiver_audit_' . $from_str . '_to_' . $to_str . '.csv';
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			$out = fopen('php://output', 'w');
			fputcsv($out, ['Decision Date', 'Approval ID', 'Type', 'Reference', 'Bill ID', 'Account', 'Customer', 'Amount (' . $currency . ')', 'Reason', 'Decision Note', 'Approved By']);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$metadata = [];
				if (!empty($row['metadata_json']) && is_string($row['metadata_json'])) {
					$decoded = json_decode($row['metadata_json'], true);
					if (is_array($decoded)) {
						$metadata = $decoded;
					}
				}
				$reason = (string)($metadata['reason'] ?? '');
				fputcsv($out, [
					$row['decided_at'],
					$row['id'],
					$row['entity_type'] === 'bill_waiver' ? 'Waiver' : 'Write-off',
					$row['reference_no'],
					$row['bill_id'],
					$row['account_number'],
					$row['full_name'],
					$row['amount'],
					$reason,
					$row['comments'],
					$row['approver_name'],
				]);
			}
			fclose($out);
		} else {
			require_once __DIR__ . '/../../vendor/autoload.php';
			$dompdf = new Dompdf();
			$rowsHtml = '';
			$totalAdjusted = 0.0;
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$metadata = [];
				if (!empty($row['metadata_json']) && is_string($row['metadata_json'])) {
					$decoded = json_decode($row['metadata_json'], true);
					if (is_array($decoded)) {
						$metadata = $decoded;
					}
				}
				$reason = (string)($metadata['reason'] ?? '');
				$totalAdjusted += (float)$row['amount'];
				$rowsHtml .= '<tr>'
					. '<td>' . htmlspecialchars((string)$row['decided_at']) . '</td>'
					. '<td>#' . (int)$row['id'] . '</td>'
					. '<td>' . htmlspecialchars($row['entity_type'] === 'bill_waiver' ? 'Waiver' : 'Write-off') . '</td>'
					. '<td>' . htmlspecialchars((string)($row['reference_no'] ?? '')) . '</td>'
					. '<td>#' . (int)$row['bill_id'] . '</td>'
					. '<td>' . htmlspecialchars((string)($row['account_number'] ?? '-')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['full_name'] ?? '')) . '</td>'
					. '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>'
					. '<td>' . htmlspecialchars($reason) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['comments'] ?? '')) . '</td>'
					. '<td>' . htmlspecialchars((string)($row['approver_name'] ?? 'System')) . '</td>'
				. '</tr>';
			}

			$html = '<html><head><meta charset="UTF-8"><title>Write-off and Waiver Audit</title>'
				. '<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111827;}h1{font-size:17px;margin-bottom:4px;}table{width:100%;border-collapse:collapse;margin-top:10px;}th,td{border:1px solid #e5e7eb;padding:4px 5px;}th{background:#f9fafb;text-align:left;font-size:9px;}td{font-size:9px;}</style>'
				. '</head><body>'
				. '<h1>Write-off and Waiver Audit</h1>'
				. '<p>Period: ' . htmlspecialchars($from_str) . ' to ' . htmlspecialchars($to_str) . '</p>'
				. '<p>Total Approved Adjustments (' . htmlspecialchars($currency) . '): <strong>' . number_format($totalAdjusted, 2) . '</strong></p>'
				. '<table><thead><tr>'
				. '<th>Date</th><th>Approval</th><th>Type</th><th>Ref</th><th>Bill</th><th>Account</th><th>Customer</th><th>Amount (' . htmlspecialchars($currency) . ')</th><th>Reason</th><th>Decision Note</th><th>Approved By</th>'
				. '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
				. '</body></html>';

			$dompdf->loadHtml($html);
			$dompdf->setPaper('A4', 'landscape');
			$dompdf->render();
			$dompdf->stream('writeoff_waiver_audit_' . $from_str . '_to_' . $to_str . '.pdf', ['Attachment' => true]);
		}
		exit;
	}
}

// Summary metrics
$billService = new Bill($db);
$paymentService = new Payment($db);

// Total billed in period (monthly billing only; registration is tracked separately)
$stmtBilled = $db->prepare("SELECT 
	COALESCE(SUM(amount),0) AS total_billed,
	COUNT(*) AS bills_count
	FROM bills b
	WHERE DATE(b.billing_month) BETWEEN :from AND :to
	AND {$monthlyBillPredicateSql}
	AND b.amount > 0");
$stmtBilled->bindParam(':from', $from_str);
$stmtBilled->bindParam(':to', $to_str);
$stmtBilled->execute();
$billedRow = $stmtBilled->fetch(PDO::FETCH_ASSOC) ?: ['total_billed' => 0, 'bills_count' => 0];

$stmtRegistrationBilled = $db->prepare("SELECT
	COALESCE(SUM(b.amount),0) AS total_registration_billed,
	COUNT(*) AS registration_bills_count
	FROM bills b
	WHERE DATE(b.billing_month) BETWEEN :from AND :to
	AND {$registrationBillPredicateSql}
	AND b.amount > 0");
$stmtRegistrationBilled->bindParam(':from', $from_str);
$stmtRegistrationBilled->bindParam(':to', $to_str);
$stmtRegistrationBilled->execute();
$registrationBilledRow = $stmtRegistrationBilled->fetch(PDO::FETCH_ASSOC) ?: ['total_registration_billed' => 0, 'registration_bills_count' => 0];

// Total collected in period (monthly billing only; registration is tracked separately)
$stmtCollected = $db->prepare("SELECT 
	COALESCE(SUM(p.amount),0) AS total_collected,
	COUNT(*) AS payments_count
	FROM payments p
	WHERE p.status = 'completed'
	AND DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to
	AND {$monthlyPaymentPredicateSql}
	AND p.amount > 0");
$stmtCollected->bindParam(':from', $from_str);
$stmtCollected->bindParam(':to', $to_str);
$stmtCollected->execute();
$collectedRow = $stmtCollected->fetch(PDO::FETCH_ASSOC) ?: ['total_collected' => 0, 'payments_count' => 0];

$stmtRegistrationCollected = $db->prepare("SELECT
	COALESCE(SUM(p.amount),0) AS total_registration_collected,
	COUNT(*) AS registration_payments_count
	FROM payments p
	WHERE p.status = 'completed'
	AND DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to
	AND {$registrationPaymentPredicateSql}
	AND p.amount > 0");
$stmtRegistrationCollected->bindParam(':from', $from_str);
$stmtRegistrationCollected->bindParam(':to', $to_str);
$stmtRegistrationCollected->execute();
$registrationCollectedRow = $stmtRegistrationCollected->fetch(PDO::FETCH_ASSOC) ?: ['total_registration_collected' => 0, 'registration_payments_count' => 0];

$monthlyOutstandingFromSql = "
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
	) pa_adj ON pa_adj.bill_id = b.id
	WHERE {$monthlyBillPredicateSql}
	AND b.amount > 0
";

$monthlyOutstandingExprSql = "GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0))";

// Total outstanding overall for monthly bills only (registration is tracked separately)
$stmtOutstanding = $db->query("SELECT 
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS total_outstanding,
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND {$monthlyOutstandingExprSql} > 0 THEN 1 ELSE 0 END),0) AS outstanding_bills
	{$monthlyOutstandingFromSql}");
$outstandingRow = $stmtOutstanding->fetch(PDO::FETCH_ASSOC) ?: ['total_outstanding' => 0, 'outstanding_bills' => 0];

// Accounts receivable aging for monthly bills only
$stmtAging = $db->query("SELECT
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND DATEDIFF(CURDATE(), b.due_date) <= 0 THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS current_bucket,
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND DATEDIFF(CURDATE(), b.due_date) BETWEEN 1 AND 30 THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS bucket_1_30,
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND DATEDIFF(CURDATE(), b.due_date) BETWEEN 31 AND 60 THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS bucket_31_60,
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND DATEDIFF(CURDATE(), b.due_date) BETWEEN 61 AND 90 THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS bucket_61_90,
	COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') AND DATEDIFF(CURDATE(), b.due_date) > 90 THEN {$monthlyOutstandingExprSql} ELSE 0 END),0) AS bucket_over_90
	{$monthlyOutstandingFromSql}");
$agingRow = $stmtAging->fetch(PDO::FETCH_ASSOC) ?: [
	'current_bucket' => 0,
	'bucket_1_30' => 0,
	'bucket_31_60' => 0,
	'bucket_61_90' => 0,
	'bucket_over_90' => 0,
];

$stmtOps = $db->prepare("SELECT
	COALESCE(SUM(consumption), 0) AS billed_units,
	COUNT(DISTINCT user_id) AS billed_accounts,
	COALESCE(AVG(consumption), 0) AS avg_units_per_bill
	FROM bills b
	WHERE DATE(b.billing_month) BETWEEN :from AND :to
	AND {$monthlyBillPredicateSql}");
$stmtOps->bindParam(':from', $from_str);
$stmtOps->bindParam(':to', $to_str);
$stmtOps->execute();
$operationalRow = $stmtOps->fetch(PDO::FETCH_ASSOC) ?: [
	'billed_units' => 0,
	'billed_accounts' => 0,
	'avg_units_per_bill' => 0,
];

$stmtUnbilledReads = $db->prepare("SELECT COUNT(*)
	FROM meter_readings
	WHERE status = 'approved'
		AND bill_id IS NULL
		AND DATE(billing_month) BETWEEN :from AND :to");
$stmtUnbilledReads->bindParam(':from', $from_str);
$stmtUnbilledReads->bindParam(':to', $to_str);
$stmtUnbilledReads->execute();
$operationalRow['unbilled_approved_readings'] = (int)$stmtUnbilledReads->fetchColumn();

$stmtCycle = $db->prepare("SELECT COALESCE(AVG(DATEDIFF(COALESCE(p.transaction_date, p.created_at), b.due_date)), 0)
	FROM payments p
	INNER JOIN bills b ON b.id = p.bill_id
	WHERE p.status = 'completed'
		AND DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to");
$stmtCycle->bindParam(':from', $from_str);
$stmtCycle->bindParam(':to', $to_str);
$stmtCycle->execute();
$operationalRow['avg_days_to_collect'] = round((float)$stmtCycle->fetchColumn(), 1);

$stmtPaymentAdjustments = $db->prepare("SELECT COUNT(*) AS adjustment_count, COALESCE(SUM(amount), 0) AS adjustment_total
	FROM payment_adjustments
	WHERE status = 'approved'
		AND DATE(COALESCE(processed_at, approved_at, created_at)) BETWEEN :from AND :to");
$stmtPaymentAdjustments->bindParam(':from', $from_str);
$stmtPaymentAdjustments->bindParam(':to', $to_str);
$stmtPaymentAdjustments->execute();
$paymentAdjustmentSummary = $stmtPaymentAdjustments->fetch(PDO::FETCH_ASSOC) ?: ['adjustment_count' => 0, 'adjustment_total' => 0];

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
		u.full_name, u.account_number,
		{$paymentTypeCaseSql} AS payment_type,
		b.amount AS bill_amount,
		GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0)) AS bill_balance
		FROM payments p
		LEFT JOIN bills b ON p.bill_id = b.id
		LEFT JOIN users u ON b.user_id = u.id
		LEFT JOIN (
			SELECT bill_id, COALESCE(SUM(amount), 0) AS completed_paid
			FROM payments
			WHERE status = 'completed' AND bill_id IS NOT NULL
			GROUP BY bill_id
		) p_paid ON p_paid.bill_id = b.id
		LEFT JOIN (
			SELECT p2.bill_id, COALESCE(SUM(pa.amount), 0) AS approved_adjustments
			FROM payment_adjustments pa
			INNER JOIN payments p2 ON p2.id = pa.payment_id
			WHERE pa.status = 'approved' AND p2.bill_id IS NOT NULL
			GROUP BY p2.bill_id
		) pa_adj ON pa_adj.bill_id = b.id
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
		, {$billTypeCaseSql} AS bill_type
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

// Installment allocation audit preview with pagination
$allocations = [];
$allocations_total = 0;
$allocations_total_pages = 1;
$allocations_grand_total = 0.0;
$allocations_page = isset($_GET['allocations_page']) ? max(1, (int)$_GET['allocations_page']) : 1;

$sqlAllocWhere = "WHERE DATE(COALESCE(p.transaction_date, p.created_at)) BETWEEN :from AND :to";
if ($search_term !== '') {
	$sqlAllocWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search OR CAST(b.id AS CHAR) LIKE :search)";
}

$sqlAllocCount = "SELECT COUNT(*) AS cnt
	FROM installment_payment_allocations a
	INNER JOIN installment_plans ip ON ip.id = a.plan_id
	INNER JOIN installment_plan_items ipi ON ipi.id = a.plan_item_id
	INNER JOIN payments p ON p.id = a.payment_id
	INNER JOIN bills b ON b.id = ip.bill_id
	LEFT JOIN users u ON u.id = b.user_id
	" . $sqlAllocWhere;

$stmtAllocCount = $db->prepare($sqlAllocCount);
$stmtAllocCount->bindParam(':from', $from_str);
$stmtAllocCount->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAlloc = '%' . $search_term . '%';
	$stmtAllocCount->bindParam(':search', $likeAlloc, PDO::PARAM_STR);
}
$stmtAllocCount->execute();
$allocCountRow = $stmtAllocCount->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0];
$allocations_total = (int)($allocCountRow['cnt'] ?? 0);
$allocations_total_pages = max(1, (int)ceil($allocations_total / $page_size));
if ($allocations_page > $allocations_total_pages) {
	$allocations_page = $allocations_total_pages;
}
$allocations_offset = ($allocations_page - 1) * $page_size;

$sqlAllocRows = "SELECT
		COALESCE(p.transaction_date, p.created_at) AS payment_date,
		p.id AS payment_id,
		p.mpesa_receipt,
		p.amount AS payment_amount,
		ip.id AS plan_id,
		ip.status AS plan_status,
		ipi.sequence_no,
		ipi.due_date,
		a.allocated_amount,
		b.id AS bill_id,
		u.account_number,
		u.full_name
	FROM installment_payment_allocations a
	INNER JOIN installment_plans ip ON ip.id = a.plan_id
	INNER JOIN installment_plan_items ipi ON ipi.id = a.plan_item_id
	INNER JOIN payments p ON p.id = a.payment_id
	INNER JOIN bills b ON b.id = ip.bill_id
	LEFT JOIN users u ON u.id = b.user_id
	" . $sqlAllocWhere . "
	ORDER BY COALESCE(p.transaction_date, p.created_at) DESC, p.id DESC, ipi.sequence_no ASC
	LIMIT :limit OFFSET :offset";

$stmtAllocRows = $db->prepare($sqlAllocRows);
$stmtAllocRows->bindParam(':from', $from_str);
$stmtAllocRows->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAllocRows = '%' . $search_term . '%';
	$stmtAllocRows->bindParam(':search', $likeAllocRows, PDO::PARAM_STR);
}
$stmtAllocRows->bindParam(':limit', $page_size, PDO::PARAM_INT);
$stmtAllocRows->bindParam(':offset', $allocations_offset, PDO::PARAM_INT);
$stmtAllocRows->execute();
$allocations = $stmtAllocRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlAllocTotal = "SELECT COALESCE(SUM(a.allocated_amount),0) AS total_amount
	FROM installment_payment_allocations a
	INNER JOIN installment_plans ip ON ip.id = a.plan_id
	INNER JOIN payments p ON p.id = a.payment_id
	INNER JOIN bills b ON b.id = ip.bill_id
	LEFT JOIN users u ON u.id = b.user_id
	" . $sqlAllocWhere;
$stmtAllocTotal = $db->prepare($sqlAllocTotal);
$stmtAllocTotal->bindParam(':from', $from_str);
$stmtAllocTotal->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAllocTotal = '%' . $search_term . '%';
	$stmtAllocTotal->bindParam(':search', $likeAllocTotal, PDO::PARAM_STR);
}
$stmtAllocTotal->execute();
$allocTotalRow = $stmtAllocTotal->fetch(PDO::FETCH_ASSOC) ?: ['total_amount' => 0];
$allocations_grand_total = (float)($allocTotalRow['total_amount'] ?? 0);

// Write-off / waiver approval audit preview with pagination
$adjustments = [];
$adjustments_total = 0;
$adjustments_total_pages = 1;
$adjustments_grand_total = 0.0;
$adjustments_page = isset($_GET['adjustments_page']) ? max(1, (int)$_GET['adjustments_page']) : 1;

$sqlAdjustWhere = "WHERE fai.entity_type IN ('bill_writeoff','bill_waiver') AND fai.status = 'approved' AND DATE(COALESCE(fai.approved_at, fai.created_at)) BETWEEN :from AND :to";
if ($search_term !== '') {
	$sqlAdjustWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR CAST(fai.entity_id AS CHAR) LIKE :search OR fai.reference_no LIKE :search)";
}

$sqlAdjustCount = "SELECT COUNT(*) AS cnt
	FROM financial_approval_items fai
	LEFT JOIN bills b ON b.id = fai.entity_id
	LEFT JOIN users u ON u.id = b.user_id
	" . $sqlAdjustWhere;
$stmtAdjustCount = $db->prepare($sqlAdjustCount);
$stmtAdjustCount->bindParam(':from', $from_str);
$stmtAdjustCount->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAdjust = '%' . $search_term . '%';
	$stmtAdjustCount->bindParam(':search', $likeAdjust, PDO::PARAM_STR);
}
$stmtAdjustCount->execute();
$adjustCountRow = $stmtAdjustCount->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0];
$adjustments_total = (int)($adjustCountRow['cnt'] ?? 0);
$adjustments_total_pages = max(1, (int)ceil($adjustments_total / $page_size));
if ($adjustments_page > $adjustments_total_pages) {
	$adjustments_page = $adjustments_total_pages;
}
$adjustments_offset = ($adjustments_page - 1) * $page_size;

$sqlAdjustRows = "SELECT
		fai.id,
		fai.entity_type,
		fai.entity_id AS bill_id,
		fai.reference_no,
		fai.amount,
		fai.comments,
		fai.metadata_json,
		COALESCE(fai.approved_at, fai.created_at) AS decided_at,
		u.account_number,
		u.full_name,
		au.full_name AS approver_name
	FROM financial_approval_items fai
	LEFT JOIN bills b ON b.id = fai.entity_id
	LEFT JOIN users u ON u.id = b.user_id
	LEFT JOIN users au ON au.id = fai.approved_by
	" . $sqlAdjustWhere . "
	ORDER BY COALESCE(fai.approved_at, fai.created_at) DESC, fai.id DESC
	LIMIT :limit OFFSET :offset";
$stmtAdjustRows = $db->prepare($sqlAdjustRows);
$stmtAdjustRows->bindParam(':from', $from_str);
$stmtAdjustRows->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAdjustRows = '%' . $search_term . '%';
	$stmtAdjustRows->bindParam(':search', $likeAdjustRows, PDO::PARAM_STR);
}
$stmtAdjustRows->bindParam(':limit', $page_size, PDO::PARAM_INT);
$stmtAdjustRows->bindParam(':offset', $adjustments_offset, PDO::PARAM_INT);
$stmtAdjustRows->execute();
$adjustments = $stmtAdjustRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlAdjustTotal = "SELECT COALESCE(SUM(fai.amount),0) AS total_amount
	FROM financial_approval_items fai
	LEFT JOIN bills b ON b.id = fai.entity_id
	LEFT JOIN users u ON u.id = b.user_id
	" . $sqlAdjustWhere;
$stmtAdjustTotal = $db->prepare($sqlAdjustTotal);
$stmtAdjustTotal->bindParam(':from', $from_str);
$stmtAdjustTotal->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likeAdjustTotal = '%' . $search_term . '%';
	$stmtAdjustTotal->bindParam(':search', $likeAdjustTotal, PDO::PARAM_STR);
}
$stmtAdjustTotal->execute();
$adjustTotalRow = $stmtAdjustTotal->fetch(PDO::FETCH_ASSOC) ?: ['total_amount' => 0];
$adjustments_grand_total = (float)($adjustTotalRow['total_amount'] ?? 0);

// Payment adjustment audit preview with pagination
$paymentAdjustments = [];
$payment_adjustments_total = 0;
$payment_adjustments_total_pages = 1;
$payment_adjustments_grand_total = 0.0;
$payment_adjustments_page = isset($_GET['payment_adjustments_page']) ? max(1, (int)$_GET['payment_adjustments_page']) : 1;

$sqlPaymentAdjustWhere = "WHERE pa.status = 'approved' AND DATE(COALESCE(pa.processed_at, pa.approved_at, pa.created_at)) BETWEEN :from AND :to";
if ($search_term !== '') {
	$sqlPaymentAdjustWhere .= " AND (u.account_number LIKE :search OR u.full_name LIKE :search OR p.mpesa_receipt LIKE :search OR CAST(pa.payment_id AS CHAR) LIKE :search)";
}

$sqlPaymentAdjustCount = "SELECT COUNT(*) AS cnt
	FROM payment_adjustments pa
	INNER JOIN payments p ON p.id = pa.payment_id
	LEFT JOIN users u ON u.id = pa.user_id
	" . $sqlPaymentAdjustWhere;
$stmtPaymentAdjustCount = $db->prepare($sqlPaymentAdjustCount);
$stmtPaymentAdjustCount->bindParam(':from', $from_str);
$stmtPaymentAdjustCount->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likePaymentAdjust = '%' . $search_term . '%';
	$stmtPaymentAdjustCount->bindParam(':search', $likePaymentAdjust, PDO::PARAM_STR);
}
$stmtPaymentAdjustCount->execute();
$paymentAdjustCountRow = $stmtPaymentAdjustCount->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0];
$payment_adjustments_total = (int)($paymentAdjustCountRow['cnt'] ?? 0);
$payment_adjustments_total_pages = max(1, (int)ceil($payment_adjustments_total / $page_size));
if ($payment_adjustments_page > $payment_adjustments_total_pages) {
	$payment_adjustments_page = $payment_adjustments_total_pages;
}
$payment_adjustments_offset = ($payment_adjustments_page - 1) * $page_size;

$sqlPaymentAdjustRows = "SELECT
		COALESCE(pa.processed_at, pa.approved_at, pa.created_at) AS decided_at,
		pa.id,
		pa.adjustment_type,
		pa.amount,
		pa.reason,
		pa.payment_id,
		pa.bill_id,
		p.mpesa_receipt,
		u.account_number,
		u.full_name,
		au.full_name AS approver_name
	FROM payment_adjustments pa
	INNER JOIN payments p ON p.id = pa.payment_id
	LEFT JOIN users u ON u.id = pa.user_id
	LEFT JOIN users au ON au.id = pa.approved_by
	" . $sqlPaymentAdjustWhere . "
	ORDER BY COALESCE(pa.processed_at, pa.approved_at, pa.created_at) DESC, pa.id DESC
	LIMIT :limit OFFSET :offset";
$stmtPaymentAdjustRows = $db->prepare($sqlPaymentAdjustRows);
$stmtPaymentAdjustRows->bindParam(':from', $from_str);
$stmtPaymentAdjustRows->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likePaymentAdjustRows = '%' . $search_term . '%';
	$stmtPaymentAdjustRows->bindParam(':search', $likePaymentAdjustRows, PDO::PARAM_STR);
}
$stmtPaymentAdjustRows->bindParam(':limit', $page_size, PDO::PARAM_INT);
$stmtPaymentAdjustRows->bindParam(':offset', $payment_adjustments_offset, PDO::PARAM_INT);
$stmtPaymentAdjustRows->execute();
$paymentAdjustments = $stmtPaymentAdjustRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlPaymentAdjustTotal = "SELECT COALESCE(SUM(pa.amount), 0) AS total_amount
	FROM payment_adjustments pa
	INNER JOIN payments p ON p.id = pa.payment_id
	LEFT JOIN users u ON u.id = pa.user_id
	" . $sqlPaymentAdjustWhere;
$stmtPaymentAdjustTotal = $db->prepare($sqlPaymentAdjustTotal);
$stmtPaymentAdjustTotal->bindParam(':from', $from_str);
$stmtPaymentAdjustTotal->bindParam(':to', $to_str);
if ($search_term !== '') {
	$likePaymentAdjustTotal = '%' . $search_term . '%';
	$stmtPaymentAdjustTotal->bindParam(':search', $likePaymentAdjustTotal, PDO::PARAM_STR);
}
$stmtPaymentAdjustTotal->execute();
$paymentAdjustTotalRow = $stmtPaymentAdjustTotal->fetch(PDO::FETCH_ASSOC) ?: ['total_amount' => 0];
$payment_adjustments_grand_total = (float)($paymentAdjustTotalRow['total_amount'] ?? 0);

$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4 mb-4 admin-shell admin-reports-page" id="reportsDensityTarget">
	<div class="fdb-header mb-4" role="banner">
		<!-- Decorative background layer -->
		<div class="fdb-bg" aria-hidden="true">
			<div class="fdb-grid-overlay"></div>
			<div class="fdb-glow fdb-glow--tr"></div>
			<div class="fdb-glow fdb-glow--bl"></div>
			<i class="bi bi-graph-up-arrow fdb-watermark-icon"></i>
		</div>
		<!-- Main content -->
		<div class="fdb-content">
			<div class="fdb-eyebrow">
				<span class="fdb-live-chip">
					<span class="fdb-live-dot" aria-hidden="true"></span>
					<span>Live Reporting</span>
				</span>
				<span class="fdb-sys-chip">
					<i class="bi bi-droplet-half" aria-hidden="true"></i>
					Water Billing System
				</span>
			</div>
			<h2 class="fdb-title">Financial Dashboard</h2>
			<p class="fdb-desc">Key financial KPIs, aging, and detailed payment and billing reports for fast collections tracking and M-Pesa reconciliation.</p>
		</div>
		<!-- Bottom info bar -->
		<div class="fdb-bar">
			<div class="fdb-bar-item">
				<span class="fdb-bar-label"><i class="bi bi-building" aria-hidden="true"></i> Company</span>
				<span class="fdb-bar-value"><?php echo htmlspecialchars($settings['company_name'] ?? ''); ?></span>
			</div>
			<div class="fdb-bar-sep" aria-hidden="true"></div>
			<div class="fdb-bar-item">
				<span class="fdb-bar-label"><i class="bi bi-currency-exchange" aria-hidden="true"></i> Reporting Currency</span>
				<span class="fdb-bar-value"><?php echo htmlspecialchars($currency); ?></span>
			</div>
			<div class="fdb-bar-sep" aria-hidden="true"></div>
			<div class="fdb-bar-item fdb-bar-item--period">
				<span class="fdb-bar-label"><i class="bi bi-calendar-range" aria-hidden="true"></i> Reporting Period</span>
				<span class="fdb-bar-value fdb-bar-period"><?php echo htmlspecialchars($from_display); ?> &ndash; <?php echo htmlspecialchars($to_display); ?></span>
			</div>
		</div>
	</div>

	<div class="card mb-3 financial-filters-card">
		<div class="card-header d-flex justify-content-between align-items-center">
			<div>
				<div class="financial-section-title mb-1">Filters</div>
				<small class="text-muted">Adjust period, scope, and search to update the dashboard below.</small>
			</div>
			<div>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-density-toggle data-density-target="#reportsDensityTarget" data-density-key="reports-tables" data-density-compact-text="Compact View" data-density-comfy-text="Comfortable View">
					<i class="bi bi-arrows-collapse"></i> <span class="js-density-label">Compact View</span>
				</button>
			</div>
		</div>
		<div class="card-body">
			<form class="row gy-2 gx-3 align-items-end" method="get" action="/reports">
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
					<a href="/reports" class="btn btn-outline-secondary btn-sm">Reset</a>
				</div>
			</form>
		</div>
	</div>

	<?php if ($maintenanceResult !== null): ?>
		<div class="alert <?php echo $maintenanceResult['succeeded'] ? 'alert-success' : 'alert-warning'; ?> mb-3" role="alert">
			<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
				<div>
					<strong><?php echo htmlspecialchars((string)$maintenanceResult['title']); ?></strong>
					<div class="small mt-1">Exit code: <?php echo (int)$maintenanceResult['exit_code']; ?></div>
				</div>
				<div class="small text-muted">Admin-only maintenance command output</div>
			</div>
			<pre class="mt-3 mb-0 p-3 bg-dark text-light rounded small" style="white-space:pre-wrap;"><?php echo htmlspecialchars(implode(PHP_EOL, $maintenanceResult['output'])); ?></pre>
		</div>
	<?php endif; ?>

	<?php
		$totalBilledAmount = (float)($billedRow['total_billed'] ?? 0);
		$totalCollectedAmount = (float)($collectedRow['total_collected'] ?? 0);
		$collectionRate = $totalBilledAmount > 0 ? min(100, round(($totalCollectedAmount / $totalBilledAmount) * 100, 1)) : 0.0;
		$auditBadgeClass = 'bg-success';
		$auditLabel = 'Healthy';
		$auditGeneratedAt = !empty($auditStatus['generated_at']) ? date('d-m-Y H:i', strtotime((string)$auditStatus['generated_at'])) : 'Unknown';
		$auditChecks = isset($auditStatus['checks']) && is_array($auditStatus['checks']) ? $auditStatus['checks'] : [];
		$auditHighlights = array_values(array_filter($auditChecks, static function ($check) {
			return in_array((string)($check['status'] ?? ''), ['FAIL', 'WARN'], true);
		}));
		if (($auditStatus['status'] ?? '') === 'failure') {
			$auditBadgeClass = 'bg-danger';
			$auditLabel = 'Failures Found';
		} elseif (($auditStatus['status'] ?? '') === 'warning') {
			$auditBadgeClass = 'bg-warning text-dark';
			$auditLabel = 'Warnings Found';
		} elseif ($auditStatus === null) {
			$auditBadgeClass = 'bg-secondary';
			$auditLabel = 'Not Run';
			$auditGeneratedAt = 'Not run yet';
		}
	?>
	<?php
		$usageExportCsvUrl = '/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'usage'])));
		$usageExportPdfUrl = '/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'usage_pdf'])));
		$allocExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'installment_allocations'])));
		$allocExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'installment_allocations_pdf'])));
		$adjustExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'writeoff_waiver'])));
		$adjustExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'writeoff_waiver_pdf'])));
		$paymentAdjustExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payment_adjustments'])));
		$paymentAdjustExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payment_adjustments_pdf'])));
	?>
	<div class="row g-3 mb-3 reports-overview-grid">
		<div class="col-xl-8 d-flex flex-column gap-3">
			<div class="card financial-snapshot-card reports-overview-panel">
				<div class="card-body py-3">
					<div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
						<div>
							<div class="financial-section-title mb-1">Performance Snapshot</div>
							<div class="small text-muted">Collection efficiency for the selected reporting range.</div>
						</div>
						<div class="financial-collection-rate">
							<span class="small text-muted me-2">Collection Rate</span>
							<strong><?php echo number_format($collectionRate, 1); ?>%</strong>
						</div>
					</div>
					<div class="progress mt-3 financial-collection-progress" role="progressbar" aria-label="Collection rate" aria-valuenow="<?php echo (int)$collectionRate; ?>" aria-valuemin="0" aria-valuemax="100">
						<div class="progress-bar" style="width: <?php echo number_format($collectionRate, 1, '.', ''); ?>%"></div>
					</div>
				</div>
			</div>

			<div>
				<h5 class="mb-2 financial-section-title">Key Metrics</h5>
				<div class="row g-3">
					<div class="col-md-6 col-xl-3">
						<div class="card shadow-sm h-100 metric-card metric-card-primary reports-kpi-card">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-1">
									<span class="text-muted text-uppercase small">Monthly Billed</span>
									<span class="badge bg-light text-dark">Bills: <?php echo (int)$billedRow['bills_count']; ?></span>
								</div>
								<div class="h5 mb-0"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$billedRow['total_billed'], 2); ?></div>
								<div class="text-muted small mt-1">For period <?php echo htmlspecialchars($from_display); ?> to <?php echo htmlspecialchars($to_display); ?></div>
							</div>
						</div>
					</div>
					<div class="col-md-6 col-xl-3">
						<div class="card shadow-sm h-100 metric-card metric-card-success reports-kpi-card">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-1">
									<span class="text-muted text-uppercase small">Monthly Collected</span>
									<span class="badge bg-light text-dark">Payments: <?php echo (int)$collectedRow['payments_count']; ?></span>
								</div>
								<div class="h5 mb-0 text-success"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$collectedRow['total_collected'], 2); ?></div>
								<div class="text-muted small mt-1">Completed non-registration payments in the selected period.</div>
							</div>
						</div>
					</div>
					<div class="col-md-6 col-xl-3">
						<div class="card shadow-sm h-100 metric-card reports-kpi-card border-info-subtle">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-1">
									<span class="text-muted text-uppercase small">Registration Fees</span>
									<span class="badge bg-info-subtle text-info-emphasis">Bills: <?php echo (int)$registrationBilledRow['registration_bills_count']; ?></span>
								</div>
								<div class="h5 mb-0 text-info-emphasis"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$registrationBilledRow['total_registration_billed'], 2); ?></div>
								<div class="text-muted small mt-1">Collected: <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$registrationCollectedRow['total_registration_collected'], 2); ?> across <?php echo (int)$registrationCollectedRow['registration_payments_count']; ?> payment(s).</div>
							</div>
						</div>
					</div>
					<div class="col-md-6 col-xl-3">
						<div class="card shadow-sm h-100 metric-card metric-card-danger reports-kpi-card">
							<div class="card-body">
								<div class="d-flex justify-content-between align-items-center mb-1">
									<span class="text-muted text-uppercase small">Monthly Outstanding</span>
									<span class="badge bg-warning text-dark">Bills: <?php echo (int)$outstandingRow['outstanding_bills']; ?></span>
								</div>
								<div class="h5 mb-0 text-danger"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$outstandingRow['total_outstanding'], 2); ?></div>
								<div class="text-muted small mt-1">Pending and overdue non-registration balances (overall).</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-4 d-flex flex-column gap-3">
			<div class="card border-0 shadow-sm reports-audit-card">
				<div class="card-body py-3">
					<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
						<div>
							<div class="financial-section-title mb-1">Last Audit Status</div>
							<?php if ($auditStatus !== null): ?>
								<div class="d-flex align-items-center gap-2 flex-wrap">
									<span class="badge <?php echo $auditBadgeClass; ?>"><?php echo htmlspecialchars($auditLabel); ?></span>
									<span class="small text-muted">Last run: <?php echo htmlspecialchars($auditGeneratedAt); ?></span>
								</div>
								<div class="small text-muted mt-2"><?php echo htmlspecialchars((string)($auditStatus['summary_line'] ?? 'No summary available.')); ?></div>
								<?php if (!empty($auditHighlights)): ?>
									<div class="mt-2">
										<?php foreach (array_slice($auditHighlights, 0, 2) as $highlight): ?>
											<div class="small text-muted"><?php echo htmlspecialchars((string)($highlight['label'] ?? 'Check')); ?>: <?php echo htmlspecialchars((string)($highlight['detail'] ?? '')); ?></div>
										<?php endforeach; ?>
									</div>
								<?php else: ?>
									<div class="small text-success mt-2">All billing integrity checks passed on the most recent run.</div>
								<?php endif; ?>
							<?php else: ?>
								<div class="small text-muted">No stored audit result yet. Run the audit once to populate this status card.</div>
							<?php endif; ?>
						</div>
						<div class="text-end">
							<a href="/reports#billingIntegrityTools" class="btn btn-outline-primary btn-sm">
								<i class="bi bi-shield-check me-1"></i> Open Integrity Tools
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-3 mb-4 reports-operations-row">
		<div class="col-xl-8 d-flex flex-column gap-3">
			<div class="card shadow-sm h-100 aging-card reports-surface-card">
				<div class="card-header bg-light d-flex justify-content-between align-items-center">
					<h6 class="mb-0">Accounts Receivable Aging</h6>
					<small class="text-muted">Current exposure across the monthly receivables ledger.</small>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Bucket</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
								</tr>
							</thead>
							<tbody>
								<tr><td>Current (Not Yet Due)</td><td class="text-end"><?php echo number_format((float)$agingRow['current_bucket'], 2); ?></td></tr>
								<tr><td>1 - 30 Days Overdue</td><td class="text-end"><?php echo number_format((float)$agingRow['bucket_1_30'], 2); ?></td></tr>
								<tr><td>31 - 60 Days Overdue</td><td class="text-end"><?php echo number_format((float)$agingRow['bucket_31_60'], 2); ?></td></tr>
								<tr><td>61 - 90 Days Overdue</td><td class="text-end"><?php echo number_format((float)$agingRow['bucket_61_90'], 2); ?></td></tr>
								<tr><td>Over 90 Days Overdue</td><td class="text-end text-danger"><?php echo number_format((float)$agingRow['bucket_over_90'], 2); ?></td></tr>
							</tbody>
						</table>
					</div>
					<p class="text-muted small mt-3 mb-0">This aging view follows a standard 0/30/60/90+ day breakdown for receivables.</p>
				</div>
			</div>

			<div class="card shadow-sm reports-surface-card">
				<div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
					<div>
						<h6 class="mb-0">Operational Billing Analytics</h6>
						<small class="text-muted">Selected-period monthly billing coverage and finance pressure indicators.</small>
					</div>
				</div>
				<div class="card-body">
					<div class="row g-3 reports-analytics-grid">
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Billed Consumption</div><div class="fw-semibold"><?php echo number_format((float)$operationalRow['billed_units'], 2); ?> units</div><div class="small text-muted mt-1">Across <?php echo (int)$operationalRow['billed_accounts']; ?> billed accounts.</div></div></div>
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Average Units per Bill</div><div class="fw-semibold"><?php echo number_format((float)$operationalRow['avg_units_per_bill'], 2); ?> units</div><div class="small text-muted mt-1">Tracks consumption mix over time.</div></div></div>
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Approved Reads Not Billed</div><div class="fw-semibold"><?php echo (int)$operationalRow['unbilled_approved_readings']; ?></div><div class="small text-muted mt-1">Practical proxy for billing coverage gaps.</div></div></div>
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Average Days to Collect</div><div class="fw-semibold"><?php echo number_format((float)$operationalRow['avg_days_to_collect'], 1); ?> days</div><div class="small text-muted mt-1">Measured from due date to completed payment.</div></div></div>
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Approved Payment Adjustments</div><div class="fw-semibold"><?php echo (int)($paymentAdjustmentSummary['adjustment_count'] ?? 0); ?> requests</div><div class="small text-muted mt-1">Total value: <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)($paymentAdjustmentSummary['adjustment_total'] ?? 0), 2); ?></div></div></div>
						<div class="col-md-6 col-xl-4"><div class="accounting-muted-box reports-analytics-tile p-3 h-100"><div class="small text-muted">Collections Interpretation</div><div class="fw-semibold"><?php echo number_format($collectionRate, 1); ?>% billing-to-cash conversion</div><div class="small text-muted mt-1">Use with aging and unbilled reads to monitor operational leakage.</div></div></div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-4 d-flex flex-column gap-3 reports-side-rail">
			<div class="card shadow-sm reports-tools-card" id="billingIntegrityTools">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h6 class="card-title mb-0"><i class="bi bi-shield-lock me-1"></i> Billing Integrity Tools</h6>
					<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Admin Only</span>
				</div>
				<div class="card-body py-3">
					<p class="text-muted small mb-3">Run the billing audit, preview repair actions, or apply the repair workflow without leaving the admin dashboard.</p>
					<div class="d-grid gap-2">
						<form method="post" action="/reports#billingIntegrityTools">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['reports_csrf']); ?>">
							<input type="hidden" name="maintenance_action" value="run_audit">
							<button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-activity me-1"></i> Run Audit</button>
						</form>
						<form method="post" action="/reports#billingIntegrityTools">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['reports_csrf']); ?>">
							<input type="hidden" name="maintenance_action" value="preview_repair">
							<button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-search me-1"></i> Preview Repair</button>
						</form>
						<form method="post" action="/reports#billingIntegrityTools" onsubmit="return confirm('Apply billing repair actions now? This updates live finance records.');">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['reports_csrf']); ?>">
							<input type="hidden" name="maintenance_action" value="apply_repair">
							<button type="submit" class="btn btn-danger btn-sm w-100"><i class="bi bi-wrench-adjustable-circle me-1"></i> Apply Repair</button>
						</form>
					</div>
				</div>
			</div>

			<div class="card quick-links-card reports-utility-card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h6 class="card-title mb-0"><i class="bi bi-lightning-charge me-1"></i> Quick Links</h6>
				</div>
				<div class="card-body py-3">
					<div class="quick-links-list d-flex flex-wrap gap-2">
						<a href="/dashboard" class="btn btn-sm btn-quick-link" title="Go to main dashboard"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
						<a href="/bills" class="btn btn-sm btn-quick-link" title="View customer bills"><i class="bi bi-receipt"></i><span>My Bills</span></a>
						<a href="/pay" class="btn btn-sm btn-quick-link" title="Initiate bill payment"><i class="bi bi-credit-card"></i><span>Pay Bill</span></a>
						<a href="/complaints" class="btn btn-sm btn-quick-link" title="View and manage complaints"><i class="bi bi-chat-left-text"></i><span>Complaints</span></a>
						<a href="/invoicing" class="btn btn-sm btn-quick-link" title="Open invoicing workspace"><i class="bi bi-file-earmark-text"></i><span>Invoicing</span></a>
						<a href="/reports#billingIntegrityTools" class="btn btn-sm btn-quick-link" title="Open billing integrity tools"><i class="bi bi-shield-check"></i><span>Integrity Tools</span></a>
					</div>
				</div>
			</div>

			<div class="card shadow-sm reports-export-card reports-utility-card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h6 class="card-title mb-0"><i class="bi bi-box-arrow-down me-1"></i> Export Center</h6>
				</div>
				<div class="card-body py-3">
					<div class="reports-export-group">
						<div class="small text-uppercase text-muted fw-semibold mb-2">Usage Exports</div>
						<div class="d-flex flex-wrap gap-2 mb-3">
							<a href="<?php echo $usageExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Usage CSV</a>
							<a href="<?php echo $usageExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Usage PDF</a>
						</div>
					</div>
					<div class="reports-export-group">
						<div class="small text-uppercase text-muted fw-semibold mb-2">Finance Audit Exports</div>
						<div class="d-flex flex-wrap gap-2">
							<a href="<?php echo $allocExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Installments CSV</a>
							<a href="<?php echo $allocExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Installments PDF</a>
							<a href="<?php echo $adjustExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Write-offs CSV</a>
							<a href="<?php echo $adjustExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Write-offs PDF</a>
							<a href="<?php echo $paymentAdjustExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download"></i> Adjustments CSV</a>
							<a href="<?php echo $paymentAdjustExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Adjustments PDF</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<h5 class="mt-4 mb-2 financial-section-title">Detailed Reports</h5>
	<p class="text-muted small mb-3">Review transaction-level details and download export-ready files.</p>
	<div class="row g-3">
		<?php if ($report_scope === 'all' || $report_scope === 'payments'): ?>
		<div class="col-12" id="paymentsReportSection">
			<div class="card h-100 report-table-card">
				<div class="card-header d-flex justify-content-between align-items-center report-card-header">
					<div>
						<h5 class="card-title mb-0">Payment Report</h5>
						<small class="text-muted">All payment attempts for the selected period and filters, labelled as monthly or registration.</small>
					</div>
					<div>
						<?php $paymentsExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payments']))); ?>
						<?php $paymentsExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'payments_pdf']))); ?>
						<a href="<?php echo $paymentsExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm me-1">
							<i class="bi bi-download"></i> Download CSV
						</a>
						<a href="<?php echo $paymentsExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm">
							<i class="bi bi-file-earmark-pdf"></i> Download PDF
						</a>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Date</th>
									<th>Type</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>MPESA Ref</th>
									<th class="text-end">Balance (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($payments)): ?>
								<tr>
									<td colspan="8" class="text-center text-muted py-3">No payments found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($payments as $p): ?>
									<tr>
										<td data-label="Date"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($p['transaction_date'] ?? $p['created_at']))); ?></td>
										<td data-label="Type"><span class="badge bg-<?php echo ($p['payment_type'] ?? 'monthly') === 'registration' ? 'info' : 'primary'; ?>-subtle text-<?php echo ($p['payment_type'] ?? 'monthly') === 'registration' ? 'info' : 'primary'; ?>-emphasis"><?php echo htmlspecialchars(ucfirst((string)($p['payment_type'] ?? 'monthly'))); ?></span></td>
										<td data-label="Account"><?php echo htmlspecialchars($p['account_number'] ?? '-'); ?></td>
										<td data-label="Customer"><?php echo htmlspecialchars($p['full_name'] ?? ''); ?></td>
										<td data-label="Amount (<?php echo htmlspecialchars($currency); ?>)" class="text-end"><?php echo number_format((float)$p['amount'], 2); ?></td>
										<td data-label="MPESA Ref"><?php echo htmlspecialchars($p['mpesa_receipt'] ?? '-'); ?></td>
										<td data-label="Balance (<?php echo htmlspecialchars($currency); ?>)" class="text-end">
											<?php
												if (($p['status'] ?? '') === 'failed') {
													echo 'Not applied';
												} else {
													echo isset($p['bill_balance']) ? number_format((float)$p['bill_balance'], 2) : '-';
												}
											?>
										</td>
										<td data-label="Status">
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
					<div class="px-3 py-2 border-top small text-end report-grand-total">
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
			<div class="card h-100 report-table-card">
				<div class="card-header d-flex justify-content-between align-items-center report-card-header">
					<div>
						<h5 class="card-title mb-0">Billing Report</h5>
						<small class="text-muted">Bills issued for the selected period and filters, labelled as monthly or registration.</small>
					</div>
					<div>
						<?php $billsExportCsvUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'bills']))); ?>
						<?php $billsExportPdfUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['export' => 'bills_pdf']))); ?>
						<a href="<?php echo $billsExportCsvUrl; ?>" class="btn btn-outline-primary btn-sm me-1">
							<i class="bi bi-download"></i> Download CSV
						</a>
						<a href="<?php echo $billsExportPdfUrl; ?>" class="btn btn-outline-secondary btn-sm">
							<i class="bi bi-file-earmark-pdf"></i> Download PDF
						</a>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Billing Month</th>
									<th>Type</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Due Date</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($bills)): ?>
								<tr>
									<td colspan="8" class="text-center text-muted py-3">No bills found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($bills as $bill): ?>
									<tr>
										<td data-label="Billing Month"><?php echo htmlspecialchars(date('M Y', strtotime($bill['billing_month']))); ?></td>
										<td data-label="Type">
								<?php if (($bill['bill_type'] ?? 'monthly') === 'registration'): ?>
									<span class="badge badge-registration"><i class="bi bi-person-fill-check me-1"></i>Registration</span>
								<?php else: ?>
									<span class="badge badge-monthly"><i class="bi bi-calendar3 me-1"></i>Monthly</span>
								<?php endif; ?>
							</td>
										<td data-label="Account"><?php echo htmlspecialchars($bill['account_number'] ?? '-'); ?></td>
										<td data-label="Customer"><?php echo htmlspecialchars($bill['full_name'] ?? ''); ?></td>
										<td data-label="Amount (<?php echo htmlspecialchars($currency); ?>)" class="text-end"><?php echo number_format((float)$bill['amount'], 2); ?></td>
										<td data-label="Due Date"><?php echo htmlspecialchars($bill['due_date']); ?></td>
										<td data-label="Status">
											<span class="badge bg-<?php echo $bill['status'] === 'paid' ? 'success' : (in_array($bill['status'], ['pending','overdue'], true) ? 'warning' : 'secondary'); ?>">
												<?php echo htmlspecialchars(ucfirst($bill['status'])); ?>
											</span>
										</td>
										<td data-label="Actions">
											<?php if (in_array($bill['status'], ['pending', 'overdue'], true)): ?>
												<button type="button" class="btn btn-sm btn-outline-info send-reminder-btn" data-bill-id="<?php echo (int)$bill['id']; ?>" title="Send payment reminder">
													<i class="bi bi-bell me-1"></i>Remind
												</button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end report-grand-total">
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
		<div class="col-12" id="allocationAuditSection">
			<div class="card h-100 report-table-card">
				<div class="card-header d-flex justify-content-between align-items-center report-card-header">
					<div>
						<h5 class="card-title mb-0">Installment Allocation Audit</h5>
						<small class="text-muted">Allocation rows posted against installment plans in the selected period.</small>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Payment Date</th>
									<th>Payment</th>
									<th>Bill</th>
									<th>Account</th>
									<th>Customer</th>
									<th>Plan</th>
									<th>Item #</th>
									<th class="text-end">Allocated (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($allocations)): ?>
								<tr>
									<td colspan="9" class="text-center text-muted py-3">No installment allocations found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($allocations as $alloc): ?>
									<tr>
										<td data-label="Payment Date"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$alloc['payment_date']))); ?></td>
										<td data-label="Payment">#<?php echo (int)$alloc['payment_id']; ?> <?php echo !empty($alloc['mpesa_receipt']) ? '(' . htmlspecialchars((string)$alloc['mpesa_receipt']) . ')' : ''; ?></td>
										<td data-label="Bill">#<?php echo (int)$alloc['bill_id']; ?></td>
										<td data-label="Account"><?php echo htmlspecialchars((string)($alloc['account_number'] ?? '-')); ?></td>
										<td data-label="Customer"><?php echo htmlspecialchars((string)($alloc['full_name'] ?? '')); ?></td>
										<td data-label="Plan">#<?php echo (int)$alloc['plan_id']; ?> (<?php echo htmlspecialchars((string)$alloc['plan_status']); ?>)</td>
										<td data-label="Item #"><?php echo (int)$alloc['sequence_no']; ?> (due <?php echo htmlspecialchars(date('d-m-Y', strtotime((string)$alloc['due_date']))); ?>)</td>
										<td data-label="Allocated" class="text-end"><?php echo number_format((float)$alloc['allocated_amount'], 2); ?></td>
										<td data-label="Action"><a class="btn btn-outline-dark btn-sm" href="/admin/bill-detail?bill_id=<?php echo (int)$alloc['bill_id']; ?>">Open Bill</a></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end report-grand-total">
						<strong>Grand Total (<?php echo htmlspecialchars($currency); ?>):</strong>
						<?php echo number_format($allocations_grand_total, 2); ?>
					</div>
					<?php if ($allocations_total_pages > 1): ?>
					<nav class="mt-2">
						<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
							<?php
								$allocPrevPage = max(1, $allocations_page - 1);
								$allocNextPage = min($allocations_total_pages, $allocations_page + 1);
							?>
							<li class="page-item <?php echo $allocations_page <= 1 ? 'disabled' : ''; ?>">
								<?php $allocFirstUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['allocations_page' => 1, 'adjustments_page' => $adjustments_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-alloc-page-link" href="<?php echo $allocFirstUrl; ?>" aria-label="First" title="Go to first page"><span aria-hidden="true">&laquo;&laquo;</span></a>
							</li>
							<li class="page-item <?php echo $allocations_page <= 1 ? 'disabled' : ''; ?>">
								<?php $allocPrevUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['allocations_page' => $allocPrevPage, 'adjustments_page' => $adjustments_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-alloc-page-link" href="<?php echo $allocPrevUrl; ?>" aria-label="Previous" title="Go to previous page"><span aria-hidden="true">&laquo;</span></a>
							</li>
							<?php for ($i = 1; $i <= $allocations_total_pages; $i++): ?>
								<?php $allocPageUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['allocations_page' => $i, 'adjustments_page' => $adjustments_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<li class="page-item <?php echo $i === $allocations_page ? 'active' : ''; ?>">
									<a class="page-link js-alloc-page-link" href="<?php echo $allocPageUrl; ?>" title="Go to page <?php echo $i; ?>"><?php echo $i; ?></a>
								</li>
							<?php endfor; ?>
							<li class="page-item <?php echo $allocations_page >= $allocations_total_pages ? 'disabled' : ''; ?>">
								<?php $allocNextUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['allocations_page' => $allocNextPage, 'adjustments_page' => $adjustments_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-alloc-page-link" href="<?php echo $allocNextUrl; ?>" aria-label="Next" title="Go to next page"><span aria-hidden="true">&raquo;</span></a>
							</li>
							<li class="page-item <?php echo $allocations_page >= $allocations_total_pages ? 'disabled' : ''; ?>">
								<?php $allocLastUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['allocations_page' => $allocations_total_pages, 'adjustments_page' => $adjustments_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-alloc-page-link" href="<?php echo $allocLastUrl; ?>" aria-label="Last" title="Go to last page"><span aria-hidden="true">&raquo;&raquo;</span></a>
							</li>
						</ul>
					</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-12" id="adjustmentAuditSection">
			<div class="card h-100 report-table-card">
				<div class="card-header d-flex justify-content-between align-items-center report-card-header">
					<div>
						<h5 class="card-title mb-0">Write-off / Waiver Audit</h5>
						<small class="text-muted">Approved write-off and waiver decisions for the selected period.</small>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Date</th>
									<th>Approval</th>
									<th>Type</th>
									<th>Bill</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Approved By</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($adjustments)): ?>
								<tr>
									<td colspan="9" class="text-center text-muted py-3">No approved write-off or waiver records found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($adjustments as $adj): ?>
									<?php
										$adjMetadata = [];
										if (!empty($adj['metadata_json']) && is_string($adj['metadata_json'])) {
											$decodedAdj = json_decode($adj['metadata_json'], true);
											if (is_array($decodedAdj)) {
												$adjMetadata = $decodedAdj;
											}
										}
										$adjReason = (string)($adjMetadata['reason'] ?? '');
									?>
									<tr>
										<td data-label="Date"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$adj['decided_at']))); ?></td>
										<td data-label="Approval">#<?php echo (int)$adj['id']; ?> <?php echo !empty($adj['reference_no']) ? '(' . htmlspecialchars((string)$adj['reference_no']) . ')' : ''; ?></td>
										<td data-label="Type"><?php echo htmlspecialchars($adj['entity_type'] === 'bill_waiver' ? 'Waiver' : 'Write-off'); ?></td>
										<td data-label="Bill">#<?php echo (int)$adj['bill_id']; ?><?php echo $adjReason !== '' ? ' - ' . htmlspecialchars($adjReason) : ''; ?></td>
										<td data-label="Account"><?php echo htmlspecialchars((string)($adj['account_number'] ?? '-')); ?></td>
										<td data-label="Customer"><?php echo htmlspecialchars((string)($adj['full_name'] ?? '')); ?></td>
										<td data-label="Amount" class="text-end"><?php echo number_format((float)$adj['amount'], 2); ?></td>
										<td data-label="Approved By"><?php echo htmlspecialchars((string)($adj['approver_name'] ?? 'System')); ?></td>
										<td data-label="Action" class="d-flex gap-1 flex-wrap">
											<a class="btn btn-outline-dark btn-sm" href="/admin/bill-detail?bill_id=<?php echo (int)$adj['bill_id']; ?>">Bill</a>
											<a class="btn btn-outline-secondary btn-sm" href="/admin/approvals?status=approved#finance-items">Approval</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end report-grand-total">
						<strong>Grand Total (<?php echo htmlspecialchars($currency); ?>):</strong>
						<?php echo number_format($adjustments_grand_total, 2); ?>
					</div>
					<?php if ($adjustments_total_pages > 1): ?>
					<nav class="mt-2">
						<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
							<?php
								$adjustPrevPage = max(1, $adjustments_page - 1);
								$adjustNextPage = min($adjustments_total_pages, $adjustments_page + 1);
							?>
							<li class="page-item <?php echo $adjustments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $adjustFirstUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['adjustments_page' => 1, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-adjust-page-link" href="<?php echo $adjustFirstUrl; ?>" aria-label="First" title="Go to first page"><span aria-hidden="true">&laquo;&laquo;</span></a>
							</li>
							<li class="page-item <?php echo $adjustments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $adjustPrevUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['adjustments_page' => $adjustPrevPage, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-adjust-page-link" href="<?php echo $adjustPrevUrl; ?>" aria-label="Previous" title="Go to previous page"><span aria-hidden="true">&laquo;</span></a>
							</li>
							<?php for ($i = 1; $i <= $adjustments_total_pages; $i++): ?>
								<?php $adjustPageUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['adjustments_page' => $i, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<li class="page-item <?php echo $i === $adjustments_page ? 'active' : ''; ?>">
									<a class="page-link js-adjust-page-link" href="<?php echo $adjustPageUrl; ?>" title="Go to page <?php echo $i; ?>"><?php echo $i; ?></a>
								</li>
							<?php endfor; ?>
							<li class="page-item <?php echo $adjustments_page >= $adjustments_total_pages ? 'disabled' : ''; ?>">
								<?php $adjustNextUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['adjustments_page' => $adjustNextPage, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-adjust-page-link" href="<?php echo $adjustNextUrl; ?>" aria-label="Next" title="Go to next page"><span aria-hidden="true">&raquo;</span></a>
							</li>
							<li class="page-item <?php echo $adjustments_page >= $adjustments_total_pages ? 'disabled' : ''; ?>">
								<?php $adjustLastUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['adjustments_page' => $adjustments_total_pages, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link js-adjust-page-link" href="<?php echo $adjustLastUrl; ?>" aria-label="Last" title="Go to last page"><span aria-hidden="true">&raquo;&raquo;</span></a>
							</li>
						</ul>
					</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-12" id="paymentAdjustmentAuditSection">
			<div class="card h-100 report-table-card">
				<div class="card-header d-flex justify-content-between align-items-center report-card-header">
					<div>
						<h5 class="card-title mb-0">Payment Adjustment Audit</h5>
						<small class="text-muted">Approved refunds and chargebacks for the selected period.</small>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-striped table-sm mb-0 align-middle table-density-target">
							<thead class="table-light">
								<tr>
									<th>Date</th>
									<th>Adjustment</th>
									<th>Type</th>
									<th>Payment</th>
									<th>Account</th>
									<th>Customer</th>
									<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
									<th>Approved By</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($paymentAdjustments)): ?>
								<tr>
									<td colspan="9" class="text-center text-muted py-3">No approved payment adjustments found for this period.</td>
								</tr>
							<?php else: ?>
								<?php foreach ($paymentAdjustments as $paymentAdjustment): ?>
									<tr>
										<td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$paymentAdjustment['decided_at']))); ?></td>
										<td>#<?php echo (int)$paymentAdjustment['id']; ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$paymentAdjustment['adjustment_type'])); ?></td>
										<td>#<?php echo (int)$paymentAdjustment['payment_id']; ?> <?php echo !empty($paymentAdjustment['mpesa_receipt']) ? '(' . htmlspecialchars((string)$paymentAdjustment['mpesa_receipt']) . ')' : ''; ?></td>
										<td><?php echo htmlspecialchars((string)($paymentAdjustment['account_number'] ?? '-')); ?></td>
										<td><?php echo htmlspecialchars((string)($paymentAdjustment['full_name'] ?? '')); ?></td>
										<td class="text-end"><?php echo number_format((float)$paymentAdjustment['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars((string)($paymentAdjustment['approver_name'] ?? 'System')); ?></td>
										<td class="d-flex gap-1 flex-wrap">
											<?php if (!empty($paymentAdjustment['bill_id'])): ?>
												<a class="btn btn-outline-dark btn-sm" href="/admin/bill-detail?bill_id=<?php echo (int)$paymentAdjustment['bill_id']; ?>">Bill</a>
											<?php endif; ?>
											<a class="btn btn-outline-secondary btn-sm" href="/admin/approvals?status=approved#finance-items">Approval</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="px-3 py-2 border-top small text-end report-grand-total">
						<strong>Grand Total (<?php echo htmlspecialchars($currency); ?>):</strong>
						<?php echo number_format($payment_adjustments_grand_total, 2); ?>
					</div>
					<?php if ($payment_adjustments_total_pages > 1): ?>
					<nav class="mt-2">
						<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
							<?php
								$paymentAdjustPrevPage = max(1, $payment_adjustments_page - 1);
								$paymentAdjustNextPage = min($payment_adjustments_total_pages, $payment_adjustments_page + 1);
							?>
							<li class="page-item <?php echo $payment_adjustments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $paymentAdjustFirstUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payment_adjustments_page' => 1, 'adjustments_page' => $adjustments_page, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link" href="<?php echo $paymentAdjustFirstUrl; ?>" aria-label="First"><span aria-hidden="true">&laquo;&laquo;</span></a>
							</li>
							<li class="page-item <?php echo $payment_adjustments_page <= 1 ? 'disabled' : ''; ?>">
								<?php $paymentAdjustPrevUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payment_adjustments_page' => $paymentAdjustPrevPage, 'adjustments_page' => $adjustments_page, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link" href="<?php echo $paymentAdjustPrevUrl; ?>" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>
							</li>
							<?php for ($i = 1; $i <= $payment_adjustments_total_pages; $i++): ?>
								<?php $paymentAdjustPageUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payment_adjustments_page' => $i, 'adjustments_page' => $adjustments_page, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<li class="page-item <?php echo $i === $payment_adjustments_page ? 'active' : ''; ?>">
									<a class="page-link" href="<?php echo $paymentAdjustPageUrl; ?>"><?php echo $i; ?></a>
								</li>
							<?php endfor; ?>
							<li class="page-item <?php echo $payment_adjustments_page >= $payment_adjustments_total_pages ? 'disabled' : ''; ?>">
								<?php $paymentAdjustNextUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payment_adjustments_page' => $paymentAdjustNextPage, 'adjustments_page' => $adjustments_page, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link" href="<?php echo $paymentAdjustNextUrl; ?>" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
							</li>
							<li class="page-item <?php echo $payment_adjustments_page >= $payment_adjustments_total_pages ? 'disabled' : ''; ?>">
								<?php $paymentAdjustLastUrl = '/admin/reports?' . htmlspecialchars(http_build_query(array_merge($baseQuery, ['payment_adjustments_page' => $payment_adjustments_total_pages, 'adjustments_page' => $adjustments_page, 'allocations_page' => $allocations_page, 'payments_page' => $payments_page, 'bills_page' => $bills_page]))); ?>
								<a class="page-link" href="<?php echo $paymentAdjustLastUrl; ?>" aria-label="Last"><span aria-hidden="true">&raquo;&raquo;</span></a>
							</li>
						</ul>
					</nav>
					<?php endif; ?>
				</div>
			</div>
		</div>
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
		var allocationContainer = document.getElementById('allocationAuditSection');
		if (allocationContainer) {
			var allocationLinks = allocationContainer.querySelectorAll('.js-alloc-page-link');
			allocationLinks.forEach(function(link) {
				link.addEventListener('click', function (e) {
					e.preventDefault();
					loadSection('allocationAuditSection', this.getAttribute('href'));
				});
			});
		}
		var adjustmentContainer = document.getElementById('adjustmentAuditSection');
		if (adjustmentContainer) {
			var adjustmentLinks = adjustmentContainer.querySelectorAll('.js-adjust-page-link');
			adjustmentLinks.forEach(function(link) {
				link.addEventListener('click', function (e) {
					e.preventDefault();
					loadSection('adjustmentAuditSection', this.getAttribute('href'));
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
				attachReminderButtonHandlers();
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

	function attachReminderButtonHandlers() {
		var reminderButtons = document.querySelectorAll('.send-reminder-btn');
		reminderButtons.forEach(function(btn) {
			btn.addEventListener('click', function() {
				var billId = this.getAttribute('data-bill-id');
				if (!billId) return;

				var btn = this;
				var originalText = btn.innerHTML;
				btn.disabled = true;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending...';

				var formData = new FormData();
				formData.append('bill_id', billId);

				fetch('/api/bills/send-reminder', {
					method: 'POST',
					body: formData,
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						if (data.success) {
							if (window.showToast) {
								showToast(data.message || 'Reminder sent successfully', 'success');
							}
							// Change button appearance after success
							btn.classList.remove('btn-outline-info');
							btn.classList.add('btn-outline-success');
							btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent';
						} else {
							if (window.showToast) {
								showToast(data.message || 'Failed to send reminder', 'danger');
							}
							btn.disabled = false;
							btn.innerHTML = originalText;
						}
					})
					.catch(function(err) {
						if (window.showToast) {
							showToast('Error sending reminder: ' + (err && err.message ? err.message : 'Unknown error'), 'danger');
						}
						btn.disabled = false;
						btn.innerHTML = originalText;
					});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			attachPaginationHandlers();
			attachReminderButtonHandlers();
		});
	} else {
		attachPaginationHandlers();
		attachReminderButtonHandlers();
	}
})();
</script>
