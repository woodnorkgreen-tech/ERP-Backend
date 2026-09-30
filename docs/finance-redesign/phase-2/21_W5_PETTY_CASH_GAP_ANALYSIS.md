# 21 — W5 Petty Cash: Current-vs-Target Gap Analysis (Read-Only)

Read-only analysis comparing the WNG-confirmed W5 decisions (`03_WNG_FINANCE_DECISION_REGISTER.md`,
confirmed 2026-09-23) against the current codebase. **No code changed as a result of this
document.** STAB-4 and STAB-7 are preserved exactly as implemented and are not re-analyzed here
beyond confirming they still hold.

Classification key: **ALREADY SUPPORTED** / **PARTIALLY SUPPORTED** / **NOT SUPPORTED** /
**EXISTING CONTROL — PRESERVE** / **FACTUALLY RESOLVED** / **BLOCKED BY WNG DECISION** /
**BLOCKED BY FINANCE/ACCOUNTANT DECISION**.

| Requirement | WNG Requirement | Current Behaviour | Gap | Future Change Required | Dependency |
|---|---|---|---|---|---|
| W5-1 / STAB-4 | Visible failure, Finance alert, audit trail, controlled retry, no duplicate posting | `PettyCashAdvancePoster` implements all five, verified by `PettyCashAdvancePostingTest.php` | **FACTUALLY RESOLVED.** No gap. | None | — |
| STAB-7 interaction | One economic expense recognised once; Advance = Dr Staff Advance/Cr Float; Surrender = Dr Project/Overhead Cost/Cr Staff Advance | Confirmed correct and unchanged by this task: `PettyCashCostProducer::postFor()` excludes requisition-linked disbursements; surrender-item CostLines use `postsIndependently: false`; `postPettyCashSurrender()` is the sole posting owner | **FACTUALLY RESOLVED.** No gap; re-verified against the current codebase during this task, not just assumed. | None | — |
| W5-2 | Approval view surfaces amount, requester, classification, justification, float availability, requester's recent activity, outstanding advances, evidence, budget/exception info | Only the raw request payload and free-text justification are shown today; `rejectDirectRequest()` (mandatory reason) already exists and is unaffected | **NOT SUPPORTED** for the additional signals; the reject mechanism itself is **ALREADY SUPPORTED** | Compute and surface the listed signals on the approval screen/endpoint — read-only additions over existing data (float balance, requester's requisition history) | None technical |
| W5-3 | `created_by` vs. `held_by`, handover record with outgoing/incoming custodian, handover date/time, float balance, physical count, variance, confirmation | `FundCustodyService` reports the top-up creator as "custodian"; no `held_by` column, no handover record/model exists anywhere | **NOT SUPPORTED.** Confirmed absent in full | Add a custodian/`held_by` concept independent of `created_by`; add a handover record type capturing the required fields | None technical |
| W5-4 | Simplified requester-facing fields only, identical underlying requisition/approval/coding/budget/evidence/Payment/surrender/Cost Collector/accounting/audit architecture | One full-featured form serves everyone today; no reduced-field variant exists | **NOT SUPPORTED.** Confirmed absent | Build a reduced-field UI variant over the existing `PettyCashRequisition` creation endpoint — a presentation-layer change, not a new transaction type | None technical |
| W5-5 | Configurable Normal/Low/Critical float thresholds | `PettyCashBalance::isLow(1000.00)`/`isCritical(500.00)` are hard-coded PHP default parameter values, not read from any settings table | **NOT SUPPORTED.** Confirmed hard-coded, not configurable | Add a Finance-editable setting (e.g. via `FinanceSetting`, the same mechanism already used for `margin_warning_percent`/`purchase_order_auto_approval_limit`) and read it in place of the hard-coded defaults | **BLOCKED BY WNG DECISION** — the actual KES amounts |
| W5-6 | One company-wide float, for now | Confirmed: `PettyCashBalance::current()` is a hard-coded singleton; no second float, no contradicting evidence found anywhere in the repository | **ALREADY SUPPORTED** (matches the confirmed direction exactly, since it asks to retain today's behaviour) | None now; if Option A is chosen later, this becomes a substantial architecture change (see the register's own caution) | — |
| W5-7 | Cash-count record: date/time, counted amount, system balance, variance, shortage/overage, counter, custodian, verifier, explanation, corrective reference, evidence; variance never silently changes the ledger | Confirmed absent entirely — no counted-amount, variance, or shortage/overage concept exists anywhere in the petty-cash module | **NOT SUPPORTED.** Confirmed absent in full | Add a new cash-count record type and a read-only variance computation (`counted − system balance`); the correction path for a real variance must go through a controlled, auditable mechanism, not a direct balance edit | **BLOCKED BY FINANCE/ACCOUNTANT DECISION** — shortage/overage GL treatment; count frequency is a separate, lighter operational decision, also open |
| W5-8 | Surrender due date; Awaiting Surrender / Due Soon / Overdue / Surrendered / Reconciled states; visibility to requester, Finance, approver/manager; period-end reportable from the same data | Confirmed absent — no due-date/deadline field exists on `PettyCashRequisition`; the only way to see outstanding advances today is an ad hoc status filter, not a dedicated ageing view | **NOT SUPPORTED.** Confirmed absent | Add a `surrender_due_at` (or equivalent) field, populated from a configurable policy and/or an explicit transaction-specific date; build an ageing view/report over existing status and date fields — no new transaction type needed | **BLOCKED BY WNG DECISION** — the deadline policy itself |
| W5-9 | Outstanding-advance check before approving/disbursing another advance to the same employee, with a not-overdue-shown / overdue-blocked-unless-exception model | Confirmed absent — no guard checks an employee's other open requisitions anywhere in `PettyCashRequisitionController` | **NOT SUPPORTED.** Confirmed absent | Add the check at approval and/or disbursement time, reading the employee's other requisitions by status (and, once W5-8 exists, by overdue status); add the exception-capture fields the confirmed direction requires | **BLOCKED BY WNG DECISION** — advance-count/monetary limits and the exception-approver role; also depends on W5-8 existing first for the "overdue" half of the check |
| Cash returned (unused advance) | Preserve | Confirmed correct — `postPettyCashSurrender()`'s `cash_returned_amount` handling; re-verified by STAB-7 scenario B | **ALREADY SUPPORTED** | None | — |
| Overspend/reimbursement | Preserve | Confirmed correct — the existing overspend leg crediting the source account for the excess; re-verified by STAB-7 scenario C | **ALREADY SUPPORTED** | None | — |
| Top-ups | Preserve | Confirmed sound — `PettyCashTopUpController::destroy()` refuses deletion with a linked disbursement and posts a compensating reversal first | **EXISTING CONTROL — PRESERVE** | None | — |
| Direct disbursements | Preserve | Confirmed sound and correctly unaffected by STAB-7 (no `requisition_id`, so `postFor()`'s exclusion never applies); `rejectDirectRequest()` already exists | **EXISTING CONTROL — PRESERVE** | None | — |
| Duplicate evidence | *(Not a W5 item — see W3-5.)* | — | N/A | No action here | **ALREADY COVERED BY W3-5** |
| Return for Correction | *(Not a W5 item — see W3-6.)* | — | N/A | No action here | **ALREADY COVERED BY W3-6** |
| Post-posting reversal | *(Not a W5 item — see W3-7.)* | — | N/A | No action here | **ALREADY COVERED BY W3-7** |
| Project/overhead coding | Preserve | Confirmed distinct and enforced throughout Cost Collector integration; re-verified by STAB-7 scenario F | **ALREADY SUPPORTED** | None | — |

## What this means for Phase 2B

- W5-3 (custodian/handover) and W5-7 (cash count) share a natural data model: a handover is, in
  effect, a cash count that also changes who is accountable — Phase 2B should design the cash-count
  record first and let a handover be a cash count with an extra "new custodian" field, rather than
  building two separate mechanisms.
- W5-8 (surrender deadlines) and W5-9 (outstanding-advance limits) are sequential: W5-9's "overdue"
  branch cannot be evaluated until W5-8's due-date concept exists. Phase 2B should build W5-8 first.
- W5-5's configurable thresholds should reuse the existing `FinanceSetting` mechanism already used
  elsewhere in Finance, not a new settings table.
- Nothing in this workflow requires touching STAB-4 or STAB-7's implementation — every new W5
  capability is additive (new fields, new record types, new read-only views) layered on top of the
  already-correct advance/surrender posting pair.
