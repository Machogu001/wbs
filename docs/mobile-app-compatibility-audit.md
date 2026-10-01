# Mobile App Compatibility Audit

Date: 2026-09-30

Scope: compare the Android app in `Machogu001/wbs_app` with the mobile API routes under `/api/mobile` in this repository.

Summary

- All mobile routes currently used by the Android app exist on the backend.
- The shared response envelope is aligned: the app expects `status`, `message`, and `data`, and the backend returns that shape from `mobileApiJson(...)`.
- The main remaining issues are client-side consumption gaps rather than missing endpoints.

Status Legend

- `Aligned`: route exists and the app is consuming the current payload shape successfully.
- `Partial`: route exists and works, but the app ignores newer metadata or still relies on a compatibility alias / older field usage.
- `Gap`: route exists, but the app currently uses it in a way that can lose data or drift from server intent.

## Public, Auth, and Customer Routes

| Route | App Use | Status | Notes |
| --- | --- | --- | --- |
| `/api/mobile/login.php` | Sign-in | Aligned | Supports login and 2FA challenge handoff. |
| `/api/mobile/resend_2fa.php` | 2FA resend | Aligned | App posts `challenge_token` and method values the backend accepts. |
| `/api/mobile/verify_2fa.php` | 2FA verify | Aligned | App expects access token and user payload; backend returns that. |
| `/api/mobile/logout.php` | Sign-out | Aligned | Bearer-token logout matches app flow. |
| `/api/mobile/me.php` | Session restore and profile | Aligned | App now consumes profile actions, alert routing, server-defined account-detail fields, and linked-meter section metadata. |
| `/api/mobile/theme.php` | Theme preference | Aligned | App reads `theme_preference` and `available_preferences`, and posts updates correctly. |
| `/api/mobile/change_password.php` | Change password | Aligned | Request and response contract matches app. |
| `/api/mobile/register.php` | Public registration metadata and submit | Aligned | App now consumes the main registration option arrays plus the richer nested `terms_conditions` structure, including section-preferred rendering with HTML/text fallback. |
| `/api/mobile/registration_payment.php` | Post-registration STK follow-up | Aligned | App uses GET status plus POST resend/initiate correctly; optional phone fallback is supported by backend. |
| `/api/mobile/dashboard.php` | Customer dashboard | Aligned | App now consumes server-driven summary cards, recommended actions, section titles, and empty-state metadata. |
| `/api/mobile/statement.php` | Statement | Aligned | App reads `summary`, `bills`, and `payments` as provided. |
| `/api/mobile/complaints.php` | Customer complaints | Aligned | App uses `complaints` list and create action correctly. |
| `/api/mobile/bills.php` | Bills list | Aligned | App now consumes filter labels/options, pagination metadata, primary action, and item link actions from the backend screen payload. |
| `/api/mobile/bill.php` | Bill detail | Aligned | App reads bill amounts, readings, line items, payments, and document links as provided. |
| `/api/mobile/payments.php` | Customer payment list | Aligned | App reads `payments` and total count correctly. |
| `/api/mobile/payment.php` | Payment detail | Aligned | App reads payment receipt fields and document links correctly. |
| `/api/mobile/document.php` | Invoice, proforma and receipt PDFs | Aligned | Bearer-authenticated PDF stream used by every mobile `document_url`, `receipt_url` and `receipt_pdf_url`, so the app renders documents natively instead of loading website pages. |
| `/api/mobile/meters.php` | Linked meters | Aligned | App uses `meters` array as returned. |
| `/api/mobile/meter_readings.php` | Reading history | Aligned | App uses `meter_readings` and `photo_url` fields correctly. |
| `/api/mobile/submit_reading.php` | Reading upload | Aligned | Multipart upload flow matches endpoint contract. |
| `/api/mobile/initiate_payment.php` | Customer bill STK push | Aligned | App treats phone as optional, and backend correctly falls back to `user.phone_number`. |
| `/api/mobile/payment_status.php` | STK payment polling | Aligned | App expects `payment` and `polling`; backend provides those fields. |
| `/api/mobile/request_bill_action.php` | Installment / waiver / write-off request | Aligned | App request body matches backend action contract. |
| `/api/mobile/profile_update.php` | Edit profile | Aligned | App updates core profile and 2FA settings in the shape backend accepts. |

## Staff and Admin Routes

| Route | App Use | Status | Notes |
| --- | --- | --- | --- |
| `/api/mobile/admin/customers.php` | Read-only customer search | Aligned | App reads `customers` list correctly. |
| `/api/mobile/admin/customer.php` | Customer detail | Aligned | App uses `customer`, `summary`, `recent_bills`, and `recent_payments` as returned. |
| `/api/mobile/admin/search_clients.php` | Live autocomplete | Aligned | Backend now exposes `clients` / `data` / `results` / `suggestions` plus `selection_value`; app lookup logic matches that surface. |
| `/api/mobile/admin/users.php` | Customer management | Partial | CRUD flows work, including meter replacement, but the app uses mostly hardcoded inputs rather than server-driven option metadata. |
| `/api/mobile/admin/staff_users.php` | Staff account management | Aligned | App request bodies match create/edit/reset/delete actions. |
| `/api/mobile/admin/role_permissions.php` | Permissions matrix | Aligned | App consumes `roles`, `permission_defs`, and `current` correctly. |
| `/api/mobile/admin/settings.php` | Billing settings and tariffs | Aligned | App reads `settings`, `mobile_api_key_masked`, and `tariff_plans` exactly as returned. |
| `/api/mobile/admin/terms_conditions.php` | Terms display and edit | Aligned | App now edits `terms_conditions_template`, consumes display metadata for preview formats, and can load backend sample templates into the editor. |
| `/api/mobile/admin/activity_logs.php` | Activity log list and delete | Aligned | App consumes `logs` and pagination total correctly. |
| `/api/mobile/admin/system_logs.php` | Error logs and SMS queue | Aligned | App now prefers the canonical `error_message` field and only falls back to `message` for compatibility. |
| `/api/mobile/admin/collections.php` | Collections dashboard | Aligned | App now consumes period/row metadata, summary cards, and backend payment-method labels when rendering collection totals. |
| `/api/mobile/admin/payments.php` | Payments workspace | Aligned | App now consumes server-provided field labels, defaults, source-driven bill options, and permission gating for manual payment actions. |
| `/api/mobile/admin/manual_payment.php` | Quick manual payment | Aligned | Quick record-payment flow matches endpoint fields and validation. |
| `/api/mobile/admin/payment_transactions.php` | Transaction history | Aligned | App uses `counts` and `payments` as returned. |
| `/api/mobile/admin/invoicing.php` | Bill from reading | Aligned | App now uses backend field metadata for labels, picker modes, placeholders, and returned billing defaults. |
| `/api/mobile/admin/bill_correction.php` | Bill correction workspace | Aligned | Search, selected bill, and correction history shape matches app. |
| `/api/mobile/admin/bill_detail.php` | Admin bill detail | Aligned | App consumes bill, credit notes, approval items, and invoice URL fields correctly. |
| `/api/mobile/admin/approvals.php` | Finance approvals | Aligned | App reads `summary` and `items` correctly. |
| `/api/mobile/admin/accounting_overview.php` | Accounting overview | Aligned | App uses the generic finance-node renderers, which are tolerant of backend structure. |
| `/api/mobile/admin/accounting_ledger.php` | Ledger and journal detail | Aligned | App consumes accounts, entries, selected entry, and reverse action correctly. |
| `/api/mobile/admin/accounting_budget.php` | Budget workspace | Aligned | App reads budget year, accounts, and budget summary structure correctly. |
| `/api/mobile/admin/accounting_reports.php` | Financial reports | Aligned | App uses generic renderers for nested report nodes. |
| `/api/mobile/admin/accounting_transfers.php` | Fund transfers | Aligned | App reads accounts and transfer history correctly. |
| `/api/mobile/admin/registration_proformas.php` | Registration proformas | Partial | CRUD flows work, but the app hardcodes customer/connection inputs and does not consume shared option metadata patterns. |
| `/api/mobile/admin/onboarding_tracker.php` | Onboarding tracker | Aligned | App consumes `summary`, `total`, and `records` as returned. |
| `/api/mobile/admin/customer_locations.php` | GPS locations | Aligned | App reads `pins` and opens external map links. |
| `/api/mobile/admin/complaints.php` | Complaint management | Aligned | App uses `complaints` and update status flow correctly. |
| `/api/mobile/admin/support_inquiries.php` | Inquiry management | Aligned | App consumes `summary` and `inquiries`, and posts status/reply actions correctly. |
| `/api/mobile/admin/demand_notices.php` | Demand notices | Aligned | App reads summary and notices and posts generate/update actions correctly. |
| `/api/mobile/admin/integration_health.php` | Integration health | Aligned | App uses generic summary rendering and matches backend output. |
| `/api/mobile/admin/blog.php` | Blog management | Aligned | App consumes post/comment structures and actions correctly. |
| `/api/mobile/admin/reports.php` | Business reports and maintenance | Aligned | App reads summary, recent items, audit status, and maintenance actions correctly. |

## Remaining Mismatches To Fix In The Android App

1. Several admin create/edit screens still hardcode some server-supported choices where no mobile metadata contract exists yet. The main remaining candidates are specialized operations outside the upgraded payments/settings flows.

## Recommended Edit Order

1. Migrate the remaining admin create/edit forms to consume shared server metadata instead of hardcoded enumerations.
