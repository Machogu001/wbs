<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/CreditNote.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Etims.php';
require_once __DIR__ . '/../../includes/Accounting.php';
require_once __DIR__ . '/../../includes/InstallmentPlan.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../includes/ErrorLog.php';
require_once __DIR__ . '/../../includes/ClientWallet.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_payments') && !$auth->hasPermission('receive_payments'))) {
	header('Location: /login');
	exit;
}

$userService = new User($db);
$billService = new Bill($db);
$creditService = new CreditNote($db);
$paymentService = new Payment($db);
$financeApproval = new FinanceApproval($db);
$installmentPlanner = new InstallmentPlan($db);
$wallet = new ClientWallet($db);
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
ErrorLog::ensureTable($db);
$errorLogger = new ErrorLog($db);

$canReceivePayments = $auth->isAdmin() || $auth->hasPermission('receive_payments');

$message = null;
$message_type = 'success';
$currentUser = null;
$userBills = [];
$userPayments = [];
$paymentAdjustments = [];
$client_list = $userService->listAll();
$resolvePaymentClient = static function (User $userService, string $identifier): ?array {
	$identifier = trim($identifier);
	if ($identifier === '') {
		return null;
	}

	$user = $userService->getByAccountNumber($identifier);
	if (!$user) {
		$user = $userService->getByMeterNumber($identifier);
	}
	if (!$user) {
		$matches = $userService->searchByNameOrAccount($identifier, 2);
		if (count($matches) === 1) {
			$user = $matches[0];
		}
	}

	return $user ?: null;
};
$loadUserBills = static function (int $userId) use ($billService, $paymentService): array {
	$bills = $billService->getBillsByUser($userId);
	foreach ($bills as &$billRow) {
		$billRow['outstanding_amount'] = $paymentService->getBillOutstandingAmount((int)($billRow['id'] ?? 0));
	}
	unset($billRow);
	return $bills;
};
$verifyCurrentStaffPassword = static function (User $userService, int $userId, string $password): bool {
	if ($userId <= 0 || trim($password) === '') {
		return false;
	}
	$currentUserRow = $userService->getById($userId);
	return $currentUserRow && !empty($currentUserRow['password_hash']) && password_verify($password, (string)$currentUserRow['password_hash']);
};

// Deep-link support: /admin/payments?account=MTR0005 (e.g. from the credit balance report)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	$deepLinkAccount = trim((string)($_GET['account'] ?? ''));
	if ($deepLinkAccount !== '') {
		$currentUser = $resolvePaymentClient($userService, $deepLinkAccount);
		if ($currentUser) {
			$userBills = $loadUserBills((int)$currentUser['id']);
			$userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
			$paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
		} else {
			$message = 'Account, meter number, or name not found.';
			$message_type = 'danger';
		}
	}
}

// Handle account lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_account') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	if ($account === '') {
		$message = 'Please enter an account number.';
		$message_type = 'danger';
	} else {
		$currentUser = $resolvePaymentClient($userService, $account);
		if (!$currentUser) {
			$message = 'Account, meter number, or name not found. If using a name, enter enough to identify one client.';
			$message_type = 'danger';
		} else {
			$userBills = $loadUserBills((int)$currentUser['id']);
			$userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
			$paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
		}
	}
}

// Handle manual payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
	if (!$canReceivePayments) {
		http_response_code(403);
		die('You are not allowed to record payments.');
	}
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$billId = (int)($_POST['bill_id'] ?? 0);
	$paymentTarget = trim((string)($_POST['payment_target'] ?? 'invoice'));
	if (!in_array($paymentTarget, ['invoice', 'balance'], true)) {
		$paymentTarget = 'invoice';
	}
	$paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? 'mpesa')));
	$allowedPaymentMethods = ['mpesa', 'cash', 'bank', 'card', 'cheque', 'wallet', 'other'];
	if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
		$paymentMethod = 'other';
	}
	$referenceNo = trim((string)($_POST['payment_reference'] ?? ''));
	$amount = (float)($_POST['amount'] ?? 0);
	$paidDate = trim($_POST['paid_date'] ?? '');
	$paidTime = trim($_POST['paid_time'] ?? '');
	$phone = trim($_POST['phone_number'] ?? '');
	$paymentNote = trim((string)($_POST['payment_note'] ?? ''));
	$currentPassword = (string)($_POST['current_password'] ?? '');

	$currentUser = $account !== '' ? $resolvePaymentClient($userService, $account) : null;
	if (!$currentUser) {
		$message = 'Account, meter number, or name not found. If using a name, enter enough to identify one client.';
		$message_type = 'danger';
	} elseif ($amount <= 0 || $paidDate === '') {
		$message = 'Please fill in all required fields (amount and paid date).';
		$message_type = 'danger';
	} elseif ($paymentMethod === 'mpesa' && $referenceNo === '') {
		$message = 'M-Pesa reference number is required for M-Pesa payments.';
		$message_type = 'danger';
	} else {
		$paidDateTime = $paidDate;
		if ($paidTime !== '') {
			$paidDateTime .= ' ' . $paidTime;
		}
		$paidDateTime = date('Y-m-d H:i:s', strtotime($paidDateTime));

		if ($referenceNo === '') {
			$referenceNo = 'MANUAL-' . strtoupper(substr($paymentMethod, 0, 4)) . '-' . date('YmdHis');
		}

		$openBillsRaw = $billService->getBillsByUser((int)$currentUser['id']);
		$openBills = array_values(array_filter($openBillsRaw, static function (array $b): bool {
			return isset($b['status']) && in_array((string)$b['status'], ['pending', 'overdue'], true);
		}));

		usort($openBills, static function (array $a, array $b): int {
			$ad = strtotime((string)($a['due_date'] ?? $a['billing_month'] ?? '1970-01-01')) ?: 0;
			$bd = strtotime((string)($b['due_date'] ?? $b['billing_month'] ?? '1970-01-01')) ?: 0;
			if ($ad === $bd) {
				return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
			}
			return $ad <=> $bd;
		});

		$getOutstanding = static function (PDO $dbConn, int $forBillId, float $billAmount): float {
			$stmt = $dbConn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
			$stmt->bindParam(':bill_id', $forBillId, PDO::PARAM_INT);
			$stmt->execute();
			$totalPaid = (float)$stmt->fetchColumn();
			return max(0.0, round($billAmount - $totalPaid, 2));
		};

		$allocationPlan = [];
		$requiresPasswordConfirmation = false;
		$projectedExcessToWallet = 0.0;
		if ($paymentTarget === 'invoice') {
			if ($billId <= 0) {
				$message = 'Please select an invoice when target is Invoice.';
				$message_type = 'danger';
			} else {
				$billRow = $billService->getById($billId, $currentUser['id']);
				if (!$billRow) {
					$message = 'Selected bill not found for this account.';
					$message_type = 'danger';
				} elseif (!in_array((string)$billRow['status'], ['pending', 'overdue'], true)) {
					$message = 'Only pending or overdue bills can be receipted manually.';
					$message_type = 'danger';
				} else {
					$outstanding = $getOutstanding($db, (int)$billRow['id'], (float)$billRow['amount']);
					if ($outstanding <= 0.0) {
						$message = 'This invoice is already fully settled.';
						$message_type = 'danger';
					} else {
						$applyAmount = round(min($amount, $outstanding), 2);
						$excessToWallet = round(max(0.0, $amount - $applyAmount), 2);
						$requiresPasswordConfirmation = $excessToWallet > 0.01;
						$projectedExcessToWallet = $excessToWallet;
						$allocationPlan[] = ['bill' => $billRow, 'amount' => $applyAmount, 'excess_to_wallet' => $excessToWallet];
					}
				}
			}
		} else {
			$remaining = round($amount, 2);
			$totalOutstanding = 0.0;
			foreach ($openBills as $openBill) {
				$totalOutstanding += $getOutstanding($db, (int)$openBill['id'], (float)$openBill['amount']);
			}
			if ($totalOutstanding <= 0.0 && $amount > 0) {
				// No open bills — entire amount goes to wallet
				$allocationPlan[] = ['bill' => null, 'amount' => 0.0, 'excess_to_wallet' => round($amount, 2)];
			} else {
				foreach ($openBills as $openBill) {
					if ($remaining <= 0.0) {
						break;
					}
					$billOutstanding = $getOutstanding($db, (int)$openBill['id'], (float)$openBill['amount']);
					if ($billOutstanding <= 0.0) {
						continue;
					}
					$applyAmount = min($remaining, $billOutstanding);
					if ($applyAmount > 0.0) {
						$allocationPlan[] = ['bill' => $openBill, 'amount' => round($applyAmount, 2), 'excess_to_wallet' => 0.0];
						$remaining = round($remaining - $applyAmount, 2);
					}
				}
				// Any remaining after all bills = goes to wallet
				if ($remaining > 0.01) {
					$requiresPasswordConfirmation = true;
					$projectedExcessToWallet = round($remaining, 2);
					if (!empty($allocationPlan)) {
						$allocationPlan[count($allocationPlan) - 1]['excess_to_wallet'] =
							round(($allocationPlan[count($allocationPlan) - 1]['excess_to_wallet'] ?? 0) + $remaining, 2);
					} else {
						$allocationPlan[] = ['bill' => null, 'amount' => 0.0, 'excess_to_wallet' => round($remaining, 2)];
					}
				}
			}
		}

		if (!empty($allocationPlan) && $message_type !== 'danger' && $requiresPasswordConfirmation && !$verifyCurrentStaffPassword($userService, (int)($auth->getUserId() ?? 0), $currentPassword)) {
			$message = 'Re-enter your current password to confirm excess payment of ' . number_format($projectedExcessToWallet, 2) . ' being stored as client credit.';
			$message_type = 'danger';
		}

		if (!empty($allocationPlan) && $message_type !== 'danger') {
			$recordedPaymentIds = [];
			$notificationWarnings = [];
			$receiptSaved = false;
			$totalExcessToWallet = 0.0;
			try {
				$db->beginTransaction();
				$totalParts = count(array_filter($allocationPlan, static fn($a) => ($a['bill'] ?? null) !== null && $a['amount'] > 0));
				$receivedByUserId = (int)($_SESSION['user_id'] ?? 0);
				$billAllocIdx = 0;
				foreach ($allocationPlan as $idx => $alloc) {
					$allocBill = $alloc['bill'];
					$excessToWallet = round((float)($alloc['excess_to_wallet'] ?? 0), 2);
					if ($allocBill === null || (float)$alloc['amount'] <= 0) {
						// No bill to apply — just record wallet credit later
						$totalExcessToWallet = round($totalExcessToWallet + $excessToWallet, 2);
						continue;
					}
					$allocAmount = (float)$alloc['amount'];
					$allocBillId = (int)$allocBill['id'];
					$allocReference = $referenceNo;
					if ($totalParts > 1) {
						$allocReference .= '-P' . ($idx + 1);
					}

						$isRegistrationBill = $billService->isRegistrationFeeBill($allocBill);
						$stmt = $db->prepare("INSERT INTO payments (bill_id, user_id, phone_number, payment_method, amount, mpesa_receipt, status, registration_id, transaction_date, received_by_user_id, created_at) VALUES (:bill_id, :user_id, :phone, :payment_method, :amount, :receipt, 'completed', :registration_id, :tx_date, :received_by_user_id, :created_at)");
						$stmt->bindParam(':bill_id', $allocBillId, PDO::PARAM_INT);
						$stmt->bindParam(':user_id', $currentUser['id'], PDO::PARAM_INT);
						$stmt->bindParam(':phone', $phone);
						$stmt->bindParam(':payment_method', $paymentMethod);
						$stmt->bindParam(':amount', $allocAmount);
						$stmt->bindParam(':receipt', $allocReference);
						$stmt->bindValue(':registration_id', $isRegistrationBill ? (int)$currentUser['id'] : null, $isRegistrationBill ? PDO::PARAM_INT : PDO::PARAM_NULL);
						$stmt->bindParam(':tx_date', $paidDateTime);
						$stmt->bindValue(':received_by_user_id', $receivedByUserId > 0 ? $receivedByUserId : null, $receivedByUserId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
						$now = date('Y-m-d H:i:s');
						$stmt->bindParam(':created_at', $now);
						$stmt->execute();
						$paymentId = (int)$db->lastInsertId();
					$recordedPaymentIds[] = $paymentId;

						$paymentService->finalizeCompletedPayment($paymentId, false);

					try {
						$logger = new ActivityLog($db);
						$logger->log(
							$_SESSION['user_id'] ?? null,
							'record_manual_payment',
							'payment',
							$paymentId,
							'Recorded manual payment for bill #' . $allocBillId,
							[
								'bill_id' => $allocBillId,
								'amount' => $allocAmount,
								'payment_method' => $paymentMethod,
								'reference' => $allocReference,
								'paid_date' => $paidDateTime,
								'note' => $paymentNote,
							]
						);
					} catch (Exception $e) {
						// Ignore logging errors
					}
				}

				// Store any excess in the client wallet
				if ($totalExcessToWallet > 0.01) {
					$walletNote = 'Excess from receipt ' . $referenceNo . ($paymentNote !== '' ? ' - ' . $paymentNote : '');
					$firstPayId = !empty($recordedPaymentIds) ? (int)$recordedPaymentIds[0] : 0;
					$wallet->addCredit(
						(int)$currentUser['id'],
						$totalExcessToWallet,
						$referenceNo,
						$firstPayId,
						$walletNote,
						(int)($_SESSION['user_id'] ?? 0)
					);
				}

				$db->commit();
				$receiptSaved = true;

				foreach ($recordedPaymentIds as $recordedPaymentId) {
					try {
						$notificationStatus = $paymentService->sendCompletedPaymentNotification((int)$recordedPaymentId);
						foreach (($notificationStatus['warnings'] ?? []) as $warning) {
							$warning = trim((string)$warning);
							if ($warning !== '') {
								$notificationWarnings[] = $warning;
							}
						}
					} catch (Throwable $e) {
						error_log('Manual payment notification failed for payment #' . (int)$recordedPaymentId . ': ' . $e->getMessage());
						$notificationWarnings[] = 'A payment confirmation step failed after the receipt was saved.';
					}
				}

				// Submit first created record to ETIMS if configured (existing flow compatibility).
				if (!empty($recordedPaymentIds)) {
					try {
						$etims = new Etims($db);
						if ($etims->isConfigured()) {
							$stmtP = $db->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
							$firstPaymentId = (int)$recordedPaymentIds[0];
							$stmtP->bindParam(':id', $firstPaymentId, PDO::PARAM_INT);
							$stmtP->execute();
							$firstPaymentRow = $stmtP->fetch(PDO::FETCH_ASSOC) ?: null;
							if ($firstPaymentRow) {
								$firstBillRow = $allocationPlan[0]['bill'];
								$etims->submitSale($firstPaymentRow, $firstBillRow, $currentUser);
							}
						}
					} catch (Exception $e) {
						error_log('Manual ETIMS error: ' . $e->getMessage());
					}
				}

				$billCount = count(array_filter($allocationPlan, static fn($a) => ($a['bill'] ?? null) !== null && $a['amount'] > 0));
				$message = $paymentTarget === 'balance'
					? ('Manual payment recorded and allocated to ' . $billCount . ' invoice(s).')
					: 'Manual payment recorded successfully.';
				if ($totalExcessToWallet > 0.01) {
					$currency = (string)($settings['currency'] ?? 'KES');
					$message .= ' Excess ' . $currency . ' ' . number_format($totalExcessToWallet, 2) . ' stored as client credit balance.';
				}
				if (!empty($notificationWarnings)) {
					$message .= ' Notification note: ' . implode(' ', array_values(array_unique($notificationWarnings)));
				}
				$message_type = 'success';
				$userBills = $loadUserBills((int)$currentUser['id']);
			} catch (Exception $e) {
				if ($db->inTransaction()) {
					$db->rollBack();
				}

				// Defensive fallback: in rare cases an implicit DB commit may have
				// persisted one or more payment rows before this exception was raised.
				if (!$receiptSaved && !empty($recordedPaymentIds)) {
					try {
						$placeholders = implode(',', array_fill(0, count($recordedPaymentIds), '?'));
						$stmtPersisted = $db->prepare("SELECT COUNT(*) FROM payments WHERE id IN ({$placeholders})");
						foreach ($recordedPaymentIds as $idx => $persistedId) {
							$stmtPersisted->bindValue($idx + 1, (int)$persistedId, PDO::PARAM_INT);
						}
						$stmtPersisted->execute();
						if ((int)$stmtPersisted->fetchColumn() > 0) {
							$receiptSaved = true;
						}
					} catch (Throwable $persistCheckError) {
						// Keep original error handling path if persistence check fails.
					}
				}

				if ($receiptSaved) {
					$message = $paymentTarget === 'balance'
						? ('Manual payment recorded and allocated to ' . count($allocationPlan) . ' invoice(s).')
						: 'Manual payment recorded successfully.';
					$message .= ' Some follow-up steps could not complete: ' . $e->getMessage();
					$message_type = 'warning';
				} else {
					$errorLogger->logSystemError('ManualPayment', 'Failed to record manual payment: ' . $e->getMessage(), __FILE__, __LINE__, [
						'account_number' => $account,
						'bill_id' => $billId,
						'payment_target' => $paymentTarget,
						'payment_method' => $paymentMethod,
						'reference_no' => $referenceNo,
						'amount' => $amount,
						'paid_date' => $paidDate,
						'paid_time' => $paidTime,
						'recorded_by_user_id' => (int)($_SESSION['user_id'] ?? 0),
					]);
					$message = 'Failed to record manual payment. ' . $e->getMessage();
					$message_type = 'danger';
				}
			}
		}
	}
}

// Handle credit note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'credit_note') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$billId = (int)($_POST['bill_id'] ?? 0);
	$type = $_POST['credit_type'] ?? 'full';
	$units = (float)($_POST['units'] ?? 0);
	$note = trim($_POST['note'] ?? '');

	$currentUser = $account !== '' ? $resolvePaymentClient($userService, $account) : null;
	if (!$currentUser) {
		$message = 'Account, meter number, or name not found. If using a name, enter enough to identify one client.';
		$message_type = 'danger';
	} elseif ($billId <= 0) {
		$message = 'Please select a bill to credit.';
		$message_type = 'danger';
	} else {
		$billRow = $billService->getById($billId, $currentUser['id']);
		if (!$billRow) {
			$message = 'Selected bill not found for this account.';
			$message_type = 'danger';
		} elseif (!in_array($billRow['status'], ['pending','overdue'], true)) {
			$message = 'Only pending or overdue bills can be adjusted by credit note.';
			$message_type = 'danger';
		} else {
			$prev = (float)$billRow['previous_reading'];
			$curr = (float)$billRow['current_reading'];
			$consumption = (float)$billRow['consumption'];
			$rate = (float)$billRow['rate_per_unit'];
			$serviceCharge = (float)$billRow['service_charge'];

			if ($type === 'full') {
				$unitsCredited = $consumption;
				$newConsumption = 0.0;
				$newCurrent = $prev;
				$newService = 0.0;
				$newAmount = 0.0;
			} else {
				if ($units <= 0 || $units > $consumption) {
					$message = 'Units to credit must be between 0 and current consumption.';
					$message_type = 'danger';
					goto after_credit;
				}
				$unitsCredited = $units;
				$newConsumption = max(0.0, $consumption - $unitsCredited);
				$newCurrent = $prev + $newConsumption;
				$newService = $serviceCharge;
				$newAmount = ($newConsumption * $rate) + $newService;
			}

			// Amount credited is old amount - new amount
			$oldAmount = (float)$billRow['amount'];
			$amountCredited = max(0.0, $oldAmount - $newAmount);

			try {
				// Update bill
				$stmtU = $db->prepare('UPDATE bills SET previous_reading = :prev, current_reading = :curr, consumption = :consumption, service_charge = :service_charge, amount = :amount, status = :status WHERE id = :id');
				$status = ($type === 'full') ? 'cancelled' : $billRow['status'];
				$stmtU->bindParam(':prev', $prev);
				$stmtU->bindParam(':curr', $newCurrent);
				$stmtU->bindParam(':consumption', $newConsumption);
				$stmtU->bindParam(':service_charge', $newService);
				$stmtU->bindParam(':amount', $newAmount);
				$stmtU->bindParam(':status', $status);
				$stmtU->bindParam(':id', $billId, PDO::PARAM_INT);
				$stmtU->execute();

				// Record credit note
				$creditService->create($billId, $currentUser['id'], $unitsCredited, $amountCredited, $type, $_SESSION['user_id'], $note);

				$message = 'Credit note applied successfully.';
				$message_type = 'success';
				// Refresh bills
				$userBills = $loadUserBills((int)$currentUser['id']);
			} catch (Exception $e) {
				$message = 'Failed to apply credit note.';
				$message_type = 'danger';
			}
		}
	}
}
after_credit:

// Handle refund / chargeback request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment_adjustment_request') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$paymentId = (int)($_POST['payment_id'] ?? 0);
	$adjustmentType = trim((string)($_POST['adjustment_type'] ?? 'refund'));
	$amount = (float)($_POST['amount'] ?? 0);
	$reason = trim((string)($_POST['reason'] ?? ''));

	$currentUser = $account !== '' ? $resolvePaymentClient($userService, $account) : null;
	if (!$currentUser) {
		$message = 'Account, meter number, or name not found. If using a name, enter enough to identify one client.';
		$message_type = 'danger';
	} else {
		$paymentRow = $paymentService->getById($paymentId);
		if (!$paymentRow || (int)($paymentRow['user_id'] ?? 0) !== (int)$currentUser['id']) {
			$message = 'Selected payment does not belong to this account.';
			$message_type = 'danger';
		} else {
			try {
				$financeApproval->createPaymentAdjustmentRequest(
					$paymentId,
					$adjustmentType,
					$amount,
					(int)($_SESSION['user_id'] ?? 0),
					$reason
				);

				try {
					$logger = new ActivityLog($db);
					$logger->log(
						$_SESSION['user_id'] ?? null,
						'create_payment_adjustment_request',
						'payment',
						$paymentId,
						ucfirst($adjustmentType) . ' request created for payment #' . $paymentId,
						[
							'payment_id' => $paymentId,
							'adjustment_type' => $adjustmentType,
							'amount' => $amount,
							'reason' => $reason,
						]
					);
				} catch (Exception $e) {
					// Ignore logging errors
				}

				$message = ucfirst($adjustmentType) . ' request submitted for approval.';
				$message_type = 'success';
			} catch (Throwable $e) {
				$message = $e->getMessage();
				$message_type = 'danger';
			}
		}
	}
}

// If we already have a current user from search or actions, reload bills when not set
if ($currentUser && empty($userBills)) {
	$userBills = $loadUserBills((int)$currentUser['id']);
}

if ($currentUser && empty($userPayments)) {
	$userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
}

if ($currentUser && empty($paymentAdjustments)) {
	$paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
}

// Only show unpaid (pending/overdue) bills in the UI lists
if (!empty($userBills)) {
	$userBills = array_values(array_filter($userBills, function ($b) {
		return isset($b['status']) && in_array($b['status'], ['pending', 'overdue'], true);
	}));
}

$currency = $settings['currency_code'] ?? 'KES';
$manualPaymentPostback = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'manual_payment');
$manualPaymentPostedTarget = $manualPaymentPostback ? trim((string)($_POST['payment_target'] ?? 'invoice')) : 'invoice';
if (!in_array($manualPaymentPostedTarget, ['invoice', 'balance'], true)) {
	$manualPaymentPostedTarget = 'invoice';
}
$manualPaymentPostedMethod = $manualPaymentPostback ? strtolower(trim((string)($_POST['payment_method'] ?? 'mpesa'))) : 'mpesa';
if (!in_array($manualPaymentPostedMethod, ['mpesa', 'cash', 'bank', 'card', 'cheque', 'wallet', 'other'], true)) {
	$manualPaymentPostedMethod = 'mpesa';
}
$manualPaymentPostedBillId = $manualPaymentPostback ? (int)($_POST['bill_id'] ?? 0) : 0;
$manualPaymentShouldReopenPasswordModal = $manualPaymentPostback && $message_type === 'danger' && stripos((string)$message, 'password') !== false;

$page_title = 'Admin - Payments';
$is_admin_page = true;

include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid py-3 admin-shell admin-payments-page">
	<div class="pb-banner pb-banner--emerald mb-4">
		<div class="pb-bg" aria-hidden="true">
			<div class="pb-grid"></div>
			<div class="pb-blob pb-blob--a"></div>
			<div class="pb-blob pb-blob--b"></div>
			<i class="bi bi-wallet2 pb-watermark"></i>
		</div>
		<div class="pb-inner">
			<div class="pb-left">
				<div class="pb-eyebrow-row">
					<span class="pb-eyebrow-chip"><i class="bi bi-wallet2"></i> Revenue Operations</span>
				</div>
				<h2 class="pb-title">Payments &amp; Credit Notes</h2>
				<p class="pb-subtitle">Record manual receipts and apply bill adjustments with audit-ready controls.</p>
			</div>
			<div class="pb-right">
				<div class="pb-btn-row">
					<span class="pb-btn" style="cursor:default; background:rgba(52,211,153,0.15); border-color:rgba(52,211,153,0.35); color:#6ee7b7;"><i class="bi bi-shield-check"></i> Audit trail enabled</span>
					<a href="/reports?report_scope=payments" class="pb-btn pb-btn--accent"><i class="bi bi-graph-up"></i> Payment Reports</a>
				</div>
			</div>
		</div>
	</div>

	<?php if (!empty($message)): ?>
		<div class="alert alert-<?php echo htmlspecialchars($message_type); ?> mt-2"><?php echo htmlspecialchars($message); ?></div>
	<?php endif; ?>

	<div class="row mt-3">
		<div class="col-md-4">
			<div class="card mb-3">
				<div class="card-header admin-section-title">Account Lookup</div>
				<div class="card-body">
					<form method="POST">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? ''); ?>">
						<input type="hidden" name="action" value="search_account">
						<div class="mb-3">
							<label class="form-label">Account / Meter / Name</label>
							<input type="text" name="account_number" id="account-search-input" class="form-control js-client-autocomplete" placeholder="Start typing account, meter or name" value="<?php echo htmlspecialchars($_POST['account_number'] ?? ($currentUser['account_number'] ?? '')); ?>" autocomplete="off" required>
							<small class="text-muted">Type to search; select from suggestions.</small>
						</div>
						<button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Load Account</button>
					</form>
					<?php if ($currentUser): ?>
						<hr>
						<p class="mb-1"><strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong></p>
						<?php $currentUserMeters = !empty($currentUser['meter_details']) ? array_values(array_filter(explode('||', (string)$currentUser['meter_details']))) : []; ?>
						<p class="mb-0 text-muted">Meters: <?php
							$meterLabels = [];
							foreach ($currentUserMeters as $segment) {
								$parts = explode('::', (string)$segment, 2);
								$number = trim((string)($parts[0] ?? ''));
								$label = trim((string)($parts[1] ?? ''));
								if ($number === '') { continue; }
								$meterLabels[] = $number . ($label !== '' ? ' (' . $label . ')' : '');
							}
							echo htmlspecialchars(!empty($meterLabels) ? implode(', ', $meterLabels) : (string)($currentUser['meter_number'] ?? '')); ?>
						</p>
						<?php
							$walletBalance = $wallet->getBalance((int)$currentUser['id']);
						?>
						<?php if ($walletBalance > 0): ?>
							<div class="alert alert-info mt-2 mb-0 py-2 px-3 small">
								<i class="bi bi-piggy-bank me-1"></i>
								<strong>Credit balance: <?php echo htmlspecialchars($currency ?? 'KES'); ?> <?php echo number_format($walletBalance, 2); ?></strong><br>
								<span class="text-muted">This will be automatically applied to the next new bill.</span>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-md-8">
			<div class="card mb-3">
				<div class="card-header admin-section-title">Manual Payment Receipt</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to record a manual payment.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="current_password" id="manualPaymentCurrentPassword" value="">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? ''); ?>">
							<input type="hidden" name="action" value="manual_payment">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-4">
								<label class="form-label">Payment Target</label>
								<select name="payment_target" id="manualPaymentTarget" class="form-select" required>
									<option value="invoice" <?php echo $manualPaymentPostedTarget === 'invoice' ? 'selected' : ''; ?>>Specific Invoice</option>
									<option value="balance" <?php echo $manualPaymentPostedTarget === 'balance' ? 'selected' : ''; ?>>Outstanding Balance (auto-allocate)</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Payment Method</label>
								<select name="payment_method" id="manualPaymentMethod" class="form-select" required>
									<option value="mpesa" <?php echo $manualPaymentPostedMethod === 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
									<option value="cash" <?php echo $manualPaymentPostedMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
									<option value="bank" <?php echo $manualPaymentPostedMethod === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
									<option value="card" <?php echo $manualPaymentPostedMethod === 'card' ? 'selected' : ''; ?>>Card</option>
									<option value="cheque" <?php echo $manualPaymentPostedMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
									<option value="wallet" <?php echo $manualPaymentPostedMethod === 'wallet' ? 'selected' : ''; ?>>Wallet</option>
									<option value="other" <?php echo $manualPaymentPostedMethod === 'other' ? 'selected' : ''; ?>>Other</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label" id="manualReferenceLabel">M-Pesa Reference No</label>
								<input type="text" name="payment_reference" id="manualPaymentReference" class="form-control" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['payment_reference'] ?? '') : ''); ?>" required>
							</div>
							<div class="col-md-6">
								<label class="form-label">Bill / Invoice</label>
								<select name="bill_id" id="manualBillSelect" class="form-select" required>
									<option value="">Select bill</option>
									<?php foreach ($userBills as $b): ?>
										<option value="<?php echo (int)$b['id']; ?>" data-outstanding="<?php echo htmlspecialchars((string)number_format((float)($b['outstanding_amount'] ?? $b['amount']), 2, '.', '')); ?>" <?php echo $manualPaymentPostedBillId === (int)$b['id'] ? 'selected' : ''; ?>>
											#<?php echo (int)$b['id']; ?> - <?php echo htmlspecialchars(date('M Y', strtotime($b['billing_month']))); ?> - Outstanding <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)($b['outstanding_amount'] ?? $b['amount']), 2); ?> (<?php echo htmlspecialchars(ucfirst($b['status'])); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Amount Paid (<?php echo htmlspecialchars($currency); ?>)</label>
								<input type="number" step="0.01" name="amount" class="form-control" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['amount'] ?? '') : ''); ?>" required>
							</div>
							<div class="col-md-4">
								<label class="form-label">Paid Date</label>
								<input type="date" name="paid_date" class="form-control" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['paid_date'] ?? '') : ''); ?>" required>
							</div>
							<div class="col-md-4">
								<label class="form-label">Paid Time</label>
								<input type="time" name="paid_time" class="form-control" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['paid_time'] ?? '') : ''); ?>">
							</div>
							<div class="col-md-6">
								<label class="form-label">Payer Phone (optional)</label>
								<input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['phone_number'] ?? '') : (string)($currentUser['phone_number'] ?? '')); ?>">
							</div>
							<div class="col-md-6">
								<label class="form-label">Internal Note (optional)</label>
								<input type="text" name="payment_note" class="form-control" placeholder="Optional receipt note" value="<?php echo htmlspecialchars($manualPaymentPostback ? (string)($_POST['payment_note'] ?? '') : ''); ?>">
							</div>
							<div class="col-md-6 d-flex align-items-end">
								<button type="submit" class="btn btn-success w-100"><i class="bi bi-receipt-cutoff me-1"></i> Record Manual Payment</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">For M-Pesa choose method M-Pesa and enter the M-Pesa reference. For balance payments, select Outstanding Balance to auto-allocate to oldest open invoices.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Credit Note Adjustment</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to apply a credit note.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? ''); ?>">
							<input type="hidden" name="action" value="credit_note">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-6">
								<label class="form-label">Bill / Invoice</label>
								<select name="bill_id" class="form-select" required>
									<option value="">Select bill</option>
									<?php foreach ($userBills as $b): ?>
										<option value="<?php echo (int)$b['id']; ?>">
											#<?php echo (int)$b['id']; ?> - <?php echo htmlspecialchars(date('M Y', strtotime($b['billing_month']))); ?> - <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$b['amount'], 2); ?> (<?php echo htmlspecialchars(ucfirst($b['status'])); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label">Credit Type</label>
								<select name="credit_type" class="form-select">
									<option value="full">Full invoice</option>
									<option value="partial">Partial (some units)</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Units to credit (for partial)</label>
								<input type="number" step="0.01" name="units" class="form-control">
							</div>
							<div class="col-md-8">
								<label class="form-label">Note (optional)</label>
								<input type="text" name="note" class="form-control">
							</div>
							<div class="col-12 d-flex justify-content-end">
								<button type="submit" class="btn btn-outline-warning" data-confirm-message="Apply this credit note to the selected invoice?"><i class="bi bi-journal-minus me-1"></i> Apply Credit Note</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">Full credit will cancel the invoice. Partial credit reduces billed units and amount while keeping the bill open.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Refund / Chargeback Request</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to raise a refund or chargeback request.</p>
					<?php elseif (empty($userPayments)): ?>
						<p class="text-muted mb-0">This account has no completed payments available for adjustment.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? ''); ?>">
							<input type="hidden" name="action" value="payment_adjustment_request">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-6">
								<label class="form-label">Completed Payment</label>
								<select name="payment_id" class="form-select" required>
									<option value="">Select payment</option>
									<?php foreach ($userPayments as $paymentRow): ?>
										<option value="<?php echo (int)$paymentRow['id']; ?>">
											#<?php echo (int)$paymentRow['id']; ?> - <?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?: 'Manual payment'); ?> - <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$paymentRow['amount'], 2); ?>
											<?php if (!empty($paymentRow['available_adjustment_amount'])): ?>
												(available <?php echo number_format((float)$paymentRow['available_adjustment_amount'], 2); ?>)
											<?php endif; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-3">
								<label class="form-label">Request Type</label>
								<select name="adjustment_type" class="form-select">
									<option value="refund">Refund</option>
									<option value="chargeback">Chargeback</option>
								</select>
							</div>
							<div class="col-md-3">
								<label class="form-label">Amount (<?php echo htmlspecialchars($currency); ?>)</label>
								<input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
							</div>
							<div class="col-12">
								<label class="form-label">Reason</label>
								<input type="text" name="reason" class="form-control" placeholder="Explain why this payment needs reversal or refund" required>
							</div>
							<div class="col-12 d-flex justify-content-end">
								<button type="submit" class="btn btn-outline-danger" data-confirm-message="Submit this payment adjustment request for approval?"><i class="bi bi-arrow-counterclockwise me-1"></i> Submit Request</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">Approved requests restore receivables and post accounting entries through the finance approvals workflow.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Open Bills</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($userBills)): ?>
						<p class="text-muted mb-0 p-3">No open bills to display for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>ID</th>
										<th>Billing Month</th>
										<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
										<th class="text-end">Balance (<?php echo htmlspecialchars($currency); ?>)</th>
										<th>Status</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($userBills as $b): ?>
									<tr>
										<td>#<?php echo (int)$b['id']; ?></td>
										<td><?php echo htmlspecialchars(date('M Y', strtotime((string)$b['billing_month']))); ?></td>
										<td class="text-end"><?php echo number_format((float)$b['amount'], 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($b['outstanding_amount'] ?? $b['amount']), 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$b['status'])); ?></td>
										<td>
											<div class="d-flex gap-2 flex-wrap">
												<a href="/admin/bill-detail?bill_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-dark">View Detail</a>
												<a href="/invoice?bill_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener">Invoice</a>
												<?php if ((float)($b['outstanding_amount'] ?? $b['amount']) > 0.01): ?>
													<button type="button" class="btn btn-sm btn-outline-info send-reminder-btn" data-bill-id="<?php echo (int)$b['id']; ?>" title="Send payment reminder">
														<i class="bi bi-bell me-1"></i>Remind
													</button>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Completed Payments</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($userPayments)): ?>
						<p class="text-muted mb-0 p-3">No completed payments to display for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>Payment</th>
										<th>Method</th>
										<th>Date</th>
										<th class="text-end">Amount</th>
										<th class="text-end">Available</th>
										<th>Status</th>
										<th>Bill</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($userPayments as $paymentRow): ?>
									<tr>
										<td>#<?php echo (int)$paymentRow['id']; ?> <?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?: 'Manual'); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)($paymentRow['payment_method'] ?? 'mpesa'))); ?></td>
										<td><?php echo htmlspecialchars(date('d M Y', strtotime((string)($paymentRow['transaction_date'] ?? $paymentRow['created_at'] ?? 'now')))); ?></td>
										<td class="text-end"><?php echo number_format((float)$paymentRow['amount'], 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($paymentRow['available_adjustment_amount'] ?? 0), 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$paymentRow['status'])); ?></td>
										<td>
											<?php if (!empty($paymentRow['bill_id'])): ?>
												<a href="/admin/bill-detail?bill_id=<?php echo (int)$paymentRow['bill_id']; ?>" class="btn btn-sm btn-outline-dark">Bill #<?php echo (int)$paymentRow['bill_id']; ?></a>
											<?php else: ?>
												<span class="text-muted small">None</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Adjustment Requests</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($paymentAdjustments)): ?>
						<p class="text-muted mb-0 p-3">No refund or chargeback requests recorded for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>Type</th>
										<th>Payment</th>
										<th class="text-end">Amount</th>
										<th>Status</th>
										<th>Reason</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($paymentAdjustments as $adjustmentRow): ?>
									<tr>
										<td><?php echo htmlspecialchars(ucfirst((string)$adjustmentRow['adjustment_type'])); ?></td>
										<td>#<?php echo (int)$adjustmentRow['payment_id']; ?> <?php echo htmlspecialchars($adjustmentRow['mpesa_receipt'] ?: 'Manual'); ?></td>
										<td class="text-end"><?php echo number_format((float)$adjustmentRow['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$adjustmentRow['status'])); ?></td>
										<td><?php echo htmlspecialchars((string)($adjustmentRow['reason'] ?? '')); ?></td>
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

<div class="modal fade" id="manualPaymentPasswordModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Confirm Excess Payment</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="mb-2" id="manualPaymentPasswordHelp">This receipt exceeds the outstanding balance. Re-enter your password to store the excess as client credit.</p>
				<div class="mb-0">
					<label class="form-label">Current Password</label>
					<input type="password" class="form-control" id="manualPaymentPasswordInput" autocomplete="current-password" placeholder="Enter your current password">
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-primary" id="manualPaymentPasswordConfirmBtn">Confirm &amp; Record</button>
			</div>
		</div>
	</div>
</div>
<script src="/public/js/admin-client-autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var accountSearchInput = document.getElementById('account-search-input');
	var methodEl = document.getElementById('manualPaymentMethod');
	var targetEl = document.getElementById('manualPaymentTarget');
	var refEl = document.getElementById('manualPaymentReference');
	var refLabelEl = document.getElementById('manualReferenceLabel');
	var billEl = document.getElementById('manualBillSelect');
	var manualPaymentForm = document.querySelector('form input[name="action"][value="manual_payment"]') ? document.querySelector('form input[name="action"][value="manual_payment"]').form : null;
	var manualPaymentAmountEl = manualPaymentForm ? manualPaymentForm.querySelector('input[name="amount"]') : null;
	var manualPaymentPasswordHiddenEl = document.getElementById('manualPaymentCurrentPassword');
	var passwordModalEl = document.getElementById('manualPaymentPasswordModal');
	var passwordModalInputEl = document.getElementById('manualPaymentPasswordInput');
	var passwordModalHelpEl = document.getElementById('manualPaymentPasswordHelp');
	var passwordModalConfirmBtn = document.getElementById('manualPaymentPasswordConfirmBtn');
	var passwordModal = (passwordModalEl && window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(passwordModalEl) : null;
	var bypassManualPaymentPasswordPrompt = false;
	var reopenPasswordModal = <?php echo $manualPaymentShouldReopenPasswordModal ? 'true' : 'false'; ?>;

	if (accountSearchInput && window.WbsClientAutocomplete) {
		window.WbsClientAutocomplete.init('.js-client-autocomplete', {
			endpoint: '/api/admin/search_clients',
			minChars: 2,
			debounceMs: 250
		});
	}

	function syncManualPaymentFields() {
		if (methodEl && refEl && refLabelEl) {
			var isMpesa = methodEl.value === 'mpesa';
			refLabelEl.textContent = isMpesa ? 'M-Pesa Reference No' : 'Payment Reference No (optional)';
			refEl.required = isMpesa;
			if (!isMpesa) {
				refEl.placeholder = 'Optional ref, slip, or cheque number';
			} else {
				refEl.placeholder = '';
			}
		}
		if (targetEl && billEl) {
			var isInvoice = targetEl.value === 'invoice';
			billEl.required = isInvoice;
			billEl.disabled = !isInvoice;
		}
	}

	if (methodEl) {
		methodEl.addEventListener('change', syncManualPaymentFields);
	}
	if (targetEl) {
		targetEl.addEventListener('change', syncManualPaymentFields);
	}
	syncManualPaymentFields();

	function parseMoney(value) {
		var num = parseFloat(value);
		return Number.isFinite(num) ? num : 0;
	}

	function currentOutstandingForManualPayment() {
		if (!targetEl) {
			return 0;
		}
		if (targetEl.value === 'invoice') {
			if (!billEl) {
				return 0;
			}
			var selected = billEl.options[billEl.selectedIndex];
			return selected ? parseMoney(selected.getAttribute('data-outstanding')) : 0;
		}
		var totalOutstanding = 0;
		if (!billEl) {
			return 0;
		}
		Array.prototype.forEach.call(billEl.options, function (option) {
			if (!option.value) {
				return;
			}
			totalOutstanding += parseMoney(option.getAttribute('data-outstanding'));
		});
		return totalOutstanding;
	}

	if (manualPaymentForm) {
		manualPaymentForm.addEventListener('submit', function (event) {
			if (bypassManualPaymentPasswordPrompt) {
				bypassManualPaymentPasswordPrompt = false;
				return;
			}
			if (!manualPaymentAmountEl || !targetEl || !passwordModal) {
				return;
			}
			var amountValue = parseMoney(manualPaymentAmountEl.value);
			var outstandingValue = currentOutstandingForManualPayment();
			var excessValue = Math.max(0, amountValue - outstandingValue);
			if (excessValue <= 0.01) {
				if (manualPaymentPasswordHiddenEl) {
					manualPaymentPasswordHiddenEl.value = '';
				}
				return;
			}
			event.preventDefault();
			if (manualPaymentPasswordHiddenEl) {
				manualPaymentPasswordHiddenEl.value = '';
			}
			if (passwordModalInputEl) {
				passwordModalInputEl.value = '';
			}
			if (passwordModalHelpEl) {
				passwordModalHelpEl.textContent = 'This receipt exceeds the outstanding balance by ' + excessValue.toFixed(2) + '. Re-enter your password to store the excess as client credit.';
			}
			passwordModal.show();
		});
	}

	if (passwordModalConfirmBtn) {
		passwordModalConfirmBtn.addEventListener('click', function () {
			if (!passwordModalInputEl || !manualPaymentForm || !manualPaymentPasswordHiddenEl) {
				return;
			}
			if (!passwordModalInputEl.value.trim()) {
				if (window.showToast) {
					showToast('Enter your current password to continue.', 'danger');
				}
				passwordModalInputEl.focus();
				return;
			}
			manualPaymentPasswordHiddenEl.value = passwordModalInputEl.value;
			bypassManualPaymentPasswordPrompt = true;
			passwordModal.hide();
			manualPaymentForm.requestSubmit();
		});
	}

	if (passwordModalEl) {
		passwordModalEl.addEventListener('hidden.bs.modal', function () {
			if (passwordModalInputEl) {
				passwordModalInputEl.value = '';
			}
		});
	}

	if (reopenPasswordModal && passwordModal) {
		if (passwordModalHelpEl) {
			passwordModalHelpEl.textContent = <?php echo json_encode((string)$message); ?>;
		}
		passwordModal.show();
		if (passwordModalInputEl) {
			passwordModalInputEl.focus();
		}
	}

	document.querySelectorAll('.send-reminder-btn').forEach(function (button) {
		button.addEventListener('click', function () {
			var billId = button.getAttribute('data-bill-id');
			if (!billId) return;

			var originalText = button.innerHTML;
			button.disabled = true;
			button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending...';

			var formData = new FormData();
			formData.append('bill_id', billId);

			fetch('/api/bills/send-reminder', {
				method: 'POST',
				body: formData,
				headers: {
					'X-Requested-With': 'XMLHttpRequest'
				}
			})
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (data.success) {
						if (window.showToast) {
							showToast(data.message || 'Reminder sent successfully', 'success');
						}
						button.classList.remove('btn-outline-info');
						button.classList.add('btn-outline-success');
						button.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent';
					} else {
						if (window.showToast) {
							showToast(data.message || 'Failed to send reminder', 'danger');
						}
						button.disabled = false;
						button.innerHTML = originalText;
					}
				})
				.catch(function (error) {
					if (window.showToast) {
						showToast('Error sending reminder: ' + (error && error.message ? error.message : 'Unknown error'), 'danger');
					}
					button.disabled = false;
					button.innerHTML = originalText;
				});
		});
	});
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
