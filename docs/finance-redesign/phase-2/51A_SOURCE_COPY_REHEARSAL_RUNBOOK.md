# 51A — Source-Copy Rehearsal Runbook

**This runbook is executable. It has not been executed against any WNG data.**

**Purpose:** rehearse the full source → redesigned-ERP migration (Reports 50–51) on **copies**, on a non-production host.

**Owner:** Engineering, with an authorised operator for steps 1–2 (the only steps that touch the WNG host, read-only).

**Never, in this runbook:**
- connect the tooling to `woodnork_erpsystem` or `woodnork_erp`;
- run anything that writes on the WNG host;
- start a worker or cron on the WNG host.

**Names used below** (change consistently if needed):

| Name | What it is |
|---|---|
| `wng_source_copy` | Untouched restore of the source dump: the reference for counts |
| `wng_staging` | Second restore of the same dump; Stage 1 upgrades it |
| `wng_rehearsal_target` | Built by the clean migration chain; Stage 2 loads it |
| `$R` | The rehearsal checkout: the release commit, with its own `.env` |

**Shell notes** (both hit during the local dress rehearsal):
- `cp` may be aliased to `cp -i`; use `command cp -f`.
- In zsh, a variable holding several `--allow-orphans=…` flags must be expanded with `${=VAR}`, or it arrives as a single argument (and the guard refuses).

---

## 1. Obtain a recent source database dump — operator, on the WNG host, read-only

```bash
cd /home/woodnork/public_html/system
mysqldump --single-transaction --quick --routines --triggers --no-tablespaces \
  -h "$(grep ^DB_HOST= .env | cut -d= -f2)" -u "$(grep ^DB_USERNAME= .env | cut -d= -f2)" -p \
  woodnork_erpsystem | gzip > ~/erpsystem-$(date +%Y%m%d-%H%M).sql.gz
sha256sum ~/erpsystem-*.sql.gz
```

- `--single-transaction` takes a consistent snapshot without locking.
- Record the time, file size and sha256.
- **Also record the live headline counts at dump time.** These replace the 2026-09-28 reference values (731 / 93 / 1,387 / 658 / 258 / 65):

```sql
SELECT (SELECT COUNT(*) FROM projects), (SELECT COUNT(*) FROM employees), (SELECT COUNT(*) FROM project_enquiries),
       (SELECT COUNT(*) FROM task_budget_data), (SELECT COUNT(*) FROM clients), (SELECT COUNT(*) FROM users);
SELECT VERSION();   -- the engine and version the rehearsal must match
```

## 2. Obtain the source storage archive — operator, read-only

```bash
tar -C /home/woodnork/public_html/system/storage -czf ~/system-storage-$(date +%Y%m%d-%H%M).tgz app
sha256sum ~/system-storage-*.tgz; du -sh /home/woodnork/public_html/system/storage/app
```

Transfer both files by an approved secure channel. They contain personal data: handle them under WNG policy, and delete them after the rehearsal.

## 3. Create the isolated databases — rehearsal host

Use the **same engine and major version** as the source (step 1).

```bash
ddev mysql -uroot -proot -e "
  CREATE DATABASE wng_source_copy; CREATE DATABASE wng_staging; CREATE DATABASE wng_rehearsal_target;
  GRANT ALL ON wng_source_copy.* TO 'db'@'%'; GRANT ALL ON wng_staging.* TO 'db'@'%';
  GRANT ALL ON wng_rehearsal_target.* TO 'db'@'%'; FLUSH PRIVILEGES;"
gunzip -c erpsystem-*.sql.gz | ddev mysql -uroot -proot wng_source_copy
gunzip -c erpsystem-*.sql.gz | ddev mysql -uroot -proot wng_staging
```

**Rehearsal checkout `$R`:**
- A separate clone or worktree at the release commit, with its own `vendor` and `composer dump-autoload`.
- Its `.env` must set `DB_DATABASE=wng_rehearsal_target`, `QUEUE_CONNECTION=database`, and `SOURCE_STAGING_DB_HOST/DATABASE=wng_staging/USERNAME/PASSWORD`.

**Prove the wiring before anything else:**

```bash
cd $R
php artisan config:show database.connections.mysql.database            # must print wng_rehearsal_target
php artisan config:show database.connections.source_staging.database   # must print wng_staging
```

Local DDEV note: `docker exec -e DB_DATABASE=…` does **not** override `.env`. Always use a checkout with its own `.env`.

## 4. Verify the source schema and ledger

```bash
ddev mysql -uroot -proot -N -e "SELECT COUNT(*) FROM wng_source_copy.migrations; SHOW TABLES FROM wng_source_copy" | wc -l
php artisan migration:stage1            # dry run: prints pending target migrations and the ledger reconciliation
php artisan migration:evidence all      # baseline evidence on the (not yet transformed) staging copy
```

**Expected:**
- about 445 source migrations in the ledger, including the two `2024_01_10_*` asset names;
- Stage 1 dry run: **162 pending**, plus "Ledger reconciliation: record 2026_06_29_000010 … / …_000011".

**Stop and investigate** if the pending count differs materially, or if the reconciliation is refused (a marker is absent). Compare the copy's column list with the rebuilt source-commit schema (Report 50 §6); any drift found here is reported, never fixed by hand.

## 5. Run the orphan scan (baseline)

`migration:evidence projects` and `employees` (step 4) list broken relationships on the untransformed copy. The full orphan scan, with severities, runs in the step 9 dry run once the target exists. Keep both reports.

## 6. Run Stage 1 on the staging copy

```bash
php artisan migration:stage1 --execute --confirm=wng_staging
```

**Pass:** "Stage 1 complete: every target migration is recorded on the staging copy."

The target's backfills (enquiry delivery-date status, board custody, return kinds, UoM) and seeds now apply to the real rows.

## 7. Create the clean rehearsal target

`wng_rehearsal_target` was created empty in step 3.

## 8. Run the full target migration chain

```bash
php artisan migrate --force                 # the 607-migration chain; about 1.5–3 minutes
php artisan migration:target-readiness      # before the queue and storage steps, expect only "cron" and "storage link" issues
```

## 9. Run the migration import dry run

```bash
php artisan migration:plan                  # validates database/source-migration/plan.json
php artisan migration:import-source         # DRY RUN (the default)
```

**Review the reports** in `storage/app/source-migration/<run>/`:

| Report | What to check |
|---|---|
| `schema_gate.json` | `drift_summary` and every `drift` entry. **Equivalent** and **widening** entries are accepted. A **narrowing** that fits needs explicit acceptance (step 11). A narrowing that does **not** fit, or anything incompatible, **stops the rehearsal** |
| `orphan_scan_staging.json` | Every finding: table, column, count, sample child keys, missing parent ids, severity |
| `plan_summary.json` | Row counts of every excluded, skipped and decision-pending table (nothing is left behind unseen) |
| `import_order.json` | Order, cycles, self-references |
| `dry_run.json` | Tables that would load, and refusals if executed |

## 10. Resolve the D2/D3/D4 evidence

```bash
php artisan migration:evidence d2           # PO/GRN/bills: counts, dates, statuses, linkage, objective test indicators
php artisan migration:evidence roles        # source_role_mapping_report
php artisan migration:evidence project-status
```

WNG records each decision in the Decision Register (MIG-D2/D3/D4):

- **D2 = import:** set the D2 tables' plan mode to `import`, adding `"decision": "<register ref>"`. The plan refuses a D2 load without it.
- **D3:** stays `decision-pending` until the accountant decides. The source chart then becomes `replace-seeded` or stays out.
- **D4:** a mapped role gets its users re-assigned **after** import, by an approved script or in the UI. Roles are never deleted by the tooling.
- **Orphans:** WNG approves, per `table.column`, loading pre-existing orphans as-is. They are never dropped.

Commit the reviewed `plan.json` changes, then re-run step 9 until the dry run is clean.

## 11. Execute the approved import

```bash
php artisan migration:import-source --execute --confirm=wng_rehearsal_target \
  --accept-verified-narrowing \
  --allow-orphans=<table.column> …          # only the WNG-approved list
```

**Pass:** `VALIDATION: PASS`, meaning:
- every loaded table's count, key range and checksum equal staging;
- orphans introduced: 0;
- exclusions PASS.

**If it fails part-way:** that table was rolled back. Rebuild the target (steps 7–8), or fix the cause and re-run with `--resume`.

## 12. Sync permissions

```bash
php artisan migration:regenerate permissions --execute --confirm=wng_rehearsal_target
php artisan migration:import-source --only=model_has_permissions --execute --confirm=wng_rehearsal_target --accept-verified-narrowing
php artisan permissions:sync --dry-run      # must print "Already in sync"
```

The load report lists each direct grant whose permission no longer exists (`unmapped`) for WNG review.

## 13. Regenerate eligible planned CostLines (D5)

```bash
php artisan migration:regenerate reference --execute --confirm=wng_rehearsal_target             # chart seeding forced OFF (D3)
php artisan migration:regenerate planned-cost-lines --execute --confirm=wng_rehearsal_target    # active/open projects only
php artisan migration:evidence w7 --connection=mysql   # recordable labour lines appear only for regenerated (open) projects
```

## 14. Verify files

```bash
mkdir -p /tmp/wng-storage && tar -C /tmp/wng-storage -xzf system-storage-*.tgz
php artisan migration:verify-files --dry-run
php artisan migration:verify-files --storage-path=/tmp/wng-storage     # against the source archive
command cp -rf /tmp/wng-storage/app/public/. $R/storage/app/public/   # or rsync -a
command cp -rf /tmp/wng-storage/app/private/. $R/storage/app/private/
php artisan storage:link
php artisan migration:verify-files --connection=mysql --storage-path=$R/storage   # after the copy
```

**Pass:** the missing count after the copy equals the missing count on the source archive (files already missing at the source), and **0** lost in the copy. Absolute URLs (the old domain) are listed for a WNG-approved rewrite decision.

## 15. Configure the rehearsal queue

**Disable any always-on worker first:** DDEV runs `web_extra_daemons: stores-finance-worker`. Comment it out and `ddev restart`, so it cannot mask a missing drain.

Then run the production drain shape, one minute at a time:

```bash
while true; do php artisan queue:work database --queue=stores-finance,default --stop-when-empty --max-time=55 --timeout=50 --sleep=3 --tries=3; sleep 5; done
```

On a host with cron, use the line `migration:target-readiness` prints under `recommended_cron`, which includes `flock -n`.

```bash
php artisan migration:target-readiness      # the queue tables and QUEUE_CONNECTION must now be clean
```

## 16. Run the W1–W7 smoke tests

- **W1–W7:** the Report 44/45 smoke list.
- **Queue-sensitive paths:** Report 48 §10 R1–R6b and Report 49 §19 Q1–Q6.
- **Logins:** a named sample of users with their own passwords, plus role-gated screens per role.
- **Interfaces:** Project and Employee screens for 5 named projects and 5 named employees, compared side by side with the source ERP.

## 17. Reconcile Projects, Employees and dependencies

```bash
php artisan migration:import-source --validate
php artisan migration:evidence all                       # staging (source copy, transformed)
php artisan migration:evidence all --connection=mysql    # target
php artisan migration:verify-overtime-chain
```

Compare the staging and target reports (projects, employees, budget authority, W7, exclusions), and compare the headline counts with the step 1 live counts. **Every difference must be explained by a named rule.**

## 18. Produce the PASS/FAIL report

| Criterion | PASS when |
|---|---|
| Stage 1 | 0 pending on staging; reconciliation marker-verified |
| Schema gate | Only equivalent, widening, or verified-and-accepted narrowing |
| Import | `VALIDATION: PASS` (counts, key ranges, checksums; 0 orphans introduced; exclusions PASS) |
| Headline counts | Source copy = target for Projects, Employees, Enquiries, Budgets, Clients, Users |
| Budget authority | `class_agrees_with_sql: true` on the target |
| W7 | `w7_service_agrees: true`; recordable lines only on open projects after regeneration |
| Authentication | Password hashes unchanged, employee–user links identical, role assignments identical; the login sample succeeds |
| Permissions | `permissions:sync --dry-run` clean; unmapped direct grants reviewed |
| Files | 0 lost in the copy; absolute URLs decided |
| Overtime chain | No break introduced by the migration |
| Queue | Tables present; drain processes R1–R6b / Q1–Q6 |
| W1–W7 | Smoke list passes |
| Decisions | D2/D3/D4 recorded; orphan allow-list and narrowing acceptance signed off |

**Record the timings of every step:** they size the cutover window (D8).
