# 01 — WNG ERP Finance & Accounts Module: Phase 1 Executive Summary

**Audit date:** 2026-09-22 · **Scope:** Read-only discovery, tracing, and documentation of the
current Finance & Accounts implementation across `ERP-Backend` and `ERP-Frontend`. No code was
modified, no schema changed, no migration run, no calculation altered. This document synthesizes
the twelve supporting documents in this folder; each claim below is backed by file:line evidence
in the referenced document.

**Important context for the reader.** A prior audit dated 2026-07-21 (`docs/finance-module-audit.md`
in this repo) concluded there was "no invoicing, no AR, no double-entry." **That conclusion is
stale.** In the two months since, WNG's engineering team built a real general ledger: double-entry
journals, client invoicing with revenue recognition, VAT/WHT schedules, month-end close, bank
reconciliation, and a unified payment architecture are all implemented, routed, and covered by
automated tests in the repository. Repository evidence cannot establish production usage. This
Phase 1 audit verified the implementation fresh, as it exists today, and found a
system considerably more complete and more carefully engineered than its own prior documentation
suggests — alongside a specific, well-evidenced set of gaps that matter.

---

## Current Finance Architecture

Finance is a Laravel module (`app/Modules/Finance`, ~150 backend files) paired with a Vue 3
frontend module (`src/modules/finance`, ~140 files), organized into nine functional sub-areas: Cost
Collector (project cost capture/verification/budget-vs-actual), Petty Cash (a full requisition→
disburse→surrender→reconcile lifecycle with its own hash-chained ledger), General Ledger/Journals
(double-entry, single posting funnel), Receivables/Client Billing, Reconciliation (bank statement
import and matching), Reports (P&L, Trial Balance, AR/AP ageing), Tax (VAT/WHT schedules with
effective-dated rates), a unified Work Queue, and Periods (month-end close). A tenth area —
Payments, Payment Sources & Spend/Payment Vouchers — implements the maker/checker/poster pattern
that should be the template for the rest of the module.

Two structural facts matter for anyone approaching this module fresh: **(1)** client billing's
entire HTTP surface (invoices, receipts, credit notes) is served by `Projects\EnquiryController`,
not any controller inside Finance, even though the accounting logic itself correctly lives in
`Finance\Services\ReceivablesPostingService` — a Finance-scoped code review will miss this entirely
unless it knows to look. **(2)** WNG's chart of accounts exists in two forms: a seeded IFRS-style
reference chart (configured not to seed when the application environment is `production`) and a
documented 123-account mnemonic chart imported from QuickBooks — and the translation layer between
the two (`ChartAccountMap`) is built but only
adopted at 1 of 13+ call sites that need it. See `02_FINANCE_MODULE_INVENTORY.md` and
`03_CURRENT_NAVIGATION_MAP.md` for the full inventory and navigation trace.

## What Works

- **Double-entry accounting is real and enforced.** A single posting funnel
  (`JournalPostingService::postBalancedEntry()`) asserts debit=credit before every write, wrapped in
  a DB transaction, gated on an open accounting period. Journals are generated exclusively by
  domain events — there is no manual journal-entry UI, closing off an entire class of ledger
  tampering by design.
- **Financial period locking is genuinely enforced at the service layer**, not just hidden in the
  UI — every posting path this audit traced calls the equivalent of `assertOpenPeriod()`, with a
  row-locked re-check immediately before the final spend-voucher post specifically to close a
  create-then-post race.
- **Supplier-bill three-way match is tamper-evident**, not just tamper-logged: a sha256 fingerprint
  of every checked fact (order approval, quantities, prices, invoice number) silently invalidates
  verification the moment any of those facts change after sign-off.
- **Project margin is computed live from posted ledger transactions**, not a cached or duplicated
  figure — cost and budget share one table (`cost_lines`), so variance is a `GROUP BY`, never a
  reconciliation between two systems that can drift.
- **Money handling is decimal-consistent throughout** (no float/double bugs found), **tax rates are
  100% data-driven and effective-dated** (resolved by transaction date, not today's date), and
  **balance-mutating writes are consistently locked inside transactions** — several assumptions a
  typical first-pass ERP audit would expect to fail here did not.
- **The SpendVoucher (Payment Voucher) workflow** implements a genuine three-permission
  maker/checker/poster control with identity-level self-approval blocks at every stage — this is
  the strongest control pattern found anywhere in the module and should be the template for
  Payroll and Bill verification, which currently fall short of it.
- **A full, well-guarded credit-note workflow exists** for client invoices, and a previously-known
  risk class (GRN-accrual double payment) is already fixed at four independent layers.

## Major Problems

- **Client billing's backend lives outside the Finance module entirely** (invoice/receipt/credit-
  note HTTP endpoints are owned by `Projects\EnquiryController`, 2,067 lines) — an architectural
  problem, not a user-facing one, but one that will hide this surface from future Finance-scoped
  reviews.
- **The Purchase Order document has no meaningful protection after approval**: `update()` has no
  status check at all, and `destroy()`'s pending-only guard is commented out in the source, with
  cascading deletes reaching already-paid Bills and GRNs.
- **Twelve GL account codes are hard-coded as literal numbers** in the posting service, bypassing
  the configurable translation layer built to avoid exactly this — and WNG's real chart likely does
  not carry most of these codes (see Critical Risks, below).
- **Payroll and salary advances create no independent money-movement record** — the business
  document's own status fields stand in for a real Payment row, making WNG's largest recurring cash
  outflow invisible to every Payment-based control in the system.
- **Two independently-written ledgers exist for petty cash** (the module's own ledger and the
  general ledger), and the coupling between them at disbursement time silently swallows posting
  failures.
- **Three different, disagreeing "what does the client still owe" figures** exist across three
  screens, two of them both simply labelled "Outstanding."
- **Project margin systematically excludes labour cost** — payroll is split by department only,
  never attributed to a job, and the code's own author declined to invent an allocation rather than
  report a fictional number. This is a real, acknowledged blind spot, not a bug.

Full evidence for every item above, plus roughly 60 further findings ranging from data-model
duplication to navigation dead-ends, is in `04_CURRENT_DATA_MODEL.md` through
`09_REPORT_AND_DASHBOARD_AUDIT.md`.

## Critical Risks

Seven findings meet the CRITICAL bar (could corrupt financial records, create incorrect balances,
bypass authorization, or materially damage accounting integrity). Full evidence in
`10_FINANCE_RISK_REGISTER.md`:

1. **Chart-of-accounts mismatch** — hard-coded reference-chart codes are not present in the
   documented mnemonic chart, meaning VAT/WHT/Inventory/Accrued/Payable/bank-charge/PAYE postings
   would fail if that documentation matches the deployed database. This is the single
   highest-priority item to verify against
   the live database before anything else in this audit is acted on.
2. **Purchase Order `update()`** has no status check — an approved, paid PO can be silently
   rewritten.
3. **Purchase Order `destroy()`**'s guard is commented out, and cascading deletes can wipe an
   already-paid Bill's GL trail.
4. **Two journal-posting paths bypass the debit=credit assertion** (`postSupplierInvoice()`,
   `reverseEntry()`) — the one gap that could theoretically let an unbalanced entry reach the
   ledger.
5. **Petty-cash GL-posting failures are silently swallowed** (`catch (\Throwable) { Log::warning }`)
   after cash has already left the tin, with no automated reconciliation to catch the divergence.
6. **A live, routed endpoint can hard-delete all petty-cash financial history** in one call — Super
   Admin-gated, but this capability should not exist in a production financial system at all.
7. **Payroll's cash-out creates no `Payment` record** — WNG's largest recurring outflow is
   untraceable through every Payment-based control.

## Duplication

- **Financial totals stored and independently writable in more than one place**: petty-cash
  running balance vs. its own ledger snapshot; a bill's cached `paid_amount`/`balance` vs. the sum
  of its payments; two live settlement-linking tables (`spend_voucher_allocations` and
  `payment_allocations`) doing the same job.
- **Two settlement engines** independently implement "how does money actually leave the business"
  — one for ordinary payments, a separate, unaudited-against-the-first one for petty cash.
- **Two "budget" concepts** (Finance's Cost Accounts vs. Projects' quote-approval Budget task)
  share a permission-name prefix and no other connection.
- **A fourth parallel money-movement table** (`CashMovement`) exists alongside Payment, BillPayment,
  and ClientReceipt — each occupies a genuinely distinct niche, but "every cash movement across the
  business" requires a four-table union.
- **Five different words** (`reversed`/`voided`/`cancelled`/`void`/workflow-`rejected`) mean "this
  transaction no longer counts," depending which table you're looking at.

Full detail in `04_CURRENT_DATA_MODEL.md` §"Cross-cutting ISSUEs" and
`11_KEEP_REDESIGN_MERGE_REMOVE_MATRIX.md`.

## Missing Controls

- No approver-initiated rejection path exists for Spend/Payment Vouchers — an approver can only
  withhold approval indefinitely, never formally refuse.
- Segregation of duties for every Finance transaction type depends on `Accounts`/`Admin` headcount,
  not role boundaries — one role holds create+approve+post+reverse+period-close simultaneously,
  mitigated only by identity-level runtime checks.
- Bank reconciliation match/unmatch/ignore actions hard-delete match history — no record survives
  of what a statement line used to be matched to.
- No generic, FK-backed attachment mechanism exists for Finance at all; supplier bills and purchase
  orders cannot attach the underlying invoice scan, a real exposure for KRA input-VAT defensibility.
- Audit logging is split across three disconnected, ad-hoc mechanisms, one of which (`HRAuditLog`)
  is borrowed cross-module by Finance from HR with no dedicated Finance-named equivalent.

Full detail in `07_APPROVAL_AND_PERMISSION_MATRIX.md`.

## Integration Problems

- **Logistics vehicle-maintenance costs never reach Finance** — the module's own UI claims a cost
  is "Sent to finance for approval," but nothing about approving it creates a Bill, CostLine, or
  journal entry; someone must re-type the same figures into Procurement/Bills for the vendor to
  actually be paid.
- **Assets carry no link to their acquisition cost** — equipment/vehicle purchases are recorded
  once in Procurement to pay for them and, if anyone bothers, a second time in the Assets register,
  with nothing tying the two together.
- **No company-wide financial dashboard exists** — revenue, cash position, and outstanding balances
  are not glanceable anywhere outside Finance's own report screens.

Full detail in `08_CROSS_MODULE_INTEGRATION_MAP.md` and `09_REPORT_AND_DASHBOARD_AUDIT.md`.

## Decisions Required from WNG

Seventy-eight specific questions are consolidated in `12_WNG_CONFIRMATION_QUESTIONS.md`, organized
by Management, Finance/Accountant, SOP/Process, and Technical. The five that most directly gate
whether the rest of this audit's findings are urgent or theoretical:

1. Does WNG's live chart of accounts actually carry the twelve reference-chart codes
   `JournalPostingService` looks up directly — and specifically, do `2200` (Client Deposits) and
   `2110` (Output VAT Payable) exist? This single fact determines whether Critical Risk #1 and the
   "three disagreeing Outstanding figures" problem are already happening in production today.
2. Is WNG a KRA-appointed VAT withholding agent? The system has no withholding-VAT implementation
   at all.
3. Is management aware that every current project margin figure excludes labour cost entirely, and
   is capturing labour against jobs a near-term priority?
4. Is there a legitimate business reason the petty-cash `clear-all` wipe endpoint needs to exist in
   production?
5. What is WNG's actual current headcount in the `Accounts`/`Admin` roles — this determines whether
   the segregation-of-duties concentration finding is a live risk or a theoretical one.

## Recommended Redesign Scope for Phase 2

Based on this audit, Phase 2 should prioritize, in roughly this order:

1. **Verify and resolve the chart-of-accounts mismatch** (Critical Risk #1) against WNG's live
   database — this is a data/configuration fix, not a redesign, and blocks trusting several other
   findings' urgency.
2. **Close the two Purchase Order control gaps** (#2, #3) and the two journal-posting funnel
   bypasses (#4) — these are the findings capable of actually corrupting the audited GL trail.
3. **Give Payroll and Salary Advances a real `Payment` record**, bringing them up to the same
   business-document/money-movement/accounting-posting separation already correct everywhere else.
4. **Resolve the petty-cash dual-ledger coupling** and remove or redesign the `clear-all` endpoint.
5. **Decide WNG's labour- and logistics-cost attribution policy** (a business decision, not a
   technical one) so project margin can either include these costs or visibly flag their exclusion.
6. **Consolidate the "Outstanding" and settlement-linking duplications** identified throughout this
   audit, using the already-correct patterns (ledger-based margin, single balanced-entry funnel,
   SpendVoucher's permission model) as the template rather than inventing new mechanisms.

This scope, its sequencing, and any target architecture are for Phase 2 to define in detail — this
document stops at describing what exists and what decisions are needed to act on it, per the audit's
governing rule: **document before changing.**

---

*Supporting documents: `02_FINANCE_MODULE_INVENTORY.md`, `03_CURRENT_NAVIGATION_MAP.md`,
`04_CURRENT_DATA_MODEL.md`, `05_CURRENT_WORKFLOWS.md`, `06_ACCOUNTING_POSTING_ANALYSIS.md`,
`07_APPROVAL_AND_PERMISSION_MATRIX.md`, `08_CROSS_MODULE_INTEGRATION_MAP.md`,
`09_REPORT_AND_DASHBOARD_AUDIT.md`, `10_FINANCE_RISK_REGISTER.md`,
`11_KEEP_REDESIGN_MERGE_REMOVE_MATRIX.md`, `12_WNG_CONFIRMATION_QUESTIONS.md`,
`13_TARGET_REDESIGN_INPUTS.md`.*
