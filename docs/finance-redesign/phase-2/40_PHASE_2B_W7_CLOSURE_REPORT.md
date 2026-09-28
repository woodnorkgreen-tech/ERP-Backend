# 40 — Phase 2B Workflow 7 Labour Cost: Independent Closure Gate

**Date:** 2026-09-27  
**Verdict:** **FAIL — W7 REMAINS OPEN**

## 1. Executive Summary

The confirmed analytical W7 subset has working backend foundations and an executable DDEV runtime. Independent testing found and fixed two bounded defects. The end-to-end closure rule is not met: active Technical Labour operations remain, the frontend cannot complete the returned, unbudgeted rate-resolution, or verified-correction paths, and the repository type-check fails. Report 39's closure-readiness verdict is rejected.

## 2. Closure Scope

This gate covers confirmed W7-1 through W7-14, W6 integration, backend and frontend execution, and Technical Labour retirement. W7-24, W7-25, and W7-26 are excluded pending accountant/HR decisions. W8 was not started.

## 3. Evidence Reviewed

Read the current Decision Register, Reports 37–39, W6 architecture/implementation/closure Reports 34–36, and the backend and frontend repositories. Inspected the W7 service, controller, resource, model, migrations, Cost Collector, Cost Account service, routes, permissions, Vue panel/composable/types, and Technical Labour references. Report 39 was treated as a claim, not proof.

## 4. Report 39 Claim Verification

| Claim | Independent finding |
|---|---|
| Backend tests ready but PHP unavailable | DDEV provides PHP 8.4/MariaDB; W7 tests ran. |
| 22 W7 tests | The suite had 51 tests at first execution; this gate added four, reaching 55. |
| Frontend testing N/A | Incorrect. The separate frontend repository is present and the interactive workflow requires tests. |
| Production build pending | Build completed successfully, with unrelated procurement warnings. |
| Technical Labour retired | Incorrect; live backend and frontend paths remain. |
| End-to-end closure ready | Incorrect; functional UI and type-check blockers remain. |

## 5. Backend Runtime

The supported runtime is DDEV in `/home/cosmas/projects/ERP-Backend`, with PHP 8.4 and MariaDB 11.8. The shell's lack of local PHP is not a blocker. Existing dependency deprecation notices and fresh-test-database role seeding warnings were observed; they did not fail the W7 suite.

## 6. Server-Authoritative Budget Control

`ProjectLabourActualService::authoritativeBudgetData()` scopes approved `TaskBudgetData` through the current enquiry's task, resolves the labour line, and requires a verified planned CostLine in the same project. The service supplies role, category, unit, rate, budget ID, and consumed line ID. The controller strips client budget-derived fields. Existing executable tests cover forged budget fields and client rate tampering. A dedicated Project A/Project B malicious-ID test is still missing.

## 7. Rate Tampering

The W7 tests demonstrate that a client rate does not control a budgeted actual. The requested explicit KES 2,000 versus KES 200 and KES 20,000 API cases were not separately executed; closure credit is limited to the existing equivalent tampering tests.

## 8. Budget Version and Rate Immutability

Actuals store `budget_id`, `unit_rate`, `rate_source`, and `calculated_cost` snapshots. The Cost Collector budget-projector regression covers repricing of planned lines. No W7 integration test exercised V1 KES 2,000, revision to V2 KES 2,500, a historical actual, and a new actual together. This remains an evidence gap.

## 9. Unbudgeted Labour

The backend records unbudgeted labour with reason, no planned-line consumption, and unresolved rate; Finance-only rate resolution requires PO-verified status, source description, and authorization reference, and logs an audit event. It does not fall back to payroll salary. The per-actual Finance override is a technical mechanism; the confirmed decision does not explicitly approve a general rate policy. The frontend has no rate-resolution control, so the user cannot complete the lifecycle there.

## 10. Workflow Verification

Record → PO verify → Finance verify is executed in the W7 backend suite. Return for correction exists, but the backend has no resubmit endpoint; the current test simulates resubmission by directly updating model status. The UI also has no resubmit control. This is a material functional gap.

## 11. Permissions

Distinct view, record, PO verify, Finance verify, and correct permissions are defined and checked server-side by `ProjectFinancialAccess` and the controller/service. Finance verification also authorizes unbudgeted rate resolution. Capability implementation is distinguishable from WNG organizational role assignment, which remains governed by ROLE-1/ROLE-2; this gate did not invent staffing policy.

## 12. Payroll Privacy

`ProjectLabourActualResource` serializes a narrow employee object (ID, name, staff number), audit users, rate, and usage. It does not serialize salary, deductions, bank details, payslips, or payroll journals. The W7 resource privacy test passes.

## 13. One Economic Cost Once

W7 supplies `postsIndependently: false` to Cost Collector. Both `collect()` and `postFromSource()` guard `JournalPostingService::postCostLine()` on that flag. The W7 test asserts the resulting CostLine has no `journal_entry_id` or `posted_at`. A before/after company GL balance assertion was not executed, so the mandatory ledger-delta proof remains incomplete.

## 14. GL Verification

No W7 independent journal is created by the observed Cost Collector branch. W7-24/25/26 remain open; no GL/WIP, payroll variance, or statutory absorption policy was implemented here. The absence of an explicit company GL delta test limits closure evidence.

## 15. CostLine Integrity

Finance verification creates an analytical verified Actual CostLine and the retry test passes. The authoritative project amount is derived from verified, nonreversed CostLines by W6 Cost Account logic. A direct unique-result count under simultaneous Finance verification was not demonstrated.

## 16. Corrections

The service preserves the original actual, creates one linked successor, marks the original superseded, and reverses its CostLine; tests cover duplicate and stale correction. However, W7-13 confirms use of W6 Cost Transfer reversing architecture, while the implemented same-project correction directly changes the old line to `reversed` and creates a new W7 actual. The divergence needs reconciliation before closure. The exact KES 20,000 → KES 16,000 project-costing scenario was not executed.

## 17. Concurrency and Idempotency

Finance retry and correction uniqueness tests pass. Row locks and a unique `reversal_of_id` constraint are present. The test named concurrent correction makes serialized calls; it is not a simultaneous two-connection race proof. Duplicate-request retry beyond Finance verify was not proven.

## 18. W6 Closure Interaction

This gate found that PO verification, return, correction, and Finance rate resolution could proceed while a project was financially closed. `guardClosure()` was added inside the locked transactions. New PO verification, correction, and rate-resolution tests pass. Record and Finance verification already had closure guards. The UI still does not display a closed-project labour state.

## 19. Project Costing

W6 Cost Account sums verified Actual CostLines separately from planned labour and marks labour completeness only after a Finance-verified W7 actual exists. The requested exact normal, over-budget, unused-budget, and correction monetary scenarios were not all executed as integrated Project Costing assertions. This gate fixed a separate budget-line display defect: remaining cost had been clamped to zero, obscuring a negative overrun.

## 20. Portfolio Reconciliation

Portfolio margin reads the same CostLine pool and batches labour-completeness lookup rather than running one query per project. No W7 test reconciled portfolio Actual Labour to the sum of individual project statements for identical filters; no W7 N+1 measurement was performed.

## 21. Decimal Precision

The authoritative W7 cost formula uses BCMath and persists decimal fields. `getBudgetLabourLines()` still casts budget and consumed money to float for the UI response; the frontend sums and formats with JavaScript numbers. No fractional-rate/usage monetary-drift regression was executed. Do not treat decimal precision as fully verified.

## 22. Technical Labour Decommissioning

Historical schema, nullable foreign keys, model, and historical report reads are acceptable. Active operational dependencies remain: backend `ProductionAssigneeController` offers active TechnicalLabour choices; Team member create/update requests accept `technical_labour_id`; petty-cash requisition paths select active TechnicalLabour; overtime and compensatory-leave create paths accept it. Frontend Production, Teams, HR overtime/compensation, and requisition components still reference live Technical Labour selection. These violate confirmed W7-10.

## 23. Audit Trail

Record, PO verify, Finance verify, rate resolution, return, correction, supersession, and overrun code paths write `GovernanceAuditLog` entries with actor and timestamp. Record and Finance verify audit tests pass. Resubmission has no supported action or audit event; not every requested audit transition is executable.

## 24. Backend W7 Test Results

Final DDEV execution: **55 passed, 149 assertions, 0 failed, 0 skipped**. Four tests were added during this gate: negative over-budget variance and three closed-project transition guards. The earlier 51-test run passed 144 assertions before edits.

## 25. Backend Regression Results

The adjacent Cost Collector, Finance, payroll, and permission regression was initiated. Its final result must be recorded only from a completed runner output; no partial PASS lines constitute a suite pass. A complete backend suite result was not obtained for this gate.

## 26. Frontend Functional Verification

`ProjectLabourPanel.vue` mounts in Cost Account with permission-aware visibility and calls real project-scoped record, PO verify, Finance verify, and return endpoints. Budget-derived role, category, unit, and rate fields are now read-only for a selected budget line; the server remains authoritative. The panel lacks Finance rate resolution, correction, resubmission, and a closed-project labour state. Its budget summary is local JavaScript arithmetic, not a reconciled Project Costing response.

## 27. Frontend Test Results

Focused W7: **3 passed, 0 failed** (mount/permission, inherited read-only fields, project-scoped capture). Full Vitest regression after edits: **26 files, 145 tests passed, 0 failed**. The focused suite does not cover the missing lifecycle controls or the complete minimum W7 test matrix.

## 28. Frontend Production Build

`npm run build` completed successfully after the W7 UI edits (Vite built 1,891 modules in 30.38 seconds). Existing procurement components emitted constant-assignment warnings and chunk-size warnings. `npm run type-check` exited 2 with widespread repository errors; the earlier W7 `formatCost` signature error was fixed and no W7 panel error appeared in the final output. A passing build does not make the failed type-check pass.

## 29. Defects Found and Fixed

| Defect | Root cause | Fix | Test evidence |
|---|---|---|---|
| Over-budget line showed zero remaining | `max(0, budget - consumed)` hid negative variance | Preserve signed remainder | New W7 test; final 55-test suite passes |
| Closed project allowed non-Finance W7 transitions | Closure guard covered record and Finance verify only | Guard added in locked PO verify, return, correction, and rate-resolution transitions | Three new closure tests; final W7 suite passes |
| Budget fields looked editable and zero unbudgeted rate blocked capture | Frontend form treated inherited fields as user inputs | Read-only inherited inputs; unbudgeted capture validates operational fields/reason | Three focused W7 frontend tests pass |
| W7 panel type-check error | `formatCost` excluded `undefined` | Accept `undefined` | No W7 panel error in final type-check output |

Unfixed material gaps are listed in Sections 10, 16, 17, 21, 22, 26, and 28. They are not represented as policy approvals.

## 30. Remaining Open Policy Decisions

W7-24 GL/WIP treatment, W7-25 standard-cost versus actual payroll variance accounting, and W7-26 employer statutory-cost absorption remain awaiting the designated decision owners. The per-actual unbudgeted rate authorization mechanism should also be checked against WNG/Finance policy before treating it as a settled rate source.

## 31. Decision Register Changes

None. The register's engineering rule requires every applicable layer complete and verified before marking W7 fully implemented. This gate did not change W7-24/25/26.

## 32. Files Modified

Backend: `ProjectLabourActualService.php`, `W7LabourCostTest.php`, and this report. Frontend: `ProjectLabourPanel.vue` and new `wave7Labour.spec.ts`. Existing unrelated dirty-worktree changes were preserved.

## 33. Closure Assessment

Backend authority, basic analytical posting, privacy, and the dedicated W7 suite are materially stronger than Report 39 documented. Closure still requires live Technical Labour retirement, supported resubmission and complete frontend Finance/correction flows, reconciliation of W7-13 correction architecture, direct ledger and budget-revision evidence, complete relevant backend regression, complete W7 frontend tests, and a passing type-check or an explicitly accepted repository baseline that satisfies the engineering gate.

## 34. Final Verdict

### FAIL — W7 REMAINS OPEN

Do not mark any confirmed W7 row fully implemented. Do not start W8. Do not implement W7-24, W7-25, or W7-26 in this gate.
