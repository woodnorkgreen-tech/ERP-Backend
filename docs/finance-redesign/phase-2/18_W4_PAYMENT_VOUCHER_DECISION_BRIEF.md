# 18 — Workflow 4 (Payment Vouchers) Decision Brief, for WNG Review

Prepared 2026-09-23. **Neither W4-1 nor W4-2 is decided by this document** — it presents the
current Spend Voucher lifecycle and controls, the options already on record in
`03_WNG_FINANCE_DECISION_REGISTER.md`, and the practical impact of each, so WNG can decide. It also
reports a read-only search for additional genuinely missing decisions (Part 12 of the originating
task), classified rather than assumed to matter.

**No financial-integrity defect comparable to STAB-7 was found anywhere in this review.** Nothing
below required stopping this task early — see the classification table at the end for the full
search result.

---

## Current Spend Voucher lifecycle

```
Create (draft/pending_approval)
   -> Approve
   -> Post
   -> Reconcile (bank statement matching, separate mechanism)
   -> Reverse if necessary
```

A Spend Voucher settles an already-verified liability (a Cost Collector `CostLine` allocation) — it
is confirmed **not** a cash-advance mechanism; that concern belongs to Cash Requisition (petty
cash), which owns "cash before spending" outright (per the code's own comment in
`SpendVoucherController::store()`, dated to the "Payment Voucher Naming Collapse" cleanup).

## Current controls (confirmed sound, this is the reference pattern for the whole module)

- **Three distinct permissions gate the lifecycle** — create, approve, post — each independently
  checkable and independently held.
- **Approve blocks self-approval** unless `SelfApproval::allowedFor()`; **Post blocks both** the
  original requester and the approver from also being the poster, unless overridden. No two of the
  three roles can be the same person on the same voucher without an explicit, auditable exception.
- **Genuinely append-only.** No `update()`/edit endpoint exists at all — a voucher is only ever
  transitioned between statuses, never altered in place.
- **Cancellation before posting works correctly.** `cancel()` (requester only, `pending_approval`/
  `draft` only) releases every reserved cost-line allocation in the same transaction before setting
  the voucher to `rejected` — reservations are never left dangling.
- **Reversal after posting is sound.** `PaymentReversalService::reverse()` refuses an already-voided
  or reconciled payment, reverses every journal entry the payment produced in one transaction, voids
  (never deletes) the payment, and sets a terminal `reversed` status — confirmed additive-not-
  destructive, unlike the STAB-7-adjacent gap found on the petty-cash-surrender side (W3-7), which
  does not apply here since a Spend Voucher's payment is always a genuine `Payment` row this service
  can find directly.
- **Payment-source integrity is enforced.** `payment_source_id` is validated against
  `can_make_payment = true`, closing the historical "Supplier Credit as a paying account" wash-entry
  defect for this path too.
- **Allocation totals are checked exactly.** The sum of `allocations.*.amount` must equal
  `total_amount` to the cent (`bccomp`), and each allocation ties to a specific, real, verified
  `CostLine` — project/job attribution rides along automatically through the underlying cost line,
  not as a separately-typed field.

## Genuine gaps in current controls

- **No approver-initiated rejection path exists.** The only route to `status='rejected'` is
  `cancel()`, restricted to the original requester — an approver who disagrees can only withhold
  approval indefinitely, with no way to formally send it back or refuse it (this is W4-1).
- **No amount-based approval escalation tier exists.** The identical permission gates a KES 500 and
  a KES 5,000,000 voucher alike — contrast Procurement's already value-aware `PurchaseApprovalPolicy`
  (this is W4-2).
- **No supporting-document/evidence attachment mechanism** — the same Finance-wide gap already
  tracked as W2-2, not a new, separate finding for vouchers specifically.
- **No duplicate-voucher/payment detection** — no check was found preventing the same payee +
  payment reference (or the same cost-line allocation) from being paid twice via two separate
  vouchers, the same class of gap already confirmed and tracked as W2-5/W3-5 on the other two
  expense rails.

---

## W4-1 — Reviewer rejection

| | |
|---|---|
| **Current behaviour** | No approver-initiated rejection path exists; only the requester can cancel, only pre-approval. |
| **Option A** | Reviewer can approve or return/reject with a mandatory reason. |
| **Option B** | Reviewer may only recommend; the Approver (a separate role in a longer chain) handles rejection. |
| **Option C** | Another control already supported by the existing workflow (e.g. relying on the requester's own `cancel()` plus informal communication). |
| **Practical impact of A** | A new `reject()` action, separate from `cancel()`, requiring a reason, available to whoever holds the approve permission — small, well-bounded addition to an already-clean state machine (`pending_approval`/`draft` → `rejected`). |
| **Practical impact of B** | Only applicable if WNG's real approval chain has more than the current two named steps (approve, post) — as documented today, there is no separate "reviewer" role distinct from "approver," so this option would first require WNG to introduce one. |
| **Practical impact of C** | No build; formalizes today's reality (a disagreeing approver has to reach the requester out-of-band to ask for a cancellation) as the deliberate choice. |
| **Decision owner** | Finance/Accounts Lead |
| **Implementation dependency** | None technical for Option A; Option B depends on a role model WNG has not defined for this workflow. |

## W4-2 — Value-based approval

| | |
|---|---|
| **Current behaviour** | One approval tier regardless of amount; contrast the already value-aware `PurchaseApprovalPolicy` on the procurement side (W2-1). |
| **Option A** | Same approval chain for all voucher values. |
| **Option B** | Additional senior approval above a WNG-defined value (mirrors the W2-1 direction WNG already confirmed for Purchase Orders). |
| **Option C** | Multiple configurable approval tiers. |
| **Practical impact of A** | No build; the current single-tier control is confirmed as the deliberate choice. |
| **Practical impact of B** | Smallest build: one additional permission/approval step gated on a threshold, directly analogous to the W2-1 pattern already confirmed — could reuse the same technical shape. |
| **Practical impact of C** | Larger build: a genuine tier table (amount ranges → required approver role), more flexible but more to build and maintain. |
| **Decision owner** | Management + Finance/Accounts Lead |
| **Implementation dependency** | None technical; blocked only on WNG supplying the threshold(s) and naming the senior approver(s), same as W2-1. |

---

## Missing-decision search (read-only)

Classification key: **ALREADY SUPPORTED** / **EXISTING CONTROL — PRESERVE** / **POTENTIAL MISSING
DECISION** / **TECHNICAL DEFECT** / **NOT APPLICABLE**.

| Topic | Classification | Basis |
|---|---|---|
| Maker/checker/poster separation | **ALREADY SUPPORTED** | Three distinct permissions, two independent self-approval/self-post guards — confirmed the strongest control pattern in the audited codebase. |
| Reviewer return/rejection | **POTENTIAL MISSING DECISION** | Tracked as W4-1 above. |
| Approver rejection | **POTENTIAL MISSING DECISION** | Same gap as reviewer return — W4-1 covers both framings. |
| Amount escalation | **POTENTIAL MISSING DECISION** | Tracked as W4-2 above. |
| Supporting documents | **POTENTIAL MISSING DECISION** | Inherits the already-tracked, Finance-wide W2-2 gap (no generic attachment mechanism exists anywhere) — not a new, voucher-specific finding. |
| Duplicate voucher/payment prevention | **POTENTIAL MISSING DECISION** | No check found preventing the same payee/reference/allocation being paid twice across two vouchers — the same class of gap as the already-confirmed W2-5/W3-5. |
| Amendment after submission | **EXISTING CONTROL — PRESERVE** | Deliberately append-only by design (no `update()` exists at all) — this is a genuine, intentional control, not an oversight, and should not be weakened. A requester needing to fix a mistake pre-approval already has `cancel()` + resubmit as a full substitute for "amend." |
| Cancellation before posting | **ALREADY SUPPORTED** | `cancel()` correctly releases reserved cost-line allocations before rejecting the voucher. |
| Reversal after posting | **ALREADY SUPPORTED** | `PaymentReversalService::reverse()` — sound, additive, confirmed working for `Payment`-sourced entries, which every Spend Voucher payment is. |
| Partial payment | **ALREADY SUPPORTED, tentatively** | `store()`'s allocation validation ties each voucher to specific cost-line allocations of any positive amount up to the liability, rather than forcing one voucher to fully settle one cost line — this appears to allow a liability to be paid across more than one voucher over time, unlike the one-Bill-per-PO restriction on the procurement side (W2-3). Not confirmed by a dedicated test in this review; Phase 2B should verify this behaviour directly before relying on it. |
| Payment source selection | **ALREADY SUPPORTED** | Validated against `can_make_payment = true`; the historical Supplier Credit wash-entry defect is closed here too. |
| Project/job attribution | **ALREADY SUPPORTED** | Rides through the underlying `CostLine` allocation automatically — never a separately-typed, and therefore never a separately-wrong, field. |
| Supplier/employee/other payee types | **PARTIALLY SUPPORTED** | Voucher `type` is only `payment` or `reimbursement`; the payee (supplier or employee) is derived from the underlying cost line's beneficiary, not chosen freely. Cost Collector's own broader `payeeType` concept (`supplier\|employee\|casual\|authority`, per `CostContext`) is not reflected as a distinct Spend Voucher type — a casual-labour or statutory-authority payment through this path is possible but not separately classified the way it is elsewhere in Cost Collector. |
| Emergency/after-hours voucher | **NOT APPLICABLE** | A Spend Voucher settles an already-verified liability; it structurally cannot be an "emergency, pay-first" instrument the way a petty-cash disbursement or a Direct Bill can — the emergency-purchase question belongs to W2-10 and any petty-cash equivalent, not here. |
| Payment evidence | **POTENTIAL MISSING DECISION** | Same as "Supporting documents" above — one Finance-wide gap, not a separate voucher-specific one. |
| Voucher ageing/stale approvals | **POTENTIAL MISSING DECISION** | No date-based staleness signal or reminder mechanism was found for a voucher sitting in `pending_approval` for an extended period — analogous to the "Needs action" visibility gaps already found elsewhere in Finance (current-state audit, Receivables A.6). Not confirmed absent by an exhaustive search; not found in the controller or model reviewed here. |
| Delegation/absence of approver | **POTENTIAL MISSING DECISION** | No approver-delegation or substitute-approver mechanism was found — if the sole holder of the approve permission is unavailable, a voucher has no defined path forward besides granting the permission to someone else administratively. Not confirmed absent by an exhaustive search of the permission system as a whole. |

**No technical defect, and nothing comparable in severity to STAB-7, was found in this review.**
Everything above is either an existing, sound control worth preserving, or a business-process
decision genuinely left to WNG — not a live data-integrity or double-posting risk.
