# Quote-to-Cash Redesign: Quote Preparation, Approval & Project Billing

**Status:** Proposed · **Date:** 2026-07-05 · **Scope:** QuoteController, Projects module approval/finance actions, Finance module billing

---

## 1. Executive Summary

The system today has a working but fragile pipeline: **Budget → Quote → (dual) Approval → 70% Mobilization Gate → Project Activation**, with payments logged as flat rows against an enquiry. There is **no billing engine at all** — no invoices, no billing schedule, no AR, no change-order billing. "Billing" is a single anchored number (`client_approved_quote`) plus a list of `enquiry_payments`.

The most urgent problems are not architectural but **integrity holes**: quote approval endpoints accept client-supplied approval status, approver names, and approved amounts with no authorization; approved quotes remain editable; payments can be edited across enquiry boundaries; and a second finance-release path bypasses governance entirely.

This document (a) inventories the current flow as implemented, (b) lists verified flaws with file/line references, (c) proposes a unified target architecture, and (d) phases the work so integrity fixes land first.

---

## 2. Current State (as implemented)

### 2.1 Actual flow

```
TaskBudgetData ──importBudgetData()──> TaskQuoteData (per-category margins, default 60%)
                                            │
                                            ▼
      QuoteController::saveQuoteData (draft edits, versions, Excel alternative path)
                                            │
                    ┌───────────────────────┴────────────────────────┐
                    ▼                                                ▼
   ENQUIRY-LEVEL "quote approval"                    TASK-LEVEL quote approval
   ApproveQuoteAction (permission-checked,           saveApproval / submitApproval
   generates job_number, fires QuoteApproved)        (quote_approvals table, NO auth,
                    │                                 sets client_approved_quote from request)
                    ▼
   EvaluateFinancialRequirements listener
     external (WNG) → status = awaiting_deposit
     internal        → ActivateProjectAction (skips gate)
                    │
                    ▼
   EnquiryPayment rows logged (FinanceService::logPayment)
                    │
                    ▼
   ReleaseFinanceGateAction (70% threshold, note-only override)
     → FinanceReleased → ActivateProjectAfterFinance → ActivateProjectAction
```

### 2.2 Key components

| Component | Location | Role |
|---|---|---|
| `QuoteController` | `app/Http/Controllers/QuoteController.php` (1,998 lines) | Prep, budget import, merge, scope sync, Excel upload, versioning, **and** approval |
| `ApproveQuoteAction` | `app/Modules/Projects/Actions/ApproveQuoteAction.php` | Enquiry-level approval; permission `FINANCE_QUOTE_APPROVE`; job number; `QuoteApproved` event |
| `saveApproval` / `submitApproval` | `QuoteController.php:1612`, `:1202` | Task-level approval into raw `quote_approvals` table |
| `FinanceService` | `app/Modules/Projects/Services/FinanceService.php` | Payment CRUD, payment progress, quote-basis resolution |
| `ReleaseFinanceGateAction` | `app/Modules/Projects/Actions/ReleaseFinanceGateAction.php` | 70% mobilization gate with governance audit log |
| `EnquiryPayment` | `app/Models/EnquiryPayment.php` | Flat payment row (no currency, no allocation) |
| Finance module | `app/Modules/Finance/` | ChartOfAccounts + PettyCash only — **no client billing** |

---

## 3. Verified Flaws

### A. Integrity & authorization (fix first — these are exploitable today)

| # | Flaw | Evidence |
|---|------|----------|
| A1 | **Self-approval via quote save.** `saveQuoteData` accepts `status ∈ {draft,pending,approved,rejected}` from the request body. Any authenticated user can mark a quote approved. | `QuoteController.php:78` |
| A2 | **Approval of record is client-supplied.** `saveApproval`/`submitApproval` take `approved_by` (free-text string), `approval_date`, `quote_amount`, and the full `quote_data` blob from the request. No permission check, no `Auth::user()` binding, no event fired. | `QuoteController.php:1612–1746`, `:1202–1309` |
| A3 | **The financial anchor is client-supplied.** On approval, `client_approved_quote = $request->quote_amount` — this number drives the 70% mobilization gate and all payment progress. | `QuoteController.php:1717` |
| A4 | **No post-approval edit lock.** Nothing prevents `saveQuoteData`, `syncScope`, `smartMergeBudget`, or version restore from rewriting an approved quote. The frozen snapshot is created once at approval and never enforced afterwards. | `QuoteController.php:57–135`, `:1323` |
| A5 | **Cross-enquiry payment tampering.** `updatePayment`/`deletePayment` resolve `EnquiryPayment::findOrFail($paymentId)` without scoping to the route's `{enquiry}` — a payment on project X can be edited via project Y's URL, and returned "progress" is computed for the wrong enquiry. | `EnquiryController.php:1159` |
| A6 | **Ungoverned finance release path.** `FinanceService::releaseProject()` sets `status=planning` directly — no `finance_released` flag, no `GovernanceAuditLog`, no `FinanceReleased` event — a full bypass of `ReleaseFinanceGateAction`. | `FinanceService.php:39–45` |
| A7 | **Silent activation failure.** `ActivateProjectAfterFinance` catches and logs exceptions, so the finance release commits while the project never activates — divergent state with no alert. | `ActivateProjectAfterFinance.php:26–31` |
| A8 | **Authorization failure surfaces as 500.** `ApproveQuoteAction` throws generic `Exception` for a permission failure; the controller returns it as a 500 with the message leaked. | `ApproveQuoteAction.php:28–30` |

### B. Workflow & correctness

| # | Flaw | Evidence |
|---|------|----------|
| B1 | **Two disconnected approval systems.** Enquiry-level (`quote_approved` flag + `QuoteApproved` event) vs task-level (`quote_approvals` table + `client_approved_quote`). Neither validates the other; graph confirms no edge between them. | `ApproveQuoteAction.php` vs `QuoteController.php:1612` |
| B2 | **Inverted ordering.** Task-level approval *requires* a job number, which is only generated by the enquiry-level approval — so the enquiry is "quote approved" (and the project activated for internal jobs) *before* the quote approval of record exists. | `QuoteController.php:1644–1652` |
| B3 | **Approval doesn't pin a version.** `quote_approvals` stores a duplicated JSON blob instead of referencing an immutable `QuoteVersion`. What was approved and what the quote now says can silently diverge. | `QuoteController.php:1742` |
| B4 | **Rejected quotes complete the task.** Rejection marks the quote task `completed`, same as approval — no rework loop, no revision cycle. | `QuoteController.php:1271–1273` |
| B5 | **Server-side totals diverge from stored data.** `recalculateTotals` hard-codes `discountAmount = 0` and VAT at 16%, ignoring stored `discount_amount`, `vat_percentage`, `vat_enabled`. Discount math exists only in the frontend. | `QuoteController.php:684–706` |
| B6 | **Draft costs price the quote.** `getBudgetMaterialPrice` deliberately reads *draft* (unapproved) budget additions for unit prices. | `QuoteController.php:740–742` |
| B7 | **Margins computed but never enforced.** Per-category margins validate `min:0`; `overallMarginPercentage` is calculated and returned but no floor, no block, no routing decision uses it. | `QuoteController.php:67–71`, `:709` |
| B8 | **Payment progress can measure against an unapproved quote.** `resolveQuoteBasis` falls back to the *latest* `TaskQuoteData` (approved preferred, but any latest accepted) when `client_approved_quote` is unset. | `FinanceService.php:146–183` |
| B9 | **One-gate approval, no routing.** A single permission approves any quote at any value, discount, or margin. No value bands, no parallel review, no SLA/escalation (stagnant approvals sit forever). | `ApproveQuoteAction.php:28` |
| B10 | **Excel path bypasses the cost model.** Excel-mode quotes carry a declared amount that Finance can override at approval — the override becomes the financial anchor with no itemized basis. | `QuoteController.php:1693–1711` |
| B11 | **Uploaded Excel is never parsed.** The workbook is stored as an opaque blob; `quote_amount` is typed separately by the uploader and nothing reconciles the typed figure against the spreadsheet's own total. | `QuoteController.php:1881–1948` |
| B12 | **Quote files are publicly accessible.** Excel quotes (client pricing) are stored on the `public` disk and served via unauthenticated `Storage::url` links. Mimetype list also admits `application/octet-stream`/`text/plain` — effectively any file. | `QuoteController.php:1886, 1898, 1959` |
| B13 | **Excel removal doesn't invalidate approval.** Replacing an approved Excel quote correctly resets the approval (`:1921–1933`), but *removing* it reverts to built-in mode while `approval_status=approved` and `client_approved_quote` survive. Removal also deletes the current file that a `QuoteVersion` snapshot may still reference (dangling audit trail). | `QuoteController.php:1971–1997` |
| B14 | **Approval reset doesn't reopen the approval task.** When a re-upload invalidates an approved quote, the already-completed `quote_approval` task stays completed and Finance is not notified — the invalidation is invisible unless someone looks. | `QuoteController.php:1921–1933` |

### C. Missing capabilities (the actual "billing module" gap)

| # | Gap | Consequence |
|---|-----|-------------|
| C1 | **No invoices.** No client invoice model, numbering, PDF, or delivery. `EnquiryPayment` rows are reconciled against a bare number. | No auditable AR; disputes unresolvable |
| C2 | **No billing schedule.** Deposit/milestone/completion terms exist nowhere; the only "term" is the hard-coded 70% mobilization constant. | Premature/late collection; no visibility of what's due when |
| C3 | **No payment allocation.** Payments have no currency, no invoice linkage, no uniqueness on `transaction_reference`. Duplicates are undetectable; overpayment is a flag, not a credit. | Revenue leakage, reconciliation pain |
| C4 | **No change-order billing.** `BudgetAddition` tracks cost-side additions and `syncScope` mutates the quote, but nothing generates a supplementary approval or bills the delta. Contract value is frozen at `client_approved_quote`. | Scope growth is executed but never billed — the largest leak in the system |
| C5 | **No unbilled-WIP or AR views.** Finance sees payment progress per enquiry, nothing portfolio-level. | Working capital invisible |
| C6 | **No multi-currency.** `amount` is a bare decimal; no currency code or locked FX rate anywhere in quotes or payments. | Silent FX exposure if any non-KES work occurs |

### Assessment of the five flaws in the brief

- *"Isolated data silos / quote data fails to map to billing"* — **confirmed, worse than stated**: there is no billing schedule to map to.
- *"Static approval bottlenecks"* — **partially**: it's not a rigid chain, it's a single gate with a parallel *unsecured* side-channel (A1/A2).
- *"Missing margin guardrails"* — **confirmed** (B7): margin is computed and displayed, never enforced.
- *"Revenue leakage from scope changes"* — **confirmed** (C4): scope sync rewrites the quote post-approval (A4) while billing stays anchored to the old number.
- *"Vague milestone triggers"* — **confirmed in the extreme** (C2): there are no milestones to trigger.

---

## 4. Target Architecture

### 4.1 Principles

1. **One approval pipeline, policy-backed.** Follow the `OvertimePolicy` pattern already established in HR: a Laravel Policy backed by Permissions, not free-text approver strings.
2. **Approve versions, not blobs.** An approval references an immutable `QuoteRevision`; editing an approved quote creates a new revision that *supersedes* and requires re-approval.
3. **Ledger-as-truth for money.** Reuse the Petty Cash lesson: invoices and payment allocations are the source of truth; progress/gates are *derived*, never stored ad hoc.
4. **Contract value = approved quote + approved change orders.** One derived function, used by the gate, dashboards, and billing.
5. **Guardrails at write time, on the server.** Totals, margins, and discounts are recomputed server-side; client-sent totals are advisory only.

### 4.2 Module layout

```
app/Modules/Projects/Quotation/            ← extract from QuoteController
  Services/QuoteService.php                  (prep, budget import, totals — single recompute)
  Services/QuoteApprovalService.php          (the one approval pipeline)
  Models/{Quote, QuoteRevision, QuoteApproval, ApprovalRule, ChangeOrder}
  Policies/QuotePolicy.php

app/Modules/Finance/Billing/               ← new, sibling of PettyCash
  Services/BillingScheduleService.php        (generate schedule from accepted quote)
  Services/InvoiceService.php                (draft/issue/void; numbering; PDF via HrPdfDocument pattern)
  Services/PaymentAllocationService.php      (allocate EnquiryPayment → Invoice)
  Models/{BillingSchedule, BillingMilestone, Invoice, InvoiceLine, PaymentAllocation}
  Services/ArReportService.php               (AR aging, unbilled WIP)
```

### 4.3 Quote state machine

```
draft ──submit──> pending_review ──[rules route]──> approved ──send──> client_accepted ──> converted
  ▲                    │                                                (locks revision,
  └──rework────── rejected                                               spawns billing schedule)
                                    any edit after `approved` ⇒ new revision (status: draft, supersedes vN)
```

- `pending_review` fan-out is driven by an `approval_rules` table: value bands, discount %, and computed margin decide required approvals (finance always; management above threshold; parallel steps allowed, each an `approvals` row with status + SLA deadline).
- Escalation: scheduled command flags approvals past `sla_hours` and notifies the approver's lead (NotificationService already exists).
- Margin guardrail: `QuoteService::recomputeTotals()` (server-side, honoring discount + VAT flags) computes `overallMarginPercentage`; below the configured floor → submission blocked unless the submitter holds `QUOTE_MARGIN_OVERRIDE`, in which case the quote is flagged and force-routed to management.

### 4.4 Billing engine

- **On `QuoteClientAccepted`**: `BillingScheduleService` materializes milestones from the quote's payment terms (default: 70% mobilization + 30% completion, i.e., today's behavior becomes *data*, not a constant). Term templates support deposit %, milestone-on-task-completion, and completion balance.
- **Milestone triggers**: `BillingMilestone.trigger` ∈ {`on_acceptance`, `on_task_completed:<type>`, `on_project_completed`, `manual`}. Task/project completion events (already emitted by `UpdateTaskStatusAction` / `CompleteProjectAction`) mark milestones *billable* and draft an invoice — issued only by a Finance user (human-in-the-loop, no auto-send).
- **Payments**: keep `EnquiryPayment` as the cash record; add `currency`, `fx_rate` (locked at quote acceptance), unique index on (`transaction_reference`,`payment_method`), and `PaymentAllocation` rows to invoices. Overpayment becomes unallocated credit.
- **Finance gate**: `ReleaseFinanceGateAction` reads `contractValue()` (approved quote + approved change orders) and allocated payments. Override requires a dedicated permission *and* a note; `FinanceService::releaseProject()` is deleted and its callers pointed at the gate action.
- **Dashboards**: AR aging (issued − allocated, bucketed) and Unbilled WIP (billable milestones + approved change orders − invoiced) per project and portfolio — this is also the Client-360 finance tab feed the CS module needs.

### 4.5 Change orders (closes the biggest leak)

```
Scope/budget delta post-acceptance
   └─> ChangeOrder (draft): links scope items + cost delta + price delta (margin rules re-applied)
        └─> same QuoteApprovalService pipeline (value-banded routing)
             └─> approved: contract value += delta; BillingSchedule gets a CO milestone
             └─> client acceptance recorded before execution tasks unlock
```

`syncScope` on a `client_accepted` quote no longer mutates the quote — it drafts a ChangeOrder instead.

### 4.6 Excel-first quote intake — ❌ withdrawn (kept for reference)

> **2026-07-06:** This design was implemented and then reverted by product decision — the business wants the simple upload (any file + typed amount, no template). Kept for reference in case structured intake is revisited; the enforcement ideas below (margin floor, routing) move to Phase 1 using the builder quote's data instead.

<details><summary>Original design (withdrawn)</summary>

Excel upload is currently the *main* way quotes enter the system, with the in-system builder as the future default. The design principle that makes both work — and makes the migration a UI swap instead of a rewrite:

> **Parse, don't store. One canonical quote model, two input channels.**

Every quote, whether uploaded or built in-system, lands as the same canonical `QuoteRevision` (line items per category, margins, discount, VAT, totals) with a `source` field (`excel | builder`). Approval, margin guardrails, billing, and materials import consume the canonical model and never care where it came from.

```
                       ┌──────────────────────────────────────────────┐
 Standard template ──> │ Upload .xlsx                                 │
 (downloaded from      │   1. store PRIVATE disk + signed URLs        │
  system, named        │   2. PARSE server-side (maatwebsite, sheet-  │
  ranges/columns)      │      pinned — see HR import gotcha)          │
                       │   3. build canonical QuoteRevision rows      │
                       │   4. server recomputes totals                │
                       │   5. RECONCILE vs typed amount (±0.5% else   │
                       │      block/flag)                             │
                       │   6. margin check vs approved budget cost    │
                       └───────────────┬──────────────────────────────┘
                                       │ parse failed?
                                       ▼
                       ┌──────────────────────────────────────────────┐
                       │ Attachment-only fallback lane (demoted):     │
                       │  requires keyed category subtotals + VAT +   │
                       │  discount; margin check on subtotals; flag   │
                       │  "unstructured" → forced Finance review step │
                       └───────────────┬──────────────────────────────┘
                                       ▼
                     Same approval pipeline · same billing engine
```

**Intake rules**

1. **Published template.** The system serves a versioned quote workbook (sections: materials / labour / expenses / logistics; columns: description, qty, unit cost, margin %, line total; summary block: discount, VAT, grand total). Named ranges make parsing deterministic; a template-version cell tells the parser which mapping to use.
2. **Server-side parsing on upload.** Use `maatwebsite/excel` (already a dependency) with the sheet explicitly pinned (`WithMultipleSheets` — the bulk-employee-import lesson: hidden sheets pollute `ToCollection`). Parsed rows populate the canonical revision; server recomputes all totals — the workbook's own formulas are advisory.
3. **Reconciliation gate.** Typed `quote_amount` vs parsed grand total: within tolerance → proceed; outside → submission blocked with a diff view (or flagged for Finance override with permission). This kills the "typed number becomes the financial anchor" hole (A3/B11) for the primary channel.
4. **Margin intelligence in Excel mode.** Parsed (or keyed-fallback) cost basis is compared against the approved `TaskBudgetData` baseline to compute implied margin; the same floor + routing rules apply as for builder quotes. Excel mode stops being a guardrail bypass.
5. **Secure storage.** Private disk, streamed download behind auth + policy, signed URLs for the frontend, real extension/content validation (drop `octet-stream`/`text/plain`). Each revision snapshot copies the file (immutable per revision); files referenced by a version are never deleted.
6. **Lifecycle correctness.** Re-upload after approval: invalidate approval (exists today), **and** reopen the `quote_approval` task, notify Finance (NotificationService), and record a `GovernanceAuditLog` row. Removal of an approved Excel quote is forbidden — the path is a new revision or a change order, never deletion.
7. **Promotion path (the migration runway).** A parsed Excel quote can be "promoted" into builder mode — its canonical rows are already the builder's data model, so the user just starts editing. Rollout metric: % of uploads that parse cleanly and % promoted; when parse success is stable and the builder covers the gaps users cite, flip the default so Excel becomes an **export** (client-facing document generated from the system quote) rather than an input. The endpoint set doesn't change.

**Why this is the "fundamentally intelligent" shape:** intelligence lives in the canonical model (parsing, reconciliation, margin inference, budget linkage), not in the input UI. The Excel channel gets the full guardrail stack on day one, and the eventual system-generated default inherits everything with zero migration of approval or billing logic.

</details>

### 4.7 Interdependence matrix (grounded)

| Module | Upstream dependency | Downstream impact | Automation trigger |
|---|---|---|---|
| Quote Prep (`QuoteService`) | `TaskBudgetData` (approved additions only), MaterialsLibrary rates | Approval routing, margin analytics | Save → server recompute + margin check |
| Quote Approval (`QuoteApprovalService`) | Pinned `QuoteRevision`, `approval_rules` | Job number, `BillingSchedule`, project activation path | Final approval → `QuoteApproved`; client acceptance → `QuoteClientAccepted` |
| Change Orders | Accepted quote, scope deltas (`syncScope`) | Contract value, billing schedule, task unlock | Post-acceptance scope edit → draft CO |
| Billing (`Finance/Billing`) | Milestones, task/project completion events | AR aging, unbilled WIP, finance gate, GL (ChartOfAccounts) | Milestone trigger → draft invoice; allocation → gate re-eval |
| Finance Gate | Invoices + allocations, contract value | `ProjectActivated`, production start | Allocation ≥ threshold → auto-clear; else manual w/ permission + note |

---

## 5. Data Model Changes

```sql
-- new
quotes (id, enquiry_id, current_revision_id, state, currency, fx_rate_locked_at, ...)
quote_revisions (id, quote_id, number, payload_json, totals_json, margin_pct, created_by, superseded_by_id)
quote_approvals_v2 (id, quote_revision_id, step, approver_id FK users, status, sla_deadline, acted_at, comments)
approval_rules (id, band_min, band_max, max_discount_pct, min_margin_pct, required_role, parallel_group)
change_orders (id, quote_id, revision_delta_json, price_delta, status, client_accepted_at)
billing_schedules (id, enquiry_id, quote_revision_id, currency, fx_rate)
billing_milestones (id, schedule_id, label, trigger, pct, amount, status: pending|billable|invoiced)
invoices (id, enquiry_id, number, currency, amount, tax, status: draft|issued|part_paid|paid|void, due_date)
invoice_lines (id, invoice_id, milestone_id NULL, change_order_id NULL, description, amount)
payment_allocations (id, enquiry_payment_id, invoice_id, amount)

-- amended
enquiry_payments: + currency, fx_rate, UNIQUE(transaction_reference, payment_method)
-- deprecated (migrate then drop)
quote_approvals (raw table), enquiries.client_approved_quote → derived contractValue()
```

---

## 6. Phased Plan

**Phase 0 — Integrity hardening (no schema changes, ~1 week)** — ✅ **completed 2026-07-05**
1. ✅ `saveQuoteData` no longer accepts `approved`/`rejected` status; approval endpoints set `approved_by`/`approval_date` from the authenticated session, client values ignored (A1, A2).
2. ✅ `saveApproval`/`submitApproval` decisions (approve/reject) require `FINANCE_QUOTE_APPROVE` → 403 otherwise; saving `pending` state remains open (A2, A8).
3. ✅ Payment update/delete scoped to the route enquiry: `$enquiry->payments()->findOrFail($paymentId)` (A5).
4. ✅ Approved quotes are locked: `saveQuoteData`, `importBudgetData`, `smartMergeBudget`, `syncScope`, and `restoreVersion` all return 422 while `approval_status = approved`; `restoreVersion` additionally never restores approval fields from snapshots (A4, B3 partial).
5. ✅ `FinanceService::releaseProject()` deleted — the only route caller already used `ReleaseFinanceGateAction` (A6).
6. ✅ `ActivateProjectAfterFinance` rethrows after logging, so a failed activation rolls back the finance release transaction instead of committing divergent state (A7).
7. ✅ `recalculateTotals` honors stored `discount_amount`/`vat_percentage`/`vat_enabled` (B5); quote pricing reads **approved** budget additions only, drafts excluded (B6).
8. ✅ **Done (2026-07-05).** Excel quote files stored on the private `local` disk; downloads via the `signed`-middleware route `quote.excel.download` with short-lived signed URLs (legacy public-disk files served through the same route as a fallback); mimetypes tightened to `xlsx,xls,csv,ods` (B12).
9. ✅ **Done (2026-07-05).** Re-upload over an approved quote now resets the `quote_approvals` row, reopens the `quote_approval` task, writes a `GovernanceAuditLog` entry, and notifies Finance + the Project Officer (`NotificationService::sendQuoteApprovalInvalidated`) (B14). `removeExcelQuote` returns 422 for approved quotes, and files referenced by a `QuoteVersion` snapshot are never deleted (B13). Covered by `tests/Feature/Projects/ExcelQuoteLifecycleTest.php`.

Phase 0 test coverage: `tests/Feature/Projects/ExcelQuoteLifecycleTest.php` + `tests/Feature/Projects/QuoteApprovalIntegrityTest.php` (11 tests). Legacy public-disk files are relocated by `php artisan quotes:migrate-excel-files` (supports `--dry-run`); run it once per environment after deploying.

Deployment checklist for each environment:
- `php artisan migrate`
- `php artisan quotes:migrate-excel-files`

Still open from the flaw list after Phase 0: A3/B11 (request-supplied `quote_amount` becomes `client_approved_quote` at approval — now permission-gated, full verification moved to Phase 1), B1/B2 (dual approval systems), B4, B7 (margin floor), B8, B9 — all Phase 1.

**Phase E′ — Advisory intelligence on the simple upload** — ✅ **shipped 2026-07-06**
Intelligence without a template, on the flow the business chose (any file + typed amount). Everything is a *signal*, never a gate:
1. ✅ `QuoteInsightsService` (`app/Modules/Projects/Services/`): best-effort **workbook total detection** (scans any spreadsheet for "total"-labelled rows, takes the largest) → `amount_match ∈ matched|mismatch|unknown` vs the typed amount (1% tolerance).
2. ✅ **Budget-vs-quote margin**: implied margin of the typed amount (net of VAT) against the enquiry's budget baseline (`TaskBudgetData.budget_summary.grandTotal`, approved budget preferred) → `margin_flag ∈ healthy|below_watch_floor(15%)|loss|no_budget`.
3. ✅ Insights stored per upload (`task_quote_data.excel_quote_insights`, json) and returned with human-readable non-blocking `advisories`; also included in all quote responses.
4. ✅ **Approval decision context**: `GET /api/projects/tasks/{taskId}/approval` (route newly added — `getApprovalData` was previously unrouted dead code) returns `financialContext` recomputed *at decision time*: budget cost, implied margin, margin flag, and the 70% mobilization amount.
5. ✅ **B8 fixed** (billing connection): `FinanceService::resolveQuoteBasis` no longer falls back to unapproved quote data — basis is `client_approved_quote` → approved system quote → `none` (total 0, gate release then requires justification).

Tests: `tests/Feature/Projects/ExcelQuoteInsightsTest.php` (4 tests: detection+margin, mismatch/loss advisories non-blocking, fresh approval context, approved-only payment basis).
Note: `submitApproval` (`QuoteController.php`) is unrouted dead code — delete during the Phase 1 pipeline merge.

**Internal budget approval removed (2026-07-08):**
- Analysis showed the "Internal Budget approved" step gated nothing: quote import never checked it, budget task completion never checked it, and procurement accepted "task completed" as an alternative — while the budget task auto-completes on save. Pure ceremony, removed by product decision.
- Removed: `/budget/submit-approval|approve|reject` routes, `BudgetController::approve/reject/submitForApproval/canApproveBudget/approvalResponse`, `BudgetService::approveBudget/rejectBudget/submitForApproval/ensureBudgetReadyForApproval/formatApproval`, the frontend approval rail + service methods. Budget status is now `draft` (legacy values preserved on old rows; `budget_approvals` table kept for history).
- Re-keyed consumers: procurement import and the Petty Cash budgets summary/stats/items now key on **completed budget task** instead of `status='approved'` (legacy approved rows still pass).
- Tightened in exchange: budget task completion (incl. auto-complete) now requires a **priced** budget (`grandTotal > 0`) — previously the `TaskBudgetData` model hook completed the task on any save, even an empty budget, unlocking procurement. Kept: materials PO/Production approvals before import into budget; BudgetAddition approvals for quote pricing.

**Budget Comparison tab removed (2026-07-08):**
- Removed the "Comparison" tab from the Budget task (both the read/write view and the read-only `BudgetDataDisplay`) — variance-vs-baseline analysis (materials-preview vs. Master MQ, or vs. a prior `BudgetVersion` snapshot) with its own audit-PDF export.
- Frontend: deleted `BudgetComparisonTab.vue`; removed the `comparison` tab entry from `useBudgetOperations.ts`, the tab icon mapping, and the template/import wiring in `BudgetTask.vue` and `BudgetDataDisplay.vue`; removed the now-dead "Review" shortcut button in `BudgetMaterialsTab.vue`'s materials-updated banner (kept "Sync").
- Backend: removed `GET /budget/materials-preview` route + `BudgetController::getMaterialsPreview` + `BudgetService::getMaterialsPreview`; removed the `type=audit` branch of `BudgetController::downloadPdf` + `BudgetService::generateAuditReportData` + `BudgetService::analyzeStandardVariances`; deleted `resources/views/reports/budget_audit.blade.php`. `analyzeMaterialVariances` (shared with the live materials-update-check banner) and `transformMaterialsToBudget` (shared with import) were kept — both have other live callers.
- Not touched (separate, already-dead mechanism, out of scope): the `comparison_version` query param on the *standard* budget PDF (`downloadPdf`/`reports.budget` view) has no frontend caller either, but is a distinct code path from the removed Comparison tab.
- Verified: full Projects test suite green (36 tests) and frontend type-check shows no new errors.

**Quote completion gate (2026-07-07):**
- The Quote Preparation task can no longer be marked complete until the quote's `approval_status` is `approved` (`EnquiryWorkflowService::validateTaskCompletion`, admin override retained). No deadlock with the `quote_approval → quote` task dependency: the approval *decision* (`saveApproval`) is an API write not blocked by task ordering, and the `TaskQuoteData` observer auto-completes the prep task the moment approval lands. Side effect: an Excel upload alone no longer auto-completes the task (previously data presence sufficed). Partially advances B4's rework loop — a rejected quote now leaves the prep task open. Test in `ExcelQuoteLifecycleTest`.

**Download fix + upload UX (2026-07-07):**
- Signed downloads switched to **relative signing** (`absolute: false` + `signed:relative` middleware): the signature covers path+query only, so links work from any browser origin (Vite dev proxy, production host) regardless of `APP_URL`. Root cause of "download not working": absolute URLs were bound to `erp-backend.ddev.site` while the browser ran on the Vite origin.
- The upload form **collapses after a successful upload**; the current-file card gains a "Revision" button to reopen it.
- A required "file type" select (quotation/render/presentation/…) was briefly added, then **removed by product decision (2026-07-07)**: quote-task uploads are always the quotation, so the field was pure friction with no consumer. Renders/presentations belong to the Design Assets module, which has its own category system. If mixed uploads ever land on the quote task, revisit with a type field that gates the financial anchor (only `quotation` files carry an amount).

**Amount auto-fill + version cleanup (2026-07-06):**
- `POST /quote/inspect-excel` inspects a workbook *without storing it* (reuses `QuoteInsightsService::detectWorkbookTotal`); on file select the frontend pre-fills the Total Quote Amount field from the detected total ("Auto-filled from the workbook's total — adjust if needed"), fully editable.
- Version cleanup: `DELETE /quote/versions/{versionId}` (single) and `DELETE /quote/versions` (clear all). Both are blocked while the quote is approved (history backs the approval), write `GovernanceAuditLog` entries, and delete snapshot files only when orphaned (not the current file, not referenced by another remaining version). UI: per-row trash + "Clear all" in the versions panel.
- Tests: `tests/Feature/Projects/ExcelQuoteVersionCleanupTest.php` (3).

**Frontend wiring (2026-07-06):** `QuoteTask.vue` and `QuoteApprovalTask.vue` had been built against the withdrawn template contract (`parse_status`, `implied_margin`, reconciliation panel, a "Download template" button hitting the deleted endpoint) — this was why the intelligence "didn't work": stale fields rendered nothing. Both realigned to the insights contract: upload shows `advisories[]` (Review Suggestions) + amount-match/margin badges + workbook total/budget cost/70% target line; the approval screen fetches `GET /approval` and renders a Financial Context card (budget cost + status, net excl. VAT, mobilization target) with `financialContext` preferred over upload-time insights. Old uploads (insights `NULL`) show neutral "Amount unverified / No budget baseline" badges.

**Phase E — Template-based intake** — ❌ **withdrawn by product decision (2026-07-06)**
A template-based parse-and-reconcile intake (versioned workbook template, server-side parsing, typed-amount reconciliation, implied margin) was built and shipped on 2026-07-05, then **reverted on 2026-07-06**: the business prefers the simple flow — upload any Excel file + typed amount, no template download. The Phase 0 hardening around the upload (private disk, signed URLs, tightened mimetypes, approval invalidation/reopen on re-upload, removal rules) **remains in place**.

Consequence: A3/B11 stays open on the Excel path — the typed `quote_amount` is unverified against the file's contents. Mitigations are now (a) the `FINANCE_QUOTE_APPROVE` permission gate on the decision, and (b) Finance's amount override at approval being an explicit, audited human check. Phase 1's approval pipeline should treat the Excel amount as *declared, human-verified* rather than machine-verified.

**Phase 1 — Unified approval pipeline (~2–3 weeks)**
Extract `QuoteService`/`QuoteApprovalService`; introduce `QuoteRevision` pinning; merge the two approval systems (enquiry-level action becomes the *final step* of the one pipeline, resolving B1/B2); rejection creates a rework revision (B4); margin floor + `approval_rules` routing + SLA escalation (B7, B9); Finance amount-confirmation step for Excel quotes (A3/B11 mitigation).

**Phase 2 — Billing engine (~3–4 weeks)**
`Finance/Billing` module: schedules, milestones, invoices (reuse the HR PDF base-document pattern), payment allocation, AR aging + unbilled WIP dashboards; finance gate reads allocations (C1–C3, C5).

**Phase 3 — Change orders & multi-currency (~2 weeks)**
ChangeOrder pipeline wired into `syncScope`/`BudgetAddition`; contract-value derivation; currency + locked FX on quotes and payments (C4, C6).

---

## 7. Risks & Migration Notes

- `client_approved_quote` is read by Production observer, CS profile, and EnquiryResource — keep it as a **derived, denormalized column** updated by `contractValue()` during Phases 1–2 rather than dropping it immediately.
- Excel-mode quotes have no itemized basis (template parsing was withdrawn — see Phase E note); the Excel amount is a declared figure confirmed by Finance at approval. Phase 1 should model it as a single-line revision so it flows through the same pipeline without special-casing.
- The migration to system-generated quotes is deliberately *not* a phase: once intake is canonical, flipping the default from upload to builder is a frontend change gated on parse-success metrics, with Excel retained as a client-facing export.
- Feature tests require MySQL `db_test` (migrations are MySQL-only); every Phase 0 fix should land with a Feature test proving the previously-open hole is closed.
