# 14 — STAB-7: Petty Cash Requisition Expense Triple Posting — Root-Cause Analysis

Prepared 2026-09-23. Read before `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md` and
`16_STAB_7_VERIFICATION.md`. This document independently re-reproduces the defect first
surfaced during the W3-3 factual verification (`13_W3_EXPENSES_DECISION_BRIEF.md` Part 2),
confirms the exact root cause by tracing every event/listener/service/CostLine/JournalEntry/
JournalLine involved, and documents the fix actually implemented.

---

## 1. Independent reproduction

Reproduced fresh, not assumed from the earlier W3-3 finding: a new, more heavily instrumented
test (`tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php`, scenario A) drove the real
`disburse → surrender → reconcile` HTTP flow for a KES 2,000.00 job-costed requisition against a
freshly seeded chart of accounts in a disposable test database, then inspected every
`JournalEntry`/`JournalLine` row actually created.

### Before the fix — every posting produced for one KES 2,000.00 spend

| # | Entry | Trigger | Debit | Credit | Source type / id (JournalEntry) | Source type / id (underlying CostLine, if any) |
|---|---|---|---|---|---|---|
| 1 | `JE-CL-0000002` | `PettyCashDisbursementPaid` event → `RecordPettyCashCost` listener (queued) → `PettyCashCostProducer::postFor()` → `CostCollectorService::postFromSource()` → `postCostLine()` | 1211 Project WIP, 2,000.00 | 1030 Petty Cash Float | `CostLine` / CostLine #2 | CostLine #2: `source_type=Payment, source_id=<disbursement id>`, `nature=ACTUAL` |
| 2 | `JE-PCA-0000001` | `PettyCashRequisitionController::disburse()` → `PettyCashAdvancePoster::attempt()` → `postPettyCashAdvance()` | 1300 Staff Advances/Imprest, 2,000.00 | 1030 Petty Cash Float | `Payment` / disbursement id | — (not a CostLine posting) |
| 3 | `JE-CL-0000003` | `PettyCashRequisitionController::reconcileSurrender()` → `CostCollectorService::postFromSource()` → `postCostLine()`, once per surrender item | 1211 Project WIP, 2,000.00 | 2100 Accounts Payable | `CostLine` / CostLine #3 | CostLine #3: `source_type=PettyCashSurrenderItem, source_id=<item id>`, `nature=ACTUAL` |
| 4 | `JE-PCS-0000001` | `reconcileSurrender()` → `JournalPostingService::postPettyCashSurrender()` | 1211 Project WIP, 2,000.00 | 1300 Staff Advances/Imprest | `PettyCashRequisition` / requisition id | — (aggregate entry covering every surrender item) |

**Total recognised against account 1211 across all four entries: KES 6,000.00, for a real KES
2,000.00 spend — a 3× overstatement**, exactly reproducing (and re-confirming, independently) the
W3-3 finding.

Entries #2 and #4 are correct and were always meant to be the complete pair (advance out, advance
cleared by the real, itemised spend). **Entries #1 and #3 are the defect.**

---

## 2. Complete before-flow, traced event by event

```
PettyCashRequisition (approved)
  │
  ├─▶ PettyCashCostProducer::commitFor()
  │      CostLine (nature=COMMITTED, source=PettyCashRequisition) — NOT posted to GL
  │      (postFromSource()'s posting gate only fires for ACCRUED/ACTUAL — correct, unaffected)
  │
  ▼
PettyCashRequisitionController::disburse()
  │
  ├─▶ PettyCashService::createDisbursement()
  │      Payment row created (requisition_id = the requisition's id, ALWAYS set by disburse())
  │      DB::afterCommit(fn () => PettyCashDisbursementPaid::dispatch($disbursementId))
  │        │
  │        ▼ (queued listener, fires shortly after commit)
  │      RecordPettyCashCost::handle()
  │        └─▶ PettyCashCostProducer::postFor($disbursement)
  │              ├─ postPaymentFee()                          — unrelated, unaffected
  │              ├─ releaseRequisitionCommitment()             — unrelated, unaffected, releases the COMMITTED line
  │              ├─ skip if supplier-settled (bill_id / BillPayment)   — unaffected, correct
  │              ├─ skip if voucher-settled (spend_voucher_id / SpendVoucher)  — unaffected, correct
  │              ├─ [BEFORE FIX: no check here at all]
  │              ├─ if blank(job_number): postDirectPayment() — DEFECT #1a (overhead case)
  │              ├─ if enquiry unmatched: postDirectPayment() — DEFECT #1a (overhead case)
  │              └─ else: postFromSource(nature=ACTUAL, source=Payment) → postCostLine()
  │                    — DEFECT #1b (job-costed case) — JE-CL-0000002 above
  │
  └─▶ PettyCashAdvancePoster::attempt() (called explicitly by the controller,
         separately from the queued listener above)
        └─▶ postPettyCashAdvance() — Dr 1300 / Cr Float — JE-PCA-0000001, CORRECT

  ... time passes, requester submits receipts ...

PettyCashRequisitionController::reconcileSurrender()
  │
  ├─▶ per surrender item: CostCollectorService::postFromSource(nature=ACTUAL,
  │      source=PettyCashSurrenderItem) → postCostLine()
  │      — DEFECT #2 — JE-CL-0000003 above
  │
  ├─▶ PettyCashCostProducer::releaseFor() — releases any still-open commitment, unaffected
  ├─▶ ledger cash-returned entry (if any) — unaffected
  ├─▶ requisition status → surrendered
  └─▶ JournalPostingService::postPettyCashSurrender()
        — Dr [each item's expense account] / Cr 1300 — JE-PCS-0000001, CORRECT
```

---

## 3. Root cause

**Two independent components each believe they are the sole owner of recognising this
disbursement's economic cost, and neither is aware of the other, nor of the advance/surrender
lifecycle that actually owns it correctly.**

- `PettyCashCostProducer::postFor()` (via the `RecordPettyCashCost` listener) treats **every**
  active, non-supplier-settled, non-voucher-settled disbursement as an immediately-substantiated
  final expense — correct for a Direct Disbursement Request (which has no future surrender) and
  for a Direct Bill, but **wrong** for a requisition-based advance, which is explicitly not yet a
  final expense (§2, task specification; and see the docblock already on `postFor()`, which
  reasons through this exact class of problem for supplier/voucher settlement but never extended
  the same reasoning to a plain, unsettled requisition advance).
- `CostCollectorService::postFromSource()` posts a CostLine's own independent journal entry for
  **every** ACCRUED/ACTUAL cost line, unconditionally — correct for the vast majority of its
  callers (Stores issues, Direct Bills, HR payroll accruals, etc.), but wrong for a surrender
  item, whose economic event is already, correctly, posted in aggregate by
  `postPettyCashSurrender()`.

Neither defect is a flaw in `PettyCashAdvancePoster` or `postPettyCashSurrender()` — both of those
already implement exactly the two-stage lifecycle described in the task's §2 (advance out, then
clear against the real spend). The defect is that **two other components post on top of that
correct pair**, not that the correct pair is wrong.

**A secondary, previously-undocumented confirmation:** because the check that fixes this
(`$disbursement->requisition_id`) is unconditional — checked before the job-number/enquiry
branching — the same defect, and the same fix, applies identically to **overhead/non-project
petty cash requisitions**, which were also being double-posted (Direct Payment at disbursement,
then the surrender clearing journal) via a different first-posting path (`postDirectPayment()`
instead of `postFromSource()`+`postCostLine()`). This was not explicitly called out in the
original W3-3 finding or in the task's own framing of DEFECT #1, which described only the
job-costed ACTUAL-cost path — it is a real, additional finding from this re-verification,
confirmed by test scenario F.

---

## 4. Posting ownership (confirmed against the actual implementation)

| Economic Event | Accounting Owner | Confirmed? |
|---|---|---|
| Petty cash requisition approved | No GL posting (COMMITTED CostLine only, budget-facing) | Confirmed — unaffected by this fix |
| Advance disbursed | `PettyCashAdvancePoster` → `postPettyCashAdvance()` | Confirmed — unaffected by this fix |
| Surrender item recorded | Cost Collector records project-cost data (CostLine created, attributed, evidenced) but **does not independently post** | **Changed by this fix** — previously also posted via `postCostLine()` |
| Surrender reconciled | `JournalPostingService::postPettyCashSurrender()` owns expense recognition and advance clearing, for every surrender item at once | Confirmed — unaffected in its own logic; now also the sole poster |
| Cash returned | Existing return-cash posting path (petty cash ledger entry inside `reconcileSurrender()`) | Confirmed — unaffected |
| Top-up | Existing top-up posting path | Confirmed — unaffected, out of scope |
| Direct Disbursement Request (no requisition) | `PettyCashCostProducer::postFor()` → `postFromSource()`/`postDirectPayment()`, immediately, since there is no future surrender | Confirmed — unaffected by this fix (test scenario G) |
| Supplier-settled / voucher-settled petty cash | The bill's/voucher's own posting; `postFor()` correctly excludes these | Confirmed — unaffected, unchanged, still the first two checks in `postFor()` (test scenarios H, I) |

The example table in the task prompt (§7) is confirmed complete against the actual implementation,
with the one addition that overhead (non-project) requisitions follow the same "Advance disbursed"
/ "Surrender reconciled" ownership as project-costed ones — there is no separate ownership rule for
overhead.

---

## 5. The fix implemented

Smallest change that establishes a single, explicit posting owner, without touching Cost Collector's
general architecture, WIP-vs-COGS policy, the Chart of Accounts, or any other settlement path:

1. **`app/Modules/Finance/CostCollector/Services/PettyCashCostProducer.php`** — `postFor()` gains
   one new early-exit check, placed *after* the existing supplier-settlement and voucher-settlement
   exclusions (so those keep their priority and their own specific outcome codes) and *before* the
   job-number/enquiry branching (so it applies uniformly to job-costed and overhead requisitions
   alike):
   ```php
   if ($disbursement->requisition_id) {
       return 'skipped_requisition_advance';
   }
   ```
   This is unconditional on `requisition_id` alone — not on the requisition's current status —
   because a requisition-linked disbursement's posting is always owned by the advance/surrender
   lifecycle, whether or not (or not yet) reconciled. `backfill()`'s outcome tally gained this new
   key (and the pre-existing, separately-noticed missing `skipped_voucher_settlement` key, fixed
   alongside it for the same reason its own docblock already warns about: "every outcome `postFor()`
   can return needs a counter here").

2. **`app/Modules/Finance/CostCollector/Contracts/CostContext.php`** — new readonly property
   `postsIndependently: bool = true`. Every existing caller is unaffected (default `true`
   preserves current behaviour exactly). Set to `false` only by the one call site described next.

3. **`app/Modules/Finance/CostCollector/Services/CostCollectorService.php`** — `postFromSource()`'s
   posting gate now reads `if ($context->postsIndependently && in_array($line->nature, [...ACCRUED,
   ACTUAL], true))`. When `false`, the `CostLine` is still created, fully attributed (project,
   expense code, evidence, everything `CollectsCost` normally records) — it simply is not, itself,
   the trigger for a `JournalEntry`.

4. **`app/Modules/Finance/PettyCash/Controllers/PettyCashRequisitionController.php`** —
   `reconcileSurrender()`'s per-surrender-item `CostContext` now passes `postsIndependently: false`.

5. **`app/Modules/Finance/Services/JournalPostingService.php`** — `postPettyCashSurrender()` now
   captures the `JournalEntry` it posts and, immediately after, stamps every surrender item's
   `CostLine` (via the item's `costLine` relation, already linked at CostLine-creation time) with
   `journal_entry_id`/`posted_at` pointing at that one entry — the same fields `postCostLine()`
   itself would have set, had it been allowed to post independently. This keeps every `CostLine`
   correctly marked "posted," pointing at its real, single posting, rather than left permanently
   `posted_at = null` (which would have made project-cost reporting/audit views built around
   "posted" CostLines silently lose these rows).

### A dependent gap this fix would otherwise have introduced, found by the regression suite

Running the full regression suite after the fix surfaced two pre-existing tests that encoded the
*old, defective* behaviour as their expected outcome — both were updated to assert the corrected
behaviour, not reverted:

- `tests/Feature/CostCollector/PettyCashCommitmentTest.php::test_paying_a_requisition_releases_its_commitment`
  asserted `postFor()` returns `'posted'` and creates an ACTUAL cost line immediately on payment.
  Updated to assert the new `'skipped_requisition_advance'` outcome and that no ACTUAL cost line
  exists yet at that point — the commitment-release assertion (unrelated to this fix) is unchanged
  and still passes.
- `tests/Feature/PettyCash/ExpenditureExceptionTest.php::test_the_justification_survives_the_payment`
  revealed a real, dependent gap: the budget-exception justification (`requisition->budget_exception`)
  was previously only ever attached to a CostLine's `details['unbudgeted_reason']` inside
  `postFor()`'s now-removed immediate-posting branch. With that posting removed, the justification
  had nowhere to land — an authorized over-budget spend would post with **no recorded reason at
  all**, silently losing an audit trail the original C1–C7 work and this task's own §6 both require
  preserving. **Fixed as part of this same change:** `reconcileSurrender()`'s per-surrender-item
  `CostContext.details` now also carries `unbudgeted_reason`/`budget_exception_log_id` from
  `$requisition->budget_exception`, so the justification survives onto the real, final ACTUAL cost
  line — at surrender, where that cost line now actually gets created, rather than at disbursement.
  The test was rewritten to drive the full `disburse → surrender → reconcile` flow (the only place
  the justification can now be observed) rather than calling `postFor()` directly.

### Why this preserves everything the task required preserved

- **Cost Collector, project attribution, cost category, surrender detail, supporting evidence**:
  unchanged — every `CostLine` still exists, still carries its full attribution, exactly as before.
  Only its GL-posting trigger is removed for this one caller.
- **Direct Disbursement Request**: unaffected — it has no `requisition_id`, so the new check never
  applies to it; it still posts immediately via `postFor()`'s existing ACTUAL-cost or
  `postDirectPayment()` path (test scenario G).
- **Supplier-settled / voucher-settled petty cash**: unaffected — those checks run first and
  return before the new check is ever reached (test scenarios H, I).
- **STAB-4 (failed advance GL posting / retry)**: untouched — `PettyCashAdvancePoster` was not
  modified in any way.
- **Idempotency**: `postFromSource()`'s own `(source_type, source_id)` idempotency is unaffected;
  `postPettyCashSurrender()`'s existing `entry_no`-based idempotency (returns the existing entry if
  `JE-PCS-{id}` already exists) is unaffected, so the new CostLine-stamping code only ever runs once
  per requisition, on the one call that actually creates the entry.
- **WIP-vs-COGS policy, Chart of Accounts, accounting policy**: untouched — no account codes,
  mappings, or recognition-timing rules were changed. `ChartAccountMap`/`config('finance_accounts.map')`
  continue to be the only source of account resolution wherever it already applied.

---

## 6. Risks and residual considerations

- **Historical data is unaffected by this fix and remains overstated** until Finance/accountant
  determines a remediation approach — see `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md`. No
  historical journal, cost line, or period was touched by this change.
- **Overhead requisitions were found to share the same defect via a different path** (§3 above) —
  this is a strictly larger confirmed scope than the task's own framing, not a smaller one; the
  single fix (gating on `requisition_id`) resolves both without any special-casing.
- **The `RecordPettyCashCost` listener is queued.** Its skip is now a fast, synchronous, in-memory
  check (`$disbursement->requisition_id`) with no new database query beyond what the method already
  performs — no meaningful performance change.
- **No other `postFromSource()` caller was touched or reviewed for a similar ownership question.**
  The task scoped this fix narrowly to petty-cash surrender; a broader audit of every ACCRUED/ACTUAL
  producer for the same "does something else already own this posting" question is explicitly out
  of scope here and is not implied to be needed elsewhere.
