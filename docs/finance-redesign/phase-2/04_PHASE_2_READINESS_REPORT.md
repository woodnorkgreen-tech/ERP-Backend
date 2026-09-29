# 04 — Phase 2 Readiness Report

Phase 1.5, Part H. This is the gate between this stabilization/requirements phase and **Phase 2B —
WNG Target Finance Architecture**. It sorts every finding and proposal from this phase into five
buckets so Phase 2B knows exactly what it can start on, what it must wait for, and what it should
not attempt yet.

---

## SAFE TO DESIGN NOW

Requirements are sufficiently established for Phase 2B to begin design work on these, without
waiting on any further WNG input:

1. **A reusable maker/checker/poster approval-chain pattern**, generalized from the existing
   SpendVoucher implementation, that can later be applied to whichever transaction types WNG
   confirms need it (Bill verification, Payroll). The pattern itself does not need to wait for
   `ROLE-1`–`ROLE-3` or `W10-2`/`W10-3` to be designed — only its *application* to a specific
   transaction type does.
2. **Extending the existing ledger-based margin calculation into a portfolio-wide view.** The
   calculation is already correct and proven for materials and invoiced revenue; Phase 2B can
   design the list-view extension now, and simply display "Labour: not included" /
   "Logistics: not included" until `W7-1`/`W8-1` are resolved, rather than waiting to build it at
   all.
3. **The navigation restructuring that has no open decision attached** — moving Reconciliation,
   Money Accounts, Tax, and Accounting Periods under consolidated "Payments & Cash" / "Accounting"
   sections, and standardizing the three-different-labels problem on the Accounting Periods and
   Control Centre screens. None of these specific moves depend on an unresolved question in
   `03_WNG_FINANCE_DECISION_REGISTER.md`.
4. **The proposed functional responsibility model itself** (Requester, Project Officer,
   Procurement, Accounts Preparer, Accounts Reviewer, Finance Approver, Payment Authorizer,
   Management Approver, Reconciler, System Administrator) as a design artifact — Phase 2B can build
   the permission-structure mechanics to support this model now; only the mapping of real people
   onto it (`ROLE-1`) is blocked.
5. **Report-format fixes that don't depend on new source data**: the Trial Balance's Equity
   placeholder, the ledger source-type filter expansion, showing `created_by` on the journal
   drawer, and adding the already-available Treatment/Supplier-invoice/net-paid columns to the tax
   screens. All of these use data the system already has.
6. **A generic Finance evidence/attachment storage pattern** (a real child table instead of a JSON
   column, private-disk storage instead of public, per-file audit metadata) — the *pattern* is safe
   to design now, informed by the fix already proven elsewhere in this codebase for a different
   document type. Its *rollout* to Bills and Purchase Orders specifically is gated on `W2-2`.

## SAFE TECHNICAL FIXES

Confirmed defects from the Phase 1 audit that do not depend on business or accounting policy.
These are the items Engineering can schedule and implement directly — see
`02_CRITICAL_STABILIZATION_PLAN.md` for the seven CRITICAL items in full detail.

**Critical (from the stabilization plan, technical portions only):**
- C1 — route the twelve hard-coded account lookups through `ChartAccountMap::local()` with a
  named failure message (the mapping *values* remain blocked — see below).
- C2 — block `PurchaseOrderController::update()` from editing a non-`pending` PO.
- C3 — reinstate `PurchaseOrderController::destroy()`'s guard and correct the cascading FK
  constraints.
- C4 — route `postSupplierInvoice()`/`reverseEntry()` through the balanced-entry funnel.
- C5 — stop silently swallowing the petty-cash GL-posting exception (visibility portion only).
- C6 — remove the `clear-all` petty-cash wipe endpoint from production reachability (default
  action, pending only the narrow confirmation in `STAB-5`).
- C7 — create a real `Payment` record at payroll `markPaid()` time, forward-only.

**Other confirmed technical defects:**
- Add a scoped uniqueness check preventing the same supplier+invoice-number being recorded as two
  separate Bills.
- Add a uniqueness check on `bill_payments.reference_number`, mirroring the client-side rule that
  already exists.
- Route `PurchaseOrder`/`Bill`/`GoodsReceiptNote` numbering through the already-proven
  `DocumentNumber::next()` mechanism.
- Add `SoftDeletes` (or a supersede marker) to `StatementMatch` so bank-reconciliation match history
  is preserved.
- Implement or formally remove the dangling, handler-less `bills.update()` route.
- Decide (an Engineering-internal call, not a WNG one) whether `spend_voucher_allocations` or
  `payment_allocations` is the authoritative settlement-link table, and retire the other.
- Decide (Engineering-internal) whether to fully commit to `posting_rules` as the live posting
  mechanism or formally deprecate it.
- Migrate `BillController::canVerify()` from a hard-coded role array to a permission constant.
- Extract a genuinely generic `AuditLog` model, ending Finance's cross-module dependency on
  `HRAuditLog`.
- Confirm zero usages, then remove `FinanceContextCard.vue` and `LabourClassificationController`.
- Harden the existing cost-evidence storage: move to the private disk, promote the JSON evidence
  column to a real child table with per-file audit metadata, and add an orphan-cleanup job.
- Add feature tests for SpendVoucher's approve/cancel/post transitions and Bill payment recording,
  the least-covered, highest-complexity money-movement paths in the module.

## BLOCKED BY WNG DECISION

Every row in `03_WNG_FINANCE_DECISION_REGISTER.md` blocks specific downstream design work. Grouped
here by what they block, not repeated in full:

| Decision(s) | Blocks |
|---|---|
| `STAB-2` (WIP accounting policy) | Completing the chart-of-accounts mapping; any Balance Sheet work |
| `STAB-3` / `W2-4` (PO change authority) | Designing the change-order/amendment workflow |
| `STAB-4` (GL-post failure handling) | Finalizing the petty-cash disbursement error-handling design |
| `STAB-5` (petty-cash wipe use case) | Confirms removal is unconditionally safe (default assumption: yes) |
| `STAB-6` / `W10-1` (payroll backfill scope) | Whether a data-migration task is needed alongside the payroll fix |
| `W1-1`–`W1-10` (client billing controls) | The entire Sales & Receivables target design — who does what, what "Outstanding" means, discount/terms handling |
| `W2-1`–`W2-3` (procurement approval/evidence/multi-billing) | Purchases & Payables target design, value-tiered approval |
| `W3-1`–`W3-2` (expense categorization, salary advances) | Expense capture screen scope, salary-advance-to-payment linkage |
| `W4-1`–`W4-2` (voucher rejection, value tiers) | Payment Voucher workflow refinement |
| `W5-2`–`W5-4` (petty-cash approval signals, custodian field, simplified form) | Petty Cash UX refinement |
| `W6-1`–`W6-2` (overhead allocation, portfolio margin priority) | Project profitability definition and rollout timing |
| `W7-1` (labour cost method) | **The single largest blocked design item** — project profitability accuracy, the labour section of Budget vs Actual, and any Cost Collector extension for labour |
| `W8-1`–`W8-2` (logistics integration) | Logistics→Finance link design |
| `W9-1` (asset accounting scope) | Any Asset Finance work at all — explicitly not to be started before this |
| `W10-2`–`W10-3` (payroll control strength, GL-posting authority) | Payroll permission-model redesign |
| `ROLE-1`–`ROLE-3` (functional-role mapping, Accounts tiering, exception-permission ownership) | The entire target permission structure |
| `NAV-1`–`NAV-3` (spend-entry reconnection, Purchases & Payables presence, GL/Reports merge) | Final navigation design for those three specific areas |
| `RPT-1`–`RPT-2` (bank position visibility, company dashboard) | Whether either report/dashboard is built at all |

## BLOCKED BY PRODUCTION DATA VERIFICATION

These require querying or checking live WNG data or configuration — not a policy decision, and not
a code change, but a verification step that must happen before certain other items can be acted on
with confidence.

1. **`STAB-1` — query the live `chart_of_accounts` table** for the twelve reference-chart codes
   used by `JournalPostingService`/`PayrollFinancePostingService`, and specifically for codes
   `2200` (Client Deposits) and `2110` (Output VAT Payable). This is the single highest-priority
   verification in the entire audit — it determines whether Critical Risk C1 and the "three
   disagreeing Outstanding figures" finding are already actively occurring in production, or are
   currently theoretical.
2. **`W3-3` — pull `journal_entries`/`journal_lines` for a handful of real, reconciled petty-cash
   surrenders** and confirm whether the expense account was debited once or twice per receipt.
   Directly checkable with no code change.
3. **Confirm current headcount in the `Accounts`/`Admin` system roles**, to establish whether the
   segregation-of-duties concentration finding (risk H9) is a live risk today or a theoretical one
   at WNG's current scale — this materially affects how urgently `ROLE-2` should be prioritized.
4. **Confirm the production `QUEUE_CONNECTION` setting and whether a queue worker process is
   running** — `ProcessStoresFinancePosting` assumes synchronous execution; if that assumption no
   longer holds, Stores cost postings could be silently queuing up unprocessed.
5. **Cross-foot the VAT Input/Output Schedules against the Trial Balance's own VAT account
   balances** for a real period, to confirm they tie out as the design intends (not confirmed
   broken, not independently re-verified in Phase 1 either).

## FUTURE / OPTIONAL

Useful, but not required for the initial Finance stabilization or the start of Phase 2B target
design. None of these should be started before the items above.

- **Balance Sheet and Cash Flow Statement** — both fully blocked on multiple upstream decisions
  (`STAB-2`, `W9-1`, `C7`'s completion) and are lower priority than the reports already partially
  working.
- **Withholding VAT (WVAT) implementation** — entirely unbuilt; only worth pursuing if WNG confirms
  it is a KRA-appointed VAT withholding agent (a Finance/Accountant question not yet asked of WNG's
  own tax advisor, separate from this register).
- **Non-resident WHT categories and alerting** — similarly dependent on WNG's accountant confirming
  the applicable rate/policy.
- **Full fixed-asset accounting (depreciation, disposal, gain/loss)** — explicitly out of scope
  until `W9-1` narrows it, and even then likely a later phase than the core Finance redesign.
- **Splitting the largest files** (`PettyCashRequisitionController`, `JournalPostingService`,
  `RequisitionForm.vue`) **for maintainability** — a real but non-urgent code-health improvement
  with no functional impact; natural to fold into whichever future work touches those files anyway.
- **Consolidating `Payment`, `BillPayment`, `ClientReceipt`, and `CashMovement`** toward one
  canonical money-movement table — each currently occupies a genuinely distinct, non-overlapping
  niche; consolidation is a longer-term architectural nice-to-have, not a defect.
- **Splitting client invoices/receipts/credit notes into separate focused screens** (rather than one
  combined page) — a usability improvement once `NAV-2`'s broader Sales & Receivables shape is
  settled, not before.
- **Value-based approval thresholds** for Purchase Orders and Payment Vouchers — genuinely useful,
  but the underlying single-tier controls are safe defaults in the meantime; building the tiered
  version only once WNG supplies actual amounts (`W2-1`, `W4-2`).

---

## Summary for whoever approves moving to Phase 2B

- **7 CRITICAL financial-integrity risks** have a fully-scoped remediation plan
  (`02_CRITICAL_STABILIZATION_PLAN.md`); the technical portions of all seven can begin immediately
  on approval of that plan.
- **15 foundational architectural patterns** are confirmed sound and must not be redesigned
  (`01_FOUNDATION_TO_PRESERVE.md`).
- **~55 business decisions** are logged in `03_WNG_FINANCE_DECISION_REGISTER.md`, none yet answered.
  The single most consequential is `W7-1` (labour cost attribution), followed by `STAB-1` (chart of
  accounts) as the most urgent to *verify*.
- **Phase 2B should begin only on the "SAFE TO DESIGN NOW" items above**, in parallel with WNG's
  review of the Decision Register — it should not attempt full target designs for Sales &
  Receivables, Purchases & Payables, Project Profitability, Payroll's permission model, or the
  Finance role/permission structure until their blocking decisions are resolved.
