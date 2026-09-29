# 13 — Workflow 3 (Expenses) Decision Brief, for WNG Review

Prepared 2026-09-23. W3-1 and W3-2 are presented for WNG's decision — nothing is confirmed here.
**W3-3 was a factual verification task, not a decision, and has been answered by direct evidence —
see Part 2 below.** A read-only search for additional, genuinely missing W3 decisions is in Part 3.

A design principle already confirmed sound and unchanged by this brief: the Cost Collector remains
the one place every expense type lands regardless of how it was paid — no second, parallel expense
system is proposed anywhere below.

---

## Part 1 — W3-1 and W3-2, for WNG decision

### W3-1 — Cash purchase at a shop counter

| | |
|---|---|
| **Current behaviour** | A cash purchase at a shop counter is indistinguishable from any other cash petty-cash payment today — both are recorded identically, differing only in the free-text description. |
| **Option A** | Add a distinct classification for "cash purchase, no prior requisition." |
| **Option B** | Current `payment_method = cash` is sufficient. |
| **Option C** | Only above a value threshold. |
| **Practical impact of A** | A small addition to the existing expense classification — a new value on an existing field, not a new mechanism. Gives Finance a reportable count/total of true walk-in cash purchases separate from other petty cash spend. |
| **Practical impact of B** | No change; the current, coarser view stays as-is. |
| **Practical impact of C** | Same small build as A, just conditionally applied — requires WNG to name the threshold. |
| **Decision owner** | Finance/Accounts Lead |

### W3-2 — Staff salary advance cash handover

| | |
|---|---|
| **Current behaviour** | An approved salary advance is scheduled for payroll deduction, but there is no system record connecting "we approved this advance" to "we actually handed the cash/made the transfer" — the payout itself happens off-system. |
| **Option A** | Route the advance payout through the existing `PaymentSettlementService` — the same engine every other payment (petty cash, supplier, payroll net pay per C7) already uses. |
| **Option B** | Current off-system process is acceptable. |
| **Option C** | Only for advances above a value threshold. |
| **Practical impact of A** | Reuses an existing, well-tested mechanism rather than building a new one — the advance payout becomes visible in bank reconciliation and payment reporting the same way every other payment type already is. Directly parallels the C7 fix already made for payroll net pay. |
| **Practical impact of B** | No build; the advance stays untracked as a payment, only visible via the payroll deduction schedule after the fact. |
| **Practical impact of C** | Same build as A, gated by amount. |
| **Decision owner** | HR + Finance/Accounts Lead |
| **Related, not yet asked** | See W3-missing item below on **salary advance settlement/recovery** — a related but distinct question about confirming the deduction actually happened, not just that the payout did. |

---

## Part 2 — W3-3, factual verification (not a WNG decision)

**Instruction was to determine the fact, not ask WNG to choose.** This was verified on 2026-09-23
by direct code-path analysis, then confirmed by executing the real `disburse → surrender →
reconcile` HTTP flow against a freshly seeded chart of accounts in a disposable test database and
inspecting every `JournalEntry`/`JournalLine` actually created — not by inspecting production data
(none was queried or needed; the defect is structural, not data-dependent, so it reproduces
identically on any installation).

### Result: CONFIRMED — and the defect is a triple posting, not a double posting

For a single KES 2,000.00 job-costed petty-cash requisition (approved → disbursed → one surrender
item of KES 2,000.00, no VAT, no cash returned → reconciled), the project's expense/WIP account
(chart code 1211, "Project WIP – Direct Materials") was debited on **three separate, uncoordinated
occasions**, for a total of KES 6,000.00 recognized against a real KES 2,000.00 spend:

| # | Entry | Trigger | Debit | Credit | Correct? |
|---|---|---|---|---|---|
| 1 | `JE-CL-0000002` | The queued `RecordPettyCashCost` listener, fired automatically on **every** disbursement via the `PettyCashDisbursementPaid` event (dispatched unconditionally at the end of `PettyCashService::createDisbursement()`, including for requisition-linked disbursements) → `PettyCashCostProducer::postFor()` | 1211 Project WIP, KES 2,000.00 | 1030 Petty Cash Float (direct) | **No.** This treats the entire disbursed amount as an already-incurred actual cost the instant cash leaves the tin — with no awareness that this disbursement came from a `PettyCashRequisition` that expects a future itemized surrender. `postFor()`'s own docblock explicitly reasons through this exact double-counting risk for supplier-settled and voucher-settled disbursements ("posting here as well would charge the job twice") and skips those — but applies no equivalent skip for a requisition awaiting surrender. |
| 2 | `JE-PCA-0000001` | The disbursement's own advance journal (`PettyCashAdvancePoster` → `postPettyCashAdvance()`) | 1300 Staff Advances/Imprest, KES 2,000.00 | 1030 Petty Cash Float | **Yes.** This is the correct, intended treatment: cash out is an advance, not yet an expense. |
| 3 | `JE-CL-0000003` | `CostCollectorService::postFromSource()` → `postCostLine()`, called once per surrender item inside `reconcileSurrender()` | 1211 Project WIP, KES 2,000.00 | 2100 Accounts Payable | **No, twice over.** This both re-recognizes the same expense a second/third time overall, and its credit side is wrong on its own terms — nothing is actually owed to a supplier at this point (the cash already left via the advance in #2); `settlementAccountFor()` falls through to its Accounts-Payable default here purely because the cost line's `details` never carry a `payment_source_id`. |
| 4 | `JE-PCS-0000001` | `JournalPostingService::postPettyCashSurrender()`, the requisition's clearing journal | 1211 Project WIP, KES 2,000.00 | 1300 Staff Advances/Imprest | **Yes.** This is the correct, intended clearing of the advance from #2 against the real, itemized spend. |

**Entries #2 and #4 together are the complete, correct pair** (advance out, advance cleared by
real spend). **Entries #1 and #3 are both extraneous** and both land on the same expense/WIP
account, which is why the true overstatement in this scenario is 3× the real spend, not 2×.

### Why this was missed by the existing regression test

`tests/Feature/Finance/PettyCashSurrenderTest.php` asserts that the surrender's own clearing
journal (`JE-PCS-*`) balances to the right total, and that exactly 2 `CostLine` rows exist with
`nature = ACTUAL` — both true and both pass. It never asserts the *total* number of
`JournalEntry` rows created by the whole flow, nor sums debits to the expense account across all
of them — so entries #1 and #3 above are invisible to it. This matches, and sharpens, what the
Phase 1 audit already flagged as a documented limitation of that test.

### Scope and severity

- This fires on **every** job-costed petty-cash requisition disbursement in production today, not
  an edge case — entry #1 is unconditional for any disbursement with a resolvable job number that
  isn't a supplier/voucher settlement.
- This is independent of, and in addition to, whatever the true historical volume of *surrender*
  double-postings the original Phase 1 suspicion was scoped to — the evidence here shows the
  problem starts even earlier, at disbursement time, before any surrender happens.
- No production data was queried for this verification, and none was needed — the defect is in
  the code path itself, not a data anomaly. However, quantifying the **real historical KES impact**
  does require a live-data pull, since it depends on how many job-costed petty-cash disbursements
  have gone through this exact path.

### Recommended next step (not performed here — outside this task's scope)

If Finance/Engineering want to quantify the real historical impact before deciding on remediation
priority and approach, the query is:

```sql
-- Every requisition-linked disbursement that reached RecordPettyCashCost's postFor(),
-- cross-referenced against its own surrender's cost lines and clearing journal, to see
-- how many times each requisition's expense account was actually debited.
SELECT r.id, r.requisition_number, r.total_amount,
       je.entry_no, je.source_type, je.source_id, jl.account_id, jl.entry_type, jl.amount
FROM petty_cash_requisitions r
JOIN payments p ON p.id = (
    SELECT disbursement_id FROM ... -- however the disbursement is linked back to the requisition
)
JOIN journal_lines jl ON jl.account_id IN (
    SELECT id FROM chart_of_accounts WHERE code LIKE '121%' -- or the live WIP/expense code range
)
JOIN journal_entries je ON je.id = jl.journal_entry_id
WHERE r.enquiry_id IS NOT NULL
ORDER BY r.id, je.id;
```

This is illustrative of the shape of the check, not a ready-to-run statement — the exact join from
`payments` back to `petty_cash_requisitions` should be confirmed against the live schema before
running it, and it is offered here only as a starting point for whoever runs the real query.

**Recommend WNG/Engineering jointly assess whether this warrants the same urgency as the C1–C7
critical stabilization fixes** — it is a live, unconditional, currently-active data-integrity
defect on the general ledger, of the same character as those seven. No fix has been implemented as
part of this task, per the stated boundary.

---

## Part 3 — Potential missing W3 decisions (read-only)

WNG will decide whether any of these belongs in the register. Each candidate below responds
directly to the topics named in the task's Part 15.

- **Not flagged — already covered: project expense vs. company overhead.** Confirmed genuinely
  distinguished and enforced today (`current-state/05_CURRENT_WORKFLOWS.md` Part C) — job-tagged
  vs. deliberately-untagged overhead spend is a real, enforced split, not just a label.
- **Not flagged — already covered: budgeted vs. unbudgeted expense.** The documented "expenditure
  exception" mechanism (permission-gated, audit-logged override for over-budget petty-cash spend)
  already exists and is a real control, not a gap.
- **Not flagged — already covered: employee reimbursement vs. supplier expense.** Both are already
  tracked as genuinely different things today, per the current implementation.
- **POTENTIAL MISSING DECISION — minimum evidence standard for an expense claim.** A surrender
  item's `receipt_number` is validated as `nullable` — an item may be submitted with
  `receipt_type: none` and no receipt number at all, backed only by a free-text description, and
  it will be accepted and posted. This may be entirely intentional for certain expense types (e.g.
  boda fare with no formal receipt), but WNG has not been asked to confirm whether a minimum
  evidence standard should apply, and if so, for which expense types or value ranges.
- **POTENTIAL MISSING DECISION — duplicate receipt/expense detection.** No uniqueness check exists
  on a surrender item's `receipt_number`, either within one requisition or across different
  requisitions/staff members over time — the same physical receipt could in principle be claimed
  twice with no system-level catch. This is the direct Workflow-3 analogue of W2-5 (duplicate
  supplier invoice/payment reference), which WNG has already confirmed needs a control on the
  procurement side.
- **POTENTIAL MISSING DECISION — expense claim return/rejection.** No mechanism was found for
  Finance to send a submitted surrender back to the requester for correction (e.g., an
  unreasonable amount, a missing description, a mismatched receipt) — reconciliation currently
  either proceeds and posts, or is not actioned. This is the Workflow-3 analogue of W2-6 (Return
  for Correction), which WNG has already confirmed is needed on the procurement side.
- **Not flagged — already substantially covered: expense incurred before approval.** The existing
  "Direct Disbursement Request" mechanism (an exceptional petty-cash payment with no prior
  requisition) already exists specifically for this scenario, with its own approval step — the
  known limitation (approval relies only on a free-text reason, with no float-availability or
  repeat-request signal) is already tracked as W5-2, not repeated here.
- **Not flagged — already covered: project coding.** Cost attribution to a job number/enquiry is
  already a working, structural mechanism (`resolveEnquiry()` in `PettyCashCostProducer`). Note
  that Part 2 above found a defect in *how many times* that coding results in a posting, not in
  *whether* an expense can be coded to a project at all — that finding is captured under W3-3, not
  repeated here.
- **Not flagged — already substantially covered: emergency expense.** Petty cash's existing Direct
  Disbursement Request path already serves this purpose, and its one known gap (free-text-only
  justification) is already tracked as W5-2. The equivalent question for procurement-side emergency
  purchases is already raised separately as W2-10.
- **POTENTIAL MISSING DECISION — salary advance settlement/recovery.** W3-2 (above) asks whether
  the advance *payout* should be tracked as a proper payment. A related, distinct question was not
  separately investigated here for time reasons: once a salary advance is paid out and scheduled
  for payroll deduction, is there any system record confirming the deduction actually happened and
  the advance was fully recovered, or reconciling a partially-recovered advance if an employee
  leaves before it's fully repaid? This would need a short, bounded code check of the Payroll
  module's advance-deduction handling before it could be answered as a fact the way W3-3 was.
- **POTENTIAL MISSING DECISION — expense cancellation/reversal at the business-process level.**
  A general-purpose journal/payment reversal permission and mechanism already exists
  (`current-state/07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.6, §16), so this is not a structural
  gap the way the items above are. It is flagged only because no *petty-cash-specific* "void this
  surrender/expense claim" concept was found distinct from the general ledger reversal — WNG may
  want a lighter, purpose-built path rather than requiring a full journal reversal for a simple
  expense-claim correction, but the general mechanism already covers the underlying need.
