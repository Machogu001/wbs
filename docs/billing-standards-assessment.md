# Billing Standards Assessment and Completion Plan

Date: 2026-04-15
Scope: Water Billing System (WBS)

## Executive Summary

This system is operationally strong for core water billing, but it is not yet fully comprehensive against utility-grade billing standards.

Current maturity estimate:
- Operational billing readiness: 75%
- Finance and audit readiness: 60%
- Enterprise utility billing completeness: 50%

This document combines:
- Requirements matrix (what is met, partial, or missing)
- Best-practice review
- Prioritized implementation roadmap
- Immediate hardening updates completed on 2026-04-15

## Immediate Hardening Completed Today

1. Accounting idempotency for automated postings
- Added duplicate-post prevention for bill and payment references in accounting posting methods.
- Added best-effort unique index on journal entries for (reference_type, reference_id, status).
- Files:
  - includes/Accounting.php

2. Safe payment completion transition handling
- Accounting entries are now posted only when a payment transitions into completed status.
- Prevents duplicate posting on repeated status updates or callback retries.
- File:
  - includes/Payment.php

3. Non-blocking accounting failures now produce warning logs
- Replaced silent catches with error_log in billing and manual payment paths.
- Files:
  - includes/Bill.php
  - pages/admin/payments.php
  - includes/Payment.php

## Requirements Matrix

Legend:
- Met: implemented and usable
- Partial: implemented but missing controls/depth
- Missing: not implemented

### 1) Customer and Service Master Data
- Customer accounts and profile data: Met
- Meter linkage and account numbers: Met
- Customer categories (domestic/commercial/industrial): Partial
- Multi-service/multi-meter per account support: Missing
- Location and geospatial capture: Partial

### 2) Meter Reading and Validation
- Meter reading capture: Met
- Approval workflow on readings: Partial
- Validation rules (spike/negative/anomaly thresholds): Partial
- Reader route planning and handheld workflow: Missing

### 3) Tariff and Rating Engine
- Flat rate per unit + fixed service charge: Met
- Tiered block tariffs: Missing
- Seasonal/time-based tariffs: Missing
- Effective-dated tariff versioning: Missing
- Category-based tariff policies: Partial

### 4) Billing and Invoicing
- Bill generation per period: Met
- Due dates and statuses (pending/paid/overdue): Met
- Penalties and late fee automation: Partial
- Tax/VAT breakdown per bill line: Partial
- Rebilling and bill cancellation lifecycle: Partial

### 5) Payments and Allocation
- M-Pesa payment integration: Met
- Manual payment posting: Met
- Payment callback handling: Met
- Idempotent accounting posting on payment completion: Met
- Advanced allocation (oldest debt, configurable rules): Partial
- Reversals/chargebacks/returns workflow: Missing

### 6) Credit Notes and Adjustments
- Credit note table and creation path: Partial
- Integrated adjustment ledger impact: Partial
- Approval and audit trail for adjustments: Partial
- Write-off and waiver workflows: Missing

### 7) Receivables and Collections
- Overdue tracking: Met
- Demand notices lifecycle: Met
- Delinquency profile per customer: Met
- Aging buckets (30/60/90+) and collection strategy: Missing
- Payment plans/installments: Missing

### 8) Accounting and Finance Controls
- Chart of accounts: Met
- Journal entries and trial balance: Met
- Auto postings from billing and payments: Met
- Duplicate post guard: Met
- Period close and lock controls: Missing
- Reconciliation reports (subledger to GL): Partial
- Reversal journals and immutable audit controls: Partial

### 9) Reporting and Analytics
- Financial reports with date filtering: Met
- CSV/PDF export: Met
- Usage and billing views: Met
- Aging, leakage, non-revenue water analytics: Missing
- Executive KPI and trend dashboarding: Partial

### 10) Compliance, Security, and Audit
- Authentication and role checks: Met
- 2FA support: Met
- Activity logging: Partial
- Tax/e-invoicing hooks (eTIMS): Partial
- Full audit-grade non-repudiation and event immutability: Missing

### 11) Platform Reliability and Operations
- Installer and schema bootstrap: Met
- Backward-compatible schema evolution in app code: Partial
- Silent failure reduction with warning logs: Partial
- Observability (metrics/alerts/health SLOs): Partial
- Automated tests and CI quality gates: Missing

## Best-Practice Review

### Strengths
- End-to-end billing to payment flow exists.
- Practical utility operations are covered (demand notices, statements, admin workflows).
- Integrated double-entry accounting foundation is present.
- Security features like 2FA and role checks are available.

### Key Risks
- Tariff model is too simple for regulatory and tariff-policy change needs.
- Tax handling is configurable but not fully modeled in bill line accounting.
- Adjustments and reversals are not yet governed by strict finance controls.
- Limited collections intelligence (no aging buckets or installment framework).
- Test automation and release guardrails are not established.

## Roadmap to Full Standards Compliance

### Phase 1: Finance Integrity (2-4 weeks)
1. Add posting source metadata and immutable audit fields for billing/payment journals.
2. Implement explicit reversal entries for payments and bills.
3. Add period-close lock for posting dates.
4. Build reconciliation report: billed amount, payments, AR movement, GL totals.

Acceptance criteria:
- No duplicate posts under callback retries.
- Any correction is reversal-based, never destructive edits.
- Reconciliation differences are detectable in one report.

### Phase 2: Tariff and Tax Engine (3-5 weeks)
1. Introduce tariff tables with effective dates and customer category scopes.
2. Support block/tier rates and optional seasonal rates.
3. Split bills into charge lines (usage, service charge, penalty, tax).
4. Post tax payable entries when tax applies.

Acceptance criteria:
- Bills are reproducible from tariff snapshot and readings.
- Tax is traceable per invoice line and GL postings.

### Phase 3: Adjustments and Collections (3-4 weeks)
1. Implement controlled adjustment workflows (credit note, waiver, write-off).
2. Add AR aging buckets and strategy states (soft/hard collections).
3. Add payment plans/installments and allocation rules.
4. Add collection performance reporting.

Acceptance criteria:
- Every adjustment has approval, reason, actor, and journal evidence.
- Collections can be managed by bucket and policy.

### Phase 4: Reliability and Governance (2-4 weeks)
1. Add test coverage for core billing, payment callbacks, and accounting invariants.
2. Add CI checks (lint, static checks, unit tests).
3. Add operational dashboards and alerting on failed integrations.
4. Add backup/restore and data retention policy documentation.

Acceptance criteria:
- Release pipeline blocks regressions in core finance flows.
- Production alerts identify payment/accounting failures quickly.

## Testing Checklist for Go-Live Quality

1. Bill generation idempotency for same reading period.
2. Payment callback replay does not duplicate AR/cash postings.
3. Partial payments and full payments produce expected balances.
4. Credit note and adjustment preserve audit trail and balances.
5. Trial balance remains mathematically consistent after all scenarios.
6. Reports tie to ledger totals for chosen periods.
7. Role-based permissions prevent unauthorized finance actions.

## Definition of Done for Full Compliance

The system should be considered comprehensive and standard when all are true:
- Tariff engine supports effective-dated, block-based rules.
- Billing output has line-level tax and charge composition.
- AR lifecycle supports adjustments, reversals, write-offs, and installments.
- Accounting is idempotent, reversible, and period-controlled.
- Reconciliation, aging, and compliance reports are available.
- Audit logs and automated tests protect financial integrity.
