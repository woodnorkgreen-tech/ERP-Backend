# 22 — Workflow 6 (Project Costing) Decision Brief, for WNG Review

Prepared 2026-09-23. **Neither W6-1 nor W6-2 is decided by this document.** It presents the
current project-cost architecture, a bounded trace of every cost category into that architecture,
the options already on record in `03_WNG_FINANCE_DECISION_REGISTER.md`, and a read-only search for
additional genuinely missing decisions.

**Financial-integrity stop-rule result: no defect comparable to STAB-7 was found.** See Part 3 for
the specific checks performed and why the margin engine does not carry the same double-posting
risk STAB-7 exposed in petty cash. Ordinary Workflow 6 analysis proceeds below.

---

## Part 1 — W6-1: What does "Project Cost" mean?

**Current model, confirmed by direct code review of `CostAccountService::marginAgainstJournals()`:**
margin = billed revenue (sum of issued, non-void invoices with a posted journal entry) minus cost
of sales (the WIP-released amount if any exists for that project, falling back to the raw sum of
`ACTUAL` cost lines when nothing has been released — which is every project today, since STAB-2's
WIP-vs-immediate-COGS policy remains undecided and no WIP accounts exist on the live chart). This
is **direct, attributable cost only** — company overhead (rent, admin salaries, shared equipment
depreciation, etc.) is never allocated into any project's cost today.

| | |
|---|---|
| **Option A** | Direct project costs only (today's behaviour, confirmed as a deliberate model to keep or formalize). |
| **Option B** | Direct costs plus allocated company overhead. |
| **Option C** | Show both — Direct Project Cost/Margin and Fully Loaded Project Cost/Margin, side by side. |
| **Management usefulness of A** | Simple, defensible, and already fully computed correctly from real posted data — every project's margin is directly traceable to actual transactions, nothing estimated or apportioned. Understates true profitability by the overhead the business still has to cover. |
| **Management usefulness of B** | A single "true cost" figure per project, useful for pricing decisions that must recover overhead — but only as useful as the allocation basis is fair, and a bad basis actively misleads rather than merely omitting information. |
| **Management usefulness of C** | Preserves A's defensibility while adding B's completeness — lets Management see both "did this job cover its direct cost" and "did this job cover its share of keeping the lights on," without forcing one number to answer both questions. |
| **Accounting implications** | A requires no accounting change. B and C both require deciding an overhead allocation basis (revenue share, direct-cost share, labour-hours share, headcount, or another method) — this is itself a real accounting-policy decision, not a technical one, and is not invented here. |
| **Data requirements** | A: none beyond what already exists. B/C: a defined overhead pool (which cost accounts count as "company overhead") and a defined allocation driver, refreshed each period. |
| **Risk of arbitrary overhead allocation** | Real and worth stating plainly: any allocation basis can be gamed or can systematically favour/penalize certain project types (e.g. revenue-share allocation penalizes large-revenue, thin-margin projects regardless of their actual overhead consumption). Whatever basis is chosen should be documented and applied consistently, not adjusted per project. |
| **Impact on profitability reporting** | A is what exists today and requires no reporting change. B/C both require new report/dashboard fields — explicitly out of scope to build in this task. |

No allocation basis is recommended here — that is squarely WNG/Finance's call, not an engineering one.

## Part 2 — W6-2: Portfolio-wide project profitability

**Current behaviour, confirmed:** `CostAccountService::forEnquiry()` computes one project's margin
at a time; `CostAccountService::index()` lists many projects' cost *totals* (planned/committed/
accrued/actual sums) in one paginated view, but does not compute or display margin (billed revenue
vs. cost of sales) across that list — the margin calculation itself is only ever invoked per
project.

| | |
|---|---|
| **Option A** | Prioritize a portfolio-wide profitability view now. |
| **Option B** | Defer it. |
| **Option C** | Provide it initially only for selected/high-value projects. |
| **Recommended technical direction, regardless of option chosen** | Reuse `marginAgainstJournals()`'s exact calculation for every project in the list, rather than building a second, separate margin formula for the portfolio view — the existing per-project calculation is already correct (billed revenue from real invoices, cost of sales from real cost lines/releases) and a portfolio view should be many rows of that same calculation, not a new one. |
| **Practical impact of A** | The heaviest lift of the three, but architecturally straightforward — no new calculation, just running the existing one across many projects and adding sort/filter. |
| **Practical impact of B** | No build; per-project review remains the only view. |
| **Practical impact of C** | Smaller initial build (a defined subset of projects), same reused calculation. |
| **Decision owner** | Management |

---

## Part 3 — Project cost attribution trace (bounded, read-only)

For each category: (1) project-attributable? (2) creates/uses a CostLine? (3) actual, committed,
planned, or informational? (4) posts to GL? (5) included in current margin? (6) automatic or
manual attribution? (7) can it be counted twice? (8) remaining business decision, if any.

| Category | Project-attributable? | CostLine? | Nature | Posts to GL? | In current margin? | Automatic or manual? | Double-count risk? | Remaining decision |
|---|---|---|---|---|---|---|---|---|
| **Materials** | Yes | Yes | Committed (PO/requisition) → Accrued (GRN) → Actual (issue) | Yes, at each stage | Yes (ACTUAL only) | Automatic, end-to-end from budget through issue | No — confirmed by Phase 1 audit: each stage explicitly retires the one before it (GRN-accrual-double-payment class of bug already found and fixed) | None — this path is the reference example of "done right" |
| **Supplier costs (Bills/POs)** | Yes | Yes | Committed → Accrued → Actual, via the three-way match | Yes | Yes (ACTUAL) | Automatic | No — `settled_by_bill_id` and the three-way-match fingerprint guard against double recognition (Phase 1, confirmed) | None |
| **Petty cash (requisition-based)** | Yes | Yes | Actual, created at surrender only | Yes, once (STAB-7) | Yes | Automatic | No — this was exactly STAB-7's defect, now fixed and re-verified in this task | None (STAB-7 closed the technical question; W3-4/W3-5/W3-6/W3-7 are process refinements, not costing questions) |
| **Direct disbursements** | Yes | Yes | Actual, immediate | Yes, once | Yes | Automatic | No — no future surrender exists for this path, so there is nothing to double-recognise against | None |
| **Spend Vouchers** | Yes, via the underlying CostLine | No — does **not** create a CostLine | N/A — settles an already-existing, already-verified liability | No new posting beyond the original cost line's own; the voucher's own posting is the *payment* (cash leaving), not a *cost* event | Indirectly, via the CostLine it settles | Automatic (allocation tied to a specific `cost_line_id`) | No — by design, `store()` requires an existing, verified `cost_line_id`; it cannot invent a new cost | None — this is a payment-settlement mechanism, not a cost-creation mechanism, and should stay that way |
| **Labour** | **No** | No | N/A | No | **Not included** | N/A | N/A — the risk here is omission, not duplication | **Already covered by Workflow 7 (W7-1)** — not re-decided here |
| **Logistics/transport** | **No** | No (manual re-entry required today; no system link) | N/A | Only if manually re-entered as a Bill/Cost Collector item | Only to the extent manually re-entered | Manual | Low direct risk today (nothing automatic to duplicate), but the manual re-entry step is itself a known integrity gap (a maintenance cost can be "approved" without ever reaching Finance) | **Already covered by Workflow 8 (W8-1, W8-2)** — not re-decided here |
| **Vehicle costs** | **No**, same as Logistics | Same as Logistics | Same as Logistics | Same as Logistics | Same as Logistics | Same as Logistics | Same as Logistics | **Already covered by Workflow 8** — not a separate question |
| **Equipment usage** | **No** | No | N/A | No | Not included | N/A | N/A | **Already covered by Workflow 9 (W9-1, Asset Finance)** — no link exists between acquisition cost and the Asset Register at all, let alone a per-project usage charge |
| **Subcontractors** | Yes | Yes | Same as any supplier cost — subcontractor is an **expense-code classification**, not a separate mechanism (confirmed: "subcontractor" appears only in the expense-code catalogue/seeders, never as a distinct model or workflow) | Yes | Yes | Automatic | No — same guards as any other Bill/Direct Bill | None — already correctly modelled through the existing Bill/Cost Collector pipeline |
| **Other project expenses** | Yes, generically | Yes | Whatever nature the producer reports | Yes | Yes if `ACTUAL` | Automatic, via whichever module reports it | No, subject to the same producer-level guards already audited elsewhere | None specific to this category |
| **WIP / Cost of Sales** | Yes | N/A — a release mechanism, not a cost-creation one | N/A | Yes, when triggered | Yes — `cost_basis: 'released'` takes priority over `'actual'` when present | Automatic once triggered, but **never triggered today** (no WIP accounts exist on the live chart) | No — dormant, not miscounting; `marginAgainstJournals()` correctly falls back to `'actual'` when nothing has been released | **Already covered by STAB-2** — not re-decided here |
| **Revenue** | Yes | N/A | N/A | Yes, at invoice issuance | Yes — `billed_revenue` is the sum of issued, non-void, posted invoices | Automatic | No — confirmed sound in the W1 audit (Client Receipt ≠ Revenue ≠ Allocation, each its own posting) | **Already covered by Workflow 1** — not re-decided here |
| **Credit notes** | Yes | N/A | N/A | Yes, mirror-image reversal | Yes — reduces `billed_revenue` immediately | Automatic | The known, named exception: a credit note reduces revenue immediately but does **not** yet reverse its share of released Cost of Sales/WIP (moot today only because nothing is released yet under the current no-WIP state — this becomes a live question the moment W1-10/STAB-2 are resolved in favour of WIP release) | **Already covered by W1-10** — not re-decided here |
| **Budget** | Yes | Yes (`NATURE_PLANNED`) | Planned | No | No (planned is excluded from margin, correctly) | Automatic, from the approved budget | No | None |
| **Commitments** | Yes | Yes (`NATURE_COMMITTED`) | Committed | No | No (committed is excluded from margin, correctly — confirmed by `marginAgainstJournals()` using `NATURE_ACTUAL` only) | Automatic | No — the existing release guards (e.g. `releaseRequisitionCommitment()`, `releaseFor()`) retire a commitment before/as its actual is recognised | None |
| **Actual cost** | Yes | Yes (`NATURE_ACTUAL`) | Actual | Yes | Yes | Automatic across every path traced above | No, per every specific finding above | None beyond what's already tracked elsewhere |

---

## Part 4 — Search for genuinely missing W6 decisions

Classification key: **ALREADY SUPPORTED** / **EXISTING CONTROL — PRESERVE** / **ALREADY COVERED BY
ANOTHER WORKFLOW** / **POTENTIAL MISSING WNG DECISION** / **ACCOUNTING-POLICY DECISION** /
**TECHNICAL DEFECT** / **NOT APPLICABLE**.

| Topic | Classification | Basis |
|---|---|---|
| Direct vs. indirect cost | **This is W6-1 itself** | Not a separate finding. |
| Overhead allocation | **ACCOUNTING-POLICY DECISION, folded into W6-1** | The allocation basis, if WNG chooses Option B/C, is an accounting decision — not invented here. |
| Labour allocation | **ALREADY COVERED BY WORKFLOW 7** | W7-1 already asks exactly this. |
| Logistics allocation | **ALREADY COVERED BY WORKFLOW 8** | W8-1/W8-2 already ask this. |
| Vehicle allocation | **ALREADY COVERED BY WORKFLOW 8** | Same mechanism as logistics; not a separate question. |
| Equipment/machinery usage | **ALREADY COVERED BY WORKFLOW 9** | W9-1 already asks this, at the acquisition-cost level; a per-project usage *rate* is a further-downstream question that presupposes W9-1's scope is settled first — noted as a dependency, not added as a new row. |
| Subcontractor cost | **ALREADY SUPPORTED** | Confirmed modelled correctly through the existing Bill/Direct Bill/expense-code mechanism — see Part 3. |
| Shared cost across multiple projects | **POTENTIAL MISSING WNG DECISION** | No mechanism was found for deliberately splitting one real cost (e.g. one delivery serving two projects) across more than one project's cost line — each cost line belongs to exactly one `project_enquiry_id`. Not previously raised in any workflow. |
| Cost transfers between projects | **POTENTIAL MISSING WNG DECISION** | No mechanism was found for moving a posted cost from one project to another after the fact (e.g. correcting a miscoded job number on an already-actual cost line). Related to, but distinct from, W3-7's petty-cash-specific correction path — this would apply to any cost line, from any producer. |
| Budget revisions | **NOT VERIFIED IN THIS REVIEW** | Whether/how an approved project budget can be revised upward or downward after work begins was not traced in this bounded review; flagging as unverified rather than asserting either way. |
| Committed vs. actual cost | **ALREADY SUPPORTED** | See Part 3 — both natures exist, are correctly distinguished, and margin correctly excludes committed. |
| Project closure | **POTENTIAL MISSING WNG DECISION** | No distinct "project cost closed" concept was found separate from the project/enquiry's own status elsewhere in the system — whether a closed project should still accept new cost lines, and what happens to any residual open commitment at closure, was not confirmed either way. |
| Late costs after project closure | **POTENTIAL MISSING WNG DECISION** | Directly follows from the above — not separately verified; genuinely unknown whether the system currently allows or blocks this. |
| Project write-offs | **POTENTIAL MISSING WNG DECISION** | No "write off remaining committed/unbilled cost" mechanism was found — an open commitment on a project that will never be billed further has no confirmed resolution path. |
| WIP release | **ALREADY COVERED BY STAB-2** | Not re-decided here. |
| Credit-note impact on cost/margin | **ALREADY COVERED BY W1-10** | Not re-decided here. |
| Project profitability ownership | **POTENTIAL MISSING WNG DECISION** | Who is accountable for a project's margin (Project Officer, Management, Finance) was not addressed by any existing register row — a genuine, unraised question distinct from *who can view* margin data. |
| Margin visibility by role | **POTENTIAL MISSING WNG DECISION** | No role-based restriction on who can see a project's margin/cost figures was confirmed either way in this review — worth WNG confirming whether margin is Management/Finance-only or visible more broadly (e.g. to Project Officers). |
| Margin thresholds/alerts | **ALREADY SUPPORTED, partially** | `CostAccountService::alerts()` already reads `margin_warning_percent`/`margin_escalation_percent` from `FinanceSetting` and flags a project below either — the mechanism exists; whether the configured percentages reflect real WNG policy was not verified (values themselves are a Finance setting, not hard-coded, so this is a data question, not a build question). |
| Portfolio profitability | **This is W6-2 itself** | Not a separate finding. |
| Completed vs. live project profitability | **POTENTIAL MISSING WNG DECISION** | Ties to "project closure" above — whether a completed project's margin should be treated/reported differently from a live one's (e.g. final vs. provisional) was not addressed anywhere found. |
| Historical project profitability | **NOT VERIFIED IN THIS REVIEW** | Whether margin can be computed as of a past date (not just "as of now") was not traced; flagged as unverified. |

**No technical defect, and nothing comparable in severity to STAB-7, was found in this review.**

---

## Part 5 — Financial-integrity stop-rule check (performed, not triggered)

Specifically checked for a STAB-7-class defect and found none:

- **Does the margin calculation double-count any cost?** No — `marginAgainstJournals()` sums
  `NATURE_ACTUAL` cost lines exclusively (or the WIP-released figure when one exists), correctly
  excluding `PLANNED` and `COMMITTED` lines from the cost-of-sales figure.
- **Do GL and Cost Collector systematically disagree?** No evidence of systematic disagreement was
  found; the STAB-7 investigation already traced this relationship exhaustively for petty cash, and
  the Bill/PO/GRN three-way match was independently confirmed sound in Phase 1.
- **Is project revenue duplicated?** No — `billed_revenue` sums issued, non-void invoices with a
  posted journal entry exactly once each; this mirrors the already-audited, already-sound W1
  revenue-recognition trace.
- **Is actual cost ever omitted from the GL despite being treated as posted?** No instance found in
  this review; every `NATURE_ACTUAL` cost line traced in Part 3 has a confirmed posting path.
- **Is project cost ever silently posted to an unrelated liability/account?** The one historically
  similar pattern (an unresolved `settlementAccountFor()` fallback landing on the wrong account) was
  precisely STAB-7's Defect #2, now fixed; no new instance of this pattern was found elsewhere in
  this review's scope.

This task therefore proceeds as ordinary Workflow 6 analysis, not as a stabilization escalation.
