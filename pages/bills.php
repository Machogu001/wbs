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
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
if ($db) {
	$billService = new Bill($db);
	$bills = $billService->getBillsByUser($_SESSION['user_id']);
	foreach ($bills as $bill) {
		if ($bill['status'] === 'paid') {
			$total_paid += (float)$bill['amount'];
		} elseif (in_array($bill['status'], ['pending', 'overdue'], true)) {
			$total_unpaid += (float)$bill['amount'];
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
		<div class="col-md-12">
			<div class="alert alert-info d-flex justify-content-between flex-wrap align-items-center">
				<div><strong>Total Paid:</strong> KES <?php echo number_format($total_paid, 2); ?></div>
				<div><strong>Total Unpaid:</strong> KES <?php echo number_format($total_unpaid, 2); ?></div>
				<div><a href="/statement" class="btn btn-sm btn-outline-primary">Download Statement</a></div>
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
										<td colspan="8" class="text-center text-muted">No bills found.</td>
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
											<td><span class="badge bg-<?php echo $bill['status'] === 'paid' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars(ucfirst($bill['status'])); ?></span></td>
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
