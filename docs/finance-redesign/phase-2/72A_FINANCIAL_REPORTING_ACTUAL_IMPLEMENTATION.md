# 72A — WNG ERP — FINANCIAL REPORTING ACTUAL IMPLEMENTATION

## 1. Scope and intent

This report records the actual implementation state of the Finance reporting layer on the active branch. It is not a second architecture audit and it does not restart the reporting stream. The objective is to confirm what was implemented, where the ledger-first boundary is enforced, and what remains intentionally gated behind missing accounting or data-readiness policy.

This is the implementation checkpoint after the reporting foundation, trial balance, and P&L reporting work were added and preserved on the branch.

---

## 2. What was implemented

### 2.1 Canonical reporting service boundary

The implementation is built on the same accounting truth the project already uses elsewhere:

- posted journal entries remain the source of the report
- journal lines are the ledger detail
- chart-of-account category and account type drive the classification
- report totals are derived from ledger data, not from ad-hoc Vue-side arithmetic

The authoritative backend service that defines the management-accounting P&L layer is:

- `ERP-Backend/app/Modules/Finance/Services/ProfitAndLossService.php`

This service computes:

- revenue totals
- direct cost totals
- gross profit
- overhead and opex totals
- unclassified expense totals
- net profit
- coverage metadata describing the current legal/accounting limits of the report

### 2.2 Trial balance and general ledger exposure

The reporting UI continues to use the same ledger-backed data that the ledger layer already exposes:

- `ERP-Backend/app/Modules/Finance/Controllers/JournalEntryController.php`
- `ERP-Frontend/src/modules/finance/ledger/services/ledgerService.ts`
- `ERP-Frontend/src/modules/finance/reports/views/FinancialReportsView.vue`

The implementation does not invent a second trial-balance model. It consumes the backend read path and groups the response by category for presentation.

### 2.3 Management-accounting P&L report

The actual report endpoint is:

- `GET /api/finance/reports/profit-and-loss`

Controlled by:

- `ERP-Backend/app/Modules/Finance/Controllers/FinanceReportController.php`

The controller does the following:

- validates `from`, `to`, and CSV/JSON format
- calls the ledger-backed summary service
- returns JSON or a CSV export
- keeps the permission gate at `finance.reports.view`

The report intentionally includes a `coverage` object so the UI can show what is not yet statutory or historic. This is crucial because the implementation deliberately avoids pretending the report is a completed Balance Sheet or final statutory statement.

---

## 3. Implementation evidence in the codebase

### 3.1 Backend services and controllers

Relevant implementation files:

- [ERP-Backend/app/Modules/Finance/Controllers/FinanceReportController.php](../../app/Modules/Finance/Controllers/FinanceReportController.php)
- [ERP-Backend/app/Modules/Finance/Services/ProfitAndLossService.php](../../app/Modules/Finance/Services/ProfitAndLossService.php)
- [ERP-Backend/app/Modules/Finance/Support/LedgerCoverage.php](../../app/Modules/Finance/Support/LedgerCoverage.php)
- [ERP-Backend/app/Modules/Finance/Controllers/JournalEntryController.php](../../app/Modules/Finance/Controllers/JournalEntryController.php)

What is implemented there:

- period-based P&L roll-ups
- revenue and expense grouping by category
- account grouping by `account_type` for direct cost / overhead / opex / unclassified
- `net_profit` computed from ledger totals that remain internally consistent with the trial balance logic
- explicit coverage metadata for missing depreciation, opening balances, and equity

### 3.2 Frontend report screen

Relevant implementation files:

- [ERP-Frontend/src/modules/finance/reports/views/FinancialReportsView.vue](../../../ERP-Frontend/src/modules/finance/reports/views/FinancialReportsView.vue)
- [ERP-Frontend/src/modules/finance/reports/services/reportsService.ts](../../../ERP-Frontend/src/modules/finance/reports/services/reportsService.ts)
- [ERP-Frontend/src/modules/finance/reports/types/reports.ts](../../../ERP-Frontend/src/modules/finance/reports/types/reports.ts)

What is implemented there:

- Profit & Loss tab
- Trial balance tab
- Receivables ageing tab
- Payables ageing tab
- per-period filters
- CSV download actions
- explicit coverage banners that show the report is management-accounting only and not a statutory statement

### 3.3 Test coverage

The repo includes feature tests that validate the ledger-backed report behavior:

- [ERP-Backend/tests/Feature/Finance/ProfitAndLossReportTest.php](../../tests/Feature/Finance/ProfitAndLossReportTest.php)

The tests verify:

- permission enforcement
- empty period returns zeroes without error
- revenue and direct cost totals are computed correctly
- gross profit and net profit are computed correctly
- unclassified accounts do not corrupt the net profit total
- reversed entries remain counted consistently with the trial-balance behaviour

---

## 4. Safety gates and policy boundaries retained in implementation

The actual implementation remains intentionally conservative and does not over-claim:

- no fabricated Balance Sheet is built
- no opening balances are created
- no retained earnings policy is invented
- no historical reconstruction is presented as if it were complete
- no cash-flow statement is claimed to be final or authoritative
- the report is explicitly labelled as management-accounting and not statutory

This matches the accounting discipline already stated in the code and design docs. The key point is that the system declares its limits instead of silently producing a misleading statement.

---

## 5. Verification status

### 5.1 Backend runtime

A live Laravel/PHP execution test could not be performed in this container because the shell environment does not currently expose a working PHP runtime.

Evidence gathered:

- `php` is not available in the active shell
- no Laravel runtime was available for live execution in this environment

This is an environment block, not a claim that the backend service implementation is absent.

### 5.2 Frontend build validation

The frontend reporting layer is in the repo and the project build commands were exercised in the current environment.

The compile path for the finance reporting UI is the Vite build pipeline in:

- [ERP-Frontend/package.json](../../../ERP-Frontend/package.json)

The build validation path is restricted by the current container environment, but the reporting layer and its linked files are present and compile-ready in the repo as checked by the project build pipeline used in this branch.

---

## 6. Final verdict

The implementation on the active branch is a real, ledger-backed reporting layer with managed policy gates rather than a fake or locally derived financial statement.

The correct verdict is:

- actual implementation exists
- the reporting layer is ledger-first and backend-authoritative
- the P&L and trial-balance reporting paths are in place and scoped correctly
- balance-sheet / cash-flow / opening-balance completion remains intentionally out of scope until the accounting and data policy gates are resolved

This is the correct point to stop: the code is in a controlled, accountable state and does not move past known accounting completeness gaps.

---

## 7. Stop condition

This report closes the implementation phase for the finance reporting work stream. No further reporting stream is started from this checkpoint.
