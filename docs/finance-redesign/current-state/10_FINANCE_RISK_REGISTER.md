# 10 — Finance Risk Register

Consolidated from all sections of the Phase 1 WNG Finance & Accounts audit, dated 2026-09-22.
Every item below was independently verified against current code with file:line evidence during
the audit; full evidence lives in the section-specific documents referenced in the last column.
Severity definitions per the audit brief:

- **CRITICAL** — could corrupt financial records, create incorrect balances, bypass authorization,
  or materially damage accounting integrity.
- **HIGH** — major workflow/control problem.
- **MEDIUM** — significant usability, architecture, or maintainability issue.
- **LOW** — minor improvement.

Severity has not been inflated because code "could be cleaner" — several areas audited (money
handling, transaction locking, delete guards, period-close enforcement) were found to be
genuinely strong and are noted as such, not flagged here.

---

## CRITICAL

| # | Issue | Evidence | Source |
|---|---|---|---|
| C1 | Twelve GL account codes (`VAT_INPUT_CODE`, `WHT_PAYABLE_CODE`, `INVENTORY_CODE`, `ACCRUED_CODE`, `PAYABLE_CODE`, `STAFF_ADVANCE_CODE`, `BANK_CHARGES_CODE`, plus 5 payroll codes) are hard-coded as literal reference-chart numbers in `JournalPostingService`/`PayrollFinancePostingService`, bypassing the `ChartAccountMap` translation layer built specifically to avoid this. The repository's documented mnemonic chart does not carry most of these numeric codes. **If that document matches the deployed `chart_of_accounts` table, most VAT/WHT/Inventory/Accrued/Payable/bank-charge/PAYE postings will throw `InvalidArgumentException` at posting time. REQUIRES WNG CONFIRMATION against deployed data.** | `04_CURRENT_DATA_MODEL.md` §4.2 |
| C2 | `PurchaseOrderController::update()` has no status check whatsoever — an approved, delivered, invoiced, or paid PO's items/supplier/total can be silently rewritten by anyone with base route access. | `05_CURRENT_WORKFLOWS.md` Part B.7 |
| C3 | `PurchaseOrderController::destroy()`'s pending-only guard is **commented out in the source**. Because `purchase_order_items`, `goods_receipt_notes`, and `bills` all cascade-delete on `purchase_order_id`, deleting an approved+paid PO cascade-deletes its Bill(s)/GRN(s) at the DB level, bypassing `Bill::destroy()`'s own protection and orphaning already-posted `JournalEntry`/`BillPayment` rows — a real path to destroying part of the audited GL trail for a transaction that already moved cash. | `05_CURRENT_WORKFLOWS.md` Part B.7 |
| C4 | `postSupplierInvoice()` (Bill posting — the highest-value AP entry point) and `reverseEntry()` bypass `postBalancedEntry()`, the one place `bccomp(debit,credit)` is actually asserted. Balance holds only "by construction," never independently verified before writing — the one gap that could let debits≠credits reach the ledger. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.2 |
| C5 | Petty-cash disbursement's GL-posting call is wrapped in `catch (\Throwable $e) { Log::warning(...) }` — the exception is swallowed, the HTTP response still reports success, and real cash can leave the tin while the General Ledger silently records nothing for it, with zero user-facing signal and no automated reconciliation to catch it later. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part B.3 |
| C6 | `PettyCashService::clearAllData()` / `DELETE clear-all` is a live, routed endpoint that hard-deletes **every** Payment, top-up, allocation, and ledger-entry row for petty cash in one transaction, with no date range, no soft delete, no export-first step. Restricted to Super Admin, but a destructive capability of this shape should not exist in a production financial system at all. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §16 |
| C7 | Payroll's cash-out event creates **no `Payment` row, no money-movement record of any kind** — only a `JournalEntry` plus four reference columns on `PayrollRun` itself. WNG's largest recurring cash outflow is invisible to every Payment-based control (fund custody, reversal, bank reconciliation). | `05_CURRENT_WORKFLOWS.md` Part D.3 |

## HIGH

| # | Issue | Evidence | Source |
|---|---|---|---|
| H1 | Orphaned primary spending entry point: `/finance/spend` (`SpendEntryView.vue`), coded as "one door for spending," is absent from the nav menu and has zero inbound links — dead scaffolding despite being the intended triage step between cost/petty-cash/PO flows. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-1 |
| H2 | Duplicated financial totals independently writable in more than one place: `petty_cash_balances.current_balance` vs `petty_cash_ledger_entries.balance_snapshot`; `bills.paid_amount`/`balance` vs `SUM(bill_payments.amount_paid)` (Eloquent-event-synced only); `spend_voucher_allocations` vs `payment_allocations` (both live simultaneously). | `04_CURRENT_DATA_MODEL.md` ISSUE-1 |
| H3 | No check (or DB unique constraint) prevents the same supplier + same `supplier_invoice_number` being entered twice as two separate `Bill` rows — the most common real-world duplicate-invoice scenario, which would double the recognized liability once both are verified. | `05_CURRENT_WORKFLOWS.md` Part B.3 |
| H4 | Two disagreeing "Fully paid" definitions for AR: per-invoice (allocation-based, correct) vs project-level (`FinanceService::getPaymentProgress()`, receipt-vs-quote, no reference to invoice allocations at all). A project can show "Fully paid" while cash sits unmatched to any invoice. | `05_CURRENT_WORKFLOWS.md` Part A.5 |
| H5 | Possible double-posting on petty-cash surrender reconciliation: `CostCollectorService::postFromSource()` unconditionally posts a journal per new ACTUAL cost line, and `reconcileSurrender()` separately, unconditionally, also posts its own aggregate journal from the same amounts. No suppression found; the existing regression test does not check total journal-entry count. **Directly verifiable against live data.** | `05_CURRENT_WORKFLOWS.md` Part C.1 |
| H6 | Salary advance approval creates no `Payment`/disbursement record of any kind — the actual cash handover to the employee has zero trace in the ERP; an advance could be approved and never paid, or paid twice through two different rails, undetected. | `05_CURRENT_WORKFLOWS.md` Part C.5 / Part D.3 |
| H7 | Labour cost is never attributed to any project — payroll splits gross pay by department only; no cost producer ever posts an actual labour cost against a project's planned labour line. Every reported project margin is systematically overstated by whatever labour was actually consumed. | `05_CURRENT_WORKFLOWS.md` Part E.3 |
| H8 | Bank reconciliation `match()`/`unmatch()`/`ignore()` all hard-delete `StatementMatch` rows before writing new state — once a line is re-matched or unmatched, there is no record anywhere of what it used to be matched to, or by whom. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part B.5 |
| H9 | `Accounts` (and similarly `Admin`) holds create+approve+post+reverse+period-close permissions for Finance transactions simultaneously as one role. Mitigated by identity-level self-approval checks (no single user can approve+post the *same* document), but segregation of duties depends entirely on headcount, not role boundaries. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §14 |
| H10 | Three different, disagreeing "what does the client still owe" calculations exist (Receivables Ageing, the Client Invoices workspace's "Outstanding," and the ledger's own AR account) — two of the three UI surfaces both simply label their figure "Outstanding" with no qualifier. If the required Client Deposits/Output VAT accounts are missing from WNG's live chart, invoices may not be posting to the GL at all today (corroborates C1). | `09_REPORT_AND_DASHBOARD_AUDIT.md` §17.3 |
| H11 | Logistics vehicle-maintenance costs never reach Finance — `MaintenanceController::approve()` only flips a status field; the model has no `bill_id`/`cost_line_id`/`journal_entry_id` field despite the UI literally saying "Sent to finance for approval." The cost must be re-typed into Procurement/Bills for the vendor to be paid. | `08_CROSS_MODULE_INTEGRATION_MAP.md` |

## MEDIUM

| # | Issue | Evidence | Source |
|---|---|---|---|
| M1 | Weak/missing foreign keys across a recurring pattern of "identity" columns with no DB constraint (`journal_lines.project_id`/`project_enquiry_id`, `cost_lines.payee_id`, `spend_vouchers.supplier_id`/`payee_id`, `petty_cash_balances.last_transaction_id`). Largely deliberate (cross-module FK avoidance), but every guarantee lives in application code only. | `04_CURRENT_DATA_MODEL.md` ISSUE-2 |
| M2 | "Append-only" for `CostLine`/`JournalEntry` is a route-absence convention, not a DB or model-level constraint — a future controller/console command could mutate a posted row with no error. | `04_CURRENT_DATA_MODEL.md` ISSUE-4 |
| M3 | Legacy classification schemes live beside their replacements on the same mass-assignable row (`payments.account`/`classification` vs `payment_source_id`/`expense_code_id`; `expense_codes.default_debit_gl` string vs `default_debit_account_id` FK, derivable-stale). | `04_CURRENT_DATA_MODEL.md` ISSUE-5 |
| M4 | Five different decimal precisions in live use for money columns with no evident business reason — `payments.amount` (10,2) could theoretically overflow at ~99.99M while every sibling table allows far larger values. | `04_CURRENT_DATA_MODEL.md` ISSUE-6 |
| M5 | No `is_system`/protected-account flag on `chart_of_accounts` — nothing at the schema level prevents deactivating a control account the posting services hard-depend on. | `04_CURRENT_DATA_MODEL.md` ISSUE-9 |
| M6 | `posting_rules`, built as the intended config-driven posting mechanism, is effectively vestigial — most posting paths bypass it for hard-coded PHP constants (compounds C1). | `04_CURRENT_DATA_MODEL.md` ISSUE-10 |
| M7 | `postSpendVoucher()`/`postSupplierPayment()` are `@deprecated` in favour of `postPayment()`, but `postPayment()`'s only caller was already deleted — the "current" method is unreachable and the "deprecated" methods are what actually runs in production. | `04_CURRENT_DATA_MODEL.md` ISSUE-11 |
| M8 | `Bill.amount`/`paid_amount`/`balance` are independently mass-assignable with no re-sync on a plain edit outside the payment-event flow. | `04_CURRENT_DATA_MODEL.md` ISSUE-12 |
| M9 | Duplicate payment reference not guarded on the supplier side: `bill_payments.reference_number` has no uniqueness constraint (the client side does). | `05_CURRENT_WORKFLOWS.md` Part B.6 |
| M10 | Credit notes do not reverse their share of Work-in-Progress/Cost-of-Sales — a named, documented limitation, not an oversight; per-job margin stays slightly overstated between a credit note and a future fix. | `05_CURRENT_WORKFLOWS.md` Part A.4 |
| M11 | "Needs action" AR tab drops a project with a pending (unverified) receipt once its verified total already meets the mobilization threshold — partially mitigated by an inline badge elsewhere. | `05_CURRENT_WORKFLOWS.md` Part A.6 |
| M12 | Two independently-written settlement engines (`PaymentSettlementService`, documented as "the single" one, vs `PettyCashService::createDisbursement()`) — a future fix to one will not automatically apply to the other. | `05_CURRENT_WORKFLOWS.md` Part D.1 |
| M13 | Payroll's `HR_MANAGE_PAYROLL` is one permission for prepare/lock/pay; separation of duties is identity-only, not permission-based — structurally jams for a single-person HR function. | `05_CURRENT_WORKFLOWS.md` Part C.4; `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.5 |
| M14 | Transport/logistics actual cost has the same structural gap as labour (H7) — no cost producer posts an actual against a budgeted logistics line; transport spend shows as "unbudgeted" instead. | `05_CURRENT_WORKFLOWS.md` Part E.4 |
| M15 | Portfolio-wide "Budget vs actual, every project" screen has no billing/margin column at all, even though the single-project drill-down computes margin correctly — a Finance user cannot scan for the worst-margin job across the portfolio. | `05_CURRENT_WORKFLOWS.md` Part E.5 |
| M16 | Trial Balance's Equity section is guaranteed to silently vanish (backend inner-join + frontend filter) because nothing posts to Equity yet — indistinguishable from "this section doesn't exist" to an uninformed reader. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.6; `09_REPORT_AND_DASHBOARD_AUDIT.md` §17.2 |
| M17 | Journal source filter (backend const + frontend dropdown) exposes only 2 of 6+ real posting-source types — Finance cannot isolate "everything payroll/a supplier bill posted" in the ledger screen. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.5; `09_REPORT_AND_DASHBOARD_AUDIT.md` §17.4 |
| M18 | `JournalEntryDrawer.vue` never renders `created_by`, even though the API already returns it — who posted/reversed an entry cannot be answered from the UI. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.5 |
| M19 | "Missing tax evidence" gate checks only `etims_invoice_no`/`supplier_pin`, never `supplier_invoice_no`, even though that field exists and is already in the CSV export — pending WNG confirmation it's genuinely required evidence. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part C.3 |
| M20 | PO/Bill/GRN numbering (`generatePONumber()`/`generateBillNumber()`/`generateGrnNumber()`) still use the unlocked "read max, add 1" race the team already identified and fixed for `PAY-` numbers via `DocumentNumber::next()` — a reproducible reliability bug under concurrent submission (DB uniqueness prevents silent duplication, but causes a generic 500). | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part D.3 |
| M21 | `BillController::canVerify()` is a hard-coded 3-role array, not a permission constant — widening/narrowing who may verify a supplier invoice for payment requires a deploy, not an admin-screen edit. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.3 |
| M22 | No approver-initiated rejection path for Spend/Payment Vouchers — only the requester's own `cancel()` reaches `rejected`; an approver who disagrees has no formal way to refuse it. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.1, §22.3.a |
| M23 | `FINANCE_EXPENDITURE_EXCEPTION_APPROVE` and `APPROVALS_SELF_APPROVE` are granted to no role but Super Admin — every budget-overrun exception and every self-approval override currently requires a Super Admin account. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §14 |
| M24 | Payroll's journal-entry posting authority sits with HR (`HR_MANAGE_PAYROLL`), not with any Accounts/Finance permission — a governance question, not a code defect. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §14 |
| M25 | Supplier bills and purchase orders have zero capacity to attach the actual invoice PDF/scan or signed PO copy — real exposure for KRA input-VAT claims and supplier disputes. | `05_CURRENT_WORKFLOWS.md` Part F.2 |
| M26 | Cost-evidence receipts (may show supplier name, amounts, KRA PIN) are stored on the **public** disk, reachable via a guessable URL — the team has already fixed this exact problem class for a different document type but not for Finance evidence. | `05_CURRENT_WORKFLOWS.md` Part F.2 |
| M27 | No linkage between an asset's acquisition cost (paid via Procurement/Bills) and its record in the Assets register — depreciation/valuation entirely out of scope today. | `08_CROSS_MODULE_INTEGRATION_MAP.md` |
| M28 | No company-wide or Finance-specific dashboard carries any financial KPI — revenue/cash/outstanding are not glanceable anywhere outside Finance's own report screens. | `09_REPORT_AND_DASHBOARD_AUDIT.md` §18.1 |
| M29 | Client billing/receivables backend (all invoice/receipt/credit-note HTTP endpoints) is owned by `Projects\EnquiryController` (2,067 lines), not any controller inside `app/Modules/Finance` — a Finance-scoped code review or refactor will miss this entire surface. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-4 |
| M30 | Two unrelated "budget" concepts (Finance's Cost Accounts vs Projects' quote-approval Budget task) share the word `budget` and a permission-name prefix with no disambiguation anywhere in the UI. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-5 |
| M31 | Terminology split: backend model/table/route is `SpendVoucher`; every user-facing label and frontend path is "Payment Voucher" — slows onboarding and bug triage. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-2 |

## LOW–MEDIUM

| # | Issue | Evidence | Source |
|---|---|---|---|
| LM1 | `bills` has a registered `update()` route with no controller handler — a live, untriaged 500-trap, already flagged in the team's own prior sweeps and still unresolved. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part D.4 |
| LM2 | WHT monthly schedule never shows net-amount-paid to the supplier, even though the exact figure is already computed elsewhere in the same codebase. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part C.4 |
| LM3 | No standard "payment terms" concept on client invoices (Net 30 etc.) and no discount field/audit trail — invoices are typed with a free-form due date and an already-discounted unit price. | `05_CURRENT_WORKFLOWS.md` Part A.3 |
| LM4 | Duplicate "trial balance" surface between General Ledger's "Account summary" tab and Financial Reports' "Trial balance" tab, under two different labels, no cross-link. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-3 |
| LM5 | Six-letter word for "this transaction no longer counts" is spelled five different ways across tables (`reversed`/`voided`/`cancelled`/`void`/workflow-`rejected`) — a reporting-tooling cost, not a data-integrity defect. | `04_CURRENT_DATA_MODEL.md` §3.3 |
| LM6 | Inconsistent soft-delete policy — only `petty_cash_requisitions` is soft-deletable among all Finance tables; every other financial table is hard-delete-capable at the schema level. | `04_CURRENT_DATA_MODEL.md` ISSUE-7 |
| LM7 | Finance work-queue `reassign` exists, is permission-correct, but has zero frontend caller; `release` cannot hand a claim to a named peer. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.4 |
| LM8 | No dedicated UI tab for reversed/rejected payment vouchers — both statuses are visible and color-coded under "All," just not filterable. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §16 |
| LM9 | No amount-based approval escalation tier for spend vouchers (contrast Procurement's genuinely value-aware `PurchaseApprovalPolicy`). | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.1 |
| LM10 | Input VAT claim screen omits Treatment/Supplier-invoice columns that already exist in the same data and the CSV export — a template-only gap. | `06_ACCOUNTING_POSTING_ANALYSIS.md` Part C.5 |
| LM11 | No test file targets SpendVoucher's approve/cancel/post workflow states directly, despite it being the most complex, highest-line-count Finance controller. | `13_TARGET_REDESIGN_INPUTS.md` (technical debt inventory) |

## LOW

| # | Issue | Evidence | Source |
|---|---|---|---|
| L1 | `FinanceContextCard.vue` and `LabourClassificationController` are dead/orphaned — zero consumers found for either. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-6 |
| L2 | `/finance/setup` is labelled three different ways across route meta, nav rail, and in-page title. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-7 |
| L3 | Three `@deprecated` methods remain in `JournalPostingService` with no confirmed-clear call-site check yet performed. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-8 |
| L4 | `FINANCE_PETTY_CASH_*_LEGACY` permission constants sit alongside their current replacements with unconfirmed live grants. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-9 |
| L5 | Finance module has two separate migration directories (main + PettyCash sub-folder, ~8 months apart). | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-10 |
| L6 | Finance has no dedicated `Routes/` folder, unlike every sibling module — ~90 routes live inline in the monolithic `routes/api.php`. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-11 |
| L7 | `PayrollDisbursement.vue` breaks the module's own sub-folder convention. | `03_CURRENT_NAVIGATION_MAP.md` ISSUE-12 |
| L8 | `EnquiryPayment` lives in root `App\Models` rather than `App\Modules\Finance\Models`, unlike every sibling AR model. | `04_CURRENT_DATA_MODEL.md` ISSUE-8 |
| L9 | Hardcoded `/1.16` VAT-inclusive conversion inside `QuoteInsightsService`'s advisory margin calculation, contradicting the rest of the system's "never hardcode the rate" rule; advisory-only, does not touch the ledger. | `05_CURRENT_WORKFLOWS.md` Part E.7 |
| L10 | Client-computed, unvalidated `task_budget_data.budget_summary.grandTotal` gates the Budget-task completion workflow and an advisory margin signal, with no server-side recomputation. | `05_CURRENT_WORKFLOWS.md` Part E.6 |
| L11 | `HRAuditLog` is the de-facto general-purpose audit log for the whole application, despite being named after and living in the HR module — a Finance developer must import an HR-named class to log a Finance event. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §22.2 |
| L12 | No Finance model carries a generic before/after field diff for ordinary edits except one petty-cash top-up case — mitigated because posted records are largely protected from mutation. | `07_APPROVAL_AND_PERMISSION_MATRIX.md` §22.3 |
| L13 | Orphaned cost-evidence uploads (no DB row at upload time, no cleanup job) accumulate on storage — inert until referenced, no financial-integrity impact. | `05_CURRENT_WORKFLOWS.md` Part F.2 |
| L14 | Several very large controller/service/component files (`PettyCashRequisitionController.php` 2,009 lines, `JournalPostingService.php` 1,947 lines, `RequisitionForm.vue` 2,466 lines) — maintainability debt, not a correctness risk; each has natural extraction seams already visible (e.g. per-`DB::transaction()` block). | `13_TARGET_REDESIGN_INPUTS.md` (technical debt inventory) |

---

## What was checked and found *genuinely strong* (not risks — noted so this register isn't read as universally negative)

- Money handling is decimal-consistent throughout — no `float`/`double` money columns found anywhere.
- Tax rates are 100% DB-driven, effective-dated, and correctly resolved by transaction date, not
  today's date.
- Balance-mutation paths are consistently wrapped in `DB::transaction()` with `lockForUpdate()` —
  no HIGH/CRITICAL race condition was found in the highest-risk money-movement code sampled.
- Financial period locking is genuinely enforced at the service layer before every ledger write,
  with a row-locked re-check immediately before spend-voucher posting specifically.
- The three-way match on supplier bills is tamper-evident (sha256 fingerprint), not just
  tamper-logged.
- Reversal (journal and payment) is additive everywhere traced — a new offsetting entry, never a
  mutation of the original.
- Delete guards on Bills, PettyCashTopUps, and PettyCashRequisitions are mature and audit-conscious.
- Project margin for a single project is computed live from posted ledger transactions, not a
  cached or duplicated value — a genuinely well-architected result.
- GRN-accrual double payment (a previously-known risk class) is already fixed, enforced at four
  independent layers.
- A full, well-guarded credit-note workflow exists for client invoices.

---

*Every issue above maps to a KEEP/KEEP&IMPROVE/REDESIGN/MERGE/REMOVE classification in
`11_KEEP_REDESIGN_MERGE_REMOVE_MATRIX.md`, and every open business decision is consolidated in
`12_WNG_CONFIRMATION_QUESTIONS.md`.*
