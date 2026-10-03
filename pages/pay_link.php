<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa_config.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/Mpesa.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/User.php';

$database = new Database();
$db = $database->getConnection();

$page_title = 'Quick Bill Payment';
require_once __DIR__ . '/../templates/header.php';

$message = null;
$message_type = 'danger';
$billData = null;
$userData = null;
$latestPayment = null;
$amountDue = 0.0;
$manualPaybill = MpesaConfig::getShortCode();
$showManualPaymentFallback = false;

$token = isset($_GET['t']) ? trim($_GET['t']) : '';

if (!$db) {
    $message = 'Unable to connect to the database.';
} elseif ($token === '') {
    $message = 'Invalid payment link.';
} else {
    $billId = PaymentLink::getBillIdFromToken($token);
    if (!$billId) {
        $message = 'Invalid or expired payment link.';
    } else {
        $billService = new Bill($db);
        $billData = $billService->getById($billId);
        if (!$billData) {
            $message = 'Bill not found.';
        } else {
            $paymentService = new Payment($db);
            $amountDue = $paymentService->getBillOutstandingAmount((int)$billId);
            if ($amountDue <= 0.01) {
                $message = 'This bill has already been paid.';
                $latestPayment = $paymentService->getLatestCompletedByBillId($billId);
            }
            $userService = new User($db);
            $userData = $userService->getById($billData['user_id']);
            if (!$userData) {
                $message = 'Account not found for this bill.';
            }
        }
    }
}

// Handle payment POST
if (!$message && $_SERVER['REQUEST_METHOD'] === 'POST' && $billData && $userData) {
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';

    if ($phone === '') {
        $message = 'Phone number is required.';
    } elseif (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phone, $matches)) {
        $message = 'Invalid phone number format. Use 07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX.';
    } else {
        $formatted_phone = '254' . $matches[1];
        $paymentService = new Payment($db);
        $amountDue = $paymentService->getBillOutstandingAmount((int)$billData['id']);
        if ($amountDue <= 0.01) {
            $message = 'This bill has already been paid.';
            $latestPayment = $paymentService->getLatestCompletedByBillId((int)$billData['id']);
            $message_type = 'success';
        } else {
            try {
                $mpesa = new Mpesa();
                $response = $mpesa->stkPush(
                    $formatted_phone,
                    $amountDue,
                    $billData['account_number'],
                    'Water Bill - ' . date('F Y', strtotime($billData['billing_month']))
                );

                if (isset($response['error'])) {
                    $details = '';
                    if (isset($response['http_code'])) {
                        $details .= ' (HTTP ' . $response['http_code'] . ')';
                    }
                    if (isset($response['details']) && is_array($response['details'])) {
                        if (!empty($response['details']['errorMessage'])) {
                            $details .= ': ' . $response['details']['errorMessage'];
                        } elseif (!empty($response['details']['errorCode'])) {
                            $details .= ' (Code ' . $response['details']['errorCode'] . ')';
                        }
                    }
                    $message = 'Payment initiation failed: ' . $response['error'] . $details;
                    $showManualPaymentFallback = true;
                } else {
                    $payment = new Payment($db);
                    $payment->bill_id = $billData['id'];
                    $payment->user_id = $billData['user_id'];
                    $payment->phone_number = $formatted_phone;
                    $payment->amount = $amountDue;
                    $payment->merchant_request_id = $response['MerchantRequestID'];
                    $payment->checkout_request_id = $response['CheckoutRequestID'];
                    $payment->status = 'pending';

                    if ($payment->create()) {
                        $message = 'Payment initiated. Check your phone for an M-Pesa prompt.';
                        $message_type = 'success';
                        $createdPaymentId = (int)$payment->id;
                    } else {
                        $message = 'Failed to save payment record.';
                        $showManualPaymentFallback = true;
                    }
                }
            } catch (Exception $e) {
                $message = 'Error initiating payment: ' . $e->getMessage();
                $showManualPaymentFallback = true;
            }
        }
    }
}
?>

<div class="container mt-4 pay-link-page">
    <div class="row">
        <div class="col-md-12">
            <h2>Quick Bill Payment</h2>
            <p class="text-muted mb-0">Pay your water bill directly from this secure link. No login required.</p>
        </div>
    </div>

    <script>
    window.addEventListener('load', function() {
        // Show toast if there is any message
        <?php if($message): ?>
        if (window.showToast) {
            showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type); ?>);
        }
        <?php endif; ?>

        var token = <?php echo $token ? json_encode($token) : 'null'; ?>;

        // Enhance submit UX: disable button and show spinner on submit
        var quickPayForm = document.getElementById('quickPayForm');
        var quickPayButton = document.getElementById('quickPayButton');
        var quickPaySpinner = document.getElementById('quickPaySpinner');
        var quickPayButtonText = document.getElementById('quickPayButtonText');

        if (quickPayForm && quickPayButton) {
            quickPayForm.addEventListener('submit', function() {
                if (quickPayButton) {
                    quickPayButton.disabled = true;
                }
                if (quickPaySpinner) {
                    quickPaySpinner.style.display = 'inline-block';
                }
                if (quickPayButtonText) {
                    quickPayButtonText.textContent = 'Initiating payment...';
                }
            });
        }

        // 1) If a payment was just created from this page load, start simple countdown + polling
        var paymentId = <?php echo isset($createdPaymentId) && $createdPaymentId > 0 ? (int)$createdPaymentId : 'null'; ?>;
        if (paymentId && token) {
            var remaining = 59; // seconds for display
            var elapsed = 0;
            var maxSeconds = 300; // stop after ~5 minutes
            var countdownEl = document.getElementById('paymentCountdown');
            if (countdownEl) {
                countdownEl.style.display = 'block';
                countdownEl.textContent = 'Waiting for payment confirmation... ' + remaining + ' seconds remaining.';
            }

            var intervalId = setInterval(function() {
                // Stop polling after maxSeconds as a safety
                elapsed++;
                if (elapsed > maxSeconds) {
                    clearInterval(intervalId);
                    if (countdownEl) {
                        countdownEl.textContent = 'Stopped checking. If you have approved the payment, please refresh to load the receipt.';
                    }
                    return;
                }

                // Update countdown display
                if (remaining > 0) {
                    remaining--;
                }
                if (countdownEl) {
                    if (remaining > 0) {
                        countdownEl.textContent = 'Waiting for payment confirmation... ' + remaining + ' seconds remaining.';
                    } else {
                        countdownEl.textContent = 'Waiting for payment confirmation... please keep this page open.';
                    }
                }

                    // Check payment status via simple GET (with cache-busting)
                    fetch('/api/payments/check_status_from_link?t=' + encodeURIComponent(token) +
                      '&p=' + encodeURIComponent(paymentId) +
                      '&_ts=' + Date.now())
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!countdownEl) {
                        return;
                    }

                    // Handle API errors explicitly so we can see them on-screen
                    if (!data || data.status !== 'success') {
                        var errMsg = (data && data.message) ? data.message : 'Unable to check payment status. Retrying...';
                        countdownEl.textContent = errMsg;
                        return;
                    }

                    if (data.data && data.data.payment_status) {
                        if (data.data.payment_status === 'completed') {
                            countdownEl.textContent = 'Payment confirmed. Redirecting to your receipt...';
                            clearInterval(intervalId);
                            window.location.href = '/payment-receipt?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(paymentId);
                        } else {
                            countdownEl.textContent = 'Current payment status: ' + data.data.payment_status + '. Please keep this page open.';
                        }
                    }
                })
                .catch(function() {
                    if (countdownEl) {
                        countdownEl.textContent = 'Network issue while checking payment status. Retrying...';
                    }
                });
            }, 1000);
        }

        // 2) If the bill is already paid when opening this link, auto-redirect to the receipt
        var alreadyPaidPaymentId = <?php echo ($latestPayment && $latestPayment['status'] === 'completed') ? (int)$latestPayment['id'] : 'null'; ?>;
        if (!paymentId && token && alreadyPaidPaymentId) {
            window.location.href = '/payment-receipt?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(alreadyPaidPaymentId);
        }
    });
    </script>

    <div class="row mt-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Bill Details</h5>
                    <span class="badge bg-<?php echo ($billData && $billData['status'] === 'paid') ? 'success' : 'warning'; ?>">
                        <?php echo ($billData && $billData['status'] === 'paid') ? 'Paid' : 'Payment Pending'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if(!$billData || !$userData): ?>
                        <p class="text-danger mb-0"><?php echo htmlspecialchars($message ?: 'Unable to load bill details.'); ?></p>
                    <?php else: ?>
                        <dl class="row receipt-detail-grid">
                            <dt class="col-sm-4">Account Number</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($billData['account_number']); ?></dd>

                            <dt class="col-sm-4">Customer Name</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($userData['full_name']); ?></dd>

                            <dt class="col-sm-4">Billing Month</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars(date('F Y', strtotime($billData['billing_month']))); ?></dd>

                            <dt class="col-sm-4">Amount Due</dt>
                            <dd class="col-sm-8">KES <?php echo number_format((float)$amountDue, 2); ?></dd>

                            <dt class="col-sm-4">Due Date</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars(date('d-m-Y', strtotime($billData['due_date']))); ?></dd>
                        </dl>

                        <?php if($billData['status'] !== 'paid'): ?>
                            <form method="POST" id="quickPayForm">
                                <div class="mb-3">
                                    <label class="form-label">M-Pesa Phone Number *</label>
                                    <input type="tel" name="phone" class="form-control" autocomplete="tel" value="<?php echo htmlspecialchars($userData['phone_number'] ?? ''); ?>" placeholder="07XXXXXXXX, 01XXXXXXXX or 2547XXXXXXXX" pattern="^(?:254|\+254|0)?((?:7|1)\d{8})$" required>
                                    <small class="text-muted">Enter the phone number registered with M-Pesa (07..., 01..., or 254...). You will receive an M-Pesa prompt on this number.</small>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" id="quickPayButton" class="btn btn-primary btn-lg">
                                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display:none;" id="quickPaySpinner"></span>
                                        <span id="quickPayButtonText"><i class="bi bi-send"></i> Pay via M-Pesa</span>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="text-success mb-3">This bill has already been paid.</p>
                            <?php if($latestPayment && $latestPayment['status'] === 'completed'): ?>
                                <h6>Payment Receipt</h6>
                                <dl class="row mb-3 receipt-detail-grid">
                                    <dt class="col-sm-4">Receipt Number</dt>
                                    <dd class="col-sm-8 text-break"><?php echo htmlspecialchars($latestPayment['mpesa_receipt'] ?? 'N/A'); ?></dd>

                                    <dt class="col-sm-4">Amount Paid</dt>
                                    <dd class="col-sm-8">KES <?php echo number_format((float)$latestPayment['amount'], 2); ?></dd>

                                    <dt class="col-sm-4">Paid On</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($latestPayment['transaction_date'] ?? $latestPayment['created_at']))); ?></dd>
                                </dl>
                                <div class="d-grid gap-2">
                                    <a href="/payment-receipt-pdf?t=<?php echo urlencode($token); ?>&p=<?php echo (int)$latestPayment['id']; ?>" class="btn btn-outline-primary">
                                        Download PDF Receipt
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div id="paymentCountdown" class="mt-3 text-muted" style="display:none;"></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">How It Works</h5>
                </div>
                <div class="card-body">
                    <ol class="mb-3">
                        <li>Confirm the bill details on this page.</li>
                        <li>Enter or confirm your M-Pesa phone number.</li>
                        <li>Click <strong>Pay via M-Pesa</strong>.</li>
                        <li>Check your phone for the M-Pesa prompt and enter your PIN.</li>
                        <li>Keep this page open while we confirm your payment.</li>
                        <li>As soon as the payment is confirmed, you will be redirected to your receipt.</li>
                    </ol>
                    <p class="small text-muted mb-0">If you do not see a prompt on your phone, ensure your line is on and has network, then try again after a few minutes.</p>

                    <?php if($showManualPaymentFallback && $billData): ?>
                        <hr>
                        <h6 class="mb-3">Manual M-Pesa Payment</h6>
                        <p class="small text-muted">If STK push is not working, you can pay manually with the details below, then keep this page open or refresh later to check for confirmation.</p>
                        <dl class="row mb-0 receipt-detail-grid">
                            <dt class="col-sm-5">Paybill</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($manualPaybill); ?></dd>

                            <dt class="col-sm-5">Account</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($billData['account_number']); ?></dd>

                            <dt class="col-sm-5">Amount</dt>
                            <dd class="col-sm-7">KES <?php echo number_format((float)$amountDue, 2); ?></dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
