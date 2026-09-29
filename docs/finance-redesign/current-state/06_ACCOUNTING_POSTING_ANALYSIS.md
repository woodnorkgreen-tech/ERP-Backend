# 06 — Accounting Posting Analysis: Journals/GL, Bank & Cash, Tax, Numbering

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Covers audit sections 9
(Journals and General Ledger), 10 (Bank and Cash Management), 12 (Tax Implementation), and 20
(Numbering and References).

---

## PART A — Journals and General Ledger

### A.1 Data model

**FACT.** `JournalEntry` (`journal_entries`) carries `entry_no` (unique), `posting_date`,
`accounting_period_id`, `cost_line_id`, `spend_voucher_id`, `source_type`/`source_id`/`source_ref`
(polymorphic origin), `total_debit`, `total_credit`, `status` (draft/posted/reversed),
`reversal_of_id` (unique self FK), `created_by` (plain `unsignedBigInteger`, not an FK to `users`),
`posted_at`. `JournalLine` carries `account_id` (FK), `entry_type`, `amount`, `currency`, `fx_rate`,
`base_amount`, `cost_centre_id`, `activity_id`, `project_id`, `project_enquiry_id` — no per-line
posting date/user, standard double-entry shape.

`source_type`/`source_id` is genuinely polymorphic and populated with at least 6 distinct classes:
`CostLine`, `SpendVoucher`, `Bill`, `BillPayment`, `Payment`, `PettyCashRequisition`, plus
`CashMovement`, `ProjectInvoice`, `EnquiryPayment` — 9 total once income and manual cash movements
are counted.

### A.2 Where "Total Debit = Total Credit" is actually enforced

**There is no database-level constraint** — no CHECK constraint, no trigger, no generated column.
Balance is enforced purely in application code, and — critically — **inconsistently**.

**The "single funnel" (works correctly).** `JournalPostingService::postBalancedEntry()` is
explicitly documented as "the single funnel" for every producer: checks the period is open, sums
legs with `bcadd`, **throws `InvalidArgumentException` if `bccomp($debit, $credit, 2) !== 0`**,
checks every account is postable, writes inside `DB::transaction()`. Producers routed through it:
`postCostLine()`, `postSpendVoucher()`'s non-cash branch, `postCashSettlement()` (itself called by
`postPayment()`, `postSupplierPayment()`, voucher payment/reimbursement), `postPettyCashAdvance()`,
`postPettyCashSurrender()`, `CashMovementService::create()`.

**ISSUE — RISK: CRITICAL. Two producers bypass the funnel entirely.**
- **`postSupplierInvoice()` (Bill posting)** — the single highest-value entry point for the
  supplier/procurement rail — builds `JournalEntry::create()` directly, with `total_debit`/
  `total_credit` both set to the *sum of the debit legs only*. **There is no `bccomp` assertion
  anywhere in this method.** Balance is achieved only because the legs happen to sum correctly by
  construction — never independently verified before being written. A future edit to the leg-
  building logic (or a bad tax-account GL override) could post an unbalanced entry with nothing to
  stop it.
- **`reverseEntry()`** — also builds entries directly rather than via the funnel. Lower incremental
  risk (it mechanically flips debit↔credit on an already-posted, presumably-balanced parent), but
  inherits whatever risk profile the original entry had — RISK: MEDIUM for this one specifically.

**RECOMMENDATION.** Route both through `postBalancedEntry()` (or extract its balance/postable-
account checks into a shared `assertBalanced()` helper) so every write to `journal_entries`/
`journal_lines` passes through one verified gate, matching the code's own stated design intent.

### A.3 How entries are generated

**FACT — exclusively automatic, triggered by domain events. There is no manual journal-entry-
creation UI or endpoint anywhere.** `JournalEntryController` is explicitly documented and built as
read-only plus one guarded `reverse()` action, which itself delegates to `reverseEntry()` rather
than writing rows directly, requires a reason, and explicitly **refuses to reverse a journal
belonging to a Payment** — redirecting the caller to `PaymentController::reverse()` instead, so a
payment's liability/voucher/cashbook/ledger always move together. Grepping the whole `Finance` tree
for `JournalEntry::create(` outside the posting service found exactly one call site, in a console
demo/simulation command (not HTTP-reachable).

### A.4 Traced code paths (one per major transaction type)

- **Cost line:** `postCostLine()` → `costLineLegs()` (up to 4 legs) → funnel → `JournalEntry`/
  `JournalLine` → surfaces via `JournalEntryController`/`GeneralLedgerView.vue`.
- **Supplier bill:** `postSupplierInvoice()` → gate on verification → `supplierInvoiceLegs()` →
  **direct** create (no funnel) → `markGrnAccrualsSettledByBill()`.
- **Payment (unified rail):** `postPayment()` → resolves debit legs via
  `resolveVerifiedLiabilityAccount()` (allocated) or `resolveUnallocatedDebitAccount()`
  (advance/top-up/refund) → `postCashSettlement()` (funnel). `postSupplierPayment()`/
  `postSpendVoucher()` are both `@deprecated → use postPayment()` but still present and callable.
- **Petty cash disbursement/surrender:** disburse → `postPettyCashAdvance()` (funnel); surrender →
  `postPettyCashSurrender()` (funnel, inside the same transaction as the rest of the surrender —
  see Bank & Cash §B.3 for a CRITICAL divergence risk in the disbursement half).
- **Manual bank/cash adjustment:** `CashMovementService::create()` → funnel directly. Used both
  standalone and from reconciliation's "create & match."
- **Reversal of any of the above:** `JournalEntryController::reverse()` →
  `JournalPostingService::reverseEntry()` (direct-write path, see A.2).

### A.5 Frontend rendering

**ISSUE — RISK: MEDIUM.** `JournalEntryDrawer.vue` renders `source_type`, `source_ref`,
`reversal_of_id`, `reversed_by_id` (as an entry-number cross-reference) and `posted_at`, but **never
renders `created_by`** — confirmed the API resource exposes it, the data simply never reaches the
screen. Who posted or reversed an entry cannot be answered from the UI.

**ISSUE — RISK: LOW-MEDIUM.** `GeneralLedgerView.vue`'s source filter offers exactly two options
(`cost_line`, `spend_voucher`), matching a hard-coded backend whitelist — but real `source_type`
values posted into `journal_entries` include at minimum `Bill`, `BillPayment`, `Payment`,
`PettyCashRequisition`, `CashMovement`, `ProjectInvoice`, `EnquiryPayment` — **5+ more source types
than the UI/API can filter by.** Finance cannot isolate "everything payroll/a supplier bill posted"
in the ledger screen at all.

### A.6 Trial balance and Equity

**FACT/ISSUE — RISK: MEDIUM.** `trialBalance()` computes rows via an **inner join** —an account
with no posted line never appears in the result set at all, not filtered afterward, structurally
absent. `ChartOfAccountSeeder` seeds four Equity accounts, but a full read of
`JournalPostingService.php` (~1,948 lines) shows **no reference anywhere** to any Equity code or
`category==='equity'`. Nothing in the current build posts to Equity — no opening-balance import, no
year-end retained-earnings closing entry. The frontend's `groupedTrialBalance` computed property
additionally **silently drops** any category with zero rows before rendering. **Net effect: Equity
is completely invisible on the trial balance today — correct given nothing has posted to it, but
indistinguishable from "this section doesn't exist" to a reader who doesn't know that.** The
endpoint's own docblock is honest about scope ("not a statutory one yet... depreciation and opening
balances are still missing") — documented, self-aware technical debt, not a hidden defect.

### A.7 KEEP/REDESIGN classification

| Component | Classification |
|---|---|
| `JournalEntry`/`JournalLine` schema | **KEEP** |
| `postBalancedEntry()` funnel | **KEEP** |
| `postSupplierInvoice()` / `reverseEntry()` direct-write paths | **REDESIGN** |
| `JournalEntryController` (read-only + guarded reverse) | **KEEP** |
| Ledger source filter | **KEEP & IMPROVE** |
| `JournalEntryDrawer.vue` audit display | **KEEP & IMPROVE** |
| Trial balance Equity coverage | **REQUIRES WNG CONFIRMATION** |
| `postSpendVoucher()`/`postSupplierPayment()` deprecated methods | **REMOVE (after caller migration)** |

---

## PART B — Bank and Cash Management

### B.1 What exists

**FACT.** No dedicated `BankAccount` model — bank, mobile-money, card and petty-cash "accounts"
are all rows in one polymorphic master table, `payment_sources`, each optionally linked to a
`gl_account_id`. Deposits/withdrawals/manual adjustments go through `CashMovement`+
`CashMovementService` (a generic 2-leg journal poster). No dedicated "transfer between two WNG
accounts" mechanism was found — a transfer today would be modelled as two separate movements
(**ASSUMPTION**, not exhaustively verified). Bank reconciliation:
`ReconciliationStatement`/`StatementTransaction`/`StatementMatch` + CSV import with auto-matching
(reference+amount+account+date-tolerance, configurable, default 3 days).

### B.2 Bank/mobile/card balances — neither live-computed nor stored

**FACT.** Deliberate, documented design: `PaymentSourceController::index()` returns
`available_balance = null` for every non-petty-cash source, with an explicit comment — *"a bank
balance is not held in this system, and a zero would read as 'no money' rather than 'not tracked
here.'"* The closest thing to a bank balance is a point-in-time, reconciliation-run-scoped
computation inside `ReconciliationService::summary()` — a genuine live-from-ledger number, but it
only exists inside a formal reconciliation statement; there is no "current bank balance" tile
anywhere else in the product.

**RECOMMENDATION.** Add a live "GL balance as of today" read per bank/mobile-money source (same
pattern already used in reconciliation and account-statement code), clearly labelled "per the
ledger" — no schema change required.

### B.3 Petty cash balance — mixed, and the two sources of truth CAN diverge — RISK: CRITICAL

Petty cash *is* tracked with a live balance, through a **separate, parallel ledger that is not the
general ledger**:
- `petty_cash_ledger_entries` is the source of truth for cash-in-hand, written exclusively through
  `LedgerService::post()` (atomic, row-locked, updates the `PettyCashBalance` cache in the same
  transaction) — a sound single-writer-plus-cache design.
- Separately, the **general ledger** is updated by `postPettyCashAdvance()` at disbursement and
  `postPettyCashSurrender()` at surrender.
- **These are two independently-written ledgers for the same real-world cash, and the call site
  triggering both is not uniformly atomic.**

**Disbursement:** `createDisbursement()` (petty cash's own ledger) **has already committed** by the
time the GL-posting call runs. That call is wrapped in `catch (\Throwable $e) {
\Log::warning(...) }` — **the exception is swallowed**, and the HTTP response still reports success
regardless of whether the journal posted. **Consequence: real cash can leave the tin (correctly
recorded in petty cash's own ledger) while the General Ledger's Staff Advances/Petty Cash Float
accounts silently show nothing for it** — e.g. if the payment source has no `gl_account_id` or the
target account is inactive, the throw is caught and discarded with only a log line. No automated
reconciliation between the two ledgers exists to catch it later.

**Surrender**, by contrast, calls the equivalent posting method **without** a local try/catch,
inside the same transaction as the rest of the surrender logic — a failure here correctly rolls
back the whole surrender. **This half of the lifecycle is atomic and safe.**

**RECOMMENDATION.** (1) At minimum, stop swallowing the exception silently — flag the requisition
and alert Finance same-day. (2) Longer-term, wrap the GL posting call in the same transaction as
the disbursement, or add a scheduled job comparing `PettyCashBalance.current_balance` against the
GL's float-account balance.

### B.4 Bank reconciliation — "ignore" reason capture — RESOLVED

**FACT.** The prior-audit concern is **no longer true as of 2026-09-21** (one day before this
audit): `ignore()` now validates and persists a required, min-5-character reason, backed by a
dedicated migration, covered by a passing test, and captured/displayed on the frontend via
`window.prompt()`. **Fixed end-to-end.** Minor cosmetic recommendation: replace the native
`prompt()`/`alert()` pattern with a proper modal.

### B.5 Bank reconciliation — hard-delete of match history — RISK: HIGH

**ISSUE — CONFIRMED.** Neither `StatementMatch` nor `StatementTransaction` is soft-deletable.
`match()`, `unmatch()`, and `ignore()` all perform a real SQL `DELETE` on the matches relation
before writing new state. **Once a statement line has been re-matched, unmatched, or marked
ignored, there is no database row anywhere recording what it used to be matched to, who matched it
originally, or when — that history is unrecoverable.** Not a debit≠credit risk (the GL itself is
untouched), but a real control gap for an external auditor or KRA review, especially now that the
ERP is intended to become WNG's real general ledger.

**RECOMMENDATION.** Add `SoftDeletes` to `StatementMatch`, or replace hard deletes with a
`superseded_at`/`superseded_by` marker.

### B.6 Bank reconciliation source-type coverage

**FACT — KEEP.** `payable`-type sources are correctly excluded from reconcilable accounts,
consistent with the wash-entry defect fix already recorded elsewhere in WNG's Finance history.

### B.7 KEEP/REDESIGN classification

| Component | Classification |
|---|---|
| `payment_sources` as unified bank/cash/card/petty-cash master | **KEEP** |
| Bank/mobile/card "no balance tracked" design | **REQUIRES WNG CONFIRMATION** |
| `ReconciliationService` workflow | **KEEP & IMPROVE** (soft-delete fix) |
| Petty cash `LedgerService` + `PettyCashBalance` cache | **KEEP** |
| Disbursement→GL posting coupling | **REDESIGN — CRITICAL** |
| Surrender→GL posting coupling | **KEEP** |
| `CashMovementService` | **KEEP** |
| First-class "transfer between two WNG accounts" | **REQUIRES WNG CONFIRMATION** |

---

## PART C — Tax Implementation

### C.1 What is implemented, and where

**FACT — VAT.** Treatment and rate are data (`vat_treatments`), effective-dated. Seeded: STD16-REC
(16%, recoverable), STD16-NONREC (16%, non-recoverable), ZERO, EXEMPT, OOS. Resolution precedence
(purchase side): an unregistered supplier is forced to OOS regardless of what was bought; otherwise
the expense code's default treatment wins over the supplier's own default.

**FACT — VAT calculation, purchase side (tax-inclusive):** captured amount treated as gross; a
typed `tax_amount` is subtracted to derive net (cannot exceed gross). Supplier-bill tax extraction
is explicit and documented ("an invoice for 116,000 at 16% is 100,000 of goods and 16,000 of tax,
not 116,000 of goods"); an explicit `vat_amount` on the input always wins over the derived figure.

**FACT — VAT calculation, sales/invoice side (tax-exclusive, tax added on top):** net = qty ×
unit price; tax = net × rate; rate resolved by **invoice date**, not today's date. Invoice header
totals are always derived from lines and overwritten on every change — not a value a person can
type that then disagrees with the lines. (The two conventions differ deliberately — purchase side
treats captured figures as gross, sales side treats entered prices as net — defensible but worth
WNG confirming matches how staff actually enter numbers on both sides.)

**FACT — Output VAT reaches the GL.** `postInvoiceIssued()` posts Output VAT Payable as its own
credit leg, separate from revenue, on every invoice with nonzero tax — confirmed current and
working, the September-2026 work the audit was asked to verify.

**FACT — WHT.** Categories are data (`wht_categories`): PROF-RES (5%), CONTRACT-RES (3%), NONE.
**No non-resident WHT category is seeded — deliberately** (the seeder's own docblock: "deliberately
absent rather than guessed"), and `TaxResolver::whtCategoryFor()` explicitly refuses to fall back to
a resident rate for a non-resident supplier ("returning null leaves it visibly unwithheld for
Finance to price by hand"). **Consequence: any payment to a non-resident supplier today
automatically withholds nothing and is not flagged as an exception anywhere** — a compliance-
relevant gap the authors knew about and deliberately deferred to WNG's accountant rather than
guessing.

**FACT — WHT calculation** charged on the net (VAT-exclusive) amount, never gross, using bcmath
throughout, no float arithmetic; below-threshold payments withhold nothing.

**FACT — known, self-documented, now-measured under-withholding gap.** The per-payment threshold
test is, by the code's own comment, "really meant to be tested against the supplier's month" —
`TaxScheduleService::whtPayeeRow()` now **measures** this exposure explicitly for aggregate-monthly
categories (an `aggregation_shortfall`/`aggregation_exposure` figure, surfaced as a red "Under-
withheld" banner) but does not correct it — recovering it is a supplier conversation, stated
explicitly in code. **This is a genuinely good design** — a known limitation, measured and
surfaced, not hidden.

**FACT — WHT reaches the GL on the cost side via preview/commit parity** — the on-screen preview a
verifier approves is byte-for-byte what gets committed. WHT is withheld against the supplier's
payment and posted as a liability to KRA, not merely a memo field.

**FACT — withholding VAT (WVAT) does not exist anywhere in the codebase** (confirmed by a
repo-wide search). If WNG is a KRA-appointed VAT withholding agent, this is a genuine, entirely
unimplemented gap — **REQUIRES WNG FINANCE/TAX CONFIRMATION.**

### C.2 Tax reports / statutory export

**FACT.** Four reports, each computed once server-side and rendered as both JSON and streamed,
BOM-prefixed CSV from the same call: Input VAT schedule (from both cost lines and supplier bills —
a documented gap-fix, since a goods-receipt accrual carries no tax and only the bill does), Output
VAT schedule, Net VAT return (with an explicit warning when unsupported input lines are excluded),
WHT schedule (by payee/month, with under-withholding exposure). **FACT — the service is explicitly
read-only and does not track "filed" state** — a sound, honest design boundary, not a gap ("filing
is an act performed by a person on KRA's portal... this produces the schedule they file from").

### C.3 ISSUE 12-A: "Missing evidence" gate checks only 2 of 3 fields — CONFIRMED, RISK: MEDIUM

The claim-evidence requirement, enforced everywhere in the tax subsystem, checks only
`etims_invoice_no` and `supplier_pin` — **never `supplier_invoice_no`**, even though that field
exists on the same rows, is displayed elsewhere, and is already a column in the CSV export. This is
not a missing-data problem, it is a missing-*validation* problem.
**CLASSIFICATION: KEEP&IMPROVE, pending WNG Finance confirming this evidence is actually required**
(it is possible the eTIMS number alone is considered sufficient).

### C.4 ISSUE 12-B: WHT schedule never shows net-paid-to-supplier — CONFIRMED, RISK: LOW-MEDIUM

The monthly WHT schedule shows gross-subject-to-WHT and WHT-withheld but never computes
`net_amount − wht_amount` — the amount the supplier was actually paid. The capability already
exists elsewhere in the same codebase (`CostTaxPricer::preview()`'s "payable" figure, labelled
"What actually leaves the business") and is simply never carried forward into the aggregate report.
Not a compliance defect, but an avoidable manual step every month.
**CLASSIFICATION: KEEP&IMPROVE.**

### C.5 ISSUE 12-C: Input VAT screen omits Treatment/Supplier-invoice columns — CONFIRMED, RISK: LOW

The on-screen claim table omits two columns (`treatment_code`, `supplier_invoice_no`) that are
already typed on the frontend, already returned by the backend, and already in the CSV export —
this is a template-only gap, not a data gap. **CLASSIFICATION: KEEP&IMPROVE.**

### C.6 Minor / supporting observations

**FACT — KEEP.** The effective-dating design (rate resolved on the cost's own incurred/invoice
date, not today's date) is a genuinely correct, non-trivial, and verifiably right implementation
detail. **FACT.** VAT/WHT resolution precedence deliberately differs between the two taxes,
documented and justified, not accidental.

### C.7 Classification summary

| Area | Classification |
|---|---|
| VAT/WHT rate-as-data architecture, effective-dating | **KEEP** |
| VAT/WHT calculation code | **KEEP** |
| Tax schedules & CSV export architecture | **KEEP** |
| Missing-evidence gate (2 of 3 checks) | **KEEP&IMPROVE, pending WNG confirmation** |
| WHT schedule missing net-paid column | **KEEP&IMPROVE** |
| Input VAT screen missing columns | **KEEP&IMPROVE** |
| Non-resident WHT categories | **REQUIRES WNG FINANCE/TAX CONFIRMATION** |
| Withholding VAT (WVAT) | **REQUIRES WNG FINANCE/TAX CONFIRMATION — not implemented at all** |

---

## PART D — Numbering and References

### D.1 Inventory of every numbering scheme found

| Document | Format | Generator |
|---|---|---|
| Client invoice | `INV-{Ym}-{6-digit id}` | Row id, post-create rename |
| Credit note | `CN-{Ym}-{6-digit id}` | Row id, post-create rename |
| Purchase order | `PO-{Y}-{4-digit seq}` | `PurchaseOrder::generatePONumber()` — max+1, **unlocked** |
| Supplier bill | `BILL-{Y}-{4-digit seq}` | `Bill::generateBillNumber()` — max+1, **unlocked** |
| Goods receipt note | `GRN-{Y}-{4-digit seq}` | `GoodsReceiptNote::generateGrnNumber()` — max+1, **unlocked** |
| Supplier payment (BillPayment) | `PAY-{year}-{4-digit seq}` | `DocumentNumber::next('PAY')` — atomic counter table |
| Requisition/receipt/journal/advance/retirement | `REQ-/RCT-/JV-/PCA-/PCR-{year}-{seq}` | `DocumentNumber::next(...)` — same atomic counter |
| Journal entries (system-generated) | `JE-BILL-{id}`, `JE-INV-{id}`, etc. | Derived deterministically from source row id |

### D.2 Uniqueness enforcement

**FACT.** Every one of `bill_number`, `po_number`, `grn_number`, `invoice_number`,
`document_sequences.(prefix,period)` carries a DB-level `unique()` constraint — a true duplicate
cannot silently persist; a collision surfaces as a failed insert.

**ISSUE — `bill_payments.reference_number` has no uniqueness constraint at all** (see Part B of
`05_CURRENT_WORKFLOWS.md`, §B.6).

### D.3 Sequence generation method and concurrency safety — self-contradictory within the codebase

**FACT.** The team has already identified and fixed this exact class of bug once, for `PAY-`
numbers: the `document_sequences` migration's own docblock describes the prior defect (three
independent "read max, add 1" implementations, no lock, a real race) and `DocumentNumber::next()`
is the correct fix — `insertOrIgnore` + `lockForUpdate()` + atomic increment.

**ISSUE — RISK: MEDIUM.** The identical race condition still exists, unfixed, in three sibling
series: `PurchaseOrder::generatePONumber()`, `Bill::generateBillNumber()`, and
`GoodsReceiptNote::generateGrnNumber()` all still use the exact "read max, add 1, format" pattern
the team's own docblock describes as the defect it replaced — no `lockForUpdate()`, called outside
any transaction. The DB `unique()` constraint means a genuine collision fails loudly (a generic 500)
rather than silently duplicating a number — a **reliability/UX bug under concurrency**, not a
silent data-integrity bug, but real and reproducible.

**RECOMMENDATION.** Route all three through `DocumentNumber::next()` with new series constants,
inside the same transaction as the row insert — the exact pattern already proven for `PAY`.

### D.4 Editability after creation

**FACT — good.** No route exists to directly edit `invoice_number` on a `ProjectInvoice` — numbers
are effectively immutable post-creation for client invoices and credit notes.

**ISSUE — `bills` has a dangling `update()` route with no handler.** `Route::apiResource` registers
`PUT/PATCH /bills/{bill}`, but `BillController` defines no `update()` method — hitting this route
throws a framework-level 500. Does not let anyone edit `bill_number` in practice, but is a live,
untriaged landmine route. Cross-checked against the team's own prior audit notes (2026-09-15/17
sweeps), which flagged the same "bills.update 500 trap" as unresolved — **confirmed still true
today.** RISK: LOW-MEDIUM.

### D.5 Missing/duplicate references possible?

- **Missing:** not observed as a routine possibility — every numbered document generates its number
  synchronously at creation.
- **Duplicate:** blocked by DB constraint for invoice/bill/PO/GRN numbers themselves (a MEDIUM
  reliability risk only, not data-integrity risk); genuinely **possible and unguarded** for
  `bill_payments.reference_number` — the one place in this section where a true silent duplicate
  can persist to the database today.

### D.6 Classification

| Area | Classification |
|---|---|
| Payment/Requisition/Receipt/Journal/Advance/Retirement numbering | **KEEP** |
| PO/Bill/GRN sequence generation | **KEEP & IMPROVE** — apply the already-proven `DocumentNumber::next()` fix |
| `bills` dangling `update()` route | **KEEP & IMPROVE** — implement or explicitly remove |

---

*Consolidated risk ratings for every ISSUE above appear in `10_FINANCE_RISK_REGISTER.md`.
Consolidated WNG questions appear in `12_WNG_CONFIRMATION_QUESTIONS.md`.*
