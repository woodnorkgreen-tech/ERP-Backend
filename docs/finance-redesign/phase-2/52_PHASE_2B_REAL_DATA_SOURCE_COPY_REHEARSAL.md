# 52 — Phase 2B: Real-Data Source-Copy Migration Rehearsal

**Date:** 2026-09-28

**Inputs:**
- rehearsal snapshot `erpsystem-20260928-0918.sql.gz`;
- Reports 50–51 and runbook 51A;
- WNG decisions D1–D8 (Decision Register, MIG-D1…D8);
- branch `finance/critical-stabilization-fixes`.

**Boundary:**
- **No connection to `woodnork_erpsystem` or `woodnork_erp`.** Everything ran on local, disposable databases.
- No deploy, no cutover, no production queue, no freeze, no URL change, no merge, no W8.

**Verdict:** **PARTIAL — REHEARSAL BLOCKED BY WNG DECISION** (§28). The gate is **D3** (chart of accounts).

---

## 1. Executive Summary

- **The real WNG snapshot migrated with full preservation.**
  - The backup was verified: SHA-256 matched and gzip was intact.
  - It was restored twice, into isolated local databases.
  - Stage 1 upgraded the copy with the target's 172 pending migrations and lost **no operational row**.
  - A clean 607-migration target was built.
  - **228 tables and 90,892 rows loaded with original IDs**, and all 228 reconciled by count, key range and checksum.
  - **Validation PASS**, with 0 orphans introduced. All DATA-1 exclusions held.
- **Preservation matches the restored copy exactly:** 737 Projects, 93 Employees, 1,387 Enquiries, 658 Project Budgets, 258 Clients and 65 Users. These equal the operator's post-backup observation. Enquiry, client, task, budget, quote, team, deliverable, element and HR relationships match source-for-source.
- **Authentication and authority are preserved:**
  - 65/65 users with hashes unchanged, compared by digest and never printed;
  - employee↔user links identical, role assignments identical;
  - permissions regenerated with **no current grant lost**;
  - `ProjectBudgetAuthority` agrees with its SQL rule on real data: 497 finalized budgets;
  - D5 planned CostLines regenerated for **299 open-project budgets only**: 3,877 lines, none on closed projects, idempotent.
- **Three migration defects were found by real data and corrected.** The corrected pipeline then passed cleanly from scratch, three times.
  1. A wrong unenforced-reference parent produced 592 false orphans.
  2. A source table without a primary key caused a false reconciliation mismatch.
  3. **The W6/W7 permission grants were lost on a clean target.** No role could record or verify labour until the matrix was corrected.
- **The W1–W7 real-data smoke test (HTTP API, real imported users and projects, queue drained with the production drain shape) has 0 FAIL.** Every step that did not pass is **BLOCKED by D3**:
  - With no chart of accounts, 107 of 109 expense codes stay inactive (activation needs a postable account). No journal can post: AR 1100, AP 2150, WIP 1212, and the paying-account GL mapping are all missing.
  - That blocks labour Finance-verify, coded expenses and disbursements, GRN accrual journals, bill verification and payment, vouchers and invoice issue.
- **D2 is effectively moot:** the source has **0** POs, GRNs, bills and bill payments. D2 stays WNG's decision.
- **The queue works in the rehearsal environment.** Budget projection, PO commitment, petty-cash commitment and its release all execute through the database queue and the drain. The GRN accrual executes but its journal is D3-blocked.
- **Also found (live source, not touched):**
  - The live ERP's own queue has **10,941 unprocessed jobs dating from 2026-07-28**: push and email notifications, and `ProjectActivated` events.
  - Live runs code whose **10 August migrations never ran**.

## 2. Backup Verification

| Check | Result |
|---|---|
| File | `ERP-Backend/database/erpsystem-20260928-0918.sql.gz`, 31,927,278 bytes |
| SHA-256 | `28bced26bad9b49ddbc4731f288b4c0bd1095e47076d254bafa99604c9e945e2` — **MATCH** |
| gzip integrity | OK |
| Header | MariaDB dump 10.19, server **11.4.13-MariaDB**, database `woodnork_erpsystem` |
| Redirect risk | **0** `CREATE DATABASE`/`USE` statements, so a restore cannot target another database. **0** `DEFINER`s |
| Collations | `utf8mb3_unicode_ci`, `utf8mb4_unicode_ci`, `utf8mb4_bin`, all supported locally |
| Repository safety | The file sits inside the repo and was **not** git-ignored. It is now excluded through `.git/info/exclude` (local, never committed). It contains personal data: delete it after the rehearsal |

## 3. Isolation Proof

- **Databases:** `wng_source_pristine` (untouched restore: count authority), `wng_source_rehearsal` (Stage 1 transformation copy), `wng_target_rehearsal` (clean target). All three are on the local DDEV server `ERP-Backend-db` (MariaDB 11.8). None existed before; they were created for this task.
- **The live names were never used.** Tooling guard `live_source_databases=[woodnork_erpsystem]` refuses the live source by name and by resolved identity. `live_target_databases=[woodnork_erp]` requires `--cutover` for the live target.
- **Checkout:** a dedicated worktree (`storage/app/wng_rehearsal`, release commit plus this task's fixes) with its own `.env`. Before any command, `config:show` proved the target resolved to `wng_target_rehearsal` and `source_staging` to `wng_source_rehearsal`.
- **Source safety:** Stage 2 reads the transformation copy through `SET SESSION TRANSACTION READ ONLY`. Stage 1 refused anything but the copy.
- **Smoke suite:** it refuses any database not named `wng_*rehearsal*`.
- **Engine difference, noted for parity:** source 11.4.13, rehearsal 11.8.

## 4. Restored Source Counts

These are the authoritative rehearsal baseline, from `wng_source_pristine`:

| Entity | Restored copy | Operator post-backup |
|---|---|---|
| Projects | **737** | 737 |
| Employees | **93** | 93 |
| Project Enquiries | **1,387** | 1,387 |
| Project Budgets (`task_budget_data`) | **658** | 658 |
| Clients | **258** | 258 |
| Users | **65** | 65 |

**Other baseline facts:**
- tables **239**;
- migration ledger **448 rows / 80 batches**;
- last activity: project created 2026-09-28 12:12:40, user updated 11:52:08.

| Distribution | Values |
|---|---|
| **Projects** | planning 683 · completed 53 · cancelled 1 |
| **Enquiries** | completed 486 · planning 319 · enquiry_logged 269 · cancelled 220 · quote_prepared 44 · awaiting_deposit 20 · design_completed 12 · site_survey_completed 7 · materials_specified 6 · quote_approved 2 · budget_created 1 · in_progress 1 |
| **Employees** | active 74 · on-leave 11 · terminated 5 · inactive 3 |

## 5. Source Schema Validation

The restored copy was compared with the schema rebuilt from source commit `5bf4ab3` (Report 50).

| Finding | Detail | Handling |
|---|---|---|
| **Live source is behind its code** | 10 migrations dated 2026-08-06…08-13 are **not in the ledger**: inventory controls, item types and UoM registry, lot traceability, controlled inventory instances, receipt cost, reconcile projections, `requisition_items.supplier_id`, GRN store confirmation and status, `inventory_logs.unit_price`. That leaves 7 tables and 41 columns absent | Stage 1 ran them as ordinary pending migrations |
| Asset migrations | The ledger already has the renamed `2026_06_29_000010/11` (batch 75), and both schema effects exist | Reconciliation correctly a no-op |
| Legacy ledger/tables | 13 ledger names with no file; `design/finance/logistics_task_contexts` present | Plan `skip` |
| Column drift | 144 type/nullability differences, mostly `varchar(255)` vs the chain's `varchar(191)` | Classified by the gate (§11) |
| Missing primary key | `app_notifications` has no PK in the source (uuid `id`); the target declares one | Reported (gate note); caused defect D-2 (§25) |

## 6. Orphan Scan

On the transformed copy, after the defect D-1 correction: **24 broken relationships.** All pre-existing, none dropped.

- **21 references to 12 users hard-deleted from the source** (ids 1, 3–9, 11, 14, 17, 20; the source has no soft deletes):
  - `task_assignment_history` assigned_by 46 / assigned_to 13;
  - `enquiry_tasks` assigned_by 34 / assigned_to 8;
  - `enquiry_task_user` assigned_by 32 / user_id 9;
  - `model_has_roles` 14 (dangling role assignments of the deleted users);
  - `project_enquiries.project_officer_id` 13;
  - `transport_items.created_by` 13;
  - `work_orders.assigned_to` 12;
  - `teams_activity_logs` 10;
  - `teams_members` 6 + 2;
  - `archival_reports` 4;
  - `teams_tasks` 2;
  - and 1 each in `design_assets`, `logistics_tasks`, `quote_versions`, `setdown_checklists`, `setdown_tasks` (created_by and updated_by).
- **`production_elements.material_id` → `element_materials`: 151** stale references to replaced materials-list items. These are genuine; see defect D-1 for the 592 false ones.
- **`quote_approvals`: 10 rows** (ids 11, 73, 87, 99, 114, 123, 129, 151, 256, 257) whose `task_id` and `enquiry_id` point at missing records.

**For the disposable rehearsal target**, all 24 were allow-listed and loaded **as-is** (never dropped or re-pointed). **Cutover needs WNG approval of this list** (§24).

## 7. D2 Evidence

From the restored copy (`migration:evidence d2`):

| Dataset | Rows |
|---|---|
| Purchase Orders | **0** |
| PO Items | **0** |
| GRNs | **0** |
| GRN Items | **0** |
| Bills | **0** |
| Bill Payments | **0** |

There are no statuses, date ranges, project or supplier links, or test indicators to report, because there are no rows. **The decision-pending isolation withholds no data.** D2 remains WNG's decision and was not made here.

## 8. Role Mapping

`source_role_mapping_report` covers 15 source roles and 95 assignments:
- **All 15 are exact target matrix equivalents:** Super Admin 10, Designer 20, Project Officer 18, Production 10, Project Manager 8, Logistics 5, Client Service 4, Employee 4, Stores 4, HR 3, Costing 3, Manager 2, Accounts 2, Admin 1, Procurement 1.
- **No `Procurement Officer` or custom role exists.** No role was removed.

**Role-design issues for WNG** (found by the smoke test; none is a migration defect, and none decided here):

| # | Issue |
|---|---|
| R-1 | The **Project Officer** role holds **no W7 labour permission** in source or target. W7 recording and PO-verification go to Project Manager, Costing and Admin (migration `2026_09_24_000006`). Should the 18 Project Officers record or verify labour? |
| R-2 | **No role holds `finance.petty_cash.custody`** (cash counts, custody handovers). Only Super Admin, by bypass. Who is the petty-cash custodian? |
| R-3 | **Accounts lacks `finance.reports.view`**, so it cannot open Finance readiness or reports. Source: Super Admin only |
| R-4 | 14 dangling role assignments of 12 deleted users (§6) |
| R-5 | `finance.petty_cash.delete_disbursement`, held by 4 source roles, is **obsolete** (not in the permission registry) and correctly not carried |

## 9. Stage 1 Transformation

`migration:stage1 --execute` on `wng_source_rehearsal`:
- **172 pending** (the 162 target-only migrations plus the 10 source-era migrations live never ran);
- **44 s, all DONE**; ledger 448 → 620.

**No operational row was lost.** Every one of the 239 source tables was compared with the pristine copy. The only count changes were additive migration seeds:

| Change | Detail |
|---|---|
| Permissions | +58 (with +169 role grants) |
| Material categories | +2 |
| Chart of accounts | +3 (payroll link) |
| New tables | UoM 17, item types 6, material workstations 438, expense codes 109, requisition types 8, finance settings 2 |
| Rename | `petty_cash_disbursements` → `payments`, **1,554 = 1,554** |

**Backfills, verified against their rules:**

| Backfill | Result |
|---|---|
| Delivery-date status | **231 TBC = 231** source enquiries without a date; 0 formula violations; 1,156 confirmed (total 1,387) |
| Return kind | 1/1 returns classified `whole_item` |
| Buying UoM | 1/1 requisition lines with a material received `uom_id` |
| Board custody | 0 boards; nothing to backfill |
| Enquiry core columns (id, client, status, Project Officer) | Checksum identical before and after |

## 10. Clean Target Creation

`wng_target_rehearsal` was built by the full chain: **607 migrations in 89 s, 310 tables.**

Present:
- `jobs`, `job_batches`, `failed_jobs`;
- Finance (`cost_lines`, `journal_entries`, `payments`, `project_labour_actuals`, …);
- HR (`employees`, `departments`, `leave_requests`, …);
- Projects (`projects`, `project_enquiries`, `enquiry_tasks`, `task_budget_data`, …).

It started with 0 operational rows. `migration:target-readiness` flagged only the expected cron and storage-link items.

The target was rebuilt from scratch three times during the rehearsal, once after each fix batch, so the final evidence comes from a clean end-to-end run.

## 11. Dry-Run Results

| Check | Result |
|---|---|
| Schema gate (strict default) | **Refused**, correctly: 120 narrowings + 1 widening, and **no incompatible type** |
| Narrowings | 115 × `varchar(255)`→`191`, 2 × `255`→`125`, `190`→`100`, `160`→`100`, and the `task_issues.issue_type` enum. **Every value fits**: tightest `budget_additions.title` 173/191 and `project_enquiries.venue` 164/191; enum 0 rows outside |
| Acceptance | `--accept-verified-narrowing` **for this disposable rehearsal only**. Cutover needs sign-off (§24) |
| Load order | 228 tables; cycles `departments ↔ employees` and `design_handoffs ↔ design_items ↔ print_jobs` |
| Plan modes | 220 import, 8 replace-seeded, 1 import-mapped, 29 skip, 45 exclude, 10 decision-pending. **90,892 rows** to load |
| Preconditions | All clean; no "execution would refuse" |
| Held back, with counts | Excluded: `payments` 1,554, `petty_cash_ledger_entries` 1,628, `petty_cash_activity_logs` 480, `enquiry_payments` 160, `petty_cash_top_ups` 74, `petty_cash_requisitions` 22 (+49 items), `payroll_ledgers` 2, `payroll_runs` 1, `salary_advance_requests` 1, `petty_cash_balances` 1. Pending: `chart_of_accounts` 123 (D3), D2 tables 0. Skipped: `jobs` 10,941, `personal_access_tokens` 6,499, `user_device_tokens` 54, `permissions` 209, `role_has_permissions` 588 (regenerated) |

## 12. Import Results

The final clean run of `migration:import-source --execute`:
- **228 tables, 90,892 rows, 406 s**;
- **VALIDATION PASS**: 0 mismatched tables, 0 orphans introduced, exclusions PASS;
- then `--only=model_has_permissions`: the source has **0** direct user grants.

## 13. ID Preservation

**All 228 loaded tables have identical staging/target key ranges and checksums.** Examples:

| Table | Key range (staging = target) |
|---|---|
| projects | 1–747 (737 rows) |
| project_enquiries | 8–1558 |
| task_budget_data | 3–674 |
| employees | 4–102 |
| users | 2–78 |
| clients | 6–270 |

No remapping was used anywhere.

## 14. Project Reconciliation

`migration:evidence projects` was run on the transformed copy and on the target. The two match exactly on totals, coverage, every relationship check and status distribution.

| Measure | Value (identical both sides) |
|---|---|
| Projects / enquiries / clients | 737 / 1,387 / 258 |
| Tasks | 17,009 |
| Budgets | 658 |
| Quotes | 576 quote records, 247 versions, 393 approvals |
| Teams | 496 team tasks, 1,290 members |
| Deliverables | 2,345 |
| Elements / element materials | 1,715 / 10,346 |
| Budget additions / versions | 10,089 / 3 |
| Coverage | Every enquiry has a client and tasks; 1,364 have a Project Officer; 658 have budget data, 575 quote data, 756 deliverables, 722 elements |
| Carried-over breaks | Project Officer → deleted user 13; `quote_approvals` 10 + 10 (§6) |

## 15. Employee Reconciliation

Identical on both sides:
- 93 employees (active 74, on-leave 11, terminated 5, inactive 3), 13 departments, 65 users;
- **every employee has a department**; 38 have a manager; 61 are linked to one user each;
- 32 have salary history (existence only; **no salary value was read into any report**);
- 54 leave requests, 5 overtime entries, 35 historical technical-labour rows.

**No broken employee relationship.**

## 16. Authentication

| Check | Result |
|---|---|
| Users | 65 / 65; ids preserved |
| Password hashes | **Unchanged**, compared by per-user digest and never printed |
| Employee↔user links | Identical |
| Role assignments | Identical per role |
| Permissions | Regenerated from the matrix (after defect D-3's fix): **complete**. `permissions:sync --dry-run`: already in sync |
| Grant gap vs source | **0 current-permission grants lost.** 4 obsolete `delete_disbursement` grants correctly not carried |

## 17. Finance Exclusions

Proven, with **0 rows copied** for any excluded or pending table:

| DATA-1 exclusion | Result |
|---|---|
| Old petty-cash history | `payments` (1,554), ledger entries, top-ups, requisitions and items, activity, balance, allocations: excluded |
| `enquiry_payments` (160) | Excluded |
| Payroll run / ledger (1 / 2) | Excluded |
| Salary advance (1) | Excluded |
| Development CostLines / journals | None in the source; excluded by plan |

**No redesigned Finance history was created from them.**

## 18. Project Budget Authority

On real imported budgets, `ProjectBudgetAuthority` gives:

| State | Enquiries |
|---|---|
| finalized | **497** |
| in_progress | 161 |
| none | 704 |

The class **agrees with the SQL rule** (latest budget task per enquiry, `completed`, with data), and 497 equals the staging figure. `task_budget_data` is never on a non-budget task, and none is orphaned. No `approved` status was created or used.

## 19. W7 Labour

| Stage | Real-data result |
|---|---|
| Budget labour allocation | 289 finalized budgets carry **741** labour lines (740 with `unitRate`); the W7 service reads all 741 |
| After D5 regeneration | **81** open projects have **194** recordable lines |
| → Employee Record | Labour record for real **employee #4** on real **enquiry #1469** (ENQ-09-2026-091) — **PASS** |
| → Labour record | Created by real assigned Project Manager **#33** — **PASS** |
| → Project Officer verify | Same user, holding `finance.labour.po_verify` — **PASS** |
| → Finance verify (Accounts #15) | **BLOCKED:D3.** `Expense code 'DL-CAS-001' does not exist or is not active` (it needs chart account 1212 WIP–Direct Labour) |
| → Actual CostLine | **BLOCKED:D3** (depends on the step above) |
| Technical labour | The labour record links to the Employee Record only. `project_labour_actuals` has no technical-labour column. The 35 technical-labour rows are history only |

## 20. Planned CostLines

`migration:regenerate planned-cost-lines` (D5):
- **299 eligible budgets of 658**; 359 historical budgets left alone;
- **3,877 planned lines on 270 open enquiries**; 1,101 zero-priced placeholder rows skipped by design;
- **0 lines on completed, closed or cancelled** enquiries or projects;
- a second run changed nothing (**0 duplicates**);
- no journals, and no non-planned lines.

## 21. Queue Rehearsal

**Setup:** `QUEUE_CONNECTION=database` in the rehearsal checkout only. The drain used the production shape: `queue:work database --queue=stores-finance,default --stop-when-empty --max-time=55 --timeout=50 --tries=3`.

| Path | Result |
|---|---|
| R1 Project Budget projection (real budget save) | **PASS**: 2 jobs queued → drained, 0 failed; planned lines unchanged (idempotent) |
| R2 PO commitment | **PASS**: 1 commitment line after the drain |
| R3 GRN accrual | Job executes; **BLOCKED:D3** (no posting rule without chart accounts) |
| R4 Petty-cash commitment | **PASS** |
| R5 Petty-cash actual cost | **BLOCKED:D3**: the disbursement is refused before any job (no active job-allowed expense code; no paying account mapped to a GL account) |
| R6a Commitment release | **PASS** |
| R6b Void reversal | **BLOCKED:D3**: no disbursement can exist to void |

## 22. W1–W7 Smoke Results

The suite is `tests/Rehearsal/RealDataRehearsalSmokeTest.php` (`phpunit -c phpunit.rehearsal.xml`). It drives the real API with real imported users (Super Admin #32/#38, Accounts #15, Project Manager #33) against real enquiry #1469. It recorded **0 FAIL**. Suite status: OK.

| Workflow | PASS | BLOCKED:D3 | Result |
|---|---|---|---|
| **W1** Receivables | Draft for the real client project; **preparer cannot check own invoice**; another holder checks | Issue: no postable AR 1100 | **PARTIAL (D3)** |
| **W2** Procurement→Payment | Requisition, approval, PO, commitment, GRN, Stores confirmation against a real material, bill recorded | GRN accrual journal; bill verify (AP 2150); payment | **PARTIAL (D3)** |
| **W3** Expenses | Project requisition, approval, commitment (queue), **requester cannot edit an approved requisition**, reviewer edit → pending, commitment released (queue) | Disburse / surrender / reconcile: no active job-allowed code | **PARTIAL (D3)** |
| **W4** Payment Vouchers | — | No eligible liability can exist without coded spend | **BLOCKED (D3)** |
| **W5** Petty Cash | Top-up (test amount; the real float is D7), cash count with explained variance, custody handover confirmed | Project disbursement (no job code); overhead disbursement (paying account unmapped to GL) | **PARTIAL (D3)**, plus R-2 |
| **W6** Project Costing | Planned lines present; labour lines, closure-check and labour list read by Accounts; **financial close**; recording on a closed project blocked; reopen by Super Admin | — | **PASS** |
| **W7** Labour | Record, PO verify, Employee-only link | Finance verify, actual CostLine | **PARTIAL (D3)**, plus R-1 |

**Finance readiness** on the target reports `ready: false`, "Finance setup needs attention before live posting". That is the D3 state, read as Super Admin because of R-3.

## 23. Regression Results

| Gate | Result |
|---|---|
| Backend full suite (private copy of the test DB; includes the 35 migration-tooling tests) | **1,479 tests, 0 real failures**: 1,474 passed (10,145 assertions, 517 s); the 5 below fail there only because of their own guard |
| `W7LabourConcurrencyTest` | Refuses any database but `db_test` (it commits data); run on `db_test`: **5/5 passed** |
| Frontend unit suite | **27 files, 169 tests, all passed** |
| ENG-1 type-check | **256 errors = baseline, 0 new** |
| Frontend production build | **exit 0**, 1,891 modules |
| Real-data smoke suite | OK (0 FAIL; D3-blocked steps recorded) |

The frontend repository was not changed in this task (`659cd20`).

## 24. Open WNG Decisions

| # | Decision | Why it matters now |
|---|---|---|
| **D3** | **Chart of accounts.** Either the source chart (120 accounts, plus the draft `finance_accounts.map` awaiting Finance sign-off) or the reference chart | **Gates every posting and every coded expense.** It is the reason for every BLOCKED result |
| D2 | PO/GRN/bills import | The source has 0 rows; the decision withholds nothing |
| D4 / R-1…R-4 | Project Officer W7 rights; petty-cash custodian role; Accounts reports access; dangling assignments of deleted users | Roles are preserved; only these grants are open |
| D5 (Finance) | Financial closure of the 53 completed and 1 cancelled projects (and the completed or cancelled enquiries) | Regeneration scope is done; closure is not |
| D6 | Direct-labour departments (13, all unclassified) | Configured in the UI at cutover |
| D7 | Opening petty-cash float and GL opening balances | Accountant input; the rehearsal float was a test amount |
| D8 | Cutover | Deferred |
| Sign-off | Accept the 120 verified narrowings; approve the 24-relationship orphan list; provide the **storage archive** (1,920 rows reference files) | Needed before cutover |

## 25. Migration Defects Found

| # | Defect | Severity | Status |
|---|---|---|---|
| **D-1** | The unenforced reference `production_elements.material_id` was configured against `library_materials`. The migration declares it a string "reference to materials task item", i.e. `element_materials`. It produced **743 orphans, 592 of them false** | Tooling / MEDIUM | **Fixed** (config); 151 genuine remain |
| **D-2** | A source table without a primary key (`app_notifications`) was fingerprinted by different keys on each side, giving a **false mismatch** although data and checksum were identical | Tooling / MEDIUM | **Fixed**: one comparison key (target PK, else source PK, else `id`) for both sides, in validation and `--resume`. PK differences are reported as gate notes |
| **D-3** | **W6/W7 grants lost on a clean target.** The W6/W7 permission migrations grant only to roles that already exist, and on a fresh chain roles don't exist yet. `RolePermissions` did not declare those grants, so regeneration left **no role able to record, verify or view labour**, and Accounts without costing close, portfolio, allocate or transfer (23 grants) | **HIGH** — W7 unusable after cutover | **Fixed**: the matrix now declares exactly the migration-decided grants. A new `grant_gap` check in the authentication evidence fails on any lost current grant. A test covers both |
| D-4 | W7 Finance verify returns **HTTP 500** for a `CostValidationException` (inactive expense code) instead of a 4xx | LOW — product, not migration | Open: map `CostValidationException` to 422 |

**Observations (not migration defects):**
- the live source queue has 10,941 unprocessed jobs since 2026-07-28 (push, mail, `ProjectActivated`);
- live code runs ahead of its schema (10 unrun August migrations);
- 419 active materials need setup corrections (`stores:readiness`).

## 26. Required Fixes

1. **Done in this task:** D-1, D-2 and D-3, with tests. The migration-tooling suite has 35 tests; the new ones cover the matrix grants and the grant gap.
2. **Recommended, small:** D-4 (a `CostValidationException` → 422 mapping).
3. **Decision-dependent, not code:** D3, then the account map or chart import, then expense-code activation. `ExpenseCodeSeeder` activates a code only when its GL account resolves to a postable account.
4. **Live source (for WNG operations, outside this migration):** its queue has not been drained since July, so notifications are not being delivered.

## 27. Cutover Readiness

**Not ready. Cutover was not attempted.** The migration mechanics are proven on real data: preservation, IDs, authentication, exclusions, budget authority, D5 and the queue. What remains:
- D3, so Finance can post;
- the R-1/R-2/R-3 role grants;
- narrowing and orphan sign-off;
- the storage archive and file verification;
- D7 and D8;
- a fresh cutover snapshot (the source is live and moved from 731 to 737 projects today).

The target deployment gap (Report 50/51) is also still unexplained.

## 28. Final Verdict

### PARTIAL — REHEARSAL BLOCKED BY WNG DECISION

**Why PARTIAL:**
- Every migration defect the real data exposed was corrected and re-proven on a clean end-to-end run.
- The preservation, authentication, exclusion, budget-authority, D5 and queue results pass.
- The W1–W7 smoke test has no failure.
- But **D3 blocks the Finance posting half of W1, W2, W3, W4, W5 and W7**. The rehearsal cannot pass those paths without a chart, and choosing one is not ours to do.

**Production cutover is not complete and was not attempted.**

## 29. Exact Next Action

1. **Finance or the accountant decides D3.** Either keep WNG's 120-account source chart and approve the `finance_accounts.map` draft (codes 1100, 1212, 2150, the paying accounts and the rest), or adopt the reference chart.
2. **WNG answers R-1, R-2 and R-3.** Signs off the narrowings and the orphan list. Provides the storage archive.
3. **Engineering then re-runs 51A** on the same snapshot, with D3 applied: `chart_of_accounts` out of `decision-pending` or the map configured. Then Finance reference and expense-code activation, the pipeline, `migration:verify-files`, and `phpunit -c phpunit.rehearsal.xml`. **The target is PASS when the smoke test shows no BLOCKED:D3 step.**
