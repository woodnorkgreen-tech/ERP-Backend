# 48 — Phase 2B: Queue Release Precondition Verification

**Date:** 2026-09-28
**Inputs:** Report 47 §15 and §21 item 2; the release branch `finance/critical-stabilization-fixes`; `master` history.
**Boundary:** No listener changed, no Supervisor or cron added, no deployment, no merge to `master`, no database reset, no production access used, no W8.
**Verdict:** **NOT PROVEN — LIVE QUEUE CONFIGURATION MUST BE VERIFIED** (§12).

---

## 1. Executive Summary

- **LIVE QUEUE CONFIGURATION NOT VERIFIED.** This environment has no authorised read access to the production host. The one attempt to find a route to it was refused, and no other route was tried. Production's `QUEUE_CONNECTION` is therefore **unknown**. The classification is **Case D**.
- **The repository evidence points towards Case C (async driver, no worker), but does not prove it:**
  - `.env.example` ships `QUEUE_CONNECTION=database`.
  - `config/queue.php` falls back to `redis` when the key is absent. Neither default is `sync`.
  - Commit `9853817` (2026-09-22) reports production symptoms. Stores finance postings stayed "queued", and "Production had no queue worker at all".
  - Commit `60491b1`, the same day, withdrew the worker unit because "production shared hosting has no service supervisor". It made **only** the Stores→Finance posting synchronous.
  - Nothing in the repository (deploy workflow, scheduler, docs) starts a worker on production.
- **Eight Finance listeners are `ShouldQueue`.** All eight are registered explicitly, and event discovery is off. If they are queued with no worker:
  - PO commitments and GRN accruals never post, and have **no fallback at all**.
  - Petty-cash commitments, actuals, releases and void reversals never post. Petty-cash actuals have only a manual backfill.
  - Budget projection is covered **only** by the one-time `finance:project-budgets` release step, not on each save that follows.
  - The failure is **silent**. No `failed()` hook runs, no Finance-visible flag is set, and the STAB-4 retry button cannot appear, because the job never started.
- **Tests could not detect this.** `phpunit.xml:31` forces `QUEUE_CONNECTION=sync`, so every listener runs inline. DDEV masks it too, because it runs its own worker daemon (`.ddev/config.yaml:265`).
- **Even `sync` would not be a no-op.** Laravel's `SyncQueue` rethrows listener exceptions into the request, and it ignores `$tries`/`$backoff`. The PO commitment listener runs **inside** the PO-approval transaction. Case A passes, but the rehearsal must still exercise failure behaviour (§10).
- **Release stays blocked** until an authorised operator runs the read-only check in §9. Repository work is not blocked.

## 2. Repository Queue Configuration

| Source | Evidence | What it tells us |
|---|---|---|
| `config/queue.php:16` | `'default' => env('QUEUE_CONNECTION', 'redis')` | An absent key means **redis**, not sync |
| `.env.example:61-62` | `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=1200` | The shipped template is async (database) |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` exist | The database driver is fully provisioned |
| `phpunit.xml:31` | `QUEUE_CONNECTION=sync` | Tests run inline (§6) |
| `.ddev/config.yaml:264-265` | `web_extra_daemons: stores-finance-worker` → `queue:work database --queue=stores-finance,default` | Dev is async **with** a worker |
| `.github/workflows/deploy.yml` | No `queue:work`, `queue:restart`, cron or `schedule:run`. It runs `stores:process-finance-postings` once per deploy | Deploy starts no worker |
| `deploy/erp-queue-worker.service` | systemd unit, "installed by hand once per box". Header: the worker "was started by hand over SSH and died silently" | Written for production and **never installed** (see `60491b1`) |
| `routes/console.php` | One scheduled command, `tasks:check-escalations` daily | No scheduled queue drain. Whether production has a `schedule:run` cron is unknown |
| `app/Providers/EventServiceProvider.php:115` | `shouldDiscoverEvents(): false` | Every listener below is registered exactly once, explicitly |
| `ProcessStoresFinancePosting` | `onQueue('stores-finance')`, now invoked via `dispatchSync` | The only path already made worker-independent |

## 3. Production Configuration Evidence

**LIVE QUEUE CONFIGURATION NOT VERIFIED.**

The only evidence about production is historical, from `master`:

1. **`9853817` (2026-09-22 10:07)** — *"Production had no queue worker at all — found while chasing 'Finance posting is queued' staying stuck on Stores issues."*
   - Under `sync`, a `->afterCommit()` dispatch runs in-process once the transaction commits. It cannot sit "queued" waiting for a worker.
   - The symptom therefore suggests an async driver was active on production on that date.
   - The commit added the systemd unit and a `queue:restart` deploy step.
2. **`60491b1` (2026-09-22 12:12)** — *"Stores-to-Finance now posts synchronously because production shared hosting has no service supervisor."*
   - It removed `queue:restart` from the deploy.
   - It converted **only** `StoresFinanceOutbox` to `dispatchSync`.
   - Every other `ShouldQueue` class kept its queued behaviour.
3. Earlier docs flagged the same question without resolving it: `08_CROSS_MODULE_INTEGRATION_MAP.md:14,58`, `12_WNG_CONFIRMATION_QUESTIONS.md` Q71, `04_PHASE_2_READINESS_REPORT.md:129`.

**Assessment:** the evidence strongly suggests that production is async with no supervised worker. That is Case C. But it is second-hand. It does not show:
- whether `.env` was changed after 2026-09-22;
- whether someone still starts a worker by hand;
- whether a hosting-panel cron drains the queue.

It is not proof, so the verdict stays Case D.

## 4. Queued Finance Listener Inventory

All eight implement `Illuminate\Contracts\Queue\ShouldQueue`. None sets `$connection`, `$queue` or `afterCommit`, so all eight go to the **default connection, `default` queue**.

| # | Listener | Triggering event → dispatch site | Transaction context of dispatch | Financial effect | Explicit synchronous fallback | Rehearsal must verify |
|---|---|---|---|---|---|---|
| 1 | `ProjectBudgetLines` | `BudgetLinesChanged` ← `BudgetService.php:84` (budget save), `:225` (materials→budget sync) | After the save transaction commits | Re-projects **planned** cost lines (`source_type='BudgetLine'`) to equal the budget | **Partial:** `finance:project-budgets` (idempotent, `--dry-run`). One-time release step only; later saves are not covered | Yes |
| 2 | `RecordPurchaseOrderCommitments` | `PurchaseOrderApproved` ← `PurchaseOrderObserver::updated` ← `PurchaseOrder::approve()` | **Inside** `DB::transaction` (`PurchaseOrder.php:300`) | **Committed** lines per `PurchaseOrderItem` | **None.** `postPurchaseOrder()` has no other caller | Yes |
| 3 | `RecordGoodsReceiptAccruals` | `GoodsReceiptRecorded` ← `GoodsReceiptNoteController.php:444`, `GoodsReceiptInspectionController.php:105` | After commit | **Accrued** lines per `GoodsReceiptNoteItem` (Dr Inventory / Cr Accrued Expenses), and relief of the matching commitment | **None.** `postGoodsReceipt()` has no other caller | Yes |
| 4 | `RecordPettyCashCommitment` | `PettyCashRequisitionApproved` ← `PettyCashRequisitionController.php:614` | `DB::afterCommit` | **Committed** line per `PettyCashRequisition` | **None** | Yes |
| 5 | `RecordPettyCashCost` | `PettyCashDisbursementPaid` ← `PettyCashService.php:528`, `SupplierPaymentService.php:168` | `DB::afterCommit` | **Actual** line and cost GL posting via `PettyCashCostPoster`, which also releases the commitment | **Partial:** `finance:backfill-petty-cash` (manual). The retry endpoint (`PettyCashController.php:606`) only works if the job already ran and flagged `cost_gl_posting_failed_at` | Yes |
| 6 | `ReleasePettyCashCommitment` | `PettyCashRequisitionReturnedToPending` ← `PettyCashRequisitionController.php:383` | `DB::afterCommit` | Retires the commitment when an approved requisition is edited | **None.** Surrender (`:2222`) releases directly, but that is a different path | Yes |
| 7 | `ReversePettyCashCost` | `PettyCashDisbursementVoided` ← `PettyCashService.php:590` | After `PaymentReversalService::reverse` | Reverses the fee journal `JE-PFEE-*`, the direct-payment journal `JE-PAY-*`, and the **actual** cost lines | **None** | Yes |
| 8 | `SyncBudgetWithMaterialsList` | `MaterialsListChanged` ← `MaterialsController.php:685`, `:1651` (afterCommit) | Mixed | Rewrites the budget's materials from the list, then chains into #1 through `BudgetLinesChanged` | **None** (a budget pulls the list when first opened) | Yes (as the upstream of #1) |

Non-Finance `ShouldQueue` classes on the same default queue are out of scope but share the same fate:
- mail and push notification jobs;
- four Stores board-workflow notifiers;
- four UniversalTask notifiers;
- `SyncHikvisionAttendanceJob`.

`ProjectBudgetLinesOnTaskCompletion` is **not** queued: its registration comment says "Queued" but the class does not implement `ShouldQueue`.

**Side finding, not changed (LOW):** `ReversePettyCashCost.php:71` logs `$line->id` before `$line` is assigned.
- It is reachable only when a void carries no authenticated user **and** cost lines exist.
- There it raises `ErrorException` instead of the intended log line.
- It is recorded here and not fixed, per §5 of the directive.

## 5. Financial Impact

If the driver is async and no worker runs (Case C):

| Path | What never happens | Visible to Finance? | Consequence |
|---|---|---|---|
| PO commitment | No committed line when a PO is approved | No | Committed spend understated on every project, so budget-vs-committed looks healthier than it is |
| GRN accrual | No accrued line and no Dr Inventory / Cr Accrued Expenses | No | Unbilled goods liability is missing from the ledger, and commitments are never relieved into accruals |
| Petty-cash commitment | Approved requisitions commit nothing | No | Same understatement as PO commitments |
| Petty-cash actual | Paid disbursements never reach project actuals or the cost GL leg | No: `cost_gl_posting_failed_at` is only set **by** the job | Actuals as stale as the last manual `finance:backfill-petty-cash` |
| Commitment release | Edited requisitions keep their old commitment | No | Committed spend overstated; the counterpart of the row above |
| Void reversal | Voided payments keep their cost lines, fee journal and payment journal | No | Project actuals **and** GL overstated after every void |
| Budget projection | Planned lines drift from the budget after the release step | No | Budget-vs-actual measured against a stale ceiling |

**Backlog hazard.** If jobs have been piling up in `jobs`, a worker started later will replay them. The listeners are idempotent on their source keys, so a replay will not double-post. But they will post **today** into the ledger, against whatever the DATA-1 reset left behind:
- Jobs for reset (deleted) sources skip harmlessly (`find()` returns null).
- Jobs for preserved sources, such as approved POs, will create commitments and accruals.

WNG must decide whether those should exist in the reset ledger. **No worker may be started against an existing backlog before the rehearsal has replayed that backlog on the production copy.**

## 6. Test Environment Difference

Report 47's finding is **confirmed**:

- `phpunit.xml:31` sets `<env name="QUEUE_CONNECTION" value="sync"/>`.
- Under `sync`, `Dispatcher::queueHandler` pushes to `SyncQueue`, which runs `executeJob()` in-process immediately. For an after-commit dispatch, it runs at commit.
- Every Finance test therefore sees the cost line the instant the event fires. The suite **proves the listeners are correct, and says nothing about whether they run in production.**
- The only local async environment is DDEV, and it runs a permanent worker daemon. The "async, no worker" state is not reproduced anywhere in development.
- No test asserts that the default connection is `sync`, and none asserts that a worker exists. Infrastructure is outside what a PHPUnit suite can observe.

## 7. Worker / Synchronous Execution Assessment

| Case | Would the eight listeners run? | Notes from the code |
|---|---|---|
| **A — `sync`** | Yes, inline | `SyncQueue::handleException` **rethrows**, and `$tries`/`$backoff` are ignored. Consequences: **#2** runs inside the PO-approval transaction, so a commitment failure **rolls back the approval**. **#1, #3** raise an HTTP error after their save has already committed, so the user sees a failure for a save that stuck. **#4–#7** do the same through `afterCommit`. #5 absorbs GL failures via `PettyCashCostPoster`. #8 swallows everything. The listeners' "must never block the workflow" comments assume async; under `sync` that guarantee does not hold. It is still a **PASS** by the decision rule, because nothing is lost |
| **B — async + real worker** | Yes, within the worker's polling interval | A worker must cover `default`. Retry and `failed()` semantics work as designed. The worker must be restarted or recycled on deploy, or it runs stale code (see the `9853817` rationale) |
| **C — async, no worker** | **No, never** | §5 applies in full |
| **D — unknown** | Unknown | **Current state** |

## 8. Production Release Risk

- **Case C is plausible, and it is pre-existing.** If true, it has been true on `master` since each listener shipped. Phase 2B does not create it. But Phase 2B's reports, reconciliations and W6/W7 controls **depend** on commitments, accruals and actuals being present, so releasing on top of it would make them untrustworthy.
- **Silent by design.** Every `failed()` hook and every STAB-4 flag lives inside the job. A job that never runs produces no error, no alert and no retry button.
- **Scope of loss.** It covers every PO approval, GRN, requisition approval or edit, disbursement and void since whatever date the worker last stopped. §9's backlog query measures it directly.
- **Classification:** DEPLOYMENT/INFRASTRUCTURE, **CRITICAL, release-blocking**, unchanged from Report 47.

## 9. Required Operator Check

Run by someone with authorised SSH access to the production host, in the production app directory (`~/erp-backend-master` per `deploy.yml`). **Every command is read-only.** None prints credentials, and none writes to the database or the queue.

```bash
cd ~/erp-backend-master

# 1. Effective connection. Reads the cached config, which is what the app
#    actually uses, and prints only this one value.
php artisan config:show queue.default

# 2. Is anything running or scheduled to run a worker?
ps -u "$(whoami)" -o pid,lstart,args | grep '[q]ueue:work' || echo "no queue:work process"
crontab -l 2>/dev/null | grep -E 'queue:work|schedule:run' || echo "no queue/scheduler cron"
systemctl status erp-queue-worker 2>/dev/null | head -3 || echo "no systemd unit"
# Also check the hosting control panel's Cron Jobs page: panel crons may not
# appear in `crontab -l` on every host.

# 3a. If step 1 printed "database": backlog by queue and job class, and oldest age.
php artisan tinker --execute='
  dump(DB::table("jobs")->selectRaw(
    "queue, JSON_UNQUOTE(JSON_EXTRACT(payload, \"$.displayName\")) AS job,
     COUNT(*) AS n, FROM_UNIXTIME(MIN(created_at)) AS oldest,
     SUM(attempts > 0) AS attempted")
    ->groupBy("queue", "job")->orderBy("oldest")->get()->toArray());'

# 3b. If step 1 printed "redis": queue lengths.
php artisan queue:monitor redis:default,redis:stores-finance

# 4. Jobs that ran and failed (any driver).
php artisan queue:failed
```

Report back **only**:
- the value from step 1;
- whether step 2 found a worker or cron, and its exact command line;
- the backlog table from step 3;
- the count of failed jobs from step 4.

For the database driver, `CallQueuedListener` rows carry the listener class in `displayName`. Rows for the §4 listeners, old and never attempted, are direct proof of Case C.

**How to read the result:**

| Step 1 | Step 2 | Step 3 | Case |
|---|---|---|---|
| `sync` | — | — | **A** |
| `database`/`redis` | persistent worker covering `default`, restarted on deploy | ≈0, recent only | **B** |
| `database`/`redis` | none, or a hand-started process with no supervision | any | **C** |

A hand-started, unsupervised worker is **not** a real mechanism, because it dies on reboot, OOM or a crashed job. Treat that as Case C.

## 10. Production-Copy Rehearsal Requirements

The rehearsal must run with the **same queue configuration production will have after release**:
- If Case A: `sync`.
- If Case C: the chosen correction from §11, for example the cron drain.

It must not run under DDEV's always-on worker, which would reproduce Case B whatever production is.

**Before any action:**
1. Record `config:show queue.default` on the rehearsal host.
2. Snapshot the `jobs` and `failed_jobs` counts.
3. Snapshot `cost_lines` counts by `nature` and `source_type`.
4. If the production dump carries a `jobs` backlog, **replay it first**, run `finance:reset-plan`, and record which preserved sources gained cost lines (§5 backlog hazard).

| # | Path | Action on the copy | Expected evidence (after the worker drains, or immediately under `sync`) |
|---|---|---|---|
| R1 | Project Budget projection | `finance:project-budgets --dry-run`, then the real run. Then edit and save one budget, and edit one materials list that feeds a budget | Planned `BudgetLine` lines equal the budget. After the edit they are re-projected **without** re-running the command. That proves #8→#1 runs unattended |
| R2 | PO commitment | Approve one PO, above and below the senior threshold | One `committed` line per `PurchaseOrderItem` |
| R3 | GRN accrual | Record a GRN against R2's PO, then run one inspection outcome | `accrued` lines per `GoodsReceiptNoteItem`, R2's commitment relieved, the Dr Inventory / Cr Accrued Expenses journal present |
| R4 | Petty-cash commitment | Approve one project-coded requisition and one overhead-coded requisition | A `committed` line for the project requisition only |
| R5 | Petty-cash actual cost | Pay R4's project requisition out | An `actual` line, the cost GL leg, and R4's commitment released. `cost_gl_posting_failed_at` is null |
| R6a | Commitment release | Approve, then edit, a second requisition so it returns to pending | Its commitment is released. Re-approving records one fresh commitment at the new amount |
| R6b | Void reversal | Void R5's disbursement with an authenticated user | Actual line `reversed`. `JE-PAY-*` and `JE-PFEE-*` (if any) reversed |

**After every row:**
- `jobs` returns to 0.
- `failed_jobs` is unchanged.
- The log contains the listener's info line and no `Failed to …` line.

**Failure-mode check (Case A only):**
- Force one commitment failure, for example with a temporarily inactive expense code, and confirm what the user sees.
- For R2, confirm the PO approval is rolled back rather than half-applied.
- Record whether that behaviour is acceptable to WNG.

**Timing check (Case C correction):** measure how long the event-to-line delay is, and confirm it is no more than one cron interval.

## 11. Recommended Resolution

**Now:** run §9. No code or infrastructure change until the result is known.

**If Case A (`sync`):**
- No infrastructure change is needed.
- Accept, or explicitly reject, the failure propagation described in §7, based on the result of R2's failure check.

**If Case B:**
- Document the exact worker mechanism in this report's successor.
- Confirm it is restarted on deploy.

**If Case C, the smallest safe correction:** a per-minute hosting cron that drains the queue and exits:

```cron
* * * * * cd ~/erp-backend-master && flock -n /tmp/erp-queue.lock php artisan queue:work --queue=stores-finance,default --stop-when-empty --max-time=55 --tries=3 >> storage/logs/queue-cron.log 2>&1
```

Why this option:
- It is the option Report 47 already named.
- It needs no root and no supervisor, so it fits shared hosting.
- It needs **no application change**.
- It keeps the listeners' designed semantics: `$tries`, `$backoff`, `failed()`, and never blocking the originating save.
- Each run is a new process, so it picks up freshly deployed code without `queue:restart`.
- `flock` stops two runs overlapping.

Before enabling it, handle any backlog as in §5 and §10.

**Why not `QUEUE_CONNECTION=sync` as the Case C fix:** it is also a one-line change, but it silently changes behaviour.
- It turns a cost-ledger fault into a failed PO approval (#2), or into an error shown for a save that already committed.
- It also runs mail sends and the Hikvision attendance sync inside web requests.

It remains a valid fallback if WNG cannot add a cron. In that case the Case A failure check in §10 becomes mandatory.

**Either way, afterwards:** add the chosen mechanism and a `schedule:run` question to the deployment docs. That step is for the next task, not this one.

## 12. Final Verdict

### NOT PROVEN — LIVE QUEUE CONFIGURATION MUST BE VERIFIED

**The queue finding:**
- Production's `QUEUE_CONNECTION` is **not verified**. This environment has no authorised production access.
- Repository evidence (`.env.example=database`, config default `redis`, commits `9853817`/`60491b1`, and no worker or cron anywhere in the deploy path) points to **Case C: async driver with no worker**.
- In Case C, the eight Finance listeners never run: PO commitments, GRN accruals, petty-cash commitments, actuals, releases and void reversals, and ongoing budget projection. Nothing fails visibly.
- The test suite cannot see this, because of `QUEUE_CONNECTION=sync` in `phpunit.xml`.

**Next required action:**
1. An authorised operator runs the read-only check in §9 on the production host and reports the four values.
2. On that answer, classify A, B or C.
3. If C, WNG approves the §11 cron correction and a decision on any backlog.
4. Then run the production-copy rehearsal with the §10 queue checks.

**Release remains blocked. Repository work is not.**

Not performed in this task: deployment, production reset, Phase 2B migrations on production, merge to `master`, W8, the Finance frontend redesign.

---

# Production Operator Verification

**Recorded:** 2026-09-28, after Report 48 was issued.

**Status of the original verdict:** the verdict above (**NOT PROVEN — LIVE QUEUE CONFIGURATION MUST BE VERIFIED**) was correct on the evidence available when it was written. It is kept unchanged as the historical record. This addendum supersedes it as the **current** classification.

**Source:** an authorised operator ran the §9 read-only checks on the production host. This environment made no production connection.

## Evidence

| Check | Result |
|---|---|
| `php artisan config:show queue.default` | `database` |
| `queue:work` process | none |
| Queue or scheduler cron | none |
| Persistent worker service | none verified |
| `php artisan queue:failed` | **failed:** `woodnork_erp.failed_jobs` does not exist |
| Read-only table inspection | `jobs`, `failed_jobs` and `job_batches` **do not exist** |
| `php artisan migrate:status` | `ERROR Migration table not found.` |

## Current classification

### CASE C CONFIRMED — ASYNC DATABASE DRIVER + NO WORKER

### DATABASE QUEUE TABLES ABSENT

### LARAVEL MIGRATION HISTORY ABSENT

**The queue release precondition currently FAILS.**

- **Backlog:** there is none to replay. `jobs` does not exist, so no queued job was ever stored. That does **not** prove no Finance effects were missed. It proves only that nothing survives to be replayed. §5's backlog hazard therefore does not arise.
- **Historical behaviour:** the missing table changes it. A `database`-driver dispatch writes to `jobs`, so each dispatch **threw** instead of queueing silently. Report 49 §14 traces the consequence for each listener.
- **Classification of the fault:** this is **production deployment/infrastructure debt**, not a W7 or Phase 2B defect.
- **Precondition:** the missing migration ledger now comes before the queue correction. `migrate --force` is unsafe on this database until a verified baseline exists.

Continued in **Report 49** (`49_PHASE_2B_PRODUCTION_SCHEMA_BASELINE_AND_QUEUE_RESOLUTION.md`).
