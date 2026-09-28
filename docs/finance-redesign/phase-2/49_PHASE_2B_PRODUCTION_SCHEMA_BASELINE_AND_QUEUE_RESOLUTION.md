# 49 — Phase 2B: Production Schema Baseline & Queue Infrastructure Resolution

**Date:** 2026-09-28
**Inputs:**
- Report 48 and its Production Operator Verification addendum;
- Report 47 (DATA-1, `FinanceResetBoundary`);
- branch `finance/critical-stabilization-fixes`, and `origin/master` history;
- the local DDEV database and its snapshots (evidence only; never production).

**Boundary:**
- Repository analysis and release strategy only.
- No production connection. No production change.
- No `migrate` of any kind, no `queue:table`, no worker, no cron, no deploy, no reset, no merge, no W8.

**Deliverables:**
- this report;
- appendix **`49A_MIGRATION_INVENTORY.md`** (all 607 migrations);
- read-only tooling **`49_baseline_tools/`** (`classify_migrations.py`, `verify_markers.py`).

**Verdict:** **READY FOR PRODUCTION-COPY MIGRATION-BASELINE REHEARSAL** (§23). Step 0 in §15 is a hard gate.

---

## 1. Executive Summary

- **Case C is confirmed:** production runs `QUEUE_CONNECTION=database` with no worker and no cron.
- **Two deeper faults:**
  - The queue tables (`jobs`, `job_batches`, `failed_jobs`) **do not exist**.
  - Production has **no Laravel `migrations` table**.
- **This is deployment and infrastructure debt, not a W7 or Phase 2B defect.**
- **How production got here.** From 2026-05-14 to 2026-09-07 the deploy pipeline never ran migrations (§6). Production's schema was therefore built and maintained **outside Laravel's migration tracking**. The repository does not record how.
- **An unresolved anomaly.**
  - Since `0dc1197` (2026-09-07), every `master` push runs `php artisan migrate --force`.
  - Laravel's `migrate` **creates the `migrations` table before it runs anything** (`MigrateCommand::prepareDatabase`).
  - A ledgerless `woodnork_erp` therefore means `migrate` never ran against the database the operator inspected.
  - Either the deploy is not reaching that database, or it is not running at all. §6.3 lists four hypotheses.
  - This must be settled **before** the production copy is taken (§15 Step 0), or the rehearsal may use the wrong database.
- **Normal `migrate --force` is unsafe here (§8).** With no ledger, Laravel cannot tell the 584 historical migrations from the 23 genuinely new Phase 2B ones.
  - Today the first attempt would create an **empty** ledger and die on `create_users_table`.
  - Any partial or naive baseline would instead let it run destructive history. That includes a DROP-then-CREATE of the **preserved** `handover_surveys` table.
- **Migration inventory (§7, 49A):** 607 migrations load.

  | Class | Count | What it means |
  |---|---|---|
  | Likely present | 406 | In a July-2026 ledger of unconfirmed provenance |
  | Needs schema comparison | 178 | On `master`, added after that ledger; production state unknown |
  | Phase 2B new | 23 | Release branch only |

  Tags: 80 data migrations, 53 potentially destructive, 63 idempotent.
- **Recommended baseline (§10):** marker-verified reconciliation on the production copy.
  - Each migration is checked against the copy's actual schema, by machine where possible (526 of 607) and by hand for the rest (81).
  - Only verified migrations enter the ledger. The rest are genuinely pending or need reconciliation.
  - The tooling was self-tested against a fully migrated database: 0 false ABSENT.
  - In a negative control it flagged all 20 markable Phase 2B migrations, with 0 false flags.
- **Queue (§12, §13):**
  - The canonical queue migration already exists: `0001_01_01_000002_create_jobs_table` creates all three tables. **No duplicate is to be created.** The baseline will show it ABSENT, so it runs as a genuinely pending migration.
  - The correction stays a **cron drain** (`queue:work --stop-when-empty`), enabled only after that migration.
- **Historical behaviour (§14).** With `jobs` absent, every queued dispatch **threw** rather than queueing, assuming the code was current. Consequences:
  - PO approvals would roll back.
  - GRN, budget, materials, requisition and supplier-payment saves would return errors **after** committing.
  - Petty-cash payouts would have logged a critical error and proceeded.

  Production's data and logs can confirm or refute this, which also tests the §6.3 hypotheses.
- **Release (§21).** The sequence now starts with a **master-push freeze** and a **migration baseline**, before the DATA-1 reset and the Phase 2B migrations.

## 2. Operator Evidence

The checks were run by an authorised operator. They are recorded as supplied; this environment made no production connection.

| Check | Result |
|---|---|
| `php artisan config:show queue.default` | `database` |
| `queue:work` process | none |
| Queue or scheduler cron | none |
| Persistent worker service | none verified |
| `php artisan queue:failed` | failed: `woodnork_erp.failed_jobs` does not exist |
| Table inspection | `jobs`, `failed_jobs`, `job_batches`: **absent** |
| `php artisan migrate:status` | `ERROR Migration table not found.` |

## 3. Report 48 Case C Confirmation

- **CASE C CONFIRMED — ASYNC DATABASE DRIVER + NO WORKER.**
- Report 48's NOT PROVEN verdict stays as the historical record. The addendum in Report 48 records the current classification.
- **The queue release precondition FAILS.**
- **Backlog:** because `jobs` does not exist, **no Laravel database-queue backlog is stored**. This proves only that nothing can be replayed. It does **not** prove that no Finance effects were missed. §14 shows that, if the code was current, those effects were never recorded and each dispatch raised an error instead.

## 4. Missing Queue Tables

| Table | Created by | Guarded? | Also created elsewhere? |
|---|---|---|---|
| `jobs` | `database/migrations/0001_01_01_000002_create_jobs_table.php` | No (`Schema::create`) | No |
| `job_batches` | same file | No | No |
| `failed_jobs` | same file | No | No |

- The three tables share one migration.
- The config points to them through `config/queue.php`: `DB_QUEUE_TABLE` defaults to `jobs`, and the `failed` driver defaults to `database-uuids` on `failed_jobs`.
- **Missing queue tables are a symptom** of the missing migration history. They are not a separate defect.

## 5. Missing Migration Ledger

- `config/database.php:134` names the ledger `migrations`, with no table prefix on any connection. The operator's `migrate:status` error is therefore unambiguous: **no ledger exists in the database that checkout is configured for.**
- Consequences:
  - Laravel treats **every** one of the 607 loaded migrations as pending.
  - `migrate`, `migrate --force` and **`migrate --pretend`** would all create the ledger first, because `prepareDatabase()` runs `migrate:install` whenever the repository is missing, even under `--pretend`. None of them is a safe inspection tool on this database.
  - `migrate:status` is read-only, and is the only migration command that is safe to run.
- `FinanceResetBoundary::PROTECTED` already lists `migrations`, so the DATA-1 reset will never touch a ledger once one exists.

## 6. Production Deployment History

### 6.1 Repository evidence

| Date | Commit | Pipeline behaviour |
|---|---|---|
| 2026-05-14 | `9d76c66` | Workflow created. On push to master: `cd ~/erp-backend-master`, `git pull`, `composer install`, caches. **No migrate.** |
| 2026-05-14 | `c8f92a2` | Path changed to `~/public_html/erp-backend-master` |
| 2026-08-25/26 | `b5b13ef`, `47a34be` | Path moved back to `~/erp-backend-master` ("Point repo outside of public html"). `key:generate` added on every deploy |
| 2026-09-07 | `0dc1197` | **`php artisan migrate --force` added**, `key:generate` removed. The commit says deploys had "shipped code against whatever schema the database happened to have… failed at request time — on a supplier payment, on a bills list" |
| 2026-09-22 | `9853817`, `60491b1` | Worker unit added, then withdrawn (Report 48) |

Searches found:
- no production SQL dump;
- no schema file (`database/schema/` does not exist);
- no provisioning script;
- no document describing how `woodnork_erp` was created or has been kept in step with the code.

The `.sql` files in `storage/app/` are untracked, local-DDEV artifacts: a 2026-08-29 dump of the local `db`, and a 2026-08-18 local project reset.

### 6.2 What the evidence supports

- **From May to September 7 production was not migrated by the pipeline.** Each schema change that production has must have been applied by hand or by import. Report 46 also found Excel-import signatures in the data.
- **"Created before Laravel migration tracking", "manually provisioned" and "imported from SQL" are all consistent with this.** The repository cannot say which, and this report does not guess.
- **Local indicative evidence.**
  - The DDEV snapshot `after-database-import` (2026-07-27) contains a `migrations` table of about 419 entries, up to `2026_07_22_000001_create_leave_balance_adjustments_table`. That matches local ledger batches 1–74.
  - If that import was a production dump, production had a ledger in July. But the import's source, and whether `import-db` replaced or kept the local ledger, are **not recorded**.
  - Report 46 already described the local database as "production-shaped, unknown date". Its project pipeline was also reset locally on 2026-08-18.
  - **It is not evidence about production's current schema.**

### 6.3 The unresolved anomaly

- `migrate --force` on a database with no ledger does two things in order: it creates `migrations`, then attempts the first migration, `create_users_table`, which fails because `users` exists. **An empty ledger would remain.**
- `origin/master` has received pushes since 2026-09-07. The latest is `60491b1` on 2026-09-22, and a 2026-09-09 working note records a push containing a migration.
- The operator found **no** ledger at all. So at least one of these is true:

| # | Hypothesis | What it would mean |
|---|---|---|
| H1 | The deploy's checkout (`~/erp-backend-master`) points at a **different database** from the one inspected. Or the operator ran the checks in a different checkout, for example the older `~/public_html/erp-backend-master` | The inspected database may not be the live one. Or the live API is served from a checkout the pipeline does not update |
| H2 | The deploy script never runs artisan successfully, for example if `cd` fails or the job fails before the SSH step | Production code is **not** what `master` says. Every deploy since May is in doubt |
| H3 | `migrate:install` fails because the database user lacks `CREATE` | Nothing Laravel-driven can create the ledger **or** the queue tables. Affects §12 and §13 |
| H4 | The ledger was created and later dropped by hand | Least likely; no evidence |

**Discriminating checks:** §15 Step 0 and §22. They are all read-only, and all must be answered before the copy is taken.

## 7. Complete Migration Inventory

**Full per-migration classification:** `49A_MIGRATION_INVENTORY.md`, generated by `49_baseline_tools/classify_migrations.py`.

**Scope.** 607 migrations load from:
- `database/migrations`, and
- 11 module directories registered by `loadMigrationsFrom()`: ArchivalTask, Finance, HR, MaterialsLibrary, ProcurementStores, Design, UniversalTask, Production, Notifications, Assets, Printing.

Two paths are excluded:
- `app/Modules/Finance/PettyCash/Database/Migrations` is **not registered**. Its single file never runs.
- `Logistics` registers a directory that does not exist.

**Baseline classes** (mutually exclusive):

| Class | Count | Basis |
|---|---|---|
| SCHEMA ALREADY LIKELY PRESENT IN PRODUCTION | 406 | In the 2026-07-27 imported ledger. "Likely" holds only if that import was production, and only as of that date. **Verification is still required** |
| LEGACY MIGRATION REQUIRING SCHEMA COMPARISON | 178 | On `master`, dated 2026-06-29 → 2026-09-21, not in that ledger. Production state unknown, since the pipeline has had migrate only since 09-07 and §6.3 is unresolved |
| PHASE 2B NEW | 23 | `2026_09_22_000001` → `2026_09_28_000001`. On the release branch only |

**Tags** (any combination):

| Tag | Count | Split by class (likely present / needs comparison / Phase 2B) |
|---|---|---|
| DATA MIGRATION | 80 | 25 / 51 / 4 |
| POTENTIALLY DESTRUCTIVE | 53 | 38 / 15 / 0 |
| SAFE / IDEMPOTENT (guarded, non-destructive) | 63 | 51 / 17 / 0 |

**Touching DATA-1 preserved tables:** 174 likely present, 51 needing comparison, 2 Phase 2B (`project_enquiries` financial-closure columns, and payment terms).

**Highest-risk historical migrations if replayed:**

| Migration | Effect on replay | Preserved table? |
|---|---|---|
| `2025_11_27_062500_recreate_handover_surveys_table` | DROP then CREATE, **wipes rows** | **Yes** (`handover_surveys`) |
| `2025_11_28_094630_recreate_handover_surveys_with_json_structure` | DROP then CREATE, **wipes rows** | **Yes** |
| `2025_11_27_062000_recreate_teams_activity_logs_table` | DROP then CREATE | No |
| `2026_01_21_000002_create_daily_job_cards_table` | DROP then CREATE `job_cards` | No |
| `2024_01_21_000007_drop_quality_checks_table`, `2025_10_12_123300_drop_enquiries_table` | Drop tables | No |
| `2026_03_14_110233_drop_redundant_enquiry_columns`, `2026_05_29_000001_drop_hikvision_id_from_employees`, `2026_06_09_000000_remove_team_fields_from_logistics_tasks_table` | Drop columns | **Yes** |
| `2026_09_09_000001_unify_payment_architecture` | Renames `petty_cash_disbursements`→`payments`, raw DROP, column renames and drops | Reset set |
| `2026_09_17_000001_add_partial_fulfilment_to_print_material_requests` | DROP then CREATE `print_material_request_fulfilments` | No |
| 19 migrations with `->change()` on preserved tables | Retype or relax columns; can truncate data if types narrow | **Yes** |

**Other structural facts:**
- `goods_receipt_notes` and `goods_receipt_note_items` are created twice: `2026_01_27_0717xx`, and the guarded `2026_01_29_163000_ensure_grn_tables_exist`.
- `handover_surveys` is also created twice (the two recreates above).
- There are no duplicate basenames.
- The local ledger holds 13 names with no file (renamed or deleted migrations). **A baseline must never insert a name that has no file.**

## 8. Risk of Normal `migrate`

**Confirmed:** with no `migrations` table, Laravel cannot distinguish historical migrations whose schema already exists from genuinely new ones. **Normal `php artisan migrate --force` is unsafe on production until a verified baseline exists.**

What happens, in execution order:
1. The `migrations` table is created (empty).
2. `0001_01_01_000000_create_users_table` runs an unguarded `Schema::create('users')`. It fails with `1050 Table 'users' already exists` and the run aborts.
3. Every later deploy repeats this. **It is not immediately destructive, but only by luck of ordering.**

**Why a partial or careless baseline is worse:**
- Once some rows exist, `migrate` advances to the first unledgered migration and runs it, whatever it is.
- Marking "everything older than X" can skip a genuinely missing migration. Failing to mark one lets it run: for example the `handover_surveys` DROP-then-CREATE, or `unify_payment_architecture` against a schema it has already transformed.

**Operational consequence.** `master` pushes deploy automatically and run `migrate --force`. So **no push to `master` may happen until the baseline is applied**, in either repository. The backend is the dangerous one, but the rule keeps the release simple. The existing "do not merge to master" instruction now carries this extra reason.

## 9. Migration Baseline Options

| Option | Summary | Assessment |
|---|---|---|
| **A — Schema reconciliation + baseline** | Diff the production-copy schema against the expected schema at `master`, then mark verified migrations as applied | Correct in principle. A table-level diff alone cannot attribute drift to individual migrations, and is laborious across about 300 tables |
| **B — Controlled ledger creation on a copy** | Create the ledger on the copy and populate it only with independently verified migrations | Correct. Needs a per-migration verification method |
| **C1 — Marker-verified baseline (A + B, mechanised)** | Every migration gets machine-checkable schema markers derived from its own `up()`: table exists or absent, column exists, dropped or renamed. A verifier scores each migration PRESENT, ABSENT, PARTIAL, SUPERSEDED or MANUAL against the copy. A full structural diff against a reference schema catches type, index and FK drift that markers miss | **Recommended** (§10). Tooling built and self-tested |
| C2 — `schema:dump` squash | Replace history with a schema file | Rejected. It changes history for every environment, and does nothing for a database with no ledger (`loadSchemaState` only applies to an empty one) |
| C3 — Mark all 584 `master` migrations as run | "Fake history" | **Rejected: forbidden by the directive.** It would hide any genuinely missing schema, which §6.1 says exists (09-07 request-time failures) |
| C4 — Rebuild from migrations and copy the preserved data across | Fresh schema, import the data | Fallback only, if drift proves extreme. The organic schema lacks constraints that migrations declare (e.g. no FK on `element_materials.project_element_id`, and orphans exist), so a copy-in would fail or need cleaning of preserved data |

## 10. Recommended Baseline Strategy

**Option C1**, executed only on the production copy. Its outputs are reviewed artifacts. Production later receives exactly those artifacts, after a fingerprint check.

### 10.1 Tooling (built, read-only)

- **`49_baseline_tools/classify_migrations.py <out_dir>`**
  - Parses each loaded migration's `up()`.
  - Writes `migration_inventory.tsv/.json` and `migration_markers.tsv`.
  - Markers are `table:`, `table-absent:`, `table-renamed:a->b`, `column:`, `changed:`, `dropped:` and `renamed:`.
- **`49_baseline_tools/verify_markers.py <tables.txt> <columns.tsv>`**
  - Scores every migration against a schema export.
  - Treats a marker that a later migration undoes (a drop, a rename, a table rename) as SUPERSEDED rather than failed.
  - Writes `verdicts.tsv`.

**Validation performed:**

| Test | Result |
|---|---|
| Fully migrated local database | 507 PRESENT, 19 SUPERSEDED, 81 MANUAL, **0 ABSENT, 0 PARTIAL** |
| Negative control (Phase 2B tables and columns removed) | **20/20** markable Phase 2B migrations flagged ABSENT, **0** false flags among the 584 `master` migrations |

The remaining 3 Phase 2B migrations (one FK change, two permission migrations) are correctly MANUAL.

### 10.2 Procedure (on the copy)

1. **Export the copy's schema:**
   - `SHOW TABLES`;
   - `information_schema.COLUMNS` (`TABLE_NAME, COLUMN_NAME`);
   - plus full `COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE` and `REFERENTIAL_CONSTRAINTS` for the structural diff.
   - Count tables with `SHOW TABLES`. Local MariaDB has returned wrong `information_schema.tables` counts before.
2. **Run `verify_markers.py`**, which gives a verdict per migration.
3. **Build the reference schema.** Migrate a scratch database from empty with `origin/master`'s migration set. Replay from empty was verified on 2026-09-08; re-verify it here. Diff the copy against it: types, nullability, defaults, indexes, FKs. Attribute each difference to the migration that introduced it.
4. **Hand-check the 81 MANUAL migrations**, by category:

   | Category | How to verify |
   |---|---|
   | Permission and role-grant migrations (23) | Their data effect is re-asserted by `php artisan permissions:sync` (additive, matrix-driven, fails on unknown roles). Baseline them after `permissions:sync --dry-run` shows the matrix is satisfiable. Never re-run them |
   | Reference-data seeding (settings, requisition types, units of measure, expense-code flags, `Uncategorized` category) | Query by natural key |
   | Index, unique or FK-only | Check `STATISTICS` / `REFERENTIAL_CONSTRAINTS` |
   | Data transforms (e.g. `migrate_setdown_issues_to_relational_records`, `reconcile_inventory_master_projections`, `give_recorded_costs_their_cost_centre`) | Target on the reset set: the DATA-1 reset empties it, so baseline after the reset (§11). Target on a preserved table: write a specific verification query and get a named sign-off |
   | `create_permission_tables` | Table-name variables; check the five Spatie tables by name |

5. **Decide per migration:**

   | Verdict | Action |
   |---|---|
   | PRESENT, diff-clean | **Baseline** |
   | PRESENT, but the structural diff shows drift (e.g. a `->change()` never applied) | Treat as PARTIAL |
   | ABSENT | **Genuinely pending.** Leave it out of the ledger; `migrate` applies it in order. If it is DESTRUCTIVE, review it individually before it may run |
   | PARTIAL | Write a hand-reviewed **reconciliation DDL** that completes exactly the missing part, with a reverse script. Apply it, re-verify, then baseline |
   | SUPERSEDED | Judge with its superseder. If the superseder is PRESENT, baseline both |

6. **Write artifacts:**
   - `baseline.sql`: `INSERT INTO migrations (migration, batch) VALUES (…, 1)` for verified names only;
   - `reconciliation.sql` and its reverse;
   - `pending.txt`;
   - a `schema-fingerprint` of the copy before any change.
7. **Apply `reconciliation.sql` and `baseline.sql` to the copy.** Then:
   - `migrate:status` must list **exactly** `pending.txt`;
   - `migrate --pretend` (safe once the ledger exists) prints the pending SQL for review.

### 10.3 Rules that never bend

- No migration enters the ledger because of its age. Only a PRESENT verdict, a clean diff, or a signed manual check puts it there.
- No ledger row may name a file that does not exist.
- Batch `1` is reserved for the baseline, so a later rollback of pending migrations (batch ≥ 2) can never reach history.

## 11. DATA-1 Impact

- **Rigour goes where DATA-1 preserves.** 227 migrations touch `FinanceResetBoundary::PROTECTED` tables: projects, enquiries and their tasks, budgets, quotes, clients, departments, users, employees and HR history, master data, and security. Those need PRESENT **and** diff-clean before baselining. Their destructive members (§7) get individual review.
- **The reset set can be handled lightly.** The Q1–Q3 Finance histories (payments, petty cash, receivables, payroll runs, procurement documents, ledgers) are emptied by the reset. So:
  - data-transform migrations on those tables need no historical-data verification, only schema verification;
  - a pending migration on an emptied table runs against zero rows.
- **Reset tables absent from production are skipped.** `FinanceResetBoundary::RESET_ORDER` skips tables a schema lacks. Tables created by the 178 later migrations, or by Phase 2B, are simply created empty afterwards.
- **The FK graph must be checked against the copy.** `FinanceResetBoundary::violations()` derives its safety check from the **live** FK graph. Production's organically grown schema may lack FKs the migrations declare (seen locally), in which case `violations()` could pass trivially. The rehearsal must compare the copy's `REFERENTIAL_CONSTRAINTS` with the reference schema, and review any missing cascade on the preserved side by hand. §15 Step 6 covers this.
- **Order:** baseline → reset → pending migrations. The baseline must come first, so the reset runs against a known schema and `finance:reset-plan` sees the true table set.

## 12. Queue Migration

- **Canonical migration:** `database/migrations/0001_01_01_000002_create_jobs_table.php`, which creates `jobs`, `job_batches` and `failed_jobs`. **Do not run `queue:table` and do not add a second migration.**
- **In the baseline**, its three `table:` markers will score **ABSENT**, so it stays out of the ledger. As a genuinely pending migration it will run in normal order, third overall.
- **Neighbours to watch:**
  - `0001_01_01_000000_create_users_table` creates `users`, `password_reset_tokens` and `sessions`.
  - `0001_01_01_000001_create_cache_table` creates `cache` and `cache_locks`.
  - Either may score **PARTIAL**, because `users` certainly exists while the others may not.
  - `.env.example` defaults `SESSION_DRIVER` and `CACHE_STORE` to `database`, so production's actual drivers decide whether those tables are in use. That is a Step 0 check.
  - A PARTIAL here is completed by `reconciliation.sql`, which creates only the missing tables with the migration's exact definitions, then baselines.
- **Prerequisite: H3.** The database user must hold `CREATE` (`SHOW GRANTS`), or no Laravel path can create these tables.

## 13. Queue Infrastructure Correction

**Intended production state:** `QUEUE_CONNECTION=database` plus a reliable recurring drain. `sync` is not the target (Report 48 §7/§11: failure propagation, and PO approval rollback).

**The smallest safe correction is unchanged in substance.** It is one hosting cron line, with no application change:

```cron
* * * * * cd ~/erp-backend-master && flock -n /tmp/erp-queue.lock php artisan queue:work --queue=stores-finance,default --stop-when-empty --max-time=55 --tries=3 >> storage/logs/queue-cron.log 2>&1
```

What the missing tables and deploy architecture add:
1. **It depends on §12.** The cron must not be enabled until `jobs` and `failed_jobs` exist, or it errors every minute.
2. **Enable it in the same maintenance window as the migration.** Once `jobs` exists, dispatches stop throwing and start queueing. Without a drain, they would queue silently: Case C again, now invisible.
3. **Use the right directory.** It must `cd` into the checkout the **live** app runs from, which Step 0 establishes (H1). The PHP CLI path must be confirmed on the host.
4. **No `deploy.yml` change is needed.** Each run is a fresh process that loads freshly deployed code, so `queue:restart` is unnecessary.

**Separate finding.** No `schedule:run` cron exists either, so `tasks:check-escalations` (daily, `routes/console.php`) has **never run** in production. That is a separate WNG decision, noted here and not bundled into the queue fix.

## 14. Historical Queue Behaviour

**The code path.** Laravel's `Events\Dispatcher::queueHandler` calls `pushOn()` without any `try`/`catch`. `DatabaseQueue::pushToDatabase` then does `insertGetId` into `jobs`, and `queue.database.after_commit` is `false`. With `jobs` absent, that insert throws `SQLSTATE[42S02] … 1146 Table 'woodnork_erp.jobs' doesn't exist`, **at dispatch time, in the originating request**.

The table below applies **only if production ran the code** (see H2). "Since" is when the listener reached `master`.

| Listener (since) | Dispatch context | Historical effect |
|---|---|---|
| `RecordPurchaseOrderCommitments` (2026-08-13) | `PurchaseOrderObserver::updated`, **inside** `PurchaseOrder::approve()`'s `DB::transaction` | **The approval rolls back. No PO could reach `approved`** through `approve()` |
| `RecordGoodsReceiptAccruals` (2026-08-13) | After `DB::commit()` / after the inspection transaction | GRN saved, request returns 500. A user retry could create a duplicate GRN |
| `ProjectBudgetLines` (2026-08-21) | After the budget save transaction | Budget saved, request 500s. The budget screen autosaves, so this would repeat |
| `SyncBudgetWithMaterialsList` (2026-08-21) | After `DB::commit()` (`MaterialsController:685`) or `afterCommit` (`:1651`) | Materials saved, 500 |
| `RecordPettyCashCommitment` (2026-09-07) | `DB::afterCommit` with no open transaction, so it runs immediately | Approval saved, 500 |
| `ReleasePettyCashCommitment` (2026-09-07) | `afterCommit` inside `beginTransaction`, so it runs at commit | Edit committed, then the exception surfaces from commit: 500 |
| `RecordPettyCashCost` via `PettyCashService:528` (2026-08-13) | `afterCommit`, runs immediately inside a `try` | **Caught.** `Log::critical('Petty-cash paid event dispatch failed after commit.')`. Payment proceeds; no cost recorded |
| `RecordPettyCashCost` via `SupplierPaymentService:168` | `afterCommit` after the transaction, not caught | Supplier payment committed, 500. Retry risk, mitigated by duplicate detection |
| `ReversePettyCashCost` (2026-08-13) | After `PaymentReversalService` commits | Caught by `catch (Exception)` and rethrown as "Failed to void disbursement". The void committed but was reported as failed |
| `ProcessStoresFinancePosting` (before `60491b1`) | `dispatch()->afterCommit()` | Posting row left `pending`. This matches the "Finance posting is queued" symptom `9853817` attributed to a missing worker: a missing table produces it too |
| Mail/push notification jobs (2026-07-01) | Inside `NotificationService` for users with mail or push enabled | 500 wherever those channels are on; database notifications unaffected |

**Were the exceptions swallowed?** Only on the petty-cash payout path. Everything else propagated to the default exception handler, which logs it.

**Logs should therefore contain:**
- `1146 Table '…jobs' doesn't exist`;
- `Petty-cash paid event dispatch failed after commit.`

Production logs were **not** accessed. They are read only if WNG authorises it (§22).

**Using this as a test.** These are strong, checkable predictions:
- Approved POs dated after 2026-08-13 in production.
- The **absence** of the errors above in the logs.

Either would mean the running code or database differs from what the operator saw. That points to H1 or H2. **This does not change DATA-1.** The affected Finance history is in the reset set.

## 15. Production-Copy Rehearsal Design

**Venue:**
- A restored copy in a **separate** local database, for example `wng_prodcopy_rehearsal`. Never `db`, never `db_test`.
- The same DB engine and major version as production (Step 0). The 64-character identifier limit bit MariaDB before.
- Code at the release branch, with `QUEUE_CONNECTION=database`.
- **No DDEV always-on worker.** Disable `web_extra_daemons` for the run, or use a separate stack.

| Step | Action | Pass condition |
|---|---|---|
| **0 (gate, on production, read-only)** | Establish identity: which checkout the web server serves; `git log -1` there vs `origin/master`; the DB **name** each checkout's config resolves to; `SHOW TABLES LIKE 'migrations'` in that database; `SHOW GRANTS` for the app user; `config:show session.driver cache.default`; the DB engine and version; the latest Actions log of "Deploy to production" (the `migrate --force` output) | H1–H4 resolved. The database to copy is **the one the live API uses** |
| 1 | Restore the copy (`mysqldump --single-transaction --routines --triggers`, taken by an authorised operator) | Restore completes; `SHOW TABLES` count recorded |
| 2 | Preservation counts (§16) | Recorded as the baseline |
| 3 | Export the schema; run `verify_markers.py`; build the reference schema; structural diff | Verdicts for all 607. Diff attributed |
| 4 | Establish the baseline (§10.2 steps 4–7) | `baseline.sql`, `reconciliation.sql`, `pending.txt` produced and applied to the copy |
| 5 | `migrate:status` | Lists **exactly** `pending.txt` |
| 6 | `finance:reset-plan` (dry run) plus a comparison of `REFERENTIAL_CONSTRAINTS` with the reference | No violations. Any missing preserved-side cascade reviewed |
| 7 | Execute the approved DATA-1 reset (Report 47 §18 ordered specification) | Reset tables empty; preservation counts unchanged |
| 8 | `migrate --pretend`, review, then `migrate` | All pending migrations run; `migrate:status` clean; nothing in batch 1 touched |
| 9 | Confirm `jobs`, `job_batches` and `failed_jobs` exist | Present, with definitions matching the reference |
| 10 | Configure the queue as planned: `database`, plus the §13 drain run by cron or a per-minute loop | `jobs` drains to 0 |
| 11 | `permissions:sync --dry-run`, then `permissions:sync` | No unknown-role failure. Permission migrations' effects present |
| 12 | `finance:project-budgets --dry-run`, then the real run | Planned lines equal the completed budget tasks |
| 13 | W1–W7 smoke tests (Report 44/45 smoke list) | Pass |
| 14 | Queue-sensitive tests (§19) | Pass |
| 15 | Reconcile preserved Projects and Employees (§16) | Identical |
| 16 | Integrity: journals balance; no orphaned FKs on preserved tables beyond pre-existing ones; `finance:audit-cost-identity`; `stores:readiness` | Clean, or only pre-existing issues |

**Record timings for each step.** Together they size the production maintenance window.

## 16. Preservation Verification

**Counts.** Take every table in `FinanceResetBoundary::PRESERVATION_COUNTS` at three points: after restore, after the reset, and after migrations. All three must match. The 16 tables are:
`project_enquiries`, `projects`, `enquiry_tasks`, `task_budget_data`, `budget_additions`, `budget_versions`, `quote_approvals`, `employees`, `employee_salary_histories`, `clients`, `departments`, `users`, `teams_members`, `technical_labours`, `governance_audit_logs`, `hr_audit_logs`.

**Content, not just counts.** Take a per-table checksum over primary key and key business columns for `project_enquiries`, `projects`, `task_budget_data`, `task_quote_data`, `employees`, `clients` and `users`, at the same three points. A migration that retypes a column (the `->change()` risk in §7) changes the checksum where a count would not.

**Spot checks.** Five named projects (budget, quote, tasks) and five employees (salary history, leave, documents), read through the application's UI or API.

## 17. Finance Reset Integration

- The reset runs **after** the baseline and **before** pending migrations (§11). Report 47's order becomes: baseline → reset → migrations → `permissions:sync` → `finance:project-budgets`.
- **Run the reset from the rehearsed specification only** (Report 47 §18). `CONFIRM_ON_PRODUCTION` tables must be shown empty or be WNG-confirmed on the copy, and that confirmation must be carried to production.
- Report 47 did not execute the reset and nothing here changes that. The copy is the first place it runs.
- **After the reset, the Phase 2B migrations run against empty Finance tables.** Their data migrations are among the 4 tagged DATA in §7: invoice-line discounts, Wave-3 controls, and the W6 and W7 permissions.

## 18. Pending Migration Strategy

- **Genuinely pending** means ABSENT, plus PARTIAL after reconciliation, plus the 23 Phase 2B migrations.
- **They run in basename order through normal `migrate`,** as batch ≥ 2. That keeps Laravel's own ordering and rollback semantics.
- **Destructive pending migrations each need a named review** before the run. Any that would destroy preserved data are **excluded and escalated**, not run. They are listed in the rehearsal log with their `--pretend` SQL.
- **Watch for dependency gaps.** A pending migration can fail if an older one is also pending and absent from the list. The rehearsal run exposes this, and the fix belongs in `reconciliation.sql`, not in edits to history.
- **Production gets exactly the rehearsed set.** Before anything is applied there, a fresh read-only schema export must reproduce the copy's `schema-fingerprint`. **If it differs, stop and re-rehearse.**

## 19. Queue-Sensitive Rehearsal

Run Report 48 §10 in full on the copy after Step 10: R1 budget projection, R2 PO commitment, R3 GRN accrual, R4 petty-cash commitment, R5 petty-cash actual, R6a commitment release, R6b void reversal. Add:

| # | Check | Pass |
|---|---|---|
| Q1 | Before the drain runs, each action writes a `jobs` row (`displayName` = the listener) **and returns success** | No `1146` anywhere; the request is not blocked |
| Q2 | After one drain cycle | `jobs` is 0; the ledger effect is present |
| Q3 | Force one listener failure (e.g. an inactive expense code) | Retries honour `$tries`/`$backoff`, then a `failed_jobs` row appears and `failed()` logs. **The originating request was unaffected** |
| Q4 | Two overlapping drains (start one manually during the cron) | `flock` prevents the overlap; no double effect (listeners are idempotent in any case) |
| Q5 | PO approval end to end (a regression guard for §14's rollback) | PO reaches `approved`; commitment posted after the drain |
| Q6 | Deploy simulation: pull a new commit on the copy host during the drain | The next cycle runs the new code |

## 20. Rollback Strategy

**Binary logging is off**, so a **verified `mysqldump` taken at the start of the window is the only way back.** Its restore time is measured in the rehearsal.

| Point of failure | Rollback |
|---|---|
| Before `reconciliation.sql`/`baseline.sql` | Nothing to undo |
| After baseline, before reset | Run the reverse reconciliation script; `DROP TABLE migrations`. Exact prior state. Rehearse it |
| After the reset | Restore the full dump. There is no partial undo, because the reset deletes rows |
| After pending migrations | Restore the full dump, **and** redeploy the previous backend commit. A redeploy runs `migrate --force` against the restored, ledgerless database, so it must come **with** the baseline re-applied or the push held. That ordering is written into the runbook and rehearsed |
| After the queue cron is enabled | Disable the cron line. Queued jobs stay in `jobs` and are harmless |

**Triggers:** any preservation count or checksum mismatch, any unreviewed destructive SQL, a `migrate:status` that differs from `pending.txt`, a fingerprint mismatch, or a smoke-test failure on W1–W7.

## 21. Updated Release Sequence

The sequence no longer assumes production has valid migration history.

1. **Freeze `master`** in both repositories. A push deploys and runs `migrate --force` (§8).
2. **Step 0 identity checks** on production, read-only (§15, §22).
3. **Production dump** by an authorised operator. Verify it by restoring it to scratch.
4. **Offline rehearsal**, §15 steps 1–16. Output: `baseline.sql`, `reconciliation.sql` and its reverse, `pending.txt`, `--pretend` SQL, fingerprint, timings, results.
5. **Review and sign-off** by WNG and Engineering: the baseline and its manual checks, destructive pending migrations, the DATA-1 reset execution, and the maintenance window.
6. **Production window:**
   1. `php artisan down`.
   2. Fresh verified dump.
   3. Schema fingerprint equals the rehearsal's. **If not, abort.**
   4. **Migration baseline establishment:** `reconciliation.sql`, then `baseline.sql`.
   5. `migrate:status` equals `pending.txt`.
   6. **DATA-1 reset** (the rehearsed specification).
   7. Merge the release branch to `master` and push. The pipeline runs `migrate --force`, which now applies only the pending set: the queue tables, the 178-era gaps if any, and Phase 2B. **An operator watches the Actions log.**
   8. `migrate:status` is clean.
   9. `permissions:sync`.
   10. `finance:project-budgets`.
   11. **Enable the queue cron** (§13).
   12. Frontend deploy.
   13. Smoke tests, and queue tests Q1/Q2/Q5.
   14. `php artisan up`.
7. **Post-release:**
   - Watch `jobs`/`failed_jobs` and `storage/logs/queue-cron.log` daily for a week.
   - Record the deployment mechanism in the repository docs.

## 22. Remaining Human/Infrastructure Requirements

| # | Requirement | Owner |
|---|---|---|
| 1 | Step 0 identity checks, read-only: served checkout; `git log -1` vs `origin/master`; DB name per checkout; `migrations` presence; `SHOW GRANTS` (CREATE); session and cache drivers; engine and version; the latest "Deploy to production" Actions log | Authorised operator |
| 2 | A recent production dump (`--single-transaction --routines --triggers`), transferred securely; PII handling per WNG policy | Authorised operator |
| 3 | Optional, if authorised: grep production `storage/logs` for `1146 Table` and `event dispatch failed after commit`; count POs approved after 2026-08-13 | Operator, with WNG authorisation |
| 4 | Confirm the hosting account allows cron, and the PHP CLI path | Hosting owner |
| 5 | Sign-off on the baseline, the destructive pending migrations, and the DATA-1 reset execution | WNG and Engineering |
| 6 | A maintenance window sized from the rehearsal timings | WNG |
| 7 | Decision on a `schedule:run` cron (§13 separate finding) | WNG |
| 8 | The `master` push freeze, acknowledged by everyone with push rights | Engineering |

## 23. Final Verdict

### READY FOR PRODUCTION-COPY MIGRATION-BASELINE REHEARSAL

**Why the repository analysis is sufficient:**
- The migration set is fully inventoried (607, in 49A).
- Normal `migrate` is shown to be unsafe here.
- The baseline method is specified.
- Its tooling is built and validated in both directions: 0 false ABSENT on a fully migrated schema, and 20/20 Phase 2B migrations flagged with 0 false flags.
- The canonical queue migration is identified, and the queue correction is designed.
- The rehearsal, rollback and release sequence are defined.

**The one hard gate:** Step 0 (§15) must establish that the database to be copied is the one the live API uses. The §6.3 anomaly means this cannot be assumed.

**Production is not ready and is not declared ready.** Queue precondition: **FAILS.** Migration history: **ABSENT.** Master: **frozen.**

## 24. Immediate Next Action

### OBTAIN A RECENT PRODUCTION DATABASE COPY AND REHEARSE THE MIGRATION BASELINE + DATA-1 RESET + PHASE 2B MIGRATIONS OFFLINE

The copy must follow the Step 0 identity checks (§15, §22 item 1), so that the copy is of the database the live application actually uses.

**Not performed in this task:**
- any production connection or change;
- creating the `migrations` or queue tables;
- a worker or cron;
- any `migrate` run;
- the reset;
- a deploy or merge;
- W8, or the Finance frontend redesign.

---

## Database Identity Resolution

**Recorded:** 2026-09-28, after this report was issued. The source is further operator verification. This environment made no production connection.

**Outcome:** the §6.3 anomaly is resolved. The answer is **H1**: the operator's checks were made against a different database from the live one.

- **`woodnork_erp`** (used by `/home/woodnork/erp-backend-master`, the checkout `deploy.yml` updates) is an **empty redesigned target database**. It has **zero tables**: no projects, no employees, no migration ledger, no queue tables, no application schema.
- **The operational source database is `woodnork_erpsystem`.**
  - Application: `/home/woodnork/public_html/system`, branch `master`, commit `5bf4ab3a67f74069fe35a0ae41aa9fce247e7256` (2026-08-19), Laravel 12.28.1, production, `www.woodnorkgreen.co.ke`.
  - It **has** a `migrations` table.
  - It has `QUEUE_CONNECTION=database` and its own `jobs`, `failed_jobs` and `job_batches` tables.
  - Verified counts: Projects 731, Employees 93, Project Enquiries 1,387, Project Budgets 658, Clients 258, Users 65.

**What this changes:**

1. **The missing ledger is not evidence of a broken legacy database.** It is simply an empty target.
2. **The migration-ledger-baseline strategy for `woodnork_erp` (§8–§10, §15–§21) is SUPERSEDED.** It is not required. It is kept here unchanged as the record of the analysis made on the evidence available at the time.
3. **The release model changes** from "in-place upgrade of an existing Finance database" to **source ERP → clean redesigned ERP**. That is **Report 50** (`50_PHASE_2B_SOURCE_TO_TARGET_DATA_MIGRATION_STRATEGY.md`).

**Still valid from this report:**
- The migration inventory (49A), and `49_baseline_tools/` for comparing the live source schema with its commit.
- §12: the canonical queue migration.
- §13: the queue drain design, now applied to the target.
- §14: the historical-dispatch analysis. It applies to any database where `jobs` is missing. It does **not** describe the source ERP, which has its queue tables.
- The rule "never mark a migration run merely because it is old".

**One mechanism is still unexplained:** why `migrate --force` in `deploy.yml` has not created even an empty ledger in `woodnork_erp`. That points to H2 (the deploy never reaches artisan) or H3 (no CREATE privilege). Report 50 §18 and §22 carry it as a target-environment check.
