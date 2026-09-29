# 45 — Phase 2B: B1 Resolution + Staging Validation Gate

**Date:** 2026-09-28
**Inputs:** Report 44 (CONDITIONAL PASS); WNG B1 decision (this directive); both repositories on `finance/critical-stabilization-fixes`.
**Boundary:** No push to `master` or `staging`, no production access, no production migration, no W8.
**Verdict:** **BLOCKED — STAGING VALIDATION REQUIRES HUMAN/INFRASTRUCTURE ACTION** (B1 resolved) (§36).

---

## 1. Executive Summary

**B1 is resolved.** `Accounts` now holds `finance.receivables.invoice_check` through the canonical role matrix (`RolePermissions::matrix()`), applied by `php artisan permissions:sync`. The preparer-cannot-self-check rule keeps segregation per invoice. Five new tests exercise Scenarios A–E through the real `Accounts` role and pass. The full backend and frontend regressions, ENG-1 and the build all remain green.

**Staging validation could not be performed** from this environment, and weaker evidence has not been substituted.
- **No access:** there is no SSH access to the deployment host and no GitHub CLI, so staging's migration status, database, logs and deploy results cannot be observed.
- **Stale and data-less:** staging is 223 commits and 139 migrations behind production.
- **No production data:** no production-like database copy is available.

Pushing to `staging` would run 162 unobserved migrations (139 parity + 23 Phase 2B) against a database of unknown content. That would prove nothing about production safety.

**Verdict: BLOCKED — STAGING VALIDATION REQUIRES HUMAN/INFRASTRUCTURE ACTION.** §8 and §34 give the exact actions.

## 2. Report 44 Preconditions

| Item | Status after this task |
|---|---|
| B1 invoice-check holder | **RESOLVED** (Accounts, via the role matrix; SoD proven) |
| B2 production backup + migration snapshot | Not performed (production control; procedure ready, Report 44 §20) |
| B3 production preflight P0–P10 | Not performed (production control; queries ready, Report 44 §4) |
| Staging trial (strongly recommended) | **BLOCKED** (§6–§9) |

## 3. B1 Decision

WNG decision (2026-09-28): the invoice checker is an Accounts/Finance role with independent checking authority, separate from the invoice preparer.

The repository's role catalogue (`RolePermissions::ROLES`) has a single `Accounts` role ("Financial accounting and invoicing") and no separate Finance-checker role. The existing `Costing` and `Manager` roles are not Finance-checker roles. Per the directive, `Accounts` receives the permission. Super Admin remains an administrative fallback through its bypass. No person-specific permission was created.

## 4. Invoice Checker Implementation

- **`app/Constants/RolePermissions.php`:** `Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK` added to the `Accounts` entry of `matrix()`, the canonical source that `permissions:sync` and `RoleAndPermissionSeeder` apply. No ad-hoc migration grant was added.
- **Already correct and unchanged:**
  - The permission constant (`Permissions.php`).
  - The route middleware `CheckPermission:finance.receivables.invoice_check` on `POST …/invoices/{invoice}/check`.
  - `EnquiryController::checkProjectInvoice()`, which refuses the preparer ("someone else has to check it").
  - `issueProjectInvoice()`, which aborts 422 on an unchecked invoice.
  - The frontend `EnquiryFinanceModal.vue`, which gates the Check action on `can('finance.receivables.invoice_check')`.
- **Applied on local dev** (DDEV only):
  - `permissions:sync --dry-run` reported exactly one change: "Accounts gains 1: + finance.receivables.invoice_check".
  - The real sync applied it, leaving the holders as Super Admin and Accounts.
  - A second dry-run reported "Already in sync — nothing to do", so the grant is idempotent.
- **Not resolved** (unchanged, per the directive): ROLE-2, ROLE-3, the W2/W4 senior-approval owners, petty-cash exception holders, and W6 reopen ownership.

## 5. Segregation-of-Duties Verification

New tests in `tests/Feature/Finance/InvoiceReviewWorkflowTest.php` use users holding the **real `Accounts` role built from `RolePermissions::matrix()`**:

| Scenario | Test | Result |
|---|---|---|
| Accounts role holds the permission | `test_b1_the_accounts_role_holds_the_invoice_check_permission` | PASS |
| A: Accounts user A prepares, then A checks | `test_b1_scenario_a_an_accounts_preparer_cannot_check_their_own_invoice` | **Rejected (422)**; `checked_at` stays null |
| B + D: A prepares, Accounts user B checks, then issue | `test_b1_scenario_b_and_d_another_accounts_user_checks_then_the_invoice_issues_once` | **Check succeeds** (`checked_by` = B); **issue succeeds**; exactly **one** journal entry for the invoice |
| C: user without the permission checks | `test_b1_scenario_c_a_user_without_the_check_permission_gets_403` | **403** |
| E: unchecked invoice, issue attempted | `test_b1_scenario_e_an_unchecked_invoice_cannot_be_issued_by_accounts` | **Rejected (422)**; stays draft; no journal |

The existing ad-hoc-grant tests (self-check refused even with the permission, return/resubmit, checker may issue) still pass.

- **`InvoiceReviewWorkflowTest`:** 28 tests, 99 assertions, all pass.
- **Conclusion:** giving the permission to all of Accounts does **not** allow self-checking.

## 6. Staging Environment Before (re-audited 2026-09-28, not assumed from Report 44)

| | Backend | Frontend |
|---|---|---|
| Branch | `origin/staging` | `origin/staging` |
| Commit | `d784899` (2026-08-25) | `9a3a3a8` (2026-08-26) |
| Behind production (`origin/master`) | **223 commits**, **139 migration files** | **186 commits** |
| Ahead of master | 0 | 0 |
| Migration status | **Not observable**: requires SSH to the host | n/a |

- **Staging database engine and version:** not observable. Staging runs on the same host as production (`deploy.yml` uses one `SSH_HOST` secret) under `~/erp-backend-staging`. Its database credentials are in that directory's `.env` on the server.
- **Data content:** unknown. No evidence it is a production-like copy.
- **External reachability:** `https://stagingapi.woodnorkgreen.co.ke` and `https://staging.woodnorkgreen.co.ke` return HTTP 200, but behind the host's bot-protection interstitial ("One moment, please…"), so application state cannot be read externally.

**Access available to this environment:**
- SSH `known_hosts` contains only `github.com`; this machine has never connected to the deployment host.
- No SSH host configuration exists.
- The GitHub CLI is not installed, so Actions runs and logs cannot be read.
- The only staging mechanism available is `git push origin <ref>:staging`, which triggers an **unobservable** deploy.

## 7. Production-Parity Preparation

**Not performed.** Bringing staging to parity means pushing `origin/master` to `staging`, which would run **139 migrations** in `migrate --force` against the unknown staging database. This environment could neither see the outcome nor recover from a failure.

Report 44 §21 ordered the steps as *restore a production copy first, then fast-forward*. Doing the fast-forward without the restore reverses that order and produces a staging database that is neither production-like nor inspectable.

## 8. Production-Like Data Status

### BLOCKED — RECENT PRODUCTION-LIKE STAGING DATA REQUIRED

No production copy is available, and obtaining one needs production credentials that this environment neither has nor may use. A development database was **not** substituted.

**Exact procedure for Engineering/WNG** (hosting account holder):
1. On the host, take a consistent dump of the production database, using the credentials in `~/erp-backend-master/.env`: `mysqldump --single-transaction --routines --triggers <prod_db> > prod-copy-<date>.sql`. If policy requires it, sanitise personal data (employee IDs, KRA PINs, bank details, phones) with a reviewed script; keep all financial tables intact.
2. Restore it into the **staging** database named in `~/erp-backend-staging/.env`, never the production database: `mysql <staging_db> < prod-copy-<date>.sql`.
3. Point staging's frontend and backend at that database (already the case per `.env`), and disable outbound email/SMS on staging (`MAIL_MAILER=log`).
4. Fast-forward staging code to production: `git push origin origin/master:staging` in **each** repo. Watch both GitHub Actions runs. The backend run should report "Nothing to migrate", because the dump already contains production's migrations table. **Any migration output here means staging code and data are not at parity; stop and investigate.**
5. Give the validating engineer one of the following:
   - SSH access to the host (for `migrate:status`, logs and the preflight), or
   - a staging database login plus the Actions logs.
6. Then run §9 onward of this gate (preflight → Phase 2B backend push to `staging` → `permissions:sync` → frontend push → smoke tests → integrity checks).

## 9. P0–P10 Results

**Not run on staging:** no staging database access, and no production-like data. The queries are unchanged from Report 44 §4 and were already validated for syntax on dev. They are **not** reported as staging evidence here.

| Preflight | Expected | Actual | PASS/FAIL | Notes |
|---|---|---|---|---|
| P0–P10 | Report 44 §4 | — | **NOT RUN** | Blocked by §8 |

## 10–15. Migration Trial, Migration #14, Permission Sync, Backend Deploy, Frontend Deploy, Migration Integrity (on staging)

**Not performed on staging**, since each depends on §8. What *is* established:
- **Migrations:** all 23 migrations run cleanly on dev and test databases. The 1,427-test suite migrates from empty on every run, and #23 was verified up → down → up.
- **Permission sync:** it behaves as intended on dev (§4).
- **Not established:** behaviour against production data volumes and values. That is precisely what the staging trial exists to prove, especially for migration #14 (ENUM `MODIFY` + backfill, non-atomic).

## 16–23. W1–W7 and General Finance Smoke Tests (on staging)

**Not performed on staging.** The automated equivalents pass (§27): W1 invoice lifecycle and SoD, W2 procurement (178 tests), W3/W4/W5 (Wave 3 suites), W6 costing (Cost Collector 260 tests), W7 labour (84 tests), and ledger/reports/periods suites. The manual smoke list (Report 44 §22) remains the staging and production checklist.

## 24. Technical Labour Compatibility

Verified from code, which needs no staging:
- **Which screens are affected:** only `OvertimeRequestModal.vue` and `CompensationRequestModal.vue` call `/api/hr/technical-labour`. They sit in the HR overtime area, which WNG states is not in operational use.
- **Employee Records:** not affected. `/api/hr/employees/compact` and `/api/hr/employees/directory` are separate routes that remain active.
- **W7:** does not depend on those screens. It uses `employees` via `ProjectLabourActualService` and the directory endpoint, as proven by the W7 suite and the `teamsTaskEmployeePicker` spec.
- **Effect of the failure:** only those two modals' personnel pickers render empty.

Recorded as **deferred Technical Labour cleanup**.

## 25. Log Review

Not possible for staging (no access). Local test runs produced only dependency deprecation notices and seeding warnings for fresh test databases, already classified as NON-BLOCKING LEGACY in Reports 40–44. There were no Phase 2B 500 errors in any automated run.

## 26. Database Integrity

Not possible for staging. On test databases the integrity guarantees are asserted by the suite: one journal per invoice (new Scenario D), one CostLine per source (W7 races), no labour GL movement (W7 ledger-delta test), and one payment per settlement.

## 27. Backend Regression

Full suite after B1 (DDEV, JUnit `storage/logs/b1-gate-junit.xml`): **1,432 tests, 9,440 assertions, 0 failures, 0 errors, 0 skipped**, 8 min 20 s. That is Report 44's 1,427 / 9,421 plus the 5 new B1 tests. The permission registry, receivables and invoice workflow suites are included.

## 28. Frontend Regression

- Finance specs: **56 passed**.
- Complete unit suite: **27 files, 166 tests, all passed**.
- No frontend code changed in this task.

## 29. ENG-1

`vue-tsc --build --force`: **256 errors**, identical to the baseline set. **0 new.** PASS.

## 30. Production Build

`vite build`: **exit 0**, 1,891 modules, 29.5 s. Same pre-existing warning classes as Report 44 §16.

## 31. Defects Found

- **Carried from Report 44:** B1 (no operational invoice checker). No new code defects were found.

## 32. Defects Corrected

- **B1:** `Accounts` → `finance.receivables.invoice_check` in `RolePermissions::matrix()`, with five SoD tests.

## 33. Deferred Issues

- The overtime/compensation modals call the removed Technical Labour route (§24).
- Latent `v-model`-on-const in `ReceiveStockModal`/`ResolveMaterialModal` (Report 44 §16).
- The phantom `Operations` role in the W7 permission migration.
- ROLE-2, ROLE-3 and the other governance holders.
- The ENG-1 legacy TypeScript debt.
- No CI tests, backup or rollback in `deploy.yml`.

## 34. Remaining Production Preconditions

1. **Staging trial (to complete this gate):**
   - WNG/hosting performs §8 steps 1–5: a production copy restored into staging, staging fast-forwarded to master with "Nothing to migrate", and host or staging-DB access for the validator.
   - The validator then runs P0–P10, pushes Phase 2B to `staging` backend-first, runs `permissions:sync`, pushes the frontend, runs the smoke tests (Report 44 §22) and checks logs and integrity.
   - Result: a Report 45 re-run to PASS.
2. **B2:** a verified production backup and migration snapshot at release time (Report 44 §20).
3. **B3:** production P0–P10 clean at release time (Report 44 §4).
4. **Release step (added by B1):** run `php artisan permissions:sync` (after `--dry-run`) on production after the migrations, so that `Accounts` receives `invoice_check`. The step is already in the Report 44 runbook (step 9); **it is now mandatory for W1 invoicing to work.**
5. **Confirm overtime/compensation remain unused** at release.

## 35. Production Release Recommendation

**Do not release to production yet.**

- **Ready:** the B1 blocker is cleared and the code is release-ready: tests, ENG-1 and the build are green, with no new defects.
- **Still missing:** migration safety against real data (especially migration #14) remains unproven. The only safe way to prove it without touching production is the staging trial on a production copy, which needs the human actions in §8.
- **If WNG accepts releasing without a staging trial:** that is a governance decision. The release would then rely on B2 (verified backup) as the recovery path, and B3 (production preflight) as the only data check. This report does not recommend it.

## 36. Final Verdict

### BLOCKED — STAGING VALIDATION REQUIRES HUMAN/INFRASTRUCTURE ACTION

**Required actions:** §8 steps 1–5.
- The hosting account holder restores a recent production copy into the staging database.
- Staging code is fast-forwarded to production master, confirming "Nothing to migrate".
- The validator receives host or staging-database access and Actions logs.

After that, this gate is re-run from §9.

B1 is resolved and verified. No production action was taken.

---

## PHASE 2B STAGING POSITION

**B1 Invoice Checker:** RESOLVED. `Accounts` → `finance.receivables.invoice_check` via `RolePermissions::matrix()` / `permissions:sync`
**Segregation of Duties:** VERIFIED. Scenarios A–E pass through the real Accounts role (self-check 422, independent check OK, no permission 403, checked issues with one journal, unchecked refused)
**Staging Production Parity:** NOT ACHIEVED. Staging is 223 commits and 139 migrations behind master; pushing it is unobservable from this environment
**Production-Like Data:** BLOCKED. No production copy available; human/hosting action required (§8)
**P0–P10:** NOT RUN ON STAGING (blocked); queries ready and syntax-validated
**23 Migrations:** Clean on dev/test databases; NOT TRIALLED ON STAGING/PRODUCTION DATA
**Migration #14:** NOT TRIALLED ON PRODUCTION-LIKE DATA (still HIGH risk until it is)
**Permission Sync:** Verified on local dev (one change: Accounts gains invoice_check; idempotent); not run on staging
**W1:** Automated PASS (28 tests incl. B1 SoD); staging smoke NOT RUN
**W2:** Automated PASS (procurement 178 tests); staging smoke NOT RUN
**W3:** Automated PASS; staging smoke NOT RUN
**W4:** Automated PASS; staging smoke NOT RUN
**W5:** Automated PASS; staging smoke NOT RUN
**W6:** Automated PASS; staging smoke NOT RUN
**W7:** Automated PASS (84 tests); staging smoke NOT RUN
**Backend Regression:** 1,432 tests, 9,440 assertions, 0 failures, 0 errors, 0 skipped
**Frontend Regression:** 27 files, 166 tests, all passed
**ENG-1:** 256 legacy errors, 0 new. PASS
**Production Build:** Success (exit 0, 1,891 modules)
**Staging Verdict:** BLOCKED — STAGING VALIDATION REQUIRES HUMAN/INFRASTRUCTURE ACTION
**Production B2:** NOT PERFORMED
**Production B3:** NOT PERFORMED
**Production Deployment:** NOT PERFORMED
**W8 Started:** NO

---

# Staging Validation Resumption

**Attempted:** 2026-09-28, after WNG reported that the §8 infrastructure prerequisites were complete. The original BLOCKED verdict above is preserved.

## R1. Independent verification of the reported human actions (§1 of the resumption directive)

| Prerequisite | How verified | Finding | Status |
|---|---|---|---|
| **A. Production-like staging database** | Requires staging DB or host access | Cannot be observed from this environment: no DB credentials, no SSH, and the staging API sits behind the host's bot-protection interstitial | **UNVERIFIED** |
| **B. Staging code parity** | `git fetch` in both repos, then `origin/staging` vs `origin/master` | **Not aligned.** Backend `origin/staging` is still `d784899` (2026-08-25), **223 commits** behind master `60491b1`. Frontend `origin/staging` is still `9a3a3a8` (2026-08-26), **186 commits** behind master `d24a3f7`. Identical to the original audit (§6), so the fast-forward (§8 step 4) has **not** happened | **NOT DONE** |
| **C. Migration parity** (`migrate:status` at master) | Requires host access | Not observable. And since staging code is not at master (B), parity cannot hold | **NOT DONE / UNVERIFIED** |
| **D. Validator access** | Local environment check | SSH `known_hosts` still contains only `github.com`. No `~/.ssh/config` host entry. GitHub CLI not installed. No MySQL client or staging DB credentials. No new files in the workspace since the original gate. No staging-related environment variables | **NOT PROVIDED** |

Per the resumption directive §1D, the gate stops here. **No push to `staging` was made**, no migration was run, and nothing was changed. Sections §3–§22 of the resumption directive (P0–P10, snapshot, deploy, migrations, permission sync, smoke tests, logs, integrity) were **not executed**, because each depends on A–D. The development and test evidence (backend 1,432 / 0 failures, frontend 166, ENG-1 256/0, build) is **not** offered as staging evidence.

## R2. Remaining actions, precisely

Complete **all** of the following, then re-run this resumption:

1. **Restore:** restore a recent production copy into the staging database (§8 steps 1–3). Note the date, and whether and how it was sanitised.
2. **Parity:** `git push origin origin/master:staging` in **ERP-Backend and ERP-Frontend**, then confirm the backend Actions run reports **"Nothing to migrate"**. After this, `origin/staging` must equal `origin/master` (`60491b1` / `d24a3f7`). That is checkable from here, and it is currently false.
3. **Access:** provide **one** of these:
   - **(a) Host access:** add this machine's public key (`~/.ssh/id_ed25519.pub`) to the hosting account's authorised keys, and supply the host, port and user. That lets the validator run `migrate:status`, the preflight, `permissions:sync` and log reads directly.
   - **(b) Operator relay:** an operator runs the read-only script in R3 on the host at each step and supplies the complete output. It must be complete and unedited, and include the Actions logs for every staging deploy.
4. **Deploy logs:** install and authenticate the GitHub CLI (`gh auth login`) on this machine, or export the Actions logs of each staging deploy.

## R3. Read-only staging evidence script (for option 3b; run on the host in `~/erp-backend-staging`)

Nothing below writes to the database. Record the output before Phase 2B (baseline) and again after it.

```bash
cd ~/erp-backend-staging
git rev-parse HEAD
php artisan --version
php artisan migrate:status | tail -40
php artisan tinker --execute="echo DB::connection()->getDatabaseName(), PHP_EOL;"   # name only, no credentials
# Report 44 §4 preflight P0–P10 (paste the SQL block from Report 44 into a file first):
mysql --defaults-extra-file=<(printf "[client]\nuser=%s\npassword=%s\nhost=%s\n" "$DB_USERNAME" "$DB_PASSWORD" "$DB_HOST") "$DB_DATABASE" < preflight.sql
# Snapshot counts:
mysql ... "$DB_DATABASE" -e "SELECT 'project_invoices',COUNT(*) FROM project_invoices UNION ALL SELECT 'purchase_orders',COUNT(*) FROM purchase_orders
 UNION ALL SELECT 'payments',COUNT(*) FROM payments UNION ALL SELECT 'petty_cash_requisitions',COUNT(*) FROM petty_cash_requisitions
 UNION ALL SELECT 'project_enquiries',COUNT(*) FROM project_enquiries UNION ALL SELECT 'cost_lines',COUNT(*) FROM cost_lines
 UNION ALL SELECT 'journal_entries',COUNT(*) FROM journal_entries UNION ALL SELECT 'spend_vouchers',COUNT(*) FROM spend_vouchers
 UNION ALL SELECT 'bills',COUNT(*) FROM bills UNION ALL SELECT 'bill_payments',COUNT(*) FROM bill_payments;"
tail -200 storage/logs/laravel.log
```

`DB_*` values are read from staging's `.env`, e.g. `set -a; . ./.env; set +a`. They are never printed.

## R4. Resumption verdict

### BLOCKED — STAGING OBSERVABILITY STILL INCOMPLETE

The reported prerequisites could not be verified. Staging code parity is demonstrably **not** in place (R1-B), and no validator access exists (R1-D). Evidence standards were not weakened.

B1 remains resolved. No production action was taken (B2 and B3 not performed, no deployment). W8 has not started.

**Transition:** BLOCKED (original gate) → prerequisites reported complete → independent verification failed (parity not done; no access) → **BLOCKED**.

**Next:** complete R2, then re-run this resumption from §1.
