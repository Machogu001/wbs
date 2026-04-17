<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Email.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if (!$auth->isLoggedIn() || !($auth->isAdmin() || $auth->hasRole('support'))) {
    header('Location: /login');
    exit;
}

$page_title = 'Support Inquiries';
$is_admin_page = true;
$isAdminUser = $auth->isAdmin();
$currentUserId = (int)($auth->getUserId() ?? 0);

$flashMessage = '';
$flashType = 'success';

function ensureSupportInquiryTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS support_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(60) DEFAULT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        email_error TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_support_inquiries_status (status),
        INDEX idx_support_inquiries_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $requiredColumns = [
        'reply_subject' => "ALTER TABLE support_inquiries ADD COLUMN reply_subject VARCHAR(191) DEFAULT NULL AFTER email_error",
        'reply_message' => "ALTER TABLE support_inquiries ADD COLUMN reply_message TEXT DEFAULT NULL AFTER reply_subject",
        'replied_at' => "ALTER TABLE support_inquiries ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER reply_message",
        'replied_by_user_id' => "ALTER TABLE support_inquiries ADD COLUMN replied_by_user_id INT DEFAULT NULL AFTER replied_at",
    ];

    $columnCheck = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
    foreach ($requiredColumns as $columnName => $alterSql) {
        $columnCheck->execute([
            ':table_name' => 'support_inquiries',
            ':column_name' => $columnName,
        ]);

        if ((int)$columnCheck->fetchColumn() === 0) {
            $db->exec($alterSql);
        }
    }
}

if (!empty($_SESSION['support_inquiries_flash']) && is_array($_SESSION['support_inquiries_flash'])) {
    $flashMessage = (string)($_SESSION['support_inquiries_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['support_inquiries_flash']['type'] ?? 'success');
    unset($_SESSION['support_inquiries_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = (string)($_POST['action'] ?? '');
    $inquiryId = isset($_POST['inquiry_id']) ? (int)$_POST['inquiry_id'] : 0;
    $selectedInquiryIds = array_values(array_filter(array_map('intval', (array)($_POST['inquiry_ids'] ?? [])), static function ($value) {
        return $value > 0;
    }));

    try {
        ensureSupportInquiryTable($db);
    } catch (Throwable $e) {
        $_SESSION['support_inquiries_flash'] = [
            'message' => 'Could not prepare support inquiries storage.',
            'type' => 'danger',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($inquiryId > 0 && $action === 'reply_email') {
        $replySubject = trim((string)($_POST['reply_subject'] ?? ''));
        $replyMessage = trim((string)($_POST['reply_message'] ?? ''));

        if ($replySubject === '' || $replyMessage === '') {
            $_SESSION['support_inquiries_flash'] = [
                'message' => 'Reply subject and message are required.',
                'type' => 'danger',
            ];
            header('Location: /admin/support-inquiries');
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM support_inquiries WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $inquiryId]);
        $inquiry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$inquiry) {
            $_SESSION['support_inquiries_flash'] = [
                'message' => 'Inquiry not found.',
                'type' => 'danger',
            ];
            header('Location: /admin/support-inquiries');
            exit;
        }

        $visitorEmail = trim((string)($inquiry['email'] ?? ''));
        if ($visitorEmail === '' || !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['support_inquiries_flash'] = [
                'message' => 'Visitor email address is invalid.',
                'type' => 'danger',
            ];
            header('Location: /admin/support-inquiries');
            exit;
        }

        $body = $replyMessage . "\n\n---\nOriginal inquiry from " . (string)$inquiry['name'] . ":\n" . (string)$inquiry['message'];
        $mailer = new Email();
        $sendResult = $mailer->send($visitorEmail, $replySubject, $body);

        if (!empty($sendResult['success'])) {
            $update = $db->prepare("UPDATE support_inquiries
                SET status = 'handled',
                    reply_subject = :reply_subject,
                    reply_message = :reply_message,
                    replied_at = NOW(),
                    replied_by_user_id = :replied_by_user_id,
                    updated_at = NOW()
                WHERE id = :id");
            $update->execute([
                ':reply_subject' => $replySubject,
                ':reply_message' => $replyMessage,
                ':replied_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
                ':id' => $inquiryId,
            ]);

            $_SESSION['support_inquiries_flash'] = [
                'message' => 'Reply sent to ' . $visitorEmail . '.',
                'type' => 'success',
            ];
        } else {
            $_SESSION['support_inquiries_flash'] = [
                'message' => (string)($sendResult['message'] ?? 'Could not send reply email.'),
                'type' => 'danger',
            ];
        }

        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($inquiryId > 0 && in_array($action, ['mark_handled', 'reopen'], true)) {
        $nextStatus = $action === 'mark_handled' ? 'handled' : 'queued';
        $stmt = $db->prepare('UPDATE support_inquiries SET status = :status WHERE id = :id');
        $stmt->execute([
            ':status' => $nextStatus,
            ':id' => $inquiryId,
        ]);

        $_SESSION['support_inquiries_flash'] = [
            'message' => $action === 'mark_handled' ? 'Inquiry marked as handled.' : 'Inquiry reopened.',
            'type' => 'success',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($isAdminUser && $inquiryId > 0 && $action === 'delete') {
        $stmt = $db->prepare('DELETE FROM support_inquiries WHERE id = :id');
        $stmt->execute([':id' => $inquiryId]);

        $_SESSION['support_inquiries_flash'] = [
            'message' => 'Inquiry deleted.',
            'type' => 'success',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($isAdminUser && $action === 'delete_selected') {
        if (empty($selectedInquiryIds)) {
            $_SESSION['support_inquiries_flash'] = [
                'message' => 'Select at least one inquiry to delete.',
                'type' => 'warning',
            ];
            header('Location: /admin/support-inquiries');
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($selectedInquiryIds), '?'));
        $stmt = $db->prepare("DELETE FROM support_inquiries WHERE id IN ($placeholders)");
        $stmt->execute($selectedInquiryIds);
        $deletedCount = (int)$stmt->rowCount();

        $_SESSION['support_inquiries_flash'] = [
            'message' => $deletedCount === 1 ? '1 inquiry deleted.' : ($deletedCount . ' inquiries deleted.'),
            'type' => 'success',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'open'));
$allowedStatuses = ['open', 'handled', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'open';
}

$inquiries = [];
$summary = [
    'open' => 0,
    'handled' => 0,
    'all' => 0,
];

if ($db) {
    try {
        ensureSupportInquiryTable($db);

        $summaryQuery = $db->query("SELECT
            SUM(CASE WHEN status IN ('pending', 'queued', 'sent') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'handled' THEN 1 ELSE 0 END) AS handled_count,
            COUNT(*) AS total_count
            FROM support_inquiries");
        $summaryRow = $summaryQuery ? ($summaryQuery->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary['open'] = (int)($summaryRow['open_count'] ?? 0);
        $summary['handled'] = (int)($summaryRow['handled_count'] ?? 0);
        $summary['all'] = (int)($summaryRow['total_count'] ?? 0);

        $sql = 'SELECT * FROM support_inquiries';
        if ($statusFilter === 'open') {
            $sql .= " WHERE status IN ('pending', 'queued', 'sent')";
        } elseif ($statusFilter === 'handled') {
            $sql .= " WHERE status = 'handled'";
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';

        $stmt = $db->query($sql);
        $inquiries = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $flashMessage = 'Could not load support inquiries.';
        $flashType = 'danger';
    }
}

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell">
    <div class="row mb-3">
        <div class="col-12">
            <div class="pb-banner pb-banner--indigo">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-inbox-fill pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-inbox-fill"></i> Support Desk</span>
                        </div>
                        <h2 class="pb-title">Support Inquiries</h2>
                        <p class="pb-subtitle">Offline visitor inquiries are stored here for follow-up.</p>
                    </div>
                    <div class="pb-right">
                        <div class="pb-btn-row">
                            <a class="pb-btn <?php echo $statusFilter === 'open' ? 'pb-btn--accent' : ''; ?>" href="/admin/support-inquiries?status=open">Open (<?php echo (int)$summary['open']; ?>)</a>
                            <a class="pb-btn <?php echo $statusFilter === 'handled' ? 'pb-btn--accent' : ''; ?>" href="/admin/support-inquiries?status=handled">Handled (<?php echo (int)$summary['handled']; ?>)</a>
                            <a class="pb-btn <?php echo $statusFilter === 'all' ? 'pb-btn--accent' : ''; ?>" href="/admin/support-inquiries?status=all">All (<?php echo (int)$summary['all']; ?>)</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($inquiries)): ?>
                <div class="p-4 text-muted">No support inquiries found for this filter.</div>
            <?php else: ?>
                <?php if ($isAdminUser): ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 px-3 py-3 border-bottom bg-light">
                        <div class="small text-muted">
                            Select one or more inquiries, then use bulk delete.
                        </div>
                        <form method="post" id="bulkDeleteInquiriesForm" class="d-inline">
                            <input type="hidden" name="action" value="delete_selected">
                            <button type="submit" class="btn btn-outline-danger btn-sm" id="bulkDeleteInquiriesBtn" disabled>
                                <i class="bi bi-trash"></i> Delete Selected
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($isAdminUser): ?>
                                    <th style="width:48px;" class="text-center">
                                        <input type="checkbox" class="form-check-input" id="selectAllInquiries" aria-label="Select all inquiries">
                                    </th>
                                <?php endif; ?>
                                <th>Visitor</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th>Email</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiries as $inquiry): ?>
                                <?php
                                    $status = (string)($inquiry['status'] ?? 'pending');
                                    $isHandled = $status === 'handled';
                                    $badgeClass = $isHandled ? 'success' : 'warning';
                                ?>
                                <tr>
                                    <?php if ($isAdminUser): ?>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                class="form-check-input inquiry-select-checkbox"
                                                name="inquiry_ids[]"
                                                value="<?php echo (int)$inquiry['id']; ?>"
                                                form="bulkDeleteInquiriesForm"
                                                aria-label="Select inquiry <?php echo (int)$inquiry['id']; ?>"
                                            >
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)$inquiry['name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string)($inquiry['phone'] ?: 'No phone provided')); ?></div>
                                    </td>
                                    <td style="min-width:280px; white-space:pre-wrap;"><?php echo htmlspecialchars((string)$inquiry['message']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(ucfirst($status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)$inquiry['created_at']); ?></div>
                                        <div class="text-muted small">Updated: <?php echo htmlspecialchars((string)$inquiry['updated_at']); ?></div>
                                        <?php if (!empty($inquiry['replied_at'])): ?>
                                            <div class="text-success small">Replied: <?php echo htmlspecialchars((string)$inquiry['replied_at']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)$inquiry['email']); ?></div>
                                        <div class="text-muted small">
                                            <?php echo !empty($inquiry['email_sent']) ? 'Notification sent' : 'Stored in inbox'; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#replyInquiry<?php echo (int)$inquiry['id']; ?>" aria-expanded="false" aria-controls="replyInquiry<?php echo (int)$inquiry['id']; ?>">
                                                Reply by email
                                            </button>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <?php if ($isHandled): ?>
                                                    <input type="hidden" name="action" value="reopen">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Reopen</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="mark_handled">
                                                    <button type="submit" class="btn btn-outline-success btn-sm">Mark handled</button>
                                                <?php endif; ?>
                                            </form>
                                            <?php if ($isAdminUser): ?>
                                                <form method="post" class="d-inline inquiry-delete-form" data-confirm-message="Delete this inquiry?">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="collapse mt-2 text-start" id="replyInquiry<?php echo (int)$inquiry['id']; ?>">
                                            <form method="post" class="border rounded p-2 bg-light">
                                                <input type="hidden" name="action" value="reply_email">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1">To</label>
                                                    <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)$inquiry['email']); ?>" disabled>
                                                </div>
                                                <div class="mb-2">
                                                    <label for="reply_subject_<?php echo (int)$inquiry['id']; ?>" class="form-label small mb-1">Subject</label>
                                                    <input type="text" class="form-control form-control-sm" id="reply_subject_<?php echo (int)$inquiry['id']; ?>" name="reply_subject" value="Re: Your support inquiry" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label for="reply_message_<?php echo (int)$inquiry['id']; ?>" class="form-label small mb-1">Reply</label>
                                                    <textarea class="form-control form-control-sm" id="reply_message_<?php echo (int)$inquiry['id']; ?>" name="reply_message" rows="4" required>Hello <?php echo htmlspecialchars((string)$inquiry['name']); ?>,

Thank you for contacting us. Here is our response to your inquiry.

Regards,
Support Team</textarea>
                                                </div>
                                                <div class="d-flex justify-content-end">
                                                    <button type="submit" class="btn btn-primary btn-sm">Send email reply</button>
                                                </div>
                                            </form>
                                        </div>
                                        <?php if (!empty($inquiry['reply_message'])): ?>
                                            <div class="mt-2 p-2 border rounded bg-light text-start">
                                                <div class="small fw-semibold mb-1">Last reply</div>
                                                <?php if (!empty($inquiry['reply_subject'])): ?>
                                                    <div class="small text-muted mb-1">Subject: <?php echo htmlspecialchars((string)$inquiry['reply_subject']); ?></div>
                                                <?php endif; ?>
                                                <div class="small" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)$inquiry['reply_message']); ?></div>
                                            </div>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const globalToastElement = document.getElementById('globalToast');
    const globalToastBody = document.getElementById('globalToastBody');
    const confirmModalElement = document.getElementById('confirmModal');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const confirmModalConfirm = document.getElementById('confirmModalConfirm');
    const flashAlert = document.querySelector('.container-fluid.mt-4.admin-shell .alert');
    const selectAllCheckbox = document.getElementById('selectAllInquiries');
    const inquiryCheckboxes = Array.from(document.querySelectorAll('.inquiry-select-checkbox'));
    const bulkDeleteForm = document.getElementById('bulkDeleteInquiriesForm');
    const bulkDeleteButton = document.getElementById('bulkDeleteInquiriesBtn');
    const singleDeleteForms = Array.from(document.querySelectorAll('.inquiry-delete-form'));

    function showToast(message, variant) {
        if (!globalToastElement || !globalToastBody || !message || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }

        globalToastBody.textContent = message;
        globalToastElement.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'text-dark');
        if (variant === 'danger') {
            globalToastElement.classList.add('bg-danger');
        } else if (variant === 'warning') {
            globalToastElement.classList.add('bg-warning', 'text-dark');
        } else {
            globalToastElement.classList.add('bg-success');
        }

        bootstrap.Toast.getOrCreateInstance(globalToastElement).show();
    }

    function requestConfirmation(message, onConfirm) {
        if (!confirmModalElement || !confirmModalMessage || !confirmModalConfirm || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            if (window.confirm(message)) {
                onConfirm();
            }
            return;
        }

        confirmModalMessage.textContent = message;
        const modalInstance = bootstrap.Modal.getOrCreateInstance(confirmModalElement);
        const handleConfirm = function () {
            confirmModalConfirm.removeEventListener('click', handleConfirm);
            modalInstance.hide();
            onConfirm();
        };

        confirmModalConfirm.removeEventListener('click', handleConfirm);
        confirmModalConfirm.addEventListener('click', handleConfirm);
        modalInstance.show();
    }

    function updateBulkDeleteState() {
        if (!bulkDeleteButton) {
            return;
        }

        const checkedCount = inquiryCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        bulkDeleteButton.disabled = checkedCount === 0;
        bulkDeleteButton.textContent = checkedCount > 0 ? 'Delete Selected (' + checkedCount + ')' : 'Delete Selected';

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = checkedCount > 0 && checkedCount === inquiryCheckboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < inquiryCheckboxes.length;
        }
    }

    if (flashAlert) {
        const message = flashAlert.textContent.trim();
        let variant = 'success';
        if (flashAlert.classList.contains('alert-danger')) {
            variant = 'danger';
        } else if (flashAlert.classList.contains('alert-warning')) {
            variant = 'warning';
        }
        flashAlert.classList.add('d-none');
        showToast(message, variant);
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            inquiryCheckboxes.forEach(function (checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateBulkDeleteState();
        });
    }

    inquiryCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateBulkDeleteState);
    });
    updateBulkDeleteState();

    singleDeleteForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const message = form.getAttribute('data-confirm-message') || 'Delete this inquiry?';
            requestConfirmation(message, function () {
                form.submit();
            });
        });
    });

    if (bulkDeleteForm) {
        bulkDeleteForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const checkedCount = inquiryCheckboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            if (checkedCount === 0) {
                showToast('Select at least one inquiry to delete.', 'warning');
                return;
            }

            const message = checkedCount === 1
                ? 'Delete the selected inquiry?'
                : 'Delete ' + checkedCount + ' selected inquiries?';

            requestConfirmation(message, function () {
                bulkDeleteForm.submit();
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>