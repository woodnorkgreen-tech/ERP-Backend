# 47 — Phase 2B: DATA-1 Confirmation & W7 Project Budget Authority Correction

**Date:** 2026-09-28
**Inputs:** Report 46; WNG answers to Q1–Q3; the Q4 directive; the release branch `finance/critical-stabilization-fixes`.
**Boundary:** No database was reset, no Finance data deleted, no deployment, no merge to `master`, no W8, no redesign.
**Verdict:** **PASS — DATA-1 CONFIRMED AND W7 BUDGET AUTHORITY CORRECTED** (§23).

---

## 1. Executive Summary

- **DATA-1 is confirmed and recorded in the Decision Register.**
  - Projects and Employee Records are preserved, with their project operational data, required dependencies, master data, security data and audit trail.
  - The imported petty-cash register, the existing client receipts and the payroll-development records are resettable, alongside development/test cost lines and journals.
  - The reset is **specified, encoded and fail-closed-tested but not executed.**
- **W7's go-live defect is corrected from existing business state; no new approval was invented.**
  - The repository already defines budget finalization: since internal budget approval was retired (2026-07-07), **completion of the project's budget task** is "the finalization signal consumed by procurement and finance summaries" (`EnquiryWorkflowService`).
  - A new canonical service, `ProjectBudgetAuthority`, is now the single definition used by W6 and W7.
  - W7 no longer requires `status = 'approved'`.
- **Status:** all tests pass, ENG-1 is unchanged, and the build succeeds.
- **New finding (pre-existing, release-critical):** six Finance cost-recording listeners are queued, and master's own deploy history says production has no queue supervisor. Production's `QUEUE_CONNECTION` must be confirmed before release (§15).

## 2. Q1 Decision

**RESET.** The following are not authoritative Finance history and join the controlled reset:
- the imported petty-cash register (`payments`, `petty_cash_ledger_entries`, `petty_cash_top_ups`, `petty_cash_activity_logs`)
- the float balance (`petty_cash_balances`)
- the development-era petty-cash requisitions and items

## 3. Q2 Decision

**RESET.** The existing `enquiry_payments` (84 in the proxy) join the reset. Projects, clients, quotes, the commercial basis and project workflow records remain protected.

## 4. Q3 Decision

**RESET.** The June payroll run (`payroll_runs`), `payroll_ledgers`, `payslips` and the salary advance (`salary_advance_requests`, `salary_advance_recoveries`) join the reset. Employee Records, salary history, HR history, attendance, leave, documents, user links and departments remain protected.

## 5. DATA-1 Final Preservation Boundary

Encoded in `FinanceResetBoundary::PROTECTED`:

- **Primary:** `project_enquiries`, `projects`, `employees`.
- **Project operational data:**
  - tasks: `enquiry_tasks`, `enquiry_task_user`, `task_assignment_history`
  - budgets: `task_budget_data`, `budget_additions`, `budget_versions`, `budget_approvals`
  - quotes: `task_quote_data`, `quote_approvals`, `quote_versions`
  - planning: `task_materials_data`, `task_procurement_data`, `task_production_data`, `project_deliverables`, `project_elements`, `element_materials`
  - crews: `teams_tasks`, `teams_members`
  - workflow: `site_surveys`, `design_requirements`, `design_assets`, `logistics_tasks`, `setup_tasks`, `setdown_tasks`, `handover_surveys`, `archival_reports`
- **Required dependencies:** `clients`, `departments`, `users`.
- **Employee HR history:** salary histories, documents, certifications, skills, HR actions, disciplinary, reviews, profile requests, leave, attendance, drivers.
- **Historical compatibility:** `technical_labours` (not the labour master).
- **Master data:**
  - suppliers, materials catalogue
  - Finance reference: chart of accounts, periods, expense codes, cost centres/causes, activities, payee types, payment sources, VAT/WHT, posting rules, finance settings, requisition types, payment terms
  - HR reference
- **Security/system/audit:** roles, permissions and their assignments, tokens, sessions, migrations, governance/HR/period audit logs, action logs, system events.
- **Cross-module (outside the reset):** procurement requisitions, stocks, inventory, work orders, boards, and the overtime/compensation/ledger tables.

## 6. Reset Boundary

`FinanceResetBoundary::RESET_ORDER` holds 51 tables, listed in §18. Of these, 18 are also flagged `CONFIRM_ON_PRODUCTION`: they must be empty on production, or WNG-confirmed development data, before they are included (invoices, client receipts, procurement-to-pay documents, vouchers, reconciliation, attachments).

The 13 Phase 2B tables are in the list for completeness. They do not exist in production before migration, and the reset runs before migration.

## 7. Project Budget Lifecycle Discovery (repository evidence)

1. **Created and saved:** `BudgetService::saveBudgetData()` does `updateOrCreate(['enquiry_task_id' => …])`. That gives **one budget row per budget task**. Saves come from the budget screen's 2-second autosave. `task_budget_data.status` resolves to `'draft'` for every save since 2026-07-07 (`resolveSaveStatus()`).
2. **Materials sync:** `syncFromMaterialsList()` rewrites the budget from the approved materials list, and **reopens a completed budget task** (`completed → in_progress`, audited), "rather than letting the status assert a sign-off of numbers nobody has seen."
3. **Finalized:** by the explicit "Complete Task" action only. `AutoSyncTaskStateAction` deliberately never auto-completes a budget, because autosave and the materials sync are not submissions. `EnquiryWorkflowService::validateTaskCompletion()` refuses completion without a saved, **priced** budget. Its comment: *"Since the internal budget approval step was removed (2026-07), task completion is the finalization signal consumed by procurement and finance summaries."*
4. **Commercial:** quote and quote approval follow (a separate commercial approval; not a budget state).
5. **Execution:** planned CostLines (W6) feed Stores, Petty Cash, the budget-line pickers and cost verification.
6. **Revisions:** edits update the same row. `BudgetProjector` supersedes the changed planned CostLines, and `BudgetRevisionRecorder` records the movement. `budget_versions` holds snapshots (history, not the budget).

**Proxy data** (local copy): 997 budget tasks, 384 completed. All 479 budget rows are `draft`, and every one sits on a `budget` task. No project has more than one budget task.

## 8. Authoritative Project Budget Definition

> **Authoritative Project Budget** = the `task_budget_data` row of the project's budget task (`enquiry_tasks.type = 'budget'`; latest by id if ever more than one), **when that budget task's status is `completed`.**

How the rule meets each requirement:
1. **One deterministic budget:** one row per budget task, and one budget task per project (latest wins).
2. **Incomplete budgets can't authorize labour:** completion requires a priced budget, autosave never completes, and a materials change reopens it.
3. **Obsolete versions can't authorize labour:** snapshots and superseded planned lines are never read, and budget rows outside the project's budget task are ignored.
4. **W7 reads the W6 planning basis:** both go through the same selector.
5. **Amendments are deterministic:** a revision edits the same row and re-projects, while historical actuals keep their rate snapshot.
6. **No new approval:** the rule uses an existing, audited workflow state.
7. **Labour still originates in the Project Budget:** the labour lines, quantities and rates come from the budget's `labour_data`.

## 9. W6/W7 Alignment

`App\Modules\Finance\CostCollector\Services\ProjectBudgetAuthority`:

| Method | Returns | Used by |
|---|---|---|
| `budgetTask()` | the project's budget task | all methods below |
| `currentBudget()` | the planning basis (finalized or not) | W6 projection; W7 budget-line display |
| `authoritativeBudget()` | `currentBudget()` only if finalized | W7 budgeted labour (record, resubmit with a new line) |
| `isFinalized()`, `isCurrent()`, `state()` | finalization, currency, and `none`/`in_progress`/`finalized` | W7 UI state; W6 projector guard |

**Why W6 projects in-progress budgets too:** planned CostLines are already consumed by Stores issues, the petty-cash budget-line picker, the cost-line picker, cost verification and unbudgeted-spend adoption while a budget is being worked on. Restricting projection to finalized budgets would silently turn that spend into "unbudgeted" for the 97 in-progress budgets in the proxy. That is an operational change outside W7 and not requested.

Both workflows use **one selector** (same record); W7 adds the finalization requirement because only W7 *authorizes cost* against the budget. There are no duplicated status filters.

## 10. W7 Defect Root Cause

`ProjectLabourActualService::approvedBudget()` filtered on `task_budget_data.status = 'approved'`. That condition was written against a budget lifecycle that ended on 2026-07-07. Every W7 test fixture built `approved` budgets, so the suite passed while real budgets (all `draft`) were invisible to W7.

## 11. Backend Correction

- **New `ProjectBudgetAuthority`** (§9).
- **`ProjectLabourActualService`:**
  - `approvedBudget()` is replaced by `authoritativeBudget()`, which delegates to the authority.
  - `getBudgetLabourLines()` reads `currentBudget()` and adds `recordable` per line (finalized, included and planned).
  - New `budgetState()`.
  - Error messages now say "The Project Budget is still in progress. Complete the Budget task…" and "This project has no Project Budget."
  - The rate-source type `approved_project_budget` is renamed to `project_budget`. It was never released, so no data migration is needed.
- **`ProjectLabourActualController`:** meta now carries `budget_state`.
- **`BudgetProjector::project()`:** projects only the project's **current** budget (`isCurrent`). A budget row on any other task is not a planning basis. No change for real data: all budget rows sit on budget tasks.
- **`finance:project-budgets`:** its description is corrected ("current budget"). It had no status filter, and still has none.
- **Server-authoritative controls are unchanged:**
  - The project comes from the route, and employees from Employee Records.
  - Line, quantity, rate and planned cost come from the authoritative budget; the client rate is discarded.
  - Each verified actual gets one analytical CostLine with `postsIndependently = false`.
  - No GL or payroll expense is created.
- **Test fixture corrected:** `UnbudgetedSpendAdoptionTest` attached its budget to a `materials` task, which never happens in real data. It now uses its own `budget` task.

## 12. Frontend Correction

`ProjectLabourPanel.vue`:
- Shows a budget-state banner:
  - in progress: "The Project Budget is still in progress. Budgeted labour can be recorded once the Budget task is completed. Unbudgeted labour can still be recorded."
  - no budget: "This project has no Project Budget yet…"
- Shows **Record** buttons only on `recordable` lines, and **Record Labour** only for a finalized budget. **Record Unbudgeted** stays available.
- Wording changed from "approved budget" to "Project Budget" / "current Project Budget".
- Types: `recordable`, `ProjectBudgetState`, `meta.budget_state`.

No other UI changed.

## 13. Budget Revision Behaviour

This is confirmed by tests, and **the behaviour is unchanged**:
- Verified actual labour keeps the rate snapshot taken at record time. Its CostLine is never rewritten.
- An actual recorded under the old budget and verified after a revision stays at its snapshot. Its consumption follows the same budget-line ID onto the current planned line.
- New labour uses the current budget's rate.

A **reopened** budget (materials changed) blocks *new* budgeted labour until the Budget task is completed again (§16, Scenario B2).

## 14. Project Budget Projection

`php artisan finance:project-budgets` (`BudgetProjector::projectAll()`) is idempotent. Each planned line is keyed by (source type, budget id, line id), changed lines are superseded, and removed lines are retired. It projects each project's current budget, **draft included**, and needs no `approved` status. `--dry-run` rolls everything back.

- **Scenario F:** a reset project yields exactly one planned line per labour line (total 29,000.00).
- **Scenario G:** a re-run adds nothing.

## 15. Queue Gap

**Budget projection:** also triggered by the queued `ProjectBudgetLines` listener. The release keeps the explicit one-time `finance:project-budgets` step (§18, §21).

**Wider finding:** this is **not only** budget projection. These listeners are also `ShouldQueue`:
- `RecordPurchaseOrderCommitments` (W2 commitments)
- `RecordGoodsReceiptAccruals` (GRN accruals)
- `RecordPettyCashCommitment` and `RecordPettyCashCost` (petty-cash cost recognition, STAB-7)
- `ReleasePettyCashCommitment` and `ReversePettyCashCost`
- `SyncBudgetWithMaterialsList`
- notification and mail jobs, and the Hikvision attendance sync

Master's own history shows `9853817` (2026-09-22) added queue-worker supervision, and `60491b1` the same day **removed** it: "production shared hosting has no service supervisor". Only the Stores postings were converted to synchronous.

`phpunit.xml` sets `QUEUE_CONNECTION=sync`, so every test runs these inline and cannot reveal the gap.

- **If production `QUEUE_CONNECTION` is `sync`:** no gap; listeners run inline.
- **If it is `database`/`redis` with no worker:** commitments, accruals and petty-cash costs **never post**. The fault is pre-existing on master, but Phase 2B relies on those paths.

**Classification:** DEPLOYMENT/INFRASTRUCTURE, **CRITICAL, release precondition.** Confirm production `QUEUE_CONNECTION` and the `jobs` backlog. If they are not `sync`, WNG/Engineering must choose one:
- set `QUEUE_CONNECTION=sync`, or
- schedule a cron `php artisan queue:work --stop-when-empty`.

The queue architecture was **not** redesigned here.

## 16. W7 Tests

New tests in `W7LabourCostTest` use the **real production budget state**: `task_budget_data.status = 'draft'`, finalized by the budget task. All W7 fixtures (including the concurrency suite) were changed from `approved` to that real state.

| Scenario | Test | Result |
|---|---|---|
| A: operational/current budget | `test_q4_scenario_a_a_finalized_draft_budget_authorizes_budgeted_labour` | Eligible; meta `finalized`; line `recordable`; labour verified (6,000.00); rate source `project_budget` |
| B: incomplete budget | `test_q4_scenario_b_an_in_progress_budget_cannot_authorize_budgeted_labour` | Plan visible, not recordable; budgeted record 422 "still in progress"; unbudgeted still records |
| B2: reopened budget | `test_q4_scenario_b_a_reopened_budget_stops_new_labour_until_completed_again` | Blocked while reopened; allowed again after completion |
| C: superseded version | `test_q4_scenario_c_a_line_from_a_superseded_budget_version_cannot_authorize_labour` | A line removed by revision → rejected |
| C2: non-authoritative row | `test_q4_scenario_c_a_budget_row_outside_the_project_budget_task_is_ignored` | Not projected; not listed; not recordable |
| D: inherited rate, override ignored | existing `test_explicit_rate_tampering_200_and_20000_is_ignored` (now on draft budgets) | 2,000 stays authoritative |
| E: revised budget | existing `test_budget_revision_keeps_historical_rate_and_new_labour_uses_v2` (now on draft budgets) | Historical 2,000 kept; new labour 2,500 |
| F / G: projection once / re-run | `test_q4_scenario_f_and_g_project_budgets_command_regenerates_planned_lines_once` | Dry run writes nothing; 2 lines once; re-run adds none |

**Mutation check:** making `isFinalized()` return true fails both Scenario B tests. The service was restored.

**Budget-related suites** (W7 + BudgetProjector + adoption + projection-on-completion + revision record): **113 tests, 459 assertions, all passed.**

**Frontend:** `wave7Labour.spec.ts` has **26 tests** (3 new: finalized, in-progress, no budget), all passing.

## 17. DATA-1 Reset Safety

`App\Modules\Finance\Support\FinanceResetBoundary` **describes and verifies; it contains no delete code.** Its `violations()` method re-derives safety from the live schema and fails closed when:
- a protected table is in the reset list;
- any table **outside** the reset set references a reset table, since resetting would null, delete, or be blocked by surviving data;
- a parent precedes a blocking (`NO ACTION`/`RESTRICT`) child;
- a table is listed twice.

`php artisan finance:reset-plan` (**read-only**) prints the ordered plan with row counts, the `CONFIRM_ON_PRODUCTION` flags, and the preservation counts, and exits non-zero on any violation.

`tests/Feature/Finance/FinanceResetBoundaryTest.php` has **6 tests, 49 assertions, all passing**, against the fully migrated schema:
- the plan is safe;
- every primary and dependency table is protected and never reset;
- adding `project_enquiries`, `employees`, `clients`, `departments`, `task_budget_data` or `technical_labours` is refused;
- resetting `clients` is refused **even with an empty protected list**, detected via `project_enquiries.client_id`;
- a swapped parent/child order is refused;
- the command is read-only.

Run on the local copy, the plan verified with zero violations and reported the counts in §18.

## 18. Ordered Reset Specification (not executed)

**Method:** ordered `DELETE` in one transaction per group. **No `TRUNCATE`, no `FOREIGN_KEY_CHECKS=0`, counters untouched.** Run **before** the Phase 2B migrations: after migration #1, PO children become RESTRICT.

Order is child-first on the 14 blocking foreign keys. `SET NULL` links never block.

**Steps before and after the deletes:**
- **Before:** record the preservation counts (`finance:reset-plan`), and require zero violations.
- **After:** preservation counts identical; every reset table's count is 0.

"Confirm" means the table must be empty on production or WNG-confirmed development data (`CONFIRM_ON_PRODUCTION`). Proxy rows are from the local copy.

| Order | Table | Reason | Child dependencies cleared first | Preservation impact | Verification |
|---|---|---|---|---|---|
| 1–2 | `project_labour_actual_returns`, `project_labour_actuals` | W7 records (Phase 2B; absent pre-migration) | returns before actuals | None | count 0 |
| 3–4 | `cost_line_allocations`, `cost_line_transfers` | W6 records (Phase 2B) | before `cost_lines` (transfers RESTRICT) | None | count 0 |
| 5–6 | `payment_allocations`, `spend_voucher_allocations` | allocation rows | before `cost_lines` (NO ACTION) | None | count 0 |
| 7–8 | `spend_voucher_reviews`, `stores_finance_postings` | review / outbox rows | — | None (stock itself untouched) | count 0 |
| 9–10 | `journal_lines`, `journal_entries` | Development journals (proxy 2/1) | lines before entries | `enquiry_payments.journal_entry_id` nulls (also reset) | count 0 |
| 11–14 | `finance_statement_matches`, `finance_statement_transactions`, `finance_reconciliation_statements`, `finance_cash_movements` | Reconciliation (confirm) | matches first | None | count 0 |
| 15 | `cost_lines` | Development lines (proxy 4); planned lines **regenerated** by `finance:project-budgets` | 3–6 cleared | None; budgets untouched | count 0, then regenerated |
| 16–18 | `project_invoice_allocations`, `project_invoice_lines`, `project_invoices` | Invoices (confirm) | allocations before invoices and receipts | Projects unaffected (child side) | count 0 |
| 19–20 | `enquiry_payments` (**Q2**, proxy 84), `client_receipts` (confirm, proxy 1) | Resettable receipts | 16 cleared | Projects keep identity and quotes | count 0 |
| 21–25 | `petty_cash_surrender_reviews`, `petty_cash_surrender_items`, `petty_cash_disbursement_allocations`, `petty_cash_requisition_items`, `petty_cash_requisitions` (**Q1**, proxy 22/49) | Development-era requisitions | items before requisitions | Employee/project payee links are on the child side | count 0 |
| 26–28 | `petty_cash_offline_rows`, `petty_cash_offline_batches`, `direct_disbursement_requests` | Offline/direct requests | rows before batches | None | count 0 |
| 29–33 | `bill_payments` (confirm), `payments` (**Q1**, proxy 1,554), `petty_cash_top_ups` (74), `petty_cash_ledger_entries` (1,628), `petty_cash_activity_logs` (480) | Imported register | bill payments before payments; payments before top-ups | None | count 0 |
| 34–36 | `petty_cash_cash_counts`, `petty_cash_custody_handovers`, `petty_cash_balances` | Float (**Q1**); recreated at 0.00 by `PettyCashBalance::current()` | counts/handovers before balance | None | count 0; float reads 0.00 |
| 37 | `spend_vouchers` (confirm) | Vouchers | allocations/reviews cleared | None | count 0 |
| 38–42 | `salary_advance_recoveries`, `payslips`, `payroll_ledgers`, `salary_advance_requests`, `payroll_runs` (**Q3**, proxy 0/0/2/1/1) | Payroll-development records | recoveries/payslips/ledgers before advances/runs | **Employee Records and salary history untouched** | count 0; `employees` count unchanged |
| 43–50 | `bills`, `goods_receipt_inspections`, `goods_receipt_note_items`, `goods_receipt_notes`, `purchase_order_amendments`, `purchase_order_corrections`, `purchase_order_items`, `purchase_orders` (confirm) | Procurement-to-pay documents | bills/GRNs/items before POs (NO ACTION) | Stock already received stays in Stores | count 0 |
| 51 | `finance_attachments` (confirm) | Evidence rows of reset documents (files on disk are not deleted by the plan) | — | None | count 0 |

**Afterwards:**
1. Run the Phase 2B migrations.
2. `permissions:sync`.
3. `finance:project-budgets --dry-run`, then the real run.
4. Assert the preservation counts again.

## 19. Revised Validation Strategy

Following Report 46 §23-B, it is a **reduced, preservation-focused rehearsal** on a **recent production database copy**, restored into a separate local database (never `db_test`). The stale shared staging environment is no longer mandatory.

The rehearsal must show, in order:
1. `finance:reset-plan` has zero violations, with preservation counts recorded.
2. The reset script succeeds.
3. Preservation counts are identical; sampled projects, employees, budgets and quotes are unchanged.
4. All 23 migrations succeed, including #14 on the emptied petty-cash tables.
5. `permissions:sync` succeeds, and Accounts holds `invoice_check`.
6. `finance:project-budgets` regenerates planned lines once.
7. The W1–W7 clean smoke tests pass, **including W7 budgeted labour on a real, finalized Project Budget**.

**Not performed:** no production copy is available to this environment.

## 20. Decision Register Changes

- **New section "Data Preservation (DATA-1)":** DATA-1 **CONFIRMED (2026-09-28)**. It records Q1/Q2/Q3 as RESET (non-authoritative), the preservation boundary, the reset method, and the encoded boundary. It states that the reset is **not executed**.
- **W7 section, "W7-1 / W7-2 Project Budget Authority: corrected":** records the canonical rule (completed budget task; `ProjectBudgetAuthority`; W6/W7 aligned; the rate-snapshot rule unchanged).
- **Unchanged:** all other rows, including W7-24/25/26.

## 21. Remaining Release Preconditions

1. **Production-copy rehearsal** (§19). Needs one production dump, taken by someone with production access.
2. **Queue connection:** confirm production `QUEUE_CONNECTION` and `jobs` backlog. If it is not `sync`, choose `sync` or a scheduled `queue:work --stop-when-empty` (§15).
3. **B2:** verified production backup, plus preservation-set and reset-set exports (Report 46 §21–22).
4. **B3:** production preflight P0–P10 plus the Report 46 Q-A to Q-D queries, and a production run of `finance:reset-plan`. `CONFIRM_ON_PRODUCTION` tables must be empty or WNG-confirmed.
5. **Release order:** reset → backend (+ migrations) → `permissions:sync` → `finance:project-budgets` → frontend → smoke tests.
6. **Confirm** overtime/compensation remain unused (Report 44 §14).

## 22. Regression Results

**Backend:** full suite (DDEV, JUnit `storage/logs/q4-gate-junit.xml`): **1,444 tests, 9,515 assertions, 0 failures, 0 errors, 0 skipped**, 8 min 32 s. That is the accepted baseline of 1,432 / 9,440, plus 6 new W7 budget-authority tests and 6 reset-boundary tests.

**Frontend:**

| Gate | Result |
|---|---|
| Complete unit suite | **27 files, 169 tests, all passed** (166 + 3 new) |
| ENG-1 type-check | **256 errors, identical to the baseline, 0 new** |
| Production build | **exit 0**, 1,891 modules |

## 23. Final Verdict

### PASS — DATA-1 CONFIRMED AND W7 BUDGET AUTHORITY CORRECTED

**DATA-1 is confirmed and recorded.** The reset boundary is specified, encoded in `FinanceResetBoundary`, and fail-closed-tested. The reset itself is **not executed**.

**Q4 is resolved from existing repository state.** The authoritative Project Budget is the project's budget task's data once that task is completed. This is the existing finalization signal since approval was retired, so no new approval was introduced. It is implemented once in `ProjectBudgetAuthority`, used by W6 and W7. W7's invalid `approved` dependency is removed, with all server-authoritative labour controls intact. Tests use the real production budget state. All regressions are green; ENG-1 is 256 with 0 new, and the build succeeds.

**Carried to the release gate:** the production queue-connection check (§15), found during this work.

### NEXT: PRODUCTION-COPY REHEARSAL OF THE CONTROLLED FINANCE RESET AND PHASE 2B RELEASE

Not performed in this task.
