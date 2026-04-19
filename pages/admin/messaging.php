<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/InternalComms.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header('Location: /login');
    exit;
}

if (!$auth->isAdmin() && !$auth->hasPermission('send_messages')) {
    header('Location: /dashboard');
    exit;
}

$isAdmin = $auth->isAdmin();
$canSendMessages = $isAdmin || $auth->hasPermission('send_messages');
$currentUserId = (int)($auth->getUserId() ?? 0);

$comms = new InternalComms($db);

if (empty($_SESSION['messaging_csrf'])) {
    $_SESSION['messaging_csrf'] = bin2hex(random_bytes(32));
}

$successMessage = '';
$errorMessage = '';

if (!empty($_SESSION['messaging_flash']) && is_array($_SESSION['messaging_flash'])) {
    $successMessage = (string)($_SESSION['messaging_flash']['success'] ?? '');
    $errorMessage = (string)($_SESSION['messaging_flash']['error'] ?? '');
    unset($_SESSION['messaging_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSendMessages) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['messaging_csrf'], $token)) {
        $errorMessage = 'Security validation failed. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'send_client_broadcast') {
            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $recipientGroup = (string)($_POST['recipient_group'] ?? 'clients');
            $audience = (string)($_POST['audience'] ?? 'all_clients');
            $selectedIds = [];
            if ($audience === 'selected_clients') {
                $selectedIds = isset($_POST['client_ids']) ? (array)$_POST['client_ids'] : [];
                $recipientGroup = 'clients';
            } elseif ($audience === 'selected_staff') {
                $selectedIds = isset($_POST['staff_ids']) ? (array)$_POST['staff_ids'] : [];
                $recipientGroup = 'staff';
            } elseif ($audience === 'all_staff') {
                $recipientGroup = 'staff';
            } else {
                $recipientGroup = 'clients';
            }

            $sendToAll = in_array($audience, ['all_clients', 'all_staff'], true);
            $result = $comms->sendSmsBroadcast($currentUserId, $subject, $message, $recipientGroup, $sendToAll, $selectedIds);

            if (!empty($result['success'])) {
                $groupLabel = $recipientGroup === 'staff' ? 'staff member(s)' : 'client(s)';
                $totalRecipients = (int)($result['recipient_count'] ?? 0);
                $sentNow = (int)($result['immediate_sent_count'] ?? 0);
                $queuedLater = (int)($result['queued_count'] ?? 0);
                $failed = (int)($result['failed_count'] ?? 0);
                $mode = (string)($result['delivery_mode'] ?? 'queued');

                if ($mode === 'immediate') {
                    $successMessage = 'Message processed for ' . $totalRecipients . ' ' . $groupLabel . ': sent now ' . $sentNow . ', queued ' . $queuedLater . ', failed ' . $failed . '.';
                } else {
                    $successMessage = 'Message queued for ' . $totalRecipients . ' ' . $groupLabel . ': queued ' . $queuedLater . ', failed ' . $failed . '.';
                }
            } else {
                $errorMessage = (string)($result['message'] ?? 'Could not send message.');
            }
        } elseif ($action === 'save_template') {
            $recipientGroup = (string)($_POST['recipient_group'] ?? 'clients');
            $title = trim((string)($_POST['template_title'] ?? ''));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));

            $result = $comms->saveCustomTemplate($currentUserId, $recipientGroup, $title, $subject, $message);
            if (!empty($result['success'])) {
                $successMessage = (string)$result['message'];
            } else {
                $errorMessage = (string)($result['message'] ?? 'Could not save template.');
            }
        } elseif ($action === 'delete_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $result = $comms->deleteCustomTemplate($templateId, $currentUserId);
            if (!empty($result['success'])) {
                $successMessage = (string)$result['message'];
            } else {
                $errorMessage = (string)($result['message'] ?? 'Could not delete template.');
            }
        }
    }

    $_SESSION['messaging_flash'] = [
        'success' => $successMessage,
        'error' => $errorMessage,
    ];

    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    if (!is_string($redirectUrl) || $redirectUrl === '') {
        $redirectUrl = '/admin/messaging';
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$clients = $canSendMessages ? $comms->getActiveClients() : [];
$staff = $canSendMessages ? $comms->getActiveStaff() : [];
$recentBroadcasts = $canSendMessages ? $comms->getRecentBroadcasts(15) : [];
$customClientTemplates = $canSendMessages ? $comms->getCustomTemplates('clients', 200) : [];
$customStaffTemplates = $canSendMessages ? $comms->getCustomTemplates('staff', 200) : [];
$initialMessages = $comms->getInternalMessages(null, 120);

$page_title = 'Messaging Center';
$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell admin-messaging-page">
    <div class="row">
        <div class="col-12">
            <div class="pb-banner pb-banner--indigo mb-3">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-chat-dots-fill pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-chat-dots-fill"></i> Communications</span>
                        </div>
                        <h2 class="pb-title">Messaging Center</h2>
                        <p class="pb-subtitle">Send announcements to clients and coordinate with staff using internal chat.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3 g-2 align-items-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold" style="font-size:0.92rem;">Support availability</div>
                        <div class="text-muted" style="font-size:0.8rem;">Enable to appear online to customers and visitors.</div>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="msgAvailabilityToggle">
                        <label class="form-check-label" for="msgAvailabilityToggle" id="msgAvailabilityToggleLabel">Offline</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body py-2">
                    <div class="fw-semibold mb-1" style="font-size:0.92rem;">Available team members</div>
                    <div id="msgAvailabilityNames" class="text-muted" style="font-size:0.82rem;">Checking availability...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-megaphone"></i> SMS Notifications</h5>
                </div>
                <div class="card-body">
                    <?php if (!$canSendMessages): ?>
                        <div class="alert alert-info mb-0">Only admins can send SMS notifications and manage templates.</div>
                    <?php else: ?>
                        <form method="post" id="clientBroadcastForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['messaging_csrf']); ?>">
                            <input type="hidden" name="action" value="send_client_broadcast">

                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject *</label>
                                <input type="text" class="form-control" id="subject" name="subject" maxlength="191" required>
                            </div>

                            <div class="mb-3">
                                <label for="message" class="form-label">Message *</label>
                                <textarea class="form-control" id="message" name="message" rows="4" required></textarea>
                                <div class="form-text">Message is queued via SMS to recipient phone numbers.</div>
                            </div>

                            <div class="mb-3">
                                <label for="recipient_group" class="form-label">Recipient Group *</label>
                                <select class="form-select" id="recipient_group" name="recipient_group" required>
                                    <option value="clients" selected>Clients</option>
                                    <option value="staff">Staff</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="message_template" class="form-label">Template</label>
                                <div class="input-group">
                                    <select class="form-select" id="message_template">
                                        <option value="">Custom message (no template)</option>
                                    </select>
                                    <button class="btn btn-outline-primary" type="button" id="applyTemplateBtn">
                                        <i class="bi bi-magic"></i> Apply
                                    </button>
                                </div>
                                <div class="form-text">Choose a template to auto-fill subject and message, then edit before sending.</div>
                            </div>

                            <div class="mb-3 border rounded p-2 bg-light">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label for="template_title" class="form-label mb-1">Template Name</label>
                                        <input type="text" class="form-control form-control-sm" id="template_title" name="template_title" maxlength="120" placeholder="e.g. Monthly Reminder">
                                    </div>
                                    <div class="col-md-7 d-flex gap-2">
                                        <button type="button" class="btn btn-outline-success btn-sm" id="saveTemplateBtn">
                                            <i class="bi bi-bookmark-plus"></i> Save Custom Template
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="deleteTemplateBtn" disabled>
                                            <i class="bi bi-trash"></i> Delete Selected Template
                                        </button>
                                    </div>
                                </div>
                                <div class="form-text">Custom templates are saved per recipient group and can be reused anytime.</div>
                            </div>

                            <div class="mb-3">
                                <label for="audience" class="form-label">Recipients *</label>
                                <select class="form-select" id="audience" name="audience" required>
                                    <option value="all_clients" selected>All Active Clients</option>
                                    <option value="selected_clients">Selected Clients</option>
                                    <option value="all_staff">All Active Staff</option>
                                    <option value="selected_staff">Selected Staff</option>
                                </select>
                            </div>

                            <div class="mb-3 d-none" id="selectedClientsWrap">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">Choose Clients</label>
                                    <div class="form-check mb-0 small">
                                        <input class="form-check-input" type="checkbox" id="selectAllClients">
                                        <label class="form-check-label" for="selectAllClients">Select all</label>
                                    </div>
                                </div>
                                <div class="border rounded p-2" style="max-height: 260px; overflow-y: auto;">
                                    <?php if (empty($clients)): ?>
                                        <p class="text-muted small mb-0">No active clients found.</p>
                                    <?php else: ?>
                                        <?php foreach ($clients as $client): ?>
                                            <div class="form-check">
                                                <input class="form-check-input client-checkbox" type="checkbox" name="client_ids[]" value="<?php echo (int)$client['id']; ?>" id="client_<?php echo (int)$client['id']; ?>">
                                                <label class="form-check-label" for="client_<?php echo (int)$client['id']; ?>">
                                                    <?php echo htmlspecialchars($client['full_name']); ?>
                                                    <span class="text-muted small">(<?php echo htmlspecialchars($client['account_number'] ?? ''); ?>)</span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3 d-none" id="selectedStaffWrap">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">Choose Staff</label>
                                    <div class="form-check mb-0 small">
                                        <input class="form-check-input" type="checkbox" id="selectAllStaff">
                                        <label class="form-check-label" for="selectAllStaff">Select all</label>
                                    </div>
                                </div>
                                <div class="border rounded p-2" style="max-height: 260px; overflow-y: auto;">
                                    <?php if (empty($staff)): ?>
                                        <p class="text-muted small mb-0">No active staff found.</p>
                                    <?php else: ?>
                                        <?php foreach ($staff as $member): ?>
                                            <div class="form-check">
                                                <input class="form-check-input staff-checkbox" type="checkbox" name="staff_ids[]" value="<?php echo (int)$member['id']; ?>" id="staff_<?php echo (int)$member['id']; ?>">
                                                <label class="form-check-label" for="staff_<?php echo (int)$member['id']; ?>">
                                                    <?php echo htmlspecialchars($member['full_name']); ?>
                                                    <span class="text-muted small">(<?php echo htmlspecialchars(ucfirst((string)($member['role'] ?? 'staff'))); ?>)</span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send"></i> Send Broadcast
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-people"></i> Internal Staff Chat</h5>
                    <small class="text-muted">For admin and office staff</small>
                </div>
                <div class="card-body d-flex flex-column" style="min-height: 500px;">
                    <div id="internalChatBox" class="flex-grow-1 border rounded p-3 mb-3" style="overflow-y: auto; max-height: 420px; background: #f8fafc;"></div>
                    <form id="internalChatForm" class="d-flex gap-2">
                        <input type="text" id="internalChatInput" class="form-control" placeholder="Type a message to staff..." autocomplete="off" required>
                        <button type="submit" class="btn btn-primary" id="internalChatSendBtn">
                            <i class="bi bi-send"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canSendMessages): ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Broadcasts</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Audience</th>
                                    <th>Subject</th>
                                    <th>Recipients</th>
                                    <th>Sent By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentBroadcasts)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">No broadcasts yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentBroadcasts as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($item['created_at']))); ?></td>
                                            <td>
                                                <?php $aud = (string)($item['audience'] ?? ''); ?>
                                                <?php if ($aud === 'clients_all'): ?>
                                                    <span class="badge bg-primary">All Clients</span>
                                                <?php elseif ($aud === 'clients_selected'): ?>
                                                    <span class="badge bg-info text-dark">Selected Clients</span>
                                                <?php elseif ($aud === 'staff_all'): ?>
                                                    <span class="badge bg-secondary">All Staff</span>
                                                <?php elseif ($aud === 'staff_selected'): ?>
                                                    <span class="badge bg-dark">Selected Staff</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['subject'] ?? ''); ?></td>
                                            <td><?php echo (int)($item['recipient_count'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars($item['sender_name'] ?? 'Admin'); ?></td>
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
    <?php endif; ?>
</div>

<?php
$initialMessagesJson = json_encode($initialMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$currentUserName = (string)($_SESSION['user_data']['full_name'] ?? 'Staff');
$currentUserRole = (string)($_SESSION['user_data']['role'] ?? 'staff');
$currentUserNameJson = json_encode($currentUserName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$currentUserRoleJson = json_encode($currentUserRole, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customClientTemplatesJson = json_encode($customClientTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customStaffTemplatesJson = json_encode($customStaffTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$successMessageJson = json_encode($successMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$errorMessageJson = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$custom_scripts = <<<JS
<script>
(function() {
    var currentUserId = {$currentUserId};
    var currentUserName = {$currentUserNameJson};
    var currentUserRole = {$currentUserRoleJson};
    var lastMessageId = null;
    var pollTimer = null;
    var initialMessages = {$initialMessagesJson};
    var customClientTemplates = {$customClientTemplatesJson} || [];
    var customStaffTemplates = {$customStaffTemplatesJson} || [];
    var successMessage = {$successMessageJson};
    var errorMessage = {$errorMessageJson};
    var templateCatalog = {
        clients: [
            {
                id: 'payment_reminder',
                subject: 'Payment Reminder',
                message: 'Dear client, this is a reminder to pay your pending water bill by the due date to avoid penalties. Thank you.'
            },
            {
                id: 'overdue_notice',
                subject: 'Overdue Bill Notice',
                message: 'Dear client, your water bill is overdue. Kindly clear the outstanding balance as soon as possible to avoid service interruption.'
            },
            {
                id: 'payment_received',
                subject: 'Payment Received',
                message: 'Dear client, we have received your payment. Thank you for paying your water bill on time.'
            },
            {
                id: 'service_outage',
                subject: 'Planned Water Interruption',
                message: 'Notice: Water supply will be temporarily interrupted in your area due to maintenance works. We apologize for the inconvenience.'
            },
            {
                id: 'maintenance_complete',
                subject: 'Maintenance Update',
                message: 'Update: Scheduled maintenance has been completed and water supply is being restored. Thank you for your patience.'
            },
            {
                id: 'meter_reading_notice',
                subject: 'Meter Reading Notice',
                message: 'Notice: Meter reading will be conducted in your area soon. Please ensure your meter is accessible to our staff.'
            },
            {
                id: 'community_meeting',
                subject: 'Community Meeting Notice',
                message: 'Notice: You are invited to the community water meeting on [DATE] at [TIME] in [VENUE]. Your attendance is appreciated.'
            }
        ],
        staff: [
            {
                id: 'staff_meeting',
                subject: 'Staff Meeting',
                message: 'Team notice: Staff meeting scheduled on [DATE] at [TIME] in [VENUE]. Please be on time.'
            },
            {
                id: 'shift_reminder',
                subject: 'Shift Reminder',
                message: 'Reminder: Your assigned shift starts at [TIME] on [DATE]. Please report on time and follow reporting procedures.'
            },
            {
                id: 'field_assignment',
                subject: 'Field Assignment',
                message: 'Assignment: Please handle meter reading and follow-up in [AREA] today. Submit your report before end of day.'
            },
            {
                id: 'urgent_operation',
                subject: 'Urgent Operations Alert',
                message: 'Urgent: Immediate operational attention required in [AREA/SITE]. Coordinate with the team and update admin once resolved.'
            },
            {
                id: 'system_downtime',
                subject: 'System Downtime Notice',
                message: 'Notice: The billing system will undergo maintenance on [DATE] from [START TIME] to [END TIME]. Plan work accordingly.'
            },
            {
                id: 'policy_update',
                subject: 'Policy Update',
                message: 'Notice: A new operations/policy update is now in effect. Please review and apply it in your daily workflow.'
            },
            {
                id: 'general_staff_notice',
                subject: 'General Staff Notice',
                message: 'Team notice: Please check your duties dashboard and complete pending tasks within the required timelines.'
            }
        ]
    };

    function normalizeCustomTemplates(items) {
        if (!Array.isArray(items)) return [];
        return items.map(function(item) {
            var id = Number(item.id || 0);
            var title = item.title ? String(item.title) : 'Custom Template';
            var subject = item.subject ? String(item.subject) : title;
            var message = item.message ? String(item.message) : '';
            return {
                id: 'custom:' + id,
                subject: '[Custom] ' + title,
                subjectValue: subject,
                message: message,
                isCustom: true,
                templateId: id
            };
        });
    }

    templateCatalog.clients = templateCatalog.clients.concat(normalizeCustomTemplates(customClientTemplates));
    templateCatalog.staff = templateCatalog.staff.concat(normalizeCustomTemplates(customStaffTemplates));

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatTime(timestamp) {
        if (!timestamp) return '';
        var date = new Date(timestamp.replace(' ', 'T'));
        if (isNaN(date.getTime())) return timestamp;
        var hh = String(date.getHours()).padStart(2, '0');
        var mm = String(date.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    function renderMessage(msg) {
        var box = document.getElementById('internalChatBox');
        if (!box) return;

        var id = Number(msg.id || 0);
        if (id > 0) {
            lastMessageId = id;
        }

        var mine = Number(msg.sender_id || 0) === Number(currentUserId);
        var role = msg.role ? String(msg.role) : 'staff';
        var sender = msg.full_name ? String(msg.full_name) : currentUserName;

        var row = document.createElement('div');
        row.className = 'd-flex mb-2 ' + (mine ? 'justify-content-end' : 'justify-content-start');

        var bubble = document.createElement('div');
        bubble.className = 'p-2 rounded';
        bubble.style.maxWidth = '78%';
        bubble.style.background = mine ? 'linear-gradient(135deg, #1d4ed8, #0f766e)' : '#ffffff';
        bubble.style.color = mine ? '#ffffff' : '#0f172a';
        bubble.style.border = mine ? 'none' : '1px solid #cbd5e1';

        var roleLabel = role.charAt(0).toUpperCase() + role.slice(1);
        bubble.innerHTML =
            '<div class="small fw-semibold" style="opacity:' + (mine ? '0.95' : '1') + ';">' +
            escapeHtml(sender) + ' · ' + escapeHtml(roleLabel) +
            '</div>' +
            '<div style="white-space: pre-wrap;">' + escapeHtml(msg.message || '') + '</div>' +
            '<div class="small mt-1" style="opacity:' + (mine ? '0.8' : '0.65') + ';">' + escapeHtml(formatTime(msg.created_at || '')) + '</div>';

        row.appendChild(bubble);
        box.appendChild(row);
        box.scrollTop = box.scrollHeight;
    }

    function renderInitial() {
        var box = document.getElementById('internalChatBox');
        if (!box) return;
        box.innerHTML = '';
        if (!Array.isArray(initialMessages) || initialMessages.length === 0) {
            box.innerHTML = '<p class="text-muted small mb-0">No internal messages yet. Start the conversation.</p>';
            return;
        }

        initialMessages.forEach(function(msg) {
            renderMessage(msg);
        });
    }

    function pollMessages() {
        var url = '/api/internal_chat/poll';
        if (lastMessageId) {
            url += '?since_id=' + encodeURIComponent(String(lastMessageId));
        }

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (!data || !data.success || !Array.isArray(data.messages)) return;
            data.messages.forEach(function(msg) {
                renderMessage(msg);
            });
        })
        .catch(function() {
            // Silent polling failure.
        });
    }

    function startPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(pollMessages, 3000);
    }

    var form = document.getElementById('internalChatForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var input = document.getElementById('internalChatInput');
            var btn = document.getElementById('internalChatSendBtn');
            if (!input || !btn) return;

            var text = input.value.trim();
            if (!text) return;

            btn.disabled = true;

            var body = new URLSearchParams();
            body.append('message', text);

            fetch('/api/internal_chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data && data.success) {
                    input.value = '';
                    if (data.message_data) {
                        renderMessage(data.message_data);
                    } else {
                        pollMessages();
                    }
                } else if (window.showToast) {
                    showToast((data && data.message) ? data.message : 'Could not send message.', 'danger');
                }
            })
            .catch(function() {
                if (window.showToast) {
                    showToast('Could not send message.', 'danger');
                }
            })
            .finally(function() {
                btn.disabled = false;
            });
        });
    }

    var audience = document.getElementById('audience');
    var recipientGroup = document.getElementById('recipient_group');
    var templateSelect = document.getElementById('message_template');
    var applyTemplateBtn = document.getElementById('applyTemplateBtn');
    var saveTemplateBtn = document.getElementById('saveTemplateBtn');
    var deleteTemplateBtn = document.getElementById('deleteTemplateBtn');
    var templateTitleInput = document.getElementById('template_title');
    var selectedWrap = document.getElementById('selectedClientsWrap');
    var selectedStaffWrap = document.getElementById('selectedStaffWrap');
    var selectAll = document.getElementById('selectAllClients');
    var selectAllStaff = document.getElementById('selectAllStaff');

    function populateTemplateOptions() {
        if (!templateSelect || !recipientGroup) return;
        var group = recipientGroup.value === 'staff' ? 'staff' : 'clients';
        var items = templateCatalog[group] || [];

        templateSelect.innerHTML = '';

        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Custom message (no template)';
        templateSelect.appendChild(defaultOption);

        items.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.subject;
            if (item.isCustom) {
                opt.setAttribute('data-custom', '1');
                opt.setAttribute('data-template-id', String(item.templateId || ''));
            }
            templateSelect.appendChild(opt);
        });

        syncTemplateButtons();
    }

    function applySelectedTemplate() {
        if (!templateSelect || !recipientGroup) return;
        var selectedId = templateSelect.value;
        if (!selectedId) return;

        var group = recipientGroup.value === 'staff' ? 'staff' : 'clients';
        var items = templateCatalog[group] || [];
        var tpl = items.find(function(item) { return item.id === selectedId; });
        if (!tpl) return;

        var subjectInput = document.getElementById('subject');
        var messageInput = document.getElementById('message');
        if (subjectInput) {
            subjectInput.value = tpl.subjectValue ? tpl.subjectValue : tpl.subject;
        }
        if (messageInput) {
            messageInput.value = tpl.message;
        }

        syncTemplateButtons();
    }

    function syncTemplateButtons() {
        if (!templateSelect || !deleteTemplateBtn) return;
        var selected = templateSelect.options[templateSelect.selectedIndex];
        var isCustom = selected && selected.getAttribute('data-custom') === '1';
        deleteTemplateBtn.disabled = !isCustom;
    }

    function submitTemplateAction(action, extraFields) {
        var formEl = document.getElementById('clientBroadcastForm');
        if (!formEl) return;

        var hiddenAction = formEl.querySelector('input[name="action"]');
        if (!hiddenAction) return;

        var oldAction = hiddenAction.value;
        hiddenAction.value = action;

        var tempFields = [];
        (extraFields || []).forEach(function(field) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = field.name;
            input.value = field.value;
            formEl.appendChild(input);
            tempFields.push(input);
        });

        formEl.submit();

        hiddenAction.value = oldAction;
        tempFields.forEach(function(input) {
            if (input && input.parentNode) {
                input.parentNode.removeChild(input);
            }
        });
    }

    function syncAudienceOptions() {
        if (!audience || !recipientGroup) return;

        var group = recipientGroup.value;
        if (group === 'staff') {
            audience.value = (audience.value === 'selected_staff') ? 'selected_staff' : 'all_staff';
        } else {
            audience.value = (audience.value === 'selected_clients') ? 'selected_clients' : 'all_clients';
        }
        populateTemplateOptions();
        syncAudience();
    }

    function syncAudience() {
        if (!audience || !selectedWrap || !selectedStaffWrap) return;
        selectedWrap.classList.toggle('d-none', audience.value !== 'selected_clients');
        selectedStaffWrap.classList.toggle('d-none', audience.value !== 'selected_staff');
    }

    if (audience) {
        audience.addEventListener('change', syncAudience);
        syncAudience();
    }
    if (recipientGroup) {
        recipientGroup.addEventListener('change', syncAudienceOptions);
        populateTemplateOptions();
    }
    if (applyTemplateBtn) {
        applyTemplateBtn.addEventListener('click', applySelectedTemplate);
    }
    if (templateSelect) {
        templateSelect.addEventListener('change', syncTemplateButtons);
    }

    if (saveTemplateBtn) {
        saveTemplateBtn.addEventListener('click', function() {
            var title = templateTitleInput ? String(templateTitleInput.value || '').trim() : '';
            var subjectInput = document.getElementById('subject');
            var messageInput = document.getElementById('message');
            var subject = subjectInput ? String(subjectInput.value || '').trim() : '';
            var message = messageInput ? String(messageInput.value || '').trim() : '';

            if (!title) {
                if (window.showToast) showToast('Enter a template name first.', 'warning');
                return;
            }
            if (!subject || !message) {
                if (window.showToast) showToast('Subject and message are required to save a template.', 'warning');
                return;
            }

            submitTemplateAction('save_template', []);
        });
    }

    if (deleteTemplateBtn) {
        deleteTemplateBtn.addEventListener('click', function() {
            if (!templateSelect) return;
            var selected = templateSelect.options[templateSelect.selectedIndex];
            if (!selected || selected.getAttribute('data-custom') !== '1') {
                if (window.showToast) showToast('Select a custom template to delete.', 'warning');
                return;
            }

            var templateId = selected.getAttribute('data-template-id');
            if (!templateId) return;

            submitTemplateAction('delete_template', [
                { name: 'template_id', value: templateId }
            ]);
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checks = document.querySelectorAll('.client-checkbox');
            checks.forEach(function(cb) {
                cb.checked = !!selectAll.checked;
            });
        });
    }
    if (selectAllStaff) {
        selectAllStaff.addEventListener('change', function() {
            var checks = document.querySelectorAll('.staff-checkbox');
            checks.forEach(function(cb) {
                cb.checked = !!selectAllStaff.checked;
            });
        });
    }

    if (typeof Swal !== 'undefined') {
        if (successMessage) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: successMessage,
                showConfirmButton: false,
                timer: 3600,
                timerProgressBar: true
            });
        } else if (errorMessage) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'error',
                title: errorMessage,
                showConfirmButton: false,
                timer: 4200,
                timerProgressBar: true
            });
        }
    }

    renderInitial();
    startPolling();
})();
</script>
JS;

?>
<script>
(function() {
    var toggle = document.getElementById('msgAvailabilityToggle');
    var toggleLabel = document.getElementById('msgAvailabilityToggleLabel');
    var namesEl = document.getElementById('msgAvailabilityNames');
    if (!toggle) return;

    function renderAvailability(resp) {
        if (!resp || !resp.success) {
            if (namesEl) namesEl.textContent = 'Could not load availability right now.';
            return;
        }
        var agents = Array.isArray(resp.agents) ? resp.agents : [];
        var recentAgents = Array.isArray(resp.recent_agents) ? resp.recent_agents : [];
        if (namesEl) {
            if (agents.length > 0) {
                var details = agents.map(function(a) { return (a.name || 'Support') + ' (online)'; });
                namesEl.textContent = details.join(', ');
                namesEl.className = 'text-success';
                namesEl.style.fontSize = '0.82rem';
            } else if (recentAgents.length > 0) {
                var latest = recentAgents[0];
                var label = latest.name || 'Support';
                var timeLabel = '';
                if (latest.updated_at) {
                    var parsed = new Date(String(latest.updated_at).replace(' ', 'T'));
                    if (!isNaN(parsed.getTime())) {
                        timeLabel = parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    }
                }
                namesEl.textContent = timeLabel ? 'Offline. ' + label + ' (last seen ' + timeLabel + ')' : 'Offline. ' + label;
                namesEl.className = 'text-muted';
                namesEl.style.fontSize = '0.82rem';
            } else {
                namesEl.textContent = 'No support agents currently available.';
                namesEl.className = 'text-muted';
                namesEl.style.fontSize = '0.82rem';
            }
        }
        toggle.checked = !!resp.current_user_available;
        toggleLabel.textContent = resp.current_user_available ? 'Online' : 'Offline';
    }

    function loadAvailability() {
        fetch('/api/chat/availability?_ts=' + Date.now())
            .then(function(r) { return r.json(); })
            .then(renderAvailability)
            .catch(function() {});
    }

    toggle.addEventListener('change', function() {
        var isAvailable = toggle.checked ? 1 : 0;
        toggle.disabled = true;
        var fd = new FormData();
        fd.append('available', isAvailable);
        fetch('/api/chat/availability', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp && resp.success) {
                    toggleLabel.textContent = isAvailable ? 'Online' : 'Offline';
                    if (window.Swal) {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: isAvailable ? 'Status set to ONLINE.' : 'Status set to OFFLINE.', showConfirmButton: false, timer: 2400, timerProgressBar: true });
                    }
                } else {
                    toggle.checked = !toggle.checked;
                    toggleLabel.textContent = toggle.checked ? 'Online' : 'Offline';
                }
            })
            .catch(function() { toggle.checked = !toggle.checked; toggleLabel.textContent = toggle.checked ? 'Online' : 'Offline'; })
            .finally(function() { toggle.disabled = false; loadAvailability(); });
    });

    loadAvailability();
    setInterval(loadAvailability, 10000);
})();
</script>

<?php

require_once __DIR__ . '/../../templates/footer.php';
