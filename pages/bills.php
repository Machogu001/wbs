<?php
session_start();
if(!isset($_SESSION['user_id'])) {
	header("Location: /login");
	exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Bill.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "My Bills";
require_once __DIR__ . '/../templates/header.php';

$bills = [];
$total_paid = 0.0;
$total_unpaid = 0.0;
$count_paid = 0;
$count_unpaid = 0;
$count_overdue = 0;
$next_due_date = null;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Optional: export this customer's bills as CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
	if (!$db) {
		header('Location: /bills');
		exit;
	}
	$billService = new Bill($db);
	$billsCsv = $billService->getBillsByUser($_SESSION['user_id']);
	header('Content-Type: text/csv; charset=utf-8');
	$filename = 'my_bills_' . date('Ymd') . '.csv';
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	$out = fopen('php://output', 'w');
	fputcsv($out, ['Billing Month', 'Previous Reading', 'Current Reading', 'Consumption (m3)', 'Rate (KES/m3)', 'Service Charge (KES)', 'Amount (KES)', 'Status', 'Due Date']);
	foreach ($billsCsv as $billRow) {
		fputcsv($out, [
			$billRow['billing_month'],
			$billRow['previous_reading'],
			$billRow['current_reading'],
			$billRow['consumption'],
			$billRow['rate_per_unit'],
			$billRow['service_charge'],
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
}
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<h2>My Bills</h2>
			<p class="text-muted">Your billing history and current charges.</p>
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
				<div class="card-footer bg-transparent border-0 pt-0 d-flex justify-content-between flex-wrap gap-2">
					<a href="/statement" class="btn btn-sm btn-outline-primary">
						<i class="bi bi-file-earmark-pdf"></i> Statement PDF
					</a>
					<a href="/bills?export=csv" class="btn btn-sm btn-outline-secondary">
						<i class="bi bi-download"></i> Bills CSV
					</a>
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
					<div class="table-responsive">
						<table class="table table-striped align-middle">
							<thead>
								<tr>
									<th>Billing Month</th>
									<th>Previous</th>
									<th>Current</th>
									<th>Consumption (m³)</th>
									<th>Rate (KES/m³)</th>
									<th>Service Charge (KES)</th>
									<th>Amount (KES)</th>
									<th>Status</th>
									<th>Invoice</th>
									<th>Pay</th>
								</tr>
							</thead>
							<tbody>
								<?php if(empty($bills)): ?>
									<tr>
										<td colspan="10" class="text-center text-muted">No bills found for this view.</td>
									</tr>
								<?php else: ?>
									<?php foreach($bills as $bill): ?>
										<tr>
											<td><?php echo htmlspecialchars(date('M Y', strtotime($bill['billing_month']))); ?></td>
											<td><?php echo number_format($bill['previous_reading'], 2); ?></td>
											<td><?php echo number_format($bill['current_reading'], 2); ?></td>
											<td><?php echo number_format($bill['consumption'], 2); ?></td>
											<td><?php echo number_format($bill['rate_per_unit'], 2); ?></td>
											<td><?php echo number_format($bill['service_charge'], 2); ?></td>
											<td><?php echo number_format($bill['amount'], 2); ?></td>
											<td>
												<?php
												$status = $bill['status'];
												$badgeClass = 'badge-pending';
												if ($status === 'paid') {
													$badgeClass = 'badge-paid';
												} elseif ($status === 'overdue') {
													$badgeClass = 'badge-overdue';
												}
												?>
												<span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
											</td>
											<td><a class="btn btn-sm btn-outline-secondary" href="/invoice?bill_id=<?php echo (int)$bill['id']; ?>">Download</a></td>
											<td>
												<?php if (in_array($bill['status'], ['pending','overdue'], true)): ?>
													<button type="button" class="btn btn-sm btn-primary js-pay-bill-btn" data-bill-id="<?php echo (int)$bill['id']; ?>" data-bill-amount="<?php echo htmlspecialchars($bill['amount']); ?>">
														Pay
													</button>
												<?php else: ?>
													<span class="text-muted small">-</span>
												<?php endif; ?>
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
