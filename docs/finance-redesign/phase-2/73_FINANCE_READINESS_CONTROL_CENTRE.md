# Report 73 — Finance Readiness & Control Centre

Audit date: 3 October 2026. Runtime: local DDEV ERP-Backend, MariaDB `db`; isolated tests use `db_test`. This report does **not** certify production. Evidence is in [73-verification](73-verification/). Readiness and the chart dry run were inspected before the UI implementation.

## 1. Executive Summary

Implemented nine business-oriented navigation areas; an action-first Overview with direct, backend-provided attention links; narrow-screen attention/transaction cards; an expandable mobile workspace menu; permission-aware federated Finance search; question-grouped reports with preserved report query state; and a central read-only setup/readiness projection. Existing Finance-owned purchasing registers and their pending changes were preserved.

The six existing CLI reference checks pass locally. This does not establish chart classification, production policy, or historical authority. The WNG chart dry run safely refused five duplicate-name conflicts. No chart cutover, accounting-policy decision, historical repair, commit, push, deployment, or worker start occurred.

Software is **partial** against the entire requested scope: search covers six authoritative register types, some secondary screens retain scrolling tables, the policy register cannot close decisions from an authoritative approval store, and a complete action-by-action interactive audit across every Finance form remains outstanding. These are distinguished from local configuration/policy/data gates below.

## 2. Git Baseline

| Repository | Branch | Starting and final HEAD |
|---|---|---|
| Backend | master | `5a5a3db738110d153610e3e5b6e5da4d9ab2d4db` |
| Frontend | master | `bb2e2af80d0305bdd7cc85cd0c5d90c821f888b9` |

Backend was already dirty: `app/Modules/MaterialsLibrary/Database/Seeders/LegacyMaterialCategorySeeder.php`. It was preserved without modification by this stream.

Frontend was already dirty: `navigation.ts`, `navigation.spec.ts`, `payables/components/DocumentChain.vue`, `payables/w2.ts`, `payables/w2.spec.ts`, `router/finance.ts`; untracked `payables/purchasingDocuments.spec.ts` and `payables/views/PurchasingDocumentsView.vue`. These were preserved; navigation was extended for this stream. The purchasing work remains uncommitted with the rest of the worktree. Final status evidence: [backend](73-verification/ERP-Backend-final-git.json), [frontend](73-verification/ERP-Frontend-final-git.json).

## 3. Existing Finance Architecture Preserved

Business document, approval, money movement, and accounting entry remain distinct. Approved does not mean posted. A PO does not become a bill/payment; receipt verification/allocation does not invent revenue. No posting service, account map, cost-recognition rule, Stores valuation method, WIP release algorithm, or payroll calculation was rewritten.

`JournalPostingService`, `assertOpenPeriod`, `ChartAccountMap`, `FinanceAccountFunctions`, existing reversals/corrections, maker/checker rules, audit records, W1–W7 permissions, and the Single Economic Cost protections remain authoritative. Navigation links invoke owning screens; they do not perform a mutation. Existing Procurement APIs continue to own supplier bill writes, even when accessed through Finance screens.

## 4. Readiness Check Result

`ddev exec php artisan finance:readiness`: six checks passed: Chart of Accounts, expense codes, procurement categories, paying accounts, accounting period, Finance roles. Recorded 200 postable accounts, 76 active procurement categories / 14 families, five paying accounts, current date covered by a period, and 15 roles. See [readiness.log](73-verification/readiness.log).

The helper now requires active postable chart accounts, active payment-capable sources linked to active postable asset accounts, and an **open** current period. Regression tests reject closed periods and unlinked paying sources. The authorized readiness API additionally passes 11 reference checks in the captured local snapshot. The legacy `ready` field remains for compatibility; the UI no longer turns it into a global “Finance Ready” claim.

Domain projection: software workflows SOFTWARE_READY (qualified retained-service scope); chart CONFIGURATION_REQUIRED; channels/period/expense dependencies/reference tax READY; WIP/payroll/petty cash POLICY_REQUIRED; documents/history DATA_REQUIRED; statutory Cash Flow NOT_SUPPORTED. Unknown domain data is “Not available”, including when using an older backend.

## 5. complete-chart Dry Run Result

Command: `ddev exec php artisan finance:complete-chart --profile=wng`. Default dry run; **no `--execute`**. Target database: local `db`. Exit 1, safely REFUSED before plan/report generation. [complete-chart.log](73-verification/complete-chart.log).

| Proposed code | Existing duplicate-name code |
|---|---|
| VAT-001 Output VAT Payable | 2110 |
| VAT-002 Input VAT Recoverable | 1330 |
| WHT-001 Withholding Tax Payable | 2120 |
| AE-001 Accrued Expenses | 2150 |
| CD-001 Client Deposits | 2200 |

The refused command cannot provide a completed reuse/mapping plan; none is claimed. Separate **read-only** inspection of the WNG profile found 29 proposed account definitions: 24 missing proposed codes and five name conflicts, no existing matching proposed codes. Only 16 of 37 WNG posting functions currently resolve against this local reference chart. All 37 functions in the currently selected default/reference map resolve. The active profile and explicit WIP mode are null locally; switching to WNG was not performed.

120 active postable accounts lack `account_type` or `normal_balance`. Missing/classification warnings and channel linkage are in [configuration-snapshot.json](73-verification/configuration-snapshot.json). No accounts were created/reused by a cutover operation, no successful `chart_completion.json` was produced, and no automatic fixes were run.

## 6. Chart/Mapping Readiness

208 chart rows, 200 postable in CLI evidence, 120 active postable classification gaps. Current account-function resolution uses `FinanceAccountFunctions::resolution()` / `ChartAccountMap`; the UI shows meaning, mapped code/name and resolution status without hardcoded posting GLs. WNG-profile resolution and currently active map resolution are reported separately.

`posting_rules` contains zero rows. This alone is not evidence that existing coded posting services are defective; their regression coverage and authoritative service rules are retained. 109 expense codes exist; the existing active-code account/dimension checks pass. The chart domain remains CONFIGURATION_REQUIRED despite passed reference functions.

## 7. Payment/Receiving Channel Readiness

Nine `payment_sources` rows exist. Payment and receipt channel capability belongs to the existing PaymentSource architecture; absence of a separate `receiving_channels` table is not itself a defect.

| Source | Active | Linked account id | Result |
|---|---|---|---|
| PC-MAIN | Yes | 127 | Linked asset source |
| BANK-MAIN | Yes | 125 | Linked asset source |
| BANK-ALT | Yes | 126 | Linked asset source |
| MPESA | Yes | 128 | Actual linked asset source; no account invented |
| CARD | Yes | 125 | Actual linked asset source; bank sharing requires Finance review |
| AP | Yes | 160 | Liability; explicitly not a money channel |
| STANBIC / KCB / FAMILY | No | 125 | Inactive / BLOCKED for use |

Five sources are payment-capable in the existing check. Setup displays type, active state, account code/name, readiness and explanation for every source. M-Pesa/Card absent or attached to an invalid/non-asset account are CONFIGURATION_REQUIRED. A liability-linked M-Pesa regression confirms that no false readiness or default account is supplied. An active linked source is not a certification of opening/history provenance. Synthetic Bank/Cash fixtures deliberately show unconfigured M-Pesa/Card to verify the alternative state; that is not the local database finding.

## 8. Accounting Period Readiness

48 period rows. The captured current October 2026 period is open. Setup shows actual period state; close/checklist/reopen/lock routes remain existing controlled actions. The CLI now fails a closed covering period. `assertOpenPeriod` remains enforced by posting services. No periods were created, closed or reopened by this task.

## 9. WIP Policy Readiness

No explicit local WIP mode/profile was configured. Setup exposes this as “Not explicitly configured” and POLICY_REQUIRED; a configured/rehearsal mode would still not constitute signed production approval. Existing WIP reports and W6 cost definitions remain. No production recognition/release choice was made.

## 10. Payroll Finance Readiness

Read-only canonical payroll readiness audit, aggregate counts only: 62 active employees; 18 with positive salary configuration; 22 missing; 22 requiring zero-salary review; one stale positive salary history; 13 unclassified departments; zero function-mapping exceptions. Canonical aggregate exception count: 58; state `not_ready`. These overlapping exception categories are not summed as unique employees.

25 salary-history rows and zero department classification rows exist. No employee salary amounts, bank details, payslips or personal HR records are copied into this report. The Payroll workspace keeps canonical salary/data readiness and permitted aggregate financial actions. Setup carries policy gates and links; it does not claim salary data is ready. A central permission-scoped aggregate salary-readiness subpanel remains a software improvement listed below.

## 11. Tax Readiness

Five VAT treatments and three WHT categories exist; both reference checks pass. Generic `tax_rates`/`wht_rates` table names are absent because the implementation uses its actual VAT/WHT models. This is not a finding that tax support is missing. Existing schedules, VAT return/eTIMS-gap and WHT routes remain authoritative. Effective rates, legal filing evidence, and historical tax authority require Finance validation; reference READY does not authorize filing.

## 12. Petty Cash Readiness

15 effective Finance settings exist. Nine stored values are literal null / unset, and all 15 lack approval actor/time. Petty-cash thresholds/surrender settings are therefore POLICY_REQUIRED. The implementation normalizes literal `null` to “Not set”; empty settings cannot pass readiness through a vacuous `every()` check. Existing defaults and W3 workflow controls remain; no threshold was invented or approved.

## 13. Policy Register

Read-only carryforwards, all POLICY_REQUIRED because this readiness contract has no authoritative signed decision evidence to close them:

| Key | Gate |
|---|---|
| W1-10 | Credit-note COGS / WIP treatment |
| W7-24 | Project labour GL / WIP dimensions |
| W7-25 | Standard labour / actual payroll variance |
| W7-26 | Employer statutory costs in standard labour rates |
| salary-advance | Salary-advance GL treatment |
| stores-consumption | Non-project Stores consumption GL |
| stock-adjustments | Manual stock adjustment authority |
| opening-balances | Opening balance/history provenance |
| retained-earnings | Retained earnings authority |
| period-end | Period-end/as-of authority |
| wip-production | Production WIP recognition/release approval |

This does not assert that no decision exists elsewhere. No policy was decided, and the register has no fake “Approve” control. Existing additional W2/W7 policy limitations shown by owning screens remain visible.

## 14. Data Readiness

Opening/history, AP authority, Cash/Bank history, retained earnings and as-of completeness are not certified by reference checks. Two sequence rows do not prove all workflows' history and sequence controls. Stores canonical OperationsReadiness reports 437 active materials, **419 needing handling setup corrections**, and **18 stocked materials without valuation**. It reports no failed/stalled cost captures and no invalid stock/reservation balances in this snapshot. Board/unit checks pass locally.

Payroll salary/classification gaps are separate from posting mappings. Canonical Cash Flow readiness is NOT_SUPPORTED, with no approved operating/investing/financing classification method. Unknown openings are never converted into a synthetic zero or created by this task.

## 15. Current User Journey Audit

Audit basis: pre-change committed frontend sources/routes plus preserved purchasing work; comparison against final source and fixture-rendered components. Counts below are structural click counts to the action entry point, excluding typing/filtering, scrolling, modal submission/confirmation and mandatory approvals. They are not timed user-study results. Queue counts assume an eligible **non-selected** row; the initial automatically inspected row already needed only one Open click.

| Workflow / start | Before: path / clicks | After: path / clicks | Finding |
|---|---|---|---|
| Client invoice / invoice register | Select, Open: 2 | Select, Open: 2 | Existing state-aware detail controls retained |
| Client receipt / receipt register | Select, Open: 2 | Select, Open: 2 | Verification/allocation remain distinct |
| Receipt verification / Overview | Inspect row, Open owning receipt: 2 | Direct Verify receipt link: 1 | Removed mandatory inspection detour |
| Receipt allocation / Overview | Inspect row, Open target: 2 | Direct backend target: 1 when queued | Allocation amounts/approval preserved |
| Supplier bill / bill register | Select, Open: 2 | Select, Open: 2 | Context inspector remains available |
| Bill verification / Overview | Inspect row, Open bill: 2 | Direct Verify supplier invoice: 1 | Detail still checks eligibility |
| Bill return / bill detail | Return button: 1 | Return button: 1 | Reason remains mandatory |
| Supplier payment / Overview | Inspect row, Open bill: 2 | Direct payment target: 1 | Source/date/reference and controls remain |
| Payment voucher / Overview | Inspect row, Open voucher: 2 | Direct target: 1 | W4 approval/senior/post rules unchanged |
| Petty cash / workspace | Workspace, chosen existing page: up to 2 | Money out, chosen existing page: up to 2 | Removed separate technical top-level category |
| Salary advance / petty cash | Open applicable requisition, owning action | Same controlled requisition action | No new GL treatment or unsupported payment path |
| Payroll Finance / Overview | Payroll section: 1 | Payroll section: 1 | Aggregate readiness and run inspector retained |
| Project financial position / selected project | Open position: 1 | Open position: 1 | W6 authoritative position already consolidated |
| Inventory Finance / Finance | Inventory section: 1 | Inventory section: 1 | No duplicate Stores control |
| Bank/Cash / Finance Overview | Reports, select Bank/Cash: 2 | Cash & banks: 1 | Canonical query tab is the section default |
| Financial report / report workspace | Select report tab: 1 | Select question report: 1 | Selection survives URL/reload |
| Configuration issue / Setup | Read generic check, find separate setup page | Domain Review: 1 when permitted | Undefined old controls-group reference removed |

Duplicate/mental-combination findings: expenses and supplier payments occupied different navigation domains; Bank/Cash was buried among reports; overview KPIs preceded work; purchasing links crossed into Procurement; readiness success hid policy/history gates; narrow screens stacked entire side menus before actions. The last purchasing issue was already being addressed in the preserved pending work and is not claimed as a new Report 73 invention.

## 16. Before/After Click Counts

| Journey | Before → After to owning action screen | Required action clicks |
|---|---|---|
| Overview → Verify Receipt | 2 → 1 | Verify/confirmation unchanged; typically 3 → 2 including initiating Verify |
| Overview → Allocate Receipt | 2 → 1 for an eligible queued item | Allocation dialog/save unchanged |
| Overview → Verify Supplier Bill | 2 → 1 | Verify/confirmation unchanged |
| Overview → Pay Supplier Bill | 2 → 1 | Payment form/save unchanged |
| Project → Financial Position | 1 → 1 | Existing position remains consolidated |
| Finance → Bank/Cash Position | 2 → 1 | No accounting operation occurs |
| Finance → Report | 1 section + 1 report → same | Question grouping improves discovery, not claimed click saving |
| Finance → Configuration Issue | Generic checks + independent navigation → direct permitted Review | No automatic repair |

The removed click is inspection of another queue row before navigating. Inspect remains optional. Claims apply when the existing queue actually supplies that eligible work type, never to fabricated attention items.

## 17. Final Navigation

Overview; Money in; Money out; Projects; Cash & banks; Payroll; Inventory; Reports; Setup. Existing route names and backend work-area keys remain. Expenses, petty cash and vouchers share Money out; Bank/Cash and movement use canonical report query routes. Payroll privacy and permission filtering remain. Mobile workspace pages expand on demand; desktop retains the sidebar.

## 18. Overview

“What needs my attention?” precedes position cards. Every queue item retains backend `required_action`, target URL, amount, party/project, age, and priority. Direct links coexist with Inspect; mutations remain in owning screens. Mobile queue cards surface action/amount/context. Canonical cash, receivables, payables and project projections follow the action workspace; titles include Cash & bank position, Money owed to us and Money we owe. Direct margin remains qualified, with excluded-cost flags.

## 19. Money In

Existing W1 invoice/receipt registers and Project Billing remain. Issued, paid/applied, outstanding, verified/unverified, unallocated/deposit and due/overdue facets retain their definitions. Register search/filter/state and inspector logic remain. Invoice/receipt tables become labeled cards below 640px. Verification, allocation, credit-note and invoice controls remain backend-owned. No receipt-to-revenue shortcut was introduced.

## 20. Money Out

Supplier bills/payments/position/WHT, Finance-owned purchasing evidence, expenses, petty cash and vouchers are grouped by the user's goal. W2/W4 controls retain three-way matching, return reason, maker/checker, pending amendment, verified balance and source eligibility. Supplier bill cards retain supplier/project, amount, outstanding/status and permitted actions. Mobile bill summaries use a compact two-column layout. Purchasing documents remain read-only Finance routes backed by Procurement authority.

## 21. Projects

Existing W6 position/cost and Project Billing views retain quote/contract, invoice/receipt/outstanding/client credit, commitments/accruals/direct costs, direct margin, completeness/exclusions and financial-close exceptions. Navigation links reuse existing owning screens and permission scoping. Project Billing remains a Money in child; cost/performance workspaces remain Projects. No claim of fully loaded profit is introduced.

## 22. Cash & Banks

Dedicated top-level entry opens canonical Report 72C Bank/Cash Position; Cash Movement and Bank matching are directly accessible. Ledger account/source/date/inflow/outflow/closing balances remain canonical. Historical-data and channel readiness are displayed by existing report projections; unknown openings remain NOT AVAILABLE. Statutory Cash Flow stays NOT_SUPPORTED.

## 23. Payroll

Existing aggregate payroll run, financial-state, liabilities, payment, readiness and classification workspaces remain. Actor/history/accounting links come from existing business/audit records. Salary amounts, employee bank information and HR personal records remain outside the Finance aggregate UI. W7-24/25/26, partial-settlement/reversal and salary-advance policy limits remain visible; no unsupported action is added.

## 24. Inventory

Stores remains the authoritative source for valuation, unvalued stock and movement. Existing Finance inventory projection shows valuation, GL/difference/contributors, received-not-issued accruals, issues, posting exceptions, opening authority and WIP caveats. No Finance stock-adjustment shortcut or duplicate valuation arithmetic was introduced. Canonical Stores readiness remains on Setup.

## 25. Reports

The report selector groups existing reports under Financial position, Control & reconciliation, Cash movement, Performance, and Money owed/due. Existing Tax workspace remains a report-area page. Existing controlled period/month-close and ledger pages remain in navigation. Unsupported report types were not added as fake tabs. Selection is synchronized to `?tab=` and preserved on reload. Mobile selector spans the row; export remains secondary. Cash Movement is explicitly distinct from statutory Cash Flow.

## 26. Finance Setup

Central domain readiness, chart/account-function resolutions, actual source/channel accounts, period status, WIP mode, read-only carryforward register, effective-setting approval evidence, existing reference checks, integrity issues and canonical OperationsReadiness. Links are shown only where the caller's permitted navigation allows them. Existing money-account/payment-term controls and tax/period/Bank matching paths are reused. A backend missing the additive domain contract produces “not available”, not green readiness. Broad seeding instructions were removed from the setup success/error presentation to avoid implying a safe production repair.

## 27. WH-Question Coverage

| Question | Retained authoritative presentation |
|---|---|
| What / Why | Document type, reference, purpose, commercial basis, return/correction reason |
| Who | Client/supplier; recorded/prepared/approved/verified/paid actors when present |
| How much | Document, VAT/WHT/payable, paid/applied, outstanding; no new totals |
| When | Recorded, approval/verification, due, receipt/payment and audit timestamps |
| Where | Project/job, account/channel, department where scoped/authorized |
| Status | Separate workflow, posting and money facets; status mapping retained |
| Next | Backend action allowance/reason and owning next-action link |

Supplier detail, client register inspectors, project position and aggregate payroll fixture screenshots substantiate representative coverage. Missing recorded actors/events remain absent/pending. Not every legacy Finance form received a new universal detail/timeline layout in this stream.

## 28. Dynamic Actions

| Screen / action | Authority / endpoint family | Permission and eligibility | Success / refusal |
|---|---|---|---|
| Overview / next-action link | `GET finance/work-queue` / FinanceWorkQueueService | Backend work visibility, role permissions, record state | Owning screen opens; no mutation |
| Invoice / check, issue, correct | Existing W1 invoice action wrappers / Receivables services | W1 action permission + maker/checker + document state | Authoritative refresh / refusal message |
| Receipt / verify, allocate | `projects/enquiries/{id}/payments/{id}/verify`; `projects/receivables/receipts/{id}/allocations` | Receivables verify/allocate; verification, unapplied amount, allocation/period rules | Verified/allocation projection / validation or business refusal |
| Bill / verify, return, correct, pay | Existing `procurement-stores/bills/{id}` action family / BillVerification and payment services | Existing Accounts permission/action allowances; preparer separation, three-way match, returned/amendment and payable state | Refreshed bill/payment / 403/422 with reason |
| Voucher / approve, senior approve, post, reverse | Existing spend-voucher W4 wrappers / SpendVoucher services | Existing permission, state, senior policy, source/period, reversal controls | Refreshed authoritative voucher / blocked reason |
| Petty cash / requisition, disburse, surrender/review | Existing W3 fund/petty-cash APIs | Owner/checker and existing requisition/surrender rules | Refresh / refusal without changing the record |
| Payroll / pay, classification | `finance/payroll/{id}/pay`, `finance/payroll/labour-classification/{id}` / PayrollPaymentService, LabourClassificationService | Payroll pay/classification permission; posted liability, eligible source, dated classification | Recorded settlement/audited classification / 403/422 reason |
| Projects / verify, close/reopen | Existing cost/labour/project-close APIs / CostCollector services | Project assignment or Finance permissions; completeness/close checks | Refresh / explicit exception |
| Ledger / reverse | `finance/journals/{id}/reverse` / ledger/posting services | Journal reversal permission, valid source/state/open period | Canonical reversal / refusal |
| Bank matching / import, match, ignore, reopen | `finance/reconciliation/statements/...` / existing reconciliation service | Existing reconciliation permissions/state/line rules | Authoritative statement refresh / validation/refusal |
| Period / close, lock, reopen | `finance/accounting-periods/{id}/...` / period controllers/services | Existing permissions/checklist/period controls | Controlled period state / failure |
| Setup / source and terms maintenance | `finance/payment-sources`, `finance/payment-terms` | Existing manage permissions, account/source validation | Saved existing model / validation error |
| Inventory / view position/issues/adjustments | `finance/inventory/...` / InventoryFinanceController, Stores valuation | `finance.reports.view`; read-only | Canonical report / unavailable/error |
| Search / permitted existing records | Six existing GET/POST search sources | Each source permission before request; backend applies its own scope | Up to five per source / visible per-source partial failure |

Single obvious primary actions retain existing styling; Verify is primary, Return secondary, and Pay is refused until its backend eligibility is true. Required approval/submission actions were not collapsed. Existing state translation helpers remain; readiness adds explicit state labels with unknown fallback.

## 29. Record Timeline

Existing business/audit-derived evidence chains and inspectors are reused, including supplier requisition → PO/amendment → GRN → bill verification → payment; invoice/receipt and payroll chains retain recorded actors/times and pending stages. No events are fabricated from a status name. The read-only fixture detail includes synthetic test history, not a production transaction. A universal timeline on every remaining legacy form is outside the verified coverage and remains a software gap.

## 30. Related Records

Preserved DocumentChain changes link requisition, PO and GRN through Finance-owned purchasing paths, plus existing bill/payment/project/accounting access. Client invoice inspectors retain project/receipt/credit/accounting context. Payroll accounting/history and inventory/project cost drilldowns remain. Links do not transfer permission or authorize a posting. Existing filters and return contexts in owning components are retained; report tab URL preservation is newly added.

## 31. Empty/Error States

Readiness distinguishes configuration/policy/data/unsupported/blocked and unknown. Fetch failure clears stale readiness and shows retry. Search uses `Promise.allSettled`, shows failed sources alongside successful results, distinguishes no permitted sources and no matches, and never labels failure as an empty successful search. Existing registers retain no-record/filter/permission error behavior. Synthetic 503 and empty supplier lists were rendered at both sizes. Canonical opening/history missing states remain explicit rather than zero.

## 32. Permission Behaviour

Permissions, not role-name checks, drive navigation, search sources and setup links. Reporting permission still gates the readiness API. Existing record/action allowances and server-side authorization remain independent of a visible link. No HR salary endpoints are queried by Finance search. Unit tests exercise restricted search and hidden setup links; backend tests exercise denied readiness and maker/checker controls. Data truth is not recomputed differently by role.

## 33. Frontend/Backend Contract

[Action contract manifest](73-verification/action-contract-manifest.md) identifies 190 static/finite-dispatch method/path contracts, source owners and registered backend handlers/middleware. The workflow matrix above describes service eligibility and outcomes for the major action families. Named permission checks inside controllers/services remain authoritative where route middleware only says authenticated/active.

No placeholder buttons or simulated posting operations were introduced; all new controls are navigation, existing search, reload, or disclosure controls. Every **new** static/finite-dispatch endpoint matches registration. A static match is not evidence that every historical mutation was interactively executed, so a universal “zero dead actions across every Finance form” certification is withheld pending complete interactive action coverage.

## 34. Backend Tests

DDEV test isolation: `APP_CONFIG_CACHE=/tmp/report73-testing-config.php DB_DATABASE=db_test php -d error_reporting=22527 artisan test ...`. Separate config cache avoids clearing shared application config. An initial normal test invocation encountered the existing cached `db` configuration and was stopped by the safety guard; no tests mutated the local operational database. Subsequent suites used `db_test`.

| Run | Result | Evidence |
|---|---|---|
| Full `tests/Feature/Finance` | 570 passed, 2 failed; 3743 assertions | [backend-finance-tests.log](73-verification/backend-finance-tests.log) |
| Focused control-centre/readiness/payroll after fixture fixes | 15 passed; 59 assertions | [backend-focused.log](73-verification/backend-focused.log) |
| Stores outbox/opening/stock integrity; GRN accrual; supplier payment and bill segregation | 55 passed; 254 assertions | [backend-integration.log](73-verification/backend-integration.log) |

The two broad failures were test setup defects: duplicate payroll reference accounts already supplied by migrations, and a “future” effective date becoming past on 3 October. Tests now reuse existing accounts and freeze that date before the future boundary. Production payroll services were unchanged. The broad suite was not rerun in full after those fixture-only corrections; affected payroll tests pass in the focused run. Do not add these counts as disjoint unique tests.

Broad coverage includes W1/W2/W3/W4/W5/W6/W7, Inventory/Stores integration, reporting/BankCash/reconciliation, permission/readiness and accounting service regressions. Dedicated new tests reject false policy/data readiness, liability-linked/unconfigured channels, closed periods, unlinked sources and unauthorized readiness access.

## 35. Frontend Tests

`npm run test:unit -- --run src/modules/finance tests/unit/finance`: **23 files, 242 tests passed**. [frontend-tests.log](73-verification/frontend-tests.log). Coverage includes navigation/route classification, W1/W2/W3/W4/W5/W6/Payroll presentation, queues/inspectors, state/action visibility, shared status/error behavior, reports, related purchasing evidence, and new readiness/search contracts. Responsive verification is real browser fixture evidence, not a unit-test screenshot simulation.

Existing nav expectation failures were updated to the new section labels/ownership. Fixture rendering exposed an absent legacy `ledger_scope` field; the setup view now safely guards it. No existing failing functional test was disabled.

## 36. API Contract

Finance literal/finite-dispatch audit: **190 contracts, 10 new versus committed HEAD, zero newly unmatched, zero unresolved static paths**. [api-contract.log](73-verification/api-contract.log), [api-contract.json](73-verification/api-contract.json). Existing canonical report check: 12 call sites / seven endpoints / zero unmatched, [reports-api-contract.log](73-verification/reports-api-contract.log).

The ten new contracts include preserved pending purchasing dispatches; they are not all newly introduced by Report 73. The audit expands known closed W1–W4 URL wrappers and purchasing/search variants explicitly; it does not pretend regex can prove arbitrary dynamic wrappers. Existing W1–W7 tests and the service matrix complement that limitation.

## 37. Build

Direct `npm exec vite build`: **PASS**. [production-build.log](73-verification/production-build.log). Existing large-chunk and stale Browserslist warnings remain non-fatal. This is a direct Vite build, not a claim that the repository-wide TypeScript debt is resolved. The additional final `npm run type-check` exits 2 with 273 diagnostics, matching the recorded baseline count. New readiness/search files have no diagnostics; the existing report-template reconciliation narrowing comparison still appears among those diagnostics. The unchanged baseline conditional was verified against HEAD. See [typecheck.log](73-verification/typecheck.log); a clean global typecheck is not claimed.

## 38. Visual Verification

Actual Vue Finance components rendered through a local Vite fixture entry, headless Chrome, synthetic existing test data and a read-only captured readiness response. Axios requests terminate in an in-memory adapter; write methods are refused. No logged-in browser session or operational mutation was used.

Desktop 1440 × 1100 and mobile 390 × 844: Overview, Money in/invoices, Money out/bills, Project Finance, Cash & Banks, Payroll, Inventory, Reports, Setup; additionally bill detail/chain/next-action, receipt register, empty supplier list and synthetic error state. **26 principal renders plus seven below-the-fold mobile checks**, with runtime exception and document overflow measurements in [visual-checks.json](73-verification/visual-checks.json). No uncaught runtime exceptions or document-wide horizontal overflow in the measured renders. Internal report/evidence tables can still scroll; that is recorded below rather than called a full card conversion.

Evidence: [desktop contact sheet](73-verification/desktop-contact-sheet.png), [mobile contact sheet](73-verification/mobile-contact-sheet.png), individual `desktop-*.png` / `mobile-*.png`, [fixture harness](../../../../ERP-Frontend/tests/fixtures/73/fixture.ts), and [browser script](73-verification/verify-visual.cjs). Contact sheets were visually inspected alongside representative full-size Overview, mobile invoice/bill, setup and detail images. Setup DOM evidence includes configuration-, policy- and data-required states; Bank/Cash fixtures include unknown openings and unconfigured channels. Setup snapshot values are local configuration evidence; all transaction/person identities in UI fixtures are synthetic.

Issues found and corrected: transient blank-page screenshots now wait for actual mounting; missing optional setup ledger scope guarded; long mobile menus disclosed on demand; report selection no longer squeezed by export; compact bill summary and labeled register cards; shared long empty/error messages now wrap rather than inheriting badge no-wrap styling. Screenshot fixture journal lookup now returns an explicit empty register rather than an accidental adapter failure. The dynamic bill fixture is explicitly unverified/unpaid with no fabricated verification or payment event. Fixtures do not certify the accounting state of a live record.

## 39. Remaining Software Gaps

1. Federated search currently covers invoices, receipts, supplier bills/payments, vouchers and POs. There is no standalone project/client/supplier master result type, journal search result, or payroll search. Relationship identifier support is limited to each existing endpoint's search contract; no universal search claim is made.
2. Secondary inventory, payroll, report, supplier-detail/evidence and legacy expense tables retain horizontal scroll rather than all becoming mobile record cards. Primary Overview/invoice/receipt/bill cards are verified; complete narrow-screen treatment of every Finance form is pending.
3. The central policy register is intentionally read-only and conservative. It lacks an authoritative decision/approval integration that could close a gate; no approval button was fabricated. Setup links to Payroll readiness but does not yet include its permission-scoped canonical salary aggregate subpanel.
4. Every major family has endpoint/source/service evidence and regressions, but a complete interactive action-by-action audit of all legacy forms, filter-return contexts, timeline and success/refusal permutations remains outstanding. Zero newly unmatched endpoints is verified; universal zero dead actions is not certified.

These limitations justify SOFTWARE PARTIAL. Unsupported statutory Cash Flow and unapproved accounting choices are separate capability/policy boundaries, not broken buttons to conceal.

## 40. Remaining Configuration Gates

Resolve the five WNG chart-name conflicts with accountant approval; review all WNG proposed/missing mappings before any separately authorized cutover; complete 120 active postable classification gaps. Review actual source account identities, including Card/Bank sharing, rather than accept mere existence as business authority. Complete 419 material-handling setup corrections and document-control/sequence review. The local reference profile is not proof of WNG production-chart readiness. No critical live-production certification can be issued from this local database.

## 41. Remaining Policy Gates

All eleven explicit register entries in §13 remain open to authoritative Finance/accountant evidence. Approve effective petty-cash thresholds/surrender settings where absent; establish signed production WIP choice; retain W1 credit-note COGS/WIP and W7 labour/statutory/variance, salary advance and other owning-workspace limitations. No approval should be inferred from defaults, rehearsals, passed tests or a configured account.

## 42. Remaining Data Gates

Validate opening balance provenance, retained earnings, period-end/as-of authority, AP/payment historical completeness, Cash/Bank history, tax filing evidence and document-sequence history. Resolve 18 stocked materials with no valuation, 22 missing salary configurations, 22 zero salary review cases, one stale history and 13 unclassified departments through their existing authorized workflows. Reconcile recorded source/account positions to external evidence. No opening or historical financial record was created or repaired here.

## 43. Files Changed

Backend: new `FinanceControlCentreService.php`; additive `FinanceReadinessController.php`; tightened `Support/FinanceReadiness.php`; new `FinanceControlCentreTest.php`; deterministic/reused fixtures in `PayrollFinanceWorkspaceTest.php`; this report and verification artifacts. Pre-existing LegacyMaterialCategorySeeder work is untouched.

Frontend: navigation and its two specs; Overview; Bill/Invoice/Receipt lists; FinancialReportsView; FinanceReadinessView plus new readiness types/spec; new FinanceSearch/spec; FinancePageFrame; finance.css; FinanceState message wrapping; workQueue labels; isolated `tests/fixtures/73` harness/data. Preserved pre-existing purchasing routes/wrappers/spec/DocumentChain and PurchasingDocumentsView remain in final status. Exact final file inventory is recorded in the two git JSON snapshots. No dependencies were added for production UI.

## 44. Commits

**None.** No push, deployment, merge, reset, discard or stash. Both repository HEADs remain unchanged. Current task's explicit Git safety takes precedence over older requests to commit/push previous streams.

## 45. Report Path

`ERP-Backend/docs/finance-redesign/phase-2/73_FINANCE_READINESS_CONTROL_CENTRE.md`. Evidence is adjacent in `73-verification/`.

## 46. Verdict

**FINANCE CONTROL CENTRE SOFTWARE PARTIAL — SPECIFIC SOFTWARE GAPS REMAIN**

**FINANCE CONFIGURATION PARTIAL — SPECIFIC CONFIGURATION/POLICY/DATA GATES REMAIN**

Implemented behavior is tested/buildable and primary desktop/mobile views are verified, with exact remaining software limits in §39. Configuration/policy/data gates are enumerated separately in §§40–42. These are local findings, not production readiness approval. Stop at Report 73: no chart execution, cutover, deployment, W8, accounting-policy invention, opening-balance/retained-earnings creation, history repair or queue-worker start.
