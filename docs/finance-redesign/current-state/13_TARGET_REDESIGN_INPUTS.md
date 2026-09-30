# 13 — Target Redesign Inputs (Facts for Phase 2)

This file consolidates what Phase 2 (Finance Requirements & Target Architecture) will need,
without prematurely defining the target design. It does **not** propose a new architecture — it
collects: (a) the code-quality/technical-debt inventory (audit section 23, which has no dedicated
output file of its own), (b) the architectural patterns already in this codebase that are strong
enough to build on rather than replace, (c) the load-bearing constraints any redesign must respect,
and (d) a map of which of the other 12 documents to read for which decision.

---

## A. Code Quality / Technical Debt Inventory (Section 23)

### A.1 Very large files

| File | Lines |
|---|---|
| `app/Modules/Finance/PettyCash/Controllers/PettyCashRequisitionController.php` | 2,009 |
| `app/Modules/Finance/Services/JournalPostingService.php` | 1,947 |
| `app/Modules/ProcurementStores/Controllers/BoardController.php` | 1,859 |
| `app/Modules/ProcurementStores/Controllers/ProcurementStoresController.php` | 1,609 |
| `app/Modules/Finance/PettyCash/Controllers/PettyCashController.php` | 1,130 |
| `app/Modules/Finance/PettyCash/Services/PettyCashService.php` | 999 |
| `app/Modules/ProcurementStores/Controllers/BillController.php` | 832 |
| `app/Modules/ProcurementStores/Controllers/RequisitionController.php` | 763 |
| `app/Modules/ProcurementStores/Controllers/GoodsReceiptNoteController.php` | 741 |
| `app/Modules/Finance/CostCollector/Services/CostCollectorService.php` | 715 |
| `app/Modules/Finance/Controllers/SpendVoucherController.php` | 604 |
| `app/Modules/ProcurementStores/Controllers/PurchaseOrderController.php` | 591 |
| `app/Modules/ProcurementStores/Services/StockMovementPoster.php` | 576 |
| `app/Modules/Finance/Services/TaxScheduleService.php` | 574 |
| `app/Modules/Finance/Services/ReconciliationService.php` | 568 |

Frontend:

| File | Lines |
|---|---|
| `src/modules/finance/petty-cash/views/requisitions/RequisitionForm.vue` | 2,466 |
| `src/modules/finance/petty-cash/components/DisbursementForm.vue` | 1,570 |
| `src/modules/finance/petty-cash/views/requisitions/RequisitionShow.vue` | 1,461 |
| `src/modules/finance/receivables/components/EnquiryFinanceModal.vue` | 1,384 |
| `src/modules/finance/petty-cash/views/PettyCashIndex.vue` | 1,166 |
| `src/modules/finance/cost-collector/components/CostCaptureForm.vue` | 1,134 |
| `src/modules/finance/petty-cash/views/requisitions/RequisitionIndex.vue` | 1,024 |
| `src/modules/finance/petty-cash/components/TransactionList.vue` | 984 |

**RISK: LOW** — maintainability/readability debt, not a correctness or safety risk by itself.
`JournalPostingService` already has 5+ distinct `DB::transaction` blocks (one per posting
scenario) that are natural extraction points into single-responsibility classes.
`PettyCashRequisitionController` mixes routing, validation, PDF generation, and the full
approval workflow in one class.

### A.2 Money handling — genuinely well-handled (KEEP)

All money-bearing Eloquent casts checked use `'decimal:2'`; no `float`/`double` cast or column
found anywhere in Finance/PettyCash/ProcurementStores. `SupplierPaymentService::recordBatch()` uses
`bcadd`/precision-safe formatting throughout. **No evidence of float-based money bugs found in the
sampled files.**

### A.3 Tax rates — DB-driven, not hard-coded (KEEP)

No hard-coded VAT/WHT rate literal was found in Finance PHP or Vue source (the one `vat_rate`
string match is a form-field label, not a rate value). See `06_ACCOUNTING_POSTING_ANALYSIS.md`
Part C for the one exception that slipped through: a hardcoded `/1.16` inside a *Projects-module*
advisory margin calculation (`QuoteInsightsService`), not the Finance tax subsystem itself.

### A.4 Race conditions and missing transactions — well-guarded (KEEP)

Balance-affecting writes are consistently wrapped in `DB::transaction()` with `lockForUpdate()`
before read-modify-write, confirmed across `LedgerService`, `PettyCashService`,
`CostCollectorService`, `SpendVoucherController`, `SupplierPaymentService::recordBatch()`,
`ReconciliationService`. **No HIGH/CRITICAL race condition was found in the highest-risk
money-movement code sampled** — this contradicts a naive first-pass assumption. **ASSUMPTION**:
based on the specific controllers/services sampled, not a full static sweep of every `->update(`/
`->save(` call on a money column across the whole module.

### A.5 Unsafe deletes — guarded, not naive (KEEP)

`Bill::destroy()`, `PettyCashRequisitionController::destroy()`, and
`PettyCashTopUpController::destroy()` all refuse to delete records with downstream financial
consequences, and the top-up case posts a compensating reversal before removing the row — mature,
audit-conscious deletion handling. (Contrast the petty-cash `clearAllData()` endpoint, which has
none of these safeguards — see risk C6.)

### A.6 Authorization — backend-enforced, not frontend-only (KEEP, in the paths checked)

Every Finance action sampled (`SpendVoucherController`, `PettyCashTopUpController::destroy()`) gates
server-side via `abort_unless($user->can(...))`, independent of what the Vue UI does or doesn't
show. **ASSUMPTION**: not every Finance controller/route was individually checked.

### A.7 Naming/architecture debt

- `HRAuditLog` used as the de facto general audit log by Finance controllers — a module-boundary
  violation (see `07_APPROVAL_AND_PERMISSION_MATRIX.md` §22.2).
- "Payment Voucher" (user-facing) vs `SpendVoucher` (backend/table/class/permission) is a
  **documented, intentional** naming split, not an accident — a route-file comment explains a
  same-implementation alias under a second URL was rejected as pure duplication. Confirmed clean:
  no dead dual-API scaffolding remains (`UnifiedPaymentService`, `PaymentVoucher` model,
  `payment_vouchers` migrations all absent). **KEEP** — a one-time onboarding gotcha, not live debt.

### A.8 Test coverage inventory (files found, not run)

**FACT.** 72 PHP test files exist across `tests/Feature/{Finance,PettyCash,CostCollector,
ProcurementStores,Stores,Procurement,Projects,Hr}` and `tests/Unit/Finance`, including targeted
regression tests for petty-cash surrender double-posting prevention, payroll balanced-journal
integrity, bill-verification segregation of duties, and project-invoice balance calculation.

**ISSUE — LOW-MEDIUM.** No test file matching `*SpendVoucher*` was found anywhere, despite
`SpendVoucherController` (604 lines) being the most complex Finance controller by distinct state
transitions (approve/post/cancel, locking, self-approval guards, journal-posting side effects). No
test file matching `*ClientReceipt*`, general `*Bill*Test*` (only `BillVerificationSegregationTest`,
scoped narrowly to verification segregation), or `*PaymentReversal*` was found either.
**RECOMMENDATION.** Add feature tests for SpendVoucher's approve/cancel/post transitions (including
the self-approval-block path) and for Bill payment recording (single + multi-bill batch) before any
redesign touches those paths — they are the least-covered, highest-complexity money-movement code
in the module.

### A.9 Duplicated calculation logic

No duplicated tax-rate or VAT/WHT calculation logic was found between frontend and backend (the
frontend has no hard-coded rate literals, implying it displays server-computed results rather than
recomputing). **ASSUMPTION**: a full line-by-line duplication audit across the ~30k lines of
Finance frontend+backend sampled was not performed; this finding is scoped to the rate-literal
search only.

---

## B. Architectural patterns worth preserving (not redesigning)

These are the parts of the current system that Phase 2 should treat as **foundation**, not as
problems to solve:

1. **Cost-lines-as-single-source-of-truth for budget and spend.** `cost_lines` with a `nature`
   column (planned/committed/accrued/actual) means variance is a `GROUP BY`, never a reconciliation
   between two separately-maintained systems. This is the pattern the labour/logistics gaps
   (risks H7, M14) need to extend into, not replace.
2. **Ledger-first project margin.** `CostAccountService::marginAgainstJournals()` reads margin from
   posted `journal_lines`/`project_invoices`, never a cached total. Any redesign of the portfolio
   view (risk M15) should extend this computation, not invent a parallel one.
3. **The single balanced-entry funnel.** `JournalPostingService::postBalancedEntry()` is the right
   design — the fix needed is closing the two paths that bypass it (risk C4), not replacing the
   funnel itself.
4. **Financial period enforcement at the service layer.** `assertOpenPeriod()` called from every
   posting path, with a row-locked re-check immediately before the final voucher post, is a strong,
   already-correct pattern.
5. **Three-way match with a tamper-evident fingerprint.** The supplier-bill verification design
   (sha256 of everything checked, silently invalidated on any post-verification change to the
   underlying facts) is a genuinely strong control worth extending to whatever replaces the
   Purchase-Order mutability gap (risks C2/C3) — e.g. requiring a fresh fingerprint after any
   approved-PO edit, rather than forbidding edits outright.
6. **SpendVoucher's three-permission maker/checker/poster split, plus identity-level self-
   succession blocks.** This is the reference pattern the audit found nowhere else applied as
   completely — Payroll (risk M13) and Bill verification (risk M21) should be brought up to this
   standard, not given a bespoke new model.
7. **`document_sequences` + `DocumentNumber::next()`.** An atomic, row-locked numbering scheme that
   already replaced three independently race-prone implementations for `PAY-` numbers. The fix for
   PO/Bill/GRN numbering (risk M20) is to point them at this existing mechanism, not build a new one.
8. **`ChartAccountMap` + `posting_rules` as the intended configurable-posting layer.** Both already
   exist, are well-designed, and are simply under-adopted (risk C1, M6). The redesign decision here
   is adoption, not replacement.
9. **The revenue-recognition three-event model** (cash arrival → Client Deposits liability; invoice
   issued → Accounts Receivable/Revenue/Output VAT; allocation → deposit discharged against
   receivable) and **WIP-release-on-billing** (matched to the invoice event, not a calendar date,
   specifically to avoid month-end distortion) are both sophisticated, correctly-reasoned, and
   should be the template for closing the credit-note/WIP gap (risk M10), not reworked.
10. **Petty cash's `LedgerService` single-writer-plus-rebuildable-cache pattern.** Sound in itself;
    the fix needed is making the *cross-ledger* (petty-cash-ledger vs GL) coupling atomic (risk C5),
    not redesigning the petty-cash ledger itself.

## C. Load-bearing constraints any redesign must respect

- **PO/Bill/Payment must remain three distinct documents.** The audit confirmed this separation is
  correct today (`05_CURRENT_WORKFLOWS.md` Part B.8) — do not conflate them to "simplify."
- **Business document / money movement / accounting posting must stay three distinct concepts**
  wherever they already are (supplier payment, customer receipt, payment voucher) — the redesign
  target for payroll and salary advances (risks C7, H6) is to bring them up to this standard, not to
  loosen the standard elsewhere.
- **Reversal must remain additive, never destructive.** Every reversal mechanism audited (journal,
  payment, top-up deletion) creates a new offsetting record rather than mutating history — this
  must not regress, including in whatever replaces the petty-cash `clear-all` endpoint (risk C6).
- **Tax rates and account codes must remain data, not code**, wherever they already are (VAT/WHT
  treatments) — the fix for hard-coded account constants (risk C1) is to bring the *remaining* hard-
  coded lookups up to this existing standard, not to introduce a new hard-coding pattern anywhere
  else.
- **Financial-period locking must remain enforced at the write path**, not just the UI — any new
  posting path introduced by a redesign must call the equivalent of `assertOpenPeriod()`.
- **Numbering must remain atomic and collision-proof** at the point of creation — any new document
  type introduced by a redesign should use `DocumentNumber::next()` from day one, not a fresh
  "read max, add 1" implementation.

## D. Map: which document answers which kind of question

| If Phase 2 needs to decide... | Read |
|---|---|
| What exists today, file by file | `02_FINANCE_MODULE_INVENTORY.md` |
| What a user currently sees/clicks | `03_CURRENT_NAVIGATION_MAP.md` |
| Table shapes, relationships, the chart of accounts | `04_CURRENT_DATA_MODEL.md` |
| How AR/AP/expenses/payments/project-costing actually work end-to-end | `05_CURRENT_WORKFLOWS.md` |
| How journals, bank/cash, tax, and numbering actually post | `06_ACCOUNTING_POSTING_ANALYSIS.md` |
| Who can create/approve/post/reverse what, and period controls | `07_APPROVAL_AND_PERMISSION_MATRIX.md` |
| How Finance connects (or doesn't) to other modules | `08_CROSS_MODULE_INTEGRATION_MAP.md` |
| What reports/dashboards exist and whether their numbers agree | `09_REPORT_AND_DASHBOARD_AUDIT.md` |
| Severity of a specific known problem | `10_FINANCE_RISK_REGISTER.md` |
| Whether to keep, fix, merge, or remove a specific component | `11_KEEP_REDESIGN_MERGE_REMOVE_MATRIX.md` |
| What business decision is blocking a given area | `12_WNG_CONFIRMATION_QUESTIONS.md` |
| Code-quality debt and which existing patterns to build on | this document |

---

## E. What this audit deliberately did not do

Per the audit brief's Critical Rule, this Phase 1 pass did **not**: modify any database schema,
rename any model, remove any page, rewrite any API, change any calculation or accounting entry,
redesign any UI, alter any permission, run any migration, or delete any legacy code. Every finding
above is a description of current behaviour, not a completed or attempted fix. Two items are
flagged as directly, cheaply verifiable against live data without any code change before Phase 2
begins (both would materially change how urgently their related risk is treated):

1. **Risk H5** (possible petty-cash surrender double-posting) — pull `journal_entries`/
   `journal_lines` for a handful of real `surrendered` requisitions and confirm whether the expense
   account was debited once or twice per item.
2. **Risk C1 / H10** (chart-of-accounts code mismatch) — query WNG's live `chart_of_accounts` table
   directly for the twelve reference-chart codes listed in `04_CURRENT_DATA_MODEL.md` §4.2, and for
   codes `2200`/`2110` specifically, to confirm whether posting failures are already occurring in
   production today.

Phase 2 — Finance Requirements & Target Architecture — should begin only after WNG has reviewed
this audit and answered the questions in `12_WNG_CONFIRMATION_QUESTIONS.md`.
