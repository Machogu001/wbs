<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if (!$auth->isLoggedIn() || !($auth->isAdmin() || $auth->hasRole('support'))) {
    header('Location: /login');
    exit;
}

$page_title = 'Support Chat';
$is_admin_page = true;

// Current admin/support user id for aligning messages correctly
$currentAdminId = (int)($auth->getUserId() ?? 0);

$chatService = new SupportChat($db);
$threads = $chatService->getThreadsForAdmin(50);

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container py-4 support-chat-page">
    <div class="row">
        <div class="col-12 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-headset"></i> Support Chat</h1>
            <p class="text-muted mb-0" style="font-size:0.9rem;">Chat live with customers and see messages as they arrive.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-2">
                    <strong>Active Conversations</strong>
                </div>
                <div class="card-body p-2" style="max-height: 420px; overflow-y: auto;">
                    <ul class="list-group list-group-flush" id="adminChatThreads">
                        <?php foreach ($threads as $t): ?>
                            <li class="list-group-item list-group-item-action admin-chat-thread" data-thread-id="<?php echo (int)$t['id']; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold" style="font-size:0.9rem;">
                                            <?php
                                            $fullName = isset($t['full_name']) ? trim((string)$t['full_name']) : '';
                                            echo htmlspecialchars($fullName !== '' ? $fullName : 'Customer #' . $t['user_id']);
                                            ?>
                                        </div>
                                        <div class="text-muted" style="font-size:0.8rem;">
                                            <?php echo !empty($t['account_number']) ? 'Account ' . htmlspecialchars($t['account_number']) : ''; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem;">
                                        <?php echo !empty($t['last_message_at']) ? htmlspecialchars($t['last_message_at']) : ''; ?>
                                    </small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($threads)): ?>
                            <li class="list-group-item text-muted small">No conversations yet.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <div>
                        <strong id="adminChatTitle">Select a conversation</strong>
                        <div id="adminChatTypingIndicator" class="text-muted" style="font-size:0.75rem; display:none;">
                            <i class="bi bi-three-dots"></i> Customer is typing...
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check mb-0 small">
                            <input class="form-check-input" type="checkbox" id="adminChatSelectAll" disabled>
                            <label class="form-check-label" for="adminChatSelectAll">Select all</label>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="adminChatDeleteSelected" disabled>
                            <i class="bi bi-trash"></i> Delete selected
                        </button>
                    </div>
                </div>
                <div class="card-body p-2" id="adminChatMessages" style="max-height: 420px; overflow-y: auto;"></div>
                <div class="card-footer p-2">
                    <form id="adminChatForm" class="d-flex align-items-center gap-2">
                        <input type="text" class="form-control form-control-sm" id="adminChatMessageInput" placeholder="Type a reply..." autocomplete="off" disabled>
                        <button type="submit" class="btn btn-primary btn-sm" id="adminChatSendBtn" disabled>
                            <i class="bi bi-send"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
    $deleteModalHtml = <<<HTML
    <div class="modal fade" id="adminChatDeleteModal" tabindex="-1" aria-labelledby="adminChatDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title" id="adminChatDeleteModalLabel" style="font-size:0.95rem;">Delete message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3" style="font-size:0.9rem;">
                    Delete this message? This cannot be undone.
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger btn-sm" id="adminChatDeleteConfirm">Delete</button>
                </div>
            </div>
        </div>
    </div>
    HTML;

    // Output the delete confirmation modal markup so it is available for JS
    echo $deleteModalHtml;

$custom_scripts = <<<HTML
<script>
(function(){
    var ADMIN_USER_ID = {$currentAdminId};
    var currentThreadId = null;
    var lastMessageId = null;
    var pollTimer = null;
    var typingTimeout = null;
    var typingState = false;
    var lastDateKey = null;
    var isLoadingThread = false;
    var deleteMode = 'single'; // 'single' or 'bulk'
    var deleteTargetMessageId = null;
    var deleteTargetElement = null;
    var deleteTargetIds = [];
    var selectedMessageIds = [];

    function formatTime(str) {
        if (!str) return '';
        var parts = String(str).split(' ');
        if (parts.length < 2) return str;
        var timePart = parts[1];
        var t = timePart.split(':');
        if (t.length < 2) return str;
        var h = t[0];
        var m = t[1];
        if (h.length === 1) h = '0' + h;
        if (m.length === 1) m = '0' + m;
        return h + ':' + m;
    }

    function getDateKey(str) {
        if (!str) return null;
        var parts = String(str).split(' ');
        if (!parts[0]) return null;
        return parts[0];
    }

    function getDateLabel(key) {
        if (!key) return '';
        var today = new Date();
        var todayKey = today.toISOString().slice(0,10);
        var y = new Date();
        y.setDate(y.getDate()-1);
        var yKey = y.toISOString().slice(0,10);
        if (key === todayKey) return 'Today';
        if (key === yKey) return 'Yesterday';
        return key;
    }

    function appendMessagesAdmin(msgs) {
        if (!Array.isArray(msgs) || !msgs.length) return;
        var box = document.getElementById('adminChatMessages');
        msgs.forEach(function(m){
            lastMessageId = m.id;
            var createdRaw = m.created_at ? m.created_at : '';
            var dateKey = getDateKey(createdRaw);
            if (dateKey && dateKey !== lastDateKey) {
                lastDateKey = dateKey;
                var label = getDateLabel(dateKey);
                if (label) {
                    var divDay = document.createElement('div');
                    divDay.className = 'support-chat-day-divider';
                    divDay.textContent = label;
                    box.appendChild(divDay);
                }
            }
            var wrapper = document.createElement('div');
            var isFromAdmin = m.sender_id && Number(m.sender_id) === ADMIN_USER_ID;
            wrapper.className = 'support-chat-message ' + (isFromAdmin ? 'support-chat-message-user' : 'support-chat-message-admin');
            if (m.id) {
                wrapper.setAttribute('data-message-id', m.id);
            }

            // Selection checkbox for bulk actions
            var selectWrapper = document.createElement('div');
            selectWrapper.className = 'form-check me-1';
            var select = document.createElement('input');
            select.type = 'checkbox';
            select.className = 'form-check-input admin-chat-select';
            if (m.id) {
                select.setAttribute('data-message-id', m.id);
            }
            selectWrapper.appendChild(select);
            wrapper.appendChild(selectWrapper);
            var bubble = document.createElement('div');
            bubble.className = 'support-chat-bubble';
            var safe = (m.message || '').replace(/&/g,'&amp;').replace(/</g,'&lt;');
            bubble.textContent = safe;
            var timeStr = formatTime(createdRaw);
            if (timeStr) {
                var timeSpan = document.createElement('span');
                timeSpan.className = 'support-chat-timestamp';
                timeSpan.textContent = timeStr;
                wrapper.appendChild(bubble);
                wrapper.appendChild(timeSpan);
            } else {
                wrapper.appendChild(bubble);
            }
            // Delete button for admin/support users to remove a message
            if (m.id) {
                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn btn-link btn-sm text-danger p-0 ms-1 admin-chat-delete';
                delBtn.setAttribute('data-message-id', m.id);
                delBtn.innerHTML = '<i class="bi bi-trash"></i>';
                wrapper.appendChild(delBtn);
            }
            box.appendChild(wrapper);
        });
        box.scrollTop = box.scrollHeight;
    }

    function refreshBulkControls() {
        var btn = document.getElementById('adminChatDeleteSelected');
        var selectAll = document.getElementById('adminChatSelectAll');
        var totalCheckboxes = $('#adminChatMessages .admin-chat-select').length;
        var selectedCount = selectedMessageIds.length;

        if (btn) {
            btn.disabled = selectedCount === 0;
        }
        if (selectAll) {
            selectAll.disabled = totalCheckboxes === 0;
            if (totalCheckboxes === 0) {
                selectAll.checked = false;
            } else {
                selectAll.checked = selectedCount > 0 && selectedCount === totalCheckboxes;
            }
        }
    }

    function setTyping(isTyping) {
        if (!currentThreadId) return;
        if (typingState === isTyping) return;
        typingState = isTyping;
        $.ajax({
            url: '/api/chat/typing',
            method: 'POST',
            dataType: 'json',
            data: { thread_id: currentThreadId, is_typing: isTyping ? 1 : 0 }
        });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(function(){
            if (!currentThreadId) return;
            $.ajax({
                url: '/api/chat/poll',
                method: 'GET',
                dataType: 'json',
                data: { thread_id: currentThreadId, since_id: lastMessageId || '' }
            }).done(function(resp){
                if (resp && resp.success) {
                    if (Array.isArray(resp.messages)) {
                        appendMessagesAdmin(resp.messages);
                    }
                    var typing = resp.typing || {};
                    var ind = document.getElementById('adminChatTypingIndicator');
                    if (ind) {
                        if (typing.user) {
                            ind.style.display = 'block';
                        } else {
                            ind.style.display = 'none';
                        }
                    }
                }
            });
        }, 2000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    $('#adminChatThreads').on('click', '.admin-chat-thread', function(){
        var tid = $(this).data('thread-id');
        if (!tid) return;
        // Prevent duplicate loads on double-click or while already loading
        if (isLoadingThread) return;
        if (currentThreadId === tid && $('#adminChatMessages').children().length > 0) {
            return;
        }

        isLoadingThread = true;
        stopPolling();
        currentThreadId = tid;
        lastMessageId = null;
        lastDateKey = null;
        $('#adminChatMessages').empty();
        selectedMessageIds = [];
        $('#adminChatSelectAll').prop('checked', false).prop('disabled', true);
        refreshBulkControls();
        $('#adminChatTitle').text('Conversation #' + tid);
        $('#adminChatMessageInput').prop('disabled', false);
        $('#adminChatSendBtn').prop('disabled', false);

        $.ajax({
            url: '/api/chat/poll',
            method: 'GET',
            dataType: 'json',
            data: { thread_id: currentThreadId }
        }).done(function(resp){
            if (resp && resp.success && Array.isArray(resp.messages)) {
                appendMessagesAdmin(resp.messages);
            }
            refreshBulkControls();
            startPolling();
        }).always(function(){
            isLoadingThread = false;
        });
    });

    $('#adminChatForm').on('submit', function(e){
        e.preventDefault();
        if (!currentThreadId) return;
        var input = $('#adminChatMessageInput');
        var text = input.val().trim();
        if (!text) return;
        $('#adminChatSendBtn').prop('disabled', true);
        $.ajax({
            url: '/api/chat/send_message',
            method: 'POST',
            dataType: 'json',
            data: { thread_id: currentThreadId, message: text }
        }).done(function(resp){
            if (resp && resp.success) {
                input.val('');
                if (resp.message_data) {
                    appendMessagesAdmin([resp.message_data]);
                }
            } else if (window.showToast) {
                showToast((resp && resp.message) || 'Could not send message.', 'danger');
            }
        }).fail(function(){
            if (window.showToast) {
                showToast('Could not send message. Please try again.', 'danger');
            }
        }).always(function(){
            $('#adminChatSendBtn').prop('disabled', false);
        });
    });

    $('#adminChatMessageInput').on('input keydown', function(){
        if (!currentThreadId) return;
        setTyping(true);
        if (typingTimeout) clearTimeout(typingTimeout);
        typingTimeout = setTimeout(function(){ setTyping(false); }, 3000);
    });

    // Track individual checkbox selection
    $('#adminChatMessages').on('change', '.admin-chat-select', function(){
        var cb = $(this);
        var msgId = parseInt(cb.data('message-id'), 10) || 0;
        if (!msgId) return;

        var idx = selectedMessageIds.indexOf(msgId);
        if (cb.is(':checked')) {
            if (idx === -1) {
                selectedMessageIds.push(msgId);
            }
        } else {
            if (idx !== -1) {
                selectedMessageIds.splice(idx, 1);
            }
        }
        refreshBulkControls();
    });

    // Select all / deselect all
    $('#adminChatSelectAll').on('change', function(){
        var checked = $(this).is(':checked');
        selectedMessageIds = [];
        $('#adminChatMessages .admin-chat-select').each(function(){
            var cb = $(this);
            var msgId = parseInt(cb.data('message-id'), 10) || 0;
            cb.prop('checked', checked);
            if (checked && msgId) {
                selectedMessageIds.push(msgId);
            }
        });
        refreshBulkControls();
    });

    // Delete message handler (admin/support only) using Bootstrap modal instead of browser confirm
    $('#adminChatMessages').on('click', '.admin-chat-delete', function(){
        var btn = $(this);
        var msgId = parseInt(btn.data('message-id'), 10) || 0;
        if (!msgId) return;

        deleteMode = 'single';
        deleteTargetMessageId = msgId;
        deleteTargetElement = btn.closest('.support-chat-message');
        deleteTargetIds = [];

        var modalEl = document.getElementById('adminChatDeleteModal');
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            // Fallback to native confirm if Bootstrap modal is not available
            if (confirm('Delete this message? This cannot be undone.')) {
                $('#adminChatDeleteConfirm').trigger('click');
            }
            return;
        }
        $('#adminChatDeleteModal .modal-body').text('Delete this message? This cannot be undone.');
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    });

    // Bulk delete selected messages
    $('#adminChatDeleteSelected').on('click', function(){
        if (!selectedMessageIds.length) return;

        deleteMode = 'bulk';
        deleteTargetIds = selectedMessageIds.slice();
        deleteTargetMessageId = null;
        deleteTargetElement = null;

        var modalEl = document.getElementById('adminChatDeleteModal');
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            if (confirm('Delete selected messages? This cannot be undone.')) {
                $('#adminChatDeleteConfirm').trigger('click');
            }
            return;
        }
        var body = $('#adminChatDeleteModal .modal-body');
        if (body.length) {
            var count = deleteTargetIds.length;
            body.text('Delete ' + count + ' selected message' + (count > 1 ? 's' : '') + '? This cannot be undone.');
        }
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    });

    $('#adminChatDeleteConfirm').on('click', function(){
        if (deleteMode === 'bulk') {
            if (!deleteTargetIds.length) return;

            $.ajax({
                url: '/api/chat/delete_messages',
                method: 'POST',
                dataType: 'json',
                traditional: true,
                data: { message_ids: deleteTargetIds }
            }).done(function(resp){
                if (resp && resp.success) {
                    deleteTargetIds.forEach(function(id){
                        var el = $('#adminChatMessages .support-chat-message[data-message-id="' + id + '"]');
                        if (el && el.length) {
                            el.remove();
                        }
                    });
                    selectedMessageIds = [];
                    refreshBulkControls();
                    if (window.showToast) {
                        showToast('Selected messages deleted.', 'success');
                    }
                    var modalEl = document.getElementById('adminChatDeleteModal');
                    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                } else if (window.showToast) {
                    showToast((resp && resp.message) || 'Could not delete selected messages.', 'danger');
                }
            }).fail(function(){
                if (window.showToast) {
                    showToast('Could not delete selected messages. Please try again.', 'danger');
                }
            }).always(function(){
                deleteTargetIds = [];
            });
        } else {
            if (!deleteTargetMessageId) return;

            $.ajax({
                url: '/api/chat/delete_message',
                method: 'POST',
                dataType: 'json',
                data: { message_id: deleteTargetMessageId }
            }).done(function(resp){
                if (resp && resp.success) {
                    if (deleteTargetElement && deleteTargetElement.length) {
                        var msgId = parseInt(deleteTargetElement.data('message-id'), 10) || 0;
                        deleteTargetElement.remove();
                        if (msgId) {
                            var idx = selectedMessageIds.indexOf(msgId);
                            if (idx !== -1) {
                                selectedMessageIds.splice(idx, 1);
                            }
                        }
                        refreshBulkControls();
                    }
                    if (window.showToast) {
                        showToast('Message deleted.', 'success');
                    }
                    var modalEl = document.getElementById('adminChatDeleteModal');
                    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                } else if (window.showToast) {
                    showToast((resp && resp.message) || 'Could not delete message.', 'danger');
                }
            }).fail(function(){
                if (window.showToast) {
                    showToast('Could not delete message. Please try again.', 'danger');
                }
            }).always(function(){
                deleteTargetMessageId = null;
                deleteTargetElement = null;
            });
        }
    });
})();
</script>
HTML;

require_once __DIR__ . '/../../templates/footer.php';