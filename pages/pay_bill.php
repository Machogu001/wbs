<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Bill.php';

$page_title = "Pay Bill";
require_once __DIR__ . '/../templates/header.php';

$database = new Database();
$db = $database->getConnection();
$bills = [];
$singleBill = null;
if ($db) {
    $billService = new Bill($db);
    $allBills = $billService->getBillsByUser($_SESSION['user_id']);
    // Only allow paying pending/overdue bills here
    foreach ($allBills as $bill) {
        if (in_array($bill['status'], ['pending', 'overdue'], true)) {
            $bills[] = $bill;
        }
    }
    if (count($bills) === 1) {
        $singleBill = $bills[0];
    }
}
?>

<div class="container-fluid mt-4 pay-bill-page admin-shell">
    <div class="row">
        <div class="col-md-12">
            <div class="pb-banner pb-banner--emerald mb-4">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-phone pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-phone"></i> M-Pesa Payment</span>
                        </div>
                        <h2 class="pb-title">Pay Water Bill</h2>
                        <p class="pb-subtitle">Select an invoice and complete payment securely through M-Pesa.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">M-Pesa Payment</h5>
                </div>
                <div class="card-body">
                    <form id="paymentForm">
                        <div class="mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_data']['account_number'] ?? ''); ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Select Invoice to Pay</label>
                            <select id="bill_id" class="form-select" required>
                                <option value="">-- Select an unpaid bill --</option>
                                <?php foreach ($bills as $bill): ?>
                                    <option value="<?php echo (int)$bill['id']; ?>" data-amount="<?php echo htmlspecialchars($bill['amount']); ?>" <?php echo ($singleBill && $singleBill['id'] == $bill['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(date('M Y', strtotime($bill['billing_month']))); ?> - KES <?php echo number_format($bill['amount'], 2); ?> (<?php echo htmlspecialchars($bill['status']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only pending or overdue bills are listed.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount to Pay (KES)</label>
                            <input type="number" id="amount" class="form-control" min="1" max="150000" value="<?php echo $singleBill ? htmlspecialchars($singleBill['amount']) : ''; ?>" readonly>
                            <small class="text-muted">Amount is taken from the selected invoice.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">M-Pesa Phone Number *</label>
                            <input type="tel" id="phone" class="form-control" 
					placeholder="07XXXXXXXX, 01XXXXXXXX or 2547XXXXXXXX" pattern="^(?:254|\+254|0)?((?:7|1)\d{8})$" required>
                            <small class="text-muted">Enter the phone number registered with M-Pesa (07..., 01..., or 254...)</small>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="payButton">
                                <i class="bi bi-send"></i> Pay via M-Pesa
                            </button>
                        </div>
                    </form>
                    
                    <div id="paymentStatus" class="mt-3"></div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Payment Instructions</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Select the invoice you want to pay</li>
                        <li>Confirm the amount to pay</li>
                        <li>Enter your M-Pesa registered phone number</li>
                        <li>Click "Pay via M-Pesa" button</li>
                        <li>Check your phone for M-Pesa prompt</li>
                        <li>Enter your M-Pesa PIN when prompted</li>
                        <li>Wait for payment confirmation</li>
                    </ol>
                    
                    <!-- Secure payment messaging will be shown only after a successful STK initiation -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Vanilla JS to avoid jQuery dependency on this page
document.addEventListener('DOMContentLoaded', function () {
    var billSelect = document.getElementById('bill_id');
    var amountInput = document.getElementById('amount');
    var phoneInput = document.getElementById('phone');
    var payButton = document.getElementById('payButton');
    var paymentForm = document.getElementById('paymentForm');
    var paymentStatus = document.getElementById('paymentStatus');

    function syncSelectedBillAmount() {
        if (!billSelect) return;
        var selected = billSelect.options[billSelect.selectedIndex];
        var amt = selected && selected.getAttribute('data-amount');
        amountInput.value = (typeof amt !== 'undefined' && amt !== null) ? amt : '';
    }

    if (billSelect) {
        billSelect.addEventListener('change', syncSelectedBillAmount);
        // Initial sync (covers the case where one bill is pre-selected)
        syncSelectedBillAmount();
    }

    if (paymentForm) {
        paymentForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var billId = billSelect ? billSelect.value : '';
            var amount = amountInput ? amountInput.value : '';
            var phone = phoneInput ? phoneInput.value.trim() : '';

            if (!billId) {
                if (window.showToast) {
                    showToast('Please select an invoice to pay.','danger');
                } else {
                    alert('Please select an invoice to pay.');
                }
                return;
            }

            var re = /^(?:254|\+254|0)?((?:7|1)\d{8})$/;
            if (!re.test(phone)) {
                if (window.showToast) {
                    showToast('Please enter a valid Kenyan phone number (e.g. 07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX)','danger');
                } else {
                    alert('Please enter a valid Kenyan phone number (e.g. 07XXXXXXXX, 01XXXXXXXX, or 2547XXXXXXXX)');
                }
                return;
            }

            var amtNum = parseFloat(amount);
            if (isNaN(amtNum) || amtNum < 1 || amtNum > 150000) {
                if (window.showToast) {
                    showToast('Amount must be between Ksh 1 and Ksh 150,000','danger');
                } else {
                    alert('Amount must be between Ksh 1 and Ksh 150,000');
                }
                return;
            }

            if (payButton) {
                payButton.disabled = true;
                payButton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
            }

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
                        var rawMsg = 'Payment API error (non-JSON): ' + text;
                        if (window.showToast) {
                            showToast(rawMsg,'danger');
                        } else if (paymentStatus) {
                            paymentStatus.textContent = rawMsg;
                        }
                        throw e;
                    }

                    if (!data || data.status !== 'success') {
                        var msg = (data && data.message) ? data.message : 'Failed to initiate payment';
                        if (window.showToast) {
                            showToast(msg,'danger');
                        } else if (paymentStatus) {
                            paymentStatus.textContent = msg;
                        }
                        return;
                    }

                    var successMsg = 'Payment initiated. Check your phone for an M-Pesa prompt.';
                    if (window.showToast) {
                        showToast(successMsg,'success');
                    } else if (paymentStatus) {
                        paymentStatus.textContent = successMsg;
                    }
                });
            })
            .catch(function (err) {
                var msg = 'Error calling payment API';
                if (err && err.message) {
                    msg += ': ' + err.message;
                }
                if (window.showToast) {
                    showToast(msg,'danger');
                } else if (paymentStatus) {
                    paymentStatus.textContent = msg;
                }
            })
            .finally(function () {
                if (payButton) {
                    payButton.disabled = false;
                    payButton.innerHTML = '<i class="bi bi-send"></i> Pay via M-Pesa';
                }
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
