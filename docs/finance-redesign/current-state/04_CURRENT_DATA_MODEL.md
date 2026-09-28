# 04 — Current Data Model & Chart of Accounts Audit

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Read-only inspection of
migrations, seeders and models. No code changed, no migration run, live DB not queried — every
claim is sourced from code as it exists on disk today. Where `chart-of-accounts-mapping.md`
(read 2026-09-07) is cited for a live-production-chart fact, that is flagged for WNG to reconfirm,
not re-asserted as this audit's own observation.

---

## Table inventory

### A. General ledger core

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `chart_of_accounts` | The account master | id | `code` (unique), `name`, `category`, `account_type`/`normal_balance` (nullable), `parent_id` (self FK), `is_postable`, `is_active` | self-referencing; referenced by every posting table | Every posting service | `account_type`/`normal_balance` nullable — a row can be unclassified and still postable. No `is_system`/protected flag at all. |
| `journal_entries` | GL entry header | id | `entry_no` (unique), `posting_date`, `accounting_period_id`, `total_debit`/`total_credit`, `status` (draft/posted/reversed), `reversal_of_id` (unique self FK), `source_type`+`source_id`, `cost_line_id`, `spend_voucher_id` | hasMany `journal_lines`; belongsTo `AccountingPeriod`/`CostLine`/`SpendVoucher` | `JournalPostingService::postBalancedEntry()` (the single write funnel) | Two redundant "what produced this" columns (`cost_line_id`/`spend_voucher_id` typed FKs **and** `source_type`/`source_id` polymorphic pair) populated in parallel for the same entry. |
| `journal_lines` | GL entry legs | id | `account_id` FK (RESTRICT), `entry_type`, `amount`, `currency`, `fx_rate`, `base_amount`, `cost_centre_id`, `activity_id`, `project_id`, `project_enquiry_id` | belongsTo `JournalEntry`, `ChartOfAccount` | Every posting path | `project_id`/`project_enquiry_id` are plain `unsignedBigInteger`, no FK. |
| `posting_rules` | Data-driven debit/credit pair by expense code/voucher type | id | `expense_code_id`, `voucher_type`, `payment_source_id`, `debit_account_id`, `credit_account_id`, `priority`, `effective_from/to`, `is_active` | belongsTo `ExpenseCode`, `PaymentSource`, `ChartOfAccount` ×2 | `resolveRuleForCostLine()` only | Built as *the* config-driven resolution mechanism, but most posting paths bypass it for hard-coded PHP constants — effectively vestigial today. |
| `accounting_periods` | Month locking | id | `year`+`month` (unique), `status` (open/locked/closed), `locked_by/at`, `reopened_by/at`, `reopen_reason` | Referenced by cost_lines, journal_entries, spend_vouchers, project_invoices, payroll_runs | `assertOpenPeriod()` on every write | None found — well-built. |
| `period_audit_logs` | Audit trail for period lock/reopen actions | id | (migration `2026_09_13_000001`) | → `accounting_periods` | Period lock/unlock actions | Not deep-dived; consistent with sibling audit tables. |

### B. Cost Collector (project cost ledger)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `cost_lines` | One cost fact; budget AND spend share this table via `nature` | id | `ref` (unique), `project_id`/`project_enquiry_id`/`job_number` (3 parallel identities), `nature` (planned/committed/accrued/actual), `status` (draft/submitted/queried/verified/rejected/reversed), `amount`/`tax_amount`/`net_amount`/`base_net_amount`/`wht_amount`, `expense_code_id`, `payee_type_id`+`payee_id`, `journal_entry_id`, `settled_by_payment_id`, `settled_by_bill_id`, `consumes_line_id` (self FK) | belongsTo ExpenseCode/VatTreatment/WhtCategory/JournalEntry/Payment, self | `postCostLine()`, `CostLineController`, budget/variance reporting | `$guarded=['id']` — every column mass-assignable. `payee_id` has no FK. "Append-only" is enforced only by the absence of an `update()` route, not by the model or DB. |
| `expense_codes` | The posting-rule catalogue as data | id | `code` (unique), `default_debit_gl` (free text, legacy), `default_debit_account_id` (FK, nullable), `default_vat_treatment_code`/`default_wht_category_code` (strings, not FKs), `is_active`, `minimum_evidence`/`extra_operational_data` (JSON) | belongsTo ChartOfAccount | JournalPostingService, CostLineController | `default_debit_gl` and `default_debit_account_id` store the same fact twice; the seeder derives the FK from the string, so the string is the real source of truth and the FK can go stale. |
| `payee_types`, `cost_centres`, `activities`, `cost_causes` | Reference dimensions | id | `code` (unique), `is_active`, booleans | Referenced by cost_lines/journal_lines | Capture/verification forms | None material — rows retire via `is_active=false`, never hard-deleted, deliberately. |
| `vat_treatments`, `wht_categories` | Effective-dated tax rate/rule masters | id | `code`+`effective_from` (unique together), `rate_percent`, `is_recoverable`, `gl_account_id` | belongsTo ChartOfAccount | JournalPostingService VAT/WHT legs, Bill | Well-designed (effective-dated, rate never hard-coded). |

### C. Spend / Payments (the disbursement & voucher rail)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `payments` (`Payment`) | **Renamed from `petty_cash_disbursements`** — every outgoing payment regardless of source | id | `payment_no` (unique), `payment_type`, `account` (free text, legacy), `payment_source_id` (FK, current), `classification` (legacy enum), `expense_code_id` (current), `project_name`/`project_id`/`job_number` (3 parallel identities), `status` (active/voided), `amount` (**decimal(10,2)**) | belongsTo PettyCashTopUp/PaymentSource/SpendVoucher (×2 relations), ExpenseCode, Project, ProjectEnquiry; hasMany PaymentAllocation; hasOne BillPayment | `postDirectPayment`/`postPettyCashAdvance`/`postPaymentFee`, BillPayment | Carries both legacy free-text classification and current structured fields on the same row. `amount` still `decimal(10,2)` (max ~99.99M) while siblings use 14/15/18,2. |
| `payment_allocations` | Direct payment → cost-line settlement links (newer redesign) | id | `payment_id`, `cost_line_id`, `amount`, `allocation_type` | belongsTo Payment, CostLine | Newer settlement path | Its own migration states it *replaces* the indirect link through `spend_voucher_allocations`, but that table is not dropped and `voucherDebitLegs()` still reads it live. |
| `spend_vouchers` | Payment document grouping multiple cost lines | id | `voucher_no` (unique), `type`, `status` (7 values), `total_amount`/`net_amount`/`vat_amount`/`wht_amount`/`net_cash_paid`, `supplier_id`/`payee_id`/`petty_cash_disbursement_id`/`petty_cash_top_up_id` (all **no FK constraint**) | belongsToMany CostLine via spend_voucher_allocations; belongsTo PaymentSource, Payment | `postSpendVoucher()` (**@deprecated**, still present) | `postPayment()`, the non-deprecated replacement, is itself unreachable (its only caller was deleted) — the codebase currently has no non-deprecated way to post a SpendVoucher. |
| `spend_voucher_allocations` | Voucher ↔ cost-line settlement pivot | id | `spend_voucher_id`, `cost_line_id`, `amount` | pivot for SpendVoucher.costLines() | `voucherDebitLegs()` | Superseded in intent by `payment_allocations` but still the live table read by posting. |
| `direct_disbursement_requests` | Direct-payment approval request | id | (migration `2026_08_10_000006`) | — | — | Not fully inspected. |

### D. Petty cash legacy tables (pre-unification, still present)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `petty_cash_top_ups` | Float top-up record | id | `amount` (10,2), `payment_method`, `external_reference` | hasMany Payment | PettyCashTopUpController | Guarded delete — cannot delete a top-up in the payment audit chain. |
| `petty_cash_balances` | **Single running balance row** per float | id | `current_balance` (10,2), `last_transaction_id` (deliberately un-FK'd) | none formal | LedgerService, FundCustodyService | Stores the same fact `petty_cash_ledger_entries.balance_snapshot` also derives — two independently-writable balance stores. |
| `petty_cash_ledger_entries` | Append-only debit/credit ledger with balance snapshot | id | `type`, `amount` (15,2), `balance_snapshot` (15,2), `reference_number`, `metadata` (JSON) | none formal | LedgerService | Coexists with `petty_cash_balances.current_balance` — see ISSUE-1. |
| `petty_cash_requisitions` | Advance/requisition workflow | id | `status` (pending/approved/rejected/disbursed/received), `total_amount` (12,2), `bill_id`, `enquiry_id`/`project_id`/`project_name` (3 parallel identities) | hasMany Item/SurrenderItem; **the only `SoftDeletes` model in the entire Finance tree** | PettyCashRequisitionController | `delete()`/`update()` are guarded server-side to pending/rejected and not-disbursed/received — a genuine positive control. |

### E. Receivables / client billing (AR side)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `project_invoices` | Client invoice header | id | `invoice_number` (unique), `subtotal`/`tax_amount`/`total_amount` (15,2) — **derived from lines, recomputed on every change**, `status` (draft/issued/paid/void), `journal_entry_id` (nullable), `credits_invoice_id` (self FK, credit notes) | hasMany ProjectInvoiceLine; belongsToMany EnquiryPayment via allocations; self-relation for credit notes | InvoicePricer, receivables ageing | Header totals are a deliberate, documented, recomputed cache — lower risk than most duplicated totals here. |
| `project_invoice_lines` | Priced invoice lines | id | `net_amount`/`tax_amount`/`total_amount` (15,2), `vat_treatment_id` (nullable), `revenue_account_id` (nullable FK) | belongsTo ProjectInvoice, VatTreatment, ChartOfAccount | InvoicePricer | None material — well designed. |
| `project_invoice_allocations` | Invoice ↔ client-payment settlement | id | `amount`, `allocated_by`, `journal_entry_id` | belongsTo ProjectInvoice, EnquiryPayment | receivables balance calc | Unique on (invoice_id, enquiry_payment_id) — reasonable. |
| `enquiry_payments` (root `App\Models`) | Client cash receipt against an enquiry | id | `amount` (15,2), `journal_entry_id` (nullable), `reversed_at`/`status` | belongsTo ProjectEnquiry, ClientReceipt | `scopeWithVerifiedPaidAmount()` | Lives outside `App\Modules\Finance\Models`, unlike every sibling AR model. |
| `client_receipts` | Bank-side record of money received | id | `received_amount`, `payment_source_id`, `evidence_path` | hasMany EnquiryPayment | Receipt recording | Not deep-dived beyond the model. |

### F. Procurement / Supplier (AP side)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `bills` (renamed from `invoices`) | Supplier invoice | id | `bill_number` (unique), `amount`/`paid_amount`/`balance` (15,2, **all three independently mass-assignable**), `status` (5 values), `verification_basis`, `verified_at`/`verified_by`, `settled_by_bill_id`, `net_amount`/`vat_amount`/`wht_amount` | belongsTo PurchaseOrder/Supplier/VatTreatment/WhtCategory/ExpenseCode; hasMany BillPayment | `postSupplierInvoice()`, SupplierPaymentGuard | Nothing stops a direct edit to `amount` leaving `balance` stale until the next payment event; `withdrawVerificationOnChange()` mitigates the verification side. No `update()` route handler exists on `BillController` — a live dangling 500-trap route. |
| `bill_payments` | Payment against a bill | id | `payment_code` (unique, via DocumentNumber), `amount_paid` (15,2), `payment_source_id`, `disbursement_id` (→ Payment), `payment_method` | belongsTo Bill, PaymentSource, Payment | SupplierPaymentGuard, `postSupplierPayment()` (@deprecated) | `bill.paid_amount`/`balance` vs `SUM(bill_payments.amount_paid)` kept in sync only by Eloquent model events — any `DB::table()` write bypasses it. `reference_number` has no uniqueness constraint at all (client side does). |
| `purchase_orders`, `purchase_order_items` | PO header/lines | id | `total_amount` (15,2), `status` (pending/approved/delivered/cancelled) | belongsTo Supplier; hasMany items | GRN, Bill matching | `items.quantity` is plain integer vs `cost_lines.quantity` decimal(14,3) — minor typing inconsistency. |
| `goods_receipt_notes`, `goods_receipt_note_items` | Stores receipt of PO goods, feeds the accrual | id | store/status fields | → PurchaseOrderItem, CostLine | `markGrnAccrualsSettledByBill()` | Not deep-dived beyond posting-service usage. |
| `suppliers` | Supplier master | id | tax fields (KRA PIN etc.) added `2026_08_10_000001` | hasMany Bill, PurchaseOrder | Bill/PO/tax posting | Not deep-dived. |

### G. Payroll (posts into the same GL)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `payroll_runs` | One payroll cycle | id | `accrual_journal_entry_id`, `payment_journal_entry_id`, `payment_source_id`, `payment_date`, `payment_reference`, `locked_by`/`paid_by` | hasMany payslips; belongsTo JournalEntry ×2, PaymentSource | `PayrollFinancePostingService` | No `Payment` row is ever created for the payment leg — see `05_CURRENT_WORKFLOWS.md`. |
| `payroll_ledgers`, `payroll_variables`, `payroll_tax_bands` | Payroll computation inputs | id | not deep-dived | — | Payroll calculation | Outside this pass's GL-posting boundary. |

### H. Reconciliation, cash movement, settings, sequencing

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `finance_reconciliation_statements` | Bank statement import header | id | `opening_balance`/`closing_balance` (**18,2**), `status` | belongsTo PaymentSource | Bank reconciliation | — |
| `finance_statement_transactions` | Imported bank lines | id | `debit`/`credit`/`statement_balance` (18,2), `fingerprint` (unique per statement), `match_status` | belongsTo statement | Reconciliation matching | `ignore_reason` added 2026-09-21. |
| `finance_statement_matches` | Bank line ↔ journal entry/payment match | id | `amount`, `match_type` | belongsTo JournalEntry, Payment | Reconciliation | No SoftDeletes — match/unmatch/ignore hard-delete history. |
| `finance_cash_movements` | Generic cash in/out ledger, separate from journal_entries | id | `amount` (18,2), `direction`, `status`, `offset_account_id`, `journal_entry_id` | belongsTo PaymentSource, ChartOfAccount, JournalEntry | Not traced to a writer in this pass | A 4th representation of "money moved" alongside journal_lines/petty_cash_ledger_entries/payments. |
| `finance_settings` | Effective-dated policy values | id | `key`+`effective_from` (unique), `value` (JSON), `approved_by/at` | none formal | Policy lookups | Well-designed. |
| `document_sequences` | Per-series document numbering with row-level locking | id | `prefix`+`period` (unique), `next_number` | — | `DocumentNumber::next()` | Replaced three independent, race-prone numbering schemes. |
| `finance_work_assignments`, `finance_work_assignment_events` | Work-queue claim/history | id | not deep-dived | — | — | — |

### I. Projects-side budget (pre-dates the Cost Collector)

| Table/Model | Purpose | PK | Important Fields | Relationships | Used By | Problems |
|---|---|---|---|---|---|---|
| `task_budget_data` | JSON-blob project budget | id | `enquiry_task_id` (unique), `materials_data`/`labour_data`/`expenses_data`/`logistics_data`/`budget_summary` (all JSON), `status` | belongsTo enquiry_tasks | Budget capture UI | Represents the same budget fact `cost_lines` (nature=planned) now represents, in a different shape. Still live, not migrated into `cost_lines`. **REQUIRES WNG/dev confirmation** of current migration status. |
| `budget_versions`, `budget_additions` | Budget revision history | id | not deep-dived | → task_budget_data | Budget approval workflow | Not deep-dived. |

---

## Cross-cutting ISSUEs

**ISSUE-1 — Duplicated financial totals/records across tables. RISK: HIGH.**
`petty_cash_balances.current_balance` vs `petty_cash_ledger_entries.balance_snapshot`;
`bills.paid_amount`/`balance` vs `SUM(bill_payments.amount_paid)` (Eloquent-event-synced only);
`spend_voucher_allocations` vs `payment_allocations` (both live); `project_invoices` totals vs
lines (mitigated, code-enforced recompute); `task_budget_data` (JSON) vs `cost_lines` (nature=planned).

**ISSUE-2 — Weak/missing foreign keys (orphan-record risk). RISK: MEDIUM-HIGH.**
A recurring, largely deliberate pattern: `journal_lines.project_id`/`project_enquiry_id`,
`cost_lines.payee_id`, `spend_vouchers.supplier_id`/`payee_id`/`petty_cash_disbursement_id`/
`petty_cash_top_up_id`, `petty_cash_balances.last_transaction_id` (explicitly documented as
"handled at the application level"). Every guarantee lives in application code, not the database.

**ISSUE-3 — Broad mass-assignment on a "posted" model. RISK: LOW-MEDIUM.**
`CostLine` uses `$guarded=['id']` — every column, including `status`/`net_amount`/
`journal_entry_id`/`settled_by_bill_id`, is mass-assignable. The only real protection is the absence
of an `update()` route on `CostLineController`.

**ISSUE-4 — "Append-only" is a convention, not a constraint. RISK: MEDIUM.**
True of the routes exposed today, but not enforced by any DB trigger, model guard, or policy — a
future controller/console command/Tinker session can mutate a posted row with no error.
*Positive counter-evidence:* `PettyCashRequisitionController` immutability guards,
`PettyCashTopUpController::destroy()`'s audit-chain check, and `JournalPostingService::reverseEntry()`'s
unique-reversal enforcement are all real, working controls.

**ISSUE-5 — Duplicated/legacy classification schemes living beside their replacements. RISK: MEDIUM.**
`payments.account`+`classification` (legacy) alongside `payment_source_id`+`expense_code_id`
(current), both `$fillable`; `expense_codes.default_debit_gl` (string) alongside
`default_debit_account_id` (FK, derived from the string and able to go stale).

**ISSUE-6 — Inconsistent monetary precision. RISK: MEDIUM.**
At least 5 different `decimal` precisions in live use for money columns, with no evident business
reason: `(10,2)` — payments/petty_cash_top_ups/petty_cash_balances; `(12,2)` —
petty_cash_requisitions; `(14,2/3/4)` — cost_lines/journal_entries/journal_lines/spend_vouchers;
`(15,2/3)` — bills/bill_payments/purchase_orders/project_invoices; `(18,2)` — reconciliation/cash
movement tables. All are `decimal`, never `float`/`double` — the precision mismatch is a real, if
unlikely, overflow risk on the oldest table (`payments`, max ~99.99M).

**ISSUE-7 — Inconsistent soft-delete policy. RISK: LOW.**
Only `petty_cash_requisitions` is soft-deletable; every other financial table is hard-delete-capable
at the schema level, protected (where protected) entirely by controller-level guards.

**ISSUE-8 — Module-boundary inconsistency for `EnquiryPayment`.** RISK: LOW. Lives in root
`App\Models` rather than `App\Modules\Finance\Models` like every sibling AR model.

**ISSUE-9 — No `is_system`/protected-account flag on `chart_of_accounts`.** RISK: MEDIUM. Nothing
marks a control account as non-deletable/non-renamable at the schema level; protection today is
entirely code-level (the seeder upserts by code and never deletes a referenced account).

**ISSUE-10 — `posting_rules` table is effectively unused.** RISK: MEDIUM. Built as "the debit/credit
pair for an event, as data," but the primary posting path resolves most accounts through hard-coded
PHP class constants instead — see the account-selection table below.

**ISSUE-11 — Dead/deprecated posting paths still reachable.** RISK: MEDIUM. `postSpendVoucher()`
and `postSupplierPayment()` are `@deprecated` in favour of `postPayment()` — but `postPayment()`'s
only caller (`UnifiedPaymentService`) was deleted 2026-09-17, so the "current" method is
unreachable and the "deprecated" methods are the ones actually running production postings.

**ISSUE-12 — `Bill.amount`/`paid_amount`/`balance` independently mass-assignable, no re-sync on
plain edit.** RISK: MEDIUM. `withdrawVerificationOnChange()` clears verification on an amount edit
but does not recompute `balance` — that only happens on the next `BillPayment` event.

## Inconsistent status-field vocabularies

No two financial tables share exactly the same status vocabulary; five different words mean
"this transaction no longer counts" (`reversed`, `voided`, `cancelled`, `void`, workflow-`rejected`):

| Table | Status values |
|---|---|
| `journal_entries` | draft, posted, reversed |
| `cost_lines` | draft, submitted, queried, verified, rejected, reversed |
| `spend_vouchers` | draft, pending_approval, approved, paid, posted, rejected, reversed |
| `payments` | active, voided |
| `bills` | pending, paid, partial, overdue, cancelled |
| `project_invoices` | draft, issued, paid, void |
| `petty_cash_requisitions` | pending, approved, rejected, disbursed, received |
| `accounting_periods` | open, locked, closed |
| `finance_reconciliation_statements` | draft, reconciled, reopened |
| `finance_cash_movements` | posted, voided |
| `finance_statement_transactions.match_status` | unmatched, matched, ignored |
| `task_budget_data` | draft, pending_approval, approved, rejected |

**RISK: LOW-MEDIUM** — a reporting/tooling cost (any cross-table "show me everything cancelled"
query needs a per-table CASE statement), not a data-integrity defect.

## Finance Entity Relationship Map (text)

```
CLIENTS / PROJECTS SIDE (Receivables)
  ProjectEnquiry (Projects module)
      └─< ProjectInvoice ────────────────< ProjectInvoiceLine >──── ChartOfAccount (revenue_account_id, nullable)
      │        │  \_ credits_invoice_id (self, credit notes)         └── VatTreatment
      │        └─< ProjectInvoiceAllocation >──── EnquiryPayment ──── ClientReceipt (bank deposit)
      │                    (journal_entry_id)          (journal_entry_id)
      └──< CostLine (project_enquiry_id / project_id / job_number — 3 parallel identities)

COST LEDGER (Cost Collector)
  ExpenseCode ──(default_debit_account_id)──> ChartOfAccount
      └─< CostLine >── VatTreatment, WhtCategory, CostCentre, Activity, CostCause, PayeeType
             ├─ consumes_line_id (self: actual → planned, budget draw-down)
             ├─ settled_by_payment_id ──> Payment
             ├─ settled_by_bill_id ─────> Bill
             ├─ voucher_id / funding_voucher_id ──> SpendVoucher
             └─ journal_entry_id ──> JournalEntry ──< JournalLine ──> ChartOfAccount

  (legacy, still live) TaskBudgetData (JSON) ── enquiry_tasks   [parallels CostLine nature=planned]

PAYMENTS / VOUCHERS (Spend rail)
  PaymentSource (bank/petty cash/mobile money/payable) ──(gl_account_id)──> ChartOfAccount
      └─< Payment [renamed from petty_cash_disbursements] >── PaymentAllocation >── CostLine
               ├─ top_up_id ──> PettyCashTopUp ──> PettyCashBalance / PettyCashLedgerEntry (2 parallel balance stores)
               ├─ requisition_id ──> PettyCashRequisition >── PettyCashRequisitionItem / SurrenderItem
               ├─ voucher_id / spend_voucher_id ──> SpendVoucher >── SpendVoucherAllocation >── CostLine (legacy path)
               └─ billPayment (hasOne) ──> BillPayment

SUPPLIERS SIDE (Payables)
  Supplier ──< PurchaseOrder >──< PurchaseOrderItem
        │             └──< GoodsReceiptNote >──< GoodsReceiptNoteItem ──(accrual)──> CostLine (source_type=GRNItem)
        └──< Bill [renamed from invoices] ──(verification_basis)── VatTreatment, WhtCategory, ExpenseCode(direct bills)
                    └─< BillPayment ──> PaymentSource, Payment(disbursement_id)

PAYROLL
  PayrollRun ──< Payslip
        ├─ accrual_journal_entry_id  ──> JournalEntry (Dr 5200/7550, Cr 2160/2130/2140 — hard-coded)
        └─ payment_journal_entry_id  ──> JournalEntry, PaymentSource

GENERAL LEDGER CORE
  ChartOfAccount (self: parent_id) <── JournalLine ──< JournalEntry (reversal_of_id: self)
        ▲                                                    ▲
        └── PostingRule (expense_code_id, payment_source_id) ┘   [built, mostly bypassed]

RECONCILIATION
  PaymentSource ──< FinanceReconciliationStatement >──< FinanceStatementTransaction >──< FinanceStatementMatch ──> JournalEntry / Payment
```

---

## Chart of Accounts Audit

### Does a proper COA exist?

**FACT.** Yes, structurally. `chart_of_accounts` has `code` (unique), `name`, `category`,
`account_type` (balance_sheet/direct_cost/overhead/opex/capex/revenue), `normal_balance`
(debit/credit), `parent_id` (self FK), `is_postable`, `is_active` — a genuinely complete COA schema.

**FACT.** There are **two charts in this codebase**:
1. A reference chart seeded by `ChartOfAccountSeeder` — 8xxx-numbered, IFRS-shaped — gated off in
   production (`config('finance_accounts.seed_reference_chart')`, defaulting to non-production only).
2. WNG's actual production chart — per `chart-of-accounts-mapping.md` (read 2026-09-07, not
   re-queried live in this pass): 123 accounts, mnemonic (`AR-001`, `AP-001`, `KCB-001`, `COS-*`,
   `OPE-*`), imported from QuickBooks, `account_type` empty on every mnemonic row.
   **REQUIRES WNG CONFIRMATION**: whether this 123-account structure still holds as of today.

**FACT.** `config/finance_accounts.php` + `App\Modules\Finance\Support\ChartAccountMap` is the
reconciliation layer between the two. As read this session, the map carries roughly two dozen
**self-mapping** entries for control accounts WNG's chart already has under the same numeric code,
plus five entries proposed but **left commented out** (the WIP→Cost-of-Sales redirect, `1211→5100`
… `1219→5900`). This confirms the existing mapping doc's "eleven control accounts do not exist" and
"the map is empty of the load-bearing WIP decision" findings are still current.

**System-controlled/protected accounts: none exist as a schema concept** (see ISSUE-9 above).
Protection is behavioural only.

**Project ↔ account relationship: indirect only.** No project carries its own GL account;
`journal_lines.project_id`/`project_enquiry_id` tag individual entries with a project dimension for
reporting/rollup, not accounts. No "this project's WIP account" concept exists — consistent with
WNG's chart having no WIP accounts.

### System-controlled account codes referenced directly from PHP

The following literal reference-chart codes are hard-coded as PHP class constants and looked up
directly (`ChartOfAccount::postable()->where('code', $code)`), **bypassing** `ChartAccountMap`:

| Constant | Code | Meaning | File |
|---|---|---|---|
| `VAT_INPUT_CODE` | 1330 | Input VAT Recoverable | `JournalPostingService.php:37` |
| `WHT_PAYABLE_CODE` | 2120 | WHT Payable | `JournalPostingService.php:39` |
| `INVENTORY_CODE` | 1200 | Raw-material Inventory | `JournalPostingService.php:50` |
| `ACCRUED_CODE` | 2150 | Accrued Expenses | `JournalPostingService.php:52` |
| `PAYABLE_CODE` | 2100 | Accounts Payable | `JournalPostingService.php:54` |
| `STAFF_ADVANCE_CODE` | 1300 | Staff Advances/Imprest | `JournalPostingService.php:56` |
| `BANK_CHARGES_CODE` | 7800 | Bank & Mobile-money Charges | `JournalPostingService.php:64` |
| `SALARIES_EXPENSE` | 7550 | Salaries & Wages | `PayrollFinancePostingService.php:21` |
| `DIRECT_LABOUR_EXPENSE` | 5200 | Cost of Sales – Direct Labour | `PayrollFinancePostingService.php:22` |
| `PAYE_PAYABLE` | 2130 | PAYE Payable | `PayrollFinancePostingService.php:23` |
| `STATUTORY_PAYABLE` | 2140 | Statutory Deductions Payable | `PayrollFinancePostingService.php:24` |
| `NET_PAYROLL_PAYABLE` | 2160 | Net Payroll Payable | `PayrollFinancePostingService.php:25` |

**ISSUE-14, RISK: CRITICAL (code-level portion is FACT; production impact is ASSUMPTION pending
WNG confirmation).** **FACT, verified directly in code:** all twelve are looked up with no call
into `ChartAccountMap::local()`. Grep confirms `ChartAccountMap::` appears in only 7 call sites
codebase-wide, while `accountByCode(self::...)` alone appears 13 times in `JournalPostingService`
and again in `PayrollFinancePostingService` — the hard-coding and the bypass of the translation
layer are not in question.

**ASSUMPTION, not independently verified against the live database this session:**
`chart-of-accounts-mapping.md` (read 2026-09-07) documents WNG's production chart as mnemonic-coded
and states it does not carry these numeric codes, except three "numeric strays" manually inserted
by migration (`2160`, `7550`, `7150`). This audit did not itself query the deployed
`chart_of_accounts` table, so it cannot independently confirm that document still matches
production today. **If it does**, the consequence is mechanical and follows directly from the code:
`accountByCode('1330')`, `('2120')`, `('1200')`, `('2150')`, `('2100')`, `('7800')`, `('1300')`, and
payroll's PAYE/statutory codes would all return `null`, and every code path that needs them —
VAT/WHT legs on any cost line or supplier bill, Inventory relief, Accrued-Expense clearing,
Accounts-Payable settlement, Staff-Advance petty cash, bank-charge posting, PAYE/statutory payroll
legs — would throw `InvalidArgumentException` at posting time, with only the payroll legs touching
`2160`/`7550` and `7150`-adjacent OpEx postings succeeding.

**REQUIRES WNG CONFIRMATION, urgently, before this finding's severity can be treated as settled:**
query the live `chart_of_accounts` table for these twelve codes. This is the single highest-priority
verification step to come out of the entire audit — see `10_FINANCE_RISK_REGISTER.md` #1 and
`13_TARGET_REDESIGN_INPUTS.md` §E for exactly what to check.

### Account-selection mechanism by transaction type

**FACT: it is inconsistently implemented — a genuine mix, varying by transaction type and even
within a single service class.**

| Transaction type | Mechanism | Evidence |
|---|---|---|
| Cost line — debit (expense) leg | Automatic (expense catalogue), hard-coded heuristic fallback only when no expense code | `resolveAccountsForCostLine()`, `JournalPostingService.php:576-631` |
| Cost line — credit (settlement) leg | Mixed automatic + hard-coded, depending on how the cost arose | `settlementAccountFor()`, `:648-680` |
| Cost line — VAT/WHT legs | Automatic first, hard-coded fallback if the treatment carries none | `:274-308` |
| Cost-line liability settlement | **Configurable** — the one place routing through `ChartAccountMap::localMany()` | `resolveVerifiedLiabilityAccount()`, `:798` |
| Posting rule lookup | Configurable via `posting_rules`, but only for cost lines, and only as first-try before hard-coded fallback | `resolveRuleForCostLine()`, `:557-573` |
| Supplier bill (3-way-match) posting | Hard-coded control accounts, automatic tax legs | `postSupplierInvoice()`/`supplierInvoiceLegs()`, `:886-1094` |
| Direct bill (no PO) posting | Automatic, throws if absent, no hard-coded fallback | `:918-924` |
| Supplier payment (cash leg) | Manual paying-account (chosen at setup), hard-coded debit leg | `postSupplierPayment()`/`postCashSettlement()`, `:1109-1239` |
| Payment transaction fee | Hard-coded debit, automatic credit | `postPaymentFee()`, `:1259-1309` |
| Petty cash advance | Hard-coded debit, automatic-with-hard-coded-fallback credit | `postPettyCashAdvance()`, `:1573-1623` |
| Petty cash surrender/reconciliation | Automatic per-item with hard-coded category fallback; hard-coded advance-clearing/VAT | `postPettyCashSurrender()`, `:1634+` |
| Direct (unallocated) payment | Automatic, throws instead of a fallback | `postDirectPayment()`, `:1321-1373` |
| Payroll accrual & payment | Entirely hard-coded (5 constants); labour split is automatic | `PayrollFinancePostingService.php:21-25,69-117,276-283` |
| Client invoice revenue account | **Automatic with manual override** — the one clear case of manual, per-transaction selection | `2026_09_08_000001_give_invoices_lines_and_a_ledger_link.php:72-73` |

**RECOMMENDATION.** Route every `accountByCode(self::X_CODE)` call (13 in `JournalPostingService`,
5 in `PayrollFinancePostingService`) through `ChartAccountMap::local()` before the chart lookup, the
same way `resolveVerifiedLiabilityAccount()` already does — the single highest-leverage fix
identified in this audit for making Finance postable on WNG's real chart without schema changes.
Implementing it is out of this audit's read-only scope.

## KEEP / REDESIGN classification by major table/model group

| Group | Classification | Rationale |
|---|---|---|
| `chart_of_accounts`, `posting_rules` schema | **KEEP** | Sound design (hierarchy, normal balance, postable flag). |
| `ChartAccountMap` / `config/finance_accounts.php` | **KEEP & IMPROVE** | Right idea, under-adopted. |
| `JournalPostingService` hard-coded account constants | **REDESIGN** | Route through `ChartAccountMap::local()` or fully onto `posting_rules`. |
| `posting_rules` table | **KEEP & IMPROVE** | Either commit to it as the real resolution path or remove it. |
| `journal_entries`/`journal_lines`/`accounting_periods`/`document_sequences`/`finance_settings` | **KEEP** | Well-designed, no material issues. |
| `cost_lines` + Cost Collector dimension tables | **KEEP** | Strong design; `$guarded=['id']` breadth is the only nit. |
| `payments` (renamed `petty_cash_disbursements`) | **KEEP & IMPROVE** | Sound unification; legacy free-text fields should be dropped/frozen. |
| `spend_vouchers`/`spend_voucher_allocations` vs `payment_allocations` | **MERGE / REQUIRES WNG CONFIRMATION** | Two live settlement-linking mechanisms; needs a decision on which is authoritative. |
| `petty_cash_balances` + `petty_cash_ledger_entries` | **MERGE** | Do not keep both as independently-writable stores of the same number. |
| `bills`/`bill_payments` stored totals | **KEEP & IMPROVE** | Cache pattern is fine but should be DB-enforced, not Eloquent-event-only. |
| `task_budget_data` (JSON budget) | **REQUIRES WNG CONFIRMATION** | Still system of record, or safe to freeze now `cost_lines` (nature=planned) exists? |
| `EnquiryPayment` module placement | **KEEP** (low priority) | Cosmetic only. |

---

*Consolidated risk ratings for every ISSUE above appear in `10_FINANCE_RISK_REGISTER.md`.
Consolidated WNG questions appear in `12_WNG_CONFIRMATION_QUESTIONS.md`.*
