// Main JavaScript file for Water Billing System

$(document).ready(function() {
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
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert:not(.alert-permanent)').fadeOut('slow');
    }, 5000);

    // Initialize dashboard charts if data and Chart.js are available
    if (window.DASHBOARD_CHART_DATA && typeof Chart !== 'undefined') {
        initializeDashboardCharts(window.DASHBOARD_CHART_DATA);
    }

    // Enable Bootstrap 5 tooltips globally where data-bs-toggle="tooltip" is used
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            new bootstrap.Tooltip(tooltipTriggerEl);
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

    var chatLastDateKey = null;

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

    function openSupportChat() {
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
