# Mobile API

This project now exposes a token-based customer mobile API under `/api/mobile`.

Base URL example:

```text
https://your-domain.example/api/mobile
```

## Authentication

- The API uses user bearer tokens, and it can also enforce an application key.
- When mobile API key enforcement is enabled in System Settings, every request must include the mobile app key header:

```http
X-API-Key: YOUR_MOBILE_API_KEY
```

- Send the token in the `Authorization` header:

```http
Authorization: Bearer YOUR_ACCESS_TOKEN
```

- Public login and 2FA endpoints also require `X-API-Key` when that setting is enabled.
- Tokens are returned by the login flow and expire after 30 days by default.
- If a user has 2FA enabled, the login flow returns a `challenge_token` first. The app must then verify the code before it receives an access token.

Generate or rotate the mobile API key from System Settings. Only admins or users with `manage_settings` can do that. The same settings panel also controls whether `X-API-Key` is required or the mobile API is left open.

If `.env` is not writable by the web server user, the generated mobile API key is stored in `billing_settings.mobile_api_key`. When a database-stored key exists, the mobile API runtime uses it as the active key ahead of the `.env` value.

## Common Response Format

```json
{
  "status": "success",
  "message": "Human readable message",
  "data": {}
}
```

Error responses use the same format with `status: "error"`.

## Endpoints

OpenAPI document:

- `docs/mobile-api-openapi.json`
- `https://wbs.bremac.co.ke/docs/mobile-api-openapi.json`

### `POST /api/mobile/login.php`

Start a mobile login.

Request:

```json
{
  "identifier": "MTR0008",
  "password": "secret123",
  "device_name": "Android Samsung A55"
}
```

Successful response without 2FA:

```json
{
  "status": "success",
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 21,
      "account_number": "MTR0008",
      "full_name": "Topcare Lands Investment Limited",
      "status": "active",
      "meters": []
    },
    "access": {
      "access_token": "...",
      "token_type": "Bearer",
      "expires_at": "2026-10-29 10:30:00",
      "expires_in": 2592000,
      "device_name": "Android Samsung A55"
    },
    "requires_registration_payment": false,
    "registration_payment": null
  }
}
```

2FA challenge response:

```json
{
  "status": "two_factor_required",
  "message": "Verification code sent.",
  "data": {
    "challenge_token": "...",
    "method": "sms",
    "available_methods": ["sms", "email"],
    "expires_in": 300,
    "device_name": "Android Samsung A55"
  }
}
```

### `POST /api/mobile/verify_2fa.php`

Complete a 2FA login and receive an access token.

Request:

```json
{
  "challenge_token": "...",
  "code": "123456",
  "device_name": "Android Samsung A55"
}
```

### `POST /api/mobile/resend_2fa.php`

Resend a 2FA code.

Request:

```json
{
  "challenge_token": "...",
  "method": "sms"
}
```

`method` is optional. Use `sms` or `email`.

### `POST /api/mobile/logout.php`

Revoke the current bearer token.

Headers:

```http
Authorization: Bearer YOUR_ACCESS_TOKEN
```

### `GET /api/mobile/me.php`

Return the current customer profile, including linked meters.

### `POST /api/mobile/profile_update.php`

Update the authenticated user's profile details and two-factor settings.

### `POST /api/mobile/change_password.php`

Change the authenticated user's password.

### `GET /api/mobile/dashboard.php`

Return a mobile dashboard payload with:

- outstanding balance summary
- pending and overdue bill counts
- latest bills
- latest payment
- active meters

### `GET /api/mobile/meters.php`

Return all meters linked to the authenticated customer account.

### `GET /api/mobile/complaints.php`

Return the authenticated customer's complaint history.

### `POST /api/mobile/complaints.php`

Create a new complaint.

Request:

```json
{
  "subject": "Low water pressure",
  "message": "Pressure has been low for the last three days in Block B."
}
```

### `GET /api/mobile/bills.php`

Return paginated bills.

Query parameters:

- `page` default `1`
- `limit` default `20`, maximum `100`
- `status` optional, for example `pending`, `overdue`, `paid`

Example:

```text
GET /api/mobile/bills.php?page=1&limit=10&status=pending
```

### `GET /api/mobile/bill.php?id={bill_id}`

Return one bill with:

- bill totals
- outstanding balance
- bill line items
- related payment history
- public payment URL
- printable document URL

### `POST /api/mobile/request_bill_action.php`

Submit a bill write-off, waiver, or installment-plan request for the authenticated customer.

Request:

```json
{
  "bill_id": 71,
  "action": "installment",
  "amount": 2500,
  "installment_count": 3,
  "frequency": "monthly",
  "start_date": "2026-10-01",
  "reason": "Requesting a payment plan"
}
```

### `GET /api/mobile/payments.php`

Return paginated payment history.

### `GET /api/mobile/payment.php?id={payment_id}`

Return a single payment with receipt URLs.

### `GET /api/mobile/statement.php`

Return an authenticated statement view with filtered bills, filtered payments, and totals.

Query parameters:

- `page` default `1`
- `limit` default `20`, maximum `100`

### `POST /api/mobile/initiate_payment.php`

Start an M-Pesa STK push for a customer bill.

Request:

```json
{
  "bill_id": 71,
  "phone": "254748103009"
}
```

Notes:

- `phone` is optional if the user profile already has a phone number.
- The API charges the current outstanding amount, not the original bill amount.

## Staff And Admin Endpoints

All of the following routes require bearer authentication plus the matching staff/admin permission.

### `GET /api/mobile/admin/customers.php`

List or search customers for mobile staff clients.

### `GET /api/mobile/admin/customer.php?id={customer_id}`

Return customer profile, summary, recent bills, and recent payments.

### `GET /api/mobile/admin/bill_detail.php?id={bill_id}`

Return bill detail for staff users, including line items, payments, credit notes, and approval items.

### `GET /api/mobile/admin/approvals.php`

Return finance approval items and approval summary counts.

### `POST /api/mobile/admin/approvals.php`

Approve or reject a finance approval item.

### `GET /api/mobile/admin/complaints.php`

Return customer complaints for support/admin users.

### `POST /api/mobile/admin/complaints.php`

Update complaint status.

### `POST /api/mobile/admin/manual_payment.php`

Record a manual payment against one invoice or auto-allocate it across open invoices.

### `GET /api/mobile/meter_readings.php`

Return recent meter-reading submissions.

Query parameters:

- `limit` default `20`
- `meter_number` optional

### `POST /api/mobile/submit_reading.php`

Submit a meter reading with a meter photo.

This endpoint expects `multipart/form-data`.

Fields:

- `current_reading` required
- `billing_month` optional, defaults to the first day of the previous month
- `due_date` optional, defaults to 3 days from submission
- `meter_number` optional if the customer has more than one meter
- `meter_photo` required, JPG or PNG

Example cURL:

```bash
curl -X POST "https://your-domain.example/api/mobile/submit_reading.php" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -F "current_reading=1820.5" \
  -F "billing_month=2026-09-01" \
  -F "due_date=2026-09-30" \
  -F "meter_number=MTR0008" \
  -F "meter_photo=@/path/to/meter.jpg"
```

On success the API returns the created reading record and the linked bill payment/document URLs.

## Staff/Admin Mobile Endpoints

These endpoints use the same bearer token system, but the signed-in user must be an admin or hold the required staff permission.

### `GET /api/mobile/admin/search_clients.php?q=...`

Mobile-friendly client autocomplete/search for staff workflows.

Allowed roles/permissions:

- admin
- `view_customers`
- `view_payments`
- `view_invoicing`
- `correct_bills`

Returns one record per client with account details plus meter-aware suggestion metadata.

### `GET /api/mobile/admin/collections.php`

Collections summary for the recent period.

Query parameters:

- `days` default `30`, maximum `90`
- `limit` default `20`, maximum `100`

Allowed roles/permissions:

- admin
- `view_payments`
- `receive_payments`

Returns:

- collections totals
- completed/pending/failed amounts
- customer coverage count
- totals grouped by payment method
- recent payment list

### `GET /api/mobile/admin/onboarding_tracker.php`

Mobile-friendly onboarding tracker feed for registration proformas and additional meters.

Query parameters:

- `q`
- `type`
- `status`
- `from`
- `to`
- `assignee`
- `page`
- `limit`

Allowed roles/permissions:

- admin
- `manage_registration_proformas`
- `view_customers`

Returns:

- tracker summary counts
- assignee summary
- paginated onboarding records
- payment/document/customer links for drill-down from the mobile app

## Registration-Payment Handling

If a customer account is not yet active, `login.php`, `verify_2fa.php`, and `me.php` include:

- `requires_registration_payment`
- `registration_payment`

This allows a mobile app to guide onboarding users to settle the registration fee before full activation.

## Mobile App Implementation Notes

- Store the bearer token securely on the device.
- Handle `401` by redirecting the user back to login.
- Handle `429` on login by backing off and retrying later.
- Use the returned public payment/document URLs if the app needs to open invoice or payment pages in a browser/webview.
- `submit_reading.php` is the only multipart endpoint in this first release; the rest use JSON for `POST` requests.