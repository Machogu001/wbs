<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/User.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header('Location: /login');
    exit;
}

$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = isset($settings['currency_code']) && $settings['currency_code'] !== '' ? $settings['currency_code'] : 'KES';
// Registration fee from settings (fallback 0)
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

$paymentModel = new Payment($db);
$userModel = new User($db);
$currentUser = $userModel->getById((int)$_SESSION['user_id']);
$pendingPayment = $paymentModel->getLatestPendingRegistrationByUserId($_SESSION['user_id']);
$latestRegistrationPayment = $paymentModel->getLatestRegistrationByUserId($_SESSION['user_id']);
$registrationBalance = $registrationFee;
$registrationBillStatus = null;
$registrationFullySettled = false;

if ($latestRegistrationPayment && !empty($latestRegistrationPayment['bill_id'])) {
    $registrationBalance = $paymentModel->getBillOutstandingAmount((int)$latestRegistrationPayment['bill_id']);
    $stmtBill = $db->prepare('SELECT status FROM bills WHERE id = :id LIMIT 1');
    $stmtBill->execute([':id' => (int)$latestRegistrationPayment['bill_id']]);
    $registrationBillStatus = $stmtBill->fetchColumn() ?: null;
}

if ($registrationBalance <= 0.01 && (($currentUser['status'] ?? '') === 'active' || $registrationBillStatus === 'paid')) {
	$registrationBalance = 0.0;
	$registrationFullySettled = true;
}

$page_title = "Complete Registration Payment";
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h3 class="text-center mb-0"><i class="bi bi-credit-card"></i> Complete Registration Payment</h3>
                </div>
                <div class="card-body">
                    <div id="regPayMessage" class="alert d-none"></div>

                    <?php if ($registrationFullySettled): ?>
                        <p class="mb-3">
                            Your registration fee is fully paid and your account is ready to use.
                        </p>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Registration Balance
                                <span><strong><?php echo htmlspecialchars($currency); ?> 0.00</strong></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Status
                                <span class="badge bg-success">Paid</span>
                            </li>
                        </ul>
                        <p class="mb-3">
                            No further registration payment is required. Use the button below to continue to your home page.
                        </p>
                    <?php elseif ($pendingPayment || $registrationBalance > 0.01): ?>
                        <p class="mb-3">
                            Your account has been created but is not yet active. To finish your registration, please complete the one-time registration fee payment below.
                        </p>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Amount Due
                                <span><strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format(max(0, $registrationBalance), 2); ?></strong></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Status
                                <span class="badge bg-<?php echo $registrationBillStatus === 'overdue' ? 'danger' : 'warning'; ?> text-<?php echo $registrationBillStatus === 'overdue' ? 'white' : 'dark'; ?>">
                                    <?php echo htmlspecialchars(ucfirst((string)($registrationBillStatus ?: 'pending'))); ?>
                                </span>
                            </li>
                        </ul>
                        <p class="mb-3">
                            An M-Pesa STK push may already have been sent to your phone. If you see it, please approve to complete your registration.
                        </p>
                        <p class="mb-3">
                            If you did not receive the prompt or it expired, click the button below to resend the payment request.
                        </p>
                    <?php else: ?>
                        <p class="mb-3">
                            Your account has been created but is not yet active. We did not find an existing registration payment, so you can start one now.
                        </p>
                        <?php if ($registrationFee > 0): ?>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Registration Fee
                                <span><strong><?php echo htmlspecialchars($currency); ?> <?php echo number_format($registrationFee, 2); ?></strong></span>
                            </li>
                        </ul>
                        <?php endif; ?>
                        <p class="mb-3">
                            Click the button below to send an M-Pesa payment request for your registration fee.
                        </p>
                    <?php endif; ?>
                    <div class="d-grid gap-2">
                        <?php if ($registrationFullySettled): ?>
                            <a href="/dashboard" class="btn btn-success btn-lg">
                                <i class="bi bi-house-door"></i> Go to Home Page
                            </a>
                        <?php else: ?>
                            <button id="btnResendRegPayment" class="btn btn-primary btn-lg">
                                <i class="bi bi-phone"></i> Pay Registration Fee
                            </button>
                        <?php endif; ?>
                        <a href="/logout" class="btn btn-link">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
$custom_scripts = <<<'JS'
<script>
$(document).ready(function() {
    $('#btnResendRegPayment').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true);
        btn.html('<span class="spinner-border spinner-border-sm"></span> Sending payment request...');

        $.ajax({
            url: '/api/payments/initiate_registration_payment',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({}),
            success: function(response) {
                if (response.status === 'success') {
                    var amount = response.data && response.data.amount;
                    var checkoutId = response.data && response.data.checkout_request_id;

                    $('#regPayMessage')
                        .removeClass('d-none alert-danger alert-success')
                        .addClass('alert-info')
                        .html('<i class="bi bi-info-circle"></i> Payment request sent. Please approve the M-Pesa prompt on your phone.');

                    if (!checkoutId) {
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-phone"></i> Pay Registration Fee');
                        return;
                    }

                    var secondsRemaining = 180;
                    var countdownInterval = null;
                    var pollInterval = null;
                    var finished = false;

                    function updateCountdownMessage() {
                        var text = 'Waiting for M-Pesa payment confirmation... ' + secondsRemaining + 's remaining.';
                        $('#regPayMessage')
                            .removeClass('d-none alert-danger alert-success')
                            .addClass('alert-info')
                            .html('<i class="bi bi-clock-history"></i> ' + text);
                    }

                    updateCountdownMessage();

                    countdownInterval = setInterval(function() {
                        if (finished) return;
                        if (secondsRemaining > 0) {
                            secondsRemaining--;
                            updateCountdownMessage();
                        }
                    }, 1000);

                    function stopTimers() {
                        finished = true;
                        if (countdownInterval) {
                            clearInterval(countdownInterval);
                            countdownInterval = null;
                        }
                        if (pollInterval) {
                            clearInterval(pollInterval);
                            pollInterval = null;
                        }
                    }

                    function handleSuccess() {
                        stopTimers();
                        $('#regPayMessage')
                            .removeClass('d-none alert-danger')
                            .addClass('alert-success')
                            .html('<i class="bi bi-check-circle"></i> Payment confirmed. Redirecting to dashboard...');
                        setTimeout(function() {
                            window.location.href = '/dashboard';
                        }, 2000);
                    }

                    function handleError(message) {
                        stopTimers();
                        var msg = message || 'Payment failed or was not completed.';
                        $('#regPayMessage')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class="bi bi-exclamation-triangle"></i> ' + msg);
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-phone"></i> Pay Registration Fee');
                    }

                    function pollStatus() {
                        if (finished) return;
                        $.ajax({
                            url: '/api/payments/check_registration_status',
                            type: 'GET',
                            dataType: 'json',
                            data: { checkout_request_id: checkoutId },
                            success: function(data) {
                                if (!data) return;
                                if (data.status === 'success' && data.payment_status === 'completed') {
                                    handleSuccess();
                                } else if (data.status === 'error' && data.payment_status === 'failed') {
                                    handleError(data.message);
                                }
                            },
                            error: function() {
                                // Ignore transient errors; timeout and user feedback will handle issues
                            }
                        });
                    }

                    pollStatus();
                    pollInterval = setInterval(pollStatus, 3000);

                    setTimeout(function() {
                        if (finished) return;
                        handleError('Payment confirmation is taking longer than expected. If you approved the STK prompt, try opening the dashboard in a moment or resend only if no payment was deducted.');
                    }, 180000);
                } else {
                    var msg = response.message || 'Failed to initiate payment.';
                    $('#regPayMessage')
                        .removeClass('d-none alert-success')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + msg);
                    btn.prop('disabled', false);
                    btn.html('<i class="bi bi-phone"></i> Pay Registration Fee');
                }
            },
            error: function(xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.message : 'Failed to initiate payment.';
                $('#regPayMessage')
                    .removeClass('d-none alert-success')
                    .addClass('alert-danger')
                    .html('<i class="bi bi-exclamation-triangle"></i> ' + error);
                btn.prop('disabled', false);
                btn.html('<i class="bi bi-phone"></i> Pay Registration Fee');
            }
        });
    });
});
</script>
JS;
?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
