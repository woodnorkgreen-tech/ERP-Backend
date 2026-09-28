# 41 — Phase 2B Workflow 7 Labour Cost: Remediation and Re-Closure Gate

**Date:** 2026-09-28
**Inputs:** Decision Register (`03`), Reports 34–40, backend repository (`ERP-Backend`, branch `finance/critical-stabilization-fixes`), frontend repository (`ERP-Frontend`, branch `master`). Report 40 is treated as the authoritative prior finding.
**Verdict:** **FAIL — W7 REMAINS OPEN.** One remaining blocker: the repository type-check has no accepted baseline, and none of its errors are caused by W7 (§27, §35).

---

## 1. Executive Summary

Every functional and evidence blocker from Report 40 (items A–N) has been fixed and tested:

- Return → Correct → Resubmit is now a real server lifecycle.
- The Finance rate-resolution and verified-correction screens are built.
- W7-13 now uses the W6-4 Cost Transfer reversing pair.
- Every required monetary scenario runs as an executable Project Costing assertion.
- Concurrency is proven with true multi-process races.

One blocker remains, and it is not caused by W7. The frontend repository fails `vue-tsc`: 256 errors at committed HEAD, 258 now. The 2 extra errors come from uncommitted procurement Wave-2 work, and W7 adds 0. No formally accepted type-check baseline exists. Report 40 and this brief both forbid inventing one to close W7, so under the gate's own wording W7 stays open until WNG either sets a baseline or the repository is cleaned. Two WNG instructions also bound this gate:

- **W7-10 scope:** during this remediation WNG confirmed that work orders, Technical Labour and overtime are not in use and must be left out. Those modules are therefore classified, not changed (§4).
- **Open policy decisions:** W7-24, W7-25 and W7-26 remain untouched.

## 2. Prior Closure Failure

Report 40 (2026-09-27) failed W7 on items A–O: active Technical Labour; no resubmit lifecycle; no Finance rate UI; no verified-correction UI; no closed-project UI; W7-13 divergence; no GL delta, budget-revision, monetary-matrix or portfolio proofs; serialized "concurrency" test; decimal precision; incomplete regression; a 3-test frontend suite; failing type-check.

This gate also found that an unrecorded remediation pass (2026-09-27, 14:12–14:18) had already touched some files. Its work was reviewed, not trusted. Three defects from it were found and fixed:

| Defect from the unrecorded pass | Effect | Fix |
|---|---|---|
| `resubmit()` moved `recorded → po_verified` and stamped the resubmitter as PO verifier | Skipped Project Officer review. Only worked on corrected records, never on returned ones. | Rewritten (§5) |
| `TeamsMember::technicalLabour()` removed while `PettyCashRequisitionController::getProjectTeamMembers()` still eager-loaded it | Project team-member lookup for petty cash would throw (HTTP 500) | Eager load and phone fallback removed |
| `UpdateTeamMemberRequest` made `member_name` required and dropped `is_active`, `efficiency_rating`, `performance_notes` | Broke partial team-member updates | Original rules restored; only `technical_labour_id` removed |
| Phantom permission `finance.costs.resubmit` (never created by any migration) | Resubmit could never be granted | Removed; resubmit is the recorder's capability (§5) |

## 3. Decision Register Reconciliation

The register already carried a "FAILED — REMEDIATION IN PROGRESS" W7 header (written 2026-09-27), and no W7 row claimed full implementation. The obsolete Report 39 wording ("all blockers resolved", "Technical Labour migrated", "frontend N/A") no longer appears in the register. It survives only inside Report 39 itself, which is a historical document and was not edited. The final register update is in §32. Confirmed decisions, historical rows, and W7-24/25/26 are preserved unchanged.

## 4. Technical Labour Decommissioning (W7-10)

**Scope instruction (WNG, during this gate):** work orders, Technical Labour and overtime are not currently in use and are left out. Changes made to those modules at the start of this gate were reverted. The remaining references there are classified, not modified.

| Area | Reference | Classification |
|---|---|---|
| `technical_labours` table, FKs on `teams_members`, `ot_entries`, `compensations`, `ledger_entries`; `TechnicalLabour` model; HR migrations | Schema and model | **Historical — retained** |
| HR routes `technical-labour/*` | Commented out since 2026-09-24 | **Retired** |
| `TechnicalLabourController`, frontend `TechnicalLabourPanel`, `useTechnicalLabour` | Unrouted / unmounted | **Compatibility — non-operational** (module left out by WNG) |
| Overtime and compensatory-leave controllers, `OvertimeService`, `OvertimeReportController`, HR overtime/compensation modals | Tech-labour branches | **Out of scope — module not in use (WNG instruction)**. Creation paths were already Employee-only from the earlier pass. |
| Work orders (`WorkOrderTaskController`, `JobCard*`, production views) | Legacy `technical_labour` assignee type | **Out of scope — module not in use (WNG instruction)** |
| `ProductionAssigneeController`, `ProductionReportsController` | Employee-only (earlier pass) | **Retired** |
| Petty cash payee search (`PettyCashRequisitionController`) | Offered active Technical Labour as payees | **Was active — defect, fixed.** Employee Records only. |
| Petty cash project team members | Eager-loaded the removed relation; used tech-labour phone as payee phone | **Was active — defect, fixed** |
| Teams member create/update | `technical_labour_id` accepted | **Retired** (create: earlier pass; update: this gate, with the unrelated rule loss reverted) |
| `TeamsTask.vue` crew picker | Picked from the disabled `/api/hr/technical-labour` route and posted `technical_labour_id` and a day-rate-derived hourly rate | **Was active — defect, fixed.** Picks from Employee Records (`/api/hr/employees/directory`: name and job title only). No rate is derived from pay. |
| `ReportTask.vue` technician picker | Same disabled route | **Was active — defect, fixed.** Employee Records. |
| `RequisitionForm.vue` | Labelled payees "(Tech Labour)" | **Fixed** (search no longer returns them) |

**Bounded:** `teams_members` has no `employee_id` column. Crew identity links to Employee Records by name, the same key the petty-cash payee lookup already matches on. An Employee FK on crew members would be a separate, additive change.

**Result:** no unjustified active operational dependency remains in the in-use modules. The modules WNG says are unused are classified above rather than modified.

## 5. Resubmission Workflow

The full lifecycle is: Record → Project Officer review → Return for Correction → recorder corrects permitted fields → **Resubmit** → Project Officer review → Project verify → Finance review → Finance verify.

- **Endpoint:** `POST /api/costs/projects/{enquiry}/labour-actuals/{actual}/resubmit`, accepting corrected fields.
- **Authorization:** the recorder's capability (`finance.labour.record` plus project assignment, or Finance). It is checked in both the controller and the service.
- **Transition:** `returned_for_correction → recorded`. The previous PO verification is cleared because it approved the version that was returned, so Project Officer review genuinely restarts.
- **Server authority:** permitted fields are usage, work date, employee, rework type, the unbudgeted reason, and the unbudgeted classification. The rate is never accepted. Changing the budget line re-derives role, category, unit and rate from the approved budget. For unbudgeted labour, changing the classification resets a previously resolved Finance rate to *unresolved*.
- **Return rules by stage:** from `recorded`, the Project Officer returns; from `po_verified`, Finance or the Project Officer.
- **Immutable history:** new table `project_labour_actual_returns`, one row per cycle. It holds the cycle number, the stage returned from, who returned it and when, the reason, a full snapshot before the change, who resubmitted and when, and field-level `from`/`to` changes. The model refuses edits after resubmission and refuses deletion.
- **Attribution:** `resubmitted_by`, `resubmitted_at` and `resubmission_count` on the actual. Audit event `resubmit` carries the field changes.
- **Frontend:** the returned card shows the return reason and full history with a **Correct & Resubmit** dialog.
- **Tests:** backend `test_return_correct_resubmit_full_lifecycle` (two cycles, HTTP), plus stale-PO, status, permission, stage-authority, immutability, and classification-reset tests. Frontend: return, resubmit and history tests.

## 6. Unbudgeted Labour Rate Resolution

- **Backend:** Finance-only (the service now re-checks the permission). The actual must be PO-verified, unbudgeted and unresolved. The rate must be positive, at most 2dp, with a mandatory source and authorization reference. The rate is never derived from salary. The analytical amount is recomputed by the server.
- **Frontend:** the pending card shows the Unbudgeted status, operational reason, usage, "Rate unresolved" state, and rate source/reference. Finance Verify is disabled until the rate is resolved. The **Resolve Rate** dialog states that the rate must not be derived from payroll salary, and refuses to submit without both source and reference. Users without Finance authority never see it.
- **Verification:** Finance verification then uses the resolved rate (backend test shows 2 × 2,000 = 4,000 posted, no budget line consumed).
- **Tests:** backend `test_rate_resolution_requires_source_reference_and_positive_rate`. Frontend covers the unresolved state, unauthorized user, missing source/reference, successful resolution, and Finance verify afterwards.

## 7. Verified Labour Correction

A verified actual is never edited. **Correct** opens a successor that holds the corrected usage (rate, role, category, unit and budget line are immutable) and runs PO → Finance verification. The UI shows:

- the original value
- the reason
- the proposed value
- who opened the correction, and when
- the original ↔ successor relationship
- the reversing-pair references (OUT/IN refs and amounts)
- the authoritative amount, which stays the original's until the correction is Finance-verified

**Reclassify** moves a verified cost to another project. Only one correction at a time is allowed per actual. The HTTP response exposes no payroll data (§19).

## 8. W7-13 Architecture Reconciliation

**The confirmed rule:** reuse W6-4 `CostTransferService` with a `CL-TRF-OUT` / `CL-TRF-IN` reversing pair. Report 40's finding was that the implementation instead mutated the original CostLine to `reversed` and created a fresh actual.

**Reconciled design (implemented):**

| Case | Mechanism |
|---|---|
| Cross-project reclassification | `CostTransferService::transfer()`, unchanged W6-4 behaviour: OUT on the source project, IN on the destination. New endpoint `…/reclassify`. The actual records `reclassification_transfer_id`. |
| Same-project correction | New `CostTransferService::correct()`, the same reversing pair on the same project: OUT = −original (releases its budget-line consumption), IN = +corrected (same budget line). Posted atomically when the successor is Finance-verified. The successor's CostLine *is* the IN leg. |

The original CostLine is never modified in either case. `transfer()` itself could not be reused unchanged for same-project work because it requires different projects and an IN equal to the source. `correct()` is therefore the same architecture specialised to that case, and the business decision is unchanged. Both kinds are recorded in `cost_line_transfers` with a new `transfer_type`.

Before the successor is verified, the original remains authoritative, so the project never shows 0 or double. A correction's IN leg may itself be corrected or reclassified later (correction chains). OUT legs and reclassification IN legs still cannot be re-moved.

**Proof:** `test_correction_20000_to_16000_project_costing_is_16000`. Project Costing reads **20,000** before, **20,000** while the correction is pending, and **16,000** after (not 36,000, not 0). The budget-line position (verified 16,000, remaining 4,000) agrees. Further tests cover a correction chain (20,000 → 16,000 → 18,000) and a reclassification (A: 0, B: 10,000; the old project cannot then correct it).

**W6 defects found and fixed while reusing the service:** the "already transferred" guard ran *before* the lock (check-then-act), and the IN amount went through a float. Both are fixed, and `cost_line_transfers.source_cost_line_id` is now UNIQUE.

## 9. Closed Project Controls

- **Server:** every transition that changes labour cost checks closure under a row lock: record, PO verify, return, resubmit, rate resolution, Finance verify, correct, reclassify, and the correction pair itself.
- **UI:** the list and budget endpoints return `meta.financial_closure_status`. On a closed project the panel shows a closure banner, hides every action, and disables the dialog submit.
- **Tests:** backend `test_closed_project_blocks_every_labour_transition` (HTTP 422 for six transitions, no CostLine created) plus the existing per-transition tests. Frontend closed-state test.

## 10. Server Authority

Role, category, unit and rate for budgeted labour come only from the approved budget and its active planned CostLine. The client `unit_rate` is discarded for every capture, budgeted and unbudgeted. Every service method re-checks permission, so the controller is not the only guard.

## 11. Cross-Project Protection

- Project B's budget-line ID posted to Project A → 422, both on record and on resubmit.
- Project B's actual ID through Project A's route → 404.
- No CostLine is created in any of these cases (`test_project_a_actual_cannot_use_project_b_budget_line`).

## 12. Rate Tampering

The approved rate is KES 2,000. A client sends KES 200, then KES 20,000 (and a forged role and unit). Both times the stored rate is 2,000.00, the cost is 2,000.00, the unit is PAX, and the rate source is `approved_project_budget` (`test_explicit_rate_tampering_200_and_20000_is_ignored`, HTTP).

## 13. Budget Revision Immutability

The test uses the legitimate mechanism: edit the approved budget, then run `BudgetProjector::project()`, which supersedes the planned line and records the revision (`test_budget_revision_keeps_historical_rate_and_new_labour_uses_v2`).

- **V1 (KES 2,000), actual verified:** it keeps rate 2,000 and its CostLine stays 2,000 and verified.
- **Actual recorded under V1 but verified after V2:** verifies at 2,000. Its consumption follows the same budget-line ID onto the current planned line. Previously this path would have failed on the reversed planned line; that defect is fixed.
- **New actual:** 2,500, consuming the V2 planned line.
- **Project total:** 6,500.

## 14. GL Delta / One Economic Cost Once

`test_finance_verification_moves_project_labour_but_not_the_company_ledger` captures three things before and after Finance verification: the journal entry count, the journal line count, and the net of payroll expense accounts (5200*/7550*).

- **After a KES 16,000 verification:** all three are identical, project Actual Labour goes from 0 to 16,000, and the CostLine has no journal.
- **After a correction pair:** the ledger is still identical and project labour reads 14,000.

`postsIndependently = false` is therefore proven economically, not just as metadata.

## 15. Project Costing Monetary Matrix

Every case asserts the labour row of the real W6 statement (`CostAccountService::forEnquiry`):

| Case | Planned | Actual | Remaining (Budget − Actual) |
|---|---|---|---|
| Normal: 10 × 2,000 budget, 8 used | 20,000.00 | 16,000.00 | 4,000.00 (favourable) |
| Over: 12 used | 20,000.00 | 24,000.00 | −4,000.00 (adverse; not capped; W7-7 alert written) |
| Unused: 50,000 budget, 30,000 used | 50,000.00 | 30,000.00 | 20,000.00 (no unused budget becomes Actual) |
| Correction: 20,000 → 16,000 | 20,000.00 | 16,000.00 | 4,000.00 |

The sign convention is the established one: remaining = planned − spent, and negative means over budget.

## 16. Portfolio Reconciliation

`portfolioMargin()` now returns `actual_labour`. It is one batched query, classified with the *same* SQL category expression the project statement uses (extracted into a shared `CATEGORY_EXPR` constant, so the two cannot drift).

- **Reconciliation:** across three projects (plain, corrected, pending-only), each project's portfolio figure equals its statement, and the portfolio sum equals the statement sum: **20,500.00**.
- **N+1:** the query count is identical for 1 project and for 4 projects (`test_portfolio_query_count_does_not_grow_with_projects`).

## 17. Concurrency / Idempotency

The new class `W7LabourConcurrencyTest` runs **true races**. It commits the fixture, forks 4 PHP processes, gives each its own DB connection, releases them together at a barrier, and has each attempt the same transition:

| Race | Outcome asserted |
|---|---|
| Finance verify ×4 | All succeed idempotently. Exactly 1 CostLine and 1 audit event. Project labour 20,000 |
| Correct ×4 | Exactly 1 succeeds. 1 successor. Project labour unchanged |
| Finance verify of a correction ×4 | 1 reversing pair. Original superseded. Project labour 16,000 |
| Rate resolution ×4 (different rates) | Exactly 1 succeeds. 1 audit event. Stored rate, amount and source agree |
| W6 `transfer()` ×4 | Exactly 1 transfer. Cost moved once |

- **Result:** 5 tests, 59 assertions, all passed.
- **The harness actually races:** as a mutation check, the row lock in `financeVerify()` was removed temporarily. The test then failed, because concurrent inserts collided and a deadlock surfaced to a caller. The lock was restored and verified afterwards.
- **Safety:** the class commits real data, so it refuses to run unless connected to `db_test`, and deletes everything it created. db_test was checked and is clean.
- **Database guarantees:** unique `cost_line_id` and `reversal_of_id` on actuals; unique `source_cost_line_id` on transfers; unique `(actual, cycle)` on return history.

## 18. Decimal Precision

- **Calculation:** W7 cost is `usage × rate` in bcmath at scale 8, rounded half-up to the cent once. The repository had no single rounding helper (CostCollector truncates, InvoicePricer rounds via float), so W7 documents its choice.
- **Budget-line response:** returns exact decimal strings (previously `(float)`).
- **Project Costing:** `CostAccountService::money()` and the category pivot no longer go through floats.
- **Transfers:** the IN amount no longer uses a float.
- **Input:** usage is limited to 2dp at the API, so the stored usage and the cost always agree.
- **Tests:**

| Calculation | Result |
|---|---|
| 1,234.56 × 2.75 | **3,395.04** (exact; CostLine equal) |
| 1,234.57 × 0.33 = 407.4081 | **407.41** |
| 1,234.56 × 2.75 × 1.5 | **5,092.56** |

  The budget-line remaining is 8,950.56, the project total is 3,802.45, and 3dp usage input is rejected.
- **Frontend:** it never computes an authoritative amount. Its only aggregation is summing server strings in integer cents (BigInt `sumMoney`, tested with 0.10 + 0.20 = 0.30).
- **Bounded:** the float formatting that remains in `CostAccountService` is limited to percentages and the W6 materials element breakdown. Neither is labour or authoritative.

## 19. Payroll Privacy

The resource serializes only the employee's ID, name and staff number, plus user ID and name. The new fields (history, corrections, transfers, rate source) carry no pay data. The rate source is allow-listed.

`test_labour_api_payloads_never_expose_payroll_data` scans the list, show and budget-line payloads for an employee whose salary is 50,000. None of these strings appear: salary, the salary figure, KRA PIN, ID number, bank, NSSF, payslip, or deduction. The frontend test confirms the rendered panel shows no salary or bank data even when an API payload carries it.

## 20. Audit Trail

`test_every_labour_event_is_audited_with_actor_and_timestamp` asserts a GovernanceAuditLog row, with actor and timestamp, for each of: `record`, `return_for_correction`, `resubmit`, `po_verify`, `finance_verify`, `alert` (over budget), `correct`, `correction_requested`, `supersede`, `reclassify`, `resolve_unbudgeted_rate`. Return cycles are additionally kept in the immutable history table, and transfers in `cost_line_transfers` (who, why, OUT/IN).

## 21. Backend W7 Tests

| Suite | Tests | Assertions | Passed | Failed | Skipped |
|---|---|---|---|---|---|
| `W7LabourCostTest` | 79 | 341 | 79 | 0 | 0 |
| `W7LabourConcurrencyTest` | 5 | 59 | 5 | 0 | 0 |

Both were run in DDEV (PHP 8.4, MariaDB 11.8, db_test). Report 40 recorded 55 tests. Stale tests that encoded the superseded behaviour were rewritten: the mutated-original correction, the serialized "concurrency" test, direct status mutation instead of resubmit, and integer money assertions.

## 22. Relevant Backend Regression

Taken from the same completed full-suite run (JUnit log `storage/logs/w7-full-junit.xml`), grouped by area:

| Area | Tests | Assertions | Failed | Errors | Skipped |
|---|---|---|---|---|---|
| Project Budget / BudgetProjector / BudgetRevisionRecorder / unbudgeted adoption | 28 | 92 | 0 | 0 | 0 |
| Cost Collector / Cost Account / allocation / verification | 185 | 3,063 | 0 | 0 | 0 |
| Cost Transfer (W6-4) | 7 | 30 | 0 | 0 | 0 |
| Portfolio margin (W6) | 3 | 19 | 0 | 0 | 0 |
| Financial closure / reopen (W6) | 6 | 36 | 0 | 0 | 0 |
| Payroll | 26 | 89 | 0 | 0 | 0 |
| Payment settlement / architecture | 34 | 174 | 0 | 0 | 0 |
| Journal posting / ledger / WIP | 56 | 307 | 0 | 0 | 0 |
| Employee | 12 | 107 | 0 | 0 | 0 |
| Petty cash (including STAB-7) | 150 | 707 | 0 | 0 | 0 |
| Permissions / policies / self-approval | 28 | 1,049 | 0 | 0 | 0 |
| Audit / integrity | 57 | 233 | 0 | 0 | 0 |
| W7 labour (cost + concurrency) | 84 | 400 | 0 | 0 | 0 |

**Result: complete, all passed.** Production and Teams have **no backend tests** in the repository, so there is nothing to run for them. Their changes here (Teams update-request rule restore; Production untouched after the WNG scope instruction) have no automated backend coverage. Overtime and compensatory leave were not changed (out of scope). The petty-cash payee-search and team-member endpoint changes are not directly covered by an existing test; the 150 petty-cash tests all pass.

## 23. Full Backend Suite

Complete runner, `vendor/bin/phpunit` in DDEV (PHP 8.4, MariaDB 11.8, db_test): **1,427 tests, 9,421 assertions, 0 failures, 0 errors, 0 skipped**, exit 0, 8 min 18 s. It includes `W7LabourConcurrencyTest`, and db_test was left clean. There are no unrelated failures to classify. (Report 36's W6 baseline was 576 tests.)

## 24. Frontend Functional Verification

`ProjectLabourPanel.vue` was rebuilt against the real project-scoped endpoints. It now covers:

- server totals (budget, verified, awaiting verification, signed variance)
- per-line budget, verified, pending and variance, with an over-budget badge
- budgeted capture that sends usage only
- unbudgeted capture with a mandatory reason
- PO verify, return, correct & resubmit, resolve rate, and Finance verify
- verified correction, reclassification, and correction/transfer display
- the closed-project state
- server error display

Every write re-reads the server state; the panel keeps no local accounting. A pre-existing template bug was also fixed: the old panel called an undefined `handleResubmit`, and its "Correct" button opened the Return dialog.

## 25. Frontend W7 Tests

`tests/unit/finance/wave7Labour.spec.ts`: **23 tests, 23 passed**. They cover mounting; permission visibility; budget-line selection; inherited role, category, unit and rate (read-only); the rate never being sent; budgeted and unbudgeted capture; return for correction; correction editing and resubmit; resubmission history; Project verify; the unresolved rate state; an unauthorized user; missing source/reference; successful Finance resolution; Finance verify afterwards; a server refusal; verified correction (open, pending, completed with reversing pair); reclassify; no-permission correction; over-budget and variance; payroll privacy; the closed-project state; and exact decimal totals.

Mutation check: removing the closure gating and the unresolved-rate block from the panel fails exactly the two corresponding tests.

Also added: `tests/unit/projects/teamsTaskEmployeePicker.spec.ts` (1 test, passed). The crew picker uses Employee Records and posts no `technical_labour_id` or pay-derived rate.

## 26. Frontend Regression

Complete Vitest run: **26 files, 165 tests, all passed, 0 failed** (before the TeamsTask spec was added). With that spec: 27 files, 166 tests. Report 40 had 145 tests.

## 27. Type-Check

`vue-tsc --build --force` exited with code 2.

| Tree | Errors |
|---|---|
| Committed HEAD (clean `git archive` copy, same `node_modules`) | **256** |
| Current working tree | **258** |

A normalised diff of the two error sets shows exactly **2** new errors. Both are in `procurement-stores/shared/composables/useOrderWorkflow.ts` (`'correct'` / `'senior-approve'` not in an action union), from uncommitted procurement Wave-2 work. **0 errors are in any W7-touched file** (cost-collector types/composable/panel, TeamsTask, ReportTask, taskService, useTeams, RequisitionForm, W7 specs). The 13 `@/network-class` entries are the same errors reported at different absolute paths.

**Baseline status:** no formally accepted type-check baseline exists. No document establishes one, and CI (`deploy.yml`) does not run type-check. Per the gate, none is invented here. The repository does not type-check cleanly, so this engineering criterion is **not satisfied**. It is a repository-level condition, not a W7 defect.

## 28. Production Build

`vite build` completed: exit 0, 1,891 modules, 26.7 s. Warnings, classified:

- **Chunk size above 500 kB:** pre-existing and general.
- **Mixed dynamic/static imports** (`useAuth`, `MainLayout`, `axios`, universal-task api): pre-existing.
- **esbuild "assignment will throw because it is a constant"** in `ReceiveStockModal.vue` (`quickCreate`) and `ResolveMaterialModal.vue` (`newMaterial`): pre-existing procurement defects that will throw at runtime if those paths run. Not W7; recorded for the procurement owners.
- **Node deprecation / localStorage notices:** tooling.

No W7 file appears in any warning. A passing build is recorded separately from the failing type-check.

## 29. Defects Found and Fixed

| # | Defect | Fix | Evidence |
|---|---|---|---|
| 1 | Resubmit skipped PO review; no resubmit for returned actuals | Real `returned → recorded` lifecycle with history | §5 tests |
| 2 | Phantom `finance.costs.resubmit` permission | Removed; recorder capability | §5 |
| 3 | Petty-cash team-member lookup eager-loaded a removed relation (HTTP 500) | Removed | §4 |
| 4 | Team-member update rules silently dropped | Restored | §4 |
| 5 | Petty-cash payee search offered Technical Labour | Employee only | §4 |
| 6 | TeamsTask / ReportTask pickers used the disabled Technical Labour route; TeamsTask derived an hourly rate from day rate | Employee Records directory; no pay-derived rate | §4, TeamsTask spec |
| 7 | W7-13: correction mutated the original CostLine and bypassed W6-4 | Reversing pair via `CostTransferService::correct()` / `transfer()` | §8 |
| 8 | Budget-line "consumed" included unverified actuals and would double-count a pending correction | Verified from CostLines; pending shown separately, net of the original | §8, §15 |
| 9 | A V1 actual verified after a budget revision failed on the reversed planned line | Consumption follows the budget-line ID | §13 |
| 10 | W6 transfer check-then-act race | Guards under lock plus a unique index | §17 |
| 11 | Float money in the W7 response, `CostAccountService`, and the transfer IN leg | bcmath | §18 |
| 12 | Usage >2dp stored rounded while the cost used the raw value | 2dp validation | §18 |
| 13 | Rate resolution accepted a zero rate and was Finance-checked only in the controller | Positive rate; service-level permission | §6 |
| 14 | Service transitions (return, correct, resolve) had no service-level permission check | Added | §10 |
| 15 | Old panel: undefined `handleResubmit`; "Correct" opened Return | Panel rebuilt | §24 |

## 30. Remaining Technical Gaps

1. **Repository type-check (blocking under the gate):** 256 pre-existing errors at HEAD, 2 from uncommitted procurement work, 0 from W7. No accepted baseline exists.
2. **Crew identity (bounded, non-blocking):** crew members link to Employee Records by name; there is no `teams_members.employee_id` FK.
3. **Modules out of scope by WNG instruction:** Technical Labour references in work orders, overtime and compensatory leave are classified, not changed.
4. **Pre-existing procurement runtime warnings** (constant assignment), noted in §28. Not W7.

## 31. Remaining Policy Decisions

- **W7-24 / W7-25 / W7-26:** unchanged and open (GL/WIP treatment; standard-cost vs actual payroll variance; employer statutory absorption).
- **Separate policy question (non-blocking):** the per-actual Finance rate for unbudgeted labour is implemented strictly as the W7-6 control (a documented Finance authorization per actual). It is not a WNG-wide rate policy. Should WNG approve a standing unbudgeted-labour rate source (for example a rate card), and who owns it?
- **Type-check baseline (engineering governance):** should WNG/Engineering formally adopt the committed-HEAD error set as a baseline with a zero-new-errors rule, or require a repository-wide clean-up before workflow closures?

## 32. Decision Register Changes

The W7 gate-status paragraph was updated in place with the re-closure result (§35) and remaining blocker. No confirmed decision, historical row, or W7-24/25/26 row was changed. No W7 row is marked FULLY IMPLEMENTED.

## 33. Files Modified

**Backend**
- `database/migrations/2026_09_28_000001_w7_labour_remediation.php` (new; verified up → down → up)
- `app/Modules/Finance/CostCollector/Models/ProjectLabourActualReturn.php` (new)
- `app/Modules/Finance/CostCollector/Models/ProjectLabourActual.php`, `CostLineTransfer.php`
- `app/Modules/Finance/CostCollector/Services/ProjectLabourActualService.php`, `CostTransferService.php`, `CostAccountService.php`
- `app/Modules/Finance/CostCollector/Http/Controllers/ProjectLabourActualController.php`
- `app/Http/Resources/ProjectLabourActualResource.php`
- `app/Services/ProjectFinancialAccess.php`, `app/Constants/Permissions.php`
- `routes/api.php` (reclassify route; unused `TechnicalLabourController` import removed)
- `app/Modules/Finance/PettyCash/Controllers/PettyCashRequisitionController.php`
- `app/Modules/Teams/Requests/UpdateTeamMemberRequest.php`
- `tests/Feature/Finance/W7LabourCostTest.php`, `tests/Feature/Finance/W7LabourConcurrencyTest.php` (new)
- This report and `03_WNG_FINANCE_DECISION_REGISTER.md`

**Frontend**
- `src/modules/finance/cost-collector/types/costCollector.ts`, `composables/useProjectLabourActuals.ts`, `components/ProjectLabourPanel.vue`
- `src/modules/projects/components/tasks/TeamsTask.vue`, `ReportTask.vue`, `services/taskService.ts`, `composables/useTeams.ts`
- `src/modules/finance/petty-cash/views/requisitions/RequisitionForm.vue`
- `tests/unit/finance/wave7Labour.spec.ts`, `tests/unit/projects/teamsTaskEmployeePicker.spec.ts` (new)

Both repositories have large pre-existing uncommitted trees; those were preserved. Nothing was committed or pushed. Stray `*.orig` files from an earlier session (`W7LabourCostTest.php.orig`, `wave7Labour.spec.ts.orig`) were left untouched.

## 34. Closure Assessment

| PASS criterion | Status |
|---|---|
| Active Technical Labour retired / Employee Records used | Met for in-use modules; unused modules classified per WNG |
| Return → Correct → Resubmit end-to-end | Met |
| Project verification | Met |
| Unbudgeted rate resolution end-to-end | Met |
| Finance verification | Met |
| Verified correction | Met |
| W7-13 reconciled | Met |
| Closed-project controls, backend and frontend | Met |
| Rate tampering blocked | Met |
| Cross-project line misuse blocked | Met |
| Historical rate snapshot | Met |
| GL non-duplication | Met |
| Project Costing matrix | Met |
| Portfolio reconciliation | Met |
| Concurrency / idempotency | Met (true races) |
| Decimal precision | Met |
| Payroll privacy | Met |
| W7 backend tests | Met (84/84) |
| Relevant backend regression | Met (complete run; all passed) |
| Full backend suite result obtained | Met (1,427 / 0 failures) |
| Complete W7 frontend tests | Met (23/23) |
| Frontend regression | Met (165/165) |
| Type-check satisfies a legitimate baseline | **Not met.** No accepted baseline; the repository fails (0 W7-caused) |
| Production build | Met |
| Audit trail | Met |
| Decision Register accurate | Met |

## 35. Final Verdict

### FAIL — W7 REMAINS OPEN

**Remaining blocker (one):**

1. **Frontend repository type-check.** `vue-tsc` fails with 258 errors: 256 already present at committed HEAD, 2 from uncommitted procurement Wave-2 work in `useOrderWorkflow.ts`. **0 are caused by W7.** No formally accepted type-check baseline exists, and this gate may not create one after the fact. To close W7, one of these must happen:
   - WNG/Engineering formally adopts a baseline (for example, the committed-HEAD error set with a zero-new-errors rule) and it is recorded, or
   - the repository is brought to a clean type-check.

   Then a re-closure pass can issue **PASS — W7 CONFIRMED ANALYTICAL SUBSET CLOSED**. Every other criterion in §34 is met.

**Not blocking, recorded:**
- W7-24, W7-25 and W7-26 are open policy decisions.
- The unbudgeted-rate policy question in §31.
- Name-based crew identity (§4).
- Technical Labour references in work orders, overtime and compensatory leave, left out by WNG instruction because those modules are not in use.

W8 was not started. W7-24, W7-25 and W7-26 were not implemented.
