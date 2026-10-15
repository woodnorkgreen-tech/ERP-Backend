# Report 78C Stage B — Project Lifecycle-Aware Material Queues: Implementation

**Date:** 2026-10-15 · **Baseline:** `78C_PROJECT_LIFECYCLE_MATERIAL_QUEUE_AUDIT.md` (Stage A) and the Report 78B implementation.
**State:** built in the working tree and **uncommitted**. Not deployed. Production is untouched. The dev database `db` received only the additive 78C migration and the four new permission grants (section 6). No historical record was changed.
**Evidence:** `78c-evidence/lifecycle_baseline.php` → `summary.json` (before) · `78c-evidence/lifecycle_after.php` → `after.json`, `overdue_triage.csv` (after). Both scripts are read-only and roll back.

**Verdict: COMPLETE** for the implementation, with operational go-live steps still open (section 9). See section 10.

---

## 1. What changed, in one paragraph

One service, `ProjectMaterialWorkflowEligibility`, now decides whether a job may have material resolved, issued, counted as a shortage, or bought for. It reads `project_enquiries.status`, an open **hold**, and requirement-level **legacy retirements**, and never the stale `projects.status` mirror. The filter is applied where demand is calculated (`ProjectMaterialDemand`), so the Desk forecast, the buyer's material picker, the replenishment planner and the new summary tiles all inherit it. Each Desk tile's number is the length of the list it opens. Stock issues, board requests, project requisitions and Resolve Material are refused on ineligible jobs. An authorised manager can override with a recorded reason where that makes sense. Cancelling, reactivating and holding a job are governed actions, and the generic status edit can no longer reach them. Returns, corrections, purchase orders, GRNs, bills, payments and finance exceptions are never filtered.

## 2. Status rules (as built)

| State | From | Resolve | Issue / board request | Shortage | New procurement | Desk tab |
|---|---|:-:|:-:|:-:|:-:|---|
| `active` | enquiry `planning` / `in_progress` | ✔ | ✔ | ✔ | ✔ | **Active work** (default) |
| ↳ overdue | `active` and `COALESCE(expected_delivery_date, projects.end_date) < today` | ✔ | ✔ | ✔ | ✔ | Active work **and** Overdue active, badged "Overdue N d" |
| `pre_start` | `quote_approved` / `awaiting_deposit` | ✔ | ✘ | ✔ | ✔ | Pre-start (D-3) |
| `on_hold` | active or pre-start + an open `project_holds` row | ✔ | override | ✘ | override | On hold |
| `completed`, `closed` | enquiry status | ✘ | override | ✘ | override | Historical / Completed (read-only) |
| `cancelled` | enquiry status | ✘ | ✘ | ✘ | ✘ | Historical / Completed |
| `pipeline` | any earlier status | ✘ | ✘ | ✘ | ✘ | All projects only |
| legacy *(tag)* | active job where every included line has an active retirement covering its current quantity | — | — | — | — | Historical / Completed |

- **The enquiry status alone decides the state.** Stage A had proposed requiring a `projects` row as well. That was dropped: activation writes `planning` and creates the row together, and a confirmed job waiting for its deposit has no row yet. Anything that moves stock or counts demand still needs the row, because the issue takes a `project_id` and demand is read per project. The Desk lists only project rows.
- **Override (D-4):** the line or request carries `lifecycle_override_reason` (≥ 10 characters). The user must hold `project_materials.closed_job_override`. Each use writes a `material_lifecycle_override` governance event, and the reason is appended to the movement's notes. Cancelled and not-yet-started jobs have no override.
- **Legacy is per line, not per job.** A retirement removes its quantity from demand (`remaining = required − net issued − retired`). A line added after retirement, or a quantity increase on a retired line, shows as live work, and the job drops back into Active work.
- **Returns, link corrections (`linkProjectMaterial`), PO creation from an approved requisition, and fulfilment of an existing board request are deliberately not guarded.** Each one is either an obligation already taken on or a correction.

## 3. Permission decisions (all six Section 9 decisions adopted)

| Permission | Purpose | Granted to (`RolePermissions` matrix) |
|---|---|---|
| `project.lifecycle.govern` | Cancel / reactivate a job (D-6) | Super Admin, Admin, Manager, Project Manager |
| `project.hold.manage` | Place / release a hold (D-2) | Super Admin, Admin, Manager, Project Manager |
| `project_materials.retire_legacy` | Retire / revoke "Fulfilled outside ERP" (D-1) | Super Admin, Admin, Manager, Project Manager — **not Stores** |
| `project_materials.closed_job_override` | Issue / request / buy on a completed, closed or on-hold job (D-4) | Super Admin, Admin, Manager — **not Project Manager** |

The override was kept away from Project Manager on purpose. A Project Manager can reopen a job, but cannot also authorise material work on it while it is finished. Manager holds `stores.manage` as well as the override and retirement permissions. That is a segregation point WNG may want to narrow (section 9).

- **D-3:** pre-start jobs create demand and shortage but cannot be issued to.
- **D-5:** the triage list (`overdue_triage.csv`, 162 jobs) is produced. **Nothing was completed or retired, and nothing is classified by date.**

## 4. Code changes

### 4.1 New

| File | Role |
|---|---|
| `database/migrations/2026_10_15_000001_report_78c_project_holds_and_requirement_retirements.php` | `project_holds`, `material_requirement_retirements`. Additive. Short FK names (MariaDB 64-character limit). Append-only: rows are stamped (`released_*`, `revoked_*`), never deleted. |
| `ProcurementStores/Services/ProjectMaterialWorkflowEligibility.php` | The policy. `stateOf()` is the only place a state is decided. `describeMany()` (for screens), `eligibleEnquiryIds()` (for queries) and `assertAllows()` (for writes, including the override) all use it. |
| `ProcurementStores/Services/MaterialRequirementRetirements.php` | Retire / revoke / history. Refuses: no permission, reason under 10 characters, job not active or on hold, open board request on the line, nothing outstanding. Revoking needs a reconciliation note. |
| `ProcurementStores/Services/ProjectMaterialWorkflowSummary.php` | The metrics and the lists behind them, from the same calls (`summarise()` / `queue()`). |
| `ProcurementStores/Services/MaterialShortageForecast.php` | The forecast arithmetic, moved out of the controller so the shortage tile and the forecast drawer are one calculation. |
| `ProcurementStores/Controllers/ProjectMaterialWorkflowController.php` | `GET material-workflow-summary`, `GET material-workflow-queue?metric=`, `GET/POST projects/{project}/legacy-retirements`, `POST …/revoke`. |
| `Projects/Actions/ProjectLifecycleAction.php` + `Projects/Http/Controllers/ProjectLifecycleController.php` | `POST enquiries/{id}/cancel`, `/reactivate`, `/hold`, `/hold/release`, `GET /lifecycle`. Each is one transaction with its governance record. Cancel reports open requisitions, POs, pending board requests and unpaid bills, and touches none of them. Reactivating a completed or closed job requires a reconciliation note. |
| `Projects/Models/ProjectHold.php`, `Models/MaterialRequirementRetirement.php` | Models. |
| `Console/Commands/ReconcileProjectStatusMirrorCommand.php` | `projects:reconcile-status-mirror`. **Dry run by default.** `--apply` is refused in production and only copies the enquiry status onto the mirror. |

### 4.2 Changed

| File | Change |
|---|---|
| `ProjectMaterialDemand` | `pendingLines()` / `pendingByMaterial()` take a capability (default `procurement_demand`) and filter jobs through the policy at the source. A single private `fulfilment()` now serves both the Desk's per-project view and every cross-project total. Before this, the totals ignored pre-linkage issues that the Desk allocated FIFO, so they could not reconcile. Retired quantity is subtracted. New `unresolvedLines()` and `unitExceptionLines()`. |
| `StockMovementPoster` | Every project issue (single, batch, movements, controlled, consumable) is checked in `classifyIssue()`. The line cap subtracts retired quantity. |
| `StockMovementRequest` | Accepts `lines.*.lifecycle_override_reason`. |
| `BoardRequestController::store` | Same lifecycle check before anything else. The cap subtracts retired quantity. |
| `RequisitionController` | `store` (project type), `update` (when it newly points at a job) and `submitForApproval` (project drafts) check the `procurement_demand` capability for every job named on the lines. The job is read from each line's `project_enquiry_id` first, because **the Projects procurement task posts an enquiry id in `project_id`** (`ProcurementTask.vue`) while the Stores desk posts a projects key there. Reading `project_id` alone would have checked the wrong job, or none, for real requisitions (found by `ProjectWorkflowContractsTest`). Editing a requisition already attached to its job is not re-judged. |
| `ProjectMaterialResolution::assertOpenForStores` | Its inline status check (the third private copy of the rule, which also read the mirror) is replaced by the policy. A fully retired line is refused. |
| `ReplenishmentPlanner` | Printing escalations (`PrintMaterialRequest`) for a job follow the same `procurement_demand` rule; print work with no job is unaffected. Open requisition lines (`draft` / `pending_approval` / `approved`) **not yet on a PO** are subtracted, converted to stock units. A unit with no conversion is not guessed at. The suggestion gains a `requested_open` field. |
| `ProcurementStoresController::materialDemandForecast` | Uses the `shortage` capability and `MaterialShortageForecast`. |
| `UpdateEnquiryAction` | `status=cancelled`, and any move out of completed, closed or cancelled other than to closed, returns 422 and points to the governed action. |
| `SyncEnquiryStatusAction` | Now skips `closed` jobs as well. Previously a task change on a closed job could rewrite its status: a side door for reopening. |
| `routes/api.php` (`/api/projects`), `MaterialsController::getMaterialsByEnquiry` | Each project and job carries the `lifecycle` block. Each line carries `retiredQuantity`. |
| `ProjectEnquiry::openHold`, `EnquiryController` index, `EnquiryResource` | `on_hold` on the enquiry list. |
| `Permissions`, `RolePermissions` | The four permissions (section 3). |

### 4.3 Frontend (ERP-Frontend)

| File | Change |
|---|---|
| `views/stores/ProjectMaterialsDesk.vue` | Tabs are now Active work (default) · Overdue active · Pre-start · On hold · Historical / Completed · All projects, built from the server `lifecycle.queue`. New badges: overdue days, legacy. New "Active work" tiles (To issue / To resolve / Awaiting registration / Unit exceptions / Historical) whose counts come from the summary endpoint and open the matching list. Each job gets a lifecycle notice. The issue command shows an override-reason field only for a permitted user on an overridable job, and that reason is sent with issues and board requests. Remaining quantity subtracts retired quantity. A "Fulfilled outside ERP" button opens the retirement modal. |
| `components/LegacyRetirementModal.vue` (new) | Retire the whole job or selected outstanding lines with a reason. History with revoke (reason + reconciliation note). |
| `components/MaterialWorkflowQueueModal.vue` (new) | The cross-project list behind a tile, searchable, opens the job. |
| `projects/views/ProjectsEnquiries.vue` | Cancel now calls `POST /cancel` with a required reason and reports open commitments. New Reactivate (with reconciliation note), Put on hold / Release hold and an "On hold" chip. The quick status dropdown routes cancel and reopen to the governed actions. |
| `views/procurement/Replenishment.vue`, `composables/useReplenishmentPlan.ts` | Shows "Requested, not yet ordered" in the arithmetic. |
| `projects/components/tasks/ProcurementTask.vue` | Now shows Laravel's `errors`/`message`, so a lifecycle refusal reads as its reason instead of "Request failed with status code 422". |
| `projectLifecycleDesk.spec.ts` (new) | Covers default tab, tab counts against the stale mirror, retired quantity, and the override reason. |

## 5. Before → after (dev `db`, same data)

| Measure | Before (Stage A) | After |
|---|---:|---:|
| Desk default tab | "In Progress" — 536 jobs (356 completed, 2 cancelled, 4 pre-project among them) | "Active work" — **174** (162 of them overdue) |
| Pending to issue (lines) | 342 | **140** (129 on overdue jobs) — tile = list = 140 |
| Pending resolution (lines) | 4,940 (no lifecycle split) | **1,416** on 96 jobs (active + pre-start) — tile = list |
| Unit exceptions on active jobs | not counted | **26** lines on 15 jobs |
| Materials with procurement demand | 41 | **16** |
| Procurement shortage (materials at risk) | not tiled | **13** of 16 |
| Replenishment suggestions | 36 | **13** (2 now net of open requisitions) |
| Historical outstanding (read-only list) | — | **3,031** lines |
| Jobs per tab | — | active 174 · overdue 162 · pre-start 3 · on hold 0 · historical 358 · pipeline 1 · all 536 |

Stage A predicted 12 replenishment suggestions. The extra one comes from D-3, which counts pre-start jobs as demand.

## 6. Migration and data requirements

| Item | Dev `db` | Target / production |
|---|---|---|
| `2026_10_15_000001_report_78c…` | **applied** with `--path` | Needs approval. Additive, no backfill. **A push to master runs it unattended**, so it is a deploy decision. |
| `permissions:sync` | **applied** (dry run first: it created exactly the 4 permissions and the 15 role grants above, nothing else) | Run at deploy. |
| `projects:reconcile-status-mirror` | dry run only: 363 mismatches (356 planning→completed, 2 →cancelled, 5 with a pre-project enquiry status the mirror cannot hold) | **Not run.** Correcting the mirror changes `ProjectsDashboardService` counts (it reads the mirror and reports 0 completed). It needs WNG approval. |
| Retirements, holds | none created | none. Retirement is per job, by a person, from the triage list. |

## 7. Data exceptions found

1. **162 jobs are `planning` past their delivery date** (2 ≤ 90 days, 156 at 91–180, 4 over 180), and 160 of them have had no Stores movement in 90 days. They are the bulk of "active" work and must be triaged (D-5) using `overdue_triage.csv`. Completed jobs go through `/complete`. Supplied-by-hand requirements get retired. Nothing happens automatically.
2. **The `projects.status` mirror disagrees on 363 rows** (above). Nothing reads it any more for material work.
3. **2 open requisitions on completed jobs.** They stay visible and are not cancelled. The new cancel/reactivate dialogs report such commitments.
4. **26 unit exceptions on active jobs** (BOM unit not readable in stock units; Report 78B register). Now counted on their own tile instead of silently missing from demand.
5. **Pre-existing, not fixed:** the replenishment draft writes base-unit quantities with the *purchase* unit id. The new open-requisition subtraction reads each line by its stated unit, so for a material whose purchase unit differs from its stock unit, a planner draft would be over-subtracted. No dev material is affected today. It needs fixing alongside the PO-unit question in `incoming`.
6. **Pre-existing identity defect:** `requisitions.project_id` holds an *enquiry* id for requisitions raised from the Projects procurement task, and a *projects* id for those raised from Stores. 78C works around it by reading each line's `project_enquiry_id`. The column itself is not repaired.

## 8. Tests

### 8.1 Report 78C — `tests/Feature/Stores/ProjectLifecycleMaterialQueueTest.php` (17 tests)

| Brief # | Test |
|---|---|
| 1, 2, 3, 5 | `only_active_jobs_including_overdue_ones_are_pending_to_issue` (projects.status deliberately stale) |
| 4 | `a_job_fulfilled_outside_the_erp_leaves_the_queue_but_new_work_on_it_stays` (+ quantity-increase case) · `retirement_needs_its_permission_a_reason_and_a_live_job` |
| 6 | `an_on_hold_job_pauses_issue_and_buying_until_released` |
| D-3 | `a_job_waiting_for_its_deposit_creates_demand_but_is_not_issued_to` |
| 7, 13 | `reactivating_a_completed_job_restores_its_demand_exactly_once` (permission, reconciliation note, no ledger change, planner stops suggesting once a requisition exists) |
| 8, 9, 14 | `cancelling_a_job_reports_but_never_cancels_its_purchase_orders_or_bills` |
| 10 | `historical_requirements_stay_readable_through_their_own_filter` |
| 11 | `every_summary_count_equals_its_queue_list` (summary vs queue endpoint vs `/api/projects` tab counts) |
| 12 | `procurement_demand_uses_the_stores_eligibility` (picker, forecast, planner) |
| 14 | `lifecycle_filtering_never_moves_stock_or_money` |
| 15 | `issuing_to_a_finished_job_is_refused_unless_an_authorised_manager_records_why` · `board_requests_and_project_requisitions_are_refused_for_finished_jobs` · `the_generic_status_update_cannot_bypass_the_governed_actions` · `resolving_a_line_on_a_finished_job_is_refused` |
| 12 | `printing_requests_for_finished_jobs_raise_no_buying` |
| Phase 7 | `the_status_mirror_command_is_a_dry_run_by_default` |

Result: **17/17 passed.**

### 8.2 Full backend suite, before vs after

Both runs use the same tests. The "before" run is a frozen copy of the pre-78C tree (`storage/app/r78c-baseline`, own database `db_r78c_base_test`). The "after" run is the working tree (`db_r78c_test`).

| | Tests | Failures + errors |
|---|---:|---:|
| Before (frozen pre-78C tree) | 1,991 | 51 |
| After (78C) | 2,007 | 50 |

- **New failures: 0.** Every test that passed before still passes.
- The 50 remaining are the same tests failing the same way before and after, and none touches 78C code: `SourceMigrationToolingTest` 36, `W7LabourConcurrencyTest` 5, `RequisitionReceiverDisbursementConcurrencyTest` 5, `PrintMaterialRequestFulfilmentTest` 2 (a 422 from the fulfilment endpoint, identical message before and after), `Report76A3BillingConcurrencyTest` 1, `NotificationApiTest` 1.
- One baseline failure passed in the after run (`FinanceControlCentreTest::…policy_projection…`). It is unrelated to 78C and is probably order- or time-sensitive.
- **Fixture corrections made to get there (53 tests across 8 classes, all caused by one thing).** Seven fixtures created a `projects` row and left the enquiry at its default `enquiry_logged`, then issued stock or counted demand for it. Under the new authority that job is not live. Each fixture's enquiry now gets `status => in_progress`, matching what the test means. The rule was not loosened. The eighth class, `ProjectWorkflowContractsTest`, exposed the requisition `project_id` defect above, and the guard was fixed instead.
- **One test was rewritten on purpose:** `ReplenishmentPlannerTest::test_existing_requests_are_visible_without_reducing_the_shortage` pinned the old behaviour that requirement 11 removes. It is now `test_open_requests_are_visible_and_reduce_the_suggestion`: an open request for 8 turns a suggestion of 10 into 2, and a rejected request restores 10.
- The last full run preceded the printing filter. After that change, the planner, printing, requisition, contract and 78C classes were re-run (66 tests). All pass except the same two pre-existing printing failures.

### 8.3 Report 78B integrity and UoM tests (test 16)

All 44 Report 78B tests (`ResolveMaterialIntegrityTest`, `ResolveMaterialConcurrencyTest`, `ResolveProjectMaterialCatalogueTest`, unit and identity tests) pass in the final run, with only the enquiry-status fixture correction above. Line identity, unit conversion, BOM approval and the issue cap are unchanged. The cap now also subtracts retired quantity.

### 8.4 Frontend

- `vitest` procurement-stores and projects: 100/100 passed, including the 3 new `projectLifecycleDesk.spec.ts` tests.
- `vue-tsc`: 0 errors in the 6 touched files. The repo-wide total is 267, all in files outside this slice.
- `vite build`: passed.

## 9. Outstanding go-live risks

1. **Uncommitted, shared tree.** 78C sits on top of uncommitted 76A–78B work in both repos and touches files 78B also changed (`ProjectMaterialDemand`, `ProjectMaterialResolution`, `StockMovementPoster`). Commit by explicit path only.
2. **Live data with the same shape as the old fixtures.** On dev, 4 projects have an enquiry that never reached planning (1 `enquiry_logged`, 1 `quote_approved`, 2 `awaiting_deposit`). They are pipeline or pre-start now, so they cannot be issued to. That is correct, but someone used to issuing to them will be refused.
3. **The 162-job triage is the real go-live work.** Until it is done, "Active work" and "To resolve" (1,416) are dominated by jobs that are probably finished.
4. **Manager holds both `stores.manage` and the closed-job override.** Every override is logged, but WNG may want the override held by a non-Stores role only.
5. **Mirror correction** needs approval because it changes the Projects dashboard numbers.
6. **Performance.** The summary endpoint evaluates all eligible lines on each call (fine at 5,473 dev lines). Watch it as the BOM grows.
7. **Unit-label defect in planner drafts** (section 7.5).

## 10. Verdict

**COMPLETE** — every acceptance criterion holds in code and is pinned by a test:

| Acceptance criterion | Evidence |
|---|---|
| Completed, closed, cancelled and legacy jobs absent from active queues | tests 1–4; dev: To issue 342 → 140, Desk default 536 → 174 |
| Overdue active jobs still visible | test 1 (flagged, 120 days); dev: 162 overdue jobs in Active work |
| Historical BOM requirements accessible | test 10; dev: 3,031 lines in the Historical list; nothing deleted |
| Pending-issue and procurement metrics exclude inactive requirements | tests 1, 6, 12 and the printing test; dev: replenishment 36 → 13 |
| Procurement and financial obligations stay visible | tests 8, 9; cancel reports POs and bills and changes neither |
| Reopening does not duplicate demand or transactions | tests 7, 13, 14 |
| Stores and Procurement use the same rules | one service; test 12 |
| Counts reconcile to their records | test 11; dev: every tile equals its list |
| Backend enforcement under direct API calls | test 15 (four tests) |
| 78B controls preserved | 44/44 |

This is not go-live. Open items are commit and review, the production migration and permission sync (approval), the 162-job triage (D-5, a Projects task), and the mirror correction (approval). None of them was done, as instructed.
