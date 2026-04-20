<?php
session_start();
if(!isset($_SESSION['user_id'])) {
	header("Location: /login");
	exit;
}

require_once __DIR__ . '/../config/database.php';require_once __DIR__ . '/../includes/Auth.php';require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Bill.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
	header("Location: /login");
	exit;
}
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
	header("Location: /login");
	exit;
}

$page_title = "My Bills";
require_once __DIR__ . '/../templates/header.php';

$bills = [];
$total_paid = 0.0;
$total_unpaid = 0.0;
$count_paid = 0;
$count_unpaid = 0;
$count_overdue = 0;
$next_due_date = null;
$hasRegistrationBillsInView = false;
$hasUsageBillsInView = false;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$fromPeriod = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$toPeriod = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

if (!preg_match('/^\d{4}-\d{2}$/', $fromPeriod)) {
	$fromPeriod = '';
}
if (!preg_match('/^\d{4}-\d{2}$/', $toPeriod)) {
	$toPeriod = '';
}

$fromDate = $fromPeriod !== '' ? ($fromPeriod . '-01') : null;
$toDate = $toPeriod !== '' ? date('Y-m-t', strtotime($toPeriod . '-01')) : null;

$billWithinPeriod = function(array $billRow) use ($fromDate, $toDate): bool {
	$month = isset($billRow['billing_month']) ? date('Y-m-01', strtotime((string)$billRow['billing_month'])) : null;
	if (!$month) {
		return false;
	}
	if ($fromDate !== null && $month < $fromDate) {
		return false;
	}
	if ($toDate !== null && $month > $toDate) {
		return false;
	}
	return true;
};

// Optional: export this customer's bills as CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
	if (!$db) {
		header('Location: /bills');
		exit;
	}
	$billService = new Bill($db);
	$billsCsv = $billService->getBillsByUser($_SESSION['user_id']);
	if ($fromDate !== null || $toDate !== null) {
		$billsCsv = array_values(array_filter($billsCsv, $billWithinPeriod));
	}
	header('Content-Type: text/csv; charset=utf-8');
	$filename = 'my_bills_' . date('Ymd') . '.csv';
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	$out = fopen('php://output', 'w');
	fputcsv($out, ['Bill Type', 'Billing Month', 'Previous Reading', 'Current Reading', 'Consumption (m3)', 'Rate (KES/m3)', 'Service Charge (KES)', 'Base Amount (KES)', 'Tax Rate (%)', 'Tax Amount (KES)', 'Amount (KES)', 'Status', 'Due Date']);
	foreach ($billsCsv as $billRow) {
		$billTypeLabel = $billService->getBillTypeLabel($billRow);
		fputcsv($out, [
			$billTypeLabel,
			$billRow['billing_month'],
			$billRow['previous_reading'],
			$billRow['current_reading'],
			$billRow['consumption'],
			$billRow['rate_per_unit'],
			$billRow['service_charge'],
			isset($billRow['base_amount']) ? $billRow['base_amount'] : $billRow['amount'],
			isset($billRow['tax_rate']) ? $billRow['tax_rate'] : 0,
			isset($billRow['tax_amount']) ? $billRow['tax_amount'] : 0,
			$billRow['amount'],
			$billRow['status'],
			$billRow['due_date'],
		]);
	}
	fclose($out);
	exit;
}
if ($db) {
	$billService = new Bill($db);
	$bills = $billService->getBillsByUser($_SESSION['user_id']);
	foreach ($bills as $bill) {
		if ($bill['status'] === 'paid') {
			$total_paid += (float)$bill['amount'];
			$count_paid++;
		} elseif (in_array($bill['status'], ['pending', 'overdue'], true)) {
			$total_unpaid += (float)$bill['amount'];
			$count_unpaid++;
			if ($bill['status'] === 'overdue') {
				$count_overdue++;
			}
			if (!empty($bill['due_date'])) {
				$ts = strtotime($bill['due_date']);
				if ($ts !== false) {
					if ($next_due_date === null || $ts < $next_due_date) {
						$next_due_date = $ts;
					}
				}
			}
		}
	}

	if ($filter === 'paid') {
		$bills = array_filter($bills, function($bill) {
			return $bill['status'] === 'paid';
		});
	} elseif ($filter === 'unpaid') {
		$bills = array_filter($bills, function($bill) {
			return in_array($bill['status'], ['pending', 'overdue'], true);
		});
	}

	foreach ($bills as $bill) {
		if ($billService->isRegistrationFeeBill($bill)) {
			$hasRegistrationBillsInView = true;
		} else {
			$hasUsageBillsInView = true;
		}
	}
}

$billColumnSubhead = 'Type and month';
$readingColumnSubhead = 'Previous, current, usage';
$chargesColumnSubhead = 'Rate and service';
$totalsColumnSubhead = 'Base, tax, amount';

if ($hasRegistrationBillsInView && !$hasUsageBillsInView) {
	$readingColumnSubhead = 'Not used for registration';
	$chargesColumnSubhead = 'Registration charge';
	$totalsColumnSubhead = 'Amount summary';
	$billColumnSubhead = 'Type and month';
	} elseif ($hasUsageBillsInView && !$hasRegistrationBillsInView) {
	$billColumnSubhead = 'Type and month';
	$readingColumnSubhead = 'Metered usage summary';
	$chargesColumnSubhead = 'Water charge summary';
	$totalsColumnSubhead = 'Amount summary';
} elseif ($hasRegistrationBillsInView && $hasUsageBillsInView) {
	$readingColumnSubhead = 'Depends on bill type';
	$chargesColumnSubhead = 'Fee or water charges';
	$totalsColumnSubhead = 'Amount summary';
}
?>

<div class="container mt-4 bills-page admin-shell">
	<div class="row">
		<div class="col-md-12">
			<div class="pb-banner mb-4">
				<div class="pb-bg" aria-hidden="true">
					<div class="pb-grid"></div>
					<div class="pb-blob pb-blob--a"></div>
					<div class="pb-blob pb-blob--b"></div>
					<i class="bi bi-receipt pb-watermark"></i>
				</div>
				<div class="pb-inner">
					<div class="pb-left">
						<div class="pb-eyebrow-row">
							<span class="pb-eyebrow-chip"><i class="bi bi-receipt"></i> My Account</span>
						</div>
						<h2 class="pb-title">My Bills</h2>
						<p class="pb-subtitle">Your billing history and current charges.</p>
					</div>
					<div class="pb-right">
						<div class="pb-btn-row">
							<a href="#" class="pb-btn" data-bs-toggle="modal" data-bs-target="#billingPolicyModal"><i class="bi bi-info-circle"></i> View Billing Policy</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-md-4 mb-3">
			<div class="card metric-card metric-card-primary h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Total amount you have already paid.">
				<div class="card-body">
					<h6 class="card-title text-muted text-uppercase small mb-1">Total Paid</h6>
					<p class="h4 mb-1">KES <?php echo number_format($total_paid, 2); ?></p>
					<p class="mb-0 small text-muted"><?php echo $count_paid > 0 ? number_format($count_paid) . ' bill(s) fully settled.' : 'No paid bills yet.'; ?></p>
				</div>
			</div>
		</div>
		<div class="col-md-4 mb-3">
			<div class="card metric-card metric-card-danger h-100" data-bs-toggle="tooltip" data-bs-placement="top" title="Total of all pending and overdue bills.">
				<div class="card-body">
					<h6 class="card-title text-muted text-uppercase small mb-1">Outstanding Balance</h6>
					<p class="h4 mb-1">KES <?php echo number_format($total_unpaid, 2); ?></p>
					<?php if ($count_unpaid > 0): ?>
						<p class="mb-0 small text-muted"><?php echo number_format($count_unpaid); ?> bill(s) unpaid<?php echo $count_overdue > 0 ? ', ' . number_format($count_overdue) . ' overdue.' : '.'; ?></p>
					<?php else: ?>
						<p class="mb-0 small text-muted">You have no unpaid bills.</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-md-4 mb-3">
			<div class="card h-100 d-flex flex-column justify-content-between">
				<div class="card-body">
					<h6 class="card-title text-muted text-uppercase small mb-1">Next Due Date</h6>
					<?php if ($next_due_date !== null): ?>
						<p class="h5 mb-1"><?php echo htmlspecialchars(date('d M Y', $next_due_date)); ?></p>
						<p class="mb-0 small text-muted">Pay before this date to avoid penalties.</p>
					<?php else: ?>
						<p class="h5 mb-1">No upcoming due date</p>
						<p class="mb-0 small text-muted">You have no pending or overdue bills.</p>
					<?php endif; ?>
				</div>
				<div class="card-footer bg-transparent border-0 pt-0">
					<form method="GET" action="/statement" class="row g-2 align-items-end">
						<div class="col-6">
							<label class="form-label small mb-1">From</label>
							<input type="month" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromPeriod); ?>">
						</div>
						<div class="col-6">
							<label class="form-label small mb-1">To</label>
							<input type="month" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toPeriod); ?>">
						</div>
						<div class="col-6 d-grid">
							<button type="submit" class="btn btn-sm btn-outline-primary">
								<i class="bi bi-file-earmark-pdf"></i> Statement PDF
							</button>
						</div>
						<div class="col-6 d-grid">
							<button type="submit" formaction="/bills" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
								<i class="bi bi-download"></i> Bills CSV
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

	<?php if($filter !== 'all'): ?>
		<div class="row">
			<div class="col-md-12">
				<div class="alert alert-secondary">
					Showing <strong><?php echo htmlspecialchars($filter); ?></strong> bills. <a href="/bills">Clear filter</a>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="row mt-3">
		<div class="col-md-12">
			<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
				<ul class="nav nav-pills bills-filter-nav">
					<li class="nav-item">
						<a class="nav-link<?php echo $filter === 'all' ? ' active' : ''; ?>" href="/bills">All</a>
					</li>
					<li class="nav-item">
						<a class="nav-link<?php echo $filter === 'unpaid' ? ' active' : ''; ?>" href="/bills?filter=unpaid">Unpaid</a>
					</li>
					<li class="nav-item">
						<a class="nav-link<?php echo $filter === 'paid' ? ' active' : ''; ?>" href="/bills?filter=paid">Paid</a>
					</li>
				</ul>
				<div class="input-group bills-search-input" style="max-width: 260px;">
					<span class="input-group-text"><i class="bi bi-search"></i></span>
					<input type="text" id="billSearch" class="form-control" placeholder="Search by month or status...">
				</div>
			</div>
			<div class="card">
				<div class="card-body">
					<?php if ($hasRegistrationBillsInView && $hasUsageBillsInView): ?>
						<div class="alert alert-light bills-table-note" role="alert">
							Registration fees do not use meter readings. Reading values apply only to water bills in this list.
						</div>
					<?php endif; ?>
					<div class="table-responsive">
						<table class="table table-striped align-middle bills-history-table">
							<thead>
								<tr>
									<th class="col-bill"><span class="bills-head-main">Bill</span><span class="bills-head-sub"><?php echo htmlspecialchars($billColumnSubhead); ?></span></th>
									<th class="col-readings"><span class="bills-head-main">Meter Readings</span><span class="bills-head-sub"><?php echo htmlspecialchars($readingColumnSubhead); ?></span></th>
									<th class="col-charges"><span class="bills-head-main">Charges</span><span class="bills-head-sub"><?php echo htmlspecialchars($chargesColumnSubhead); ?></span></th>
									<th class="col-totals"><span class="bills-head-main">Totals</span><span class="bills-head-sub"><?php echo htmlspecialchars($totalsColumnSubhead); ?></span></th>
									<th class="col-status"><span class="bills-head-main">Status</span><span class="bills-head-sub">Payment state</span></th>
									<th class="col-actions"><span class="bills-head-main">Actions</span><span class="bills-head-sub">Download invoice or pay bill</span></th>
								</tr>
							</thead>
							<tbody>
								<?php if(empty($bills)): ?>
									<tr>
										<td colspan="6" class="text-center text-muted">No bills found for this view.</td>
									</tr>
								<?php else: ?>
									<?php foreach($bills as $bill): ?>
										<?php $baseAmount = isset($bill['base_amount']) ? (float)$bill['base_amount'] : (float)$bill['amount']; ?>
										<?php $taxAmount = isset($bill['tax_amount']) ? (float)$bill['tax_amount'] : 0.0; ?>
										<?php $billTypeLabel = $billService->getBillTypeLabel($bill); ?>
										<?php $isRegistrationFeeBill = $billService->isRegistrationFeeBill($bill); ?>
										<?php $summaryClass = $isRegistrationFeeBill ? ' bills-cell-summary-accent' : ''; ?>
										<tr>
											<td class="col-bill">
												<div class="bills-cell-stack bills-cell-summary bills-cell-centered<?php echo $summaryClass; ?>">
													<span class="badge <?php echo $billTypeLabel === 'Registration Fee' ? 'bg-info text-dark' : 'bg-light text-dark border'; ?>">
														<?php echo htmlspecialchars($billTypeLabel); ?>
													</span>
													<div class="bills-cell-primary"><?php echo htmlspecialchars(date('M Y', strtotime($bill['billing_month']))); ?></div>
												</div>
											</td>
											<td class="col-readings">
												<?php if ($isRegistrationFeeBill): ?>
													<div class="bills-cell-stack bills-cell-summary">
														<div class="bills-cell-primary">No meter readings</div>
														<div class="bills-cell-note">Registration fees are fixed charges and do not depend on usage.</div>
													</div>
												<?php else: ?>
													<div class="bills-cell-stack bills-cell-summary bills-cell-metrics">
														<div><span class="bills-cell-label">Previous reading</span><span class="bills-cell-value"><?php echo number_format($bill['previous_reading'], 2); ?></span></div>
														<div><span class="bills-cell-label">Current reading</span><span class="bills-cell-value"><?php echo number_format($bill['current_reading'], 2); ?></span></div>
														<div><span class="bills-cell-label">Usage</span><span class="bills-cell-value"><?php echo number_format($bill['consumption'], 2); ?> m³</span></div>
													</div>
												<?php endif; ?>
											</td>
											<td class="col-charges">
												<?php if ($isRegistrationFeeBill): ?>
													<div class="bills-cell-stack bills-cell-summary bills-cell-summary-accent bills-cell-centered">
														<div class="bills-cell-label">Registration fee</div>
														<div class="bills-cell-value bills-cell-value-strong">KES <?php echo number_format((float)$bill['amount'], 2); ?></div>
													</div>
												<?php else: ?>
													<div class="bills-cell-stack bills-cell-summary bills-cell-metrics">
														<div><span class="bills-cell-label">Water rate</span><span class="bills-cell-value">KES <?php echo number_format($bill['rate_per_unit'], 2); ?></span></div>
														<div><span class="bills-cell-label">Service fee</span><span class="bills-cell-value">KES <?php echo number_format($bill['service_charge'], 2); ?></span></div>
													</div>
												<?php endif; ?>
											</td>
											<td class="col-totals">
												<?php if ($isRegistrationFeeBill): ?>
													<div class="bills-cell-stack bills-cell-summary bills-cell-metrics<?php echo $summaryClass; ?>">
														<div><span class="bills-cell-label">Total amount</span><span class="bills-cell-value bills-cell-value-strong">KES <?php echo number_format($bill['amount'], 2); ?></span></div>
														<?php if ($taxAmount > 0): ?>
															<div><span class="bills-cell-label">Tax</span><span class="bills-cell-value">KES <?php echo number_format($taxAmount, 2); ?></span></div>
														<?php endif; ?>
													</div>
												<?php else: ?>
													<div class="bills-cell-stack bills-cell-summary bills-cell-metrics">
														<div><span class="bills-cell-label">Subtotal</span><span class="bills-cell-value">KES <?php echo number_format($baseAmount, 2); ?></span></div>
														<div><span class="bills-cell-label">Tax</span><span class="bills-cell-value">KES <?php echo number_format($taxAmount, 2); ?></span></div>
														<div><span class="bills-cell-label">Total amount</span><span class="bills-cell-value bills-cell-value-strong">KES <?php echo number_format($bill['amount'], 2); ?></span></div>
													</div>
												<?php endif; ?>
											</td>
											<td class="col-status">
												<?php
												$status = $bill['status'];
												$badgeClass = 'badge-pending';
												if ($status === 'paid') {
													$badgeClass = 'badge-paid';
												} elseif ($status === 'overdue') {
													$badgeClass = 'badge-overdue';
												}
												?>
												<div class="bills-cell-stack bills-cell-summary bills-cell-centered<?php echo $summaryClass; ?>">
													<span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
													<div class="bills-cell-note">
														<?php if ($status === 'paid'): ?>
															Fully settled
														<?php elseif (!empty($bill['due_date'])): ?>
															<?php echo 'Due ' . htmlspecialchars(date('d M Y', strtotime($bill['due_date']))); ?>
														<?php else: ?>
															Awaiting payment
														<?php endif; ?>
													</div>
												</div>
											</td>
											<td class="col-actions">
												<div class="bills-actions-stack">
													<a class="btn btn-sm btn-outline-secondary" href="/invoice?bill_id=<?php echo (int)$bill['id']; ?>">Invoice</a>
													<?php if (in_array($bill['status'], ['pending','overdue'], true)): ?>
														<button type="button" class="btn btn-sm btn-primary js-pay-bill-btn" data-bill-id="<?php echo (int)$bill['id']; ?>" data-bill-amount="<?php echo htmlspecialchars($bill['amount']); ?>">
															Pay Now
														</button>
													<?php else: ?>
														<span class="bills-actions-state">Settled</span>
													<?php endif; ?>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
// Initiate M-Pesa STK push for a specific bill
document.addEventListener('DOMContentLoaded', function () {
	var buttons = document.querySelectorAll('.js-pay-bill-btn');
	var searchInput = document.getElementById('billSearch');
	var tableBody = document.querySelector('table.table tbody');

	// Simple client-side search by month, status, or amount
	if (searchInput && tableBody) {
		searchInput.addEventListener('input', function () {
			var term = this.value.toLowerCase();
			var rows = tableBody.querySelectorAll('tr');
			rows.forEach(function (row) {
				if (row.querySelector('td') === null) {
					return;
				}
				if (!term) {
					row.style.display = '';
					return;
				}
				var text = row.textContent || '';
				row.style.display = text.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
			});
		});
	}
	if (!buttons.length) return;
	buttons.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var billId = this.getAttribute('data-bill-id');
			var amount = this.getAttribute('data-bill-amount');
			if (!billId) return;

			var phone = prompt('Enter your M-Pesa phone number (e.g. 07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX):');
			if (!phone) return;
			phone = phone.trim();
			// Accept 07XXXXXXXX, 01XXXXXXXX, 2547XXXXXXXX, 2541XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX
			var re = /^(?:254|\+254|0)?((?:7|1)\d{8})$/;
			var m = phone.match(re);
			if (!m) {
				var invalidMsg = 'Please enter a valid Kenyan phone number (e.g. 07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX)';
				if (window.showToast) {
					showToast(invalidMsg, 'danger');
				} else {
					alert(invalidMsg);
				}
				return;
			}

			this.disabled = true;
			var originalText = this.textContent;
			this.textContent = 'Initiating...';

			fetch('/api/payments/initiate_payment', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ bill_id: billId, phone: phone })
			})
						.then(function (res) {
							return res.text().then(function (text) {
								var data = null;
								try {
									data = text ? JSON.parse(text) : null;
								} catch (e) {
									// Non-JSON response; show raw text
									var rawMsg = 'Payment API error (non-JSON): ' + text;
									if (window.showToast) {
										showToast(rawMsg, 'danger');
									} else {
										alert(rawMsg);
									}
									throw e;
								}

								if (!data || data.status !== 'success') {
									var msg = (data && data.message) ? data.message : 'Failed to initiate payment';
									if (window.showToast) {
										showToast(msg, 'danger');
									} else {
										alert(msg);
									}
									return;
								}
								var okMsg = 'Payment initiated. Check your phone for M-Pesa prompt.';
								if (window.showToast) {
									showToast(okMsg, 'success');
								} else {
									alert(okMsg);
								}
							});
						})
						.catch(function (err) {
							var msg = 'Error calling payment API';
							if (err && err.message) {
								msg += ': ' + err.message;
							}
							if (window.showToast) {
								showToast(msg, 'danger');
							} else {
								alert(msg);
							}
						})
			.finally(() => {
				btn.disabled = false;
				btn.textContent = originalText;
			});
		});
	});
});
</script>

<!-- Water Usage Billing Policy Modal -->
<div class="modal fade" id="billingPolicyModal" tabindex="-1" aria-labelledby="billingPolicyLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="billingPolicyLabel">Water Usage Billing Policy</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<h6 class="mb-3">Community Water Supply – BREMAC CONSULTANT LIMITED</h6>

				<h6>1. Metered Water Supply</h6>
				<p>
					All household connections will be fitted with a water meter to accurately measure water consumption.
					Billing will be based strictly on the volume of water used as recorded by the meter.
				</p>

				<h6>2. Water Tariff</h6>
				<p>
					Water will be billed per cubic meter (m³). One cubic meter (1 m³) is equivalent to
					<strong>1,000 litres of water</strong> or <strong>50 containers of 20 litres each</strong>.
				</p>
				<p>
					The current water tariff will be communicated to all members and may be adjusted periodically
					to accommodate operational costs such as electricity, maintenance, repairs, and system expansion.
				</p>

				<h6>3. Billing Cycle</h6>
				<p>
					Water usage will be billed on a <strong>monthly basis</strong>. Members will receive their usage
					statement through SMS or other official communication channels.
				</p>

				<h6>4. Payment Timeline</h6>
				<p>
					Members are required to settle their water bills within <strong>14 days from the billing date</strong>.
				</p>

				<h6>5. Late Payments</h6>
				<p>
					Failure to settle bills within the required time may result in:
				</p>
				<ul>
					<li>Late payment penalties</li>
					<li>Temporary suspension of water supply until outstanding balances are cleared</li>
				</ul>

				<h6>6. Payment Method</h6>
				<p>
					Payments for water usage should be made via:
				</p>
				<p>
					M-Pesa Paybill Number: <strong>4166503</strong><br>
					Business Name: <strong>BREMAC CONSULTANT LIMITED</strong><br>
					Account Number: <strong>Registered Customer Account Number</strong>
				</p>
				<p>
					Members must retain their payment confirmation message as proof of payment.
				</p>

				<h6>7. Disconnection Due to Non-Payment</h6>
				<p>
					Accounts with prolonged unpaid balances may be disconnected. Reconnection will only occur after:
				</p>
				<ul>
					<li>Full settlement of outstanding bills</li>
					<li>Payment of a reconnection fee (if applicable)</li>
				</ul>

				<h6>8. Tariff Review</h6>
				<p>
					Water tariffs may be reviewed periodically depending on operational costs such as electricity,
					pump maintenance, borehole servicing, and infrastructure improvements. Members will be notified
					of any changes in advance.
				</p>

				<hr>
				<h6 class="mb-3">Water Meter Rules and Infrastructure Protection Policy</h6>

				<h6>1. Meter Ownership</h6>
				<p>
					All water meters installed under the project remain the property of
					<strong>BREMAC CONSULTANT LIMITED</strong>.
				</p>

				<h6>2. Meter Protection</h6>
				<p>
					Members are responsible for ensuring that the water meter installed on their property is protected from:
				</p>
				<ul>
					<li>Physical damage</li>
					<li>Theft</li>
					<li>Flooding or exposure to harmful conditions</li>
				</ul>

				<h6>3. Tampering Prohibited</h6>
				<p>
					Tampering with water meters, bypassing the meter, interfering with valves, or illegally connecting
					to the pipeline is strictly prohibited.
				</p>

				<h6>4. Penalties for Tampering</h6>
				<p>
					Any member found tampering with the water infrastructure may face:
				</p>
				<ul>
					<li>Immediate disconnection</li>
					<li>Payment of repair or replacement costs</li>
					<li>Additional penalties as determined by project management</li>
				</ul>

				<h6>5. Meter Inspection</h6>
				<p>
					Authorized personnel may conduct routine inspections, maintenance, or meter readings.
					Members must allow access when required.
				</p>

				<h6>6. Meter Faults</h6>
				<p>
					If a member suspects that a meter is faulty or not recording correctly, they must report it immediately
					to the project administration team for inspection.
				</p>

				<h6>7. Damage to Infrastructure</h6>
				<p>
					Any individual who damages pipelines, meters, valves, or related infrastructure will be responsible for
					covering the full repair or replacement costs.
				</p>

				<h6>8. Illegal Connections</h6>
				<p>
					Unauthorized connections to the water distribution system are prohibited and may result in disconnection
					and penalties.
				</p>

				<h6>9. Relocation of Meter</h6>
				<p>
					Meters must remain at the installed location unless relocation is approved and performed by authorized
					technicians.
				</p>

				<h6>10. System Integrity</h6>
				<p>
					All members are expected to cooperate in protecting the water infrastructure to ensure fair usage and
					reliable water supply for the entire community.
				</p>

				<p class="mt-3 mb-0"><strong>BREMAC CONSULTANT LIMITED</strong></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
