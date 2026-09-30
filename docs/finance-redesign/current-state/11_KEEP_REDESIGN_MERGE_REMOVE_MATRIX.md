# 11 — Keep / Keep & Improve / Redesign / Merge / Remove Matrix

Consolidated classification of every major Finance component audited in Phase 1, dated 2026-09-22.
Classifications are as defined in the audit brief:

- **KEEP** — correct and reusable.
- **KEEP & IMPROVE** — fundamentally correct but requires improvement.
- **REDESIGN** — underlying workflow/data model/design is unsuitable.
- **MERGE** — duplicated capability should eventually be consolidated.
- **REMOVE** — obsolete/dead/incorrect functionality.
- **REQUIRES WNG CONFIRMATION** — cannot safely determine desired behaviour without a business
  decision.

No implementation is proposed here — this is a classification of current-state components only.

---

## Navigation & module structure

| Component | Classification | Basis |
|---|---|---|
| Work queue | KEEP | Single coherent page, real aggregation service |
| Costs & budgets (Cost Collector) | KEEP & IMPROVE | Core, well-built; orphaned `/finance/spend` chooser belongs here |
| Payments & cash | KEEP & IMPROVE | Functionally solid; naming mismatch and Payroll's folder placement need cleanup |
| Client billing (frontend) | KEEP | Real, working feature |
| Client billing (backend controller ownership) | REDESIGN | Lives in `Projects\EnquiryController`, not Finance |
| Controls & reports | KEEP & IMPROVE | Comprehensive; label inconsistency, possible trial-balance duplication |
| Petty Cash sub-module | KEEP | Ledger-as-truth design already implemented; healthiest sub-area found |
| Ledger/GL/Journals (frontend+backend) | KEEP & IMPROVE | Functional but carries dead `@deprecated` code and a narrow source filter |
| Reconciliation | KEEP | Recently built, sound workflow |
| Tax module | KEEP | Recently built, directly answers a real filing need |
| Permissions structure | KEEP & IMPROVE | Comprehensive but carries `_LEGACY` variants and a naming leak into Projects |
| `/finance/spend` (`SpendEntryView.vue`) | REDESIGN | Reconnect to the menu — the triage logic is good, just unreachable |
| `FinanceContextCard.vue` | REMOVE (pending confirmation) | Zero consumers found |
| `LabourClassificationController` | REMOVE (pending confirmation) | Zero frontend consumers found |
| Two "budget" concepts (Finance Cost Accounts vs Projects Budget task) | REQUIRES WNG CONFIRMATION | Shared permission-name prefix suggests intended convergence |
| Duplicate "trial balance" surface (GL vs Reports) | REQUIRES WNG CONFIRMATION | May be intentionally different scopes |
| `_LEGACY` petty-cash permissions | REQUIRES WNG CONFIRMATION | Needs sign-off before removal |
| HR payroll surfaced only via one Finance screen | REQUIRES WNG CONFIRMATION | Confirm the split is intentional |

## Data model & Chart of Accounts

| Component | Classification | Basis |
|---|---|---|
| `chart_of_accounts`, `posting_rules` schema | KEEP | Sound design |
| `ChartAccountMap` / `config/finance_accounts.php` | KEEP & IMPROVE | Right idea, under-adopted |
| `JournalPostingService` hard-coded account constants | REDESIGN | Route through `ChartAccountMap::local()` — CRITICAL risk C1 |
| `posting_rules` table (as actually used) | KEEP & IMPROVE | Either commit to it or remove it — currently misleading |
| `journal_entries`/`journal_lines`/`accounting_periods`/`document_sequences`/`finance_settings` | KEEP | Well-designed, no material issues |
| `cost_lines` + Cost Collector dimensions | KEEP | Strong design; `$guarded=['id']` breadth is the only nit |
| `payments` (unified, renamed from `petty_cash_disbursements`) | KEEP & IMPROVE | Sound unification; legacy free-text fields should be dropped/frozen |
| `spend_voucher_allocations` vs `payment_allocations` | MERGE / REQUIRES WNG CONFIRMATION | Two live settlement-link mechanisms |
| `petty_cash_balances` + `petty_cash_ledger_entries` | MERGE | Two independently-writable stores of the same balance |
| `bills`/`bill_payments` stored totals | KEEP & IMPROVE | Cache pattern fine, needs DB-level enforcement |
| `task_budget_data` (JSON budget) | REQUIRES WNG CONFIRMATION | Still system of record, or safe to freeze? |
| `EnquiryPayment` module placement | KEEP (low priority) | Cosmetic only |
| Two-charts-of-accounts design (reference + WNG mnemonic) | REQUIRES WNG CONFIRMATION | Needs a live-chart reconfirmation and a WIP-accounting policy decision |

## Accounts Receivable

| Component | Classification | Basis |
|---|---|---|
| Invoice/credit-note lifecycle & GL posting triggers | KEEP | Correctly separates operational capture from accounting event at every step |
| Credit-note workflow | KEEP | Complete and well-guarded |
| Void/reversal mechanism | KEEP | Compensating entry, never an edit |
| "Fully paid"/"Needs action" tab logic (project-level aggregates) | KEEP & IMPROVE | Underlying ledger/per-invoice figures are correct; only the two aggregate predicates need to incorporate allocation/pending-verification signals |
| Discounts & payment terms | REQUIRES WNG CONFIRMATION | May be intentionally out of scope |
| Credit note not reversing WIP/COS share | KEEP & IMPROVE | Named, documented limitation |

## Accounts Payable

| Component | Classification | Basis |
|---|---|---|
| Three-way match, verification, payment guard | KEEP | Tamper-evident, shared single-gate enforcement across every entry point |
| Purchase Order post-approval mutability & deletion | REDESIGN | No status guard on `update()`, commented-out `destroy()` guard, cascading deletes reach paid Bills/GRNs — CRITICAL |
| Duplicate supplier invoice number / duplicate payment reference | KEEP & IMPROVE | Fix pattern already exists elsewhere in the same codebase |
| PO/Bill/Payment as distinct documents | KEEP | Confirmed correctly modelled, never conflated |
| Direct Bill vs PO-backed Bill | KEEP | Cleanly modelled, one document type, two verification bases |

## Numbering

| Component | Classification | Basis |
|---|---|---|
| Payment/Requisition/Receipt/Journal/Advance/Retirement numbering | KEEP | Atomic counter table, correctly used |
| PO/Bill/GRN sequence generation | KEEP & IMPROVE | Same fix already proven elsewhere, just needs applying |
| `bills` dangling `update()` route | KEEP & IMPROVE | Needs a real handler or explicit removal |

## Expense Management & Payments/Receipts

| Component | Classification | Basis |
|---|---|---|
| SpendVoucher (Payment Voucher) create/approve/post | KEEP | Reference pattern — replicate elsewhere |
| Petty cash requisition lifecycle | KEEP & IMPROVE | Resolve the possible double-posting on surrender |
| Direct/exceptional petty-cash disbursement approval | KEEP & IMPROVE | Add urgency/float/repeat-offender signals |
| Two parallel settlement engines | MERGE | `PettyCashService::createDisbursement()` onto `PaymentSettlementService` |
| Payroll prepare/lock/mark-paid permissions | KEEP & IMPROVE | Split into 3 permissions to match SpendVoucher |
| Payroll cash-out (no `Payment` record) | REDESIGN | CRITICAL |
| Salary advance (no linked disbursement at all) | REDESIGN | HIGH |
| Fund custody "who held the money" | KEEP & IMPROVE / REQUIRES WNG CONFIRMATION | Needs a real custodian field, or confirmation this granularity doesn't matter |
| "Cash purchase" as a distinct concept | REQUIRES WNG CONFIRMATION | Not modelled at all today |
| `CashMovement` as a 4th ledger | REQUIRES WNG CONFIRMATION / MERGE | Acceptable if scope is genuinely distinct |
| Supplier bill / direct bill / three-way match | KEEP | — |
| Reimbursable expense (`funding_mode`) | KEEP | Real, queryable distinction |

## Journals / General Ledger

| Component | Classification | Basis |
|---|---|---|
| `JournalEntry`/`JournalLine` schema | KEEP | Standard, complete double-entry shape |
| `postBalancedEntry()` funnel | KEEP | Correct, well-documented |
| `postSupplierInvoice()`/`reverseEntry()` direct-write paths | REDESIGN | Route through the funnel — CRITICAL |
| `JournalEntryController` (read-only + guarded reverse) | KEEP | Sound access-control design |
| Ledger source filter | KEEP & IMPROVE | Extend to cover all real source types |
| `JournalEntryDrawer.vue` audit display | KEEP & IMPROVE | Add `created_by`/reversed-by user name |
| Trial balance Equity coverage | REQUIRES WNG CONFIRMATION | Business decision on recording opening balances/equity |
| `postSpendVoucher()`/`postSupplierPayment()` deprecated methods | REMOVE (after caller migration) | Already marked deprecated, still reachable |

## Bank and Cash Management

| Component | Classification | Basis |
|---|---|---|
| `payment_sources` as unified bank/cash/card/petty-cash master | KEEP | Coherent single model |
| Bank/mobile/card "no balance tracked" design | REQUIRES WNG CONFIRMATION | Deliberate today; confirm it matches how Finance wants to check bank position |
| `ReconciliationService` workflow | KEEP & IMPROVE | Sound; needs the soft-delete fix on match history |
| Petty cash `LedgerService` + `PettyCashBalance` cache | KEEP | Correct single-writer-plus-cache pattern |
| Disbursement→GL posting coupling | REDESIGN | CRITICAL |
| Surrender→GL posting coupling | KEEP | Correctly atomic |
| `CashMovementService` | KEEP | Clean 2-leg poster through the verified funnel |
| First-class "transfer between accounts" | REQUIRES WNG CONFIRMATION | Not found as a distinct mechanism |

## Tax

| Component | Classification | Basis |
|---|---|---|
| VAT/WHT rate-as-data architecture, effective-dating | KEEP | Genuinely correct, non-trivial, verifiably right |
| VAT/WHT calculation code | KEEP | — |
| Tax schedules & CSV export architecture | KEEP | One computation, two renderings |
| Missing-evidence gate (2 of 3 checks) | KEEP & IMPROVE, pending WNG confirmation | May not be a real gap if eTIMS# alone is sufficient |
| WHT schedule missing net-paid column | KEEP & IMPROVE | — |
| Input VAT screen missing columns | KEEP & IMPROVE | Template-only fix |
| Non-resident WHT categories | REQUIRES WNG FINANCE/TAX CONFIRMATION | — |
| Withholding VAT (WVAT) | REQUIRES WNG FINANCE/TAX CONFIRMATION | Not implemented at all |
| Hardcoded 1.16 in `QuoteInsightsService` | KEEP & IMPROVE | Advisory-only, resolve through `TaxResolver` instead |

## Project Finance

| Component | Classification | Basis |
|---|---|---|
| Cost-lines-as-single-source budget/spend model | KEEP | — |
| Ledger-based margin calc (single project) | KEEP | — |
| Revenue recognition (3-event model) | KEEP | — |
| WIP→COS release on billing | KEEP | — |
| Materials cost chain (budget→procurement/stores→cost line) | KEEP | The one link that is fully built and reconciles |
| Labour cost attribution to projects | REQUIRES WNG CONFIRMATION | Needs a capture-mechanism decision, not a silent fix |
| Logistics/transport actual cost attribution | REQUIRES WNG CONFIRMATION / KEEP&IMPROVE | Smaller fix than labour |
| Portfolio-wide cost accounts view (no margin column) | KEEP & IMPROVE | — |
| Budget-screen client-computed `grandTotal` | KEEP & IMPROVE | Recompute server-side |

## Approvals, Permissions, Periods, Reversal, Audit Trail

| Component | Classification | Basis |
|---|---|---|
| Accounts/Admin role group | KEEP & IMPROVE | Split by tier (clerk vs lead) |
| Payroll permission model | KEEP & IMPROVE | Split `HR_MANAGE_PAYROLL` into stage-specific permissions |
| Self-approval / expenditure-exception grants | REQUIRES WNG CONFIRMATION | Governance decision, not a code defect |
| Financial Period Controls | KEEP | One of the stronger controls found in this audit |
| Journal/Payment reversal mechanism | KEEP | New offsetting entries, atomic, well-audited, wired |
| Payment-voucher status-tab reporting | KEEP & IMPROVE | Add reversed/rejected filters |
| Petty cash `clearAllData`/`clear-all` endpoint | REMOVE (from production) or REDESIGN | CRITICAL |
| Petty cash top-up/requisition delete guards | KEEP | Mature, audit-conscious |
| Bill verification hard-coded role gate | KEEP & IMPROVE | Migrate to a permission constant |
| SpendVoucher approver-reject path | KEEP & IMPROVE | Add a distinct action |
| Column-level attribution (who created/approved/posted/paid/verified/reconciled) | KEEP & IMPROVE | Present and reasonably consistent |
| `HRAuditLog` as the de facto general audit log | KEEP & IMPROVE | Extract a genuine `AuditLog`, stop the cross-module naming leak |
| Field-level before/after diffs | KEEP & IMPROVE | Only one petty-cash case exists today |

## Cross-Module Integration

| Component | Classification | Basis |
|---|---|---|
| Projects → Finance (invoicing, receipts, WIP release) | KEEP | Atomic, well-designed |
| ProcurementStores → Finance (Stores costs, supplier bills) | KEEP | Outbox pattern with guarded retry; sound |
| HR (Payroll) → Finance | KEEP & IMPROVE | Accrual posting is sound; payment leg needs a `Payment` record (see CRITICAL C7) |
| Logistics (vehicle maintenance) → Finance | REDESIGN | The cost-capture UI exists; it just needs to hand off to Cost Collector/Bills instead of dead-ending |
| Assets → Finance | REQUIRES WNG CONFIRMATION | Needs a depreciation/valuation scope decision before building any link |

## Reporting & Dashboard

| Component | Classification | Basis |
|---|---|---|
| Profit & Loss, Trial Balance, AR/AP Ageing, GL/Account Statement, Journal Export, Tax schedules | KEEP | Each internally correct for what it measures |
| Trial Balance Equity display | KEEP & IMPROVE | Render a placeholder instead of silently filtering |
| Three disagreeing "outstanding" figures (AR ageing / payment-progress / ledger AR) | REDESIGN | Labelling/legibility problem — relabel and reconcile, not a math bug |
| Company-wide financial KPI dashboard | REQUIRES WNG CONFIRMATION | Does not exist today — confirm whether it's wanted |
| Fund Custody dashboard | KEEP | Well-designed, self-audits its own consistency |
| Project Profitability report | REQUIRES WNG CONFIRMATION | Does not exist at all; margin only visible one project at a time |

## Attachments & Evidence

| Component | Classification | Basis |
|---|---|---|
| Cost Collector evidence capture | KEEP & IMPROVE | Validated on submission, functionally linked; needs private-disk storage, a real child table, orphan cleanup |
| Bill/Purchase Order evidence | REDESIGN | Does not exist; needs to be added |
| Overall Finance attachment architecture | REDESIGN | No shared, generic, FK-integrity mechanism exists |

## Code Quality / Technical Debt

| Component | Classification | Basis |
|---|---|---|
| Money handling (decimal-consistent) | KEEP | No float/double bugs found |
| Tax rates (DB-driven) | KEEP | No hard-coded rate literals found |
| Balance-mutation locking/transactions | KEEP | Consistently guarded; contradicts a naive first assumption |
| Unsafe-delete guards | KEEP | Mature, audit-conscious |
| Backend authorization (not frontend-only) | KEEP | Every sampled action gated server-side |
| Very large files (`PettyCashRequisitionController`, `JournalPostingService`, `RequisitionForm.vue`) | KEEP & IMPROVE | Split along already-visible seams |
| "Payment Voucher"/`SpendVoucher` naming split | KEEP | Documented, intentional; a one-time onboarding gotcha, not live debt |
| SpendVoucher/Bill-payment test coverage | KEEP & IMPROVE | Thinner than the complexity of these paths warrants |

---

*See `10_FINANCE_RISK_REGISTER.md` for severity ratings and `12_WNG_CONFIRMATION_QUESTIONS.md` for
every open business decision this matrix depends on.*
