<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || !$auth->hasPermission('manage_settings')) {
    header('Location: /login');
    exit;
}

$settingsService = new BillingSettings($db);
$userService = new User($db);
$settings = $settingsService->getSettings();
$sampleTemplates = BillingSettings::getTermsTemplateSamples();

$verificationWindowSeconds = 900;
$verifiedAt = (int)($_SESSION['terms_editor_verified_at'] ?? 0);
$isVerified = $verifiedAt > 0 && (time() - $verifiedAt) <= $verificationWindowSeconds;

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'verify_terms_editor') {
        $password = (string)($_POST['editor_password'] ?? '');
        $currentUser = $userService->getById((int)($auth->getUserId() ?? 0));
        if (!$currentUser || empty($currentUser['password_hash']) || !password_verify($password, $currentUser['password_hash'])) {
            $message = 'Password is incorrect. Terms editor was not opened.';
            $messageType = 'danger';
        } else {
            $_SESSION['terms_editor_verified_at'] = time();
            header('Location: /admin/terms-conditions');
            exit;
        }
    }

    if ($action === 'lock_terms_editor') {
        unset($_SESSION['terms_editor_verified_at']);
        $_SESSION['flash_message'] = 'Terms editor locked.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /settings');
        exit;
    }

    if ($action === 'save_terms_conditions') {
        if (!$isVerified) {
            $message = 'Password confirmation expired. Enter your password again to continue.';
            $messageType = 'danger';
        } else {
            $termsContent = trim((string)($_POST['terms_conditions_content'] ?? ''));
            if ($settingsService->updateTermsContent($termsContent)) {
                try {
                    $logger = new ActivityLog($db);
                    $logger->log(
                        $_SESSION['user_id'] ?? null,
                        'update_terms_conditions',
                        'billing_settings',
                        1,
                        'Updated terms and conditions content',
                        ['content_length' => strlen($termsContent)]
                    );
                } catch (Throwable $e) {
                    // Keep save successful even if activity log storage fails.
                }

                $_SESSION['flash_message'] = 'Terms & Conditions updated successfully.';
                $_SESSION['flash_type'] = 'success';
                header('Location: /admin/terms-conditions');
                exit;
            }

            $message = 'Failed to save Terms & Conditions.';
            $messageType = 'danger';
        }
    }
}

$settings = $settingsService->getSettings();

if (isset($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    $messageType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$renderedTerms = BillingSettings::renderTermsContent($settings, 'https://wbs.bremac.co.ke/');
$page_title = 'Terms & Conditions';
$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell terms-editor-page">
    <div class="row">
        <div class="col-12">
            <div class="pb-banner pb-banner--teal mb-4">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-card-text pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-shield-lock"></i> Protected Content Editor</span>
                        </div>
                        <h2 class="pb-title">Terms &amp; Conditions</h2>
                        <p class="pb-subtitle">Edit customer-facing terms in a dedicated workspace with password confirmation and live preview.</p>
                    </div>
                    <div class="pb-right">
                        <div class="pb-btn-row">
                            <a href="/settings" class="pb-btn"><i class="bi bi-arrow-left"></i> Back to Settings</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <style>
    .terms-workspace {
        position: relative;
    }

    .terms-workspace.is-focus-mode {
        position: fixed;
        inset: 1rem;
        z-index: 1085;
        background: #f5f7fb;
        border-radius: 1rem;
        padding: 1rem;
        box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.2);
        overflow: auto;
    }

    body.terms-focus-open {
        overflow: hidden;
    }

    .terms-editor-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }

    .terms-editor-surface,
    .terms-editor-preview {
        min-height: 34rem;
        max-height: 65vh;
        overflow: auto;
        line-height: 1.6;
        background: #fff;
    }

    .terms-editor-preview {
        background: #f8f9fa;
    }

    .terms-editor-surface:focus {
        outline: 0;
        box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.35);
        border-color: rgba(13, 110, 253, 0.35);
    }

    .terms-workspace.is-editor-focus .terms-preview-column,
    .terms-workspace.is-preview-focus .terms-editor-column {
        display: none;
    }

    .terms-workspace.is-editor-focus .terms-editor-column,
    .terms-workspace.is-preview-focus .terms-preview-column {
        width: 100%;
    }

    .terms-placeholder-list,
    .terms-sample-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .terms-side-card {
        position: sticky;
        top: 1rem;
    }
    </style>

    <?php if (!$isVerified): ?>
        <div class="row justify-content-center">
            <div class="col-xl-5 col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h4 class="mb-2">Confirm your password</h4>
                        <p class="text-muted mb-4">To avoid accidental edits, the terms editor only opens after you confirm your password.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="verify_terms_editor">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label class="form-label">Current password</label>
                                <input type="password" name="editor_password" class="form-control" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-unlock me-1"></i>Open Terms Editor</button>
                                <a href="/settings" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-xl-9">
                <form method="POST" id="termsEditorForm">
                    <input type="hidden" name="action" value="save_terms_conditions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="card shadow-sm border-0 terms-workspace" id="termsWorkspace">
                        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h5 class="mb-1">Terms Editor Workspace</h5>
                                <div class="text-muted small">Protected for 15 minutes after password confirmation.</div>
                            </div>
                            <div class="terms-sample-actions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEditorFocus"><i class="bi bi-arrows-fullscreen"></i> Editor Focus</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPreviewFocus"><i class="bi bi-layout-text-sidebar-reverse"></i> Preview Focus</button>
                                <button type="button" class="btn btn-sm btn-outline-dark d-none" id="btnExitFocus"><i class="bi bi-x-lg"></i> Exit Focus</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 mb-3">
                                <div class="col-lg-7">
                                    <label class="form-label">Sample terms template</label>
                                    <select id="termsSamplePicker" class="form-select">
                                        <option value="">Choose a sample template...</option>
                                        <?php foreach ($sampleTemplates as $sampleKey => $sample): ?>
                                            <option value="<?php echo htmlspecialchars($sampleKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sample['label'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($sample['description'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-5 d-flex align-items-end">
                                    <div class="terms-sample-actions w-100 justify-content-lg-end">
                                        <button type="button" class="btn btn-outline-primary" id="btnLoadSample"><i class="bi bi-journal-text me-1"></i>Load Sample</button>
                                        <button type="button" class="btn btn-outline-warning" id="btnResetDefault"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore Default</button>
                                    </div>
                                </div>
                            </div>

                            <div class="terms-editor-toolbar">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary js-terms-command" data-command="bold"><i class="bi bi-type-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary js-terms-command" data-command="italic"><i class="bi bi-type-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary js-terms-command" data-command="underline"><i class="bi bi-type-underline"></i></button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary js-terms-block" data-block="P">Paragraph</button>
                                    <button type="button" class="btn btn-outline-secondary js-terms-block" data-block="H6">Section Title</button>
                                    <button type="button" class="btn btn-outline-secondary js-terms-command" data-command="insertUnorderedList">Bullets</button>
                                    <button type="button" class="btn btn-outline-secondary js-terms-command" data-command="insertOrderedList">Numbers</button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary" id="btnAddLink"><i class="bi bi-link-45deg"></i> Link</button>
                                    <button type="button" class="btn btn-outline-secondary" id="btnAddLine"><i class="bi bi-hr"></i> Divider</button>
                                </div>
                            </div>

                            <div class="terms-placeholder-list mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{company_name}}">Company Name</button>
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{portal_url}}">Portal URL</button>
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{currency_code}}">Currency Code</button>
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{registration_fee}}">Registration Fee</button>
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{support_phone}}">Support Phone</button>
                                <button type="button" class="btn btn-sm btn-outline-primary js-terms-placeholder" data-placeholder="{{support_email}}">Support Email</button>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-6 terms-editor-column">
                                    <label class="form-label">Edit content</label>
                                    <div id="termsEditor" class="form-control terms-editor-surface" contenteditable="true"><?php echo (string)($settings['terms_conditions_content'] ?? BillingSettings::getDefaultTermsTemplate()); ?></div>
                                    <textarea name="terms_conditions_content" id="termsTextarea" class="d-none"><?php echo htmlspecialchars((string)($settings['terms_conditions_content'] ?? BillingSettings::getDefaultTermsTemplate()), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <div class="form-text mt-2">Write normally. Formatting, links, sections, and placeholders are kept when saved.</div>
                                </div>
                                <div class="col-lg-6 terms-preview-column">
                                    <label class="form-label">Live preview</label>
                                    <div id="termsPreview" class="form-control terms-editor-preview"><?php echo $renderedTerms; ?></div>
                                    <div class="form-text mt-2">Preview shows real values from current settings for company name, fee, currency, and support contacts.</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="text-muted small">Changes affect the public terms modal immediately after saving.</div>
                            <div class="d-flex gap-2">
                                <a href="/settings" class="btn btn-outline-secondary">Back</a>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Terms</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-xl-3">
                <div class="card shadow-sm border-0 terms-side-card">
                    <div class="card-body">
                        <h5 class="mb-3">Safety</h5>
                        <p class="text-muted small mb-3">This editor is separated from general settings to reduce accidental edits. It also asks for your password before opening.</p>
                        <form method="POST" class="d-grid gap-2">
                            <input type="hidden" name="action" value="lock_terms_editor">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-lock me-1"></i>Lock Editor</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const termsEditor = document.getElementById('termsEditor');
            const termsTextarea = document.getElementById('termsTextarea');
            const termsPreview = document.getElementById('termsPreview');
            const termsWorkspace = document.getElementById('termsWorkspace');
            const btnAddLink = document.getElementById('btnAddLink');
            const btnAddLine = document.getElementById('btnAddLine');
            const btnLoadSample = document.getElementById('btnLoadSample');
            const btnResetDefault = document.getElementById('btnResetDefault');
            const btnEditorFocus = document.getElementById('btnEditorFocus');
            const btnPreviewFocus = document.getElementById('btnPreviewFocus');
            const btnExitFocus = document.getElementById('btnExitFocus');
            const termsSamplePicker = document.getElementById('termsSamplePicker');
            const defaultTermsTemplate = <?php echo json_encode(BillingSettings::getDefaultTermsTemplate()); ?>;
            const sampleTemplates = <?php echo json_encode($sampleTemplates); ?>;
            const replacementMap = <?php echo json_encode([
                '{{company_name}}' => trim((string)($settings['company_name'] ?? 'BreMac Consultant Ltd')),
                '{{portal_url}}' => 'https://wbs.bremac.co.ke/',
                '{{currency_code}}' => trim((string)($settings['currency_code'] ?? 'KES')),
                '{{registration_fee}}' => number_format((float)($settings['registration_fee'] ?? 0), 2),
                '{{support_phone}}' => trim((string)($settings['support_phone'] ?? '254724400202')),
                '{{support_email}}' => trim((string)($settings['support_email'] ?? 'support@waterbilling.com')),
            ]); ?>;

            function replacePlaceholders(html) {
                let output = String(html || '');
                Object.keys(replacementMap).forEach(function(key) {
                    output = output.split(key).join(replacementMap[key]);
                });
                return output;
            }

            function syncTerms() {
                const html = String(termsEditor.innerHTML || '').trim();
                termsTextarea.value = html;
                termsPreview.innerHTML = replacePlaceholders(html);
            }

            function focusEditor() {
                termsEditor.focus();
            }

            function setFocusMode(mode) {
                document.body.classList.toggle('terms-focus-open', mode !== '');
                termsWorkspace.classList.toggle('is-focus-mode', mode !== '');
                termsWorkspace.classList.toggle('is-editor-focus', mode === 'editor');
                termsWorkspace.classList.toggle('is-preview-focus', mode === 'preview');
                btnExitFocus.classList.toggle('d-none', mode === '');
            }

            function insertHtmlAtCursor(html) {
                focusEditor();
                if (document.queryCommandSupported && document.queryCommandSupported('insertHTML')) {
                    document.execCommand('insertHTML', false, html);
                    return;
                }

                const selection = window.getSelection();
                if (!selection || !selection.rangeCount) {
                    termsEditor.innerHTML += html;
                    return;
                }

                const range = selection.getRangeAt(0);
                range.deleteContents();
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                const fragment = document.createDocumentFragment();
                let child = wrapper.firstChild;
                let lastNode = null;
                while (child) {
                    lastNode = fragment.appendChild(child);
                    child = wrapper.firstChild;
                }
                range.insertNode(fragment);
                if (lastNode) {
                    range.setStartAfter(lastNode);
                    range.collapse(true);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            }

            syncTerms();

            termsEditor.addEventListener('input', syncTerms);
            termsEditor.addEventListener('blur', syncTerms);

            document.querySelectorAll('.js-terms-command').forEach(function(button) {
                button.addEventListener('click', function() {
                    focusEditor();
                    document.execCommand(this.getAttribute('data-command'), false, null);
                    syncTerms();
                });
            });

            document.querySelectorAll('.js-terms-block').forEach(function(button) {
                button.addEventListener('click', function() {
                    focusEditor();
                    document.execCommand('formatBlock', false, this.getAttribute('data-block'));
                    syncTerms();
                });
            });

            document.querySelectorAll('.js-terms-placeholder').forEach(function(button) {
                button.addEventListener('click', function() {
                    insertHtmlAtCursor(this.getAttribute('data-placeholder'));
                    syncTerms();
                });
            });

            btnAddLink.addEventListener('click', function() {
                const url = window.prompt('Enter the link URL', 'https://');
                if (!url) {
                    return;
                }
                const text = window.prompt('Enter the link text', url) || url;
                insertHtmlAtCursor('<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</a>');
                syncTerms();
            });

            btnAddLine.addEventListener('click', function() {
                insertHtmlAtCursor('<hr>');
                syncTerms();
            });

            btnResetDefault.addEventListener('click', function() {
                if (!window.confirm('Replace the current text with the default terms template?')) {
                    return;
                }
                termsEditor.innerHTML = defaultTermsTemplate;
                syncTerms();
            });

            btnLoadSample.addEventListener('click', function() {
                const key = String(termsSamplePicker.value || '');
                if (!key || !sampleTemplates[key]) {
                    window.alert('Choose a sample template first.');
                    return;
                }
                if (!window.confirm('Load the selected sample into the editor? This replaces the current unsaved text.')) {
                    return;
                }
                termsEditor.innerHTML = String(sampleTemplates[key].content || '');
                syncTerms();
            });

            btnEditorFocus.addEventListener('click', function() {
                setFocusMode('editor');
            });

            btnPreviewFocus.addEventListener('click', function() {
                setFocusMode('preview');
            });

            btnExitFocus.addEventListener('click', function() {
                setFocusMode('');
            });

            document.getElementById('termsEditorForm').addEventListener('submit', syncTerms);
        })();
        </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>