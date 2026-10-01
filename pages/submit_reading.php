<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa_config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/User.php';
require_once __DIR__ . '/../includes/MeterReading.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
require_once __DIR__ . '/../includes/SMS.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/ShortUrl.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}

$page_title = "Submit Meter Reading";
require_once __DIR__ . '/../templates/header.php';

$message = null;
$message_type = "success";
$defaultBillingMonth = date('Y-m-01', strtotime('first day of last month'));
$defaultDueDate = date('Y-m-d', strtotime('+3 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    // CSRF validation
    if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    $current_reading = (float)$_POST['current_reading'];
    $billing_month = trim((string)($_POST['billing_month'] ?? '')) ?: $defaultBillingMonth;
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: $defaultDueDate;

    if ($current_reading <= 0) {
        $message = "Current reading must be greater than 0.";
        $message_type = "danger";
    } elseif (!isset($_FILES['meter_photo']) || $_FILES['meter_photo']['error'] !== UPLOAD_ERR_OK) {
        $message = "Meter photo is required.";
        $message_type = "danger";
    } else {
        $photo = $_FILES['meter_photo'];
        $imageInfo = getimagesize($photo['tmp_name']);
        $allowedTypes = ['image/jpeg', 'image/png'];
        if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedTypes, true)) {
            $message = "Please upload a valid JPG or PNG image.";
            $message_type = "danger";
        } else {
            $userService = new User($db);
            $user = $userService->getById($_SESSION['user_id']);

            if (!$user) {
                $message = "User not found.";
                $message_type = "danger";
            } else {
                $upload_dir = __DIR__ . '/../uploads/meter_readings';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = $imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
                $filename = 'reading_' . $user['account_number'] . '_' . time() . '.' . $ext;
                $destination = $upload_dir . '/' . $filename;

                if (move_uploaded_file($photo['tmp_name'], $destination)) {
                    $photo_path = '/uploads/meter_readings/' . $filename;
                    $settingsService = new BillingSettings($db);
                    $settings = $settingsService->getSettings();
                    $billService = new Bill($db);
                    $billResult = $billService->createBillForUser(
                        $user['id'],
                        $user['account_number'],
                        $current_reading,
                        $billing_month,
                        $due_date,
                        $settings['rate_per_unit'],
                        $settings['service_charge'],
                        'pending',
                        (string)($user['meter_number'] ?? '')
                    );

                    if (!$billResult) {
                        $message = "Failed to create pending bill.";
                        $message_type = "danger";
                    } else {
                        $sms = new SMS();
                        $previousReading = $billResult['previous_reading'];
                        $currentReading = $billResult['current_reading'];
                        $units = $billResult['consumption'];
                        $billAmount = $billResult['amount'];
                        $previousBalance = 0;
                        $totalToPay = $billAmount;
                        $billDate = date('d-m-Y');
                        $account = $user['account_number'];
                        $paybill = MpesaConfig::getShortCode();
                        $payUrl = PaymentLink::generateLink((int)$billResult['bill_id']);

                        $messageText = Bill::buildBillNotificationMessage(
                            $user,
                            $billResult,
                            $due_date,
                            (float)$previousBalance,
                            (float)$totalToPay,
                            $paybill,
                            $payUrl,
                            $billDate
                        );

                        $sms->sendWithFallback($user['phone_number'], $messageText, 'bill_notification');

                        // Also send an email bill notice if user has email
                        if (!empty($user['email'])) {
                            require_once __DIR__ . '/../includes/Email.php';
                            $email = new Email();
                            $email->queue(
                                $user['email'],
                                'New water bill generated',
                                $messageText,
                                'bill_notification'
                            );
                        }
                        $readingService = new MeterReading($db);
                        $reading_id = $readingService->createReading(
                            $user['id'],
                            $user['account_number'],
                            $user['meter_number'],
                            $current_reading,
                            $billing_month,
                            $due_date,
                            $photo_path,
                            $_SESSION['user_id'],
                            $billResult['bill_id'],
                            'pending',
                            null
                        );

                        if ($reading_id) {
                            $_SESSION['flash_message'] = "Reading submitted. Bill created and pending payment. Approval required.";
                            $_SESSION['flash_type'] = "success";
                            header("Location: /submit-reading");
                            exit;
                        } else {
                            $message = "Failed to submit reading.";
                            $message_type = "danger";
                        }
                    }
                } else {
                    $message = "Failed to upload meter photo.";
                    $message_type = "danger";
                }
            }
        }
    }
}
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-12">
            <h2>Submit Meter Reading</h2>
            <p class="text-muted">Upload your meter reading for approval.</p>
        </div>
    </div>

    <?php
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $message_type = $_SESSION['flash_type'] ?? 'success';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    }
    ?>

    <?php if($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> mt-3">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Current Reading (m³)</label>
                            <input type="number" step="0.01" min="0" name="current_reading" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Billing Month</label>
                            <input type="date" name="billing_month" class="form-control" value="<?php echo htmlspecialchars($defaultBillingMonth); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo htmlspecialchars($defaultDueDate); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Meter Photo (JPG/PNG)</label>
                            <input type="file" name="meter_photo" accept="image/png,image/jpeg" class="form-control" required>
                            <small class="text-muted">Photo is required for client submissions.</small>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Reading</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
