# 43 — Phase 2B Workflow 7 Labour Cost: Final Closure Report

**Date:** 2026-09-28
**Inputs:** Reports 40, 41 and 42; Decision Register `03`; both repositories on `finance/critical-stabilization-fixes`.
**Verdict:** **PASS — W7 CONFIRMED ANALYTICAL SUBSET CLOSED** (§34).

---

## 1. Executive Summary

Report 41 left W7 open on a single item: the frontend repository type-check, with no formally accepted baseline. WNG/Engineering has now adopted the documented pre-Phase-2B state as the legacy baseline: **256 pre-existing errors**, with a **zero-new-errors** rule for Finance redesign work.

- **Correction:** the only two Finance/Phase-2B type errors were in `useOrderWorkflow.ts`. They are fixed at the type level, with no suppression or `any`.
- **Type-check:** now **256 errors, exactly the committed-HEAD set**, with 0 new errors and 0 in any W7-touched file.
- **Regression:** the full W7 evidence set and complete regressions were re-executed and pass.

W7 closes on its confirmed analytical subset: **W7-1 through W7-14**, the scope set in Reports 40/41. W7-15 to W7-23 remain confirmed attribution rules and are not claimed here as separately implemented. Rework tagging (W7-19/20) and work-phase classification (W7-17) are implemented; W7-15 project overtime depends on the overtime module, which WNG states is not in use. W7-24, W7-25 and W7-26 remain open. Nothing was merged to `master` or deployed.

## 2. Why Report 41 Failed

All Report 40 functional and evidence items were met. The frontend `vue-tsc` failed with 258 errors: 256 pre-existing, 2 from uncommitted W2-era procurement work, and 0 from W7. No accepted baseline existed, and the gate forbade inventing one.

## 3. Report 42 Evidence

Report 42 (§8) established four facts:
- **A pre-W7 baseline measurement exists.** Report 31, 2026-09-23: 256 errors, all pre-existing.
- **Earlier closures accepted it implicitly.** W1–W3 and W6 closed without a clean type-check.
- **The engineering rule does not name type-check.** The criterion first became blocking in the W7 directives.
- **The debt lies outside Finance.** It sits in repository-wide modules; W7 contributes 0 errors.

## 4. Engineering Baseline Decision

Decided by WNG/Engineering (directive of 2026-09-28, recorded in the Decision Register as **ENG-1**):

> The Finance redesign inherited a documented frontend repository baseline of 256 pre-existing TypeScript errors. Finance workflow closure uses a zero-new-errors rule against that baseline until the repository-wide TypeScript debt is addressed separately.
>
> This baseline does not classify the legacy errors as correct or permanently acceptable.

The rule applies to W8 onward unless WNG/Engineering explicitly changes it.

## 5. Legacy TypeScript Debt Boundary

The 256 errors are **pre-existing repository technical debt**. They are not charged against a Finance workflow's closure unless that workflow modifies, or directly depends on, the defective code. They sit mainly in universal-task, production/work orders, projects, printing, logistics and assets, plus one in `finance/shared/components/FinanceNavigation.vue`, which predates Phase 2B and was not touched by W7. They were not modified in this task, and their cleanup is tracked separately (§31).

## 6. Two Phase-2B Errors Found

`src/modules/procurement-stores/shared/composables/useOrderWorkflow.ts`:
- **(168,11):** `'correct'` is not assignable to `NextStep['key']`.
- **(176,11):** `'senior-approve'` is not assignable to `NextStep['key']`.

**Cause:** `nextStepFor()` was extended for W2-6 (a returned order's *Correct* step) and W2-1 (the *Senior approve* step), but the `NextStep.key` union was not widened.

## 7. Corrections Applied

The `NextStep.key` union now includes the two real, supported workflow steps:

```ts
key: 'correct' | 'submit' | 'senior-approve' | 'approve' | 'receive' | 'stores' | 'bill' | 'payment'
```

Both steps are backed by real actions: `POST /purchase-orders/{id}/senior-approve` (backend route present), and the W2-6 correction route to the order page. No consumer switches exhaustively on this key, so the change is safe. There is no `any`, no `@ts-ignore` and no loosening of the type.

## 8. Type-Check Before

`vue-tsc --build --force`: exit 2, **258 errors** (Report 41 §27).

## 9. Type-Check After

`vue-tsc --build --force`: exit 2, **256 errors**.

## 10. Zero-New-Errors Verification

The error sets were normalised by removing positions and path prefixes, then compared with a clean `git archive` copy of committed HEAD run against the same `node_modules`:

- errors in current not in HEAD: **0**
- errors in HEAD not in current: **0**. The sets are identical.
- errors in W7-touched files (cost-collector types/composable/panel, TeamsTask, ReportTask, taskService, useTeams, RequisitionForm, W7 specs): **0**
- errors in `useOrderWorkflow.ts`: **0**

## 11. W7 Functional Re-Verification

The only production code changed since Report 41 is the frontend type union above. No backend code changed. Every W7 guarantee was re-proven by re-executing its tests (§12–§27).

## 12. Backend W7 Tests

Executed in DDEV (PHP 8.4, MariaDB 11.8, db_test) as part of the full run; JUnit log `storage/logs/w7-final-junit.xml`:

| Suite | Tests | Assertions | Failures | Errors | Skipped |
|---|---|---|---|---|---|
| `W7LabourCostTest` | 79 | 341 | 0 | 0 | 0 |
| `W7LabourConcurrencyTest` | 5 | 59 | 0 | 0 | 0 |
| **W7 total** | **84** | **400** | **0** | **0** | **0** |
| Cost Collector (W6/W7 relevant: `tests/Feature/CostCollector`) | 260 | 3,350 | 0 | 0 | 0 |
| of which `CostTransferTest` / `PortfolioMarginTest` / `ProjectFinancialClosureTest` | 7 / 3 / 6 | 30 / 19 / 36 | 0 | 0 | 0 |

db_test was verified clean after the concurrency suite (0 labour actuals, 0 cost lines, 0 race roles).

## 13. Backend Full Regression

`vendor/bin/phpunit`, complete run: **1,427 tests, 9,421 assertions, 0 failures, 0 errors, 0 skipped**, exit 0, 8 min 22 s. These match Report 42's figures, because no backend code changed in this task. Procurement (the area of the frontend correction): 178 tests / 602 assertions, all passed.

## 14. Frontend W7 Tests

`tests/unit/finance/wave7Labour.spec.ts`: **1 file, 23 tests, 23 passed, 0 failed.**

## 15. Frontend Full Regression

- Finance and procurement specs (`tests/unit/finance`, `tests/unit/procurement`): **10 files, 91 tests, all passed.** This covers the changed procurement composable's area.
- Complete Vitest suite: **27 files, 166 tests, all passed, 0 failed, 0 skipped.**

## 16. Production Build

`vite build`: **exit 0**, 1,891 modules, 28.75 s. The warnings are the same pre-existing classes recorded in Report 41 §28:
- chunk size
- mixed dynamic/static imports of `axios` and `useAuth` (cost-collector files appear only in those repository-wide importer lists)
- constant reassignment in `ReceiveStockModal.vue` and `ResolveMaterialModal.vue`

None is W7-caused. Build success is recorded separately from type-check.

## 17. Server Authority

Re-proven by `test_explicit_rate_tampering_200_and_20000_is_ignored`, `test_server_overrides_client_supplied_budget_fields_on_record`, `test_http_store_rejects_client_tampered_rate` and `test_project_a_actual_cannot_use_project_b_budget_line`. Role, category, unit and rate come only from the approved budget line. A forged KES 200 or KES 20,000 still yields 2,000.00. A cross-project line ID is rejected on record and on resubmit.

## 18. Costing Integrity

Analytical Actual CostLines are created only by Finance verification, with `postsIndependently=false` (`test_cost_line_postsindependently_false`). Budget-line consumption is computed from verified CostLines. Pending cost is shown separately.

## 19. GL Non-Duplication

`test_finance_verification_moves_project_labour_but_not_the_company_ledger`. Journal entries, journal lines and payroll-expense (5200/7550) balances are unchanged by verification and by the correction pair, while project labour moves 0 → 16,000 → 14,000.

## 20. Corrections / W7-13

`test_correction_20000_to_16000_project_costing_is_16000`: Project Costing reads 20,000 before, 20,000 while pending, and 16,000 after. `test_correct_creates_reversing_pair` shows the CL-TRF-OUT/CL-TRF-IN pair with the original line unmodified. Also passing: the correction-chain test and reclassification via `CostTransferService::transfer()`.

## 21. Concurrency

`W7LabourConcurrencyTest` uses forked processes with separate connections and a barrier. Five races each yield one authoritative outcome: Finance verify ×4, correct ×4, correction verify ×4, rate resolution ×4, and W6 transfer ×4.

## 22. Budget Revision Immutability

`test_budget_revision_keeps_historical_rate_and_new_labour_uses_v2`. The V1 actual keeps KES 2,000; an actual recorded under V1 and verified after V2 stays at 2,000; a new actual is KES 2,500.

## 23. Project Costing

The monetary matrix tests all pass:
- Normal: 20,000 / 16,000 / 4,000
- Over budget: 20,000 / 24,000 / −4,000, not capped
- Unused budget: 50,000 / 30,000 / 20,000
- Correction: 16,000

## 24. Portfolio Reconciliation

`test_portfolio_actual_labour_equals_sum_of_project_statements` (20,500.00 = 20,500.00) and `test_portfolio_query_count_does_not_grow_with_projects`.

## 25. Payroll Privacy

`test_labour_api_payloads_never_expose_payroll_data` and `test_resource_never_serializes_salary_or_bank_fields` on the backend. On the frontend, the privacy test confirms only the employee's name and staff number render.

## 26. Closed-Project Controls

`test_closed_project_blocks_every_labour_transition` plus the per-transition closure tests on the backend. On the frontend, the closed-state test (banner shown, no actions offered).

## 27. Audit Trail

`test_every_labour_event_is_audited_with_actor_and_timestamp` covers record, return, resubmit, PO verify, Finance verify, over-budget alert, correct, correction requested, supersede, reclassify and rate resolution. Return cycles are also kept in the immutable history table.

## 28. Technical Labour Boundary

Unchanged from Report 41 §4:
- In-use worker selection (petty cash, Teams, project report) uses Employee Records.
- Historical schema is retained.
- Per WNG instruction, work orders, overtime/compensatory leave and Technical Labour screens are out of scope as not in use. Their residue is classified, not modified.

## 29. Open Accountant Decisions

These do not block closure of the analytical subset:
- **W7-24:** GL/WIP project-dimension treatment.
- **W7-25:** standard-cost vs actual payroll variance accounting.
- **W7-26:** employer statutory-cost absorption.

Separately, the non-blocking policy question from Report 41 §31 stands: whether WNG wants a standing unbudgeted-labour rate source.

## 30. Decision Register Changes

In place, in `03_WNG_FINANCE_DECISION_REGISTER.md`:
- **New Engineering Rule addendum (ENG-1):** the 256-error legacy baseline plus the zero-new-errors rule, applying to W8 onward.
- **W7 gate paragraph:** now records the final re-closure PASS for the confirmed analytical subset, while keeping the Report 40/41 history.
- **W7-24/25/26:** unchanged.
- **WNG's W7-10 scope instruction:** recorded (work orders, overtime and Technical Labour not in use and left out).

Report 41 has a dated appended section; its original FAIL verdict is preserved.

## 31. Technical Debt Carried Forward

- **Legacy TypeScript debt:** 256 errors, tracked separately. They are not Finance closure debt under ENG-1.
- **Build defects in procurement:** constant-reassignment runtime defects in `ReceiveStockModal.vue` and `ResolveMaterialModal.vue`.
- **Test and CI gaps:** CI runs no tests or type-check; there are no backend tests for Production or Teams.
- **Technical Labour residue** in the unused modules.
- **Crew identity:** `teams_members` has no `employee_id` FK.
- **Documentation reconciliation items** from Report 42 §22.
- **Phase 2B release:** 23 migrations, a separate controlled release gate.

## 32. Files Modified

- **Frontend:** `src/modules/procurement-stores/shared/composables/useOrderWorkflow.ts` (type union only).
- **Backend docs:** this report; `41_PHASE_2B_W7_REMEDIATION_AND_RECLOSURE_REPORT.md` (appended section); `03_WNG_FINANCE_DECISION_REGISTER.md`. Report 42 was also committed alongside.

No backend code, migration, or other frontend file was changed.

## 33. Closure Assessment

| Success criterion | Result |
|---|---|
| 2 Phase-2B type errors corrected | Met |
| Type-check matches the 256 legacy baseline | Met (256; set identical to HEAD) |
| No W7-touched file has a type error | Met (0) |
| No new Finance-redesign type errors | Met (0 new vs HEAD) |
| W7 backend tests pass | Met (84/84; 400 assertions) |
| Full backend regression passes | Met (1,427/0 failures) |
| W7 frontend tests pass | Met (23/23) |
| Full frontend unit regression passes | Met (166/166) |
| Production build succeeds | Met |
| Previous W7 integrity guarantees remain proven | Met (84/84; 400 assertions) |

## 34. Final Verdict

### PASS — W7 CONFIRMED ANALYTICAL SUBSET CLOSED

Every success criterion in §33 is met:
- The two Finance/Phase-2B type errors are corrected, and the type-check equals the documented 256-error legacy baseline, with 0 new errors and 0 in W7-touched files.
- W7 backend (84), full backend (1,427), W7 frontend (23) and full frontend (166) all pass.
- The production build succeeds.
- Every prior W7 integrity guarantee is re-proven.

**Still open:** W7-24, W7-25 and W7-26 (accountant decisions), which do not block the analytical subset.

**Not done, by instruction:** no merge to `master`, no deployment, no production migrations, and W8 not started. The next activity is the **Phase 2B Controlled Release Readiness Gate**.
