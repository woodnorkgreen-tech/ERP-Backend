# 72C — Cash Movement, Bank & Cash Reporting and Financial Reporting Software Closure

Verified 2026-10-01 (Africa/Nairobi). **72C COMPLETE.**

## 1. Executive Summary

Bank & Cash Position, Cash Movement, account/journal/source drill-down, same-projection CSV exports and an explicit unsupported Cash Flow readiness panel are implemented. BANK_CASH and Finance Overview consume the canonical cash reporting service. The acceptance checks pass: 54 backend tests / 395 assertions, 50 frontend tests, zero unmatched Reports endpoints, direct Vite build, diff checks and desktop/mobile fixture rendering.

Software completion is distinct from historical accounting authority. This task did not establish live financial readiness or approve opening balances, accounting policies or cutover.

## 2. Scope

Canonical configured-account cash position and movements; internal transfer elimination; original/reversal treatment; Cash Flow readiness; existing BANK_CASH integration; Finance Reports surfaces, CSV exports and drill-down; focused regressions; evidence and the reporting software completion matrix.

## 3. Non-Scope

No Finance Setup implementation, W8 work, Project Finance expansion, migrated opening balances, retained earnings, historical accounting repair, cutover, policy decisions or deployment. No statutory operating/investing/financing statement was fabricated.

## 4. Git Baseline

Captured before edits; see [git-baseline-status.txt](72c-verification/git-baseline-status.txt) for every modified and untracked path.

| Repository | Branch | HEAD | Modified at baseline | Untracked at baseline |
| --- | --- | --- | --- | --- |
| ERP-Backend | master | 8427fcb216e30d77e737a24ff3534088bb5daf08 | FinanceReportController.php; routes/api.php; ProfitAndLossReportTest.php | BalanceSheetService.php; FinancialReconciliationService.php; Report 72B; check-finance-reports-contract.py; BalanceSheetReportTest.php; FinancialReconciliationTest.php |
| ERP-Frontend | master | 10007e5d5de6704c9b84edf381eef14623a657e8 | navigation.ts; reportsService.ts; reports.ts; FinancialReportsView.vue | reports.spec.ts; BalanceSheetStatement.vue; FinancialReconciliationCentre.vue |

All baseline work was preserved. No reset, checkout over work, stash, discard, branch switch, merge, commit or deployment occurred. Existing ProfitAndLossReportTest.php changes were not edited by 72C.

## 5. 72B Baseline

[Report 72B](72B_FINANCIAL_REPORTING_BALANCE_SHEET_RECONCILIATION.md) remains accepted and unchanged. Its Balance Sheet service/frontend, equation/readiness controls, seven-family reconciliation framework and tests were reused. Only BANK_CASH was integrated with the new canonical service; the other control definitions remain intact.

## 6. Files Changed — Feature Evidence

Paths below are relative to the workspace; section 32 lists every 72C file and verification artifact.

| Implementation | Backend service | Controller / route | Frontend component | API service / types | Tests |
| --- | --- | --- | --- | --- | --- |
| Bank & Cash Position | BankCashReportingService::position | FinanceReportController::bankCashPosition; routes/api.php | BankCashPositionReport.vue; CashReportingReadiness.vue | reportsService.ts; reports.ts | BankCashReportTest.php; cashReports.spec.ts |
| Cash Movement | BankCashReportingService::movement | FinanceReportController::cashMovement; routes/api.php | CashMovementReport.vue; CashReportingReadiness.vue | reportsService.ts; reports.ts | CashMovementReportTest.php; cashReports.spec.ts |
| Cash Flow readiness | BankCashReportingService::cashFlowReadiness | FinanceReportController::cashFlowReadiness; routes/api.php | CashFlowReadinessPanel.vue | reportsService.ts; reports.ts | CashFlowReadinessTest.php; cashReports.spec.ts |
| Cash account/journal/source drill-down | Existing journal/account-statement projection | Existing JournalEntryController APIs / routes/api.php | CashAccountDrillDown.vue; CashJournalDetail.vue | Existing ledgerService.ts; ledger.ts | JournalLedgerReadTest.php; cashReports.spec.ts |
| BANK_CASH | FinancialReconciliationService → BankCashReportingService | Existing reconciliation endpoint | Existing FinancialReconciliationCentre.vue | Existing reconciliation contract in reportsService.ts / reports.ts | FinancialReconciliationTest.php; reports.spec.ts |
| Overview cash | FinanceOverviewController → BankCashReportingService | Existing overview endpoint | Existing FinanceOverviewView.vue | Existing overview contract | FinanceOverviewTest.php; overview.spec.ts |
| Reports integration / mobile layout | No additional calculation | Existing reporting permissions | FinancialReportsView.vue; FinancePageFrame.vue; navigation.ts | reportsService.ts / reports.ts | cashReports.spec.ts; navigation.spec.ts |

Backend services/controllers live under `ERP-Backend/app/Modules/Finance/`; backend tests under `ERP-Backend/tests/Feature/Finance/`. Report Vue files live under `ERP-Frontend/src/modules/finance/reports/views/`; the shared frame lives under `finance/shared/components/`.

## 7. Canonical Cash/Bank Source

`journal_entries` + `journal_lines.base_amount` + configured `chart_of_accounts`. Only `posted` and `reversed` entries through the accounting date are included. Base currency is KES, consistent with the existing Finance base-currency architecture. Operational payments, receipts and Petty Cash float do not replace the GL balance. Monetary SQL aggregates are passed as decimal strings to BCMath, without binary-float summation.

The same service supplies BANK_CASH and the Finance Overview cash projection. Overview retains its compact response and hides zero-only function accounts without payment-source rows; its total still comes from the canonical position. Its separately permission-gated operational float is not added to the GL cash total.

## 8. Cash/Bank Account Identification

Eligible accounts are the union of active payment sources of type `bank`, `mobile_money`, `card`, `petty_cash` linked to valid chart accounts and the existing mapped `FinanceAccountFunctions::BANK_DEFAULT` / `PETTY_CASH_FLOAT` functions resolved through `ChartAccountMap`. An eligible account must be active, postable, asset-classified and `balance_sheet`. Account IDs are deduplicated. Names never determine eligibility.

Payment-source configuration is not changed or reseeded by report reads. Actual unlinked MPESA/CARD rows, or absent channel identities, return `NOT_CONFIGURED` with null account IDs; no chart accounts or channel balances are created. Inactive sources and invalid classifications are excluded with explicit configuration states. A mapped function is independently authoritative even without a payment-source row.

Petty Cash is included exactly once in total Cash & Bank; there is no second petty-cash subtotal added to that total. Source types remain visible on position rows. Tests prove duplicate-source deduplication and bank-to-petty-cash transfers.

## 9. Bank & Cash Position Service

`BankCashReportingService::position(as_at)` returns as-at date, KES currency, unique configured accounts, cumulative debits/credits, debit-minus-credit closing ledger balances, account configuration/readiness and drill-down parameters, total cash/bank, configuration channels, historical/opening readiness and generation time.

`opening_or_brought_forward_position` is null because an approved migrated opening-position authority is not established. The UI/CSV show **NOT AVAILABLE**, not zero. The closing figure is explicitly a recorded GL position subject to the historical warning. Credit-normal asset corrections still use asset statement signs: debit increases, credit reduces cash.

## 10. Cash Movement Service

`BankCashReportingService::movement(from, to)` returns recorded ledger brought-forward balance, period debit/credit activity, net movement and closing ledger position for each account and the company projection. Approved `opening_cash_position` / per-account `opening_balance` remain null. Separate `ledger_opening_cash_position` / `ledger_brought_forward_balance` expose the safely calculated pre-period recorded balance with an explicit historical caveat.

Recorded opening + period net = recorded closing. Company inflows/outflows remove eligible internal transfers; account rows retain gross activity. Labels do not treat receipts as revenue or payments as expenses. Tests cover boundaries, drafts, period activity, negative values, closing-to-position agreement and decimal cents at the existing DECIMAL(14,2) journal limit.

## 11. Internal Transfer Treatment

Eliminate equal debits/credits only when a posted/reversed journal is balanced, has at least two distinct eligible cash accounts, and every line belongs to eligible cash accounts. Subtract that journal's debit amount from both company inflows and outflows. Net movement is unchanged. Account rows retain every leg.

Mixed cash/fee/FX/split-settlement journals retain gross activity; no undocumented allocation is guessed. This method and the eliminated amount/journal count are returned by the backend and displayed/exported. Regression tests prove bank-to-bank and bank-to-petty-cash transfers, mixed-journal treatment and transfer reversals.

## 12. Reversal Treatment

JournalPostingService reversals swap sides in a compensating journal dated on the correction date and mark the original `reversed`. Both histories remain in cash projections, each with its own date cutoff. Tests invoke the actual reversal service, prove the original remains at the earlier cutoff and prove compensation nets at the later cutoff. Transfer originals and their reversals are both eliminated from company gross flows when eligible.

## 13. Cash Flow Readiness

Backend result:

```json
{"cash_flow_statement":{"supported":false,"status":"NOT_SUPPORTED","reason":"The ledger has no approved operating/investing/financing classification method. Cash Movement reports posted cash-account activity, not a statutory Cash Flow Statement.","method":null}}
```

Repository inspection found no controlled statutory classification method to reuse. There are no fabricated activity sections. The UI says **CASH FLOW STATEMENT — NOT YET AUTHORITATIVE**, shows the backend reason/status, and opens Cash Movement.

## 14. Endpoints

All require `finance.reports.view`; the existing controller authorisation is reused.

| Method / endpoint | Validated filters | Result |
| --- | --- | --- |
| GET `/api/finance/reports/bank-cash-position` | optional ISO calendar `as_at`; `format=json/csv` | Position projection |
| GET `/api/finance/reports/cash-movement` | required ISO calendar `from`, `to`; `to >= from`; `format=json/csv` | Movement projection |
| GET `/api/finance/reports/cash-flow-readiness` | none | Controlled Cash Flow readiness |

Account drill-down reuses GET `/api/finance/journals/accounts/{account}/statement`; journal detail reuses GET `/api/finance/journals/{journal}`. No duplicate account/journal endpoints were created.

## 15. Frontend Implementation

Three tabs were added to the existing Finance Reports page, with current Finance typography, money formatting, surfaces, date filters and exports. Backend readiness and channel states are displayed without client inference. `?tab=bank-cash` selects the correct surface from the reconciliation inspector.

Browser verification exposed mobile intrinsic-width overflow and UTC-shifted local month dates. `FinancePageFrame` now permits its content grid item to shrink, report tabs scroll within their own container, and date defaults use local calendar components. A Nairobi-timezone regression test proves September 1–30 remains September 1–30. Existing P&L/Trial Balance definitions and 72B services were not changed.

## 16. Drill-down

Selecting an account loads paginated posted journal lines for that report's date range. Selecting a journal loads its actual header/legs. Known referenced SpendVoucher, ProjectInvoice, Bill and project-cost routes receive real source IDs; unsupported/unrecorded source routes show no link. There are no correction buttons on this read-only cash inspector. Version guards prevent stale account/journal responses from replacing a newer selection.

## 17. Export

Position and Cash Movement CSV use the same backend projection methods as JSON. Account figures, company totals, unavailable approved openings, readiness and transfer treatment are exported without a second cash calculation. Position export includes channel configuration. CSV regression tests compare projected values and readiness disclosures; frontend tests verify dates/path/filename forwarding.

## 18. Readiness

Backend owns `HISTORICAL_DATA_INCOMPLETE`, `NOT_CONFIGURED`, `CONFIGURED`, `INACTIVE`, `INVALID_ACCOUNT` and Cash Flow `NOT_SUPPORTED`. Approved openings are null, while recorded ledger brought-forward values are distinct decimal strings. These states do not imply missing software and do not claim the full historical WNG position.

## 19. BANK_CASH Reconciliation Integration

BANK_CASH obtains `gl_balance` directly from `BankCashReportingService::position`. There is no separate BANK_CASH journal aggregation. Its contract retains null independent subledger/difference and **NOT_READY** because independent authoritative operational cash evidence is unavailable. Evidence identifies the canonical service, configuration and opening/historical readiness. It is system cash/GL, not an external bank-statement reconciliation. The canonical-service mock regression proves delegation and forbids a GL-to-itself reconciled claim.

## 20. Backend Tests

Actual command, from ERP-Backend:

```bash
ddev exec php -d error_reporting=22527 vendor/bin/phpunit --testdox tests/Feature/Finance/BankCashReportTest.php tests/Feature/Finance/CashMovementReportTest.php tests/Feature/Finance/CashFlowReadinessTest.php tests/Feature/Finance/BalanceSheetReportTest.php tests/Feature/Finance/FinancialReconciliationTest.php tests/Feature/Finance/ProfitAndLossReportTest.php tests/Feature/Finance/JournalLedgerReadTest.php tests/Feature/Finance/FinanceOverviewTest.php
```

**PASS — 54 tests, 395 assertions, exit 0.** Runtime PHP 8.4.24 through DDEV; `phpunit.xml` forces the isolated MySQL `db_test` database. Native host PHP is absent; it did not block execution. No production database was used. Vendor deprecations were suppressed for readable output; test/schema errors were not suppressed. Optional-role migration warnings remained non-failing.

| Suite | Tests |
| --- | ---: |
| BankCashReportTest | 9 |
| CashMovementReportTest | 7 |
| CashFlowReadinessTest | 2 |
| BalanceSheetReportTest | 6 |
| FinancialReconciliationTest | 7 |
| ProfitAndLossReportTest | 7 |
| JournalLedgerReadTest | 11 |
| FinanceOverviewTest | 5 |

An intermediate run rejected an oversized precision fixture outside the existing journal DECIMAL(14,2) schema; the fixture was corrected without changing the accounting schema. The final run above passes. See [backend-tests.log](72c-verification/backend-tests.log).

## 21. Frontend Tests

Actual command, from ERP-Frontend:

```bash
TZ=Africa/Nairobi ./node_modules/.bin/vitest run src/modules/finance/reports/reports.spec.ts src/modules/finance/reports/cashReports.spec.ts src/modules/finance/navigation.spec.ts src/modules/finance/overview/overview.spec.ts
```

**PASS — 4 files, 50 tests, exit 0.** `reports.spec.ts`: 6; `cashReports.spec.ts`: 13; `navigation.spec.ts`: 13; `overview.spec.ts`: 18. Includes large/negative values, unavailable openings, unconfigured channels, backend readiness, transfer presentation, loading/empty/errors, date boundaries/filters, export, account/journal/source drill-down and no unsupported source link. See [frontend-tests.log](72c-verification/frontend-tests.log).

## 22. 72B Regression

Balance Sheet's 6 tests, reconciliation's 7 tests (including the new BANK_CASH delegation test) and the existing 6 frontend report tests pass. Classification, contra-assets, equation difference, no auto-balancing, historical readiness and backend status ownership remain intact.

## 23. P&L / Trial Balance Regression

ProfitAndLossReportTest: 7 passing tests. JournalLedgerReadTest: 11 passing tests covering journal register, journal detail, trial balance, account statements and permissions. Existing P&L/Trial Balance accounting definitions were not edited. FinanceOverviewTest's 5 tests additionally pass after canonical cash delegation.

## 24. API Contract

Actual commands, from ERP-Backend:

```bash
ddev exec php -d error_reporting=22527 artisan route:list --path=api/finance --json > /tmp/72c-finance-routes.json
python3 scripts/check-finance-reports-contract.py /tmp/72c-finance-routes.json
```

Both exit 0. **12 call sites, 7 unique Finance Reports endpoints, 0 unmatched; 0 new unmatched attributable to 72C.** Baseline was 7 calls / 4 endpoints. New position JSON/CSV, movement JSON/CSV and readiness calls all match actual GET routes. Reused ledger drill-down endpoints are separately exercised by the ledger regressions and frontend tests. The checker covers Finance Reports, not a fresh claim about every unrelated Finance endpoint. See [api-contract.log](72c-verification/api-contract.log) and [route export](72c-verification/registered-finance-routes.json).

## 25. Production Build

Actual command, from ERP-Frontend:

```bash
./node_modules/.bin/vite build
```

**PASS — exit 0; built in 41.19s.** Direct Vite invocation, not wrapper-only evidence. Browserslist-age, large-chunk and Node warning messages are non-failing warnings. See [production-build.log](72c-verification/production-build.log).

## 26. Git Diff Check

`git diff --check` in each repository: **PASS, exit 0**. See [git-diff-check.log](72c-verification/git-diff-check.log). Changes remain uncommitted; branch/HEAD values are unchanged.

## 27. Visual Verification

Actually rendered the real Finance Reports page/components with local Chrome and the cached Playwright runtime using clearly labelled synthetic test fixtures. Inspected saved screenshots for Bank & Cash, Cash Movement, Cash Flow readiness, the BANK_CASH inspector, journal/source drill-down, large negatives, date inputs, readability, alignment and mobile table scrolling. Ready surfaces were rendered at 1440px and 390px; loading/empty/error states were rendered at 390px.

Final automated capture exits 0: no browser JavaScript errors and no document overflow in any of the 11 viewport/state cases; document width equals viewport width. Dense tables intentionally scroll inside their containers. Fourteen PNG captures (including two horizontally scrolled tables and the journal/source inspector) and [visual-check.json](72c-verification/visual-check.json) are retained. Preview files/server were removed/stopped after verification. This is fixture-based visual verification, not a live login or live financial-data validation.

## 28. Financial Reporting Completion Matrix

Software status evaluates the supported report/control surface and its canonical service, not approval of its underlying policy/data. Existing non-72C rows were reassessed by their actual code surfaces; only the suites listed above were re-executed in this task.

| Report / control | Software | Actual surface / source | Live authority / limitation |
| --- | --- | --- | --- |
| Trial Balance | Implemented | JournalEntryController::trialBalance; GeneralLedgerView / FinancialReportsView | Recorded ledger coverage; opening/history gates |
| General Ledger | Implemented | JournalEntryController::accountStatement; GeneralLedgerView | Recorded GL; approved historical openings not established |
| Journal Register | Implemented | JournalEntryController::index/show; GeneralLedgerView | Posted journal/source coverage |
| Management P&L | Implemented | ProfitAndLossService; FinancialReportsView | Management scope; depreciation/openings/year-end completeness |
| Balance Sheet | Implemented | BalanceSheetService; BalanceSheetStatement | Historical/retained-earnings gates, no auto-balancing |
| Cash Movement | Implemented | BankCashReportingService::movement; CashMovementReport | Recorded history; approved opening unavailable; pure-transfer elimination |
| Cash Flow readiness | Implemented | cashFlowReadiness; CashFlowReadinessPanel | Statutory method NOT_SUPPORTED |
| Bank & Cash | Implemented | BankCashReportingService::position; BankCashPositionReport | Configured GL subset, channel/opening/history gates |
| AR | Implemented | ReceivablesAgeingService; FinancialReportsView / receivables surfaces | Historical receipt projection gated; undated outstanding invoices DATA_INCOMPLETE |
| AP | Implemented | PayablesController::position / BillController::ageing; PayablesPositionView / FinancialReportsView | Complete independent as-at AP authority NOT_READY |
| Reconciliation Centre | Implemented | FinancialReconciliationService; FinancialReconciliationCentre | Canonical seven-family statuses/reasons |
| Inventory reconciliation | Implemented | StoresValuationReadinessService + INVENTORY control | Unvalued/review stock DATA_INCOMPLETE; historical snapshots NOT_READY |
| Payroll liability reconciliation | Implemented | PAYROLL control; aggregate Payroll Finance readiness | NOT_READY until complete opening/execution authority; no employee disclosures |
| Petty Cash reconciliation | Implemented | Petty-cash ledger + configured control; PETTY_CASH inspector | Current ledger comparison; historical opening policy gate |
| WIP reconciliation | Implemented | WIP control; reconciliation inspector | POLICY_BLOCKED while recognition policy unresolved; no independent as-at authority |
| Project Performance | Implemented for declared management coverage | CostAccountService::forEnquiry / marginAgainstJournals; CostAccountPanel | Provisional category completeness, WIP/release/credit-note policy gates |
| Portfolio | Implemented for declared management coverage | CostAccountService::index / portfolioMargin; CostAccountsView | Included direct-cost coverage; logistics/overhead not included |
| Project Budget vs Actual | Implemented | CostAccountService planned/spend category/element projection; CostAccountPanel | Approved budget and cost capture completeness; not a GL balance substitute |
| VAT | Implemented | TaxScheduleService; TaxSchedulesView, input/output/return/eTIMS APIs | Source tax evidence and ledger coverage; not an asserted filed statutory return |
| WHT | Implemented | TaxScheduleService::whtSchedule; TaxSchedulesView / WithholdingTaxView | Verified withholding/source and remittance completeness |

Source declarations explicitly mark missing cost categories rather than inventing final project margins. W8 Logistics/Fleet integration remains outside the supported reporting coverage and was not started.

## 29. Remaining Software Gaps

No missing required service, endpoint, permission, report surface, inspector, export or focused test remains in the 72C acceptance scope. No genuine missing required reporting surface was identified in the completion matrix. The unsupported statutory Cash Flow method is an explicit software readiness state, not a fabricated statement or missing controlled surface.

Policy-dependent posting work such as W1-10 and future W8 coverage remains outside this closure; existing supported management/control surfaces do not claim those authorities. Fixture visual checks do not establish live usability with real data.

## 30. Policy Gates

Carried forward without decisions: WIP timing/release; W1-10 credit-note versus WIP/COGS treatment; W7-24; W7-25; W7-26; salary-advance GL; non-project Stores consumption; manual stock-adjustment dual approval; opening balances; retained earnings; period-end/as-of policy. Existing Finance policy decision records remain authoritative; 72C does not close them.

## 31. Data Gates

Approved opening/historical financial positions are not established by these projections. Stores valuation completeness, payroll live readiness, independent AP authority and complete cash/bank history remain gated as already evidenced in 72B and the current control contracts. Actual MPESA/Card channel mappings are respected; this task does not establish live configuration. No production/live rehearsal or cutover validation was performed.

## 32. Files Changed — Complete 72C Inventory

### Backend

- `ERP-Backend/app/Modules/Finance/Controllers/FinanceOverviewController.php`
- `ERP-Backend/app/Modules/Finance/Controllers/FinanceReportController.php`
- `ERP-Backend/app/Modules/Finance/Services/BankCashReportingService.php`
- `ERP-Backend/app/Modules/Finance/Services/FinancialReconciliationService.php`
- `ERP-Backend/docs/finance-redesign/phase-2/72C_FINANCIAL_REPORTING_CASH_BANK_STREAM_CLOSURE.md`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/api-contract.log`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/backend-tests.log`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/bank-cash-1440-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/bank-cash-390-empty.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/bank-cash-390-error.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/bank-cash-390-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/bank-cash-390-scrolled.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-account-journal-source-1440.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-flow-1440-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-flow-390-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-movement-1440-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-movement-390-loading.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-movement-390-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/cash-movement-390-scrolled.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/frontend-tests.log`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/git-baseline-status.txt`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/git-diff-check.log`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/git-final-status.txt`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/production-build.log`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/reconciliation-1440-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/reconciliation-390-ready.png`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/registered-finance-routes.json`
- `ERP-Backend/docs/finance-redesign/phase-2/72c-verification/visual-check.json`
- `ERP-Backend/routes/api.php`
- `ERP-Backend/tests/Feature/Finance/BankCashReportTest.php`
- `ERP-Backend/tests/Feature/Finance/CashFlowReadinessTest.php`
- `ERP-Backend/tests/Feature/Finance/CashMovementReportTest.php`
- `ERP-Backend/tests/Feature/Finance/FinancialReconciliationTest.php`
- `ERP-Backend/tests/Support/CashReportingFixtures.php`

### Frontend

- `ERP-Frontend/src/modules/finance/reports/services/reportsService.ts`
- `ERP-Frontend/src/modules/finance/reports/types/reports.ts`
- `ERP-Frontend/src/modules/finance/reports/views/FinancialReportsView.vue`
- `ERP-Frontend/src/modules/finance/reports/views/BankCashPositionReport.vue`
- `ERP-Frontend/src/modules/finance/reports/views/CashMovementReport.vue`
- `ERP-Frontend/src/modules/finance/reports/views/CashFlowReadinessPanel.vue`
- `ERP-Frontend/src/modules/finance/reports/views/CashReportingReadiness.vue`
- `ERP-Frontend/src/modules/finance/reports/views/CashAccountDrillDown.vue`
- `ERP-Frontend/src/modules/finance/reports/views/CashJournalDetail.vue`
- `ERP-Frontend/src/modules/finance/reports/cashReports.spec.ts`
- `ERP-Frontend/src/modules/finance/shared/components/FinancePageFrame.vue`
- `ERP-Frontend/src/modules/finance/navigation.ts`
- `ERP-Frontend/tests/fixtures/financeCash.ts`

The 72B files already present at baseline remain intact except the explicitly listed BANK_CASH service/test integration. Temporary fixture-preview HTML/TS files were deleted after verification. Existing P&L test changes are preserved, not attributed to 72C.

## 33. Commits

None. Both repositories remain on the baseline master HEADs with preserved uncommitted work. No merge/deployment.

## 34. Financial Reporting Software Verdict

FINANCIAL REPORTING SOFTWARE COMPLETE —
LIVE AUTHORITY REMAINS SUBJECT TO DOCUMENTED DATA/POLICY GATES

All 72C acceptance items are satisfied:

- [x] Canonical Bank/Cash service, endpoint and frontend
- [x] Cash Movement service, endpoint and frontend
- [x] Internal transfers handled and reversal behaviour tested
- [x] Cash Flow readiness implemented without fabricated statutory sections
- [x] BANK_CASH uses the canonical service
- [x] Historical readiness visible; unconfigured channels not fabricated
- [x] Backend tests executed in isolated DDEV runtime
- [x] 72B / P&L / ledger regressions pass
- [x] Frontend tests pass
- [x] API contract has zero new unmatched endpoints
- [x] Direct Vite production build passes
- [x] Both git diff checks pass
- [x] Actual changed files and verification evidence listed
- [x] Report 72C exists in the backend docs directory

## 35. Live Data Verdict

LIVE FINANCIAL DATA READINESS NOT VERIFIED

The passing tests and fixture renders establish software behaviour. They do not verify WNG's production opening balances, channel configuration, historical completeness, payroll/AP readiness or approved accounting policies.

## 36. Recommended Next Step

**Finance Setup & Configuration**, focused on explicit approval/readiness of existing chart-function mappings, cash channels, opening/history provenance and policy-dependent configuration. The reporting software now surfaces these missing authorities; approved configuration/readiness is the immediate prerequisite for trustworthy live financial positions. Finance/accountant policy closure must supply the unresolved decisions rather than engineering selecting defaults. Project Finance expansion, W8 and staging/cutover can be reconsidered after those prerequisites; none was started here.
