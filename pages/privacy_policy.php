<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/BillingSettings.php';

$companyName = 'Water Billing System';
$supportPhone = '+25472400202';
$supportEmail = 'support@bremac.co.ke';
$effectiveDate = '2026-09-29';

try {
    $db = (new Database())->getConnection();
    if ($db) {
        $settings = (new BillingSettings($db))->getSettings();
        if (!empty($settings['company_name'])) {
            $companyName = (string)$settings['company_name'];
        }
        if (!empty($settings['support_phone'])) {
            $supportPhone = (string)$settings['support_phone'];
        }
        if (!empty($settings['support_email'])) {
            $supportEmail = (string)$settings['support_email'];
        }
    }
} catch (Throwable $e) {
    // Fall back to defaults when settings are unavailable.
}

$page_title = 'Privacy Policy';
include __DIR__ . '/../templates/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            <div class="pb-banner pb-banner--cobalt mb-4">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-shield-lock pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-shield-check"></i> Public Policy</span>
                        </div>
                        <h1 class="pb-title mb-2">Privacy Policy</h1>
                        <p class="pb-subtitle mb-0">How <?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?> collects, uses, stores, and protects information in the Water Billing System.</p>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <p class="text-muted mb-4">Effective date: <?php echo htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8'); ?></p>

                    <h3>1. Information We Collect</h3>
                    <p>We may collect customer, staff, and payment-related information needed to provide water billing and support services. This may include account numbers, names, phone numbers, email addresses, physical addresses, meter numbers, billing records, payment references, and customer support communications.</p>

                    <h3>2. How We Use Information</h3>
                    <p>Information is used to register accounts, manage meters, issue bills, receive and reconcile payments, deliver receipts and notifications, support customer service, detect fraud or misuse, and maintain operational and financial records for the water service.</p>

                    <h3>3. Payments And Transaction Data</h3>
                    <p>When payments are processed through integrated services such as M-Pesa, the system stores transaction details required for confirmation, reconciliation, receipts, and audit history. Sensitive payment credentials are not intentionally displayed publicly through this website.</p>

                    <h3>4. Meter Readings And Uploaded Files</h3>
                    <p>If users submit meter readings, photographs, or related supporting documents, those files may be stored and reviewed for billing, verification, dispute resolution, and service administration.</p>

                    <h3>5. Communications</h3>
                    <p>The system may send SMS messages, emails, payment prompts, reminders, support responses, verification codes, and operational notices using the contact details provided by the user or maintained by the service operator.</p>

                    <h3>6. Who Can Access Information</h3>
                    <p>Access to personal and billing data is restricted to authenticated users, authorized staff, administrators, service providers involved in communication or payment processing, and other parties where disclosure is required for lawful operational purposes.</p>

                    <h3>7. Data Security</h3>
                    <p>Reasonable technical and administrative safeguards are used to protect account data, billing records, and system access. These safeguards may include authentication controls, role-based permissions, audit logs, and restricted administrative access.</p>

                    <h3>8. Data Retention</h3>
                    <p>Records may be retained for as long as necessary to operate the service, resolve disputes, enforce terms, meet reporting obligations, maintain audit trails, and comply with legal or regulatory requirements.</p>

                    <h3>9. Sharing And Disclosure</h3>
                    <p>Information may be shared with payment processors, messaging providers, hosting or technical support providers, auditors, and regulatory or lawful authorities when necessary to operate the system, provide requested services, investigate incidents, or comply with legal obligations.</p>

                    <h3>10. User Responsibilities</h3>
                    <p>Users should provide accurate account information, keep their login credentials private, protect their devices, and promptly report suspected unauthorized access or incorrect account activity.</p>

                    <h3>11. Policy Updates</h3>
                    <p>This privacy policy may be updated from time to time to reflect operational, legal, or technical changes. The latest published version on this page will apply from the effective date shown above.</p>

                    <h3>12. Contact</h3>
                    <p>If you have questions about this privacy policy or your information in the system, contact the support team using the details below.</p>

                    <div class="mt-4 p-3 rounded" style="background:#f8fafc; border:1px solid #dbeafe;">
                        <div><strong>Service Operator:</strong> <?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8'); ?></a></div>
                        <div><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?></a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>