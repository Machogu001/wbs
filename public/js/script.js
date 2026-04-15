// Main JavaScript file for Water Billing System

$(document).ready(function() {
    (function initGlobalClientErrorReporter() {
        if (window.__wbsClientErrorReporterInit) {
            return;
        }
        window.__wbsClientErrorReporterInit = true;

        var endpoint = '/api/system/client_error';
        var ignoredSourcePrefixes = ['chrome-extension://', 'moz-extension://', 'safari-extension://', 'extensions::'];
        var ignoredSourcePatterns = [/CloseDisplay\.js/i];
        var seen = {};
        var sentCount = 0;
        var maxReportsPerPage = 5;

        function sanitize(value, maxLen) {
            if (value == null) return '';
            var text = String(value);
            if (text.length > maxLen) {
                return text.slice(0, maxLen);
            }
            return text;
        }

        function shouldIgnore(source, message) {
            var src = String(source || '');
            var msg = String(message || '');

            for (var i = 0; i < ignoredSourcePrefixes.length; i++) {
                if (src.indexOf(ignoredSourcePrefixes[i]) === 0) {
                    return true;
                }
            }

            for (var j = 0; j < ignoredSourcePatterns.length; j++) {
                if (ignoredSourcePatterns[j].test(src) || ignoredSourcePatterns[j].test(msg)) {
                    return true;
                }
            }

            return false;
        }

        function send(payload) {
            if (sentCount >= maxReportsPerPage) {
                return;
            }

            var key = [payload.type, payload.message, payload.source, payload.line, payload.column].join('|');
            if (seen[key]) {
                return;
            }
            seen[key] = true;
            sentCount++;

            var body = JSON.stringify(payload);

            try {
                if (navigator.sendBeacon) {
                    var blob = new Blob([body], { type: 'application/json' });
                    navigator.sendBeacon(endpoint, blob);
                    return;
                }
            } catch (e) {
                // Fall through to fetch.
            }

            try {
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    keepalive: true,
                    body: body
                }).catch(function() {});
            } catch (e2) {
                // Ignore client logging failures.
            }
        }

        window.addEventListener('error', function(event) {
            var source = sanitize(event.filename || '', 300);
            var message = sanitize(event.message || 'Unknown JS error', 500);

            if (shouldIgnore(source, message)) {
                return;
            }

            send({
                type: 'error',
                message: message,
                source: source,
                line: Number(event.lineno || 0),
                column: Number(event.colno || 0),
                stack: sanitize(event.error && event.error.stack ? event.error.stack : '', 2000),
                page: sanitize(window.location.pathname || '', 300),
                userAgent: sanitize(navigator.userAgent || '', 500)
            });
        });

        window.addEventListener('unhandledrejection', function(event) {
            var reason = event.reason;
            var message = '';
            var stack = '';

            if (reason && typeof reason === 'object') {
                message = reason.message || String(reason);
                stack = reason.stack || '';
            } else {
                message = String(reason || 'Unhandled promise rejection');
            }

            message = sanitize(message, 500);
            if (shouldIgnore('', message)) {
                return;
            }

            send({
                type: 'unhandledrejection',
                message: message,
                source: '',
                line: 0,
                column: 0,
                stack: sanitize(stack, 2000),
                page: sanitize(window.location.pathname || '', 300),
                userAgent: sanitize(navigator.userAgent || '', 500)
            });
        });
    })();

    function resolveAlertTypeFromClass(el) {
        if (!el || !el.classList) return 'info';
        if (el.classList.contains('alert-danger')) return 'danger';
        if (el.classList.contains('alert-warning')) return 'warning';
        if (el.classList.contains('alert-info')) return 'info';
        if (el.classList.contains('alert-success')) return 'success';
        return 'info';
    }

    // Convert inline alerts to Sweet toasts so notifications are not shown inline.
    function convertInlineAlertsToToasts() {
        var alerts = document.querySelectorAll('main .alert');
        if (!alerts || !alerts.length) return;

        Array.prototype.forEach.call(alerts, function(el) {
            if (!el || !el.parentNode) return;
            if (el.classList.contains('alert-permanent') || el.classList.contains('alert-inline-allow') || el.classList.contains('d-none')) {
                return;
            }
            if (el.getClientRects().length === 0) {
                return;
            }

            var message = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!message) {
                el.parentNode.removeChild(el);
                return;
            }

            var type = resolveAlertTypeFromClass(el);
            if (window.WbsAdminUi && typeof window.WbsAdminUi.showFlashToast === 'function') {
                window.WbsAdminUi.showFlashToast(message, type);
            } else if (typeof window.showToast === 'function') {
                window.showToast(message, type);
            }

            el.parentNode.removeChild(el);
        });
    }

    // Auto-format phone numbers
    $('input[type="tel"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        
        if(value.startsWith('0')) {
            value = '254' + value.substr(1);
        } else if(!value.startsWith('254')) {
            value = '254' + value;
        }
        
        if(value.length > 12) {
            value = value.substr(0, 12);
        }
        
        $(this).val(value);
    });
    
    // Toggle password visibility
    $('.toggle-password').on('click', function() {
        const input = $(this).closest('.input-group').find('input');
        const icon = $(this).find('i');
        
        if(input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('bi-eye-slash').addClass('bi-eye');
        }
    });
    
    // Auto-hide alerts after 5 seconds.
    // Keep timed callback tiny; do real work in idle/rAF to avoid long-task violations.
    function runAutoHideAlerts() {
        var autoHideAlerts = Array.prototype.filter.call(document.getElementsByClassName('alert'), function(el) {
            if (el.classList.contains('alert-permanent') || el.classList.contains('d-none')) return false;
            return el.getClientRects().length > 0;
        });

        if (!autoHideAlerts.length) return;

        requestAnimationFrame(function() {
            autoHideAlerts.forEach(function(el) {
                if (!el || !el.parentNode) return;
                el.classList.add('alert-fading');
                el.addEventListener('transitionend', function handler() {
                    el.removeEventListener('transitionend', handler);
                    if (el.parentNode) el.parentNode.removeChild(el);
                }, { once: true });
            });
        });
    }

    setTimeout(function() {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(runAutoHideAlerts, { timeout: 800 });
        } else {
            requestAnimationFrame(runAutoHideAlerts);
        }
    }, 5000);

    // Run after DOM is ready so server-rendered inline notifications become toasts.
    convertInlineAlertsToToasts();

    // Initialize dashboard charts if data and Chart.js are available
    if (window.DASHBOARD_CHART_DATA && typeof Chart !== 'undefined') {
        initializeDashboardCharts(window.DASHBOARD_CHART_DATA);
    }

    // Defer Bootstrap tooltip init to avoid blocking the ready handler
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        requestAnimationFrame(function() {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        });
    }

    // Contact form submission (modal)
    $('#contactForm').on('submit', function(e) {
        e.preventDefault();

        var form = this;
        if (!form.checkValidity()) {
            e.stopPropagation();
            $(form).addClass('was-validated');
            return;
        }

        var $btn = $('#contactSubmitBtn');
        showLoading($btn);

        $.ajax({
    	    url: '/api/contact/send_message',
            method: 'POST',
            data: $(form).serialize(),
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.showToast) {
                    showToast(resp.message || 'Message sent successfully.', 'success');
                }
                form.reset();
                $(form).removeClass('was-validated');
                var modalEl = document.getElementById('contactModal');
                if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var modal = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();
                }
            } else {
                if (window.showToast) {
                    showToast((resp && resp.message) || 'Failed to send your message.', 'danger');
                }
            }
        }).fail(function() {
            if (window.showToast) {
                showToast('Failed to send your message. Please try again later.', 'danger');
            }
        }).always(function() {
            hideLoading($btn);
        });
    });

    // --- Live Support Chat (logged-in users) ---
    var $chatToggle = $('#supportChatToggle');
    var $chatWindow = $('#supportChatWindow');
    var $chatMessages = $('#supportChatMessages');
    var $chatForm = $('#supportChatForm');
    var $chatInput = $('#supportChatMessageInput');
    var $chatSendBtn = $('#supportChatSendBtn');
    var $chatTypingIndicator = $('#supportChatTypingIndicator');
    var chatThreadId = null;
    var chatLastMessageId = null;
    var chatPollTimer = null;
    var chatTypingTimeout = null;
    var chatTypingState = false;
    var supportAvailabilityTimer = null;
    var lastSupportAvailable = null;
    var lastSupportNamesSignature = '';

    var chatLastDateKey = null;

    var $supportAvailabilityBadge = $('#supportAvailabilityBadge');
    var $supportAvailabilityStatus = $('#supportAvailabilityStatus');
    var $supportAvailabilityAgents = $('#supportAvailabilityAgents');
    var $supportChatAvailabilityLabel = $('#supportChatAvailabilityLabel');

    function renderSupportAvailability(resp, withToast) {
        if (!resp || !resp.success) {
            if ($supportAvailabilityStatus.length) {
                $supportAvailabilityStatus.text('Support status unavailable right now');
            }
            if ($supportAvailabilityAgents.length) {
                $supportAvailabilityAgents.text('Please try again in a moment.');
            }
            return;
        }

        var available = !!resp.available;
        var names = Array.isArray(resp.available_names) ? resp.available_names : [];
        var namesSignature = names.join('|');

        if ($supportAvailabilityBadge.length) {
            $supportAvailabilityBadge.removeClass('is-online is-offline').addClass(available ? 'is-online' : 'is-offline');
        }

        if ($supportAvailabilityStatus.length) {
            $supportAvailabilityStatus.text(available ? 'Support team is online now' : 'Support team currently offline');
        }

        if ($supportAvailabilityAgents.length) {
            if (available && names.length > 0) {
                $supportAvailabilityAgents.text('Available: ' + names.join(', '));
            } else {
                $supportAvailabilityAgents.text('Leave a message via contact form; we will respond as soon as possible.');
            }
        }

        if ($supportChatAvailabilityLabel.length) {
            if (available && names.length > 0) {
                $supportChatAvailabilityLabel.text('Online: ' + names.join(', '));
            } else {
                $supportChatAvailabilityLabel.text('No agent currently online; message will be queued.');
            }
        }

        if ($chatToggle.length) {
            $chatToggle.removeClass('is-online is-offline').addClass(available ? 'is-online' : 'is-offline');
            var title = available ? 'Live chat with support (online)' : 'Live chat with support (offline - messages still delivered)';
            $chatToggle.attr('title', title).attr('aria-label', title);
        }

        if (withToast && window.showToast) {
            if (lastSupportAvailable !== null && lastSupportAvailable !== available) {
                if (available) {
                    var label = names.length ? names.join(', ') : 'Support team';
                    showToast('Support is now available: ' + label, 'success');
                } else {
                    showToast('Support is currently offline. You can still leave a message.', 'warning');
                }
            } else if (available && lastSupportAvailable === true && lastSupportNamesSignature !== namesSignature && names.length > 0) {
                showToast('Available support team: ' + names.join(', '), 'info');
            }
        }

        lastSupportAvailable = available;
        lastSupportNamesSignature = namesSignature;
    }

    function fetchSupportAvailability(withToast) {
        $.ajax({
            url: '/api/chat/availability',
            method: 'GET',
            dataType: 'json',
            cache: false
        }).done(function(resp) {
            renderSupportAvailability(resp, !!withToast);
        });
    }

    function startSupportAvailabilityPolling() {
        if (supportAvailabilityTimer) return;
        supportAvailabilityTimer = setInterval(function() {
            fetchSupportAvailability(true);
        }, 10000);
    }

    function formatTimeFromString(str) {
        if (!str) return '';
        // Expecting format like "YYYY-MM-DD HH:MM:SS" from MySQL
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
        var todayKey = today.toISOString().slice(0, 10);
        var y = new Date();
        y.setDate(y.getDate() - 1);
        var yKey = y.toISOString().slice(0, 10);
        if (key === todayKey) return 'Today';
        if (key === yKey) return 'Yesterday';
        return key;
    }

    function appendChatMessages(msgs) {
        if (!Array.isArray(msgs) || !msgs.length) return;

        msgs.forEach(function(m) {
            chatLastMessageId = m.id;
            var isFromCurrentUser = false;
            if (typeof window.CURRENT_USER_ID === 'number' && m.sender_id) {
                isFromCurrentUser = Number(m.sender_id) === window.CURRENT_USER_ID;
            } else {
                // Fallback to sender_type when sender_id is missing
                isFromCurrentUser = (m.sender_type !== 'admin');
            }
            var cls = isFromCurrentUser ? 'support-chat-message-user' : 'support-chat-message-admin';
            var createdRaw = m.created_at ? m.created_at : '';
            var dateKey = getDateKey(createdRaw);
            if (dateKey && dateKey !== chatLastDateKey) {
                chatLastDateKey = dateKey;
                var label = getDateLabel(dateKey);
                if (label) {
                    var $div = $('<div/>', { 'class': 'support-chat-day-divider', text: label });
                    $chatMessages.append($div);
                }
            }
            var created = formatTimeFromString(createdRaw);
            var safeText = (m.message || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
            var $row = $('<div/>', { 'class': 'support-chat-message ' + cls });
            var $bubble = $('<div/>', { 'class': 'support-chat-bubble' });
            var $time = $('<span/>', { 'class': 'support-chat-timestamp', text: created });
            $bubble.text(safeText);
            if (created) {
                $row.append($bubble).append($time);
            } else {
                $row.append($bubble);
            }
            $chatMessages.append($row);
        });

        $chatMessages.scrollTop($chatMessages[0].scrollHeight);
    }

    function startChatPolling() {
        if (chatPollTimer) return;
        chatPollTimer = setInterval(function() {
            if (!chatThreadId) return;
            $.ajax({
                url: '/api/chat/poll',
                method: 'GET',
                dataType: 'json',
                data: {
                    thread_id: chatThreadId,
                    since_id: chatLastMessageId || ''
                }
            }).done(function(resp) {
                if (resp && resp.success) {
                    if (Array.isArray(resp.messages)) {
                        appendChatMessages(resp.messages);
                    }
                    if (resp.typing && typeof resp.typing === 'object' && $chatTypingIndicator.length) {
                        if (resp.typing.admin) {
                            $chatTypingIndicator.show();
                        } else {
                            $chatTypingIndicator.hide();
                        }
                    }
                }
            });
        }, 5000);
    }

    function stopChatPolling() {
        if (chatPollTimer) {
            clearInterval(chatPollTimer);
            chatPollTimer = null;
        }
    }

    function openSupportChat(onReady) {
        if (!$chatWindow.length) return;
        $chatWindow.show();

        if (!chatThreadId) {
            $.ajax({
                url: '/api/chat/start',
                method: 'GET',
                dataType: 'json'
            }).done(function(resp) {
                if (resp && resp.success && resp.thread) {
                    chatThreadId = resp.thread.id;
                    chatLastMessageId = null;
                    $chatMessages.empty();
                    if (Array.isArray(resp.messages)) {
                        appendChatMessages(resp.messages);
                    }
                    startChatPolling();
                    if (typeof onReady === 'function') {
                        onReady();
                    }
                } else {
                    if (window.showToast) {
                        showToast((resp && resp.message) || 'Unable to start chat.', 'danger');
                    }
                }
            }).fail(function() {
                if (window.showToast) {
                    showToast('Unable to start chat right now.', 'danger');
                }
            });
        } else {
            startChatPolling();
            if (typeof onReady === 'function') {
                onReady();
            }
        }
    }

    function closeSupportChat() {
        $chatWindow.hide();
        stopChatPolling();
    }

    function sendTyping(isTyping) {
        if (!chatThreadId) return;
        if (chatTypingState === isTyping) return;
        chatTypingState = isTyping;
        $.ajax({
            url: '/api/chat/typing',
            method: 'POST',
            dataType: 'json',
            data: {
                thread_id: chatThreadId,
                is_typing: isTyping ? 1 : 0
            }
        });
    }

    $chatToggle.on('click', function() {
        if ($chatWindow.is(':visible')) {
            closeSupportChat();
        } else {
            openSupportChat();
        }
    });

    $('#supportChatClose').on('click', function() {
        closeSupportChat();
    });

    $chatInput.on('input keydown', function() {
        if (!chatThreadId) return;
        sendTyping(true);
        if (chatTypingTimeout) {
            clearTimeout(chatTypingTimeout);
        }
        chatTypingTimeout = setTimeout(function() {
            sendTyping(false);
        }, 3000);
    });

    $chatForm.on('submit', function(e) {
        e.preventDefault();
        if (!chatThreadId) {
            openSupportChat();
            return;
        }
        var text = $chatInput.val().trim();
        if (!text) return;

        $chatSendBtn.prop('disabled', true);

        $.ajax({
            url: '/api/chat/send_message',
            method: 'POST',
            dataType: 'json',
            data: {
                thread_id: chatThreadId,
                message: text
            }
        }).done(function(resp) {
            if (resp && resp.success) {
                $chatInput.val('');
                if (resp.message_data) {
                    appendChatMessages([resp.message_data]);
                }
            } else if (window.showToast) {
                showToast((resp && resp.message) || 'Could not send message.', 'danger');
            }
        }).fail(function() {
            if (window.showToast) {
                showToast('Could not send message. Please try again.', 'danger');
            }
        }).always(function() {
            $chatSendBtn.prop('disabled', false);
        });
    });

    fetchSupportAvailability(false);
    startSupportAvailabilityPolling();
});

// Format currency
function formatCurrency(amount) {
    return 'Ksh ' + parseFloat(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Show loading spinner
function showLoading(element) {
    element.prop('disabled', true);
    element.data('original-text', element.html());
    element.html('<span class="spinner-border spinner-border-sm"></span> Loading...');
}

// Hide loading spinner
function hideLoading(element) {
    element.prop('disabled', false);
    element.html(element.data('original-text'));
}

// Initialize dashboard charts (billing trend + status breakdown)
function initializeDashboardCharts(data) {
    var labels = Array.isArray(data.labels) ? data.labels.slice() : [];
    var bills = Array.isArray(data.bills) ? data.bills.slice() : [];
    var payments = Array.isArray(data.payments) ? data.payments.slice() : [];
    var usage = Array.isArray(data.usage) ? data.usage.slice() : [];

    var ctxTrend = document.getElementById('billingTrendsChart');
    var ctxStatus = document.getElementById('statusPieChart');
    var ctxUsage = document.getElementById('usageChart');
    var ctxCollection = document.getElementById('collectionRateChart');

    var maxRange = labels.length;
    if (maxRange === 0 || !ctxTrend) {
        return;
    }

    var defaultRange = Math.min(6, Math.max(1, maxRange));

    function sliceRange(range) {
        var r;
        if (range === 'all') {
            r = maxRange;
        } else {
            r = parseInt(range, 10);
            if (!r || r <= 0 || r > maxRange) {
                r = maxRange;
            }
        }
        return {
            labels: labels.slice(-r),
            bills: bills.slice(-r),
            payments: payments.slice(-r)
        };
    }

    function computeCollectionRates(billArr, paymentArr) {
        var rates = [];
        for (var i = 0; i < billArr.length; i++) {
            var b = Number(billArr[i]) || 0;
            var p = Number(paymentArr[i]) || 0;

            if (b <= 0 && p <= 0) {
                rates.push(null);
            } else if (b <= 0) {
                rates.push(null);
            } else {
                var rate = (p / b) * 100;
                if (rate > 200) {
                    rate = 200;
                }
                rates.push(rate);
            }
        }
        return rates;
    }

    var initial = sliceRange(defaultRange);
    var initialRates = computeCollectionRates(initial.bills, initial.payments);

    var trendChart = new Chart(ctxTrend, {
        type: 'line',
        data: {
            labels: initial.labels,
            datasets: [
                {
                    label: 'Bills (Ksh)',
                    data: initial.bills,
                    borderColor: 'rgba(0, 123, 255, 0.9)',
                    backgroundColor: 'rgba(0, 123, 255, 0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    pointBackgroundColor: 'rgba(0, 123, 255, 1)'
                },
                {
                    label: 'Payments (Ksh)',
                    data: initial.payments,
                    borderColor: 'rgba(40, 167, 69, 0.9)',
                    backgroundColor: 'rgba(40, 167, 69, 0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    pointBackgroundColor: 'rgba(40, 167, 69, 1)'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var label = context.dataset.label || '';
                            var value = context.parsed.y || 0;
                            return label + ': Ksh ' + Number(value).toLocaleString('en-KE', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Ksh ' + Number(value).toLocaleString('en-KE', {
                                maximumFractionDigits: 0
                            });
                        }
                    }
                }
            }
        }
    });

    var collectionChart = null;

    if (ctxCollection && initial.labels.length && initialRates.some(function(v){ return v !== null && !isNaN(v); })) {
        collectionChart = new Chart(ctxCollection, {
            type: 'line',
            data: {
                labels: initial.labels,
                datasets: [{
                    label: 'Collection Rate (%)',
                    data: initialRates,
                    borderColor: 'rgba(255, 193, 7, 0.95)',
                    backgroundColor: 'rgba(255, 193, 7, 0.15)',
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: 3,
                    pointBackgroundColor: 'rgba(255, 193, 7, 1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var value = context.parsed.y || 0;
                                return 'Collection rate: ' + Number(value).toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 120,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    var rangeSelect = document.getElementById('dashboardRange');
    if (rangeSelect) {
        rangeSelect.addEventListener('change', function() {
            var sliced = sliceRange(this.value);
            trendChart.data.labels = sliced.labels;
            trendChart.data.datasets[0].data = sliced.bills;
            trendChart.data.datasets[1].data = sliced.payments;
            trendChart.update();

            if (collectionChart) {
                var newRates = computeCollectionRates(sliced.bills, sliced.payments);
                collectionChart.data.labels = sliced.labels;
                collectionChart.data.datasets[0].data = newRates;
                collectionChart.update();
            }
        });
    }

    // Export CSV for the currently selected range (labels, bills, payments, usage)
    var exportBtn = document.getElementById('exportDashboardCsvBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            var rangeValue = rangeSelect ? rangeSelect.value : 'all';
            var sliced = sliceRange(rangeValue);
            var rows = [];
            rows.push(['Month', 'Bills (Ksh)', 'Payments (Ksh)', 'Usage (units)']);

            var startIndex = labels.length - sliced.labels.length;
            for (var i = 0; i < sliced.labels.length; i++) {
                var label = sliced.labels[i];
                var billVal = sliced.bills[i] != null ? Number(sliced.bills[i]) : 0;
                var payVal = sliced.payments[i] != null ? Number(sliced.payments[i]) : 0;
                var usageVal = 0;
                if (usage && usage.length && (startIndex + i) >= 0 && (startIndex + i) < usage.length) {
                    usageVal = Number(usage[startIndex + i]) || 0;
                }
                rows.push([
                    label,
                    billVal.toFixed(2),
                    payVal.toFixed(2),
                    usageVal.toFixed(2)
                ]);
            }

            var csvContent = rows.map(function(row) {
                return row.map(function(cell) {
                    var str = String(cell);
                    if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1) {
                        str = '"' + str.replace(/"/g, '""') + '"';
                    }
                    return str;
                }).join(',');
            }).join('\r\n');

            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            var now = new Date();
            var datePart = now.toISOString().slice(0,10);
            link.download = 'dashboard-metrics-' + datePart + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        });
    }

    if (ctxStatus) {
        var paid = data.totals && data.totals.paid ? Number(data.totals.paid) : 0;
        var unpaid = data.totals && data.totals.unpaid ? Number(data.totals.unpaid) : 0;
        var total = paid + unpaid;
        if (total > 0) {
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Paid', 'Unpaid'],
                    datasets: [{
                        data: [paid, unpaid],
                        backgroundColor: ['#28a745', '#0d6efd'],
                        hoverBackgroundColor: ['#218838', '#0b5ed7']
                    }]
                },
                options: {
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    cutout: '60%'
                }
            });
        }
    }

    if (ctxUsage && usage.length && usage.some(function(v){ return Number(v) > 0; })) {
        new Chart(ctxUsage, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Estimated Usage (units)',
                    data: usage,
                    backgroundColor: 'rgba(13, 110, 253, 0.65)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1.5,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var v = context.parsed.y || 0;
                                return 'Usage: ' + Number(v).toLocaleString('en-KE', {
                                    maximumFractionDigits: 2
                                }) + ' units';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
}

// Shared admin UX helpers for SweetAlert-backed toasts and confirm actions.
(function() {
    function mapAlertType(type) {
        if (type === 'danger') return 'error';
        if (type === 'warning') return 'warning';
        if (type === 'info') return 'info';
        return 'success';
    }

    function showFlashToast(message, type) {
        if (!message) {
            return;
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: mapAlertType(type),
                title: message,
                showConfirmButton: false,
                timer: 3600,
                timerProgressBar: true
            });
            return;
        }

        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'success');
        }
    }

    function submitButtonAction(button) {
        var form = null;
        if (button) {
            var formId = button.getAttribute('form');
            form = formId ? document.getElementById(formId) : button.closest('form');
        }
        if (!form) {
            return;
        }

        if (button.name) {
            var existingTemp = form.querySelector('input[data-temp-action="1"]');
            if (existingTemp) {
                existingTemp.remove();
            }

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = button.name;
            hidden.value = button.value;
            hidden.setAttribute('data-temp-action', '1');
            form.appendChild(hidden);
        }

        form.submit();
    }

    function resolveConfirmMessage(button) {
        var explicitMessage = button.getAttribute('data-confirm-message');
        if (explicitMessage) {
            return explicitMessage;
        }

        var template = button.getAttribute('data-confirm-template');
        if (!template) {
            return 'Proceed with this action?';
        }

        var formId = button.getAttribute('form');
        var form = formId ? document.getElementById(formId) : button.closest('form');
        var statusField = form ? form.querySelector('select[name="status"]') : null;
        var statusLabel = statusField && statusField.options[statusField.selectedIndex]
            ? statusField.options[statusField.selectedIndex].text
            : 'the selected status';

        return template.replace('{status}', statusLabel);
    }

    function bindConfirmButtons(root) {
        var scope = root || document;
        var buttons = scope.querySelectorAll('button[data-confirm-message], button[data-confirm-template]');

        Array.prototype.forEach.call(buttons, function(button) {
            if (button.getAttribute('data-confirm-bound') === '1') {
                return;
            }

            button.setAttribute('data-confirm-bound', '1');
            button.addEventListener('click', function(event) {
                event.preventDefault();
                var message = resolveConfirmMessage(button);

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Please Confirm',
                        text: message,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, continue',
                        cancelButtonText: 'Cancel'
                    }).then(function(result) {
                        if (result.isConfirmed) {
                            submitButtonAction(button);
                        }
                    });
                    return;
                }

                if (window.confirm(message)) {
                    submitButtonAction(button);
                }
            });
        });
    }

    function densityStorageKey(button, targetSelector) {
        var key = button.getAttribute('data-density-key');
        if (key) {
            return 'wbs_density_' + key;
        }
        var path = (window.location && window.location.pathname) ? window.location.pathname : 'global';
        return 'wbs_density_' + path + '_' + targetSelector;
    }

    function shouldUseAutoCompact() {
        if (!window.matchMedia) {
            return false;
        }
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function applyDensityMode(target, mode) {
        var resolvedMode = mode;
        if (mode === 'auto') {
            resolvedMode = shouldUseAutoCompact() ? 'compact' : 'comfortable';
        }

        if (resolvedMode === 'compact') {
            target.classList.add('ui-density-compact');
        } else {
            target.classList.remove('ui-density-compact');
        }
    }

    function updateDensityButtonLabel(button, mode, isCompact) {
        var labelNode = button.querySelector('.js-density-label');
        if (!labelNode) {
            return;
        }

        var autoEnabled = button.getAttribute('data-density-auto-enabled') === '1';
        var autoText = button.getAttribute('data-density-auto-text') || 'Auto Mode';
        var compactText = button.getAttribute('data-density-compact-text') || 'Compact View';
        var comfyText = button.getAttribute('data-density-comfy-text') || 'Comfortable View';

        if (autoEnabled) {
            if (mode === 'auto') {
                labelNode.textContent = autoText;
            } else if (mode === 'compact') {
                labelNode.textContent = compactText;
            } else {
                labelNode.textContent = comfyText;
            }
            return;
        }

        labelNode.textContent = isCompact ? comfyText : compactText;
    }

    function applyButtonDensityState(button, target, mode) {
        button.setAttribute('data-density-current-mode', mode);
        applyDensityMode(target, mode);
        updateDensityButtonLabel(button, mode, target.classList.contains('ui-density-compact'));
    }

    function bindDensityToggles(root) {
        var scope = root || document;
        var buttons = scope.querySelectorAll('[data-density-toggle]');

        Array.prototype.forEach.call(buttons, function(button) {
            if (button.getAttribute('data-density-bound') === '1') {
                return;
            }

            var targetSelector = button.getAttribute('data-density-target');
            if (!targetSelector) {
                return;
            }
            var target = document.querySelector(targetSelector);
            if (!target) {
                return;
            }

            button.setAttribute('data-density-bound', '1');
            var key = densityStorageKey(button, targetSelector);
            var autoEnabled = button.getAttribute('data-density-auto-enabled') === '1';
            var defaultMode = button.getAttribute('data-density-default') || (autoEnabled ? 'auto' : 'comfortable');
            var saved = '';
            try {
                saved = window.localStorage ? (localStorage.getItem(key) || '') : '';
            } catch (e) {
                saved = '';
            }

            var mode = saved;
            if (mode === '') {
                mode = defaultMode;
            }
            if (!autoEnabled && mode !== 'compact' && mode !== 'comfortable') {
                mode = 'comfortable';
            }
            if (autoEnabled && mode !== 'compact' && mode !== 'comfortable' && mode !== 'auto') {
                mode = 'auto';
            }

            applyButtonDensityState(button, target, mode);

            button.addEventListener('click', function() {
                var currentMode = button.getAttribute('data-density-current-mode') || (autoEnabled ? 'auto' : 'comfortable');
                var nextMode;

                if (autoEnabled) {
                    if (currentMode === 'auto') {
                        nextMode = 'compact';
                    } else if (currentMode === 'compact') {
                        nextMode = 'comfortable';
                    } else {
                        nextMode = 'auto';
                    }
                } else {
                    nextMode = currentMode === 'compact' ? 'comfortable' : 'compact';
                }

                applyButtonDensityState(button, target, nextMode);
                try {
                    if (window.localStorage) {
                        localStorage.setItem(key, nextMode);
                    }
                } catch (e) {
                    // Ignore storage errors.
                }
            });
        });
    }

    function refreshAutoDensityToggles() {
        var autoButtons = document.querySelectorAll('[data-density-toggle][data-density-auto-enabled="1"]');
        Array.prototype.forEach.call(autoButtons, function(button) {
            var mode = button.getAttribute('data-density-current-mode');
            if (mode !== 'auto') {
                return;
            }
            var targetSelector = button.getAttribute('data-density-target');
            if (!targetSelector) {
                return;
            }
            var target = document.querySelector(targetSelector);
            if (!target) {
                return;
            }
            applyButtonDensityState(button, target, 'auto');
        });
    }

    window.WbsAdminUi = {
        mapAlertType: mapAlertType,
        showFlashToast: showFlashToast,
        submitButtonAction: submitButtonAction,
        bindDensityToggles: bindDensityToggles,
        bindConfirmButtons: bindConfirmButtons,
        init: function(options) {
            var config = options || {};
            bindConfirmButtons(config.root || document);
            bindDensityToggles(config.root || document);
            showFlashToast(config.flashMessage || '', config.flashType || 'success');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            bindDensityToggles(document);
            refreshAutoDensityToggles();
        });
    } else {
        bindDensityToggles(document);
        refreshAutoDensityToggles();
    }

    if (window.matchMedia) {
        var mq = window.matchMedia('(max-width: 991.98px)');
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', refreshAutoDensityToggles);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(refreshAutoDensityToggles);
        }
    }
})();
