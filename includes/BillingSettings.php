<?php
class BillingSettings {
    private $conn;
    private $table = "billing_settings";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
    }

    public function getSettings() {
        $query = "SELECT rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, locale_code, timezone_name, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee, enforce_location_accuracy, terms_conditions_content, bill_notification_template, mobile_api_key, mobile_api_key_required, updated_at FROM " . $this->table . " WHERE id = 1 LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $this->createDefault();
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Ensure we always have sensible defaults
        if (!isset($settings['company_name']) || $settings['company_name'] === null || $settings['company_name'] === '') {
            $settings['company_name'] = 'BreMac Consultant Ltd';
        }
        if (!isset($settings['support_phone']) || $settings['support_phone'] === null || $settings['support_phone'] === '') {
            $settings['support_phone'] = '254724400202';
        }
        if (!isset($settings['support_email']) || $settings['support_email'] === null || $settings['support_email'] === '') {
            $settings['support_email'] = 'support@waterbilling.com';
        }
        if (!isset($settings['currency_code']) || $settings['currency_code'] === null || $settings['currency_code'] === '') {
            $settings['currency_code'] = 'KES';
        }
        if (!isset($settings['locale_code']) || $settings['locale_code'] === null || $settings['locale_code'] === '') {
            $settings['locale_code'] = 'en-KE';
        }
        if (!isset($settings['timezone_name']) || $settings['timezone_name'] === null || $settings['timezone_name'] === '') {
            $settings['timezone_name'] = 'Africa/Nairobi';
        }
        if (!isset($settings['financial_year_start_month']) || (int)$settings['financial_year_start_month'] < 1 || (int)$settings['financial_year_start_month'] > 12) {
            $settings['financial_year_start_month'] = 1; // January
        }
        // Ensure VAT/taxation defaults
        if (!isset($settings['vat_rate']) || $settings['vat_rate'] === null || $settings['vat_rate'] === '') {
            $settings['vat_rate'] = 0.0;
        }
        $settings['vat_rate'] = (float)$settings['vat_rate'];
        if (!isset($settings['etims_taxation_type_code']) || $settings['etims_taxation_type_code'] === null) {
            $settings['etims_taxation_type_code'] = '';
        } else {
            $settings['etims_taxation_type_code'] = strtoupper(trim((string)$settings['etims_taxation_type_code']));
        }
        // Ensure registration fee default
        if (!isset($settings['registration_fee']) || $settings['registration_fee'] === null || $settings['registration_fee'] === '') {
            $settings['registration_fee'] = 0.00;
        }
        $settings['registration_fee'] = (float)$settings['registration_fee'];
        // Ensure enforce_location_accuracy default
        if (!isset($settings['enforce_location_accuracy'])) {
            $settings['enforce_location_accuracy'] = 0;
        }
        $settings['enforce_location_accuracy'] = (int)$settings['enforce_location_accuracy'];
        if (!isset($settings['terms_conditions_content']) || trim((string)$settings['terms_conditions_content']) === '') {
            $settings['terms_conditions_content'] = self::getDefaultTermsTemplate();
        }
        if (!isset($settings['bill_notification_template']) || trim((string)$settings['bill_notification_template']) === '') {
            $settings['bill_notification_template'] = self::getDefaultBillNotificationTemplate();
        }
        if (!isset($settings['mobile_api_key']) || $settings['mobile_api_key'] === null) {
            $settings['mobile_api_key'] = '';
        }
        if (!isset($settings['mobile_api_key_required'])) {
            $settings['mobile_api_key_required'] = 1;
        }
        $settings['mobile_api_key_required'] = (int)$settings['mobile_api_key_required'];

        return $settings;
    }

    public function updateMobileApiKey(string $mobileApiKey): bool
    {
        $query = "UPDATE " . $this->table . "
                  SET mobile_api_key = :mobile_api_key,
                      updated_at = NOW()
                  WHERE id = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':mobile_api_key', trim($mobileApiKey));

        if ($stmt->execute() && $stmt->rowCount() > 0) {
            return true;
        }

        $this->createDefault();
        return $stmt->execute();
    }

    public function updateSettings($rate_per_unit, $service_charge, $company_pin = null, $etims_integration_url = null, $etims_api_key = null, $company_name = null, $support_phone = null, $support_email = null, $currency_code = null, $financial_year_start_month = null, $vat_rate = null, $etims_taxation_type_code = null, $registration_fee = null, $locale_code = null, $timezone_name = null, $enforce_location_accuracy = null, $terms_conditions_content = null, $mobile_api_key_required = null, $bill_notification_template = null) {
        $query = "UPDATE " . $this->table . " 
                  SET rate_per_unit = :rate_per_unit,
                      service_charge = :service_charge,
                      company_pin = :company_pin,
                      etims_integration_url = :etims_integration_url,
                      etims_api_key = :etims_api_key,
                      company_name = :company_name,
                      support_phone = :support_phone,
                      support_email = :support_email,
                      currency_code = :currency_code,
                      locale_code = :locale_code,
                      timezone_name = :timezone_name,
                      financial_year_start_month = :financial_year_start_month,
                      vat_rate = :vat_rate,
                      etims_taxation_type_code = :etims_taxation_type_code,
                      registration_fee = :registration_fee,
                      terms_conditions_content = :terms_conditions_content,
                      bill_notification_template = :bill_notification_template,
                      mobile_api_key_required = :mobile_api_key_required,
                      enforce_location_accuracy = :enforce_location_accuracy,
                      updated_at = NOW()
                  WHERE id = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":rate_per_unit", $rate_per_unit);
        $stmt->bindParam(":service_charge", $service_charge);
        $stmt->bindParam(":company_pin", $company_pin);
        $stmt->bindParam(":etims_integration_url", $etims_integration_url);
        $stmt->bindParam(":etims_api_key", $etims_api_key);
        $company_name = $company_name !== null && $company_name !== '' ? $company_name : 'BreMac Consultant Ltd';
        $stmt->bindParam(":company_name", $company_name);
        $support_phone = $support_phone !== null && $support_phone !== '' ? $support_phone : '254724400202';
        $support_email = $support_email !== null && $support_email !== '' ? $support_email : 'support@waterbilling.com';
        $currency_code = $currency_code !== null && $currency_code !== '' ? strtoupper($currency_code) : 'KES';
        $locale_code = $locale_code !== null && $locale_code !== '' ? trim((string)$locale_code) : 'en-KE';
        $timezone_name = $timezone_name !== null && $timezone_name !== '' ? trim((string)$timezone_name) : 'Africa/Nairobi';
        $financial_year_start_month = (int)($financial_year_start_month ?? 1);
        if ($financial_year_start_month < 1 || $financial_year_start_month > 12) {
            $financial_year_start_month = 1;
        }
        $vat_rate = $vat_rate !== null && $vat_rate !== '' ? (float)$vat_rate : 0.0;
        if ($vat_rate < 0) {
            $vat_rate = 0.0;
        }
        $etims_taxation_type_code = $etims_taxation_type_code !== null ? strtoupper(trim((string)$etims_taxation_type_code)) : '';
        $registration_fee = $registration_fee !== null && $registration_fee !== '' ? (float)$registration_fee : 0.00;
        $stmt->bindParam(":support_phone", $support_phone);
        $stmt->bindParam(":support_email", $support_email);
        $stmt->bindParam(":currency_code", $currency_code);
        $stmt->bindParam(":locale_code", $locale_code);
        $stmt->bindParam(":timezone_name", $timezone_name);
        $stmt->bindParam(":financial_year_start_month", $financial_year_start_month, PDO::PARAM_INT);
        $stmt->bindParam(":vat_rate", $vat_rate);
        $stmt->bindParam(":etims_taxation_type_code", $etims_taxation_type_code);
        $stmt->bindParam(":registration_fee", $registration_fee);
        $termsConditionsContent = $terms_conditions_content === null
            ? $this->getStoredTermsContent()
            : $this->normalizeTermsContent($terms_conditions_content);
        $stmt->bindParam(":terms_conditions_content", $termsConditionsContent);
        $billNotificationTemplate = $bill_notification_template === null
            ? $this->getStoredBillNotificationTemplate()
            : $this->normalizeBillNotificationTemplate($bill_notification_template);
        $stmt->bindParam(":bill_notification_template", $billNotificationTemplate);
        $mobile_api_key_required = ($mobile_api_key_required !== null) ? (int)$mobile_api_key_required : 1;
        $stmt->bindParam(":mobile_api_key_required", $mobile_api_key_required, PDO::PARAM_INT);
        $enforce_location_accuracy = ($enforce_location_accuracy !== null) ? (int)$enforce_location_accuracy : 0;
        $stmt->bindParam(":enforce_location_accuracy", $enforce_location_accuracy, PDO::PARAM_INT);
        if ($stmt->execute() && $stmt->rowCount() > 0) {
            return true;
        }

        // If no row exists, create default then update
        $this->createDefault();
        return $stmt->execute();
    }

    public function updateTermsContent(string $termsConditionsContent): bool
    {
        $query = "UPDATE " . $this->table . "
                  SET terms_conditions_content = :terms_conditions_content,
                      updated_at = NOW()
                  WHERE id = 1";
        $stmt = $this->conn->prepare($query);
        $normalizedContent = $this->normalizeTermsContent($termsConditionsContent);
        $stmt->bindParam(':terms_conditions_content', $normalizedContent);

        if ($stmt->execute() && $stmt->rowCount() > 0) {
            return true;
        }

        $this->createDefault();
        return $stmt->execute();
    }

    public static function renderTermsContent(array $settings, string $baseUrl = 'https://wbs.bremac.co.ke/'): string
    {
        $template = trim((string)($settings['terms_conditions_content'] ?? ''));
        if ($template === '') {
            $template = self::getDefaultTermsTemplate();
        }

        $baseUrl = rtrim($baseUrl, '/') . '/';
        $currencyCode = trim((string)($settings['currency_code'] ?? 'KES'));
        $registrationFee = isset($settings['registration_fee']) ? number_format((float)$settings['registration_fee'], 2) : '0.00';
        $companyName = trim((string)($settings['company_name'] ?? 'BreMac Consultant Ltd'));
        $supportPhone = trim((string)($settings['support_phone'] ?? '254724400202'));
        $supportEmail = trim((string)($settings['support_email'] ?? 'support@waterbilling.com'));

        $replacements = [
            '{{portal_url}}' => htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'),
            '{{company_name}}' => htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
            '{{currency_code}}' => htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8'),
            '{{registration_fee}}' => htmlspecialchars($registrationFee, ENT_QUOTES, 'UTF-8'),
            '{{support_phone}}' => htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8'),
            '{{support_email}}' => htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'),
        ];

        return strtr($template, $replacements);
    }

    public static function renderTermsPlainText(array $settings, string $baseUrl = 'https://wbs.bremac.co.ke/'): string
    {
        $html = self::renderTermsContent($settings, $baseUrl);
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|h[1-6]|li|ul|ol)>/i', "\n", (string)$text);
        $text = strip_tags((string)$text);
        $decoded = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $decoded = preg_replace('/\r\n?|\n{3,}/', "\n\n", (string)$decoded);
        return trim((string)$decoded);
    }

    public static function renderTermsSections(array $settings, string $baseUrl = 'https://wbs.bremac.co.ke/'): array
    {
        $html = self::renderTermsContent($settings, $baseUrl);
        if (!preg_match_all('/<h6>(.*?)<\/h6>(.*?)(?=<h6>|$)/is', $html, $matches, PREG_SET_ORDER)) {
            $plain = self::renderTermsPlainText($settings, $baseUrl);
            return $plain !== '' ? [['title' => 'Terms & Conditions', 'content' => $plain]] : [];
        }

        $sections = [];
        foreach ($matches as $match) {
            $title = trim(strip_tags((string)($match[1] ?? '')));
            $contentHtml = trim((string)($match[2] ?? ''));
            $contentText = preg_replace('/<\s*br\s*\/?>/i', "\n", $contentHtml);
            $contentText = preg_replace('/<\/(p|li|ul|ol)>/i', "\n", (string)$contentText);
            $contentText = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags((string)$contentText), ENT_QUOTES, 'UTF-8')));
            if ($title === '' && $contentText === '') {
                continue;
            }
            $sections[] = [
                'title' => $title,
                'content' => $contentText,
            ];
        }

        return $sections;
    }

    public static function getDefaultTermsTemplate(): string
    {
        return <<<'HTML'
<p><strong>Community Water Supply Connection – {{company_name}}</strong></p>

<h6>1. Registration and Membership</h6>
<p>
    All individuals, companies, and organizations wishing to receive a water connection must first register through the
    official registration platform <a href="{{portal_url}}" target="_blank" rel="noopener noreferrer">BreMac Water Supply</a>.
    Registration requires accurate customer details including the applicant or company name, contact person details,
    phone number, service address, and location information.
</p>

<h6>2. Installation and Meter Registration Fees</h6>
<p>
    A one-time non-refundable installation fee of {{currency_code}} {{registration_fee}} is required for each approved new
    household or property connection. The same registration fee applies to each additional meter added under an existing
    customer account for extra properties, units, or service points.
</p>

<h6>3. Payment Method</h6>
<p>
    The preferred payment method is through the online registration system. Depending on the registration flow,
    applicants may pay directly during self-registration or through a registration proforma link issued by an
    authorized officer. The system may send an M-Pesa STK push to the registered phone number or to another phone
    number chosen by the applicant for payment.
</p>
<p>
    <strong>Alternative payment (for members unable to access the online platform):</strong><br>
    M-Pesa Paybill Number: 4166503<br>
    Business Name: {{company_name}}<br>
    Account Number: Registered Customer Account Number
</p>
<p>Applicants must retain the M-Pesa confirmation message as proof of payment.</p>

<h6>4. Installation Schedule</h6>
<p>
    Installation of household connections or additional approved meters will begin as soon as site conditions are suitable
    for safe trenching, pipe laying, and meter installation. Scheduling may be adjusted due to weather, hard ground, or
    other conditions that make excavation unsafe or impractical.
</p>

<h6>5. Connection Approval</h6>
<p>A connection or additional meter will only be scheduled after:</p>
<ul>
    <li>Successful registration or issuance of an approved registration proforma</li>
    <li>Full payment of the required registration or additional meter fee</li>
    <li>Verification or confirmation of payment by the system or project administrators</li>
</ul>

<h6>6. Account Creation and Multi-Property Accounts</h6>
<p>
    Customer accounts may be created in an inactive state during registration or proforma issuance. Water service activation,
    billing use, and portal access are only completed after the applicable registration fee is fully paid and confirmed.
    One customer account may hold multiple approved meters for different properties or units, but each meter remains subject
    to separate operational control, readings, and billing records.
</p>
<p>
    Where the portal password is not collected during registration, the customer will receive a one-time secure link to set
    their own password after payment confirmation. Account details and payment-related notifications may be sent to the
    registered phone number or email address.
</p>

<h6>7. Water Usage Charges</h6>
<p>
    Water usage charges, tariffs, and billing procedures will be communicated to members separately once the supply system
    becomes fully operational. Additional meters under the same customer account may be billed separately according to the
    meter readings captured for each installed meter.
</p>

<h6>8. Access for Installation</h6>
<p>
    Members must allow reasonable access to their property for trenching, pipe installation, meter installation, meter
    replacement, and maintenance work.
</p>

<h6>9. Responsibility for Internal Plumbing</h6>
<p>
    The project installation covers connection from the main distribution line to the designated connection point. Any
    internal plumbing within the property is the responsibility of the property owner.
</p>

<h6>10. Damage or Interference</h6>
<p>
    Tampering with pipelines, meters, valves, or any part of the water infrastructure is strictly prohibited. Any damage
    caused intentionally or through negligence will be repaired at the responsible member’s cost.
</p>

<h6>11. Service Interruptions</h6>
<p>
    While every effort will be made to ensure a reliable water supply, the project management shall not be liable for
    temporary service interruptions caused by maintenance, repairs, weather conditions, or other unforeseen circumstances.
</p>

<h6>12. Refund Policy</h6>
<p>
    Registration fees and additional meter fees are non-refundable once registration and payment have been confirmed and
    planning, procurement, or installation processes have commenced.
</p>

<h6>13. Changes to Terms</h6>
<p>
    {{company_name}} reserves the right to update or modify these terms and conditions when necessary. Members will be
    notified of any significant changes through the portal, SMS, email, or other official communication channels.
</p>

<h6>14. Compliance</h6>
<p>
    All registered members agree to abide by these terms and conditions as part of participating in the community water
    supply project.
</p>

<hr>

<h6>Community Water Supply Rules</h6>
<p><strong>{{company_name}}</strong></p>
<p>
    To ensure fair access, sustainability, and proper management of the community water supply system, all members are
    required to observe the following rules:
</p>

<h6>1. Registered Members Only</h6>
<p>
    Only customers who have completed registration, or who have been issued an approved registration proforma, and who have
    paid the required installation fee are eligible for a water connection. Additional meters under an existing account must
    also be formally approved and paid for before activation.
</p>

<h6>2. Authorized Connections</h6>
<p>
    All water connections must be installed <strong>only by authorized technicians</strong> appointed by {{company_name}}.
    Members are not allowed to install or modify connections themselves.
</p>

<h6>3. Prohibition of Illegal Connections</h6>
<p>
    Unauthorized tapping into the main pipeline, bypassing meters, sharing connections without approval, or connecting extra
    properties without formal registration is strictly prohibited. Any illegal connection will lead to immediate
    disconnection and penalties.
</p>

<h6>4. Protection of Water Infrastructure</h6>
<p>
    Members must help protect the water infrastructure including pipelines, valves, meters, and fittings. Any damage caused
    intentionally or through negligence must be repaired at the responsible person’s cost.
</p>

<h6>5. Water Meter Integrity</h6>
<p>
    Water meters must not be tampered with, altered, bypassed, or interfered with in any way. Each registered meter under a
    customer account is subject to inspection and operational verification.
</p>

<h6>6. Timely Payment of Bills</h6>
<p>
    All members must settle their water usage bills within the stipulated payment period. Persistent non-payment may result
    in temporary suspension of water supply until outstanding balances are cleared.
</p>

<h6>7. Access for Maintenance</h6>
<p>
    Authorized personnel may need access to properties for meter reading, maintenance, inspection, meter replacement, or
    repair. Members must cooperate and allow reasonable access when required.
</p>

<h6>8. Responsible Water Use</h6>
<p>
    Members are encouraged to use water responsibly and avoid wastage. Water should not be used for activities that may
    strain the supply system or reduce availability for other members.
</p>

<h6>9. Leak Reporting</h6>
<p>
    Members should promptly report any leaks, pipe bursts, or system faults to help prevent water loss and infrastructure damage.
</p>

<h6>10. Connection Transfer</h6>
<p>
    Water connections and additional meters are linked to the registered property and customer account. Any transfer,
    relocation, change of ownership, subdivision, or change of company/contact details must be communicated to the project
    administration for proper records update.
</p>

<h6>11. Dispute Resolution</h6>
<p>
    Any concerns or disputes related to billing, connections, additional meters, or services should be reported to the
    project administration team for review and resolution. For help, contact {{support_phone}} or {{support_email}}.
</p>
HTML;
    }

    public static function getDefaultBillNotificationTemplate(): string
    {
        return <<<'TEXT'
Dear {client_name},
{month} water bill: KES {bill_amount}
AC: {account}
Bill Date: {bill_date}
Prev Read: {previous_reading}
Reading: {current_reading}
Units: {units}
Service Fee: KES {service_fee}
{balance_label}: KES {balance_amount}
Amount Due: KES {amount_due}
Due Date: {due_date}
Pay: {payment_url}
Thank you, {company_name}.
TEXT;
    }

    public static function getTermsTemplateSamples(): array
    {
        return [
            'community_standard' => [
                'label' => 'Community Water Scheme',
                'description' => 'Balanced full policy covering registration, extra meters, billing, and community rules.',
                'content' => self::getDefaultTermsTemplate(),
            ],
            'plain_language' => [
                'label' => 'Plain Language Terms',
                'description' => 'Shorter, easier wording for public-facing schemes that want a friendlier tone.',
                'content' => <<<'HTML'
<p><strong>{{company_name}} Water Connection Terms</strong></p>

<h6>1. Who can apply</h6>
<p>Anyone who wants a water connection must register using <a href="{{portal_url}}" target="_blank" rel="noopener noreferrer">the official water portal</a> and provide correct contact and property details.</p>

<h6>2. Registration fee</h6>
<p>The registration fee is {{currency_code}} {{registration_fee}} for each new approved meter. If one customer account needs extra meters for more units or properties, the same fee is charged for each additional meter.</p>

<h6>3. Payment process</h6>
<p>Payment may be made through the online portal, through a registration proforma issued by staff, or through an M-Pesa prompt sent to the chosen phone number. Keep your payment confirmation message.</p>

<h6>4. Activation</h6>
<p>Your account or meter is only activated after payment is confirmed and the request has been approved.</p>

<h6>5. Additional meters</h6>
<p>One customer account may have several approved meters, but each meter is managed and billed using its own readings.</p>

<h6>6. Customer responsibilities</h6>
<ul>
    <li>Allow access for installation, inspection, reading, and maintenance</li>
    <li>Do not tamper with pipes, valves, or meters</li>
    <li>Pay bills on time</li>
    <li>Report leaks or faults quickly</li>
</ul>

<h6>7. Important notes</h6>
<p>Registration and additional meter fees are non-refundable once approval, planning, or installation work has started. {{company_name}} may update these terms when needed.</p>

<p>For help, contact {{support_phone}} or {{support_email}}.</p>
HTML,
            ],
            'multi_property' => [
                'label' => 'Landlord / Multi-Property Terms',
                'description' => 'Focused wording for landlords, compounds, and company-managed properties with several meters under one account.',
                'content' => <<<'HTML'
<p><strong>{{company_name}} Multi-Property Water Service Terms</strong></p>

<h6>1. Account structure</h6>
<p>A customer account may be opened for an individual, landlord, business, institution, or property manager. The same account may hold more than one approved water meter for separate houses, blocks, shops, units, or compounds.</p>

<h6>2. Registration and approval</h6>
<p>Every first connection and every additional meter must be requested through the official portal at <a href="{{portal_url}}" target="_blank" rel="noopener noreferrer">{{portal_url}}</a> or through an approved registration proforma issued by authorized staff.</p>

<h6>3. Charges</h6>
<p>Each approved new meter attracts a registration fee of {{currency_code}} {{registration_fee}}. This applies both to the first meter and to every approved additional meter on the same customer account.</p>

<h6>4. Billing</h6>
<p>Even where several meters belong to one account, each meter may be read, tracked, and billed separately according to actual consumption, applicable tariffs, and service rules.</p>

<h6>5. Access and maintenance</h6>
<p>The customer must provide safe access to all linked properties or meter points for installation, reading, repair, replacement, and inspection.</p>

<h6>6. Prohibited conduct</h6>
<p>Customers must not share unauthorized supply lines, bypass any meter, reconnect disconnected lines without approval, or attach unregistered extra properties to an existing line.</p>

<h6>7. Records updates</h6>
<p>Any change in occupancy, meter label, property use, subdivision, ownership, or responsible contact person must be reported so that account records remain accurate.</p>

<h6>8. Support</h6>
<p>For questions, disputes, or service requests, contact {{support_phone}} or {{support_email}}.</p>
HTML,
            ],
        ];
    }

    public function listTariffPlans(bool $activeOnly = false): array {
        $sql = "SELECT * FROM tariff_plans";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY effective_from DESC, id DESC";
        $stmt = $this->conn->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getActiveTariffPlan(?string $billingDate = null, string $connectionType = 'domestic'): ?array {
        $billingDate = $billingDate ?: date('Y-m-d');
        $stmt = $this->conn->prepare("SELECT * FROM tariff_plans
            WHERE is_active = 1
                AND effective_from <= :billing_date
                AND (effective_to IS NULL OR effective_to >= :billing_date)
                AND category IN ('all', :category)
            ORDER BY CASE WHEN category = :category2 THEN 0 ELSE 1 END, effective_from DESC, id DESC
            LIMIT 1");
        $stmt->execute([
            ':billing_date' => $billingDate,
            ':category' => $connectionType,
            ':category2' => $connectionType,
        ]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $blockStmt = $this->conn->prepare("SELECT * FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id ORDER BY from_unit ASC");
        $blockStmt->execute([':tariff_plan_id' => (int)$plan['id']]);
        $plan['blocks'] = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $plan;
    }

    public function getTariffPlanById(int $planId): ?array {
        if ($planId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT * FROM tariff_plans WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return null;
        }

        $blockStmt = $this->conn->prepare("SELECT * FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id ORDER BY from_unit ASC");
        $blockStmt->execute([':tariff_plan_id' => $planId]);
        $plan['blocks'] = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $plan;
    }

    public function saveTariffPlan(array $data, array $blocks): int {
        $name = trim((string)($data['name'] ?? ''));
        $category = trim((string)($data['category'] ?? 'all'));
        $effectiveFrom = trim((string)($data['effective_from'] ?? ''));
        $effectiveTo = trim((string)($data['effective_to'] ?? ''));
        $baseRate = (float)($data['base_rate_per_unit'] ?? 0);
        $serviceCharge = (float)($data['service_charge'] ?? 0);
        $vatRate = (float)($data['vat_rate'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $planId = (int)($data['id'] ?? 0);

        $allowedCategories = ['domestic', 'commercial', 'industrial', 'all'];
        if ($name === '') {
            throw new InvalidArgumentException('Tariff name is required.');
        }
        if (!in_array($category, $allowedCategories, true)) {
            throw new InvalidArgumentException('Invalid tariff category.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            throw new InvalidArgumentException('Effective from date is required.');
        }
        if ($effectiveTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)) {
            throw new InvalidArgumentException('Effective to date format is invalid.');
        }
        if ($baseRate < 0 || $serviceCharge < 0 || $vatRate < 0) {
            throw new InvalidArgumentException('Rates and charges cannot be negative.');
        }

        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            $from = (float)($block['from_unit'] ?? 0);
            $toRaw = $block['to_unit'] ?? null;
            $to = ($toRaw === null || $toRaw === '') ? null : (float)$toRaw;
            $rate = (float)($block['rate_per_unit'] ?? 0);
            if ($rate < 0) {
                continue;
            }
            if ($to !== null && $to <= $from) {
                continue;
            }
            $normalizedBlocks[] = [
                'from_unit' => $from,
                'to_unit' => $to,
                'rate_per_unit' => $rate,
            ];
        }

        if (empty($normalizedBlocks)) {
            $normalizedBlocks[] = [
                'from_unit' => 0,
                'to_unit' => null,
                'rate_per_unit' => $baseRate,
            ];
        }

        usort($normalizedBlocks, static function (array $a, array $b): int {
            return ($a['from_unit'] <=> $b['from_unit']);
        });

        $this->conn->beginTransaction();
        try {
            if ($planId > 0) {
                $stmt = $this->conn->prepare("UPDATE tariff_plans
                    SET name = :name,
                        category = :category,
                        effective_from = :effective_from,
                        effective_to = :effective_to,
                        base_rate_per_unit = :base_rate_per_unit,
                        service_charge = :service_charge,
                        vat_rate = :vat_rate,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':effective_from' => $effectiveFrom,
                    ':effective_to' => $effectiveTo !== '' ? $effectiveTo : null,
                    ':base_rate_per_unit' => $baseRate,
                    ':service_charge' => $serviceCharge,
                    ':vat_rate' => $vatRate,
                    ':is_active' => $isActive,
                    ':id' => $planId,
                ]);

                $del = $this->conn->prepare("DELETE FROM tariff_blocks WHERE tariff_plan_id = :tariff_plan_id");
                $del->execute([':tariff_plan_id' => $planId]);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO tariff_plans
                    (name, category, effective_from, effective_to, base_rate_per_unit, service_charge, vat_rate, is_active)
                    VALUES (:name, :category, :effective_from, :effective_to, :base_rate_per_unit, :service_charge, :vat_rate, :is_active)");
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':effective_from' => $effectiveFrom,
                    ':effective_to' => $effectiveTo !== '' ? $effectiveTo : null,
                    ':base_rate_per_unit' => $baseRate,
                    ':service_charge' => $serviceCharge,
                    ':vat_rate' => $vatRate,
                    ':is_active' => $isActive,
                ]);
                $planId = (int)$this->conn->lastInsertId();
            }

            $stmtBlock = $this->conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit)
                VALUES (:tariff_plan_id, :from_unit, :to_unit, :rate_per_unit)");
            foreach ($normalizedBlocks as $block) {
                $stmtBlock->execute([
                    ':tariff_plan_id' => $planId,
                    ':from_unit' => (float)$block['from_unit'],
                    ':to_unit' => $block['to_unit'] !== null ? (float)$block['to_unit'] : null,
                    ':rate_per_unit' => (float)$block['rate_per_unit'],
                ]);
            }

            $this->conn->commit();
            return $planId;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function setTariffPlanStatus(int $planId, int $isActive): bool {
        if ($planId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE tariff_plans SET is_active = :is_active, updated_at = NOW() WHERE id = :id");
        return $stmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $planId,
        ]);
    }

    public function deleteTariffPlan(int $planId): bool {
        if ($planId <= 0) {
            return false;
        }

        $countStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans");
        $totalPlans = (int)$countStmt->fetchColumn();
        if ($totalPlans <= 1) {
            throw new RuntimeException('At least one tariff plan must remain in the system.');
        }

        $planStmt = $this->conn->prepare("SELECT id, is_active FROM tariff_plans WHERE id = :id LIMIT 1");
        $planStmt->execute([':id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$plan) {
            return false;
        }

        $this->conn->beginTransaction();
        try {
            $delStmt = $this->conn->prepare("DELETE FROM tariff_plans WHERE id = :id");
            $deleted = $delStmt->execute([':id' => $planId]);

            if ($deleted && !empty($plan['is_active'])) {
                $activeCountStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans WHERE is_active = 1");
                $activeCount = (int)$activeCountStmt->fetchColumn();
                if ($activeCount === 0) {
                    $fallbackStmt = $this->conn->query("SELECT id FROM tariff_plans ORDER BY effective_from DESC, id DESC LIMIT 1");
                    $fallbackId = (int)$fallbackStmt->fetchColumn();
                    if ($fallbackId > 0) {
                        $activateStmt = $this->conn->prepare("UPDATE tariff_plans SET is_active = 1, updated_at = NOW() WHERE id = :id");
                        $activateStmt->execute([':id' => $fallbackId]);
                    }
                }
            }

            $this->conn->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    private function createDefault() {
          $default_rate = 50.00;
          $default_service = 0.00;
          $default_registration_fee = 0.00;
          $default_company_name = 'BreMac Consultant Ltd';
          $default_support_phone = '254724400202';
          $default_support_email = 'support@waterbilling.com';
          $default_currency = 'KES';
          $default_locale = 'en-KE';
          $default_timezone = 'Africa/Nairobi';
          $default_fy_start = 1;
          $default_vat_rate = 0.0;
          $default_tax_code = '';
          $default_terms = self::getDefaultTermsTemplate();
          $default_bill_notification_template = self::getDefaultBillNotificationTemplate();
          $default_mobile_api_key = '';
          $default_mobile_api_key_required = 1;
          $query = "INSERT INTO " . $this->table . " (id, rate_per_unit, service_charge, company_pin, etims_integration_url, etims_api_key, company_name, support_phone, support_email, currency_code, locale_code, timezone_name, financial_year_start_month, vat_rate, etims_taxation_type_code, registration_fee, enforce_location_accuracy, terms_conditions_content, bill_notification_template, mobile_api_key, mobile_api_key_required) 
              VALUES (1, :rate_per_unit, :service_charge, NULL, NULL, NULL, :company_name, :support_phone, :support_email, :currency_code, :locale_code, :timezone_name, :financial_year_start_month, :vat_rate, :etims_taxation_type_code, :registration_fee, 0, :terms_conditions_content, :bill_notification_template, :mobile_api_key, :mobile_api_key_required)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":rate_per_unit", $default_rate);
        $stmt->bindParam(":service_charge", $default_service);
        $stmt->bindParam(":company_name", $default_company_name);
        $stmt->bindParam(":support_phone", $default_support_phone);
        $stmt->bindParam(":support_email", $default_support_email);
        $stmt->bindParam(":currency_code", $default_currency);
        $stmt->bindParam(":locale_code", $default_locale);
        $stmt->bindParam(":timezone_name", $default_timezone);
        $stmt->bindParam(":financial_year_start_month", $default_fy_start, PDO::PARAM_INT);
        $stmt->bindParam(":vat_rate", $default_vat_rate);
        $stmt->bindParam(":etims_taxation_type_code", $default_tax_code);
        $stmt->bindParam(":registration_fee", $default_registration_fee);
        $stmt->bindParam(":terms_conditions_content", $default_terms);
        $stmt->bindParam(":bill_notification_template", $default_bill_notification_template);
        $stmt->bindParam(":mobile_api_key", $default_mobile_api_key);
        $stmt->bindParam(":mobile_api_key_required", $default_mobile_api_key_required, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
            id INT PRIMARY KEY AUTO_INCREMENT,
            rate_per_unit DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            company_pin VARCHAR(60) NULL,
            etims_integration_url VARCHAR(255) NULL,
            etims_api_key VARCHAR(255) NULL,
            company_name VARCHAR(255) DEFAULT 'BreMac Consultant Ltd',
            support_phone VARCHAR(50) DEFAULT '254724400202',
            support_email VARCHAR(255) DEFAULT 'support@waterbilling.com',
            currency_code VARCHAR(10) DEFAULT 'KES',
			locale_code VARCHAR(20) DEFAULT 'en-KE',
			timezone_name VARCHAR(100) DEFAULT 'Africa/Nairobi',
			financial_year_start_month TINYINT UNSIGNED DEFAULT 1,
			vat_rate DECIMAL(5,2) DEFAULT 0.00,
            etims_taxation_type_code VARCHAR(10) NULL,
            registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            enforce_location_accuracy TINYINT(1) NOT NULL DEFAULT 0,
            terms_conditions_content LONGTEXT NULL,
            bill_notification_template TEXT NULL,
            mobile_api_key VARCHAR(191) NULL,
            mobile_api_key_required TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->conn->exec($sql);

        // In case the table already existed without the new columns, attempt to add them.
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_pin VARCHAR(60) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_integration_url VARCHAR(255) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_api_key VARCHAR(255) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN company_name VARCHAR(255) DEFAULT 'BreMac Consultant Ltd'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN support_phone VARCHAR(50) DEFAULT '254724400202'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN support_email VARCHAR(255) DEFAULT 'support@waterbilling.com'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN currency_code VARCHAR(10) DEFAULT 'KES'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN locale_code VARCHAR(20) DEFAULT 'en-KE'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN timezone_name VARCHAR(100) DEFAULT 'Africa/Nairobi'");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN financial_year_start_month TINYINT UNSIGNED DEFAULT 1");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN vat_rate DECIMAL(5,2) DEFAULT 0.00");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN etims_taxation_type_code VARCHAR(10) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN enforce_location_accuracy TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN terms_conditions_content LONGTEXT NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN bill_notification_template TEXT NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN mobile_api_key VARCHAR(191) NULL");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }
        try {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN mobile_api_key_required TINYINT(1) NOT NULL DEFAULT 1");
        } catch (\PDOException $e) {
            // Ignore if column already exists
        }

        $this->ensureTariffTables();
    }

    private function normalizeTermsContent($termsConditionsContent): string
    {
        $content = trim((string)$termsConditionsContent);
        if ($content === '') {
            return self::getDefaultTermsTemplate();
        }

        return $content;
    }

    private function getStoredTermsContent(): string
    {
        try {
            $stmt = $this->conn->prepare("SELECT terms_conditions_content FROM " . $this->table . " WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        } catch (Throwable $e) {
            // Fall back to default template if lookup fails.
        }

        return self::getDefaultTermsTemplate();
    }

    private function normalizeBillNotificationTemplate($billNotificationTemplate): string
    {
        $content = trim(str_replace(["\r\n", "\r"], "\n", (string)$billNotificationTemplate));
        if ($content === '') {
            return self::getDefaultBillNotificationTemplate();
        }

        return $content;
    }

    private function getStoredBillNotificationTemplate(): string
    {
        try {
            $stmt = $this->conn->prepare("SELECT bill_notification_template FROM " . $this->table . " WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return $this->normalizeBillNotificationTemplate($value);
            }
        } catch (Throwable $e) {
            // Fall back to default template if lookup fails.
        }

        return self::getDefaultBillNotificationTemplate();
    }

    private function ensureTariffTables(): void {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('domestic', 'commercial', 'industrial', 'all') NOT NULL DEFAULT 'all',
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            base_rate_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tariff_dates (effective_from, effective_to),
            INDEX idx_tariff_category_active (category, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tariff_plan_id INT NOT NULL,
            from_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            to_unit DECIMAL(10,2) NULL,
            rate_per_unit DECIMAL(10,4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plan_from_unit (tariff_plan_id, from_unit),
            CONSTRAINT fk_tariff_blocks_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $countStmt = $this->conn->query("SELECT COUNT(*) FROM tariff_plans");
        $count = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        if ($count === 0) {
            $settings = $this->getSettings();
            $stmt = $this->conn->prepare("INSERT INTO tariff_plans
                (name, category, effective_from, base_rate_per_unit, service_charge, vat_rate, is_active)
                VALUES (:name, 'all', :effective_from, :base_rate_per_unit, :service_charge, :vat_rate, 1)");
            $stmt->execute([
                ':name' => 'Default Standard Tariff',
                ':effective_from' => date('Y-m-01'),
                ':base_rate_per_unit' => (float)($settings['rate_per_unit'] ?? 50.0),
                ':service_charge' => (float)($settings['service_charge'] ?? 0.0),
                ':vat_rate' => (float)($settings['vat_rate'] ?? 0.0),
            ]);

            $planId = (int)$this->conn->lastInsertId();
            if ($planId > 0) {
                $blockStmt = $this->conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit)
                    VALUES (:tariff_plan_id, 0.00, NULL, :rate_per_unit)");
                $blockStmt->execute([
                    ':tariff_plan_id' => $planId,
                    ':rate_per_unit' => (float)($settings['rate_per_unit'] ?? 50.0),
                ]);
            }
        }
    }
}
?>
