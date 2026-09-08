# Finance Module — Standards Gap Analysis

**Date:** 2026-09-07 · **Scope:** `app/Modules/Finance/**`, `app/Modules/HR/Services/Payroll/PayrollFinancePostingService.php`, `app/Modules/ProcurementStores/Controllers/StockCountController.php`, `app/Modules/Projects/Http/Controllers/EnquiryController.php` (invoice endpoints), `app/Modules/Assets/**`
**Companions:** [finance-ledger-purpose.md](./finance-ledger-purpose.md) · [finance-module-gap-analysis-2026-08.md](./finance-module-gap-analysis-2026-08.md) · [finance-operational-review-2026-09-05.md](./finance-operational-review-2026-09-05.md) · [quote-to-cash-redesign.md](./quote-to-cash-redesign.md)

Every finding below was re-verified against code on 2026-09-07, not carried over from the August gap analysis. Where the August doc recorded something as open and it is now fixed, that is stated.

---

## 1. The one decision that explains the shape

`finance-ledger-purpose.md` demoted the GL to a **tax-and-audit subledger** because WNG's statutory books live in an external package. That was defensible. It is also the root of nearly every gap below: the module is by design not a set of books, and the holes are consequences, not accidents.

`LedgerExportService.php:194` and `JournalEntryController::COVERAGE` (line 51) both state the exclusions honestly — revenue, client invoices and receipts; bank and cash movements not raised as a voucher; opening balances, equity, depreciation and year-end adjustments. Honesty about scope is not the same as completeness, and §2 works through what is absent inside and outside that scope.

---

## 2. Current state, by subsystem

| Subsystem | State | Evidence |
|---|---|---|
| **Cost Collector** | Strong. Policy auth, state machine, idempotent producers, tax pricing, reversal. | `CostCollector/` — the quality bar to measure the rest against. |
| **Petty Cash** | Sound single-writer ledger (`LedgerService`), reconciliation invariant, custody, offline batches. Authorization is now permission-based, not role strings. | `PettyCash/` |
| **Tax / KRA schedules** | Genuinely good. VAT input schedule, eTIMS gap by claim expiry, WHT by payee. | `TaxScheduleService.php` |
| **GL / Journals** | Real double-entry, cost side only. Supplier invoice + payment rail added 2026-09-07. | `JournalPostingService.php` |
| **Receivables** | An 18-line header stub. Posts nothing. | `ProjectInvoice.php` |
### 2.1 Verified as fixed since the August gap analysis

- **Posting rules now seeded; settlement account resolved.** `JournalPostingService::settlementAccountFor()` resolves the credit leg by how the cost arose (stock-issue → 1200, accrual → 2150, payment source, funding voucher, else **2100 AP**). The old fallback read "first postable asset", which in the seeded chart is `1010 Bank – Main Account` — every cost was crediting the bank. Pinned by `SettlementAccountTest`.
- **Goods receipt now debits 1200**, so a delivery hits Project WIP exactly once across receipt + issue. Pinned by `test_the_full_material_cycle_touches_project_wip_exactly_once`.
- **`CostAccountService::releaseAccrual()`** retires the accrual when material is issued, so a job no longer carries both the accrual and the issue.
- **Supplier invoice + payment rail** (`postSupplierInvoice`, `postSupplierPayment`) — `Dr 2150 / Cr 2100` and `Dr 2100 / Cr <source>`. Idempotent on `entry_no`. Covered by `SupplierLedgerRailTest`. **Caveat:** both are net-only; `bills` has no tax columns, so the procurement rail recognises no input VAT.
- **GL read API** (`JournalEntryController`): paginated entry list, single-entry drill-down, trial balance with explicit `coverage` block, document-batched export. Read-only by design.
- **Period enforcement on voucher posting** — `SpendVoucherController::store` resolves `accounting_period_id` from the posting date and refuses a locked/closed period.
- **Petty cash authorization** — `store`/`update`/`destroy` now gated on `finance.petty_cash.*` permissions; `destroy()` posts a reversing entry first. `PaymentMethods` centralised.
- **Spend voucher list** — the phantom `costLines` relation (column does not exist) is removed; filtering, search and pagination are server-side; `summary` aggregates over all vouchers, not the page.
## 3. Gaps, by finance cycle

### 3.1 Order-to-cash (AR) — the biggest hole

Verified by grep on 2026-09-07:

- `ProjectInvoice.php` is 18 lines: a header with `subtotal`, `tax_amount`, `total_amount`, `status`, `issued_by`, `issued_at`, `voided_by`, `voided_at`, `void_reason`. **No line items.** No `invoice_items` table exists anywhere in the schema.

- `EnquiryController::issueProjectInvoice()` (line 1498) sets `status => issued` and writes no journal. The whole method is:

  php
  abort_unless($lockedInvoice->status === 'draft', 422);
  $lockedInvoice->update(['status' => 'issued', 'issued_by' => Auth::id(), 'issued_at' => now()]);


- Nothing in the codebase references `4100 Project Revenue`, `1100 Accounts Receivable`, or `2110 Output VAT Payable`. Confirmed: `ChartOfAccountSeeder.php:100` seeds `4100` and `config/finance_accounts.php:112` mentions `2200 Client deposits`, but no writer, no reader, no matcher exists for any of them.

Consequences:

- **No revenue recognition.** A project has no revenue entry, so no margin, no gross-profit line, and no P&L.

- **No AR control account.** Nothing offsets the cash that arrives, so the balance sheet cannot show what is owed.

- **Output VAT is never declared.** `tax_amount` is typed on the invoice header and never posted. WNG claims input VAT every month and never declares output — a filing exposure, and the one-sidedness is invisible because neither side reaches the ledger.

- **No credit notes.** `voided_by`/`voided_at`/`void_reason` exist on the model but no route and no compensating entry.

- **No customer master.** `ProjectInvoice` links to `ProjectEnquiry`, not to a counterparty. There is no debtor aging, no statement, no dunning.

- **No deposits or retentions.** `2200` and `1110` are seeded and unreferenced. A deposit taken against a job is a balance-sheet liability that does not exist in this system.

- **Payment allocation is a raw pivot.** `allocatePaymentToInvoice()` (line 1510) writes `project_invoice_allocations` directly via `DB::table(...)->insert(...)`, bypassing the model, the policy layer, and the event system. It validates balances but fires no event, so no downstream consumer (cost release, revenue recognition, statement) learns a payment landed.

- **`enquiry_payments` still has no `unique(transaction_reference)` and no currency column** (migration `2026_03_10_131208`). Duplicate receipts are undetectable; a foreign payment is indistinguishable from a KES one. This was finding C3/C6 in `quote-to-cash-redesign.md` and is fully open.

### 3.2 WIP never releases to cost of sales

- Every project cost debits `1211`–`1219` Project WIP (`OperationalExpenseCodes.php:70`). The seeder's own `recording_rule` says *"Post to Project WIP while the job is open, **then transfer to cost of sales when the related revenue is recognised**."*

- Nothing references `5100`–`5900`. `ChartOfAccountSeeder` seeds all nine (`:106`–`:114`) with `parent_code => '5000'`, but no code reads them and `config/finance_accounts.php` comments the whole WIP→COS transfer as an accounting decision that has not been made.

- **WIP accumulates on the balance sheet forever.** For an events business where jobs complete in weeks, this means a job's cost sits as an asset months after the revenue was earned. There is no matching, no gross margin, and no P&L.

- The August doc flagged this; it is unchanged. The `finance_accounts.php` draft mappings (`1211 => COS-008`, `1212 => PE-007`) are commented out precisely because WNG's chart has no WIP accounts — so the transfer is not a naming decision, it is a structural one.

### 3.3 No financial statements

- Routes expose journal list, trial balance, export, and tax schedules. There is no P&L, balance sheet, cash flow, or account statement — and structurally there could not be, with no revenue, no equity, and no opening balances.

- `JournalEntryController::trialBalance()` is honest about this: it returns `is_statutory_trial_balance: false` and lists what is excluded. That is the right behaviour for a subledger, but it means the module cannot answer *"did we make money this month"* or *"what do we owe*".

### 3.4 Bank and cash

- Petty cash is well built. Everything above it is not.

- **No bank account ledger.** `1010 Bank – Main Account` (`ChartOfAccountSeeder.php:42`) is the only bank account and it moves only when a spend voucher or payroll payment posts (`PaymentSourceSeeder` maps `BANK-MAIN` and `CARD` both to `1010` — the company card is not a separate account).

- **No statement import, no bank reconciliation.** There is no `BankStatement`, no `BankTransaction`, no reconciliation model. `FinanceReadinessController` checks petty-cash reconciliation only.

- **Bank and cash movements not raised as a voucher are invisible to the GL.** The supplier-payment rail (`postSupplierPayment`) is the one place a bank account is credited, and it is gated on the invoice having posted — so a direct bank transfer, a card charge, or a mobile-money float top-up leaves no trace.

- **`config/finance_accounts.php` confirms the gap is structural, not an oversight:** `1200 Raw-material Inventory`, `1330 Input VAT`, `2150 Output VAT`, `2120 WHT Payable`, `2200 Client Deposits`, `1300/1310/1320 Advances`, `1340 Prepaid`, `1600 Leasehold`, `2300 Loans` — *"none of them exist"* in WNG's chart. The first four *"matter most"* and are commented out awaiting Finance sign-off. Until they are uncommented, the stores flow this system is built around **cannot post at all** on WNG's real chart.

### 3.5 Payroll

- Payroll now posts (`PayrollFinancePostingService.php`, in `app/Modules/HR`, not Finance). `postAccrual()` writes `Dr 7550 Salaries & Wages / Cr 2160 Net Payroll Payable (+ 2130 PAYE, + 2140 Other deductions)`; `postPayment()` writes `Dr 2160 / Cr <payment source>`.

- **All gross goes to `7550` (opex).** There is no split of direct project labour into `1212 WIP – Direct Labour`, so project labour cost is missing from job costing. `OperationalExpenseCodes` seeds `DL-CAS-001`/`DL-CAS-002`/`DL-ALW-001` with `default_debit_gl => 1212`, but the payroll path does not consult the expense catalogue at all — it hard-codes `7550` (`PayrollFinancePostingService::SALARIES_EXPENSE`, line 17). The catalogue and the payroll writer disagree about where labour goes, and the catalogue is the one that loses.

- **No employer statutory contributions.** Only employee deductions are posted (`PAYE`, `other deductions`). Employer NSSF and housing levy are neither captured from HR nor posted. Employer cost is understated by the full statutory amount, and the liability does not exist for the return.

- **No WHT on employment income.** `WhtCategory` and `TaxResolver` exist for supplier payments; the payroll path does not call either.

### 3.6 Fixed assets

- `Assets/` is a register. `Asset.php:49,68` carries `purchase_cost` and `current_value` as hand-typed decimals; `AssetResource.php:53` exposes `current_value` as a float.

- **Zero depreciation logic anywhere.** No schedule, no monthly charge, no disposal. `1900 Accumulated Depreciation` and `6500 Machinery Depreciation` are seeded (`ChartOfAccountSeeder.php:123`) and never written by anything — grep-confirmed on 2026-09-07.

- **No capitalisation from purchase.** `NonExpenseCodes` seeds two reference rows (`:420`–`:429`, `:454`–`:486`) whose `inventory_treatment` says *"Create asset record and depreciate"* and *"Capitalise approved build cost and depreciate"*, but no workflow creates an `Asset` from a verified cost or a purchase order. The threshold exists as a `FinanceSetting` (`FinanceSettingsSeeder.php:49`) and nothing reads it for a capitalisation decision.

- `current_value` is a stored number, not a computed one. It drifts from `purchase_cost` by whatever a user types, and nothing reconciles the two.

### 3.7 Inventory ↔ GL will drift

- **`StockCountController::approve()` adjusts quantities and writes an `inventory_log` — no journal.** `InventoryService::adjustStock()` returns a log row; nothing in the call chain touches `JournalPostingService`, `JournalEntry`, or `JournalLine`. So `1200 Raw-material Inventory` in the GL cannot equal the stores valuation, and the drift is permanent by construction.

- **Opening inventory entered no GL opening balance.** `StockCount::MODE_OPENING` creates `Stock` rows and `StockCountItem` rows; on approve it updates `library_materials.unit_cost` and posts an `inventory_log`. No opening-balance journal exists anywhere, so the first stock count establishes a physical position with no corresponding asset in the ledger.

- **Standard costing with no purchase-price variance account.** `StoresCostProducer::plannedUnitRate()` values issues at the budget rate when no receipt cost exists (documented in `finance-module-gap-analysis-2026-08.md` §"Stores issues could not be valued"). Every such line is flagged `details.valued_at_plan`, which is the right disclosure — but the variance between budget rate and actual receipt cost has nowhere to post, so it is silently absorbed into WIP.

### 3.8 Reversal is only half-built

- `JournalPostingService::reverseCostLine()` exists, is idempotent, posts in the current open period, and sets `reversal_of_id`. Good.

- **Spend vouchers, supplier bills, supplier payments and payroll journals have no reversal path and no route.** `SpendVoucherController` has no reverse endpoint; `JournalPostingService` has no `reverseVoucher()`/`reverseSupplierPayment()`; `PayrollFinancePostingService` has no reverse method. A mis-posted voucher or a payroll run posted to the wrong month is correctable only by direct database edit.

- `routes/api.php` exposes `POST {cost}/reverse` for cost lines only. Grep for `reverse` across the finance module on 2026-09-07 returns exactly that one route plus the service method.

### 3.9 Period control is a CLI command

- `ClosePeriodCommand` runs a real month-end checklist (open cost lines block, unposted verified costs block, unclaimable input VAT warns, WHT informs, journals must balance). Good, but it is a CLI command.

- **No open/lock/close endpoints and no screen.** `routes/api.php` has no accounting-period routes under `finance`. Periods exist only because `AccountingPeriodSeeder` made them, and `AccountingPeriod::isOpen()` is enforced on cost verification and voucher posting but cannot be changed through the API.

- **No year-end close to `3200 Retained Earnings`.** `ChartOfAccountSeeder` does not even seed `3200`. There is no equity roll-forward mechanism at all.

### 3.10 Two journal writers

- `JournalPostingService` is documented and enforced as the single writer. `PayrollFinancePostingService` (in `app/Modules/HR`) creates `JournalEntry`/`JournalLine` directly with its own validation, its own period check, its own account resolver, and its own `entry_no` scheme (`JE-PR-A-*`, `JE-PR-P-*`).

- This is the exact divergence pattern that caused the petty-cash board-request problem: two writers, two rule sets, no shared invariant. Concretely:

  - Payroll bypasses `PostingRule` resolution entirely — it hard-codes four chart codes (`SALARIES_EXPENSE = 7550`, `PAYE_PAYABLE = 2130`, `STATUTORY_PAYABLE = 2140`, `NET_PAYROLL_PAYABLE = 2160`). A chart renumber moves the postings and nothing notices.

  - Payroll bypasses the `PostingRule`/`ExpenseCode` mapping that every cost line goes through, so the two writers can disagree about where a cost goes — and they already do, on labour (`7550` vs `1212`).

  - Payroll posts no `cost_centre_id`, `activity_id`, `project_id`, or `project_enquiry_id` on its `JournalLine`s (line 133–137). A payroll journal cannot be sliced by job, which is the one cut the cost account statement is built around.

  - Payroll does not consult `config/finance_accounts.php` at all, so the reference-code→local-code mapping is invisible to it.

### 3.11 Multi-currency is cosmetic

- `currency` and `fx_rate` columns exist on `journal_lines`, `cost_lines`, `spend_vouchers`, and `bills`/`bill_payments` (indirectly). `JournalPostingService::base()` multiplies by `fx_rate` to produce `base_amount`.

- **There is no exchange-rate table, no rate source, no revaluation, and no realised/unrealised FX accounts.** Grep for `exchange_rate`, `fx_rates`, `ExchangeRate`, `revaluation` across the whole repo on 2026-09-07 returns exactly one hit — a comment in `docs/cost-capture-audit.md:111` describing the gap.

- `assets.purchase_cost_usd` has no rate behind it. `enquiry_payments` has no currency column. A foreign invoice, a USD asset, or a non-KES receipt are all indistinguishable from KES in this system.

- The trial balance sums `base_amount`, so mixed-currency entries are aggregated as if they were KES. There is no mechanism to restate them.

## 4. Structural disconnects (the parts that will keep breaking)

### 4.1 The chart of accounts is a reference chart, and WNG's real chart is unmapped

`config/finance_accounts.php` is a mapping from canonical four-digit codes to WNG's local codes. As of 2026-09-07 **every single entry is commented out**. The file's own comment says this is *"a proposal awaiting Finance's sign-off, not a configuration."*

That means the expense catalogue posts to codes like `1211`, `1330`, `2150` — and on WNG's production chart those accounts do not exist. The file lists ten accounts with no counterpart at all: `1200`, `1330`, `2150`, `2120`, `2200`, `1300`, `1310`, `1320`, `1340`, `1600`, `2300`. The first four *"matter most"* — without `1200` the stores flow cannot post, and without the tax accounts the schedules have nothing to accumulate against.

This is not a data-entry gap. It is the single decision that gates the entire module on WNG's real chart, and it has been sitting in a config file for weeks.

### 4.2 The supplier rail recognises no input VAT

`bills` carries a single `amount` and no tax columns (`migration 2026_09_05_000001_add_supplier_invoice_verification_to_bills`). `JournalPostingService::postSupplierInvoice()` and `postSupplierPayment()` are deliberately net-only, and the code says so (`JournalPostingService.php:690`).

So the procurement path — which is where most of WNG's spend originates — contributes nothing to the VAT input schedule. `TaxScheduleService` reads `cost_lines.tax_amount`, and a bill's VAT never reaches a cost line. **The VAT return is being prepared from a subset of input VAT that excludes every procurement invoice, and the exclusion is silent.**

Closing it needs tax capture on `bills` (schema, form, eTIMS fields) so a supplier invoice can price its own VAT and WHT the way `CostTaxPricer` already does for a verified cost line. That is a schema change, not a service change.

### 4.3 The Cost Collector is the only producer wired to the GL

Per `cost-collector-integration.md`: Projects budget ✅ · Petty Cash ⚠️ command-only (`BackfillPettyCashCostsCommand`) · **Stores ❌ · HR overtime ❌ · Procurement ❌ · Logistics ❌ · Payroll ❌**.

Payroll is the sharpest example: it *does* post journals, but through its own service, bypassing the cost collector entirely. So a payroll run appears in the GL (as `7550` opex) and does not appear in the project cost account at all. The two systems that both move money cannot see each other.

Stores is the cheapest win — `inventory_logs` already carries `project_id` and `receipt_unit_cost` — but `StoresCostProducer` has no caller outside tests.

### 4.4 The GL is a write-only ledger with no balance control

Every entry is constructed balanced (`total_debit === total_credit` by construction), so `is_balanced` in the trial balance proves the posting code works and says nothing about the business. There is no:

- **Account balance reconciliation** against source systems (no `1200` reconciled to Stores, no `2100`/`2150` reconciled to the procurement subledger, no `1330` reconciled to the VAT schedule);

- **Suspense or clearing accounts** — the voucher path debits `2100`/`2150` control accounts directly, and nothing nets them against the liabilities that created them;

- **An imbalance detector** — a posting that lands on the same account twice, or misses a leg, is balanced and invisible.

The `FinanceReadinessController` checks a subset of these (inactive accounts, missing periods, unbalanced journal lines) but not the cross-system reconciliation that actually catches drift.

### 4.5 Reversal is not a general primitive

`reverseCostLine()` is the only reversal in the module, and it is private to the cost-line path. Reversal needs to be a property of `JournalEntry` — an entry knows its `reversal_of_id`, its `status` (`posted`/`reversed`), and its compensating entry — and every writer should produce it. Today only one of four writers does.

Related: `CostVerificationService::reverse()` throws if `posted_at` is set, directing the user to *"reverse by journal entry instead"* — and no journal-reversal route existed when that comment was written. The door it points at has since been built for cost lines only.

## 5. What changes the module from a cost subledger into a set of books

Items 1–3 below are what make the difference between *"we can tell you what we spent"* and *"we can tell you whether we made money on this job."* Everything else is control hardening.

### 1. Order-to-cash (AR)

- Invoice **line items** — a `project_invoice_items` table linking the invoice to the quote/budget line, the milestone, the quantity, the unit price, and the VAT treatment. An invoice is currently a subtotal someone types.

- **Invoice issue posts the journal:** `Dr 1100 Accounts Receivable / Cr 4100 Project Revenue / Cr 2110 Output VAT Payable`. Output VAT is currently never declared — WNG claims input VAT every month and files nothing on the output side.

- **Receipt posts the journal:** `Dr 1010 Bank / Cr 1100 AR`, allocated against the invoice. `allocatePaymentToInvoice()` currently writes a raw pivot and fires no event.

- **VAT treatment on the invoice line**, derived from the quote/budget line rather than typed, so output VAT is computed not entered.

- **Credit notes** as negative invoices with their own reversal journal.

- **Customer master + aging.** A debtor table, statement generation, and overdue buckets. Currently `ProjectInvoice` links to `ProjectEnquiry`, not to a counterparty.

- **Deposits (`2200`) and retentions (`1110`)** as liability movements when taken and released.

- **`enquiry_payments` needs `currency` and `unique(transaction_reference)`** — both open since the July audit.

### 2. WIP → cost of sales on revenue recognition

- When an invoice is issued (or a milestone completes), release the job's accumulated `1211`–`1219` WIP to `5100`–`5900` Cost of Sales. The seeder's own `recording_rule` already describes this; nothing implements it.

- This closes the matching gap, makes the `5xxx` accounts real, and produces a gross-margin line. Without it WIP sits on the balance sheet forever and a job spanning a month-end carries its cost forward against revenue earned in a prior month.

- **Decide the WIP question for WNG.** `config/finance_accounts.php` already drafts the choice: keep WIP (capitalise project cost, release on completion) or expense it on purchase (what QuickBooks appears to do). The draft maps `1211 => COS-008`, `1212 => PE-007` — sound mappings that turn WIP into an expense. Either way the transfer has to exist; the current state is neither.

### 3. Payroll: direct labour to WIP + employer statutory cost

- Split gross pay by `job_id_rule` on the expense code: direct labour → `1212 WIP – Direct Labour`, everything else → `7550`. Currently the payroll service hard-codes `7550` for all gross and ignores the catalogue entirely.

- Capture employer NSSF and housing levy from HR and post them to their own liability accounts. Currently only employee deductions are posted; employer cost is understated by the full statutory amount.

- Fold payroll posting behind `JournalPostingService` so there is one writer, one `PostingRule` resolution, one `entry_no` scheme, and one place the `finance_accounts.php` mapping is consulted.

### 4. Inventory to GL

- **Stock count variance → journal.** `StockCountController::approve()` writes an `inventory_log`; have `StoresCostProducer` (or a new listener) post `Dr/Cr 1200` for the variance so the GL inventory equals the stores valuation.

- **Opening inventory → GL opening balance.** On approval of a `MODE_OPENING` count, post `Dr 1200 / Cr <opening-balance account>` for the total valued stock.

- **Purchase-price variance account.** Standard costing needs a `PPV` account so the difference between budget rate and actual receipt cost stops being silently absorbed into WIP.

### 5. Generalise reversal

- Make reversal a property of `JournalEntry`: `reverse()` on the service, an endpoint per writer (voucher, supplier invoice, supplier payment, payroll), idempotent on `reversal_of_id`, always posting in the current open period.

### 6. Period control API + screen

- Open/lock/close endpoints gated on a period-management permission, plus a screen. `ClosePeriodCommand` has the checklist; it needs to be reachable.

- Year-end close to `3200 Retained Earnings` (not yet even seeded).

### 7. Chart sign-off

- Uncomment (or replace) the mappings in `config/finance_accounts.php` and add the ten missing accounts to WNG's chart. This gates everything in §3.4 and the stores flow.

### 8. Multi-currency

- Exchange-rate table with a source, revaluation at period end, and realised/unrealised FX accounts. `fx_rate` on journal lines is currently a number nobody validates.

## 6. Sequencing

Ordering matters because several of these make later work harmful if done out of sequence:

1. **Chart sign-off (§5.7) before anything posts on WNG's real chart.** The mapping is entirely commented out; until it is not, the stores flow cannot post at all on production.

2. **AR journals (§5.1) before WIP release (§5.2).** The release trigger is revenue recognition, and revenue recognition needs the AR journal to exist.

3. **Single payroll writer (§5.3) before direct-labour-to-WIP.** Routing labour through `1212` is meaningless while the payroll service and the cost collector disagree about where labour goes.

4. **Inventory → GL (§5.4) before period close.** `ClosePeriodCommand` checks that verified costs reached the ledger; it cannot check that `1200` reconciles to Stores until something posts the variance.

5. **Reversal (§5.5) before period lock.** Locking a period with no reversal path makes every prior mistake permanent.

6. **Period API (§5.6) last of the controls.** It only becomes meaningful once the things it guards are built.

---

## 7. Relationship to existing docs

- **`finance-ledger-purpose.md`** — the decision this analysis flows from. It argues for the subledger scope; this document works out what that scope costs and what would close it.

- **`finance-module-gap-analysis-2026-08.md`** — the prior gap list. Several of its items are now closed (§2.1 lists them). Its G1–G13 and Phase A–E remain the engineering sequence for the control-hardening half; this document covers the finance-cycle half.

- **`finance-operational-review-2026-09-05.md`** — the operational readiness pass. Confirms the subledger invariants hold at runtime; does not address the gaps above.

- **`quote-to-cash-redesign.md`** — the AR/billing redesign. §3.1 here is the same hole, re-derived from the Finance side. Do not build two billing engines; this is Phase 2 of that doc.

- **`config/finance_accounts.php`** — not a doc, but the load-bearing file. Every gap in §3.4 and §4.1 traces back to its commented-out mappings.
