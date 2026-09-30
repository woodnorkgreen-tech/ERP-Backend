# 16 — STAB-7: Verification

Prepared 2026-09-23. Companion to `14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md` (root cause and
fix) and `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md` (read-only historical exposure diagnostic).

---

## Test scenarios (all new, in `tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php`)

| Test Scenario | Expected | Actual | Result |
|---|---|---|---|
| A. Full surrender, advance == spend (KES 2,000) | Expense/WIP account debited exactly once (2,000.00); Staff Advance debited and credited 2,000.00 (fully cleared); Float credited 2,000.00; every JournalEntry balances; surrender item's CostLine posted via the requisition's own `JE-PCS-*` entry, no stray `JE-CL-*` for it | Matches exactly | PASS |
| B. Partial spend with cash returned (advance 10,000, spend 7,000, cash back 3,000) | Expense/WIP debited exactly once (7,000.00); Staff Advance cleared 10,000.00; Float credited 10,000 (disbursed) and debited 3,000 (returned) | Matches exactly | PASS |
| C. Spend exceeds advance (advance 2,000, spend 2,500) | Expense/WIP debited exactly once (2,500.00); Staff Advance cleared only up to the original advance (2,000.00); pre-existing overspend/reimbursement leg untouched and still balances | Matches exactly | PASS |
| D/E. Multiple surrender items, different expense categories (2,000 + 1,000 across two codes) | Each category's own expense/WIP account debited exactly once for its own item's amount (or, if both codes share one account in the seed, the combined total posts exactly once); Staff Advance cleared for the full 3,000.00 | Matches exactly | PASS |
| F. Non-project / overhead requisition (no enquiry, KES 1,500) | At disbursement: only the Staff Advance entry exists, nothing yet debited to the overhead expense account; at reconcile: overhead expense account debited exactly once (1,500.00), Staff Advance cleared 1,500.00 | Matches exactly — this also confirms the fix resolves a previously-undocumented equivalent defect on the overhead path (see analysis §3) | PASS |
| G. Direct Disbursement (no `requisition_id`) | `postFor()` still returns `'posted'`; still creates its own ACTUAL CostLine with its own `journal_entry_id`; expense account still debited immediately, exactly as before this fix | Matches exactly — completely unaffected | PASS |
| H. Supplier-settled petty cash (`requisition->bill_id` set) | `postFor()` returns `'skipped_supplier_settlement'`, not swallowed by the new check | Matches exactly | PASS |
| I. Voucher-settled petty cash (`SpendVoucher.petty_cash_disbursement_id` set) | `postFor()` returns `'skipped_voucher_settlement'`, not swallowed by the new check | Matches exactly | PASS |
| Requisition-linked advance alone, called directly | `postFor()` returns the new `'skipped_requisition_advance'`; no CostLine created for the disbursement itself | Matches exactly | PASS |
| K. Reconcile twice (retry/idempotency) | Second `reconcile` call refused (422, pre-existing guard); expense account total unchanged after the second attempt; exactly one `JournalEntry` for the requisition's surrender | Matches exactly | PASS |
| Budget-exception justification survives to the real cost line (pre-existing test, updated) | An authorized over-budget requisition's `unbudgeted_reason` lands on the surrender item's ACTUAL CostLine, not lost when the disbursement-time posting was removed | Matches, after fixing `reconcileSurrender()` to carry the reason (see analysis) | PASS |

**10/10 new STAB-7 tests pass; both dependent pre-existing tests pass after being updated.**

---

## Files changed

| File | Change |
|---|---|
| `app/Modules/Finance/CostCollector/Contracts/CostContext.php` | Added `postsIndependently: bool = true` (default preserves every existing caller's behaviour) |
| `app/Modules/Finance/CostCollector/Services/CostCollectorService.php` | `postFromSource()`'s posting gate now also requires `$context->postsIndependently` |
| `app/Modules/Finance/CostCollector/Services/PettyCashCostProducer.php` | `postFor()` gained the `skipped_requisition_advance` exclusion (placed after the existing supplier/voucher exclusions); `backfill()`'s tally and both return-type docblocks updated to include it and the previously-missing `skipped_voucher_settlement` key |
| `app/Modules/Finance/PettyCash/Controllers/PettyCashRequisitionController.php` | `reconcileSurrender()`'s per-item `CostContext` now passes `postsIndependently: false`, and also carries `unbudgeted_reason`/`budget_exception_log_id` from `$requisition->budget_exception` into `details` — previously only attached at the disbursement-time posting this fix removes (see analysis, "a dependent gap this fix would otherwise have introduced") |
| `app/Modules/Finance/Services/JournalPostingService.php` | `postPettyCashSurrender()` now captures its own `JournalEntry` and stamps every surrender item's `CostLine` with `journal_entry_id`/`posted_at` pointing at it; `loadMissing()` extended to include `surrenderItems.costLine` |

## Migrations

None. This fix required no schema change — `CostLine.journal_entry_id`/`posted_at` already existed
and were already used by `postCostLine()` for the same purpose on every other caller.

## Tests added

- `tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php` — 10 tests, described above.

## Tests changed

Two pre-existing tests encoded the *old, defective* behaviour as their expected outcome and were
updated to assert the corrected behaviour (not reverted — see the analysis document's "a dependent
gap this fix would otherwise have introduced" section for why):

- `tests/Feature/CostCollector/PettyCashCommitmentTest.php::test_paying_a_requisition_releases_its_commitment`
  — now asserts `'skipped_requisition_advance'` and zero ACTUAL cost lines immediately after payment,
  instead of `'posted'` and one.
- `tests/Feature/PettyCash/ExpenditureExceptionTest.php::test_the_justification_survives_the_payment`
  — rewritten to drive the full `disburse → surrender → reconcile` flow and assert the justification
  lands on the surrender item's CostLine, since that is now where the real ACTUAL cost line is
  created; also proved the real dependent gap above, which is fixed in
  `PettyCashRequisitionController.php`.

## Full Finance regression result

Ran the same regression scope used to verify C1–C7 (`tests/Feature/{PettyCash,CostCollector,Finance,
Hr,Procurement,ProcurementStores,Stores}`, `tests/Unit/Finance`), plus the new STAB-7 suite:

**886 passing, 6 failed — the identical 6 pre-existing, unrelated failures present on the branch
before this fix** (4 in `PurchaseOrderApprovalPricingTest`, "No accounting period covers that
date"; 2 in `PettyCashCostProducerTest` — `test_overhead_coded_spend_is_not_forced_onto_a_project`
and `test_a_disbursement_with_no_job_number_is_skipped` — "Payment cannot post: its expense code
and paying source must map to postable GL accounts" inside `postDirectPayment()`, caused by that
test file's `disbursement()` helper never setting `expense_code_id`/`payment_source_id`, unrelated
to any `requisition_id` logic this fix touches). 884 passed before the two test-file fixes below
were applied; the two additional failures surfaced by the first full run
(`PettyCashCommitmentTest::test_paying_a_requisition_releases_its_commitment` and
`ExpenditureExceptionTest::test_the_justification_survives_the_payment`) were investigated,
confirmed to be dependent on the *old, defective* behaviour this fix intentionally changes, fixed
by updating those two tests (see "Tests changed" above — one of the two also revealed a real,
separate gap in the fix itself, which was corrected in `PettyCashRequisitionController.php`), and
the suite was run a third time to confirm exactly the 6-failure baseline remained. No other test in
the regression scope was affected.

## Remaining risk

- **Historical data remains overstated** until Finance/accountant reviews the output of
  `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md` and decides on a remediation approach. No historical
  journal, cost line, or accounting period was modified by this task.
- **No broader audit was performed** of every other `CostCollectorService::postFromSource()` caller
  for a similar "does something else already own this posting" question — this fix is scoped
  exactly to the petty-cash requisition surrender path per the task's boundary. If a similar pattern
  exists elsewhere (e.g., another module that both advances cash and later itemizes it), it has not
  been checked here.
- **`postsIndependently` is a new, permanent concept on `CostContext`.** Any future caller of
  `postFromSource()` that also owns its own GL posting elsewhere should be aware this flag exists,
  rather than reinventing the same fix differently.

---

## STAB-7 result

**PASS WITH HISTORICAL REMEDIATION PENDING**

The forward fix is correct and verified: the defect was independently reproduced, the root cause
was identified precisely (two uncoordinated posting owners on top of an already-correct
advance/surrender pair), duplicate/triple accounting is removed for every future petty-cash
requisition disbursement (job-costed and overhead alike), the advance/surrender accounting and
Cost Collector/project attribution are fully preserved, Direct Disbursement and supplier/voucher
settlement paths are confirmed unaffected, STAB-4's failure/retry mechanism was not touched, every
journal balances, and the complete-flow regression tests prove the real spend is recognised exactly
once across scenarios A through K. The historical diagnostic is prepared and ready to run; no
historical accounting was changed, and quantifying real historical exposure and choosing a
remediation approach is explicitly left to Finance/accountant review, per the task's boundary.
