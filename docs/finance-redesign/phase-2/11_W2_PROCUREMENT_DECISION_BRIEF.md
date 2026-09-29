# 11 — Workflow 2 (Procurement to Payment) Decision Brief, for WNG Review

Prepared 2026-09-23. **None of W2-1 through W2-4 is decided by this document** — it presents the
current workflow, current controls, the options already on record in
`03_WNG_FINANCE_DECISION_REGISTER.md`, and the practical impact of each, so WNG can decide. It also
flags additional candidate decisions found while reviewing the current implementation, which WNG
may or may not choose to add to the register.

---

## Current workflow

```
Purchase Request (Requisition)
   -> Approval
   -> Purchase Order (status: pending -> pending_approval -> approved)
   -> Goods / Service Confirmation (Goods Receipt Note, one or more per PO)
   -> Supplier Bill (PO-backed, or a Direct Bill with no PO)
   -> Bill Verification (three-way match)
   -> Payment Approval
   -> Payment
   -> Bank Reconciliation
   -> Accounting
```

A Purchase Order, a Supplier Bill, and a Payment remain three separate documents, three separate
tables, three separate numbering series, never merged into one "purchase transaction" record — this
is confirmed sound and is not in question here (`current-state/05_CURRENT_WORKFLOWS.md` B.8).

## Current controls (what already works and should not be redesigned)

- **Three-way match.** `PurchaseOrderWorkflow::bill()` checks order approval, supplier match,
  invoice number recorded, goods accepted by Stores, invoice ≤ value accepted into stock, invoice ≤
  approved order total — and stamps a sha256 fingerprint of everything checked. If the order,
  receipt, or invoice figures change after sign-off, the stored fingerprint stops matching and
  `verified` flips back to `false` automatically. Direct Bills (no PO) get a narrower, analogous
  check.
- **Value-aware auto-approval, already built.** `PurchaseApprovalPolicy::evaluate()` auto-approves
  an order that is fully "covered" by an already-approved requisition of at least that value, and
  applies an *optional*, Finance-signed ceiling above which any order goes back to a person
  regardless of cover. An order with no requisition behind it, or one that grew past its
  requisition's approved value, always goes to a human. This is real value-awareness — it is not
  the "one approval step regardless of value" that older documentation described.
- **Separation of duties on approval.** The person who raised a PO cannot approve it themselves,
  unless separately granted the self-approve exception — the same pattern used on Spend Vouchers,
  Payroll, and other Finance approvals.
- **Payment controls.** Over-payment is blocked at three independent layers for both single and
  batch bill payments; a batch is refused whole if any one bill in it is blocked; the historical
  "Supplier Credit as a paying account" defect is fixed and guarded at every layer
  (`current-state/05_CURRENT_WORKFLOWS.md` B.6).
- **PO mutability, now closed (STAB-3/W2-4 safety guard, implemented 2026-09-23).** No PO outside
  `pending` status can be directly edited or deleted; the FK relationships from PO to its items,
  GRNs, and bills were changed from CASCADE to RESTRICT as defense-in-depth. This closed a
  previously CRITICAL risk (an approved, paid PO could be silently rewritten or its cascade-deleted
  children could wipe part of the GL trail).
- **Partial/staged goods receipt is already architecturally supported.** `GoodsReceiptNoteItem`
  tracks `ordered_quantity` separately from `received_quantity`, and a `PurchaseOrder` can have
  many `GoodsReceiptNote`s (`hasMany`, not `hasOne`) — a PO delivered in stages across multiple
  GRN events is not a gap. (Contrast this with **billing**, below, which is restricted to one Bill
  per PO regardless of how many GRNs exist.)

## Genuine gaps in current controls

- **No supporting-document attachment mechanism exists for Finance at all** — not "incomplete," but
  entirely absent. There is no generic `attachments` table, no polymorphic attachment relation
  anywhere in the Finance or Procurement modules. Today, evidence is limited to typed reference
  fields (invoice number, supplier PIN, eTIMS number) — never a scanned copy or PDF held in-system.
- **Only one Bill may be raised per PO**, enforced explicitly in `BillController::store()` ("This
  purchase order already has a bill"), regardless of how many GRNs exist against that PO.
- **Duplicate supplier invoices are not fully blocked.** One cross-module guard exists (Bill vs.
  Cost Collector `unpaid_invoice` entries for the same supplier+invoice-number), but nothing
  prevents the same supplier + the same `supplier_invoice_number` being entered as two separate
  `Bill` rows outright — the most common real-world duplicate scenario.
- **Supplier payment bank references are not checked for duplicates** — `bill_payments.reference_number`
  carries no uniqueness constraint, while the equivalent client-side field does. The same bank
  transfer could in theory be recorded as two separate payments with no system-level catch.
- **No way exists today to correct or amend an order once it leaves `pending`**, including one still
  awaiting a human approval decision (`pending_approval`, not yet `approved`). There is no
  `reject()`/return-to-`pending` action — combined with the new STAB-3 safety guard, a PO submitted
  for approval with a typo or error cannot currently be fixed by anyone; the only way forward is
  approval as submitted, or leaving it stuck.

---

## W2-1 — Purchase Order approval levels

| | |
|---|---|
| **Current behaviour** | Not literally one flat approval tier: an order fully covered by an already-approved requisition auto-approves; an optional Finance-signed ceiling can force manual approval above a set amount regardless of cover. Manual approval itself, when it happens, is a single non-tiered role check — no "small order needs one signer, large order needs two" scaling exists. |
| **Option A** | PO approval authority scales with order value (WNG supplies the tiers/amounts and who approves each). |
| **Option B** | One approval level applies regardless of value (today's manual-approval behaviour, formalized as the deliberate choice). |
| **Option C** | Normal approval until a defined high-value threshold, above which additional/senior approval is required. |
| **Practical impact of A** | Requires WNG to name specific monetary tiers and the approver(s) for each — without that, nothing can be built. Extends the existing `PurchaseApprovalPolicy` cover/ceiling logic with named tiers rather than replacing it. |
| **Practical impact of B** | No new build — current behaviour already matches this if formally confirmed. |
| **Practical impact of C** | Simpler than A (one threshold, not several); still requires WNG to name the single amount and the senior approver. |
| **Decision owner** | Management + Procurement Lead |
| **Implementation dependency** | None technical; blocked only on WNG supplying amounts if A or C is chosen. |

## W2-2 — Supplier supporting documents

| | |
|---|---|
| **Current behaviour** | Typed reference fields only; no attachment mechanism exists anywhere in Finance/Procurement to attach a scan or PDF. |
| **Option A** | Supplier invoice/evidence must be attached in the ERP. |
| **Option B** | Supporting documents may remain outside the ERP (current de facto state). |
| **Option C** | ERP attachment becomes mandatory only above a defined threshold/type. |
| **Practical impact of A** | Requires building a new, generic attachment capability from nothing — a real, non-trivial feature (storage, virus/type validation, retention, access control), not an extension of an existing mechanism. Directly relevant to KRA input-VAT defensibility (flagged separately in Phase 1's confirmation questions). |
| **Practical impact of B** | No build; the current gap remains a named, accepted risk rather than an oversight. |
| **Practical impact of C** | Same build as A, scoped down initially to bills above the threshold — still a new capability, just smaller in first-release surface area. |
| **Decision owner** | Finance/Accounts Lead |
| **Implementation dependency** | None technical; this is a scope decision, not a data dependency. |

## W2-3 — Multiple/partial bills against one PO

| | |
|---|---|
| **Current behaviour** | Exactly one Bill per PO, enforced in code (`BillController::store()`). Partial/staged goods receipt itself is already supported (multiple GRNs per PO) — only billing is restricted to one document. |
| **Option A** | Multiple/staged supplier bills against one PO. |
| **Option B** | Exactly one supplier bill per PO (current behaviour, confirmed as deliberate). |
| **Option C** | Partial billing is exceptional, handled separately (e.g. via Direct Bill) rather than as a first-class PO feature. |
| **Practical impact of A** | Requires reworking the three-way match and "amount already billed vs. PO total" balance to work across multiple bills instead of one — a real, non-trivial change to `PurchaseOrderWorkflow` and `Bill` validation, not just removing a check. |
| **Practical impact of B** | No build; the current restriction stays and is now known to be intentional rather than an oversight. |
| **Practical impact of C** | No change to the PO/Bill one-to-one rule; instead, defines when a Direct Bill (or another mechanism) is the correct tool for a staged-delivery scenario — a policy/process clarification more than a code change. |
| **Decision owner** | Procurement Lead |
| **Implementation dependency** | Do not remove the current restriction until Option A is explicitly confirmed — removing it prematurely would let a genuine three-way-match gap through. |

## W2-4 — Approved PO amendments

WNG has already confirmed (STAB-3) that a formal PO Amendment/Change-Order workflow is the target
direction, and the immediate safety guard blocking direct edits to any non-`pending` PO is already
implemented. **The remaining decision is narrower: the authority/reapproval model for the formal
amendment itself.**

| | |
|---|---|
| **Current behaviour** | No amendment mechanism exists at all yet — an order that needs to change after leaving `pending` (including one merely submitted and awaiting approval) currently cannot be changed by anyone through any path. |
| **Option A** | Only named authorized approver(s) may approve an amendment; every amendment always requires reapproval. |
| **Option B** | Named authorized approver(s) may approve changes; reapproval is required only when defined material/value thresholds are exceeded. |
| **Option C** | No amendments after approval at all — cancel/close and create a new PO. (This option is superseded by WNG's own confirmed STAB-3 direction, which chose the formal-amendment path over this; it is listed here only for completeness, not as a live choice.) |
| **Practical impact of A** | Simplest rule to explain and audit ("every change goes back to the approver"), at the cost of reapproval overhead even for trivial changes (e.g. a corrected delivery address). |
| **Practical impact of B** | Matches how `PurchaseApprovalPolicy` already thinks about "material" change (it already blocks auto-approval the moment an order exceeds its covering requisition's value) — would let trivial edits proceed with the amendment recorded but not re-gated, while a real price/quantity/supplier change still requires a person. Requires WNG to define what counts as "material" and the threshold(s). |
| **Practical impact of C** | Not compatible with the STAB-3 direction WNG already confirmed; raised only so the register shows the option was considered and consciously not chosen. |
| **Also to resolve regardless of A/B** | Who may *request* an amendment (vs. who approves it); how supplier changes are treated differently from quantity/price/scope changes; whether receiving or billing must pause while an amendment awaits approval. |
| **Decision owner** | Procurement Lead + Finance/Accounts Lead |
| **Implementation dependency** | This is Phase-2B-scale feature work (new amendment/change-order record, its own approval flow, linkage back to the original PO) — the safety guard already implemented is sufficient to prevent the CRITICAL risk in the meantime. |

---

## Potential missing decisions (read-only; found while reviewing the current implementation)

WNG will decide whether any of these belongs in the register — they are not proposed as
requirements.

- **POTENTIAL MISSING DECISION — Duplicate supplier invoices and duplicate payment references.**
  Two real, confirmed gaps exist today: (1) the same supplier + the same invoice number can be
  entered as two separate Bills with no system-level block, and (2) the same bank payment reference
  can be recorded on two separate payments with no uniqueness check. Neither is currently in the
  register as its own decision (W2-2 is about *evidence*, not duplicate detection). This may matter
  because a duplicate bill or duplicate payment reference is a direct path to double-paying a
  supplier.
- **POTENTIAL MISSING DECISION — Correcting a PO stuck in `pending_approval`.** Since the STAB-3
  safety guard now blocks all edits outside `pending`, and no `reject()`/return-to-`pending` action
  exists, an order submitted for approval with an error currently has no correction path short of
  approving it as-is. This may need a narrow, separate answer from the full amendment workflow
  (W2-4), since it concerns a PO that was never approved in the first place, not one that needs to
  change *after* approval.
- **POTENTIAL MISSING DECISION — PO cancellation/closure.** No `cancelled` or `closed` status was
  found on the Purchase Order model. It is not clear from the code what should happen to an
  approved order that a supplier can no longer fulfill, or that WNG no longer needs, short of the
  now-restricted delete path. This is distinct from W2-4 (which concerns *changing* an order) and
  from the amendment workflow's "paid/closed PO cannot be amended" rule (which presumes a "closed"
  state exists to check against).
- **POTENTIAL MISSING DECISION — Service-only purchase confirmation.** The three-way match's "goods
  accepted into stock" check is built around physical materials received through Stores. No
  service-specific confirmation flag or field was found. If WNG buys services (e.g. contracted
  labour, logistics, consulting) through the same PO→Bill pipeline, it's not confirmed how "goods
  confirmation" is meant to be satisfied for a service with nothing to receive into stock —
  this may already be handled by a convention not visible from the code (e.g. treating the full
  ordered quantity as "received" on sign-off), but that has not been verified here.
- **POTENTIAL MISSING DECISION — Supplier-side credit notes.** No supplier-facing credit note model
  was found (only the client-facing `CreditNote`/`ProjectInvoice` credit mechanism exists). If a
  supplier issues WNG a credit (e.g. for returned or defective materials), it's not clear from the
  code how that is currently recorded against the original Bill/PO.
- **POTENTIAL MISSING DECISION — Emergency/exceptional purchases.** No explicit "buy first, raise
  the paperwork after" path was identified distinct from the existing Direct Bill mechanism (which
  already lets a smaller purchase skip the full Requisition→PO→GRN chain). If WNG has a genuine
  emergency-purchase scenario broader than what Direct Bill already covers, it is not currently
  named as its own decision or control point.
- **Not flagged as missing, for clarity:** partial supplier *payments* against a bill (already
  supported — `Bill::updatePaymentStatus()` recomputes balance after every partial payment) and
  partial/staged goods *receipt* against a PO (already supported — multiple GRNs per PO). These are
  distinct from W2-3's restriction, which is specifically about the number of **Bills**, not
  payments or receipts, per PO.
