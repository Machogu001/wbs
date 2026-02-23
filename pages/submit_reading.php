<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa_config.php';
require_once __DIR__ . '/../includes/User.php';
require_once __DIR__ . '/../includes/MeterReading.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
require_once __DIR__ . '/../includes/SMS.php';
require_once __DIR__ . '/../includes/PaymentLink.php';

$database = new Database();
$db = $database->getConnection();

$page_title = "Submit Meter Reading";
require_once __DIR__ . '/../templates/header.php';

$message = null;
$message_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $current_reading = (float)$_POST['current_reading'];
    $billing_month = $_POST['billing_month'];
    $due_date = $_POST['due_date'];

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
                        'pending'
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
                        $paybill = MpesaConfig::SHORTCODE;
                        $payUrl = PaymentLink::generateLink((int)$billResult['bill_id']);

                        $messageText = "AC: {$account}\n" .
                            "BillDate: {$billDate}\n" .
                            "CurRead: " . number_format($currentReading, 2) . "\n" .
                            "PrevRead: " . number_format($previousReading, 2) . "\n" .
                            "Units: " . number_format($units, 2) . "\n" .
                            "Bill: KES " . number_format($billAmount, 2) . "\n" .
                            "PrevBal: KES " . number_format($previousBalance, 2) . "\n" .
                            "Total to Pay: KES " . number_format($totalToPay, 2) . "\n" .
                            "DueDate: " . date('d-m-Y', strtotime($due_date)) . "\n" .
                            "Paybill: {$paybill}\n" .
                            "Acc: {$account}\n" .
                            "Pay online: {$payUrl}";

                        $sms->send($user['phone_number'], $messageText);
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
                            <input type="date" name="billing_month" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
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
