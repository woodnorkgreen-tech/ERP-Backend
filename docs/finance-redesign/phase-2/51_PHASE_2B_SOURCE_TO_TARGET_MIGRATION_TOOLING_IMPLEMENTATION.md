# 51 — Phase 2B: Source-to-Target Migration Tooling Implementation

**Date:** 2026-09-28
**Inputs:** Report 50 (strategy, verdict READY TO BUILD); WNG decisions D1–D8 (recorded in the Decision Register as MIG-D1…D8); branch `finance/critical-stabilization-fixes`.

**Boundary:**
- Offline and repository implementation only.
- **No connection** to `woodnork_erpsystem` or `woodnork_erp`.
- No live data imported, no live target initialised, no reset, no worker or cron, no deploy, no cutover, no W8.
- Every database used here was a local scratch database, or the local development database read in a read-only session.

**Deliverables:**
- `migration:*` commands and their support classes;
- config `config/source_migration.php`;
- the reviewed plan `database/source-migration/plan.json`;
- 34 automated tests;
- the host diagnostic `deploy/diagnose-target-deploy.sh`;
- the runbook **`51A_SOURCE_COPY_REHEARSAL_RUNBOOK.md`**;
- the Decision Register update.

**Commit:** `bec066e` (tooling). This report and the related documents are committed separately.

**Verdict:** **MIGRATION TOOLING READY FOR SOURCE-COPY REHEARSAL** (§18).

---

## 1. Implementation Summary

- **Two stages, as in Report 50 §21**, with the boundary enforced in code:
  - **Stage 1 — Transform** (`migration:stage1`). It is the only command that writes to `source_staging`: a restored **copy** of the source, never the live source or the target. First it records the two renamed asset migrations, and only after their schema markers are proven. Then it runs this codebase's pending migrations on the copy, so every backfill and seed runs on real rows.
  - **Stage 2 — Load** (`migration:import-source`). It reads the transformed copy through a **read-only session** and loads the reviewed plan's tables into the application's database, which the clean 607-migration chain has built. **Original primary keys are preserved.**
- **Reads before writes.** Evidence, verification and readiness commands are read-only. Every writing command is a dry run unless given `--execute` **and** `--confirm=<database name>`, plus `--cutover` for the live target.
- **A local dress rehearsal proved the tooling on production-shaped data (§14).** It loaded 228 tables into a scratch target built by the chain, with every table reconciled by count, key range and checksum. Along the way it found real drift and real orphans, which the tooling now classifies and reports.

## 2. Commands Created

| Command | Writes? | Purpose |
|---|---|---|
| `migration:plan [--generate --force]` | the plan file only | Generate the plan from `config/source_migration.php` (Report 50A rules plus D1–D8); validate it |
| `migration:stage1 [--execute --confirm=<staging db>]` | the **staging copy** only | Stage 1: marker-verified ledger reconciliation, then this codebase's migrations on the copy |
| `migration:import-source [--execute --confirm=<target db>] [--cutover] [--only=*] [--resume] [--allow-orphans=*] [--accept-verified-narrowing] [--validate]` | target only | Stage 2 load; dry run by default |
| `migration:evidence {projects\|employees\|d2\|roles\|budget-authority\|w7\|project-status\|exclusions\|all} [--connection=]` | no | Evidence and preservation reports; `roles` writes `source_role_mapping_report` |
| `migration:verify-files [--storage-path=] [--dry-run] [--connection=]` | no | Checks that file references in preserved tables exist in a storage tree |
| `migration:verify-overtime-chain [--target-only]` | no | Recomputes the overtime hash chain on staging and target, and reports any break the migration introduced |
| `migration:regenerate {permissions\|reference\|planned-cost-lines\|all} [--execute --confirm=] [--cutover]` | target only | Regeneration hooks (§12) |
| `migration:target-readiness` | no | Target DB identity, derived privileges, migration ledger, queue tables, `QUEUE_CONNECTION`, storage link, cron (§16) |

**Code layout:**
- `app/Support/SourceMigration/`: `SchemaInspector`, `ConnectionGuard`, `MigrationPlan`, `PlanGenerator`, `SchemaGate`, `ColumnDrift`, `ImportOrder`, `OrphanScanner`, `TableLoader`, `TableFingerprint`, `LoadValidator`, `EvidenceReports`, `FileReferenceVerifier`, `OvertimeChainVerifier`, `ReportWriter`, `MigrationRefused`.
- `app/Console/Commands/SourceMigration/`: the commands, auto-discovered.

**Reports** go to `storage/app/source-migration/<run-id>/*.json|.md`. They contain counts, IDs, key ranges and digests, never row contents, passwords or salaries.

## 3. Plan Format

`database/source-migration/plan.json` has two parts:
- `meta`: the decisions D1–D8 and the review status;
- `tables`: `{table: {mode, reason, group[, natural_key][, decision]}}`, with **exactly one mode per table**.

| Mode | Tables | Meaning |
|---|---|---|
| `import` | 220 | Copy every row with original IDs (DATA-1 group: 67; D1 group: 153) |
| `replace-seeded` | 8 | Target migrations seed these; the staging rows (source rows plus the same seeds) replace them, keyed by natural key |
| `import-mapped` | 1 | `model_has_permissions`, re-keyed by permission **name**, after permissions are regenerated |
| `skip` | 29 | Transient (sessions, tokens, cache, jobs, ledger); regenerated authority (`permissions`, `role_has_permissions`); target-only reference masters; 3 legacy `*_task_contexts` tables |
| `exclude` | 45 | DATA-1 Finance exclusions (Q1 petty cash including `payments`, formerly `petty_cash_disbursements`; Q2 `enquiry_payments`/receivables; Q3 payroll and salary advance; cost lines; journals; W6/W7 records; vouchers; Finance statements) |
| `decision-pending` | 10 | D2 (POs, items, amendments, corrections, GRNs, items, inspections, bills, bill payments) and D3 (`chart_of_accounts`) |

The plan has 313 tables: the clean chain's 310 plus 3 legacy tables a source database may still carry.

**Validation (`MigrationPlan::problems()`)** refuses when:
- a mode is invalid, or a table has no reason;
- a `replace-seeded` table has no natural key, or an `import-mapped` table has no mapper;
- **any `FinanceResetBoundary::RESET_ORDER` table would load.** The only exception is a `CONFIRM_ON_PRODUCTION` (D2) table carrying a recorded `decision` reference.

**Drift detectors (tests):**
- The committed plan must match the rules.
- **Every table the migration chain seeds must be classified as something other than `import`.** A future migration that seeds a new table fails the suite until it is classified.

## 4. Safety Guards

The command refuses when:

| Guard | How it is enforced |
|---|---|
| Source not explicitly configured | `source_staging` has **no defaults**. Host, database and username must be set (`SOURCE_STAGING_DB_*`) |
| Source is the application's connection | Refused by connection name |
| Source and target are the same database | Refused by configuration (same host, port and database), **and** by server identity (`@@hostname`, `@@port`, `DATABASE()`) once connected |
| Source is the live source ERP | `woodnork_erpsystem` is refused by name, both configured and resolved |
| Accidental source mutation | `SET SESSION TRANSACTION READ ONLY` on the source before anything reads it. Any write fails at the server (tested) |
| Timestamp shift | The two connections' session time zones must be equal, because `TIMESTAMP` values travel as strings |
| Target schema incomplete | Every migration this codebase loads must have run on the target, with none pending |
| Stage 1 not done | The staging ledger must record every target migration |
| Source schema invalid | The schema gate (§5) |
| Plan invalid | §3 |
| Execution not confirmed | `--execute` plus `--confirm=<exact target db name>`. A **live target** (`woodnork_erp`) also requires `--cutover` (D8) |
| Target not empty | An `import` table must be empty on the target. A `replace-seeded` table may hold only rows that staging also has |
| Orphans | Any orphan in a loaded table blocks execution unless its `table.column` is explicitly allow-listed (§6) |

## 5. Schema Gate

`SchemaGate` checks, and fails closed on:
1. target migration completeness;
2. Stage 1 completeness;
3. every staging table classified by the plan;
4. every loaded table present on both sides;
5. column parity for every loaded table.

**Missing or extra columns always refuse.** A column whose definition differs is classified by `ColumnDrift`:

| Class | Example | Outcome |
|---|---|---|
| equivalent | `uuid` ≡ `char(36)`; integer display widths; `json` ≡ `longtext` | Accepted, reported |
| widening | `varchar(100)`→`varchar(191)`; enum values added; NOT NULL→NULL; `int`→`bigint` | Accepted, reported |
| narrowing, data fits | `varchar(255)`→`varchar(191)` where the longest stored value is 92 | **Refused by default.** Accepted only with `--accept-verified-narrowing`, after review. The evidence (longest value, NULL rows, enum values in use) is in `schema_gate.json` |
| narrowing, data does not fit | A 200-character value into `varchar(191)` | **Always refused**, with evidence. Nothing is truncated |
| incompatible | `bigint`→`int`; `date`→`varchar` | Always refused |

**No schema is ever altered to fit.** This classification exists because of the dress rehearsal (§14). Databases created before the 191-character default string length carry `varchar(255)` where the chain now builds `varchar(191)`: 120 such columns in the local production-shaped copy. The live source is likely to carry the same drift.

## 6. Orphan Scan

`OrphanScanner` covers:
- **every FK the target schema declares** on a loaded table;
- **the unenforced integer references** listed in config: `quote_approvals.task_id`/`enquiry_id`, `requisition_items.project_enquiry_id`, `hr_on/offboarding_cases.employee_id`, `production_elements.material_id`, and others;
- the polymorphic `model_has_roles`/`model_has_permissions` → `users`.

**Each finding reports:**
- table, column and parent;
- whether it is FK-enforced;
- the parent's plan mode;
- the orphan count;
- up to 5 sample child keys (full composite keys for pivot tables);
- up to 5 missing parent ids;
- severity:

| Severity | When |
|---|---|
| HIGH | The parent is excluded or pending; or the parent is a core DATA-1 table; or the child is DATA-1 protected |
| MEDIUM | The parent is loaded |
| LOW | Otherwise |

**It never drops a row.** Execution is blocked until each `table.column` is either resolved or allow-listed (`--allow-orphans`, WNG-approved). Allow-listed orphans are loaded as-is. The post-load scan must show **no orphan the staging copy did not already have**.

## 7. Import Order and FK Cycles

`ImportOrder`:
1. Groups tables into strongly connected components (Tarjan).
2. Orders the components topologically (Kahn): parents before children along every FK between loaded tables.
3. Uses the table name only as a tie-break between independent tables. The order is identical on every run (tested with reversed input).

**Cycles are reported explicitly.** The dress rehearsal found `departments ↔ employees` and `design_handoffs ↔ design_items ↔ print_jobs`. Self-references are listed separately.

**The load session sets:**
- `foreign_key_checks = 0` for the session only, restored afterwards, so cycle members load without insertion tricks;
- `NO_AUTO_VALUE_ON_ZERO`, so an explicit id 0 is kept, not renumbered.

Integrity is then proved by the post-load orphan scan. **Relationships are never reconstructed from insertion order.** Every row keeps its original id and every reference its original value.

**Loading and repeatability:**
- One transaction per table; a failure rolls that table back and stops the run.
- `--resume` skips tables whose target rows already equal staging (same fingerprint) and refuses partial ones.
- Recovery otherwise is to rebuild the target from the chain (about 2 minutes).

## 8. ID Policy

- **Original primary keys are preserved for every loaded table.** No remapping exists in the tooling.
- The only remap-like case is the `import-mapped` permission grants. There the *permission* id is re-keyed by permission name, because permissions are regenerated. The user id and the grant itself are preserved.
- Any other need to remap would stop the entity (the loader refuses non-empty or mismatched tables) and be reported; it would never be introduced silently.
- **Tested:** gapped ids (users 5/42/77, employees 7/12/900) survive exactly, and the next inserted id continues above the maximum.

## 9. Seeded-Table Handling

Eight tables are seeded by the target's own migrations on an empty database:

| Table | Natural key |
|---|---|
| `leave_types` | code |
| `units_of_measure` | code |
| `material_item_types` | code |
| `material_categories` | (name, parent's name) |
| `hr_action_types` | code |
| `attendance_work_schedules` | name |
| `production_defect_codes` | code |
| `production_root_cause_codes` | code |

- **Stage 1 applies the same seeds on top of the source rows**, so the staging copy holds source rows plus target seeds with consistent ids.
- **`replace-seeded` deletes the target's seeded rows and loads staging's**, but **only if every target seed row exists on staging by natural key.** Otherwise it refuses, and never overwrites master data it cannot account for.
- **Tested:** no duplicate codes; staging ids exactly.

**Permissions and D3:**
- **Permissions** are `skip`: regenerated by `RoleAndPermissionSeeder` plus `permissions:sync`. **Obsolete permission definitions are never imported.**
- **`chart_of_accounts`** stays `decision-pending` (D3). `migration:regenerate reference` forces the reference-chart seeding **off**, and a test proves the chart is untouched even when the flag is on.

## 10. D2 Evidence

`migration:evidence d2` is read-only. For each of `purchase_orders`, `purchase_order_items`, `goods_receipt_notes`, `goods_receipt_note_items`, `bills` and `bill_payments` it reports:
- row count, and the document-date and `created_at` ranges;
- status distribution;
- supplier linkage (linked and broken);
- project, enquiry and header linkage (linked and broken), plus PO → requisition → project;
- zero or negative amounts;
- duplicate document numbers;
- **objective indicators only:** keyword hits (`test`, `dummy`, `sample`, `demo`, …) with sample ids and the columns checked; rows in same-second bursts of 5 or more (the bulk-import signature); distinct creating users.

The report states: *"D2 PENDING — this is evidence, not a classification."* **D2 tables are never loaded while pending (tested).**

## 11. Role Mapping

`migration:evidence roles` writes **`source_role_mapping_report`**. For every source role it gives:
- users assigned;
- source direct permission grants;
- code references (quoted occurrences in `app/`);
- a classification:

| Classification | Meaning |
|---|---|
| `exact-target-equivalent` | In the `RolePermissions` matrix, or `Super Admin` (Gate bypass) |
| `renamed-target-equivalent` | Matches a matrix role after normalisation, e.g. `project manager` → `Project Manager`, with the proposed mapping |
| `requires-wng-mapping` | Has users or code references, e.g. `Procurement Officer` |
| `obsolete-retire-candidate` | No users and no code references; **WNG approval still required** |

**Every role survives the load with its users (tested).** A non-matrix role receives **no matrix permissions until WNG maps it.** The report says so, per role.

## 12. File Verification and Regeneration

**`migration:verify-files`:**
- checks every configured file column in preserved tables, **expanding JSON arrays**, against `<storage>/app/public`, `<storage>/app/private` and `<storage>/app`;
- reports present, missing, absolute URLs and inline `data:` values, by table/column and by module, with missing samples;
- `--dry-run` lists columns and row counts without touching the filesystem.

The dress rehearsal corrected the column list: the signature columns hold typed names, and `checklist_project_budget_file` is a boolean. The rehearsal compares missing-on-source-archive with missing-after-copy.

**`migration:regenerate`:**

| Step | What it does |
|---|---|
| `permissions` | `RoleAndPermissionSeeder` (additive) + `permissions:sync` |
| `reference` | `FinanceReferenceSeeder` with the chart forced off (D3). It deliberately does **not** run the source-authoritative seeders (departments, teams, workstations, material categories, HR action types) |
| `planned-cost-lines` | `BudgetProjector` for **D5-eligible budgets only**: the enquiry is not completed, closed or cancelled, and neither is its project if one exists |

Opening balances and float are **not** generated (D7).

## 13. Validation (Projects, Employees, Authentication, Budget Authority, W7, Exclusions, Overtime)

| Check | What it verifies |
|---|---|
| **Post-load reconciliation** (`LoadValidator`) | For every loaded table: staging vs target count, min/max key and a deterministic streamed SHA-256 checksum. Plus: orphans introduced by the load; headline counts against the operator-observed 2026-09-28 references (**informational: the source copy is the authority**, and any difference must be explained); the exclusion proof |
| **Projects** | Totals; project and enquiry status distributions; coverage (client, Project Officer, tasks, budget data, quote data, team, deliverables, elements); 19 relationship checks, broken ones flagged |
| **Employees** | Totals; status; coverage (department, manager, user link, multiple-user links, salary history *existence*, documents); 15 relationship checks. **No salary value appears in any report (tested)** |
| **Authentication** | User ids; password hashes compared by per-user digest and **never printed (tested)**; employee↔user links identical; role assignments identical; matrix permission coverage |
| **Budget authority** | The `ProjectBudgetAuthority` rule in SQL (latest budget task per enquiry, `completed`, with data) **and** through the real class on the target, which must agree. `'approved'` is not a criterion |
| **W7** | Finalized budgets with labour allocation; labour lines with `unitRate`; labour records link to `employee_id` and have **no technical-labour link**; departments' `labour_classification` left unclassified (D6); the **W7 service** reads every imported line |
| **Exclusions** | Every excluded, skipped or pending table: staging rows, target rows, and rows copied verbatim (must be 0). An explicit DATA-1 checklist covers old petty cash, `enquiry_payments`, payroll run/ledger, salary advance, cost lines and journals |
| **Overtime chain** | Recomputed per subject (employee, or technical labourer), ordered `occurred_at, id`, with `LedgerEntry::generateHash`. Nothing is re-hashed. Breaks on the target that staging lacks = introduced by the migration (tested by tampering) |

**A W7 dependency found by testing.** A budget labour line is **recordable** only when an active **planned CostLine** exists for it. After the load, W7 sees every line but can record none until `migration:regenerate planned-cost-lines` runs, and then only for D5-eligible open projects (tested: 2 lines recordable for the open project, 0 for the completed one). **The runbook orders regeneration before the W7 smoke tests.**

## 14. Tests and Local Dress Rehearsal

**Automated:** `tests/Feature/SourceMigration/SourceMigrationToolingTest.php` — **34 tests, 642 assertions, all passing.**

It runs against two scratch databases:
- the test database, built by the full migration chain;
- `db_srcmig_staging_test`, whose fixture tables are created from the target's own `SHOW CREATE TABLE`.

| Area | Tests cover |
|---|---|
| Guards | Dry run default; source not configured; same DB; live source name; read-only source session; typed confirmation; `--cutover` for a live target |
| Plan and schema | Invalid plan; schema drift (missing column); narrowing with data proof and acceptance; incomplete Stage 1 |
| Load | ID preservation; dependency order and cycles; seeded replacement and refusal; non-empty target refusal; `--resume` |
| Data rules | Excluded, transient and pending tables; orphans (reported, blocking, allow-listed, never dropped); mapped grants with unmapped reporting |
| Evidence | Role mapping (all 4 classes, no deletion); authentication (hashes unchanged, not printed); project and employee breaks; no salary exposure; D2 evidence and non-import |
| Compatibility | Budget authority and W7 (before and after regeneration); overtime chain and tamper detection; file verification |
| Regeneration | D5 regeneration scope; D3 chart protection |
| Integrity detectors | Generator classifies every table and catches unknown seeded tables; the committed plan matches the rules; configured columns exist; Stage 1 marker-verified reconciliation and refusal; target readiness (queue tables, no GRANT output) |

**Regression:** full backend suite, run on a private copy of the test database: **1,477 tests, 0 real failures.**
- 1,472 passed there.
- The 5 `W7LabourConcurrencyTest` tests refuse to run anywhere but `db_test` (they commit data, by design). Run on `db_test` they **pass (5/5)**.

**Local dress rehearsal** (production-shaped local development database as a *read-only* stand-in for the staging copy; scratch target built by the clean chain in 2 min 40 s; **not WNG's live data**):

| Step | Result |
|---|---|
| Schema gate | 124 drift columns: 120 narrowings, **all proven to fit** (e.g. longest `clients.email` 60 of 191), 3 equivalent (`uuid`), 1 widening (enum). Refused by default; passed with `--accept-verified-narrowing` |
| Orphan scan | **24 broken relationships**: 21 references to users that no longer exist (`created_by`, `assigned_by`, `project_officer_id`, …; the development database never enforced those FKs), 743 unenforced `production_elements.material_id`, and 8 `quote_approvals` pointing at missing tasks and enquiries. All blocked until allow-listed |
| Load | **228 tables in 4 min 13 s** (while the test suite ran concurrently); cycles `departments ↔ employees` and `design_handoffs ↔ design_items ↔ print_jobs` |
| Validation | **PASS**: 228 tables reconciled, 0 mismatches, 0 orphans introduced, exclusions PASS |
| Budget authority (target) | 382 finalized, 97 in progress, 518 none. Class agrees with SQL |
| W7 (target) | 580 labour lines read by the W7 service. After regeneration, 71 open projects have 193 recordable lines. 13 departments unclassified (D6) |
| Regeneration | Permissions in sync; chart untouched; **D5: 174 eligible budgets of 479**, 2,265 planned lines projected, 305 historical budgets left alone |
| Files | Resolution rules confirmed. Column list corrected (−343 false references). 17 absolute URLs found. Production uploads are absent on the development machine, as expected |

The dress-rehearsal worktree and scratch target were removed afterwards. The development database was only read: its ledger was unchanged at 620 rows.

## 15. Rehearsal Runbook

See **`51A_SOURCE_COPY_REHEARSAL_RUNBOOK.md`**. It is executable, step by step, and covers all 18 required steps:
1. dump;
2. storage archive;
3. isolated `wng_source_copy`/`wng_staging`/`wng_rehearsal_target`, with the wiring proved;
4. schema and ledger verification;
5. baseline orphan evidence;
6. Stage 1;
7. clean target;
8. full migrations and readiness;
9. dry run and report review;
10. D2/D3/D4, orphan and narrowing decisions;
11. approved execute;
12. permissions and mapped grants;
13. reference and D5 planned lines;
14. files;
15. rehearsal queue (disable the DDEV always-on worker);
16. W1–W7 and queue smoke tests, logins, interfaces;
17. reconciliation;
18. the PASS/FAIL table.

## 16. Target Deployment Gap

Report 50 found that `deploy.yml` runs `migrate --force` on `~/erp-backend-master` on every master push, yet `woodnork_erp` has zero tables. Both new diagnostics are read-only:

- **`php artisan migration:target-readiness`**, run in the checkout, reports:
  - base path, environment, `.env` presence, config-cache state, PHP binary and version;
  - configured versus resolved database and host;
  - **derived privileges** (SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/DROP/REFERENCES). Raw GRANT lines are never printed, because they may carry password hashes;
  - tables present, ledger, migration files, ran and pending (it states when the database is empty);
  - queue tables, `QUEUE_CONNECTION`, the failed-jobs driver, and the waiting and failed counts;
  - the `recommended_cron` with this checkout's path and PHP binary;
  - whether a cron line exists, and the storage link.
- **`deploy/diagnose-target-deploy.sh <checkout>`** is the host-level checklist. It covers:
  - whether `cd` works;
  - branch and last commit, plus dirty-tree detection (a dirty tree can make `git pull` fail);
  - PHP path;
  - non-secret `.env` keys (APP_ENV, DB_CONNECTION/HOST/PORT/DATABASE, QUEUE_CONNECTION);
  - a **missing `.env` means `DB_CONNECTION` falls back to `pgsql`**, so migrate cannot reach MySQL;
  - cached config;
  - readiness output, crontab and storage link;
  - a pointer to the Actions log.

**Working hypothesis, stated as such:** appleboy/ssh-action v1.0.3 defaults to `script_stop: false`. The deploy step's exit status is therefore the last command's, so a failed `migrate` is masked when `stores:process-finance-postings` succeeds afterwards. The likely causes are wrong or absent credentials, or missing privileges, on `woodnork_erp`. **The cause is unverified until an operator runs the diagnostic** (§17).

**Queue drain (unchanged from Report 50 §18; not implemented):**

```cron
* * * * * cd <base_path> && flock -n /tmp/wng-erp-queue.lock <php> artisan queue:work database --queue=stores-finance,default --stop-when-empty --max-time=55 --timeout=50 --sleep=3 --tries=3 >> storage/logs/queue-cron.log 2>&1
```

| Setting | Value | Reason |
|---|---|---|
| Frequency | Every minute | |
| Overlap protection | `flock -n` | |
| `--max-time=55` | | Each run ends inside its minute |
| `--timeout=50` | | Below `retry_after` |
| `--tries=3` | | The listeners declare their own `$tries`/`$backoff` |
| Deploys | No `queue:restart` needed | Each run is a fresh process on the new code |
| Monitoring | `failed_jobs` via `queue:failed` and `migration:target-readiness`; `storage/logs/queue-cron.log` | |

Enable the cron only after the chain has created the queue tables.

## 17. Unresolved Decisions and Open Items

| Item | Status | Needed before |
|---|---|---|
| **D2** — PO/GRN/bills: genuine or test | **PENDING**: needs `migration:evidence d2` on the source copy | Rehearsal step 10 |
| **D3** — chart of accounts | **PENDING**: accountant. No GL opening balance until decided | Rehearsal PASS; any GL opening entry |
| **D4** — per-role mapping (e.g. `Procurement Officer` and any hand-made role) | **PENDING**: from `source_role_mapping_report` | Rehearsal step 10 |
| **D5** — financial closure of historical projects | **PENDING (Finance)**. Regeneration scope is confirmed | Cutover |
| **D7** — opening float and GL opening amounts | **PENDING**: cutover input | Cutover |
| **D8** — cutover | **DEFERRED** until the rehearsal passes | — |
| **Orphan disposition** (new, from the dress rehearsal) | Per `table.column`, WNG approves loading pre-existing orphans as-is. Most will be references to deleted users. Nothing is dropped or re-pointed without a decision | Rehearsal step 10 |
| **Narrowing acceptance** (new) | WNG and Engineering review `schema_gate.json`, then approve `--accept-verified-narrowing` | Rehearsal step 11 |
| **Absolute file URLs** (new) | Rewrite or leave: a WNG-approved decision if the source has them | Rehearsal step 14 |
| **Target deployment gap** | Operator runs `deploy/diagnose-target-deploy.sh` and reads the Actions log | Before any target initialisation on the host |
| **Engine and version parity** | Operator records `SELECT VERSION()` on the source | Rehearsal step 3 |

MIG-D1, D4 (policy), D5 (scope), D6 and D7 (policy) are recorded as **CONFIRMED**. D2 and D3 are **PENDING**, and D8 **DEFERRED**, in the Decision Register. Nothing pending is marked confirmed.

## 18. Final Verdict

### MIGRATION TOOLING READY FOR SOURCE-COPY REHEARSAL

**Why:**
- Every tool Report 50 §21 and this task specify is implemented.
- The guards fail closed.
- The plan is generated, reviewed, validated and drift-guarded.
- 34 dedicated tests pass, and the full suite shows no regression.
- A full-scale local dress rehearsal on production-shaped data loaded and reconciled 228 tables with zero mismatches, and exercised the drift, orphan and regeneration paths for real.

**The remaining items (§17) are decisions and operator facts that the rehearsal itself produces or requires.** None is a missing capability.

**Not done:** no live source connection; no live target initialisation; no live import; no Finance reset; no worker or cron; no deploy; no cutover; no W8; no Finance frontend redesign.

**Immediate next action:** an authorised operator runs **51A steps 1–2** (source dump and storage archive, read-only), records `SELECT VERSION()` and the headline counts, and runs `deploy/diagnose-target-deploy.sh` on the target checkout. Engineering then executes **51A steps 3–18** on the copies.
