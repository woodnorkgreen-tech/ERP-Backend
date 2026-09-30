# 03 — Current Finance Navigation Map

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Documents exactly what a
user sees today: menu → submenu → page → tabs → actions → resulting transaction. No redesign
proposed here — see `13_TARGET_REDESIGN_INPUTS.md` for what Phase 2 should weigh.

---

## 2.1 Entry point

- **FACT:** The global left-hand menu shows a "Finance" item (icon `mdi-cash-multiple`),
  `ERP-Frontend/src/components/DynamicSidebar.vue:267`.
- **FACT:** `/finance` resolves through `router/finance.ts:5-21`: `MainLayout` → `FinanceShell.vue`
  → redirect to `/finance/work-queue` (`finance.ts:18-21`).
- **FACT:** `FinanceShell.vue` renders only `<RouterView />`; the sub-navigation is a separate
  component, `FinanceNavigation.vue`, driven by `src/modules/finance/navigation.ts` — a clean
  single-source design (route table for URLs, `navigation.ts` for menu presentation), not a
  duplicated hierarchy.

## 2.2 Full menu → submenu → page map

(permission-filtered at render time, from `navigation.ts`)

```
Finance (global nav)
└── /finance  → redirects to /finance/work-queue
    │
    ├── [rail] Work queue                         (navigation.ts:39-60)
    │   └── My finance queue        /finance/work-queue                → FinanceWorkQueueView.vue
    │
    ├── [rail] Costs & budgets                     (navigation.ts:61-104)
    │   ├── Record Expense          /finance/costs?tab=capture         → CostCollectorIndex.vue (tab=capture)
    │   ├── My submissions          /finance/costs?tab=mine            → CostCollectorIndex.vue (tab=mine)
    │   ├── Cost verification       /finance/costs/verification        → CostVerificationView.vue
    │   ├── Project cost sheet      /finance/costs?tab=account         → CostCollectorIndex.vue (tab=account)
    │   └── Portfolio budgets       /finance/costs/accounts            → CostAccountsView.vue
    │
    ├── [rail] Payments & cash                     (navigation.ts:105-141)
    │   ├── Cash requisitions       /finance/petty-cash/requisitions   → RequisitionIndex.vue
    │   ├── Payment vouchers        /finance/payment-vouchers          → PaymentVouchersView.vue  (backend: SpendVoucher)
    │   ├── Petty cash register     /finance/petty-cash                → PettyCashIndex.vue
    │   └── Payroll                 /finance/payroll-disbursement      → PayrollDisbursement.vue  (permission: hr.manage_payroll)
    │
    ├── [rail] Client billing                      (navigation.ts:142-156)
    │   └── Billing & receivables   /finance/project-receivables       → ProjectReceivablesIndex.vue
    │
    └── [rail] Controls & reports                  (navigation.ts:157-228)
        ├── [Set up] Control centre         /finance/setup                      → FinanceReadinessView.vue
        ├── [Set up] Request forms          /finance/setup/requisition-types    → RequisitionTypesView.vue
        ├── [Set up] Money accounts         /finance/setup/paying-accounts      → PayingAccountsView.vue
        ├── [Check & close] Bank matching   /finance/reconciliation              → ReconciliationView.vue
        ├── [Check & close] Tax review      /finance/tax                         → TaxSchedulesView.vue
        ├── [Check & close] Close month     /finance/periods                     → AccountingPeriodsView.vue
        ├── [Understand results] Account book  /finance/ledger                   → GeneralLedgerView.vue
        └── [Understand results] Reports       /finance/reports                  → FinancialReportsView.vue
```

**Routes that exist but are absent from the menu tree entirely:**
- `/finance/spend` (`finance-spend`, `router/finance.ts:74-80`) → `SpendEntryView.vue` — see ISSUE-1.
- `/finance/spend-vouchers` → pure redirect to `/finance/payment-vouchers` (legacy URL kept alive).
- `/finance/petty-cash/requisitions/{new,:id,:id/edit}` — standard detail-route pattern, not an issue.

## 2.3 Tabs within each page

| Page | Tabs | Evidence |
|---|---|---|
| CostCollectorIndex.vue | capture / mine / account | `cost-collector/views/CostCollectorIndex.vue:36-38` |
| CostVerificationView.vue | single page, filter bar only | — |
| PettyCashIndex.vue | Cashbook / [Approval inbox]* / [Top-up custody]* / Fund requests / [Reports]* / [Audit trail]* (*permission-gated) | `petty-cash/views/PettyCashIndex.vue:472-491` |
| RequisitionIndex.vue | single list page with status filters | — |
| ReconciliationView.vue | Unmatched / Matched / Ignored | `reconciliation/views/ReconciliationView.vue:90,419-449` |
| FinancialReportsView.vue | Profit and loss / Trial balance / Receivables ageing / Payables ageing | `reports/views/FinancialReportsView.vue:30-35` |
| TaxSchedulesView.vue | VAT input claim / VAT output / Missing evidence / WHT | `tax/views/TaxSchedulesView.vue:25-30` |
| AccountingPeriodsView.vue | single page, no tab state | — |
| GeneralLedgerView.vue | Journal entries / Account summary / Account book | `ledger/views/GeneralLedgerView.vue:22-25` |
| PaymentVouchersView.vue | All / Awaiting approval / Ready to post / Posted | `cost-collector/views/PaymentVouchersView.vue:83-86` |
| FinanceWorkQueueView.vue | single page, no tab state | — |
| FinanceReadinessView.vue | single page (checklist) | — |
| PayingAccountsView.vue | single page | — |

## 2.4 Findings — duplicate/overlapping, dead, confusing, or incomplete

### ISSUE-1 — Orphaned primary entry point ("one door for spending" is unreachable). RISK: HIGH.
- **FACT:** `router/finance.ts:70-80` defines `/finance/spend` → `SpendEntryView.vue`, with a code
  comment describing it as *"one door for spending"*, so a user doesn't have to choose between menu
  sections.
- **FACT:** `SpendEntryView.vue` presents 3 choices — "Record a cost", "Need cash in advance",
  "Order from supplier".
- **FACT:** `navigation.ts` does not list `/finance/spend` anywhere in its menu tree, and a
  repo-wide search found zero links to it outside the router file and its own spec test.
- **ISSUE:** The page the code comments describe as the intended starting point for all spending is
  dead scaffolding — unreachable through the UI. "Record Expense" instead goes straight to
  `CostCollectorIndex.vue?tab=capture`, skipping the paid/unpaid/PO decision this page exists to make.
- **CLASSIFICATION: REDESIGN** (reconnect it; the underlying triage logic is good, pure removal
  would lose a useful "which form do I need" step).

### ISSUE-2 — Terminology mismatch: "Payment Vouchers" (UI) vs. `SpendVoucher` (backend/route/URL). RISK: MEDIUM.
- Backend model/table/controller/route prefix are all `SpendVoucher`/`spend-vouchers`; the frontend
  page, route path, and nav label are all "Payment Vouchers"/`payment-vouchers`. The old
  `/finance/spend-vouchers` path survives only as a redirect.
- A developer reading the codebase sees two different nouns for the same document, slowing
  onboarding and bug triage ("the payment voucher won't post" doesn't grep-match the backend).
- **CLASSIFICATION: KEEP & IMPROVE** (cross-reference comment, or rename one side).

### ISSUE-3 — Duplicate "trial balance" surface between General Ledger and Financial Reports. RISK: LOW-MEDIUM.
- `GeneralLedgerView.vue`'s "Account summary" tab and `FinancialReportsView.vue`'s "Trial balance"
  tab both ultimately read from `JournalEntryController::trialBalance`, under two different labels,
  with no cross-link between them.
- **REQUIRES WNG CONFIRMATION** whether these are meant to differ in scope, or should merge.

### ISSUE-4 — Client billing/receivables backend lives outside the Finance module. RISK: MEDIUM (architectural).
- Every HTTP endpoint behind "Billing & receivables" is served by
  `App\Modules\Projects\Http\Controllers\EnquiryController` (2,067 lines), not any controller
  inside `app/Modules/Finance` — though it correctly delegates posting to
  `Finance\Services\ReceivablesPostingService`.
- A Finance-module-only code review, permission audit, or refactor will miss the entire
  invoice/credit-note/payment-logging surface. "Client billing" is the only Finance nav section
  with zero backend files of its own inside `Finance/Controllers`.
- **CLASSIFICATION: REDESIGN** (module-boundary cleanup — extract a `Finance\Controllers\Receivables`
  controller wrapping the existing service; not a user-facing change).

### ISSUE-5 — Two unrelated "budget" concepts share the word with no disambiguation. RISK: MEDIUM.
- Finance's "Portfolio budgets"/"Project cost sheet" (CostLine/BudgetProjector-based) and Projects'
  quote-approval "Budget" task (`BudgetTask.vue` and siblings) are different features governed by
  permission constants (`FINANCE_BUDGET_*`) that are, per direct grep, consumed only by Projects
  code, never by anything under `app/Modules/Finance`.
- A third, now-removed, Petty Cash "Project Budgets" tab pointed users at Finance's Cost Accounts
  instead, because its own numbers were always zero.
- **CLASSIFICATION: REQUIRES WNG CONFIRMATION** (shared permission-name prefix suggests someone once
  intended these to converge).

### ISSUE-6 — Dead/orphaned finance-adjacent component and endpoint. RISK: LOW.
- `FinanceContextCard.vue` (Universal Task module) has zero imports anywhere in the frontend.
- `LabourClassificationController` is routed but has zero frontend callers.
- **CLASSIFICATION: REQUIRES WNG CONFIRMATION / candidate REMOVE.**

### ISSUE-7 — Same page reachable under three different labels. RISK: LOW.
- `/finance/setup` is named `finance-setup` with meta title "Finance Setup Check", labelled
  "Control centre" in the nav rail, and titled "Controls & reports" in-page.
- **CLASSIFICATION: KEEP & IMPROVE** (standardize on one label).

### ISSUE-8 — Deprecated methods retained in the core posting service. RISK: LOW (code health).
- `JournalPostingService.php` carries three `@deprecated` method docblocks pointing at
  `postPayment()` — which is itself unreachable (see `06_ACCOUNTING_POSTING_ANALYSIS.md`).
- **CLASSIFICATION: KEEP & IMPROVE** (delete once call sites confirmed clear).

### ISSUE-9 — Legacy permission constants alongside their replacements. RISK: LOW.
- `FINANCE_PETTY_CASH_{CREATE,UPDATE,VOID}_LEGACY` sit next to the current
  `create_disbursement`/`edit_disbursement`/`void_disbursement` permissions.
- **CLASSIFICATION: REQUIRES WNG CONFIRMATION** (a permissions change needs sign-off).

### ISSUE-10 — Finance module has two separate migration directories. RISK: LOW.
- `Finance/Database/Migrations/` (79 files) and `Finance/PettyCash/Database/Migrations/` (1 file,
  dated ~8 months earlier) both exist.
- **CLASSIFICATION: KEEP** (cosmetic), pending confirmation both are still discovered by the loader.

### ISSUE-11 — Finance has no dedicated `Routes/` folder, unlike every sibling module. RISK: LOW.
- All ~90 Finance routes are declared inline inside the monolithic `routes/api.php` (1,198 lines),
  interleaved with unrelated modules' routes.
- **CLASSIFICATION: KEEP & IMPROVE** (extract to `app/Modules/Finance/Routes/api.php`).

### ISSUE-12 — `PayrollDisbursement.vue` breaks the module's own folder convention. RISK: LOW.
- Every other Finance page lives inside a named sub-area folder; this is the sole file directly
  under `finance/views/`.
- **CLASSIFICATION: KEEP & IMPROVE.**

### ISSUE-13 — "AP ageing" tab's backend source not fully confirmed in this pass. RISK: LOW.
- `FinancialReportsView.vue` renders an AP-ageing tab; the only confirmed Finance route is
  `receivablesAgeing`. AP ageing likely calls a ProcurementStores endpoint directly (confirmed in
  the reporting audit, `09_REPORT_AND_DASHBOARD_AUDIT.md`) — a minor cross-module coupling, not
  necessarily wrong, but worth confirming in Phase 2.

## 2.5 Navigation area classifications (summary)

| Nav section / inventory group | Classification | Basis |
|---|---|---|
| Work queue | **KEEP** | Single coherent page, real aggregation service |
| Costs & budgets | **KEEP & IMPROVE** | Core, well-built; orphaned `/finance/spend` chooser belongs here |
| Payments & cash | **KEEP & IMPROVE** | Functionally solid; naming mismatch and Payroll's folder placement need cleanup |
| Client billing | **REDESIGN** (backend ownership only) | Real, working feature; backend controller lives in the wrong module |
| Controls & reports | **KEEP & IMPROVE** | Comprehensive; label inconsistency and possible trial-balance duplication |
| Cost Collector (backend) | **KEEP** | Mature, actively maintained through Sept 2026 |
| Petty Cash | **KEEP** | Ledger-as-truth design already implemented; healthiest sub-area found |
| Ledger/GL/Journals | **KEEP & IMPROVE** | Functional but carries dead `@deprecated` code |
| Reconciliation | **KEEP** | Recently built (Sept 2026), no navigation issues found |
| Tax | **KEEP** | Recently built, directly answers a real filing need |
| Permissions | **KEEP & IMPROVE** | Comprehensive but carries `_LEGACY` variants and a naming leak into Projects |
| Finance-adjacent (ProcurementStores bills/GRN) | **KEEP** | Actively used, tax-aware, ledger-posting confirmed |
| Finance-adjacent (HR payroll) | **REQUIRES WNG CONFIRMATION** | Only the disbursement step is surfaced inside Finance nav — confirm this split is intentional |
| FinanceContextCard.vue / LabourClassificationController | **REMOVE** (pending confirmation) | Zero consumers found |

---

*Questions for WNG arising from this section are consolidated in `12_WNG_CONFIRMATION_QUESTIONS.md`.*
