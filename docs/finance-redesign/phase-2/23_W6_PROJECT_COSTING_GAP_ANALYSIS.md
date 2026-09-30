# 23 — W6 Project Costing: Current-vs-Target Gap Analysis (Read-Only)

Read-only analysis comparing the WNG-confirmed W6 decisions (`03_WNG_FINANCE_DECISION_REGISTER.md`,
confirmed 2026-09-23) against the current codebase. **No code changed as a result of this
document.** STAB-4, STAB-7, and the existing per-project margin calculation are preserved and are
not re-analyzed beyond confirming they still hold.

Classification key: **ALREADY SUPPORTED** / **PARTIALLY SUPPORTED** / **NOT SUPPORTED** /
**EXISTING CONTROL — PRESERVE** / **BLOCKED BY WNG DECISION** / **BLOCKED BY FINANCE/ACCOUNTANT
DECISION** / **DEPENDENT ON ANOTHER WORKFLOW** / **FACTUALLY RESOLVED**.

| Requirement | WNG Requirement | Current Behaviour | Gap | Future Change Required | Dependency |
|---|---|---|---|---|---|
| W6-1 Direct vs. Fully Loaded | Distinguish Direct Project Margin (reliable now) from Fully Loaded Project Margin (future target) | Only Direct Margin exists, computed correctly by `marginAgainstJournals()` | **PARTIALLY SUPPORTED.** Direct Margin is already correct; Fully Loaded Margin does not exist in any form | Add the Fully Loaded calculation and its clear "not yet reliable"/dependency labelling once W6-1A and its dependencies resolve | **BLOCKED BY WNG/FINANCE DECISION** — W6-1A, plus W7/W8/possibly W9 |
| W6-1A Overhead allocation | Which accounts, which driver, which period, which edge-case treatment | No allocation exists | **NOT SUPPORTED.** Confirmed absent | Build only once the methodology is confirmed | **BLOCKED BY FINANCE/ACCOUNTANT + MANAGEMENT DECISION** |
| W6-2 Portfolio profitability | Reuse the existing per-project margin calculation across every project | `CostAccountService::index()` lists cost totals per project but never computes margin across the list | **NOT SUPPORTED.** Confirmed absent as a feature; the underlying calculation to reuse already exists and is correct | Extend the existing list endpoint to also invoke `marginAgainstJournals()` per row, or an equivalent batched version of the same logic | None technical — a build, not a design, question |
| W6-3 Shared cost allocation | One source cost split across projects, sum-preserving, fully audited | No mechanism exists — every CostLine belongs to exactly one project | **NOT SUPPORTED.** Confirmed absent | Add an allocation mechanism (split CostLines under one source, or a child allocation table) preserving the sum-equals-source invariant | None technical; the exact mechanism is a Phase 2B design choice, not a further business decision |
| W6-4 Project cost transfer | Reclassify a wrong-project ACTUAL cost without duplication or silent editing | No mechanism exists | **NOT SUPPORTED.** Confirmed absent | Add a transfer/reclassification action producing a paired reduce-and-add CostLine change, fully attributed and audited | None technical |
| W6-5 Financial Closure | A distinct closure step with an open-items check | No concept of financial closure exists — only operational/enquiry status | **NOT SUPPORTED.** Confirmed absent | Build the closure check against the listed open-item sources (invoicing, commitments, bills, advances, transfers, credit notes, exceptions) | **BLOCKED BY WNG/FINANCE DECISION** — hard-blocker vs. warning classification |
| W6-6 Late cost after closure | Controlled reopen exception, never silent | No closure concept exists yet, so nothing currently distinguishes a late cost | **NOT SUPPORTED.** Confirmed absent (depends on W6-5 existing first) | Build the exception-capture flow once W6-5 exists | **BLOCKED BY WNG DECISION** — reopen authority; **DEPENDENT ON W6-5** |
| W6-7 Commitment release vs. write-off | Distinguish the two; confirm existing release mechanisms cover the first | `releaseCommitment()`/`releaseFor()` already exist and are confirmed working elsewhere in Finance (petty cash, procurement) for commitment cleanup | **PARTIALLY SUPPORTED / requires confirmation, not a build.** The commitment-release half likely already works for the project-costing case too, since it is the same underlying mechanism — this needs confirming by direct test in Phase 2B, not assumed. The true accounting write-off half does not exist in any form | Confirm (don't rebuild) the release mechanism covers a project-costing commitment; separately design a write-off mechanism only once policy is set | **BLOCKED BY FINANCE/ACCOUNTANT DECISION** — write-off policy |
| W6-8 Profitability ownership | Documented functional ownership model | None existed before this task | **FACTUALLY RESOLVED** by this task's confirmation — a documentation/process decision, not a code gap | None technical | — |
| W6-9 Margin visibility | Scoped visibility by role, no payroll-detail leakage | No confirmed role-based scoping exists today for margin/cost data | **NOT SUPPORTED / NOT VERIFIED.** Whether current permissions already partition this correctly was not exhaustively traced in this bounded review | Confirm current permission boundaries against the confirmed model once ROLE-1/ROLE-2 are resolved; build any missing scoping then | **BLOCKED BY WNG DECISION** — deferred to ROLE-1/ROLE-2 |
| W6-10 Provisional vs. Final margin | Distinguish live from closed-project margin in reporting | No such distinction exists — every margin figure is always "live" | **NOT SUPPORTED.** Confirmed absent | Add the distinction once W6-5 (Financial Closure) exists — a closed project's margin becomes "Final" | **DEPENDENT ON W6-5** |
| W6-11 Budget revision | Controlled, traceable budget revision | **EXISTING CONTROL — PRESERVE.** Confirmed sound: append-only `cost_lines` history, `BudgetRevisionRecorder` events once money is committed, deliberate no-approval-gate design (documented, not an oversight) | One reporting gap: no first-class original-vs-current baseline comparison view | Build the comparison view only if WNG confirms it's wanted — the underlying data already supports it | **BLOCKED BY WNG DECISION** — whether the report is wanted at all |
| W6-12 Historical profitability | As-of-date project financial position | **NOT SUPPORTED** as a feature — no date parameter exists in the margin calculation | The date-stamped source data (`posting_date`, `incurred_at`) already exists; this is a reporting-code gap, not a data-model or accounting limitation | Add an `asOfDate` parameter to the existing calculation, filtering the same sums by date, once WNG confirms priority | **BLOCKED BY WNG DECISION** — build priority |
| Labour dependency | Labour cost per project | Not attributed at all — confirmed via schema review of `AttendanceRecord` and `JobCard`, neither carries a project reference | N/A here | No action under W6 | **DEPENDENT ON WORKFLOW 7** |
| Logistics dependency | Logistics/fleet cost per project | Manual re-entry required, no automatic link | N/A here | No action under W6 | **DEPENDENT ON WORKFLOW 8** |
| Equipment dependency | Equipment/asset usage cost per project | No link between acquisition cost and the Asset Register at all | N/A here | No action under W6 | **DEPENDENT ON WORKFLOW 9** |
| STAB-2 dependency | WIP-vs-immediate-COGS policy | Undecided; `marginAgainstJournals()` already correctly falls back to raw `ACTUAL` costs when nothing is released | N/A here | No action under W6 | **DEPENDENT ON STAB-2** |
| W1-10 dependency | Credit note vs. Cost of Sales/WIP release timing | Undecided; moot today only because nothing is currently released under WIP | N/A here | No action under W6 | **DEPENDENT ON W1-10** |

## What this means for Phase 2B

- W6-3 and W6-4 are closely related (both touch how a CostLine's project attribution can change
  after the fact) but serve different purposes — a shared-cost allocation and a wrong-project
  transfer should not become the same mechanism, since one is a legitimate multi-project split and
  the other is a correction of an error.
- W6-5/W6-6/W6-10 form one coherent feature (Financial Closure, its late-cost exception, and the
  Provisional/Final label) and should be designed together, in that dependency order.
- W6-2's portfolio view is the lowest-risk, most mechanical build in this workflow — it is
  explicitly a reuse of an already-correct calculation, not new logic.
- W6-7 should start with a direct test confirming the existing commitment-release mechanism already
  works for a project-costing commitment, before any new code is written for that half of the
  question.
