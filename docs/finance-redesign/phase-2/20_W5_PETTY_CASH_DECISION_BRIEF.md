# 20 — Workflow 5 (Petty Cash) Decision Brief, for WNG Review

Prepared 2026-09-23. **W5-1 is reconciled against STAB-4 and is not reopened as undecided. None of
W5-2 through W5-9 is decided by this document.** It presents current behaviour, confirmed
directions already on record, options for what remains open, and a bounded read-only search for
additional genuinely missing decisions (per the originating task's Part 9), each classified rather
than assumed to matter.

**No financial-integrity defect comparable to STAB-7 was found anywhere in this review.**

---

## W5-1 — GL-posting-failure handling (reconciled with STAB-4)

This is **not** an open question. STAB-4 already confirmed and implemented the direction: a
failed advance GL posting no longer disappears into a log line — `PettyCashAdvancePoster` flags the
requisition (`advance_gl_posting_failed_at`/`advance_gl_posting_error`), alerts every holder of
`finance.petty_cash.edit_disbursement`, and a `POST .../retry-advance-posting` endpoint provides a
controlled, idempotent retry. Verified in `PettyCashAdvancePostingTest.php` (4 tests). W5-1's
register row is retained only as a pointer to STAB-4, not as a separate open item.

## W5-2 — Exceptional (Direct Disbursement) approval signals

| | |
|---|---|
| **Current behaviour, re-confirmed 2026-09-23** | `DirectDisbursementRequest.payload` stores exactly the raw disbursement fields (`CreateDisbursementRequest`'s validated shape) submitted at request time. `approveDirectRequest()`/`directRequests()` add no computed signal — no current float balance, no count of this requester's recent direct requests, no urgency flag — anywhere in the approval view. **One correction to the earlier framing:** a proper `rejectDirectRequest()` action, requiring a reason of at least 10 characters, already exists for this specific path — the "no formal rejection path" gap identified for Spend Vouchers (W4-1) does **not** apply to Direct Disbursement Requests. |
| **Option A** | Add float-availability and repeat-request signals to the approval screen. |
| **Option B** | Current free-text reason is sufficient. |
| **Option C** | Add signals only above a value threshold. |
| **Decision owner** | Finance/Accounts Lead |
| **Status** | AWAITING WNG CONFIRMATION (unchanged) |

## W5-3 — Custodian: recorder or physical holder?

| | |
|---|---|
| **Current behaviour, re-confirmed 2026-09-23** | `FundCustodyService::overview()` literally reports `'custodian' => $topUp->creator?->name` — whoever's user id created the top-up row. No `held_by`/physical-custodian column exists anywhere in the petty-cash schema. |
| **Option A** | Add a distinct physical-custodian field. |
| **Option B** | Current behaviour is acceptable. |
| **Option C** | Track both, display both. |
| **Decision owner** | Finance/Accounts Lead |
| **Status** | AWAITING WNG CONFIRMATION (unchanged) |

## W5-4 — Simplified request form for occasional requesters

| | |
|---|---|
| **Current behaviour** | One set of petty-cash screens serves every requester, regular or occasional; no reduced-field variant exists. |
| **Option A** | Build a simplified form, on the same underlying transaction/control architecture. |
| **Option B** | Current forms are acceptable for everyone. |
| **Option C** | Simplify only specific fields, keep the rest. |
| **Decision owner** | SOP/Process Owner |
| **Status** | AWAITING WNG CONFIRMATION (unchanged) |

---

## Bounded read-only search for additional missing W5 decisions

Classification key: **ALREADY SUPPORTED** / **EXISTING CONTROL — PRESERVE** / **ALREADY COVERED BY
W2/W3/W4** / **TECHNICAL DEFECT** / **POTENTIAL MISSING WNG DECISION** / **NOT APPLICABLE**.

| Topic | Classification | Basis |
|---|---|---|
| Float ownership/custody | **Same gap as W5-3** | Not a separate finding — see above. |
| Float top-up | **ALREADY SUPPORTED** | `PettyCashTopUpController`/`TopUpAllocator` work correctly; `destroy()` refuses to delete a top-up with any linked disbursement and posts a compensating reversal ledger entry before removing the row (confirmed, Phase 1 audit §16). |
| Minimum/maximum float | **POTENTIAL MISSING WNG DECISION** | Added to the register as **W5-5** — `isLow()`/`isCritical()` default to fixed KES 1,000/500, not a configurable, WNG-set policy. |
| Float transfers | **NOT APPLICABLE** | No multiple floats exist to transfer between (see W5-6). |
| Multiple cash boxes/floats | **POTENTIAL MISSING WNG DECISION** | Added to the register as **W5-6** — `PettyCashBalance::current()` is a hard-coded singleton (`firstOrCreate(['id' => 1], ...)`); the architecture assumes exactly one company-wide float. |
| Physical cash count | **POTENTIAL MISSING WNG DECISION** | Added to the register as **W5-7**, together with the next three rows — no counted-vs-system-balance concept exists anywhere. |
| Surprise cash count | **POTENTIAL MISSING WNG DECISION** | Same finding as physical cash count — folded into **W5-7** rather than a separate row, since a surprise count is the same mechanism run unannounced. |
| Cash count variance | **POTENTIAL MISSING WNG DECISION** | Same finding — folded into **W5-7**. |
| Shortage/overage | **POTENTIAL MISSING WNG DECISION** | Same finding — folded into **W5-7**; a cash-count record with variance tracking would naturally surface a shortage or overage. |
| Surrender deadlines | **POTENTIAL MISSING WNG DECISION** | Added to the register as **W5-8** — confirmed no due-date/deadline field exists on `PettyCashRequisition`. |
| Overdue surrender | **POTENTIAL MISSING WNG DECISION** | Same finding — folded into **W5-8**; an overdue view requires the due date to exist first. |
| Employee with multiple outstanding advances | **POTENTIAL MISSING WNG DECISION** | Added to the register as **W5-9** — no guard found checking an employee's other open requisitions before approving/disbursing a new one. |
| Requester limits | **Same gap as W5-9** | A per-person cap distinct from project-budget/expenditure-exception controls was not found; treated as the same underlying question as multiple outstanding advances rather than a separate row. |
| Direct/emergency disbursement | **ALREADY COVERED BY W5-2** | The Direct Disbursement Request mechanism exists and is already the subject of W5-2. |
| Approval thresholds | **ALREADY COVERED BY W2/W4 pattern** | Petty cash has no value-tiered approval today (only the existing project-budget/expenditure-exception gate, a different concern) — if WNG wants value-tiered approval generally, the same mechanism being defined for W2-1/W4-2 should extend here rather than a fourth, separate implementation. Not added as its own row to avoid a fourth copy of the same underlying question. |
| Evidence | **ALREADY COVERED BY W3-4** | Not a separate petty-cash-only question. |
| Duplicate receipt detection | **ALREADY COVERED BY W3-5** | Not a separate petty-cash-only question. |
| Return for correction | **ALREADY COVERED BY W3-6** | Not a separate petty-cash-only question. |
| Reversal after reconciliation | **ALREADY COVERED BY W3-7** | Not a separate petty-cash-only question. |
| Unused cash returned | **ALREADY SUPPORTED** | Confirmed correct by STAB-7's own regression scenario B: `cash_returned_amount` is handled correctly in both the ledger and the surrender clearing journal. |
| Excess spending above advance | **ALREADY SUPPORTED** | Confirmed correct by STAB-7's own regression scenario C: `postPettyCashSurrender()`'s existing overspend/reimbursement leg is sound and was verified unaffected by the STAB-7 fix. |
| Project vs. overhead coding | **ALREADY SUPPORTED** | Confirmed distinct and enforced treatment throughout the Cost Collector integration (Phase 1 audit; re-confirmed during STAB-7's scenario F). |
| Cash-to-bank/mobile-money transfers | **NOT APPLICABLE, tentatively** | No mechanism was found for moving float cash back to a bank/mobile account (the reverse of a top-up) — not confirmed as a real WNG operational need in this review; included for completeness rather than as a strong finding. |
| Float closure | **NOT APPLICABLE** | No multiple floats exist to open or close (see W5-6); closing the single company float is not an ordinary business scenario. |
| Custodian handover | **Same gap as W5-3** | No dedicated "handover" transaction exists; the underlying question is the same as W5-3 (recorder vs. physical holder), not a separate one. |
| Approver absence | **ALREADY COVERED BY W4-4** | Same cross-module question already raised for Payment Vouchers; petty cash has the identical gap (no delegation mechanism) rather than a distinct one. |
| Reconciliation responsibility | **Same gap as W5-7** | The per-requisition `reconcileSurrender()` has a clear actor, but there is no float-level reconciliation (someone periodically verifying the system balance against physical cash) distinct from the cash-count gap already raised. |
| Period-end outstanding advances | **Same gap as W5-8** | No dedicated report exists; folded into the surrender-deadline/ageing finding rather than a separate row. |

**No technical defect, and nothing comparable in severity to STAB-7, was found in this review.**
Five genuinely new, non-redundant candidates were added to the register (W5-5 through W5-9); every
other topic in the search either already has a home in an existing decision, is already supported,
or is not applicable to WNG's current single-float architecture.
