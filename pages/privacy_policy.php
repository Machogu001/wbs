<?php

$page_title = 'My Water Bill Privacy Policy';
include __DIR__ . '/../templates/header.php';
?>

<style>
body {
    background: #f5f7fb;
}

.privacy-policy-shell {
    padding: 40px 16px;
}

.privacy-policy-card {
    max-width: 820px;
    margin: 0 auto;
    padding: 32px;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
    color: #172b4d;
    font: 16px/1.6 Arial, sans-serif;
}

.privacy-policy-card h1,
.privacy-policy-card h2 {
    color: #073b8f;
}

.privacy-policy-card a {
    color: #0969da;
}

.privacy-policy-card ul {
    padding-left: 1.25rem;
}
</style>

<section class="privacy-policy-shell">
    <div class="privacy-policy-card">
        <h1>My Water Bill Privacy Policy</h1>
        <p><strong>Effective date:</strong> 29 September 2026</p>
        <p>
            BreMac provides My Water Bill to help authorized customers and staff access water billing
            services. This policy explains how information is handled when you use the app.
        </p>

        <h2>Information we process</h2>
        <ul>
            <li>Account identifiers and authentication information used to sign in.</li>
            <li>Profile and contact information, including name, phone number, email, address, and tax PIN.</li>
            <li>Water account information, including account numbers, meters, readings, bills, and statements.</li>
            <li>Payment information, including amounts, payment status, phone number, method, and receipt references.</li>
            <li>Meter photographs and reading details submitted through the app.</li>
            <li>Support complaints and related status information.</li>
            <li>Device model information used to identify authenticated sessions.</li>
        </ul>

        <h2>How we use information</h2>
        <p>
            Information is used to authenticate users, provide billing and payment services, process
            meter readings, deliver account notifications, support customers, prevent unauthorized
            access, and perform authorized staff operations.
        </p>

        <h2>Sharing and service providers</h2>
        <p>
            Information may be processed by service providers needed to operate the service, including
            payment, SMS, email, and hosting providers. We do not sell personal information.
        </p>

        <h2>Security and retention</h2>
        <p>
            Data is transmitted using encrypted HTTPS connections. Access is restricted according to
            authenticated account roles and permissions. Information is retained as required to provide
            billing services, maintain financial records, resolve disputes, and meet legal obligations.
        </p>

        <h2>Your choices</h2>
        <p>
            You may update available profile information in the app. To request correction, access, or
            deletion of eligible personal information, contact us. Financial and billing records may
            need to be retained where required by law or legitimate business obligations.
        </p>

        <h2>Children</h2>
        <p>My Water Bill is not directed to children and is intended for authorized water-service customers and staff.</p>

        <h2>Contact</h2>
        <p>
            For privacy questions or requests, email
            <a href="mailto:admin@bremac.co.ke">admin@bremac.co.ke</a>.
        </p>
    </div>
</section>

<?php include __DIR__ . '/../templates/footer.php'; ?>